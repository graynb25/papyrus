<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_password_screen_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('password.confirm'));

        $response->assertOk();
    }

    /**
     * The page says what the password is for, and a reader who did not mean to be here
     * has a way back instead of only the browser's back button.
     */
    public function test_it_says_why_the_password_is_needed_again_and_offers_a_way_back(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('password.confirm'))
            ->assertOk()
            ->assertSee('For your security, Papyrus needs your password again before this change.')
            ->assertSee('Back to your library')
            ->assertSee('href="'.route('library').'"', false);
    }
}
