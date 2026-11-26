<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Locations\ArchiveLocation;
use App\Models\Project;
use App\Support\Locations\RowsOfProject;

/**
 * Retire a project into the Archived state.
 *
 * Y-17 slice 1: the row is the writer, so this delegates to ArchiveLocation
 * for each row the project carries and writes nothing itself.
 *
 * @deprecated Y-17 slice 3 removes it. Call ArchiveLocation on the row.
 */
final class ArchiveProject
{
    public function handle(Project $project): void
    {
        foreach (RowsOfProject::of($project) as $row) {
            app(ArchiveLocation::class)->handle($row);
        }
    }
}
