<?php

namespace Papyrus\Launcher;

use Throwable;

/**
 * The `rollback` command: put the reader database back to the pre-migration snapshot.
 *
 * ## Why this is separate from `restore`
 *
 * `restore` puts back a whole pair, both the manga half and the reader half, and it starts the
 * install again. A migration rollback only needs the reader database, because a migration changes
 * the reader schema and leaves Suwayomi's manga database alone. It also must not start the version
 * that just migrated, or the next start would migrate the restored database forward again.
 *
 * So this command stops, saves the current database, puts the recorded snapshot back, and tells the
 * reader to reinstall the previous version. It never starts the current version.
 *
 * ## Why there is no PDO here
 *
 * The launcher's own interpreter loads no php.ini and therefore has no `pdo_sqlite`; the snapshot
 * command runs the install's PHP for that reason. This command needs no query, only the file. It
 * checks the SQLite header, verifies the digest the migration record wrote, and uses the same
 * verified `FileMover` as `restore`. That keeps the rollback path runnable when the application is
 * the thing that is broken.
 */
final class RollbackCommand
{
    private const SQLITE_HEADER = "SQLite format 3\0";

    public function __construct(
        private readonly InstallControl $control,
        private readonly InstallFacts $facts,
        private readonly MigrationRecord $record,
        private readonly string $databaseFile,
        private readonly FileMover $mover = new LocalFileMover,
    ) {}

    public static function forSupervisor(Supervisor $supervisor): self
    {
        $control = new SupervisorControl($supervisor);

        return new self(
            $control,
            $control,
            new MigrationRecord($supervisor->dataDirectory(), dirname(__DIR__, 3)),
            $supervisor->databaseFile(),
        );
    }

    /**
     * @param  list<string>  $argv  Everything after the command name.
     */
    public function run(array $argv, callable $say): int
    {
        $options = $this->parse($argv);

        if ($options['unknown'] !== null) {
            $say('');
            $say(sprintf('  There is no option %s.', $options['unknown']));
            $say('  Nothing has been changed.');
            $this->usage($say);

            return 1;
        }

        if ($options['help']) {
            $this->usage($say);

            return 0;
        }

        $record = $this->record->read();
        $recordedSnapshot = is_array($record) && is_string($record['snapshot'] ?? null)
            ? $record['snapshot']
            : null;
        $snapshot = $options['snapshot'] ?? $recordedSnapshot;

        if (! is_string($snapshot) || $snapshot === '') {
            $say('');
            $say('  There is no recorded pre-migration snapshot to roll back to.');
            $say('  Pass --snapshot=<path> with a reader-half file from the reader-snapshots folder.');

            return 1;
        }

        /*
         * The recorded digest belongs to the recorded file. An explicit --snapshot may be a
         * different file, and comparing it with that digest would refuse a valid snapshot for
         * being the wrong one.
         */
        $problem = $this->snapshotProblem(
            $snapshot,
            $recordedSnapshot === $snapshot ? $record : null,
        );

        if ($problem !== null) {
            $say('');
            $say('  This snapshot cannot be used. Nothing has been changed.');
            $say('  '.$problem);

            return 1;
        }

        $say('');
        $say('  Snapshot: '.$snapshot);

        if (is_array($record)) {
            $version = $record['from_version'] ?? null;
            $commit = $record['from_commit'] ?? null;

            if (is_string($version) && $version !== '') {
                $say('  Taken by Papyrus '.$version.(is_string($commit) && $commit !== '' ? ' ('.$commit.')' : ''));
            }
        }

        $say('  Current database: '.$this->databaseFile);

        if (! $options['yes'] && ! $this->confirm($say)) {
            $say('');
            $say('  Nothing has been changed.');

            return 0;
        }

        if (($pid = $this->facts->supervisorPid()) !== null) {
            $say('');
            $say(sprintf('  Another launcher (pid %d) is running this install.', $pid));
            $say('  Close it, then run rollback from a terminal.');

            return 1;
        }

        $say('');
        $say('  Stopping Papyrus...');

        $stopped = $this->control->stop($say);

        $failed = array_filter(
            $stopped,
            static fn (string $outcome): bool => $outcome === 'still running' || $outcome === 'could not be stopped',
        );

        if ($failed !== []) {
            $say('  Papyrus did not stop, so the database was not touched.');

            return 1;
        }

        $backup = $this->moveCurrentAside($say);

        if ($backup === null) {
            return 1;
        }

        $temporary = $this->databaseFile.'.rollback-'.bin2hex(random_bytes(4));

        try {
            $this->mover->copy($snapshot, $temporary);
            $this->mover->move($temporary, $this->databaseFile);
        } catch (Throwable $e) {
            @unlink($temporary);
            $this->restoreCurrent($backup, $say);
            $say('');
            $say('  The rollback did not finish: '.$e->getMessage());
            $say('  The database from before the rollback was put back.');

            return 1;
        }

        $problem = $this->databaseProblem($this->databaseFile);

        if ($problem !== null) {
            $say('');
            $say('  The restored database did not open cleanly: '.$problem);
            $say('  The database from before the rollback is at '.$backup['target'].'.');

            return 1;
        }

        $say('');
        $say('  Restored the reader database from '.$snapshot.'.');
        $say('  The database from before the rollback is at '.$backup['target'].'.');
        $say('  Its write-ahead log and shared-memory file, if any, are beside it.');
        $say('  Reinstall the Papyrus version you upgraded from, then start it.');
        $say('  If that installer refuses the downgrade, run it with /FORCEDOWNGRADE.');

        return 0;
    }

