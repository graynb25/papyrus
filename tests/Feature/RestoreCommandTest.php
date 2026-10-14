<?php

namespace Tests\Feature;

use Papyrus\Launcher\BackupPair;
use Papyrus\Launcher\FileMover;
use Papyrus\Launcher\InstallControl;
use Papyrus\Launcher\LocalFileMover;
use Papyrus\Launcher\RestoreCommand;
use Papyrus\Launcher\RestoreRefused;
use Tests\TestCase;

/**
 * The `restore` command, and specifically the order it does its four things in.
 *
 * ## What is being pinned
 *
 * Not the file swapping, which {@see RestoreOrchestrationTest} covers. The choreography:
 *
 * 1. Nothing is stopped until the reader has agreed to it.
 * 2. The install comes back up whatever happens, including when the restore failed.
 * 3. A bad backup is refused without the install ever being stopped.
 *
 * Each of those is a claim about ordering, and an ordering claim asserted only through the end state
 * is not an ordering claim at all. A command that stopped first and checked later would leave the
 * reader's files correct and their application dead, and every assertion about the files would pass.
 *
 * ## Why the process layer is faked and the filesystem is not
 *
 * `InstallControl` is the seam, and it exists because `Supervisor::start()` launches a web server, a
 * JVM and workers. The first version held the Supervisor directly and five tests died on
 * `The web server configuration is not where this install expects it`, which is the Supervisor
 * working correctly and the test reaching past what it was testing.
 *
 * The filesystem is left alone because the guarantee is about what is on disk when the command stops.
 */
class RestoreCommandTest extends TestCase
{
    private string $workspace = '';

    /** @var list<array{event: string, names: list<string>}> */
    private array $controlCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-restorecmd-'.bin2hex(random_bytes(6));

        /*
         * The data directory is a SIBLING of the install root, not a child of it.
         *
         * Because `Supervisor` refuses a data directory inside the install, and it is right to: that
         * arrangement loses the reader's library on every reinstall. The first version of this test
         * nested them and five tests errored on a guard working exactly as intended.
         */
        mkdir($this->installDir(), 0o777, true);
        mkdir($this->backupDir(), 0o777, true);
        mkdir($this->liveDir().DIRECTORY_SEPARATOR.'suwayomi', 0o777, true);

