<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Enums\MilestoneStatus;
use App\Enums\TaskStatus;
use App\Models\DonorReport;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ScheduleBaseline;
use App\Models\ScheduleDependency;
use App\Models\Task;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the flat, ordered row list the x-ui.gantt engine renders for one
 * project: the fundraising phase of every linked proposal, the milestone tree
 * with its tasks and subtasks, and the donor obligations (transfers and
 * reports) that fall due while the project runs.
 *
 * Every row is plain data — the engine never queries — and summary rows carry
 * a span rolled up from their children so a parent always covers its work.
 *
 * @phpstan-type GanttRow array{
 *     id: string,
 *     parent: string|null,
 *     depth: int,
 *     kind: string,
 *     wbs: string,
 *     name: string,
 *     start: string|null,
 *     end: string|null,
 *     actualStart: string|null,
 *     actualEnd: string|null,
 *     progress: int,
 *     status: string|null,
 *     statusLabel: string|null,
 *     tone: string,
 *     owner: string|null,
 *     summary: bool,
 *     diamond: bool,
 *     url: string|null,
 *     money: string|null,
 *     editable: bool,
 *     progressEditable: bool,
 *     float?: int|null,
 *     critical?: bool,
 *     predecessors?: string,
 *     baselineStart?: string|null,
 *     baselineEnd?: string|null
 * }
 */
trait BuildsProjectGantt
{
    use SchedulesProjectGantt;

    /**
     * @return array{rows: list<GanttRow>, links: list<array<string, mixed>>, baselines: list<array<string, mixed>>, baselineId: int|null, weekend: string, from: string, to: string, today: string}
     */
    protected function projectGanttPayload(Project $project, ?int $baselineId = null): array
    {
        $rows = $this->newGanttRows();

        $this->pushFundraisingRows($project, $rows);
        $this->pushDeliveryRows($project, $rows);
        $this->pushObligationRows($project, $rows);

        $links = $this->decorateSchedule($project, $rows);
        $baselines = ScheduleBaseline::query()->where('project_id', $project->id)->latest('id')->get(['id', 'name', 'created_at']);
        $baseline = $baselineId === null ? null : $baselines->firstWhere('id', $baselineId);
        $this->decorateBaseline($baseline, $rows);

        $dates = [
            ...$rows->flatMap(fn (array $row): array => [$row['start'], $row['end'], $row['actualStart'], $row['actualEnd']])
                ->push($project->start_date?->toDateString(), $project->end_date?->toDateString())
                ->filter()
                ->all(),
            CarbonImmutable::today()->toDateString(),
        ];

        return [
            'rows' => array_values($rows->all()),
            'links' => $links,
            'baselines' => array_values($baselines->map(fn (ScheduleBaseline $saved): array => [
                'id' => $saved->id,
                'name' => $saved->name,
                'savedOn' => $saved->created_at?->toDateString(),
            ])->all()),
            'baselineId' => $baseline?->id,
            'weekend' => $project->schedule_weekend,
            'from' => min($dates),
            'to' => max($dates),
            'today' => CarbonImmutable::today()->toDateString(),
        ];
    }

