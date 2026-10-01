<?php

namespace App\Http\Controllers;

use App\Suwayomi\AuthManager;
use App\Suwayomi\SuwayomiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams Suwayomi page images through the app on the same origin.
 *
 * The original plan assumed Herd's nginx could reverse-proxy /suwayomi/ and
 * keep PHP out of the image path. It cannot: Herd's config is a single
 * catch-all server block that rewrites *everything*, static assets included,
 * to valet/server.php. There is no per-site server block to add a location to,
 * and PHP is already in the path for every request. So the proxy lives here.
 *
 * Two things this must never become:
 *
 *  - An open relay to Suwayomi's admin surface. Only chapter page images are
 *    allowed through; extension install, settings mutation and raw GraphQL are
 *    not reachable from the browser.
 *  - A memory sink. A long webtoon chapter is hundreds of multi-megabyte
 *    images, so the body is streamed in chunks rather than buffered.
 */
class SuwayomiProxyController extends Controller
{
    /**
     * Paths this proxy will forward, relative to /api/v1.
     *
     * Suwayomi addresses pages as manga/{mangaId}/chapter/{index}/page/{page}
     * and covers as manga/{mangaId}/thumbnail. Everything else - including the
     * CBZ export, which builds an entire zip in memory server-side
     * (Suwayomi-Server#2353) - is refused.
     */
    private const ALLOWED_PATH = '#^manga/\d+(/chapter/\d+/page/\d+|/thumbnail)$#';

    /**
     * Query parameters forwarded upstream.
     *
     * format      - MIME transcode, e.g. webp or avif (Suwayomi serveConversions)
     * updateProgress - lets a bare <img> advance read state with no JS at all
     */
    private const ALLOWED_QUERY = ['format', 'updateProgress'];

    public function __invoke(Request $request, string $path, AuthManager $auth): StreamedResponse
    {
        if (preg_match(self::ALLOWED_PATH, $path) !== 1) {
            abort(404, 'Not a proxied resource.');
        }

        $url = rtrim((string) config('papyrus.suwayomi.url'), '/').'/api/v1/'.$path;

        // Only the query parameters we actually use are forwarded. Anything
        // else a caller appends is dropped, so the proxy cannot be steered into
        // a different behaviour by a crafted URL.
        $query = array_intersect_key($request->query(), array_flip(self::ALLOWED_QUERY));

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        try {
            $response = Http::withToken($auth->token())
                ->withOptions(['stream' => true])
                ->timeout((int) config('papyrus.suwayomi.timeout', 28))
                ->send('GET', $url);
        } catch (ConnectionException $e) {
            throw SuwayomiException::unavailable($e->getMessage(), $e);
        }

        if (! $response->successful()) {
            abort($response->status(), 'Suwayomi could not serve this page.');
        }

        $body = $response->toPsrResponse()->getBody();

        $headers = [
            'Content-Type' => $response->header('Content-Type') ?: 'application/octet-stream',
            // Suwayomi already sets a day-long max-age. Reuse it so the browser
            // does not refetch pages it has already read.
            'Cache-Control' => $response->header('Cache-Control') ?: 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (($length = $response->header('Content-Length')) !== '') {
            $headers['Content-Length'] = $length;
        }

        return response()->stream(function () use ($body): void {
            // Chunked so a 400-page chapter never materialises in memory.
            while (! $body->eof()) {
                echo $body->read(64 * 1024);
                flush();
            }
        }, 200, $headers);
    }
}
