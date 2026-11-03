<?php

namespace Tests\Feature;

use App\Live\Contracts\LiveRun;
use App\Live\RunPresenter;
use App\Models\LiveBackupRun;
use App\Models\LiveExportRun;
use App\Models\LiveRestoreRun;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The one reading of a live run's state.
 *
 * The three tables carry different receipts, but a reader sees the same badges, words,
 * poll decision and silence rule on every card. This test walks every state for every
 * type, then the two sentences that genuinely name the product: what a silent run left
 * behind, and what a complete row says when its job wrote no closing note.
 */
class RunPresenterTest extends TestCase
{
    private const TYPES = [
        LiveBackupRun::class,
        LiveRestoreRun::class,
        LiveExportRun::class,
    ];

    #[DataProvider('states')]
    public function test_every_state_is_presented_the_same_way_for_every_run_type(
        string $model,
        string $state,
        string $badge,
        string $label,
        bool $polls,
        string $summary,
    ): void {
        $run = $this->runFor($model, $state);
        $presenter = new RunPresenter;

        $this->assertInstanceOf(LiveRun::class, $run);
        $this->assertSame($state, $run->state());
        $this->assertSame($state === LiveRun::RUNNING, $run->isRunning());
        $this->assertSame($badge, $presenter->badgeColour($run));
        $this->assertSame($label, $presenter->stateLabel($run));
        $this->assertSame($polls, $presenter->shouldPoll($run));
        $this->assertSame($summary, $presenter->summary($run));
        $this->assertFalse($presenter->isAbandoned($run), 'a fresh row is never abandoned, whatever state it holds');
    }

    /**
     * @return array<string, array{string, string, string, string, bool, string}>
     */
    public static function states(): array
    {
        $expected = [
            LiveRun::RUNNING => ['blue', 'Running', true, 'Working through the manga half.'],
            LiveRun::COMPLETE => ['green', 'Finished', false, 'The run wrote its closing sentence.'],
            LiveRun::FAILED => ['red', 'Stopped', false, 'This run stopped on an error: the disk filled up.'],
            LiveRun::REFUSED => ['orange', 'Refused', false, 'The run refused: nothing changed.'],
            'queued' => ['zinc', 'queued', false, 'queued'],
        ];

        $cases = [];

        foreach (self::TYPES as $model) {
            foreach ($expected as $state => [$badge, $label, $polls, $summary]) {
                $cases[$model.' '.$state] = [$model, $state, $badge, $label, $polls, $summary];
            }
        }

        return $cases;
    }

    #[DataProvider('abandoned')]
    public function test_a_running_row_that_went_silent_reads_as_stopped_for_every_run_type(
        string $model,
        string $summary,
    ): void {
        $run = $this->runFor($model, LiveRun::RUNNING, stale: true);
        $presenter = new RunPresenter;

        $this->assertTrue($presenter->isAbandoned($run));
        $this->assertSame('orange', $presenter->badgeColour($run));
        $this->assertSame('Stopped', $presenter->stateLabel($run));
        $this->assertFalse($presenter->shouldPoll($run), 'a silent row must not keep the page polling');
        $this->assertSame($summary, $presenter->summary($run));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function abandoned(): array
    {
        return [
            'backup' => [
                LiveBackupRun::class,
                'This backup stopped before it finished. Nothing from the unfinished copy was kept.',
            ],
            'restore' => [
                LiveRestoreRun::class,
                'This restore stopped before it finished. The backup on disk was left unchanged.',
            ],
            'export' => [
                LiveExportRun::class,
                'This run stopped without finishing. Its staging was swept, and nothing half written was left where a reader looks for a product.',
            ],
        ];
    }

    #[DataProvider('completeFallbacks')]
    public function test_a_complete_row_without_a_closing_note_falls_back_to_its_own_product(
        string $model,
        string $fallback,
    ): void {
        $run = new $model;
        $run->forceFill([
            'state' => LiveRun::COMPLETE,
            'progress_note' => null,
            'updated_at' => now(),
        ]);

        $this->assertSame($fallback, (new RunPresenter)->summary($run));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function completeFallbacks(): array
    {
        return [
            'backup' => [LiveBackupRun::class, 'Your backup is ready to restore.'],
            'restore' => [LiveRestoreRun::class, 'Your library is back.'],
            'export' => [LiveExportRun::class, 'The file is in the exports folder.'],
        ];
    }

    public function test_the_backup_summary_still_translates_notes_from_before_the_current_vocabulary(): void
    {
        $run = new LiveBackupRun;
        $run->forceFill([
            'state' => LiveRun::COMPLETE,
            'progress_note' => '1 series states and 1 chapter states against Suwayomi v3.4.2366, taken without stopping anything',
            'updated_at' => now(),
        ]);

        $presenter = new RunPresenter;

        $this->assertSame(
            'Backup complete: 1 series and 1 chapter saved while Papyrus stayed open. Manga server v3.4.2366.',
            $presenter->summary($run),
        );

        $refused = new LiveBackupRun;
        $refused->forceFill([
            'state' => LiveRun::REFUSED,
            'refusal_reason' => 'The downloaded manga half is a real backup of a library that holds nothing, so the run was refused.',
            'updated_at' => now(),
        ]);

        $this->assertSame(
            'The manga server answered, but it has no series to save. The backup was refused and nothing was changed.',
            $presenter->summary($refused),
        );
    }

    public function test_every_run_model_shares_the_state_vocabulary_and_the_presser_relation(): void
    {
        foreach (self::TYPES as $model) {
            /** @var LiveBackupRun|LiveRestoreRun|LiveExportRun $run */
            $run = new $model;
            $run->forceFill(['state' => LiveRun::RUNNING]);

            $this->assertSame(LiveRun::RUNNING, $model::RUNNING);
            $this->assertSame(LiveRun::COMPLETE, $model::COMPLETE);
            $this->assertSame(LiveRun::FAILED, $model::FAILED);
            $this->assertSame(LiveRun::REFUSED, $model::REFUSED);

            $this->assertSame(LiveRun::RUNNING, $run->state());
            $this->assertTrue($run->isRunning());
            $this->assertSame('started_by_user_id', $run->startedBy()->getForeignKeyName());
        }
    }

    /**
     * A row as the presenter reads it: the state, the note or refusal that state quotes,
     * and a timestamp the silence rule can compare.
     */
    private function runFor(string $model, string $state, bool $stale = false): LiveBackupRun|LiveRestoreRun|LiveExportRun
    {
        /** @var LiveBackupRun|LiveRestoreRun|LiveExportRun $run */
        $run = new $model;

        $run->forceFill([
            'state' => $state,
            'progress_note' => match ($state) {
                LiveRun::RUNNING => 'Working through the manga half.',
                LiveRun::COMPLETE => 'The run wrote its closing sentence.',
                LiveRun::FAILED => 'This run stopped on an error: the disk filled up.',
                default => null,
            },
            'refusal_reason' => $state === LiveRun::REFUSED ? 'The run refused: nothing changed.' : null,
            'updated_at' => $stale ? now()->subSeconds(RunPresenter::STUCK_SECONDS + 1) : now(),
        ]);

        return $run;
    }
}
