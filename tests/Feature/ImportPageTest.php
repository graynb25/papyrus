<?php

namespace Tests\Feature;

use App\Import\BackupImporter;
use App\Jobs\RunBackupImport;
use App\Models\ImportRun;
use App\Models\User;
use App\Support\UploadLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\BuildsTachimangaBackups;
use Tests\TestCase;

/**
 * The page a reader imports a backup through, and the job it hands the work to.
 *
 * ## Why this needs its own suite
 *
 * The importer itself is covered end to end by {@see BackupImporterTest}, and the command by
 * {@see ImportBackupCommandTest}. What exists nowhere else is the two things this page adds:
 *
 * 1. **A file gets here at all.** The packaged install now accepts a measured 41MB
 *    backup, but a development PHP can still cap uploads well below that. The page has
 *    to offer both an upload and a path without claiming the upload will always work,
 *    and must not dispatch an import that can never receive its file.
 * 2. **Somebody is told when nothing is processing the queue.** A dispatch to a queue nobody
 *    serves leaves a run reading `running` forever. That is not a hypothetical on this
 *    project: it is why "Check 867 series" reported success and did nothing. The page is the
 *    first place in the app that can say so, so it is the first place that must.
 */
class ImportPageTest extends TestCase
{
    use BuildsTachimangaBackups;
    use RefreshDatabase;

    private User $user;

