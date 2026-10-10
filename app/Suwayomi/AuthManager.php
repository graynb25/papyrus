<?php

namespace App\Suwayomi;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Auth against Suwayomi's UI_LOGIN mode.
 *
 * Measured token lifetimes, from a v2.3.2243 server:
 *
 *   access   300 seconds, claim `token_type: access`
 *   refresh  5,184,000 seconds (60 days), claim `token_type: refresh`
 *
 * The access token is short enough that treating it as long-lived is not a
 * matter of taste. An earlier version cached it for `token_ttl` seconds,
 * which was never measured and was set to 3000, so the app spent 45 minutes of
 * every 50 handing out a dead token. Suwayomi does not report that as HTTP
 * 401: the `@RequireAuth` directive raises UnauthorizedException inside the
 * resolver, so it arrives as HTTP 200 with the exception buried in a GraphQL
 * error. The status check in {@see Gateway} never fired and nothing re-logged
 * in until someone cleared the cache by hand.
 *
 * So two rules, and both are load bearing:
 *
 *   - never assume a lifetime, read `exp` from the token we were handed
 *   - renew with the refresh token, not with credentials
 *
 * A 60 day refresh token means an ordinary process almost never re-authenticates
 * at all. Both the renew and the login paths sit under one cache lock and
 * re-check the cache after acquiring it, so a fan-out across forty sources
 * costs one refresh rather than forty.
 *
 * SIMPLE_LOGIN is deliberately not supported: the server keeps those sessions
 * in memory with no session handler and a ~30m timeout, which is unusable
 * server to server.
 *
 * Talks to {@see Transport} rather than {@see Gateway}, since logging in is
 * precisely the case where no valid token exists yet.
 */
class AuthManager
{
    private const CACHE_KEY = 'suwayomi.tokens';

    private const LOCK_KEY = 'suwayomi.tokens:lock';

    /**
     * Renew once the access token has this little life left.
     *
     * Sized to the token rather than to a guess: 30 seconds is comfortably more
     * than one Suwayomi round trip, and small enough that we are not
     * re-authenticating on every call once the window tightens.
     */
    private const ACCESS_MARGIN = 30;

    /** @var array{accessToken: string, refreshToken: string|null, accessExpiresAt: int|null, refreshExpiresAt: int|null}|null */
    private ?array $tokens = null;

    public function __construct(
        private readonly Transport $transport,
    ) {}

    /**
     * A valid bearer token, renewing if the one we hold is spent.
     *
     * @throws SuwayomiException
     */
    public function token(): string
    {
        if ($this->tokens !== null && $this->accessUsable($this->tokens)) {
            return $this->tokens['accessToken'];
        }

        $cached = $this->readCached();

        if ($this->accessUsable($cached)) {
            return $this->adopt($cached)['accessToken'];
        }

        return $this->renew($cached)['accessToken'];
    }

    /**
     * Exchange credentials for a token pair.
     *
     * @return array{accessToken: string, refreshToken: string|null, accessExpiresAt: int|null, refreshExpiresAt: int|null}
     *
     * @throws SuwayomiException
     */
    public function login(): array
    {
        $username = config('papyrus.suwayomi.username');
        $password = config('papyrus.suwayomi.password');

        if (blank($username) || blank($password)) {
            throw SuwayomiException::unavailable(
                'SUWAYOMI_USERNAME and SUWAYOMI_PASSWORD are not set. Suwayomi must run with AuthMode.UI_LOGIN.'
            );
        }

        $response = $this->transport->post([
            'query' => <<<'GQL'
                mutation Login($input: LoginInput!) {
                    login(input: $input) {
                        accessToken
                        refreshToken
                    }
                }
                GQL,
            'variables' => (object) ['input' => ['username' => $username, 'password' => $password]],
        ]);

        return $this->store($this->readLoginResponse($response));
    }

    /**
     * Drop the cached pair so the next call re-authenticates from credentials.
     *
     * Used after the server rejects a token, where the reason is usually a
     * restart or a rotated signing secret. In that case the refresh token is
     * suspect too, so renewing with it would just fail once more.
     */
    public function forget(): void
    {
        $this->tokens = null;

        cache()->forget(self::CACHE_KEY);
    }

    public function isAuthenticated(): bool
    {
        try {
            $this->token();

            return true;
        } catch (SuwayomiException) {
            return false;
        }
    }

    /**
     * Whether the access token has life left, allowing for one slow round trip.
     *
     * @param  array{accessToken?: mixed, accessExpiresAt?: mixed}  $tokens
     */
    private function accessUsable(array $tokens): bool
    {
        $token = $tokens['accessToken'] ?? null;
        $expiresAt = $tokens['accessExpiresAt'] ?? null;

        if (! is_string($token) || $token === '') {
            return false;
        }

        // No readable expiry means we cannot claim the token works. Refusing to
        // guess is what stops a stale value from being handed out forever.
        if (! is_int($expiresAt)) {
            return false;
        }

        return time() < $expiresAt - self::ACCESS_MARGIN;
    }

