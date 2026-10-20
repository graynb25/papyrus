<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The portable zip, and the two things that must never travel inside it.
 *
 * ## Why a portable needs its own leak guards when the install already has them
 *
 * Because they answer different questions, and the portable is the artefact most likely to be handed
 * to somebody else. `build-install.php` guards what a *build* may contain. `build-portable.php` guards
 * what may leave this machine in a file the reader will copy onto a USB stick. A build that is clean on
 * Tuesday can be pointed at a developer's live install on Wednesday, and nothing about the first build
 * would notice.
 *
 * ## Why the leak check is a refusal and not a filter
 *
 * Because a filter that quietly omits a file is indistinguishable from a filter working. That is the
 * exact failure the licences page shipped with, reporting zero npm packages rather than three hundred,
 * and it is why every count in these scripts is checked rather than assumed.
 */
class PortableBuildTest extends TestCase
{
    /**
     * The unique parent of one test's staging area, and what `cleanUp` removes.
     *
     * The install and its output both live under it, as siblings, because the script refuses output
     * inside the install.
     */
    private ?string $base = null;

    private function script(): string
    {
        return base_path('packaging/installer/build-portable.php');
    }

    /**
     * The version these fixtures stamp into their installs.
     *
     * `build-portable.php` refuses an install whose version disagrees with the repository's own
     * `VERSION`, deliberately, so a stale tree cannot package itself under today's number. A
     * literal here disagreed at the 1.3.0 bump of 2026-10-19 and six tests were refused for the
     * version mismatch instead of the fault each of their names describes: the fixture and the
     * tree agreed yesterday and the fixture alone fell behind today. The stamp is read from the
     * same file the build checks, so the agreement holds on every future bump.
     */
    private function stampVersion(): string
    {
        $stamp = trim((string) file_get_contents(base_path('VERSION')));

        $this->assertNotSame(
            '',
            $stamp,
            'the repository VERSION file must hold a version for these installs to agree with'
        );

        return $stamp;
    }

    /**
     * Run the script against a staged install and return [exitCode, output].
     *
     * @return array{0: int, 1: string}
     */
    private function build(?string $install, ?string $out = null): array
    {
        $arguments = [escapeshellarg(PHP_BINARY), escapeshellarg($this->script())];

        if ($install !== null) {
            $arguments[] = '--install='.escapeshellarg($install);
        }

        if ($out !== null) {
            $arguments[] = '--out='.escapeshellarg($out);
        }

        $output = [];
        $status = 1;

        exec(implode(' ', $arguments).' 2>&1', $output, $status);

        return [$status, implode("\n", $output)];
    }

    public function test_the_script_exists_and_is_syntactically_valid(): void
    {
        $this->assertFileExists($this->script());

        $output = [];
        $status = 1;

        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($this->script()).' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
    }

    /**
     * The refusal, proved by running the script rather than by reading its source.
     *
     * The question is whether running it stops, and the only way to know that is to run it.
     */
    public function test_it_refuses_an_install_containing_a_real_env_file(): void
    {
        // `version.txt` is here because the build now refuses an install that was never finished, and
        // that check runs first. Without it these three tests were passing for the wrong reason: they
        // asserted that a forbidden file is refused, and were in fact being refused for a missing
        // stamp. One test per fault, so each failure names the fault.
        $install = $this->stageInstall([
            'version.txt' => $this->stampVersion(),
            'licences/node/manifest.json' => '{"packages": []}',
            '.env' => "APP_KEY=base64:thisIsNotARealKey\n",
        ]);

        [$status, $text] = $this->build($install);

        $this->assertSame(1, $status, 'the build must fail rather than package a real environment file');
        $this->assertStringContainsString('.env', $text, 'and it must name the file it found');
        $this->assertStringContainsString('APP_KEY', $text, 'and say why that file is dangerous');

        $this->cleanUp($install);
    }

    public function test_it_refuses_an_install_containing_a_live_reader_database(): void
    {
        $install = $this->stageInstall([
            'version.txt' => $this->stampVersion(),
            'licences/node/manifest.json' => '{"packages": []}',
            'database/database.sqlite' => 'not really sqlite, but a file in the wrong place',
        ]);

        [$status, $text] = $this->build($install);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('database.sqlite', $text);

        $this->cleanUp($install);
    }

