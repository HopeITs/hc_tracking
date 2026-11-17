<?php

use App\Enums\ProjectStatus;
use App\Enums\ReportStatus;
use App\Enums\TaskStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLine;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use App\Support\MonthlyReportPeriods;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/** A task due on $due, assigned to one HR employee only. */
function calendarTask(int $employeeId, DateTimeInterface $due, string $title = 'Calendar task'): Task
{
    $task = Task::factory()->create(['title' => $title, 'due_date' => $due]);
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $employeeId]);

    return $task;
}

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
    $user = User::factory()->role('General Manager')->create(['employee_id' => null]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->assertSeeText('No linked employee record')
        ->assertDontSee('data-test="calendar-day-', false);
});

test('month navigation shows the new month and hides the old one', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    $june = calendarTask($employeeId, Carbon::create(2026, 6, 10), 'June gate');
    $july = calendarTask($employeeId, Carbon::create(2026, 7, 10), 'July gate');

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertSee('data-test="calendar-task-'.$june->id.'"', false)
        ->assertDontSee('data-test="calendar-task-'.$july->id.'"', false)
        ->call('nextMonth')
        ->assertSet('month', '2026-07')
        ->assertSee('data-test="calendar-task-'.$july->id.'"', false)
        ->assertDontSee('data-test="calendar-task-'.$june->id.'"', false);
});

test('a linked user sees their own assigned tasks on the correct day, and not another employee\'s', function () {
    $employeeId = insertHrEmployee();
    $otherEmployeeId = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Nassar');
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    $mine = Task::factory()->create([
        'title' => 'My task',
        'due_date' => Carbon::create(2026, 6, 15),
    ]);
    $mine->taskAssignees()->delete();
    $mine->taskAssignees()->create(['employee_id' => $employeeId]);
    $notMine = Task::factory()->create([
        'title' => 'Not my task',
        'due_date' => Carbon::create(2026, 6, 15),
    ]);
    $notMine->taskAssignees()->delete();
    $notMine->taskAssignees()->create(['employee_id' => $otherEmployeeId]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertSee('data-test="calendar-task-'.$mine->id.'"', false)
        ->assertSee('My task')
        ->assertDontSee('data-test="calendar-task-'.$notMine->id.'"', false);
});

test('the right-click context menu offers to add a task, targeting the clicked date', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertSee('data-test="calendar-context-menu"', false)
        ->assertSee('data-test="calendar-context-add-task"', false)
        ->assertSee('$wire.openCreateTaskModal(menuDate)', false);
});

test('clicking a day pre-fills the add task form with that date and defaults the assignee to the signed-in employee', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->call('openCreateTaskModal', '2026-06-15')
        ->assertSet('dueDate', '2026-06-15')
        ->assertSet('showTaskModal', true)
        ->assertSet('assignedTo', [(string) $employeeId]);
});

test('saving a task from the calendar creates it with the clicked due date', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);
    $proposal = Proposal::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->call('openCreateTaskModal', '2026-06-15')
        ->set('title', 'Follow up with donor')
        ->set('taskSourceType', 'proposal')
        ->set('taskSourceId', (string) $proposal->id)
        ->call('saveTask')
        ->assertHasNoErrors()
        ->assertSet('showTaskModal', false);

    $task = Task::query()->where('title', 'Follow up with donor')->sole();

    expect($task->due_date->toDateString())->toBe('2026-06-15')
        ->and($task->taskAssignees->pluck('employee_id')->all())->toBe([$employeeId])
        ->and($task->source_type)->toBe('proposal')
        ->and($task->source_id)->toBe($proposal->id);
});

test('a linked user sees their own report deadlines on the correct day, and not another user\'s', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);
    $otherUser = User::factory()->role('General Manager')->create();

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

