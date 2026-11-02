<?php

declare(strict_types=1);

namespace Papyrus\Launcher;

use RuntimeException;

/**
 * The packaging pass that puts licence text into an install.
 *
 * ## Why this lives beside the launcher and not inside a build script
 *
 * Because the rules it enforces outlive any one script: walk only real package roots, refuse a
 * licence the publisher declared but shipped no text for unless the repository records that measured
 * absence itself, and leave behind an install that either carries every text its manifest promises or stops the build before anything lands. That is the
 * same discipline the launcher applies to a start, and it is testable only as a class the build
 * calls rather than as lines inside a script nobody exercises twice.
 *
 * The build scripts stay thin callers: stage, then ask {@see missingStaged()} whether anything
 * the manifest promises went missing, and fail in their own voice if the answer is not empty.
 *
 * Like every class under src/, this depends on nothing but PHP itself, so it runs wherever the
 * build runs.
 */
final class LicenceStaging
{
    /** A licence a package declares nowhere, the state the page names rather than inventing past. */
    private const UNDECLARED = 'not recorded in the lock file';

    /**
     * The licence file names this tree is known to carry, in resolver preference order.
     *
     * The application's own resolver carries the same measured list, and this class cannot use it:
     * the launcher depends on nothing but PHP. The list was measured on the tree, not invented, and
     * every singleton keeps its place because a rename that matched nothing would otherwise read as
     * a package with no text at all. For a DECLARED licence that outcome is the refusal this pass
     * exists to raise, so both lists move together, and this one failing short fails loudly at
     * build time rather than silently at page time.
     *
     * @var list<string>
     */
    private const LICENCE_SHAPES = [
        'LICENSE', 'LICENSE.md', 'LICENSE.txt', 'LICENCE', 'LICENCE.md', 'LICENCE.txt',
        'COPYING', 'COPYING.txt', 'NOTICE', 'NOTICE.md', 'COPYRIGHT', 'COPYRIGHT.md',
        'CopyrightNotice.txt', 'LICENSE-MIT.txt', 'MIT-LICENSE', 'MIT-LICENSE.txt',
        'UNLICENSE', 'UNLICENSE.md',
    ];

    /**
     * The tracked marker for the third state a licence can sit in: the publisher declared one,
     * the pass found no text beside the package, and the measurement upstream found none either.
     *
     * An invented text is forbidden, and an unserved declaration is not honest either: what this
     * marker buys is the row an undeclared licence already stages, a licence named with nothing
     * behind it, backed here by a tracked file that records where the absence was measured. A
     * marker covers only the package whose override directory carries it, and a text staged into
     * that directory alongside makes the question moot on the next build.
     */
    private const ABSENCE_MARKER = 'NO-LICENCE-FILE-UPSTREAM.txt';

