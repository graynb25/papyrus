<?php

namespace Tests\Feature;

use App\Backup\BackupLibrary;
use App\Backup\LiveRestore;
use App\Backup\PairManifest;
use App\Backup\ReaderHalfCapture;
use App\Backup\ReaderHalfRefused;
use App\Jobs\RunLiveRestore;
use App\Models\LiveRestoreRun;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\Support\BuildsTachibkBackups;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * The job around the live restore: the row it writes, and how a press ends on it.
 *
 * ## Why it stands alone like RunLiveBackupJobTest
 *
 * For the same reason, kept short here rather than restated: `RefreshDatabase` opens a
 * transaction and the reader half is a `VACUUM INTO`, which refuses one. The connection is
 * pointed at a real file, migrated, and given a factory user and one series state with one
 * chapter state, so the pair a test builds has rows to be verified against both ends and
 * the swap has something to put back. Suwayomi's side is `Http::fake` from the same
 * measured shapes, one fake for the whole class.
 *
 * ## What the tests are about
 *
 * The round trip, because that is what a restore is for: take a pair, damage the live
 * database, put the pair back, and find the old rows there again. And the refusals, because
 * each one has a claim attached: a refusal before the manga half is asked must never open
 * that route, a refusal after it must say both halves' truth, and whatever the outcome is,
 * the live database is only ever changed by the swap itself.
 */
class RunLiveRestoreJobTest extends TestCase
{
    use BuildsTachibkBackups;
    use MintsTokens;

    private string $scratch;

    /** What the import route answers. A 503 stands in for the server refusing the file. */
    private int $importStatus = 200;

    /** Every request the fake saw aimed at the import route, for the never-asked assertions. */
    private array $importAsked = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-restore-job-'.getmypid().'-'.uniqid();

        File::ensureDirectoryExists($this->scratch);

        /*
         * A real file-backed database, migrated, because a swap needs a file to open outside
         * the connection pool and rows of its own to live on both sides of.
         */
        $database = $this->scratch.DIRECTORY_SEPARATOR.'app.sqlite';

        File::put($database, '');

        config(['database.connections.sqlite.database' => $database]);

        DB::purge('sqlite');

        $this->artisan('migrate', ['--force' => true])->run();

        User::factory()->create(['id' => 1]);

        $this->seedOneSeriesState();

        config(['papyrus.suwayomi.url' => 'http://127.0.0.1:4567']);

