<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Events\NotificationPushed;
use App\Models\User;
use App\Models\UserNotification;
use Throwable;

/**
 * Pushes a stored notification to its owner's browser. The notification is
 * already saved; a failure here is reported and swallowed so storage never
 * depends on the socket (the client catches up by polling / focus re-sync).
 */
final class NotificationBroadcaster
{
    public function __construct(private NotificationPresenter $presenter) {}

    public function push(User $user, UserNotification $notification): void
    {
        try {
            event(new NotificationPushed(
                $user->id,
                $this->presenter->toBroadcast($notification, $user->preferredLocale() ?? (string) config('app.locale')),
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
