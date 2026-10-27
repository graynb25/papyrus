<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\User;
use App\Models\UserSource;
use App\Search\SearchFanout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The fan-out's slice is taken round robin over extension packages.
 *
 * ## Why this test exists
 *
 * A reader reported that a search only returned MangaDex and Atsumaru, and the cause was
 * not a bug. `targets()` ordered by name, the search page capped at 40, and 61 of the 82
 * sources are named "MangaDex (...)", so the 40 that survived were ten scanlation sites
 * plus thirty MangaDex languages. MangaFire (7 sources), MangaTaro, Thunder Scans and Weeb
 * Central were never dispatched at all. A limit applied to a sort nobody chose.
 *
 * The measurement that matters is not which sources win, it is that **no site contributes a
 * second language until every site has contributed a first one.** That is the property the
 * round robin buys, and it is the one that can be asserted without pinning a specific list.
 */
class SearchFanoutInterleaveTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * One source per package, with the given package prefix.
     *
     * @return array<int, Source>
     */
    private function sources(string $pkg, int $count, int $from = 1): array
    {
        $made = [];

        foreach (range($from, $from + $count - 1) as $i) {
            $made[] = Source::query()->create([
                'suwayomi_source_id' => crc32($pkg.$i),
                'name' => "{$pkg} ({$i})",
                'display_name' => "{$pkg} ({$i})",
                'extension_pkg_name' => $pkg,
                'lang' => 'en',
                'is_installed' => true,
                'supports_latest' => true,
            ]);
        }

        return $made;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function targets(array $options = []): Collection
    {
        return app(SearchFanout::class)->targets($this->user, $options);
    }

    public function test_every_site_contributes_a_first_source_before_any_contributes_a_second(): void
    {
        // Three sites, five variants each, so a cut at any round is observable.
        $this->sources('mangadex', 5);
        $this->sources('mangafire', 5);
        $this->sources('asurascans', 5);

        $picked = $this->targets(['limit' => 4]);

        $this->assertSame(4, $picked->count());

        /*
         * The first three must be three different sites. Under the old alphabetical order
         * this was two MangaDex variants and one MangaFire, because "asurascans" sorts
         * before "mangadex" but the fourth slot came back round to MangaDex.
         */
        $this->assertCount(
            3,
            $picked->take(3)->pluck('extension_pkg_name')->unique(),
            'The first round must be one source per site, not three sources from whichever sites sort first.'
        );

        $this->assertSame(
            ['asurascans', 'mangadex', 'mangafire'],
            $picked->take(3)->pluck('extension_pkg_name')->all(),
            'Sites take their first turn in name order, so the round is predictable rather than arbitrary.'
        );

        // And the fourth slot is the second asurascans variant, because every site has now
        // had a turn and the walk returns to the first bucket.
        $this->assertSame('asurascans', $picked->last()->extension_pkg_name);
        $this->assertSame('asurascans (2)', $picked->last()->name);
    }

    public function test_the_round_robin_walks_depth_before_it_walks_sites(): void
    {
        $this->sources('mangadex', 4);
        $this->sources('weebcentral', 2);

        $picked = $this->targets(['limit' => 6])->pluck('extension_pkg_name')->all();

        $this->assertSame(
            ['mangadex', 'weebcentral', 'mangadex', 'weebcentral', 'mangadex', 'mangadex'],
            $picked
        );
    }

    public function test_the_cap_still_holds(): void
    {
        $this->sources('mangadex', 30);
        $this->sources('mangafire', 30);
        $this->sources('asurascans', 30);

        $this->assertSame(40, $this->targets(['limit' => 40])->count());
        $this->assertSame(5, $this->targets(['limit' => 5])->count());
        $this->assertSame(90, $this->targets(['limit' => -1])->count(), 'A negative limit means every source, as SQL reads it.');
        $this->assertSame(0, $this->targets(['limit' => 0])->count(), 'A zero limit means none, as SQL reads it.');
    }

    /**
     * The page's denominator is the runnable universe, not the capped slice.
     *
     * It has to be the same read `targets()` starts from, or the page can name a total
     * that no run could ever draw from. The limit is deliberately included in the
     * options and deliberately makes no difference to the count.
     */
    public function test_the_runnable_count_is_the_universe_before_the_cap(): void
    {
        $this->sources('mangadex', 30);
        $this->sources('mangafire', 30);
        $this->sources('asurascans', 30);

        $fanout = app(SearchFanout::class);
        $capped = ['limit' => 40];

        $this->assertSame(90, $fanout->runnableCount($this->user, $capped));
        $this->assertSame(40, $fanout->targets($this->user, $capped)->count());
        $this->assertSame(
            50,
            $fanout->runnableCount($this->user, $capped) - $fanout->targets($this->user, $capped)->count(),
            'The difference is the number of runnable sources the cap skips.',
        );
        $this->assertSame(90, $fanout->runnableCount($this->user, ['limit' => -1]), 'The denominator does not move with the cap.');
    }

    /**
     * The filters are the same ones `targets()` applies.
     *
     * A count built in the page from a different query would drift the moment a language
     * or content filter changed. This proves the public count and the run disagree about
     * nothing when a language is selected.
     */
    public function test_the_runnable_count_applies_the_same_language_filter(): void
    {
        $this->sources('english', 3);

        foreach ($this->sources('japanese', 2) as $source) {
            $source->update(['lang' => 'ja']);
        }

        $fanout = app(SearchFanout::class);
        $options = ['langs' => ['ja'], 'limit' => 40];

        $this->assertSame(2, $fanout->runnableCount($this->user, $options));
        $this->assertSame(2, $fanout->targets($this->user, $options)->count());
    }

    public function test_the_slice_is_deterministic(): void
    {
        $this->sources('mangadex', 6);
        $this->sources('mangafire', 6);
        $this->sources('asurascans', 6);

        $first = $this->targets(['limit' => 8])->pluck('suwayomi_source_id')->all();
        $second = $this->targets(['limit' => 8])->pluck('suwayomi_source_id')->all();

        $this->assertSame($first, $second, 'Two runs over the same catalogue must pick the same sources.');
    }

    public function test_a_source_with_no_extension_gets_its_own_bucket(): void
    {
        $this->sources('mangadex', 4);

        // The local source has no package, and must not be filed with the others.
        Source::query()->create([
            'suwayomi_source_id' => 999,
            'name' => 'Local source (other)',
            'display_name' => 'Local source (other)',
            'extension_pkg_name' => '',
            'lang' => 'other',
            'is_installed' => true,
            'supports_latest' => false,
        ]);

        $picked = $this->targets(['limit' => 5]);

        $this->assertSame(
            2,
            $picked->take(2)->pluck('extension_pkg_name')->unique()->count(),
            'An extension-less source is its own site, so it gets its own turn.'
        );
    }

    public function test_disabled_and_dead_sources_are_still_excluded_before_the_round_robin(): void
    {
        $this->sources('mangadex', 3);
        $skipped = $this->sources('asurascans', 3, from: 90);

        // A disabled source is dropped before the round robin runs, so the cap spends its
        // slots on the sources that are still eligible.
        UserSource::setDisabledFor($this->user, [(int) $skipped[0]->suwayomi_source_id], true);

        $picked = $this->targets(['limit' => 10]);

        $this->assertNotContains(
            $skipped[0]->suwayomi_source_id,
            $picked->pluck('suwayomi_source_id')->all(),
            'A disabled source is dropped before the cap is applied.'
        );

        $this->assertContains(
            $skipped[1]->suwayomi_source_id,
            $picked->pluck('suwayomi_source_id')->all(),
            'Its two enabled siblings still take their turn, so the exclusion is per source and not per site.'
        );
    }

    /**
     * The reader's actual complaint, as a measurement.
     *
     * Mirrors the real catalogue: 16 packages, MangaDex carrying 61 language variants, and a
     * limit of 40. Under the alphabetical order the five sites whose names sort after
     * "MangaDex" were unreachable. Under the round robin every package gets in.
     */
    public function test_the_sites_that_sort_last_are_now_reachable(): void
    {
        $this->sources('eu.kanade.tachiyomi.extension.all.mangadex', 61);
        $this->sources('eu.kanade.tachiyomi.extension.all.mangafire', 7);
        $this->sources('eu.kanade.tachiyomi.extension.en.mangataro', 1);
        $this->sources('eu.kanade.tachiyomi.extension.en.thunderscans', 1);
        $this->sources('eu.kanade.tachiyomi.extension.en.weebcentral', 1);

        $picked = $this->targets(['limit' => 40])->pluck('extension_pkg_name')->unique()->all();

        $this->assertCount(
            5,
            $picked,
            'All five packages get in. Under the alphabetical order, three of them never did.'
        );

        $this->assertContains('eu.kanade.tachiyomi.extension.en.weebcentral', $picked);
        $this->assertContains('eu.kanade.tachiyomi.extension.en.thunderscans', $picked);
        $this->assertContains('eu.kanade.tachiyomi.extension.all.mangafire', $picked);
    }
}
