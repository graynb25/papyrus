<?php

namespace Papyrus\Launcher;

use Throwable;

/**
 * The `snapshot` command: take both halves of a backup, or take neither.
 *
 * ## Why this command exists and what it is the only way to get
 *
 * Because a backup could be restored but not taken. Every half of the machinery was here already and
 * no command joined it up, so the only thing a reader could do was `artisan papyrus:snapshot`, which
 * writes `papyrus-20261013-031701.sqlite` beside the database and calls itself a backup.
 *
 * It is not one. The two databases reference each other by id: `series_states.suwayomi_manga_id`
 * points at a row in Suwayomi's H2 file. A file with one half of a pair cannot be restored, and the
 * refusal to restore it arrives as a library that reads as empty with nothing saying why.
 *
 * ## The order, and why each part of it is where it is
 *
 *     1. work out where it is going and refuse on anything already there
 *     2. find Suwayomi's pid, before the stop destroys it
 *     3. stop the install
 *     4. copy the manga half, having proved the process writing it is gone
 *     5. copy the reader half
 *     6. write the manifest that binds the two together
 *     7. start the install again, including when a step failed
 *
 * Step 1 is before step 3 because every step after it costs the reader their install being down, and a
 * refusal that arrives once it is down is a refusal they paid for. Step 2 is before step 3 for the same
 * reason and because the pid does not exist afterwards.
 *
 * Step 7 is last and unconditional. A reader whose snapshot failed and who is then left with a dead
 * install cannot tell a bad backup from a broken application.
 *
 * ## Why the run is recorded as it goes rather than printed and forgotten
 *
 * Because Papyrus is stopped for the middle of this, so anything that showed progress would be served
 * by the thing being stopped. The launcher is still running, so the launcher writes each stage down,
 * and the application reads the result when it comes back. See {@see RunRecord}.
 */
final class SnapshotCommand
{
    public function __construct(
        private readonly InstallControl $control,
        private readonly InstallFacts $facts,
        private readonly string $dataDirectory,
        private readonly string $databaseFile,
        private readonly MangaSnapshot $manga = new MangaSnapshot,
        private readonly ReaderSnapshot $reader = new ReaderSnapshot,
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
        $control = new SupervisorControl($supervisor);

        return new self(
            $control,
            $control,
            $supervisor->dataDirectory(),
            $supervisor->databaseFile(),
            translator: $supervisor->translator(),
        );
    }

