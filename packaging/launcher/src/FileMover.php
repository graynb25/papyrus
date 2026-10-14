<?php

namespace Papyrus\Launcher;

/**
 * The filesystem operations a restore performs, behind an interface.
 *
 * ## Why this is an interface rather than a set of private helpers
 *
 * Because the guarantee this class makes is about what happens **when the second half fails**, and the
 * only honest way to test that is to make the second copy fail. A restore is eleven moving parts
 * across two databases and a process supervisor, and the interesting case is the one where step four
 * of six raises.
 *
 * Everything else is real: the tests write real files into a temporary directory and assert on the
 * bytes afterwards, because "it rolled back" means something about the disk and not about a return
 * value. Only the failing operation is substituted.
 *
 * This is not a test seam dressed up as an abstraction. It is the boundary between what a restore
 * *decides* and how the filesystem behaves while carrying that out, and the second half of that
 * boundary is exactly where the interesting failures live.
 */
interface FileMover
{
    /**
     * Move a file. Throws rather than returning false, because a caller that has to check every call
     * will eventually not check one, and "the move silently did not happen" is how a half-restored
     * install looks from the inside.
     *
     * @throws RestoreRefused
     */
    public function move(string $from, string $to): void;

    /**
     * Copy a file, leaving the original in place.
     *
     * Copy rather than move for the incoming files, because the backup is the reader's and a restore
     * that consumed it would make the restore impossible to repeat.
     *
     * @throws RestoreRefused
     */
    public function copy(string $from, string $to): void;

    /**
     * The SHA-256 of a file, lower case and unpunctuated.
     *
     * @throws RestoreRefused
     */
    public function digest(string $path): string;

    public function exists(string $path): bool;
}
