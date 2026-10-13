<?php

use App\Enums\TaskStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
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
    $user = User::factory()->role('General Manager')->create(['employee_id' => null]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->assertSeeText('No linked employee record')
        ->assertDontSee('data-test="calendar-day-', false);
});

test('a linked user sees their own responsible milestones on the correct day, and not another employee\'s', function () {
    $employeeId = insertHrEmployee();
    $otherEmployeeId = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Nassar');
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    $mine = Milestone::factory()->create([
        'name' => 'My milestone',
        'responsible_user_id' => $employeeId,
        'planned_start_date' => Carbon::create(2026, 6, 15),
    ]);
    $notMine = Milestone::factory()->create([
        'name' => 'Not mine',
        'responsible_user_id' => $otherEmployeeId,
        'planned_start_date' => Carbon::create(2026, 6, 15),
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
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->call('openCreateModal', '2026-06-15')
        ->assertSet('plannedStartDate', '2026-06-15')
        ->assertSet('showMilestoneModal', true)
        ->assertSet('responsibleUserId', (string) $employeeId);
});

test('saving from the calendar creates a milestone on the clicked date', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);
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

    expect($milestone->planned_start_date->toDateString())->toBe('2026-06-15')
        ->and($milestone->responsible_user_id)->toBe($employeeId);
});

test('month navigation shows the new month and hides the old one', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    $june = Milestone::factory()->create([
        'name' => 'June gate',
        'responsible_user_id' => $employeeId,
        'planned_start_date' => Carbon::create(2026, 6, 10),
    ]);
    $july = Milestone::factory()->create([
        'name' => 'July gate',
        'responsible_user_id' => $employeeId,
        'planned_start_date' => Carbon::create(2026, 7, 10),
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
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);
    $milestone = Milestone::factory()->create([
        'name' => 'Original name',
        'responsible_user_id' => $employeeId,
        'planned_start_date' => Carbon::create(2026, 6, 15),
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

test('deleting a milestone that still has a task from the calendar is refused', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);
    $milestone = Milestone::factory()->create([
        'responsible_user_id' => $employeeId,
        'planned_start_date' => Carbon::create(2026, 6, 15),
    ]);
    Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->call('confirmDelete', $milestone->id)
        ->call('deleteMilestone')
        ->assertSet('showDeleteModal', false)
        ->assertDispatched('toast-show', fn (string $event, array $params) => ($params['slots']['text'] ?? null) === __('milestones.this_milestone_still_has_nested_milestones_or_tasks_move_or')
            && ($params['dataset']['variant'] ?? null) === 'danger');

    $this->assertDatabaseHas('milestones', ['id' => $milestone->id]);
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

test('the right-click context menu offers to add either a milestone or a task, targeting the clicked date', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

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

    $milestone = Milestone::factory()->create([
        'responsible_user_id' => $employeeId,
        'planned_start_date' => Carbon::create(2026, 6, 15),
    ]);
    $task = Task::factory()->create([
        'due_date' => Carbon::create(2026, 6, 15),
    ]);
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $employeeId]);

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
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $myEmployeeId);

    $mine = Milestone::factory()->create([
        'name' => 'Mine',
        'responsible_user_id' => $myEmployeeId,
        'planned_start_date' => Carbon::create(2026, 6, 15),
    ]);
    $theirs = Milestone::factory()->create([
        'name' => 'Theirs',
        'responsible_user_id' => $otherEmployeeId,
        'planned_start_date' => Carbon::create(2026, 6, 15),
    ]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertSee('data-test="calendar-milestone-'.$mine->id.'"', false)
        ->assertDontSee('data-test="calendar-milestone-'.$theirs->id.'"', false)
        ->set('filterEmployeeIds', [(string) $otherEmployeeId])
        ->assertDontSee('data-test="calendar-milestone-'.$mine->id.'"', false)
        ->assertSee('data-test="calendar-milestone-'.$theirs->id.'"', false)
        ->set('filterEmployeeIds', [])
        ->assertSee('data-test="calendar-milestone-'.$mine->id.'"', false)
        ->assertDontSee('data-test="calendar-milestone-'.$theirs->id.'"', false);
});

test('adding a milestone while viewing another employee\'s calendar assigns it to them by default', function () {
    $myEmployeeId = insertHrEmployee();
    $otherEmployeeId = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Nassar');
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $myEmployeeId);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('filterEmployeeIds', [(string) $otherEmployeeId])
        ->call('openCreateModal', '2026-06-15')
        ->assertSet('responsibleUserId', (string) $otherEmployeeId);
});

