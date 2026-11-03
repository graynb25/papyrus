<?php

namespace App\Live\Library;

use App\Library\CheckLedger;
use App\Library\CheckSchedule;
use App\Library\ContinueReading;
use App\Models\CheckRun;
use App\Models\LibraryCheck;
use App\Models\SeriesState;
use App\Support\Library;
use Illuminate\Support\Collection;

/**
 * The library page's reads, kept out of the Livewire component.
 *
 * The component asks these on every render and on a few card actions. Keeping them
 * here lets the query shapes, the ordering and the pagination be exercised without
 * mounting a component, and leaves the component owning lifecycle, actions and the
 * check state machine.
 *
 * Reads that depend on another read take the collection the component already
 * computed, so a render still makes one Suwayomi batch call rather than one per
 * helper. The shapes, order and limits are the page's own.
 */
final class LibraryPageData
{
    /**
     * How long a bulk check may report nothing before the page says the queue is not moving.
     *
     * Generous, because a slice is a time budget of 45 seconds and a whole run is fifteen of
     * them. Five minutes is about seven slices with nothing, which is not a slow worker.
     */
    public const CHECK_STALL_SECONDS = 300;

    public function __construct(
        private readonly Library $library,
        private readonly CheckSchedule $schedule,
        private readonly CheckLedger $ledger,
        private readonly ContinueReading $continue,
    ) {}

    /**
     * Every entry, before filtering and paging.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function entries(): Collection
    {
        return $this->library->entries(auth()->user());
    }

    /**
     * @return Collection<int, array{id: int, name: string, order: int, count: int}>
     */
    public function shelves(): Collection
    {
        return $this->library->categories(auth()->user());
    }

