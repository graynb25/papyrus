<?php

declare(strict_types=1);

namespace Papyrus\Launcher;

use RuntimeException;

/**
 * A minimal zip writer, because the bundled runtime cannot be assumed to have ext-zip.
 *
 * ## Why not ZipArchive
 *
 * `stage-runtime.php` already carries a comment saying it falls back because "most packaged PHP
 * builds" have no `ext-zip`. Building the bundle on an extension the packaged runtime probably
 * lacks would mean the feature that exists to help readers is the one thing that breaks on their
 * machine, and it would break by being absent rather than by saying so.
 *
 * The alternative was branching: ZipArchive when present, this otherwise. That is worse, because
 * two code paths means one of them is only ever exercised on one machine, and the one that only
 * runs on a reader's computer is the one nobody tested.
 *
 * ## Why this is small
 *
 * Because the format is. Every file is stored uncompressed with method 0, which needs a local file
 * header, a central directory entry and an end-of-central-directory record. The diagnostics
 * bundle is a few hundred kilobytes of text, so compressing it buys almost nothing and would buy
 * it at the cost of a chunked deflate implementation written by hand.
 *
 * It is verified against the real implementation: the tests write a zip with this class and open
 * it with `ZipArchive`, so "it produces a valid zip" is a checked claim rather than a belief.
 */
final class ZipWriter
{
    /** @var array<int, array{name: string, offset: int, crc: int, size: int}> */
    private array $entries = [];

    private string $body = '';

    /**
     * Add one file, stored uncompressed.
     *
     * @param  string  $name  the path inside the archive, with forward slashes
     *
     * @throws RuntimeException when a file cannot be read
     */
    public function addFile(string $name, string $source): void
    {
        if (! is_file($source)) {
            throw new RuntimeException('cannot read '.$source.' for the bundle');
        }

        $contents = @file_get_contents($source);

        if (! is_string($contents)) {
            throw new RuntimeException('cannot read '.$source.' for the bundle');
        }

        $this->add($name, $contents);
    }

    /**
     * Add one file from a string, stored uncompressed.
     *
     * The bytes are never longer than they need to be, because a bundle holds logs and text, and
     * an empty `summary.txt` is still worth having in the archive so a reader can see it was
     * collected.
     */
    public function add(string $name, string $contents): void
    {
        $offset = strlen($this->body);
        $crc = crc32($contents);
        $size = strlen($contents);

        $this->entries[] = ['name' => $name, 'offset' => $offset, 'crc' => $crc, 'size' => $size];

        $this->body .= pack('V', 0x04034B50)          // local file header signature
            .pack('v', 10)                            // version needed to extract, 1.0
            .pack('v', 0)                             // general purpose flags
            .pack('v', 0)                             // compression method, stored
            .pack('v', 0)                             // last modified time, not set
            .pack('v', 0x21)                          // last modified date, 1 January 1980
            .pack('V', $crc)
            .pack('V', $size)
            .pack('V', $size)
            .pack('v', strlen($name))
            .pack('v', 0)                             // extra field length
            .$name
            .$contents;
    }

    /**
     * The finished archive.
     *
     * Timestamps are fixed at the start of the zip epoch rather than at "now" so the same inputs
     * produce the same archive. A bundle that changes every time it is written cannot be compared
     * against the last one, and the first thing anyone asks when two bundles differ is whether
     * anything changed at all.
     */
    public function finish(): string
    {
        $directory = '';

        foreach ($this->entries as $entry) {
            $directory .= pack('V', 0x02014B50)       // central directory header signature
                .pack('v', 0x031E)                     // version made by, UNIX, zip 3.0
                .pack('v', 10)                         // version needed to extract
                .pack('v', 0)                          // general purpose flags
                .pack('v', 0)                          // compression method, stored
                .pack('v', 0)                          // last modified time
                .pack('v', 0x21)                       // last modified date
                .pack('V', $entry['crc'])
                .pack('V', $entry['size'])
                .pack('V', $entry['size'])
                .pack('v', strlen($entry['name']))
                .pack('v', 0)                          // extra field length
                .pack('v', 0)                          // file comment length
                .pack('v', 0)                          // disk number start
                .pack('v', 0)                          // internal file attributes
                .pack('V', 0x81A40000)                 // external attributes, 0644 regular file
                .pack('V', $entry['offset'])
                .$entry['name'];
        }

        $count = count($this->entries);

        return $this->body.$directory.pack('V', 0x06054B50)   // end of central directory
            .pack('v', 0)
            .pack('v', 0)
            .pack('v', $count)
            .pack('v', $count)
            .pack('V', strlen($directory))
            .pack('V', strlen($this->body))
            .pack('v', 0);
    }

    /** @return array<int, string> */
    public function names(): array
    {
        return array_column($this->entries, 'name');
    }
}
