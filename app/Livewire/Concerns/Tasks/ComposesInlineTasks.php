<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Renderless;

/**
 * The tasks list's inline composers: the "+ Add task" row under each group
 * heading and the "add subtask" row in an expanded task, both created
 * straight from a title and a draft without opening the drawer.
 *
 * Used by tasks/index only. quickCreateTask() falls back to
 * openCreateDrawer() from ManagesTaskDrawer when the source or assignee
 * cannot be inferred, and calls resetPage() from WithPagination.
 *
 * @property string $sourceType
 * @property string $sourceId
 * @property string $title
 * @property string $taskPriority
 * @property string $dueDate
 * @property array<int, string> $assignedTo
 */
trait ComposesInlineTasks
{
    /**
     * Per-parent-task draft values for the list view's inline "add subtask"
     * row — its due date, priority, status and assignees. Bound with plain
     * (non-live) wire:model so filling them out costs no network round
     * trips; they're only read once, when addSubtaskQuick() actually creates
     * the subtask in the same request as the Enter keypress that submits
     * the title.
     *
     * @var array<int, array{status?: string, priority?: string, due_date?: string, assignees?: array<int, string>}>
     */
    public array $subtaskDrafts = [];

    /**
     * Per-group draft values for the list view's inline "+ Add task" row —
     * one per group heading (keyed by "{group}|{groupKeyString}", since each
     * heading renders its own composer row independently), covering the
     * same due date, priority, status and assignees a real row edits. Mirrors
     * subtaskDrafts in every other respect.
     *
     * @var array<string, array{status?: string, priority?: string, due_date?: string, assignees?: array<int, string>}>
     */
    public array $taskDrafts = [];

    /**
     * A group's inline "+ Add task" row only captures a title, but a task
     * always needs a source and an assignee. Both are inferred from context
     * so the row can create the task directly, with no drawer:
     *  - source: the row's own milestone (its own milestoneId, when the row
     *    is grouped or scheduled under one), falling back to the active
     *    source-type/source-id filter — no milestone context and no active
     *    single-record filter is genuinely ambiguous.
     *  - assignee: whichever employees the row's own draft (see taskDraft())
     *    has picked, falling back to the signed-in user's own HR employee
     *    record when the row hasn't been touched.
     * Due date, priority and status also come from that same draft, same as
     * addSubtaskQuick()'s own draft. When the source or every assignee can't
     * be resolved, this falls back to the drawer exactly as before,
     * pre-filled with the title and whatever the row's own context and
     * draft already had, so nothing is ever silently dropped.
     */
    public function quickCreateTask(string $title, ?string $status = null, ?string $milestoneId = null, ?string $rowKey = null, ?string $dueDate = null): void
    {
        Gate::authorize('create', Task::class);

        $title = trim($title);

        if ($title === '') {
            return;
        }

        $draft = $rowKey !== null ? $this->taskDraft($rowKey, $status) : null;

        $source = $this->resolveInlineSource($milestoneId);
        $employeeIds = $draft !== null && $draft['assignees'] !== []
            ? HrEmployee::query()->whereIn('id', collect($draft['assignees'])->map(fn (string $id): int => (int) $id))->pluck('id')
            : collect(array_filter([$this->resolveInlineAssigneeId()]));

        if ($source === null || $employeeIds->isEmpty()) {
            $this->openCreateDrawer(dueDate: $dueDate, status: $draft !== null ? $draft['status'] : $status, milestoneId: $milestoneId);
            $this->title = $title;

            if ($draft !== null) {
                $this->taskPriority = $draft['priority'];
                $this->assignedTo = $draft['assignees'];

                if ($dueDate === null && $draft['due_date'] !== '') {
                    $this->dueDate = $draft['due_date'];
                }
            }

            return;
        }

        $statusCase = TaskStatus::tryFrom(($draft !== null ? $draft['status'] : $status) ?? '') ?? TaskStatus::Todo;
        $priorityCase = $draft !== null ? (TaskPriority::tryFrom($draft['priority']) ?? TaskPriority::Medium) : TaskPriority::Medium;

        // A calendar day's row passes its own day as $dueDate, which always
        // wins — that row has no due-date field of its own precisely because
        // the cell it's in already fixes the date. Every other row's due
        // date, if any, comes from its own draft.
        $resolvedDueDate = $dueDate ?? ($draft !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $draft['due_date']) === 1 ? $draft['due_date'] : null);

