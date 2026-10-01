<?php

namespace App\Http\Controllers;

use App\Covers\CoverStore;
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
            return response('', SymfonyResponse::HTTP_NOT_FOUND);
        }

        $file = $covers->read($cover);

        if ($file === null) {
            // The row outlived its file, which a database restore without the storage
            // directory produces. The page falls back to the source's cover, so a 404 is
            // the correct and quiet answer.
            return response('', SymfonyResponse::HTTP_NOT_FOUND);
        }

        return response($file['contents'], SymfonyResponse::HTTP_OK, [
            'Content-Type' => $file['mime'],

            /*
             * Private, and long.
             *
             * Private because the bytes belong to one reader, so a shared cache must not
             * keep them. Long because the URL is stable: replacing a cover overwrites the
             * same path, so a browser that already has it would show the old art for as
             * long as it cached it. That is why the name has no content hash in it and
             * why the cache window is short enough to be a nuisance rather than a lie.
             */
            'Cache-Control' => 'private, max-age=300',

            /*
             * The cover is bytes this reader chose and nothing else, so nothing in it
             * should be treated as a document. `nosniff` matters here more than usual
             * because the mime comes from the file rather than from an allowlist.
             */
            'X-Content-Type-Options' => 'nosniff',
            'Content-Length' => (string) strlen($file['contents']),
        ]);
    }
}
