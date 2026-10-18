<?php

namespace App\Migration;

use App\Models\BulkMigration;
use App\Models\BulkMigrationItem;
use App\Models\BulkMigrationTarget;
use App\Models\MigrationSearch;
use App\Models\Scopes\OwnedByUser;
use App\Models\Source;
use App\Support\Library;
use App\Suwayomi\SeriesGateway;
use App\Suwayomi\SuwayomiException;
use Illuminate\Support\Collection;

/**
 * Compares every series a reader has on one source against a chosen destination, and
 * records what it found.
 *
 * ## Why this searches only the destination the reader picked
 *
 * A reader moving a shelf has already chosen both ends. Sweeping all 82 installed sources per
 * series would be 6,560 requests for 80 series where 80 will do, and every one of the extra
 * requests goes to a site that has no bearing on the decision. {@see CandidateFinder::findOn()}
 * does the one search and reuses the same ranking and detail fetch as the wizard, so a bulk
 * match and a wizard match cannot disagree.
 *
 * ## Why the current series is read even though the library already has it
 *
 * The library entry carries the title and the chapter count, but not the author. Author
 * agreement is one of the signals that decides whether a match is pre-ticked, and measured
 * author strings differ wildly across sources for the same series: "Life Ruined",
 * "Isang, Life Ruined", "Insaengmanghaesseum, Isang" and "". Reading it properly costs one
 * GraphQL call per series. Skipping it would make every match with no author on either side
 * look like a disagreement, which on a self hosted install with many anonymous sources is
 * most of them.
 *
 * ## Why one series that fails does not end the run
 *
 * Same lesson as every other loop in this app. A pass over a reader's shelf is a slice of
 * something bigger, and an exception on the fortieth of eighty costs the other forty, because
 * the job dies before it writes the finished row and the page is left watching a run that will
 * never end. Each series is recorded as failed and the run continues, so the page can say
 * which one and why.
 *
 * ## Why the cap is a series count rather than a time budget
 *
 * Because a reader needs to know whether their shelf was fully compared before they approve
 * anything. A time budget would silently stop halfway on a slow day and the reader would have
 * no way to tell that the forty series they were shown is not all eighty. A hard count can be
 * stated plainly: this compared the first two hundred and there are more.
 */
class BulkSourceMatcher
{
    /**
     * How often to write progress.
     *
     * The run row is a polled page and the number a reader watches, but writing it on every
     * series is wasted IO for a figure that changes slowly in human terms.
     */
    private const PROGRESS_INTERVAL_SECONDS = 3;

    public function __construct(
        private readonly CandidateFinder $finder,
        private readonly SeriesGateway $series,
        private readonly Library $library,
    ) {}

