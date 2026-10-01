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
     * Browse a source's POPULAR or LATEST listing.
     *
     * LATEST is checked against supportsLatest first: the server answers an
     * unsupported request with a bare java.lang.Exception and a full stack
     * trace, which is both noisy and slower than simply not asking.
     *
     * @param  array<string, mixed>  $filterValues
     */
    public function browse(Source $source, string $type, int $page = 1, array $filterValues = []): SourceSearchOutcome
    {
        $started = microtime(true);
        $timeout = (int) config('papyrus.search.per_source_timeout', 20);
        $limit = (int) config('papyrus.search.results_per_source', 15);

        if ($type === 'LATEST' && ! $source->supports_latest) {
            return SourceSearchOutcome::error(
                'This source does not provide a latest-chapters listing.',
                0,
            );
        }

        try {
            $filters = $this->filtersFor($source);
            $changes = $filterValues === [] ? [] : $this->translator->toChanges($filters, $filterValues);

            $data = $this->gateway->execute(<<<'GQL'
                mutation Browse($source: LongString!, $type: FetchSourceMangaType!, $page: Int!, $filters: [FilterChangeInput!]) {
                    fetchSourceManga(input: {source: $source, type: $type, page: $page, filters: $filters}) {
                        hasNextPage
                        mangas {
                            id title url thumbnailUrl status
                            genre
                            source { id name }
                        }
                    }
                }
                GQL, [
                'source' => (string) $source->suwayomi_source_id,
                'type' => $type,
                'page' => $page,
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
