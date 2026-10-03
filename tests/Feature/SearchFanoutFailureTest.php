<?php

namespace Tests\Feature;

use App\Jobs\SearchSourceJob;
use App\Models\SearchRun;
use App\Models\SearchSourceResult;
use App\Models\Source;
use App\Models\User;
use App\Search\FilterVocabulary;
use App\Search\HealthRecorder;
use App\Search\SearchFanout;
use App\Search\SourceSearcher;
use App\Search\TitleNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The ways a run used to hang, and the one that double-counted.
 *
 * Every test here fails against the code as it stood before this pass, and each names the
 * symptom rather than the mechanism, because the mechanism is the part that changes. The
 * window's own tests pass on the sync queue driver, where the counter arithmetic is
 * exercised but none of the failure paths are, so "the feature tests are green" was never
 * evidence that a run terminates. Four of these were found by reading the code and one by
 * running it:
 *
 *   deleted source       the run sat one short of its total for ever
 *   null run             every pending source stranded behind a slot never freed
 *   undispatchable feed  the same hang by another route, and it was documented as "slow"
 *   retry after commit   sources_done past sources_total, run stamped partial
 *
 * The last one is measured. A real fan-out on 2026-10-02 returned `done=41 total=40`,
 * status `partial`, and 547 result rows, and the seventh failed job's trace put the throw
 * inside the batch's own `decrementPendingJobs` UPDATE, which runs after the outcome was
 * already committed. The retry re-recorded a source that had reported.
 */
class SearchFanoutFailureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->fakeSuwayomi();
    }

    private function fakeSuwayomi(): void
    {
        Http::fake(function (Request $request) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => 'token-1',
                    'refreshToken' => 'r',
                ]]]);
            }

            if (str_contains($query, 'fetchSourceManga')) {
                return Http::response(['data' => ['fetchSourceManga' => [
                    'hasNextPage' => false,
                    'mangas' => [[
                        'id' => 4242,
                        'title' => 'One Piece',
                        'url' => '/one-piece',
                        'thumbnailUrl' => 'http://upstream.test/cover.jpg',
                        'status' => 'ONGOING',
                        'genre' => ['Action'],
                        'source' => ['id' => '1', 'name' => 'test'],
                    ]],
                ]]]);
            }

            return Http::response(['data' => []]);
        });
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, Source>
     */
    private function sources(array $names): array
    {
        $made = [];

        foreach (array_values($names) as $i => $name) {
            $made[] = Source::create([
                'suwayomi_source_id' => 8513894820944573933 + $i,
                'name' => $name,
                'display_name' => $name,
                'extension_pkg_name' => "eu.kanade.tachiyomi.extension.en.test{$i}",
                'lang' => 'en',
                'content_warning' => Source::SAFE,
                'is_installed' => true,
                'supports_latest' => true,
            ]);
        }

        return $made;
    }

    /**
     * Drive a real fan-out through the page, the same way a reader does.
     */
    private function fanOut(string $query = 'one piece'): SearchRun
    {
        Livewire::actingAs($this->user)
            ->test('pages::search')
            ->set('query', $query)
            ->call('search');

        return SearchRun::forUser($this->user->id)->orderByDesc('id')->first();
    }

    public function test_a_run_where_every_source_reports_finishes_and_balances(): void
    {
        config(['papyrus.search.fanout_concurrency' => 2, 'queue.default' => 'sync']);

        $this->sources(['Alpha', 'Beta', 'Gamma', 'Delta']);

        $run = $this->fanOut();

        $this->assertSame(4, $run->sources_total);
        $this->assertSame(
            $run->sources_total,
            $run->sources_done,
            'The counter must never pass the total: that is what stamps a full run partial.'
        );
        $this->assertTrue($run->isFinished());
        $this->assertSame(4, SearchSourceResult::forUser($this->user->id)->count());
    }

    public function test_a_source_deleted_mid_run_still_advances_the_counter(): void
    {
        $run = $this->makeRun(2, done: 1);

        /*
         * Driven as a single job rather than through a fan-out, because deleting the row
         * before searching does not produce the situation this is about: the fan-out
         * builds its target list from somewhere other than this table, so `sources_total`
         * came back as 2 with one row in the database and no job ever saw a missing
         * source. What the branch actually handles is a row that disappears between
         * dispatch and execution, which is what an id with no row reproduces.
         */
        $job = new SearchSourceJob(
            runId: $run->id,
            userId: $this->user->id,
            sourceId: 999_999,
            query: 'one piece',
            filterValues: [],
        );

        $job->handle(...$this->deps());

        $fresh = $run->fresh();

        $this->assertSame(
            2,
            $fresh->sources_done,
            'The old code fed the next source and returned, so the run sat one short of its total for ever.'
        );
        $this->assertSame(1, $fresh->sources_skipped);
        $this->assertTrue($fresh->isFinished(), 'And the run finishes rather than hanging.');
    }

    public function test_a_deleted_source_on_the_final_slot_still_finishes_the_run(): void
    {
        $run = $this->makeRun(1, done: 0);

        (new SearchSourceJob(
            runId: $run->id,
            userId: $this->user->id,
            sourceId: 999_999,
            query: 'one piece',
            filterValues: [],
        ))->handle(...$this->deps());

        $this->assertTrue(
            $run->fresh()->isFinished(),
            'Losing the only source must finish the run, not leave it running for ever.'
        );
    }

    public function test_a_feed_that_cannot_take_its_lock_is_accounted_for(): void
    {
        config(['papyrus.search.fanout_concurrency' => 2, 'queue.default' => 'sync']);

        $this->sources(['Alpha', 'Beta', 'Gamma']);

        $run = $this->makeRun(3, 2);

        /*
         * A lock that cannot be taken was documented as "slow". It is a hang: the pop
         * never happened, so the source is still pending and nothing will claim it, and
         * this is the last job, so done can never reach total.
         *
         * Holding the lock here rather than letting block() time out keeps the test to
         * milliseconds instead of the thirty seconds the real path waits, and it is the
         * same contention arriving from the same place.
         */
        Cache::lock("search:run:{$run->id}:feed", 30)->get();

        $job = new SearchSourceJob(
            runId: $run->id,
            userId: $this->user->id,
            sourceId: 999,
            query: 'one piece',
            filterValues: [],
        );

        // No batch, so feedNextSource() finds nothing to add to and falls through to
        // recording the shortfall, which is the path under test.
        $job->handle(...$this->deps());

        $fresh = $run->fresh();

        $this->assertSame(
            $fresh->sources_total,
            $fresh->sources_done,
            'A source that could not be dispatched has to be accounted for, or the run never finishes.'
        );
        $this->assertTrue($fresh->isFinished());
    }

    public function test_the_pending_list_survives_a_job_with_no_batch(): void
    {
        $run = $this->makeRun(3, 0);
        $key = SearchFanout::pendingKey($run->id);

        Cache::put($key, [11, 12], now()->addHour());

        $job = new SearchSourceJob(
            runId: $run->id,
            userId: $this->user->id,
            sourceId: 999,
            query: 'one piece',
            filterValues: [],
        );

        $job->handle(...$this->deps());

        $this->assertTrue(
            Cache::has($key),
            'Without a batch there is nothing to hand a source to, so the pending list must not be consumed.'
        );
    }

    public function test_a_retry_after_a_committed_outcome_does_not_count_the_source_twice(): void
    {
        config(['papyrus.search.fanout_concurrency' => 8, 'queue.default' => 'sync']);

        [$alpha] = $this->sources(['Alpha']);

        $run = SearchRun::createFor($this->user, [
            'query' => 'one piece',
            'status' => 'running',
            'sources_total' => 1,
            'sources_done' => 0,
            'sources_failed' => 0,
            'sources_skipped' => 0,
            'result_count' => 0,
        ]);

        $job = fn () => new SearchSourceJob(
            runId: $run->id,
            userId: $this->user->id,
            sourceId: $alpha->id,
            query: 'one piece',
            filterValues: [],
        );

        // First attempt: the outcome is committed inside its own transaction.
        $job()->handle(...$this->deps());

        $this->assertSame(1, $run->fresh()->sources_done);

        // Second attempt, as the queue's retry would after the batch bookkeeping threw.
        $job()->handle(...$this->deps());

        $fresh = $run->fresh();

        $this->assertSame(
            1,
            $fresh->sources_done,
            'Measured on 2026-10-02: a real run reached done=41 against total=40 and was stamped partial while returning 547 results.'
        );
        $this->assertSame(
            1,
            SearchSourceResult::forUser($this->user->id)->where('search_run_id', $run->id)->count(),
            'One source that reported, one row.'
        );
    }

    /**
     * A run in a chosen state, for the paths that drive a single job directly.
     *
     * Named makeRun rather than run because PHPUnit declares run() final.
     */
    private function makeRun(int $total, int $done): SearchRun
    {
        return SearchRun::createFor($this->user, [
            'query' => 'one piece',
            'status' => 'running',
            'sources_total' => $total,
            'sources_done' => $done,
            'sources_failed' => 0,
            'sources_skipped' => 0,
            'result_count' => 0,
        ]);
    }

    /**
     * The handler's collaborators, in the order the constructor asks for them.
     *
     * Four, and `FilterPlan` is not among them: the plan is built inside `handle()` from
     * the vocabulary. Passing five got a BindingResolutionException. The tuple is spelled
     * out because PHPStan level 7 will not accept `array<int, object>` for a signature
     * that names four concrete types.
     *
     * @return array{0: SourceSearcher, 1: TitleNormalizer, 2: HealthRecorder, 3: FilterVocabulary}
     */
    private function deps(): array
    {
        return [
            $this->app->make(SourceSearcher::class),
            $this->app->make(TitleNormalizer::class),
            $this->app->make(HealthRecorder::class),
            $this->app->make(FilterVocabulary::class),
        ];
    }
}
