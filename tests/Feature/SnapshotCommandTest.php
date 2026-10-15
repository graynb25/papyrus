<?php

namespace Tests\Feature;

use Papyrus\Launcher\BackupPair;
use Papyrus\Launcher\FileMover;
use Papyrus\Launcher\InstallControl;
use Papyrus\Launcher\InstallFacts;
use Papyrus\Launcher\LocalFileMover;
use Papyrus\Launcher\MangaSnapshot;
use Papyrus\Launcher\ReaderSnapshot;
use Papyrus\Launcher\RunRecord;
use Papyrus\Launcher\SnapshotCommand;
use Tests\TestCase;

/**
 * Taking a backup that is actually a pair.
 *
 * ## What is being tested and what is being stubbed
 *
 * The choreography. A snapshot stops the install, copies two files, writes a manifest and starts the
 * install again, and every part of that worth testing is about ordering and about what happens when
 * something in the middle fails.
 *
 * The two copies are stubbed. `MangaSnapshot` needs a process that is not running to copy safely and
 * `ReaderSnapshot` needs a PHP with `pdo_sqlite`, neither of which a unit test can arrange honestly,
 * and both of which have their own tests: `MangaSnapshotTest` for the proof and liveness, and
 * `ReaderSnapshotTest` for the child process, measured against the reader's own install.
 *
 * What is *not* stubbed: the directory, the files, the manifest, and the run record. "It took a pair"
 * has to mean something about the disk, because a backup that exists only as a return value is not a
 * backup. The manifest these tests produce is read back through {@see BackupPair}, which is the same
 * thing a restore does with it, so a manifest that would not restore fails here.
 */
class SnapshotCommandTest extends TestCase
{
    private string $workspace = '';

    /** @var list<array{event: string, names: list<string>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-snap-'.bin2hex(random_bytes(6));

        mkdir($this->workspace.DIRECTORY_SEPARATOR.'suwayomi', 0o777, true);

        file_put_contents($this->mangaFile(), 'H2 DATABASE BYTES');
        file_put_contents($this->databaseFile(), 'LIVE-PAPYRUS');
    }

    protected function tearDown(): void
    {
        foreach ([true, false] as $directories) {
            foreach ((array) glob($this->workspace.'/*') as $entry) {
                if (! is_string($entry)) {
                    continue;
                }

                $directories
                    ? @array_map('unlink', (array) glob($entry.'/*')) && @rmdir($entry)
                    : @unlink($entry);
            }
        }

        @rmdir($this->workspace);

        parent::tearDown();
    }

    private function backups(): string
    {
        return $this->workspace.DIRECTORY_SEPARATOR.'backups';
    }

    private function mangaFile(): string
    {
        return $this->workspace.DIRECTORY_SEPARATOR.'suwayomi'.DIRECTORY_SEPARATOR.'database.mv.db';
    }

    private function databaseFile(): string
    {
        return $this->workspace.DIRECTORY_SEPARATOR.'database.sqlite';
    }

    // -------------------------------------------------------------------------------------------
    // The fakes.
    // -------------------------------------------------------------------------------------------

    /**
     * @param  list<string>  $running
     * @param  list<string>  $startNames  What `start()` answers with. Empty means it did not come up.
     */
    private function control(array $running = ['web', 'suwayomi'], array $startNames = ['web', 'suwayomi']): InstallControl
    {
        return new class($this->calls, $running, $startNames) implements InstallControl
        {
            /**
             * @param  list<array{event: string, names: list<string>}>  $log
             * @param  list<string>  $running
             * @param  list<string>  $startNames
             */
            public function __construct(
                private array &$log,
                private readonly array $running,
                private readonly array $startNames,
            ) {}

            public function stop(callable $say): array
            {
                $this->log[] = ['event' => 'stop', 'names' => $this->running];

                $outcomes = [];

                foreach ($this->running as $name) {
                    $outcomes[$name] = 'stopped';
                    $say('  '.$name.': stopped');
                }

                return $outcomes;
            }

            public function start(callable $say): array
            {
                $this->log[] = ['event' => 'start', 'names' => $this->startNames];

                $started = [];

                foreach ($this->startNames as $name) {
                    $started[] = $name.': started';
                    $say('  '.$name.': started');
                }

                return $started;
            }
        };
    }

