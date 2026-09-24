<?php

use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\NotificationMute;
use App\Models\Program;
use App\Models\Task;
use App\Models\User;
use App\Notifications\RecipientResolver;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * A task activity written by hand, so the resolver is tested without the
 * recorder.
 *
 * @param  array<string, mixed>  $properties
 */
function taskActivity(Task $task, string $event, ?int $actorId, array $properties = [], ?int $programId = null): Activity
{
    return Activity::withoutRecording(fn () => Activity::query()->create([
        'actor_id' => $actorId,
        'subject_type' => 'task',
        'subject_id' => $task->id,
        'program_id' => $programId,
        'module' => 'tasks',
        'event' => $event,
        'changes' => [],
        'properties' => ['label' => $task->title, 'notify' => true, ...$properties],
    ]));
}

/**
 * A task with exactly the given HR employees assigned, created without
 * recording.
 *
 * @param  list<int>  $employeeIds
 */
function taskAssignedTo(array $employeeIds, ?int $createdBy = null): Task
{
    return Activity::withoutRecording(function () use ($employeeIds, $createdBy): Task {
        $task = Task::factory()->create($createdBy === null ? [] : ['created_by' => $createdBy]);
        $task->taskAssignees()->delete();

        foreach ($employeeIds as $employeeId) {
            $task->taskAssignees()->create(['employee_id' => $employeeId]);
        }

        return $task;
    });
}

test('assignees with an account and the creator are direct recipients; the actor is removed', function () {
    $employee = insertHrEmployee();
    $assignee = Activity::withoutRecording(fn () => User::factory()->role('Managers')->create(['employee_id' => $employee]));
    $creator = Activity::withoutRecording(fn () => User::factory()->role('Managers')->create());
    $task = taskAssignedTo([$employee], $creator->id);

    $resolved = app(RecipientResolver::class)->resolve(taskActivity($task, 'task.status_changed', $creator->id));

    expect(array_keys($resolved->priorities))->toBe([$assignee->id]);
});

test('an assigned employee without an account is reported as not notified', function () {
    $withoutAccount = insertHrEmployee(firstNameEn: 'Nour');
    $actor = Activity::withoutRecording(fn () => User::factory()->role('Managers')->create());
    $task = taskAssignedTo([$withoutAccount], $actor->id);

    $resolved = app(RecipientResolver::class)->resolve(taskActivity($task, 'task.assigned', $actor->id, ['employee_id' => $withoutAccount]));

    expect($resolved->priorities)->toBe([])
        ->and($resolved->unnotifiedEmployeeIds)->toBe([$withoutAccount]);
});

test('being assigned is important while the same event is info for everyone else', function () {
    $employee = insertHrEmployee();
    $assignee = Activity::withoutRecording(fn () => User::factory()->role('Managers')->create(['employee_id' => $employee]));
    $creator = Activity::withoutRecording(fn () => User::factory()->role('Managers')->create());
    $actor = Activity::withoutRecording(fn () => User::factory()->role('Managers')->create());
    $task = taskAssignedTo([$employee], $creator->id);

    $resolved = app(RecipientResolver::class)->resolve(taskActivity($task, 'task.assigned', $actor->id, ['employee_id' => $employee]));

    expect($resolved->priorities[$assignee->id])->toBe(NotificationPriority::Important)
        ->and($resolved->priorities[$creator->id])->toBe(NotificationPriority::Info)
        ->and($resolved->assignedUserIds)->toBe([$assignee->id]);
});

test('the manager of the task program is a cascade recipient', function () {
    $managerEmployee = insertHrEmployee();
    $manager = Activity::withoutRecording(fn () => User::factory()->role('Managers')->create(['employee_id' => $managerEmployee]));
    $program = Activity::withoutRecording(fn () => Program::factory()->create(['manager_id' => $managerEmployee]));
    $actor = Activity::withoutRecording(fn () => User::factory()->role('Managers')->create());
    $task = taskAssignedTo([], $actor->id);

    $resolved = app(RecipientResolver::class)->resolve(taskActivity($task, 'task.created', $actor->id, programId: $program->id));

    expect(array_keys($resolved->priorities))->toBe([$manager->id]);
});

test('users without tasks.view are never notified about tasks', function () {
    $employee = insertHrEmployee();
    Activity::withoutRecording(fn () => User::factory()->create(['employee_id' => $employee]));
    $actor = Activity::withoutRecording(fn () => User::factory()->role('Managers')->create());
    $task = taskAssignedTo([$employee], $actor->id);

    $resolved = app(RecipientResolver::class)->resolve(taskActivity($task, 'task.status_changed', $actor->id));

    expect($resolved->priorities)->toBe([]);
});

test('a mute silences cascade notifications but never direct ones', function () {
    $managerEmployee = insertHrEmployee();
    $manager = Activity::withoutRecording(fn () => User::factory()->role('Managers')->create(['employee_id' => $managerEmployee]));
    $assigneeEmployee = insertHrEmployee();
    $assignee = Activity::withoutRecording(fn () => User::factory()->role('Managers')->create(['employee_id' => $assigneeEmployee]));
    $program = Activity::withoutRecording(fn () => Program::factory()->create(['manager_id' => $managerEmployee]));
    $actor = Activity::withoutRecording(fn () => User::factory()->role('Managers')->create());
    $task = taskAssignedTo([$assigneeEmployee], $actor->id);
    NotificationMute::query()->create(['user_id' => $manager->id, 'module' => 'tasks']);
    NotificationMute::query()->create(['user_id' => $assignee->id, 'module' => 'tasks']);

    $resolved = app(RecipientResolver::class)->resolve(taskActivity($task, 'task.status_changed', $actor->id, programId: $program->id));

    expect(array_keys($resolved->priorities))->toBe([$assignee->id]);
});

test('a deleted task still reaches the people it named', function () {
    $employee = insertHrEmployee();
    $assignee = Activity::withoutRecording(fn () => User::factory()->role('Managers')->create(['employee_id' => $employee]));
    $actor = Activity::withoutRecording(fn () => User::factory()->role('Managers')->create());
    $task = taskAssignedTo([], $actor->id);

    $resolved = app(RecipientResolver::class)->resolve(taskActivity($task, 'task.deleted', $actor->id, ['employee_ids' => [$employee], 'creator_id' => $actor->id]));

    expect(array_keys($resolved->priorities))->toBe([$assignee->id]);
});

test('a module nobody mapped has no recipients', function () {
    $actor = Activity::withoutRecording(fn () => User::factory()->create());
    $activity = Activity::withoutRecording(fn () => Activity::query()->create([
        'actor_id' => $actor->id, 'subject_type' => 'task', 'subject_id' => 1, 'module' => 'nowhere',
        'event' => 'x.y', 'changes' => [], 'properties' => [],
    ]));

    expect(app(RecipientResolver::class)->resolve($activity)->priorities)->toBe([]);
});
