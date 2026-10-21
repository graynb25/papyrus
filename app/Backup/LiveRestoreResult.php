<?php

namespace App\Backup;

use App\Import\ImportedBackup;

/**
 * What a finished live restore produced, read back from the pair that was put back.
 *
 * Everything here is what a run row names and a page renders: the token the pair carries,
 * the reader half's counts as its own manifest records them, the summary the manga half
 * answered when it was read back during the run, the Suwayomi version the manifest binds
 * the two halves to, and the import report the server wrote as it restored its side. The
 * constructor carries no defaults, for the same reason {@see LiveBackupResult} carries
 * none: a number that could quietly sit at zero is the believable lie this feature exists
 * to prevent.
 */
final class LiveRestoreResult
{
    /**
     * @param  int  $seriesStates  The pair's reader half count, as the manifest records it.
     * @param  int  $chapterStates  The pair's reader half count, as the manifest records it.
     * @param  array<string, int|string|null>  $mangaSummary  {@see ImportedBackup::summary()} for the pair's manga half.
     * @param  array{missingSources: list<string>, missingTrackers: list<string>, mangasMissingSources: list<string>}  $restoreSummary
     *                                                                                                                                  The import answer, in the server's own three lists.
     */
    public function __construct(
        public readonly string $token,
        public readonly int $seriesStates,
        public readonly int $chapterStates,
        public readonly array $mangaSummary,
        public readonly string $suwayomiVersion,
        public readonly array $restoreSummary,
    ) {}
}
