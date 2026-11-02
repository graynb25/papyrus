<?php

namespace Tests\Feature\Suwayomi;

use App\Suwayomi\FailureKind;
use App\Suwayomi\Gateway;
use App\Suwayomi\SuwayomiException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\MintsTokens;
use Tests\TestCase;

class GatewayTest extends TestCase
{
    use MintsTokens;

    /**
     * Answers the login mutation with a token, and delegates everything else to
     * the caller. Mirrors how AuthManager bootstraps itself before any real
     * query runs.
     */
    private function fakeLogin(string $token = 'token-1'): void
    {
        Http::fake(function (Request $request) use ($token) {
            $body = $request->data();
            $query = $body['query'] ?? '';

            if (str_contains($query, 'mutation Login')) {
                return Http::response([
                    'data' => ['login' => ['accessToken' => $token, 'refreshToken' => 'refresh-1']],
                ]);
            }

            return null;
        });
    }

    public function test_it_returns_the_data_payload(): void
    {
        $this->fakeLogin();

        Http::fake([
            '*/api/graphql' => Http::response(['data' => ['aboutServer' => ['version' => '2.3.2243']]]),
        ]);

        $data = app(Gateway::class)->execute('query { aboutServer { version } }');

        $this->assertSame('2.3.2243', $data['aboutServer']['version']);
    }

    public function test_it_turns_graphql_errors_into_a_typed_exception(): void
    {
        $this->fakeLogin();

        Http::fake([
            '*/api/graphql' => Http::response([
                'errors' => [
                    ['message' => "Source not installed\n\tat eu.kanade.Foo.bar(Foo.kt:42)", 'path' => ['fetchSourceManga']],
                ],
            ]),
        ]);

        try {
            app(Gateway::class)->execute('query { sources { nodes { id } } }');
            $this->fail('Expected a SuwayomiException.');
        } catch (SuwayomiException $e) {
            $this->assertSame(FailureKind::Query, $e->kind);
            $this->assertStringNotContainsString("\n", $e->getMessage());
            $this->assertNotNull($e->errors);
        }
    }

    /**
     * The log record names the operation and a bounded message, not the upstream payload.
     */
    public function test_a_graphql_error_is_logged_as_a_curated_record(): void
    {
        $this->fakeLogin();

        $tail = 'tail-marker-that-must-not-be-logged';
        $message = str_repeat('upstream detail ', 40).$tail;

        Http::fake([
            '*/api/graphql' => Http::response([
                'errors' => [
                    ['message' => $message, 'path' => ['manga']],
                ],
            ]),
        ]);

        Log::spy();

        try {
            app(Gateway::class)->execute('query Series($id: Int!) { manga(id: $id) { id } }');
            $this->fail('Expected a SuwayomiException.');
        } catch (SuwayomiException $e) {
            $this->assertSame(FailureKind::Query, $e->kind);
        }

        Log::shouldHaveReceived('debug')
            ->withArgs(function (string $record, array $context) use ($tail): bool {
                $this->assertSame('Suwayomi GraphQL error', $record);
                $this->assertSame('Series', $context['operation'] ?? null);
                $this->assertArrayHasKey('elapsed_ms', $context);
                $this->assertArrayNotHasKey('errors', $context, 'the raw upstream array must not be logged');
                $this->assertIsString($context['message'] ?? null);
                $this->assertLessThanOrEqual(203, mb_strlen($context['message']));
                $this->assertStringNotContainsString($tail, $context['message']);

                return true;
            });
    }

    public function test_it_reauthenticates_once_on_a_401_then_retries(): void
    {
        $this->fakeLogin('token-2');

        $attempts = 0;

        Http::fake(function (Request $request) use (&$attempts) {
            $body = $request->data();
            $query = $body['query'] ?? '';

            if (str_contains($query, 'mutation Login')) {
                return Http::response([
                    'data' => ['login' => ['accessToken' => 'token-'.(++$attempts), 'refreshToken' => 'r']],
                ]);
            }

            // First authenticated call is rejected; the retry succeeds.
            if ($attempts === 1) {
                return Http::response(['errors' => []], 401);
            }

            return Http::response(['data' => ['ok' => true]]);
        });

        $data = app(Gateway::class)->execute('query { ok }');

        $this->assertTrue($data['ok']);
    }

