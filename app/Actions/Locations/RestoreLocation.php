<?php

declare(strict_types=1);

namespace App\Actions\Locations;

use App\Enums\ProjectStatus;
use App\Models\ProposalLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Take an archived location row out of the archive (D-27, contracts.md §3).
 * The twin of the former RestoreProject, moved onto the row.
 *
 * It returns as `closed` when it has dates, else as `draft`. The
 * one-open-project check the project twin ran is gone: the open-project rule
 * L94 is withdrawn (Y-17 [contract], D-77), so a second row at the same
 * city, program and center is allowed and MEAL decides whether the two
 * share a monthly report.
 */
final class RestoreLocation
{
    /**
     * @throws ValidationException when the row is not archived
     */
    public function handle(ProposalLocation $row): ProjectStatus
    {
        Gate::authorize('delete', $row);

        $restored = DB::transaction(function () use ($row): ProjectStatus {
            $locked = ProposalLocation::query()->whereKey($row->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('delete', $locked);

            if ($locked->status !== ProjectStatus::Archived) {
                throw ValidationException::withMessages(['status' => __('projects.only_archived_can_be_restored')]);
            }

            $locked->status = $locked->start_date !== null && $locked->end_date !== null ? ProjectStatus::Closed : ProjectStatus::Draft;
            $locked->save();
            $locked->recordActivity('project.restored');

            return $locked->status;
        });

        $row->refresh();

        return $restored;
    }
}
