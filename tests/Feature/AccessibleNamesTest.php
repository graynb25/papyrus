<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The names on the controls the sweep found unnamed.
 *
 * The sweep's rule is the one the house already used in the craft pass: a
 * control is named when it has an `aria-label`, an `aria-labelledby` that
 * resolves, a label element that points at it, or a wrapping label. A
 * placeholder is not a name, because it goes the moment the reader types.
 *
 * These are the checks that hold the shell-level one. The page-level ones sit
 * in the page's own suite, where the server fake already is.
 */
class AccessibleNamesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The toast's dismiss button is named.
     *
     * Flux's toast ships a close button with no accessible name, and the app
     * cannot put one on it: the button lives inside the component's own
     * template, and the element the reader sees is a copy of that template
     * made at the moment the toast appears. Measured on 2026-10-21: the
     * library page carries exactly one control with no name, a 32 by 32
     * button at the bottom right of the viewport, and pressing it takes the
     * notification away.
     *
     * So the name goes on after the copy is made, and the shell carries the
     * script that does it. This test holds the three parts of that wiring
     * together: the name, the selector it applies to, and the watch that
     * catches the copy when it appears.
     *
     * **The browser walk is the real proof, and it is in the register.** A
     * Livewire test never runs the page's scripts, so this one says the
     * wiring is shipped and not that it works.
     */
    public function test_the_toasts_dismiss_button_is_named(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/library')
            ->assertSee('Close notification', false)
            ->assertSee('ui-toast-group', false)
            ->assertSee('ui-close button', false);
    }

    /**
     * The shell's script is on every page, not only on the library.
     *
     * The toast can be raised from any page, so the naming has to be in the
     * shell rather than in one page's markup.
     */
    public function test_the_naming_is_in_the_shell_and_not_in_one_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/search')
            ->assertSee('Close notification', false);
    }
}
