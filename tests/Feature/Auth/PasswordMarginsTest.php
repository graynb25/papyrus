<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\Deployment;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Livewire\Livewire;
use Tests\TestCase;

/*
 * The meter is the browser-visible half of the validating rule. Nothing
 * about it is allowed to be quietly wrong: a row that renders an old copy
 * of the config, an anchor no field points at, ticks that survive a
 * Livewire reset. These tests pin those seams, with config overrides
 * standing in for drift.
 */
class PasswordMarginsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
        $this->skipUnlessFortifyHas(Features::resetPasswords());
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::passkeys([
            'confirmPassword' => true,
        ]);

        /*
         * Registration and its pages are closed in `single`, which is the
         * default the test environment runs in; these surfaces render
         * inside `shared`.
         */
        config(['papyrus.deployment_mode' => Deployment::SHARED]);
    }

    public function test_the_register_page_lists_the_margins_the_server_enforces(): void
    {
        $min = (int) config('papyrus.password_margins.min');

        $response = $this->get(route('register'));

        $response->assertOk()
            ->assertSee('At least '.$min.' characters')
            ->assertSee('Uses upper and lower case')
            ->assertSee('Has a number')
            ->assertSee('Has a symbol')
            ->assertSee('Matches the first password');

        $response->assertDontSee('known leak lists');
    }

    public function test_the_meter_follows_the_margins_rather_than_a_copy_of_them(): void
    {
        config(['papyrus.password_margins.min' => 4]);
        config(['papyrus.password_margins.numbers' => false]);
        config(['papyrus.password_margins.symbols' => false]);

        $response = $this->get(route('register'));

        $response->assertOk()
            ->assertSee('At least 4 characters')
            ->assertDontSee('Has a number')
            ->assertDontSee('Has a symbol');
    }

    public function test_the_breach_margin_names_itself_where_the_server_owns_the_check(): void
    {
        config(['papyrus.password_margins.breach_on_submit' => true]);

        $response = $this->get(route('register'));

        $response->assertOk()
            ->assertSee('Not in known leak lists (checked when you submit)');
    }

    public function test_the_password_fields_declare_their_margins_to_the_screen(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk()
            ->assertSee('id="password"', false)
            ->assertSee('id="password_confirmation"', false)
            ->assertSee('aria-describedby="password-margins"', false)
            ->assertSee('aria-describedby="password_confirmation-margins"', false)
            ->assertSee('id="password-margins"', false)
            ->assertSee('id="password_confirmation-margins"', false);
    }

    public function test_the_reset_page_wears_the_same_margins_and_wiring(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        $token = null;

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $min = (int) config('papyrus.password_margins.min');

        $response = $this->get(route('password.reset', $token));

        $response->assertOk()
            ->assertSee('At least '.$min.' characters')
            ->assertSee('Matches the first password')
            ->assertSee('aria-describedby="password-margins"', false)
            ->assertSee('aria-describedby="password_confirmation-margins"', false)
            ->assertSee('id="password-margins"', false);
    }

    public function test_the_security_page_declares_the_same_margins(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);

        $this->actingAs($user);

        $min = (int) config('papyrus.password_margins.min');

        $html = Livewire::test('pages::settings.security')->html();

        $this->assertStringContainsString('At least '.$min.' characters', $html);
        $this->assertStringContainsString('aria-describedby="password-margins"', $html);
        $this->assertStringContainsString('aria-describedby="password_confirmation-margins"', $html);
    }

    public function test_the_validation_speaks_the_same_margins_the_meter_lists(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);

        $this->actingAs($user);

        config(['papyrus.password_margins.min' => 8]);

        // 'Abcd1234!' meets every margin at eight characters and would be
        // refused by the committed twelve, so the outcome has to ride the
        // config both ways in this one test.
        Livewire::test('pages::settings.security')
            ->set('current_password', 'password')
            ->set('password', 'Abcd1234!')
            ->set('password_confirmation', 'Abcd1234!')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('Abcd1234!', $user->refresh()->password));

        config(['papyrus.password_margins.min' => 12]);

        Livewire::test('pages::settings.security')
            ->set('current_password', 'password')
            ->set('password', 'Abcd1234!')
            ->set('password_confirmation', 'Abcd1234!')
            ->call('updatePassword')
            ->assertHasErrors(['password']);
    }
}
