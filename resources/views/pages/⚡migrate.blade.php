<?php

use App\Jobs\FindMigrationCandidates;
use App\Migration\CandidateFinder;
use App\Migration\MigrationSettings;
use App\Migration\SeriesMigrator;
use App\Models\AniListAccount;
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

    /**
     * The move the dialog is holding open, as the candidate's manga id.
     *
     * Armed by requestMove(), cleared by the cancel route and by a successful
     * move. It is deliberately not what migrates: the migrator re-checks the
     * candidate against the search regardless, this only names the row the
     * dialog describes.
     */
    public ?int $pendingMove = null;

    /**
     * The dialog's four answers.
     *
     * Safe set on every open, because a remembered choice would outlive its
     * context: a checked downloads box from yesterday must not delete pages
     * from today's move.
     */
    public bool $carryChapters = true;
    public bool $carryShelves = true;
    public bool $tellTracking = false;
    public bool $removeDownloads = false;

    /**
     * Candidates the reader stepped aside from on this look.
     *
     * Deliberately not stored with the search: a reload is a fresh look at the
     * same list, and a row that was set aside comes back because nothing was
     * decided against it. It is a to-do about attention, not a verdict.
     */
    public array $dismissed = [];

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
         *
         * The denominator rides the row from the press on, counted from the rows
         * the sweep will walk. Measured live on 2026-10-16: without it the card
         * read "0 / ?" for the whole first stretch, because the total only ever
         * came from the job's first throttled write five seconds in, and a bar
         * with no total is not drawn at all.
         */
        $total = app(CandidateFinder::class)
            ->sweepTargets(auth()->id(), (string) ($series['source']['id'] ?? ''))
            ->count();

        $search = MigrationSearch::createFor(auth()->id(), [
            'from_manga_id' => $this->manga,
            'state' => MigrationSearch::SEARCHING,
            'sources_searched' => 0,
            'sources_total' => $total,
        ]);

        FindMigrationCandidates::dispatch($search->getKey(), auth()->id(), $this->manga);

        Flux::toast(
            text: trans_choice(
                'Searching :count other installed source, about half a minute.|Searching :count other installed sources, about half a minute.',
                $total,
                ['count' => $total],
            ),
            heading: __('Searching'),
            variant: 'info',
        );

        $this->dispatch('$refresh');
    }

    /**
     * Arm the settings dialog for one candidate.
     *
     * The press no longer moves on contact. The dialog belongs to the press and
     * takes its answers from there, which is the shape the install warning took
     * on the sources page for the same reason: the request that arms it has to
     * land before anything opens on it, so the page's root div translates the
     * dispatched event into Flux's own modal-show.
     */
    public function requestMove(int $toManga): void
    {
        // The safe set on every open. A remembered choice outlived its context:
        // yesterday's ticked downloads box has no business deleting anything
        // from today's move.
        $this->carryChapters = true;
        $this->carryShelves = true;
        $this->tellTracking = $this->trackConnected;
        $this->removeDownloads = false;

        $this->pendingMove = $toManga;

        $this->dispatch('open-migration-settings');
    }

    /** The cancel route: close the dialog, leave everything as it was. */
    public function closeMigrationDialog(): void
    {
        $this->pendingMove = null;
    }

    /**
     * Move the series onto the candidate the dialog is holding open.
     *
     * The candidate is re-checked against the search inside the migrator, so a value
     * this method was tricked into accepting is refused there rather than trusted
     * here. See SeriesMigrator.
     */
    public function moveConfirmed(): void
    {
        if ($this->pendingMove === null) {
            return;
        }

        $outcome = app(SeriesMigrator::class)->migrate(auth()->user(), $this->manga, $this->pendingMove, new MigrationSettings(
            carryChapters: $this->carryChapters,
            carryShelves: $this->carryShelves,
            tellTracking: $this->tellTracking,
            removeDownloads: $this->removeDownloads,
        ));

        if (! $outcome['ok']) {
            Flux::toast(
                text: (string) $outcome['reason'],
                heading: __('Not moved'),
                variant: 'danger',
            );

            // Deliberately not closed: the reason a move refuses is usually about
            // what is ticked and what the source now lists, both of which are
            // answers this dialog can still change.
            return;
        }

        /** @var SeriesMigration $migration */
        $migration = $outcome['migration'];

        $this->resultId = $migration->getKey();
        $this->pendingMove = null;

        $this->dispatch('close-migration-settings');

        Flux::toast(
            text: $migration->carried_chapters
                ? trans_choice(
                    ':count chapter kept its place.|:count chapters kept their place.',
                    (int) $migration->chapters_carried,
                    ['count' => (int) $migration->chapters_carried],
                )
                : __('Moved.'),
            heading: __('Moved'),
            variant: 'success',
        );

        $this->dispatch('$refresh');
    }

    /**
     * Take one candidate out of this look.
     *
     * Not stored with the search: this is a to-do about attention, not a verdict
     * about the source, and the row returns the next time anyone opens the page.
     */
    public function dismiss(int $mangaId): void
    {
        $this->dismissed[] = $mangaId;
    }

    /**
     * Whether this reader's progress has an AniList account to go to.
     *
     * The dialog's tracking row exists only when it can do something, because a
     * checkbox that is wired to nothing is a promise the page would be making
     * up. Cached as a computed: the page renders it twice per request.
     */
    #[Computed]
    public function trackConnected(): bool
    {
        return AniListAccount::forUser((int) auth()->id())->exists();
    }

    /**
     * The candidate the dialog is holding open, or null when nothing is.
     *
     * A computed and read as a property: the dialog reads it in three places,
     * and calling the scan again would be a search row read per read.
     */
    #[Computed]
    public function pendingCandidate(): ?array
    {
        $search = $this->search;

        if ($this->pendingMove === null || $search === null) {
            return null;
        }

        foreach ($search->candidates as $candidate) {
            if ((int) $candidate['manga_id'] === $this->pendingMove) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The candidates still on the board, best first.
     *
     * Stepping aside removes the row and lets the numbering close behind it, so
     * the list keeps a rank for every row it shows. The search row is untouched.
     */
    #[Computed]
    public function shownCandidates(): array
    {
        $dismissed = array_map('intval', $this->dismissed);

        return array_values(array_filter(
            $this->search?->candidates ?? [],
            fn (array $candidate): bool => ! in_array((int) $candidate['manga_id'], $dismissed, true),
        ));
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

<div
    class="w-full space-y-6"
    x-on:open-migration-settings.window="$dispatch('modal-show', { name: 'migration-settings' })"
    x-on:close-migration-settings.window="$dispatch('modal-close', { name: 'migration-settings' })"
>
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
        <x-page.header>
            <x-slot:eyebrow>{{ __('A new home for an old friend.') }}</x-slot:eyebrow>
            <x-slot:title>{{ $series['title'] }}</x-slot:title>

            <x-slot:meta>
                {{ __('On') }} <span class="font-medium">{{ $series['source']['name'] }}</span>
                <span class="text-zinc-500">({{ $series['source']['lang'] }})</span>
                @if (($series['chapters']['totalCount'] ?? 0) > 0)
                    &middot; {{ trans_choice(
                        ':count chapter|:count chapters',
                        (int) $series['chapters']['totalCount'],
                        ['count' => (int) $series['chapters']['totalCount']],
                    ) }}
                @endif
            </x-slot:meta>
        </x-page.header>

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
            {{--
                The poll rides the card it refreshes, and the card's unmounting when
                the search settles is the stop.

                The page used to declare polling through a `with()` array, and nothing
                in Livewire or volt ever calls such a method, so the directive never
                reached the rendered page and the page never polled at all: the row
                turned ready in the database while the card kept saying it was
                searching, until a full reload. Measured twice live on 2026-10-16.
                This is the search page's idiom, proven there with a probe: the
                directive sits on the block that shows progress, and a directive on
                an empty element is not reliably started at all.
            --}}
            <div wire:poll.{{ $this::REFRESH_MS / 1000 }}s>
                <flux:card class="p-4">
                    <div class="flex items-center justify-between gap-4">
                        <flux:heading size="sm" level="3">{{ __('Searching the other sources') }}</flux:heading>
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
                                class="h-full rounded-full bg-vermilion-500 transition-[width] duration-500"
                                style="width: {{ (int) round(($search->sources_searched / $search->sources_total) * 100) }}%"
                            ></div>
                        </div>
                    @endif
                </flux:card>
            </div>
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
                {{-- $shown is the board the reader sees: set-aside rows are out and
                     the ranking closes behind them. The count below the text is
                     theirs, because a list that says 2 while showing 1 is a
                     sentence undoing itself. --}}
                @php($shown = $this->shownCandidates)

                @if (count($shown) === 0)
                    {{-- Everything was set aside. The search found rows, so neither
                         the found count nor a now empty list should describe this;
                         the thing that happened is the reader's own two clicks. --}}
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                        {{ trans_choice(
                            'You set the only candidate aside.|You set all :count candidates aside.',
                            count($search->candidates),
                            ['count' => count($search->candidates)],
                        ) }}
                    </flux:text>
                @endif

                @if (count($shown) > 0)
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                        {{ trans_choice(
                            'Found on :count source, best first. The numbers are what was measured, not a guess.|Found on :count sources, best first. The numbers are what was measured, not a guess.',
                            count($shown),
                            ['count' => count($shown)],
                        ) }}
                    </flux:text>
                @endif

                @if (count($shown) > 0)
                    <flux:card class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($shown as $index => $candidate)
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
                                <flux:button size="xs" variant="primary" wire:click="requestMove({{ (int) $candidate['manga_id'] }})">
                                    {{ __('Move here') }}
                                </flux:button>
                            @else
                                {{-- The two moves a dead end keeps, which is what the
                                     Tachimanga row menu offers at the same spot: look
                                     at the source for yourself, or step this one out
                                     of the way of the ones that can take the move. --}}
                                <div class="flex items-center gap-2">
                                    @if (($candidate['source_browse_id'] ?? null) !== null)
                                        <flux:button
                                            size="xs"
                                            icon="magnifying-glass"
                                            href="{{ route('browse', ['source' => $candidate['source_browse_id'], 'q' => $series['title']]) }}"
                                            wire:navigate
                                        >
                                            {{ __('Search manually') }}
                                        </flux:button>
                                    @endif

                                    <flux:button size="xs" variant="ghost" wire:click="dismiss({{ (int) $candidate['manga_id'] }})">
                                        {{ __('Not this one') }}
                                    </flux:button>
                                </div>
                            @endif
                        </div>
                        @endforeach
                    </flux:card>
                @endif
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
                    everything look identical otherwise. Told from the run row, which
                    is also where the reader's own choices live: the page says "as you
                    chose" about every line a choice made, because the row recorded
                    what was asked alongside what happened.
                --}}
                @php($result = $this->result)

                @if ($result->carried_chapters)
                    <p class="mt-2 text-sm">
                        {{ trans_choice(
                            ':count chapter kept its place.|:count chapters kept their place.',
                            (int) $result->chapters_carried,
                            ['count' => (int) $result->chapters_carried],
                        ) }}
                        @if ($result->chaptersLost() > 0)
                            <span class="text-amber-600 dark:text-amber-400">
                                {{ trans_choice(
                                    ':count chapter found no match.|:count chapters found no match.',
                                    $result->chaptersLost(),
                                    ['count' => $result->chaptersLost()],
                                ) }}
                            </span>
                        @endif
                    </p>
                @else
                    {{-- The carry the reader unticked. The rows stayed on the old
                         chapter ids, where they still hold the place, which is the
                         same fact the honesty block promises for downloads. --}}
                    <p class="mt-2 text-sm">
                        {{ __('Its read progress stayed on the old source, as you chose.') }}
                    </p>
                @endif

                <p class="text-sm">
                    @if ($result->carried_shelves)
                        {{ __('It kept its shelves.') }}
                    @else
                        {{ __('It came off its shelves, as you chose.') }}
                    @endif
                </p>

                @if ($result->tracking_requested)
                    <p class="text-sm">
                        {{ __('AniList was told to catch up with its progress.') }}
                    </p>
                @endif

                @if ($result->remove_downloads)
                    <p class="text-sm">
                        {{ trans_choice(
                            ':count downloaded chapter was deleted.|:count downloaded chapters were deleted.',
                            (int) $result->downloads_removed,
                            ['count' => (int) $result->downloads_removed],
                        ) }}

                        @if ($result->downloads_cancelled > 0)
                            {{ trans_choice(
                                ':count queued download was taken off the queue.|:count queued downloads were taken off the queue.',
                                (int) $result->downloads_cancelled,
                                ['count' => (int) $result->downloads_cancelled],
                            ) }}
                        @endif
                    </p>

                    @if ($result->downloads_unremoved > 0)
                        <p class="text-sm text-amber-600 dark:text-amber-400">
                            {{ trans_choice(
                                ':count of them could not be deleted and is still listed in the download centre.|:count of them could not be deleted and are still listed in the download centre.',
                                (int) $result->downloads_unremoved,
                                ['count' => (int) $result->downloads_unremoved],
                            ) }}
                        </p>
                    @endif
                @endif

                {{-- Null matters here: a not-carried move records that nothing was
                     attempted, which the cast leaves as null rather than []. --}}
                @if ($result->unmatched_chapters !== null && $result->unmatched_chapters !== [])
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

    {{--
        The settings dialog, one for the whole page rather than one per candidate,
        for the same reason the install warning carries: the dialog belongs to the
        press, not to the row, and the destination it names is whichever candidate
        that press was on.

        Opened from the response rather than from the click, with the race the
        install warning's comment records inverted in one place: the request that
        arms the move has to land before anything opens on it, so the root div
        re-dispatches Flux's own modal-show when the actions dispatch
        open-migration-settings. The confirm is a plain wire button rather than
        flux:modal.close, so the move, the pending reset and the close run as one
        request; on a refusal the dialog stays open, because the reason a move
        refuses is usually about what is ticked.
    --}}
    <flux:modal
        name="migration-settings"
        class="max-w-md"
        @close="closeMigrationDialog"
    >
        @php($pending = $this->pendingCandidate)

        @if ($pending !== null)
            <div class="flex items-start gap-4">
                <span
                    class="mt-0.5 flex size-10 flex-none items-center justify-center rounded-xl bg-vermilion-600/10 text-vermilion-600 dark:bg-vermilion-500/15 dark:text-vermilion-400"
                    aria-hidden="true"
                >
                    <flux:icon name="arrows-right-left" class="size-5" />
                </span>

                <div class="min-w-0">
                    <flux:heading size="lg">
                        {{ __('What the move carries') }}
                    </flux:heading>

                    <flux:subheading class="mt-1">
                        {{ __('Moving to') }}
                        <strong class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $pending['title'] }}</strong>
                        {{ __('on') }} {{ $pending['source_name'] }}
                        <span class="text-zinc-500">({{ $pending['source_lang'] }})</span>
                    </flux:subheading>

                    {{--
                        Four rows because that is what a move can touch, and no more
                        than the engine actually parameterizes. The tracking row is
                        absent for a reader with no AniList account: a checkbox wired
                        to nothing would be a promise the page making up. The notes
                        sit under their rows the import page's form does.
                    --}}
                    <div class="mt-4 space-y-3">
                        <flux:checkbox label="{{ __('Read progress') }}" wire:model="carryChapters" />
                        <p class="-mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                            {{ __('Chapters read here stay read there, matched on chapter number.') }}
                        </p>

                        <flux:checkbox label="{{ __('Shelves') }}" wire:model="carryShelves" />
                        <p class="-mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                            {{ __('It stays on its shelves.') }}
                        </p>

                        @if ($this->trackConnected)
                            <flux:checkbox label="{{ __('Tell AniList') }}" wire:model="tellTracking" />
                            <p class="-mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ __('Your progress on it is pushed to AniList after the move.') }}
                            </p>
                        @endif

                        <flux:checkbox label="{{ __('Remove downloaded pages') }}" wire:model="removeDownloads" />
                        <p class="-mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                            {{ __('The pages the old home holds are deleted after the move. Left alone they stay on disk, and the download centre can delete them later.') }}
                        </p>
                    </div>

                    <flux:subheading class="mt-4">
                        {{ __('Nothing moves until this is confirmed, and cancelling leaves everything as it is.') }}
                    </flux:subheading>
                </div>
            </div>

            <div class="mt-7 flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Never mind') }}</flux:button>
                </flux:modal.close>

                <flux:button
                    variant="primary"
                    wire:click="moveConfirmed"
                    wire:loading.attr="disabled"
                >
                    {{ __('Move here') }}
                </flux:button>
            </div>
        @endif
    </flux:modal>
</div>
