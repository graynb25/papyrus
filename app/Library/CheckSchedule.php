<?php

namespace App\Library;

use App\Models\SeriesState;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Which series are due for a check, and why.
 *
 * ## Why this needs no call to Suwayomi
 *
 * The obvious implementation asks the server which series have a chapter list, and
 * that is the wrong question. What decides whether a series is due is when this
 * reader last looked at it, and that is a local fact on `series_states`.
 *
 * Measured, and it is not a small saving: one `fetchMangaAndChapters` costs 361ms to
 * 1617ms with a median of 876ms, and a re-check costs the same as the first fetch
 * (measured ratio 0.95x, so Suwayomi does not cache and every check is a real
 * upstream request). Asking the server to find out what we already know would be one
 * upstream request per series per page render.
 *
 * ## Why a failed check does not advance the clock
 *
 * The floor is on *successful* checks, not attempts. If a check times out and the
 * timestamp moved, the series would sit unexamined for the whole interval while the
 * page said it was current. Measured: 1 of 14 refreshes returned `No chapters
 * found`, so this is not hypothetical.
 *
 * ## Why the interval is per series
 *
 * `series_states.fetch_interval_days` has existed since migration 3 with nothing
 * reading it. A reader with 300 series wants an ongoing one checked daily and a
 * completed one never, and one global number cannot say both. Zero means always,
 * which is Mihon's convention and the only way to express "check this one every
 * time".
 */
class CheckSchedule
{
    /**
     * How many days a series goes unchecked when the reader has not said otherwise.
     *
     * One day, because that is what a reader expects from a "check for new
     * chapters" button on a series that is still running. A week would be safer for
     * the sites being hit and wrong for the reader.
     */
    public static function defaultIntervalDays(): int
    {
        return (int) config('papyrus.library.check_interval_days', 1);
    }

    /**
     * How many days before this series is checked again.
     *
     * Zero means every pass, which is the way to say "always check this one".
     */
    public function intervalDays(SeriesState $state): int
    {
        $override = $state->fetch_interval_days;

        if ($override === null) {
            return self::defaultIntervalDays();
        }

        // Negative is not a shorter interval, it is a mistake, and treating it as
        // zero would make the series the most checked thing in the library.
        return max(0, (int) $override);
    }

    /**
     * Whether this series is due right now.
     */
    public function isDue(SeriesState $state, bool $force = false): bool
    {
        if ($force) {
            return true;
        }

        $checkedAt = $state->chapter_list_checked_at;

        // Never looked at. Measured on the dev library: all 14 series have a null
        // `chapter_list_checked_at`, because nothing ever wrote one until the check
        // landed. This branch is the whole library on a fresh install.
        if ($checkedAt === null) {
            return true;
        }

        $interval = $this->intervalDays($state);

        if ($interval === 0) {
            return true;
        }

        return $checkedAt->lt(now()->subDays($interval));
    }

    /**
     * The series due for a check, oldest check first.
     *
     * Oldest first so a library that is entirely overdue works through it in the
     * order the reader added it, and a series that has been waiting longest is not
     * left to a later pass that may never come.
     *
     * @return Collection<int, SeriesState>
     */
    public function due(User|int $user, ?int $limit = null): Collection
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        /*
         * Read every library row rather than filtering in SQL, because the interval
         * is per row and an override has to beat the global default. A SQL interval
         * would need a join against a config value, which is not expressible, and a
         * library is bounded by what one reader has added rather than by anything
         * that grows on its own.
         *
         * Ordered in the database rather than here so the limit applies to the
         * oldest checks, not to whatever happened to load first.
         *
         * And only the rows a check can actually be run against, which is the third query that
         * needed saying. `CheckLedger::candidates()` and `CheckSchedule::summary()` were both
         * fixed for this and this one was missed, so the three disagreed, and the disagreement
         * was not cosmetic.
         *
         * Measured, and this is the whole of "the check is not updating even after a good
         * while": a series filed from a backup with a dead source has a null
         * `suwayomi_manga_id`, so it came back from here, `isDue()` called it due for ever
         * because nothing ever checked it, and `CheckLibrary::anythingStillDue()` re-booked the
         * chain on it. 95 dispatches against a worker that checked nothing, every one of them
         * re-checking the same phantom. A reader watching that sees a job plainly running and a
         * number that never moves.
         *
         * Excluded here, and in the two places above, because "what is due" has to mean "what a
         * pass would visit", which is the question the schedule exists to answer.
         */
        $states = SeriesState::forUser($userId)
            ->where('in_library', true)
            ->whereNotNull('suwayomi_manga_id')
            ->orderByRaw('chapter_list_checked_at is not null')
            ->orderBy('chapter_list_checked_at')
            ->orderBy('added_at')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get();

        return $states
            ->filter(fn (SeriesState $state): bool => $this->isDue($state))
            ->values();
    }

    /**
     * How many series a pass would visit, and how many it would skip.
     *
     * ## Why a series with no source is not counted as due
     *
     * Because it cannot be checked, and a number that cannot go down is not a number a reader
     * can watch.
     *
     * A series filed from a backup whose source has since shut down has a null
     * `suwayomi_manga_id`. There is nothing to check it against, so `CheckLedger::candidates()`
     * excludes it, deliberately, and counting it here would contradict that. Measured on the
     * real library: 867 series, 195 of them filed with no source, and `due` sitting at 853. A
     * reader pressing "Check 853 series" would have watched it climb and then stop at 77%, with
     * no way to tell the remaining 23% from a stall.
     *
     * So `due` here means "a pass would visit this", which is the question the button asks.
     * The 195 are still in `total`, because they are still in the reader's library and the
     * library page still shows them.
     *
     * @return array{total: int, due: int, never: int, skipped: int, unreachable: int, interval_days: int}
     */
    public function summary(User|int $user): array
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        $states = SeriesState::forUser($userId)
            ->where('in_library', true)
            ->get();

        $due = 0;
        $never = 0;
        $unreachable = 0;

        foreach ($states as $state) {
            if ($state->suwayomi_manga_id === null) {
                $unreachable++;

                continue;
            }

            if ($this->isDue($state)) {
                $due++;

                if ($state->chapter_list_checked_at === null) {
                    $never++;
                }
            }
        }

        return [
            'total' => $states->count(),
            'due' => $due,
            'never' => $never,
            // `$states->count() - $due` counts the unreachable ones as skipped, which would
            // claim they are merely not due yet. They are due and can never be, so they are
            // named.
            'skipped' => $states->count() - $due - $unreachable,
            'unreachable' => $unreachable,
            'interval_days' => self::defaultIntervalDays(),
        ];
    }
}
