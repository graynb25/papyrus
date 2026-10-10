<?php

namespace App\Models;

use App\Search\HealthRecorder;
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
     * Give every installed source a ledger row, and report how many were missing.
     *
     * Without this the "never asked" state is unreachable, and it is unreachable for a
     * structural reason rather than an accidental one: a row is only ever created by
     * {@see HealthRecorder::record()}, so a source that has never been searched
     * has no row, and a source with no row is absent from the dashboard entirely rather
     * than counted as unobserved.
     *
     * Measured on the dev library before this existed: 223 sources installed, 0 rows, and a
     * Health page reading six zeros with no explanation. One search then filled 60 rows,
     * which is the correct behaviour but the wrong first impression, and it left 163
     * sources permanently invisible rather than visibly unasked.
     *
     * Existing rows are left alone, so a seed never resets an observation. The counters
     * stay at zero rather than being nulled, and no timestamp is written, because a seeded
     * row carrying `last_success_at` would read as a successful call that never happened.
     *
     * @return int How many rows were created.
     */
    public static function seedForInstalledSources(): int
    {
        $missing = Source::query()
            ->installed()
            ->whereNotExists(
                fn ($query) => $query->selectRaw('1')
                    ->from('source_healths')
                    ->whereColumn('source_healths.source_id', 'sources.suwayomi_source_id'),
            )
            ->get(['suwayomi_source_id', 'name', 'lang']);

        $now = now();

        foreach ($missing as $source) {
            SourceHealth::query()->create([
                'source_id' => (int) $source->suwayomi_source_id,
                'name' => (string) $source->name,
                'lang' => (string) $source->lang,
                'consecutive_failures' => 0,
                'consecutive_empty' => 0,
                'total_successes' => 0,
                'total_failures' => 0,
                'is_dead' => false,
            ]);
        }

        return $missing->count();
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
