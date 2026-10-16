<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\ArStatus;
use App\Enums\MilestoneStatus;
use App\Enums\MonthlyReportStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Enums\ReportStatus;
use App\Enums\TaskStatus;
use App\Models\DonorReport;
use App\Models\HrDepartment;
use App\Models\Milestone;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Notifications\EmployeeRecipients;
use App\Notifications\Recipients\OversightRecipients;
use App\Notifications\TracksActivity;
use App\Support\MonthlyReportPeriods;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Every open item that carries a date the deadline scanners watch. Closed
 * states follow the app's own definitions (Report::isOverdue(),
 * TransfersTracking::state(), ProjectStatus::isOpen()).
 */
final class DeadlineCatalog
{
    private const FinanceWatchers = ['Financial', 'Public Relations Manager'];

    public function __construct(
        private EmployeeRecipients $employees,
        private OversightRecipients $oversight,
        private NotificationSettings $settings,
    ) {}

    /**
     * @return Collection<int, DeadlineItem>
     */
    public function items(CarbonImmutable $dueOnOrBefore): Collection
    {
        $limit = $dueOnOrBefore->toDateString();

        return collect([
            ...$this->tasks($limit),
            ...$this->departmentTasks($limit),
            ...$this->milestones($limit),
            ...$this->reports($limit),
            ...$this->monthlyReports($dueOnOrBefore),
            ...$this->donorReports($limit),
            ...$this->proposals($limit),
            ...$this->transfers($limit),
            ...$this->projects($limit),
        ]);
    }

    /**
     * @return list<DeadlineItem>
     */
    private function tasks(string $limit): array
    {
        $tasks = Task::query()
            ->select(['id', 'title', 'due_date', 'created_by'])
            ->whereNull('department_id')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $limit)
            ->where('status', '!=', TaskStatus::Done->value)
            ->where(fn (Builder $query) => $this->outsideRejectedProposals($query))
            ->with('taskAssignees:id,task_id,employee_id')
            ->get();

        $users = $this->employees->usersFor($tasks->flatMap(fn (Task $task) => $task->taskAssignees->pluck('employee_id'))->map(fn ($id): int => (int) $id)->unique()->values()->all());

