<?php

use App\Models\Activity;
use App\Models\Supervision;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\Alerts\NotificationSettings;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-09-15 09:00:00');
});

/**
 * A late task created by one user and assigned to one HR employee only.
 */
function nudgeLateTask(User $creator, int $assigneeEmployeeId): Task
{
    return Activity::withoutRecording(function () use ($creator, $assigneeEmployeeId): Task {
        $task = Task::factory()->create(['due_date' => '2026-09-10', 'created_by' => $creator->id, 'title' => 'Procure water pumps']);
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $assigneeEmployeeId]);

        return $task;
    });
}

test('nudging a late task I created reaches its assignee once a day and is logged', function () {
    $me = userWithRole('Managers', insertHrEmployee());
    $colleague = userWithRole('Team Member', insertHrEmployee(firstNameEn: 'Rana'));
    $task = nudgeLateTask($me, $colleague->employee_id);

    $panel = Livewire::actingAs($me)->test('desk.panels.waiting', ['size' => 'M'])
        ->assertSeeHtml("nudge('task-{$task->id}')")
        ->call('nudge', "task-{$task->id}");

    expect(UserNotification::query()->forUser($colleague)->count())->toBe(1)
        ->and(sentencesFor($colleague)[0])->toContain('nudged you about “Procure water pumps”')
        ->and(Activity::query()->where('event', 'nudge.logged')->where('subject_id', $task->id)->count())->toBe(1);

    $panel->call('nudge', "task-{$task->id}");
    expect(UserNotification::query()->forUser($colleague)->count())->toBe(1);

    $this->travel(25)->hours();
    Livewire::actingAs($me)->test('desk.panels.waiting', ['size' => 'M'])->call('nudge', "task-{$task->id}");
    expect(UserNotification::query()->forUser($colleague)->count())->toBe(2);
});

test('a key that is not one of my waiting rows nudges nobody', function () {
    $me = userWithRole('Managers', insertHrEmployee());
    $stranger = userWithRole('Team Member', insertHrEmployee(firstNameEn: 'Omar'));
    $task = nudgeLateTask(userWithRole('Managers', insertHrEmployee(firstNameEn: 'Lina')), $stranger->employee_id);

    Livewire::actingAs($me)->test('desk.panels.waiting', ['size' => 'M'])
        ->assertDontSeeHtml("nudge('task-{$task->id}')")
        ->call('nudge', "task-{$task->id}");

    expect(UserNotification::query()->forUser($stranger)->count())->toBe(0);
});

test('a supervisor nudges a supervisee about their oldest overdue task', function () {
    $me = userWithRole('Managers', insertHrEmployee());
    $supervisee = userWithRole('Team Member', insertHrEmployee(firstNameEn: 'Fadi'));
    Activity::withoutRecording(fn () => Supervision::factory()->create([
        'supervisor_employee_id' => $me->employee_id,
        'supervisee_employee_id' => $supervisee->employee_id,
    ]));
    nudgeLateTask($me, $supervisee->employee_id);

    Livewire::actingAs($me)->test('desk.panels.myteam', ['size' => 'M'])
        ->call('nudge', $supervisee->employee_id);

    expect(sentencesFor($supervisee)[0])->toContain('nudged you about “Procure water pumps”');
});

test('nobody is nudged when an administrator switches the event off', function () {
    $me = userWithRole('Managers', insertHrEmployee());
    $colleague = userWithRole('Team Member', insertHrEmployee(firstNameEn: 'Rana'));
    $task = nudgeLateTask($me, $colleague->employee_id);
    app(NotificationSettings::class)->save(['event_overrides' => ['nudge.sent' => ['enabled' => false]]]);

    Livewire::actingAs($me)->test('desk.panels.waiting', ['size' => 'M'])->call('nudge', "task-{$task->id}");

    expect(UserNotification::query()->forUser($colleague)->count())->toBe(0);
});
