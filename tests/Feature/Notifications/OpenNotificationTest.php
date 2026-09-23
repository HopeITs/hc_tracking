<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AppNotification;
use App\Notifications\NotificationPayload;

function notifyAboutTask(User $user, int $taskId): string
{
    $before = UserNotification::query()->pluck('id')->all();

    $user->notify(new AppNotification(new NotificationPayload(
        kind: NotificationKind::Action, module: 'tasks', priority: NotificationPriority::Info,
        key: 'notifications.events.task.created', params: ['actor' => 'Sami', 'subject' => 'Plan'],
        subjectType: 'task', subjectId: $taskId,
    )));

    return UserNotification::query()->whereNotIn('id', $before)->sole()->id;
}

test('opening a notification marks it read and goes to its record', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    $task = Activity::withoutRecording(fn () => Task::factory()->create());
    $id = notifyAboutTask($user, $task->id);

    $this->actingAs($user)->get(route('notifications.open', $id))->assertRedirect(route('tasks.show', $task));

    expect(UserNotification::query()->find($id)->read_at)->not->toBeNull();
});

test('a notification about a deleted record opens the inbox saying it no longer exists', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    $id = notifyAboutTask($user, 999999);

    $this->actingAs($user)->get(route('notifications.open', $id))
        ->assertRedirect(route('notifications.index'))
        ->assertSessionHas('notification-missing', true);

    $this->actingAs($user)->withSession(['notification-missing' => true])
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertSeeText(__('notifications.item_no_longer_exists'));
});

test('someone else cannot open my notification', function () {
    [$owner, $intruder] = Activity::withoutRecording(fn () => User::factory()->count(2)->create());
    $id = notifyAboutTask($owner, 1);

    $this->actingAs($intruder)->get(route('notifications.open', $id))->assertNotFound();
});
