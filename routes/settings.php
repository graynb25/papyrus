<?php

use App\Http\Controllers\AniListCallbackController;
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
     * What can be restored, and what the last backup actually did.
     *
     * Behind `auth` and not `verified`, beside `about` and for the same reason: it reads the
     * filesystem and nothing about the reader. There is no reader data on the page, so there is
     * nothing to escalate for, and a reader who has just signed up and cannot yet receive a mail is
     * exactly the one most likely to be wondering whether their first backup happened.
     *
     * Nothing here can start a backup. It cannot: the manga half has to be copied with Suwayomi
     * stopped, and Suwayomi being stopped means this process is stopped. The page says so and gives
     * the command, which is the honest shape of a thing that cannot be done from in here.
     */
    Route::livewire('settings/backups', 'pages::settings.backups')->name('backups.edit');
    Route::livewire('settings/about', 'pages::settings.about')->name('about.edit');
    Route::livewire('settings/licences', 'pages::settings.licences')->name('licences.edit');
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