test('a linked user sees their own unscheduled tasks in the unscheduled panel', function () {
    $employeeId = insertHrEmployee();
    $otherEmployeeId = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Nassar');
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    $mine = Task::factory()->create(['title' => 'My unscheduled task', 'due_date' => null]);
    $mine->taskAssignees()->delete();
    $mine->taskAssignees()->create(['employee_id' => $employeeId]);

    $notMine = Task::factory()->create(['title' => 'Not my unscheduled task', 'due_date' => null]);
    $notMine->taskAssignees()->delete();
    $notMine->taskAssignees()->create(['employee_id' => $otherEmployeeId]);

    $scheduled = Task::factory()->create(['title' => 'Scheduled task', 'due_date' => Carbon::create(2026, 6, 15)]);
    $scheduled->taskAssignees()->delete();
    $scheduled->taskAssignees()->create(['employee_id' => $employeeId]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->assertSee('data-test="calendar-unscheduled-'.$mine->id.'"', false)
        ->assertSee('My unscheduled task')
        ->assertDontSee('data-test="calendar-unscheduled-'.$notMine->id.'"', false)
        ->assertDontSee('data-test="calendar-unscheduled-'.$scheduled->id.'"', false);
});

test('rescheduling a task from the calendar sets its due date, and clearing it unschedules it again', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);
    // Only a task's creator moves its due date.
    $task = Task::factory()->create(['title' => 'Drag me', 'due_date' => null, 'created_by' => $user->id]);
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $employeeId]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->call('rescheduleTask', $task->id, '2026-06-20');

    expect($task->refresh()->due_date->toDateString())->toBe('2026-06-20');

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->call('rescheduleTask', $task->id, '');

    expect($task->refresh()->due_date)->toBeNull();
});

test('a task can be edited and deleted from the calendar', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);
    $task = Task::factory()->create([
        'title' => 'Original task',
        'due_date' => Carbon::create(2026, 6, 15),
    ]);
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $employeeId]);

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

test('a completed task cannot be reopened from the calendar edit form', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);
    $task = Task::factory()->create([
        'title' => 'Finished task',
        'status' => TaskStatus::Done,
        'created_by' => $user->id,
        'due_date' => Carbon::create(2026, 6, 15),
    ]);
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $employeeId]);

    $component = Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->call('openEditTaskModal', $task->id)
        ->set('taskStatus', TaskStatus::Todo->value)
        ->call('saveTask');

    expect($task->refresh()->status)->toBe(TaskStatus::Done);

    $component
        ->assertDispatched('toast-show', fn (string $event, array $params) => ($params['slots']['text'] ?? null) === __('tasks.completed_tasks_cannot_change_status')
            && ($params['dataset']['variant'] ?? null) === 'danger')
        ->assertSet('showTaskModal', true);
});

test('turning off a type filter hides that type\'s chips without affecting the others', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    $project = Project::factory()->create(['user_id' => $employeeId, 'start_date' => Carbon::create(2026, 6, 15), 'end_date' => Carbon::create(2026, 12, 31)]);
    $task = calendarTask($employeeId, Carbon::create(2026, 6, 15));

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertSee('data-test="calendar-project-start-'.$project->id.'"', false)
        ->assertSee('data-test="calendar-task-'.$task->id.'"', false)
        ->set('showTasks', false)
        ->assertDontSee('data-test="calendar-task-'.$task->id.'"', false)
        ->assertSee('data-test="calendar-project-start-'.$project->id.'"', false);
});

test('the responsible filter switches the calendar to another employee\'s items, and back to mine', function () {
    $myEmployeeId = insertHrEmployee();
    $otherEmployeeId = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Nassar');
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $myEmployeeId);

    $mine = calendarTask($myEmployeeId, Carbon::create(2026, 6, 15), 'Mine');
    $theirs = calendarTask($otherEmployeeId, Carbon::create(2026, 6, 15), 'Theirs');

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertSee('data-test="calendar-task-'.$mine->id.'"', false)
        ->assertDontSee('data-test="calendar-task-'.$theirs->id.'"', false)
        ->set('filterEmployeeIds', [(string) $otherEmployeeId])
        ->assertDontSee('data-test="calendar-task-'.$mine->id.'"', false)
        ->assertSee('data-test="calendar-task-'.$theirs->id.'"', false)
        ->set('filterEmployeeIds', [])
        ->assertSee('data-test="calendar-task-'.$mine->id.'"', false)
        ->assertDontSee('data-test="calendar-task-'.$theirs->id.'"', false);
});

test('switching to day view shows only the anchor date, and week view shows its whole week', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    $inWeek = calendarTask($employeeId, Carbon::create(2026, 6, 16));
    $outsideWeek = calendarTask($employeeId, Carbon::create(2026, 6, 25));

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('anchorDate', '2026-06-15')
        ->set('viewMode', 'day')
        ->assertSee('data-test="calendar-allday-2026-06-15"', false)
        ->assertDontSee('data-test="calendar-allday-2026-06-16"', false)
        ->set('viewMode', 'week')
        ->assertSee('data-test="calendar-allday-2026-06-16"', false)
        ->assertSee('data-test="calendar-task-'.$inWeek->id.'"', false)
        ->assertDontSee('data-test="calendar-task-'.$outsideWeek->id.'"', false);
});

