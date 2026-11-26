<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Locations\CloseLocation;
use App\Models\Project;
use App\Support\Locations\RowsOfProject;
use Illuminate\Validation\ValidationException;

/**
 * Close an active project (contracts.md §3: active → closed, dates kept).
 *
 * Y-17 slice 1: the row is the writer, so this delegates to CloseLocation
 * for each row the project carries and writes nothing itself. It stays only
 * so that a caller slice 1 has not moved keeps working.
 *
 * @deprecated Y-17 slice 3 removes it. Call CloseLocation on the row.
 */
final class CloseProject
{
    /**
     * @throws ValidationException when a row is not active
     */
    public function handle(Project $project): void
    {
        foreach (RowsOfProject::of($project) as $row) {
            app(CloseLocation::class)->handle($row);
        }
    }
}
