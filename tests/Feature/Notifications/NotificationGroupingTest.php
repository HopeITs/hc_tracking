<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AppNotification;
use App\Notifications\Inbox;
use App\Notifications\NotificationPayload;
use App\Notifications\UnreadCounts;

/**
 * A payload for task 9, backed by a real activity row (notifications.activity_id
 * is a foreign key).
 */
function taskPayload(NotificationPriority $priority = NotificationPriority::Info, string $key = 'notifications.events.task.updated', string $groupKey = '5:task:9'): NotificationPayload
{
    $activityId = Activity::withoutRecording(fn () => Activity::query()->create([
        'subject_type' => 'task', 'subject_id' => 9, 'module' => 'tasks', 'event' => 'task.updated', 'changes' => [], 'properties' => [],
    ]))->id;

    return new NotificationPayload(
        kind: NotificationKind::Action,
        module: 'tasks',
        priority: $priority,
        key: $key,
        params: ['actor' => 'Sami', 'subject' => 'Plan'],
        subjectType: 'task',
        subjectId: 9,
        actorId: null,
        activityId: $activityId,
        groupKey: $groupKey,
    );
}

test('a notification is stored with its columns and a count of one', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());

    $user->notify(new AppNotification(taskPayload(NotificationPriority::Important)));

    $row = UserNotification::query()->sole();

    expect($row->priority)->toBe(NotificationPriority::Important)
        ->and($row->module)->toBe('tasks')
        ->and($row->kind)->toBe(NotificationKind::Action)
        ->and($row->subject_type)->toBe('task')
        ->and($row->subject_id)->toBe(9)
        ->and($row->group_key)->toBe('5:task:9')
        ->and($row->data['count'])->toBe(1)
        ->and($row->data['activity_ids'])->toBe(Activity::query()->pluck('id')->all());
});

test('a second event on the same record by the same actor within five minutes merges', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());

    $user->notify(new AppNotification(taskPayload()));
    $this->travel(4)->minutes();
    $user->notify(new AppNotification(taskPayload(NotificationPriority::Important, 'notifications.events.task.assigned')));

    $row = UserNotification::query()->sole();

    expect($row->data['count'])->toBe(2)
        ->and($row->data['activity_ids'])->toBe(Activity::query()->orderBy('id')->pluck('id')->all())
        ->and($row->data['key'])->toBe('notifications.events.task.assigned')
        ->and($row->priority)->toBe(NotificationPriority::Important);
});

test('a louder earlier event keeps its sentence when a quieter one merges in', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());

    $user->notify(new AppNotification(taskPayload(NotificationPriority::Important, 'notifications.events.task.assigned')));
    $user->notify(new AppNotification(taskPayload()));

    $row = UserNotification::query()->sole();

    expect($row->data['key'])->toBe('notifications.events.task.assigned')
        ->and($row->priority)->toBe(NotificationPriority::Important);
});

test('events more than five minutes apart are separate notifications', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());

    $user->notify(new AppNotification(taskPayload()));
    $this->travel(6)->minutes();
    $user->notify(new AppNotification(taskPayload()));

    expect(UserNotification::query()->count())->toBe(2);
});

test('an event never merges into a notification that was already read', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());

    $user->notify(new AppNotification(taskPayload()));
    UserNotification::query()->sole()->markAsRead();
    $user->notify(new AppNotification(taskPayload()));

    expect(UserNotification::query()->count())->toBe(2);
});

test('another actor on the same record starts its own notification', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());

    $user->notify(new AppNotification(taskPayload(groupKey: '5:task:9')));
    $user->notify(new AppNotification(taskPayload(groupKey: '6:task:9')));

    expect(UserNotification::query()->count())->toBe(2);
});

test('grouping is per recipient', function () {
    [$first, $second] = Activity::withoutRecording(fn () => User::factory()->count(2)->create());

    $first->notify(new AppNotification(taskPayload()));
    $second->notify(new AppNotification(taskPayload()));

    expect(UserNotification::query()->count())->toBe(2);
});

test('an event never merges into a snoozed notification, so it cannot vanish until the snooze ends', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    $user->notify(new AppNotification(taskPayload()));
    app(Inbox::class)->snooze($user, UserNotification::query()->value('id'), 'tomorrow');

    $user->notify(new AppNotification(taskPayload()));

    expect(UserNotification::query()->count())->toBe(2)
        ->and(app(UnreadCounts::class)->for($user)['total'])->toBe(1);
});
