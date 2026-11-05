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
use App\Models\HrCenter;
use App\Models\HrDepartment;
use App\Models\Milestone;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use App\Notifications\EmployeeRecipients;
use App\Notifications\EventCatalog;
use App\Notifications\Recipients\OversightRecipients;
use App\Notifications\TracksActivity;
use App\Support\AgreementMoney;
use App\Support\DonorReportResponsibles;
use App\Support\MonthlyReportPeriods;
use App\Support\Slots;
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

        // An active proposal ending with funds left gets W-33's sentence
        // instead of the generic "is due" one: same subject, same date, same
        // people, so two items would collide in the ledger and the vaguer of
        // the two would win on a coin toss.
        $endingWithFunds = $this->endingWithFunds($limit);
        $saidByExtension = array_map(fn (DeadlineItem $item): int => $item->subjectId, $endingWithFunds);

        return collect([
            ...$this->tasks($limit),
            ...$this->departmentTasks($limit),
            ...$this->milestones($limit),
            ...$this->reports($limit),
            ...$this->monthlyReports($dueOnOrBefore),
            ...$this->donorReports($limit),
            ...$this->proposals($limit, $saidByExtension),
            ...$endingWithFunds,
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
            ->notPaused()
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
            ->where(fn ($query) => $query->whereNull('projects.center_id')->orWhereNotIn('projects.center_id', HrCenter::inactiveIds()))
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
     * Unsubmitted donor reports (contracts §14.3, §15.2 `donor_report.due` /
     * `.overdue`), due on their manual `due_on` (D-32). The responsible
     * person hears without funding-agreements.view and is sent to My
     * Reporting Duties. When they are inactive in HR or have no login, the
     * default slot holder of the report's type hears instead; with neither,
     * Financial and the Public Relations Manager. Four queries.
     *
     * @return list<DeadlineItem>
     */
    private function donorReports(string $limit): array
    {
        $reports = DonorReport::query()
            ->whereNull('submitted_on')
            ->whereDate('due_on', '<=', $limit)
            ->get(['id', 'title', 'type', 'due_on', 'responsible_employee_id']);

        if ($reports->isEmpty()) {
            return [];
        }

        $responsibles = DonorReportResponsibles::resolve(array_values($reports->pluck('responsible_employee_id')->filter()->map(fn ($id): int => (int) $id)->all()));
        $slotUsers = [];

        return array_values($reports->map(function (DonorReport $report) use ($responsibles, &$slotUsers): DeadlineItem {
            $responsible = $report->responsible_employee_id === null ? null : ($responsibles[$report->responsible_employee_id] ?? null);
            $reachable = $responsible !== null && $responsible['reachable'];

            if ($reachable) {
                $direct = [(int) $responsible['user_id']];
            } else {
                $slot = DonorReportResponsibles::slotFor($report->type);

                if (! array_key_exists($slot, $slotUsers)) {
                    $holder = Slots::holderOf($slot);
                    $slotUsers[$slot] = $holder === null ? null : ($this->employees->usersFor([$holder])[$holder] ?? null);
                }

                $direct = $this->unique([$slotUsers[$slot]]);
            }

            return new DeadlineItem(
                'donor_report', $report->id, 'donor-reports', $report->title, $report->due_on, $direct, self::FinanceWatchers,
                eventKey: 'donor_report',
                exemptUserIds: $direct,
                link: $reachable ? ['route' => 'reporting-duties.index'] : [],
            );
        })->all());
    }

    /**
     * @param  list<int>  $except  proposals W-33 is already telling these people about
     * @return list<DeadlineItem>
     */
    private function proposals(string $limit, array $except = []): array
    {
        return array_values(Proposal::query()
            ->when($except !== [], fn (Builder $query) => $query->whereNotIn('proposals.id', $except))
            // A held proposal is `submitted` since W-06 (D-58), so the hold is
            // read from its flag; it stays out of the deadline chase either way.
            ->whereNotIn('status', [ProposalStatus::Completed->value, ProposalStatus::Rejected->value])
            ->whereNull('on_hold_since')
            ->whereRaw('(case when status = ? then coalesce(extended_date_end, actual_date_end, planned_date_end) else planned_date_end end) <= ?', [ProposalStatus::Active->value, $limit])
            ->get(['id', 'name', 'planned_date_end', 'actual_date_end', 'extended_date_end', 'status'])
            ->map(fn (Proposal $proposal): DeadlineItem => new DeadlineItem(
                'proposal', $proposal->id, 'proposals', $proposal->name,
                $proposal->status === ProposalStatus::Active ? ($proposal->effectiveDateEnd() ?? $proposal->planned_date_end) : $proposal->planned_date_end,
                [], self::FinanceWatchers,
            ))->all());
    }

    /**
     * An active proposal whose agreement still has money to come, ending
     * soon: Finance is told so an extension can be asked for in time (D-60,
     * W-33). «Still has funds» is AgreementMoney's outstanding rule, the
     * budget the donor has not transferred yet.
     *
     * The join to `donor_proposal` is what keeps a proposal without an
     * agreement out — by construction, not by a filter someone has to
     * remember — and the money comes from one sub-query over all of them,
     * never a query per row. No amount leaves this method: the item carries
     * only the fact that something remains.
     *
     * @return list<DeadlineItem>
     */
    private function endingWithFunds(string $limit): array
    {
        $recipients = $this->extensionRecipientIds();
        $leadDays = max(1, (int) $this->settings->get('extension_lead_days'));

        return array_values(Proposal::query()
            ->join('donor_proposal', 'donor_proposal.proposal_id', '=', 'proposals.id')
            ->leftJoinSub(AgreementMoney::perAgreement(), 'money', 'money.donor_proposal_id', '=', 'donor_proposal.id')
            ->where('proposals.status', ProposalStatus::Active->value)
            ->whereRaw(AgreementMoney::OUTSTANDING_SQL.' > 0')
            ->whereRaw('coalesce(proposals.extended_date_end, proposals.actual_date_end, proposals.planned_date_end) <= ?', [$limit])
            ->get(['proposals.id', 'proposals.name', 'proposals.planned_date_end', 'proposals.actual_date_end', 'proposals.extended_date_end', 'proposals.status'])
            ->map(fn (Proposal $proposal): DeadlineItem => new DeadlineItem(
                'proposal', $proposal->id, 'proposals', $proposal->name,
                $proposal->effectiveDateEnd() ?? $proposal->planned_date_end,
                $recipients, [],
                eventKey: 'proposal.ending_with_funds',
                dueSoonStages: [$leadDays, 0],
                messageKey: 'notifications.alerts.ending_with_funds',
            ))->all());
    }

    /**
     * Who hears it: the people who may see agreement money, among Finance
     * and the Public Relations Manager. Never Super Admin (D-20a).
     *
     * @return list<int>
     */
    private function extensionRecipientIds(): array
    {
        return array_values(User::permission('funding-agreements.view')
            ->whereHas('roles', fn ($query) => $query->whereIn('name', self::FinanceWatchers))
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', EventCatalog::SuperAdminRole))
            ->pluck('users.id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all());
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
            ->notPaused()
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
