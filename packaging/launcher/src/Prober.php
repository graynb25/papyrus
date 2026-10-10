<?php

namespace Papyrus\Launcher;

/**
 * Waits for something to become true, and says what it was waiting for when it does not.
 *
 * ## Why the failure message matters more than the polling
 *
 * Because the alternative is the reader staring at a launcher that never opens a browser and
 * never says why. Every one of these waits has a specific answer when it times out, and the
 * answer is different every time: Suwayomi not answering is a different problem from the
 * database being locked, and both look identical from outside if you only print "timed out".
 *
 * ## Why the interval backs off
 *
 * Suwayomi takes tens of seconds to come up and is doing genuine work while it does. Polling
 * it every 50ms for that long is thousands of HTTP requests against a server that is trying to
 * start, on a machine that is busy starting it. The first attempt is quick because most
 * failures are immediate, and it slows to a floor because most successes are slow.
 */
final class Prober
{
    private const FASTEST_MS = 100;

    private const SLOWEST_MS = 1_000;

    /**
     * Poll until the probe passes or the deadline passes.
     *
     * @param  callable(): bool  $probe
     * @param  callable(string): void|null  $onAttempt  Told each attempt, for progress output.
     * @return bool Whether it passed.
     */
    public function waitFor(
        callable $probe,
        int $timeoutSeconds,
        string $describe,
        ?callable $onAttempt = null,
    ): bool {
        $deadline = microtime(true) + $timeoutSeconds;
        $interval = self::FASTEST_MS;
        $attempts = 0;

        while (microtime(true) < $deadline) {
            $attempts++;

            if ($onAttempt !== null) {
                $onAttempt(sprintf('waiting for %s, attempt %d', $describe, $attempts));
            }

            try {
                if ($probe()) {
                    return true;
                }
            } catch (\Throwable) {
                /*
                 * A probe that throws means "not ready yet", not "broken". The distinction
                 * matters: a Suwayomi that answers a refused connection for thirty seconds and
                 * then serves normally is the normal case, and treating the refusal as fatal
                 * would fail every cold start.
                 */
            }

            usleep($interval * 1_000);

            $interval = (int) min($interval * 1.6, self::SLOWEST_MS);
        }

        return false;
    }

    /**
     * Is something already listening on this port.
     *
     * Used to tell "my Suwayomi is starting" from "something else already owns 4567", which
     * are the same silent failure from the outside and opposite from here. The second means the
     * launcher should stop and say who holds it rather than wait forever.
     */
    public function isPortTaken(int $port): bool
    {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.4);

        if ($probe === false) {
            return false;
        }

        fclose($probe);

        return true;
    }
}
