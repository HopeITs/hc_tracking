<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Events\ProjectPeriodChanged;
use App\Models\Project;
use App\Models\Proposal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Give a project the dates of its donor-approved proposals and, once its owner
 * has acknowledged, move a draft to `approved` (contracts.md §3, §6).
 *
 * Two paths reach it, and either may come first: the proposal's approval
 * (`proposal_approval`) and the owner's acknowledgement
 * (`owner_acknowledgement`). Both lock the project row, so the second finds
 * the first's work done. The call is idempotent: it returns true only on the
 * call that moved the status to `approved`.
 *
 * Dates are the earliest begin and latest end of the donor-approved
 * proposals, merged with the dates the project already holds, so they are
 * never shortened. ProjectPeriodChanged is raised only for an active project.
 */
final class ApproveProjectFromProposal
{
    /** @var list<string> */
    public const VIAS = ['proposal_approval', 'owner_acknowledgement'];

    /**
     * @param  string  $via  proposal_approval or owner_acknowledgement
     */
    public function handle(Project $project, string $via): bool
    {
        if (! in_array($via, self::VIAS, true)) {
            throw new InvalidArgumentException("Unknown approval path [{$via}].");
        }

        return DB::transaction(function () use ($project, $via): bool {
            $locked = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            $locked->load('ownerAssignment');
            $linked = $locked->proposals()->get();

            if ($linked->isEmpty()) {
                return false;
            }

            if ($via === 'proposal_approval') {
                Gate::authorize('approve', $linked->first());
            } else {
                $assignment = $locked->currentAssignment('user_id');

                if ($assignment === null) {
                    return false;
                }

                Gate::authorize('approveProject', $assignment);
            }

            $approved = $linked->filter(fn (Proposal $proposal): bool => $this->isFunding($proposal));

            if ($approved->isEmpty() || $locked->isOwnerRejected()) {
                return false;
            }

            return match ($locked->status) {
                ProjectStatus::Draft => $this->approveDraft($locked, array_values($approved->all()), $via),
                ProjectStatus::Approved, ProjectStatus::Active => $this->extendDates($locked, array_values($approved->all())),
                ProjectStatus::Submitted, ProjectStatus::Closed, ProjectStatus::Archived => false,
            };
        });
    }

    /**
     * A draft takes the dates and, with an acknowledged owner, becomes approved.
     *
     * @param  list<Proposal>  $approved
     */
    private function approveDraft(Project $project, array $approved, string $via): bool
    {
        [$begin, $end] = $this->window($approved, null, null);

        $project->start_date = $begin;
        $project->end_date = $end;

        $isAcknowledged = $project->isAssignmentAcknowledged('user_id');

        if (! $isAcknowledged) {
            $project->save();

            return false;
        }

        $project->status = ProjectStatus::Approved;
        $project->setActivityContext(['via' => $via, 'proposal_id' => $this->earliest($approved)->id]);
        $project->save();

        return true;
    }

    /**
     * An approved or active project keeps its status and only ever grows.
     *
     * @param  list<Proposal>  $approved
     */
    private function extendDates(Project $project, array $approved): bool
    {
        $oldStart = $project->start_date;
        $oldEnd = $project->end_date;

        [$begin, $end] = $this->window($approved, $oldStart, $oldEnd);

        if ($oldStart?->toDateString() === $begin->toDateString() && $oldEnd?->toDateString() === $end->toDateString()) {
            return false;
        }

        $project->start_date = $begin;
        $project->end_date = $end;
        $project->save();

        if ($project->status === ProjectStatus::Active && $oldStart !== null && $oldEnd !== null) {
            ProjectPeriodChanged::dispatch($project->id, $oldStart, $oldEnd, $begin, $end);
        }

        return false;
    }

    private function isFunding(Proposal $proposal): bool
    {
        return $proposal->status->isApprovedByDonor()
            && $proposal->status !== ProposalStatus::FinalReports
            && $proposal->actual_date_begin !== null
            && $proposal->actual_date_end !== null;
    }

    /**
     * @param  list<Proposal>  $approved
     */
    private function earliest(array $approved): Proposal
    {
        usort($approved, fn (Proposal $a, Proposal $b): int => [$a->actual_date_begin->toDateString(), $a->id] <=> [$b->actual_date_begin->toDateString(), $b->id]);

        return $approved[0];
    }

    /**
     * Earliest begin and latest end over the proposals and the existing dates.
     *
     * @param  list<Proposal>  $approved
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(array $approved, ?CarbonImmutable $currentStart, ?CarbonImmutable $currentEnd): array
    {
        $begins = array_map(fn (Proposal $proposal): CarbonImmutable => $proposal->actual_date_begin, $approved);
        $ends = array_map(fn (Proposal $proposal): CarbonImmutable => $proposal->actual_date_end, $approved);

        if ($currentStart !== null) {
            $begins[] = $currentStart;
        }

        if ($currentEnd !== null) {
            $ends[] = $currentEnd;
        }

        usort($begins, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);
        usort($ends, fn (CarbonImmutable $a, CarbonImmutable $b): int => $b <=> $a);

        return [$begins[0], $ends[0]];
    }
}