    /**
     * @param  list<string>  $argv  Everything after the command name.
     * @return int 0 when a pair was written, non-zero otherwise
     */
    public function run(array $argv, callable $say): int
    {
        $options = $this->parse($argv);

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

        $token = gmdate('Ymd-His');
        $directory = $options['into'] ?? $this->defaultDirectory();

        /** @var array{manifest: string, reader: string, manga: string, record: string} $paths */
        $paths = [
            'manifest' => $directory.DIRECTORY_SEPARATOR.'backup-'.$token.'.json',
            'reader' => $directory.DIRECTORY_SEPARATOR.'papyrus-'.$token.'.sqlite',
            'manga' => $directory.DIRECTORY_SEPARATOR.'suwayomi-'.$token.'.mv.db',
            'record' => $directory.DIRECTORY_SEPARATOR.'run-'.$token.'.json',
        ];

        $say('');
        $say($this->translator->get('snapshot.heading'));
        $say('');
        $say($this->translator->get('snapshot.going_to', ['directory' => $directory]));
        $say($this->translator->get('snapshot.both_halves', ['file' => basename($this->mangaFile())]));

        /*
         * The destination is checked before the run is written down, not after.
         *
         * The record lives in the folder the backup goes in, so it cannot be written until that folder
         * exists. Which means the cheap refusals have to happen first, and the record is opened
         * afterwards to say what they found. The alternative was letting `RunRecord::begin()` make the
         * directory, and then a reader who pointed a snapshot at a path that could not exist was told
         * the run record could not be written, which names the wrong problem and points at the
         * bookkeeping rather than at the backup.
         *
         * Nothing is lost by checking first. A refusal here has changed nothing, so there is no run
         * worth recording, and a run that recorded its own refusal would have needed the thing it is
         * refusing to exist first.
         */
        try {
            $this->checkTarget($directory, [
                $paths['reader'], $paths['manga'], $paths['manifest'], $paths['record'],
            ]);
        } catch (RestoreRefused $e) {
            $say('');
            $say($this->translator->get('common.nothing_changed'));
            $say('');
            $say('  '.$e->getMessage());
            $say('');

            return 1;
        }

        try {
            $record = RunRecord::begin(
                $paths['record'],
                'snapshot',
                RunRecord::SNAPSHOT_STAGES,
                $token,
                gmdate('c'),
            );
        } catch (RestoreRefused $e) {
            $say('');
            $say($this->translator->get('common.nothing_changed'));
            $say('');
            $say('  '.$e->getMessage());
            $say('');

            return 1;
        }

        $record->enter('checking_target', 'there was room and nothing was in the way');
        $record->finish('checking_target', 'nothing is in the way');

        if (! $options['yes'] && ! $this->confirm($say)) {
            $say('');
            $say($this->translator->get('common.nothing_changed'));

            $this->abandon($record, 'declined, so the snapshot never started');

            return 0;
        }

        /*
         * The pid, before the stop.
         *
         * Read once and held, because `stopAll` destroys it and the copy has to wait for exactly this
         * process. Two JVMs answering for one data directory is refused inside the manga step rather
         * than resolved by taking the first, because waiting for the wrong one and copying a file the
         * other is writing produces a backup that looks like a backup.
         */
        /*
         * Is another launcher supervising this install?
         *
         * Checked here, after the confirmation and before anything is stopped, because the cost of
         * being wrong is two supervisors fighting over one install. Measured on the reader's install,
         * with `papyrus start` holding the terminal open:
         *
         *     the holder saw its children die, said "stopped on its own", shut the rest down, exit 1
         *     the snapshot finished every stage, said "up again", exit 0
         *     the install was down, and both had reported honestly
         *
         * Refused rather than waited on. The reader can close the other window and try again; they
         * cannot act on a message telling them to wait for a process they cannot see. The lock names
         * the pid, so the message names something they can find.
         *
         * This is checked before `stopping` is entered, so a refusal has not stopped anything and the
         * run is abandoned rather than half-reported.
         */
        if (($supervisor = $this->facts->supervisorPid()) !== null) {
            $record->fail('stopping', sprintf('another launcher (pid %d) is running this install', $supervisor));
            $this->abandon($record, 'refused because another launcher is running this install');
            $this->listWhatIsThere($say, $directory, $paths, true);

            $say('');
            $say($this->translator->get('common.nothing_changed'));
            $say('');
            $say($this->translator->get('snapshot.other_launcher', ['pid' => $supervisor]));
            $say('');

            return 1;
        }

        $pids = $this->facts->suwayomiPids();
        $wasRunningPid = count($pids) === 1 ? $pids[0] : null;

        $record->enter('stopping', 'asking the launcher to stop everything');

        $say('');
        $say($this->translator->get('snapshot.stopping'));

        $stopped = $this->control->stop($say);

        if ($stopped === []) {
            $say($this->translator->get('snapshot.nothing_running'));
        }

        $wasRunning = $stopped !== [];

        $record->finish('stopping', $wasRunning
            ? count($stopped).' processes stopped'
            : 'nothing was running');

        $failure = null;
        $held = null;

        try {
            $this->step($record, 'copying_manga_half', function () use ($paths, $wasRunningPid, $pids, $record): null {
                $this->copyManga($paths['manga'], $wasRunningPid, $pids, $record);

                return null;
            });

            $held = $this->step($record, 'copying_reader_half', fn (): ReaderSnapshotResult => $this->copyReader($paths['reader'], $record));

            $this->step($record, 'writing_manifest', function () use ($paths, $token, $held, $record): null {
                $this->writeManifest($paths, $token, $held, $record);

                return null;
            });
        } catch (Throwable $e) {
            $failure = $e;
        }

        return $this->conclude($say, $record, $failure, $wasRunning, $directory, $paths, $held);
    }

    // -----------------------------------------------------------------------------------------
    // Steps.
    // -----------------------------------------------------------------------------------------

