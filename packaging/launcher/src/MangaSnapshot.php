<?php

namespace Papyrus\Launcher;

use Throwable;

/**
 * Copy the manga database, having proved that nothing is writing it.
 *
 * ## Why this class exists rather than a `copy()` in the snapshot command
 *
 * Because the copy is the one step in the whole backup that depends on a process being gone, and the
 * stop that precedes it cannot be trusted to have done that.
 *
 * Measured on this machine, four stop and start cycles against the reader's own install:
 *
 *     suwayomi: killed                   (pid 29680, found rather than started here)
 *     suwayomi: could not be stopped     (pid 27384, found rather than started here)
 *     web: could not be stopped          (pid 30060, found rather than started here)
 *
 * And the reason the graceful attempt never works for Suwayomi at all:
 *
 *     taskkill /PID 8656 /T        (no /F)
 *       exit 128
 *       SUCCESS: Sent termination signal to PID 18984, child of 8656.
 *       ERROR:   PID 5984  could not be terminated. Reason: can only be terminated forcefully.
 *       ERROR:   PID 17844 could not be terminated. Reason: can only be terminated forcefully.
 *       ERROR:   PID 8656  could not be terminated. Reason: child processes were still running.
 *
 * The JVM spawns children that can only be force-killed, so the polite pass always fails for it and
 * kills some of them on the way. The launcher exits zero either way.
 *
 * ## Why the check is process liveness and not a file lock
 *
 * This is the part that was going to be built wrong, so it is written down rather than left as a
 * comment that reads like an excuse.
 *
 * The obvious check is to try taking an exclusive handle on the file. Measured, while Suwayomi was
 * running and writing:
 *
 *     File.Open(path, Open, Read, ReadWrite)   -> succeeded
 *     File.Open(path, Open, ReadWrite, None)   -> succeeded
 *
 * **An exclusive handle with no sharing is obtainable from a database a live JVM is writing.** H2 maps
 * and closes around its writes rather than holding a lock, so a lock probe reports "safe" on a file
 * that is being changed. A check like that is the worst kind: it reports a specific verdict, it is
 * always true, and it would have shipped a backup that copies a moving file while looking careful.
 *
 * So the question asked is the boring one, and the only one that can be answered: **is the process
 * still alive?** That is a fact about the world rather than about the filesystem.
 *
 * ## What the pid argument means, and why it is nullable
 *
 * `$wasRunningPid` is the pid Suwayomi was running under when the caller looked, or **null when it
 * was not running at all**.
 *
 * Null is not the absence of an answer. It is the strongest answer available: nothing is running, so
 * nothing is writing the file, so copying it cannot catch it mid-write. There is no process to check
 * and no wait to do.
 *
 * The first version took `int $suwayomiPid` and refused anything at or below zero, saying "No process
 * id was given for Suwayomi, so nothing has proved the manga database is not being written." That
 * refused the safest case there is. The obvious moment to copy the manga database is when Suwayomi
 * is already stopped, and that moment would have been refused, leaving a reader to start Suwayomi
 * purely so that it could be stopped again to be copied.
 *
 * That is a check that could only fail rather than one that could not, which is the same defect as a
 * check that can never fail: both report a verdict about something other than the thing being
 * checked. This one refused on a fact that argues the other way.
 *
 * So the type says it. `?int` cannot express "running, but I do not know its pid", which is the one
 * state that genuinely must be refused, because that is the state where a copy would be unsafe. A
 * caller passing `0` or below has not done the lookup, and the refusal says so and names the fix.
 *
 * ## What it does not do
 *
 * It does not decide whether Suwayomi was stopped. It is told. It checks, refuses if the answer is no,
 * and copies. Separating those two is what makes the refusal testable, and no test here starts a real
 * process.
 */
final class MangaSnapshot
{
    /** @var (callable(int): bool)|null Injected by the tests; null means ask the operating system. */
    private $isAlive;

    /**
     * @param  (callable(int): bool)|null  $isAlive  Overrides how liveness is answered, so that no
     *                                               test has to start a real process in order to test a
     *                                               refusal.
     */
    public function __construct(
        private readonly FileMover $mover = new LocalFileMover,
        ?callable $isAlive = null,
    ) {
        $this->isAlive = $isAlive ?? static fn (int $pid): bool => ProcessIdentity::isAlive($pid);
    }

