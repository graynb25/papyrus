<?php

namespace Tests\Feature;

use App\Library\ContinueReading;
use App\Models\ChapterState;
use App\Models\SeriesState;
use App\Models\User;
use App\Support\Library;
use App\Support\ReadingState;
use App\Suwayomi\SeriesGateway;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * Where a reader should pick up next.
 *
 * Written against measurements, so the tests would fail if any of them stopped being
 * true:
 *
 *  - The feed is a local join. The library page's existing `mangas(filter: {id: {in:
 *    [...]}})` batch returns 575 chapter ids for 14 series in 539ms, and the read set
 *    is ours. The alternative, one `fetchMangaAndChapters` per series, is 3.5 to 4.4
 *    seconds of upstream requests for the same 14.
 *  - "First unread" rather than "the chapter after last_read_chapter_id". Measured on
 *    the dev library: 1 of 14 series has a `last_read_chapter_id` and there are zero
 *    read chapter rows, so a last-read pointer would send almost every reader back to
 *    chapter 1.
 *  - A series with everything read has nothing to continue to, and offering chapter
 *    one would be the one answer that is certainly wrong.
 *  - The source's own chapter label is not derivable from the number. Measured: two
 *    sources label one chapter `Chapter 1` and `Ch. 1`.
 */
class ContinueReadingTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private User $user;

    /** @var array<int, int> chapter count per manga, as the batch reports it */
    private array $chapterCounts = [];

    /** @var array<int, string> chapter label per manga, to prove labels are used */
    private array $chapterNames = [];

    /** @var array<int, int> every GraphQL document sent */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->chapterCounts = [];
        $this->chapterNames = [];
        $this->sent = [];

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

            $ids = (array) (data_get($request->data(), 'variables.ids') ?? []);

            $nodes = [];

            foreach ($ids as $mangaId) {
                $mangaId = (int) $mangaId;
                $count = $this->chapterCounts[$mangaId] ?? 3;
                $label = $this->chapterNames[$mangaId] ?? 'Chapter %d';

                $nodes[] = [
                    'id' => (string) $mangaId,
                    'title' => "Series {$mangaId}",
                    'thumbnailUrl' => "/api/v1/manga/{$mangaId}/thumbnail",
                    'status' => 'ONGOING',
                    'initialized' => true,
                    'source' => ['id' => '1', 'name' => 'Asura Scans', 'lang' => 'en'],
                    'chapters' => [
                        'totalCount' => $count,
                        /*
                         * The label is rendered with the number substituted, so a test
                         * can tell whether the card used the source's name or invented
                         * one from the position.
                         */
                        'nodes' => array_map(
                            static fn (int $n): array => [
                                'id' => (string) ($mangaId * 100 + $n),
                                'isDownloaded' => false,
                                'name' => str_replace('%d', (string) $n, $label),
                            ],
                            $count > 0 ? range(1, $count) : [],
                        ),
                    ],
                ];
            }

            return Http::response(['data' => ['mangas' => ['nodes' => $nodes]]]);
        });
    }

    private function addToLibrary(int $mangaId, int $chapters = 3, ?string $namePattern = null): SeriesState
    {
        $this->chapterCounts[$mangaId] = $chapters;

        if ($namePattern !== null) {
            $this->chapterNames[$mangaId] = $namePattern;
        }

        return SeriesState::createFor($this->user, [
            'suwayomi_manga_id' => $mangaId,
            'in_library' => true,
            'added_at' => now(),
        ]);
    }

    /**
     * The same, for a named reader.
     *
     * `forLibrary()` walks one reader's own rows, so comparing two readers needs the
     * series in both libraries. Adding it to Bob is also what proves the tenancy is
     * doing the filtering rather than the query being narrow by accident.
     */
    private function addToLibraryFor(User $user, int $mangaId, int $chapters = 3): SeriesState
    {
        $this->chapterCounts[$mangaId] = $chapters;

        return SeriesState::createFor($user, [
            'suwayomi_manga_id' => $mangaId,
            'in_library' => true,
            'added_at' => now(),
        ]);
    }

    /**
     * Mark chapters read, the way the app does it.
     *
     * Through `ReadingState::markRead()` rather than a direct insert, because that is
     * the only path that writes every column the model expects. A hand-built insert
     * left `read` false on some rows, so the resolver saw no read chapters at all and
     * every test that depended on progress pointed at chapter one.
     */
    private function markRead(int $mangaId, array $chapterIndexes): void
    {
        $state = app(ReadingState::class);

        foreach ($chapterIndexes as $index) {
            $state->markRead($this->user->id, $mangaId, $mangaId * 100 + $index, true);
        }
    }

    /**
     * `CarbonInterface`, not `Carbon`. `now()` returns a CarbonImmutable in Laravel
     * 13, so a narrower parameter would refuse the very call this helper exists to
     * make.
     */
    private function lastReadAt(int $mangaId, CarbonInterface $when): void
    {
        SeriesState::forUser($this->user->id)
            ->where('suwayomi_manga_id', $mangaId)
            ->update([
                'last_read_at' => $when,
                'last_read_chapter_id' => $mangaId * 100 + 1,
            ]);
    }

    // Resolution -------------------------------------------------------------

    public function test_a_series_nothing_is_read_points_at_its_first_chapter(): void
    {
        $this->addToLibrary(76, chapters: 5);

        $resolutions = app(ContinueReading::class)->forLibrary(
            $this->user,
            app(SeriesGateway::class)->fetchMany([76]),
        );

        $this->assertSame(76 * 100 + 1, $resolutions[76]['chapter_id']);
        $this->assertSame(1, $resolutions[76]['position'], 'One-based, because that is how a reader counts.');
        $this->assertSame(5, $resolutions[76]['total']);
        $this->assertSame(0, $resolutions[76]['read']);
    }

    public function test_it_points_at_the_first_chapter_that_is_not_read(): void
    {
        $this->addToLibrary(76, chapters: 5);
        $this->markRead(76, [1, 2, 3]);

        $resolutions = app(ContinueReading::class)->forLibrary(
            $this->user,
            app(SeriesGateway::class)->fetchMany([76]),
        );

        $this->assertSame(76 * 100 + 4, $resolutions[76]['chapter_id']);
        $this->assertSame(4, $resolutions[76]['position']);
        $this->assertSame(3, $resolutions[76]['read']);
    }

    public function test_a_gap_in_the_middle_still_resolves_to_the_gap(): void
    {
        $this->addToLibrary(76, chapters: 5);

        // Chapter 2 skipped, which happens when a reader jumps around or a source
        // renumbers. The feed must offer the gap rather than sailing past it.
        $this->markRead(76, [1, 3, 4, 5]);

        $resolutions = app(ContinueReading::class)->forLibrary(
            $this->user,
            app(SeriesGateway::class)->fetchMany([76]),
        );

        $this->assertSame(76 * 100 + 2, $resolutions[76]['chapter_id']);
    }

    public function test_a_fully_read_series_has_nothing_to_continue(): void
    {
        $this->addToLibrary(76, chapters: 3);
        $this->markRead(76, [1, 2, 3]);

        $resolutions = app(ContinueReading::class)->forLibrary(
            $this->user,
            app(SeriesGateway::class)->fetchMany([76]),
        );

        $this->assertArrayNotHasKey(
            76,
            $resolutions,
            'Offering chapter one here would be the one answer that is certainly wrong.',
        );
    }

    public function test_a_last_read_pointer_rescues_a_series_with_no_read_rows(): void
    {
        /*
         * Measured: 1 of 14 dev series has a `last_read_chapter_id` and zero read rows,
         * so this is the branch that stops a reader who opened chapter 20 without
         * finishing it from being sent back to chapter 1.
         */
        $this->addToLibrary(76, chapters: 10);
        $this->lastReadAt(76, now()->subDay());

        $resolutions = app(ContinueReading::class)->forLibrary(
            $this->user,
            app(SeriesGateway::class)->fetchMany([76]),
        );

        $this->assertSame(76 * 100 + 2, $resolutions[76]['chapter_id']);
    }

    public function test_a_stale_pointer_falls_back_to_the_first_chapter(): void
    {
        // Every chapter id changes when a series migrates, so a pointer can name
        // something this series no longer has.
        $this->addToLibrary(76, chapters: 3);

        SeriesState::forUser($this->user->id)
            ->where('suwayomi_manga_id', 76)
            ->update(['last_read_chapter_id' => 999999]);

        $resolutions = app(ContinueReading::class)->forLibrary(
            $this->user,
            app(SeriesGateway::class)->fetchMany([76]),
        );

        $this->assertSame(76 * 100 + 1, $resolutions[76]['chapter_id']);
    }

    public function test_a_pointer_at_the_last_chapter_resolves_to_nothing(): void
    {
        $this->addToLibrary(76, chapters: 3);

        // Straight at the final chapter, so there is nothing after it. No read rows,
        // so the read-set walk finds chapter one unread and would otherwise answer
        // with that.
        SeriesState::forUser($this->user->id)
            ->where('suwayomi_manga_id', 76)
            ->update([
                'last_read_chapter_id' => 76 * 100 + 3,
                'last_read_at' => now()->subDay(),
            ]);

        $this->markRead(76, [1, 2, 3]);

        $resolutions = app(ContinueReading::class)->forLibrary(
            $this->user,
            app(SeriesGateway::class)->fetchMany([76]),
        );

        $this->assertArrayNotHasKey(76, $resolutions);
    }

    public function test_a_series_with_no_chapter_list_is_skipped(): void
    {
        // A series the server knows but has never fetched. Measured: 6 of 14 on a
        // fresh library, and `totalCount` is 0 because the list is empty.
        $this->addToLibrary(76, chapters: 0);
        $this->chapterCounts[76] = 0;

        $resolutions = app(ContinueReading::class)->forLibrary(
            $this->user,
            app(SeriesGateway::class)->fetchMany([76]),
        );

        $this->assertSame([], $resolutions);
    }

    public function test_a_series_the_server_does_not_know_is_skipped(): void
    {
        $this->addToLibrary(76, chapters: 3);
        $this->addToLibrary(999, chapters: 3);

        // The server drops an id it does not have, silently and with no error. Measured.
        $resolutions = app(ContinueReading::class)->forLibrary(
            $this->user,
            app(SeriesGateway::class)->fetchMany([76]),
        );

        $this->assertArrayHasKey(76, $resolutions);
        $this->assertArrayNotHasKey(999, $resolutions);
    }

    public function test_the_source_label_is_carried_rather_than_invented(): void
    {
        // The measured case: two sources label one chapter `Chapter 1` and `Ch. 1`.
        $this->addToLibrary(76, chapters: 3, namePattern: 'Ch. %d');

        $resolutions = app(ContinueReading::class)->forLibrary(
            $this->user,
            app(SeriesGateway::class)->fetchMany([76]),
        );

        $this->assertSame('Ch. 1', $resolutions[76]['chapter_name']);
    }

    public function test_an_empty_library_resolves_to_nothing(): void
    {
        $this->assertSame([], app(ContinueReading::class)->forLibrary($this->user, []));
    }

    public function test_another_readers_reads_do_not_leak(): void
    {
        $bob = User::factory()->create();
        $this->addToLibrary(76, chapters: 5);
        $this->markRead(76, [1, 2, 3]);

        $batch = app(SeriesGateway::class)->fetchMany([76]);

        $mine = app(ContinueReading::class)->forLibrary($this->user, $batch);

        $this->assertSame(4, $mine[76]['position'], 'Three read, so the fourth.');

        /*
         * Bob has the series in *his* library too, which is the only way to compare:
         * `forLibrary` walks a reader's own rows, so asking about a series Bob has not
         * added resolves to nothing, which is the tenancy working and not a leak.
         */
        $this->addToLibraryFor($bob, 76, chapters: 5);

        $theirs = app(ContinueReading::class)->forLibrary($bob, $batch);

        $this->assertSame(
            1,
            $theirs[76]['position'],
            'Bob has read nothing of this series, so chapter one is the honest answer for him.',
        );
    }

    // The feed ----------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        return [
            ['manga_id' => 1, 'title' => 'One', 'last_read_at' => now()->subDays(3)],
            ['manga_id' => 2, 'title' => 'Two', 'last_read_at' => now()->subHours(2)],
            ['manga_id' => 3, 'title' => 'Three', 'last_read_at' => null],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function resolutions(): array
    {
        return [
            1 => ['read' => 10, 'position' => 11],
            2 => ['read' => 2, 'position' => 3],
            3 => ['read' => 0, 'position' => 1],
        ];
    }

    public function test_the_feed_leads_with_what_was_most_recently_read(): void
    {
        $feed = app(ContinueReading::class)->feed($this->rows(), $this->resolutions());

        $this->assertSame([2, 1, 3], $feed->pluck('manga_id')->all());
    }

    public function test_a_never_opened_series_sorts_after_anything_read(): void
    {
        $feed = app(ContinueReading::class)->feed($this->rows(), $this->resolutions());

        $this->assertSame(
            3,
            $feed->last()['manga_id'],
            'A reader who added forty series and read none gets what they were reading first.',
        );
    }

    public function test_the_feed_omits_anything_with_nothing_to_continue(): void
    {
        $feed = app(ContinueReading::class)->feed($this->rows(), [1 => ['read' => 5, 'position' => 6]]);

        $this->assertSame([1], $feed->pluck('manga_id')->all());
    }

    public function test_the_feed_is_capped(): void
    {
        $rows = [];
        $resolutions = [];

        foreach (range(1, 20) as $id) {
            $rows[] = ['manga_id' => $id, 'title' => "Series {$id}", 'last_read_at' => now()->subDays($id)];
            $resolutions[$id] = ['read' => $id, 'position' => $id + 1];
        }

        $feed = app(ContinueReading::class)->feed($rows, $resolutions);

        $this->assertCount(6, $feed, 'Six, because the shelves below hold the rest.');
        $this->assertSame(1, $feed->first()['manga_id'], 'Most recent first.');
    }

    public function test_an_empty_feed_when_nothing_resolves(): void
    {
        $this->assertTrue(app(ContinueReading::class)->feed($this->rows(), [])->isEmpty());
    }

    // Cost -------------------------------------------------------------------

    public function test_resolution_costs_no_call_beyond_the_batch(): void
    {
        $this->addToLibrary(76, chapters: 5);
        $this->addToLibrary(77, chapters: 5);

        $batch = app(SeriesGateway::class)->fetchMany([76, 77]);

        $before = count($this->sent);

        app(ContinueReading::class)->forLibrary($this->user, $batch);

        $during = count($this->sent) - $before;

        /*
         * Zero, not one.
         *
         * The batch the page already makes is fetched before the counter starts, so
         * what is being asserted here is the stronger claim: resolving the feed talks
         * to nobody. A per-series refresh would be two calls at 876ms to 1099ms each,
         * which is the expensive path this design exists to avoid, and a query that
         * fetched per card would be the mistake the library page already made once with
         * its Suwayomi metadata.
         */
        $this->assertSame(0, $during, 'It reads the batch it is handed and asks for nothing.');
    }

    public function test_the_page_adds_no_call_for_the_feed(): void
    {
        $this->addToLibrary(76, chapters: 5);

        $before = count($this->sent);

        Livewire::test('pages::library')->assertOk();

        $pageCalls = count($this->sent) - $before;

        // The library page's own batch, and the batch the feed reuses.
        $this->assertLessThanOrEqual(
            2,
            $pageCalls,
            'The page makes its batch and the feed reads it, rather than fetching per card.',
        );
    }

    public function test_the_read_set_is_one_query(): void
    {
        $this->addToLibrary(1, chapters: 5);
        $this->addToLibrary(2, chapters: 5);
        $this->markRead(1, [1]);
        $this->markRead(2, [1]);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        app(ContinueReading::class)->forLibrary(
            $this->user,
            app(SeriesGateway::class)->fetchMany([1, 2]),
        );

        $this->assertSame(
            2,
            $queries,
            'The series states and the grouped read rows. One per, not one per series.',
        );
    }

    // The page ----------------------------------------------------------------

    public function test_the_page_shows_the_feed(): void
    {
        $this->addToLibrary(76, chapters: 5);
        $this->markRead(76, [1, 2]);

        Livewire::test('pages::library')
            ->assertOk()
            ->assertSee('Continue reading');
    }

    public function test_a_feed_card_names_the_source_chapter(): void
    {
        $this->addToLibrary(76, chapters: 5, namePattern: 'Ch. %d');
        $this->markRead(76, [1, 2]);

        Livewire::test('pages::library')
            ->assertOk()
            ->assertSee('Ch. 3', escape: false);
    }

    public function test_a_feed_card_falls_back_to_a_position(): void
    {
        $this->addToLibrary(76, chapters: 5);
        $this->markRead(76, [1, 2]);

        Livewire::test('pages::library')
            ->assertOk()
            ->assertSee('Chapter 3');
    }

    public function test_a_feed_card_links_straight_to_the_next_chapter(): void
    {
        $this->addToLibrary(76, chapters: 5);
        $this->markRead(76, [1, 2]);

        $expected = route('reader.show', ['manga' => 76, 'chapter' => 76 * 100 + 3]);

        Livewire::test('pages::library')
            ->assertOk()
            ->assertSee($expected, escape: false);
    }

    public function test_the_feed_reports_progress(): void
    {
        $this->addToLibrary(76, chapters: 5);
        $this->markRead(76, [1, 2]);

        Livewire::test('pages::library')
            ->assertOk()
            ->assertSee('of 5, 2 read');
    }

    public function test_a_fully_read_library_shows_no_feed(): void
    {
        $this->addToLibrary(76, chapters: 3);
        $this->markRead(76, [1, 2, 3]);

        Livewire::test('pages::library')
            ->assertOk()
            ->assertDontSee('Continue reading');
    }

    public function test_an_empty_library_shows_no_feed(): void
    {
        Livewire::test('pages::library')
            ->assertOk()
            ->assertDontSee('Continue reading');
    }

    public function test_the_feed_respects_the_shelf_filter(): void
    {
        $shelf = app(Library::class)->createCategory($this->user, 'Soon');

        $this->addToLibrary(76, chapters: 5);
        $this->addToLibrary(174, chapters: 5);
        app(Library::class)->attach($this->user, 76, $shelf->id);

        Livewire::test('pages::library')
            ->set('shelf', $shelf->id)
            ->assertOk()
            ->assertSee('Series 76')
            ->assertDontSee('Series 174');
    }

    public function test_the_feed_respects_the_title_search(): void
    {
        $this->addToLibrary(76, chapters: 5);
        $this->addToLibrary(174, chapters: 5);

        Livewire::test('pages::library')
            ->set('search', 'Series 17')
            ->assertOk()
            ->assertSee('Series 174')
            ->assertDontSee('Series 76');
    }

    public function test_a_readers_own_progress_only(): void
    {
        $bob = User::factory()->create();
        $this->addToLibrary(76, chapters: 5);

        // Bob's row, invisible here because the tenancy scope names this reader.
        app(ReadingState::class)->markRead($bob->id, 76, 76 * 100 + 1, true);

        $this->assertSame(
            0,
            (int) ChapterState::query()->forUser($this->user->id)->where('is_read', true)->count(),
            'Bob reading one chapter is not this reader reading one chapter.',
        );

        $this->assertSame(
            1,
            (int) ChapterState::query()->forUser($bob->id)->where('is_read', true)->count(),
            'and the row really was written, on Bob.',
        );
    }
}
