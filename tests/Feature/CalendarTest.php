<?php

use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/** Attach the user's account to an HR employee record, bypassing mass-assignment guarding. */
function linkUserToEmployee(User $user, int $employeeId): User
{
    $user->employee_id = $employeeId;
    $user->saveQuietly();

    return $user->refresh();
}

test('guests are redirected from the calendar', function () {
    $this->get(route('calendar.index'))->assertRedirect(route('login'));
});

test('a user with no linked HR employee sees an explanation instead of a calendar', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->assertSeeText('No linked employee record')
        ->assertDontSee('data-test="calendar-day-', false);
});

test('a linked user sees their own responsible milestones on the correct day, and not another employee\'s', function () {
    $employeeId = insertHrEmployee();
    $otherEmployeeId = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Nassar');
    $user = linkUserToEmployee(User::factory()->create(), $employeeId);

    $mine = Milestone::factory()->create([
        'name' => 'My milestone',
        'responsible_user_id' => $employeeId,
        'planned_date' => Carbon::create(2026, 6, 15),
    ]);
    $notMine = Milestone::factory()->create([
        'name' => 'Not mine',
        'responsible_user_id' => $otherEmployeeId,
        'planned_date' => Carbon::create(2026, 6, 15),
    ]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertSee('data-test="calendar-milestone-'.$mine->id.'"', false)
        ->assertSee('My milestone')
        // The other employee's milestone must not appear as a calendar chip,
        // though its name may still legitimately appear elsewhere on the page
        // (e.g. a milestone picker unrelated to "my" scoping), so this checks
        // the chip hook specifically rather than a page-wide text search.
        ->assertDontSee('data-test="calendar-milestone-'.$notMine->id.'"', false);
});

test('clicking a day pre-fills the add milestone form with that date', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->create(), $employeeId);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->call('openCreateModal', '2026-06-15')
        ->assertSet('plannedDate', '2026-06-15')
        ->assertSet('showMilestoneModal', true)
        ->assertSet('responsibleUserId', (string) $employeeId);
});

test('saving from the calendar creates a milestone on the clicked date', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->create(), $employeeId);
    $project = Project::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->call('openCreateModal', '2026-06-15')
        ->set('name', 'Kickoff review')
        ->set('milestoneProjectId', (string) $project->id)
        ->call('saveMilestone')
        ->assertHasNoErrors()
        ->assertSet('showMilestoneModal', false);

    $milestone = Milestone::query()->where('name', 'Kickoff review')->sole();

    expect($milestone->planned_date->toDateString())->toBe('2026-06-15')
        ->and($milestone->responsible_user_id)->toBe($employeeId);
});

test('month navigation shows the new month and hides the old one', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->create(), $employeeId);

    $june = Milestone::factory()->create([
        'name' => 'June gate',
        'responsible_user_id' => $employeeId,
        'planned_date' => Carbon::create(2026, 6, 10),
    ]);
    $july = Milestone::factory()->create([
        'name' => 'July gate',
        'responsible_user_id' => $employeeId,
        'planned_date' => Carbon::create(2026, 7, 10),
    ]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertSee('data-test="calendar-milestone-'.$june->id.'"', false)
        ->assertDontSee('data-test="calendar-milestone-'.$july->id.'"', false)
        ->call('nextMonth')
        ->assertSet('month', '2026-07')
        ->assertSee('data-test="calendar-milestone-'.$july->id.'"', false)
        ->assertDontSee('data-test="calendar-milestone-'.$june->id.'"', false);
});

test('a milestone can be edited and deleted from the calendar', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->create(), $employeeId);
    $milestone = Milestone::factory()->create([
        'name' => 'Original name',
        'responsible_user_id' => $employeeId,
        'planned_date' => Carbon::create(2026, 6, 15),
    ]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->call('openEditModal', $milestone->id)
        ->assertSet('name', 'Original name')
        ->set('name', 'Renamed')
        ->call('saveMilestone')
        ->assertHasNoErrors();

    expect($milestone->refresh()->name)->toBe('Renamed');

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->call('confirmDelete', $milestone->id)
        ->assertSet('showDeleteModal', true)
        ->call('deleteMilestone');

    $this->assertDatabaseMissing('milestones', ['id' => $milestone->id]);
});

