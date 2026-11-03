<?php

namespace App\Jobs;

use App\Backup\BackupLibrary;
use App\Backup\LiveRestore;
use App\Backup\LiveRestoreResult;
use App\Backup\ReaderHalfRefused;
use App\Jobs\Concerns\RecordsRunProgress;
use App\Models\LiveRestoreRun;
use App\Suwayomi\SuwayomiException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Papyrus\Launcher\BackupPair;
use Throwable;

/**
 * Runs one live restore for whoever pressed a row's Restore action, writing the row the
 * page polls.
 *
 * ## Why the job owns the row
 *
 * The exact shape {@see RunLiveBackup} carries, for the mirrored reason: the pair this run
 * puts back belongs to no one reader, {@see LiveRestoreRun} deliberately
 * carries no ownership shape, and the job is the single place the row's life begins and
 * ends. A reader who pressed and is gone by execution time changes nothing about what
 * happens to the install.
 *
 * ## The two failure families, and the one in flight state between them
 *
 * A refusal from {@see LiveRestore} with nothing yet restored, {@see SuwayomiException}
 * from the manga half or a reader half verification, or a reader half refusal before the
 * manga half was ever sent: the row says REFUSED with the sentence, because the restores
 * that change nothing end as refusals, the way an expected outcome never belongs in
 * `failed_jobs`.
 *
 * A reader half refusal *after* the manga half is restored is the run's one in flight
 * state: the server has already restored its side, the reader half is exactly what it
 * was, and the run rethrows so the queue's own retry (backoff 30) picks the swap up once
 * the transaction or the lock it refused is over. A retry re-verifies and puts the whole
 * pair back from the same files, which is why the retry needs nothing special to be
 * correct: the pair is on disk, both halves verified, and the job's attempt writes its
 * own row so the first attempt's refusal is history rather than a claim about the second.
 *
 * ## The row's one honest answer to a foreign writer
 *
 * The queue, the sessions, the cache and the run ledgers all keep their rows in this one
 * database beside the reader half the swap moves, and a foreign write transaction held
 * anywhere in the queue worker answers Laravel's own writes with SQLite's
 * `database is locked` after the connection's ten second busy window. Measured in the
 * job suite with the lock sitting over the swap.
 *
 * The answer is split at the one point it is expensive. The run row is created before
 * anything is restored, and a create that a foreign lock refused ends the attempt right
 * there: nothing is restored without a receipt, and the queue's own retry opens the run
 * again. The writes after creation, a stage note or the refusal's sentence, belong to a
 * run that is going ahead anyway and describe its own progress, so a foreign lock on one
 * of those is deferred instead: logged, and the receipt's truth is delivered by the next
 * attempt or the run's own completion, which the reader half's swap preserves along with
 * the rest of the running app's surfaces.
 */
class RunLiveRestore implements ShouldQueue
{
    use Queueable;
    use RecordsRunProgress;

    /**
     * One restore is one job, and it is allowed to be slow, the same ceiling its backup
     * sibling carries: the whole point is one number for one press, and a job killed at
     * its timeout leaves the row reading running with no way to tell that apart.
     */
    public int $timeout = 3600;

    /**
     * Two attempts rather than the default of one, because of exactly the state named
     * above: a reader half refusal after the manga half has settled is the case a second
     * attempt exists for. The backup job's own backoff, carried over.
     *
     * @var list<int>
     */
    public $backoff = [30];

    public int $tries = 2;

    public function __construct(
        public readonly int $userId,
        public readonly string $token,
    ) {}

