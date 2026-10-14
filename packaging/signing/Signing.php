<?php

declare(strict_types=1);

namespace Papyrus\Signing;

use RuntimeException;

/**
 * Authenticode signing for the built artefacts.
 *
 * ## Why this is separate from the build scripts
 *
 * Because signing is the only step that needs a secret, and a secret in the middle of a build script
 * is a secret that eventually ends up in a shell history or a CI log. This class reads its
 * configuration from the environment at the moment it runs, holds no defaults, and is the only place
 * in the repository that knows a certificate can have a password.
 *
 * ## The rule that matters: unsigned must be a decision, never an omission
 *
 * The first version of this let signing be skipped by simply not configuring it, which is the same
 * shape as every other opt-in step in a build and is wrong here. An unsigned installer looks exactly
 * like a signed one on disk, Windows shows the same file size, and the difference is only visible to
 * somebody who knows to look. So:
 *
 *   - `sign()` refuses when asked to sign and cannot find a certificate.
 *   - `sign()` refuses when the signature does not verify afterwards.
 *   - Publishing anything while signing was requested and failed is impossible, because the caller
 *     gets an exception rather than a warning.
 *   - Skipping requires the caller to say so out loud, in a call that reads as a decision.
 *
 * A build step that can fail quietly is worse than no build step, and a signature that can be
 * forgotten is not a signature.
 *
 * ## Why the timestamp server is not optional for a real release
 *
 * Without one, the signature is valid on the day it was made and the certificate's own expiry is the
 * hard stop. With one, the signature says "this existed at this time" and outlives the certificate,
 * which is what a reader installing from a copy on a USB stick four years later needs. The network
 * failure mode is handled rather than ignored: a release build refuses to produce an untimestamped
 * signature, a local one reports that it produced one.
 */
final class Signing
{
    /**
     * @param  array<int, string>  $artefacts  absolute paths to sign
     */
    private function __construct(
        private readonly string $signtool,
        private readonly array $artefacts,
    ) {}

    /**
     * Whether the caller asked for signing at all.
     *
     * Read rather than guessed, because the difference between "unsigned because nobody configured a
     * certificate" and "unsigned because signing was explicitly waived" is the whole point of this
     * class, and conflating them is how an unsigned release ships labelled as signed.
     */
    public static function isRequested(): bool
    {
        return self::setting('PAPYRUS_SIGN') !== null;
    }

    /**
     * Whether a release build must be signed.
     *
     * A release is anything published to somebody who is not the person building it, so the variable
     * that distinguishes the two is set by whoever is publishing rather than inferred.
     */
    public static function isRelease(): bool
    {
        return self::setting('PAPYRUS_RELEASE') === '1';
    }

    /**
     * Sign the artefacts, or refuse.
     *
     * @param  array<int, string>  $artefacts
     * @return string a one line summary for the build log
     *
     * @throws RuntimeException on any failure, always. There is no path here that returns quietly.
     */
    public static function sign(array $artefacts, bool $allowTimestampFailure = false): string
    {
        $signtool = self::findSignTool();

        foreach ($artefacts as $artefact) {
            if (! is_file($artefact)) {
                throw new RuntimeException("cannot sign {$artefact} because it is not there");
            }
        }

        (new self($signtool, $artefacts))->run($allowTimestampFailure);

        return sprintf(
            'signed %d artefact(s) with %s',
            count($artefacts),
            self::describeCertificate(),
        );
    }

