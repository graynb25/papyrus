<?php

namespace Tests\Feature;

use App\Backup\BackupLibrary;
use App\Backup\LiveBackup;
use App\Backup\ReaderHalfCapture;
use App\Backup\ReaderHalfRefused;
use App\Models\User;
use App\Suwayomi\SuwayomiException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Papyrus\Launcher\BackupPair;
use RuntimeException;
use Tests\Support\BuildsTachibkBackups;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * The live pair, staged, verified and renamed, without `RefreshDatabase`.
 *
 * ## Why it stands alone like SnapshotDatabaseTest
 *
 * Because the same reason holds: `RefreshDatabase` opens a transaction, and the reader half
 * is a `VACUUM INTO`, which refuses one. The connection is pointed at a real file, migrated,
 * and given a factory user, so the staged half has rows to be verified against.
 *
 * ## Why Suwayomi's side is `Http::fake`
 *
 * Because the shapes it answers were measured against a live v2.4.2366 server: a root
 * relative path from `createBackup`, and 200 gzip bytes off the file GET. The fake answers
 * those with a real fixture, so the manga half is walked by {@see TachibkReader} exactly as
 * Suwayomi's own export would be. One fake per test, set up once, never replaced, which is
 * trap 116 again.
 */
class LiveBackupTest extends TestCase
{
    use BuildsTachibkBackups;
    use MintsTokens;

    private const BACKUP_PATH = '/api/graphql/files/backup/org.suwayomi.tachidesk._2026-10-20_14-02.tachibk';

    private string $scratch;

    /** What the settings query answers for `downloadsPath`. Null means nothing usable. */
    private ?string $downloadsPath = null;

    /** What the file GET answers. Replaced by a 503 in the tests that need the run to fail. */
    private bool $fileGetAnswersBytes = true;

    /** What `aboutServer` answers. Set false to leave the version unknowable. */
    private bool $answersItsVersion = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-live-'.getmypid().'-'.uniqid();

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
     * The whole run, end to end: a pair in the backups folder whose manifest the launcher's
     * own reader accepts, staging gone afterwards, and a result that says what each half held.
     */
    public function test_it_takes_a_pair_the_launcher_reader_accepts(): void
    {
        $result = app(LiveBackup::class)->run(false);

        $directory = $result->directory;

        $manifests = (array) glob($directory.DIRECTORY_SEPARATOR.'backup-*.json');
        $readers = (array) glob($directory.DIRECTORY_SEPARATOR.'papyrus-*.sqlite');
        $mangas = (array) glob($directory.DIRECTORY_SEPARATOR.'suwayomi-*.tachibk');

        $this->assertSame(1, count($manifests));
        $this->assertSame(1, count($readers));
        $this->assertSame(1, count($mangas));
        $this->assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.'staging', 'a finished run leaves nothing in staging');

        // The readback is through the launcher's own reader, because that is the shape the
        // next restore will read and the shape this pair cannot be allowed to drift from.
        $pair = BackupPair::read((string) $manifests[0], $directory);

        $this->assertSame($result->token, $pair->token);
        $this->assertSame('v2.4.2366', $pair->suwayomiVersion);
        $this->assertSame(1, $pair->seriesStates, 'one series state went in the run');
        $this->assertSame(1, $pair->chapterStates, 'one chapter state went in the run');
        $this->assertSame(basename((string) $readers[0]), $pair->papyrusFile);
        $this->assertSame(basename((string) $mangas[0]), $pair->suwayomiFile);
        $this->assertSame(hash_file('sha256', (string) $readers[0]), $pair->papyrusSha256, 'the manifest names the file that was renamed, digest and all');
        $this->assertSame(hash_file('sha256', (string) $mangas[0]), $pair->suwayomiSha256);