    public function handle(LiveRestore $restore): void
    {
        /*
         * The reader who pressed can be gone by the time the worker picks the job up. The
         * shared branch logs it and leaves the attribution out; the restore still goes,
         * because the pair belongs to the install and not to whoever pressed.
         */
        $pressedBy = $this->presser();

        $library = BackupLibrary::forThisInstall();
        $manifest = $library->directory().DIRECTORY_SEPARATOR.'backup-'.$this->token.'.json';

        /*
         * The pair is read from its manifest, the one reader of that format: an unreadable
         * or mismatched manifest is a failed row with the refusal's own words carried into
         * the note, not a silent no-op.
         */
        try {
            $pair = BackupPair::read($manifest, $library->directory());
        } catch (Throwable $e) {
            /*
             * Reads before any row exists yet. Refused is the honest state anyway: nothing
             * can be restored from a manifest that disagrees with itself.
             */
            $this->writeReceipt(function () use ($pressedBy, $e): void {
                LiveRestoreRun::create([
                    'started_by_user_id' => $pressedBy?->id,
                    'state' => LiveRestoreRun::REFUSED,
                    'token' => $this->token,
                    'progress_stage' => null,
                    'progress_note' => null,
                    'refusal_reason' => $e->getMessage(),
                    'started_at' => now(),
                    'finished_at' => now(),
                ]);
            }, "writing a pair's refusal of itself before any run row exists");

            return;
        }

        /*
         * The receipt comes before anything is restored. A create refused by a foreign write
         * transaction, the lock shape the swap itself refuses with, ends the attempt here
         * with the refusal live: the queue's own retry opens the run again, and restoring
         * suffered nothing because nothing had started. The exception passes through raw,
         * rather than being named a second time at this layer.
         */
        $run = $this->openRun(LiveRestoreRun::class, $pressedBy, [
            'token' => $this->token,
            'progress_stage' => 'starting',
            'progress_note' => 'Starting the restore',
        ]);

        /*
         * Whether the manga half was already restored when a refusal arrived. The stage
         * note the run reaches first after the server's import answers is the reader
         * swap, so this flag turns on precisely between the two halves.
         */
        $mangaHalfIsRestored = false;

        try {
            $result = $restore->run(
                $pair,
                function (string $stage, string $words) use ($run, &$mangaHalfIsRestored): void {
                    if ($stage === 'reader_swap') {
                        $mangaHalfIsRestored = true;
                    }

                    $this->noteProgress($run, ['progress_stage' => $stage, 'progress_note' => $words], "the run's stage note");
                },
            );

            $this->complete($run, $result);
        } catch (SuwayomiException $e) {
            /*
             * Refused with the reader half untouched, because it moves after the server
             * answers. The last stage note describes work that did not happen, so it goes.
             */
            $this->refuseRun($run, $e, "a server refusal's own sentence on the run row");

            return;
        } catch (ReaderHalfRefused $e) {
            if ($mangaHalfIsRestored) {
                /*
                 * The in flight state: the server's side is restored, the reader's side is
                 * exactly what it was, and the queue's own retry finishes the pair. The
                 * note has to say both halves' truth or a reader staring at a failed row
                 * could conclude their library is unrestored when half of it already is.
                 *
                 * The retry is deliberately a full restore: the manga half is fetched and
                 * sent again, then the reader half is swapped. Skipping the half from the
                 * receipt would save that work, but the receipt is written after refusals
                 * and is not yet trustworthy enough to decide a half is complete. The
                 * duplicate work is bounded by the job timeout, and a wrong skip is worse
                 * than a slow retry. Accepted for this release; COR-09.
                 */
                $this->writeReceipt(static function () use ($run, $e): void {
                    $run->update([
                        'state' => LiveRestoreRun::FAILED,
                        'progress_stage' => $run->progress_stage,
                        'refusal_reason' => $e->getMessage(),
                        'progress_note' => 'The server\'s manga half is restored. The reader half refused its swap, '
                            .'and the restore run\'s own retry takes it again in a few seconds. '
                            .'The refusal said: '.$e->getMessage(),
                        'finished_at' => now(),
                    ]);
                }, "the both halves' truth on the run row");

                throw $e;
            }

            /*
             * Refused before anything was restored at all. Same shape as the refusal
             * above precisely because nothing about the install changed.
             */
            $this->refuseRun($run, $e, "a reader refusal's own sentence on the run row, nothing having moved");

            return;
        } catch (Throwable $e) {
            /*
             * A dead run without a name to its failure, record it honestly then rethrow
             * so `failed_jobs` keeps the trace.
             */
            $this->failRun($run, $e, 'restore', "an unnamed failure's own sentence on the run row");

            throw $e;
        }
    }

    /**
     * Write one piece of the receipt, whatever it is made of.
     *
     * A stage note or a refusal sentence rides along with work that is going ahead
     * anyway, so a foreign lock over it is not worth undoing the run for, and the next
     * attempt or the run's own completion delivers the truth the row was there to carry.
     * Every other failure is the receipt itself failing and passes through.
     */
    protected function writeReceipt(callable $write, string $what): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            if (! $this->isLockRefusal($e)) {
                throw $e;
            }

            Log::warning("The restore deferred {$what}: the database is busy with another writer.", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The job suite's measured lock shape, and only that shape.
     */
    private function isLockRefusal(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'SQLSTATE[HY000]')
            && str_contains($e->getMessage(), 'database is locked');
    }

    /**
     * Stamp the row with what the restore produced, once the run's stage notes have
     * already delivered the closing sentence to {@see $progress_note}.
     */
    private function complete(LiveRestoreRun $run, LiveRestoreResult $result): void
    {
        $this->writeReceipt(static function () use ($run, $result): void {
            $run->update([
                'state' => LiveRestoreRun::COMPLETE,
                'progress_stage' => 'done',
                'token' => $result->token,
                'series_states' => $result->seriesStates,
                'chapter_states' => $result->chapterStates,
                'suwayomi_version' => $result->suwayomiVersion,
                'manga_summary' => $result->mangaSummary,
                'restore_summary' => $result->restoreSummary,
                'finished_at' => now(),
            ]);
        }, "the run's own closing receipt");
    }
}
