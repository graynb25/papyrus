<?php

namespace Tests\Feature;

use App\Models\ReadingSession;
use App\Models\SeriesState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The insights page against its ledger.
 *
 * The important claim on this page is not that it renders; it is that its
 * numbers are the rows and then never anything else: a render changes nothing,
 * a window excludes what began outside it, and another reader's minutes are
 * nowhere on it. Those are the claims that keep the page from inventing a
 * parallel denominator next to the one Phase 0 pinned.
 */
class InsightsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create();
        $this->actingAs($this->alice);

        $this->fakeSuwayomi();
    }

    private function fakeSuwayomi(): void
    {
        Http::fake(function (Request $request) {
            $query = $request->data()['query'] ?? '';

            if (str_contains($query, 'mutation Login')) {
                return Http::response([
                    'data' => ['login' => ['accessToken' => 'token-1', 'refreshToken' => 'r']],
                ]);
            }

            if (str_contains($query, 'query Many')) {
                return Http::response(['data' => ['mangas' => ['nodes' => [
                    [
                        'id' => '76',
                        'title' => 'Chainsaw Man',
                        'status' => 'ONGOING',
                        'initialized' => true,
                        'source' => ['id' => '1', 'name' => 'MangaFire', 'lang' => 'EN'],
                        'chapters' => ['totalCount' => 210, 'nodes' => []],
                    ],
                ]]]]);
            }

            return Http::response(['data' => []]);
        });
    }

    private function sitting(User $who, int $manga, int $seconds, int $pages = 0, int $daysAgo = 0): ReadingSession
    {
        return ReadingSession::factory()->create([
            'user_id' => $who->id,
            'suwayomi_manga_id' => $manga,
            'suwayomi_chapter_id' => 1,
            'started_at' => now()->subDays($daysAgo),
            'last_active_at' => now()->subDays($daysAgo),
            'read_seconds' => $seconds,
            'pages_turned' => $pages,
            'ended_at' => now()->subDays($daysAgo)->addSeconds(30),
        ]);
    }

    // ---------------------------------------------------------------- the page

    public function test_the_page_reads_its_numbers_from_the_ledger_and_writes_nothing(): void
    {
        $this->sitting($this->alice, 76, 90, pages: 40);

        $response = $this->get('/insights');

        $response->assertOk();
        $response->assertSee('1 minute'); // Ninety seconds is one whole minute, not a rounded or a decimal one.
        $response->assertSee('across one series so far');

        $this->assertSame(1, ReadingSession::forAllUsers()->count(), 'A render is a read, and nothing else.');
    }

    public function test_a_window_excludes_rows_that_began_outside_it(): void
    {
        $this->sitting($this->alice, 76, 90);
        $this->sitting($this->alice, 76, 5000, daysAgo: 400);

        /*
         * The row begun four hundred days ago contributes nothing to a window
         * of today it did not begin in: the page counts the one row it has and
         * is silent about the one it does not.
         */
        $this->get('/insights?since='.now()->toDateString())
            ->assertOk()
            ->assertSee('1 minute')
            ->assertDontSee('1 hour 23 minutes');
    }

    public function test_a_window_with_no_rows_in_it_says_so(): void
    {
        $this->sitting($this->alice, 76, 5000, daysAgo: 400);

        $this->get('/insights?since='.now()->toDateString())
            ->assertOk()
            ->assertSee('No reading counted yet');
    }

    public function test_an_all_time_view_counts_every_row_the_ledger_holds(): void
    {
        $this->sitting($this->alice, 76, 90);
        $this->sitting($this->alice, 76, 5000, daysAgo: 400);

        $this->get('/insights')
            ->assertOk()
            /*
             * The rows are one series, two sittings, so the bars group them and
             * the total is the two together: ninety seconds plus one hour and
             * twenty three minutes is one hour and twenty four.
             */
            ->assertSee('1 hour 24 minutes')
            ->assertSee('2 sittings');
    }

    public function test_rows_are_never_shared_between_readers(): void
    {
        $bob = User::factory()->create();

        $this->sitting($this->alice, 76, 90);
        $this->sitting($bob, 76, 5400);

        $response = $this->get('/insights');

        $response->assertOk();
        $response->assertSee('1 minute'); // Alice's sitting is hers.
        $response->assertDontSee('1 hour 30 minutes'); // Bob's sitting is invisible from her page.
    }

    public function test_a_series_the_lens_cannot_name_renders_by_id_rather_than_lying(): void
    {
        SeriesState::factory()->create([
            'user_id' => $this->alice->id,
            'suwayomi_manga_id' => 76,
            'in_library' => true,
        ]);

        $this->sitting($this->alice, 76, 60);
        $this->sitting($this->alice, 999, 60);

        $response = $this->get('/insights');

        $response->assertOk();
        $response->assertSee('Chainsaw Man'); // An id in the lens keeps its name.
        $response->assertSee('Series 999', false); // A bare id with no invented title.
    }

    public function test_the_unread_figure_rides_the_lens_the_library_uses(): void
    {
        SeriesState::factory()->create([
            'user_id' => $this->alice->id,
            'suwayomi_manga_id' => 76,
            'in_library' => true,
        ]);

        $this->sitting($this->alice, 76, 60, pages: 5);

        $this->get('/insights')
            ->assertOk()
            ->assertSee('still 210 chapters unread');
    }

    public function test_an_empty_ledger_says_so_and_says_what_would_change_it(): void
    {
        $this->get('/insights')
            ->assertOk()
            ->assertSee('No reading counted yet')
            // The local-only fact is said on the page, not left to be inferred
            // from the absence of a claim.
            ->assertSee('rows written on this machine');
    }

    public function test_a_window_the_url_cannot_clarify_falls_back_to_the_whole_ledger(): void
    {
        $this->sitting($this->alice, 76, 5000, daysAgo: 400);

        $this->get('/insights?since=not-a-date')
            ->assertOk()
            ->assertSee('1 hour');
    }

    public function test_a_future_date_is_refused_as_a_window_and_reads_as_the_whole_ledger(): void
    {
        $this->sitting($this->alice, 76, 5000, daysAgo: 400);

        $this->get('/insights?since=2099-01-01')
            ->assertOk()
            // A future date counts nothing today and can only mislead, so it is
            // refused rather than rendered, and the ledger shows.
            ->assertSee('1 hour');
    }
}
