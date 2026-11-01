<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The proxy exists so the browser never holds a Suwayomi token. The cost of
 * that is a relay sitting inside the app, so the allowlist is the part that
 * actually matters.
 */
class SuwayomiProxyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The status the fake Suwayomi answers page requests with, and the Cache-Control
     * window it attaches to a healthy answer (empty string attaches none).
     *
     * Both read at request time rather than baked into the fake, because `Http::fake()` appends a
     * stub and the first one registered wins: a test that re-fakes the same pattern after
     * `setUp` silently keeps getting the `setUp` answer, which is a test that reports 200 for a
     * 502 and passes a fix that did nothing.
     */
    private int $upstreamStatus = 200;

    private string $upstreamWindow = 'max-age=86400';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/api/graphql')) {
                return Http::response([
                    'data' => ['login' => ['accessToken' => 'token-1', 'refreshToken' => 'r']],
                ]);
            }

            $upstream = $this->upstreamStatus;

            if ($upstream !== 200) {
                return Http::response('upstream is down', $upstream);
            }

            /*
             * A `?format=` answer is its own representation: the bytes carry the
             * transcode, because that is the shape the proxy's validator has to follow
             * to keep two formats from validating each other.
             */
            $body = str_contains($request->url(), '?format=') ? 'webp-bytes' : 'image-bytes';

            $headers = ['Content-Type' => 'image/webp'];

            if ($this->upstreamWindow !== '') {
                $headers['Cache-Control'] = $this->upstreamWindow;
            }

            return Http::response($body, 200, $headers);
        });
    }

    public function test_it_streams_a_chapter_page(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get('/suwayomi/api/v1/manga/12/chapter/0/page/3');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/webp');
        $this->assertSame('image-bytes', $response->streamedContent());
    }

    public function test_it_refuses_paths_outside_the_page_allowlist(): void
    {
        $user = User::factory()->create();

        // GraphQL and the admin surface must not be reachable from the browser.
        $this->actingAs($user)->get('/suwayomi/api/v1/manga/12/chapter/0/download')->assertNotFound();
        $this->actingAs($user)->get('/suwayomi/api/v1/graphql')->assertNotFound();
        $this->actingAs($user)->get('/suwayomi/api/v1/extension/list')->assertNotFound();
        $this->actingAs($user)->get('/suwayomi/api/v1/settings')->assertNotFound();
    }

    public function test_it_refuses_traversal_out_of_the_page_shape(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/suwayomi/api/v1/manga/12/chapter/0/page/../../../settings')
            ->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $this->get('/suwayomi/api/v1/manga/12/chapter/0/page/0')->assertRedirect(route('login'));
    }

    /**
     * Extension icons, which the source cards draw.
     *
     * `icon_url` and `iconUrl` were mirrored and fetched from the very first build and had
     * never been rendered, which is most of why the sources page read as fifty identical
     * lines. Drawing them needs this route, because Suwayomi answers the icon endpoint 401
     * without a bearer token and the browser cannot hold one.
     */
    public function test_it_serves_an_extension_icon(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get('/suwayomi/api/v1/extension/icon/eu.kanade.tachiyomi.extension.en.mangadex');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/webp');
        // An icon is now a whole buffered file with a validator (it is not a
        // chapter page, so it does not stream), and the body is read as bytes.
        $this->assertSame('image-bytes', $response->getContent());
    }

    public function test_an_extension_icon_carries_the_suwayomi_token_server_side(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/suwayomi/api/v1/extension/icon/eu.kanade.tachiyomi.extension.en.mangadex');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/extension/icon/')
            && $request->hasHeader('Authorization', 'Bearer token-1'));
    }

    public function test_an_extension_icon_still_needs_a_signed_in_reader(): void
    {
        $this->get('/suwayomi/api/v1/extension/icon/eu.kanade.tachiyomi.extension.en.mangadex')
            ->assertRedirect(route('login'));
    }

    /**
     * The set the widening must not have taken with it.
     *
     * `extension/list` is the sharp case: one character from `extension/icon`, and it
     * enumerates the whole catalogue, which is the admin surface the class docblock says
     * this route must never become a relay to.
     */
    public function test_the_icon_allowlist_did_not_widen_the_admin_surface(): void
    {
        $user = User::factory()->create();

        foreach ([
            '/suwayomi/api/v1/extension/list',
            '/suwayomi/api/v1/extension/install/eu.kanade.tachiyomi.extension.en.mangadex',
            '/suwayomi/api/v1/extension/icon',
            '/suwayomi/api/v1/extension/icon/../../settings',
            '/suwayomi/api/v1/extension/icons/eu.kanade.tachiyomi.extension.en.mangadex',
            '/suwayomi/api/v1/settings',
            '/suwayomi/api/v1/graphql',
        ] as $path) {
            $this->actingAs($user)->get($path)->assertNotFound($path);
        }
    }

    public function test_a_failed_icon_never_returns_the_application_layout(): void
    {
        $this->upstreamStatus = 500;

        $response = $this->actingAs(User::factory()->create())
            ->get('/suwayomi/api/v1/extension/icon/eu.kanade.tachiyomi.extension.en.mangadex');

        $this->assertSame(502, $response->getStatusCode());
        $this->assertStringNotContainsString('<html', (string) $response->getContent());
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
    }

    public function test_it_attaches_the_suwayomi_token_server_side(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/suwayomi/api/v1/manga/12/chapter/0/page/0');

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/api/v1/manga/12/chapter/0/page/0')
                && $request->hasHeader('Authorization', 'Bearer token-1');
        });
    }

    /**
     * A blocked manga source makes Suwayomi answer 500, and the app used to
     * hand that status to the browser as though it were its own fault.
     *
     * Measured, not asserted in advance: `verify-continue-reading.py` failed
     * three runs in a row on "no console errors", and the three 500s were all
     * `GET /suwayomi/api/v1/manga/{2,3,4,7}/chapter/N/page/K?format=webp`.
     * Papyrus had done its job in all three and something it depends on had not.
     */
    public function test_an_upstream_failure_is_reported_as_a_gateway_failure(): void
    {
        $this->upstreamStatus = 500;

        $this->actingAs(User::factory()->create())
            ->get('/suwayomi/api/v1/manga/7/chapter/2/page/0?format=webp')
            ->assertStatus(502);
    }

    /**
     * 404 is kept, because it is a different claim: Suwayomi has no such page.
     */
    public function test_a_missing_page_stays_a_404(): void
    {
        $this->upstreamStatus = 404;

        $this->actingAs(User::factory()->create())
            ->get('/suwayomi/api/v1/manga/12/chapter/0/page/99')
            ->assertStatus(404);
    }

    /**
     * The other half of the fault, and the reason a status-code test is not
     * enough on its own.
     *
     * This route answers `<img>`. It used to `abort()`, so a failed page image
     * came back as the full authenticated application layout, head and CSRF
     * token included, as the body of a resource the browser was about to treat
     * as a picture. The body is asserted here because the status was already
     * right in the 404 case above.
     */
    public function test_a_failure_never_returns_the_application_layout(): void
    {
        $this->upstreamStatus = 500;

        $user = User::factory()->create();

        foreach ([
            '/suwayomi/api/v1/manga/7/chapter/2/page/0',
            '/suwayomi/api/v1/manga/12/chapter/0/download',
        ] as $path) {
            $response = $this->actingAs($user)->get($path);

            $this->assertStringNotContainsString('<html', $response->getContent(), $path);
            $this->assertStringNotContainsString('csrf-token', $response->getContent(), $path);
            $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'), $path);
        }
    }

    /**
     * A failed page image must not be cached, or a source that recovers keeps
     * rendering the broken image and the reader's reload does nothing.
     */
    public function test_a_failure_is_not_cacheable(): void
    {
        $this->upstreamStatus = 500;

        $response = $this->actingAs(User::factory()->create())
            ->get('/suwayomi/api/v1/manga/7/chapter/2/page/0');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    // The app owned caching ------------------------------------------------

    /**
     * Covers and icons are the buffered branch: the body is small and whole, so the
     * app mints the validator upstream sends none of.
     */
    public function test_a_cover_serves_a_validator_of_its_own_bytes(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get('/suwayomi/api/v1/manga/12/thumbnail');

        $response->assertOk();
        $this->assertSame('"'.hash('sha256', 'image-bytes').'"', $response->headers->get('ETag'));
        $this->assertSame((string) strlen('image-bytes'), $response->headers->get('Content-Length'));
        $this->assertSame('image-bytes', $response->getContent());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /**
     * A lapsed window asks with the minted validator and waits for no bytes: that is
     * the whole point of minting one, and the window riding on the 304 is what keeps
     * the validated cover from lapsing straight into a refetch.
     */
    public function test_a_validating_cover_is_answered_without_the_bytes(): void
    {
        $etag = '"'.hash('sha256', 'image-bytes').'"';

        $response = $this->actingAs(User::factory()->create())
            ->withHeaders(['If-None-Match' => $etag])
            ->get('/suwayomi/api/v1/manga/12/thumbnail');

        $response->assertStatus(304);
        $this->assertSame('', $response->getContent());
        $this->assertSame($etag, $response->headers->get('ETag'));

        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('max-age=86400', $cache, 'The validation renews the window.');
        $this->assertStringContainsString('private', $cache);
    }

    /**
     * The validator is derived from the representation's own bytes, which is the seam
     * that keeps two formats apart: a webp copy that validated a baseline copy would
     * hand a reader's `<img>` an image in a form it did not ask for.
     *
     * The fake answers `?format=` with different bytes for exactly this reason.
     */
    public function test_a_webp_copy_cannot_validate_a_baseline_cover(): void
    {
        $user = User::factory()->create();

        $baseline = $this->actingAs($user)->get('/suwayomi/api/v1/manga/12/thumbnail');
        $transcode = $this->actingAs($user)->get('/suwayomi/api/v1/manga/12/thumbnail?format=webp');

        $baseline->assertOk();
        $transcode->assertOk();
        $this->assertSame('webp-bytes', $transcode->getContent());

        $this->assertNotSame(
            $baseline->headers->get('ETag'),
            $transcode->headers->get('ETag'),
            'Two representations that differ in bytes differ in the minted validator.',
        );

        // A browser holding the transcode asks with the validator minted for it. The
        // URL it lands on without the query names the baseline representation, so the
        // answer is bytes, not a validated 304.
        $cross = $this->actingAs($user)
            ->withHeaders(['If-None-Match' => $transcode->headers->get('ETag')])
            ->get('/suwayomi/api/v1/manga/12/thumbnail');

        $cross->assertStatus(200);
        $this->assertSame('image-bytes', $cross->getContent());
    }

    public function test_a_chapter_page_window_is_private_and_immutable(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get('/suwayomi/api/v1/manga/12/chapter/0/page/3');

        $cache = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('max-age=86400', $cache);
        $this->assertStringContainsString('immutable', $cache, 'The URL carries chapter and page indexes that re-read the same image.');
    }

    /**
     * The private mark is the app's own statement: the measured upstream sends a bare
     * shared-cache window, and wherever it answered at all, the answer the reader gets
     * is still private.
     */
    public function test_a_cover_window_is_private_and_renewable_over_upstreams(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get('/suwayomi/api/v1/manga/12/thumbnail');

        $cache = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('max-age=86400', $cache);
        $this->assertStringContainsString(
            'stale-while-revalidate=604800',
            $cache,
            'A lapsed cover renews in the background rather than blanking its card.',
        );
    }

    /**
     * The fallback window is the same length, so the day upstream stops sending a
     * window changes nothing a reader can see.
     */
    public function test_the_app_own_window_stands_in_when_upstream_sends_none(): void
    {
        $this->upstreamWindow = '';

        $response = $this->actingAs(User::factory()->create())
            ->get('/suwayomi/api/v1/manga/12/thumbnail');

        $response->assertOk();

        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('max-age=86400', $cache);
        $this->assertStringContainsString('stale-while-revalidate=604800', $cache);
    }

    /**
     * The one visibility case the header bag alone cannot own: upstream declaring its
     * answer for every cache in the world. The bytes are one reader's, so the
     * allegiance is replaced, never left contradicting.
     */
    public function test_an_upstream_that_claims_public_is_served_private(): void
    {
        $this->upstreamWindow = 'public, max-age=86400';

        $response = $this->actingAs(User::factory()->create())
            ->get('/suwayomi/api/v1/manga/12/thumbnail');

        $cache = (string) $response->headers->get('Cache-Control');

        $this->assertStringNotContainsString('public', $cache);
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('max-age=86400', $cache);
    }
}
