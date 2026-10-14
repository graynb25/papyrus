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
     * @param  array{papyrus: string, suwayomi: string}  $live  The two files as they are now
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
        $movedAside = [];

        try {
            foreach (['papyrus', 'suwayomi'] as $half) {
                $this->mover->move($live[$half], $rollbackDirectory.DIRECTORY_SEPARATOR.'previous-'.basename($live[$half]));
                $movedAside[$half] = $live[$half];
            }

            foreach (['papyrus', 'suwayomi'] as $half) {
                $this->mover->copy($pair->pathOf($half), $live[$half]);
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
     * Both halves present, and both are what the manifest says they are.
     *
     * Touches nothing, and the ordering matters: this runs before the first move so a bad backup
     * costs the reader nothing but the time to read the message.
     *
     * @throws RestoreRefused
     */
    private function checkPairIsWhole(BackupPair $pair): void
    {
        foreach (['papyrus', 'suwayomi'] as $half) {
            $path = $pair->pathOf($half);

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
     * @param  array{papyrus: string, suwayomi: string}  $live
     *
     * @throws RestoreRefused
     */
    private function checkLandedMatchesManifest(BackupPair $pair, array $live): void
    {
        foreach (['papyrus', 'suwayomi'] as $half) {
            $expected = $half === 'suwayomi' ? $pair->suwayomiSha256 : $pair->papyrusSha256;

            if ($this->mover->digest($live[$half]) !== $expected) {
                throw (new RestoreRefused(sprintf(
                    'The %s half did not land intact. It has been taken back out again.',
                    $half === 'suwayomi' ? 'manga' : 'reader',
                )))->attempted($live[$half]);
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
     * @param  array<string, string>  $movedAside  Which halves were actually moved, so only those
     *                                             are put back. Moving a file that was never moved
     *                                             would destroy the very thing being restored.
     * @param  array{papyrus: string, suwayomi: string}  $live
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

        foreach (array_reverse(array_keys($movedAside), true) as $half) {
            $kept = $rollbackDirectory.DIRECTORY_SEPARATOR.'previous-'.basename($live[$half]);

            try {
                $this->mover->move($kept, $live[$half]);
            } catch (Throwable $e) {
                $problems[] = sprintf('%s: %s', $half, $e->getMessage());
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
     * @param  array<string, string>  $movedAside
     * @param  array{papyrus: string, suwayomi: string}  $live
     * @return array<string, string>
     */
    private function rollbackPaths(array $movedAside, array $live, string $rollbackDirectory): array
    {
        $paths = [];

        foreach (array_keys($movedAside) as $half) {
            $paths[$half] = $rollbackDirectory.DIRECTORY_SEPARATOR.'previous-'.basename($live[$half]);
        }

        return $paths;
    }
}