    public function test_it_gives_up_after_a_second_401(): void
    {
        $this->fakeLogin();

        Http::fake([
            '*/api/graphql' => Http::response(['errors' => []], 401),
        ]);

        $this->expectException(SuwayomiException::class);

        try {
            app(Gateway::class)->execute('query { ok }');
        } catch (SuwayomiException $e) {
            $this->assertTrue($e->isUnauthenticated());

            throw $e;
        }
    }

    /**
     * The regression that mattered most.
     *
     * An expired access token on a @RequireAuth field comes back as HTTP 200
     * with the UnauthorizedException buried in a GraphQL error, because
     * graphql-java catches the throw inside the resolver and reports it as a
     * field error. The old retry only checked the status code, so every
     * expiry after the first few minutes of a process's life surfaced to the
     * user as a query failure and nothing ever re-authenticated.
     */
    public function test_it_reauthenticates_on_an_auth_error_inside_a_200(): void
    {
        $this->fakeLogin();

        $logins = 0;
        $queries = 0;

        Http::fake(function (Request $request) use (&$logins, &$queries) {
            $query = $request->data()['query'] ?? '';

            if (str_contains($query, 'mutation Login')) {
                $logins++;

                return Http::response([
                    'data' => ['login' => [
                        'accessToken' => $this->accessToken(),
                        'refreshToken' => $this->refreshToken(),
                    ]],
                ]);
            }

            // The first query carries a token the server will not accept.
            if (++$queries === 1) {
                return Http::response($this->expiredTokenBody('extensions'));
            }

            return Http::response(['data' => ['extensions' => ['totalCount' => 1379]]]);
        });

        $data = app(Gateway::class)->execute('query { extensions { totalCount } }');

        $this->assertSame(1379, $data['extensions']['totalCount']);
        $this->assertSame(2, $logins, 'One fresh login after the rejection.');
        $this->assertSame(2, $queries, 'The document must be resent, not abandoned.');
    }

    /**
     * A server that refuses every token is a credentials problem, and saying so
     * is more use to whoever is holding the pager than a stack trace.
     */
    public function test_it_reports_persistent_auth_failure_as_unauthenticated(): void
    {
        $this->fakeLogin();

        Http::fake([
            '*/api/graphql' => Http::response($this->expiredTokenBody('extensions')),
        ]);

        try {
            app(Gateway::class)->execute('query { extensions { totalCount } }');
            $this->fail('Expected a SuwayomiException.');
        } catch (SuwayomiException $e) {
            $this->assertTrue($e->isUnauthenticated());
            $this->assertStringNotContainsString('UserTypeKt', $e->getMessage(), 'The trace should not leak into the message.');
        }
    }

    /**
     * The fresh-install shape: no credentials, no cached token, and a server
     * that answers anonymously. The query must go out with no Authorization
     * header rather than failing before a request leaves the machine.
     */
    public function test_it_queries_anonymously_when_no_credentials_are_configured(): void
    {
        config(['papyrus.suwayomi.username' => null, 'papyrus.suwayomi.password' => null]);

        $requests = 0;
        $authorized = null;

        Http::fake(function (Request $request) use (&$requests, &$authorized) {
            $requests++;
            $authorized = $request->hasHeader('Authorization');

            return Http::response(['data' => ['aboutServer' => ['version' => '2.3.2243']]]);
        });

        $data = app(Gateway::class)->execute('query { aboutServer { version } }');

        $this->assertSame('2.3.2243', $data['aboutServer']['version']);
        $this->assertFalse($authorized, 'A request with no token must not carry an Authorization header.');
        $this->assertSame(1, $requests);
    }

