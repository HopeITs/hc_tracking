<?php

use App\Enums\NotificationPriority;
use App\Models\NotificationMute;
use App\Models\UserNotification;
use App\Notifications\Alerts\AlertDispatcher;
use App\Notifications\Alerts\AlertLedger;
use App\Notifications\Alerts\DeadlineItem;
use App\Notifications\NotificationPayload;
use Carbon\CarbonImmutable;

function alertPayload(): NotificationPayload
{
    return app(AlertDispatcher::class)->payload('tasks', NotificationPriority::Info, 'notifications.alerts.due_soon.today', ['subject' => 'Plan'], 'task', 7);
}

test('a stage fires once per user and can be re-armed', function () {
    $user = userWithRole('Managers');
    $dispatcher = app(AlertDispatcher::class);

    expect($dispatcher->send('due_soon', 'd0:2026-09-30', alertPayload(), [$user->id]))->toBe(1)
        ->and($dispatcher->send('due_soon', 'd0:2026-09-30', alertPayload(), [$user->id]))->toBe(0);

    app(AlertLedger::class)->release('due_soon', 'task', 7);

    expect($dispatcher->send('due_soon', 'd0:2026-09-30', alertPayload(), [$user->id]))->toBe(1)
        ->and(UserNotification::query()->forUser($user)->count())->toBe(2);
});

test('alerts respect the view permission and mutes, except for direct people', function () {
    $direct = userWithRole('Managers');
    $mutedWatcher = userWithRole('Managers');
    $blind = userWithRole('Financial');
    NotificationMute::query()->create(['user_id' => $direct->id, 'module' => 'tasks']);
    NotificationMute::query()->create(['user_id' => $mutedWatcher->id, 'module' => 'tasks']);

    app(AlertDispatcher::class)->send('due_soon', 'd0:x', alertPayload(), [$direct->id], [$mutedWatcher->id, $blind->id]);

    expect(UserNotification::query()->pluck('notifiable_id')->all())->toBe([$direct->id]);
});

test('an item with nobody named goes to its watcher roles', function () {
    $financial = userWithRole('Financial');
    $item = new DeadlineItem('transfer', 3, 'transfers', 'Tranche', CarbonImmutable::today(), [], ['Financial']);
    $payload = app(AlertDispatcher::class)->payload('transfers', NotificationPriority::Info, 'notifications.alerts.due_soon.today', ['subject' => 'Tranche'], 'transfer', 3);

    expect(app(AlertDispatcher::class)->sendForItem('due_soon', 'd0', $payload, $item))->toBe(1)
        ->and(UserNotification::query()->forUser($financial)->sole()->kind->value)->toBe('alert');
});
