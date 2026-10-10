<?php

namespace Tests\Feature\Suwayomi;

use App\Suwayomi\AuthManager;
use App\Suwayomi\FailureKind;
use App\Suwayomi\SuwayomiException;
use App\Suwayomi\Transport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\MintsTokens;
use Tests\TestCase;

class AuthManagerTest extends TestCase
{
    use MintsTokens;

    private int $loginCalls = 0;

    private int $refreshCalls = 0;

    private int $issued = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginCalls = 0;
        $this->refreshCalls = 0;
        $this->issued = 0;
    }

    /**
     * Counts login and refresh mutations, issuing a distinct token each time so
     * a spurious re-authentication is visible rather than invisible.
     */
    private function fakeLogin(): void
    {
        Http::fake(function (Request $request) {
            $query = $request->data()['query'] ?? '';

            if (str_contains($query, 'mutation Login')) {
                $this->loginCalls++;
                $this->issued++;

                return Http::response([
                    'data' => ['login' => [
                        'accessToken' => $this->accessToken(),
                        'refreshToken' => $this->refreshToken(),
                    ]],
                ]);
            }

            if (str_contains($query, 'mutation Refresh')) {
                $this->refreshCalls++;
                $this->issued++;

                return Http::response([
                    'data' => ['refreshToken' => ['accessToken' => $this->accessToken()]],
                ]);
            }

            return Http::response(['data' => ['ok' => true]]);
        });
    }

    public function test_it_logs_in_when_there_is_no_cached_token(): void
    {
        $this->fakeLogin();

        $this->assertNotSame('', app(AuthManager::class)->token());
        $this->assertSame(1, $this->loginCalls);
        $this->assertSame(0, $this->refreshCalls);
    }

    public function test_it_reuses_an_in_memory_token_within_one_request(): void
    {
        $this->fakeLogin();

        $auth = app(AuthManager::class);
        $first = $auth->token();

        $this->assertSame($first, $auth->token());
        $this->assertSame($first, $auth->token());
        $this->assertSame(1, $this->loginCalls);
    }

    /**
     * The regression this file existed for.
     *
     * A fresh AuthManager with a warm cache is the *second web request* of a
     * session, which a single-shot test never exercises. An earlier version
     * assigned the access-token string to the pair on this path, so the second
     * request read back a string where it expected an array and blew up.
     */
    public function test_a_fresh_instance_reuses_a_warm_cache_without_re_logging_in(): void
    {
        $this->fakeLogin();

        $first = app(AuthManager::class);
        $token = $first->token();
        $this->assertSame(1, $this->loginCalls);

        // A new instance, as a second HTTP request would create.
        $second = new AuthManager(app(Transport::class));

        $this->assertSame($token, $second->token());
        $this->assertSame(1, $this->loginCalls, 'Must not log in again on a warm cache.');
        $this->assertSame(0, $this->refreshCalls, 'Must not refresh a token that still has life left.');

        $this->assertSame($token, $second->token());
        $this->assertSame(1, $this->loginCalls);
    }

    public function test_forget_drops_the_cache_so_the_next_call_re_authenticates(): void
    {
        $this->fakeLogin();

        $auth = app(AuthManager::class);
        $auth->token();

        $auth->forget();

        $auth->token();

        $this->assertSame(2, $this->loginCalls);
        $this->assertSame(0, $this->refreshCalls, 'Forget must log in, not refresh: the refresh token is suspect too.');
    }

    public function test_it_refuses_to_guess_when_credentials_are_missing(): void
    {
        config(['papyrus.suwayomi.username' => null, 'papyrus.suwayomi.password' => null]);

        $this->expectException(SuwayomiException::class);
        $this->expectExceptionMessageMatches('/SUWAYOMI_USERNAME/');

        app(AuthManager::class)->token();
    }

    /**
     * The access token lives 300 seconds and the refresh token 60 days, so a
     * long-lived process should spend almost all its time holding a usable
     * refresh token rather than re-sending credentials.
     */
    public function test_an_expired_access_token_is_renewed_with_the_refresh_token(): void
    {
        $this->fakeLogin();

        // Seed the cache the way a settled process would have it: access spent,
        // refresh still good for a month.
        cache()->put('suwayomi.tokens', [
            'accessToken' => $this->accessToken(-10),
            'refreshToken' => $this->refreshToken(),
            'accessExpiresAt' => time() - 10,
            'refreshExpiresAt' => time() + 50000,
        ], 3600);

        app(AuthManager::class)->token();

        $this->assertSame(1, $this->refreshCalls, 'Should refresh, not log in.');
        $this->assertSame(0, $this->loginCalls, 'Credentials should not be sent while a refresh token works.');
    }

    /**
     * The refresh token lives 60 days but it does die, and the server reports a
     * bad one as HTTP 200 with a GraphQL error, so this has to be recoverable.
     */
    public function test_it_falls_back_to_login_when_the_refresh_token_is_rejected(): void
    {
        Http::fake(function (Request $request) {
            $query = $request->data()['query'] ?? '';

            if (str_contains($query, 'mutation Refresh')) {
                $this->refreshCalls++;

                return Http::response([
                    'errors' => [[
                        'message' => 'Exception while fetching data (/refreshToken) : The token was expected to have 3 parts, but got 0.',
                    ]],
                ]);
            }

            if (str_contains($query, 'mutation Login')) {
                $this->loginCalls++;

                return Http::response([
                    'data' => ['login' => [
                        'accessToken' => $this->accessToken(),
                        'refreshToken' => $this->refreshToken(),
                    ]],
                ]);
            }

            return Http::response(['data' => ['ok' => true]]);
        });

        cache()->put('suwayomi.tokens', [
            'accessToken' => $this->accessToken(-10),
            'refreshToken' => $this->refreshToken(),
            'accessExpiresAt' => time() - 10,
            'refreshExpiresAt' => time() + 50000,
        ], 3600);

        app(AuthManager::class)->token();

        $this->assertSame(1, $this->refreshCalls);
        $this->assertSame(1, $this->loginCalls, 'A dead refresh token must end in a login, not an exception.');
    }

    public function test_an_expired_refresh_token_skips_refresh_entirely(): void
    {
        $this->fakeLogin();

        cache()->put('suwayomi.tokens', [
            'accessToken' => $this->accessToken(-10),
            'refreshToken' => $this->refreshToken(-10),
            'accessExpiresAt' => time() - 10,
            'refreshExpiresAt' => time() - 10,
        ], 3600);

        app(AuthManager::class)->token();

        $this->assertSame(0, $this->refreshCalls, 'No point refreshing with a dead token.');
        $this->assertSame(1, $this->loginCalls);
    }

    /**
     * The bug that started this: an access token with no readable expiry. The
     * old code trusted a configured TTL; the new code refuses, because handing
     * out a token whose age it cannot determine is how the dead-token problem
     * started in the first place.
     */
    public function test_it_refuses_a_token_whose_expiry_cannot_be_read(): void
    {
        $this->fakeLogin();

        cache()->put('suwayomi.tokens', [
            'accessToken' => 'not-a-jwt',
            'refreshToken' => null,
            'accessExpiresAt' => null,
            'refreshExpiresAt' => null,
        ], 3600);

        app(AuthManager::class)->token();

        $this->assertSame(1, $this->loginCalls, 'An unreadable token must be replaced, not trusted.');
    }

    /**
     * A token inside the renew margin counts as spent. Refreshing a token with
     * 29 seconds left would work but leaves no room for a slow round trip.
     */
    public function test_a_token_inside_the_renew_margin_is_treated_as_spent(): void
    {
        $this->fakeLogin();

        cache()->put('suwayomi.tokens', [
            'accessToken' => $this->accessToken(29),
            'refreshToken' => $this->refreshToken(),
            'accessExpiresAt' => time() + 29,
            'refreshExpiresAt' => time() + 50000,
        ], 3600);

        app(AuthManager::class)->token();

        $this->assertSame(1, $this->refreshCalls);
    }

    public function test_a_token_outside_the_renew_margin_is_kept(): void
    {
        $this->fakeLogin();

        cache()->put('suwayomi.tokens', [
            'accessToken' => $this->accessToken(31),
            'refreshToken' => $this->refreshToken(),
            'accessExpiresAt' => time() + 31,
            'refreshExpiresAt' => time() + 50000,
        ], 3600);

        app(AuthManager::class)->token();

        $this->assertSame(0, $this->refreshCalls);
        $this->assertSame(0, $this->loginCalls);
    }

    /**
     * Concurrent processes all miss the cache before any of them gets the lock.
     * Each must re-check inside it, or forty parallel sources cost forty
     * authentications every five minutes.
     */
    public function test_concurrent_callers_collapse_into_one_refresh(): void
    {
        $this->fakeLogin();

        cache()->put('suwayomi.tokens', [
            'accessToken' => $this->accessToken(-10),
            'refreshToken' => $this->refreshToken(),
            'accessExpiresAt' => time() - 10,
            'refreshExpiresAt' => time() + 50000,
        ], 3600);

        // Each instance reads the cache before it would take the lock, which is
        // exactly what a separate queue worker does.
        $managers = array_map(static fn (): AuthManager => new AuthManager(app(Transport::class)), range(1, 5));

        foreach ($managers as $manager) {
            $manager->token();
        }

        $this->assertSame(1, $this->refreshCalls, 'Five processes, one refresh.');
        $this->assertSame(0, $this->loginCalls);
    }

    public function test_a_lock_it_cannot_take_arrives_as_a_gateway_failure(): void
    {
        /*
         * The page must be able to cope, and the only way it copes is by catching the gateway's
         * own exception type.
         *
         * `block(5, ...)` raises `LockTimeoutException`, which is not a `SuwayomiException`, so
         * it escaped every handler in the app. Measured: a worker holding the lock while it
         * checked, and a reader loading the library, which produced a 500 with a lock timeout
         * in it rather than a library page with no covers.
         */
        $this->fakeLogin();

        // A lock held by someone else for far longer than `block(5)` is willing to wait.
        Cache::lock('suwayomi.tokens:lock', 60)->get(function () {
            try {
                app(AuthManager::class)->token();
            } catch (SuwayomiException $e) {
                $this->assertSame(FailureKind::Timeout, $e->kind);
                $this->assertStringContainsString('busy', $e->getMessage());
            }
        });
    }

    public function test_the_lock_timeout_is_not_swallowed(): void
    {
        $this->fakeLogin();

        $threw = false;

        Cache::lock('suwayomi.tokens:lock', 60)->get(function () use (&$threw) {
            try {
                app(AuthManager::class)->token();
            } catch (SuwayomiException) {
                $threw = true;
            }
        });

        $this->assertTrue(
            $threw,
            'silently returning nothing would give every page a blank library and no explanation',
        );
    }
}
