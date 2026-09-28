<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Http\UserAgent;
use App\Models\User;
use App\Notifications\SecondStepFailed;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Throwable;

/**
 * Emails the user when the second sign-in step keeps failing.
 *
 * The event fires from Fortify's challenge (authenticator or recovery code)
 * and from EmailTwoFactorController, both of which are only reachable after
 * the correct HR password. One wrong code is usually a typo, so the user is
 * mailed once the failures reach Threshold within WindowMinutes, and only
 * once per window so a guessing run does not flood their inbox. Users with
 * no email (their HR entry is gone) are not mailed. A failure here is
 * reported and never breaks the challenge response.
 */
final class AlertOnFailedSecondStep
{
    /**
     * Failed codes within the window that trigger the email.
     */
    public const Threshold = 3;

    /**
     * Minutes failures are counted over, and the alert is not repeated in.
     */
    public const WindowMinutes = 15;

    public function __construct(private Request $request) {}

    public function handle(TwoFactorAuthenticationFailed $event): void
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
        $window = now()->addMinutes(self::WindowMinutes);
        $countKey = 'second-step-failures:'.$user->getKey();

        Cache::add($countKey, 0, $window);
        $failures = (int) Cache::increment($countKey);

        if ($failures < self::Threshold || $user->email === '') {
            return;
        }

        if (! Cache::add('second-step-failures-alerted:'.$user->getKey(), true, $window)) {
            return;
        }

        $user->notify(
            (new SecondStepFailed(
                UserAgent::parse($this->request->userAgent()),
                (string) $this->request->ip(),
                CarbonImmutable::now(),
            ))->locale($user->preferredLocale() ?? App::getLocale()),
        );
    }
}
