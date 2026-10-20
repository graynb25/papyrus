<?php

namespace App\Jobs;

use App\Backup\LiveBackup;
use App\Backup\LiveBackupResult;
use App\Backup\ReaderHalfRefused;
use App\Models\LiveBackupRun;
use App\Models\User;
use App\Suwayomi\SuwayomiException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
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
         * The import job cancels when that happens because its rows would write under an
         * owner that no longer exists. A backup has no such owner, so the run still goes
         * and the row starts with its attribution left out. That the exception a notice
         * named might reach no one is still worth a line in the log, because the row
         * alone cannot say who was there to see it.
         */
        $pressedBy = User::find($this->userId);

        if ($pressedBy === null) {
            Log::info('Running a live backup for a reader who no longer exists.', ['user_id' => $this->userId]);
        }

        $run = LiveBackupRun::create([
            'started_by_user_id' => $pressedBy?->id,
            'state' => LiveBackupRun::RUNNING,
            'include_downloads' => $this->includeDownloads,
            'progress_note' => 'Starting the run',
            'started_at' => now(),
        ]);

        try {
            /*
             * The run's own stage notes are full sentences, written for whoever is watching,
             * so they go onto the row as they arrive and the page reads the row instead of
             * translating anything.
             */
            $result = $backup->run(
                $this->includeDownloads,
                static function (string $stage, string $words) use ($run): void {
                    $run->update(['progress_note' => $words]);
                },
            );

            $this->complete($run, $result);
        } catch (SuwayomiException|ReaderHalfRefused $e) {
            /*
             * Run() rejected the work with nothing taken and nothing changed. The last stage
             * note is about work the row can no longer claim, so it goes, and the refusal
             * reason from the run itself is the sentence that stays.
             */
            $run->update([
                'state' => LiveBackupRun::REFUSED,
                'progress_note' => null,
                'refusal_reason' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            return;
        } catch (Throwable $e) {
            /*
             * The run died without a name to its failure. Rethrown rather than swallowed
             * so `failed_jobs` keeps the trace, which is the only place the stack survives.
             */
            $run->update([
                'state' => LiveBackupRun::FAILED,
                'refusal_reason' => $e->getMessage(),
                'progress_note' => 'This run stopped on an error: '.$e->getMessage(),
                'finished_at' => now(),
            ]);

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
