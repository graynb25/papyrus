<?php

use App\Jobs\CheckLibrary;
use App\Library\CheckLedger;
use App\Library\CheckSchedule;
use App\Library\ContinueReading;
use App\Models\LibraryCheck;
use App\Support\Library;
use App\Support\ReadingState;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Library')] class extends Component
{
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

    /**
     * Whether selection mode is on.
     *
     * Off until asked, because 60 checkboxes on every card is noise for a reader
     * who came here to read. Mihon enters selection on long press; the web
     * equivalent is a control in the bar, and the mode turning off clears the
     * selection so stale ids cannot ride into a later action.
     */
    public bool $selecting = false;

    /**
     * The selected manga ids.
     *
     * Bound to the cards' checkboxes, deferred, so ticking costs no round trip.
     * The ids ride out with the action that spends them.
     *
     * @var array<int, int>
     */
    public array $selected = [];

    public function updated(string $property): void
    {
        // Landing on page 7 of a result set that now has two pages looks like an
        // empty library, so every filter change returns to the start.
        if (in_array($property, ['search', 'sort', 'shelf'], true)) {
            $this->page = 1;
        }

        $this->normalise();
    }

    // Selection ----------------------------------------------------------

    public function toggleSelecting(): void
    {
        $this->selecting = ! $this->selecting;
        $this->selected = [];
    }

    /**
     * Select every series currently on the page.
     *
     * A reader with 300 series and one shelf to file them into should not have
     * to walk a range for it. Merged with what is already selected rather than
     * replacing it, because selection outlives paging.
     */
    public function selectVisible(): void
    {
        $ids = $this->visibleEntries
            ->pluck('manga_id')
            /*
             * Nulls dropped, not cast. A series filed from a backup has no manga, and
             * `(int) null` is 0, which is a real id to everything downstream: it would
             * be filed onto a shelf, counted in the selection, and sent to a server
             * looking for manga 0. "Select visible" means the series the reader can act on.
             */
            ->filter(fn (mixed $id): bool => $id !== null)
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $this->selected = array_values(array_unique(array_merge($this->selected, $ids)));
    }

    /**
     * File every selected series into one shelf.
     *
     * The shelf is re-checked here rather than trusted from the menu: the menu
     * was rendered from this reader's shelves, but the request carries only the
     * id, and a stale page must not file into someone else's shelf.
     */
    public function fileSelected(int $shelfId): void
    {
        if (! $this->shelves->contains('id', $shelfId)) {
            Flux::toast(text: __('That shelf is not yours.'), heading: __('Not filed'), variant: 'danger');

            return;
        }

        $library = app(Library::class);

        foreach ($this->selected as $mangaId) {
            $library->attach(auth()->user(), (int) $mangaId, $shelfId);
        }

        $count = count($this->selected);

        // "Series" is number-invariant in English, so there is no plural to choose.
        Flux::toast(
            text: __('Filed :count series under “:name”.', ['count' => $count, 'name' => $this->shelfName($shelfId)]),
            heading: __('Filed'),
            variant: 'success',
        );

        $this->reset('selected');
        unset($this->filings);
    }

    /**
     * Take every selected series out of the shelf being viewed.
     *
     * Only offered under a shelf filter, because that is the only context where
     * "remove from the shelf" means something a reader can see.
     */
    public function unfileSelected(): void
    {
        if ($this->shelf === null) {
            return;
        }

        $library = app(Library::class);

        foreach ($this->selected as $mangaId) {
            $library->detach(auth()->user(), (int) $mangaId, $this->shelf);
        }

        $count = count($this->selected);

        Flux::toast(
            text: __('Moved to Unfiled.'),
            heading: __('Removed from “:name”', ['name' => $this->shelfName($this->shelf)]),
            variant: 'info',
        );

        $this->reset('selected');
        unset($this->filings);
    }

    /**
     * Mark every chapter of every selected series read or unread.
     *
     * The chapter ids come from one batch call, whatever the selection size:
     * the same call the library page already makes for its own cards, measured
     * flat at 246ms for 300 series. Marking read leaves the continue-reading
     * pointer alone, because a series with everything read leaves the feed on
     * its own; marking unread clears it, because a pointer onto a chapter the
     * reader has just declared unread would have the feed offer the middle of
     * a series they say they have not started.
     */
    public function markSelectedRead(bool $read = true): void
    {
        $ids = array_map('intval', $this->selected);

        if ($ids === []) {
            return;
        }

        $reading = app(ReadingState::class);

        foreach (app(\App\Suwayomi\SeriesGateway::class)->fetchMany($ids) as $mangaId => $node) {
            $chapterIds = array_map(
                static fn (mixed $chapter): int => (int) ($chapter['id'] ?? 0),
                $node['chapters']['nodes'] ?? [],
            );

            $reading->markManyRead(auth()->user(), (int) $mangaId, $chapterIds, $read);

            /*
             * The same push the series page books after marking chapters read there.
             * Without it, a reader who marks read from the grid has their AniList
             * progress silently go stale, because "mark all read" on a series page and
             * "mark these series read" from the library are the same statement about the
             * same data and only one of them told AniList. It is booked per series
             * because `scheduleSync` dedupes on (user, manga), so one call for the whole
             * selection would collapse to a single push. It returns early when the reader
             * has no AniList connection, so this costs a lookup per series and nothing
             * more.
             */
            app(\App\AniList\Tracking::class)->scheduleSync(auth()->user(), (int) $mangaId);

            if (! $read) {
                $reading->setPointer(auth()->user(), (int) $mangaId, null);
            }
        }

        $count = count($this->selected);

        Flux::toast(
            text: $read
                ? __(':count series marked read.', ['count' => $count])
                : __(':count series marked unread.', ['count' => $count]),
            heading: $read ? __('Marked read') : __('Marked unread'),
            variant: 'success',
        );

        $this->reset('selected');
    }

    /**
     * Take every selected series out of the library.
     *
     * Read state and filings are left in place, on the same reasoning as the
     * shelf delete: removal is reversible, deleting history is not.
     */
    public function removeSelected(): void
    {
        foreach ($this->selected as $mangaId) {
            app(ReadingState::class)->setInLibrary(auth()->user(), (int) $mangaId, false);
        }

        $count = count($this->selected);

        Flux::toast(
            text: trans_choice(
                '{1} Its chapters stay in your reading history.|[2,*] Their chapters stay in your reading history.',
                $count,
            ),
            heading: __('Removed from the library'),
            variant: 'info',
        );

        $this->reset('selected');
        $this->selecting = false;
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
            // Filtered rather than cast: a filed series has no manga, and this map builds
            // the key everything downstream looks a series up by. `(int) null` is 0, which
            // would collide with a real id and answer "no resume" for the wrong series.
            ->filter(static fn (array $entry): bool => $entry['manga_id'] !== null)
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
    /**
     * How many series were due when the reader pressed the button, or null when they have not.
     *
     * The denominator for the progress. Taken at press time rather than read live because a
     * fraction needs a fixed top: the whole point is that 853 stays 853 while the bottom
     * number climbs, and a denominator that moves with the numerator draws a bar that is always
     * full.
     */
    public ?int $checkingTotal = null;

    /** When the run started, so "has anything moved" is a question with an answer. */
    public ?string $checkingSince = null;

    /**
     * Whether the run looks finished.
     *
     * Null means "no run to report". True means `due` has reached zero, which is the job's own
     * termination condition: `CheckLibrary` re-books itself until nothing is due.
     */
    public ?bool $checkingDone = null;

    /**
     * How long a bulk check may report nothing before the page says the queue is not moving.
     *
     * Generous, because a slice is a time budget of 45 seconds and a whole run is fifteen of
     * them. Five minutes is about seven slices with nothing, which is not a slow worker.
     */
    public const CHECK_STALL_SECONDS = 300;

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

        /*
         * Remember where this run started.
         *
         * Two numbers and a time, and all three are needed. The total is the bar's top, the
         * time is what tells a stalled queue from a slow one, and neither survives a page
         * reload, which is why the page also treats "nothing was pressed here" as "say
         * nothing about a run in progress" rather than inventing progress.
         */
        $this->checkingTotal = $due;
        $this->checkingSince = now()->toIso8601String();
        $this->checkingDone = false;
        unset($this->checkProgress);

        Flux::toast(
            text: trans_choice(
                'Checking :count series. This takes about ten minutes for a large library.|Checking :count series. This takes about ten minutes for a large library.',
                $due,
                ['count' => number_format($due)],
            ),
            heading: __('Checking'),
            variant: 'info',
        );

        $this->dispatch('$refresh');
    }

    /**
     * Where the running check has got to.
     *
     * Null when no run was started from this page, so an ordinary visit says nothing about a
     * background run rather than claiming one it cannot see the start of.
     *
     * @return array{total: int, done: int, running: bool, stalled: bool, new: int}|null
     */
    #[Computed]
    public function checkProgress(): ?array
    {
        if ($this->checkingTotal === null || $this->checkingTotal === 0) {
            return null;
        }

        $summary = app(CheckSchedule::class)->summary(auth()->user());
        $counts = app(\App\Library\CheckLedger::class)->counts(auth()->user());

        $done = max(0, $this->checkingTotal - (int) $summary['due']);

        /*
         * Finished when nothing a pass could visit is due any more, which is the condition the
         * job itself uses to stop re-booking. Read here rather than set during a click, so a
         * run that finishes while the page is shut still reports as finished when it is next
         * opened.
         *
         * Series with no source cannot be checked at all and are excluded from `due` upstream,
         * so they cannot hold the bar open: without that, a library holding 195 of them would
         * sit at 77% for ever with no way to tell the remainder from a stall.
         */
        $running = (int) $summary['due'] > 0;

        $lastChecked = $counts['last_checked_at'];
        $since = $this->checkingSince === null ? null : Carbon::parse($this->checkingSince);

        /*
         * Three conditions, and the first is the one that was wrong first.
         *
         * The run has to be old enough. Without it, "nothing has been checked yet" is true in
         * the instant after the press, so every run reported itself stalled before it had done
         * anything: measured, pressing the button showed the queue warning rather than a bar at
         * zero. A run that has just started has not had time to prove anything either way.
         *
         * Then, something must have happened since it started, or nothing at all. A run whose
         * last check predates the press is not making progress on this run.
         */
        $stalled = $running
            && $since !== null
            && $since->lt(now()->subSeconds(self::CHECK_STALL_SECONDS))
            && ($lastChecked === null || $lastChecked->lt($since));

        return [
            'total' => $this->checkingTotal,
            'done' => min($done, $this->checkingTotal),
            'running' => $running,
            'stalled' => $stalled,
            'new' => (int) $counts['new'],
        ];
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
            // Nulls dropped rather than cast to 0, for the same reason as `selectVisible()`:
            // every entry in this ledger is keyed by a manga id, and 0 would be a lookup
            // for a manga that does not exist rather than an empty result.
            ->filter(fn (mixed $id): bool => $id !== null)
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

<div class="w-full space-y-7 @if (count($this->selected) > 0) pb-28 @endif">
    {{--
        Page hero.

        The check row lives here rather than in a card of its own: it is status
        information, not content, and it reads best as a quiet line under the
        title. The button is disabled when nothing is due rather than hidden,
        because a reader who came here to check and finds no control cannot tell
        the difference between "nothing to do" and "not built yet".
    --}}
    <x-page.header>
        <x-slot:eyebrow>{{ __('Good reading.') }}</x-slot:eyebrow>

        <x-slot:title>{{ __('Library') }}</x-slot:title>

        <x-slot:meta>
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
        </x-slot:meta>

        <x-slot:actions>
            @if ($this->totalAll > 0)
                <flux:button
                    wire:click="checkLibrary"
                    wire:loading.attr="disabled"
                    wire:target="checkLibrary"
                    variant="outline"
                    size="sm"
                    icon="arrow-path"
                    :disabled="$this->checkSchedule['due'] === 0"
                    class="group/check"
                >
                    {{ trans_choice(
                        'Check :count series|Check :count series',
                        $this->checkSchedule['due'],
                        ['count' => $this->checkSchedule['due']],
                    ) }}
                </flux:button>
            @endif

            <flux:button variant="primary" href="{{ route('search') }}" icon="plus" wire:navigate>
                {{ __('Add series') }}
            </flux:button>
        </x-slot:actions>
    </x-page.header>

        {{--
            A bulk check, while one is running here.

            The reader pressed a button, a toast said "Checking 853 series", and then nothing
            happened on screen for eleven minutes. No fraction, no count of what had been done,
            and nothing at all when it finished, so a working run and a dead one looked
            identical.

            `due` falls as series are checked, because a series stops being due the moment it
            has been, so the bar's bottom number is a real count rather than an animation.
        --}}
        @php($progress = $this->checkProgress)

        @if ($progress !== null)
            <div
                class="mt-3 rounded-lg border border-zinc-200 p-3 text-sm dark:border-white/10"
                @if ($progress['running'] && ! $progress['stalled'])
                    wire:poll.3s
                @endif
            >
                @if ($progress['stalled'])
                    <div class="flex flex-wrap items-center gap-2 text-amber-600 dark:text-amber-400">
                        <flux:icon name="exclamation-triangle" class="shrink-0" />

                        <span>
                            {{ __('The check has not reported anything for several minutes. That usually means nothing is processing this server queue.') }}

                            <code class="font-mono text-xs">php artisan queue:work</code>
                        </span>
                    </div>
                @elseif ($progress['running'])
                    @php($pct = (int) round($progress['done'] / max(1, $progress['total']) * 100))

                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-zinc-600 dark:text-zinc-300" aria-live="polite">
                            {{
                                __('Checked :done of :total series, :pct percent.', [
                                    'done' => number_format($progress['done']),
                                    'total' => number_format($progress['total']),
                                    'pct' => $pct,
                                ])
                            }}
                        </span>

                        <span class="tabular-nums text-zinc-500">{{ $pct }}%</span>
                    </div>

                    <div
                        class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"
                        role="progressbar"
                        aria-valuemin="0"
                        aria-valuemax="{{ $progress['total'] }}"
                        aria-valuenow="{{ $progress['done'] }}"
                    >
                        <div
                            class="h-full rounded-full bg-vermilion-500 transition-[width] duration-500"
                            style="width: {{ $pct }}%"
                        ></div>
                    </div>
                @else
                    <div class="flex flex-wrap items-center gap-2 text-emerald-600 dark:text-emerald-400">
                        <flux:icon name="check-circle" class="shrink-0" />

                        <span aria-live="polite">
                            {{
                                trans_choice(
                                    'Checked all :count series.|Checked all :count series.',
                                    $progress['total'],
                                    ['count' => number_format($progress['total'])],
                                )
                            }}

                            @if ($progress['new'] > 0)
                                {{
                                    trans_choice(
                                        'Found :count new chapter.|Found :count new chapters.',
                                        $progress['new'],
                                        ['count' => number_format($progress['new'])],
                                    )
                                }}
                            @endif
                        </span>
                    </div>
                @endif
            </div>
        @endif

        {{-- The count beside the check button is the whole point of the feature:
             6 of 14 series had no chapter list at all, and each of those was
             showing "Chapters never fetched" because a series added from search
             has never been read from its source. --}}
        @if ($this->totalAll > 0)
            <div class="relative mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400">
                <span class="inline-flex items-center gap-2" aria-live="polite">
                    <span
                        class="inline-block size-1.5 rounded-full {{
                            $this->checkSchedule['due'] === 0
                                ? 'bg-moss-500 animate-breathe'
                                : 'bg-vermilion-500 animate-breathe'
                        }}"
                        aria-hidden="true"
                    ></span>

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

                @if ($this->checkSchedule['skipped'] > 0)
                    <span class="text-xs text-zinc-400 dark:text-zinc-500">
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
                    </span>
                @endif
            </div>
        @endif

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
                <flux:heading id="continue-heading" size="sm" level="2" class="font-display font-semibold uppercase tracking-[0.14em] text-zinc-500 dark:text-zinc-400">
                    {{ __('Continue reading') }}
                </flux:heading>

                <flux:text size="xs" class="hidden text-zinc-400 dark:text-zinc-500 sm:block">
                    {{ __('Your most recent, with the next chapter ready.') }}
                </flux:text>
            </div>

            {{-- A rail, not a grid: the feed answers "what was I reading", and a
                 horizontal shelf keeps the answer one glance wide no matter how
                 many series it holds. The masked edges fade the ends out, and
                 snap makes one wheel nudge land on the next card. --}}
            <ul class="rail-scroll -mx-1 flex snap-x gap-3.5 px-1 pb-2 pt-1">
                @foreach ($this->continueFeed as $index => $entry)
                    @php($where = $entry['continue_at'])

                    <li class="w-40 shrink-0 sm:w-44" style="animation-delay: {{ $index * 0.06 }}s">
                        <a
                            href="{{ route('reader.show', ['manga' => $entry['manga_id'], 'chapter' => $where['chapter_id']]) }}"
                            wire:navigate
                            class="group flex h-full flex-col focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-vermilion-500"
                        >
                            <x-cover.art
                                :src="$entry['cover']"
                                :glow="true"
                                :tilt="true"
                                :vt="true"
                                :progress="($where['total'] ?? 0) > 0 ? (int) round(($where['read'] / max(1, $where['total'])) * 100) : null"
                                class="group"
                            >
                                <span class="absolute start-2 top-2 rounded-md px-1.5 py-0.5 text-[0.65rem] font-semibold uppercase tracking-wide on-art">
                                    {{ __('Resume') }}
                                </span>
                            </x-cover.art>

                            <div class="min-w-0 space-y-0.5 px-0.5 pt-2">
                                <flux:text size="xs" class="line-clamp-2 font-medium leading-snug text-zinc-800 dark:text-zinc-100">
                                    {{ $entry['title'] !== '' ? $entry['title'] : __('Series :id', ['id' => $entry['manga_id']]) }}
                                </flux:text>

                                {{--
                                    The destination, named rather than numbered where the
                                    source has a name for it. Measured: two sources label
                                    one chapter `Chapter 1` and `Ch. 1`, so the label is
                                    not derivable from the position and is worth showing.
                                --}}
                                <flux:text size="xs" class="truncate font-medium text-vermilion-600 dark:text-vermilion-400">
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

                                <flux:text size="xs" class="truncate text-zinc-500 dark:text-zinc-400">
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

    {{--
        The control bar.

        Shelves, search and sort are one bar: the reader's three ways of asking
        "show me less". Shelf chips act as tabs, the active one carries the
        accent, and everything that manages shelves lives behind the kebab so
        the bar stays one row tall.
    --}}
    <div class="flex flex-wrap items-center gap-2.5">
        @if ($this->totalAll > 0)
            <flux:input
                wire:model.live.debounce.300ms='search'
                placeholder="{{ __('Search your library') }}"
                icon="magnifying-glass"
                class="w-full max-w-64 sm:order-3 sm:w-56"
            />

            <div class="flex flex-1 flex-wrap items-center gap-1.5 sm:order-1">
                <flux:button
                    :variant="$this->shelf === null ? 'primary' : 'ghost'"
                    size="sm"
                    wire:click="$set('shelf', null)"
                    class="rounded-full! px-3.5!"
                >
                    {{ __('All') }}
                    <span class="ms-1.5 text-xs opacity-70 tabular-nums">{{ $this->totalAll }}</span>
                </flux:button>

                @foreach ($this->shelves as $one)
                    <flux:button
                        :variant="$this->shelf === $one['id'] ? 'primary' : 'ghost'"
                        size="sm"
                        wire:click="$set('shelf', {{ $one['id'] }})"
                        class="rounded-full! px-3.5!"
                    >
                        {{ $one['name'] }}
                        <span class="ms-1.5 text-xs opacity-70 tabular-nums">{{ $one['count'] }}</span>
                    </flux:button>
                @endforeach

                @if ($this->shelves->isNotEmpty())
                    <flux:dropdown placement="bottom-start">
                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" aria-label="{{ __('Manage shelves') }}" class="rounded-full!"></flux:button>

                        <flux:menu>
                            @foreach ($this->shelves as $one)
                                <flux:menu.item wire:click="startRenaming({{ $one['id'] }})" icon="pencil">
                                    {{ __('Rename :name', ['name' => $one['name']]) }}
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                @endif

                <form wire:submit="createShelf" class="flex items-center gap-1.5">
                    <flux:input
                        wire:model="newShelfName"
                        size="sm"
                        placeholder="{{ __('New shelf') }}"
                        class="w-36"
                    />
                    <flux:button type="submit" variant="ghost" size="sm" icon="plus" aria-label="{{ __('Create shelf') }}" class="rounded-full!"></flux:button>
                </form>
            </div>

            <flux:button.group class="sm:order-2">
                @foreach ([
                    'recent' => __('Recent'),
                    'unread' => __('Unread'),
                    'added' => __('Date added'),
                    'title' => __('Title'),
                ] as $value => $label)
                    <flux:button
                        :variant="$this->sort === $value ? 'primary' : 'ghost'"
                        size="sm"
                        wire:click="$set('sort', '{{ $value }}')"
                    >{{ $label }}</flux:button>
                @endforeach
            </flux:button.group>

            {{--
                Selection mode.

                Off until asked: 60 checkboxes on every card is noise for a reader
                who came here to read. Leaving the mode clears the selection, so
                ids ticked an hour ago cannot ride into a later action.
            --}}
            <flux:button
                :variant="$selecting ? 'primary' : 'outline'"
                size="sm"
                wire:click="toggleSelecting"
                aria-pressed="{{ $selecting ? 'true' : 'false' }}"
                class="sm:order-2"
            >
                {{ $selecting ? __('Stop selecting') : __('Select') }}
            </flux:button>

            @if ($selecting)
                <flux:button size="sm" variant="outline" wire:click="selectVisible" class="sm:order-2">
                    {{ __('Select page') }}
                </flux:button>
            @endif
        @endif

        @if ($this->renaming !== [])
            <div class="flex w-full flex-wrap items-end gap-2 border-t border-zinc-200 pt-3 dark:border-white/[0.06]">
                @foreach ($this->renaming as $shelfId => $draft)
                    <flux:input.group class="w-64">
                        <flux:input wire:model="renaming.{{ $shelfId }}" size="sm" />
                        <flux:button type="button" size="sm" variant="primary" icon="check"
                            wire:click="renameShelf({{ $shelfId }})"
                            aria-label="{{ __('Save shelf name') }}"></flux:button>
                    </flux:input.group>

                    <flux:button size="sm" variant="ghost" wire:click="cancelRenaming">{{ __('Cancel') }}</flux:button>

                    <x-confirm
                        action="deleteShelf({{ $shelfId }})"
                        :title="__('Delete this shelf?')"
                        :body="__('Its series stay in your library and become unfiled.')"
                        :confirm="__('Delete shelf')"
                    >
                        <flux:button size="sm" variant="danger" icon="trash">
                            {{ __('Delete shelf') }}
                        </flux:button>
                    </x-confirm>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Entries --------------------------------------------------------- --}}
    @if ($this->totalFiltered === 0)
        {{--
            The empty room. Centered and quiet rather than a callout in the
            flow: this screen is either a reader's first impression of the
            whole app or a moment of "where did it go", and both deserve a
            composed answer. The strings are load-bearing for tests, so they
            stay exactly as they were; only the room around them changed.
        --}}
        <div class="mx-auto max-w-md py-16 text-center sm:py-24">
            <span class="seal mx-auto size-14" aria-hidden="true">
                <flux:icon name="book-open" class="size-6" />
            </span>

            <p class="serif-italic mt-6 text-xl text-zinc-500 dark:text-zinc-400">
                @if ($this->totalAll === 0)
                    {{ __('Your library is empty') }}
                @elseif ($this->search !== '')
                    {{ __('Nothing matches that search') }}
                @else
                    {{ __('This shelf is empty') }}
                @endif
            </p>

            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                @if ($this->totalAll === 0)
                    {{ __('Add something from search and it will show up here with its progress.') }}
                @else
                    {{ __('Try a different search, or pick another shelf.') }}
                @endif
            </p>

            @if ($this->totalAll === 0)
                <flux:button class="mt-6" variant="primary" href="{{ route('search') }}" wire:navigate icon="magnifying-glass">
                    {{ __('Go to search') }}
                </flux:button>
            @endif
        </div>
    @else
        <ul class="stagger-enter grid grid-cols-2 gap-x-4 gap-y-7 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6" data-bulk-grid>
            @foreach ($this->visibleEntries as $index => $entry)
                <li class="group relative">
                    {{--
                        A series filed from a backup has no Suwayomi manga, so there is no
                        series page to link to.

                        `route('series', ['manga' => null])` is a UrlGenerationException, not a
                        missing href: the route has a required parameter and Laravel throws when
                        it is absent rather than producing a broken URL. Measured in the log the
                        first time a library page rendered one of these, which is 195 of 867
                        series on a real import. So the link is conditional, and the cover is
                        still rendered either way to hold the grid's shape.
                    --}}
                    @if ($entry['manga_id'] !== null)
                        <a
                            href="{{ route('series', ['manga' => $entry['manga_id']]) }}"
                            wire:navigate
                            class="block"
                            tabindex="-1"
                            aria-hidden="true"
                        >
                    @else
                        <div class="block">
                    @endif
                        {{-- 2/3 held by the frame rather than the image, so a
                             cover that has not loaded still reserves its space and
                             the grid does not jump as the page fills. --}}
                        <x-cover.art
                            :src="$entry['cover']"
                            :glow="true"
                            :tilt="true"
                            :vt="true"
                            :progress="($entry['total_chapters'] ?? 0) > 0 && ($entry['unread_chapters'] ?? null) !== null && (($entry['read_chapters'] / max(1, $entry['total_chapters'])) * 100) >= 1 ? (int) round(($entry['read_chapters'] / max(1, $entry['total_chapters'])) * 100) : null"
                        ></x-cover.art>
                    @if ($entry['manga_id'] !== null)
                        </a>
                    @else
                        </div>
                    @endif

                    {{--
                        Unread, stale, and new-since-check badges.

                        Plain spans rather than `flux:badge`, deliberately. A badge
                        carries a translucent surface token tuned for a page
                        background: measured, `color="zinc"` compiles to
                        oklab(0.715 / 0.15) with zinc-800 text. Over a dark cover
                        that is a 15% grey on near-black with dark grey text, which
                        is invisible. A control floating over artwork needs a
                        background chosen for that, not inherited from one.

                        Unread lives top-end, new top-start, stale bottom-start:
                        three facts that can all be true at once and must never
                        fight for one corner.
                    --}}
                    @if ($entry['unread_chapters'] !== null && $entry['unread_chapters'] > 0)
                        <span
                            class="absolute end-1.5 top-1.5 rounded-md px-1.5 py-0.5 text-xs font-semibold tabular-nums on-art"
                            title="{{ trans_choice(':count chapter waiting|:count chapters waiting', $entry['unread_chapters'], ['count' => $entry['unread_chapters']]) }}"
                        >{{ $entry['unread_chapters'] }}</span>
                    @endif

                    {{-- Null for a filed series: there is no server manga to have new chapters on, and
                         casting null to 0 would ask about a manga that does not exist. --}}
                    @php($found = $entry['manga_id'] === null ? null : $this->newChapters($entry['manga_id']))

                    @if ($found !== null && $found > 0)
                        <flux:tooltip
                            content="{{ trans_choice(
                                ':count chapter arrived on the last check|:count chapters arrived on the last check',
                                $found,
                                ['count' => $found],
                            ) }}"
                        >
                            <span
                                class="absolute start-1.5 top-1.5 inline-flex items-center gap-0.5 rounded-md bg-vermilion-500 px-1.5 py-0.5 text-xs font-semibold tabular-nums text-zinc-950 shadow-sm"
                            >
                                +{{ $found }}
                            </span>
                        </flux:tooltip>
                    @endif

                    @if ($entry['needs_refresh'])
                        <flux:tooltip content="{{ __('The chapter list has changed since it was last checked.') }}">
                            <span
                                class="absolute bottom-2.5 start-1.5 inline-flex items-center gap-0.5 rounded-md bg-amber-400 px-1.5 py-0.5 text-xs font-semibold text-amber-950 shadow-sm"
                            >
                                <flux:icon name="exclamation-triangle" class="size-3" />
                                {{ __('Stale') }}
                            </span>
                        </flux:tooltip>
                    @endif

                    {{--
                        The selection checkbox, in selection mode only.

                        The label is the hit area: a bare 20px box is a finger's
                        worth of miss. It sits outside the cover's anchor, because
                        a control inside a link navigates instead of toggling.

                        Not offered for a filed series. Every bulk action is "ask
                        the server about this manga", and there is no manga, so a
                        checkbox that appears to work and then does nothing is
                        worse than no checkbox.
                    --}}
                    @if ($selecting && $entry['manga_id'] !== null)
                        <label
                            class="absolute bottom-0 end-0 z-20 flex h-12 w-12 cursor-pointer items-center justify-center"
                        >
                            <input
                                type="checkbox"
                                wire:model.live="selected"
                                value="{{ $entry['manga_id'] }}"
                                data-bulk-index="{{ $index }}"
                                class="size-5 rounded accent-vermilion-600"
                                aria-label="{{ __('Select :title', ['title' => $entry['title'] !== '' ? $entry['title'] : __('Series :id', ['id' => $entry['manga_id']])]) }}"
                            >
                        </label>
                    @endif

                    <flux:heading size="sm" class="mt-2.5 truncate font-display font-semibold" level="3">
                        @if ($entry['manga_id'] !== null)
                            <a href="{{ route('series', ['manga' => $entry['manga_id']]) }}" wire:navigate class="transition-colors hover:text-vermilion-600 dark:hover:text-vermilion-400">
                                {{ $entry['title'] !== '' ? $entry['title'] : __('Series :id', ['id' => $entry['manga_id']]) }}
                            </a>
                        @else
                            {{-- Plain text, because a link here throws: the series route needs a
                                 manga id and this series has none. The title is the whole point
                                 of filing it, so it is still shown. --}}
                            <span>{{ $entry['title'] !== '' ? $entry['title'] : __('A series from your old app') }}</span>
                        @endif
                    </flux:heading>

                    <flux:text size="xs" class="mt-0.5 truncate text-zinc-500 dark:text-zinc-400">
                        @if (! $entry['has_source_data'])
                            {{--
                                The reason is shown rather than the generic sentence, because
                                "Not on the server" describes a state and a reader wants to know
                                whether to do something about it. A series filed from a backup
                                knows why: its source is not installed here.
                            --}}
                            {{ $entry['unavailable_reason'] ?? __('Not on the server') }}
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

                    <flux:text size="xs" class="truncate text-zinc-400 dark:text-zinc-500">
                        @if ($entry['has_source_data'])
                            {{ $entry['source_name'] }}@if ($entry['source_lang'] !== null) · {{ strtoupper($entry['source_lang']) }}@endif
                        @endif
                    </flux:text>

                    @if ($entry['last_read_at'] !== null)
                        <flux:text size="xs" class="truncate text-zinc-400 dark:text-zinc-500">
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
                            1617ms measured and a reader watching one card can wait
                            for that. The floor is bypassed deliberately: pressing it
                            is the reader asking now.
                        --}}
                        {{-- The actions a filed series cannot offer.

                            Checking asks the server for new chapters and filing puts a
                            series on a shelf, and both need a manga id this series does
                            not have. Rendering the buttons with an empty id would call
                            `tryCheckSeries()` with no argument at all, so these are
                            omitted rather than disabled: a control that cannot work
                            should not be offered.
                        --}}
                        @if ($entry['manga_id'] !== null && $this->isDue((int) $entry['manga_id']))
                            <flux:button
                                size="xs"
                                variant="ghost"
                                icon="arrow-path"
                                wire:click="tryCheckSeries({{ $entry['manga_id'] }})"
                                wire:loading.attr="disabled"
                                wire:target="tryCheckSeries({{ $entry['manga_id'] }})"
                                title="{{ __('Check this series for new chapters now') }}"
                                aria-label="{{ __('Check :title for new chapters now', ['title' => $entry['title']]) }}"
                                class="rounded-full!"
                            >
                                {{ __('Check') }}
                            </flux:button>
                        @endif

                        @if ($entry['manga_id'] !== null && $this->shelves->isNotEmpty())
                            @php($filedHere = $this->filedShelves($entry['manga_id']))
                            @php($unfiledHere = $this->unfiledShelves($entry['manga_id']))

                            @foreach ($filedHere as $one)
                                <flux:button
                                    size="xs"
                                    variant="ghost"
                                    wire:click="toggleFiling({{ $entry['manga_id'] }}, {{ $one['id'] }})"
                                    title="{{ __('Remove from :name', ['name' => $one['name']]) }}"
                                    aria-label="{{ __('Remove from :name', ['name' => $one['name']]) }}"
                                    class="rounded-full! border border-zinc-200 dark:border-white/[0.08]"
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
                                        class="rounded-full! text-zinc-400 dark:text-zinc-500"
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
            <div class="flex items-center justify-between gap-3 border-t border-zinc-200/80 pt-5 dark:border-white/[0.06]">
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
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

    {{--
        The bulk action bar.

        Fixed to the bottom edge because the actions belong to the selection,
        not to any card, and a selection can span two screens of grid. It is
        the only fixed element on this page, so there is nothing for it to
        cover; the padding below keeps the last row of cards reachable while
        it is up.
    --}}
    @if (count($this->selected) > 0)
        <div class="fixed inset-x-0 bottom-0 z-40 animate-fade-up border-t border-zinc-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur dark:border-white/10 dark:bg-zinc-950/95">
            <div class="mx-auto flex w-full flex-wrap items-center justify-center gap-2 px-4 py-3">
                <span class="me-1 text-sm font-medium tabular-nums">
                    {{ trans_choice('{1} :count selected|[2,*] :count selected', count($this->selected), ['count' => count($this->selected)]) }}
                </span>

                @if ($this->shelves->isNotEmpty())
                    <flux:dropdown placement="top-start">
                        <flux:button size="sm" variant="outline" icon="plus">{{ __('File to shelf') }}</flux:button>

                        <flux:menu>
                            @foreach ($this->shelves as $one)
                                <flux:menu.item wire:click="fileSelected({{ $one['id'] }})">
                                    {{ $one['name'] }}
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                @endif

                @if ($this->shelf !== null)
                    <flux:button size="sm" variant="outline" wire:click="unfileSelected">
                        {{ __('Remove from this shelf') }}
                    </flux:button>
                @endif

                <flux:button size="sm" variant="outline" icon="check" wire:click="markSelectedRead(true)">
                    {{ __('Mark read') }}
                </flux:button>

                <flux:button size="sm" variant="outline" icon="arrow-uturn-left" wire:click="markSelectedRead(false)">
                    {{ __('Mark unread') }}
                </flux:button>

                <x-confirm
                    action="removeSelected"
                    :title="__('Remove from the library?')"
                    :body="trans_choice(
                        'Remove :count series from the library? Filings and reading history stay.',
                        count($this->selected),
                        ['count' => count($this->selected)],
                    )"
                    :confirm="__('Remove')"
                >
                    <flux:button size="sm" variant="danger" icon="trash">
                        {{ __('Remove') }}
                    </flux:button>
                </x-confirm>

                <flux:button size="sm" variant="ghost" wire:click="$set('selected', [])">
                    {{ __('Clear') }}
                </flux:button>
            </div>
        </div>
    @endif

    {{--
        Shift-click range select.

        Plain JS on the document, deliberately: delegation survives every Livewire
        morph, where a listener on the grid itself would die with the element it
        was bound to, and a global flag keeps wire:navigate from binding it twice.
        The boxes are Livewire-bound and deferred, so the batch of programmatic
        clicks a range produces costs no round trip until the action itself.
    --}}
    <script>
        (() => {
            if (window.__papyrusBulkRangeBound === true) return;
            window.__papyrusBulkRangeBound = true;

            let last = null;

            document.addEventListener('click', (event) => {
                /*
                 * The listener sits on the document, so event.target is not
                 * necessarily an Element. A click landing on the document
                 * itself has no closest(), and calling it throws inside the
                 * listener. One instanceof is cheaper than a stack trace.
                 */
                if (!(event.target instanceof Element)) {
                    return;
                }

                const box = event.target.closest('input[data-bulk-index]');

                if (box === null || box.closest('[data-bulk-grid]') === null) {
                    return;
                }

                const index = Number(box.dataset.bulkIndex);

                if (event.shiftKey && last !== null && last !== index) {
                    const [from, to] = last < index ? [last, index] : [index, last];
                    const target = box.checked;

                    document.querySelectorAll('[data-bulk-grid] input[data-bulk-index]').forEach((one) => {
                        const at = Number(one.dataset.bulkIndex);

                        if (at >= from && at <= to && one.checked !== target) {
                            one.click();
                        }
                    });
                }

                last = index;
            });
        })();
    </script>
</div>