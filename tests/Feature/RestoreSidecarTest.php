<?php

namespace Tests\Feature;

use Papyrus\Launcher\BackupPair;
use Papyrus\Launcher\FileMover;
use Papyrus\Launcher\LocalFileMover;
use Papyrus\Launcher\Restorer;
use Papyrus\Launcher\RestoreRefused;
use PDO;
use Tests\TestCase;

/**
 * The write-ahead log that belongs to the database being replaced.
 *
 * ## What was measured, and what it means
 *
 * Measured on a database that had just been created, written to, and left with its WAL holding rows
 * the main file did not have, then had that main file replaced by a snapshot while the WAL was left
 * exactly where it was:
 *
 *     the snapshot alone                    -> 2 rows, no sidecars
 *     the snapshot plus the previous WAL    -> 3 rows, integrity ok
 *
 * Three rows, not two. **The stale WAL was replayed onto the restored database**, so the restored
 * library gained a row that belonged to the install that had just been replaced. `integrity_check`
 * said `ok` throughout, because the result is a valid database containing a row that should not be
 * in it.
 *
 * That is worse than an error. There is nothing to notice.
 *
 * Measured on the reader's own install: `database.sqlite` is 5,192,368 bytes with a 4,140,632 byte
 * `database.sqlite-wal` beside it. Three quarters of the database is in a file the restore does not
 * mention, and AGENTS.md already warns that copying `database.sqlite` on its own is the quiet way to
 * lose rows.
 *
 * ## What these tests pin
 *
 * The `-wal` and `-shm` are not independent files. They belong to the main database, they are
 * replaced with it, and they come back with it. Getting either half of that wrong is invisible: the
 * database opens, the page renders, and the numbers are a mixture of two timelines.
 */
class RestoreSidecarTest extends TestCase
{
    private string $workspace = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-sidecar-'.bin2hex(random_bytes(6));

        mkdir($this->backupDir(), 0o777, true);
        mkdir($this->liveDir(), 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);

        parent::tearDown();
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach ((array) glob($path.'/*') as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            is_dir($entry) ? $this->removeTree($entry) : @unlink($entry);
        }

