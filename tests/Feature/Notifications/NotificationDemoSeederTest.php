<?php

use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\NotificationDemoSeeder;

test('the demo seeder fills every role holder inbox with real, partly read notifications', function () {
    $this->seed();
    $first = userWithRole('Super Admin', insertHrEmployee());
    $second = userWithRole('Super Admin', insertHrEmployee());

    $this->seed(NotificationDemoSeeder::class);

    foreach ([$first, $second] as $recipient) {
        $inbox = UserNotification::query()->forUser($recipient)->get();

        expect($inbox)->not->toBeEmpty()
            ->and($inbox->whereNull('read_at'))->not->toBeEmpty()
            ->and($inbox->whereNotNull('read_at'))->not->toBeEmpty()
            ->and($inbox->where('priority', NotificationPriority::Critical))->not->toBeEmpty()
            ->and($inbox->max(fn (UserNotification $notification): int => $notification->data['count']))->toBeGreaterThan(1);
    }

    expect(Activity::query()->whereNotNull('actor_id')->count())->toBeGreaterThan(0);
});

test('the demo seeder does nothing when nobody can receive notifications', function () {
    $this->seed();
    Activity::withoutRecording(fn () => User::query()->each(fn (User $user) => $user->syncRoles([])));

    $this->seed(NotificationDemoSeeder::class);

    expect(Activity::query()->count())->toBe(0)
        ->and(UserNotification::query()->count())->toBe(0);
});
