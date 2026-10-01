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

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*/api/graphql' => Http::response([
                'data' => ['login' => ['accessToken' => 'token-1', 'refreshToken' => 'r']],
            ]),
            '*/api/v1/*' => Http::response('image-bytes', 200, [
                'Content-Type' => 'image/webp',
                'Cache-Control' => 'max-age=86400',
            ]),
        ]);
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
        $this->get('/suwayomi/api/v1/manga/12/chapter/0/page/0')->assertRedirect();
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
}
