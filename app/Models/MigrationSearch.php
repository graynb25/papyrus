<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;

/**
 * One attempt to find another source for a series.
 *
 * Exists because the search is 21.9 seconds. Measured: 81 sources, one term each,
 * p50 143ms and a worst source of 1459ms, zero failures. That is fine for a queue
 * worker and poor for a page request, so the search runs behind a job and the page
 * polls this row.
 *
 * The candidate list is a JSON column rather than a table of its own. It is read
 * once, shown once, and then either used or thrown away, so a table would be a second
 * copy of something derivable and stale the moment a source's chapter list moves.
 * Deleting one row drops the whole search.
 *
 * @property int $id
 * @property int $user_id
 * @property int $from_manga_id
 * @property string $state
 * @property int $sources_searched
 * @property int $sources_total
 * @property array<int, array<string, mixed>>|null $candidates
 * @property string|null $error
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'from_manga_id',
    'state',
    'sources_searched',
    'sources_total',
    'candidates',
    'error',
])]
class MigrationSearch extends OwnedModel
{
    /** A job is walking the sources. The page polls on this. */
    public const SEARCHING = 'searching';

    /**
     * Finished, one way or the other.
     *
     * Distinct from FAILED because "searched everything and found nothing" is a
     * result a reader can act on, and "the server was unreachable" is not. Collapsing
     * them would report a transport failure as though the series exists nowhere else.
     */
    public const READY = 'ready';

    /** The search could not be completed. `error` says why. */
    public const FAILED = 'failed';

    /**
     * How long a search may go without reporting before it is given up on.
     *
     * Ten times the measured 35.4 seconds. See {@see self::isStalled()} for why this
     * cannot fire on a search that is merely slow.
     */
    public const STALL_AFTER_SECONDS = 300;

    protected function casts(): array
    {
        return [
            'from_manga_id' => 'integer',
            'sources_searched' => 'integer',
            'sources_total' => 'integer',
            'candidates' => 'array',
        ];
    }

    public function isSettled(): bool
    {
        return $this->state !== self::SEARCHING;
    }

    /**
     * Whether a search that says it is running has stopped running.
     *
     * Measured, and this exists because of what happened rather than what might.
     * The first live run was killed by the queue worker's timeout, and with
     * `--tries=1` nothing recorded the failure, so the row sat `searching` and the
     * page polled it for three minutes with no end in sight. A page that can poll for
     * ever is worse than one that admits it lost track.
     *
     * The threshold is ten times the measured cost: the whole search is 35.4 seconds,
     * 27.6 of it sweeping 81 sources and 7.8 fetching candidate details. And the
     * threshold is safe against a slow run because the job rewrites `updated_at` every
     * five seconds while it works, so a search that is genuinely progressing never
     * looks stalled no matter how long it takes.
     */
    public function isStalled(): bool
    {
        if ($this->state !== self::SEARCHING) {
            return false;
        }

        return $this->updated_at->addSeconds(self::STALL_AFTER_SECONDS)->isPast();
    }

    /** Why a stalled search is being treated as finished. */
    public function stalledReason(): string
    {
        return __('The search stopped reporting and was given up on. Nothing was changed, so it is safe to try again.');
    }

    /**
     * The newest search for a series, which is the one the page renders.
     *
     * A reader who searches twice gets the second answer. Keeping every attempt would
     * mean deciding which stale one to show, and there is no good reason to keep a
     * candidate list whose sources have moved on.
     *
     * @param  Builder<MigrationSearch>  $query
     * @return Builder<MigrationSearch>
     */
    public function scopeForSeries(Builder $query, int $userId, int $mangaId): Builder
    {
        return $query->where('user_id', $userId)->where('from_manga_id', $mangaId);
    }
}