    /**
     * Stage every licence text an install must carry to serve its licences page.
     *
     * Three sources, one pass, for two package managers: the texts publishers ship beside their
     * packages, the tracked overrides for declarations the ecosystem shipped without a file and the
     * tracked absence markers for declarations no publisher ever published text for, and the
     * project's own texts, the root LICENSE and the packaged ones, which travel with everything
     * else. The npm side walks the installed node_modules and writes `licences/node/manifest.json`;
     * the composer side reads the lock the vendor tree was installed from and writes
     * `licences/composer/manifest.json` for the rows that need a record of their own. Both manifests
     * are written last, so a manifest on disk is the signature of a pass that completed.
     *
     * Validation completes before the first byte moves. A pass that wrote as it walked would stop
     * halfway to its own refusal and leave an install that is neither the old build nor the new,
     * which is worse than refusing.
     *
     * @return int the number of package texts staged under licences/node and licences/composer
     */
    public static function stage(string $repo, string $into): int
    {
        $repo = rtrim($repo, '/\\');
        $nodeRoot = $repo.'/node_modules';
        $bundled = $repo.'/packaging/licences';

        if (! is_dir($nodeRoot)) {
            throw new RuntimeException('no node_modules at '.$repo.': the licence pass walks the tree the install is built from');
        }

        if (! is_file($repo.'/LICENSE')) {
            throw new RuntimeException('no LICENSE at the repository root, so the pass refuses to build an install that cannot name its own project');
        }

        if (! is_dir($bundled)) {
            throw new RuntimeException('no packaging/licences at '.$repo.', so the bundled texts the page reads at rest are not there to stage');
        }

        $rows = [];
        $copies = [];

        foreach (self::packageRoots($repo, $nodeRoot) as $root) {
            $relative = self::normalizeSlashes(substr(self::normalizeSlashes((string) $root), strlen(self::normalizeSlashes($repo)) + 1));
            $package = self::readPackage((string) $root, $relative);
            $text = self::textSource($repo, (string) $root, $relative);

            if ($text === null && $package['licence'] !== self::UNDECLARED && ! self::absenceRecorded($repo, $relative)) {
                throw new RuntimeException(sprintf(
                    'no licence text for %s: it declares %s but ships no file the pass reads, and nothing is'
                    .' staged at packaging/staged-licences/%s/LICENSE. Stage the text there, or, if the'
                    .' publisher ships no licence text anywhere, record the measured absence with %s beside'
                    .' it, and rebuild.',
                    $package['name'],
                    $package['licence'],
                    $relative,
                    self::ABSENCE_MARKER,
                ));
            }

            $file = $text === null ? null : 'node/'.$relative.'/'.basename($text);

            $rows[] = [
                'name' => $package['name'],
                'path' => $relative,
                'version' => $package['version'],
                'licence' => $package['licence'],
                'upstream' => $package['upstream'],
                'file' => $file,
            ];

            if ($file !== null) {
                $copies[] = ['file' => $file, 'source' => (string) $text];
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['path'], (string) $b['path']));

        /*
         * The composer plan is validated here, before the first byte below moves, for the same
         * reason the loop above refuses before writing: a build that stops halfway to its own
         * refusal leaves an install that is neither the old build nor the new.
         */
        $composer = self::composerPlan($repo);

        /*
         * Fresh rather than merged. A package that left the tree between builds would otherwise
         * leave its staged text behind with nothing in the manifest pointing at it, and dead text
         * in an artifact is the same lie as a licence the tree no longer carries.
         */
        $nodeLicences = $into.'/licences/node';
        self::removeDirectory($nodeLicences);

        if (! mkdir($nodeLicences, 0777, true) && ! is_dir($nodeLicences)) {
            throw new RuntimeException('could not create '.$nodeLicences);
        }

        $count = 0;

        foreach ($copies as $copy) {
            $target = $into.'/licences/'.$copy['file'];
            $directory = dirname($target);

            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            copy($copy['source'], $target);
            $count++;
        }

        file_put_contents($into.'/licences/node/manifest.json', (string) json_encode(
            ['packages' => $rows],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));

        copy($repo.'/LICENSE', $into.'/LICENSE');

        foreach (self::bundledTexts($bundled) as $text) {
            copy($text, $into.'/licences/'.basename($text));
        }

        if ($composer === null) {
            // No lock means no composer tree this pass can describe. The build's required files
            // catch that loudly; leaving a stale manifest here would describe a tree that no
            // longer exists.
            self::removeDirectory($into.'/licences/composer');

            return $count;
        }

        return $count + self::composerWrite($into, $composer);
    }

