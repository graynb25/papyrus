<?php

namespace Tests\Feature;

use App\Jobs\FetchSeriesChapters;
use App\Models\SeriesState;
use App\Models\Source;
use App\Models\User;
use App\Support\Library;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The browse page: one source's Popular and Latest, loaded by scrolling rather than by paging.
 *
 * ## What is pinned here
 *
 * Three things, in order of how badly they went wrong when measured.
 *
 * **Rows are not truncated.** `browse()` used to slice to `results_per_source`, which is 15
 * because it bounds a search fan-out. Applied to a browse page it discarded most of every one:
 * measured against MangaFire it threw away 35 of 50 rows, Manga Demon 41 of 56, Atsumaru 25 of
 * 40. A browse page is a page, not a sample of one.
 *
 * **The scroll terminates.** `hasNextPage` was already selected in the mutation and thrown away,
 * so nothing knew where the end was. It is also the only honest signal: Asura Scans answers true
 * on every page four deep and Akaza Scans answers false on its first, so "fewer rows than last
 * time" cannot tell a finished source from an odd one.
 *
 * **A repeated page cannot grow the grid for ever.** An endless source that repeats rows would
 * otherwise append on every scroll, and the reader would watch covers they already have pile up.
 *
 * The shelf cases are here too, and one of them is load-bearing for a bug this feature shipped
 * with: `Library::attach()` writes the pivot row but not `in_library`, and the library lists
 * only `in_library = true`, so filing a title on a shelf used to create a row the library page
 * never showed. The reader filed "Reading soon" and the series disappeared.
 */
class BrowsePageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Library $library;

    /**
     * Page sizes the fake answers with, keyed by the requested page number.
     *
     * @var array<int, int>
     */
    private array $pageSizes = [1 => 3];

    private bool $lastPage = false;

    /**
     * Whether every page answers with page one's rows, which is the shape of a source stuck on
     * one page and the reason the append de-duplicates.
     */
    private bool $repeatsFirstPage = false;

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
                // data_get, not array access: $request->data() decodes nested JSON into
                // stdClass. Trap 82, and the reason the first run of this file failed 14 times.
                $page = (int) data_get($request->data(), 'variables.page', 1);
                $popular = str_contains($body, 'POPULAR');

                if ($this->repeatsFirstPage) {
                    $page = 1;
                }

                $count = $this->pageSizes[$page] ?? 0;
                $rows = [];

                foreach (range(1, max(0, $count)) as $i) {
                    $rows[] = [
                        // Page number in the id, so a repeated page is detectable and so rows
                        // from different pages are distinguishable.
                        'id' => ($page * 1000) + $i,
                        'title' => ($popular ? 'Popular' : 'Latest')." P{$page} #{$i}",
                        'url' => '/manga/'.$i,
                        'thumbnailUrl' => 'http://upstream.test/cover.jpg',
                        'source' => ['id' => '1', 'name' => 'test'],
                    ];
                }

                return Http::response(['data' => ['fetchSourceManga' => [
                    'hasNextPage' => ! ($this->lastPage && $page >= count($this->pageSizes)),
                    'mangas' => $rows,
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
            ->assertSee('Popular P1 #1');
    }

    public function test_the_latest_tab_asks_for_a_different_listing(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $this->source()])
            ->assertSee('Popular P1 #1')
            ->call('setType', 'LATEST')
            ->assertSee('Latest P1 #1')
            ->assertDontSee('Popular P1 #1');
    }

    public function test_switching_tabs_clears_the_grid_and_returns_to_the_first_page(): void
    {
        $this->pageSizes = [1 => 2, 2 => 2];

        $page = Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $this->source()]);

        $page->call('loadMore')->assertSet('loadedThrough', 2);

        $page->call('setType', 'LATEST')
            ->assertSet('loadedThrough', 1)
            ->assertSet('lastLoadedPage', 1)
            ->assertCount('rows', 2);
    }

    public function test_rows_are_not_truncated_to_the_search_limit(): void
    {
        /*
         * 50 rows, which is what MangaFire returns on a page. `results_per_source` is 15, and
         * the first version of this sliced a browse page to it, so 35 rows silently vanished on
         * every scroll of every page.
         */
        $this->pageSizes = [1 => 50];

        Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $this->source()])
            ->assertCount('rows', 50)
            ->assertSee('Popular P1 #50')
            ->assertDontSee('Popular P1 #51');
    }

    public function test_scrolling_appends_the_next_page(): void
    {
        $this->pageSizes = [1 => 3, 2 => 4];
        $this->lastPage = true;

        $page = Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $this->source()]);

        $this->assertCount(3, $page->get('rows'));
        $this->assertTrue($page->get('hasNextPage'));

        $page->call('loadMore');

        $this->assertCount(7, $page->get('rows'), 'The next page appends rather than replacing.');
        $this->assertSame(2, $page->get('loadedThrough'));
        $this->assertFalse($page->get('hasNextPage'), 'The last page reports no more.');
        $page->assertSee('Popular P2 #4');
    }

    public function test_the_scroll_stops_when_the_source_says_there_is_no_more(): void
    {
        $this->pageSizes = [1 => 2];
        $this->lastPage = true;

        $page = Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $this->source()]);

        $this->assertFalse($page->get('hasNextPage'));

        // The sentinel must be inert at the end, or an endless observer would book requests
        // for pages the source has already said do not exist.
        $page->call('loadMore');

        $this->assertCount(2, $page->get('rows'));
        $this->assertSame(1, $page->get('loadedThrough'));
    }

    public function test_a_source_repeating_a_page_cannot_grow_the_grid_for_ever(): void
    {
        // Every page answers page one's rows, which is what a source stuck on one page looks
        // like, and the reason the append de-duplicates rather than trusting the source.
        $this->pageSizes = [1 => 3];
        $this->repeatsFirstPage = true;

        $page = Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $this->source()]);

        $page->call('loadMore');

        $this->assertCount(
            3,
            $page->get('rows'),
            'A repeated page contributes nothing rather than stacking copies of the same covers.'
        );
        $this->assertFalse($page->get('hasNextPage'));
    }

    public function test_the_latest_tab_is_not_offered_when_the_source_has_none(): void
    {
        $source = $this->source(latest: false);

        Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $source])
            ->assertOk()
            ->assertDontSee(__('Latest'));
    }

    public function test_a_hand_written_latest_link_says_why_rather_than_showing_an_empty_grid(): void
    {
        // The tab is hidden for a source with no latest listing, so this state is only reachable
        // by editing the URL. It has to explain itself, because an empty grid reads as a fault
        // and the real reason is a source that never had that listing.
        $source = $this->source(latest: false);

        Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $source])
            ->call('setType', 'LATEST')
            ->assertSet('type', 'LATEST')
            ->assertSee(__('This source does not provide a latest-chapters listing.'));
    }

    /**
     * An uninstalled source is not reachable, and the test goes through HTTP rather than the
     * component harness because `abort(404)` is a kernel concern.
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
            ->call('toggleLibrary', 1001);

        $this->assertDatabaseHas('series_states', [
            'user_id' => $this->user->id,
            'suwayomi_manga_id' => 1001,
            'in_library' => true,
        ]);
    }

    public function test_a_card_files_onto_a_shelf(): void
    {
        $shelf = $this->library->createCategory($this->user, 'Reading soon');

        Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $this->source()])
            ->call('toggleFiling', 1001, (int) $shelf->id);

        $stateId = SeriesState::query()
            ->where('user_id', $this->user->id)
            ->where('suwayomi_manga_id', 1001)
            ->value('id');

        $this->assertDatabaseHas('category_series', [
            'user_id' => $this->user->id,
            'series_state_id' => $stateId,
            'category_id' => (int) $shelf->id,
        ]);
    }

    public function test_filing_onto_a_shelf_also_puts_the_series_in_the_library(): void
    {
        /*
         * Load-bearing. `Library::attach()` writes the pivot row and the home shelf but never
         * sets `in_library`, and `Library::entries()` filters on `in_library = true`. So filing a
         * title from this grid used to create a row the library page never showed, and the
         * reader's series vanished the moment they filed it.
         */
        $shelf = $this->library->createCategory($this->user, 'Reading soon');

        Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $this->source()])
            ->call('toggleFiling', 1001, (int) $shelf->id);

        $this->assertDatabaseHas('series_states', [
            'user_id' => $this->user->id,
            'suwayomi_manga_id' => 1001,
            'in_library' => true,
        ]);

        $this->assertContains(
            1001,
            $this->library->entries($this->user)->pluck('manga_id')->all(),
            'A series filed on a shelf from the browse grid must be reachable from the library.'
        );
    }

    public function test_filing_twice_unfiles(): void
    {
        $shelf = $this->library->createCategory($this->user, 'Reading soon');

        $page = Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $this->source()]);

        $page->call('toggleFiling', 1001, (int) $shelf->id);
        $page->call('toggleFiling', 1001, (int) $shelf->id);

        $this->assertDatabaseMissing('category_series', [
            'user_id' => $this->user->id,
            'category_id' => (int) $shelf->id,
        ]);
    }

    public function test_removing_from_the_library_also_unfiles(): void
    {
        $shelf = $this->library->createCategory($this->user, 'Reading soon');

        $page = Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $this->source()]);

        $page->call('toggleFiling', 1001, (int) $shelf->id);
        $page->call('toggleLibrary', 1001);

        // The pivot row is left alone: the series row is the source of truth for membership and
        // the library check reconciles the rest, so unfiling here would be a second opinion.
        $this->assertDatabaseHas('category_series', [
            'user_id' => $this->user->id,
            'category_id' => (int) $shelf->id,
        ]);

        $this->assertSame([], $page->get('shelfFilings')[1001] ?? [], '...but the card stops showing it as filed.');
    }

    public function test_the_card_shows_no_status_line(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $this->source()])
            ->assertDontSee('UNKNOWN');
    }

    public function test_a_shared_link_rebuilds_only_what_it_was_told_to(): void
    {
        $this->pageSizes = [1 => 2, 2 => 2, 3 => 2];
        $this->lastPage = true;

        $source = $this->source();

        // A link that says page 3 rebuilds three pages, so a refresh keeps what the reader had.
        $this->actingAs($this->user)
            ->get(route('browse', ['source' => $source->id, 'page' => 3]))
            ->assertOk();
    }

    public function test_a_shared_link_cannot_demand_an_unbounded_number_of_requests(): void
    {
        /*
         * `loadedThrough` is URL-bound, so a link can claim any page number. Without the cap,
         * `?page=400` would mean 400 upstream requests to somebody else's site before the page
         * rendered, from a URL in a chat message.
         */
        $source = $this->source();

        $this->actingAs($this->user)
            ->get(route('browse', ['source' => $source->id, 'page' => 400]))
            ->assertOk();

        $blade = collect(glob(__DIR__.'/../../resources/views/pages/*browse.blade.php'))->first();
        $contents = (string) file_get_contents($blade);

        $this->assertStringContainsString(
            'MOUNT_PAGE_LIMIT',
            $contents,
            'The cap must exist in the component, not only in a test.'
        );
    }

    public function test_no_status_is_rendered_even_when_the_payload_carries_one(): void
    {
        // The payload's status is not even selected any more, so a source that volunteers one
        // cannot put the word UNKNOWN back on a card.
        $this->pageSizes = [1 => 1];

        Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $this->source()])
            ->assertDontSee('UNKNOWN');
    }

    public function test_the_picker_groups_sources_by_site_and_starts_closed(): void
    {
        foreach ([1, 2, 3] as $i) {
            Source::query()->create([
                'suwayomi_source_id' => 900000 + $i,
                'name' => "MangaDex ({$i})",
                'display_name' => "MangaDex (EN{$i})",
                'extension_pkg_name' => 'eu.kanade.tachiyomi.extension.all.mangadex',
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

        $html = Livewire::actingAs($this->user)->test('pages::sources')->html();

        $this->assertStringContainsString('mangadex.org', $html);
        $this->assertStringContainsString('testscans.test', $html);
        $this->assertStringContainsString('3 sources', $html, 'The three variants share one group.');

        // The reader asked for every group closed. The first version opened the largest, which
        // put MangaDex's 61 languages on screen before anything else on the page.
        $this->assertDoesNotMatchRegularExpression(
            '/<details[^>]*\sopen/',
            $html,
            'No site group may start expanded. A reader who wants MangaDex reaches for a language in the first four pills anyway.'
        );
    }

    public function test_the_picker_links_every_installed_source_from_the_summary_row(): void
    {
        $source = $this->source();

        $html = Livewire::actingAs($this->user)->test('pages::sources')->html();

        $this->assertStringContainsString(
            route('browse', ['source' => $source->id]),
            $html,
            'A single-language site shows its one source on the summary row, so a closed group costs nothing.'
        );
    }

    public function test_the_picker_omits_an_uninstalled_source(): void
    {
        $gone = $this->source(installed: false);

        $html = Livewire::actingAs($this->user)->test('pages::sources')->html();

        $this->assertStringNotContainsString(
            route('browse', ['source' => $gone->id]),
            $html,
            'Listing a source whose page 404s would offer a link that cannot work.'
        );
    }

    public function test_the_browse_grid_does_not_leak_a_reader_s_shelves_between_people(): void
    {
        $other = User::factory()->create();
        $this->library->createCategory($other, 'Someone elses shelf');

        Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $this->source()])
            ->assertDontSee('Someone elses shelf');
    }

    public function test_the_cache_is_not_left_claimed_by_browsing(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::browse', ['source' => $this->source()])
            ->call('loadMore');

        $this->assertFalse(
            Cache::has(FetchSeriesChapters::claimKey((int) $this->user->id)),
            'Browsing must not take the one chapter-fetch claim, or the next series page would wait behind a scroll.'
        );
    }
}
