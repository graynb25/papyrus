<?php

namespace Tests\Feature;

use Papyrus\Launcher\ProcessFinder;
use Papyrus\Launcher\ProcessIdentity;
use Tests\TestCase;

/**
 * Stopping an install from a process that did not start it.
 *
 * ## Why this is tested at all
 *
 * Because `stop` is documented as the command a reader types when the application is too broken
 * to explain itself, and as one of the three the future tray icon calls. Both run as their own
 * process. The sandbox gate is what proved it did not work: it started the install, ran `stop`
 * from a second process, and found both ports still held and the JVM still alive, while the
 * launcher cheerfully reported that nothing had been started by this session.
 *
 * ## Why the tests never touch the real operating system
 *
 * Because a test that really starts a PHP process, finds it, and really terminates it is a test
 * that can terminate the PHPUnit runner instead, and this project has already spent one debugging
 * session on a probe that could not be told apart from the thing it was probing. The OS query is
 * injectable for exactly that reason, and the one thing tested for real is the refusal to kill
 * this process, which has no mock in it and is the safety property that matters most.
 */
class CrossProcessStopTest extends TestCase
{
    /**
     * A finder whose OS query is a fixed table, so a test states what is on the machine instead
     * of inheriting whatever happens to be running on the developer's.
     *
     * @param  array<string, list<int>>  $table  Token joined by a null byte, to pids.
     * @param  list<int>  $terminated
     * @return array{0: ProcessFinder, 1: \ArrayObject}
     */
    private function finderFor(array $table, array &$terminated = []): array
    {
        /** @var \ArrayObject<string, list<int>> $asked */
        $asked = new \ArrayObject;

        $finder = new ProcessFinder(function (array $tokens) use ($table, $asked, &$terminated): array {
            $asked[] = $tokens;

            $found = $table[implode("\0", $tokens)] ?? [];

            return $found;
        });

        $terminated = [];

        return [$finder, $asked];
    }

    public function test_it_finds_a_process_only_when_every_token_is_present(): void
    {
        $calls = [];

        $finder = new ProcessFinder(function (array $tokens) use (&$calls): array {
            $calls[] = $tokens;

            return [1234];
        });

        $pids = $finder->find(['C:/papyrus', 'queue:work']);

        $this->assertSame([1234], $pids);
        $this->assertSame([['C:/papyrus', 'queue:work']], $calls);
    }

    public function test_no_tokens_never_asks_the_operating_system(): void
    {
        $called = false;

        $finder = new ProcessFinder(function () use (&$called): array {
            $called = true;

            return [999];
        });

        /*
         * Because an empty token list matches every process on the machine, and the answer to
         * "which processes are this install's" with nothing to match on cannot be a list of every
         * process.
         */
        $this->assertSame([], $finder->find([]));
        $this->assertFalse($called, 'an empty token list must not reach the OS at all');
    }

    public function test_a_root_with_no_match_finds_nothing(): void
    {
        $finder = new ProcessFinder(fn (array $tokens): array => []);

        $this->assertSame([], $finder->find(['C:/somewhere-else', 'queue:work']));
    }

    public function test_a_role_marker_alone_would_match_too_much(): void
    {
        /*
         * The reason two tokens are always required, asserted rather than left as a comment.
         *
         * A developer with their own checkout open has a real `queue:work` process on this
         * machine. If a role marker were sufficient on its own, a reader typing `stop` would
         * terminate somebody's unrelated work.
         */
        $found = [];

        $finder = new ProcessFinder(function (array $tokens) use (&$found): array {
            $found[] = $tokens;

            return [];
        });

        $finder->find(['C:/papyrus', 'queue:work']);

        $this->assertCount(2, $found[0], 'both the install root and the role must be sent');
        $this->assertContains('queue:work', $found[0]);
        $this->assertNotSame($found[0], ['queue:work'], 'the role marker must never travel alone');
    }