    /**
     * @return array{checked: int, matched: int, total: int}
     */
    public function run(BulkMigration $run, int $limit = 0): array
    {
        $userId = (int) $run->user_id;

        $targets = $this->targetsFor($run);

        if ($targets === null) {
            return ['checked' => 0, 'matched' => 0, 'total' => 0];
        }

        $from = Source::query()->where('suwayomi_source_id', $run->from_source_id)->first();

        if ($from === null) {
            $this->refuse($run, __('The source you are moving away from is not in the catalogue on this server any more.'));

            return ['checked' => 0, 'matched' => 0, 'total' => 0];
        }

        /*
         * The reader's own series on the source being left, read in one batched call.
         *
         * `entries()` rather than a query on `series_states`, because the table has no source
         * column: which source a series sits on is a fact about the server's entry for it, not
         * about the reader's row. It also excludes the 195 series filed from a backup, which
         * have no server entry and therefore no source to move away from.
         */
        $onSource = $this->library->entries($userId)
            ->filter(fn (array $entry): bool => $entry['source_id'] !== null
                && (string) $entry['source_id'] === $run->from_source_id)
            ->values();

        /*
         * The picked subset rides the run as ids, not as titles, and is intersected here
         * rather than trusted to still be true: between the tick on the compose panel and
         * the job waking, a series can leave the source it was picked off. What the reader
         * chose is what the ids record; what they still sit on is what this measures. One
         * intersect of one list is cheaper than a wrong offer.
         */
        if ($run->isPicked()) {
            $picks = array_map('intval', (array) $run->picked_manga_ids);

            $onSource = $onSource
                ->filter(fn (array $entry): bool => in_array((int) $entry['manga_id'], $picks, true))
                ->values();
        }

        /*
         * Read state is read once for the whole run, not per series: one grouped count
         * upstream of here builds every entry's number, and the per item stamp below is
         * what a later render reads, so two rows never disagree about the same series'
         * progress for the life of the run.
         */
        $unreadHere = $onSource
            ->mapWithKeys(fn (array $entry): array => [(int) $entry['manga_id'] => $entry['unread_chapters']])
            ->all();

        $total = $onSource->count();

        /*
         * `series_total` and the leaving name are re-stamped here because the job owns the
         * run's progress; the destination names are not, because they were stamped once at
         * creation into their own rows and a run whose reader picked five targets must read
         * exactly those names after the mirror forgets one.
         */
        $run->update([
            'series_total' => $total,
            'from_source_name' => $from->display_name,
        ]);

        if ($total === 0) {
            $run->update([
                'state' => BulkMigration::READY,
                'error' => $run->isPicked()
                    ? __('None of the picked series is on that source any more.')
                    : __('Nothing in your library is on that source.'),
                'finished_at' => now(),
            ]);

            return ['checked' => 0, 'matched' => 0, 'total' => 0];
        }

        $cap = $limit > 0 ? $limit : (int) config('papyrus.migration.bulk_series_cap', 200);
        $willCompare = min($total, $cap);

        $lastWrite = microtime(true);
        $checked = 0;
        $matched = 0;

        foreach ($onSource as $entry) {
            /*
             * The reader may have pressed stop since the last series finished.
             *
             * Checked here rather than after the compare, because the only outcome that
             * matters is that the series in front of the loop is not compared once the
             * reader has said stop: a press that lands during a compare lets that one
             * finish, and every row it wrote stands, which is the polite part of the
             * phrase "politely abandons". The final and cap exits below check as well,
             * because they both write a state and must not overwrite the stop.
             */
            if ($this->hasBeenStopped($run)) {
                /*
                 * The counts are written on the way out, because the in-progress writes
                 * above are throttled to every three seconds and a stop that lands before
                 * the first one fires leaves the row reading zero directly above card rows
                 * the run did create. The counters are the reader's "how far did it get",
                 * and the rows are the same claim in a second form.
                 */
                $run->update(['series_checked' => $checked, 'series_matched' => $matched]);

                return ['checked' => $checked, 'matched' => $matched, 'total' => $total];
            }

            if ($checked >= $cap) {
                $run->update([
                    'state' => BulkMigration::READY,

                    /*
                     * The counts are written here as well as at the end.
                     *
                     * The in-progress writes are throttled to every three seconds, so a run
                     * that compared two series and stopped wrote nothing at all, and the page
                     * said "Looked at 0 of your series" directly above a message saying it had
                     * stopped part way through. Two adjacent sentences contradicting each other,
                     * which is the thing a reader is least able to reason about. Caught by
                     * `test_it_stops_at_the_limit_and_says_that_it_did`.
                     */
                    'series_checked' => $checked,
                    'series_matched' => $matched,

                    /*
                     * `sprintf`, not `trans_choice`, and the reason is the same one the import
                     * command gives: the sentence does not change shape with the number, only
                     * the number in it does, so there is no verb for the singular to disagree
                     * with. The earlier version of this passed two identical string literals
                     * separated by a comma where a `|` belonged, which PHP read as four
                     * arguments, and passing an int where an array belonged is a type error
                     * rather than a subtle mistake.
                     */
                    'error' => sprintf(
                        'This compared the first %s series and stopped there. There are more, so raise the limit to compare them all.',
                        number_format($willCompare),
                    ),
                    'finished_at' => now(),
                ]);

                return ['checked' => $checked, 'matched' => $matched, 'total' => $total];
            }

            /**
             * Written before the compare, not after, so a run that dies mid series leaves
             * the name of the one it died on. The library entry's title rather than the
             * fetched one: the point of the line is naming what is about to be requested,
             * and asking the destination for it first would put the request before the name.
             */
            $run->update(['current_title' => (string) $entry['title']]);

            $checked++;

            $outcome = $this->compareOne(
                $run,
                $from,
                $targets,
                (int) $entry['manga_id'],
                $unreadHere[(int) $entry['manga_id']] ?? null,
            );

            if ($outcome['matched']) {
                $matched++;
            }

            $now = microtime(true);

            if ($now - $lastWrite >= self::PROGRESS_INTERVAL_SECONDS) {
                $lastWrite = $now;

                $run->update(['series_checked' => $checked, 'series_matched' => $matched]);
            }
        }

        /*
         * The stop can land between the last series' own state check and this write, so this
         * exit re-checks rather than stamping READY over whatever the reader named. Counts
         * still get written on the stopped path, because the stopped card shows them, but the
         * state it named stands.
         */
        if ($this->hasBeenStopped($run)) {
            $run->update(['series_checked' => $checked, 'series_matched' => $matched]);
        } else {
            $run->update([
                'state' => BulkMigration::READY,
                'series_checked' => $checked,
                'series_matched' => $matched,
                'error' => null,
                'finished_at' => now(),
            ]);
        }

        return ['checked' => $checked, 'matched' => $matched, 'total' => $total];
    }

