<?php

namespace Papyrus\Launcher;

use Throwable;

/**
 * Restore both databases as one operation, or leave both as they were.
 *
 * ## The guarantee
 *
 * **Either both halves are replaced or neither is.** Not "the second one is usually fine". The
 * install holds two databases that reference each other, and a restore that lands one of them leaves
 * every `series_states.suwayomi_manga_id` aimed at a row that now means something else. There is no
 * error for that. The symptom is a library that reads as empty, with nothing in any log.
 *
 * That is the only outcome in this project where a reader can lose work they cannot reconstruct, so
 * the ordering below is not a matter of taste.
 *
 * ## The order, and why each step is where it is
 *
 * 1. **Check the pair, touching nothing.** Files present, digests match, manifest names both halves.
 *    A pre-flight that stops the application and *then* discovers a missing file has already cost the
 *    reader a running install.
 * 2. **Move both live files aside.** Both, before either is replaced. Moving them first is what makes
 *    the rollback possible at all: once the first has been overwritten there is nothing to put back.
 * 3. **Copy both in.** The incoming files are copied, not moved, so the backup survives and the
 *    restore can be repeated. `LocalFileMover` verifies each copy against its source as it lands.
 * 4. **Check what landed.** Digests again, against the manifest rather than against the backup, so a
 *    file corrupted on the way in is caught here and not days later.
 * 5. **Put both back if any of that failed.**
 *
 * Stopping and starting the application is the caller's business, and deliberately not this class's:
 * the launcher owns process control and a restore that also manages processes cannot be tested
 * without starting them.
 *
 * ## What a reader is told afterwards
 *
 * Where the previous files went, in full paths, because a reader who has just been through this will
 * want to put them back themselves and the rollback directory is the only record that they exist.
 */
final class Restorer
{
    public function __construct(private readonly FileMover $mover) {}

    /**
     * Put a pair back into a live install.
     *
     * @param  array<string, array{target: string, companions: list<string>}>  $live  Each half as it
     *                                                                                is now: the file to replace, and the sidecars that belong to it and go with it.
     * @param  string|null  $rollbackDirectory  Defaults to `rollback-<token>` beside the pair
     *
     * @throws RestoreRefused Nothing was changed, or a rollback failed and the message says so.
     */
    public function restore(BackupPair $pair, array $live, ?string $rollbackDirectory = null): RestoreOutcome
    {
        $this->checkPairIsWhole($pair);

        $rollbackDirectory ??= dirname($pair->pathOf('papyrus')).DIRECTORY_SEPARATOR.'rollback-'.$pair->token;

        // Both files are moved aside first, and the set records that it is under way. Once this is
        // non-empty, any failure has to put things back, so the rollback path cannot be skipped by a
        // later `return` or `throw`.
        /*
         * A flat list of every file actually moved, so the rollback puts back exactly those and no
         * others. Restoring a file that was never moved would destroy the very thing being restored,
         * and leaving one out would lose the rows that lived in it.
         */
        $movedAside = [];

        try {
            /*
             * The target and every companion move aside together.
             *
             * Together because a WAL without its database and a database without its WAL are both
             * states nothing can be reasoned about. Measured: replacing `database.sqlite` while the
             * previous install's `database.sqlite-wal` stayed beside it produced a restored database
             * with THREE rows where the snapshot held two, and `PRAGMA integrity_check` said `ok`
             * throughout, because the result is a valid database holding a row that should not be in
             * it. On the reader's own install that WAL is 4,140,632 bytes against a 5,192,368 byte
             * database.
             */
            foreach (['papyrus', 'suwayomi'] as $half) {
                foreach ($this->filesOf($live[$half]) as $path) {
                    $this->mover->move(
                        $path,
                        $rollbackDirectory.DIRECTORY_SEPARATOR.'previous-'.basename($path),
                    );

                    $movedAside[] = $path;
                }
            }

            foreach (['papyrus', 'suwayomi'] as $half) {
                $this->mover->copy($pair->pathOf($half), $live[$half]['target']);
            }

            /*
             * Checked against the manifest, not against the backup.
             *
             * Comparing what landed with what was copied proves the copy was faithful. Comparing it
             * with what the manifest recorded proves the backup itself was what it claimed to be, and
             * that is the one that catches a snapshot written by a process that died partway.
             */
            $this->checkLandedMatchesManifest($pair, $live);
        } catch (Throwable $e) {
            throw $this->undo($movedAside, $live, $rollbackDirectory, $e);
        }

        return new RestoreOutcome(
            pair: $pair,
            rollback: $this->rollbackPaths($movedAside, $live, $rollbackDirectory),
            seriesStates: $pair->seriesStates,
            chapterStates: $pair->chapterStates,
        );
    }

