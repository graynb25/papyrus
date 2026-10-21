<?php

namespace Tests\Feature;

use App\Backup\ReaderHalfRefused;
use App\Backup\ReaderHalfSwap;
use Illuminate\Support\Facades\Config;
use PDO;
use Tests\TestCase;

/**
 * The reader half's own seam, on its own, without a backup or a restore around it.
 *
 * ## What only this class can prove
 *
 * `restore-probe22.php` measured the shape twice in a scratch directory before the class
 * existed, and these tests carry the measured promises into the suite: the swap replaces
 * the live rows with the candidate's inside one committed point, the `id` counters the
 * refills would otherwise move are carried at the end, a foreign writer refuses the brief
 * window in SQLite's own words, and a plain reader is not that foreign writer.
 *
 * No migration runs and Laravel's connection is never opened: the class opens its own
 * plain PDO connection, so the test hands it a hand made database whose schema carries the
 * two tables the counts read, plus a parent and child sharing real foreign keys.
 */
class ReaderHalfSwapTest extends TestCase
{
    private string $scratch;

    private string $live;

    private string $candidate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = rtrim((string) sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'papyrus-swap-'.getmypid().'-'.uniqid();
        mkdir($this->scratch, 0o777, true);

        $this->live = $this->scratch.DIRECTORY_SEPARATOR.'live.sqlite';
        $this->candidate = $this->scratch.DIRECTORY_SEPARATOR.'candidate.sqlite';

        Config::set('database.connections.sqlite.database', $this->live);
    }

    protected function tearDown(): void
    {
        foreach ([$this->live, $this->candidate] as $file) {
            foreach ([$file, $file.'-wal', $file.'-shm'] as $name) {
                if (is_file($name)) {
                    @unlink($name);
                }
            }
        }

        @rmdir($this->scratch);

        parent::tearDown();
    }

    /**
     * The whole swap: a live database whose rows are replaced, in one point, by the
     * candidate's, with every row the candidate carries refilled and nothing of the old
     * rows left anywhere.
     */
    public function test_the_swap_replaces_the_live_rows_with_the_candidates(): void
    {
        $this->fill($this->live, [['b1', 'Ada'], ['b2', 'Ada']], 9, 40);
        $this->fill($this->candidate, [['n1', '176 Beta'], ['n2', '500 Gamma']], 2, 7);

        $counts = (new ReaderHalfSwap)->swap($this->candidate);

        $this->assertSame(2, $counts['series'], 'the counts answer the two tables the class owns, in the candidate\'s rows');
        $this->assertSame(7, $counts['chapters']);

        $live = $this->open($this->live);

        $this->assertSame(
            [['name' => '176 Beta'], ['name' => '500 Gamma']],
            $live->query('select name from authors order by id')->fetchAll(PDO::FETCH_ASSOC),
            'the live rows are gone and the candidate\'s are in',
        );

        $this->assertSame(
            [['title' => 'n1'], ['title' => 'n2']],
            $live->query('select title from books order by id')->fetchAll(PDO::FETCH_ASSOC),
        );

        $this->assertSame(2, (int) $live->query('select count(*) from series_states')->fetchColumn(), 'the candidate\'s series are the ones that came back');
        $this->assertSame('ok', (string) $live->query('PRAGMA integrity_check')->fetchColumn());
    }

    /**
     * The probe22 promise the refills cannot keep by accident: a candidate whose counter
     * has moved past its own rows keeps that counter, so the next insert does not renumber
     * rows the reader's file says existed.
     *
     * The candidate holds five books and deletes its four most recent, which is where its
     * counter reaches 5 while its rows stop at 1: a refill alone would end the counter at
     * the highest id it inserts, and the number past it would come back wrong.
     */
    public function test_the_id_counters_survive_the_refills(): void
    {
        $this->fill($this->live, [['b1', 'Ada']], 4, 4);
        $this->fill($this->candidate, [
            ['kept', 'Ada'],
            ['gone', 'Ada'],
            ['gone', 'Ada'],
            ['gone', 'Ada'],
            ['gone', 'Ada'],
        ], 5, 5);

        // The candidate deleted its four most recent books: one row left, counter 5.
        $remove = $this->open($this->candidate);

        $remove->exec('BEGIN IMMEDIATE');
        $remove->exec("delete from books where title = 'gone'");
        $remove->exec('COMMIT');
        $remove = null;

        (new ReaderHalfSwap)->swap($this->candidate);

        $live = $this->open($this->live);

        $this->assertSame(1, (int) $live->query('select count(*) from books')->fetchColumn());

        // The next book the app adds sits past the counter, not past the highest refilled row.
        $insert = $live->prepare('insert into books (author_id, title) values (1, ?)');
        $insert->execute(['next']);

        $this->assertSame(
            6,
            (int) $live->query('select max(id) from books')->fetchColumn(),
            'the carry over of the id counter is the only place 6 comes from: the refilled rows alone would name 2',
        );
    }

    /**
     * Refusal number one, as SQLite says it: another writer's transaction is open, and the
     * swap refuses to wait past its own busy timeout, naming the SQLSTATE and the error
     * code inside the refusal the run row will carry.
     */
    public function test_a_foreign_writer_refuses_the_window_in_sqlite_s_own_words(): void
    {
        $this->fill($this->live, [['b1', 'Exists']], 2, 2);
        $this->fill($this->candidate, [['c1', 'Unnamed Author']], 1, 1);

        $writer = $this->open($this->live);

        // Set the journal mode while this connection holds nothing, then take the write lock.
        $writer->exec('PRAGMA journal_mode = WAL');
        $writer->exec('BEGIN IMMEDIATE');
        $writer->exec("insert into authors (name) values ('still writing')");

        try {
            (new ReaderHalfSwap)->swap($this->candidate);
            $this->fail('The swap walked over a foreign write transaction.');
        } catch (ReaderHalfRefused $e) {
            $this->assertStringContainsString('database is locked', $e->getMessage());
            $this->assertStringContainsString('SQLSTATE HY000', $e->getMessage());
            $this->assertStringContainsString('SQLite error 5', $e->getMessage());
        } finally {
            $writer->rollBack();
            $writer = null;
        }

        $this->assertSame(1, (int) $this->open($this->live)->query('select count(*) from authors')->fetchColumn(), 'the refusal leaves the live rows exactly as they were');
    }

