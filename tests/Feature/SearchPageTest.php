<?php

namespace Tests\Feature;

use App\Models\SearchResult;
use App\Models\SearchRun;
use App\Models\SearchSourceResult;
use App\Models\SeriesState;
use App\Models\Source;
use App\Models\User;
use App\Models\UserSource;
use App\Support\AdultContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SearchPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->fakeSuwayomi();
    }

    /**
     * A live-shaped source with an id beyond 2^53, because that is what the
     * real instance returns and it is the case most likely to corrupt.
     */
    private function source(string $name, int $offset = 0): Source
    {
        return Source::create([
            'suwayomi_source_id' => 8513894820944573933 + $offset,
            'name' => $name,
            'display_name' => $name,
            'extension_pkg_name' => 'eu.kanade.tachiyomi.extension.en.test'.$offset,
            'lang' => 'en',
            'content_warning' => Source::SAFE,
            'is_installed' => true,
            'supports_latest' => true,
        ]);
    }

    private function fakeSuwayomi(): void
    {
        Http::fake(function (Request $request) {
            $query = $request->data()['query'] ?? '';

            if (str_contains($query, 'mutation Login')) {
                return Http::response([
                    'data' => ['login' => ['accessToken' => 'token-1', 'refreshToken' => 'r']],
                ]);
            }

            if (str_contains($query, 'fetchSourceManga')) {
                return Http::response([
                    'data' => ['fetchSourceManga' => [
                        'hasNextPage' => false,
                        'mangas' => [[
                            'id' => 1,
                            'title' => 'One Piece',
                            'url' => '/one-piece',
                            'thumbnailUrl' => 'http://upstream.test/cover.jpg',
                            'status' => 'ONGOING',
                            'genre' => ['Action'],
                            'source' => ['id' => '1', 'name' => 'test'],
                        ]],
                    ]],
                ]);
            }

            return Http::response(['data' => []]);
        });
    }

    /**
     * The query is bound to the URL as `q`, and replaces the history entry rather than pushing.
     *
     * `$query` was the one property on this page without `#[Url]`, so a reader could share the
     * filters and the AniList toggle and not the search. Found by `verify-inflight.py`, which
     * submitted a query and landed on an address bar carrying only `content[0]` and
     * `content[1]`.
     *
     * Asserted on `wire:effects`, which is the binding contract rendered into the page: the
     * parameter name it writes, and whether it pushes or replaces. The first version of this
     * asserted `value="one piece"` in the markup and failed, because a `wire:model` input takes
     * its value from the component snapshot rather than from server-rendered HTML. Asserting on
     * the snapshot instead would test Livewire's encoding; the effects attribute is the part
     * this change is responsible for.
     *
     * `use: replace` is deliberate and is why the test asserts it. The input is
     * `wire:model.live.debounce.500ms`, so typing "one piece" settles two or three times, and
     * with `push` the back button would step through a reader's own keystrokes.
     */
    public function test_the_query_is_bound_to_the_url(): void
    {
        $html = $this->actingAs($this->user)->get('/search')->assertOk()->getContent();

        // HTML-entity decoded first: the attribute is written into the markup escaped, so
        // `&quot;query&quot;` is what is in the document and a regex for a bare quote finds
        // nothing. Asserting on the raw response cost one run of this test.
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);

        $this->assertMatchesRegularExpression(
            '/"query":\{"as":"q","use":"replace"/',
            $html,
            'The query must be bound to the URL as ?q= and replace rather than push, or a search '
                .'cannot be bookmarked and the back button fills with keystrokes.',
        );
    }

    /**
     * Arriving at a search runs it, rather than showing an empty box.
     *
     * The other half of being bookmarkable. With the terms restored but no run behind them, a
     * bookmarked link reads as a broken page: the reader asked for these results by saving the
     * link, and making them press Search again is asking twice.
     */
    public function test_the_search_input_has_a_real_name(): void
    {
        $html = $this->actingAs($this->user)->get('/search')->assertOk()->getContent();
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);

        $this->assertMatchesRegularExpression('/id="search-query"/', $html);
        $this->assertMatchesRegularExpression('/aria-label="Search"/', $html);
    }

    public function test_arriving_with_a_query_starts_a_run(): void
    {
        $before = SearchRun::query()->count();

        $this->actingAs($this->user)->get('/search?q=berserk')->assertOk();

        $this->assertSame(
            $before + 1,
            SearchRun::query()->count(),
            'A bookmarked search should run itself on arrival.',
        );

        $run = SearchRun::query()->latest('id')->first();

        $this->assertNotNull($run);
        $this->assertSame('berserk', $run->query, 'It must run the search that was bookmarked, not another.');
    }

    /**
     * `/search` on its own is still the empty page.
     *
     * The guard on the auto-run. Without it, every visit to the search page would start a
     * 40-source fan-out, which is measured at 21 to 86 seconds on this box.
     */
    public function test_arriving_without_a_query_starts_nothing(): void
    {
        $before = SearchRun::query()->count();

        $this->actingAs($this->user)->get('/search')->assertOk();

        $this->assertSame(
            $before,
            SearchRun::query()->count(),
            'No query in the URL must mean no search, or the page costs a fan-out per visit.',
        );
    }

    public function test_the_page_requires_authentication(): void
    {
        $this->get('/search')->assertRedirect();
    }

    public function test_the_page_renders_for_an_authenticated_user(): void
    {
        $this->actingAs($this->user)->get('/search')->assertOk();
    }

    public function test_a_query_creates_a_run_and_persists_results(): void
    {
        config(['queue.default' => 'sync']);

        $this->source('Alpha Manga');
        $this->source('Akaza Scans', 1);

        Livewire::actingAs($this->user)->test('pages::search')
            ->set('query', 'one piece')
            ->call('search')
            ->assertHasNoErrors();

        $this->assertSame(1, SearchRun::forUser($this->user->id)->count());

        $run = SearchRun::forUser($this->user->id)->first();

        $this->assertSame(2, $run->sources_total);
        $this->assertSame(2, $run->sources_done);
        $this->assertSame(2, $run->result_count, 'Both sources return the same series.');
        $this->assertTrue($run->isFinished());

        $this->assertSame(2, SearchSourceResult::forUser($this->user->id)->count());
    }

    public function test_results_from_multiple_sources_are_deduplicated_in_the_view(): void
    {
        config(['queue.default' => 'sync']);

        $this->source('Alpha Manga');
        $this->source('Akaza Scans', 1);

        $component = Livewire::actingAs($this->user)->test('pages::search')
            ->set('query', 'one piece')
            ->call('search');

        // Two raw rows collapse to one card with a "+1 source" badge.
        $this->assertSame(2, SearchResult::forUser($this->user->id)->count());
        $this->assertCount(1, $component->instance()->groups());
        $this->assertSame(2, $component->instance()->groups()->first()->sourceCount());
    }

    public function test_an_empty_query_is_rejected(): void
    {
        Livewire::actingAs($this->user)->test('pages::search')
            ->set('query', '')
            ->call('search')
            ->assertHasErrors(['query' => 'required']);
    }

    public function test_searches_are_scoped_to_the_signed_in_user(): void
    {
        config(['queue.default' => 'sync']);

        $mallory = User::factory()->create();
        $this->source('Alpha Manga');

        Livewire::actingAs($this->user)->test('pages::search')
            ->set('query', 'one piece')
            ->call('search');

        $this->assertSame(1, SearchRun::forUser($this->user->id)->count());
        $this->assertSame(0, SearchRun::forUser($mallory->id)->count());
        $this->assertSame(0, SearchResult::forUser($mallory->id)->count());
    }

    public function test_clearing_resets_the_component(): void
    {
        config(['queue.default' => 'sync']);

        $this->source('Alpha Manga');

        $component = Livewire::actingAs($this->user)->test('pages::search')
            ->set('query', 'one piece')
            ->call('search')
            ->call('clear');

        $this->assertSame('', $component->get('query'));
        $this->assertNull($component->get('runId'));
    }

    /**
     * NSFW is opt-in per user, because in shared deployment one reader's
     * preference must not decide what another sees.
     *
     * Rewritten on 2026-10-21, when the opt-in became a stored per reader
     * setting behind a confirmation rather than a rating in the address bar.
     * The sentence above was always the intent and was not always the
     * behaviour: the rating used to be a query parameter, so one reader's
     * choice could travel in a link and land on somebody else's screen.
     *
     * The test now holds what it always meant to hold. The adult source is
     * out of the scope while the gate is off, it comes in when the reader
     * turns the gate on, and the address bar cannot do it on its own.
     */
    public function test_nsfw_sources_are_excluded_unless_requested(): void
    {
        config(['queue.default' => 'sync']);

        $safe = $this->source('Safe Source');
        $adult = $this->source('Adult Source', 1);
        $adult->update(['content_warning' => Source::NSFW]);

        $component = Livewire::actingAs($this->user)->test('pages::search')
            ->set('query', 'one piece')
            ->call('search');

        $this->assertSame(1, $component->instance()->eligibleSources());

        // The rating in the address bar is not a confirmation, so the scope
        // does not move because of it. Measured on 2026-10-21: the same
        // address on the sources page put 41 adult rated extensions on the
        // first page of the catalogue with nothing asked.
        $component->set('content', [Source::SAFE, Source::NSFW])
            ->call('search');

        $this->assertSame(1, $component->instance()->eligibleSources(), 'The rating in the address bar does not open the gate.');

        app(AdultContent::class)->set($this->user, true);

        $component->set('content', [Source::SAFE, Source::NSFW])
            ->call('search');

        $this->assertSame(2, $component->instance()->eligibleSources(), 'The gate opens the rating for this reader.');
    }

    /**
     * The gate is per reader, and one reader opening it does not open it for
     * anybody else. The same rule the AniList switch has carried since it was
     * built, and the reason the setting is stored rather than carried in the
     * address.
     */
    public function test_the_gate_is_per_reader(): void
    {
        config(['queue.default' => 'sync']);

        $this->source('Safe Source');
        $adult = $this->source('Adult Source', 1);
        $adult->update(['content_warning' => Source::NSFW]);

        $other = User::factory()->create();

        app(AdultContent::class)->set($this->user, true);

        $component = Livewire::actingAs($this->user)->test('pages::search')
            ->set('query', 'one piece')
            ->set('content', [Source::SAFE, Source::NSFW])
            ->call('search');

        $this->assertSame(2, $component->instance()->eligibleSources());

        $theirs = Livewire::actingAs($other)->test('pages::search')
            ->set('query', 'one piece')
            ->call('search');

        $this->assertSame(1, $theirs->instance()->eligibleSources(), 'One reader opening the gate is not consent for another.');
    }

    /**
     * The rating is not offered as a choice while the gate is off, and it is
     * offered once the reader has confirmed.
     *
     * An option that is offered and then does nothing is a control that
     * lies, and the reader would tick it and see nothing change.
     */
    public function test_the_rating_is_only_offered_once_the_gate_is_on(): void
    {
        $page = Livewire::actingAs($this->user)->test('pages::search');

        $page->assertDontSee('value="NSFW"', escape: false)
            ->assertSee('Adult content off')
            ->assertSee('Nothing marked NSFW is listed or searched.');

        $page->call('confirmAdultContent');

        // A fresh instance for the read, because the component caches its
        // computeds for its own life and the instance that ran the
        // confirmation would answer from the cache it took before the setting
        // was written.
        $fresh = Livewire::actingAs($this->user)->test('pages::search');

        $fresh->assertSee('value="NSFW"', escape: false)
            ->assertSee('Adult content on');
    }

    /**
     * The confirmation states the age ask, because the age ask is the point
     * of the confirmation.
     */
    public function test_the_confirmation_states_the_age_ask(): void
    {
        Livewire::actingAs($this->user)->test('pages::search')
            ->assertSee('Turn on adult content?')
            ->assertSee('18 or older')
            ->assertSee('I am 18 or older, turn it on');
    }

    public function test_a_language_filter_narrows_the_fan_out(): void
    {
        config(['queue.default' => 'sync']);

        $english = $this->source('English Source');
        $japanese = $this->source('Japanese Source', 1);
        $japanese->update(['lang' => 'ja']);

        $component = Livewire::actingAs($this->user)->test('pages::search')
            ->set('query', 'one piece');

        $this->assertSame(2, $component->instance()->eligibleSources());

        $component->set('langs', ['en']);

        $this->assertSame(1, $component->instance()->eligibleSources());
    }

    public function test_cover_urls_are_proxied_not_hotlinked(): void
    {
        $result = SearchResult::createFor($this->user->id, [
            'search_run_id' => SearchRun::createFor($this->user->id, ['query' => 'x'])->id,
            'suwayomi_manga_id' => 42,
            'suwayomi_source_id' => 1,
            'title' => 'One Piece',
            'normalized_title' => 'onepiece',
            'thumbnail_url' => 'http://upstream.test/cover.jpg',
            'source_name' => 'Alpha',
            'content_warning' => 'SAFE',
        ]);

        /*
         * Sources hotlink-protect, so the browser must never hit the upstream host
         * directly for a cover. The stored thumbnail_url is 'http://upstream.test/...'
         * and none of that may appear: the URL is rebuilt from the manga id so it
         * can only ever point at our own proxy.
         *
         * Asserted by substring rather than by equality, because route() returns an
         * absolute URL and the transcoding format is a config value. What matters is
         * that the path is right and the upstream host is gone.
         */
        $cover = (string) $result->coverUrl();

        $this->assertStringContainsString('/suwayomi/api/v1/manga/42/thumbnail', $cover);
        $this->assertStringNotContainsString('upstream.test', $cover);
    }

    /**
     * The proxy has to see the format as a query parameter, since that is how
     * Suwayomi is told which encoding to transcode to.
     */
    public function test_a_cover_asks_for_the_configured_transcode(): void
    {
        config(['papyrus.reader.default_image_format' => 'webp']);

        $result = SearchResult::createFor($this->user->id, [
            'search_run_id' => SearchRun::createFor($this->user->id, ['query' => 'x'])->id,
            'suwayomi_manga_id' => 42,
            'suwayomi_source_id' => 1,
            'title' => 'One Piece',
            'normalized_title' => 'onepiece',
            'thumbnail_url' => '/api/v1/manga/42/thumbnail',
            'source_name' => 'Alpha',
            'content_warning' => 'SAFE',
        ]);

        $this->assertStringEndsWith('?format=webp', (string) $result->coverUrl());
    }

    public function test_a_result_without_a_thumbnail_has_no_cover_url(): void
    {
        $result = new SearchResult;

        $this->assertNull($result->coverUrl());
    }

    /**
     * Render assertions, not just model assertions. A search that populates
     * rows correctly but explodes inside the Blade template would pass every
     * other test in this file, so the actual output has to be checked.
     */
    public function test_the_rendered_page_shows_deduplicated_results_with_a_source_badge(): void
    {
        config(['queue.default' => 'sync']);

        $this->source('Alpha Manga');
        $this->source('Akaza Scans', 1);

        Livewire::actingAs($this->user)->test('pages::search')
            ->set('query', 'one piece')
            ->call('search')
            ->assertOk()
            ->assertSee('One Piece')
            ->assertSee('Alpha Manga')
            ->assertSee('Akaza Scans')
            // Two sources, one card, so the badge advertises the alternatives.
            ->assertSee('+1 source')
            // The cover must be proxied, never hotlinked to the upstream host.
            ->assertSee('/suwayomi/api/v1/manga/1/thumbnail')
            ->assertDontSee('upstream.test');
    }

    public function test_the_rendered_page_reports_per_source_status(): void
    {
        config(['queue.default' => 'sync']);

        $this->source('Alpha Manga');

        Livewire::actingAs($this->user)->test('pages::search')
            ->set('query', 'one piece')
            ->call('search')
            ->assertOk()
            ->assertSee('Per-source status')
            ->assertSee('Alpha Manga');
    }

    public function test_the_page_offers_only_installed_languages(): void
    {
        $this->source('English Source');
        $japanese = $this->source('Japanese Source', 1);
        $japanese->update(['lang' => 'ja']);

        $component = Livewire::actingAs($this->user)->test('pages::search');

        // Both have a single source here, so both land in the rare tail.
        $offered = $component->instance()
            ->languages()
            ->pluck('lang')
            ->merge($component->instance()->rareLanguages()->pluck('lang'))
            ->all();

        $this->assertContains('en', $offered);
        $this->assertContains('ja', $offered);
    }

    /**
     * The live catalogue is long-tailed: most installed languages have exactly
     * one source. Rendering all of them inline produces an unusable wall, so
     * only multi-source languages stay visible.
     */
    public function test_singleton_languages_are_split_out_of_the_main_filter(): void
    {
        // Two English sources, plus one language with a single source.
        $this->source('English A');
        $this->source('English B', 1);
        $this->source('Solo', 2)->update(['lang' => 'ja']);

        $component = Livewire::actingAs($this->user)->test('pages::search');

        $this->assertSame(['en'], $component->instance()->languages()->pluck('lang')->all());
        $this->assertSame(['ja'], $component->instance()->rareLanguages()->pluck('lang')->all());
    }

    /**
     * The pills used to render the raw codes, and `es-419` next to `es` asked a
     * reader to know the numbering scheme. The name is the interface; the code
     * is an implementation detail.
     */
    public function test_language_pills_carry_human_names(): void
    {
        $this->source('English Source');
        $this->source('Brazilian Source', 1)->update(['lang' => 'pt-BR']);

        $component = Livewire::actingAs($this->user)->test('pages::search');

        $this->assertSame('English', $component->instance()->languageName('en'));
        $this->assertSame('Portuguese (Brazil)', $component->instance()->languageName('pt-BR'));
        $this->assertSame('qq-ZZ', $component->instance()->languageName('qq-ZZ'), 'An unknown code falls back to itself rather than to an empty label.');
    }

    public function test_a_result_card_carries_a_library_toggle(): void
    {
        config(['queue.default' => 'sync']);

        $this->source('Alpha Manga');

        $component = Livewire::actingAs($this->user)->test('pages::search')
            ->set('query', 'one piece')
            ->call('search');

        $this->assertStringContainsString('data-test="library-toggle"', $component->html());
    }

    public function test_toggling_a_result_adds_it_to_the_library(): void
    {
        config(['queue.default' => 'sync']);

        $this->source('Alpha Manga');

        $component = Livewire::actingAs($this->user)->test('pages::search')
            ->set('query', 'one piece')
            ->call('search')
            ->call('toggleLibrary', 1)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('series_states', [
            'user_id' => $this->user->id,
            'suwayomi_manga_id' => 1,
            'in_library' => true,
        ]);

        $this->assertTrue($component->instance()->libraryMap()[1]);
    }

    public function test_toggling_again_removes_it_from_the_library(): void
    {
        config(['queue.default' => 'sync']);

        $this->source('Alpha Manga');

        Livewire::actingAs($this->user)->test('pages::search')
            ->set('query', 'one piece')
            ->call('search')
            ->call('toggleLibrary', 1)
            ->call('toggleLibrary', 1)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('series_states', [
            'user_id' => $this->user->id,
            'suwayomi_manga_id' => 1,
            'in_library' => false,
        ]);
    }

    public function test_library_toggles_are_scoped_to_the_signed_in_reader(): void
    {
        config(['queue.default' => 'sync']);

        $mallory = User::factory()->create();
        $this->source('Alpha Manga');

        Livewire::actingAs($this->user)->test('pages::search')
            ->set('query', 'one piece')
            ->call('search')
            ->call('toggleLibrary', 1)
            ->assertHasNoErrors();

        $this->assertSame(0, SeriesState::forUser($mallory->id)->count());
    }

    /**
     * Two sources reporting the same display name. The card used to read
     * "+1 source:" and name nobody, because unique() collapsed the alternative
     * into the primary's name before the template sliced it off.
     */
    public function test_a_group_with_a_same_named_alternative_still_names_it(): void
    {
        config(['queue.default' => 'sync']);

        $this->source('Twin Source');
        $this->source('Twin Source', 1);

        $component = Livewire::actingAs($this->user)->test('pages::search')
            ->set('query', 'one piece')
            ->call('search');

        $group = $component->instance()->groups()->first();

        $this->assertSame(2, $group->sourceCount());
        $this->assertSame(['Twin Source'], $group->alternativeNames());
        $this->assertSame(['Twin Source'], $group->sourceNames());
    }

    public function test_a_reader_with_no_installed_source_is_sent_to_sources_rather_than_accused(): void
    {
        /*
         * The F26 walk's last stuck seam: a fresh reader lands on an empty
         * library, presses Go to search, and the one line waiting there used to
         * be "No sources match these filters.", which names a problem their
         * filters never touched, because they own no filter yet. Zero installed
         * is a different situation from filters gone too narrow, so the two
         * shapes are asserted apart, and the old line keeps its own case: one
         * installed source, langs narrowed to a language no source carries.
         */
        Livewire::actingAs($this->user)->test('pages::search')
            ->assertOk()
            ->assertSee('No source is installed to search with.')
            ->assertSee('Go to Sources')
            ->assertDontSee('No sources match these filters.');

        $this->source('Alpha Manga');

        Livewire::actingAs($this->user)->test('pages::search')
            ->set('langs', ['xx'])
            ->assertOk()
            ->assertSee('No sources match these filters.')
            ->assertDontSee('No source is installed to search with.');
    }

    /**
     * The pin is the reader's search scope, so the cost line stops being a count of
     * everything and becomes a count of the pinned run. The same seam writes both, which is
     * what makes this test meaningful against drift elsewhere: the page reads
     * `eligibleSources()` and the run reads `targets()`, and one seam means they cannot
     * disagree here without disagreeing everywhere.
     */
    public function test_pins_narrow_the_cost_line(): void
    {
        $alpha = $this->source('Alpha Manga');
        $this->source('Beta Manga', 1);
        $this->source('Gamma Manga', 2);

        UserSource::setPinnedFor($this->user, [$alpha->suwayomi_source_id], true);

        $component = Livewire::actingAs($this->user)->test('pages::search');

        $this->assertSame(1, $component->instance()->eligibleSources());
        $component->assertSee('Searching 1 source')
            ->assertDontSee('Searching 3');
    }

    /**
     * "No results" with pins active must never read as "nobody has this": the reader has
     * chosen to ask fewer places, and the page says which places it passed over.
     */
    public function test_pins_put_the_passed_over_sources_on_the_page(): void
    {
        $alpha = $this->source('Alpha Manga');
        $this->source('Beta Manga', 1);
        $this->source('Gamma Manga', 2);

        UserSource::setPinnedFor($this->user, [$alpha->suwayomi_source_id], true);

        Livewire::actingAs($this->user)->test('pages::search')
            ->assertOk()
            ->assertSee('installed but not pinned')
            ->assertSee('Beta Manga')
            ->assertSee('Gamma Manga');
    }

    /**
     * The frozen half of the rule: a reader with no pins gets the page that existed before
     * the pin was built, with no pin copy anywhere in it.
     */
    public function test_a_reader_without_pins_sees_no_pin_copy_at_all(): void
    {
        $this->source('Alpha Manga');
        $this->source('Beta Manga', 1);

        Livewire::actingAs($this->user)->test('pages::search')
            ->assertOk()
            ->assertSee('Searching 2 sources')
            ->assertDontSee('not pinned');
    }
}
