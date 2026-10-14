<?php

namespace Tests\Feature;

use Papyrus\Launcher\FileMover;
use Papyrus\Launcher\LocalFileMover;
use Papyrus\Launcher\MangaSnapshot;
use Papyrus\Launcher\RestoreRefused;
use Tests\TestCase;

/**
 * Copying the manga database, having proved nothing is writing it.
 *
 * ## What this is protecting against, measured
 *
 * Four stop and start cycles against the reader's own install produced, in three of them:
 *
 *     suwayomi: killed        (pid 29680, found rather than started here)
 *     suwayomi: could not be stopped (pid 27384, found rather than started here)
 *     web: could not be stopped      (pid 30060, found rather than started here)
 *
 * So the launcher exits zero having not necessarily stopped anything, and a backup that trusted that
 * would copy a file a JVM was still writing.
 *
 * ## Why no test here checks a file lock
 *
 * Because it cannot, and a check that always passes is worse than no check. Measured while Suwayomi
 * was running and writing:
 *
 *     File.Open(path, Open, Read, ReadWrite)   -> succeeded
 *     File.Open(path, Open, ReadWrite, None)   -> succeeded
 *
 * An exclusive handle with no sharing is obtainable from a live database. A lock probe would report
 * "safe" on a moving file. So the question is process liveness, which is a fact about the world, and
 * these tests fake it in the only way that is honest: by answering, not by pretending to be Windows.
 */
class MangaSnapshotTest extends TestCase
{
    private string $workspace = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-manga-'.bin2hex(random_bytes(6));

