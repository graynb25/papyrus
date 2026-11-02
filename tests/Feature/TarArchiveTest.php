<?php

namespace Tests\Feature;

use Papyrus\Runtime\TarArchive;
use RuntimeException;
use Tests\TestCase;

require_once __DIR__.'/../../packaging/runtime/SystemTools.php';
require_once __DIR__.'/../../packaging/runtime/TarArchive.php';

/**
 * The tar fallback for a runtime without ext-zip, and the guard it was missing.
 *
 * ## What was broken
 *
 * The `ZipArchive` branch refused any entry containing `..` or starting with `/` before writing
 * anything. The `else` branch ran `tar -xf` with no member inspection, so a hostile archive could
 * write outside the unpack directory, up to the install root or the build machine's own
 * directories. Both archives are checked against a pinned SHA-256 first, which is why this is
 * defence in depth rather than a live hole, and defence in depth is exactly what a fallback should
 * not quietly lose.
 *
 * ## Why the archives are built here rather than checked in
 *
 * Because a hostile archive in the repository is a file a scanner has to explain to somebody, and
 * because the exact member shape is the thing under test. The builder writes the 512-byte header
 * by hand so a `../escape.txt` member is really in the archive rather than being normalised away
 * by the tool that made it.
 */
final class TarArchiveTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('the packaged build uses the Windows tar.exe this guard names');
        }

        $this->scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-tar-'.getmypid().'-'.uniqid();

        mkdir($this->scratch, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->scratch)) {
            $this->removeTree($this->scratch);
        }

        parent::tearDown();
    }

    public function test_a_safe_archive_extracts_to_the_destination(): void
    {
        $archive = $this->archive(['folder/file.txt' => 'hello']);

        $destination = $this->scratch.DIRECTORY_SEPARATOR.'safe';

        mkdir($destination, 0o777, true);

        TarArchive::extract($archive, $destination);

        $this->assertSame(
            'hello',
            (string) file_get_contents($destination.DIRECTORY_SEPARATOR.'folder'.DIRECTORY_SEPARATOR.'file.txt'),
        );
    }

    public function test_a_parent_directory_member_is_refused_before_extraction(): void
    {
        $archive = $this->archive(['../escape.txt' => 'should not exist']);

        $destination = $this->scratch.DIRECTORY_SEPARATOR.'guarded';

        mkdir($destination, 0o777, true);

        try {
            TarArchive::extract($archive, $destination);
            $this->fail('an archive with ../escape.txt must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('refusing to extract', $e->getMessage());
            $this->assertStringContainsString('../escape.txt', $e->getMessage());
        }

        $this->assertFileDoesNotExist(
            $this->scratch.DIRECTORY_SEPARATOR.'escape.txt',
            'the refusal has to happen before anything is written outside the destination',
        );

        $this->assertFileDoesNotExist(
            $destination.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'escape.txt',
            'and nothing was written through the parent either',
        );
    }

    public function test_an_absolute_member_is_refused_before_extraction(): void
    {
        $archive = $this->archive(['/tmp/papyrus-escape.txt' => 'should not exist']);

        $destination = $this->scratch.DIRECTORY_SEPARATOR.'absolute';

        mkdir($destination, 0o777, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refusing to extract');

        try {
            TarArchive::extract($archive, $destination);
        } finally {
            $this->assertFileDoesNotExist('/tmp/papyrus-escape.txt');
        }
    }

    public function test_a_drive_qualified_member_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refusing to extract');

        TarArchive::assertSafeMember('C:/escape.txt');
    }

    /**
     * The two checks the guard is made of, stated without an archive.
     */
    public function test_the_member_rules_cover_empty_parent_and_absolute_names(): void
    {
        foreach (['../escape.txt', 'folder/../../escape.txt', '/escape.txt', 'C:\\escape.txt', 'folder\\..\\escape.txt'] as $member) {
            try {
                TarArchive::assertSafeMember($member);
                $this->fail($member.' must be refused');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('refusing to extract', $e->getMessage());
            }
        }

        try {
            TarArchive::assertSafeMember('');
            $this->fail('an empty member name must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('refusing to extract', $e->getMessage());
        }

        TarArchive::assertSafeMember('folder/file.txt');
        $this->addToAssertionCount(1);
    }

    /**
     * Both extraction paths use the guard, rather than one of them keeping its own copy.
     */
    public function test_both_build_extraction_paths_go_through_the_guard(): void
    {
        $root = dirname(__DIR__, 2);

        $build = (string) file_get_contents($root.'/packaging/runtime/build-install.php');
        $stage = (string) file_get_contents($root.'/packaging/runtime/stage-runtime.php');

        $this->assertStringContainsString('TarArchive::extract($archive, $working)', $build);
        $this->assertStringContainsString('TarArchive::extract($archive, $staging)', $stage);

        $this->assertStringNotContainsString("'tar -xf", $build);
        $this->assertStringNotContainsString("'tar -xf", $stage);

        $archive = (string) file_get_contents($root.'/packaging/runtime/TarArchive.php');

        $this->assertStringContainsString('SystemTools::tar()', $archive, 'tar must be named by absolute path');
    }

    /**
     * Build a tar with one file member per entry, using forward slashes in the header.
     *
     * @param  array<string, string>  $members
     */
    private function archive(array $members): string
    {
        $tar = '';

        foreach ($members as $name => $contents) {
            $tar .= $this->header($name, strlen($contents));
            $tar .= $contents;
            $tar .= str_repeat("\0", (512 - (strlen($contents) % 512)) % 512);
        }

        $tar .= str_repeat("\0", 1024);

        $path = $this->scratch.DIRECTORY_SEPARATOR.'archive-'.bin2hex(random_bytes(4)).'.tar';

        file_put_contents($path, $tar);

        return $path;
    }

    private function header(string $name, int $size): string
    {
        $octal = static fn (int $value, int $width): string => str_pad(decoct($value), $width - 1, '0', STR_PAD_LEFT)."\0";

        $header = str_pad(substr($name, 0, 100), 100, "\0");
        $header .= $octal(0o644, 8);
        $header .= $octal(0, 8);
        $header .= $octal(0, 8);
        $header .= $octal($size, 12);
        $header .= $octal(time(), 12);
        $header .= str_repeat(' ', 8);
        $header .= '0';
        $header .= str_repeat("\0", 100);
        $header .= "ustar\0".'00';
        $header .= str_repeat("\0", 32);
        $header .= str_repeat("\0", 32);
        $header .= str_repeat("\0", 8);
        $header .= str_repeat("\0", 8);
        $header .= str_repeat("\0", 155);
        $header .= str_repeat("\0", 12);

        $sum = array_sum(array_map('ord', str_split($header)));
        $checksum = str_pad(decoct($sum), 6, '0', STR_PAD_LEFT)."\0 ";

        return substr_replace($header, $checksum, 148, 8);
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->removeTree($path.DIRECTORY_SEPARATOR.$entry);
        }

        @rmdir($path);
    }
}
