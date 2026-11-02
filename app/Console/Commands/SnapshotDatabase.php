<?php

namespace App\Console\Commands;

use App\Backup\ReaderHalfCapture;
use App\Backup\ReaderHalfRefused;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Takes a consistent snapshot of this install's own database.
 *
 * ## Why this exists at all, given Suwayomi already backs up
 *
 * Because they are different databases holding different facts, and restoring only one gives a
 * reader a broken install.
 *
 * | | Held by |
 * | --- | --- |
 * | series, chapters, covers, sources | Suwayomi, as H2, on a live-file lock |
 * | read state per series and per chapter, shelves, settings, AniList link, import ledger | here, as SQLite |
 *
 * Suwayomi already takes a daily `.tachibk` with 14 day retention, and this project can read
 * those. What nothing else does is this side. Measured on the reader's install: **867
 * `series_states` and 21,447 `chapter_states` rows** that exist only in this file.
 *
 * So this is half of the backup story, and it is the half a reader cannot reconstruct.
 *
 * ## Why VACUUM INTO and not a file copy
 *
 * Because a copy of a database being written is not a backup. Copying `database.sqlite` while
 * the app is running can catch it mid-write and produce a file that opens and is short a page.
 * The symptom appears days later as a corrupt database, long after anyone remembers the backup
 * step.
 *
 * `VACUUM INTO 'file'` takes a consistent, compacted copy of an **open** database, in one
 * statement, without taking a lock that would block a reader and without anything having to be
 * stopped. It is the right tool and it is a single line.
 *
 * The general shape: **a backup of a live database is a database operation, not a file
 * operation.**
 */
class SnapshotDatabase extends Command
{
    protected $signature = 'papyrus:snapshot
        {--into= : Directory to write snapshots into. Defaults to the configured data directory}
        {--keep=14 : How many snapshots to keep}
        {--prune : Delete older snapshots beyond the retention, and take none}';

    protected $description = 'Take a consistent copy of this install\'s own database. This is one half of a backup, not a backup: `papyrus snapshot` takes one you can restore';

    /**
     * The name every half written by this command starts with.
     *
     * `reader-half`, not `papyrus`. This used to write `papyrus-20261013-031701.sqlite`, which reads
     * as a backup and sits exactly where a reader would look for one. It is not one and cannot be
     * restored, so the name now says what it is before anything else has to be read. See
     * {@see sayWhatThisIsAndIsNot()} for the full reason, which is worth more than the constant.
     */
    private const PREFIX = 'reader-half-';

