<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AppNotification;
use App\Notifications\NotificationPayload;
use App\Support\Desk\Panels;
use Livewire\Livewire;

function bellNotify(User $user, NotificationPriority $priority = NotificationPriority::Info, NotificationKind $kind = NotificationKind::Action, string $subject = 'Plan'): string
{
    $before = UserNotification::query()->pluck('id')->all();

    $user->notify(new AppNotification(new NotificationPayload(
        kind: $kind, module: 'tasks', priority: $priority,
        key: 'notifications.events.task.created', params: ['actor' => 'Sami', 'subject' => $subject],
        subjectType: 'task', subjectId: random_int(1, 99999),
    )));

    return UserNotification::query()->whereNotIn('id', $before)->sole()->id;
}

test('the bell shows the unread count and the sentences', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    bellNotify($user, subject: 'Water trucking');
    bellNotify($user, subject: 'Hygiene kits');

    Livewire::actingAs($user)->test('notifications.bell')
        ->call('loadPanel')
        ->assertSeeHtml('data-unread-count')
        ->assertSeeText('Sami created the task “Water trucking”')
        ->assertSeeText('Hygiene kits');
});

test('the panel reads no rows until it is opened', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    bellNotify($user, subject: 'Water trucking');

    Livewire::actingAs($user)->test('notifications.bell')
        ->assertSeeHtml('data-unread-count')
        ->assertSeeHtml('data-panel-loading')
        ->assertDontSeeText('Water trucking')
        ->call('loadPanel')
        ->assertDontSeeHtml('data-panel-loading')
        ->assertSeeText('Water trucking');
});

test('an empty inbox shows no counter', function () {
    Livewire::actingAs(Activity::withoutRecording(fn () => User::factory()->create()))->test('notifications.bell')
        ->call('loadPanel')
        ->assertDontSeeHtml('data-unread-count')
        ->assertSeeText(__('notifications.empty'));
});

test('an unread critical notification is pinned first and turns the counter red', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    bellNotify($user, NotificationPriority::Critical, subject: 'Urgent');
    $this->travel(1)->minutes();
    bellNotify($user, subject: 'Latest of all');

    Livewire::actingAs($user)->test('notifications.bell')
        ->call('loadPanel')
        ->assertSeeInOrder(['Urgent', 'Latest of all'])
        ->assertSeeHtml('data-critical-unread');
});

test('the unread and alerts tabs narrow the list', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    UserNotification::query()->find(bellNotify($user, subject: 'Already read'))->markAsRead();
    bellNotify($user, kind: NotificationKind::Alert, subject: 'Due tomorrow');
    bellNotify($user, subject: 'Fresh action');

    Livewire::actingAs($user)->test('notifications.bell')
        ->call('loadPanel')
        ->call('showTab', 'unread')
        ->assertDontSeeText('Already read')
        ->assertSeeText('Fresh action')
        ->call('showTab', 'alerts')
        ->assertSeeText('Due tomorrow')
        ->assertDontSeeText('Fresh action');
});

test('marking read, unread, all read and snoozing from the bell', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    $first = bellNotify($user);
    $second = bellNotify($user);

    Livewire::actingAs($user)->test('notifications.bell')
        ->call('markRead', $first)
        ->assertDispatched('notification-received')
        ->call('markUnread', $first)
        ->call('snooze', $second, 'tomorrow')
        ->call('markAllRead');

    expect(UserNotification::query()->find($first)->read_at)->not->toBeNull()
        ->and(UserNotification::query()->find($second)->snoozed_until)->not->toBeNull();
});

test('an unknown snooze preset from the browser changes nothing', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    $id = bellNotify($user);

    Livewire::actingAs($user)->test('notifications.bell')->call('snooze', $id, 'forever');

    expect(UserNotification::query()->find($id)->snoozed_until)->toBeNull();
});

test('a refresh tells the page the new counts', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    bellNotify($user);

    Livewire::actingAs($user)->test('notifications.bell')
        ->dispatch('notification-received')
        ->assertDispatched('notification-counts', counts: ['total' => 1, 'critical' => 0, 'modules' => ['tasks' => 1]]);
});

test('every authenticated page tells the script who is listening, and the desk has no notifications panel', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeHtml('data-user-id="'.$user->id.'"');

    expect(app(Panels::class)->availableTo($user))->not->toHaveKey('inbox');
});

test('the bell renders in arabic for arabic readers', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    bellNotify($user, subject: 'خطة');

    app()->setLocale('ar');

    Livewire::actingAs($user)->test('notifications.bell')->call('loadPanel')->assertSeeText('أنشأ Sami المهمة «خطة»');
});

test('a module chip narrows the panel and a second click clears it', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    bellNotify($user, subject: 'Task side');
    $user->notify(new AppNotification(new NotificationPayload(
        kind: NotificationKind::Action, module: 'risks', priority: NotificationPriority::Info,
        key: 'notifications.events.task.created', params: ['actor' => 'Sami', 'subject' => 'Risk side'],
        subjectType: 'risk', subjectId: 1,
    )));

    Livewire::actingAs($user)->test('notifications.bell')
        ->call('loadPanel')
        ->assertSeeHtml('data-bell-module="risks"')
        ->call('showModule', 'risks')
        ->assertSeeText('Risk side')
        ->assertDontSeeText('Task side')
        ->call('showModule', 'risks')
        ->assertSeeText('Task side');
});
