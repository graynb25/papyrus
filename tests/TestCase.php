<?php

namespace Tests;

use FilesystemIterator;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Every path this test created in the system temp directory, removed when it finishes.
     *
     * ## Why this exists, and why it is 76,500 files
     *
     * `tempnam()` **creates a file**. That is its contract, not an accident, and almost every
     * fixture here used it as if it returned a name:
     *
     *     return tempnam(sys_get_temp_dir(), 'papyrus-fixture-').'.'.$suffix;
     *
     * which makes a second file, `something.tmb`, and abandons the first, `something.tmp`, forever.
     * Measured on this machine on 2026-10-15, in `%LOCALAPPDATA%\Temp`:
     *
     *     6,475  .tmb files, every one with an orphaned .tmp sibling of the same stem
     *     4,505  covXXXX.tmp.jpg files, each with an abandoned .tmp beside it
     *    70,576  zero-byte .tmp files that nothing had adopted or deleted
     *
     * About 76,500 files, oldest from 2026-10-09, none of them mine and all of them from this suite.
     * They are not harmless. `tempnam()` eventually stopped returning a path at all in that
     * directory, failing 20 times out of 20 while the directory reported itself writable, and that
     * took 22 tests down. The cause is the directory holding so many files of exactly the shape
     * `tempnam()` generates, and the limit is reached at a point nobody chose.
     *
     * ## Why a seam rather than fixing the eight call sites
     *
     * Because fixing eight call sites is how the ninth gets written. Every fixture that needs a
     * temporary file now asks for one here, and this removes whatever was made, whether the test
     * passed, failed or threw. The call sites stop having to remember, which was the thing that was
     * not being remembered.
     *
     * @var list<string>
     */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        $this->removeTemporaryFiles();

        parent::tearDown();
    }

    /**
     * Remove everything this test registered, and name anything that would not go.
     *
     * The previous body called unlink() on every registered path and suppressed the error.
     * unlink() cannot remove a directory that still holds files, and the suppression hid
     * exactly the refusal that mattered: the staging fixtures keep their contents by design,
     * so every full suite run left its fixture directories in the system temp directory,
     * fifty of them measured across one day of runs, the family that grew to 82,457 files
     * before it was first found. The removal walks the tree first, children before their
     * parents, and then checks what it did: anything still on disk is named in the exception
     * instead of being swept under a silence. One Windows specific measured detail sits in
     * the middle: the tree walker holds an open directory handle on the base, and rmdir()
     * refuses a directory with an open handle, so the walker is unset before the base is
     * asked to leave, otherwise the base is named a survivor in a clean removal.
     */
    protected function removeTemporaryFiles(): void
    {
        $survivors = [];

        foreach ($this->temporaryFiles as $path) {
            if (is_dir($path) && ! is_link($path)) {
                $members = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST,
                );

                foreach ($members as $member) {
                    if ($member->isDir()) {
                        @rmdir($member->getPathname());
                    } else {
                        @unlink($member->getPathname());
                    }
                }

                unset($members);
            }

            @unlink($path);
            @rmdir($path);

            if (is_file($path) || is_dir($path)) {
                $survivors[] = $path;
            }
        }

        $this->temporaryFiles = [];

        if ($survivors !== []) {
            throw new RuntimeException('the temporary files this test registered could not be removed: '.implode(', ', $survivors));
        }
    }

    /**
     * A path inside a fresh directory that is removed when this test finishes.
     *
     * The directory rather than a file, because a fixture that makes two files out of one call
     * cannot be cleaned up by remembering the one it meant to keep. A scratch directory can be
     * removed wholesale, so a fixture cannot leak by being clever.
     */
    protected function scratchPath(string $prefix = 'papyrus', string $suffix = ''): string
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.'-scratch-'.getmypid().'-'.bin2hex(random_bytes(6));

        if (! is_dir($base) && ! mkdir($base, 0o777, true) && ! is_dir($base)) {
            $this->fail('could not make a scratch directory at '.$base);
        }

        $this->temporaryFiles[] = $base;

        return $suffix === '' ? $base : $base.DIRECTORY_SEPARATOR.$suffix;
    }

    /**
     * An empty file inside a scratch directory, removed when this test finishes.
     *
     * The random part is hex. The first version put `random_bytes(4)` in the name directly, and
     * four random bytes contain a path separator and a null byte often enough that twenty two tests
     * failed with `No such file or directory` on paths like `papyrus-fixture-/<two bytes>.tmb`.
     * Hex costs eight more characters and cannot contain anything the filesystem objects to.
     */
    protected function temporaryFile(string $prefix = 'papyrus-fixture-', string $suffix = ''): string
    {
        $name = $prefix.bin2hex(random_bytes(4)).($suffix === '' ? '' : '.'.$suffix);
        $path = $this->scratchPath('papyrus-fixture', $name);

        if (file_put_contents($path, '') === false) {
            $this->fail('could not make a temporary file at '.$path);
        }

        return $path;
    }
}
