<?php

declare(strict_types=1);

namespace App\Actions\Locations;

use App\Enums\ProjectStatus;
use App\Models\ProposalLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Close an active location row (contracts.md §3: active → closed, dates
 * kept). The twin of the former CloseProject, moved onto the row.
 *
 * The row is locked and its status re-read inside the transaction, so a
 * second close of the same row finds it closed and refuses: one change is
 * written. Authorization runs on the locked row
 * (`ProposalLocationPolicy::update`: a projects.update holder, the
 * responsible or the program manager; never a row whose responsible has
 * rejected the appointment).
 */
final class CloseLocation
{
    /**
     * @throws ValidationException when the row is not active
     */
    public function handle(ProposalLocation $row): void
    {
        DB::transaction(function () use ($row): void {
            $locked = ProposalLocation::query()->with('ownerAssignment')->whereKey($row->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('update', $locked);

            if ($locked->status !== ProjectStatus::Active) {
                throw ValidationException::withMessages(['status' => __('projects.close_only_active')]);
            }

            $locked->status = ProjectStatus::Closed;
            $locked->save();
            $locked->recordActivity('project.closed');
        });

        $row->refresh();
    }
}
