<?php

namespace Papyrus\Launcher;

/**
 * Asking the operating system whether a process is alive, and which one it is.
 *
 * ## Why this is a class and not two inline calls
 *
 * Because it is the only part of the launcher that is genuinely platform dependent, and
 * because getting it wrong is silent. Both of these return "no" for a process that is
 * definitely running:
 *
 * ```php
 * function_exists('posix_kill') && posix_kill($pid, 0)   // posix is not on Windows
 * file_exists("/proc/$pid")                               // not on Windows, and not on macOS
 * ```
 *
 * and the second returns "yes" for a pid that has since been recycled, which is worse.
 *
 * ## What a start time is for
 *
 * Identity. A pid answers "does a process with this number exist", which after a reboot or a
 * busy afternoon is not the question. The start time answers "is it the same process", and
 * comparing the two is what turns a pid into an identity.
 *
 * ## Where the start time comes from on Windows
 *
 * `wmic` was the answer for twenty years and is gone. The supported route is CIM through
 * `Get-CimInstance`, which is a PowerShell invocation, and invoking PowerShell once per
 * liveness check would be absurd for something polled every second.
 *
 * So the cost is paid once, at launch, and cached for the life of the process: this class
 * memoises the current process's own start time, which is all the identity check actually
 * needs, because a live launcher's pid is always its own.
 */
final class ProcessIdentity
{
    /** @var array<int, string> */
    private static array $ownStartTime = [];

    /** EPERM. Exists on every platform this runs on, and spelled out so the branch above is self describing. */
    private const EPERM = 1;

    /**
     * Whether the pid is alive and, when a start time is given, is the same process.
     *
     * A null or empty start time weakens the check to "the pid exists", which is the best
     * that can be done for a lock written by an older version or by hand, and is why the lock
     * always writes one.
     */
    public static function isSameProcess(int $pid, ?string $startedAt = null): bool
    {
        if ($pid <= 0 || ! self::isAlive($pid)) {
            return false;
        }

        if ($startedAt === null || $startedAt === '') {
            return true;
        }

        $actual = self::startTime($pid);

        return $actual !== null && $actual === $startedAt;
    }

    /**
     * Whether a process with this number exists.
     *
     * `posix_kill` with signal 0 is the cheap test on anything Unix. It does not signal
     * anything, it only checks existence and permission, and it returns false for both "gone"
     * and "not yours". EPERM is the one errno that means the process is real, so it is read
     * rather than treated as absence: a launcher holding a pid for a process started by another
     * user is still looking at a live process, and saying otherwise would let it start a second
     * one.
     */
    public static function isAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            if (@posix_kill($pid, 0)) {
                return true;
            }

            $errno = function_exists('posix_get_last_error') ? posix_get_last_error() : 0;

            return $errno === self::EPERM;
        }

        return self::isAliveOnWindows($pid);
    }

    /**
     * The process start time, as a stable string, or null when it cannot be read.
     *
     * Memoised for this process's own pid only, because that is the one asked about on every
     * liveness poll and it cannot change.
     */
    public static function startTime(int $pid): ?string
    {
        if (isset(self::$ownStartTime[$pid])) {
            return self::$ownStartTime[$pid];
        }

        if ($pid === getmypid()) {
            $started = self::readOwnStartTime();

            if ($started !== null) {
                self::$ownStartTime[$pid] = $started;
            }

            return $started;
        }

        return self::readForeignStartTime($pid);
    }

    /**
     * This process's start time.
     *
     * Cheap and dependency free: PHP has been recording it in microseconds since 7.0, and it
     * is the same clock on both platforms.
     */
    private static function readOwnStartTime(): ?string
    {
        if (! isset($_SERVER['REQUEST_TIME_FLOAT'])) {
            return null;
        }

        return number_format((float) $_SERVER['REQUEST_TIME_FLOAT'], 6, '.', '');
    }

    /**
     * Another process's start time.
     *
     * `ps -o lstart=` on anything Unix. On Windows this is genuinely awkward, which is the
     * honest reason the lock memoises its own: the only pid this needs to re-read is its own.
     */
    private static function readForeignStartTime(int $pid): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return null;
        }

        $output = @shell_exec(sprintf('ps -o lstart= -p %d 2>/dev/null', $pid));

        if (! is_string($output)) {
            return null;
        }

        $trimmed = trim($output);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Liveness on Windows, where posix_kill does not exist.
     *
     * `tasklist` is the only thing available without a COM or WMI dependency, and it is slow
     * enough that calling it on every poll would be worse than the problem it solves. The
     * memoisation above means it is called at most once for this process.
     */
    private static function isAliveOnWindows(int $pid): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            // Not Windows and no posix: a PHP build without either. Assume alive, because
            // assuming dead here would refuse to start on an exotic build.
            return true;
        }

        $output = @shell_exec(sprintf('tasklist /FI "PID eq %d" /NH 2>NUL', $pid));

        if (! is_string($output)) {
            return true;
        }

        return stripos($output, (string) $pid) !== false;
    }
}
