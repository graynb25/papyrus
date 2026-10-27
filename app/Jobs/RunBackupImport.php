<?php

namespace App\Jobs;

use App\Console\Commands\ImportBackup;
use App\Import\BackupImporter;
use App\Import\BackupReaders;
use App\Import\ImportRefused;
use App\Models\ImportRun;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Runs one backup import for one reader.
 *
 * ## Why a job, when the command deliberately is not one
 *
 * {@see ImportBackup} argues at length for staying a command: slicing
 * 1,277 series into jobs exists to survive a worker timeout, and the ledger makes an
 * interrupted run resumable anyway. All of that holds for a reader watching a terminal.
 *
 * It does not hold for a page. `max_execution_time` is 0 here, so PHP will not cut an inline
 * import off, but the request is eleven minutes long and the browser, the reverse proxy and
 * Herd's own FastCGI timeout all will, at their own undisclosed moments. A page cannot poll a
 * request that never comes back, so a run started inline would look identical to a run that
 * never started.
 *
 * ## The queue worker is a precondition, and this class cannot supply one
 *
 * A dispatch to a queue nobody is serving produces a job that sits in `jobs` forever. That is
 * not hypothetical here: it is why "Check 867 series" reported success and did nothing, and
 * the same trap would swallow this. So the job records when it started, and the page that
 * dispatched it says so plainly when a run has sat untouched, naming the command to fix it,
 * rather than showing an empty progress bar indefinitely.
 *
 * The alternative of running inline in slices was rejected for a concrete reason: every slice
 * would re-read and re-parse the whole backup. The measured file is 39.36MB, and a slice
 * bounded at 25 series would pay that parse 51 times.
 */
class RunBackupImport implements ShouldQueue
{
    use Queueable;

    /**
     * One import is one job, and it is allowed to be slow.
     *
     * `CheckLibrary` re-books itself in slices because it must not hold a worker. This one must:
     * the whole point is that a reader gets one progress number for the whole library rather
     * than 51 partial ones, and a job that is killed at the worker's timeout leaves a run row
     * reading `running` with no way to tell that from a run in progress.
     */
    public int $timeout = 3600;

    /**
     * Two attempts rather than the default of one.
     *
     * Safe because the importer is idempotent through the ledger: a retry re-reads the file,
     * finds every series already written, and skips it. A run that died halfway is the exact
     * case a retry is for. It is not safe for the command, which is run by a person who can
     * see what happened and press return, so the command keeps the shell default.
     *
     * @var list<int>
     */
    public array $backoff = [30];

    public function __construct(
        public readonly int $userId,
        public readonly string $path,
        public readonly int $limit = 0,
        public readonly bool $installExtensions = true,
        public readonly bool $inspectOnly = false,
        public readonly bool $force = false,
        /**
         * The name the reader saw in the file picker, when the file came through one.
         *
         * Null for a server-side path, where the path's own name is the honest label.
         */
        public readonly ?string $originalName = null,
    ) {}

    public function handle(BackupImporter $importer): void
    {
        $user = User::find($this->userId);

        if ($user === null) {
            // The account was deleted between dispatch and execution. Nothing to write to, and
            // failing loudly would fill `failed_jobs` with a job that could never succeed.
            Log::info('A queued import named a user that no longer exists.', [
                'user_id' => $this->userId,
                'path' => basename($this->path),
            ]);

            return;
        }

        /*
         * Which paths an account may name.
         *
         * A path is read by the queue worker, so on a shared install it would let a reader
         * ask the server to read any file its account can read. The owner keeps the
         * large-backup route. A reader may only name a file this app itself stored under
         * `imports`, which is the only place an upload can land.
         *
         * The rule is repeated here, after dispatch, because the account's role can change
         * between the page and the worker, and a queued payload is not a permission.
         */
        if (! $user->isInstallOwner() && ! $this->isUnderImports($this->path)) {
            $this->refuse("There is no file named {$this->fileLabel()} that this account can import.");

            return;
        }

        if (! is_file($this->path)) {
            /*
             * The file can be gone before the job runs, and it usually is: the page accepts a
             * path, and whatever wrote the backup there may be cleaned up in between. A missing
             * file is a refusal with a sentence attached, which is what {@see ImportRefused}
             * means, so it is recorded the same way a format problem is rather than being left
             * as an exception in `failed_jobs` that a page has no way to read.
             *
             * The sentence names the file, not the path. The history and the page are visible
             * to every reader on this install, and a server path says where this machine keeps
             * things.
             */
            $this->refuse("There is no file named {$this->fileLabel()} when the import started.");

            return;
        }

        /*
         * The inspect and the import share one guard, which they did not used to.
         *
         * `is_file()` catches a path that has gone, but not one that exists and is not a
         * backup this server can read: `BackupReaders::for()` raises `ImportRefused` for that,
         * and the inspect call sat outside the try, so the refusal escaped as an uncaught
         * exception and the reader saw nothing at all. Caught by moving the branch inside,
         * rather than by a second catch that would have had to remember to be kept in step.
         */
        try {
            if ($this->inspectOnly) {
                $this->inspect($user);

                return;
            }

            $importer->import(
                user: $user,
                path: $this->path,
                limit: $this->limit,
                installExtensions: $this->installExtensions,
                force: $this->force,
                fileName: $this->fileLabel(),
            );
        } catch (ImportRefused $e) {
            /*
             * The importer records its own refusal on its own run row, so there is nothing to
             * add there. An inspect has no run yet, so it records its own.
             */
            if ($this->inspectOnly) {
                $this->refuse($e->getMessage());
            }
        } catch (\Throwable $e) {
            /*
             * Mark whatever run the importer had reached, then let the exception continue.
             *
             * The importer writes its run row at the end, so a throw anywhere before that
             * leaves a row reading `running` with `finished_at` null. That is the state a
             * polling page cannot interpret: it is indistinguishable from a run in progress,
             * and it is the state that makes "nothing happened" the only thing the page can
             * honestly say. `BackupImporter::import()` marks its own row `complete`, so a row
             * still reading `running` when the job dies is, by elimination, a run that died.
             *
             * Rethrown rather than swallowed so `failed_jobs` keeps the trace, which is the
             * only place the stack survives.
             */
            $this->markDeadRun($e);

            throw $e;
        }
    }

