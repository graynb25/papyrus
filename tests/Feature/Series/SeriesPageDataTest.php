<?php

namespace Tests\Feature\Series;

use App\Live\Series\SeriesPageData;
use App\Models\Download;
use App\Models\LibraryCheck;
use App\Models\SeriesCover;
use App\Models\Source;
use App\Models\User;
use App\Support\ChapterNotes;
use App\Support\ImageUrl;
use App\Support\Incognito;
use App\Support\ReaderSettings;
use App\Support\ReadingState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * The series page's reads, exercised without mounting the page.
 *
 * The shapes, order, page slice and empty cases are the page's own. The
 * gateway is the real one behind a fake HTTP server, so the hydration of ids
 * and chapter nodes is part of what these tests hold.
 */
class SeriesPageDataTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function data(): SeriesPageData
    {
        return app(SeriesPageData::class);
    }

    /**
     * @param  array<int, array<string, mixed>>  $chapters
     * @return array<string, mixed>
     */
    private function manga(array $chapters = [], string $status = 'ONGOING'): array
    {
        return [
            'id' => 76,
            'title' => 'A Series',
            'url' => '/api/v1/manga/76',
            'realUrl' => 'https://source.test/a-series',
            'thumbnailUrl' => null,
            'status' => $status,
            'author' => 'Someone',
            'artist' => null,
            'genre' => ['Action'],
            'description' => 'A description.',
            'initialized' => true,
            'unreadCount' => 0,
            'updateStrategy' => 'ALWAYS_UPDATE',
            'chaptersLastFetchedAt' => '1790990083',
            'source' => ['id' => '6247824327199706550', 'name' => 'Example', 'lang' => 'en', 'contentWarning' => 'SAFE'],
            'chapters' => ['totalCount' => count($chapters), 'nodes' => $chapters],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function chapter(int $id, int $sourceOrder, float $number, ?string $scanlator = null, bool $downloaded = false): array
    {
        return [
            'id' => $id,
            'name' => "Chapter {$number}",
            'chapterNumber' => $number,
            'scanlator' => $scanlator,
            'uploadDate' => null,
            'pageCount' => -1,
            'isRead' => false,
            'isDownloaded' => $downloaded,
            'lastPageRead' => 0,
            'sourceOrder' => $sourceOrder,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $manga
     */
    private function fakeSeries(?array $manga): void
    {
        Http::fake(function (Request $request) use ($manga) {
            $body = (string) $request->body();

            if (str_contains($body, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            if (str_contains($body, 'query Series')) {
                return Http::response(['data' => ['manga' => $manga]]);
            }

            return Http::response(['data' => []]);
        });
    }

    public function test_the_series_read_returns_the_hydrated_manga_and_chapters(): void
    {
        $this->fakeSeries($this->manga([
            $this->chapter(7601, 1, 1.0, 'Group A'),
            $this->chapter(7602, 2, 2.0, 'Group B'),
        ]));

        $data = $this->data();
        $series = $data->series(76);

        $this->assertIsArray($series);
        $this->assertSame('A Series', $series['title']);
        $this->assertNull($data->seriesError());

        $all = $data->allChapters($series);

        $this->assertCount(2, $all);
        $this->assertSame(7601, $all[0]['id']);
        $this->assertSame(2, $data->chapterCount($all));
    }

    public function test_an_unreachable_server_returns_null_and_a_named_error(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains((string) $request->body(), 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            return Http::response('The server is not answering.', 500);
        });

        $data = $this->data();

        $this->assertNull($data->series(76));
        $this->assertStringContainsString('Suwayomi returned HTTP 500', (string) $data->seriesError());
    }

    public function test_a_series_the_server_does_not_hold_is_null_without_an_error(): void
    {
        $this->fakeSeries(null);

        $data = $this->data();

        $this->assertNull($data->series(76));
        $this->assertNull($data->seriesError());
    }

    public function test_the_source_mirror_joins_on_suwayomi_id_and_accepts_a_missing_source(): void
    {
        $source = Source::create([
            'suwayomi_source_id' => 6_247_824_327_199_706_550,
            'name' => 'Example',
            'display_name' => 'Example',
            'extension_pkg_name' => 'eu.kanade.tachiyomi.extension.all.example',
            'lang' => 'en',
            'content_warning' => Source::SAFE,
            'is_installed' => true,
        ]);

        $data = $this->data();

        $mirror = $data->sourceMirror(['source' => ['id' => (string) $source->suwayomi_source_id]]);

        $this->assertNotNull($mirror);
        $this->assertSame($source->id, $mirror->id);

        $this->assertNull($data->sourceMirror(['source' => ['id' => '1']]));
        $this->assertNull($data->sourceMirror(['source' => ['id' => '']]));
        $this->assertNull($data->sourceMirror(['source' => []]));
        $this->assertNull($data->sourceMirror(null));
    }

    public function test_state_is_the_stored_row_or_an_unsaved_one(): void
    {
        $data = $this->data();

        $missing = $data->state(76);

        $this->assertSame(76, $missing->suwayomi_manga_id);
        $this->assertFalse($missing->exists);

        app(ReadingState::class)->setInLibrary($this->user, 76, true);

        $stored = $data->state(76);

        $this->assertTrue($stored->exists);
        $this->assertTrue($stored->in_library);
    }

    public function test_chapters_filter_and_order_like_the_page(): void
    {
        $chapters = [
            $this->chapter(1, 2, 2.0, 'Group A'),
            $this->chapter(2, 1, 1.0, 'Group B'),
            $this->chapter(3, 1, 1.5, null),
            $this->chapter(4, 3, 3.0, "\u{200B}"),
        ];

        $data = $this->data();

        $ascending = $data->chapters($chapters, ReaderSettings::ORDER_OLDEST, []);

        $this->assertSame([2, 3, 1, 4], array_column($ascending, 'id'));
        $this->assertSame(4, $data->visibleCount($ascending));

        $descending = $data->chapters($chapters, ReaderSettings::ORDER_NEWEST, []);

        $this->assertSame([4, 1, 3, 2], array_column($descending, 'id'));

        $excluded = $data->chapters($chapters, ReaderSettings::ORDER_OLDEST, ['group a']);

        $this->assertSame([2, 3, 4], array_column($excluded, 'id'));

        $noScanlators = $data->chapters($chapters, ReaderSettings::ORDER_OLDEST, ['Group A', 'Group B']);

        $this->assertSame(
            [3, 4],
            array_column($noScanlators, 'id'),
            'a chapter with no visible scanlator is never excluded',
        );

        $this->assertSame([], $data->chapters([], ReaderSettings::ORDER_OLDEST, []));
    }

    public function test_the_missing_series_and_reader_state_are_empty_not_errors(): void
    {
        $data = $this->data();
        $state = $data->state(76);

        $this->assertSame([], $data->allChapters(null));
        $this->assertSame(0, $data->chapterCount([]));
        $this->assertSame(0, $data->visibleCount([]));
        $this->assertSame(1, $data->lastPage(0));
        $this->assertSame([], $data->chapterStates([], 1));
        $this->assertSame([], $data->allChapterStates([]));
        $this->assertSame([], $data->chapterNotes([], 1));
        $this->assertNull($data->customCover(76));
        $this->assertNull($data->rating($state));
        $this->assertSame([], $data->tags($state));
        $this->assertNull($data->emptyReason(76, null));
        $this->assertSame(0, $data->readCount([]));
        $this->assertSame(0, $data->unreadDownloadableCount([], []));
        $this->assertNull($data->resumeChapter($state, [], ReaderSettings::ORDER_OLDEST));
        $this->assertFalse($data->isDownloaded([], 1));
        $this->assertNull($data->downloadState([], 1));

        $this->assertSame(
            ImageUrl::thumbnail(76, ImageUrl::defaultFormat()),
            $data->coverUrl(76, null),
        );

        $this->assertSame(
            ReaderSettings::ORDER_OLDEST,
            $data->readingSettings(76)['chapter_order'],
        );
    }

    public function test_last_page_follows_the_visible_count(): void
    {
        $data = $this->data();

        $this->assertSame(1, $data->lastPage(50));
        $this->assertSame(2, $data->lastPage(51));
        $this->assertSame(2, $data->lastPage(100));
        $this->assertSame(3, $data->lastPage(101));
        $this->assertSame(2, $data->lastPage(60, 50));
    }

    public function test_chapter_states_and_notes_are_scoped_to_the_open_page(): void
    {
        $chapters = [];

        for ($index = 1; $index <= 60; $index++) {
            $chapters[] = $this->chapter(7600 + $index, $index, (float) $index);
        }

        $reading = app(ReadingState::class);
        $reading->markRead($this->user, 76, 7601, true);
        $reading->markRead($this->user, 76, 7660, true);

        $notes = app(ChapterNotes::class);
        $notes->save($this->user, 76, 7601, 'The first note.');
        $notes->save($this->user, 76, 7660, 'The last note.');

        $data = $this->data();

        $firstPageStates = $data->chapterStates($chapters, 1);

        $this->assertArrayHasKey(7601, $firstPageStates);
        $this->assertArrayNotHasKey(7660, $firstPageStates);

        $allStates = $data->allChapterStates($chapters);

        $this->assertArrayHasKey(7601, $allStates);
        $this->assertArrayHasKey(7660, $allStates);

        $firstPageNotes = $data->chapterNotes($chapters, 1);
        $lastPageNotes = $data->chapterNotes($chapters, 2);

        $this->assertArrayHasKey(7601, $firstPageNotes);
        $this->assertArrayNotHasKey(7660, $firstPageNotes);
        $this->assertArrayHasKey(7660, $lastPageNotes);
        $this->assertSame(2, $data->readCount($chapters));
    }

    public function test_all_chapter_states_reads_past_the_five_hundred_row_chunk(): void
    {
        $chapters = [];

        for ($id = 1; $id <= 501; $id++) {
            $chapters[] = ['id' => $id];
        }

        $reading = new class(app(Incognito::class)) extends ReadingState
        {
            /** @var list<int> */
            public array $chunks = [];

            public function chapterStates(User|int $user, array $chapterIds): array
            {
                $this->chunks[] = count($chapterIds);

                return parent::chapterStates($user, $chapterIds);
            }
        };

        $this->app->instance(ReadingState::class, $reading);

        $reading->markRead($this->user, 76, 501, true);

        $states = $this->data()->allChapterStates($chapters);

        $this->assertSame([500, 1], $reading->chunks);
        $this->assertArrayHasKey(501, $states);
    }

    public function test_empty_reason_names_the_recorded_failure_and_the_empty_source(): void
    {
        $data = $this->data();

        $this->assertSame(
            [__('The source returned no chapters'), __('The source answered, but it listed no chapters. If the series moved, you can move it to another source from here. The source may have changed its layout or it may be broken.')],
            $data->emptyReason(101, 'java.lang.Exception: No chapters found'),
        );

        $this->assertSame(
            [__('The chapter list did not load'), 'Cloudflare bypass currently disabled'],
            $data->emptyReason(101, 'Cloudflare bypass currently disabled'),
        );

        LibraryCheck::createFor($this->user, [
            'suwayomi_manga_id' => 102,
            'state' => LibraryCheck::ERROR,
            'error' => 'The source timed out.',
            'checked_at' => now(),
        ]);
        LibraryCheck::createFor($this->user, [
            'suwayomi_manga_id' => 103,
            'state' => LibraryCheck::ERROR,
            'error' => null,
            'checked_at' => now(),
        ]);
        LibraryCheck::createFor($this->user, [
            'suwayomi_manga_id' => 104,
            'state' => LibraryCheck::CHECKED,
            'chapter_count' => 0,
            'checked_at' => now(),
        ]);

        $this->assertSame(
            [__('This source could not be reached'), 'The source timed out.'],
            $data->emptyReason(102, null),
        );
        $this->assertSame(
            [__('This source could not be reached'), __('The server could not be reached.')],
            $data->emptyReason(103, null),
        );
        $this->assertSame(
            [__('No chapters yet'), __('The source has not listed any chapters for this series.')],
            $data->emptyReason(104, null),
        );
    }

    public function test_a_custom_cover_wins_over_the_source_cover(): void
    {
        $data = $this->data();

        $this->assertNull($data->customCover(76));

        SeriesCover::createFor($this->user, [
            'suwayomi_manga_id' => 76,
            'disk' => 'local',
            'path' => 'covers/76.jpg',
            'origin' => 'upload',
        ]);

        $cover = $data->customCover(76);

        $this->assertNotNull($cover);
        $this->assertSame(
            route('covers.show', ['manga' => 76]),
            $data->coverUrl(76, $cover),
        );
    }

    public function test_rating_tags_and_reading_settings_come_from_the_reader_rows(): void
    {
        $reading = app(ReadingState::class);
        $reading->setRating($this->user, 76, 4);
        $reading->setTags($this->user, 76, ['Favourite', 'To read']);

        app(ReaderSettings::class)->setSeriesOverride($this->user, 76, [
            'mode' => ReaderSettings::MODE_PAGED,
            'chapter_order' => ReaderSettings::ORDER_NEWEST,
        ]);

        $data = $this->data();
        $state = $data->state(76);

        $this->assertSame(4, $data->rating($state));
        $this->assertSame(['Favourite', 'To read'], $data->tags($state));

        $settings = $data->readingSettings(76);

        $this->assertSame(ReaderSettings::MODE_PAGED, $settings['mode']);
        $this->assertSame(ReaderSettings::ORDER_NEWEST, $settings['chapter_order']);
    }

    public function test_resume_prefers_the_pointer_then_the_end_of_the_display_order(): void
    {
        $chapters = [
            $this->chapter(7601, 1, 1.0),
            $this->chapter(7602, 2, 2.0),
            $this->chapter(7603, 3, 3.0),
        ];

        $reading = app(ReadingState::class);
        $reading->setPointer($this->user, 76, 7602);

        $data = $this->data();
        $state = $data->state(76);

        $this->assertSame(7602, $data->resumeChapter($state, $chapters, ReaderSettings::ORDER_OLDEST)['id'] ?? null);

        $reading->setPointer($this->user, 76, null);
        $state = $data->state(76);

        $this->assertSame(7601, $data->resumeChapter($state, $chapters, ReaderSettings::ORDER_OLDEST)['id'] ?? null);
        $this->assertSame(7603, $data->resumeChapter($state, $chapters, ReaderSettings::ORDER_NEWEST)['id'] ?? null);
    }

    public function test_download_state_is_one_read_for_the_whole_list_and_can_be_forgotten(): void
    {
        $chapters = [
            $this->chapter(7601, 1, 1.0, downloaded: true),
            $this->chapter(7602, 2, 2.0),
        ];

        Download::createFor($this->user, [
            'suwayomi_chapter_id' => 7602,
            'suwayomi_manga_id' => 76,
            'state' => Download::QUEUED,
        ]);

        $data = $this->data();

        DB::enableQueryLog();

        $this->assertNull($data->downloadState($chapters, 7601));
        $this->assertSame(Download::QUEUED, $data->downloadState($chapters, 7602));

        $queries = collect(DB::getQueryLog())
            ->filter(static fn (array $query): bool => str_contains((string) $query['query'], 'downloads'))
            ->count();

        $this->assertSame(1, $queries, 'the map is built once for the whole list');

        $data->forgetDownloadStates();
        $data->downloadState($chapters, 7602);

        $queries = collect(DB::getQueryLog())
            ->filter(static fn (array $query): bool => str_contains((string) $query['query'], 'downloads'))
            ->count();

        $this->assertSame(2, $queries, 'forgetting the map makes the next read query again');

        $this->assertTrue($data->isDownloaded($chapters, 7601));
        $this->assertFalse($data->isDownloaded($chapters, 7602));
        $this->assertFalse($data->isDownloaded($chapters, 9999));
    }

    public function test_unread_downloadable_count_uses_the_whole_filtered_list(): void
    {
        $chapters = [
            $this->chapter(7601, 1, 1.0, downloaded: true),
            $this->chapter(7602, 2, 2.0),
            $this->chapter(7603, 3, 3.0),
            $this->chapter(7604, 4, 4.0),
        ];

        $reading = app(ReadingState::class);
        $reading->markRead($this->user, 76, 7602, true);
        $reading->markRead($this->user, 76, 7603, true);

        $data = $this->data();
        $states = $data->allChapterStates($chapters);

        $this->assertSame(1, $data->unreadDownloadableCount($chapters, $states));
    }
}
