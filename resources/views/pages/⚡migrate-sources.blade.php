<?php

use App\Jobs\BuildBulkMigration;
use App\Migration\BulkMover;
use App\Models\BulkMigration;
use App\Models\BulkMigrationItem;
use App\Models\BulkMigrationTarget;
use App\Support\ImageUrl;
use App\Support\Library;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
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

    /**
     * Whether the reader came here to compose a run out of one named source.
     *
     * The value is that source's id, and it is honoured only when the mirror still knows
     * it, because a query string is a reader's control, not a fact: a stale link naming a
     * source the mirror lost must not leave the page half composed, a picked shelf beside
     * a leaving picker it quietly disagrees with. Clamped in mount(), the house rule for
     * anything reachable from the URL.
     */
    #[Url]
    public ?string $compose = null;

    /**
     * The series ticked on the compose panel, as manga id => bool.
     *
     * All of it is page state until the run is created, and the ids stamped at pick time
     * are the ones the run carries. The page never re-derives a pick from a title, because
     * a title is what the lens was showing when the reader picked, and one server rename
     * between the pick and the press would otherwise rewrite the reader's choice.
     *
     * @var array<int, bool>
     */
    public array $pick = [];

    public function mount(): void
    {
        if ($this->compose !== null) {
            $known = \App\Models\Source::query()
                ->where('suwayomi_source_id', $this->compose)
                ->exists();

            if (! $known) {
                $this->compose = null;
            }

            if ($this->compose !== null) {
                $this->from = $this->compose;
            }
        }
    }

    /**
     * The destinations the reader picked for the next comparison, in pick order.
     *
     * A list, not the old single `to`: picking several destinations is the normal case
     * now, and the single value carried a measured wart the list kills by design. With
     * one picker, switching the source being left kept the old destination, which is only
     * a harmless bug until a reader moving from source A to B switches to source C and
     * runs without noticing the picker still said B. Each pick here is its own value, so
     * nothing of one leaving side can quietly ride into another.
     *
     * @var array<int, string>
     */
    public array $targets = [];

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
     * Sources with at least one of this reader's series on them, biggest first. This
     * collection feeds the picker for the source being left.
     *
     * ## Why the count is on the button
     *
     * Because the reader's question is "which of these is the one holding my library", and a
     * list of 82 names does not answer that. Putting the number on each entry answers it
     * before the click, and a source with nothing on it is not offered as a place to leave,
     * because a run out of it can only ever produce "nothing in your library is on that
     * source". The target list is a different collection ({@see installedSources()}),
     * because the destination is chosen before anything has moved there.
     *
     * The grouping, the mirror join and the name fallbacks moved to
     * {@see \App\Support\Library::holdingsBySource()}, because the Migrate tab on the sources
     * page asks the same question and two copies of a tally is how counts started
     * disagreeing about "installed" once already.
     *
     * @return \Illuminate\Support\Collection<int, array{source_id: string, display_name: string, lang: string, count: int, installed: bool, named: bool, icon: string|null}>
     */
    #[Computed]
    public function sourcesInLibrary(): \Illuminate\Support\Collection
    {
        return app(Library::class)->holdingsBySource(auth()->id());
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
     * The name of the source being composed out of, stamped or the id.
     *
     * mount() guarantees the mirror carries a row before the panel ever renders, so this
     * names what the mirror says rather than guessing: an unsynced name falls back to the
     * id, which the row then says plainly.
     */
    #[Computed]
    public function composeName(): string
    {
        return (string) (\App\Models\Source::query()
            ->where('suwayomi_source_id', $this->compose)
            ->value('display_name') ?? $this->compose);
    }

    /**
     * How many of the compose panel's series are ticked.
     */
    public function pickedCount(): int
    {
        return count(array_filter($this->pick));
    }

    /**
     * The reader's series on the source being composed out of, alphabetical.
     *
     * The lens, filtered to one source, because the compose panel must agree with the
     * shelf it names: the same batched read the leaving picker counts, so a run's row
     * numbers and this list cannot tell different stories about how much is here. Rows
     * carry the unread count only where the chapter list has ever been fetched, which is
     * the same three state rule the shelf's unread line lives by: never counted, not
     * counted as zero.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function composeRows(): \Illuminate\Support\Collection
    {
        return app(Library::class)->entries(auth()->id())
            ->filter(fn (array $entry): bool => $entry['source_id'] !== null
                && (string) $entry['source_id'] === (string) $this->compose)
            ->sortBy(fn (array $entry): string => mb_strtolower((string) $entry['title']))
            ->values();
    }

    /**
     * Tick or untick one series on the compose panel.
     *
     * Same shape as {@see toggleTarget()}: a key present with false is a deliberate
     * untick and is different from a key that was never there.
     */
    public function togglePick(int $mangaId): void
    {
        if (array_key_exists($mangaId, $this->pick) && $this->pick[$mangaId]) {
            $this->pick[$mangaId] = false;

            return;
        }

        $this->pick[$mangaId] = true;
    }

    /**
     * Pick or drop one destination for the next comparison, keeping pick order.
     *
     * Five is the cap, and the cap is named at the pick rather than only at the press:
     * every target is one more full search per series, so five targets on a shelf of
     * eighty is four hundred searches. A rule that appears only as a slow run is not a
     * rule a reader knows.
     */
    public function toggleTarget(string $sourceId): void
    {
        if (in_array($sourceId, $this->targets, true)) {
            $this->targets = array_values(
                array_filter($this->targets, fn (string $one): bool => $one !== $sourceId),
            );

            return;
        }

        if (count($this->targets) >= 5) {
            Flux::toast(
                text: __('Five destinations is the cap: every one is another full search of the whole shelf. Untick one to add another.'),
                heading: __('Nothing more to add'),
                variant: 'danger',
            );

            return;
        }

        $this->targets[] = $sourceId;
    }

    /**
     * Start comparing. The source being left and at least one destination, or it refuses
     * before dispatching anything.
     */
    public function compare(): void
    {
        if ($this->from === '' || $this->targets === []) {
            Flux::toast(
                text: __('Choose the source to move away from and at least one to move to.'),
                heading: __('Nothing to compare'),
                variant: 'danger',
            );

            return;
        }

        if (in_array($this->from, $this->targets, true)) {
            Flux::toast(
                text: __('That is the same source twice. Moving a source onto itself does nothing.'),
                heading: __('Nothing to compare'),
                variant: 'danger',
            );

            return;
        }

        /*
         * The picks ride the run as ids stamped on the compose panel, never as titles, and
         * are intersected against the lens when the job starts rather than trusted here:
         * between this press and the job waking, a series can leave the source, so what
         * the reader picked is and what they still sit on are measured apart. Empty is a
         * composition of nothing, which the run cannot survive.
         */
        $picks = $this->compose === null
            ? null
            : array_values(array_keys(array_filter($this->pick, fn (bool $ticked): bool => $ticked)));

        if ($picks === []) {
            Flux::toast(
                text: __('Tick at least one series to compose a run from.'),
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
        $rows = \App\Models\Source::query()
            ->whereIn('suwayomi_source_id', array_unique(array_merge([$this->from], $this->targets)))
            ->get()
            ->keyBy(fn ($source): string => (string) $source->suwayomi_source_id);

        $run = BulkMigration::createFor(auth()->id(), [
            'from_source_id' => $this->from,
            'from_source_name' => (string) ($rows->get((string) $this->from)->display_name ?? $this->from),
            'picked_manga_ids' => $picks,
            'state' => BulkMigration::RUNNING,
            'started_at' => now(),
        ]);

        foreach (array_values($this->targets) as $seq => $sourceId) {
            BulkMigrationTarget::createFor(auth()->id(), [
                'bulk_migration_id' => $run->getKey(),
                'source_id' => $sourceId,
                'source_name' => $rows->get((string) $sourceId)?->display_name,
                'lang' => (string) ($rows->get((string) $sourceId)?->lang ?? ''),
                'seq' => $seq,
            ]);
        }

        BuildBulkMigration::dispatch($run->getKey(), (int) auth()->id(), max(0, $this->limit));

        $this->runId = $run->getKey();
        $this->report = null;

        // A new comparison means every tick, every set aside, every pick, every composed
        // mark and the stop value from the previous one are meaningless: all are page
        // state for the run that was showing. The stop value is the one a shorter list
        // left behind once: the field showed 0 and the run behind it still stopped at
        // the 1 typed on the press before.
        $this->reset('approved');
        $this->reset('setAside');
        $this->reset('targets');
        $this->reset('pick');
        $this->reset('limit');

        Flux::toast(
            text: __('Comparing your library against the destinations you picked. This is one request per destination per series, so it takes a while.'),
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
     * The destinations the run on display is walking.
     *
     * The model owns the naming, the two destination shapes and the read-back order,
     * so the running heading and every surface that names a run's destinations read the
     * one seam and cannot drift apart.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function targetNames(): array
    {
        return $this->run?->destinationNames() ?? [];
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

    {{--
        Back to sources.

        The reader arrives here from the Sources page's "Move a whole source"
        button, and that button is the only way in. Without a way back along
        it, the page is a dead end: the sidebar's Sources entry is a different
        place, and a reader who came here to look at the shelf they were
        holding has to go and find it again.

        The same shape the single series wizard carries, which is the house's
        way of saying a page knows where it was reached from.
    --}}
    <div class="flex items-center gap-2 text-sm">
        <a href="{{ route('sources') }}" wire:navigate class="text-zinc-500 hover:underline dark:text-zinc-400">
            &larr; {{ __('Back to sources') }}
        </a>
    </div>

    <x-page.header>
        <x-slot:eyebrow>
            @if ($compose !== null)
                {{ __('Move series off :name.', ['name' => $this->composeName]) }}
            @else
                {{ __('Move a whole source at once.') }}
            @endif
        </x-slot:eyebrow>
        <x-slot:title>{{ __('Move your library to another source') }}</x-slot:title>

        <x-slot:meta>
            @if ($compose !== null)
                {{-- Composed: the leaving side is a fact the reader arrived with, so the
                     intro names it rather than pointing at a picker that is not there. --}}
                {{ __('Every series you have saved from :name is listed below. Tick what moves, pick where it goes, and compare.', ['name' => $this->composeName]) }}
            @else
                {{ __('Pick the source you are leaving and the one you are moving to. Every series you have saved from the first is looked up on the second, and you decide what moves.') }}
            @endif
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
                @if ($compose !== null)
                    {{-- Composed from the sources page: the leaving side is a fact the reader
                         arrived with, not a picker. Swapping it for the series list is the
                         whole point of the mode. --}}
                    <div class="w-full">
                        <div class="flex items-baseline justify-between gap-3">
                            <flux:label class="font-medium">{{ __('Series on :name', ['name' => $this->composeName]) }}</flux:label>

                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ __(':picked of :total picked', ['picked' => $this->pickedCount(), 'total' => $this->composeRows->count()]) }}
                            </p>
                        </div>

                        <div class="max-h-72 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-700 p-2 mt-1">
                            <div class="flex flex-col gap-1">
                                @foreach ($this->composeRows as $row)
                                    <label class="flex items-center gap-2 px-2 py-1.5 rounded-md hover:bg-zinc-50 dark:hover:bg-zinc-600/60 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            @checked(array_key_exists((int) $row['manga_id'], $pick) && $pick[(int) $row['manga_id']])
                                            wire:click="togglePick({{ $row['manga_id'] }})"
                                            class="text-(--primary) rounded-[.25rem] border-zinc-300 dark:border-zinc-500"
                                        />

                                        <span class="text-sm text-zinc-800 dark:text-white">{{ $row['title'] }}</span>

                                        <span class="ms-auto text-xs tabular-nums text-zinc-500 dark:text-zinc-400">
                                            {{ $row['read_chapters'] }} of {{ $row['total_chapters'] ?? '?' }} read
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
                            {{ __('Tick the series you are moving. Nothing is written until the comparison finishes and you press again on the offers.') }}
                        </p>
                    </div>
                @else
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
                @endif

                <flux:icon.chevron-right class="mb-3 text-zinc-400" />

                {{-- Moving to --}}
                <div class="min-w-56">
                    <flux:label>{{ __('Moving to') }}</flux:label>

                    {{-- A checklist rather than a menu. Picking several destinations is the
                         normal case, and the old single value was where the stale picker came
                         from: one value shared by every leaving side makes one reader's
                         previous choice look like this one's. --}}
                    <div class="max-h-40 w-56 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-700 p-1.5">
                        <div class="flex flex-col">
                            @foreach ($this->installedSources as $one)
                                @continue($one['source_id'] === $this->from)

                                <label class="flex items-center gap-2 px-2 py-1.5 rounded-md text-sm font-medium text-zinc-800 dark:text-white hover:bg-zinc-50 dark:hover:bg-zinc-600/60 cursor-pointer select-none">
                                    <input
                                        type="checkbox"
                                        @checked(in_array($one['source_id'], $this->targets))
                                        wire:click="toggleTarget('{{ $one['source_id'] }}')"
                                        class="text-(--primary) rounded-[.25rem] border-zinc-300 dark:border-zinc-500"
                                    />

                                    {{ $one['display_name'] }}
                                    <span class="text-zinc-500">({{ $one['lang'] }})</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
                        {{ __('Five destinations is the most. Each one is another full search of the shelf per series.') }}
                    </p>
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
                {{ __('Stop after 0 means every series on the first source. The numbers in the moving away from list are how many of your series sit on each one, and every destination ticked is one more full search of those series.') }}
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
                        {{ __('Looking up your series on') }} {{ implode(', ', $this->targetNames) }}
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
    @endif

        {{-- The table rides the run open as well as settled, and stays open whenever the run
              holds a row with a destination to send it to: landed rows appear as their
              compares land, the stills' mid run card and the thing the cover strip marks,
              while the decisions wait for the run. A finished run re-renders the rows so a
              moved or refused row keeps its place, its numbers and its words, instead of
              the page telling the reader that nothing matched. --}}
        @if ($this->comparisonRows->isNotEmpty())
            <flux:card class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->comparisonRows as $item)
                    @php($author = $this->authorVerdict($item))

                    <div class="flex flex-wrap items-center gap-3 p-4">
                        @php($fromCover = ImageUrl::thumbnail((int) $item->from_manga_id, ImageUrl::defaultFormat()))

                        {{-- The reader's own series, pictured. The table asks where a shelf goes
                             and the reader knows a shelf by what it looks like; the same atom the
                             wizard's candidate rows use, fed the same way. Mid run the atom's own
                             progress hairline carries the landing mark: the row exists because
                             the compare came back, so a landed row flies full, the mark rides the
                             frame the library grid already owns, and it leaves with the run that
                             holds it, because progress that outlives the run is noise the reader
                             stops trusting. --}}
                        <div class="w-12 shrink-0">
                            <x-cover.art
                                :src="$fromCover"
                                :alt="$item->title"
                                :blur-up="false"
                                :vt="false"
                                :progress="$run !== null && $run->state === BulkMigration::RUNNING ? 100 : null"
                            >
                                @if ($item->unread_here !== null)
                                    {{-- The chip is the number the reader's eye sorts a shelf by, and
                                         the words live in the accessible title for anyone who wants
                                         them: the numeric chip is what the reference's own rows use. --}}
                                    <span
                                        class="absolute top-1 left-1 rounded bg-zinc-900/80 px-1.5 py-0.5 text-[10px] font-semibold leading-4 text-white tabular-nums"
                                        title="{{ trans_choice(':count unread chapter|:count unread chapters', (int) $item->unread_here, ['count' => number_format($item->unread_here)]) }}"
                                    >
                                        {{ number_format($item->unread_here) }}
                                    </span>
                                @endif
                            </x-cover.art>
                        </div>

                        {{-- The name is carried by the heading and the accessible name carries
                             the checkbox; the visible label would render the title twice. --}}
                        {{-- The tick only rides a row still waiting for its move: a decided one is
                              history, and a checkbox offered to move a row twice is a lie. Mid run
                              it rides nothing at all: the flight is a readout, and the offer bar
                              that would act on the tick waits for the run to settle. --}}
                        @if ($item->status === BulkMigrationItem::PENDING && $this->isDecidable())
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
                                @php($destination = $item->to_source_name ?? $run?->to_source_name)

                                {{-- The destination's own picture beside its name, because this
                                     row is the same identity question the wizard asks and the
                                     wizard's row answers it the same way. The row names the destination it was matched on:
                                     several destinations make "the new source" nameless, the run's own columns fill in for the runs that predate the list, and the generic wording stays for a row neither half can name. --}}
                                <div class="mt-1 flex items-center gap-2">
                                    <div class="w-8 shrink-0">
                                        <x-cover.art :src="$toCover" :alt="$item->to_title ?? $item->title" :blur-up="false" :vt="false" />
                                    </div>

                                    @if ($renamed)
                                        <flux:text size="xs" class="truncate">
                                            {{ $destination !== null ? __('on :source it is called', ['source' => $destination]) : __('on the new source it is called') }}
                                            <span class="italic">{{ $item->to_title }}</span>
                                        </flux:text>
                                    @else
                                        <flux:text size="xs" class="truncate">
                                            {{ $destination !== null ? __('on :source', ['source' => $destination]) : __('on the new source') }}
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

                                @if ($item->unread_here !== null)
                                    {{-- The leaving side's own unread, exactly as the compare knew it:
                                          the number the mover is asking about is how far behind this run
                                          left, not how far behind now. Not stamped for a series whose
                                          chapter list the server never fetched, which is not zero. --}}
                                    <span class="text-zinc-500 dark:text-zinc-400">
                                        {{ trans_choice(':count unread chapter.|:count unread chapters.', (int) $item->unread_here, ['count' => number_format($item->unread_here)]) }}
                                    </span>
                                @endif

                                @if ($item->chapters_here !== null)
                                    <span class="text-zinc-500 dark:text-zinc-400">
                                        {{ __('Latest: Chapter :count', ['count' => number_format($item->chapters_here)]) }}
                                    </span>
                                @endif

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
        @elseif ($run !== null && $this->isDecidable())
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
        @if ($run !== null && $this->isDecidable() && $this->missing->isNotEmpty())
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
                        {{-- Destinations read through the model: a list run's columns are empty
                             and an entry that read them rendered "MangaFire (JA) to , 0 matched". --}}
                        <button
                            type="button"
                            wire:click="$set('runId', {{ $one->id }})"
                            class="underline hover:no-underline"
                        >
                            {{ $one->from_source_name ?? $one->from_source_id }}
                            {{ __('to') }}
                            {{ implode(', ', $one->destinationNames()) }},
                            {{ $one->series_matched }} {{ __('matched') }}
                        </button>
                        <span class="text-zinc-500">({{ $this->stateLabel($one) }})</span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</div>