<?php

declare(strict_types=1);

namespace App\Actions\Locations;

use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Events\LocationActivated;
use App\Events\ProposalStatusChanged;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\ProposalStatusNote;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Activate an approved, dated location row and, once every row of its
 * proposal is delivering, move the proposal to active (contracts.md §3, §6;
 * D-04b, D-39, Y-17).
 *
 * The twin of the former ActivateProject, moved onto the row. The one entry
 * point for `approved → active`: ConfirmReportTemplate (T33) calls it inside
 * its own transaction, once per row of the template's proposals at its
 * place. A row that is not `approved` (including one already active) is
 * refused with no write, so a second call raises no second event. Events
 * fire after the commit.
 *
 * The proposal moves only when **every** row of it that is not archived is
 * active: an archived row has left the portfolio and holds nothing back.
 */
final class ActivateLocation
{
    /** @var list<string> */
    public const VIAS = ['template_confirmation'];

    public function handle(ProposalLocation $row, string $via): void
    {
        if (! in_array($via, self::VIAS, true)) {
            throw new InvalidArgumentException("Unknown activation path [{$via}].");
        }

        DB::transaction(function () use ($row, $via): void {
            // The row, then its proposal: the lock order of every action on
            // this path (quality-bar.md §2).
            $locked = ProposalLocation::query()->whereKey($row->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('activate', $locked);

            if ($locked->status !== ProjectStatus::Approved) {
                throw ValidationException::withMessages(['status' => __('projects.activate_not_approved')]);
            }

            if ($locked->start_date === null || $locked->end_date === null) {
                throw ValidationException::withMessages(['status' => __('projects.activate_dates_required')]);
            }

            if (! $locked->isAssignmentAcknowledged('responsible_employee_id')) {
                throw ValidationException::withMessages(['status' => __('projects.activate_owner_not_acknowledged')]);
            }

            $proposal = Proposal::query()->whereKey($locked->proposal_id)->lockForUpdate()->firstOrFail();

            $locked->status = ProjectStatus::Active;
            $locked->save();
            $locked->recordActivity('project.activated', properties: ['via' => $via, 'proposal_id' => $proposal->id]);

            $this->activateProposal($proposal, $locked);

            LocationActivated::dispatch($locked);
        });

        $row->refresh();
    }

    /**
     * Move the proposal `approved → active` once none of its rows is still
     * waiting: one note, one event, and nothing when a row has yet to be
     * confirmed or the proposal has already moved.
     */
    private function activateProposal(Proposal $proposal, ProposalLocation $activated): void
    {
        if ($proposal->status !== ProposalStatus::Approved) {
            return;
        }

        $waiting = $proposal->locations()
            ->where('status', '!=', ProjectStatus::Archived->value)
            ->where('status', '!=', ProjectStatus::Active->value)
            ->whereKeyNot($activated->getKey())
            ->exists();

        if ($waiting) {
            return;
        }

        $proposal->status = ProposalStatus::Active;
        $proposal->save();

        ProposalStatusNote::query()->create([
            'proposal_id' => $proposal->id,
            'status' => ProposalStatus::Active,
            'context' => __('proposals.activated_with_report_template'),
            'date' => today()->toDateString(),
            'changed_by' => Auth::id(),
            'is_correction' => false,
        ]);

        ProposalStatusChanged::dispatch($proposal->id, ProposalStatus::Approved, ProposalStatus::Active, Auth::user()?->id);
    }
}
