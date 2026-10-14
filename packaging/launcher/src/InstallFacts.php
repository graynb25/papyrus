<?php

namespace Papyrus\Launcher;

/**
 * Read-only facts about an install that a backup has to know before it stops anything.
 *
 * ## Why this is not three more methods on {@see InstallControl}
 *
 * Because `InstallControl` is the stop and start seam, and {@see RestoreCommand} depends on it. A
 * restore needs to bring the install down and bring it back, and nothing else about it. Adding three
 * methods it never calls would mean the restore depends on an interface it does not use, and the next
 * reader has to work out which of the five it actually cares about.
 *
 * A snapshot is the other command, and it needs to know three things before it stops anything:
 *
 *   | Question | Why it has to be asked first |
 *   | --- | --- |
 *   | which pids Suwayomi is running under | the copy refuses unless the process it was writing with is gone, and the stop destroys this |
 *   | which version of Suwayomi this is | the manifest carries it, and the manifest is written after the stop |
 *   | where this install's PHP is | the reader half is taken by that interpreter, because the launcher's own has no `pdo_sqlite` |
 *
 * So the two commands have two seams, each shaped by what it does. A command that has to hold a
 * `Supervisor` needs a whole install on disk to test, which is what lesson 225's neighbour cost last
 * time: five restore tests dying on `The web server configuration is not where this install expects
 * it`.
 *
 * ## Why these come from the supervisor and not from the environment
 *
 * Because the supervisor is the only thing that knows them. The data directory, the jar and the
 * resolved ini are all facts it derived, and a snapshot that re-derived any of them would be a second
 * answer to a question that already has one.
 */
interface InstallFacts
{
    /**
     * The pid of a live launcher that is supervising this install, or null when none is.
     *
     * ## Why a command that stops the install has to ask this first
     *
     * Because the launcher lock was only ever read by `start`, so `snapshot` and `restore` would stop
     * an install another launcher was holding open without either of them knowing. Measured, on the
     * reader's install; the full transcript is in {@see InstallControl::stop()}:
     *
     *     the holder saw its children die, said "stopped on its own", shut the rest down and exited 1
     *     the snapshot finished all six stages, said "up again", and exited 0
     *     the install was down
     *
     * Two supervisors, one install, no shared idea of who owns it. This is the question that decides
     * it, and the lock file already answers it: it names a pid and a process start time, so a stale
     * lock left by a crashed launcher reads as nobody rather than as somebody. That is what
     * `InstanceLock` was built for and this is the first caller other than `start`.
     */
    public function supervisorPid(): ?int;

    /**
     * The pids Suwayomi is running under, for this install only.
     *
     * A list rather than one pid because "which one" is not something to guess. More than one means
     * two JVMs are answering for one data directory, and a snapshot that picked the first would wait
     * for the wrong one and copy a file the other is writing.
     *
     * @return list<int> Empty when Suwayomi is not running.
     */
    public function suwayomiPids(): array;

    /**
     * Which Suwayomi this install ships, as in `2.4.2366`.
     *
     * Never empty: `unknown` rather than a blank, because {@see BackupPair} refuses a blank and a
     * reader is better served by a manifest admitting it does not know than by no backup at all.
     */
    public function suwayomiVersion(): string;

    /**
     * The packaged `php.exe`, for a caller that has to run something against this install's data.
     *
     * Not the launcher's own interpreter, which loads no ini and therefore has no `pdo_sqlite`. Ask
     * the install's, so the work is done by the same interpreter and the same extensions the
     * application itself uses.
     */
    public function phpExecutable(): string;

    /**
     * The resolved `php.ini` that goes with {@see phpExecutable()}.
     *
     * Passed alongside rather than left to the child, because a `php.exe` that finds a different ini
     * is a different configuration, and the reader half depends on the extension being loaded.
     */
    public function iniPath(): string;
}
