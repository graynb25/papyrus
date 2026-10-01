<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fan-out search and its persisted results.
 *
 * Results are rows rather than a held request because a search touches dozens
 * of upstreams under a hard 30s server ceiling, so the UI has to poll a run that
 * is still filling. Partial results are the normal case, not an error path.
 *
 * @property int $id
 * @property int $user_id
 * @property string $query
 * @property string|null $text_query
 * @property array<string, string>|null $filters
 * @property string $status
 * @property int $sources_total
 * @property int $sources_done
 * @property int $sources_failed
 * @property int $sources_skipped
 * @property int $result_count
 * @property CarbonImmutable|null $finished_at
 */
#[Fillable([
    'query',
    'text_query',
    'filters',
    'status',
    'sources_total',
    'sources_done',
    'sources_failed',
    'sources_skipped',
    'result_count',
    'finished_at',
])]
class SearchRun extends OwnedModel
{
    public const RUNNING = 'running';

    public const COMPLETE = 'complete';

    public const PARTIAL = 'partial';

    /**
     * @return HasMany<SearchResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(SearchResult::class);
    }

    /**
     * @return HasMany<SearchSourceResult, $this>
     */
    public function sourceResults(): HasMany
    {
        return $this->hasMany(SearchSourceResult::class);
    }

    protected function casts(): array
    {
        return [
            /*
             * `filters` is a json column handed over as an array of term to value.
             * Without the cast the array reaches the driver as an array and the insert
             * fails with "Array to string conversion", which reads as a database fault
             * rather than as a missing cast.
             */
            'filters' => 'array',
            'finished_at' => 'datetime',
            'sources_total' => 'integer',
            'sources_done' => 'integer',
            'sources_failed' => 'integer',
            'sources_skipped' => 'integer',
            'result_count' => 'integer',
        ];
    }

    public function isFinished(): bool
    {
        return $this->status !== self::RUNNING;
    }

    /**
     * A run that finished with some sources failing is still a usable run -
     * surfacing that honestly is the point.
     */
    public function finalise(): void
    {
        $this->status = $this->sources_failed > 0 ? self::PARTIAL : self::COMPLETE;
        $this->finished_at = now();
        $this->save();
    }

    public function progressPercent(): int
    {
        if ($this->sources_total === 0) {
            return 100;
        }

        return (int) min(100, round($this->sources_done / $this->sources_total * 100));
    }
}
