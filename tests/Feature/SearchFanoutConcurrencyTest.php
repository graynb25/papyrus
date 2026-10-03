<?php

namespace Tests\Feature;

use App\Jobs\SearchSourceJob;
use App\Models\SearchRun;
use App\Models\SearchSourceResult;
use App\Models\Source;
use App\Models\User;
use App\Search\SearchFanout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The fan-out's concurrency cap.
 *
 * For months the config key existed and was read by nobody, while the fan-out's
 * comment claimed Bus::batch capped in-flight jobs. It does not: a batch queues
 * everything it is given. These tests pin the rolling window that replaced the
 * claim: only the first window dispatches, each finishing job dispatches the
 * next pending source, and every source is searched exactly once regardless.
 */
class SearchFanoutConcurrencyTest extends TestCase
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
                        'id' => random_int(100, 999),
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
     */
    private function sources(array $names): void
    {
        foreach (array_values($names) as $i => $name) {
            Source::create([
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
    }

    public function test_only_the_first_window_is_dispatched(): void
    {
        config(['papyrus.search.fanout_concurrency' => 2, 'queue.default' => 'sync']);

        $this->sources(['Alpha', 'Beta', 'Gamma', 'Delta']);

        Queue::fake();

        $component = Livewire::actingAs($this->user)->test('pages::search')
            ->set('query', 'one piece')
            ->call('search');

        $run = SearchRun::forUser($this->user->id)->first();

        Queue::assertPushed(SearchSourceJob::class, 2);

        $pending = Cache::get(SearchFanout::pendingKey($run->id));

        $this->assertCount(2, $pending, 'Four sources at a cap of two leaves two pending.');
    }

    public function test_a_capped_run_still_searches_every_source(): void
    {
        config(['papyrus.search.fanout_concurrency' => 2, 'queue.default' => 'sync']);

        $this->sources(['Alpha', 'Beta', 'Gamma', 'Delta']);

        $component = Livewire::actingAs($this->user)->test('pages::search')
            ->set('query', 'one piece')
            ->call('search');

        $run = SearchRun::forUser($this->user->id)->first();

        $this->assertSame(4, $run->sources_total);
        $this->assertTrue($run->isFinished(), 'The rolling window must reach the last source.');
        $this->assertSame(4, $run->sources_done);

        $this->assertSame(4, SearchSourceResult::forUser($this->user->id)->count());
        $this->assertFalse(Cache::has(SearchFanout::pendingKey($run->id)), 'The pending list is cleared once it empties.');
    }

    public function test_an_uncapped_catalogue_dispatches_whole(): void
    {
        config(['papyrus.search.fanout_concurrency' => 8, 'queue.default' => 'sync']);

        $this->sources(['Alpha', 'Beta', 'Gamma']);

        Livewire::actingAs($this->user)->test('pages::search')
            ->set('query', 'one piece')
            ->call('search');

        $run = SearchRun::forUser($this->user->id)->first();

        $this->assertTrue($run->isFinished());
        $this->assertSame(3, $run->sources_done);
        $this->assertFalse(Cache::has(SearchFanout::pendingKey($run->id)), 'No pending list is written when the cap covers every source.');
    }

    public function test_a_cap_of_one_still_searches_every_source(): void
    {
        config(['papyrus.search.fanout_concurrency' => 1, 'queue.default' => 'sync']);

        $this->sources(['Alpha', 'Beta', 'Gamma']);

        Livewire::actingAs($this->user)->test('pages::search')
            ->set('query', 'one piece')
            ->call('search');

        $run = SearchRun::forUser($this->user->id)->first();

        $this->assertTrue($run->isFinished());
        $this->assertSame(3, SearchSourceResult::forUser($this->user->id)->count(), 'One at a time, but all three.');
    }

    /*
     * The depth of the window, which is the thing the cap exists to bound.
     *
     * `test_only_the_first_window_is_dispatched` already bounds the opening: it counts what
     * the queue was handed and what was left pending. What no test above can see is the
     * window as it rolls, because all of them run `queue.default => sync`, which executes a
     * dispatched job inline and nested inside the one that fed it. At every instant exactly
     * one job is running however wide the window is, so a cap of two and a cap of two
     * hundred look identical on that driver.
     *
     * So the three below put the run on the `database` driver with no worker attached,
     * where a dispatched job stays in the `jobs` table until something drains it. The
     * number of rows there is then the number of sources in flight, read off the table
     * rather than inferred from what the fake was handed.
     *
     * The cache store is `database` rather than the suite's `array` for a second reason:
     * `feedNextSource()` pops the next pending source under `Cache::lock()`, and on the
     * array store that lock is a PHP array inside one process, so the mutual exclusion
     * would be exercised against a stub. Against `database` the lock is the `cache_locks`
     * row production uses, and these tests run the same lock the app does.
     *
     * What still cannot be reached is two workers taking that lock in the same moment,
     * which is the one thing `feedNextSource()` asks the lock for. A single-process suite
     * cannot produce that race without faking the lock's own failure, and a faked lock
     * proves nothing. The claim is bounded here to what was measured: the window's shape
     * at dispatch, and its depth at every step of the roll.
     */

    public function test_the_first_window_is_the_only_work_the_queue_is_holding(): void
    {
        $this->useQueuedRun(cap: 2);

        $this->sources(['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon']);

        Livewire::actingAs($this->user)->test('pages::search')
            ->set('query', 'one piece')
            ->call('search');

        $run = SearchRun::forUser($this->user->id)->first();

        // Five sources dispatched with nothing draining them, so every row here is a
        // source Suwayomi is being asked for right now.
        $this->assertSame(2, $this->queuedJobs());

        // The batch's own count agrees, which is the second layer: it was handed two
        // jobs and knows about two, not about the five the catalogue offered.
        $this->assertSame(2, (int) DB::table('job_batches')->value('total_jobs'));

        $this->assertSame(
            3,
            count(Cache::get(SearchFanout::pendingKey($run->id), [])),
            'The three sources outside the window are the pending list.',
        );
    }

    public function test_the_queue_never_holds_more_than_the_cap_while_the_window_rolls(): void
    {
        $this->useQueuedRun(cap: 2);

        $this->sources(['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon']);

        Livewire::actingAs($this->user)->test('pages::search')
            ->set('query', 'one piece')
            ->call('search');

        /*
         * Two at a time, and never three. Read straight off the table: five
         * sources opened as two, the first three drained jobs each handed on
         * one successor so the count held, and the last two drained off the
         * back of a window that was already empty. The sequence is the whole
         * claim, because its maximum is the cap and its tail is the run
         * draining rather than stalling.
         */
        $this->assertSame([2, 2, 2, 1, 0], $this->drainDepths());
    }

    public function test_the_window_follows_the_configured_cap(): void
    {
        $this->useQueuedRun(cap: 3);

        $this->sources(['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon']);

        Livewire::actingAs($this->user)->test('pages::search')
            ->set('query', 'one piece')
            ->call('search');

        // Three rather than two, so the depth is the configured number rather than a
        // shape that happens to look capped. An uncapped fan-out would open at five.
        $this->assertSame(3, $this->queuedJobs());
        $this->assertSame([3, 3, 2, 1, 0], $this->drainDepths());

        $run = SearchRun::forUser($this->user->id)->first();

        $this->assertTrue($run->isFinished(), 'The window has to reach the last source, not just bound the first.');
        $this->assertSame(5, $run->sources_done);
        $this->assertSame(5, SearchSourceResult::forUser($this->user->id)->count());
    }

    /**
     * Put the run on a queue that holds jobs, and on a cache store with a real lock.
     */
    private function useQueuedRun(int $cap): void
    {
        config([
            'papyrus.search.fanout_concurrency' => $cap,
            'queue.default' => 'database',
            'cache.default' => 'database',
        ]);
    }

    /**
     * How many of this run's sources are sitting on the queue, undrained.
     *
     * Scoped by the job class rather than counting the table, so a search page that
     * dispatches something else cannot be mistaken for a wider window.
     */
    private function queuedJobs(): int
    {
        $names = DB::table('jobs')
            ->pluck('payload')
            ->map(fn (string $payload): mixed => json_decode($payload, true)['displayName'] ?? null);

        return $names->filter(fn (mixed $name): bool => $name === SearchSourceJob::class)->count();
    }

    /**
     * Run the queue down one job at a time, recording what is left each time.
     *
     * One `--once` worker per job is the honest shape available here: a real worker
     * picks a job off the table, and this reads the table between picks. A job that
     * feeds its successor adds the row back before the worker returns, so the count
     * read after each pick is the number of sources still in flight.
     *
     * @return array<int, int>
     */
    private function drainDepths(): array
    {
        $depths = [];

        while ($this->queuedJobs() > 0) {
            $this->artisan('queue:work', ['--once' => true]);

            $depths[] = $this->queuedJobs();
        }

        return $depths;
    }
}
