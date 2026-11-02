<?php

namespace App\Library;

use App\Models\CheckRun;
use App\Models\LibraryCheck;
use App\Models\SeriesState;
use App\Models\User;
use App\Support\Library;
use App\Suwayomi\SeriesGateway;
use App\Suwayomi\SuwayomiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Re-reads a series from its source and records what came back.
 *
 * ## One code path, two callers
 *
 * A per-card button and a whole-library pass are the same action at two scales, so
 * they are the same method. The button calls {@see check()} inline and answers in
 * about a second, because the measured cost of one refresh is 361ms to 1617ms with
 * a median of 876ms and a reader watching one card can wait for that. The pass calls
 * the same method in a loop, inside a job, and shows progress.
 *
 * ## Why the chapter count is the source's and not ours
 *
 * The number that replaces "Chapters never fetched" is `chapters.totalCount` as the
 * source counts it, not a count of rows we happen to have read state for. AniList's
 * chapter count was measured as a substitute and rejected: 4 of 6 sampled series had
 * no entry at all, and both that did over-count, by +9 and +17, because it counts the
 * original release including chapters no scanlation has.
 *
 * ## Why a "no chapters" answer is not an error
 *
 * Measured: `fetchMangaAndChapters` on a series whose source has nothing throws a
 * bare `java.lang.Exception: No chapters found`, delivered as a GraphQL error on HTTP
 * 200. The gateway already turns that one message into a result rather than an
 * exception, and this class turns it into its own state. A source with no chapters
 * today is a fact about the source, and a reader shown an error would go looking for
 * a fault that is not there.
 *
 * ## What moves the clock, which is everything
 *
 * `chapter_list_checked_at` means "we asked", not "the source answered", and it moves on
 * every outcome including a failure. It is written once, in `record()`, because every path
 * through `check()` funnels there and a rule enforced in one place is a rule that cannot be
 * half applied.
 *
 * It used to move only when the source answered, on the reasoning that a request that never
 * landed is not information. True, and it does not stop the loop: a series whose extension is
 * broken never gets an answer, so it stayed due for ever and `CheckLibrary` re-booked the chain
 * on it for as long as the worker ran. Measured: 4 series answering "Could not find environment
 * bundle", 95 dispatches against a worker that checked nothing else, every one of them
 * re-checking the same four.
 *
 * Nothing is lost by counting a failure as a check. The outcome and its error are recorded in
 * `library_checks`, the library page still shows which series failed and why, and a reader can
 * still press Check on a single series because that path forces. Retrying on the normal
 * interval rather than immediately is the shape worth having: a source being briefly down is
 * worth trying again, and a tight loop against a broken extension is how a worker ends up at 100%
 * for nobody's benefit.
 */
