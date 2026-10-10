<?php

use App\Jobs\BuildBulkMigration;
use App\Migration\BulkMover;
use App\Models\BulkMigration;
use App\Models\BulkMigrationItem;
use App\Support\Library;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Move a whole source')] class extends Component
{
    /**
     * The source being left, as a Suwayomi source id.
     *
     * A string, never a row id, because a name is not an identity. Measured: MangaFire
     * appears as seven language variants and several share a display name, so "everything on
     * MangaFire" is ambiguous where "everything on source id X" is not.
     */
    public string $from = '';

    public string $to = '';

    /**
     * Stop after this many series.
     *
     * Zero means the configured ceiling. Offered because comparing a shelf is one request
     * per series and a reader should be able to look at ten before committing to eighty.
     */
    public int $limit = 0;

    /**
     * Which series are ticked, as from_manga_id => bool.
     *
     * Only the reader's own explicit choices are stored here. A row the reader has not touched
     * is ticked or not by {@see isTicked()} reading the stored comparison, which means the
     * default cannot drift away from the numbers shown beside it. A key present with `false`
     * is a deliberate untick and is different from an absent key.
     *
     * @var array<int, bool>
     */
    public array $approved = [];

    /** The run being shown, pinned by the reader or the newest one. */
    public ?int $runId = null;

    /**
     * What the last approval did, so it can be reported once and not re-derived on every
     * render from rows the reader may have since changed.
     *
     * @var array{moved: int, refused: int, chapters_carried: int, reasons: array<int, string>}|null
     */
    public ?array $report = null;

    /**
     * How often the page looks for a finished comparison.
     *
     * Matched to the progress interval in `BulkSourceMatcher`, so the number moves about as
     * often as the page asks for it.
     */
    public const REFRESH_MS = 3000;

    /**
     * Sources with at least one of this reader's series on them, biggest first.
     *
     * ## Why the count is on the button
     *
     * Because the reader's question is "which of these is the one holding my library", and a
     * list of 82 names does not answer that. Putting the number on each entry answers it
     * before the click, and a source with nothing on it is not offered at all, because
     * choosing it can only ever produce "nothing in your library is on that source".
     *
     * @return \Illuminate\Support\Collection<int, array{source_id: string, display_name: string, lang: string, count: int, installed: bool}>
     */
    #[Computed]
    public function sourcesInLibrary(): \Illuminate\Support\Collection
    {
        $counts = [];

        foreach (app(Library::class)->entries(auth()->id()) as $entry) {
            if ($entry['source_id'] === null) {
                continue;
            }

            $id = (string) $entry['source_id'];

            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }

        $rows = \App\Models\Source::query()
            ->whereIn('suwayomi_source_id', array_keys($counts))
            ->get()
            ->keyBy(fn ($source): string => (string) $source->suwayomi_source_id);

        $out = collect();

        foreach ($counts as $id => $count) {
            $source = $rows->get((string) $id);

            $out->push([
                'source_id' => (string) $id,
                'display_name' => (string) ($source->display_name ?? $id),
                'lang' => (string) ($source->lang ?? ''),
                'count' => $count,
                'installed' => (bool) ($source->is_installed ?? false),
            ]);
        }

        return $out->sortByDesc('count')->values();
    }

    /** The run this page is showing. The newest one, unless the reader pinned another. */
    #[Computed]
    public function run(): ?BulkMigration
    {
        return BulkMigration::query()
            ->forUser(auth()->id())
            ->when($this->runId !== null, fn ($query) => $query->whereKey($this->runId))
            ->latest('id')
            ->first();
    }

    /**
     * The series with somewhere to go, and those with nowhere, kept apart.
     *
     * Two lists rather than one with a disabled row, because a reader scanning this table is
     * looking for the ones that need a decision and a long tail of "not there" buries them.
     * Both counts are still stated above the table.
     *
     * @return \Illuminate\Support\Collection<int, BulkMigrationItem>
     */
    #[Computed]
    public function offers(): \Illuminate\Support\Collection
    {
        return $this->items()->filter(fn (BulkMigrationItem $i): bool => $i->to_manga_id !== null)->values();
    }

    /** @return \Illuminate\Support\Collection<int, BulkMigrationItem> */
    #[Computed]
    public function items(): \Illuminate\Support\Collection
    {
        $run = $this->run;

        return $run === null
            ? collect()
            : BulkMigrationItem::query()->forUser(auth()->id())->where('bulk_migration_id', $run->id)->get();
    }

    /**
     * Start comparing. Two sources, or it refuses before dispatching anything.
     */
    public function compare(): void
    {
        if ($this->from === '' || $this->to === '') {
            Flux::toast(
                text: __('Choose the source to move away from and the one to move to.'),
                heading: __('Nothing to compare'),
                variant: 'danger',
            );

            return;
        }

        if ($this->from === $this->to) {
            Flux::toast(
                text: __('That is the same source twice. Moving a source onto itself does nothing.'),
                heading: __('Nothing to compare'),
                variant: 'danger',
            );

            return;
        }

        $run = BulkMigration::createFor(auth()->id(), [
            'from_source_id' => $this->from,
            'to_source_id' => $this->to,
            'state' => BulkMigration::RUNNING,
            'started_at' => now(),
        ]);

        BuildBulkMigration::dispatch($run->getKey(), (int) auth()->id(), max(0, $this->limit));

        $this->runId = $run->getKey();
        $this->report = null;

        // A new comparison means every tick from the previous one is meaningless.
        $this->reset('approved');

        Flux::toast(
            text: __('Comparing your library against that source. This is one request per series, so it takes a while.'),
            heading: __('Comparing'),
            variant: 'info',
        );
    }

    /**
     * The series actually ticked, defaults included.
     *
     * @return \Illuminate\Support\Collection<int, int> from_manga_id to to_manga_id
     */
    #[Computed]
    public function effectiveApprovals(): \Illuminate\Support\Collection
    {
        $out = collect();

        foreach ($this->offers as $item) {
            if ($this->isTicked($item) && $item->isOfferable()) {
                $out->put((int) $item->from_manga_id, (int) $item->to_manga_id);
            }
        }

        return $out;
    }

    /**
     * Whether a row is ticked, the reader's choice or the measured default.
     *
     * `array_key_exists` rather than a truthiness check, because an unticked row and a row
     * that was never touched have to be told apart: the second should fall back to the
     * default, the first must not.
     */
    public function isTicked(BulkMigrationItem $item): bool
    {
        $id = (int) $item->from_manga_id;

        if (array_key_exists($id, $this->approved)) {
            return (bool) $this->approved[$id];
        }

        return $item->isConfident((float) config('papyrus.migration.confident_title_similarity', 0.95));
    }

    public function tickAll(): void
    {
        foreach ($this->offers as $item) {
            if ($item->isOfferable()) {
                $this->approved[(int) $item->from_manga_id] = true;
            }
        }
    }

    public function untickAll(): void
    {
        $this->reset('approved');
    }

    /**
     * Carry out the ticked moves.
     *
     * One transaction per series, inside the migrator, so a refusal at the fortieth leaves the
     * thirty-nine good ones standing and reported rather than rolling the lot back into
     * nothing. See `BulkMover`.
     */
    public function apply(): void
    {
        $run = $this->run;
        $approvals = $this->effectiveApprovals;

        if ($run === null || $approvals->isEmpty()) {
            Flux::toast(
                text: __('Nothing is ticked.'),
                heading: __('Nothing to move'),
                variant: 'danger',
            );

            return;
        }

        $tally = app(BulkMover::class)->move($run, $approvals);

        $this->report = $tally;

        if ($tally['moved'] === 0) {
            Flux::toast(
                text: $tally['reasons'] === []
                    ? __('Nothing moved. The reasons are beside each series.')
                    : (string) reset($tally['reasons']),
                heading: __('Nothing moved'),
                variant: 'danger',
            );

            return;
        }

        Flux::toast(
            text: trans_choice(
                ':count series moved, :chapters chapters kept their place.|:count series moved, :chapters chapters kept their place.',
                $tally['moved'],
                [
                    'count' => number_format($tally['moved']),
                    'chapters' => number_format($tally['chapters_carried']),
                ],
            ),
            heading: __('Moved'),
            variant: 'success',
        );
    }

    /**
     * Whether a comparison is genuinely still going, as opposed to a row that stopped moving.
     *
     * A stalled comparison counts as finished, for the reason every other page in this app
     * does it: a page that can poll for ever is worse than one that admits it lost track.
     */
    public function stillRunning(): bool
    {
        $run = $this->run;

        return $run !== null && $run->state === BulkMigration::RUNNING && ! $run->isStalled();
    }

    public function with(): array
    {
        return $this->stillRunning() ? ['poll' => self::REFRESH_MS . 'ms'] : [];
    }

    /**
     * The chapter comparison, phrased for a reader.
     *
     * Count and gap together, because either alone misleads: 41 against 41 is the same series
     * and 333 against 41 is a different entry that happens to share a title. Both were measured
     * for one real series across six sites.
     */
    /*
     * `trans_choice` with the whole sentence in both branches, never `{1}` inside it.
     *
     * Laravel takes the branch from the pipe in the key and treats `{1}` in the value as a
     * literal string it does not touch. Measured in this project: `trans_choice($key, 1)`
     * rendered the literal `1 was already up to date`. So the differing word goes in the key
     * and the number is always substituted.
     */
    public function chapterVerdict(BulkMigrationItem $item): string
    {
        if ($item->chapters_there === null) {
            return __('Not looked at yet.');
        }

        $delta = (int) $item->chapter_delta;

        if ($delta === 0) {
            return trans_choice(
                'Same as here: :count chapter.|Same as here: :count chapters.',
                (int) $item->chapters_there,
                ['count' => number_format($item->chapters_there)],
            );
        }

        return $delta > 0
            ? trans_choice(
                ':count chapter, further along.|:count chapters, further along.',
                (int) $item->chapters_there,
                ['count' => number_format($item->chapters_there)],
            )
            : trans_choice(
                ':count chapter, behind.|:count chapters, behind.',
                (int) $item->chapters_there,
                ['count' => number_format($item->chapters_there)],
            );
    }

    /** How the author agreement reads, or null when there is nothing to say. */
    public function authorVerdict(BulkMigrationItem $item): ?string
    {
        if ($item->author_overlap === null) {
            return null;
        }

        if ($item->author_overlap >= 1.0) {
            return __('Same author');
        }

        return $item->author_overlap > 0.0
            ? __('Shares an author name')
            : __('Different author');
    }

    /**
     * The button label for a chosen source, or null when nothing is chosen yet.
     *
     * Looked up from the same list the menu renders, so the label and the menu can never
     * disagree about what a source is called.
     *
     * @param  \Illuminate\Support\Collection<int, array{source_id: string, display_name: string, lang: string, count: int, installed: bool}>  $sources
     */
    public function labelFor(string $sourceId, \Illuminate\Support\Collection $sources): ?string
    {
        if ($sourceId === '') {
            return null;
        }

        $match = $sources->firstWhere('source_id', $sourceId);

        if ($match === null) {
            // A source that has since left the reader's library. The id is shown rather than
            // a blank button, because a blank picker next to a filled one is unreadable.
            return __('Source :id', ['id' => $sourceId]);
        }

        return $match['display_name'].' ('.$match['lang'].')';
    }

    public function stateLabel(BulkMigration $run): string
    {
        return match ($run->state) {
            BulkMigration::READY => __('Ready'),
            BulkMigration::RUNNING => __('Comparing'),
            BulkMigration::FAILED => __('Stopped'),
            default => $run->state,
        };
    }
};
?>

