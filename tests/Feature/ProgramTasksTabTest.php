<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\DonorReport;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\NotificationPayload;
use Livewire\Livewire;

/**
 * A program with one project and one milestone, and a manager who may do
 * everything on its tasks.
 *
 * @return array{program: Program, project: Project, milestone: Milestone, manager: User, employeeId: int}
 */
function programTaskSetup(): array
{
    $employeeId = insertHrEmployee();
    $program = Program::factory()->create();
    $project = Project::factory()->create(['program_id' => $program->id, 'name' => 'Wells Project']);
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'name' => 'Pumps installed']);

    return [
        'program' => $program,
        'project' => $project,
        'milestone' => $milestone,
        'manager' => userWithRole('General Manager', $employeeId),
        'employeeId' => $employeeId,
    ];
}

function projectTask(Project $project, array $attributes = []): Task
{
    return Task::factory()->create(['source_type' => 'project', 'source_id' => $project->id, ...$attributes]);
}

test('the stat cards count and filter the tasks, and search reaches every task', function () {
    ['program' => $program, 'project' => $project, 'manager' => $manager] = programTaskSetup();
    projectTask($project, ['title' => 'Late pump order', 'due_date' => now()->subDays(3)]);
    projectTask($project, ['title' => 'Future survey', 'due_date' => now()->addMonth()]);
    projectTask($project, ['title' => 'Finished report', 'status' => TaskStatus::Done]);

    $page = Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->assertSee(['Late pump order', 'Future survey'])
        ->assertDontSee('Finished report')
        ->assertSee('3 d late');

    $page->call('pickTaskFilter', 'overdue')
        ->assertSet('taskFilter', 'overdue')
        ->assertSee('Late pump order')
        ->assertDontSee('Future survey');

    $page->call('pickTaskFilter', 'overdue')->assertSet('taskFilter', 'open');

    $page->set('taskSearch', 'finished')
        ->assertSee('Finished report')
        ->assertDontSee('Late pump order');
});

test('the tasks can be grouped by due date and by assignee', function () {
    ['program' => $program, 'project' => $project, 'manager' => $manager] = programTaskSetup();
    projectTask($project, ['due_date' => now()->subDay()]);
    projectTask($project, ['due_date' => null]);

    Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->set('taskGroupBy', 'due')
        ->assertSeeHtmlInOrder(['data-task-bucket="overdue"', 'data-task-bucket="none"'])
        ->set('taskGroupBy', 'assignee')
        ->assertSeeHtml('data-task-bucket="employee-')
        ->set('taskGroupBy', 'nonsense')
        ->assertSet('taskGroupBy', 'project');
});

test('a task is re-prioritised, moved to another status and completed from the row', function () {
    ['program' => $program, 'project' => $project, 'manager' => $manager] = programTaskSetup();
    $task = projectTask($project);
    $other = projectTask($project);

    Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->call('setProgramTaskPriority', $task->id, 'high')
        ->call('setProgramTaskStatus', $other->id, TaskStatus::InProgress->value)
        ->call('setProgramTaskStatus', $task->id, TaskStatus::Done->value);

    expect($task->fresh()->priority)->toBe(TaskPriority::High)
        ->and($task->fresh()->status)->toBe(TaskStatus::Done)
        ->and($other->fresh()->status)->toBe(TaskStatus::InProgress);
});

test('only the task creator moves its due date', function () {
    ['program' => $program, 'project' => $project, 'manager' => $manager] = programTaskSetup();
    $own = projectTask($project, ['created_by' => $manager->id]);
    $someoneElses = projectTask($project);

    Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->call('rescheduleProgramTask', $own->id, '2027-03-01')
        ->call('rescheduleProgramTask', $someoneElses->id, '2027-03-01');

    expect($own->fresh()->due_date->toDateString())->toBe('2027-03-01')
        ->and($someoneElses->fresh()->due_date)->toBeNull();
});