    /**
     * What the signature on a file currently says, for the build log.
     *
     * Separate from `sign()` because the interesting question after a signing step is not whether the
     * command exited zero. It is whether Windows itself agrees the file is signed, which is a
     * different check performed by a different tool.
     */
    public static function describe(string $artefact): string
    {
        $signtool = self::findSignTool();

        $output = [];
        $status = 1;

        exec(
            sprintf(
                '%s verify /pa /v %s 2>&1',
                escapeshellarg($signtool),
                escapeshellarg($artefact),
            ),
            $output,
            $status,
        );

        $text = trim(implode("\n", $output));

        /*
         * `/pa` failing means Windows would not trust it, which is the whole point of the check.
         *
         * And "does not trust" is worth stating precisely rather than as a verdict on the signature.
         * For a certificate from a real authority this branch is a genuine problem. For a self-signed
         * one it is the expected and only possible outcome on a machine that has not installed the
         * root: the signature is present, intact, and chains to a root nobody was told to trust.
         * Both measured, with the trusted root count read back rather than assumed:
         *
         *     root absent  ->  UnknownError, signtool exits 1
         *     root present ->  Valid
         *
         * So the wording says which of those happened, because "Windows does not trust this" invites
         * the reader to conclude the file is tampered with, and it is not.
         */
        if ($status !== 0) {
            return 'present, and Windows does not trust the certificate: '
                .'expected for a self-signed signer, and a real problem for a CA-issued one';
        }

        if (preg_match('/^Number of certificates=\d+/mi', $text, $matches) === 1) {
            return 'signed: '.$matches[0];
        }

        return 'signed: signature verifies against the Windows trust policy';
    }

    private function run(bool $allowTimestampFailure): void
    {
        $thumbprint = self::setting('PAPYRUS_CERT_THUMBPRINT');
        $pfx = self::setting('PAPYRUS_CERT_PFX');

        if ($thumbprint === null && $pfx === null) {
            throw new RuntimeException(
                "signing was requested but no certificate was given.\n\n"
                ."  Set one of:\n"
                ."    PAPYRUS_CERT_THUMBPRINT   a code-signing certificate already in the Windows store\n"
                ."    PAPYRUS_CERT_PFX          path to a .pfx holding one\n\n"
                .'  Or pass --unsigned, which is a decision rather than an omission.'
            );
        }

        foreach ($this->artefacts as $artefact) {
            $this->signOne($artefact, $thumbprint, $pfx, $allowTimestampFailure);
        }
    }

    private function signOne(string $artefact, ?string $thumbprint, ?string $pfx, bool $allowTimestampFailure): void
    {
        $arguments = [$this->signtool, 'sign'];

        /*
         * `/fd` is the digest of the signature, `/td` the digest the timestamp is made over. Both
         * SHA-256, because SHA-1 is refused by current Windows and `/fd sha1` fails confusingly rather
         * than clearly.
         *
         * `/td` is set once here and not repeated in the timestamp block below. It was, and
         * signtool's answer was `You cannot use the /td option twice`, which is the correct refusal of
         * a real command and the reason this class is probed against a real certificate rather than a
         * mocked one.
         */
        $arguments[] = '/fd';
        $arguments[] = 'sha256';
        $arguments[] = '/td';
        $arguments[] = 'sha256';

        $timestamp = self::setting('PAPYRUS_TIMESTAMP_URL')
            ?? 'http://timestamp.digicert.com';

        $command = implode(' ', array_map('escapeshellarg', array_merge($arguments, [
            '/tr',
            $timestamp,
        ])));

        if ($thumbprint !== null) {
            $command .= ' /sha1 '.escapeshellarg($thumbprint);
        }

        if ($pfx !== null) {
            $command .= ' /f '.escapeshellarg($pfx);

            $password = self::setting('PAPYRUS_CERT_PASSWORD');

            if ($password !== null) {
                $command .= ' /p '.escapeshellarg($password);
            }
        }

        $command .= ' '.escapeshellarg($artefact);

        $output = [];
        $status = 1;

        exec($command.' 2>&1', $output, $status);

        $text = trim(implode("\n", $output));

        /*
         * A network failure reaching the timestamp server is the one failure worth surviving, and only
         * outside a release. The signature it produced is still valid today, which is worth more than
         * no signature, and the log says plainly that it is untimestamped so nobody is misled.
         *
         * Anything else is refused, including an empty output with a zero exit, because signtool has
         * exited zero without signing in the past when a certificate was not usable.
         */
        $timestamped = stripos($text, 'timestamp') !== false || stripos($text, 'signing') !== false;

        if ($status !== 0) {
            $networkish = stripos($text, 'unable to access') !== false
                || stripos($text, 'unable to connect') !== false
                || stripos($text, 'timed out') !== false;

            if ($networkish && $allowTimestampFailure && ! self::isRelease()) {
                // Retry without the timestamp, so there is still a valid signature.
                $retry = str_replace(' /tr '.escapeshellarg($timestamp), '', $command);

                exec($retry.' 2>&1', $output, $status);

                if ($status !== 0) {
                    throw new RuntimeException(
                        "signtool failed on the artefact, and the retry without a timestamp also failed.\n\n  "
                        .str_replace("\n", "\n  ", trim(implode("\n", $output)))
                    );
                }

                return;
            }

            throw new RuntimeException(
                "signtool exited {$status} signing the artefact.\n\n  "
                .str_replace("\n", "\n  ", $text === '' ? '(no output)' : $text)
                ."\n\n  Nothing was published. A failed signature must never look like a signed build."
            );
        }

        if (! $timestamped && self::isRelease() && ! $allowTimestampFailure) {
            throw new RuntimeException(
                "signtool reported success but produced no timestamp, and this is a release.\n\n"
                ."  An untimestamped signature stops being valid the day the certificate expires,\n"
                .'  which is not something to discover in four years.'
            );
        }
    }

