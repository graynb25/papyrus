<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\SourceHealth;
use App\Models\User;
use App\Models\UserSource;
use App\Search\HealthDashboard;
use App\Search\SearchFanout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class HealthDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $nextId = 8000000000000000001;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /**
     * A source with a live-shaped id, since those exceed 2^53 in practice.
     */
    private function source(?string $homeUrl, string $lang = 'en', ?string $name = null): Source
    {
        return Source::create([
            'suwayomi_source_id' => $this->nextId++,
            'name' => $name ?? 'Test Source',
            'display_name' => $name ?? 'Test Source',
            'extension_pkg_name' => 'eu.kanade.tachiyomi.extension.all.test',
            'lang' => $lang,
            'content_warning' => Source::SAFE,
            'home_url' => $homeUrl,
            'is_installed' => true,
        ]);
    }

    private function health(Source $source, array $overrides = []): SourceHealth
    {
        return SourceHealth::create(array_merge([
            'source_id' => $source->suwayomi_source_id,
            'name' => $source->name,
            'lang' => $source->lang,
            'consecutive_failures' => 0,
            'consecutive_empty' => 0,
            'total_successes' => 5,
            'total_failures' => 0,
            'avg_latency_ms' => 300,
            'is_dead' => false,
        ], $overrides));
    }

    private function page(): Testable
    {
        return Livewire::test('pages::health');
    }

    public function test_it_requires_authentication(): void
    {
        auth()->logout();

        $this->get('/health')->assertRedirect();
    }

    public function test_it_lists_one_row_per_site_not_per_source(): void
    {
        // The shape that matters: MangaDex arrives as 61 language variants.
        foreach (['en', 'ja', 'de', 'fr', 'es'] as $lang) {
            $this->health($this->source('https://mangadex.org', $lang, 'MangaDex '.$lang));
        }

        $this->health($this->source('https://mangafire.to', 'en', 'MangaFire'));

        $this->assertSame(2, app(HealthDashboard::class)->siteTally()['sites']);

        $html = $this->page()->html();

        // Two sites, not seven sources, and the site URLs are what appear.
        $this->assertStringContainsString('mangadex.org', $html);
        $this->assertStringContainsString('mangafire.to', $html);
    }

    /**
     * Without grouping this row would be MangaDex five times over, and the page
     * would show 7 sites where there are 2.
     */
    public function test_several_variants_of_one_site_collapse_into_a_single_row(): void
    {
        foreach (['en', 'ja', 'de', 'fr', 'es'] as $lang) {
            $this->health($this->source('https://mangadex.org', $lang, 'MangaDex '.$lang));
        }

        $this->health($this->source('https://mangafire.to'));

        $this->assertSame(2, app(HealthDashboard::class)->sites()->total());
        $this->assertSame(5, (int) app(HealthDashboard::class)->sites()->first()->variants);
    }

    public function test_a_site_is_only_as_healthy_as_its_worst_variant(): void
    {
        $this->health($this->source('https://mangadex.org', 'en'), ['total_successes' => 10]);
        $this->health($this->source('https://mangadex.org', 'de'), ['consecutive_failures' => 4, 'is_dead' => true]);

        $dashboard = app(HealthDashboard::class);
        $site = $dashboard->sites()->first();

        $this->assertSame(HealthDashboard::STATE_DEAD, $site->state);
        $this->assertSame(2, (int) $site->variants);
    }

    public function test_an_unobserved_source_is_not_reported_as_healthy(): void
    {
        $this->health($this->source('https://quiet.test'), [
            'total_successes' => 0,
            'total_failures' => 0,
            'consecutive_empty' => 0,
        ]);

        $dashboard = app(HealthDashboard::class);

        $this->assertSame(HealthDashboard::STATE_UNOBSERVED, $dashboard->sites()->first()->state);
        $this->assertSame(1, $dashboard->siteTally()[HealthDashboard::STATE_UNOBSERVED]);
        $this->assertSame(0, $dashboard->siteTally()[HealthDashboard::STATE_HEALTHY]);
    }

    public function test_a_site_that_answers_but_never_matches_is_going_quiet(): void
    {
        $this->health($this->source('https://corpse.test'), ['consecutive_empty' => 25]);

        $dashboard = app(HealthDashboard::class);

        $this->assertSame(HealthDashboard::STATE_SILENT, $dashboard->sites()->first()->state);
        $this->assertSame(1, $dashboard->siteTally()[HealthDashboard::STATE_SILENT]);
    }

    /**
     * One failure is noise. Two is a pattern, and the tile has to agree with the
     * recorder's own dead threshold being five.
     */
    public function test_a_single_failure_does_not_make_a_site_failing(): void
    {
        $this->health($this->source('https://flaky.test'), ['consecutive_failures' => 1]);

        $this->assertSame(
            HealthDashboard::STATE_HEALTHY,
            app(HealthDashboard::class)->sites()->first()->state,
        );
    }

    public function test_the_tiles_and_the_rows_cannot_disagree(): void
    {
        $this->health($this->source('https://good.test'));
        $this->health($this->source('https://bad.test'), ['consecutive_failures' => 3, 'is_dead' => true]);
        $this->health($this->source('https://quiet.test'), ['consecutive_empty' => 30]);
        $this->health($this->source('https://never.test'), ['total_successes' => 0, 'total_failures' => 0]);

        $dashboard = app(HealthDashboard::class);
        $tally = $dashboard->siteTally();
        $fromRows = [];

        foreach ($dashboard->sites([], perPage: 50) as $row) {
            $fromRows[$row->state] = ($fromRows[$row->state] ?? 0) + 1;
        }

        foreach (HealthDashboard::STATES as $state) {
            $this->assertSame(
                $tally[$state],
                $fromRows[$state] ?? 0,
                "Tile and row count disagree for {$state}.",
            );
        }

        $this->assertSame(4, $tally['sites']);
    }

    public function test_a_source_with_no_home_url_is_still_reported(): void
    {
        // Suwayomi's built-in local source publishes no home_url, and dropping it
        // would quietly shrink the catalogue by one every time it syncs.
        $this->health($this->source(null));

        $this->assertSame(1, app(HealthDashboard::class)->siteTally()['sites']);
        $this->assertSame('unknown', app(HealthDashboard::class)->sites()->first()->site);
    }

    /**
     * A site whose home_url genuinely contains the word "unknown" must not be
     * conflated with the no-home_url bucket, or the drill-down for one would list
     * the other's variants.
     */
    public function test_a_site_named_unknown_is_not_the_no_url_bucket(): void
    {
        $this->health($this->source('https://unknown.example.test', 'en', 'Real Site'));
        $this->health($this->source(null, 'en', 'Local Source'));

        $dashboard = app(HealthDashboard::class);

        $this->assertSame(2, $dashboard->siteTally()['sites']);
        $this->assertCount(1, $dashboard->variantsOf('unknown'));
        $this->assertSame(
            'Local Source',
            $dashboard->variantsOf('unknown')->first()->name,
            'The unknown bucket is the no-home_url source only.',
        );
    }

    public function test_the_state_filter_narrows_the_list(): void
    {
        $this->health($this->source('https://good.test'));
        $this->health($this->source('https://bad.test'), ['consecutive_failures' => 3, 'is_dead' => true]);

        $this->page()
            ->set('state', 'dead')
            ->assertSee('bad.test')
            ->assertDontSee('good.test');
    }

    public function test_the_search_matches_a_site_or_any_of_its_variants(): void
    {
        $this->health($this->source('https://mangadex.org', 'en', 'MangaDex (EN)'));
        $this->health($this->source('https://example.test', 'en', 'Something Else'));

        $this->page()
            ->set('search', 'mangadex')
            ->assertSee('mangadex.org')
            ->assertDontSee('example.test');
    }

    public function test_the_drill_down_lists_every_language_variant(): void
    {
        foreach (['en', 'ja', 'de'] as $lang) {
            $this->health($this->source('https://mangadex.org', $lang, 'MangaDex '.$lang));
        }

        $this->page()
            ->set('openSite', 'https://mangadex.org')
            ->assertSee('MangaDex en')
            ->assertSee('MangaDex ja')
            ->assertSee('MangaDex de')
            ->assertSet('openSite', 'https://mangadex.org');
    }

    public function test_skipping_a_site_disables_every_variant_for_this_user_only(): void
    {
        foreach (['en', 'ja'] as $lang) {
            $this->health($this->source('https://mangadex.org', $lang, 'MangaDex '.$lang));
        }

        $bob = User::factory()->create();

        $this->page()->call('skip', 'https://mangadex.org');

        $this->assertSame(2, UserSource::forUser($this->user->id)->where('disabled', true)->count());
        $this->assertSame(0, UserSource::forUser($bob->id)->where('disabled', true)->count());
    }

    public function test_skipping_actually_removes_the_site_from_the_search_targets(): void
    {
        $source = $this->source('https://mangadex.org', 'en', 'MangaDex EN');
        $this->health($source);

        $before = app(SearchFanout::class)->targets($this->user)->pluck('suwayomi_source_id')->all();
        $this->assertContains($source->suwayomi_source_id, $before);

        $this->page()->call('skip', 'https://mangadex.org');

        $after = app(SearchFanout::class)->targets($this->user)->pluck('suwayomi_source_id')->all();
        $this->assertNotContains($source->suwayomi_source_id, $after);
    }

    public function test_restoring_a_site_puts_it_back_into_the_search_targets(): void
    {
        $source = $this->source('https://mangadex.org');
        $this->health($source);

        $this->page()->call('skip', 'https://mangadex.org')->call('unskip', 'https://mangadex.org');

        $this->assertContains(
            $source->suwayomi_source_id,
            app(SearchFanout::class)->targets($this->user)->pluck('suwayomi_source_id')->all(),
        );
    }

    /**
     * The skip control lives in the drill-down rather than on the row, so a
     * button that retires 61 sources cannot be clicked without the count being
     * on screen. This checks the badge appears on the row and the control is
     * reachable by opening the site.
     */
    public function test_a_skipped_site_is_labelled_and_can_be_restored(): void
    {
        $this->health($this->source('https://mangadex.org', 'en'));
        $this->health($this->source('https://mangadex.org', 'ja'));

        $this->page()->call('skip', 'https://mangadex.org')
            ->assertSee('skipped by you')
            ->assertSee('Skipped')
            ->call('unskip', 'https://mangadex.org')
            ->assertDontSee('skipped by you');
    }

    public function test_trouble_sorts_above_working_which_sorts_above_unobserved(): void
    {
        $this->health($this->source('https://dead.test'), ['consecutive_failures' => 5, 'is_dead' => true]);
        $this->health($this->source('https://quiet.test'), ['consecutive_empty' => 30]);
        $this->health($this->source('https://good.test'));
        $this->health($this->source('https://never.test'), ['total_successes' => 0, 'total_failures' => 0]);

        $order = app(HealthDashboard::class)->sites()->pluck('site')->all();

        $this->assertSame([
            'https://dead.test',
            'https://quiet.test',
            'https://good.test',
            'https://never.test',
        ], $order);
    }

    /**
     * Latency is a site-level number, and averaging it would hide the one slow
     * language variant a reader would actually hit.
     */
    public function test_a_site_reports_its_slowest_variant(): void
    {
        $this->health($this->source('https://mangadex.org', 'en'), ['avg_latency_ms' => 200]);
        $this->health($this->source('https://mangadex.org', 'ja'), ['avg_latency_ms' => 4100]);

        $this->assertSame(4100, (int) app(HealthDashboard::class)->sites()->first()->worst_latency_ms);
    }
}