    /**
     * The destinations this run may search, validated against the server, or null when
     * the run has been recorded as refused.
     *
     * ## Two shapes, and why the older one is still trusted
     *
     * Runs before the compose screen carried exactly one destination in two columns, with
     * no `bulk_migration_targets` rows. Reading those columns means an old run reopened in
     * this app still walks exactly what the reader of its day picked. {@see
     * \App\Models\BulkMigration::isSingleDestination()} is the seam that keeps the two
     * shapes apart, because a row answering both ways would make neither answer checked.
     *
     * ## Why one refused destination refuses the run
     *
     * A pick that cannot be honoured is not four searches' worth of a run with one
     * destination quietly missing: every series would come back without something the
     * reader asked for by name, and the card would offer moves that do not account for
     * the whole pick. The refusal names what was asked for and could not be honoured by
     * the name its row was stamped with, or its id where there is no name.
     *
     * @return Collection<int, Source>|null
     */
    private function targetsFor(BulkMigration $run): ?Collection
    {
        if ($run->isSingleDestination()) {
            $to = Source::query()->where('suwayomi_source_id', (string) $run->to_source_id)->first();

            if ($to === null) {
                $this->refuse($run, __('That source is not in the catalogue on this server any more.'));

                return null;
            }

            if (! $to->is_installed) {
                $this->refuse($run, __('That source is not installed on this server, so there is nothing to search. Install it and try again.'));

                return null;
            }

            if ((string) $to->suwayomi_source_id === (string) $run->from_source_id) {
                $this->refuse($run, __('The source being left is also a destination. Moving a source onto itself does nothing.'));

                return null;
            }

            return collect([$to]);
        }

        /*
         * The destinations are read once, with the reader scope set aside deliberately,
         * and the one collection below is what every branch after this reads. The method
         * runs inside the queue worker, which holds no authenticated reader, and
         * OwnedByUser answers no reader with zero rows, so the relation read as the page
         * reads it would hand the run an empty destination list and the run would search
         * none of its own picks. The job resolved this run to one reader through
         * forUser, and these rows are that run's rows, keyed on its own id and stamped
         * by it at creation: the read follows the run rather than re-asking who is
         * asking.
         */
        $targets = $run->targets()
            ->withoutGlobalScope(OwnedByUser::class)
            ->orderBy('seq')
            ->get();

        $rows = Source::query()
            ->whereIn('suwayomi_source_id', $targets->pluck('source_id')->all())
            ->get()
            ->keyBy(fn (Source $source): string => (string) $source->suwayomi_source_id);

        $sameAsLeaving = $targets
            ->contains(fn (BulkMigrationTarget $target): bool => (string) $target->source_id === (string) $run->from_source_id);

        if ($sameAsLeaving) {
            $this->refuse($run, __('The source being left is also a destination. Moving a source onto itself does nothing.'));

            return null;
        }

        foreach ($targets as $target) {
            $source = $rows->get((string) $target->source_id);
            $name = (string) ($target->source_name ?? $target->source_id);

            if ($source === null) {
                $this->refuse($run, __('One destination is not in the catalogue on this server any more: :name', ['name' => $name]));

                return null;
            }

            if (! $source->is_installed) {
                $this->refuse($run, __(':name is not installed on this server, so there is nothing to search there. Install it and try again.', ['name' => $name]));

                return null;
            }
        }

        return $targets->map(fn (BulkMigrationTarget $target): Source => $rows->get((string) $target->source_id));
    }

