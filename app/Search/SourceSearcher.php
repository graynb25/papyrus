<?php

namespace App\Search;

use App\Models\Source;
use App\Models\SourcePreference;
use App\Suwayomi\Gateway;
use App\Suwayomi\SourceFilterTranslator;
use App\Suwayomi\SuwayomiException;

/**
 * Runs one source's search and classifies the result.
 *
 * Suwayomi has no global search endpoint - MangaAPI.kt carries a literal TODO -
 * so "search everything" is a fan-out over sources, and this class is one arm
 * of it. It never throws: every failure mode becomes an outcome, because a run
 * is only as good as its ability to report which source let it down.
 */
class SourceSearcher
{
    public function __construct(
        private readonly Gateway $gateway,
        private readonly SourceFilterTranslator $translator,
    ) {}

    /**
     * @param  array<string, mixed>  $filterValues  named filter values
     */
    public function search(Source $source, string $query, array $filterValues = []): SourceSearchOutcome
    {
        $started = microtime(true);
        $timeout = (int) config('papyrus.search.per_source_timeout', 20);
        $limit = (int) config('papyrus.search.results_per_source', 15);

        try {
            $filters = $this->filtersFor($source);

            $changes = $filterValues === [] ? [] : $this->translator->toChanges($filters, $filterValues);

            $data = $this->gateway->execute(<<<'GQL'
                mutation Search($source: LongString!, $page: Int!, $query: String, $filters: [FilterChangeInput!]) {
                    fetchSourceManga(input: {source: $source, type: SEARCH, page: $page, query: $query, filters: $filters}) {
                        hasNextPage
                        mangas {
                            id title url thumbnailUrl status
                            genre

                            # Shown on the result card when the source offers one, and
                            # nothing more than that. Measured across 704 search rows
                            # from 76 sources, only 10.4% carry an author, because an
                            # extension populates a manga from whatever the listing gave
                            # it and author is usually only in the detail fetch.
                            #
                            # It rides free in a request the fan-out was making anyway,
                            # so asking costs nothing and the tenth that arrives is worth
                            # storing. It is not a filter. Nothing is checked against it,
                            # because checking against a field that is empty nine times
                            # in ten throws away nine tenths of a source's results.
                            #
                            # A hash comment and not a PHP one, because this is inside a
                            # GraphQL string. GraphQL's only comment is a hash, and a
                            # block comment here is a syntax error that fails the whole
                            # query rather than being ignored. Every search returned
                            # nothing with one, which read as 82 broken sources.
                            author
                            source { id name }
                        }
                    }
                }
                GQL, [
                'source' => (string) $source->suwayomi_source_id,
                'page' => 1,
                'query' => $query,
                'filters' => $changes,
            ], $timeout);

            $mangas = $data['fetchSourceManga']['mangas'] ?? [];

            return SourceSearchOutcome::ok(
                array_slice($mangas, 0, $limit),
                (int) ((microtime(true) - $started) * 1000),
            );
        } catch (SuwayomiException $e) {
            $duration = (int) ((microtime(true) - $started) * 1000);

            return $e->isTimeout()
                ? SourceSearchOutcome::timeout($duration)
                : SourceSearchOutcome::error($e->getMessage(), $duration);
        }
    }

    /**
     * One page of one source's search results.
     *
     * Separate from {@see search()} for the same reason {@see browsePage()} is separate from
     * `browse()`: `search()` returns a `SourceSearchOutcome`, which the health ledger reads for
     * its ok/empty/error split and which slices to `results_per_source` because that bounds a
     * fan-out. A search bar on the browse page wants neither: it wants the rows, and it wants the
     * scroll that is already there to keep working.
     *
     * Measured on Asura Scans, which is where the request came from: "regression" returns 8 rows
     * in 737ms with `hasNextPage` false, "solo level" 2 rows in 211ms, a nonsense term 0 rows in
     * 1,673ms, and the broadest term seen returns 20. So a single source's search is small and
     * usually one page, which means the scroll stops on its own rather than needing a cutoff, and
     * the 15-row slice would have been hiding a fifth of the broadest results.
     *
     * The nonsense term is worth keeping in mind: it cost 1,673ms against 211ms for a real one.
     * A search box that fires on every keystroke would spend that on typos, so the page waits to
     * be asked rather than reacting per character.
     *
     * @param  array<string, mixed>  $filterValues
     */
    public function searchPage(Source $source, string $query, int $page = 1, array $filterValues = []): BrowsePage
    {
        $term = trim($query);

        if ($term === '') {
            return BrowsePage::of([], false);
        }

        try {
            $filters = $this->filtersFor($source);
            $changes = $filterValues === [] ? [] : $this->translator->toChanges($filters, $filterValues);

            $data = $this->gateway->execute(<<<'GQL'
                mutation Find($source: LongString!, $page: Int!, $query: String!, $filters: [FilterChangeInput!]) {
                    fetchSourceManga(input: {source: $source, type: SEARCH, page: $page, query: $query, filters: $filters}) {
                        hasNextPage
                        mangas {
                            id title url thumbnailUrl
                            source { id name }
                        }
                    }
                }
                GQL, [
                'source' => (string) $source->suwayomi_source_id,
                'page' => $page,
                'query' => $term,
                'filters' => $changes,
            ], (int) config('papyrus.search.per_source_timeout', 20));

            return BrowsePage::of(
                $data['fetchSourceManga']['mangas'] ?? [],
                (bool) ($data['fetchSourceManga']['hasNextPage'] ?? false),
            );
        } catch (SuwayomiException $e) {
            return BrowsePage::failed(
                $e->isTimeout() ? 'Exceeded the per-source time budget.' : $e->getMessage(),
            );
        }
    }

