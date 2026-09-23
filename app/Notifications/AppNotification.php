<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * The single notification class of this app. It is sent synchronously from
 * already-queued work (fan-out, scanners), so it does not queue itself.
 */
final class AppNotification extends Notification
{
    public function __construct(public NotificationPayload $payload) {}

    /**
     * @return list<class-string>
     */
    public function via(User $notifiable): array
    {
        return [AppDatabaseChannel::class];
    }
}
