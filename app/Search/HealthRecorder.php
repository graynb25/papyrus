<?php

namespace App\Search;

use App\Models\SearchSourceResult;
use App\Models\Source;
use App\Models\SourceHealth;

/**
 * Folds one search outcome into the source health ledger.
 *
 * Suwayomi exposes no per-source health anywhere in its schema - not last
 * success, not last error, not a rate-limit flag - even though the server keeps
 * RateLimitInterceptor and CloudflareInterceptor internally. This ledger is
 * built purely by watching what comes back, which makes it the only record that
 * a source has gone quiet.
 *
 * Three states are tracked separately on purpose:
 *
 *   ok      - answered with results
 *   empty   - answered with nothing. Reachable, but silent. Repeated empties
 *             are the signature of a dead source that still resolves.
 *   failure - timed out or errored
 */
class HealthRecorder
{
    /** Consecutive hard failures before a source is considered dead. */
    public const DEAD_AFTER_FAILURES = 5;

    /**
     * Consecutive empty responses before a source is flagged as "returning
     * nothing". Set high on purpose: a niche source legitimately has no match
     * for most queries, so a low threshold would bury working sources.
     */
    public const SILENT_AFTER_EMPTY = 20;

    public function record(Source $source, SourceSearchOutcome $outcome): SourceHealth
    {
        $health = SourceHealth::query()->firstOrNew([
            'source_id' => $source->suwayomi_source_id,
        ]);

        $this->initialiseCounters($health);

        $health->name = $source->name;
        $health->lang = $source->lang;

        $this->applyLatency($health, $outcome->durationMs);

        match ($outcome->status) {
            SearchSourceResult::OK => $this->applySuccess($health, $outcome),
            SearchSourceResult::EMPTY => $this->applyEmpty($health),
            default => $this->applyFailure($health, $outcome),
        };

        $health->save();

        return $health;
    }

    /**
     * firstOrNew returns an unsaved model whose DB-defaulted columns are null,
     * not 0. Several counters are read before they are ever incremented, so
     * they have to be seeded here rather than trusted to the schema.
     */
    private function initialiseCounters(SourceHealth $health): void
    {
        foreach ([
            'consecutive_failures',
            'consecutive_empty',
            'total_successes',
            'total_failures',
        ] as $column) {
            $health->{$column} ??= 0;
        }

        $health->is_dead ??= false;
    }

    private function applySuccess(SourceHealth $health, SourceSearchOutcome $outcome): void
    {
        $health->total_successes++;
        $health->consecutive_failures = 0;
        $health->consecutive_empty = 0;
        $health->last_success_at = now();
        $health->is_dead = false;
        $health->marked_dead_at = null;
        $health->last_error = null;
    }

    /**
     * Reachable but silent. Not a failure, so the failure streak is untouched -
     * but a run of these is the only evidence a resolved-but-dead source gives.
     */
    private function applyEmpty(SourceHealth $health): void
    {
        $health->total_successes++;
        $health->last_success_at = now();
        $health->consecutive_empty++;

        if ($health->consecutive_failures > 0) {
            $health->consecutive_failures = 0;
        }

        // A source that has just answered is not dead, however quiet it is.
        $health->is_dead = false;
        $health->marked_dead_at = null;
    }

    private function applyFailure(SourceHealth $health, SourceSearchOutcome $outcome): void
    {
        $health->total_failures++;
        $health->consecutive_failures++;
        $health->last_error_at = now();
        $health->last_error = $outcome->error;

        if ($health->consecutive_empty > 0) {
            $health->consecutive_empty = 0;
        }

        if ($health->consecutive_failures >= self::DEAD_AFTER_FAILURES && ! $health->is_dead) {
            $health->is_dead = true;
            $health->marked_dead_at = now();
        }
    }

    /**
     * Running average. Simpler than a windowed mean and adequate for a ledger
     * whose purpose is spotting a source that got slow, not precise profiling.
     */
    private function applyLatency(SourceHealth $health, int $durationMs): void
    {
        if ($durationMs <= 0) {
            return;
        }

        $samples = $health->total_successes + $health->total_failures + 1;
        $current = $health->avg_latency_ms ?? 0;

        $health->avg_latency_ms = (int) round((($current * ($samples - 1)) + $durationMs) / $samples);
    }
}
