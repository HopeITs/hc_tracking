<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Programs;

use App\Actions\Tasks\ChangeTaskStatus;
use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\DeleteTask;
use App\Actions\Tasks\RescheduleTask;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\HrEmployee;
use App\Models\ProposalLocation;
use App\Models\Task;
use App\Notifications\UnreadSubjects;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;

/**
 * The program page's Tasks tab: its filter, grouping and search, and every
 * action a row offers (status, priority, due date, assignees, move,
 * subtask, delete, quick add).
 *
 * Every action first finds the task inside this program, so a task id from
 * another program is ignored, and then goes through the same actions and
 * policies the tasks pages use. The page names its program in $programId.
 */
trait ManagesProgramTasks
{
    /** The tab's filters, in the order the stat cards show them. */
    public const TASK_FILTERS = ['open', 'overdue', 'week', 'mine', 'done', 'all'];

    /** The ways the tab can group its tasks; the first is the default. */
    public const TASK_GROUPINGS = ['project', 'due', 'assignee'];

    #[Url(as: 'show', except: 'open')]
    public string $taskFilter = 'open';

    #[Url(as: 'group', except: 'project')]
    public string $taskGroupBy = 'project';

    public string $taskSearch = '';

    /** The bucket picked in the side list ("all", "project-12", "overdue", "employee-7"…). */
    #[Url(as: 'in', except: 'all')]
    public string $taskBucket = 'all';

    #[Url(as: 'page', except: 1)]
    public int $taskPage = 1;

    /** Rows per page in the task list. */
    public const TASK_PAGE_SIZE = 7;

    public function updatedTaskFilter(): void
    {
        $this->taskFilter = in_array($this->taskFilter, self::TASK_FILTERS, true) ? $this->taskFilter : 'open';
        $this->taskPage = 1;
    }

    public function updatedTaskGroupBy(): void
    {
        $this->taskGroupBy = in_array($this->taskGroupBy, self::TASK_GROUPINGS, true) ? $this->taskGroupBy : 'project';
        $this->taskBucket = 'all';
        $this->taskPage = 1;
    }

    public function updatedTaskSearch(): void
    {
        $this->taskPage = 1;
    }

    /**
     * Pick a filter, or clear back to the open tasks when the picked one is
     * already on.
     */
    public function pickTaskFilter(string $filter): void
    {
        $this->taskFilter = $this->taskFilter === $filter ? 'open' : $filter;
        $this->updatedTaskFilter();
    }

    /**
     * Show one bucket of the side list. Only a key shaped like the side
     * list's own keys is taken; anything else shows every task.
     */
    public function pickTaskBucket(string $bucket): void
    {
        $this->taskBucket = preg_match('/^(all|program|unassigned|overdue|week|later|none|done|(project|employee)-\d+)$/', $bucket) === 1 ? $bucket : 'all';
        $this->taskPage = 1;
    }

    public function goToTaskPage(int $page): void
    {
        $this->taskPage = max(1, $page);
    }

    /**
     * Limit a task query to this program's tasks: raised against the program,
     * or one of its places (Y-16, D-93).
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    protected function scopeToProgramTasks(Builder $query): Builder
    {
        $locationIds = ProposalLocation::query()->select('id')->where('program_id', $this->programId);

        return $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->where('source_type', 'program')->where('source_id', $this->programId))
            ->orWhere(fn (Builder $query) => $query->where('source_type', 'proposal_location')->whereIn('source_id', $locationIds)));
    }

    /**
     * One of this program's tasks, or null (and a toast) when it is gone or
     * belongs elsewhere.
     */
    protected function programTask(int $taskId): ?Task
    {
        $task = $this->scopeToProgramTasks(Task::query())->whereKey($taskId)->first();

        if ($task === null) {
            Flux::toast(variant: 'danger', text: __('tasks.this_task_no_longer_exists'));
        }

        return $task;
    }