    /**
     * Give every schedule row its total float, critical flag and MS-Project
     * style predecessors text ("1.2FS+3d"), and return the links to draw.
     *
     * @param  Collection<int, GanttRow>  $rows
     * @return list<array{id: int, from: string, to: string, type: string, lag: int, label: string}>
     */
    private function decorateSchedule(Project $project, Collection $rows): array
    {
        $weekend = $this->weekendDays($project);
        $links = $this->scheduleLinks($project->id);
        $wbs = $rows->pluck('wbs', 'id');

        $items = [];
        foreach ($rows as $row) {
            if (in_array($row['kind'], ['milestone', 'task'], true) && $row['start'] !== null && $row['end'] !== null) {
                $items[$row['id']] = ['start' => $this->dayNumber($row['start']), 'end' => $this->dayNumber($row['end'])];
            }
        }

        $links = $links->filter(fn (ScheduleDependency $link): bool => $wbs->has($link->predecessorRowId()) && $wbs->has($link->successorRowId()));
        $float = $this->totalFloat($items, $links, $weekend);

        $predecessors = [];
        foreach ($links as $link) {
            $lag = $link->lag_days === 0 ? '' : sprintf('%+dd', $link->lag_days);
            $predecessors[$link->successorRowId()][] = $wbs[$link->predecessorRowId()].$link->type->code().$lag;
        }

        $rows->transform(function (array $row) use ($float, $predecessors): array {
            $row['float'] = $float[$row['id']] ?? null;
            $row['critical'] = isset($float[$row['id']]) && $float[$row['id']] <= 0 && $row['progress'] < 100;
            $row['predecessors'] = implode(', ', $predecessors[$row['id']] ?? []);

            return $row;
        });

        return array_values($links->map(fn (ScheduleDependency $link): array => [
            'id' => $link->id,
            'from' => $link->predecessorRowId(),
            'to' => $link->successorRowId(),
            'type' => $link->type->value,
            'lag' => $link->lag_days,
            'label' => $link->type->label(),
        ])->all());
    }

    /**
     * Lay the chosen baseline's dates under each row, when one is shown.
     *
     * @param  Collection<int, GanttRow>  $rows
     */
    private function decorateBaseline(?ScheduleBaseline $baseline, Collection $rows): void
    {
        $saved = $baseline === null ? collect() : $baseline->items()->get()->keyBy(fn ($item): string => $item->item_type.'-'.$item->item_id);

        $rows->transform(function (array $row) use ($saved): array {
            $item = $saved->get($row['id']);
            $row['baselineStart'] = $item?->start_date?->toDateString();
            $row['baselineEnd'] = $item?->end_date?->toDateString();

            return $row;
        });
    }

    /**
     * One group row for the pre-award phase, with a bar per linked proposal.
     *
     * @param  Collection<int, GanttRow>  $rows
     */
    private function pushFundraisingRows(Project $project, Collection $rows): void
    {
        $proposals = $project->proposals()
            ->select(['proposals.id', 'proposals.name', 'proposals.status', 'planned_date_begin', 'planned_date_end', 'actual_date_begin', 'actual_date_end'])
            ->orderBy('planned_date_begin')
            ->get();

        if ($proposals->isEmpty()) {
            return;
        }

        $canOpenProposals = $this->ganttViewerCan('proposals.view');
        $groupIndex = count($rows->where('depth', 0)) + 1;
        $groupAt = $rows->count();
        $rows->push($this->ganttGroupRow('fundraising', (string) $groupIndex, __('gantt.fundraising')));

        foreach ($proposals->values() as $index => $proposal) {
            /** @var Proposal $proposal */
            $rows->push($this->ganttRow(
                id: 'proposal-'.$proposal->id,
                parent: 'fundraising',
                depth: 1,
                kind: 'proposal',
                wbs: $groupIndex.'.'.($index + 1),
                name: $proposal->name,
                start: $proposal->planned_date_begin,
                end: $proposal->planned_date_end,
                actualStart: $proposal->actual_date_begin,
                actualEnd: $proposal->actual_date_end,
                progress: $proposal->actual_date_end !== null ? 100 : 0,
                status: $proposal->status->value,
                statusLabel: $proposal->status->label(),
                tone: 'funding',
                url: $canOpenProposals ? route('proposals.show', $proposal) : null,
            ));
        }

        $this->rollUp($rows, $groupAt);
    }

