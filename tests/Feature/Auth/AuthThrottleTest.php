<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * SEC-01: the auth POSTs that Fortify leaves unthrottled, and the reset response that
 * confirms whether an address exists.
 */
final class AuthThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_forgot_password_is_limited_by_address(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->post(route('password.email'), ['email' => "reader{$i}@example.test"])
                ->assertStatus(302);
        }

        $limited = $this->post(route('password.email'), ['email' => 'reader21@example.test']);

        $limited->assertStatus(429);
        $limited->assertHeader('Retry-After');
    }

    public function test_registration_is_limited_by_address_in_shared_mode(): void
    {
        config(['papyrus.deployment_mode' => 'shared']);

        for ($i = 1; $i <= 20; $i++) {
            $this->post(route('register.store'), [
                'name' => 'Reader '.$i,
                'email' => "new{$i}@example.test",
                'password' => 'Correct-Horse-Battery-42',
                'password_confirmation' => 'Correct-Horse-Battery-42',
            ]);
        }

        $this->post(route('register.store'), [
            'name' => 'Reader 21',
            'email' => 'new21@example.test',
            'password' => 'Correct-Horse-Battery-42',
            'password_confirmation' => 'Correct-Horse-Battery-42',
        ])->assertStatus(429);
    }

    public function test_password_confirmation_is_limited_by_address(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 20; $i++) {
            $this->actingAs($user)->post(route('password.confirm.store'), [
                'password' => 'wrong-password',
            ]);
        }

        $this->actingAs($user)->post(route('password.confirm.store'), [
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_the_login_limiter_still_returns_429_after_five_tries(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 5; $i++) {
            $this->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertSessionHasErrorsIn('email');
        }

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_a_known_and_an_unknown_reset_address_get_the_same_response(): void
    {
        $user = User::factory()->create();

        $known = $this->postJson(route('password.email'), ['email' => $user->email]);
        $unknown = $this->postJson(route('password.email'), ['email' => 'nobody@example.test']);

        $known->assertOk()->assertJson(['message' => trans('passwords.sent')]);
        $unknown->assertOk()->assertJson(['message' => trans('passwords.sent')]);
    }

    public function test_the_reset_form_shows_the_same_status_for_both_addresses(): void
    {
        $user = User::factory()->create();

        $known = $this->post(route('password.email'), ['email' => $user->email]);
        $unknown = $this->post(route('password.email'), ['email' => 'nobody@example.test']);

        $known->assertSessionHas('status', trans('passwords.sent'));
        $unknown->assertSessionHas('status', trans('passwords.sent'));
        $known->assertSessionHasNoErrors();
        $unknown->assertSessionHasNoErrors();
    }
}