    /**
     * The sidecars are the quieter half of the same leak.
     *
     * `database.sqlite-wal` holds rows not yet in the main file, so filtering only the main file would
     * still ship somebody's last few chapters. Three forbidden entries, not two.
     */
    public function test_it_refuses_the_database_sidecars_too(): void
    {
        foreach (['database/database.sqlite-wal', 'database/database.sqlite-shm'] as $sidecar) {
            $install = $this->stageInstall([
                'version.txt' => $this->stampVersion(),
                'licences/node/manifest.json' => '{"packages": []}',
                $sidecar => 'x',
            ]);

            [$status, $text] = $this->build($install);

            $this->assertSame(1, $status, "{$sidecar} must be refused");
            $this->assertStringContainsString(basename($sidecar), $text);

            $this->cleanUp($install);
        }
    }

    public function test_it_refuses_a_relative_install_root(): void
    {
        [$status, $text] = $this->build('build/install');

        $this->assertSame(1, $status);
        $this->assertStringContainsString('absolute', $text);
    }

    public function test_it_refuses_an_install_that_is_not_there(): void
    {
        [$status, $text] = $this->build('C:\\nowhere\\papyrus-install');

        $this->assertSame(1, $status);
        $this->assertStringContainsString('build-install', $text);
    }

    /**
     * Output inside the install makes each build package the previous one.
     *
     * Measured before this test existed: building into `<install>\dist` twice gave an entry count of
     * three then five, and the second archive genuinely contained the first archive as an entry.
     * Nothing warned. On the real install that is a 305MB file absorbing a copy of itself on every run,
     * reported as success throughout.
     */
    public function test_it_refuses_to_write_inside_the_install(): void
    {
        $install = $this->stageInstall([
            'version.txt' => $this->stampVersion(),
            'Papyrus.cmd' => "@echo off\r\n",
        ]);

        [$status, $text] = $this->build($install, $install.'/dist');

        $this->assertSame(1, $status, 'output inside the install must be refused');
        $this->assertStringContainsString('inside the install', $text);

        $this->cleanUp($install);
    }

    /**
     * An install that was cleared and abandoned is the one this must refuse.
     *
     * `build-install.php` rewrites the install directory in place, so it empties that directory and
     * then fills it again. A build killed part way through leaves a tree with none of the install in
     * it and no `version.txt`, because that file is written near the end.
     *
     * This used to package one anyway, because the version was read with a fallback:
     *
     *     $version = preg_match('/\d+\.\d+\.\d+/', $version, $m) === 1 ? $m[0] : '0.0.0';
     *
     * Measured on exactly that state, before this test existed:
     *
     *     files           12143 from the install, plus the readme
     *     size            70.8 MB
     *
     * against 12672 files and 623 MB for the same version. Nothing objected, because 12143 is a number
     * and this script had nothing to compare it against. The build reported DONE and wrote a hash.
     *
     * The fallback named the broken install rather than marking it: it produced
     * `Papyrus-0.0.0-portable.zip`, and one of those was sitting in `build/dist` three days later.
     */
    public function test_it_refuses_an_install_that_was_cleared_and_never_filled(): void
    {
        $install = $this->stageInstall([
            'artisan' => "<?php\n",
        ]);

        [$status, $text] = $this->build($install);

        $this->assertSame(1, $status, 'an install with no version.txt is not a finished install');
        $this->assertStringContainsString('version.txt', $text);
        $this->assertStringContainsString('build-install', $text, 'the message has to say what to run');

        $this->cleanUp($install);
    }

    /**
     * A tree built from last week's source must not ship under today's version number.
     *
     * `build-installer.php` already refuses this for the `.exe`, comparing the install's own stamp
     * against the repository's `VERSION`. This is the same mistake one stage later, and the portable
     * was the half that did not have the check: it would have taken whatever install was lying around
     * and named it after whatever the caller asked for.
     */
    public function test_it_refuses_an_install_built_from_another_version(): void
    {
        $install = $this->stageInstall([
            'version.txt' => '0.9.0',
            'Papyrus.cmd' => "@echo off\r\n",
        ]);

        [$status, $text] = $this->build($install);

        $this->assertSame(1, $status, 'a stale tree must not be packaged under the current version');
        $this->assertStringContainsString('0.9.0', $text, 'the message names what the install actually is');
        $this->assertStringContainsString('stale', $text);

        $this->cleanUp($install);
    }

    /**
     * A `version.txt` that is there but says nothing is not a licence to guess.
     *
     * The fallback this replaces produced `0.0.0` for this case too. The particular trouble with
     * `0.0.0` is that it looks like a real version: it sorts below every release, so it is easy to
     * mistake for a pre-release somebody meant to publish rather than for a build that did not finish.
     */
    public function test_it_refuses_a_version_file_that_holds_no_version(): void
    {
        $install = $this->stageInstall([
            'version.txt' => "this is not a version\n",
            'Papyrus.cmd' => "@echo off\r\n",
        ]);

        [$status, $text] = $this->build($install);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('does not hold a version', $text);
        $this->assertStringNotContainsString('0.0.0', $text, 'it must not quietly become a 0.0.0 release');

        $this->cleanUp($install);
    }

