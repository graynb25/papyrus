<?php

namespace Tests\Feature;

use Papyrus\Launcher\LicenceStaging;
use RuntimeException;
use Tests\TestCase;

/**
 * The packaging pass that puts licence text into the install.
 *
 * ## Why a packaging step gets a class and tests
 *
 * Because the rules it enforces are the same rules the build already enforces about a reader's
 * library: the failure has to be loud, named, and before the artifact says anything. A rule
 * buried in the build script's own output is something the next build redoes; a class with
 * tests is a rule the next sitting can rely on. The build script stays a thin caller: it
 * stages, then it asks the same class whether anything it staged is missing, and it fails in
 * its own voice if the answer is not empty.
 */
class LicenceStagingTest extends TestCase
{
    /**
     * The repository shape the staging reads: a scoped publisher text, a flat one, an outer
     * and a nested pair, a package that declares a licence and ships no text which a tracked
     * override covers, a package that declares one and carries the tracked absence marker
     * because no text exists anywhere, and a package.json under an ESM alias directory that is
     * sub path metadata and not a package.
     */
    private function fixtureRepo(bool $withOverride = true, bool $withMarker = true): string
    {
        $repo = $this->scratchPath('licence-repo');

        mkdir($repo.'/packaging/licences', 0777, true);
        file_put_contents($repo.'/LICENSE', "MIT License\n");
        file_put_contents($repo.'/packaging/licences/suwayomi-server.txt', "MPL 2.0 TEXT\n");
        file_put_contents($repo.'/packaging/licences/java-runtime.txt', "GPL WITH CLASSPATH TEXT\n");

        $simple = $repo.'/node_modules/@scope/simple';
        mkdir($simple, 0777, true);
        file_put_contents($simple.'/package.json', (string) json_encode([
            'name' => '@scope/simple',
            'version' => '1.0.0',
            'license' => 'MIT',
            'repository' => ['type' => 'git', 'url' => 'https://git.example.com/scope/simple'],
        ]));
        file_put_contents($simple.'/LICENSE', "SCOPE TEXT\n");

        $homepage = $repo.'/node_modules/homepage-pkg';
        mkdir($homepage, 0777, true);
        file_put_contents($homepage.'/package.json', (string) json_encode([
            'name' => 'homepage-pkg',
            'version' => '2.3.4',
            'license' => 'MIT',
            'homepage' => 'https://www.example.org/homepage-pkg',
        ]));
        file_put_contents($homepage.'/LICENSE.md', "MARKDOWN TEXT\n");

        /*
         * A package that declares nothing, beside a directory that happens to carry a
         * package.json for an ESM alias, which is sub-path metadata and not a package.
         */
        $undeclared = $repo.'/node_modules/nom-licence';
        mkdir($undeclared, 0777, true);
        file_put_contents($undeclared.'/package.json', (string) json_encode([
            'name' => 'nom-licence',
            'version' => '0.1.0',
        ]));

        $alias = $undeclared.'/esm';
        mkdir($alias, 0777, true);
        file_put_contents($alias.'/package.json', (string) json_encode([
            'name' => '@nom-licence/esm-alias',
            'version' => '0.1.0',
            'license' => 'MIT',
        ]));

        /*
         * The rename the acceptance sentence is about: a declaration the publisher wrote and
         * a text file saved under a name the resolver has no reason to read. With a tracked
         * override the build passes and carries the override text; without one the build
         * refuses, naming the package.
         */
        $renamed = $repo.'/node_modules/renamed-text';
        mkdir($renamed, 0777, true);
        file_put_contents($renamed.'/package.json', (string) json_encode([
            'name' => 'renamed-text',
            'version' => '1.0.0',
            'license' => 'MIT',
        ]));
        file_put_contents($renamed.'/NOT-A-LICENCE-FILE.txt', "NOT A LICENCE\n");

        if ($withOverride) {
            $staged = $repo.'/packaging/staged-licences/node_modules/renamed-text';
            mkdir($staged, 0777, true);
            file_put_contents($staged.'/LICENSE', "OVERRIDE TEXT\n");
        }

        /*
         * The third state, measured upstream rather than assumed: a declaration the publisher
         * wrote and no licence text obtainable anywhere, covered by the tracked absence marker
         * instead of an invented text.
         */
        $absent = $repo.'/node_modules/absent-text';
        mkdir($absent, 0777, true);
        file_put_contents($absent.'/package.json', (string) json_encode([
            'name' => 'absent-text',
            'version' => '4.0.0',
            'license' => 'ISC',
        ]));

        if ($withMarker) {
            $staged = $repo.'/packaging/staged-licences/node_modules/absent-text';
            mkdir($staged, 0777, true);
            file_put_contents($staged.'/NO-LICENCE-FILE-UPSTREAM.txt', "MEASURED ABSENCE\n");
        }

        $nested = $repo.'/node_modules/with-nested/node_modules/deep-pkg';
        mkdir($nested, 0777, true);
        file_put_contents($repo.'/node_modules/with-nested/package.json', (string) json_encode([
            'name' => 'with-nested',
            'version' => '3.0.0',
            'license' => 'MIT',
        ]));
        file_put_contents($repo.'/node_modules/with-nested/LICENSE', "OUTER TEXT\n");
        file_put_contents($nested.'/package.json', (string) json_encode([
            'name' => 'deep-pkg',
            'version' => '9.9.9',
            'license' => ['MIT', 'GPL-3.0'],
        ]));
        file_put_contents($nested.'/LICENSE', "DEEP TEXT\n");

        return $repo;
    }

