<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The shared page header, and the one optional thing on it.
 *
 * The back link is a named URL rather than `history.back()`: a reader who
 * opened a bookmarked page has no history to go back along, and a control that
 * does nothing is worse than no control. The component only draws it when the
 * page names a parent, which is the second test here.
 */
class PageHeaderTest extends TestCase
{
    public function test_a_page_that_names_its_parent_draws_the_way_back(): void
    {
        $this->blade(
            '<x-page.header back="/sources" back-label="Back to sources">'
            .'<x-slot:title>Source health</x-slot:title>'
            .'</x-page.header>'
        )
            ->assertSee('href="/sources"', false)
            ->assertSee('Back to sources');
    }

    public function test_a_top_level_page_draws_no_back_link(): void
    {
        $this->blade(
            '<x-page.header>'
            .'<x-slot:title>Library</x-slot:title>'
            .'</x-page.header>'
        )
            ->assertDontSee('href="/sources"', false)
            ->assertDontSee('Back to sources');
    }
}
