<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Locations\ReopenLocationAsDraft;
use App\Models\Project;
use App\Support\Locations\RowsOfProject;

/**
 * Reopen a closed project as a draft (contracts.md §3: closed → draft, dates
 * cleared).
 *
 * Y-17 slice 1: the row is the writer, so this delegates to
 * ReopenLocationAsDraft for each row the project carries.
 *
 * @deprecated Y-17 slice 3 removes it. Call ReopenLocationAsDraft on the row.
 */
final class ReopenProjectAsDraft
{
    public function handle(Project $project): void
    {
        foreach (RowsOfProject::of($project) as $row) {
            app(ReopenLocationAsDraft::class)->handle($row);
        }
    }
}
