<?php

namespace App\Support;

/**
 * The largest file this server will take through a normal upload.
 *
 * ## Two limits, and the smaller one decides
 *
 * PHP rejects a request before this app sees it when the body passes `post_max_size`,
 * and rejects one file when it passes `upload_max_filesize`. The reader-visible
 * ceiling is therefore the smaller of the two, because the error they get is about
 * whichever one they hit first.
 *
 * ## Zero means no limit, not zero bytes
 *
 * A bare `0` in `post_max_size` or a `-1` in `memory_limit` means unlimited. Treating
 * them as zero would make the upload page tell a reader their upload cannot work on a
 * server that allows any size at all, so an unlimited setting drops out of the
 * comparison rather than winning it with zero.
 *
 * ## Why this is a class rather than a method on the import page
 *
 * The page reports the ceiling, Livewire enforces it on its temporary upload endpoint,
 * and the two must agree. One parser means a value such as `256M` cannot mean two
 * different numbers in the two places that decide whether a backup gets in.
 */
final class UploadLimit
{
    /**
     * The ceiling from this process's own ini values, in bytes. Zero means no limit.
     */
    public static function bytes(): int
    {
        return self::fromIni(
            (string) ini_get('post_max_size'),
            (string) ini_get('upload_max_filesize'),
        );
    }

    /**
     * The smaller of two ini size strings, in bytes. Zero means no limit.
     *
     * Exposed as values rather than only through {@see bytes()} so the parsing has a
     * seam a test can exercise without the machine's own php.ini deciding the answer.
     */
    public static function fromIni(string $postMaxSize, string $uploadMaxFilesize): int
    {
        $limits = array_filter(
            [self::iniBytes($postMaxSize), self::iniBytes($uploadMaxFilesize)],
            fn (int $bytes): bool => $bytes > 0,
        );

        return $limits === [] ? 0 : min($limits);
    }

    /**
     * An ini size such as `8M` or `2G` into bytes. An unrecognised value reads as zero.
     */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $multiply = match (strtolower(substr($value, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return (int) $value * $multiply;
    }
}