        $this->controlCalls = [];
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);

        parent::tearDown();
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach ((array) glob($path.'/*') as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            is_dir($entry) ? $this->removeTree($entry) : @unlink($entry);
        }

        @rmdir($path);
    }

    private function installDir(): string
    {
        return $this->workspace.DIRECTORY_SEPARATOR.'install';
    }

    private function backupDir(): string
    {
        return $this->workspace.DIRECTORY_SEPARATOR.'backup';
    }

    /**
     * The reader's data directory.
     *
     * Named `data` because that is what it is. A test whose directory names describe the test rather
     * than the product is a test that has to be re-read to be understood.
     */
    private function liveDir(): string
    {
        return $this->workspace.DIRECTORY_SEPARATOR.'data';
    }

    private function databaseFile(): string
    {
        return $this->liveDir().DIRECTORY_SEPARATOR.'database.sqlite';
    }

    private function mangaFile(): string
    {
        return $this->liveDir().DIRECTORY_SEPARATOR.'suwayomi'.DIRECTORY_SEPARATOR.'database.mv.db';
    }

    private function manifestPath(string $token = '20261013-031701'): string
    {
        $papyrus = $this->backupDir().DIRECTORY_SEPARATOR.'papyrus-'.$token.'.sqlite';
        $suwayomi = $this->backupDir().DIRECTORY_SEPARATOR.'suwayomi-'.$token.'.mv.db';

        file_put_contents($papyrus, 'BACKUP-PAPYRUS');
        file_put_contents($suwayomi, 'BACKUP-SUWAYOMI');

        $manifest = $this->backupDir().DIRECTORY_SEPARATOR.'papyrus-'.$token.'.json';

        file_put_contents($manifest, json_encode([
            'version' => 1,
            'token' => $token,
            'taken_at' => '2026-10-13T03:17:01+00:00',
            'papyrus' => [
                'file' => basename($papyrus),
                'sha256' => hash_file('sha256', $papyrus),
                'bytes' => filesize($papyrus),
                'series_states' => 867,
                'chapter_states' => 21447,
            ],
            'suwayomi' => [
                'file' => basename($suwayomi),
                'sha256' => hash_file('sha256', $suwayomi),
                'bytes' => filesize($suwayomi),
                'server_version' => '2.4.2366',
            ],
        ]));

        return $manifest;
    }

    private function writeLiveInstall(): void
    {
        file_put_contents($this->databaseFile(), 'LIVE-PAPYRUS');
        file_put_contents($this->mangaFile(), 'LIVE-SUWAYOMI');
    }

    /**
     * A control that records the order it was called in, and reports `running` as already up.
     *
     * @param  list<string>  $running
     */
    private function control(array $running = ['web', 'suwayomi']): InstallControl
    {
        return new class($this->controlCalls, $running) implements InstallControl
        {
            /**
             * @param  list<array{event: string, names: list<string>}>  $log
             * @param  list<string>  $running
             */
            public function __construct(private array &$log, private readonly array $running) {}

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
                $this->log[] = ['event' => 'start', 'names' => $this->running];

                // A list, because `Supervisor::start()` answers with one line per process in the
                // order it started them. `stop()` answers with a map of name to outcome instead, and
                // the two are not interchangeable: see the table in `InstallControl`.
                $started = [];

                foreach ($this->running as $name) {
                    $started[] = $name.': started';
                    $say('  '.$name.': started');
                }

                return $started;
            }
        };
    }

    /**
     * @param  list<string>  $argv
     * @return array{code: int, output: string}
     */
    private function invoke(array $argv, ?InstallControl $control = null, ?FileMover $mover = null): array
    {
        $lines = [];

        $command = new RestoreCommand(
            $control ?? $this->control(),
            $this->liveDir(),
            $this->databaseFile(),
            $mover ?? new LocalFileMover,
        );

        $code = $command->run($argv, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        return ['code' => $code, 'output' => implode("\n", $lines)];
    }

    /**
     * The events the control saw, in order, as words.
     *
     * @return list<string>
     */
    private function events(): array
    {
        return array_map(
            static fn (array $call): string => $call['event'].'('.implode(',', $call['names']).')',
            $this->controlCalls,
        );
    }

    // ---------------------------------------------------------------------------------------------

    public function test_it_refuses_without_a_manifest_and_says_how_to_use_it(): void
    {
        $result = $this->invoke([]);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('papyrus restore <backup.json>', $result['output']);
        $this->assertSame([], $this->events(), 'nothing may be started or stopped to explain a usage error');
    }

    public function test_help_does_not_need_a_manifest_and_does_not_fail(): void
    {
        $result = $this->invoke(['--help']);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('--yes', $result['output']);
        $this->assertSame([], $this->events());
    }

    /**
     * An unreadable manifest is refused without the install being stopped.
     *
     * The ordering, not the refusal. A reader whose backup is unusable must not lose a running
     * application to find that out.
     */
    public function test_an_unreadable_manifest_is_refused_without_stopping_anything(): void
    {
        $broken = $this->backupDir().DIRECTORY_SEPARATOR.'broken.json';
        file_put_contents($broken, '{ this is not json');

        $result = $this->invoke([$broken, '--yes']);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Nothing has been changed', $result['output']);
        $this->assertSame(
            [],
            $this->events(),
            'a bad manifest must be refused before the install is touched, or a reader loses a '
            .'running application to learn their backup is unusable',
        );
    }

    /**
     * A lopsided manifest is refused the same way, and says which half is missing.
     */
    public function test_a_lopsided_manifest_names_the_missing_half(): void
    {
        $manifest = $this->backupDir().DIRECTORY_SEPARATOR.'lopsided.json';

        file_put_contents($manifest, json_encode([
            'version' => 1,
            'token' => 'lopsided',
            'taken_at' => '2026-10-13T03:17:01+00:00',
            'papyrus' => [
                'file' => 'a.sqlite',
                'sha256' => str_repeat('a', 64),
                'bytes' => 1,
                'series_states' => 0,
                'chapter_states' => 0,
            ],
        ]));

        $result = $this->invoke([$manifest, '--yes']);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString(
            'suwayomi',
            $result['output'],
            'the message has to name which half is absent, or a reader cannot tell what to fix',
        );
        $this->assertSame([], $this->events());
    }

    /**
     * An unknown flag is refused, and refuses loudly.
     *
     * Because a reader who typed `--dry-run` and watched a restore happen anyway has been badly
     * served. The first version printed the complaint and fell through to `--help`, which returned
     * zero, so a script would have recorded a restore that never ran as a restore that worked.
     */
    public function test_an_unknown_flag_fails_and_does_not_stop_anything(): void
    {
        $result = $this->invoke([$this->manifestPath(), '--dry-run']);

        $this->assertSame(1, $result['code'], 'an unknown option must not exit zero');
        $this->assertStringContainsString('There is no option --dry-run', $result['output']);
        $this->assertSame([], $this->events(), 'an unknown option is not a decision to go ahead');
    }

    /**
     * The reader is told what is about to be replaced, and that both go together.
     */
    public function test_it_says_what_will_be_replaced_before_it_does_it(): void
    {
        $this->writeLiveInstall();

        $result = $this->invoke([$this->manifestPath(), '--yes']);

        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertStringContainsString('About to restore', $result['output']);
        $this->assertStringContainsString('867', $result['output']);
        $this->assertStringContainsString('21,447', $result['output']);
        $this->assertStringContainsString(
            'Both go in or neither does',
            $result['output'],
            'the reader is about to have their library replaced, and the reason it is safe is the '
            .'one thing they cannot see for themselves',
        );
    }

    /**
     * The order: stop, then start, and stop before anything on disk changes.
     */
    public function test_the_install_is_stopped_then_started_around_the_restore(): void
    {
        $this->writeLiveInstall();

        $this->invoke([$this->manifestPath(), '--yes']);

        $this->assertSame(
            ['stop(web,suwayomi)', 'start(web,suwayomi)'],
            $this->events(),
            'exactly one stop and one start, in that order',
        );
    }

    /**
     * The install comes back up when the restore fails.
     *
     * The one that matters most and is easiest to get wrong. A reader whose restore failed and who is
     * then left with a dead install cannot distinguish a bad backup from a broken application, and
     * will conclude the second.
     */
    public function test_the_install_comes_back_up_when_the_restore_fails(): void
    {
        $this->writeLiveInstall();

        $failing = new class(new LocalFileMover) implements FileMover
        {
            public function __construct(private readonly FileMover $inner) {}

            public function move(string $from, string $to): void
            {
                $this->inner->move($from, $to);
            }

            public function copy(string $from, string $to): void
            {
                if (str_contains($to, '.mv.db')) {
                    throw new RestoreRefused('the manga half failed to copy');
                }

                $this->inner->copy($from, $to);
            }

            public function digest(string $path): string
            {
                return $this->inner->digest($path);
            }

            public function exists(string $path): bool
            {
                return $this->inner->exists($path);
            }
        };

        $result = $this->invoke([$this->manifestPath(), '--yes'], null, $failing);

        $this->assertSame(1, $result['code']);
        $this->assertSame(
            ['stop(web,suwayomi)', 'start(web,suwayomi)'],
            $this->events(),
            'the start must not be skipped by the failure path',
        );

        // And the files really are back, not merely reported as back.
        $this->assertSame('LIVE-PAPYRUS', (string) file_get_contents($this->databaseFile()));
        $this->assertSame('LIVE-SUWAYOMI', (string) file_get_contents($this->mangaFile()));
    }

    /**
     * The restart is announced before the explanation, not after it.
     *
     * Because the reader who scrolls to the end of a failure has to end up knowing the application is
     * running. A paragraph about a rollback directory read first is a paragraph about a rollback
     * directory read first.
     */
    public function test_the_restart_is_announced_before_the_failure_explanation(): void
    {
        $this->writeLiveInstall();

        $failing = new class(new LocalFileMover) implements FileMover
        {
            public function __construct(private readonly FileMover $inner) {}

            public function move(string $from, string $to): void
            {
                $this->inner->move($from, $to);
            }

            public function copy(string $from, string $to): void
            {
                throw new RestoreRefused('the copy failed');
            }

            public function digest(string $path): string
            {
                return $this->inner->digest($path);
            }

            public function exists(string $path): bool
            {
                return $this->inner->exists($path);
            }
        };

        $result = $this->invoke([$this->manifestPath(), '--yes'], null, $failing);

        $restart = strpos($result['output'], 'Starting Papyrus again');
        $explanation = strpos($result['output'], 'The restore did not complete');

        $this->assertNotFalse($restart);
        $this->assertNotFalse($explanation);
        $this->assertLessThan(
            $explanation,
            $restart,
            'the reader has to learn the application is running before they read why it went wrong',
        );
    }

    /**
     * Afterwards, the reader is told where their previous files are.
     *
     * Not a log line. The previous install is the only copy of their library they have, and it
     * disappears the first time anybody tidies the data directory.
     */
    public function test_it_reports_where_the_previous_files_went(): void
    {
        $this->writeLiveInstall();

        $result = $this->invoke([$this->manifestPath(), '--yes']);

        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertStringContainsString('kept rather than deleted', $result['output']);
        $this->assertStringContainsString('rollback-20261013-031701', $result['output']);
    }

    /**
     * The backup survives, so the restore can be repeated.
     *
     * Asserted by checking the files still exist. A restore that consumed the backup is a restore
     * that cannot be retried after a failure, and retrying is what a reader does.
     */
    public function test_the_backup_survives_the_restore(): void
    {
        $manifest = $this->manifestPath();

        $this->writeLiveInstall();

        $result = $this->invoke([$manifest, '--yes']);

        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertFileExists($manifest, 'the manifest must survive');
        $this->assertFileExists(
            $this->backupDir().DIRECTORY_SEPARATOR.'papyrus-20261013-031701.sqlite',
            'the reader half of the backup must survive, or the restore cannot be repeated',
        );
        $this->assertFileExists($this->backupDir().DIRECTORY_SEPARATOR.'suwayomi-20261013-031701.mv.db');
    }

    /**
     * The bytes that land are the backups, not a file that happened to be nearby.
     */
    public function test_the_bytes_that_land_are_the_backups(): void
    {
        $this->writeLiveInstall();

        $this->invoke([$this->manifestPath(), '--yes']);

        $this->assertSame('BACKUP-PAPYRUS', (string) file_get_contents($this->databaseFile()));
        $this->assertSame('BACKUP-SUWAYOMI', (string) file_get_contents($this->mangaFile()));
    }

    /**
     * A pair is read from the directory its manifest sits in, not from the working directory.
     *
     * Worth pinning because it is the difference between a command that works and one that only works
     * when run from a particular folder.
     */
    public function test_the_pair_is_found_beside_its_manifest(): void
    {
        $manifest = $this->manifestPath();

        $pair = BackupPair::read($manifest, dirname($manifest));

        $this->assertSame('20261013-031701', $pair->token);
        $this->assertFileExists($pair->pathOf('papyrus'));
        $this->assertFileExists($pair->pathOf('suwayomi'));
    }

    /**
     * An install that was already down is not started.
     *
     * Because a reader who stopped Papyrus to copy their database and then ran a restore does not
     * expect it to come back up on its own, and an unexpected web server is a surprise on their own
     * machine.
     */
    public function test_an_install_that_was_already_down_is_left_down(): void
    {
        $this->writeLiveInstall();

        $result = $this->invoke([$this->manifestPath(), '--yes'], $this->control(running: []));

        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertSame(['stop()'], $this->events(), 'nothing was running, so nothing should be started');
        $this->assertStringContainsString('Nothing was running', $result['output']);
    }
}
