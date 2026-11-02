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
        $this->assertStringContainsString('answers only the ids in the cached wall payload', $text);
        $this->assertStringContainsString('The CBZ export carries `throttle:6,1`', $text);
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

        $export = Route::getRoutes()->getByName('downloads.cbz');

        $this->assertNotNull($export, 'the CBZ export route exists');

        $this->assertContains(
            'Illuminate\Routing\Middleware\ThrottleRequests:6,1',
            $this->app->make('router')->gatherRouteMiddleware($export),
            'the CBZ export carries the documented six per minute limit',
        );
    }

    public function test_the_reset_link_origin_is_stated(): void
    {
        $this->assertStringContainsString(
            'Password reset links are built from `APP_URL`, not the request `Host` header',
            $this->text(),
        );
    }

    public function test_the_extension_section_states_the_import_review_and_runtime_trust(): void
    {
        $text = $this->text();

        $this->assertStringContainsString('The Sources page asks for an explicit trust decision per store', $text);
        $this->assertStringContainsString('Import asks the same question', $text);
        $this->assertStringContainsString('A backup names the extensions it needs', $text);
        $this->assertStringContainsString('Nothing installs until you tick a store and confirm', $text);
        $this->assertStringContainsString('The install then asks the database which stores you trust at that moment', $text);
        $this->assertStringContainsString('An unanswered review expires after 24 hours', $text);
        $this->assertStringContainsString('Clear the checkbox, or choose "Import without extensions"', $text);
        $this->assertStringNotContainsString('known risk, accepted for this release', $text);
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
        $this->assertStringContainsString('the encrypted AniList OAuth token', $text);
        $this->assertStringContainsString('password hashes and remember tokens', $text);
        $this->assertStringContainsString('It does not carry live session rows or the `cache` table', $text);
        $this->assertStringContainsString('their rows are removed and the file is vacuumed', $text);
        $this->assertStringContainsString('Treat the backups folder as a secret', $text);
        $this->assertStringContainsString(
            'The AniList token in the pair is ciphertext',
            $text,
        );
    }

    public function test_the_anilist_token_is_encrypted_and_disconnect_is_honest(): void
    {
        $text = $this->text();

        $this->assertStringContainsString('AniList tokens are encrypted at rest', $text);
        $this->assertStringContainsString("Laravel's `encrypted` cast", $text);
        $this->assertStringContainsString('AniList publishes no token revocation endpoint', $text);
        $this->assertStringContainsString('does not invalidate the token on AniList', $text);
        $this->assertStringContainsString('The token remains valid there until it expires', $text);
        $this->assertStringContainsString('restored on an install with a different `APP_KEY`', $text);
    }
}