        @rmdir($path);
    }

    private function backupDir(): string
    {
        return $this->workspace.DIRECTORY_SEPARATOR.'backup';
    }

    private function liveDir(): string
    {
        return $this->workspace.DIRECTORY_SEPARATOR.'data';
    }

    private function databaseFile(): string
    {
        return $this->liveDir().DIRECTORY_SEPARATOR.'database.sqlite';
    }

    /**
     * Insert one note.
     *
     * A prepared statement rather than `exec()` with a value, because `PDO::exec()` takes exactly
     * one argument and silently reads a second as an error. The first run of this test failed four
     * times on that rather than on anything to do with the write-ahead log.
     */
    private function insert(PDO $pdo, string $note): void
    {
        $statement = $pdo->prepare('INSERT INTO chapter_states (note) VALUES (?)');
        $statement->execute([$note]);
    }

    /**
     * A snapshot and its manifest, as `VACUUM INTO` produces them: no sidecars.
     *
     * The manifest is written alongside because a pair is two files and a manifest binding them, and
     * this test is about the files rather than the manifest, so the manifest is treated as scenery.
     */
    private function writeSnapshot(string $marker = 'from the snapshot'): BackupPair
    {
        $path = $this->backupDir().DIRECTORY_SEPARATOR.'snapshot.sqlite';

        $pdo = new PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE chapter_states (id INTEGER PRIMARY KEY, note TEXT)');
        $this->insert($pdo, $marker);
        unset($pdo);

        $manifest = $this->backupDir().DIRECTORY_SEPARATOR.'snapshot.json';

        file_put_contents($manifest, json_encode([
            'version' => 1,
            'token' => '20261013-031701',
            'taken_at' => '2026-10-13T03:17:01+00:00',
            'papyrus' => [
                'file' => 'snapshot.sqlite',
                'sha256' => hash_file('sha256', $path),
                'bytes' => filesize($path),
                'series_states' => 867,
                'chapter_states' => 21447,
            ],
            'suwayomi' => [
                'file' => 'snapshot.mv.db',
                'sha256' => hash_file('sha256', $this->writeManga()),
                'bytes' => filesize($this->backupDir().DIRECTORY_SEPARATOR.'snapshot.mv.db'),
                'server_version' => '2.4.2366',
            ],
        ]));

        return BackupPair::read($manifest, $this->backupDir());
    }

    private function writeManga(): string
    {
        $path = $this->backupDir().DIRECTORY_SEPARATOR.'snapshot.mv.db';

        file_put_contents($path, 'BACKUP-MANGA');

        return $path;
    }

    /**
     * The live reader's database, left with a WAL holding a row the main file does not have.
     *
     * The sidecars are copied out while the connection is open and put back after it is closed,
     * which is the only arrangement that produces both properties this test needs at once:
     *
     *  - the WAL really exists on disk, because closing a connection checkpoints and deletes it. The
     *    first measurement attempt closed the connection first, copied nothing, and reported
     *    `integrity: ok` for a stale-WAL case that never happened.
     *  - nothing holds the main file open, because Windows refuses to rename an open file and the
     *    restore's first step is a rename. A version that kept the handle open could not get as far
     *    as the thing it was testing.
     *
     * @return list<string> The notes the live database holds, read from a copy.
     */
    private function writeLiveWithLiveWal(): array
    {
        $db = $this->databaseFile();

        $handle = new PDO('sqlite:'.$db, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $handle->exec('PRAGMA journal_mode=WAL');
        $handle->exec('CREATE TABLE chapter_states (id INTEGER PRIMARY KEY, note TEXT)');
        $this->insert($handle, 'before the restore');

        // Committed, and living only in the WAL. This is the row a stale WAL would carry across.
        $this->insert($handle, 'a row from the OLD install');

        $this->assertFileExists($db.'-wal', 'the fixture must produce a WAL or this test measures nothing');

        // Copy the sidecars out while they exist.
        $saved = [];

        foreach (['-wal', '-shm'] as $suffix) {
            if (is_file($db.$suffix)) {
                $saved[$suffix] = (string) file_get_contents($db.$suffix);
            }
        }

        // Closing checkpoints and deletes them, so put them straight back.
        unset($handle);

        foreach ($saved as $suffix => $bytes) {
            file_put_contents($db.$suffix, $bytes);
        }

        $this->assertFileExists(
            $db.'-wal',
            'the WAL did not survive being copied out and back, so this test would measure nothing',
        );

        /*
         * The fixture checks itself WITHOUT opening the live database, and that is the whole
         * point of the third version of it.
         *
         *
otes() opens the database. Opening a database with a WAL checkpoints it and deletes
         * the sidecars, so a fixture that verified itself by opening the database destroyed the
         * very WAL it had just put back. Two tests then failed on a missing file and the defect
         * they were written for never got a chance to show.
         *
         * So the replay is proved on a COPY, and the live directory is only ever inspected with
         * is_file, which opens nothing.
         */
        $probe = $this->workspace.DIRECTORY_SEPARATOR.'probe';
        mkdir($probe, 0o777, true);

        foreach (['' => $db, '-wal' => $db.'-wal', '-shm' => $db.'-shm'] as $suffix => $from) {
            if (is_file($from)) {
                copy($from, $probe.DIRECTORY_SEPARATOR.'database.sqlite'.$suffix);
            }
        }

        $replayed = $this->notes($probe.DIRECTORY_SEPARATOR.'database.sqlite');
        $this->removeTree($probe);

        $this->assertContains(
            'a row from the OLD install',
            $replayed,
            'the WAL did not replay on a copy either, so there is no stale row for a restore to '
            .'inherit and every assertion in this file would pass for the wrong reason.',
        );

        // Handed back so no caller has to read the live database to find out what it holds.
        //
        // Which is the whole lesson of this fixture, stated as a return value: reading the live
        // database is what destroys the WAL, so a caller that wants to know what is in it must not
        // ask. The first version of the rollback test called `notes()` here and destroyed the very
        // file it was about to assert on.
        return $replayed;
    }

    private function liveMangaFile(): string
    {
        return $this->liveDir().DIRECTORY_SEPARATOR.'database.mv.db';
    }

    /**
     * The live install as the command describes it: a target and the files that belong to it.
     *
     * @return array{papyrus: array{target: string, companions: list<string>}, suwayomi: array{target: string, companions: list<string>}}
     */
    private function live(): array
    {
        $companions = [];

        foreach (['-wal', '-shm'] as $suffix) {
            if (is_file($this->databaseFile().$suffix)) {
                $companions[] = $this->databaseFile().$suffix;
            }
        }

        return [
            'papyrus' => ['target' => $this->databaseFile(), 'companions' => $companions],
            'suwayomi' => ['target' => $this->liveMangaFile(), 'companions' => []],
        ];
    }

    private function notes(string $database): array
    {
        $pdo = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $rows = $pdo->query('SELECT note FROM chapter_states ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

        unset($pdo);

        return $rows;
    }

    // ---------------------------------------------------------------------------------------------

    /**
     * The restored database holds the snapshot's rows and nothing else.
     *
     * The measured failure: with the WAL left in place, this returned three rows, one of them
     * `a row from the OLD install`. The database opened cleanly and `integrity_check` said `ok`.
     */
    public function test_the_restored_database_does_not_inherit_the_old_wal(): void
    {
        $before = $this->writeLiveWithLiveWal();

        file_put_contents($this->liveMangaFile(), 'LIVE-MANGA');

        $this->assertContains('a row from the OLD install', $before, 'the fixture must set this up');

        $pair = $this->writeSnapshot();

        (new Restorer(new LocalFileMover))->restore($pair, $this->live());

        $this->assertSame(
            ['from the snapshot'],
            $this->notes($this->databaseFile()),
            'the restored database carries a row from the install that was just replaced, which is '
            .'a valid database containing data that should not be in it',
        );

    }

    /**
     * The sidecars are gone, not merely harmless.
     *
     * Leaving a WAL beside a database it does not belong to means the next process to open it decides
     * what to do with a file nobody expected. The snapshot has no sidecars of its own, so the correct
     * end state is no sidecars at all.
     */
    public function test_the_sidecars_are_removed_by_a_restore(): void
    {
        $this->writeLiveWithLiveWal();

        file_put_contents($this->liveMangaFile(), 'LIVE-MANGA');

        $this->assertFileExists($this->databaseFile().'-wal');

        (new Restorer(new LocalFileMover))->restore($this->writeSnapshot(), $this->live());

        $this->assertFileDoesNotExist(
            $this->databaseFile().'-wal',
            'a write-ahead log belonging to the replaced database is still beside the restored one',
        );
        $this->assertFileDoesNotExist($this->databaseFile().'-shm');

    }

    /**
     * A rollback brings the WAL back with the main file.
     *
     * The mirror image of the defect above, and worse if it goes wrong. The live WAL held rows the
     * main database did not have, so rolling back by restoring the main file alone would silently
     * discard them. That is the exact loss AGENTS.md warns about, in the direction that looks like a
     * successful undo.
     */
    public function test_a_rollback_brings_the_wal_back_too(): void
    {
        $before = $this->writeLiveWithLiveWal();

        file_put_contents($this->liveMangaFile(), 'LIVE-MANGA');

        $failing = new class(new LocalFileMover) implements FileMover
        {
            public function __construct(private readonly FileMover $inner) {}

            public function move(string $from, string $to): void
            {
                $this->inner->move($from, $to);
            }

            public function copy(string $from, string $to): void
            {
                if (str_contains($to, '.mv.db')) {
                    throw new RestoreRefused('the manga half failed to copy');
                }

                $this->inner->copy($from, $to);
            }

            public function digest(string $path): string
            {
                return $this->inner->digest($path);
            }

            public function exists(string $path): bool
            {
                return $this->inner->exists($path);
            }
        };

        $refused = false;

        try {
            (new Restorer($failing))->restore($this->writeSnapshot(), $this->live());
        } catch (RestoreRefused) {
            $refused = true;
        }

        $this->assertTrue($refused, 'the restore was supposed to fail');

        $this->assertFileExists(
            $this->databaseFile().'-wal',
            'the rollback put the main file back without its WAL, which discards every row that was '
            .'in the WAL. That is a successful-looking undo of data the reader still had.',
        );

        $this->assertSame(
            $before,
            $this->notes($this->databaseFile()),
            'the rows that were only in the WAL did not survive the rollback',
        );

    }

    /**
     * An install with no WAL is unaffected.
     *
     * So the fix is not "delete a WAL that may not be there" dressed up as correctness: a database
     * that was cleanly closed has no sidecars, and the restore must work on it too.
     */
    public function test_an_install_with_no_wal_restores_normally(): void
    {
        file_put_contents($this->databaseFile(), 'LIVE-PAPYRUS');
        file_put_contents($this->liveMangaFile(), 'LIVE-MANGA');

        $pair = $this->writeSnapshot();

        $outcome = (new Restorer(new LocalFileMover))->restore($pair, $this->live());

        $this->assertSame(['from the snapshot'], $this->notes($this->databaseFile()));
        $this->assertSame('BACKUP-MANGA', (string) file_get_contents($this->liveMangaFile()));
        $this->assertArrayHasKey('papyrus', $outcome->rollback);
        $this->assertArrayHasKey('suwayomi', $outcome->rollback);
    }
}
