<?php

use App\Actions\Desk\SendNudge;
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
        ->assertSee("nudge('task-{$task->id}', note)")
        ->call('nudge', "task-{$task->id}");

    expect(UserNotification::query()->forUser($colleague)->count())->toBe(1)
        ->and(sentencesFor($colleague)[0])->toContain('poked you about “Procure water pumps”')
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
        ->assertDontSee("nudge('task-{$task->id}', note)")
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

    expect(sentencesFor($supervisee)[0])->toContain('poked you about “Procure water pumps”');
});

test('nobody is nudged when an administrator switches the event off', function () {
    $me = userWithRole('Managers', insertHrEmployee());
    $colleague = userWithRole('Team Member', insertHrEmployee(firstNameEn: 'Rana'));
    $task = nudgeLateTask($me, $colleague->employee_id);
    app(NotificationSettings::class)->save(['event_overrides' => ['nudge.sent' => ['enabled' => false]]]);

    Livewire::actingAs($me)->test('desk.panels.waiting', ['size' => 'M'])->call('nudge', "task-{$task->id}");

    expect(UserNotification::query()->forUser($colleague)->count())->toBe(0);
});

test('a poke carries a cleaned note, and the same sender pokes the same person once a day', function () {
    $me = userWithRole('Managers', insertHrEmployee());
    $colleague = userWithRole('Team Member', insertHrEmployee(firstNameEn: 'Rana'));
    $task = nudgeLateTask($me, $colleague->employee_id);
    $other = nudgeLateTask($me, $colleague->employee_id);

    Livewire::actingAs($me)->test('desk.panels.waiting', ['size' => 'M'])
        ->call('nudge', "task-{$task->id}", '  <b>Can you</b>   give me an update? ')
        ->call('nudge', "task-{$other->id}", 'Second item');

    expect(sentencesFor($colleague))->toHaveCount(1)
        ->and(sentencesFor($colleague)[0])->toContain('poked you about “Procure water pumps”: “Can you give me an update?”');
});

test('a note is cut to 200 characters and a blank note is dropped', function () {
    expect(mb_strlen(SendNudge::cleanNote(str_repeat('a', 300))))->toBe(200)
        ->and(SendNudge::cleanNote('   '))->toBeNull();
});

test('My team can poke someone with nothing overdue, without a subject', function () {
    $me = userWithRole('Managers', insertHrEmployee());
    $supervisee = userWithRole('Team Member', insertHrEmployee(firstNameEn: 'Fadi'));
    Activity::withoutRecording(fn () => Supervision::factory()->create([
        'supervisor_employee_id' => $me->employee_id,
        'supervisee_employee_id' => $supervisee->employee_id,
    ]));

    Livewire::actingAs($me)->test('desk.panels.myteam', ['size' => 'M'])
        ->call('nudge', $supervisee->employee_id, 'Coffee at 3?');

    expect(sentencesFor($supervisee))->toBe([$me->name.' poked you: “Coffee at 3?”'])
        ->and(Activity::query()->where('event', 'nudge.logged')->count())->toBe(0);
});
