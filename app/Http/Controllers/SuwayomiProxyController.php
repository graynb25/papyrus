<?php

namespace App\Http\Controllers;

use App\Suwayomi\AuthManager;
use App\Suwayomi\SuwayomiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
     * Suwayomi addresses pages as manga/{mangaId}/chapter/{index}/page/{page},
     * covers as manga/{mangaId}/thumbnail, and extension icons as
     * extension/icon/{packageName}. Everything else - including the
     * CBZ export, which builds an entire zip in memory server-side
     * (Suwayomi-Server#2353) - is refused.
     *
     * Icons are here for the same reason covers are. The endpoint needs the bearer
     * token, and measured anonymous it answers 401, so the browser cannot ask Suwayomi
     * for one directly and a plain `<img>` pointed at the server would either show
     * nothing or put the token in the markup. An allowlist entry rather than a widened
     * rule, so extension install, settings mutation and raw GraphQL stay unreachable
     * from the browser exactly as before.
     */
    private const ALLOWED_PATH = '#^(manga/\d+(/chapter/\d+/page/\d+|/thumbnail)|extension/icon/[A-Za-z0-9._-]+)$#';

    /**
     * Query parameters forwarded upstream.
     *
     * format      - MIME transcode, e.g. webp or avif (Suwayomi serveConversions)
     * updateProgress - lets a bare <img> advance read state with no JS at all
     */
    private const ALLOWED_QUERY = ['format', 'updateProgress'];

    public function __invoke(Request $request, string $path, AuthManager $auth): StreamedResponse|Response
    {
        if (preg_match(self::ALLOWED_PATH, $path) !== 1) {
            /*
             * Plain text, and not `abort()`, for the same reason as the upstream failure below:
             * this route answers `<img>` requests, and an HTML error page is not a usable answer
             * to one. The status stays 404 because a refused path is a true statement about the
             * request rather than a failure to fetch anything.
             */
            return self::plain('Not a proxied resource.', 404);
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
            $response = $auth->attachToken(
                Http::withOptions(['stream' => true])
                    ->timeout((int) config('papyrus.suwayomi.timeout', 28))
            )->send('GET', $url);
        } catch (ConnectionException $e) {
            throw SuwayomiException::unavailable($e->getMessage(), $e);
        }

        /*
         * An upstream failure is answered as a gateway failure, in binary, with no layout.
         *
         * `abort()` was here, and it is the right tool for a web page: Laravel's handler
         * renders the application's own error page. This route is not serving a web page, it
         * is serving `<img>`, so every failed page image shipped the entire authenticated
         * layout, head and CSRF token and all, as the body of a resource the browser was
         * about to treat as a picture.
         *
         * The status was the second fault. Suwayomi answers 500 when the manga source behind a
         * page is blocked or down, and relaying that verbatim told the browser that Papyrus
         * had failed, when the app had done its job and something it depends on had not. A
         * gateway that reports a dead upstream as its own server error is misreporting where
         * the fault is, and it is why this surfaced as a console error on a gate whose subject
         * was the continue-reading feed rather than anything a blocked source was doing.
         *
         * 404 is passed through because it means something different and is worth keeping:
         * Suwayomi has no such page, which is a true statement about the request rather than a
         * failure to fetch one. Everything else is a 502, including a 5xx from Suwayomi and a
         * 4xx that is Suwayomi's complaint about something upstream of both of them.
         */
        if (! $response->successful()) {
            return self::plain(
                "Suwayomi could not serve this page.\n",
                $response->status() === 404 ? 404 : 502,
            );
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

    /**
     * A failure for a consumer that wanted bytes, not a document.
     *
     * `no-store` because a failed page image must not be cached: the browser caches a 502
     * eagerly enough that a source which recovers keeps rendering the broken image, and the
     * reader reloads to no effect.
     */
    private static function plain(string $message, int $status): Response
    {
        return response($message, $status, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
