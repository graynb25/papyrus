<?php

namespace Papyrus\Launcher;

/**
 * The real filesystem, for {@see FileMover}.
 *
 * ## Every failure throws, and says which path
 *
 * Because a restore has eleven steps and the reader will only ever see the message. "Permission
 * denied" with no path attached is the shape AGENTS.md calls a confident wrong answer rather than an
 * answer, and it is worse than nothing when the reader is trying to work out whether their library
 * survived.
 *
 * The digest is streamed rather than read whole. A manga database measured in the hundreds of
 * megabytes is not a file to hold in memory, and the one that matters here is the larger of the two.
 */
final class LocalFileMover implements FileMover
{
    public function move(string $from, string $to): void
    {
        if (! is_file($from)) {
            throw (new RestoreRefused(sprintf('There is no file at %s to move.', $from)))->attempted($from);
        }

        $this->prepareTarget($to);

        if (! @rename($from, $to)) {
            throw (new RestoreRefused(sprintf(
                'Could not move %s to %s. Both are on this machine, so this is a permission or a '
                .'lock rather than a missing directory.',
                $from,
                $to,
            )))->attempted($from);
        }
    }

    public function copy(string $from, string $to): void
    {
        if (! is_file($from)) {
            throw (new RestoreRefused(sprintf('There is no file at %s to copy.', $from)))->attempted($from);
        }

        $this->prepareTarget($to);

        // copy() rather than rename(), because the source is the backup and the backup has to survive
        // this. A restore that consumed the only copy of a reader's library is not recoverable by
        // running it again.
        if (! @copy($from, $to)) {
            throw (new RestoreRefused(sprintf(
                'Could not copy %s to %s. A copy that stops part way is worse than one that never '
                .'starts, because it leaves a file that looks like a database.',
                $from,
                $to,
            )))->attempted($from);
        }

        /*
         * The copy is verified against the source immediately.
         *
         * Because `copy()` returning true does not mean the bytes arrived. On Windows a copy that
         * loses an antivirus filter part way through can return success and leave a short file, and
         * the symptom of that is a database that will not open days later, long after anyone
         * remembers the restore.
         *
         * This is the same lesson as the portable zip: the tool reported success and the artefact was
         * wrong. Here it costs one hash of a file already in memory's reach.
         *
         * The check is a named method rather than an inline condition because the interesting case
         * cannot be produced through the real `copy()`: making a copy genuinely lose bytes needs a
         * filter or a full disk, and a test that reaches for one is a test that works on the machine
         * it was written on and nowhere else. Exposed, it can be called with a mismatched pair and
         * asserted on, which is the whole reason it is a method.
         */
        $this->assertCopyArrivedIntact($from, $to);
    }

    /**
     * Refuse, and delete, a destination that is not a faithful copy of the source.
     *
     * @throws RestoreRefused
     */
    public function assertCopyArrivedIntact(string $from, string $to): void
    {
        if (filesize($from) !== filesize($to) || $this->digest($from) !== $this->digest($to)) {
            @unlink($to);

            throw (new RestoreRefused(sprintf(
                'The copy of %s did not arrive intact, so it was deleted rather than left in place. '
                .'Nothing has been changed.',
                basename($from),
            )))->attempted($from);
        }
    }

    public function digest(string $path): string
    {
        $hash = @hash_file('sha256', $path);

        if (! is_string($hash)) {
            throw (new RestoreRefused(sprintf('Could not read %s to check it is intact.', $path)))->attempted($path);
        }

        return strtolower($hash);
    }

    public function exists(string $path): bool
    {
        return is_file($path);
    }

    /**
     * Make sure the destination can be written, refusing before anything is moved out of the way.
     *
     * The directory is created if it is missing, because a rollback directory that could not be
     * created is discovered at the moment the live file has already been moved aside, which is the
     * worst possible time to learn it.
     */
    private function prepareTarget(string $to): void
    {
        $directory = dirname($to);

        if (! is_dir($directory) && ! @mkdir($directory, 0o777, true) && ! is_dir($directory)) {
            throw (new RestoreRefused(sprintf('Could not create %s.', $directory)))->attempted($directory);
        }

        // An existing file at the destination is replaced deliberately, but only if it can be removed.
        // SQLite refuses to be overwritten by some operations, and a destination that cannot be
        // removed is a fact worth learning before the live database is moved.
        if (is_file($to) && ! @unlink($to)) {
            throw (new RestoreRefused(sprintf(
                'Could not replace %s. Something is holding it open, which usually means the '
                .'application is still running.',
                $to,
            )))->attempted($to);
        }
    }
}
