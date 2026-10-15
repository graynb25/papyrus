<?php

namespace Tests\Unit\Suwayomi;

use App\Suwayomi\Jwt;
use Tests\Support\MintsTokens;
use Tests\TestCase;

class JwtTest extends TestCase
{
    use MintsTokens;

    public function test_it_reads_the_expiry_from_the_token(): void
    {
        // One clock reading for both sides. This used to mint with the helper's own `time()` and then
        // compare against a fresh `time() + 300`, so a second ticking in between gave 301 against 300.
        $now = 1800000000;

        $this->assertSame($now + 300, Jwt::expiry($this->accessToken(300, $now)));
    }

    public function test_it_reads_a_string_claim(): void
    {
        $this->assertSame('access', Jwt::claim($this->accessToken(), 'token_type'));
        $this->assertSame('refresh', Jwt::claim($this->refreshToken(), 'token_type'));
    }

    public function test_it_returns_null_for_a_token_that_is_not_a_jwt(): void
    {
        $this->assertNull(Jwt::expiry('token-1'));
        $this->assertNull(Jwt::expiry('not-a-jwt'));
        $this->assertNull(Jwt::claim('not-a-jwt', 'exp'));
    }

    /**
     * The server's own complaint about a malformed token was "expected to have
     * 3 parts, but got 0", so a partial token is a case worth being calm about.
     */
    public function test_it_returns_null_rather_than_throwing_on_junk(): void
    {
        foreach (['', '.', '..', 'a.b', 'a.b.c.d', 'header.%%%', "\x00\x01"] as $junk) {
            $this->assertNull(Jwt::expiry($junk), 'Failed on: '.var_export($junk, true));
        }
    }

    public function test_it_handles_base64url_without_padding(): void
    {
        // A payload whose length is not a multiple of 3 loses its padding, which
        // is why strtr then rtrim appear in both the faker and the reader.
        $token = $this->jwt(['token_type' => 'access'], 300);

        $this->assertStringNotContainsString('=', explode('.', $token)[1]);
        $this->assertSame('access', Jwt::claim($token, 'token_type'));
    }

    public function test_it_ignores_claims_of_the_wrong_type(): void
    {
        $this->assertNull(Jwt::claim($this->jwt(['scope' => ['a', 'b']]), 'scope'));
    }
}
