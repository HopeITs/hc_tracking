<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Fortify\RecognisedBrowsers;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Throwable;

/**
 * Marks the browser as recognised for the account once a sign-in completes
 * (see RecognisedBrowsers), so a lockout someone else caused on the user's
 * email does not keep the user out of the browser they always use.
 *
 * The Login event only fires after the password and the second step, so a
 * browser that merely knows the password is never recognised. A failure here
 * is reported and never breaks the sign-in.
 */
final class RememberRecognisedBrowser
{
    public function __construct(private Request $request) {}

    public function handle(Login $event): void
    {
        if ($event->guard !== 'web' || ! $event->user instanceof User) {
            return;
        }

        try {
            RecognisedBrowsers::recognise($this->request, $event->user->email);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
