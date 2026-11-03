<?php

namespace App\Live\Library;

use Illuminate\Support\Collection;

/**
 * The library page's filter pipeline, kept out of the Livewire component.
 *
 * The page offers a shelf, a title search, a sort and paging. The series page's status,
 * source and language filters are not part of this component, so they are not invented
 * here. The pipeline returns the same collection in the same order as the inline
 * `matching()` it replaces, including the unchanged collection for the default sort.
 */
final class LibraryFilter
{
    /**
     * How many entries one page holds.
     *
     * 60 because this is about images rather than rows. A library page renders a
     * proxied cover per entry, and 60 is roughly two screens of scrolling. Beyond
     * that the page stops feeling instant without showing anything the reader
     * could use, so the rest is behind paging.
     */
    public const PER_PAGE = 60;

    /** The sort values the control offers. */
    public const SORTS = ['recent', 'added', 'title', 'unread'];

    /**
     * The shelf, search and sort applied.
     *
     * `recent` is last read then date added, which is the order `entries()` already
     * returns. `unread` puts the series with the most waiting at the top, which is
     * the order a reader wants on the days they came here to read.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @param  array<int, array<int, int>>  $filings
     * @return Collection<int, array<string, mixed>>
     */
    public function matching(
        Collection $entries,
        array $filings,
        ?int $shelf,
        string $search,
        string $sort,
    ): Collection {
        if ($shelf !== null) {
            $entries = $entries->filter(
                fn (array $entry): bool => in_array($shelf, $filings[$entry['manga_id']] ?? [], true)
            );
        }

        $needle = trim($search);

        if ($needle !== '') {
            $needle = mb_strtolower($needle);

            $entries = $entries->filter(
                fn (array $entry): bool => str_contains(mb_strtolower((string) $entry['title']), $needle)
            );
        }

        return match ($sort) {
            'title' => $entries->sortBy(fn (array $entry): string => mb_strtolower((string) $entry['title']))->values(),
            'added' => $entries->sortByDesc(fn (array $entry): int => (int) ($entry['added_at']?->getTimestamp() ?? 0))->values(),
            /*
             * `unread_chapters` is null for a series nobody has fetched, and null
             * sorts to -1 so those land at the bottom. Putting them at the top
             * would fill the top of the page with the series this page cannot say
             * anything about yet.
             */
            'unread' => $entries
                ->sortByDesc(fn (array $entry): int => (int) ($entry['unread_chapters'] ?? -1))
                ->values(),
            // 'recent' needs no work: entries already arrives in last read then
            // date added order and re-sorting only risks disagreeing with it.
            default => $entries,
        };
    }

    /**
     * One page of the matching entries.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    public function page(Collection $entries, int $page): Collection
    {
        return $entries->forPage($page, self::PER_PAGE)->values();
    }

    /**
     * Whether a changed property returns the reader to the first page.
     *
     * Landing on page 7 of a result set that now has two pages looks like an
     * empty library, so every filter change returns to the start.
     */
    public function resetsPage(string $property): bool
    {
        return in_array($property, ['search', 'sort', 'shelf'], true);
    }

    /**
     * Clamp a sort to one the control offers.
     *
     * A sort of "title; drop table" must never reach the query builder.
     */
    public function normaliseSort(string $sort): string
    {
        return in_array($sort, self::SORTS, true) ? $sort : 'recent';
    }
}
