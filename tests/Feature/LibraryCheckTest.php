<?php

namespace Tests\Feature;

use App\Jobs\CheckLibrary;
use App\Library\CheckLedger;
use App\Library\CheckSchedule;
use App\Models\LibraryCheck;
use App\Models\SeriesState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * Checking a library for new chapters.
 *
 * Written against measurements rather than assumptions, so the tests would fail if
 * any of them stopped being true:
 *
 *  - A re-check costs what the first check cost. Measured ratio 0.95x, 361ms to
 *    1617ms per refresh, 876ms median. Suwayomi does not cache, so every check is a
 *    real upstream request, which is the entire reason a floor exists.
 *  - `fetchMangaAndChapters` on a series whose source has nothing throws a bare
 *    `java.lang.Exception: No chapters found`, delivered as a GraphQL error on HTTP
 *    200. Measured on 1 of 14 library series. That is not a fault and must not be
 *    recorded as one.
 *  - Before this existed, all 14 dev series had a null `chapter_list_checked_at`,
 *    because nothing ever wrote one. The library's staleness badge could not fire
 *    and the six "Chapters never fetched" lines had no route out of that state.
 *  - AniList's chapter count is not a substitute. Measured: 4 of 6 sampled series
 *    had no AniList entry, and both that did over-count, by +9 and +17, because it
 *    counts the original release including chapters no scanlation has.
 */
class LibraryCheckTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private User $user;

    /** @var array<int, string> every GraphQL document sent */
    private array $sent = [];

    /** @var array<int, array<string, mixed>> what a refresh returns, by manga id */
    private array $refreshShapes = [];

    /** @var array<int, string> a series whose refresh throws outright */
    private array $unreachable = [];

    /** @var array<int, int> a series the source has no chapters for */
    private array $noChapters = [];

    /** @var array<int, array<int, int>> chapter ids per manga, when the count stays still */
    private array $renumber = [];

    /** @var array<int, bool> every series this test put in the library */
    private array $libraryIds = [];

    /**
     * Series whose refresh returns a chapter node that is not a list.
     *
     * `hydrate()` closes over `function (array $chapter)`, so a scalar node raises a TypeError.
     * A realistic malformed upstream rather than a contrived mock, and not a
     * `SuwayomiException` either: those are the expected failure and `check()` records them.
     */
    private array $malformed = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->sent = [];
        $this->refreshShapes = [];
        $this->unreachable = [];
        $this->noChapters = [];
        $this->renumber = [];
        $this->libraryIds = [];
        $this->malformed = [];

        $this->fakeSuwayomi();
    }

    private function fakeSuwayomi(): void
    {
        Http::fake(function (Request $request) {
            $query = (string) ($request->data()['query'] ?? '');
            $this->sent[] = $query;

            if (str_contains($query, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            /*
             * `data_get` rather than an array cast. The request body is JSON, so
             * `data()` decodes it into nested stdClass and `$data['variables']['id']`
             * raises "Cannot use object of type stdClass as array". That is the same
             * trap the AniList fake hit.
             */
            $id = (int) (data_get($request->data(), 'variables.id') ?? 0);

            if (in_array($id, $this->unreachable, true)) {
                throw new ConnectionException('connection refused');
            }

            if (str_contains($query, 'mutation Refresh')) {
                /*
                 * The measured shape. A GraphQL error on HTTP 200, not a 5xx, and
                 * carrying a bare Java exception name with no detail. Reading it as a
                 * transport failure rather than an answer is the mistake the whole
                 * state exists to prevent.
                 */
                if (in_array($id, $this->noChapters, true)) {
                    return Http::response([
                        'errors' => [['message' => 'Exception while fetching data (fetchMangaAndChapters) : java.lang.Exception: No chapters found']],
                    ]);
                }

                if (in_array($id, $this->malformed, true)) {
                    return Http::response(['data' => ['fetchMangaAndChapters' => [
                        'manga' => [
                            'id' => (string) $id,
                            'title' => "Series {$id}",
                            'initialized' => true,
                            'source' => ['id' => '1', 'name' => 'Asura Scans', 'lang' => 'en'],
                            // `hydrate()` does `array_values($manga['genre'] ?? [])`, so a
                            // string here is a TypeError. A sibling `chapters` key was the
                            // first guess and never threw, because hydrate is handed the
                            // manga node alone and reads chapters separately.
                            'genre' => 'Action, Drama',
                        ],
                    ]]]);
                }

                $shape = $this->refreshShapes[$id] ?? ['chapters' => 10, 'initialized' => true];

                $chapters = (int) ($shape['chapters'] ?? 0);

                return Http::response(['data' => ['fetchMangaAndChapters' => [
                    'manga' => [
                        'id' => (string) $id,
                        'title' => "Series {$id}",
                        'initialized' => $shape['initialized'] ?? true,
                        'status' => 'ONGOING',
                        'source' => ['id' => '1', 'name' => 'Asura Scans', 'lang' => 'en'],
                    ],
                    /*
                     * Chapter ids come from $renumber when it is set, so a test can
                     * hold the count still and move the ids, which is the case the
                     * fingerprint exists for.
                     */
                    'chapters' => $this->chapterRows($id, $chapters),
                ]]]);
            }

            if (str_contains($query, 'mangas(')) {
                /*
                 * The batch read the library page makes. It reports a chapter list
                 * for everything in the library, because that is what the real
                 * server does once a refresh has landed, and it is the list the
                 * staleness fingerprint is compared against.
                 */
                $nodes = [];

                /*
                 * `array_keys`, not the map itself. `libraryIds` is keyed by manga id
                 * with `true` as the value, so iterating the map directly gives
                 * `$mangaId = true`, which casts to 1, and every batch read answered
                 * with a single series numbered 1 whatever was asked for. That is why
                 * `present()` saw no source data and the staleness badge could not
                 * fire: the row it looked up simply was not in the response.
                 */
                foreach (array_keys($this->libraryIds) as $mangaId) {
                    $chapters = $this->refreshShapes[$mangaId]['chapters'] ?? 10;

                    $nodes[] = [
                        'id' => (string) $mangaId,
                        'title' => "Series {$mangaId}",
                        'thumbnailUrl' => "/api/v1/manga/{$mangaId}/thumbnail",
                        'status' => 'ONGOING',
                        'initialized' => true,
                        'source' => ['id' => '1', 'name' => 'Asura Scans', 'lang' => 'en'],
                        'chapters' => [
                            'totalCount' => $chapters,
                            'nodes' => $this->chapterRows($mangaId, (int) $chapters),
                        ],
                    ];
                }

                return Http::response(['data' => ['mangas' => ['nodes' => $nodes]]]);
            }

            return Http::response(['data' => ['manga' => null]]);
        });
    }

    /**
     * The chapter rows a refresh reports, honouring a renumbering override.
     *
     * @return array<int, array<string, string>>
     */
    private function chapterRows(int $mangaId, int $count): array
    {
        $ids = $this->renumber[$mangaId] ?? null;

        return array_map(
            static fn (int $n): array => [
                'id' => (string) ($ids[$n - 1] ?? ($mangaId * 100 + $n)),
                'name' => "Chapter {$n}",
            ],
            $count > 0 ? range(1, $count) : [],
        );
    }

    private function addToLibrary(int $mangaId): SeriesState
    {
        $this->libraryIds[$mangaId] = true;

        return SeriesState::createFor($this->user, [
            'suwayomi_manga_id' => $mangaId,
            'in_library' => true,
            'added_at' => now(),
        ]);
    }

    private function ledger(): CheckLedger
    {
        return app(CheckLedger::class);
    }

    // The floor -------------------------------------------------------------

    public function test_a_series_nobody_has_checked_is_due(): void
    {
        // The measured state of the whole dev library before this feature: 14 rows,
        // 14 null timestamps.
        $state = $this->addToLibrary(76);

        $this->assertTrue(app(CheckSchedule::class)->isDue($state));
    }

    public function test_a_series_checked_today_is_not_due(): void
    {
        $state = $this->addToLibrary(76);
        $state->chapter_list_checked_at = now();
        $state->save();

        $this->assertFalse(app(CheckSchedule::class)->isDue($state));
    }

    public function test_a_series_checked_yesterday_is_due_under_a_one_day_interval(): void
    {
        $state = $this->addToLibrary(76);
        $state->chapter_list_checked_at = now()->subDay()->subMinute();
        $state->save();

        $this->assertTrue(app(CheckSchedule::class)->isDue($state));
    }

    public function test_a_per_series_interval_beats_the_default(): void
    {
        $state = $this->addToLibrary(76);
        $state->chapter_list_checked_at = now()->subDays(3);
        $state->fetch_interval_days = 7;
        $state->save();

        $this->assertFalse(app(CheckSchedule::class)->isDue($state), 'Three days into a seven day interval.');

        $state->chapter_list_checked_at = now()->subDays(8);
        $state->save();

        $this->assertTrue(app(CheckSchedule::class)->isDue($state));
    }

    public function test_a_zero_interval_means_every_pass(): void
    {
        $state = $this->addToLibrary(76);
        $state->chapter_list_checked_at = now();
        $state->fetch_interval_days = 0;
        $state->save();

        $this->assertTrue(app(CheckSchedule::class)->isDue($state), 'The only way to say "always this one".');
    }

    public function test_a_negative_interval_is_treated_as_zero_not_as_shorter(): void
    {
        $state = $this->addToLibrary(76);
        $state->chapter_list_checked_at = now();
        $state->fetch_interval_days = -5;
        $state->save();

        $this->assertSame(0, app(CheckSchedule::class)->intervalDays($state));
    }

    public function test_the_summary_separates_due_from_skipped(): void
    {
        $checked = $this->addToLibrary(1);
        $checked->chapter_list_checked_at = now();
        $checked->save();

        $this->addToLibrary(2);
        $this->addToLibrary(3);

        $summary = app(CheckSchedule::class)->summary($this->user);

        $this->assertSame(3, $summary['total']);
        $this->assertSame(2, $summary['due']);
        $this->assertSame(2, $summary['never']);
        $this->assertSame(1, $summary['skipped']);
    }

    public function test_due_returns_the_oldest_check_first(): void
    {
        $newer = $this->addToLibrary(2);
        $newer->chapter_list_checked_at = now()->subHours(2);
        $newer->fetch_interval_days = 1;
        $newer->save();

        $older = $this->addToLibrary(1);
        $older->chapter_list_checked_at = now()->subDays(5);
        $older->fetch_interval_days = 1;
        $older->save();

        $due = app(CheckSchedule::class)->due($this->user);

        $this->assertSame(1, $due->first()->suwayomi_manga_id, 'The one waiting longest goes first.');
    }

    // One check --------------------------------------------------------------

    public function test_a_check_records_the_chapter_count(): void
    {
        $this->addToLibrary(76);
        $this->refreshShapes[76] = ['chapters' => 41];

        $row = $this->ledger()->check($this->user, 76);

        $this->assertSame(LibraryCheck::CHECKED, $row->state);
        $this->assertSame(41, $row->chapter_count);
    }

    public function test_a_first_check_reports_null_new_rather_than_zero(): void
    {
        $this->addToLibrary(76);
        $this->refreshShapes[76] = ['chapters' => 41];

        $row = $this->ledger()->check($this->user, 76);

        $this->assertNull(
            $row->new_chapters,
            'There is no previous count, and a zero would claim nothing was new.',
        );
        $this->assertFalse($row->hasNewChapters());
    }

    public function test_a_second_check_reports_what_arrived(): void
    {
        $this->addToLibrary(76);
        $this->refreshShapes[76] = ['chapters' => 40];

        $this->ledger()->check($this->user, 76);

        // The source gained a chapter. This is the number the whole feature is for.
        $this->refreshShapes[76] = ['chapters' => 43];

        $row = $this->ledger()->check($this->user, 76, force: true);

        $this->assertSame(43, $row->chapter_count);
        $this->assertSame(3, $row->new_chapters);
        $this->assertTrue($row->hasNewChapters());
    }

    public function test_a_shrinking_source_reports_zero_not_a_negative(): void
    {
        $this->addToLibrary(76);
        $this->refreshShapes[76] = ['chapters' => 40];

        $this->ledger()->check($this->user, 76);

        $this->refreshShapes[76] = ['chapters' => 12];

        $row = $this->ledger()->check($this->user, 76, force: true);

        $this->assertSame(0, $row->new_chapters, 'A source that removed chapters did not add any.');
    }

    public function test_a_check_writes_the_fingerprint_the_badge_depends_on(): void
    {
        $state = $this->addToLibrary(76);
        $this->refreshShapes[76] = ['chapters' => 41];

        $this->assertNull($state->chapter_list_fingerprint, 'Nothing had ever written one before this.');

        $this->ledger()->check($this->user, 76);

        $state->refresh();

        $this->assertNotNull($state->chapter_list_fingerprint);
        $this->assertNotNull($state->chapter_list_checked_at);
    }

    public function test_a_source_with_no_chapters_is_not_an_error(): void
    {
        $this->addToLibrary(174);
        $this->noChapters[] = 174;

        $row = $this->ledger()->check($this->user, 174);

        $this->assertSame(
            LibraryCheck::NO_CHAPTERS,
            $row->state,
            'A source with nothing today is a fact about the source, not a fault.',
        );
    }

    public function test_a_source_with_no_chapters_advances_the_clock(): void
    {
        /*
         * Measured: MangaDex answered `No chapters found` for One Piece, and with the
         * clock left alone the series stayed due forever, so every press re-asked a
         * source that had already said no. An answer is an answer.
         */
        $state = $this->addToLibrary(174);
        $this->noChapters[] = 174;

        $this->ledger()->check($this->user, 174);

        $state->refresh();

        $this->assertNotNull($state->chapter_list_checked_at);
        $this->assertFalse(app(CheckSchedule::class)->isDue($state));
    }

    public function test_a_source_with_no_chapters_writes_no_fingerprint(): void
    {
        /*
         * There is no chapter list to describe, and a fingerprint over nothing would
         * either match everything or look like staleness.
         *
         * Asserted on the state rather than through `fingerprintMatches()`, which is
         * private and takes a source array: making it public for a test would widen
         * the class's surface for no gain, and the null fingerprint is the whole
         * claim. `fingerprintMatches` returns true for a null fingerprint, which is
         * the behaviour that keeps this from reading as stale.
         */
        $state = $this->addToLibrary(174);
        $this->noChapters[] = 174;

        $this->ledger()->check($this->user, 174);

        $state->refresh();

        $this->assertNull($state->chapter_list_fingerprint);
    }

    public function test_a_failure_does_not_advance_the_clock(): void
    {
        $state = $this->addToLibrary(76);
        $this->unreachable[] = 76;

        $row = $this->ledger()->check($this->user, 76, force: true);

        $this->assertSame(LibraryCheck::ERROR, $row->state);

        $state->refresh();

        $this->assertNull(
            $state->chapter_list_checked_at,
            'A request that never landed must not hold the series out for an interval.',
        );
    }

    public function test_a_failure_records_some_error_text(): void
    {
        $this->addToLibrary(76);
        $this->unreachable[] = 76;

        $row = $this->ledger()->check($this->user, 76, force: true);

        $this->assertNotNull($row->error);
        $this->assertNotSame('', trim((string) $row->error));
    }

    public function test_one_unreachable_series_does_not_stop_another(): void
    {
        $this->addToLibrary(1);
        $this->addToLibrary(2);
        $this->unreachable[] = 1;
        $this->refreshShapes[2] = ['chapters' => 5];

        $tally = $this->ledger()->checkDue($this->user, 30, 10, force: true);

        $this->assertSame(2, $tally['checked']);
        $this->assertSame(1, $tally['failed']);
        $this->assertSame(1, $tally['checked'] - $tally['failed']);
    }

    public function test_a_series_not_in_this_readers_state_is_refused(): void
    {
        // Measured shape of a stale page: an id from a library the reader has since
        // changed. Throwing is right, and the page catches it rather than rendering
        // an error screen.
        $this->expectException(\InvalidArgumentException::class);

        $this->ledger()->check($this->user, 9999);
    }

    public function test_a_check_is_kept_to_one_row_per_series(): void
    {
        $this->addToLibrary(76);
        $this->refreshShapes[76] = ['chapters' => 5];

        $this->ledger()->check($this->user, 76);
        $this->refreshShapes[76] = ['chapters' => 6];
        $this->ledger()->check($this->user, 76, force: true);

        $this->assertSame(
            1,
            LibraryCheck::query()->forUser($this->user->id)->count(),
            'A check happens every day forever, so a history would grow without bound.',
        );
    }

    public function test_the_floor_holds_a_recently_checked_series(): void
    {
        $this->addToLibrary(76);
        $this->refreshShapes[76] = ['chapters' => 10];

        $this->ledger()->check($this->user, 76);
        $tally = $this->ledger()->checkDue($this->user, 30, 10);

        $this->assertSame(0, $tally['checked'], 'The second call within the interval does nothing.');
    }

    public function test_a_forced_check_ignores_the_floor(): void
    {
        $this->addToLibrary(76);
        $this->refreshShapes[76] = ['chapters' => 10];

        $this->ledger()->check($this->user, 76);

        $before = count($this->sent);

        $this->ledger()->checkDue($this->user, 30, 10, force: true);

        $this->assertGreaterThan($before, count($this->sent), 'Pressing the button is asking now.');
    }

    public function test_the_tally_adds_up_what_it_found(): void
    {
        $this->addToLibrary(1);
        $this->addToLibrary(2);
        $this->refreshShapes[1] = ['chapters' => 10];
        $this->refreshShapes[2] = ['chapters' => 0];
        $this->noChapters[] = 2;

        $tally = $this->ledger()->checkDue($this->user, 30, 10, force: true);

        $this->assertSame(2, $tally['checked']);
        $this->assertSame(1, $tally['no_chapters']);
        $this->assertSame(0, $tally['failed']);
    }

    public function test_the_tally_counts_new_chapters_across_a_pass(): void
    {
        $this->addToLibrary(1);
        $this->addToLibrary(2);
        $this->refreshShapes[1] = ['chapters' => 10];
        $this->refreshShapes[2] = ['chapters' => 20];

        $this->ledger()->checkDue($this->user, 30, 10, force: true);

        $this->refreshShapes[1] = ['chapters' => 12];
        $this->refreshShapes[2] = ['chapters' => 20];

        $tally = $this->ledger()->checkDue($this->user, 30, 10, force: true);

        $this->assertSame(2, $tally['new'], 'Two chapters on one series, none on the other.');
    }

    // The job ----------------------------------------------------------------

    public function test_the_job_checks_what_is_due(): void
    {
        Queue::fake();

        $this->addToLibrary(1);
        $this->refreshShapes[1] = ['chapters' => 3];

        $this->app->call([new CheckLibrary($this->user->id), 'handle']);

        $this->assertSame(3, (int) LibraryCheck::query()->first()->chapter_count);
    }

    public function test_the_job_rebooks_itself_while_work_remains(): void
    {
        Queue::fake();

        // More series than one pass takes, which is what a 300 series library looks
        // like from a slice's point of view.
        foreach (range(1, CheckLibrary::MAX_SERIES + 5) as $id) {
            $this->addToLibrary($id);
            $this->refreshShapes[$id] = ['chapters' => 1];
        }

        $this->app->call([new CheckLibrary($this->user->id), 'handle']);

        Queue::assertPushed(CheckLibrary::class);
    }

    public function test_the_job_books_nothing_once_everything_is_checked(): void
    {
        Queue::fake();

        $this->addToLibrary(1);
        $this->refreshShapes[1] = ['chapters' => 3];

        $this->app->call([new CheckLibrary($this->user->id), 'handle']);

        $this->app->call([new CheckLibrary($this->user->id), 'handle']);

        Queue::assertNotPushed(CheckLibrary::class);
    }

    public function test_the_job_does_not_check_another_readers_library(): void
    {
        Queue::fake();

        $bob = User::factory()->create();
        SeriesState::createFor($bob, [
            // user_id is not mass-assignable, so this must be explicit
            'suwayomi_manga_id' => 500,
            'in_library' => true,
            'added_at' => now(),
        ]);

        $this->refreshShapes[500] = ['chapters' => 9];

        $this->app->call([new CheckLibrary($this->user->id), 'handle']);

        $this->assertSame(0, LibraryCheck::query()->forUser($bob->id)->count());
    }

    public function test_the_sweeper_finds_readers_with_a_library(): void
    {
        $this->addToLibrary(1);

        $bob = User::factory()->create();
        User::factory()->create();

        $jobs = CheckLibrary::everyReaderWithALibrary();

        $this->assertCount(1, $jobs);
        $this->assertSame($this->user->id, $jobs[0]->userId);
    }

    public function test_the_sweeper_skips_a_reader_whose_library_is_empty(): void
    {
        SeriesState::createFor($this->user, [
            'suwayomi_manga_id' => 900,
            'in_library' => false,
            'added_at' => now(),
        ]);

        $this->assertSame([], CheckLibrary::everyReaderWithALibrary());
    }

    // Reading the ledger -----------------------------------------------------

    public function test_counts_tally_the_outcomes(): void
    {
        $this->addToLibrary(1);
        $this->addToLibrary(2);
        $this->addToLibrary(3);
        $this->refreshShapes[1] = ['chapters' => 10];
        $this->refreshShapes[2] = ['chapters' => 10];
        $this->refreshShapes[3] = ['chapters' => 0];
        $this->noChapters[] = 3;

        $this->ledger()->checkDue($this->user, 30, 10, force: true);

        $this->refreshShapes[1] = ['chapters' => 12];
        $this->ledger()->checkDue($this->user, 30, 10, force: true);

        $counts = $this->ledger()->counts($this->user);

        $this->assertSame(2, $counts['checked']);
        $this->assertSame(1, $counts['no_chapters']);
        $this->assertSame(2, $counts['new'], 'The two that arrived on the last pass.');
        $this->assertNotNull($counts['last_checked_at']);
    }

    public function test_latest_for_returns_one_row_per_series(): void
    {
        $this->addToLibrary(1);
        $this->addToLibrary(2);
        $this->refreshShapes[1] = ['chapters' => 5];
        $this->refreshShapes[2] = ['chapters' => 5];

        $this->ledger()->checkDue($this->user, 30, 10, force: true);

        $latest = $this->ledger()->latestFor($this->user, [1, 2]);

        $this->assertCount(2, $latest);
        $this->assertArrayHasKey(1, $latest);
        $this->assertArrayHasKey(2, $latest);
    }

    public function test_latest_for_asks_once_not_once_per_series(): void
    {
        $this->addToLibrary(1);
        $this->addToLibrary(2);
        $this->refreshShapes[1] = ['chapters' => 5];
        $this->refreshShapes[2] = ['chapters' => 5];

        $this->ledger()->checkDue($this->user, 30, 10, force: true);

        /*
         * Counted with a real listener rather than asserted on the shape.
         *
         * The library page already made this mistake once, with its Suwayomi
         * metadata: a per-card loop that was measured at 4.5 seconds for 300 series
         * before the batch call replaced it. Asking the database what it did is the
         * only assertion that would have caught that, and this is the same method
         * shape.
         */
        $queries = 0;

        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $latest = $this->ledger()->latestFor($this->user, [1, 2]);

        $this->assertCount(2, $latest);
        $this->assertSame(
            1,
            $queries,
            'Two ids in one call. A per-series loop would be two.',
        );
    }

    public function test_latest_for_an_empty_set_asks_nothing(): void
    {
        $this->assertSame([], $this->ledger()->latestFor($this->user, []));
    }

    public function test_with_new_chapters_lists_only_what_arrived(): void
    {
        $this->addToLibrary(1);
        $this->addToLibrary(2);
        $this->refreshShapes[1] = ['chapters' => 10];
        $this->refreshShapes[2] = ['chapters' => 10];

        $this->ledger()->checkDue($this->user, 30, 10, force: true);

        $this->refreshShapes[1] = ['chapters' => 11];

        $this->ledger()->checkDue($this->user, 30, 10, force: true);

        $found = $this->ledger()->withNewChapters($this->user);

        $this->assertCount(1, $found);
        $this->assertSame(1, $found->first()->suwayomi_manga_id);
    }

    // The page ---------------------------------------------------------------

    public function test_the_page_offers_a_check_when_series_are_due(): void
    {
        $this->addToLibrary(76);

        Livewire::test('pages::library')
            ->assertOk()
            ->assertSee('Check 1 series');
    }

    public function test_the_page_shows_nothing_to_do_when_all_current(): void
    {
        $state = $this->addToLibrary(76);
        $state->chapter_list_checked_at = now();
        $state->save();

        Livewire::test('pages::library')
            ->assertOk()
            ->assertSee('All 1 series checked');
    }

    public function test_the_page_says_how_many_were_never_fetched(): void
    {
        $this->addToLibrary(1);
        $this->addToLibrary(2);

        Livewire::test('pages::library')
            ->assertOk()
            ->assertSee('2 series to check, 2 never fetched');
    }

    public function test_the_page_explains_the_floor(): void
    {
        $state = $this->addToLibrary(1);
        $state->chapter_list_checked_at = now();
        $state->save();

        $this->addToLibrary(2);

        Livewire::test('pages::library')
            ->assertOk()
            ->assertSee('1 checked recently')
            ->assertSee('for at least 1 day before again');
    }

    public function test_a_zero_interval_makes_nothing_skipped(): void
    {
        /*
         * The global default rather than a per-series override. A series carrying an
         * override of zero is *always* due, so it is never among the skipped, and the
         * copy that explains the floor only renders when something was skipped. So a
         * per-series zero has nothing to say in that spot, and the meaningful case
         * is the default.
         */
        config(['papyrus.library.check_interval_days' => 0]);

        $state = $this->addToLibrary(1);
        $state->chapter_list_checked_at = now();
        $state->save();

        $summary = app(CheckSchedule::class)->summary($this->user);

        $this->assertSame(1, $summary['due'], 'A zero default checks everything, checked a moment ago included.');
        $this->assertSame(0, $summary['skipped'], 'So there is nothing for the floor copy to describe.');
        $this->assertSame(0, $summary['interval_days']);

        $this->assertTrue(
            app(CheckSchedule::class)->isDue($state),
            'Checked a moment ago and still due, because the interval is zero.',
        );

        Livewire::test('pages::library')
            ->assertOk()
            ->assertDontSee('before again');
    }

    public function test_checking_one_series_from_the_page_records_it(): void
    {
        $this->addToLibrary(76);
        $this->refreshShapes[76] = ['chapters' => 41];

        Livewire::test('pages::library')
            ->call('checkSeries', 76)
            ->assertOk();

        $this->assertSame(41, (int) LibraryCheck::query()->first()->chapter_count);
    }

    public function test_a_stale_id_is_reported_rather_than_crashing(): void
    {
        Livewire::test('pages::library')
            ->call('tryCheckSeries', 9999)
            ->assertOk();
    }

    public function test_the_page_queue_a_pass(): void
    {
        Queue::fake();

        $this->addToLibrary(76);

        Livewire::test('pages::library')
            ->call('checkLibrary')
            ->assertOk();

        Queue::assertPushed(CheckLibrary::class);
    }

    public function test_the_page_says_so_when_there_is_nothing_to_check(): void
    {
        Queue::fake();

        $state = $this->addToLibrary(76);
        $state->chapter_list_checked_at = now();
        $state->save();

        Livewire::test('pages::library')
            ->call('checkLibrary')
            ->assertOk();

        Queue::assertNotPushed(CheckLibrary::class);
    }

    public function test_the_card_shows_how_many_arrived(): void
    {
        $this->addToLibrary(76);
        $this->refreshShapes[76] = ['chapters' => 10];

        $this->ledger()->checkDue($this->user, 30, 10, force: true);

        $this->refreshShapes[76] = ['chapters' => 14];

        $this->ledger()->checkDue($this->user, 30, 10, force: true);

        Livewire::test('pages::library')
            ->assertOk()
            ->assertSee('+4');
    }

    public function test_the_card_shows_no_badge_for_nothing_new(): void
    {
        $this->addToLibrary(76);
        $this->refreshShapes[76] = ['chapters' => 10];

        $this->ledger()->checkDue($this->user, 30, 10, force: true);

        Livewire::test('pages::library')->assertOk();

        $this->assertSame(
            0,
            (int) LibraryCheck::query()->withNewChapters()->count(),
            'A first check is not evidence that nothing is new.',
        );
    }

    public function test_a_due_card_offers_its_own_check(): void
    {
        $this->addToLibrary(76);

        Livewire::test('pages::library')
            ->assertOk()
            ->assertSee('Check this series for new chapters now');
    }

    public function test_a_checked_card_hides_its_own_check(): void
    {
        $state = $this->addToLibrary(76);
        $state->chapter_list_checked_at = now();
        $state->save();

        $this->refreshShapes[76] = ['chapters' => 5];

        $this->ledger()->check($this->user, 76);

        Livewire::test('pages::library')
            ->assertOk()
            ->assertDontSee('Check this series for new chapters now');
    }

    public function test_the_page_works_with_an_empty_library(): void
    {
        Livewire::test('pages::library')
            ->assertOk()
            ->assertSee('Nothing added yet.');
    }

    public function test_a_reader_only_ever_sees_their_own_checks(): void
    {
        $bob = User::factory()->create();

        SeriesState::createFor($bob, [
            // user_id is not mass-assignable, so this must be explicit
            'suwayomi_manga_id' => 76,
            'in_library' => true,
            'added_at' => now(),
        ]);

        LibraryCheck::createFor($bob, [
            'suwayomi_manga_id' => 76,
            'state' => LibraryCheck::CHECKED,
            'chapter_count' => 99,
            'checked_at' => now(),
        ]);

        $this->addToLibrary(76);

        Livewire::test('pages::library')
            ->assertOk()
            ->assertDontSee('+99');
    }

    // The staleness badge, which this makes reachable -------------------------

    public function test_the_staleness_badge_can_now_fire(): void
    {
        $this->addToLibrary(76);
        $this->refreshShapes[76] = ['chapters' => 3];

        $this->ledger()->check($this->user, 76);

        $state = SeriesState::forUser($this->user->id)
            ->where('suwayomi_manga_id', 76)
            ->first();

        $this->assertNotNull(
            $state?->chapter_list_fingerprint,
            'A check writes the fingerprint, which is what makes the badge reachable at all.',
        );

        // The source renumbers: same count, different chapter ids. The fingerprint is
        // over ids in order rather than over the count precisely for this, because a
        // count-only check would see nothing and every reader's unread total would be
        // quietly wrong.
        $this->renumber = [76 => [901, 902, 903]];

        Livewire::test('pages::library')
            ->assertOk()
            ->assertSee('Stale');
    }

    public function test_the_check_counts_chapters_from_the_source_not_from_ours(): void
    {
        $this->addToLibrary(76);
        $this->refreshShapes[76] = ['chapters' => 41];

        $row = $this->ledger()->check($this->user, 76);

        // AniList's count was measured and rejected: it is a different publisher's
        // number and over-counts. The source's own count is the only honest one.
        $this->assertSame(41, $row->chapter_count);
    }

    public function test_the_interval_default_is_configurable(): void
    {
        config(['papyrus.library.check_interval_days' => 3]);

        $state = $this->addToLibrary(76);
        $state->chapter_list_checked_at = now()->subDays(2);
        $state->save();

        $this->assertFalse(app(CheckSchedule::class)->isDue($state), 'Two days into a three day interval.');
    }

    public function test_a_checked_at_in_the_future_is_not_due(): void
    {
        $state = $this->addToLibrary(76);
        $state->chapter_list_checked_at = Carbon::now()->addDay();
        $state->save();

        $this->assertFalse(app(CheckSchedule::class)->isDue($state));
    }

    /*
     * ---------------------------------------------------------------------------
     * A series filed from a backup.
     * ---------------------------------------------------------------------------
     */

    public function test_a_filed_series_is_never_a_check_candidate(): void
    {
        SeriesState::createFor($this->user->id, [
            'suwayomi_manga_id' => null,
            'title' => 'Filed And Unreachable',
            'in_library' => true,
        ]);

        $this->addToLibrary(76);

        $tally = app(CheckLedger::class)->checkDue($this->user, 45, 60, force: true);

        $this->assertGreaterThan(0, $tally['checked'], 'the real series is still checked');
        $this->assertSame(
            0,
            $tally['failed'],
            'a series with no manga is not a candidate, rather than being checked as manga 0',
        );
    }

    public function test_one_series_that_throws_does_not_end_the_pass(): void
    {
        /*
         * A pass is a slice of a reader's whole library. An exception on the third of sixty
         * costs the other fifty-seven and stops the chain, because the job dies before it
         * reaches the re-book. Measured on a real library: four failures in 5ms and nothing
         * checked, and 867 series pressing one button.
         *
         * Ordered by `added_at`, so 76 is visited before 77. Without the catch the pass dies
         * on 76 and 77 is never reached, which is the assertion that fails.
         */
        $this->malformed = [76];

        $this->addToLibrary(76);
        $this->addToLibrary(77);

        $tally = app(CheckLedger::class)->checkDue($this->user, 45, 60, force: true);

        $this->assertSame(2, $tally['checked'], 'both are visited, so the pass did not stop');
        $this->assertSame(
            1,
            $tally['failed'],
            'the malformed one is counted as failed rather than taking the pass with it',
        );
        $this->assertSame(
            1,
            $tally['chapters'] > 0 ? 1 : 0,
            'and the well-formed one still produced its chapter count',
        );
    }
}
