<?php

namespace App\Jobs\Concerns;

use App\Live\Contracts\LiveRun;
use App\Models\LiveBackupRun;
use App\Models\LiveExportRun;
use App\Models\LiveRestoreRun;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The receipt lifecycle every live run job shares.
 *
 * Opening the row, writing a stage note, refusing with nothing changed and failing with
 * the stack rethrown are the same four moves in the backup, restore and export jobs. What
 * each job does between them stays in the job: the provider calls, the staging sweep, the
 * retry policy and the different completion columns are not flattened here.
 *
 * Restore overrides {@see writeReceipt()} because its swap can meet a foreign SQLite
 * writer. The default is a direct write; the override defers a locked note instead of
 * undoing a run that is already going ahead.
 *
 * @property-read int $userId
 */
trait RecordsRunProgress
{
    /**
     * The reader who pressed, or null when the account was gone before the worker started.
     *
     * One log line serves all three jobs. A backup or restore keeps going with the
     * attribution left out, because the row belongs to the install. An export cannot merge
     * an arrangement that no longer exists, so its job ends the attempt after this returns
     * null and writes no row.
     */
    protected function presser(): ?User
    {
        $presser = User::find($this->userId);

        if ($presser === null) {
            Log::info('A live run was queued for a reader who no longer exists.', ['user_id' => $this->userId]);
        }

        return $presser;
    }

    /**
     * Open the running row before the work starts.
     *
     * @template TRun of LiveBackupRun|LiveRestoreRun|LiveExportRun
     *
     * @param  class-string<TRun>  $model
     * @param  array<string, mixed>  $attributes
     * @return TRun
     */
    protected function openRun(string $model, ?User $presser, array $attributes): LiveBackupRun|LiveRestoreRun|LiveExportRun
    {
        /** @var TRun $run */
        $run = $model::create([
            'started_by_user_id' => $presser?->id,
            'state' => LiveRun::RUNNING,
            'started_at' => now(),
            ...$attributes,
        ]);

        return $run;
    }

    /**
     * One stage note onto the row.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function noteProgress(
        LiveBackupRun|LiveRestoreRun|LiveExportRun $run,
        array $attributes,
        string $what = "the run's progress note",
    ): void {
        $this->writeReceipt(static function () use ($run, $attributes): void {
            $run->update($attributes);
        }, $what);
    }

    /**
     * A refusal with nothing changed: the sentence stays, the note about work that did not
     * happen goes, and the run ends without entering failed_jobs.
     */
    protected function refuseRun(
        LiveBackupRun|LiveRestoreRun|LiveExportRun $run,
        Throwable $e,
        string $what = "the run's refusal",
    ): void {
        $this->writeReceipt(static function () use ($run, $e): void {
            $run->update([
                'state' => LiveRun::REFUSED,
                'progress_note' => null,
                'refusal_reason' => $e->getMessage(),
                'finished_at' => now(),
            ]);
        }, $what);
    }

    /**
     * A failure the row names, with the trace still to be rethrown by the caller.
     *
     * `$noun` is the job's own word for its product in "This run/restore stopped". It is a
     * parameter rather than a guess because the restore says "restore" and the other two
     * say "run".
     */
    protected function failRun(
        LiveBackupRun|LiveRestoreRun|LiveExportRun $run,
        Throwable $e,
        string $noun,
        string $what = "the run's failure",
    ): void {
        $this->writeReceipt(static function () use ($run, $e, $noun): void {
            $run->update([
                'state' => LiveRun::FAILED,
                'refusal_reason' => $e->getMessage(),
                'progress_note' => "This {$noun} stopped on an error: ".$e->getMessage(),
                'finished_at' => now(),
            ]);
        }, $what);
    }

    /**
     * Write one piece of the receipt.
     *
     * The default is a direct write. Restore overrides this with its measured lock
     * deferral; keeping the seam here means its note and refusal paths still pass through
     * one implementation without the backup or export inheriting a lock policy they do
     * not have.
     */
    protected function writeReceipt(callable $write, string $what): void
    {
        $write();
    }
}
