<?php

namespace Tests\Feature;

use App\Jobs\CheckLibrary;
use App\Library\CheckSchedule;
use App\Models\SeriesState;
use App\Models\User;
use App\Support\Library;
use App\Support\ReadingState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * The library page, and the arithmetic behind it.
 *
 * The interesting test here is
 * `test_the_page_makes_one_batch_call_however_many_cards_it_renders`. Everything
 * else in this file could pass while the page issued one Suwayomi round trip per
 * card, because a fake answers in microseconds and thirty of them still finish
 * inside the test's time budget. Only a counter catches that, and only the counter
 * caught it after a live page spent thirty seconds on a fourteen series library.
 *
 * Every way the server can behave is a property rather than a second `Http::fake()`,
 * because Laravel keeps the earliest matching stub and a second fake silently serves
 * the first one's data. Trap 23.
 */
class LibraryTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private User $user;

    /** @var array<int, string> every GraphQL document the page sent */
    private array $sent = [];

    /** @var array<int, int> every id currently in the library */
    private array $libraryIds = [];

    /**
     * Manga ids the fake server knows, with their shape.
     *
     * @var array<int, array{chapters?: int, initialized?: bool, title?: string}>
     */
    private array $knownIds = [];

    /** @var array<int, true> ids the server has never heard of */
    private array $unknownIds = [];

    /** When true, everything but the login fails, as a dead upstream would. */
    private bool $serverDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->sent = [];
        $this->libraryIds = [];
        $this->knownIds = [];
        $this->unknownIds = [];
        $this->serverDown = false;

        $this->fakeSuwayomi();
    }

    // The fake ------------------------------------------------------------

    private function fakeSuwayomi(): void
    {
        Http::fake(function (Request $request) {
            $query = $request->data()['query'] ?? '';
            $this->sent[] = $query;

            if (str_contains($query, 'mutation Login')) {
                return Http::response([
                    'data' => ['login' => [
                        'accessToken' => $this->accessToken(),
                        'refreshToken' => $this->refreshToken(),
                    ]],
                ]);
            }

            if ($this->serverDown) {
                return Http::response('gateway down', 503);
            }

            if (str_contains($query, 'mutation Refresh')) {
                return Http::response(['data' => ['fetchMangaAndChapters' => ['manga' => null, 'chapters' => []]]]);
            }

            if (str_contains($query, 'mangas(')) {
                return Http::response(['data' => ['mangas' => ['nodes' => $this->sourceRows()]]]);
            }

            return Http::response(['data' => ['manga' => null]]);
        });
    }

    /**
     * A row per library entry the server knows about.
     *
     * An id in `unknownIds` gets no row at all, which is what the real server does
     * with an id it has never seen: drop it silently rather than error. Measured.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sourceRows(): array
    {
        $rows = [];

        foreach ($this->libraryIds as $mangaId) {
            if (isset($this->unknownIds[$mangaId])) {
                continue;
            }

            $known = $this->knownIds[$mangaId] ?? [];
            $chapters = $known['chapters'] ?? 10;

            $rows[] = [
                'id' => (string) $mangaId,
                'title' => $known['title'] ?? "Series {$mangaId}",
                'thumbnailUrl' => "/api/v1/manga/{$mangaId}/thumbnail",
                'status' => 'ONGOING',
                // A row Suwayomi knows but has never fetched reports initialized
                // false and a chapter total of zero. Both are load-bearing.
                'initialized' => $known['initialized'] ?? true,
                'source' => ['id' => '6247824327199706550', 'name' => 'Asura Scans', 'lang' => 'en'],
                'chapters' => [
                    'totalCount' => $chapters,
                    'nodes' => array_map(
                        fn (int $n): array => ['id' => (string) ($mangaId * 100 + $n)],
                        range(1, $chapters)
                    ),
                ],
            ];
        }

        return $rows;
    }

    /**
     * Put a series in the library.
     *
     * @param  array{chapters?: int, initialized?: bool, title?: string}  $shape
     */
    private function addToLibrary(int $mangaId, array $shape = []): void
    {
        $this->libraryIds[] = $mangaId;

        $this->knownIds[$mangaId] = $shape + ['chapters' => 10, 'initialized' => true];

        app(ReadingState::class)->setInLibrary($this->user, $mangaId, true);
    }

    /** Put a series in the library that the server has never heard of. */
    private function addUnknownToLibrary(int $mangaId): void
    {
        $this->libraryIds[] = $mangaId;
        $this->unknownIds[$mangaId] = true;

        app(ReadingState::class)->setInLibrary($this->user, $mangaId, true);
    }

    /** Mark chapters read, so unread has something to subtract. */
    private function markRead(int $mangaId, int $count): void
    {
        $state = app(ReadingState::class);

        for ($n = 1; $n <= $count; $n++) {
            $state->markRead($this->user->id, $mangaId, $mangaId * 100 + $n, true);
        }
    }

    private function library(): Library
    {
        return app(Library::class);
    }

    private function page(): Testable
    {
        return Livewire::test('pages::library');
    }

    /** How many batch queries have been sent so far. */
    private function batchCalls(): int
    {
        return count(array_filter($this->sent, fn (string $q): bool => str_contains($q, 'mangas(')));
    }

    // The batch call ------------------------------------------------------

    /**
     * The guard for the bug that cost thirty seconds.
     *
     * Every card renders a filing button per shelf, so a naive implementation asks
     * once per card per shelf and the page issues one Suwayomi round trip each
     * time. Against a fake that is invisible. Against the real server it is a
     * thirty second timeout, which is how it was found.
     */
    public function test_the_page_makes_one_batch_call_however_many_cards_it_renders(): void
    {
        foreach (range(1, 8) as $id) {
            $this->addToLibrary($id);
        }

        $soon = $this->library()->createCategory($this->user, 'Soon');
        $this->library()->createCategory($this->user, 'Later');

        foreach (range(1, 8) as $id) {
            $this->library()->attach($this->user, $id, $soon->id);
        }

        $this->page()->assertOk();

        $this->assertSame(
            1,
            $this->batchCalls(),
            'Eight cards and two shelves should need one batch call, not sixteen.'
        );
    }

    /**
     * The database query count must not grow with the number of shelves either,
     * since every card renders one filing button per shelf.
     *
     * Measured as a comparison rather than a ceiling, because a ceiling is the wrong shape
     * for this claim: it can be satisfied by raising the number. One shelf and five shelves
     * must cost the same, and any per-shelf query shows up as a difference instead of as
     * an absolute number that needs re-tuning every time the page gains a lookup.
     *
     * The ceiling is still there, as a small constant, because "the same as one shelf" with
     * no upper bound would pass a page that issued zero queries by rendering nothing.
     */
    public function test_the_query_count_does_not_grow_with_the_number_of_shelves(): void
    {
        $this->addToLibrary(76);

        // One shelf first, so there is something to compare five against.
        $this->library()->createCategory($this->user, 'Only');

        $withOne = $this->queriesForOneRefresh();

        foreach (['Two', 'Three', 'Four', 'Five'] as $name) {
            $this->library()->createCategory($this->user, $name);
        }

        $withFive = $this->queriesForOneRefresh();

        $this->assertSame(
            $withOne,
            $withFive,
            sprintf('One shelf cost %d queries and five cost %d.', $withOne, $withFive),
        );

        // A small constant: shelves, series, filings, read counts, and this reader's
        // custom covers. Five shelves and five filing buttons per card is 26 controls.
        $this->assertLessThan(14, $withFive, 'Five shelves on one card produced '.$withFive.' queries.');
    }

    /**
     * Queries for one refresh of the library page, with no shelves yet added.
     *
     * The count is taken after the component has mounted and been asserted on, because
     * mounting itself issues queries that have nothing to do with what a refresh costs.
     */
    private function queriesForOneRefresh(): int
    {
        $page = $this->page();
        $page->assertOk();

        $queries = 0;

        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $page->call('$refresh');

        return $queries;
    }

    public function test_the_batch_call_asks_for_every_id_at_once(): void
    {
        foreach ([76, 174, 175] as $id) {
            $this->addToLibrary($id);
        }

        $this->page()->assertOk();

        $batches = array_values(array_filter($this->sent, fn (string $q): bool => str_contains($q, 'mangas(')));

        $this->assertCount(1, $batches);
        $this->assertStringContainsString('in: $ids', $batches[0]);
    }

    /**
     * The filter is an Int filter while the field comes back as a LongString, so
     * the variable has to be declared `[Int!]!`. Declaring it `[LongString!]!` is a
     * type mismatch and `[Long!]!` is an unknown type; both measured against the
     * live server.
     */
    public function test_the_batch_call_declares_its_ids_as_ints(): void
    {
        $this->addToLibrary(76);
        $this->page()->assertOk();

        $batch = array_values(array_filter($this->sent, fn (string $q): bool => str_contains($q, 'mangas(')))[0];

        $this->assertStringContainsString('$ids: [Int!]!', $batch);
        $this->assertStringNotContainsString('Long', $batch);
    }

    // Unread --------------------------------------------------------------

    public function test_unread_is_the_source_total_minus_what_this_reader_read(): void
    {
        $this->addToLibrary(76, ['chapters' => 41]);
        $this->markRead(76, 3);

        $entry = $this->library()->entries($this->user)->first();

        $this->assertSame(41, $entry['total_chapters']);
        $this->assertSame(3, $entry['read_chapters']);
        $this->assertSame(38, $entry['unread_chapters']);
    }

    /**
     * Suwayomi's own unreadCount is computed from Admin(1)'s read state, so it is
     * never what this reader wants. It is not even asked for.
     */
    public function test_suwayomis_unread_count_is_never_requested(): void
    {
        $this->addToLibrary(76);
        $this->page()->assertOk();

        foreach ($this->sent as $query) {
            $this->assertStringNotContainsString('unreadCount', $query);
        }
    }

    public function test_unread_is_never_negative(): void
    {
        // A source that drops chapters can leave us having read more than it now
        // reports. A negative count on a card is worse than a zero.
        $this->addToLibrary(76, ['chapters' => 2]);
        $this->markRead(76, 9);

        $this->assertSame(0, $this->library()->entries($this->user)->first()['unread_chapters']);
    }

    /**
     * A series Suwayomi knows but has never fetched has `totalCount` 0 because the
     * list is empty, not because the series has none. Showing 0 unread there says
     * "caught up" about something nobody has looked at.
     */
    public function test_a_series_whose_chapters_were_never_fetched_has_no_count(): void
    {
        $this->addToLibrary(76, ['chapters' => 0, 'initialized' => false]);

        $entry = $this->library()->entries($this->user)->first();

        $this->assertNull($entry['unread_chapters']);
        $this->assertNull($entry['total_chapters']);
        $this->assertTrue($entry['has_source_data'], 'The server does know the series.');
        $this->assertFalse($entry['has_chapter_list']);
    }

    public function test_a_series_the_server_has_never_heard_of_is_reported_as_unknown(): void
    {
        $this->addUnknownToLibrary(76);

        $entry = $this->library()->entries($this->user)->first();

        $this->assertFalse($entry['has_source_data']);
        $this->assertNull($entry['unread_chapters']);
        $this->assertNull($entry['cover']);
        $this->assertSame(76, $entry['manga_id'], 'It still has to be linkable.');
    }

    /** A dead upstream must not take the page with it. */
    public function test_an_unreachable_server_leaves_a_readable_page(): void
    {
        $this->addToLibrary(76, ['chapters' => 41]);
        $this->serverDown = true;

        $this->page()
            ->assertOk()
            ->assertSee('Not on the server');
    }

    // Staleness -----------------------------------------------------------

    public function test_a_changed_chapter_list_is_flagged_rather_than_silently_miscounted(): void
    {
        $this->addToLibrary(76, ['chapters' => 41]);

        // Record a two chapter list, then have the source report 41.
        $this->library()->recordChapterFingerprint($this->user, 76, [
            ['id' => 7601], ['id' => 7602],
        ]);

        $this->assertTrue($this->library()->entries($this->user)->first()['needs_refresh']);
    }

    public function test_a_series_never_opened_is_not_flagged_as_stale(): void
    {
        // Half a new library has no fingerprint. Flagging those would mark the
        // whole thing stale on the day it was made.
        $this->addToLibrary(76, ['chapters' => 41]);

        $this->assertFalse($this->library()->entries($this->user)->first()['needs_refresh']);
    }

    public function test_an_unchanged_chapter_list_is_not_flagged(): void
    {
        $this->addToLibrary(76, ['chapters' => 3]);

        $this->library()->recordChapterFingerprint($this->user, 76, [
            ['id' => 7601], ['id' => 7602], ['id' => 7603],
        ]);

        $this->assertFalse($this->library()->entries($this->user)->first()['needs_refresh']);
    }

    /**
     * The digest is over the ids in order, not the count. Renumbering chapters
     * leaves the count identical and every reader's unread total wrong.
     */
    public function test_the_fingerprint_notices_a_renumber(): void
    {
        $before = Library::chapterFingerprint([['id' => '1'], ['id' => '2'], ['id' => '3']]);
        $after = Library::chapterFingerprint([['id' => '1'], ['id' => '3'], ['id' => '2']]);

        $this->assertNotSame($before, $after);
    }

    public function test_the_fingerprint_ignores_anything_but_the_ids(): void
    {
        // A source changing a chapter's name or number must not make every card
        // on the page claim it is stale.
        $before = Library::chapterFingerprint([['id' => '1', 'name' => 'Chapter 1']]);
        $after = Library::chapterFingerprint([['id' => '1', 'name' => 'Ch. 1', 'chapterNumber' => 1.5]]);

        $this->assertSame($before, $after);
    }

    // Shelves -------------------------------------------------------------

    public function test_a_shelf_is_created_with_the_readers_own_order(): void
    {
        $first = $this->library()->createCategory($this->user, 'One');
        $second = $this->library()->createCategory($this->user, 'Two');

        $this->assertSame(0, $first->order_column);
        $this->assertSame(1, $second->order_column);
    }

    public function test_creating_a_shelf_twice_returns_the_same_one(): void
    {
        $first = $this->library()->createCategory($this->user, 'Reading soon');
        $again = $this->library()->createCategory($this->user, 'Reading soon');

        $this->assertSame($first->id, $again->id);
        $this->assertDatabaseCount('categories', 1);
    }

    public function test_an_empty_shelf_name_is_refused(): void
    {
        $this->assertNull($this->library()->createCategory($this->user, '   '));
        $this->assertDatabaseCount('categories', 0);
    }

    public function test_shelves_are_per_user(): void
    {
        $bob = User::factory()->create();

        $this->library()->createCategory($this->user, 'Mine');
        $this->library()->createCategory($bob, 'Theirs');

        $this->assertSame(['Mine'], $this->library()->categories($this->user)->pluck('name')->all());
        $this->assertSame(['Theirs'], $this->library()->categories($bob)->pluck('name')->all());
    }

    public function test_a_shelf_can_be_renamed(): void
    {
        $shelf = $this->library()->createCategory($this->user, 'Old');

        $this->library()->renameCategory($this->user, $shelf->id, 'New');

        $this->assertDatabaseHas('categories', ['id' => $shelf->id, 'name' => 'New']);
    }

    public function test_someone_elses_shelf_cannot_be_renamed_or_deleted(): void
    {
        $bob = User::factory()->create();
        $theirs = $this->library()->createCategory($bob, 'Theirs');

        $this->assertNull($this->library()->renameCategory($this->user, $theirs->id, 'Mine now'));
        $this->assertFalse($this->library()->deleteCategory($this->user, $theirs->id));

        $this->assertDatabaseHas('categories', ['id' => $theirs->id, 'name' => 'Theirs']);
    }

    public function test_deleting_a_shelf_keeps_the_series_and_unfiles_them(): void
    {
        $shelf = $this->library()->createCategory($this->user, 'Doomed');
        $this->addToLibrary(76);
        $this->library()->attach($this->user, 76, $shelf->id);

        $this->assertTrue($this->library()->deleteCategory($this->user, $shelf->id));

        $this->assertDatabaseCount('categories', 0);
        $this->assertDatabaseCount('category_series', 0);

        $state = SeriesState::forUser($this->user->id)->where('suwayomi_manga_id', 76)->first();

        $this->assertNotNull($state);
        $this->assertTrue((bool) $state->in_library);
        $this->assertNull($state->default_category_id);
    }

    public function test_shelf_counts_come_from_one_grouped_query(): void
    {
        $one = $this->library()->createCategory($this->user, 'One');
        $two = $this->library()->createCategory($this->user, 'Two');

        $this->addToLibrary(76);
        $this->addToLibrary(174);

        $this->library()->attach($this->user, 76, $one->id);
        $this->library()->attach($this->user, 76, $two->id);
        $this->library()->attach($this->user, 174, $one->id);

        $counts = $this->library()->categories($this->user)->pluck('count', 'id')->all();

        $this->assertSame(2, $counts[$one->id]);
        $this->assertSame(1, $counts[$two->id]);
    }

    // Filing ---------------------------------------------------------------

    public function test_a_series_can_be_filed_in_several_shelves(): void
    {
        $soon = $this->library()->createCategory($this->user, 'Soon');
        $webtoon = $this->library()->createCategory($this->user, 'Webtoons');

        $this->addToLibrary(76);
        $this->library()->attach($this->user, 76, $soon->id);
        $this->library()->attach($this->user, 76, $webtoon->id);

        $this->assertCount(2, $this->library()->filingsFor($this->user, [76])[76] ?? []);
    }

    public function test_filing_twice_does_not_duplicate_the_row(): void
    {
        $shelf = $this->library()->createCategory($this->user, 'Soon');
        $this->addToLibrary(76);

        $this->library()->attach($this->user, 76, $shelf->id);
        $this->library()->attach($this->user, 76, $shelf->id);

        $this->assertDatabaseCount('category_series', 1);
    }

    public function test_the_first_shelf_becomes_the_home_one_and_later_filings_do_not_move_it(): void
    {
        $first = $this->library()->createCategory($this->user, 'First');
        $second = $this->library()->createCategory($this->user, 'Second');
        $this->addToLibrary(76);

        $this->library()->attach($this->user, 76, $second->id);
        $this->library()->attach($this->user, 76, $first->id);

        $state = SeriesState::forUser($this->user->id)->where('suwayomi_manga_id', 76)->first();

        $this->assertSame($second->id, (int) $state->default_category_id);
    }

    /** Removing the home shelf must not leave the series pointing at it. */
    public function test_unfiling_the_home_shelf_moves_the_home_elsewhere(): void
    {
        $home = $this->library()->createCategory($this->user, 'Home');
        $other = $this->library()->createCategory($this->user, 'Other');
        $this->addToLibrary(76);

        $this->library()->attach($this->user, 76, $home->id);
        $this->library()->attach($this->user, 76, $other->id);
        $this->library()->detach($this->user, 76, $home->id);

        $state = SeriesState::forUser($this->user->id)->where('suwayomi_manga_id', 76)->first();

        $this->assertSame($other->id, (int) $state->default_category_id);
    }

    public function test_unfiling_the_last_shelf_leaves_the_series_unfiled_not_dangling(): void
    {
        $only = $this->library()->createCategory($this->user, 'Only');
        $this->addToLibrary(76);
        $this->library()->attach($this->user, 76, $only->id);

        $this->library()->detach($this->user, 76, $only->id);

        $state = SeriesState::forUser($this->user->id)->where('suwayomi_manga_id', 76)->first();

        $this->assertNull($state->default_category_id);
        $this->assertTrue((bool) $state->in_library, 'Unfiling is not removing.');
    }

    public function test_a_shelf_belonging_to_someone_else_cannot_be_filed_into(): void
    {
        $bob = User::factory()->create();
        $theirs = $this->library()->createCategory($bob, 'Theirs');

        $this->addToLibrary(76);

        $this->assertSame([], $this->library()->attach($this->user, 76, $theirs->id));
        $this->assertDatabaseCount('category_series', 0);
    }

    public function test_the_home_shelf_must_be_one_the_series_is_filed_in(): void
    {
        $filed = $this->library()->createCategory($this->user, 'Filed');
        $unfiled = $this->library()->createCategory($this->user, 'Unfiled');
        $this->addToLibrary(76);
        $this->library()->attach($this->user, 76, $filed->id);

        $this->library()->setDefault($this->user, 76, $unfiled->id);

        $state = SeriesState::forUser($this->user->id)->where('suwayomi_manga_id', 76)->first();

        $this->assertNull($state->default_category_id);
    }

    public function test_filings_are_per_user(): void
    {
        $bob = User::factory()->create();
        $mine = $this->library()->createCategory($this->user, 'Mine');
        $this->library()->createCategory($bob, 'Theirs');

        $this->addToLibrary(76);
        $this->library()->attach($this->user, 76, $mine->id);

        $this->assertNotSame([], $this->library()->filingsFor($this->user, [76]));
        $this->assertSame([], $this->library()->filingsFor($bob, [76]));
    }

    /**
     * The whole class avoids auth, because a scheduled command has nobody logged
     * in and the tenancy scope would narrow every query to `user_id is null`,
     * producing a write that does nothing and no error.
     */
    public function test_it_works_with_nobody_logged_in(): void
    {
        $shelf = $this->library()->createCategory($this->user, 'Queued');
        $this->addToLibrary(76);

        $this->library()->attach($this->user, 76, $shelf->id);

        auth()->logout();

        $this->assertCount(1, $this->library()->filingsFor($this->user, [76]), 'Filings must survive having no auth context.');
        $this->assertCount(1, $this->library()->categories($this->user));
        $this->assertCount(1, $this->library()->entries($this->user));
    }

    // The page ------------------------------------------------------------

    public function test_the_library_needs_authentication(): void
    {
        auth()->logout();

        $this->get('/library')->assertRedirect();
    }

    public function test_an_empty_library_says_so(): void
    {
        $this->page()
            ->assertOk()
            ->assertSee('Your library is empty')
            ->assertSee('Nothing added yet');
    }

    public function test_the_page_lists_the_series_and_their_progress(): void
    {
        $this->addToLibrary(76, ['chapters' => 41]);
        $this->markRead(76, 3);

        $this->page()
            ->assertOk()
            ->assertSee('Series 76')
            ->assertSee('3 of 41 read');
    }

    public function test_the_page_shows_the_unread_badge(): void
    {
        $this->addToLibrary(76, ['chapters' => 41]);
        $this->markRead(76, 3);

        $this->page()->assertSee('38');
    }

    public function test_the_page_filters_to_one_shelf(): void
    {
        $shelf = $this->library()->createCategory($this->user, 'Soon');
        $this->addToLibrary(76);
        $this->addToLibrary(174);
        $this->library()->attach($this->user, 76, $shelf->id);

        $this->page()
            ->set('shelf', $shelf->id)
            ->assertOk()
            ->assertSee('Series 76')
            ->assertDontSee('Series 174');
    }

    public function test_the_page_searches_by_title(): void
    {
        $this->addToLibrary(76);
        $this->addToLibrary(174);

        $this->page()
            ->set('search', 'Series 17')
            ->assertOk()
            ->assertSee('Series 174')
            ->assertDontSee('Series 76');
    }

    public function test_a_search_with_no_matches_explains_itself(): void
    {
        $this->addToLibrary(76);

        $this->page()
            ->set('search', 'zzzz')
            ->assertSee('Nothing matches that search');
    }

    public function test_sorting_by_unread_puts_the_most_waiting_first(): void
    {
        $this->addToLibrary(76, ['chapters' => 2]);
        $this->addToLibrary(174, ['chapters' => 90]);

        $this->page()->set('sort', 'unread')->assertOk();

        $this->assertSame(
            174,
            $this->page()->instance()->visibleEntries->first()['manga_id'],
            'The series with 90 unread chapters should be first.'
        );
    }

    /** A series with no count has nothing to sort by and belongs at the bottom. */
    public function test_unread_sorting_puts_never_fetched_series_last(): void
    {
        $this->addToLibrary(76, ['chapters' => 0, 'initialized' => false]);
        $this->addToLibrary(174, ['chapters' => 5]);

        $this->page()->set('sort', 'unread');

        $this->assertSame(174, $this->page()->instance()->visibleEntries->first()['manga_id']);
    }

    /** Landing on page 7 of a result set that now has two pages looks like an empty library. */
    public function test_changing_a_filter_returns_to_the_first_page(): void
    {
        foreach (range(1, 5) as $id) {
            $this->addToLibrary($id);
        }

        $page = $this->page()->set('page', 2);

        $this->assertSame(2, $page->instance()->page);

        $page->set('search', 'Series 1');

        $this->assertSame(1, $page->instance()->page);
    }

    public function test_an_unknown_sort_is_refused_rather_than_reaching_the_query(): void
    {
        $this->addToLibrary(76);

        $page = $this->page()->set('sort', "title'; drop table series_states; --");

        $this->assertSame('recent', $page->instance()->sort);
        $this->assertDatabaseCount('series_states', 1);
    }

    public function test_a_shelf_belonging_to_someone_else_is_not_used_as_a_filter(): void
    {
        $bob = User::factory()->create();
        $theirs = $this->library()->createCategory($bob, 'Theirs');
        $this->addToLibrary(76);

        $this->assertNull($this->page()->set('shelf', $theirs->id)->instance()->shelf);
    }

    public function test_a_negative_page_is_clamped(): void
    {
        $this->addToLibrary(76);

        $this->assertSame(1, $this->page()->set('page', -4)->instance()->page);
    }

    public function test_the_page_pages_at_sixty(): void
    {
        foreach (range(1, 70) as $id) {
            $this->addToLibrary($id);
        }

        $page = $this->page();

        $this->assertCount(60, $page->instance()->visibleEntries);
        $this->assertSame(2, $page->instance()->lastPage);

        $page->set('page', 2);

        $this->assertCount(10, $page->instance()->visibleEntries);
    }

    public function test_filing_from_the_page_writes_through(): void
    {
        $shelf = $this->library()->createCategory($this->user, 'Soon');
        $this->addToLibrary(76);

        $this->page()->call('toggleFiling', 76, $shelf->id)->assertOk();

        $this->assertDatabaseCount('category_series', 1);
    }

    public function test_filing_twice_from_the_page_unfiles_the_second_time(): void
    {
        $shelf = $this->library()->createCategory($this->user, 'Soon');
        $this->addToLibrary(76);

        $page = $this->page();
        $page->call('toggleFiling', 76, $shelf->id);
        $this->assertDatabaseCount('category_series', 1);

        $page->call('toggleFiling', 76, $shelf->id);

        $this->assertDatabaseCount('category_series', 0);
    }

    public function test_creating_a_shelf_from_the_page(): void
    {
        $this->addToLibrary(76);

        $this->page()->set('newShelfName', '  Webtoons  ')->call('createShelf')->assertOk();

        $this->assertDatabaseHas('categories', ['name' => 'Webtoons']);
    }

    public function test_a_blank_shelf_name_does_nothing(): void
    {
        $this->page()->set('newShelfName', '   ')->call('createShelf');

        $this->assertDatabaseCount('categories', 0);
    }

    public function test_deleting_the_shelf_being_viewed_leaves_the_filter(): void
    {
        $shelf = $this->library()->createCategory($this->user, 'Doomed');
        $this->addToLibrary(76);
        $this->library()->attach($this->user, 76, $shelf->id);

        $this->page()
            ->set('shelf', $shelf->id)
            ->call('deleteShelf', $shelf->id)
            ->assertOk();

        $this->assertNull($this->page()->instance()->shelf);
        $this->assertSame(1, $this->page()->instance()->page);
    }

    public function test_the_header_counts_the_whole_library_and_the_shelf_separately(): void
    {
        $soon = $this->library()->createCategory($this->user, 'Soon');
        $this->addToLibrary(76, ['chapters' => 10]);
        $this->addToLibrary(174, ['chapters' => 4]);

        $this->page()->assertSee('2 series, 14 unread');

        $this->library()->attach($this->user, 76, $soon->id);
        $this->page()->set('shelf', $soon->id)->assertSee('1 series on this shelf');
    }

    public function test_no_cover_is_reported_rather_than_shown_as_a_broken_image(): void
    {
        $this->addUnknownToLibrary(76);

        $this->page()
            ->assertOk()
            ->assertSee('Not on the server')
            ->assertDontSee('/suwayomi/api/v1/manga/76/thumbnail');
    }

    /**
     * A cover URL must go through the proxy. The upstream host must never appear in
     * a library card, because that is what keeps the token off the browser.
     */
    public function test_covers_are_proxied_and_never_hotlinked(): void
    {
        $this->addToLibrary(76);

        $html = $this->page()->assertOk()->html();

        $this->assertStringContainsString('/suwayomi/api/v1/manga/76/thumbnail', $html);
        $this->assertStringNotContainsString('127.0.0.1', $html);
    }

    /*
     * ---------------------------------------------------------------------------
     * A series with no source behind it.
     * ---------------------------------------------------------------------------
     */

    public function test_a_series_filed_without_a_source_shows_the_title_from_its_own_row(): void
    {
        $this->addToLibrary(76, ['title' => 'A Series That Resolved']);

        SeriesState::createFor($this->user->id, [
            'suwayomi_manga_id' => null,
            'title' => 'A Series Whose Source Is Gone',
            'unavailable_reason' => 'Its source is not installed on this server.',
            'in_library' => true,
        ]);

        $entries = $this->library()->entries($this->user);

        $this->assertCount(2, $entries);

        $filed = $entries->firstWhere('manga_id', null);

        $this->assertNotNull($filed, 'the filed series is shown, not dropped');
        $this->assertSame(
            'A Series Whose Source Is Gone',
            $filed['title'],
            'the title comes from the row, because the server has nothing to give',
        );
        $this->assertSame(
            'Its source is not installed on this server.',
            $filed['unavailable_reason'],
            'and it says why, so a title that cannot be opened is explained',
        );
        $this->assertNull($filed['cover'], 'there is no source to take a thumbnail from');
        $this->assertNull($filed['manga_id'], 'and no id to build a link to a page that cannot exist');
    }

    public function test_a_filed_series_is_never_sent_to_the_server_as_an_id(): void
    {
        $this->addToLibrary(76);

        SeriesState::createFor($this->user->id, [
            'suwayomi_manga_id' => null,
            'title' => 'Gone',
            'in_library' => true,
        ]);

        $this->library()->entries($this->user);

        /*
         * The ids travel as GraphQL variables, so the check is on the request rather than on
         * the document. A null in that list is a query for a manga that does not exist, and it
         * would be sent on every page load for as long as the series is filed.
         *
         * Read from the raw body rather than through `Request::data()`, whose return shape
         * comes from `$this->json()` and has been both an array and a stdClass across
         * versions. Two earlier versions of this test failed on that and nothing else, which
         * is a test that cannot tell a real regression from its own indexing.
         *
         * The variable is `ids`, because `SeriesGateway::fetchMany()` sends
         * `['ids' => $ids, 'first' => count($ids) + 10]` rather than building a filter object
         * inline. Three guesses at its name failed before reading the gateway.
         */
        $bodies = collect(Http::recorded())
            ->map(fn (array $pair): mixed => json_decode((string) $pair[0]->body(), true))
            ->filter(fn (mixed $body): bool => is_array($body) && str_contains((string) ($body['query'] ?? ''), 'mangas(filter:'));

        $this->assertNotEmpty($bodies, 'the page still makes its one batched call');

        $ids = $bodies->map(fn (array $body): mixed => $body['variables']['ids'] ?? null)
            ->filter(fn (mixed $in): bool => $in !== null)
            ->flatten()
            ->all();

        $this->assertNotContains(
            null,
            $ids,
            'a null id must never reach the batched filter, which is a query for a manga that does not exist',
        );

        $this->assertSame(
            [76],
            array_values($ids),
            'and the one real id is the only thing the page asks about',
        );
    }

    public function test_a_filed_series_is_not_queued_for_a_library_check(): void
    {
        SeriesState::createFor($this->user->id, [
            'suwayomi_manga_id' => null,
            'title' => 'Gone',
            'in_library' => true,
        ]);

        $due = CheckLibrary::dueIds(app(CheckSchedule::class), $this->user->id);

        $this->assertSame(
            [],
            $due,
            'there is nothing to check on a series the server does not have, and a 0 would queue one forever',
        );
    }

    /*
     * ---------------------------------------------------------------------------
     * The page, with a series filed from a backup.
     * ---------------------------------------------------------------------------
     */

    public function test_the_page_renders_a_series_that_has_no_server_manga(): void
    {
        $this->addToLibrary(76);

        SeriesState::createFor($this->user->id, [
            'suwayomi_manga_id' => null,
            'title' => 'The Buff Cuisine Diner',
            'unavailable_reason' => 'Its source is not installed on this server.',
            'in_library' => true,
        ]);

        /*
         * Rendered HTML, deliberately.
         *
         * The unit test above already asserts that `present()` hands back a null manga id and
         * a good title, and it passed while the page threw. `route('series', ['manga' => null])`
         * is a UrlGenerationException, not a broken href, so the failure lives in the template
         * and only a template can see it.
         */
        $this->page()
            ->assertOk()
            ->assertSee('The Buff Cuisine Diner')
            ->assertSee('Its source is not installed on this server.');

        $this->assertSame(
            0,
            substr_count($this->rendered(), 'route: series'),
            'nothing on the page links to a series route with no id',
        );
    }

    public function test_a_filed_series_offers_no_bulk_actions(): void
    {
        SeriesState::createFor($this->user->id, [
            'suwayomi_manga_id' => null,
            'title' => 'Filed And Unreachable',
            'in_library' => true,
        ]);

        $html = $this->rendered(enterSelectionMode: true);

        $this->assertStringNotContainsString(
            'tryCheckSeries()',
            $html,
            'a check button with an empty id calls the method with no argument at all',
        );

        $this->assertStringNotContainsString(
            'toggleFiling(,',
            $html,
            'and a shelf button would file manga 0, which is a real id to everything downstream',
        );
    }

    public function test_selecting_visible_series_never_selects_the_zero_id(): void
    {
        $this->addToLibrary(76);

        SeriesState::createFor($this->user->id, [
            'suwayomi_manga_id' => null,
            'title' => 'Filed And Unreachable',
            'in_library' => true,
        ]);

        $this->page()
            ->call('selectVisible')
            ->assertSet('selected', [76]);

        /*
         * `(int) null` is 0, and 0 is not a stand-in for "no manga": every bulk action keyed
         * off this list would act on a series the server has no record of, and the shelf table
         * would gain a row pointing at manga 0. The check button's guard is redundant for the
         * same reason this one is not, and the difference between the two is a test.
         */
        $this->assertNotContains(
            0,
            $this->page()->instance()->selected,
            'a filed series contributes no id to the bulk selection',
        );
    }

    /**
     * The library page's rendered HTML.
     *
     * A helper because two tests want it and the Livewire call is the noisy part.
     */
    private function rendered(bool $enterSelectionMode = false): string
    {
        $page = $this->page()->assertOk();

        if ($enterSelectionMode) {
            $page->call('toggleSelecting');
        }

        return $page->html();
    }
}
