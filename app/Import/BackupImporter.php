<?php

namespace App\Import;

use App\Models\ImportedSeries as Ledger;
use App\Models\ImportedSource as SourceRow;
use App\Models\ImportRun;
use App\Models\SeriesState;
use App\Models\User;
use App\Support\Library;
use App\Suwayomi\ExtensionManager;
use Illuminate\Support\Collection;
use Throwable;

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
        private readonly ExtensionManager $extensions,
    ) {}

    /**
     * Import one backup file for one reader.
     *
     * ## Why a run can be handed in
     *
     * The page shows a review before it installs anything, and that review is a run row in
     * `AWAITING_CONFIRMATION`. Confirming has to continue that row rather than create a second
     * one, or the history would show a review nobody ever answered beside an import nobody
     * asked for. The caller owns the check that the row is still awaiting and belongs to this
     * reader; this method only puts it back into `RUNNING`.
     *
     * @param  int  $limit  Stop after this many series. Zero means all of them.
     * @param  string|null  $fileName  The name the reader chose, when it differs from the path's.
     * @param  ImportRun|null  $run  An awaiting run to continue, or null to start a new one.
     */
    public function import(User|int $user, string $path, int $limit = 0, bool $installExtensions = true, bool $force = false, ?string $fileName = null, ?ImportRun $run = null): ImportResult
    {
        if ($run === null) {
            $run = ImportRun::createFor($user, [
                'format' => 'unknown',
                'state' => ImportRun::RUNNING,
                'file_name' => $fileName ?? basename($path),
                'started_at' => now(),
            ]);
        } else {
            /*
             * A resumed run keeps its own label unless the caller has a better one. The review
             * already wrote the file's name, and the confirm request does not carry it.
             */
            $run->forceFill([
                'state' => ImportRun::RUNNING,
                'file_name' => $fileName ?? $run->file_name ?? basename($path),
                'started_at' => now(),
                'finished_at' => null,
                'refusal_reason' => null,
                'progress_note' => null,
            ])->save();
        }

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
            /*
             * The file's identity, not its name.
             *
             * Imported reading days are kept per backup so that a re-import replaces
             * that file's days instead of adding a second copy, and two exports can
             * share a name. Hashing here rather than in the writer means one read of
             * the file's bytes for the whole run.
             */
            $backupKey = is_readable($path) ? hash_file('sha256', $path) : false;

            if ($backupKey === false) {
                throw new ImportRefused(
                    'That backup could not be read. It may have been moved or become unreadable. '
                    .'Nothing has been changed.'
                );
            }

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
            series: $this->applySeries($user, $run, $backup, $limit, $force, $backupKey),
            alreadyInstalled: $extensions['already'],
            attempted: $extensions['attempted'],
            installed: $extensions['installed'],
            stillMissing: $extensions['still_missing'],
            obsoleteSeries: $extensions['obsolete'],
            refusedPackages: $extensions['refused'],
            installAttempted: $extensions['allowed'],
            heldPackages: $extensions['held'],
        );
    }

    /**
     * Install what the backup needs, and record what came across.
     *
     * ## Trust is read here, not passed in from the page
     *
     * The ids come from the reader's own rows at the moment the install runs. A review is a
     * snapshot that may be minutes or hours old, and the page's ticking is only a request to
     * trust; the install asks the database what is true now. A store revoked between the
     * review and this call holds its packages rather than installing them.
     *
     * @param  Collection<int, BackupSource>  $sources
     * @return array{already: int, attempted: int, installed: int, still_missing: int, obsolete: int, refused: array<string, string>, held: array<string, string>, allowed: bool}
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
                'held' => [],
                'allowed' => false,
            ];
        }

        $outcome = $this->installer->install($sources, $this->extensions->trustedStoreIds($user));

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

        if ($outcome['held'] !== []) {
            $this->say(sprintf(
                '  %d held back because no trusted store offers them: %s',
                count($outcome['held']),
                implode(', ', array_keys($outcome['held'])),
            ));
        }

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
            'held' => $outcome['held'],
            'allowed' => true,
        ];
    }

    /**
     * Resolve and write, series by series, skipping anything unchanged.
     *
     * @return array<string, int>
     */
    private function applySeries(User|int $user, ImportRun $run, ImportedBackup $backup, int $limit, bool $force = false, ?string $backupKey = null): array
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

            /*
             * Series that raised rather than resolving. Its own bucket rather than one of the
             * resolution tiers, because "this source is broken" is a fault and "this series is
             * not at that address" is a fact about the library, and a report that adds them
             * together is the kind of number nobody can act on.
             */
            'threw' => 0,
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
             * Declared outside the try so the filing below can tell "resolved to something
             * unusable" from "threw before a resolution existed". The two are different
             * outcomes: one is a fact about the source, the other is a fault, and filing
             * collapses them.
             */
            $resolution = null;

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

            /*
             * The delta. A series whose fingerprint matches has nothing to write, and that is
             * the whole point of recording one.
             *
             * `force` is the escape hatch, and it exists because the fingerprint describes the
             * *input*, not what a given importer version manages to do with it. Measured: an
             * importer that could not pair a destination's chapter list wrote 1,053 of 40,160
             * read chapters and reported success. The fix makes the pairing work, but the
             * fingerprint is byte for byte the same, so every one of those series is now
             * "unchanged" and a plain re-run skips them. Without this flag the only recovery is
             * editing the ledger by hand.
             *
             * Safe to force because every write is forward only: a chapter is marked read if
             * either side says so, a bookmark is never cleared, and a series already in the
             * library is added to rather than rewritten. Re-applying can only add.
             */
            if (! $force && $existing !== null && ! $existing->wantsApplyingFor($fingerprint)) {
                $counts['unchanged']++;
                $existing->update(['last_seen_run_id' => $run->id]);

                continue;
            }

            /*
 * One series that throws must not end the run.
 *
 * A series is one step of a walk over a reader's whole library, so an exception on the
 * nine hundred and twenty fifth of one thousand two hundred and seventy seven costs the three
 * hundred and fifty two that follow, and the run is left reading `running` with nothing behind
 * it. Measured on the real backup: a forced re-apply got to 925 of 1,277 with 432 series'
 * read state written, and stopped on a single source whose extension answered "Could not find
 * environment bundle". The reader's read state was half restored and the run looked like it
 * had just started.
 *
 * `SuwayomiException` is the expected failure and is handled where it is thrown. What lands in
 * this catch is the unexpected: a source whose extension is broken, a response shaped wrong, a
 * cast that fails. Those are counted and skipped, because a reader who pointed this at their
 * real library asked for 1,277 series and getting 925 is a worse answer than getting 1,276.
 *
 * The series is still recorded, so the next run retries it and the report can say which one
 * and why rather than a run that looks merely short.
 */
            try {
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

                    $written = $this->writer->write($user, $series, $resolution, $backupKey);

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
            } catch (Throwable $e) {
                report($e);

                $counts['threw']++;

                $ledgerRow = $this->recordThrew($user, $run, $existing, $series, $e->getMessage());
            }

            /*
             * Only a series that got as far as a resolution can be filed, because filing is
             * what happens to a series whose source turned out to be unusable. A series that
             * threw has no resolution to file against and is recorded as an error instead.
             */
            if ($resolution !== null) {
                $counts['filed'] += $this->fileWithoutSource($user, $series, $resolution, $ledgerRow);
            }

            if ($done % 25 === 0 || $done === $total) {
                $this->say(sprintf(
                    '  %d of %d series, %d written, %d unchanged, %d not found, %d could not be read',
                    $done, $total, $counts['applied'], $counts['unchanged'], $counts['unresolved'], $counts['threw'],
                ), $done);
            }
        }

        /*
         * The shelves go on after every series has been through the loop, because a
         * membership is a claim about a ledger row (`resolved`, or filed with a local
         * row) and the last row is written inside the loop. A member scored before its
         * own row exists would land on no shelf at all.
         */
        $placement = $this->applyShelves($user, $backup);

        $run->update([
            'state' => ImportRun::COMPLETE,
            'resolved' => $counts['resolved'],
            'from_catalogue' => $counts['tier 1'],
            'from_source' => $counts['tier 2'],
            'not_found' => $counts['unresolved'],
            'ambiguous' => $counts['ambiguous'],
            'series_on_missing_sources' => $counts['missing_source'],
            'categories_in_file' => $placement['in_file'],
            'category_members_placed' => $placement['placed'],
            'category_members_skipped' => $placement['skipped'],
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
            'threw' => $counts['threw'],
        ];
    }

    /**
     * Recover the reader's arrangement: file each linked member onto a shelf of its name.
     *
     * This is the owner's two-sentence complaint closed at the second half: the categories
     * the file carries used to be reported as counts and died there. The landing goes onto
     * the shelves this server already carries (`categories` and `category_series`), through
     * the same filing operation the library page performs ({@see Library::attach()} for a
     * resolved series and {@see Library::attachState()} for one the file carried without a
     * source, filling the same four columns `ReadingState::mergeShelves()` matches for the
     * same reason).
     *
     * The per-item placement report is the ledger rows themselves: this pass stamps each
     * member's row with the names its file linked, and that row's `state` and `reason`
     * already say what did or did not resolve. Nothing here invents a second per-item
     * vocabulary to keep in step with.
     *
     * Skips are counted and named at the run level, because a member that did not land has
     * a why that is one of a handful: its series did not resolve (the row's own reason says
     * which), this run stopped before its series, or the file links a series it does not
     * carry.
     *
     * @return array{in_file: int|null, placed: int|null, skipped: int|null} Null when the file carries no categories at all, the same honesty a `.tachibk` answers with.
     */
    private function applyShelves(User|int $user, ImportedBackup $backup): array
    {
        if ($backup->categories->isEmpty()) {
            return ['in_file' => null, 'placed' => null, 'skipped' => null];
        }

        $library = app(Library::class);
        $userId = $user instanceof User ? $user->id : $user;
        $seasons = $backup->series->keyBy(fn (ImportedSeries $s): int => $s->backupId);

        $placed = 0;
        $skipped = 0;

        foreach ($backup->categories as $name => $members) {
            $landed = 0;

            /** @var array<string, int> $whys one member's skip why to the members it stopped, string keyed because the report names them */
            $whys = [];

            $shelf = $library->createCategory($user, (string) $name);

            if ($shelf === null) {
                $whys['the name carries nothing to file under'] = 1;
            } else {
                foreach ($members as $member) {
                    $why = $this->placeMember(
                        $library,
                        $userId,
                        $seasons->get($member),
                        $name,
                        $shelf->id,
                    );

                    if ($why === null) {
                        $landed++;
                        $placed++;

                        continue;
                    }

                    $skipped++;
                    $whys[$why] = ($whys[$why] ?? 0) + 1;
                }
            }

            $skipNote = $whys === []
                ? ''
                : ', skipped: '.implode(', ', array_map(
                    fn (string $why, int $n): string => "$n $why",
                    array_keys($whys),
                    array_values($whys),
                ));

            $this->say(sprintf(
                'categories: %s: %d of %d members landed%s',
                trim((string) $name),
                $landed,
                $members->count(),
                $skipNote,
            ));
        }

        return ['in_file' => $backup->categories->count(), 'placed' => $placed, 'skipped' => $skipped];
    }

    /**
     * One member of one shelf: stamp its ledger row, then land it if the reader has it.
     *
     * @return string|null Null when it landed, or the why when it was skipped.
     */
    private function placeMember(
        Library $library,
        int $userId,
        ?ImportedSeries $fromFile,
        string $name,
        int $shelfId,
    ): ?string {
        if ($fromFile === null) {
            return 'the file links a series it does not carry';
        }

        $ledger = Ledger::query()
            ->forUser($userId)
            ->where('source_id', $fromFile->sourceId)
            ->where('source_url', $fromFile->url)
            ->first();

        if ($ledger === null) {
            return 'this run never reached its series';
        }

        /*
         * The file's own record of the shelf, on the row that reports the series. Two
         * categories in the file naming the same series merge into one row, and the
         * stamping skips when the names are already exactly what this file said.
         */
        $names = $ledger->categories ?? [];
        $label = trim($name);

        if (! in_array($label, $names, true)) {
            $ledger->update(['categories' => [...$names, $label]]);
        }

        if ($ledger->suwayomi_manga_id !== null) {
            $library->attach($userId, (int) $ledger->suwayomi_manga_id, $shelfId);

            return null;
        }

        $state = SeriesState::query()->forUser($userId)->find($ledger->series_state_id);

        if ($state === null) {
            return 'its local row is gone';
        }

        $library->attachState($userId, $state, $shelfId);

        return null;
    }

    /**
     * Write the ledger row for a series that raised rather than resolved.
     *
     * Recorded rather than skipped, for two reasons. The next run has to retry it, and a
     * fingerprint-less row is exactly what makes "we could not resolve this" survive into the
     * next run instead of being retried from nothing. And a reader whose import reported
     * 1,277 series needs to be able to see which ones raised and why, rather than a run that
     * looks merely short.
     */
    private function recordThrew(
        User|int $user,
        ImportRun $run,
        ?Ledger $existing,
        ImportedSeries $series,
        string $reason,
    ): Ledger {
        $attributes = [
            'import_run_id' => $run->id,
            'source_id' => $series->sourceId,
            'source_url' => $series->url,
            // Null on purpose: an unknown fingerprint is what makes the next run try again.
            'fingerprint' => null,
            'suwayomi_manga_id' => null,
            'backup_manga_id' => $series->backupId,
            'title' => $series->title,
            'state' => Ledger::THREW,
            'reason' => $reason,
            'attempts' => ($existing === null ? 0 : (int) $existing->attempts) + 1,
            'chapters_seen' => $series->chapters->count(),
            'chapters_applied' => 0,
            'last_seen_run_id' => $run->id,
            'last_applied_at' => null,
        ];

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing;
        }

        return Ledger::createFor($user, $attributes);
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
