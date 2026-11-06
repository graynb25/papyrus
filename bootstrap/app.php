<?php

use App\Http\Controllers\ReadinessController;
use App\Http\Middleware\RegistrationMustBeAllowed;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ThrottleAuthPosts;
use App\Support\Locale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * The environment file, named by the launcher.
 *
 * A packaged install writes its `.env` into the **data directory**, not into the install, because
 * the install directory is read only under Program Files and because an uninstall must not take
 * the reader's configuration with it. The launcher writes that file and then starts this
 * application, which by default looks for `.env` in its own base path and does not find it.
 *
 * The failure is silent and total. The app boots, has no `APP_KEY`, `/ready` answers 503 with
 * `"key": false`, and the launcher polls a permanently unready application until it gives up and
 * reports that the web server never started, while the server's own log says "server running".
 * Nothing anywhere names the cause, because every component is behaving exactly as written.
 *
 * So `PAPYRUS_ENV` is set by the launcher and honoured here. Falling back to the default keeps a
 * development checkout working unchanged, which is why this is a conditional rather than a
 * replacement.
 */
$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        /*
         * Readiness, registered outside every middleware group.
         *
         * `SESSION_DRIVER=database` means anything in the web group writes a session row per
         * request, and a launcher polls this at least once a second. A timer-driven write to
         * SQLite from a process whose whole job is to be idle is not a cost worth paying, so
         * this route gets no group at all. See ReadinessController for the rest.
         */
        then: function (): void {
            Route::get('ready', ReadinessController::class)->name('ready');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Enforces the deployment mode's registration rule.
         *
         * Appended to the web stack rather than aliased onto specific routes, because
         * Fortify registers its own from a service provider and a route middleware cannot
         * be attached to them from routes/web.php. The middleware itself only acts on two
         * named routes, so the cost on every other request is one `routeIs` call.
         */
        /*
         * The locale goes first in the appended list so a 404 from the
         * registration gate is rendered in the reader's language. A CSRF
         * failure happens before appended middleware, so the exception handler
         * handles that page separately.
         */
        $middleware->web(append: [
            SetLocale::class,
            RegistrationMustBeAllowed::class,
            ThrottleAuthPosts::class,
        ]);

        /*
         * Only the configured host and loopback are trusted, and the list is a closure rather
         * than an array because this file runs before the application exists: `config()` is
         * not available yet, but a closure is only called from the middleware per request.
         *
         * Password reset links used to be built from whatever Host header arrived, so a forged
         * one pointed the emailed token at an attacker. Absolute URLs are forced to `APP_URL`
         * in AppServiceProvider, and this list stops an unlisted host from being accepted in
         * the first place. The patterns are anchored because Symfony treats an unanchored
         * entry as a regular expression, where `localhost` would also match
         * `evil-localhost.example`.
         *
         * `subdomains: false` because the configured host is the install's own name, not a
         * domain whose children all belong to it. The two loopback forms stay so a reader who
         * opens the packaged install on `localhost` instead of `127.0.0.1` is not refused.
         */
        $middleware->trustHosts(
            at: static function (): array {
                $hosts = ['localhost', '127.0.0.1', '[::1]'];
                $configured = parse_url((string) config('app.url'), PHP_URL_HOST);

                if (is_string($configured) && $configured !== '') {
                    array_unshift($hosts, $configured);
                }

                return array_values(array_unique(array_map(
                    static fn (string $host): string => '^'.preg_quote($host, '/').'$',
                    $hosts,
                )));
            },
            subdomains: false,
        );

        /*
         * Global rather than web-grouped, because the headers belong on the responses the web
         * group never sees: `/ready`, `/up`, downloads, covers and the Suwayomi proxy. The
         * middleware also has to run last, so a controller that sets one of the same headers
         * cannot leave a page without the rest.
         */
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * A 419 is rendered before appended web middleware can set the locale,
         * because CSRF validation runs first. Resolve the locale here as well and
         * render the app's own page, so an expired form is not the one response
         * in the reader's language that stays English.
         *
         * JSON requests are left to Laravel: an API caller needs the status, not
         * the HTML page.
         */
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419 || $request->expectsJson()) {
                return null;
            }

            Locale::apply(Locale::resolve($request));

            return response()->view('errors.419', [], 419);
        });

        /*
         * An exception response is rendered outside the middleware pipeline, so without this a
         * 404 or a 500 would be the one response with no security headers. The callback shares
         * the middleware's `apply()` rather than repeating the header list.
         */
        $exceptions->respond(
            fn (Response $response, Throwable $e, Request $request): Response => SecurityHeaders::apply(
                $response,
                $request->isSecure(),
            ),
        );
    })
    ->create();

