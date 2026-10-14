<?php

namespace Tests\Feature;

use Papyrus\Launcher\FileMover;
use Papyrus\Launcher\LocalFileMover;
use Papyrus\Launcher\ReaderSnapshot;
use Papyrus\Launcher\RestoreRefused;
use Tests\TestCase;

/**
 * Taking the reader's half, and what it costs to take it honestly.
 *
 * ## Why this class spawns a child process at all
 *
 * Because the launcher's own interpreter has no `pdo_sqlite`. It loads no ini, so `VACUUM INTO` is
 * not available to it, and the work goes to the install's own `php.exe`. That is measured rather than
 * assumed, and it is why {@see Supervisor::phpExecutable()} is public.
 *
 * ## Why these tests run the real child
 *
 * Because the interesting failures are all in the interaction between the command line and the child,
 * and none of them can be seen from inside the class:
 *
 *   - a target path containing a space, which the install root genuinely has
 *   - `VACUUM INTO` refusing to overwrite a file that already exists
 *   - a source database that is not there, which SQLite turns into an empty one rather than an error
 *
 * The last of those is the trap worth the whole file, and it is in the class docstring. The tests that
 * matter here therefore build a real SQLite database, run the real child, and assert on the bytes.
 */
class ReaderSnapshotTest extends TestCase
{
    private string $workspace = '';

    /** @var list<array<string, string>> */
    private array $runs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-reader-'.bin2hex(random_bytes(6));

