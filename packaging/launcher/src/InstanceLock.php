<?php

namespace Papyrus\Launcher;

/**
 * A lock file that says which launcher owns this install, and whether it is still alive.
 *
 * ## Why this exists rather than a check on the port
 *
 * Because the port answers a different question. It says something is listening, not that it
 * is the thing this launcher started. On this machine, Suwayomi runs from a shortcut started
 * by hand and a launcher that only checked "is 4567 open" would decide it was already running,
 * adopt it, and then have no handle on it to stop.
 *
 * Worse, the same idea applied to process names is actively wrong here. Trap 217 records two
 * `java.exe` processes with identical command lines that are a launcher shim and its child, one
 * server between them. Any check that reads a process table to decide "is Suwayomi up" gets a
 * false answer in one direction or the other.
 *
 * So identity comes from a file this process writes and this process owns.
 *
 * ## Why the start time is in there
 *
 * Because a pid alone is not an identity. Windows and Linux both recycle pids, and on a
 * long lived machine a launcher that crashed leaves a lock behind that names a pid which will
 * eventually belong to something else. If the reader then relaunches and is told "already
 * running", or is allowed to proceed because that pid exists, the failure is a wrong answer
 * delivered confidently.
 *
 * Storing pid plus the process start time makes the check falsifiable: if the pid is alive but
 * its start time differs, it is a different process that happens to have the same number, and
 * the lock is stale. The cost is one extra field and a platform call to read it.
 *
 * ## What a stale lock does
 *
 * Gets reclaimed. A crashed launcher must not make an install permanently unstartable, and
 * refusing to start because of a stale file is a support call every time it happens.
 */
final class InstanceLock
{
    /**
     * Written as JSON rather than as "pid on line one".
     *
     * Because a second line that is not read today is a second line nobody will remember to
     * parse tomorrow, and this file is exactly the kind of thing that gets `explode()`d into
     * an int by a future reader in a hurry.
     */
    private const FORMAT = 'json';

    /**
     * How long an unreadable lock must sit before it is treated as abandoned.
     *
     * A lock that cannot be parsed is either a crashed launcher or a launcher part way through
     * writing its own lock. Removing the second is how two launchers both win, and the window is
     * short enough that a few seconds of patience costs nothing a reader will notice. A lock
     * naming a live process is never removed by age; this threshold is only for one that cannot
     * be identified at all.
     */
    private const UNREADABLE_GRACE_SECONDS = 10;

    public function __construct(private readonly string $path) {}

    /**
     * Whether a live launcher holds this lock.
     *
     * Reads the lock and asks the operating system whether the process it names is the one
     * that wrote it. Returns false for an absent, unreadable, malformed or dead lock, because
     * every one of those means the same thing to the caller: there is nobody to talk to.
     */
    public function isHeld(): bool
    {
        $owner = $this->readOwner();

        if ($owner === null) {
            return false;
        }

        return ProcessIdentity::isSameProcess($owner['pid'], $owner['startedAt']);
    }

    /**
     * The pid of the live holder, or null.
     *
     * Only returned when the process is verifiably the one that wrote the lock. A pid read
     * out of a file that belongs to somebody else is worse than no pid at all, because the
     * caller would use it to kill a stranger's process.
     */
    public function holderPid(): ?int
    {
        if (! $this->isHeld()) {
            return null;
        }

        return $this->readOwner()['pid'];
    }

    /**
     * Take the lock for this process, or report that somebody else has it.
     *
     * ## Why exclusive creation rather than check then rename
     *
     * `isHeld()` followed by a temporary file and `rename()` is two decisions where one is
     * needed. On Windows PHP's `rename()` replaces an existing file, so two launchers can both
     * pass the check and both rename their temporary file over the lock. Both then believe they
     * own the install, the second fails on a port or on Suwayomi, and its catch block calls
     * `stopAll()`, which stops the processes the first launcher just started.
     *
     * `fopen($path, 'x')` is one decision made by the operating system: it creates the file only
     * if it does not exist, and only one caller wins. The payload is written into that file
     * rather than into a temporary one, so there is no second rename to lose.
     *
     * ## Why stale reclamation holds a guard
     *
     * Two launchers can both decide that an old lock is stale. Without a guard they can both
     * unlink it, one can create the new lock, and the other can then unlink that new lock because
     * it decided before the new file existed. A separate `flock` on a guard file serialises only
     * the reclamation, so whichever process loses sees the winner's lock and refuses. A launcher
     * whose own lock is fine never touches the guard.
     */
    public function acquire(): bool
    {
        // getmypid() answers false only when the process has no id, which PHP cannot give
        // it, but a false reaching startTime() is a TypeError rather than a lock file that
        // says nothing. Zero is the documented failure value, and a lock with pid 0 is
        // refused by the reader rather than accepted as a live holder.
        $pid = getmypid();
        $pid = $pid === false ? 0 : $pid;

        $payload = json_encode([
            'format' => self::FORMAT,
            'pid' => $pid,
            'startedAt' => ProcessIdentity::startTime($pid),
            'acquiredAt' => gmdate('c'),
        ], JSON_PRETTY_PRINT);

        if ($payload === false) {
            return false;
        }

        if ($this->createExclusively($payload)) {
            return true;
        }

        if ($this->isHeld()) {
            return false;
        }

        return $this->reclaim($payload);
    }

