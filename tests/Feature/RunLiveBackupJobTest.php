<?php

namespace Tests\Feature;

use App\Backup\BackupLibrary;
use App\Backup\LiveBackup;
use App\Jobs\RunLiveBackup;
use App\Models\LiveBackupRun;
use App\Models\User;
use App\Suwayomi\AuthManager;
use App\Suwayomi\Gateway;
use App\Suwayomi\Transport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\BuildsTachibkBackups;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * The job around the live pair: the row it writes, and how a press ends on it.
 *
 * ## Why it stands alone like LiveBackupTest
 *
 * For the same reason, kept short here rather than restated: `RefreshDatabase` opens a
 * transaction and the reader half is a `VACUUM INTO`, which refuses one. The connection
 * is pointed at a real file, migrated, and given a factory user and one series state with
 * one chapter state, so the staged half has rows to be verified against. Suwayomi's side
 * is `Http::fake` from the same measured shapes, one fake for the whole class.
 *
 * ## What the tests are about
 *
 * Not the run, which LiveBackupTest owns end to end. The row: that a completed press
 * names the token of the pair it produced, that a refusal records the sentence with
 * nothing taken, that an unexpected error fails the row honestly and leaves the stack
 * in `failed_jobs`, and that a run keeps going when its reader vanished between press
 * and execution, because a backup has no owner to go with them.
 */
class RunLiveBackupJobTest extends TestCase
{
    use BuildsTachibkBackups;
    use MintsTokens;

    private const BACKUP_PATH = '/api/graphql/files/backup/org.suwayomi.tachidesk._2026-10-20_14-02.tachibk';

    private string $scratch;

    /** What the settings query answers for `downloadsPath`. Null means nothing usable. */
    private ?string $downloadsPath = null;

    /** What the file GET answers. Replaced by a 503 in the tests that need the run to refuse. */
    private bool $fileGetAnswersBytes = true;

    /** What `aboutServer` answers. Set false to leave the version unknowable. */
    private bool $answersItsVersion = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-live-job-'.getmypid().'-'.uniqid();

        File::ensureDirectoryExists($this->scratch);

        /*
         * A real file-backed database, migrated, so the half the run stages carries rows and
         * the comparison the run makes has two ends.
         */
        $database = $this->scratch.DIRECTORY_SEPARATOR.'app.sqlite';

        File::put($database, '');

        config(['database.connections.sqlite.database' => $database]);

        DB::purge('sqlite');

        $this->artisan('migrate', ['--force' => true])->run();

        $user = User::factory()->create(['id' => 1]);

