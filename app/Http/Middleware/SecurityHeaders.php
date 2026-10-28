<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the browser security headers to every response.
 *
 * ## Why this is global
 *
 * Because the risk is on every page, not only the ones that render a form: a framed page can
 * clickjack, a Referer can carry a reader's series URL to another site, and a response whose
 * content type is guessed can execute as something else. Registering it on the web group would
 * miss `/ready`, `/up`, the downloads and the cover and proxy responses.
 *
 * ## What each header buys
 *
 * - `Content-Security-Policy: frame-ancestors 'none'` refuses framing. It is the one CSP
 *   directive used, deliberately: a `default-src` policy needs an inventory of every script,
 *   style and image the reader pages load, and a policy written without that inventory breaks
 *   the app rather than hardening it.
 * - `X-Frame-Options: DENY` is the same refusal for a browser that does not read
 *   `frame-ancestors`.
 * - `Referrer-Policy: same-origin` keeps a reader's library and series URLs out of the
 *   `Referer` sent to AniList, a manga source, or any other site.
 * - `Permissions-Policy` turns off the device features the app never asks for.
 * - `X-Content-Type-Options: nosniff` stops a response from being reinterpreted as another
 *   type. The file-serving controllers already set it; this makes it hold for every response.
 *
 * HSTS is added only when the request arrived over HTTPS. Sending it on the default loopback
 * HTTP install would be ignored, and sending it for a host that later serves plain HTTP would
 * lock the reader out of it.
 *
 * ## Why exceptions apply it too
 *
 * Laravel renders an exception response outside the middleware pipeline, so a 404 or a 500
 * would otherwise be the one page without the headers. `bootstrap/app.php` registers the same
 * `apply()` from the exception handler's `respond` callback.
 */
class SecurityHeaders
{
    /**
     * The anti-framing directive, and nothing else.
     *
     * A fuller policy is a later decision that needs an inventory of every script, style and
     * image source. This directive is safe on its own because it does not fall back to
     * `default-src`.
     */
    private const CONTENT_SECURITY_POLICY = "frame-ancestors 'none'";

    /**
     * Device features no page in the app asks for.
     *
     * @var array<int, string>
     */
    private const PERMISSIONS = [
        'camera=()',
        'display-capture=()',
        'geolocation=()',
        'hid=()',
        'microphone=()',
        'payment=()',
        'serial=()',
        'usb=()',
    ];

    /**
     * One year, without `includeSubDomains` or `preload`.
     *
     * A local install may share a parent domain with something that is not HTTPS, and either
     * extra token would make this app's policy reach a host it does not own.
     */
    private const STRICT_TRANSPORT_SECURITY = 'max-age=31536000';

    public function handle(Request $request, Closure $next): Response
    {
        return self::apply($next($request), $request->isSecure());
    }

    /**
     * Put the headers on a response, wherever it was rendered.
     *
     * Shared with the exception handler so a page produced outside the middleware pipeline
     * cannot be the one response without them.
     */
    public static function apply(Response $response, bool $secure): Response
    {
        $response->headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', implode(', ', self::PERMISSIONS));
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        if ($secure) {
            $response->headers->set('Strict-Transport-Security', self::STRICT_TRANSPORT_SECURITY);
        }

        return $response;
    }
}
