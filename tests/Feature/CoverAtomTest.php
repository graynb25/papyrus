<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class CoverAtomTest extends TestCase
{
    /** @test */
    public function test_the_atom_holds_its_slot_over_its_own_cover()
    {
        // The slot's badges are positioned absolutely, so the slot's owner has to
        // be a containing block. Measured live: with glow and tilt both off the
        // atom's root rendered static and the nearest positioned ancestor won,
        // which was the page card, so every row's unread chip stacked on the
        // card's top-left corner instead of its own cover's.
        $html = trim(Blade::render(
            '<x-cover.art :src="null" :blur-up="false" :vt="false">sentinel</x-cover.art>'
        ));

        $this->assertMatchesRegularExpression(
            '/^<div\b[^>]*\bclass="[^"]*\brelative\b[^"]*"/s',
            $html,
            'The atom root carries the slot that overlays the artwork, so the root has to be the positioned ancestor.'
        );
    }

    /** @test */
    public function test_the_atom_keeps_the_wrap_when_it_leans_and_glows()
    {
        $html = trim(Blade::render(
            '<x-cover.art :src="null" :glow="true" :tilt="true" :blur-up="false" :vt="false">sentinel</x-cover.art>'
        ));

        $tag = mb_substr($html, 0, mb_strpos($html, '>') + 1);

        $this->assertStringContainsString('relative', $tag);
        $this->assertStringContainsString('cover-wrap', $tag);
        $this->assertStringContainsString('cover-tilt', $tag);
    }

    /** @test */
    public function test_the_slot_paints_above_the_frame()
    {
        $html = trim(Blade::render(
            '<x-cover.art :src="null" :blur-up="false" :vt="false">sentinel</x-cover.art>'
        ));

        $this->assertStringContainsString('cover-slot', $html);
        $this->assertGreaterThan(
            mb_strpos($html, 'cover-frame'),
            mb_strpos($html, 'cover-slot'),
            'The slot wrapper has to follow the frame so its overlays paint above the artwork.'
        );
    }
}