    /**
     * Tasks with unread notifications for me, for the row dots (as on the Tasks page).
     *
     * @return list<int>
     */
    #[Computed]
    public function unreadTaskIds(): array
    {
        return app(UnreadSubjects::class)->ids(auth()->user(), 'task');
    }

    public function setProgramTaskStatus(int $taskId, string $status): void
    {
        $newStatus = TaskStatus::tryFrom($status);
        $task = $newStatus === null ? null : $this->programTask($taskId);

        if ($task === null || $newStatus === null) {
            return;
        }

        try {
            $changed = app(ChangeTaskStatus::class)->handle($task, $newStatus);
        } catch (ValidationException $refused) {
            Flux::toast(variant: 'danger', text: $refused->validator->errors()->first());

            return;
        }

        if (! $changed) {
            Flux::toast(variant: 'danger', text: __('tasks.completed_tasks_cannot_change_status'));

            return;
        }

        $this->programTasksChanged(__('tasks.task_status_changed', ['status' => $newStatus->label()]));
    }

    public function setProgramTaskPriority(int $taskId, string $priority): void
    {
        $newPriority = TaskPriority::tryFrom($priority);
        $task = $newPriority === null ? null : $this->programTask($taskId);

        if ($task === null || $newPriority === null) {
            return;
        }

        Gate::authorize('update', $task);

        $task->priority = $newPriority;
        $task->save();

        $this->programTasksChanged(__('tasks.task_priority_changed', ['priority' => $newPriority->label()]));
    }

    /**
     * Move a task's due date, or clear it with an empty string. Only the
     * task's creator may (RescheduleTask).
     */
    public function rescheduleProgramTask(int $taskId, string $date): void
    {
        if ($this->programTask($taskId) === null) {
            return;
        }

        $moved = app(RescheduleTask::class)->handle($taskId, $date);

        if ($moved === false) {
            Flux::toast(variant: 'danger', text: __('tasks.only_the_task_creator_can_change_the_due_date'));

            return;
        }

        if ($moved === null) {
            return;
        }

        $this->programTasksChanged(__('tasks.task_rescheduled'));
    }

    /**
     * Add or remove one assignee, from the shared assignee popover. Removing
     * the last one leaves the task unassigned.
     *
     * Renderless: the popover moves its check marks itself and refreshes the
     * page once it closes (see assignee-picker.js).
     */
    #[Renderless]
    public function togglePickedAssignee(int $taskId, int $employeeId): void
    {
        $task = $this->programTask($taskId);

        if ($task === null || ! HrEmployee::query()->whereKey($employeeId)->exists()) {
            return;
        }

        Gate::authorize('update', $task);

        $existing = $task->taskAssignees()->where('employee_id', $employeeId)->first();

        if ($existing === null) {
            $task->taskAssignees()->create(['employee_id' => $employeeId]);

            return;
        }

        $existing->delete();
    }

    /**
     * Rename a task in place, from the pencil beside its title.
     */
    public function renameProgramTask(int $taskId, string $title): void
    {
        $title = mb_substr(trim($title), 0, 255);
        $task = $title === '' ? null : $this->programTask($taskId);

        if ($task === null) {
            return;
        }

        Gate::authorize('update', $task);

        if ($task->title === $title) {
            return;
        }

        $task->title = $title;
        $task->save();

        $this->programTasksChanged(__('programs.task_renamed'));
    }

    /**
     * Take one person off a task, from the × on their photo in the row.
     */
    public function removeProgramTaskAssignee(int $taskId, int $employeeId): void
    {
        $task = $this->programTask($taskId);

        if ($task === null) {
            return;
        }

        Gate::authorize('update', $task);

        $task->taskAssignees()->where('employee_id', $employeeId)->delete();

        $this->programTasksChanged(__('tasks.assignee_removed'));
    }

    public function deleteProgramTask(int $taskId): void
    {
        if ($this->programTask($taskId) === null) {
            return;
        }

        if (! app(DeleteTask::class)->handle($taskId)) {
            Flux::toast(variant: 'danger', text: __('tasks.this_task_still_has_subtasks_move_or_delete_them_first'));

            return;
        }

        $this->programTasksChanged(__('tasks.task_deleted'));
    }

