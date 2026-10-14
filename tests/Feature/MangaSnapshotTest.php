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
     * A pid of zero or below is refused, and the refusal names the fix.
     *
     * Not because "no pid" is unsafe. Because `null` now means "not running, safe to copy", so a
     * caller passing a number that is not a process has not done the lookup, and saying so is the
     * difference between a refusal and a reader guessing.
     */
    public function test_a_pid_that_is_not_a_process_is_refused_with_the_fix_in_the_message(): void
    {
        foreach ([0, -1] as $pid) {
            $refused = false;

            try {
                $this->snapshot()->capture($this->source(), $this->target(), $pid, 0);
            } catch (RestoreRefused $e) {
                $refused = true;

                $this->assertStringContainsString('No usable process id', $e->getMessage());
                $this->assertStringContainsString('Pass null', $e->getMessage());
            }

            $this->assertTrue($refused, "pid {$pid} must be refused");
            $this->assertFileDoesNotExist($this->target());
        }
    }

    /**
     * Suwayomi not running at all is the strongest proof available, and it copies.
     *
     * The first version refused a pid of zero with "nothing has proved the manga database is not being
     * written", which was backwards: nothing running is the proof. So the most convenient moment to
     * take this backup, Suwayomi already being stopped, was the one moment that was refused.
     *
     * Asserted that it copies, that it does not ask the operating system anything, and that it says
     * why it did not wait. A null that quietly waited thirty seconds would pass a file-exists
     * assertion and still be wrong.
     */
    public function test_suwayomi_not_running_copies_without_asking_or_waiting(): void
    {
        $source = $this->source();
        $target = $this->target();
        $lines = [];

        $started = microtime(true);

        $this->snapshot(aliveFor: 999)->capture(
            $source,
            $target,
            null,
            5,
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );

        $this->assertFileExists($target, 'nothing running means nothing is writing the file');
        $this->assertSame('H2 DATABASE BYTES', (string) file_get_contents($target));
        $this->assertLessThan(
            3.0,
            microtime(true) - $started,
            'it must not have waited, because there is no process to wait for',
        );

        $said = implode("\n", $lines);

        $this->assertStringContainsString('was not running', $said);
        $this->assertStringNotContainsString('has gone', $said, 'a process that was never there cannot have gone');
    }

    /**
     * The liveness check is not consulted at all when there is nothing to check.
     *
     * `aliveFor: 999` makes any call to it report a process that never dies. If null consulted it,
     * this would time out and refuse, which is the defect the null branch exists to remove.
     */
    public function test_a_null_pid_never_asks_whether_a_process_is_alive(): void
    {
        $target = $this->target();

        $this->assertTrue(
            $this->snapshot(aliveFor: 999)->tryCapture($this->source(), $target, null, 0),
            'null must not be answered by asking the operating system',
        );

        $this->assertFileExists($target);
    }

    /**
     * `tryCapture` reports a success for null too, not only for a refusal.
     */
    public function test_try_capture_reports_success_when_suwayomi_was_not_running(): void
    {
        $captured = $this->snapshot()->tryCapture($this->source(), $this->target(), null, 0);

        $this->assertTrue($captured);
        $this->assertFileExists($this->target());
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

    /**
     * It waits after the process has gone, rather than copying the instant it notices.
     *
     * ## Why this test exists because a mutation survived without it
     *
     * The first mutation pass here found that deleting the settle sleep changed nothing for any test,
     * because every other test passes a settle of zero, and the null path returns before reaching it
     * at all. An ineffective mutation and an uncovered one look identical from outside, and the
     * difference is worth having: ineffective means no test could ever have caught it, uncovered
     * means a test could and does not.
     *
     * This one could, so it now does. The wait is not politeness. Measured on this machine, the
     * launcher reported a process stopped and a port was still held a moment later, which is the
     * whole reason this class exists rather than a `copy()`. A wait nothing tests is a wait that
     * gets removed by the next person tidying up.
     *
     * A real second of real time, because the thing under test is elapsed time and a fake clock
     * would only be testing the fake.
     */
    public function test_it_waits_after_the_process_is_gone_before_copying(): void
    {
        $started = microtime(true);

        $this->snapshot()->capture($this->source(), $this->target(), 4242, 1);

        $this->assertFileExists($this->target());
        $this->assertGreaterThanOrEqual(
            0.9,
            microtime(true) - $started,
            'it copied without waiting, so the window between taskkill returning and the port being '
            .'released is exactly the one this class exists to cover',
        );
    }

    /**
     * The wait is not paid when there was nothing to wait for.
     *
     * The other half of the test above, so that a fix for it cannot simply be "always sleep".
     */
    public function test_a_zero_settle_does_not_wait_at_all(): void
    {
        $started = microtime(true);

        $this->snapshot()->capture($this->source(), $this->target(), 4242, 0);

        $this->assertLessThan(
            0.9,
            microtime(true) - $started,
            'a settle of zero has to mean zero, or the parameter does nothing',
        );
    }

    /**
     * A copy that reports success and produces nothing is refused.
     *
     * ## Why this is checked separately from the digest comparison
     *
     * Because the digest comparison cannot answer it. Two files that are both absent hash identically,
     * so a mover that copies nothing and then returns a constant for both paths passes the digest
     * check and the snapshot carries on to write a manifest naming a file that does not exist. That
     * restores into an empty library with nothing saying why, which is the one failure a pair exists
     * to prevent, reached by a shorter road.
     *
     * Found by a mutation rather than by reading. Removing the "are both halves present" check in
     * `SnapshotCommand::writeManifest()` survived every test, because every test had arranged for the
     * copy to either succeed with a file or fail before that point was reached. So the check moved
     * here, where the copy happens, and this test is the one that holds it.
     *
     * The mover is not pretending to be the filesystem: the verification genuinely runs and genuinely
     * cannot tell the difference. That is the point of the test rather than a flaw in it.
     */
    public function test_a_copy_that_produces_no_file_is_refused(): void
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
                // Copies nothing, and says nothing.
            }

            public function digest(string $path): string
            {
                return str_repeat('a', 64);
            }

            public function exists(string $path): bool
            {
                return false;
            }
        };

        $lines = [];
        $target = $this->target();

        $captured = $this->snapshot(mover: $silent)->tryCapture(
            $this->source(),
            $target,
            4242,
            0,
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );

        $this->assertFalse($captured, 'nothing arrived, so nothing was copied');
        $this->assertFileDoesNotExist($target);
        $this->assertStringContainsString('nothing arrived', implode("\n", $lines));
        $this->assertStringContainsString(
            'Nothing has been recorded as backed up',
            implode("\n", $lines),
            'and it does not leave the reader believing there is a backup',
        );
    }

    /**
     * The same shape through `capture()` rather than `tryCapture()`, so the exception a caller sees
     * names the cause rather than arriving as a bare failure.
     */
    public function test_capture_throws_when_the_copy_produces_no_file(): void
    {
        $silent = new class(new LocalFileMover) implements FileMover
        {
            public function __construct(private readonly FileMover $inner) {}

            public function move(string $from, string $to): void
            {
                $this->inner->move($from, $to);
            }

            public function copy(string $from, string $to): void {}

            public function digest(string $path): string
            {
                return str_repeat('a', 64);
            }

            public function exists(string $path): bool
            {
                return false;
            }
        };

        $refused = false;

        try {
            $this->snapshot(mover: $silent)->capture($this->source(), $this->target(), 4242, 0);
        } catch (RestoreRefused $e) {
            $refused = true;

            $this->assertStringContainsString('nothing arrived', $e->getMessage());
        }

        $this->assertTrue($refused);
    }
}
