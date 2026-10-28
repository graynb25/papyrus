<?php

namespace App\Jobs;

use App\Console\Commands\ImportBackup;
use App\Import\BackupImporter;
use App\Import\BackupReaders;
use App\Import\ExtensionInstaller;
use App\Import\ImportRefused;
use App\Import\ImportUploads;
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
        /**
         * The name the reader saw in the file picker, when the file came through one.
         *
         * Null for a server-side path, where the path's own name is the honest label.
         */
        public readonly ?string $originalName = null,
        /**
         * Read the file, resolve its packages, and write a review instead of importing.
         *
         * The page uses this when the reader has extensions switched on: nothing installs
         * until they have seen which stores the packages come from and ticked the ones they
         * accept. Every other route keeps today's behaviour.
         */
        public readonly bool $surveyForConfirmation = false,
        /**
         * Continue the awaiting run with this id rather than create a new one.
         *
         * Set by the page after the reader confirms a review. The path comes from the run row,
         * not from the request, so a crafted payload cannot point the worker at another file.
         */
        public readonly ?int $reuseRunId = null,
    ) {}

    public function handle(BackupImporter $importer, ?ImportUploads $uploads = null): void
    {
        $uploads ??= app(ImportUploads::class);

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
         * A confirmation continues a run that already carries its file. It gets its own branch
         * before any request path is looked at, because the path it must use is the one the
         * survey wrote on the row, not one a queued payload supplied.
         */
        if ($this->reuseRunId !== null) {
            $this->resume($importer, $user, $uploads);

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
        if (! $user->isInstallOwner() && ! $uploads->owns($this->path)) {
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

            if ($this->surveyForConfirmation) {
                $this->survey($user, $uploads);

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
     * Read the file and write the review a reader must answer before anything installs.
     *
     * ## Why this is a run, not a session
     *
     * The answer has to survive a closed modal, a reload, and a worker that takes a minute to
     * start. The run row is already the durable record of an attempt, so the review lives
     * beside its state. `file_path` is what lets a confirm, possibly hours later and from
     * history, read the same file again.
     *
     * ## Refusals delete the upload
     *
     * A file this server cannot read will not become readable by being kept. Leaving it behind
     * would pin a reader's tens of megabytes until the expiry sweep, so the refusal removes it
     * and clears the path with it. A thrown failure is different: the file is kept because the
     * review may be retried, and the hourly sweep is the backstop.
     */
    private function survey(User $user, ImportUploads $uploads): void
    {
        $run = ImportRun::createFor($user, [
            'format' => 'unknown',
            'state' => ImportRun::RUNNING,
            'file_name' => $this->fileLabel(),
            'file_path' => $this->path,
            'started_at' => now(),
            'progress_note' => 'Reading the file.',
        ]);

        try {
            $backup = app(BackupReaders::class)->for($this->path)->read($this->path);
        } catch (ImportRefused $e) {
            $run->update([
                'state' => ImportRun::REFUSED,
                'refusal_reason' => $e->getMessage(),
                'file_path' => null,
                'finished_at' => now(),
            ]);

            $uploads->forget($this->path);

            return;
        } catch (\Throwable $e) {
            $run->update([
                'state' => ImportRun::FAILED,
                'refusal_reason' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            throw $e;
        }

        $review = app(ExtensionInstaller::class)->review($backup->sources);

        $run->update([
            'format' => $backup->format,
            'schema_version' => $backup->schemaVersion,
            'series_in_file' => $backup->totalInFile,
            'library_series' => $backup->series->count(),
            'chapters' => $backup->series->sum(fn ($s): int => $s->chapters->count()),
            /*
             * The limit and force choices travel with the review, because the confirm request
             * only says "this run", not what the reader picked before the review opened. Stored
             * rather than re-sent, so a crafted request cannot turn a trial run into a full one.
             */
            'review' => $review + [
                'limit' => max(0, $this->limit),
                'force' => $this->force,
            ],
            'state' => ImportRun::AWAITING_CONFIRMATION,
            'finished_at' => now(),
            'progress_note' => 'Waiting for you to choose which extension stores to trust.',
        ]);
    }

    /**
     * Continue an awaiting review once the reader has answered it.
     *
     * ## The claim is conditional, not read-then-write
     *
     * Two clicks, or a click and a queue retry, must not both start an import. The state moves
     * from `awaiting` to `running` in one conditional update, and only the caller whose update
     * changed a row proceeds. The same update is the lock: on SQLite this is the atomic write
     * the database offers, and it is narrower than a transaction held across a queue dispatch.
     *
     * ## The path comes from the row
     *
     * A confirm payload carries a run id, never a path. The row already says which file the
     * review read, so there is nothing for a crafted request to point somewhere else. Trust is
     * not read from the payload either: {@see BackupImporter::installExtensions()} reads the
     * reader's rows when it installs.
     */
    private function resume(BackupImporter $importer, User $user, ImportUploads $uploads): void
    {
        $run = ImportRun::query()->forUser($user->id)->find($this->reuseRunId);

        if ($run === null || ! $run->isAwaitingConfirmation()) {
            /*
             * Already answered, discarded, expired, or another reader's. There is nothing to
             * do and nothing to say to a queue log: the page has the row and will show it.
             */
            return;
        }

        $path = $run->file_path;

        if ($path === null || $path === '') {
            $this->refuseRun($run, "There is no file named {$this->fileLabel()} any more.");

            return;
        }

        /*
         * The account's role and the file's location are checked again, not trusted from the
         * survey. A reader who was the owner then and is not now must not read an arbitrary
         * server path through a review that was opened while they were.
         */
        if (! $user->isInstallOwner() && ! $uploads->owns($path)) {
            $this->refuseRun($run, "There is no file named {$this->fileLabel()} that this account can import.");

            return;
        }

        if (! is_file($path)) {
            $this->refuseRun($run, "There is no file named {$this->fileLabel()} when the import started.");

            return;
        }

        $claimed = ImportRun::query()
            ->forUser($user->id)
            ->whereKey($run->id)
            ->where('state', ImportRun::AWAITING_CONFIRMATION)
            ->update([
                'state' => ImportRun::RUNNING,
                'started_at' => now(),
                'finished_at' => null,
                'refusal_reason' => null,
                'progress_note' => null,
            ]);

        if ($claimed !== 1) {
            return;
        }

        $run = ImportRun::query()->forUser($user->id)->findOrFail($run->id);
        $review = $run->review ?? [];

        try {
            $importer->import(
                user: $user,
                path: $path,
                limit: (int) ($review['limit'] ?? 0),
                installExtensions: $this->installExtensions,
                force: (bool) ($review['force'] ?? false),
                fileName: $run->file_name,
                run: $run,
            );
        } catch (ImportRefused $e) {
            // The importer wrote the refusal on this same row. The file is no more readable
            // than it was, so it goes with it.
            $uploads->forget($path);

            return;
        } catch (\Throwable $e) {
            $this->markDeadRun($e);

            throw $e;
        }

        /*
         * The import has read the file for the last time. Keeping it would leave the reader's
         * backup on disk for no purpose, and the expiry sweep only knows about reviews that
         * are still awaiting an answer.
         */
        $uploads->forget($path);
    }

    /**
     * Close a review that cannot proceed, with the sentence the history will show.
     */
    private function refuseRun(ImportRun $run, string $reason): void
    {
        $run->update([
            'state' => ImportRun::REFUSED,
            'refusal_reason' => $reason,
            'finished_at' => now(),
        ]);
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
