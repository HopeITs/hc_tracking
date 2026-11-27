<?php

use App\Enums\TaskStatus;
use App\Models\HrEmployee;
use App\Models\ProposalLocation;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use Carbon\CarbonImmutable;

test('a task resolves its polymorphic source', function () {
    $source = placeRow();

    $task = Task::factory()->create([
        'source_type' => 'proposal_location',
        'source_id' => $source->id,
    ]);

    expect($task->source)->toBeInstanceOf(ProposalLocation::class)
        ->and($task->source->id)->toBe($source->id)
        ->and($task->sourceLabel())->toBe($source->fresh()->load(['city', 'program', 'centers'])->label());
});

test('a task sourceLabel falls back to no source when the source no longer exists', function () {
    $source = placeRow();
    $task = Task::factory()->create([
        'source_type' => 'proposal_location',
        'source_id' => $source->id,
    ]);
    $source->delete();

    expect($task->fresh()->sourceLabel())->toBe(__('tasks.no_source'));
});

test('a task raised against a location row is labelled with the place of that row', function () {
    $location = ProposalLocation::factory()->cityWide()->create();
    $task = Task::factory()->create([
        'source_type' => 'proposal_location',
        'source_id' => $location->id,
    ]);

    $loadedTask = Task::query()->with('source')->get()->firstWhere('id', $task->id);

    expect($loadedTask->sourceLabel())
        ->toBe($location->fresh()->load(['city', 'program', 'centers'])->label())
        ->not->toBe(__('tasks.no_source'));
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
    $source = placeRow();
    $parent = Task::factory()->create(['source_type' => 'proposal_location', 'source_id' => $source->id]);

    $subtask = Task::factory()->subtaskOf($parent)->create();

    expect($subtask->parent_task_id)->toBe($parent->id)
        ->and($subtask->source_type)->toBe('proposal_location')
        ->and($subtask->source_id)->toBe($source->id);
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

test('a todo task past its due date is missed, not overdue', function () {
    $task = Task::factory()->create(['status' => TaskStatus::Todo, 'due_date' => now()->subDay()->toDateString()]);

    expect($task->isMissed())->toBeTrue()
        ->and($task->isOverdue())->toBeFalse();
});

test('an in progress or under review task past its due date is overdue, not missed', function () {
    $inProgress = Task::factory()->create(['status' => TaskStatus::InProgress, 'due_date' => now()->subDay()->toDateString()]);
    $underReview = Task::factory()->create(['status' => TaskStatus::UnderReview, 'due_date' => now()->subDay()->toDateString()]);

    expect($inProgress->isOverdue())->toBeTrue()
        ->and($inProgress->isMissed())->toBeFalse()
        ->and($underReview->isOverdue())->toBeTrue()
        ->and($underReview->isMissed())->toBeFalse();
});

test('a done task is never overdue or missed regardless of its due date', function () {
    $task = Task::factory()->create(['status' => TaskStatus::Done, 'due_date' => now()->subDay()->toDateString()]);

    expect($task->isOverdue())->toBeFalse()
        ->and($task->isMissed())->toBeFalse();
});

test('a task with no due date or a future due date is neither overdue nor missed', function () {
    $noDueDate = Task::factory()->create(['status' => TaskStatus::Todo, 'due_date' => null]);
    $futureDueDate = Task::factory()->create(['status' => TaskStatus::InProgress, 'due_date' => now()->addDay()->toDateString()]);

    expect($noDueDate->isOverdue())->toBeFalse()
        ->and($noDueDate->isMissed())->toBeFalse()
        ->and($futureDueDate->isOverdue())->toBeFalse()
        ->and($futureDueDate->isMissed())->toBeFalse();
});