    /** A real file on disk, so the path branch is exercised against the filesystem. */
    private string $file = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => User::ROLE_INSTALL_OWNER]);
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
     * Both formats are named, and the one that cannot carry reading time says so.
     *
     * The Mihon/Tachiyomi/Suwayomi reader is built now, so the old "only Tachimanga"
     * warning would turn away a valid backup. The formats do not carry the same thing,
     * though: `.tmb` holds reading time and `.tachibk` does not, and a reader comparing
     * Insights after an import has to know which one they gave the app.
     */
    public function test_it_names_the_backup_formats_it_reads_and_what_they_carry(): void
    {
        $this->page()
            ->assertOk()
            ->assertSee('Tachimanga')
            ->assertSee('.tmb')
            ->assertSee('.tachibk')
            ->assertSee('Mihon')
            ->assertSee('Tachiyomi')
            ->assertSee('Suwayomi')
            ->assertSee('reading time stays behind in the app that made it')
            ->assertDontSee('Only Tachimanga backups import today')
            ->assertSee('https://tachimanga.app/help/guides/backups.html', escape: false);
    }

    /**
     * The number on the page and the number the upload endpoint enforces are one number.
     *
     * Livewire's own default is 12MB, and raising PHP's ceiling without raising that
     * would let the page promise room for a 41MB backup while the receiving endpoint
     * refused it. Both come from {@see UploadLimit}, so this checks the seam rather than
     * a literal size that only holds on one machine.
     */
    public function test_the_upload_endpoint_accepts_the_size_the_page_reports(): void
    {
        $ceiling = UploadLimit::bytes();
        $rules = (array) config('livewire.temporary_file_upload.rules');

        $this->assertContains('required', $rules);
        $this->assertContains('file', $rules);

        $maximum = null;
        foreach ($rules as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'max:')) {
                $maximum = (int) substr($rule, 4);
            }
        }

        if ($ceiling === 0) {
            $this->assertNull($maximum, 'an unlimited server must not carry a max rule at all');

            return;
        }

        $this->assertSame(intdiv($ceiling, 1024), $maximum);

        $page = $this->page();
        $page->assertOk();
        $this->assertSame($ceiling, $page->instance()->uploadCeiling());
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
            ->assertHasNoErrors()
            ->assertDispatched('toast-show', function (string $name, array $params): bool {
                return ($params['slots']['text'] ?? null) === 'There is no readable file named backup.tmb on this server.';
            });

        Queue::assertNothingPushed();
    }

    /**
     * The path field is an install-wide capability, not a reader one.
     *
     * A path is read by the process that runs the queue, so on a shared install it would let
     * one reader name any file the server account can read. The owner still needs it for a
     * backup too large to upload, which is why the field stays and the rule lives in PHP as
     * well as in the markup: a hidden input is not a control.
     */
    public function test_a_reader_is_not_offered_the_server_path_field(): void
    {
        $reader = User::factory()->create();

        $this->actingAs($reader);

        $this->page()
            ->assertOk()
            ->assertSee('Only the person who owns this server can import a file that is already on it. Choose the backup file above instead.')
            ->assertDontSee('wire:model="path"', escape: false);
    }

    public function test_the_owner_is_offered_the_server_path_field(): void
    {
        $this->page()
            ->assertOk()
            ->assertSee('Or a file already on this server')
            ->assertSee('wire:model="path"', escape: false);
    }

    public function test_a_reader_cannot_use_a_server_path_even_if_they_call_the_action(): void
    {
        Queue::fake();

        $reader = User::factory()->create();

        $this->actingAs($reader);

        $this->page()
            ->set('path', (string) $this->file)
            ->call('start')
            ->assertDispatched('toast-show', function (string $name, array $params): bool {
                return ($params['slots']['heading'] ?? null) === 'That path cannot be used'
                    && ($params['slots']['text'] ?? null) === 'Only the person who owns this server can import a file that is already on it. Choose the backup file above instead.';
            });

        Queue::assertNothingPushed();
    }

    public function test_a_reader_can_still_choose_an_uploaded_backup(): void
    {
        Queue::fake();
        Storage::fake('local');

        $reader = User::factory()->create();

        $this->actingAs($reader);

        $this->page()
            ->set('backup', UploadedFile::fake()->create('Tachimanga_backup.tmb', 16, 'application/zip'))
            ->call('start');

        Queue::assertPushed(RunBackupImport::class, fn (RunBackupImport $job): bool => $job->userId === (int) $reader->id);
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

        $component = $this->page()
            ->set('path', (string) $this->file)
            ->set('inspectOnly', false)
            ->call('start');

        $component
            ->assertSee(__('Reading the file'))
            ->assertSee(__('Bringing your series across'));

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

    /**
     * The install box is on by default, and the page says what that means.
     *
     * Import installs the extensions a backup names without the per-store trust step the
     * Sources page asks for. That is accepted for this release rather than hidden, so the
     * default and the consequence are on the page beside the box that controls it.
     */
    public function test_the_install_checkbox_carries_its_warning(): void
    {
        $this->page()
            ->assertSee('This box is on by default.')
            ->assertSee('An extension is code that runs inside the manga server with the access that process has')
            ->assertSee('without asking you to trust the store they came from first')
            ->assertSee('If you did not make the backup, clear this box before importing it.');
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

    /**
     * The file the job is told about is the file the page actually stored.
     *
     * Measured on the installed app: the page stored the upload on the `local` disk,
     * whose root is `storage/app/private`, and then handed the job
     * `storage/app/imports/<hash>.zip`. That path does not exist, so the job recorded
     * a refusal before reading a single byte. The names and the bytes were fine; the
     * path was not.
     */
    public function test_an_uploaded_backup_is_dispatched_from_the_disk_it_was_stored_on(): void
    {
        Queue::fake();
        Storage::fake('local');

        $this->page()
            ->set('backup', UploadedFile::fake()->create('Tachimanga_backup.tmb', 16, 'application/zip'))
            ->assertHasNoErrors()
            ->call('start');

        Queue::assertPushed(RunBackupImport::class, function (RunBackupImport $job): bool {
            $imports = str_replace('\\', '/', Storage::disk('local')->path('imports'));

            return is_file($job->path)
                && str_starts_with(str_replace('\\', '/', $job->path), $imports.'/');
        });
    }

    /**
     * The history names the file the reader chose, not the hash it was stored under.
     *
     * Laravel names an uploaded backup after its guessed MIME type, so the real
     * Tachimanga file became `FHK....zip` in `import_runs.file_name`. Recognition is
     * by bytes, so that label was only misleading, not fatal. A reader looking for
     * the backup they picked should see the name they know.
     */
    public function test_an_uploaded_backup_keeps_the_name_the_reader_chose(): void
    {
        Queue::fake();
        Storage::fake('local');

        $this->page()
            ->set('backup', UploadedFile::fake()->create('Tachimanga_backup.tmb', 16, 'application/zip'))
            ->call('start');

        Queue::assertPushed(RunBackupImport::class, fn (RunBackupImport $job): bool => property_exists($job, 'originalName')
            && $job->originalName === 'Tachimanga_backup.tmb');
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

    /**
     * The answer belongs in front of the reader, not in a row below the fold.
     *
     * Measured on the installed app: choosing the 41MB backup and pressing Inspect
     * produced no modal, no checkpoint, and no visible result. The run was created and
     * refused, but the page only listed it under "Your imports" and nothing opened it.
     * The modal therefore has to open on the button press, before the run row exists.
     */
    public function test_pressing_the_button_opens_the_modal_on_a_queued_run(): void
    {
        Queue::fake();

        $this->page()
            ->set('path', (string) $this->file)
            ->call('start')
            ->assertSet('modalOpen', true)
            ->assertSet('modalRunId', null)
            ->assertSee(__('The file will be read in a moment.'))
            ->assertSee(__('Reading the file'))
            ->assertDontSee(__('Bringing your series across'));
    }

    /**
     * An older finished run must not be mistaken for the one just dispatched.
     *
     * The page used to ask `activeRun()` whether anything was running. With history on
     * the page, that is the previous run until a worker picks the new job up, so the
     * first poll would say "finished" and stop watching before the new row existed.
     */
    public function test_an_older_finished_run_does_not_end_the_wait_for_the_new_one(): void
    {
        Queue::fake();

        ImportRun::createFor($this->user, [
            'format' => 'tachimanga',
            'state' => ImportRun::COMPLETE,
            'file_name' => 'older.tmb',
            'started_at' => now()->subDay(),
            'finished_at' => now()->subDay(),
        ]);

        $component = $this->page()->set('path', (string) $this->file)->call('start');

        $this->assertTrue($component->instance()->shouldPoll(), 'the new job is still queued');
        $component->assertSee(__('The file will be read in a moment.'));

        $new = $this->runningRun();
        $component->call('$refresh');

        $this->assertSame($new->id, $component->instance()->modalRun()?->id);
        $this->assertTrue($component->instance()->shouldPoll(), 'and now watches the new run');
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
            ->assertSee(__('It is waiting in the queue because no worker has picked it up yet.'))
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
            ->assertSee(__('Imports need a worker on this server'))
            ->assertSee('QUEUE_CONNECTION=database');
    }

    public function test_a_proper_queue_is_not_called_out(): void
    {
        $this->useDatabaseQueue();

        $this->page()->assertDontSee(__('Imports need a worker on this server'));
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
            ->assertSee(__('This import is waiting'))
            ->assertSee('php artisan queue:work');
    }

    public function test_a_finished_run_is_never_reported_as_stuck(): void
    {
        $run = $this->runningRun();

        $run->forceFill([
            'state' => ImportRun::COMPLETE,
            'updated_at' => now()->subHours(3),
        ])->save();

        $this->page()->assertDontSee(__('This import is waiting'));
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

    /**
     * A finished run keeps the time it took.
     *
     * The elapsed line measured from `started_at` to `now()` on every render,
     * so a five minute run read "2 hours 10 minutes" two hours after it ended.
     * A finished run is measured to `finished_at`; only a live one keeps
     * counting, and only a live one says "so far".
     */
    public function test_a_finished_run_keeps_the_time_it_took(): void
    {
        $run = ImportRun::createFor($this->user, [
            'format' => 'tachimanga',
            'state' => ImportRun::COMPLETE,
            'file_name' => 'library.tmb',
            'library_series' => 10,
            'applied' => 10,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(5),
        ]);

        $this->travel(2)->hours();

        $this->page()
            ->call('openRunModal', $run->id)
            ->assertSee('5 minutes')
            ->assertDontSee('2 hours 10 minutes')
            ->assertDontSee(__('so far'));
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

    public function test_a_real_inspect_is_not_shown_as_an_import_that_found_nothing(): void
    {
        /*
         * Measured on the installed app with the real 41MB backup. The inspect job stored
         * `library_series = 1277`, so the old "null means inspect" test called it an import:
         * the modal ticked all four import checkpoints and said "0 series imported" about a
         * file holding 40,160 read chapters. The hand-built run above could not catch this
         * because it used the shape the job no longer writes.
         */
        $backup = $this->buildTachimangaBackup();

        $job = new RunBackupImport(
            $this->user->id,
            $backup,
            inspectOnly: true,
            originalName: 'Tachimanga_backup_2026-10-08_18-31-01-290.tmb',
        );

        $job->handle($this->app->make(BackupImporter::class));

        $run = ImportRun::query()->latest('id')->firstOrFail();

        $this->assertSame(2, $run->library_series, 'the real inspect writes a library count');
        $this->assertSame(0, $run->series_processed);

        $this->page()
            ->call('openRunModal', $run->id)
            ->assertSee('series in your library')
            ->assertSee('marked read')
            ->assertSee(__('Reading the file'))
            ->assertDontSee(__('Installing what it needs'))
            ->assertDontSee(__('Bringing your series across'))
            ->assertDontSee(__('Filing your shelves'))
            ->assertDontSee(__('0 series imported'));
    }

    public function test_a_real_import_accounts_for_every_series_it_walked(): void
    {
        /*
         * Measured on the installed app with the real 41MB backup: 1,277 series were
         * walked, 667 imported, 294 not found, 250 on sources this server did not have
         * installed, 64 on sources it could not reach, and 2 failed. The sentence named
         * only the 667 imported and the 55 filed, so 555 series were unaccounted for and
         * a reader could not tell what happened to them.
         */
        ImportRun::createFor($this->user, [
            'format' => 'tachimanga',
            'state' => ImportRun::COMPLETE,
            'file_name' => 'Tachimanga_backup_2026-10-08_18-31-01-290.tmb',
            'library_series' => 1277,
            'series_processed' => 1277,
            'applied' => 667,
            'unchanged' => 0,
            'not_found' => 294,
            'series_on_missing_sources' => 250,
            'filed_without_source' => 55,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $run = ImportRun::query()->latest('id')->firstOrFail();

        $this->page()
            ->call('openRunModal', $run->id)
            ->assertSee('667 series imported, 0 already up to date.')
            ->assertSee('The other 610 did not come across')
            ->assertSee('294 not found')
            ->assertSee('250 on sources this server does not have installed')
            ->assertSee('66 because of an error')
            ->assertSee('55 titles were added to your library without their source')
            ->assertDontSee('55 with no source');
    }

    public function test_an_import_that_left_nothing_behind_does_not_invent_failures(): void
    {
        ImportRun::createFor($this->user, [
            'format' => 'tachimanga',
            'state' => ImportRun::COMPLETE,
            'file_name' => 'library.tmb',
            'library_series' => 1277,
            'series_processed' => 1277,
            'applied' => 0,
            'unchanged' => 1277,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $run = ImportRun::query()->latest('id')->firstOrFail();

        $this->page()
            ->call('openRunModal', $run->id)
            ->assertSee('0 series imported, 1,277 already up to date.')
            ->assertDontSee('did not come across');
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
