<?php

namespace Tests\Feature;

use App\AniList\Tracking;
use App\Jobs\SyncAniListProgress;
use App\Models\AniListAccount;
use App\Models\AniListMedia;
use App\Models\User;
use App\Search\TitleNormalizer;
use App\Support\ReadingState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reading progress pushed to AniList.
 *
 * Three facts these tests exist to pin:
 *
 *  - **The token is the whole credential.** AniList issues no refresh token,
 *    so a rejected token deletes the connection rather than pretending it can
 *    recover, and the reader is told to connect again.
 *  - **The progress number is the largest chapter number read**, floored,
 *    because AniList counts chapters and sources number them 10.5.
 *  - **Nothing is pushed without a reader connection.** Every hook (the
 *    progress endpoint, the series page's read toggles) schedules through
 *    scheduleSync, which is a no-op for a reader who never connected, so the
 *    feature costs the uninterested reader nothing.
 */
class AniListTrackingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /**
     * Suwayomi answering a chapter map for manga 76, and AniList answering
     * whatever the current test needs from its side.
     *
     * @param  array<int, mixed>  $extra
     */
    private function fakeApis(array $extra = []): void
    {
        Http::fake(function (Request $request) use ($extra) {
            $url = (string) $request->url();
            $body = (string) $request->body();

            if (str_contains($url, '127.0.0.1:4567')) {
                if (str_contains($body, 'mutation Login')) {
                    return Http::response(['data' => ['login' => [
                        'accessToken' => 'suwayomi-token',
                        'refreshToken' => 'r',
                    ]]]);
                }

                if (str_contains($body, 'ChapterMap') || str_contains($body, 'manga(id:')) {
                    return Http::response(['data' => ['manga' => [
                        'title' => 'Series 76',
                        'chapters' => ['nodes' => [
                            ['id' => '7601', 'name' => 'Chapter 1', 'chapterNumber' => 1],
                            ['id' => '7602', 'name' => 'Chapter 2', 'chapterNumber' => 2],
                            ['id' => '7603', 'name' => 'Chapter 3', 'chapterNumber' => 3],
                        ]],
                    ]]]);
                }

                return Http::response(['data' => []]);
            }

            if (str_contains($url, 'anilist.co/api/v2/oauth/token')) {
                return $extra['token'] ?? Http::response([
                    'access_token' => 'anilist-token-1',
                    'token_type' => 'Bearer',
                    'expires_in' => 31536000,
                ]);
            }

            if (str_contains($url, 'graphql.anilist.co')) {
                if (isset($extra['viewer']) && str_contains($body, 'Viewer')) {
                    return $extra['viewer'];
                }

                if (isset($extra['save']) && str_contains($body, 'SaveMediaListEntry')) {
                    return $extra['save'];
                }

                if (str_contains($body, 'Viewer')) {
                    return Http::response(['data' => ['Viewer' => ['id' => 4242, 'name' => 'Reader']]]);

                }

                return Http::response(['data' => ['SaveMediaListEntry' => [
                    'id' => 9, 'progress' => 2, 'status' => 'CURRENT',
                ]]]);
            }

            return Http::response(['data' => []]);
        });
    }

    private function connect(): AniListAccount
    {
        return app(Tracking::class)->connect(
            $this->user,
            'anilist-token-1',
            4242,
            'Reader',
            now()->addYear(),
        );
    }

    private function seedReadChapters(): void
    {
        $state = app(ReadingState::class);
        $state->markRead($this->user, 76, 7601, true);
        $state->markRead($this->user, 76, 7602, true);
    }

    private function seedMedia(): void
    {
        AniListMedia::create([
            'anilist_id' => 424242,
            // Keyed the way the resolver will look it up, computed with the
            // normalizer rather than guessed: it strips spaces.
            'normalized_title' => app(TitleNormalizer::class)->normalise('Series 76'),
            'title_english' => 'Series 76',
            'chapters' => 100,
            'looked_up_at' => now(),
        ]);
    }

    // The connection -------------------------------------------------------

    public function test_the_exchange_stores_the_token_and_who_it_belongs_to(): void
    {
        $this->fakeApis();

        $this->withSession(['anilist_oauth_state' => 'expected-state'])
            ->get('/anilist/callback?code=the-code&state=expected-state')
            ->assertRedirect(route('connections.edit'));

        $account = AniListAccount::forUser($this->user->id)->first();

        $this->assertNotNull($account);
        $this->assertSame('anilist-token-1', $account->access_token);
        $this->assertSame(4242, $account->anilist_user_id);
        $this->assertSame('Reader', $account->anilist_user_name);
        $this->assertNotNull($account->token_expires_at);
    }

    public function test_a_state_mismatch_connects_nobody(): void
    {
        $this->fakeApis();

        $this->withSession(['anilist_oauth_state' => 'expected-state'])
            ->get('/anilist/callback?code=the-code&state=forged')
            ->assertRedirect(route('connections.edit'));

        $this->assertSame(0, AniListAccount::forAllUsers()->count());
    }

    public function test_a_refused_exchange_connects_nobody(): void
    {
        $this->fakeApis(['token' => Http::response(['error' => 'bad verification code'], 400)]);

        $this->withSession(['anilist_oauth_state' => 'expected-state'])
            ->get('/anilist/callback?code=the-code&state=expected-state')
            ->assertRedirect(route('connections.edit'));

        $this->assertSame(0, AniListAccount::forAllUsers()->count());
    }

    public function test_the_callback_needs_a_signed_in_reader(): void
    {
        $this->fakeApis();

        auth()->logout();

        $this->get('/anilist/callback?code=the-code&state=x')->assertRedirect();
    }

    public function test_the_token_is_never_rendered(): void
    {
        $this->fakeApis();
        $this->connect();

        $this->get('/settings/connections')
            ->assertOk()
            ->assertDontSee('anilist-token-1');
    }

    // The push -------------------------------------------------------------

    public function test_sync_pushes_the_largest_chapter_number_read(): void
    {
        $this->fakeApis();
        $this->connect();
        $this->seedMedia();
        $this->seedReadChapters();

        $result = app(Tracking::class)->sync($this->user, 76);

        $this->assertSame('pushed', $result);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains((string) $request->url(), 'graphql.anilist.co')) {
                return false;
            }

            $body = (string) $request->body();

            return str_contains($body, 'SaveMediaListEntry')
                && str_contains($body, '"mediaId":424242')
                && str_contains($body, '"progress":2')
                && str_contains($body, '"status":"CURRENT"')
                && str_contains((string) ($request->header('Authorization')[0] ?? ''), 'Bearer anilist-token-1');
        });
    }

    public function test_sync_marks_completed_when_the_push_reaches_ani_list_s_own_total(): void
    {
        $this->fakeApis();
        $this->connect();

        // AniList says this series is three chapters long, and the reader has
        // read up to number 3.
        AniListMedia::create([
            'anilist_id' => 424242,
            'normalized_title' => app(TitleNormalizer::class)->normalise('Series 76'),
            'title_english' => 'Series 76',
            'chapters' => 3,
            'looked_up_at' => now(),
        ]);

        app(ReadingState::class)->markRead($this->user, 76, 7603, true);

        $result = app(Tracking::class)->sync($this->user, 76);

        $this->assertSame('completed', $result);

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request->body(), '"status":"COMPLETED"'));
    }

    public function test_a_rejected_token_disconnects_the_reader(): void
    {
        $this->fakeApis([
            'save' => Http::response(['error' => 'Invalid token'], 401),
        ]);
        $this->connect();
        $this->seedMedia();
        $this->seedReadChapters();

        $result = app(Tracking::class)->sync($this->user, 76);

        $this->assertSame('token_rejected', $result);
        $this->assertNull(AniListAccount::forUser($this->user->id)->first());
    }

    public function test_a_reader_with_no_connection_pushes_nothing(): void
    {
        $this->fakeApis();
        $this->seedMedia();
        $this->seedReadChapters();

        $this->assertSame('not_connected', app(Tracking::class)->sync($this->user, 76));

        Http::assertNothingSent();
    }

    public function test_a_title_ani_list_has_never_heard_of_is_left_alone(): void
    {
        $this->connect();
        $this->seedReadChapters();

        // No AniListMedia row, and the lookup the resolver makes comes back
        // with no Media, which is a recorded no rather than a failure.
        Http::fake(function (Request $request) {
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
                    'chapters' => ['nodes' => [['id' => '7601', 'name' => 'Chapter 1', 'chapterNumber' => 1]]],
                ]]]);
            }

            if (str_contains($url, 'graphql.anilist.co')) {
                // The real shape for an unmatched search is a 404 with a Not Found
                // error. Measured 2026-10-02, and the 200-with-null-Media this used to
                // fake is a response this endpoint does not produce.
                return Http::response([
                    'errors' => [['message' => 'Not Found.', 'status' => 404]],
                    'data' => ['Media' => null],
                ], 404);
            }

            return Http::response(['data' => []]);
        });

        $this->assertSame('unmatched', app(Tracking::class)->sync($this->user, 76));

        // The no is remembered, so the next read of the same series does not
        // ask AniList again.
        $this->assertSame(1, AniListMedia::where('anilist_id', 0)->count());
    }

    // The schedule ---------------------------------------------------------

    public function test_the_schedule_is_a_no_op_without_a_connection(): void
    {
        Queue::fake();

        app(Tracking::class)->scheduleSync($this->user, 76);

        Queue::assertNotPushed(SyncAniListProgress::class);
    }

    public function test_a_continuous_session_books_one_job_not_one_per_ping(): void
    {
        $this->fakeApis();
        $this->connect();

        Queue::fake();

        app(Tracking::class)->scheduleSync($this->user, 76);
        app(Tracking::class)->scheduleSync($this->user, 76);
        app(Tracking::class)->scheduleSync($this->user, 76);

        Queue::assertPushed(SyncAniListProgress::class, 1);

        app(Tracking::class)->clearPending($this->user->id, 76);

        app(Tracking::class)->scheduleSync($this->user, 76);

        Queue::assertPushed(SyncAniListProgress::class, 2);
    }

    public function test_the_progress_endpoint_books_a_job_when_a_chapter_finishes(): void
    {
        $this->fakeApis();
        $this->connect();

        Queue::fake();

        // Page 2 of 3 is not read yet, so no push. The last page is, and is.
        $this->post('/read/76/7603/progress', ['page' => 1, 'total' => 3]);
        Queue::assertNotPushed(SyncAniListProgress::class);

        $this->post('/read/76/7603/progress', ['page' => 2, 'total' => 3]);
        Queue::assertPushed(SyncAniListProgress::class, 1);
    }

    public function test_the_series_page_s_bulk_mark_books_a_job(): void
    {
        $this->fakeApis();
        $this->connect();

        Queue::fake();

        // fakeApis already answers manga(id:) with three chapters, which is all
        // the series page needs to mark them.
        Livewire::test('pages::series', ['manga' => 76])
            ->call('markAllRead')
            ->assertHasNoErrors();

        Queue::assertPushed(SyncAniListProgress::class, 1);
    }
}
