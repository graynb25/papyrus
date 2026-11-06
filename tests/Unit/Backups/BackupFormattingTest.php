<?php

namespace Tests\Unit\Backups;

use App\Backup\RunOutcome;
use App\Live\Backups\BackupFormatting;
use App\Models\LiveBackupRun;
use App\Models\LiveRestoreRun;
use App\Support\Locale;
use Carbon\CarbonImmutable;
use Papyrus\Launcher\RunRecord;
use Tests\TestCase;

/**
 * The backups page's pure rules, exercised without mounting the page.
 *
 * Every case here is a boundary the page used to carry inside the Livewire component:
 * the byte units and their decimal, whole elapsed seconds, the verdict word for each
 * launcher state, the palette, the server's state vocabulary, and the refusal that has
 * no timeline behind it.
 */
class BackupFormattingTest extends TestCase
{
    private BackupFormatting $formatting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formatting = new BackupFormatting;
    }

    public function test_bytes_are_read_in_the_pages_own_units(): void
    {
        $this->assertSame('0 B', $this->formatting->humanBytes(0));
        $this->assertSame('16 B', $this->formatting->humanBytes(16));
        $this->assertSame('105 B', $this->formatting->humanBytes(105));
        $this->assertSame('1023 B', $this->formatting->humanBytes(1023));
        $this->assertSame('1 KB', $this->formatting->humanBytes(1024));
        $this->assertSame('1.5 KB', $this->formatting->humanBytes(1536));
        $this->assertSame('1 MB', $this->formatting->humanBytes(1048576));
        $this->assertSame('3 MB', $this->formatting->humanBytes(3 * 1048576));
        $this->assertSame('10 MB', $this->formatting->humanBytes(10 * 1048576));
        $this->assertSame('1 GB', $this->formatting->humanBytes(1024 * 1024 * 1024));
    }

    public function test_spanish_bytes_use_the_spanish_decimal_separator(): void
    {
        Locale::apply('es');

        $this->assertSame('1,5 KB', $this->formatting->humanBytes(1536));
        $this->assertSame('1 KB', $this->formatting->humanBytes(1024));
    }

    public function test_elapsed_time_keeps_whole_seconds_and_names_the_wait(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00'));

        $this->assertSame('34 seconds so far', $this->formatting->elapsedTime(now()->subSeconds(34)));
        $this->assertSame(
            '34 seconds so far',
            $this->formatting->elapsedTime(now()->subSeconds(34)->subMilliseconds(900)),
            'a fraction says more about the render than the backup',
        );
        $this->assertSame('1 minute 1 second so far', $this->formatting->elapsedTime(now()->subSeconds(61)));
        $this->assertSame('0 seconds so far', $this->formatting->elapsedTime(now()->addSeconds(5)));
    }

    public function test_verdict_tones_are_the_palette_the_page_has_always_used(): void
    {
        $this->assertSame(
            'border-red-200 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-200',
            $this->formatting->verdictTone('red'),
        );
        $this->assertSame(
            'border-green-200 bg-green-50 text-green-900 dark:border-green-900 dark:bg-green-950 dark:text-green-200',
            $this->formatting->verdictTone('green'),
        );
        $this->assertSame(
            'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200',
            $this->formatting->verdictTone('amber'),
        );
        $this->assertSame(
            'border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200',
            $this->formatting->verdictTone('blue'),
        );
        $this->assertSame(
            'border-gray-200 bg-gray-50 text-gray-900 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-100',
            $this->formatting->verdictTone('anything-else'),
        );
    }

    public function test_last_run_verdict_word_reads_the_launcher_and_live_rows(): void
    {
        $this->assertSame('', $this->formatting->lastRunVerdictWord(null));

        $this->assertSame(
            'Finished',
            $this->formatting->lastRunVerdictWord($this->event($this->outcome('finished'))),
        );
        $this->assertSame(
            'Failed',
            $this->formatting->lastRunVerdictWord($this->event($this->outcome('failed'))),
        );
        $this->assertSame(
            'Failed',
            $this->formatting->lastRunVerdictWord($this->event($this->outcome('stalled'))),
        );
        $this->assertSame(
            'Running',
            $this->formatting->lastRunVerdictWord($this->event($this->outcome('running'))),
        );

        $this->assertSame('Refused', $this->formatting->lastRunVerdictWord([
            'at' => now(),
            'tone' => 'orange',
            'label' => 'Refused',
            'summary' => 'The run refused.',
            'outcome' => null,
            'row' => new LiveBackupRun(['state' => LiveBackupRun::REFUSED]),
        ]));

        $this->assertSame('', $this->formatting->lastRunVerdictWord([
            'at' => now(),
            'tone' => 'zinc',
            'label' => '',
            'summary' => '',
            'outcome' => null,
            'row' => null,
        ]));
    }

    public function test_restore_state_words_keep_an_unknown_name(): void
    {
        $expected = [
            'IDLE' => 'waiting to start',
            'RESTORING_META' => 'reading the backup',
            'RESTORING_CATEGORIES' => 'restoring categories',
            'RESTORING_MANGA' => 'restoring series',
            'RESTORING_SETTINGS' => 'restoring settings',
            'SUCCESS' => 'finished',
            'FAILURE' => 'failed',
        ];

        foreach ($expected as $state => $words) {
            $this->assertSame($words, $this->formatting->restoreStateWords($state));
        }

        $this->assertSame(
            'RESTORING_SOMETHING_NEW',
            $this->formatting->restoreStateWords('RESTORING_SOMETHING_NEW'),
        );
    }

    public function test_a_refused_run_hides_its_timeline_only_before_it_started(): void
    {
        $this->assertTrue($this->formatting->runWasRefusedBeforeStarting(
            new LiveBackupRun(['state' => LiveBackupRun::REFUSED, 'progress_stage' => null]),
        ));
        $this->assertFalse($this->formatting->runWasRefusedBeforeStarting(
            new LiveBackupRun(['state' => LiveBackupRun::REFUSED, 'progress_stage' => 'version']),
        ));
        $this->assertTrue($this->formatting->runWasRefusedBeforeStarting(
            new LiveRestoreRun(['state' => LiveRestoreRun::REFUSED, 'progress_stage' => null]),
        ));
        $this->assertFalse($this->formatting->runWasRefusedBeforeStarting(
            new LiveBackupRun(['state' => LiveBackupRun::COMPLETE, 'progress_stage' => null]),
        ));
        $this->assertFalse($this->formatting->runWasRefusedBeforeStarting(
            new LiveBackupRun(['state' => LiveBackupRun::RUNNING, 'progress_stage' => null]),
        ));
    }

    /**
     * @return array{at: CarbonImmutable, tone: string, label: string, summary: string, outcome: RunOutcome|null, row: LiveBackupRun|null}
     */
    private function event(?RunOutcome $outcome): array
    {
        return [
            'at' => now(),
            'tone' => 'green',
            'label' => 'This backup finished.',
            'summary' => '',
            'outcome' => $outcome,
            'row' => null,
        ];
    }

    private function outcome(string $state): RunOutcome
    {
        $stages = [];

        foreach (RunRecord::SNAPSHOT_STAGES as $stage) {
            $stages[$stage] = ['state' => 'done', 'attempts' => 1, 'message' => null];
        }

        if ($state === 'failed') {
            $stages['copying_manga_half'] = [
                'state' => 'failed',
                'attempts' => 1,
                'message' => 'Suwayomi is still running after thirty seconds',
            ];
        }

        if ($state === 'stalled' || $state === 'running') {
            $stages['copying_manga_half'] = [
                'state' => 'running',
                'attempts' => 1,
                'message' => 'waiting for Suwayomi to go',
            ];
        }

        $path = $this->scratchPath('papyrus-formatting', 'run.json');

        file_put_contents($path, (string) json_encode([
            'version' => 1,
            'operation' => 'snapshot',
            'token' => '20261014-203857',
            'started_at' => gmdate('c', $state === 'stalled' ? time() - 600 : time() - 60),
            'stage_order' => RunRecord::SNAPSHOT_STAGES,
            'stages' => $stages,
        ], JSON_PRETTY_PRINT));

        $outcome = RunOutcome::fromRecord($path);
        $this->assertInstanceOf(RunOutcome::class, $outcome);

        return $outcome;
    }
}
