<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Dashboard;

use App\Enums\ArStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ReportStatus;
use App\Enums\TaskStatus;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\ProposalStatusNote;
use App\Models\Report;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Models\TransfersTracking;
use App\Support\SupervisionMap;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;

/**
 * The dashboard's own-work and timeline sections: the signed-in person's
 * workload, their team's overdue tasks, what falls due in the next thirty
 * days, the recent activity feed and the time-of-day greeting.
 *
 * Used by dashboard/index only. The page provides visible() and canView().
 * upcoming() also calls transferHref() and taskParentName() from
 * ReadsDashboardAttention.
 */
trait ReadsDashboardWork
{
    /**
     * The signed-in person's own workload.
     *
     * Tasks are assigned to HR employees, so a user with no linked employee has
     * no task figures, only their own draft reports.
     *
     * @return array{employeeId: int|null, overdue: int, dueThisWeek: int, draftReports: int}
     */
    #[Computed]
    public function myWork(): array
    {
        $user = auth()->user();
        $employeeId = $user->employee_id;
        $today = CarbonImmutable::today();
        $openTasks = fn () => $this->visible(Task::query(), 'tasks')
            ->whereHas('taskAssignees', fn ($query) => $query->where('employee_id', $employeeId))
            ->where('status', '!=', TaskStatus::Done);

        return [
            'employeeId' => $employeeId,
            'overdue' => $employeeId === null ? 0 : $openTasks()->whereDate('due_date', '<', $today)->count(),
            'dueThisWeek' => $employeeId === null ? 0 : $openTasks()->whereBetween('due_date', [$today->toDateString(), $today->addDays(7)->toDateString()])->count(),
            'draftReports' => $this->visible(Report::query(), 'reports')->where('user_id', $user->id)->where('status', ReportStatus::Draft)->count(),
        ];
    }

    /**
     * The people the signed-in person supervises (SupervisionMap: HR direct
     * reports plus local supervisions, active employees only) and how many
     * open tasks each has overdue, most overdue first.
     *
     * Supervision grants no visibility: the count runs as the signed-in user,
     * so TaskVisibilityScope still applies. A fixed number of queries however
     * many people are listed; with nobody to supervise, only the map loads.
     *
     * @return list<array{employeeId: int, name: string, overdue: int}>
     */
    #[Computed]
    public function myTeam(): array
    {
        $employeeId = auth()->user()->employee_id;

        if ($employeeId === null) {
            return [];
        }

        $superviseeIds = SupervisionMap::superviseesOf($employeeId);

        if ($superviseeIds === []) {
            return [];
        }

        $names = HrEmployee::query()->whereKey($superviseeIds)->get(HrEmployee::NameColumns)
            ->mapWithKeys(fn (HrEmployee $employee): array => [$employee->id => $employee->name]);

        $overdue = TaskAssignee::query()
            ->whereIn('employee_id', $superviseeIds)
            ->whereIn('task_id', $this->visible(Task::query(), 'tasks')
                ->select('id')
                ->where('status', '!=', TaskStatus::Done)
                ->whereDate('due_date', '<', CarbonImmutable::today()))
            ->groupBy('employee_id')
            ->selectRaw('employee_id, count(*) as overdue')
            ->pluck('overdue', 'employee_id');

        return array_values(collect($superviseeIds)
            ->filter(fn (int $id): bool => $names->has($id))
            ->map(fn (int $id): array => [
                'employeeId' => $id,
                'name' => (string) $names->get($id),
                'overdue' => (int) $overdue->get($id, 0),
            ])
            ->sortBy([['overdue', 'desc'], ['name', 'asc']])
            ->all());
    }

