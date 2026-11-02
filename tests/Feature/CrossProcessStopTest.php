<?php

namespace Tests\Feature;

use Papyrus\Launcher\ProcessFinder;
use Papyrus\Launcher\ProcessIdentity;
use Papyrus\Launcher\SystemTools;
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
     * A finder whose OS query is a fixed process table, so a test states what is on the machine
     * instead of inheriting whatever happens to be running on the developer's.
     *
     * @param  list<array{pid: int, name: string, executable: string, command: string}>  $processes
     * @param  list<array<string, mixed>>  $asked
     */
    private function finderFor(array $processes, array &$asked = []): ProcessFinder
    {
        return new ProcessFinder(function (array $spec) use ($processes, &$asked): array {
            $asked[] = $spec;

            return $processes;
        });
    }

    public function test_it_finds_a_process_only_when_every_token_is_present(): void
    {
        $asked = [];
        $finder = $this->finderFor([
            ['pid' => 1234, 'name' => 'php.exe', 'executable' => 'C:/papyrus/runtime/bin/php.exe', 'command' => 'php -c C:/papyrus/php.ini artisan queue:work'],
        ], $asked);

        $pids = $finder->find(['C:/papyrus', 'queue:work']);

        $this->assertSame([1234], $pids);
        $this->assertSame(['C:/papyrus', 'queue:work'], $asked[0]['tokens']);
    }

    public function test_no_query_never_asks_the_operating_system(): void
    {
        $called = false;

        $finder = new ProcessFinder(function () use (&$called): array {
            $called = true;

            return [['pid' => 999, 'name' => 'php.exe', 'executable' => '', 'command' => '']];
        });

        /*
         * Because a query with no anchors would match every process on the machine, and the answer
         * to "which processes are this install's" with nothing to match on cannot be a list of
         * every process.
         */
        $this->assertSame([], $finder->find([]));
        $this->assertFalse($called, 'an empty query must not reach the OS at all');
    }

    public function test_a_root_with_no_match_finds_nothing(): void
    {
        $finder = $this->finderFor([
            ['pid' => 1234, 'name' => 'php.exe', 'executable' => 'C:/papyrus/runtime/bin/php.exe', 'command' => 'php -c D:/other/php.ini artisan queue:work'],
        ]);

        $this->assertSame([], $finder->find(['C:/papyrus', 'queue:work']));
    }

    /**
     * The published defect: an editor holding this install's Caddyfile is not the web server.
     *
     * The old matcher required every token to be a substring of the whole command line, and the
     * web role token was the literal `frankenphp`. The data directory contains
     * `...\Papyrus\frankenphp\Caddyfile`, so an editor, diff tool or backup tool whose command line
     * named that file matched, and `stop` ran `taskkill /T /F` against it and its children.
     *
     * The process table below is the scenario rather than a description of it: both rows carry the
     * path, and only one is `frankenphp.exe` at this install's executable path.
     */
    public function test_an_editor_holding_the_install_caddyfile_is_not_the_web_process(): void
    {
        $data = 'C:\\Users\\<you>\\AppData\\Local\\Papyrus';
        $root = 'C:\\Program Files\\Papyrus';
        $caddyfile = $data.'\\frankenphp\\Caddyfile';

        $finder = $this->finderFor([
            [
                'pid' => 111,
                'name' => 'notepad.exe',
                'executable' => 'C:\\Windows\\System32\\notepad.exe',
                'command' => '"C:\\Windows\\System32\\notepad.exe" "'.$caddyfile.'"',
            ],
            [
                'pid' => 222,
                'name' => 'frankenphp.exe',
                'executable' => $root.'\\runtime\\bin\\frankenphp.exe',
                'command' => '"'.$root.'\\runtime\\bin\\frankenphp.exe" "run" "--config" "'.$caddyfile.'"',
            ],
        ]);

        $pids = $finder->find([
            'name' => 'frankenphp.exe',
            'executable' => $root.'\\runtime\\bin\\frankenphp.exe',
            'tokens' => [$data, $caddyfile],
        ]);

        $this->assertSame(
            [222],
            $pids,
            'the editor names the same Caddyfile and must not be matched, or stop kills unsaved work',
        );
    }

    /**
     * A same-named executable from another install is not this install's either.
     */
    public function test_the_executable_path_is_an_anchor_not_just_the_image_name(): void
    {
        $finder = $this->finderFor([
            ['pid' => 111, 'name' => 'frankenphp.exe', 'executable' => 'D:\\other\\runtime\\bin\\frankenphp.exe', 'command' => 'frankenphp run --config D:\\other\\data\\frankenphp\\Caddyfile'],
        ]);

        $this->assertSame([], $finder->find([
            'name' => 'frankenphp.exe',
            'executable' => 'C:\\Program Files\\Papyrus\\runtime\\bin\\frankenphp.exe',
            'tokens' => [],
        ]));
    }

    public function test_a_role_marker_alone_would_match_too_much(): void
    {
        /*
         * The reason a token list is never the whole query, asserted rather than left as a comment.
         *
         * A developer with their own checkout open has a real `queue:work` process on this
         * machine. If a role marker were sufficient on its own, a reader typing `stop` would
         * terminate somebody's unrelated work.
         */
        $found = [];

        $finder = new ProcessFinder(function (array $spec) use (&$found): array {
            $found[] = $spec;

            return [];
        });

        $finder->find(['C:/papyrus', 'queue:work']);

        $this->assertCount(2, $found[0]['tokens'], 'both the install root and the role must be sent');
        $this->assertContains('queue:work', $found[0]['tokens']);
        $this->assertNotSame($found[0]['tokens'], ['queue:work'], 'the role marker must never travel alone');
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

    public function test_the_probe_lists_the_fields_the_matcher_needs(): void
    {
        $method = new \ReflectionMethod(ProcessFinder::class, 'probeScript');
        $method->setAccessible(true);

        $script = (string) $method->invoke(null);

        /*
         * The matcher lives in PHP so a test can hand it a process table. That only works if the
         * script returns the fields the matcher anchors on. A script that filtered by token again
         * would put the rule back somewhere no test can reach.
         */
        $this->assertStringContainsString('name = [string]$_.Name', $script);
        $this->assertStringContainsString('executable = [string]$_.ExecutablePath', $script);
        $this->assertStringContainsString('command = [string]$_.CommandLine', $script);
        $this->assertStringNotContainsString('$proc.CommandLine.Contains($token)', $script);
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

        $start = strpos($supervisor, 'private function specFor');

        $this->assertIsInt($start, 'specFor must exist, or nothing builds the stop query');

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
            '$this->dataDirectory',
            $code,
            'the data directory must be sent exactly as this install knows it',
        );

        $this->assertStringNotContainsString(
            "str_replace('\\\\', '/', \$this->dataDirectory)",
            $code,
            'normalising separators to forward slashes produces a token that matches nothing',
        );
    }

    public function test_every_lookup_keys_on_the_data_directory_and_uses_the_exe_as_an_anchor(): void
    {
        /*
         * Two launchers can share one install root with different data directories.
         *
         * The lock lives in the data directory, so they do not exclude each other and both start.
         * Their four processes then carry an identical install root, so a query keyed only on the
         * root would have `stop` on one install terminate the workers of the other.
         *
         * The executable path is an anchor rather than an identity: it says which program, and the
         * data directory says which install. Both are needed, which is why the exact editor that
         * named the Caddyfile is excluded but this install's web server is not.
         */
        $supervisor = (string) file_get_contents(
            dirname(__DIR__, 2).'/packaging/launcher/src/Supervisor.php'
        );

        $start = strpos($supervisor, 'private function specFor');
        $end = strpos((string) $supervisor, 'private function ini()', $start);

        $code = (string) preg_replace(
            ['#/\*.*?\*/#s', '#//[^\n]*#'],
            '',
            substr((string) $supervisor, $start, (int) $end - $start),
        );

        $this->assertStringContainsString(
            '$this->dataDirectory',
            $code,
            'the data directory is unique per install and is on every process command line',
        );

        $this->assertStringContainsString(
            "'executable' =>",
            $code,
            'the exact executable path is what tells this install\'s web server from an editor '
                .'that happens to have the same file open',
        );
    }

    public function test_every_process_the_stop_loop_looks_for_has_a_token_it_can_match_on(): void
    {
        /*
         * The stop loop is a list of names, and `specFor` is a separate list of names. A name in
         * the first with no case in the second is looked for with an empty token list, and an empty
         * token list matches nothing and prints nothing. So it is a process that is silently never
         * stopped, and the stop line looks complete.
         *
         * That is exactly how the embedded browser was missed. Suwayomi's `kcef` helper is launched
         * by the JVM rather than by this class, so it is never in `$started` and has to be found by
         * the matcher, and nothing found it. It survived every stop holding `jcef_helper.exe` open
         * inside the reader's data directory, which is what made that directory refuse to delete
         * afterwards with "Access is denied". It was found by running a real install, uninstalling
         * it, and then failing to empty a folder nothing was using any more.
         *
         * Asserted over both lists rather than naming `kcef`, because the property worth protecting
         * is "nothing can be added to one of these without the other", and a test that named the
         * fifth entry would have had to be edited for the sixth.
         *
         * Read as code with the comments stripped, for the reason the two tests above give: the
         * failure mode was code that looked right next to a comment that explained it away.
         */
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/packaging/launcher/src/Supervisor.php'
        );

        $code = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);

        $this->assertSame(
            1,
            preg_match("/foreach \(\[([^\]]*)\] as \\\$name\)/", $code, $loop),
            'the stop loop must be a literal list, or this test cannot read it',
        );

        preg_match_all("/'([a-z]+)'/", $loop[1], $wanted);

        $this->assertNotEmpty($wanted[1], 'the stop loop parsed to nothing, so this would pass for the wrong reason');

        $start = strpos($code, 'private function specFor');
        $end = strpos($code, 'private function ini()', $start);

        $this->assertIsInt($start, 'specFor must exist, or nothing builds the stop query');

        $matcher = substr($code, $start, (int) $end - $start);

        foreach ($wanted[1] as $name) {
            $this->assertMatchesRegularExpression(
                "/'".preg_quote($name, '/')."'\s*=>/",
                $matcher,
                "the stop loop looks for {$name}, so specFor must have a query for it. "
                    .'Without one it is searched for with an empty query, which matches nothing and '
                    .'reports nothing, and the process survives every stop.',
            );
        }

        /*
         * And the reason the last one is worth a test of its own: it is not in `$started` and never
         * was, so it has no other route out. Asserted so that removing it does not quietly restore
         * the undeletable data directory while every other test still passes.
         */
        $this->assertContains(
            'kcef',
            $wanted[1],
            'the embedded browser has to be in the stop loop, or it outlives the install',
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

    // -------------------------------------------------------------------------------------------
    // The command that does the killing, which is where the defect was.
    // -------------------------------------------------------------------------------------------

    /**
     * A finder that records the commands it would run and answers with a fixed exit status.
     *
     * The command is asserted rather than the effect because the effect cannot be produced in a test.
     * `waitForExit()` genuinely asks the operating system, so a fake that claimed a process had died
     * would be asserting against its own fiction, and a test that starts a real process to kill is a
     * test that can kill the PHPUnit runner instead. See this class's own docblock.
     *
     * @param  array<string, int>  $answers  Exit status per pid.
     * @param  list<string>  $issued
     */
    private function killerFor(array $answers, array &$issued): ProcessFinder
    {
        return new ProcessFinder(
            fn (array $spec): array => [],
            function (string $command) use ($answers, &$issued): array {
                $issued[] = $command;

                preg_match('/PID (\d+)/', $command, $found);

                $pid = (string) (int) ($found[1] ?? 0);

                return ['status' => $answers[$pid] ?? 0];
            },
        );
    }

    /**
     * ## The defect, measured on the reader's own install
     *
     * `terminate()` used to try `taskkill /PID <pid> /T` first and fall back to `/F`. The first pass
     * cannot terminate a console process on Windows at all, and it reported a success it had not
     * achieved:
     *
     *     taskkill /PID 8396 /T          exit 128
     *       SUCCESS: Sent termination signal to process with PID 30280, child of PID 8396.
     *       ERROR:   The process with PID 8396 could not be terminated.
     *
     * 30280 was still running afterwards. A `stop` that ran the pretend pass first was therefore
     * slower than one that did not, printed a false success, and killed nothing.
     *
     * This asserts the command, because the command was the defect. A class whose Windows branch
     * shells out cannot be tested any other way, and that is why this went unnoticed: `terminate()`
     * had no test at all.
     */
    public function test_the_only_kill_command_is_the_forceful_one(): void
    {
        $pid = $this->somethingThatIsAlive();

        $issued = [];
        $finder = $this->killerFor([(string) $pid => 0], $issued);

        $finder->terminate($pid);

        $this->assertCount(1, $issued, 'one command, because there is one that works');
        $this->assertStringContainsString('/T /F', $issued[0], 'and it is the forceful one');
        $this->assertDoesNotMatchRegularExpression(
            '/\/T\s+2>NUL/',
            $issued[0],
            'with no `/T` alone anywhere: that pass cannot stop a console process and reports a '
            .'success it did not achieve',
        );
    }

    /**
     * The system tool is named by absolute path, not resolved through the current directory.
     *
     * Windows resolves a bare image name through the application directory, then the current
     * directory, then `System32` and `PATH`. The per-user install lives under
     * `%LOCALAPPDATA%\Programs`, which the reader owns, and the shortcut's working directory can
     * be anywhere. A `taskkill.exe` placed there would run with the reader's privileges instead of
     * the system tool, in the one command that force kills processes.
     */
    public function test_the_kill_command_names_the_system_tool_by_absolute_path(): void
    {
        $pid = $this->somethingThatIsAlive();

        $issued = [];
        $this->killerFor([(string) $pid => 0], $issued)->terminate($pid);

        $this->assertStringContainsString(
            SystemTools::taskkill(),
            $issued[0],
            'the command must carry the absolute System32 path',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/^(taskkill|"taskkill")\s/',
            $issued[0],
            'a bare taskkill is resolved through the reader-owned working directory first',
        );
    }

    /**
     * Adding the pretend-graceful pass back is the mutation this test exists to kill.
     */
    public function test_no_pass_before_the_forceful_one_is_attempted(): void
    {
        $pid = $this->somethingThatIsAlive();

        $issued = [];

        $this->killerFor([(string) $pid => 0], $issued)->terminate($pid);

        foreach ($issued as $command) {
            $this->assertStringNotContainsString(
                ' 2>NUL',
                str_replace('/T /F 2>NUL', '', $command),
                'every command issued must carry /F, and there must be no command without it',
            );
        }
    }

    /**
     * `/T` is kept, because Suwayomi's `jcef_helper` children hold files open in the reader's data
     * directory and a JVM stopped without its tree leaves that directory undeletable.
     */
    public function test_the_tree_is_taken_with_the_parent(): void
    {
        $pid = $this->somethingThatIsAlive();

        $issued = [];
        $this->killerFor([(string) $pid => 0], $issued)->terminate($pid);

        $this->assertStringContainsString('/T', $issued[0]);
    }

    /**
     * A failure is named as one, rather than reported as a stop that did not stop anything.
     */
    public function test_a_refused_kill_is_reported_as_one(): void
    {
        $pid = $this->somethingThatIsAlive();

        $issued = [];

        $this->assertSame(
            'could not be stopped',
            $this->killerFor([(string) $pid => 1], $issued)->terminate($pid),
        );
    }

    /**
     * Its own pid, which is the safety property this class most has to get right.
     */
    public function test_it_will_not_run_any_command_against_itself(): void
    {
        $issued = [];
        $finder = $this->killerFor([], $issued);

        $this->assertSame('refused, that is this process', $finder->terminate((int) getmypid()));
        $this->assertSame([], $issued, 'and issues no command at all');
    }

    /**
     * A process that is not running is not killed, which is the same property seen from the other side.
     */
    public function test_a_process_that_is_already_gone_is_not_killed(): void
    {
        $issued = [];
        $finder = $this->killerFor([], $issued);

        $this->assertSame('already gone', $finder->terminate(4194303));
        $this->assertSame([], $issued);
    }

    /**
     * A pid of zero or less, which is what a failed `getmypid()` would produce.
     */
    public function test_a_pid_of_zero_or_less_issues_no_command(): void
    {
        $issued = [];
        $finder = $this->killerFor([], $issued);

        foreach ([0, -1] as $pid) {
            $this->assertSame('refused, that is this process', $finder->terminate($pid));
        }

        $this->assertSame([], $issued);
    }

    /**
     * A real, live pid on this machine that is not this one.
     *
     * The parent, because it cannot have exited while this is running and `terminate()` refuses its
     * own pid by design. That refusal is the safety property that matters most here, so a test cannot
     * use the runner itself: the first version did, and every assertion passed against the refusal
     * branch instead of the code under test.
     *
     * Read from the operating system rather than invented, so a machine where it cannot be read says
     * so and skips rather than testing nothing.
     */
    private function somethingThatIsAlive(): int
    {
        $parent = @shell_exec(
            'powershell -NoProfile -NonInteractive -Command '
            .'"(Get-CimInstance Win32_Process -Filter \"ProcessId='.getmypid().'\").ParentProcessId" 2>NUL',
        );

        $pid = is_string($parent) && ctype_digit(trim($parent)) ? (int) trim($parent) : 0;

        if ($pid <= 0 || ! ProcessIdentity::isAlive($pid)) {
            $this->markTestSkipped('no second live process to name, so the check would prove nothing');
        }

        return $pid;
    }
}
