<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\LockoutSurge;
use App\Notifications\NotificationPayload;
use App\Notifications\RecipientResolver;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Tells the administrators when refused sign-in attempts pile up.
 *
 * A Lockout fires each time an attempt is refused because a limit was already
 * reached (login or password confirmation). One person who forgot their
 * password causes a handful; a guessing run causes them without end. Once
 * Threshold of them fall within WindowMinutes, every administrator gets an
 * inbox notification and an email, and only once per window so an attack
 * does not flood them. A failure here is reported and never changes the
 * response to the refused attempt.
 */
final class AlertOnLockoutSurge
{
    /**
     * Refused attempts within the window that trigger the alert.
     */
    public const Threshold = 25;

    /**
     * Minutes refused attempts are counted over, and the alert is not
     * repeated in.
     */
    public const WindowMinutes = 15;

    public function __construct(private RecipientResolver $recipients) {}

    public function handle(Lockout $event): void
    {
        try {
            $this->record($event->request);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function record(Request $request): void
    {
        $window = now()->addMinutes(self::WindowMinutes);

        Cache::add('lockout-surge', 0, $window);

        if ((int) Cache::increment('lockout-surge') < self::Threshold) {
            return;
        }

        if (! Cache::add('lockout-surge-alerted', true, $window)) {
            return;
        }

        $inboxAlert = new NotificationPayload(
            kind: NotificationKind::Alert,
            module: 'system',
            priority: NotificationPriority::Critical,
            key: 'notifications.lockout_surge',
            params: ['attempts' => (string) self::Threshold, 'minutes' => (string) self::WindowMinutes],
            subjectType: null,
            subjectId: null,
            link: ['route' => 'activity.index'],
        );
        $email = new LockoutSurge(self::Threshold, self::WindowMinutes, (string) $request->ip(), CarbonImmutable::now());

        $administratorIds = $this->recipients->usersWithRoles(config('access-control.administrator_roles', []));

        foreach (User::query()->whereIn('id', $administratorIds)->get() as $administrator) {
            rescue(fn () => $administrator->notify(new AppNotification($inboxAlert)));

            rescue(function () use ($administrator, $email): void {
                if ($administrator->email !== '') {
                    $administrator->notify((clone $email)->locale($administrator->preferredLocale() ?? App::getLocale()));
                }
            });
        }
    }
}
