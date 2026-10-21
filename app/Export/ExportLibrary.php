<?php

namespace App\Export;

use App\Backup\BackupLibrary;

/**
 * Where this install keeps its outward exports.
 *
 * ## Why it sits beside {@see BackupLibrary} rather than inside it
 *
 * BackupLibrary's folder is the launcher pair's home, and that class already owns the reason
 * the two things must not mix: a backup folder that grows files which are not pairs is a
 * folder a reader restores from by mistake. The exported `.tachibk` is a product for another
 * reader app, not one half of this install's pair, so it lives beside the database in its own
 * folder, resolved by the same rule: beside the database, because that is the location the
 * install has already proved writable and the launcher writes beside it too.
 */
final class ExportLibrary
{
    public function __construct(private readonly string $directory) {}

    /**
     * The exports folder for this install.
     *
     * The same resolution BackupLibrary takes, down to its `:memory:` fallback and the
     * reason for it: the test suite's `dirname()` is `.`, and the checkout's own working
     * folder must never take test products in.
     */
    public static function forThisInstall(): self
    {
        $database = (string) config('database.connections.sqlite.database');

        if ($database === '' || str_contains($database, ':memory:')) {
            return new self(base_path('exports'));
        }

        return new self(dirname($database).DIRECTORY_SEPARATOR.'exports');
    }

    /**
     * The folder the merged product is renamed into, once the file is whole.
     */
    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Where the export is staged, inside the product folder and on the same drive, so the
     * rename out is atomic and a half written file never looks like a product.
     */
    public function staging(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'staging';
    }
}
