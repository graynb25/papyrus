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
        $install = $this->stageInstall(['.env' => "APP_KEY=base64:thisIsNotARealKey\n"]);

        [$status, $text] = $this->build($install);

        $this->assertSame(1, $status, 'the build must fail rather than package a real environment file');
        $this->assertStringContainsString('.env', $text, 'and it must name the file it found');
        $this->assertStringContainsString('APP_KEY', $text, 'and say why that file is dangerous');

        $this->cleanUp($install);
    }

    public function test_it_refuses_an_install_containing_a_live_reader_database(): void
    {
        $install = $this->stageInstall([
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
            $install = $this->stageInstall([$sidecar => 'x']);

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
            'version.txt' => '1.2.0',
            'Papyrus.cmd' => "@echo off\r\n",
        ]);

        [$status, $text] = $this->build($install, $install.'/dist');

        $this->assertSame(1, $status, 'output inside the install must be refused');
        $this->assertStringContainsString('inside the install', $text);

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
            'version.txt' => '1.2.0',
            'Papyrus.cmd' => "@echo off\r\nrem entry point\r\n",
            'app/Support/Licences.php' => "<?php // a representative file\r\n",
        ]);

        $out = $this->outputBeside().'/dist';

        [$status, $text] = $this->build($install, $out);

        $this->assertSame(0, $status, 'a clean install must build: '.$text);

        $zip = $out.'/Papyrus-1.2.0-portable.zip';

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
            'version.txt' => '1.2.0',
            'Papyrus.cmd' => "@echo off\r\n",
            'b/second.txt' => 'b',
            'a/first.txt' => 'a',
        ]);

        $hashes = [];

        for ($run = 0; $run < 2; $run++) {
            $out = $this->outputBeside().'/out'.$run;

            [$status, $text] = $this->build($install, $out);

            $this->assertSame(0, $status, $text);

            $hashes[] = hash_file('sha256', $out.'/Papyrus-1.2.0-portable.zip');
        }

        $this->assertSame(
            $hashes[0],
            $hashes[1],
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
