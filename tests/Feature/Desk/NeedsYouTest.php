<?php

use App\Enums\DeliveryState;
use App\Enums\MonthlyReportStatus;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\MonthlyReport;
use App\Models\Risk;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-09-16 09:00:00');
});

/**
 * A task created by $creator and assigned to one HR employee only.
 *
 * @param  array<string, mixed>  $attributes
 */
function needsTask(User $creator, int $assigneeEmployeeId, array $attributes): Task
{
    return Activity::withoutRecording(function () use ($creator, $assigneeEmployeeId, $attributes): Task {
        $task = Task::factory()->create(['created_by' => $creator->id, ...$attributes]);
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $assigneeEmployeeId]);

        return $task;
    });
}

test('a task sent for my review appears with who is waiting, my own plain task does not', function () {
    $me = userWithRole('Managers', insertHrEmployee());
    $worker = insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Khalil');
    needsTask($me, $worker, ['title' => 'Survey the wells', 'status' => TaskStatus::UnderReview, 'due_date' => '2026-09-17']);
    needsTask($me, $me->employee_id, ['title' => 'My own late chore', 'status' => TaskStatus::InProgress, 'due_date' => '2026-09-01']);
    Activity::withoutRecording(fn () => Risk::factory()->happened()->create(['name' => 'Road closure']));

    Livewire::actingAs($me)->test('desk.panels.needs', ['size' => 'L'])
        ->assertSee('Survey the wells')
        ->assertSee('Omar Khalil')
        ->assertSee(__('desk.decision_action.approve'))
        ->assertDontSee('My own late chore')
        ->assertDontSee('Road closure');
});

test('a submitted monthly report waiting for my validation appears', function () {
    $meal = userWithRole('MEAL', insertHrEmployee());
    Activity::withoutRecording(fn () => MonthlyReport::factory()->create([
        'period' => '2026-08-01',
        'status' => MonthlyReportStatus::Submitted,
        'submitted_at' => '2026-09-03 10:00:00',
    ]));

    Livewire::actingAs($meal)->test('desk.panels.needs', ['size' => 'L'])
        ->assertSee(__('desk.decision.validate', ['month' => 'August 2026']))
        ->assertSee(__('desk.decision_action.validate'));
});

test('this week is the default view, late narrows it and all shows later decisions too', function () {
    $me = userWithRole('Managers', insertHrEmployee());
    $worker = insertHrEmployee();
    needsTask($me, $worker, ['title' => 'Late approval', 'status' => TaskStatus::UnderReview, 'due_date' => '2026-09-10']);
    needsTask($me, $worker, ['title' => 'This week approval', 'status' => TaskStatus::UnderReview, 'due_date' => '2026-09-19']);
    needsTask($me, $worker, ['title' => 'Next month approval', 'status' => TaskStatus::UnderReview, 'due_date' => '2026-10-20']);

    $panel = Livewire::actingAs($me)->test('desk.panels.needs', ['size' => 'L'])
        ->assertSet('filter', 'week')
        ->assertSeeInOrder(['Late approval', 'This week approval'])
        ->assertDontSee('Next month approval');

    $panel->call('setFilter', 'late')
        ->assertSee('Late approval')
        ->assertDontSee('This week approval');

    $panel->call('setFilter', 'all')
        ->assertSee('Next month approval');

    Livewire::actingAs($me)->test('pages::desk.index')
        ->assertSet('needsCount', ['total' => 2, 'late' => 1]);
});

test('a delivered deliverable I requested can be accepted from the row', function () {
    $me = userWithRole('Managers', insertHrEmployee());
    $task = needsTask($me, insertHrEmployee(), [
        'title' => 'Field video',
        'kind' => TaskKind::MediaDeliverable,
        'delivery_state' => DeliveryState::Delivered,
        'delivered_on' => '2026-09-12',
        'status' => TaskStatus::InProgress,
    ]);

    Livewire::actingAs($me)->test('desk.panels.needs', ['size' => 'L'])
        ->assertSee('Field video')
        ->call('accept', $task->id)
        ->assertDontSee('Field video');

    expect($task->refresh()->delivery_state)->toBe(DeliveryState::Accepted);
});

test('nothing waiting shows the all clear state', function () {
    Livewire::actingAs(userWithRole('Managers', insertHrEmployee()))->test('desk.panels.needs', ['size' => 'L'])
        ->assertSee(__('desk.panel.needs.clear'));
});
