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

        /*
         * A live pid whose start time cannot be read is held, not stolen.
         *
         * This is the conservative answer, and it is deliberate. Reading another process's start time
         * can fail for reasons that say nothing about the process: PowerShell unavailable, a policy
         * blocking CIM, or the read racing the winner's own start. Answering "not the same process"
         * from a failed read unlinks a live launcher's lock, which is the dangerous direction: two
         * launchers then supervise one install and the loser's cleanup stops the winner's children.
         *
         * A dead pid never reaches this point, because liveness is checked first. A recycled pid is
         * still caught below whenever its start time is readable and more than the tolerance away.
         */
        if ($actual === null) {
            return true;
        }

        /*
         * One whole second of tolerance, because both sources report whole seconds and a process
         * starting at a boundary can land on either side of it.
         *
         * `REQUEST_TIME_FLOAT` carries microseconds and is what a lock records; Windows CIM
         * `CreationDate` is reduced to a whole second when it is parsed, and `ps -o lstart=` on Unix
         * is whole seconds to begin with. The process is created before PHP's request starts, so a
         * child created at `...408.98` can record `...409.00` in its lock while a reader sees
         * `...408.00`. Measured in the concurrency test, with two winners:
         *
         *     lock startedAt   : 1793658409.000777
         *     actual startTime : 1793658408.000000
         *     difference       : 1.000777 seconds
         *
         * Both readings name the same process, and the second winner took the lock because the first
         * winner's lock looked stale. The comparison therefore works in the units both sources
         * actually report: whole seconds, with a tolerance of one. The earlier exact comparison of
         * those whole seconds rejected the boundary case above.
         *
         * One second is safe because a recycled pid starts much later than the start time the old lock
         * recorded; it does not appear in the same or the next whole second as the process that wrote
         * the lock. The `startedAt` is truncated rather than rounded, because both real sources
         * truncate: CIM's `strtotime` drops the fraction and `ps` reports a whole second. Rounding a
         * `...59.9` reading up into the next second would widen the window for no gain.
         */
        return abs((int) $actual - (int) (float) $startedAt) <= 1;
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
     * `ps -o lstart=` on anything Unix. On Windows, CIM through `Get-CimInstance`, which is the
     * supported route now that `wmic` is gone.
     *
     * ## Why this used to return null on Windows, and what that cost
     *
     * The comment said "the only pid this needs to re-read is its own", which was the reasoning at the
     * time and has since turned out to be wrong in a way that disabled the instance lock entirely.
     *
     * `isSameProcess()` returns `$actual !== null && $actual === $startedAt`. With null it returns
     * false for **every pid that is not this process**. So `InstanceLock::isHeld()` was false for
     * every lock the launcher had not written itself, so `acquire()` never refused, so the lock has
     * never once blocked anything on Windows.
     *
     * Measured, against the reader's own install, with a launcher alive and holding the lock:
     *
     *     lock pid         : 16308          isAlive          : true
     *     lock startedAt   : 1792009245.444885
     *     actual startTime : NULL           isSameProcess    : false
     *     isHeld           : false          holderPid        : NULL
     *     describe         : pid 16308 started ... (not alive, the lock is stale)
     *
     * The lock said the lock was stale, naming a process that was running, and the whole chain
     * believed it. Two `papyrus start` runs in a row both succeeded and the second took the lock, which
     * is the first half of a race that ends with `snapshot` stopping an install another launcher is
     * supervising.
     *
     * ## Why this is a single CIM query and not a poll
     *
     * Because it is only reached for a pid this process did not write, which is the lock holder or a
     * pid a reader is asking about. That is not every second: `MangaSnapshot` polls liveness, not
     * identity, and liveness still goes through the cheap `tasklist` path.
     *
     * The read is memoised per pid, and only for a process found alive. A dead pid's start time
     * changes the moment its number is recycled, and caching that would make the cache actively wrong.
     */
    private static function readForeignStartTime(int $pid): ?string
    {
        if (isset(self::$ownStartTime[$pid])) {
            return self::$ownStartTime[$pid];
        }

        $output = PHP_OS_FAMILY === 'Windows'
            ? @shell_exec(sprintf(
                '"%s" -NoProfile -NonInteractive -Command "(Get-CimInstance Win32_Process -Filter \'ProcessId=%d\').CreationDate.ToUniversalTime().ToString(\'o\')" 2>NUL',
                SystemTools::powershell(),
                $pid,
            ))
            : @shell_exec(sprintf('ps -o lstart= -p %d 2>/dev/null', $pid));

        if (! is_string($output)) {
            return null;
        }

        $trimmed = trim($output);

        if ($trimmed === '') {
            return null;
        }

        /*
         * Both formats are reduced to a float of seconds, because the two platforms do not agree on
         * how to write one and a string comparison across them is a comparison of formatting.
         *
         * `toString('o')` gives an ISO 8601 instant with a `Z`; `ps -o lstart=` gives
         * `Tue Oct 14 19:59:31 2026`. `strtotime` reads the first natively and the second correctly,
         * and both then become the same number. The lock stores that number as a string, which is
         * what `readOwnStartTime()` already produces, so a lock written by a launcher and read back by
         * another are comparable.
         */
        $at = strtotime($trimmed);

        if ($at === false) {
            // An unparseable answer is still better than none when it came from ps, whose format is
            // a fixed shape. Returning it raw keeps the comparison working on Unix, where the writer
            // and the reader both produce it.
            return PHP_OS_FAMILY === 'Windows' ? null : $trimmed;
        }

        $seconds = number_format((float) $at, 6, '.', '');

        if (self::isAlive($pid)) {
            self::$ownStartTime[$pid] = $seconds;
        }

        return $seconds;
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

        $output = @shell_exec(sprintf('"%s" /FI "PID eq %d" /NH 2>NUL', SystemTools::tasklist(), $pid));

        if (! is_string($output)) {
            return true;
        }

        return stripos($output, (string) $pid) !== false;
    }
}