test('assignees are added and removed, down to none', function () {
    ['program' => $program, 'project' => $project, 'manager' => $manager, 'employeeId' => $employeeId] = programTaskSetup();
    $task = projectTask($project);
    $firstAssignee = $task->taskAssignees()->value('employee_id');

    $page = Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->call('togglePickedAssignee', $task->id, $employeeId);

    expect($task->taskAssignees()->pluck('employee_id')->all())->toContain($employeeId);

    $page->call('togglePickedAssignee', $task->id, $firstAssignee);

    expect($task->taskAssignees()->pluck('employee_id')->all())->toBe([$employeeId]);

    $page->call('togglePickedAssignee', $task->id, $employeeId);

    expect($task->taskAssignees()->count())->toBe(0);
});

test('a group adds a task unassigned when its author has no HR employee', function () {
    ['program' => $program, 'project' => $project] = programTaskSetup();
    $author = userWithRole('General Manager', null);

    Livewire::actingAs($author)
        ->test('pages::programs.show', ['program' => $program])
        ->call('addProgramTask', 'Find a volunteer', 'project:'.$project->id);

    expect(Task::query()->where('title', 'Find a volunteer')->sole()->taskAssignees()->count())->toBe(0);
});

test('a task is deleted from the row unless it still has subtasks', function () {
    ['program' => $program, 'project' => $project, 'manager' => $manager] = programTaskSetup();
    $parent = projectTask($project);
    projectTask($project, ['parent_task_id' => $parent->id]);
    $lone = projectTask($project);

    Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->call('deleteProgramTask', $parent->id)
        ->call('deleteProgramTask', $lone->id);

    expect(Task::query()->find($parent->id))->not->toBeNull()
        ->and(Task::query()->find($lone->id))->toBeNull();
});

test('a group adds a task assigned to its author and a row adds a subtask', function () {
    ['program' => $program, 'project' => $project, 'manager' => $manager, 'employeeId' => $employeeId] = programTaskSetup();
    $parent = projectTask($project);

    Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->call('addProgramTask', 'Buy spare parts', 'project:'.$project->id)
        ->call('addProgramSubtask', $parent->id, 'Call the supplier');

    $added = Task::query()->where('title', 'Buy spare parts')->firstOrFail();
    $subtask = Task::query()->where('title', 'Call the supplier')->firstOrFail();

    expect($added->only(['source_type', 'source_id']))->toBe(['source_type' => 'project', 'source_id' => $project->id])
        ->and($added->taskAssignees()->pluck('employee_id')->all())->toBe([$employeeId])
        ->and($subtask->parent_task_id)->toBe($parent->id)
        ->and($subtask->taskAssignees()->pluck('employee_id')->all())->toBe($parent->taskAssignees()->pluck('employee_id')->all());
});

test('a task of another program is never touched from this page', function () {
    ['program' => $program, 'manager' => $manager] = programTaskSetup();
    $elsewhere = projectTask(Project::factory()->create());

    Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->call('setProgramTaskPriority', $elsewhere->id, 'high')
        ->call('deleteProgramTask', $elsewhere->id);

    expect($elsewhere->fresh())->not->toBeNull()
        ->and($elsewhere->fresh()->priority)->not->toBe(TaskPriority::High);
});

test('a user without task rights cannot change a task and sees no edit controls', function () {
    ['program' => $program, 'project' => $project] = programTaskSetup();
    $task = projectTask($project);
    $viewer = userWithRole('Team Member');

    Livewire::actingAs($viewer)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->assertDontSeeHtml('data-test="task-status-trigger-'.$task->id.'"')
        ->assertDontSeeHtml('data-test="task-priority-trigger-'.$task->id.'"')
        ->call('setProgramTaskPriority', $task->id, 'high')
        ->call('deleteProgramTask', $task->id);

    expect($task->fresh())->not->toBeNull()
        ->and($task->fresh()->priority)->not->toBe(TaskPriority::High);
});

