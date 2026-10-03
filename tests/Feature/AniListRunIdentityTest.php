<?php

namespace Tests\Feature;

use App\AniList\Import;
use App\AniList\Tracking;
use App\Jobs\ImportAniListProgress;
use App\Models\SeriesState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A job from an earlier press must not be able to write into a newer run's tally.
 *
 * ## What this pins
 *
 * The Connections button can be pressed more than once, and a reader who pressed it while
 * the first press looked stuck is the ordinary case rather than an edge. `start()` resets
 * the tally and dispatches a job, and until this was fixed the job carried a user id and
 * nothing else, so a chain still draining from the first press could not tell that the
 * tally underneath it belonged to a different run.
 *
 * Measured against the live app before the fix: two interleaved chains pushed `processed`
 * to **30 against a `total` of 20**, and a pass that ran against a tally it had not
 * initialised finished the run with the zero `start()` had written, which rendered as
 * "Nothing to import: your library holds no series" on a library of twenty. That second
 * one is what a reader sees, and it is the reason the total is now counted at press time.
 *
 * ## Why it is a unit test and not a browser test
 *
 * The interleaving needs two chains against one state key, which a single-process suite
 * can produce by hand but a browser cannot. What is asserted here is the contract: a pass
 * carrying a run id that is not the tally's own returns without writing, and a pass
 * carrying the right one proceeds.
 */
class AniListRunIdentityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        Queue::fake();

        // A connection, or `start()` refuses before it ever mints a run.
        app(Tracking::class)->connect($this->user, 'anilist-token-1', 4242, 'Reader', now()->addYear());

        // AniList answers with an empty list, and Suwayomi a chapter map, so a pass
        // advances without reaching a title lookup. The shapes are real ones: the no-match
        // 404 is covered in AniListImportTest, where it is the point.
        Http::fake([
            'graphql.anilist.co*' => Http::response(['data' => ['MediaListCollection' => ['lists' => []]]]),
            '127.0.0.1:4567*' => Http::response(['data' => ['login' => [
                'accessToken' => 'token-1',
                'refreshToken' => 'r',
            ]]]),
        ]);

        Http::fake([
            '127.0.0.1:4567*' => Http::response(['data' => ['manga' => [
                'title' => 'A series',
                'chapters' => ['nodes' => [
                    ['id' => '7601', 'chapterNumber' => 1],
                    ['id' => '7602', 'chapterNumber' => 2],
                ]],
            ]]]),
        ]);

        Http::preventStrayRequests();
    }

    /**
     * @param  int  $count  how many series to put in the library
     */
    private function library(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            SeriesState::createFor($this->user, [
                'suwayomi_manga_id' => 1000 + $i,
                'in_library' => true,
            ]);
        }
    }

    public function test_the_total_is_counted_when_the_run_starts(): void
    {
        $this->library(7);

        $import = app(Import::class);

        $this->assertSame(
            7,
            $import->librarySize($this->user->id),
            'The library size is the page total, so it cannot depend on a pass having run.'
        );

        Queue::fake();
        $this->assertTrue($import->start($this->user));

        $state = $import->state($this->user->id);

        $this->assertSame(
            7,
            $state['total'],
            'A tally whose total only a pass can set is how an empty-library message reached a library of twenty.'
        );
    }

    public function test_starting_mints_a_run_id_and_the_job_carries_it(): void
    {
        $this->library(3);

        $import = app(Import::class);

        $this->assertTrue($import->start($this->user));

        $runId = $import->state($this->user->id)['run_id'];

        $this->assertIsString($runId);
        $this->assertNotSame('', $runId, 'A run with no identity cannot be told apart from another.');

        Queue::assertPushed(
            ImportAniListProgress::class,
            fn (ImportAniListProgress $job): bool => $job->runId === $runId
                && $job->userId === $this->user->id
        );
    }

    public function test_a_pass_from_an_earlier_run_writes_nothing(): void
    {
        $this->library(4);

        $import = app(Import::class);
        $this->assertTrue($import->start($this->user));

        $before = $import->state($this->user->id);

        // A job left over from a press the reader has already superseded.
        $stale = new ImportAniListProgress($this->user->id, 'a-run-that-is-not-this-one');

        $more = (new \ReflectionMethod($import, 'runPass'))
            ->getClosure($import)($this->user->id, $stale->runId);

        $after = $import->state($this->user->id);

        $this->assertFalse($more, 'A stale job has nothing to continue.');
        $this->assertSame(
            $before['processed'],
            $after['processed'],
            'The tally belongs to the run that minted it. A job from another press must not count against it.'
        );
        $this->assertSame($before['phase'], $after['phase']);
    }

    public function test_a_pass_carrying_the_current_run_id_proceeds(): void
    {
        $this->library(2);

        $import = app(Import::class);
        $this->assertTrue($import->start($this->user));

        $runId = $import->state($this->user->id)['run_id'];

        $passed = (new \ReflectionMethod($import, 'runPass'))
            ->getClosure($import)($this->user->id, $runId);

        $state = $import->state($this->user->id);

        // Two series is one pass, so the run finishes here and `$passed` is false. The
        // claim under test is that the matching job advanced the run at all, which is
        // what distinguishes it from the stale job above.
        $this->assertFalse($passed, 'One pass covers both series, so there is no more to do.');
        $this->assertSame(2, $state['total']);
        $this->assertSame(
            2,
            $state['processed'],
            'The matching job is the one that advances the run.'
        );
        $this->assertSame('done', $state['phase']);
    }

    public function test_a_lapsed_marker_lets_a_new_press_supersede_the_old_chain(): void
    {
        $this->library(4);

        $import = app(Import::class);

        $this->assertTrue($import->start($this->user));
        $firstRun = $import->state($this->user->id)['run_id'];

        /*
         * A second press while a chain is live is refused, by `Cache::add` on the running
         * marker, and that is correct: there is nothing for the reader to cancel and a
         * second chain would only fight the first.
         *
         * The gap is a marker that has lapsed. The lease is ten minutes, a pass re-puts
         * it, and a chain refreshes it well inside that, so a lapse means the queue has
         * stopped. The reader then presses again, which is what a reader does, and the
         * superseded chain is still sitting in the queue.
         */
        $this->assertFalse(
            $import->start($this->user),
            'A live chain is not restarted by a second press.'
        );

        $this->forgetRunningMarker($import);

        $this->assertTrue(
            $import->start($this->user),
            'With the marker lapsed, a new press is a new run.'
        );

        $secondRun = $import->state($this->user->id)['run_id'];

        $this->assertNotSame(
            $firstRun,
            $secondRun,
            'Two runs need two identities.'
        );

        // The superseded chain's job, still in the queue, now runs.
        $passed = (new \ReflectionMethod($import, 'runPass'))
            ->getClosure($import)($this->user->id, $firstRun);

        $this->assertFalse($passed, 'The superseded chain stops rather than interleaving.');
        $this->assertSame(
            $secondRun,
            $import->state($this->user->id)['run_id'],
            'And the tally still belongs to the run the reader is watching.'
        );
        $this->assertSame(
            0,
            $import->state($this->user->id)['processed'],
            'The superseded chain counted nothing against the new run.'
        );
    }

    /**
     * Expire the running marker the way a lapsed lease would.
     */
    private function forgetRunningMarker(Import $import): void
    {
        $method = new \ReflectionMethod($import, 'runningKey');
        $method->setAccessible(true);

        Cache::forget($method->invoke($import, $this->user->id));
    }
}
