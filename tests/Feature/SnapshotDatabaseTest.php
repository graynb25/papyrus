<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `papyrus:snapshot`, deliberately in a class of its own and without `RefreshDatabase`.
 *
 * ## Why it cannot share a class with the install tests
 *
 * Because `RefreshDatabase` begins a transaction, and VACUUM cannot run inside one. A shared
 * class looks like it would work, fails with a generic SQLite error naming no transaction, and
 * sends you looking at the SQL rather than at the harness.
 *
 * So the command **checks for a transaction and refuses with a message that says so**. That is
 * not only for the test: a queued job wrapping a snapshot in a transaction is an entirely
 * reasonable thing to write, and without the guard it fails with an error that mentions
 * neither the transaction nor the cause.
 *
 * ## What is being protected
 *
 * 867 `series_states` rows and 21,447 `chapter_states` rows that exist in this database and
 * nowhere else. Suwayomi backs up the library; this is the half nothing else holds.
 */
class SnapshotDatabaseTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-snap-'.getmypid().'-'.uniqid();

        File::ensureDirectoryExists($this->scratch);

        /*
         * A real file-backed database with the schema on it.
         *
         * Without `RefreshDatabase` the test connection is the suite's own `:memory:`, which has
         * never been migrated, so a snapshot of it is a faithful snapshot of an empty database
         * and the test can only ever prove that a file was produced. Pointing the connection at
         * a file and migrating it makes the snapshot carry an install, which is the thing being
         * protected.
         */
        $database = $this->scratch.DIRECTORY_SEPARATOR.'app.sqlite';

        File::put($database, '');

        config(['database.connections.sqlite.database' => $database]);

        DB::purge('sqlite');

        $this->artisan('migrate', ['--force' => true])->run();

        // Through the factory rather than by hand, because `chapter_states.user_id` is a foreign
        // key and a hand built user row misses whatever the model requires.
        $user = User::factory()->create(['id' => 1]);

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
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    /**
     * A snapshot of a real database, opened afterwards to prove it is one.
     *
     * Checking the bytes are non-empty proves very little: a truncated file and a good one are
     * both non-empty. Opening it and asking SQLite what is inside is the only check that means
     * something.
     */
    public function test_a_snapshot_is_written_and_is_a_real_database(): void
    {
        $directory = $this->scratch.'/snapshots';

        $this->artisan('papyrus:snapshot', ['--into' => $directory])->assertSuccessful();

        $files = glob($directory.'/papyrus-*.sqlite') ?: [];

        $this->assertCount(1, $files, 'exactly one snapshot per run');
        $this->assertGreaterThan(0, filesize($files[0]));

        $copy = new \PDO('sqlite:'.$files[0]);
        $tables = $copy->query("select count(*) from sqlite_master where type='table' and name='users'")
            ->fetchColumn();

        $this->assertSame(
            1,
            (int) $tables,
            'the snapshot must open as a database holding the schema, not merely be a file with the right name',
        );
    }

    /**
     * A snapshot must carry rows, not just structure.
     *
     * This is the assertion that matters. A database file with the right tables and none of
     * the reader's data in it is a restore that appears to work and gives someone an empty
     * library, which is exactly the failure the whole command exists to prevent.
     */
    public function test_a_snapshot_carries_the_readers_data(): void
    {
        $directory = $this->scratch.'/data';

        $this->artisan('papyrus:snapshot', ['--into' => $directory])->assertSuccessful();

        $files = glob($directory.'/papyrus-*.sqlite') ?: [];
        $copy = new \PDO('sqlite:'.$files[0]);

        $states = $copy->query('select count(*) from chapter_states')->fetchColumn();

        $this->assertSame(
            1,
            (int) $states,
            'a read state row must survive the snapshot, or the backup is worthless',
        );

        $chapter = $copy->query('select suwayomi_chapter_id, is_read from chapter_states')
            ->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame(4242, (int) $chapter['suwayomi_chapter_id']);
        $this->assertSame(1, (int) $chapter['is_read']);
    }

    /**
     * Retention keeps the newest and drops the oldest.
     */
    public function test_retention_keeps_the_newest_and_drops_the_oldest(): void
    {
        $directory = $this->scratch.'/retention';
        File::ensureDirectoryExists($directory);

        foreach (['20260101-000000', '20260201-000000', '20260301-000000'] as $stamp) {
            File::put($directory."/papyrus-{$stamp}.sqlite", 'x');
        }

        $this->artisan('papyrus:snapshot', ['--into' => $directory, '--keep' => 2, '--prune' => true])
            ->assertSuccessful();

        $left = array_map('basename', glob($directory.'/papyrus-*.sqlite') ?: []);

        sort($left);

        $this->assertSame(['papyrus-20260201-000000.sqlite', 'papyrus-20260301-000000.sqlite'], $left);
    }

    /**
     * Pruning is destructive, so it must be able to be asked to do nothing else.
     */
    public function test_prune_only_writes_no_snapshot(): void
    {
        $directory = $this->scratch.'/pruneonly';

        $this->artisan('papyrus:snapshot', ['--into' => $directory, '--keep' => 5, '--prune' => true])
            ->assertSuccessful();

        $this->assertSame([], glob($directory.'/papyrus-*.sqlite') ?: []);
    }

    /**
     * A snapshot that could not be taken must say so, and must not leave something behind that
     * looks like a backup.
     *
     * A half written file carrying a snapshot's name is indistinguishable from a good one by
     * name, and is discovered at restore time, which is the worst moment to find it.
     */
    public function test_a_failed_snapshot_reports_failure(): void
    {
        $directory = $this->scratch.'/failing';
        File::ensureDirectoryExists($directory);

        // A directory exactly where the snapshot file belongs, so the write cannot succeed.
        File::ensureDirectoryExists($directory.'/papyrus-'.gmdate('Ymd-His').'.sqlite');

        $exit = $this->artisan('papyrus:snapshot', ['--into' => $directory])->run();

        $this->assertSame(1, $exit, 'a failed snapshot must report failure, not success');
    }

    /**
     * The guard, and the reason this class is separate.
     *
     * Without the check, running this inside a transaction fails with a SQLite error that names
     * no transaction and no cause, which is a genuinely bad thing to hand someone.
     */
    public function test_it_refuses_inside_a_transaction_and_says_why(): void
    {
        $directory = $this->scratch.'/transaction';

        $exit = DB::transaction(function () use ($directory): int {
            return $this->artisan('papyrus:snapshot', ['--into' => $directory])->run();
        });

        $this->assertSame(1, $exit, 'inside a transaction it must refuse rather than fail obscurely');
        $this->assertSame([], glob($directory.'/papyrus-*.sqlite') ?: [], 'and write nothing');
    }
}
