<?php

namespace App\Jobs;

use App\Downloads\DownloadLedger;
use App\Models\Download;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/**
 * Reconciles this reader's downloads against the shared queue.
 *
 * ## Why a job and not a page render
 *
 * The queue moves whether or not anyone is looking at it, so a page that only reads
 * it shows whatever was true when it rendered and nothing after. Polling has to
 * happen on a timer, which means a queue worker and a schedule.
 *
 * ## Why it swallows its failures
 *
 * One reader's sync failing is not the end of anything. If this job throws, the
 * retry logs it, and the reader's downloads sit showing stale progress with no
 * explanation. Catching and recording keeps the rest of the ledger accurate and
 * puts the reason where the reader will see it.
 *
 * ## Why it syncs every reader rather than one
 *
 * The queue is shared, so a per-user job dispatched from a page render only
 * reconciles whoever happens to be looking. One job walking the readers who have
 * pending rows costs one pass and covers all of them.
 */
class SyncDownloads implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt. A retry would re-run the same two calls and probably fail the
     * same way, and the next tick is seconds away anyway.
     */
    public int $tries = 1;

    /**
     * Short, so a dead queue does not hold a worker for a minute. The download
     * mutations and status read are all loopback and measured in milliseconds
     * unless Suwayomi is busy, which is exactly when we want to give up.
     */
    public int $timeout = 120;

    /**
     * Seconds between passes, shared with the schedule.
     *
     * Measured: on a 28 page chapter Suwayomi's own progress moves by 1/28 per
     * page and the chapter takes 39 seconds, so ten seconds samples a long chapter
     * four or five times. Faster mostly re-reads the same number.
     */
    public const INTERVAL_SECONDS = 10;

    public function __construct(public readonly ?int $userId = null) {}

    /**
     * How deep this pass is nested inside another.
     *
     * Re-booking is only allowed at depth one, and the reason is the sync queue
     * driver. With `QUEUE_CONNECTION=sync` a dispatch is not deferred, it runs
     * inline, so a pass that re-books itself re-enters itself, and a download that
     * never settles loops until the process runs out of memory. Measured: a test
     * with one unreachable queue and no `Queue::fake()` exhausted 128MB in
     * Gateway's server-error branch in about a second.
     *
     * Static rather than a constructor argument, and that matters. Carried on the
     * job it would survive into the next tick of a real chain and cut the chain
     * short after a few passes. Held in the process, it is one while a worker is
     * running a pass and two only when a dispatch came back inline, which is
     * exactly the case worth stopping.
     */
    private static int $chainDepth = 0;

    public function handle(DownloadLedger $ledger): void
    {
        self::$chainDepth++;

        try {
            $users = $this->userId !== null
                ? User::query()->whereKey($this->userId)->get()
                : $this->usersWithPendingDownloads();

            foreach ($users as $user) {
                try {
                    $ledger->sync($user);
                } catch (\Throwable $e) {
                    $this->noteForUser($ledger, $user, $e);
                }
            }

            if (self::$chainDepth === 1 && $this->anythingStillPending()) {
                $this->queueTheNextTick();
            }
        } finally {
            self::$chainDepth--;
        }
    }

    /**
     * Whether any download is still waiting on the server.
     *
     * Checked after the pass rather than before it, because a sync that finished
     * the last pending row must not schedule another one. That is what stops this
     * from running forever: the chain ends when the work does.
     */
    private function anythingStillPending(): bool
    {
        return Download::forAllUsers()->pending()->exists();
    }

    /**
     * Book the next pass through the queue rather than the scheduler.
     *
     * The schedule in routes/console.php is the safety net, not the mechanism. It
     * needs `schedule:work` to be running, and a queue worker alone would leave the
     * ledger frozen: nothing dispatches the job, so nothing reconciles, so a reader
     * who clicks download watches a bar that never moves. Chaining through the
     * worker means the feature works with the worker this project already runs.
     *
     * The interval is the schedule's, and for the same measured reason: Suwayomi's
     * own progress steps once per page, so a faster tick mostly re-reads the same
     * value.
     */
    private function queueTheNextTick(): void
    {
        self::dispatch()->delay(now()->addSeconds(self::INTERVAL_SECONDS));
    }

    /**
     * @return Collection<int, User>
     */
    private function usersWithPendingDownloads(): Collection
    {
        $ids = Download::forAllUsers()
            ->pending()
            ->distinct()
            ->pluck('user_id');

        return User::query()->whereKey($ids)->get();
    }

    /**
     * Record why this reader's downloads stopped moving.
     *
     * The rows stay pending. A transport failure says nothing about whether the
     * server is downloading them, and marking them failed would be a lie that the
     * next successful sync then has to walk back.
     */
    private function noteForUser(DownloadLedger $ledger, User $user, \Throwable $e): void
    {
        $ledger->forUser($user)->each(function (Download $row) use ($e): void {
            if ($row->isSettled()) {
                return;
            }

            $row->error = $e->getMessage();
            $row->save();
        });
    }
}
