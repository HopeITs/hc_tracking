<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;

/**
 * Retire a project into the Archived state. Nothing is destroyed: this is how
 * a project leaves the active portfolio without losing its delivery record.
 */
class ArchiveProject
{
    public function handle(Project $project): void
    {
        Gate::authorize('delete', $project);

        $project->update(['status' => ProjectStatus::Archived]);
    }
}
