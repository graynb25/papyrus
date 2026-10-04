<?php

namespace App\Downloads;

use RuntimeException;

/**
 * A refusal to export, carrying the reason and the numbers behind it.
 *
 * ## Why this is an exception and not a response
 *
 * The guard has to be decided before the first byte is written, which means before there is a
 * response to return: a streamed response cannot be withdrawn once it has begun. So the
 * decision is raised, the controller catches it, and the reader gets a page explaining the
 * limit. A 413 built as a response would read correctly in a controller and be impossible to
 * write at the point the guard runs.
 *
 * ## Why the numbers travel with it
 *
 * "Too large" is not actionable. Knowing the chapter is 340 MiB against a 256 MiB limit tells
 * a reader whether to raise the limit, export fewer chapters, or accept that this one is not
 * going to work, and those are three different decisions. The message is built here rather
 * than in the view so the same numbers cannot be phrased two ways on two pages.
 */
class CbzExportRefused extends RuntimeException
{
    /**
     * The pages are larger than the configured cap.
     */
    public const TOO_LARGE = 'too_large';

    /**
     * At least one page did not report a length, so the cap cannot be honoured.
     */
    public const SIZE_UNKNOWN = 'size_unknown';

    /**
     * @param  self::TOO_LARGE|self::SIZE_UNKNOWN  $reason
     */
    public function __construct(
        public readonly string $reason,
        public readonly int $chapterId,
        public readonly int $sizeBytes,
        public readonly int $maxBytes,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function tooLarge(int $chapterId, int $sizeBytes, int $maxBytes): self
    {
        return new self(
            self::TOO_LARGE,
            $chapterId,
            $sizeBytes,
            $maxBytes,
            'This chapter’s pages add up to '.self::human($sizeBytes)
            .', over the '.self::human($maxBytes).' export limit.'
        );
    }

    public static function unknownSize(int $chapterId, int $unknownPages, int $maxBytes): self
    {
        /*
         * Wording chosen carefully. "Could not be measured" would suggest a bug, and "too
         * large" would be a claim nobody has evidence for. Neither is true: the server simply
         * did not say, and the honest consequence is that the limit cannot be honoured, so
         * the export is declined rather than attempted and cut short.
         */
        return new self(
            self::SIZE_UNKNOWN,
            $chapterId,
            0,
            $maxBytes,
            $unknownPages === 1
                ? 'One page did not report its size, so the '
                    .self::human($maxBytes).' export limit cannot be honoured. Nothing was downloaded.'
                : $unknownPages.' pages did not report their size, so the '
                    .self::human($maxBytes).' export limit cannot be honoured. Nothing was downloaded.'
        );
    }

    /**
     * Bytes as something a reader can compare against a limit they have also been shown.
     */
    public static function human(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024 * 1024), 1).' GB';
        }

        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1).' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1).' kB';
        }

        return $bytes.' bytes';
    }
}
