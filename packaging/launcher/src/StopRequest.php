<?php

declare(strict_types=1);

namespace Papyrus\Launcher;

/**
 * The marker a `stop` command leaves for the `start` process that is holding the terminal.
 *
 * ## The wrong sentence this exists to prevent
 *
 * `start` holds the terminal and watches the four children. When one of them disappears it
 * cannot tell an intentional `stop` from a crash, so it did what it does for a crash: stopped
 * the rest and exited 1. `Papyrus.cmd` turns any non-zero exit into "Papyrus did not start",
 * which is false for an app that served pages for an hour and was then stopped on purpose.
 *
 * The stop command writes this marker before it stops anything. The start process consumes it
 * when a child disappears and exits 0 with a sentence that says what happened. A crash leaves
 * no marker, so the failure signal and the failure message stay.
 *
 * ## Why a file rather than a signal
 *
 * The stop command is a separate PHP process on Windows. It has no handle on the start window
 * and no console signal to send it. The data directory is the one place both processes already
 * agree on, and it is where the lock already lives.
 */
final class StopRequest
{
    private const FILE = 'stop-requested';

    public static function path(string $dataDirectory): string
    {
        return $dataDirectory.DIRECTORY_SEPARATOR.self::FILE;
    }

    /**
     * Ask the running start process to treat the next child stop as intentional.
     *
     * Failure to write is not allowed to stop the stop itself. The reader asked for the app to
     * be stopped, and an unwritable marker must not leave it running; the worst case is the old
     * misleading sentence, which is still a stopped app.
     */
    public static function request(string $dataDirectory): void
    {
        @file_put_contents(self::path($dataDirectory), (string) getmypid());
    }

    /**
     * Read and remove the request, so one stop cannot excuse a later crash.
     */
    public static function consume(string $dataDirectory): bool
    {
        $path = self::path($dataDirectory);

        if (! is_file($path)) {
            return false;
        }

        @unlink($path);

        return true;
    }

    /**
     * Remove a request left behind by a stop that ran when no start process was watching.
     */
    public static function clear(string $dataDirectory): void
    {
        @unlink(self::path($dataDirectory));
    }
}
