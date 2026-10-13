<?php

declare(strict_types=1);

namespace Papyrus\Launcher;

/**
 * Opens a URL in the reader's default browser, and is allowed to fail.
 *
 * ## Why this exists
 *
 * Because the launcher knows exactly which port it bound to, and until now it printed that port and
 * relied on a reader to act on it. Which means a reader who guessed wrong got `ERR_CONNECTION_REFUSED`
 * from a perfectly working install, and had no way to tell a broken app from a wrong address. That is
 * not hypothetical: it has now happened twice on this project in one day, first with `papyrus.test`,
 * which is a host-only name that cannot resolve in a sandbox, and then with `8402`, which is the port
 * *the clean-room gate* forces rather than the default of 8000. Both times the install was healthy and
 * the address was wrong.
 *
 * Removing the guess is worth more than any amount of better wording about which port to use, because
 * the port is not a constant. It is 8000 by default, 8402 under the gate, and anything the reader sets.
 * A document can only state the common case. This removes the question.
 *
 * ## Why `start` and not `explorer`
 *
 * `start` is what Windows itself uses for a shell open, and it is the one that respects the default
 * browser rather than picking a program. It needs `cmd /c` in front of it to work from PHP, and it
 * needs a title argument, because a quoted string in the first position of `start` is read as the
 * window title rather than as the command. Getting that wrong opens a console window with the URL in
 * its title bar and nothing else, which looks like it worked.
 *
 * ## Why every failure is swallowed
 *
 * Because this runs *after* the app is confirmed working, and a browser that will not open is not a
 * reason to take a running application down or to print a scary line. A reader whose default browser is
 * broken still has the URL printed on screen one line above, which is the whole fallback.
 *
 * ## Why success here does not mean a window opened, and is not reported as though it does
 *
 * Measured, from a process with no interactive desktop: `cmd /c start "" http://127.0.0.1:8000/login`
 * ran, returned a clean exit code, and left the browser process count at zero before and after.
 * There is no desktop to open a window on, and the shell does not say so.
 *
 * So this returns whether it **asked**, never whether a window appeared, because the second thing
 * cannot be known without inventing it. `papyrus.php` words the message to match: "Asked your browser
 * to open it", and not "Opened your browser", which would be a confident wrong answer to the one
 * reader who most needed the truth.
 *
 * For a reader double-clicking the shortcut there *is* a desktop, and it does open. The distinction
 * is between the shape of the thing working and the shape of it working everywhere.
 *
 * ## Why it is a class rather than four lines in the script
 *
 * Because the alternative is untestable: a test that opens a browser either does that or does not, and
 * a suite must not. The opener is therefore a seam, the same one `ProcessFinder` and `Prober` use, and
 * a test asserts on the command line that *would* have run.
 */
final class BrowserLauncher
{
    /**
     * @param  (callable(string): void)|null  $opener  Runs the command. For tests, and the reason
     *                                                 this is a class rather than an inline `exec`.
     */
    public function __construct(private $opener = null) {}

    /**
     * Open a URL. Returns whether it was asked to, not whether a window appeared.
     *
     * @return bool false when the URL is not one this would open, or when opening it failed
     */
    public function open(string $url): bool
    {
        if (! $this->isSafeUrl($url)) {
            return false;
        }

        try {
            if ($this->opener !== null) {
                ($this->opener)($this->commandFor($url));

                return true;
            }

            // `''` is the window title `start` would otherwise read the URL as. Two spaces, because
            // Windows wants a title and does not want it quoted.
            $command = sprintf('cmd /c start "" %s', $url);

            @exec($command, $ignored, $status);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * The command this would run, which is what a test asserts on.
     *
     * Public because that is the point of it: the assertion is on the exact string, since the failure
     * mode being guarded against is a command that is subtly wrong and silently opens nothing.
     */
    public function commandFor(string $url): string
    {
        return sprintf('cmd /c start "" %s', $url);
    }

    /**
     * Whether this is a URL this would hand to a shell.
     *
     * ## Why a loopback URL is the only one accepted
     *
     * Because this is only ever called with the address the launcher just bound, and the cost of
     * accepting anything else is that a value reaching this class from an environment variable or a
     * command line becomes a shell command. Papyrus serves on 127.0.0.1 and nothing else; if that
     * ever changes, the check changes with it and the failure is a refused open rather than a launch.
     *
     * The port is bounded as well, for the same reason and because a port is the one part of a URL
     * that is not self-evidently safe to interpolate.
     */
    private function isSafeUrl(string $url): bool
    {
        return preg_match('#^http://127\.0\.0\.1:\d{2,5}\z#', $url) === 1;
    }
}