        mkdir($this->workspace, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->workspace.'/*') as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            is_dir($entry) ? @array_map('unlink', (array) glob($entry.'/*')) && @rmdir($entry) : @unlink($entry);
        }

        @rmdir($this->workspace);

        parent::tearDown();
    }

    private function target(string $name = 'snap.sqlite'): string
    {
        return $this->workspace.DIRECTORY_SEPARATOR.$name;
    }

    /** A real SQLite database with the two tables the manifest counts. */
    private function database(int $series = 867, int $chapters = 21467, string $name = 'database.sqlite'): string
    {
        $path = $this->workspace.DIRECTORY_SEPARATOR.$name;

        $pdo = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $pdo->exec('CREATE TABLE series_states (id INTEGER PRIMARY KEY, suwayomi_manga_id INTEGER)');
        $pdo->exec('CREATE TABLE chapter_states (id INTEGER PRIMARY KEY, series_state_id INTEGER)');

        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO series_states (suwayomi_manga_id) VALUES (1)');
        $pdo->commit();

        for ($i = 1; $i < $series; $i++) {
            $pdo->exec('INSERT INTO series_states (suwayomi_manga_id) VALUES ('.$i.')');
        }

        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO chapter_states (series_state_id) VALUES (1)');
        $pdo->commit();

        for ($i = 1; $i < $chapters; $i++) {
            $pdo->exec('INSERT INTO chapter_states (series_state_id) VALUES ('.$i.')');
        }

        unset($pdo);

        return $path;
    }

    /**
     * A runner that records what it was asked to do and answers with whatever the test wants.
     *
     * @param  array{status?: int, output?: string, writes?: string}  $answer
     */
    private function stub(array $answer = []): ReaderSnapshot
    {
        return new ReaderSnapshot(
            new LocalFileMover,
            function (string $php, string $ini, string $script, string $database, string $target) use ($answer): array {
                $this->runs[] = [
                    'php' => $php, 'ini' => $ini, 'script' => $script,
                    'database' => $database, 'target' => $target,
                    // Read while it exists: the script is deleted the moment the child returns, and
                    // should be. A test that reads it afterwards is asserting on a missing file.
                    'source' => (string) @file_get_contents($script),
                ];

                if (isset($answer['writes'])) {
                    file_put_contents($target, $answer['writes']);
                }

                return [
                    'status' => $answer['status'] ?? 0,
                    'output' => $answer['output'] ?? '{"counts":{"series_states":1,"chapter_states":2}}',
                ];
            },
        );
    }

    /** The interpreter this suite is running on, which does have pdo_sqlite. */
    private function php(): string
    {
        return PHP_BINARY;
    }

    private function ini(): string
    {
        return (string) (php_ini_loaded_file() ?: '');
    }

    // -------------------------------------------------------------------------------------------
    // Refusals, which are the whole point.
    // -------------------------------------------------------------------------------------------

    /**
     * A missing source is refused with its path, and no child process is ever started.
     *
     * The measurement behind this: with a `sqlite:` DSN, SQLite **creates the database if it is not
     * there**. So a missing source does not fail to open. It opens as an empty database, and the first
     * thing to fail is a query against a table that was never created:
     *
     *     exit 1   SQLSTATE[HY000]: General error: 1 no such table: series_states
     *
     * That message names a table, not a missing file, and a reader handed it would go looking for
     * `series_states` in an install that does not have one. The check has to happen before the child
     * exists, which is what the empty `$this->runs` asserts.
     */
    public function test_a_missing_database_is_refused_before_any_child_runs(): void
    {
        $missing = $this->workspace.DIRECTORY_SEPARATOR.'not-here.sqlite';

        $refused = false;

        try {
            $this->stub()->capture($missing, $this->target(), $this->php(), $this->ini());
        } catch (RestoreRefused $e) {
            $refused = true;

            $this->assertStringContainsString('not-here.sqlite', $e->getMessage());
            $this->assertStringContainsString('Nothing has been written', $e->getMessage());
        }

        $this->assertTrue($refused, 'a missing database must be refused');
        $this->assertSame([], $this->runs, 'no child process may run, because it would make the file we are refusing about');
        $this->assertFileDoesNotExist($missing, 'and SQLite must not have been asked to open it');
    }

    /**
     * An existing target is refused, and left exactly as it was.
     */
    public function test_an_existing_target_is_refused_and_untouched(): void
    {
        $target = $this->target();
        file_put_contents($target, 'A PREVIOUS BACKUP');

        $refused = false;

        try {
            $this->stub()->capture($this->database(), $target, $this->php(), $this->ini());
        } catch (RestoreRefused $e) {
            $refused = true;

            $this->assertStringContainsString('will not replace it', $e->getMessage());
        }

        $this->assertTrue($refused);
        $this->assertSame([], $this->runs);
        $this->assertSame('A PREVIOUS BACKUP', (string) file_get_contents($target));
    }

    /**
     * A child that fails is reported with the child's own words, and its half-written file goes.
     *
     * A partial file found during a restore is worse than no file, because it is found at the moment
     * it is needed.
     */
    public function test_a_failing_child_is_reported_and_its_output_deleted(): void
    {
        $target = $this->target();

        $failing = $this->stub([
            'status' => 1,
            'output' => 'SQLSTATE[HY000]: General error: 1 no such table: series_states',
            'writes' => 'HALF A DATABASE',
        ]);

        $refused = false;

        try {
            $failing->capture($this->database(), $target, $this->php(), $this->ini());
        } catch (RestoreRefused $e) {
            $refused = true;

            $this->assertStringContainsString('no such table: series_states', $e->getMessage());
            $this->assertStringContainsString('nothing has been recorded as backed up', $e->getMessage());
        }

        $this->assertTrue($refused);
        $this->assertFileDoesNotExist($target);
    }

    /**
     * A child that exits zero having produced no file is refused.
     *
     * The specific shape: a backup command that reports success and produces nothing is the worst
     * outcome available, and it is reachable by any future change to the child.
     */
    public function test_a_child_that_produces_no_file_is_refused(): void
    {
        $refused = false;

        try {
            $this->stub(['status' => 0])->capture($this->database(), $this->target(), $this->php(), $this->ini());
        } catch (RestoreRefused $e) {
            $refused = true;

            $this->assertStringContainsString('nothing arrived at', $e->getMessage());
        }

        $this->assertTrue($refused);
    }

    /**
     * Counts that come back in a shape we do not understand are refused, not read as zero.
     *
     * Two zeroes in a manifest is a believable number for an empty library, so this is the difference
     * between a backup that says nothing and a backup that lies convincingly.
     */
    public function test_counts_in_an_unknown_shape_are_refused_rather_than_read_as_zero(): void
    {
        /*
         * Built once, outside the loop. The first version called `database()` inside it, and the
         * second iteration tried to create tables that were already there.
         */
        $database = $this->database(1, 1);

        foreach ([
            'not json at all',
            '{}',
            '{"counts":{}}',
            '{"counts":{"series_states":"867","chapter_states":21447}}',
            '{"counts":[1,2]}',
        ] as $output) {
            $target = $this->target('out-'.md5($output).'.sqlite');

            $refused = false;

            try {
                $this->stub(['status' => 0, 'output' => $output, 'writes' => 'DATA'])
                    ->capture($database, $target, $this->php(), $this->ini());
            } catch (RestoreRefused) {
                $refused = true;
            }

            $this->assertTrue($refused, 'counts of '.var_export($output, true).' must be refused');

            @unlink($target);
        }
    }

    /**
     * The temporary script does not survive, including when the child failed.
     */
    public function test_the_temporary_script_does_not_survive_a_failure(): void
    {
        $script = '';

        $failing = new ReaderSnapshot(
            new LocalFileMover,
            function (string $php, string $ini, string $path, string $database, string $target) use (&$script): array {
                $script = $path;

                return ['status' => 1, 'output' => 'no'];
            },
        );

        try {
            $failing->capture($this->database(), $this->target(), $this->php(), $this->ini());
        } catch (RestoreRefused) {
            // Expected.
        }

        $this->assertNotSame('', $script, 'the script should have been written before the child was asked to run');
        $this->assertFileDoesNotExist($script, 'a PHP file left in the temporary directory is one more thing to go wrong');
    }

    // -------------------------------------------------------------------------------------------
    // The real child, against a real database.
    // -------------------------------------------------------------------------------------------

    /**
     * The whole thing, end to end: a real database, the real child, real bytes.
     *
     * Asserted on the copy's own contents rather than on the return value, because "it worked" is
     * exactly the thing that has been wrong. The copy is opened and recounted, so a manifest built
     * from it is describing the file that is actually on disk.
     */
    public function test_it_copies_a_real_database_and_counts_what_it_held(): void
    {
        $database = $this->database(867, 21467);
        $target = $this->target();

        $result = (new ReaderSnapshot)->capture($database, $target, $this->php(), $this->ini());

        $this->assertFileExists($target);
        $this->assertSame(867, $result->seriesStates);
        $this->assertSame(21467, $result->chapterStates);
        $this->assertSame(hash_file('sha256', $target), $result->sha256);
        $this->assertSame((int) filesize($target), $result->bytes);

        $copy = new \PDO('sqlite:'.$target, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $this->assertSame(
            867,
            (int) $copy->query('SELECT COUNT(*) FROM series_states')->fetchColumn(),
            'the copy has to hold what the original held, not merely exist',
        );
        $this->assertSame(21467, (int) $copy->query('SELECT COUNT(*) FROM chapter_states')->fetchColumn());

        unset($copy);

        $this->assertSame(
            '867 series and 21,467 chapter states, '.number_format($result->bytes).' bytes',
            $result->describe(),
        );
    }

    /**
     * A path containing a space survives the round trip.
     *
     * Not a hypothesis: the install root has one in it, on this machine and on a default install. The
     * first version of `ProcessFinder` measured every way of passing a path through a Windows command
     * line and found that all of them break on a space.
     */
    public function test_a_path_containing_a_space_survives(): void
    {
        $spaced = $this->workspace.DIRECTORY_SEPARATOR.'a folder with a space';

        mkdir($spaced, 0o777, true);

        $database = $this->database(3, 5, 'db with spaces.sqlite');
        $target = $spaced.DIRECTORY_SEPARATOR.'probe two.sqlite';

        $result = (new ReaderSnapshot)->capture($database, $target, $this->php(), $this->ini());

        $this->assertFileExists($target);
        $this->assertSame(3, $result->seriesStates);
        $this->assertSame(5, $result->chapterStates);
    }

    /**
     * The copy carries no write-ahead log beside it.
     *
     * Measured rather than assumed: `VACUUM INTO` produces a single file, so a snapshot directory
     * holding sidecars means something else wrote there, and a `-wal` beside a snapshot is a file
     * that could be restored instead of the snapshot.
     */
    public function test_the_copy_has_no_sidecar_files_beside_it(): void
    {
        $target = $this->target();

        (new ReaderSnapshot)->capture($this->database(2, 2), $target, $this->php(), $this->ini());

        $this->assertSame(
            [$target],
            (array) glob($target.'*'),
            'a write-ahead log beside a snapshot is a file that could be restored instead of it',
        );
    }

    /**
     * Taking the same database twice produces the same bytes both times.
     *
     * A snapshot that varies run to run is a snapshot nobody can check, and the digest in the manifest
     * is what a restore verifies against.
     */
    public function test_two_snapshots_of_one_database_are_identical(): void
    {
        $database = $this->database(10, 10);

        $first = (new ReaderSnapshot)->capture($database, $this->target('one.sqlite'), $this->php(), $this->ini());
        $second = (new ReaderSnapshot)->capture($database, $this->target('two.sqlite'), $this->php(), $this->ini());

        $this->assertSame($first->sha256, $second->sha256);
        $this->assertSame(
            file_get_contents($this->target('one.sqlite')),
            file_get_contents($this->target('two.sqlite')),
        );
    }

    /**
     * A database that is busy in the middle of a write is still copied consistently.
     *
     * The reason this class exists rather than a `copy()`. A `VACUUM INTO` reads a consistent image
     * even while another connection has uncommitted work, and a file copy would take whatever bytes
     * happened to be there.
     */
    public function test_a_copy_is_consistent_even_with_another_connection_open(): void
    {
        $database = $this->database(50, 50);

        $writer = new \PDO('sqlite:'.$database, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $writer->beginTransaction();
        $writer->exec('INSERT INTO series_states (suwayomi_manga_id) VALUES (9999)');

        $result = (new ReaderSnapshot)->capture($database, $this->target(), $this->php(), $this->ini());

        $writer->rollBack();
        unset($writer);

        $this->assertSame(50, $result->seriesStates, 'the uncommitted insert is not part of the backup');

        $copy = new \PDO('sqlite:'.$this->target(), null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $this->assertSame(50, (int) $copy->query('SELECT COUNT(*) FROM series_states')->fetchColumn());
        $this->assertSame('ok', (string) $copy->query('PRAGMA integrity_check')->fetchColumn());

        unset($copy);
    }

    /**
     * The child is given the source and the target, and not something else.
     *
     * The first version of `run()` had five placeholders in its format string and four values, which
     * PHPStan caught and which meant the child was handed the target where it expected a database and
     * nothing at all where it expected a target. The format string had been copied from `Health`,
     * which passes one argument, and the shape of the command was not the same.
     *
     * Asserted through the stub rather than through the real command line, because the stub is where
     * the argument order is visible.
     */
    public function test_the_child_is_handed_the_source_and_the_target(): void
    {
        $database = $this->database(1, 1);
        $target = $this->target();

        $this->stub(['writes' => 'X'])->capture($database, $target, 'PHP-HERE', 'INI-HERE');

        $this->assertCount(1, $this->runs);
        $this->assertSame('PHP-HERE', $this->runs[0]['php']);
        $this->assertSame('INI-HERE', $this->runs[0]['ini']);
        $this->assertSame($database, $this->runs[0]['database']);
        $this->assertSame($target, $this->runs[0]['target']);
        $this->assertStringContainsString('<?php', $this->runs[0]['source']);
        $this->assertStringContainsString('VACUUM INTO', $this->runs[0]['source']);
        $this->assertFileDoesNotExist($this->runs[0]['script'], 'and it is gone afterwards');
    }

    /**
     * The mover is asked to digest the copy, so a corrupt file is caught by the same code path every
     * other half uses.
     *
     * The digest is taken through the interface rather than with `hash_file` directly, so a test can
     * substitute a mover that returns something wrong and prove the value in the manifest would be
     * wrong too, rather than being quietly right by accident.
     */
    public function test_the_digest_comes_from_the_mover(): void
    {
        $wrong = new class(new LocalFileMover) implements FileMover
        {
            public function __construct(private readonly FileMover $inner) {}

            public function move(string $from, string $to): void
            {
                $this->inner->move($from, $to);
            }

            public function copy(string $from, string $to): void
            {
                $this->inner->copy($from, $to);
            }

            public function digest(string $path): string
            {
                return str_repeat('0', 64);
            }

            public function exists(string $path): bool
            {
                return $this->inner->exists($path);
            }
        };

        $snapshot = new ReaderSnapshot($wrong, static function (string $php, string $ini, string $script, string $database, string $target): array {
            file_put_contents($target, 'DATA');

            return ['status' => 0, 'output' => '{"counts":{"series_states":1,"chapter_states":2}}'];
        });

        $result = $snapshot->capture($this->database(1, 1), $this->target(), $this->php(), $this->ini());

        $this->assertSame(str_repeat('0', 64), $result->sha256);
    }
}
