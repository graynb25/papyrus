<?php

namespace App\Export;

use App\Backup\LiveRestoreResult;

/**
 * What a finished tachibk export produced, read back from the file that was written.
 *
 * Everything here is what a run row names and a page renders: the sink the caller named,
 * the byte size of what actually landed, the three series counts the run's own comparison
 * produced (in file, planned from the reader's arrangement, matched by the merger's
 * report), the shelf and read mark totals, the per series detail the merger reported, and
 * the skipped reasons for the planned series the file could not take. The constructor
 * carries no defaults, for the same reason {@see LiveRestoreResult} carries
 * none: a number that could quietly sit at zero is the believable lie this feature exists
 * to prevent.
 */
final class TachibkExportResult
{
    /**
     * @param  string  $file  The sink path the caller named, now holding the merged file.
     * @param  int  $fileBytes  The written size on disk, measured after the write.
     * @param  int  $seriesInFile  Series records the fresh export carried.
     * @param  int  $seriesPlanned  Series whose reader state (shelf or read marks) the run tried to carry, every stateful series including the ones the server could not name. Matched plus skipped equals this.
     * @param  int  $seriesMatched  Planned series a merger report matched, so bytes actually changed for them.
     * @param  int  $shelvesAppended  Shelf records appended under field 2.
     * @param  int  $readMarks  Chapter read flags the merger appended.
     * @param  list<array{source: int, url: string, title: ?string, marks: int, shelves: int}>  $series  The merger's per series report, unchanged.
     * @param  list<string>  $skipped  One reason per planned series no report matched.
     */
    public function __construct(
        public readonly string $file,
        public readonly int $fileBytes,
        public readonly int $seriesInFile,
        public readonly int $seriesPlanned,
        public readonly int $seriesMatched,
        public readonly int $shelvesAppended,
        public readonly int $readMarks,
        public readonly array $series,
        public readonly array $skipped,
    ) {}
}
