<?php

declare(strict_types=1);

namespace App\Actions\Locations;

use App\Enums\ProjectStatus;
use App\Models\ProposalLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Reopen a closed location row as a draft (contracts.md §3: closed → draft,
 * dates cleared). The twin of the former ReopenProjectAsDraft, moved onto
 * the row.
 *
 * The row is re-locked and re-read, so a row another caller already
 * reopened is left as it is. The status change is saved through the model,
 * so the Activity Log keeps the old status and dates.
 *
 * The one-open-project check is gone with rule L94 (Y-17 [contract], D-77).
 */
final class ReopenLocationAsDraft
{
    public function handle(ProposalLocation $row): void
    {
        DB::transaction(function () use ($row): void {
            $locked = ProposalLocation::query()->with('ownerAssignment')->whereKey($row->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('reopenAsDraft', $locked);

            if ($locked->status === ProjectStatus::Draft) {
                return;
            }

            if ($locked->status !== ProjectStatus::Closed) {
                throw ValidationException::withMessages(['locations' => __('proposals.that_project_is_no_longer_available')]);
            }

            $locked->status = ProjectStatus::Draft;
            $locked->start_date = null;
            $locked->end_date = null;
            $locked->save();
            $locked->recordActivity('project.reopened');
        });

        $row->refresh();
    }
}
