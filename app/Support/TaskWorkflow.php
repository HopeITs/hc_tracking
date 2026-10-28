<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\Tasks\NotifyTaskReview;
use App\Enums\DependencyType;
use App\Enums\TaskStatus;
use App\Models\ScheduleDependency;
use App\Models\Scopes\TaskVisibilityScope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Who may move a task to which phase (user decision 2026-10-27), checked by
 * every status change (ChangeTaskStatus, CompleteTask, SaveTask):
 *
 * - The approver is the task's creator, or someone who may edit it
 *   (TaskPolicy::update) and is not one of its assignees. The approver may
 *   move it to any phase, and sends an Under review task back to In
 *   progress only with a note.
 * - An assignee who is not the approver moves it between To do, In
 *   progress and Under review, never to Done.
 * - Done waits for every subtask and every finish-blocking predecessor task
 *   (finish-to-start, finish-to-finish) to be done, and for an attachment
 *   when the task is flagged evidence_required.
 */
final class TaskWorkflow
{
    /**
     * Whether the user approves this task's work: its creator, or an editor
     * who is not doing the work themselves.
     */
    public static function approves(User $user, Task $task): bool
    {
        if ($task->created_by === $user->id) {
            return true;
        }

        return Gate::forUser($user)->allows('update', $task) && ! self::isAssignee($user, $task);
    }

    /**
     * Why the user may not move the task to $to, or null when they may.
     */
    public static function refusal(User $user, Task $task, TaskStatus $to, ?string $note = null): ?string
    {
        if ($task->status === TaskStatus::Done) {
            return __('tasks.completed_tasks_cannot_change_status');
        }

        if ($to === $task->status) {
            return null;
        }

        if (! Gate::forUser($user)->allows('updateStatus', $task)) {
            return __('tasks.workflow.not_yours');
        }

        $approves = self::approves($user, $task);

        if ($to === TaskStatus::Done) {
            return $approves ? self::doneBlocker($task) : __('tasks.workflow.only_approver_done');
        }

        if ($approves && $task->status === TaskStatus::UnderReview && in_array($to, [TaskStatus::Todo, TaskStatus::InProgress], true) && ! self::isAssignee($user, $task) && trim((string) $note) === '') {
            return __('tasks.workflow.note_required');
        }

        return null;
    }

    /**
     * What keeps the task from being done, or null when nothing does.
     */
    public static function doneBlocker(Task $task): ?string
    {
        $openSubtasks = Task::query()->withoutGlobalScope(TaskVisibilityScope::class)
            ->where('parent_task_id', $task->id)
            ->where('status', '!=', TaskStatus::Done)
            ->count();

        if ($openSubtasks > 0) {
            return trans_choice('tasks.workflow.open_subtasks', $openSubtasks, ['count' => $openSubtasks]);
        }

        $predecessorIds = ScheduleDependency::query()
            ->where('successor_type', 'task')
            ->where('successor_id', $task->id)
            ->where('predecessor_type', 'task')
            ->whereIn('type', [DependencyType::FinishToStart, DependencyType::FinishToFinish])
            ->pluck('predecessor_id');

        $openPredecessor = $predecessorIds->isEmpty() ? null : Task::query()->withoutGlobalScope(TaskVisibilityScope::class)
            ->whereKey($predecessorIds->all())
            ->where('status', '!=', TaskStatus::Done)
            ->value('title');

        if ($openPredecessor !== null) {
            return __('tasks.workflow.open_dependency', ['task' => $openPredecessor]);
        }

        if ($task->evidence_required && ! $task->attachments()->exists()) {
            return __('tasks.workflow.evidence_missing');
        }

        return null;
    }

    /**
     * Refuse a status change made through a form as a validation error on
     * $field. Nothing is thrown when the status does not change.
     *
     * @throws ValidationException
     */
    public static function guard(User $user, Task $task, TaskStatus $to, ?string $note, string $field): void
    {
        if ($to === $task->status) {
            return;
        }

        $refusal = self::refusal($user, $task, $to, $note);

        if ($refusal !== null) {
            throw ValidationException::withMessages([$field => $refusal]);
        }
    }

    /**
     * The notices a saved move sends: Under review from someone who does not
     * approve tells the creator; sent back with a note tells the assignees.
     * $approved is whether the actor approved the task before the move.
     */
    public static function announce(User $user, Task $task, TaskStatus $from, bool $approved, ?string $note): void
    {
        if ($from === $task->status) {
            return;
        }

        $notify = app(NotifyTaskReview::class);

        if ($task->status === TaskStatus::UnderReview && ! $approved) {
            $notify->submitted($user, $task);
        }

        if ($from === TaskStatus::UnderReview && in_array($task->status, [TaskStatus::Todo, TaskStatus::InProgress], true) && $note !== null && $note !== '') {
            $notify->sentBack($user, $task, $note);
        }
    }

    private static function isAssignee(User $user, Task $task): bool
    {
        if ($user->employee_id === null) {
            return false;
        }

        return $task->relationLoaded('taskAssignees')
            ? $task->taskAssignees->contains('employee_id', $user->employee_id)
            : $task->taskAssignees()->where('employee_id', $user->employee_id)->exists();
    }
}