        Http::fake(function (Request $request) {
            $body = (string) $request->body();

            if (str_contains($body, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            if (str_contains($request->url(), '/api/v1/backup/import')) {
                $this->importAsked[] = $request;

                return $this->importStatus === 200
                    ? Http::response(['missingSources' => [], 'missingTrackers' => [], 'mangasMissingSources' => []])
                    : Http::response([], $this->importStatus);
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
     * The round trip is the feature's whole reason: a pair taken, the live database damaged
     * afterwards, and the press putting the pair's rows back over the damage.
     *
     * The row this ends on is the page's read, so everything a reader is shown is asserted
     * from it: the token of the pair restored, both counts as the manifest records them, the
     * manga half's summary the run read back before sending it, the server's own import
     * report, and a last note that is the closing sentence, not one of the earlier stages'.
     */
    public function test_a_completed_restore_round_trip_records_its_receipt(): void
    {
        $token = $this->writePair();

        $this->damageLive();

        (new RunLiveRestore(1, $token))->handle(app(LiveRestore::class));

        $run = LiveRestoreRun::query()->sole();

        $this->assertSame(LiveRestoreRun::COMPLETE, $run->state);
        $this->assertFalse($run->isRunning(), 'a finished restore is not running, so the page does not poll it');

        $this->assertSame($token, $run->token);
        $this->assertSame(1, $run->started_by_user_id);
        $this->assertSame(1, $run->series_states, 'the counts the manifest records went back in the run');
        $this->assertSame(1, $run->chapter_states);
        $this->assertSame('v2.4.2366', $run->suwayomi_version);
        $this->assertSame(2, $run->manga_summary['library_series']);
        $this->assertSame(7, $run->manga_summary['chapters']);
        $this->assertSame(3, $run->manga_summary['chapters_read']);
        $this->assertSame(['missingSources' => [], 'missingTrackers' => [], 'mangasMissingSources' => []], $run->restore_summary, 'the server answered its own import report and it went on the row');
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);

        $this->assertSame(
            'Restore complete: 1 series and 1 chapter are back, along with your manga data.',
            $run->progress_note,
            'the last note is the run\'s closing sentence, not one of the stages that led there',
        );

        /*
         * The live database really is back where the pair took it: the damaged state's rows
         * are gone and the pair's own row is back, not just the counts.
         */
        $this->assertSame(1, (int) DB::table('series_states')->count());
        $this->assertSame(1, (int) DB::table('chapter_states')->count());
        $this->assertSame(7, (int) DB::table('series_states')->value('suwayomi_manga_id'));
    }

    /**
     * A pair leaves the live session and cache rows behind, and a restore leaves them alone.
     *
     * The reader half used to be a whole database copy, so a backup invited the reader to
     * carry a still-valid session id and the Suwayomi token cache off the machine. The copy
     * keeps both tables, because the swap checks the schemas match, but their rows are emptied
     * and the file is vacuumed so deleted bytes are not left in free pages. The live tables are
     * preserved by the swap, so the restore must not put the pair's empty tables over them.
     */
    public function test_a_round_trip_keeps_live_sessions_and_cached_tokens_out_of_the_pair(): void
    {
        DB::table('sessions')->insert([
            'id' => 'live-session',
            'user_id' => 1,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'the round trip test',
            'payload' => 'session-secret-that-must-not-travel',
            'last_activity' => 1,
        ]);

        DB::table('cache')->insert([
            'key' => 'suwayomi.tokens',
            'value' => 'cache-secret-that-must-not-travel',
            'expiration' => 4102444800,
        ]);

        $token = $this->writePair();

        $reader = BackupLibrary::forThisInstall()->directory().DIRECTORY_SEPARATOR.'papyrus-'.$token.'.sqlite';
        $copy = new PDO('sqlite:'.$reader);

        $this->assertSame(
            1,
            (int) $copy->query("select count(*) from sqlite_master where type='table' and name='sessions'")->fetchColumn(),
            'the pair keeps the schema the live database has, so the swap can still compare it',
        );
        $this->assertSame(0, (int) $copy->query('select count(*) from sessions')->fetchColumn(), 'no live session rows travel in the pair');
        $this->assertSame(0, (int) $copy->query('select count(*) from cache')->fetchColumn(), 'no cached manga server tokens travel in the pair');

        $copy = null;

        $this->assertFileDoesNotExist($reader.'-wal', 'the scrub left no write ahead log beside the pair');
        $this->assertFileDoesNotExist($reader.'-shm', 'the scrub left no shared memory file beside the pair');
        $this->assertFileDoesNotExist($reader.'-journal', 'the scrub left no rollback journal beside the pair');

        $bytes = (string) file_get_contents($reader);

        $this->assertStringNotContainsString('session-secret-that-must-not-travel', $bytes, 'the deleted session bytes are not left in free pages');
        $this->assertStringNotContainsString('cache-secret-that-must-not-travel', $bytes, 'the deleted cache bytes are not left in free pages');

        DB::table('sessions')->where('id', 'live-session')->update(['payload' => 'session-live-after-backup']);
        DB::table('cache')->where('key', 'suwayomi.tokens')->update(['value' => 'cache-live-after-backup']);

        $this->damageLive();

        (new RunLiveRestore(1, $token))->handle(app(LiveRestore::class));

        $this->assertSame(LiveRestoreRun::COMPLETE, LiveRestoreRun::query()->sole()->state);
        $this->assertSame(
            'session-live-after-backup',
            (string) DB::table('sessions')->where('id', 'live-session')->value('payload'),
            'the live session is preserved rather than replaced by the pair\'s empty table',
        );
        $this->assertSame(
            'cache-live-after-backup',
            (string) DB::table('cache')->where('key', 'suwayomi.tokens')->value('value'),
            'the live cache is preserved rather than replaced by the pair\'s empty table',
        );
    }

    /**
     * The reader half must hold what its manifest records, verified before anything moves:
     * a file whose rows disagree with its own manifest is not the pair it claims to be, and
     * the run refuses without ever asking the server for anything.
     */
    public function test_a_manifest_its_file_disagrees_with_is_refused_with_nothing_asked(): void
    {
        $token = $this->writePair();
        $this->tamperManifestSeriesStates($token, 5);

        (new RunLiveRestore(1, $token))->handle(app(LiveRestore::class));

        $this->assertOneRefusedRun('does not match the backup details');

        $this->assertImportNeverAsked();
        $this->assertLiveUntouched();
    }

    /**
     * The manga half is restored first by design, so a server that refuses the file is a
     * refusal where both halves come out untouched: the route answered 503, the reader half
     * was never reached, and the damaged rows stay damaged, provable from the live tables.
     */
    public function test_a_server_that_refuses_the_import_refuses_with_both_halves_untouched(): void
    {
        $token = $this->writePair();
        $this->damageLive();

        $this->importStatus = 503;

        (new RunLiveRestore(1, $token))->handle(app(LiveRestore::class));

        $run = LiveRestoreRun::query()->sole();

        $this->assertSame(LiveRestoreRun::REFUSED, $run->state);
        $this->assertStringContainsString('HTTP 503', (string) $run->refusal_reason);
        $this->assertNull($run->progress_note, 'a refused run clears the note, which described work that did not happen');
        $this->assertNotNull($run->finished_at);

        $this->assertSame(1, count($this->importAsked), 'the import was asked once and its refusal ended the run');

        /*
         * The damage is still the damage: two series, no chapters, the mutated state the run
         * found the live database in, until its own retry lands the reader half.
         */
        $this->assertSame(2, (int) DB::table('series_states')->count());
        $this->assertSame(0, (int) DB::table('chapter_states')->count());
    }

    /**
     * The reader half is verified first, so a pair missing its reader half is refused before
     * the manga half is ever asked of the server.
     */
    public function test_a_reader_half_missing_on_disk_is_refused_before_the_manga_half_is_asked(): void
    {
        $token = $this->writePair();

        unlink(BackupLibrary::forThisInstall()->directory().DIRECTORY_SEPARATOR.'papyrus-'.$token.'.sqlite');

        (new RunLiveRestore(1, $token))->handle(app(LiveRestore::class));

        $this->assertOneRefusedRun('Your library is missing from this backup');

        $this->assertImportNeverAsked();
    }

    /**
     * The manga half is read through the format's own reader before it is sent, so a file
     * missing there is the server's own refusal shape and the route is never opened.
     */
    public function test_a_manga_half_missing_on_disk_is_refused_without_the_route_being_asked(): void
    {
        $token = $this->writePair();

        unlink(BackupLibrary::forThisInstall()->directory().DIRECTORY_SEPARATOR.'suwayomi-'.$token.'.tachibk');

        (new RunLiveRestore(1, $token))->handle(app(LiveRestore::class));

        $this->assertOneRefusedRun('Your manga data is missing from this backup');

        $this->assertImportNeverAsked();
    }

    /**
     * The one in flight state: the manga half already restored, the reader half refusing
     * its swap. The refusal comes through the swap's own real seam, the schema check inside
     * the one `BEGIN IMMEDIATE` transaction after the pair's file is attached, by giving
     * the pair a table the live database does not carry; the swap rolls back before
     * anything is deleted and refuses, which is the same routing the measured lock refusal
     * travels, whose own words live in ReaderHalfSwapTest.
     *
     * The foreign writer shape was deliberately not held here: a `BEGIN IMMEDIATE` held
     * across the whole attempt keeps the run row's receipt writes under the same lock,
     * which the job answers by deferring them (a deferral covered by the receipt() seam),
     * and then the row truth this test exists to assert could never land on any attempt.
     * The receipt must be able to live on the row, so the refusal here is one the write
     * path survives.
     *
     * The row must say both halves' truth or a reader staring at a failed row could conclude
     * their server lost its library when it did not, and the job rethrows so the queue's own
     * retry picks the swap up once the transaction is over.
     */
    public function test_a_reader_half_refusal_after_the_manga_half_names_both_halves_truth(): void
    {
        $token = $this->writePair();
        $this->damageLive();

        /*
         * The refusal is placed after the manga half by construction: the pair's reader
         * half is captured, verified and only then found carrying a table the live database
         * does not have. Nothing of the capture's own rows moved.
         */
        (new PDO('sqlite:'.BackupLibrary::forThisInstall()->directory().DIRECTORY_SEPARATOR.'papyrus-'.$token.'.sqlite'))
            ->exec('create table papyrus_swap_refusal_test (id integer primary key)');

        $threw = false;

        try {
            (new RunLiveRestore(1, $token))->handle(app(LiveRestore::class));
        } catch (ReaderHalfRefused $caught) {
            $threw = true;

            $this->assertStringContainsString('does not carry the schema the live database has', $caught->getMessage());
        }

        $this->assertTrue($threw, 'the job rethrows, so the queue\'s own retry takes the swap again');

        $run = LiveRestoreRun::query()->sole();

        $this->assertSame(LiveRestoreRun::FAILED, $run->state);

        $note = (string) $run->progress_note;

        $this->assertStringContainsString("The server's manga half is restored", $note, 'the note names the half that is already back');
        $this->assertStringContainsString('The reader half refused its swap', $note, 'the note names the half that is still waiting');
        $this->assertStringContainsString('does not carry the schema the live database has', $note, 'the note carries the refusal\'s own words');
        $this->assertSame($token, $run->token);
        $this->assertNotNull($run->finished_at);

        $this->assertSame(1, count($this->importAsked), 'the manga half went to the server');

        /*
         * The reader half refused its swap, so the live database is still the damaged state:
         * two series, no chapters. The manga half is half of the work, not all of it.
         */
        $this->assertSame(2, (int) DB::table('series_states')->count());
        $this->assertSame(0, (int) DB::table('chapter_states')->count());
    }

    /**
     * The reader who pressed can be gone when the worker picks the job up. The restore still
     * goes, because the data belongs to the install and not to whoever pressed.
     */
    public function test_a_vanished_reader_still_gets_the_restore_taken(): void
    {
        $token = $this->writePair();

        (new RunLiveRestore(999999, $token))->handle(app(LiveRestore::class));

        $run = LiveRestoreRun::query()->sole();

        $this->assertSame(LiveRestoreRun::COMPLETE, $run->state);
        $this->assertNull($run->started_by_user_id);
        $this->assertSame($token, $run->token);
    }

    /**
     * A manifest that cannot be read at all is a refusal before any row of work starts, and
     * the row that records it carries the reader's own sentence, since the pair reader owns
     * what a broken manifest is.
     */
    public function test_an_unreadable_manifest_is_refused_before_anything_starts(): void
    {
        $token = $this->writePair();

        unlink(BackupLibrary::forThisInstall()->directory().DIRECTORY_SEPARATOR.'backup-'.$token.'.json');

        (new RunLiveRestore(1, $token))->handle(app(LiveRestore::class));

        $this->assertOneRefusedRun('There is no backup manifest at');

        $this->assertImportNeverAsked();
        $this->assertLiveUntouched();
    }

    /**
     * The `id` counters are the swap's quiet damage if they are forgotten: a refill sets each
     * counter to the highest id it just inserted, so a pair whose sequence ran past its rows
     * loses those numbers, and the next row one of the app's tables writes takes an id
     * something else already once had.
     *
     * The probe table deliberately sits in the live schema, captured into the pair, and
     * carries a sequence that ran two past its highest id: a library that grew and shrank
     * before the pair was taken. After the swap, the next insert must pick up at the pair's
     * counter, not at the number the refill reached.
     */
    public function test_an_id_counter_that_ran_past_its_rows_survives_the_refill(): void
    {
        $this->growProbeTableWithALeadingCounter();

        $token = $this->writePair();

        $this->damageLive();

        $this->assertSame(42, $this->probeSequence(), 'the pair was taken with the counter already two past its rows');

        (new RunLiveRestore(1, $token))->handle(app(LiveRestore::class));

        $this->assertSame(
            LiveRestoreRun::COMPLETE,
            LiveRestoreRun::query()->sole()->state,
            'the round trip itself is not what this test is about, so the restore must have landed',
        );

        DB::insert('insert into probe_swap (name) values (?)', ['after the swap']);

        $this->assertSame(43, $this->probeSequence(), 'the pair\'s counter was carried over: the next id continues it');
    }

    // -------------------------------------------------------------------------------------------

    /**
     * One series state and one chapter state, the shape a fresh install's reader half has to
     * carry for a pair to be built against and a swap to be verified across.
     */
    private function seedOneSeriesState(): void
    {
        DB::table('series_states')->insert([
            'user_id' => 1,
            'suwayomi_manga_id' => 7,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        DB::table('chapter_states')->insert([
            'user_id' => 1,
            'suwayomi_chapter_id' => 4242,
            'suwayomi_manga_id' => 7,
            'is_read' => 1,
            'is_bookmarked' => 0,
            'last_page_read' => 0,
            'read_seconds' => 0,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    /**
     * The damage a round trip walks over: the chapter row deleted and a second series added,
     * so the pair's counts no longer describe what the app holds, which is a restore's whole
     * point.
     */
    private function damageLive(): void
    {
        DB::table('chapter_states')->delete();

        DB::table('series_states')->insert([
            'user_id' => 1,
            'suwayomi_manga_id' => 8,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    /**
     * The pair, built the way the job will find one on a real install: the reader half, the
     * manga half, and the manifest binding them as the one reader of that format records it.
     *
     * The manifest's counts are the capture's own: one series state, one chapter state.
     *
     * @return string The pair's token.
     */
    private function writePair(string $token = '20261020-183000'): string
    {
        $directory = BackupLibrary::forThisInstall()->directory();

        File::ensureDirectoryExists($directory);

        $reader = $directory.DIRECTORY_SEPARATOR.'papyrus-'.$token.'.sqlite';
        $manga = $directory.DIRECTORY_SEPARATOR.'suwayomi-'.$token.'.tachibk';

        (new ReaderHalfCapture)->capture($reader);

        File::put($manga, $this->tachibkBytes());

        PairManifest::write(
            $directory.DIRECTORY_SEPARATOR.'backup-'.$token.'.json',
            $token,
            $reader,
            1,
            1,
            $manga,
            'v2.4.2366',
        );

        return $token;
    }

    /** The one number most likely to be tampered with on a disk a reader shares. */
    private function tamperManifestSeriesStates(string $token, int $seriesStates): void
    {
        $path = BackupLibrary::forThisInstall()->directory().DIRECTORY_SEPARATOR.'backup-'.$token.'.json';

        $manifest = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($manifest, 'the manifest the helper wrote is the one being tampered with');

        $manifest['papyrus']['series_states'] = $seriesStates;

        file_put_contents($path, (string) json_encode($manifest, JSON_PRETTY_PRINT));
    }

    /**
     * The probe table, grown to forty rows and then shrunk by two, so its counter reads 42
     * while its highest id is 40: the shape of a table that grew and shrank before the pair
     * was taken.
     */
    private function growProbeTableWithALeadingCounter(): void
    {
        DB::statement('create table probe_swap (id integer primary key autoincrement, name text)');

        DB::statement(
            "insert into probe_swap (name)
             with recursive seq(i) as (select 1 union all select i + 1 from seq where i < 42)
             select 'probe ' || i from seq",
        );

        DB::statement('delete from probe_swap where id > 40');
    }

    /** The counter the probe table now reads, or a refusal, because a missing row is data. */
    private function probeSequence(): int
    {
        $value = DB::selectOne('select seq from sqlite_sequence where name = ?', ['probe_swap']);

        $this->assertNotNull($value, 'the probe table has a counter of its own');

        return (int) $value->seq;
    }

    /** Any run the job left not a refusal is the wrong answer to these tests' refusals. */
    private function assertOneRefusedRun(string $reasonWords): void
    {
        $run = LiveRestoreRun::query()->sole();

        $this->assertSame(LiveRestoreRun::REFUSED, $run->state);
        $this->assertStringContainsString($reasonWords, (string) $run->refusal_reason);
        $this->assertNull($run->progress_note, 'a refusal clears the note, which described work that did not happen');
        $this->assertNotNull($run->finished_at);
    }

    /** A refusal before the server side is reached must never have opened the import route. */
    private function assertImportNeverAsked(): void
    {
        $this->assertSame([], $this->importAsked, 'nothing was asked of the import route, which is the shape of a refusal that took nothing');
    }

    /** The live database still holds exactly what the setup seeded, nothing moved. */
    private function assertLiveUntouched(): void
    {
        $this->assertSame(1, (int) DB::table('series_states')->count());
        $this->assertSame(1, (int) DB::table('chapter_states')->count());
    }
}
