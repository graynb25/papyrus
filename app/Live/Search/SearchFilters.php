<?php

namespace App\Live\Search;

/**
 * The one fanout options array the search page builds.
 *
 * The run that gets scheduled, the cost line above the button and the not-asked list
 * all ask this class for the same array, so one change to the filters reaches every
 * read at once. The ratings arrive already narrowed by the adult gate, and an empty
 * language list means every language rather than none.
 */
final class SearchFilters
{
    /**
     * @param  array<int, string>  $langs
     * @param  array<int, string>  $content
     * @return array<string, mixed>
     */
    public function options(array $langs, array $content, int $limit): array
    {
        return [
            'langs' => $langs === [] ? null : $langs,
            'content' => $content,
            'limit' => $limit,
        ];
    }
}
