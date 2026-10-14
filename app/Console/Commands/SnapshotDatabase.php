<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
        // Checked before anything is written, because the refusal is the useful part.
        //
        // VACUUM cannot run inside a transaction, and SQLite reports that as a generic SQL
        // error with no mention of transactions. A caller that wraps this in one, which is a
        // normal thing to do to a queued job, would get an opaque failure rather than being told
        // what is wrong. It also makes the command untestable under `RefreshDatabase`, which
        // begins a transaction, so the guard is load bearing in two directions.
        if (DB::transactionLevel() > 0) {
            $this->components->error(
                'A snapshot cannot be taken inside a transaction. Run this outside one, which is '
                .'what a plain command invocation does.'
            );

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

        $target = $directory.DIRECTORY_SEPARATOR.self::PREFIX.gmdate('Ymd-His').'.sqlite';

        try {
            $this->vacuumInto($target);
        } catch (Throwable $e) {
            // Refuse rather than leave a partial file that looks like a backup. An incomplete
            // snapshot discovered during a restore is worse than no snapshot, because it is
            // found at the moment it is needed.
            @unlink($target);

            $this->components->error('The snapshot failed and nothing was written: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! is_file($target) || filesize($target) === 0) {
            @unlink($target);
            $this->components->error('The snapshot produced no file.');

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
     * The one statement this command exists for.
     *
     * The target has to not exist. SQLite refuses to overwrite, which is convenient: it means a
     * same-second second run cannot quietly replace a good snapshot with a worse one.
     *
     * @throws Throwable
     */
    private function vacuumInto(string $target): void
    {
        // The path goes in as a bound parameter rather than interpolated, even though it is
        // ours. A path containing an apostrophe is legal on every platform this ships to, and
        // interpolating one is a syntax error in a backup command, which is the worst place to
        // have one.
        DB::select('VACUUM INTO ?', [$target]);
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
