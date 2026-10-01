<?php

namespace Tests\Unit\Suwayomi;

use App\Support\Suwayomi\Timestamps;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * C6: every 64-bit value crosses the wire as a JSON string.
 *
 * The unit of a timestamp is not in the schema, and it is not consistent across
 * fields. Measured against v2.3.2243:
 *
 *   ChapterType.uploadDate              '1767198093000'  milliseconds
 *   ChapterType.fetchedAt               '1790776081'     seconds
 *   MangaType.chaptersLastFetchedAt     '1790776498'     seconds
 *   MangaType.age / .chaptersAge        '88'             days, not a timestamp
 *
 * The values in the tests below are copied from live responses rather than
 * invented, since the whole point is that a plausible-looking wrong unit is the
 * failure mode.
 */
class TimestampsTest extends TestCase
{
    private Timestamps $timestamps;

    protected function setUp(): void
    {
        parent::setUp();

        $this->timestamps = new Timestamps;
    }

    public function test_it_reads_long_strings_as_integers(): void
    {
        $this->assertSame(1234567890, $this->timestamps->toInt('1234567890'));
        $this->assertSame(42, $this->timestamps->toInt(42));
    }

    public function test_it_treats_zero_as_a_real_id(): void
    {
        // LocalSource.ID is 0 in Suwayomi, so 0 must not collapse to null.
        $this->assertSame(0, $this->timestamps->toInt('0'));
    }

    public function test_it_maps_null_and_empty_string_to_null(): void
    {
        $this->assertNull($this->timestamps->toInt(null));
        $this->assertNull($this->timestamps->toInt(''));
    }

    public function test_it_reads_epoch_seconds(): void
    {
        $date = $this->timestamps->fromEpochSecond('1759257600');

        $this->assertInstanceOf(CarbonImmutable::class, $date);
        $this->assertSame(1759257600, $date->getTimestamp());
    }

    public function test_it_reads_epoch_milliseconds(): void
    {
        $date = $this->timestamps->fromEpochMilli('1759257600000');

        $this->assertInstanceOf(CarbonImmutable::class, $date);
        $this->assertSame(1759257600, $date->getTimestamp());
    }

    public function test_seconds_and_milliseconds_are_not_interchangeable(): void
    {
        // The exact bug this class exists to prevent: a SyncStatus timestamp read
        // as seconds lands 50 years in the future.
        $asSeconds = $this->timestamps->fromEpochSecond('1759257600000');
        $asMillis = $this->timestamps->fromEpochMilli('1759257600000');

        $this->assertNotSame(
            $asSeconds->getTimestamp(),
            $asMillis->getTimestamp(),
        );
    }

    public function test_it_round_trips_to_epoch_seconds(): void
    {
        $date = CarbonImmutable::createFromTimestampUTC(1759257600);

        $this->assertSame(1759257600, $this->timestamps->toEpochSecond($date));
        $this->assertSame(1759257600000, $this->timestamps->toEpochMilli($date));
        $this->assertNull($this->timestamps->toEpochSecond(null));
    }

    public function test_it_detects_the_unit_from_the_magnitude(): void
    {
        // Both values are verbatim from a live response for the same series.
        $this->assertSame('milli', $this->timestamps->detectUnit('1767198093000'));
        $this->assertSame('second', $this->timestamps->detectUnit('1790776081'));
        $this->assertSame('milli', $this->timestamps->detectUnit('1759257600000'));
        $this->assertSame('second', $this->timestamps->detectUnit('1759257600'));
    }

    /**
     * Zero is the server's way of saying "never", not a unit. Treating it as a
     * second count would produce 1970 for every unfetched chapter.
     */
    public function test_zero_and_absent_values_have_no_detectable_unit(): void
    {
        $this->assertSame('unknown', $this->timestamps->detectUnit('0'));
        $this->assertSame('unknown', $this->timestamps->detectUnit(0));
        $this->assertSame('unknown', $this->timestamps->detectUnit(null));
        $this->assertSame('unknown', $this->timestamps->detectUnit(''));
    }

    /**
     * The concrete bug this measurement caught.
     *
     * `uploadDate` read as seconds renders as year 57970, and across a real
     * 41 chapter series that put every chapter in the future. A chapter list
     * ordered newest-first would then be ordered by nonsense, and any release
     * window logic would treat the whole series as unreleased.
     */
    public function test_a_chapter_upload_date_read_as_seconds_lands_in_the_year_57970(): void
    {
        $wrong = $this->timestamps->fromEpochSecond('1767198093000');
        $right = $this->timestamps->fromEpochMilli('1767198093000');

        $this->assertSame(57970, $wrong->year);
        $this->assertSame(2025, $right->year);
        $this->assertTrue($wrong->greaterThan(CarbonImmutable::createFromTimestampUTC(time())));
    }

    /**
     * Year pinned to the *year*, not the value: a millisecond read lands in 2025
     * and a second read in 2026 today, but pinning a date would make this test
     * fail in 2027 for no reason.
     */
    public function test_it_reads_a_mixed_unit_payload_without_being_told(): void
    {
        $uploadDate = $this->timestamps->fromEpochAuto('1767198093000');
        $fetchedAt = $this->timestamps->fromEpochAuto('1790776081');

        $this->assertSame('2025-12-31', $uploadDate->format('Y-m-d'));
        $this->assertSame(2026, $fetchedAt->year);
        $this->assertNull($this->timestamps->fromEpochAuto('0'));
    }
}
