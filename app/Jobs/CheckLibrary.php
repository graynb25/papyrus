<?php

namespace App\Jobs;

use App\Library\CheckLedger;
use App\Library\CheckSchedule;
use App\Models\SeriesState;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Works through a reader's due series, in slices, until none are left.
 *
 * ## Why it re-books itself rather than running it all
 *
 * A 300 series library is 4.4 minutes of upstream requests at the measured median of
 * 876ms per refresh. A single pass holding a worker for four minutes is a worker
 * that cannot do anything else, and the worker's timeout would kill it, and with
 * `failOnTimeout` at its default the job would vanish with no record (trap 70).
 *
 * So each pass takes a slice bounded by time, writes what it found, and re-books
 * itself if any are due. The page polls the ledger, so progress is visible the whole
 * way rather than arriving at the end.
 *
 * Chained through the queue rather than the scheduler, for the reason
 * {@see SyncDownloads} chains: `schedule:work` is not running, and a feature that
 * depends on it silently does nothing.
 */
class CheckLibrary implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt. A retry repeats the same upstream calls and probably fails the
     * same way, and the next slice is three seconds away.
     */
    public int $tries = 1;

    /**
     * Comfortably above {@see BUDGET_SECONDS} plus a request, so the budget is what
     * ends a pass rather than the worker.
     */
    public int $timeout = 120;

    /**
     * How long one pass may spend.
     *
     * Sized against the measured cost: 876ms median, 1617ms worst, so a 45 second
     * budget takes about 50 series in a normal pass and 27 on a bad day. Either way
     * it is inside the timeout above.
     */
    public const BUDGET_SECONDS = 45;

    /**
     * A hard ceiling per pass, because time alone is not a bound when every source
     * answers in 5ms.
     */
    public const MAX_SERIES = 60;

    /**
     * Seconds before the next slice.
     *
     * Long enough that a reader watching a progress bar sees it move rather than
     * seeing a hundred dispatches queue up, short enough that the last series is not
     * left waiting.
     */
    public const NEXT_SLICE_SECONDS = 3;

    /**
     * How deep this pass sits inside another.
     *
     * The sync queue driver is the reason. With `QUEUE_CONNECTION=sync` a dispatch
     * runs inline, so a pass that re-books itself re-enters itself, and a library
     * that is permanently due would loop until the process ran out of memory.
     * Measured on the download reconciler: 128MB in about a second.
     *
     * Static rather than a constructor argument, because a field would survive into
     * the next slice of a real chain and cut it short.
     */
    private static int $chainDepth = 0;

    public function __construct(
        public readonly int $userId,
        public readonly ?int $shelfId = null,
        public readonly bool $force = false,
    ) {}

    public function handle(CheckLedger $ledger, CheckSchedule $schedule): void
    {
        self::$chainDepth++;

        try {
            $tally = $ledger->checkDue(
                $this->userId,
                self::BUDGET_SECONDS,
                self::MAX_SERIES,
                $this->force,
            );

            if ($tally['checked'] === 0) {
                return;
            }

            /*
             * Re-book only at depth one, and only if work remains.
             *
             * Both halves matter. The depth stops the inline-dispatch recursion, and
             * the remaining-work test stops a pass that finished the last series from
             * scheduling another one, which is what ends the chain rather than running
             * forever.
             */
            if (self::$chainDepth === 1 && $this->anythingStillDue($schedule)) {
                self::dispatch($this->userId, $this->shelfId, $this->force)
                    ->delay(now()->addSeconds(self::NEXT_SLICE_SECONDS));
            }
        } finally {
            self::$chainDepth--;
        }
    }

    /**
     * Whether any series is still due.
     *
     * Checked after the pass rather than before it, so a pass that cleared the last
     * due series ends the chain instead of booking an empty one.
     */
    private function anythingStillDue(CheckSchedule $schedule): bool
    {
        return $schedule->due($this->userId, 1)->isNotEmpty();
    }

    /**
     * The manga ids a pass would visit, for the progress the page shows.
     *
     * @return array<int, int>
     */
    public static function dueIds(CheckSchedule $schedule, int $userId): array
    {
        return $schedule->due($userId)
            /*
             * A series filed from a backup, whose source has shut down, has no Suwayomi manga
             * id and so nothing to check. Casting null to 0 would queue a pass for a manga that
             * does not exist, once per sweep, forever.
             */
            ->filter(fn (SeriesState $state): bool => $state->suwayomi_manga_id !== null)
            ->map(fn (SeriesState $state): int => (int) $state->suwayomi_manga_id)
            ->all();
    }

    /**
     * A pass per reader with a library, for a dispatcher that sweeps rather than
     * serving one page.
     *
     * @return array<int, self>
     */
    public static function everyReaderWithALibrary(): array
    {
        /*
         * A subquery rather than a relation, because the User model has no
         * seriesStates relation and adding one for the benefit of a sweeper would be
         * a wider change than this. The tenancy scope would also narrow the
         * relation to the current user, which is null here, so it could not be used
         * even if it existed.
         */
        return User::query()
            ->whereExists(
                fn ($query) => $query
                    ->selectRaw('1')
                    ->from('series_states')
                    ->whereColumn('series_states.user_id', 'users.id')
                    ->where('series_states.in_library', true),
            )
            ->pluck('id')
            ->map(fn ($id): self => new self((int) $id))
            ->all();
    }
}
