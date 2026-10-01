<?php

use App\Jobs\FindMigrationCandidates;
use App\Migration\SeriesMigrator;
use App\Models\MigrationSearch;
use App\Models\SeriesMigration;
use App\Suwayomi\SeriesGateway;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Move to another source')] class extends Component {
    /**
     * How often the page looks for a finished search.
     *
     * Measured: the search is 21.9 seconds across 82 sources. A reader staring at a
     * spinner for that long learns nothing, so it runs behind a job and this page
     * polls its own row. Three seconds is fast enough that the result appears to
     * arrive when it does, and slow enough not to be a busy loop.
     */
    public const REFRESH_MS = 3000;

    /**
     * Named `manga` because that is the route segment: Livewire passes mount()
     * arguments by name.
     */
    public int $manga;

    public ?int $resultId = null;

    public function mount(int $manga): void
    {
        $this->manga = $manga;
    }

    /**
     * The series being moved, straight from the server.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function series(): ?array
    {
        try {
            return app(SeriesGateway::class)->find($this->manga);
        } catch (\Throwable) {
            // The page says the series could not be read rather than erroring, which
            // is what a server that is down should look like.
            return null;
        }
    }

    /**
     * The newest search for this series, which is the one on screen.
     *
     * A computed, and read as a property. Calling it would run the query again, and
     * the markup reads it in five places.
     */
    #[Computed]
    public function search(): ?MigrationSearch
    {
        return MigrationSearch::forUser(auth()->id())
            ->where('from_manga_id', $this->manga)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The move that was just made, so the result can be reported once.
     */
    #[Computed]
    public function result(): ?SeriesMigration
    {
        return $this->resultId === null
            ? null
            : SeriesMigration::forUser(auth()->id())->find($this->resultId);
    }

    public function findSources(): void
    {
        $series = $this->series;

        if ($series === null) {
            Flux::toast(
                text: __('The server could not read this series.'),
                heading: __('Not searched'),
                variant: 'danger',
            );

            return;
        }

        /*
         * A new row per attempt, and the old ones stay until the reader leaves. The
         * page renders only the newest, so a second search supersedes the first
         * without leaving a stale list one click away.
         */
        $search = MigrationSearch::createFor(auth()->id(), [
            'from_manga_id' => $this->manga,
            'state' => MigrationSearch::SEARCHING,
        ]);

        FindMigrationCandidates::dispatch($search->getKey(), auth()->id(), $this->manga);

        Flux::toast(
            text: __('This searches every installed source and takes about half a minute.'),
            heading: __('Searching'),
            variant: 'info',
        );

        $this->dispatch('$refresh');
    }

    /**
     * Move the series onto a candidate the reader chose.
     *
     * The candidate is re-checked against the search inside the migrator, so a value
     * this method was tricked into accepting is refused there rather than trusted
     * here. See SeriesMigrator.
     */
    public function moveTo(int $toManga): void
    {
        $outcome = app(SeriesMigrator::class)->migrate(auth()->user(), $this->manga, $toManga);

        if (! $outcome['ok']) {
            Flux::toast(
                text: (string) $outcome['reason'],
                heading: __('Not moved'),
                variant: 'danger',
            );

            return;
        }

        /** @var SeriesMigration $migration */
        $migration = $outcome['migration'];

        $this->resultId = $migration->getKey();

        Flux::toast(
            text: trans_choice(
                ':count chapter kept its place.|:count chapters kept their place.',
                (int) $migration->chapters_carried,
                ['count' => (int) $migration->chapters_carried],
            ),
            heading: __('Moved'),
            variant: 'success',
        );

        $this->dispatch('$refresh');
    }

    /**
     * Whether the search on screen is genuinely still running.
     *
     * A stalled search counts as finished. Measured: the first live run was killed by
     * the worker's timeout with `--tries=1`, so nothing recorded the failure and the
     * row said `searching` for ever. A page that can poll for ever is worse than one
     * that admits it lost track, and the row is rewritten every five seconds while the
     * job works, so a slow search is never mistaken for a dead one.
     */
    public function stillRunning(): bool
    {
        $search = $this->search;

        return $search !== null
            && $search->state === MigrationSearch::SEARCHING
            && ! $search->isStalled();
    }

    public function with(): array
    {
        // Polling only while a search is genuinely in flight. Once it settles, or is
        // given up on, the page stops asking, because a reader looking at a finished
        // list should not be paying for a request every three seconds.
        $polling = $this->stillRunning();

        return $polling ? ['poll' => self::REFRESH_MS . 'ms'] : [];
    }

    /**
     * The chapter comparison, phrased for a reader.
     *
     * Count and gap together, because either alone misleads: 41 against 41 is the
     * same series, and 333 against 41 is a different entry that happens to share a
     * title. Measured on both counts for the same series.
     */
    public function chapterVerdict(array $candidate): string
    {
        $chapters = $candidate['chapters'] ?? null;
        $delta = $candidate['chapter_delta'] ?? null;

        if ($chapters === null) {
            return __('Not looked at yet.');
        }

        if ($delta === null) {
            return trans_choice(
                ':count chapter.|:count chapters.',
                (int) $chapters,
                ['count' => (int) $chapters],
            );
        }

        if ((int) $delta === 0) {
            return trans_choice(
                'Same as here: :count chapters.',
                (int) $chapters,
                ['count' => (int) $chapters],
            );
        }

        return (int) $delta > 0
            ? trans_choice(
                    ':count chapters, further along.',
                    (int) $chapters,
                    ['count' => (int) $chapters],
                )
            : trans_choice(
                    ':count chapters, behind.',
                    (int) $chapters,
                    ['count' => (int) $chapters],
                );
    }

    /** Whether a candidate can be chosen, and why not when it cannot. */
    public function candidateBlocked(array $candidate): ?string
    {
        return match ($candidate['status'] ?? '') {
            'ready' => null,
            'no_chapters' => __('This source lists no chapters for it.'),
            'unavailable' => __('This source could not be read.'),
            default => __('Not looked at.'),
        };
    }

    /** How the author agreement reads, or null when there is nothing to say. */
    public function authorVerdict(array $candidate): ?string
    {
        $overlap = $candidate['author_overlap'] ?? null;

        if ($overlap === null) {
            return null;
        }

        if ($overlap >= 1.0) {
            return __('Same author');
        }

        if ($overlap > 0.0) {
            return __('Shares an author name');
        }

        return __('Different author');
    }
}; ?>