    /**
     * What falls due in the next thirty days, soonest first.
     *
     * @return Collection<int, array{key: string, date: CarbonImmutable, icon: string, tone: string, title: string, context: string, href: string}>
     */
    #[Computed]
    public function upcoming(): Collection
    {
        $today = CarbonImmutable::today();
        $horizon = [$today->toDateString(), $today->addDays(30)->toDateString()];

        $milestones = $this->visible(Milestone::query(), 'milestones')
            ->select(['id', 'project_id', 'proposal_id', 'name', 'planned_end_date'])
            ->with(['project:id,name', 'proposal:id,name'])
            ->where('status', MilestoneStatus::Planned)
            ->whereBetween('planned_end_date', $horizon)
            ->oldest('planned_end_date')
            ->limit(8)
            ->get()
            ->map(fn (Milestone $milestone): array => [
                'key' => 'milestone-'.$milestone->id,
                'date' => $milestone->planned_end_date,
                'icon' => 'flag',
                'tone' => 'neutral',
                'title' => $milestone->name,
                'context' => __('dashboard.milestone_in', ['project' => $milestone->ownerName()]),
                'href' => route('milestones.index', ['project' => $milestone->project_id]),
            ]);

        $transfers = ! $this->canView('transfers') ? new Collection : TransfersTracking::query()
            ->select(['id', 'donor_proposal_id', 'payment_amount', 'amount_transfered_currency', 'scheduled_date'])
            ->with(['donorProposal:id,donor_id', 'donorProposal.donor:id,name'])
            ->where('ar_status', ArStatus::Pending)
            ->whereBetween('scheduled_date', $horizon)
            ->oldest('scheduled_date')
            ->limit(8)
            ->get()
            ->map(fn (TransfersTracking $transfer): array => [
                'key' => 'transfer-'.$transfer->id,
                'date' => $transfer->scheduled_date,
                'icon' => 'banknotes',
                'tone' => 'funding',
                'title' => __('dashboard.transfer_from', [
                    'amount' => Number::format((float) $transfer->payment_amount, maxPrecision: 0),
                    'currency' => $transfer->amount_transfered_currency,
                    'donor' => $transfer->donorProposal?->donor?->name ?? '—',
                ]),
                'context' => __('dashboard.scheduled_transfer'),
                'href' => $this->transferHref($transfer),
            ]);

        $tasks = $this->visible(Task::query(), 'tasks')
            ->select(['id', 'source_type', 'source_id', 'title', 'due_date'])
            ->with(['source' => fn (MorphTo $morphTo) => $morphTo->morphWith([Milestone::class => ['project:id,name', 'proposal:id,name']])])
            ->where('status', '!=', TaskStatus::Done)
            ->whereBetween('due_date', $horizon)
            ->oldest('due_date')
            ->limit(8)
            ->get()
            ->map(fn (Task $task): array => [
                'key' => 'task-'.$task->id,
                'date' => $task->due_date,
                'icon' => 'clipboard-document-check',
                'tone' => 'neutral',
                'title' => $task->title,
                'context' => __('dashboard.task_in', ['parent' => $this->taskParentName($task)]),
                'href' => route('tasks.show', $task),
            ]);

        $reports = $this->visible(Report::query(), 'reports')
            ->select(['id', 'plan_indicator_id', 'planned_date', 'status'])
            ->with(['planIndicator:id,indicator_id', 'planIndicator.indicator:id,name'])
            ->where('status', ReportStatus::Draft)
            ->whereBetween('planned_date', $horizon)
            ->oldest('planned_date')
            ->limit(8)
            ->get()
            ->map(fn (Report $report): array => [
                'key' => 'report-'.$report->id,
                'date' => $report->planned_date,
                'icon' => 'document-text',
                'tone' => 'warn',
                'title' => $report->planIndicator?->indicator?->name ?? __('dashboard.report'),
                'context' => __('dashboard.report_due'),
                'href' => route('reports.show', $report),
            ]);

        return $milestones
            ->concat($transfers)
            ->concat($tasks)
            ->concat($reports)
            ->sortBy(fn (array $item): string => $item['date']->toDateString())
            ->take(8)
            ->values();
    }

    /**
     * The latest changes across proposals, tasks and reports.
     *
     * @return Collection<int, array{key: string, at: CarbonImmutable, dateOnly: bool, icon: string, sentence: string, href: string}>
     */
    #[Computed]
    public function activity(): Collection
    {
        $notes = ! $this->canView('proposals') ? new Collection : ProposalStatusNote::query()
            ->select(['id', 'proposal_id', 'status', 'date'])
            ->with('proposal:id,name')
            ->latest('date')
            ->limit(6)
            ->get()
            ->map(fn (ProposalStatusNote $note): array => [
                'key' => 'note-'.$note->id,
                'at' => $note->date,
                'dateOnly' => true,
                'icon' => 'arrows-right-left',
                'sentence' => __('dashboard.proposal_moved', ['proposal' => $note->proposal?->name ?? '—', 'status' => $note->status->label()]),
                'href' => route('proposals.show', $note->proposal),
            ]);

        $reports = $this->visible(Report::query(), 'reports')
            ->select(['id', 'plan_indicator_id', 'user_id', 'submit_date'])
            ->with(['user:id,hr_user_id', 'planIndicator:id,indicator_id', 'planIndicator.indicator:id,name'])
            ->whereNotNull('submit_date')
            ->latest('submit_date')
            ->limit(6)
            ->get()
            ->map(fn (Report $report): array => [
                'key' => 'report-'.$report->id,
                'at' => $report->submit_date,
                'dateOnly' => true,
                'icon' => 'document-check',
                'sentence' => __('dashboard.report_submitted', ['user' => $report->user?->name ?? '—', 'indicator' => $report->planIndicator?->indicator?->name ?? '—']),
                'href' => route('reports.show', $report),
            ]);

        return $notes
            ->concat($reports)
            ->sortByDesc(fn (array $item): int => $item['at']->getTimestamp())
            ->take(7)
            ->values();
    }

    /**
     * The greeting for the current time of day.
     */
    protected function greeting(): string
    {
        $hour = (int) now()->format('G');
        $key = match (true) {
            $hour < 12 => 'dashboard.good_morning',
            $hour < 18 => 'dashboard.good_afternoon',
            default => 'dashboard.good_evening',
        };

        return __($key, ['name' => str(auth()->user()->name)->before(' ')->toString()]);
    }
}