    /**
     * The milestone tree in sequence order, each milestone followed by its
     * tasks (and their subtasks) and then its child milestones.
     *
     * @param  Collection<int, GanttRow>  $rows
     */
    private function pushDeliveryRows(Project $project, Collection $rows): void
    {
        $milestones = Milestone::query()
            ->select(['id', 'project_id', 'parent_milestone_id', 'name', 'planned_start_date', 'planned_end_date', 'actual_start_date', 'actual_end_date', 'status', 'sequence', 'responsible_user_id'])
            ->where('project_id', $project->id)
            ->with('responsibleUser:id,first_name_en,last_name_en,first_name_ar,last_name_ar')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        if ($milestones->isEmpty()) {
            return;
        }

        $tasks = Task::query()
            ->select(['id', 'parent_task_id', 'source_type', 'source_id', 'title', 'status', 'start_date', 'due_date', 'completed_date', 'progress_percentage'])
            ->where('source_type', 'milestone')
            ->whereIn('source_id', $milestones->modelKeys())
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        // Plain collections: grouped, these hold collections, not models.
        $milestonesByParent = $milestones->toBase()->groupBy(fn (Milestone $milestone): int => (int) $milestone->parent_milestone_id);
        $tasksByMilestone = $tasks->whereNull('parent_task_id')->toBase()->groupBy('source_id');
        $subtasksByParent = $tasks->whereNotNull('parent_task_id')->toBase()->groupBy('parent_task_id');

        $groupIndex = count($rows->where('depth', 0)) + 1;
        $groupAt = $rows->count();
        $rows->push($this->ganttGroupRow('delivery', (string) $groupIndex, __('gantt.delivery')));

        $this->pushMilestoneBranch($milestonesByParent, $tasksByMilestone, $subtasksByParent, 0, 'delivery', 1, (string) $groupIndex, 0, $rows);

        $this->rollUp($rows, $groupAt);
    }

    /**
     * @param  Collection<array-key, Collection<int, Milestone>>  $milestonesByParent
     * @param  Collection<array-key, Collection<int, Task>>  $tasksByMilestone
     * @param  Collection<array-key, Collection<int, Task>>  $subtasksByParent
     * @param  Collection<int, GanttRow>  $rows
     */
    private function pushMilestoneBranch(Collection $milestonesByParent, Collection $tasksByMilestone, Collection $subtasksByParent, int $parentId, string $parentRowId, int $depth, string $wbsPrefix, int $indexOffset, Collection $rows): void
    {
        foreach (($milestonesByParent->get($parentId) ?? collect())->values() as $index => $milestone) {
            /** @var Milestone $milestone */
            $wbs = $wbsPrefix.'.'.($indexOffset + $index + 1);
            $at = $rows->count();
            $rowId = 'milestone-'.$milestone->id;
            $childTasks = $tasksByMilestone->get($milestone->id) ?? collect();
            $hasChildren = $childTasks->isNotEmpty() || $milestonesByParent->has($milestone->id);

            $rows->push($this->ganttRow(
                id: $rowId,
                parent: $parentRowId,
                depth: $depth,
                kind: 'milestone',
                wbs: $wbs,
                name: $milestone->name,
                start: $milestone->planned_start_date,
                end: $milestone->planned_end_date ?? $milestone->planned_start_date,
                actualStart: $milestone->actual_start_date,
                actualEnd: $milestone->actual_end_date,
                progress: $milestone->status === MilestoneStatus::Reached ? 100 : 0,
                status: $milestone->status->value,
                statusLabel: $milestone->status->label(),
                tone: $this->milestoneTone($milestone),
                owner: $milestone->responsibleUser?->name,
                summary: $hasChildren,
                diamond: ! $hasChildren && ($milestone->planned_end_date === null || $milestone->planned_end_date->equalTo($milestone->planned_start_date)),
                url: route('milestones.show', $milestone),
                editable: true,
            ));

            $childIndex = 0;
            foreach ($childTasks as $task) {
                $this->pushTaskBranch($task, $subtasksByParent, $rowId, $depth + 1, $wbs.'.'.(++$childIndex), $rows);
            }

            $this->pushMilestoneBranch($milestonesByParent, $tasksByMilestone, $subtasksByParent, $milestone->id, $rowId, $depth + 1, $wbs, $childIndex, $rows);

            if ($hasChildren) {
                $this->rollUp($rows, $at, keepProgress: $milestone->status === MilestoneStatus::Reached);
            }
        }
    }