    /**
     * Record a run that could not be honoured at all, before anything is searched.
     */
    private function refuse(BulkMigration $run, string $error): void
    {
        $run->update([
            'state' => BulkMigration::FAILED,
            'error' => $error,
            'finished_at' => now(),
        ]);
    }

    /**
     * Whether the reader has said stop since the loop last looked.
     *
     * A method rather than an inline compare, because the loop top and the final exit make
     * the same claim and should read it the same way, and because a stop is otherwise a
     * timing race a test fixture cannot win on purpose: this is the one place a stop is
     * believed, which is the override point a test needs.
     */
    protected function hasBeenStopped(BulkMigration $run): bool
    {
        $run->refresh();

        return $run->state !== BulkMigration::RUNNING;
    }

    /**
     * Compare one series against every destination the run carries, recording whatever
     * happened rather than throwing.
     *
     * ## Why the winner names its source on the item row
     *
     * With several destinations in one run, two rows can compare equal on chapters and
     * only disagree on where the better one lives, so which source won is part of the
     * answer. Stamping it per item means the mover, the cards and the report all read it
     * from the row rather than asking the mirror, which may not still carry that source
     * by the time any of them render.
     *
     * ## Why the read progress is stamped the same way
     *
     * The number comes from this run's lens read, not from a fresh lookup at render
     * time: what a row shows is what the compare knew when it decided, and a render
     * that re-counts chapters later can disagree with the row it sits under without
     * either being wrong at its own moment.
     *
     * @param  Collection<int, Source>  $targets  validated, in pick order
     * @param  int|null  $unreadHere  null when the server never fetched the chapter list
     * @return array{matched: bool}
     */
    private function compareOne(BulkMigration $run, Source $from, Collection $targets, int $mangaId, ?int $unreadHere): array
    {
        $userId = (int) $run->user_id;

        try {
            $current = $this->series->find($mangaId);
        } catch (SuwayomiException $e) {
            $this->recordFailed($run, $mangaId, '', $e->getMessage());

            return ['matched' => false];
        }

        if ($current === null) {
            $this->recordFailed(
                $run,
                $mangaId,
                '',
                __('The server no longer knows this series.'),
            );

            return ['matched' => false];
        }

        $title = (string) $current['title'];

        try {
            $found = $this->finder->findOnMany($targets, $current);
        } catch (SuwayomiException $e) {
            $this->recordFailed($run, $mangaId, $title, $e->getMessage());

            return ['matched' => false];
        }

        $best = $found['candidates'][0] ?? null;

        if ($best === null) {
            BulkMigrationItem::createFor($run->user_id, [
                'bulk_migration_id' => $run->id,
                'from_manga_id' => $mangaId,
                'title' => $title,
                'unread_here' => $unreadHere,
                'status' => BulkMigrationItem::SKIPPED,
                'reason' => __('That source does not list this series.'),
            ]);

            return ['matched' => false];
        }

        /*
         * A candidate the finder could not read is not a match.
         *
         * `no_chapters` and `unavailable` are both destinations with nothing behind them. A
         * series whose every candidate failed to answer is reported as not found there, which
         * is what it amounts to, and offering a row with a destination the reader cannot read
         * would be offering a move that fails on press.
         */
        if (($best['status'] ?? '') !== 'ready') {
            BulkMigrationItem::createFor($run->user_id, [
                'bulk_migration_id' => $run->id,
                'from_manga_id' => $mangaId,
                'title' => $title,
                'to_manga_id' => (int) $best['manga_id'],
                'to_title' => (string) $best['title'],
                'to_source_id' => (string) $best['source_id'],
                'to_source_name' => (string) $best['source_name'],
                'unread_here' => $unreadHere,
                'status' => BulkMigrationItem::SKIPPED,
                'reason' => (string) ($best['note'] ?? __('That source could not be read for this series.')),
            ]);

            return ['matched' => false];
        }

        BulkMigrationItem::createFor($run->user_id, [
            'bulk_migration_id' => $run->id,
            'from_manga_id' => $mangaId,
            'title' => $title,
            'to_manga_id' => (int) $best['manga_id'],
            'to_title' => (string) $best['title'],
            'to_source_id' => (string) $best['source_id'],
            'to_source_name' => (string) $best['source_name'],
            'unread_here' => $unreadHere,
            'chapters_here' => (int) ($current['chapters']['totalCount'] ?? 0),
            'chapters_there' => (int) ($best['chapters'] ?? 0),
            'title_similarity' => (float) ($best['title_similarity'] ?? 0),
            'chapter_delta' => isset($best['chapter_delta']) ? (int) $best['chapter_delta'] : null,
            'author_overlap' => isset($best['author_overlap']) ? (float) $best['author_overlap'] : null,
            'status' => BulkMigrationItem::PENDING,
        ]);

        $this->recordWhatWasOffered($userId, $mangaId, $found, $best);

        return ['matched' => true];
    }

