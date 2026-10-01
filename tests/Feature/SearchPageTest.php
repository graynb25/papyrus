<?php

namespace Tests\Feature;

use App\Models\SearchResult;
use App\Models\SearchRun;
use App\Models\SearchSourceResult;
use App\Models\Source;
use App\Models\User;
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

        $component->set('content', [Source::SAFE, Source::NSFW])
            ->call('search');

        $this->assertSame(2, $component->instance()->eligibleSources());
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
}
