<?php

namespace Tests\Unit;

use App\Support\Etag;
use Tests\TestCase;

/**
 * The validator the app mints where upstream sends none (Suwayomi answers neither
 * ETag nor Last-Modified on thumbnails or pages). The mint side is one line and
 * carried by its consumers, so this file owns the half that can quietly betray one:
 * the If-None-Match comparison.
 */
class EtagTest extends TestCase
{
    public function test_a_minted_validator_is_a_quoted_digest_of_the_bytes(): void
    {
        $this->assertSame('"'.hash('sha256', 'abc').'"', Etag::for('abc'));
        $this->assertNotSame(Etag::for('abc'), Etag::for('abd'));
    }

    public function test_a_request_header_that_matches_mints_a_validation(): void
    {
        $etag = '"etag-1"';

        // An exact echo, a list echoing among others, anything at all.
        $this->assertTrue(Etag::matched('"etag-1"', $etag));
        $this->assertTrue(Etag::matched('"etag-2", "etag-1"', $etag));
        $this->assertTrue(Etag::matched('*', $etag));
    }

    /**
     * Weak echoes match strong mints: that is RFC 9110's own rule for If-None-Match,
     * and refusing it would turn a proxy that re-emits W/ into a full rebyte.
     */
    public function test_a_weak_echo_matches_a_strong_mint(): void
    {
        $this->assertTrue(Etag::matched('W/"etag-1"', '"etag-1"'));
    }

    public function test_a_header_that_names_nothing_mints_nothing(): void
    {
        $etag = '"etag-1"';

        $this->assertFalse(Etag::matched(null, $etag));
        $this->assertFalse(Etag::matched('', $etag));
        $this->assertFalse(Etag::matched('   ', $etag));
        $this->assertFalse(Etag::matched('"etag-2"', $etag));
    }
}