test('previous and next move by the active view\'s period, not always by a month', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

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

test('a linked user sees start/end dates for their own projects, and not another employee\'s', function () {
    $employeeId = insertHrEmployee();
    $otherEmployeeId = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Nassar');
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    $mine = Project::factory()->create([
        'user_id' => $employeeId,
        'start_date' => Carbon::create(2026, 6, 15),
        'end_date' => Carbon::create(2026, 6, 20),
    ]);
    $notMine = Project::factory()->create([
        'user_id' => $otherEmployeeId,
        'start_date' => Carbon::create(2026, 6, 15),
        'end_date' => Carbon::create(2026, 6, 20),
    ]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertSee('data-test="calendar-project-start-'.$mine->id.'"', false)
        ->assertSee('data-test="calendar-project-end-'.$mine->id.'"', false)
        ->assertDontSee('data-test="calendar-project-start-'.$notMine->id.'"', false)
        ->assertDontSee('data-test="calendar-project-end-'.$notMine->id.'"', false);
});

test('a linked user sees deadlines for proposals funding their own projects, and not another employee\'s projects', function () {
    $employeeId = insertHrEmployee();
    $otherEmployeeId = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Nassar');
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    $myProject = Project::factory()->create(['user_id' => $employeeId]);
    $theirProject = Project::factory()->create(['user_id' => $otherEmployeeId]);

    $mine = Proposal::factory()->create(['planned_date_end' => Carbon::create(2026, 6, 15)]);
    $mine->projects()->attach($myProject);

    $notMine = Proposal::factory()->create(['planned_date_end' => Carbon::create(2026, 6, 15)]);
    $notMine->projects()->attach($theirProject);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertSee('data-test="calendar-proposal-'.$mine->id.'"', false)
        ->assertDontSee('data-test="calendar-proposal-'.$notMine->id.'"', false);
});

test('a linked user sees transfer dates for funding agreements under their own projects', function () {
    $employeeId = insertHrEmployee();
    $otherEmployeeId = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Nassar');
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    $myProject = Project::factory()->create(['user_id' => $employeeId]);
    $theirProject = Project::factory()->create(['user_id' => $otherEmployeeId]);

    $myProposal = Proposal::factory()->create();
    $myProposal->projects()->attach($myProject);
    $myAgreement = DonorProposal::factory()->create(['proposal_id' => $myProposal->id]);
    $mine = TransfersTracking::factory()->create([
        'donor_proposal_id' => $myAgreement->id,
        'scheduled_date' => Carbon::create(2026, 6, 15),
    ]);

    $theirProposal = Proposal::factory()->create();
    $theirProposal->projects()->attach($theirProject);
    $theirAgreement = DonorProposal::factory()->create(['proposal_id' => $theirProposal->id]);
    $notMine = TransfersTracking::factory()->create([
        'donor_proposal_id' => $theirAgreement->id,
        'scheduled_date' => Carbon::create(2026, 6, 15),
    ]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertSee('data-test="calendar-transfer-'.$mine->id.'"', false)
        ->assertDontSee('data-test="calendar-transfer-'.$notMine->id.'"', false);
});

test('a linked user sees donor follow-ups and donor report deadlines tied to their own projects', function () {
    $employeeId = insertHrEmployee();
    $otherEmployeeId = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Nassar');
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    $myProject = Project::factory()->create(['user_id' => $employeeId]);
    $theirProject = Project::factory()->create(['user_id' => $otherEmployeeId]);

    $myProposal = Proposal::factory()->create();
    $myProposal->projects()->attach($myProject);
    $myDonor = Donor::factory()->create(['next_step_due_on' => Carbon::create(2026, 6, 15)]);
    Proposal::query()->whereKey($myProposal->id)->update(['donor_id' => $myDonor->id]);
    $myAgreement = DonorProposal::factory()->create(['proposal_id' => $myProposal->id, 'donor_id' => $myDonor->id]);
    $myReport = DonorReport::factory()->create(['donor_proposal_id' => $myAgreement->id, 'due_on' => Carbon::create(2026, 6, 15)]);

    $theirProposal = Proposal::factory()->create();
    $theirProposal->projects()->attach($theirProject);
    $theirDonor = Donor::factory()->create(['next_step_due_on' => Carbon::create(2026, 6, 15)]);
    Proposal::query()->whereKey($theirProposal->id)->update(['donor_id' => $theirDonor->id]);
    $theirAgreement = DonorProposal::factory()->create(['proposal_id' => $theirProposal->id, 'donor_id' => $theirDonor->id]);
    $theirReport = DonorReport::factory()->create(['donor_proposal_id' => $theirAgreement->id, 'due_on' => Carbon::create(2026, 6, 15)]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertSee('data-test="calendar-donor-followup-'.$myDonor->id.'"', false)
        ->assertSee('data-test="calendar-donor-report-'.$myReport->id.'"', false)
        ->assertDontSee('data-test="calendar-donor-followup-'.$theirDonor->id.'"', false)
        ->assertDontSee('data-test="calendar-donor-report-'.$theirReport->id.'"', false);
});

test('toggling off the new type filters hides their chips without affecting the others', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    $project = Project::factory()->create([
        'user_id' => $employeeId,
        'start_date' => Carbon::create(2026, 6, 15),
        'end_date' => Carbon::create(2026, 6, 20),
    ]);
    $proposal = Proposal::factory()->create(['planned_date_end' => Carbon::create(2026, 6, 15)]);
    $proposal->projects()->attach($project);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertSee('data-test="calendar-project-start-'.$project->id.'"', false)
        ->assertSee('data-test="calendar-proposal-'.$proposal->id.'"', false)
        ->set('showProjects', false)
        ->assertDontSee('data-test="calendar-project-start-'.$project->id.'"', false)
        ->assertSee('data-test="calendar-proposal-'.$proposal->id.'"', false);
});

test('narrowing the type filter to exactly one type shows the focused banner, and clearing it restores the types but keeps the people picked', function () {
    $employeeId = insertHrEmployee();
    $otherEmployeeId = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Nassar');
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    calendarTask($employeeId, Carbon::create(2026, 6, 15));

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertDontSee('data-test="calendar-focused-banner"', false)
        ->set('showReports', false)
        ->set('showMonthlyReports', false)
        ->set('showProposals', false)
        ->set('showTransfers', false)
        ->set('showDonors', false)
        ->set('showProjects', false)
        ->set('filterEmployeeIds', [(string) $otherEmployeeId])
        ->assertSee('data-test="calendar-focused-banner"', false)
        ->assertSee(__('calendar.showing_type', ['type' => __('tasks.task')]))
        ->call('resetFilters')
        ->assertDontSee('data-test="calendar-focused-banner"', false)
        ->assertSet('showTasks', true)
        ->assertSet('showProjects', true)
        ->assertSet('filterEmployeeIds', [(string) $otherEmployeeId]);
});

test('focusOnlyType isolates one type in a single call, closes the menu and jumps to its nearest occurrence', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);
    $targetDate = Carbon::today()->addDays(40);

    calendarTask($employeeId, $targetDate);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('showTypeFilterMenu', true)
        ->call('focusOnlyType', 'task')
        ->assertSet('showTasks', true)
        ->assertSet('showReports', false)
        ->assertSet('showProposals', false)
        ->assertSet('showTransfers', false)
        ->assertSet('showDonors', false)
        ->assertSet('showProjects', false)
        ->assertSet('showTypeFilterMenu', false)
        ->assertSet('month', $targetDate->format('Y-m'))
        ->assertDispatched('toast-show');
});

test('toggling a type keeps the menu open, so unchecking several types in a row needs no reopening', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('showTypeFilterMenu', true)
        ->call('toggleType', 'task')
        ->assertSet('showTypeFilterMenu', true)
        ->call('toggleType', 'report')
        ->assertSet('showTypeFilterMenu', true);
});

