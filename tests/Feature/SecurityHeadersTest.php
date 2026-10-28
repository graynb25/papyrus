<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The security headers every response carries.
 *
 * The audit measured guest responses with no CSP, no framing refusal, no referrer policy, no
 * permissions policy and no HSTS. The middleware is global, so the cases that matter are the
 * ones outside the web group as well as the ordinary pages: the readiness probe, a 404 that
 * Laravel renders outside the middleware pipeline, and a secure request, where HSTS appears.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_page_carries_the_security_headers(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $this->assertSecurityHeaders($response);
    }

    public function test_an_authenticated_page_carries_the_security_headers(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/insights');

        $response->assertOk();
        $this->assertSecurityHeaders($response);
    }

    /**
     * `/ready` is registered outside every middleware group, so this proves the middleware is
     * global rather than attached to the web stack.
     */
    public function test_the_readiness_probe_carries_the_security_headers(): void
    {
        $response = $this->getJson('/ready');

        $response->assertOk();
        $this->assertSecurityHeaders($response);
    }

    /**
     * A response rendered by the exception handler does not pass through the middleware
     * pipeline, so it needs the handler's own call to `apply()`.
     */
    public function test_an_error_page_carries_the_security_headers(): void
    {
        $response = $this->get('/no-such-page');

        $response->assertNotFound();
        $this->assertSecurityHeaders($response);
    }

    /**
     * HSTS is meaningful only after a browser has seen the site over HTTPS. Sending it on the
     * default loopback HTTP install would be ignored, and sending it on a host that later
     * serves plain HTTP would lock the reader out.
     */
    public function test_hsts_is_sent_only_on_a_secure_request(): void
    {
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/login')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    private function assertSecurityHeaders(TestResponse $response): void
    {
        $response->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'same-origin');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader(
            'Permissions-Policy',
            'camera=(), display-capture=(), geolocation=(), hid=(), microphone=(), payment=(), serial=(), usb=()',
        );
    }
}
