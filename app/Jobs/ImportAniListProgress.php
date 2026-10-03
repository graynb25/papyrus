<?php

namespace App\Jobs;

use App\AniList\AniListRefusedException;
use App\AniList\Import;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Pulls a reader's AniList progress into their library, one slice at a time.
 *
 * A chain rather than one job for the same reason the title resolver is: the
 * title lookups are rate-limited to 30 a minute, and a fresh cache means most
 * of the library needs one. Each pass takes ten series, and this job re-books
 * itself while any remain. The work shrinks every pass, so the chain ends.
 */
class ImportAniListProgress implements ShouldQueue
{
    use Queueable;

    public bool $failOnTimeout = true;

    /**
     * How long one attempt may take, in seconds.
     *
     * Derived from {@see Import::worstCaseSeconds()} rather than typed, so it
     * cannot drift away from the budgets a pass is actually bounded by: raising
     * `papyrus.anilist.timeout`, `papyrus.suwayomi.timeout` or the pass budget
     * moves this with it, and a test holds the two against each other.
     *
     * The literal it replaced was 120, and that was reachable in ordinary
     * operation rather than only in a catastrophe. One series can cost a 15
     * second AniList timeout three times over plus two 28 second Suwayomi
     * budgets, so ten series reach 780 seconds and a slow day killed the job
     * mid-pass. With `failOnTimeout` on, that killed the reader's import while
     * the page showed it failing, so a number that has to be true on every
     * ordinary Tuesday cannot be a literal.
     *
     * A ceiling rather than an expectation. A pass stops at its own budget and
     * re-books, so the usual attempt is the budget plus one series and a chain
     * still hands its worker back between passes.
     */
    public int $timeout;

    /**
     * Three attempts, and none of them can overlap a timed-out one.
     *
     * With `failOnTimeout` on, a pass that hits `$timeout` is failed and deleted
     * rather than retried, so the retry this leaves is for an attempt that threw
     * and returned. Two attempts of the same chain therefore never run at once,
     * which is the property the pass lock could not have given on its own.
     */
    public int $tries = 3;

    public function __construct(
        public readonly int $userId,
        public readonly ?string $runId = null,
    ) {
        $this->timeout = Import::worstCaseSeconds();
    }

    public function handle(Import $import): void
    {
        try {
            $more = $import->runPass($this->userId, $this->runId);
        } catch (AniListRefusedException $e) {
            $this->release(min(max($e->retryAfter, 5), 120));

            return;
        }

        if ($more) {
            self::dispatch($this->userId, $this->runId);
        }
    }

    /**
     * Give up loudly, because the connections page is watching.
     *
     * Without this the run ends with the page still saying "Checking N of M series".
     * `$timeout` is now above what a pass can reach by arithmetic, so this handler
     * is for the three ways a chain stops that nothing inside the pass wrote down:
     * an attempt killed at the timeout, one that has run out of attempts, and a
     * queue with no worker at all, where the chain simply never continues. Only
     * {@see Import::fail()} writes `phase = failed`, so without this the poll sat
     * on the running message until the six-hour state TTL expired.
     */
    public function failed(?Throwable $exception): void
    {
        app(Import::class)->fail(
            $this->userId,
            $exception?->getMessage() !== null
                ? mb_substr($exception->getMessage(), 0, 300)
                : 'The import did not finish. Start it again.',
        );
    }
}
