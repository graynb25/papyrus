<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/dashboard` is a redirect to the library.
 *
 * It used to render the Laravel starter placeholder, which was the least useful
 * page in an app whose whole purpose is reading. The route name stays because it is
 * in the sidebar and in saved links, so this file pins the redirect rather than
 * deleting it: a 404 for a reader with the app open is worse than a hop.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_the_dashboard_sends_a_reader_to_the_library(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))->assertRedirect(route('library'));
    }

    public function test_the_library_itself_needs_authentication(): void
    {
        $this->get(route('library'))->assertRedirect(route('login'));
    }

    public function test_a_reader_can_open_the_library(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('library'))->assertOk();
    }
}
