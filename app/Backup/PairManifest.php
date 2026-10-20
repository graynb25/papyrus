<?php

namespace App\Backup;

use Papyrus\Launcher\BackupPair;
use Papyrus\Launcher\RestoreRefused;
use Papyrus\Launcher\SnapshotCommand;

/**
 * The manifest that binds the in-app pair's two halves together.
 *
 * ## Why the key shape is the launcher's, field for field
 *
 * Because this manifest sits in the same folder as `papyrus snapshot`'s and is read by the same
 * reader: {@see BackupPair}. The keys, the nesting and the value shapes below
 * are {@see SnapshotCommand}'s own `writeManifest()`, copied rather than
 * reinvented, so a reader, a restore, and a test cannot find two shapes of pair manifest to
 * disagree about. The one key this adds is the honest marker `suwayomi.format = "tachibk"`:
 * the launcher's pair carries a copy of the live H2 file, and this pair carries Suwayomi's own
 * fresh export, which restores through Suwayomi's `restoreBackup` mutation and must never be
 * file copied over the live H2 file. `BackupPair` ignores keys it does not know, so the marker
 * rides along harmless on a launcher-shaped reader, and a launcher-side restore of this pair
 * reads it from the same place a person would.
 */
final class PairManifest
{
    /**
     * Write a manifest for a pair that already exists on disk.
     *
     * The halves are checked for being there before anything is written, because the manifest
     * is the thing that says they are a pair, and a manifest naming a half that never landed
     * is exactly the {@see BackupPair} lopsided-refusal this project already
     * refuses a restore through.
     *
     * So is a count that was never established: two zeroes read as an empty library, and an
     * empty library is a credible lie. The stager verifies its counts against the live
     * database before it calls this; this method refuses a null for the same reason the
     * launcher's own writer does, so the two callers cannot drift.
     *
     * @param  int  $seriesStates  Staged-file count of `series_states`, not the live count.
     * @param  int  $chapterStates  Staged-file count of `chapter_states`, not the live count.
     *
     * @throws RestoreRefused
     */
    public static function write(
        string $path,
        string $token,
        string $readerFile,
        int $seriesStates,
        int $chapterStates,
        string $mangaFile,
        string $suwayomiVersion,
    ): void {
        foreach ([$readerFile, $mangaFile] as $half) {
            if (! is_file($half)) {
                throw (new RestoreRefused(sprintf(
                    'A manifest cannot be written because %s is not there, so the two files would '
                    .'not be a pair. Nothing has been recorded as backed up.',
                    basename($half),
                )))->attempted($half);
            }
        }

        $encoded = json_encode([
            'token' => $token,
            'taken_at' => gmdate('c'),
            'papyrus' => [
                'file' => basename($readerFile),
                'sha256' => hash_file('sha256', $readerFile),
                'bytes' => (int) filesize($readerFile),
                'series_states' => $seriesStates,
                'chapter_states' => $chapterStates,
            ],
            'suwayomi' => [
                'file' => basename($mangaFile),
                'sha256' => hash_file('sha256', $mangaFile),
                'bytes' => (int) filesize($mangaFile),
                'server_version' => $suwayomiVersion,
                'format' => 'tachibk',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false || @file_put_contents($path, $encoded) === false) {
            throw (new RestoreRefused(sprintf(
                'The manifest could not be written to %s. Both halves are on disk but unbound, so '
                .'nothing has been recorded as a backup you can restore.',
                $path,
            )))->attempted($path);
        }
    }
}