    /**
     * Enter a stage, run the work, finish it, and record a failure against the stage it happened in.
     *
     * One wrapper rather than a try, catch, record and break at each of the three steps, because
     * forgetting the record on one of them is exactly how a failed stage ends up reading as still
     * running for ever, which is the failure {@see RunRecord} exists to prevent.
     *
     * A stage that raises is marked failed here rather than left `running`, so that a run which stops
     * mid-step says which step it stopped in rather than appearing to still be working.
     *
     * @template T
     *
     * @param  callable(): T  $body
     * @return T
     */
    private function step(RunRecord $record, string $stage, callable $body): mixed
    {
        /*
         * The stage is entered by the body, not here, and that is the whole reason for the note.
         *
         * The first version entered it here and again in each step method, so `RunRecord` counted two
         * attempts for work that was attempted once. Measured on the reader's install, on the first
         * snapshot this launcher ever took:
         *
         *     "copying_manga_half": { "state": "done", "attempts": 2, ... }
         *
         * Every stage of that run read as retried, in the record whose entire purpose is to tell a
         * reader what actually happened. A retry that did not happen is worse than a retry that was
         * not shown: it makes every future real retry invisible, because the record has already
         * taught the reader to ignore that column.
         */
        try {
            return $body();
        } catch (Throwable $e) {
            $record->fail($stage, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Refuse on anything already there, and make the directory.
     *
     * Every refusal here is free. Nothing is stopped, nothing is copied and nothing is overwritten, so
     * a reader who runs this twice in the same second loses only the time to read why.
     *
     * @param  list<string>  $targets
     *
     * @throws RestoreRefused
     */
    private function checkTarget(string $directory, array $targets): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0o777, true) && ! is_dir($directory)) {
            throw new RestoreRefused($this->translator->get('snapshot.target_not_created', [
                'directory' => $directory,
            ]));
        }

        if (! is_writable($directory)) {
            throw (new RestoreRefused($this->translator->get('snapshot.target_not_writable', [
                'directory' => $directory,
            ])))->attempted($directory);
        }

        foreach ($targets as $target) {
            if (file_exists($target)) {
                throw (new RestoreRefused($this->translator->get('snapshot.target_exists', [
                    'path' => $target,
                ])))->attempted($target);
            }
        }
    }

    /**
     * The manga half, refused outright if there is more than one JVM answering for this install.
     *
     * @param  list<int>  $pids
     *
     * @throws RestoreRefused
     */
    private function copyManga(string $target, ?int $wasRunningPid, array $pids, RunRecord $record): void
    {
        if (count($pids) > 1) {
            throw (new RestoreRefused($this->translator->get('snapshot.manga_multiple', [
                'count' => count($pids),
                'pids' => implode(', ', $pids),
            ])))->attempted(implode(', ', $pids));
        }

        $record->enter('copying_manga_half', $wasRunningPid === null
            ? 'Suwayomi was not running, so nothing is writing this file'
            : 'waiting for Suwayomi (pid '.$wasRunningPid.') to go');

        if (! $this->manga->tryCapture($this->mangaFile(), $target, $wasRunningPid, 2)) {
            throw (new RestoreRefused($this->translator->get('snapshot.manga_not_copied')))->attempted($target);
        }

        /*
         * The size is asked for after asking whether there is a file, because `filesize()` on a path
         * that is not there emits a warning and returns false rather than refusing.
         *
         * The first version read it unconditionally, on the strength of `tryCapture` having returned
         * true. A mutation run then produced, as a real failure on a real run:
         *
         *     filesize(): stat failed for ...\suwayomi-20261014-190228.mv.db
         *
         * as the reader-facing explanation for a refused snapshot. A warning is not an explanation.
         */
        $record->finish('copying_manga_half', is_file($target)
            ? number_format((int) filesize($target)).' bytes, matching the original'
            : 'reported as copied, but nothing arrived');
    }

    /**
     * The reader half, copied by the install's own PHP so the copy is consistent by construction.
     *
     * @throws RestoreRefused
     */
    private function copyReader(string $target, RunRecord $record): ReaderSnapshotResult
    {
        $record->enter('copying_reader_half', 'asking the install\'s own PHP to VACUUM INTO a new file');

        $result = $this->reader->capture(
            $this->databaseFile,
            $target,
            $this->facts->phpExecutable(),
            $this->facts->iniPath(),
        );

        $record->finish('copying_reader_half', $result->describe());

        return $result;
    }

