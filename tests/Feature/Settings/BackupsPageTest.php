<?php

namespace Tests\Feature\Settings;

use App\Backup\BackupLibrary;
use App\Jobs\RunLiveBackup;
use App\Jobs\RunLiveExport;
use App\Jobs\RunLiveRestore;
use App\Models\LiveBackupRun;
use App\Models\LiveExportRun;
use App\Models\LiveRestoreRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Papyrus\Launcher\RunRecord;
use RuntimeException;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * The Backups settings page: what can be restored, and what the last run from any door did.
 *
 * ## What is asserted here
 *
 * That the page tells a reader the truth in the states that matter, and that it never says a
 * backup is fine when it is not. Those are:
 *
 *   - a run that finished, with every stage accounted for
 *   - a run that failed, naming the step and quoting the reason
 *   - a run whose launcher was killed, which must not read as one still going
 *
 * and, since phase 7, that the launcher's record and the live runs read through ONE "last time"
 * block: the newest event wins whatever door wrote it, so the page never says two different
 * things happened last from two different places.
 *
 * ## What the page does now, and what carried over
 *
 * A backup can be taken from a button on this page, so the old sentence explaining why it could
 * not is gone with the banner that held it. The plain empty states stayed: same door-naming copy
 * about what exists now instead of what could not be done, and the same refusal to let an empty
 * table mean silence.
 */
class BackupsPageTest extends TestCase
{
    use MintsTokens;
    use RefreshDatabase;

    private string $folder = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        $this->folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-backupspage-'.bin2hex(random_bytes(6));

        mkdir($this->folder, 0o777, true);

