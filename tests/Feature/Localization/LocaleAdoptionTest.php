<?php

namespace Tests\Feature\Localization;

use App\Models\User;
use App\Support\Deployment;
use App\Support\LocalePreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleAdoptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_locale_is_stored_when_registering(): void
    {
        config(['papyrus.deployment_mode' => Deployment::SHARED]);

        $response = $this->withSession(['locale' => 'es'])
            ->post(route('register.store'), [
                'name' => 'Guest Reader',
                'email' => 'guest-reader@example.test',
                'password' => 'Correct-Horse-Battery-42',
                'password_confirmation' => 'Correct-Horse-Battery-42',
            ]);

        $response->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'guest-reader@example.test')->firstOrFail();

        $this->assertSame('es', app(LocalePreference::class)->read($user));
    }

    public function test_a_guest_locale_is_stored_when_logging_in_to_an_account_without_one(): void
    {
        $user = User::factory()->create();

        $this->withSession(['locale' => 'es'])
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertSame('es', app(LocalePreference::class)->read($user));
    }

    public function test_an_existing_preference_is_not_overwritten_by_the_session(): void
    {
        $user = User::factory()->create();

        app(LocalePreference::class)->write($user, 'es');

        $this->withSession(['locale' => 'en'])
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertSame('es', app(LocalePreference::class)->read($user));
    }

    public function test_an_uncatalogued_session_locale_is_ignored_on_login(): void
    {
        $user = User::factory()->create();

        $this->withSession(['locale' => 'it'])
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertNull(app(LocalePreference::class)->read($user));
        $this->assertDatabaseMissing('user_settings', [
            'user_id' => $user->id,
            'key' => LocalePreference::KEY,
        ]);
    }
}