    /**
     * @param  array{accessToken?: mixed, refreshToken?: mixed, accessExpiresAt?: mixed, refreshExpiresAt?: mixed}|null  $stale
     * @return array{accessToken: string, refreshToken: string|null, accessExpiresAt: int|null, refreshExpiresAt: int|null}
     *
     * @throws SuwayomiException
     */
    private function renew(?array $stale): array
    {
        /*
         * The lock, and what happens when it cannot be taken.
         *
         * `block()` raises `LockTimeoutException` after five seconds. Left alone it is not a
         * `SuwayomiException`, so it escaped every `catch (SuwayomiException)` in the app and
         * surfaced as a 500: measured, a worker holding the lock while it checked series, and a
         * reader loading the library at the same time, which is the normal state now that a
         * worker runs at all.
         *
         * So it is translated here rather than in the callers. From anything above the gateway,
         * "could not get a token" is the same fact as "the server is not reachable right now",
         * and the gateway's contract is to throw `SuwayomiException` for that. One place, and
         * the handling that already exists starts working: the library renders without source
         * data instead of erroring, which is what `Library::fetchFromSource()` does for every
         * other transport failure.
         *
         * Not retried, because a request that could not take the lock in five seconds is a
         * server that is saturated, and adding another five seconds of waiting to a page request
         * makes that worse rather than better.
         */
        try {
            return $this->underLock($stale);
        } catch (LockTimeoutException $e) {
            throw new SuwayomiException(
                'This server is busy and did not hand out a Suwayomi token in time. Try again in a moment.',
                // `Timeout`, and not `Unavailable`: this is literally our own wait expiring, not
                // the server being down. Anything that branches on the kind reads it correctly.
                FailureKind::Timeout,
                previous: $e,
            );
        }
    }

