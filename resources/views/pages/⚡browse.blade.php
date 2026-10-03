<?php

use App\Models\SeriesState;
use App\Models\Source;
use App\Search\SourceSearcher;
use App\Support\ImageUrl;
use App\Support\Library;
use App\Support\ReadingState;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Browse')] class extends Component
{
    /**
     * Which listing is shown, matching Suwayomi's own FetchSourceMangaType.
     *
     * In the URL so a source's latest can be shared, and so the back button walks the tabs
     * rather than leaving the page.
     */
    #[Url(as: 'type', keep: false)]
    public string $type = 'POPULAR';

    /**
     * How far the reader has scrolled, as the source's own page number.
     *
     * This is what makes infinite scroll survive a reload and a shared link. It is also the
     * one place a shared link can cost upstream requests, so {@see MOUNT_PAGE_LIMIT} caps how
     * far back it will rebuild: a hand-written `?page=400` would otherwise mean 400 requests to
     * somebody else's site before the page rendered. Twelve is about three scroll gestures and
     * about 40 covers, which is past where a link stops being worth sending.
     */
    #[Url(as: 'page', keep: false)]
    public int $loadedThrough = 1;

    /**
     * Every row loaded so far, appended as the reader scrolls.
     *
     * Accumulated in a property rather than recomputed by a `#[Computed]`, because a computed
     * would re-query page one on every append and discard what the reader already has. The
     * library state and filings below are accumulated for the same reason, and each append
     * queries only the rows it just added rather than the whole grid.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $rows = [];

    /**
     * @var array<int, bool> manga id => in this reader's library
     */
    public array $libraryStates = [];

    /**
     * @var array<int, array<int, int>> manga id => shelf ids
     */
    public array $shelfFilings = [];

    public bool $hasNextPage = false;

    public bool $loadingMore = false;

    public ?string $loadError = null;

    /**
     * The most pages a shared or hand-written link will rebuild.
     */
    public const MOUNT_PAGE_LIMIT = 12;

    public Source $source;

    public function mount(Source $source): void
    {
        /*
         * An uninstalled source has no catalogue to show.
         *
         * This is not defensive for its own sake. The mirror marked nine installed sources
         * uninstalled after a truncated sync response on 2026-10-01, one of them Asura Scans,
         * so a browse page that 404s for a source the reader can see listed would have been a
         * third symptom of the same defect rather than an independent problem.
         */
        abort_unless($source->is_installed, 404);

        $this->source = $source;
        $this->type = strtoupper($this->type) === 'LATEST' ? 'LATEST' : 'POPULAR';
        $this->loadedThrough = max(1, min($this->loadedThrough, self::MOUNT_PAGE_LIMIT));

        for ($page = 1; $page <= $this->loadedThrough; $page++) {
            if (! $this->appendPage($page)) {
                break;
            }
        }

        // Whatever the link said, the reader is now at the end of what has actually loaded.
        $this->loadedThrough = $this->lastLoadedPage;
    }

    /**
     * The highest page number that has actually been appended.
     */
    public int $lastLoadedPage = 0;

    /**
     * Append one page of the source's listing.
     *
     * @return bool whether the page was appended, so mounting can stop early
     */
    private function appendPage(int $page): bool
    {
        $outcome = app(SourceSearcher::class)->browsePage($this->source, $this->type, $page);

        if ($outcome->hasError()) {
            // A link that asked for page 5 of a source that failed on page 1 should say so
            // rather than rendering an empty grid that looks like the end.
            $this->loadError = $this->loadError ?? $outcome->error;

            return false;
        }

        $this->hasNextPage = $outcome->hasNextPage;
        $this->lastLoadedPage = $page;

        if ($outcome->isEmpty()) {
            return false;
        }

        $fresh = array_values(array_filter($outcome->rows, fn (array $row): bool => (int) ($row['id'] ?? 0) > 0));

        // Guard against a source repeating a page, which would otherwise grow the grid forever
        // on an endless source that always answers true.
        $seen = array_flip(array_map(static fn (array $r): int => (int) $r['id'], $this->rows));
        $fresh = array_values(array_filter($fresh, fn (array $r): bool => ! isset($seen[(int) $r['id']])));

        if ($fresh === []) {
            $this->hasNextPage = false;

            return false;
        }

        $this->rows = [...$this->rows, ...$fresh];
        $this->loadReaderStateFor($fresh);

        return true;
    }

    /**
     * Read this reader's library state and shelf filings for the rows just added.
     *
     * Only the new ids, so appending page four does not re-query the sixty covers above it. One
     * query each rather than one per card, which is the same reasoning the search page applies
     * to the same shape of grid.
     *
     * @param  array<int, array<string, mixed>>  $fresh
     */
    private function loadReaderStateFor(array $fresh): void
    {
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $fresh);
        $userId = (int) auth()->id();

        $inLibrary = SeriesState::forUser($userId)
            ->whereIn('suwayomi_manga_id', $ids)
            ->pluck('in_library', 'suwayomi_manga_id')
            ->map(fn (mixed $in): bool => (bool) $in)
            ->all();

        foreach ($inLibrary as $mangaId => $in) {
            $this->libraryStates[(int) $mangaId] = $in;
        }

        foreach (app(Library::class)->filingsFor($userId, $ids) as $mangaId => $shelfIds) {
            $this->shelfFilings[(int) $mangaId] = array_map('intval', $shelfIds);
        }
    }

    /**
     * Load the next page, because the reader reached the sentinel.
     *
     * Called by an IntersectionObserver rather than by a button, which is the whole point: the
     * reader asked for scrolling rather than for paging.
     */
    public function loadMore(): void
    {
        if ($this->loadingMore || ! $this->hasNextPage) {
            return;
        }

        $this->loadingMore = true;

        try {
            $appended = $this->appendPage($this->lastLoadedPage + 1);

            $this->loadedThrough = $this->lastLoadedPage;

            if (! $appended) {
                // Either the source has no more, or it failed. Both stop the scroll, and the
                // message below distinguishes them.
                $this->hasNextPage = false;
            }
        } finally {
            $this->loadingMore = false;
        }
    }

    /**
     * Switch between Popular and Latest, back to the first page.
     *
     * Page one rather than the page they had scrolled to, because page seven of Popular and page
     * seven of Latest are unrelated slices, and landing on an empty grid reads as a fault.
     */
    public function setType(string $type): void
    {
        $this->type = strtoupper($type) === 'LATEST' ? 'LATEST' : 'POPULAR';
        $this->rows = [];
        $this->libraryStates = [];
        $this->shelfFilings = [];
        $this->loadError = null;
        $this->hasNextPage = false;
        $this->lastLoadedPage = 0;
        $this->loadedThrough = 1;

        $this->appendPage(1);
    }

    /**
     * Try the page that failed again.
     */
    public function retry(): void
    {
        $this->loadError = null;

        if ($this->rows !== []) {
            $this->hasNextPage = true;
            $this->loadMore();

            return;
        }

        $this->appendPage(1);
    }

    /**
     * Why the grid is empty, when it is.
     *
     * Only one reason remains. The "no latest chapters" case used to live here too, but the
     * Latest tab is hidden for a source that has none, and `browsePage()` refuses the request
     * outright, so a hand-edited `?type=LATEST` lands on the load error that says exactly why
     * rather than on a silent empty grid.
     *
     * @return array{0: string, 1: string}|null
     */
    #[Computed]
    public function emptyReason(): ?array
    {
        if ($this->rows !== [] || $this->loadError !== null) {
            return null;
        }

        return [
            __('Nothing to show'),
            __('This source returned no titles at all. It may be empty, or its listing may have changed.'),
        ];
    }

    /**
     * This reader's shelves, for the per-card picker.
     *
     * @return array<int, array{id: int, name: string}>
     */
    #[Computed]
    public function shelves(): array
    {
        return app(Library::class)
            ->categories(auth()->id())
            ->map(static fn (array $one): array => ['id' => (int) $one['id'], 'name' => (string) $one['name']])
            ->values()
            ->all();
    }

    /**
     * Add or remove a title from the library, from its card.
     *
     * The same call the search page makes, so a title added here and one added there are the
     * same row rather than two beliefs about it.
     */
    public function toggleLibrary(int $manga): void
    {
        $inLibrary = ! ($this->libraryStates[$manga] ?? false);

        app(ReadingState::class)->setInLibrary(auth()->user(), $manga, $inLibrary);

        $this->libraryStates[$manga] = $inLibrary;

        // Taking a title out of the library takes it off its shelves, or the shelf would keep a
        // series the reader no longer has.
        if (! $inLibrary && ($this->shelfFilings[$manga] ?? []) !== []) {
            unset($this->shelfFilings[$manga]);
        }

        Flux::toast(
            text: $inLibrary
                ? __('Added to your library.')
                : __('Removed from your library.'),
            heading: $inLibrary ? __('In library') : __('Removed'),
            variant: $inLibrary ? 'success' : 'info',
        );
    }

    /**
     * File or unfile one title on one shelf, from its card.
     *
     * A "list" here is a shelf, the same one the library page files into, so a reader who has
     * already made shelves finds them here.
     *
     * Filing implies having it. `Library::attach()` writes the pivot row and the home shelf but
     * deliberately does not set `in_library`, and the library lists only `in_library = true`, so
     * filing without this would create a row the library page never shows: the reader files a
     * title on "Reading soon" and it disappears. Measured from the code, not reported, because
     * `entries()` filters on `in_library` and `attach()` never writes it.
     *
     * Two ids are in play and conflating them is the trap in this method. `Library::attach()`
     * and `::detach()` take a **manga** id, because that is what a reader thinks in and what the
     * routes carry. `categoryIdsFor()` takes the **series_states** id instead. Passing the state
     * id to attach writes a pivot row against a manga that does not exist and the chip flips
     * straight back on re-render, which is what the first version of this did.
     */
    public function toggleFiling(int $manga, int $shelfId): void
    {
        $library = app(Library::class);

        if (! ($this->libraryStates[$manga] ?? false)) {
            // Filing a series is a decision to keep it, so it joins the library too.
            app(ReadingState::class)->setInLibrary(auth()->user(), $manga, true);
            $this->libraryStates[$manga] = true;
        }

        $state = app(ReadingState::class)->loadOrCreate(auth()->id(), $manga);
        $filed = in_array($shelfId, $library->categoryIdsFor(auth()->id(), (int) $state->id), true);

        if ($filed) {
            $library->detach(auth()->id(), $manga, $shelfId);
        } else {
            $library->attach(auth()->id(), $manga, $shelfId);
        }

        $this->shelfFilings[$manga] = $library->categoryIdsFor(auth()->id(), (int) $state->id);

        Flux::toast(
            text: $filed ? __('Removed from the shelf.') : __('Added to the shelf.'),
            heading: $filed ? __('Unfiled') : __('Shelved'),
            variant: $filed ? 'info' : 'success',
        );
    }
};

