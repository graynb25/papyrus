<?php

namespace App\Search;

/**
 * The terms a reader can type, and what each one can honestly promise.
 *
 * ## Every number here was measured, and two of them killed a term
 *
 * Read across all **82 installed sources**, every filter name each offers:
 *
 * | term    | sources with the filter | share |
 * | ------- | ----------------------- | ----- |
 * | genre   | 77                      | 94%   |
 * | status  | 74                      | 90%   |
 * | sort    | 69                      | 84%   |
 * | rating  | 64                      | 78%   |
 * | author  | 15                      | 18%   |
 * | year    | 15                      | 18%   |
 *
 * And then measured against what a **search result actually carries**, 704 rows from 76
 * sources:
 *
 * | field       | present | usable |
 * | ----------- | ------- | ------ |
 * | title       | 100%    | 100%   |
 * | url         | 100%    | 100%   |
 * | thumbnail   | 100%    | 100%   |
 * | status      | 100%    | **1%** |
 * | genre       | 10.9%   | 10.9%  |
 * | author      | 10.4%   | 10.4%  |
 * | description | 11.1%   | 11.1%  |
 *
 * `status` is present on every row and useless on 697 of them, because the value is
 * `UNKNOWN`. `year`, `contentRating` and `viewerCount` are not on the type at all.
 *
 * ## Which is why there is no second route
 *
 * The first design pushed a term where the source had a matching filter and checked the
 * returned rows against a field where it did not, so that a thin term like `author:`
 * would still work on the 67 sources that lack an author filter.
 *
 * There is nothing to check against. A term checked against `author` would throw away
 * 90% of a source's rows, and checked against `status` would throw away 99%, and a run
 * that discards almost everything reads as broken rather than as narrow. So a term is
 * either pushed or it is not offered, and `author:` is not offered: 18% is not a filter,
 * it is a coin flip that usually comes up empty.
 *
 * ## The gap is reported rather than papered over
 *
 * Where a source cannot express a filter, that source is **skipped** for the run and
 * counted. It is not asked for unfiltered results, because then the reader gets rows that
 * do not match what they asked for and cannot tell. It is not dropped silently either.
 * The page says how many sources each term cost.
 *
 * `sort` is the exception, and deliberately: it is an order rather than a membership
 * filter, so a source that cannot sort still returns everything it has, in its own order.
 * Losing a source's results because it will not re-order them is a much worse trade than
 * returning them slightly out of order.
 */
class FilterVocabulary
{
    /**
     * A term that narrows which rows come back. A source without it is skipped.
     */
    public const FILTER = 'filter';

    /**
     * A term that only changes the order rows come back in. A source without it is left
     * to its own ordering rather than skipped.
     */
    public const ORDER = 'order';

    /**
     * Our terms, the pattern that recognises a source's own name for it, and its kind.
     *
     * `pattern` matches a source's filter name rather than an exact string because the
     * names are not exact anywhere: measured 93 distinct filter names across 82 sources,
     * with three spellings of author alone.
     *
     * @var array<string, array{label: string, pattern: string, kind: string, options: array<int, string>}>
     */
    private const TERMS = [
        'genre' => [
            'label' => 'Genre',
            // Measured names: `Genre`, `Genres`, `Genre and theme match mode`,
            // `Genre condition`, and `Genre exclusion is not available for all sources`,
            // which are three different controls sharing one word.
            'pattern' => '/^genre/i',
            'kind' => self::FILTER,
            'options' => [],
        ],

        'status' => [
            'label' => 'Status',
            // Measured `Status`, and a few sources that qualify it.
            'pattern' => '/^status$/i',
            'kind' => self::FILTER,
            'options' => ['ongoing', 'completed', 'cancelled', 'hiatus', 'unknown'],
        ],

        'sort' => [
            'label' => 'Sort',
            // Measured: `Sort By`, `Sort Order`. Measured across 82 sources and none of
            // them puts a sort on a returned row, which is why it is an order and not a
            // filter: there is nothing afterwards to check.
            'pattern' => '/^sort/i',
            'kind' => self::ORDER,
            'options' => ['relevance', 'popularity', 'latest', 'alphabetically', 'views'],
        ],
    ];

    /**
     * The terms that ship, in the order they are offered.
     *
     * @return array<int, string>
     */
    public function terms(): array
    {
        return array_keys(self::TERMS);
    }

    public function has(string $label): bool
    {
        return isset(self::TERMS[strtolower($label)]);
    }

    /**
     * The human name for a term.
     */
    public function label(string $term): string
    {
        return self::TERMS[strtolower($term)]['label'] ?? $term;
    }

    /**
     * Whether a term is a filter or an order, or null when it is not a term at all.
     */
    public function kind(string $term): ?string
    {
        return self::TERMS[strtolower($term)]['kind'] ?? null;
    }

    public function isFilter(string $term): bool
    {
        return $this->kind($term) === self::FILTER;
    }

    /**
     * The values a term accepts, when it has a fixed set.
     *
     * @return array<int, string>
     */
    public function options(string $term): array
    {
        return self::TERMS[strtolower($term)]['options'] ?? [];
    }

    /**
     * Whether a value is one this term accepts.
     *
     * An empty option list means the term is free text, so anything non-empty is accepted.
     * A non-empty list means a closed set, and a value outside it is refused rather than
     * forwarded: `status:ongo` would otherwise reach 74 sources and quietly match nothing.
     */
    public function accepts(string $term, string $value): bool
    {
        $options = $this->options($term);

        return $options === []
            ? trim($value) !== ''
            : in_array(strtolower(trim($value)), $options, true);
    }

    /**
     * The source's own filter entry for a term, from its filter list.
     *
     * The whole entry rather than just its name, because a name is not enough to send a
     * value with: a `SelectFilter` needs our value mapped onto one of its declared
     * options, and a `SortFilter` needs an index into its own list. Both live on the
     * entry.
     *
     * @param  array<int, array<string, mixed>>  $filters  as SourceFilterTranslator reads them
     * @return array<string, mixed>|null
     */
    public function sourceFilter(string $term, array $filters): ?array
    {
        $pattern = self::TERMS[strtolower($term)]['pattern'] ?? null;

        if ($pattern === null) {
            return null;
        }

        foreach ($filters as $filter) {
            $name = (string) ($filter['name'] ?? '');

            if ($name !== '' && preg_match($pattern, $name) === 1) {
                return $filter;
            }
        }

        return null;
    }

    /**
     * The source-side filter name for a term, from a source's own filter list.
     *
     * @param  array<int, array<string, mixed>>  $filters
     */
    public function sourceFilterName(string $term, array $filters): ?string
    {
        $filter = $this->sourceFilter($term, $filters);

        return $filter === null ? null : (string) ($filter['name'] ?? '');
    }

    /**
     * A sentence describing the syntax, for the hint under the search box.
     *
     * Says what a term is not as well as what it is, because the one thing a reader
     * cannot work out for themselves is which of their sources a term will apply to.
     */
    public function termsHint(): string
    {
        return 'label:value, where label is one of: '.implode(', ', array_map(
            fn (string $t): string => $this->label($t),
            $this->terms(),
        )).'. Sources that cannot honour a term are left out and counted.';
    }
}