    private function stagedInstall(): string
    {
        $into = $this->scratchPath('licence-install');

        $this->assertSame(
            5,
            LicenceStaging::stage($this->fixtureRepo(), $into),
            'five texts travel: scoped, homepage, override, outer, nested',
        );

        return $into;
    }

    public function test_it_stages_every_text_the_publishers_ship_and_writes_the_manifest(): void
    {
        $repo = $this->fixtureRepo();
        $into = $this->scratchPath('licence-install');

        $this->assertSame(5, LicenceStaging::stage($repo, $into), 'scoped, homepage, override, outer and nested make five texts');

        $manifest = json_decode((string) file_get_contents($into.'/licences/node/manifest.json'), true);
        $this->assertIsArray($manifest);

        $rows = $manifest['packages'];
        $this->assertCount(7, $rows, 'seven package roots: the scoped one, the homepage one, the undeclared one, the override one, the marked one, and the outer plus the nested one');

        $byPath = array_column($rows, null, 'path');

        $simple = $byPath['node_modules/@scope/simple'] ?? null;
        $this->assertNotNull($simple);
        $this->assertSame('@scope/simple', $simple['name']);
        $this->assertSame('1.0.0', $simple['version']);
        $this->assertSame('MIT', $simple['licence']);
        $this->assertSame('https://git.example.com/scope/simple', $simple['upstream']);
        $this->assertSame('node/node_modules/@scope/simple/LICENSE', $simple['file']);
        $this->assertSame("SCOPE TEXT\n", (string) file_get_contents($into.'/licences/'.$simple['file']));

        $homepage = $byPath['node_modules/homepage-pkg'] ?? null;
        $this->assertNotNull($homepage);
        $this->assertSame('https://www.example.org/homepage-pkg', $homepage['upstream'], 'a home page carries the upstream link when no repository names one');
        $this->assertSame('node/node_modules/homepage-pkg/LICENSE.md', $homepage['file']);
        $this->assertSame("MARKDOWN TEXT\n", (string) file_get_contents($into.'/licences/'.$homepage['file']));

        $undeclared = $byPath['node_modules/nom-licence'] ?? null;
        $this->assertNotNull($undeclared);
        $this->assertSame('not recorded in the lock file', $undeclared['licence'], 'an undeclared licence is the gap state, not a refusal');
        $this->assertNull($undeclared['file'], 'nothing to read where nothing was declared');
        $this->assertSame('https://www.npmjs.com/package/nom-licence', $undeclared['upstream']);

        $renamed = $byPath['node_modules/renamed-text'] ?? null;
        $this->assertNotNull($renamed);
        $this->assertSame('node/node_modules/renamed-text/LICENSE', $renamed['file'], 'the override carries the text when upstream ships a declaration without one');
        $this->assertSame("OVERRIDE TEXT\n", (string) file_get_contents($into.'/licences/'.$renamed['file']));

        $deep = $byPath['node_modules/with-nested/node_modules/deep-pkg'] ?? null;
        $this->assertNotNull($deep, 'a package inside a package is its own row');
        $this->assertSame('MIT or GPL-3.0', $deep['licence'], 'a dual declaration is joined with the word or, which is what the page renders');
        $this->assertSame("DEEP TEXT\n", (string) file_get_contents($into.'/licences/'.((string) $deep['file'])));

        $this->assertArrayNotHasKey('node_modules/nom-licence/esm', $byPath, 'sub path metadata is not a package and gets no row');

        $this->assertSame("MIT License\n", (string) file_get_contents($into.'/LICENSE'), 'the repository own text travels the same pass');
        $this->assertSame("MPL 2.0 TEXT\n", (string) file_get_contents($into.'/licences/suwayomi-server.txt'));
        $this->assertSame("GPL WITH CLASSPATH TEXT\n", (string) file_get_contents($into.'/licences/java-runtime.txt'));
    }

