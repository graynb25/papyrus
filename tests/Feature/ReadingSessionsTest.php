<?php

namespace Tests\Feature;

use App\Models\ReadingSession;
use App\Models\Source;
use App\Models\User;
use App\Support\Incognito;
use App\Support\ReadingSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * The reading-session ledger, from the reader's side of it.
 *
 * ## What is pinned here
 *
 * Chapter open makes a row; a ping extends it by no more than the idle cap in
 * seconds the server measured itself; a second open closes the first at its
 * last stamp, which is what makes a reload unable to pile the same minutes onto
 * two rows; and an incognito source leaves the table empty of anything this
 * reader did on it.
 *
 * ## Why the open and ping claims go through routes
 *
 * IncognitoTest recorded the reason and it holds here for the same reason: a
 * test that calls the service and asserts a row exists can pass forever while
 * the controller never learned the service's name. So every wiring claim below
 * arrives the way a reader's browser arrives, and only the cap and the page
 * delta are asserted service side, where the numbers are small on purpose.
 *
 * ## The fake is copied from the server, not tidied
 *
 * Same reason IncognitoTest's fake is: source ids arrive as strings past 2^53,
 * and a small integer in a fixture would exercise a different path than
 * production (trap 131). The GraphQL shapes below are that file's shapes.
 */
class ReadingSessionsTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private const ASURA = '6247824327199706550';

    /**
     * What the fake server says about the one series this fixture knows.
     */
    private ?array $knownSeries = null;

    private User $alice;

    protected function setUp(): void
    {
        parent::setUp();

        Source::query()->create([
            'suwayomi_source_id' => self::ASURA,
            'name' => 'Asura Scans (EN)',
            'display_name' => 'Asura Scans (EN)',
            // Not nullable, and named after the Keiyoushi package rather than the site, which
            // is why a source row cannot be written without it even in a test.
            'extension_pkg_name' => 'tachiyomi-en.asurascans',
            'home_url' => 'https://asurascans.com',
            'lang' => 'en',
            'is_installed' => true,
        ]);

        /*
         * The manga-to-source cache is shared state, and a test that leaves a mapping behind
         * decides what the next test believes about which source a series is on.
         */
        Cache::flush();

        $this->alice = User::factory()->create();
        $this->actingAs($this->alice);

        $this->fakeSuwayomi();
    }

    /**
     * One fake for the whole read path.
     *
     * Http::fake() called twice does not replace the first registration, so the
     * fake installed in setUp answers every case; a test stubbing a second one
     * would be writing a test that does not run (trap 116).
     */
    private function fakeSuwayomi(): void
    {
        $this->knownSeries = [
            'id' => '76',
            'title' => 'The Berserker\'s Second Playthrough',
            'url' => '/series/x',
            'realUrl' => 'https://asurascans.com/comics/x',
            'thumbnailUrl' => '/api/v1/manga/76/thumbnail',
            'status' => 'ONGOING',
            'author' => 'Life Ruined',
            'artist' => 'Isang',
            'genre' => ['Manhwa'],
            'description' => 'A description.',
            'initialized' => true,
            'unreadCount' => 3,
            'updateStrategy' => 'ALWAYS_UPDATE',
            'chaptersLastFetchedAt' => '1790776498',
            'source' => [
                'id' => self::ASURA,
                'name' => 'Asura Scans',
                'lang' => 'en',
                'contentWarning' => 'SAFE',
            ],
            'chapters' => [
                'totalCount' => 2,
                'nodes' => [
                    ['id' => '1', 'name' => 'Chapter 1', 'chapterNumber' => 1.0, 'scanlator' => null, 'uploadDate' => '1767000000000', 'pageCount' => -1, 'isRead' => false, 'lastPageRead' => 0, 'sourceOrder' => 1],
                    ['id' => '2', 'name' => 'Chapter 2', 'chapterNumber' => 2.0, 'scanlator' => null, 'uploadDate' => '1767100000000', 'pageCount' => -1, 'isRead' => false, 'lastPageRead' => 0, 'sourceOrder' => 2],
                ],
            ],
        ];

        Http::fake(function (Request $request) {
            $body = (string) $request->body();

            if (str_contains($body, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            if (str_contains($body, 'fetchChapterPages')) {
                return Http::response(['data' => ['fetchChapterPages' => [
                    'chapter' => ['id' => '1', 'name' => 'Chapter 1', 'pageCount' => 3],
                    'pages' => [
                        '/api/v1/manga/76/chapter/1/page/0',
                        '/api/v1/manga/76/chapter/1/page/1',
                        '/api/v1/manga/76/chapter/1/page/2',
                    ],
                ]]]);
            }

            if (str_contains($body, 'query Series')) {
                return Http::response(['data' => ['manga' => $this->knownSeries]]);
            }

            return Http::response(['data' => []]);
        });
    }

    private function goIncognito(): void
    {
        Incognito::setFor($this->alice->id, [self::ASURA], true);

        // Not strictly needed because the mapping caches the source id rather than the
        // preference, but a test that depends on cache state surviving setUp fails for
        // the wrong reason.
        Cache::flush();
    }

    /**
     * Move every open row's stamp this far into the past, so the next accrual
     * has an interval to measure against instead of a wall clock a test cannot
     * steer.
     */
    private function backdateLastStamp(int $seconds): void
    {
        ReadingSession::forAllUsers()->whereNull('ended_at')->update([
            'last_active_at' => now()->subSeconds($seconds),
        ]);
    }

    // ------------------------------------------------------------------ the open

    public function test_opening_a_chapter_makes_a_session_row(): void
    {
        $this->get('/read/76/1')->assertOk();

        $row = ReadingSession::forUser($this->alice)->where('suwayomi_chapter_id', 1)->sole();

        $this->assertSame(76, $row->suwayomi_manga_id);
        $this->assertNull($row->ended_at, 'An open row is the sitting that is happening.');
        $this->assertSame(0, $row->read_seconds, 'Opening contributes nothing by itself.');
        $this->assertSame(0, $row->pages_turned);
    }

    public function test_a_second_open_closes_the_first_at_its_last_stamp(): void
    {
        $this->get('/read/76/1')->assertOk();

        $this->travel(5)->minutes();
        $this->get('/read/76/2')->assertOk();

        $rows = ReadingSession::forUser($this->alice)->orderBy('id')->get();

        $this->assertCount(2, $rows, 'One row per chapter open, never a shared lifetime row.');

        $first = $rows[0];
        $second = $rows[1];

        $this->assertSame(1, $first->suwayomi_chapter_id);
        $this->assertSame(2, $second->suwayomi_chapter_id);

        $this->assertNotNull($first->ended_at, 'The older row is closed, not left open behind the reader.');
        $this->assertTrue(
            $first->ended_at->equalTo($first->last_active_at),
            'Closed at the moment it stopped being read, not at the moment something else was opened.',
        );
        $this->assertNull($second->ended_at);
        $this->assertSame(0, $first->read_seconds, 'Five minutes idle are five minutes of nothing.');
    }

    public function test_resuming_a_chapter_makes_a_fresh_row_which_starts_from_nothing(): void
    {
        $this->get('/read/76/1')->assertOk();

        $this->backdateLastStamp(300);
        $this->ping(1)->assertOk();

        $first = ReadingSession::forUser($this->alice)->where('suwayomi_chapter_id', 1)->sole();
        $this->assertSame(ReadingSessions::IDLE_CAP_SECONDS, $first->read_seconds);

        $this->get('/read/76/1')->assertOk();

        $rows = ReadingSession::forUser($this->alice)->orderBy('id')->get();

        $this->assertCount(2, $rows, 'The resumed chapter opens its own row.');
        $this->assertSame(
            $first->read_seconds,
            $rows[0]->read_seconds,
            'The old row keeps the seconds it was given and not one more.',
        );
        $this->assertSame(0, $rows[1]->read_seconds, 'The new row starts from nothing, so a reload cannot double count a sitting.');
        $this->assertNull($rows[1]->last_page_seen);
    }

    public function test_a_new_open_never_rewrites_a_row_that_ended_long_ago(): void
    {
        // The close before every open exists for the still-open. A close that
        // also claimed ended rows would re-stamp their ending to whenever a
        // newer chapter happened to be opened, so this row deliberately ends
        // two hours after its final stamp: the one shape a re-close would move.
        $row = ReadingSession::factory()->create([
            'user_id' => $this->alice->id,
            'suwayomi_manga_id' => 76,
            'suwayomi_chapter_id' => 1,
            'started_at' => now()->subDays(3),
            'last_active_at' => now()->subDays(3)->addHour(),
            'ended_at' => now()->subDays(3)->addHours(2),
            'read_seconds' => 3600,
        ]);

        app(ReadingSessions::class)->openFor($this->alice, 76, 2);

        $row->refresh();

        $this->assertEqualsWithDelta(
            now()->subDays(3)->addHours(2)->timestamp,
            $row->ended_at->timestamp,
            2,
            'An ended row keeps the moment it ended; nothing about today\'s open belongs to it.',
        );
    }

    // ----------------------------------------------------------------- the ping

    public function test_a_ping_accrues_elapsed_seconds_but_never_past_the_cap(): void
    {
        $this->get('/read/76/1')->assertOk();

        $this->backdateLastStamp(900);

        $this->ping(1)->assertOk();

        $row = ReadingSession::forUser($this->alice)->sole();

        $this->assertSame(ReadingSessions::IDLE_CAP_SECONDS, $row->read_seconds, 'Fifteen minutes of a missing tab is not reading, whatever else it is.');
        $this->assertSame(0, $row->pages_turned, 'The first stamp of a row sets the base position and turns no pages.');
        $this->assertSame(1, $row->last_page_seen);
    }

    public function test_pages_are_the_positive_movements_between_stamps(): void
    {
        $this->get('/read/76/1')->assertOk();

        $this->ping(5)->assertOk();
        $this->ping(9)->assertOk();

        $row = ReadingSession::forUser($this->alice)->sole();

        $this->assertSame(4, $row->pages_turned, 'Nine reaching past five is four pages, not nine.');

        $this->ping(6)->assertOk();
        $this->ping(9)->assertOk();

        $row->refresh();

        $this->assertSame(4, $row->pages_turned, 'Backwards and repeats move nothing.');
        $this->assertSame(9, $row->last_page_seen);
    }

    public function test_a_ping_for_no_open_row_writes_nothing(): void
    {
        $this->ping(3)->assertOk();

        $this->assertSame(0, ReadingSession::query()->count(), 'A ping without a sitting has nothing to attribute to.');
    }

    public function test_a_straggler_ping_from_a_closed_view_accrues_to_nothing(): void
    {
        $this->get('/read/76/1')->assertOk();
        $this->travel(1)->minutes();
        $this->get('/read/76/2')->assertOk();

        $oldRow = ReadingSession::forUser($this->alice)->where('suwayomi_chapter_id', 1)->sole();
        $secondsBefore = $oldRow->read_seconds;
        $pagesBefore = $oldRow->pages_turned;

        // The browser of a tab the reader left behind. Its ping is real, but the
        // row it would have extended was closed by a newer open, and the newer
        // row is a different chapter.
        $this->post('/read/76/1/progress', ['page' => 3, 'total' => 9])->assertOk();

        $oldRow->refresh();

        $this->assertSame($secondsBefore, $oldRow->read_seconds, 'A straggler belongs to no row, not to the next open.');
        $this->assertSame($pagesBefore, $oldRow->pages_turned, 'And its pages are the closed chapter\'s, not the next chapter\'s.');
    }

    // --------------------------------------------------------------- incognito

    public function test_an_incognito_source_writes_no_session_rows(): void
    {
        $this->goIncognito();

        $this->get('/read/76/1')->assertOk();
        $this->ping(2)->assertOk();

        $this->assertSame(0, ReadingSession::query()->count(), 'The ledger is the most personal of the records, so it is the most guarded.');
    }

    public function test_the_open_seam_itself_suppresses_an_incognito_source(): void
    {
        // The reader's controller happens to place its own incognito branch
        // around the open, and that is the wiring's business. The standing
        // refusal is the seam's: whatever call site reaches openFor next, this
        // guard is the thing they inherit, so it is tested without a route.
        $this->goIncognito();

        app(ReadingSessions::class)->openFor($this->alice, 76, 9);

        $this->assertSame(
            0,
            ReadingSession::query()->count(),
            'The seam must refuse an incognito source on its own, whatever its caller does.',
        );
    }

    public function test_pings_stop_the_moment_incognito_turns_on(): void
    {
        // A sitting that began recorded, then the reader flips the setting in the
        // health drill-down and keeps scrolling. The row already exists; the
        // stamps after the switch must not extend it, because every second the
        // guard lets through is history on a source this reader said not to record.
        app(ReadingSessions::class)->openFor($this->alice, 76, 1);

        $this->backdateLastStamp(300);

        Incognito::setFor($this->alice->id, [self::ASURA], true);
        Cache::flush();

        app(ReadingSessions::class)->pingFor($this->alice, 76, 1, 5);

        $row = ReadingSession::forUser($this->alice)->sole();

        $this->assertSame(0, $row->read_seconds, 'The row held nothing because nothing was stamped after the switch.');
        $this->assertNull($row->last_page_seen, 'Nor did it take the page the stamp carried.');
    }

    // ----------------------------------------------------------------- tenancy

    public function test_a_row_is_invisible_to_every_other_reader_and_unreadable_with_no_reader_at_all(): void
    {
        $this->get('/read/76/1')->assertOk();

        $this->actingAs(User::factory()->create());
        $this->assertSame(0, ReadingSession::query()->count(), 'Another reader reading the same series is a different sitting.');

        auth()->logout();
        $this->assertSame(0, ReadingSession::query()->count(), 'No reader means no view: the scope fails closed.');
    }

    // ------------------------------------------------------------------ helpers

    private function ping(int $page): TestResponse
    {
        return $this->post('/read/76/1/progress', ['page' => $page, 'total' => 9]);
    }
}
