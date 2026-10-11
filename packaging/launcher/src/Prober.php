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
/**
 * Asks questions about the machine, over the network and the filesystem.
 *
 * ## Why this is not final
 *
 * So a test can subclass it and say what the machine answers. Every method here does real I/O, and
 * a health test that cannot control its answers is a health test that reports whatever this
 * machine happens to be doing at the time. That already happened: a suite asserting that a
 * *missing* worker is reported as missing found the real workers running and failed for reasons
 * that had nothing to do with the code under test.
 *
 * Extending is preferred over a production seam for that, because a seam in the class under test
 * is a permanent affordance for tests and this is not: the base class stays exactly what it is and
 * only a test knows it was subclassed.
 */
class Prober
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
    /**
     * Whether Suwayomi's GraphQL endpoint answers, without waiting.
     *
     * A single attempt rather than a wait, because `papyrus health` must answer in a second and a
     * health command that takes thirty seconds gets skipped by the reader who needs it.
     */
    public function suwayomiAnswers(int $port, float $timeoutSeconds = 3.0): bool
    {
        return $this->graphql($port, $timeoutSeconds) !== null;
    }

    /**
     * Suwayomi's reported version, or an empty string when it will not say.
     *
     * Reported rather than assumed, because "which Suwayomi is this" is one of the first questions
     * asked about a bug and guessing it from the jar's name is a guess.
     */
    public function suwayomiVersion(int $port, float $timeoutSeconds = 3.0): string
    {
        $decoded = $this->graphql($port, $timeoutSeconds);

        $version = $decoded['data']['aboutServer']['version'] ?? null;

        return is_string($version) ? $version : '';
    }

    /**
     * One GraphQL round trip, or null when Suwayomi does not answer.
     *
     * @return array<string, mixed>|null
     */
    private function graphql(int $port, float $timeoutSeconds): ?array
    {
        $body = json_encode(['query' => '{ aboutServer { version } }']);

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $body,
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
        ]]);

        $response = @file_get_contents(sprintf('http://127.0.0.1:%d/api/graphql', $port), false, $context);

        if (! is_string($response) || $response === '') {
            return null;
        }

        $decoded = json_decode($response, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * What the application's own readiness endpoint says.
     *
     * Lives here rather than on the Supervisor because asking the app how it is is the same kind
     * of question as asking Suwayomi whether it is answering, and putting it on the Supervisor put
     * an HTTP request inside the class that owns process lifecycle. It also made the all healthy
     * case untestable: a test could control every answer except this one, because this one reached
     * past the Prober that was being stubbed.
     *
     * Returns null when nothing is listening, which is different from a 503, which is the app
     * answering and refusing. That distinction is the whole reason the status is returned rather
     * than a boolean: a reader told "the web server is not listening" looks at the launcher, and
     * one told "the app says it is not ready" looks at the app.
     *
     * @return array{status: int, checks: array{key: bool, database: bool, pending_migrations: int}}|null
     */
    public function readiness(int $port): ?array
    {
        if (! $this->isPortTaken($port)) {
            return null;
        }

        $context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);

        $response = @file_get_contents(sprintf('http://127.0.0.1:%d/ready', $port), false, $context);

        if (! is_string($response)) {
            return null;
        }

        $status = 0;

        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $matches) === 1) {
            $status = (int) $matches[1];
        }

        $decoded = json_decode($response, true);

        if (! is_array($decoded)) {
            return ['status' => $status, 'checks' => ['key' => false, 'database' => false, 'pending_migrations' => -1]];
        }

        return [
            'status' => $status,
            'checks' => [
                'key' => (bool) ($decoded['checks']['key'] ?? false),
                'database' => (bool) ($decoded['checks']['database'] ?? false),
                'pending_migrations' => (int) ($decoded['checks']['pending_migrations'] ?? -1),
            ],
        ];
    }

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