    public function test_a_declared_licence_with_no_text_refuses_and_names_the_package(): void
    {
        $repo = $this->fixtureRepo(withOverride: false);
        $into = $this->scratchPath('licence-install');

        $fired = false;

        try {
            LicenceStaging::stage($repo, $into);
        } catch (RuntimeException $e) {
            $fired = true;

            $this->assertStringContainsString('renamed-text', $e->getMessage(), 'the package being refused is named');
            $this->assertStringContainsString('packaging/staged-licences', $e->getMessage(), 'the remedy an operator can actually perform is named beside it');
        }

        $this->assertTrue($fired, 'a licence the publisher declared but shipped as nothing stops the build at this pass');
        $this->assertFileDoesNotExist($into.'/licences/node/manifest.json', 'nothing was written before the pass that refused');
    }

    /**
     * The third state the fixture carries: the publisher declared a licence and no text
     * obtainable anywhere, and the tracked absence marker covers it. The row it stages is the
     * one the page already renders honestly, a licence named with nothing behind it.
     */
    public function test_a_measured_absence_marker_stages_the_declaration_without_a_text(): void
    {
        $repo = $this->fixtureRepo();
        $into = $this->scratchPath('licence-install');

        $this->assertSame(5, LicenceStaging::stage($repo, $into), 'a marker stages no text, so five still travel');

        $manifest = json_decode((string) file_get_contents($into.'/licences/node/manifest.json'), true);
        $this->assertIsArray($manifest);

        $byPath = array_column($manifest['packages'] ?? [], null, 'path');

        $absent = $byPath['node_modules/absent-text'] ?? null;

        $this->assertNotNull($absent, 'the marked package is a row like any other');
        $this->assertSame('absent-text', $absent['name']);
        $this->assertSame('ISC', $absent['licence'], 'the declaration is recorded as written, not as missing');
        $this->assertNull($absent['file'], 'no text is pointed at, and none was invented');
        $this->assertFileDoesNotExist($into.'/licences/node/node_modules/absent-text/LICENSE');
    }

    /**
     * The marker is the narrow key, and it covers only the package that carries it. A declared
     * licence with no text and no marker is refused even on the day the fixture carries a marker
     * for a neighbor, which is what stops one measurement quietly licensing every gap.
     */
    public function test_a_declared_licence_without_text_and_without_its_own_marker_still_refuses(): void
    {
        $repo = $this->fixtureRepo(withOverride: false, withMarker: true);
        $into = $this->scratchPath('licence-install');

        $fired = false;

        try {
            LicenceStaging::stage($repo, $into);
        } catch (RuntimeException $e) {
            $fired = true;

            $this->assertStringContainsString('renamed-text', $e->getMessage(), 'the marker covers only the package that carries it, so the refusal names its own package');
            $this->assertStringContainsString('NO-LICENCE-FILE-UPSTREAM.txt', $e->getMessage(), 'the refusal names the marker beside the text it names');
        }

        $this->assertTrue($fired, 'a declared licence with no text and no marker stops the build');

        $this->assertFileDoesNotExist($into.'/licences/node/manifest.json', 'nothing was written before the pass that refused');
    }