    /**
     * An install finished under the rules that predate the licence pass is not finished enough
     * to package.
     *
     * Without the manifest the licences page's npm rows are simply absent: the lock files those
     * declarations were derived from do not travel in an install, so there is no fallback and
     * nothing on the page says why. Packaging one used to be a success, which is the same shape
     * `0.0.0` was for a version: a number reported where an honest refusal belonged. One test per
     * fault, so this fixture carries a real stamp and is refused for the manifest alone.
     */
    public function test_it_refuses_an_install_that_carries_no_licence_manifest(): void
    {
        $install = $this->stageInstall([
            'version.txt' => $this->stampVersion(),
        ]);

        [$status, $text] = $this->build($install);

        $this->assertSame(1, $status, 'an install with no licence manifest ships a page with a gap in it');
        $this->assertStringContainsString('licences/node/manifest.json', $text);
        $this->assertStringContainsString('build-install', $text, 'the message has to say what to run');

        $this->cleanUp($install);
    }

    /**
     * A complete portable, opened afterwards by an independent reader.
     *
     * The staging tree is a handful of files rather than the real 12,651, because this is about
     * behaviour and a full zip takes a minute and a third of a gigabyte to prove the same thing. What
     * it does check is the part that is easy to get wrong: that a zip PHP says it wrote is a zip
     * another library agrees with, and that the readme made it in.
     */
    public function test_it_produces_a_readable_archive_with_the_readme_inside(): void
    {
        $install = $this->stageInstall([
            'version.txt' => $this->stampVersion(),
            'licences/node/manifest.json' => '{"packages": []}',
            'Papyrus.cmd' => "@echo off\r\nrem entry point\r\n",
            'app/Support/Licences.php' => "<?php // a representative file\r\n",
        ]);

        $out = $this->outputBeside().'/dist';

        [$status, $text] = $this->build($install, $out);

        $this->assertSame(0, $status, 'a clean install must build: '.$text);

        $zip = $out.'/Papyrus-'.$this->stampVersion().'-portable.zip';

        $this->assertFileExists($zip);

        // Opened fresh, rather than trusting the object that wrote it.
        $archive = new \ZipArchive;

        $this->assertTrue($archive->open($zip) === true, 'the archive must open as a zip');

        $names = [];

        for ($i = 0; $i < $archive->numFiles; $i++) {
            $names[] = $archive->getNameIndex($i);
        }

        $archive->close();

        $this->assertContains('PAPYRUS-PORTABLE.txt', $names, 'a portable has to explain itself');
        $this->assertContains('Papyrus.cmd', $names);
        $this->assertContains('app/Support/Licences.php', $names);
        $this->assertContains('licences/node/manifest.json', $names, 'the npm declarations travel with the install');

        // The hash sidecar, in the format both `sha256sum -c` and certutil accept.
        $sidecar = $zip.'.sha256';

        $this->assertFileExists($sidecar);

        $published = trim((string) file_get_contents($sidecar));

        $this->assertStringStartsWith(hash_file('sha256', $zip), $published, 'the sidecar must carry the real digest');
        $this->assertStringContainsString(basename($zip), $published);

        $this->cleanUp($install);
    }

    /**
     * Two builds of identical content must produce identical bytes.
     *
     * A zip whose hash changes every build makes "is this the file you published" unanswerable, which
     * quietly destroys the only verification a portable has, since a zip cannot be signed at all.
     *
     * Verified rather than assumed: two runs into sibling output directories produce byte-identical
     * archives, which is why the entry order is sorted before writing.
     */
    public function test_two_builds_of_the_same_content_produce_the_same_hash(): void
    {
        $install = $this->stageInstall([
            'version.txt' => $this->stampVersion(),
            'licences/node/manifest.json' => '{"packages": []}',
            'Papyrus.cmd' => "@echo off\r\n",
            'b/second.txt' => 'b',
            'a/first.txt' => 'a',
        ]);

        $archives = [];

        for ($run = 0; $run < 2; $run++) {
            $out = $this->outputBeside().'/out'.$run;

            [$status, $text] = $this->build($install, $out);

            $this->assertSame(0, $status, $text);

            $archives[] = $out.'/Papyrus-'.$this->stampVersion().'-portable.zip';
        }

        $first = $this->describeEntries($archives[0]);
        $second = $this->describeEntries($archives[1]);

        /*
         * Compared entry by entry, with contents and timestamp, rather than as two bare digests.
         *
         * This test failed about one run in fifteen and the difference was always the same thing: the
         * injected readme, whose content matched byte for byte and whose embedded timestamp was the
         * moment it was added. A bare digest mismatch says only "something differs" and sends the next
         * reader hunting; naming the entry turns the same failure into a diagnosis.
         *
         * Which is the second half of the lesson. The first time this failed I had a hunt to find the
         * cause, and the hunt was only necessary because the assertion had thrown the information away.
         */
        $this->assertSame(
            array_keys($first),
            array_keys($second),
            'two builds of identical content must hold the same entries in the same order',
        );

        foreach ($first as $name => $detail) {
            $this->assertSame(
                $detail,
                $second[$name] ?? '(absent)',
                sprintf('the entry %s differs between two builds of identical content', $name),
            );
        }

        $this->assertSame(
            hash_file('sha256', $archives[0]),
            hash_file('sha256', $archives[1]),
            'a reproducible build is what makes a published hash checkable',
        );

        $this->cleanUp($install);
    }

