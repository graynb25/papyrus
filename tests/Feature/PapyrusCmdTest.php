<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * The one file a reader ever runs, tested by actually running it.
 *
 * ## Why this is a test and not a read of the file
 *
 * `Papyrus.cmd` is the Start Menu entry, the shortcut target, and the thing a reader double-clicks.
 * Everything else in the launcher is exercised by the suite through `Supervisor` and `Health`. This
 * file was not exercised at all: the build checked that it existed and that its line endings were
 * CRLF, and nothing ran it.
 *
 * That gap is how the worst message in the project shipped. A fresh install is 623MB of newly
 * written binaries, antivirus scans them before letting any of them execute, and for that window
 * `cmd.exe` refuses the runtime and writes one line to stderr:
 *
 *     Access is denied.
 *
 * With nothing in the launcher to catch that, `Papyrus.cmd` printed its own message underneath:
 *
 *     Papyrus did not start. The lines above say why.
 *
 * Which is true, and useless. The reason was not Papyrus failing, it was Windows declining to run
 * the interpreter Papyrus is written in. The message points a reader, and anyone reading over their
 * shoulder, at the wrong layer entirely.
 *
 * ## How a batch file is reproduced without a batch file test framework
 *
 * By making a scratch install and running the real file in it. A runtime that cannot execute is a
 * **directory** named `php.exe`: `if exist` is satisfied, so the old "the file is missing" branch is
 * not what fires, and `cmd.exe` refuses to run it, which is exactly the antivirus case. For the
 * working case the runtime is a copy of the PHP running this suite, and the launcher is a stub that
 * prints a reason and exits non-zero.
 *
 * Both cases run the real `Papyrus.cmd`, copied with CRLF endings the way the build writes it, because
 * running the repository's LF copy would measure defect 18 instead of the thing under test.
 */
