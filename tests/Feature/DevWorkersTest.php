<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Which queue worker belongs to this checkout, decided by the script that starts them.
 *
 * ## The bug, and why it is worth a test rather than a fix
 *
 * A worker belonging to a different install is indistinguishable, from the outside, from one that
 * would have drained this checkout's queue. It runs `queue:work`, it is a `php.exe`, and it is
 * perfectly healthy. The queue it is draining is a different database.
 *
 * That has now happened twice.
 *
 * The first time it was the whole reason `packaging/dev-workers.ps1` exists at all: a packaged
 * install's worker was running while a bulk library check sat unreserved for seventy minutes, and
 * every check that asked "is a worker running" said yes.
 *
 * The second time was in the script written to fix the first. It excluded the two install paths that
 * existed when it was written, `build\install` and `packaging\launcher`, by name. Then Papyrus was
 * actually installed to `%LOCALAPPDATA%\Programs\Papyrus`, its worker appeared, neither exclusion
 * matched it, and the script reported `ok queue pid 6448` over a checkout that had no worker at all
 * while six jobs sat unreserved for seven minutes.
 *
 * Naming the paths you happen to know about is not a rule. It is a list that goes stale the moment
 * anything new exists, and it failed silently and confidently, which is the worst way to fail.
 *
 * ## The rule that does not go stale
 *
 * This checkout's own workers are started with a bare relative `artisan`, so they carry no directory
 * in front of it. Any worker whose command line has a path-qualified `artisan` is by construction
 * running some other install's code against some other install's database, whatever that path
 * happens to be. That holds today, for `build\install`, and for an install that does not exist yet.
 *
 * ## Why the rule is asked about a command line rather than about the machine
 *
 * Because a test that inspects this machine's running processes is a test whose answer depends on
 * what the machine is doing. `HealthTest` records having made exactly that mistake and having three
 * tests fail for reasons that had nothing to do with the code. So the script answers about a command
 * line it is given, and the assertions below are about fixed strings.
 */
final class DevWorkersTest extends TestCase
{
    private const SCRIPT = __DIR__.'/../../packaging/dev-workers.ps1';