    /**
     * @param  Collection<int|string, Collection<int, Task>>  $subtasksByParent
     * @param  Collection<int, GanttRow>  $rows
     */
    private function pushTaskBranch(Task $task, Collection $subtasksByParent, string $parentRowId, int $depth, string $wbs, Collection $rows): void
    {
        $at = $rows->count();
        $subtasks = $subtasksByParent->get($task->id) ?? collect();
        $start = $task->start_date ?? $task->due_date;

        $rows->push($this->ganttRow(
            id: 'task-'.$task->id,
            parent: $parentRowId,
            depth: $depth,
            kind: 'task',
            wbs: $wbs,
            name: $task->title,
            start: $start,
            end: $task->due_date ?? $start,
            actualStart: null,
            actualEnd: $task->completed_date,
            progress: $task->status === TaskStatus::Done ? 100 : (int) $task->progress_percentage,
            status: $task->status->value,
            statusLabel: $task->status->label(),
            tone: $this->taskTone($task),
            summary: $subtasks->isNotEmpty(),
            url: route('tasks.show', $task),
            editable: true,
        ));

        foreach ($subtasks->values() as $index => $subtask) {
            $this->pushTaskBranch($subtask, $subtasksByParent, 'task-'.$task->id, $depth + 1, $wbs.'.'.($index + 1), $rows);
        }

        if ($subtasks->isNotEmpty()) {
            $this->rollUp($rows, $at, keepProgress: $task->status === TaskStatus::Done);
        }
    }

