<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
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

        $files = glob($directory.'/reader-half-*.sqlite') ?: [];

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

        $files = glob($directory.'/reader-half-*.sqlite') ?: [];
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
     * The command is the other caller of the shared capture seam, so it must not carry the
     * live session or cache rows either. The tables stay for schema compatibility; the rows
     * and their deleted bytes do not.
     */
    public function test_a_snapshot_leaves_live_sessions_and_cached_tokens_out(): void
    {
        DB::table('sessions')->insert([
            'id' => 'snapshot-session',
            'user_id' => 1,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'the snapshot test',
            'payload' => 'snapshot-session-secret-that-must-not-travel',
            'last_activity' => 1,
        ]);

        DB::table('cache')->insert([
            'key' => 'suwayomi.tokens',
            'value' => 'snapshot-cache-secret-that-must-not-travel',
            'expiration' => 4102444800,
        ]);

        $directory = $this->scratch.'/private';

        $this->artisan('papyrus:snapshot', ['--into' => $directory])->assertSuccessful();

        $files = glob($directory.'/reader-half-*.sqlite') ?: [];

        $this->assertCount(1, $files);

        $copy = new \PDO('sqlite:'.$files[0]);

        $this->assertSame(
            1,
            (int) $copy->query("select count(*) from sqlite_master where type='table' and name='sessions'")->fetchColumn(),
            'the snapshot keeps the schema the restore checks against',
        );
        $this->assertSame(0, (int) $copy->query('select count(*) from sessions')->fetchColumn(), 'no live session rows travel in the snapshot');
        $this->assertSame(0, (int) $copy->query('select count(*) from cache')->fetchColumn(), 'no cached manga server tokens travel in the snapshot');

        $copy = null;

        $this->assertFileDoesNotExist($files[0].'-wal', 'the scrub left no write ahead log beside the snapshot');
        $this->assertFileDoesNotExist($files[0].'-shm', 'the scrub left no shared memory file beside the snapshot');
        $this->assertFileDoesNotExist($files[0].'-journal', 'the scrub left no rollback journal beside the snapshot');

        $bytes = (string) file_get_contents($files[0]);

        $this->assertStringNotContainsString('snapshot-session-secret-that-must-not-travel', $bytes, 'the deleted session bytes are not left in free pages');
        $this->assertStringNotContainsString('snapshot-cache-secret-that-must-not-travel', $bytes, 'the deleted cache bytes are not left in free pages');
    }

    /**
     * Retention keeps the newest and drops the oldest.
     */
    public function test_retention_keeps_the_newest_and_drops_the_oldest(): void
    {
        $directory = $this->scratch.'/retention';
        File::ensureDirectoryExists($directory);

        foreach (['20260101-000000', '20260201-000000', '20260301-000000'] as $stamp) {
            File::put($directory."/reader-half-{$stamp}.sqlite", 'x');
        }

        $this->artisan('papyrus:snapshot', ['--into' => $directory, '--keep' => 2, '--prune' => true])
            ->assertSuccessful();

        $left = array_map('basename', glob($directory.'/reader-half-*.sqlite') ?: []);

        sort($left);

        $this->assertSame(['reader-half-20260201-000000.sqlite', 'reader-half-20260301-000000.sqlite'], $left);
    }

    /**
     * Pruning is destructive, so it must be able to be asked to do nothing else.
     */
    public function test_prune_only_writes_no_snapshot(): void
    {
        $directory = $this->scratch.'/pruneonly';

        $this->artisan('papyrus:snapshot', ['--into' => $directory, '--keep' => 5, '--prune' => true])
            ->assertSuccessful();

        $this->assertSame([], glob($directory.'/reader-half-*.sqlite') ?: []);
    }

    /**
     * A failed snapshot must not delete a valid snapshot that shares its timestamp.
     *
     * Two runs in the same second used to build the same target name, and the cleanup for a
     * failed capture unlinked whatever that name pointed at. `VACUUM INTO` refuses an existing
     * target, so the run that lost the race deleted the winner's good snapshot and reported
     * only a failure.
     *
     * `PRAGMA query_only = ON` is the deterministic failure: measured on this machine it makes
     * `VACUUM INTO` fail with "attempt to write a readonly database", while the live database
     * stays readable. It is reset in `finally` so no later test inherits it.
     *
     * The frozen time keeps the name assertion stable. The failure itself no longer depends on
     * a name collision: the run names its own temporary file and must remove only that, whether
     * or not a snapshot from the same second already exists.
     */
    public function test_a_failed_snapshot_reports_failure_without_deleting_a_valid_snapshot(): void
    {
        $directory = $this->scratch.'/failing';

        Carbon::setTestNow(Carbon::create(2026, 11, 2, 3, 17, 1, 'UTC'));

        try {
            $this->artisan('papyrus:snapshot', ['--into' => $directory])->assertSuccessful();

            $first = glob($directory.'/reader-half-*.sqlite') ?: [];

            $this->assertCount(1, $first, 'the first snapshot must exist before the failure');

            $this->assertStringStartsWith(
                'reader-half-20261102-031701-',
                basename($first[0]),
                'the name must come from the frozen UTC clock, followed by its random suffix',
            );

            DB::statement('PRAGMA query_only = ON');

            try {
                $exit = $this->artisan('papyrus:snapshot', ['--into' => $directory])->run();
            } finally {
                DB::statement('PRAGMA query_only = OFF');
            }
        } finally {
            Carbon::setTestNow();
            DB::statement('PRAGMA query_only = OFF');
        }

        $this->assertSame(1, $exit, 'a failed snapshot must report failure, not success');

        $this->assertFileExists($first[0], 'the valid snapshot must survive the failed run');

        $copy = new \PDO('sqlite:'.$first[0]);

        $this->assertSame(
            1,
            (int) $copy->query("select count(*) from sqlite_master where type='table' and name='chapter_states'")->fetchColumn(),
            'the surviving snapshot must still open as the database it was',
        );

        $this->assertSame(
            [],
            glob($directory.'/.reader-half-*') ?: [],
            'the failed run must remove its own temporary file and leave nothing else behind',
        );
    }

    /**
     * Two snapshots in one second both survive, under distinct random suffixes.
     *
     * Both runs share the frozen timestamp prefix, and neither can compute a name the other
     * owns, so the second cannot fail on the first and neither replaces the other. This proves
     * the same-second collision directly: two runs under one timestamp both survive, with no
     * `-2` suffix and no reliance on lexicographic ordering.
     */
    public function test_two_snapshots_in_the_same_second_do_not_collide(): void
    {
        $directory = $this->scratch.'/same-second';

        Carbon::setTestNow(Carbon::create(2026, 11, 2, 3, 17, 1, 'UTC'));

        try {
            $this->artisan('papyrus:snapshot', ['--into' => $directory])->assertSuccessful();

            $first = glob($directory.'/reader-half-*.sqlite') ?: [];

            $this->assertCount(1, $first, 'the first run must write one snapshot');

            $this->artisan('papyrus:snapshot', ['--into' => $directory])->assertSuccessful();
        } finally {
            Carbon::setTestNow();
        }

        $files = glob($directory.'/reader-half-*.sqlite') ?: [];
        sort($files);

        $this->assertCount(2, $files, 'both runs must leave a snapshot');

        $names = array_map('basename', $files);

        foreach ($names as $name) {
            $this->assertStringStartsWith(
                'reader-half-20261102-031701-',
                $name,
                'both names must carry the frozen UTC timestamp prefix and a suffix',
            );
        }

        $this->assertNotSame(
            $names[0],
            $names[1],
            'the two runs must choose distinct random suffixes rather than replace each other',
        );

        foreach ($files as $file) {
            $copy = new \PDO('sqlite:'.$file);

            $this->assertSame(
                1,
                (int) $copy->query("select count(*) from sqlite_master where type='table' and name='chapter_states'")->fetchColumn(),
                basename($file).' must open as a database with the schema',
            );
        }

        $this->assertFileExists(
            $first[0],
            'the first run\'s snapshot must not be replaced by the second run',
        );

        $original = new \PDO('sqlite:'.$first[0]);

        $this->assertSame(
            1,
            (int) $original->query('select count(*) from chapter_states')->fetchColumn(),
            'the first run\'s snapshot must still hold the row it captured',
        );
    }

    /**
     * A plain timestamped snapshot is not a target and is not replaced.
     *
     * The naming before the random suffix used `reader-half-<stamp>.sqlite`. Whether such a
     * file came from an earlier build or was placed there by hand, a run in the same second
     * must succeed without treating the plain name as its own target. The older file keeps its
     * bytes, and the new snapshot sits beside it under the same timestamp with a random suffix.
     */
    public function test_a_run_succeeds_when_the_plain_timestamped_name_is_already_taken(): void
    {
        $directory = $this->scratch.'/upgrade';
        $plain = $directory.'/reader-half-20261102-031701.sqlite';

        Carbon::setTestNow(Carbon::create(2026, 11, 2, 3, 17, 1, 'UTC'));

        try {
            $this->artisan('papyrus:snapshot', ['--into' => $directory])->assertSuccessful();

            $written = glob($directory.'/reader-half-*.sqlite') ?: [];

            $this->assertCount(1, $written, 'the setup snapshot must exist');

            $this->assertTrue(
                rename($written[0], $plain),
                'could not put the setup snapshot under the old plain timestamped name',
            );

            $before = (string) hash_file('sha256', $plain);

            $this->artisan('papyrus:snapshot', ['--into' => $directory])->assertSuccessful();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertFileExists($plain, 'the older version\'s snapshot must still be there');
        $this->assertSame(
            $before,
            (string) hash_file('sha256', $plain),
            'the new run must not replace the older version\'s snapshot',
        );

        $files = glob($directory.'/reader-half-*.sqlite') ?: [];

        $this->assertCount(2, $files, 'the older snapshot and the new one must both exist');

        $new = array_values(array_filter(
            $files,
            static fn (string $file): bool => $file !== $plain,
        ));

        $this->assertCount(1, $new, 'the run must write one new snapshot beside the older one');

        $this->assertStringStartsWith(
            'reader-half-20261102-031701-',
            basename($new[0]),
            'the new name must carry the same frozen timestamp with a random suffix',
        );

        foreach ([$plain, $new[0]] as $file) {
            $copy = new \PDO('sqlite:'.$file);

            $this->assertSame(
                1,
                (int) $copy->query("select count(*) from sqlite_master where type='table' and name='chapter_states'")->fetchColumn(),
                basename($file).' must open as a database with the schema',
            );
        }
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
        $this->assertSame([], glob($directory.'/reader-half-*.sqlite') ?: [], 'and write nothing');
    }

    /**
     * The file this command writes must not look like a backup, because it is not one.
     *
     * ## What this is fixing, measured
     *
     * On the reader's own install there were two of these beside the database:
     *
     *     papyrus-20261013-031701.sqlite   4.95 MB   10/12/2026 22:17
     *     papyrus-20261014-031700.sqlite   4.98 MB   10/13/2026 22:17
     *
     * Both from the daily 03:17 job, whose output `Artisan::call()` discards. So the arrangement was:
     * once a day, silently, write a file that reads as a backup, sits exactly where a reader would
     * look for one, and cannot be restored.
     *
     * It cannot be restored because the two databases point at each other by id.
     * `series_states.suwayomi_manga_id` names a row in Suwayomi's H2 file, so `BackupPair::read()`
     * refuses a manifest naming one half, and the symptom of restoring one alone is a library that
     * reads as empty with nothing saying why.
     *
     * Asserted on the name rather than on a message, because the name is what a reader sees in a file
     * listing six weeks from now, long after they have forgotten this command existed.
     */
    public function test_the_file_it_writes_does_not_look_like_a_backup(): void
    {
        $directory = $this->scratch.'/honesty';

        $this->artisan('papyrus:snapshot', ['--into' => $directory])->assertSuccessful();

        $written = glob($directory.'/reader-half-*.sqlite') ?: [];

        $this->assertCount(1, $written, 'one half, under a name that says so');

        $this->assertSame(
            [],
            glob($directory.'/papyrus-*.sqlite') ?: [],
            'and nothing named like the old version, which is what a reader mistakes for a backup',
        );

        $this->assertSame(
            [],
            glob($directory.'/backup-*.json') ?: [],
            'and nothing named like a manifest, because there is no pair here',
        );

        $this->assertStringContainsString(
            'reader-half',
            basename($written[0]),
            'the name itself has to carry the warning, since a file listing is all a reader has',
        );
    }

    /**
     * And it says so out loud, every time, because the name is not the only way it is found.
     *
     * The scheduled job discards this output, which is half the problem: a reader who runs the
     * command by hand gets the explanation, and a reader who finds the file later gets the name. Both
     * need it, and neither alone is enough.
     */
    public function test_it_says_it_is_not_a_backup_and_names_the_command_that_is(): void
    {
        $directory = $this->scratch.'/says-so';

        $this->artisan('papyrus:snapshot', ['--into' => $directory])
            ->expectsOutputToContain('one half of a backup, not a backup')
            ->expectsOutputToContain('papyrus snapshot')
            ->assertSuccessful();
    }

    /**
     * It must not claim to be restorable in the sentence that names the file.
     *
     * The old output was `Snapshot written: papyrus-20261013-031701.sqlite (4,949,760 bytes)`, which
     * is a sentence with no caveat in it at all.
     */
    public function test_it_does_not_announce_itself_as_a_backup(): void
    {
        $directory = $this->scratch.'/announcing';

        $output = $this->runAndCapture($directory);

        $this->assertStringContainsString('Snapshot written', $output);
        $this->assertStringNotContainsStringIgnoringCase('backup written', $output);
        $this->assertStringContainsString('one half of a backup, not a backup', $output);
    }

    /**
     * Pruning only ever deletes what this command wrote.
     *
     * The glob used to be `papyrus-*.sqlite`, and a snapshot folder is a reader's folder. A file
     * there that this command never wrote would have been deleted silently, which is the one thing a
     * retention routine must never do to a folder it shares.
     */
    public function test_pruning_never_deletes_a_file_it_did_not_write(): void
    {
        $directory = $this->scratch.'/foreign';
        File::ensureDirectoryExists($directory);

        $keep = 'reader-half-20260101-000000.sqlite';
        $mine = 'reader-half-20260201-000000.sqlite';
        $theirs = 'a-file-someone-else-put-here.sqlite';
        $looks_like_a_snapshot = 'papyrus-20250101-000000.sqlite';

        foreach ([$keep, $mine, $theirs, $looks_like_a_snapshot] as $name) {
            File::put($directory.'/'.$name, 'x');
        }

        $this->artisan('papyrus:snapshot', ['--into' => $directory, '--keep' => 1, '--prune' => true])
            ->assertSuccessful();

        $left = array_map('basename', glob($directory.'/*') ?: []);
        sort($left);

        $this->assertSame([$theirs, $looks_like_a_snapshot, $mine], $left);
    }

    /**
     * The default folder's name says what is in it.
     *
     * `backups/` is where `papyrus snapshot` puts whole pairs. A half sharing that folder with pairs
     * is how somebody tries to restore one.
     */
    public function test_the_default_folder_is_not_the_one_pairs_live_in(): void
    {
        $this->artisan('papyrus:snapshot')->assertSuccessful();

        $expected = dirname((string) config('database.connections.sqlite.database')).DIRECTORY_SEPARATOR.'reader-snapshots';

        $this->assertDirectoryExists($expected);
        $this->assertCount(1, glob($expected.'/reader-half-*.sqlite') ?: []);

        $this->assertSame(
            [],
            glob(dirname((string) config('database.connections.sqlite.database')).DIRECTORY_SEPARATOR.'backups') ?: [],
            'and nothing was written into the folder pairs live in',
        );
    }

    /**
     * Run the command and hand back everything it printed.
     */
    private function runAndCapture(string $directory): string
    {
        Artisan::call('papyrus:snapshot', ['--into' => $directory]);

        return (string) Artisan::output();
    }
}
