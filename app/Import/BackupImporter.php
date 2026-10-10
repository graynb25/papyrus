<?php

namespace App\Import;

use App\Models\ImportedSeries as Ledger;
use App\Models\ImportedSource as SourceRow;
use App\Models\ImportRun;
use App\Models\SeriesState;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Runs a whole import: read, install, resolve, write, report.
 *
 * ## Why it is safe to interrupt
 *
 * Because the ledger is written as each series goes, rather than at the end. An import of
 * 1,277 series is eleven minutes of upstream calls, and an interrupted run that had to start
 * from zero would make people avoid running it at all. Re-running picks up: a series whose
 * fingerprint is unchanged is skipped, so the second attempt does the remaining work and
 * nothing else.
 *
 * That is also why this can be a command rather than a chain of queue jobs. Slicing exists to
 * survive a worker timeout; here the resumable ledger does the same job without the moving
 * parts, and a reader can watch it print progress rather than poll a page.
 *
 * ## What it will not do
 *
 * It will not invent a match, and it will not file a series whose source this server cannot
 * have as though it had come across. Both are counted and named at the end, because an import
 * that reports "1,082 imported" for a library of 1,277 is lying by omission unless it says
 * where the other 195 are. On the measured backup they sit on 7 sources the reader's own app
 * had already marked gone, and nothing brings them back.
 *
 * ## What it does with those 195
 *
 * It files their titles, with no source behind them. A reader's library that quietly lost 15%
 * of itself teaches them the import was lossy; one that shows 195 series greyed out and named
 * teaches them what happened and when. The rows are local only, carry the reason, and are
 * revisited on every run, so a source that comes back fills them in.
 */
final class BackupImporter
{
    /** @var callable(string): void|null Progress line, so a command can print without this class knowing about consoles. */
    private $progress = null;

    /**
     * The run the current progress line belongs to, so `say()` can persist it.
     *
     * Rather than threading the run through every call that reports something. Three methods
     * report, at three different depths, and the one that runs in a loop already has the run in
     * hand but the two that precede it do not pass it to a shared helper.
     */
    private ?ImportRun $activeRun = null;

    public function __construct(
        private readonly BackupReaders $readers,
        private readonly ExtensionInstaller $installer,
        private readonly SeriesResolver $resolver,
        private readonly SeriesWriter $writer,
    ) {}

