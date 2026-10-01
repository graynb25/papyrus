<?php

namespace Tests\Feature\Auth;

use App\Support\Deployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());

        /*
         * Fortify's feature flag says registration is compiled in. Whether it is reachable
         * is a separate question, and the answer is the deployment mode: `single` closes
         * it, which is the default the test environment runs in.
         *
         * This test used to pass without saying so, because nothing enforced the mode. It
         * was asserting that Fortify works while the app it runs in has registration
         * switched off, and only the gate's absence made that consistent.
         *
         * `RegistrationGateTest` covers the mode rule itself.
         */
        config(['papyrus.deployment_mode' => Deployment::SHARED]);
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'John Doe',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }
}
