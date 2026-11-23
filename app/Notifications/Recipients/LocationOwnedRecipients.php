<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;
use App\Models\ProposalLocation;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Records hanging off a location row (risks since Y-13, D-93): the row's
 * responsible employee is direct.
 *
 * The twin of ProjectOwnedRecipients, which still serves the modules whose
 * subject has no `proposal_location_id` yet (Y-16 moves them).
 */
abstract class LocationOwnedRecipients extends ModuleRecipients
{
    public function directEmployeeIds(Activity $activity): array
    {
        $class = Relation::getMorphedModel($activity->subject_type);
        $locationId = $class === null ? null : $class::query()->whereKey($activity->subject_id)->value('proposal_location_id');

        return $this->uniqueIds([
            ProposalLocation::ownerEmployeeIdFor($locationId),
            ...$this->idsFromProperty($activity, 'employee_ids'),
        ]);
    }
}