/*
 * The environment file, named by the launcher.
 *
 * Registered as an `afterLoadingEnvironment` hook rather than called directly, and the timing is
 * the entire point. Two earlier attempts both looked right and did nothing:
 *
 *   - calling `loadEnvironmentFrom` on the `ApplicationBuilder` that `Application::configure()`
 *     returns is a fatal, because the method is on `Application`;
 *   - calling it on the `Application` immediately afterwards is silently overwritten. Laravel's
 *     `LoadEnvironmentVariables` bootstrapper runs later and calls `setEnvironmentFilePath`,
 *     which resets the file to `<environmentPath>/.env.<APP_ENV>`, discarding what was set.
 *
 * So the only place a file chosen from outside `bootstrap/app.php` survives is after the
 * environment has been loaded. That is what this hook is for.
 *
 * A packaged install writes its `.env` into the **data directory**, not into the install, because
 * the install directory is read only under Program Files and because an uninstall must not take
 * the reader's configuration with it. The launcher writes that file and then starts this
 * application, which by default looks for `.env` in its own base path and does not find it.
 *
 * The failure is silent and total. The app boots, has no `APP_KEY`, `/ready` answers 503 with
 * `"key": false`, and the launcher polls a permanently unready application until it gives up and
 * reports that the web server never started, while the server's own log says "server running".
 * Nothing anywhere names the cause, because every component is behaving exactly as written.
 *
 * Left unset in a development checkout, which is why this is conditional rather than a
 * replacement. The dotenv repository is immutable by default in this framework version, so the
 * already-loaded values are replaced rather than merely skipped.
 */
$packagedEnvironment = getenv('PAPYRUS_ENV');

if (is_string($packagedEnvironment) && $packagedEnvironment !== '') {
    /*
     * Point the framework's own environment *path* at the data directory, rather than trying to
     * override the file afterwards.
     *
     * Three other approaches were tried first and all three fail, which is why this is worth
     * writing down rather than leaving as a one liner:
     *
     *   1. `loadEnvironmentFrom` on what `Application::configure()` returns. That is an
     *      `ApplicationBuilder`; the method is on `Application`. Fatal.
     *   2. `loadEnvironmentFrom` on the `Application` after `create()`. Runs, and is then
     *      silently discarded: `LoadEnvironmentVariables` bootstraps later and calls
     *      `setEnvironmentFilePath`, resetting the file to `<path>/.env.<APP_ENV>`.
     *   3. An `afterLoadingEnvironment` hook that writes the values into config. The hook runs
     *      before `LoadConfiguration`, so there is no `config` binding to write to yet, and it
     *      dies with "Target class [config] does not exist".
     *
     * Each failure was quiet or misleading, which is what makes this worth a comment. Setting the
     * path before the application is returned means the framework's own bootstrapper loads the
     * install's file as though it had always been there, which is both correct and the only point
     * in the sequence where it can be correct.
     *
     * The directory rather than the file, because `environmentFile()` appends `.<APP_ENV>` when
     * APP_ENV is set in the process environment. Naming the file would be undone by that.
     */
    if (is_file($packagedEnvironment)) {
        $app->useEnvironmentPath(dirname($packagedEnvironment));
    }

    /*
     * Storage goes to the data directory too, and for the same three reasons as the environment
     * file: the install may be read only under Program Files, an uninstall must not take the
     * reader's files with it, and the launcher collects logs from the data directory only.
     *
     * Without this the application's own log is written to `<install>/storage/logs/laravel.log`,
     * which is a directory the install may not be able to write to, which an uninstall deletes,
     * and which nothing collects. The install then looks healthy while the one file a bug report
     * needs is missing. Measured on a complete run: 0 files reached the data directory's storage
     * tree, all six directories of which `InstallLayout` had just created them.
     *
     * Keyed on the storage directory's existence rather than the `.env` file's, because the first
     * run has no `.env` yet. The launcher creates this tree before `papyrus:configure`, which has
     * to boot before it can write the file, and the portable archive carries no install storage to
     * fall back to. Measured on the 1.5.1 portable: the first launch failed inside
     * `papyrus:configure` with "Please provide a valid cache path" before any `.env` existed.
     *
     * Set next to `useEnvironmentPath` rather than in a service provider, because both have to be
     * in place before anything reads them, and because this file is the only place that runs
     * unconditionally for both a web request and an artisan command.
     */
    $dataDirectory = dirname($packagedEnvironment);

    if (is_dir($dataDirectory.DIRECTORY_SEPARATOR.'storage')) {
        $app->useStoragePath($dataDirectory.DIRECTORY_SEPARATOR.'storage');
    }
}

return $app;
