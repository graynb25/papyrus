<?php

namespace Tests\Unit\Series;

use App\Live\Series\SeriesFormatting;
use Tests\TestCase;

/**
 * The series page's pure rules, exercised without mounting the page.
 *
 * Each case is a boundary the page used to carry inside the Livewire
 * component: the scanlator name a source actually sent, the reader link, and
 * the paging arithmetic the list and footer share.
 */
class SeriesFormattingTest extends TestCase
{
    private SeriesFormatting $formatting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formatting = new SeriesFormatting;
    }

    public function test_an_invisible_or_missing_scanlator_is_no_scanlator(): void
    {
        $this->assertNull($this->formatting->scanlatorOf([]));
        $this->assertNull($this->formatting->scanlatorOf(['scanlator' => null]));
        $this->assertNull($this->formatting->scanlatorOf(['scanlator' => 42]));
        $this->assertNull($this->formatting->scanlatorOf(['scanlator' => "\u{200B}"]));
        $this->assertNull($this->formatting->scanlatorOf(['scanlator' => " \u{FEFF} "]));
    }

    public function test_a_visible_scanlator_is_trimmed_and_kept(): void
    {
        $this->assertSame('Group A', $this->formatting->scanlatorOf(['scanlator' => '  Group A  ']));
    }

    public function test_scanlators_are_unique_and_sorted(): void
    {
        $chapters = [
            ['scanlator' => 'Group B'],
            ['scanlator' => ' Group A '],
            ['scanlator' => 'Group B'],
            ['scanlator' => null],
            ['scanlator' => "\u{200B}"],
            [],
        ];

        $this->assertSame(['Group A', 'Group B'], $this->formatting->scanlators($chapters));
        $this->assertSame([], $this->formatting->scanlators([]));
    }

    public function test_chapter_url_points_at_the_reader_route(): void
    {
        $this->assertSame(
            route('reader.show', ['manga' => 76, 'chapter' => 7601]),
            $this->formatting->chapterUrl(76, 7601),
        );
    }

    public function test_the_visible_range_covers_each_page(): void
    {
        $this->assertSame([1, 50], $this->formatting->visibleRange(50, 1, 50));
        $this->assertSame([51, 51], $this->formatting->visibleRange(51, 2, 50));
        $this->assertSame([101, 120], $this->formatting->visibleRange(120, 3, 50));
        $this->assertSame([51, 60], $this->formatting->visibleRange(60, 3, 25));
    }

    public function test_an_empty_list_reports_no_range_rather_than_a_first_row(): void
    {
        $this->assertSame([0, 0], $this->formatting->visibleRange(0, 1, 50));
    }

    public function test_the_page_slice_returns_the_rows_for_that_page(): void
    {
        $chapters = [];

        for ($id = 1; $id <= 51; $id++) {
            $chapters[] = ['id' => $id];
        }

        $first = $this->formatting->pageSlice($chapters, 1, 50);
        $second = $this->formatting->pageSlice($chapters, 2, 50);

        $this->assertCount(50, $first);
        $this->assertSame(1, $first[0]['id']);
        $this->assertSame(50, $first[49]['id']);

        $this->assertCount(1, $second);
        $this->assertSame(51, $second[0]['id']);

        $this->assertSame([], $this->formatting->pageSlice($chapters, 3, 50));
    }
}
