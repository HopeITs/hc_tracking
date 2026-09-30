<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Http\UserAgent;
use App\Models\KnownDevice;
use App\Models\User;
use App\Notifications\NewDeviceSignIn;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Throwable;

/**
 * Remembers the browser/platform each user signs in from and emails them when
 * a new one appears.
 *
 * The very first device on record is the baseline and sends nothing; a device
 * seen before only has its last IP and time refreshed. Users with no email
 * (their HR entry is gone) are not mailed. A failure here is reported and
 * never breaks the sign-in.
 */
final class RecordSignInDevice
{
    public function __construct(private Request $request) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        try {
            $this->record($event->user);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function record(User $user): void
    {
        $agent = UserAgent::parse($this->request->userAgent());
        $ipAddress = (string) $this->request->ip();
        $signedInAt = CarbonImmutable::now();
        $seen = ['last_ip' => $ipAddress, 'last_seen_at' => $signedInAt];

        $device = KnownDevice::query()
            ->where('user_id', $user->id)
            ->where('fingerprint', $agent->fingerprint())
            ->first();

        if ($device !== null) {
            $device->update($seen);

            return;
        }

        $isBaseline = ! KnownDevice::query()->where('user_id', $user->id)->exists();

        $device = KnownDevice::query()->createOrFirst(
            ['user_id' => $user->id, 'fingerprint' => $agent->fingerprint()],
            ['label' => $agent->description()] + $seen,
        );

        if (! $device->wasRecentlyCreated) {
            $device->update($seen);

            return;
        }

        if ($isBaseline || $user->email === '') {
            return;
        }

        $user->notify(
            (new NewDeviceSignIn($agent, $ipAddress, $signedInAt))->locale($user->preferredLocale() ?? App::getLocale()),
        );
    }
}
