<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Windows Sandbox runners, and the two ways each one fails silently.
 *
 * ## Why this exists
 *
 * Because a `.wsb` whose `LogonCommand` points at a file that is not there does not complain. The
 * guest never runs the script, the sandbox window closes, and the host sees nothing at all: no
 * output, exit code zero, and no file in the results folder. That is indistinguishable from a slow
 * boot, and it has cost this project real time more than once, which is why the gate's own comment
 * warns about it twice.
 *
 * So the properties worth having are checked here rather than discovered by waiting out a boot.
 *
 * ## What is not asserted, and why
 *
 * The host paths inside a `.wsb` are this machine's. Asserting them would make the test pass here
 * and fail everywhere else, which is a test that teaches the wrong thing. What is asserted is the
 * part that is the project's: that the runner named in the file exists, and that the runner does the
 * thing its name promises.
 */
class SandboxRunnerTest extends TestCase
{
    private const DIR = 'build/sandbox';

    /**
     * @return list<array{string}>
     */
    public static function sandboxes(): array
    {
        return [['papyrus.wsb'], ['papyrus-hold.wsb']];
    }

    #[DataProvider('sandboxes')]
    public function test_a_sandbox_configuration_is_well_formed_xml(string $file): void
    {
        $path = self::DIR.'/'.$file;

        $this->assertFileExists($path, $path.' must be committed, or the sandbox cannot be launched at all');

        $parsed = @simplexml_load_file($path);

        $this->assertNotFalse(
            $parsed,
            $path.' is not well formed XML. Windows Sandbox reports a malformed configuration by doing '
                .'nothing visible whatsoever.',
        );
        $this->assertSame('Configuration', $parsed->getName());
    }

    /**
     * The one that actually bites: a runner path that does not resolve.
     *
     * A missing script produces no error in the guest, no window, and an exit code of zero on the
     * host. It looks exactly like a machine that is being slow, so this checks the resolution rather
     * than trusting anybody to notice after a two minute boot.
     */
    #[DataProvider('sandboxes')]
    public function test_the_runner_a_sandbox_actually_launches_exists(string $file): void
    {
        $path = self::DIR.'/'.$file;
        $parsed = simplexml_load_file($path);

        $command = trim((string) $parsed->LogonCommand->Command);

        $this->assertNotSame('', $command, $file.' has no LogonCommand, so the guest boots to nothing');

        // The runner is the last argument of a `-File` invocation. Pulling it out rather than
        // pattern matching the whole line keeps this honest about what is being resolved.
        $this->assertMatchesRegularExpression(
            '/-File\s+(\S+\.ps1)\s*$/i',
            $command,
            $file.' must launch a .ps1 with -File',
        );

        preg_match('/-File\s+(\S+\.ps1)\s*$/i', $command, $matches);

        $runner = str_replace('\\', '/', $matches[1]);

        $this->assertFileExists(
            self::DIR.'/'.basename($runner),
            $file.' launches '.$runner.', and there is no such file in '.self::DIR.'. In the guest this '
                .'means no runner, no output, and a sandbox that closes looking exactly like a slow boot.',
        );
    }

    /**
     * Both runners need a writable results folder, and it has to be a separate mapping.
     *
     * Windows Sandbox will not create a mapped folder that is missing on the host. The mount fails,
     * the logon command never runs, and the same nothing-visible failure happens. A separate mapping
     * is also what lets a guest write a report the host can still read once the guest is gone.
     *
     * @return list<array{string}>
     */
    #[DataProvider('sandboxes')]
    public function test_results_are_mapped_somewhere_writable_and_separate_from_the_payload(string $file): void
    {
        $parsed = simplexml_load_file(self::DIR.'/'.$file);

        $folders = $parsed->MappedFolders->MappedFolder;

        $writable = [];
        foreach ($folders as $folder) {
            if (trim((string) $folder->ReadOnly) === 'false') {
                $writable[] = trim((string) $folder->SandboxFolder);
            }
        }

        $this->assertCount(1, $writable, $file.' needs exactly one writable mapping for results');
        $this->assertSame('C:\results', $writable[0]);

        // The payload mount is read only, and has to be a different folder. Mapping one folder both
        // ways would make the results writeable and the payload absent in the same stroke.
        $readOnly = [];
        foreach ($folders as $folder) {
            if (trim((string) $folder->ReadOnly) === 'true') {
                $readOnly[] = trim((string) $folder->SandboxFolder);
            }
        }

        $this->assertContains('C:\build', $readOnly, $file.' must mount the build read only');
        $this->assertNotContains('C:\results', $readOnly);
    }

