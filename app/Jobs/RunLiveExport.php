<?php

namespace App\Jobs;

use App\Export\ExportLibrary;
use App\Export\TachibkExport;
use App\Export\TachibkExportResult;
use App\Models\LiveExportRun;
use App\Models\User;
use App\Suwayomi\SuwayomiException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one outward `.tachibk` export for whoever pressed the button, writing the row the page polls.
 *
 * ## Why the job owns the row, and where the file is staged
 *
 * {@see RunLiveBackup} argues both at length, kept short here rather than restated: the page
 * cannot poll a request eleven minutes long, so the run goes to the queue the worker serves
 * and the row starts in {@see handle()}. The product is staged inside
 * {@see ExportLibrary}'s own folder and renamed only once the merged file is whole, for the
 * same reason the live backup stages beside its destination: a half written `.tachibk` that
 * looks like a product is the empty library lie, and a rename inside one folder is atomic.
 *
 * ## One reader, and that is the difference from the backup
 *
 * The backup runs even for a presser who is gone, because the pair it takes belongs to no
 * one reader. The export is the reverse: it merges one reader's own read marks and shelves
 * into the file, so the run carries their user id into the exporter and a vanished reader
 * means there is no arrangement to merge. Like {@see RunBackupImport}, the job ends its
 * attempt with a log line and no row, because a cancelled export could never succeed and
 * `failed_jobs` should not carry it.
 *
 * ## The two failure families
 *
 * A refusal {@see SuwayomiException}, which {@see TachibkExport} throws with nothing merged
 * and no file renamed, so the row says so and the job ends, the way the backup's refusals
 * are recorded rather than putting an expected outcome into `failed_jobs`. Anything else is
 * a failure the row records as `failed`, with the rethrow to follow, because `failed_jobs`
 * is the only place the stack survives.
 */
class RunLiveExport implements ShouldQueue
{
    use Queueable;

    /**
     * One export is one job, and it is allowed to be slow.
     *
     * The same reason {@see RunLiveBackup} carries its hour: the run asks the server for a
     * fresh export of a whole library, reads it, and merges the reader's ledger onto it, and
     * a job killed at the worker's timeout leaves the run row reading `running` with no way
     * to tell that from a run in progress.
     */
    public int $timeout = 3600;

    /**
     * Two attempts rather than the default of one.
     *
     * Safe because the export leaves staging only when it succeeds: a retry stages and
     * merges from the start, over the same ledger the first attempt read, and a run that
     * died halfway merged nothing outward. Each attempt writes its own row, so a retry
     * leaves the first one answering for itself instead of rewriting history.
     *
     * @var list<int>
     */
    public array $backoff = [30];

    public function __construct(
        public readonly int $userId,
    ) {}

    public function handle(TachibkExport $export): void
    {
        $presser = User::find($this->userId);

        if ($presser === null) {
            // The account was deleted between dispatch and execution. Nothing to merge, and
            // failing loudly would fill `failed_jobs` with a job that could never succeed.
            // No row goes down either: a run row that never named its reader would hang on
            // the page with no future state to reach.
            Log::info('A queued export named a user that no longer exists.', ['user_id' => $this->userId]);

            return;
        }

        $run = LiveExportRun::create([
            'started_by_user_id' => $presser->id,
            'state' => LiveExportRun::RUNNING,
            'progress_note' => 'Starting the export',
            'started_at' => now(),
        ]);

        /*
         * The run's own lines are full sentences, written for whoever is watching, so they
         * go onto the row as they arrive and the page reads the row instead of translating
         * anything.
         */
        $library = ExportLibrary::forThisInstall();
        $staging = $library->staging();

        /*
         * Stale staging is removed before anything starts, and whole run means whole run on
         * any failure: a half finished export left behind would be a file that looks like a
         * product where the next reader looks for one.
         */
        $this->removeStaging($staging);
        File::ensureDirectoryExists($staging);

        $token = gmdate('Ymd-His');
        $staged = $staging.DIRECTORY_SEPARATOR.'tachibk-'.$token.'.tachibk';

        try {
            $result = $export->run(
                $presser,
                $staged,
                function (string $words) use ($run): void {
                    $run->update(['progress_note' => $words]);
                },
            );

            $this->deliver($run, $result, $library->directory(), basename($staged));
        } catch (SuwayomiException $e) {
            /*
             * The export rejected the work with nothing merged and nothing renamed. The last
             * stage note is about a file the row can no longer claim, so it goes, and the
             * refusal reason from the run itself is the sentence that stays.
             */
            $run->update([
                'state' => LiveExportRun::REFUSED,
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
                'state' => LiveExportRun::FAILED,
                'refusal_reason' => $e->getMessage(),
                'progress_note' => 'This run stopped on an error: '.$e->getMessage(),
                'finished_at' => now(),
            ]);

            throw $e;
        } finally {
            $this->removeStaging($staging);
        }
    }

    /**
     * Stamp the row with what the run produced, once the rename has put the merged file
     * where the page will serve it from and the exporter's own last note already sits in
     * {@see LiveExportRun::$progress_note}.
     *
     * `file_bytes` comes from the exporter's own measurement of the file that actually
     * landed in staging, which the rename carries unchanged.
     *
     * @throws \RuntimeException When a product of the same name is already there, because
     *                           the name comes from the clock: the first export is a file
     *                           worth keeping and the second is its own moment, and
     *                           overwriting one with the other answers neither reader.
     */
    private function deliver(LiveExportRun $run, TachibkExportResult $result, string $directory, string $name): void
    {
        $target = $directory.DIRECTORY_SEPARATOR.$name;

        if (is_file($target)) {
            throw new \RuntimeException("{$target} is already there, so a second export inside the same second would overwrite it. The run was stopped, and the product already recorded stands.");
        }

        if (! rename($result->file, $target)) {
            throw new \RuntimeException("The staged export refused its rename: {$result->file} to {$target}");
        }

        $run->update([
            'state' => LiveExportRun::COMPLETE,
            'file_name' => $name,
            'file_bytes' => $result->fileBytes,
            'series_in_file' => $result->seriesInFile,
            'series_planned' => $result->seriesPlanned,
            'series_matched' => $result->seriesMatched,
            'shelves_appended' => $result->shelvesAppended,
            'read_marks' => $result->readMarks,
            'skipped' => $result->skipped,
            'finished_at' => now(),
        ]);
    }

    private function removeStaging(string $staging): void
    {
        if (is_dir($staging)) {
            File::deleteDirectory($staging);
        }
    }
}
