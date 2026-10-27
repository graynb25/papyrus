<?php

declare(strict_types=1);

namespace Papyrus\Runtime;

use RuntimeException;

/**
 * Read and edit the JRE's `cacerts` store with the JRE's own keytool.
 *
 * Temurin names keystore entries after the certificate's distinguished name and stores them in a
 * PKCS12 file whose aliases are case sensitive. `keytool -list` displays them lowercased and appends
 * `[jdk]`, so an alias copied from that output does not necessarily name an entry. This class
 * identifies a certificate by its SHA-256 fingerprint, then tries the alias spellings keytool is
 * known to accept, and re-reads the store afterwards rather than trusting the delete exit code.
 */
final class TrustStore
{
    public const PASSWORD = 'changeit';

    /**
     * Every trusted certificate in a keystore, identified by alias, subject common name and
     * normalised SHA-256 fingerprint.
     *
     * @return list<array{alias: string, subject: string, fingerprint: string}>
     */
    public static function entries(string $keytool, string $keystore): array
    {
        if (! is_file($keytool)) {
            throw new RuntimeException('no keytool at '.$keytool.', so the trust store cannot be read');
        }

        if (! is_file($keystore)) {
            throw new RuntimeException('no keystore at '.$keystore.', so the trust store cannot be read');
        }

        $output = self::run([
            $keytool,
            '-J-Duser.language=en',
            '-J-Duser.country=US',
            '-list',
            '-rfc',
            '-keystore',
            $keystore,
            '-storepass',
            self::PASSWORD,
        ], 'list '.$keystore);

        $entries = [];

        foreach (preg_split('/\*{10,}\R/', $output) ?: [] as $block) {
            if (preg_match('/^Alias name:\s*(.+?)\s*$/m', $block, $alias) !== 1) {
                continue;
            }

            if (preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $block, $pem) !== 1) {
                continue;
            }

            $parsed = openssl_x509_parse($pem[0]);

            if (! is_array($parsed)) {
                throw new RuntimeException('keytool listed an entry that openssl could not parse');
            }

            $subject = $parsed['subject'] ?? [];
            $commonName = is_array($subject) ? ($subject['CN'] ?? '') : '';

            $fingerprint = openssl_x509_fingerprint($pem[0], 'sha256');

            if ($fingerprint === false) {
                throw new RuntimeException('openssl could not fingerprint an entry in '.$keystore);
            }

            $entries[] = [
                'alias' => trim($alias[1]),
                'subject' => is_string($commonName) ? $commonName : '',
                'fingerprint' => self::normalise($fingerprint),
            ];
        }

        if ($entries === []) {
            throw new RuntimeException('keytool listed no certificates from '.$keystore);
        }

        return $entries;
    }

    /**
     * Which of the distrust-listed roots the store holds, and any entry that carries one of their
     * subjects but not the published certificate.
     *
     * A same-subject entry with a different fingerprint is refused rather than skipped. It is the
     * shape a silently reissued or substituted root would take, and treating it as absent would let
     * the build report a clean trust store it had not recognised.
     *
     * @param  list<array{alias: string, subject: string, fingerprint: string}>  $entries
     * @return array{present: array<string, string>, unexpected: list<string>}
     */
    public static function matches(array $entries): array
    {
        $present = [];
        $unexpected = [];

        foreach (RuntimePins::DISTRUSTED_ROOTS as $root) {
            $wanted = self::normalise($root['fingerprint']);
            $matched = false;

            foreach ($entries as $entry) {
                if ($entry['fingerprint'] === $wanted) {
                    $present[$root['name']] = $entry['alias'];
                    $matched = true;

                    break;
                }
            }

            if ($matched) {
                continue;
            }

            foreach ($entries as $entry) {
                if (strcasecmp($entry['subject'], $root['subject']) === 0) {
                    $unexpected[] = sprintf(
                        '%s is present as %s with fingerprint %s, not the published %s',
                        $root['name'],
                        $entry['alias'],
                        self::display($entry['fingerprint']),
                        self::display($wanted),
                    );
                }
            }
        }

        return ['present' => $present, 'unexpected' => $unexpected];
    }

    /**
     * Remove every distrust-listed root the store holds.
     *
     * @return array<string, string> root name to `removed as <alias>` or `absent`
     */
    public static function removeDistrusted(string $keytool, string $keystore): array
    {
        $before = self::matches(self::entries($keytool, $keystore));

        if ($before['unexpected'] !== []) {
            throw new RuntimeException(implode("\n  ", $before['unexpected']));
        }

        $outcome = [];

        foreach (RuntimePins::DISTRUSTED_ROOTS as $root) {
            $alias = $before['present'][$root['name']] ?? null;

            if ($alias === null) {
                $outcome[$root['name']] = 'absent';

                continue;
            }

            $removed = self::removeAlias($keytool, $keystore, $alias);
            $outcome[$root['name']] = 'removed as '.$removed;
        }

        $after = self::matches(self::entries($keytool, $keystore));

        if ($after['present'] !== []) {
            throw new RuntimeException(
                'still present after removal: '.implode(', ', array_keys($after['present']))
            );
        }

        return $outcome;
    }

    /**
     * The distrust-listed roots a store still holds, by Oracle alias.
     *
     * @return list<string>
     */
    public static function present(string $keytool, string $keystore): array
    {
        $matches = self::matches(self::entries($keytool, $keystore));

        if ($matches['unexpected'] !== []) {
            throw new RuntimeException(implode("\n  ", $matches['unexpected']));
        }

        return array_keys($matches['present']);
    }

    /**
     * Delete one alias, trying the spellings keytool accepts for a Temurin entry.
     *
     * The first spelling is the one keytool displayed, for a store whose aliases are already
     * lowercase. The uppercase and no-suffix forms cover the PKCS12 store Adoptium ships.
     */
    private static function removeAlias(string $keytool, string $keystore, string $alias): string
    {
        $withoutMarker = (string) preg_replace('/\s+\[jdk\]$/i', '', $alias);

        $candidates = array_values(array_unique([
            $alias,
            strtoupper($alias),
            $withoutMarker,
            strtoupper($withoutMarker),
        ]));

        $lastFailure = '';

        foreach ($candidates as $candidate) {
            try {
                self::run([
                    $keytool,
                    '-J-Duser.language=en',
                    '-delete',
                    '-alias',
                    $candidate,
                    '-keystore',
                    $keystore,
                    '-storepass',
                    self::PASSWORD,
                ], 'delete '.$candidate);

                return $candidate;
            } catch (RuntimeException $e) {
                $lastFailure = $e->getMessage();
            }
        }

        throw new RuntimeException('could not delete '.$alias.' from '.$keystore.': '.$lastFailure);
    }

    /**
     * @param  list<string>  $command
     */
    private static function run(array $command, string $action): string
    {
        $output = [];
        $status = 1;

        exec(implode(' ', array_map('escapeshellarg', $command)).' 2>&1', $output, $status);

        $text = trim(implode("\n", $output));

        if ($status !== 0) {
            throw new RuntimeException('keytool could not '.$action.': '.$text);
        }

        return $text;
    }

    private static function normalise(string $fingerprint): string
    {
        return strtoupper(str_replace(':', '', $fingerprint));
    }

    private static function display(string $fingerprint): string
    {
        return implode(':', str_split(self::normalise($fingerprint), 2));
    }
}
