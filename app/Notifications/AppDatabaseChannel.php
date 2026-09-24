<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Models\UserNotification;

/**
 * Stores an AppNotification in the inbox (merging it into a recent unread row
 * when the grouper says so), refreshes the owner's unread counts, then pushes
 * it to their browser. Storage always comes first.
 */
final class AppDatabaseChannel
{
    public function __construct(
        private NotificationGrouper $grouper,
        private UnreadCounts $counts,
        private NotificationBroadcaster $broadcaster,
    ) {}

    public function send(User $notifiable, AppNotification $notification): UserNotification
    {
        [$row] = $this->grouper->store($notifiable, $notification->payload, $notification->id);

        $this->counts->forget($notifiable->id);
        $this->broadcaster->push($notifiable, $row);

        return $row;
    }
}