?>

<div class="w-full space-y-7">
    {{--
        The header. A source's own catalogue is a place, so it gets the same eyebrow, display
        heading and serif italic treatment every other page uses, rather than a page-specific
        shape.
    --}}
    <header class="relative">
        <div class="hero-glow" aria-hidden="true"></div>

        <div class="relative flex flex-wrap items-end justify-between gap-x-6 gap-y-4">
            <div class="min-w-0">
                <p class="serif-italic text-base text-zinc-500 dark:text-zinc-400">
                    {{ __('Browse a source') }}
                </p>

                <h1 class="font-display text-2xl font-medium text-zinc-800 dark:text-white">
                    {{ $source->display_name }}
                </h1>

                <p class="mt-1.5 text-xs text-zinc-500 dark:text-white/70">
                    {{ $source->lang }}
                    @if ($source->supports_latest)
                        &middot; {{ __('Latest chapters available') }}
                    @endif
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <flux:button variant="outline" size="sm" href="{{ route('sources') }}" wire:navigate>
                    {{ __('All sources') }}
                </flux:button>
            </div>
        </div>
    </header>

    {{--
        Popular and Latest. The tab is hidden rather than disabled when a source has no latest
        listing, because a control that cannot do anything is noise, and 81 of the 82 sources on
        this box do offer one so the hidden case is rare.
    --}}
    <div class="flex flex-wrap items-center gap-2">
        <flux:button
            size="sm"
            :variant="$type === 'POPULAR' ? 'primary' : 'outline'"
            wire:click="setType('POPULAR')"
            aria-pressed="{{ $type === 'POPULAR' ? 'true' : 'false' }}"
        >
            {{ __('Popular') }}
        </flux:button>

        @if ($source->supports_latest)
            <flux:button
                size="sm"
                :variant="$type === 'LATEST' ? 'primary' : 'outline'"
                wire:click="setType('LATEST')"
                aria-pressed="{{ $type === 'LATEST' ? 'true' : 'false' }}"
            >
                {{ __('Latest') }}
            </flux:button>
        @endif

        @if ($type === 'LATEST')
            <span class="text-xs text-zinc-500 dark:text-zinc-400">
                {{ __('Newest chapters first.') }}
            </span>
        @endif
    </div>

    @if ($loadError !== null && $rows === [])
        <flux:callout variant="secondary" icon="exclamation-circle">
            <flux:heading size="sm">{{ __('This listing did not load') }}</flux:heading>
            <p class="mt-1.5 text-sm text-zinc-600 dark:text-zinc-300">{{ $loadError }}</p>

            <flux:button class="mt-3" size="sm" wire:click="retry">
                {{ __('Try again') }}
            </flux:button>
        </flux:callout>
    @elseif ($this->emptyReason !== null)
        <flux:callout>
            <flux:heading size="sm">{{ $this->emptyReason[0] }}</flux:heading>
            <p class="mt-1.5 text-sm text-zinc-600 dark:text-zinc-300">{{ $this->emptyReason[1] }}</p>
        </flux:callout>
    @else
        {{--
            The grid. `cover-frame`, `cover-scrim` and `cover-placeholder` are the same classes
            the library grid uses, so a cover holds its space before it loads and a wall of
            covers does not jump as the images arrive.

            There is no status line under a title. A listing carries no status: measured across
            122 rows from five sources, every one read UNKNOWN, because the status lives on the
            manga record and only arrives once something fetches it. The series page shows the
            real value; a card cannot, and printing UNKNOWN on every cover is printing a
            non-answer. Genre is no substitute, measured the same way: 16 of 20 rows blank on
            Asura Scans and 47 of 50 on MangaFire.
        --}}
        <ul
            class="stagger-enter grid grid-cols-2 gap-x-4 gap-y-7 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6"
            wire:key="browse-grid"
        >
            @foreach ($rows as $row)
                @php($mangaId = (int) ($row['id'] ?? 0))
                @php($inLibrary = $libraryStates[$mangaId] ?? false)
                @php($filed = $shelfFilings[$mangaId] ?? [])
                {{--
                    The cover comes from the app, not from the payload's own thumbnail URL.
                    That value is the upstream site's URL, and pointing an img at it renders a
                    broken image on every card: the site serves hotlink protection and the
                    browser has no Suwayomi token to present. Every other grid here asks
                    Suwayomi for the thumbnail and streams it back through ImageUrl, which is
                    AD-3 in the engineering notes, so the same builder is used.
                --}}
                @php($cover = ImageUrl::thumbnail($mangaId))

                <li class="group relative" wire:key="browse-row-{{ $mangaId }}">
                    <a
                        href="{{ route('series', ['manga' => $mangaId]) }}"
                        wire:navigate
                        class="block"
                        tabindex="-1"
                        aria-hidden="true"
                    >
                        <div class="cover-frame">
                            @if ($cover !== null)
                                <img src="{{ $cover }}" alt="" loading="lazy">
                            @else
                                <div class="cover-placeholder flex items-center justify-center text-zinc-300 dark:text-zinc-600">
                                    <flux:icon name="photo" class="size-8" />
                                </div>
                            @endif

                            <div class="cover-scrim" aria-hidden="true"></div>
                        </div>
                    </a>

                    {{--
                        The library toggle sits over the cover rather than under it, and outside
                        the link, because a control inside an anchor navigates instead of
                        toggling.
                    --}}
                    <button
                        type="button"
                        wire:click="toggleLibrary({{ $mangaId }})"
                        wire:loading.attr="disabled"
                        data-test="library-toggle"
                        aria-pressed="{{ $inLibrary ? 'true' : 'false' }}"
                        aria-label="{{ $inLibrary
                            ? __('Remove :title from your library', ['title' => $row['title'] ?? ''])
                            : __('Add :title to your library', ['title' => $row['title'] ?? '']) }}"
                        title="{{ $inLibrary ? __('In library') : __('Add to library') }}"
                        class="absolute bottom-1.5 end-1.5 z-10 flex size-7 items-center justify-center rounded-full bg-white/90 text-zinc-700 shadow-sm ring-1 ring-black/5 transition hover:bg-white dark:bg-zinc-900/90 dark:text-zinc-200 dark:ring-white/10"
                    >
                        <flux:icon :name="$inLibrary ? 'bookmark' : 'bookmark-slash'" class="size-4" />
                    </button>

                    <flux:heading size="sm" class="mt-2.5 truncate font-display font-semibold" level="3">
                        <a
                            href="{{ route('series', ['manga' => $mangaId]) }}"
                            wire:navigate
                            class="transition-colors hover:text-vermilion-600 dark:hover:text-vermilion-400"
                        >
                            {{ $row['title'] ?? __('Series :id', ['id' => $mangaId]) }}
                        </a>
                    </flux:heading>

                    {{--
                        The shelf picker, only when the reader has shelves. An empty dropdown on
                        every card is a control that cannot do anything.

                        At three shelves and a screenful of covers this is a handful of buttons
                        per card, which is fine. It is not fine at two hundred covers with ten
                        shelves, and if that ever becomes the case the answer is one button per
                        card opening a menu, not more chips.
                    --}}
                    @if ($this->shelves !== [])
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            @foreach ($this->shelves as $shelf)
                                @php($isFiled = in_array($shelf['id'], $filed, true))
                                <button
                                    type="button"
                                    wire:click="toggleFiling({{ $mangaId }}, {{ $shelf['id'] }})"
                                    wire:loading.attr="disabled"
                                    aria-pressed="{{ $isFiled ? 'true' : 'false' }}"
                                    title="{{ $isFiled
                                        ? __('Remove from :name', ['name' => $shelf['name']])
                                        : __('File under :name', ['name' => $shelf['name']]) }}"
                                    class="rounded-full border px-2 py-0.5 text-xs transition
                                        {{ $isFiled
                                            ? 'border-vermilion-500 bg-vermilion-500/10 text-vermilion-700 dark:border-vermilion-400 dark:text-vermilion-300'
                                            : 'border-zinc-200 text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:border-white/15 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
                                >
                                    {{ $shelf['name'] }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>

        {{--
            The status under the grid. Outside the sentinel below, because the sentinel is
            replaced on every append and anything inside it would be replaced with it.
        --}}
        <div class="flex min-h-16 flex-col items-center justify-center gap-3 pt-2" aria-live="polite">
            @if ($loadingMore)
                <div class="flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400">
                    <flux:icon name="arrow-path" class="size-4 animate-spin" />
                    <span>{{ __('Loading more') }}</span>
                </div>
            @elseif ($loadError !== null)
                {{-- A mid-scroll failure keeps what is already on screen and offers a retry. --}}
                <div class="flex flex-wrap items-center justify-center gap-3 text-sm">
                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('Could not load more.') }}</span>
                    <flux:button size="sm" variant="outline" wire:click="retry">
                        {{ __('Try again') }}
                    </flux:button>
                </div>
            @elseif (! $hasNextPage)
                <p class="pb-2 text-xs text-zinc-400 dark:text-zinc-500">
                    {{ __('That is everything this source listed.') }}
                </p>
            @endif
        </div>

        {{--
            Infinite scroll.

            The observer fires about a viewport-height before the sentinel reaches the reader, so
            the next page is usually there by the time they get to it. That is the difference
            between this feeling instant and feeling like paging with extra steps.

            Keyed on `lastLoadedPage` rather than `wire:ignore`d. It has to survive being
            morphed without losing its observer, and it has to be replaced after each append so
            a fresh one is armed for the next page. A key change is what gives both: Livewire
            swaps the element, Alpine runs `x-init` again, and the old observer goes with the old
            element. `wire:ignore` would have kept the element and therefore kept one observer
            that had already disconnected, which loads page 2 and then never again.

            Written inline rather than with an Alpine plugin because there is no intersect plugin
            installed and the reader already runs its own IntersectionObserver for page tracking.
        --}}
        <div
            wire:key="browse-sentinel-{{ $lastLoadedPage }}"
            x-init="
                /*
                 * An IIFE, because Alpine wraps `x-init` as a single expression and rejects a
                 * statement list outright with `Unexpected token 'const'`.
                 *
                 * Two earlier shapes failed before this one, both silently. One read
                 * `this.$wire` and bailed when it was not attached yet, a race on a page that
                 * had just hydrated, so no observer was ever built. The next defined observe()
                 * on the x-data object and called it through requestAnimationFrame, where `this`
                 * is the window and not the Alpine scope, so `$el` was undefined and the
                 * observer rejected its argument.
                 *
                 * `$el` and `$wire` are magics on the evaluation scope, so the arrow captures
                 * them lexically and the observer is built once, with nothing to look up later.
                 */
                (() => {
                    const el = $el
                    const io = new IntersectionObserver((entries) => {
                        if (! entries.some((e) => e.isIntersecting)) return
                        io.disconnect()
                        $wire.loadMore()
                    }, { rootMargin: '900px 0px' })
                    io.observe(el)
                })()
            "
            class="h-px w-full"
            aria-hidden="true"
        ></div>
    @endif
</div>
