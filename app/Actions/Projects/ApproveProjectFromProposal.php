<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Locations\ApproveLocationFromProposal;
use App\Models\Project;
use App\Support\Locations\RowsOfProject;

/**
 * Give a project the dates of its donor-approved proposals and, once its
 * owner has acknowledged, move a draft to `approved` (contracts.md §3, §6).
 *
 * Y-17 slice 1: the row is the writer, so this delegates to
 * ApproveLocationFromProposal for each row the project carries. A row
 * belongs to exactly one proposal (D-93), so each takes its own grant's
 * window instead of a window merged across grants. Returns whether any row
 * moved to `approved` on this call.
 *
 * @deprecated Y-17 slice 3 removes it. Call ApproveLocationFromProposal on the row.
 */
final class ApproveProjectFromProposal
{
    /** @var list<string> */
    public const VIAS = ['proposal_approval', 'owner_acknowledgement'];

    public function handle(Project $project, string $via): bool
    {
        $approved = false;

        foreach (RowsOfProject::of($project) as $row) {
            $approved = app(ApproveLocationFromProposal::class)->handle($row, $via) || $approved;
        }

        return $approved;
    }
}
