<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;

/**
 * Indicators and targets: the project owner is direct; MEAL watches.
 */
final class IndicatorRecipients extends ProjectOwnedRecipients
{
    public function watcherRoles(Activity $activity): array
    {
        return ['MEAL'];
    }
}
