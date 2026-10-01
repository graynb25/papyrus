<?php

namespace Tests\Feature\Search;

use App\Models\Source;
use App\Models\SourceHealth;
use App\Search\HealthRecorder;
use App\Search\SourceSearchOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The health ledger is the only record that a source has gone quiet, because
 * Suwayomi exposes no per-source health anywhere. A live dead source (Aqua
 * Manga) answers with zero results and no error, so "empty" and "failed" have
 * to stay distinguishable or a broken source looks like a good search.
 */
class HealthRecorderTest extends TestCase
{
    use RefreshDatabase;

    private HealthRecorder $recorder;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recorder = app(HealthRecorder::class);

        // A source id beyond 2^53, as the live instance actually returns.
        $this->source = Source::create([
            'suwayomi_source_id' => 8513894820944573933,
            'name' => 'Aquamanga',
            'display_name' => 'Aqua Manga',
            'extension_pkg_name' => 'eu.kanade.tachiyomi.extension.en.aquamanga',
            'lang' => 'en',
            'content_warning' => Source::SAFE,
        ]);
    }

    public function test_a_hit_records_success_and_latency(): void
    {
        $health = $this->recorder->record($this->source, SourceSearchOutcome::ok([
            ['id' => 1, 'title' => 'Shadow Slave'],
        ], 420));

        $this->assertSame(1, $health->total_successes);
        $this->assertSame(0, $health->consecutive_failures);
        $this->assertSame(0, $health->consecutive_empty);
        $this->assertSame(420, $health->avg_latency_ms);
        $this->assertNotNull($health->last_success_at);
        $this->assertFalse($health->is_dead);
    }

    /**
     * The Aqua Manga case: the source resolves, answers, and returns nothing.
     * Reachable, so not a failure - but a run of these is the only evidence
     * anything is wrong.
     */
    public function test_an_empty_response_is_recorded_as_silent_not_failed(): void
    {
        $health = $this->recorder->record($this->source, SourceSearchOutcome::ok([], 300));

        $this->assertSame(1, $health->total_successes);
        $this->assertSame(0, $health->total_failures);
        $this->assertSame(1, $health->consecutive_empty);
        $this->assertFalse($health->is_dead, 'Answering is not the same as being broken.');
    }

    public function test_a_repeat_empty_response_increments_the_silent_run(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $health = $this->recorder->record($this->source, SourceSearchOutcome::ok([], 250));
        }

        $this->assertSame(3, $health->consecutive_empty);
        $this->assertSame(0, $health->total_failures);
    }

    public function test_a_hit_clears_the_silent_run(): void
    {
        $this->recorder->record($this->source, SourceSearchOutcome::ok([], 100));
        $this->recorder->record($this->source, SourceSearchOutcome::ok([], 100));

        $health = $this->recorder->record($this->source, SourceSearchOutcome::ok([
            ['id' => 1],
        ], 100));

        $this->assertSame(0, $health->consecutive_empty);
    }

    public function test_a_timeout_counts_as_a_failure(): void
    {
        $health = $this->recorder->record($this->source, SourceSearchOutcome::timeout(20_000));

        $this->assertSame(1, $health->total_failures);
        $this->assertSame(1, $health->consecutive_failures);
        $this->assertNotNull($health->last_error_at);
        $this->assertNotNull($health->last_error);
        $this->assertFalse($health->is_dead, 'One failure is not enough to condemn a source.');
    }

    public function test_a_source_is_marked_dead_after_repeated_failures(): void
    {
        $health = null;

        for ($i = 0; $i < HealthRecorder::DEAD_AFTER_FAILURES; $i++) {
            $health = $this->recorder->record($this->source, SourceSearchOutcome::error('boom', 100));
        }

        $this->assertTrue($health->is_dead);
        $this->assertNotNull($health->marked_dead_at);
        $this->assertSame('boom', $health->last_error);
    }

    public function test_recovering_clears_the_dead_flag(): void
    {
        for ($i = 0; $i < HealthRecorder::DEAD_AFTER_FAILURES; $i++) {
            $health = $this->recorder->record($this->source, SourceSearchOutcome::error('boom', 100));
        }
        $this->assertTrue($health->is_dead);

        $recovered = $this->recorder->record($this->source, SourceSearchOutcome::ok([['id' => 1]], 120));

        $this->assertFalse($recovered->is_dead);
        $this->assertNull($recovered->marked_dead_at);
        $this->assertNull($recovered->last_error);
        $this->assertSame(0, $recovered->consecutive_failures);
    }

    public function test_the_ledger_is_shared_across_users(): void
    {
        // Health describes the upstream, which does not care who asked, so the
        // table is deliberately not user-scoped.
        $this->recorder->record($this->source, SourceSearchOutcome::ok([['id' => 1]], 100));

        $this->assertSame(1, SourceHealth::query()->count());
        $this->assertSame(
            8513894820944573933,
            SourceHealth::query()->first()->source_id,
        );
    }

    public function test_latency_averages_across_samples(): void
    {
        $this->recorder->record($this->source, SourceSearchOutcome::ok([['id' => 1]], 100));
        $health = $this->recorder->record($this->source, SourceSearchOutcome::ok([['id' => 1]], 300));

        $this->assertSame(200, $health->avg_latency_ms);
    }
}