test('a read-only viewer sees the tasks but no add button or add line', function () {
    ['program' => $program, 'project' => $project] = programTaskSetup();
    projectTask($project, ['title' => 'Order the pumps']);
    $reader = User::factory()->create();
    $reader->givePermissionTo('programs.view');
    expect($reader->can('create', Task::class))->toBeFalse();

    Livewire::actingAs($reader)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->assertSee('Order the pumps')
        ->assertDontSeeHtml('data-test="add-program-task-button"')
        ->assertDontSeeHtml('data-task-add=');
});

test('the task list shows seven tasks a page', function () {
    ['program' => $program, 'project' => $project, 'manager' => $manager] = programTaskSetup();
    foreach (range(1, 12) as $day) {
        projectTask($project, ['title' => sprintf('Paged task %02d', $day), 'due_date' => now()->addDays($day)]);
    }

    Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->assertSee(['Paged task 01', 'Paged task 07', '1 of 2'])
        ->assertDontSee('Paged task 08')
        ->call('goToTaskPage', 2)
        ->assertSee(['Paged task 08', 'Paged task 12'])
        ->assertDontSee('Paged task 01')
        ->call('pickTaskFilter', 'week')
        ->assertSet('taskPage', 1);
});

test('each line of the side list shows one task count, flagged when some are past due', function () {
    ['program' => $program, 'project' => $project, 'manager' => $manager] = programTaskSetup();
    $calm = Project::factory()->create(['program_id' => $program->id]);
    projectTask($project, ['due_date' => now()->subDay()]);
    projectTask($project, ['due_date' => now()->addWeek()]);
    projectTask($calm, ['due_date' => now()->addWeek()]);

    Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->assertSeeHtml('title="2 tasks · 1 past due"')
        ->assertSeeHtml('class="dk-ptk-rail-n dk-num dk-bad"')
        ->assertSeeHtml('title="1 task"');
});

test('a subtask shows a remove button and is removed from its parent', function () {
    ['program' => $program, 'project' => $project, 'manager' => $manager] = programTaskSetup();
    $parent = projectTask($project);
    $subtask = projectTask($project, ['parent_task_id' => $parent->id, 'created_by' => $manager->id]);

    Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->assertSeeHtml('data-test="subtask-remove-'.$subtask->id.'"')
        ->call('deleteProgramTask', $subtask->id);

    expect(Task::query()->find($subtask->id))->toBeNull()
        ->and(Task::query()->find($parent->id))->not->toBeNull();
});

test('a task row shows no progress percentage', function () {
    ['program' => $program, 'project' => $project, 'manager' => $manager] = programTaskSetup();
    projectTask($project, ['title' => 'Half-typed task', 'progress_percentage' => 47]);

    Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->assertSee('Half-typed task')
        ->assertDontSee('47%')
        ->assertDontSeeHtml('dk-ptk-prog');
});

test('an empty subtask field closes on blur, on a click elsewhere and on Esc', function () {
    ['program' => $program, 'project' => $project, 'manager' => $manager] = programTaskSetup();
    $parent = projectTask($project);

    Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->assertSeeHtml('data-subtask-add="'.$parent->id.'"')
        ->assertSeeHtml('@click.outside="stopSubtask()"')
        ->assertSeeHtml('@blur="stopSubtask()" @keydown.escape="title = \'\'; stopSubtask()" data-subtask-input="'.$parent->id.'"');
});

test('the grouping pills carry no visible "Group by" label', function () {
    ['program' => $program, 'project' => $project, 'manager' => $manager] = programTaskSetup();
    projectTask($project);

    Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->assertSeeHtml('aria-label="Group by"')
        ->assertDontSeeHtml('dk-ptk-seg-l');
});

