<?php

namespace Tests\Feature;

use App\Http\Controllers\SuwayomiProxyController;
use Illuminate\Support\Facades\Route;
use ReflectionClassConstant;
use Tests\TestCase;

/**
 * The security page, held against the code it describes.
 *
 * ## Why a documentation test exists
 *
 * Because three claims on this page were false for a release and no test noticed. The page is
 * the one an operator reads before deciding what to put in front of Papyrus, so a wrong
 * sentence about the proxy allowlist, the rate limits or the extension trust path is a
 * security claim the tree does not support.
 *
 * The assertions are on phrases and on the values the page names, never on line numbers or the
 * whole document. A full-text comparison would fail on every paragraph that has nothing to do
 * with security, and then be weakened until it noticed nothing.
 */
class SecurityDocumentTest extends TestCase
{
    private string $document = '';

    protected function setUp(): void
    {
        parent::setUp();

        $path = base_path('docs/SECURITY.md');

        $this->assertFileExists($path);

        $this->document = (string) file_get_contents($path);
    }

    /**
     * The page wrapped at one width and read at another.
     *
     * Line breaks inside a sentence are formatting, not content, and an assertion that spans
     * one would fail every time a paragraph is re-wrapped.
     */
    private function text(): string
    {
        return (string) preg_replace('/\s+/', ' ', $this->document);
    }

    public function test_the_proxy_parameter_list_matches_the_controller(): void
    {
        $this->assertStringContainsString(
            'only `format`, `updateProgress`, `width` and `height` forwarded to Suwayomi',
            $this->text(),
        );
        $this->assertStringNotContainsString('only `format` and `updateProgress`', $this->text());

        $constant = new ReflectionClassConstant(SuwayomiProxyController::class, 'ALLOWED_QUERY');

        $this->assertSame(
            ['format', 'updateProgress', 'width', 'height'],
            $constant->getValue(),
            'the documented proxy parameters are the ones the controller forwards',
        );
    }

    public function test_the_rate_limit_claim_matches_the_routes(): void
    {
        $text = $this->text();

        $this->assertStringContainsString(
            'The public FAQ, privacy, terms and showcase JSON routes carry `throttle:120,1`',
            $text,
        );

        $this->assertStringContainsString('showcase cover route is deliberately not throttled', $text);
        $this->assertStringNotContainsString('No application route is rate limited', $text);

        foreach (['faq', 'privacy', 'terms', 'showcase.index'] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "the {$name} route exists");

            $this->assertContains(
                'Illuminate\Routing\Middleware\ThrottleRequests:120,1',
                $this->app->make('router')->gatherRouteMiddleware($route),
                "the {$name} route carries the documented 120 per minute throttle",
            );
        }

        $cover = Route::getRoutes()->getByName('showcase.cover');

        $this->assertNotNull($cover, 'the showcase cover route exists');

        $this->assertNotContains(
            'Illuminate\Routing\Middleware\ThrottleRequests:120,1',
            $this->app->make('router')->gatherRouteMiddleware($cover),
            'the cover route is deliberately not throttled',
        );
    }

    public function test_the_extension_section_states_the_import_default_and_the_trust_bypass(): void
    {
        $text = $this->text();

        $this->assertStringContainsString('The Sources page asks for an explicit trust decision per store', $text);
        $this->assertStringContainsString('A backup names the extensions it needs', $text);
        $this->assertStringContainsString('Papyrus installs them by default without the per-store trust step', $text);
        $this->assertStringContainsString('known risk, accepted for this release', $text);
        $this->assertStringContainsString('Clear the checkbox before importing a backup you did not make yourself', $text);
        $this->assertStringNotContainsString('nothing installs itself quietly', $text);
    }

    public function test_the_owner_only_boundary_is_stated(): void
    {
        $text = $this->text();

        $this->assertStringContainsString(
            'Whole-install backup, restore, export and store add or remove require the install owner',
            $text,
        );
        $this->assertStringContainsString(
            'first account, made by the browser\'s first-account window or by `php artisan users:create`',
            $text,
        );
        $this->assertStringContainsString('the role is not settable from a web request', $text);
        $this->assertStringContainsString('Readers keep their own library, imports, trust records and profile', $text);
    }

    public function test_the_backup_secrets_are_named(): void
    {
        $text = $this->text();

        $this->assertStringContainsString('A backup pair is not a library-only copy', $text);
        $this->assertStringContainsString('live `sessions` rows', $text);
        $this->assertStringContainsString('the `cache` table where the Suwayomi bearer and refresh tokens live', $text);
        $this->assertStringContainsString('the plaintext AniList OAuth token', $text);
        $this->assertStringContainsString('password hashes and remember tokens', $text);
        $this->assertStringContainsString('Treat the backups folder as a secret', $text);
        $this->assertStringContainsString(
            'Version 1.5.0 does not exclude those tables or encrypt the AniList token inside a backup',
            $text,
        );
    }
}
