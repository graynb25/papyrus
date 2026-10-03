<?php

use App\Models\SeriesState;
use App\Models\Source;
use App\Search\SourceSearcher;
use App\Support\ImageUrl;
use App\Support\Library;
use App\Support\ReadingState;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Browse')] class extends Component
{
    /**
     * Which listing is shown, matching Suwayomi's own FetchSourceMangaType.
     *
     * In the URL so a page of a source's latest can be shared, and so the browser's back
     * button walks the pages a reader paged through rather than leaving the page.
     */
    #[Url(as: 'type', keep: false)]
    public string $type = 'POPULAR';

    #[Url(as: 'page', keep: false)]
    public int $pageNumber = 1;

    /**
     * The source being browsed, resolved from the route.
     */
    public Source $source;

    public function mount(Source $source): void
    {
        /*
         * An uninstalled source has no catalogue to show.
         *
         * This is not defensive for its own sake. The mirror marked nine installed
         * sources uninstalled after a truncated sync response on 2026-10-01, and one of
         * them was Asura Scans, so a browse page that 404s for a source the reader can see
         * listed would have been a third symptom of the same defect rather than an
         * independent problem.
         */
        abort_unless($source->is_installed, 404);

        $this->source = $source;
        $this->type = strtoupper($this->type) === 'LATEST' ? 'LATEST' : 'POPULAR';
    }

    /**
     * Switch between Popular and Latest, back to page one.
     *
     * Page one rather than the page they were on, because page seven of Popular and page
     * seven of Latest are unrelated slices, and landing on an empty grid reads as a fault.
     */
    public function setType(string $type): void
    {
        $this->type = strtoupper($type) === 'LATEST' ? 'LATEST' : 'POPULAR';
        $this->pageNumber = 1;
    }

    public function previousPage(): void
    {
        $this->pageNumber = max(1, $this->pageNumber - 1);
    }

    public function nextPage(): void
    {
        $this->pageNumber++;
    }

    /**
     * The rows for the current tab and page.
     *
     * Read directly rather than queued. A fan-out is 82 requests and belongs behind a job
     * with a poll, which is what search does. This is one request to one source, measured
     * at 81ms for Latest and 549ms for Popular on Asura Scans. Queuing it would add a poll
     * and a ledger to save half a second, and the reader would watch a spinner where the
     * grid could already be on screen.
     */
    #[Computed]
    public function rows(): Collection
    {
        if ($this->type === 'LATEST' && ! $this->source->supports_latest) {
            return collect();
        }

        return collect(app(SourceSearcher::class)
            ->browse($this->source, $this->type, $this->pageNumber)
            ->results);
    }

    /**
     * Why the grid is empty, when it is.
     *
     * @return array{0: string, 1: string}|null
     */
    #[Computed]
    public function emptyReason(): ?array
    {
        if ($this->rows->isNotEmpty()) {
            return null;
        }

        if ($this->type === 'LATEST' && ! $this->source->supports_latest) {
            return [
                __('No latest chapters'),
                __('This source does not keep a latest-chapters listing. Its popular titles are still here.'),
            ];
        }

        return [
            __('Nothing to show'),
            __('This source returned no titles for page :page. It may not have that many pages.', ['page' => $this->pageNumber]),
        ];
    }

    /**
     * Whether each row is already in this reader's library, keyed by manga id.
     *
     * One query for the whole grid rather than one per card, which is the same reasoning
     * the search page applies to the same shape of grid.
     *
     * @return array<int, bool>
     */
    #[Computed]
    public function libraryMap(): array
    {
        $ids = $this->mangaIds();

        if ($ids === []) {
            return [];
        }

        return SeriesState::forUser((int) auth()->id())
            ->whereIn('suwayomi_manga_id', $ids)
            ->pluck('in_library', 'suwayomi_manga_id')
            ->map(fn (mixed $in): bool => (bool) $in)
            ->all();
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
     * Which shelves each row is filed under, keyed by manga id.
     *
     * @return array<int, array<int, int>>
     */
    #[Computed]
    public function filings(): array
    {
        $ids = $this->mangaIds();

        if ($ids === []) {
            return [];
        }

        return app(Library::class)->filingsFor(auth()->id(), $ids);
    }

    /**
     * Add or remove a title from the library, from its card.
     *
     * The same call the search page makes, so a title added here and one added there are
     * the same row rather than two beliefs about it.
     */
    public function toggleLibrary(int $manga): void
    {
        $inLibrary = ! ($this->libraryMap[$manga] ?? false);

        app(ReadingState::class)->setInLibrary(auth()->user(), $manga, $inLibrary);

        unset($this->libraryMap);

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
     * A "list" here is a shelf, the same one the library page files into, so a reader who
     * has already made shelves finds them here and a title filed while browsing is
     * discoverable from the library without another step.
     */
    public function toggleFiling(int $manga, int $shelfId): void
    {
        /*
         * Two different ids, which is the trap in this method.
         *
         * `Library::attach()` and `::detach()` take a **manga** id, because a reader
         * thinks in terms of series and that is what the routes carry. `categoryIdsFor()`
         * takes the **series_states** id instead. Passing the state id to attach, as the
         * first version of this did, silently filed nothing: the row was written against a
         * manga that does not exist and the chip flipped straight back on re-render.
         */
$library = app(Library::class);
        $state = app(ReadingState::class)->loadOrCreate(auth()->id(), $manga);

        $filed = in_array($shelfId, $library->categoryIdsFor(auth()->id(), (int) $state->id), true);

        if ($filed) {
            $library->detach(auth()->id(), $manga, $shelfId);
        } else {
            $library->attach(auth()->id(), $manga, $shelfId);
        }

        unset($this->filings);

        Flux::toast(
            text: $filed ? __('Removed from the shelf.') : __('Added to the shelf.'),
            heading: $filed ? __('Unfiled') : __('Shelved'),
            variant: $filed ? 'info' : 'success',
        );
    }

    /**
     * The manga ids on this page, without the zero ids.
     *
     * @return array<int, int>
     */
    private function mangaIds(): array
    {
        $ids = array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $this->rows->all(),
        );

        return array_values(array_filter($ids));
    }
};

?>

<div class="w-full space-y-7">
    {{--
        The header. A source's own catalogue is a place, so it gets the same
        eyebrow, display heading and serif italic treatment every other page uses,
        rather than a page-specific shape.
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
        Popular and Latest. The tab is hidden rather than disabled when a source has no
        latest listing, because a control that cannot do anything is noise, and 81 of the
        82 sources on this box do offer one so the hidden case is rare.
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

    @if ($this->emptyReason !== null)
        <flux:callout>
            <flux:heading size="sm">{{ $this->emptyReason[0] }}</flux:heading>
            <p class="mt-1.5 text-sm text-zinc-600 dark:text-zinc-300">{{ $this->emptyReason[1] }}</p>

            @if ($pageNumber > 1)
                <flux:button class="mt-3" size="sm" wire:click="previousPage">
                    {{ __('Back a page') }}
                </flux:button>
            @endif
        </flux:callout>
    @else
        {{--
            The grid. `cover-frame`, `cover-scrim` and `cover-placeholder` are the same
            classes the library grid uses, so a cover holds its space before it loads and
            a wall of covers does not jump as the images arrive.
        --}}
        <ul class="stagger-enter grid grid-cols-2 gap-x-4 gap-y-7 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6">
            @foreach ($this->rows as $row)
                @php($mangaId = (int) ($row['id'] ?? 0))
                @php($inLibrary = $this->libraryMap[$mangaId] ?? false)
                @php($filed = $this->filings[$mangaId] ?? [])
                {{--
                    The cover comes from the app, not from `$row['thumbnailUrl']`.

                    That value is the upstream site's own URL, and pointing an img at it
                    renders a broken image on every card: the site serves hotlink protection
                    and the browser has no Suwayomi token to present. Every other grid in
                    this app asks Suwayomi for the thumbnail and streams it back through
                    `ImageUrl::thumbnail()`, which is AD-3 in the engineering notes, so the
                    same builder is used here. The upstream URL is kept only as the
                    fallback for a source whose thumbnail path the server cannot resolve.
                --}}
                @php($cover = ImageUrl::thumbnail($mangaId) ?: ($row['thumbnailUrl'] ?? null))

                <li class="group relative">
                    <a
                        href="{{ route('series', ['manga' => $mangaId]) }}"
                        wire:navigate
                        class="block"
                        tabindex="-1"
                        aria-hidden="true"
                    >
                        <div class="cover-frame">
                            @if (! empty($cover))
                                <img
                                    src="{{ $cover }}"
                                    alt=""
                                    loading="lazy"
                                >
                            @else
                                <div class="cover-placeholder flex items-center justify-center text-zinc-300 dark:text-zinc-600">
                                    <flux:icon name="photo" class="size-8" />
                                </div>
                            @endif

                            <div class="cover-scrim" aria-hidden="true"></div>
                        </div>
                    </a>

                    {{--
                        The library toggle sits over the cover rather than under it, and
                        outside the link, because a control inside an anchor navigates
                        instead of toggling.
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
                        <flux:icon
                            :name="$inLibrary ? 'bookmark' : 'bookmark-slash'"
                            class="size-4"
                        />
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

                    @if (! empty($row['status']))
                        <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $row['status'] }}
                        </p>
                    @endif

                    {{--
                        The shelf picker. Only rendered when the reader has shelves,
                        because an empty dropdown on every card is a control that cannot
                        do anything. A title filed here is discoverable from the library
                        with no further step, which is what makes this a list rather than
                        a bookmark.
                    --}}
                    @if ($this->shelves !== [])
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            @foreach ($this->shelves as $shelf)
                                <button
                                    type="button"
                                    wire:click="toggleFiling({{ $mangaId }}, {{ $shelf['id'] }})"
                                    wire:loading.attr="disabled"
                                    aria-pressed="{{ in_array($shelf['id'], $filed, true) ? 'true' : 'false' }}"
                                    title="{{ in_array($shelf['id'], $filed, true)
                                        ? __('Remove from :name', ['name' => $shelf['name']])
                                        : __('File under :name', ['name' => $shelf['name']]) }}"
                                    class="rounded-full border px-2 py-0.5 text-xs transition
                                        {{ in_array($shelf['id'], $filed, true)
                                            ? 'border-vermilion-500 bg-vermil-500/10 text-vermilion-700 dark:border-vermilion-400 dark:text-vermilion-300'
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
            Paging rather than infinite scroll. Next and Previous match the sources
            page's own pagination, and a reader who wants to go back three pages can
            see that they are on page four instead of discovering it by scrolling up.
        --}}
        <div class="flex items-center justify-between gap-3 pt-1">
            <flux:button
                size="sm"
                variant="outline"
                wire:click="previousPage"
                :disabled="$pageNumber <= 1"
            >
                {{ __('Previous') }}
            </flux:button>

            <span class="text-sm tabular-nums text-zinc-500 dark:text-zinc-400">
                {{ __('Page :page', ['page' => $pageNumber]) }}
            </span>

            <flux:button
                size="sm"
                variant="outline"
                wire:click="nextPage"
                :disabled="count($this->rows) < 1"
            >
                {{ __('Next') }}
            </flux:button>
        </div>
    @endif
</div>