test('selecting several employees in the responsible filter merges their milestones', function () {
    $myEmployeeId = insertHrEmployee();
    $otherEmployeeId = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Nassar');
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $myEmployeeId);

    $mine = Milestone::factory()->create([
        'name' => 'Mine',
        'responsible_user_id' => $myEmployeeId,
        'planned_start_date' => Carbon::create(2026, 6, 15),
    ]);
    $theirs = Milestone::factory()->create([
        'name' => 'Theirs',
        'responsible_user_id' => $otherEmployeeId,
        'planned_start_date' => Carbon::create(2026, 6, 15),
    ]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->set('filterEmployeeIds', [(string) $myEmployeeId, (string) $otherEmployeeId])
        ->assertSee('data-test="calendar-milestone-'.$mine->id.'"', false)
        ->assertSee('data-test="calendar-milestone-'.$theirs->id.'"', false)
        ->set('filterEmployeeIds', [(string) $myEmployeeId])
        ->assertSee('data-test="calendar-milestone-'.$mine->id.'"', false)
        ->assertDontSee('data-test="calendar-milestone-'.$theirs->id.'"', false);
});

test('switching to day view shows only the anchor date, and week view shows its whole week', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    $inWeek = Milestone::factory()->create([
        'responsible_user_id' => $employeeId,
        'planned_start_date' => Carbon::create(2026, 6, 16),
    ]);
    $outsideWeek = Milestone::factory()->create([
        'responsible_user_id' => $employeeId,
        'planned_start_date' => Carbon::create(2026, 6, 25),
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

test('narrowing the type filter to exactly one type shows the focused banner, and resetting restores the legend and the responsible filter', function () {
    $employeeId = insertHrEmployee();
    $otherEmployeeId = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Nassar');
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    Milestone::factory()->create([
        'responsible_user_id' => $employeeId,
        'planned_start_date' => Carbon::create(2026, 6, 15),
    ]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertDontSee('data-test="calendar-focused-banner"', false)
        ->set('showTasks', false)
        ->set('showReports', false)
        ->set('showProposals', false)
        ->set('showTransfers', false)
        ->set('showDonors', false)
        ->set('showProjects', false)
        ->set('filterEmployeeIds', [(string) $otherEmployeeId])
        ->assertSee('data-test="calendar-focused-banner"', false)
        ->assertSee(__('calendar.showing_type', ['type' => __('tasks.milestone')]))
        ->call('resetFilters')
        ->assertDontSee('data-test="calendar-focused-banner"', false)
        ->assertSet('showTasks', true)
        ->assertSet('showProjects', true)
        ->assertSet('filterEmployeeIds', []);
});

test('focusOnlyType isolates one type in a single call, closes the menu and jumps to its nearest occurrence', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);
    $targetDate = Carbon::today()->addDays(40);

    Milestone::factory()->create([
        'responsible_user_id' => $employeeId,
        'planned_start_date' => $targetDate,
    ]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('showTypeFilterMenu', true)
        ->call('focusOnlyType', 'milestone')
        ->assertSet('showMilestones', true)
        ->assertSet('showTasks', false)
        ->assertSet('showReports', false)
        ->assertSet('showProposals', false)
        ->assertSet('showTransfers', false)
        ->assertSet('showDonors', false)
        ->assertSet('showProjects', false)
        ->assertSet('showTypeFilterMenu', false)
        ->assertSet('month', $targetDate->format('Y-m'));
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

    Milestone::factory()->create([
        'responsible_user_id' => $employeeId,
        'planned_start_date' => $targetDate,
    ]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('showTasks', false)
        ->set('showReports', false)
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
        ->assertSet('showMilestones', true)
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
        ->set('showMilestones', false)
        ->set('showTasks', false)
        ->set('showReports', false)
        ->set('showProposals', false)
        ->set('showTransfers', false)
        ->call('toggleType', 'donor')
        ->assertSet('showProjects', true)
        ->assertSee('data-test="calendar-focused-list"', false)
        ->assertSee($project->name)
        ->assertSee(__('calendar.project_starts'))
        ->assertSee(__('calendar.project_ends'));
});

test('opening the day-detail list shows every visible item on that day and can edit or navigate to each', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);
    $milestone = Milestone::factory()->create([
        'name' => 'Kickoff review',
        'responsible_user_id' => $employeeId,
        'planned_start_date' => Carbon::create(2026, 6, 15),
    ]);
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
        ->call('openEditModal', $milestone->id)
        ->assertSet('name', 'Kickoff review');
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

test('the milestone, task and day-detail forms are rendered only while their modals are open', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->role('General Manager')->create(), $employeeId);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->assertDontSeeHtml('data-test="calendar-form-planned-date"')
        ->call('openCreateModal')
        ->assertSeeHtml('data-test="calendar-form-planned-date"');

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
