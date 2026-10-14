<?php

namespace Tests\Feature;

use Papyrus\Launcher\RestoreRefused;
use Papyrus\Launcher\RunRecord;
use Tests\TestCase;

/**
 * The durable record of what a restore or a snapshot is doing.
 *
 * ## The failure this exists to make impossible
 *
 * "What a user does not know, they will think worked." That is a property of this file, not of the
 * screen that draws it, and these tests are about the file.
 *
 * The concrete ways a run can lie:
 *
 *   - nothing is written until the end, and the launcher is killed, so the file says nothing and the
 *     reader concludes nothing happened
 *   - a stage is left reading "running" by a dead process, and the reader watches a bar climb to
 *     nothing for ever
 *   - a retry is invisible, so "it failed once and worked" and "it worked" look the same
 *   - a run from last week renders as "restoring now"
 *   - a half written JSON file reads as no run at all, which is the original failure wearing a hat
 */
class RunRecordTest extends TestCase
{
    private string $workspace = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-run-'.bin2hex(random_bytes(6));

        mkdir($this->workspace, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->workspace.'/*') as $file) {
            if (is_string($file) && is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($this->workspace);

        parent::tearDown();
    }

    private function path(): string
    {
        return $this->workspace.DIRECTORY_SEPARATOR.'run.json';
    }

    private function begin(string $operation = 'restore', string $token = '20261014-031701'): RunRecord
    {
        return RunRecord::begin($this->path(), $operation, $token, gmdate('c'));
    }

    // ---------------------------------------------------------------------------------------------

    public function test_a_new_run_starts_with_every_stage_pending(): void
    {
        $view = $this->begin()->view();

        $this->assertCount(count(RunRecord::STAGES), $view['stages']);

        foreach ($view['stages'] as $stage) {
            $this->assertSame('pending', $stage['state'], $stage['stage'].' should start pending');
        }
    }

    /**
     * A stage is written when it STARTS, so a launcher killed mid-flight leaves evidence.
     *
     * This is the difference between "the reader is told nothing happened" and "the reader is told
     * where it got to". Written only on completion, a killed run leaves an empty file.
     */
    public function test_entering_a_stage_is_recorded_before_it_finishes(): void
    {
        $record = $this->begin();
        $record->enter('stopping', 'asking the launcher to stop everything');

        $view = $record->view();

        $stopping = $this->stage($view, 'stopping');

        $this->assertSame('running', $stopping['state']);
        $this->assertSame(1, $stopping['attempts']);
        $this->assertSame('asking the launcher to stop everything', $stopping['message']);
        $this->assertFalse($view['finished'], 'a run with a stage in flight is not finished');
    }

    public function test_a_finished_stage_says_so(): void
    {
        $record = $this->begin();
        $record->enter('copying_manga_half');
        $record->finish('copying_manga_half', '59,203,584 bytes, digest matches');

        $stage = $this->stage($record->view(), 'copying_manga_half');

        $this->assertSame('done', $stage['state']);
        $this->assertStringContainsString('digest matches', $stage['message']);
    }

    public function test_a_failed_stage_says_so_and_names_the_reason(): void
    {
        $record = $this->begin();
        $record->enter('copying_manga_half');
        $record->fail('copying_manga_half', 'Suwayomi is still running after thirty seconds');

        $view = $record->view();

        $this->assertSame('failed', $this->stage($view, 'copying_manga_half')['state']);
        $this->assertTrue($view['failed'], 'a view with a failed stage must say the run failed');
        $this->assertStringContainsString('still running', $this->stage($view, 'copying_manga_half')['message']);
    }

    /**
     * A retry is visible.
     *
     * "Tried twice and worked" is a different thing from "worked", and a reader who watched the first
     * attempt fail is entitled to know that something did.
     */
    public function test_a_retry_is_counted_and_visible(): void
    {
        $record = $this->begin();
        $record->enter('stopping');
        $record->fail('stopping', 'Suwayomi could not be stopped');
        $record->enter('stopping', 'trying again');
        $record->finish('stopping', 'stopped on the second attempt');

        $view = $record->view();
        $stage = $this->stage($view, 'stopping');

        $this->assertSame(2, $stage['attempts']);
        $this->assertTrue($stage['retried']);
        $this->assertContains('stopping', $view['retries']);
    }

    public function test_a_run_with_no_retries_reports_none(): void
    {
        $record = $this->begin();
        $record->enter('reading_backup');
        $record->finish('reading_backup');

        $this->assertSame([], $record->view()['retries']);
    }

    /**
     * A skipped stage is not a pending stage.
     *
     * Without it a snapshot that never copies the manga half would show a row that has read "pending"
     * since it began, which is a small lie told by leaving something out.
     */
    public function test_a_skipped_stage_does_not_read_as_pending(): void
    {
        $record = $this->begin('snapshot');
        $record->skip('copying_reader_half', 'this run only takes the manga half');

        $view = $record->view();

        $this->assertSame('skipped', $this->stage($view, 'copying_reader_half')['state']);
    }

    /**
     * A run that stopped being updated is stalled, not running for ever.
     *
     * A launcher that was killed leaves its last word as "running". Without this the reader watches a
     * bar that never finishes, which is the failure this file exists to prevent, reproduced exactly.
     */
    public function test_a_run_that_stopped_reporting_reads_as_stalled(): void
    {
        $record = RunRecord::begin($this->path(), 'restore', 't', gmdate('c', time() - 600));
        $record->enter('stopping');

        $view = $record->view();

        $this->assertTrue($view['stalled'], 'six minutes of silence is a dead launcher, not a slow one');
        $this->assertFalse($view['finished'], 'and it must not claim to have finished');
    }

    public function test_a_run_that_is_reporting_is_not_stalled(): void
    {
        $record = RunRecord::begin($this->path(), 'restore', 't', gmdate('c', time() - 600));

        $this->assertFalse($record->view()['stalled'], 'a run that has not started a stage is not stalled');
    }

    /**
     * A finished run is finished, whatever the age.
     */
    public function test_a_finished_run_is_not_stalled_however_old(): void
    {
        $record = RunRecord::begin($this->path(), 'restore', 't', gmdate('c', time() - 86400));

        foreach (RunRecord::STAGES as $stage) {
            $record->skip($stage);
        }

        $view = $record->view();

        $this->assertTrue($view['finished']);
        $this->assertFalse($view['stalled']);
        $this->assertSame(86400, $view['age_seconds']);
    }

    /**
     * How old it is, so a reader can see that a run from last week is not running now.
     */
    public function test_it_reports_its_age(): void
    {
        $record = RunRecord::begin($this->path(), 'restore', 't', gmdate('c', time() - 3600));

        $this->assertSame(3600, $record->view()['age_seconds']);
    }

    /**
     * A second run does not overwrite the first.
     *
     * Because a run that is still going looks exactly like one that has finished, and replacing the
     * record would erase the only evidence of the one that is going.
     */
    public function test_a_second_run_refuses_to_overwrite_the_first(): void
    {
        $this->begin();

        $refused = false;

        try {
            $this->begin('restore', 'another');
        } catch (RestoreRefused $e) {
            $refused = true;

            $this->assertStringContainsString('already a run recorded', $e->getMessage());
        }

        $this->assertTrue($refused);
        $this->assertSame(
            '20261014-031701',
            RunRecord::read($this->path())?->view()['token'],
            'the original run must still be the one on disk',
        );
    }

    /**
     * A stage that is not one of the stages is refused, not invented.
     */
    public function test_an_unknown_stage_is_refused(): void
    {
        $record = $this->begin();

        foreach ([['enter', ['teleporting']], ['finish', ['teleporting']], ['fail', ['teleporting', 'no reason']], ['skip', ['teleporting']]] as [$method, $arguments]) {
            $refused = false;

            try {
                $record->{$method}(...$arguments);
            } catch (RestoreRefused) {
                $refused = true;
            }

            $this->assertTrue($refused, $method.'() must refuse a stage that does not exist');
        }
    }

    // ---------------------------------------------------------------------------------------------
    // The file itself, because a record that cannot be read is the failure wearing a hat.
    // ---------------------------------------------------------------------------------------------

    public function test_it_survives_being_read_back(): void
    {
        $record = $this->begin();
        $record->enter('checking_pair', 'both halves present');
        $record->finish('checking_pair', 'both halves match the manifest');

        $read = RunRecord::read($this->path());

        $this->assertNotNull($read, 'the record has to be readable by the app when it comes back');
        $this->assertSame('restore', $read->view()['operation']);
        $this->assertSame('done', $this->stage($read->view(), 'checking_pair')['state']);
    }

    public function test_no_record_reads_as_null_rather_than_throwing(): void
    {
        $this->assertNull(RunRecord::read($this->workspace.DIRECTORY_SEPARATOR.'absent.json'));
    }

    /**
     * A truncated file reads as nothing rather than as a broken screen.
     */
    public function test_a_corrupt_record_reads_as_null(): void
    {
        file_put_contents($this->path(), '{"operation": "restore", "token": "t", "stages": {');

        $this->assertNull(RunRecord::read($this->path()), 'a torn file must not become an error page');
    }

    /**
     * A record missing a stage, from a version that knew fewer stages, still reads.
     *
     * Otherwise every future stage would make every old record unreadable, and the file a reader is
     * looking at would go blank the moment this project shipped a change.
     */
    public function test_a_record_missing_a_stage_still_reads(): void
    {
        $record = $this->begin();
        $record->enter('reading_backup');
        $record->finish('reading_backup');

        $raw = json_decode((string) file_get_contents($this->path()), true);
        unset($raw['stages']['restarting']);
        file_put_contents($this->path(), json_encode($raw));

        $read = RunRecord::read($this->path());

        $this->assertNotNull($read);
        $this->assertSame('pending', $this->stage($read->view(), 'restarting')['state']);
        $this->assertSame('done', $this->stage($read->view(), 'reading_backup')['state']);
    }

    /**
     * No temporary files are left where a reader or a cleaner would trip over them.
     */
    public function test_the_write_leaves_nothing_behind(): void
    {
        $record = $this->begin();

        foreach (RunRecord::STAGES as $stage) {
            $record->enter($stage);
            $record->finish($stage);
        }

        $strays = array_values(array_filter(
            (array) glob($this->workspace.DIRECTORY_SEPARATOR.'*'),
            static fn ($file): bool => is_string($file) && str_ends_with($file, '.tmp'),
        ));

        $this->assertSame([], $strays, 'an atomic write must not leave its temporary behind');
    }

    /**
     * @param  array<string, mixed>  $view
     * @return array<string, mixed>
     */
    private function stage(array $view, string $name): array
    {
        foreach ($view['stages'] as $stage) {
            if ($stage['stage'] === $name) {
                return $stage;
            }
        }

        $this->fail('no stage named '.$name);

        return [];
    }
}
