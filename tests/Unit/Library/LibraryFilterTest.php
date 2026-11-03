<?php

namespace Tests\Unit\Library;

use App\Live\Library\LibraryFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The library page's filter pipeline, exercised without mounting the page.
 *
 * The page offers a shelf, a title search, a sort and paging. The fixture covers the
 * shapes `Library::entries()` returns for each filter, the combinations the page test
 * already uses, and the page reset rule the component keeps.
 */
class LibraryFilterTest extends TestCase
{
    private LibraryFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filter = new LibraryFilter;
    }

    public function test_the_default_sort_keeps_the_entries_own_order(): void
    {
        $entries = $this->entries();

        $this->assertSame(
            $entries->all(),
            $this->filter->matching($entries, [], null, '', 'recent')->all(),
            'recent needs no work: entries already arrives in last read then date added order',
        );
    }

    public function test_a_shelf_filter_keeps_only_the_series_filed_in_it(): void
    {
        $entries = $this->entries();
        $filings = [1 => [10], 2 => [10, 20], 3 => [20], 4 => []];

        $this->assertSame(
            [1, 2],
            $this->filter->matching($entries, $filings, 10, '', 'recent')->pluck('manga_id')->all(),
        );

        $this->assertSame(
            [2, 3],
            $this->filter->matching($entries, $filings, 20, '', 'recent')->pluck('manga_id')->all(),
        );

        $this->assertSame(
            [],
            $this->filter->matching($entries, $filings, 30, '', 'recent')->pluck('manga_id')->all(),
            'a shelf nobody is filed in is empty rather than unfiltered',
        );
    }

    public function test_a_search_is_case_insensitive_and_trimmed(): void
    {
        $entries = $this->entries();

        $this->assertSame(
            [1],
            $this->filter->matching($entries, [], null, '  ALPHA  ', 'recent')->pluck('manga_id')->all(),
        );

        $this->assertSame(
            [2, 3],
            $this->filter->matching($entries, [], null, 'two', 'recent')->pluck('manga_id')->all(),
            'the search is a substring of the title, not a prefix',
        );
    }

    public function test_a_search_with_no_matches_is_empty(): void
    {
        $this->assertTrue(
            $this->filter->matching($this->entries(), [], null, 'zzzz', 'recent')->isEmpty(),
        );
    }

    public function test_a_blank_search_is_ignored(): void
    {
        $entries = $this->entries();

        $this->assertSame(
            $entries->pluck('manga_id')->all(),
            $this->filter->matching($entries, [], null, '   ', 'recent')->pluck('manga_id')->all(),
        );
    }

    public function test_sorting_by_title_ignores_case(): void
    {
        $entries = collect([
            $this->entry(1, 'beta', '2026-01-01', 1),
            $this->entry(2, 'Alpha', '2026-01-02', 1),
            $this->entry(3, 'gamma', '2026-01-03', 1),
        ]);

        $this->assertSame(
            [2, 1, 3],
            $this->filter->matching($entries, [], null, '', 'title')->pluck('manga_id')->all(),
        );
    }

    public function test_sorting_by_added_puts_the_newest_first_and_undated_last(): void
    {
        $entries = collect([
            $this->entry(1, 'Older', '2026-01-01', 1),
            $this->entry(2, 'Newest', '2026-01-03', 1),
            $this->entry(3, 'Undated', null, 1),
            $this->entry(4, 'Middle', '2026-01-02', 1),
        ]);

        $this->assertSame(
            [2, 4, 1, 3],
            $this->filter->matching($entries, [], null, '', 'added')->pluck('manga_id')->all(),
        );
    }

    public function test_sorting_by_unread_puts_the_most_waiting_first_and_never_fetched_last(): void
    {
        $entries = collect([
            $this->entry(1, 'Some', '2026-01-01', 2),
            $this->entry(2, 'Never fetched', '2026-01-02', null),
            $this->entry(3, 'Most', '2026-01-03', 90),
            $this->entry(4, 'None', '2026-01-04', 0),
        ]);

        $this->assertSame(
            [3, 1, 4, 2],
            $this->filter->matching($entries, [], null, '', 'unread')->pluck('manga_id')->all(),
            'null sorts to -1 so the series this page cannot count yet land at the bottom',
        );
    }

    public function test_the_filters_combine(): void
    {
        $entries = $this->entries();
        $filings = [1 => [10], 2 => [10], 3 => [20], 4 => []];

        $this->assertSame(
            [2, 1],
            $this->filter->matching($entries, $filings, 10, 'a', 'unread')->pluck('manga_id')->all(),
            'shelf and search narrow first, then the unread sort orders what remains',
        );
    }

    public function test_paging_returns_sixty_then_the_rest_and_reindexes(): void
    {
        $entries = collect();

        for ($id = 1; $id <= 70; $id++) {
            $entries->push($this->entry($id, "Series {$id}", '2026-01-01', 1));
        }

        $first = $this->filter->page($entries, 1);
        $second = $this->filter->page($entries, 2);
        $past = $this->filter->page($entries, 3);

        $this->assertCount(60, $first);
        $this->assertSame(1, $first->first()['manga_id']);
        $this->assertSame(60, $first->last()['manga_id']);

        $this->assertCount(10, $second);
        $this->assertSame([0, 1, 2, 3, 4, 5, 6, 7, 8, 9], array_keys($second->all()));
        $this->assertSame(61, $second->first()['manga_id']);

        $this->assertTrue($past->isEmpty(), 'page 3 of two pages renders nothing rather than an error');
    }

    public function test_a_sort_value_is_clamped_to_the_offered_set(): void
    {
        $this->assertSame('recent', $this->filter->normaliseSort("title'; drop table series_states; --"));
        $this->assertSame('recent', $this->filter->normaliseSort(''));
        $this->assertSame('unread', $this->filter->normaliseSort('unread'));
    }

    public function test_a_filter_change_resets_the_page_but_other_properties_do_not(): void
    {
        foreach (['search', 'sort', 'shelf'] as $property) {
            $this->assertTrue($this->filter->resetsPage($property), $property.' is a filter');
        }

        foreach (['page', 'selecting', 'selected', 'newShelfName', 'renaming'] as $property) {
            $this->assertFalse($this->filter->resetsPage($property), $property.' is not a filter');
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function entries(): Collection
    {
        return collect([
            $this->entry(1, 'Alpha One', '2026-01-01', 3),
            $this->entry(2, 'Beta Two', '2026-01-02', 9),
            $this->entry(3, 'Gamma Two', '2026-01-03', null),
            $this->entry(4, 'Delta', '2026-01-04', 0),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(int $mangaId, string $title, ?string $addedAt, ?int $unread): array
    {
        return [
            'manga_id' => $mangaId,
            'title' => $title,
            'added_at' => $addedAt === null ? null : CarbonImmutable::parse($addedAt),
            'unread_chapters' => $unread,
        ];
    }
}