    public function test_the_guard_names_a_staged_file_that_vanished(): void
    {
        $into = $this->stagedInstall();

        $this->assertSame([], LicenceStaging::missingStaged($into), 'a staging that just wrote everything has nothing missing');

        unlink($into.'/licences/node/node_modules/renamed-text/LICENSE');

        $missing = LicenceStaging::missingStaged($into);

        $this->assertCount(1, $missing);
        $this->assertSame('node/node_modules/renamed-text/LICENSE', $missing[0], 'the path refused is the one the manifest records');
    }

    /**
     * The composer tree the staging reads: one package whose text ships beside it, and one
     * proprietary package that ships no text, with either a tracked override text or the tracked
     * absence marker beside where that text would go.
     */
    private function composerRepo(bool $withMarker = true, bool $withOverride = false): string
    {
        $repo = $this->scratchPath('composer-repo');

        mkdir($repo.'/packaging/licences', 0777, true);
        mkdir($repo.'/node_modules', 0777, true);
        file_put_contents($repo.'/LICENSE', "MIT License\n");
        file_put_contents($repo.'/packaging/licences/suwayomi-server.txt', "MPL 2.0 TEXT\n");

        $mit = $repo.'/vendor/stub/mit';
        mkdir($mit, 0777, true);
        file_put_contents($mit.'/LICENSE', "VENDOR MIT TEXT\n");

        $flux = $repo.'/vendor/livewire/flux';
        mkdir($flux, 0777, true);
        file_put_contents($flux.'/composer.json', (string) json_encode([
            'name' => 'livewire/flux',
            'version' => 'v2.20.1',
            'license' => 'proprietary',
        ]));

        /*
         * A dev package the lock names and the checkout's full vendor tree carries. It has a
         * staged override, which is the state that would put it in the manifest if the pass read
         * the dev section: an install built with --no-dev does not carry the package at all, so a
         * row for it would describe something the reader was never given.
         */
        $dev = $repo.'/vendor/stub/dev-only';
        mkdir($dev, 0777, true);
        file_put_contents($dev.'/composer.json', (string) json_encode([
            'name' => 'stub/dev-only',
            'version' => '2.0.0',
            'license' => 'MIT',
        ]));

        $devStaged = $repo.'/packaging/staged-licences/stub/dev-only';
        mkdir($devStaged, 0777, true);
        file_put_contents($devStaged.'/LICENSE', "DEV ONLY TEXT\n");

        file_put_contents($repo.'/composer.lock', (string) json_encode([
            'packages' => [
                ['name' => 'livewire/flux', 'version' => 'v2.20.1', 'license' => 'proprietary'],
                ['name' => 'stub/mit', 'version' => '1.0.0', 'license' => 'MIT'],
            ],
            'packages-dev' => [
                ['name' => 'stub/dev-only', 'version' => '2.0.0', 'license' => 'MIT'],
            ],
        ]));

        if ($withOverride) {
            $staged = $repo.'/packaging/staged-licences/livewire/flux';
            mkdir($staged, 0777, true);
            file_put_contents($staged.'/LICENSE', "STAGED COMPOSER OVERRIDE\n");
        }

        if ($withMarker) {
            $staged = $repo.'/packaging/staged-licences/livewire/flux';
            mkdir($staged, 0777, true);
            file_put_contents($staged.'/NO-LICENCE-FILE-UPSTREAM.txt', "MEASURED COMPOSER ABSENCE\n");
        }

        return $repo;
    }

    /**
     * The composer side of the same rule the npm side already carries: a proprietary package with
     * no text anywhere is recorded by a marker, and the manifest names the marker rather than
     * leaving the row's gap unexplained.
     */
    public function test_a_composer_absence_marker_is_staged_and_named_in_the_composer_manifest(): void
    {
        $repo = $this->composerRepo();
        $into = $this->scratchPath('composer-install');

        $this->assertSame(0, LicenceStaging::stage($repo, $into), 'a marker stages no licence text');

        $manifest = json_decode((string) file_get_contents($into.'/licences/composer/manifest.json'), true);
        $this->assertIsArray($manifest);

        $byName = array_column($manifest['packages'] ?? [], null, 'name');

        $flux = $byName['livewire/flux'] ?? null;
        $this->assertNotNull($flux, 'the proprietary package is a row like any other');
        $this->assertSame('v2.20.1', $flux['version']);
        $this->assertSame('proprietary', $flux['licence']);
        $this->assertNull($flux['file'], 'no licence text was invented for it');
        $this->assertSame(
            'composer/livewire/flux/NO-LICENCE-FILE-UPSTREAM.txt',
            $flux['marker'],
            'the manifest names the tracked record of the measured absence',
        );

        $this->assertSame(
            "MEASURED COMPOSER ABSENCE\n",
            (string) file_get_contents($into.'/licences/'.$flux['marker']),
            'the marker travels into the install, because packaging/ does not',
        );

        $this->assertArrayNotHasKey(
            'stub/mit',
            $byName,
            'a text beside the vendor package already ships, so staging a copy would be a second answer',
        );

        unlink($into.'/licences/'.$flux['marker']);

        $this->assertSame(
            ['composer/livewire/flux/NO-LICENCE-FILE-UPSTREAM.txt'],
            LicenceStaging::missingStaged($into),
            'a marker the manifest promises and the install lost is a build fault, like any staged text',
        );
    }