        $this->app->instance(BackupLibrary::class, new BackupLibrary($this->folder));
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->folder.'/*') as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            is_dir($entry) ? @array_map('unlink', (array) glob($entry.'/*')) && @rmdir($entry) : @unlink($entry);
        }

        @rmdir($this->folder);

        parent::tearDown();
    }

    public function test_it_loads_for_a_signed_in_reader(): void
    {
        $this->get('/settings/backups')->assertOk();
    }

    public function test_a_reader_who_is_not_signed_in_is_sent_to_sign_in(): void
    {
        auth()->logout();

        $this->get('/settings/backups')->assertRedirect();
    }

    /**
     * A page with nothing on it names both doors a pair can be taken from.
     *
     * The old refusal copy is gone because a button exists now, but the honest work it did
     * stays: an empty page says what can be taken and what each door stops to do it, rather
     * than leaving a reader to conclude the feature is missing.
     */
    public function test_a_page_with_nothing_yet_names_both_doors_a_pair_can_be_taken_from(): void
    {
        $this->get('/settings/backups')
            ->assertSee('No backups yet')
            ->assertSee('Back up now')
            ->assertSee('papyrus snapshot');
    }

    public function test_with_nothing_taken_yet_it_says_so_rather_than_showing_an_empty_table(): void
    {
        $this->get('/settings/backups')
            ->assertSee('No backups yet')
            ->assertSee('Nothing has happened here yet');
    }

    /**
     * A finished backup, with its stages, in the words the manifest used.
     */
    public function test_it_lists_a_backup_and_what_it_holds(): void
    {
        $this->writePair();
        $this->writeRun();

        $this->get('/settings/backups')
            ->assertSee('867 series and 21,447 chapters')
            ->assertSee('Backup is complete')
            ->assertSee('2.4.2366');

        Livewire::test('pages::settings.backups')
            ->call('openRunModal')
            ->assertSee('This backup finished.');
    }

    public function test_a_backup_says_when_it_was_last_restored(): void
    {
        $this->writePair();
        $this->writeRestoreRun(state: LiveRestoreRun::COMPLETE);

        $this->get('/settings/backups')
            ->assertSee('Last restored');
    }

    /**
     * A failed run names the step and quotes the reason.
     *
     * The reason is the whole point. A reader who cannot see why their backup failed cannot decide
     * whether to try again, and "the backup failed" on its own is the confident unhelpful answer this
     * project keeps having to take back.
     */
    public function test_a_failed_run_names_the_step_and_the_reason(): void
    {
        $this->writeRun(failed: true);

        Livewire::test('pages::settings.backups')
            ->call('openRunModal')
            ->assertSee('stopped while saving your manga data')
            ->assertSee('still running after thirty seconds');
    }

    /**
     * A launcher that was killed reads as stalled, not as one still going.
     */
    public function test_a_run_whose_launcher_died_does_not_read_as_still_running(): void
    {
        $this->writeRun(stalled: true);

        Livewire::test('pages::settings.backups')
            ->call('openRunModal')
            ->assertSee('did not finish')
            ->assertDontSee('is still running');
    }

    /**
     * A retry is visible, because "failed once then worked" and "worked" are different facts.
     */
    public function test_a_retried_step_is_shown_as_retried(): void
    {
        $this->writeRun(retried: true);

        Livewire::test('pages::settings.backups')
            ->call('openRunModal')
            ->assertSee('tried 2 times')
            ->assertSee('was tried more than once');
    }

    // -------------------------------------------------------------------------------------------

    /*
     * "The last time", read in one place.
     *
     * Four doors write records and the block shows the newest of them. A launcher record on
     * its own reads in the block's old shape, the launcher's own verdict, and a newer live
     * run takes the block without the launcher's words still standing there, because two
     * truths about what happened last is exactly what one block exists to refuse.
     */

    public function test_the_last_run_block_reads_the_launcher_record_when_it_is_the_newest_of_the_doors(): void
    {
        $this->writeRun();

        $this->get('/settings/backups')
            ->assertSee('Last run was')
            ->assertSee('Show details');

        Livewire::test('pages::settings.backups')
            ->call('openRunModal')
            ->assertSee('This backup finished.');
    }

    public function test_a_newer_live_run_owns_the_last_run_block_and_the_launcher_verdict_goes_quiet(): void
    {
        $this->writeRun();

        LiveBackupRun::query()->create(
            ['state' => LiveBackupRun::COMPLETE, 'include_downloads' => false, 'started_at' => now(), 'finished_at' => now()],
        );

        $this->get('/settings/backups')
            ->assertSee('Last run was')
            ->assertSee('Finished');
    }

    /**
     * A live run has its own timeline, built from the stage key the job writes rather than from
     * a sentence. Without this, the modal can look populated while telling a waiting reader none
     * of the things block 17 promised: where the work is, how far it has got, or whether it is
     * still updating.
     */
    public function test_a_running_live_backup_has_a_polling_timeline_in_the_last_run_modal(): void
    {
        $this->writeLiveRun(progressStage: 'manga_half');

        $this->travel(34)->seconds();

        $page = Livewire::test('pages::settings.backups')->call('openRunModal');

        $page->assertSee('Checking the server')
            ->assertSee('Saving your library')
            ->assertSee('Saving your manga data')
            ->assertSee('Checking your manga data')
            ->assertSee('2 of 6')
            ->assertSee('34 seconds so far')
            ->assertSee('counting what the library holds')
            ->assertSee('bg-blue-500', false);

        $html = (string) $page->html();
        $modal = substr($html, (int) strpos($html, 'data-modal="backup-run"'));
        $modal = substr($modal, 0, (int) strpos($modal, '</dialog>'));

        $this->assertStringContainsString('wire:poll.3s', $modal, 'the open modal keeps watching a live run');
        $this->assertStringNotContainsString('aria-label="Close modal"', $modal, 'the running modal exposes no close button');
        $this->assertTrue($page->instance()->runModalIsRunning(), 'the running live row locks the modal like a running launcher record');
    }

    public function test_a_running_live_restore_has_a_polling_timeline_in_the_last_run_modal(): void
    {
        $this->writeRestoreRun(progressStage: 'manga');

        $page = Livewire::test('pages::settings.backups')->call('openRunModal');

        $page->assertSee('Checking your library')
            ->assertSee('Checking your manga data')
            ->assertSee('Restoring your manga data')
            ->assertSee('Restoring your library')
            ->assertSee('3 of 6')
            ->assertSee('Restore progress');

        $this->assertTrue($page->instance()->runModalIsRunning(), 'the running restore locks the modal like a running backup');
    }

    /**
     * A backup with a half missing is called incomplete, not listed as if it were whole.
     */
    public function test_an_incomplete_backup_is_called_incomplete(): void
    {
        $pair = $this->writePair();
        unlink($pair['manga']);

        $this->get('/settings/backups')
            ->assertSee('Backup is incomplete')
            ->assertSee('cannot be restored');
    }

    /**
     * And a file that no longer matches its digest is not called fine.
     */
    public function test_a_backup_whose_file_no_longer_matches_is_not_called_matching(): void
    {
        $pair = $this->writePair();
        file_put_contents($pair['manga'], 'TRUNCATED');

        $this->get('/settings/backups')
            ->assertSee('One file has changed')
            ->assertSee('not complete anymore');
    }

    /**
     * The folder is named, because a backup on a disk the reader cannot see is one they do not have.
     */
    public function test_it_says_where_the_backups_are(): void
    {
        $this->writePair();

        $this->get('/settings/backups')
            ->assertSee($this->folder)
            ->assertSee('papyrus restore');
    }

    /**
     * A saved backup says when it was taken, in a date a reader can read.
     *
     * The row used to carry the manifest's raw token, `20261014-203857`, under
     * the relative age. The token is still what restore is keyed on, but it is
     * not what a reader needs to tell one backup from another.
     */
    public function test_a_saved_backup_shows_when_it_was_taken(): void
    {
        $this->writePair();

        $this->get('/settings/backups')
            ->assertSee('14 October 2026')
            ->assertDontSee('20261014-203857</span>');
    }

    /**
     * What a restore kept is named and measured, so the disk space is accounted for.
     */
    public function test_it_says_what_a_restore_left_behind(): void
    {
        mkdir($this->folder.'/rollback-20261014-203857', 0o777, true);
        file_put_contents($this->folder.'/rollback-20261014-203857/previous-database.mv.db', str_repeat('x', 3 * 1048576));

        $this->get('/settings/backups')
            ->assertSee('What a restore kept')
            ->assertSee('rollback-20261014-203857')
            ->assertSee('3.0 MB');
    }

    // -------------------------------------------------------------------------------------------

    /*
     * The live backup.
     *
     * Phase 7 turned what the launcher alone did into doors this page owns, and the launcher-era
     * tests above moved with it instead of going silent: the refusal copy became the door-naming
     * copy, and "The last time" is one block from one door to another. The tests here carry the
     * button, the press, the poll gate and the run rows.
     */

    public function test_the_button_is_on_the_page_because_the_live_backup_exists(): void
    {
        $this->get('/settings/backups')->assertSee('Back up now');
    }

    /**
     * The press dispatches the job to the queue, without asking for the downloads folder.
     *
     * That the dispatch names the pressed reader is what makes the run row's attribution honest,
     * and that the flag parks at false without a tick is what the checkbox default means.
     */
    public function test_a_press_dispatches_a_live_backup_without_the_downloads_folder(): void
    {
        $user = auth()->user();
        Bus::fake([RunLiveBackup::class]);

        Livewire::test('pages::settings.backups')->call('start');

        Bus::assertDispatched(function (RunLiveBackup $job) use ($user): bool {
            return $job->userId === $user->id && $job->includeDownloads === false;
        });
    }

    /** A ticked checkbox in the dialog is what the job carries, not whatever was last pressed. */
    public function test_a_ticked_downloads_checkbox_reaches_the_job(): void
    {
        $user = auth()->user();
        Bus::fake([RunLiveBackup::class]);

        Livewire::test('pages::settings.backups')
            ->set('includeDownloads', true)
            ->call('start');

        Bus::assertDispatched(function (RunLiveBackup $job): bool {
            return $job->includeDownloads === true;
        });
    }

    /**
     * A second press is refused while a run claims to be running.
     *
     * Two runs sweep the same staging folder, and the first one's half staged files would
     * disappear under the second. The row the runs write is the guard, and this is the test
     * that would go red the day a mutant let the refusal pass.
     */
    public function test_a_second_press_is_refused_while_a_run_says_it_is_running(): void
    {
        $this->writeLiveRun();
        Bus::fake([RunLiveBackup::class]);

        Livewire::test('pages::settings.backups')->call('start');

        Bus::assertNotDispatched(RunLiveBackup::class);
    }

    /** A running run is named on the page, and a finished one is not called running. */
    public function test_a_running_run_is_named_and_a_finished_one_is_not(): void
    {
        $this->writeLiveRun();
        $this->get('/settings/backups')->assertSee('A backup is being taken');

        LiveBackupRun::query()->first()->update(['state' => LiveBackupRun::COMPLETE]);
        $this->get('/settings/backups')->assertDontSee('A backup is being taken');
    }

    /**
     * The poll gate stops when the run is over, and runs while it is not.
     *
     * The page polls every three seconds only while something is genuinely going, which means
     * the run's `isRunning()` word and nothing else: a complete run polling forever would be a
     * request every three seconds for as long as the page stays open.
     */
    public function test_the_page_polls_a_run_that_is_running_and_stops_when_it_is_not(): void
    {
        $this->writeLiveRun();
        $page = Livewire::test('pages::settings.backups')->set('polling', true);
        $this->assertStringContainsString('wire:poll.3s', (string) $page->html(), 'a running run is being watched');

        LiveBackupRun::query()->first()->update(['state' => LiveBackupRun::COMPLETE]);

        // A second lifecycle, because the component caches its computeds for its own life and a
        // mid life row change would read through that cache rather than through the database.
        $page = Livewire::test('pages::settings.backups')->set('polling', true);
        $this->assertStringNotContainsString('wire:poll.3s', (string) $page->html(), 'a finished run is not still watched');
    }

    /** A run that went quiet in the queue is called nothing yet, and nothing polls for it. */
    public function test_the_page_says_the_run_is_queued_when_no_row_exists_yet(): void
    {
        Bus::fake([RunLiveBackup::class]);

        $page = Livewire::test('pages::settings.backups')->call('start');

        $this->assertStringContainsString('Queued. The backup will appear in a moment.', (string) $page->html());
        $this->assertStringContainsString('wire:poll.3s', (string) $page->html(), 'the gap between the press and the row is watched');
    }

    /**
     * A finished run quotes its own closing sentence, the one the job wrote as it ended.
     */
    public function test_a_finished_run_carries_its_own_closing_sentence(): void
    {
        $this->writeLiveRun(state: LiveBackupRun::COMPLETE);

        $this->get('/settings/backups')
            ->assertSee('1 series and 1 chapters saved while Papyrus stayed open')
            ->assertSee('Finished');
    }

    public function test_an_old_backup_note_is_read_in_current_words(): void
    {
        $this->writeLiveRun(state: LiveBackupRun::COMPLETE);
        LiveBackupRun::query()->first()->update([
            'progress_note' => '1 series states and 1 chapter states against Suwayomi v2.4.2366, taken without stopping anything',
        ]);

        $this->get('/settings/backups')
            ->assertSee('Backup complete: 1 series and 1 chapter saved while Papyrus stayed open')
            ->assertDontSee('series states against Suwayomi');
    }

    /** A refused run names why, the sentence the run itself refused by. */
    public function test_a_refused_run_names_why_nothing_was_taken(): void
    {
        $this->writeLiveRun(state: LiveBackupRun::REFUSED);

        $this->get('/settings/backups')
            ->assertSee('The manga server did not report its version')
            ->assertSee('Refused');
    }

    /**
     * Each refusal cause keeps its own sentence, and only its own.
     *
     * The checkout's refused run stores a sentence from before the copy was split: it names an
     * empty server, then appends the wrong-file advice about a .tachibk. Read back through the
     * page, the empty-server cause must stop at "nothing was changed." and the wrong-file cause
     * must keep the Mihon or Suwayomi sentence it already had. A reader who never opened an
     * import is never told to use one.
     */
    public function test_each_refusal_cause_keeps_its_own_sentence(): void
    {
        $this->writeLiveRun(state: LiveBackupRun::REFUSED);

        LiveBackupRun::query()->firstOrFail()->update([
            'refusal_reason' => 'The downloaded manga half is a real backup of a library that holds nothing: the server answered with a file that carries its own shelves and no series at all, so there is no manga half to pair with the reader half. The run was refused with nothing taken. That file is not a Mihon or Suwayomi backup. It has to be the .tachibk that one of those apps writes from its own backup screen.',
        ]);

        $page = Livewire::test('pages::settings.backups');
        $summary = $page->instance()->runSummary(LiveBackupRun::query()->firstOrFail());

        $this->assertStringContainsString('has no series to save', $summary);
        $this->assertStringEndsWith('nothing was changed.', $summary);
        $this->assertStringNotContainsString('tachibk', $summary);
        $this->assertStringNotContainsString('import', $summary);
        $this->assertStringNotContainsString('Mihon', $summary);

        $wrongFile = $page->instance()->humanizeBackupSummary(
            'The downloaded manga half does not read as a Mihon or Suwayomi backup, so it cannot be one half of a pair: the bytes did not parse.',
        );

        $this->assertStringContainsString('not read as a Mihon or Suwayomi backup', $wrongFile);

        $this->get('/settings/backups')
            ->assertSee('The manga server answered, but it has no series to save.')
            ->assertDontSee('This file is not a Mihon or Suwayomi backup');
    }

    /**
     * A refused run is over, so its modal counts no live seconds and does not wear the green dot.
     *
     * The dot reads in the same orange as the row's Refused badge, and the elapsed clock belongs
     * only to a run that is still going. Before this, a refusal from days ago read "164261 seconds
     * so far" under a green dot, as if it were still working.
     */
    public function test_a_refused_run_shows_no_elapsed_time_and_no_green_dot(): void
    {
        $this->writeLiveRun(state: LiveBackupRun::REFUSED);

        $this->travel(120)->seconds();

        $page = Livewire::test('pages::settings.backups')->call('openRunModal');

        $html = (string) $page->html();
        $modal = substr($html, (int) strpos($html, 'data-modal="backup-run"'));
        $modal = substr($modal, 0, (int) strpos($modal, '</dialog>'));

        $this->assertStringContainsString('Refused', $modal);
        $this->assertStringNotContainsString('seconds so far', $modal);
        $this->assertStringNotContainsString('bg-green-500', $modal);
        $this->assertStringContainsString('bg-orange-500', $modal);
    }

    /**
     * A refused run that started no stage has no timeline to show.
     *
     * The refusal is written before any checkpoint runs, so six pending rows and "0 of 6"
     * read as work still waiting when nothing did. The refusal sentence is the verdict and
     * stays, with the run's timestamp and the close action.
     */
    public function test_a_refused_run_that_started_nothing_hides_the_checkpoint_timeline(): void
    {
        $this->writeLiveRun(state: LiveBackupRun::REFUSED);

        $page = Livewire::test('pages::settings.backups')->call('openRunModal');

        $html = (string) $page->html();
        $modal = substr($html, (int) strpos($html, 'data-modal="backup-run"'));
        $modal = substr($modal, 0, (int) strpos($modal, '</dialog>'));

        $this->assertStringContainsString('The manga server did not report its version', $modal);
        $this->assertStringContainsString('Started', $modal);
        $this->assertStringContainsString('Done', $modal);
        $this->assertStringNotContainsString('0 of 6', $modal);
        $this->assertStringNotContainsString('of 6', $modal);

        foreach ([
            'Checking the server',
            'Stopping Papyrus',
            'Saving your manga data',
            'Saving your library',
            'Checking the backup details',
            'Starting Papyrus again',
            'Checking your manga data',
            'Finishing the backup',
        ] as $stage) {
            $this->assertStringNotContainsString($stage, $modal, "the refused run has no {$stage} checkpoint");
        }
    }

    /** A failed run names what stopped it, said by the job that stopped. */
    public function test_a_failed_run_names_what_stopped_it(): void
    {
        $this->writeLiveRun(state: LiveBackupRun::FAILED);

        $this->get('/settings/backups')
            ->assertSee('This run stopped on an error: the staging disk filled up')
            ->assertSee('Stopped');
    }

    /**
     * A run that went silent reads as stopped, not as one still going.
     *
     * The row that claims to be running with nothing behind it is the import page's standing
     * trap, and the same shape lands here the day a worker is killed mid run.
     */
    public function test_a_run_whose_worker_died_does_not_read_as_running(): void
    {
        $this->writeLiveRun(stale: true);

        $this->get('/settings/backups')
            ->assertSee('Stopped')
            ->assertDontSee('A backup is being taken');
    }

    /**
     * The size the checkbox presses against, named with the number the folder really holds.
     */
    public function test_the_press_names_the_downloads_size_before_it_happens(): void
    {
        $folder = $this->folder.DIRECTORY_SEPARATOR.'downloads';
        mkdir($folder, 0o777, true);
        file_put_contents($folder.DIRECTORY_SEPARATOR.'pages-001', str_repeat('p', 13));
        file_put_contents($folder.DIRECTORY_SEPARATOR.'pages-002', str_repeat('q', 3));

        $this->fakeSuwayomi(settingsPath: $folder);

        Livewire::test('pages::settings.backups')
            ->call('openDialog')
            ->assertSee('holds 16 B right now');
    }

    /** Unknown is said, not invented: the honest answer when Suwayomi cannot be asked. */
    public function test_the_press_says_the_size_is_unknown_when_suwayomi_cannot_be_asked(): void
    {
        $this->fakeSuwayomi(settingsPath: null, unreachable: true);

        Livewire::test('pages::settings.backups')
            ->call('openDialog')
            ->assertSee('cannot ask Suwayomi how big the downloads folder is');
    }

    /**
     * The dialog's stays-closed side matters: a render of the page itself asks Suwayomi nothing.
     */
    public function test_reading_the_page_costs_suwayomi_nothing(): void
    {
        Http::fake(function (): never {
            throw new RuntimeException('The page must not reach Suwayomi while the dialog is closed.');
        });

        Livewire::test('pages::settings.backups')->assertOk();
    }

    // -------------------------------------------------------------------------------------------

    /*
     * The restore side's own tests, beside the live backup's rather than over them: the row
     * action, the destructive confirm and the two words it must never say, the press, the
     * refusals, the run rows, and the bar that maps the server's own restore vocabulary.
     */

    /** The Restore action is offered only on a pair both of whose halves are whole. */
    public function test_a_whole_pair_row_carries_the_restore_action_and_a_broken_one_does_not(): void
    {
        $this->writePair();

        $this->assertTrue(
            str_contains((string) $this->get('/settings/backups')->getContent(), "openRestoreDialog('20261014-203857'"),
            'the whole pair names the moment a restore can be opened on',
        );

        unlink($this->folder.DIRECTORY_SEPARATOR.'papyrus-20261014-203857.sqlite');

        $this->assertFalse(
            str_contains((string) $this->get('/settings/backups')->getContent(), "openRestoreDialog('20261014-203857'"),
            'an incomplete pair is not offered a restore',
        );
    }

    /**
     * The confirm names what the pair holds against what the app holds now, and names the
     * manga half's unreadable state when its file cannot be read, rather than inventing it.
     */
    public function test_the_confirm_names_both_sides_of_the_exchange(): void
    {
        $this->writePair();

        Livewire::test('pages::settings.backups')
            ->call('openRestoreDialog', '20261014-203857')
            ->assertSee('Restore this backup?')
            ->assertSee('This backup contains')
            ->assertSee('867 series and 21447 chapters')
            ->assertSee('Your library contains now')
            ->assertSee('0 series and 0 chapters')
            ->assertSee('The manga data could not be read');
    }

    /**
     * Every confirm number is read from the pair's own manifest and its own files, so the
     * confirm can be opened with Suwayomi unreachable and still read whole: no count in it
     * is ever the server's, and the vocabulary caution the feature was built under says the
     * server's own totalManga counts a wider set the pair does not name.
     */
    public function test_the_confirm_reads_nothing_from_the_servers_counters(): void
    {
        $this->writePair();

        Http::fake(function (): never {
            throw new RuntimeException('A confirm must not ask the server for any number in it.');
        });

        Livewire::test('pages::settings.backups')
            ->call('openRestoreDialog', '20261014-203857')
            ->assertSee('867 series and 21447 chapters');
    }

    /**
     * The two claims the confirm must never carry: that it restarts anything, and that the
     * app's own pre restore state survives. The first is a word for processes and nothing
     * here stops; the second is contradicted in the confirm's first sentence, which is why
     * that sentence is asserted too.
     */
    public function test_the_confirm_never_promises_a_restart_and_says_what_it_replaces(): void
    {
        $this->writePair();

        $html = (string) Livewire::test('pages::settings.backups')
            ->call('openRestoreDialog', '20261014-203857')
            ->html();

        $this->assertTrue(stripos($html, 'restart') === false, 'no word of restarting anything');
        $this->assertTrue(stripos($html, 'reboot') === false, 'no word of rebooting anything');
        $this->assertStringContainsString('Anything currently in your library that this backup does not contain will be removed', $html);
    }

    /** The press dispatches the restore for the pair the confirm was opened on. */
    public function test_a_restore_press_dispatches_the_job_for_the_open_pair(): void
    {
        $user = auth()->user();
        Bus::fake([RunLiveRestore::class]);

        Livewire::test('pages::settings.backups')
            ->call('openRestoreDialog', '20261014-203857')
            ->call('restore');

        Bus::assertDispatched(function (RunLiveRestore $job) use ($user): bool {
            return $job->userId === $user->id && $job->token === '20261014-203857';
        });
    }

    /**
     * Two restores swapping the same live database is the one thing this feature must never
     * do, so a second press waits behind the running row and dispatches nothing.
     */
    public function test_a_second_restore_press_is_refused_while_a_run_says_it_is_running(): void
    {
        $this->writePair();
        $this->writeRestoreRun();
        Bus::fake([RunLiveRestore::class]);

        Livewire::test('pages::settings.backups')
            ->call('openRestoreDialog', '20261014-203857')
            ->call('restore');

        Bus::assertNotDispatched(RunLiveRestore::class);
    }

    /**
     * The bar reads the server's own restore vocabulary: its state name in its own words,
     * its own counts, and the percent a reader acts on out of those counts.
     */
    public function test_the_bar_maps_the_servers_actual_restore(): void
    {
        $this->writeRestoreRun();
        $this->fakeSuwayomiWithStatus('RESTORING_MANGA', 4, 8);

        $this->get('/settings/backups')
            ->assertSee('Manga server: restoring series')
            ->assertSee('4 of 8')
            ->assertSee('aria-valuenow="50"', false);
    }

    /** A state name this app does not know is carried as the name it is, not a meaning invented for it. */
    public function test_a_server_state_this_app_does_not_know_keeps_its_own_name(): void
    {
        $this->writeRestoreRun();
        $this->fakeSuwayomiWithStatus('RESTORING_SOMETHING_NEW', 1, 8);

        $this->get('/settings/backups')->assertSee('Manga server: RESTORING_SOMETHING_NEW');
    }

    /**
     * A run row going without the server's word is waiting, not failure.
     *
     * The measured answer of a server process whose restore has not named itself yet is
     * `restoreStatus` answered null, and the page's sentence for it names the waiting rather
     * than borrowing a failure word for it.
     */
    public function test_a_run_going_without_the_servers_word_says_so(): void
    {
        $this->writeRestoreRun();
        $this->fakeSuwayomiWithStatus(null);

        $this->get('/settings/backups')
            ->assertSee('The manga server has not reported a stage yet')
            ->assertSee('A restore is running')
            ->assertDontSee('Manga server:');
    }

    /** The run rows read in the restore's own words, finished and refused alike. */
    public function test_the_restore_run_rows_carried_their_own_sentences(): void
    {
        $this->writeRestoreRun();

        $this->get('/settings/backups')->assertSee('A restore is running');

        LiveRestoreRun::query()->first()->update([
            'state' => LiveRestoreRun::COMPLETE,
            'token' => '20261014-203857',
            'progress_note' => 'Restore complete: 7 series and 21,447 chapters are back, along with your manga data.',
        ]);

        $this->get('/settings/backups')
            ->assertSee('Restore complete: 7 series and 21,447 chapters are back')
            ->assertDontSee('A restore is running');

        LiveRestoreRun::query()->first()->update([
            'state' => LiveRestoreRun::REFUSED,
            'refusal_reason' => 'The library copy does not match the backup details.',
        ]);

        $this->get('/settings/backups')
            ->assertSee('does not match the backup details')
            ->assertSee('Refused');
    }

    /** A restore run whose worker died reads as stopped, the backup card's own standing shape. */
    public function test_a_restore_run_that_went_silent_reads_as_stopped(): void
    {
        $this->writeRestoreRun(stale: true);

        $this->get('/settings/backups')
            ->assertSee('This restore stopped before it finished')
            ->assertDontSee('A restore is running');
    }

    /**
     * The restore card's poll gate mirrors the backup card's.
     *
     * With nothing pressed and nothing running the page is quiet and says so in its own
     * sentence; from the press until a run row exists the gap is polled and named as queued;
     * a running run keeps being watched; a settled one does not.
     */
    public function test_the_restore_card_polls_only_while_a_run_is_owed(): void
    {
        Bus::fake([RunLiveRestore::class]);

        $page = Livewire::test('pages::settings.backups');

        $this->assertStringNotContainsString('wire:poll.3s', (string) $page->html(), 'with nothing pressed and nothing running the page is quiet');
        $this->assertStringContainsString('Nothing has been restored from this page yet', (string) $page->html());

        $page = Livewire::test('pages::settings.backups')->set('restorePolling', true);

        $this->assertStringContainsString('wire:poll.3s', (string) $page->html(), 'the gap between the press and the row is watched');
        $this->assertStringContainsString('Queued. The restore will appear in a moment.', (string) $page->html());

        $this->writeRestoreRun();

        $page = Livewire::test('pages::settings.backups')->set('restorePolling', true);

        $this->assertStringContainsString('wire:poll.3s', (string) $page->html(), 'a running restore is being watched');

        LiveRestoreRun::query()->first()->update(['state' => LiveRestoreRun::REFUSED, 'refusal_reason' => 'refused for the shape of a return']);

        $page = Livewire::test('pages::settings.backups')->set('restorePolling', true);

        $this->assertStringNotContainsString('wire:poll.3s', (string) $page->html(), 'a settled restore is not still watched');
    }

    // -------------------------------------------------------------------------------------------

    /*
     * The export side's own tests, beside the restore's rather than over them: the press and
     * its refusal, the poll gate, the run rows in their states, and the receipt a finished row
     * carries out to the reader with its download under it.
     */

    /** The press dispatches the export for whoever pressed it, with no options to ask about. */
    public function test_an_export_press_dispatches_the_export_for_the_pressed_reader(): void
    {
        $user = auth()->user();
        Bus::fake([RunLiveExport::class]);

        Livewire::test('pages::settings.backups')->call('startExport');

        Bus::assertDispatched(function (RunLiveExport $job) use ($user): bool {
            return $job->userId === $user->id;
        });
    }

    /** A second press is refused while a run says it is running, the two other cards' own guard. */
    public function test_a_second_export_press_is_refused_while_a_run_says_it_is_running(): void
    {
        $this->writeExportRun();
        Bus::fake([RunLiveExport::class]);

        Livewire::test('pages::settings.backups')->call('startExport');

        Bus::assertNotDispatched(RunLiveExport::class);
    }

    /**
     * The export card's poll gate mirrors the restore card's.
     *
     * With nothing pressed and nothing running the page is quiet and says so in its own
     * sentence; from the press until a run row exists the gap is polled and named as queued;
     * a running run keeps being watched; a settled one does not.
     */
    public function test_the_export_card_polls_only_while_a_run_is_owed(): void
    {
        Bus::fake([RunLiveExport::class]);

        $page = Livewire::test('pages::settings.backups');

        $this->assertStringNotContainsString('wire:poll.3s', (string) $page->html(), 'with nothing pressed and nothing running the page is quiet');
        $this->assertStringContainsString('Nothing has been sent out from here yet', (string) $page->html());

        $page = Livewire::test('pages::settings.backups')->set('exportPolling', true);

        $this->assertStringContainsString('wire:poll.3s', (string) $page->html(), 'the gap between the press and the row is watched');
        $this->assertStringContainsString('Queued. The export will appear in a moment.', (string) $page->html());

        $this->writeExportRun();

        $page = Livewire::test('pages::settings.backups')->set('exportPolling', true);

        $this->assertStringContainsString('wire:poll.3s', (string) $page->html(), 'a running export is being watched');

        LiveExportRun::query()->first()->update(['state' => LiveExportRun::REFUSED]);

        $page = Livewire::test('pages::settings.backups')->set('exportPolling', true);

        $this->assertStringNotContainsString('wire:poll.3s', (string) $page->html(), 'a settled export is not still watched');
    }

    /**
     * The row a finished run wrote is the receipt the page names: the file's own name, the
     * counts the run's comparison produced, the byte size, the download under the run's key,
     * and the skipped sentences the run refused with.
     */
    public function test_a_finished_export_names_its_receipt_and_offers_the_download(): void
    {
        $this->writeExportRun(state: LiveExportRun::COMPLETE);

        $this->get('/settings/backups')
            ->assertSee('tachibk-20261021-120000.tachibk')
            ->assertSee('2 of 3 series merged, 2 shelves written and 4 read marks added (105 B).')
            ->assertSee('One series its plan could not carry: the server could not name its source.')
            ->assertSee('Download the file')
            ->assertSee('settings/backups/export/')
            ->assertSee('Finished');
    }

    /** The states a reader most needs told apart are complete and refused, and the page words them apart. */
    public function test_a_refused_export_names_why_and_a_running_one_reads_as_running(): void
    {
        $this->writeExportRun(state: LiveExportRun::REFUSED);

        $this->get('/settings/backups')
            ->assertSee('Suwayomi did not answer what version it is')
            ->assertSee('Refused')
            ->assertDontSee('Download the file');

        LiveExportRun::query()->first()->update(['state' => LiveExportRun::RUNNING]);

        $this->get('/settings/backups')
            ->assertSee('An export is running')
            ->assertSee('being written now')
            ->assertDontSee('Download the file');
    }

    /** An export run whose worker died reads as stopped, neither card's shape lost on the third. */
    public function test_an_export_run_that_went_silent_reads_as_stopped(): void
    {
        $this->writeExportRun(stale: true);

        $this->get('/settings/backups')
            ->assertSee('This run stopped without finishing')
            ->assertDontSee('An export is running');
    }

    // -------------------------------------------------------------------------------------------

    /**
     * A live backup run row, as the job writes one.
     *
     * The default is a running row with a stage note, because that is what a paused mid run
     * looks like on this page the moment a test needs it. `stale` ages the row past the page's
     * own silence threshold, which is how a killed worker shows up: running, and quiet.
     */
    private function writeLiveRun(string $state = LiveBackupRun::RUNNING, bool $stale = false, ?string $progressStage = null): void
    {
        $run = LiveBackupRun::create(array_filter([
            'state' => $state,
            'include_downloads' => false,
            'token' => $state === LiveBackupRun::COMPLETE ? '20261020-120000' : null,
            'progress_stage' => $progressStage ?? match ($state) {
                LiveBackupRun::RUNNING => 'manga_half',
                LiveBackupRun::COMPLETE => 'done',
                default => null,
            },
            'progress_note' => match ($state) {
                LiveBackupRun::RUNNING => 'counting what the library holds',
                LiveBackupRun::COMPLETE => 'Backup complete: 1 series and 1 chapters saved while Papyrus stayed open. Manga server v3.4.2366.',
                LiveBackupRun::FAILED => 'This run stopped on an error: the staging disk filled up',
                default => null,
            },
            'refusal_reason' => match ($state) {
                LiveBackupRun::REFUSED => 'The manga server did not report its version, so the backup could not be checked and nothing was changed.',
                LiveBackupRun::FAILED => 'the staging disk filled up',
                default => null,
            },
            'suwayomi_version' => $state === LiveBackupRun::COMPLETE ? 'v3.4.2366' : null,
            'series_states' => $state === LiveBackupRun::COMPLETE ? 1 : null,
            'chapter_states' => $state === LiveBackupRun::COMPLETE ? 1 : null,
            'started_at' => now(),
        ], static fn (mixed $value): bool => $value !== null));

        /*
         * `updated_at` is not fillable on the model, so a stale row is aged after it is written,
         * straight onto the column the page reads its silence threshold against.
         */
        if ($stale) {
            $run->forceFill(['updated_at' => now()->subSeconds(320)])->save();
        }
    }

    /**
     * What Suwayomi answers while a page test is open.
     *
     * `settingsPath` is the folder the size line names, and `unreachable` stands in for the
     * server not being there: both are shapes the real page has to survive, and both arrive
     * through the same settings query the run itself sends.
     */
    private function fakeSuwayomi(?string $settingsPath, bool $unreachable = false): void
    {
        Http::fake(function (Request $request) use ($settingsPath, $unreachable) {
            if (str_contains((string) $request->body(), 'mutation Login')) {
                return $unreachable
                    ? Http::response([], 503)
                    : Http::response(['data' => ['login' => [
                        'accessToken' => $this->accessToken(),
                        'refreshToken' => $this->refreshToken(),
                    ]]]);
            }

            if (str_contains((string) $request->body(), 'query DownloadsLocation')) {
                return $unreachable
                    ? Http::response([], 503)
                    : Http::response(['data' => ['settings' => ['downloadsPath' => $settingsPath]]]);
            }

            return $unreachable
                ? Http::response([], 503)
                : Http::response(['data' => []]);
        });
    }

    /**
     * A live restore run row, as the job writes one, beside `writeLiveRun()`.
     *
     * The default is a running row carrying the job's own first stage note, because that is
     * what a restore pressed a moment ago looks like on this page the moment a test needs it.
     * `stale` ages the row past the page's silence threshold, `writeLiveRun()`'s own shape:
     * `updated_at` is not fillable on the model, so the row is aged after it is written,
     * straight onto the column the abandonment check reads.
     */
    private function writeRestoreRun(string $state = LiveRestoreRun::RUNNING, bool $stale = false, ?string $progressStage = null): void
    {
        $run = LiveRestoreRun::create(array_filter([
            'token' => $state === LiveRestoreRun::COMPLETE ? '20261014-203857' : null,
            'state' => $state,
            'progress_stage' => $progressStage ?? match ($state) {
                LiveRestoreRun::RUNNING => 'manga',
                LiveRestoreRun::COMPLETE => 'done',
                default => null,
            },
            'progress_note' => match ($state) {
                LiveRestoreRun::RUNNING => 'Starting the restore',
                LiveRestoreRun::COMPLETE => 'Restore complete: 7 series and 21,447 chapters are back, along with your manga data.',
                default => null,
            },
            'refusal_reason' => match ($state) {
                LiveRestoreRun::REFUSED => 'The library copy does not match the backup details.',
                default => null,
            },
            'series_states' => $state === LiveRestoreRun::COMPLETE ? 7 : null,
            'chapter_states' => $state === LiveRestoreRun::COMPLETE ? 21447 : null,
            'started_at' => now(),
            'finished_at' => $state === LiveRestoreRun::COMPLETE ? now() : null,
        ], static fn (mixed $value): bool => $value !== null));

        if ($stale) {
            $run->forceFill(['updated_at' => now()->subSeconds(320)])->save();
        }
    }

    /**
     * A live export run row, as the job writes one, beside the other two helpers.
     *
     * The default is a running row carrying the job's own first stage note. `stale` ages the
     * row past the page's silence threshold the same way the other two do. A complete row
     * carries the receipt numbers a real deliver() stamps: the product name from the clock,
     * its byte count, in file against planned and matched, the shelves and read marks the
     * merger added, and the one skipped sentence the run's report carries for the series its
     * plan could not take.
     */
    private function writeExportRun(string $state = LiveExportRun::RUNNING, bool $stale = false): void
    {
        $run = LiveExportRun::create(array_filter([
            'state' => $state,
            'file_name' => $state === LiveExportRun::COMPLETE ? 'tachibk-20261021-120000.tachibk' : null,
            'progress_note' => match ($state) {
                LiveExportRun::RUNNING => 'Starting the export',
                LiveExportRun::COMPLETE => '3 series merged of 3 planned, 2 shelves and 4 read marks onto the file',
                default => null,
            },
            'refusal_reason' => match ($state) {
                LiveExportRun::REFUSED => 'Suwayomi did not answer what version it is, so the export was refused with nothing merged.',
                default => null,
            },
            'file_bytes' => $state === LiveExportRun::COMPLETE ? 105 : null,
            'series_in_file' => $state === LiveExportRun::COMPLETE ? 5 : null,
            'series_planned' => $state === LiveExportRun::COMPLETE ? 3 : null,
            'series_matched' => $state === LiveExportRun::COMPLETE ? 2 : null,
            'shelves_appended' => $state === LiveExportRun::COMPLETE ? 2 : null,
            'read_marks' => $state === LiveExportRun::COMPLETE ? 4 : null,
            'skipped' => $state === LiveExportRun::COMPLETE
                ? ['One series its plan could not carry: the server could not name its source.']
                : null,
            'started_at' => now(),
        ], static fn (mixed $value): bool => $value !== null));

        if ($stale) {
            $run->forceFill(['updated_at' => now()->subSeconds(320)])->save();
        }
    }

    /**
     * What Suwayomi answers about its own restore while a page test is open.
     *
     * The login first, `fakeSuwayomi()`'s own shape, because the page asks for its token before
     * it asks what the server says. A null state stands for the measured answer of a server
     * process whose restore has never named itself: `restoreStatus` answered null.
     */
    private function fakeSuwayomiWithStatus(?string $state, int $progress = 0, int $total = 0): void
    {
        Http::fake(function (Request $request) use ($state, $progress, $total) {
            if (str_contains((string) $request->body(), 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            if (str_contains((string) $request->body(), 'restoreStatus')) {
                return Http::response(['data' => ['restoreStatus' => $state === null ? null : [
                    'state' => $state,
                    'mangaProgress' => $progress,
                    'totalManga' => $total,
                ]]]);
            }

            return Http::response(['data' => []]);
        });
    }

    /** @return array{manifest: string, reader: string, manga: string} */
    private function writePair(string $token = '20261014-203857'): array
    {
        $reader = $this->folder.DIRECTORY_SEPARATOR.'papyrus-'.$token.'.sqlite';
        $manga = $this->folder.DIRECTORY_SEPARATOR.'suwayomi-'.$token.'.mv.db';
        $manifest = $this->folder.DIRECTORY_SEPARATOR.'backup-'.$token.'.json';

        file_put_contents($reader, 'READER HALF');
        file_put_contents($manga, 'MANGA HALF');

        file_put_contents($manifest, (string) json_encode([
            'token' => $token,
            'taken_at' => '2026-10-14T20:38:57+00:00',
            'papyrus' => [
                'file' => basename($reader),
                'sha256' => hash_file('sha256', $reader),
                'bytes' => filesize($reader),
                'series_states' => 867,
                'chapter_states' => 21447,
            ],
            'suwayomi' => [
                'file' => basename($manga),
                'sha256' => hash_file('sha256', $manga),
                'bytes' => filesize($manga),
                'server_version' => '2.4.2366',
            ],
        ], JSON_PRETTY_PRINT));

        return ['manifest' => $manifest, 'reader' => $reader, 'manga' => $manga];
    }

    private function writeRun(bool $failed = false, bool $stalled = false, bool $retried = false): void
    {
        $stages = [];

        foreach (RunRecord::SNAPSHOT_STAGES as $stage) {
            $stages[$stage] = ['state' => 'done', 'attempts' => 1, 'message' => $stage.' finished'];
        }

        $stages['checking_target']['message'] = 'nothing is in the way';

        if ($failed) {
            $stages['copying_manga_half'] = [
                'state' => 'failed',
                'attempts' => 1,
                'message' => 'Suwayomi (pid 4242) is still running after thirty seconds',
            ];
        }

        if ($retried) {
            $stages['stopping']['attempts'] = 2;
        }

        if ($stalled) {
            $stages['copying_manga_half'] = [
                'state' => 'running',
                'attempts' => 1,
                'message' => 'waiting for Suwayomi (pid 4242) to go',
            ];
        }

        file_put_contents($this->folder.DIRECTORY_SEPARATOR.'run-20261014-203857.json', (string) json_encode([
            'version' => 1,
            'operation' => 'snapshot',
            'token' => '20261014-203857',
            'started_at' => gmdate('c', $stalled ? time() - 600 : time() - 60),
            'stage_order' => RunRecord::SNAPSHOT_STAGES,
            'stages' => $stages,
        ], JSON_PRETTY_PRINT));
    }
}
