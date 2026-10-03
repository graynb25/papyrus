<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ChapterState;
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
 * Bulk actions on the library grid (H7).
 *
 * The plan called for multi-select with range select, and these tests hold the
 * server side of that: what each action actually writes, that it stays inside
 * the signed-in reader's rows, and that the two write paths with real blast
 * radius behave. The range select itself is browser JS; the feature test
 * asserts the page carries the handles the JS needs.
 */
class LibraryBulkTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private User $user;

    /**
     * The manga ids the server will answer for, read by the fake.
     *
     * @var array<int, int>
     */
    private array $libraryIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->fakeSuwayomi();
    }

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

            if (str_contains($query, 'mangas(')) {
                $rows = [];

                foreach ($this->libraryIds as $mangaId) {
                    $rows[] = [
                        'id' => (string) $mangaId,
                        'title' => "Series {$mangaId}",
                        'thumbnailUrl' => "/api/v1/manga/{$mangaId}/thumbnail",
                        'status' => 'ONGOING',
                        'initialized' => true,
                        'source' => ['id' => '6247824327199706550', 'name' => 'Asura Scans', 'lang' => 'en'],
                        'chapters' => [
                            'totalCount' => 3,
                            'nodes' => array_map(
                                fn (int $n): array => ['id' => (string) ($mangaId * 100 + $n)],
                                range(1, 3),
                            ),
                        ],
                    ];
                }

                return Http::response(['data' => ['mangas' => ['nodes' => $rows]]]);
            }

            return Http::response(['data' => ['manga' => null]]);
        });
    }

    private function addToLibrary(int $mangaId): void
    {
        $this->libraryIds[] = $mangaId;

        app(ReadingState::class)->setInLibrary($this->user, $mangaId, true);
    }

    private function page(): Testable
    {
        return Livewire::actingAs($this->user)->test('pages::library');
    }

    private function shelf(string $name = 'Reading'): Category
    {
        return app(Library::class)->createCategory($this->user, $name);
    }

    // Filing ---------------------------------------------------------------

    public function test_filing_selected_series_writes_every_pivot_row(): void
    {
        $this->addToLibrary(5);
        $this->addToLibrary(6);
        $shelf = $this->shelf();

        // Hold the one Testable: a second Livewire::test builds a fresh
        // component whose `selected` is the untouched default, so reading it
        // back would prove nothing about what the action did.
        $component = $this->page()
            ->set('selected', [5, 6])
            ->call('fileSelected', $shelf->id);

        $component->assertHasNoErrors();

        foreach ([5, 6] as $mangaId) {
            $state = SeriesState::forUser($this->user->id)->where('suwayomi_manga_id', $mangaId)->first();

            $this->assertSame(
                [$shelf->id],
                app(Library::class)->categoryIdsFor($this->user->id, (int) $state->id),
                "Series {$mangaId} is filed.",
            );
        }

        $this->assertSame([], $component->instance()->selected, 'The selection is spent by the action.');
    }

    public function test_filing_into_a_shelf_that_is_not_the_readers_is_refused(): void
    {
        $mallory = User::factory()->create();
        $foreign = app(Library::class)->createCategory($mallory, 'Mallory shelf');

        $this->addToLibrary(5);

        $this->page()
            ->set('selected', [5])
            ->call('fileSelected', $foreign->id)
            ->assertHasNoErrors();

        $this->assertSame(0, DB::table('category_series')->count());
    }

    public function test_unfiling_selected_series_from_the_viewed_shelf(): void
    {
        $this->addToLibrary(5);
        $this->addToLibrary(6);
        $shelf = $this->shelf();

        foreach ([5, 6] as $mangaId) {
            app(Library::class)->attach($this->user, $mangaId, $shelf->id);
        }

        $this->page()
            ->set('shelf', $shelf->id)
            ->set('selected', [5, 6])
            ->call('unfileSelected')
            ->assertHasNoErrors();

        $this->assertSame(0, DB::table('category_series')->count());
    }

    // Read state -----------------------------------------------------------

    public function test_marking_selected_read_marks_every_chapter_of_every_series(): void
    {
        $this->addToLibrary(5);
        $this->addToLibrary(6);

        $this->page()
            ->set('selected', [5, 6])
            ->call('markSelectedRead', true)
            ->assertHasNoErrors();

        foreach ([501, 502, 503, 601, 602, 603] as $chapterId) {
            $this->assertDatabaseHas('chapter_states', [
                'user_id' => $this->user->id,
                'suwayomi_chapter_id' => $chapterId,
                'is_read' => true,
            ]);
        }
    }

    public function test_marking_selected_unread_clears_the_continue_pointer(): void
    {
        $this->addToLibrary(5);
        $this->addToLibrary(6);

        $reading = app(ReadingState::class);
        $reading->markRead($this->user, 5, 502, true);
        $reading->setPointer($this->user, 5, 502);

        $this->page()
            ->set('selected', [5, 6])
            ->call('markSelectedRead', false)
            ->assertHasNoErrors();

        $this->assertSame(0, ChapterState::forUser($this->user->id)->where('is_read', true)->count());
        $this->assertNull($reading->findSeries($this->user, 5)->last_read_chapter_id);
    }

    // Removal --------------------------------------------------------------

    public function test_removing_selected_series_leaves_their_history(): void
    {
        $this->addToLibrary(5);

        app(ReadingState::class)->markRead($this->user, 5, 501, true);

        $this->page()
            ->set('selected', [5])
            ->call('removeSelected')
            ->assertHasNoErrors();

        $this->assertFalse((bool) SeriesState::forUser($this->user->id)->where('suwayomi_manga_id', 5)->first()->in_library);
        $this->assertSame(1, ChapterState::forUser($this->user->id)->count(), 'Reading history is not the library membership and must survive it.');
    }

    // Selection lifecycle --------------------------------------------------

    public function test_leaving_selection_mode_clears_the_selection(): void
    {
        $this->page()
            ->set('selecting', true)
            ->set('selected', [5])
            ->call('toggleSelecting')
            ->assertSet('selecting', false)
            ->assertSet('selected', []);
    }

    public function test_select_page_takes_every_visible_series(): void
    {
        $this->addToLibrary(5);
        $this->addToLibrary(6);

        // Hold the one Testable: a second Livewire::test builds a fresh
        // component, and the selection would be read from the wrong one.
        $component = $this->page()
            ->set('selecting', true)
            ->call('selectVisible');

        // Entries arrive in last-read order rather than id order, so the set is
        // the assertion, not the sequence.
        $this->assertEqualsCanonicalizing([5, 6], $component->instance()->selected);
    }

    public function test_bulk_actions_are_scoped_to_the_signed_in_reader(): void
    {
        $mallory = User::factory()->create();

        $this->addToLibrary(5);

        /*
         * Mallory holds the same series the reader is about to act on, in the
         * opposite state: in her library, chapter 501 explicitly unread. Same
         * manga id and the same rows, so a write that lost its tenant would
         * land on state that already exists and her half of this test could
         * fail rather than merely counting an empty table.
         */
        $reading = app(ReadingState::class);
        $reading->setInLibrary($mallory, 5, true);
        $reading->markRead($mallory, 5, 501, false);

        $page = $this->page()->set('selected', [5]);

        $page->call('markSelectedRead', true)->assertHasNoErrors();

        // Marking read spends the selection, so removal needs its own. Chained
        // off the first action it would run against an empty selection and pass
        // without ever touching a row.
        $page->set('selected', [5])->call('removeSelected')->assertHasNoErrors();

        $this->assertSame(
            3,
            ChapterState::forUser($this->user->id)->where('is_read', true)->count(),
            'The reader gets every chapter of the selected series marked read.',
        );
        $this->assertFalse(
            (bool) SeriesState::forUser($this->user->id)->where('suwayomi_manga_id', 5)->first()->in_library,
            'The reader gets the selected series out of their own library.',
        );

        $this->assertSame(
            1,
            ChapterState::forUser($mallory->id)->count(),
            'Mallory keeps the one row she had and gains nothing from the action.',
        );
        $this->assertFalse(
            (bool) ChapterState::forUser($mallory->id)->first()->is_read,
            'Marking read must not reach another reader.',
        );
        $this->assertTrue(
            (bool) SeriesState::forUser($mallory->id)->where('suwayomi_manga_id', 5)->first()->in_library,
            'Removing from the library must not reach another reader.',
        );
    }

    // The page -------------------------------------------------------------

    public function test_the_grid_carries_the_bulk_handles_only_in_selection_mode(): void
    {
        $this->addToLibrary(5);

        $plain = $this->page()->html();
        $selecting = $this->page()->set('selecting', true)->html();

        // The index handles carry a value, so the script's bare selector cannot satisfy them.
        $this->assertStringNotContainsString('data-bulk-index="', $plain);

        /*
         * On the element, not on the string. The range script carries the selector twice as
         * literal text, so `assertStringContainsString('data-bulk-grid', $plain)` passed
         * whether or not the grid rendered. Measured on a reader with an empty library: two
         * occurrences of the string, no `<ul>` at all, assertion green. Anchored to a tag
         * and to attributes before the name, so only the rendered `<ul>` the cards live in
         * can satisfy it.
         */
        $this->assertMatchesRegularExpression('/<ul\b[^>]*\bdata-bulk-grid\b/', $plain);
        $this->assertStringContainsString('data-bulk-index="0"', $selecting);

        /*
         * `.live` is load-bearing, and this assertion is the only thing holding it.
         *
         * Plain `wire:model` is deferred: it writes the browser's copy of `selected`
         * and commits on the *next* Livewire request. On this page ticking a box is
         * the only thing a reader does, so nothing ever requested, so the bulk bar
         * gated on `count($this->selected) > 0` never rendered. Measured in a
         * browser: three boxes ticked, `$wire.get('selected')` correct, zero requests
         * sent, no bar; clicking an unrelated sort flushed it and the bar appeared.
         *
         * A test cannot see that, because `->set('selected', [...])` bypasses the
         * binding entirely, which is why twelve tests passed over a feature no reader
         * could use. So the binding is pinned here instead.
         */
        $this->assertStringContainsString('wire:model.live="selected"', $selecting);
        $this->assertStringNotContainsString('wire:model="selected"', $selecting);
    }

    public function test_the_range_select_script_is_on_the_page_once(): void
    {
        $this->addToLibrary(5);

        $html = $this->page()->html();

        // The guard reads the flag and the assignment writes it, so one script
        // is exactly one occurrence of the read.
        $this->assertSame(1, substr_count($html, 'window.__papyrusBulkRangeBound === true'));
    }
}
