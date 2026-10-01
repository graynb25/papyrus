<?php

namespace App\Jobs;

use App\Migration\CandidateFinder;
use App\Models\MigrationSearch;
use App\Models\Source;
use App\Suwayomi\SeriesGateway;
use App\Suwayomi\SuwayomiException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Searches every installed source for another copy of a series, and ranks what it
 * finds.
 *
 * ## Why a job
 *
 * Measured: 82 sources, one search term each, 21.9 seconds, p50 143ms and a worst
 * source of 1459ms, zero failures. That is a good fit for a queue worker and a poor
 * one for a page request, and a reader watching a spinner for twenty seconds learns
 * nothing. So the page dispatches this and polls the `migration_searches` row, which
 * is the same shape the download centre uses for the same reason.
 *
 * ## Why it writes progress at all
 *
 * Two columns, "searched 40 of 82 sources", rather than a percentage bar. A bar at
 * 50% implies a duration the search cannot promise, because the sources have a
 * measured spread from 143ms to 1459ms and no upper bound worth betting on. The
 * count is something the page can state without qualifying.
 *
 * ## Why it swallows a source failure
 *
 * One source that times out is a source with no candidates, which is the same as one
 * that returned none. Throwing would discard 80 good results because of one bad
 * site, and the reader cannot tell the difference between "nothing there" and "I
 * could not look".
 */
class FindMigrationCandidates implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt. A retry re-runs the same 82 searches, and the sources do not get
     * any more reliable in the meantime.
     */
    public int $tries = 1;

    /**
     * Well above the measured cost, because the number a reader is waiting on is
     * worth more than the time it takes.
     *
     * Measured 35.4 seconds for the whole thing: 27.6 sweeping 81 sources and 7.8
     * fetching candidate details. An earlier estimate of 21.9s covered only the
     * sweep, and a first live run was killed at 60 seconds by a worker whose
     * `--timeout` was below the job's own budget.
     *
     * So this is deliberately larger than the worker's default, and an operator
     * running a tighter worker must raise it, because the worker kills the job at its
     * own limit regardless of what the job asks for. {@see MigrationSearch::isStalled()}
     * is what covers the case where it is not raised.
     */
    public int $timeout = 240;

    /**
     * Record a timeout as a failure rather than discarding the job.
     *
     * Measured, and this is the reason a search could vanish without a trace. The
     * queued payload carries `failOnTimeout: false` by default, so when this job
     * exceeded the worker's `--timeout` the worker killed it and deleted the row
     * without writing to `failed_jobs`. Four consecutive live runs left four search
     * rows saying `searching` and no record anywhere that a job had ever started.
     *
     * With this set, a timeout becomes a failed job, the row is marked failed with a
     * reason, and the page says so instead of polling for ever.
     */
    public bool $failOnTimeout = true;

    /**
     * How often to persist progress.
     *
     * The finder reports after every source, which is 82 times. Writing a row that
     * often to show a counter moving is wasted IO for a number that changes slowly in
     * human terms, so this throttles it. Five seconds is about twenty sources.
     */
    private const PROGRESS_INTERVAL_SECONDS = 5;

    public function __construct(
        public readonly int $searchId,
        public readonly int $userId,
        public readonly int $fromMangaId,
    ) {}

    public function handle(CandidateFinder $finder, SeriesGateway $series): void
    {
        $search = MigrationSearch::forUser($this->userId)->find($this->searchId);

        if ($search === null || $search->state !== MigrationSearch::SEARCHING) {
            // Superseded by a newer search, or already finished. Nothing to do, and
            // finishing anyway would overwrite a newer answer with an older one.
            return;
        }

        $current = null;

        try {
            $current = $series->find($this->fromMangaId);
        } catch (SuwayomiException $e) {
            // The series read is inside the guard too, not just the search. A server
            // that is down fails here first, and an exception escaping handle() would
            // leave the row `searching` for ever, so the page would poll a job that
            // had already given up.
            $this->fail($e->getMessage());

            return;
        }

        if ($current === null) {
            $this->fail('The server no longer knows this series.');

            return;
        }

        $source = Source::query()
            ->where('suwayomi_source_id', (string) $current['source']['id'])
            ->first();

        if ($source === null) {
            $this->fail('The series\' own source is not in the catalogue any more.');

            return;
        }

        /*
         * By reference, which is the whole point. Captured by value this never moved,
         * so the closure compared every report against the moment the sweep began and
         * wrote at most once, five seconds in. The row then sat at its initial value
         * for the entire run and a page polling it showed no progress at all.
         */
        $lastWrite = microtime(true);

        try {
            $result = $finder->find(
                $source,
                $current,
                function (int $searched, int $total) use ($search, &$lastWrite): void {
                    $now = microtime(true);

                    if ($now - $lastWrite < self::PROGRESS_INTERVAL_SECONDS) {
                        return;
                    }

                    $lastWrite = $now;

                    $search->sources_searched = $searched;
                    $search->sources_total = $total;
                    $search->save();
                },
            );
        } catch (SuwayomiException $e) {
            $this->fail($e->getMessage());

            return;
        }

        $search->state = MigrationSearch::READY;
        $search->candidates = $result['candidates'];
        $search->sources_searched = $result['meta']['sources_searched'];
        $search->sources_total = $result['meta']['sources_total'];
        $search->error = null;
        $search->save();
    }

    /**
     * Record why a search could not be completed.
     *
     * FAILED rather than READY-with-no-candidates, because "I could not look" and
     * "it is nowhere else" are different things to a reader and collapsing them
     * would report a transport failure as a fact about the series.
     */
    private function fail(string $reason): void
    {
        MigrationSearch::forUser($this->userId)
            ->whereKey($this->searchId)
            ->update([
                'state' => MigrationSearch::FAILED,
                'error' => $reason,
                'updated_at' => now(),
            ]);
    }
}
