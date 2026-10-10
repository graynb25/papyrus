<?php

namespace App\Import;

/**
 * What one import produced: the series counters and the extension outcome.
 *
 * ## Why this is a type and not a bag of arrays
 *
 * Because the run's report says "0 installed" and "32 still missing" as two separate facts, and
 * when both came out of one `array<string, int>` with a nested array folded into it, PHPStan
 * could not tell a counter from the container holding the counters. Every read became
 * `int|array<string, mixed>`, which meant 14 errors and, before them, a report that described
 * the remainder as the thing that had been fixed.
 *
 * Two extension numbers that are genuinely different, and which the earlier code conflated:
 *
 * - {@see self::$attempted} is what the backup needed that this server did not have, measured
 *   before the install.
 * - {@see self::$stillMissing} is that set after the install, which is what a later run
 *   retries and what a reader has to act on.
 *
 * `updateExtension` is one mutation per package, so most of the `attempted` set does land and
 * the two numbers usually differ. They are not derived from each other and neither is inferred.
 *
 * {@see self::$refusedPackages} is the third: what did not land, and why. One package out of 32
 * that will not install must not abandon the other 31, so this is a count and a list rather than
 * an exception.
 */
final class ImportResult
{
    /**
     * @param  array<string, int>  $series  The series counters, keyed as {@see BackupImporter} names them.
     * @param  int  $alreadyInstalled  Sources this server already had an extension for.
     * @param  int  $attempted  Sources the backup needed that were missing, before installing.
     * @param  int  $installed  Of those, how many the install actually brought in.
     * @param  int  $stillMissing  Of those, how many are still missing afterwards.
     * @param  int  $obsoleteSeries  Series on sources the reader's own app had marked gone.
     * @param  array<string, string>  $refusedPackages  Package to the message it refused with.
     * @param  bool  $installAttempted  False when the run was told not to install.
     */
    public function __construct(
        public readonly array $series,
        public readonly int $alreadyInstalled,
        public readonly int $attempted,
        public readonly int $installed,
        public readonly int $stillMissing,
        public readonly int $obsoleteSeries,
        public readonly array $refusedPackages = [],
        public readonly bool $installAttempted = true,
    ) {}

    public function applied(): int
    {
        return $this->series['applied'];
    }

    public function unchanged(): int
    {
        return $this->series['unchanged'];
    }

    public function library(): int
    {
        return $this->series['library_series'];
    }

    public function count(string $bucket): int
    {
        return $this->series[$bucket] ?? 0;
    }
}
