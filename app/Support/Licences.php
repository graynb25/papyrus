<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * The third party code this install is made of, read from the files that already say so.
 *
 * ## Why this is generated rather than written
 *
 * Because a licence page written by hand is a licence page that is wrong. It is wrong the moment a
 * dependency moves, it is wrong for every package nobody remembered to list, and it is wrong in the
 * direction that matters: it claims coverage it does not have. A reader who opens it to check what
 * they have been given is asking precisely that question, and an incomplete answer is worse than none.
 *
 * So names, versions and licences come from `composer.lock` and `package-lock.json`, which are the
 * same two files the build installs from and therefore cannot disagree with what is shipped.
 *
 * ## Why the two bundled pieces are here too
 *
 * Because they are most of the install and appear in neither file. The Suwayomi server is a 174MB
 * jar and the Java runtime beside it is 180MB, together more than half of everything a reader
 * installs, and a licence page listing only PHP and JavaScript would omit the largest third party
 * code in the product.
 *
 * They are read from the packaged manifests rather than hardcoded, for the same reason as everything
 * else: the build asserts the jar against a digest and `packaging/suwayomi.json` records which
 * release is expected, so the licence claim should come from the thing that is verified rather than
 * from a constant written beside it.
 *
 * ## What it found, which is the reason the page exists
 *
 * 448 packages. Exactly one of them, `livewire/flux`, names itself proprietary, and it is the
 * component library every page in this application is built from. Twenty two record no licence at
 * all, which is a different problem and is reported separately rather than being folded into the
 * first. The first version of this reader folded them together and called all twenty three closed,
 * which was a false accusation about twenty two packages that ship nothing on this platform.
 */
final class Licences
{
    /**
     * Every PHP package, dev dependencies included.
     *
     * Dev dependencies are listed because the question being asked is "what am I running", and the
     * honest answer includes the code that built it even where that code does not execute at runtime.
     * Filtering them out would make the page shorter and less true.
     *
     * @return list<LicensedPackage>
     */
    public function composerPackages(): array
    {
        return $this->fromComposerLock(base_path('composer.lock'));
    }

    /**
     * @return list<LicensedPackage>
     */
    public function npmPackages(): array
    {
        $lock = base_path('package-lock.json');

        if (! File::exists($lock)) {
            return [];
        }

        $decoded = json_decode((string) File::get($lock), true);

        if (! is_array($decoded)) {
            return [];
        }

        /*
         * `packages` rather than `dependencies`, and the name comes from the path.
         *
         * Two separate traps, both of which produce an empty list rather than a wrong one, which is
         * the worst shape a check like this can have:
         *
         * The older `dependencies` key is a tree of the hoisted layout, so one package appears once
         * per place it was hoisted to and counting it gives a number that is not the number of
         * packages. `packages` is the flat keyed-by-path list where each entry appears once.
         *
         * And lockfileVersion 3 records no `name` field on a dependency at all: the name is the path
         * after the last `node_modules/`. Filtering on `name` therefore rejected all 307 entries and
         * the first version of this reported zero JavaScript packages, which reads exactly like a
         * filter doing its job.
         */
        $found = [];

        foreach (($decoded['packages'] ?? []) as $path => $package) {
            if (! is_array($package) || $path === '') {
                continue;
            }

            $name = $package['name'] ?? $this->nameFromPath((string) $path);

            if ($name === '') {
                continue;
            }

            // npm does not record a licence field in every lockfile version, and inventing one is
            // worse than saying so.
            $licence = $package['license'] ?? 'not recorded in the lock file';

            if (is_array($licence)) {
                $licence = implode(' or ', $licence);
            }

            $found[] = LicensedPackage::make(
                $name,
                (string) ($package['version'] ?? 'unknown'),
                (string) $licence,
                (string) $path,
            );
        }

        return $this->sorted($found);
    }

    /**
     * The package name inside an npm lock file path: the segment after the last `node_modules/`.
     *
     * The last one, not the first. A package depending on a second copy of itself is recorded as
     * `node_modules/a/node_modules/b`, and taking the first segment would list it twice as `a`.
     */
    private function nameFromPath(string $path): string
    {
        $segments = explode('node_modules/', $path);

        return (string) end($segments);
    }

