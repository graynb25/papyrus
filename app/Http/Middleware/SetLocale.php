<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Apply the reader's language before any page renders.
 *
 * ## Why this is appended to the web group
 *
 * The session and the authenticated user are available by then, which are the
 * first two sources in the resolution order. Error pages rendered before
 * appended middleware, including 403, 404, 419, 429, 500, and 503, resolve
 * the locale from the exception path in `bootstrap/app.php` instead.
 *
 * ## Why every request sets the locale
 *
 * FrankenPHP keeps workers alive. Carbon and `Number` hold their locale in
 * static state, so a request that skipped the reset would inherit the previous
 * reader's language. {@see Locale::apply()} always runs, English included.
 */
final class SetLocale
{
    /**
     * Routes that answer bytes rather than copy.
     *
     * The Suwayomi proxy is the important one: a chapter is dozens of image
     * requests, and resolving a stored preference on each would add a
     * `user_settings` read to every page. The file and cover routes are the
     * same shape.
     *
     * @var list<string>
     */
    private const BYTE_ROUTES = [
        'backups.export',
        'covers.show',
        'downloads.cbz',
        'showcase.cover',
        'suwayomi.proxy',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs(...self::BYTE_ROUTES)) {
            return $next($request);
        }

        Locale::apply(Locale::resolve($request));

        return $next($request);
    }
}