    /** And a reader holding an open transaction is not a writer: the swap goes past it. */
    public function test_a_plain_reader_does_not_block_the_window(): void
    {
        $this->fill($this->live, [['b1', 'Ada']], 2, 2);
        $this->fill($this->candidate, [['c1', '176 Beta']], 1, 1);

        $reader = $this->open($this->live);

        // WAL first, while this connection holds nothing, because the live shape the swap
        // meets is the app's own connection, which runs WAL; a reader in rollback journal
        // mode is a fixture-only shape.
        $reader->exec('PRAGMA journal_mode = WAL');

        $reader->exec('BEGIN DEFERRED');
        $reader->query('select count(*) from authors')->fetchColumn();

        $this->assertSame(
            'b1',
            (string) $reader->query('select title from books')->fetchColumn(),
            'the old row is what a reader holding an open transaction saw',
        );

        (new ReaderHalfSwap)->swap($this->candidate);

        /*
         * WAL's own snapshot semantics, as probe22 measured them: a reader holding an open
         * transaction keeps the snapshot it started on whatever it reads, so the book above
         * stayed old through the whole swap. New content arrives on the next access after
         * that transaction ends, and never anything between the two.
         */
        $reader->exec('COMMIT');

        $this->assertSame(
            'c1',
            (string) $reader->query('select title from books')->fetchColumn(),
            'the reader\'s next access after its own transaction ends sees the candidate\'s rows, and never anything between',
        );

        $reader = null;
    }

    /**
     * A candidate that does not carry the live schema is refused before anything is
     * deleted: the live rows the newer schema would lose are exactly the rows that must
     * still be there after the refusal.
     */
    public function test_a_schema_mismatch_refuses_before_anything_is_deleted(): void
    {
        $this->fill($this->live, [['b1', 'Exists']], 2, 2);
        $this->fill($this->candidate, [['b1', 'Exists']], 2, 2);

        $extra = $this->open($this->candidate);
        $extra->exec('create table candidate_only (id integer primary key)');
        $extra = null;

        try {
            (new ReaderHalfSwap)->swap($this->candidate);
            $this->fail('The swap refilled a live database from a schema it does not hold.');
        } catch (ReaderHalfRefused $e) {
            $this->assertStringContainsString('does not carry the schema the live database has', $e->getMessage());
            $this->assertStringContainsString('candidate_only', $e->getMessage());
        }

        $live = $this->open($this->live);

        $this->assertSame([['title' => 'b1']], $live->query('select title from books')->fetchAll(PDO::FETCH_ASSOC), 'the refusal leaves the live rows exactly as they were');
    }

    // -------------------------------------------------------------------------------------------

    /**
     * A database in a hand made schema the two counts read.
     *
     * @param  list<array{string, string}>  $books  Title and author name. The named authors exist once each.
     */
    private function fill(string $file, array $books, int $series, int $chapters): void
    {
        @unlink($file);
        @unlink($file.'-wal');
        @unlink($file.'-shm');

        $pdo = $this->open($file);

        $pdo->exec('create table authors (id integer primary key autoincrement, name text not null)');
        $pdo->exec('create table books (id integer primary key autoincrement, author_id integer not null references authors(id), title text not null)');
        $pdo->exec('create table series_states (id integer primary key, user_id integer not null, suwayomi_manga_id integer not null, created_at text not null, updated_at text not null)');
        $pdo->exec(
            'create table chapter_states (id integer primary key, user_id integer not null, '
            .'suwayomi_manga_id integer not null, is_read integer not null, is_bookmarked integer not null, '
            .'last_page_read integer not null, read_seconds integer not null, '
            .'created_at text not null, updated_at text not null)'
        );

        $insert = $pdo->prepare('insert into books (author_id, title) values (?, ?)');

        foreach ($books as [$title, $authorName]) {
            $insert->execute([$this->authorId($pdo, $authorName), $title]);
        }

        $stamp = '2026-01-01 00:00:00';

        $seriesInsert = $pdo->prepare(
            'insert into series_states (user_id, suwayomi_manga_id, created_at, updated_at) values (1, ?, ?, ?)'
        );

        for ($rows = 1; $rows <= $series; $rows++) {
            $seriesInsert->execute([$rows, $stamp, $stamp]);
        }

        $chaptersInsert = $pdo->prepare(
            'insert into chapter_states (user_id, suwayomi_manga_id, is_read, is_bookmarked, '
            .'last_page_read, read_seconds, created_at, updated_at) '
            .'values (1, ?, 0, 0, 0, 0, ?, ?)'
        );

        for ($rows = 1; $rows <= $chapters; $rows++) {
            $chaptersInsert->execute([$rows, $stamp, $stamp]);
        }
    }

    /** @throws void */
    private function authorId(PDO $pdo, string $name): int
    {
        $found = $pdo->prepare('select id from authors where name = ?');
        $found->execute([$name]);
        $id = $found->fetchColumn();

        if ($id !== false) {
            return (int) $id;
        }

        $insert = $pdo->prepare('insert into authors (name) values (?)');
        $insert->execute([$name]);

        return (int) $pdo->lastInsertId();
    }

    private function open(string $file): PDO
    {
        $pdo = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }
}
