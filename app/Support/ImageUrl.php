<?php

namespace App\Support;

/**
 * Turns Suwayomi's image paths into same-origin proxy URLs.
 *
 * Two shapes exist and they are not interchangeable:
 *
 *   /api/v1/manga/76/thumbnail              search results and series metadata
 *   /api/v1/manga/76/chapter/1/page/0       chapter pages
 *
 * Both are root-relative and both ignore any subpath the server is mounted at,
 * which is why `Gateway::resolvePageUrls()` exists for the page case. Covers need
 * the same treatment and had none, so a series page would have hotlinked the
 * upstream host directly and leaked the request path.
 *
 * The proxy allowlist is `manga/\d+(/chapter/\d+/page/\d+|/thumbnail)$`, which
 * covers exactly these two shapes and nothing else. Anything that does not match
 * is returned as null rather than passed through, so a surprising path from a
 * future server version fails closed instead of becoming a new capability.
 */
final class ImageUrl
{
    /**
     * A chapter page image.
     *
     * Page numbers are zero-based, matching Suwayomi's own addressing, so page 0
     * is the first page. Negative numbers are refused rather than clamped: a
     * negative index is a bug somewhere upstream and silently showing page 0
     * would hide it.
     */
    public static function page(int $mangaId, int $chapterId, int $page, ?string $format = null, ?int $width = null, ?int $height = null): ?string
    {
        if ($mangaId <= 0 || $chapterId <= 0 || $page < 0) {
            return null;
        }

        return self::proxy(
            sprintf('manga/%d/chapter/%d/page/%d', $mangaId, $chapterId, $page),
            $format,
            $width,
            $height,
        );
    }

    /**
     * A series or manga cover thumbnail.
     *
     * Null for a non-positive id. Suwayomi's LocalSource.ID is 0, so a zero here
     * means "no series" rather than "the first series", and the allowlist's \d+
     * would happily build a URL for it.
     */
    public static function thumbnail(int $mangaId, ?string $format = null, ?int $width = null, ?int $height = null): ?string
    {
        if ($mangaId <= 0) {
            return null;
        }

        return self::proxy("manga/{$mangaId}/thumbnail", $format, $width, $height);
    }

    /**
     * Wrap a raw Suwayomi path from a payload.
     *
     * Takes the value as the server sends it, which may be a root-relative path,
     * an absolute URL to the server, or already an app URL, and returns null for
     * anything outside the allowlist.
     */
    public static function fromServerValue(mixed $value, ?string $format = null, ?int $width = null, ?int $height = null): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        // Strip any origin the server believed it was mounted at, then reduce to
        // the /api/v1-relative remainder so the allowlist can judge it.
        $path = preg_replace('#^https?://[^/]+#', '', $value) ?? $value;
        $path = preg_replace('#^.*?/api/v1/#', '', $path) ?? $path;

        return self::proxy(ltrim($path, '/'), $format, $width, $height);
    }

    /**
     * The configured transcoding format, or null to serve whatever the source has.
     */
    public static function defaultFormat(): ?string
    {
        $format = config('papyrus.reader.default_image_format');

        return is_string($format) && $format !== '' ? $format : null;
    }

    /**
     * Build a responsive srcset from the same URL at a handful of widths.
     */
    public static function responsiveSet(string $url, array $widths = [320, 480, 640, 960, 1200]): string
    {
        return implode(', ', array_map(
            static fn (int $width): string => $url.(str_contains($url, '?') ? '&' : '?').'width='.$width.' '.$width.'w',
            $widths,
        ));
    }

    /**
     * Build the proxy URL, or null when the path is not one we forward.
     */
    private static function proxy(string $path, ?string $format = null, ?int $width = null, ?int $height = null): ?string
    {
        // The same allowlist the controller enforces, so a URL that renders is a
        // URL that can be fetched. Duplicated deliberately rather than shared: the
        // controller must not depend on app/Support to decide what is safe.
        if (preg_match('#^manga/\d+(/chapter/\d+/page/\d+|/thumbnail)$#', $path) !== 1) {
            return null;
        }

        $url = route('suwayomi.proxy', ['path' => $path]);
        $query = [];

        if ($format !== null && $format !== '') {
            $query['format'] = $format;
        }

        if ($width !== null && $width > 0) {
            $query['width'] = $width;
        }

        if ($height !== null && $height > 0) {
            $query['height'] = $height;
        }

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        return $url;
    }
}
