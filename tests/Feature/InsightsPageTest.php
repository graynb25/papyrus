<?php

namespace Tests\Feature;

use App\Models\ImportedReadingDay;
use App\Models\ReadingSession;
use App\Models\SeriesState;
use App\Models\User;
use App\Support\ImageUrl;
use App\Support\Library;
use App\Suwayomi\SuwayomiException;
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

    /**
     * A second lens node a test can press in when the claim needs a series
     * whose shape the first node cannot carry. Null by default: the fake then
     * answers one series, as most tests took it.
     */
    private ?array $extraNode = null;

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
                $nodes = [
                    [
                        'id' => '76',
                        'title' => 'Chainsaw Man',
                        'status' => 'ONGOING',
                        'initialized' => true,
                        'source' => ['id' => '1', 'name' => 'MangaFire', 'lang' => 'EN'],
                        'chapters' => ['totalCount' => 210, 'nodes' => []],
                    ],
                ];

                if ($this->extraNode !== null) {
                    $nodes[] = $this->extraNode;
                }

                return Http::response(['data' => ['mangas' => ['nodes' => $nodes]]]);
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

    /**
     * One imported day, keyed by a file so two exports can be told apart.
     *
     * The default key is 64 characters because that is the column's width and the
     * shape the importer produces from a sha256.
     */
    private function imported(User $who, int $manga, int $seconds, int $daysAgo = 0, string $backup = 'a'): ImportedReadingDay
    {
        return ImportedReadingDay::createFor($who, [
            'backup_key' => str_pad($backup, 64, '0'),
            'suwayomi_manga_id' => $manga,
            'day' => now()->subDays($daysAgo)->toDateString(),
            'seconds' => $seconds,
        ]);
    }

    // ---------------------------------------------------------------- the page

    public function test_the_page_reads_its_numbers_from_the_ledger_and_writes_nothing(): void
    {
        $this->sitting($this->alice, 76, 90, pages: 40);

        $response = $this->get('/insights');

        $response->assertOk();
        $response->assertSee('1 minute 30 seconds'); // Ninety seconds keeps the thirty that remain.
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
            ->assertSee('1 minute 30 seconds')
            ->assertDontSee('1 hour 23 minutes 20 seconds');
    }

    public function test_a_window_with_no_rows_in_it_says_so(): void
    {
        $this->sitting($this->alice, 76, 5000, daysAgo: 400);

        $this->get('/insights?since='.now()->toDateString())
            ->assertOk()
            ->assertSee('No reading recorded yet');
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
            ->assertSee('1 hour 24 minutes 50 seconds')
            ->assertSee('2 sittings');
    }

    public function test_rows_are_never_shared_between_readers(): void
    {
        $bob = User::factory()->create();

        $this->sitting($this->alice, 76, 90);
        $this->sitting($bob, 76, 5400);

        $response = $this->get('/insights');

        $response->assertOk();
        $response->assertSee('1 minute 30 seconds'); // Alice's sitting is hers.
        $response->assertDontSee('1 hour 30 minutes'); // Bob's sitting is invisible from her page.
    }

    // ------------------------------------------------------------- imported time

    public function test_imported_reading_time_is_counted_and_says_where_it_came_from(): void
    {
        $this->sitting($this->alice, 76, 60);
        $this->imported($this->alice, 76, 120);

        $this->get('/insights')
            ->assertOk()
            ->assertSee('3 minutes') // Ninety seconds here plus two imported minutes.
            ->assertSee('Includes 2 minutes from imported backups')
            ->assertSee('2 minutes from an imported backup');
    }

    public function test_a_window_counts_imported_days_from_inside_it(): void
    {
        $this->imported($this->alice, 76, 90);
        $this->imported($this->alice, 76, 5000, daysAgo: 400);

        $this->get('/insights?since='.now()->toDateString())
            ->assertOk()
            ->assertSee('1 minute 30 seconds')
            ->assertDontSee('1 hour 23 minutes 20 seconds');
    }

    public function test_imported_time_is_never_shared_between_readers(): void
    {
        $bob = User::factory()->create();

        $this->imported($this->alice, 76, 60);
        $this->imported($bob, 76, 5400);

        $this->get('/insights')
            ->assertOk()
            ->assertSee('1 minute')
            ->assertDontSee('1 hour 30 minutes');
    }

    public function test_two_backups_that_carry_the_same_day_count_it_once(): void
    {
        /*
         * February's export contains January's days. The later file is not a second
         * January: the ledger keeps both files so neither import destroys the other,
         * and the page takes the largest claim for a series and day. Summing 120 and
         * 300 would report seven minutes of reading where five happened.
         */
        $this->imported($this->alice, 76, 120, backup: 'a');
        $this->imported($this->alice, 76, 300, backup: 'b');

        $this->get('/insights')
            ->assertOk()
            ->assertSee('5 minutes')
            ->assertDontSee('7 minutes');
    }

    public function test_a_series_with_only_imported_time_does_not_claim_sittings_it_never_had(): void
    {
        $this->imported($this->alice, 999, 3600);

        $this->get('/insights')
            ->assertOk()
            ->assertSee('1 hour')
            ->assertSee('1 hour from an imported backup')
            ->assertDontSee('0 pages')
            ->assertDontSee('0 sittings');
    }

    public function test_a_named_row_pictures_the_series_the_lens_already_carries(): void
    {
        SeriesState::factory()->create([
            'user_id' => $this->alice->id,
            'suwayomi_manga_id' => 76,
            'in_library' => true,
        ]);

        $this->sitting($this->alice, 76, 60);

        $this->get('/insights')
            ->assertOk()
            ->assertSee('Chainsaw Man')
            /*
             * The cover rides the same entry that named the row, so a named row
             * and its picture cannot disagree about what the server knows.
             */
            ->assertSee(ImageUrl::thumbnail(76));
    }

    public function test_a_series_the_lens_knows_without_a_name_still_earns_its_true_cover(): void
    {
        /*
         * Library can know a manga and have no name for it: nodes arrive
         * without a title, and the row fallback is a blank string, which is
         * that same no-name. The page answers with the honest "Series :id"
         * rather than rendering an empty link, and the cover the server knows
         * is still the true one, so the row carries it.
         */
        $this->extraNode = [
            'id' => '63',
            'status' => 'ONGOING',
            'initialized' => true,
            'source' => ['id' => '1', 'name' => 'MangaFire', 'lang' => 'EN'],
            'chapters' => ['totalCount' => 97, 'nodes' => []],
        ];

        SeriesState::factory()->create([
            'user_id' => $this->alice->id,
            'suwayomi_manga_id' => 63,
            'in_library' => true,
        ]);

        $this->sitting($this->alice, 63, 60);

        $this->get('/insights')
            ->assertOk()
            ->assertSee('Series 63', false)
            ->assertSee(ImageUrl::thumbnail(63));
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

        /*
         * The row the lens cannot name pictures nothing rather than lending a
         * cover from nothing: the atom's own placeholder answers, and no cover
         * link is invented for a manga the server does not know.
         */
        $response->assertSee('cover-placeholder', false);
        $response->assertDontSee(ImageUrl::thumbnail(999));
    }

    public function test_a_lens_that_throws_leaves_the_rows_without_names_without_losing_a_beat(): void
    {
        /*
         * The lens is the one piece of this page that can fail out, so its
         * throw is pushed straight here: the Library catch that swallows a
         * stalled server is that class's own record, and what this page owns
         * is the shape it answers with. The ledger's numbers stay, the names
         * and covers go, and the honest id rows hold with no 500 anywhere.
         */
        $this->swap(Library::class, new class
        {
            public function entries(): Collection
            {
                throw new SuwayomiException('The lens is down for this walk.');
            }
        });

        $this->sitting($this->alice, 76, 60);

        $this->get('/insights')
            ->assertOk()
            ->assertSee('Series 76', false)
            ->assertSee('cover-placeholder', false);
    }

    public function test_the_share_bar_says_nothing_the_minutes_have_not_already_said(): void
    {
        SeriesState::factory()->create([
            'user_id' => $this->alice->id,
            'suwayomi_manga_id' => 76,
            'in_library' => true,
        ]);

        $this->sitting($this->alice, 76, 60);

        $response = $this->get('/insights');
        $response->assertOk();
        $response->assertSee('1 minute');

        /*
         * The bar's width is a picture the badge's words already carry, so it
         * announces nothing: no second role for the ear and no second phrasing
         * of the same number to keep in step.
         */
        $response->assertDontSee('of reading', false);
        $response->assertSee('bg-zinc-200 dark:bg-white/10" aria-hidden="true">', false);
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
            ->assertSee('No reading recorded yet')
            // The local-only fact is said on the page, not left to be inferred
            // from the absence of a claim. Imported time is named in the same breath
            // so a reader knows the page can count more than this machine saw.
            ->assertSee('These numbers come from reading recorded on this machine, plus reading time carried over from backups you imported.')
            ->assertSee('Papyrus does not send or request anything to create them.');
    }

    public function test_a_window_the_url_cannot_clarify_falls_back_to_the_whole_ledger(): void
    {
        $this->sitting($this->alice, 76, 5000, daysAgo: 400);

        $this->get('/insights?since=not-a-date')
            ->assertOk()
            ->assertSee('1 hour');
    }

    public function test_rows_render_largest_first_with_cover_and_bar(): void
    {
        $this->extraNode = [
            'id' => '77',
            'title' => 'Berserk',
            'status' => 'ONGOING',
            'initialized' => true,
            'source' => ['id' => '1', 'name' => 'MangaFire', 'lang' => 'EN'],
            'chapters' => ['totalCount' => 100, 'nodes' => []],
        ];

        SeriesState::factory()->create([
            'user_id' => $this->alice->id,
            'suwayomi_manga_id' => 76,
            'in_library' => true,
        ]);
        SeriesState::factory()->create([
            'user_id' => $this->alice->id,
            'suwayomi_manga_id' => 77,
            'in_library' => true,
        ]);

        $this->sitting($this->alice, 76, 60);
        $this->sitting($this->alice, 77, 3600);

        $html = $this->get('/insights')->assertOk()->getContent();

        $first = strpos($html, 'data-manga="77"');
        $second = strpos($html, 'data-manga="76"');

        $this->assertNotFalse($first);
        $this->assertNotFalse($second);
        $this->assertLessThan($second, $first, 'The hour of Berserk renders before the minute of Chainsaw Man.');

        $this->assertStringContainsString('bg-vermilion-500', $html);
        $this->assertStringContainsString(ImageUrl::thumbnail(77), $html);
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
