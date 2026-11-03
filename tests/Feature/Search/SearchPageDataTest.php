<?php

namespace Tests\Feature\Search;

use App\AniList\Preference as AniListPreference;
use App\AniList\TitleResolver;
use App\Live\Search\SearchFormatting;
use App\Live\Search\SearchPageData;
use App\Models\AniListMedia;
use App\Models\SearchResult;
use App\Models\SearchRun;
use App\Models\SearchSourceResult;
use App\Models\SeriesState;
use App\Models\Source;
use App\Models\User;
use App\Models\UserSource;
use App\Search\SearchFanout;
use App\Search\TitleNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The search page's reads, exercised without mounting the page.
 *
 * These cover the run scoping, the grouping opt-in, the ordering, the language
 * counts and both buckets, the fanout counts and the pinned skipped list, the
 * installed count, the skipped sources, the library map, the chooser reads and the
 * AniList reads with the preference off and on.
 */
class SearchPageDataTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function data(): SearchPageData
    {
        return app(SearchPageData::class);
    }

    private function givenRun(User $user, string $query = 'lantern', string $status = SearchRun::COMPLETE): SearchRun
    {
        return SearchRun::createFor($user, [
            'query' => $query,
            'status' => $status,
            'sources_total' => 1,
            'sources_done' => 1,
            'result_count' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function givenResult(SearchRun $run, int $mangaId, string $title, string $source, array $overrides = []): SearchResult
    {
        return SearchResult::createFor($run->user_id, array_merge([
            'search_run_id' => $run->id,
            'suwayomi_manga_id' => $mangaId,
            'suwayomi_source_id' => 8513894820944573933 + $mangaId,
            'title' => $title,
            'normalized_title' => mb_strtolower($title),
            'url' => '/manga/'.$mangaId,
            'source_name' => $source,
            'content_warning' => 'SAFE',
        ], $overrides));
    }

    private function source(string $name, int $offset, string $lang = 'en', bool $installed = true): Source
    {
        return Source::create([
            'suwayomi_source_id' => 8513894820944573933 + $offset,
            'name' => $name,
            'display_name' => $name,
            'extension_pkg_name' => 'eu.kanade.tachiyomi.extension.'.$lang.'.test'.$offset,
            'lang' => $lang,
            'content_warning' => Source::SAFE,
            'is_installed' => $installed,
            'supports_latest' => true,
        ]);
    }

    // Run scoping ---------------------------------------------------------

    public function test_a_run_is_only_found_for_the_reader_who_owns_it(): void
    {
        $mine = $this->givenRun($this->user);
        $theirs = $this->givenRun(User::factory()->create());

        $data = $this->data();

        $this->assertSame($mine->id, $data->run($this->user, $mine->id)?->id);
        $this->assertNull($data->run($this->user, $theirs->id), 'another reader\'s run is not this page\'s run');
        $this->assertNull($data->run($this->user, null), 'no run on screen means no run to read');
    }

    // Grouping ------------------------------------------------------------

    public function test_groups_do_not_use_the_resolver_while_the_reader_is_opted_out(): void
    {
        $run = $this->givenRun($this->user);
        $this->givenResult($run, 1, 'One Piece', 'Alpha Manga');
        $this->givenResult($run, 2, 'One Piece', 'Akaza Scans');

        $resolver = new class(app(TitleNormalizer::class)) extends TitleResolver
        {
            public function idFor(string $title): ?int
            {
                throw new RuntimeException('the resolver was used while the reader was opted out');
            }
        };

        $data = new SearchPageData(
            app(SearchFanout::class),
            $resolver,
            app(TitleNormalizer::class),
            app(AniListPreference::class),
            new SearchFormatting,
        );

        $groups = $data->groups($this->user, $run, false);

        $this->assertCount(1, $groups);
        $this->assertSame(2, $groups->first()->sourceCount(), 'the title path still collapses the two sources');
    }

    // Outcomes ------------------------------------------------------------

    public function test_outcomes_are_ordered_by_result_count_and_scoped_to_the_reader(): void
    {
        $run = $this->givenRun($this->user);

        SearchSourceResult::createFor($this->user, [
            'search_run_id' => $run->id,
            'suwayomi_source_id' => 1,
            'source_name' => 'Quiet',
            'status' => SearchSourceResult::OK,
            'result_count' => 2,
        ]);

        SearchSourceResult::createFor($this->user, [
            'search_run_id' => $run->id,
            'suwayomi_source_id' => 2,
            'source_name' => 'Busy',
            'status' => SearchSourceResult::OK,
            'result_count' => 9,
        ]);

        $theirs = $this->givenRun(User::factory()->create());
        SearchSourceResult::createFor($theirs->user_id, [
            'search_run_id' => $theirs->id,
            'suwayomi_source_id' => 3,
            'source_name' => 'Not Mine',
            'status' => SearchSourceResult::OK,
            'result_count' => 99,
        ]);

        $this->assertSame(
            ['Busy', 'Quiet'],
            $this->data()->outcomes($this->user, $run)->pluck('source_name')->all(),
        );
    }

    // Languages -----------------------------------------------------------

    public function test_language_counts_are_installed_only_and_keep_both_sort_keys(): void
    {
        $this->source('English One', 1);
        $this->source('English Two', 2);
        $this->source('Japanese One', 3, 'ja');
        $this->source('German One', 4, 'de');
        $this->source('Portuguese One', 5, 'pt-BR', installed: false);

        $counts = $this->data()->languageCounts();

        $this->assertSame(['en', 'de', 'ja'], $counts->pluck('lang')->all());
        $this->assertSame([2, 1, 1], $counts->pluck('total')->all());
    }

    public function test_the_language_buckets_split_more_than_one_from_exactly_one(): void
    {
        $this->source('English One', 1);
        $this->source('English Two', 2);
        $this->source('Japanese One', 3, 'ja');
        $this->source('German One', 4, 'de', installed: false);

        $data = $this->data();
        $counts = $data->languageCounts();

        $this->assertSame(['en'], $data->languages($counts)->pluck('lang')->all());
        $this->assertSame(['ja'], $data->rareLanguages($counts)->pluck('lang')->all());
    }

    // Fanout scope --------------------------------------------------------

    public function test_the_fanout_counts_and_skipped_lists_follow_the_cap_and_pins(): void
    {
        $alpha = $this->source('Alpha Manga', 1);
        $this->source('Beta Manga', 2);
        $this->source('Gamma Manga', 3);

        $data = $this->data();
        $options = ['langs' => null, 'content' => [Source::SAFE, Source::MIXED], 'limit' => 2];

        $this->assertSame(3, $data->runnableSourceCount($this->user, $options));
        $this->assertSame(2, $data->eligibleSources($this->user, $options));
        $this->assertSame([], $data->sourcesNotPinned($this->user, $options)->all());
        $this->assertSame(
            1,
            $data->sourcesSkippedByCap(
                $data->runnableSourceCount($this->user, $options),
                $data->eligibleSources($this->user, $options),
                $data->sourcesNotPinned($this->user, $options),
            ),
        );

        UserSource::setPinnedFor($this->user, [$alpha->suwayomi_source_id], true);

        $pinned = ['langs' => null, 'content' => [Source::SAFE, Source::MIXED], 'limit' => 40];

        $this->assertSame(1, $data->eligibleSources($this->user, $pinned));
        $this->assertSame(['Beta Manga', 'Gamma Manga'], $data->sourcesNotPinned($this->user, $pinned)->pluck('display_name')->all());
        $this->assertSame(
            0,
            $data->sourcesSkippedByCap(
                $data->runnableSourceCount($this->user, $pinned),
                $data->eligibleSources($this->user, $pinned),
                $data->sourcesNotPinned($this->user, $pinned),
            ),
            'with pins active the skipped list is the reader\'s own narrowing, not the cap\'s cut',
        );
    }

    public function test_the_installed_count_ignores_uninstalled_sources(): void
    {
        $this->source('Installed', 1);
        $this->source('Not Installed', 2, installed: false);

        $this->assertSame(1, $this->data()->installedSourceCount());
    }

    // Skipped sources -----------------------------------------------------

    public function test_skipped_sources_are_named_only_after_the_run_finishes(): void
    {
        $run = $this->givenRun($this->user, status: SearchRun::RUNNING);

        SearchSourceResult::createFor($this->user, [
            'search_run_id' => $run->id,
            'suwayomi_source_id' => 1,
            'source_name' => 'Zeta',
            'status' => SearchSourceResult::SKIPPED,
            'error' => 'no status filter',
        ]);

        $this->assertSame([], $this->data()->skippedSources($this->user, $run), 'a source that has not reported yet is a guess');

        $run->finalise();

        SearchSourceResult::createFor($this->user, [
            'search_run_id' => $run->id,
            'suwayomi_source_id' => 2,
            'source_name' => 'Alpha',
            'status' => SearchSourceResult::SKIPPED,
            'error' => 'no genre filter',
        ]);

        $this->assertSame(
            [
                ['source_name' => 'Alpha', 'reason' => 'no genre filter'],
                ['source_name' => 'Zeta', 'reason' => 'no status filter'],
            ],
            $this->data()->skippedSources($this->user, $run),
        );
    }

    // Library and chooser -------------------------------------------------

    public function test_the_library_map_covers_primaries_and_alternatives(): void
    {
        $run = $this->givenRun($this->user);
        $this->givenResult($run, 1, 'Eternally Regressing Knight', 'Manga Demon (EN)');
        $this->givenResult($run, 2, 'Eternally Regressing Knight', 'Asura Scans (EN)');

        $data = $this->data();
        $groups = $data->groups($this->user, $run, false);

        SeriesState::createFor($this->user->id, ['suwayomi_manga_id' => 2, 'in_library' => true]);
        SeriesState::createFor(User::factory()->create()->id, ['suwayomi_manga_id' => 1, 'in_library' => true]);

        $map = $data->libraryMap($this->user, $groups);

        $this->assertTrue($map[2] ?? false, 'the alternative is in the map');
        $this->assertArrayNotHasKey(1, $map, 'another reader\'s state does not leak in');
    }

    public function test_the_chooser_lists_every_source_and_names_the_title(): void
    {
        $run = $this->givenRun($this->user);
        $this->givenResult($run, 1, 'Eternally Regressing Knight', 'Manga Demon (EN)', [
            'thumbnail_url' => '/api/v1/manga/1/thumbnail',
        ]);
        $this->givenResult($run, 2, 'Eternally Regressing Knight', 'Asura Scans (EN)');

        $data = $this->data();
        $groups = $data->groups($this->user, $run, false);
        $map = $data->libraryMap($this->user, $groups);

        $rows = $data->chosenSources(1, $groups, $map);

        $this->assertSame([1, 2], array_column($rows, 'manga'));
        $this->assertSame(['Manga Demon (EN)', 'Asura Scans (EN)'], array_column($rows, 'source'));
        $this->assertSame('Eternally Regressing Knight', $data->chosenTitle($rows));
        $this->assertSame([], $data->chosenSources(9999, $groups, $map));
        $this->assertNull($data->chosenTitle([]));
    }

    // AniList -------------------------------------------------------------

    public function test_the_anilist_reads_stay_off_until_the_reader_opts_in(): void
    {
        $run = $this->givenRun($this->user);
        $this->givenResult($run, 1, 'One Piece', 'Alpha Manga');
        $this->givenResult($run, 2, 'Berserk', 'Beta Manga');

        $data = $this->data();

        $this->assertFalse($data->anilistEnabled($this->user));
        $this->assertSame(['total' => 0, 'answered' => 0], $data->anilistProgress($this->user, $run, false));
        $this->assertNull($data->anilistMediaFor('One Piece', false));
    }

    public function test_the_anilist_reads_count_the_run_and_return_the_cached_media(): void
    {
        $run = $this->givenRun($this->user);
        $this->givenResult($run, 1, 'One Piece', 'Alpha Manga');
        $this->givenResult($run, 2, 'One Piece', 'Akaza Scans');
        $this->givenResult($run, 3, 'Berserk', 'Beta Manga');

        $theirs = $this->givenRun(User::factory()->create());
        $this->givenResult($theirs, 4, 'Not Mine', 'Other');

        AniListMedia::query()->create([
            'anilist_id' => 42,
            'normalized_title' => app(TitleNormalizer::class)->normalise('One Piece'),
            'title_romaji' => 'One Piece',
            'is_adult' => false,
            'looked_up_at' => now(),
        ]);

        app(AniListPreference::class)->set($this->user, true);

        $data = $this->data();

        $this->assertTrue($data->anilistEnabled($this->user));
        $this->assertSame(['One Piece', 'Berserk'], $data->resultTitles($this->user, $run));
        $this->assertSame(['total' => 2, 'answered' => 1], $data->anilistProgress($this->user, $run, true));
        $this->assertSame(42, $data->anilistMediaFor('One Piece', true)?->anilist_id);
        $this->assertNull($data->anilistMediaFor('Berserk', true), 'a title nobody has looked up has no media');
    }
}
