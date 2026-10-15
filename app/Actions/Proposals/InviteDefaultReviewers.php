<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\ReviewerSlot;
use App\Enums\ReviewStage;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\ProposalStatusNote;
use App\Support\MealCoverage;
use App\Support\Slots;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Invite the reviewers of a review stage; called inside TransitionProposal's
 * transaction, right after the note that opens the stage (contracts.md §6).
 *
 * First entry adds the four default slots (resolved now; a vacant slot adds
 * no row) and each linked project's owner and program manager. Re-entry after
 * a send-back adds only people of projects linked since, never removes or
 * re-slots a row, and asks again only those who have not seen it. Settings
 * and holder changes are never retroactive. One person keeps one row, under
 * the highest-priority slot they fit.
 */
final class InviteDefaultReviewers
{
    public function handle(Proposal $proposal, ReviewStage $stage): void
    {
        Gate::authorize('transition', [$proposal, $stage->reviewStatus()]);

        $existing = $proposal->reviewers()->forStage($stage)->lockForUpdate()->get()->keyBy('employee_id');
        $isFirstEntry = $this->isFirstEntry($proposal, $stage);
        $candidates = $this->candidates($proposal, $isFirstEntry);

        foreach ($candidates as $employeeId => $slot) {
            $row = $existing->get($employeeId);

            if ($row === null) {
                $existing->put($employeeId, ProposalReviewer::query()->create([
                    'proposal_id' => $proposal->id,
                    'stage' => $stage,
                    'employee_id' => $employeeId,
                    'slot' => $slot,
                    'added_by' => Auth::id(),
                ]));
            } elseif ($isFirstEntry && $slot->priority() < $row->slot->priority()) {
                $row->update(['slot' => $slot]);
            }
        }

        $unseen = array_values($existing->filter(fn (ProposalReviewer $row): bool => $row->seen_at === null)->keys()->map(fn (mixed $id): int => (int) $id)->all());
        $notified = MealCoverage::activeEmployeeIds($unseen);
        sort($notified);
        $meetingAt = $this->openingNote($proposal, $stage)?->meeting_at;

        $proposal->recordActivity('proposal.review_requested', properties: [
            'stage' => $stage->value,
            'employee_ids' => $notified,
            'meeting' => $meetingAt?->toDateTimeString(),
        ]);
    }

    /**
     * Whether the note that just opened the stage is its first: no earlier
     * move (corrections aside) ever entered this review status.
     */
    private function isFirstEntry(Proposal $proposal, ReviewStage $stage): bool
    {
        return $proposal->statusNotes()->where('status', $stage->reviewStatus())->where('is_correction', false)->count() <= 1;
    }

    private function openingNote(Proposal $proposal, ReviewStage $stage): ?ProposalStatusNote
    {
        return $proposal->statusNotes()->where('status', $stage->reviewStatus())->latest('id')->first(['id', 'meeting_at']);
    }

    /**
     * The people to invite, each under their highest-priority slot.
     *
     * @return array<int, ReviewerSlot> employee id => slot
     */
    private function candidates(Proposal $proposal, bool $isFirstEntry): array
    {
        $candidates = [];
        $add = function (?int $employeeId, ReviewerSlot $slot) use (&$candidates): void {
            if ($employeeId !== null && (! isset($candidates[$employeeId]) || $slot->priority() < $candidates[$employeeId]->priority())) {
                $candidates[$employeeId] = $slot;
            }
        };

        if ($isFirstEntry) {
            foreach (ReviewerSlot::defaultSlots() as $slot) {
                $add(Slots::holderOf($slot->value), $slot);
            }
        }

        $projects = $proposal->projects()->orderBy('projects.id')->get(['projects.id', 'projects.user_id', 'projects.program_id']);
        $managers = Program::query()->whereKey($projects->pluck('program_id')->filter()->unique()->all())->pluck('manager_id', 'id');

        foreach ($projects as $project) {
            $add($project->user_id, ReviewerSlot::ProjectOwner);

            $managerId = $managers->get($project->program_id);
            $add($managerId === null ? null : (int) $managerId, ReviewerSlot::ProgramManager);
        }

        return $candidates;
    }
}
