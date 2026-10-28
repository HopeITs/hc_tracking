<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Dashboard;

use App\Enums\ArStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ReportStatus;
use App\Enums\TaskStatus;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Report;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Models\TransfersTracking;
use App\Models\User;
use App\Support\Desk\TeamScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;

/**
 * The signed-in person's team and timeline: their supervisees' overdue
 * tasks, what falls due in the next thirty days and the time-of-day
 * greeting.
 *
 * Used by the Desk page and its Needs you and My team panels. The host
 * provides visible() and canView() (IsDeskPanel).
 * upcoming() also calls transferHref() and taskParentName() from
 * ReadsDashboardAttention.
 */
trait ReadsDashboardWork
{
    /**
     * The signed-in person's team (TeamScope: their reports, the people on
     * their projects and their department, each under the closest tie) and
     * how many open tasks each has overdue, most overdue first.
     *
     * Being on the team grants no visibility: the count runs as the signed-in
     * user, so TaskVisibilityScope still applies. A fixed number of queries
     * however many people are listed.
     *
     * @return list<array{employeeId: int, name: string, overdue: int, relation: string}>
     */
    #[Computed]
    public function myTeam(): array
    {
        $team = TeamScope::for(auth()->user());
        $superviseeIds = array_keys($team);

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
                'relation' => $team[$id],
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
