<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Events\NotificationPushed;
use App\Models\Activity;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AppNotification;
use App\Notifications\Inbox;
use App\Notifications\NotificationPayload;
use App\Notifications\UnreadCounts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

function inboxPayload(string $module = 'tasks', NotificationPriority $priority = NotificationPriority::Info): NotificationPayload
{
    return new NotificationPayload(
        kind: NotificationKind::Action, module: $module, priority: $priority,
        key: 'notifications.events.task.created', params: ['actor' => 'Sami', 'subject' => 'Plan'],
        subjectType: 'task', subjectId: 1,
    );
}

test('a stored notification is pushed to its owner in their language', function () {
    Event::fake([NotificationPushed::class]);
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    $user->forceFill(['locale' => 'ar'])->save();

    $user->notify(new AppNotification(inboxPayload(priority: NotificationPriority::Critical)));

    Event::assertDispatched(NotificationPushed::class, fn (NotificationPushed $event): bool => $event->userId === $user->id
        && $event->payload['priority'] === 'critical'
        && $event->payload['id'] === UserNotification::query()->value('id')
        && str_contains($event->payload['text'], 'أنشأ')
        && $event->payload['module_label'] === 'المهام'
        && $event->broadcastAs() === 'notification.pushed'
        && $event->broadcastOn()[0]->name === 'private-App.Models.User.'.$user->id);
});

test('a broadcast failure never loses the notification', function () {
    Event::listen(NotificationPushed::class, fn () => throw new RuntimeException('socket down'));
    $user = Activity::withoutRecording(fn () => User::factory()->create());

    $user->notify(new AppNotification(inboxPayload()));

    expect(UserNotification::query()->count())->toBe(1);
});

test('unread counts split by module and flag critical, and refresh on arrival and on read', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    $counts = app(UnreadCounts::class);

    expect($counts->for($user))->toBe(['total' => 0, 'critical' => 0, 'modules' => []]);

    $user->notify(new AppNotification(inboxPayload('tasks')));
    $user->notify(new AppNotification(inboxPayload('risks', NotificationPriority::Critical)));

    expect($counts->for($user))->toBe(['total' => 2, 'critical' => 1, 'modules' => ['risks' => 1, 'tasks' => 1]]);

    app(Inbox::class)->markRead($user, UserNotification::query()->where('module', 'risks')->value('id'));

    expect($counts->for($user))->toBe(['total' => 1, 'critical' => 0, 'modules' => ['tasks' => 1]]);
});

test('inbox operations only touch the acting user rows', function () {
    [$owner, $intruder] = Activity::withoutRecording(fn () => User::factory()->count(2)->create());
    $owner->notify(new AppNotification(inboxPayload()));
    $id = UserNotification::query()->value('id');

    app(Inbox::class)->markRead($intruder, $id);
    app(Inbox::class)->snooze($intruder, $id, '1h');

    $row = UserNotification::query()->sole();
    expect($row->read_at)->toBeNull()->and($row->snoozed_until)->toBeNull();
});

test('mark unread, mark many and mark all read', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    foreach (range(1, 3) as $n) {
        $user->notify(new AppNotification(inboxPayload()));
    }
    $ids = UserNotification::query()->pluck('id')->all();
    $inbox = app(Inbox::class);

    expect($inbox->markManyRead($user, [$ids[0], $ids[1]]))->toBe(2);
    $inbox->markUnread($user, $ids[0]);
    expect($inbox->markAllRead($user))->toBe(2)
        ->and(UserNotification::query()->whereNull('read_at')->count())->toBe(0);
});

test('snooze presets land on the promised moments', function () {
    $now = CarbonImmutable::parse('2026-09-23 10:15'); // a Wednesday

    expect(Inbox::snoozeUntil('1h', $now)->format('Y-m-d H:i'))->toBe('2026-09-23 11:15')
        ->and(Inbox::snoozeUntil('tomorrow', $now)->format('Y-m-d H:i'))->toBe('2026-09-24 08:00')
        ->and(Inbox::snoozeUntil('next_week', $now)->format('Y-m-d H:i'))->toBe('2026-09-28 08:00');
});

test('an unknown snooze preset is refused', function () {
    Inbox::snoozeUntil('forever', CarbonImmutable::now());
})->throws(InvalidArgumentException::class);

test('a snoozed notification hides, then returns unread on top and is pushed again', function () {
    Event::fake([NotificationPushed::class]);
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    $user->notify(new AppNotification(inboxPayload()));
    $id = UserNotification::query()->value('id');

    app(Inbox::class)->snooze($user, $id, '1h');
    expect(app(UnreadCounts::class)->for($user)['total'])->toBe(0);

    $this->travel(61)->minutes();
    $this->artisan('notifications:wake-snoozed')->assertSuccessful();

    $row = UserNotification::query()->sole();
    expect($row->snoozed_until)->toBeNull()
        ->and($row->read_at)->toBeNull()
        ->and($row->created_at->greaterThan(now()->subMinute()))->toBeTrue()
        ->and(app(UnreadCounts::class)->for($user)['total'])->toBe(1);
    Event::assertDispatchedTimes(NotificationPushed::class, 2);
});
