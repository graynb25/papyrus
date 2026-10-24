<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The names on the controls the sweep found unnamed.
 *
 * The sweep's rule is the one the house already used in the craft pass: a
 * control is named when it has an `aria-label`, an `aria-labelledby` that
 * resolves, a label element that points at it, or a wrapping label. A
 * placeholder is not a name, because it goes the moment the reader types.
 *
 * The toast is the shell-level case. Its name lives in a local copy of Flux's
 * toast view, so it is present on first paint on every page rather than being
 * added by a script after the copy is made. The cost of that copy is a re-diff
 * against the vendor view on every Flux upgrade.
 */
class AccessibleNamesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The toast's dismiss button is named on a page.
     *
     * Flux's toast ships a close button with no accessible name. The button
     * lives inside the component's own template, and the element the reader
     * sees is a copy of that template made when the toast appears, so the name
     * has to be in the template rather than added from outside later.
     *
     * The app keeps a local copy of the Flux view at
     * resources/views/flux/toast/index.blade.php. It is an exact copy of the
     * vendor view apart from aria-label="Close notification" on that button and
     * a comment recording why. FluxServiceProvider registers
     * resource_path('views/flux') before the vendor stub path, so the local
     * copy shadows the vendor one. Re-diff the two files whenever Flux is
     * upgraded; that is the cost of the override.
     *
     * This asserts the markup the server sends. It does not run the page's
     * scripts, and it does not prove what a screen reader announces; the
     * browser walk in the register is the live proof.
     */
    public function test_the_toasts_dismiss_button_is_named_on_a_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/library');

        $response->assertOk();

        $html = (string) $response->getContent();

        $this->assertStringNotContainsString('fluxToastNamed', $html);
        $this->assertSame(1, $this->toastCloseButtons($html)->length);
    }

    /**
     * The name is in the shell, not only on the library page.
     *
     * The toast can be raised from any page, so the named button has to come
     * from the layout every page shares. Search is the second page checked
     * here; the same local toast view renders in the shell on both.
     */
    public function test_the_naming_is_in_the_shell_and_not_in_one_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/search');

        $response->assertOk();

        $this->assertSame(1, $this->toastCloseButtons((string) $response->getContent())->length);
    }

    /**
     * The rendered markup, parsed.
     *
     * Reading an attribute off parsed markup rather than off the string is the
     * house pattern: Flux emits a run of attributes and classes of its own, so
     * a string search has to guess at formatting that is not observable.
     */
    private function dom(string $html): \DOMDocument
    {
        $doc = new \DOMDocument;
        $doc->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><body>'.$html.'</body>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        return $doc;
    }

    /**
     * The toast's close button, wherever the local view put it.
     */
    private function toastCloseButtons(string $html): \DOMNodeList
    {
        $xpath = new \DOMXPath($this->dom($html));

        $buttons = $xpath->query('//ui-close/button[@aria-label="Close notification"]');

        $this->assertNotFalse($buttons);

        return $buttons;
    }
}
