<?php

declare(strict_types=1);

namespace App\Support\Locations;

use App\Models\Project;
use App\Models\ProposalLocation;

/**
 * The location rows a project still carries, for the project actions that
 * delegate to the row actions while both exist (Y-17 slice 1).
 *
 * @deprecated Slice 3 removes the project actions and this class with them.
 */
final class RowsOfProject
{
    /**
     * Rows in ascending id, which is the lock order of every row action.
     *
     * @return list<ProposalLocation>
     */
    public static function of(Project $project): array
    {
        return array_values(
            ProposalLocation::query()
                ->where('project_id', $project->getKey())
                ->orderBy('id')
                ->get()
                ->all()
        );
    }
}
