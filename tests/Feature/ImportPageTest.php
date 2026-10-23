<?php

namespace Tests\Feature;

use App\Jobs\RunBackupImport;
use App\Models\ImportRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The page a reader imports a backup through, and the job it hands the work to.
 *
 * ## Why this needs its own suite
 *
 * The importer itself is covered end to end by {@see BackupImporterTest}, and the command by
 * {@see ImportBackupCommandTest}. What exists nowhere else is the two things this page adds:
 *
 * 1. **A file gets here at all.** The importer has always taken a path, because a 39.36MB
 *    backup does not fit through `post_max_size`. Deciding how it arrives is new work, and it
 *    has a real failure mode: silently dispatching an import that can never run.
 * 2. **Somebody is told when nothing is processing the queue.** A dispatch to a queue nobody
 *    serves leaves a run reading `running` forever. That is not a hypothetical on this
 *    project: it is why "Check 867 series" reported success and did nothing. The page is the
 *    first place in the app that can say so, so it is the first place that must.
 */
class ImportPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** A real file on disk, so the path branch is exercised against the filesystem. */
    private string $file = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->file = $this->temporaryFile('papyrus-import-');
        file_put_contents((string) $this->file, 'not really a backup, but a real file');
    }

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            @unlink($this->file);
        }

        parent::tearDown();
    }

    /**
     * The guide is the other half of the same sentence: how to create the backup on the
     * source app, not just how to hand one over.
     *
     * The steps are Tachimanga's own rather than remembered. The file location is the part
     * that earns the section: Tachimanga is an iOS app, `On My iPhone` is hidden from the
     * Files browser until it is revealed, and a reader who did exactly the right thing can
     * still conclude they could not store a backup.
     */
    public function test_it_says_how_to_make_the_backup(): void
    {
        $this->page()
            ->assertOk()
            ->assertSee('Making the backup')
            // The literal HTML holds `&amp;`, and the needle has to be that escaped form
            // with escaping off, or the two sides disagree about what an ampersand is.
            ->assertSee('Backup &amp; Restore', escape: false)
            ->assertSee('Create Backup')
            ->assertSee('On My iPhone')
            ->assertSee('.tmb');
    }

    /**
     * Only Tachimanga is claimed, because only Tachimanga is read.
     *
     * The Mihon reader is not built. Saying so is the whole reason the section was held
     * back until now, and leaving it out again would hand a reader a `.tachibk` and a wait
     * for nothing.
     */
    public function test_it_says_which_apps_are_read_and_which_are_not(): void
    {
        $this->page()
            ->assertOk()
            ->assertSee('Only Tachimanga backups import today')
            ->assertSee('Mihon')
            ->assertSee('https://tachimanga.app/help/guides/backups.html', escape: false);
    }

    private function page(): Testable
    {
        return Livewire::test('pages::settings.import');
    }

    /*
     * ---------------------------------------------------------------------------
     * Reaching the page.
     * ---------------------------------------------------------------------------
     */

    public function test_it_requires_authentication(): void
    {
        auth()->logout();

        $this->get('/settings/import')->assertRedirect();
    }

    public function test_it_renders_for_a_signed_in_reader(): void
    {
        $this->get('/settings/import')->assertOk();
    }

    public function test_an_unverified_reader_can_still_reach_it(): void
    {
        /*
         * Deliberately unlike the other settings pages, which are behind `verified`.
         *
         * A reader who has just signed up and cannot yet receive a mail is exactly the reader
         * who is migrating a library off another app, and there is nothing here to escalate
         * privilege for: the importer is handed the signed in user, never one from the request.
         */
        $this->user->forceFill(['email_verified_at' => null])->save();

        $this->get('/settings/import')->assertOk();
    }

    public function test_the_settings_nav_offers_it(): void
    {
        $this->get('/settings/profile')
            ->assertOk()
            ->assertSee(route('import.edit'), escape: false);
    }

    /*
     * ---------------------------------------------------------------------------
     * Nothing to import.
     * ---------------------------------------------------------------------------
     */

    public function test_it_refuses_to_start_with_neither_a_file_nor_a_path(): void
    {
        Queue::fake();

        $this->page()
            ->call('start')
            ->assertHasNoErrors();

        Queue::assertNothingPushed();
    }

    public function test_it_refuses_a_path_that_is_not_a_readable_file(): void
    {
        Queue::fake();

        $this->page()
            ->set('path', 'D:/no/such/backup.tmb')
            ->call('start')
            ->assertHasNoErrors();

        Queue::assertNothingPushed();
    }

    public function test_an_empty_path_is_not_treated_as_the_working_directory(): void
    {
        Queue::fake();

        $this->page()
            ->set('path', '   ')
            ->call('start');

        Queue::assertNothingPushed();
    }

    /*
     * ---------------------------------------------------------------------------
     * Starting a real import.
     * ---------------------------------------------------------------------------
     */

    public function test_a_good_path_dispatches_the_import_for_the_signed_in_reader(): void
    {
        Queue::fake();

        $this->page()
            ->set('path', (string) $this->file)
            ->set('inspectOnly', false)
            ->call('start');

        Queue::assertPushed(RunBackupImport::class, function (RunBackupImport $job): bool {
            return $job->userId === (int) $this->user->id
                && $job->path === $this->file
                && $job->inspectOnly === false;
        });
    }

    public function test_inspect_is_the_default_and_still_dispatches(): void
    {
        Queue::fake();

        $this->page()
            ->set('path', (string) $this->file)
            ->call('start');

        Queue::assertPushed(RunBackupImport::class, fn (RunBackupImport $job): bool => $job->inspectOnly === true);
    }

    public function test_the_options_reach_the_job(): void
    {
        Queue::fake();

        $this->page()
            ->set('path', (string) $this->file)
            ->set('inspectOnly', false)
            ->set('limit', 25)
            ->set('installExtensions', false)
            ->call('start');

        Queue::assertPushed(RunBackupImport::class, function (RunBackupImport $job): bool {
            return $job->limit === 25 && $job->installExtensions === false;
        });
    }

    public function test_the_force_flag_reaches_the_job(): void
    {
        Queue::fake();

        $this->page()
            ->set('path', (string) $this->file)
            ->set('force', true)
            ->call('start');

        Queue::assertPushed(RunBackupImport::class, fn (RunBackupImport $job): bool => $job->force === true);
    }

    public function test_force_is_off_by_default(): void
    {
        Queue::fake();

        $this->page()
            ->set('path', (string) $this->file)
            ->call('start');

        Queue::assertPushed(RunBackupImport::class, fn (RunBackupImport $job): bool => $job->force === false);
    }

    public function test_a_negative_limit_is_clamped_rather_than_dispatched(): void
    {
        Queue::fake();

        $this->page()
            ->set('path', (string) $this->file)
            ->set('limit', -5)
            ->call('start');

        Queue::assertPushed(RunBackupImport::class, fn (RunBackupImport $job): bool => $job->limit === 0);
    }

    public function test_the_path_is_cleared_after_dispatching(): void
    {
        Queue::fake();

        $this->page()
            ->set('path', (string) $this->file)
            ->call('start')
            ->assertSet('path', '');
    }

    /*
     * ---------------------------------------------------------------------------
     * Watching a run that does not exist yet.
     * ---------------------------------------------------------------------------
     *
     * Measured on the real 39.36MB backup: pressing Inspect showed the toast and then never
     * showed the result. The page re-rendered on click, at which point the job was queued and
     * had not run, so there was no run row to render, and nothing ever asked the page to look
     * again. The gap between pressing the button and the worker starting is where the answer
     * was being lost, and on a server with no worker it is the whole story.
     */

    public function test_pressing_the_button_starts_polling(): void
    {
        Queue::fake();

        $component = $this->page()->set('path', (string) $this->file)->call('start');

        $this->assertTrue($component->instance()->polling);
        $this->assertTrue($component->instance()->shouldPoll());
        $component->assertSee(__('Queued. The file will be read in a moment.'));
    }

    public function test_it_keeps_polling_until_a_run_finishes(): void
    {
        Queue::fake();

        $component = $this->page()->set('path', (string) $this->file)->call('start');

        $this->runningRun();

        $this->assertTrue($component->instance()->shouldPoll(), 'a run is going');

        ImportRun::query()->forUser($this->user->id)->update(['state' => ImportRun::COMPLETE]);

        $this->assertFalse(
            $component->instance()->shouldPoll(),
            'and stops the moment one finishes, rather than polling forever',
        );
    }

    public function test_a_page_that_has_not_dispatched_anything_does_not_poll(): void
    {
        $this->runningRun();

        $this->assertFalse($this->page()->instance()->shouldPoll());
    }

    public function test_a_queued_job_nobody_is_running_is_reported(): void
    {
        $this->useDatabaseQueue();

        $component = $this->page()->set('path', (string) $this->file)->call('start');

        // The dispatch was faked, so nothing is really in the queue. Put the job there.
        $this->queueAJobWaitingFor(now()->subMinutes(2));

        $this->assertTrue($component->instance()->queueStalled());

        // The HTML under assertion was rendered when the button was pressed, which was before
        // the job existed. Refresh so the callout is actually on the page being checked.
        $component->call('$refresh')
            ->assertSee(__('It is sitting in the queue and nothing is processing it'))
            ->assertSee('php artisan queue:work');
    }

    public function test_a_freshly_queued_job_is_not_accused_of_being_abandoned(): void
    {
        $this->useDatabaseQueue();

        $component = $this->page()->set('path', (string) $this->file)->call('start');

        $this->queueAJobWaitingFor(now());

        $this->assertFalse(
            $component->instance()->queueStalled(),
            'a worker that has only just been given work has not failed yet',
        );
    }

    public function test_a_worker_busy_on_a_long_run_is_not_accused_of_being_dead(): void
    {
        $this->useDatabaseQueue();

        $component = $this->page()->set('path', (string) $this->file)->call('start');
        $this->runningRun();

        $this->queueAJobWaitingFor(now()->subMinutes(2));

        $this->assertFalse(
            $component->instance()->queueStalled(),
            'a run is actually going, so the worker is working rather than absent',
        );
    }

    public function test_nothing_is_reported_when_the_queue_is_not_the_database_one(): void
    {
        $component = $this->page()->set('path', (string) $this->file)->call('start');

        $this->queueAJobWaitingFor(now()->subMinutes(2));

        $this->assertFalse(
            $component->instance()->queueStalled(),
            'with a sync queue the job already ran, and there is no jobs table to read',
        );
    }

    /*
     * ---------------------------------------------------------------------------
     * A server whose queue cannot do background work.
     * ---------------------------------------------------------------------------
     */

    public function test_a_sync_queue_is_called_out_before_anything_is_started(): void
    {
        /*
         * `sync` is Laravel's default for a new install, and it runs the job inside the request.
         *
         * For a check that is 361ms this costs nothing. For an import of 1,277 series at eleven
         * minutes it means the browser request hangs until something upstream gives up, and the
         * reader sees a page that stopped responding rather than a page that told them
         * anything. Saying so up front is the only version of this that is a warning rather
         * than an outage.
         */
        $this->assertTrue($this->page()->instance()->queueIsInline());

        $this->page()
            ->assertSee(__('This server runs its queue inside the request'))
            ->assertSee('QUEUE_CONNECTION=database');
    }

    public function test_a_proper_queue_is_not_called_out(): void
    {
        $this->useDatabaseQueue();

        $this->page()->assertDontSee(__('This server runs its queue inside the request'));
    }

    public function test_an_inline_queue_still_dispatches(): void
    {
        Queue::fake();

        $this->page()
            ->set('path', (string) $this->file)
            ->call('start');

        Queue::assertPushed(RunBackupImport::class);
    }

    public function test_another_readers_library_is_never_the_target(): void
    {
        /*
         * The job is given the signed in user rather than one from the request, so there is no
         * field to tamper with. This asserts the absence of the hole rather than the presence
         * of a guard, because a guard that does not exist cannot be bypassed.
         */
        Queue::fake();

        $other = User::factory()->create();

        $this->page()
            ->set('path', (string) $this->file)
            ->call('start');

        Queue::assertPushed(RunBackupImport::class, function (RunBackupImport $job) use ($other): bool {
            return $job->userId !== (int) $other->id;
        });
    }

    /*
     * ---------------------------------------------------------------------------
     * Telling the reader nothing is processing the queue.
     * ---------------------------------------------------------------------------
     */

    public function test_a_freshly_started_run_is_not_reported_as_stuck(): void
    {
        $this->runningRun();

        $this->page()->assertDontSee(__('This import has not moved'));
    }

    public function test_a_run_that_has_not_moved_is_reported_with_the_command_to_run(): void
    {
        $run = $this->runningRun();

        $run->forceFill(['updated_at' => now()->subMinutes(20)])->save();

        $this->page()
            ->assertSee(__('This import has not moved'))
            ->assertSee('php artisan queue:work');
    }

    public function test_a_finished_run_is_never_reported_as_stuck(): void
    {
        $run = $this->runningRun();

        $run->forceFill([
            'state' => ImportRun::COMPLETE,
            'updated_at' => now()->subHours(3),
        ])->save();

        $this->page()->assertDontSee(__('This import has not moved'));
    }

    public function test_a_refused_run_is_never_reported_as_stuck(): void
    {
        $run = $this->runningRun();

        $run->forceFill([
            'state' => ImportRun::REFUSED,
            'refusal_reason' => 'That is not a backup this app can read.',
            'updated_at' => now()->subHours(3),
        ])->save();

        $run = ImportRun::query()->latest('id')->first();

        $this->page()
            ->call('openRunModal', $run->id)
            ->assertDontSee(__('This import has not moved'))
            ->assertSee('That is not a backup this app can read.');
    }

    public function test_the_stuck_check_looks_only_at_this_readers_runs(): void
    {
        $other = User::factory()->create();

        $run = ImportRun::createFor($other, [
            'format' => 'tachimanga',
            'state' => ImportRun::RUNNING,
            'started_at' => now(),
        ]);

        $run->forceFill(['updated_at' => now()->subHours(3)])->save();

        $this->page()->assertDontSee(__('This import has not moved'));
    }

    /*
     * ---------------------------------------------------------------------------
     * A run nobody can finish.
     * ---------------------------------------------------------------------------
     *
     * `RunBackupImport` marks its own run failed when it catches a throw. These are the cases
     * it cannot: the worker killed at its timeout, an out of memory kill, a machine that went
     * down. All of them leave the row reading `running` with no process behind it, and the page
     * has to stop believing that, or the history fills with rows claiming to be working.
     */

    public function test_an_abandoned_run_reads_as_stopped_rather_than_running(): void
    {
        $run = ImportRun::createFor($this->user, [
            'format' => 'tachimanga',
            'state' => ImportRun::RUNNING,
            'file_name' => 'killed.tmb',
            'progress_note' => '  250 of 1277 series',
            'started_at' => now()->subHour(),
        ]);

        $run->forceFill(['updated_at' => now()->subHour()])->save();

        $this->page()
            ->call('openRunModal', $run->fresh()->id)
            ->assertSee(__('This run stopped without finishing.'))
            ->assertSee(__('Stopped'));

        $this->assertTrue(
            $this->page()->instance()->abandoned($run->fresh()),
            'and the page agrees the run is over rather than reporting it as working',
        );
    }

    public function test_a_run_that_is_merely_slow_is_not_called_abandoned(): void
    {
        $run = $this->runningRun();

        $this->assertFalse($this->page()->instance()->abandoned($run));
    }

    public function test_a_run_installing_extensions_is_not_called_abandoned(): void
    {
        /*
         * The extension phase reports once at the start and once at the end, and installing 31
         * of them takes minutes. A run that is working but quiet must not be called abandoned,
         * or the page would tell a reader their import died while it was succeeding.
         */
        $run = ImportRun::createFor($this->user, [
            'format' => 'tachimanga',
            'state' => ImportRun::RUNNING,
            'progress_note' => 'extensions: 0 already here, 4 installed of 31 attempted',
            'started_at' => now(),
        ]);

        $this->assertFalse($this->page()->instance()->abandoned($run));
    }

    /*
     * ---------------------------------------------------------------------------
     * The progress bar.
     * ---------------------------------------------------------------------------
     */

    public function test_a_run_in_progress_shows_a_bar_with_a_percentage(): void
    {
        $run = $this->runningRun();

        $run->forceFill([
            'library_series' => 1000,
            'series_processed' => 250,
            'progress_note' => '  250 of 1000 series, 250 written, 0 unchanged, 0 not found',
        ])->save();

        $this->page()
            ->call('openRunModal', $run->id)
            ->assertSee('25')
            ->assertSee('250 of 1000 series');
    }

    public function test_no_bar_before_the_loop_starts(): void
    {
        /*
         * `library_series` is null until the file has been read, and a bar at that point would
         * be claiming a position the page cannot know. The note carries the run through the
         * extension install instead.
         */
        $this->runningRun();

        $this->page()->assertDontSee('role="progressbar"', escape: false);
    }

    public function test_a_zero_total_does_not_divide(): void
    {
        $run = $this->runningRun();

        $run->forceFill(['library_series' => 0, 'series_processed' => 0])->save();

        $this->page()->assertOk();
    }

    /*
     * ---------------------------------------------------------------------------
     * The finished runs, as a reader reads them.
     * ---------------------------------------------------------------------------
     */

    public function test_a_finished_run_reports_the_three_numbers_that_matter(): void
    {
        $run = ImportRun::createFor($this->user, [
            'format' => 'tachimanga',
            'state' => ImportRun::COMPLETE,
            'file_name' => 'library.tmb',
            'library_series' => 1277,
            'applied' => 1082,
            'unchanged' => 0,
            'filed_without_source' => 195,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->page()
            ->call('openRunModal', $run->id)
            ->assertSee('1,082')
            ->assertSee('195')
            ->assertSee(__('Finished'));
    }

    public function test_the_history_is_newest_first(): void
    {
        ImportRun::createFor($this->user, [
            'format' => 'tachimanga',
            'state' => ImportRun::COMPLETE,
            'file_name' => 'older.tmb',
            'started_at' => now()->subDay(),
        ]);

        ImportRun::createFor($this->user, [
            'format' => 'tachimanga',
            'state' => ImportRun::COMPLETE,
            'file_name' => 'newer.tmb',
            'started_at' => now(),
        ]);

        $this->page()
            ->assertSeeInOrder(['newer.tmb', 'older.tmb']);
    }

    public function test_it_says_so_when_nothing_has_been_imported(): void
    {
        $this->page()->assertSee(__('You have not imported anything yet.'));
    }

    /*
     * ---------------------------------------------------------------------------
     * An inspect is not an import that found nothing.
     * ---------------------------------------------------------------------------
     */

    public function test_an_inspect_reports_what_was_in_the_file_not_a_count_of_zero(): void
    {
        /*
         * Measured on the real 39.36MB backup. The run fell through to the import wording and
         * said "0 series imported, 0 already up to date, 0 with no source" about a file holding
         * 1,277 series, which had just been described in full one field away. Every number was
         * true and the sentence was worthless.
         */
        ImportRun::createFor($this->user, [
            'format' => 'tachimanga',
            'state' => ImportRun::COMPLETE,
            'file_name' => 'library.tmb',
            'library_series' => null,
            'progress_note' => 'Read a tachimanga backup, format version 520. 1277 series on the shelf.',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $run = ImportRun::query()->latest('id')->first();

        $this->page()
            ->call('openRunModal', $run->id)
            ->assertSee('1277 series on the shelf')
            ->assertDontSee(__('0 series imported'));
    }

    public function test_a_real_import_still_reports_counts(): void
    {
        ImportRun::createFor($this->user, [
            'format' => 'tachimanga',
            'state' => ImportRun::COMPLETE,
            'file_name' => 'library.tmb',
            'library_series' => 1277,
            'progress_note' => '  1277 of 1277 series, 1082 written, 0 unchanged, 195 not found',
            'applied' => 1082,
            'unchanged' => 0,
            'filed_without_source' => 195,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $run = ImportRun::query()->latest('id')->first();

        $this->page()
            ->call('openRunModal', $run->id)
            ->assertSee('1,082')
            ->assertSee('195');
    }

    /*
     * ---------------------------------------------------------------------------
     * Helpers.
     * ---------------------------------------------------------------------------
     */

    private function runningRun(): ImportRun
    {
        return ImportRun::createFor($this->user, [
            'format' => 'tachimanga',
            'state' => ImportRun::RUNNING,
            'started_at' => now(),
        ]);
    }

    /**
     * Put the page on the queue a deployed server actually uses.
     *
     * `phpunit.xml` forces `QUEUE_CONNECTION=sync` so the suite does not need a worker, which
     * is right for every other test here and wrong for these. These assert on the `jobs`
     * table, so they have to say so rather than quietly pass against a queue that cannot hold
     * a job.
     */
    private function useDatabaseQueue(): void
    {
        config(['queue.default' => 'database']);
    }

    /**
     * Put exactly one job in the queue, waiting since the given moment.
     *
     * Cleared first because this suite's queries read the whole `jobs` table, and a queue
     * worker on the developer's machine is writing to that same table outside the test
     * transaction. Measured: a test asserting "no job is old enough" failed because the dev
     * worker happened to have an import's worth of work parked in there at the time. The test
     * is about the row it creates, so it starts from an empty table. The delete is inside the
     * transaction and rolls back with everything else.
     */
    private function queueAJobWaitingFor(\DateTimeInterface $since): void
    {
        DB::table('jobs')->delete();

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $since->getTimestamp(),
            'created_at' => $since->getTimestamp(),
        ]);
    }
}
