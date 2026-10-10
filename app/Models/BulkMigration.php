<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
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
 * @property string $to_source_id
 * @property string|null $from_source_name
 * @property string|null $to_source_name
 * @property string $state running|ready|failed
 * @property string|null $error
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
    'state',
    'error',
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
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public const RUNNING = 'running';

    public const READY = 'ready';

    public const FAILED = 'failed';

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
        return $this->state === self::READY && $this->items()->where('status', '!=', BulkMigrationItem::SKIPPED)->exists();
    }
}
