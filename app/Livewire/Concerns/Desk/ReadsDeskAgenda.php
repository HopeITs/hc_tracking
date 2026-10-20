<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Desk;

use App\Enums\ArStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ReportStatus;
use App\Enums\TaskStatus;
use App\Models\DonorReport;
use App\Models\Milestone;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * Everything with a date between three days ago and an end date, soonest
 * first, for the Coming up panel: open milestones, open tasks, indicator
 * reports still in draft, pending transfers (transfers viewers only) and
 * the donor reports the viewer is responsible for. Each query respects
 * the same module checks as the dashboard's upcoming list.
 *
 * Calls transferHref() and taskParentName() from ReadsDashboardAttention.
 */
trait ReadsDeskAgenda
{
    /**
     * @return Collection<int, array{key: string, kind: string, date: CarbonImmutable|null, title: string, context: string, href: string}>
     */
    protected function agendaBetween(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $window = [$from->toDateString(), $to->toDateString()];

        $milestones = $this->visible(Milestone::query(), 'milestones')
            ->select(['id', 'project_id', 'proposal_id', 'name', 'planned_end_date'])
            ->with(['project:id,name', 'proposal:id,name'])
            ->whereIn('status', [MilestoneStatus::Planned, MilestoneStatus::Ongoing])
            ->whereBetween('planned_end_date', $window)
            ->get()
            ->map(fn (Milestone $milestone): array => [
                'key' => 'milestone-'.$milestone->id,
                'kind' => 'milestone',
                'date' => $milestone->planned_end_date,
                'title' => $milestone->name,
                'context' => $milestone->ownerName(),
                'href' => route('milestones.show', $milestone),
            ]);

        $tasks = $this->visible(Task::query(), 'tasks')
            ->select(['id', 'source_type', 'source_id', 'title', 'slug', 'due_date'])
            ->with(['source' => fn (MorphTo $morphTo) => $morphTo->morphWith([Milestone::class => ['project:id,name', 'proposal:id,name']])])
            ->where('status', '!=', TaskStatus::Done)
            ->whereBetween('due_date', $window)
            ->get()
            ->map(fn (Task $task): array => [
                'key' => 'task-'.$task->id,
                'kind' => 'task',
                'date' => $task->due_date,
                'title' => $task->title,
                'context' => $this->taskParentName($task),
                'href' => route('tasks.show', $task),
            ]);

        $reports = $this->visible(Report::query(), 'reports')
            ->select(['id', 'plan_indicator_id', 'planned_date'])
            ->with(['planIndicator:id,indicator_id', 'planIndicator.indicator:id,name'])
            ->where('status', ReportStatus::Draft)
            ->whereBetween('planned_date', $window)
            ->get()
            ->map(fn (Report $report): array => [
                'key' => 'report-'.$report->id,
                'kind' => 'report',
                'date' => $report->planned_date,
                'title' => $report->planIndicator?->indicator->name ?? (string) __('dashboard.report'),
                'context' => (string) __('dashboard.report_due'),
                'href' => route('reports.show', $report),
            ]);

        $transfers = ! $this->canView('transfers') ? new Collection : TransfersTracking::query()
            ->select(['id', 'donor_proposal_id', 'payment_amount', 'amount_transfered_currency', 'scheduled_date'])
            ->with(['donorProposal:id,donor_id', 'donorProposal.donor:id,name'])
            ->where('ar_status', ArStatus::Pending)
            ->whereBetween('scheduled_date', $window)
            ->get()
            ->map(fn (TransfersTracking $transfer): array => [
                'key' => 'transfer-'.$transfer->id,
                'kind' => 'transfer',
                'date' => $transfer->scheduled_date,
                'title' => (string) __('dashboard.transfer_from', [
                    'amount' => Number::format((float) $transfer->payment_amount, maxPrecision: 0),
                    'currency' => $transfer->amount_transfered_currency->value,
                    'donor' => $transfer->donorProposal?->donor->name ?? '—',
                ]),
                'context' => (string) __('dashboard.scheduled_transfer'),
                'href' => $this->transferHref($transfer),
            ]);

        $employeeId = auth()->user()->employee_id;
        $donorReports = $employeeId === null ? new Collection : DonorReport::query()
            ->select(['id', 'donor_proposal_id', 'title', 'due_on'])
            ->with(['donorProposal:id,donor_id', 'donorProposal.donor:id,name'])
            ->where('responsible_employee_id', $employeeId)
            ->whereNull('submitted_on')
            ->whereBetween('due_on', $window)
            ->get()
            ->map(fn (DonorReport $report): array => [
                'key' => 'donor-report-'.$report->id,
                'kind' => 'donor-report',
                'date' => $report->due_on,
                'title' => $report->title,
                'context' => $report->donorProposal?->donor->name ?? '—',
                'href' => route('donor-reports.show', $report),
            ]);

        return $milestones
            ->concat($tasks)
            ->concat($reports)
            ->concat($transfers)
            ->concat($donorReports)
            ->sortBy(fn (array $item): string => ($item['date']?->toDateString() ?? '').$item['key'])
            ->values();
    }
}