    /**
     * The refresh itself, under the lock that stops two processes refreshing at once.
     *
     * @param  array{accessToken?: mixed, refreshToken?: mixed, accessExpiresAt?: mixed, refreshExpiresAt?: mixed}|null  $stale
     * @return array{accessToken: string, refreshToken: string|null, accessExpiresAt: int|null, refreshExpiresAt: int|null}
     */
    private function underLock(?array $stale): array
    {
        return cache()->lock(self::LOCK_KEY, 10)->block(5, function () use ($stale): array {
            // Re-check under the lock. Without this every process that queued
            // on the lock would issue its own refresh, because each one read
            // the cache before the lock and so all of them saw a spent token.
            $cached = $this->readCached();

            if ($this->accessUsable($cached)) {
                return $this->adopt($cached);
            }

            $refresh = $this->usableRefreshToken($stale, $cached);

            if ($refresh !== null) {
                try {
                    return $this->refresh($refresh);
                } catch (SuwayomiException $e) {
                    // A dead or rotated refresh token is ordinary over a
                    // 60 day life, so fall back to credentials rather than
                    // surfacing it. The reason is logged because a store that
                    // silently re-authenticates forever is its own kind of bug.
                    Log::info('Suwayomi refresh token rejected, logging in instead.', [
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            return $this->login();
        });
    }

    /**
     * Trade the refresh token for a new access token.
     *
     * Measured: the response carries `accessToken` only. The refresh token is
     * not rotated and stays valid, which is why it is kept in the cache rather
     * than discarded.
     *
     * @return array{accessToken: string, refreshToken: string|null, accessExpiresAt: int|null, refreshExpiresAt: int|null}
     *
     * @throws SuwayomiException
     */
    private function refresh(string $refreshToken): array
    {
        $response = $this->transport->post([
            'query' => <<<'GQL'
                mutation Refresh($refreshToken: String!) {
                    refreshToken(input: { refreshToken: $refreshToken }) {
                        accessToken
                    }
                }
                GQL,
            'variables' => (object) ['refreshToken' => $refreshToken],
        ]);

        $body = $response->json();

        if (! is_array($body)) {
            throw SuwayomiException::unavailable('the server did not return JSON while refreshing a token');
        }

        if (! empty($body['errors'])) {
            throw SuwayomiException::query($body['errors'], 0.0);
        }

        $accessToken = $body['data']['refreshToken']['accessToken'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw SuwayomiException::unauthenticated();
        }

        return $this->store([
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
            'accessExpiresAt' => Jwt::expiry($accessToken),
            'refreshExpiresAt' => Jwt::expiry($refreshToken),
        ]);
    }

    /**
     * Re-shape a cached pair rather than trusting its stored form.
     *
     * A value written by an older version must not be able to poison this one,
     * and the shape has changed as the expiry came from the claim rather than
     * from config.
     *
     * @return array{accessToken: string, refreshToken: string|null, accessExpiresAt: int|null, refreshExpiresAt: int|null}
     */
    private function readCached(): array
    {
        $raw = cache()->get(self::CACHE_KEY);

        if (! is_array($raw)) {
            return ['accessToken' => '', 'refreshToken' => null, 'accessExpiresAt' => null, 'refreshExpiresAt' => null];
        }

        return [
            'accessToken' => is_string($raw['accessToken'] ?? null) ? $raw['accessToken'] : '',
            'refreshToken' => is_string($raw['refreshToken'] ?? null) ? $raw['refreshToken'] : null,
            'accessExpiresAt' => is_int($raw['accessExpiresAt'] ?? null) ? $raw['accessExpiresAt'] : null,
            'refreshExpiresAt' => is_int($raw['refreshExpiresAt'] ?? null) ? $raw['refreshExpiresAt'] : null,
        ];
    }

    /**
     * The refresh token to try, if it has life left.
     *
     * Prefers the copy read inside the lock, falling back to what the caller
     * saw before it waited, so a token written by a process that has since
     * exited is still usable. Each candidate is checked against its own expiry
     * rather than against one shared value, since the two copies may have been
     * written by different versions of this class.
     *
     * @param  array<string, mixed>|null  $stale
     * @param  array<string, mixed>  $cached
     */
    private function usableRefreshToken(?array $stale, array $cached): ?string
    {
        $candidates = [
            [$cached['refreshToken'] ?? null, $cached['refreshExpiresAt'] ?? null],
            [$stale['refreshToken'] ?? null, $stale['refreshExpiresAt'] ?? null],
        ];

        foreach ($candidates as [$token, $expiresAt]) {
            if (! is_string($token) || $token === '') {
                continue;
            }

            // An unreadable expiry is treated as usable, because the server
            // rejects a bad token loudly and the alternative is refusing to
            // renew with a perfectly good one.
            if (! is_int($expiresAt) || time() < $expiresAt) {
                return $token;
            }
        }

        return null;
    }

    /**
     * @param  array{accessToken: string, refreshToken: string|null, accessExpiresAt: int|null, refreshExpiresAt: int|null}  $tokens
     * @return array{accessToken: string, refreshToken: string|null, accessExpiresAt: int|null, refreshExpiresAt: int|null}
     */
    private function adopt(array $tokens): array
    {
        return $this->tokens = $tokens;
    }

    /**
     * Cache the pair and memoise it.
     *
     * The cache lifetime is bounded by the refresh token's own life, since once
     * that expires nothing in the pair is usable and the next call has to log
     * in. Falling back to `token_ttl` covers a server that mints a token we
     * cannot decode, without keeping a pair around that we have no way to age
     * out.
     *
     * @param  array{accessToken: string, refreshToken: string|null, accessExpiresAt: int|null, refreshExpiresAt: int|null}  $tokens
     * @return array{accessToken: string, refreshToken: string|null, accessExpiresAt: int|null, refreshExpiresAt: int|null}
     */
    private function store(array $tokens): array
    {
        $lifetime = $tokens['refreshExpiresAt'] !== null
            ? max(60, $tokens['refreshExpiresAt'] - time())
            : max(60, (int) config('papyrus.suwayomi.token_ttl', 300));

        cache()->put(self::CACHE_KEY, $tokens, $lifetime);

        return $this->adopt($tokens);
    }

    /**
     * @return array{accessToken: string, refreshToken: string|null, accessExpiresAt: int|null, refreshExpiresAt: int|null}
     *
     * @throws SuwayomiException
     */
    private function readLoginResponse(Response $response): array
    {
        if ($response->status() === 401) {
            throw SuwayomiException::unauthenticated();
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw SuwayomiException::unavailable('the server did not return JSON');
        }

        if (! empty($body['errors'])) {
            throw SuwayomiException::query($body['errors'], 0.0);
        }

        $tokens = $body['data']['login'] ?? null;

        if (! is_array($tokens) || blank($tokens['accessToken'] ?? null)) {
            throw new SuwayomiException(
                'Suwayomi accepted the request but returned no access token. Confirm AuthMode is set to UI_LOGIN.',
                FailureKind::Unauthenticated,
            );
        }

        $accessToken = (string) $tokens['accessToken'];
        $refreshToken = is_string($tokens['refreshToken'] ?? null) ? $tokens['refreshToken'] : null;

        return [
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
            'accessExpiresAt' => Jwt::expiry($accessToken),
            'refreshExpiresAt' => $refreshToken !== null ? Jwt::expiry($refreshToken) : null,
        ];
    }
}
