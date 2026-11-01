<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Throttle the unauthenticated auth POSTs that Fortify registers without a limiter.
 *
 * Login already has Fortify's `login` limiter keyed on email and IP. Registration, the
 * forgot-password request, the reset submission and the password confirmation had no limit at
 * all, so one caller could spray distinct addresses, create accounts, or brute-force the
 * confirmation that guards passkeys and two-factor management.
 *
 * The key is the address only, not the email. Keying on the email would let a caller with many
 * addresses avoid the limit, which is the case this is for. The ceiling is deliberately loose
 * enough for a shared NAT: twenty auth POSTs a minute is more than a reader needs and far less
 * than a spray.
 *
 * A route-name check rather than a path pattern, for the same reason RegistrationMustBeAllowed
 * uses one: Fortify owns these routes, and a new auth route has to be added here deliberately
 * instead of becoming covered by accident.
 */
final class ThrottleAuthPosts
{
    /**
     * @var list<string>
     */
    private const ROUTES = [
        'login.store',
        'register.store',
        'password.email',
        'password.update',
        'password.confirm.store',
    ];

    private const PER_MINUTE = 20;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->routeIs(...self::ROUTES)) {
            return $next($request);
        }

        $key = 'auth-post:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            $seconds = RateLimiter::availableIn($key);

            throw new ThrottleRequestsException(
                'Too many attempts. Try again in '.$seconds.' seconds.',
                null,
                ['Retry-After' => $seconds],
            );
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
