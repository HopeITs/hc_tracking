<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;
use App\Models\Project;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Records hanging off a project (indicators, report templates): the project
 * owner is direct.
 *
 * Risks left for LocationOwnedRecipients in Y-13. The subjects still here —
 * `plan_indicators` and `report_templates` — carry no `proposal_location_id`
 * until Y-16 moves them, so they keep reading the project; this class goes
 * with the `projects` table in Y-18.
 */
abstract class ProjectOwnedRecipients extends ModuleRecipients
{
    public function directEmployeeIds(Activity $activity): array
    {
        $class = Relation::getMorphedModel($activity->subject_type);
        $projectId = $class === null ? null : $class::query()->whereKey($activity->subject_id)->value('project_id');

        return $this->uniqueIds([
            Project::ownerEmployeeIdFor($projectId),
            ...$this->idsFromProperty($activity, 'employee_ids'),
        ]);
    }
}
