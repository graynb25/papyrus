<?php

declare(strict_types=1);

namespace Papyrus\Runtime;

use RuntimeException;

/**
 * The runtime versions, digests and trust decisions the build requires.
 *
 * These used to live as separate constants in each staging script and again as literals in
 * `build-install.php`, which meant a pin could move in one place and leave the build asserting the
 * old floor. There is one copy here so the staging scripts, the build assertions and the packaging
 * test all read the same numbers.
 */
final class RuntimePins
{
    /**
     * FrankenPHP 1.13.2 carries PHP 8.5.12, which closes CVE-2026-109618 and CVE-2026-109619, and
     * closes four FrankenPHP advisories fixed in the same release.
     */
    public const FRANKENPHP_VERSION = '1.13.2';

    public const FRANKENPHP_ARCHIVE = 'frankenphp-windows-x86_64.zip';

    public const FRANKENPHP_SHA256 = '68964d3d262ed6addaae7777e5b0dec53690551a366a5c7981528e585d8f66cc';

    /**
     * The PHP release the packaged binary must report. A FrankenPHP pin carries this without
     * saying so in its version number, so the build measures the binary rather than assuming it.
     */
    public const MINIMUM_PHP_VERSION = '8.5.12';

    public const MINIMUM_FRANKENPHP_VERSION = '1.13.2';

    /**
     * Temurin 25.0.5+8 carries the October 2026 CPU fixes. The `release` file and `java -version`
     * both report the shorter `25.0.5`, which is what the build asserts.
     */
    public const JRE_VERSION = '25.0.5+8';

    public const JRE_JAVA_VERSION = '25.0.5';

    public const JRE_ARCHIVE = 'OpenJDK25U-jre_x64_windows_hotspot_25.0.5_8.zip';

    public const JRE_SHA256 = 'da78d54a2fcdbba1e92d5e3f07dd30236f96297891c4a21a781c3f38f5c0beae';

    /**
     * Suwayomi Server 2.4.2366, the release the suite was run against.
     *
     * The digest is the one GitHub publishes for the release asset, not a measurement of the
     * local copy, so a substituted jar with the right name is refused before the layout is
     * cleared. `packaging/suwayomi.json` records the same version; a test compares the two.
     */
    public const SUWAYOMI_VERSION = '2.4.2366';

    public const SUWAYOMI_JAR = 'Suwayomi-Server-v2.4.2366.jar';

    public const SUWAYOMI_SHA256 = 'af9feb20af9d7ebe9e30769e6c6ebc7fc7ab1c447388d42e0280b1da5fe07bfd';

    /**
     * The curl.se CA extract dated 2026-10-20.
     *
     * A dated file is the pin. `cacert.pem` is a moving URL, so two builds of one commit could
     * otherwise ship different trust stores without either build noticing.
     */
    public const CACERT_DATE = '2026-10-20';

    public const CACERT_URL = 'https://curl.se/ca/cacert-2026-10-20.pem';

    public const CACERT_SHA256 = '796f1389bfea13c0ed78f663b765baaff54a508e5483786bd6ece73c5e3e3cbe';