        // The manga half was walked by TachibkReader, and the fixture it faked carries two
        // series, seven chapters, three read: Naruto's long shelf and the short unread one.
        $this->assertSame(2, $result->mangaSummary['library_series']);
        $this->assertSame(7, $result->mangaSummary['chapters']);
        $this->assertSame(3, $result->mangaSummary['chapters_read']);
        $this->assertNull($result->downloadsBytes, 'no downloads zip was asked for');
    }

    public function test_the_version_refusal_comes_before_the_manga_half_is_asked(): void
    {
        $this->answersItsVersion = false;

        try {
            app(LiveBackup::class)->run(false);
            $this->fail('The run took a pair with no version to name in the manifest.');
        } catch (SuwayomiException $e) {
            $this->assertStringContainsString('did not answer what version', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $request): bool => str_contains((string) $request->body(), 'mutation CreateBackup'));

        $directory = BackupLibrary::forThisInstall()->directory();

        $this->assertSame([], (array) glob($directory.DIRECTORY_SEPARATOR.'backup-*.json'), 'a refused run records no pair');
    }

    public function test_the_downloads_folder_is_zipped_when_the_run_carries_it(): void
    {
        $folder = $this->scratch.DIRECTORY_SEPARATOR.'downloads';

        File::ensureDirectoryExists($folder.DIRECTORY_SEPARATOR.'sub');
        File::put($folder.DIRECTORY_SEPARATOR.'a.txt', 'chapter bytes');
        File::put($folder.DIRECTORY_SEPARATOR.'sub'.DIRECTORY_SEPARATOR.'b.bin', "\x00\x01\x02");

        $this->downloadsPath = $folder;

        $result = app(LiveBackup::class)->run(true);

        $zips = (array) glob($result->directory.DIRECTORY_SEPARATOR.'downloads-*.zip');

        $this->assertSame(1, count($zips), 'the asked for zip landed beside the pair');
        $this->assertSame(16, $result->downloadsBytes, '"chapter bytes" is 13 bytes, the binary 3, sixteen in all, named before the press');

        $zip = new \ZipArchive;
        $zip->open((string) $zips[0]);

        // Entry names are relative to the downloads folder itself: no absolute install path
        // rides along, and an unzip later finds the folder shape they had on the server.
        $this->assertSame('a.txt', $zip->getNameIndex(0));

        $names = [];
        $count = $zip->numFiles;

        for ($e = 0; $e < $count; $e++) {
            $names[] = $zip->getNameIndex($e);
        }

        $zip->close();

        sort($names);

        $this->assertSame(['a.txt', 'sub/b.bin'], $names);
    }

    public function test_the_downloads_folder_is_left_alone_when_not_asked(): void
    {
        $this->downloadsPath = $this->scratch.DIRECTORY_SEPARATOR.'a-folder-nobody-asks-about';

        $result = app(LiveBackup::class)->run(false);

        $this->assertNull($result->downloadsBytes);
        $this->assertSame([], (array) glob($result->directory.DIRECTORY_SEPARATOR.'downloads-*.zip'));
        $this->assertSame([], (array) glob($result->directory.DIRECTORY_SEPARATOR.'staging'.DIRECTORY_SEPARATOR.'*'));

        Http::assertNotSent(fn (Request $request): bool => str_contains((string) $request->body(), 'query DownloadsLocation'));
    }

    public function test_a_failed_stage_cleans_staging_and_the_backups_folder(): void
    {
        // The file GET answers 503: the reader half captured fine, the manga half cannot land.
        $this->fileGetAnswersBytes = false;

        $directory = BackupLibrary::forThisInstall()->directory();

        try {
            app(LiveBackup::class)->run(false);
            $this->fail('The run streamed a refused body into a pair.');
        } catch (SuwayomiException $e) {
            $this->assertStringContainsString('HTTP 503', $e->getMessage());
        }

        $this->assertSame([], (array) glob($directory.DIRECTORY_SEPARATOR.'backup-*.json'), 'a failed run names no pair');
        $this->assertSame([], (array) glob($directory.DIRECTORY_SEPARATOR.'papyrus-*.sqlite'), 'a failed run renames no half');
        $this->assertSame([], (array) glob($directory.DIRECTORY_SEPARATOR.'suwayomi-*.tachibk'), 'a failed run renames no half');
        $this->assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.'staging', 'a failed run cleans staging whole');
    }

    public function test_a_stale_staging_folder_from_a_dead_run_is_removed_instead_of_mixed_into_a_new_one(): void
    {
        $library = BackupLibrary::forThisInstall();
        $staging = $library->directory().DIRECTORY_SEPARATOR.'staging';

        File::ensureDirectoryExists($staging);
        File::put($staging.DIRECTORY_SEPARATOR.'papyrus-20261015-154003.sqlite', 'a half file the last run never finished');
        File::put($staging.DIRECTORY_SEPARATOR.'backup-20261015-154003.json', '{"token": "20261015-154003"}');

        /*
         * Staged at the first stage note, which the sweep at the start of the run happens
         * before: the same sweep being reduced to the end of run's own cleanup would leave
         * the stale files in place right up until the run finished or failed either way, and
         * a same second retry, whose stale file this sweep is what makes it run clean
         * instead of refusing on a file that already exists, happens between the two.
         */
        $seenAtFirstNote = null;
        $note = static function (string $stage, string $words) use (&$seenAtFirstNote, $staging): void {
            if ($seenAtFirstNote === null) {
                $seenAtFirstNote = (array) glob($staging.DIRECTORY_SEPARATOR.'*');
            }
        };

        app(LiveBackup::class)->run(false, $note);

        $this->assertSame([], $seenAtFirstNote, 'the stale staging was swept before the run took anything');
        $this->assertFileDoesNotExist($staging.DIRECTORY_SEPARATOR.'papyrus-20261015-154003.sqlite');
        $this->assertFileDoesNotExist($staging.DIRECTORY_SEPARATOR.'backup-20261015-154003.json');
    }

    /** The guard a queued job sits behind when someone wraps the run in a transaction. */
    public function test_a_run_inside_a_transaction_refuses_and_writes_nothing(): void
    {
        try {
            DB::transaction(function (): void {
                app(LiveBackup::class)->run(false);
            });
            $this->fail('A run inside a transaction went ahead and took a half.');
        } catch (ReaderHalfRefused $e) {
            // The capture's own guard, before anything is taken: the same refusal the queued
            // job's hardened shape relies on, raised here from inside the wrapper.
            $this->assertStringContainsString('inside a transaction', $e->getMessage());
        }

        $directory = BackupLibrary::forThisInstall()->directory();

        $this->assertSame([], (array) glob($directory.DIRECTORY_SEPARATOR.'backup-*.json'));
        $this->assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.'staging');
    }

    /**
     * The comparison refuses a staged file whose counts disagree with the live database. A
     * real run cannot stage both ends honestly from behind this class's own capture, so the
     * comparison is invoked on a file captured a moment before the live rows moved.
     */
    public function test_a_staged_half_whose_counts_disagree_with_the_live_database_is_refused(): void
    {
        $target = $this->scratch.DIRECTORY_SEPARATOR.'disagree.sqlite';

        app(ReaderHalfCapture::class)->capture($target);

        DB::table('series_states')->where('user_id', 1)->delete();

        try {
            $this->verify($target);
            $this->fail('The verification passed on a file whose counts no longer match.');
        } catch (RuntimeException $e) {
            // The deletion leaves the live side at 0 series and 1 chapter while the staged copy
            // still holds 1 and 1. The message names both sets with a side for each, and the
            // two assertions here must not let one slide in for the other.
            $this->assertStringContainsString('it carries 1 series states and 1 chapter states', $e->getMessage());
            $this->assertStringContainsString('the live database holds 0 and 1', $e->getMessage());
        } finally {
            @unlink($target);
        }
    }

    /** Two runs inside one clock second name the same files, so the second is refused. */
    public function test_a_second_run_of_the_same_token_is_refused_rather_than_overwriting(): void
    {
        $into = BackupLibrary::forThisInstall()->directory();

        File::ensureDirectoryExists($into);

        $from = $this->scratch.DIRECTORY_SEPARATOR.'staged-file.sqlite';
        $to = $into.DIRECTORY_SEPARATOR.basename($from);

        File::put($from, 'the first run landed here');

        $this->renameStaged(['reader' => $from], ['reader'], $into);
        $this->assertFileExists($to);

        try {
            $this->renameStaged(['reader' => $from], ['reader'], $into);
            $this->fail('A second run of the same token overwrote the pair already recorded.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('second run inside the same second', $e->getMessage());
        } finally {
            @unlink($to);
        }
    }

    /**
     * A server whose own library holds nothing is named as what it is.
     *
     * Measured against a live v2.4.2366 with an empty library: the server answers with a
     * real backup of 352 bytes whose payload carries one category record and no series
     * record at all. The reader's gate refuses that file, and it is right to, but the
     * refusal's words have to name the cause: the server answered, and what it answered
     * was a library with nothing in it. Refusing it as "not a Mihon or Suwayomi backup"
     * would tell the reader their manga server is broken when it is answering fine.
     */
    public function test_a_server_with_an_empty_library_is_refused_in_words_that_name_it(): void
    {
        $this->withoutSeries = true;
        $this->withCategories = true;

        $directory = BackupLibrary::forThisInstall()->directory();

        try {
            app(LiveBackup::class)->run(false);
            $this->fail('The run took a pair whose manga half held no series at all.');
        } catch (SuwayomiException $e) {
            $this->assertStringContainsString('a real backup of a library that holds nothing', $e->getMessage());
            $this->assertStringContainsString('no series at all', $e->getMessage());
        }

        $this->assertSame([], (array) glob($directory.DIRECTORY_SEPARATOR.'backup-*.json'), 'a refused run names no pair');
        $this->assertSame([], (array) glob($directory.DIRECTORY_SEPARATOR.'suwayomi-*.tachibk'), 'a refused run renames no half');
        $this->assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.'staging', 'a refused run cleans staging whole');
    }

    /**
     * A file that is not the measured empty library shape is still refused as a broken
     * file, so the new wording cannot become a catch-all for anything unreadable.
     */
    public function test_a_manga_half_that_is_not_a_backup_at_all_is_still_refused_as_one(): void
    {
        $this->gzipButNotProtobuf = true;

        try {
            app(LiveBackup::class)->run(false);
            $this->fail('The run took a pair whose manga half was not a backup at all.');
        } catch (SuwayomiException $e) {
            $this->assertStringContainsString('does not read as a Mihon or Suwayomi backup', $e->getMessage());
            $this->assertStringNotContainsString('a real backup of a library that holds nothing', $e->getMessage());
        }
    }

    /** The private compare, invoked through the same reflection template HealthTest uses. */
    private function verify(string $target): void
    {
        $method = new \ReflectionMethod(LiveBackup::class, 'verifyReaderHalf');
        $method->setAccessible(true);

        $method->invoke(app(LiveBackup::class), $target, static function (string $stage, string $words): void {});
    }

    /** The private rename, handed two files that stand in for a staged pair. */
    private function renameStaged(array $staged, array $order, string $into): void
    {
        $method = new \ReflectionMethod(LiveBackup::class, 'rename');
        $method->setAccessible(true);

        $method->invoke(app(LiveBackup::class), $staged, $order, $into, static function (string $stage, string $words): void {});
    }
}
