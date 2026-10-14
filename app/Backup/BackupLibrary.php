<?php

namespace App\Backup;

use Illuminate\Support\Facades\File;
use Papyrus\Launcher\Restorer;

/**
 * Where this install keeps its backups, and what is in them.
 *
 * ## What this reads
 *
 * Two things on disk, both written by the launcher rather than by the application:
 *
 * | | Written by | What it is |
 * | --- | --- | --- |
 * | `backup-<token>.json` and the two files beside it | `papyrus snapshot` | a **pair**: both databases, and a manifest binding them |
 * | `run-<token>.json` | `papyrus snapshot` | what that run was doing, stage by stage, as it happened |
 *
 * The run record exists because Papyrus has to be stopped to be backed up, so anything streaming
 * progress would be served by the thing being stopped. The launcher is still running, so the launcher
 * writes each stage down, and this is where it is read back.
 *
 * ## Why nothing here decides whether a backup is good
 *
 * Because the restore does, and it does it properly. {@see Restorer} verifies both
 * digests immediately before it touches anything and rolls back if either is wrong. This class reports
 * what it can see from a page: whether the files are still there, whether their digests still match
 * what the manifest describes, and what the reader's half held at the time.
 *
 * That is a weaker check, and it is honest about being one. A digest that matches says the file is
 * the one the manifest describes. It does not say the pair is restorable, because only attempting it
 * says that.
 *
 * ## Why the digest check is here at all
 *
 * Because a backup folder can be edited, copied half of, or filled by a disk that failed silently.
 * Measured on the reader's own install, the two files a manifest describes are 4.9MB and 52MB, and a
 * digest over each is fast enough to do on a page load. Without it, a page listing backups would list
 * a truncated manga database beside the others with nothing to say about it.
 */
final class BackupLibrary
{
    public function __construct(private readonly string $directory) {}

    /**
     * The backups folder for this install.
     *
     * Beside the database, because that is the location the install has already proved writable and
     * the launcher writes there too. Not the folder the daily reader-half snapshots go in: that is a
     * different thing, and mixing the two is how somebody tries to restore a half.
     */
    public static function forThisInstall(): self
    {
        $database = (string) config('database.connections.sqlite.database');

        // The test suite runs on `:memory:`, where `dirname()` is `.`. Reading the checkout's own
        // working directory would list whatever a developer happened to leave there.
        if ($database === '' || str_contains($database, ':memory:')) {
            return new self(base_path('backups'));
        }

        return new self(dirname($database).DIRECTORY_SEPARATOR.'backups');
    }

    /**
     * Every pair in the folder, newest first.
     *
     * A manifest with a file missing is still listed, because a reader deciding what to keep needs to
     * know it exists and is incomplete rather than to find it missing and wonder.
     *
     * @return list<BackupSummary>
     */
    public function pairs(): array
    {
        $found = [];

        foreach ((array) glob($this->directory.DIRECTORY_SEPARATOR.'backup-*.json') as $manifest) {
            if (! is_string($manifest)) {
                continue;
            }

            $found[] = BackupSummary::fromManifest($manifest, $this->directory);
        }

        usort($found, static fn (BackupSummary $a, BackupSummary $b): int => $b->token <=> $a->token);

        return $found;
    }

    /**
     * The most recent run, whatever it did.
     *
     * Newest by token rather than by mtime, for the same reason the restorer prunes by the name's
     * timestamp: copying a file changes its mtime, and the token is what the run actually carries.
     */
    public function lastRun(): ?RunOutcome
    {
        $found = [];

        foreach ((array) glob($this->directory.DIRECTORY_SEPARATOR.'run-*.json') as $record) {
            if (! is_string($record)) {
                continue;
            }

            $outcome = RunOutcome::fromRecord($record);

            if ($outcome !== null) {
                $found[] = $outcome;
            }
        }

        usort($found, static fn (RunOutcome $a, RunOutcome $b): int => $b->token <=> $a->token);

        return $found[0] ?? null;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Whether anything has ever been written here.
     *
     * So the page can say "none yet" rather than showing an empty table, which reads as a folder that
     * failed rather than an install nobody has backed up.
     */
    public function isEmpty(): bool
    {
        return $this->pairs() === [] && $this->lastRun() === null;
    }

    /**
     * Anything the launcher left that is not part of a pair or a run record.
     *
     * Rollback directories, mostly, which are what a restore keeps in case it was the wrong backup.
     * Named here rather than hidden so a reader who wonders where the disk space went can find the
     * answer, and so an accidental second copy is visible rather than invisible.
     *
     * @return list<array{name: string, bytes: int}>
     */
    public function leftovers(): array
    {
        $out = [];

        foreach ((array) glob($this->directory.DIRECTORY_SEPARATOR.'*') as $entry) {
            if (! is_string($entry) || ! is_dir($entry)) {
                continue;
            }

            if (! str_starts_with(basename($entry), 'rollback-')) {
                continue;
            }

            $out[] = [
                'name' => basename($entry),
                'bytes' => $this->sizeOf($entry),
            ];
        }

        return $out;
    }

    /**
     * The bytes under a directory, counted rather than taken from the filesystem.
     *
     * `File::size()` on a directory returns nothing useful, so a rollback folder would be listed as
     * zero and a reader would conclude it takes no space.
     */
    private function sizeOf(string $directory): int
    {
        $total = 0;

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $total += $file->getSize();
        }

        return $total;
    }
}
