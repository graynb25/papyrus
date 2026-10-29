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
 * An install carries none of that: the locks do not travel and neither do `node_modules`. What it
 * does carry is the record composer wrote beside the vendor tree it installed
 * (`vendor/composer/installed.json`) and, staged by the build, a manifest of the npm licence texts
 * under `licences/`. The reader here is first for whichever of those a root actually has, so one
 * class serves both the checkout and the shipped tree without two answers drifting apart. Every
 * reading below passes through {@see root()} for exactly that reason.
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
 * component library every page in this application is built from. Twenty two recorded no licence at
 * all, which is a different problem and is reported separately rather than being folded into the
 * first. The first version of this reader folded them together and called all twenty three closed,
 * which was a false accusation about twenty two packages that ship nothing on this platform. The
 * 1.5.1 toolchain update removed those binaries, so the checkout lockfile now records every
 * licence, but the page still keeps the two counts apart for a future gap.
 */
final class Licences
{
    public function __construct(
        /**
         * A root other than the running application's, for reading what a different tree carries.
         *
         * Null means the checkout the app runs from. The concretisation is deferred rather than
         * captured, because `base_path()` inside a container object the container has not been
         * asked for remains fine either way, but reading it lazily keeps constructing this service
         * free of side effects a test with no root would otherwise inherit.
         */
        private readonly ?string $root = null,
    ) {}

    private function root(): string
    {
        return $this->root ?? base_path();
    }

    /**
     * Every PHP package, dev dependencies included.
     *
     * Dev dependencies are listed because the question being asked is "what am I running", and the
     * honest answer includes the code that built it even where that code does not execute at runtime.
     * Filtering them out would make the page shorter and less true.
     *
     * Source of record first: `composer.lock` where it exists, because it is the file the build
     * installed from and it names the dev tree explicitly. An install has none, so the reader falls
     * to `vendor/composer/installed.json`, which a packaged tree always carries because the vendor
     * tree beside it was made by composer. Where a tree has neither, the answer is empty rather than
     * half invented.
     *
     * @return list<LicensedPackage>
     */
    public function composerPackages(): array
    {
        $lock = $this->root().'/composer.lock';

        if (File::exists($lock)) {
            return $this->fromComposerLock($lock);
        }

        $installed = $this->root().'/vendor/composer/installed.json';

        if (File::exists($installed)) {
            return $this->fromInstalledJson($installed);
        }

        return [];
    }

    /**
     * @return list<LicensedPackage>
     */
    public function npmPackages(): array
    {
        $lock = $this->root().'/package-lock.json';

        if (! File::exists($lock)) {
            return $this->fromStagedManifest($this->root().'/licences/node/manifest.json');
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
                textKey: 'npm:'.$path,
                kind: 'npm',
                upstream: $this->npmUpstream((string) $name, $package),
            );
        }