    /**
     * The manifest, which is what makes two files a pair rather than a coincidence.
     *
     * Written last, and only if both halves exist, because {@see BackupPair} refuses a manifest
     * naming one half, and a manifest naming one half restores into a library that reads as empty
     * with nothing saying why.
     *
     * The Suwayomi version is read here, after the stop, and that is safe because it comes from the
     * jar's name on disk rather than from the server that has just been shut down.
     *
     * @param  array{manifest: string, reader: string, manga: string, record: string}  $paths
     *
     * @throws RestoreRefused
     */
    private function writeManifest(array $paths, string $token, ?ReaderSnapshotResult $held, RunRecord $record): void
    {
        $record->enter('writing_manifest', 'binding the two halves together');

        foreach ([$paths['reader'], $paths['manga']] as $half) {
            if (! is_file($half)) {
                throw (new RestoreRefused($this->translator->get('snapshot.manifest_half_missing', [
                    'file' => basename($half),
                ])))->attempted($half);
            }
        }

        /*
         * Refused rather than written as zeroes.
         *
         * Two zeroes in a manifest is a believable number for an empty library, so a snapshot that
         * could not establish what it copied has to say so instead. A confident wrong answer beats no
         * answer only when the reader cannot tell them apart, and this is a case where they cannot.
         */
        if ($held === null) {
            throw new RestoreRefused($this->translator->get('snapshot.manifest_no_counts'));
        }

        $encoded = json_encode([
            'token' => $token,
            'taken_at' => gmdate('c'),
            'papyrus' => [
                'file' => basename($paths['reader']),
                'sha256' => hash_file('sha256', $paths['reader']),
                'bytes' => (int) filesize($paths['reader']),
                'series_states' => $held->seriesStates,
                'chapter_states' => $held->chapterStates,
            ],
            'suwayomi' => [
                'file' => basename($paths['manga']),
                'sha256' => hash_file('sha256', $paths['manga']),
                'bytes' => (int) filesize($paths['manga']),
                'server_version' => $this->facts->suwayomiVersion(),
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false || @file_put_contents($paths['manifest'], $encoded) === false) {
            throw (new RestoreRefused($this->translator->get('snapshot.manifest_write_failed', [
                'path' => $paths['manifest'],
            ])))->attempted($paths['manifest']);
        }

        $record->finish('writing_manifest', basename($paths['manifest']).', naming both halves');
    }

    // -----------------------------------------------------------------------------------------

    /**
     * Start the install again, then say what happened.
     *
     * Restart before reporting, always. A reader scrolling to the end sees the install running, which
     * is the fact that matters most, rather than a paragraph about a backup directory.
     *
     * @param  array{manifest: string, reader: string, manga: string, record: string}  $paths
     */
    private function conclude(
        callable $say,
        RunRecord $record,
        ?Throwable $failure,
        bool $wasRunning,
        string $directory,
        array $paths,
        ?ReaderSnapshotResult $held,
    ): int {
        $record->enter('restarting', 'bringing Papyrus back up');

        /*
         * Only when something was stopped, which is what RestoreCommand already does and what the
         * run record two lines below says in words.
         *
         * This line printed unconditionally, so a reader who closed their Papyrus window and ran a
         * snapshot was told "Starting Papyrus again..." while nothing was started and nothing was
         * listening. Measured on a real install on 2026-10-15: the record said "it was not running,
         * so there was nothing to start" and the console said it was starting. A message that
         * describes an action that did not happen is the same shape as a fallback that names a
         * broken thing, and a reader who believes their app came back will not go and look.
         */
        if ($wasRunning) {
            $say('');
            $say($this->translator->get('snapshot.restarting'));
        }

        $started = $wasRunning ? $this->control->start($say) : [];

        if ($wasRunning && $started === []) {
            $record->fail('restarting', 'Papyrus did not come back up');
            $this->abandon($record, 'the install did not come back up, so nothing after it was reached');

            $say('');
            $say($this->translator->get('snapshot.not_back'));
            $say('');

            $this->listWhatIsThere($say, $directory, $paths, $failure !== null);

            return 1;
        }

        $record->finish('restarting', $wasRunning ? 'up again' : 'it was not running, so there was nothing to start');

        if ($failure !== null) {
            $this->abandon($record, 'the snapshot stopped before reaching this');

            $say('');
            $say($this->translator->get('snapshot.failed'));

            foreach (explode("\n", $failure->getMessage()) as $line) {
                $say('  '.$line);
            }

            $this->listWhatIsThere($say, $directory, $paths, true);

            return 1;
        }

        $say('');
        $say($this->translator->get('snapshot.pair_written'));
        $say('    '.$directory);
        $say('');
        $say($this->translator->get('snapshot.holds', ['counts' => $held?->describe($this->translator)]));
        $say('');
        $say($this->translator->get('snapshot.restore_hint', ['manifest' => $paths['manifest']]));
        $say('');

        return 0;
    }

    /**
     * Say exactly which of the three files exist.
     *
     * Named individually rather than as "a partial backup", because a reader deciding whether to keep
     * something cannot act on that, and because a half-written pair beside a whole one is the state
     * this listing exists to describe rather than hide.
     *
     * @param  array{manifest: string, reader: string, manga: string, record: string}  $paths
     */
    private function listWhatIsThere(callable $say, string $directory, array $paths, bool $incomplete): void
    {
        $say('');
        $say($this->translator->get('snapshot.now_holds', ['directory' => $directory]));

        foreach (['reader', 'manga', 'manifest'] as $which) {
            $say(sprintf(
                '    %-44s %s',
                basename($paths[$which]),
                is_file($paths[$which])
                    ? $this->translator->get('snapshot.bytes', ['bytes' => number_format((int) filesize($paths[$which]))])
                    : $this->translator->get('snapshot.not_written'),
            ));
        }

        if ($incomplete) {
            $say('');
            $say($this->translator->get('snapshot.no_manifest'));
        }

        $say('');
    }

    /**
     * Mark every stage that was never reached as deliberately not reached.
     *
     * Without this they sit at `pending` for ever, which is a lie told by leaving something out rather
     * than by saying anything false. A run that stopped three stages in has to say that it did.
     */
    private function abandon(RunRecord $record, string $why): void
    {
        $pending = [];

        foreach ($record->view()['stages'] as $stage) {
            if ($stage['state'] === 'pending') {
                $pending[] = $stage['stage'];
            }
        }

        foreach ($pending as $stage) {
            $record->skip($stage, $why);
        }
    }

    /**
     * Ask, and read the answer.
     *
     * Not silent and not a timeout, because this stops the install and there is nothing to be gained
     * by guessing on the reader's behalf. An empty answer, which is what a closed pipe or a redirected
     * stdin gives, counts as no.
     */
    private function confirm(callable $say): bool
    {
        $say('');
        $say($this->translator->get('snapshot.confirm'));

        $answer = fgets(STDIN);

        return is_string($answer) && $this->translator->isYes($answer);
    }

    private function mangaFile(): string
    {
        return $this->dataDirectory.DIRECTORY_SEPARATOR.'suwayomi'.DIRECTORY_SEPARATOR.'database.mv.db';
    }

    private function defaultDirectory(): string
    {
        return $this->dataDirectory.DIRECTORY_SEPARATOR.'backups';
    }

    /**
     * @param  list<string>  $argv
     * @return array{into: string|null, yes: bool, help: bool, unknown: string|null}
     */
    private function parse(array $argv): array
    {
        $into = null;
        $yes = false;
        $help = false;
        $unknown = null;

        foreach ($argv as $argument) {
            if ($argument === '--yes' || $argument === '-y') {
                $yes = true;
            } elseif ($argument === '--help' || $argument === '-h') {
                $help = true;
            } elseif ($argument === '--into') {
                /*
                 * Recorded as unknown rather than half handled.
                 *
                 * `--into` with no value would otherwise fall back to the default, put a backup
                 * somewhere the reader did not ask for, and then print where it went. A flag that
                 * cannot be satisfied has to fail loudly.
                 */
                $unknown = '--into';
            } elseif (str_starts_with($argument, '--into=')) {
                $into = substr($argument, 7);

                if (trim($into) === '') {
                    $unknown = '--into';
                }
            } elseif (str_starts_with($argument, '--')) {
                $unknown = $argument;
            }
        }

        return ['into' => $into, 'yes' => $yes, 'help' => $help, 'unknown' => $unknown];
    }

    private function usage(callable $say): void
    {
        $say('');
        $say($this->translator->get('snapshot.usage.heading'));
        $say('');
        $say($this->translator->get('snapshot.usage.summary'));
        $say('');
        $say($this->translator->get('snapshot.usage.into'));
        $say($this->translator->get('snapshot.usage.yes'));
        $say($this->translator->get('snapshot.usage.help'));
        $say('');
        $say($this->translator->get('snapshot.usage.both'));
        $say('');
    }
}