    /**
     * Read the file and say what is in it, writing nothing.
     *
     * Delegates to the inspect command, as the command's own `--dry-run` does, so a page and a
     * shell cannot describe the same file differently.
     */
    private function inspect(User $user): void
    {
        $backup = app(BackupReaders::class)->for($this->path)->read($this->path);

        $series = $backup->series->count();
        $chapters = $backup->series->sum(fn ($s): int => $s->chapters->count());
        $read = $backup->readChapterCount();

        ImportRun::createFor($user, [
            'format' => $backup->format,
            'schema_version' => $backup->schemaVersion,
            'state' => ImportRun::COMPLETE,
            'file_name' => $this->fileLabel(),
            'series_in_file' => $backup->totalInFile,
            'library_series' => $series,
            'chapters' => $chapters,
            'started_at' => now(),
            'finished_at' => now(),
            'progress_note' => sprintf(
                'A %s backup, %s. It holds %s series in your library across %s chapters, '
                .'and %s of those chapters are marked read.',
                $backup->format,
                $backup->schemaVersion === null
                        ? 'which states no format version'
                        : 'format version '.number_format($backup->schemaVersion),
                number_format($series),
                number_format($chapters),
                number_format($read),
            ),
        ]);
    }

    private function refuse(string $reason): void
    {
        ImportRun::createFor($this->userId, [
            'format' => 'unknown',
            'state' => ImportRun::REFUSED,
            'file_name' => $this->fileLabel(),
            'refusal_reason' => $reason,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    /**
     * Whether a path names a file inside this app's own imports directory.
     *
     * A prefix is not containment: `imports-evil` starts with `imports`, so a string
     * comparison would hand a reader a sibling directory. Both sides are canonicalised and
     * the result must sit below the directory separator. A leaf that no longer exists is
     * resolved through its parent, which is exactly the missing-upload case, so a reader
     * learns "missing" rather than "outside" for a file this app stored.
     */
    private function isUnderImports(string $path): bool
    {
        $root = realpath(Storage::disk('local')->path('imports'));

        if ($root === false) {
            return false;
        }

        $candidate = realpath($path);

        if ($candidate === false) {
            $parent = realpath(dirname($path));

            if ($parent === false) {
                return false;
            }

            $candidate = $parent.DIRECTORY_SEPARATOR.basename($path);
        }

        $root = rtrim($root, '/\\');
        $candidate = rtrim($candidate, '/\\');

        if (PHP_OS_FAMILY === 'Windows') {
            $root = strtolower($root);
            $candidate = strtolower($candidate);
        }

        return $candidate !== $root && str_starts_with($candidate, $root.DIRECTORY_SEPARATOR);
    }

    /**
     * The name a reader recognises, rather than the key the file was stored under.
     *
     * Laravel names an upload after its guessed MIME type, so a Tachimanga `.tmb` (a ZIP)
     * became a random `.zip` name. That name is only ever written to `file_name` and
     * rendered; it never becomes a filesystem path. The path's own name is the fallback
     * for the server-path route and for any caller that has no client name.
     *
     * Not `displayName()`: that is a public method on Laravel's queue contract, and a
     * private override of it fataled every queued run with "Call to private method
     * RunBackupImport::displayName() from scope Illuminate\Queue\Queue".
     */
    private function fileLabel(): string
    {
        $name = $this->originalName;

        if ($name !== null && trim($name) !== '') {
            return basename(str_replace('\\', '/', $name));
        }

        return basename(str_replace('\\', '/', $this->path));
    }

    /**
     * Close out the run the importer left open when it threw.
     */
    private function markDeadRun(\Throwable $e): void
    {
        $run = ImportRun::query()->forUser($this->userId)->latest('id')->first();

        if ($run === null || $run->state !== ImportRun::RUNNING) {
            return;
        }

        $run->update([
            'state' => ImportRun::FAILED,
            'finished_at' => now(),
            'refusal_reason' => $e->getMessage(),
            'progress_note' => 'This run stopped on an error: '.$e->getMessage(),
        ]);
    }
}