    /**
     * What the composer side must stage, validated before anything is written.
     *
     * The npm pass walks the tree it installs; this one reads the lock the vendor tree was
     * installed from, because a composer package's own directory travels with the install and only
     * the two cases the ecosystem cannot serve need a record: a text staged by hand because the
     * publisher shipped none, and a marker because no text exists anywhere to stage. A package
     * whose licence file already ships beside it is not copied: a second copy would be a second
     * answer to one question.
     *
     * A proprietary package with neither is the one state this side refuses, because it is the
     * state the product cannot honestly redistribute: the terms are unknown, and nothing in the
     * tree says that was measured rather than missed.
     *
     * A null plan is a repository with no composer.lock, so no composer tree this pass can describe.
     *
     * @return array{rows: list<array<string, mixed>>, copies: list<array{file: string, source: string}>}|null
     */
    private static function composerPlan(string $repo): ?array
    {
        $lockPath = $repo.'/composer.lock';

        if (! is_file($lockPath)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($lockPath), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('unreadable composer.lock at '.$lockPath.': the pass refuses to guess what the packages declare');
        }

        $plan = ['rows' => [], 'copies' => []];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach (($decoded[$section] ?? []) as $package) {
                if (! is_array($package) || ! is_string($package['name'] ?? null) || $package['name'] === '') {
                    continue;
                }

                $name = $package['name'];
                $licence = self::licenceDeclared($package['license'] ?? ($package['licence'] ?? null));

                if (self::licenceFileIn($repo.'/vendor/'.$name) !== null) {
                    continue;
                }

                $staged = $repo.'/packaging/staged-licences/'.$name;
                $override = $staged.'/LICENSE';
                $marker = $staged.'/'.self::ABSENCE_MARKER;

                if (is_file($override)) {
                    $file = 'composer/'.$name.'/'.basename($override);
                    $plan['rows'][] = self::composerRow($package, $licence, $file, null);
                    $plan['copies'][] = ['file' => $file, 'source' => $override];

                    continue;
                }

                if (is_file($marker)) {
                    $file = 'composer/'.$name.'/'.self::ABSENCE_MARKER;
                    $plan['rows'][] = self::composerRow($package, $licence, null, $file);
                    $plan['copies'][] = ['file' => $file, 'source' => $marker];

                    continue;
                }

                if (! str_contains(strtolower($licence), 'proprietary')) {
                    continue;
                }

                throw new RuntimeException(sprintf(
                    'no licence text for %s: it declares %s but ships no file the pass reads, and nothing is'
                    .' staged at packaging/staged-licences/%s/LICENSE. Stage the text there, or, if the'
                    .' publisher ships no licence text anywhere, record the measured absence with %s beside'
                    .' it, and rebuild.',
                    $name,
                    $licence,
                    $name,
                    self::ABSENCE_MARKER,
                ));
            }
        }