<div class="w-full space-y-6">
    @php($run = $this->run)

    <x-page.header>
        <x-slot:eyebrow>{{ __('Move a whole source at once.') }}</x-slot:eyebrow>
        <x-slot:title>{{ __('Move your library to another source') }}</x-slot:title>

        <x-slot:meta>
            {{ __('Pick the source you are leaving and the one you are moving to. Every series you have saved from the first is looked up on the second, and you decide what moves.') }}
        </x-slot:meta>
    </x-page.header>

    {{-- What this does, before anything is chosen. The wizard says this too, and it is
         worth saying here because a run of eighty moves is a bigger thing to press once. --}}
    <flux:callout variant="secondary" icon="information-circle">
        <flux:heading level="2" size="sm" class="mb-1">{{ __('What this does') }}</flux:heading>
        <ul class="list-inside list-disc space-y-1 text-sm">
            <li>{{ __('Each series is searched for on the new source by its title, and the result is compared on title, chapter count and author.') }}</li>
            <li>{{ __('Nothing moves until you tick it. A tick is a suggestion, not a decision: change any of them.') }}</li>
            <li>{{ __('Your read progress moves with the series, matched chapter by chapter on chapter number.') }}</li>
            <li>{{ __('Anything that cannot be matched is reported afterwards rather than dropped quietly.') }}</li>
            <li>{{ __('Pages you already downloaded stay on disk and can be deleted from the downloads page.') }}</li>
        </ul>
    </flux:callout>

    {{-- Picking the two ends ------------------------------------------------ --}}
    <flux:card class="space-y-4 p-4">
        @php($sources = $this->sourcesInLibrary)

        @if ($sources->isEmpty())
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Nothing in your library is on a source this server knows about, so there is nothing to move.') }}
            </p>
        @else
            <div class="flex flex-wrap items-end gap-3">
                {{-- Leaving --}}
                <div class="min-w-56">
                    <flux:label>{{ __('Moving away from') }}</flux:label>

                    <flux:dropdown placement="bottom-start" class="w-full">
                        <flux:button variant="outline" class="w-full justify-between" icon="chevron-down">
                            {{ $this->labelFor($this->from, $sources) ?? __('Choose a source') }}
                        </flux:button>

                        <flux:menu class="max-h-80 overflow-y-auto">
                            @foreach ($sources as $one)
                                <flux:menu.item wire:click="$set('from', '{{ $one['source_id'] }}')">
                                    {{ $one['display_name'] }}
                                    <span class="text-zinc-500">({{ $one['lang'] }})</span>
                                    <span class="ms-auto tabular-nums text-zinc-500">{{ $one['count'] }}</span>
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                </div>

                <flux:icon.chevron-right class="mb-3 text-zinc-400" />

                {{-- Moving to --}}
                <div class="min-w-56">
                    <flux:label>{{ __('Moving to') }}</flux:label>

                    <flux:dropdown placement="bottom-start" class="w-full">
                        <flux:button variant="outline" class="w-full justify-between" icon="chevron-down">
                            {{ $this->labelFor($this->to, $sources) ?? __('Choose a source') }}
                        </flux:button>

                        <flux:menu class="max-h-80 overflow-y-auto">
                            @foreach ($sources as $one)
                                @continue($one['source_id'] === $this->from)

                                <flux:menu.item wire:click="$set('to', '{{ $one['source_id'] }}')">
                                    {{ $one['display_name'] }}
                                    <span class="text-zinc-500">({{ $one['lang'] }})</span>
                                    <span class="ms-auto tabular-nums text-zinc-500">{{ $one['count'] }}</span>

                                    @unless ($one['installed'])
                                        <flux:badge size="xs" color="orange">{{ __('not installed') }}</flux:badge>
                                    @endunless
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                </div>

                <div class="w-40">
                    <flux:input
                        label="{{ __('Stop after') }}"
                        type="number"
                        min="0"
                        wire:model="limit"
                        placeholder="0"
                    />
                </div>

                <flux:button variant="primary" wire:click="compare" wire:target="compare">
                    {{ __('Compare') }}
                </flux:button>
            </div>

            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                {{ __('Stop after 0 means every series on the first source. The number beside each source is how many of your series sit on it.') }}
            </p>
        @endif
    </flux:card>

    {{-- Comparing ---------------------------------------------------------- --}}
    @if ($this->stillRunning() && $run !== null)
        <flux:card class="space-y-3 p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="sm" level="3">
                    {{ __('Looking up your series on') }} {{ $run->to_source_name ?? $this->to }}
                </flux:heading>

                <flux:text size="sm" class="tabular-nums text-zinc-500">
                    {{ $run->series_checked }} / {{ $run->series_total ?: '?' }}
                </flux:text>
            </div>

            @if ($run->series_total > 0)
                <div
                    class="h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"
                    role="progressbar"
                    aria-valuemin="0"
                    aria-valuemax="{{ $run->series_total }}"
                    aria-valuenow="{{ $run->series_checked }}"
                >
                    <div
                        class="h-full rounded-full bg-vermilion-500 transition-[width] duration-500"
                        style="width: {{ (int) round(($run->series_checked / max(1, $run->series_total)) * 100) }}%"
                    ></div>
                </div>
            @endif

            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                {{ __('This is one request per series to the source you are moving to. A shelf of eighty takes a minute or two.') }}
            </p>
        </flux:card>
    @endif

    {{-- Stopped, and why --------------------------------------------------- --}}
    @if ($run !== null && ($run->state === BulkMigration::FAILED || $run->isStalled()))
        <flux:callout variant="danger" icon="exclamation-triangle">
            <flux:heading level="2" size="sm" class="mb-1">{{ __('The comparison could not finish') }}</flux:heading>
            <p class="text-sm">
                {{ $run->isStalled() ? $run->stalledReason() : $run->error }}
            </p>

            @unless ($run->isStalled())
                <p class="mt-1 text-sm">
                    <code class="font-mono">php artisan queue:work</code>
                </p>
            @endunless
        </flux:callout>
    @endif

    {{-- The result, and the decision ---------------------------------------- --}}
    @if ($run !== null && $run->state === BulkMigration::READY)
        <flux:callout variant="secondary" icon="information-circle">
            <flux:heading level="2" size="sm" class="mb-1">{{ __('What was found') }}</flux:heading>

            <p class="text-sm">
                {{
                    __('Looked at :checked of your series.', ['checked' => number_format($run->series_checked)])
                }}

                @if ($run->series_total > $run->series_checked)
                    {{ __('Your library holds :total on that source.', ['total' => number_format($run->series_total)]) }}
                @endif

                {{
                    trans_choice(
                        ':count of them is on the new source.|:count of them are on the new source.',
                        $run->series_matched,
                        ['count' => number_format($run->series_matched)],
                    )
                }}

                @php($notThere = $this->items->where('status', BulkMigrationItem::SKIPPED)->count())

                @if ($notThere > 0)
                    {{
                        trans_choice(
                            ':count is not there at all.|:count are not there at all.',
                            $notThere,
                            ['count' => number_format($notThere)],
                        )
                    }}
                @endif
            </p>

            @if ($run->error !== null)
                <p class="mt-1 text-sm text-amber-600 dark:text-amber-400">{{ $run->error }}</p>
            @endif
        </flux:callout>

        {{-- What the last approval did --}}
        @if ($this->report !== null)
            <flux:callout variant="{{ $this->report['moved'] > 0 ? 'success' : 'danger' }}" icon="check-circle">
                <flux:heading level="2" size="sm" class="mb-1">
                    {{
                        trans_choice(
                            ':count series moved.|:count series moved.',
                            $this->report['moved'],
                            ['count' => number_format($this->report['moved'])],
                        )
                    }}
                </flux:heading>

                <p class="text-sm">
                    {{
                        trans_choice(
                            ':count chapter kept its place.|:count chapters kept their place.',
                            $this->report['chapters_carried'],
                            ['count' => number_format($this->report['chapters_carried'])],
                        )
                    }}

                    @if ($this->report['refused'] > 0)
                        <span class="text-amber-600 dark:text-amber-400">
                            {{
                                trans_choice(
                                    ':count was refused.|:count were refused.',
                                    $this->report['refused'],
                                    ['count' => number_format($this->report['refused'])],
                                )
                            }}
                        </span>
                    @endif
                </p>
            </flux:callout>
        @endif

        @if ($this->offers->isNotEmpty())
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                    {{ trans_choice(
                        'One series is ticked. Change any of them before moving.|:count series are ticked. Change any of them before moving.',
                        $this->effectiveApprovals->count(),
                        ['count' => number_format($this->effectiveApprovals->count())],
                    ) }}
                </flux:text>

                <div class="flex items-center gap-2">
                    <flux:button size="sm" variant="ghost" wire:click="tickAll">{{ __('Tick all') }}</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="untickAll">{{ __('Untick all') }}</flux:button>

                    <flux:button
                        size="sm"
                        variant="primary"
                        wire:click="apply"
                        wire:target="apply"
                        :disabled="$this->effectiveApprovals->isEmpty()"
                    >
                        {{ trans_choice(
                            'Move the ticked series|Move the :count ticked series',
                            $this->effectiveApprovals->count(),
                            ['count' => number_format($this->effectiveApprovals->count())],
                        ) }}
                    </flux:button>
                </div>
            </div>

            {{-- The offers, each with the numbers beside it --}}
            <flux:card class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->offers as $item)
                    @php($author = $this->authorVerdict($item))

                    <div class="flex flex-wrap items-center gap-3 p-4">
                        <flux:checkbox
                            wire:model.live="approved.{{ $item->from_manga_id }}"
                            label="{{ $item->title }}"
                            class="shrink-0"
                        />

                        <div class="min-w-0 flex-1">
                            <flux:heading size="sm" level="3" class="truncate">
                                {{ $item->title }}
                            </flux:heading>

                            @if ($item->to_title !== null && $item->to_title !== $item->title)
                                <flux:text size="xs" class="truncate">
                                    {{ __('on the new source it is called') }} <span class="italic">{{ $item->to_title }}</span>
                                </flux:text>
                            @endif

                            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                <span class="text-zinc-500 dark:text-zinc-400">
                                    {{ __('Title') }} {{ (int) round((float) $item->title_similarity * 100) }}%
                                </span>

                                <span class="text-zinc-500 dark:text-zinc-400">
                                    {{ $this->chapterVerdict($item) }}
                                </span>

                                @if ($author !== null)
                                    <span @class([
                                        'text-emerald-600 dark:text-emerald-400' => $author === __('Same author'),
                                        'text-zinc-500 dark:text-zinc-400' => $author !== __('Same author'),
                                    ])>{{ $author }}</span>
                                @endif

                                @if ($item->status === BulkMigrationItem::MOVED)
                                    <flux:badge size="xs" color="green">{{ __('moved') }}</flux:badge>
                                @elseif ($item->status === BulkMigrationItem::REFUSED)
                                    <flux:badge size="xs" color="red">{{ __('refused') }}</flux:badge>
                                @endif
                            </div>

                            @if ($item->reason !== null)
                                <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">{{ $item->reason }}</p>
                            @endif
                        </div>

                        {{-- The full wizard, for anything this table got wrong --}}
                        <flux:button
                            size="xs"
                            variant="ghost"
                            href="{{ route('migrate', ['manga' => $item->from_manga_id]) }}"
                            wire:navigate
                        >
                            {{ __('Look for another') }}
                        </flux:button>
                    </div>
                @endforeach
            </flux:card>
        @else
            <flux:callout variant="secondary" icon="magnifying-glass">
                <flux:heading level="2" size="sm" class="mb-1">{{ __('Nothing on that source is on the new one') }}</flux:heading>
                <p class="text-sm">
                    {{ __('Every series was looked up and none of them came back with a match close enough to offer.') }}
                </p>
            </flux:callout>
        @endif

        {{-- The ones with nowhere to go, kept out of the way but counted --}}
        @php($missing = $this->items->where('status', BulkMigrationItem::SKIPPED))

        @if ($missing->isNotEmpty())
            <details class="text-sm">
                <summary class="cursor-pointer text-zinc-500 hover:underline dark:text-zinc-400">
                    {{ trans_choice(
                        ':count series is not on the new source|:count series are not on the new source',
                        $missing->count(),
                        ['count' => number_format($missing->count())],
                    ) }}
                </summary>

                <ul class="mt-2 list-inside list-disc space-y-1 text-zinc-600 dark:text-zinc-300">
                    @foreach ($missing as $item)
                        <li>
                            {{ $item->title }}
                            @if ($item->reason !== null)
                                <span class="text-zinc-500">({{ $item->reason }})</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    @endif

    {{-- Earlier attempts -------------------------------------------------- --}}
    @if (BulkMigration::query()->forUser(auth()->id())->count() > 1)
        <details class="text-sm">
            <summary class="cursor-pointer text-zinc-500 hover:underline dark:text-zinc-400">
                {{ __('Earlier comparisons') }}
            </summary>

            <ul class="mt-2 list-inside list-disc space-y-1 text-zinc-600 dark:text-zinc-300">
                @foreach (BulkMigration::query()->forUser(auth()->id())->latest('id')->limit(10)->get() as $one)
                    @continue($run !== null && $one->id === $run->id)

                    <li>
                        <button
                            type="button"
                            wire:click="$set('runId', {{ $one->id }})"
                            class="underline hover:no-underline"
                        >
                            {{ $one->from_source_name ?? $one->from_source_id }}
                            {{ __('to') }}
                            {{ $one->to_source_name ?? $one->to_source_id }},
                            {{ $one->series_matched }} {{ __('matched') }}
                        </button>
                        <span class="text-zinc-500">({{ $this->stateLabel($one) }})</span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</div>