<?php

use App\Http\Controllers\CbzExportController;
use App\Http\Controllers\CoverController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ReaderController;
use App\Http\Controllers\ReaderProgressController;
use App\Http\Controllers\ShowcaseController;
use App\Http\Controllers\SuwayomiProxyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * The entry point sends a reader where they are going: the library when signed
 * in, the sign-in screen when not. The starter kit's marketing page is gone:
 * a self-hosted reader who opened the app has no use for it.
 */
Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('library')
        : redirect()->route('login');
})->name('home');

/*
 * The language switch.
 *
 * Public because a reader who cannot read the sign-in screen has to be able to
 * change it before they have an account. A POST because it writes the session
 * and, when signed in, the reader's stored preference. The form posts and this
 * controller redirects, so the next response is rendered with the new locale and
 * the server-side `html lang` attribute.
 */
Route::post('locale', LocaleController::class)
    ->middleware('throttle:30,1')
    ->name('locale.update');

/*
 * The Reading Room's showcase: the login screen's cover wall. Public because
 * the reader it describes is signed out, and single-mode-gated because that
 * is the one deployment where the collection has one owner. See
 * ShowcaseController for the full reasoning, and config('papyrus.showcase')
 * for the off switch.
 *
 * The payload is throttled and the covers are not, and the asymmetry is measured rather than
 * tidier. Every rate-limited request writes: Laravel's limiter counts with `increment()`, and
 * `DatabaseStore::increment()` is a transaction that reads the counter and then updates it.
 * On SQLite that is a deferred transaction upgrading to a write, which trap 129 measures at
 * twelve of twelve reproductions failing in about 3ms, so `busy_timeout` and WAL cannot save
 * it. The login page asks for eighteen covers at once because that is what the wall is, so
 * the cover route was answering 6 of 72 as 500 in a browser, with the trace landing inside
 * `increment()`. Moving the counter to the file store removes the 500 and is worse:
 * `FileStore::increment()` is a read-modify-write with no lock, so it loses counts silently,
 * measured at 140 of 140 served against a declared 120.
 *
 * So the limit stays where one request per page load can afford a write, and the covers do not
 * carry one. They are gated to `single` mode, they serve an hour of public cache, and the
 * expensive thing they reach is a thumbnail on a loopback-bound server the reader owns.
 */
/*
 * The FAQ, public because the reader it is for cannot sign in.
 *
 * A reader who has just run an application from an unknown download and does not trust it is
 * precisely the person who most needs to read what it collects, and they are by definition not
 * logged in yet. Behind the login this page would answer the question for everybody except the one
 * person asking it.
 *
 * Throttled, like the showcase, for the same reason and the same measured asymmetry: a GET that
 * reads nothing expensive should not need a write to rate limit, and this one renders a static list
 * out of two PHP files.
 */
Route::livewire('faq', 'pages::faq')
    ->middleware('throttle:120,1')
    ->name('faq');

/*
 * The privacy page and the terms of service page, public for the same reason
 * the FAQ is.
 *
 * A reader who has just run an application from an unknown download and does
 * not trust it is exactly the person who most needs to read what it collects
 * and what it promises, and they are by definition not logged in yet. Behind
 * the login these two pages would answer the question for everybody except
 * the person asking it.
 *
 * Throttled, like the FAQ and for the same reason: a GET that reads nothing
 * expensive should not need a write to rate limit, and these render a static
 * document out of one PHP file.
 */
Route::livewire('privacy', 'pages::legal.privacy')
    ->middleware('throttle:120,1')
    ->name('privacy');

Route::livewire('terms', 'pages::legal.terms')
    ->middleware('throttle:120,1')
    ->name('terms');

Route::get('showcase', ShowcaseController::class)
    ->middleware('throttle:120,1')
    ->name('showcase.index');

Route::get('showcase/cover/{manga}', [ShowcaseController::class, 'cover'])
    ->whereNumber('manga')
    ->name('showcase.cover');

/*
 * Chapter page images, same origin as the app so the browser never holds a
 * Suwayomi token. The path allowlist lives in the controller - this is not a
 * general-purpose proxy to Suwayomi.
 */
Route::middleware(['auth'])->get('suwayomi/api/v1/{path}', SuwayomiProxyController::class)
    ->where('path', '.*')
    ->name('suwayomi.proxy');