    /**
     * The claim in the source that a zip cannot be signed, asserted rather than trusted.
     *
     * If a future Windows accepts Authenticode on a container this stops being true and the advice in
     * the shipped readme is wrong. That is worth a test, because "it is impossible" is exactly the
     * sort of claim that quietly stops being impossible.
     */
    public function test_the_source_says_plainly_that_a_zip_cannot_be_signed(): void
    {
        $source = (string) file_get_contents($this->script());

        $this->assertStringContainsString('cannot carry an Authenticode signature', $source);
        $this->assertStringContainsString('not recognized', $source, 'the measured error is worth keeping');
    }

    // Helpers --------------------------------------------------------------

    /**
     * Every entry in an archive, as name to a description of its contents and timestamp.
     *
     * The timestamp is in the description on purpose. It is the field that actually differed when this
     * test was failing, and a description without it would have said "identical" about an entry that
     * produced different archive bytes.
     *
     * @return array<string, string>
     */
    private function describeEntries(string $archivePath): array
    {
        $archive = new \ZipArchive;

        if ($archive->open($archivePath) !== true) {
            $this->fail('could not open '.$archivePath.' to compare it');
        }

        $described = [];

        for ($i = 0; $i < $archive->numFiles; $i++) {
            $stat = $archive->statIndex($i);
            $contents = (string) $archive->getFromIndex($i);

            $described[(string) $stat['name']] = sprintf(
                '%d bytes, sha256 %s, mtime %s',
                $stat['size'],
                substr(hash('sha256', $contents), 0, 16),
                date('Y-m-d H:i:s', (int) $stat['mtime']),
            );
        }

        $archive->close();

        return $described;
    }

    /**
     * A directory beside the staged install, never inside it.
     *
     * This was a shared `sys_get_temp_dir()/out` to begin with, and it made the suite flaky: every test
     * wrote to the same place, `cleanUp` removed only the install, and the previous test's archive was
     * still on disk for the next one to walk into. It surfaced as a test that passed on one run and
     * failed on the next with no code change at all.
     *
     * So each test gets its own parent directory and everything under it is removed together.
     */
    private function outputBeside(): string
    {
        return $this->base.DIRECTORY_SEPARATOR.'out';
    }

    /**
     * @param  array<string, string>  $files
     */
    private function stageInstall(array $files): string
    {
        $this->base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-portable-test-'.bin2hex(random_bytes(6));

        // A named child, so the output has a legitimate sibling rather than a temporary directory
        // every other test is also writing into.
        $root = $this->base.DIRECTORY_SEPARATOR.'install';

        mkdir($root, 0o777, true);

        foreach ($files as $relative => $contents) {
            $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0o777, true);
            }

            file_put_contents($path, $contents);
        }

        return $root;
    }

    private function cleanUp(string $root): void
    {
        $target = $this->base ?? $root;

        $this->base = null;

        if (! is_dir($target)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($target);
    }

    /**
     * Cleanup that runs whether or not the test passed.
     *
     * Every test called cleanUp at its end, so a failing test never reached it and left its
     * staging directory in the temporary folder for the next run to walk into. That is how an
     * intermittent failure becomes a persistent one, and it is why the first version of this
     * file looked flaky for a reason nobody could see: the poisoning presented as flakiness.
     *
     * The cause of the original failure was not pinned. It appeared twice in five runs and
     * then not once in thirteen, which is the worst shape a failure can have, and the honest
     * position is that this makes the suite deterministic without claiming to explain it.
     */
    protected function tearDown(): void
    {
        if ($this->base !== null) {
            $this->cleanUp($this->base.DIRECTORY_SEPARATOR.'install');
        }

        parent::tearDown();
    }
}