    /**
     * One page of a source's POPULAR or LATEST listing.
     *
     * LATEST is checked against supportsLatest first: the server answers an unsupported request
     * with a bare java.lang.Exception and a full stack trace, which is both noisy and slower
     * than simply not asking.
     *
     * Rows are returned whole. This used to slice to `results_per_source`, which is a
     * fan-out bound rather than a page size, and measured against a browse page it discarded
     * 35 of MangaFire's 50 rows, 41 of Manga Demon's 56 and 25 of Atsumaru's 40.
     *
     * Measured cost, since page one blocks the render and later pages are the prefetch:
     * Asura Scans 709ms then 225ms, MangaFire 431ms then 420ms, Atsumaru 335ms then 169ms,
     * Weeb Central 867ms then 2,083ms, Manga Demon 543ms then 294ms. Nothing here justifies
     * a job, and the initial page is unavoidable either way because it is all there is to
     * show.
     *
     * @param  array<string, mixed>  $filterValues
     */
    public function browsePage(Source $source, string $type, int $page = 1, array $filterValues = []): BrowsePage
    {
        if ($type === 'LATEST' && ! $source->supports_latest) {
            return BrowsePage::failed('This source does not provide a latest-chapters listing.');
        }

        try {
            $filters = $this->filtersFor($source);
            $changes = $filterValues === [] ? [] : $this->translator->toChanges($filters, $filterValues);

            $data = $this->gateway->execute(<<<'GQL'
                mutation Browse($source: LongString!, $type: FetchSourceMangaType!, $page: Int!, $filters: [FilterChangeInput!]) {
                    fetchSourceManga(input: {source: $source, type: $type, page: $page, filters: $filters}) {
                        hasNextPage
                        mangas {
                            id title url thumbnailUrl
                            source { id name }
                        }
                    }
                }
                GQL, [
                'source' => (string) $source->suwayomi_source_id,
                'type' => $type,
                'page' => $page,
                'filters' => $changes,
            ], (int) config('papyrus.search.per_source_timeout', 20));

            return BrowsePage::of(
                $data['fetchSourceManga']['mangas'] ?? [],
                (bool) ($data['fetchSourceManga']['hasNextPage'] ?? false),
            );
        } catch (SuwayomiException $e) {
            return BrowsePage::failed(
                $e->isTimeout() ? 'Exceeded the per-source time budget.' : $e->getMessage(),
            );
        }
    }

    /**
     * Resolve a reader's filter terms against one source's own filter list.
     *
     * Reads the filter list through the same cached path `filtersFor()` already uses, so
     * resolving a name to a volatile position costs no request the search was not making.
     *
     * @param  array<string, string>  $values  our term name to value
     */
    public function plan(Source $source, array $values, FilterVocabulary $vocabulary): FilterPlan
    {
        if ($values === []) {
            return FilterPlan::resolve([], [], $vocabulary);
        }

        return FilterPlan::resolve($values, $this->filtersFor($source), $vocabulary);
    }

    /**
     * The source's filter list, read from the cache we keep for exactly this
     * reason - it is needed to resolve named filters to positions, and asking
     * the server every time would double the request count for a search.
     *
     * @return array<int, array<string, mixed>>
     */
    private function filtersFor(Source $source): array
    {
        $cached = SourcePreference::query()
            ->where('source_id', $source->suwayomi_source_id)
            ->value('preferences');

        if (is_array($cached)) {
            return $cached;
        }

        $members = <<<'GQL'
            __typename
            ... on HeaderFilter { name }
            ... on SeparatorFilter { name }
            ... on SelectFilter { name values selectDefault: default }
            ... on TextFilter { name textDefault: default }
            ... on CheckBoxFilter { name checkDefault: default }
            ... on TriStateFilter { name triDefault: default }
            ... on SortFilter { name values sortDefault: default { index ascending } }
        GQL;

        // GroupFilter children are addressed by their index within the group,
        // so the member selection is repeated verbatim inside it.
        $query = "query F(\$id: LongString!) { source(id: \$id) { filters { {$members} ... on GroupFilter { name filters { {$members} } } } } }";

        $data = $this->gateway->execute($query, [
            'id' => (string) $source->suwayomi_source_id,
        ]);

        $filters = $data['source']['filters'] ?? [];

        $this->translator->cacheFilters($source->suwayomi_source_id, $filters);

        return $filters;
    }
}
