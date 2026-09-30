<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;

/**
 * Put an archived project back into the portfolio as a draft, the one
 * lifecycle state that makes no claim about where the work stands.
 */
class RestoreProject
{
    /**
     * @return bool false when the project is not archived and nothing changed
     */
    public function handle(Project $project): bool
    {
        if ($project->status !== ProjectStatus::Archived) {
            return false;
        }

        Gate::authorize('delete', $project);

        $project->update(['status' => ProjectStatus::Draft]);

        return true;
    }
}
