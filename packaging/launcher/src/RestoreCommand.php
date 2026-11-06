<?php

namespace Papyrus\Launcher;

use Throwable;

/**
 * The `restore` command: put both databases back, or leave both as they were.
 *
 * ## Why this is a class and not a case in the switch
 *
 * Because the interesting part is not the file swap, it is the choreography around it, and that is
 * exactly the part a switch statement hides. Four things have to be true at once:
 *
 * 1. Nothing is stopped until the reader has been told what is about to happen and agreed to it.
 * 2. The install comes back up **whatever happens**, including when the restore itself failed and
 *    rolled back. A reader whose restore failed and who is then left with a dead install cannot tell
 *    a bad backup from a broken application.
 * 3. A refusal before the first stop costs the reader nothing but the time to read it.
 * 4. The reader is told where their previous files went, because that is the only copy they have.
 *
 * Each of those is a claim about ordering. The first version of this held the Supervisor directly,
 * which meant the tests had to build a whole install to call `start()`, and five of them died on
 * `The web server configuration is not where this install expects it`. See {@see InstallControl}.
 */
final class RestoreCommand
{
    public function __construct(
        private readonly InstallControl $control,
        private readonly string $dataDirectory,
        private readonly string $databaseFile,
        private readonly FileMover $mover = new LocalFileMover,
        ?Translator $translator = null,
    ) {
        $this->translator = $translator ?? new Translator;
    }

    private readonly Translator $translator;

    /**
     * Build one wired to the launcher, which is how the entry point uses it.
     */
    public static function forSupervisor(Supervisor $supervisor): self
    {
        return new self(
            new SupervisorControl($supervisor),
            $supervisor->dataDirectory(),
            $supervisor->databaseFile(),
            translator: $supervisor->translator(),
        );
    }

    /**
     * @param  list<string>  $argv  Everything after the command name.
     * @return int 0 when the install was left as it was found running, non-zero otherwise
     */
    public function run(array $argv, callable $say): int
    {
        $options = $this->parse($argv);

        /*
         * An unknown flag is a failure, and shows the usage on the way out.
         *
         * The first version printed the complaint and then fell through to `--help`, which returned
         * zero. So `papyrus restore backup.json --dry-run` exited successfully having done nothing,
         * which is the worst of both: a script would treat it as a restore that worked.
         */
        if ($options['unknown'] !== null) {
            $say('');
            $say($this->translator->get('common.no_option', ['option' => $options['unknown']]));
            $say($this->translator->get('common.unknown_option'));

            $this->usage($say);

            return 1;
        }

        if ($options['help']) {
            $this->usage($say);

            return 0;
        }

        if ($options['manifest'] === null) {
            $this->usage($say);

            return 1;
        }

        /*
         * The pair is read here, before anything is stopped.
         *
         * `BackupPair::read` checks the manifest's shape. The digests of the files themselves are
         * checked by the Restorer immediately before it touches anything, so a corrupt backup is still
         * a refusal rather than a half-restore. This is the first half of that pre-flight and the half
         * that needs no filesystem at all.
         */
        try {
            $pair = BackupPair::read($options['manifest'], dirname($options['manifest']));
        } catch (RestoreRefused $e) {
            $say('');
            $say($this->translator->get('restore.cannot'));
            $say('  '.$e->getMessage());

            return 1;
        }

        $live = $this->liveFiles();

        $this->describe($say, $pair, $live);

        if (! $options['yes'] && ! $this->confirm($say)) {
            $say('');
            $say($this->translator->get('common.nothing_changed'));

            return 0;
        }

        // Stopped here, not above, because everything above this line is free to refuse.
        $say('');
        $say($this->translator->get('restore.stopping'));

        $stopped = $this->control->stop($say);

        if ($stopped === []) {
            $say($this->translator->get('restore.nothing_running'));
        }

        $say($this->translator->get('restore.putting_back'));

        try {
            $outcome = (new Restorer($this->mover))->restore($pair, $live);
        } catch (Throwable $e) {
            $this->reportFailure($say, $e, $stopped !== []);

            return 1;
        }

        $this->reportSuccess($say, $outcome, $stopped !== []);

        return 0;
    }

