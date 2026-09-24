<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Enums\NotificationPriority;
use App\Models\Activity;

/**
 * Proposals: watched by Financial and the Public Relations Manager; approved
 * (important) and rejected (critical) are ★.
 */
final class ProposalRecipients extends WatcherRecipients
{
    protected function roles(): array
    {
        return ['Financial', 'Public Relations Manager'];
    }

    public function isKeyEvent(Activity $activity): bool
    {
        return $activity->event === 'proposal.status_changed'
            && in_array($this->changedTo($activity, 'status'), ['approved', 'rejected'], true);
    }

    public function priority(Activity $activity): NotificationPriority
    {
        return match (true) {
            $this->isKeyEvent($activity) && $this->changedTo($activity, 'status') === 'rejected' => NotificationPriority::Critical,
            $this->isKeyEvent($activity) => NotificationPriority::Important,
            default => NotificationPriority::Info,
        };
    }
}