        usort($plan['rows'], static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    private static function composerRow(array $package, string $licence, ?string $file, ?string $marker): array
    {
        $row = [
            'name' => (string) $package['name'],
            'version' => is_string($package['version'] ?? null) && trim($package['version']) !== ''
                ? trim($package['version'])
                : 'unknown',
            'licence' => $licence,
            'file' => $file,
        ];

        if ($marker !== null) {
            $row['marker'] = $marker;
        }

        return $row;
    }

    /**
     * Write the validated composer plan, starting fresh like the npm tree.
     *
     * @param  array{rows: list<array<string, mixed>>, copies: list<array{file: string, source: string}>}  $plan
     * @return int the number of licence texts staged under licences/composer, which excludes the
     *             absence markers: a marker records that no text exists and is never counted as one
     */
    private static function composerWrite(string $into, array $plan): int
    {
        $composerLicences = $into.'/licences/composer';
        self::removeDirectory($composerLicences);

        if (! mkdir($composerLicences, 0777, true) && ! is_dir($composerLicences)) {
            throw new RuntimeException('could not create '.$composerLicences);
        }

        $count = 0;

        foreach ($plan['copies'] as $copy) {
            $target = $into.'/licences/'.$copy['file'];
            $directory = dirname($target);

            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            copy($copy['source'], $target);

            if (basename($copy['file']) !== self::ABSENCE_MARKER) {
                $count++;
            }
        }

        file_put_contents($into.'/licences/composer/manifest.json', (string) json_encode(
            ['packages' => $plan['rows']],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));

        return $count;
    }

    /**
     * The staged files the manifest promises that the install does not carry.
     *
     * The build asks this immediately after staging, because a manifest whose files went missing
     * is a page of "no text" rows on a build that reported success, which is the exact shape a
     * build exists to make impossible. The paths are the manifest's own, so a refusal names the
     * row the reader would have been shown.
     *
     * @return list<string> manifest file paths, relative to the install's licences directory
     */
    public static function missingStaged(string $into): array
    {
        $manifest = $into.'/licences/node/manifest.json';

        if (! is_file($manifest)) {
            throw new RuntimeException('no npm licence manifest at '.$manifest.': the guard reads what staging wrote, so the pass must have run');
        }

        $decoded = json_decode((string) file_get_contents($manifest), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('unreadable npm licence manifest at '.$manifest.': the guard refuses to guess what staging promised');
        }

        $missing = [];

        foreach (($decoded['packages'] ?? []) as $row) {
            $file = is_array($row) ? ($row['file'] ?? null) : null;

            if (is_string($file) && $file !== '' && ! is_file($into.'/licences/'.$file)) {
                $missing[] = $file;
            }
        }

        /*
         * The composer manifest is optional here rather than required, because a repository whose
         * composer side staged nothing still writes one, while a tree that predates the composer
         * pass does not. A manifest that is present is checked the same way as the npm one: its
         * files and its absence markers are both promises the install must keep.
         */
        $composerManifest = $into.'/licences/composer/manifest.json';

        if (is_file($composerManifest)) {
            $composer = json_decode((string) file_get_contents($composerManifest), true);

            if (! is_array($composer)) {
                throw new RuntimeException('unreadable composer licence manifest at '.$composerManifest.': the guard refuses to guess what staging promised');
            }

            foreach (($composer['packages'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                foreach (['file', 'marker'] as $field) {
                    $file = $row[$field] ?? null;

                    if (is_string($file) && $file !== '' && ! is_file($into.'/licences/'.$file)) {
                        $missing[] = $file;
                    }
                }
            }
        }

        return $missing;
    }

    /**
     * The directories under an npm tree that are real package roots and carry a package.json.
     *
     * @return list<string>
     */
    private static function packageRoots(string $repo, string $nodeRoot): array
    {
        $repoLength = strlen(self::normalizeSlashes($repo));

        $roots = [];

        $tree = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($nodeRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($tree as $entry) {
            if (! $entry->isDir()) {
                continue;
            }

            $relative = self::normalizeSlashes(substr(self::normalizeSlashes($entry->getPathname()), $repoLength + 1));

            if (self::isPackagePath($relative) && is_file($entry->getPathname().DIRECTORY_SEPARATOR.'package.json')) {
                $roots[] = $entry->getPathname();
            }
        }

        return $roots;
    }

    /**
     * Whether a path under the repository root names a package or subpath metadata.
     *
     * The rule reads what follows a path's LAST node_modules hop: one segment, or a scoped pair,
     * is a package; anything else is a directory inside one, whatever package.json it may carry.
     * That is what keeps ESM alias directories like `nom-licence/esm` and bundled subpath
     * metadata like `rxjs/operators` out of the manifest, while a package nested in another
     * package's own node_modules stays in as its own row.
     */
    private static function isPackagePath(string $relative): bool
    {
        $segments = explode('/', $relative);

        $lastHop = -1;

        foreach ($segments as $index => $segment) {
            if ($segment === 'node_modules') {
                $lastHop = (int) $index;
            }
        }

        $tail = array_slice($segments, $lastHop + 1);

        foreach ($tail as $segment) {
            if ($segment === '' || str_starts_with($segment, '.')) {
                return false;
            }
        }

        $count = count($tail);

        return $count === 1 || ($count === 2 && str_starts_with((string) $tail[0], '@'));
    }

    /**
     * What a package says about itself, read from its own package.json.
     *
     * @return array{name: string, version: string, licence: string, upstream: string}
     */
    private static function readPackage(string $dir, string $relative): array
    {
        $decoded = json_decode((string) file_get_contents($dir.DIRECTORY_SEPARATOR.'package.json'), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('unreadable package.json in '.$relative.': the pass refuses to guess what a package declares');
        }

        $name = is_string($decoded['name'] ?? null) && trim($decoded['name']) !== ''
            ? trim($decoded['name'])
            : basename($relative);

        return [
            'name' => $name,
            'version' => is_string($decoded['version'] ?? null) && trim($decoded['version']) !== ''
                ? trim($decoded['version'])
                : 'unknown',
            'licence' => self::licenceDeclared($decoded['license'] ?? null),
            'upstream' => self::upstreamDeclared($decoded, $name),
        ];
    }

    /**
     * The licence a package declares, in the sentence the page renders.
     *
     * A string passes as written. npm's dual declaration, an array of licences, is joined with the
     * word or, which is what the page renders. Everything else records the gap honestly rather
     * than inventing a declaration no publisher made.
     *
     * @param  mixed  $field  the package.json license field, any shape npm allows
     */
    private static function licenceDeclared(mixed $field): string
    {
        if (is_string($field)) {
            $declared = trim($field);

            return $declared === '' ? self::UNDECLARED : $declared;
        }

        if (is_array($field)) {
            $parts = [];

            foreach ($field as $piece) {
                $type = is_string($piece)
                    ? $piece
                    : (is_array($piece) && is_string($piece['type'] ?? null) ? $piece['type'] : null);

                if (is_string($type) && trim($type) !== '') {
                    $parts[] = trim($type);
                }
            }

            $parts = array_values(array_unique($parts));

            if ($parts !== []) {
                return implode(' or ', $parts);
            }
        }

        return self::UNDECLARED;
    }

    /**
     * Where the project lives, for the row's link.
     *
     * A repository first, object or string, because npm carries both, then a homepage, then the
     * registry page, which is the truth remaining when a publisher named neither.
     *
     * @param  array<string, mixed>  $decoded
     */
    private static function upstreamDeclared(array $decoded, string $name): string
    {
        $repository = $decoded['repository'] ?? null;

        $url = null;

        if (is_string($repository)) {
            $url = $repository;
        } elseif (is_array($repository) && is_string($repository['url'] ?? null)) {
            $url = $repository['url'];
        }

        if (is_string($url) && trim($url) !== '') {
            return trim($url);
        }

        $homepage = $decoded['homepage'] ?? null;

        if (is_string($homepage) && trim($homepage) !== '') {
            return trim($homepage);
        }

        return 'https://www.npmjs.com/package/'.$name;
    }

    /**
     * The file a package's text is read from, null when nothing offers text to stage.
     *
     * The package's own directory first, then the tracked override. The override exists for the
     * real ecosystem state where a publisher declares a licence and the registry ships no file:
     * both are real, and the remedy is a text staged by hand at
     * packaging/staged-licences/<package path>/LICENSE in the repository, tracked beside the code
     * so the next build carries it without anybody remembering why.
     *
     * Null here is not in itself a refusal. {@see stage()} refuses it only while the package
     * declares a licence and {@see absenceRecorded()} finds no marker, which is the narrow state
     * there is no honest way through.
     */
    private static function textSource(string $repo, string $dir, string $relative): ?string
    {
        $found = self::licenceFileIn($dir);

        if ($found !== null) {
            return $found;
        }

        $override = $repo.'/packaging/staged-licences/'.$relative.'/LICENSE';

        return is_file($override) ? $override : null;
    }

    /**
     * Whether the package's tracked override directory records the absence the gate would refuse.
     *
     * The marker exists for the one case a staged text cannot fix: measured upstream, publisher
     * never published a licence text at all. The record lives in the repository beside where the
     * text would have been, and lets the pass stage the row the manifest already renders honestly,
     * a declared licence with a null file, instead of stopping a build over a text that does not
     * exist and must not be invented.
     */
    private static function absenceRecorded(string $repo, string $relative): bool
    {
        return is_file($repo.'/packaging/staged-licences/'.$relative.'/'.self::ABSENCE_MARKER);
    }

    /**
     * The licence text file in a directory, in the measured preference order, or null.
     */
    private static function licenceFileIn(string $dir): ?string
    {
        $entries = scandir($dir);

        if ($entries === false) {
            return null;
        }

        foreach (self::LICENCE_SHAPES as $shape) {
            foreach ($entries as $entry) {
                if (is_file($dir.DIRECTORY_SEPARATOR.$entry) && strcasecmp($entry, $shape) === 0) {
                    return $dir.DIRECTORY_SEPARATOR.$entry;
                }
            }
        }

        return null;
    }

    /**
     * The project's own tracked licence texts, sorted so the pass is deterministic.
     *
     * @return list<string>
     */
    private static function bundledTexts(string $dir): array
    {
        $texts = glob($dir.'/*.txt') ?: [];

        sort($texts);

        return $texts;
    }

    /**
     * Remove a directory whole, which is how the staged node licence tree starts fresh each pass.
     */
    private static function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $inside = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($inside as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($path);
    }

    private static function normalizeSlashes(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
