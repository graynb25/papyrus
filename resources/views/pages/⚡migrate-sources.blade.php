<?php

use App\Jobs\BuildBulkMigration;
use App\Migration\BulkMover;
use App\Models\BulkMigration;
use App\Models\BulkMigrationItem;
use App\Support\ImageUrl;
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

    /**
     * Series the reader said to leave behind, as from_manga_id => bool.
     *
     * These are the rows the comparison found nothing for. They carry no move to refuse, so
     * the action is the reader saying they do not want to look at the row again, and the
     * page keeps counting what it set aside rather than pretending the rows went away.
     * Reset with the run, because the choice belongs to the run the reader saw it in, not
     * to the reader's browser forever: an earlier run re-opened shows its rows again.
     *
     * @var array<int, bool>
     */
    public array $setAside = [];

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
    /**
     * Sources with at least one of this reader's series on them, biggest first. This
     * collection feeds the picker for the source being left.
     *
     * ## Why the count is on the button
     *
     * Because the reader's question is "which of these is the one holding my library", and a
     * list of 82 names does not answer that. Putting the number on each entry answers it
     * before the click, and a source with nothing on it is not offered as a place to leave,
     * because a run out of it can only ever produce "nothing in your library is on that
     * source". The target picker is a different collection ({@see installedSources()}),
     * because the target is chosen before anything has moved there.
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

    /**
     * Every installed source, alphabetical, for the picker of where the library is going.
     *
     * The destination is picked before anything has moved, so holdings say nothing about
     * whether a source may be chosen: a reader moving off a source that died is picking the
     * one their library is about to live on, which by definition holds nothing yet. The
     * first bulk move to a fresh source was impossible to pick when this list was also the
     * holdings list, which is how that was caught. Offered with name and language only, the
     * way the target list on the reference design reads, and the source being left is
     * skipped in the menu rather than here, because here the page does not know it yet.
     *
     * @return \Illuminate\Support\Collection<int, array{source_id: string, display_name: string, lang: string}>
     */
    #[Computed]
    public function installedSources(): \Illuminate\Support\Collection
    {
        return \App\Models\Source::query()
            ->where('is_installed', true)
            ->orderBy('display_name')
            ->get()
            ->map(fn ($source): array => [
                'source_id' => (string) $source->suwayomi_source_id,
                'display_name' => (string) ($source->display_name ?? $source->suwayomi_source_id),
                'lang' => (string) ($source->lang ?? ''),
            ]);
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
     * A row whose only answer was a destination that could not be read is not here: it has
     * nothing to tick and one reason to show, which is the missing family's shape, so it
     * stays with the other no-alternative rows rather than being an untickable one among
     * the tickable.
     *
     * @return \Illuminate\Support\Collection<int, BulkMigrationItem>
     */
    #[Computed]
    public function offers(): \Illuminate\Support\Collection
    {
        return $this->items()->filter(
            fn (BulkMigrationItem $item): bool => $item->to_manga_id !== null
                && $item->status === BulkMigrationItem::PENDING,
        )->values();
    }

    /**
     * Stop a comparison that is still going.
     *
     * Writes the state and returns; the job notices between two series and leaves with
     * everything it compared intact. This is deliberately cheaper than killing the job:
     * the one attempt rule means the next run is fresh anyway, so what stop has to
     * guarantee is only that the series in front of the loop is not searched after the
     * reader said stop, and the job owns that check because the loop is the job's.
     */
    public function updatedRunId(): void
    {
        /*
         * A pinned run is a record with its own rows. The set asides name series of the run
         * they were pressed in, so pinning another one puts its rows back: the choice
         * belongs to the run, and a different run owes the reader nothing about it.
         */
        $this->reset('setAside');
    }

    public function cancel(): void
    {
        $run = $this->run;

        if ($run === null || $run->state !== BulkMigration::RUNNING) {
            return;
        }

        $run->update(['state' => BulkMigration::CANCELLED, 'finished_at' => now()]);

        Flux::toast(
            text: __('Stopped. The series it already compared are kept, and nothing moves until you tick them.'),
            heading: __('Stopped'),
            variant: 'info',
        );
    }

    public function setAsideItem(int $fromMangaId): void
    {
        $this->setAside[$fromMangaId] = true;
    }

    /** Whether a missing row is currently set aside. */
    public function isSetAside(BulkMigrationItem $item): bool
    {
        return array_key_exists((int) $item->from_manga_id, $this->setAside);
    }

    /**
     * How many rows are set aside in the run being shown.
     *
     * Counted against this run's rows, not the raw keys, so a set aside from an earlier
     * run never inflates the count after the reader switches back: the sentence under the
     * try button and the rows it describes have to agree.
     */
    #[Computed]
    public function setAsideCount(): int
    {
        return $this->missing->filter(fn (BulkMigrationItem $item): bool => $this->isSetAside($item))->count();
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
     * The series the comparison found nowhere to send.
     *
     * Both shapes land here: the ones the destination does not list, and the ones whose
     * only answer was a destination that could not be read. The former says "not there",
     * the latter says what happened, and they are one card family because they ask the
     * same question of the reader: this row has nothing to tick, what do you want for it.
     *
     * @return \Illuminate\Support\Collection<int, BulkMigrationItem>
     */
    #[Computed]
    public function missing(): \Illuminate\Support\Collection
    {
        return $this->items()->filter(
            fn (BulkMigrationItem $item): bool => $item->status === BulkMigrationItem::SKIPPED,
        )->values();
    }

    /**
     * The rows the offer table is made of: offers while they stand, and the decisions
     * they became.
     *
     * The table used to key on pending offers alone, which made it vanish the moment a
     * move completed: a reader reloading after the move read that nothing on the source
     * was on the new one, over a run whose items all said moved, and rows refused at
     * move time had nowhere at all. Read here instead as "compared, found something,
     * not set up for the dead end cards": pending still carry the tick row above; a
     * moved row keeps its numbers under its badge; a refused row keeps the reason it
     * was given. The dead ends stay in the missing family because they ask for a
     * decision, and these no longer do.
     *
     * @return \Illuminate\Support\Collection<int, BulkMigrationItem>
     */
    #[Computed]
    public function comparisonRows(): \Illuminate\Support\Collection
    {
        return $this->items()->filter(
            fn (BulkMigrationItem $item): bool => $item->to_manga_id !== null
                && $item->status !== BulkMigrationItem::SKIPPED,
        )->values();
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

        /*
         * The names are stamped at creation, not looked up at reading time: a run long
         * outlives what the source table holds about it, and the whole history section on
         * this page reads these columns. A source with no row at all stamps its id, which
         * is how the rest of this page renders an unresolvable source.
         */
        $sourceRows = \App\Models\Source::query()
            ->whereIn('suwayomi_source_id', [$this->from, $this->to])
            ->get()
            ->keyBy(fn ($source): string => (string) $source->suwayomi_source_id);

        $run = BulkMigration::createFor(auth()->id(), [
            'from_source_id' => $this->from,
            'to_source_id' => $this->to,
            'from_source_name' => (string) ($sourceRows->get((string) $this->from)->display_name ?? $this->from),
            'to_source_name' => (string) ($sourceRows->get((string) $this->to)->display_name ?? $this->to),
            'state' => BulkMigration::RUNNING,
            'started_at' => now(),
        ]);

        BuildBulkMigration::dispatch($run->getKey(), (int) auth()->id(), max(0, $this->limit));

        $this->runId = $run->getKey();
        $this->report = null;

        // A new comparison means every tick and every set aside from the previous one is
        // meaningless: both are page state for the run that was showing.
        $this->reset('approved');
        $this->reset('setAside');

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
            BulkMigration::CANCELLED => __('Stopped by you'),
            default => $run->state,
        };
    }

    /** Whether the run has rows the reader can still act on: ready, or stopped part way by them. */
    public function isDecidable(): bool
    {
        $run = $this->run;

        return $run !== null
            && in_array($run->state, [BulkMigration::READY, BulkMigration::CANCELLED], strict: true);
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
                            {{ $this->labelFor($this->to, $this->installedSources) ?? __('Choose a source') }}
                        </flux:button>

                        <flux:menu class="max-h-80 overflow-y-auto">
                            @foreach ($this->installedSources as $one)
                                @continue($one['source_id'] === $this->from)

                                <flux:menu.item wire:click="$set('to', '{{ $one['source_id'] }}')">
                                    {{ $one['display_name'] }}
                                    <span class="text-zinc-500">({{ $one['lang'] }})</span>
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
                {{ __('Stop after 0 means every series on the first source. The numbers in the moving away from list are how many of your series sit on each one.') }}
            </p>
        @endif
    </flux:card>

    {{-- Comparing ---------------------------------------------------------- --}}
    @if ($this->stillRunning() && $run !== null)
        {{--
            The poll rides the card it refreshes, and the card's unmounting when
            the run settles is the stop.

            This page declared polling through a `with()` array, exactly the way the
            wizard page did before it: nothing in Livewire or volt ever calls such a
            method, so no directive ever reached the rendered page and the run went
            ready in the database while the card kept saying it was still looking up
            series, until a reload. Measured live twice on 2026-10-17: run 4 was ready
            19:32:38 and the card still read "Looking up" at 19:33:10; run 5 was ready
            19:39:40 and it was the same seconds later.
        --}}
        <div wire:poll.{{ $this::REFRESH_MS / 1000 }}s>
            <flux:card class="space-y-3 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading size="sm" level="3">
                        {{ __('Looking up your series on') }} {{ $run->to_source_name ?? $this->to }}
                    </flux:heading>

                    <flux:button size="xs" variant="ghost" wire:click="cancel" wire:target="cancel">
                        {{ __('Stop comparing') }}
                    </flux:button>
                </div>

                @if ($run->current_title !== null)
                    {{-- One line about what is being requested right now, because the run is one
                         request per series and the series in flight is the only thing that
                         explains what the number is waiting on. It truncates rather than wraps,
                         safe to lose: the complete name is in the library. --}}
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="truncate text-sm">
                            {{ __('Matching') }}: <span class="italic">{{ $run->current_title }}</span>
                        </p>

                        <flux:text size="sm" class="shrink-0 tabular-nums text-zinc-500">
                            {{ $run->series_checked }} / {{ $run->series_total ?: '?' }}
                        </flux:text>
                    </div>
                @endif

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
        </div>
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
    @if ($run !== null && $this->isDecidable())
        <flux:callout variant="secondary" icon="information-circle">
            <flux:heading level="2" size="sm" class="mb-1">
                {{ $run->state === BulkMigration::CANCELLED ? __('The comparison stopped') : __('What was found') }}
            </flux:heading>

            <p class="text-sm">
                {{
                    __('Looked at :checked of your series.', ['checked' => number_format($run->series_checked)])
                }}

                @if ($run->series_total > $run->series_checked)
                    {{ __('Your library holds :total on that source.', ['total' => number_format($run->series_total)]) }}
                @endif

                @if ($run->state === BulkMigration::CANCELLED)
                    {{-- The stop's own truth: what never got looked at is named rather than
                         let the reader wonder whether the run was finished when they pressed. --}}
                    {{
                        trans_choice(
                            'You stopped it, so :count series was never looked at.|You stopped it, so :count series were never looked at.',
                            max(0, $run->series_total - $run->series_checked),
                            ['count' => number_format(max(0, $run->series_total - $run->series_checked))],
                        )
                    }}
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

            @php($neverLookedAt = $run->seriesNeverLookedAt())

            @if ($neverLookedAt !== [])
                <details class="mt-1 text-sm">
                    <summary class="cursor-pointer text-zinc-500 hover:underline dark:text-zinc-400">
                        {{ trans_choice(
                            'Which one was never looked at|Which ones were never looked at',
                            count($neverLookedAt),
                            ['count' => number_format(count($neverLookedAt))],
                        ) }}
                    </summary>

                    <ul class="mt-2 list-inside list-disc space-y-1 text-zinc-600 dark:text-zinc-300">
                        @foreach ($neverLookedAt as $title)
                            <li>{{ $title }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif

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
        @endif

        {{-- The table stays open whenever the run holds a row with a destination to send it to:
              offers ride with the tick row above, and a finished run re-renders it so a moved
              or refused row keeps its place, its numbers and its words, instead of the page
              telling the reader that nothing matched. --}}
        @if ($this->comparisonRows->isNotEmpty())
            <flux:card class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->comparisonRows as $item)
                    @php($author = $this->authorVerdict($item))

                    <div class="flex flex-wrap items-center gap-3 p-4">
                        @php($fromCover = ImageUrl::thumbnail((int) $item->from_manga_id, ImageUrl::defaultFormat()))

                        {{-- The reader's own series, pictured. The table asks where a shelf goes
                             and the reader knows a shelf by what it looks like; the same atom the
                             wizard's candidate rows use, fed the same way. --}}
                        <div class="w-12 shrink-0">
                            <x-cover.art :src="$fromCover" :alt="$item->title" :blur-up="false" :vt="false" />
                        </div>

                        {{-- The name is carried by the heading and the accessible name carries
                             the checkbox; the visible label would render the title twice. --}}
                        {{-- The tick only rides a row still waiting for its move: a decided one is
                              history, and a checkbox offered to move a row twice is a lie. --}}
                        @if ($item->status === BulkMigrationItem::PENDING)
                            <flux:checkbox
                                wire:model.live="approved.{{ $item->from_manga_id }}"
                                aria-label="{{ $item->title }}"
                                class="shrink-0"
                            />
                        @endif

                        <div class="min-w-0 flex-1">
                            <flux:heading size="sm" level="3" class="truncate">
                                {{ $item->title }}
                            </flux:heading>

                            @if ($item->to_title !== null)
                                @php($toCover = ImageUrl::thumbnail((int) $item->to_manga_id, ImageUrl::defaultFormat()))
                                @php($renamed = $item->to_title !== $item->title)

                                {{-- The destination's own picture beside its name, because this
                                     row is the same identity question the wizard asks and the
                                     wizard's row answers it the same way. When the name did not
                                     survive the move, the line below says what changed. --}}
                                <div class="mt-1 flex items-center gap-2">
                                    <div class="w-8 shrink-0">
                                        <x-cover.art :src="$toCover" :alt="$item->to_title ?? $item->title" :blur-up="false" :vt="false" />
                                    </div>

                                    @if ($renamed)
                                        <flux:text size="xs" class="truncate">
                                            {{ __('on the new source it is called') }} <span class="italic">{{ $item->to_title }}</span>
                                        </flux:text>
                                    @else
                                        <flux:text size="xs" class="truncate">
                                            {{ __('on the new source') }}
                                        </flux:text>
                                    @endif
                                </div>
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

                        {{-- The full wizard, for anything this table got wrong. A moved row is
                              not wrong: its identity lives on the new source now, and the wizard
                              on the old id would ask about a series the from side no longer
                              lists. --}}
                        @unless ($item->status === BulkMigrationItem::MOVED)
                            <flux:button
                                size="xs"
                                variant="ghost"
                                href="{{ route('migrate', ['manga' => $item->from_manga_id]) }}"
                                wire:navigate
                            >
                                {{ __('Look for another') }}
                            </flux:button>
                        @endunless
                    </div>
                @endforeach
            </flux:card>
        @else
            <flux:callout variant="secondary" icon="magnifying-glass">
                <flux:heading level="2" size="sm" class="mb-1">{{ __('Nothing on that source is on the new one') }}</flux:heading>
                <p class="text-sm">
                    @if ($run->state === BulkMigration::CANCELLED)
                        {{
                            __('Of the :checked series the run looked at, none came back with a match close enough to offer.', [
                                'checked' => number_format($run->series_checked),
                            ])
                        }}
                    @else
                        {{ __('Every series was looked up and none of them came back with a match close enough to offer.') }}
                    @endif
                </p>
            </flux:callout>
        @endif

        {{-- The rows the comparison found nothing to send, as cards with actions rather
             than a folded list. Tachimanga's stills give them the two exits a dead end
             needs, and the fold counted these rows without answering them; the summary
             sentence above still counts all of them, so the count and the cards agree. --}}
        @if ($this->missing->isNotEmpty())
            <flux:card class="divide-y divide-zinc-200 dark:divide-zinc-700">
                <div class="flex flex-wrap items-center justify-between gap-2 px-4 pb-1 pt-4">
                    <flux:heading size="sm" level="3">{{ __('No alternatives found') }}</flux:heading>

                    <flux:text size="xs" class="text-zinc-500 dark:text-zinc-400">
                        {{
                            trans_choice(
                                'One series with nowhere to go.|:count series with nowhere to go.',
                                $this->missing->count(),
                                ['count' => number_format($this->missing->count())],
                            )
                        }}
                    </flux:text>
                </div>

                @foreach ($this->missing as $item)
                    @continue($this->isSetAside($item))

                    <div class="flex flex-wrap items-center gap-3 p-4">
                        @php($cover = ImageUrl::thumbnail((int) $item->from_manga_id, ImageUrl::defaultFormat()))

                        <div class="w-12 shrink-0">
                            <x-cover.art :src="$cover" :alt="$item->title" :blur-up="false" :vt="false" />
                        </div>

                        <div class="min-w-0 flex-1">
                            <flux:heading size="sm" level="3" class="truncate">
                                {{ $item->title }}
                            </flux:heading>

                            @if ($item->reason !== null)
                                <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">{{ $item->reason }}</p>
                            @else
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ __('No alternatives found') }}
                                </p>
                            @endif
                        </div>

                        {{-- The two exits a dead end has: the wizard, which searches every
                             installed source for this one series rather than the chosen
                             destination, or writing it off. "Don't migrate" is the wording
                             the still shows for exactly this menu, and it does not read as
                             abandoning a move here, because the row IS the move there is
                             nothing left to abandon. --}}
                        <div class="flex items-center gap-2">
                            <flux:button
                                size="xs"
                                variant="ghost"
                                href="{{ route('migrate', ['manga' => $item->from_manga_id]) }}"
                                wire:navigate
                            >
                                {{ __('Search manually') }}
                            </flux:button>

                            <flux:button size="xs" variant="ghost" wire:click="setAsideItem({{ $item->from_manga_id }})">
                                {{ __("Don't migrate") }}
                            </flux:button>
                        </div>
                    </div>
                @endforeach

                @if ($this->setAsideCount > 0)
                    <p class="px-4 pb-4 pt-3 text-xs text-zinc-500 dark:text-zinc-400">
                        {{
                            trans_choice(
                                'You set one aside. It is still on the old source.|You set all :count of them aside. They are still on the old source.',
                                $this->setAsideCount,
                                ['count' => number_format($this->setAsideCount)],
                            )
                        }}
                    </p>
                @endif
            </flux:card>
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