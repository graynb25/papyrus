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
}
