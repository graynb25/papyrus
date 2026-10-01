<?php

namespace App\Http\Middleware;

use App\Support\Deployment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses the registration endpoints when the deployment mode says there are none.
 *
 * ## Why this exists, and why it is a fix rather than a feature
 *
 * `Deployment::allowsRegistration()` has existed since the deployment modes were added and
 * has never been called. `single` mode is documented as disabling registration, and the
 * unit test proves the method returns false, so the suite was green while the app did the
 * opposite: `/register` answered 200 on a single-user install and anyone who could reach the
 * site could create an account.
 *
 * That combination is the worst one. A test asserting that a method returns false reads as
 * coverage of the behaviour and is not, because nothing routes through it. The only thing
 * that catches it is a test that makes a request.
 *
 * ## Why 404 rather than 403
 *
 * A 403 says the endpoint exists and you are not allowed. In a mode whose whole point is
 * that there is no registration, that is an invitation to try the other paths. A 404 says
 * nothing is here, which is the truth.
 *
 * ## Why the check is on route names
 *
 * Fortify registers its own routes from a service provider, so a route middleware cannot be
 * attached to them from `routes/web.php`. Matching the two resolved route names is explicit
 * about what is covered, and anything Fortify adds later is uncovered by default rather than
 * accidentally open.
 */
class RegistrationMustBeAllowed
{
    /**
     * The routes that create an account. Named rather than pattern-matched so a new
     * Fortify route has to be added here deliberately.
     *
     * @var array<int, string>
     */
    private const REGISTRATION_ROUTES = ['register', 'register.store'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs(...self::REGISTRATION_ROUTES)) {
            return $next($request);
        }

        if (app(Deployment::class)->allowsRegistration()) {
            return $next($request);
        }

        abort(404);
    }
}
