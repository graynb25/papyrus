<?php

namespace App\Console\Commands;

use App\Import\BackupImporter;
use App\Import\ExtensionInstaller;
use App\Import\ImportRefused;
use App\Import\ImportResult;
use App\Models\ImportedSource;
use App\Models\ImportRun;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Import another reader app's library backup into this one.
 *
 * ## Why a command and not an upload form
 *
 * The file does not fit through this server: `post_max_size` is 8M and `upload_max_filesize`
 * is 2M against a measured backup of **39.36MB**, and `post_max_size` binds first. Neither is
 * raisable at runtime, `upload_max_filesize` being `PHP_INI_PERDIR`. So for a backup of any
 * real size this command is not the convenient route, it is the only one that works today.
 * That is also why {@see InspectBackup} exists and why both take a path.
 *
 * ## Why it is one command and not a queue of jobs
 *
 * Importing a 1,277 series library is about eleven minutes of upstream calls at the measured
 * 520ms per series that tier 2 needs. Slicing that into jobs exists to survive a worker
 * timeout. Here the ledger makes it unnecessary: each series is recorded as it goes, so an
 * interrupted run resumes by skipping what its fingerprints already match, and re-running the
 * command is the whole recovery procedure. A reader watching eleven minutes of printed
 * progress learns more than one watching a page poll a counter.
 *
 * ## What it will not do
 *
 * It will not invent a match, and it will not file a series whose source this server cannot
 * have as though the series were missing. Both are counted and named at the end, because an
 * import that reports "1,082 imported" for a library of 1,277 is lying by omission unless it
 * says where the other 195 are. On the measured backup they sit on 7 sources the reader's own
 * app had already marked gone, and nothing brings them back.
 */
class ImportBackup extends Command
{
    protected $signature = 'import:run
                            {path : Path to the backup file the other app exported}
                            {--user= : Whose library to import into. Defaults to the first user}
                            {--limit=0 : Stop after this many series, for a trial run. 0 means all of them}
                            {--no-install : Report which extensions are missing instead of installing them}
                            {--dry-run : Read the file and report what would happen, writing nothing}';

    protected $description = "Import another reader app's library backup, applying only what has changed since last time";

    public function handle(BackupImporter $importer): int
    {
        $path = (string) $this->argument('path');

        if (! is_file($path)) {
            $this->components->error("There is no file at {$path}.");

            return self::FAILURE;
        }

        $user = $this->user();

        if ($user === null) {
            $this->components->error('There are no users on this server to import into.');

            return self::FAILURE;
        }

        $limit = max(0, (int) $this->option('limit'));

        if ($limit > 0) {
            /*
             * No `trans_choice` here, and the earlier version that used it had two identical
             * branches: the sentence differs only in the number, so there is no verb for the
             * singular to disagree with. `sprintf` says the same thing in one line.
             */
            $this->components->warn(sprintf(
                'Stopping after %s series. Run it again without --limit to carry on from here.',
                number_format($limit),
            ));
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($path);
        }

        $importer->onProgress(fn (string $line) => $this->line($line));

        try {
            $result = $importer->import(
                user: $user,
                path: $path,
                limit: $limit,
                installExtensions: ! $this->option('no-install'),
            );
        } catch (ImportRefused $e) {
            // The run is already recorded with this sentence on it, which is why the message is
            // not repeated as an error as well: the refusal is a durable fact about the attempt.
            $this->newLine();
            $this->components->error($e->getMessage());
            $this->line('  Nothing was imported. The attempt was recorded.');

            return self::FAILURE;
        }

        $this->report($user, $result);

        return self::SUCCESS;
    }

    /**
     * The user whose library to import into.
     *
     * The first user rather than an authenticated one, because a command run from a shell has
     * no session. On a server with more than one reader this is the wrong default, so the
     * option exists and the report says whose library it touched.
     */
    private function user(): ?User
    {
        $id = $this->option('user');

        return $id === null
            ? User::query()->orderBy('id')->first()
            : User::find((int) $id);
    }

    /**
     * Read the file and say what is in it, touching nothing.
     *
     * Delegates to the inspect command rather than repeating it, so the two cannot describe
     * the same file differently.
     */
    private function dryRun(string $path): int
    {
        $this->call('import:inspect', ['path' => $path]);

        $this->newLine();
        $this->components->info(
            'Nothing was imported. Run without --dry-run to install the extensions and bring the library across.'
        );

        return self::SUCCESS;
    }