    /**
     * Which shelf each series in the library is in.
     *
     * One query for the whole library rather than one per card.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return array<int, array<int, int>>
     */
    public function filings(Collection $entries): array
    {
        $ids = $entries
            ->pluck('manga_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        return $this->library->filingsFor(auth()->user(), $ids);
    }

    /**
     * Why the library has no covers and no chapter counts, when it does.
     *
     * The verdict from the read the page already made, not a fresh probe. The entries
     * collection is passed so that read precedes the verdict; probing would double the
     * cost of the most expensive page in the app.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     */
    public function sourceFailure(Collection $entries): ?string
    {
        // The entries are passed so the read that learned this failure has already run.
        return $this->library->sourceFailure();
    }

    /**
     * The run the page reports, if there is one worth showing.
     *
     * A finished run stays for an hour, which covers a reload just after the last
     * slice and stops yesterday's result reading as today's. A running run stays
     * whatever its age, because a reader who opens the page mid-check should see
     * it and be able to stop it.
     */
    public function checkRun(): ?CheckRun
    {
        return CheckRun::query()
            ->forUser(auth()->user())
            ->visible()
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Where the running check has got to.
     *
     * Read from the run row rather than from page properties, so a reload
     * reports the same fraction, the same finished sentence, and the same Stop
     * control as the page that pressed the button.
     *
     * @return array{total: int, done: int, percent: int, running: bool, cancelled: bool, failed: bool, stalled: bool, new: int, failed_series: int}|null
     */
    public function checkProgress(?CheckRun $run): ?array
    {
        if ($run === null) {
            return null;
        }

        return [
            'total' => $run->total,
            'done' => min($run->checked, $run->total),
            'percent' => $run->percent(),
            'running' => $run->isRunning(),
            'cancelled' => $run->isCancelled(),
            'failed' => $run->isFailed(),
            /*
             * A run that is working but has not reported a series in five minutes
             * is the dead-queue case. `last_progress_at` moves per series, not per
             * slice, so a slow source still reports unless it is stuck for longer
             * than the stall window.
             */
            'stalled' => $run->isRunning()
                && $run->last_progress_at->lt(now()->subSeconds(self::CHECK_STALL_SECONDS)),
            'new' => $run->new_chapters,
            'failed_series' => $run->failed,
        ];
    }

    /**
     * Which series are due, and how the floor is set.
     *
     * @return array{total: int, due: int, never: int, skipped: int, unreachable: int, interval_days: int}
     */
    public function checkSchedule(): array
    {
        return $this->schedule->summary(auth()->user());
    }

    /**
     * The latest check per series on this page, keyed by manga id.
     *
     * One query rather than one per card.
     *
     * @param  Collection<int, array<string, mixed>>  $visibleEntries
     * @return array<int, LibraryCheck>
     */
    public function checks(Collection $visibleEntries): array
    {
        $ids = $visibleEntries
            ->pluck('manga_id')
            // Nulls dropped rather than cast to 0: every entry in this ledger is keyed by
            // a manga id, and 0 would be a lookup for a manga that does not exist rather
            // than an empty result.
            ->filter(fn (mixed $id): bool => $id !== null)
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return $this->ledger->latestFor(auth()->user(), $ids);
    }

    /**
     * How many chapters arrived on the last check of a series.
     *
     * Null when it has never been checked or the check found nothing, which are
     * different from "zero new".
     *
     * @param  array<int, LibraryCheck>  $checks
     */
    public function newChapters(array $checks, int $mangaId): ?int
    {
        // `??` rather than `?->` on the array offset. A computed property holding an
        // array cannot be indexed with `?->`, so PHP reports "Undefined array key"
        // for every series that has not been checked, which is every series on a
        // fresh install and most of them in general.
        $row = $checks[$mangaId] ?? null;

        return $row?->new_chapters;
    }

    /**
     * Whether a series is due, for the card's own button.
     */
    public function isDue(int $mangaId): bool
    {
        $state = SeriesState::forUser((int) auth()->id())
            ->where('suwayomi_manga_id', $mangaId)
            ->first();

        return $state !== null && $this->schedule->isDue($state);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $matching
     */
    public function totalFiltered(Collection $matching): int
    {
        return $matching->count();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     */
    public function totalAll(Collection $entries): int
    {
        return $entries->count();
    }

    public function lastPage(int $totalFiltered): int
    {
        return max(1, (int) ceil($totalFiltered / LibraryFilter::PER_PAGE));
    }

    /**
     * Where this reader should pick up, most recently read first.
     *
     * Built from the matching entries, not every entry, so the shelf and search filters
     * apply. A page filtered to one shelf that then offers a continue card for a series
     * outside that shelf contradicts itself.
     *
     * @param  Collection<int, array<string, mixed>>  $matching
     * @return Collection<int, array<string, mixed>>
     */
    public function continueFeed(Collection $matching): Collection
    {
        $resolutions = $matching
            ->filter(static fn (array $entry): bool => ($entry['continue_at'] ?? null) !== null)
            // Filtered rather than cast: a filed series has no manga, and this map builds
            // the key everything downstream looks a series up by. `(int) null` is 0, which
            // would collide with a real id and answer "no resume" for the wrong series.
            ->filter(static fn (array $entry): bool => $entry['manga_id'] !== null)
            ->mapWithKeys(static fn (array $entry): array => [
                (int) $entry['manga_id'] => $entry['continue_at'],
            ])
            ->all();

        return $this->continue->feed($matching->all(), $resolutions);
    }

    /**
     * Whether anything is worth continuing, so an empty feed renders nothing at all.
     *
     * @param  Collection<int, array<string, mixed>>  $feed
     */
    public function hasContinueFeed(Collection $feed): bool
    {
        return $feed->isNotEmpty();
    }

    /**
     * Unread across everything in the library, for the page header.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     */
    public function totalUnread(Collection $entries): int
    {
        return (int) $entries->sum(fn (array $entry): int => (int) ($entry['unread_chapters'] ?? 0));
    }

    /**
     * The shelves one series is filed in, in the reader's shelf order.
     *
     * @param  Collection<int, array<string, mixed>>  $shelves
     * @param  array<int, array<int, int>>  $filings
     * @return array<int, array{id: int, name: string}>
     */
    public function filedShelves(Collection $shelves, array $filings, int $mangaId): array
    {
        $ids = $filings[$mangaId] ?? [];

        return $shelves
            ->filter(fn (array $one): bool => in_array($one['id'], $ids, true))
            ->map(fn (array $one): array => ['id' => (int) $one['id'], 'name' => (string) $one['name']])
            ->values()
            ->all();
    }

    /**
     * The shelves one series is not filed in.
     *
     * @param  Collection<int, array<string, mixed>>  $shelves
     * @param  array<int, array<int, int>>  $filings
     * @return array<int, array{id: int, name: string}>
     */
    public function unfiledShelves(Collection $shelves, array $filings, int $mangaId): array
    {
        $ids = $filings[$mangaId] ?? [];

        return $shelves
            ->reject(fn (array $one): bool => in_array($one['id'], $ids, true))
            ->map(fn (array $one): array => ['id' => (int) $one['id'], 'name' => (string) $one['name']])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $shelves
     */
    public function shelfName(Collection $shelves, int $shelfId): string
    {
        foreach ($shelves as $shelf) {
            if ((int) $shelf['id'] === $shelfId) {
                return (string) $shelf['name'];
            }
        }

        return __('Shelf');
    }
}
