<?php

use App\Enums\NotificationPriority;
use App\Jobs\FanOutActivity;
use App\Models\Activity;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AppNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

/**
 * A task with one assignee, created without recording.
 */
function fanOutTask(int $employeeId, ?int $createdBy = null): Task
{
    return Activity::withoutRecording(function () use ($employeeId, $createdBy): Task {
        $task = Task::factory()->create(['title' => 'Plan', ...($createdBy === null ? [] : ['created_by' => $createdBy])]);
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $employeeId]);

        return $task;
    });
}

test('fan-out notifies each recipient once with the assigned wording for the assignee', function () {
    $employee = insertHrEmployee();
    $assignee = Activity::withoutRecording(fn () => User::factory()->role('Managers')->create(['employee_id' => $employee]));
    $actor = Activity::withoutRecording(fn () => User::factory()->role('Managers')->create(['name' => 'Sami']));
    $task = fanOutTask($employee, $actor->id);
    $activity = Activity::withoutRecording(fn () => Activity::query()->create([
        'actor_id' => $actor->id, 'subject_type' => 'task', 'subject_id' => $task->id, 'module' => 'tasks',
        'event' => 'task.assigned', 'changes' => [],
        'properties' => ['label' => 'Plan', 'notify' => true, 'employee_id' => $employee, 'employee' => ['en' => 'Sami Haddad', 'ar' => 'سامي حداد']],
    ]));

    dispatch_sync(new FanOutActivity($activity->id));
    dispatch_sync(new FanOutActivity($activity->id)); // a retry must not duplicate

    $row = UserNotification::query()->forUser($assignee)->sole();

    expect($row->data['key'])->toBe('notifications.events.task.assigned_you')
        ->and($row->priority)->toBe(NotificationPriority::Important)
        ->and($row->activity_id)->toBe($activity->id)
        ->and($row->actor_id)->toBe($actor->id)
        ->and($row->group_key)->toBe($actor->id.':task:'.$task->id)
        ->and(UserNotification::query()->forUser($actor)->count())->toBe(0);
});

test('fan-out skips activities that are not meaningful', function () {
    Notification::fake();
    $activity = Activity::withoutRecording(fn () => Activity::query()->create([
        'actor_id' => null, 'subject_type' => 'task', 'subject_id' => 1, 'module' => 'tasks',
        'event' => 'task.updated', 'changes' => ['description' => ['a', 'b']], 'properties' => ['label' => 'x', 'notify' => false],
    ]));

    dispatch_sync(new FanOutActivity($activity->id));

    Notification::assertNothingSent();
});

test('fan-out sends the app notification through the notification system', function () {
    Notification::fake();
    $employee = insertHrEmployee();
    $assignee = Activity::withoutRecording(fn () => User::factory()->role('Managers')->create(['employee_id' => $employee]));
    $task = fanOutTask($employee);
    $activity = Activity::withoutRecording(fn () => Activity::query()->create([
        'actor_id' => null, 'subject_type' => 'task', 'subject_id' => $task->id, 'module' => 'tasks',
        'event' => 'task.created', 'changes' => [], 'properties' => ['label' => 'x', 'notify' => true],
    ]));

    dispatch_sync(new FanOutActivity($activity->id));

    Notification::assertSentTo($assignee, AppNotification::class, fn (AppNotification $notification): bool => $notification->payload->key === 'notifications.events.task.created'
        && $notification->payload->groupKey === 'system:task:'.$task->id);
});

test('fan-out of an activity that no longer exists does nothing', function () {
    Notification::fake();

    dispatch_sync(new FanOutActivity(999999));

    Notification::assertNothingSent();
});
