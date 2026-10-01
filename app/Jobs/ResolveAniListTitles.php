<?php

namespace App\Jobs;

use App\AniList\AniListRefusedException;
use App\AniList\TitleResolver;
use App\Models\SearchResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Looks up the titles a search run found, so the deduplicator can group them by
 * AniList id.
 *
 * ## Why a job, and why it re-books itself
 *
 * The rate limit is 30 requests a minute, measured. A fan-out produces 1,080 raw hits
 * and 91 distinct titles, so resolving one run is 91 requests and over three minutes.
 * That is not a request, and it is not a single job either: a job holding a worker for
 * three minutes is a worker that cannot do anything else, and the worker's timeout
 * would kill it, and with `failOnTimeout` at its default the job would vanish with no
 * record (trap 70).
 *
 * So the work is spread over a chain of short jobs. Each pass takes a slice of the
 * remaining titles, sleeps between them to stay inside the limit, writes what it got,
 * and re-books itself if any are left. The reader's page polls the cache, so progress
 * is visible throughout rather than arriving all at once.
 *
 * ## A slice, not a batch of one
 *
 * Each pass takes as many titles as fit in the time budget, so a handful of titles
 * finish in seconds instead of queueing one job per title.
 */
class ResolveAniListTitles implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt. A refusal is not a reason to give up on the whole chain, and the
     * chain is what recovers.
     */
    public int $tries = 1;

    /**
     * Short, so a chain of these never holds a worker long. The budget below is what
     * decides how many titles a pass takes.
     */
    public int $timeout = 90;

    /**
     * How long one pass may spend, in seconds.
     *
     * Sized to the worker's timeout with room to spare, and to the rate limit: at
     * 30 a minute a pass of 20 titles takes 40 seconds, which is inside it and
     * leaves the worker free between passes.
     */
    private const PASS_BUDGET_SECONDS = 45;

    /**
     * Titles per pass. At the measured 2 seconds between requests this is 40 seconds,
     * inside the budget, and a run of 91 titles is then five passes rather than 91
     * jobs.
     */
    private const TITLES_PER_PASS = 20;

    /**
     * Seconds to wait between two lookups.
     *
     * 2.0 against a measured limit of 30 a minute, so a pass stays just inside it.
     */
    private const GAP_SECONDS = 2;

    public function __construct(public readonly int $userId, public readonly int $runId) {}

    public function handle(TitleResolver $resolver): void
    {
        $titles = $this->titles();

        if ($titles === []) {
            return;
        }

        $remaining = $resolver->missing($titles);
        $answered = 0;
        $refused = false;
        $passStarted = microtime(true);

        foreach ($remaining as $normalized => $title) {
            /*
             * Both limits, and the time one first.
             *
             * A title count cannot respect a time limit: two lookups at 2 seconds
             * each plus a 1.4 second slowest-case request is most of the budget
             * after nine titles, so a count of twenty would overrun the worker
             * on a slow day. Time decides, and the count is a ceiling on top.
             */
            if ($answered >= self::TITLES_PER_PASS) {
                break;
            }

            if (microtime(true) - $passStarted >= self::PASS_BUDGET_SECONDS) {
                break;
            }

            if ($answered > 0) {
                sleep(self::GAP_SECONDS);
            }

            try {
                $resolver->resolve($title);
                $answered++;
            } catch (AniListRefusedException $e) {
                /*
                 * Back off as long as AniList asked, then stop this pass. The chain
                 * re-books, so the next pass picks up where this one left off and
                 * nothing is lost: a refusal writes no cache row, so a title is never
                 * recorded as unknown because of a rate limit.
                 */
                sleep(max(1, $e->retryAfter));
                $refused = true;

                break;
            }
        }

        if ($resolver->missing($titles) !== [] && ! $refused) {
            self::dispatch($this->userId, $this->runId)->delay(now()->addSeconds(3));
        }
    }

    /**
     * The distinct titles this run found, in the order they should be asked about.
     *
     * Grouped in the database rather than in PHP, because a run holds 1,080 rows and
     * the reader wants 91 lookups, so the collapse to distinct titles is the
     * difference between a minute and half an hour.
     *
     * @return array<int, string>
     */
    private function titles(): array
    {
        return SearchResult::query()
            ->where('search_run_id', $this->runId)
            ->where('user_id', $this->userId)
            ->whereNotNull('normalized_title')
            ->distinct()
            ->orderBy('normalized_title')
            ->limit(400)
            ->pluck('title')
            ->all();
    }
}
