<?php

namespace Tests\Feature\Settings;

use App\Backup\BackupLibrary;
use App\Jobs\RunLiveBackup;
use App\Models\LiveBackupRun;
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
 * The Backups settings page: what can be restored, and what the last backup did.
 *
 * ## What is asserted here
 *
 * That the page tells a reader the truth in the three states that matter, and that it never says a
 * backup is fine when it is not. Those are:
 *
 *   - a run that finished, with every stage accounted for
 *   - a run that failed, naming the step and quoting the reason
 *   - a run whose launcher was killed, which must not read as one still going
 *
 * ## What is deliberately not asserted
 *
 * That the page can start a backup. It cannot, and the page says so in the place a button would
 * otherwise be. The manga half has to be copied with nothing writing it, which means Suwayomi has to
 * be stopped, which means this process is stopped. A test asserting a button exists would be asserting
 * something false about the architecture.
 *
 * What it does assert is that the sentence saying so is on the page, because a reader who came here
 * to make a backup and found nothing but an empty table would conclude the feature is missing rather
 * than that it cannot run from in here.
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
     * The sentence that explains why there is no button, on a page with no backups.
     *
     * An empty table with no explanation is indistinguishable from a feature that does not exist, and
     * this is a feature that cannot run from inside the application for a reason worth one sentence.
     */
    public function test_it_explains_that_a_backup_cannot_be_taken_from_here(): void
    {
        $this->get('/settings/backups')
            ->assertSee('cannot be done from inside Papyrus')
            ->assertSee('papyrus snapshot');
    }

    public function test_with_nothing_taken_yet_it_says_so_rather_than_showing_an_empty_table(): void
    {
        $this->get('/settings/backups')
            ->assertSee('No backups yet')
            ->assertSee('Nothing has been recorded yet');
    }

    /**
     * A finished backup, with its stages, in the words the manifest used.
     */
    public function test_it_lists_a_backup_and_what_it_holds(): void
    {
        $this->writePair();
        $this->writeRun();

        $this->get('/settings/backups')
            ->assertSee('867 series and 21,447 chapter states')
            ->assertSee('Both halves match')
            ->assertSee('2.4.2366')
            ->assertSee('This backup finished.');
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

        $this->get('/settings/backups')
            ->assertSee('failed at copying manga half')
            ->assertSee('still running after thirty seconds');
    }

    /**
     * A launcher that was killed reads as stalled, not as one still going.
     */
    public function test_a_run_whose_launcher_died_does_not_read_as_still_running(): void
    {
        $this->writeRun(stalled: true);

        $this->get('/settings/backups')
            ->assertSee('did not finish')
            ->assertDontSee('is still running');
    }

    /**
     * A retry is visible, because "failed once then worked" and "worked" are different facts.
     */
    public function test_a_retried_step_is_shown_as_retried(): void
    {
        $this->writeRun(retried: true);

        $this->get('/settings/backups')
            ->assertSee('tried 2 times')
            ->assertSee('was tried more than once');
    }

    /**
     * A backup with a half missing is called incomplete, not listed as if it were whole.
     */
    public function test_an_incomplete_backup_is_called_incomplete(): void
    {
        $pair = $this->writePair();
        unlink($pair['manga']);

        $this->get('/settings/backups')
            ->assertSee('Incomplete')
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
            ->assertSee('One does not match')
            ->assertSee('no longer matches the manifest');
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
     * These are the new shape's tests, written beside the old ones rather than over them: the
     * banner tests above carry the launcher-only age until the page rework rewrites them to the
     * new behavior, and the tests here carry the button, the press, the poll gate and the run
     * rows. A test asserting a button used to be asserting something false about the
     * architecture; since phase 7's live backup it is the true thing, and these say so.
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

        $this->assertStringContainsString('Queued. The pair will appear in a moment.', (string) $page->html());
        $this->assertStringContainsString('wire:poll.3s', (string) $page->html(), 'the gap between the press and the row is watched');
    }

    /**
     * A finished run quotes its own closing sentence, the one the job wrote as it ended.
     */
    public function test_a_finished_run_carries_its_own_closing_sentence(): void
    {
        $this->writeLiveRun(state: LiveBackupRun::COMPLETE);

        $this->get('/settings/backups')
            ->assertSee('1 series states and 1 chapter states against Suwayomi v3.4.2366, taken without stopping anything')
            ->assertSee('Finished');
    }

    /** A refused run names why, the sentence the run itself refused by. */
    public function test_a_refused_run_names_why_nothing_was_taken(): void
    {
        $this->writeLiveRun(state: LiveBackupRun::REFUSED);

        $this->get('/settings/backups')
            ->assertSee('Suwayomi did not answer what version it is')
            ->assertSee('Refused');
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

    /**
     * A live backup run row, as the job writes one.
     *
     * The default is a running row with a stage note, because that is what a paused mid run
     * looks like on this page the moment a test needs it. `stale` ages the row past the page's
     * own silence threshold, which is how a killed worker shows up: running, and quiet.
     */
    private function writeLiveRun(string $state = LiveBackupRun::RUNNING, bool $stale = false): void
    {
        $run = LiveBackupRun::create(array_filter([
            'state' => $state,
            'include_downloads' => false,
            'token' => $state === LiveBackupRun::COMPLETE ? '20261020-120000' : null,
            'progress_note' => match ($state) {
                LiveBackupRun::RUNNING => 'counting what the library holds',
                LiveBackupRun::COMPLETE => '1 series states and 1 chapter states against Suwayomi v3.4.2366, taken without stopping anything',
                LiveBackupRun::FAILED => 'This run stopped on an error: the staging disk filled up',
                default => null,
            },
            'refusal_reason' => match ($state) {
                LiveBackupRun::REFUSED => 'Suwayomi did not answer what version it is, so the manifest cannot be written and the run was refused with nothing taken.',
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
