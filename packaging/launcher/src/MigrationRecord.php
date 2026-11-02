<?php

namespace Papyrus\Launcher;

use RuntimeException;

/**
 * The record of the snapshot taken before the last migration.
 *
 * The launcher already takes a snapshot before `migrate --force`, but the path only appeared in
 * the snapshot log. A reader who needs to roll back cannot be asked to find a timestamped file in
 * a log they have never opened, and a rollback command that guesses the newest file can restore
 * the wrong one. This writes the path, digest, version and commit in one place the rollback
 * command reads.
 *
 * It is written before the migration runs, so the record exists even when the migration fails.
 */
final class MigrationRecord
{
    public function __construct(
        private readonly string $dataDirectory,
        private readonly string $installRoot,
    ) {}

    public function path(): string
    {
        return $this->dataDirectory.DIRECTORY_SEPARATOR.'last-migration.json';
    }

    /**
     * Record the snapshot that is about to protect the migration.
     *
     * @throws RuntimeException when the snapshot or the install stamp cannot be read, or the
     *                          record cannot be written. The caller must not migrate after this
     *                          throws: a snapshot nobody can name is not a rollback path.
     */
    public function write(string $snapshotPath): string
    {
        if (! is_file($snapshotPath)) {
            throw new RuntimeException('The pre-migration snapshot is not at '.$snapshotPath);
        }

        $digest = @hash_file('sha256', $snapshotPath);

        if (! is_string($digest)) {
            throw new RuntimeException('Could not read the pre-migration snapshot at '.$snapshotPath);
        }

        $stamp = $this->versionStamp();

        $record = [
            'snapshot' => $snapshotPath,
            'snapshot_sha256' => strtolower($digest),
            'from_version' => $stamp['version'] ?? null,
            'from_commit' => $stamp['commit'] ?? null,
            'at' => gmdate('c'),
        ];

        $encoded = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new RuntimeException('Could not encode the pre-migration snapshot record');
        }

        $target = $this->path();

        if (file_put_contents($target, $encoded.PHP_EOL) === false) {
            throw new RuntimeException('Could not write the pre-migration snapshot record at '.$target);
        }

        return $target;
    }

    /**
     * Read the last record, or null when none was written.
     *
     * @return array<mixed>|null
     */
    public function read(): ?array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        if (! is_array($decoded) || ! is_string($decoded['snapshot'] ?? null)) {
            return null;
        }

        return $decoded;
    }

    /**
     * The version and commit stamped into the install that took the snapshot.
     *
     * @return array<string, string>
     */
    private function versionStamp(): array
    {
        $file = $this->installRoot.DIRECTORY_SEPARATOR.'version.txt';
        $stamp = [];

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^([a-z]+)\s+(\S+)$/i', $line, $matches) === 1) {
                $stamp[strtolower($matches[1])] = $matches[2];
            }
        }

        return $stamp;
    }
}
