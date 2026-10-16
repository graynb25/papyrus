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

    /**
     * The fresh install shape: `.env.example` ships both values blank and the
     * bundled server answers anonymously, so there is nothing to log in with
     * and nothing to log in for. The token is null and no request leaves the
     * machine. Only `/sources` ever noticed, as a 500.
     */
    public function test_blank_credentials_and_nothing_to_renew_mean_no_token_and_no_request(): void
    {
        config(['papyrus.suwayomi.username' => null, 'papyrus.suwayomi.password' => null]);

        $requests = 0;

        Http::fake(function () use (&$requests) {
            $requests++;

            return Http::response(['data' => ['ok' => true]]);
        });

        $this->assertNull(app(AuthManager::class)->token());
        $this->assertSame(0, $requests, 'Nothing may leave the machine when there is nothing to authenticate with.');
    }

    /**
     * The anonymous answer is decided before the lock is ever asked for. If it
     * were decided inside, this test would wait out the five second lock
     * timeout and then throw, serialising every anonymous request behind one
     * process that is authenticating.
     */
    public function test_the_anonymous_answer_does_not_wait_on_the_token_lock(): void
    {
        config(['papyrus.suwayomi.username' => null, 'papyrus.suwayomi.password' => null]);

        $token = Cache::lock('suwayomi.tokens:lock', 60)->get(
            static fn () => app(AuthManager::class)->token()
        );

        $this->assertNull($token);
    }

    /**
     * Blank credentials only mean anonymous when there is nothing to renew.
     * A pair cached while the server still accepted logins must keep working,
     * because the refresh token is the only thing that can authenticate then.
     */
    public function test_blank_credentials_still_renew_with_a_live_refresh_token(): void
    {
        config(['papyrus.suwayomi.username' => null, 'papyrus.suwayomi.password' => null]);

        $this->fakeLogin();

        cache()->put('suwayomi.tokens', [
            'accessToken' => $this->accessToken(-10),
            'refreshToken' => $this->refreshToken(),
            'accessExpiresAt' => time() - 10,
            'refreshExpiresAt' => time() + 50000,
        ], 3600);

        $token = app(AuthManager::class)->token();

        $this->assertNotNull($token);
        $this->assertSame(1, $this->refreshCalls);
        $this->assertSame(0, $this->loginCalls, 'Blank credentials must not cost a login while the refresh token works.');
    }

    /**
     * A dead refresh token with no credentials ends anonymous, and the dead
     * pair is dropped: keeping it would pay the same failed refresh before
     * every single request.
     */
    public function test_a_rejected_refresh_token_with_blank_credentials_goes_anonymous_and_is_dropped(): void
    {
        config(['papyrus.suwayomi.username' => null, 'papyrus.suwayomi.password' => null]);

        $requests = 0;

        Http::fake(function (Request $request) use (&$requests) {
            $requests++;

            $query = $request->data()['query'] ?? '';

            if (str_contains($query, 'mutation Refresh')) {
                $this->refreshCalls++;

                return Http::response(['errors' => [[
                    'message' => 'The token was expected to have 3 parts, but got 0.',
                ]]]);
            }

            $this->loginCalls++;

            return Http::response(['data' => ['login' => [
                'accessToken' => $this->accessToken(),
                'refreshToken' => $this->refreshToken(),
            ]]]);
        });

        cache()->put('suwayomi.tokens', [
            'accessToken' => $this->accessToken(-10),
            'refreshToken' => $this->refreshToken(),
            'accessExpiresAt' => time() - 10,
            'refreshExpiresAt' => time() + 50000,
        ], 3600);

        $auth = app(AuthManager::class);

        $this->assertNull($auth->token());
        $this->assertSame(1, $this->refreshCalls);
        $this->assertSame(0, $this->loginCalls, 'Blank credentials must not even attempt a login.');

        $this->assertNull($auth->token());
        $this->assertSame(1, $requests, 'A dead pair must not be retried on every request.');
    }

    /**
     * The guard moved to credentials-only territory, but it stays: reaching
     * `login()` with a blank pair is still a mistake worth a clear message,
     * not an anonymous request.
     */
    public function test_login_itself_still_refuses_blank_credentials(): void
    {
        config(['papyrus.suwayomi.username' => null, 'papyrus.suwayomi.password' => null]);

        $this->expectException(SuwayomiException::class);
        $this->expectExceptionMessageMatches('/SUWAYOMI_USERNAME/');

        app(AuthManager::class)->login();
    }

    /**
     * The three streaming GETs build their own requests rather than going
     * through the gateway, so the null branch lives in `attachToken()` once.
     */
    public function test_attach_token_omits_the_header_when_there_is_no_token(): void
    {
        config(['papyrus.suwayomi.username' => null, 'papyrus.suwayomi.password' => null]);

        $authorized = null;

        Http::fake(function (Request $request) use (&$authorized) {
            $authorized = $request->hasHeader('Authorization');

            return Http::response('ok');
        });

        app(AuthManager::class)
            ->attachToken(Http::withOptions(['stream' => true]))
            ->get('https://suwayomi.example/api/v1/manga/1/thumbnail');

        $this->assertFalse($authorized);
    }

    public function test_attach_token_adds_the_header_when_there_is_a_token(): void
    {
        $access = $this->accessToken();

        cache()->put('suwayomi.tokens', [
            'accessToken' => $access,
            'refreshToken' => $this->refreshToken(),
            'accessExpiresAt' => time() + 300,
            'refreshExpiresAt' => time() + 50000,
        ], 3600);

        $authorized = null;

        Http::fake(function (Request $request) use (&$authorized) {
            $authorized = $request->header('Authorization')[0] ?? null;

            return Http::response('ok');
        });

        app(AuthManager::class)
            ->attachToken(Http::withOptions(['stream' => true]))
            ->get('https://suwayomi.example/api/v1/manga/1/thumbnail');

        $this->assertSame('Bearer '.$access, $authorized);
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

        /*
         * 300 seconds, not 31. `AuthManager::ACCESS_MARGIN` is 30 and `isFresh()` is
         * `time() < $expiresAt - 30`, so an expiry of `time() + 31` is outside the margin by
         * exactly one second. Every second of work between capturing that time and asking for
         * the token flips the branch, so this test fails on a loaded machine and passes on an
         * idle one, which is the worst way for a test to behave.
         *
         * The margin is still pinned to the second from the other side: the test above uses
         * `time() + 29`, which is inside it and stays inside for the whole run. Pinning the
         * outside case to the second as well would need a clock this code does not use, since
         * `AuthManager` reads `time()` directly and Laravel's `travelTo` cannot reach it.
         */
        cache()->put('suwayomi.tokens', [
            'accessToken' => $this->accessToken(300),
            'refreshToken' => $this->refreshToken(),
            'accessExpiresAt' => time() + 300,
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
