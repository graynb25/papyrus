<?php

namespace Tests\Feature;

use RuntimeException;
use Tests\TestCase;

/**
 * The PHP version the manifest declares, and whether the lock can be installed on it.
 *
 * ## Why this is a test rather than a documented rule
 *
 * The manifest said `^8.3` while twenty one locked Symfony packages declared `php >=8.4.1`. A
 * contributor on the documented floor got a refused install and no obvious cause, and nothing in
 * the suite noticed because nothing read the two files against each other. The requirement and the
 * lock are two statements about one support boundary, and a boundary stated twice drifts unless
 * something compares them.
 *
 * The comparison walks every locked package rather than naming the twenty one that were measured.
 * The next dependency to raise its floor is, by definition, the one nobody listed here.
 *
 * ## Why the constraint reader is written out
 *
 * Because composer/semver is not in the vendor tree, and shelling out to composer from a test makes
 * the suite depend on an executable and a network-free resolution it does not control. The reader
 * below understands exactly the shapes composer.lock carries today, and refuses anything else. A
 * shape it does not understand is a failure rather than a pass, because a check that treats an
 * unknown constraint as satisfied cannot report its own blind spot.
 */
class ComposerManifestTest extends TestCase
{
    public function test_the_declared_php_floor_is_a_version_the_lock_can_be_installed_on(): void
    {
        $manifest = $this->decode(base_path('composer.json'));
        $lock = $this->decode(base_path('composer.lock'));

        $declared = $manifest['require']['php'] ?? null;
        $this->assertIsString($declared, 'composer.json names the PHP versions this project supports');

        $pin = $manifest['config']['platform']['php'] ?? null;

        /*
         * Without the pin the floor is the lower bound of the requirement, which for `^8.4` is
         * 8.4.0. The lock needs 8.4.1, so the requirement alone cannot state an honest floor: a
         * caret range always starts at a patch that may not exist in the lock's world. The pin is
         * the concrete version the project promises, so the check below runs against it when it
         * exists and against the range's lower bound when it does not, which is how this test
         * fails on the tree the defect was found in.
         */
        $floor = is_string($pin) ? $pin : $this->lowerBound($declared);

        foreach ($this->lockedPackages($lock) as $package) {
            $constraint = $package['require']['php'] ?? null;

            if (! is_string($constraint)) {
                continue;
            }

            $this->assertTrue(
                $this->satisfies($floor, $constraint),
                $package['name'].' requires php '.$constraint.', which the declared floor '.$floor
                    .' does not satisfy. Raise the requirement and the config.platform pin together,'
                    .' then update the lock, or hold the dependency below the floor.',
            );
        }

        $this->assertIsString(
            $pin,
            'the floor must be pinned to a concrete patch. `'.$declared.'` alone admits versions the'
                .' locked packages refuse, so the requirement cannot state the support boundary by itself.',
        );

        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            $pin,
            'config.platform.php is a full version, because a partial one would be a range again',
        );

        $this->assertSame(
            '^'.$this->minorOf($pin),
            $declared,
            'the requirement names the same major and minor the pin belongs to, or the two statements disagree',
        );
    }

    /**
     * The lock records what it was resolved under, so a manifest change that nobody re-locked is
     * visible here rather than at the next contributor's install.
     */
    public function test_the_lock_records_the_requirement_and_the_platform_it_was_resolved_on(): void
    {
        $manifest = $this->decode(base_path('composer.json'));
        $lock = $this->decode(base_path('composer.lock'));

        $this->assertSame(
            $manifest['require']['php'] ?? null,
            $lock['platform']['php'] ?? null,
            'the lock names the same PHP requirement it was resolved against',
        );

        $this->assertSame(
            $manifest['config']['platform']['php'] ?? null,
            $lock['platform-overrides']['php'] ?? null,
            'a lock resolved on a pinned platform records that platform, so a stale lock cannot hide behind a matching requirement',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            $this->fail($path.' is not readable JSON');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $lock
     * @return list<array<string, mixed>>
     */
    private function lockedPackages(array $lock): array
    {
        $packages = [];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach (($lock[$section] ?? []) as $package) {
                if (is_array($package) && is_string($package['name'] ?? null)) {
                    $packages[] = $package;
                }
            }
        }

        return $packages;
    }

    private function lowerBound(string $constraint): string
    {
        $first = trim(explode('||', explode('|', $constraint)[0])[0]);

        if (preg_match('/^\^(\S+)$/', $first, $matches) === 1) {
            return $this->pad($matches[1]);
        }

        if (preg_match('/^>=?\s*(\S+)$/', $first, $matches) === 1) {
            return $this->pad($matches[1]);
        }

        throw new RuntimeException('this test cannot read the lower bound of the PHP constraint '.$constraint);
    }

    private function minorOf(string $version): string
    {
        $parts = explode('.', $version);

        return $parts[0].'.'.$parts[1];
    }

    private function pad(string $version): string
    {
        $parts = explode('.', $version);

        while (count($parts) < 3) {
            $parts[] = '0';
        }

        return implode('.', array_slice($parts, 0, 3));
    }

    /**
     * Whether a concrete version satisfies one composer constraint, as the lock spells them.
     */
    private function satisfies(string $version, string $constraint): bool
    {
        foreach (preg_split('/\s*\|\|?\s*/', trim($constraint)) ?: [] as $alternative) {
            if ($this->alternativeSatisfied($version, trim($alternative))) {
                return true;
            }
        }

        return false;
    }

    private function alternativeSatisfied(string $version, string $alternative): bool
    {
        // A hyphen range, `8.1 - 8.5`, which composer reads as a lower and an upper bound.
        if (preg_match('/^(\S+)\s+-\s+(\S+)$/', $alternative, $matches) === 1) {
            return version_compare($version, $this->pad($matches[1]), '>=')
                && $this->belowHyphenUpper($version, $matches[2]);
        }

        // A space separated conjunction, `>=7.1 <9.0`.
        foreach (preg_split('/\s+/', $alternative) ?: [] as $token) {
            if (! $this->tokenSatisfied($version, $token)) {
                return false;
            }
        }

        return true;
    }

    private function belowHyphenUpper(string $version, string $upper): bool
    {
        $parts = explode('.', $upper);

        // A partial right side of a hyphen range includes the whole named release.
        if (count($parts) === 1) {
            return version_compare($version, ((int) $parts[0] + 1).'.0.0', '<');
        }

        if (count($parts) === 2) {
            return version_compare($version, $parts[0].'.'.((int) $parts[1] + 1).'.0', '<');
        }

        return version_compare($version, $this->pad($upper), '<=');
    }

    private function tokenSatisfied(string $version, string $token): bool
    {
        if ($token === '*') {
            return true;
        }

        if (preg_match('/^\^(\S+)$/', $token, $matches) === 1) {
            $bound = $this->pad($matches[1]);
            $next = ((int) explode('.', $bound)[0] + 1).'.0.0';

            return version_compare($version, $bound, '>=') && version_compare($version, $next, '<');
        }

        if (preg_match('/^(>=|>|<|<=)\s*(\S+)$/', $token, $matches) === 1) {
            return version_compare($version, $this->pad($matches[2]), $matches[1]);
        }

        throw new RuntimeException(
            'this test cannot read the PHP constraint '.$token.'. Add the shape rather than letting'
                .' an unreadable constraint pass as satisfied.',
        );
    }
}