    /**
     * Ask the real script about one command line.
     *
     * Runs the script rather than reproducing its filter, because a test of a copy of the rule is a
     * test of the copy. Every path here is a real one from this machine, taken from a process that
     * was actually running while this was written.
     *
     * The command line goes in on **stdin**, not as an argument. Passed as an argv value it is split
     * at the first space, so the rule was asked about `C:\...\php84\php.exe` alone, found no
     * `artisan`, and answered not-ours about a worker that is unambiguously ours. That version of
     * the test passed against a rule that was simply wrong, which is worse than no test.
     */
    private function verdictFor(string $commandLine): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('the command lines are Windows process command lines');
        }

        if (! is_file(self::SCRIPT)) {
            $this->fail('packaging/dev-workers.ps1 is missing, so there is no rule to test');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            'powershell -NoProfile -ExecutionPolicy Bypass -File '.escapeshellarg(self::SCRIPT).' -Explain',
            $descriptors,
            $pipes,
            dirname(__DIR__, 2),
        );

        $this->assertIsResource($process, 'PowerShell could not be started at all');

        fwrite($pipes[0], $commandLine."\n");
        fclose($pipes[0]);

        $output = (string) stream_get_contents($pipes[1]);
        $output .= (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        // The script echoes the line back, so a truncated or mangled round trip is visible here
        // rather than being silently answered for the wrong input.
        $this->assertStringContainsString(
            $commandLine,
            $output,
            'the command line did not survive the trip into the script, so its answer would be about '
                .'something other than what was asked',
        );

        return trim((string) strtok($output, "\r\n"));
    }

    private function assertOurs(string $commandLine, string $why): void
    {
        $this->assertStringStartsWith('checkout|', $this->verdictFor($commandLine), $why);
    }

    private function assertNotOurs(string $commandLine, string $why): void
    {
        $this->assertStringStartsWith('not-ours|', $this->verdictFor($commandLine), $why);
    }

    /**
     * The two shapes that are ours.
     *
     * Started with a bare relative `artisan` and a working directory, so the command line contains
     * no path at all. That absence is the only reliable thing about them, and it is what the rule
     * keys on.
     */
    /**
     * The interpreter must be a real `php.exe`, never a batch wrapper.
     *
     * This is the third defect in this one file, and the quietest. Started through Herd's `php.bat`,
     * the worker comes up, appears in the process list, and drains nothing: six jobs sat unreserved
     * for twenty-five minutes beside a healthy-looking `queue:work`, and the database had no WAL file
     * next to it, which is what showed nothing had it open at all. Swapping in the interpreter drained
     * all six in about a second and brought the WAL back.
     *
     * So the wrapper is not a slower way to run the same thing. It is a different thing that leaves a
     * worker running and idle, which is precisely the state this script exists to report.
     */
    public function test_the_worker_is_started_with_an_interpreter_and_not_a_batch_wrapper(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('this is about how Windows resolves php');
        }

        if (! is_file(self::SCRIPT)) {
            $this->fail('packaging/dev-workers.ps1 is missing, so there is nothing to ask');
        }

        $output = [];
        $status = 0;

        // No working directory is set: `exec()` takes three arguments, and the script finds the
        // repository from `$PSScriptRoot`, so where it is launched from does not matter.
        exec(
            'powershell -NoProfile -ExecutionPolicy Bypass -File '
            .escapeshellarg(self::SCRIPT).' -WhichPhp 2>&1',
            $output,
            $status,
        );

        $resolved = trim((string) ($output[0] ?? ''));

        $this->assertNotSame('', $resolved, 'the script reported no interpreter at all');

        $this->assertStringEndsWith(
            '.exe',
            $resolved,
            'a worker started through a .bat can run without draining anything, which is the exact '
                .'failure this script exists to report',
        );

        $this->assertFileExists($resolved, 'the interpreter it named is not actually there');
    }

    /**
     * A worker started with a bare `artisan` is this checkout's, and anything path-qualified is not.
     */
    public function test_a_worker_started_with_a_bare_artisan_is_this_checkout(): void
    {
        $this->assertOurs(
            '"C:\\Users\\<you>\\.config\\herd\\bin\\php84\\php.exe"  artisan queue:work --tries=3 --sleep=1',
            'this is the command line this machine actually produced for the checkout queue worker',
        );

        $this->assertOurs(
            '"C:\\Users\\<you>\\.config\\herd\\bin\\php84\\php.exe"  artisan schedule:work',
            'the scheduler is started the same way and belongs to the same checkout',
        );
    }

    /**
     * An installed worker is not ours, and this is the case that was missed.
     *
     * The verbatim command line of the installed Papyrus's worker while it was running. The first
     * version of this rule excluded `build\install` and `packaging\launcher` by name, so this matched
     * neither and the script reported the checkout as having a healthy worker.
     */
    public function test_an_installed_workers_worker_is_not_this_checkout(): void
    {
        $this->assertNotOurs(
            '"C:\\Users\\<you>\\AppData\\Local\\Papyrus\\php\\php.ini" '
            .'"C:\\Users\\<you>\\AppData\\Local\\Programs\\Papyrus\\artisan" "queue:work" "--tries=3" "--sleep=1"',
            'this is a packaged install draining %LOCALAPPDATA%, and reporting it as ours is the bug',
        );

        $this->assertNotOurs(
            '"C:\\Users\\<you>\\AppData\\Local\\Papyrus\\php\\php.ini" '
            .'"C:\\Users\\<you>\\AppData\\Local\\Programs\\Papyrus\\artisan" "schedule:work"',
            'the installed scheduler is equally not ours',
        );
    }

    /**
     * The build directory's worker, which the first version of the rule did exclude.
     *
     * Kept as its own case so that removing the general rule cannot quietly reintroduce the
     * specific exclusions it replaced, which is the failure mode a path list has.
     */
    public function test_the_build_installs_worker_is_not_this_checkout(): void
    {
        $this->assertNotOurs(
            '"C:\\Papyrus\\build\\install\\runtime\\bin\\php.exe" '
            .'"-c" "C:\\Users\\<you>\\Documents\\Papyrus\\data\\php\\php.ini" '
            .'"C:\\Papyrus\\build\\install\\artisan" "queue:work" "--tries=3" "--sleep=1"',
            'the build install drains the old Documents library and is not this checkout',
        );
    }

    /**
     * An install at a path nothing in this repository mentions.
     *
     * The reason the rule is a shape rather than a list. A copy of Papyrus somewhere else, a
     * colleague's machine, a second checkout: none of them are named anywhere, and every one of them
     * would have drained a queue that is not this checkout's.
     */
    public function test_an_install_at_a_path_nothing_knows_about_is_not_this_checkout(): void
    {
        $this->assertNotOurs(
            '"D:\\Apps\\Papyrus\\runtime\\bin\\php.exe" "D:\\Apps\\Papyrus\\artisan" "queue:work"',
            'a portable install is exactly the case a hard-coded list of known paths cannot cover',
        );

        $this->assertNotOurs(
            '"C:\\Program Files\\Papyrus\\runtime\\bin\\php.exe" "-c" "C:\\ProgramData\\Papyrus\\php.ini" '
            .'"C:\\Program Files\\Papyrus\\artisan" "queue:work"',
            'a per-machine install has the same shape and the same consequence',
        );
    }

    /**
     * Things that are not workers at all.
     *
     * The web server is a frankenphp, the launcher is the thing that starts the workers, and neither
     * should ever be reported as one.
     */
    public function test_a_non_worker_is_never_reported_as_a_worker(): void
    {
        $this->assertNotOurs(
            '"frankenphp.exe" "run" "--config" "C:\\Users\\<you>\\AppData\\Local\\Papyrus\\frankenphp\\Caddyfile"',
            'the web server is not a queue worker',
        );

        $this->assertNotOurs(
            '"php.exe" "papyrus.php" status',
            'the launcher asks about workers, it is not one',
        );

        $this->assertNotOurs('', 'a process with no command line is not a worker we can vouch for');

        $this->assertNotOurs(
            '"C:\\Users\\<you>\\.config\\herd\\bin\\php84\\php.exe" artisan migrate --force',
            'artisan on its own is not a worker',
        );
    }
}