class CheckLedger
{
    /**
     * How long one series may take inside a pass.
     *
     * Measured refreshes run from 361ms to 1617ms. Fifteen seconds is about
     * nine times the worst measured call, so a healthy source is never cut off,
     * while one unresponsive source costs a third of a slice instead of all of
     * it. Without a per-series ceiling, `refresh()`'s forty second default is
     * long enough for a single dead source to consume the whole
     * forty five second budget, and the next slice begins with the same series
     * first in line because its clock has not moved yet.
     */
    public const PER_SERIES_TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly SeriesGateway $gateway,
        private readonly Library $library,
    ) {}

    /**
     * Check one series and record the outcome.
     *
     * Never throws. A reader pressing a button should get a row saying what happened,
     * not an error page, and one unreachable source must not stop the other
     * thirteen in a pass.
     *
     * `$timeout` is the bulk pass's per-series ceiling. The single card leaves it
     * null and keeps the gateway's longer default, because a reader waiting on
     * one card asked for that one answer.
     */
    public function check(User|int $user, int $mangaId, bool $force = false, ?int $timeout = null): LibraryCheck
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        $state = SeriesState::forUser($userId)
            ->where('suwayomi_manga_id', $mangaId)
            ->first();

        if ($state === null) {
            throw new \InvalidArgumentException(
                "Series {$mangaId} is not in this reader's state, so there is nothing to check."
            );
        }

        if (! $force && ! app(CheckSchedule::class)->isDue($state)) {
            return $this->existingRow($userId, $mangaId) ?? $this->skippedRow($userId, $mangaId);
        }

        // The count we last saw, which is what "new" is measured against. Read
        // before the refresh so a refresh that changes it does not hide the change.
        $previous = LibraryCheck::query()
            ->forUser($userId)
            ->where('suwayomi_manga_id', $mangaId)
            ->value('chapter_count');

        try {
            $result = $this->gateway->refresh($mangaId, $timeout);
        } catch (SuwayomiException $e) {
            return $this->record($userId, $mangaId, LibraryCheck::ERROR, [
                'error' => $this->describe($e),
            ]);
        }

        if ($result['error'] !== null) {
            $noChapters = str_contains(strtolower((string) $result['error']), 'no chapters');

            if ($noChapters) {
                /*
                 * A successful answer, so the clock moves.
                 *
                 * The floor exists to stop an accidental press putting hundreds of
                 * upstream requests into 82 sites, and a source that answers "I have
                 * nothing" is answering. Leaving the timestamp alone would make such a
                 * series due forever, so every press would re-ask a source that has
                 * already said no. Measured on the dev library: MangaDex answered
                 * `No chapters found` for One Piece, which is then re-checked on every
                 * single pass for as long as it stays in the library.
                 *
                 * No fingerprint is written, because there is no chapter list to
                 * describe. `fingerprintMatches` treats a null fingerprint as current,
                 * so this cannot be mistaken for staleness either.
                 */
                return $this->record($userId, $mangaId, LibraryCheck::NO_CHAPTERS, [
                    'error' => (string) $result['error'],
                ]);
            }

            return $this->record($userId, $mangaId, LibraryCheck::ERROR, [
                'error' => (string) $result['error'],
            ]);
        }

        $series = $result['series'] ?? null;

        if (! is_array($series)) {
            return $this->record($userId, $mangaId, LibraryCheck::ERROR, [
                'error' => 'The server returned no series for a refresh that reported no error.',
            ]);
        }

        $chapters = (array) ($series['chapters']['nodes'] ?? []);
        $count = (int) ($series['chapters']['totalCount'] ?? count($chapters));

        /*
         * The fingerprint goes down here and nowhere else until now, which is what
         * makes the library's staleness badge work for the first time. Measured: all
         * 14 series had a null fingerprint before this ran, so `needs_refresh` could
         * never be true and the badge was unreachable.
         */
        $this->library->recordChapterFingerprint($userId, $mangaId, $chapters);

        return $this->record($userId, $mangaId, LibraryCheck::CHECKED, [
            'chapter_count' => $count,
            'new_chapters' => $previous === null ? null : max(0, $count - (int) $previous),
        ]);
    }

    /**
     * Check a slice of what is due, stopping on the time budget or on Stop.
     *
     * The budget rather than a count, because the cost per series is 361ms to 1617ms
     * and a fixed count overruns the worker on a slow day. Measured against the
     * same rule in the AniList resolver, where a title count could not respect a time
     * limit and the budget was the thing that fixed it.
     *
     * ## The per-series ceiling is what makes a dead source skippable
     *
     * The budget alone only bounds the pass, not one request. A source that never
     * answers held the pass for the gateway's forty second default, moved no
     * clock because no outcome was recorded yet, and came back first in the next
     * slice. Each series now gets at most {@see self::PER_SERIES_TIMEOUT_SECONDS}
     * and whatever is left of the slice, so a dead source is recorded as failed,
     * its clock moves, and the pass reaches the series behind it.
     *
     * ## A run, when there is one
     *
     * The counters and the Stop flag live on {@see CheckRun}. The ledger touches
     * them after every series rather than once at the end, because a polled page
     * should see a number that reflects completed work, and a Stop pressed while
     * a source is answering should take effect before the next series starts.
     *
     * @return array{checked: int, chapters: int, new: int, failed: int, no_chapters: int, budget_out: bool, cancelled: bool}
     */
    public function checkDue(
        User|int $user,
        int $budgetSeconds = 45,
        int $maxSeries = 60,
        bool $force = false,
        ?CheckRun $run = null,
    ): array {
        $due = $this->candidates($user, $maxSeries, $force);

        $tally = [
            'checked' => 0,
            'chapters' => 0,
            'new' => 0,
            'failed' => 0,
            'no_chapters' => 0,
            'budget_out' => false,
            'cancelled' => false,
        ];

        if ($run !== null && ! $run->isRunning()) {
            $tally['cancelled'] = true;

            return $tally;
        }

        $started = microtime(true);

        foreach ($due as $state) {
            /*
             * Re-read the row rather than trusting the copy in memory. Stop is
             * pressed in another request, so the flag can change between two
             * series in this loop. The refresh is one indexed read per series
             * against a table with one live row per reader.
             */
            if ($run !== null) {
                $run->refresh();

                if (! $run->isRunning()) {
                    $tally['cancelled'] = true;

                    break;
                }
            }

            $remaining = $budgetSeconds - (microtime(true) - $started);

            if ($remaining < 1) {
                $tally['budget_out'] = true;

                break;
            }

            $timeout = (int) min(
                self::PER_SERIES_TIMEOUT_SECONDS,
                max(1, (int) floor($remaining)),
            );

            /*
             * One bad series must not end the pass.
             *
             * A pass is a slice of a reader's whole library, so an exception on the third of
             * sixty costs the other fifty-seven and stops the chain, because the job dies
             * before it reaches the re-book. Measured: `Check 867 series` against a library
             * holding series with no source queued a job that failed four times in 5ms,
             * checked nothing, and re-queued itself never.
             *
             * `SuwayomiException` is the expected failure and `check()` already turns it into
             * a failed row. What lands here is the unexpected: a malformed row, a source that
             * returns nonsense, a cast that fails. Those are counted and skipped, because a
             * reader pressing one button asked for 867 checks and getting four is a worse
             * answer than 866.
             */
            try {
                $row = $this->check($user, (int) $state->suwayomi_manga_id, $force, $timeout);
            } catch (Throwable $e) {
                report($e);

                $tally['failed']++;
                $tally['checked']++;

                if ($run !== null) {
                    $this->advance($run);
                }

                continue;
            }

            $tally['checked']++;

            match ($row->state) {
                LibraryCheck::CHECKED => $this->tallySuccess($tally, $row),
                LibraryCheck::NO_CHAPTERS => $tally['no_chapters']++,
                default => $tally['failed']++,
            };

            if ($run !== null) {
                $this->advance($run, $row);
            }
        }

        return $tally;
    }

    /**
     * Move the run's counters by one finished series.
     *
     * Called for every outcome, including a series that threw before producing a
     * row, because the run's `checked` is what the Stop sentence reports. A
     * series the source could not answer for is counted in `failed` so the
     * finished sentence does not call the run complete while hiding it.
     */
    private function advance(CheckRun $run, ?LibraryCheck $row = null): void
    {
        $run->forceFill([
            'checked' => $run->checked + 1,
            'new_chapters' => $run->new_chapters
                + (($row !== null && $row->hasNewChapters()) ? (int) $row->new_chapters : 0),
            'failed' => $run->failed
                + (($row !== null && $row->state === LibraryCheck::ERROR) ? 1 : 0),
            'last_progress_at' => now(),
        ])->save();
    }

    /**
     * The series a pass would visit.
     *
     * A forced pass takes the whole library, not what is due. The floor exists to
     * stop an accidental press from putting hundreds of upstream requests into 82
     * sites, and a forced pass is a reader pressing the button on purpose, so
     * applying the floor to it would make the button do nothing for a series checked
     * an hour ago. That is not what forcing is for.
     *
     * @return Collection<int, SeriesState>
     */
    private function candidates(User|int $user, int $maxSeries, bool $force): Collection
    {
        $schedule = app(CheckSchedule::class);
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        if ($force) {
            return SeriesState::forUser($userId)
                ->where('in_library', true)
                // A series filed from a backup has no manga, so there is nothing to check it
                // against. Measured: `Check 867 series` on a real import dispatched the job,
                // which died four times on `Series 0 is not in this reader's state` because
                // this query handed it the 195 filed series and the cast below turned their
                // null ids into 0.
                ->whereNotNull('suwayomi_manga_id')
                ->orderByRaw('chapter_list_checked_at is not null')
                ->orderBy('chapter_list_checked_at')
                ->orderBy('added_at')
                ->limit($maxSeries)
                ->get();
        }

        return $schedule->due($user, $maxSeries);
    }

    /**
     * @param  array{checked: int, chapters: int, new: int, failed: int, no_chapters: int, budget_out: bool, cancelled: bool}  $tally
     */
    private function tallySuccess(array &$tally, LibraryCheck $row): void
    {
        $tally['chapters'] += (int) $row->chapter_count;

        if ($row->hasNewChapters()) {
            $tally['new'] += (int) $row->new_chapters;
        }
    }

    /**
     * Write the outcome, keeping only the most recent per series.
     *
     * `createFor()` rather than `updateOrCreate()`, because `user_id` is not
     * mass-assignable on an owned model by design and `query()->updateOrCreate()`
     * would raise MissingOwnerException. Found the hard way: the job runs with no
     * authenticated user, so the tenancy fallback finds nothing to fall back to.
     * The attribute is set explicitly by `createFor`, which is also the greppable
     * way to write a row on another reader's behalf.
     *
     * @param  array{error?: string|null, chapter_count?: int, new_chapters?: int|null}  $attributes
     */
    private function record(
        int $userId,
        int $mangaId,
        string $state,
        array $attributes = [],
    ): LibraryCheck {
        $existing = LibraryCheck::query()
            ->forUser($userId)
            ->where('suwayomi_manga_id', $mangaId)
            ->first();

        /*
         * The clock, moved here and only here.
         *
         * Every outcome of `check()` reaches `record()`, so one write covers success, "no
         * chapters" and failure alike, and there is no second path that can forget. The class
         * docblock explains why a failure counts.
         */
        SeriesState::forUser($userId)
            ->where('suwayomi_manga_id', $mangaId)
            ->update(['chapter_list_checked_at' => now()]);

        $values = array_merge($attributes, [
            'suwayomi_manga_id' => $mangaId,
            'state' => $state,
            'checked_at' => now(),
        ]);

        if ($existing !== null) {
            $existing->fill($values);
            $existing->save();

            return $existing;
        }

        try {
            return LibraryCheck::createFor($userId, $values);
        } catch (UniqueConstraintViolationException $e) {
            /*
             * Two requests read no row before either wrote. Laravel maps the driver's unique
             * failure to this subclass on this tree, so the losing request re-reads the
             * winner's row and updates it, which is the same "one row per series" answer the
             * unique index enforces. Sharing the seam with `DownloadLedger::enqueue()` means
             * neither class parses SQLSTATE or driver text.
             */
            $existing = LibraryCheck::query()
                ->forUser($userId)
                ->where('suwayomi_manga_id', $mangaId)
                ->first();

            if ($existing === null) {
                throw $e;
            }

            $existing->fill($values);
            $existing->save();

            return $existing;
        }
    }

    /**
     * The existing row for a series, if it has one.
     */
    private function existingRow(int $userId, int $mangaId): ?LibraryCheck
    {
        return LibraryCheck::query()
            ->forUser($userId)
            ->where('suwayomi_manga_id', $mangaId)
            ->first();
    }

    /**
     * A row recording that the floor kept a series out of the pass.
     *
     * Written rather than returning null, because a reader who pressed "check" and
     * watched a number not move deserves to be told the series was skipped rather
     * than left to work out whether it was visited.
     */
    private function skippedRow(int $userId, int $mangaId): LibraryCheck
    {
        $row = $this->existingRow($userId, $mangaId);

        if ($row !== null) {
            return $row;
        }

        return $this->record($userId, $mangaId, LibraryCheck::SKIPPED, [
            'error' => null,
        ]);
    }

    /**
     * A reader-facing description of a failure.
     *
     * Suwayomi reports a bare exception with no detail, so this adds only what we
     * actually know, which is that the call did not complete. A timeout is
     * called a timeout rather than printing the transport's millisecond count:
     * the reader needs to know the series was skipped on purpose, not how long
     * cURL waited.
     */
    private function describe(SuwayomiException $e): string
    {
        $message = trim($e->getMessage());

        /*
         * Transport turns every connection failure into one exception, so a
         * cURL timeout arrives as `Unavailable` rather than `Timeout`. The text
         * is the only signal left, and it is the one cURL writes.
         */
        if ($e->isTimeout() || str_contains(strtolower($message), 'timed out')) {
            return 'The source did not answer in time, so Papyrus skipped this series. It will try again on the next check.';
        }

        return $message === ''
            ? 'The server could not be reached. Nothing was recorded as checked.'
            : $message;
    }

    // Reading ------------------------------------------------------------

    /**
     * The tallies a library page header shows.
     *
     * @return array{checked: int, new: int, failed: int, no_chapters: int, last_checked_at: Carbon|null}
     */
    public function counts(User|int $user): array
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        $rows = LibraryCheck::query()->forUser($userId)->get(['state', 'new_chapters', 'checked_at']);

        $new = 0;

        foreach ($rows as $row) {
            if ($row->hasNewChapters()) {
                $new += (int) $row->new_chapters;
            }
        }

        $last = $rows->max('checked_at');

        return [
            'checked' => $rows->where('state', LibraryCheck::CHECKED)->count(),
            'new' => $new,
            'failed' => $rows->where('state', LibraryCheck::ERROR)->count(),
            'no_chapters' => $rows->where('state', LibraryCheck::NO_CHAPTERS)->count(),
            'last_checked_at' => $last,
        ];
    }

    /**
     * The latest check per series, keyed by manga id.
     *
     * One query for the whole library page, because the card badges ask about every
     * series on the page and asking once each is the mistake the library page
     * already made with its Suwayomi metadata.
     *
     * @param  array<int, int>  $mangaIds
     * @return array<int, LibraryCheck>
     */
    public function latestFor(User|int $user, array $mangaIds): array
    {
        if ($mangaIds === []) {
            return [];
        }

        $rows = LibraryCheck::query()
            ->forUser($user)
            ->whereIn('suwayomi_manga_id', $mangaIds)
            ->get()
            ->sortByDesc('checked_at')
            ->groupBy('suwayomi_manga_id');

        $latest = [];

        foreach ($rows as $mangaId => $group) {
            $latest[(int) $mangaId] = $group->first();
        }

        return $latest;
    }

    /**
     * Series that turned up chapters on their last check, newest first.
     *
     * @return Collection<int, LibraryCheck>
     */
    public function withNewChapters(User|int $user, int $limit = 20): Collection
    {
        return LibraryCheck::query()
            ->forUser($user)
            ->foundChapters()
            ->withNewChapters()
            ->orderByDesc('checked_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Series whose last check failed, newest first.
     *
     * @return Collection<int, LibraryCheck>
     */
    public function failures(User|int $user, int $limit = 20): Collection
    {
        return LibraryCheck::query()
            ->forUser($user)
            ->failed()
            ->orderByDesc('checked_at')
            ->limit($limit)
            ->get();
    }
}
