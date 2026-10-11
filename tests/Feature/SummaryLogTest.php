<?php

declare(strict_types=1);

namespace Tests\Feature;

use Papyrus\Launcher\SummaryLog;
use RuntimeException;
use Tests\TestCase;

/**
 * The log a reader is meant to read.
 *
 * ## Why this is tested rather than trusted
 *
 * Because it is the one file in the install whose entire job is to be believed. If it truncates,
 * a reader who reports a failure sends us the log from after it and we conclude the install was
 * fine. If it grows without bound, a reader who opens Papyrus occasionally fills their disk. If it
 * contains a stack trace, a screenshot of it goes into a public issue.
 *
 * Each of those is a property of the file rather than of any line in it, so each needs a test that
 * fails when the property is broken.
 */
class SummaryLogTest extends TestCase
{
    private string $scratch;

    private string $logs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-summary-'.getmypid().'-'.uniqid();
        $this->logs = $this->scratch.DIRECTORY_SEPARATOR.'logs';

        @mkdir($this->logs, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->scratch);

        parent::tearDown();
    }

    /**
     * Removed recursively.
     *
     * `glob('*')` does not descend, so the first version deleted the files and left the `logs`
     * directory behind, and `rmdir` on a directory that is not empty is an error rather than a
     * no-op. Every test in the file then reported as risky with a message about the temporary
     * directory, which reads like a production failure and is entirely a cleanup bug.
     */
    private function removeTree(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path.DIRECTORY_SEPARATOR.$entry);
            }
        }

        @rmdir($path);
    }

    private function log(): SummaryLog
    {
        return new SummaryLog($this->logs);
    }

    /** @return list<string> */
    private function lines(): array
    {
        $path = $this->logs.DIRECTORY_SEPARATOR.'papyrus.log';

        return is_file($path)
            ? array_values(array_filter(explode("\n", (string) file_get_contents($path))))
            : [];
    }

    public function test_it_writes_one_line_per_state_change(): void
    {
        $log = $this->log();

        $log->record('papyrus', 'starting');
        $log->record('suwayomi', 'ready on port 4567');
        $log->record('web', 'serving');

        $this->assertCount(3, $this->lines());
    }

    /**
     * The property the whole file rests on. A summary log that is rewritten rather than appended
     * is useless precisely when it is needed, because a crash is exactly when nothing gets the
     * chance to tidy up.
     */
    public function test_it_appends_rather_than_truncating(): void
    {
        $this->log()->record('papyrus', 'run one: ready');

        // A second run, as a reader would experience after restarting.
        $this->log()->record('papyrus', 'run two: ready');

        $lines = $this->lines();

        $this->assertCount(2, $lines, 'the first run was lost when the second started');
        $this->assertStringContainsString('run one', $lines[0]);
        $this->assertStringContainsString('run two', $lines[1]);
    }

    public function test_each_line_names_its_process(): void
    {
        $log = $this->log();

        $log->record('suwayomi', 'ready');
        $log->record('queue', 'started');

        $lines = $this->lines();

        $this->assertStringContainsString('suwayomi', $lines[0]);
        $this->assertStringContainsString('queue', $lines[1]);
    }

    public function test_it_never_writes_more_than_one_line_per_record(): void
    {
        $this->log()->record('papyrus', "first line\nsecond line\r\nthird line");

        $lines = $this->lines();

        $this->assertCount(1, $lines, 'a multi line message breaks the one fact per line rule');
        $this->assertStringContainsString('first line second line third line', $lines[0]);
    }

    public function test_problems_are_marked_as_such(): void
    {
        $this->log()->problem('papyrus', 'did not start');

        $this->assertStringContainsString('PROBLEM', $this->lines()[0]);
    }

    public function test_pointing_at_detail_keeps_the_detail_out_of_the_summary(): void
    {
        $this->log()->pointAt('web', 'web.log', 'the web server reported an error');

        $line = $this->lines()[0];

        $this->assertStringContainsString('web.log', $line);
        $this->assertStringNotContainsString('Stack trace', $line);
    }

    /**
     * The rotation boundary, tested by writing a real file rather than by lowering the threshold,
     * because a threshold constant made configurable for its own test is a threshold nobody can
     * find in production.
     */
    /**
     * The rotation boundary, and the number of generations kept.
     *
     * The `.1` file is seeded before the rotation rather than after, which is the part that
     * matters. Asserting only that `.2` does not exist passes whether one generation is kept or
     * two, because with nothing in `.1` there is nothing to shift forward and no `.2` is ever
     * created. A mutation raising `KEEP_GENERATIONS` to two survived until this was seeded.
     *
     * The threshold is exercised by writing a real oversized file rather than by making the
     * constant configurable for its own test, which would be a constant nobody can find in
     * production.
     */
    /**
     * The rotation boundary, and the number of generations kept.
     *
     * Two things here were wrong at first and both are worth recording.
     *
     * Asserting only that `.2` does not exist passes whether one generation is kept or two,
     * because with nothing in `.1` there is nothing to shift forward, so no `.2` is ever created.
     * A mutation raising `KEEP_GENERATIONS` survived until a `.1` was seeded alongside.
     *
     * And every assertion compares a length or a digest rather than contents. Comparing two 1MB
     * strings makes PHPUnit print the whole difference on failure, which buried the entire test
     * output in a megabyte of the filler character.
     *
     * The threshold is exercised by writing a real oversized file rather than by making the
     * constant configurable for its own test, which would be a constant nobody can find in
     * production.
     */
    public function test_it_rotates_once_the_file_is_too_big_and_keeps_the_previous_run(): void
    {
        $path = $this->logs.DIRECTORY_SEPARATOR.'papyrus.log';

        $oversized = str_repeat('x', 1048600);

        file_put_contents($path, $oversized);

        $this->log()->record('papyrus', 'this line is written after the rotation');

        $this->assertFileExists($path.'.1', 'the previous run was not kept aside');

        $rotated = (string) file_get_contents($path.'.1');

        $this->assertSame(1048600, strlen($rotated), 'the previous run was not preserved whole');
        $this->assertSame(hash('sha256', $oversized), hash('sha256', $rotated));
        $this->assertStringContainsString('this line', (string) file_get_contents($path));
    }

    /**
     * Generations beyond the ones kept must actually go.
     *
     * The production code did not do this at first: rotation shifted and overwrote but never
     * removed a stale `.2`, so one left over from a build that once kept more generations would
     * survive forever and be swept into every diagnostics bundle this reader ever sends.
     *
     * The seeded `.1` is what makes this a real test. Rotation *overwrites* `.1` with the
     * current log, so with only the current file and a `.2` present nothing ever moves and no
     * `.2` is created, and the assertion passes whether one generation is kept or two. Seeding a
     * `.1` alongside means a build that kept two would shift it to `.2`, where it survives and is
     * caught here.
     */
    public function test_rotation_discards_generations_beyond_the_ones_kept(): void
    {
        $path = $this->logs.DIRECTORY_SEPARATOR.'papyrus.log';

        $oversized = str_repeat('x', 1048600);

        file_put_contents($path, $oversized);
        file_put_contents($path.'.1', str_repeat('y', 100));
        file_put_contents($path.'.2', 'stale, and two is more than is kept');
        file_put_contents($path.'.3', 'stale, and so is three');

        $this->log()->record('papyrus', 'after rotation');

        $this->assertFileDoesNotExist($path.'.2');
        $this->assertFileDoesNotExist($path.'.3');

        // `.1` holds the file that was current, because rotation replaces it rather than
        // preserving what was there. Asserting its size is asserting that, and a digest avoids
        // PHPUnit printing a megabyte of filler if it ever fails.
        $this->assertFileExists($path.'.1');
        $this->assertSame(hash('sha256', $oversized), hash_file('sha256', $path.'.1'));
    }

    public function test_it_does_not_rotate_a_file_that_is_still_small(): void
    {
        $log = $this->log();

        $log->record('papyrus', 'one');
        $log->record('papyrus', 'two');

        $this->assertFileDoesNotExist($this->logs.DIRECTORY_SEPARATOR.'papyrus.log.1');
        $this->assertCount(2, $this->lines());
    }

    public function test_it_finds_its_own_files_for_the_bundle(): void
    {
        $log = $this->log();

        $log->record('papyrus', 'one');

        $found = $log->files();

        $this->assertContains($log->path(), $found);
    }

    public function test_diagnose_says_the_directory_is_missing_rather_than_just_failing(): void
    {
        $log = new SummaryLog($this->scratch.DIRECTORY_SEPARATOR.'nowhere');

        $status = $log->diagnose();

        $this->assertNotSame('ok', $status);
        $this->assertStringContainsString('does not exist', $status);
    }

    public function test_diagnose_is_ok_for_a_writable_directory(): void
    {
        $this->assertSame('ok', $this->log()->diagnose());
    }

    public function test_assert_usable_throws_when_the_log_cannot_be_written(): void
    {
        $this->expectException(RuntimeException::class);

        (new SummaryLog($this->scratch.DIRECTORY_SEPARATOR.'nowhere'))->assertUsable();
    }

    /**
     * A log that cannot be written must not stop an install from starting.
     *
     * A launcher that dies because a log directory is locked is a far worse outcome than a
     * missing line, so the write fails silently by design and the absence is reported by health
     * instead.
     */
    /**
     * A log that cannot be written must not stop an install from starting.
     *
     * A launcher that dies because a log directory is locked is a far worse outcome than a missing
     * line, so the write fails silently by design and the absence is reported by health instead.
     *
     * The path here has a parent that does not exist either, which matters: an earlier version
     * used `<scratch>/nowhere`, whose parent *did* exist, so `mkdir` succeeded, the unwritable
     * branch was never reached, and the test passed for the wrong reason. A mutation that made
    /**
     * A log that cannot be written must not stop an install from starting.
     *
     * A launcher that dies because a log directory is locked is a far worse outcome than a missing
     * line, so the write fails silently by design and the absence is reported by health instead.
     *
     * ## Why a file is used as the blocker
     *
     * Two earlier attempts at an unwritable path both turned out to be writable, and both left a
     * test that passed without exercising anything:
     *
     *   - `<scratch>/nowhere`, whose *parent* existed, so `mkdir` succeeded and the unwritable
     *     branch was never reached.
     *   - `C:\no-such-drive-for-tests\...`, which looks unwritable and is not. Windows treats
     *     `no-such-drive-for-tests` as an ordinary directory name under `C:\` and creates the
     *     whole chain, so the test passed and left a stray directory at the drive root.
     *
     * A file where the directory should go cannot be written through, on any platform, for a
     * reason about the filesystem rather than about permissions or drive letters. That is also why
     * it is asserted rather than assumed: the first mutation check run reported this as surviving,
     * and the reason turned out to be the test rather than the code.
     */
    public function test_writing_to_an_unwritable_location_does_not_throw(): void
    {
        $blocker = $this->scratch.DIRECTORY_SEPARATOR.'a-file-not-a-directory';

        file_put_contents($blocker, 'anything in here makes the path below impossible to create');

        $log = new SummaryLog($blocker.DIRECTORY_SEPARATOR.'logs');

        $log->record('papyrus', 'this cannot be written and must not throw');
        $log->problem('papyrus', 'nor this');

        $this->assertTrue(true, 'no exception was thrown');
    }

    public function test_diagnosing_an_unwritable_location_says_so(): void
    {
        $blocker = $this->scratch.DIRECTORY_SEPARATOR.'another-file';

        file_put_contents($blocker, 'blocker');

        $status = (new SummaryLog($blocker.DIRECTORY_SEPARATOR.'logs'))->diagnose();

        $this->assertNotSame('ok', $status);
        $this->assertStringContainsString('does not exist', $status);
    }

    public function test_it_writes_a_timestamp_a_reader_can_match_to_a_screenshot(): void
    {
        $this->log()->record('papyrus', 'starting');

        $this->assertMatchesRegularExpression(
            '#^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}#',
            $this->lines()[0],
        );
    }
}
