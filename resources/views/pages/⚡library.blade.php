<?php

use App\Jobs\CheckLibrary;
use App\Library\ContinueReading;
use App\Library\CheckLedger;
use App\Library\CheckSchedule;
use App\Models\LibraryCheck;
use App\Support\Library;
use App\Support\ReadingState;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Library')] class extends Component {
    /**
     * How many entries one page holds.
     *
     * 60 because this is about images rather than rows. A library page renders a
     * proxied cover per entry, and 60 is roughly two screens of scrolling. Beyond
     * that the page stops feeling instant without showing anything the reader
     * could use, so the rest is behind paging.
     */
    public const PER_PAGE = 60;

    /** Current page. Reset whenever a filter changes, see updated(). */
    public int $page = 1;

    /** The shelf being viewed, or null for the whole library. */
    public ?int $shelf = null;

    /**
     * Sort order.
     *
     * `recent` is last read then date added, which is the order `entries()` already
     * returns. `unread` puts the series with the most waiting at the top, which is
     * the order a reader wants on the days they came here to read.
     */
    public string $sort = 'recent';

    /** Title search, matched against the source titles. */
    public string $search = '';

    /** Draft name for a new shelf. */
    public string $newShelfName = '';

    /** Shelf ids being renamed, to their draft names. */
    public array $renaming = [];


    public function updated(string $property): void
    {
        // Landing on page 7 of a result set that now has two pages looks like an
        // empty library, so every filter change returns to the start.
        if (in_array($property, ['search', 'sort', 'shelf'], true)) {
            $this->page = 1;
        }

        $this->normalise();
    }

    // Shelves ------------------------------------------------------------

    public function createShelf(): void
    {
        $name = trim($this->newShelfName);

        if ($name === '') {
            return;
        }

        $shelf = app(Library::class)->createCategory(auth()->user(), $name);

        $this->newShelfName = '';

        Flux::toast(
            text: __('File something into it from any series.'),
            heading: __('“:name” is ready', ['name' => $shelf->name]),
            variant: 'success',
        );
    }

    public function renameShelf(int $shelfId): void
    {
        $name = trim((string) ($this->renaming[$shelfId] ?? ''));

        if ($name === '') {
            return;
        }

        $renamed = app(Library::class)->renameCategory(auth()->user(), $shelfId, $name);

        $this->renaming = [];

        if ($renamed === null) {
            Flux::toast(text: __('That shelf is not yours.'), heading: __('Not renamed'), variant: 'danger');
        }
    }

    public function startRenaming(int $shelfId): void
    {
        $this->renaming = [$shelfId => $this->shelfName($shelfId)];
    }

    public function cancelRenaming(): void
    {
        $this->renaming = [];
    }

    /**
     * Delete a shelf. Its series stay in the library and become unfiled.
     *
     * Deleting a series instead would be a data loss the reader did not ask for:
     * they asked to remove a shelf, not to remove everything that was on it.
     */
    public function deleteShelf(int $shelfId): void
    {
        $name = $this->shelfName($shelfId);
        $deleted = app(Library::class)->deleteCategory(auth()->user(), $shelfId);

        $this->renaming = [];

        if ($this->shelf === $shelfId) {
            $this->shelf = null;
            $this->page = 1;
        }

        Flux::toast(
            text: $deleted
                ? __('Its series are still in your library, just unfiled.')
                : __('That shelf is not yours.'),
            heading: $deleted ? __('“:name” deleted', ['name' => $name]) : __('Not deleted'),
            variant: $deleted ? 'info' : 'danger',
        );
    }

    // Filing -------------------------------------------------------------

    /**
     * File or unfile one series into one shelf.
     *
     * Refuses silently for a shelf that is not the reader's, because the button is
     * rendered from their own shelves and a mismatch means the page is stale rather
     * than that the reader is doing something wrong.
     */
    public function toggleFiling(int $mangaId, int $shelfId): void
    {
        $library = app(Library::class);
        $state = app(ReadingState::class)->loadOrCreate(auth()->id(), $mangaId);

        $isFiled = in_array($shelfId, $library->categoryIdsFor(auth()->id(), (int) $state->id), true);

        if ($isFiled) {
            $library->detach(auth()->user(), $mangaId, $shelfId);

            Flux::toast(
                text: __('Moved to Unfiled.'),
                heading: __('Removed from “:name”', ['name' => $this->shelfName($shelfId)]),
                variant: 'info',
            );
        } else {
            $library->attach(auth()->user(), $mangaId, $shelfId);

            Flux::toast(
                text: __('Filed under “:name”.', ['name' => $this->shelfName($shelfId)]),
                heading: __('Added to a shelf'),
                variant: 'success',
            );
        }
    }

    /**
     * Whether a series is filed in a shelf.
     *
     * Reads the preloaded map rather than asking, for the reason the class docblock
     * gives. Called once per card per shelf from the view.
     */
    public function isFiled(int $mangaId, int $shelfId): bool
    {
        return in_array($shelfId, $this->filings[$mangaId] ?? [], true);
    }

    /**
     * The shelves one series is filed in, in the reader's shelf order.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function filedShelves(int $mangaId): array
    {
        $ids = $this->filings[$mangaId] ?? [];

        return $this->shelves
            ->filter(fn (array $one): bool => in_array($one['id'], $ids, true))
            ->map(fn (array $one): array => ['id' => $one['id'], 'name' => $one['name']])
            ->values()
            ->all();
    }

    /**
     * The shelves one series is not filed in.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function unfiledShelves(int $mangaId): array
    {
        $ids = $this->filings[$mangaId] ?? [];

        return $this->shelves
            ->reject(fn (array $one): bool => in_array($one['id'], $ids, true))
            ->map(fn (array $one): array => ['id' => $one['id'], 'name' => $one['name']])
            ->values()
            ->all();
    }

    public function shelfName(int $shelfId): string
    {
        foreach ($this->shelves as $shelf) {
            if ($shelf['id'] === $shelfId) {
                return $shelf['name'];
            }
        }

        return __('Shelf');
    }

    // Data ---------------------------------------------------------------

    /**
     * Every entry, before filtering and paging.
     *
     * Read as `$this->entries`, never as `$this->entries()`. A `#[Computed]`
     * method is memoised per request under its property name; calling it as a
     * method skips the cache and runs the body again. That mistake was live in
     * this file and cost 30 seconds: the view asks `isFiled()` once per card per
     * shelf, each of which re-ran this, and each run is a Suwayomi round trip.
     *
     * `ReadPathTest` cannot see it either, because 28 sequential HTTP calls to a
     * fake in the same test still finish. Only the live server times out.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function entries(): Collection
    {
        return app(Library::class)->entries(auth()->user());
    }

    /**
     * @return Collection<int, array{id: int, name: string, order: int, count: int}>
     */
    #[Computed]
    public function shelves(): Collection
    {
        return app(Library::class)->categories(auth()->user());
    }

    /**
     * The shelf, search and sort applied.
     *
     * A computed rather than a plain method for the same reason as `entries`: the
     * sort runs over the whole library and three things below read it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function matching(): Collection
    {
        $entries = $this->entries;

        if ($this->shelf !== null) {
            $entries = $entries->filter(
                fn (array $entry): bool => in_array($this->shelf, $this->filings[$entry['manga_id']] ?? [], true)
            );
        }

        $needle = trim($this->search);

        if ($needle !== '') {
            $needle = mb_strtolower($needle);

            $entries = $entries->filter(
                fn (array $entry): bool => str_contains(mb_strtolower((string) $entry['title']), $needle)
            );
        }

        return match ($this->sort) {
            'title' => $entries->sortBy(fn (array $entry): string => mb_strtolower((string) $entry['title']))->values(),
            'added' => $entries->sortByDesc(fn (array $entry): int => (int) ($entry['added_at']?->getTimestamp() ?? 0))->values(),
            /*
             * `unread_chapters` is null for a series nobody has fetched, and null
             * sorts to -1 so those land at the bottom. Putting them at the top
             * would fill the top of the page with the series this page cannot say
             * anything about yet.
             */
            'unread' => $entries
                ->sortByDesc(fn (array $entry): int => (int) ($entry['unread_chapters'] ?? -1))
                ->values(),
            // 'recent' needs no work: entries already arrives in last read then
            // date added order and re-sorting only risks disagreeing with it.
            default => $entries,
        };
    }

    /**
     * Which shelf each series in the library is in.
     *
     * One query for the whole library rather than one per card, which is the same
     * failure mode the class docblock describes one level up.
     *
     * Note what this reads: `entries`, not `matching`. Reading `matching` here makes
     * a cycle, because `matching` filters by shelf and so needs `filings` in order
     * to do it. Livewire resolves computed properties re-entrantly, so the inner
     * `filings` never produces a value and PHP reports the whole thing as an
     * undefined property. It reads exactly like a name collision and it is not, so
     * renaming the method changes nothing.
     *
     * @return array<int, array<int, int>>
     */
    #[Computed]
    public function filings(): array
    {
        $ids = $this->entries
            ->pluck('manga_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        return app(Library::class)->filingsFor(auth()->user(), $ids);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function visibleEntries(): Collection
    {
        return $this->matching->forPage($this->page, self::PER_PAGE)->values();
    }

    #[Computed]
    public function totalFiltered(): int
    {
        return $this->matching->count();
    }

    #[Computed]
    public function totalAll(): int
    {
        return $this->entries->count();
    }

    #[Computed]
    public function lastPage(): int
    {
        return max(1, (int) ceil($this->totalFiltered / self::PER_PAGE));
    }

    /**
     * Where this reader should pick up, most recently read first.
     *
     * Reads the `continue_at` that `Library::entries()` already resolved, so the feed
     * costs no call the page was not already making. Measured: the batch read that
     * renders the cards returns 575 chapter ids for 14 series in 539ms, and the read
     * set is local, so this is a join over rows already in hand.
     *
     * Six cards, not more, because the feed answers "what was I reading" and a reader
     * who wants the rest of their library is looking at the shelves below it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function continueFeed(): Collection
    {
        /*
         * Built from `matching`, not `entries`, so the shelf and search filters apply.
         *
         * A page filtered to one shelf that then offers a continue card for a series
         * outside that shelf contradicts itself, and two existing tests already assert
         * the stricter contract: filtering to a shelf must mean that series is absent
         * from the page entirely, not just from the grid.
         */
        $filtered = $this->matching;

        $resolutions = $filtered
            ->filter(static fn (array $entry): bool => ($entry['continue_at'] ?? null) !== null)
            ->mapWithKeys(static fn (array $entry): array => [
                (int) $entry['manga_id'] => $entry['continue_at'],
            ])
            ->all();

        return app(ContinueReading::class)->feed($filtered->all(), $resolutions);
    }

    /**
     * Whether anything is worth continuing, so an empty feed renders nothing at all.
     */
    #[Computed]
    public function hasContinueFeed(): bool
    {
        return $this->continueFeed->isNotEmpty();
    }

    /** Unread across everything in the library, for the page header. */
    #[Computed]
    public function totalUnread(): int
    {
        return (int) $this->entries->sum(fn (array $entry): int => (int) ($entry['unread_chapters'] ?? 0));
    }

    // Checking ----------------------------------------------------------

    /**
     * Check one series, inline.
     *
     * The per-card button, and deliberately not a job. Measured: one refresh is
     * 361ms to 1617ms with an 876ms median, so a reader watching a single card can
     * wait for the answer. A job here would mean a spinner for the same second and
     * a second code path, since {@see CheckLedger::check()} is already the shared
     * one.
     */
    public function checkSeries(int $mangaId): void
    {
        $row = app(CheckLedger::class)->check(auth()->user(), $mangaId, force: true);

        $this->report($row);

        $this->dispatch('$refresh');
    }

    /**
     * Queue a pass over everything due.
     *
     * A job rather than a loop, because a 300 series library is 4.4 minutes of
     * upstream requests at the measured 876ms median, and a request cannot last that
     * long. The job re-books itself in slices and the page polls the ledger, so
     * progress is visible throughout.
     *
     * Forced, because pressing the button is the reader asking now. The interval
     * still exists for the schedule-driven path; overriding it here would be
     * saying the button does nothing for a series checked an hour ago, which it
     * would.
     */
    public function checkLibrary(): void
    {
        /*
         * `app(CheckSchedule::class)->summary()` rather than the computed property.
         * A computed is exposed as its cached value, so `$this->checkSchedule` is the
         * array the method returns and calling `->summary()` on it is "Call to a
         * member function summary() on array".
         */
        $summary = app(CheckSchedule::class)->summary(auth()->user());
        $due = $summary['due'];

        if ($due === 0) {
            Flux::toast(
                text: trans_choice(
                    ':count series is up to date|:count series are up to date',
                    $summary['total'],
                    ['count' => $summary['total']],
                ),
                heading: __('Nothing to check'),
                variant: 'info',
            );

            return;
        }

        CheckLibrary::dispatch((int) auth()->id(), null, force: true);

        Flux::toast(
            text: trans_choice(
                ':count series will be checked|:count series will be checked',
                $due,
                ['count' => $due],
            ),
            heading: __('Checking'),
            variant: 'info',
        );

        $this->dispatch('$refresh');
    }

    /**
     * What one check found, said plainly.
     *
     * The new-chapter count leads, because that is what the reader pressed to
     * find out. A source with no chapters is a warning rather than a success, since
     * it means the check happened and there was nothing there, and those are
     * different things to a reader.
     */
    private function report(LibraryCheck $row): void
    {
        match ($row->state) {
            LibraryCheck::CHECKED => $row->hasNewChapters()
                ? Flux::toast(
                    text: trans_choice(
                        ':count new chapter|:count new chapters',
                        (int) $row->new_chapters,
                        ['count' => (int) $row->new_chapters],
                    ),
                    heading: __('Found something'),
                    variant: 'success',
                )
                : Flux::toast(
                    text: trans_choice(
                        ':count chapter, nothing new|:count chapters, nothing new',
                        (int) $row->chapter_count,
                        ['count' => (int) $row->chapter_count],
                    ),
                    heading: __('Checked'),
                    variant: 'info',
                ),
            LibraryCheck::NO_CHAPTERS => Flux::toast(
                text: __('The source had no chapters to return. It may not carry this series.'),
                heading: __('Nothing from this source'),
                variant: 'warning',
            ),
            default => Flux::toast(
                text: $row->error ?? __('The check did not complete. Nothing was recorded as checked.'),
                heading: __('Check failed'),
                variant: 'danger',
            ),
        };
    }

    /**
     * A check, which must never take the page down.
     *
     * The ledger throws for one case only: a manga id this reader has no state row
     * for, which means the id came from a stale page rather than from the library. A
     * reader who clicks a button on a page that has been open since yesterday should
     * be told that, not shown an error page.
     */
    public function tryCheckSeries(int $mangaId): void
    {
        try {
            $this->checkSeries($mangaId);
        } catch (\InvalidArgumentException $e) {
            Flux::toast(
                text: __('That series is no longer in your library. Reload the page to see the current list.'),
                heading: __('Nothing to check'),
                variant: 'danger',
            );
        }
    }

    // Reading ----------------------------------------------------------

    /**
     * Which series are due, and how the floor is set.
     *
     * @return array{total: int, due: int, never: int, skipped: int, interval_days: int}
     */
    #[Computed]
    public function checkSchedule(): array
    {
        return app(CheckSchedule::class)->summary(auth()->user());
    }

    /**
     * The latest check per series on this page, keyed by manga id.
     *
     * One query rather than one per card. Measured: every series had a null
     * `chapter_list_checked_at` before this existed, because nothing ever wrote one,
     * so the library's staleness badge could not fire at all. Recording the outcome
     * per series is what makes it reachable.
     *
     * @return array<int, LibraryCheck>
     */
    #[Computed]
    public function checks(): array
    {
        $ids = $this->visibleEntries
            ->pluck('manga_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return app(CheckLedger::class)->latestFor(auth()->user(), $ids);
    }

    /**
     * How many chapters arrived on the last check of a series.
     *
     * Null when it has never been checked or the check found nothing, which are
     * different from "zero new".
     */
    public function newChapters(int $mangaId): ?int
    {
        // `??` rather than `?->` on the array offset. A computed property holding an
        // array cannot be indexed with `?->`, so PHP reports "Undefined array key"
        // for every series that has not been checked, which is every series on a
        // fresh install and most of them in general.
        $row = $this->checks[$mangaId] ?? null;

        return $row?->new_chapters;
    }

    /**
     * Whether a series is due, for the card's own button.
     */
    public function isDue(int $mangaId): bool
    {
        $state = \App\Models\SeriesState::forUser((int) auth()->id())
            ->where('suwayomi_manga_id', $mangaId)
            ->first();

        return $state !== null && app(CheckSchedule::class)->isDue($state);
    }

    /**
     * Clamp anything that is not one of our options.
     *
     * All three are user input, whether from a URL or a stale page. A sort of
     * "title; drop table" must never reach the query builder, and a shelf
     * belonging to someone else must not become a filter.
     */
    private function normalise(): void
    {
        $this->page = max(1, $this->page);

        if (! in_array($this->sort, ['recent', 'added', 'title', 'unread'], true)) {
            $this->sort = 'recent';
        }

        if ($this->shelf !== null && ! $this->shelves->contains('id', $this->shelf)) {
            $this->shelf = null;
        }
    }
}; ?>

<div class="w-full space-y-6">
    {{--
        Checking.

        A pass over everything due, and a summary of what the last ones found. The
        button is disabled when nothing is due rather than hidden, because a reader
        who came here to check and finds no control cannot tell the difference between
        "nothing to do" and "not built yet".

        The count beside it is the whole point of the feature: 6 of 14 series had no
        chapter list at all, and each of those was showing "Chapters never fetched"
        because a series added from search has never been read from its source.
    --}}
    @if ($this->totalAll > 0)
        {{-- A plain row, not flux:section. That component does not exist in Flux 2, and a
             view:cache run is what proves it rather than the page, which renders the
             branch only when a library is non-empty. --}}
        <div class="flex flex-wrap items-center gap-3">
                <flux:button
                    wire:click="checkLibrary"
                    wire:loading.attr="disabled"
                    wire:target="checkLibrary"
                    variant="primary"
                    size="sm"
                    icon="arrow-path"
                    :disabled="$this->checkSchedule['due'] === 0"
                >
                    {{ trans_choice(
                        'Check :count series|Check :count series',
                        $this->checkSchedule['due'],
                        ['count' => $this->checkSchedule['due']],
                    ) }}
                </flux:button>

                <span class="text-sm text-zinc-500 dark:text-zinc-400" aria-live="polite">
                    @if ($this->checkSchedule['due'] === 0)
                        {{
                            trans_choice(
                                'All :count series checked|All :count series checked',
                                $this->checkSchedule['total'],
                                ['count' => $this->checkSchedule['total']],
                            )
                        }}
                    @elseif ($this->checkSchedule['never'] > 0)
                        {{
                            trans_choice(
                                ':count series to check, :never never fetched|:count series to check, :never never fetched',
                                $this->checkSchedule['due'],
                                [
                                    'count' => $this->checkSchedule['due'],
                                    'never' => $this->checkSchedule['never'],
                                ],
                            )
                        }}
                    @else
                        {{
                            trans_choice(
                                ':count series to check|:count series to check',
                                $this->checkSchedule['due'],
                                ['count' => $this->checkSchedule['due']],
                            )
                        }}
                    @endif
                </span>

                <span class="text-xs text-zinc-400 dark:text-zinc-500">
                    @if ($this->checkSchedule['skipped'] > 0)
                        {{
                            trans_choice(
                                ':skipped checked recently|:skipped checked recently',
                                $this->checkSchedule['skipped'],
                                ['skipped' => $this->checkSchedule['skipped']],
                            )
                        }}

                        @if ($this->checkSchedule['interval_days'] === 0)
                            {{ __('every pass checks everything') }}
                        @else
                            {{
                                trans_choice(
                                    'for at least :days day before again|for at least :days days before again',
                                    $this->checkSchedule['interval_days'],
                                    ['days' => $this->checkSchedule['interval_days']],
                                )
                            }}
                        @endif
                    @endif
                </span>
        </div>
    @endif

    {{-- Header ---------------------------------------------------------- --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading level="1" size="xl">{{ __('Library') }}</flux:heading>
            <flux:text size="sm" class="mt-1">
                @if ($this->totalAll === 0)
                    {{ __('Nothing added yet.') }}
                @elseif ($this->shelf !== null)
                    {{ trans_choice(
                        ':count series on this shelf|:count series on this shelf',
                        $this->totalFiltered,
                        ['count' => $this->totalFiltered]
                    ) }}
                @else
                    {{ trans_choice(
                        ':count series, :unread unread|:count series, :unread unread',
                        $this->totalAll,
                        ['count' => $this->totalAll, 'unread' => $this->totalUnread]
                    ) }}
                @endif
            </flux:text>
        </div>

        <flux:button variant="primary" href="{{ route('search') }}" icon="plus" wire:navigate>
            {{ __('Add series') }}
        </flux:button>
    </div>

    {{--
        Continue reading.

        The one thing a reader opens this page for, and it was missing: every card
        linked to the series page, so finding the right chapter meant looking for it.

        Each card links straight to the next unread chapter, ordered by last read so
        the top of the feed is whatever this reader was last doing. A series with
        everything read is absent rather than offered chapter one, which would be the
        one answer that is certainly wrong.

        Six cards, and no heading when there is nothing to continue, because an empty
        section with a title above it is worse than no section.
    --}}
    @if ($this->hasContinueFeed)
        <section aria-labelledby="continue-heading" class="space-y-3">
            <div class="flex items-baseline justify-between gap-3">
                <flux:heading id="continue-heading" size="sm" level="2">
                    {{ __('Continue reading') }}
                </flux:heading>

                <flux:text size="xs" class="text-zinc-500 dark:text-zinc-400">
                    {{ __('Your most recent, with the next chapter ready.') }}
                </flux:text>
            </div>

            <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                @foreach ($this->continueFeed as $entry)
                    @php($where = $entry['continue_at'])

                    <li>
                        <a
                            href="{{ route('reader.show', ['manga' => $entry['manga_id'], 'chapter' => $where['chapter_id']]) }}"
                            wire:navigate
                            class="group flex h-full flex-col overflow-hidden rounded-lg border border-zinc-200 bg-white transition hover:border-purple-400 hover:shadow-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-purple-500 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-purple-500"
                        >
                            @if ($entry['cover'] !== null)
                                <img
                                    src="{{ $entry['cover'] }}"
                                    alt=""
                                    loading="lazy"
                                    width="200"
                                    height="280"
                                    class="aspect-[5/7] w-full bg-zinc-100 object-cover dark:bg-zinc-800"
                                >
                            @else
                                {{-- No cover is reported rather than shown as a broken image. --}}
                                <div class="flex aspect-[5/7] w-full items-center justify-center bg-zinc-100 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                                    {{ __('No cover') }}
                                </div>
                            @endif

                            <div class="space-y-1 p-2.5">
                                <flux:text size="xs" class="line-clamp-2 font-medium leading-tight">
                                    {{ $entry['title'] !== '' ? $entry['title'] : __('Series :id', ['id' => $entry['manga_id']]) }}
                                </flux:text>

                                {{--
                                    The destination, named rather than numbered where the
                                    source has a name for it. Measured: two sources label
                                    one chapter `Chapter 1` and `Ch. 1`, so the label is
                                    not derivable from the position and is worth showing.
                                --}}
                                <flux:text size="xs" class="text-purple-600 dark:text-purple-400">
                                    @if ($where['chapter_name'] !== null && $where['chapter_name'] !== '')
                                        {{ $where['chapter_name'] }}
                                    @else
                                        {{ trans_choice(
                                            'Chapter :position|Chapter :position',
                                            $where['position'],
                                            ['position' => $where['position']],
                                        ) }}
                                    @endif
                                </flux:text>

                                <flux:text size="xs" class="text-zinc-500 dark:text-zinc-400">
                                    {{ trans_choice(
                                        'of :total, :read read|of :total, :read read',
                                        $where['total'],
                                        ['total' => $where['total'], 'read' => $where['read']],
                                    ) }}
                                </flux:text>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Shelves --------------------------------------------------------- --}}
    <flux:card class="p-4">
        <div class="flex flex-wrap items-center gap-2">
            <flux:button.group>
                <flux:button
                    :variant="$this->shelf === null ? 'primary' : 'outline'"
                    size="sm"
                    wire:click="$set('shelf', null)"
                >
                    {{ __('All') }}
                    <span class="ms-1 text-xs opacity-70">{{ $this->totalAll }}</span>
                </flux:button>

                @foreach ($this->shelves as $one)
                    <flux:button
                        :variant="$this->shelf === $one['id'] ? 'primary' : 'outline'"
                        size="sm"
                        wire:click="$set('shelf', {{ $one['id'] }})"
                    >
                        {{ $one['name'] }}
                        <span class="ms-1 text-xs opacity-70">{{ $one['count'] }}</span>
                    </flux:button>
                @endforeach
            </flux:button.group>

            <div class="ms-auto flex items-center gap-2">
                @if ($this->shelves->isNotEmpty())
                    <flux:dropdown placement="bottom-end">
                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" aria-label="{{ __('Manage shelves') }}"></flux:button>

                        <flux:menu>
                            @foreach ($this->shelves as $one)
                                <flux:menu.item wire:click="startRenaming({{ $one['id'] }})" icon="pencil">
                                    {{ __('Rename :name', ['name' => $one['name']]) }}
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                @endif

                <form wire:submit="createShelf" class="flex items-center gap-2">
                    <flux:input
                        wire:model="newShelfName"
                        size="sm"
                        placeholder="{{ __('New shelf') }}"
                        class="w-40"
                    />
                    <flux:button type="submit" variant="outline" size="sm" icon="plus" aria-label="{{ __('Create shelf') }}"></flux:button>
                </form>
            </div>
        </div>

        @if ($this->renaming !== [])
            <div class="mt-3 flex flex-wrap items-end gap-2 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                @foreach ($this->renaming as $shelfId => $draft)
                    <flux:input.group class="w-64">
                        <flux:input wire:model="renaming.{{ $shelfId }}" size="sm" />
                        <flux:button type="button" size="sm" variant="primary" icon="check"
                            wire:click="renameShelf({{ $shelfId }})"
                            aria-label="{{ __('Save shelf name') }}"></flux:button>
                    </flux:input.group>

                    <flux:button size="sm" variant="ghost" wire:click="cancelRenaming">{{ __('Cancel') }}</flux:button>

                    <flux:button size="sm" variant="danger" icon="trash"
                        wire:click="deleteShelf({{ $shelfId }})"
                        wire:confirm="{{ __('Delete this shelf? Its series stay in your library and become unfiled.') }}"
                    >
                        {{ __('Delete shelf') }}
                    </flux:button>
                @endforeach
            </div>
        @endif
    </flux:card>

    {{-- Filters --------------------------------------------------------- --}}
    @if ($this->totalAll > 0)
        <div class="flex flex-wrap items-center gap-3">
            <flux:input
                wire:model.live.debounce.300ms='search'
                placeholder="{{ __('Search your library') }}"
                icon="magnifying-glass"
                class="max-w-xs flex-1"
            />

            <flux:button.group>
                @foreach ([
                    'recent' => __('Recent'),
                    'unread' => __('Unread'),
                    'added' => __('Date added'),
                    'title' => __('Title'),
                ] as $value => $label)
                    <flux:button
                        :variant="$this->sort === $value ? 'primary' : 'outline'"
                        size="sm"
                        wire:click="$set('sort', '{{ $value }}')"
                    >{{ $label }}</flux:button>
                @endforeach
            </flux:button.group>
        </div>
    @endif

    {{-- Entries --------------------------------------------------------- --}}
    @if ($this->totalFiltered === 0)
        <flux:callout variant="secondary" icon="book-open">
            <flux:heading level="2" size="sm" class="mb-1">
                @if ($this->totalAll === 0)
                    {{ __('Your library is empty') }}
                @elseif ($this->search !== '')
                    {{ __('Nothing matches that search') }}
                @else
                    {{ __('This shelf is empty') }}
                @endif
            </flux:heading>
            <p class="text-sm">
                @if ($this->totalAll === 0)
                    {{ __('Add something from search and it will show up here with its progress.') }}
                @else
                    {{ __('Try a different search, or pick another shelf.') }}
                @endif
            </p>
            @if ($this->totalAll === 0)
                <flux:button class="mt-3" variant="primary" href="{{ route('search') }}" wire:navigate>
                    {{ __('Go to search') }}
                </flux:button>
            @endif
        </flux:callout>
    @else
        <ul class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
            @foreach ($this->visibleEntries as $entry)
                <li class="group relative">
                    <a
                        href="{{ route('series', ['manga' => $entry['manga_id']]) }}"
                        wire:navigate
                        class="block"
                    >
                        {{-- 2/3 held by the wrapper rather than the image, so a
                             cover that has not loaded still reserves its space and
                             the grid does not jump as the page fills. --}}
                        <div class="overflow-hidden rounded-lg bg-zinc-100 ring-1 ring-zinc-900/10 dark:bg-white/5 dark:ring-white/10">
                            @if ($entry['cover'] !== null)
                                <img
                                    src="{{ $entry['cover'] }}"
                                    alt=""
                                    loading="lazy"
                                    class="aspect-[2/3] w-full object-cover"
                                >
                            @else
                                <div class="flex aspect-[2/3] items-center justify-center text-zinc-300 dark:text-zinc-600">
                                    <flux:icon name="photo" class="size-8" />
                                </div>
                            @endif
                        </div>
                    </a>

                    {{--
                        Unread, and the staleness marker.

                        Plain spans rather than `flux:badge`, deliberately. A badge
                        carries a translucent surface token tuned for a page
                        background: measured, `color="zinc"` compiles to
                        oklab(0.715 / 0.15) with zinc-800 text. Over a dark cover
                        that is a 15% grey on near-black with dark grey text, which
                        is invisible. A control floating over artwork needs a
                        background chosen for that, not inherited from one.
                    --}}
                    @if ($entry['unread_chapters'] !== null && $entry['unread_chapters'] > 0)
                        <span
                            class="absolute start-1 top-1 rounded-md bg-zinc-950/85 px-1.5 py-0.5 text-xs font-semibold tabular-nums text-zinc-50 shadow-sm ring-1 ring-white/15 backdrop-blur-sm"
                            title="{{ trans_choice(':count chapter waiting|:count chapters waiting', $entry['unread_chapters'], ['count' => $entry['unread_chapters']]) }}"
                        >{{ $entry['unread_chapters'] }}</span>
                    @endif

                    @if ($entry['needs_refresh'])
                        <flux:tooltip content="{{ __('The chapter list has changed since it was last checked.') }}">
                            <span
                                class="absolute end-1 top-1 inline-flex items-center gap-0.5 rounded-md bg-amber-400 px-1.5 py-0.5 text-xs font-semibold text-amber-950 shadow-sm"
                            >
                                <flux:icon name="exclamation-triangle" class="size-3" />
                                {{ __('Stale') }}
                            </span>
                        </flux:tooltip>
                    @endif

                    {{--
                        New since the last check.

                        The number a reader opened this page for, and it is the only
                        place in the app that can say it. The library's own unread
                        count is total minus read, which is a standing figure; this is
                        the change since the reader last looked, which is why it earns
                        the second slot on the cover.

                        Null when the series has never been checked, or the check
                        predates a stored previous count. Neither is zero, and showing
                        a zero there would claim a certainty the data does not have.
                    --}}
                    @php($found = $this->newChapters((int) $entry['manga_id']))

                    @if ($found !== null && $found > 0)
                        <flux:tooltip
                            content="{{ trans_choice(
                                ':count chapter arrived on the last check|:count chapters arrived on the last check',
                                $found,
                                ['count' => $found],
                            ) }}"
                        >
                            <span
                                class="absolute end-1 top-1 inline-flex items-center gap-0.5 rounded-md bg-purple-500 px-1.5 py-0.5 text-xs font-semibold tabular-nums text-white shadow-sm"
                            >
                                +{{ $found }}
                            </span>
                        </flux:tooltip>
                    @endif

                    <flux:heading size="sm" class="mt-2 truncate" level="3">
                        <a href="{{ route('series', ['manga' => $entry['manga_id']]) }}" wire:navigate class="hover:underline">
                            {{ $entry['title'] !== '' ? $entry['title'] : __('Series :id', ['id' => $entry['manga_id']]) }}
                        </a>
                    </flux:heading>

                    <flux:text size="xs" class="truncate">
                        @if (! $entry['has_source_data'])
                            {{ __('Not on the server') }}
                        @elseif (! $entry['has_chapter_list'])
                            {{-- 
                                Honest, and it was the only thing this card could say.
                                Measured: 6 of 14 series on the dev library were in
                                this state, because a series added from search has
                                never been read from its source. A check makes this
                                line a real count.
                            --}}
                            {{ __('Chapters never fetched') }}
                        @elseif ($entry['unread_chapters'] === 0)
                            {{ trans_choice(':count chapter, all read|:count chapters, all read', $entry['total_chapters'], ['count' => $entry['total_chapters']]) }}
                        @else
                            {{ trans_choice(
                                ':read of :total read|:read of :total read',
                                $entry['total_chapters'],
                                ['read' => $entry['read_chapters'], 'total' => $entry['total_chapters']]
                            ) }}
                        @endif
                    </flux:text>

                    <flux:text size="xs" class="truncate opacity-70">
                        @if ($entry['has_source_data'])
                            {{ $entry['source_name'] }}@if ($entry['source_lang'] !== null) · {{ strtoupper($entry['source_lang']) }}@endif
                        @endif
                    </flux:text>

                    @if ($entry['last_read_at'] !== null)
                        <flux:text size="xs" class="truncate opacity-70">
                            {{ __('Read :when', ['when' => $entry['last_read_at']->diffForHumans()]) }}
                        </flux:text>
                    @endif

                    {{--
                        Filing.

                        The shelves it is in, as chips that remove themselves, and one
                        button for everything else. Showing every shelf on every card
                        was tried and it is unusable: with two shelves the grid is
                        two buttons taller per card and the eye has nothing to land
                        on. Mihon does the same thing.

                        The "+" is omitted when the series is in every shelf, because
                        a control that opens a menu with nothing in it is worse than
                        no control.
                    --}}
                    <div class="mt-1.5 flex flex-wrap items-center gap-1">
                        {{--
                            Check this one.

                            Only when it is due, so a checked series does not carry a
                            button that does nothing. The card's own check runs inline
                            rather than as a job, because one refresh is 361ms to
                            1617ms measured and a reader watching one card can wait for
                            that. The floor is bypassed deliberately: pressing it is the
                            reader asking now.
                        --}}
                        @if ($this->isDue((int) $entry['manga_id']))
                            <flux:button
                                size="xs"
                                variant="ghost"
                                icon="arrow-path"
                                wire:click="tryCheckSeries({{ $entry['manga_id'] }})"
                                wire:loading.attr="disabled"
                                wire:target="tryCheckSeries({{ $entry['manga_id'] }})"
                                title="{{ __('Check this series for new chapters now') }}"
                                aria-label="{{ __('Check :title for new chapters now', ['title' => $entry['title']]) }}"
                            >
                                {{ __('Check') }}
                            </flux:button>
                        @endif

                        @if ($this->shelves->isNotEmpty())
                            @php($filedHere = $this->filedShelves($entry['manga_id']))
                            @php($unfiledHere = $this->unfiledShelves($entry['manga_id']))

                            @foreach ($filedHere as $one)
                                <flux:button
                                    size="xs"
                                    variant="primary"
                                    wire:click="toggleFiling({{ $entry['manga_id'] }}, {{ $one['id'] }})"
                                    title="{{ __('Remove from :name', ['name' => $one['name']]) }}"
                                    aria-label="{{ __('Remove from :name', ['name' => $one['name']]) }}"
                                >
                                    {{ $one['name'] }}
                                </flux:button>
                            @endforeach

                            @if ($unfiledHere !== [])
                                <flux:dropdown placement="bottom-start" align="start">
                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        icon="plus"
                                        aria-label="{{ __('Add to a shelf') }}"
                                        title="{{ __('Add to a shelf') }}"
                                    ></flux:button>

                                    <flux:menu>
                                        @foreach ($unfiledHere as $one)
                                            <flux:menu.item
                                                wire:click="toggleFiling({{ $entry['manga_id'] }}, {{ $one['id'] }})"
                                            >
                                                {{ $one['name'] }}
                                            </flux:menu.item>
                                        @endforeach
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        @if ($this->lastPage > 1)
            <div class="flex items-center justify-between gap-3">
                <flux:text size="sm">
                    {{ __('Page :page of :total', ['page' => $this->page, 'total' => $this->lastPage]) }}
                </flux:text>

                <div class="flex items-center gap-2">
                    <flux:button size="sm" variant="outline" icon="chevron-left"
                        wire:click="setPage({{ $this->page - 1 }})"
                        :disabled="$this->page <= 1"
                        aria-label="{{ __('Previous page') }}"
                    ></flux:button>

                    <flux:button size="sm" variant="outline" icon="chevron-right"
                        wire:click="setPage({{ $this->page + 1 }})"
                        :disabled="$this->page >= $this->lastPage"
                        aria-label="{{ __('Next page') }}"
                    ></flux:button>
                </div>
            </div>
        @endif
    @endif
</div>