    /**
     * Add a task from a group's one-line row, assigned to the person adding
     * it. The group names where it goes, as "program" or "project:ID".
     */
    public function addProgramTask(string $title, string $target): void
    {
        Gate::authorize('create', Task::class);

        $title = trim($title);
        $destination = $this->taskDestination($target);

        if ($title === '' || $destination === null) {
            return;
        }

        $employeeId = auth()->user()?->employee_id;

        app(CreateTask::class)->handle($destination['type'], $destination['id'], [
            'newTaskTitle' => mb_substr($title, 0, 255),
            'newTaskStatus' => TaskStatus::Todo->value,
            'newTaskPriority' => TaskPriority::Medium->value,
            'newTaskDueDate' => '',
            'newTaskAssignedTo' => array_filter([$employeeId]),
        ], (int) auth()->id());

        $this->programTasksChanged(__('tasks.task_created'));
    }

    /**
     * Add a one-line subtask under one of this program's top-level tasks; it
     * shares its parent's source and assignees.
     */
    public function addProgramSubtask(int $parentTaskId, string $title): void
    {
        Gate::authorize('create', Task::class);

        $title = trim($title);
        $parent = $title === '' ? null : $this->programTask($parentTaskId);

        if ($parent === null || $parent->parent_task_id !== null) {
            return;
        }

        $employeeIds = $parent->taskAssignees()->pluck('employee_id');

        DB::transaction(function () use ($parent, $title, $employeeIds): void {
            $subtask = Task::query()->create([
                'parent_task_id' => $parent->id,
                'source_type' => $parent->source_type,
                'source_id' => $parent->source_id,
                'title' => mb_substr($title, 0, 255),
                'status' => TaskStatus::Todo,
                'priority' => TaskPriority::Medium,
                'progress_percentage' => 0,
                'created_by' => (int) auth()->id(),
            ]);

            foreach ($employeeIds as $employeeId) {
                $subtask->taskAssignees()->create(['employee_id' => $employeeId]);
            }
        });

        $this->programTasksChanged(__('tasks.subtask_added'));
    }

    /**
     * A move target inside this program, or null for anything else.
     *
     * @return array{type: string, id: int}|null
     */
    protected function taskDestination(string $target): ?array
    {
        if ($target === 'program') {
            return ['type' => 'program', 'id' => $this->programId];
        }

        [$type, $id] = array_pad(explode(':', $target, 2), 2, '');
        $id = (int) $id;

        $belongs = match ($type) {
            'proposal_location' => ProposalLocation::query()->whereKey($id)->where('program_id', $this->programId)->exists(),
            default => false,
        };

        return $belongs ? ['type' => $type, 'id' => $id] : null;
    }

    /**
     * Forget everything the page memoised about its tasks, then confirm.
     */
    protected function programTasksChanged(string $message): void
    {
        unset($this->tasks, $this->healthFigures, $this->attention, $this->projectLedger, $this->tabs);

        Flux::toast(variant: 'success', text: $message);
    }

    /**
     * Whether a task passes a filter: the tab's current one unless named.
     */
    public function taskPassesFilter(Task $task, ?int $employeeId, ?string $filter = null): bool
    {
        $isDone = $task->status === TaskStatus::Done;
        $today = CarbonImmutable::today();

        return match ($filter ?? $this->taskFilter) {
            'overdue' => ! $isDone && $task->due_date !== null && $task->due_date->lt($today),
            'week' => ! $isDone && $task->due_date !== null && $task->due_date->betweenIncluded($today, $today->addDays(7)),
            'mine' => ! $isDone && $employeeId !== null && $task->taskAssignees->contains('employee_id', $employeeId),
            'done' => $isDone,
            'all' => true,
            default => ! $isDone,
        };
    }
}
