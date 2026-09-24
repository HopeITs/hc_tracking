<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationPriority;

/**
 * Who hears about one activity, and how loudly.
 */
final readonly class ResolvedRecipients
{
    /**
     * @param  array<int, NotificationPriority>  $priorities  user id => priority
     * @param  list<int>  $assignedUserIds  recipients this activity assigns something to
     * @param  list<int>  $unnotifiedEmployeeIds  assigned employees who have no account
     */
    public function __construct(
        public array $priorities,
        public array $assignedUserIds = [],
        public array $unnotifiedEmployeeIds = [],
    ) {}
}
