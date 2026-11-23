<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Enums\NotificationPriority;
use App\Models\Activity;

/**
 * Risks: the location row's responsible employee is direct (Y-13, D-93);
 * Managers and MEAL watch; a risk that happens is ★ and critical.
 */
final class RiskRecipients extends LocationOwnedRecipients
{
    public function watcherRoles(Activity $activity): array
    {
        return ['Managers', 'MEAL'];
    }

    public function isKeyEvent(Activity $activity): bool
    {
        return $activity->event === 'risk.happened';
    }

    public function priority(Activity $activity): NotificationPriority
    {
        return $this->isKeyEvent($activity) ? NotificationPriority::Critical : NotificationPriority::Info;
    }
}
