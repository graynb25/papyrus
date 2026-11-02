<?php

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * A filesystem that records the first lookup instead of answering it.
 *
 * The translator guard's job is to stop sentence shaped copy from becoming a file path. A real
 * filesystem hides the defect on this machine, where `file_exists` on a `?` filename returns false
 * without the packaged ini's `open_basedir`. Throwing on the first lookup makes a regression name
 * the exact path instead of passing as a missing translation.
 */
final class RefusingFilesystem extends Filesystem
{
    public int $existsCalls = 0;

    /**
     * @param  string  $path
     * @return bool
     */
    public function exists($path)
    {
        $this->existsCalls++;

        throw new RuntimeException("the translator asked the filesystem about [{$path}]");
    }
}