    public function handle(): int
    {
        // Checked before anything is written, because the refusal is the useful part there
        // too: `sayWhatThisIsAndIsNot` and retention both touch the folder, and none of that
        // should happen for a call that is going to be refused. The guard's own reason is
        // written once, in the seam it shares with the in app backup.
        $capture = new ReaderHalfCapture;

        try {
            $capture->guard();
        } catch (ReaderHalfRefused $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $directory = $this->directory();

        try {
            File::ensureDirectoryExists($directory);
        } catch (Throwable $e) {
            $this->components->error("Could not create {$directory}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->prune($directory);

        if ($this->option('prune')) {
            $this->components->info('Pruned only. No snapshot taken.');

            return self::SUCCESS;
        }

        $stamp = now('UTC')->format('Ymd-His');
        $temporary = $this->temporaryTarget($directory);

        try {
            $capture->capture($temporary);
        } catch (Throwable $e) {
            // Refuse rather than leave a partial file that looks like a backup. An incomplete
            // snapshot discovered during a restore is worse than no snapshot, because it is
            // found at the moment it is needed. Only this run's temporary file is removed: a
            // snapshot that already owns a timestamped name is never this run's to delete.
            $this->discard($temporary);

            $this->components->error('The snapshot failed and nothing was written: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! is_file($temporary) || filesize($temporary) === 0) {
            $this->discard($temporary);
            $this->components->error('The snapshot produced no file.');

            return self::FAILURE;
        }

        /*
         * The suffix is chosen once and the rename goes straight to it. An existence check
         * followed by `rename()` is two decisions, and `rename()` overwrites on this platform,
         * so two runs that both saw a free name could still lose one snapshot. A name another
         * run cannot have chosen removes the window. The timestamp stays readable, and the
         * shape is the same for every snapshot, so `prune()` never mistakes a plain name from
         * an older version for a newer same-second file.
         */
        $target = $directory.DIRECTORY_SEPARATOR.self::PREFIX.$stamp.'-'.bin2hex(random_bytes(4)).'.sqlite';

        if (! @rename($temporary, $target)) {
            $this->discard($temporary);
            $this->components->error("The snapshot could not be moved into place: {$target}");

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Snapshot written: %s (%s)',
            basename($target),
            number_format((int) filesize($target)).' bytes',
        ));

        $this->sayWhatThisIsAndIsNot($directory);

        $this->prune($directory);

        return self::SUCCESS;
    }

    /**
     * A name no other run is writing to, for the capture to fill before verification.
     *
     * The leading dot and the `.tmp` ending keep this out of `prune()`'s
     * `reader-half-*.sqlite` glob, so a temporary is never counted as a snapshot and never
     * deleted by retention. The random part is what makes two runs in the same second safe:
     * `VACUUM INTO` refuses an existing target, and this loop only hands back a path that does
     * not exist, so anything later found there was made by this invocation and nothing else.
     */
    private function temporaryTarget(string $directory): string
    {
        do {
            $path = $directory.DIRECTORY_SEPARATOR.'.reader-half-'.bin2hex(random_bytes(8)).'.sqlite.tmp';
        } while (file_exists($path));

        return $path;
    }

    /**
     * Remove only the temporary file this invocation made.
     *
     * Never the timestamped name. That name may belong to a snapshot another run finished,
     * and deleting it on this run's failure was the data loss this method exists to stop.
     */
    private function discard(string $temporary): void
    {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }

    /**
     * Say, every single time, that this is one half and not a backup.
     *
     * ## Why this is not a footnote
     *
     * Because the name this used to write was `papyrus-20261013-031701.sqlite`, which reads as a
     * backup and sits beside the database where a reader would look for one. It is not one. It cannot
     * be restored: `BackupPair::read()` refuses a manifest naming one half, and for a very good
     * reason, because `series_states.suwayomi_manga_id` points at a row in Suwayomi's H2 file and a
     * reader half alone is a library with no library.
     *
     * Worse, this runs on a schedule. Measured on the reader's own install there were two of these
     * beside the database, `papyrus-20261013-031701.sqlite` and `papyrus-20261014-031700.sqlite`,
     * from the 03:17 job, and `Artisan::call()` discards what the command printed. So the arrangement
     * was: once a day, silently, produce a file that looks like a backup and cannot be restored. That
     * is the whole of "what a user does not know, they will think worked", running unattended.
     *
     * So the name changed to say what it is, the default folder is one whose name says what it is,
     * and the command says the rest. A reader who finds one of these should be able to work out from
     * the name alone that it is not the thing they want.
     *
     * The real answer is `papyrus snapshot`, which stops the install, copies both halves and writes
     * the manifest that binds them. That cannot be done from in here, which is why this command
     * exists at all: stopping the application would stop the scheduler that is running it.
     */
    private function sayWhatThisIsAndIsNot(string $directory): void
    {
        $this->newLine();

        $this->components->warn('This is one half of a backup, not a backup.');

        $this->line(sprintf('  It holds what you have read, your shelves and your settings, in %s.', $directory));
        $this->line('  It does not hold your manga, and on its own it cannot be restored: your library');
        $this->line('  points into the manga database, so one half without the other is a library that');
        $this->line('  reads as empty with nothing saying why.');
        $this->newLine();
        $this->line('  A backup you can actually restore is both halves together. To take one:');
        $this->line('    papyrus snapshot');
        $this->line('  Papyrus has to be stopped for that, which is why this command cannot do it.');
        $this->newLine();
    }

    /**
     * Delete snapshots past the retention, newest first.
     *
     * Retention rather than a count of files, so a reader who snapshots hourly and one who
     * snapshots daily both keep a sensible window. And a snapshot that fails to delete is
     * reported rather than ignored, because a directory that quietly grows forever is how a
     * reader's disk fills.
     *
     * Matches {@see PREFIX} only, so this can never delete a file it did not write. That is not
     * hypothetical: a snapshot folder is a reader's folder, and the older glob of `papyrus-*.sqlite`
     * would have matched a file this command never wrote if one had ever been put there.
     */
    private function prune(string $directory): void
    {
        $keep = max(0, (int) $this->option('keep'));

        $snapshots = glob($directory.DIRECTORY_SEPARATOR.self::PREFIX.'*.sqlite') ?: [];

        if (count($snapshots) <= $keep) {
            return;
        }

        // Newest first, by the timestamp in the name rather than by mtime. Copying a snapshot
        // around changes its mtime, and pruning by mtime would then delete the wrong one.
        rsort($snapshots, SORT_STRING);

        foreach (array_slice($snapshots, $keep) as $stale) {
            if (@unlink($stale)) {
                $this->line('pruned '.basename($stale));
            } else {
                $this->components->warn('Could not delete '.basename($stale).', it may be in use');
            }
        }
    }

    /**
     * Where snapshots go.
     *
     * Defaults to a folder beside the database whose name says what is in it, because the location
     * is already proven writable by the install and because a file written somewhere else is a file
     * that stops being written the first time that somewhere is not writable.
     *
     * The folder is not called `backups`. `backups/` is where `papyrus snapshot` puts whole pairs, and
     * mixing a half into a folder of pairs is how somebody ends up trying to restore one. Measured on
     * the reader's install before this change: two files named `papyrus-*.sqlite` sitting beside the
     * database where a reader would look for a backup, neither of them restorable.
     */
    private function directory(): string
    {
        $into = $this->option('into');

        if (is_string($into) && $into !== '') {
            return $into;
        }

        $database = (string) config('database.connections.sqlite.database');

        // `:memory:` is what the test suite runs on, and `dirname()` of that is `.`, which
        // would put every snapshot in the process working directory.
        if ($database === '' || str_contains($database, ':memory:')) {
            return base_path('database');
        }

        return dirname($database).DIRECTORY_SEPARATOR.'reader-snapshots';
    }
}