        DB::table('series_states')->insert([
            'user_id' => $user->id,
            'suwayomi_manga_id' => 7,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        DB::table('chapter_states')->insert([
            'user_id' => $user->id,
            'suwayomi_chapter_id' => 4242,
            'suwayomi_manga_id' => 7,
            'is_read' => 1,
            'is_bookmarked' => 0,
            'last_page_read' => 0,
            'read_seconds' => 0,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        config(['papyrus.suwayomi.url' => 'http://127.0.0.1:4567']);

        Http::fake(function (Request $request) {
            $body = (string) $request->body();

            if (str_contains($body, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            if (str_contains($body, 'aboutServer')) {
                return $this->answersItsVersion
                    ? Http::response(['data' => ['aboutServer' => ['version' => 'v2.4.2366']]])
                    : Http::response([], 503);
            }

            if (str_contains($body, 'query DownloadsLocation')) {
                return Http::response(['data' => ['settings' => ['downloadsPath' => $this->downloadsPath]]]);
            }

            if (str_contains($body, 'mutation CreateBackup')) {
                return Http::response(['data' => ['createBackup' => ['url' => self::BACKUP_PATH]]]);
            }

            if (str_contains($request->url(), '/files/backup/')) {
                return $this->fileGetAnswersBytes
                    ? Http::response((string) $this->tachibkBytes(), 200, ['Content-Type' => 'application/octet-stream'])
                    : Http::response([], 503);
            }

            return Http::response(['data' => []]);
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    /**
     * A completed press: the row carries the token of the pair that landed, the two state
     * counts, the version the manifest binds to, the manga summary, and as its last note
     * not what it started as but the closing sentence the run delivered.
     */
    public function test_a_completed_press_records_its_receipt(): void
    {
        (new RunLiveBackup(1))->handle(app(LiveBackup::class));

        $run = LiveBackupRun::query()->sole();

        $this->assertSame(LiveBackupRun::COMPLETE, $run->state);
        $this->assertFalse($run->isRunning(), 'a finished run is not running, so the page does not poll it');
        $this->assertSame('done', $run->progress_stage, 'the closing note carries the stage that lets the modal complete its timeline');

        $this->assertNotNull($run->token);
        $this->assertSame(1, $run->started_by_user_id);
        $this->assertSame(1, $run->series_states, 'one series state went in the run');
        $this->assertSame(1, $run->chapter_states, 'one chapter state went in the run');
        $this->assertSame('v2.4.2366', $run->suwayomi_version);
        $this->assertFalse($run->include_downloads, 'the press did not ask for the downloads folder');
        $this->assertNull($run->downloads_bytes, 'no downloads zip was asked for');
        $this->assertSame(2, $run->manga_summary['library_series']);
        $this->assertSame(7, $run->manga_summary['chapters']);
        $this->assertSame(3, $run->manga_summary['chapters_read']);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);

        $this->assertSame(
            'Backup complete: 1 series and 1 chapters saved while Papyrus stayed open. Manga server v2.4.2366.',
            $run->progress_note,
            'the last note is the run\'s closing sentence, not the row\'s opening one',
        );

        // The pair the token names is on disk in the folder the page will list from.
        $directory = BackupLibrary::forThisInstall()->directory();

        $this->assertSame(1, count((array) glob($directory.DIRECTORY_SEPARATOR.'backup-'.$run->token.'.json')), 'the row names the token of the pair that landed');
    }

    /**
     * The zip variant: when the press asked for the downloads folder, the row says so and
     * names the size, and the zip is on disk beside the pair.
     */
    public function test_a_press_asking_for_downloads_records_the_zip(): void
    {
        $folder = $this->scratch.DIRECTORY_SEPARATOR.'downloads';

        File::ensureDirectoryExists($folder.DIRECTORY_SEPARATOR.'sub');
        File::put($folder.DIRECTORY_SEPARATOR.'a.txt', 'chapter bytes');
        File::put($folder.DIRECTORY_SEPARATOR.'sub'.DIRECTORY_SEPARATOR.'b.bin', "\x00\x01\x02");

        $this->downloadsPath = $folder;

        (new RunLiveBackup(1, true))->handle(app(LiveBackup::class));

        $run = LiveBackupRun::query()->sole();

        $this->assertSame(LiveBackupRun::COMPLETE, $run->state);
        $this->assertTrue($run->include_downloads, 'the run was asked for the downloads folder');
        $this->assertSame(16, $run->downloads_bytes, '"chapter bytes" is 13 bytes, the binary 3, sixteen in all, named before the press');
        $this->assertSame(1, count((array) glob(BackupLibrary::forThisInstall()->directory().DIRECTORY_SEPARATOR.'downloads-'.$run->token.'.zip')), 'the asked for zip landed beside the pair');
    }

    /**
     * A version the server would not answer is a refusal before anything is taken: the row
     * keeps the sentence, holds no token, and the note it started with goes, because it
     * describes work that never happened.
     */
    public function test_an_unanswered_version_refuses_with_nothing_taken(): void
    {
        $this->answersItsVersion = false;

        (new RunLiveBackup(1))->handle(app(LiveBackup::class));

        $run = LiveBackupRun::query()->sole();

        $this->assertSame(LiveBackupRun::REFUSED, $run->state);
        $this->assertStringContainsString('did not report its version', (string) $run->refusal_reason);
        $this->assertNull($run->token, 'a refused run has no token, because it renamed nothing');
        $this->assertNull($run->progress_stage, 'the version refusal reached no stage, so the row claims no progress');
        $this->assertNull($run->progress_note, 'a refusal clears the note, which described work that no longer exists');
        $this->assertNotNull($run->finished_at);

        $directory = BackupLibrary::forThisInstall()->directory();

        $this->assertSame([], (array) glob($directory.DIRECTORY_SEPARATOR.'backup-*.json'), 'a refused run records no pair');
    }

    /**
     * A transfer that answers 503 after the reader half was staged is still a refusal by
     * the plan's own mapping: Suwayomi's own throw, nothing taken, staging swept, and the
     * run row says so with the reason. The forgotten reader half is no one's pair.
     */
    public function test_a_failed_transfer_refuses_with_its_reason(): void
    {
        $this->fileGetAnswersBytes = false;

        (new RunLiveBackup(1))->handle(app(LiveBackup::class));

        $run = LiveBackupRun::query()->sole();

        $this->assertSame(LiveBackupRun::REFUSED, $run->state);
        $this->assertStringContainsString('HTTP 503', (string) $run->refusal_reason);
        $this->assertNull($run->token);

        $directory = BackupLibrary::forThisInstall()->directory();

        $this->assertSame([], (array) glob($directory.DIRECTORY_SEPARATOR.'backup-*.json'), 'a refused run records no pair');
    }

    /**
     * Anything that is not one of the run's own refusals fails the row and then rethrows,
     * so `failed_jobs` keeps the trace.
     *
     * The throw is planted in the gateway, the collaborator the version stage asks first,
     * because the difference between the two failure families is exactly the exception's
     * kind and the row's state: `runningSuwayomi()` catches `SuwayomiException` and nothing
     * else, so a plain error from underneath reaches the job as it is.
     */
    public function test_an_unexpected_error_fails_the_row_and_rethrows(): void
    {
        $this->app->bind(Gateway::class, function (): Gateway {
            return new class(app(Transport::class), app(AuthManager::class)) extends Gateway
            {
                public function execute(string $document, array $variables = [], ?int $timeout = null): array
                {
                    throw new RuntimeException('the transfer gave up on its own terms');
                }
            };
        });

        $threw = false;

        try {
            (new RunLiveBackup(1))->handle(app(LiveBackup::class));
        } catch (RuntimeException $caught) {
            $threw = true;

            $this->assertSame('the transfer gave up on its own terms', $caught->getMessage(), 'the stack that reaches failed_jobs is the run\'s own');
        }

        $this->assertTrue($threw, 'the job rethrows, so failed_jobs keeps the trace');

        $run = LiveBackupRun::query()->sole();

        $this->assertSame(LiveBackupRun::FAILED, $run->state);
        $this->assertStringContainsString('the transfer gave up on its own terms', (string) $run->refusal_reason);
        $this->assertSame('This run stopped on an error: the transfer gave up on its own terms', $run->progress_note);
        $this->assertNull($run->token);

        $directory = BackupLibrary::forThisInstall()->directory();

        $this->assertSame([], (array) glob($directory.DIRECTORY_SEPARATOR.'backup-*.json'), 'a failed run records no pair');
    }

    /**
     * The reader who pressed can be gone when the worker picks the job up. The backup has
     * no owner, so the run goes anyway and the row starts without its attribution.
     */
    public function test_a_vanished_reader_still_gets_the_run_taken(): void
    {
        (new RunLiveBackup(999999))->handle(app(LiveBackup::class));

        $run = LiveBackupRun::query()->sole();

        $this->assertSame(LiveBackupRun::COMPLETE, $run->state);
        $this->assertNotNull($run->token, 'a backup goes even when its presser is gone');
        $this->assertNull($run->started_by_user_id);
    }
}