    /**
     * The manifest describes the tree an install was built from, and that tree is production only.
     *
     * The fixture's dev package carries a staged override, which is exactly the shape that used to
     * pull it in: the pass read `packages-dev` and copied the override. An install made with
     * --no-dev has neither the package nor any reason to name it, so both the row and its text
     * must be absent.
     */
    public function test_a_dev_package_does_not_reach_the_staged_composer_manifest(): void
    {
        $repo = $this->composerRepo();
        $into = $this->scratchPath('composer-install');

        LicenceStaging::stage($repo, $into);

        $manifest = json_decode((string) file_get_contents($into.'/licences/composer/manifest.json'), true);
        $this->assertIsArray($manifest);

        $byName = array_column($manifest['packages'] ?? [], null, 'name');

        $this->assertArrayNotHasKey(
            'stub/dev-only',
            $byName,
            'a package the install does not carry must not be a row on the licences page',
        );
        $this->assertFileDoesNotExist(
            $into.'/licences/composer/stub/dev-only/LICENSE',
            'a dev package text must not be staged either',
        );
    }

    /**
     * The override is the other half of the same seam: a proprietary package whose publisher
     * declares a licence and ships no file, where a text does exist and is tracked by hand.
     */
    public function test_a_composer_staged_override_carries_the_text(): void
    {
        $repo = $this->composerRepo(withMarker: false, withOverride: true);
        $into = $this->scratchPath('composer-install');

        $this->assertSame(1, LicenceStaging::stage($repo, $into), 'one composer text travels');

        $manifest = json_decode((string) file_get_contents($into.'/licences/composer/manifest.json'), true);
        $this->assertIsArray($manifest);

        $flux = array_column($manifest['packages'] ?? [], null, 'name')['livewire/flux'] ?? null;

        $this->assertNotNull($flux);
        $this->assertSame('composer/livewire/flux/LICENSE', $flux['file']);
        $this->assertArrayNotHasKey('marker', $flux, 'a staged text makes the absence marker moot');
        $this->assertSame(
            "STAGED COMPOSER OVERRIDE\n",
            (string) file_get_contents($into.'/licences/'.$flux['file']),
        );
    }

    /**
     * And the narrow refusal: a proprietary composer package with neither a text nor a marker
     * stops the build, naming the package and both remedies.
     */
    public function test_a_proprietary_composer_package_with_neither_text_nor_marker_refuses(): void
    {
        $repo = $this->composerRepo(withMarker: false);
        $into = $this->scratchPath('composer-install');

        $fired = false;

        try {
            LicenceStaging::stage($repo, $into);
        } catch (RuntimeException $e) {
            $fired = true;

            $this->assertStringContainsString('livewire/flux', $e->getMessage(), 'the refused package is named');
            $this->assertStringContainsString('proprietary', $e->getMessage(), 'the declaration that made it a refusal is named');
            $this->assertStringContainsString('packaging/staged-licences', $e->getMessage(), 'the remedy an operator can perform is named');
            $this->assertStringContainsString('NO-LICENCE-FILE-UPSTREAM.txt', $e->getMessage(), 'the absence record is named beside it');
        }

        $this->assertTrue($fired, 'a proprietary package with no text and no record of the absence stops the build');
        $this->assertFileDoesNotExist($into.'/licences/composer/manifest.json', 'nothing was written before the pass that refused');
    }
}
