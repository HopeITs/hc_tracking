<?php

use App\Models\Activity;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;

function storedNotification(User $user, ?string $readAt): UserNotification
{
    return UserNotification::query()->create([
        'id' => (string) Str::uuid(), 'type' => 'test', 'notifiable_type' => 'user', 'notifiable_id' => $user->id,
        'data' => [], 'module' => 'tasks', 'read_at' => $readAt,
    ]);
}

test('read notifications go after 61 days; unread ones stay forever', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    $old = storedNotification($user, now()->subDays(62)->toDateTimeString());
    $recent = storedNotification($user, now()->subDays(60)->toDateTimeString());
    $unread = storedNotification($user, null);
    $unread->forceFill(['created_at' => now()->subYears(3)])->save();

    $this->artisan('model:prune', ['--model' => [UserNotification::class]])->assertSuccessful();

    expect(UserNotification::query()->pluck('id')->sort()->values()->all())->toBe(collect([$recent->id, $unread->id])->sort()->values()->all());
});

test('activities go after two years', function () {
    $make = fn (string $at) => Activity::withoutRecording(fn () => Activity::query()->forceCreate([
        'subject_type' => 'task', 'subject_id' => 1, 'module' => 'tasks', 'event' => 'task.updated',
        'changes' => [], 'properties' => [], 'created_at' => $at, 'updated_at' => $at,
    ]));
    $expired = $make(now()->subYears(2)->subDay()->toDateTimeString());
    $kept = $make(now()->subYears(2)->addDay()->toDateTimeString());

    $this->artisan('model:prune', ['--model' => [Activity::class]])->assertSuccessful();

    expect(Activity::query()->pluck('id')->all())->toBe([$kept->id]);
});

test('pruning is scheduled daily', function () {
    $prune = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'model:prune'));

    expect($prune)->not->toBeNull()
        ->and($prune->command)->toContain('Activity')->toContain('UserNotification')
        ->and($prune->expression)->toBe('0 0 * * *');
});