test('narrowing to a single type via toggleType jumps the grid to its nearest occurrence', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);
    $targetDate = Carbon::today()->addDays(40);

    calendarTask($employeeId, $targetDate);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('showReports', false)
        ->set('showMonthlyReports', false)
        ->set('showProposals', false)
        ->set('showTransfers', false)
        ->set('showDonors', false)
        ->call('toggleType', 'project')
        ->assertSet('month', $targetDate->format('Y-m'));
});

test('an unknown type sent to toggleType or focusOnlyType changes no type filter', function (string $method) {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('showTasks', false)
        ->set('showTypeFilterMenu', true)
        ->call($method, 'unknown')
        ->assertOk()
        ->assertSet('showTasks', false)
        ->assertSet('showReports', true)
        ->assertSet('showProposals', true)
        ->assertSet('showTransfers', true)
        ->assertSet('showDonors', true)
        ->assertSet('showProjects', true)
        ->assertSet('showTypeFilterMenu', true);
})->with(['toggleType', 'focusOnlyType']);

test('an impossible month in the URL falls back to the current month', function (string $month) {
    $this->travelTo(Carbon::create(2026, 6, 15, 9));
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', $month)
        ->assertOk()
        ->assertSee('data-test="calendar-day-2026-06-15"', false)
        ->call('nextMonth')
        ->assertSet('month', '2026-07');
})->with(['2026-13', '2026-00']);

