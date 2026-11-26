<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Locations\RestoreLocation;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Support\Locations\RowsOfProject;
use Illuminate\Validation\ValidationException;

/**
 * Take an archived project out of the archive (D-27, contracts.md §3).
 *
 * Y-17 slice 1: the row is the writer, so this delegates to RestoreLocation
 * for each row the project carries and writes nothing itself. The
 * one-open-project check is gone with rule L94 (D-77). The status returned
 * is the first row's, which is the project's own rule applied to the place.
 *
 * @deprecated Y-17 slice 3 removes it. Call RestoreLocation on the row.
 */
final class RestoreProject
{
    /**
     * @throws ValidationException when a row is not archived
     */
    public function handle(Project $project): ProjectStatus
    {
        $restored = null;

        foreach (RowsOfProject::of($project) as $row) {
            $restored ??= app(RestoreLocation::class)->handle($row);
        }

        if ($restored !== null) {
            return $restored;
        }

        throw ValidationException::withMessages(['status' => __('projects.only_archived_can_be_restored')]);
    }
}