    /**
     * @param  list<int>  $pids
     * @param  int|null  $supervisor  A live launcher owning this install, if any.
     */
    private function facts(array $pids = [4242], ?int $supervisor = null): InstallFacts
    {
        return new class($pids, $supervisor) implements InstallFacts
        {
            /**
             * @param  list<int>  $pids
             */
            public function __construct(
                private readonly array $pids,
                private readonly ?int $supervisor,
            ) {}

            public function supervisorPid(): ?int
            {
                return $this->supervisor;
            }

            public function suwayomiPids(): array
            {
                return $this->pids;
            }

            public function suwayomiVersion(): string
            {
                return '2.4.2366';
            }

            public function phpExecutable(): string
            {
                return 'php.exe';
            }

            public function iniPath(): string
            {
                return 'php.ini';
            }
        };
    }

    /**
     * A manga half that copies what it was told to, or refuses.
     *
     * A real `MangaSnapshot` would prove a process is gone first, which needs a real pid and a real
     * process, and that is `MangaSnapshotTest`'s job rather than this one's.
     *
     * @param  array{wasRunningPid: int|null, copies: bool}  $state
     */
    private function manga(array &$state): MangaSnapshot
    {
        return new MangaSnapshot(new LocalFileMover, static function (int $pid): bool {
            return false;
        });
    }

    /** The reader half, writing a file and reporting what it found. */
    private function reader(int $series = 867, int $chapters = 21447): ReaderSnapshot
    {
        return new ReaderSnapshot(
            new LocalFileMover,
            static function (string $php, string $ini, string $script, string $database, string $target) use ($series, $chapters): array {
                file_put_contents($target, 'SNAPSHOT OF '.$database);

                return [
                    'status' => 0,
                    'output' => (string) json_encode([
                        'counts' => ['series_states' => $series, 'chapter_states' => $chapters],
                        'bytes' => filesize($target),
                    ]),
                ];
            },
        );
    }