test('an impossible anchor date in the URL falls back to today', function (string $anchorDate) {
    $this->travelTo(Carbon::create(2026, 6, 15, 9));
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('viewMode', 'day')
        ->set('anchorDate', $anchorDate)
        ->assertOk()
        ->assertSee('data-test="calendar-allday-2026-06-15"', false)
        ->call('nextPeriod')
        ->assertSet('anchorDate', '2026-06-16');
})->with(['2026-13-45', '2026-02-31', '2026-00-10']);

test('the related-dates panel lists both occurrences of a composite type with click-through', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);
    $project = Project::factory()->create([
        'user_id' => $employeeId,
        'start_date' => Carbon::today()->addDays(5),
        'end_date' => Carbon::today()->addDays(35),
    ]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('showTasks', false)
        ->set('showTasks', false)
        ->set('showReports', false)
        ->set('showMonthlyReports', false)
        ->set('showProposals', false)
        ->set('showTransfers', false)
        ->call('toggleType', 'donor')
        ->assertSet('showProjects', true)
        ->assertSee('data-test="calendar-focused-list"', false)
        ->assertSee($project->name)
        ->assertSee(__('calendar.project_starts'))
        ->assertSee(__('calendar.project_ends'));
});

test('the related-dates panel lists upcoming project ends instead of past project starts', function () {
    $this->travelTo(Carbon::create(2026, 11, 6, 9));
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);
    $projects = Project::factory()->count(3)->sequence(
        ['name' => 'Closing Soon A', 'start_date' => Carbon::create(2024, 3, 1), 'end_date' => Carbon::create(2026, 12, 31)],
        ['name' => 'Closing Soon B', 'start_date' => Carbon::create(2024, 7, 1), 'end_date' => Carbon::create(2026, 12, 31)],
        ['name' => 'Closing Later', 'start_date' => Carbon::create(2025, 1, 1), 'end_date' => Carbon::create(2027, 3, 31)],
    )->create(['user_id' => $employeeId]);

    $component = Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->call('focusOnlyType', 'project');

    $items = $component->instance()->focusedItems;

    expect($items)->toHaveCount(3)
        ->and($items->pluck('subLabel')->unique()->all())->toBe([__('calendar.project_ends')])
        ->and($items->pluck('id')->all())->toEqualCanonicalizing($projects->pluck('id')->all())
        ->and($items->last()['title'])->toBe('Closing Later');

    $component->assertSeeText(trans_choice('calendar.items_count', 3, ['count' => 3]));
});

test('the related-dates panel falls back to the most recent past dates across both project sources when nothing is upcoming', function () {
    $this->travelTo(Carbon::create(2026, 11, 6, 9));
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);
    $project = Project::factory()->create([
        'user_id' => $employeeId,
        'start_date' => Carbon::create(2024, 3, 1),
        'end_date' => Carbon::create(2026, 9, 30),
    ]);

    $items = Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->call('focusOnlyType', 'project')
        ->instance()
        ->focusedItems;

    expect($items->pluck('subLabel')->all())->toBe([__('calendar.project_starts'), __('calendar.project_ends')])
        ->and($items->pluck('id')->unique()->all())->toBe([$project->id]);
});

test('opening the day-detail list shows every visible item on that day and can edit or navigate to each', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);
    $kickoff = calendarTask($employeeId, Carbon::create(2026, 6, 15), 'Kickoff review');
    $task = Task::factory()->create(['title' => 'Follow up', 'due_date' => Carbon::create(2026, 6, 15)]);
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $employeeId]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->call('openDayDetail', '2026-06-15')
        ->assertSet('showDayDetailModal', true)
        ->assertSee('data-test="calendar-daydetail-list"', false)
        ->assertSee('Kickoff review')
        ->assertSee('Follow up')
        ->call('openEditTaskModal', $kickoff->id)
        ->assertSet('title', 'Kickoff review');
});

