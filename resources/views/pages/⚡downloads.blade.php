<?php

use App\Downloads\DownloadLedger;
use App\Models\Download;
use App\Suwayomi\SeriesGateway;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Downloads')] class extends Component
{
    /**
     * How often the page asks for fresh numbers, in milliseconds.
     *
     * Matches the ten second schedule rather than being faster. Polling faster than
     * the thing that changes the data shows the same value twice, and Suwayomi's
     * own progress steps once per page, so there is nothing to see in between.
     */
    public const REFRESH_MS = 10000;

    /*
     * No render() method. Livewire infers the view from the file name, and its
     * single-file parser injects a `view($data)` method of its own. Declaring
     * render() as well made the two disagree, and the page asked for a view named
     * "downloads" rather than "pages::downloads". Every other page here leaves it
     * out for the same reason.
     */

    /**
     * This reader's rows.
     *
     * A computed rather than a plain method so the page's three readers of it do
     * not each walk the table. Read as `$this->downloads`, never `$this->downloads()`:
     * calling a computed method runs its body again, and here that body is a poll of
     * the shared queue.
     *
     * @return Collection<int, Download>
     */
    #[Computed]
    public function downloads(): Collection
    {
        return app(DownloadLedger::class)->forUser(auth()->user());
    }

    /**
     * What each downloaded chapter belongs to, keyed by chapter id.
     *
     * The rows used to render `Chapter {suwayomi_chapter_id}`, which is a database id rather than
     * a chapter number, so the page read "Chapter 14430" and gave the reader nothing to recognise
     * it by. The row already carried `suwayomi_manga_id`, so the series was known all along and
     * simply was not being read.
     *
     * ## Why this is one call and not two per row
     *
     * The page holds up to 200 rows, and a title is one `manga(id:)` each, measured at about
     * 15ms against the live server, so 200 of them is three seconds of serial waiting to render a
     * list. `SeriesGateway::fetchMany()` is the batched call the library page already uses for
     * this exact problem, measured at 246ms for 300 ids, and its payload **already includes
     * `chapters { nodes { id name } }`**. So the titles and the chapter names come out of one
     * request the shape was carrying anyway, rather than two extra requests per row.
     *
     * A chapter's name is not derivable from its number: measured across sources, one labels a
     * chapter `Chapter 1` and another `Ch. 1`, and some carry a volume or a scanlator suffix. The
     * number is the fallback when the name is empty.
     *
     * @return array<int, array{series: string, chapter: string, manga: int}>
     */
    #[Computed]
    public function labels(): array
    {
        return $this->buildLabels();
    }

    /**
     * The name a CBZ export should be saved under, from what the labels already resolved.
     *
     * "Series - Chapter" rather than either alone. A downloads folder with forty files named
     * `chapter-1.cbz` is not a downloads folder anyone can use, and the series title is what
     * makes a file recognisable in it. The labels are already on the page for the row labels,
     * so this costs nothing rather than a second lookup per row.
     *
     * Falls back to the chapter id when the gateway could not name the series, which is what
     * the row itself shows, so the filename never invents a title the page did not have.
     */
    public function exportName(Download $row): string
    {
        $label = $this->labels[(int) $row->suwayomi_chapter_id] ?? null;

        $series = trim((string) ($label['series'] ?? ''));
        $chapter = trim((string) ($label['chapter'] ?? ''));

        if ($series === '' && $chapter === '') {
            return 'chapter-'.(int) $row->suwayomi_chapter_id;
        }

        if ($series === '') {
            return $chapter;
        }

        if ($chapter === '') {
            return $series;
        }

        return $series.' - '.$chapter;
    }

    #[Computed]
    private function buildLabels(): array
    {
        $rows = $this->downloads;

        $mangaIds = $rows
            ->map(fn (Download $row): int => (int) $row->suwayomi_manga_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($mangaIds === []) {
            return [];
        }

        $series = app(SeriesGateway::class)->fetchMany($mangaIds);

        $labels = [];

        foreach ($rows as $row) {
            $mangaId = (int) $row->suwayomi_manga_id;
            $chapterId = (int) $row->suwayomi_chapter_id;

            $node = $series[$mangaId] ?? null;
            $title = trim((string) ($node['title'] ?? ''));

            $chapter = __('Chapter :id', ['id' => $chapterId]);

            foreach ($node['chapters']['nodes'] ?? [] as $candidate) {
                if ((int) ($candidate['id'] ?? 0) !== $chapterId) {
                    continue;
                }

                $name = trim((string) ($candidate['name'] ?? ''));

                if ($name !== '') {
                    $chapter = $name;
                }

                break;
            }

            $labels[$chapterId] = [
                'series' => $title !== '' ? $title : __('Unknown series'),
                'chapter' => $chapter,
                'manga' => $mangaId,
            ];
        }

        return $labels;
    }

    /** @return array{pending: int, finished: int, failed: int, pages: int} */
    #[Computed]
    public function counts(): array
    {
        return app(DownloadLedger::class)->counts(auth()->user());
    }

    /**
     * How much of the shared queue belongs to other readers.
     *
     * Counted, never named. The downloader is one worker for every reader of this
     * app, and putting another reader's chapter titles on this page would be
     * leaking their library into it.
     *
     * @return array{count: int, running: bool}
     */
    #[Computed]
    public function shared(): array
    {
        return app(DownloadLedger::class)->sharedQueue(auth()->user());
    }

    /**
     * Reconcile on the way in.
     *
     * So the page is right immediately rather than up to ten seconds stale after a
     * click. The schedule still does the work between visits; this is only so the
     * reader who just enqueued something sees it move.
     */
    public function mount(): void
    {
        try {
            app(DownloadLedger::class)->sync(auth()->user());
        } catch (\Throwable) {
            // The page is still worth showing without it. Every row that needed a
            // decision has been left pending, so the next pass resolves it.
        }

        $this->dispatch('$refresh');
    }

    /**
     * The browser refreshes on a timer.
     *
     * `wire:poll` rather than a subscription: `Subscription.downloadChanged` exists
     * in the schema, but this app polls everything else and a websocket per reader
     * for one page is a different architecture to introduce for one feature.
     */
    public function with(): array
    {
        return ['poll' => self::REFRESH_MS.'ms'];
    }

    /**
     * Stop a download, and record it as stopped rather than as broken.
     */
    public function cancel(int $chapterId): void
    {
        $done = app(DownloadLedger::class)->cancel(auth()->user(), $chapterId);

        Flux::toast(
            text: $done
                ? __('Its pages already fetched were left alone.')
                : __('That download is not yours.'),
            heading: $done ? __('Download removed') : __('Nothing removed'),
            variant: $done ? 'info' : 'danger',
        );

        $this->dispatch('$refresh');
    }

    /**
     * Delete a finished download's pages from disk.
     */
    public function forget(int $chapterId): void
    {
        $done = app(DownloadLedger::class)->forget(auth()->user(), $chapterId);

        Flux::toast(
            text: $done
                ? __('The pages are gone from disk. Reading it will fetch them again.')
                : __('Only a finished download can be deleted, and one already deleted cannot be.'),
            heading: $done ? __('Deleted') : __('Not deleted'),
            variant: $done ? 'success' : 'warning',
        );

        $this->dispatch('$refresh');
    }

    /**
     * Queue the same chapters again.
     *
     * From the reader's point of view a failed download is a thing to retry, so
     * this re-enqueues rather than deleting the row: the history of what failed and
     * when is the reason the ledger exists.
     */
    public function retry(int $chapterId): void
    {
        $row = $this->find($chapterId);

        if ($row === null) {
            return;
        }

        $result = app(DownloadLedger::class)->enqueue(
            auth()->user(),
            [$chapterId],
            (int) $row->suwayomi_manga_id,
        );

        Flux::toast(
            text: $result['queued'] > 0
                ? __('Queued again.')
                : __('Suwayomi would not accept it again just now.'),
            heading: $result['queued'] > 0 ? __('Retrying') : __('Not queued'),
            variant: $result['queued'] > 0 ? 'success' : 'warning',
        );

        $this->dispatch('$refresh');
    }

    /** Remove every finished and cancelled row, leaving the live ones alone. */
    public function clearHistory(): void
    {
        $removed = Download::forUser(auth()->id())
            ->whereNotIn('state', Download::PENDING)
            ->delete();

        Flux::toast(
            text: $removed > 0
                ? trans_choice(':count entry cleared|:count entries cleared', $removed, ['count' => $removed])
                : __('Nothing to clear.'),
            heading: __('History cleared'),
            variant: 'info',
        );

        $this->dispatch('$refresh');
    }

    public function find(int $chapterId): ?Download
    {
        return $this->downloads->first(
            static fn (Download $row): bool => (int) $row->suwayomi_chapter_id === $chapterId
        );
    }

    /** Whether a row is still expecting the server to act. */
    public function isPending(Download $row): bool
    {
        return in_array($row->state, Download::PENDING, true);
    }

    /**
     * The label for a state.
     *
     * An array rather than a `match`, and not for style. Livewire's single-file
     * parser injects the generated `view()` method at the LAST `};` in the class
     * body, and a `match` expression ends in one. With a match in the class the
     * injection landed inside the class and the compiled view failed to parse with
     * "unexpected token protected". Measured, and it is worth knowing before
     * reaching for `match` in any of these page components.
     *
     * @return array<string, string>
     */
    private function stateLabels(): array
    {
        return [
            Download::QUEUED => __('Waiting'),
            Download::DOWNLOADING => __('Downloading'),
            Download::FINISHED => __('Ready offline'),
            Download::FAILED => __('Failed'),
            Download::CANCELLED => __('Removed'),
        ];
    }

    public function stateLabel(string $state): string
    {
        return $this->stateLabels()[$state] ?? ucfirst($state);
    }

    public function stateIsProblem(string $state): bool
    {
        return $state === Download::FAILED;
    }
}; ?>
<div class="w-full space-y-6">
    {{-- Header ---------------------------------------------------------- --}}
    <x-page.header>
        <x-slot:eyebrow>{{ __('Reading ahead, quietly.') }}</x-slot:eyebrow>
        <x-slot:title>{{ __('Downloads') }}</x-slot:title>

        <x-slot:meta>
            @if ($this->counts['pending'] > 0)
                {{ trans_choice(
                    ':count downloading|:count downloading',
                    $this->counts['pending'],
                    ['count' => $this->counts['pending']]
                ) }}
            @elseif ($this->counts['finished'] > 0)
                {{ trans_choice(
                    ':count chapter ready offline|:count chapters ready offline',
                    $this->counts['finished'],
                    ['count' => $this->counts['finished']]
                ) }}
            @else
                {{ __('Nothing queued.') }}
            @endif
        </x-slot:meta>

        <x-slot:actions>
            <flux:button variant="ghost" size="sm" icon="arrow-path" wire:click="$refresh">
                {{ __('Refresh') }}
            </flux:button>
        </x-slot:actions>
    </x-page.header>

    {{-- A refused export, which is a redirect back here rather than a response. --}}
    @if (session('cbzRefusal'))
        <flux:callout variant="warning" icon="exclamation-triangle" data-test="cbz-refused">
            {{ session('cbzRefusal') }}
        </flux:callout>
    @endif

    {{--
        The shared queue.

        Suwayomi has one downloader and resolves every request to the same
        account, so one reader's downloads appear in another's queue. Saying how
        many there are, without naming them, is the difference between explaining a
        stall and leaking a library.
    --}}
    @if ($this->shared['count'] > 0)
        <flux:callout variant="secondary" icon="users">
            <flux:heading level="2" size="sm" class="mb-1">{{ __('Shared download queue') }}</flux:heading>
            <p class="text-sm">
                {{ trans_choice(
                    ':count other chapter is ahead of yours in the one downloader this app shares.|:count other chapters are ahead of yours in the one downloader this app shares.',
                    $this->shared['count'],
                    ['count' => $this->shared['count']]
                ) }}
            </p>
        </flux:callout>
    @endif

    @if ($this->downloads->isEmpty())
        <flux:callout variant="secondary" icon="cloud-arrow-down">
            <flux:heading level="2" size="sm" class="mb-1">{{ __('No downloads yet') }}</flux:heading>
            <p class="text-sm">
                {{ __('Queue one from any series page. A downloaded chapter reads without the source being reachable.') }}
            </p>
            <flux:button class="mt-3" variant="primary" href="{{ route('library') }}" wire:navigate>
                {{ __('Go to your library') }}
            </flux:button>
        </flux:callout>
    @else
        {{-- Totals -------------------------------------------------------- --}}
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            @foreach ([
                ['label' => __('Downloading'), 'value' => $this->counts['pending'], 'tone' => 'text-vermilion-600 dark:text-vermilion-400', 'bar' => 'bg-vermilion-500', 'share' => $this->counts['pending']],
                ['label' => __('Ready offline'), 'value' => $this->counts['finished'], 'tone' => 'text-moss-600 dark:text-moss-400', 'bar' => 'bg-moss-500', 'share' => $this->counts['finished']],
                ['label' => __('Failed or removed'), 'value' => $this->counts['failed'], 'tone' => $this->counts['failed'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-zinc-500 dark:text-zinc-400', 'bar' => 'bg-red-500', 'share' => $this->counts['failed']],
                ['label' => __('Pages on disk'), 'value' => $this->counts['pages'], 'tone' => 'text-zinc-800 dark:text-zinc-100', 'bar' => 'bg-zinc-400 dark:bg-zinc-600', 'share' => $this->counts['pages']],
            ] as $index => $tile)
                <flux:card class="card-press relative overflow-hidden p-4">
                    <flux:text size="xs" class="text-zinc-500 dark:text-zinc-400">{{ $tile['label'] }}</flux:text>

                    <flux:heading size="lg" class="mt-1 font-display! tabular-nums {{ $tile['tone'] }}">{{ number_format($tile['value']) }}</flux:heading>

                    {{-- A quiet measure of each tile's share of the total,
                         so the numbers are comparable at a glance without a
                         second chart anywhere. --}}
                    @php($total = max(1, $this->counts['pending'] + $this->counts['finished'] + $this->counts['failed'] + $this->counts['pages']))

                    <div class="mt-3 h-0.5 w-full overflow-hidden rounded-full bg-zinc-200/70 dark:bg-white/[0.06]" aria-hidden="true">
                        <div
                            class="h-full rounded-full {{ $tile['bar'] }} transition-[width] duration-700"
                            style="width: {{ (int) round(min(100, $tile['share'] / $total * 100)) }}%"
                        ></div>
                    </div>
                </flux:card>
            @endforeach
        </div>

        {{-- Rows ---------------------------------------------------------- --}}
        <flux:card class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($this->downloads as $row)
                @php($label = $this->labels[(int) $row->suwayomi_chapter_id] ?? null)
                @php($seriesTitle = $label['series'] ?? __('Unknown series'))
                @php($chapterLabel = $label['chapter'] ?? __('Chapter :id', ['id' => $row->suwayomi_chapter_id]))
                @php($mangaId = $label['manga'] ?? (int) $row->suwayomi_manga_id)

                {{--
                    The whole row is the link, not just the text.

                    The reader asked for the click to go to the series, and it did, but only if
                    they hit the one word of text that was a link. A row that looks like a link and
                    is not, over its other four fifths, is worse than no link. The anchor is
                    stretched over the row with an `::after`, which keeps the row's own buttons
                    working because they sit above it.

                    What it says is the series first and the chapter second, because the series is
                    what a reader recognises and the chapter is what they are looking for inside it.
                --}}
                <div class="relative flex flex-wrap items-center gap-3 p-4">
                    <div class="min-w-0 flex-1">
                        <flux:heading size="sm" level="3" class="truncate">
                            <a
                                href="{{ route('series', ['manga' => $mangaId]) }}"
                                wire:navigate
                                id="download-series-link"
                                class="transition-colors after:absolute after:inset-0 hover:underline"
                            >
                                {{ $seriesTitle }}
                            </a>
                        </flux:heading>

                        <flux:text size="xs" class="truncate">
                            <span data-test="download-chapter-label">{{ $chapterLabel }}</span>
                            &middot; {{ $this->stateLabel($row->state) }}
                            @if ($row->page_count !== null)
                                &middot; {{ trans_choice(':count page|:count pages', $row->page_count, ['count' => $row->page_count]) }}
                            @endif
                            @if ($row->finished_at !== null)
                                &middot; {{ $row->finished_at->diffForHumans() }}
                            @endif
                        </flux:text>

                        @if ($row->error !== null)
                            <flux:text size="xs" class="mt-0.5 text-red-600 dark:text-red-400">
                                {{ $row->error }}
                            </flux:text>
                        @endif
                    </div>

                    {{--
                        The bar. Stepped, because it steps on the server too:
                        measured, `progress` advances once per page, 1/28 on a
                        28 page chapter. A smooth bar would be a nicer lie.
                    --}}
                    @if ($this->isPending($row))
                        <div class="relative z-10 w-full max-w-40">
                            <div
                                class="h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"
                                role="progressbar"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-valuenow="{{ (int) round($row->progress * 100) }}"
                            >
                                <div
                                    class="h-full rounded-full bg-vermilion-500 transition-[width] duration-700"
                                    style="width: {{ max(2, (int) round($row->progress * 100)) }}%"
                                ></div>
                            </div>
                            <flux:text size="xs" class="mt-1 tabular-nums opacity-70">
                                {{ (int) round($row->progress * 100) }}%
                            </flux:text>
                        </div>

                        <flux:button size="xs" variant="ghost" icon="x-mark" class="relative z-10"
                            wire:click="cancel({{ $row->suwayomi_chapter_id }})"
                            aria-label="{{ __('Stop this download') }}"
                            title="{{ __('Stop') }}"
                        ></flux:button>
                    @else
                        @if ($row->state === \App\Models\Download::FINISHED)
                            {{--
                                Export as CBZ, beside delete rather than on the series page.

                                It belongs next to delete because it is the other thing a reader
                                does with a chapter that is on disk: either keep it or hand it
                                to something else. On the series page it would be a fifth
                                control competing with four that are about reading.

                                A plain link, not a Livewire action. The response is a file, so
                                the browser has to navigate to it, and a `wire:click` would
                                receive the archive as a Livewire payload and try to diff it.
                            --}}
                            <a
                                href="{{ route('downloads.cbz', ['chapter' => $row->suwayomi_chapter_id]) }}?name={{ rawurlencode($this->exportName($row)) }}"
                                class="relative z-10 inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-zinc-600 transition-colors hover:bg-zinc-100 hover:text-zinc-900 focus-visible:ring-2 focus-visible:ring-vermilion-500 focus-visible:outline-none dark:text-zinc-300 dark:hover:bg-white/10 dark:hover:text-white"
                                aria-label="{{ __('Export this chapter as a CBZ') }}"
                                title="{{ __('Export as CBZ') }}"
                            >
                                <flux:icon name="arrow-down-tray" class="size-4" />
                            </a>

                            <x-confirm
                                action="forget({{ $row->suwayomi_chapter_id }})"
                                :title="__('Delete these pages from disk?')"
                                :body="__('Reading the chapter will fetch them again.')"
                                :confirm="__('Delete from disk')"
                            >
                                <flux:button size="xs" variant="ghost" icon="trash" class="relative z-10"
                                    aria-label="{{ __('Delete from disk') }}"
                                    title="{{ __('Delete from disk') }}"
                                ></flux:button>
                            </x-confirm>
                        @elseif ($this->stateIsProblem($row->state))
                            <flux:button size="xs" variant="ghost" icon="arrow-path" class="relative z-10"
                                wire:click="retry({{ $row->suwayomi_chapter_id }})"
                                aria-label="{{ __('Try again') }}"
                                title="{{ __('Try again') }}"
                            ></flux:button>
                        @endif
                    @endif
                </div>
            @endforeach
        </flux:card>

        <div class="flex justify-end">
            <flux:button size="sm" variant="ghost" wire:click="clearHistory">
                {{ __('Clear finished history') }}
            </flux:button>
        </div>
    @endif
</div>