    /**
     * The files that make up each half of the install right now.
     *
     * The write-ahead log and shared memory file beside the reader's database are not separate
     * artefacts. They belong to the database beside them, and replacing that database without them
     * is how a restored library ends up holding rows from the install that was just replaced.
     *
     * Measured, not reasoned about: a restored database with the previous WAL left beside it came
     * back with three rows where the snapshot held two, and `PRAGMA integrity_check` said `ok`
     * throughout because the result is a valid database containing a row that should not be in it.
     * On the reader's own install that WAL is 4,140,632 bytes against a 5,192,368 byte database.
     *
     * Only files that exist are listed. A database that was cleanly closed has no sidecars, and
     * asking the mover to move one that is not there would be a refusal rather than a restore.
     *
     * @return array<string, array{target: string, companions: list<string>}>
     */
    private function liveFiles(): array
    {
        $companions = [];

        foreach (['-wal', '-shm'] as $suffix) {
            if (is_file($this->databaseFile.$suffix)) {
                $companions[] = $this->databaseFile.$suffix;
            }
        }

        return [
            'papyrus' => [
                'target' => $this->databaseFile,
                'companions' => $companions,
            ],
            'suwayomi' => [
                'target' => $this->dataDirectory
                    .DIRECTORY_SEPARATOR.'suwayomi'
                    .DIRECTORY_SEPARATOR.'database.mv.db',
                'companions' => [],
            ],
        ];
    }

    /**
     * What is about to happen, in the words a reader reads before agreeing to it.
     *
     * @param  array<string, array{target: string, companions: list<string>}>  $live
     */
    private function describe(callable $say, BackupPair $pair, array $live): void
    {
        $say('');
        $say($this->translator->get('restore.about'));
        $say($this->translator->get('restore.backup', ['description' => $pair->describe($this->translator)]));
        $say('');
        $say($this->translator->get('restore.replace_both'));

        foreach (['papyrus', 'suwayomi'] as $half) {
            $say(sprintf('    %-9s %s', $half, $live[$half]['target']));
        }

        $say('');
        $say($this->translator->get('restore.explain'));
        $say('');
        $say($this->translator->get('restore.kept'));
    }

    /**
     * Ask, and read the answer.
     *
     * Deliberately not silent and deliberately not a timeout. This replaces a reader's library and
     * there is nothing to be gained by guessing on their behalf. An empty answer, which is what a
     * closed pipe or a redirected stdin gives, counts as no.
     */
    private function confirm(callable $say): bool
    {
        $say($this->translator->get('restore.confirm'));

        $answer = fgets(STDIN);

        return is_string($answer) && $this->translator->isYes($answer);
    }

    /**
     * The restore worked. Say what it did, and where the old files are.
     *
     * @param  bool  $wasRunning  Whether anything was actually stopped, and so whether anything
     *                            needs starting again.
     */
    private function reportSuccess(callable $say, RestoreOutcome $outcome, bool $wasRunning): void
    {
        if ($wasRunning) {
            $say('');
            $say($this->translator->get('restore.starting'));

            $this->control->start($say);
        }

        $say('');

        foreach ($outcome->lines($this->translator) as $line) {
            $say($line);
        }

        foreach ($outcome->rollbackLines() as $line) {
            $say($line);
        }
    }

    /**
     * The restore failed. Start the install again **before** saying anything that reads like a
     * conclusion.
     *
     * The order is deliberate. A reader scrolling to the end of this output sees the install running,
     * which is the fact that matters most, rather than a paragraph about a rollback directory.
     */
    private function reportFailure(callable $say, Throwable $e, bool $wasRunning): void
    {
        if ($wasRunning) {
            $say('');
            $say($this->translator->get('restore.starting'));

            $this->control->start($say);
        }

        $say('');
        $say($this->translator->get('restore.failed'));

        foreach (explode("\n", $e->getMessage()) as $line) {
            $say('  '.$line);
        }
    }

    /**
     * @param  list<string>  $argv
     * @return array{manifest: string|null, yes: bool, help: bool, unknown: string|null}
     */
    private function parse(array $argv): array
    {
        $manifest = null;
        $yes = false;
        $help = false;
        $unknown = null;

        foreach ($argv as $argument) {
            if ($argument === '--yes' || $argument === '-y') {
                $yes = true;
            } elseif ($argument === '--help' || $argument === '-h') {
                $help = true;
            } elseif (str_starts_with($argument, '--')) {
                // Recorded, not ignored. A reader who typed `--dry-run` and watched it restore anyway
                // has been badly served, and the failure would otherwise be silent.
                $unknown = $argument;
            } elseif ($manifest === null) {
                $manifest = $argument;
            }
        }

        return ['manifest' => $manifest, 'yes' => $yes, 'help' => $help, 'unknown' => $unknown];
    }

    private function usage(callable $say): void
    {
        $say('');
        $say($this->translator->get('restore.usage.heading'));
        $say('');
        $say($this->translator->get('restore.usage.summary'));
        $say('');
        $say($this->translator->get('restore.usage.yes'));
        $say($this->translator->get('restore.usage.help'));
        $say('');
        $say($this->translator->get('restore.usage.after'));
        $say('');
    }
}
