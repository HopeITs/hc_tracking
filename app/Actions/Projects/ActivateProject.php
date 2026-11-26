<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Locations\ActivateLocation;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Support\Locations\RowsOfProject;

/**
 * Activate an approved, dated project (contracts.md §3; D-04b, D-39).
 *
 * Y-17 slice 1: the row is the writer, so this delegates to ActivateLocation
 * for each approved row the project carries. It no longer dispatches
 * ProjectActivated — LocationActivated replaces it (§5) — and it no longer
 * moves the proposal: ActivateLocation does, once every row of that proposal
 * is delivering.
 *
 * @deprecated Y-17 slice 3 removes it. Call ActivateLocation on the row.
 */
final class ActivateProject
{
    /** @var list<string> */
    public const VIAS = ['template_confirmation'];

    public function handle(Project $project, string $via): void
    {
        foreach (RowsOfProject::of($project) as $row) {
            if ($row->status === ProjectStatus::Approved) {
                app(ActivateLocation::class)->handle($row, $via);
            }
        }
    }
}