test('a day with no visible items has no clickable content area', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertDontSee('data-test="calendar-day-content-2026-06-15"', false);
});

test('the today button resets both the month grid and the day/week anchor', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('viewMode', 'week')
        ->set('anchorDate', '2026-06-15')
        ->call('goToToday')
        ->assertSet('anchorDate', '')
        ->assertSet('month', '');
});

test('the task and day-detail forms are rendered only while their modals are open', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->assertDontSeeHtml('data-test="calendar-task-form-due-date"')
        ->call('openCreateTaskModal')
        ->assertSeeHtml('data-test="calendar-task-form-due-date"');

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->assertDontSeeHtml('data-test="calendar-daydetail-list"')
        ->call('openDayDetail', now()->toDateString())
        ->assertSeeHtml('data-test="calendar-daydetail-list"');
});

test('an active proposal with an extended end shows on the extended day, marked as extended', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);
    $project = Project::factory()->create(['user_id' => $employeeId]);

    $extended = Proposal::factory()->create([
        'status' => 'active',
        'planned_date_end' => Carbon::create(2026, 6, 15),
        'extended_date_end' => Carbon::create(2026, 8, 20),
    ]);
    $plain = Proposal::factory()->create(['status' => 'active', 'planned_date_end' => Carbon::create(2026, 6, 16)]);
    $extended->projects()->attach($project);
    $plain->projects()->attach($project);

    Livewire::actingAs($user)->test('pages::calendar.index')->set('month', '2026-06')
        ->assertDontSee('data-test="calendar-proposal-'.$extended->id.'"', false)
        ->assertSee('data-test="calendar-proposal-'.$plain->id.'"', false)
        ->assertDontSee('data-test="calendar-proposal-extended-'.$plain->id.'"', false);

    Livewire::actingAs($user)->test('pages::calendar.index')->set('month', '2026-08')
        ->assertSee('data-test="calendar-proposal-'.$extended->id.'"', false)
        ->assertSee('data-test="calendar-proposal-extended-'.$extended->id.'"', false);
});

test('someone who may not add tasks gets no Add button and no click-a-day menu', function () {
    $employeeId = insertHrEmployee();
    $viewer = linkUserToEmployee(User::factory()->role('Team Member')->create(), $employeeId);

    Livewire::actingAs($viewer)
        ->test('pages::calendar.index')
        ->assertOk()
        ->assertDontSee('data-test="calendar-add-button"', false)
        ->assertDontSee('data-test="calendar-context-add-task"', false)
        ->assertDontSee('menuOpen = true', false);

    Livewire::actingAs(linkUserToEmployee(User::factory()->role('General Manager')->create(), insertHrEmployee()))
        ->test('pages::calendar.index')
        ->assertSee('data-test="calendar-add-button"', false)
        ->assertSee('menuOpen = true', false);
});

test('a task, report and project whose date passed with the work unfinished are marked late, finished ones are not', function () {
    $this->travelTo(Carbon::create(2026, 6, 20, 9));
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    $lateTask = Task::factory()->create(['due_date' => Carbon::create(2026, 6, 10), 'status' => TaskStatus::InProgress]);
    $doneTask = Task::factory()->create(['due_date' => Carbon::create(2026, 6, 10), 'status' => TaskStatus::Done]);
    foreach ([$lateTask, $doneTask] as $task) {
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $employeeId]);
    }

    $lateReport = Report::factory()->create(['user_id' => $user->id, 'planned_date' => Carbon::create(2026, 6, 10), 'status' => ReportStatus::Draft]);
    $approvedReport = Report::factory()->create(['user_id' => $user->id, 'planned_date' => Carbon::create(2026, 6, 10), 'status' => ReportStatus::Approved]);

    $openProject = Project::factory()->status(ProjectStatus::Active)->create(['user_id' => $employeeId, 'start_date' => Carbon::create(2025, 1, 1), 'end_date' => Carbon::create(2026, 6, 10)]);
    $closedProject = Project::factory()->status(ProjectStatus::Closed)->create(['user_id' => $employeeId, 'start_date' => Carbon::create(2025, 1, 1), 'end_date' => Carbon::create(2026, 6, 10)]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertSee('data-test="calendar-late-task-'.$lateTask->id.'"', false)
        ->assertDontSee('data-test="calendar-late-task-'.$doneTask->id.'"', false)
        ->assertSee('data-test="calendar-late-report-'.$lateReport->id.'"', false)
        ->assertDontSee('data-test="calendar-late-report-'.$approvedReport->id.'"', false)
        ->assertSee('data-test="calendar-late-project-end-'.$openProject->id.'"', false)
        ->assertDontSee('data-test="calendar-late-project-end-'.$closedProject->id.'"', false)
        ->assertSeeText(__('calendar.ends_name', ['name' => $openProject->name]));
});