        return $this->sorted($found);
    }

    /**
     * @return list<LicensedPackage>
     */
    private function fromStagedManifest(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode((string) File::get($path), true);

        if (! is_array($decoded)) {
            return [];
        }

        $found = [];

        /*
         * The manifest is what the build staged, so each row already carries the licence string and
         * the staged file name, and the reader here is a map rather than a derivation. A row whose
         * file is null is one the publisher declared no text for and the build staged nothing of;
         * the text reader reads exactly that and answers null, so the page can say the truth: no
         * text in this install, rather than a paraphrase of a declaration nobody shipped.
         */
        foreach (($decoded['packages'] ?? []) as $row) {
            if (! is_array($row) || (($row['path'] ?? '') === '')) {
                continue;
            }

            $name = is_string($row['name'] ?? null) && $row['name'] !== ''
                ? $row['name']
                : $this->nameFromPath((string) $row['path']);

            $found[] = LicensedPackage::make(
                $name,
                (string) ($row['version'] ?? 'unknown'),
                (string) ($row['licence'] ?? 'not recorded in the lock file'),
                'JavaScript, shipped in this install',
                textKey: 'npm:'.$row['path'],
                kind: 'npm',
                upstream: is_string($row['upstream'] ?? null) ? $row['upstream'] : '',
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
     * Where the project behind an npm row lives, from what npm records about it.
     *
     * The repository field is an object in modern packages and a bare string in old ones, so both
     * shapes are named here rather than trusting one. Failing every recorded link, the registry
     * page still exists for any published package, which is a link a reader can follow rather than
     * a guess about where the source is.
     *
     * @param  array<string, mixed>  $package
     */
    private function npmUpstream(string $name, array $package): string
    {
        $repository = $package['repository'] ?? null;
        $url = is_array($repository) ? ($repository['url'] ?? null) : $repository;

        if (is_string($url) && $url !== '') {
            return $url;
        }

        if (is_string($package['homepage'] ?? null) && $package['homepage'] !== '') {
            return $package['homepage'];
        }

        return 'https://www.npmjs.com/package/'.$name;
    }

    /**
     * The pieces the build bundles, which no lockfile mentions.
     *
     * @return list<LicensedPackage>
     */
    public function bundled(): array
    {
        $declared = File::exists($this->root().'/packaging/suwayomi.json')
            ? json_decode((string) File::get($this->root().'/packaging/suwayomi.json'), true)
            : null;

        $expected = is_array($declared) ? ($declared['tested'] ?? null) : null;

        return [
            LicensedPackage::make(
                'Suwayomi Server',
                $expected === null ? 'bundled' : (string) $expected,
                'Mozilla Public License 2.0',
                'The manga server. It does the sources, the downloading and the library, and Papyrus '
                    .'talks to it over a local socket.',
                textKey: 'bundled:suwayomi-server',
                kind: 'bundled',
                upstream: 'https://github.com/Suwayomi/Suwayomi-Server',
            ),
            LicensedPackage::make(
                'Eclipse Temurin JRE',
                $this->jreVersion() ?? 'bundled',
                'GPL-2.0 with the Classpath Exception',
                'The Java runtime the manga server runs on. Papyrus never executes Java itself.',
                textKey: 'bundled:java-runtime',
                kind: 'bundled',
                upstream: 'https://adoptium.net',
            ),
            LicensedPackage::make(
                'Heroicons',
                'bundled',
                'MIT',
                'The open book in the Papyrus seal. It is artwork from the Heroicons outline set, not '
                    .'a runtime dependency.',
                textKey: 'bundled:heroicons',
                kind: 'bundled',
                upstream: 'https://heroicons.com',
            ),
        ];
    }

    /**
     * Everything the install is made of, in the order a reader wants it: the bundled pieces first,
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
     * record and not a statement about the package. The gap is usually a platform specific prebuilt
     * binary for a platform this install does not use; the 1.5.1 toolchain update removed the last of
     * them from the checkout lockfile, but a future dependency can add one back.
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
        $licence = $this->root().'/LICENSE';

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
                    textKey: 'composer:'.$package['name'],
                    kind: 'composer',
                    upstream: $this->upstreamOf($package),
                );
            }
        }

        return $this->sorted($found);
    }

    /**
     * The record composer wrote beside the vendor tree it installed, which is what a packaged tree
     * carries instead of a lock file.
     *
     * Its shape is one flat `packages` list, so there is no dev section to name separately: the
     * reader reads what shipped, and the label says that rather than pretending this tree came from
     * a lock. Every row gains its text key here so a later read of the licence text beside the
     * vendor package finds exactly this row again.
     *
     * @return list<LicensedPackage>
     */
    private function fromInstalledJson(string $path): array
    {
        $decoded = json_decode((string) File::get($path), true);

        if (! is_array($decoded)) {
            return [];
        }

        $found = [];

        foreach (($decoded['packages'] ?? []) as $package) {
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
                'PHP, shipped in this install',
                textKey: 'composer:'.$package['name'],
                kind: 'composer',
                upstream: $this->upstreamOf($package),
            );
        }

        return $this->sorted($found);
    }

    /**
     * Where the project behind a composer row lives, taken from the fields composer records.
     *
     * A homepage is a hand curated link and beats a bare source URL, which is sometimes an internal
     * git remote a reader cannot open anyway. An empty string is the honest answer when neither was
     * recorded, and the page renders no link for it.
     *
     * @param  array<string, mixed>  $package
     */
    private function upstreamOf(array $package): string
    {
        $homepage = is_string($package['homepage'] ?? null) ? $package['homepage'] : '';
        $source = is_array($package['source'] ?? null) ? ($package['source']['url'] ?? null) : (is_string($package['source'] ?? null) ? $package['source'] : null);
        $sourceUrl = is_string($source) ? $source : '';

        return $homepage !== '' ? $homepage : $sourceUrl;
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
        $release = $this->root().'/suwayomi/runtime/release';

        if (! File::exists($release)) {
            return null;
        }

        if (preg_match('/JAVA_VERSION="([^"]+)"/', (string) File::get($release), $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * The row a text action refers to, so the page never has to hold two derivations of identity.
     */
    public function licenceByKey(string $key): ?LicensedPackage
    {
        foreach ($this->all() as $package) {
            if ($package->textKey === $key) {
                return $package;
            }
        }

        return null;
    }

    /**
     * The text behind a row, byte for byte from the file that ships beside the package.
     *
     * Null is the honest failure: a package whose publisher named a licence but ships no text, or a
     * tree the package is not in. The page hides its reveal control for such a row rather than
     * paraphrasing a declaration the reader cannot read anywhere.
     */
    public function textFor(LicensedPackage $package): ?string
    {
        $file = $this->licenceFileFor($package);

        return $file === null ? null : (string) File::get($file);
    }

    /**
     * Whether a text lives behind the row at all, asked without reading it.
     *
     * The page's reveal buttons ask this for every row they render, so a row with nothing behind it
     * offers nothing to press. On the checkout that is a directory probe per row and on an install
     * it is the staged manifest; none of it is a byte read until a reader actually opens the row.
     */
    public function hasText(LicensedPackage $package): bool
    {
        return $this->licenceFileFor($package) !== null;
    }

    /**
     * The file a row's text would be read from, indexed once so the button and the read agree.
     *
     * The button must not offer a text that would fail to open, and the open must not read a file
     * the button declined to offer, so both questions answer through this one resolution instead
     * of two shapes that could drift.
     */
    private function licenceFileFor(LicensedPackage $package): ?string
    {
        return match ($package->kind) {
            'composer' => $this->composerLicenceFile($package->textKey),
            'npm' => $this->npmLicenceFile($package->textKey),
            'bundled' => $this->bundledLicenceFile($package->textKey),
            default => null,
        };
    }

    private function composerLicenceFile(string $key): ?string
    {
        $name = (string) substr($key, strlen('composer:'));

        // The key is built by this class from a lock file and read back only by this class, but the
        // pieces it travels through are not: a guard on traversal is one line here and a rule that
        // cannot break there.
        if ($name === '' || str_contains($name, '..')) {
            return null;
        }

        return $this->licenceFileIn($this->root().'/vendor/'.$name);
    }

    /**
     * On an install the file resolves from the staged manifest; anywhere else, from beside the package.
     *
     * The manifest is first because it is the thing the build staged: a packaged tree carries no
     * node_modules, so reaching for the lock-era path would produce a page of silent nulls even
     * though the build put the text on disk. When no manifest exists this is a checkout, where the
     * directory beside the package is exactly right.
     */
    private function npmLicenceFile(string $key): ?string
    {
        $path = (string) substr($key, strlen('npm:'));

        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        $manifest = $this->root().'/licences/node/manifest.json';

        if (File::exists($manifest)) {
            $decoded = json_decode((string) File::get($manifest), true);

            if (! is_array($decoded)) {
                return null;
            }

            foreach (($decoded['packages'] ?? []) as $row) {
                if (is_array($row) && (($row['path'] ?? null) === $path)) {
                    $file = is_string($row['file'] ?? null) && $row['file'] !== '' ? $row['file'] : null;

                    if ($file === null) {
                        return null;
                    }

                    $candidate = $this->root().'/licences/'.$file;

                    return File::exists($candidate) ? $candidate : null;
                }
            }

            return null;
        }

        return $this->licenceFileIn($this->root().'/'.$path);
    }

    /**
     * The two large pieces, from the copy a build stages or the tracked originals at rest.
     */
    private function bundledLicenceFile(string $key): ?string
    {
        $slug = (string) substr($key, strlen('bundled:'));

        if ($slug === '' || str_contains($slug, '..')) {
            return null;
        }

        foreach ([
            $this->root().'/licences/'.$slug.'.txt',
            $this->root().'/packaging/licences/'.$slug.'.txt',
        ] as $candidate) {
            if (File::exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The first licence shaped file in a package directory, read in a fixed preference order.
     *
     * Scanning finds every candidate; the order the shapes are tried decides which one a directory
     * carrying several receives, so two copies of one package cannot disagree about what a reveal
     * reads for either of them. Case insensitivity is a measurement: the trees carry at least four
     * spellings of the same file name.
     */
    private function licenceFileIn(string $dir): ?string
    {
        if (! is_dir($dir)) {
            return null;
        }

        $entries = scandir($dir);

        if ($entries === false) {
            return null;
        }

        foreach (self::LICENCE_FILE_SHAPES as $shape) {
            foreach ($entries as $entry) {
                if (is_file($dir.DIRECTORY_SEPARATOR.$entry) && strcasecmp($entry, $shape) === 0) {
                    return $dir.DIRECTORY_SEPARATOR.$entry;
                }
            }
        }

        return null;
    }

    /**
     * The licence file names this tree was measured to actually carry, in resolver preference order.
     *
     * Measured on 2026-10-18, not invented: composer carries LICENSE, LICENSE.md, LICENSE.txt,
     * license.md and one COPYRIGHT.md; npm carries LICENSE and license by the dozen, LICENSE.md,
     * LICENSE.txt, LICENCE, and two singletons, LICENSE-MIT.txt and CopyrightNotice.txt. Every
     * singleton keeps its place on the list because a rename that matched nothing would otherwise
     * read as a package with no text at all, which is a worse failure than showing the file. Anything
     * not on this list is a licence file this tree has never carried, so a shape matching none of
     * these falls through to "no text in this install", a state the page can name.
     *
     * @var list<string>
     */
    private const LICENCE_FILE_SHAPES = [
        'LICENSE', 'LICENSE.md', 'LICENSE.txt', 'LICENCE', 'LICENCE.md', 'LICENCE.txt',
        'COPYING', 'COPYING.txt', 'NOTICE', 'NOTICE.md', 'COPYRIGHT', 'COPYRIGHT.md',
        'CopyrightNotice.txt', 'LICENSE-MIT.txt', 'MIT-LICENSE', 'MIT-LICENSE.txt',
        'UNLICENSE', 'UNLICENSE.md',
    ];
}
