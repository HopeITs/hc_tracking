<?php

declare(strict_types=1);

namespace App\Support\Desk;

use App\Enums\DeliveryState;
use App\Enums\MilestoneStatus;
use App\Enums\MonthlyReportStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Enums\TaskStatus;
use App\Models\DonorReport;
use App\Models\Milestone;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The queries behind what the Desk calls "my tasks", "waiting on others",
 * "open donor reports", the proposal pipeline and the projects card, in one
 * place: the panels load their rows from them and ReadyLayoutSignals counts
 * them, so a layout's signal and the panel it describes can never disagree.
 */
final class DeskWork
{
    /**
     * Proposals past their last deadline: still listed on the pipeline, never
     * its next deadline.
     *
     * @var list<ProposalStatus>
     */
    public const array ClosedProposalStatuses = [ProposalStatus::Rejected, ProposalStatus::Completed];

    /**
     * Every proposal on the Proposal pipeline card.
     *
     * @return Builder<Proposal>
     */
    public static function pipelineProposals(User $user): Builder
    {
        return $user->can('proposals.view') ? Proposal::query() : Proposal::query()->whereRaw('1 = 0');
    }

    /**
     * Narrows a milestones query to those that set a proposal's deadline on
     * the pipeline: open, with a planned end.
     *
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $milestones
     */
    public static function deadlineMilestones(Builder|Relation $milestones): void
    {
        $milestones->whereIn('status', [MilestoneStatus::Planned, MilestoneStatus::Ongoing])->whereNotNull('planned_end_date');
    }

    /**
     * Every project on the projects card, before its "mine" scope.
     *
     * @return Builder<Project>
     */
    public static function projectsOnCard(User $user): Builder
    {
        $projects = Project::query()->where('status', '!=', ProjectStatus::Archived);

        return $user->can('projects.view') ? $projects : $projects->whereRaw('1 = 0');
    }

    /**
     * The projects card's default scope: an owner of some, but not all, of
     * the projects starts on their own; anyone else on every project.
     *
     * @param  Collection<int, Project>  $projects
     * @return array{mine: Collection<int, Project>, hasOthers: bool, shown: Collection<int, Project>}
     */
    public static function projectScopeOf(Collection $projects, User $user): array
    {
        $mine = $projects->where('user_id', $user->employee_id ?? 0);
        $hasOthers = $mine->isNotEmpty() && $mine->count() !== $projects->count();

        return ['mine' => $mine, 'hasOthers' => $hasOthers, 'shown' => $hasOthers ? $mine : $projects];
    }

    /**
     * Top-level tasks assigned to or created by the user (the Tasks panel's "mine").
     *
     * @return Builder<Task>
     */
    public static function myTasks(User $user): Builder
    {
        return self::visibleTasks($user)
            ->whereNull('parent_task_id')
            ->where(fn (Builder $mine) => $mine
                ->where('created_by', $user->id)
                ->orWhereHas('taskAssignees', fn (Builder $assignee) => $assignee->where('employee_id', $user->employee_id ?? 0)));
    }

    /**
     * Tasks the user created for others that are past due.
     *
     * @return Builder<Task>
     */
    public static function waitingTasks(User $user): Builder
    {
        return self::visibleTasks($user)
            ->where('created_by', $user->id)
            ->whereNull('department_id')
            ->where('status', '!=', TaskStatus::Done)
            ->whereDate('due_date', '<', CarbonImmutable::today())
            ->whereHas('taskAssignees', fn (Builder $query) => $query->where('employee_id', '!=', $user->employee_id ?? 0));
    }

    /**
     * Monthly reports the user submitted that MEAL has not validated.
     *
     * @return Builder<MonthlyReport>
     */
    public static function waitingReports(User $user): Builder
    {
        $query = MonthlyReport::query()
            ->where('submitted_by', $user->id)
            ->where('status', MonthlyReportStatus::Submitted);

        return $user->can('reports.view') ? $query : $query->whereRaw('1 = 0');
    }

    /**
     * Reviewers who have not opened the proposals of the user's projects.
     *
     * @return Builder<ProposalReviewer>
     */
    public static function waitingReviews(User $user): Builder
    {
        return ProposalReviewer::query()
            ->whereNull('seen_at')
            ->whereIn('proposal_id', DB::table('project_proposal')->select('proposal_id')->whereIn('project_id', self::projectsOwnedBy($user)));
    }

    /**
     * Deliverables the user's projects requested that are not delivered yet.
     *
     * @return Builder<Task>
     */
    public static function waitingDeliverables(User $user): Builder
    {
        return self::visibleTasks($user)
            ->whereNotNull('department_id')
            ->whereNull('parent_task_id')
            ->where('status', '!=', TaskStatus::Done)
            ->where(fn (Builder $query) => $query->whereNull('delivery_state')->orWhereIn('delivery_state', [DeliveryState::Requested, DeliveryState::Returned]))
            ->where('source_type', 'milestone')
            ->whereIn('source_id', Milestone::query()->select('id')->whereIn('project_id', self::projectsOwnedBy($user)));
    }

    /**
     * Donor reports not yet submitted, every version: funding-agreement
     * viewers and report aggregators see them all, everyone else only those
     * they are responsible for. Read through newestVersions().
     *
     * @return Builder<DonorReport>
     */
    public static function openDonorReports(User $user): Builder
    {
        $seesAll = $user->can('funding-agreements.view') || $user->can('reports.aggregate');

        return DonorReport::query()
            ->whereNull('submitted_on')
            ->when(! $seesAll, fn (Builder $query) => $user->employee_id === null
                ? $query->whereRaw('1 = 0')
                : $query->where('responsible_employee_id', $user->employee_id));
    }

    /**
     * One row per report: when a report was regenerated as a new version,
     * only the newest open version. Expects newest versions first.
     *
     * @param  Collection<int, DonorReport>  $reports
     * @return Collection<int, DonorReport>
     */
    public static function newestVersions(Collection $reports): Collection
    {
        return $reports->unique(fn (DonorReport $report): string => $report->period_start === null
            ? 'report-'.$report->id
            : implode('|', [$report->donor_proposal_id, $report->type->value, $report->period_start->toDateString(), $report->period_end?->toDateString()]));
    }

    /**
     * @return Builder<Task>
     */
    private static function visibleTasks(User $user): Builder
    {
        return $user->can('tasks.view') ? Task::query() : Task::query()->whereRaw('1 = 0');
    }

    /**
     * @return Builder<Project>
     */
    private static function projectsOwnedBy(User $user): Builder
    {
        return Project::query()->select('id')->where('user_id', $user->employee_id ?? 0);
    }
}
