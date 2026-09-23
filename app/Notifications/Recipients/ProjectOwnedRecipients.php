<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;
use App\Models\Project;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Records hanging off a project (risks, indicators, targets): the project
 * owner is direct.
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
