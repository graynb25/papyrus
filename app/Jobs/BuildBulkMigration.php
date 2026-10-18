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
 * The cost is one search plus one detail fetch per comparison, on every destination. Measured
 * live on 2026-10-18: thirteen series at one target took 42 seconds, thirteen series at five
 * targets took 44 seconds for sixty five comparisons, so the same comparison runs from 0.7
 * seconds to 3.2 seconds depending on the upstream moment and how many candidates need
 * fetching. Too long for a page request and long enough that a reader watching a spinner
 * learns nothing, which is the same shape as every other long job in this app: dispatch, then
 * poll a row.
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
     * Set from the measurement, not chosen large.
     *
     * Measured live on 2026-10-18: sixty five comparisons, thirteen series at the five
     * destination cap, settled in 44 seconds at 0.68 seconds each; the two destination walk
     * ran 1.5 seconds each; the one destination flight ran 3.2 seconds a series the same
     * evening. At the two hundred series cap that arithmetic is 680 seconds at the best
     * rate and 3,230 at the slowest one, and 1800 covered the middle of that spread but not
     * its ceiling, which is why this is an hour. It is deliberately larger than a worker's
     * default, and an operator running a tight worker must raise theirs, because the worker
     * kills the job at its own limit regardless of what the job asks for. {@see
     * BulkMigration::isStalled()} covers the case where they did not.
     */
    public int $timeout = 3600;

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
             *
             * Only while it is still running, because the press of a stop can land inside the
             * same window a throw did: the reader's own verdict for a run they stopped is the
             * stop, and a failure stamp over it would tell them it broke when it did not.
             * The exception still goes to the log either way.
             */
            $run->refresh();

            if ($run->state === BulkMigration::RUNNING) {
                $run->update([
                    'state' => BulkMigration::FAILED,
                    'error' => $e->getMessage(),
                    'finished_at' => now(),
                ]);
            }

            throw $e;
        }
    }
}
