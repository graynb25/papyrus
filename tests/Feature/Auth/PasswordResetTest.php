<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());
    }

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get(route('password.request'));

        $response->assertOk();
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    /**
     * SEC-02: the reset link is built from APP_URL, not whatever Host arrived.
     *
     * Both assertions matter. The first pins the link to the configured origin;
     * the second is the attack, a forged Host header that used to become the
     * link's host and would have handed the token to evil.example.
     */
    public function test_the_reset_link_uses_the_configured_app_host(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $host = parse_url((string) $notification->toMail($user)->actionUrl, PHP_URL_HOST);

            $this->assertSame(parse_url((string) config('app.url'), PHP_URL_HOST), $host);

            return true;
        });
    }

    public function test_a_forged_host_header_cannot_steer_the_reset_link(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        /*
         * An absolute URL with an unlisted host. Passing the path alone would
         * not work: the test client turns a path into an absolute URL from the
         * configured root, and Symfony then overwrites HTTP_HOST with that
         * host, so the forged header never reaches the request.
         */
        $evil = 'http://evil.example'.parse_url(route('password.request'), PHP_URL_PATH);

        $this->post($evil, ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $host = parse_url((string) $notification->toMail($user)->actionUrl, PHP_URL_HOST);

            $this->assertNotSame('evil.example', $host);
            $this->assertSame(parse_url((string) config('app.url'), PHP_URL_HOST), $host);

            return true;
        });
    }

    /**
     * The middleware's list is the configured host plus the two loopback forms.
     *
     * TrustHosts does not run under the test environment, so the list is read
     * directly rather than inferred from a request. It is still worth pinning:
     * a list that lost the configured host would 400 every real request, and an
     * unanchored pattern would accept `evil-localhost.example`.
     */
    public function test_the_trusted_hosts_cover_the_configured_host_and_loopback(): void
    {
        $hosts = $this->app->make(TrustHosts::class)->hosts();

        $configured = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        foreach ([$configured, 'localhost', '127.0.0.1', '[::1]'] as $host) {
            $this->assertContains('^'.preg_quote($host, '/').'$', $hosts);
        }
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get(route('password.reset', $notification->token));

            $response->assertOk();

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'Correct-Horse-Battery-42',
                'password_confirmation' => 'Correct-Horse-Battery-42',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login', absolute: false));

            return true;
        });
    }
}