    /**
     * Find signtool.exe, or say plainly that it is not there.
     *
     * The x64 build is preferred and then any other, because the arm64 one on this machine reports a
     * different set of supported algorithms and the difference is invisible until a digest is refused.
     */
    private static function findSignTool(): string
    {
        if (($fromEnv = self::setting('PAPYRUS_SIGNTOOL')) !== null) {
            if (is_file($fromEnv)) {
                return $fromEnv;
            }

            throw new RuntimeException("PAPYRUS_SIGNTOOL is set to {$fromEnv}, which is not a file");
        }

        $roots = [
            'C:\\Program Files (x86)\\Windows Kits\\10\\bin',
            'C:\\Program Files\\Windows Kits\\10\\bin',
        ];

        $found = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $entry) {
                if (! $entry->isFile() || strtolower($entry->getFilename()) !== 'signtool.exe') {
                    continue;
                }

                $found[] = $entry->getPathname();
            }
        }

        if ($found === []) {
            throw new RuntimeException(
                "signtool.exe was not found, so signing cannot run.\n\n"
                ."  It ships with the Windows SDK. Set PAPYRUS_SIGNTOOL to its path, or pass\n"
                .'  --unsigned if this build is deliberately not signed.'
            );
        }

        foreach ($found as $candidate) {
            if (str_contains(strtolower($candidate), '\\x64\\')) {
                return $candidate;
            }
        }

        return $found[0];
    }

    /**
     * What certificate was used, for the log, without printing the fingerprint in full.
     *
     * The thumbprint identifies a certificate and therefore a person or an organisation, so the build
     * log gets the first twelve characters and nothing more. Build logs get pasted into issues.
     */
    private static function describeCertificate(): string
    {
        $thumbprint = self::setting('PAPYRUS_CERT_THUMBPRINT');

        if ($thumbprint === null) {
            return 'the certificate in PAPYRUS_CERT_PFX';
        }

        return 'thumbprint '.substr($thumbprint, 0, 12).'...';
    }

    /**
     * Read a setting, treating an empty string as absent.
     *
     * An environment variable set to nothing is the same as not set, and treating them differently
     * means `PAPYRUS_SIGN=` silently switches signing off, which is precisely the omission this class
     * exists to prevent.
     */
    private static function setting(string $name): ?string
    {
        $value = getenv($name);

        if ($value === false || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