    /**
     * Transfers and donor reports of every agreement funding this project,
     * each drawn as a diamond on its due or scheduled date.
     *
     * Pass a donor proposal id to narrow the rows to that one agreement, or a
     * list of proposal ids for every agreement signed against them. When a
     * $relations collection is given, each row is tied to its proposal
     * ('pays' for a transfer, 'reports' for a donor report) so the timeline
     * can trace the money back to the work it funds.
     *
     * Transfers need transfers.view and donor reports need
     * funding-agreements.view: a viewer holding neither gets no obligations
     * group and no query runs, since both carry amounts and agreement data.
     *
     * @param  int|list<int>|Project  $source
     * @param  Collection<int, GanttRow>  $rows
     * @param  Collection<int, array{from: string, to: string, type: string}>|null  $relations
     */
    private function pushObligationRows(Project|int|array $source, Collection $rows, ?Collection $relations = null): void
    {
        $canSeeTransfers = $this->ganttViewerCan('transfers.view');
        $canSeeReports = $this->ganttViewerCan('funding-agreements.view');

        if ((! $canSeeTransfers && ! $canSeeReports) || $source === []) {
            return;
        }

        $scope = match (true) {
            $source instanceof Project => fn ($query) => $query->whereIn('proposal_id', $source->proposals()->pluck('proposals.id')),
            is_array($source) => fn ($query) => $query->whereIn('proposal_id', $source),
            default => fn ($query) => $query->whereKey($source),
        };

        $transfers = ! $canSeeTransfers ? collect() : TransfersTracking::query()
            ->whereHas('donorProposal', $scope)
            ->with('donorProposal.donor:id,name')
            ->get()
            ->filter(fn (TransfersTracking $transfer): bool => ($transfer->scheduled_date ?? $transfer->transfer_date) !== null);

        $reports = ! $canSeeReports ? collect() : DonorReport::query()
            ->whereHas('donorProposal', $scope)
            ->with('donorProposal.donor:id,name')
            ->get();

        if ($transfers->isEmpty() && $reports->isEmpty()) {
            return;
        }

        $groupIndex = count($rows->where('depth', 0)) + 1;
        $groupAt = $rows->count();
        $rows->push($this->ganttGroupRow('obligations', (string) $groupIndex, __('gantt.donor_obligations')));

        $entries = collect();

        foreach ($transfers as $transfer) {
            /** @var TransfersTracking $transfer */
            $state = $transfer->state();
            $date = $transfer->scheduled_date ?? $transfer->transfer_date;

            $entries->push(['date' => $date, 'row' => fn (string $wbs): array => $this->ganttRow(
                id: 'transfer-'.$transfer->id,
                parent: 'obligations',
                depth: 1,
                kind: 'transfer',
                wbs: $wbs,
                name: __('gantt.transfer_from', ['donor' => $transfer->donorProposal->donor->name ?? '—']),
                start: $date,
                end: $date,
                actualStart: null,
                actualEnd: $transfer->receive_date,
                progress: $state === 'received' ? 100 : 0,
                status: $state,
                statusLabel: match ($state) {
                    'received' => __('gantt.transfer_state.received'),
                    'failed' => __('gantt.transfer_state.failed'),
                    'transit' => __('gantt.transfer_state.transit'),
                    'late' => __('gantt.transfer_state.late'),
                    default => __('gantt.transfer_state.upcoming'),
                },
                tone: match ($state) {
                    'received' => 'good',
                    'late', 'failed' => 'critical',
                    'transit' => 'warn',
                    default => 'funding',
                },
                diamond: true,
                url: route('transfers.index'),
                money: $this->ganttMoney([[$transfer->payment_amount, $transfer->amount_transfered_currency->value]]),
            )]);

            $relations?->push(['from' => 'transfer-'.$transfer->id, 'to' => 'proposal-'.$transfer->donorProposal?->proposal_id, 'type' => 'pays']);
        }

        foreach ($reports as $report) {
            /** @var DonorReport $report */
            $reportState = $report->isSubmitted() ? 'submitted' : ($report->isOverdue() ? 'overdue' : 'due');
            $reportLabel = match ($reportState) {
                'submitted' => __('gantt.report_state.submitted'),
                'overdue' => __('gantt.report_state.overdue'),
                default => __('gantt.report_state.due'),
            };

            $entries->push(['date' => $report->due_on, 'row' => fn (string $wbs): array => $this->ganttRow(
                id: 'report-'.$report->id,
                parent: 'obligations',
                depth: 1,
                kind: 'report',
                wbs: $wbs,
                name: $report->title,
                start: $report->due_on,
                end: $report->due_on,
                actualStart: null,
                actualEnd: $report->submitted_on,
                progress: $report->isSubmitted() ? 100 : 0,
                status: $reportState,
                statusLabel: $reportLabel,
                tone: $report->isSubmitted() ? 'good' : ($report->isOverdue() ? 'critical' : 'neutral'),
                owner: $report->donorProposal?->donor?->name,
                diamond: true,
                url: $report->donorProposal === null ? null : route('funding-agreements.show', $report->donorProposal),
            )]);

            $relations?->push(['from' => 'report-'.$report->id, 'to' => 'proposal-'.$report->donorProposal?->proposal_id, 'type' => 'reports']);
        }

        foreach ($entries->sortBy(fn (array $entry): int => $entry['date']->getTimestamp())->values() as $index => $entry) {
            $rows->push(($entry['row'])($groupIndex.'.'.($index + 1)));
        }

        $this->rollUp($rows, $groupAt);
    }

    /**
     * Widen the row at $at to cover every descendant pushed after it, and
     * give it the duration-weighted progress of its direct children.
     *
     * @param  Collection<int, GanttRow>  $rows
     */
    private function rollUp(Collection $rows, int $at, bool $keepProgress = false): void
    {
        $parent = $rows->get($at);
        $descendants = $rows->slice($at + 1)->takeWhile(fn (array $row): bool => $row['depth'] > $parent['depth']);
        $children = $descendants->where('depth', $parent['depth'] + 1);

        $starts = $descendants->pluck('start')->push($parent['start'])->filter();
        $ends = $descendants->pluck('end')->push($parent['end'])->filter();

        $weight = 0;
        $done = 0;
        foreach ($children as $child) {
            if ($child['start'] === null || $child['end'] === null) {
                continue;
            }
            $days = CarbonImmutable::parse($child['start'])->diffInDays(CarbonImmutable::parse($child['end'])) + 1;
            $weight += $days;
            $done += $days * $child['progress'];
        }

        $parent['start'] = $starts->min();
        $parent['end'] = $ends->max();
        $parent['summary'] = true;
        $parent['diamond'] = false;
        $parent['progressEditable'] = false;
        if (! $keepProgress && $weight > 0) {
            $parent['progress'] = (int) round($done / $weight);
        }

        $rows->put($at, $parent);
    }

