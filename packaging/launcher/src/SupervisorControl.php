<?php

namespace Papyrus\Launcher;

/**
 * {@see InstallControl} and {@see InstallFacts}, backed by the launcher's own supervisor.
 *
 * A thin adapter rather than the commands calling the supervisor themselves, so that each command's
 * only knowledge of the install is the questions it actually has to ask.
 *
 * The interesting detail is `start()`'s return, which is what lets the caller report honestly. A
 * start that raised is caught here and turned into a list, because a restore that put the files back
 * and then could not bring the application up has still left the reader with a problem, and it should
 * not report a clean success.
 */
final class SupervisorControl implements InstallControl, InstallFacts
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

    public function supervisorPid(): ?int
    {
        return $this->supervisor->supervisorPid();
    }

    public function suwayomiPids(): array
    {
        return $this->supervisor->pidsFor('suwayomi');
    }

    public function suwayomiVersion(): string
    {
        return $this->supervisor->suwayomiVersion();
    }

    public function phpExecutable(): string
    {
        return $this->supervisor->phpExecutable();
    }

    public function iniPath(): string
    {
        return $this->supervisor->resolvedIniPath();
    }
}