    public function test_it_refuses_to_terminate_this_process(): void
    {
        /*
         * No mock here, and that is the point.
         *
         * The `stop` command is a PHP process whose own command line contains the install root,
         * so a matcher loose enough to find the running workers can find the command that is
         * trying to stop them. Without this the launcher kills the reader mid-command and reports
         * a tidy four process stop while doing it.
         */
        $finder = new ProcessFinder;

        $outcome = $finder->terminate(getmypid());

        $this->assertSame('refused, that is this process', $outcome);
        $this->assertTrue(true, 'this process is demonstrably still alive');
    }

    public function test_it_refuses_a_pid_that_is_not_a_pid(): void
    {
        $finder = new ProcessFinder;

        $this->assertSame('refused, that is this process', $finder->terminate(0));
        $this->assertSame('refused, that is this process', $finder->terminate(-1));
    }

    public function test_terminating_a_dead_pid_reports_it_is_already_gone(): void
    {
        $finder = new ProcessFinder;

        /*
         * A pid that cannot be alive. Not 999999999, which might be allocated on some machine,
         * but a pid that this process has just proved is not running by asking.
         */
        $dead = 999999999;
        $this->assertFalse(ProcessIdentity::isAlive($dead));

        $this->assertSame('already gone', $finder->terminate($dead));
    }

    public function test_the_probe_requires_all_tokens_not_just_one(): void
    {
        $method = new \ReflectionMethod(ProcessFinder::class, 'probeScript');
        $method->setAccessible(true);

        $script = (string) $method->invoke(null);

        /*
         * Longhand, not a nested Where-Object. A filter that reads as though it matches every
         * token but is really an "any of these" is how a matcher becomes a matcher for one token.
         */
        $this->assertStringContainsString('$matched = $true', $script);
        $this->assertStringContainsString('$matched = $false', $script);
        $this->assertStringContainsString('$proc.CommandLine.Contains($token)', $script);
    }

    public function test_the_probe_script_is_written_with_a_ps1_extension(): void
    {
        /*
         * The bug that made every other explanation wrong.
         *
         * `tempnam()` returns a name with no extension, and PowerShell refuses to run a file
         * passed to -File unless it ends in .ps1. The refusal is: "The file does not have a '.ps1'
         * extension." What arrives on stdout alongside it is PowerShell's own startup banner, so
         * the failure looks like a probe that ran and found nothing rather than one that never
         * ran at all.
         *
         * It survived several wrong diagnoses precisely because the output was never empty, and a
         * plausible-looking result is harder to distrust than an obvious one.
         */
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/packaging/launcher/src/ProcessFinder.php'
        );

        $this->assertStringContainsString(
            ".ps1'",
            $source,
            'the probe script must be given a .ps1 name or PowerShell will refuse to run it',
        );

