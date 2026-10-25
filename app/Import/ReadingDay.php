<?php

namespace App\Import;

use Carbon\CarbonImmutable;

/**
 * One day of reading time from another app's backup.
 *
 * ## The grain is the file's, not this server's
 *
 * Tachimanga records `Stats` as one row per series per day and `History.read_duration`
 * as one lifetime total per series. Neither carries a chapter, a start or an end, so
 * this value object carries the two facts both can honestly supply: a calendar day and
 * a number of seconds. Pretending a sitting happened would put invented sessions in
 * front of a reader who is looking at their own history.
 *
 * ## The measured day is an integer, and an invalid one is dropped
 *
 * `Stats.day` is `YYYYMMDD` as an integer, measured on a real backup. Building a date
 * with `createFromFormat` alone is not enough, because PHP rolls `20260231` into March
 * and a rolled date looks like a real reading day. The round trip through `format()`
 * is what rejects it, along with any day that is not eight digits.
 */
final class ReadingDay
{
    public function __construct(
        public readonly CarbonImmutable $day,
        public readonly int $seconds,
    ) {}

    /**
     * A `Stats` row's day and duration, or null when either cannot be believed.
     *
     * Zero and negative durations are dropped rather than stored: they say nothing
     * about reading, and a row of zero would make a series look like it had daily
     * coverage when the only honest answer is that it did not.
     */
    public static function fromBackupDay(mixed $day, mixed $seconds): ?self
    {
        if (! is_numeric($day) || ! is_numeric($seconds)) {
            return null;
        }

        $seconds = (int) $seconds;

        if ($seconds <= 0) {
            return null;
        }

        $text = (string) (int) $day;

        if (strlen($text) !== 8) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Ymd', $text);

        if ($date === null || $date->format('Ymd') !== $text) {
            return null;
        }

        return new self($date, $seconds);
    }
}
