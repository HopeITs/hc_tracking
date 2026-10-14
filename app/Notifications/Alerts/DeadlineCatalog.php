<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\ArStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Enums\ReportStatus;
use App\Enums\TaskStatus;
use App\Models\DonorReport;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Notifications\EmployeeRecipients;
use App\Notifications\TracksActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Every open item that carries a date the deadline scanners watch. Closed
 * states follow the app's own definitions (Report::isOverdue(),
 * TransfersTracking::state(), ProjectStatus::isOpen()).
 */
final class DeadlineCatalog
{
    private const FinanceWatchers = ['Financial', 'Public Relations Manager'];

    public function __construct(private EmployeeRecipients $employees) {}

    /**
     * @return Collection<int, DeadlineItem>
     */
    public function items(CarbonImmutable $dueOnOrBefore): Collection
    {
        $limit = $dueOnOrBefore->toDateString();

        return collect([
            ...$this->tasks($limit),
            ...$this->milestones($limit),
            ...$this->reports($limit),
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
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $limit)
            ->where('status', '!=', TaskStatus::Done->value)
            ->with('taskAssignees:id,task_id,employee_id')
            ->get();

        $users = $this->employees->usersFor($tasks->flatMap(fn (Task $task) => $task->taskAssignees->pluck('employee_id'))->map(fn ($id): int => (int) $id)->unique()->values()->all());

        return array_values($tasks->map(fn (Task $task): DeadlineItem => new DeadlineItem(
            'task', $task->id, 'tasks', $task->title, $task->due_date,
            $this->unique([...$task->taskAssignees->map(fn ($assignee): ?int => $users[(int) $assignee->employee_id] ?? null)->all(), $task->created_by]),
        ))->all());
    }

    /**
     * @return list<DeadlineItem>
     */
    private function milestones(string $limit): array
    {
        $milestones = Milestone::query()
            ->join('projects', 'projects.id', '=', 'milestones.project_id')
            ->whereNotNull('milestones.planned_end_date')
            ->whereDate('milestones.planned_end_date', '<=', $limit)
            ->whereNotIn('milestones.status', [MilestoneStatus::Reached->value, MilestoneStatus::Cancelled->value])
            ->toBase()
            ->get(['milestones.id', 'milestones.name', 'milestones.planned_end_date', 'milestones.responsible_user_id', 'projects.user_id as owner_id']);

        $users = $this->employees->usersFor($milestones->flatMap(fn (object $row): array => [(int) $row->responsible_user_id, (int) $row->owner_id])->unique()->values()->all());

        return array_values($milestones->map(fn (object $row): DeadlineItem => new DeadlineItem(
            'milestone', (int) $row->id, 'milestones', (string) $row->name, CarbonImmutable::parse($row->planned_end_date),
            $this->unique([$users[(int) $row->responsible_user_id] ?? null, $users[(int) $row->owner_id] ?? null]),
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
            ->whereDate('reports.planned_date', '<=', $limit)
            ->toBase()
            ->get(['reports.id', 'reports.user_id', 'reports.planned_date', 'indicators.name'])
            ->map(fn (object $row): DeadlineItem => new DeadlineItem(
                'report', (int) $row->id, 'reports', (string) $row->name, CarbonImmutable::parse($row->planned_date), [(int) $row->user_id],
            ))->all());
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
            ->get(['transfers_tracking.id', 'transfers_tracking.scheduled_date', 'transfers_tracking.payment_amount', 'transfers_tracking.amount_transfered_currency', 'donors.name as donor', 'proposals.name as proposal'])
            ->map(fn (object $row): DeadlineItem => new DeadlineItem(
                'transfer', (int) $row->id, 'transfers', $row->donor.' · '.$row->proposal.' · '.number_format((float) $row->payment_amount, 2).' '.$row->amount_transfered_currency,
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