test('a monthly report appears on its due date and is marked late once that date passes as a draft', function () {
    $this->travelTo(Carbon::create(2026, 7, 20, 9));
    $employeeId = insertHrEmployee();
    $viewer = linkUserToEmployee(User::factory()->role('Team Member')->create(), $employeeId);
    $project = Project::factory()->create(['user_id' => $employeeId]);

    $draft = MonthlyReport::factory()->for($project)->create(['period' => Carbon::create(2026, 6, 1)]);
    $validated = MonthlyReport::factory()->for($project)->validated()->create(['period' => Carbon::create(2026, 5, 1)]);
    $lineless = MonthlyReport::factory()->for($project)->create(['period' => Carbon::create(2026, 4, 1)]);
    MonthlyReportLine::factory()->for($draft)->create();
    MonthlyReportLine::factory()->for($validated)->create();

    $periods = MonthlyReportPeriods::fromLoaded($project, new EloquentCollection);
    $draftDue = $periods->dueDate(CarbonImmutable::create(2026, 6, 1));
    $validatedDue = $periods->dueDate(CarbonImmutable::create(2026, 5, 1));

    $component = Livewire::actingAs($viewer)->test('pages::calendar.index');

    $component->set('month', $draftDue->format('Y-m'))
        ->assertSeeHtml('data-test="calendar-day-'.$draftDue->toDateString().'"')
        ->assertSee('data-test="calendar-monthly-report-'.$draft->id.'"', false)
        ->assertSee('data-test="calendar-late-monthly-report-'.$draft->id.'"', false)
        ->assertDontSee('data-test="calendar-monthly-report-'.$lineless->id.'"', false);

    $component->set('month', $validatedDue->format('Y-m'))
        ->assertSee('data-test="calendar-monthly-report-'.$validated->id.'"', false)
        ->assertDontSee('data-test="calendar-late-monthly-report-'.$validated->id.'"', false);

    expect($component->instance()->calendar['monthlyReportsByDay']->get($validatedDue->toDateString())?->pluck('id')->all())->toBe([$validated->id]);
});

test('focusing on monthly reports lists their due dates in the related-dates panel', function () {
    $this->travelTo(Carbon::create(2026, 7, 20, 9));
    $employeeId = insertHrEmployee();
    $viewer = linkUserToEmployee(User::factory()->role('Team Member')->create(), $employeeId);
    $project = Project::factory()->create(['user_id' => $employeeId]);
    $report = MonthlyReport::factory()->for($project)->create(['period' => Carbon::create(2026, 7, 1)]);
    MonthlyReportLine::factory()->for($report)->create();

    $items = Livewire::actingAs($viewer)
        ->test('pages::calendar.index')
        ->call('focusOnlyType', 'monthly_report')
        ->assertSet('showTasks', false)
        ->instance()
        ->focusedItems;

    expect($items->pluck('id')->all())->toBe([$report->id])
        ->and($items->first()['date']->toDateString())->toBe(MonthlyReportPeriods::fromLoaded($project, new EloquentCollection)->dueDate(CarbonImmutable::create(2026, 7, 1))->toDateString());
});

test('a busy day shows its item count for phone screens', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);
    Project::factory()->count(3)->create(['user_id' => $employeeId, 'start_date' => Carbon::create(2025, 1, 1), 'end_date' => Carbon::create(2026, 12, 31)]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-12')
        ->assertSee('data-test="calendar-day-count-2026-12-31"', false)
        ->assertSeeText(trans_choice('calendar.items_short', 3, ['count' => 3]));
});

test('the type filter is kept in the address, so a reload shows the same types', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->call('toggleType', 'task')
        ->assertSet('shownTypes', 'report,monthly_report,proposal,transfer,donor,project')
        ->call('resetFilters')
        ->assertSet('shownTypes', '');

    Livewire::withQueryParams(['types' => 'task,not-a-type'])
        ->actingAs($user)
        ->test('pages::calendar.index')
        ->assertSet('showTasks', true)
        ->assertSet('showReports', false)
        ->assertSet('showProjects', false)
        ->assertSee('data-test="calendar-focused-banner"', false);
});

