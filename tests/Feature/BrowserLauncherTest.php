<?php

namespace Tests\Feature;

use Papyrus\Launcher\BrowserLauncher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Opening the reader's browser at the address the launcher just bound.
 *
 * ## Why this is worth a class
 *
 * Because it cost two wrong addresses in one day, both against a perfectly healthy install: `papyrus.test`,
 * which is a host-only name that cannot resolve inside the clean-room sandbox, and `8402`, which is the
 * port the gate forces rather than the default of 8000. In both cases the app was fine and the address
 * was wrong, and the reader had no way to tell those apart from a broken app.
 *
 * So the port is no longer something to know. The launcher prints it and now opens it.
 *
 * ## What is asserted
 *
 * The exact command, because the failure mode is a command that is subtly wrong and silently opens
 * nothing: `start` reads a quoted first argument as the window *title*, so a missing title argument
 * produces a console window with the URL in its title bar and no browser. A test that only checked
 * "it was called" would pass over that.
 */
class BrowserLauncherTest extends TestCase
{
    /**
     * The command, on the loopback URL the launcher actually produces.
     */
    public function test_it_asks_windows_shell_to_open_the_url(): void
    {
        $this->assertSame(
            'cmd /c start "" http://127.0.0.1:8000',
            (new BrowserLauncher)->commandFor('http://127.0.0.1:8000'),
            'the empty quoted argument is the window title. Without it, start reads the URL as the title '
                .'and opens nothing.',
        );
    }

    public function test_it_opens_a_loopback_url(): void
    {
        $ran = null;
        $launcher = new BrowserLauncher(function (string $command) use (&$ran): void {
            $ran = $command;
        });

        $this->assertTrue($launcher->open('http://127.0.0.1:8000'));
        $this->assertSame('cmd /c start "" http://127.0.0.1:8000', $ran);
    }

    /**
     * Every port the launcher can actually produce is accepted.
     *
     * Two digits is the floor, and that is deliberate rather than lazy: a one digit port is not a thing
     * this launcher binds, and the loopback regex is the only thing standing between a value from the
     * environment and a shell. The bound being "the launcher never does that" is the reason it can be
     * narrow without costing anything.
     *
     * @return list<array{string}>
     */
    public static function realPorts(): array
    {
        return [['http://127.0.0.1:8000'], ['http://127.0.0.1:8402'], ['http://127.0.0.1:4567'], ['http://127.0.0.1:65535']];
    }

    #[DataProvider('realPorts')]
    public function test_a_port_the_launcher_could_really_bind_is_accepted(string $url): void
    {
        $this->assertTrue((new BrowserLauncher(fn (string $c): null => null))->open($url), $url);
    }

    /**
     * And nothing else is, because this value can arrive from the environment.
     *
     * Papyrus serves on loopback and nothing else. Refusing everything else costs nothing and means a
     * value from `PAPYRUS_APP_PORT` or a command line can never become a shell command.
     *
     * @return list<array{string, string}>
     */
    public static function refusedUrls(): array
    {
        return [
            'a remote host' => ['http://example.test:8000'],
            'a public host' => ['http://127.0.0.1.evil.test:8000'],
            'no port' => ['http://127.0.0.1'],
            'not loopback' => ['http://localhost:8000'],
            'https, which this never serves' => ['https://127.0.0.1:8000'],
            'a shell metacharacter in the port' => ['http://127.0.0.1:8000&calc'],
            'a command' => ['cmd /c calc'],
            'empty' => [''],
        ];
    }

    #[DataProvider('refusedUrls')]
    public function test_anything_that_is_not_a_loopback_url_is_refused(string $url): void
    {
        $ran = false;
        $launcher = new BrowserLauncher(function (string $command) use (&$ran): void {
            $ran = true;
        });

        $this->assertFalse($launcher->open($url), $url.' must not be opened');
        $this->assertFalse($ran, 'nothing may have been run for '.$url);
    }

    /**
     * An opener that throws is reported as a failure, not as a success.
     *
     * The first version of this test asserted the opposite and was wrong. Returning `false` is what
     * makes the launcher say "Could not open a browser for you, the address above is all you need",
     * which is the only useful thing it can say. Returning `true` after an opener that threw would
     * report a browser window the reader never saw.
     */
    public function test_an_opener_that_throws_is_reported_as_a_failure(): void
    {
        $launcher = new BrowserLauncher(function (string $command): void {
            throw new \RuntimeException('no browser here');
        });

        $this->assertFalse($launcher->open('http://127.0.0.1:8000'));
    }

    /**
     * `Papyrus.cmd` passes it, because a launcher flag nobody sets opens nothing.
     *
     * Asserted against the file rather than inferred, because the whole change is one word in a batch
     * file and a test of the launcher alone would pass with the shortcut not passing the flag at all.
     *
     * The first version of this asserted the launcher path appeared in the command, and it does not:
     * the batch file calls a `%PAPYRUS_LAUNCHER%` variable it set two lines earlier. So the property
     * worth asserting is the pair, that the variable points at `papyrus.php` and the call carries the
     * flag. Either half alone would pass with the feature unreachable.
     */
    public function test_the_shortcut_passes_the_flag_to_the_launcher(): void
    {
        $cmd = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/launcher/Papyrus.cmd');

        $this->assertStringContainsString(
            'set "PAPYRUS_LAUNCHER=%PAPYRUS_ROOT%packaging\launcher\papyrus.php"',
            $cmd,
            'the shortcut must be calling the launcher this test knows about',
        );

        $this->assertMatchesRegularExpression(
            '/"%PAPYRUS_LAUNCHER%"\s+start\s+--open/',
            $cmd,
            'Papyrus.cmd must ask for the browser, or this feature is unreachable from the only thing a '
                .'reader runs',
        );

        // The gate runs the launcher directly, so it must NOT open a browser on a machine with none.
        $this->assertStringNotContainsString(
            '--open',
            (string) file_get_contents(dirname(__DIR__, 2).'/build/sandbox/run-in-sandbox.ps1'),
            'the clean-room gate must not ask for a browser',
        );
    }
}
