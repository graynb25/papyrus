<?php

namespace App\Http\Controllers;

use App\Covers\CoverStore;
use App\Support\Etag;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Serve a reader's own cover art.
 *
 * ## Why a route and not the public disk
 *
 * Covers are per reader. The `series_covers` table is unique on
 * `(user_id, suwayomi_manga_id)` and the file lives on the `local` disk, outside
 * `public/`, so a path under the web root would hand one reader's cover to another on a
 * multi-tenant install. This project is multi-tenant from migration 1, so that is not a
 * hypothetical.
 *
 * The cost argument for the public disk does not apply anyway: measured, source covers
 * are **already** served through a Laravel route (`route('suwayomi.proxy')`), and a
 * library page renders up to 60 of them. A custom cover on its own route is no new cost
 * class.
 */
class CoverController extends Controller
{
    /**
     * Serve this reader's cover for a series, or fall through to nothing.
     *
     * 404 rather than the source's cover when the row is missing. The page that renders
     * the URL is the thing that decides whether to fall back, so this route only ever
     * serves a custom cover and never has to know what the source's one looks like.
     */
    public function show(Request $request, int $manga, CoverStore $covers): SymfonyResponse
    {
        $cover = $covers->find($request->user(), $manga);

        if ($cover === null) {
            return self::absent();
        }

        $file = $covers->read($cover);

        if ($file === null) {
            // The row outlived its file, which a database restore without the storage
            // directory produces. The page falls back to the source's cover, so a 404 is
            // the correct and quiet answer.
            return self::absent();
        }

        /*
         * The validator is the bytes themselves. A file mtime was the first idea here
         * and lost on the seam it would have to be minted across: read() already holds
         * the contents in memory, so a digest costs no extra read, and replacing a
         * cover overwrites the same path with new bytes at nearly the same size, which
         * is exactly the change an mtime can miss at its granularity. A digest of what
         * the cover is cannot.
         */
        $etag = Etag::for($file['contents']);
        $cacheControl = 'private, max-age=300';

        if (Etag::matched($request->header('If-None-Match'), $etag)) {
            // The window comes back with the 304, so a validated cover also renews the
            // time the browser may keep using it without asking again.
            return response('', SymfonyResponse::HTTP_NOT_MODIFIED, [
                'ETag' => $etag,
                'Cache-Control' => $cacheControl,
            ]);
        }

        return response($file['contents'], SymfonyResponse::HTTP_OK, [
            'Content-Type' => $file['mime'],
            'ETag' => $etag,

            /*
             * Private because the bytes belong to one reader, so a shared cache must
             * not keep them. Short because the URL is stable: replacing a cover
             * overwrites the same path, so the window bounds how long a browser may
             * show old art without asking, and the ETag above is what makes every ask
             * after the window a validation of exactly those bytes rather than a
             * second copy of them.
             */
            'Cache-Control' => $cacheControl,

            /*
             * The cover is bytes this reader chose and nothing else, so nothing in it
             * should be treated as a document. `nosniff` matters here more than usual
             * because the mime comes from the file rather than from an allowlist.
             */
            'X-Content-Type-Options' => 'nosniff',
            'Content-Length' => (string) strlen($file['contents']),
        ]);
    }

    /**
     * A missing cover is a real answer a browser must not keep.
     *
     * Both 404 shapes (no row, and the row outlived its file, which a database restore
     * without the storage directory produces) carry it: with no directive at all,
     * whether a browser kept the 404 was left to heuristics, and holding a missing
     * answer hides the fallback the very page beside it is relying on.
     */
    private static function absent(): SymfonyResponse
    {
        return response('', SymfonyResponse::HTTP_NOT_FOUND, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
