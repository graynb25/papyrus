<?php

use App\Downloads\DownloadLedger;
use App\Models\Download;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Downloads')] class extends Component {
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
        return ['poll' => self::REFRESH_MS . 'ms'];
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
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading level="1" size="xl">{{ __('Downloads') }}</flux:heading>
            <flux:text size="sm" class="mt-1">
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
            </flux:text>
        </div>

        <flux:button variant="ghost" size="sm" icon="arrow-path" wire:click="$refresh">
            {{ __('Refresh') }}
        </flux:button>
    </div>

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
                ['label' => __('Downloading'), 'value' => $this->counts['pending'], 'tone' => 'text-purple-600 dark:text-purple-400'],
                ['label' => __('Ready offline'), 'value' => $this->counts['finished'], 'tone' => 'text-emerald-600 dark:text-emerald-400'],
                ['label' => __('Failed or removed'), 'value' => $this->counts['failed'], 'tone' => $this->counts['failed'] > 0 ? 'text-red-600 dark:text-red-400' : ''],
                ['label' => __('Pages on disk'), 'value' => $this->counts['pages'], 'tone' => ''],
            ] as $tile)
                <flux:card class="p-4">
                    <flux:text size="xs" class="text-zinc-500 dark:text-zinc-400">{{ $tile['label'] }}</flux:text>
                    <flux:heading size="lg" class="mt-1 {{ $tile['tone'] }}">{{ number_format($tile['value']) }}</flux:heading>
                </flux:card>
            @endforeach
        </div>

        {{-- Rows ---------------------------------------------------------- --}}
        <flux:card class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($this->downloads as $row)
                <div class="flex flex-wrap items-center gap-3 p-4">
                    <div class="min-w-0 flex-1">
                        <flux:heading size="sm" level="3" class="truncate">
                            <a href="{{ route('series', ['manga' => $row->suwayomi_manga_id]) }}" wire:navigate class="hover:underline">
                                {{ __('Chapter :id', ['id' => $row->suwayomi_chapter_id]) }}
                            </a>
                        </flux:heading>

                        <flux:text size="xs" class="truncate">
                            {{ $this->stateLabel($row->state) }}
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
                        <div class="w-full max-w-40">
                            <div
                                class="h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"
                                role="progressbar"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-valuenow="{{ (int) round($row->progress * 100) }}"
                            >
                                <div
                                    class="h-full rounded-full bg-purple-500 transition-[width] duration-700"
                                    style="width: {{ max(2, (int) round($row->progress * 100)) }}%"
                                ></div>
                            </div>
                            <flux:text size="xs" class="mt-1 tabular-nums opacity-70">
                                {{ (int) round($row->progress * 100) }}%
                            </flux:text>
                        </div>

                        <flux:button size="xs" variant="ghost" icon="x-mark"
                            wire:click="cancel({{ $row->suwayomi_chapter_id }})"
                            aria-label="{{ __('Stop this download') }}"
                            title="{{ __('Stop') }}"
                        ></flux:button>
                    @else
                        @if ($row->state === \App\Models\Download::FINISHED)
                            <flux:button size="xs" variant="ghost" icon="trash"
                                wire:click="forget({{ $row->suwayomi_chapter_id }})"
                                wire:confirm="{{ __('Delete these pages from disk? Reading the chapter will fetch them again.') }}"
                                aria-label="{{ __('Delete from disk') }}"
                                title="{{ __('Delete from disk') }}"
                            ></flux:button>
                        @elseif ($this->stateIsProblem($row->state))
                            <flux:button size="xs" variant="ghost" icon="arrow-path"
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