    /**
     * Write the search row the per series migrator insists on.
     *
     * ## Why this is not optional bookkeeping
     *
     * {@see SeriesMigrator::offeredCandidate()} refuses any destination that was not offered
     * by a search this reader ran for this series. That is the boundary that stops a value
     * tampered with in the browser from moving a series anywhere on the internet, and it is
     * checked in the migrator rather than only hidden in a template, because a hidden control
     * is not a boundary.
     *
     * So a bulk match gets a real `migration_searches` row carrying the candidate that was
     * found. The alternative was a second code path into the migrator for bulk moves, which
     * would have been one more place where a destination is trusted, and one more thing to keep
     * in step with the wizard. Writing the row means the bulk flow and the wizard go through
     * exactly the same gate, and {@see SeriesMigrator} needs no change at all.
     *
     * One row per series rather than one per run, because that is what the migrator looks up:
     * by `from_manga_id`. A run wide row could not answer "was this destination offered for
     * this series".
     *
     * @param  array{candidates: array<int, array<string, mixed>>, meta: array<string, int>}  $found
     * @param  array<string, mixed>  $best
     */
    private function recordWhatWasOffered(int $userId, int $mangaId, array $found, array $best): void
    {
        MigrationSearch::createFor($userId, [
            'from_manga_id' => $mangaId,
            'state' => MigrationSearch::READY,
            'sources_searched' => (int) ($found['meta']['sources_searched'] ?? 0),
            'sources_total' => (int) ($found['meta']['sources_total'] ?? 0),
            'candidates' => [$best],
            'error' => null,
        ]);
    }

    private function recordFailed(BulkMigration $run, int $mangaId, string $title, string $reason): void
    {
        BulkMigrationItem::createFor($run->user_id, [
            'bulk_migration_id' => $run->id,
            'from_manga_id' => $mangaId,
            'title' => $title !== '' ? $title : __('(title unavailable)'),
            'status' => BulkMigrationItem::FAILED,
            'reason' => $reason,
        ]);
    }
}
