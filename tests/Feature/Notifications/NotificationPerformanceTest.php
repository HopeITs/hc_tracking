<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\NotificationPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

function fillInbox(User $user, int $count): void
{
    foreach (range(1, $count) as $n) {
        $user->notify(new AppNotification(new NotificationPayload(
            kind: NotificationKind::Action, module: 'tasks', priority: NotificationPriority::Info,
            key: 'notifications.events.task.created', params: ['actor' => 'Sami', 'subject' => "Task {$n}"],
            subjectType: 'task', subjectId: $n, actorId: Activity::withoutRecording(fn () => User::factory()->create())->id,
        )));
    }
}

test('the bell costs the same number of queries however full the inbox is', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    $measure = function () use ($user): int {
        cache()->flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($user)->test('notifications.bell');

        return count(DB::getQueryLog());
    };

    fillInbox($user, 3);
    $few = $measure();
    fillInbox($user, 25);

    expect($measure())->toBe($few);
});

test('the inbox lookups are indexed', function () {
    $indexed = collect(Schema::getIndexes('notifications'))->pluck('columns')->map(fn (array $columns): string => implode(',', $columns));

    expect($indexed)->toContain('notifiable_type,notifiable_id,read_at')
        ->toContain('notifiable_id,group_key,read_at')
        ->toContain('notifiable_id,subject_type,subject_id,read_at')
        ->toContain('snoozed_until');

    $activity = collect(Schema::getIndexes('activities'))->pluck('columns')->map(fn (array $columns): string => implode(',', $columns));

    expect($activity)->toContain('subject_type,subject_id,created_at')->toContain('program_id,created_at')->toContain('actor_id,created_at')->toContain('created_at');
});
