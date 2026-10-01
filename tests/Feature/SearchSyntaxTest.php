<?php

namespace Tests\Feature;

use App\Jobs\SearchSourceJob;
use App\Models\SearchResult;
use App\Models\SearchRun;
use App\Models\SearchSourceResult;
use App\Models\Source;
use App\Models\User;
use App\Search\FilterPlan;
use App\Search\FilterValue;
use App\Search\FilterVocabulary;
use App\Search\HealthRecorder;
use App\Search\QueryParser;
use App\Search\SearchFanout;
use App\Search\SourceSearcher;
use App\Search\TitleNormalizer;
use App\Suwayomi\SourceFilterTranslator as Translator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * A best-effort `label:value` search syntax.
 *
 * ## What these hold, and the two measurements that shaped them
 *
 * Which filter names the sources offer, read across all **82 installed sources**:
 *
 * | term   | sources with the filter | share |
 * | ------ | ----------------------- | ----- |
 * | genre  | 77                      | 94%   |
 * | status | 74                      | 90%   |
 * | sort   | 69                      | 84%   |
 * | rating | 64                      | 78%   |
 * | author | 15                      | 18%   |
 * | year   | 15                      | 18%   |
 *
 * And what a search result actually carries, over **704 rows from 76 sources**:
 *
 * | field | present | usable  |
 * | ----- | ------- | ------- |
 * | title | 100%    | 100%    |
 * | url   | 100%    | 100%    |
 * | status| 100%    | **1%**  |
 * | genre | 10.9%   | 10.9%   |
 * | author| 10.4%   | 10.4%   |
 *
 * `status` is on every row and reads `UNKNOWN` on 697 of them. `year`, `contentRating`
 * and `viewerCount` are not on the type at all.
 *
 * ## The design those numbers force
 *
 * The first version pushed a term where the source had a matching filter and checked the
 * returned rows where it did not, so a thin term like `author:` would still work on the
 * 67 sources without an author filter. There is nothing to check against. A term checked
 * against `author` discards 90% of a source's rows and against `status` discards 99%, and
 * a run that discards nearly everything reads as broken rather than as narrow.
 *
 * So a term is either **pushed** or it is not offered, a source that cannot express a
 * filter is **skipped and counted**, and `author:` is gone. A term that works on 18% of
 * sources and does nothing on the rest is not a filter.
 *
 * ## The rule the parser exists to enforce
 *
 * An unrecognised label stays **text**. A reader searching for a title containing a colon
 * must get their search, not an empty page.
 */
class SearchSyntaxTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private User $user;

    private FilterVocabulary $vocabulary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->vocabulary = app(FilterVocabulary::class);

        $this->fakeSuwayomi();
    }

    private function parse(string $raw): array
    {
        return app(QueryParser::class)->parse($raw, $this->vocabulary);
    }

    /**
     * Two sources with deliberately different filter lists.
     *
     * Source 1 offers Status, Genre and Sort. Source 2 offers Genre and Sort only, which
     * is what makes the skip path reachable: a `status:` run asks one and leaves the
     * other out, and a `genre:` run asks both.
     *
     * 74 of 82 real sources have a status filter and 77 have a genre filter, so the shape
     * is the common case and not a corner.
     */
    private function fakeSuwayomi(): void
    {
        Http::fake(function (Request $request) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            if (str_contains($query, 'source(id:') || str_contains($query, 'source(id :')) {
                /*
                 * Read the body rather than `data()`. `data()` hands back an object, and
                 * reaching into it as an array is a runtime error rather than a null, so
                 * `data()['variables']['id'] ?? null` cannot be made safe with `??`.
                 */
                $body = json_decode((string) $request->body(), true);
                $variables = is_array($body) ? ($body['variables'] ?? []) : [];

                /*
                 * Options are declared, because a SelectFilter with an empty list cannot
                 * be asked anything: there is no index to send. That is measured
                 * behaviour, and a fake that left them out tested a source that no real
                 * source is.
                 */
                $status = [
                    '__typename' => 'SelectFilter',
                    'name' => 'Status',
                    'values' => ['ONGOING', 'COMPLETED'],
                ];

                $sort = [
                    '__typename' => 'SortFilter',
                    'name' => 'Sort By',
                    'values' => ['RELEVANCE', 'ALPHABETICAL'],
                ];

                $genre = ['__typename' => 'TextFilter', 'name' => 'Genre', 'textDefault' => null];

                // Source 2 has Genre and Sort but no Status, which is what makes the
                // skip path reachable: a `status:` run asks source 1 and leaves this
                // one out, and a `genre:` run asks both.
                $filters = (($variables['id'] ?? null) === '2')
                    ? [$genre, $sort]
                    : [$status, $genre, $sort];

                return Http::response(['data' => ['source' => ['filters' => $filters]]]);
            }

            if (str_contains($query, 'fetchSourceManga')) {
                return Http::response(['data' => ['fetchSourceManga' => [
                    'hasNextPage' => false,
                    'mangas' => [
                        [
                            'id' => '101', 'title' => 'Paper Lantern', 'url' => '/a',
                            'thumbnailUrl' => null, 'status' => 'ONGOING',
                            'genre' => ['Action', 'Drama'], 'author' => 'Isang',
                            'source' => ['id' => '1', 'name' => 'Full'],
                        ],
                        [
                            'id' => '102', 'title' => 'Paper Heart', 'url' => '/b',
                            'thumbnailUrl' => null, 'status' => 'COMPLETED',
                            'genre' => ['Romance'], 'author' => 'Someone Else',
                            'source' => ['id' => '1', 'name' => 'Full'],
                        ],
                    ],
                ]]]);
            }

            return Http::response(['data' => ['sources' => ['nodes' => [
                ['id' => '1', 'name' => 'Full', 'lang' => 'en', 'isNsfw' => false],
                ['id' => '2', 'name' => 'Sparse', 'lang' => 'en', 'isNsfw' => false],
            ]]]]);
        });
    }

    private function source(string $id, string $name): Source
    {
        /*
         * `Source::create()` rather than `createFor()`, because a source is not per
         * reader: it is an installed extension that everyone on this box shares, so it is
         * not an OwnedModel. Reader preference lives on `SourcePreference`.
         */
        return Source::create([
            'suwayomi_source_id' => $id,
            'name' => $name,
            'display_name' => $name,
            'extension_pkg_name' => 'eu.kanade.tachiyomi.extension.en.'.strtolower($name),
            'lang' => 'en',
            'content_warning' => Source::SAFE,
            'is_installed' => true,
            'supports_latest' => true,
        ]);
    }

    // Parsing -------------------------------------------------------------

    public function test_plain_text_stays_plain_text(): void
    {
        $result = $this->parse('paper lantern');

        $this->assertSame('paper lantern', $result['text']);
        $this->assertSame([], $result['filters']);
    }

    public function test_a_term_is_split_out_of_the_text(): void
    {
        $result = $this->parse('status:ongoing paper lantern');

        $this->assertSame('paper lantern', $result['text']);
        $this->assertSame(['status' => 'ongoing'], $result['filters']);
    }

    public function test_a_term_may_come_first(): void
    {
        $result = $this->parse('genre:action berserker');

        $this->assertSame('berserker', $result['text']);
        $this->assertSame(['genre' => 'action'], $result['filters']);
    }

    public function test_labels_are_case_insensitive(): void
    {
        $this->assertSame(['status' => 'ongoing'], $this->parse('STATUS:ongoing x')['filters']);
    }

    public function test_a_quoted_value_keeps_its_spaces(): void
    {
        $result = $this->parse('genre:"slice of life" lantern');

        $this->assertSame(['genre' => 'slice of life'], $result['filters']);
        $this->assertSame('lantern', $result['text']);
    }

    public function test_an_unrecognised_label_stays_text(): void
    {
        // The rule the parser exists for. A title containing a colon must still be found.
        $result = $this->parse('edition:deluxe arc');

        $this->assertSame([], $result['filters']);
        $this->assertStringContainsString('edition:deluxe', $result['text']);
        $this->assertSame(['edition:deluxe'], $result['ignored']);
    }

    public function test_an_empty_value_is_reported_and_dropped(): void
    {
        $result = $this->parse('status: lantern');

        $this->assertSame(['status'], $result['empty']);
        $this->assertSame([], $result['filters']);
        $this->assertSame('lantern', $result['text'], 'The rest still searches.');
    }

    public function test_a_repeated_label_keeps_the_last_value(): void
    {
        $result = $this->parse('status:ongoing status:completed x');

        $this->assertSame(['status' => 'completed'], $result['filters']);
    }

    public function test_several_terms_are_all_kept(): void
    {
        $result = $this->parse('status:ongoing genre:action lantern');

        $this->assertSame(['status' => 'ongoing', 'genre' => 'action'], $result['filters']);
        $this->assertSame('lantern', $result['text']);
    }

    public function test_a_query_of_only_terms_has_no_text(): void
    {
        $result = $this->parse('status:ongoing genre:action');

        $this->assertSame('', $result['text']);
        $this->assertCount(2, $result['filters']);
    }

    public function test_a_url_is_not_eaten_as_a_term(): void
    {
        // The label branch is tried first, so `http` reads as a label with a value. It is
        // not a term, so it has to come back out as text or a reader searching for a URL
        // gets an empty page.
        $result = $this->parse('http://example.com/x');

        $this->assertSame([], $result['filters']);
        $this->assertStringContainsString('example.com', $result['text']);
    }

    // The vocabulary -------------------------------------------------------

    public function test_only_three_terms_ship(): void
    {
        /*
         * Coverage is what chose these. Measured across 82 sources: genre 77, status 74,
         * sort 69, rating 64, author 15, year 15.
         */
        $this->assertSame(['genre', 'status', 'sort'], $this->vocabulary->terms());
    }

    public function test_author_is_not_a_term(): void
    {
        /*
         * The measurement that removed it. 15 of 82 sources have an author filter, so it
         * would work on a fifth of them. And there is no second route: over 704 search
         * rows from 76 sources, 10.4% carry an author, so checking afterwards would
         * discard nine tenths of a source's results on the other 67.
         */
        $this->assertFalse($this->vocabulary->has('author'));
    }

    public function test_rating_and_year_are_not_terms(): void
    {
        // rating reaches 64 sources but has no field to check against afterwards, and
        // year is 15 sources and is not even on the Manga type.
        $this->assertFalse($this->vocabulary->has('rating'));
        $this->assertFalse($this->vocabulary->has('year'));
    }

    public function test_sort_is_an_order_and_the_others_are_filters(): void
    {
        // The difference decides whether a source is dropped or merely left unsorted.
        $this->assertTrue($this->vocabulary->isFilter('genre'));
        $this->assertTrue($this->vocabulary->isFilter('status'));
        $this->assertFalse($this->vocabulary->isFilter('sort'));
    }

    public function test_a_closed_term_refuses_a_value_it_does_not_have(): void
    {
        // `status:ongo` would otherwise reach 74 sources and quietly match nothing.
        $this->assertTrue($this->vocabulary->accepts('status', 'ongoing'));
        $this->assertFalse($this->vocabulary->accepts('status', 'ongo'));
    }

    public function test_a_free_text_term_accepts_anything(): void
    {
        $this->assertTrue($this->vocabulary->accepts('genre', 'slice of life'));
        $this->assertFalse($this->vocabulary->accepts('genre', '  '));
    }

    public function test_a_sources_filter_name_is_matched_by_pattern(): void
    {
        // Measured 93 distinct filter names across 82 sources, and three of them mean
        // genre, so an exact match would find one and miss the rest.
        $filters = [
            ['name' => 'Genre and theme match mode'],
            ['name' => 'Genre condition'],
            ['name' => 'Status'],
            ['name' => 'Sort By'],
        ];

        $this->assertSame('Genre and theme match mode', $this->vocabulary->sourceFilterName('genre', $filters));
        $this->assertSame('Status', $this->vocabulary->sourceFilterName('status', $filters));
        $this->assertSame('Sort By', $this->vocabulary->sourceFilterName('sort', $filters));
    }

    public function test_a_source_with_no_matching_filter_reports_none(): void
    {
        $this->assertNull($this->vocabulary->sourceFilterName('status', [['name' => 'Genre']]));
    }

    // The plan -------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $filters
     */
    private function plan(array $values, array $filters): FilterPlan
    {
        return FilterPlan::resolve($values, $filters, $this->vocabulary);
    }

    public function test_a_term_the_source_has_is_keyed_by_the_sources_own_name(): void
    {
        // Keyed by the source's name because SourceFilterTranslator resolves a name to a
        // volatile position and refuses one it has not seen.
        $plan = $this->plan(['status' => 'ongoing'], [['name' => 'Status']]);

        $this->assertSame(['Status' => 'ongoing'], $plan->toArray());
        $this->assertTrue($plan->canRun());
    }

    public function test_a_filter_the_source_lacks_stops_the_source_running(): void
    {
        $plan = $this->plan(['status' => 'ongoing'], [['name' => 'Genre']]);

        $this->assertFalse($plan->canRun());
        $this->assertSame(['status'], $plan->unfilterable);
    }

    public function test_an_order_the_source_lacks_does_not_stop_it_running(): void
    {
        // The asymmetry that matters. A source that cannot re-order still has perfectly
        // good results, and dropping them to enforce an ordering would be the worse trade.
        $plan = $this->plan(['sort' => 'latest'], [['name' => 'Genre']]);

        $this->assertTrue($plan->canRun());
        $this->assertSame([], $plan->toArray());
        $this->assertSame(['sort'], $plan->unsortable);
    }

    public function test_a_skipped_source_says_which_term_stopped_it(): void
    {
        $plan = $this->plan(
            ['status' => 'ongoing', 'sort' => 'latest'],
            [['name' => 'Genre']],
        );

        $this->assertStringContainsString('status', (string) $plan->reason());
        $this->assertStringNotContainsString('sort', (string) $plan->reason());
    }

    public function test_a_runnable_source_has_no_reason(): void
    {
        $this->assertNull($this->plan(['genre' => 'action'], [['name' => 'Genre']])->reason());
    }

    // The value, not just the name -----------------------------------------

    public function test_a_select_filter_wants_an_index_not_our_string(): void
    {
        /*
         * The bug this exists for. A value came through as a bare string, became
         * `textState`, and every source whose Status filter is a SelectFilter rejected
         * it: `Expected select state change at position 16` on MangaFire, and the same
         * on four others. All five were ours.
         *
         * `values` is `[String!]!`, a plain list of strings. Reading `$option['value']`
         * off a string yields nothing, so an earlier version resolved every select to
         * null and every sort to nothing, with no error anywhere to point at it.
         */
        $filter = [
            '__typename' => 'SelectFilter',
            'name' => 'Status',
            'values' => ['ONGOING', 'COMPLETED'],
        ];

        $this->assertSame(1, FilterValue::resolve('completed', $filter));
        $this->assertSame(0, FilterValue::resolve('ONGOING', $filter));
    }

    public function test_a_value_the_source_has_no_option_for_is_not_expressible(): void
    {
        // A source declaring ONGOING, COMPLETED and PAUSED cannot be asked for `hiatus`,
        // and picking PAUSED would apply a filter the reader did not ask for.
        $filter = [
            '__typename' => 'SelectFilter',
            'name' => 'Status',
            'values' => ['ONGOING', 'COMPLETED', 'PAUSED'],
        ];

        $this->assertNull(FilterValue::resolve('hiatus', $filter));
    }

    public function test_a_text_filter_takes_our_value_as_written(): void
    {
        // `genre` lands here on the sources that model it as free text, and needs nothing
        // done to it.
        $filter = ['__typename' => 'TextFilter', 'name' => 'Genre'];

        $this->assertSame('slice of life', FilterValue::resolve('slice of life', $filter));
    }

    public function test_a_sort_filter_wants_an_index_and_a_direction(): void
    {
        $filter = [
            '__typename' => 'SortFilter',
            'name' => 'Sort',
            'values' => ['RELEVANCE', 'ALPHABETICAL'],
        ];

        /*
         * `alphabetically` against a source that says `ALPHABETICAL`. That one difference
         * in spelling is the only systematic mismatch across the 82 sources, so it gets a
         * rule rather than a special case.
         */
        $this->assertSame(['index' => 1, 'ascending' => false], FilterValue::resolve('alphabetically', $filter));
    }

    public function test_a_value_a_source_does_not_list_is_not_a_sort_either(): void
    {
        $filter = [
            '__typename' => 'SortFilter',
            'name' => 'Sort',
            'values' => ['RELEVANCE'],
        ];

        $this->assertNull(FilterValue::resolve('alphabetically', $filter));
    }

    public function test_option_names_are_compared_on_letters_alone(): void
    {
        $filter = [
            '__typename' => 'SelectFilter',
            'name' => 'Genre',
            'values' => ['Slice of Life', 'Action'],
        ];

        $this->assertSame(0, FilterValue::resolve('slice-of-life', $filter));
    }

    public function test_a_source_with_no_options_at_all_cannot_be_asked(): void
    {
        // An empty list means there is nothing to index into, so the source cannot be
        // asked. That is not the same as a source with no such filter, and both end in
        // the same place: skipped and counted.
        $filter = ['__typename' => 'SelectFilter', 'name' => 'Status', 'values' => []];

        $this->assertNull(FilterValue::resolve('completed', $filter));
    }

    // Groups --------------------------------------------------------------

    public function test_a_group_child_is_matched_by_its_name(): void
    {
        /*
         * MangaDex is the case that made this necessary, at 61 of the 82 installed
         * sources. It models Status as a group with one checkbox per value, so our value
         * selects a *label* rather than an option to look up in a list.
         */
        $group = [
            '__typename' => 'GroupFilter',
            'name' => 'Status',
            'filters' => [
                ['__typename' => 'CheckBoxFilter', 'name' => 'Ongoing'],
                ['__typename' => 'CheckBoxFilter', 'name' => 'Completed'],
                ['__typename' => 'CheckBoxFilter', 'name' => 'Hiatus'],
                ['__typename' => 'CheckBoxFilter', 'name' => 'Cancelled'],
            ],
        ];

        $child = FilterValue::childFor('completed', $group['filters']);

        $this->assertNotNull($child);
        $this->assertSame('Completed', $child['name']);

        // A checkbox child exists to be ticked, and matching its name is the whole test.
        $this->assertTrue(FilterValue::stateForChild($child));
    }

    public function test_a_group_child_that_is_a_toggle_wants_included(): void
    {
        // MangaDex models every genre as a three-way: include, exclude, ignore. A reader
        // typing `genre:action` wants it included.
        $child = ['__typename' => 'TriStateFilter', 'name' => 'Action'];

        $this->assertSame('INCLUDE', FilterValue::stateForChild($child));
    }

    public function test_a_group_with_no_such_child_is_not_expressible(): void
    {
        $group = ['filters' => [
            ['__typename' => 'CheckBoxFilter', 'name' => 'Ongoing'],
            ['__typename' => 'CheckBoxFilter', 'name' => 'Completed'],
        ]];

        $this->assertNull(FilterValue::childFor('hiatus', $group['filters']));
    }

    public function test_a_group_is_keyed_by_its_child_not_by_itself(): void
    {
        /*
         * The whole chain. Keying the group produced `Expected group change at position
         * 4` on every MangaDex variant, because the change belongs at the child's inner
         * position and `SourceFilterTranslator` only knows to nest it for a child's name.
         */
        $filters = [[
            '__typename' => 'GroupFilter',
            'name' => 'Status',
            'filters' => [
                ['__typename' => 'CheckBoxFilter', 'name' => 'Ongoing'],
                ['__typename' => 'CheckBoxFilter', 'name' => 'Completed'],
            ],
        ]];

        $plan = $this->plan(['status' => 'completed'], $filters);

        $this->assertTrue($plan->canRun());
        $this->assertSame(['Completed' => true], $plan->toArray(), 'The child, ticked.');
    }

    public function test_a_group_without_our_value_skips_the_source(): void
    {
        $filters = [[
            '__typename' => 'GroupFilter',
            'name' => 'Status',
            'filters' => [
                ['__typename' => 'CheckBoxFilter', 'name' => 'Ongoing'],
                ['__typename' => 'CheckBoxFilter', 'name' => 'Completed'],
            ],
        ]];

        $plan = $this->plan(['status' => 'hiatus'], $filters);

        $this->assertFalse($plan->canRun());
        $this->assertSame([], $plan->toArray());
    }

    public function test_the_nested_change_the_translator_builds(): void
    {
        // The shape the server actually receives, which is the only thing that settles it.
        $translator = app(Translator::class);

        $changes = $translator->toChanges(
            [[
                '__typename' => 'GroupFilter',
                'name' => 'Status',
                'filters' => [
                    ['__typename' => 'CheckBoxFilter', 'name' => 'Ongoing'],
                    ['__typename' => 'CheckBoxFilter', 'name' => 'Completed'],
                ],
            ]],
            ['Completed' => true],
        );

        $this->assertSame([
            ['position' => 0, 'groupChange' => ['position' => 1, 'checkBoxState' => true]],
        ], $changes);
    }

    public function test_a_flat_select_change_is_addressed_directly(): void
    {
        $translator = app(Translator::class);

        $changes = $translator->toChanges(
            [
                ['__typename' => 'TextFilter', 'name' => 'Has chapters'],
                ['__typename' => 'SelectFilter', 'name' => 'Status', 'values' => ['ONGOING', 'COMPLETED']],
            ],
            ['Status' => 1],
        );

        $this->assertSame([['position' => 1, 'selectState' => 1]], $changes);
    }

    // The run --------------------------------------------------------------

    public function test_a_run_records_what_it_understood(): void
    {
        $this->source('1', 'Full');

        $run = app(SearchFanout::class)->start($this->user, 'status:ongoing lantern');

        $this->assertSame('status:ongoing lantern', $run->query, 'What the reader typed is kept.');
        $this->assertSame('lantern', $run->text_query, 'And what was actually sent upstream.');
        $this->assertSame(['status' => 'ongoing'], $run->filters);
    }

    public function test_the_term_is_not_sent_upstream_as_text(): void
    {
        /*
         * The claim is an absence, so the assertion has to be one too. Sending
         * `status:ongoing` to a source as text would search for that literal string,
         * which is the failure the whole split exists to prevent.
         */
        $this->source('1', 'Full');

        app(SearchFanout::class)->start($this->user, 'status:ongoing lantern');

        Http::assertNotSent(fn (Request $request): bool => str_contains((string) $request->body(), 'status:ongoing'));
    }

    public function test_a_plain_query_records_no_filters(): void
    {
        $this->source('1', 'Full');

        $run = app(SearchFanout::class)->start($this->user, 'lantern');

        $this->assertSame('lantern', $run->text_query);
        $this->assertNull($run->filters);
    }

    public function test_an_unrecognised_label_becomes_text_in_the_run(): void
    {
        $this->source('1', 'Full');

        $run = app(SearchFanout::class)->start($this->user, 'edition:deluxe arc');

        $this->assertSame('edition:deluxe arc', $run->text_query);
        $this->assertNull($run->filters);
    }

    // The job --------------------------------------------------------------

    /**
     * Run one search job for real and hand back what it stored.
     *
     * @param  array<string, string>  $filters  our terms, as a reader would type them
     * @return array<int, string> the titles that survived
     */
    private function runJob(array $filters, string $id = '1', string $name = 'Full'): array
    {
        $source = $this->source($id, $name);

        $run = SearchRun::createFor($this->user, [
            'query' => 'lantern',
            'status' => SearchRun::RUNNING,
            'sources_total' => 1,
            'sources_done' => 0,
            'result_count' => 0,
        ]);

        (new SearchSourceJob(
            runId: $run->id,
            userId: $this->user->id,
            sourceId: $source->id,
            query: 'lantern',
            filterValues: $filters,
        ))->handle(
            app(SourceSearcher::class),
            app(TitleNormalizer::class),
            app(HealthRecorder::class),
            $this->vocabulary,
        );

        return SearchResult::query()
            ->where('search_run_id', $run->id)
            ->pluck('title')
            ->all();
    }

    public function test_an_unfiltered_job_stores_everything(): void
    {
        $this->assertCount(2, $this->runJob([]));
    }

    public function test_a_source_that_can_filter_is_asked_and_keeps_its_results(): void
    {
        // Source 1 has a Status filter, so it is asked and whatever it returns is kept.
        // The filter is the source's job, not ours: measured, a returned row's status is
        // UNKNOWN on 99% of rows, so there is nothing here to check against.
        $this->assertCount(2, $this->runJob(['status' => 'completed']));
    }

    public function test_a_source_that_cannot_filter_is_skipped_and_counted(): void
    {
        // Source 2 has no Status filter, so it is left out rather than asked for
        // unfiltered rows the reader would then have to distrust.
        $source = $this->source('2', 'Sparse');

        $run = SearchRun::createFor($this->user, [
            'query' => 'lantern',
            'status' => SearchRun::RUNNING,
            'sources_total' => 1,
        ]);

        (new SearchSourceJob(
            runId: $run->id,
            userId: $this->user->id,
            sourceId: $source->id,
            query: 'lantern',
            filterValues: ['status' => 'completed'],
        ))->handle(
            app(SourceSearcher::class),
            app(TitleNormalizer::class),
            app(HealthRecorder::class),
            $this->vocabulary,
        );

        $row = SearchSourceResult::query()->where('search_run_id', $run->id)->firstOrFail();

        $this->assertSame(SearchSourceResult::SKIPPED, $row->status);
        $this->assertSame(0, (int) $row->result_count);
        $this->assertStringContainsString('status', (string) $row->error);
        $this->assertSame(0, SearchResult::query()->where('search_run_id', $run->id)->count());
    }

    public function test_a_skip_still_advances_the_run(): void
    {
        // A skip that forgets to count leaves the run at "0 of 1 sources" for ever, which
        // reads as a hang rather than as a source being left out.
        $source = $this->source('2', 'Sparse');

        $run = SearchRun::createFor($this->user, [
            'query' => 'lantern',
            'status' => SearchRun::RUNNING,
            'sources_total' => 1,
        ]);

        (new SearchSourceJob(
            runId: $run->id,
            userId: $this->user->id,
            sourceId: $source->id,
            query: 'lantern',
            filterValues: ['status' => 'completed'],
        ))->handle(
            app(SourceSearcher::class),
            app(TitleNormalizer::class),
            app(HealthRecorder::class),
            $this->vocabulary,
        );

        $run->refresh();

        $this->assertSame(1, (int) $run->sources_done);
        $this->assertSame(1, (int) $run->sources_skipped);
        $this->assertTrue($run->isFinished(), 'A run of one skipped source is finished.');
    }

    public function test_a_skipped_source_is_not_a_failure(): void
    {
        // It is healthy and answered nothing; we chose not to ask. Counting it as failed
        // would turn the health dashboard amber for a filter the reader asked for.
        $source = $this->source('2', 'Sparse');

        $run = SearchRun::createFor($this->user, [
            'query' => 'lantern',
            'status' => SearchRun::RUNNING,
            'sources_total' => 1,
        ]);

        (new SearchSourceJob(
            runId: $run->id,
            userId: $this->user->id,
            sourceId: $source->id,
            query: 'lantern',
            filterValues: ['status' => 'completed'],
        ))->handle(
            app(SourceSearcher::class),
            app(TitleNormalizer::class),
            app(HealthRecorder::class),
            $this->vocabulary,
        );

        $this->assertSame(0, (int) $run->refresh()->sources_failed);
    }

    public function test_an_order_a_source_lacks_does_not_skip_it(): void
    {
        // Source 2 has no Sort By either, and it still runs. This is the asymmetry that
        // keeps an order from costing a source its results.
        $this->assertCount(2, $this->runJob(['sort' => 'latest'], '2', 'Sparse'));
    }

    public function test_a_genre_term_asks_a_source_that_has_no_status_filter(): void
    {
        // Source 2 has Genre, so a genre run includes it and only status would exclude it.
        $this->assertCount(2, $this->runJob(['genre' => 'action'], '2', 'Sparse'));
    }

    public function test_the_author_is_stored_when_the_source_offers_one(): void
    {
        /*
         * Present on about a tenth of search rows, over 704 rows measured, and shown on
         * the card when it arrives. Not a filter: nothing is checked against it.
         */
        $this->runJob([]);

        $this->assertSame('Isang', SearchResult::query()->where('title', 'Paper Lantern')->first()?->author);
    }

    // The page -------------------------------------------------------------

    public function test_the_page_offers_the_syntax(): void
    {
        Livewire::test('pages::search')
            ->assertOk()
            ->assertSee('Add label:value to narrow it.');
    }

    public function test_the_page_names_every_term(): void
    {
        $html = Livewire::test('pages::search')->assertOk()->html();

        foreach (['Genre', 'Status', 'Sort'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    public function test_the_page_does_not_offer_author(): void
    {
        // The hint lists the terms, so a cut term has to be gone from it too. Offering
        // Author and then doing nothing with it is the failure this whole design avoids.
        $html = Livewire::test('pages::search')->set('query', 'author:oda')->assertOk()->html();

        $this->assertStringNotContainsString('Author: oda', $html);
        $this->assertStringContainsString('author:oda', $html, 'It searched as text instead.');
    }

    public function test_the_page_says_sources_are_left_out(): void
    {
        $html = Livewire::test('pages::search')->assertOk()->html();

        $this->assertStringContainsString('left out', $html);
    }

    public function test_the_page_shows_what_a_typed_query_became(): void
    {
        Livewire::test('pages::search')
            ->set('query', 'status:ongoing lantern')
            ->assertSee('lantern')
            ->assertSee('Status: ongoing');
    }

    public function test_the_page_says_an_unknown_label_was_searched_as_text(): void
    {
        // The safety of leaving unknown labels alone depends on the reader being able to
        // see that it happened.
        Livewire::test('pages::search')
            ->set('query', 'edition:deluxe arc')
            ->assertSee('edition:deluxe')
            ->assertSee('searched as text');
    }

    public function test_the_page_shows_nothing_extra_for_a_plain_query(): void
    {
        $html = Livewire::test('pages::search')->set('query', 'lantern')->assertOk()->html();

        $this->assertStringNotContainsString('searched as text', $html);
    }
}
