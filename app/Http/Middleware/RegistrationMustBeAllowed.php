<?php

namespace App\Http\Middleware;

use App\Support\Registration;
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
 *
 * ## Why showing the form and accepting the submission are different questions
 *
 * Because `single` mode opens registration only while nobody has registered, so the two endpoints
 * have genuinely different answers. GET is a question about now: if the install is unclaimed, show
 * the form. POST is a question about taking the one account, and asking it twice at once is how a
 * single-reader install ends up with two readers, which breaks the invariant the mode is named for.
 *
 * So the form checks whether registration is open, and the submission tries to claim the install. A
 * check followed by a use is not atomic, and the claim is what closes that gap.
 *
 * ## Why it consults `Registration` and not `Deployment` again
 *
 * Because the login page asks the same question to decide whether to offer the link, and those two
 * answers must not be able to disagree. This project has already shipped that defect once: two
 * implementations of one question, disagreeing for the life of a feature with every test green. See
 * lesson 227.
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

        $registration = app(Registration::class);

        // The submission has to win the install, not merely observe that it is unclaimed.
        $allowed = $request->routeIs('register.store')
            ? $registration->claim()
            : $registration->isOpen();

        if ($allowed) {
            return $next($request);
        }

        abort(404);
    }
}