    /**
     * Copy the manga database to `$target`, refusing unless nothing is writing it.
     *
     * @param  int|null  $wasRunningPid  The pid Suwayomi was running under, or null when it was not
     *                                   running at all, which is proof enough on its own.
     * @param  int  $settleSeconds  How long to keep re-checking once the process is first seen gone.
     *
     * @throws RestoreRefused The process is still alive, or the copy did not arrive intact.
     */
    public function capture(
        string $source,
        string $target,
        ?int $wasRunningPid,
        int $settleSeconds = 2,
        ?callable $note = null,
    ): void {
        $note ??= static fn (string $line): null => null;

        if (! is_file($source)) {
            throw (new RestoreRefused(sprintf(
                'There is no manga database at %s. The snapshot cannot include the half that holds '
                .'your library.',
                $source,
            )))->attempted($source);
        }

        $this->waitUntilGone($wasRunningPid, $settleSeconds, $note);

        $note('  copying '.basename($source).' ('.number_format((int) filesize($source)).' bytes)');

        $this->mover->copy($source, $target);

        /*
         * Is it there at all, before asking whether the bytes agree.
         *
         * The digest comparison below is the real check, and it is the reason this class exists rather
         * than a `copy()`. But it cannot answer this question on its own: two files that are both
         * absent hash identically, so a copy that silently produced nothing passes the digest
         * comparison and the snapshot goes on to write a manifest naming a file that does not exist.
         * That restores into an empty library with nothing saying why, which is the exact failure a
         * pair exists to prevent, reached by a shorter road.
         *
         * Found by a mutation rather than by reading: removing the "are both halves present" check in
         * `SnapshotCommand::writeManifest()` survived every test, because every test had arranged for
         * the copy to either work or fail before that point. This is the check that closes it, and it
         * is here rather than there because this is where the copy happens and this is the only place
         * that knows.
         */
        if (! is_file($target)) {
            throw (new RestoreRefused(
                'The manga database was reported as copied but nothing arrived, so there is no manga '
                .'half and no pair can be recorded. Nothing has been recorded as backed up.',
            ))->attempted($target);
        }

        /*
         * Verified against the source, because a copy of a file that is still being written opens and
         * is wrong.
         */
        if ($this->mover->digest($source) !== $this->mover->digest($target)) {
            @unlink($target);

            throw (new RestoreRefused(
                'The copy of the manga database did not arrive intact, so it was deleted rather than '
                .'left in place. Nothing has been recorded as backed up.',
            ))->attempted($target);
        }

        $note('  done, and the copy matches the original byte for byte');
    }

    /**
     * Keep asking until the process is gone, then wait a moment more.
     *
     * Nothing to ask about when Suwayomi was not running to begin with, which returns immediately.
     *
     * The extra wait is measurable rather than superstitious. `taskkill` returning does not mean the
     * process has finished exiting, and in the cycles above the launcher reported a process stopped
     * and a port was still held a moment later.
     *
     * @throws RestoreRefused
     */
    private function waitUntilGone(?int $pid, int $settleSeconds, callable $note): void
    {
        if ($pid === null) {
            $note('  Suwayomi was not running, so nothing was writing this file');

            return;
        }

        if ($pid <= 0) {
            throw (new RestoreRefused(
                'No usable process id was given for Suwayomi. Pass null when Suwayomi is not running, '
                .'which is safe to copy, rather than a number that is not a process.'
            ))->attempted('pid');
        }

        $deadline = time() + 30;

        while (time() < $deadline) {
            if (! ($this->isAlive)($pid)) {
                $note(sprintf('  Suwayomi (pid %d) has gone', $pid));

                if ($settleSeconds > 0) {
                    sleep($settleSeconds);
                }

                return;
            }

            sleep(1);
        }

        throw (new RestoreRefused(sprintf(
            'Suwayomi (pid %d) is still running after thirty seconds, so the manga database may still '
            .'be being written and copying it would produce a file that looks like a backup and is not '
            .'one. Nothing has been copied.',
            $pid,
        )))->attempted('pid '.$pid);
    }

    /**
     * The command's version, which turns a refusal into a message rather than a stack trace.
     *
     * The caller has to put Suwayomi back either way, and a caller that cannot tell a refusal from a
     * crash will not know whether to report success.
     */
    public function tryCapture(
        string $source,
        string $target,
        ?int $wasRunningPid,
        int $settleSeconds = 2,
        ?callable $note = null,
    ): bool {
        $note ??= static fn (string $line): null => null;

        try {
            $this->capture($source, $target, $wasRunningPid, $settleSeconds, $note);

            return true;
        } catch (Throwable $e) {
            $note('');
            $note('  '.$e->getMessage());

            return false;
        }
    }
}
