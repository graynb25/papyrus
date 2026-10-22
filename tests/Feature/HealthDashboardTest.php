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

    /**
     * The report this fixes: 223 sources installed, 0 rows, six zeros, no explanation.
     */
    public function test_a_source_nobody_has_searched_is_counted_as_never_asked(): void
    {
        $this->source('https://a.test');
        $this->source('https://b.test');

        $this->assertSame(0, SourceHealth::query()->count(), 'nothing observed it yet');

        SourceHealth::seedForInstalledSources();

        $tally = app(HealthDashboard::class)->siteTally();

        $this->assertSame(2, $tally['unobserved'], 'they are visible, and visibly unasked');
        $this->assertSame(0, $tally['healthy'], 'not mistaken for working');
        $this->assertSame(2, $tally['sites']);
    }

    /**
     * Two sites, several variants. The dashboard's grain is the site, so the seeded rows have
     * to roll up the same way an observed one does.
     */
    public function test_seeded_sources_roll_up_by_site_like_observed_ones(): void
    {
        $this->source('https://a.test', 'en');
        $this->source('https://a.test', 'es');

        SourceHealth::seedForInstalledSources();

        $sites = app(HealthDashboard::class)->sites();

        $this->assertCount(1, $sites);
        $this->assertSame(2, (int) $sites->first()->variants);
    }

    public function test_seeding_never_resets_an_observation(): void
    {
        $source = $this->source('https://a.test');
        $observed = $this->health($source, ['total_successes' => 7, 'last_success_at' => now()]);

        $this->assertSame(0, SourceHealth::seedForInstalledSources(), 'nothing was missing');

        $observed->refresh();

        $this->assertSame(7, $observed->total_successes, 'a real reading is not a blank one');
        $this->assertNotNull($observed->last_success_at);
    }

    public function test_seeding_is_safe_to_run_twice(): void
    {
        $this->source('https://a.test');
        $this->source('https://b.test');

        $this->assertSame(2, SourceHealth::seedForInstalledSources());
        $this->assertSame(0, SourceHealth::seedForInstalledSources(), 'the second run creates nothing');
        $this->assertSame(2, SourceHealth::query()->count());
    }

    /**
     * A seed that carried a timestamp would read as an observation, which is the one thing
     * "never asked" must not be. `last_success_at` in particular has to stay null.
     */
    public function test_a_seeded_row_carries_no_timestamp(): void
    {
        $this->source('https://a.test');

        SourceHealth::seedForInstalledSources();

        $row = SourceHealth::query()->firstOrFail();

        $this->assertNull($row->last_success_at);
        $this->assertNull($row->last_error_at);
        $this->assertNull($row->marked_dead_at);
        $this->assertNull($row->successRate(), 'unknown, not zero and not a hundred percent');
        $this->assertSame(0, $row->total_successes);
        $this->assertFalse($row->is_dead);
    }

    public function test_an_uninstalled_source_is_not_seeded(): void
    {
        $gone = $this->source('https://gone.test');
        $gone->update(['is_installed' => false]);

        SourceHealth::seedForInstalledSources();

        $this->assertSame(0, SourceHealth::query()->count(), 'health describes what is there now');
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

    /**
     * The filter is named.
     *
     * A placeholder is not a name: it goes the moment the reader types, and a
     * screen reader announces a control with no name as an edit box. The words
     * are the ones the box already shows, so the name and the visible text
     * agree.
     */
    public function test_the_filter_is_named(): void
    {
        $this->health($this->source('https://good.test'));

        $this->page()
            ->assertSee('aria-label="Filter by site or source name"', false);
    }

    /**
     * The row that opens a site says which site it opens.
     *
     * A row of chevrons is a row of buttons a screen reader announces
     * identically without the site's name in it, and there is one of them for
     * every site the ledger knows.
     */
    public function test_the_row_that_opens_a_site_is_named(): void
    {
        $this->health($this->source('https://mangadex.org', 'en', 'MangaDex (EN)'));

        $this->page()
            ->assertSee('aria-label="Show the sources of MangaDex (EN)"', false);
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

    /**
     * The pin is the mirror of the skip: one writes `disabled`, the other writes
     * `pinned_at`, and a run that reads both gets the intersection. The same two
     * assertions skip carries, on the same row grain.
     */
    public function test_pinning_a_site_marks_every_variant_for_this_reader_only(): void
    {
        $this->health($this->source('https://mangadex.org', 'en', 'MangaDex en'));
        $this->health($this->source('https://mangadex.org', 'ja', 'MangaDex ja'));
        $bob = User::factory()->create();

        $this->page()->call('pin', 'https://mangadex.org');

        $this->assertSame(2, UserSource::forUser($this->user->id)->whereNotNull('pinned_at')->count());
        $this->assertSame(0, UserSource::forUser($bob->id)->whereNotNull('pinned_at')->count());
    }

    public function test_pinning_narrows_the_search_to_the_pinned_site(): void
    {
        $mangadex = $this->source('https://mangadex.org', 'en', 'MangaDex en');
        $other = $this->source('https://other.test', 'en', 'Other EN');

        $this->page()->call('pin', 'https://mangadex.org');

        $after = app(SearchFanout::class)->targets($this->user)->pluck('suwayomi_source_id')->all();
        $this->assertSame([$mangadex->suwayomi_source_id], $after, 'Only the pinned site runs.');
        $this->assertNotContains($other->suwayomi_source_id, $after);
    }

    public function test_unpinning_returns_the_search_to_every_runnable_source(): void
    {
        $this->health($this->source('https://mangadex.org', 'en', 'MangaDex en'));
        $this->health($this->source('https://other.test', 'en', 'Other EN'));

        $this->page()->call('pin', 'https://mangadex.org')->call('unpin', 'https://mangadex.org');

        $this->assertSame(2, app(SearchFanout::class)->targets($this->user)->count());
    }

    public function test_a_pinned_site_is_labelled_and_can_be_cleared(): void
    {
        $this->health($this->source('https://mangadex.org', 'en'));

        $this->page()->call('pin', 'https://mangadex.org')
            ->assertSee('pinned by you')
            ->assertSee('Pinned')
            ->call('unpin', 'https://mangadex.org')
            ->assertDontSee('pinned by you');
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