    /**
     * Import one backup file for one reader.
     *
     * @param  int  $limit  Stop after this many series. Zero means all of them.
     */
    public function import(User|int $user, string $path, int $limit = 0, bool $installExtensions = true): ImportResult
    {
        $run = ImportRun::createFor($user, [
            'format' => 'unknown',
            'state' => ImportRun::RUNNING,
            'file_name' => basename($path),
            'started_at' => now(),
        ]);

        $this->activeRun = $run;

        /*
         * A run that is left half done is one nobody can tell apart from one that never started.
         *
         * The importer is written to be re-runnable rather than resumable, because the ledger
         * makes a second run cheap. That is a recovery for a reader who knows to press the
         * button again, and this is the half of it that makes the button safe to press again:
         * a crash inside `import()` is otherwise invisible, because the row is only written at
         * the end and a failure between here and there leaves `state` reading `running` with
         * nothing behind it.
         */
        try {
            $backup = $this->readers->for($path)->read($path);
        } catch (ImportRefused $e) {
            // A refusal is kept as a run, not thrown away. "It did nothing" and "it worked"
            // are different outcomes and a reader needs to be able to see which happened.
            $run->update([
                'state' => ImportRun::REFUSED,
                'refusal_reason' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            throw $e;
        }

        $run->update([
            'format' => $backup->format,
            'schema_version' => $backup->schemaVersion,
            'series_in_file' => $backup->totalInFile,
            'library_series' => $backup->series->count(),
            'chapters' => $backup->series->sum(fn (ImportedSeries $s): int => $s->chapters->count()),
        ]);

        $sources = $backup->sources;

        $extensions = $this->installExtensions($user, $run, $sources, $installExtensions);

        return new ImportResult(
            series: $this->applySeries($user, $run, $backup, $limit),
            alreadyInstalled: $extensions['already'],
            attempted: $extensions['attempted'],
            installed: $extensions['installed'],
            stillMissing: $extensions['still_missing'],
            obsoleteSeries: $extensions['obsolete'],
            refusedPackages: $extensions['refused'],
            installAttempted: $extensions['allowed'],
        );
    }

    /**
     * Install what the backup needs, and record what came across.
     *
     * @param  Collection<int, BackupSource>  $sources
     * @return array{already: int, attempted: int, installed: int, still_missing: int, obsolete: int, refused: array<string, string>, allowed: bool}
     */
    private function installExtensions(User|int $user, ImportRun $run, Collection $sources, bool $allowed): array
    {
        $this->installer->survey($sources);

        foreach ($sources as $source) {
            SourceRow::createFor($user, [
                'import_run_id' => $run->id,
                'source_id' => $source->sourceId,
                'name' => $source->name,
                'pkg_name' => $source->package,
                'series_count' => $source->seriesCount,
                'obsolete' => $source->obsolete,
            ]);
        }

        if (! $allowed) {
            $surveyed = $this->installer->survey($sources);

            $missing = $surveyed
                ->reject(fn (BackupSource $s): bool => $s->isInstalled || $s->obsolete)
                ->count();

            /*
             * Recorded even though nothing was installed.
             *
             * `sources_missing` is the run's record of which sources it could not reach, and a
             * `--no-install` run is exactly the run that could not. Leaving it null made the
             * column mean "we do not know" for the one case where we do know perfectly well,
             * and a reader who ran with `--no-install` to see the gap then ran without it had
             * no record to compare against.
             */
            $run->update(['sources_missing' => $missing]);

            return [
                'already' => $surveyed->filter(fn (BackupSource $s): bool => $s->isInstalled)->count(),
                // Nothing was attempted, so nothing to retry that this run did not try.
                'attempted' => 0,
                'installed' => 0,
                'still_missing' => $missing,
                'obsolete' => $surveyed->where('obsolete', true)->sum(fn (BackupSource $s): int => $s->seriesCount),
                'refused' => [],
                'allowed' => false,
            ];
        }

        $outcome = $this->installer->install($sources);

        /*
         * Counted after the install, so this is the remainder rather than the attempt.
         *
         * That is the useful number: it is what a later run should try again and what a reader
         * has to act on. The report said this many "had one installed", which was false in the
         * direction that reads as good news, so the two numbers now have separate names.
         */
        $stillMissing = $sources
            ->reject(fn (BackupSource $s): bool => $s->isInstalled || $s->obsolete)
            ->count();

        $run->update(['sources_missing' => $stillMissing]);

        $this->say(sprintf(
            'extensions: %d already here, %d installed of %d attempted, %d still missing, %d series on sources that no longer exist',
            $outcome['already'],
            $outcome['installed'],
            $outcome['attempted'],
            $stillMissing,
            $outcome['obsolete'],
        ));

        $refusals = ExtensionInstaller::describeRefusals($outcome['refused']);

        if ($refusals !== '') {
            $this->say('  '.$refusals);
        }

        return [
            'already' => $outcome['already'],
            'attempted' => $outcome['attempted'],
            'installed' => $outcome['installed'],
            'still_missing' => $stillMissing,
            'obsolete' => $outcome['obsolete'],
            'refused' => $outcome['refused'],
            'allowed' => true,
        ];
    }

    /**
     * Resolve and write, series by series, skipping anything unchanged.
     *
     * @return array<string, int>
     */
    private function applySeries(User|int $user, ImportRun $run, ImportedBackup $backup, int $limit): array
    {
        /*
         * Keyed by the resolver's tier names, so the bucketing is a lookup rather than a
         * string comparison, and so a tier added later cannot land in a bucket that does not
         * exist. `applied`, `unchanged`, `filed` and `read_chapters` are this importer's own,
         * not the resolver's.
         *
         * The one alias: the resolver calls it `unresolved` and a report calls it
         * `not_found`, because a reader asking why a series is missing is asking about the
         * file, not about the resolver's vocabulary.
         */
        $counts = [
            'resolved' => 0,
            'tier 1' => 0,
            'tier 2' => 0,
            'unresolved' => 0,
            'ambiguous' => 0,
            'missing_source' => 0,
            'unreachable_source' => 0,
            'applied' => 0,
            'unchanged' => 0,
            'filed' => 0,
            'read_chapters' => 0,
        ];

        $total = $backup->series->count();
        $done = 0;

        foreach ($backup->series as $series) {
            if ($limit > 0 && $done >= $limit) {
                break;
            }

            $done++;
            $fingerprint = Ledger::fingerprintFor($series);

            /*
             * `forUser()`, not `query()`.
             *
             * The ledger has a global scope restricting rows to whoever is authenticated, and
             * an import run from a command or a job has no authenticated user: the owner is the
             * `$user` argument. Reading through the scope therefore found nothing on the
             * second run, so every series looked new, and the unique index on
             * `(user_id, source_id, source_url)` turned the delta into a constraint violation.
             * The delta, which is the reason this is safe to interrupt, worked only in tests,
             * because the test suite authenticates.
             */
            $existing = Ledger::query()
                ->forUser($user)
                ->where('source_id', $series->sourceId)
                ->where('source_url', $series->url)
                ->first();

            // The delta. A series whose fingerprint matches has nothing to write, and that is
            // the whole point of recording one.
            if ($existing !== null && ! $existing->wantsApplyingFor($fingerprint)) {
                $counts['unchanged']++;
                $existing->update(['last_seen_run_id' => $run->id]);

                continue;
            }

            $resolution = $this->resolver->resolve($series);

            /*
             * Exactly one bucket per series. An earlier version counted every unresolved
             * resolution as `not_found` and *also* counted the ambiguous ones, so the totals
             * exceeded the library: 1,290 accounted for out of 1,277, which reads as a
             * rounding problem rather than the counting bug it was.
             */
            if ($resolution->resolved) {
                $counts['resolved']++;
                $counts[$resolution->tier]++;

                $written = $this->writer->write($user, $series, $resolution);

                $counts['applied'] += (int) $written['written'];
                $counts['read_chapters'] += $written['read_chapters'];
            } else {
                $counts[$resolution->tier]++;

                $written = [
                    'written' => false,
                    'read_chapters' => 0,
                    'chapters_seen' => 0,
                    'pointer' => null,
                    'reason' => $resolution->reason,
                ];
            }

            $ledgerRow = $this->record($user, $run, $existing, $series, $resolution, $fingerprint, $written);

            $counts['filed'] += $this->fileWithoutSource($user, $series, $resolution, $ledgerRow);

            if ($done % 25 === 0 || $done === $total) {
                $this->say(sprintf(
                    '  %d of %d series, %d written, %d unchanged, %d not found',
                    $done, $total, $counts['applied'], $counts['unchanged'], $counts['unresolved'],
                ), $done);
            }
        }

        $run->update([
            'state' => ImportRun::COMPLETE,
            'resolved' => $counts['resolved'],
            'from_catalogue' => $counts['tier 1'],
            'from_source' => $counts['tier 2'],
            'not_found' => $counts['unresolved'],
            'ambiguous' => $counts['ambiguous'],
            'series_on_missing_sources' => $counts['missing_source'],
            'applied' => $counts['applied'],
            'unchanged' => $counts['unchanged'],
            'filed_without_source' => $counts['filed'],
            'read_chapters_applied' => $counts['read_chapters'],
            'finished_at' => now(),
        ]);

        /*
         * Returned in the words a report uses rather than the tiers it counted in. The tiers
         * are an internal detail of the resolver, and the run row already stores them mapped,
         * so exposing both would invite a caller to read the wrong one.
         */
        return [
            'library_series' => $total,
            'resolved' => $counts['resolved'],
            'from_catalogue' => $counts['tier 1'],
            'from_source' => $counts['tier 2'],
            'not_found' => $counts['unresolved'],
            'ambiguous' => $counts['ambiguous'],
            'missing_source' => $counts['missing_source'],
            'unreachable_source' => $counts['unreachable_source'],
            'applied' => $counts['applied'],
            'unchanged' => $counts['unchanged'],
            'filed' => $counts['filed'],
            'read_chapters' => $counts['read_chapters'],
        ];
    }

    /**
     * Write the ledger row for one series, updating rather than inserting when it exists.
     *
     * @param  array{written: bool, read_chapters: int, chapters_seen: int, pointer: string|null, reason: string|null}  $written
     */
    private function record(
        User|int $user,
        ImportRun $run,
        ?Ledger $existing,
        ImportedSeries $series,
        Resolution $resolution,
        string $fingerprint,
        array $written,
    ): Ledger {
        /*
         * Matched on the tier, which is a named constant of what happened, rather than on
         * the wording of the reason. The earlier version searched the sentence for the phrase
         * "not installed", which put a database column at the mercy of user-facing prose: a
         * plainer sentence would have reclassified every affected series as genuinely not
         * found, and nothing would have failed.
         */
        $state = match ($resolution->resolved ? 'resolved' : $resolution->tier) {
            'resolved' => Ledger::RESOLVED,
            'ambiguous' => Ledger::AMBIGUOUS,
            'missing_source' => Ledger::MISSING_SOURCE,
            'unreachable_source' => Ledger::UNRESOLVED,
            default => Ledger::UNRESOLVED,
        };

        $attributes = [
            'import_run_id' => $run->id,
            'source_id' => $series->sourceId,
            'source_url' => $series->url,
            'title' => $series->title,
            'state' => $state,
            'reason' => $resolution->reason ?? $written['reason'],
            'backup_manga_id' => $series->backupId,
            'suwayomi_manga_id' => $resolution->suwayomiMangaId,
            'fingerprint' => $resolution->resolved ? $fingerprint : $existing?->fingerprint,
            'chapters_seen' => $written['chapters_seen'],
            'chapters_applied' => $written['read_chapters'],
            'last_seen_run_id' => $run->id,
            'last_applied_at' => $written['written'] ? now() : $existing?->last_applied_at,
            'attempts' => ($existing->attempts ?? 0) + 1,
        ];

        if ($existing === null) {
            return Ledger::createFor($user, $attributes);
        }

        $existing->update($attributes);

        return $existing;
    }

    /**
     * File a series this server has nothing for, so its title is not simply lost.
     *
     * Only for a series whose *source* this server has no extension for, which is a fact
     * nothing will change on its own. A series the source answered for and did not have at
     * that url is a different thing: the url moved, matching it safely is not possible, and
     * filing it would put a title in a reader's library that opens onto nothing at the wrong
     * address.
     *
     * Idempotent through the ledger's `series_state_id`, because the ledger row is keyed by
     * `(user_id, source_id, source_url)` and is updated in place on every run. Keying on the
     * title would collide on any two series sharing one.
     *
     * @return int 1 when a row was filed, 0 when not.
     */
    private function fileWithoutSource(
        User|int $user,
        ImportedSeries $series,
        Resolution $resolution,
        Ledger $ledger,
    ): int {
        if ($resolution->resolved || $resolution->tier !== 'missing_source') {
            return 0;
        }

        if ($ledger->series_state_id !== null) {
            $existing = SeriesState::query()->forUser($user)->find($ledger->series_state_id);

            if ($existing !== null) {
                // Keep the title current if the backup spells it differently now.
                if ($existing->title !== $series->title) {
                    $existing->update(['title' => $series->title]);
                }

                return 0;
            }
        }

        $state = SeriesState::createFor($user, [
            'suwayomi_manga_id' => null,
            'title' => $series->title,
            'unavailable_reason' => 'Its source is not installed on this server.',
            'in_library' => true,
            'added_at' => $series->addedAt ?? now(),
        ]);

        $ledger->update(['series_state_id' => $state->id]);

        return 1;
    }

    /**
     * Progress, if the caller wants it. A callable rather than a console dependency so this
     * class stays testable.
     *
     * @param  callable(string): void  $listener
     */
    public function onProgress(callable $listener): void
    {
        $this->progress = $listener;
    }

    private function say(string $line, int $done = 0): void
    {
        if ($this->activeRun !== null) {
            /*
             * Every progress line is written down, not just the ones in the series loop.
             *
             * A page polling this row shows the last line an importer said, which is how a
             * reader learns that eleven minutes of silence is spent installing extensions
             * rather than being told the page is broken.
             *
             * `series_processed` only moves when `$done` is non-zero, so the extension phase
             * does not report series it has not looked at. It stays 0, and `progress_note`
             * is what carries the run through the part before the loop starts.
             */
            $this->activeRun->update([
                'progress_note' => $line,
                ...($done > 0 ? ['series_processed' => $done] : []),
            ]);
        }

        if ($this->progress !== null) {
            ($this->progress)($line);
        }
    }
}
