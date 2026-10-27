<?php

declare(strict_types=1);

namespace Tests\Feature;

use Papyrus\Runtime\RuntimePins;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../packaging/runtime/RuntimePins.php';

/**
 * The pins the packaged runtime is built from, readable without a build.
 *
 * `build-install.php` asserts the staged binaries, but that failure only appears when somebody runs
 * a build. A pin that regresses below the security floor is cheap to catch here, on the suite every
 * change already runs. This test reads the same class the staging scripts and the build read, so it
 * cannot agree with a comment while disagreeing with what ships.
 */
final class RuntimePinsTest extends TestCase
{
    public function test_frankenphp_pin_carries_the_php_floor(): void
    {
        $this->assertTrue(
            version_compare(RuntimePins::FRANKENPHP_VERSION, '1.13.2', '>='),
            'FrankenPHP must be 1.13.2 or newer because 1.13.2 closes four advisories',
        );

        $this->assertTrue(
            version_compare(RuntimePins::MINIMUM_FRANKENPHP_VERSION, '1.13.2', '>='),
            'the build floor must not fall below the release that closes the advisories',
        );
    }

    public function test_php_floor_is_the_release_that_closes_the_october_rce_fixes(): void
    {
        $this->assertTrue(
            version_compare(RuntimePins::MINIMUM_PHP_VERSION, '8.5.12', '>='),
            'the packaged PHP must be 8.5.12 or newer for CVE-2026-109618 and CVE-2026-109619',
        );
    }

    public function test_jre_pin_is_the_october_cpu_release_and_the_removal_is_documented(): void
    {
        $this->assertTrue(
            version_compare(RuntimePins::JRE_JAVA_VERSION, '25.0.5', '>='),
            'the packaged JRE must carry the October 2026 CPU fixes',
        );

        $names = array_map(
            static fn (array $root): string => $root['name'],
            RuntimePins::DISTRUSTED_ROOTS,
        );

        $this->assertSame(
            ['emsignrootcag1', 'emsignrootcag2', 'emsigneccrootcag3', 'comodoaaaca'],
            $names,
            'the distrust list must name the four roots the plan requires, so a removal is not silently lost',
        );
    }

    public function test_every_archive_pin_has_a_download_url_and_a_full_digest(): void
    {
        foreach ([
            [RuntimePins::FRANKENPHP_ARCHIVE, RuntimePins::FRANKENPHP_SHA256, RuntimePins::frankenphpUrl()],
            [RuntimePins::JRE_ARCHIVE, RuntimePins::JRE_SHA256, RuntimePins::jreUrl()],
        ] as [$archive, $digest, $url]) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $digest, $archive.' needs a SHA-256');
            $this->assertSame($archive, basename((string) parse_url($url, PHP_URL_PATH)), 'the URL must name the pinned archive');
        }

        $this->assertStringContainsString('v'.RuntimePins::FRANKENPHP_VERSION, RuntimePins::frankenphpUrl());
        $this->assertStringContainsString(
            'jdk-'.str_replace('+', '%2B', RuntimePins::JRE_VERSION),
            RuntimePins::jreUrl(),
        );
    }

    public function test_every_distrusted_root_names_a_subject_and_fingerprint(): void
    {
        foreach (RuntimePins::DISTRUSTED_ROOTS as $root) {
            $this->assertNotSame('', $root['subject'], $root['name'].' needs a subject to identify it');
            $this->assertMatchesRegularExpression(
                '/^[0-9A-F]{64}$/',
                $root['fingerprint'],
                $root['name'].' needs a normalised SHA-256 fingerprint',
            );
        }
    }
}
