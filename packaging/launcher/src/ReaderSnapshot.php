<?php

namespace Papyrus\Launcher;

use JsonException;

/**
 * Take the reader's half of a backup: a consistent copy of `database.sqlite` and what it held.
 *
 * ## Why the work happens in a child process
 *
 * Because the launcher's own interpreter cannot do it. It loads no ini, so it has no `pdo_sqlite`,
 * and `VACUUM INTO` is a SQLite operation. Asking the install's own `php.exe` means the work is done
 * by the same interpreter and the same extensions the application itself runs on, rather than by a
 * second arrangement that answers a slightly different question. This is the reason
 * {@see Supervisor::phpExecutable()} is public at all, and `Health` already asks it for the same
 * reason.
 *
 * The alternative, a plain file copy, is the thing this project has already measured being wrong: a
 * copy of a database being written opens and is short a page, and the symptom appears days later as a
 * corrupt database, long after anybody remembers the backup step.
 *
 * ## The refusal that has to happen here and not in the child
 *
 * Measured, against the reader's own install, on this machine:
 *
 *     target plain                     exit 0   {"counts":{"series_states":867,...},"bytes":5111808}
 *     target in a path with a space    exit 0   {"counts":{"series_states":867,...},"bytes":5111808}
 *     source database not present      exit 1   SQLSTATE[HY000]: General error: 1 no such table: series_states
 *     target already present           exit 1   SQLSTATE[HY000]: General error: 1 output file already exists
 *
 * That third line is the trap. **A `sqlite:` DSN creates the database if it is not there.** So a
 * missing source does not fail to open, it opens as an empty database, and the first thing to fail is
 * a query against a table that was never there. The message names a table, not a missing file, and a
 * reader handed that would go looking for `series_states` in an install that does not have one.
 *
 * So the source is checked here, before a child process is started at all. A check the child cannot
 * fail is not a check, and this one had been measured reporting the wrong thing about the wrong
 * file.
 *
 * ## Why the counts come before the copy and from the same connection
 *
 * Because the manifest has to say what the backup held, and the two numbers are the only evidence
 * anyone will ever have of what a backup contained. Taking them from the same connection, before the
 * `VACUUM INTO`, means they describe the database the copy was made from rather than a database
 * read at a different moment.
 *
 * The install is stopped when this runs, so nothing is changing between the two statements. That is
 * why the copy is taken while the app is down rather than through `VACUUM INTO` against a live one:
 * one stop covers both halves, and the counts cannot drift.
 *
 * ## What the copy leaves out
 *
 * The live database holds the app's session rows and its cache, where the Suwayomi bearer and
 * refresh tokens live. A pair that a reader copies must not carry either. The child keeps both
 * tables so the copy still has the live schema, empties their rows, and vacuums the copy so the
 * deleted bytes are not left in free pages.
 *
 * ## Why the target is refused if it already exists
 *
 * Because SQLite refuses to overwrite one, which is convenient, and because the refusal has to arrive
 * as a sentence naming the file rather than as `output file already exists`. The first version let the
 * child produce that message and it was correct and useless.
 */
final class ReaderSnapshot
{
    /**
     * @var (callable(string, string, string, string, string): array{status: int, output: string})|null
     *                                                                                                  Injected by the tests. Null means run the install's own PHP for real.
     */
    private $runner;

    /**
     * @param  (callable(string, string, string, string, string): array{status: int, output: string})|null  $runner
     *                                                                                                               Receives php, ini, script path and target, and returns what the child said.
     */
    public function __construct(
        private readonly FileMover $mover = new LocalFileMover,
        ?callable $runner = null,
    ) {
        $this->runner = $runner ?? [self::class, 'run'];
    }

