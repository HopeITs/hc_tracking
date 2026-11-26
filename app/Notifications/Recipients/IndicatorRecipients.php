<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;

/**
 * Indicators and targets: the owner of the place the target is committed at
 * is direct (Y-16, D-93); MEAL watches.
 */
final class IndicatorRecipients extends LocationOwnedRecipients
{
    public function watcherRoles(Activity $activity): array
    {
        return ['MEAL'];
    }
}
