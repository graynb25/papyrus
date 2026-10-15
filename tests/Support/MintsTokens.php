<?php

namespace Tests\Support;

/**
 * Mints tokens shaped like the ones Suwayomi actually issues.
 *
 * These are not signed JWTs and do not need to be. Nothing verifies the
 * signature on our side: the server signed it and we gain nothing by checking.
 * What matters is that the payload carries an `exp` claim, because
 * AuthManager reads the expiry from the token it was handed rather than
 * assuming a lifetime.
 *
 * That is also why a plain "token-1" string will not do here. It has no
 * readable expiry, so AuthManager correctly refuses to hand it out, and a test
 * using one would quietly exercise the wrong path instead of the real one.
 *
 * The default lifetimes are measured from a v2.3.2243 server.
 *
 * Every method here takes the issuing time rather than reading the clock, so a test that compares a
 * token's `exp` against a number of its own cannot be off by one because the second ticked between
 * the mint and the assertion. That is not hypothetical: `JwtTest` did exactly this and would fail on
 * any run where the two calls straddled a boundary. `RunRecord::view()` has had an injectable clock
 * in its first argument the whole time for the same reason.
 */
trait MintsTokens
{
    /** Measured: the access token carries exp = issued + 300. */
    protected const ACCESS_LIFETIME = 300;

    /** Measured: the refresh token carries exp = issued + 5,184,000 (60 days). */
    protected const REFRESH_LIFETIME = 5_184_000;

    protected function accessToken(int $lifetime = self::ACCESS_LIFETIME, ?int $now = null): string
    {
        return $this->jwt(['token_type' => 'access'], $lifetime, $now);
    }

    protected function refreshToken(int $lifetime = self::REFRESH_LIFETIME, ?int $now = null): string
    {
        return $this->jwt(['token_type' => 'refresh'], $lifetime, $now);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function jwt(array $claims = [], ?int $lifetime = 60, ?int $now = null): string
    {
        $segment = static fn (array $value): string => rtrim(
            strtr(base64_encode((string) json_encode($value)), '+/', '-_'),
            '='
        );

        $payload = array_merge(
            ['iss' => 'suwayomi-server', 'aud' => 'suwayomi-server-api'],
            $claims,
            ['exp' => ($now ?? time()) + (int) $lifetime],
        );

        return $segment(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$segment($payload).'.signature';
    }

    /**
     * The verbatim error Suwayomi returns for an expired access token on a
     *
     * @RequireAuth field: HTTP 200, with the Kotlin exception in the message.
     *
     * @return array<string, mixed>
     */
    protected function expiredTokenBody(string $field = 'extensions'): array
    {
        return [
            'errors' => [[
                'message' => "Exception while fetching data (/{$field}) : Unauthorized\r\n\r\n"
                    ."suwayomi.tachidesk.server.user.UnauthorizedException: Unauthorized\r\n"
                    ."\tat suwayomi.tachidesk.server.user.UserTypeKt.requireUser(UserType.kt:23)\r\n"
                    ."\tat suwayomi.tachidesk.graphql.directives.RequireAuthDirectiveWiring.onField\$lambda\$0(RequireAuthDirectiveWiring.kt:28)",
                'locations' => [['line' => 1, 'column' => 1]],
                'path' => [$field],
            ]],
        ];
    }
}