    /**
     * Copy `$database` to `$target` and report what it held.
     *
     * @throws RestoreRefused The database is not there, the target is, or the copy did not arrive.
     */
    public function capture(
        string $database,
        string $target,
        string $php,
        string $ini,
        ?callable $note = null,
    ): ReaderSnapshotResult {
        $note ??= static fn (string $line): null => null;

        /*
         * Checked here, before any child process exists.
         *
         * The measurement is in the class docstring and it is the whole reason this is not left to
         * SQLite: a `sqlite:` DSN makes the file if it is absent, so a missing database is reported
         * as a missing table several steps later.
         */
        if (! is_file($database)) {
            throw (new RestoreRefused(sprintf(
                'There is no reader database at %s, so the snapshot cannot include the half that holds '
                .'what you have read, your shelves and your settings. Nothing has been written.',
                $database,
            )))->attempted($database);
        }

        if (file_exists($target)) {
            throw (new RestoreRefused(sprintf(
                'There is already a file at %s. This snapshot will not replace it, because a backup '
                .'that quietly overwrote the previous one leaves nothing to go back to.',
                $target,
            )))->attempted($target);
        }

        $script = $this->writeScript();

        try {
            $result = ($this->runner)($php, $ini, $script, $database, $target);
        } finally {
            @unlink($script);
        }

        if ($result['status'] !== 0) {
            @unlink($target);

            throw (new RestoreRefused(sprintf(
                "Taking the reader's database failed, so nothing has been recorded as backed up.\n%s",
                $this->oneLine($result['output']) ?: 'The copy gave no reason.',
            )))->attempted($target);
        }

        /*
         * `is_file` rather than the mover, and after the child rather than before it.
         *
         * PHPStan is right that the two existence checks cannot both be answering about the same
         * state, because a child process ran in between and wrote a file. Asking the mover both times
         * reads as asking one question twice, and only one of them can be right.
         */
        if (! is_file($target)) {
            throw (new RestoreRefused(sprintf(
                'The reader database was copied without complaint but nothing arrived at %s. A backup '
                .'that reports success and produces no file is the worst outcome there is, so it is '
                .'refused rather than reported.',
                $target,
            )))->attempted($target);
        }

        $counts = $this->countsFrom($result['output']);

        return new ReaderSnapshotResult(
            seriesStates: $counts['series_states'],
            chapterStates: $counts['chapter_states'],
            bytes: (int) filesize($target),
            sha256: $this->mover->digest($target),
        );
    }

