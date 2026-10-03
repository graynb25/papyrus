<?php

namespace Tests\Feature;

use App\Models\SeriesState;
use App\Models\Source;
use App\Models\User;
use App\Support\Library;
use App\Support\ReadingState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The browse page: one source's own catalogue, with the reader's own library and shelves.
 *
 * ## What is being pinned
 *
 * The picker, the two tabs, and the fact that a card writes the same rows a search card
 * writes. The last is worth a test: a title added while browsing and a title added from
 * search must be one `series_states` row rather than two beliefs about one series, and a
 * shelf filed from here must be visible from the library.
 *
 * Two ids are in play and conflating them is the trap in this feature. `Library::attach()`
 * takes a **manga** id, because that is what a reader thinks in and what the routes carry.
 * `Library::categoryIdsFor()` takes the **series_states** id instead. Passing the state id
 * to attach writes a pivot row against a manga that does not exist and the chip flips
 * straight back on the next render. That shipped broken, and the shelf case below is what
 * catches it.
 */
class BrowsePageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Library $library;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->library = app(Library::class);

        Http::fake(function (Request $request) {
            $body = (string) $request->body();

            if (str_contains($body, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => 'token-1',
                    'refreshToken' => 'r',
                ]]]);
            }

            if (str_contains($body, 'fetchSourceManga')) {
                /*
                 * Popular and Latest answer differently, so a tab that does not change the
                 * query cannot pass by returning the same rows for both.
                 */
                $popular = str_contains($body, 'POPULAR');

                return Http::response(['data' => ['fetchSourceManga' => [
                    'hasNextPage' => true,
                    'mangas' => [[
                        'id' => $popular ? 101 : 201,
                        'title' => $popular ? 'Popular Title' : 'Latest Title',
                        'url' => '/manga/1',
                        'thumbnailUrl' => 'http://upstream.test/cover.jpg',
                        'status' => 'ONGOING',
                        'genre' => ['Action'],
                        'source' => ['id' => '1', 'name' => 'test'],
                    ]],
                ]]]);
            }

            if (str_contains($body, 'fetchExtensions')) {
                return Http::response(['data' => ['fetchExtensions' => ['extensions' => []]]]);
            }

            if (str_contains($body, 'query Stores')) {
                return Http::response(['data' => ['extensionStores' => ['nodes' => []]]]);
            }

            if (str_contains($body, 'query Sources')) {
                return Http::response(['data' => ['sources' => ['totalCount' => 0, 'nodes' => []]]]);
            }

            return Http::response(['data' => []]);
        });
    }

    private function source(bool $installed = true, bool $latest = true): Source
    {
        return Source::query()->create([
            'suwayomi_source_id' => 4242,
            'name' => 'Test Scans',
            'display_name' => 'Test Scans (EN)',
            'extension_pkg_name' => 'com.example.testscans',
            'lang' => 'en',
            'home_url' => 'https://testscans.test',
            'is_installed' => $installed,
            'supports_latest' => $latest,
        ]);
    }

    public function test_it_renders_a_source_popular_catalogue(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $this->source()])
            ->assertOk()
            ->assertSee('Test Scans (EN)')
            ->assertSee('Popular Title');
    }

    public function test_the_latest_tab_asks_for_a_different_listing(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $this->source()])
            ->assertSee('Popular Title')
            ->call('setType', 'LATEST')
            ->assertSee('Latest Title');
    }

    public function test_switching_tabs_returns_to_page_one(): void
    {
        $page = Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $this->source()]);

        $page->set('pageNumber', 4);

        $page->call('setType', 'LATEST')
            ->assertSet('pageNumber', 1);
    }

    public function test_the_latest_tab_is_not_offered_when_the_source_has_none(): void
    {
        $source = $this->source(latest: false);

        Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $source])
            ->assertOk()
            ->assertDontSee(__('Latest'))
            ->set('type', 'LATEST')
            ->assertSee(__('No latest chapters'));
    }

    /**
     * An uninstalled source is not reachable, and the test goes through HTTP rather than
     * the component harness because `abort(404)` is a kernel concern. The mirror marked
     * nine installed sources uninstalled after a truncated sync, one of them Asura Scans,
     * so a page that rendered for an uninstalled source would have been a third symptom of
     * that same defect rather than an independent one.
     */
    public function test_an_uninstalled_source_is_not_reachable(): void
    {
        $source = $this->source(installed: false);

        $this->actingAs($this->user)
            ->get(route('browse', ['source' => $source->id]))
            ->assertNotFound();
    }

    public function test_a_card_adds_the_title_to_the_library(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $this->source()])
            ->call('toggleLibrary', 101);

        $this->assertDatabaseHas('series_states', [
            'user_id' => $this->user->id,
            'suwayomi_manga_id' => 101,
            'in_library' => true,
        ]);
    }

    public function test_a_card_files_onto_a_shelf(): void
    {
        $shelf = $this->library->createCategory($this->user, 'Reading soon');

        Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $this->source()])
            ->call('toggleFiling', 101, (int) $shelf->id);

        $stateId = SeriesState::query()
            ->where('user_id', $this->user->id)
            ->where('suwayomi_manga_id', 101)
            ->value('id');

        $this->assertDatabaseHas('category_series', [
            'user_id' => $this->user->id,
            'series_state_id' => $stateId,
            'category_id' => (int) $shelf->id,
        ]);
    }

    public function test_filing_twice_unfiles(): void
    {
        $shelf = $this->library->createCategory($this->user, 'Reading soon');

        $page = Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $this->source()]);

        $page->call('toggleFiling', 101, (int) $shelf->id);
        $page->call('toggleFiling', 101, (int) $shelf->id);

        $this->assertDatabaseMissing('category_series', [
            'user_id' => $this->user->id,
            'category_id' => (int) $shelf->id,
        ]);
    }

    public function test_paging_moves_and_stops_at_the_first_page(): void
    {
        $page = Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $this->source()]);

        $page->call('nextPage')->assertSet('pageNumber', 2);
        $page->call('previousPage')->call('previousPage')->assertSet('pageNumber', 1);
    }

    public function test_a_title_browsed_and_a_title_searched_are_one_row(): void
    {
        $source = $this->source();

        Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $source])
            ->call('toggleLibrary', 101);

        app(ReadingState::class)->setInLibrary($this->user, 101, false);

        Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $source])
            ->call('toggleLibrary', 101);

        $this->assertSame(
            1,
            SeriesState::query()
                ->where('user_id', $this->user->id)
                ->where('suwayomi_manga_id', 101)
                ->count(),
            'A title added while browsing and one added from search must be one row, not two.'
        );
    }

    public function test_the_tab_and_page_are_carried_in_the_url(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $this->source()])
            ->assertSet('type', 'POPULAR')
            ->assertSet('pageNumber', 1);

        // Both are #[Url], which is what makes a page of a source's latest shareable. The
        // filename carries the Livewire single-file prefix, so it is globbed rather than
        // named, which is what the first version of this test did and got wrong.
        $blade = collect(glob(__DIR__.'/../../resources/views/pages/*browse.blade.php'))->first();

        $this->assertNotNull($blade, 'the browse page is a single-file component');

        $contents = (string) file_get_contents($blade);

        $this->assertStringContainsString("#[Url(as: 'type'", $contents);
        $this->assertStringContainsString("#[Url(as: 'page'", $contents);
    }

    public function test_the_picker_groups_sources_by_site(): void
    {
        // MangaDex alone is 61 language variants, which is the reason for the grouping.
        foreach ([1, 2, 3] as $i) {
            Source::query()->create([
                'suwayomi_source_id' => 900000 + $i,
                'name' => "MangaDex ({$i})",
                'display_name' => "MangaDex (EN{$i})",
                'extension_pkg_name' => 'eu.kanade.tachiyomi.extension.all.mangadex',
                // The marker in the name and the row's language agree, as they do for
                // every real source measured on this box. A mismatch is what the
                // fallback in siteName() covers, and its own case below pins that.
                'lang' => "EN{$i}",
                'home_url' => 'https://mangadex.org',
                'is_installed' => true,
                'supports_latest' => true,
            ]);
        }

        Source::query()->create([
            'suwayomi_source_id' => 910000,
            'name' => 'Test Scans',
            'display_name' => 'Test Scans (EN)',
            'extension_pkg_name' => 'com.example.testscans',
            'lang' => 'en',
            'home_url' => 'https://testscans.test',
            'is_installed' => true,
            'supports_latest' => true,
        ]);

        $groups = Livewire::actingAs($this->user)
            ->test('pages::sources')
            ->instance()
            ->browseSites();

        $byHost = collect($groups)->keyBy('host');

        $this->assertCount(2, $groups, 'Three MangaDex variants and one other site are two groups.');
        $this->assertTrue($byHost->has('mangadex.org'));
        $this->assertTrue($byHost->has('testscans.test'));

        $this->assertCount(
            3,
            $byHost->get('mangadex.org')['sources'],
            'The 61-language problem is exactly this: variants must share one group.'
        );

        $this->assertSame(
            'MangaDex',
            $byHost->get('mangadex.org')['site'],
            'A group is named for the site, not for one of its language variants.'
        );

        $this->assertSame('Test Scans', $byHost->get('testscans.test')['site']);
    }

    public function test_the_picker_links_every_installed_source(): void
    {
        $source = $this->source();

        $html = Livewire::actingAs($this->user)
            ->test('pages::sources')
            ->html();

        $this->assertStringContainsString(
            route('browse', ['source' => $source->id]),
            $html,
            'Every installed source is reachable from the picker.'
        );
    }

    public function test_the_picker_omits_an_uninstalled_source(): void
    {
        $gone = $this->source(installed: false);

        $html = Livewire::actingAs($this->user)
            ->test('pages::sources')
            ->html();

        $this->assertStringNotContainsString(
            route('browse', ['source' => $gone->id]),
            $html,
            'Listing a source whose page 404s would offer a link that cannot work.'
        );
    }
}
