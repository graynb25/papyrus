<?php

use App\AniList\Tracking;
use App\Jobs\CheckLibrary;
use App\Library\CheckLedger;
use App\Library\CheckSchedule;
use App\Live\Library\LibraryFilter;
use App\Live\Library\LibraryPageData;
use App\Models\CheckRun;
use App\Models\LibraryCheck;
use App\Support\Library;
use App\Support\ReadingState;
use App\Suwayomi\SeriesGateway;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Library')] class extends Component
{
    /**
     * How many entries one page holds.
     *
     * The pipeline owns the number; this alias keeps the component's own constant
     * and its meaning in one place.
     */
    public const PER_PAGE = LibraryFilter::PER_PAGE;

    /** Current page. Reset whenever a filter changes, see updated(). */
    public int $page = 1;

    /**
     * The shelf being viewed, or null for the whole library.
     *
     * In the URL, which is what makes the shelf survive opening a series and coming back. It did
     * not, and the failure is the same one the series page's chapter order had: a reader picks
     * "Reading", opens the next chapter of something in it, comes back, and is in the whole library
     * again with nothing on screen saying a shelf had ever been chosen.
     *
     * The default is omitted from the URL, so an unfiltered library is still `/library` and not
     * `/library?shelf=`. Same rule as the search page's filters and the sources page's view: a
     * state that is not a choice should not be in the address.
     */
    #[Url(as: 'shelf', except: null)]
    public ?int $shelf = null;

    /**
     * Sort order.
     *
     * `recent` is last read then date added, which is the order `entries()` already
     * returns. `unread` puts the series with the most waiting at the top, which is
     * the order a reader wants on the days they came here to read.
     *
     * In the URL for the same reason as the shelf above it. `recent` is omitted so the default is
     * a clean `/library`.
     */
    #[Url(as: 'sort', except: 'recent')]
    public string $sort = 'recent';

    /**
     * Title search, matched against the source titles.
     *
     * In the URL as `q`, to match the search page so a link means the same thing wherever it is
     * read. `history: false` because this is bound with a 300ms debounce, and without it every
     * pause in typing pushes an entry the reader then has to walk back through.
     */
    #[Url(as: 'q', except: '', history: false)]
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

    /** The page's reads, kept out of this component so their shapes can be tested directly. */
    private LibraryPageData $data;

    /** The page's filter pipeline, shared with its direct tests. */
    private LibraryFilter $filter;

    /** The shelf and filing writes behind the bulk and shelf actions. */
    private Library $library;

    /** Read state written by the bulk and inline controls. */
    private ReadingState $readingState;

    /** The batch chapter read for a selection. */
    private SeriesGateway $seriesGateway;

    /** AniList pushes booked after a bulk read-state change. */
    private Tracking $tracking;

    /** The per-card check write. */
    private CheckLedger $checkLedger;

    /** The due counts behind the check bar. */
    private CheckSchedule $schedule;

    public function boot(
        LibraryPageData $data,
        LibraryFilter $filter,
        Library $library,
        ReadingState $readingState,
        SeriesGateway $seriesGateway,
        Tracking $tracking,
        CheckLedger $checkLedger,
        CheckSchedule $schedule,
    ): void {
        $this->data = $data;
        $this->filter = $filter;
        $this->library = $library;
        $this->readingState = $readingState;
        $this->seriesGateway = $seriesGateway;
        $this->tracking = $tracking;
        $this->checkLedger = $checkLedger;
        $this->schedule = $schedule;
    }

    public function updated(string $property): void
    {
        // Landing on page 7 of a result set that now has two pages looks like an
        // empty library, so every filter change returns to the start.
        if ($this->filter->resetsPage($property)) {
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

        foreach ($this->selected as $mangaId) {
            $this->library->attach(auth()->user(), (int) $mangaId, $shelfId);
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

        foreach ($this->selected as $mangaId) {
            $this->library->detach(auth()->user(), (int) $mangaId, $this->shelf);
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

        foreach ($this->seriesGateway->fetchMany($ids) as $mangaId => $node) {
            $chapterIds = array_map(
                static fn (mixed $chapter): int => (int) ($chapter['id'] ?? 0),
                $node['chapters']['nodes'] ?? [],
            );

            $this->readingState->markManyRead(auth()->user(), (int) $mangaId, $chapterIds, $read);

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
            $this->tracking->scheduleSync(auth()->user(), (int) $mangaId);

            if (! $read) {
                $this->readingState->setPointer(auth()->user(), (int) $mangaId, null);
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
            $this->readingState->setInLibrary(auth()->user(), (int) $mangaId, false);
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

        $shelf = $this->library->createCategory(auth()->user(), $name);

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

        $renamed = $this->library->renameCategory(auth()->user(), $shelfId, $name);

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
        $deleted = $this->library->deleteCategory(auth()->user(), $shelfId);

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
        $state = $this->readingState->loadOrCreate(auth()->id(), $mangaId);

        $isFiled = in_array($shelfId, $this->library->categoryIdsFor(auth()->id(), (int) $state->id), true);

        if ($isFiled) {
            $this->library->detach(auth()->user(), $mangaId, $shelfId);

            Flux::toast(
                text: __('Moved to Unfiled.'),
                heading: __('Removed from “:name”', ['name' => $this->shelfName($shelfId)]),
                variant: 'info',
            );
        } else {
            $this->library->attach(auth()->user(), $mangaId, $shelfId);

            Flux::toast(
                text: __('Filed under “:name”.', ['name' => $this->shelfName($shelfId)]),
                heading: __('Added to a shelf'),
                variant: 'success',
            );
        }
    }

    /**
     * The shelves one series is filed in, in the reader's shelf order.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function filedShelves(int $mangaId): array
    {
        return $this->data->filedShelves($this->shelves, $this->filings, $mangaId);
    }

    /**
     * The shelves one series is not filed in.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function unfiledShelves(int $mangaId): array
    {
        return $this->data->unfiledShelves($this->shelves, $this->filings, $mangaId);
    }

    public function shelfName(int $shelfId): string
    {
        return $this->data->shelfName($this->shelves, $shelfId);
    }

    // Data ---------------------------------------------------------------

    /**
     * Every entry, before filtering and paging.
     *
     * Read as `$this->entries`, never as `$this->entries()`. A `#[Computed]`
     * method is memoised per request under its property name; calling it as a
     * method skips the cache and runs the body again. That mistake was live in
     * this file and cost 30 seconds: the view asked a filing helper once per card
     * per shelf, each of which re-ran this, and each run is a Suwayomi round trip.
     *
     * `ReadPathTest` cannot see it either, because 28 sequential HTTP calls to a
     * fake in the same test still finish. Only the live server times out.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function entries(): Collection
    {
        return $this->data->entries();
    }

    /**
     * @return Collection<int, array{id: int, name: string, order: int, count: int}>
     */
    #[Computed]
    public function shelves(): Collection
    {
        return $this->data->shelves();
    }

    /**
     * The shelf, search and sort applied.
     *
     * A computed rather than a plain method for the same reason as `entries`: the
     * sort runs over the whole library and three things below read it. The pipeline
     * itself is {@see LibraryFilter}, which keeps its own direct tests.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function matching(): Collection
    {
        return $this->filter->matching(
            $this->entries,
            $this->filings,
            $this->shelf,
            $this->search,
            $this->sort,
        );
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
        return $this->data->filings($this->entries);
    }

    /**
     * Move to a page.
     *
     * `setPage` is the name the pagination buttons have always called, and the trait that
     * supplies it, `WithPagination`, has never been declared in this repository. The markup
     * arrived with the first commit and has thrown since: clicking either arrow raised
     * `MethodNotFoundException: Public method [setPage] not found on component`.
     *
     * Declared here rather than by importing the trait because this component paginates by
     * hand. `$page` is a typed property that `normalise()` clamps and `updated()` resets when a
     * filter changes. `WithPagination` keeps its own page state in `$paginators` and reads
     * `Paginator::resolveCurrentPage()`, so the trait would give the component two sources of
     * truth for one number and a reset that never fires.
     *
     * Clamped at both ends. The upper clamp is the one that earns its place: when the library
     * shrinks under the reader, page 9 of a result set that now has two pages renders an empty
     * grid, which reads as a broken library rather than as being past the end.
     */
    public function setPage(int $page): void
    {
        $this->page = max(1, min($page, (int) $this->lastPage));

        $this->normalise();
    }

    /**
     * Why the library has no covers and no chapter counts, when it does.
     *
     * The verdict from the read the page already made, not a fresh probe. Probing would
     * double the cost of the library page to learn something the fetch just learned, and the
     * library page is the most expensive page in the app.
     *
     * Taking the read here is what lets the banner sit above the grid. Livewire memoises a
     * computed for the request, so the grid's own read costs nothing extra.
     */
    #[Computed]
    public function sourceFailure(): ?string
    {
        return $this->data->sourceFailure($this->entries);
    }

    /**
     * Say once, on arrival at an empty library, that a library can be brought across.
     *
     * While the library is empty rather than once ever, and that is the whole design. A
     * "seen this" flag would need a column, a migration, and a rule for what a dismissal
     * means, all of it to answer a question the library already answers for free: an empty
     * library is the only state in which this is true.
     *
     * So the toast appears on arriving at an empty library and stops for ever the moment
     * the reader imports one series or searches one series. Nothing to clean up, and no
     * flag that can be set wrongly or survive the flag being cleared.
     *
     * `mount()` rather than the render, because a toast dispatched from the template would
     * fire on every poll as well. The library page only polls while a check is running and
     * an empty library has nothing to check, but relying on that is relying on a coincidence
     * between two unrelated decisions.
     */
    public function mount(): void
    {
        /*
         * Clamp whatever arrived in the address, before anything reads it.
         *
         * `normalise()` used to run only from `updated()` and `setPage()`, which is enough when
         * these three can only be set by clicking on the page. `#[Url]` means they can now also
         * arrive typed, and a shelf id belonging to somebody else, or a sort nobody offers, would
         * reach the page unvalidated.
         *
         * Without it, `/library?shelf=999` filtered the library down to nothing and said "This
         * shelf is empty" about a shelf the reader never had. A wrong answer about their own data,
         * which is worse than an empty page.
         */
        $this->normalise();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function visibleEntries(): Collection
    {
        return $this->filter->page($this->matching, $this->page);
    }

    #[Computed]
    public function totalFiltered(): int
    {
        return $this->data->totalFiltered($this->matching);
    }

    #[Computed]
    public function totalAll(): int
    {
        return $this->data->totalAll($this->entries);
    }

    #[Computed]
    public function lastPage(): int
    {
        return $this->data->lastPage($this->totalFiltered);
    }

    /**
     * Where this reader should pick up, most recently read first.
     *
     * Reads the `continue_at` that `Library::entries()` already resolved, so the feed
     * costs no call the page was not already making. Measured: the batch read that
     * renders the cards returns 575 chapter ids for 14 series in 539ms, and the read
     * set is local, so this is a join over rows already in hand.
     *
     * Five cards, not more, because the feed answers "what was I reading" and a reader
     * who wants the rest of their library is looking at the shelves below it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function continueFeed(): Collection
    {
        return $this->data->continueFeed($this->matching);
    }

    /**
     * Whether anything is worth continuing, so an empty feed renders nothing at all.
     */
    #[Computed]
    public function hasContinueFeed(): bool
    {
        return $this->data->hasContinueFeed($this->continueFeed);
    }

    /** Unread across everything in the library, for the page header. */
    #[Computed]
    public function totalUnread(): int
    {
        return $this->data->totalUnread($this->entries);
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
        $row = $this->checkLedger->check(auth()->user(), $mangaId, force: true);

        $this->report($row);

        $this->dispatch('$refresh');
    }

    /**
     * Queue a pass over everything due.
     *
     * A job rather than a loop, because a 300 series library is 4.4 minutes of
     * upstream requests at the measured 876ms median, and a request cannot last that
     * long. The job re-books itself in slices and the page polls the run, so
     * progress is visible throughout and survives a reload.
     *
     * Not forced, and the reason is the number on the button.
     *
     * The button counts what is due. A forced pass takes the whole library,
     * including series checked an hour ago, so the bar would say it was checking
     * 77 series while 867 requests ran, and the count that sent the reader here
     * would be a lie. A reader who wants one recently checked series now has
     * that card's own check, which forces exactly that one.
     */
    public function checkLibrary(): void
    {
        if ($this->checkRun?->isRunning()) {
            Flux::toast(
                text: __('A check is already running. Stop it first if you want to start a different one.'),
                heading: __('Already checking'),
                variant: 'info',
            );

            return;
        }

        /*
         * `$this->schedule->summary()` rather than the computed property.
         * A computed is exposed as its cached value, so `$this->checkSchedule` is the
         * array the method returns and calling `->summary()` on it is "Call to a
         * member function summary() on array".
         */
        $summary = $this->schedule->summary(auth()->user());
        $due = (int) $summary['due'];

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

        /*
         * The run exists before the job is dispatched, because the page has to
         * show a bar at zero in the instant after the press and the job needs an
         * id to keep advancing across slices. A reader who reloads in that
         * instant finds the same row rather than nothing.
         */
        $run = CheckRun::begin(auth()->user(), $due);

        CheckLibrary::dispatch(
            (int) auth()->id(),
            null,
            force: false,
            runId: (int) $run->getKey(),
        );

        unset($this->checkRun, $this->checkProgress);

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
     * Stop the running check.
     *
     * The flag is the job's signal, and the state changes here as well so the
     * page can say stopped immediately rather than waiting for a worker that
     * may not be there. Every series already checked wrote its own row and
     * moved its own clock, so nothing that has happened is undone; the job
     * simply stops before the next series.
     */
    public function stopLibraryCheck(): void
    {
        $run = $this->checkRun;

        if ($run === null || ! $run->isRunning()) {
            return;
        }

        $run->forceFill([
            'state' => CheckRun::CANCELLED,
            'cancelled_at' => now(),
            'finished_at' => now(),
        ])->save();

        unset($this->checkRun, $this->checkProgress);

        Flux::toast(
            text: __('Stopped after :done of :total series. Every series already checked keeps its result.', [
                'done' => number_format($run->checked),
                'total' => number_format($run->total),
            ]),
            heading: __('Stopped'),
            variant: 'info',
        );

        $this->dispatch('$refresh');
    }

    /**
     * How long a bulk check may report nothing before the page says the queue is not moving.
     *
     * The read class owns the number; this alias keeps the component's own constant
     * and its meaning in one place.
     */
    public const CHECK_STALL_SECONDS = LibraryPageData::CHECK_STALL_SECONDS;

    /**
     * The run the page reports, if there is one worth showing.
     *
     * A finished run stays for an hour, which covers a reload just after the last
     * slice and stops yesterday's result reading as today's. A running run stays
     * whatever its age, because a reader who opens the page mid-check should see
     * it and be able to stop it.
     */
    #[Computed]
    public function checkRun(): ?CheckRun
    {
        return $this->data->checkRun();
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
    #[Computed]
    public function checkProgress(): ?array
    {
        return $this->data->checkProgress($this->checkRun);
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
     * @return array{total: int, due: int, never: int, skipped: int, unreachable: int, interval_days: int}
     */
    #[Computed]
    public function checkSchedule(): array
    {
        return $this->data->checkSchedule();
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
        return $this->data->checks($this->visibleEntries);
    }

    /**
     * How many chapters arrived on the last check of a series.
     *
     * Null when it has never been checked or the check found nothing, which are
     * different from "zero new".
     */
    public function newChapters(int $mangaId): ?int
    {
        return $this->data->newChapters($this->checks, $mangaId);
    }

    /**
     * Whether a series is due, for the card's own button.
     */
    public function isDue(int $mangaId): bool
    {
        return $this->data->isDue($mangaId);
    }

    /**
     * Clamp anything that is not one of our options.
     *
     * All three are user input, whether from a URL or a stale page. The sort is
     * clamped by {@see LibraryFilter}; the page and shelf stay here because they
     * are component state and the shelf check reads this reader's own shelves.
     */
    private function normalise(): void
    {
        $this->page = max(1, $this->page);
        $this->sort = $this->filter->normaliseSort($this->sort);

        if ($this->shelf !== null && ! $this->shelves->contains('id', $this->shelf)) {
            $this->shelf = null;
        }
    }
}; ?>

<div class="w-full space-y-7 @if (count($this->selected) > 0) pb-28 @endif">
    @php($progress = $this->checkProgress)

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
                    :disabled="$this->checkSchedule['due'] === 0 || ($progress['running'] ?? false)"
                    class="group/check"
                >
                    @if ($progress['running'] ?? false)
                        {{ __('Checking...') }}
                    @elseif ($this->checkSchedule['due'] === 0)
                        {{ __('Nothing to check') }}
                    @else
                        {{ trans_choice(
                            'Check :count series|Check :count series',
                            $this->checkSchedule['due'],
                            ['count' => $this->checkSchedule['due']],
                        ) }}
                    @endif
                </flux:button>
            @endif

            <flux:button variant="primary" href="{{ route('search') }}" icon="plus" wire:navigate>
                {{ __('Add series') }}
            </flux:button>
        </x-slot:actions>
    </x-page.header>

        {{-- The server is not answering.

            The library read swallows a transport failure so that one stalled upstream cannot
            turn a page into an error page, and with the server stopped that rendered 867
            titles with no covers, no counts and no explanation. A reader cannot tell that from
            an empty library, and the address is here so they know what is not answering.

            There was already a "Not on the server" on each card, which is the right signal at
            the size of a card. 867 blank cards at once reads as a broken app rather than as a
            server that is off.
        --}}
        @if ($this->sourceFailure !== null)
            <div class="mt-3 flex flex-wrap items-center gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-400">
                <flux:icon name="exclamation-triangle" class="shrink-0" />

                <span>
                    {{ __('Papyrus cannot reach the manga server, so covers, chapter counts and new chapters are missing. Your series and what you have read are safe.') }}

                    <code class="font-mono text-xs">{{ config('papyrus.suwayomi.url') }}</code>
                </span>
            </div>
        @endif

        @if ($progress !== null)
            <div
                class="mt-3 rounded-lg border border-zinc-200 p-3 text-sm dark:border-white/10"
                @if ($progress['running'] && ! $progress['stalled'])
                    wire:poll.3s
                @endif
            >
                @if ($progress['stalled'])
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex flex-wrap items-center gap-2 text-amber-600 dark:text-amber-400">
                            <flux:icon name="exclamation-triangle" class="shrink-0" />

                            <span>
                                {{ __('The check has not reported anything for several minutes. That usually means nothing is processing this server queue.') }}

                                <code class="font-mono text-xs">php artisan queue:work</code>
                            </span>
                        </div>

                        <flux:button
                            wire:click="stopLibraryCheck"
                            wire:loading.attr="disabled"
                            wire:target="stopLibraryCheck"
                            variant="outline"
                            size="sm"
                        >
                            {{ __('Stop checking') }}
                        </flux:button>
                    </div>
                @elseif ($progress['running'])
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-zinc-600 dark:text-zinc-300" aria-live="polite">
                            {{
                                __('Checked :done of :total series, :pct percent.', [
                                    'done' => number_format($progress['done']),
                                    'total' => number_format($progress['total']),
                                    'pct' => $progress['percent'],
                                ])
                            }}
                        </span>

                        <div class="flex items-center gap-3">
                            <span class="tabular-nums text-zinc-500">{{ $progress['percent'] }}%</span>

                            <flux:button
                                wire:click="stopLibraryCheck"
                                wire:loading.attr="disabled"
                                wire:target="stopLibraryCheck"
                                variant="outline"
                                size="sm"
                            >
                                {{ __('Stop checking') }}
                            </flux:button>
                        </div>
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
                            style="width: {{ $progress['percent'] }}%"
                        ></div>
                    </div>
                @elseif ($progress['cancelled'])
                    <div class="flex flex-wrap items-center gap-2 text-zinc-600 dark:text-zinc-300">
                        <flux:icon name="stop-circle" class="shrink-0" />

                        <span aria-live="polite">
                            {{
                                __('Stopped after :done of :total series. Every series already checked keeps its result.', [
                                    'done' => number_format($progress['done']),
                                    'total' => number_format($progress['total']),
                                ])
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
                @elseif ($progress['failed'])
                    <div class="flex flex-wrap items-center gap-2 text-amber-600 dark:text-amber-400">
                        <flux:icon name="exclamation-triangle" class="shrink-0" />

                        <span aria-live="polite">
                            {{
                                __('The check stopped after :done of :total series. What was already checked is kept.', [
                                    'done' => number_format($progress['done']),
                                    'total' => number_format($progress['total']),
                                ])
                            }}

                            {{ __('The queue may not be processing this check.') }}

                            <code class="font-mono text-xs">php artisan queue:work</code>
                        </span>
                    </div>
                @else
                    <div class="flex flex-wrap items-center gap-2 text-emerald-600 dark:text-emerald-400">
                        <flux:icon name="check-circle" class="shrink-0" />

                        <span aria-live="polite">
                            @if ($progress['done'] >= $progress['total'])
                                {{
                                    trans_choice(
                                        'Checked all :count series.|Checked all :count series.',
                                        $progress['total'],
                                        ['count' => number_format($progress['total'])],
                                    )
                                }}
                            @else
                                {{
                                    __('Checked :done of :total series.', [
                                        'done' => number_format($progress['done']),
                                        'total' => number_format($progress['total']),
                                    ])
                                }}
                            @endif

                            @if ($progress['new'] > 0)
                                {{
                                    trans_choice(
                                        'Found :count new chapter.|Found :count new chapters.',
                                        $progress['new'],
                                        ['count' => number_format($progress['new'])],
                                    )
                                }}
                            @endif

                            @if ($progress['failed_series'] > 0)
                                {{
                                    trans_choice(
                                        ':count series could not be checked.|:count series could not be checked.',
                                        $progress['failed_series'],
                                        ['count' => number_format($progress['failed_series'])],
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
                            $this->checkSchedule['due'] > 0
                                ? 'bg-vermilion-500 animate-breathe'
                                : ($this->checkSchedule['unreachable'] > 0
                                    ? 'bg-amber-500'
                                    : 'bg-moss-500 animate-breathe')
                        }}"
                        aria-hidden="true"
                    ></span>

                    @if ($this->checkSchedule['due'] === 0)
                        {{-- A library of only unreachable series has nothing to call
                             up to date, and the amber line below is the whole answer. --}}
                        @if ($this->checkSchedule['unreachable'] > 0)
                            @if ($this->checkSchedule['skipped'] > 0)
                                {{
                                    trans_choice(
                                        ':count series with a source is up to date|:count series with a source are up to date',
                                        $this->checkSchedule['skipped'],
                                        ['count' => $this->checkSchedule['skipped']],
                                    )
                                }}
                            @endif
                        @else
                            {{
                                trans_choice(
                                    'All :count series are up to date|All :count series are up to date',
                                    $this->checkSchedule['total'],
                                    ['count' => $this->checkSchedule['total']],
                                )
                            }}
                        @endif
                    @elseif ($this->checkSchedule['never'] > 0)
                        {{
                            trans_choice(
                                ':count series is due for a check|:count series are due for a check',
                                $this->checkSchedule['due'],
                                ['count' => $this->checkSchedule['due']],
                            )
                        }}
                        {{
                            trans_choice(
                                ':count has never been fetched.|:count have never been fetched.',
                                $this->checkSchedule['never'],
                                ['count' => $this->checkSchedule['never']],
                            )
                        }}
                    @else
                        {{
                            trans_choice(
                                ':count series is due for a check|:count series are due for a check',
                                $this->checkSchedule['due'],
                                ['count' => $this->checkSchedule['due']],
                            )
                        }}
                    @endif
                </span>

                @if ($this->checkSchedule['skipped'] > 0)
                    <span class="text-xs text-zinc-400 dark:text-zinc-500">
                        @if ($this->checkSchedule['interval_days'] === 0)
                            {{
                                trans_choice(
                                    ':count was checked recently. Papyrus checks them on every pass.|:count were checked recently. Papyrus checks them on every pass.',
                                    $this->checkSchedule['skipped'],
                                    ['count' => $this->checkSchedule['skipped']],
                                )
                            }}
                        @else
                            {{
                                trans_choice(
                                    ':count was checked recently. Papyrus checks each one again after at least :days day.|:count were checked recently. Papyrus checks each one again after at least :days days.',
                                    $this->checkSchedule['skipped'],
                                    [
                                        'count' => $this->checkSchedule['skipped'],
                                        'days' => $this->checkSchedule['interval_days'],
                                    ],
                                )
                            }}
                        @endif
                    </span>
                @endif

                @if ($this->checkSchedule['unreachable'] > 0)
                    <span class="text-xs text-amber-700 dark:text-amber-300">
                        {{
                            trans_choice(
                                ':count series has no source and cannot be checked.|:count series have no source and cannot be checked.',
                                $this->checkSchedule['unreachable'],
                                ['count' => $this->checkSchedule['unreachable']],
                            )
                        }}
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

        Five cards, and no heading when there is nothing to continue, because an empty
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
                            aria-label="{{ __('Open :title', ['title' => $entry['title'] !== '' ? $entry['title'] : __('Series :id', ['id' => $entry['manga_id']])]) }}"
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
                aria-label="{{ __('Search your library') }}"
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
                        aria-label="{{ __('New shelf name') }}"
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
                        <flux:input wire:model="renaming.{{ $shelfId }}" size="sm" aria-label="{{ __('Rename shelf') }}" />
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
                    {{ __('Papyrus reads from sources. A source is an extension repository that holds manga; Papyrus ships with none, so the first step is to add one.') }}
                @else
                    {{ __('Try a different search, or pick another shelf.') }}
                @endif
            </p>

            @if ($this->totalAll === 0)
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('Add something from search and it will show up here with its progress.') }}
                </p>
            @endif

            @if ($this->totalAll === 0)
                {{--
                    Two ways in, because a reader arriving at an empty library has two
                    situations and the page was only offering one of them.

                    Most people here want one series. Some arrived from another reader app
                    with a whole library on their phone and nothing on this machine, and
                    searching for eight hundred series one at a time is not a thing anyone
                    is going to do.
                --}}
                <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                    <flux:button variant="primary" href="{{ route('sources') }}" wire:navigate icon="folder-git-2">
                        {{ __('Add a source') }}
                    </flux:button>

                    <flux:button variant="outline" href="{{ route('search') }}" wire:navigate icon="magnifying-glass">
                        {{ __('Go to search') }}
                    </flux:button>

                    <flux:button variant="outline" href="{{ route('import.edit') }}" wire:navigate icon="arrow-up-tray">
                        {{ __('Bring a library from another app') }}
                    </flux:button>
                </div>
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
                            aria-label="{{ __('Open :title', ['title' => $entry['title']]) }}"
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

                        Unread lives top-start in vermilion, new-since-check
                        top-end in the neutral on-art style, and stale
                        bottom-start in amber: three facts that can all be true
                        at once and must never fight for one corner.
                    --}}
                    @if ($entry['unread_chapters'] !== null && $entry['unread_chapters'] > 0)
                        <span
                            class="absolute start-1.5 top-1.5 rounded-md bg-vermilion-500 px-1.5 py-0.5 text-xs font-semibold tabular-nums text-zinc-950 shadow-sm"
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
                                class="absolute end-1.5 top-1.5 inline-flex items-center gap-0.5 rounded-md px-1.5 py-0.5 text-xs font-semibold tabular-nums on-art"
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
                                aria-label="{{ __('Check :title for new chapters now', ['title' => $entry['title'] !== '' ? $entry['title'] : __('Series :id', ['id' => $entry['manga_id']])]) }}"
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