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
        ?Translator $translator = null,
    ) {
        $this->translator = $translator ?? new Translator;
    }

    private readonly Translator $translator;

    public static function forSupervisor(Supervisor $supervisor): self
    {
        $control = new SupervisorControl($supervisor);

        return new self(
            $control,
            $control,
            new MigrationRecord($supervisor->dataDirectory(), dirname(__DIR__, 3)),
            $supervisor->databaseFile(),
            translator: $supervisor->translator(),
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
            $say($this->translator->get('common.no_option', ['option' => $options['unknown']]));
            $say($this->translator->get('rollback.unknown_option'));
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
            $say($this->translator->get('rollback.no_snapshot'));
            $say($this->translator->get('rollback.no_snapshot_hint'));

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
            $say($this->translator->get('rollback.cannot_use'));
            $say('  '.$problem);

            return 1;
        }

        $say('');
        $say($this->translator->get('rollback.snapshot', ['path' => $snapshot]));

        if (is_array($record)) {
            $version = $record['from_version'] ?? null;
            $commit = $record['from_commit'] ?? null;

            if (is_string($version) && $version !== '') {
                $say($this->translator->get('rollback.taken_by', [
                    'version' => $version,
                    'commit' => is_string($commit) && $commit !== '' ? ' ('.$commit.')' : '',
                ]));
            }
        }

        $say($this->translator->get('rollback.current_database', ['path' => $this->databaseFile]));

        if (! $options['yes'] && ! $this->confirm($say)) {
            $say('');
            $say($this->translator->get('common.nothing_changed'));

            return 0;
        }

        if (($pid = $this->facts->supervisorPid()) !== null) {
            $say('');
            $say($this->translator->get('rollback.other_launcher', ['pid' => $pid]));
            $say($this->translator->get('rollback.other_launcher_hint'));

            return 1;
        }

        $say('');
        $say($this->translator->get('rollback.stopping'));

        $stopped = $this->control->stop($say);

        $failed = array_filter(
            $stopped,
            static fn (string $outcome): bool => $outcome === 'still running' || $outcome === 'could not be stopped',
        );

        if ($failed !== []) {
            $say($this->translator->get('rollback.did_not_stop'));

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
            $say($this->translator->get('rollback.did_not_finish', ['message' => $e->getMessage()]));
            $say($this->translator->get('rollback.put_back'));

            return 1;
        }

        $problem = $this->databaseProblem($this->databaseFile);

        if ($problem !== null) {
            $say('');
            $say($this->translator->get('rollback.restored_problem', ['problem' => $problem]));
            $say($this->translator->get('rollback.backup_at', ['path' => $backup['target']]));

            return 1;
        }

        $say('');
        $say($this->translator->get('rollback.restored', ['path' => $snapshot]));
        $say($this->translator->get('rollback.backup_at', ['path' => $backup['target']]));
        $say($this->translator->get('rollback.wal_note'));
        $say($this->translator->get('rollback.reinstall'));
        $say($this->translator->get('rollback.forcedowngrade'));

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

            $say($this->translator->get('rollback.could_not_save', ['message' => $e->getMessage()]));

            return null;
        }

        $say($this->translator->get('rollback.saved', ['path' => $backup]));

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
                $say($this->translator->get('rollback.could_not_put_back', [
                    'path' => $original,
                    'message' => $e->getMessage(),
                ]));
                $say($this->translator->get('rollback.still_at', ['path' => $saved]));
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
                return $this->translator->get('rollback.digest_mismatch');
            }
        }

        return null;
    }

    private function databaseProblem(string $path): ?string
    {
        if (! is_file($path) || filesize($path) === 0) {
            return $this->translator->get('rollback.no_file', ['path' => $path]);
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return $this->translator->get('rollback.could_not_read', ['path' => $path]);
        }

        $header = fread($handle, strlen(self::SQLITE_HEADER));
        fclose($handle);

        if ($header !== self::SQLITE_HEADER) {
            return $this->translator->get('rollback.not_sqlite');
        }

        return null;
    }

    private function confirm(callable $say): bool
    {
        $word = $this->translator->get('rollback.confirm_word');

        $say($this->translator->get('rollback.confirm_replace'));
        $say($this->translator->get('rollback.confirm_type', ['word' => $word]));

        $line = fgets(STDIN);

        return is_string($line) && strtolower(trim($line)) === strtolower($word);
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
        $say($this->translator->get('rollback.usage'));
        $say('');
        $say($this->translator->get('rollback.usage.summary'));
        $say($this->translator->get('rollback.usage.recorded'));
        $say($this->translator->get('rollback.usage.saved'));
        $say($this->translator->get('rollback.usage.no_start'));
    }
}