test('a task row shows one assignee by photo and name, several by up to three photos and a count', function () {
    ['program' => $program, 'project' => $project, 'manager' => $manager] = programTaskSetup();
    $solo = projectTask($project, ['title' => 'Solo task']);
    $solo->taskAssignees()->delete();
    $solo->taskAssignees()->create(['employee_id' => insertHrEmployee(firstNameEn: 'Lina', lastNameEn: 'Solo')]);
    $crowd = projectTask($project, ['title' => 'Crowd task']);
    $crowd->taskAssignees()->delete();
    foreach (['Ali', 'Bea', 'Cem', 'Dina'] as $firstName) {
        $crowd->taskAssignees()->create(['employee_id' => insertHrEmployee(firstNameEn: $firstName, lastNameEn: 'Crowd')]);
    }

    $html = Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->html();

    $crowdCell = str($html)->after('data-test="task-assignee-trigger-'.$crowd->id.'"')->before('dk-ptk-prio')->toString();
    $soloCell = str($html)->after('data-test="task-assignee-trigger-'.$solo->id.'"')->before('dk-ptk-prio')->toString();

    expect($soloCell)->toContain('<span class="dk-ptk-who-n">Lina Solo</span>')
        ->and(substr_count($crowdCell, 'class="dk-ptk-who-p"'))->toBe(3)
        ->and($crowdCell)->toContain('+1')
        ->and($crowdCell)->not->toContain('dk-ptk-who-n');
});

test('the × on an assignee photo takes that person off the task', function () {
    ['program' => $program, 'project' => $project, 'manager' => $manager] = programTaskSetup();
    $task = projectTask($project);
    $assignee = $task->taskAssignees()->value('employee_id');

    Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->assertSeeHtml('data-test="task-assignee-remove-'.$task->id.'-'.$assignee.'"')
        ->assertSeeHtml('data-tip="Remove assignee"')
        ->call('removeProgramTaskAssignee', $task->id, $assignee)
        ->assertDontSeeHtml('data-test="task-assignee-remove-'.$task->id.'-'.$assignee.'"');

    expect($task->taskAssignees()->count())->toBe(0);
});

test('a single page of a person\'s tasks has no footer line under it', function () {
    ['program' => $program, 'project' => $project, 'manager' => $manager, 'employeeId' => $employeeId] = programTaskSetup();
    $task = projectTask($project);
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $employeeId]);

    Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->assertSeeHtml('class="dk-ptk-foot"')
        ->set('taskGroupBy', 'assignee')
        ->call('pickTaskBucket', 'employee-'.$employeeId)
        ->assertDontSeeHtml('class="dk-ptk-foot"');
});

test('a report task row carries the Report badge and opens its report, as on the Tasks page', function () {
    ['program' => $program, 'project' => $project, 'manager' => $manager] = programTaskSetup();
    $report = DonorReport::factory()->create();
    $task = projectTask($project, ['kind' => TaskKind::Report, 'reportable_type' => 'donor_report', 'reportable_id' => $report->id]);

    Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->assertSeeHtml('data-test="report-task-chip"')
        ->assertSeeHtml('href="'.route('donor-reports.show', $report->id).'"');
});

test('a task row carries the unread dot only while the task has unread notifications', function () {
    ['program' => $program, 'project' => $project, 'manager' => $manager] = programTaskSetup();
    $flagged = projectTask($project);
    $quiet = projectTask($project);
    $manager->notify(new AppNotification(new NotificationPayload(
        kind: NotificationKind::Action, module: 'tasks', priority: NotificationPriority::Info,
        key: 'notifications.events.task.created', params: ['actor' => 'Sami', 'subject' => 'x'],
        subjectType: 'task', subjectId: $flagged->id,
    )));

    Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->assertSeeHtml('data-unread-dot="task-'.$flagged->id.'"')
        ->assertDontSeeHtml('data-unread-dot="task-'.$quiet->id.'"');
});

test('the task menu carries the Desk scope so its icons keep their line style', function () {
    ['program' => $program, 'project' => $project, 'manager' => $manager] = programTaskSetup();
    projectTask($project);

    Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->assertSeeHtml('class="dk-scope dk-ptk-menu"')
        ->assertSee('This cannot be undone.');
});

test('a task starts a subtask from the + beside its title, not from the ⋯ menu', function () {
    ['program' => $program, 'project' => $project, 'manager' => $manager] = programTaskSetup();
    $parent = projectTask($project);
    $subtask = projectTask($project, ['parent_task_id' => $parent->id]);

    Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->assertSeeHtml('data-test="task-add-subtask-'.$parent->id.'"')
        ->assertDontSeeHtml('data-test="task-add-subtask-'.$subtask->id.'"')
        ->assertDontSeeHtml('data-test="task-more-subtask"');
});