test('"Only" keeps the month on screen when it already has that type', function () {
    $this->travelTo(Carbon::create(2026, 6, 10, 9));
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);
    calendarTask($employeeId, Carbon::create(2026, 6, 2));
    calendarTask($employeeId, Carbon::create(2026, 8, 1));

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->call('focusOnlyType', 'task')
        ->assertSet('month', '')
        ->assertNotDispatched('toast-show');
});

test('a busy day shows three chips and a "+N more" button to the rest', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);
    Project::factory()->count(5)->create(['user_id' => $employeeId, 'start_date' => Carbon::create(2025, 1, 1), 'end_date' => Carbon::create(2026, 12, 30)]);
    Project::factory()->count(2)->create(['user_id' => $employeeId, 'start_date' => Carbon::create(2025, 1, 1), 'end_date' => Carbon::create(2026, 12, 29)]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-12')
        ->assertSee('data-test="calendar-day-more-2026-12-30"', false)
        ->assertSeeText(trans_choice('calendar.more_items', 2, ['count' => 2]))
        ->assertDontSee('data-test="calendar-day-more-2026-12-29"', false)
        ->call('openDayDetail', '2026-12-30')
        ->assertSeeHtml('data-test="calendar-daydetail-list"');
});

test('the heading names whose calendar is on screen', function () {
    $employeeId = insertHrEmployee();
    $ranaId = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Nassar');
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->assertSeeText(__('common.my_calendar'))
        ->set('filterEmployeeIds', [(string) $ranaId])
        ->assertSeeText(__('calendar.calendar_of', ['name' => 'Rana Nassar']))
        ->set('filterEmployeeIds', [(string) $ranaId, (string) $employeeId])
        ->assertSeeText(trans_choice('calendar.calendar_of_people', 2, ['count' => 2]));
});

test('dates use the language patterns, with the year only when it is not this year, and Arabic keeps one set of month names', function () {
    $this->travelTo(Carbon::create(2026, 11, 6, 9));
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), insertHrEmployee());

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('viewMode', 'day')
        ->set('anchorDate', '2026-11-04')
        ->assertSeeText('Wednesday, November 4')
        ->set('anchorDate', '2025-11-05')
        ->assertSeeText('Wednesday, November 5, 2025');

    app()->setLocale('ar');

    expect(CarbonImmutable::getLocale())->toBe('ar_SY');

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->assertSeeText('الأربعاء')
        ->assertSeeText('تشرين الثاني 2026');
});

test('people who may add can open the add menu from the keyboard on the day number', function () {
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), insertHrEmployee());
    $viewer = linkUserToEmployee(User::factory()->role('Team Member')->create(), insertHrEmployee());
    $today = now()->toDateString();

    Livewire::actingAs($user)->test('pages::calendar.index')
        ->assertSee('data-test="calendar-day-add-'.$today.'"', false);

    Livewire::actingAs($viewer)->test('pages::calendar.index')
        ->assertDontSee('data-test="calendar-day-add-'.$today.'"', false);
});

test('rejected and completed proposals leave the calendar, and an extended proposal is not shown as overdue after its planned end', function () {
    $this->travelTo(Carbon::create(2026, 7, 1, 9));
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);
    $project = Project::factory()->create(['user_id' => $employeeId]);

    $rejected = Proposal::factory()->create(['status' => 'rejected', 'planned_date_end' => Carbon::create(2026, 8, 5)]);
    $completed = Proposal::factory()->create(['status' => 'completed', 'planned_date_end' => Carbon::create(2026, 8, 6)]);
    $extended = Proposal::factory()->create(['status' => 'active', 'planned_date_end' => Carbon::create(2026, 6, 15), 'extended_date_end' => Carbon::create(2026, 8, 20)]);
    foreach ([$rejected, $completed, $extended] as $proposal) {
        $proposal->projects()->attach($project);
    }

    $html = Livewire::actingAs($user)->test('pages::calendar.index')->set('month', '2026-08')
        ->assertDontSee('data-test="calendar-proposal-'.$rejected->id.'"', false)
        ->assertDontSee('data-test="calendar-proposal-'.$completed->id.'"', false)
        ->html();

    expect($html)->toMatch('/class="[^"]*bg-status-active-soft[^"]*"\s+data-test="calendar-proposal-'.$extended->id.'"/');
});
