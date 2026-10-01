<?php

namespace Tests\Feature\Suwayomi;

use App\Suwayomi\SeriesGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\MintsTokens;
use Tests\TestCase;

class SeriesGatewayTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    /**
     * Responses keyed by which document they answer, so one test can fail the
     * refresh while the follow-up read still succeeds.
     *
     * A property rather than a second Http::fake() inside a test: Laravel keeps
     * the earliest matching stub, so re-faking silently serves the original data
     * and the test passes or fails for the wrong reason. Trap 23.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $overrides = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->overrides = [];

        Http::fake(function (Request $request) {
            $query = $request->data()['query'] ?? '';

            if (str_contains($query, 'mutation Login')) {
                return Http::response([
                    'data' => ['login' => ['accessToken' => $this->accessToken(), 'refreshToken' => $this->refreshToken()]],
                ]);
            }

            foreach ($this->overrides as $needle => $response) {
                if (str_contains($query, $needle)) {
                    return Http::response($response);
                }
            }

            return Http::response($this->responseFor($query));
        });
    }

    /**
     * The "this id is not a known manga" answer.
     */
    private function makeMangaUnknown(): void
    {
        $this->overrides['query Series'] = ['data' => ['manga' => null]];
    }

    /**
     * The upstream condition where a source's chapter listing yields nothing.
     *
     * Scoped to the refresh mutation, because the read that follows it still has
     * to answer: the series page shows the series with an explanation, so a test
     * that failed every document would be testing the wrong thing.
     */
    private function makeSourceHaveNoChapters(): void
    {
        $this->overrides['fetchMangaAndChapters'] = ['errors' => [[
            'message' => 'Exception while fetching data (/fetchChapters) : No chapters found '
                .'java.lang.Exception: No chapters found at suwayomi.tachidesk.manga.impl.Chapter.update',
        ]]];
    }

    /**
     * Whether a recorded request is a data request rather than the login the
     * gateway makes first.
     */
    private function isDataRequest(Request $request): bool
    {
        return ! str_contains($request->data()['query'] ?? '', 'mutation Login');
    }

    /**
     * A payload copied from a live response, with the ids and timestamps the
     * server actually sent rather than tidy invented ones.
     *
     * @return array<string, mixed>
     */
    private function responseFor(string $query): array
    {
        $manga = [
            'id' => '76',
            'title' => 'The Berserker\'s Second Playthrough',
            'url' => '/series/the-berserkers-second-playthrough',
            'realUrl' => 'https://asurascans.com/comics/the-berserkers-second-playthrough',
            'thumbnailUrl' => '/api/v1/manga/76/thumbnail',
            'status' => 'ONGOING',
            'author' => 'Life Ruined',
            'artist' => 'Isang',
            'genre' => ['Manhwa', 'Action', 'Fantasy'],
            'description' => 'Rating: 8.24',
            'initialized' => true,
            'unreadCount' => 41,
            'updateStrategy' => 'ALWAYS_UPDATE',
            'chaptersLastFetchedAt' => '1790776498',
            'source' => ['id' => '6247824327199706550', 'name' => 'Asura Scans', 'lang' => 'en', 'contentWarning' => 'SAFE'],
        ];

        $chapters = [
            // uploadDate is milliseconds; fetchedAt-style fields are seconds.
            // Both sit in the same payload, which is the trap.
            $this->chapter(3, 3.0, '1767198093000'),
            $this->chapter(2, 2.0, '1767100000000'),
            $this->chapter(1, 1.0, '1767000000000'),
        ];

        if (str_contains($query, 'fetchChapterPages')) {
            return ['data' => ['fetchChapterPages' => [
                'chapter' => ['id' => '1', 'name' => 'Chapter 1', 'pageCount' => 22],
                'pages' => [
                    '/api/v1/manga/76/chapter/1/page/0',
                    '/api/v1/manga/76/chapter/1/page/1',
                ],
            ]]];
        }

        if (str_contains($query, 'fetchMangaAndChapters')) {
            return ['data' => ['fetchMangaAndChapters' => [
                'manga' => $manga,
                // A bare list, not the node-list shape the query returns. If the
                // gateway does not wrap these the series page reads as empty.
                'chapters' => $chapters,
            ]]];
        }

        if (str_contains($query, 'query Series')) {
            return ['data' => ['manga' => $manga + ['chapters' => [
                'totalCount' => count($chapters),
                'nodes' => $chapters,
            ]]]];
        }

        return ['data' => ['ok' => true]];
    }

    /**
     * @return array<string, mixed>
     */
    private function chapter(int $id, float $number, string $uploadDate): array
    {
        return [
            'id' => (string) $id,
            'name' => 'Chapter '.$id,
            'chapterNumber' => $number,
            'scanlator' => null,
            'uploadDate' => $uploadDate,
            'pageCount' => -1,
            'isRead' => false,
            'lastPageRead' => 0,
            'sourceOrder' => $id,
        ];
    }

    public function test_it_reads_a_series_without_mutating_the_server(): void
    {
        $series = app(SeriesGateway::class)->find(76);

        $this->assertNotNull($series);
        $this->assertSame(76, $series['id']);
        $this->assertSame("The Berserker's Second Playthrough", $series['title']);
        $this->assertSame(3, $series['chapters']['totalCount']);

        // The login mutation is expected, so exclude it rather than asserting
        // that nothing was a mutation at all.
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->data()['query'] ?? '', 'fetchManga'));
    }

    /**
     * The measured reason the series route needs no source segment. A source id
     * that round-trips through a JSON number becomes a different id.
     */
    public function test_the_source_id_survives_as_a_string(): void
    {
        $series = app(SeriesGateway::class)->find(76);

        $this->assertSame('6247824327199706550', $series['source']['id']);
        $this->assertGreaterThan(9007199254740992, (int) $series['source']['id']);
    }

    /**
     * uploadDate and the seconds-based fields sit in one payload. Reading both as
     * seconds put all 41 chapters of the live series in the year 57970.
     */
    public function test_chapter_upload_dates_are_read_as_milliseconds(): void
    {
        $series = app(SeriesGateway::class)->find(76);

        $newest = $series['chapters']['nodes'][0]['uploadDate'];

        $this->assertNotNull($newest);
        // The value is copied verbatim from a live response, so the expected date
        // is whatever that millisecond count actually is.
        $this->assertSame('2025-12-31', $newest->format('Y-m-d'));
        $this->assertLessThan(time(), $newest->getTimestamp());
    }

    public function test_the_series_last_fetched_time_is_read_as_seconds(): void
    {
        $series = app(SeriesGateway::class)->find(76);

        $this->assertSame(2026, $series['chaptersLastFetchedAt']->year);
    }

    public function test_it_casts_ids_and_numbers_before_anything_else_sees_them(): void
    {
        $series = app(SeriesGateway::class)->find(76);

        foreach ($series['chapters']['nodes'] as $chapter) {
            $this->assertIsInt($chapter['id']);
            $this->assertIsFloat($chapter['chapterNumber']);
            $this->assertIsInt($chapter['pageCount']);
        }

        $this->assertSame(['Manhwa', 'Action', 'Fantasy'], $series['genre']);
    }

    /**
     * pageCount is -1 until fetchChapterPages runs, so the chapter list cannot
     * show progress on first view and must not imply that it can.
     */
    public function test_page_counts_are_minus_one_until_the_pages_are_fetched(): void
    {
        $series = app(SeriesGateway::class)->find(76);

        foreach ($series['chapters']['nodes'] as $chapter) {
            $this->assertSame(-1, $chapter['pageCount']);
        }
    }

    public function test_it_fetches_page_images_for_a_chapter(): void
    {
        $result = app(SeriesGateway::class)->pages(1);

        $this->assertSame(22, $result['page_count']);
        $this->assertSame([
            '/api/v1/manga/76/chapter/1/page/0',
            '/api/v1/manga/76/chapter/1/page/1',
        ], $result['pages']);
    }

    /**
     * The refresh mutation returns chapters as a bare list while the query returns
     * a node list. Not wrapping them makes a refreshed series read as having no
     * chapters at all, which is the failure this guards.
     */
    public function test_refresh_wraps_the_chapter_list_to_match_the_query_shape(): void
    {
        $result = app(SeriesGateway::class)->refresh(76);

        $this->assertNull($result['error']);
        $this->assertNotNull($result['series']);
        $this->assertSame(3, $result['series']['chapters']['totalCount']);
        $this->assertCount(3, $result['series']['chapters']['nodes']);
    }

    /**
     * "No chapters found" is a bare java.lang.Exception from the source, not a
     * fault of ours. It arrives as a GraphQL error on HTTP 200, so it has to be
     * reported rather than thrown.
     */
    public function test_a_source_with_no_chapters_is_reported_not_thrown(): void
    {
        $this->makeSourceHaveNoChapters();

        $result = app(SeriesGateway::class)->refresh(76);

        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('No chapters found', $result['error']);

        // And the series itself is still readable, so the page can show the
        // series with an explanation rather than an error page.
        $this->assertSame(76, $result['series']['id']);
    }

    /**
     * A GraphQL error arrives with HTTP 200, so the transport sees a successful
     * response. Without handling it the reader gets a bare "Unknown GraphQL error".
     */
    public function test_the_no_chapters_error_keeps_its_server_message(): void
    {
        $this->makeSourceHaveNoChapters();

        $result = app(SeriesGateway::class)->refresh(76);

        $this->assertStringNotContainsString('Unknown GraphQL error', (string) $result['error']);
    }

    public function test_an_unknown_manga_resolves_to_null(): void
    {
        $this->makeMangaUnknown();

        $this->assertNull(app(SeriesGateway::class)->find(999999));
    }
}
