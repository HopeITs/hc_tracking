<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Calendar;

use App\Enums\ReviewStage;
use App\Models\Donor;
use App\Models\DonorReport;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\Report;
use App\Models\Scopes\TaskVisibilityScope;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * The calendar grid on calendar/index: the days on screen and every item
 * grouped by the day it falls on, one query per type, each skipped when its
 * type is filtered out or the viewer may not see it.
 *
 * Used by calendar/index only. The page provides the #[Url] viewMode;
 * activeMonth() and periodDays() come from NavigatesCalendarPeriods,
 * scopedEmployeeIds() from ScopesCalendarPeople, isTypeShown() and
 * canViewDonors() from FiltersCalendarTypes, canViewAgreements() from
 * ChecksFinanceVisibility.
 *
 * @property string $viewMode
 * @property bool $showMilestones
 * @property bool $showTasks
 * @property bool $showReports
 * @property bool $showDonors
 * @property bool $showProjects
 */
trait BuildsCalendarGrid
{
    /**
     * The calendar grid. In month view, full weeks covering the active
     * month (padded with the tail of adjacent months). In day/4-days/week
     * view, the plain list of visible days instead — `weeks` is null then
     * and `days` is populated, and vice versa in month view.
     *
     * @return array{month: CarbonImmutable, weeks: ?array<int, array<int, CarbonImmutable>>, days: ?array<int, CarbonImmutable>, milestonesByDay: \Illuminate\Support\Collection<string, Collection<int, Milestone>>, tasksByDay: \Illuminate\Support\Collection<string, Collection<int, Task>>, reportsByDay: \Illuminate\Support\Collection<string, Collection<int, Report>>, proposalsByDay: \Illuminate\Support\Collection<string, Collection<int, Proposal>>, transfersByDay: \Illuminate\Support\Collection<string, Collection<int, TransfersTracking>>, donorFollowUpsByDay: \Illuminate\Support\Collection<string, Collection<int, Donor>>, donorReportsByDay: \Illuminate\Support\Collection<string, Collection<int, DonorReport>>, projectStartsByDay: \Illuminate\Support\Collection<string, Collection<int, Project>>, projectEndsByDay: \Illuminate\Support\Collection<string, Collection<int, Project>>, reviewMeetingsByDay: \Illuminate\Support\Collection<string, Collection<int, ProposalStatusNote>>, unscheduledTasks: Collection<int, Task>, nowOffsetPercent: float}
     */
    #[Computed]
    public function calendar(): array
    {
        if ($this->viewMode === 'month') {
            $month = $this->activeMonth();
            $gridStart = $month->startOfWeek(CarbonImmutable::SUNDAY);
            $gridEnd = $month->endOfMonth()->endOfWeek(CarbonImmutable::SATURDAY);

            $weeks = [];
            $cursor = $gridStart;
            while ($cursor->lte($gridEnd)) {
                $week = [];
                for ($i = 0; $i < 7; $i++) {
                    $week[] = $cursor;
                    $cursor = $cursor->addDay();
                }
                $weeks[] = $week;
            }

            $days = null;
        } else {
            $days = $this->periodDays();
            $month = $days[0];
            $gridStart = $days[0];
            $gridEnd = end($days);
            $weeks = null;
        }

        $now = CarbonImmutable::now();

        $employeeIds = $this->scopedEmployeeIds();

        $milestonesByDay = $employeeIds === [] || ! $this->showMilestones
            ? new Collection
            : Milestone::query()
                ->select(['id', 'project_id', 'name', 'planned_start_date', 'planned_end_date', 'actual_end_date', 'status', 'weight_percentage', 'responsible_user_id'])
                ->whereIn('responsible_user_id', $employeeIds)
                ->whereBetween('planned_start_date', [$gridStart->toDateString(), $gridEnd->toDateString()])
                ->with('project:id,name')
                ->withCount(['children', 'tasks' => fn ($query) => $query->withoutGlobalScope(TaskVisibilityScope::class)])
                ->orderBy('planned_start_date')
                ->orderBy('sequence')
                ->get()
                ->groupBy(fn (Milestone $milestone): string => $milestone->planned_start_date->toDateString());

        $tasksByDay = $employeeIds === [] || ! $this->showTasks
            ? new Collection
            : Task::query()
                ->select(['id', 'slug', 'title', 'status', 'due_date', 'created_by'])
                ->whereHas('taskAssignees', fn (Builder $query) => $query->whereIn('employee_id', $employeeIds))
                ->whereNotNull('due_date')
                ->whereBetween('due_date', [$gridStart->toDateString(), $gridEnd->toDateString()])
                ->orderBy('due_date')
                ->get()
                ->groupBy(fn (Task $task): string => $task->due_date->toDateString());

        $reportUserIds = $employeeIds === []
            ? []
            : User::query()->whereIn('employee_id', $employeeIds)->pluck('id')->all();

        $reportsByDay = $reportUserIds === [] || ! $this->showReports
            ? new Collection
            : Report::query()
                ->select(['id', 'plan_indicator_id', 'user_id', 'status', 'planned_date'])
                ->whereIn('user_id', $reportUserIds)
                ->whereBetween('planned_date', [$gridStart->toDateString(), $gridEnd->toDateString()])
                ->with('planIndicator.indicator:id,name')
                ->orderBy('planned_date')
                ->get()
                ->groupBy(fn (Report $report): string => $report->planned_date->toDateString());

        $unscheduledTasks = $employeeIds === [] || ! $this->showTasks
            ? new Collection
            : Task::query()
                ->select(['id', 'slug', 'title', 'status', 'priority', 'due_date', 'created_by'])
                ->whereHas('taskAssignees', fn (Builder $query) => $query->whereIn('employee_id', $employeeIds))
                ->whereNull('due_date')
                ->orderBy('title')
                ->get();

        // Proposals, transfers and donors carry no direct "responsible employee"
        // column of their own — the only real ownership signal in the schema is
        // Project::user_id, so anything funding/funded-by one of my projects
        // counts as "mine" here, the same proxy every one of these queries uses.
        // Each of them is money-adjacent, so each is skipped outright unless the
        // viewer holds the matching view permission (see canSeeType()).
        $ownedProjectIds = $employeeIds === []
            ? []
            : Project::query()->whereIn('user_id', $employeeIds)->pluck('id')->all();

        $projectStartsByDay = $employeeIds === [] || ! $this->showProjects
            ? new Collection
            : Project::query()
                ->select(['id', 'name', 'start_date'])
                ->whereIn('user_id', $employeeIds)
                ->whereNotNull('start_date')
                ->whereBetween('start_date', [$gridStart->toDateString(), $gridEnd->toDateString()])
                ->orderBy('start_date')
                ->get()
                ->groupBy(fn (Project $project): string => $project->start_date->toDateString());

        $projectEndsByDay = $employeeIds === [] || ! $this->showProjects
            ? new Collection
            : Project::query()
                ->select(['id', 'name', 'end_date'])
                ->whereIn('user_id', $employeeIds)
                ->whereNotNull('end_date')
                ->whereBetween('end_date', [$gridStart->toDateString(), $gridEnd->toDateString()])
                ->orderBy('end_date')
                ->get()
                ->groupBy(fn (Project $project): string => $project->end_date->toDateString());

        $proposalsByDay = $ownedProjectIds === [] || ! $this->isTypeShown('proposal')
            ? new Collection
            : Proposal::query()
                ->select(['id', 'slug', 'name', 'planned_date_end', 'actual_date_end'])
                ->whereHas('projects', fn (Builder $query) => $query->whereIn('projects.id', $ownedProjectIds))
                ->whereBetween('planned_date_end', [$gridStart->toDateString(), $gridEnd->toDateString()])
                ->orderBy('planned_date_end')
                ->get()
                ->groupBy(fn (Proposal $proposal): string => $proposal->planned_date_end->toDateString());

        $transfersByDay = $ownedProjectIds === [] || ! $this->isTypeShown('transfer')
            ? new Collection
            : TransfersTracking::query()
                ->select(['id', 'donor_proposal_id', 'scheduled_date', 'payment_amount', 'amount_transfered_currency', 'ar_status'])
                ->whereNotNull('scheduled_date')
                ->whereBetween('scheduled_date', [$gridStart->toDateString(), $gridEnd->toDateString()])
                ->whereHas('donorProposal.proposal.projects', fn (Builder $query) => $query->whereIn('projects.id', $ownedProjectIds))
                ->with('donorProposal:id,slug,donor_id,proposal_id', 'donorProposal.donor:id,name')
                ->orderBy('scheduled_date')
                ->get()
                ->groupBy(fn (TransfersTracking $transfer): string => $transfer->scheduled_date->toDateString());

        $donorFollowUpsByDay = $ownedProjectIds === [] || ! $this->showDonors || ! $this->canViewDonors()
            ? new Collection
            : Donor::query()
                ->select(['id', 'slug', 'name', 'next_step', 'next_step_due_on'])
                ->whereNotNull('next_step_due_on')
                ->whereBetween('next_step_due_on', [$gridStart->toDateString(), $gridEnd->toDateString()])
                ->whereHas('proposals.projects', fn (Builder $query) => $query->whereIn('projects.id', $ownedProjectIds))
                ->orderBy('next_step_due_on')
                ->get()
                ->groupBy(fn (Donor $donor): string => $donor->next_step_due_on->toDateString());

        $donorReportsByDay = $ownedProjectIds === [] || ! $this->showDonors || ! $this->canViewAgreements()
            ? new Collection
            : DonorReport::query()
                ->select(['id', 'donor_proposal_id', 'title', 'due_on', 'submitted_on'])
                ->whereBetween('due_on', [$gridStart->toDateString(), $gridEnd->toDateString()])
                ->whereHas('donorProposal.proposal.projects', fn (Builder $query) => $query->whereIn('projects.id', $ownedProjectIds))
                ->with('donorProposal:id,slug')
                ->orderBy('due_on')
                ->get()
                ->groupBy(fn (DonorReport $report): string => $report->due_on->toDateString());

        // Review meetings (T07) on the note that opened a review stage, for the
        // people asked to review it. Someone without proposals.view reviews
        // only as themselves, so the people filter never shows them others'.
        $ownEmployeeId = $this->employeeId();
        $reviewEmployeeIds = $this->canViewProposals() ? $employeeIds : ($ownEmployeeId === null ? [] : [$ownEmployeeId]);

        $reviewMeetingsByDay = $reviewEmployeeIds === [] || ! $this->showProposals
            ? new Collection
            : ProposalStatusNote::query()
                ->select(['id', 'proposal_id', 'status', 'meeting_at'])
                ->whereBetween('meeting_at', [$gridStart->startOfDay(), $gridEnd->endOfDay()])
                ->where(function (Builder $query) use ($reviewEmployeeIds): void {
                    foreach (ReviewStage::cases() as $stage) {
                        $query->orWhere(fn (Builder $stageQuery) => $stageQuery
                            ->where('status', $stage->reviewStatus())
                            ->whereHas('proposal.reviewers', fn (Builder $reviewers) => $reviewers->whereIn('employee_id', $reviewEmployeeIds)->where('stage', $stage->value)));
                    }
                })
                ->with('proposal:id,slug,name')
                ->orderBy('meeting_at')
                ->get()
                ->groupBy(fn (ProposalStatusNote $note): string => $note->meeting_at->toDateString());

        return [
            'month' => $month,
            'weeks' => $weeks,
            'days' => $days,
            'milestonesByDay' => $milestonesByDay,
            'tasksByDay' => $tasksByDay,
            'reportsByDay' => $reportsByDay,
            'proposalsByDay' => $proposalsByDay,
            'transfersByDay' => $transfersByDay,
            'donorFollowUpsByDay' => $donorFollowUpsByDay,
            'donorReportsByDay' => $donorReportsByDay,
            'projectStartsByDay' => $projectStartsByDay,
            'projectEndsByDay' => $projectEndsByDay,
            'reviewMeetingsByDay' => $reviewMeetingsByDay,
            'unscheduledTasks' => $unscheduledTasks,
            'nowOffsetPercent' => ($now->hour * 60 + $now->minute) / (24 * 60) * 100,
        ];
    }
}
