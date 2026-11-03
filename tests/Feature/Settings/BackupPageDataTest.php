<?php

namespace Tests\Feature\Settings;

use App\Backup\BackupLibrary;
use App\Live\Backups\BackupPageData;
use App\Models\LiveBackupRun;
use App\Models\LiveExportRun;
use App\Models\LiveRestoreRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Papyrus\Launcher\RunRecord;
use RuntimeException;
use Tests\Support\BuildsTachibkBackups;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * The backups page's reads, exercised without mounting the page.
 *
 * These cover the shapes the page depends on: newest first by id, twenty rows per live
 * card, the newest of the four doors in one event, the pair and diff the restore confirm
 * reads, the completed restore per token, the server's own restore status, and the
 * downloads size. The visible sentences stay in BackupsPageTest.
 */
class BackupPageDataTest extends TestCase
{
    use BuildsTachibkBackups;
    use MintsTokens;
    use RefreshDatabase;

    private string $folder = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = $this->scratchPath('papyrus-backupdata');

        $this->app->instance(BackupLibrary::class, new BackupLibrary($this->folder));
    }

    private function data(): BackupPageData
    {
        return app(BackupPageData::class);
    }

    public function test_every_read_is_empty_before_any_press(): void
    {
        $data = $this->data();

        $this->assertSame($this->folder, $data->directory());
        $this->assertSame([], $data->pairs());
        $this->assertSame([], $data->leftovers());
        $this->assertNull($data->latestEvent());
        $this->assertNull($data->activeRun());
        $this->assertTrue($data->liveRuns()->isEmpty());
        $this->assertNull($data->activeRestoreRun());
        $this->assertTrue($data->restoreRuns()->isEmpty());
        $this->assertNull($data->activeExportRun());
        $this->assertTrue($data->exportRuns()->isEmpty());
        $this->assertNull($data->restoreDiff(true, null));
        $this->assertNull($data->restoreServerStatus());
        $this->assertNull($data->lastRestoredAt('20261014-203857'));
        $this->assertNull($data->downloadsSize(false));
    }

    public function test_each_run_list_is_newest_first_and_stops_at_twenty(): void
    {
        for ($index = 1; $index <= 25; $index++) {
            LiveBackupRun::create([
                'state' => LiveBackupRun::RUNNING,
                'include_downloads' => false,
                'started_at' => now(),
            ]);
            LiveRestoreRun::create([
                'state' => LiveRestoreRun::RUNNING,
                'started_at' => now(),
            ]);
            LiveExportRun::create([
                'state' => LiveExportRun::RUNNING,
                'started_at' => now(),
            ]);
        }

        $data = $this->data();

        $this->assertSame(25, $data->activeRun()?->id);
        $this->assertSame(25, $data->activeRestoreRun()?->id);
        $this->assertSame(25, $data->activeExportRun()?->id);

        $this->assertSame(20, $data->liveRuns()->count());
        $this->assertSame(25, $data->liveRuns()->first()?->id);
        $this->assertSame(6, $data->liveRuns()->last()?->id);

        $this->assertSame(20, $data->restoreRuns()->count());
        $this->assertSame(25, $data->restoreRuns()->first()?->id);
        $this->assertSame(6, $data->restoreRuns()->last()?->id);

        $this->assertSame(20, $data->exportRuns()->count());
        $this->assertSame(25, $data->exportRuns()->first()?->id);
        $this->assertSame(6, $data->exportRuns()->last()?->id);
    }

    public function test_latest_event_reads_a_launcher_record_when_it_is_the_only_door(): void
    {
        $this->writeRun();

        $latest = $this->data()->latestEvent();

        $this->assertNotNull($latest);
        $this->assertSame('green', $latest['tone']);
        $this->assertSame('20261014-203857', $latest['outcome']?->token);
        $this->assertNull($latest['row']);
        $this->assertSame('', $latest['summary']);
    }

    public function test_latest_event_takes_the_newest_live_run_across_the_three_doors(): void
    {
        LiveBackupRun::create([
            'state' => LiveBackupRun::RUNNING,
            'include_downloads' => false,
            'progress_note' => 'Saving the library.',
            'started_at' => now()->subSeconds(90),
        ]);
        LiveRestoreRun::create([
            'state' => LiveRestoreRun::RUNNING,
            'progress_note' => 'Restoring the manga data.',
            'started_at' => now()->subSeconds(60),
        ]);
        $export = LiveExportRun::create([
            'state' => LiveExportRun::RUNNING,
            'progress_note' => 'Writing the export.',
            'started_at' => now()->subSeconds(30),
        ]);

        $latest = $this->data()->latestEvent();

        $this->assertNotNull($latest);
        $this->assertSame('blue', $latest['tone']);
        $this->assertSame('Running', $latest['label']);
        $this->assertSame('Writing the export.', $latest['summary']);
        $this->assertInstanceOf(LiveExportRun::class, $latest['row']);
        $this->assertSame($export->id, $latest['row']->id);
    }

    public function test_latest_event_names_a_failed_launcher_run_in_red(): void
    {
        $this->writeRun('failed');

        $latest = $this->data()->latestEvent();

        $this->assertNotNull($latest);
        $this->assertSame('red', $latest['tone']);
        $this->assertTrue($latest['outcome']?->failed());
    }

    public function test_pairs_and_leftovers_read_what_is_on_disk(): void
    {
        $this->writePair('20261014-203857');
        $this->writePair('20261015-000000', withReaderHalf: true);

        mkdir($this->folder.DIRECTORY_SEPARATOR.'rollback-20261014-203857', 0o777, true);
        file_put_contents(
            $this->folder.DIRECTORY_SEPARATOR.'rollback-20261014-203857'.DIRECTORY_SEPARATOR.'previous-database.mv.db',
            str_repeat('x', 3 * 1048576),
        );

        $data = $this->data();
        $pairs = $data->pairs();
        $leftovers = $data->leftovers();

        $this->assertCount(2, $pairs);
        $this->assertSame('20261015-000000', $pairs[0]->token);
        $this->assertSame('20261014-203857', $pairs[1]->token);
        $this->assertCount(1, $leftovers);
        $this->assertSame('rollback-20261014-203857', $leftovers[0]['name']);
        $this->assertSame(3 * 1048576, $leftovers[0]['bytes']);
    }

    public function test_restore_pair_and_diff_read_the_pairs_own_numbers(): void
    {
        $this->writePair();

        $owner = User::factory()->create();

        DB::table('series_states')->insert([
            'user_id' => $owner->id,
            'suwayomi_manga_id' => 111,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('chapter_states')->insert([
            [
                'user_id' => $owner->id,
                'suwayomi_chapter_id' => 1001,
                'suwayomi_manga_id' => 111,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $owner->id,
                'suwayomi_chapter_id' => 1002,
                'suwayomi_manga_id' => 111,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $data = $this->data();

        $this->assertNull($data->restorePair(null));
        $this->assertNull($data->restorePair('missing-token'));
        $this->assertNull($data->restoreDiff(false, '20261014-203857'));

        $pair = $data->restorePair('20261014-203857');
        $this->assertNotNull($pair);
        $this->assertSame('20261014-203857', $pair->token);
        $this->assertSame(867, $pair->seriesStates);
        $this->assertSame(21447, $pair->chapterStates);

        $diff = $data->restoreDiff(true, '20261014-203857');
        $this->assertNotNull($diff);
        $this->assertSame(['series' => 867, 'chapters' => 21447], $diff['pair']);
        $this->assertSame(['series' => 1, 'chapters' => 2], $diff['live']);
        $this->assertSame(2, $diff['manga']['library_series']);
        $this->assertSame(7, $diff['manga']['chapters']);
        $this->assertSame(3, $diff['manga']['chapters_read']);
        $this->assertNull($diff['mangaProblem']);
        $this->assertSame('2026-10-14T20:38:57+00:00', $diff['takenAt']);
        $this->assertSame('2.4.2366', $diff['version']);
    }

    public function test_restore_diff_names_an_unreadable_manga_half(): void
    {
        $this->writePair(mangaBytes: 'NOT A BACKUP');

        $diff = $this->data()->restoreDiff(true, '20261014-203857');

        $this->assertNotNull($diff);
        $this->assertSame([], $diff['manga']);
        $this->assertStringContainsString('not a Mihon or Suwayomi backup', (string) $diff['mangaProblem']);
    }

    public function test_last_restored_at_is_the_newest_complete_run_for_that_token(): void
    {
        $finished = now()->subMinutes(30)->startOfSecond();
        $otherFinished = now()->startOfSecond();

        LiveRestoreRun::create([
            'token' => '20261014-203857',
            'state' => LiveRestoreRun::COMPLETE,
            'started_at' => now()->subDays(2),
            'finished_at' => now()->subDay(),
        ]);
        LiveRestoreRun::create([
            'token' => '20261014-203857',
            'state' => LiveRestoreRun::COMPLETE,
            'started_at' => now()->subHour(),
            'finished_at' => $finished,
        ]);
        LiveRestoreRun::create([
            'token' => '20261014-203857',
            'state' => LiveRestoreRun::FAILED,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now(),
        ]);
        LiveRestoreRun::create([
            'token' => '20261015-000000',
            'state' => LiveRestoreRun::COMPLETE,
            'started_at' => now(),
            'finished_at' => $otherFinished,
        ]);

        $data = $this->data();

        $restoredAt = $data->lastRestoredAt('20261014-203857');
        $this->assertNotNull($restoredAt);
        $this->assertSame($finished->timestamp, $restoredAt->timestamp);

        $other = $data->lastRestoredAt('20261015-000000');
        $this->assertNotNull($other);
        $this->assertSame($otherFinished->timestamp, $other->timestamp);
    }

    public function test_restore_server_status_is_null_until_a_live_restore_is_running(): void
    {
        $data = $this->data();

        $this->assertNull($data->restoreServerStatus());

        LiveRestoreRun::create([
            'state' => LiveRestoreRun::COMPLETE,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $this->assertNull($data->restoreServerStatus());

        $running = LiveRestoreRun::create([
            'state' => LiveRestoreRun::RUNNING,
            'progress_note' => 'Restoring the manga data.',
            'started_at' => now(),
        ]);

        $this->fakeSuwayomiWithStatus('RESTORING_MANGA', 4, 8);

        $this->assertSame(
            [
                'unreachable' => false,
                'status' => ['state' => 'RESTORING_MANGA', 'mangaProgress' => 4, 'totalManga' => 8],
            ],
            $data->restoreServerStatus(),
        );

        $running->forceFill(['updated_at' => now()->subSeconds(320)])->save();

        $this->assertNull($data->restoreServerStatus(), 'a silent row costs the server nothing');
    }

    public function test_restore_server_status_names_an_unreachable_server(): void
    {
        LiveRestoreRun::create([
            'state' => LiveRestoreRun::RUNNING,
            'started_at' => now(),
        ]);

        Http::fake(fn () => Http::response([], 503));

        $this->assertSame(
            ['unreachable' => true, 'status' => null],
            $this->data()->restoreServerStatus(),
        );
    }

    public function test_a_closed_dialog_costs_suwayomi_nothing(): void
    {
        Http::fake(function (): never {
            throw new RuntimeException('A closed dialog must not reach Suwayomi.');
        });

        $this->assertNull($this->data()->downloadsSize(false));
    }

    public function test_downloads_size_measures_the_folder_while_the_dialog_is_open(): void
    {
        $downloads = $this->scratchPath('papyrus-downloads');
        file_put_contents($downloads.DIRECTORY_SEPARATOR.'pages-001', str_repeat('p', 13));
        file_put_contents($downloads.DIRECTORY_SEPARATOR.'pages-002', str_repeat('q', 3));

        $this->fakeSuwayomi(settingsPath: $downloads);

        $this->assertSame(16, $this->data()->downloadsSize(true));
    }

    public function test_downloads_size_is_unknown_when_suwayomi_cannot_be_asked(): void
    {
        $this->fakeSuwayomi(settingsPath: null, unreachable: true);

        $this->assertNull($this->data()->downloadsSize(true));
    }

    /**
     * A pair on disk, shaped like the one the launcher writes.
     *
     * The manga half is a real synthetic `.tachibk`, because the restore diff reads it
     * through the same format reader the run verifies with.
     *
     * @return array{manifest: string, reader: string, manga: string}
     */
    private function writePair(
        string $token = '20261014-203857',
        string $mangaBytes = '',
        bool $withReaderHalf = true,
    ): array {
        $reader = $this->folder.DIRECTORY_SEPARATOR.'papyrus-'.$token.'.sqlite';
        $manga = $this->folder.DIRECTORY_SEPARATOR.'suwayomi-'.$token.'.tachibk';
        $manifest = $this->folder.DIRECTORY_SEPARATOR.'backup-'.$token.'.json';

        if ($withReaderHalf) {
            file_put_contents($reader, 'READER HALF');
        }

        file_put_contents($manga, $mangaBytes === '' ? $this->tachibkBytes() : $mangaBytes);

        file_put_contents($manifest, (string) json_encode([
            'token' => $token,
            'taken_at' => '2026-10-14T20:38:57+00:00',
            'papyrus' => [
                'file' => basename($reader),
                'sha256' => is_file($reader) ? hash_file('sha256', $reader) : str_repeat('0', 64),
                'bytes' => is_file($reader) ? filesize($reader) : 0,
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

    private function writeRun(string $state = 'finished'): void
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

        if ($state === 'stalled') {
            $stages['copying_manga_half'] = [
                'state' => 'running',
                'attempts' => 1,
                'message' => 'waiting for Suwayomi to go',
            ];
        }

        file_put_contents(
            $this->folder.DIRECTORY_SEPARATOR.'run-20261014-203857.json',
            (string) json_encode([
                'version' => 1,
                'operation' => 'snapshot',
                'token' => '20261014-203857',
                'started_at' => gmdate('c', $state === 'stalled' ? time() - 600 : time() - 60),
                'stage_order' => RunRecord::SNAPSHOT_STAGES,
                'stages' => $stages,
            ], JSON_PRETTY_PRINT),
        );
    }

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
}