    /**
     * Both halves present, both are what the manifest says they are, and the manga half is a shape
     * this launcher can actually put back.
     *
     * Touches nothing, and the ordering matters: this runs before the first move so a bad backup
     * costs the reader nothing but the time to read the message.
     *
     * A `.tachibk` manga half is Suwayomi's own export, which Papyrus's in-app backup takes while
     * the server keeps running. The launcher can only restore an H2 file, so it refuses here
     * rather than replace a working database with a file Suwayomi cannot read.
     *
     * @throws RestoreRefused
     */
    private function checkPairIsWhole(BackupPair $pair): void
    {
        foreach (['papyrus', 'suwayomi'] as $half) {
            $path = $pair->pathOf($half);

            if ($half === 'suwayomi' && str_ends_with($pair->suwayomiFile, '.tachibk')) {
                throw (new RestoreRefused(sprintf(
                    'The manga half of this backup is a Suwayomi export (%s), the shape Papyrus\'s '
                    .'own in-app backup takes while Suwayomi keeps running. This command can only '
                    .'put back an H2 database, so it refuses rather than replace one with a file '
                    .'Suwayomi cannot read. Nothing has been changed.',
                    $pair->suwayomiFile,
                )))->attempted($path);
            }

            if (! $this->mover->exists($path)) {
                throw (new RestoreRefused(sprintf(
                    'The backup is missing its %s half: there is nothing at %s. Nothing has been '
                    .'changed.',
                    $half === 'suwayomi' ? 'manga' : 'reader',
                    $path,
                )))->attempted($path);
            }

            $expected = $half === 'suwayomi' ? $pair->suwayomiSha256 : $pair->papyrusSha256;

            if ($this->mover->digest($path) !== $expected) {
                throw (new RestoreRefused(sprintf(
                    'The %s half of this backup does not match the digest recorded when it was '
                    .'taken, so it is incomplete or damaged. Nothing has been changed.',
                    $half === 'suwayomi' ? 'manga' : 'reader',
                )))->attempted($path);
            }
        }
    }

    /**
     * @param  array<string, array{target: string, companions: list<string>}>  $live
     *
     * @throws RestoreRefused
     */
    private function checkLandedMatchesManifest(BackupPair $pair, array $live): void
    {
        foreach (['papyrus', 'suwayomi'] as $half) {
            $expected = $half === 'suwayomi' ? $pair->suwayomiSha256 : $pair->papyrusSha256;

            if ($this->mover->digest($live[$half]['target']) !== $expected) {
                throw (new RestoreRefused(sprintf(
                    'The %s half did not land intact. It has been taken back out again.',
                    $half === 'suwayomi' ? 'manga' : 'reader',
                )))->attempted($live[$half]['target']);
            }
        }
    }

    /**
     * Put both halves back, and say so plainly if that did not work either.
     *
     * The second refusal is the one that matters. A restore that has failed and cannot undo itself
     * has left a reader with two databases that are not a matched pair, and the least bad thing it
     * can do is say that in words rather than return an exception a caller might report as "the
     * restore failed, try again".
     *
     * @param  list<string>  $movedAside  Every file actually moved, so only those are put back.
     *                                    Moving a file that was never moved would destroy the very
     *                                    thing being restored, and leaving one out would lose the
     *                                    rows that lived in it: the live WAL held rows the main
     *                                    database did not have, so a rollback that restored only
     *                                    the main file would discard them while looking like a
     *                                    successful undo.
     * @param  array<string, array{target: string, companions: list<string>}>  $live
     */
    private function undo(
        array $movedAside,
        array $live,
        string $rollbackDirectory,
        Throwable $cause,
    ): RestoreRefused {
        $refused = new RestoreRefused($cause->getMessage());
        $refused = $refused->attempted('the restore failed part way and is being taken back');

        if ($movedAside === []) {
            return $refused;
        }

        $problems = [];

        foreach (array_reverse($movedAside) as $path) {
            $kept = $rollbackDirectory.DIRECTORY_SEPARATOR.'previous-'.basename($path);

            try {
                $this->mover->move($kept, $path);
            } catch (Throwable $e) {
                $problems[] = sprintf('%s: %s', basename($path), $e->getMessage());
            }
        }

        if ($problems !== []) {
            return new RestoreRefused(sprintf(
                'The restore failed part way AND the files that were there before could not be put '
                ."back. This install is not in a known state and you should not open Papyrus yet.\n\n"
                ."  Why the restore failed:\n    %s\n\n"
                ."  What could not be put back:\n    %s\n\n"
                ."  Your previous files are still here, if you need them:\n    %s\n\n"
                .'  Stop everything with `papyrus stop`, then copy them back by hand over the two '
                ."files in the install's data directory.",
                $cause->getMessage(),
                implode("\n    ", $problems),
                $rollbackDirectory,
            ));
        }

        return $refused;
    }

    /**
     * Where each kept file now is, grouped by the half it belongs to.
     *
     * A reader told only where `database.sqlite` went has been told half of what they need. The WAL
     * beside it held rows the main file did not have, and it is the only other copy of them.
     *
     * @param  list<string>  $movedAside
     * @param  array<string, array{target: string, companions: list<string>}>  $live
     * @return array<string, array<string, string>>
     */
    private function rollbackPaths(array $movedAside, array $live, string $rollbackDirectory): array
    {
        $paths = [];

        foreach ($live as $half => $files) {
            foreach ($this->filesOf($files) as $path) {
                if (in_array($path, $movedAside, true)) {
                    $paths[$half][basename($path)] = $rollbackDirectory
                        .DIRECTORY_SEPARATOR.'previous-'.basename($path);
                }
            }
        }

        return $paths;
    }

    /**
     * The target and the sidecars that belong to it, target first and duplicates dropped.
     *
     * Only files that exist are returned. A database that was cleanly closed has no WAL, and asking
     * the mover to move one that is not there would be a refusal rather than a restore.
     *
     * @param  array{target: string, companions: list<string>}  $half
     * @return list<string>
     */
    private function filesOf(array $half): array
    {
        $files = [$half['target']];

        foreach ($half['companions'] as $companion) {
            if (! in_array($companion, $files, true) && $this->mover->exists($companion)) {
                $files[] = $companion;
            }
        }

        return $files;
    }
}
