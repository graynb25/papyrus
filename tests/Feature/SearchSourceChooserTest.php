<?php

namespace Tests\Feature;

use App\Models\SearchResult;
use App\Models\SearchRun;
use App\Models\User;
use App\Search\DedupeGroup;
use App\Support\ReadingState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A search card with more than one source offers a choice instead of picking one.
 *
 * ## What was wrong
 *
 * The card said "+2 sources" and then ignored them. Both the cover and the title linked to
 * whichever result the deduplicator ranked first, which is the closest title match and has no
 * relation to which site a reader trusts or which language they read. A reader shown three
 * sources and landed on one of them has been offered a choice and had it taken away.
 *
 * The alternatives were already on the card, named in plain text under the title, so the
 * information existed and only the action was missing.
 *
 * ## The two things that make this more than a dialog
 *
 * `libraryMap()` used to cover the primaries alone, so a bookmark on an alternative had no state
 * to show and would render as "not in library" for a series the reader already had. It now
 * covers every result in every group, still in one query.
 *
 * And a group with a single source keeps its plain link. A chooser over one entry is a dialog
 * that can only be dismissed, and most cards on a search are single source.
 */
class SearchSourceChooserTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * Write search results into a finished run, and hand back its id.
     *
     * @param  array<int, array{0: int, 1: string, 2: string}>  $rows  [manga id, source, title]
     */
    private function givenResults(array $rows): int
    {
        $run = SearchRun::createFor($this->user, [
            'query' => 'regressing knight',
            'status' => SearchRun::COMPLETE,
            'sources_total' => 40,
            'sources_done' => 40,
            'result_count' => count($rows),
        ]);

        $offset = 0;

        foreach ($rows as [$mangaId, $source, $title]) {
            SearchResult::createFor($this->user->id, [
                'search_run_id' => $run->id,
                'suwayomi_manga_id' => $mangaId,
                'suwayomi_source_id' => 8513894820944573933 + $offset,
                'title' => $title,
                'normalized_title' => mb_strtolower($title),
                'url' => '/manga/'.$mangaId,
                'source_name' => $source,
                'content_warning' => DedupeGroup::SAFE,
            ]);

            $offset++;
        }

        return (int) $run->id;
    }

    public function test_a_multi_source_card_opens_a_chooser(): void
    {
        $runId = $this->givenResults([
            [1, 'Manga Demon (EN)', 'Eternally Regressing Knight'],
            [2, 'Asura Scans (EN)', 'Eternally Regressing Knight'],
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::search')
            ->set('runId', $runId)
            ->assertOk()
            ->assertSee('data-test="choose-source"', false);
    }

    public function test_a_single_source_card_still_links_straight_there(): void
    {
        $runId = $this->givenResults([
            [1, 'Manga Demon (EN)', 'Only Here'],
            [2, 'Asura Scans (EN)', 'Something Else Entirely'],
        ]);

        // No chooser button, and a real link to the one source there is.
        Livewire::actingAs($this->user)
            ->test('pages::search')
            ->set('runId', $runId)
            ->assertDontSee('data-test="choose-source"', false)
            ->assertSee(route('series', ['manga' => 1]), false);
    }

    public function test_the_chooser_lists_every_source_in_the_group(): void
    {
        $runId = $this->givenResults([
            [1, 'Manga Demon (EN)', 'Eternally Regressing Knight'],
            [2, 'Asura Scans (EN)', 'Eternally Regressing Knight'],
            [3, 'Lagoon Scans (EN)', 'Eternally Regressing Knight'],
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::search')
            ->set('runId', $runId)
            ->call('chooseSource', 1)
            ->assertSee('Manga Demon (EN)')
            ->assertSee('Asura Scans (EN)')
            ->assertSee('Lagoon Scans (EN)');
    }

    public function test_each_source_in_the_chooser_links_to_its_own_series(): void
    {
        $runId = $this->givenResults([
            [1, 'Manga Demon (EN)', 'Eternally Regressing Knight'],
            [2, 'Asura Scans (EN)', 'Eternally Regressing Knight'],
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::search')
            ->set('runId', $runId)
            ->call('chooseSource', 1)
            ->assertSee(route('series', ['manga' => 1]), false)
            ->assertSee(route('series', ['manga' => 2]), false);
    }

    public function test_the_chooser_names_the_series_it_is_choosing_between(): void
    {
        $runId = $this->givenResults([
            [1, 'Manga Demon (EN)', 'Eternally Regressing Knight'],
            [2, 'Asura Scans (EN)', 'Eternally Regressing Knight'],
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::search')
            ->set('runId', $runId)
            ->call('chooseSource', 1)
            ->assertSee('Eternally Regressing Knight');
    }

    public function test_a_chooser_entry_can_be_bookmarked(): void
    {
        $runId = $this->givenResults([
            [1, 'Manga Demon (EN)', 'Eternally Regressing Knight'],
            [2, 'Asura Scans (EN)', 'Eternally Regressing Knight'],
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::search')
            ->set('runId', $runId)
            ->call('chooseSource', 1)
            ->call('toggleLibrary', 2);

        // The alternative, not the primary. Bookmarking only ever reached the primary before.
        $this->assertDatabaseHas('series_states', [
            'user_id' => $this->user->id,
            'suwayomi_manga_id' => 2,
            'in_library' => true,
        ]);
    }

    public function test_the_chooser_knows_an_alternative_is_already_in_the_library(): void
    {
        $runId = $this->givenResults([
            [1, 'Manga Demon (EN)', 'Eternally Regressing Knight'],
            [2, 'Asura Scans (EN)', 'Eternally Regressing Knight'],
        ]);

        // The alternative is the one already held, which is the case the old primaries-only
        // map would have reported as "not in library".
        app(ReadingState::class)->setInLibrary($this->user, 2, true);

        $sources = Livewire::actingAs($this->user)
            ->test('pages::search')
            ->set('runId', $runId)
            ->call('chooseSource', 1)
            ->instance()
            ->chosenSources();

        $byManga = collect($sources)->keyBy('manga');

        $this->assertTrue($byManga[2]['inLibrary'], 'An alternative already in the library must say so.');
        $this->assertFalse($byManga[1]['inLibrary']);
    }

    public function test_the_library_map_covers_alternatives(): void
    {
        $runId = $this->givenResults([
            [1, 'Manga Demon (EN)', 'Eternally Regressing Knight'],
            [2, 'Asura Scans (EN)', 'Eternally Regressing Knight'],
        ]);

        // The reader holds the alternative, not the primary. This is the case that used to
        // read as "not in library": the map was keyed on primaries, so the alternative had
        // no entry at all and the chooser would have offered to add a series already on
        // the shelf, which is a no-op that reads as a broken button.
        app(ReadingState::class)->setInLibrary($this->user, 2, true);

        $map = Livewire::actingAs($this->user)
            ->test('pages::search')
            ->set('runId', $runId)
            ->instance()
            ->libraryMap();

        $this->assertArrayHasKey(
            2,
            $map,
            'The alternative has to be in the map or its bookmark has no state to show.'
        );
        $this->assertTrue($map[2]);

        // Absence still means false, so the primary is not wrongly claimed either.
        $this->assertFalse($map[1] ?? false);
    }

    public function test_the_library_map_still_covers_primaries(): void
    {
        $runId = $this->givenResults([
            [1, 'Manga Demon (EN)', 'Eternally Regressing Knight'],
            [2, 'Asura Scans (EN)', 'Eternally Regressing Knight'],
        ]);

        app(ReadingState::class)->setInLibrary($this->user, 1, true);
        app(ReadingState::class)->setInLibrary($this->user, 2, true);

        $map = Livewire::actingAs($this->user)
            ->test('pages::search')
            ->set('runId', $runId)
            ->instance()
            ->libraryMap();

        $this->assertTrue($map[1] ?? false, 'The primary was always covered and still is.');
        $this->assertTrue($map[2] ?? false);
    }

    public function test_closing_the_chooser_forgets_the_choice(): void
    {
        $runId = $this->givenResults([
            [1, 'Manga Demon (EN)', 'Eternally Regressing Knight'],
            [2, 'Asura Scans (EN)', 'Eternally Regressing Knight'],
        ]);

        $page = Livewire::actingAs($this->user)
            ->test('pages::search')
            ->set('runId', $runId);

        $page->call('chooseSource', 1);

        $this->assertNotSame(
            [],
            $page->instance()->chosenSources(),
            'Choosing a group fills the chooser.'
        );

        $page->call('closeSourceChooser')
            ->assertSet('chosenManga', null);

        $this->assertSame(
            [],
            $page->instance()->chosenSources(),
            'And closing it empties the chooser, rather than leaving the last choice on screen behind a closed dialog.'
        );
    }

    public function test_choosing_a_group_that_is_not_on_the_page_lists_nothing(): void
    {
        $runId = $this->givenResults([[1, 'Manga Demon (EN)', 'Only Here']]);

        $sources = Livewire::actingAs($this->user)
            ->test('pages::search')
            ->set('runId', $runId)
            ->call('chooseSource', 9999)
            ->instance()
            ->chosenSources();

        $this->assertSame([], $sources);
    }

    public function test_an_empty_chooser_says_so_rather_than_showing_nothing(): void
    {
        $runId = $this->givenResults([[1, 'Manga Demon (EN)', 'Only Here']]);

        // A heading over an empty list is a dialog that reads as broken, so the empty case is
        // given a sentence. Reached here by asking for a group that is not on the page.
        Livewire::actingAs($this->user)
            ->test('pages::search')
            ->set('runId', $runId)
            ->call('chooseSource', 9999)
            ->assertSee('This result is no longer on the page.');
    }

    public function test_the_chooser_is_found_by_any_of_the_group_s_sources(): void
    {
        $runId = $this->givenResults([
            [1, 'Manga Demon (EN)', 'Eternally Regressing Knight'],
            [2, 'Asura Scans (EN)', 'Eternally Regressing Knight'],
            [3, 'Lagoon Scans (EN)', 'Eternally Regressing Knight'],
        ]);

        /*
         * Ask for the group using the id of a source that is not the primary.
         *
         * The lookup used to be "which group's primary is this id". A search streams: results
         * arrive while the reader is looking, the deduplicator recomputes, and the id the
         * reader clicked can stop being the primary without leaving the group. Keyed that way,
         * the chooser came back empty for a group that was still right there, with no sign of
         * why.
         */
        $sources = Livewire::actingAs($this->user)
            ->test('pages::search')
            ->set('runId', $runId)
            ->call('chooseSource', 3)
            ->instance()
            ->chosenSources();

        $this->assertCount(
            3,
            $sources,
            'Membership is what identifies a group. The primary is a ranking that moves.'
        );
    }

    public function test_choosing_a_source_asks_the_server_to_open_the_chooser(): void
    {
        $runId = $this->givenResults([
            [1, 'Manga Demon (EN)', 'Eternally Regressing Knight'],
            [2, 'Asura Scans (EN)', 'Eternally Regressing Knight'],
        ]);

        /*
         * The dialog is opened from the response, not from the click.
         *
         * Alpine's dispatch runs the instant the button is pressed and the round trip carrying
         * the sources has not come back yet, so opening on the click shows a heading over an
         * empty list. The button therefore only asks, and the answer opens it.
         */
        Livewire::actingAs($this->user)
            ->test('pages::search')
            ->set('runId', $runId)
            ->call('chooseSource', 1)
            ->assertDispatched('open-source-chooser');
    }

    public function test_the_card_does_not_open_the_chooser_on_the_click_itself(): void
    {
        $blade = (string) file_get_contents(
            __DIR__.'/../../resources/views/pages/⚡search.blade.php'
        );

        // The opening card must carry no `modal-show` dispatch of its own.
        $this->assertStringNotContainsString(
            "wire:click=\"chooseSource({{ \$primaryId }})\"\n                                x-on:click=",
            $blade,
            'The button dispatches the modal before its own state has arrived, which opens an empty chooser.'
        );

        $this->assertStringContainsString(
            'x-on:open-source-chooser.window',
            $blade,
            'Nothing listens for the server asking to open it, so the chooser would never appear.'
        );
    }

    public function test_the_choice_is_not_carried_in_the_url(): void
    {
        $blade = (string) file_get_contents(
            __DIR__.'/../../resources/views/pages/⚡search.blade.php'
        );

        $this->assertStringNotContainsString(
            "#[Url(as: 'chosenManga'",
            $blade,
            'A transient question is not a place. Putting it in the URL would make the back button close a modal nobody opened.'
        );
    }
}
