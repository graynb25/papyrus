<?php

namespace App\Backup;

use Illuminate\Support\Facades\DB;
use PDO;

/**
 * The one statement a reader half exists for, and the guard beside it.
 *
 * `papyrus:snapshot` owned this statement first, and the in app backup needs the same one while
 * both services are still running, so the statement and its transaction guard live here and both
 * callers come through this seam. Two copies of a statement this load bearing is how one copy
 * ends up with the guard and the other without it.
 */
final class ReaderHalfCapture
{
    /**
     * Tables whose rows are live credentials or session state, never copied into a reader half.
     *
     * The tables themselves stay in the copy, because the restore's schema check compares the
     * live database's table list with the pair's and refuses a mismatch. Only their rows are
     * removed.
     */
    private const EMPTIED = ['sessions', 'cache'];

    /**
     * Refuse unless the call sits outside a transaction, before anything is written, because
     * the refusal is the useful part.
     *
     * VACUUM cannot run inside a transaction, and SQLite reports that as a generic SQL error
     * with no mention of transactions. A caller that wraps this in one, which is a normal
     * thing to do to a queued job, would get an opaque failure rather than being told what is
     * wrong. It also makes any test suite that runs its work under `RefreshDatabase`, which
     * begins a transaction, untestable against this seam, so the guard is load bearing in two
     * directions.
     *
     * @throws ReaderHalfRefused
     */
    public function guard(): void
    {
        if (DB::transactionLevel() > 0) {
            throw new ReaderHalfRefused('A snapshot cannot be taken inside a transaction. Run it outside one.');
        }
    }

    /**
     * Copy this install's database to `$target` with VACUUM INTO.
     *
     * The target has to not exist. SQLite refuses to overwrite, which is convenient: it means a
     * same-second second run cannot quietly replace a good snapshot with a worse one.
     *
     * @throws ReaderHalfRefused inside a transaction
     * @throws \Throwable a path that cannot be written, or one that already exists, is SQLite's
     *                    error, and the caller decides what a partial attempt means
     */
    public function capture(string $target): void
    {
        $this->guard();

        // The path goes in as a bound parameter rather than interpolated, even though it is
        // ours. A path containing an apostrophe is legal on every platform this ships to, and
        // interpolating one is a syntax error in a backup command, which is the worst place to
        // have one.
        DB::select('VACUUM INTO ?', [$target]);

        $this->emptyPrivateTables($target);
    }

    /**
     * Remove the rows that would make a copied reader half a credential.
     *
     * `VACUUM INTO` has no table filter, so the copy is made whole and then scrubbed. The
     * second `VACUUM` is the part that matters: `DELETE` moves the removed pages to SQLite's
     * freelist, and a file whose free pages still carry a session payload or a cached token is
     * still a copy of that secret. `secure_delete` covers the window before the rebuild.
     *
     * A missing table has no rows under that name to leak, and a real install always has both.
     *
     * @throws \Throwable a copy that cannot be opened or rebuilt is the caller's to discard
     */
    private function emptyPrivateTables(string $target): void
    {
        $copy = new PDO('sqlite:'.$target, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        try {
            $copy->exec('PRAGMA secure_delete = ON');
            $copy->exec('PRAGMA journal_mode = DELETE');

            foreach (self::EMPTIED as $table) {
                if (! $this->hasTable($copy, $table)) {
                    continue;
                }

                $copy->exec('DELETE FROM "'.$table.'"');
            }

            $copy->exec('VACUUM');
        } finally {
            $copy = null;
        }
    }

    /** Whether the copied database carries the table at all. */
    private function hasTable(PDO $copy, string $table): bool
    {
        $statement = $copy->prepare("select count(*) from sqlite_master where type = 'table' and name = ?");
        $statement->execute([$table]);

        return (int) $statement->fetchColumn() > 0;
    }
}
