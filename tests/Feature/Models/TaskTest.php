<?php

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

test('a task resolves its assignee and its creator separately', function () {
    $assignee = User::factory()->create();
    $creator = User::factory()->create();

    $task = Task::factory()->create([
        'assigned_to' => $assignee->id,
        'created_by' => $creator->id,
    ]);

    expect($task->assignee)->toBeInstanceOf(User::class)
        ->and($task->assignee->id)->toBe($assignee->id)
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
