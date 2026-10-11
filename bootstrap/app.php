<?php

use App\Http\Controllers\ReadinessController;
use App\Http\Middleware\RegistrationMustBeAllowed;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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
        $middleware->web(append: [
            RegistrationMustBeAllowed::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
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
if (($packagedEnvironment = getenv('PAPYRUS_ENV')) !== false
    && $packagedEnvironment !== ''
    && is_file($packagedEnvironment)) {
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
    $app->useEnvironmentPath(dirname($packagedEnvironment));
}

return $app;