        DB::transaction(function () use ($title, $statusCase, $priorityCase, $resolvedDueDate, $source, $employeeIds): void {
            $task = Task::query()->create([
                'title' => $title,
                'status' => $statusCase,
                'priority' => $priorityCase,
                'due_date' => $resolvedDueDate,
                'progress_percentage' => $statusCase === TaskStatus::Done ? 100 : 0,
                'completed_date' => $statusCase === TaskStatus::Done ? CarbonImmutable::today() : null,
                'source_type' => $source['type'],
                'source_id' => $source['id'],
                'created_by' => (int) auth()->id(),
            ]);

            foreach ($employeeIds as $employeeId) {
                $task->taskAssignees()->create(['employee_id' => $employeeId]);
            }
        });

        if ($rowKey !== null) {
            unset($this->taskDrafts[$rowKey]);
        }

        $this->resetPage();

        $this->toastQuickSuccess(__('tasks.task_created'));
    }

    /**
     * The list view's inline "+ Add task" row's current draft values for one
     * group heading, merged with sensible defaults so the row always renders
     * something sane before the user touches it. $defaultStatus lets a
     * status-grouped heading default its own row to that column's status
     * instead of always "todo" — mirrors subtaskDraft() in every other
     * respect.
     *
     * @return array{status: string, priority: string, due_date: string, assignees: array<int, string>}
     */
    protected function taskDraft(string $rowKey, ?string $defaultStatus = null): array
    {
        $defaults = [
            'status' => $defaultStatus ?? TaskStatus::Todo->value,
            'priority' => TaskPriority::Medium->value,
            'due_date' => '',
            'assignees' => [],
        ];

        return array_merge($defaults, $this->taskDrafts[$rowKey] ?? []);
    }

    /**
     * Adds or removes one employee from a group heading's "+ Add task" draft
     * — mirrors toggleSubtaskDraftAssignee() but for a not-yet-created
     * top-level task instead of a subtask.
     */
    #[Renderless]
    public function toggleTaskDraftAssignee(string $rowKey, int $employeeId): void
    {
        $ids = $this->taskDraft($rowKey)['assignees'];
        $employeeIdString = (string) $employeeId;

        $this->taskDrafts[$rowKey]['assignees'] = in_array($employeeIdString, $ids, true)
            ? array_values(array_diff($ids, [$employeeIdString]))
            : [...$ids, $employeeIdString];
    }

    /**
     * Resolves the source a group's inline "+ Add task" row can safely
     * default to, or null when that would be a guess: its own milestone
     * context when given (the row is grouped or scheduled under one),
     * otherwise the active filter bar's source type/id pair when it names
     * a single record.
     *
     * @return array{type: string, id: int}|null
     */
    protected function resolveInlineSource(?string $milestoneId): ?array
    {
        if ($milestoneId !== null) {
            return Milestone::query()->whereKey((int) $milestoneId)->exists()
                ? ['type' => 'milestone', 'id' => (int) $milestoneId]
                : null;
        }

        if ($this->sourceType !== '' && $this->sourceId !== '') {
            return ['type' => $this->sourceType, 'id' => (int) $this->sourceId];
        }

        return null;
    }

    /** The signed-in user's own HR employee id, when their account is linked to one. */
    protected function resolveInlineAssigneeId(): ?int
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->employee_id;
    }

    /**
     * The list view's inline "add subtask" row's current draft values for a
     * parent task, merged with sensible defaults — todo/medium, no due date,
     * and the parent's own assignees — so the row always renders something
     * sane before the user touches it. Takes the parent (already loaded with
     * its assignees by filteredTasksQuery()) rather than an id, so reading
     * this once per row costs no extra query.
     *
     * @return array{status: string, priority: string, due_date: string, assignees: array<int, string>}
     */
    protected function subtaskDraft(Task $parent): array
    {
        $defaults = [
            'status' => TaskStatus::Todo->value,
            'priority' => TaskPriority::Medium->value,
            'due_date' => '',
            'assignees' => $parent->taskAssignees->pluck('employee_id')->map(fn (int $id): string => (string) $id)->all(),
        ];

        return array_merge($defaults, $this->subtaskDrafts[$parent->id] ?? []);
    }

    /**
     * Adds or removes one employee from a parent task's subtask draft, for
     * the "add subtask" row's own assignee popover — mirrors toggleAssignee()
     * but writes to the not-yet-saved draft instead of a real task.
     */
    #[Renderless]
    public function toggleSubtaskDraftAssignee(int $parentTaskId, int $employeeId): void
    {
        $parent = Task::query()->with('taskAssignees:id,task_id,employee_id')->find($parentTaskId);

        if ($parent === null) {
            return;
        }

        $ids = $this->subtaskDraft($parent)['assignees'];
        $employeeIdString = (string) $employeeId;

        $this->subtaskDrafts[$parentTaskId]['assignees'] = in_array($employeeIdString, $ids, true)
            ? array_values(array_diff($ids, [$employeeIdString]))
            : [...$ids, $employeeIdString];
    }

    /**
     * Adds a one-line subtask under an already-visible parent task, from the
     * inline row in its expanded panel — no drawer. Proposal and milestone
     * always match the parent (a subtask delivers the same thing its parent
     * does), while due date, priority, status and assignees come from the
     * row's own draft fields, defaulting to the parent's own assignees when
     * none were chosen. Mirrors the show page's own addSubtask(), kept
     * separate because this is a different Livewire component instance.
     */
    public function addSubtaskQuick(int $parentTaskId, string $title): void
    {
        $title = trim($title);

        if ($title === '') {
            return;
        }

        $parent = Task::query()->with('taskAssignees:id,task_id,employee_id')->find($parentTaskId);

        if ($parent === null || $parent->parent_task_id !== null) {
            return;
        }

        Gate::authorize('create', Task::class);

        $draft = $this->subtaskDraft($parent);
        $status = TaskStatus::tryFrom($draft['status']) ?? TaskStatus::Todo;
        $priority = TaskPriority::tryFrom($draft['priority']) ?? TaskPriority::Medium;
        $dueDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $draft['due_date']) === 1 ? $draft['due_date'] : null;

        $employeeIds = HrEmployee::query()
            ->whereIn('id', collect($draft['assignees'])->map(fn (string $id): int => (int) $id))
            ->pluck('id');

        if ($employeeIds->isEmpty()) {
            $employeeIds = $parent->taskAssignees->pluck('employee_id');
        }

        DB::transaction(function () use ($parent, $title, $status, $priority, $dueDate, $employeeIds): void {
            $subtask = Task::query()->create([
                'parent_task_id' => $parent->id,
                'source_type' => $parent->source_type,
                'source_id' => $parent->source_id,
                'title' => $title,
                'status' => $status,
                'priority' => $priority,
                'due_date' => $dueDate,
                'progress_percentage' => $status === TaskStatus::Done ? 100 : 0,
                'completed_date' => $status === TaskStatus::Done ? CarbonImmutable::today() : null,
                'created_by' => (int) auth()->id(),
            ]);

            foreach ($employeeIds as $employeeId) {
                $subtask->taskAssignees()->create(['employee_id' => $employeeId]);
            }
        });

        unset($this->subtaskDrafts[$parentTaskId]);

        $this->toastQuickSuccess(__('tasks.subtask_added'));
    }
}
