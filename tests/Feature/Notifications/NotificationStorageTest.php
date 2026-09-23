<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Str;

/**
 * A raw inbox row for a user, bypassing the notification pipeline.
 *
 * @param  array<string, mixed>  $attributes
 */
function rawNotification(User $user, array $attributes = []): UserNotification
{
    return UserNotification::query()->create([
        'id' => (string) Str::uuid(),
        'type' => 'test',
        'notifiable_type' => 'user',
        'notifiable_id' => $user->id,
        'data' => [],
        'module' => 'tasks',
        ...$attributes,
    ]);
}

test('a notification row carries priority module kind subject and group key', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());

    $notification = rawNotification($user, [
        'data' => ['key' => 'notifications.events.task.created', 'params' => ['subject' => 'مهمة']],
        'priority' => NotificationPriority::Important,
        'kind' => NotificationKind::Action,
        'subject_type' => 'task',
        'subject_id' => 7,
        'group_key' => '1:task:7',
    ]);

    $fresh = $user->notifications()->first();

    expect($fresh)->toBeInstanceOf(UserNotification::class)
        ->and($fresh->id)->toBe($notification->id)
        ->and($fresh->priority)->toBe(NotificationPriority::Important)
        ->and($fresh->kind)->toBe(NotificationKind::Action)
        ->and($fresh->subject_id)->toBe(7)
        ->and($fresh->group_key)->toBe('1:task:7')
        ->and($fresh->data['params']['subject'])->toBe('مهمة');
});

test('arabic text in notification data is stored unescaped so it can be searched', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());

    rawNotification($user, ['data' => ['params' => ['subject' => 'توزيع']]]);

    expect(UserNotification::query()->where('data', 'like', '%توزيع%')->count())->toBe(1);
});

test('snoozed notifications are hidden until their snooze ends', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());

    rawNotification($user, ['snoozed_until' => now()->addHour()]);
    rawNotification($user, ['snoozed_until' => now()->subMinute()]);
    rawNotification($user);

    expect(UserNotification::query()->forUser($user)->visible()->count())->toBe(2);
});

test('the kill switch stops recording for the callback only', function () {
    expect(Activity::isRecording())->toBeTrue();

    $inside = Activity::withoutRecording(fn (): bool => Activity::isRecording());

    expect($inside)->toBeFalse()->and(Activity::isRecording())->toBeTrue();
});

test('the kill switch is restored even when the callback throws', function () {
    try {
        Activity::withoutRecording(fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    expect(Activity::isRecording())->toBeTrue();
});

test('a user prefers the language saved on their account', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    $user->forceFill(['locale' => 'ar'])->save();

    expect($user->preferredLocale())->toBe('ar');
});

test('deleting a user deletes their inbox', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    rawNotification($user);

    $user->delete();

    expect(UserNotification::query()->count())->toBe(0);
});
