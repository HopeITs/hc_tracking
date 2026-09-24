<?php

use App\Enums\ArStatus;
use App\Enums\ReportStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\Alerts\OverdueScanner;
use App\Notifications\QuickActions;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

function taskNotificationFor(User $assignee, TaskStatus $status = TaskStatus::Todo): array
{
    $task = Activity::withoutRecording(function () use ($assignee, $status) {
        $task = Task::factory()->create(['status' => $status, 'title' => 'Plan']);
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $assignee->employee_id]);

        return $task;
    });
    test()->actingAs(userWithRole('Managers'));
    $task->update(['priority' => 'high']);

    return [$task, UserNotification::query()->forUser($assignee)->sole()];
}

test('a task notification offers start and mark done to someone who may update tasks', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    [, $notification] = taskNotificationFor($assignee);

    expect(array_column($notification->data['actions'], 'type'))->toBe(['task.start', 'task.mark_done']);
});

test('marking a task done from the bell records the actor and reads the notification', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    [$task, $notification] = taskNotificationFor($assignee);

    Livewire::actingAs($assignee)->test('notifications.bell')->call('runQuickAction', $notification->id, 'task.mark_done');

    expect($task->fresh()->status)->toBe(TaskStatus::Done)
        ->and($task->fresh()->completed_date)->not->toBeNull()
        ->and(Activity::query()->latest('id')->first()->actor_id)->toBe($assignee->id)
        ->and($notification->fresh()->read_at)->not->toBeNull();
});

test('a quick action on work already done changes nothing and says so', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    [$task, $notification] = taskNotificationFor($assignee);
    Activity::withoutRecording(fn () => $task->update(['status' => TaskStatus::Done]));

    $result = app(QuickActions::class)->run($assignee, $notification, 'task.mark_done');

    expect($result['ok'])->toBeFalse()->and($result['message'])->toBe('notifications.quick.already_done');
});

test('a quick action is re-authorised on the server', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    [, $notification] = taskNotificationFor($assignee);
    $assignee->syncRoles(['Media Team']); // no tasks.update any more

    $result = app(QuickActions::class)->run($assignee->fresh(), $notification, 'task.mark_done');

    expect($result['ok'])->toBeFalse()->and($result['message'])->toBe('notifications.quick.forbidden');
});

test('an action that was never offered cannot be run', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    [, $notification] = taskNotificationFor($assignee);

    expect(app(QuickActions::class)->run($assignee, $notification, 'report.approve')['message'])->toBe('notifications.quick.unavailable');
});

test('meal can mark a draft report reviewed and then approve it', function () {
    $meal = userWithRole('MEAL');
    $report = Activity::withoutRecording(fn () => Report::factory()->create(['status' => ReportStatus::Draft]));
    $this->actingAs(userWithRole('MEAL'));
    $report->update(['value' => '10']);
    $this->travel(6)->minutes();
    $notification = UserNotification::query()->forUser($meal)->sole();

    expect(array_column($notification->data['actions'], 'type'))->toBe(['report.mark_reviewed']);

    $this->actingAs($meal);
    expect(app(QuickActions::class)->run($meal, $notification, 'report.mark_reviewed')['ok'])->toBeTrue()
        ->and($report->fresh()->status)->toBe(ReportStatus::Reviewed)
        ->and($report->fresh()->submit_date)->not->toBeNull();
});

test('mark received opens the tranche edit form instead of guessing amounts', function () {
    $financial = userWithRole('Financial');
    $transfer = Activity::withoutRecording(fn () => TransfersTracking::factory()->create());
    $this->actingAs(userWithRole('Financial'));
    $transfer->update(['payment_amount' => '1500.00']);
    $notification = UserNotification::query()->forUser($financial)->sole();

    $result = app(QuickActions::class)->run($financial, $notification, 'transfer.mark_received');

    expect($result['redirect'])->toBe(route('transfers.index', ['edit' => $transfer->id]))
        ->and($transfer->fresh()->ar_status)->toBe(ArStatus::Pending);

    Livewire::actingAs($financial)->withQueryParams(['edit' => $transfer->id])->test('pages::transfers.index')
        ->assertSet('showTransferModal', true)
        ->assertSet('editingTransferId', $transfer->id);
});

test('an overdue alert can be snoozed until tomorrow', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    Activity::withoutRecording(function () use ($assignee): void {
        $task = Task::factory()->create(['due_date' => '2026-09-18']);
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $assignee->employee_id]);
    });
    $this->travelTo(CarbonImmutable::parse('2026-09-21 09:00'));
    app(OverdueScanner::class)->scan(CarbonImmutable::now());
    $alert = UserNotification::query()->forUser($assignee)->sole();

    expect(array_column($alert->data['actions'], 'type'))->toContain('alert.snooze');

    app(QuickActions::class)->run($assignee, $alert, 'alert.snooze');

    expect($alert->fresh()->snoozed_until->format('Y-m-d H:i'))->toBe('2026-09-22 08:00');
});

test('a quick action that did not go through leaves the notification unread', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    [$task, $notification] = taskNotificationFor($assignee);
    Activity::withoutRecording(fn () => $task->update(['status' => TaskStatus::Done]));

    Livewire::actingAs($assignee)->test('notifications.bell')->call('runQuickAction', $notification->id, 'task.mark_done');

    expect($notification->fresh()->read_at)->toBeNull();
});
