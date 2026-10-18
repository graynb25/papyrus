<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use App\Models\Scopes\OwnedByUser;
use App\Support\Library;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One attempt to move a reader's whole shelf from one source to another.
 *
 * ## Why a run is kept when it matched nothing
 *
 * Same reasoning as every other run in this app: "it did nothing" and "it worked" are
 * different outcomes, and a reader who asked for eighty matches and got none needs to be able
 * to see that the attempt happened and what it found, rather than a page that never changed.
 *
 * ## The counts describe the search, not the move
 *
 * `series_total` is how many of the reader's series sit on the source being left,
 * `series_checked` is how many have been compared, and `series_matched` is how many have a
 * candidate to be offered. None of them says anything about what moved. That is on the items,
 * and keeping the two apart is what stops a page reporting "80 of 80 matched" above a list in
 * which the reader approved four.
 *
 * @property int $id
 * @property int $user_id
 * @property string $from_source_id
 * @property string|null $to_source_id
 * @property string|null $from_source_name
 * @property string|null $to_source_name
 * @property string $state running|ready|failed
 * @property string|null $error
 * @property string|null $current_title
 * @property array<int, int>|null $picked_manga_ids
 * @property int $series_total
 * @property int $series_checked
 * @property int $series_matched
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 */
#[Fillable([
    'from_source_id',
    'to_source_id',
    'from_source_name',
    'to_source_name',
    'picked_manga_ids',
    'state',
    'error',
    'current_title',
    'series_total',
    'series_checked',
    'series_matched',
    'started_at',
    'finished_at',
])]
class BulkMigration extends OwnedModel
{
    /**
     * Cast because every counter here is divided or compared, and SQLite hands them back as
     * strings without this. The same reason every other counter in this app is cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'series_total' => 'integer',
            'series_checked' => 'integer',
            'series_matched' => 'integer',
            'picked_manga_ids' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public const RUNNING = 'running';

    public const READY = 'ready';

    public const FAILED = 'failed';

    /**
     * The reader pressed stop and the job saw it between two series.
     *
     * Deliberately distinct from FAILED, because "it broke" and "the reader stopped it" are
     * different things to say and different things to do next: a broken run names what broke,
     * a stopped one names what survived. Tachimanga offers no stop at all; one attempt then a
     * fresh run is already this job's design, and a visible stop makes that honest instead of
     * implying the reader has to choose between waiting and killing the worker.
     */
    public const CANCELLED = 'cancelled';

    /**
     * How long a run may go untouched before the page stops calling it running.
     *
     * The same lesson as every other long job in this app: a queued run with no worker behind
     * it never writes anything, so silence is the only evidence there is, and a page that only
     * watches state shows a spinner that means nothing.
     */
    public const STALL_AFTER_SECONDS = 600;

    /** @return HasMany<BulkMigrationItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BulkMigrationItem::class);
    }

    /** @return HasMany<BulkMigrationTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(BulkMigrationTarget::class);
    }

    /**
     * Whether the run's destinations are one value in two columns rather than the list.
     *
     * The shape every run before the compose screen carried. The matcher trusts
     * `to_source_id` only when the list is absent, because a row that answered both ways
     * would make neither answer checked, and the page renders the run card through this
     * method for the same reason: two shapes, one seam, no card left guessing.
     */
    public function isSingleDestination(): bool
    {
        return $this->to_source_id !== null
            && $this->targets()->doesntExist();
    }

    /**
     * The destinations a run is walking, stamped at creation, read back in pick order.
     *
     * Every surface that names a run's destinations renders through here rather than
     * its own columns: the runs from the list shape onwards carry their destinations as
     * rows and leave those columns empty, so a reader who read the columns directly saw
     * a blank name, and the card, the running heading and the earlier comparisons list
     * would name their own shapes and drift apart. One seam, every surface.
     *
     * The scope is set aside on the rows on purpose: they belong to the run whose
     * identity is already resolved, keeping the naming true even where no reader is
     * logged in, which is exactly the read the matcher makes for them.
     *
     * @return array<int, string>
     */
    public function destinationNames(): array
    {
        if ($this->isSingleDestination()) {
            return [(string) ($this->to_source_name ?? $this->to_source_id)];
        }

        return $this->targets()
            ->withoutGlobalScope(OwnedByUser::class)
            ->orderBy('seq')
            ->get()
            ->map(fn (BulkMigrationTarget $target): string => (string) ($target->source_name ?? $target->source_id))
            ->all();
    }

    /**
     * Whether the run compares a picked subset rather than the whole shelf.
     *
     * Null is the whole shelf, which is every run that predates the compose screen. A list
     * present means the compose screen made this run, whatever it holds, so the two shapes
     * are told apart by the column rather than inferred from counters, and the matcher
     * intersects it with the lens once when it starts.
     */
    public function isPicked(): bool
    {
        return $this->picked_manga_ids !== null;
    }

    /**
     * The item for one series, if this run looked at it.
     *
     * Used when applying a reader's approvals, so a run cannot act on a series it never
     * compared. The per series wizard's equivalent check is a candidate that appeared in a
     * search, and this is the same boundary for the bulk flow.
     *
     * @return HasOne<BulkMigrationItem, $this>
     */
    public function itemFor(int $mangaId): HasOne
    {
        return $this->hasOne(BulkMigrationItem::class)->where('from_manga_id', $mangaId);
    }

    /**
     * Whether the run says it is working and has been heard from in far too long.
     *
     * Broader than a state check on purpose. A worker killed at its timeout, an out of memory
     * kill, or a machine that went down all leave the row reading `running` with no process
     * behind it, and those are not recoverable from inside a process that no longer exists.
     */
    public function isStalled(): bool
    {
        return $this->state === self::RUNNING
            && $this->updated_at->diffInSeconds(now()) > self::STALL_AFTER_SECONDS;
    }

    public function stalledReason(): string
    {
        return __(
            'This has not reported anything for several minutes, which usually means the worker was killed part way through. Nothing it compared is lost; run it again.',
        );
    }

    /** Whether the reader can be shown something to decide about. */
    public function isOffered(): bool
    {
        return in_array($this->state, [self::READY, self::CANCELLED], strict: true)
            && $this->items()->where('status', '!=', BulkMigrationItem::SKIPPED)->exists();
    }

    /**
     * A stopped run tells the reader which series it never reached.
     *
     * A cancelled run may be complete by coincidence (the press landed after the last
     * series), so the sentence is only about the ones it can name as unlooked at. The
     * complements are on the item rows, not inferred from counters, so the page cannot
     * name a series it has no row for.
     *
     * @return array<int, string>
     */
    public function seriesNeverLookedAt(): array
    {
        if ($this->state !== self::CANCELLED) {
            return [];
        }

        $compared = $this->items()->pluck('from_manga_id')->all();

        return app(Library::class)->entries($this->user_id)
            ->filter(fn (array $entry): bool => (string) $entry['source_id'] === $this->from_source_id
                && ! in_array((int) $entry['manga_id'], $compared, true))
            ->pluck('title')
            ->values()
            ->all();
    }
}
