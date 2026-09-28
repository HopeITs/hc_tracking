<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Fortify\TrustedDevices;
use App\Models\User;
use Laravel\Fortify\Events\RecoveryCodesGenerated;

/**
 * Forgets every trusted browser when a user makes new recovery codes.
 *
 * People regenerate the codes when they fear the old ones leaked, so any
 * browser trusted until now may be the one that leaked them and must answer
 * the second step again. Turning the app on for the first time does not fire
 * this event (Fortify writes those first codes itself), so only a deliberate
 * regeneration ends the trust.
 */
final class ForgetTrustedDevicesOnNewRecoveryCodes
{
    public function handle(RecoveryCodesGenerated $event): void
    {
        if ($event->user instanceof User) {
            TrustedDevices::forgetAll($event->user);
        }
    }
}
