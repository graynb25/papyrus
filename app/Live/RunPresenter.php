<?php

namespace App\Live;

use App\Live\Contracts\LiveRun;
use App\Models\LiveBackupRun;
use App\Models\LiveExportRun;
use App\Models\LiveRestoreRun;

/**
 * The one reading of a live run's state, shared by the backup, restore and export cards.
 *
 * Badge, label, poll decision and the abandoned test are identical for the three tables.
 * The summary is shared except for two sentences that genuinely name the product: what a
 * reader is told when a run went silent, and what a complete run says when the job wrote
 * no closing note. Those live in the small per-type matches at the bottom rather than in
 * a union that would quietly give a restore the backup's words.
 */
final class RunPresenter
{
    /**
     * How long a run may go without touching its row before the page calls it stopped.
     *
     * A worker killed at its timeout, an out of memory kill, or a machine that went down
     * all leave the row reading running with nothing behind it. The page treats silence
     * as the end of the run rather than repeating a claim that is no longer true.
     */
    public const STUCK_SECONDS = 300;

    /**
     * The badge colours the run states are read in, the import page's palette for them.
     */
    public function badgeColour(LiveRun $run): string
    {
        return match (true) {
            $this->isAbandoned($run) => 'orange',
            $run->state() === LiveRun::COMPLETE => 'green',
            $run->state() === LiveRun::RUNNING => 'blue',
            $run->state() === LiveRun::FAILED => 'red',
            $run->state() === LiveRun::REFUSED => 'orange',
            default => 'zinc',
        };
    }

    /**
     * What a run's state says to a reader, one word, with silence named as an end.
     */
    public function stateLabel(LiveRun $run): string
    {
        return match (true) {
            $this->isAbandoned($run) => __('Stopped'),
            $run->state() === LiveRun::COMPLETE => __('Finished'),
            $run->state() === LiveRun::RUNNING => __('Running'),
            $run->state() === LiveRun::FAILED => __('Stopped'),
            $run->state() === LiveRun::REFUSED => __('Refused'),
            default => $run->state(),
        };
    }

    /**
     * Whether the page should keep asking what is happening for this run.
     */
    public function shouldPoll(LiveRun $run): bool
    {
        return $run->isRunning() && ! $this->isAbandoned($run);
    }

    /**
     * What a run is saying, in the row's own sentences.
     *
     * The notes are written full by the job for exactly this surface, so this computes
     * less and quotes more: a finished run carries its own closing count sentence, a
     * refused one carries the sentence the run itself refused by, and a running one
     * carries its stage note.
     */
    public function summary(LiveRun $run): string
    {
        $summary = match (true) {
            $this->isAbandoned($run) => $this->abandonedSummary($run),
            $run->state() === LiveRun::REFUSED => (string) ($run->refusal_reason ?? __('No reason recorded.')),
            $run->state() === LiveRun::FAILED => (string) ($run->progress_note ?? __('No reason recorded.')),
            $run->state() === LiveRun::RUNNING => (string) ($run->progress_note ?? __('Starting.')),
            $run->state() === LiveRun::COMPLETE => $this->completeSummary($run),
            default => $run->state(),
        };

        /*
         * Only the backup wrote notes in the vocabulary this page carried before the live
         * backup existed. Reading them belongs to the backup's row alone; the restore and
         * export notes never need translating.
         */
        return $run instanceof LiveBackupRun ? $this->humanizeBackupSummary($summary) : $summary;
    }

    /**
     * Whether a run says it is running and has not been heard from in far too long.
     */
    public function isAbandoned(LiveRun $run): bool
    {
        return $run->isRunning()
            && $run->updated_at->diffInSeconds(now()) > self::STUCK_SECONDS;
    }

    /** Translate stored backup notes from before the reader-facing backup vocabulary existed. */
    public function humanizeBackupSummary(string $summary): string
    {
        if (str_contains($summary, 'downloaded manga half is a real backup of a library that holds nothing')) {
            return __('The manga server answered, but it has no series to save. The backup was refused and nothing was changed.');
        }

        if (preg_match('/^([\d,]+) series states and ([\d,]+) chapter states against Suwayomi (.+), taken without stopping anything$/', trim($summary), $matches) === 1) {
            $chapterWord = (int) str_replace(',', '', $matches[2]) === 1 ? __('chapter') : __('chapters');

            return __('Backup complete: :series series and :chapters :chapter_word saved while Papyrus stayed open. Manga server :version.', [
                'series' => $matches[1],
                'chapters' => $matches[2],
                'chapter_word' => $chapterWord,
                'version' => $matches[3],
            ]);
        }

        return $summary;
    }

    /**
     * What a silent run leaves behind, which differs by the product it was making.
     */
    private function abandonedSummary(LiveRun $run): string
    {
        return match (true) {
            $run instanceof LiveBackupRun => __('This backup stopped before it finished. Nothing from the unfinished copy was kept.'),
            $run instanceof LiveRestoreRun => __('This restore stopped before it finished. The backup on disk was left unchanged.'),
            $run instanceof LiveExportRun => __('This run stopped without finishing. Its staging was swept, and nothing half written was left where a reader looks for a product.'),
            default => __('This run stopped before it finished.'),
        };
    }

    /**
     * The sentence a complete run carries when its job wrote no closing note.
     *
     * The jobs write one, so this is the fallback for an older or interrupted row. Each
     * product names its own result rather than all three claiming a generic finish.
     */
    private function completeSummary(LiveRun $run): string
    {
        if ($run->progress_note !== null) {
            return (string) $run->progress_note;
        }

        return match (true) {
            $run instanceof LiveBackupRun => __('Your backup is ready to restore.'),
            $run instanceof LiveRestoreRun => __('Your library is back.'),
            $run instanceof LiveExportRun => __('The file is in the exports folder.'),
            default => __('Finished.'),
        };
    }
}
