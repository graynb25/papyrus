<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one source behaved during one search run.
 *
 * This is the only place a source's behaviour is observable: Suwayomi exposes
 * no per-source health, last-success, or last-error anywhere in its schema, and
 * a live dead source (Aqua Manga) returns 0 results with no error at all. A
 * silent zero is indistinguishable from a good search, so it has to be
 * recorded here and promoted into the health ledger.
 *
 * @property int $id
 * @property int $user_id
 * @property int $search_run_id
 * @property int $suwayomi_source_id
 * @property string $source_name
 * @property string $status
 * @property int $result_count
 * @property int|null $duration_ms
 * @property string|null $error
 */
#[Fillable([
    'search_run_id',
    'suwayomi_source_id',
    'source_name',
    'status',
    'result_count',
    'duration_ms',
    'error',
])]
class SearchSourceResult extends OwnedModel
{
    /** The source answered. */
    public const OK = 'ok';

    /** The source answered with zero results - not the same as OK-with-hits. */
    public const EMPTY = 'empty';

    /** The source timed out against our per-source budget. */
    public const TIMEOUT = 'timeout';

    /** The source returned an error. */
    public const ERROR = 'error';

    /**
     * The source was never asked, because it cannot express a filter the reader typed.
     *
     * Its own state, not a failure. The source is healthy and answered nothing; we chose
     * not to ask. Recorded so the page can say how many sources each term cost, which is
     * the difference between a filter that narrowed things and one that silently emptied
     * a third of the sources.
     */
    public const SKIPPED = 'skipped';

    /**
     * @return BelongsTo<SearchRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(SearchRun::class, 'search_run_id');
    }

    protected function casts(): array
    {
        return [
            'result_count' => 'integer',
            'duration_ms' => 'integer',
        ];
    }
}
