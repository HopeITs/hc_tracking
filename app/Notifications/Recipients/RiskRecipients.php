<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Enums\NotificationPriority;
use App\Models\Activity;

/**
 * Risks: the project owner is direct; Managers and MEAL watch; a risk that
 * happens is ★ and critical.
 */
final class RiskRecipients extends ProjectOwnedRecipients
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
