<?php

namespace App\Backup;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * The reader half's one brief SQLite point, where the pair's database becomes this one.
 *
 * ## The arrangement, measured before it was built
 *
 * This is the shape `restore-probe22.php` measured twice in a scratch directory before a
 * line of this class was written: one `BEGIN IMMEDIATE` transaction, an `ATTACH` of the
 * pair's reader half under the name `candidate`, every table deleted and refilled from the
 * candidate inside that one transaction, and a single `COMMIT`. At realistic volume the
 * measured commit was 6 ms for 867 series and 20,808 chapters, so a reader of the live
 * database sees old rows through the whole swap and new rows at its next access, and
 * nothing in between.
 *
 * ## The refusals, and why they are this class's own shape
 *
 * With a foreign write transaction open elsewhere (the app itself in another request, a
 * worker's answer mid flight), the swap's own `BEGIN IMMEDIATE` refuses with SQLite's
 * `database is locked`, `SQLSTATE HY000`, error 5, also measured: a plain reader open
 * never blocks the swap, and only a writer does. That refusal surfaces as
 * {@see ReaderHalfRefused}, carrying both the SQLSTATE and the sentence SQLite said,
 * because "the restore did nothing" has to be provable from the row alone.
 *
 * A schema mismatch between the pair's half and the live database, and a cycle in the
 * foreign key graph the copy order would have to navigate, are refused the same way,
 * before anything is deleted. Foreign keys run ON here, matching the app's own
 * connection, so the copy order is derived at runtime from `PRAGMA foreign_key_list`:
 * children deleted before their parents, parents refilled before their children, and the
 * `id` counters carried across by `sqlite_sequence` at the end, once the refills have
 * stopped moving them.
 *
 * ## The surfaces that are never part of the swap
 *
 * Not every table in the file belongs to the pair. The job's own suite forced this one:
 * the swap used to refill every table, and that refill emptied `live_restore_runs` and
 * `live_backup_runs` mid run, so the receipt row the restore had already written vanished
 * and `complete()` updated zero rows in silence. Measured beside it: this install's
 * queue, sessions and cache all live in this same database (`QUEUE_CONNECTION`,
 * `SESSION_DRIVER` and `CACHE_STORE` are each `database` in the shipped `.env`), so a
 * whole-file swap while the app is live would erase the queue the acceptance premise says
 * must keep churning, and every reader's login with it. The preserve list
 * {@see self::PRESERVED} is the owner's answer to exactly that evidence, taken on
 * 2026-10-20.
 */
final class ReaderHalfSwap
{
    /** SQLite's own driver error code for a lock another writer holds. */
    private const SQLITE_BUSY = 5;

    /** How long the swap waits for a foreign write transaction before refusing. */
    private const BUSY_TIMEOUT_MILLISECONDS = 10000;

    /**
     * The running app's own surfaces, carried whole through the swap.
     *
     * Their rows come from this app and not from the pair, and the restore run's own
     * writes are among them: keeping them is what lets one restore hold its receipt row
     * from `create()` to `complete()` without the swap deleting it underneath. Their
     * `id` counters are excluded from the sequence carry for the same reason, so a table
     * here keeps this app's numbering wherever the pair's file took it, and SQLite
     * derives a missing counter from the rows themselves when one is absent.
     *
     * A pair captures the whole live database, so these tables ride along in the pair
     * unchanged. A restore of a pair onto a different install keeps swap table rows that
     * point at preserved rows only when those preserved rows are still here, which is
     * what {@see self::refuseOrphanedCandidateRows()} verifies row by row before
     * anything moves.
     */
    private const PRESERVED = [
        'users',
        'password_reset_tokens',
        'sessions',
        'passkeys',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'migrations',
        'live_backup_runs',
        'live_restore_runs',
    ];

    /**
     * @param  string  $pairReaderFile  The pair's `.sqlite` half, read but never written.
     * @param  (callable(string $words): void)|null  $note  One line per stage, for whoever is watching.
     * @return array{series: int, chapters: int} The counts now in the live database.
     *
     * @throws ReaderHalfRefused
     */
    public function swap(string $pairReaderFile, ?callable $note = null): array
    {
        $say = $note ?? static function (string $words): void {};

        if (! is_file($pairReaderFile)) {
            throw new ReaderHalfRefused("The pair's reader half is not there to put back: {$pairReaderFile}");
        }

        $database = (string) config('database.connections.sqlite.database');

        if ($database === '' || ! is_file($database)) {
            throw new ReaderHalfRefused("The live database could not be found at {$database}, so the swap has nothing to swap into.");
        }

        $live = $this->connect($database);

        /*
         * Lines said while the transaction is open, delivered after it closes. Their delivery
         * is a row write through Laravel's own connection, and that connection cannot write
         * while this transaction holds the file's write lock: the job suite caught the undo,
         * the swap refusing on its own progress notes. The lines describe a transaction whose
         * commit has not landed yet, so they are held until the swap has proven its own
         * success, and a swap that rolls back never delivers them, the same clearing rule the
         * job applies to notes of work that did not happen.
         */
        $held = [];

        try {
            $this->begin($live);

            /*
             * The ATTACH joins the pair into this transaction before anything moves. Nothing
             * writes to the pair: every statement below selects from it only.
             */
            $this->exec($live, 'ATTACH DATABASE \''.str_replace('\'', '\'\'', $pairReaderFile).'\' AS candidate', 'the attach of the pair\'s reader half');

            $tables = $this->tables($live, 'main');
            $candidateTables = $this->tables($live, 'candidate');

            if ($tables !== $candidateTables) {
                throw new ReaderHalfRefused(sprintf(
                    'The pair\'s reader half does not carry the schema the live database has: '
                    .'the live database holds %s and the pair holds %s, and a swap between '
                    .'those two schemas would lose rows the newer one names. The live '
                    .'database was not touched.',
                    implode(', ', $tables),
                    implode(', ', $candidateTables),
                ));
            }

            $preservedTables = array_values(array_intersect($tables, self::PRESERVED));
            $swapTables = array_values(array_diff($tables, self::PRESERVED));

            /*
             * The two refusals the preserve decision makes necessary, both before anything
             * is deleted. A preserved surface's rows survive this transaction, so a pair
             * whose swap-table rows point at a preserved row this app no longer holds
             * would land as a foreign key violation inside the one committed point, after
             * nothing could be taken back. The row by row check runs here instead.
             */
            $this->refusePreservedChildEdges($live, $swapTables, $preservedTables);
            $this->refuseOrphanedCandidateRows($live, $swapTables, $preservedTables);

            $held[] = sprintf(
                '%d tables to swap and %d of the running app\'s own surfaces kept whole, entering the swap',
                count($swapTables),
                count($preservedTables),
            );

            foreach ($this->deleteOrder($live, $swapTables) as $table) {
                $this->exec($live, "DELETE FROM main.\"{$table}\"", "the emptying of {$table}");
            }

            $held[] = 'every table to swap emptied of its live rows, order chosen so no foreign key ran out';

            foreach ($this->insertOrder($live, $swapTables) as $table) {
                $this->exec($live, "INSERT INTO main.\"{$table}\" SELECT * FROM candidate.\"{$table}\" ORDER BY rowid", "the refilling of {$table}");
            }

            $this->carrySequences($live, $swapTables);

            $live->commit();

            $this->exec($live, 'DETACH DATABASE candidate', 'the detach of the pair\'s reader half');

            foreach ($held as $words) {
                $say($words);
            }

            $counts = [
                'series' => $this->count($live, 'series_states'),
                'chapters' => $this->count($live, 'chapter_states'),
            ];

            $say(sprintf(
                'committed in one point: %d series states and %d chapter states now live',
                $counts['series'],
                $counts['chapters'],
            ));

            return $counts;
        } catch (Throwable $e) {
            try {
                $inTransaction = $live->inTransaction();
            } catch (PDOException) {
                $inTransaction = false;
            }

            if ($inTransaction) {
                $live->rollBack();
            }

            $this->detachQuietly($live);

            if ($e instanceof ReaderHalfRefused) {
                throw $e;
            }

            throw new ReaderHalfRefused(
                "The reader half refused its swap and the live database was left as it was: {$e->getMessage()}",
                0,
                $e,
            );
        }
    }

    /**
     * The id counters, carried over once the refills are done, swap tables only.
     *
     * `sqlite_sequence` records the last number every AUTOINCREMENT table reached, and the
     * refills bump it themselves, so copying it before them would leave numbers the refills
     * then move. The swap tables' counters come from the pair; the preserved surfaces'
     * counters are never touched, so an install keeps its own history there. The table is a
     * shape SQLite creates for its own bookkeeping and is not in the copy list, so a pair
     * that carries one and a live database without one is a mismatch this refuses rather
     * than a column the swap invents.
     *
     * A swap table whose counter the pair's file does not carry is refunded by SQLite
     * itself: the deleted entry is recreated from the refilled rows at the next insert.
     *
     * @param  list<string>  $swapTables
     *
     * @throws ReaderHalfRefused
     */
    private function carrySequences(PDO $live, array $swapTables): void
    {
        $main = $this->hasSequence($live, 'main');
        $candidate = $this->hasSequence($live, 'candidate');

        if ($main !== $candidate) {
            throw new ReaderHalfRefused(
                'The pair\'s reader half and the live database disagree about id counters: one '
                .'carries a sequence table and the other does not, so a swap between them would '
                .'renumber rows. The live database was not touched.',
            );
        }

        if (! $main) {
            return;
        }

        $names = implode(', ', array_map(
            static fn (string $table): string => "'".str_replace('\'', '\'\'', $table)."'",
            $swapTables,
        ));

        $this->exec($live, "DELETE FROM main.sqlite_sequence WHERE name IN ({$names})", 'the clearing of the id counters the swap owns');
        $this->exec($live, "INSERT INTO main.sqlite_sequence SELECT name, seq FROM candidate.sqlite_sequence WHERE name IN ({$names})", 'the carrying over of the id counters the swap owns');
    }

    /**
     * Refuse a preserved surface that carries rows pointing into the swap.
     *
     * A preserved table's rows survive the whole transaction, so one of them pointing at a
     * table the swap empties would name a row that is not there while its own rows stood
     * still. No migration today makes that edge, so this is the guard a future migration
     * meets first instead of a mid transaction foreign key error it would have taken the
     * rollback to explain.
     *
     * @param  list<string>  $swapTables
     * @param  list<string>  $preservedTables
     *
     * @throws ReaderHalfRefused
     */
    private function refusePreservedChildEdges(PDO $live, array $swapTables, array $preservedTables): void
    {
        $inSwap = array_flip($swapTables);

        foreach ($preservedTables as $preserved) {
            foreach ($this->foreignKeys($live, $preserved) as $foreign) {
                $parent = (string) $foreign['table'];

                if (isset($inSwap[$parent])) {
                    throw new ReaderHalfRefused(sprintf(
                        "The running app's own %s table carries rows that point into %s, which "
                        .'the swap empties and refills, so those rows would name a row that is '
                        .'not there by the time the point commits. A swap can only run when '
                        .'every surface it preserves points only at other preserved surfaces. '
                        .'The live database was not touched.',
                        $preserved,
                        $parent,
                    ));
                }
            }
        }
    }

    /**
     * Verify, row group by row group, that the pair's rows point at preserved rows this app still has.
     *
     * The preserve decision keeps users (and the rest of {@see self::PRESERVED}) with this
     * app while the pair's reader data swaps under them, so the pair's saved rows can only
     * land when the account they name is still here: a pair taken before an account was
     * deleted carries rows whose parent is gone from the other side. The check reads the
     * candidate against the live preserved rows one foreign key at a time and refuses with
     * the actual value named, before anything is deleted.
     *
     * @param  list<string>  $swapTables
     * @param  list<string>  $preservedTables
     *
     * @throws ReaderHalfRefused
     */
    private function refuseOrphanedCandidateRows(PDO $live, array $swapTables, array $preservedTables): void
    {
        $preserved = array_flip($preservedTables);

        foreach ($swapTables as $child) {
            /** @var array<int, array<string, mixed>> $groups */
            $groups = [];

            foreach ($this->foreignKeys($live, $child) as $foreign) {
                $parent = (string) $foreign['table'];

                if (! isset($preserved[$parent])) {
                    continue;
                }

                $groups[(int) $foreign['id']][(int) $foreign['seq']] = $foreign + ['parent' => $parent];
            }

            foreach ($groups as $group) {
                if (count($group) > 1) {
                    throw new ReaderHalfRefused(sprintf(
                        "The pair's reader half carries a composite foreign key from %s that points "
                        .'at a surface this swap preserves (%s), and the pre-check refuses a linking '
                        .'shape it cannot verify one column at a time. The live database was not touched.',
                        $child,
                        implode(', ', array_map(
                            static fn (array $foreign): string => (string) $foreign['parent'],
                            $group,
                        )),
                    ));
                }

                $this->refuseMissingCandidateLinks($live, $child, (array) $group[0]);
            }
        }
    }

    /**
     * The one column one parent shape {@see self::refuseOrphanedCandidateRows()} grouped down to.
     *
     * @param  array<string, string|int|null>  $foreign  The foreign key list's own row, plus the
     *                                                   parent name. Its `to` is null when the
     *                                                   link names no column, the implicit
     *                                                   primary key shape
     *                                                   {@see self::singlePrimaryKeyColumn()}
     *                                                   resolves.
     *
     * @throws ReaderHalfRefused
     */
    private function refuseMissingCandidateLinks(PDO $live, string $child, array $foreign): void
    {
        $parent = (string) $foreign['parent'];
        $from = (string) $foreign['from'];
        $to = ($foreign['to'] !== null && (string) $foreign['to'] !== '')
            ? (string) $foreign['to']
            : $this->singlePrimaryKeyColumn($live, $parent);

        $linked = sprintf(
            'select count(*) from candidate."%s" c '
            .'left join main."%s" p on p."%s" = c."%s" '
            .'where c."%s" is not null and p."%s" is null',
            $child, $parent, $to, $from, $from, $to,
        );

        $missing = (int) $this->query($live, $linked, "the links between {$child} and the preserved {$parent}")->fetchColumn();

        if ($missing === 0) {
            return;
        }

        $example = (string) $this->query($live, $linked.' limit 1', "one of the unresolvable links between {$child} and {$parent}")->fetchColumn();

        throw new ReaderHalfRefused(sprintf(
            "The pair's reader half carries %d rows in %s pointing at %s this app no longer has "
            ."(%s carries %s and this app's %s.%s does not hold it). The reader half's swap was "
            .'refused before anything was deleted, and the live database was not touched.',
            $missing,
            $child,
            $parent === 'users' ? 'an account' : 'a row',
            $child.'.'.$from,
            $example,
            $parent,
            $to,
        ));
    }

    /**
     * The parent column an implicit foreign key link resolves to: the one primary key
     * column the parent's own schema names. A parent without one is not a shape this
     * verifies, so it refuses rather than letting a link through unchecked.
     */
    private function singlePrimaryKeyColumn(PDO $live, string $table): string
    {
        /** @var list<array<string, mixed>> $columns */
        $columns = $this->query($live, "PRAGMA table_info(\"{$table}\")", "the columns of the preserved {$table}")
            ->fetchAll(PDO::FETCH_ASSOC);

        $keys = array_values(array_filter($columns, static fn (array $column): bool => (int) $column['pk'] > 0));

        if (count($keys) === 1) {
            return (string) $keys[0]['name'];
        }

        throw new ReaderHalfRefused(sprintf(
            "The running app's preserved %s table does not carry the one primary key column a "
            .'link into it resolves to, so the swap refuses to weigh what pointed there '
            .'against nothing. The live database was not touched.',
            $table,
        ));
    }

    /**
     * The foreign keys one table carries, in the list's own shape, rows in the order the
     * list reads them.
     *
     * @return list<array<string, mixed>>
     */
    private function foreignKeys(PDO $live, string $table): array
    {
        /** @var list<array<string, mixed>> */
        return $this->query($live, "PRAGMA foreign_key_list(\"{$table}\")", "the foreign keys of {$table}")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    private function hasSequence(PDO $live, string $schema): bool
    {
        $statement = $this->query(
            $live,
            "select count(*) from {$schema}.sqlite_master where name = 'sqlite_sequence'",
            "the sequence table of {$schema}",
        );
        $value = $statement->fetchColumn();

        return (int) $value > 0;
    }

    /**
     * The order the tables are emptied in: children before their parents, so running with
     * foreign keys ON never violates one it could have avoided.
     *
     * The cycle check lives on the same graph the insert order derives from, so a cycle is
     * refused whichever order was asked first.
     *
     * @param  list<string>  $tables
     * @return list<string>
     *
     * @throws ReaderHalfRefused
     */
    private function deleteOrder(PDO $live, array $tables): array
    {
        return array_reverse($this->topologicalOrder($live, $tables));
    }

    /**
     * The order the tables are refilled in: parents before their children, so every foreign key
     * accepts what it is being asked to accept.
     *
     * @param  list<string>  $tables
     * @return list<string>
     *
     * @throws ReaderHalfRefused
     */
    private function insertOrder(PDO $live, array $tables): array
    {
        return $this->topologicalOrder($live, $tables);
    }

    /**
     * Parents first, read off the live schema's own foreign key list, in Kahn's order.
     *
     * A self reference is not a cycle in this sense: every row of the table is deleted
     * before any of it is refilled, and the refill walks `rowid`, which ordinarily puts the
     * id a same-table row points at before the row pointing at it.
     *
     * @param  list<string>  $tables
     * @return list<string> The tables, parents before children.
     *
     * @throws ReaderHalfRefused on a cycle between two or more tables
     */
    private function topologicalOrder(PDO $live, array $tables): array
    {
        /** @var array<string, int> $parents */
        $parents = array_fill_keys($tables, 0);
        /** @var array<string, list<string>> $children */
        $children = [];

        foreach ($tables as $table) {
            $foreignKeys = $this->query($live, "PRAGMA foreign_key_list(\"{$table}\")", "the foreign keys of {$table}");

            /** @var array<string, string>|false $row */
            $row = $foreignKeys->fetch(PDO::FETCH_ASSOC);

            while ($row !== false) {
                $parentName = (string) ($row['table'] ?? '');

                if (array_key_exists($parentName, $parents) && $parentName !== $table) {
                    $parents[$table]++;
                    $children[$parentName] = [...($children[$parentName] ?? []), $table];
                }

                /** @var array<string, string>|false $row */
                $row = $foreignKeys->fetch(PDO::FETCH_ASSOC);
            }
        }

        $queue = array_values(array_filter($tables, static fn (string $table): bool => $parents[$table] === 0));
        /** @var array<string, true> $seen */
        $seen = [];
        $ordered = [];

        while ($queue !== []) {
            $table = (string) array_shift($queue);

            if (isset($seen[$table])) {
                continue;
            }

            $seen[$table] = true;
            $ordered[] = $table;

            foreach ($children[$table] ?? [] as $child) {
                $parents[$child]--;

                if ($parents[$child] === 0) {
                    $queue[] = $child;
                }
            }
        }

        if (count($ordered) !== count($tables)) {
            $stuck = array_values(array_filter($tables, static fn (string $table): bool => $parents[$table] > 0));

            throw new ReaderHalfRefused(sprintf(
                'The reader half\'s tables form a foreign key cycle between %s, so no order of '
                .'deletes and refills between those tables could keep both kinds of row '
                .'satisfied. The swap was refused with the live database untouched.',
                implode(', ', $stuck),
            ));
        }

        return $ordered;
    }

    /**
     * Connect to the live database outside Laravel's connection pool, on purpose.
     *
     * The app's own connection carries its own transaction state, and the swap needs a
     * plain connection whose `BEGIN IMMEDIATE` either holds the write lock or answers with
     * SQLite's own error codes. The settings here match what the app's own connection runs
     * with: foreign keys on, WAL, a ten second busy timeout.
     *
     * @throws ReaderHalfRefused
     */
    private function connect(string $database): PDO
    {
        try {
            $pdo = new PDO('sqlite:'.$database, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = '.self::BUSY_TIMEOUT_MILLISECONDS);

            return $pdo;
        } catch (PDOException $e) {
            throw new ReaderHalfRefused("The live database refused to open for the swap: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Open the transaction the whole swap lives in, refusing SQLite's lock answer in its
     * own words rather than translating it into a number nobody can act on.
     *
     * @throws ReaderHalfRefused
     */
    private function begin(PDO $live): void
    {
        try {
            $live->exec('BEGIN IMMEDIATE');
        } catch (PDOException $e) {
            [$sqlstate, $code, $message] = $live->errorInfo();
            $code = (int) $code;

            if ($code === self::SQLITE_BUSY || str_contains((string) $message, 'database is locked')) {
                throw new ReaderHalfRefused(sprintf(
                    'The reader half\'s swap could not open its brief write window because '
                    .'something else is writing the live database right now (SQLSTATE %s, '
                    .'SQLite error %d, %s). Once that write is over, the restore run\'s own '
                    .'retry takes the swap again; the live database was not touched.',
                    (string) $sqlstate,
                    $code,
                    (string) $message,
                ), 0, $e);
            }

            throw new ReaderHalfRefused("The reader half's swap could not open its transaction: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * The tables to copy over, main's set read as the schema of record.
     *
     * Only real tables. SQLite's own bookkeeping, `sqlite_%`, is not among them: the
     * sequence table is carried across by its own two statements, which is why a pair that
     * carries an id counter and a live database without one is refused above instead of
     * silently renumbering rows.
     *
     * @return list<string>
     */
    private function tables(PDO $live, string $schema): array
    {
        /** @var list<string> */
        return $this->query(
            $live,
            "select name from {$schema}.sqlite_master where type = 'table' and name not like 'sqlite_%' order by name",
            "the tables of {$schema}",
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @throws ReaderHalfRefused */
    private function count(PDO $live, string $table): int
    {
        $value = (int) $this->query($live, "select count(*) from main.\"{$table}\"", "the count of {$table}")->fetchColumn();

        return $value;
    }

    /** @throws ReaderHalfRefused */
    private function query(PDO $live, string $sql, string $what): PDOStatement
    {
        $statement = $live->query($sql);

        if ($statement === false) {
            throw new ReaderHalfRefused("The live database refused a query, so {$what} could not be read: {$sql}");
        }

        return $statement;
    }

    /** @throws ReaderHalfRefused */
    private function exec(PDO $live, string $sql, string $what): void
    {
        if ($live->exec($sql) === false) {
            throw new ReaderHalfRefused("The live database refused this swap statement, so {$what} did not happen: {$sql}");
        }
    }

    private function detachQuietly(PDO $live): void
    {
        try {
            $live->exec('DETACH DATABASE candidate');
        } catch (Throwable) {
            // The detach after an early refusal can name a candidate that was never attached,
            // and that is the expected shape of those refusals. The connection's destructor
            // releases the file handle; nothing here can put the pair's file at risk.
        }

        $live = null;
    }
}
