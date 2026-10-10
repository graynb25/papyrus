<?php

namespace App\Jobs;

use App\Migration\BulkSourceMatcher;
use App\Models\BulkMigration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Compares a reader's whole shelf from one source against another.
 *
 * ## Why a job, and why it writes progress
 *
 * The cost is one search plus one detail fetch per series, measured at 370ms to 1346ms for the
 * fetch, so eighty series is one to two minutes of upstream requests. Too long for a page
 * request and long enough that a reader watching a spinner learns nothing, which is the same
 * shape as every other long job in this app: dispatch, then poll a row.
 *
 * Progress goes on the run row rather than into logs, because the page has to be able to say
 * "compared 40 of 80" and because a job that dies part way through has to leave something a
 * reader can see. {@see BulkSourceMatcher} writes it as it goes rather than only at the end.
 *
 * ## Why one attempt
 *
 * A retry re-runs every comparison, and the sources do not get more reliable in the meantime.
 * A reader who wants another go presses the button again, and gets a fresh run rather than a
 * resumed one. Resuming would need a cursor over the library, and the cost of the thing being
 * avoided is a minute of requests.
 *
 * ## The stall case is covered, and this is why
 *
 * `failOnTimeout` is set, because the queued payload defaults it to false: when a job exceeds
 * the worker's own `--timeout` the worker kills it and deletes the row with nothing written to
 * `failed_jobs`. That is not hypothetical, it is how the first live migration search vanished
 * four times in a row. With it set, a timeout becomes a failed job the page can report.
 */
class BuildBulkMigration implements ShouldQueue
{
    use Queueable;

    /** One attempt. See above. */
    public int $tries = 1;

    /**
     * Well above the expected cost.
     *
     * Eighty series at a measured 1346ms for the slowest fetch is under two minutes, so this
     * is generous. It is deliberately larger than a worker's default, and an operator running
     * a tight worker must raise theirs, because the worker kills the job at its own limit
     * regardless of what the job asks for. {@see BulkMigration::isStalled()} covers the case
     * where it is not raised.
     */
    public int $timeout = 1800;

    /**
     * Record a timeout as a failure rather than discarding the job.
     *
     * Without this, a run killed at the worker's timeout leaves its row reading `running` for
     * ever, which is the one state a polling page cannot interpret.
     */
    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $runId,
        public readonly int $userId,
        public readonly int $limit = 0,
    ) {}

    public function handle(BulkSourceMatcher $matcher): void
    {
        $run = BulkMigration::query()->forUser($this->userId)->find($this->runId);

        if ($run === null || $run->state !== BulkMigration::RUNNING) {
            // Superseded by a newer attempt, or already finished. Finishing anyway would
            // overwrite a newer answer with an older one.
            return;
        }

        try {
            $matcher->run($run, $this->limit);
        } catch (\Throwable $e) {
            report($e);

            /*
             * Close the run out, because the matcher records its own failures per series and
             * only reaches here for something it could not survive, such as the library itself
             * being unreadable. Leaving the row reading `running` is the state a page cannot
             * interpret.
             */
            $run->update([
                'state' => BulkMigration::FAILED,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            throw $e;
        }
    }
}
