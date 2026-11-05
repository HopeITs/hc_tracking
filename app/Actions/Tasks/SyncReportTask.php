<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\DonorReport;
use App\Models\Task;
use App\Models\TaskAssignee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Keep the one report task of a donor report in step with the report
 * (contracts §12.2 and §6, D-64).
 *
 * - A report with a responsible has exactly one open task for them: created
 *   when none is open, moved to the new person when the responsible changed.
 * - A submitted report closes its task (`done`, completed today). The other
 *   way round never happens: finishing the task by hand does not submit the
 *   report.
 * - A report that is no longer submitted gets its closed task back.
 * - A newer version of the same report (same agreement, type and period)
 *   takes over from the version it supersedes: the older version's open
 *   task is deleted, so the person has one task per period.
 *
 * No Gate: the caller (the report action) has already authorised. Run it
 * inside the caller's transaction; the report row is locked here.
 */
final class SyncReportTask
{
    public function handle(DonorReport $report): ?Task
    {
        return DB::transaction(function () use ($report): ?Task {
            $locked = DonorReport::query()->whereKey($report->getKey())->with('donorProposal.donor')->lockForUpdate()->firstOrFail();

            $this->releaseSuperseded($locked);

            $task = $this->latestTask($locked);

            if ($locked->isSubmitted()) {
                return $task === null ? null : $this->complete($task);
            }

            if ($locked->responsible_employee_id === null) {
                return $task;
            }

            if ($task === null) {
                return $this->create($locked);
            }

            if ($task->status === TaskStatus::Done) {
                $this->reopen($task);
            }

            return $this->assign($task, $locked->responsible_employee_id);
        });
    }

    /**
     * Delete the report's open task, for a report that is going away.
     */
    public function forget(DonorReport $report): void
    {
        DB::transaction(function () use ($report): void {
            $this->tasksOf($report->getKey())->get()->each(fn (Task $task) => $task->delete());
        });
    }

    /**
     * @return Builder<Task>
     */
    private function tasksOf(int $reportId): Builder
    {
        return Task::query()
            ->withoutGlobalScopes()
            ->where('kind', TaskKind::Report->value)
            ->where('reportable_type', 'donor_report')
            ->where('reportable_id', $reportId);
    }

    /**
     * The open task, else the most recent closed one.
     */
    private function latestTask(DonorReport $report): ?Task
    {
        return $this->tasksOf($report->id)
            ->orderByRaw('status = ? asc', [TaskStatus::Done->value])
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    private function create(DonorReport $report): Task
    {
        $actorId = Auth::id();

        if ($actorId === null) {
            throw new LogicException('A report task is created by a signed-in user.');
        }

        $agreement = $report->donorProposal;
        $period = $report->isComposed()
            ? $report->period_start->format('Y-m').' – '.$report->period_end->format('Y-m')
            : $report->title;

        $task = Task::query()->create([
            'source_type' => 'proposal',
            'source_id' => $agreement->proposal_id,
            'kind' => TaskKind::Report,
            'reportable_type' => 'donor_report',
            'reportable_id' => $report->id,
            'title' => __('donor_reports.report_task_title', ['donor' => $agreement->donor->name, 'period' => $period]),
            'due_date' => $report->due_on->toDateString(),
            'created_by' => $actorId,
        ]);

        $task->taskAssignees()->create(['employee_id' => $report->responsible_employee_id]);

        return $task;
    }

    /**
     * Leave exactly one assignee: the responsible.
     */
    private function assign(Task $task, int $employeeId): Task
    {
        $assignees = $task->taskAssignees()->get();

        if ($assignees->count() === 1 && (int) $assignees->first()->employee_id === $employeeId) {
            return $task;
        }

        $assignees->reject(fn (TaskAssignee $assignee): bool => (int) $assignee->employee_id === $employeeId)
            ->each(fn (TaskAssignee $assignee) => $assignee->delete());

        if (! $assignees->contains(fn (TaskAssignee $assignee): bool => (int) $assignee->employee_id === $employeeId)) {
            $task->taskAssignees()->create(['employee_id' => $employeeId]);
        }

        return $task;
    }

    private function complete(Task $task): Task
    {
        if ($task->status === TaskStatus::Done) {
            return $task;
        }

        $task->status = TaskStatus::Done;
        $task->completed_date = CarbonImmutable::today();
        $task->progress_percentage = 100;
        $task->save();

        return $task;
    }

    private function reopen(Task $task): void
    {
        $task->status = TaskStatus::Todo;
        $task->completed_date = null;
        $task->progress_percentage = 0;
        $task->save();
    }

    /**
     * Older, unsubmitted versions of the same report lose their open task.
     */
    private function releaseSuperseded(DonorReport $report): void
    {
        if ($report->version <= 1) {
            return;
        }

        $olderIds = DonorReport::query()
            ->where('donor_proposal_id', $report->donor_proposal_id)
            ->where('type', $report->type->value)
            ->where('period_start', $report->period_start?->toDateString())
            ->where('period_end', $report->period_end?->toDateString())
            ->where('version', '<', $report->version)
            ->whereNull('submitted_on')
            ->pluck('id');

        foreach ($olderIds as $olderId) {
            $this->tasksOf((int) $olderId)->where('status', '!=', TaskStatus::Done->value)->get()->each(fn (Task $task) => $task->delete());
        }
    }
}
