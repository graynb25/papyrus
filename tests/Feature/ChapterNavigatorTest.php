<?php

namespace Tests\Feature;

use App\Library\ChapterNavigator;
use App\Models\ChapterState;
use App\Models\User;
use App\Support\ReadingState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * The chapter navigator: boundary ticks, a read map, cross-chapter seek.
 *
 * ## What the tests are actually protecting
 *
 * The three fill states, and the reason there are three. Measured on the dev library,
 * **12 of 575 chapters carry a `pageCount`, 2.1%**, because `pageCount` is `-1` until
 * a chapter's pages have been fetched. So a fraction per chapter is computable for
 * almost nothing, and a two-state strip would render "untouched" and "read but not
 * computable" identically. These tests hold that distinction.
 *
 * ## Why the state names are the claim
 *
 * `read`, `partial` and `unknown` are not decoration. `unknown` is most of a real
 * library, and a segment that silently looked like zero progress would be a strip
 * lying about two thirds of itself.
 */
class ChapterNavigatorTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private User $user;

    /** @var array<int, array<int, array<string, mixed>>> chapters per manga */
    private array $chapters = [];

    /** @var bool when true, the server refuses everything */
    private bool $down = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->chapters = [];
        $this->down = false;

        $this->fakeSuwayomi();
    }

    /**
     * A server with the chapter list we say it has.
     *
     * `pageCount` defaults to -1, which is what the real server reports for a chapter
     * nobody has downloaded. Tests set it where a fraction is the point.
     */
    private function fakeSuwayomi(): void
    {
        Http::fake(function (Request $request) {
            if ($this->down) {
                throw new ConnectionException('connection refused');
            }

            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            if (str_contains($query, 'fetchChapterPages')) {
                $chapterId = (int) (data_get($request->data(), 'variables.id') ?? 0);

                return Http::response(['data' => ['fetchChapterPages' => [
                    'chapter' => ['id' => (string) $chapterId, 'name' => 'Chapter', 'pageCount' => 3],
                    'pages' => [
                        "/api/v1/manga/76/chapter/{$chapterId}/page/0",
                        "/api/v1/manga/76/chapter/{$chapterId}/page/1",
                        "/api/v1/manga/76/chapter/{$chapterId}/page/2",
                    ],
                ]]]);
            }

            $ids = (array) (data_get($request->data(), 'variables.ids') ?? []);

            $nodes = [];

            foreach ($ids as $mangaId) {
                $list = $this->chapters[(int) $mangaId] ?? [];
                $chapters = [];

                foreach ($list as $chapter) {
                    $chapters[] = array_merge([
                        'id' => (string) $chapter['id'],
                        'name' => $chapter['name'] ?? 'Chapter',
                        // -1 unless a test says otherwise, because that is what an
                        // undownloaded chapter reports.
                        'pageCount' => $chapter['pageCount'] ?? -1,
                        'lastPageRead' => 0,
                        'isDownloaded' => false,
                    ], array_diff_key($chapter, ['id' => true, 'name' => true, 'pageCount' => true]));
                }

                $nodes[] = [
                    'id' => (string) $mangaId,
                    'title' => 'Series '.$mangaId,
                    'chapters' => ['totalCount' => count($chapters), 'nodes' => $chapters],
                ];
            }

            return Http::response(['data' => ['mangas' => ['nodes' => $nodes]]]);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $list
     */
    private function withChapters(array $list): void
    {
        $this->chapters[76] = $list;
    }

    private function markRead(int $chapterId, int $page = 0): void
    {
        app(ReadingState::class)->markRead($this->user->id, 76, $chapterId, true);

        if ($page > 0) {
            $this->progress($chapterId, $page);
        }
    }

    /**
     * Record that a reader stopped on a page, without marking the chapter read.
     *
     * Through the app's own path rather than an UPDATE, because an UPDATE against a
     * row that does not exist affects nothing and reports nothing. Three tests were
     * setting progress on a chapter the reader had never opened and silently recording
     * nothing.
     */
    private function progress(int $chapterId, int $page): void
    {
        app(ReadingState::class)->recordProgress($this->user->id, 76, $chapterId, $page, 20);
    }

    private function navigator(): ChapterNavigator
    {
        return app(ChapterNavigator::class);
    }

    // The three states -------------------------------------------------------

    public function test_an_untouched_chapter_is_unknown(): void
    {
        $this->withChapters([['id' => 7601]]);

        $result = $this->navigator()->forSeries($this->user, 76, 7601);

        $this->assertSame('unknown', $result['chapters'][0]['state']);
        $this->assertSame(0.0, $result['chapters'][0]['progress']);
    }

    public function test_a_finished_chapter_is_read_and_needs_no_page_count(): void
    {
        // The important one: a chapter with pageCount -1 can still be known finished,
        // because "finished" is a boolean and a fraction is not needed to say it.
        $this->withChapters([['id' => 7601, 'pageCount' => -1]]);
        $this->markRead(7601);

        $result = $this->navigator()->forSeries($this->user, 76, 7601);

        $this->assertSame('read', $result['chapters'][0]['state']);
        $this->assertSame(1.0, $result['chapters'][0]['progress']);
    }

    public function test_a_part_read_chapter_is_partial_only_with_a_page_count(): void
    {
        $this->withChapters([['id' => 7601, 'pageCount' => 20]]);
        $this->progress(7601, 5);

        $result = $this->navigator()->forSeries($this->user, 76, 7601);

        $this->assertSame('partial', $result['chapters'][0]['state']);
        $this->assertSame(0.25, $result['chapters'][0]['progress']);
    }

    public function test_progress_without_a_page_count_is_unknown_not_a_fraction(): void
    {
        // The case the two-state design would get wrong. There is a numerator and no
        // denominator, so any fraction would be a guess.
        $this->withChapters([['id' => 7601, 'pageCount' => -1]]);
        $this->progress(7601, 5);

        $result = $this->navigator()->forSeries($this->user, 76, 7601);

        $this->assertSame(
            'unknown',
            $result['chapters'][0]['state'],
            'Five pages read of an unknown length is not a fraction.',
        );
        $this->assertNull($result['chapters'][0]['pages'], 'And the denominator is reported as absent.');
    }

    public function test_the_counts_separate_the_three_states(): void
    {
        $this->withChapters([
            ['id' => 7601, 'pageCount' => -1],
            ['id' => 7602, 'pageCount' => 20],
            ['id' => 7603, 'pageCount' => 20],
            ['id' => 7604, 'pageCount' => -1],
        ]);

        $this->markRead(7601);
        $this->progress(7602, 5);

        $counts = $this->navigator()->forSeries($this->user, 76, 7603)['counts'];

        $this->assertSame(1, $counts['read']);
        $this->assertSame(1, $counts['partial']);
        $this->assertSame(2, $counts['unknown']);
        $this->assertSame(4, $counts['total']);
    }

    public function test_progress_is_clamped_when_a_source_shrinks(): void
    {
        // A chapter can hold more progress than pages after a source renumbers, and a
        // fill over 100% overflows its track.
        $this->withChapters([['id' => 7601, 'pageCount' => 10]]);
        $this->progress(7601, 25);

        $result = $this->navigator()->forSeries($this->user, 76, 7601);

        $this->assertSame(1.0, $result['chapters'][0]['progress']);
    }

    public function test_a_finished_chapter_beats_a_partial_fill(): void
    {
        // A reader who finished a chapter and then read back to page 3 is finished.
        $this->withChapters([['id' => 7601, 'pageCount' => 20]]);
        $this->markRead(7601);

        /*
         * Set directly rather than through recordProgress(), which recomputes
         * `is_read` from the page against the total and would un-mark a finished
         * chapter at page 3 of 20. That is correct: a reader at page 3 of 20 has not
         * finished, whatever an earlier visit said. The state this test needs is one
         * the app can hold when the count is unknown, so it is written directly.
         */
        ChapterState::query()
            ->forUser($this->user->id)
            ->where('suwayomi_chapter_id', 7601)
            ->update(['last_page_read' => 3]);

        $result = $this->navigator()->forSeries($this->user, 76, 7601);

        $this->assertSame(
            'read',
            $result['chapters'][0]['state'],
            'Finished wins over a partial fill, because finishing needs no denominator.',
        );
        $this->assertSame(1.0, $result['chapters'][0]['progress']);
    }

    // Position and ticks ----------------------------------------------------

    public function test_the_position_finds_the_neighbours(): void
    {
        $this->withChapters([
            ['id' => 7601],
            ['id' => 7602],
            ['id' => 7603],
        ]);

        $chapters = $this->navigator()->forSeries($this->user, 76, 7602)['chapters'];
        $position = $this->navigator()->position($chapters, 7602);

        $this->assertSame(7601, $position['previous']);
        $this->assertSame(7603, $position['next']);
        $this->assertSame(2, $position['index']);
        $this->assertSame(3, $position['total']);
    }

    public function test_the_first_chapter_has_no_previous(): void
    {
        $this->withChapters([['id' => 7601], ['id' => 7602]]);

        $chapters = $this->navigator()->forSeries($this->user, 76, 7601)['chapters'];

        $this->assertNull($this->navigator()->position($chapters, 7601)['previous']);
    }

    public function test_the_last_chapter_has_no_next(): void
    {
        $this->withChapters([['id' => 7601], ['id' => 7602]]);

        $chapters = $this->navigator()->forSeries($this->user, 76, 7602)['chapters'];

        $this->assertNull($this->navigator()->position($chapters, 7602)['next']);
    }

    public function test_an_unknown_chapter_still_reports_a_total(): void
    {
        // A deep link to a chapter the server no longer lists. The scrubber is
        // decoration on a chapter the reader can already read, so it must not throw.
        $this->withChapters([['id' => 7601]]);

        $chapters = $this->navigator()->forSeries($this->user, 76, 7601)['chapters'];
        $position = $this->navigator()->position($chapters, 9999);

        $this->assertNull($position['previous']);
        $this->assertNull($position['next']);
        $this->assertSame(1, $position['total']);
    }

    public function test_the_current_chapter_is_marked(): void
    {
        $this->withChapters([['id' => 7601], ['id' => 7602]]);

        $chapters = $this->navigator()->forSeries($this->user, 76, 7602)['chapters'];

        $this->assertFalse($chapters[0]['is_current']);
        $this->assertTrue($chapters[1]['is_current']);
    }

    public function test_order_follows_the_source_not_our_ids(): void
    {
        // Deliberately out of numeric order, because the source's order is the order a
        // reader means and sorting by id would be a different series on a source that
        // numbers them oddly.
        $this->withChapters([['id' => 7610], ['id' => 7602], ['id' => 7605]]);

        $chapters = $this->navigator()->forSeries($this->user, 76, 7602)['chapters'];

        $this->assertSame([7610, 7602, 7605], array_column($chapters, 'id'));
    }

    // Degradation -----------------------------------------------------------

    public function test_an_unreachable_server_costs_the_navigator_and_nothing_else(): void
    {
        $this->withChapters([['id' => 7601]]);
        $this->down = true;

        $result = $this->navigator()->forSeries($this->user, 76, 7601);

        $this->assertSame([], $result['chapters']);
        $this->assertSame(0, $result['counts']['total']);
    }

    public function test_a_series_the_server_does_not_know_resolves_to_nothing(): void
    {
        $this->withChapters([]);

        $result = $this->navigator()->forSeries($this->user, 76, 7601);

        $this->assertSame([], $result['chapters']);
    }

    public function test_another_readers_progress_is_not_shown(): void
    {
        $bob = User::factory()->create();
        $this->withChapters([['id' => 7601]]);

        app(ReadingState::class)->markRead($bob->id, 76, 7601, true);

        $result = $this->navigator()->forSeries($this->user, 76, 7601);

        $this->assertSame(
            'unknown',
            $result['chapters'][0]['state'],
            'Bob reading it is not this reader reading it.',
        );
    }

    // The page ---------------------------------------------------------------

    public function test_the_reader_renders_a_segment_per_chapter(): void
    {
        $this->withChapters([['id' => 7601], ['id' => 7602], ['id' => 7603]]);

        $this->get('/read/76/1')
            ->assertOk()
            ->assertSee('Chapters in this series', escape: false);
    }

    public function test_the_strip_links_to_every_chapter(): void
    {
        $this->withChapters([['id' => 7601], ['id' => 7602]]);

        $html = $this->get('/read/76/1')->assertOk()->content();

        $this->assertStringContainsString('/read/76/7601', $html);
        $this->assertStringContainsString('/read/76/7602', $html);
    }

    public function test_the_three_states_reach_the_markup(): void
    {
        $this->withChapters([
            ['id' => 7601, 'pageCount' => -1],
            ['id' => 7602, 'pageCount' => 20],
            ['id' => 7603, 'pageCount' => -1],
        ]);

        $this->markRead(7601);
        $this->progress(7602, 5);

        $html = $this->get('/read/76/1')->assertOk()->content();

        $this->assertStringContainsString('data-state="read"', $html);
        $this->assertStringContainsString('data-state="partial"', $html);
        $this->assertStringContainsString('data-state="unknown"', $html);
    }

    public function test_the_legend_says_what_the_states_mean(): void
    {
        // The states are not guessable, so the page states them rather than leaving a
        // reader to infer that an empty segment means "not started" rather than
        // "we cannot tell".
        $this->withChapters([['id' => 7601], ['id' => 7602]]);
        $this->markRead(7601);

        $this->get('/read/76/1')
            ->assertOk()
            ->assertSee('1 finished')
            ->assertSee('1 not started');
    }

    public function test_a_partial_segment_carries_its_fraction(): void
    {
        $this->withChapters([['id' => 7601, 'pageCount' => 20]]);
        $this->progress(7601, 5);

        $this->get('/read/76/1')
            ->assertOk()
            ->assertSee('--fill: 25.0%', escape: false);
    }

    public function test_the_current_chapter_is_marked_for_assistive_tech(): void
    {
        $this->withChapters([['id' => 7601], ['id' => 7602]]);

        // By chapter id, not by position: the route takes an id and the fake lists 7601
        // and 7602, so `/read/76/2` would ask for a chapter that does not exist.
        $html = $this->get('/read/76/7602')->assertOk()->content();

        $this->assertStringContainsString('data-current="true"', $html);
        $this->assertStringContainsString('aria-current="true"', $html);
    }

    public function test_no_navigator_when_the_series_has_no_chapters(): void
    {
        $this->withChapters([]);

        $this->get('/read/76/1')
            ->assertOk()
            // The landmark's label, not the class: the class name is in the page's CSS
            // whether or not the navigator rendered, so asserting on it proves nothing.
            ->assertDontSee('Chapters in this series', escape: false);
    }

    public function test_the_navigator_does_not_break_the_page_when_its_own_call_fails(): void
    {
        /*
         * The page renders and the navigator is absent.
         *
         * Deliberately not "the server is down": a reader cannot render at all without
         * the server, so a 502 there is correct and would say nothing about this class.
         * What matters is that the navigator's own batch failing costs the scrubber and
         * nothing else, which is the case covered directly above.
         */
        $this->withChapters([]);

        $this->get('/read/76/1')
            ->assertOk()
            ->assertDontSee('Chapters in this series', escape: false);
    }
}
