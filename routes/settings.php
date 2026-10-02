<?php

use App\Http\Controllers\AniListCallbackController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
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