test('a linked user sees their own assigned tasks on the correct day, and not another employee\'s', function () {
    $employeeId = insertHrEmployee();
    $otherEmployeeId = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Nassar');
    $user = linkUserToEmployee(User::factory()->create(), $employeeId);

    $mine = Task::factory()->create([
        'title' => 'My task',
        'assigned_to' => $employeeId,
        'due_date' => Carbon::create(2026, 6, 15),
    ]);
    $notMine = Task::factory()->create([
        'title' => 'Not my task',
        'assigned_to' => $otherEmployeeId,
        'due_date' => Carbon::create(2026, 6, 15),
    ]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertSee('data-test="calendar-task-'.$mine->id.'"', false)
        ->assertSee('My task')
        ->assertDontSee('data-test="calendar-task-'.$notMine->id.'"', false);
});

test('the right-click context menu offers to add either a milestone or a task, targeting the clicked date', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->create(), $employeeId);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertSee('data-test="calendar-context-menu"', false)
        ->assertSee('data-test="calendar-context-add-milestone"', false)
        ->assertSee('data-test="calendar-context-add-task"', false)
        ->assertSee('$wire.openCreateModal(menuDate)', false)
        ->assertSee('$wire.openCreateTaskModal(menuDate)', false);
});

test('clicking a day pre-fills the add task form with that date and defaults the assignee to the signed-in employee', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->create(), $employeeId);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->call('openCreateTaskModal', '2026-06-15')
        ->assertSet('dueDate', '2026-06-15')
        ->assertSet('showTaskModal', true)
        ->assertSet('assignedTo', (string) $employeeId);
});

test('saving a task from the calendar creates it with the clicked due date', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->create(), $employeeId);
    $proposal = Proposal::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->call('openCreateTaskModal', '2026-06-15')
        ->set('title', 'Follow up with donor')
        ->set('taskProposalId', (string) $proposal->id)
        ->call('saveTask')
        ->assertHasNoErrors()
        ->assertSet('showTaskModal', false);

    $task = Task::query()->where('title', 'Follow up with donor')->sole();

    expect($task->due_date->toDateString())->toBe('2026-06-15')
        ->and($task->assigned_to)->toBe($employeeId)
        ->and($task->proposal_id)->toBe($proposal->id);
});

test('a linked user sees their own report deadlines on the correct day, and not another user\'s', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->create(), $employeeId);
    $otherUser = User::factory()->create();

    $mine = Report::factory()->create([
        'user_id' => $user->id,
        'planned_date' => Carbon::create(2026, 6, 15),
    ]);
    $notMine = Report::factory()->create([
        'user_id' => $otherUser->id,
        'planned_date' => Carbon::create(2026, 6, 15),
    ]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertSee('data-test="calendar-report-'.$mine->id.'"', false)
        ->assertDontSee('data-test="calendar-report-'.$notMine->id.'"', false);
});

test('a task can be edited and deleted from the calendar', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->create(), $employeeId);
    $task = Task::factory()->create([
        'title' => 'Original task',
        'assigned_to' => $employeeId,
        'due_date' => Carbon::create(2026, 6, 15),
    ]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->call('openEditTaskModal', $task->id)
        ->assertSet('title', 'Original task')
        ->set('title', 'Renamed task')
        ->call('saveTask')
        ->assertHasNoErrors();

    expect($task->refresh()->title)->toBe('Renamed task');

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->call('confirmDeleteTask', $task->id)
        ->assertSet('showTaskDeleteModal', true)
        ->call('deleteTask');

    $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
});

