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

        if (! is_file($this->path)) {
            /*
             * The file can be gone before the job runs, and it usually is: the page accepts a
             * path, and whatever wrote the backup there may be cleaned up in between. A missing
             * file is a refusal with a sentence attached, which is what {@see ImportRefused}
             * means, so it is recorded the same way a format problem is rather than being left
             * as an exception in `failed_jobs` that a page has no way to read.
             */
            $this->refuse("There is no file at {$this->path} when the import started.");

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
            'file_name' => basename($this->path),
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
            'file_name' => basename($this->path),
            'refusal_reason' => $reason,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
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
