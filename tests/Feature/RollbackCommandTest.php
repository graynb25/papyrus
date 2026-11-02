<?php

namespace Tests\Feature;

use Papyrus\Launcher\InstallControl;
use Papyrus\Launcher\InstallFacts;
use Papyrus\Launcher\LocalFileMover;
use Papyrus\Launcher\MigrationRecord;
use Papyrus\Launcher\RollbackCommand;
use PDO;
use Tests\TestCase;

final class RollbackCommandTest extends TestCase
{
    private string $data;

    private string $install;

    private string $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->data = $this->scratchPath('rollback/data');
        $this->install = $this->scratchPath('rollback/install');

        file_put_contents(
            $this->install.DIRECTORY_SEPARATOR.'version.txt',
            "version 1.5.2\ncommit 090c096\nbuilt 2026-11-01 00:00:00+00:00\n",
        );

        $this->database = $this->data.DIRECTORY_SEPARATOR.'database.sqlite';
    }

    public function test_it_restores_the_recorded_snapshot_and_saves_the_current_database(): void
    {
        $snapshot = $this->snapshot('old', '2026_01_01_000000_old');
        $this->database('new', '2026_02_02_000000_new');

        $record = new MigrationRecord($this->data, $this->install);
        $record->write($snapshot);

        $control = $this->control(['web' => 'stopped']);
        $result = $this->invoke(['--yes'], $control, $this->facts(null), $record);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('Restored the reader database', $result['output']);
        $this->assertSame(1, $control->stops);
        $this->assertSame('old', $this->marker($this->database), 'the snapshot is back');
        $this->assertSame('old', $this->marker($snapshot), 'the snapshot is not consumed');
        $this->assertNotSame([], glob($this->data.DIRECTORY_SEPARATOR.'database-before-rollback-*.sqlite') ?: []);
    }

    public function test_an_explicit_snapshot_is_not_checked_against_another_files_digest(): void
    {
        $recorded = $this->snapshot('old', '2026_01_01_000000_old');
        $other = $this->data.DIRECTORY_SEPARATOR.'reader-half-other.sqlite';
        $this->createDatabase($other, 'other', '2026_01_01_000000_other');
        $this->database('new', '2026_02_02_000000_new');

        $record = new MigrationRecord($this->data, $this->install);
        $record->write($recorded);

        $control = $this->control(['web' => 'stopped']);
        $result = $this->invoke(['--snapshot='.$other, '--yes'], $control, $this->facts(null), $record);

        $this->assertSame(0, $result['code']);
        $this->assertSame('other', $this->marker($this->database));
    }

    public function test_it_refuses_while_another_launcher_is_running(): void
    {
        $snapshot = $this->snapshot('old', '2026_01_01_000000_old');
        $this->database('new', '2026_02_02_000000_new');

        $control = $this->control(['web' => 'stopped']);
        $result = $this->invoke(['--snapshot='.$snapshot, '--yes'], $control, $this->facts(123));

        $this->assertSame(1, $result['code']);
        $this->assertSame(0, $control->stops);
        $this->assertSame('new', $this->marker($this->database));
    }

    public function test_it_refuses_when_a_process_survives_the_stop(): void
    {
        $snapshot = $this->snapshot('old', '2026_01_01_000000_old');
        $this->database('new', '2026_02_02_000000_new');

        $control = $this->control(['web' => 'still running']);
        $result = $this->invoke(['--snapshot='.$snapshot, '--yes'], $control, $this->facts(null));

        $this->assertSame(1, $result['code']);
        $this->assertSame(1, $control->stops);
        $this->assertSame('new', $this->marker($this->database), 'nothing is replaced when the install is not down');
    }

    public function test_it_refuses_a_snapshot_that_is_not_a_reader_database_without_stopping(): void
    {
        $broken = $this->data.DIRECTORY_SEPARATOR.'broken.sqlite';
        file_put_contents($broken, 'not a database');

        $control = $this->control(['web' => 'stopped']);
        $result = $this->invoke(['--snapshot='.$broken, '--yes'], $control, $this->facts(null));

        $this->assertSame(1, $result['code']);
        $this->assertSame(0, $control->stops);
        $this->assertStringContainsString('Nothing has been changed', $result['output']);
    }

    public function test_it_refuses_when_no_snapshot_is_recorded(): void
    {
        $control = $this->control(['web' => 'stopped']);
        $result = $this->invoke(['--yes'], $control, $this->facts(null));

        $this->assertSame(1, $result['code']);
        $this->assertSame(0, $control->stops);
        $this->assertStringContainsString('no recorded pre-migration snapshot', $result['output']);
    }

    private function snapshot(string $marker, string $migration): string
    {
        $path = $this->data.DIRECTORY_SEPARATOR.'reader-half-20261101-000000.sqlite';
        $this->createDatabase($path, $marker, $migration);

        return $path;
    }

    private function database(string $marker, string $migration): void
    {
        $this->createDatabase($this->database, $marker, $migration);
    }

    private function createDatabase(string $path, string $marker, string $migration): void
    {
        $pdo = new PDO('sqlite:'.$path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('create table migrations (id integer primary key autoincrement, migration text, batch integer)');
        $pdo->prepare('insert into migrations (migration, batch) values (?, 1)')->execute([$migration]);
        $pdo->exec('create table markers (value text)');
        $pdo->prepare('insert into markers (value) values (?)')->execute([$marker]);
    }

    private function marker(string $path): string
    {
        $pdo = new PDO('sqlite:'.$path);

        return (string) $pdo->query('select value from markers limit 1')->fetchColumn();
    }

    private function control(array $outcomes): InstallControl
    {
        return new class($outcomes) implements InstallControl
        {
            public int $stops = 0;

            public function __construct(private readonly array $outcomes) {}

            public function stop(callable $say): array
            {
                $this->stops++;

                return $this->outcomes;
            }

            public function start(callable $say): array
            {
                return [];
            }
        };
    }

    private function facts(?int $pid): InstallFacts
    {
        return new class($pid) implements InstallFacts
        {
            public function __construct(private readonly ?int $pid) {}

            public function supervisorPid(): ?int
            {
                return $this->pid;
            }

            public function suwayomiPids(): array
            {
                return [];
            }

            public function suwayomiVersion(): string
            {
                return '2.4.2366';
            }

            public function phpExecutable(): string
            {
                return PHP_BINARY;
            }

            public function iniPath(): string
            {
                return '';
            }
        };
    }

    /**
     * @param  list<string>  $argv
     * @return array{code: int, output: string}
     */
    private function invoke(array $argv, InstallControl $control, InstallFacts $facts, ?MigrationRecord $record = null): array
    {
        $lines = [];

        $command = new RollbackCommand(
            $control,
            $facts,
            $record ?? new MigrationRecord($this->data, $this->install),
            $this->database,
            new LocalFileMover,
        );

        $code = $command->run($argv, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        return ['code' => $code, 'output' => implode("\n", $lines)];
    }
}