<div class="w-full space-y-6">
    @php($series = $this->series)
    @php($search = $this->search)

    {{-- Back to the series ------------------------------------------------- --}}
    <div class="flex items-center gap-2 text-sm">
        <a href="{{ route('series', ['manga' => $this->manga]) }}" wire:navigate class="text-zinc-500 hover:underline dark:text-zinc-400">
            &larr; {{ __('Back to the series') }}
        </a>
    </div>

    @if ($series === null)
        {{-- The server could not be read, which is not the same as the series
             being gone. Say which, because the reader's next move differs. --}}
        <flux:callout variant="danger" icon="exclamation-triangle">
            <flux:heading level="2" size="sm" class="mb-1">{{ __('This series could not be read') }}</flux:heading>
            <p class="text-sm">{{ __('The server did not answer. Nothing has been changed.') }}</p>
        </flux:callout>
    @else
        {{-- What is being moved -------------------------------------------- --}}
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <flux:heading level="1" size="xl" class="truncate">{{ $series['title'] }}</flux:heading>
                <flux:text size="sm" class="mt-1">
                    {{ __('On') }} <span class="font-medium">{{ $series['source']['name'] }}</span>
                    <span class="text-zinc-500">({{ $series['source']['lang'] }})</span>
                    @if (($series['chapters']['totalCount'] ?? 0) > 0)
                        &middot; {{ trans_choice(
                            ':count chapter|:count chapters',
                            (int) $series['chapters']['totalCount'],
                            ['count' => (int) $series['chapters']['totalCount']],
                        ) }}
                    @endif
                </flux:text>
            </div>
        </div>

        {{--
            A plain explanation, because the wizard moves a series and carries read
            state with it. Measured: chapter numbers placed 41 of 41 chapters across
            two sources, and chapter labels placed none of them, so what carries over
            is worth saying out loud before rather than after.
        --}}
        <flux:callout variant="secondary" icon="information-circle">
            <flux:heading level="2" size="sm" class="mb-1">{{ __('What moving does') }}</flux:heading>
            <ul class="list-inside list-disc space-y-1 text-sm">
                <li>{{ __('This series points at the new source, and stays in your library and on its shelves.') }}</li>
                <li>{{ __('Your read progress moves across, chapter by chapter, matched on chapter number.') }}</li>
                <li>{{ __('Anything that cannot be matched is reported afterwards rather than dropped quietly.') }}</li>
                <li>{{ __('Pages you already downloaded stay on disk and can be deleted from the downloads page.') }}</li>
            </ul>
        </flux:callout>

        {{--
            Search again.

            Offered whenever a search exists rather than only when one found nothing,
            because the sources move under a reader's feet: a site that had the series
            yesterday may list it today, and a list from an hour ago is the wrong list
            to decide on. The first run reuses its row so progress has somewhere to go.
        --}}
        <div class="flex justify-end">
            <flux:button
                {{--
                    `base`, not `default`. Flux sizes are base, sm, xs and lg with a
                    sealed match, so an unhandled value throws "Unhandled match case"
                    from inside the component rather than rendering something wrong.
                --}}
                variant="{{ $search === null ? 'primary' : 'ghost' }}"
                size="{{ $search === null ? 'base' : 'sm' }}"
                icon="magnifying-glass"
                wire:click="findSources"
            >
                {{ $search === null ? __('Look for another source') : __('Search again') }}
            </flux:button>
        </div>

        {{-- Searching --------------------------------------------------------- --}}
        @if ($this->stillRunning())
            <flux:card class="p-4">
                <div class="flex items-center justify-between gap-4">
                    <flux:heading size="sm" level="3">{{ __('Searching every installed source') }}</flux:heading>
                    <flux:text size="sm" class="tabular-nums text-zinc-500">
                        {{ $search->sources_searched }} / {{ $search->sources_total ?: '?' }}
                    </flux:text>
                </div>

                {{--
                    A determinate bar, because the count is known. Measured: this
                    takes about 22 seconds across 82 sources, and the page polls
                    every three.
                --}}
                @if ($search->sources_total > 0)
                    <div
                        class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"
                        role="progressbar"
                        aria-valuemin="0"
                        aria-valuemax="{{ $search->sources_total }}"
                        aria-valuenow="{{ $search->sources_searched }}"
                    >
                        <div
                            class="h-full rounded-full bg-purple-500 transition-[width] duration-500"
                            style="width: {{ (int) round(($search->sources_searched / $search->sources_total) * 100) }}%"
                        ></div>
                    </div>
                @endif
            </flux:card>
        @endif

        {{-- Failed ------------------------------------------------------------ --}}
        @if ($search !== null && ($search->state === MigrationSearch::FAILED || $search->isStalled()))
            <flux:callout variant="danger" icon="exclamation-triangle">
                <flux:heading level="2" size="sm" class="mb-1">{{ __('The search could not finish') }}</flux:heading>
                <p class="text-sm">{{ $search->isStalled() ? $search->stalledReason() : $search->error }}</p>
                <flux:button class="mt-3" size="sm" wire:click="findSources">
                    {{ __('Try again') }}
                </flux:button>
            </flux:callout>
        @endif

        {{-- Ready ------------------------------------------------------------- --}}
        @if ($search !== null && $search->state === MigrationSearch::READY)
            @if ($search->candidates === [])
                <flux:callout variant="secondary" icon="magnifying-glass">
                    <flux:heading level="2" size="sm" class="mb-1">{{ __('No other source has it') }}</flux:heading>
                    <p class="text-sm">
                        {{ trans_choice(
                            'Searched :count source and found nothing close enough.|Searched :count sources and found nothing close enough.',
                            (int) $search->sources_total,
                            ['count' => (int) $search->sources_total],
                        ) }}
                    </p>
                    <flux:button class="mt-3" size="sm" wire:click="findSources">
                        {{ __('Search again') }}
                    </flux:button>
                </flux:callout>
            @else
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                    {{ trans_choice(
                        'Found on :count source, best first. The numbers are what was measured, not a guess.|Found on :count sources, best first. The numbers are what was measured, not a guess.',
                        count($search->candidates),
                        ['count' => count($search->candidates)],
                    ) }}
                </flux:text>

                <flux:card class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($search->candidates as $index => $candidate)
                        @php($blocked = $this->candidateBlocked($candidate))
                        @php($author = $this->authorVerdict($candidate))

                        <div class="flex flex-wrap items-center gap-3 p-4">
                            {{-- Rank. A number rather than a badge, because the
                                 ordering is the claim being made. --}}
                            <span class="w-6 shrink-0 text-sm tabular-nums text-zinc-400">
                                {{ $index + 1 }}
                            </span>

                            <div class="min-w-0 flex-1">
                                <flux:heading size="sm" level="3" class="truncate">
                                    {{ $candidate['title'] }}
                                </flux:heading>

                                <flux:text size="xs" class="truncate">
                                    {{ $candidate['source_name'] }}
                                    <span class="text-zinc-500">({{ $candidate['source_lang'] }})</span>
                                </flux:text>

                                {{-- The measured signals, each stated. A reader is
                                     being asked to choose, so the reasons are shown
                                     rather than folded into one opaque number. --}}
                                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                    <span class="text-zinc-500 dark:text-zinc-400" title="{{ __('How alike the titles are') }}">
                                        {{ __('Title') }} {{ round($candidate['title_similarity'] * 100) }}%
                                    </span>

                                    <span class="text-zinc-500 dark:text-zinc-400">
                                        {{ $this->chapterVerdict($candidate) }}
                                    </span>

                                    @if ($author !== null)
                                        <span @class([
                                            'text-emerald-600 dark:text-emerald-400' => $author === __('Same author'),
                                            'text-zinc-500 dark:text-zinc-400' => $author !== __('Same author'),
                                        ])>{{ $author }}</span>
                                    @endif

                                    @if ($blocked !== null)
                                        <span class="text-amber-600 dark:text-amber-400">{{ $blocked }}</span>
                                    @endif
                                </div>
                            </div>

                            @if ($blocked === null)
                                <flux:button size="xs" variant="primary" wire:click="moveTo({{ (int) $candidate['manga_id'] }})">
                                    {{ __('Move here') }}
                                </flux:button>
                            @endif
                        </div>
                    @endforeach
                </flux:card>
            @endif
        @endif

        {{-- What the move did ------------------------------------------------- --}}
        @if ($this->result !== null)
            <flux:callout variant="success" icon="check-circle">
                <flux:heading level="2" size="sm" class="mb-1">{{ __('Moved') }}</flux:heading>

                <p class="text-sm">
                    {{ __('Now on') }} <span class="font-medium">{{ $this->result->to_source_name }}</span>.
                </p>

                {{--
                    The alignment report, which is the point of the whole exercise.
                    A transfer that silently lost three chapters and one that carried
                    everything look identical otherwise.
                --}}
                <p class="mt-2 text-sm">
                    {{ trans_choice(
                        ':count chapter kept its place.|:count chapters kept their place.',
                        (int) $this->result->chapters_carried,
                        ['count' => (int) $this->result->chapters_carried],
                    ) }}
                    @if ($this->result->chaptersLost() > 0)
                        <span class="text-amber-600 dark:text-amber-400">
                            {{ trans_choice(
                                ':count chapter found no match.|:count chapters found no match.',
                                $this->result->chaptersLost(),
                                ['count' => $this->result->chaptersLost()],
                            ) }}
                        </span>
                    @endif
                </p>

                @if ($this->result->unmatched_chapters !== [])
                    <details class="mt-2 text-sm">
                        <summary class="cursor-pointer text-zinc-500 hover:underline dark:text-zinc-400">
                            {{ __('Which ones did not match') }}
                        </summary>
                        <ul class="mt-1 list-inside list-disc text-zinc-600 dark:text-zinc-300">
                            @foreach ($this->result->unmatched_chapters as $label)
                                <li>{{ $label }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif

                <flux:button class="mt-3" size="sm" variant="primary"
                    href="{{ route('series', ['manga' => $this->result->to_manga_id]) }}" wire:navigate>
                    {{ __('Go to the series') }}
                </flux:button>
            </flux:callout>
        @endif
    @endif
</div>
