<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Deployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

/**
 * The deployment mode's registration rule, tested through the app rather than the object.
 *
 * ## Why this file exists
 *
 * `Deployment::allowsRegistration()` was written, documented and unit tested from the day
 * the deployment modes were added. The unit test proved it returns false in `single` mode
 * and passed the whole time. Nothing ever called it, so `/register` answered 200 on a
 * single-user install and anyone who could reach the site could create an account.
 *
 * That is the failure mode worth writing down: a test asserting that a method returns a
 * value reads as coverage of the behaviour and is not, because nothing routes through it.
 * Every test here makes a request.
 */
class RegistrationGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    private function inMode(string $mode): void
    {
        config(['papyrus.deployment_mode' => $mode]);
    }

    // The rule holds ------------------------------------------------------

    public function test_single_mode_closes_the_registration_page(): void
    {
        $this->inMode(Deployment::SINGLE);

        $this->get(route('register'))->assertNotFound();
    }

    public function test_single_mode_closes_the_registration_post(): void
    {
        $this->inMode(Deployment::SINGLE);

        /*
         * The POST is the one that matters. A GET that 404s while the POST still works
         * would hide the form and leave the hole open, which is the shape a lazy fix
         * takes.
         */
        $this->post(route('register.store'), [
            'name' => 'Intruder',
            'email' => 'intruder@example.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'intruder@example.test']);
        $this->assertGuest();
    }

    public function test_shared_and_public_modes_leave_registration_open(): void
    {
        foreach ([Deployment::SHARED, Deployment::PUBLIC] as $mode) {
            $this->inMode($mode);

            $this->get(route('register'))->assertOk();
        }
    }

    public function test_shared_mode_can_still_create_an_account(): void
    {
        $this->inMode(Deployment::SHARED);

        $this->post(route('register.store'), [
            'name' => 'Second Reader',
            'email' => 'second@example.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'second@example.test']);
    }

    // The page stops advertising it ---------------------------------------

    public function test_the_login_page_offers_sign_up_only_when_it_is_allowed(): void
    {
        /*
         * The link was unconditional, so a single-user install pointed at an endpoint that
         * now answers 404. A visible link to a closed door is worse than no link.
         */
        $this->inMode(Deployment::SINGLE);
        $this->get(route('login'))->assertOk()->assertDontSee(__('Sign up'));

        $this->inMode(Deployment::SHARED);
        $this->get(route('login'))->assertOk()->assertSee(__('Sign up'));
    }

    public function test_the_login_page_still_renders_in_single_mode(): void
    {
        // Hiding the link must not hide the form, which is the only way in.
        $this->inMode(Deployment::SINGLE);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee(__('Log in'))
            ->assertSee('type="email"', escape: false);
    }

    // Nothing else was closed by accident --------------------------------

    public function test_password_reset_still_works_in_single_mode(): void
    {
        /*
         * The middleware matches two route names. Widening it to anything containing
         * "register", or closing the whole guest group, would take the recovery route with
         * it, and a locked-out reader with no reset is the worst outcome available here.
         */
        $this->inMode(Deployment::SINGLE);

        $this->get(route('password.request'))->assertOk();
    }

    public function test_login_still_works_in_single_mode(): void
    {
        $user = User::factory()->create();

        $this->inMode(Deployment::SINGLE);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }
}
