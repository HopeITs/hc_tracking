<?php

declare(strict_types=1);

namespace App\Actions\Locations;

use App\Enums\ProjectStatus;
use App\Models\ProposalLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Retire a location row into the Archived state. The twin of the former
 * ArchiveProject, moved onto the row.
 *
 * Nothing is destroyed: this is how a place leaves the active portfolio
 * without losing its delivery record. Rows are archived, never deleted
 * (`ProposalLocationPolicy`).
 */
final class ArchiveLocation
{
    public function handle(ProposalLocation $row): void
    {
        Gate::authorize('delete', $row);

        DB::transaction(function () use ($row): void {
            $locked = ProposalLocation::query()->whereKey($row->getKey())->lockForUpdate()->firstOrFail();

            $locked->status = ProjectStatus::Archived;
            $locked->save();
            $locked->recordActivity('project.archived');
        });

        $row->refresh();
    }
}
