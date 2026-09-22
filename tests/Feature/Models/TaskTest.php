<?php

use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use Carbon\CarbonImmutable;

test('a task resolves its polymorphic source', function () {
    $milestone = Milestone::factory()->create();

    $task = Task::factory()->create([
        'source_type' => 'milestone',
        'source_id' => $milestone->id,
    ]);

    expect($task->source)->toBeInstanceOf(Milestone::class)
        ->and($task->source->id)->toBe($milestone->id)
        ->and($task->sourceLabel())->toBe($milestone->name);
});

test('a task sourceLabel falls back to no source when the source no longer exists', function () {
    $milestone = Milestone::factory()->create();
    $task = Task::factory()->create([
        'source_type' => 'milestone',
        'source_id' => $milestone->id,
    ]);
    $milestone->delete();

    expect($task->fresh()->sourceLabel())->toBe(__('tasks.no_source'));
});

test('sourceProjectId resolves the project only when the source is a milestone', function () {
    $milestone = Milestone::factory()->create();
    $milestoneTask = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id]);
    $proposalTask = Task::factory()->create(['source_type' => 'proposal', 'source_id' => Proposal::factory()->create()->id]);

    expect($milestoneTask->sourceProjectId())->toBe($milestone->project_id)
        ->and($proposalTask->sourceProjectId())->toBeNull();
});

test('a task resolves its hr employee assignees and its user creator separately', function () {
    $assigneeId = insertHrEmployee();
    $secondAssigneeId = insertHrEmployee();
    $creator = User::factory()->create();

    $task = Task::factory()->create(['created_by' => $creator->id]);
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $assigneeId]);
    $task->taskAssignees()->create(['employee_id' => $secondAssigneeId]);

    $task->load('taskAssignees.employee');

    expect($task->taskAssignees)->toHaveCount(2)
        ->and($task->taskAssignees->first()->employee)->toBeInstanceOf(HrEmployee::class)
        ->and($task->taskAssignees->pluck('employee_id')->sort()->values()->all())->toBe(collect([$assigneeId, $secondAssigneeId])->sort()->values()->all())
        ->and($task->creator)->toBeInstanceOf(User::class)
        ->and($task->creator->id)->toBe($creator->id);
});

test('a task has its attachments', function () {
    $task = Task::factory()->create();

    TaskAttachment::factory()->create(['task_id' => $task->id]);

    expect($task->attachments)->toHaveCount(1);
});

test('a task can have subtasks and each resolves its parent', function () {
    $parent = Task::factory()->create();
    $subtaskOne = Task::factory()->subtaskOf($parent)->create();
    $subtaskTwo = Task::factory()->subtaskOf($parent)->create();
    $unrelated = Task::factory()->create();

    expect($parent->subtasks)->toHaveCount(2)
        ->and($parent->subtasks->pluck('id')->sort()->values()->all())->toBe(collect([$subtaskOne->id, $subtaskTwo->id])->sort()->values()->all())
        ->and($subtaskOne->parentTask->id)->toBe($parent->id)
        ->and($parent->parentTask)->toBeNull()
        ->and($unrelated->parentTask)->toBeNull();
});

test('a subtask inherits its parent source through the factory helper', function () {
    $milestone = Milestone::factory()->create();
    $parent = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id]);

    $subtask = Task::factory()->subtaskOf($parent)->create();

    expect($subtask->parent_task_id)->toBe($parent->id)
        ->and($subtask->source_type)->toBe('milestone')
        ->and($subtask->source_id)->toBe($milestone->id);
});

test('a task assignee resolves its task and its hr employee', function () {
    $employeeId = insertHrEmployee();
    $task = Task::factory()->create();
    $assignment = $task->taskAssignees()->create(['employee_id' => $employeeId]);

    expect($assignment->task->id)->toBe($task->id)
        ->and($assignment->employee)->toBeInstanceOf(HrEmployee::class)
        ->and($assignment->employee->id)->toBe($employeeId);
});

test('a task casts its dates and progress', function () {
    $task = Task::factory()->create([
        'start_date' => '2026-01-05',
        'due_date' => '2026-02-05',
        'completed_date' => null,
        'progress_percentage' => 45,
    ])->fresh();

    expect($task->start_date)->toBeInstanceOf(CarbonImmutable::class)
        ->and($task->start_date->toDateString())->toBe('2026-01-05')
        ->and($task->due_date->toDateString())->toBe('2026-02-05')
        ->and($task->completed_date)->toBeNull()
        ->and($task->progress_percentage)->toBe(45);
});