    /**
     * @param  list<string>  $argv
     * @return array{code: int, output: string}
     */
    private function invoke(
        array $argv,
        ?InstallControl $control = null,
        ?InstallFacts $facts = null,
        ?MangaSnapshot $manga = null,
        ?ReaderSnapshot $reader = null,
    ): array {
        $lines = [];

        $command = new SnapshotCommand(
            $control ?? $this->control(),
            $facts ?? $this->facts(),
            $this->workspace,
            $this->databaseFile(),
            $manga ?? new MangaSnapshot(new LocalFileMover, static fn (int $pid): bool => false),
            $reader ?? $this->reader(),
        );

        $code = $command->run($argv, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        return ['code' => $code, 'output' => implode("\n", $lines)];
    }

    private function manifestPath(): string
    {
        $found = glob($this->backups().DIRECTORY_SEPARATOR.'backup-*.json');

        if (! is_string($found[0] ?? null)) {
            $this->fail('no manifest was written to '.$this->backups());
        }

        return $found[0];
    }

    private function record(): RunRecord
    {
        $found = glob($this->backups().DIRECTORY_SEPARATOR.'run-*.json');
        $record = RunRecord::read((string) ($found[0] ?? ''));

        if ($record === null) {
            $this->fail('no run record was written to '.$this->backups());
        }

        return $record;
    }

    // -------------------------------------------------------------------------------------------

    /**
     * The happy path produces three files and a manifest that a restore would accept.
     *
     * Read back through `BackupPair` rather than by asserting on JSON, because the question is not
     * "does the manifest have these keys" but "would a restore take it". That is the same question a
     * reader's first attempt at recovering asks.
     */
    public function test_it_writes_a_pair_a_restore_would_accept(): void
    {
        $result = $this->invoke(['--yes', '--into='.$this->backups()]);

        $this->assertSame(0, $result['code'], $result['output']);

        $pair = BackupPair::read($this->manifestPath(), $this->backups());

        $this->assertSame(867, $pair->seriesStates);
        $this->assertSame(21447, $pair->chapterStates);
        $this->assertSame('2.4.2366', $pair->suwayomiVersion);
        $this->assertFileExists($pair->pathOf('papyrus'));
        $this->assertFileExists($pair->pathOf('suwayomi'));
        $this->assertStringContainsString('867 series and 21,447 chapter states', $result['output']);
    }

    /**
     * The digests in the manifest are the digests of the files, so a restore can check them.
     */
    public function test_the_manifest_describes_the_files_that_are_there(): void
    {
        $this->invoke(['--yes', '--into='.$this->backups()]);

        $pair = BackupPair::read($this->manifestPath(), $this->backups());

        $this->assertSame(hash_file('sha256', $pair->pathOf('papyrus')), $pair->papyrusSha256);
        $this->assertSame(hash_file('sha256', $pair->pathOf('suwayomi')), $pair->suwayomiSha256);
        $this->assertSame((int) filesize($pair->pathOf('papyrus')), $pair->papyrusBytes);
    }

    /**
     * It stops before it touches anything, and starts again afterwards.
     */
    public function test_it_stops_first_and_starts_last(): void
    {
        $result = $this->invoke(['--yes', '--into='.$this->backups()]);

        $events = array_column($this->calls, 'event');

        $this->assertSame(['stop', 'start'], $events, 'the install has to be down while the files are copied');

        // The other half of the message pair: something was stopped here, so the reader is told.
        $this->assertStringContainsString(
            'Starting Papyrus again',
            $result['output'],
            'something was stopped, so the reader has to be told it is being put back',
        );
    }

    /**
     * The pid is asked for before the stop, because the stop destroys it.
     *
     * Asserted on the order rather than on the value, because the value is only interesting when the
     * order is wrong, and an ordering bug here produces a backup that copies a file a live JVM is
     * still writing.
     */
    public function test_the_pid_is_read_before_the_stop(): void
    {
        $asked = [];

        $facts = new class($asked) implements InstallFacts
        {
            /** @param list<string> $asked */
            public function __construct(private array &$asked) {}

            public function supervisorPid(): ?int
            {
                return null;
            }

            public function suwayomiPids(): array
            {
                $this->asked[] = 'pids';

                return [4242];
            }

            public function suwayomiVersion(): string
            {
                return '2.4.2366';
            }

            public function phpExecutable(): string
            {
                return 'php.exe';
            }

            public function iniPath(): string
            {
                return 'php.ini';
            }
        };

        $control = new class($this->calls, $asked) implements InstallControl
        {
            /**
             * @param  list<array{event: string, names: list<string>}>  $log
             * @param  list<string>  $asked
             */
            public function __construct(private array &$log, private array &$asked) {}

            public function stop(callable $say): array
            {
                $this->asked[] = 'stop';
                $this->log[] = ['event' => 'stop', 'names' => ['suwayomi']];

                return ['suwayomi' => 'stopped'];
            }

            public function start(callable $say): array
            {
                $this->asked[] = 'start';
                $this->log[] = ['event' => 'start', 'names' => ['suwayomi']];

                return ['suwayomi: started'];
            }
        };

        $this->invoke(['--yes', '--into='.$this->backups()], $control, $facts);

        $this->assertSame(
            ['pids', 'stop', 'start'],
            $asked,
            'the pid has to be read while the process it names still exists',
        );
    }

    /**
     * Two JVMs for one install is refused rather than resolved by taking the first.
     *
     * Because waiting for the wrong one and copying a file the other is writing produces a backup
     * that looks exactly like a backup. The refusal has to arrive before any file is written.
     */
    public function test_two_suwayomi_processes_are_refused_rather_than_one_being_guessed_at(): void
    {
        $result = $this->invoke(['--yes', '--into='.$this->backups()], null, $this->facts([111, 222]));

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('111, 222', $result['output']);
        $this->assertStringContainsString('which one is writing', $result['output']);
        $this->assertSame([], glob($this->backups().DIRECTORY_SEPARATOR.'backup-*.json'));
        $this->assertSame([], glob($this->backups().DIRECTORY_SEPARATOR.'papyrus-*.sqlite'));
        $this->assertSame([], glob($this->backups().DIRECTORY_SEPARATOR.'suwayomi-*.mv.db'), 'nothing is copied');
        $this->assertSame(['stop', 'start'], array_column($this->calls, 'event'), 'and the install comes back up');
    }

    /**
     * The install comes back up even when the snapshot failed.
     *
     * A reader whose backup failed and who is then left with a dead install cannot tell a bad backup
     * from a broken application.
     */
    public function test_the_install_comes_back_up_after_a_failure(): void
    {
        $failing = new ReaderSnapshot(
            new LocalFileMover,
            static fn (): array => ['status' => 1, 'output' => 'SQLSTATE: no such table'],
        );

        $result = $this->invoke(['--yes', '--into='.$this->backups()], null, null, null, $failing);

        $this->assertSame(1, $result['code']);
        $this->assertSame(['stop', 'start'], array_column($this->calls, 'event'));
        $this->assertStringContainsString('did not complete', $result['output']);
        $this->assertStringContainsString('no such table', $result['output']);
    }

    /**
     * A failed snapshot writes no manifest, and says which of the three files did arrive.
     *
     * The manifest is the part that makes a pair, so a snapshot that fails after copying both halves
     * has two files and nothing that binds them, and that has to read as an incomplete snapshot rather
     * than as a backup.
     */
    public function test_a_failure_leaves_no_manifest_and_says_what_did_arrive(): void
    {
        $failing = new ReaderSnapshot(
            new LocalFileMover,
            static fn (): array => ['status' => 1, 'output' => 'the copy gave no reason'],
        );

        $result = $this->invoke(['--yes', '--into='.$this->backups()], null, null, null, $failing);

        $this->assertSame([], glob($this->backups().DIRECTORY_SEPARATOR.'backup-*.json'));
        $this->assertNotEmpty(glob($this->backups().DIRECTORY_SEPARATOR.'suwayomi-*.mv.db'), 'the manga half did arrive');
        $this->assertStringContainsString('suwayomi-', $result['output']);
        $this->assertStringContainsString('not written', $result['output'], 'the reader half is named as not written');
    }

    /**
     * An install that was already down is not started, and saying so would be wrong.
     *
     * A snapshot that starts an install the reader had deliberately stopped is a snapshot that
     * changes something the reader did not ask it to change.
     */
    public function test_an_install_that_was_already_down_is_not_started(): void
    {
        $result = $this->invoke(
            ['--yes', '--into='.$this->backups()],
            $this->control(running: [], startNames: ['web']),
        );

        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertSame(['stop'], array_column($this->calls, 'event'), 'nothing was running, so nothing is started');

        /*
         * And the console must not claim it is starting something.
         *
         * The assertion above was here from the start and it passed while the command printed
         * "Starting Papyrus again..." unconditionally, because it checks what was called and not
         * what was said. Found by running it: on a real install with nothing running, the record
         * said "it was not running, so there was nothing to start" and the console said the
         * opposite.
         *
         * So the message is asserted too, here and in the case below where something was running.
         * One assertion about behaviour is not one assertion about what the reader is told.
         */
        $this->assertStringNotContainsString(
            'Starting Papyrus again',
            $result['output'],
            'nothing was started, so nothing may say that something is',
        );
    }

    /**
     * An install that does not come back up is reported as a failure, not as a successful backup.
     */
    public function test_an_install_that_does_not_come_back_up_is_a_failure(): void
    {
        $result = $this->invoke(
            ['--yes', '--into='.$this->backups()],
            $this->control(running: ['web'], startNames: []),
        );

        $this->assertSame(1, $result['code'], $result['output']);
        $this->assertStringContainsString('did not come back up', $result['output']);
        $this->assertFileExists($this->manifestPath(), 'the backup itself was fine and is still on disk');
    }

    // -------------------------------------------------------------------------------------------
    // Refusals that cost the reader nothing.
    // -------------------------------------------------------------------------------------------

    /**
     * Another launcher holding this install is refused, and nothing is stopped.
     *
     * ## The failure this exists to prevent, measured
     *
     * Run on the reader's install with `papyrus start` holding the terminal open, this is the whole
     * transcript of the two of them together:
     *
     *     papyrus start (the holder)             papyrus snapshot
     *     ------------------------------------   ----------------------------------------------
     *     scheduler stopped on its own,          stopping: 2 processes stopped
     *     shutting the rest down                 copying_manga_half: done, 49,709,056 bytes
     *     scheduler: already gone                copying_reader_half: done, 867 series
     *     queue: terminated                      writing_manifest: done
     *     web: terminated                        restarting: done, up again
     *     suwayomi: terminated                   (exit 0)
     *     kcef: could not be stopped
     *     (exit 1)
     *
     * Then the ports were free and nothing was listening on either of them.
     *
     * Neither command lied. The holder's children had genuinely died, so "stopped on its own" was
     * true of what it observed and false about why. The snapshot genuinely did start four processes,
     * and they were genuinely killed by the holder's shutdown a moment later, after the snapshot had
     * already reported `up again` and exited zero.
     *
     * Two supervisors, one install, no shared idea of who owned it: the instance lock existed and only
     * `start` ever read it. So the fix is a refusal, and it has to arrive before anything is stopped.
     */
    public function test_it_refuses_while_another_launcher_owns_this_install(): void
    {
        $result = $this->invoke(
            ['--yes', '--into='.$this->backups()],
            null,
            $this->facts(pids: [4242], supervisor: 10448),
        );

        $this->assertSame(1, $result['code'], $result['output']);
        $this->assertSame([], $this->calls, 'nothing may be stopped, or the race is still possible');
        $this->assertStringContainsString('process 10448', $result['output'], 'and it names the pid, which the reader can find');
        $this->assertStringContainsString('Close that one first', $result['output']);
        $this->assertStringContainsString('Nothing has been changed', $result['output']);
        $this->assertSame([], glob($this->backups().DIRECTORY_SEPARATOR.'backup-*.json'));
        $this->assertSame([], glob($this->backups().DIRECTORY_SEPARATOR.'suwayomi-*.mv.db'));
    }

    /**
     * The refusal is recorded, so the run does not read as one still in progress.
     */
    public function test_the_refusal_to_stop_is_recorded_and_nothing_is_left_pending(): void
    {
        $this->invoke(['--yes', '--into='.$this->backups()], null, $this->facts([4242], 10448));

        $view = $this->record()->view();

        foreach ($view['stages'] as $stage) {
            $this->assertNotSame('pending', $stage['state'], $stage['stage'].' was left pending');
        }

        $this->assertTrue($view['finished'], 'a refused run is finished, not going');
        $this->assertTrue($view['failed']);
        $this->assertStringContainsString(
            'another launcher',
            $this->stage($view, 'stopping')['message'],
        );
    }

    /**
     * An existing file at the target is refused before the install is stopped.
     */
    public function test_it_refuses_to_overwrite_and_does_not_stop_the_install(): void
    {
        mkdir($this->backups(), 0o777, true);

        $token = gmdate('Ymd-His');
        file_put_contents($this->backups().DIRECTORY_SEPARATOR.'papyrus-'.$token.'.sqlite', 'A PREVIOUS BACKUP');

        $result = $this->invoke(['--yes', '--into='.$this->backups()]);

        $this->assertSame(1, $result['code']);
        $this->assertSame([], $this->calls, 'the install must not be stopped for a refusal that costs nothing');
        $this->assertStringContainsString('will not replace it', $result['output']);
        $this->assertSame(
            'A PREVIOUS BACKUP',
            (string) file_get_contents($this->backups().DIRECTORY_SEPARATOR.'papyrus-'.$token.'.sqlite'),
            'the previous backup is untouched',
        );
    }

    /**
     * A place that cannot hold the backup is refused before the install is stopped.
     *
     * A file where a directory should be, rather than an unwritable directory. `mkdir` with
     * `recursive: true` creates every missing level, so the first version of this test pointed at a
     * path under a directory it had just made and the command succeeded, exit zero, having proved
     * nothing at all. Windows will not let an unwritable directory be arranged from a test without
     * changing ACLs on a real path, and a test that can do that is a test that can break a machine.
     */
    public function test_a_place_that_cannot_hold_the_backup_is_refused(): void
    {
        $inTheWay = $this->workspace.DIRECTORY_SEPARATOR.'in-the-way';

        file_put_contents($inTheWay, 'NOT A DIRECTORY');

        $result = $this->invoke(['--yes', '--into='.$inTheWay.DIRECTORY_SEPARATOR.'backups']);

        $this->assertSame(1, $result['code']);
        $this->assertSame([], $this->calls, 'the install must not be stopped for a refusal that costs nothing');
        $this->assertStringContainsString('could not be created', $result['output']);
        $this->assertStringContainsString('in-the-way', $result['output'], 'and it names where it tried');
    }

    /**
     * A half that disappears between being copied and the manifest being written is refused.
     *
     * ## Why this is a separate test from the one above
     *
     * Because they are different failures with different causes, and only one of them can be produced
     * by the copy itself.
     *
     * The test above arranges for the manga half never to arrive: `MangaSnapshot` refuses, and the
     * manifest is never reached. That check, in `writeManifest()`, is the second line, for the case
     * the first one cannot see: the file was copied, verified byte for byte, and then was not there
     * by the time the manifest was written. Something between the two steps removed it. A backup tool,
     * a cleanup routine, a second snapshot with the same token, a virus scanner quarantining it.
     *
     * With only the first test, removing the manifest's own check survives every test in this suite.
     * That was measured. So this one reaches the second line the only honest way available: by having
     * the reader half, which runs in between, take the manga file away.
     *
     * A contrived cause, but the window is real, and a guard with no test is a guard that will be
     * deleted by whoever tidies up next.
     */
    public function test_a_half_that_disappears_before_the_manifest_is_refused(): void
    {
        $mangaCopy = null;

        $thief = new ReaderSnapshot(
            new LocalFileMover,
            static function (string $php, string $ini, string $script, string $database, string $target) use (&$mangaCopy): array {
                file_put_contents($target, 'SNAPSHOT OF '.$database);

                // The manga half was copied and verified a moment ago. Take it away, the way a cleanup
                // routine or a scanner would, without either of them raising.
                @unlink((string) $mangaCopy);

                return [
                    'status' => 0,
                    'output' => (string) json_encode([
                        'counts' => ['series_states' => 40, 'chapter_states' => 200],
                        'bytes' => (int) filesize($target),
                    ]),
                ];
            },
        );

        // Find what the manga half will be called, without duplicating the naming rule.
        $expected = $this->backups().DIRECTORY_SEPARATOR.'suwayomi-'.gmdate('Ymd-His').'.mv.db';
        $mangaCopy = $expected;

        $result = $this->invoke(['--yes', '--into='.$this->backups()], null, null, null, $thief);

        $this->assertSame(1, $result['code'], $result['output']);
        $this->assertSame([], glob($this->backups().DIRECTORY_SEPARATOR.'backup-*.json'), 'no manifest may name a file that is gone');
        $this->assertStringContainsString('is not there', $result['output']);
        $this->assertStringContainsString('would not be a pair', $result['output']);
        $this->assertStringContainsString('Nothing has been recorded as backed up', $result['output']);
        $this->assertSame(['stop', 'start'], array_column($this->calls, 'event'), 'and the install comes back up');
    }

    /**
     * A half that reported success but produced no file is refused, and no manifest is written.
     *
     * ## The mutation this exists to kill
     *
     * Removing the "is this file actually there" check in `writeManifest()` survived every other test.
     * Every one of them arranged for the manga copy to either succeed with a file or fail before the
     * manifest was reached, so the check was never the thing that decided the outcome. This test
     * arranges for the third case.
     *
     * The shape is reachable rather than hypothetical. It is the same one `ReaderSnapshot` already
     * refuses, for the same reason: a copy that exits zero having produced nothing is the worst
     * outcome available, and a manifest naming a file that is not there restores into an empty
     * library with nothing saying why.
     *
     * A mover that copies nothing and then reports two identical digests is how this is produced
     * without starting a process. That is not the same as pretending to be the filesystem: the
     * verification code genuinely runs and genuinely cannot tell the difference, which is the point.
     */
    public function test_a_half_that_reports_success_but_produces_no_file_is_refused(): void
    {
        $silent = new class(new LocalFileMover) implements FileMover
        {
            public function __construct(private readonly FileMover $inner) {}

            public function move(string $from, string $to): void
            {
                $this->inner->move($from, $to);
            }

            public function copy(string $from, string $to): void
            {
                // Copies nothing at all, and says nothing.
            }

            public function digest(string $path): string
            {
                // Two identical answers for a file that is not there, which is the whole trick: the
                // verification in MangaSnapshot compares digests and has no other way to know.
                return str_repeat('a', 64);
            }

            public function exists(string $path): bool
            {
                return false;
            }
        };

        $manga = new MangaSnapshot($silent, static fn (int $pid): bool => false);

        $result = $this->invoke(['--yes', '--into='.$this->backups()], null, null, $manga);

        $this->assertSame(1, $result['code'], $result['output']);
        $this->assertSame([], glob($this->backups().DIRECTORY_SEPARATOR.'backup-*.json'), 'no manifest may name a file that is not there');
        $this->assertSame([], glob($this->backups().DIRECTORY_SEPARATOR.'suwayomi-*.mv.db'));
        $this->assertStringContainsString('was not copied', $result['output']);
        $this->assertStringContainsString('Nothing has been claimed as backed up', $result['output']);
        $this->assertStringContainsString('not written', $result['output'], 'and the listing says which files are missing');
    }

    /**
     * The manga half going missing is refused with its path, not as a bare failure.
     */
    public function test_a_missing_manga_database_is_refused_by_path(): void
    {
        unlink($this->mangaFile());

        $result = $this->invoke(['--yes', '--into='.$this->backups()]);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('database.mv.db', $result['output']);
        $this->assertSame([], glob($this->backups().DIRECTORY_SEPARATOR.'backup-*.json'));
    }

    // -------------------------------------------------------------------------------------------
    // Options.
    // -------------------------------------------------------------------------------------------

    public function test_an_unknown_option_fails_and_shows_the_usage(): void
    {
        $result = $this->invoke(['--dry-run', '--into='.$this->backups()]);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('There is no option --dry-run', $result['output']);
        $this->assertStringContainsString('papyrus snapshot', $result['output']);
    }

    /**
     * `--into` with no value fails rather than silently using the default.
     *
     * Because the alternative is putting a backup somewhere the reader did not ask for and then
     * printing where it went, which is a surprise rather than a default.
     */
    public function test_into_with_no_value_is_refused(): void
    {
        $result = $this->invoke(['--yes', '--into']);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('There is no option --into', $result['output']);
        $this->assertSame([], $this->calls);
    }

    public function test_help_exits_zero_and_changes_nothing(): void
    {
        $result = $this->invoke(['--help']);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('--into=DIR', $result['output']);
        $this->assertSame([], $this->calls);
        $this->assertDirectoryDoesNotExist($this->backups(), 'even the backups folder is not created');
    }

    // -------------------------------------------------------------------------------------------
    // The run record, which is the only thing a reader can be shown while the app is down.
    // -------------------------------------------------------------------------------------------

    /**
     * A successful run records one attempt per stage, not two.
     *
     * ## The failure this exists to catch, measured
     *
     * The first snapshot taken on the reader's install wrote this into its run record:
     *
     *     "copying_manga_half": { "state": "done", "attempts": 2, "message": "45,641,728 bytes..." }
     *
     * Every stage of that run read as retried. `step()` entered the stage and each step method
     * entered it again, so `RunRecord` counted two attempts for work attempted once.
     *
     * A retry that did not happen is worse than a retry that was not shown, because it trains the
     * reader to ignore that column. Every later real retry then looks like the same thing this fake
     * one did, which is the whole reason the column exists.
     */
    public function test_a_successful_run_records_one_attempt_per_stage(): void
    {
        $this->invoke(['--yes', '--into='.$this->backups()]);

        foreach ($this->record()->view()['stages'] as $stage) {
            $this->assertSame(
                1,
                $stage['attempts'],
                $stage['stage'].' reports '.$stage['attempts'].' attempts for work attempted once',
            );
            $this->assertFalse($stage['retried'], $stage['stage'].' must not read as retried');
        }
    }

    /**
     * Every stage of a successful run is accounted for, none left pending.
     */
    public function test_a_successful_run_leaves_no_stage_pending(): void
    {
        $this->invoke(['--yes', '--into='.$this->backups()]);

        $view = $this->record()->view();

        $this->assertTrue($view['finished'], 'a run with a stage still in flight reads as a run in progress');
        $this->assertFalse($view['failed']);

        foreach ($view['stages'] as $stage) {
            $this->assertSame('done', $stage['state'], $stage['stage'].' should have been done');
        }
    }

    /**
     * A failed run names the stage it failed in and marks the rest as never reached.
     *
     * The rest have to be marked, because a stage that reads as `pending` for ever is a run that
     * looks like it is still going, which is the failure the record exists to prevent.
     */
    public function test_a_failed_run_names_its_stage_and_skips_the_rest(): void
    {
        $failing = new ReaderSnapshot(
            new LocalFileMover,
            static fn (): array => ['status' => 1, 'output' => 'no such table'],
        );

        $this->invoke(['--yes', '--into='.$this->backups()], null, null, null, $failing);

        $view = $this->record()->view();
        $states = [];

        foreach ($view['stages'] as $stage) {
            $states[$stage['stage']] = $stage['state'];
        }

        $this->assertSame('failed', $states['copying_reader_half']);
        $this->assertSame('skipped', $states['writing_manifest'], 'a stage that was never reached must not read as pending');
        $this->assertSame('done', $states['stopping']);
        $this->assertSame('done', $states['restarting'], 'the install came back up, and the record says so');
        $this->assertTrue($view['failed']);
        $this->assertTrue($view['finished']);
    }

    /**
     * A refusal writes no run record at all, because nothing happened.
     *
     * The record lives in the folder the backup goes in, so it cannot exist before that folder does.
     * The first version had `RunRecord::begin()` make the folder itself, which inverted the two: a
     * reader who pointed a snapshot at a path that could not exist was told the run record could not
     * be written, naming the bookkeeping rather than the backup.
     *
     * So the refusals come first and the record is opened afterwards. A refusal here has changed
     * nothing, so there is nothing to show a reader, and writing an empty shell of a run would be the
     * lie this whole mechanism exists to avoid.
     */
    public function test_a_refusal_writes_no_run_record_and_leaves_nothing_behind(): void
    {
        mkdir($this->backups(), 0o777, true);

        $token = gmdate('Ymd-His');
        file_put_contents($this->backups().DIRECTORY_SEPARATOR.'suwayomi-'.$token.'.mv.db', 'A PREVIOUS BACKUP');

        $result = $this->invoke(['--yes', '--into='.$this->backups()]);

        $this->assertSame(1, $result['code']);
        $this->assertSame([], glob($this->backups().DIRECTORY_SEPARATOR.'run-*.json'), 'no run, no record');
        $this->assertSame([], glob($this->backups().DIRECTORY_SEPARATOR.'backup-*.json'));
        $this->assertSame([], $this->calls);
        $this->assertStringContainsString('Nothing has been changed', $result['output']);
    }

    /**
     * A refusal before the folder exists does not leave the folder behind either.
     */
    public function test_a_refusal_does_not_create_the_backups_folder(): void
    {
        $inTheWay = $this->workspace.DIRECTORY_SEPARATOR.'in-the-way';

        file_put_contents($inTheWay, 'NOT A DIRECTORY');

        $this->invoke(['--yes', '--into='.$inTheWay.DIRECTORY_SEPARATOR.'backups']);

        $this->assertDirectoryDoesNotExist($inTheWay.DIRECTORY_SEPARATOR.'backups');
    }

    /**
     * One stage out of a run's view, or null.
     *
     * @param  array<string, mixed>  $view
     * @return array<string, mixed>|null
     */
    private function stage(array $view, string $name): ?array
    {
        foreach ($view['stages'] as $stage) {
            if ($stage['stage'] === $name) {
                return $stage;
            }
        }

        return null;
    }
}