        return array_values($tasks->map(fn (Task $task): DeadlineItem => new DeadlineItem(
            'task', $task->id, 'tasks', $task->title, $task->due_date,
            $this->unique([...$task->taskAssignees->map(fn ($assignee): ?int => $users[(int) $assignee->employee_id] ?? null)->all(), $task->created_by]),
        ))->all());
    }

    /**
     * A proposal plan's department tasks (T31, D-33): the person named and
     * the department hear it is coming and when it is late; the requester
     * hears only once it is overdue; a department gone from HR hands the
     * alert to the proposal's owners. Tasks of a rejected proposal are quiet.
     *
     * @return list<DeadlineItem>
     */
    private function departmentTasks(string $limit): array
    {
        $tasks = Task::query()
            ->withoutGlobalScopes()
            ->select(['id', 'title', 'source_type', 'source_id', 'department_id', 'due_date', 'created_by'])
            ->whereNotNull('department_id')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $limit)
            ->where('status', '!=', TaskStatus::Done->value)
            ->where(fn (Builder $query) => $this->outsideRejectedProposals($query))
            ->with('taskAssignees:id,task_id,employee_id')
            ->get();

        if ($tasks->isEmpty()) {
            return [];
        }

        $proposalIds = $this->planProposalIds($tasks);
        $owners = Proposal::ownerEmployeeIdsFor(array_values(array_unique(array_filter($proposalIds))));
        $users = $this->employees->usersFor($tasks->flatMap(fn (Task $task) => $task->taskAssignees->pluck('employee_id'))
            ->merge(collect($owners)->flatten())
            ->map(fn ($id): int => (int) $id)->unique()->values()->all());
        $departments = [];
        $stages = [(int) $this->settings->get('department_task_due_days'), 0];

        return array_values($tasks->map(function (Task $task) use ($proposalIds, $owners, $users, &$departments, $stages): DeadlineItem {
            $departmentId = (int) $task->department_id;
            $departments[$departmentId] ??= HrDepartment::nameOf($departmentId) === ''
                ? null
                : $this->oversight->departmentUserIds($departmentId);
            $proposalId = $proposalIds[$task->id] ?? null;
            $team = $departments[$departmentId]
                ?? array_map(fn (int $employeeId): ?int => $users[$employeeId] ?? null, $owners[$proposalId] ?? []);
            $assignees = $task->taskAssignees->map(fn ($assignee): ?int => $users[(int) $assignee->employee_id] ?? null)->all();

            return new DeadlineItem(
                'task', $task->id, 'tasks', $task->title, $task->due_date,
                $this->unique([...$assignees, ...$team]),
                eventKey: 'department_task',
                overdueUserIds: $this->unique([$task->created_by]),
                dueSoonStages: $stages,
                unassignedUserIds: $task->taskAssignees->isEmpty() ? $this->unique($team) : [],
            );
        })->all());
    }

    /**
     * The proposal each plan task belongs to: its own source, or the
     * proposal of its source milestone.
     *
     * @param  Collection<int, Task>  $tasks
     * @return array<int, int|null> task id => proposal id
     */
    private function planProposalIds(Collection $tasks): array
    {
        $milestoneProposals = Milestone::query()
            ->whereIn('id', $tasks->where('source_type', 'milestone')->pluck('source_id')->all())
            ->pluck('proposal_id', 'id');

        return $tasks->mapWithKeys(fn (Task $task): array => [$task->id => match ($task->source_type) {
            'proposal' => (int) $task->source_id,
            'milestone' => ($id = $milestoneProposals->get($task->source_id)) === null ? null : (int) $id,
            default => null,
        }])->all();
    }

    /**
     * Keeps a task query to work whose proposal (its source, or its source
     * milestone's proposal) is not rejected: a rejected plan stops alerting.
     *
     * @param  Builder<Task>  $query
     */
    private function outsideRejectedProposals(Builder $query): void
    {
        $rejected = Proposal::query()->select('id')->where('status', ProposalStatus::Rejected->value);

        $query->where(fn (Builder $query) => $query->where('source_type', '!=', 'proposal')->orWhereNotIn('source_id', $rejected))
            ->where(fn (Builder $query) => $query->where('source_type', '!=', 'milestone')
                ->orWhereNotIn('source_id', Milestone::query()->select('id')->whereIn('proposal_id', $rejected)));
    }

    /**
     * Project milestones tell their responsible person and the project owner;
     * a proposal plan's shared milestone tells its responsible person and the
     * proposal's owners, and is quiet once the proposal is rejected.
     *
     * @return list<DeadlineItem>
     */
    private function milestones(string $limit): array
    {
        $milestones = Milestone::query()
            ->leftJoin('projects', 'projects.id', '=', 'milestones.project_id')
            ->whereNotNull('milestones.planned_end_date')
            ->whereDate('milestones.planned_end_date', '<=', $limit)
            ->whereNotIn('milestones.status', [MilestoneStatus::Reached->value, MilestoneStatus::Cancelled->value])
            ->where(fn ($query) => $query->whereNotNull('milestones.project_id')->orWhereNotNull('milestones.proposal_id'))
            ->where(fn ($query) => $query->whereNull('milestones.proposal_id')
                ->orWhereNotIn('milestones.proposal_id', Proposal::query()->select('id')->where('status', ProposalStatus::Rejected->value)))
            ->toBase()
            ->get(['milestones.id', 'milestones.name', 'milestones.planned_end_date', 'milestones.responsible_user_id', 'milestones.proposal_id', 'projects.user_id as owner_id']);

        $planOwners = Proposal::ownerEmployeeIdsFor($milestones->whereNull('owner_id')->pluck('proposal_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all());
        $ownersOf = fn (stdClass $row): array => $row->owner_id !== null ? [(int) $row->owner_id] : ($planOwners[(int) $row->proposal_id] ?? []);

        $users = $this->employees->usersFor($milestones->flatMap(fn (object $row): array => [(int) $row->responsible_user_id, ...$ownersOf($row)])->unique()->values()->all());

        return array_values($milestones->map(fn (object $row): DeadlineItem => new DeadlineItem(
            'milestone', (int) $row->id, 'milestones', (string) $row->name, CarbonImmutable::parse($row->planned_end_date),
            $this->unique([$users[(int) $row->responsible_user_id] ?? null, ...array_map(fn (int $owner): ?int => $users[$owner] ?? null, $ownersOf($row))]),
        ))->all());
    }

    /**
     * @return list<DeadlineItem>
     */
    private function reports(string $limit): array
    {
        return array_values(Report::query()
            ->join('plan_indicators', 'plan_indicators.id', '=', 'reports.plan_indicator_id')
            ->join('indicators', 'indicators.id', '=', 'plan_indicators.indicator_id')
            ->where('reports.status', ReportStatus::Draft->value)
            ->whereNull('reports.monthly_report_id')
            ->whereDate('reports.planned_date', '<=', $limit)
            ->toBase()
            ->get(['reports.id', 'reports.user_id', 'reports.planned_date', 'indicators.name'])
            ->map(fn (object $row): DeadlineItem => new DeadlineItem(
                'report', (int) $row->id, 'reports', (string) $row->name, CarbonImmutable::parse($row->planned_date), [(int) $row->user_id],
            ))->all());
    }

    /**
     * One item per started, required (at least one line), unsubmitted
     * monthly report (contracts §13, §13.1 item 2), due on
     * MonthlyReportPeriods::dueDate(): the configured working days after the
     * month's end on the project's calendar. Months not started raise
     * nothing; the report's mirrors in `reports` are left out of reports().
     * Three queries.
     *
     * @return list<DeadlineItem>
     */
    private function monthlyReports(CarbonImmutable $dueOnOrBefore): array
    {
        $reports = MonthlyReport::query()
            ->where('status', MonthlyReportStatus::Draft->value)
            ->whereDate('period', '<=', CarbonImmutable::today()->startOfMonth()->toDateString())
            ->has('lines')
            ->withCount('lines')
            ->orderBy('period')
            ->get(['id', 'project_id', 'period', 'status', 'prepared_by']);

        if ($reports->isEmpty()) {
            return [];
        }

        $projects = Project::query()->whereKey($reports->pluck('project_id')->unique()->values())->get(['id', 'name', 'user_id', 'working_days'])->keyBy('id');
        $owners = $this->employees->usersFor($projects->pluck('user_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all());
        $items = [];

        foreach ($reports->groupBy('project_id') as $projectId => $projectReports) {
            $project = $projects->get($projectId);

            if ($project === null) {
                continue;
            }

            $periods = MonthlyReportPeriods::fromLoaded($project, $projectReports);

            foreach ($periods->openRequiredReports() as $report) {
                $due = $periods->dueDate($report->period);

                if ($due->greaterThan($dueOnOrBefore)) {
                    continue;
                }

                $items[] = new DeadlineItem(
                    'monthly_report', $report->id, 'monthly-reports', $project->name.' · '.$report->period->format('Y-m'), $due,
                    $this->unique([$owners[(int) $project->user_id] ?? null, $report->prepared_by]), ['MEAL'], 'monthly_report', projectId: $project->id,
                );
            }
        }

        return $items;
    }

    /**
     * @return list<DeadlineItem>
     */
    private function donorReports(string $limit): array
    {
        return array_values(DonorReport::query()
            ->whereNull('submitted_on')
            ->whereDate('due_on', '<=', $limit)
            ->get(['id', 'title', 'due_on'])
            ->map(fn (DonorReport $report): DeadlineItem => new DeadlineItem(
                'donor_report', $report->id, 'funding-agreements', $report->title, $report->due_on, [], self::FinanceWatchers,
            ))->all());
    }

    /**
     * @return list<DeadlineItem>
     */
    private function proposals(string $limit): array
    {
        return array_values(Proposal::query()
            ->whereNotIn('status', [ProposalStatus::Completed->value, ProposalStatus::Rejected->value, ProposalStatus::OnHold->value])
            ->whereDate('planned_date_end', '<=', $limit)
            ->get(['id', 'name', 'planned_date_end', 'status'])
            ->map(fn (Proposal $proposal): DeadlineItem => new DeadlineItem(
                'proposal', $proposal->id, 'proposals', $proposal->name, $proposal->planned_date_end, [], self::FinanceWatchers,
            ))->all());
    }

    /**
     * @return list<DeadlineItem>
     */
    private function transfers(string $limit): array
    {
        return array_values(TransfersTracking::query()
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->join('donors', 'donors.id', '=', 'donor_proposal.donor_id')
            ->join('proposals', 'proposals.id', '=', 'donor_proposal.proposal_id')
            ->where('transfers_tracking.ar_status', ArStatus::Pending->value)
            ->whereNull('transfers_tracking.transfer_date')
            ->whereNotNull('transfers_tracking.scheduled_date')
            ->whereDate('transfers_tracking.scheduled_date', '<=', $limit)
            ->toBase()
            ->get(['transfers_tracking.id', 'transfers_tracking.scheduled_date', 'donors.name as donor', 'proposals.name as proposal'])
            ->map(fn (object $row): DeadlineItem => new DeadlineItem(
                'transfer', (int) $row->id, 'transfers', $row->donor.' · '.$row->proposal.' · '.CarbonImmutable::parse($row->scheduled_date)->toDateString(),
                CarbonImmutable::parse($row->scheduled_date), [], ['Financial'],
            ))->all());
    }

    /**
     * @return list<DeadlineItem>
     */
    private function projects(string $limit): array
    {
        $open = array_map(fn (ProjectStatus $status): string => $status->value, array_filter(ProjectStatus::cases(), fn (ProjectStatus $status): bool => $status->isOpen()));

        $projects = Project::query()
            ->whereIn('status', $open)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<=', $limit)
            ->get(['id', 'name', 'end_date', 'user_id']);

        $users = $this->employees->usersFor($projects->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());

        return array_values($projects->map(fn (Project $project): DeadlineItem => new DeadlineItem(
            'project', $project->id, 'projects', $project->name, $project->end_date, $this->unique([$users[(int) $project->user_id] ?? null]),
        ))->all());
    }

    /**
     * The program an item rolls up to (for escalation and the weekly digest),
     * read through the subject's own TracksActivity::activityProgramId().
     */
    public function programIdFor(DeadlineItem $item): ?int
    {
        $class = Relation::getMorphedModel($item->subjectType);
        $model = $class === null ? null : $class::query()->find($item->subjectId);

        return $model instanceof TracksActivity ? $model->activityProgramId() : null;
    }

    /**
     * @param  array<int, int|null>  $ids
     * @return list<int>
     */
    private function unique(array $ids): array
    {
        return array_values(array_unique(array_map('intval', array_filter($ids))));
    }
}
