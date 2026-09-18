<?php

use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskUpdate;
use App\Models\User;
use Carbon\CarbonImmutable;

test('a task belongs to a milestone and a proposal', function () {
    $milestone = Milestone::factory()->create();
    $proposal = Proposal::factory()->create();

    $task = Task::factory()->create([
        'milestone_id' => $milestone->id,
        'proposal_id' => $proposal->id,
    ]);

    expect($task->milestone->id)->toBe($milestone->id)
        ->and($task->proposal->id)->toBe($proposal->id);
});

test('a task resolves its hr employee assignee and its user creator separately', function () {
    $assigneeId = insertHrEmployee();
    $creator = User::factory()->create();

    $task = Task::factory()->create([
        'assigned_to' => $assigneeId,
        'created_by' => $creator->id,
    ]);

    expect($task->assignee)->toBeInstanceOf(HrEmployee::class)
        ->and($task->assignee->id)->toBe($assigneeId)
        ->and($task->creator)->toBeInstanceOf(User::class)
        ->and($task->creator->id)->toBe($creator->id);
});

test('a task with no milestone has a null milestone relation', function () {
    $task = Task::factory()->create(['milestone_id' => null]);

    expect($task->milestone)->toBeNull();
});

test('a task has its updates and attachments', function () {
    $task = Task::factory()->create();

    TaskUpdate::factory(2)->create(['task_id' => $task->id]);
    TaskAttachment::factory()->create(['task_id' => $task->id]);

    expect($task->updates)->toHaveCount(2)
        ->and($task->attachments)->toHaveCount(1);
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

test('a subtask inherits its parent proposal and milestone through the factory helper', function () {
    $milestone = Milestone::factory()->create();
    $proposal = Proposal::factory()->create();
    $parent = Task::factory()->create(['milestone_id' => $milestone->id, 'proposal_id' => $proposal->id]);

    $subtask = Task::factory()->subtaskOf($parent)->create();

    expect($subtask->parent_task_id)->toBe($parent->id)
        ->and($subtask->milestone_id)->toBe($milestone->id)
        ->and($subtask->proposal_id)->toBe($proposal->id);
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
