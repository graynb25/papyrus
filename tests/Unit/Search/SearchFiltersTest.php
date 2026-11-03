<?php

namespace Tests\Unit\Search;

use App\Live\Search\SearchFilters;
use Tests\TestCase;

/**
 * The search page's one fanout options array, exercised without mounting.
 *
 * The action and the cost line both build this array, so the cases pin the empty
 * language coercion, the ratings passing through already narrowed by the gate, the
 * unchanged limit and the shape they share.
 */
class SearchFiltersTest extends TestCase
{
    private SearchFilters $filters;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filters = new SearchFilters;
    }

    public function test_empty_languages_become_null_and_a_list_passes_through(): void
    {
        $this->assertNull($this->filters->options([], ['SAFE', 'MIXED'], 40)['langs']);
        $this->assertSame(['en', 'ja'], $this->filters->options(['en', 'ja'], ['SAFE', 'MIXED'], 40)['langs']);
    }

    public function test_the_ratings_pass_through_as_narrowed_by_the_gate(): void
    {
        $narrowed = ['SAFE', 'MIXED'];

        $this->assertSame($narrowed, $this->filters->options([], $narrowed, 40)['content']);
        $this->assertSame(['SAFE', 'MIXED', 'NSFW'], $this->filters->options([], ['SAFE', 'MIXED', 'NSFW'], 40)['content']);
    }

    public function test_the_limit_is_unchanged(): void
    {
        $this->assertSame(1, $this->filters->options([], ['SAFE', 'MIXED'], 1)['limit']);
        $this->assertSame(150, $this->filters->options([], ['SAFE', 'MIXED'], 150)['limit']);
    }

    public function test_the_action_and_the_cost_line_share_one_shape(): void
    {
        $this->assertSame(
            [
                'langs' => ['en'],
                'content' => ['SAFE', 'MIXED'],
                'limit' => 40,
            ],
            $this->filters->options(['en'], ['SAFE', 'MIXED'], 40),
        );
    }
}
