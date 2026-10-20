<?php

namespace App\Backup;

use App\Import\ImportedBackup;

/**
 * What a finished live backup produced, read back from the pair that landed.
 *
 * Everything here is what a run row names and a page renders: the token the files carry, the
 * counts the staged reader half was verified against, the summary the staged `.tachibk`
 * answered, and the Suwayomi version the manifest binds the manga half to. The constructor
 * carries no defaults, because a number that could quietly sit at zero is exactly the
 * believable lie this feature exists to prevent.
 */
final class LiveBackupResult
{
    /**
     * @param  int  $seriesStates  Staged-file count, as the manifest records it.
     * @param  int  $chapterStates  Staged-file count, as the manifest records it.
     * @param  array<string, int|string|null>  $mangaSummary  {@see ImportedBackup::summary()} for the staged file.
     * @param  int|null  $downloadsBytes  The bytes zipped from the downloads folder; null when the run carried none.
     */
    public function __construct(
        public readonly string $token,
        public readonly int $seriesStates,
        public readonly int $chapterStates,
        public readonly array $mangaSummary,
        public readonly string $suwayomiVersion,
        public readonly ?int $downloadsBytes,
        /** The folder the pair was renamed into, which is {@see BackupLibrary} for this install. */
        public readonly string $directory,
    ) {}
}
