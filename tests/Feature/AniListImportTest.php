<?php

namespace Tests\Feature;

use App\AniList\Import;
use App\AniList\Tracking;
use App\Jobs\ImportAniListProgress;
use App\Models\AniListAccount;
use App\Models\AniListMedia;
use App\Models\User;
use App\Search\TitleNormalizer;
use App\Support\ReadingState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Progress pulled in from AniList.
 *
 * The three limits these tests exist to hold are the ones the page promises:
 * an import marks forward only, touches library series only, and records a
 * title AniList has never heard of rather than guessing at it. A fourth follows
 * from the first: nothing here is allowed to infer a chapter number out of a
 * chapter count.
 */
class AniListImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Import $import;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->import = app(Import::class);

        $this->connect();
    }

    private function connect(): void
    {
        app(Tracking::class)->connect(
            $this->user,
            'anilist-token-1',
            4242,
            'Reader',
            now()->addYear(),
        );
    }

    /**
     * Suwayomi answers a chapter map for series 76 (three chapters), AniList
     * answers the list the current test configures and any title lookup.
     *
     * @param  array<int, mixed>  $shape
     */
    private function fakeApis(array $shape = []): void
    {
        Http::fake(function (Request $request) use ($shape) {
            $url = (string) $request->url();
            $body = (string) $request->body();

            if (str_contains($url, '127.0.0.1:4567')) {
                if (str_contains($body, 'mutation Login')) {
                    return Http::response(['data' => ['login' => [
                        'accessToken' => 'suwayomi-token',
                        'refreshToken' => 'r',
                    ]]]);
                }

                return Http::response(['data' => ['manga' => [
                    'title' => 'Series 76',
                    'chapters' => ['nodes' => [
                        ['id' => '7601', 'chapterNumber' => 1],
                        ['id' => '7602', 'chapterNumber' => 2],
                        ['id' => '7603', 'chapterNumber' => 3],
                    ]],
                ]]]);
            }

            if (str_contains($url, 'graphql.anilist.co')) {
                if (str_contains($body, 'MediaListCollection')) {
                    return $shape['list'] ?? Http::response(['data' => ['MediaListCollection' => ['lists' => []]]]);
                }

                if (str_contains($body, 'Media(search:')) {
                    /*
                     * An unmatched search answers HTTP 404 with a Not Found error, not a
                     * 200 carrying a null Media. Measured 2026-10-02 against the live API.
                     * Faking the 200 is what let eighteen tests pass over an import that
                     * could not complete: the client read the real 404 as a refusal, the
                     * job backed off and retried the same title, and the run never got
                     * past its first series. Copy the real shape.
                     */
                    return $shape['lookup'] ?? Http::response([
                        'errors' => [['message' => 'Not Found.', 'status' => 404]],
                        'data' => ['Media' => null],
                    ], 404);
                }

                return Http::response(['data' => []]);
            }

            return Http::response(['data' => []]);
        });
    }

    private function seedMedia(int $anilistId, int $chapters): void
    {
        AniListMedia::create([
            'anilist_id' => $anilistId,
            'normalized_title' => app(TitleNormalizer::class)->normalise('Series 76'),
            'title_english' => 'Series 76',
            'chapters' => $chapters,
            'looked_up_at' => now(),
        ]);
    }

    private function entry(array $attributes): array
    {
        return [
            'progress' => null,
            'status' => 'CURRENT',
            'media' => ['id' => 424242, 'chapters' => null],
            ...$attributes,
        ];
    }

    /**
     * The MediaListCollection answer, built in one place.
     *
     * A four-deep array literal written per test miscounts its brackets and
     * reports the error lines away from the cause, so the shape lives here.
     * The return is whatever `Http::response()` gives, which only the fake
     * machinery unwraps: it is usable solely as a stub's return value.
     */
    private function listResponse(array $entries)
    {
        return Http::response(['data' => ['MediaListCollection' => ['lists' => [['entries' => $entries]]]]]);
    }

    // The pull -------------------------------------------------------------

    public function test_import_marks_read_up_to_ani_list_progress(): void
    {
        $this->fakeApis(['list' => $this->listResponse([$this->entry(['progress' => 2])])]);

        app(ReadingState::class)->setInLibrary($this->user, 76, true);
        $this->seedMedia(424242, 3);

        $more = $this->import->runPass($this->user->id);

        $this->assertFalse($more, 'A one series library finishes in one pass.');

        foreach ([7601, 7602] as $chapterId) {
            $this->assertDatabaseHas('chapter_states', [
                'user_id' => $this->user->id,
                'suwayomi_chapter_id' => $chapterId,
                'is_read' => true,
            ]);
        }

        // The import marks only up to the progress AniList holds.
        $this->assertDatabaseMissing('chapter_states', [
            'user_id' => $this->user->id,
            'suwayomi_chapter_id' => 7603,
        ]);

        $reading = app(ReadingState::class);
        $this->assertSame(7602, $reading->findSeries($this->user, 76)->last_read_chapter_id, 'The pointer lands on the furthest imported chapter.');

        $state = $this->import->state($this->user->id);
        $this->assertSame('done', $state['phase']);
        $this->assertSame(1, $state['series_marked']);
        $this->assertSame(2, $state['chapters_marked']);
    }

    public function test_import_never_marks_unread(): void
    {
        $this->fakeApis(['list' => $this->listResponse([$this->entry(['progress' => 1])])]);

        $reading = app(ReadingState::class);
        $reading->setInLibrary($this->user, 76, true);
        $reading->markRead($this->user, 76, 7601, true);
        $reading->markRead($this->user, 76, 7602, true);
        $this->seedMedia(424242, 3);

        $this->import->runPass($this->user->id);

        $this->assertTrue((bool) $reading->findChapter($this->user, 7602)?->is_read, 'Progress 1 against two read chapters marks nothing down.');

        $state = $this->import->state($this->user->id);
        $this->assertSame(0, $state['series_marked']);
    }

    public function test_a_title_ani_list_has_never_heard_of_is_recorded(): void
    {
        $this->fakeApis();

        app(ReadingState::class)->setInLibrary($this->user, 76, true);

        $this->import->runPass($this->user->id);

        $state = $this->import->state($this->user->id);

        $this->assertSame(['Series 76'], $state['unmatched']);
        $this->assertSame(0, $state['series_marked']);
        $this->assertDatabaseMissing('chapter_states', [
            'user_id' => $this->user->id,
            'suwayomi_chapter_id' => 7601,
        ]);
    }

    public function test_import_touches_library_series_only(): void
    {
        $this->fakeApis(['list' => $this->listResponse([$this->entry(['progress' => 3])])]);

        // State exists, but the series is not in the library.
        app(ReadingState::class)->setInLibrary($this->user, 76, false);
        $this->seedMedia(424242, 3);

        $this->import->runPass($this->user->id);

        $state = $this->import->state($this->user->id);

        $this->assertSame(0, $state['total']);
        $this->assertSame(0, $state['series_marked']);
        $this->assertNull(app(ReadingState::class)->findChapter($this->user, 7601));
    }

    public function test_a_completed_entry_without_progress_marks_nothing(): void
    {
        $this->fakeApis(['list' => $this->listResponse([
            $this->entry(['status' => 'COMPLETED', 'progress' => null, 'media' => ['id' => 424242, 'chapters' => 3]]),
        ])]);

        app(ReadingState::class)->setInLibrary($this->user, 76, true);
        $this->seedMedia(424242, 3);

        $more = $this->import->runPass($this->user->id);

        $this->assertFalse($more);

        $state = $this->import->state($this->user->id);
        $this->assertSame(0, $state['series_marked']);

        /*
         * AniList's own chapter count is not this reader's progress. It counts
         * the original release including chapters no scanlation has, and it
         * over-counted the sampled series by 9 and by 17, so reading it as
         * progress marks chapters read that the reader never said they had read.
         * `Tracking::sync()` refuses the same inference in the other direction
         * and `Client` stores the count as reference data for the same reason.
         *
         * An import marks forward only, so a guess here cannot be taken back by
         * running the import again: nothing is written, the series is left as it
         * is, and AniList's COMPLETED is honoured by declining to invent the
         * progress that went with it.
         */
        $this->assertDatabaseMissing('chapter_states', [
            'user_id' => $this->user->id,
        ]);
    }

    public function test_the_lookup_gap_is_read_from_config(): void
    {
        // Three, so a pass that slept the old hardcoded two cannot pass this.
        config(['papyrus.anilist.gap_seconds' => 3]);

        app(ReadingState::class)->setInLibrary($this->user, 76, true);

        $this->fakeApis();

        $started = microtime(true);
        $this->import->runPass($this->user->id);
        $elapsed = microtime(true) - $started;

        // The key is the number the gap comes from, so moving it moves the wait,
        // and the literal this replaced was two.
        $this->assertGreaterThanOrEqual(3.0, $elapsed);
    }

    public function test_a_title_ani_list_has_never_heard_of_is_never_slept_for(): void
    {
        config(['papyrus.anilist.gap_seconds' => 3]);

        // An id of 0 is the sentinel row `TitleResolver::resolve()` writes when
        // AniList has no such title, and the reason it returns without asking
        // anything again.
        $this->seedMedia(0, 0);

        app(ReadingState::class)->setInLibrary($this->user, 76, true);

        $this->fakeApis();

        $started = microtime(true);
        $this->import->runPass($this->user->id);
        $elapsed = microtime(true) - $started;

        $state = $this->import->state($this->user->id);
        $this->assertSame(['Series 76'], $state['unmatched']);

        /*
         * The gap buys a request a slot in the rate limit window, and a sentinel
         * row means no request goes out. Sleeping for one anyway made a
         * permanently unmatched series pay two seconds on every pass it appeared
         * in, for ever, which across a 300 series library is ten minutes of
         * sleeping across thirty passes and not one lookup.
         */
        $this->assertLessThan(1.0, $elapsed, 'An answer already recorded is not a request, so it is not slept for.');

        Http::assertNotSent(fn (Request $request): bool => str_contains((string) $request->url(), 'graphql.anilist.co')
            && str_contains((string) $request->body(), 'Media(search:'));
    }

    public function test_a_long_library_arrives_over_several_passes(): void
    {
        $this->seedMedia(424242, 3);

        for ($manga = 1; $manga <= 12; $manga++) {
            app(ReadingState::class)->setInLibrary($this->user, $manga, true);
        }

        // One fake, registered once: a second Http::fake does not replace the
        // first. Chapter ids are distinct per manga so the rows cannot alias.
        Http::fake(function (Request $request) {
            $url = (string) $request->url();
            $body = (string) $request->body();

            if (str_contains($url, '127.0.0.1:4567')) {
                if (str_contains($body, 'mutation Login')) {
                    return Http::response(['data' => ['login' => [
                        'accessToken' => 'suwayomi-token', 'refreshToken' => 'r',
                    ]]]);
                }

                $id = (int) (data_get($request->data(), 'variables.id') ?? 0);
                $nodes = [];

                foreach (range(1, 3) as $n) {
                    $nodes[] = ['id' => (string) ($id * 10 + $n), 'chapterNumber' => $n];
                }

                return Http::response(['data' => ['manga' => [
                    'title' => 'Series 76',
                    'chapters' => ['nodes' => $nodes],
                ]]]);
            }

            if (str_contains($body, 'MediaListCollection')) {
                return $this->listResponse([$this->entry(['progress' => 1])]);
            }

            return Http::response(['data' => []]);
        });

        $passes = 0;
        $more = true;

        while ($more && $passes < 10) {
            $more = $this->import->runPass($this->user->id);
            $passes++;
        }

        $this->assertFalse($more);
        $this->assertSame(2, $passes, 'Twelve series at ten a pass is two passes.');
        $this->assertSame(12, $this->import->state($this->user->id)['processed']);
        $this->assertSame(12, $this->import->state($this->user->id)['series_marked']);
    }

    public function test_a_pass_that_has_spent_its_budget_hands_the_rest_back(): void
    {
        // A budget of zero is the fastest way to spend one, and one series still
        // runs per pass, because a pass that could decide it had no time at all
        // would hand the same queue straight back and the chain would re-book
        // itself for ever without touching anything.
        config(['papyrus.anilist.pass_budget_seconds' => 0]);

        $this->seedMedia(424242, 3);

        for ($manga = 1; $manga <= 12; $manga++) {
            app(ReadingState::class)->setInLibrary($this->user, $manga, true);
        }

        $this->fakeApis(['list' => $this->listResponse([$this->entry(['progress' => 1])])]);

        $passes = 0;
        $more = true;

        while ($more && $passes < 20) {
            $more = $this->import->runPass($this->user->id);
            $passes++;
        }

        $this->assertFalse($more);
        $this->assertSame(12, $passes, 'One series a pass under a spent budget, and the chain still ends.');
        $this->assertSame(12, $this->import->state($this->user->id)['processed']);
    }

    public function test_the_page_state_carries_the_tally_and_not_the_runs_working_set(): void
    {
        $this->seedMedia(424242, 3);

        for ($manga = 1; $manga <= 12; $manga++) {
            app(ReadingState::class)->setInLibrary($this->user, $manga, true);
        }

        $this->fakeApis(['list' => $this->listResponse([$this->entry(['progress' => 1])])]);

        $this->assertTrue($this->import->runPass($this->user->id), 'Twelve series leaves a second pass to run.');

        $state = $this->import->state($this->user->id);

        /*
         * The connections page polls this entry every three seconds for as long
         * as it lives and reads seven keys out of it. The progress map is one
         * int per series the reader follows and the queue is one id per library
         * series, and both of them sat in here: a 300 series library had a
         * working set deserialised on every poll for the full six hours, to draw
         * one progress line.
         */
        $this->assertArrayNotHasKey('progress_by_media', $state);
        $this->assertArrayNotHasKey('queue', $state);

        // The tally itself is unchanged, because the template reads it.
        $this->assertSame('running', $state['phase']);
        $this->assertSame(12, $state['total']);
        $this->assertSame(10, $state['processed']);
    }

    // The schedule ---------------------------------------------------------

    public function test_start_requires_a_connection(): void
    {
        Queue::fake();

        $this->trackingDisconnect();

        $this->assertFalse($this->import->start($this->user));
        Queue::assertNotPushed(ImportAniListProgress::class);
    }

    public function test_start_dispatches_once_while_running(): void
    {
        Queue::fake();

        $this->assertTrue($this->import->start($this->user));
        Queue::assertPushed(ImportAniListProgress::class, 1);

        $this->assertFalse($this->import->start($this->user), 'A running import is not started twice.');
        Queue::assertPushed(ImportAniListProgress::class, 1);
    }

    public function test_the_running_marker_outlives_a_worst_case_pass(): void
    {
        // The gate in `start()` is this marker, so a pass that could outlast its
        // lease would lapse its own gate while it was still working, and the
        // reader watching it could book a second chain against one state key.
        $lease = Import::RUNNING_LEASE_MINUTES * 60;

        $this->assertGreaterThan(
            Import::worstCaseSeconds(),
            $lease,
            'One pass has to fit inside the marker it refreshes.'
        );

        // And inside the pass lock, which is what actually serialises the work.
        $this->assertGreaterThan(Import::worstCaseSeconds(), 300);
    }

    public function test_the_job_timeout_is_derived_from_the_worst_case_pass(): void
    {
        $derived = Import::worstCaseSeconds();

        $this->assertSame(
            $derived,
            (new ImportAniListProgress($this->user->id))->timeout,
            'The job takes the ceiling rather than typing one of its own.'
        );

        // 120 was reachable in ordinary operation: one series can cost a 15
        // second AniList timeout three times over plus two 28 second Suwayomi
        // budgets, so ten of them reach 780 seconds.
        $this->assertGreaterThan(120, $derived);

        /*
         * And it is derived, not merely larger: raising the per-call timeout
         * moves the ceiling with it. Every attempt the retries buy carries one
         * timeout, three of them, and the first pass's list fetch carries one
         * more, so 15 to 30 is +45 for the lookup and +15 for the fetch.
         */
        config(['papyrus.anilist.timeout' => 30]);

        $this->assertSame($derived + 60, Import::worstCaseSeconds());
        $this->assertSame(Import::worstCaseSeconds(), (new ImportAniListProgress($this->user->id))->timeout);
    }

    public function test_a_restart_after_a_stalled_queue_reads_the_list_again(): void
    {
        Queue::fake();

        $this->seedMedia(424242, 3);

        for ($manga = 1; $manga <= 12; $manga++) {
            app(ReadingState::class)->setInLibrary($this->user, $manga, true);
        }

        $this->fakeApis(['list' => $this->listResponse([$this->entry(['progress' => 1])])]);

        $this->import->runPass($this->user->id);

        /*
         * The one way the marker lapses with a chain still attached is a queue
         * that stops for its whole lease, which is what forgetting it by hand
         * stands in for. The run's working state is then a spent queue and half
         * a progress map under a tally that `start()` has just zeroed, so it has
         * to be dropped rather than resumed.
         */
        Cache::forget("anilist:import:{$this->user->id}:running");

        $this->assertTrue($this->import->start($this->user));
        $this->assertTrue($this->import->runPass($this->user->id));

        $state = $this->import->state($this->user->id);

        $this->assertSame(12, $state['total'], 'A new run counts the library again.');
        $this->assertSame(10, $state['processed']);
    }

    public function test_a_job_that_gives_up_flips_the_page_to_failed(): void
    {
        // Without this the sync queue runs the dispatched job inline, so `start()`
        // finishes the whole import before the assertions below ever happen.
        Queue::fake();

        $this->assertTrue($this->import->start($this->user));

        $this->assertSame(
            'running',
            $this->import->state($this->user->id)['phase'],
            'Starting leaves the page saying it is running.'
        );

        /*
         * `failOnTimeout` is on and a pass can genuinely exceed `$timeout`, and with no
         * worker the chain simply never continues. Neither path wrote `phase = failed`,
         * because only Import::fail() does, so the connections page polled "Checking N
         * of M series" for the full six hours until the state TTL expired.
         */
        (new ImportAniListProgress($this->user->id))
            ->failed(new \RuntimeException('The job has been attempted too many times.'));

        $state = $this->import->state($this->user->id);

        $this->assertSame('failed', $state['phase']);
        $this->assertStringContainsString('attempted too many times', (string) $state['error']);
    }

    public function test_a_job_that_gives_up_without_a_message_still_flips_the_phase(): void
    {
        $this->import->start($this->user);

        (new ImportAniListProgress($this->user->id))->failed(null);

        $state = $this->import->state($this->user->id);

        $this->assertSame('failed', $state['phase']);
        $this->assertNotSame('', (string) $state['error'], 'The page says something rather than showing a blank.');
    }

    /*
 * The pass lock has no test here, and that is deliberate rather than an oversight.
 *
 * `runPass` wraps its whole body in `Cache::lock("anilist:import:{id}:pass")` because it
 * is a read-modify-write over one cache key: read the tally, splice ten series off the
 * queue, mark them, write it back. Two passes overlapping clobber each other's `queue`
 * and `processed`, skipping series and double-counting others without raising anything.
 *
 * Two ways to test it, both rejected on measurement:
 *
 *   Probe the lock from inside the pass, by asking for it again while it is held.
 *   Measured on this store: a second `get()` on a held key returns a Lock rather than
 *   null, so the probe reports a correctly held lock as absent and the assertion would
 *   be asserting something untrue.
 *
 *   Hold the lock from the test and call `runPass()`. The real path blocks for 300
 *   seconds, so the test either takes five minutes or, with a short lease, succeeds
 *   once its own lock expires and so never exercises the contended branch at all.
 *
 * So the lock is unverified. What is verified is the behaviour that depends on it:
 * `test_a_long_library_arrives_over_several_passes` drives the chain to completion and
 * checks the tally, which is the thing the lock protects.
 */

    public function test_a_rejected_token_fails_the_import_and_disconnects(): void
    {
        Http::fake(function (Request $request) {
            $url = (string) $request->url();

            if (str_contains($url, '127.0.0.1:4567')) {
                return Http::response(['data' => []]);
            }

            if (str_contains($url, 'graphql.anilist.co')) {
                return Http::response(['error' => 'Invalid token'], 401);
            }

            return Http::response(['data' => []]);
        });

        app(ReadingState::class)->setInLibrary($this->user, 76, true);

        $more = $this->import->runPass($this->user->id);

        $this->assertFalse($more);
        $this->assertSame('failed', $this->import->state($this->user->id)['phase']);
        $this->assertNull(AniListAccount::forUser($this->user->id)->first());
    }

    private function trackingDisconnect(): void
    {
        app(Tracking::class)->disconnect($this->user);
    }
}