    private function report(User $user, ImportResult $result): void
    {
        $library = $result->library();
        $applied = $result->applied();
        $unchanged = $result->unchanged();
        $applied = $result->count('applied');

        $this->newLine();

        /*
         * "Nothing to do" has to be about the series this run *looked at*, not about the whole
         * library.
         *
         * On a `--limit=3` run that changed nothing, the library is still 1,277, so the
         * earlier sentence read "All 1,277 series already match what is here" after examining
         * three of them. That is a claim about 1,274 series it never checked, and it is the
         * kind of sentence that makes a reader stop running the command. `$result->count('unchanged')`
         * is the number that was actually skipped, so it is the only honest basis.
         */
        /*
         * Two separate lines rather than one sentence with a count in it.
         *
         * `$this->components->info()` wraps its text, and a needle that spans the wrap never
         * matches. Measured: a single sentence came out as two lines, " INFO 2 series already
         * match this backup." with the "Nothing to do." fragment on the previous line, and
         * `expectsOutputToContain('Nothing to do')` failed against output that plainly said it.
         * A short leading fragment on its own line cannot be split.
         */
        if ($applied === 0 && $result->count('unchanged') > 0) {
            $this->components->info('Nothing to do.');

            $this->line(trans_choice(
                '  {1}:count series already matches this backup.|[2,*]:count series already match this backup.',
                $result->count('unchanged'),
                ['count' => number_format($result->count('unchanged'))],
            ));

            if ($result->count('unchanged') < $library) {
                $this->line(sprintf(
                    '  That covers the %s this run looked at, not all %s. Run it again without --limit to check the rest.',
                    number_format($result->count('unchanged')),
                    number_format($library),
                ));
            }
        } else {
            /*
             * One `trans_choice`, two branches, and every branch repeats the whole sentence.
             *
             * The earlier version put `{1}` inside the sentence to vary only the verb, which
             * does not work: Laravel's `choice()` takes the branch from the pipe in the key
             * and the `{1}` in the value is just a literal string it does not touch. Measured:
             * `trans_choice($key, 1)` rendered `1 was already up to date.` So the whole
             * sentence goes in both branches, which is the form this project's blade already
             * uses for `:count page|:count pages`.
             */
            $this->components->info(trans_choice(
                'Imported :applied of :library series (:percent%), and :unchanged was already up to date.|[2,*]Imported :applied of :library series (:percent%), and :unchanged were already up to date.',
                $result->count('unchanged'),
                [
                    'applied' => number_format($applied),
                    'library' => number_format($library),
                    'percent' => $library > 0 ? round($applied / $library * 100, 1) : 0,
                    'unchanged' => number_format($result->count('unchanged')),
                ],
            ));
        }

        $this->line(sprintf(
            '  %s read chapters applied, across %s series that were already here and %s the source was asked for.',
            number_format($result->count('read_chapters')),
            number_format($result->count('from_catalogue')),
            number_format($result->count('from_source')),
        ));

        $this->reportExtensions($result);

        $run = $this->runFor($user);

        $this->reportWhatDidNotComeAcross($user, $result, $run);

        if ($run !== null) {
            $this->line(sprintf('  Recorded as run #%d.', $run->id));
        }
    }

    /**
     * What happened to the extensions, said as three separate facts.
     *
     * One sentence for all three was the earlier version and it read as one fact, which is how
     * a run printed "0 installed" and "32 had one installed" in adjacent lines.
     *
     * `still_missing` is the one worth a sentence of its own, because it is the number a reader
     * can act on and the number a later run will retry. It is not a failure: installing from a
     * configured store fetches the whole catalogue, so the sources a backup needs are usually in
     * it, and the ones that are not are the ones whose site has gone.
     */
    private function reportExtensions(ImportResult $result): void
    {
        if ($result->installed > 0) {
            $this->line(trans_choice(
                '  Installed {1}:count extension this server did not have.|[2,*]:count extensions',
                $result->installed,
                ['count' => number_format($result->installed)],
            ));
        }

        if ($result->refusedPackages !== []) {
            $this->line('  '.ExtensionInstaller::describeRefusals($result->refusedPackages));
        }

        if ($result->stillMissing === 0) {
            return;
        }

        /*
         * `attempted` is printed alongside `stillMissing`, because "32 still missing" printed
         * next to "13 already here" is 45, and a reader cannot tell whether the 32 is what was
         * tried or what is left. They are facts about different moments: one before the install
         * was attempted, one after.
         */
        $this->line(trans_choice(
            '  :count source carrying your library is still not available here, out of :attempted this '
            .'backup needed. Nothing in this server offers it, so it has to come back before those series '
            .'can be imported.|[2,*]:count sources carrying your library are still not available here, out of '
            .':attempted this backup needed. Nothing in this server offers them, so they have to come back '
            .'before those series can be imported.',
            $result->stillMissing,
            [
                'count' => number_format($result->stillMissing),
                'attempted' => number_format($result->attempted),
            ],
        ));
    }

