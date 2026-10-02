<?php

namespace App\Jobs;

use App\AniList\AniListRefusedException;
use App\AniList\Tracking;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pushes one series' reading progress to the reader's AniList account.
 *
 * A job rather than an inline call because the reader's progress endpoint fires
 * while a chapter is being read: the write to AniList must never sit between a
 * page turn and its response. The queue also gives a rate limit somewhere to
 * wait that is not the reader's attention.
 *
 * `failOnTimeout` is set on purpose. A job whose runtime is uncertain and that
 * vanishes silently when it overruns is the exact shape of failure this project
 * has been bitten by before: the row would sit pending forever, and every test
 * would still pass.
 */
class SyncAniListProgress implements ShouldQueue
{
    use Queueable;

    public bool $failOnTimeout = true;

    public int $timeout = 60;

    /**
     * Enough attempts to sit out a rate limit window or two. A rejected token
     * is not retried: it ends the job as a normal state, and the reader sees
     * the disconnection in settings.
     */
    public int $tries = 4;

    public function __construct(
        public readonly int $userId,
        public readonly int $mangaId,
    ) {}

    public function handle(Tracking $tracking): void
    {
        try {
            $tracking->sync($this->userId, $this->mangaId);
        } catch (AniListRefusedException $e) {
            $this->release(min(max($e->retryAfter, 5), 120));
        } finally {
            $tracking->clearPending($this->userId, $this->mangaId);
        }
    }
}
