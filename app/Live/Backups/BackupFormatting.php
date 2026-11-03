<?php

namespace App\Live\Backups;

use App\Backup\RunOutcome;
use App\Live\Contracts\LiveRun;
use App\Live\RunPresenter;
use App\Models\LiveBackupRun;
use App\Models\LiveRestoreRun;
use App\Support\Duration;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The backups page's pure rules: bytes, elapsed seconds, verdict words and tones.
 *
 * None of these reads the database or the component's state. Each takes the value it
 * formats, which is what makes the boundaries testable without a Livewire mount.
 */
final class BackupFormatting
{
    /**
     * The bytes the way this page's own list already reads sizes.
     *
     * A value below ten in a unit above bytes is rounded to one decimal, so 1.5 KB keeps
     * the half; a whole value still prints without a trailing zero, as this page has
     * always read it.
     */
    public function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return round($value, $value < 10 && $unit > 0 ? 1 : 0).' '.$units[$unit];
    }

    /**
     * The amount of time a run has been going, in the reader's words.
     *
     * Carbon returns fractional seconds here. A fraction says more about the render than
     * the backup, and changes every poll, so the page keeps whole elapsed seconds instead.
     */
    public function elapsedTime(CarbonInterface $startedAt): string
    {
        $seconds = (int) floor(max(0, $startedAt->diffInSeconds(now())));

        return Duration::forHumans($seconds).' '.__('so far');
    }

    /**
     * The border, background and text classes a verdict is read in, the palette the block
     * has always carried: a run that did not go through cannot be styled like one that
     * did, however it stopped.
     */
    public function verdictTone(string $tone): string
    {
        return match ($tone) {
            'red' => 'border-red-200 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-200',
            'green' => 'border-green-200 bg-green-50 text-green-900 dark:border-green-900 dark:bg-green-950 dark:text-green-200',
            'amber' => 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200',
            'blue' => 'border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200',
            default => 'border-gray-200 bg-gray-50 text-gray-900 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-100',
        };
    }

    /**
     * The one word the "Last run" line and the modal header read a run by.
     *
     * A live row's label was built by {@see RunPresenter::stateLabel()} when the event
     * was assembled, so this reads the word the page already carries. Only the launcher
     * record needs its own three way reading, which is the same reading the modal uses.
     *
     * @param  array{at: CarbonImmutable, tone: string, label: string, summary: string, outcome: RunOutcome|null, row: LiveRun|null}|null  $latest
     */
    public function lastRunVerdictWord(?array $latest): string
    {
        if ($latest === null) {
            return '';
        }

        if ($latest['outcome'] !== null) {
            $outcome = $latest['outcome'];

            if ($outcome->stalled() || $outcome->failed()) {
                return __('Failed');
            }

            if (! $outcome->finished()) {
                return __('Running');
            }

            return __('Finished');
        }

        if ($latest['row'] !== null) {
            return $latest['label'];
        }

        return '';
    }

    /**
     * The server's own restore states, in this app's words, with an unknown name carried
     * as the name it is rather than a meaning invented for it.
     */
    public function restoreStateWords(string $state): string
    {
        return match ($state) {
            'IDLE' => __('waiting to start'),
            'RESTORING_META' => __('reading the backup'),
            'RESTORING_CATEGORIES' => __('restoring categories'),
            'RESTORING_MANGA' => __('restoring series'),
            'RESTORING_SETTINGS' => __('restoring settings'),
            'SUCCESS' => __('finished'),
            'FAILURE' => __('failed'),
            default => $state,
        };
    }

    /**
     * Whether a refusal landed before the run reached its first checkpoint.
     *
     * The Refused badge reads the run's own REFUSED state, and `progress_stage` is the
     * row's own record of the stage it reached. A refusal with no stage behind it has no
     * timeline to draw; one that did reach a stage keeps the progress it earned.
     */
    public function runWasRefusedBeforeStarting(LiveBackupRun|LiveRestoreRun $run): bool
    {
        return ($run->state === LiveBackupRun::REFUSED || $run->state === LiveRestoreRun::REFUSED)
            && $run->progress_stage === null;
    }
}