        $this->assertMatchesRegularExpression(
            '/\$script\s*\.\=\s*\'\.ps1\'/',
            $source,
            'the .ps1 extension must be appended to the tempnam result',
        );
    }

    public function test_tokens_are_not_normalised_to_forward_slashes(): void
    {
        /*
         * Measured against a live worker, and the opposite of what the code used to do.
         *
         * proc_open puts a path on the child's command line as it was given, so what CIM holds
         * for a worker started with a data directory is the backslash form:
         *
         *     "...\php.exe" "-c" "C:\Users\<you>\Papyrus Data\php\php.ini" "...\artisan" "queue:work"
         *
         * Normalising the separators produces a token matching none of that, and a token that
         * matches nothing returns the same empty list as a token that found a machine with
         * nothing running. So it fails silently, which is the only way this could have shipped
         * looking correct.
         *
         * The body is read as code, with its comments stripped, because the reason this bug was
         * dangerous is precisely that the code looked right and the comment explained it away.
         * A test that also matched the prose could be satisfied by a comment and nothing else.
         */
        $supervisor = (string) file_get_contents(
            dirname(__DIR__, 2).'/packaging/launcher/src/Supervisor.php'
        );

        $start = strpos($supervisor, 'private function tokensFor');

        $this->assertIsInt($start, 'tokensFor must exist, or nothing builds the stop tokens');

        // Read to the closing brace of the method rather than a fixed number of characters: the
        // comment above the return is long, and a window sized to guess at it truncates exactly
        // where the assertion needs to look.
        $end = strpos((string) $supervisor, 'private function ini()', $start);

        $body = substr(
            (string) $supervisor,
            $start,
            (int) $end - $start,
        );

        // Strip line comments and block comments so only executable code is examined.
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $body);

        $this->assertStringContainsString(
            'return [$this->dataDirectory, $role];',
            $code,
            'the data directory must be sent exactly as this install knows it',
        );

        $this->assertStringNotContainsString(
            "str_replace('\\\\', '/', \$this->dataDirectory)",
            $code,
            'normalising separators to forward slashes produces a token that matches nothing',
        );
    }

    public function test_the_matcher_keys_on_the_data_directory_and_not_the_install_root(): void
    {
        /*
         * Two launchers can share one install root with different data directories.
         *
         * The lock lives in the data directory, so they do not exclude each other and both start.
         * Their four processes then carry an identical install root, so a matcher keyed on the
         * root would have `stop` on one install terminate the workers of the other.
         *
         * Keyed on the data directory this cannot happen, because the data directory is where the
         * lock is, and every one of the four processes carries it on its own command line.
         */
        $supervisor = (string) file_get_contents(
            dirname(__DIR__, 2).'/packaging/launcher/src/Supervisor.php'
        );

        $start = strpos($supervisor, 'private function tokensFor');
        $end = strpos((string) $supervisor, 'private function ini()', $start);

        $code = (string) preg_replace(
            ['#/\*.*?\*/#s', '#//[^\n]*#'],
            '',
            substr((string) $supervisor, $start, (int) $end - $start),
        );

        $this->assertStringNotContainsString(
            '$this->root',
            $code,
            'the install root is shared by two installs of one install, so it cannot identify '
                .'which processes belong to this one',
        );

        $this->assertStringContainsString(
            '$this->dataDirectory',
            $code,
            'the data directory is unique per install and is on every process command line',
        );
    }

    public function test_every_entry_point_that_builds_a_supervisor_also_requires_the_finder(): void
    {
        /*
         * The require lists are a second dependency graph, and nothing exercised it.
         *
         * The launcher has no autoloader, so `papyrus.php` names its dependencies by hand. When
         * `stopAll` began constructing a ProcessFinder, that list was not updated, and the suite
         * did not notice, because the tests reach Supervisor through Composer's autoloader and
         * never through those requires.
         *
         * A build would have fataled at the construction rather than at the use, which is the
         * worst place for it: the message would name a missing class rather than the launcher
         * decision that needed one, and every test would be green throughout.
         *
         * `build-install.php` kept a second copy of the same list, which is why this went wrong a
         * second time when `SuwayomiCredentials` arrived. It does not have one now, and the second
         * half of this test is what stops it getting one back.
         */
        $entryPoint = dirname(__DIR__, 2).'/packaging/launcher/papyrus.php';
        $build = dirname(__DIR__, 2).'/packaging/runtime/build-install.php';

        $this->assertStringContainsString(
            'ProcessFinder.php',
            (string) file_get_contents($entryPoint),
            'papyrus.php must require ProcessFinder, because Supervisor constructs one in its own'
                .' constructor and this file has no autoloader to find it with',
        );

        $buildSource = (string) file_get_contents($build);

        $this->assertStringNotContainsString(
            "DIRECTORY_SEPARATOR.'ProcessFinder.php'",
            $buildSource,
            'build-install.php must take the launcher dependency order from papyrus.php rather than'
                .' keeping its own copy. A second copy is a second graph nobody exercises, and it has'
                .' now been wrong twice',
        );

        $this->assertStringContainsString(
            "DIRECTORY_SEPARATOR.'papyrus.php'",
            $buildSource,
            'build-install.php must read the order from somewhere, and papyrus.php is where it is',
        );
    }
}
