<?php

namespace Tests\Feature\Library;

use App\Live\Library\LibraryFilter;
use App\Live\Library\LibraryPageData;
use App\Models\CheckRun;
use App\Models\LibraryCheck;
use App\Models\SeriesState;
use App\Models\User;
use App\Support\Library;
use App\Support\ReadingState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * The library page's reads, exercised without mounting the page.
 *
 * These cover the shapes the page depends on: last read then date added order, the
 * reader's shelf order and counts, one filings map for the whole library, the sixty
 * entry page, unread and continue-feed arithmetic, the one visible check run and its
 * progress, the schedule summary, the latest check per series, and the empty states.
 * The visible sentences stay in LibraryTest, LibraryBulkTest and LibraryCheckTest.
 */
class LibraryPageDataTest extends TestCase
{
    use MintsTokens;
    use RefreshDatabase;

    private User $user;

    /**
     * Manga ids the fake server knows, with their shape.
     *
     * @var array<int, array{chapters: int, initialized: bool}>
     */
    private array $known = [];

    /** When true, everything but the login fails, as a dead upstream would. */
    private bool $serverDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->known = [];
        $this->serverDown = false;

        $this->fakeSuwayomi();
    }

    private function data(): LibraryPageData
    {
        return app(LibraryPageData::class);
    }

    private function filter(): LibraryFilter
    {
        return app(LibraryFilter::class);
    }

    // Empty states ---------------------------------------------------------

    public function test_every_read_is_empty_before_a_library_exists(): void
    {
        $data = $this->data();
        $entries = $data->entries();
        $checks = $data->checks($entries);

        $this->assertTrue($entries->isEmpty());
        $this->assertTrue($data->shelves()->isEmpty());
        $this->assertSame([], $data->filings($entries));
        $this->assertNull($data->sourceFailure($entries));
        $this->assertSame(0, $data->totalAll($entries));
        $this->assertSame(0, $data->totalFiltered($entries));
        $this->assertSame(1, $data->lastPage(0));
        $this->assertSame(0, $data->totalUnread($entries));
        $this->assertTrue($data->continueFeed($entries)->isEmpty());
        $this->assertFalse($data->hasContinueFeed($entries));
        $this->assertNull($data->checkRun());
        $this->assertNull($data->checkProgress(null));
        $this->assertSame(0, $data->checkSchedule()['total']);
        $this->assertSame([], $checks);
        $this->assertNull($data->newChapters($checks, 1));
        $this->assertFalse($data->isDue(1));
        $this->assertSame([], $data->filedShelves($data->shelves(), [], 1));
        $this->assertSame([], $data->unfiledShelves($data->shelves(), [], 1));
        $this->assertSame('Shelf', $data->shelfName($data->shelves(), 1));
    }

    // Entries and shelves --------------------------------------------------

    public function test_entries_keep_the_pages_last_read_then_added_order(): void
    {
        $this->addToLibrary(1, addedAt: CarbonImmutable::parse('2026-01-01'), lastReadAt: CarbonImmutable::parse('2026-01-03'));
        $this->addToLibrary(2, addedAt: CarbonImmutable::parse('2026-01-02'), lastReadAt: CarbonImmutable::parse('2026-01-04'));
        $this->addToLibrary(3, addedAt: CarbonImmutable::parse('2026-01-03'));

        $this->assertSame(
            [2, 1, 3],
            $this->data()->entries()->pluck('manga_id')->all(),
            'last read first, then date added, and a never opened series after both',
        );
    }

    public function test_shelves_keep_the_readers_own_order_and_counts(): void
    {
        $one = app(Library::class)->createCategory($this->user, 'One');
        $two = app(Library::class)->createCategory($this->user, 'Two');

        $this->addToLibrary(1);
        $this->addToLibrary(2);

        app(Library::class)->attach($this->user, 1, $one->id);
        app(Library::class)->attach($this->user, 2, $one->id);
        app(Library::class)->attach($this->user, 1, $two->id);

        $shelves = $this->data()->shelves();

        $this->assertSame(['One', 'Two'], $shelves->pluck('name')->all());
        $this->assertSame([2, 1], $shelves->pluck('count')->all());
    }

    public function test_filings_are_keyed_by_manga_id_in_shelf_order(): void
    {
        $one = app(Library::class)->createCategory($this->user, 'One');
        $two = app(Library::class)->createCategory($this->user, 'Two');

        $this->addToLibrary(1);
        $this->addToLibrary(2);

        app(Library::class)->attach($this->user, 1, $two->id);
        app(Library::class)->attach($this->user, 1, $one->id);
        app(Library::class)->attach($this->user, 2, $one->id);

        $this->assertSame(
            [1 => [$one->id, $two->id], 2 => [$one->id]],
            $this->data()->filings($this->data()->entries()),
        );
    }

    // Paging, unread and the feed ----------------------------------------

    public function test_paging_counts_sixty_entries_per_page(): void
    {
        $data = $this->data();

        $this->assertSame(1, $data->lastPage(0));
        $this->assertSame(1, $data->lastPage(60));
        $this->assertSame(2, $data->lastPage(61));
        $this->assertSame(2, $data->lastPage(70));

        for ($id = 1; $id <= 70; $id++) {
            $this->addToLibrary($id);
        }

        $entries = $data->entries();
        $matching = $this->filter()->matching($entries, [], null, '', 'recent');

        $this->assertSame(70, $data->totalAll($entries));
        $this->assertSame(70, $data->totalFiltered($matching));
        $this->assertCount(60, $this->filter()->page($matching, 1));
        $this->assertCount(10, $this->filter()->page($matching, 2));
    }

    public function test_total_unread_sums_only_the_counts_the_source_can_answer(): void
    {
        $this->addToLibrary(1, chapters: 10);
        $this->addToLibrary(2, chapters: 4);
        $this->addToLibrary(3, chapters: 0, initialized: false);

        $reading = app(ReadingState::class);

        foreach ([101, 102, 103] as $chapterId) {
            $reading->markRead($this->user, 1, $chapterId, true);
        }

        $this->assertSame(11, $this->data()->totalUnread($this->data()->entries()));
    }

    public function test_continue_feed_uses_matching_entries_and_stops_at_five(): void
    {
        for ($id = 1; $id <= 6; $id++) {
            $this->addToLibrary($id, lastReadAt: CarbonImmutable::parse('2026-01-01')->addMinutes($id));
        }

        $data = $this->data();
        $feed = $data->continueFeed($data->entries());

        $this->assertTrue($data->hasContinueFeed($feed));
        $this->assertCount(5, $feed);
        $this->assertSame(
            [6, 5, 4, 3, 2],
            $feed->pluck('manga_id')->all(),
            'most recently read first',
        );

        $this->assertTrue($data->continueFeed(collect())->isEmpty());
        $this->assertFalse($data->hasContinueFeed(collect()));
    }

    public function test_header_counts_follow_shelf_and_search_filters(): void
    {
        $one = app(Library::class)->createCategory($this->user, 'One');

        $this->addToLibrary(1);
        $this->addToLibrary(2);

        app(Library::class)->attach($this->user, 1, $one->id);

        $data = $this->data();
        $entries = $data->entries();
        $filings = $data->filings($entries);

        $this->assertSame(2, $data->totalAll($entries));
        $this->assertSame(
            1,
            $data->totalFiltered($this->filter()->matching($entries, $filings, $one->id, '', 'recent')),
            'one series filed on the shelf',
        );
        $this->assertSame(
            0,
            $data->totalFiltered($this->filter()->matching($entries, $filings, null, 'no such title', 'recent')),
            'a search with no match filters everything out',
        );
    }

    public function test_continue_feed_skips_fully_read_and_breaks_ties_by_progress(): void
    {
        $moment = CarbonImmutable::parse('2026-02-01 12:00:00');

        // Fully read, but read most recently: still absent, because offering
        // chapter one here would be the one answer that is certainly wrong.
        $this->addToLibrary(1, chapters: 2, lastReadAt: $moment->addHour());
        $this->addToLibrary(2, chapters: 10, lastReadAt: $moment);
        $this->addToLibrary(3, chapters: 10, lastReadAt: $moment);

        $reading = app(ReadingState::class);
        $reading->markRead($this->user, 1, 101, true);
        $reading->markRead($this->user, 1, 102, true);
        $reading->markRead($this->user, 2, 201, true);

        foreach ([301, 302, 303, 304] as $chapterId) {
            $reading->markRead($this->user, 3, $chapterId, true);
        }

        $feed = $this->data()->continueFeed($this->data()->entries());

        $this->assertSame(
            [3, 2],
            $feed->pluck('manga_id')->all(),
            'fully read stays out, and the tied pair breaks toward further progress',
        );
    }

    public function test_a_filtered_library_reports_empty_feed_but_keeps_its_totals(): void
    {
        $this->addToLibrary(1, lastReadAt: CarbonImmutable::parse('2026-01-05'));
        $this->addToLibrary(2);

        $data = $this->data();
        $entries = $data->entries();
        $matching = $this->filter()->matching($entries, $data->filings($entries), null, 'no such title', 'recent');

        $this->assertSame(2, $data->totalAll($entries));
        $this->assertSame(0, $data->totalFiltered($matching));
        $this->assertTrue($data->continueFeed($matching)->isEmpty());
        $this->assertFalse($data->hasContinueFeed($matching));
    }

    // Checks --------------------------------------------------------------

    public function test_checks_read_the_latest_row_per_visible_series(): void
    {
        $this->addToLibrary(1);
        $this->addToLibrary(2);
        $this->addToLibrary(3);

        LibraryCheck::createFor($this->user, [
            'suwayomi_manga_id' => 1,
            'state' => LibraryCheck::CHECKED,
            'chapter_count' => 12,
            'new_chapters' => 2,
            'checked_at' => now(),
        ]);
        LibraryCheck::createFor($this->user, [
            'suwayomi_manga_id' => 2,
            'state' => LibraryCheck::CHECKED,
            'chapter_count' => 3,
            'new_chapters' => 0,
            'checked_at' => now(),
        ]);

        $data = $this->data();
        $checks = $data->checks($data->entries());

        $this->assertSame([1, 2], array_keys($checks));
        $this->assertSame(2, $data->newChapters($checks, 1));
        $this->assertSame(0, $data->newChapters($checks, 2));
        $this->assertNull($data->newChapters($checks, 3));
    }

    public function test_check_run_and_progress_read_the_visible_run(): void
    {
        $run = CheckRun::begin($this->user, 10);

        $run->forceFill([
            'state' => CheckRun::COMPLETE,
            'checked' => 4,
            'new_chapters' => 2,
            'failed' => 1,
            'finished_at' => now(),
        ])->save();

        $data = $this->data();

        $this->assertSame($run->id, $data->checkRun()?->id);
        $this->assertSame(
            [
                'total' => 10,
                'done' => 4,
                'percent' => 40,
                'running' => false,
                'cancelled' => false,
                'failed' => false,
                'stalled' => false,
                'new' => 2,
                'failed_series' => 1,
            ],
            $data->checkProgress($data->checkRun()),
        );
    }

    public function test_a_running_run_that_has_gone_quiet_is_stalled(): void
    {
        $run = CheckRun::begin($this->user, 10);

        $run->forceFill(['last_progress_at' => now()->subSeconds(LibraryPageData::CHECK_STALL_SECONDS + 1)])->save();

        $data = $this->data();
        $progress = $data->checkProgress($data->checkRun());

        $this->assertNotNull($progress);
        $this->assertTrue($progress['running']);
        $this->assertTrue($progress['stalled']);
    }

    public function test_a_finished_run_is_not_reported_an_hour_later(): void
    {
        $run = CheckRun::begin($this->user, 10);

        $run->forceFill([
            'state' => CheckRun::COMPLETE,
            'finished_at' => now()->subMinutes(CheckRun::VISIBLE_MINUTES + 1),
        ])->save();

        $this->assertNull($this->data()->checkRun());
    }

    public function test_the_schedule_summary_and_due_check_read_the_series_rows(): void
    {
        $checked = $this->addToLibrary(1);
        $checked->forceFill(['chapter_list_checked_at' => now()])->save();

        $this->addToLibrary(2);
        $this->addToLibrary(3);

        $data = $this->data();
        $summary = $data->checkSchedule();

        $this->assertSame(3, $summary['total']);
        $this->assertSame(2, $summary['due']);
        $this->assertSame(2, $summary['never']);
        $this->assertSame(1, $summary['skipped']);

        $this->assertFalse($data->isDue(1));
        $this->assertTrue($data->isDue(2));
        $this->assertFalse($data->isDue(999), 'an id with no row is not due');
    }

    // Failure and shelves --------------------------------------------------

    public function test_source_failure_is_reported_after_a_failed_read_and_clears_after_recovery(): void
    {
        $this->addToLibrary(1);
        $this->serverDown = true;

        $data = $this->data();
        $failed = $data->entries();

        $this->assertNotNull($data->sourceFailure($failed));
        $this->assertNull($failed->first()['cover'], 'the rows still render without the source');

        $this->serverDown = false;

        $this->assertNull(
            $data->sourceFailure($data->entries()),
            'a server that came back and answered is not down, whatever it said a minute ago',
        );
    }

    public function test_filed_and_unfiled_shelves_and_shelf_name_follow_the_readers_order(): void
    {
        $one = app(Library::class)->createCategory($this->user, 'One');
        $two = app(Library::class)->createCategory($this->user, 'Two');

        $this->addToLibrary(1);
        app(Library::class)->attach($this->user, 1, $two->id);

        $data = $this->data();
        $shelves = $data->shelves();
        $filings = $data->filings($data->entries());

        $this->assertSame([['id' => $two->id, 'name' => 'Two']], $data->filedShelves($shelves, $filings, 1));
        $this->assertSame([['id' => $one->id, 'name' => 'One']], $data->unfiledShelves($shelves, $filings, 1));
        $this->assertSame('Two', $data->shelfName($shelves, $two->id));
        $this->assertSame('Shelf', $data->shelfName($shelves, 999));
    }

    // The fake -------------------------------------------------------------

    private function fakeSuwayomi(): void
    {
        Http::fake(function (Request $request) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            if ($this->serverDown) {
                return Http::response('gateway down', 503);
            }

            if (str_contains($query, 'mangas(')) {
                return Http::response(['data' => ['mangas' => ['nodes' => $this->sourceRows()]]]);
            }

            return Http::response(['data' => ['manga' => null]]);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sourceRows(): array
    {
        $rows = [];

        foreach ($this->known as $mangaId => $shape) {
            $chapters = $shape['chapters'];

            $rows[] = [
                'id' => (string) $mangaId,
                'title' => "Series {$mangaId}",
                'thumbnailUrl' => "/api/v1/manga/{$mangaId}/thumbnail",
                'status' => 'ONGOING',
                'initialized' => $shape['initialized'],
                'source' => ['id' => '6247824327199706550', 'name' => 'Asura Scans', 'lang' => 'en'],
                'chapters' => [
                    'totalCount' => $chapters,
                    'nodes' => $chapters > 0
                        ? array_map(
                            fn (int $n): array => ['id' => (string) ($mangaId * 100 + $n)],
                            range(1, $chapters),
                        )
                        : [],
                ],
            ];
        }

        return $rows;
    }

    private function addToLibrary(
        int $mangaId,
        int $chapters = 10,
        bool $initialized = true,
        ?CarbonImmutable $addedAt = null,
        ?CarbonImmutable $lastReadAt = null,
    ): SeriesState {
        $this->known[$mangaId] = ['chapters' => $chapters, 'initialized' => $initialized];

        return SeriesState::createFor($this->user, [
            'suwayomi_manga_id' => $mangaId,
            'title' => "Series {$mangaId}",
            'in_library' => true,
            'added_at' => $addedAt ?? now(),
            'last_read_at' => $lastReadAt,
        ]);
    }
}