    /**
     * A server that requires auth still answers an anonymous request, it just
     * answers with an auth error. That is a credentials problem, not an
     * unreachable server, and the one re-authentication attempt still runs
     * before the failure is reported as unauthenticated.
     */
    public function test_a_server_that_refuses_anonymous_requests_reports_unauthenticated(): void
    {
        config(['papyrus.suwayomi.username' => null, 'papyrus.suwayomi.password' => null]);

        $requests = 0;

        Http::fake(function () use (&$requests) {
            $requests++;

            return Http::response($this->expiredTokenBody('extensions'));
        });

        try {
            app(Gateway::class)->execute('query { extensions { totalCount } }');
            $this->fail('Expected a SuwayomiException.');
        } catch (SuwayomiException $e) {
            $this->assertTrue($e->isUnauthenticated());
        }

        $this->assertSame(2, $requests, 'The one re-authentication retry still happens, then the failure is reported.');
    }

    /**
     * The matcher keys on the Kotlin class, so an ordinary source failure that
     * merely mentions the word must not trigger a re-login.
     */
    public function test_an_unrelated_error_naming_unauthorized_is_not_treated_as_an_auth_failure(): void
    {
        $this->fakeLogin();

        $logins = 0;

        Http::fake(function (Request $request) use (&$logins) {
            $query = $request->data()['query'] ?? '';

            if (str_contains($query, 'mutation Login')) {
                $logins++;

                return Http::response([
                    'data' => ['login' => ['accessToken' => $this->accessToken(), 'refreshToken' => $this->refreshToken()]],
                ]);
            }

            return Http::response([
                'errors' => [[
                    'message' => "Source is unauthorized to view this content (site returned 401)\n\tat eu.kanade.Foo.bar(Foo.kt:42)",
                    'path' => ['fetchSourceManga'],
                ]],
            ]);
        });

        try {
            app(Gateway::class)->execute('query { fetchSourceManga { id } }');
            $this->fail('Expected a SuwayomiException.');
        } catch (SuwayomiException $e) {
            $this->assertSame(FailureKind::Query, $e->kind);
        }

        $this->assertSame(1, $logins, 'A source-level 401 must not cost us a re-authentication.');
    }

    public function test_it_maps_server_errors_to_unavailable(): void
    {
        $this->fakeLogin();

        Http::fake([
            '*/api/graphql' => Http::response('boom', 500),
        ]);

        try {
            app(Gateway::class)->execute('query { ok }');
            $this->fail('Expected a SuwayomiException.');
        } catch (SuwayomiException $e) {
            $this->assertSame(FailureKind::Unavailable, $e->kind);
        }
    }

    public function test_it_flags_a_non_json_body_as_unavailable(): void
    {
        $this->fakeLogin();

        Http::fake([
            '*/api/graphql' => Http::response('<html>not graphql</html>', 200),
        ]);

        try {
            app(Gateway::class)->execute('query { ok }');
            $this->fail('Expected a SuwayomiException.');
        } catch (SuwayomiException $e) {
            $this->assertSame(FailureKind::Unavailable, $e->kind);
            $this->assertStringContainsString('non-JSON', $e->getMessage());
        }
    }

    /**
     * C9: fetchChapterPages returns root-relative /api/v1 paths that ignore any
     * configured subpath. They must be rebuilt against the prefix nginx serves.
     */
    public function test_it_rewrites_page_urls_onto_the_public_prefix(): void
    {
        $urls = app(Gateway::class)->resolvePageUrls([
            '/api/v1/manga/12/chapter/0/page/0',
            '/api/v1/manga/12/chapter/0/page/1',
        ], 'webp');

        $this->assertSame([
            '/suwayomi/api/v1/manga/12/chapter/0/page/0?format=webp',
            '/suwayomi/api/v1/manga/12/chapter/0/page/1?format=webp',
        ], $urls);
    }

    public function test_it_strips_an_absolute_origin_from_page_urls(): void
    {
        $urls = app(Gateway::class)->resolvePageUrls([
            'http://127.0.0.1:4567/api/v1/manga/12/chapter/0/page/0',
        ]);

        $this->assertSame(['/suwayomi/api/v1/manga/12/chapter/0/page/0'], $urls);
    }
}
