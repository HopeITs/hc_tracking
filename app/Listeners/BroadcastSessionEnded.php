<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\SessionEnded;
use App\Models\User;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Throwable;

/**
 * Tells the signed-out user's open tabs to re-check their session over the
 * websocket (see SessionEnded). Covers the sign-out button, the HR
 * deactivation / password-change sign-out in EnsureHrAccountIsActive and
 * "sign out other devices". A broadcast failure (Pusher/Reverb down) is
 * reported and never breaks the sign-out.
 */
final class BroadcastSessionEnded
{
    public function handle(Logout|CurrentDeviceLogout|OtherDeviceLogout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        try {
            event(new SessionEnded($event->user->id));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