    /**
     * The script with its comments removed, which is the only thing worth asserting on.
     *
     * Both assertions in this file that read the raw file were wrong the first time, and for the
     * same reason: the runner explains at length why it does *not* use the gate's ports, so the
     * numbers `8402` and `4571` appear in the prose on purpose, and a substring check over the whole
     * file reported the explanation as the defect.
     *
     * That is the "a grep that cannot match what is there is not evidence it is absent" family
     * pointed the other way: a grep that matches too much is not evidence of anything either. A check
     * which reads the comments is reporting on the author's explanation rather than on the program.
     */
    private static function code(string $file): string
    {
        $lines = explode("\n", (string) file_get_contents(self::DIR.'/'.$file));

        $kept = [];
        foreach ($lines as $line) {
            if (! str_starts_with(ltrim($line), '#')) {
                $kept[] = $line;
            }
        }

        return implode("\n", $kept);
    }

    /**
     * The hold runner uses the ports a reader actually sees.
     *
     * This is the property that keeps lesson 228 from recurring. The gate forces 8402 and 4571 so
     * that two things on one machine cannot collide, and those numbers exist nowhere else. A person
     * being shown how to use the app must be shown the addresses a person actually uses, or the one
     * thing this runner exists to prevent is the thing it teaches.
     */
    public function test_the_hold_runner_uses_a_real_installs_ports(): void
    {
        $code = self::code('hold-open.ps1');

        $this->assertStringContainsString(
            '$appPort = 8000',
            $code,
            'the hold runner must show the port a real install binds',
        );
        $this->assertStringContainsString('$suwayomiPort = 4567', $code);

        foreach (['8402', '4571'] as $gateOnly) {
            $this->assertStringNotContainsString(
                $gateOnly,
                $code,
                $gateOnly.' is the clean-room gate port and belongs to nobody else. Using it here teaches '
                    .'exactly the wrong address.',
            );
        }
    }

    /**
     * And it says which machine to type the address into, because that is the actual trap.
     *
     * A guest has its own loopback. A host browser asking the host's `127.0.0.1` for the guest's
     * port gets `ERR_CONNECTION_REFUSED` from a perfectly healthy install, and there is nothing in
     * that error that distinguishes it from a broken one. The runner is the only place available to
     * say so.
     */
    public function test_the_hold_runner_says_the_browser_must_be_inside_the_guest(): void
    {
        // Read with the comments intact, because this one is genuinely about the words. The warning
        // lives in a `Say` call the reader sees on screen, not in a comment they never read.
        $runner = (string) file_get_contents(self::DIR.'/hold-open.ps1');

        $this->assertMatchesRegularExpression(
            '/INSIDE this sandbox window/i',
            $runner,
            'the runner must say the address is typed into a browser inside the guest',
        );

        // Without this a reader is left guessing which of two machines the printed address belongs
        // to, which is the whole of lesson 228.
        $this->assertMatchesRegularExpression(
            '/not into one on the host/i',
            $runner,
        );
    }

    /**
     * The hold runner has to hold, or it is just the gate with a slower ending.
     *
     * A gate stops everything it started, because leaving four processes running on a machine whose
     * only job was to prove they start would make the next run meaningless. This one deliberately
     * does not, and the difference is the whole reason it exists.
     */
    public function test_the_hold_runner_holds_rather_than_stopping(): void
    {
        $code = self::code('hold-open.ps1');

        $hold = strpos($code, 'while ($true)');
        $this->assertNotFalse($hold, 'the runner must hold the terminal open, which keeps the app alive');

        $before = substr($code, 0, $hold);

        $this->assertStringNotContainsStringIgnoringCase(
            'stop',
            $before,
            'the hold runner must not stop what it started before it holds. If it does, it is the gate '
                .'with a slower ending.',
        );
    }
}
