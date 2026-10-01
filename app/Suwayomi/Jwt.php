<?php

namespace App\Suwayomi;

/**
 * Just enough of a JWT reader to learn when a token dies.
 *
 * The payload is base64url, not encrypted, so this is decoding rather than
 * verification. As the client we gain nothing from checking the signature:
 * the server signed it and we already trust the transport. What we need is the
 * `exp` claim.
 *
 * Reading it matters more than it sounds. Guessing a token's lifetime is what
 * let AuthManager hand out a dead access token for 45 minutes against a real
 * 5 minute lifetime, and because Suwayomi reports that rejection as HTTP 200
 * with a GraphQL error, nothing noticed until a user-facing page broke.
 */
final class Jwt
{
    /**
     * The moment the token stops being valid, or null if we cannot tell.
     *
     * Null rather than a guess: a caller that invents a fallback here would
     * reintroduce exactly the bug this class exists to remove.
     */
    public static function expiry(string $token): ?int
    {
        $exp = self::claim($token, 'exp');

        return is_int($exp) ? $exp : null;
    }

    /**
     * A single claim, decoded. Handles the string and integer forms since
     * `exp` is numeric while `token_type` and `aud` are text.
     */
    public static function claim(string $token, string $name): int|string|null
    {
        $payload = self::payload($token);

        if ($payload === null) {
            return null;
        }

        $value = $payload[$name] ?? null;

        return is_int($value) || is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function payload(string $token): ?array
    {
        $segments = explode('.', $token);

        // The server told us a token with the wrong shape has zero parts, so
        // guard on the real thing rather than trusting the count.
        if (count($segments) < 2 || $segments[1] === '') {
            return null;
        }

        // Non-strict on purpose: base64_decode only returns false in strict
        // mode, and refusing characters is not useful here. A payload we
        // cannot parse falls out as null from json_decode instead.
        $json = json_decode(base64_decode(strtr($segments[1], '-_', '+/'), false), true);

        return is_array($json) ? $json : null;
    }
}
