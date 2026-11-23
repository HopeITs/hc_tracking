<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
use App\Models\Program;
use App\Models\Project;
use App\Support\Locations\ProjectLocationMirror;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Take an archived project out of the archive (D-27, contracts.md §3).
 *
 * It returns as `closed` when it has dates, else as `draft`, and only when
 * no other open project holds its (center, program): the check is
 * Project::openProjectFor(), under the program's row lock, in this
 * transaction. A project without a center skips it, as it has no pair.
 */
final class RestoreProject
{
    public function __construct(private ProjectLocationMirror $mirror) {}

    /**
     * @throws ValidationException when the project is not archived or another open project holds its center and program
     */
    public function handle(Project $project): ProjectStatus
    {
        Gate::authorize('delete', $project);

        $restored = DB::transaction(function () use ($project): ProjectStatus {
            // The program lock first, then the project row (the lock order of SaveProject).
            Program::query()->whereKey($project->program_id)->lockForUpdate()->value('id');

            $locked = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('delete', $locked);

            if ($locked->status !== ProjectStatus::Archived) {
                throw ValidationException::withMessages(['status' => __('projects.only_archived_can_be_restored')]);
            }

            // A legacy row with neither center nor host is not checked until W-02 maps it.
            if ($locked->center_id !== null || $locked->host_center_id !== null) {
                $open = Project::openProjectFor($locked->center_id, (int) $locked->city_id, $locked->program_id, $locked->id);

                if ($open !== null) {
                    throw ValidationException::withMessages(['status' => __('projects.open_project_exists', ['name' => $open->name])]);
                }
            }

            $locked->status = $locked->start_date !== null && $locked->end_date !== null ? ProjectStatus::Closed : ProjectStatus::Draft;
            $locked->save();

            $this->mirror->sync($locked);

            return $locked->status;
        });

        $project->refresh();

        return $restored;
    }
}
