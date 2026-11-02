<?php

namespace Papyrus\Runtime;

use RuntimeException;

/**
 * The tar fallback for a PHP without ext-zip, with the guard the zip path already has.
 *
 * ## Why the listing happens before extraction
 *
 * The `ZipArchive` branch refuses a member containing `..` or starting with `/` before writing
 * anything. The tar branch ran `tar -xf` with no member inspection, so an archive that reached
 * it could write outside the unpack directory, up to the install root or the build machine's own
 * directories. Both archives are checked against a pinned SHA-256 first, which is why this is
 * defence in depth rather than a live hole, and defence in depth is exactly what a fallback
 * should not quietly lose.
 *
 * `tar -tf` lists members without writing them, so a hostile archive is refused before the first
 * file exists.
 */
final class TarArchive
{
    /**
     * Refuse an archive with an unsafe member, then extract it.
     *
     * The guard is a method of its own so the listing can be tested without writing anything.
     */
    public static function extract(string $archive, string $destination): void
    {
        $tar = SystemTools::tar();

        self::assertSafe($archive, $tar);

        $output = [];
        $status = 1;

        exec(sprintf(
            '%s -xf %s -C %s 2>&1',
            escapeshellarg($tar),
            escapeshellarg($archive),
            escapeshellarg($destination),
        ), $output, $status);

        if ($status !== 0) {
            throw new RuntimeException('tar could not read the archive: '.implode("\n", $output));
        }
    }

    /**
     * List the archive and refuse the first member that could escape the destination.
     */
    public static function assertSafe(string $archive, ?string $tar = null): void
    {
        $tar ??= SystemTools::tar();

        $listing = [];
        $status = 1;

        exec(sprintf(
            '%s -tf %s 2>&1',
            escapeshellarg($tar),
            escapeshellarg($archive),
        ), $listing, $status);

        if ($status !== 0) {
            throw new RuntimeException('tar could not list the archive: '.implode("\n", $listing));
        }

        foreach ($listing as $member) {
            self::assertSafeMember(rtrim((string) $member, "\r\n"));
        }
    }

    /**
     * One member, as the archive lists it.
     *
     * Normalised to forward slashes first, because tar on Windows can list either spelling and a
     * check that only knows one of them is a check with a second way past it. A drive-qualified
     * path is refused as well: `C:\escape.txt` is absolute even though it does not start with `/`.
     */
    public static function assertSafeMember(string $member): void
    {
        $normalised = str_replace('\\', '/', $member);

        if ($member === ''
            || str_contains($normalised, '..')
            || str_starts_with($normalised, '/')
            || preg_match('#^[A-Za-z]:/#', $normalised) === 1) {
            throw new RuntimeException('refusing to extract '.$member);
        }
    }
}
