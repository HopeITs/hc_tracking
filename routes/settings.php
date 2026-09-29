<?php

use App\Http\Middleware\EnsureTwoFactorIsEnabled;
use Illuminate\Support\Facades\Route;

/*
| Security is the only settings page, so /settings opens it.
*/
Route::middleware(['auth', EnsureTwoFactorIsEnabled::class])->group(function () {
    Route::redirect('settings', 'settings/security');
});

/*
| The security page stays open to someone who still has to turn on two-factor
| authentication: EnsureTwoFactorIsEnabled sends them here to do it.
*/
Route::middleware(['auth'])->group(function () {
    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
