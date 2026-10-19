<?php

namespace Tests\Unit;

use RuntimeException;
use Tests\TestCase;

/**
 * The removal seam every fixture borrows, tested against its one measured failure shape.
 *
 * Measured 2026-10-18: the old cleanup called unlink() on every registered path with the
 * error suppressed, unlink() cannot remove a directory that still holds files, and the
 * staging fixtures keep their contents by design, so every full suite run left its ten
 * fixture directories in the system temp directory, fifty measured across one day of runs.
 * The seam now walks the tree and then checks what it did, and both halves are required
 * here: a populated tree actually comes back gone, and a survivor is named, not silenced.
 */
class TestCaseScratchCleanupTest extends TestCase
{
    /**
     * A populated tree survives a test suite run only if the removal is silent about it;
     * this pins the removal itself.
     */
    public function test_a_populated_scratch_directory_comes_back_gone(): void
    {
        $dir = $this->scratchPath('removal-probe');
        file_put_contents($dir.DIRECTORY_SEPARATOR.'staged.txt', 'x');

        $nested = $dir.DIRECTORY_SEPARATOR.'nested';
        mkdir($nested, 0777, true);
        file_put_contents($nested.DIRECTORY_SEPARATOR.'deep.txt', 'x');

        $this->removeTemporaryFiles();

        $this->assertDirectoryDoesNotExist($dir);
    }

    /**
     * The refusal is the part the 82,457 file episode came from: removal that cannot finish
     * must say so instead of returning quietly. Measured on 2026-10-18: Windows does delete
     * a read-only file through unlink(), so a read-only file is not a stuck shape at all,
     * and the shape that actually holds a directory is an open handle on one of its files,
     * which makes unlink() refuse with permission denied while the handle lives. The message
     * then names the directory it could not clear.
     */
    public function test_a_scratch_that_cannot_be_removed_is_named(): void
    {
        $dir = $this->scratchPath('removal-probe');
        $stuck = $dir.DIRECTORY_SEPARATOR.'stuck.txt';
        file_put_contents($stuck, 'x');
        $handle = fopen($stuck, 'rb');

        try {
            $this->removeTemporaryFiles();
            $this->fail('the removal said nothing about the directory it could not clear');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('removal-probe-scratch', $e->getMessage(), 'the refusal names the survivor');
        } finally {
            fclose($handle);
            @unlink($stuck);
            @rmdir($dir);
        }
    }
}
