<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Desk;

use App\Enums\ArStatus;
use App\Enums\MilestoneStatus;
use App\Enums\MonthlyReportStatus;
use App\Enums\ReportStatus;
use App\Enums\TaskStatus;
use App\Models\DonorReport;
use App\Models\Milestone;
use App\Models\MonthlyReport;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Support\MonthlyReportPeriods;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * My agenda (user decision 2026-10-27): every dated item between two days
 * that involves the viewer, soonest first, for the Coming up panel. Tasks
 * assigned to or created by them; milestones of the projects they own or
 * manage, or that they are responsible for; monthly reports still in draft
 * on their projects or prepared by them, on their due date; indicator
 * reports still in draft (reports viewers); pending tranches (transfers
 * viewers); and the donor reports they are responsible for. Proposals carry
 * no deadline column, so none appear. Each query keeps the module checks.
 *
 * Calls transferHref() and taskParentName() from ReadsDashboardAttention.
 */
trait ReadsDeskAgenda
{
    /**
     * @return Collection<int, array{key: string, kind: string, date: CarbonImmutable, title: string, context: string, project: string|null, href: string}>
     */
    protected function agendaBetween(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $user = auth()->user();
        $employeeId = $user->employee_id ?? 0;
        $projectIds = $user->ownedProjectIds();
        $window = [$from->toDateString(), $to->toDateString()];

        $milestones = $this->visible(Milestone::query(), 'milestones')
            ->select(['id', 'slug', 'project_id', 'proposal_id', 'name', 'planned_end_date'])
            ->with(['project:id,name', 'proposal:id,name'])
            ->whereIn('status', [MilestoneStatus::Planned, MilestoneStatus::Ongoing])
            ->where(fn (Builder $query) => $query->whereIn('project_id', $projectIds === [] ? [0] : $projectIds)->orWhere('responsible_user_id', $employeeId))
            ->whereBetween('planned_end_date', $window)
            ->get()
            ->map(fn (Milestone $milestone): array => [
                'key' => 'milestone-'.$milestone->id,
                'kind' => 'milestone',
                'date' => $milestone->planned_end_date,
                'title' => $milestone->name,
                'context' => $milestone->ownerName(),
                'project' => $milestone->ownerName(),
                'href' => route('milestones.show', $milestone),
            ]);

        $tasks = $this->visible(Task::query(), 'tasks')
            ->select(['id', 'source_type', 'source_id', 'title', 'slug', 'due_date'])
            ->with(['source' => fn (MorphTo $morphTo) => $morphTo->morphWith([Milestone::class => ['project:id,name', 'proposal:id,name']])])
            ->where('status', '!=', TaskStatus::Done)
            ->where(fn (Builder $query) => $query->where('created_by', $user->id)->orWhereHas('taskAssignees', fn (Builder $assignee) => $assignee->where('employee_id', $employeeId)))
            ->whereBetween('due_date', $window)
            ->get()
            ->map(fn (Task $task): array => [
                'key' => 'task-'.$task->id,
                'kind' => 'task',
                'date' => $task->due_date,
                'title' => $task->title,
                'context' => $this->taskParentName($task),
                'project' => $this->taskParentName($task),
                'href' => route('tasks.show', $task),
            ]);

        $monthlyReports = $this->visible(MonthlyReport::query(), 'reports')
            ->select(['id', 'slug', 'project_id', 'period', 'prepared_by'])
            ->with('project')
            ->where('status', MonthlyReportStatus::Draft)
            ->where(fn (Builder $query) => $query->whereIn('project_id', $projectIds === [] ? [0] : $projectIds)->orWhere('prepared_by', $user->id))
            ->whereBetween('period', [$from->subMonths(2)->startOfMonth()->toDateString(), $to->toDateString()])
            ->get()
            ->map(fn (MonthlyReport $report): array => [
                'key' => 'monthly-report-'.$report->id,
                'kind' => 'monthly-report',
                'date' => MonthlyReportPeriods::fromLoaded($report->project, new EloquentCollection)->dueDate($report->period->startOfMonth()),
                'title' => (string) __('desk.agenda_monthly_report', ['month' => $report->period->translatedFormat('F Y')]),
                'context' => $report->project?->name ?? '—',
                'project' => $report->project?->name,
                'href' => route('monthly-reports.show', $report),
            ])
            ->filter(fn (array $item): bool => $item['date']->betweenIncluded($from, $to));

        $reports = $this->visible(Report::query(), 'reports')
            ->select(['id', 'plan_indicator_id', 'planned_date'])
            ->with(['planIndicator:id,indicator_id', 'planIndicator.indicator:id,name'])
            ->where('status', ReportStatus::Draft)
            ->where('user_id', $user->id)
            ->whereBetween('planned_date', $window)
            ->get()
            ->map(fn (Report $report): array => [
                'key' => 'report-'.$report->id,
                'kind' => 'report',
                'date' => $report->planned_date,
                'title' => $report->planIndicator?->indicator->name ?? (string) __('dashboard.report'),
                'context' => (string) __('dashboard.report_due'),
                'project' => null,
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
                'project' => null,
                'href' => $this->transferHref($transfer),
            ]);

        $donorReports = $user->employee_id === null ? new Collection : DonorReport::query()
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
                'project' => null,
                'href' => route('donor-reports.show', $report),
            ]);

        return $milestones
            ->concat($tasks)
            ->concat($monthlyReports)
            ->concat($reports)
            ->concat($transfers)
            ->concat($donorReports)
            ->sortBy(fn (array $item): string => $item['date']->toDateString().$item['key'])
            ->values();
    }
}
