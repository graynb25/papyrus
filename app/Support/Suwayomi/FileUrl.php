<?php

namespace App\Support\Suwayomi;

use App\Downloads\CbzExporter;

/**
 * Joining a rooted path Suwayomi returns onto this app's configured base url.
 *
 * ## Measured, not assumed
 *
 * Exactly one slash has to survive the join. The first version used `':'` here, on the
 * assumption that the returned path omitted a separator the way this app's own proxy
 * builds one; it does not, and the result was
 * `http://127.0.0.1:4567:/api/v1/...`. Correcting that to a bare concatenation then gave
 * `http://127.0.0.1:4567api/v1/...`, because trimming the trailing slash off the host and
 * the leading slash off the path removes the separator along with them. Both attempts were
 * found by running the exporter that owned this join first; neither was visible by reading it.
 *
 * The join started in {@see CbzExporter} for page images and moved here when
 * the in-app backup needed the same door for the `.tachibk` file Suwayomi's own backup
 * mutation answers with, which is a rooted `/api/graphql/files/...` path built the same way.
 * A third attempt at writing this twice is how the third defect forms.
 */
final class FileUrl
{
    public static function absolute(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim((string) config('papyrus.suwayomi.url'), '/').'/'.ltrim($path, '/');
    }
}
