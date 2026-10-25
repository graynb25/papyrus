<?php

namespace Tests\Unit;

use App\Support\Duration;
use Tests\TestCase;

/**
 * The words a duration is said in.
 *
 * The measured defect this replaces: the import modal printed Carbon's raw
 * difference, `1273352.650004s`, and Insights dropped the seconds from every
 * duration past a minute, so ninety seconds read "1 minute".
 */
class DurationTest extends TestCase
{
    public function test_a_duration_under_a_minute_keeps_its_seconds(): void
    {
        $this->assertSame('0 seconds', Duration::forHumans(0));
        $this->assertSame('1 second', Duration::forHumans(1));
        $this->assertSame('59 seconds', Duration::forHumans(59));
    }

    public function test_a_duration_past_a_minute_keeps_the_seconds_that_remain(): void
    {
        $this->assertSame('1 minute', Duration::forHumans(60));
        $this->assertSame('1 minute 30 seconds', Duration::forHumans(90));
        $this->assertSame('5 minutes 30 seconds', Duration::forHumans(330));
    }

    public function test_hours_and_days_are_used_only_where_they_say_something(): void
    {
        $this->assertSame('1 hour', Duration::forHumans(3600));
        $this->assertSame('1 hour 1 minute 1 second', Duration::forHumans(3661));
        $this->assertSame('1 hour 23 minutes 20 seconds', Duration::forHumans(5000));
        $this->assertSame('1 day 1 hour', Duration::forHumans(90000));
    }

    public function test_at_most_three_units_are_said(): void
    {
        // 1,273,352 seconds was the raw number in the report.
        $this->assertSame('14 days 17 hours 42 minutes', Duration::forHumans(1273352));
    }

    public function test_a_large_count_is_still_grouped(): void
    {
        $this->assertSame('1,234 days', Duration::forHumans(1234 * 86400));
    }

    public function test_a_negative_duration_is_zero_rather_than_a_minus_sign(): void
    {
        $this->assertSame('0 seconds', Duration::forHumans(-5));
    }
}
