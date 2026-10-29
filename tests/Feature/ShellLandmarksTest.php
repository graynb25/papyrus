<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shell's landmarks.
 *
 * A selector probe on the rendered shell found no `main` element and no in-page
 * anchor, so keyboard and screen-reader readers had to traverse the whole
 * navigation on every page. The page content was a generic region after the
 * sidebar and header.
 *
 * The `<main>` is a native element rather than a div with `role="main"`, because
 * the walk asked for the landmark the browser exposes and the probe looked for
 * the element. It keeps `data-flux-main`, which is what Flux's grid CSS keys off,
 * and the `[grid-area:main]` class that places it in that grid.
 */
class ShellLandmarksTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_shell_has_one_main_landmark_and_a_skip_link_to_it(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/library');

        $response->assertOk();

        $html = (string) $response->getContent();
        $xpath = new \DOMXPath($this->dom($html));

        $mains = $xpath->query('//main[@id="main-content"]');

        $this->assertNotFalse($mains);
        $this->assertSame(1, $mains->length, 'The page content needs one main landmark with a stable id.');

        $main = $mains->item(0);

        $this->assertNotNull($main);
        $this->assertTrue(
            $main->hasAttribute('data-flux-main'),
            'Flux places the main region with data-flux-main, and its grid CSS keys off that attribute.',
        );
        $this->assertStringContainsString(
            '[grid-area:main]',
            $main->getAttribute('class'),
            'The main region still needs the grid-area class Flux would have added.',
        );

        $links = $xpath->query('//a[@href="#main-content"]');

        $this->assertNotFalse($links);
        $this->assertSame(1, $links->length, 'There must be one skip link to the main region.');
        $this->assertSame('Skip to content', trim((string) $links->item(0)?->textContent));

        $firstLink = $xpath->query('//body//a')->item(0);

        $this->assertNotNull($firstLink);
        $this->assertSame(
            '#main-content',
            $firstLink->getAttribute('href'),
            'The skip link must be the first link in the shell, before the navigation.',
        );
    }

    private function dom(string $html): \DOMDocument
    {
        $doc = new \DOMDocument;
        $doc->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><body>'.$html.'</body>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        return $doc;
    }
}
