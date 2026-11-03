<?php

namespace Tests\Unit\Sources;

use App\Live\Sources\SourcesFilter;
use App\Models\Source;
use Tests\TestCase;

/**
 * The sources page's filter pipeline, exercised without mounting the page.
 *
 * Each case pins one half of the pipeline: the four slices, the trimmed name,
 * the language list passed through unchanged, and the two content shapes the
 * adult gate decides between. The page still owns the narrowing itself; these
 * tests hold that the pipeline does not narrow a second time.
 */
class SourcesFilterTest extends TestCase
{
    private SourcesFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filter = new SourcesFilter;
    }

    public function test_each_view_carries_its_own_slice_and_the_safe_ratings(): void
    {
        $ratings = [Source::SAFE, Source::MIXED];

        $this->assertSame(
            ['installed' => true, 'content' => []],
            $this->filter->filters('installed', '', [], $ratings, true),
        );

        $this->assertSame(
            ['installed' => false, 'obsolete' => false, 'content' => $ratings],
            $this->filter->filters('available', '', [], $ratings, true),
        );

        $this->assertSame(
            ['has_update' => true, 'installed' => true, 'content' => $ratings],
            $this->filter->filters('updates', '', [], $ratings, true),
        );

        $this->assertSame(
            ['content' => $ratings],
            $this->filter->filters('all', '', [], $ratings, true),
        );

        $this->assertSame(
            ['content' => $ratings],
            $this->filter->filters('a-view-the-page-does-not-know', '', [], $ratings, true),
            'an unknown view is the unfiltered family, as the default branch always was',
        );
    }

    public function test_the_name_is_trimmed_and_a_blank_one_is_omitted(): void
    {
        $this->assertSame(
            [
                'installed' => false,
                'obsolete' => false,
                'name' => 'Berserk',
                'content' => [Source::SAFE, Source::MIXED],
            ],
            $this->filter->filters('available', '  Berserk  ', [], [Source::SAFE, Source::MIXED], true),
            'the name is inserted before the content key, as the inline pipeline did',
        );

        $this->assertArrayNotHasKey(
            'name',
            $this->filter->filters('available', " \t\n ", [], [Source::SAFE, Source::MIXED], true),
        );
    }

    public function test_the_language_list_is_passed_through_case_sensitively(): void
    {
        $filters = $this->filter->filters(
            'available',
            '',
            ['pt-BR', 'pt-br'],
            [Source::SAFE, Source::MIXED],
            true,
        );

        $this->assertSame(
            ['pt-BR', 'pt-br'],
            $filters['lang'],
            'the server match is case sensitive, so folding the code would filter nothing',
        );
    }

    public function test_the_installed_slice_has_no_rating_while_the_gate_is_on(): void
    {
        $filters = $this->filter->filters('installed', '', [], [Source::NSFW], true);

        $this->assertSame([], $filters['content']);
    }

    public function test_the_installed_slice_carries_the_safe_default_while_the_gate_is_off(): void
    {
        $filters = $this->filter->filters('installed', '', [], [Source::NSFW], false);

        $this->assertSame(
            [Source::SAFE, Source::MIXED],
            $filters['content'],
            'the installed list names what the gate hides rather than trusting the address',
        );
    }

    public function test_the_other_slices_carry_the_ratings_the_page_already_narrowed(): void
    {
        foreach (['available', 'updates', 'all', 'unknown'] as $view) {
            $this->assertSame(
                [Source::SAFE],
                $this->filter->filters($view, '', [], [Source::SAFE], true)['content'],
                'the gate is applied by the page, not a second time here',
            );

            $this->assertSame(
                [Source::SAFE],
                $this->filter->filters($view, '', [], [Source::SAFE], false)['content'],
            );
        }
    }

    public function test_filters_without_language_drops_only_the_language(): void
    {
        foreach (['installed', 'available', 'updates', 'all'] as $view) {
            $filters = $this->filter->filters(
                $view,
                '  Example ',
                ['ja'],
                [Source::SAFE, Source::MIXED],
                true,
            );

            $without = $this->filter->filtersWithoutLanguage(
                $view,
                '  Example ',
                ['ja'],
                [Source::SAFE, Source::MIXED],
                true,
            );

            $this->assertArrayHasKey('lang', $filters);
            $this->assertArrayNotHasKey('lang', $without);

            unset($filters['lang']);

            $this->assertSame($filters, $without);
        }
    }
}
