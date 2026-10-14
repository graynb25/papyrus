<?php

namespace Tests\Feature\Settings;

use App\Backup\BackupLibrary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Papyrus\Launcher\RunRecord;
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