    /**
     * The four roots the October 2026 eMudhra distrust removes, by the Oracle alias that names
     * them, with the subject and fingerprint that identify the certificate when the alias does not
     * exist in a Temurin keystore.
     *
     * Adoptium publishes no 25.0.5.0.2 build, so the JRE is staged from 25.0.5+8 and these roots
     * are removed after extraction. Fingerprints are SHA-256 with no separators, uppercase, because
     * that is the form `openssl_x509_fingerprint` returns after normalisation.
     *
     * @var list<array{name: string, subject: string, fingerprint: string}>
     */
    public const DISTRUSTED_ROOTS = [
        [
            'name' => 'emsignrootcag1',
            'subject' => 'emSign Root CA - G1',
            'fingerprint' => '40F6AF0346A99AA1CD1D555A4E9CCE62C7F9634603EE406615833DC8C8D00367',
        ],
        [
            'name' => 'emsignrootcag2',
            'subject' => 'emSign Root CA - G2',
            'fingerprint' => '1AA0C2709E831BD6E3B5129A00BA41F7EEEF020872F1E6504BF0F6C3F24F3AF3',
        ],
        [
            'name' => 'emsigneccrootcag3',
            'subject' => 'emSign ECC Root CA - G3',
            'fingerprint' => '86A1ECBA089C4A8D3BBE2734C612BA341D813E043CF9E8A862CD5C57A36BBE6B',
        ],
        [
            'name' => 'comodoaaaca',
            'subject' => 'AAA Certificate Services',
            'fingerprint' => 'D7A7A0FB5D7E2731D771E9484EBCD5F71D5F0C3E0A2948782BC83EE0EA699EF4',
        ],
    ];

    public static function frankenphpUrl(): string
    {
        return 'https://github.com/php/frankenphp/releases/download/'
            .'v'.self::FRANKENPHP_VERSION.'/'.self::FRANKENPHP_ARCHIVE;
    }

    public static function jreUrl(): string
    {
        return 'https://github.com/adoptium/temurin25-binaries/releases/download/'
            .'jdk-'.str_replace('+', '%2B', self::JRE_VERSION).'/'.self::JRE_ARCHIVE;
    }

    public static function suwayomiUrl(): string
    {
        return 'https://github.com/Suwayomi/Suwayomi-Server/releases/download/'
            .'v'.self::SUWAYOMI_VERSION.'/'.self::SUWAYOMI_JAR;
    }

    /**
     * Refuse a jar that is not the pinned Suwayomi release.
     *
     * The digest is checked before the name, because a substituted file with the right name is the
     * case the name check alone cannot see. The name check then catches a correctly built jar for a
     * different release, whose digest would already fail but whose message should say why.
     *
     * @throws RuntimeException
     */
    public static function assertSuwayomiJar(string $path): void
    {
        $digest = @hash_file('sha256', $path);

        if ($digest === false || ! hash_equals(self::SUWAYOMI_SHA256, $digest)) {
            throw new RuntimeException(
                'the Suwayomi jar does not match the pinned digest: '.basename($path).PHP_EOL
                .'  expected '.self::SUWAYOMI_SHA256.PHP_EOL
                .'  got '.($digest === false ? 'unreadable' : $digest)
            );
        }

        if (! str_contains(basename($path), self::SUWAYOMI_VERSION)) {
            throw new RuntimeException(
                'the Suwayomi jar name does not carry the pinned version '
                .self::SUWAYOMI_VERSION.': '.basename($path)
            );
        }
    }

    /**
     * Refuse a CA bundle that is not the pinned curl.se extract.
     *
     * @throws RuntimeException
     */
    public static function assertCaBundle(string $path): void
    {
        $digest = @hash_file('sha256', $path);

        if ($digest === false || ! hash_equals(self::CACERT_SHA256, $digest)) {
            throw new RuntimeException(
                'the CA bundle does not match the pinned digest: '.basename($path).PHP_EOL
                .'  expected '.self::CACERT_SHA256.PHP_EOL
                .'  got '.($digest === false ? 'unreadable' : $digest)
            );
        }
    }

    /**
     * Read `JAVA_VERSION` from a staged JRE's `release` file.
     *
     * Returns null rather than guessing when the file is absent, unreadable or shaped differently,
     * so the staging script replaces an unknown runtime instead of trusting one it could not read.
     */
    public static function javaVersionFromRelease(string $releaseFile): ?string
    {
        if (! is_file($releaseFile)) {
            return null;
        }

        $contents = @file_get_contents($releaseFile);

        if ($contents === false) {
            return null;
        }

        if (preg_match('/^JAVA_VERSION="([^"]+)"/m', $contents, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
