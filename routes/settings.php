<?php

use App\Http\Controllers\AniListCallbackController;
use App\Http\Controllers\ExportDownloadController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');

    /*
     * Importing another reader app's backup.
     *
     * Under `auth` and not `verified`, unlike everything below, and the difference is
     * deliberate. Every other page here changes an account: a password, a theme, a
     * connection. This one reads a file and writes a library, and a reader who has just signed
     * up and cannot yet receive a mail is exactly the reader who is migrating off another app.
     *
     * There is nothing here to escalate for. The importer is handed the signed in user, never
     * one named in the request, so an unverified account can only ever write to its own
     * library. Placed in this group because it is an import, not an account change.
     */
    Route::livewire('settings/import', 'pages::settings.import')->name('import.edit');

    /*
     * What this install is, and what it is running.
     *
     * Here, behind `auth` and not `verified`, and the reason is the opposite of the import page
     * directly above. That one is below verification because a reader who has just signed up and
     * cannot receive a mail is exactly the one migrating off another app. This one reads nothing
     * about the reader at all: it is the Papyrus version, the Suwayomi version, and whether the two
     * are compatible. There is no reader data on the page, so there is nothing to escalate for, and
     * putting it behind verification would hide two version numbers from exactly the people most
     * likely to be filing a first-run report.
     */
    /*
     * What can be restored, and what the last run from any door did.
     *
     * Behind `auth` and not `verified`, beside `about` and for the same reason: it reads the
     * filesystem and nothing about the reader. There is no reader data on the page, so there is
     * nothing to escalate for, and a reader who has just signed up and cannot yet receive a mail is
     * exactly the one most likely to be wondering whether their first backup happened.
     *
     * The page takes pairs itself now, through the live backup and the live restore, and keeps
     * the launcher's commands named beside them as the doors that stop Papyrus to run. Nothing
     * here stops anything: a backup is taken while everything keeps running, and the refusals
     * it can meet are named on the page in the run rows, not in this comment.
     */
    Route::livewire('settings/backups', 'pages::settings.backups')->name('backups.edit');

    /*
     * The download one finished export run offers.
     *
     * A GET beside the backups page whose button presses it, in that page's own group rather
     * than the verified one below: the reader standing at `settings/backups` pressing the
     * export is already past this seam, and the page's own reason for sitting under plain
     * `auth` is this one's too. Nothing the request changes is state on this server, the
     * file having been written by a finished run before the request arrived, so a GET is
     * the whole shape and a reader can bookmark a completion or open it in a new tab the
     * way the cbz export's route comment argues in `routes/web.php`.
     *
     * Keyed on the run and never on a file name, which is the decision the controller's
     * own docblock carries in full: the exports folder is a product folder, and only a
     * finished row's receipt makes one of its products reachable at all.
     */
    Route::get('settings/backups/export/{run}', ExportDownloadController::class)
        ->whereNumber('run')
        ->name('backups.export');

    Route::livewire('settings/about', 'pages::settings.about')->name('about.edit');
    Route::livewire('settings/licences', 'pages::settings.licences')->name('licences.edit');

    /*
     * The same questions the public FAQ page answers, inside the app.
     *
     * The public page stays public and stays where it is. A reader who has just run an
     * application from an unknown download is, by definition, not signed in yet, and that page
     * is the one somebody else can be sent to. But the Settings navlist's FAQ item pointed at
     * it, so a reader who opened Settings to ask a question was thrown out of the app shell to
     * read it and had to find their way back afterwards. This route is the in-shell seat of
     * the same list.
     *
     * The list is in one place, `App\Support\Faq`, and the markup is in one component, so the
     * two pages cannot answer different questions. Behind plain `auth` and not `verified`,
     * beside `about` and for the same reason: it reads nothing about the reader, it is prose,
     * and a reader who has just signed up and cannot yet receive a mail is exactly the one
     * most likely to be reading what the app does with their library.
     */
    Route::livewire('settings/faq', 'pages::settings.faq')->name('settings.faq');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    Route::livewire('settings/connections', 'pages::settings.connections')->name('connections.edit');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');

    /*
     * The end of the AniList connect flow. A GET with the code and the state in
     * the query, which is AniList's shape and not ours to change. Behind auth,
     * because the token it trades for is attached to whoever is signed in.
     */
    Route::get('anilist/callback', AniListCallbackController::class)->name('anilist.callback');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
