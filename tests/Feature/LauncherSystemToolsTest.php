<?php

namespace Tests\Feature;

use Papyrus\Launcher\SystemTools;
use Tests\TestCase;

/**
 * The Windows tools the launcher starts, named by absolute path.
 *
 * ## What was broken
 *
 * `powershell`, `taskkill` and `tar` were launched by bare image name. Windows resolves one through
 * the application directory, then the current directory, then `System32` and `PATH`. The per-user
 * install lives under `%LOCALAPPDATA%\Programs`, which the reader owns, and the shortcut's working
 * directory can be anywhere. A marker executable named `powershell.exe` placed there would run with
 * the reader's privileges in place of the system tool.
 *
 * The behaviour that matters is which path is returned, so that is what is asserted. The source
 * assertions below catch the second half: a class that returns the right path while the caller still
 * passes the bare name is a fix applied in one place and not the place that runs.
 */
final class LauncherSystemToolsTest extends TestCase
{
    public function test_the_launcher_tools_are_named_by_absolute_path(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('the launcher runs on Windows and resolves these tools there');
        }

        $root = strtolower(str_replace('/', '\\', SystemTools::systemRoot()));

        foreach ([
            'powershell' => SystemTools::powershell(),
            'taskkill' => SystemTools::taskkill(),
            'tasklist' => SystemTools::tasklist(),
        ] as $name => $path) {
            $this->assertStringStartsWith(
                $root.'\\',
                strtolower(str_replace('/', '\\', $path)),
                $name.' must be inside the Windows directory, not wherever the process was started',
            );

            $this->assertFileExists($path, $name.' must name a tool that is really there');
        }
    }

    /**
     * A marker beside the process must not change the answer.
     *
     * This is the attack in its simplest form: put a file called `powershell.exe` in the directory
     * the process starts in, and a bare name runs it. The path returned here is independent of the
     * working directory by construction, and the assertion makes that a property rather than a hope.
     */
    public function test_a_marker_in_the_working_directory_does_not_change_the_tool_path(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('the launcher runs on Windows and resolves these tools there');
        }

        $before = SystemTools::powershell();
        $scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-tools-'.getmypid().'-'.uniqid();

        mkdir($scratch, 0o777, true);
        file_put_contents($scratch.DIRECTORY_SEPARATOR.'powershell.exe', 'not a program');
        file_put_contents($scratch.DIRECTORY_SEPARATOR.'taskkill.exe', 'not a program');

        $previous = getcwd();
        chdir($scratch);

        try {
            $this->assertSame($before, SystemTools::powershell());
            $this->assertStringNotContainsString($scratch, SystemTools::taskkill());
        } finally {
            if (is_string($previous)) {
                chdir($previous);
            }

            @unlink($scratch.DIRECTORY_SEPARATOR.'powershell.exe');
            @unlink($scratch.DIRECTORY_SEPARATOR.'taskkill.exe');
            @rmdir($scratch);
        }
    }

    public function test_the_process_code_uses_the_absolute_paths(): void
    {
        $root = dirname(__DIR__, 2).'/packaging/launcher/src';

        $prober = (string) file_get_contents($root.'/Prober.php');
        $finder = (string) file_get_contents($root.'/ProcessFinder.php');
        $identity = (string) file_get_contents($root.'/ProcessIdentity.php');

        $this->assertStringContainsString('SystemTools::powershell()', $prober);
        $this->assertStringContainsString('SystemTools::powershell()', $finder);
        $this->assertStringContainsString('SystemTools::taskkill()', $finder);
        $this->assertStringContainsString('SystemTools::powershell()', $identity);
        $this->assertStringContainsString('SystemTools::tasklist()', $identity);

        $this->assertStringNotContainsString("'powershell',", $prober);
        $this->assertStringNotContainsString("'powershell',", $finder);
        $this->assertStringNotContainsString("sprintf('taskkill ", $finder);
        $this->assertStringNotContainsString("'tasklist /FI", $identity);
    }
}