test('a task title line shows its subtask count and the hover buttons the viewer may use', function () {
    ['program' => $program, 'project' => $project, 'manager' => $manager] = programTaskSetup();
    $parent = projectTask($project);
    projectTask($project, ['parent_task_id' => $parent->id]);
    projectTask($project, ['parent_task_id' => $parent->id]);

    Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->assertSeeHtml('data-task-subtasks="'.$parent->id.'"')
        ->assertSeeHtml('data-test="task-subtask-count-'.$parent->id.'"')
        ->assertSeeHtml('data-test="task-rename-'.$parent->id.'"');
});

test('a task is renamed in place, and a blank name is ignored', function () {
    ['program' => $program, 'project' => $project, 'manager' => $manager] = programTaskSetup();
    $task = projectTask($project, ['title' => 'Old name']);

    $page = Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->call('renameProgramTask', $task->id, '   ');

    expect($task->fresh()->title)->toBe('Old name');

    $page->call('renameProgramTask', $task->id, '  New name  ');

    expect($task->fresh()->title)->toBe('New name');
});

test('someone who cannot edit a task cannot rename it', function () {
    ['program' => $program, 'project' => $project] = programTaskSetup();
    $task = projectTask($project, ['title' => 'Kept name']);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::programs.show', ['program' => $program])
        ->call('renameProgramTask', $task->id, 'Taken over');

    expect($task->fresh()->title)->toBe('Kept name');
});

test('a milestone task names its milestone under the title', function () {
    ['program' => $program, 'milestone' => $milestone, 'manager' => $manager] = programTaskSetup();
    $task = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id]);

    Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->assertSeeHtml('data-task-milestone="'.$task->id.'"')
        ->assertSeeHtml('title="Milestone: Pumps installed"');
});

test('the task menu no longer offers to move a task', function () {
    ['program' => $program, 'project' => $project, 'manager' => $manager] = programTaskSetup();
    projectTask($project);

    Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->assertDontSeeHtml('data-test="task-more-move"')
        ->assertDontSeeHtml('data-move-target=');
});

test('the Add task dialog shows where the task goes, starting on the project being viewed', function () {
    ['program' => $program, 'project' => $project, 'manager' => $manager] = programTaskSetup();

    Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->call('pickTaskBucket', 'project-'.$project->id)
        ->call('openAddTaskModal')
        ->assertSet('newTaskTarget', 'project:'.$project->id)
        ->assertSeeHtml('data-test="new-program-task-goes-to"')
        ->set('newTaskTitle', 'Order spare pumps')
        ->call('addTask')
        ->assertHasNoErrors();

    expect(Task::query()->where('title', 'Order spare pumps')->sole()->only(['source_type', 'source_id']))
        ->toBe(['source_type' => 'project', 'source_id' => $project->id]);
});

test('the Add task dialog sends the task to the picked milestone, and never outside the program', function () {
    ['program' => $program, 'milestone' => $milestone, 'manager' => $manager] = programTaskSetup();
    $foreignMilestone = Milestone::factory()->create();

    $page = Livewire::actingAs($manager)
        ->test('pages::programs.show', ['program' => $program])
        ->call('openAddTaskModal')
        ->assertSet('newTaskTarget', 'program')
        ->set('newTaskTitle', 'Test the pumps')
        ->set('newTaskTarget', 'milestone:'.$foreignMilestone->id)
        ->call('addTask')
        ->assertHasErrors(['newTaskTarget']);

    expect(Task::query()->where('title', 'Test the pumps')->exists())->toBeFalse();

    $page->set('newTaskTarget', 'milestone:'.$milestone->id)
        ->call('addTask')
        ->assertHasNoErrors();

    expect(Task::query()->where('title', 'Test the pumps')->sole()->only(['source_type', 'source_id']))
        ->toBe(['source_type' => 'milestone', 'source_id' => $milestone->id]);
});