    /**
     * An empty row list for the push*Rows() builders to fill.
     *
     * @return Collection<int, GanttRow>
     */
    private function newGanttRows(): Collection
    {
        return new Collection;
    }

    /**
     * @return GanttRow
     */
    private function ganttGroupRow(string $id, string $wbs, string $name): array
    {
        return $this->ganttRow(id: $id, parent: null, depth: 0, kind: 'group', wbs: $wbs, name: $name, start: null, end: null, tone: 'neutral', summary: true);
    }

    /**
     * @return GanttRow
     */
    private function ganttRow(
        string $id,
        ?string $parent,
        int $depth,
        string $kind,
        string $wbs,
        string $name,
        ?CarbonImmutable $start,
        ?CarbonImmutable $end,
        ?CarbonImmutable $actualStart = null,
        ?CarbonImmutable $actualEnd = null,
        int $progress = 0,
        ?string $status = null,
        ?string $statusLabel = null,
        string $tone = 'neutral',
        ?string $owner = null,
        bool $summary = false,
        bool $diamond = false,
        ?string $url = null,
        bool $editable = false,
        ?string $money = null,
    ): array {
        if ($start !== null && $end !== null && $end->lt($start)) {
            $end = $start;
        }

        return [
            'id' => $id,
            'parent' => $parent,
            'depth' => $depth,
            'kind' => $kind,
            'wbs' => $wbs,
            'name' => $name,
            'start' => $start?->toDateString(),
            'end' => $end?->toDateString(),
            'actualStart' => $actualStart?->toDateString(),
            'actualEnd' => $actualEnd?->toDateString(),
            'progress' => max(0, min(100, $progress)),
            'status' => $status,
            'statusLabel' => $statusLabel,
            'tone' => $tone,
            'owner' => $owner,
            'summary' => $summary,
            'diamond' => $diamond,
            'url' => $url,
            'money' => $money,
            'editable' => $editable,
            'progressEditable' => $editable && $kind === 'task' && ! $summary,
        ];
    }

    /**
     * Whether the signed-in viewer holds a permission, for rows that carry
     * money or link to a page the viewer may not be allowed to open.
     */
    private function ganttViewerCan(string $permission): bool
    {
        return auth()->user()?->can($permission) ?? false;
    }

    /**
     * Amounts as "12,500 USD · 3,000 EUR", one figure per currency — USD, EUR
     * and SYP are never converted or added together.
     *
     * @param  iterable<array{0: string|float|int|null, 1: string|null}>  $amounts  [amount, currency] pairs
     */
    private function ganttMoney(iterable $amounts): ?string
    {
        $totals = [];

        foreach ($amounts as [$amount, $currency]) {
            if ($amount === null || $currency === null || $currency === '') {
                continue;
            }

            $totals[$currency] = ($totals[$currency] ?? 0) + (float) $amount;
        }

        if ($totals === []) {
            return null;
        }

        return collect($totals)
            ->map(fn (float $total, string $currency): string => number_format($total, fmod($total, 1.0) === 0.0 ? 0 : 2).' '.$currency)
            ->implode(' · ');
    }

    private function milestoneTone(Milestone $milestone): string
    {
        return match (true) {
            $milestone->status === MilestoneStatus::Reached => 'good',
            $milestone->status === MilestoneStatus::Cancelled => 'muted',
            $milestone->isMissed() || $milestone->isOverdue() => 'critical',
            $milestone->status === MilestoneStatus::Ongoing => 'warn',
            default => 'neutral',
        };
    }

    private function taskTone(Task $task): string
    {
        return match (true) {
            $task->status === TaskStatus::Done => 'good',
            $task->due_date !== null && $task->due_date->isPast() => 'critical',
            $task->status === TaskStatus::InProgress, $task->status === TaskStatus::UnderReview => 'warn',
            default => 'neutral',
        };
    }
}
