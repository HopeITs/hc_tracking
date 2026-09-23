<?php

use App\Enums\NotificationPriority;
use App\Jobs\FanOutActivity;
use App\Models\Activity;
use App\Models\Task;
use App\Models\UserNotification;
use App\Notifications\NotificationPresenter;

test('assigning someone with no login warns the actor, naming them', function () {
    $actor = userWithRole('Managers');
    $withoutAccount = insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Ali', firstNameAr: 'نور', lastNameAr: 'علي');
    $task = Activity::withoutRecording(fn () => Task::factory()->create(['title' => 'Plan']));

    $this->actingAs($actor);
    $task->taskAssignees()->create(['employee_id' => $withoutAccount]);

    $warning = UserNotification::query()->forUser($actor)->sole();

    expect($warning->priority)->toBe(NotificationPriority::Important)
        ->and(sentencesFor($actor))->toBe(['Nour Ali could not be notified about “Plan” because they have no login account'])
        ->and(app(NotificationPresenter::class)->sentence($warning->data, 'ar'))->toContain('نور علي');
});

test('the warning is not repeated when the fan-out is retried', function () {
    $actor = userWithRole('Managers');
    $task = Activity::withoutRecording(fn () => Task::factory()->create());

    $this->actingAs($actor);
    $task->taskAssignees()->create(['employee_id' => insertHrEmployee()]);
    dispatch_sync(new FanOutActivity(Activity::query()->value('id')));

    expect(UserNotification::query()->forUser($actor)->count())->toBe(1);
});

test('nobody is warned about system changes', function () {
    $task = Activity::withoutRecording(fn () => Task::factory()->create());

    $task->taskAssignees()->create(['employee_id' => insertHrEmployee()]);

    expect(UserNotification::query()->count())->toBe(0);
});