        mkdir($this->workspace, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->workspace.'/*') as $file) {
            if (is_string($file) && is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($this->workspace);

        parent::tearDown();
    }

    private function source(string $body = 'H2 DATABASE BYTES'): string
    {
        $path = $this->workspace.DIRECTORY_SEPARATOR.'database.mv.db';

        file_put_contents($path, $body);

        return $path;
    }

    private function target(): string
    {
        return $this->workspace.DIRECTORY_SEPARATOR.'suwayomi-snapshot.mv.db';
    }

    /**
     * A liveness answer that becomes false after `$aliveFor` checks.
     *
     * Time-based rather than count-based would make these tests slow and would still not be more
     * honest. The property under test is "does it keep asking", and a counter answers that.
     */
    private function snapshot(int $aliveFor = 0, ?FileMover $mover = null): MangaSnapshot
    {
        $checks = 0;

        return new MangaSnapshot($mover ?? new LocalFileMover, function (int $pid) use (&$checks, $aliveFor): bool {
            $checks++;

            return $checks <= $aliveFor;
        });
    }

    // ---------------------------------------------------------------------------------------------

    public function test_it_copies_the_file_when_the_process_is_already_gone(): void
    {
        $source = $this->source();
        $target = $this->target();

        $this->snapshot()->capture($source, $target, 4242, 0);

        $this->assertFileExists($target);
        $this->assertSame('H2 DATABASE BYTES', (string) file_get_contents($target));
    }

    /**
     * The refusal that matters: the process is still there, so nothing is copied.
     *
     * Asserted on both the refusal and the absence of a file, because a class that throws after it
     * has already copied has not refused anything.
     */
    public function test_it_refuses_and_copies_nothing_while_the_process_is_alive(): void
    {
        $source = $this->source();
        $target = $this->target();

        // Alive for longer than the thirty second deadline allows in checks, so the loop runs out.
        $refused = false;

        try {
            $this->snapshot(aliveFor: 999)->capture($source, $target, 4242, 0);
        } catch (RestoreRefused $e) {
            $refused = true;

            $this->assertStringContainsString('still running', $e->getMessage());
        }

        $this->assertTrue($refused, 'a live process must refuse the copy');
        $this->assertFileDoesNotExist(
            $target,
            'the refusal has to arrive before the copy, or it is not a refusal',
        );
    }

    /**
     * It keeps asking rather than deciding on the first answer.
     *
     * Because `taskkill` returning does not mean the process has exited, and the launcher reporting a
     * process stopped was followed by a port still being held.
     */
    public function test_it_keeps_asking_until_the_process_is_really_gone(): void
    {
        $source = $this->source();
        $target = $this->target();

        // Alive for the first three checks.
        $this->snapshot(aliveFor: 3)->capture($source, $target, 4242, 0);

        $this->assertFileExists($target, 'it gave up on a process that did eventually go');
    }

    /**
     * No pid is not a yes.
     *
     * A pid of zero, or none, means nothing was actually identified. Treating that as permission
     * would make "I could not find the process" indistinguishable from "the process is gone", which
     * is the failure AGENTS.md calls a confident wrong answer.
     */
    public function test_no_process_id_is_refused_rather_than_treated_as_gone(): void
    {
        foreach ([0, -1] as $pid) {
            $refused = false;

            try {
                $this->snapshot()->capture($this->source(), $this->target(), $pid, 0);
            } catch (RestoreRefused $e) {
                $refused = true;

                $this->assertStringContainsString('No process id', $e->getMessage());
            }

            $this->assertTrue($refused, "pid {$pid} must be refused");
            $this->assertFileDoesNotExist($this->target());
        }
    }

    /**
     * A missing database is refused with the path, not a bare failure.
     */
    public function test_a_missing_database_is_refused_by_path(): void
    {
        $missing = $this->workspace.DIRECTORY_SEPARATOR.'not-here.mv.db';

        $refused = false;

        try {
            $this->snapshot()->capture($missing, $this->target(), 4242, 0);
        } catch (RestoreRefused $e) {
            $refused = true;

            $this->assertStringContainsString('not-here.mv.db', $e->getMessage());
        }

        $this->assertTrue($refused);
    }

    /**
     * A copy that arrives wrong is deleted rather than left where a backup is expected.
     *
     * The same rule as the reader half: a partial file found during a restore is worse than no file,
     * because it is found at the moment it is needed.
     */
    public function test_a_corrupt_copy_is_deleted_rather_than_left_in_place(): void
    {
        $source = $this->source('THE REAL DATABASE');
        $target = $this->target();

        $corrupting = new class(new LocalFileMover) implements FileMover
        {
            public function __construct(private readonly FileMover $inner) {}

            public function move(string $from, string $to): void
            {
                $this->inner->move($from, $to);
            }

            public function copy(string $from, string $to): void
            {
                $this->inner->copy($from, $to);

                // Loses the tail, which is what a full disk or a filter does.
                file_put_contents($to, substr((string) file_get_contents($to), 0, 3));
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

        $refused = false;

        try {
            $this->snapshot(mover: $corrupting)->capture($source, $target, 4242, 0);
        } catch (RestoreRefused $e) {
            $refused = true;
        }

        $this->assertTrue($refused, 'a copy that did not arrive intact must be refused');
        $this->assertFileDoesNotExist(
            $target,
            'a short file left where a backup is expected is worse than no file, because it is found '
            .'at the moment it is needed',
        );
    }

    /**
     * `tryCapture` reports a refusal rather than throwing, and says what went wrong.
     *
     * Because the caller has to start Suwayomi again either way, and a caller that cannot tell a
     * refusal from a crash will not know whether to report success.
     */
    public function test_try_capture_reports_a_refusal_instead_of_throwing(): void
    {
        $lines = [];

        $captured = $this->snapshot(aliveFor: 999)->tryCapture(
            $this->source(),
            $this->target(),
            4242,
            0,
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );

        $this->assertFalse($captured);

        $said = implode("\n", $lines);

        $this->assertStringContainsString('still running', $said);
        $this->assertFileDoesNotExist($this->target());
    }

    /**
     * The successful path reports what it did, in words a reader reads.
     */
    public function test_it_says_what_it_copied(): void
    {
        $lines = [];

        $this->snapshot()->tryCapture(
            $this->source(),
            $this->target(),
            4242,
            0,
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );

        $said = implode("\n", $lines);

        $this->assertStringContainsString('has gone', $said);
        $this->assertStringContainsString('copying database.mv.db', $said);
        $this->assertStringContainsString('byte for byte', $said);
    }
}
