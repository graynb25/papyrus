<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Observed health for a source.
 *
 * Global, not user-owned: health describes the upstream, which does not care
 * who asked. Suwayomi exposes no per-source health, last-success, last-error or
 * rate-limit state anywhere in its schema, even though the server keeps
 * RateLimitInterceptor and CloudflareInterceptor internally. This ledger is
 * built purely by observing the outcomes of our own calls.
 *
 * @property int $id
 * @property int $source_id
 * @property string $name
 * @property string|null $lang
 * @property CarbonImmutable|null $last_success_at
 * @property CarbonImmutable|null $last_error_at
 * @property string|null $last_error
 * @property int $consecutive_failures
 * @property int $consecutive_empty
 * @property int $total_successes
 * @property int $total_failures
 * @property int|null $avg_latency_ms
 * @property bool $is_dead
 * @property CarbonImmutable|null $marked_dead_at
 */
#[Fillable([
    'source_id',
    'name',
    'lang',
    'last_success_at',
    'last_error_at',
    'last_error',
    'consecutive_failures',
    'consecutive_empty',
    'total_successes',
    'total_failures',
    'avg_latency_ms',
    'is_dead',
    'marked_dead_at',
])]
class SourceHealth extends Model
{
    protected function casts(): array
    {
        return [
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
            'marked_dead_at' => 'datetime',
            'is_dead' => 'boolean',
            'consecutive_failures' => 'integer',
            'consecutive_empty' => 'integer',
            'total_successes' => 'integer',
            'total_failures' => 'integer',
            'avg_latency_ms' => 'integer',
        ];
    }

    /**
     * Success rate over all observed calls, or null when nothing has been seen
     * yet. A brand new source is not 0% - it is unknown.
     */
    public function successRate(): ?float
    {
        $total = $this->total_successes + $this->total_failures;

        if ($total === 0) {
            return null;
        }

        return round($this->total_successes / $total, 4);
    }
}