    /**
     * Move the live database and its sidecars aside, together.
     *
     * A WAL without its database and a database without its WAL are both states nothing can reason
     * about. The pair is moved as a set, and every moved file is tracked so a failure can put the
     * exact set back.
     *
     * @return array{target: string, files: array<string, string>}|null
     */
    private function moveCurrentAside(callable $say): ?array
    {
        $backup = dirname($this->databaseFile).DIRECTORY_SEPARATOR.'database-before-rollback-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(2)).'.sqlite';

        $files = [$this->databaseFile => $backup];

        foreach (['-wal', '-shm'] as $suffix) {
            if (is_file($this->databaseFile.$suffix)) {
                $files[$this->databaseFile.$suffix] = $backup.$suffix;
            }
        }

        $moved = [];

        try {
            foreach ($files as $from => $to) {
                $this->mover->move($from, $to);
                $moved[$from] = $to;
            }
        } catch (Throwable $e) {
            foreach (array_reverse($moved, true) as $from => $to) {
                try {
                    $this->mover->move($to, $from);
                } catch (Throwable) {
                    // The message below names the backup, which is the copy that matters.
                }
            }

            $say('  Could not save the current database, so nothing was changed: '.$e->getMessage());

            return null;
        }

        $say('  Current database saved to '.$backup.'.');

        return ['target' => $backup, 'files' => $moved];
    }

    /**
     * Put the moved set back when the incoming snapshot could not be installed.
     *
     * @param  array{target: string, files: array<string, string>}  $backup
     */
    private function restoreCurrent(array $backup, callable $say): void
    {
        foreach (array_reverse($backup['files'], true) as $original => $saved) {
            try {
                $this->mover->move($saved, $original);
            } catch (Throwable $e) {
                $say('  Could not put '.$original.' back: '.$e->getMessage());
                $say('  It is still at '.$saved.'.');
            }
        }
    }

    /**
     * Why a snapshot is not usable, or null when it is.
     *
     * The digest is the record's own value, so a file replaced after the snapshot was taken is
     * refused even if it is a valid SQLite database. Without a record the header check is all this
     * can honestly do; the caller passed the file explicitly.
     *
     * @param  array<mixed>|null  $record
     */
    private function snapshotProblem(string $path, ?array $record): ?string
    {
        $problem = $this->databaseProblem($path);

        if ($problem !== null) {
            return $problem;
        }

        if (is_array($record) && is_string($record['snapshot_sha256'] ?? null)) {
            try {
                $digest = $this->mover->digest($path);
            } catch (Throwable $e) {
                return $e->getMessage();
            }

            if (! hash_equals(strtolower($record['snapshot_sha256']), $digest)) {
                return 'The snapshot digest does not match the one recorded before the migration.';
            }
        }

        return null;
    }

    private function databaseProblem(string $path): ?string
    {
        if (! is_file($path) || filesize($path) === 0) {
            return 'There is no file at '.$path.', or it is empty.';
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return 'Could not read '.$path.'.';
        }

        $header = fread($handle, strlen(self::SQLITE_HEADER));
        fclose($handle);

        if ($header !== self::SQLITE_HEADER) {
            return 'The file is not a SQLite database.';
        }

        return null;
    }

    private function confirm(callable $say): bool
    {
        $say('  This replaces the reader database with the snapshot.');
        $say('  Type "yes" to continue: ');

        $line = fgets(STDIN);

        return is_string($line) && trim($line) === 'yes';
    }

    /**
     * @param  list<string>  $argv
     * @return array{snapshot: string|null, yes: bool, help: bool, unknown: string|null}
     */
    private function parse(array $argv): array
    {
        $options = ['snapshot' => null, 'yes' => false, 'help' => false, 'unknown' => null];

        foreach ($argv as $argument) {
            if (str_starts_with($argument, '--snapshot=')) {
                $options['snapshot'] = substr($argument, strlen('--snapshot='));
            } elseif ($argument === '--yes' || $argument === '-y') {
                $options['yes'] = true;
            } elseif ($argument === '--help' || $argument === '-h') {
                $options['help'] = true;
            } else {
                $options['unknown'] = $argument;
            }
        }

        return $options;
    }

    private function usage(callable $say): void
    {
        $say('');
        $say('  Usage: papyrus.php rollback [--snapshot=<path>] [--yes]');
        $say('');
        $say('  Restores the reader database from the snapshot taken before the last migration.');
        $say('  Without --snapshot, the path recorded in last-migration.json is used.');
        $say('  The current database is saved beside it before anything is replaced.');
        $say('  This command does not start Papyrus; reinstall the previous version afterwards.');
    }
}