final class PapyrusCmdTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Papyrus.cmd is a Windows batch file and this project ships one platform');
        }

        $this->scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-cmd-'.getmypid().'-'.uniqid();

        mkdir($this->scratch.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'bin', 0o777, true);
        mkdir($this->scratch.DIRECTORY_SEPARATOR.'packaging'.DIRECTORY_SEPARATOR.'launcher', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->scratch);

        parent::tearDown();
    }

    private function removeTree(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
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

    /**
     * The shipped file, with the endings the build gives it.
     *
     * Written as a copy rather than run from the repository because the repository holds it with LF
     * and the install holds it with CRLF, and `cmd.exe` parses by CRLF. Running the repository copy
     * would launch the command and discard the output, which is defect 18, and every assertion here
     * would fail for a reason that has nothing to do with what is being tested.
     */
    private function installTheRealScript(): string
    {
        $source = __DIR__.'/../../packaging/launcher/Papyrus.cmd';
        $contents = (string) file_get_contents($source);

        $target = $this->scratch.DIRECTORY_SEPARATOR.'Papyrus.cmd';

        file_put_contents($target, str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $contents)));

        return $target;
    }

    /**
     * Run a command in cmd.exe and return its output and exit code.
     *
     * Not named `run`, because that is a final method on PHPUnit's TestCase and shadowing it is a
     * fatal error rather than a surprise.
     *
     * stdin is closed immediately, because `Papyrus.cmd` calls `pause` on every failure path and
     * `pause` against a closed stdin returns at once. Without that the suite would sit on a keypress
     * nobody is there to give, and a test that hangs is worse than no test.
     *
     * @return array{output: string, status: int}
     */
    private function runCmd(string $command): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);

        $this->assertIsResource($process, 'cmd.exe could not be started at all');

        fclose($pipes[0]);

        $output = (string) stream_get_contents($pipes[1]);
        $output .= (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['output' => $output, 'status' => proc_close($process)];
    }

    /**
     * A runtime Windows will not execute, and a launcher that exists.
     *
     * The runtime is a directory. `if exist "%PAPYRUS_PHP%"` is satisfied by it, so the branch that
     * fires is not "this install is incomplete", and `cmd.exe` refuses to run it. That is the shape
     * antivirus leaves behind, and it is the only way to reproduce the failure without installing
     * antivirus software.
     */
    private function installAnUnrunnableRuntime(): void
    {
        mkdir(
            $this->scratch.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'php.exe',
            0o777,
            true,
        );

        file_put_contents(
            $this->scratch.DIRECTORY_SEPARATOR.'packaging'.DIRECTORY_SEPARATOR.'launcher'.DIRECTORY_SEPARATOR.'papyrus.php',
            "<?php\n// never reached: the runtime cannot execute\nexit(0);\n",
        );
    }

    /**
     * A real `php.exe`, or null when there is not one to be had.
     *
     * `PHP_BINARY` is normally the interpreter itself, but under Herd it is a `php.bat` wrapper,
     * which cannot stand in for the runtime: copying it produced a batch file named `php.exe` that
     * cmd.exe refuses, so the test measured the wrong failure and looked like a product bug. It also
     * has to travel with `php8.dll`, because a Windows PHP on its own will not load.
     *
     * Returns null rather than skipping inside itself, so the caller decides what a missing
     * interpreter means for the test that wanted one.
     */
    private function aRealPhpExecutable(): ?string
    {
        $candidates = [];

        if (strtolower((string) pathinfo(PHP_BINARY, PATHINFO_EXTENSION)) === 'exe') {
            $candidates[] = PHP_BINARY;
        }

        // Herd's layout: a php.bat in bin, and the real thing one level down in bin/php84/php.exe.
        foreach (glob(dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'php*'.DIRECTORY_SEPARATOR.'php.exe') ?: [] as $found) {
            $candidates[] = $found;
        }

        foreach (['C:/php/php.exe', 'C:/xampp/php/php.exe'] as $conventional) {
            if (is_file($conventional)) {
                $candidates[] = $conventional;
            }
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * A runtime that works, and a launcher that refuses and says why.
     *
     * Copied with its DLLs rather than bare, because a Windows PHP that cannot find `php8.dll`
     * beside it exits without saying anything, and a test whose stub silently prints nothing would
     * fail on a missing string rather than on a behaviour.
     */
    private function installARuntimeThatRefuses(): bool
    {
        $real = $this->aRealPhpExecutable();

        if ($real === null) {
            return false;
        }

        $bin = $this->scratch.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'bin';

        copy($real, $bin.DIRECTORY_SEPARATOR.'php.exe');

        foreach (glob(dirname($real).DIRECTORY_SEPARATOR.'php*.dll') ?: [] as $dll) {
            copy($dll, $bin.DIRECTORY_SEPARATOR.basename($dll));
        }

        file_put_contents(
            $this->scratch.DIRECTORY_SEPARATOR.'packaging'.DIRECTORY_SEPARATOR.'launcher'.DIRECTORY_SEPARATOR.'papyrus.php',
            "<?php\nfwrite(STDOUT, \"Suwayomi refused a query behind its login.\".PHP_EOL);\nexit(1);\n",
        );

        return true;
    }

    /**
     * A runtime Windows will not run is named, and the real cause is offered.
     *
     * The assertion that matters is the negative one. "The lines above say why" is the message that
     * sent the reader to the wrong layer, so its absence is the fix, and a test that only checked for
     * the new wording would pass against a script printing both.
     */
    public function test_a_runtime_windows_refuses_to_execute_is_named_and_explained(): void
    {
        $this->installAnUnrunnableRuntime();

        $cmd = $this->installTheRealScript();

        $result = $this->runCmd('cmd.exe /c '.(escapeshellarg($cmd)).' < NUL');

        $this->assertNotSame(0, $result['status'], 'a refused runtime must not report success');

        $this->assertStringContainsString(
            'refused to run the PHP runtime',
            $result['output'],
            'the reader is told Windows refused the runtime, not that Papyrus failed',
        );

        $this->assertStringContainsString(
            'antivirus',
            $result['output'],
            'the likeliest cause is named, because the reader can act on it and cannot act on "access denied"',
        );

        $this->assertStringNotContainsString(
            'The lines above say why',
            $result['output'],
            'this pointed at the wrong layer: the reason is cmd.exe refusing the interpreter, and it has no lines above',
        );
    }

    /**
     * When the launcher really is the thing that refused, its own reason is what points at it.
     *
     * The other half of the distinction, and the reason the fix could not simply delete the old
     * message. The launcher's output is on stdout directly above, so "the lines above say why" is
     * accurate in this case and is the only thing that tells a reader where to look.
     */
    public function test_a_launcher_that_refuses_still_points_at_its_own_output(): void
    {
        if (! $this->installARuntimeThatRefuses()) {
            $this->markTestSkipped(
                'no real php.exe was found to stand in for the runtime, and Herd\'s php.bat cannot: '
                .'the other two tests in this file need no interpreter and still run'
            );
        }

        $cmd = $this->installTheRealScript();

        $result = $this->runCmd('cmd.exe /c '.(escapeshellarg($cmd)).' < NUL');

        $this->assertNotSame(0, $result['status'], 'a launcher that exits non-zero must be reported as a failure');

        $this->assertStringContainsString(
            'Suwayomi refused a query behind its login.',
            $result['output'],
            "the launcher's own reason reaches the reader",
        );

        $this->assertStringContainsString(
            'The lines above say why',
            $result['output'],
            'this message is correct when the launcher really is what failed',
        );

        $this->assertStringNotContainsString(
            'refused to run the PHP runtime',
            $result['output'],
            'a working runtime must not be reported as a refused one',
        );
    }

    /**
     * A missing runtime is still a missing runtime, and is still not the antivirus message.
     *
     * The pre-existing branch, kept honest by this. It is a different problem with a different fix,
     * and folding it into the new wording would tell a reader with a genuinely incomplete install to
     * go and change their antivirus settings.
     */
    public function test_a_missing_runtime_is_reported_as_an_incomplete_install(): void
    {
        file_put_contents(
            $this->scratch.DIRECTORY_SEPARATOR.'packaging'.DIRECTORY_SEPARATOR.'launcher'.DIRECTORY_SEPARATOR.'papyrus.php',
            "<?php\nexit(0);\n",
        );

        $cmd = $this->installTheRealScript();

        $result = $this->runCmd('cmd.exe /c '.(escapeshellarg($cmd)).' < NUL');

        $this->assertNotSame(0, $result['status']);

        $this->assertStringContainsString('This install is incomplete', $result['output']);

        $this->assertStringNotContainsString(
            'antivirus',
            $result['output'],
            'an absent file is not an antivirus problem and must not be dressed up as one',
        );
    }
}