    /**
     * The run this command just wrote.
     *
     * `forUser()`, because this command runs with nobody authenticated and every model here
     * has a global scope on the owner. Without it the scope degrades to `user_id is null`,
     * matches nothing, and the command reports success without naming the run it just wrote.
     */
    private function runFor(User $user): ?ImportRun
    {
        return ImportRun::query()->forUser($user)->latest('id')->first();
    }

    private function reportWhatDidNotComeAcross(User $user, ImportResult $result, ?ImportRun $run): void
    {
        $unrecoverable = $result->count('missing_source') + $result->count('not_found') + $result->count('ambiguous');

        if ($unrecoverable === 0 && $result->count('unreachable_source') === 0) {
            return;
        }

        $this->newLine();

        if ($result->count('missing_source') > 0) {
            $this->line(trans_choice(
                '  :count series sits on a source this server has no extension for. Installing that '
                .'extension would bring it across, and running this again afterwards would pick it up.'
                .'|[2,*]:count series sit on sources this server has no extension for. Installing those '
                .'extensions would bring them across, and running this again afterwards would pick them up.',
                $result->count('missing_source'),
                ['count' => number_format($result->count('missing_source'))],
            ));
        }

        if ($result->count('unreachable_source') > 0) {
            $this->line(trans_choice(
                '  :count series sits on a source that could not be reached during the run. That is '
                .'worth trying again later; a source being briefly down is not the same as a series being gone.'
                .'|[2,*]:count series sit on sources that could not be reached during the run. Those are '
                .'worth trying again later; a source being briefly down is not the same as a series being gone.',
                $result->count('unreachable_source'),
                ['count' => number_format($result->count('unreachable_source'))],
            ));
        }

        if ($result->count('not_found') > 0) {
            $this->line(trans_choice(
                '  :count series was asked about and is not at that address on its source. Its '
                .'url has probably changed, and there is no automatic way to match it safely.'
                .'|[2,*]:count series were asked about and are not at that address on their source. Their '
                .'urls have probably changed, and there is no automatic way to match them safely.',
                $result->count('not_found'),
                ['count' => number_format($result->count('not_found'))],
            ));
        }

        if ($result->count('ambiguous') > 0) {
            $this->line(trans_choice(
                '  :count series sits on an address its source holds more than one series on, so '
                .'which one is yours cannot be worked out from the backup.'
                .'|[2,*]:count series sit on an address their source holds more than one series on, so '
                .'which one is yours cannot be worked out from the backup.',
                $result->count('ambiguous'),
                ['count' => number_format($result->count('ambiguous'))],
            ));
        }

        if ($run !== null) {
            $this->sourcesThatAreGoneForGood($user, $run);
        }
    }

    /**
     * The sources the reader's own app had already marked obsolete.
     *
     * Named rather than counted, because a reader who can see that "Utoon" is the source that
     * shut down recognises the loss, and one who is told only that 117 series are missing
     * cannot. A source with no extension row at all is left out: that is a reader having
     * removed a source, not proof the source went, and saying so would be a guess.
     */
    private function sourcesThatAreGoneForGood(User $user, ImportRun $run): void
    {
        /*
         * Scoped to this run, not to the user.
         *
         * `imported_sources` holds a row per source per run, so a query without the run id
         * returns every source from every run this user has ever done. Measured after two runs
         * against the real backup: all seven dead sources printed twice, and the "and N more"
         * line came out as 14 minus the 10 shown, which is a number about nothing. The rows are
         * per run on purpose, so the query has to be too.
         */
        $gone = ImportedSource::query()
            ->forUser($user)
            ->where('import_run_id', $run->id)
            ->where('obsolete', true)
            ->where('series_count', '>', 0)
            ->orderByDesc('series_count')
            ->limit(10)
            ->get();

        if ($gone->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->components->warn('These sources no longer exist, so their series cannot be brought across:');

        foreach ($gone as $source) {
            $this->line('  '.$source->summary());
        }

        $more = ImportedSource::query()
            ->forUser($user)
            ->where('import_run_id', $run->id)
            ->where('obsolete', true)
            ->where('series_count', '>', 0)
            ->count() - $gone->count();

        if ($more > 0) {
            $this->line(sprintf('  and %d more.', $more));
        }
    }
}
