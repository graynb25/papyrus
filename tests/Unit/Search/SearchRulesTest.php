<?php

namespace Tests\Unit\Search;

use App\Live\Search\SearchRules;
use Tests\TestCase;

/**
 * The search action's validation rules, exercised without mounting the page.
 *
 * The exact arrays are pinned because the action passes them to `$this->validate()`
 * unchanged; a looser test would let a max length or a limit bound drift.
 */
class SearchRulesTest extends TestCase
{
    public function test_the_query_and_limit_rules_are_exact(): void
    {
        $this->assertSame(
            [
                'query' => ['required', 'string', 'max:512'],
                'limit' => ['integer', 'min:1', 'max:150'],
            ],
            (new SearchRules)->rules(),
        );
    }
}
