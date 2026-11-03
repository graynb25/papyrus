<?php

namespace App\Jobs;

use App\Backup\LiveBackup;
use App\Backup\LiveBackupResult;
use App\Backup\ReaderHalfRefused;
use App\Jobs\Concerns\RecordsRunProgress;
use App\Models\LiveBackupRun;
use App\Suwayomi\SuwayomiException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one live backup for whoever pressed the button, writing the row the page polls.
 *
 * ## Why the job owns the row
 *
 * {@see app\Jobs\RunBackupImport} creates its run rows through the importer. This one creates
 * its own at the start of {@see handle()}, because the pair this run protects belongs to no
 * one reader and {@see app\Models\LiveBackupRun} deliberately carries no ownership shape: the
 * job is the single place the row's life begins and ends.
 *
 * A dispatch to a queue nobody is serving produces a job that sits in `jobs` and a row that
 * does not exist yet. That is the standing precondition discussed in the import job, and the
 * page that dispatches this one names the idle command when nothing arrived, the way the
 * import page does.
 *
 * ## The two failure families
 *
 * A refusal {@see SuwayomiException} or {@see ReaderHalfRefused}, and {@see \app\Backup\LiveBackup}
 * throws both with the staging already swept: nothing was taken and nothing changed, so the
 * row says so and the job ends, the way an import answer diffs:
 * {@see RunBackupImport::refuse()} swallows its refusal rather than putting an
 * expected outcome into `failed_jobs`.
 *
 * Anything else is a failure the row records as `failed`, with the rethrow to follow, because
 * `failed_jobs` is the only place the stack survives.
 */
class RunLiveBackup implements ShouldQueue
{
    use Queueable;
    use RecordsRunProgress;

    /**
     * One backup is one job, and it is allowed to be slow.
     *
     * `CheckLibrary` re-books itself in slices because it must not hold a worker. This one
     * must hold one: the whole point is that a reader gets one number for one press, and a
     * job killed at the worker's timeout leaves the run row reading `running` with no way
     * to tell that from a run in progress.
     */
    public int $timeout = 3600;

    /**
     * Two attempts rather than the default of one.
     *
     * Safe because the backup is whole run or nothing: a retry starts by sweeping stale
     * staging, refuses a same second token, and renames only once the pair exists. A run
     * that died halfway is exactly the case a second attempt exists for. Each attempt
     * writes its own row, so a retry leaves the first one answering for itself instead of
     * rewriting history.
     *
     * Declared here rather than left to the launcher's `--tries=3`, which would otherwise
     * supply a third attempt the docblock above never promised.
     */
    public int $tries = 2;

    /**
     * The wait between those two attempts.
     *
     * @var list<int>
     */
    public array $backoff = [30];

    public function __construct(
        public readonly int $userId,
        public readonly bool $includeDownloads = false,
    ) {}

    public function handle(LiveBackup $backup): void
    {
        /*
         * The reader who pressed can be gone by the time the worker picks the job up.
         * The shared branch logs the orphaned press and leaves the attribution out. A
         * backup has no owner to cancel for: the pair belongs to the install, so the run
         * still goes and the log is the only place that might have been expected is said.
         */
        $pressedBy = $this->presser();

        $run = $this->openRun(LiveBackupRun::class, $pressedBy, [
            'include_downloads' => $this->includeDownloads,
            'progress_note' => 'Starting the run',
        ]);

        try {
            /*
            * The run's own stage notes are full sentences, written for whoever is watching.
            * The key travels with each sentence too: the modal can then show the reader where
            * the work has reached without guessing from the sentence it happens to say.
             */
            $result = $backup->run(
                $this->includeDownloads,
                function (string $stage, string $words) use ($run): void {
                    $this->noteProgress($run, ['progress_stage' => $stage, 'progress_note' => $words]);
                },
            );

            $this->complete($run, $result);
        } catch (SuwayomiException|ReaderHalfRefused $e) {
            /*
             * Run() rejected the work with nothing taken and nothing changed. The last stage
             * note is about work the row can no longer claim, so it goes, and the refusal
             * reason from the run itself is the sentence that stays.
             */
            $this->refuseRun($run, $e);

            return;
        } catch (Throwable $e) {
            /*
             * The run died without a name to its failure. Rethrown rather than swallowed
             * so `failed_jobs` keeps the trace, which is the only place the stack survives.
             */
            $this->failRun($run, $e, 'run');

            throw $e;
        }
    }

    /**
     * Stamp the row with what the run produced, once the run's stage notes have already
     * delivered the closing sentence to {@see $progress_note}.
     */
    private function complete(LiveBackupRun $run, LiveBackupResult $result): void
    {
        $run->update([
            'state' => LiveBackupRun::COMPLETE,
            'token' => $result->token,
            'series_states' => $result->seriesStates,
            'chapter_states' => $result->chapterStates,
            'suwayomi_version' => $result->suwayomiVersion,
            'downloads_bytes' => $result->downloadsBytes,
            'manga_summary' => $result->mangaSummary,
            'finished_at' => now(),
        ]);
    }
}