    /**
     * Create the lock only if it does not exist, and write the whole payload into it.
     */
    private function createExclusively(string $payload): bool
    {
        $handle = @fopen($this->path, 'x');

        if ($handle === false) {
            return false;
        }

        $written = fwrite($handle, $payload);

        fflush($handle);
        fclose($handle);

        if ($written === false || $written !== strlen($payload)) {
            @unlink($this->path);

            return false;
        }

        return true;
    }

    /**
     * Replace a lock that is demonstrably not held, under a guard that only one process takes.
     */
    private function reclaim(string $payload): bool
    {
        $guard = @fopen($this->path.'.reclaim', 'c');

        if ($guard === false) {
            return false;
        }

        // Non-blocking: another launcher is already reclaiming, and waiting on it would delay the
        // message this one is about to print. Losing the guard means losing the race.
        if (! flock($guard, LOCK_EX | LOCK_NB)) {
            fclose($guard);

            return false;
        }

        try {
            // Re-read under the guard. The lock may have been taken between the caller's check
            // and this point, and a live lock must not be removed.
            if ($this->isHeld()) {
                return false;
            }

            if (! $this->isStale()) {
                return false;
            }

            @unlink($this->path);

            return $this->createExclusively($payload);
        } finally {
            flock($guard, LOCK_UN);
            fclose($guard);
            @unlink($this->path.'.reclaim');
        }
    }

    /**
     * Whether an existing lock has no live owner.
     *
     * A readable owner is compared with the process table, so a dead pid and a recycled pid are
     * both stale. An unreadable file is only stale once it is older than the grace period, because
     * a launcher that has just created the file may not have written it yet.
     */
    private function isStale(): bool
    {
        $owner = $this->readOwner();

        if ($owner !== null) {
            return ! ProcessIdentity::isSameProcess($owner['pid'], $owner['startedAt']);
        }

        $modified = @filemtime($this->path);

        return $modified !== false && (time() - $modified) >= self::UNREADABLE_GRACE_SECONDS;
    }

    /**
     * Release the lock, but only if this process is the holder.
     *
     * The check is not defensive programming, it is the whole point. A `stop` that arrives
     * after a crash and a restart would otherwise delete the *new* launcher's lock, and two
     * launchers would both believe they own the install.
     */
    public function release(): void
    {
        if (! $this->isHeld() || $this->holderPid() !== getmypid()) {
            return;
        }

        @unlink($this->path);
    }

    /**
     * The recorded owner, without asking whether it is alive.
     *
     * Separate from {@see isHeld()} because the two answers are used differently. "Who holds
     * this" is a diagnostic worth showing on a stale lock, and it is the number a reader needs
     * in order to find out what is in the way.
     */
    public function describe(): string
    {
        $owner = $this->readOwner();

        if ($owner === null) {
            return 'no lock, or one this launcher cannot read';
        }

        $alive = $this->isHeld();

        return sprintf(
            'pid %d started %s (%s)',
            $owner['pid'],
            $owner['startedAt'] === '' ? 'at an unknown time' : $owner['startedAt'],
            $alive ? 'alive' : 'not alive, the lock is stale',
        );
    }

    /**
     * @return array{pid: int, startedAt: string}|null
     */
    private function readOwner(): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }

        $raw = @file_get_contents($this->path);

        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        // A lock naming another format was not written by this launcher and must not be
        // interpreted by it. Guessing at a pid from an unknown layout is how a launcher kills
        // the wrong process.
        if (! is_array($decoded) || ($decoded['format'] ?? null) !== self::FORMAT) {
            return null;
        }

        if (! isset($decoded['pid']) || ! is_int($decoded['pid'])) {
            return null;
        }

        return [
            'pid' => $decoded['pid'],
            'startedAt' => is_string($decoded['startedAt'] ?? null) ? $decoded['startedAt'] : '',
        ];
    }
}
