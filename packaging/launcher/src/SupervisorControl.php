<?php

namespace Papyrus\Launcher;

/**
 * {@see InstallControl} backed by the launcher's own supervisor.
 *
 * A thin adapter rather than `RestoreCommand` calling the supervisor itself, so that the command's
 * only knowledge of processes is that they can be stopped and started.
 *
 * The interesting detail is `start()`'s return, which is what lets the caller report honestly. A
 * start that raised is caught here and turned into a list, because a restore that put the files back
 * and then could not bring the application up has still left the reader with a problem, and it should
 * not report a clean success.
 */
final class SupervisorControl implements InstallControl
{
    public function __construct(private readonly Supervisor $supervisor) {}

    public function stop(callable $say): array
    {
        return $this->supervisor->stopAll($say);
    }

    public function start(callable $say): array
    {
        try {
            return $this->supervisor->start($say);
        } catch (\Throwable $e) {
            $say('  Papyrus did not come back up: '.$e->getMessage());

            return [];
        }
    }
}
