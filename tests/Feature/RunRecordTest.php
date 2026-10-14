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
        return RunRecord::begin($this->path(), $operation, RunRecord::RESTORE_STAGES, $token, gmdate('c'));
    }

    // ---------------------------------------------------------------------------------------------

    public function test_a_new_run_starts_with_every_stage_pending(): void
    {
        $view = $this->begin()->view();

        $this->assertCount(count(RunRecord::RESTORE_STAGES), $view['stages']);

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
        $record = RunRecord::begin($this->path(), 'restore', RunRecord::RESTORE_STAGES, 't', gmdate('c', time() - 600));
        $record->enter('stopping');

        $view = $record->view();

        $this->assertTrue($view['stalled'], 'six minutes of silence is a dead launcher, not a slow one');
        $this->assertFalse($view['finished'], 'and it must not claim to have finished');
    }

    public function test_a_run_that_is_reporting_is_not_stalled(): void
    {
        $record = RunRecord::begin($this->path(), 'restore', RunRecord::RESTORE_STAGES, 't', gmdate('c', time() - 600));

        $this->assertFalse($record->view()['stalled'], 'a run that has not started a stage is not stalled');
    }

    /**
     * A finished run is finished, whatever the age.
     */
    public function test_a_finished_run_is_not_stalled_however_old(): void
    {
        $record = RunRecord::begin($this->path(), 'restore', RunRecord::RESTORE_STAGES, 't', gmdate('c', time() - 86400));

        foreach (RunRecord::RESTORE_STAGES as $stage) {
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
        $record = RunRecord::begin($this->path(), 'restore', RunRecord::RESTORE_STAGES, 't', gmdate('c', time() - 3600));

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
     *
     * The four calls are written out rather than looped over method names. `fail()` takes a reason
     * as well as a stage, so a loop has to pass a different number of arguments to each one, and
     * PHPStan is right that a spread it cannot resolve to a fixed arity proves nothing about any of
     * them.
     */
    public function test_an_unknown_stage_is_refused(): void
    {
        $record = $this->begin();

        $refused = 0;

        foreach ([
            static fn (RunRecord $r) => $r->enter('teleporting'),
            static fn (RunRecord $r) => $r->finish('teleporting'),
            static fn (RunRecord $r) => $r->fail('teleporting', 'no reason'),
            static fn (RunRecord $r) => $r->skip('teleporting'),
        ] as $call) {
            try {
                $call($record);
            } catch (RestoreRefused) {
                $refused++;
            }
        }

        $this->assertSame(4, $refused, 'every one of enter, finish, fail and skip must refuse a stage that does not exist');
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

        foreach (RunRecord::RESTORE_STAGES as $stage) {
            $record->enter($stage);
            $record->finish($stage);
        }

        $strays = array_values(array_filter(
            (array) glob($this->workspace.DIRECTORY_SEPARATOR.'*'),
            static fn ($file): bool => is_string($file) && str_ends_with($file, '.tmp'),
        ));

        $this->assertSame([], $strays, 'an atomic write must not leave its temporary behind');
    }

    // ---------------------------------------------------------------------------------------------
    // Each operation brings its own stages, because a shared list invents stages that never ran.
    // ---------------------------------------------------------------------------------------------

    /**
     * A snapshot reports a snapshot's stages.
     *
     * The first version had one list, tuned for a restore, and a snapshot inherited
     * `reading_backup` and `checking_pair`, neither of which it has anything to do with. They would
     * have sat at `pending` for ever, which is a lie told by leaving something out rather than by
     * saying anything false.
     */
    public function test_a_snapshot_reports_its_own_stages(): void
    {
        $record = RunRecord::begin($this->path(), 'snapshot', RunRecord::SNAPSHOT_STAGES, 'tok', gmdate('c'));

        $view = $record->view();

        $this->assertCount(count(RunRecord::SNAPSHOT_STAGES), $view['stages']);
        $this->assertSame('snapshot', $view['operation']);

        foreach (RunRecord::SNAPSHOT_STAGES as $stage) {
            $this->assertNotNull($this->stageOrNull($view, $stage), $stage.' must be a stage of a snapshot');
        }

        foreach (['reading_backup', 'checking_pair', 'verifying'] as $notASnapshotStage) {
            $this->assertNull(
                $this->stageOrNull($view, $notASnapshotStage),
                'a snapshot must not report '.$notASnapshotStage.', which is a stage of a restore',
            );
        }
    }

    /**
     * A snapshot's stages can be entered, and read back, like any other's.
     */
    public function test_a_snapshot_can_be_entered_and_read_back(): void
    {
        $record = RunRecord::begin($this->path(), 'snapshot', RunRecord::SNAPSHOT_STAGES, 'tok', gmdate('c'));
        $record->enter('stopping', 'asking the launcher to stop everything');
        $record->finish('stopping', 'four processes stopped');
        $record->fail('copying_manga_half', 'Suwayomi was still running after thirty seconds');
        $record->finish('restarting', 'up again');

        $read = RunRecord::read($this->path());

        $this->assertNotNull($read, 'the app has to be able to read this back when it returns');
        $this->assertSame('snapshot', $read->view()['operation']);
        $this->assertSame('done', $this->stage($read->view(), 'stopping')['state']);
        $this->assertTrue($read->view()['failed']);
        $this->assertSame('pending', $this->stage($read->view(), 'checking_target')['state']);
    }

    /**
     * A stage name that was entered and then never written still reads back.
     *
     * The stage order is stored beside the stages rather than being inferred from them, precisely so
     * that a launcher killed between writing `running` and writing `done` leaves a readable record.
     * Inferred from the keys, that record would lose the very stage it was in the middle of.
     */
    public function test_the_stage_order_outlives_a_stage_removed_from_the_file(): void
    {
        $record = RunRecord::begin($this->path(), 'snapshot', RunRecord::SNAPSHOT_STAGES, 'tok', gmdate('c'));
        $record->enter('stopping');

        $raw = json_decode((string) file_get_contents($this->path()), true);

        $this->assertSame(RunRecord::SNAPSHOT_STAGES, $raw['stage_order']);

        unset($raw['stages']['stopping']);
        file_put_contents($this->path(), json_encode($raw));

        $read = RunRecord::read($this->path());

        $this->assertNotNull($read);
        $this->assertSame(
            'pending',
            $this->stage($read->view(), 'stopping')['state'],
            'the stage that was mid-flight is still named, and reads as not finished',
        );
    }

    /**
     * A record with no stages at all is refused rather than written.
     *
     * Nothing about it could be shown to anybody, so writing it produces a file that exists and says
     * nothing, which is the original failure wearing a different hat.
     */
    public function test_a_run_with_no_stages_is_refused(): void
    {
        $refused = false;

        try {
            RunRecord::begin($this->path(), 'snapshot', [], 'tok', gmdate('c'));
        } catch (RestoreRefused $e) {
            $refused = true;

            $this->assertStringContainsString('no stages to report', $e->getMessage());
        }

        $this->assertTrue($refused);
        $this->assertFileDoesNotExist($this->path(), 'a refused run must leave no file behind');
    }

    /**
     * A stage list holding something that is not a name is refused.
     */
    public function test_a_stage_list_holding_something_that_is_not_a_name_is_refused(): void
    {
        foreach ([[['stopping']], [''], ['  '], [42]] as $stages) {
            $refused = false;

            try {
                RunRecord::begin($this->path(), 'snapshot', $stages, 'tok', gmdate('c'));
            } catch (RestoreRefused) {
                $refused = true;
            }

            $this->assertTrue($refused, 'a stage list of '.json_encode($stages).' must be refused');
        }
    }

    /**
     * @param  array<string, mixed>  $view
     * @return array<string, mixed>
     */
    private function stage(array $view, string $name): array
    {
        /*
         * Keyed by name, and `assertArrayHasKey` rather than `$this->fail()` at the end.
         *
         * `fail()` is declared `@return never`, so a `return []` after it is unreachable as far as
         * PHPStan is concerned, and PHP will never reach it either. The assertion does the same job
         * without the dead statement, and naming the stage it could not find is in the message.
         */
        $byName = [];

        foreach ($view['stages'] as $stage) {
            $byName[$stage['stage']] = $stage;
        }

        $this->assertArrayHasKey($name, $byName, 'there is no stage named '.$name);

        return $byName[$name];
    }

    /**
     * @param  array<string, mixed>  $view
     * @return array<string, mixed>|null
     */
    private function stageOrNull(array $view, string $name): ?array
    {
        foreach ($view['stages'] as $stage) {
            if ($stage['stage'] === $name) {
                return $stage;
            }
        }

        return null;
    }
}
