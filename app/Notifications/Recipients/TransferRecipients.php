<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Enums\NotificationPriority;
use App\Models\Activity;

/**
 * Transfers: watched by Financial; a failed tranche is ★ and critical.
 */
final class TransferRecipients extends WatcherRecipients
{
    protected function roles(): array
    {
        return ['Financial'];
    }

    public function isKeyEvent(Activity $activity): bool
    {
        return $activity->event === 'transfer.failed';
    }

    public function priority(Activity $activity): NotificationPriority
    {
        return $this->isKeyEvent($activity) ? NotificationPriority::Critical : NotificationPriority::Info;
    }
}