    /**
     * The large packaged pieces, which no lockfile mentions.
     *
     * @return list<LicensedPackage>
     */
    public function bundled(): array
    {
        $expected = null;
        $declared = base_path('packaging/suwayomi.json');

        if (File::exists($declared)) {
            $decoded = json_decode((string) File::get($declared), true);
            $expected = is_array($decoded) ? ($decoded['tested'] ?? null) : null;
        }

        return [
            LicensedPackage::make(
                'Suwayomi Server',
                $expected === null ? 'bundled' : (string) $expected,
                'Mozilla Public License 2.0',
                'The manga server. It does the sources, the downloading and the library, and Papyrus '
                    .'talks to it over a local socket.',
            ),
            LicensedPackage::make(
                'Eclipse Temurin JRE',
                $this->jreVersion() ?? 'bundled',
                'GPL-2.0 with the Classpath Exception',
                'The Java runtime the manga server runs on. Papyrus never executes Java itself.',
            ),
        ];
    }

    /**
     * Everything the install is made of, in the order a reader wants it: the two large pieces first,
     * then the languages, each sorted by name.
     *
     * @return list<LicensedPackage>
     */
    public function all(): array
    {
        return [
            ...$this->bundled(),
            ...$this->composerPackages(),
            ...$this->npmPackages(),
        ];
    }

    public function totalCount(): int
    {
        return count($this->all());
    }

    /**
     * How many name themselves as not open source, which is the number a reader cannot get anywhere
     * else and which is currently one.
     */
    public function proprietaryCount(): int
    {
        return count(array_filter($this->all(), static fn (LicensedPackage $p): bool => $p->isProprietary));
    }

    /**
     * How many record no licence at all.
     *
     * Counted separately rather than folded into the above, because an absent licence is a gap in the
     * record and not a statement about the package. These are all platform specific prebuilt binaries
     * for darwin, freebsd and arm, none of which ship on a Windows install.
     */
    public function unrecordedCount(): int
    {
        return count(array_filter($this->all(), static fn (LicensedPackage $p): bool => $p->isUnrecorded()));
    }

    /**
     * The distinct licences in play, with a count each, so the shape of the dependency tree is
     * legible without scrolling several hundred rows.
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        $counts = [];

        foreach ($this->all() as $package) {
            $counts[$package->licence] = ($counts[$package->licence] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /**
     * Papyrus itself, which is the one thing here this project owns.
     *
     * Read from the LICENSE file rather than asserted, for the same reason as everything else on this
     * page: a hardcoded licence is a licence that can be wrong.
     */
    public function papyrus(): string
    {
        $licence = base_path('LICENSE');

        if (! File::exists($licence)) {
            return 'not stated';
        }

        $first = trim((string) strtok((string) File::get($licence), "\n"));
        $head = strtoupper($first);

        /*
         * Normalised to the identifier, because the first line of an MIT licence is "MIT License" and
         * "MIT LICENSE" in capitals reads like a placeholder nobody filled in. Anything unrecognised
         * falls through to the line as written, since guessing at an unfamiliar licence is worse than
         * showing it.
         */
        return match (true) {
            str_contains($head, 'MIT') => 'MIT',
            str_contains($head, 'MOZILLA') => 'Mozilla Public License 2.0',
            str_contains($head, 'APACHE') => 'Apache-2.0',
            str_contains($head, 'GNU AFFERO') => 'AGPL-3.0',
            str_contains($head, 'GNU GENERAL') => 'GPL-3.0',
            default => $first,
        };
    }

    /**
     * @return list<LicensedPackage>
     */
    private function fromComposerLock(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode((string) File::get($path), true);

        if (! is_array($decoded)) {
            return [];
        }

        $found = [];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach (($decoded[$section] ?? []) as $package) {
                if (! is_array($package) || ($package['name'] ?? null) === null) {
                    continue;
                }

                $licence = $package['license'] ?? ($package['licence'] ?? 'not recorded');

                if (is_array($licence)) {
                    $licence = implode(' or ', $licence);
                }

                $found[] = LicensedPackage::make(
                    (string) $package['name'],
                    (string) ($package['version'] ?? 'unknown'),
                    (string) $licence,
                    $section === 'packages' ? 'PHP, required at runtime' : 'PHP, used to build this',
                );
            }
        }

        return $this->sorted($found);
    }

    /**
     * @param  list<LicensedPackage>  $packages
     * @return list<LicensedPackage>
     */
    private function sorted(array $packages): array
    {
        usort($packages, static fn (LicensedPackage $a, LicensedPackage $b): int => strcasecmp($a->name, $b->name));

        return $packages;
    }

    /**
     * Read the JRE version out of the staged runtime rather than carrying it here.
     */
    private function jreVersion(): ?string
    {
        $release = base_path('suwayomi/runtime/release');

        if (! File::exists($release)) {
            return null;
        }

        if (preg_match('/JAVA_VERSION="([^"]+)"/', (string) File::get($release), $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
