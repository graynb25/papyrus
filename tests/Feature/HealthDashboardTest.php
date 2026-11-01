<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\SourceHealth;
use App\Models\User;
use App\Models\UserSource;
use App\Search\HealthDashboard;
use App\Search\HealthProbe;
use App\Search\SearchFanout;
use App\Suwayomi\AuthManager;
use App\Suwayomi\Gateway;
use App\Suwayomi\Transport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\MintsTokens;
use Tests\Support\RecordingHealthGateway;
use Tests\TestCase;

class HealthDashboardTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private User $user;

    private int $nextId = 8000000000000000001;

    /**
     * What the fake says when the health probe asks for a source count.
     *
     * A property rather than a second Http::fake(), because a later fake does
     * not replace the first: the earliest matching stub stays in charge.
     */
    private bool $probeUnreachable = false;

    /**
     * How many liveness probes the fake has answered, not counting login.
     */
    private int $probeRequests = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->fakeSuwayomi();
    }

    /**
     * The one fake behind every request this page can make.
     *
     * The health page now asks Suwayomi whether it is answering before it
     * draws its counts. A test that mounted the page without this would either
     * reach the configured server or fail on its absence, and a suite that
     * passes only while the developer's Suwayomi happens to be up is not a
     * suite. `preventStrayRequests` is the belt to the fake's braces.
     */
    private function fakeSuwayomi(): void
    {
        Http::preventStrayRequests();

        Http::fake(function (Request $request) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'mutation Login')) {
                return Http::response([
                    'data' => ['login' => [
                        'accessToken' => $this->accessToken(),
                        'refreshToken' => $this->refreshToken(),
                    ]],
                ]);
            }

            if (str_contains($query, 'HealthProbe')) {
                $this->probeRequests++;

                if ($this->probeUnreachable) {
                    return Http::response('The server is not answering.', 503);
                }

                return Http::response(['data' => ['sources' => ['totalCount' => 108]]]);
            }

            return Http::response(['data' => []]);
        });
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

        $this->get('/health')->assertRedirect(route('login'));
    }

    public function test_the_page_explains_that_health_is_based_on_searches_not_a_live_source_status(): void
    {
        $this->page()
            ->assertSee('Health shows what Papyrus has seen while searching.')
            ->assertSee('Suwayomi does not report a live status for each source.');
    }

    public function test_the_page_has_a_way_back_to_sources(): void
    {
        $this->page()->assertSee('Back to sources');
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

    public function test_a_site_row_shows_a_compact_summary_of_its_broken_variants(): void
    {
        $good = $this->source('https://mangadex.org', 'en', 'MangaDex EN');
        $bad = $this->source('https://mangadex.org', 'ja', 'MangaDex JA');

        $this->health($good, ['total_successes' => 10]);
        $this->health($bad, ['consecutive_failures' => 3, 'is_dead' => false]);

        $this->page()
            ->assertSee('2 variants')
            ->assertSee('1 failing');
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

    /**
     * The probe asks the one question the page needs, inside a budget short
     * enough that a click cannot wait out the default 28 second one.
     *
     * HTTP faking cannot see a client timeout, so this one test swaps the
     * gateway for a double and reads the argument the probe actually passed.
     */
    public function test_the_liveness_probe_asks_for_a_source_count_with_a_two_second_budget(): void
    {
        $gateway = new RecordingHealthGateway(app(Transport::class), app(AuthManager::class));
        $this->app->instance(Gateway::class, $gateway);

        $this->assertTrue(app(HealthProbe::class)->reachable());

        $this->assertCount(1, $gateway->documents);
        $this->assertStringContainsString('sources { totalCount }', $gateway->documents[0]);
        $this->assertSame([2], $gateway->timeouts);
    }

    /**
     * A reachable answer is one request for a minute, then asked again.
     *
     * The page draws the answer on every render, so a miss on each one would
     * make every click pay for a round trip the reader did not ask for.
     */
    public function test_a_reachable_answer_is_one_request_for_the_minute(): void
    {
        $probe = app(HealthProbe::class);

        $this->assertTrue($probe->reachable());
        $this->assertTrue($probe->reachable());
        $this->assertSame(1, $this->probeRequests);

        $this->travel(59)->seconds();

        $this->assertTrue($probe->reachable());
        $this->assertSame(1, $this->probeRequests, '59 seconds still fits the minute');

        $this->travel(2)->seconds();

        $this->assertTrue($probe->reachable());
        $this->assertSame(2, $this->probeRequests, 'the minute has passed, so the answer is asked again');
    }

    /**
     * A failure is cached as a failure, not as an absent answer.
     *
     * Cache::remember treats null as a miss, and false is not null. Without
     * that, an unreachable server would be asked once per render, which is the
     * one moment the page must stay cheap.
     */
    public function test_an_unreachable_answer_is_cached_too(): void
    {
        $this->probeUnreachable = true;

        $probe = app(HealthProbe::class);

        $this->assertFalse($probe->reachable());
        $this->assertSame(1, $this->probeRequests);

        $this->probeUnreachable = false;

        $this->assertFalse($probe->reachable(), 'the false answer is a cached fact, not a missing one');
        $this->assertSame(1, $this->probeRequests);

        $this->travel(61)->seconds();

        $this->assertTrue($probe->reachable());
        $this->assertSame(2, $this->probeRequests);
    }

    /**
     * The counts are a ledger of observations, so they survive a dead server.
     *
     * What must not survive is the implication that they are current. The line
     * says exactly which fact changed, and it sits above the counts it is
     * qualifying.
     */
    public function test_the_page_names_the_counts_as_old_observations_when_suwayomi_is_unreachable(): void
    {
        $this->probeUnreachable = true;
        $this->health($this->source('https://a.test'));

        $page = $this->page()
            ->assertSee('Not checked now, Suwayomi is unreachable. The counts below are the last observations.')
            ->assertSee('https://a.test');

        $this->assertMatchesRegularExpression(
            '/Not checked now, Suwayomi is unreachable\..*grid grid-cols-2/s',
            $page->html(),
            'the line sits above the counts it qualifies',
        );
    }

    public function test_the_page_says_nothing_new_when_suwayomi_answers(): void
    {
        $this->health($this->source('https://a.test'));

        $this->page()->assertDontSee('Not checked now, Suwayomi is unreachable.');
    }

    /**
     * A link to a site must open that site, not a page it happens to be on.
     *
     * The list is ordered trouble first and then by site, and the live
     * MangaDot entry sits on page two of the healthy rows. A filter that did
     * not also ask for page one would set openSite on a row the reader cannot
     * see, which is how the chip looked broken.
     */
    public function test_a_linked_site_is_shown_on_the_first_page_even_when_the_list_would_have_paged_it_away(): void
    {
        for ($index = 1; $index <= 30; $index++) {
            $this->health($this->source(
                sprintf('https://site%02d.test', $index),
                'en',
                sprintf('Site %02d', $index),
            ));
        }

        $this->health($this->source('https://zz-target.test', 'en', 'Target EN'));

        $this->assertNotContains(
            'https://zz-target.test',
            app(HealthDashboard::class)->sites()->pluck('site')->all(),
            'the fixture really did fall on page two',
        );

        Livewire::withQueryParams(['site' => 'https://zz-target.test', 'page' => 2])
            ->test('pages::health')
            ->assertSet('openSite', 'https://zz-target.test')
            ->assertSee('Target EN')
            ->assertDontSee('https://site01.test');
    }

    /**
     * Closing the open site returns the reader to the list they came from.
     *
     * The filter exists because openSite is set. Clearing it has to clear the
     * filter with it, or the collapse leaves the page showing one row and no
     * way back.
     */
    public function test_collapsing_a_linked_site_restores_the_list(): void
    {
        for ($index = 1; $index <= 30; $index++) {
            $this->health($this->source(
                sprintf('https://site%02d.test', $index),
                'en',
                sprintf('Site %02d', $index),
            ));
        }

        $this->health($this->source('https://zz-target.test', 'en', 'Target EN'));

        $this->page()->set('openSite', 'https://zz-target.test')
            ->assertDontSee('https://site01.test')
            ->call('toggleSite', 'https://zz-target.test')
            ->assertSet('openSite', '')
            ->assertSee('https://site01.test');
    }

    /**
     * A site the ledger does not hold is not an empty search.
     *
     * The generic empty state tells a reader to run a search, which is the
     * wrong instruction for someone who followed a link to a site that has
     * since left the catalogue. Naming the site, and offering the full list
     * back, is the truthful answer to the address they arrived with.
     */
    public function test_a_linked_site_the_ledger_does_not_have_is_named_rather_than_called_an_empty_search(): void
    {
        $this->health($this->source('https://known.test'));

        Livewire::withQueryParams(['site' => 'https://gone.test'])
            ->test('pages::health')
            ->assertSee('No site in the health ledger matches https://gone.test.')
            ->assertSee('Clear the site filter to see every site.')
            ->assertSee('Show every site')
            ->assertDontSee('Run a search');
    }

    /**
     * A linked site longer than the column it would match is dropped.
     *
     * `sources.home_url` is a varchar(255), so a longer address cannot name a
     * row. Printing it back as though it were a site would turn a truncated
     * link into a claim about a site that does not exist.
     */
    public function test_a_linked_site_longer_than_the_home_url_column_is_dropped_on_mount(): void
    {
        $this->health($this->source('https://known.test'));

        $long = 'https://'.str_repeat('a', 250).'.test';

        $this->assertGreaterThan(255, mb_strlen($long));

        Livewire::withQueryParams(['site' => $long])
            ->test('pages::health')
            ->assertSet('openSite', '')
            ->assertSee('https://known.test')
            ->assertDontSee($long);
    }
}
