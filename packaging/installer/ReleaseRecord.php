<?php

declare(strict_types=1);

/**
 * One shared writer for `releases/<version>.json`.
 *
 * The installer and the portable are built by separate scripts. Before this class each one wrote
 * its own idea of the record, and the installer overwrote the portable's entry. A release record
 * that drops an artifact is worse than no record: it looks complete and cannot be used to audit
 * the download.
 *
 * The record is merged under an exclusive lock. The same file name may be merged again only with
 * the same digest. A different digest, a missing commit, or a record for another version is a
 * refusal, not a warning.
 */
final class ReleaseRecord
{
    /**
     * Merge one artifact into the version's record and return the record path.
     *
     * @param  array<string, mixed>  $manifest
     */
    public static function merge(string $repo, array $manifest): string
    {
        $version = $manifest['version'] ?? null;
        $artifacts = $manifest['artifacts'] ?? null;
        $artifact = is_array($artifacts) ? ($artifacts[0] ?? null) : null;

        if (! is_string($version) || $version === '' || ! is_array($artifact)) {
            throw new RuntimeException('a release record needs a version and one artifact');
        }

        $file = $artifact['file'] ?? null;
        $sha256 = $artifact['sha256'] ?? null;
        $commit = $artifact['commit'] ?? null;

        if (! is_string($file) || $file === '' || ! is_string($sha256) || $sha256 === '' || ! is_string($commit) || $commit === '') {
            throw new RuntimeException('a release artifact needs a file, a sha256 and a commit');
        }

        $releases = $repo.DIRECTORY_SEPARATOR.'releases';

        if (! is_dir($releases) && ! mkdir($releases, 0o777, true) && ! is_dir($releases)) {
            throw new RuntimeException('could not create '.$releases);
        }

        $path = $releases.DIRECTORY_SEPARATOR.$version.'.json';
        $lockPath = $path.'.lock';
        $lock = fopen($lockPath, 'c');

        if ($lock === false) {
            throw new RuntimeException('could not open '.$lockPath);
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('could not lock '.$lockPath);
            }

            if (is_file($path)) {
                $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

                if (! is_array($decoded)) {
                    throw new RuntimeException('the release record is not an object: '.$path);
                }

                $record = $decoded;
            } else {
                $record = [
                    'version' => $version,
                    'released' => is_string($manifest['released'] ?? null) ? $manifest['released'] : gmdate('c'),
                    'commit' => is_string($manifest['commit'] ?? null) ? $manifest['commit'] : $commit,
                    'requires' => is_array($manifest['requires'] ?? null) ? $manifest['requires'] : [],
                    'signed' => (bool) ($manifest['signed'] ?? false),
                    'signature' => is_string($manifest['signature'] ?? null) ? $manifest['signature'] : null,
                    'toolchain' => is_array($manifest['toolchain'] ?? null) ? $manifest['toolchain'] : [],
                    'artifacts' => [],
                ];
            }

            if (($record['version'] ?? null) !== $version) {
                throw new RuntimeException('the release record names another version: '.$path);
            }

            /*
             * The installer is built first and carries the signing state. The portable is a zip and
             * cannot be signed, so merging it must not turn a signed record into an unsigned one.
             */
            if (($manifest['signed'] ?? false) === true) {
                $record['signed'] = true;
                $record['signature'] = is_string($manifest['signature'] ?? null) ? $manifest['signature'] : null;
            }

            $existing = $record['artifacts'] ?? [];

            if (! is_array($existing)) {
                $existing = [];
            }

            foreach ($existing as $candidate) {
                if (is_array($candidate) && ($candidate['file'] ?? null) === $file && ($candidate['sha256'] ?? null) !== $sha256) {
                    throw new RuntimeException('the same artifact name already has a different digest: '.$file);
                }
            }

            $existing = array_values(array_filter(
                $existing,
                static fn (mixed $candidate): bool => ! is_array($candidate) || ($candidate['file'] ?? null) !== $file,
            ));
            $existing[] = $artifact;

            usort($existing, static function (mixed $a, mixed $b): int {
                $left = is_array($a) ? (string) ($a['file'] ?? '') : '';
                $right = is_array($b) ? (string) ($b['file'] ?? '') : '';

                return strcmp($left, $right);
            });

            $record['artifacts'] = $existing;
            $encoded = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if ($encoded === false) {
                throw new RuntimeException('could not encode the release record');
            }

            if (file_put_contents($path, $encoded.PHP_EOL) === false) {
                throw new RuntimeException('could not write '.$path);
            }

            return $path;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockPath);
        }
    }

    /**
     * Build the manifest for a portable artifact from the finished install tree.
     *
     * @param  array{file: string, sha256: string, size: int}  $artifact
     * @return array<string, mixed>
     */
    public static function manifest(string $install, array $artifact): array
    {
        $stampPath = $install.DIRECTORY_SEPARATOR.'version.txt';

        if (! is_file($stampPath)) {
            throw new RuntimeException('the install has no version.txt: '.$stampPath);
        }

        $stamp = [];
        foreach (file($stampPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^([a-z]+)\s+(\S+)$/i', $line, $matches) === 1) {
                $stamp[strtolower($matches[1])] = $matches[2];
            }
        }

        $version = $stamp['version'] ?? null;
        $commit = $stamp['commit'] ?? null;

        if (! is_string($version) || ! is_string($commit)) {
            throw new RuntimeException('version.txt needs a version and a commit');
        }

        $compatibilityPath = $install.DIRECTORY_SEPARATOR.'suwayomi.json';

        if (! is_file($compatibilityPath)) {
            throw new RuntimeException('the install has no suwayomi.json: '.$compatibilityPath);
        }

        $compatibility = json_decode((string) file_get_contents($compatibilityPath), true);

        if (! is_array($compatibility) || ! is_string($compatibility['minimum'] ?? null)) {
            throw new RuntimeException('suwayomi.json needs a minimum version');
        }

        $jvm = null;
        $jreRelease = $install.DIRECTORY_SEPARATOR.'suwayomi'.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'release';

        if (is_file($jreRelease) && preg_match('/JAVA_VERSION="([^"]+)"/', (string) file_get_contents($jreRelease), $matches) === 1) {
            $jvm = '>='.$matches[1];
        }

        return [
            'version' => $version,
            'released' => is_string($stamp['built'] ?? null) ? $stamp['built'] : gmdate('c'),
            'commit' => $commit,
            'requires' => [
                'suwayomi' => '>='.ltrim($compatibility['minimum'], 'vV'),
                'jvm' => $jvm,
            ],
            'signed' => false,
            'signature' => null,
            'artifacts' => [[
                'file' => $artifact['file'],
                'sha256' => $artifact['sha256'],
                'size' => $artifact['size'],
                'commit' => $commit,
            ]],
        ];
    }
}