    /**
     * The counts the child reported, or a refusal that says what was actually said.
     *
     * Checked here rather than trusted, for the reason this file exists: a child process that exits
     * zero having printed something else is a shape that happens, and `{}` would become two zeroes in
     * a manifest, which is a believable number for an empty library rather than an error.
     *
     * @return array{series_states: int, chapter_states: int}
     *
     * @throws RestoreRefused
     */
    private function countsFrom(string $output): array
    {
        try {
            $decoded = json_decode(trim($output), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw (new RestoreRefused(sprintf(
                'The reader database was copied but could not be counted, so the manifest would say it '
                .'held nothing. What came back was: %s',
                $this->oneLine($output) ?: $e->getMessage(),
            )))->attempted('counts');
        }

        $counts = is_array($decoded) ? ($decoded['counts'] ?? null) : null;

        if (
            ! is_array($counts)
            || ! is_int($counts['series_states'] ?? null)
            || ! is_int($counts['chapter_states'] ?? null)
        ) {
            throw (new RestoreRefused(
                'The reader database was copied but the counts came back in a shape this does not '
                .'understand, so the manifest would say it held nothing and be believed.',
            ))->attempted('counts');
        }

        return ['series_states' => $counts['series_states'], 'chapter_states' => $counts['chapter_states']];
    }

    /** The first line of whatever the child said, trimmed. */
    private function oneLine(string $output): string
    {
        foreach (preg_split('#\R#', trim($output)) ?: [] as $line) {
            if (trim($line) !== '') {
                return trim($line);
            }
        }

        return '';
    }

    /**
     * Write the child script to a temporary file and return its path.
     *
     * A file rather than `-r`, because `ProcessFinder` measured every way of passing source through
     * a Windows command line and none of them survived a path containing a space. A path with a space
     * is not a hypothesis here: the install root has one.
     *
     * The caller deletes it, including when the child fails, so a failure does not leave a PHP file
     * behind in the system temporary directory.
     */
    private function writeScript(): string
    {
        $script = tempnam(sys_get_temp_dir(), 'papyrus-readhalf-');

        if ($script === false) {
            throw new RestoreRefused(
                'There is nowhere to write the small program that copies the reader database, so the '
                .'snapshot cannot continue. Nothing has been written.',
            );
        }

        if (@file_put_contents($script, self::CHILD) === false) {
            @unlink($script);

            throw new RestoreRefused(
                'The small program that copies the reader database could not be written, so the '
                .'snapshot cannot continue. Nothing has been written.',
            );
        }

        return $script;
    }

    /**
     * Run the child, the way {@see Health} runs its probe.
     *
     * `2>&1` so a failure's own words reach the caller rather than disappearing into a stream nobody
     * reads, and the exit status is taken from `exec` rather than assumed, which is the one thing
     * `Health` had to learn the hard way: printing a verdict and branching on a different value is
     * how a health command says "something is wrong" and exits zero.
     *
     * Five values for five placeholders, and the first version of this had four values for five
     * placeholders, which PHPStan caught and which would have meant the child was told the target
     * where it expected a database and was told nothing at all where it expected a target. The
     * format string came from `Health`, which passes one argument, and the shape of the command was
     * not the same. Copying a line and changing what it means is how that happens.
     *
     * @return array{status: int, output: string}
     */
    public static function run(string $php, string $ini, string $script, string $database, string $target): array
    {
        $output = [];
        $status = 1;

        @exec(sprintf(
            '%s -c %s %s %s %s 2>&1',
            escapeshellarg($php),
            escapeshellarg($ini),
            escapeshellarg($script),
            escapeshellarg($database),
            escapeshellarg($target),
        ), $output, $status);

        return ['status' => $status, 'output' => implode("\n", $output)];
    }

    /**
     * The child itself.
     *
     * Counts first, then the copy, from one connection. The order is the point: it means the numbers
     * in the manifest describe the database the copy was made from. The install is stopped when this
     * runs, so the two statements cannot see different databases.
     *
     * The target is passed as a bound-looking quoted literal rather than interpolated, because it
     * comes from a command line a reader typed. A path containing an apostrophe is legal on every
     * platform this ships to, and interpolating one is a syntax error inside a backup.
     */
    private const CHILD = <<<'PHP'
<?php

declare(strict_types=1);

$database = $argv[1] ?? '';
$target = $argv[2] ?? '';

try {
    $pdo = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // VACUUM cannot run inside a transaction, and SQLite says so as a generic SQL error that never
    // mentions transactions. Checked here so the refusal names the cause.
    if ($pdo->inTransaction()) {
        throw new RuntimeException('the connection is already inside a transaction, and VACUUM INTO cannot run in one');
    }

    $counts = [];

    foreach (['series_states', 'chapter_states'] as $table) {
        $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    }

    $pdo->exec('VACUUM INTO '.$pdo->quote($target));

    // The copy is made whole by VACUUM INTO, then the two tables that carry live credentials
    // are emptied. VACUUM again is what removes their deleted pages rather than leaving the
    // session payload or cached token in the file's free space.
    $copy = new PDO('sqlite:'.$target, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $copy->exec('PRAGMA secure_delete = ON');
    $copy->exec('PRAGMA journal_mode = DELETE');

    foreach (['sessions', 'cache'] as $table) {
        $exists = (int) $copy->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ".$copy->quote($table)
        )->fetchColumn();

        if ($exists > 0) {
            $copy->exec('DELETE FROM "'.$table.'"');
        }
    }

    $copy->exec('VACUUM');
    $copy = null;

    echo json_encode(['counts' => $counts, 'bytes' => filesize($target)]), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
PHP;
}