Route::middleware(['auth', 'verified'])->group(function () {
    /*
     * The library is what the app is for, so it is where the dashboard goes.
     * Redirect rather than delete: the route name is in the sidebar and in every
     * saved link, and a 404 for a reader with the app open is worse than a
     * redirect.
     */
    Route::redirect('dashboard', 'library')->name('dashboard');

    Route::livewire('library', 'pages::library')->name('library');

    /*
     * Reading time, seen by series.
     *
     * Reads only rows its own reader wrote while a chapter was open and moving:
     * there is nothing to fetch here and nothing to send anywhere, which is said
     * plainly on the page rather than left to be discovered. Route order beside
     * the library because the two pages answer different questions about the
     * same collection and a reader moves between them both ways.
     */
    Route::livewire('insights', 'pages::insights')->name('insights');

    /*
     * The download centre.
     *
     * Reads this reader's ledger, which a background job keeps reconciled against
     * Suwayomi's shared queue every ten seconds. The page itself does not poll the
     * server for download state; it polls its own rows. See App\Downloads.
     */
    Route::livewire('downloads', 'pages::downloads')->name('downloads');

    /*
     * The CBZ export.
     *
     * A GET, not a POST, and deliberately: it is a download, and a reader should be able to
     * link to one, put it in a bookmark bar, or open it in a new tab. Nothing it changes is
     * state on this server, so there is nothing for a POST to protect.
     *
     * `name` is a query parameter rather than part of the path so the route stays keyed on the
     * chapter alone, and the controller sanitises it, because a filename arriving from a URL
     * ends up in a Content-Disposition header.
     *
     * Note what is not here: a permission gate. The route spends real CPU and disk on a
     * reader's behalf, which is what a toggle would be for, and `can:cbz.export` was tried
     * first. This app defines no gates, and Laravel denies an undefined ability to everyone,
     * so the "guard" would have been a 403 on every export. The cap in
     * `papyrus.downloads.cbz_export_max_bytes` is the guard that exists and is enforced.
     * A deployment switch is a small addition whenever someone wants one.
     *
     * Throttled to six a minute per signed-in reader. Each export can pull up to 256 MB
     * through Suwayomi and write it to the temp volume before the response starts, so a loop
     * starves every other reader in `shared` mode. The key is the reader, not the address,
     * because a household shares one address and six is already more than a bookmark bar
     * needs. See `docs/SECURITY.md` for the stated limit.
     */
    Route::get('downloads/chapter/{chapter}/cbz', CbzExportController::class)
        ->middleware('throttle:6,1')
        ->name('downloads.cbz');

    /*
     * The migration wizard.
     *
     * Separate from the series page because it is a different job: the series page
     * reads one series, and this one searches 82 of them. Measured 21.9 seconds, so
     * the search is behind a job and this route is where a reader waits on a poll
     * rather than on a request.
     *
     * Note what is absent. Suwayomi exposes no migration primitive, so there is no
     * "apply" endpoint here that the server would honour; the wizard composes the
     * move from a search, a chapter mapping and a state rewrite, and records what it
     * did because nothing upstream does.
     */
    /*
     * Moving a whole source at once.
     *
     * Registered BEFORE `migrate/{manga}`, and that ordering is load bearing rather than
     * incidental. The wizard route has no `whereNumber`, so it matches any segment after
     * `migrate/`, and Laravel takes the first match it finds. Registered after, this page would
     * be unreachable: every request for it would be handed to the wizard with `manga` set to
     * the string "sources".
     */
    Route::livewire('migrate/sources', 'pages::migrate-sources')->name('migrate.sources');

    Route::livewire('migrate/{manga}', 'pages::migrate')->name('migrate');

    /*
 * Livewire mount() arguments come from the route parameter name, so the single-file
 * component declares `mount($manga)` and reads the id itself. Without this the
 * container tries to resolve an int with no default and the page 500s.
 */
    Route::livewire('search', 'pages::search')->name('search');
    Route::livewire('sources', 'pages::sources')->name('sources');

    /*
     * One source's own catalogue: Popular and Latest.
     *
     * Search cannot reach every source. It caps at 40 targets, orders by name, and 61
     * of the 82 sources are named "MangaDex (...)", so the cut is alphabetical and
     * MangaFire, MangaTaro, Thunder Scans and Weeb Central were never searched at
     * all. This is the other door: a reader who wants one scanlation site picks it and
     * sees what it has.
     *
     * Bound to the mirror's own id rather than Suwayomi's, because the mirror is what
     * carries `is_installed` and `supports_latest`, and the page 404s on an uninstalled
     * source rather than rendering an empty grid.
     */
    Route::livewire('browse/{source}', 'pages::browse')->name('browse');

    Route::livewire('health', 'pages::health')->name('health');

    /*
     * One manga id, no source segment. Measured: manga ids are global, so
     * `manga(id:)` resolves with no source named and 174 is One Piece on
     * MangaDex while 76 is a Berserker title on Asura Scans.
     */
    Route::livewire('series/{manga}', 'pages::series')
        ->whereNumber('manga')
        ->name('series');

    Route::get('read/{manga}/{chapter}', ReaderController::class)
        ->whereNumber(['manga', 'chapter'])
        ->name('reader.show');

    /*
     * Progress ping from the reader. Debounced in the browser, and sendBeacon on
     * page close, so this accepts a form post as well as JSON.
     */
    Route::post('read/{manga}/{chapter}/progress', ReaderProgressController::class)
        ->whereNumber(['manga', 'chapter'])
        ->name('reader.progress');

    /*
     * Reader settings for one series. A separate endpoint rather than a query
     * parameter on the reader, because these are durable overrides that the
     * series page also reads and writes, not per-page view state.
     */
    Route::put('read/{manga}/settings', [ReaderController::class, 'updateSettings'])
        ->whereNumber('manga')
        ->name('reader.settings');

    /*
     * Colour filters, session-scoped by default.
     *
     * A separate route from the settings above, because the default is the opposite: a
     * reader dragging a brightness slider is adjusting for the light they are in right
     * now, and writing it to the series would follow them to a desk they are not
     * sitting at. "Remember" is a flag on the request rather than a third route, so
     * the client does not have to know which of two URLs means "keep this".
     *
     * The reader's own session, not a per-reader row: a temporary filter is not user
     * state, so it does not belong in a table a migration or an export would sweep.
     */
    Route::put('read/{manga}/filters', [ReaderController::class, 'updateFilters'])
        ->whereNumber('manga')
        ->name('reader.filters');

    /*
     * Drop every session filter, back to the durable settings.
     *
     * Separate from the update route rather than an empty body, because "set everything
     * to nothing" and "forget what I was overriding" are the same request from the
     * panel's point of view, and the first should not have to enumerate every key to
     * say so.
     */
    Route::delete('read/{manga}/filters', [ReaderController::class, 'clearFilters'])
        ->whereNumber('manga')
        ->name('reader.filters.clear');

    /*
     * The reader's note on the chapter it is showing.
     *
     * One PUT, and no DELETE. A note is a memo with one value, so clearing the box and
     * typing a new note are the same gesture from the reader's side of it, and an empty
     * body is the request that says "forget this". Two routes would make the client
     * decide which one the reader meant, and the reader is not the one making that
     * decision on purpose.
     *
     * Scoped to the chapter as well as the series, unlike settings and filters, which
     * are per series. A note is about one chapter, and the series page writes the same
     * row, so the two cannot disagree about what is on it.
     */
    Route::put('read/{manga}/{chapter}/note', [ReaderController::class, 'updateNote'])
        ->whereNumber(['manga', 'chapter'])
        ->name('reader.note');

    /*
     * A reader's own cover for a series.
     *
     * A GET that serves the bytes, behind the same auth as everything else. It is a route
     * rather than a file under `public/` because covers are per reader: the table is
     * unique on `(user_id, suwayomi_manga_id)`, and this project is multi-tenant from
     * migration 1, so a guessable public path would hand one reader's art to another.
     *
     * The upload itself is not here. It goes through the series page, which is Livewire,
     * so the browser gets upload progress for free and the same form posts to the same
     * place as the rest of that page's controls.
     */
    Route::get('covers/{manga}', [CoverController::class, 'show'])
        ->whereNumber('manga')
        ->name('covers.show');
});

require __DIR__.'/settings.php';

/*
 * The unmatched URL.
 *
 * A route fallback rather than only the 404 exception callback, because this
 * path runs the web middleware: the session is started and SetLocale can read
 * the reader's stored or guest language. The exception callback still covers
 * 404s raised by a matched route.
 */
Route::fallback(function (Request $request) {
    if ($request->expectsJson()) {
        abort(404);
    }

    return response()->view('errors.404', [], 404);
});
