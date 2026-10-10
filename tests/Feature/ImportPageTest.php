<?php

namespace Tests\Feature;

use App\Jobs\RunBackupImport;
use App\Models\ImportRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->file = tempnam(sys_get_temp_dir(), 'papyrus-import-');
        file_put_contents((string) $this->file, 'not really a backup, but a real file');
    }

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            @unlink($this->file);
        }

        parent::tearDown();
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

        $this->page()
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
        ImportRun::createFor($this->user, [
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
}
