<?php

use App\Http\Controllers\ReadinessController;
use App\Http\Middleware\RegistrationMustBeAllowed;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
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
    })->create();
