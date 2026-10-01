<?php

namespace App\Support\Suwayomi;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Suwayomi's time and integer conventions.
 *
 * Every 64-bit value crosses the wire as a JSON *string* - the schema maps
 * Long to GraphQLLongAsString - so ids and timestamps arrive as
 * `"1234567890123"` rather than a number. Cast deliberately rather than letting
 * a JSON decode decide.
 *
 * ## Measured, not assumed
 *
 * Every field was checked against v2.3.2243 by value, not by reading the schema,
 * because the schema types them all identically and only says LongString.
 *
 * **Milliseconds:**
 *   - `ChapterType.uploadDate`, e.g. `'1767198093000'`
 *   - `SyncStatus.startDate` and `.endDate`
 *
 * **Seconds:**
 *   - `ChapterType.fetchedAt`, e.g. `'1790776081'`
 *   - `MangaType.chaptersLastFetchedAt`, `.lastFetchedAt`, `.thumbnailUrlLastFetched`
 *
 * **Neither, despite looking like a timestamp:**
 *   - `MangaType.age` and `.chaptersAge`, both `'88'`. Days since last update.
 *
 * `uploadDate` being milliseconds is the one that matters. Read as seconds it
 * renders as year 57970, and in a test over a real 41 chapter series it put
 * **all 41 chapters in the future**, which would sort a chapter list newest-first
 * into nonsense and defeat any release-window logic. See {@see self::detectUnit()}.
 */
final class Timestamps
{
    public function now(): int
    {
        return time();
    }

    public function fromEpochSecond(int|string|null $value): ?CarbonImmutable
    {
        $int = $this->toInt($value);

        return $int === null ? null : CarbonImmutable::createFromTimestampUTC($int);
    }

    public function fromEpochMilli(int|string|null $value): ?CarbonImmutable
    {
        $int = $this->toInt($value);

        return $int === null ? null : CarbonImmutable::createFromTimestampUTC(intdiv($int, 1000));
    }

    /**
     * Which unit a LongString timestamp is in, from the magnitude alone.
     *
     * The two are trivially separable: a plausible second count sits near 1.8e9
     * and a plausible millisecond count near 1.8e12, so anything past 1e11
     * cannot be seconds. That matters because `ChapterType.uploadDate` and
     * `ChapterType.fetchedAt` sit side by side in the same payload with opposite
     * units, and the schema types them both as LongString.
     *
     * Returns 'milli', 'second', or 'unknown' for absent and zero. Zero is not a
     * unit, it is "never fetched", which is how the server reports an unfetched
     * chapter's lastReadAt and a manga's chaptersLastFetchedAt before the first
     * sync.
     */
    public function detectUnit(int|string|null $value): string
    {
        $int = $this->toInt($value);

        if ($int === null || $int === 0) {
            return 'unknown';
        }

        // 1e11 seconds is the year 5138, so no real timestamp reaches it.
        return $int > 100_000_000_000 ? 'milli' : 'second';
    }

    /**
     * Read a LongString timestamp without being told its unit.
     *
     * For the fields whose unit is genuinely mixed within one payload. Prefer the
     * explicit methods everywhere the unit is known and fixed.
     */
    public function fromEpochAuto(int|string|null $value): ?CarbonImmutable
    {
        return match ($this->detectUnit($value)) {
            'milli' => $this->fromEpochMilli($value),
            'second' => $this->fromEpochSecond($value),
            // 'unknown' means absent or zero, and zero is the server saying
            // "never". Returning 1970 would put a fake date on an unfetched
            // chapter, which is worse than having none.
            default => null,
        };
    }

    public function toEpochSecond(?DateTimeInterface $value): ?int
    {
        return $value?->getTimestamp();
    }

    public function toEpochMilli(?DateTimeInterface $value): ?int
    {
        return $value === null ? null : $value->getTimestamp() * 1000;
    }

    /**
     * Normalise a LongString to an int, or null for absent values.
     *
     * 0 is a legitimate id in Suwayomi (LocalSource.ID is 0), so only null and
     * the empty string become null.
     */
    public function toInt(int|string|float|null $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_int($value) ? $value : (int) $value;
    }
}