test('turning off a type filter hides that type\'s chips without affecting the others', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->create(), $employeeId);

    $milestone = Milestone::factory()->create([
        'responsible_user_id' => $employeeId,
        'planned_date' => Carbon::create(2026, 6, 15),
    ]);
    $task = Task::factory()->create([
        'assigned_to' => $employeeId,
        'due_date' => Carbon::create(2026, 6, 15),
    ]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertSee('data-test="calendar-milestone-'.$milestone->id.'"', false)
        ->assertSee('data-test="calendar-task-'.$task->id.'"', false)
        ->set('showMilestones', false)
        ->assertDontSee('data-test="calendar-milestone-'.$milestone->id.'"', false)
        ->assertSee('data-test="calendar-task-'.$task->id.'"', false);
});

test('the responsible filter switches the calendar to another employee\'s items, and back to mine', function () {
    $myEmployeeId = insertHrEmployee();
    $otherEmployeeId = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Nassar');
    $user = linkUserToEmployee(User::factory()->create(), $myEmployeeId);

    $mine = Milestone::factory()->create([
        'name' => 'Mine',
        'responsible_user_id' => $myEmployeeId,
        'planned_date' => Carbon::create(2026, 6, 15),
    ]);
    $theirs = Milestone::factory()->create([
        'name' => 'Theirs',
        'responsible_user_id' => $otherEmployeeId,
        'planned_date' => Carbon::create(2026, 6, 15),
    ]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertSee('data-test="calendar-milestone-'.$mine->id.'"', false)
        ->assertDontSee('data-test="calendar-milestone-'.$theirs->id.'"', false)
        ->set('filterEmployeeId', (string) $otherEmployeeId)
        ->assertDontSee('data-test="calendar-milestone-'.$mine->id.'"', false)
        ->assertSee('data-test="calendar-milestone-'.$theirs->id.'"', false)
        ->set('filterEmployeeId', '')
        ->assertSee('data-test="calendar-milestone-'.$mine->id.'"', false)
        ->assertDontSee('data-test="calendar-milestone-'.$theirs->id.'"', false);
});

test('adding a milestone while viewing another employee\'s calendar assigns it to them by default', function () {
    $myEmployeeId = insertHrEmployee();
    $otherEmployeeId = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Nassar');
    $user = linkUserToEmployee(User::factory()->create(), $myEmployeeId);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('filterEmployeeId', (string) $otherEmployeeId)
        ->call('openCreateModal', '2026-06-15')
        ->assertSet('responsibleUserId', (string) $otherEmployeeId);
});

test('switching to day view shows only the anchor date, and week view shows its whole week', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->create(), $employeeId);

    $inWeek = Milestone::factory()->create([
        'responsible_user_id' => $employeeId,
        'planned_date' => Carbon::create(2026, 6, 16),
    ]);
    $outsideWeek = Milestone::factory()->create([
        'responsible_user_id' => $employeeId,
        'planned_date' => Carbon::create(2026, 6, 25),
    ]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('anchorDate', '2026-06-15')
        ->set('viewMode', 'day')
        ->assertSee('data-test="calendar-allday-2026-06-15"', false)
        ->assertDontSee('data-test="calendar-allday-2026-06-16"', false)
        ->set('viewMode', 'week')
        ->assertSee('data-test="calendar-allday-2026-06-16"', false)
        ->assertSee('data-test="calendar-milestone-'.$inWeek->id.'"', false)
        ->assertDontSee('data-test="calendar-milestone-'.$outsideWeek->id.'"', false);
});

test('previous and next move by the active view\'s period, not always by a month', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->create(), $employeeId);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('viewMode', 'four_days')
        ->set('anchorDate', '2026-06-15')
        ->call('nextPeriod')
        ->assertSet('anchorDate', '2026-06-19')
        ->call('previousPeriod')
        ->call('previousPeriod')
        ->assertSet('anchorDate', '2026-06-11');
});

test('the today button resets both the month grid and the day/week anchor', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->create(), $employeeId);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('viewMode', 'week')
        ->set('anchorDate', '2026-06-15')
        ->call('goToToday')
        ->assertSet('anchorDate', '')
        ->assertSet('month', '');
});
