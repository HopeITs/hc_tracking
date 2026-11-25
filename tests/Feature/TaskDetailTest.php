<?php

use App\Enums\FileType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Donor;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

test('guests are redirected from task detail', function () {
    $task = Task::factory()->create();

    $this->get(route('tasks.show', $task))->assertRedirect(route('login'));
});

test('missing tasks return not found from task detail', function () {
    $user = User::factory()->role('General Manager')->create();

    $this->actingAs($user)->get(route('tasks.show', 999999))->assertNotFound();
});

test('task detail shows the task project assignees and creator', function () {
    $user = User::factory()->role('General Manager')->create();
    $assignee = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $creator = User::factory()->create(['name' => 'Bob Rahman']);
    $project = Project::factory()->create(['name' => 'Winter Support']);
    $task = Task::factory()->create([
        'source_type' => 'project',
        'source_id' => $project->id,
        'title' => 'Draft distribution plan',
        'created_by' => $creator->id,
        'start_date' => '2026-09-01',
        'due_date' => '2026-09-30',
        'progress_percentage' => 35,
    ]);
    $task->taskAssignees()->create(['employee_id' => $assignee]);

    $this->actingAs($user)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertSeeText('Draft distribution plan')
        ->assertSeeText('Winter Support')
        ->assertSeeText('Alice Nadir')
        ->assertSeeText('Bob Rahman')
        ->assertDontSeeText('35%');
});

test('task detail shows the task proposal as its source when it is raised against a proposal', function () {
    $user = User::factory()->role('General Manager')->create();
    $proposal = Proposal::factory()->create(['name' => 'Emergency Response']);
    $task = Task::factory()->create([
        'source_type' => 'proposal',
        'source_id' => $proposal->id,
        'title' => 'Draft distribution plan',
    ]);

    $this->actingAs($user)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertSeeText('Emergency Response');
});

test('task detail lists every assignee when a task has more than one', function () {
    $user = User::factory()->role('General Manager')->create();
    $alice = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $bob = insertHrEmployee(firstNameEn: 'Bob', lastNameEn: 'Karam');
    $task = Task::factory()->create();
    $task->taskAssignees()->create(['employee_id' => $alice]);
    $task->taskAssignees()->create(['employee_id' => $bob]);

    $this->actingAs($user)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertSeeText('Alice Nadir')
        ->assertSeeText('Bob Karam');
});

test('attachments are listed as metadata without exposing storage or controls', function () {
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create();
    $otherTask = Task::factory()->create();
    TaskAttachment::factory()->create([
        'task_id' => $task->id,
        'path' => 'task-attachments/private-distribution-plan.pdf',
        'type' => FileType::Pdf,
        'date' => '2026-09-10',
    ]);
    TaskAttachment::factory()->create([
        'task_id' => $otherTask->id,
        'path' => 'task-attachments/other-task.xlsx',
        'type' => FileType::Xlsx,
    ]);

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->assertSee('private-distribution-plan.pdf')
        ->assertSee('PDF')
        ->assertSee('Sep 10, 2026')
        ->assertDontSee('other-task.xlsx')
        ->assertDontSee('task-attachments/private-distribution-plan.pdf')
        ->assertDontSee('download', false)
        ->assertDontSee('upload', false)
        ->assertDontSee('href="task-attachments/private-distribution-plan.pdf"', false);
});

test('task detail escapes all user controlled content', function () {
    $user = User::factory()->role('General Manager')->create();
    $assignee = insertHrEmployee(firstNameEn: '<script>assignee()</script>', lastNameEn: '');
    $creator = User::factory()->create(['name' => '<img src=x onerror=creator()>']);
    $proposal = Proposal::factory()->create(['name' => '<svg onload=proposal()>']);
    $task = Task::factory()->create([
        'title' => '<script>task()</script>',
        'description' => '<img src=x onerror=description()>',
        'created_by' => $creator->id,
        'source_type' => 'proposal',
        'source_id' => $proposal->id,
    ]);
    $task->taskAssignees()->create(['employee_id' => $assignee]);
    TaskAttachment::factory()->create([
        'task_id' => $task->id,
        'path' => 'task-attachments/<img src=x onerror=attachment()>.pdf',
        'type' => FileType::Pdf,
    ]);

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->assertSee('<script>task()</script>')
        ->assertSee('<img src=x onerror=description()>')
        ->assertSee('<script>assignee()</script>')
        ->assertSee('<img src=x onerror=creator()>')
        ->assertSee('<svg onload=proposal()>')
        ->assertSee('<img src=x onerror=attachment()>.pdf')
        ->assertDontSeeHtml('<script>task()</script>')
        ->assertDontSeeHtml('<img src=x onerror=description()>')
        ->assertDontSeeHtml('<script>assignee()</script>')
        ->assertDontSeeHtml('<img src=x onerror=creator()>')
        ->assertDontSeeHtml('<svg onload=proposal()>')
        ->assertDontSeeHtml('<img src=x onerror=attachment()>.pdf');
});

test('task detail renders a task with no attachments and no project link when the source is a proposal', function () {
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create(['source_type' => 'proposal', 'source_id' => Proposal::factory()->create()->id, 'description' => null]);

    $this->actingAs($user)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertSeeText('No files recorded');
});

test('a subtask can be added from the task detail page via the add subtask modal and inherits the parent source', function () {
    $user = User::factory()->role('General Manager')->create();
    $sourceProject = Project::factory()->create();
    $assignee = insertHrEmployee();
    $parent = Task::factory()->create(['source_type' => 'project', 'source_id' => $sourceProject->id]);
    $parent->taskAssignees()->delete();
    $parent->taskAssignees()->create(['employee_id' => $assignee]);

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $parent])
        ->call('openAddSubtaskModal')
        ->assertSet('newSubtaskAssignedTo', [(string) $assignee])
        ->set('newSubtaskTitle', '  Confirm the delivery window  ')
        ->set('newSubtaskStatus', TaskStatus::InProgress->value)
        ->set('newSubtaskDueDate', '2026-10-01')
        ->call('addSubtask')
        ->assertHasNoErrors()
        ->assertSet('showAddSubtaskModal', false)
        ->assertSee('Confirm the delivery window');

    $subtask = Task::query()->where('title', 'Confirm the delivery window')->sole();

    expect($subtask->parent_task_id)->toBe($parent->id)
        ->and($subtask->source_type)->toBe('project')
        ->and($subtask->source_id)->toBe($sourceProject->id)
        ->and($subtask->taskAssignees->pluck('employee_id')->all())->toBe([$assignee])
        ->and($subtask->status)->toBe(TaskStatus::InProgress)
        ->and($subtask->due_date->toDateString())->toBe('2026-10-01')
        ->and($subtask->created_by)->toBe($user->id);
});

test('adding a subtask with a blank title is rejected', function () {
    $user = User::factory()->role('General Manager')->create();
    $parent = Task::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $parent])
        ->call('openAddSubtaskModal')
        ->set('newSubtaskTitle', '   ')
        ->call('addSubtask')
        ->assertHasErrors(['newSubtaskTitle' => 'required']);

    expect(Task::query()->where('parent_task_id', $parent->id)->count())->toBe(0);
});

test('a subtask added without an assignee is left unassigned', function () {
    $user = User::factory()->role('General Manager')->create();
    $parent = Task::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $parent])
        ->call('openAddSubtaskModal')
        ->set('newSubtaskTitle', 'Confirm the delivery window')
        ->set('newSubtaskAssignedTo', [])
        ->call('addSubtask')
        ->assertHasNoErrors();

    $subtask = Task::query()->where('parent_task_id', $parent->id)->sole();

    expect($subtask->taskAssignees()->count())->toBe(0);
});

test('subtasks persist and remain visible after navigating back to the task', function () {
    $user = User::factory()->role('General Manager')->create();
    $parent = Task::factory()->create();
    Task::factory()->subtaskOf($parent)->create(['title' => 'Existing subtask']);

    $this->actingAs($user)
        ->get(route('tasks.show', $parent))
        ->assertOk()
        ->assertSeeText('Existing subtask');

    $this->actingAs($user)
        ->get(route('tasks.show', $parent))
        ->assertOk()
        ->assertSeeText('Existing subtask');
});

test('the related tasks panel lists a subtask without inline editing controls', function () {
    $user = User::factory()->role('General Manager')->create();
    $parent = Task::factory()->create();
    $subtask = Task::factory()->subtaskOf($parent)->create([
        'title' => 'Confirm the delivery window',
        'status' => TaskStatus::InProgress,
    ]);

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $parent])
        ->assertSeeHtml('data-test="task-subtasks-panel"')
        ->assertSeeText('0/1')
        ->assertSeeText('Confirm the delivery window')
        ->assertDontSee('data-test="subtask-toggle-complete-'.$subtask->id.'"', false)
        ->assertDontSee('data-test="subtask-edit-button-'.$subtask->id.'"', false);
});

test('a subtask panel does not appear on a subtask s own detail page', function () {
    $user = User::factory()->role('General Manager')->create();
    $parent = Task::factory()->create();
    $subtask = Task::factory()->subtaskOf($parent)->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $subtask])
        ->assertDontSee('Add subtask')
        ->assertSeeText('Subtask of');
});

test('a task can be edited from its own detail page', function () {
    $user = User::factory()->role('General Manager')->create();
    $newAssignee = insertHrEmployee(firstNameEn: 'Nadia', lastNameEn: 'Younes');
    $task = Task::factory()->create([
        'title' => 'Draft distribution plan',
        'status' => TaskStatus::Todo,
    ]);

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->set('title', '  Finalize distribution plan  ')
        ->set('taskStatus', TaskStatus::InProgress->value)
        ->set('progressPercentage', '40')
        ->set('assignedTo', [(string) $newAssignee])
        ->call('updateTask')
        ->assertHasNoErrors();

    $task->refresh();

    expect($task->title)->toBe('Finalize distribution plan')
        ->and($task->status)->toBe(TaskStatus::InProgress)
        ->and($task->progress_percentage)->toBe(40)
        ->and($task->taskAssignees->pluck('employee_id')->all())->toBe([$newAssignee]);
});

test('editing a task can remove every assignee', function () {
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->set('assignedTo', [])
        ->call('updateTask')
        ->assertHasNoErrors();

    expect($task->taskAssignees()->count())->toBe(0);
});

test('a subtask is edited from its own detail page, not from the parent', function () {
    $user = User::factory()->role('General Manager')->create();
    $newAssignee = insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Saleh');
    $parent = Task::factory()->create();
    $subtask = Task::factory()->subtaskOf($parent)->create([
        'title' => 'Confirm the delivery window',
        'status' => TaskStatus::Todo,
    ]);

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $subtask])
        ->set('title', '  Confirm the final delivery window  ')
        ->set('taskStatus', TaskStatus::Done->value)
        ->set('progressPercentage', '100')
        ->set('assignedTo', [(string) $newAssignee])
        ->call('updateTask')
        ->assertHasNoErrors();

    $subtask->refresh();

    expect($subtask->title)->toBe('Confirm the final delivery window')
        ->and($subtask->status)->toBe(TaskStatus::Done)
        ->and($subtask->taskAssignees->pluck('employee_id')->all())->toBe([$newAssignee]);
});

test('the add subtask form is rendered only while its modal is open', function () {
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->assertDontSeeHtml('data-test="new-subtask-title-input"')
        ->call('openAddSubtaskModal')
        ->assertSeeHtml('data-test="new-subtask-title-input"');
});

test('the properties column saves a priority change in place', function () {
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create(['priority' => TaskPriority::Low]);

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->assertSeeHtml('data-test="task-properties"')
        ->set('taskPriority', TaskPriority::High->value)
        ->assertHasNoErrors();

    expect($task->fresh()->priority)->toBe(TaskPriority::High);
});

test('a refused in-place date change puts the field back and keeps the stored date', function () {
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create(['created_by' => $user->id, 'start_date' => '2026-09-10', 'due_date' => '2026-09-30']);

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->set('dueDate', '2026-09-01')
        ->assertHasErrors(['dueDate'])
        ->assertSet('dueDate', '2026-09-30');

    expect($task->fresh()->due_date->toDateString())->toBe('2026-09-30');
});

test('only the task creator moves the due date in place', function () {
    $creator = User::factory()->role('General Manager')->create();
    $editor = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create(['created_by' => $creator->id, 'due_date' => '2026-09-30']);

    Livewire::actingAs($editor)
        ->test('pages::tasks.show', ['task' => $task])
        ->assertDontSeeHtml('data-test="task-edit-due-date-input"')
        ->assertSeeHtml('data-test="task-edit-start-date-input"')
        ->set('dueDate', '2026-10-15')
        ->assertSet('dueDate', '2026-09-30');

    expect($task->fresh()->due_date->toDateString())->toBe('2026-09-30');

    Livewire::actingAs($creator)
        ->test('pages::tasks.show', ['task' => $task])
        ->assertSeeHtml('data-test="task-edit-due-date-input"')
        ->set('dueDate', '2026-10-15')
        ->assertHasNoErrors();

    expect($task->fresh()->due_date->toDateString())->toBe('2026-10-15');
});

test('a status picked in the properties column is saved at once', function () {
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create(['status' => TaskStatus::Todo]);

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->call('changeStatus', TaskStatus::InProgress->value)
        ->assertHasNoErrors();

    expect($task->fresh()->status)->toBe(TaskStatus::InProgress);
});

test('sending an Under review task back waits for the approver note', function () {
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create(['created_by' => $user->id, 'status' => TaskStatus::UnderReview]);

    $page = Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->call('changeStatus', TaskStatus::InProgress->value)
        ->assertSeeHtml('data-test="task-send-back-form"');

    expect($task->fresh()->status)->toBe(TaskStatus::UnderReview);

    $page->call('sendBack')->assertHasErrors(['taskStatus']);

    expect($task->fresh()->status)->toBe(TaskStatus::UnderReview);

    $page->call('cancelStatusChange')
        ->assertSet('taskStatus', TaskStatus::UnderReview->value)
        ->assertDontSeeHtml('data-test="task-send-back-form"')
        ->call('changeStatus', TaskStatus::InProgress->value)
        ->set('reviewNote', 'Attach the signed list')
        ->call('sendBack')
        ->assertHasNoErrors();

    expect($task->fresh()->status)->toBe(TaskStatus::InProgress);
});

test('the title and description are edited in place from the description panel', function () {
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create(['title' => 'Draft plan', 'description' => null]);
    Role::create(['name' => 'Task Reader', 'guard_name' => 'web'])->givePermissionTo('tasks.view');

    Livewire::actingAs(User::factory()->role('Task Reader')->create())
        ->test('pages::tasks.show', ['task' => $task])
        ->assertDontSeeHtml('data-test="task-description"');

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->assertDontSeeHtml('data-test="task-no-description"')
        ->assertSeeHtml('data-test="task-details-form"')
        ->set('title', 'Final plan')
        ->set('description', 'Cover every district.')
        ->call('saveDetails')
        ->assertHasNoErrors()
        ->assertDispatched('task-details-saved')
        ->assertSee('Cover every district.');

    expect($task->fresh())
        ->title->toBe('Final plan')
        ->description->toBe('Cover every district.');
});

test('someone who may only view a task reads every property as text', function () {
    Role::create(['name' => 'Task Viewer', 'guard_name' => 'web'])->givePermissionTo('tasks.view');
    $viewer = User::factory()->role('Task Viewer')->create();
    $assignee = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $task = Task::factory()->create([
        'status' => TaskStatus::InProgress,
        'start_date' => '2026-09-01',
        'due_date' => '2026-09-30',
        'progress_percentage' => 35,
    ]);
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $assignee]);

    Livewire::actingAs($viewer)
        ->test('pages::tasks.show', ['task' => $task])
        ->assertSeeText('Sep 1, 2026')
        ->assertSeeText('Sep 30, 2026')
        ->assertSeeHtml('data-test="task-assignee-names"')
        ->assertSeeHtml('data-test="task-priority-text"')
        ->assertSeeHtml('data-test="task-evidence-required-text"')
        ->assertDontSeeHtml('data-record-activity')
        ->assertSeeText('Alice Nadir')
        ->assertSeeHtml('data-test="task-related-to"')
        ->assertDontSeeHtml('data-test="task-edit-form"')
        ->assertDontSeeHtml('data-test="task-edit-status-done"')
        ->assertDontSeeHtml('data-test="task-edit-priority-high"')
        ->assertDontSeeHtml('data-test="task-edit-assignee-picker"')
        ->assertDontSeeHtml('data-test="task-edit-start-date-input"')
        ->assertDontSeeHtml('data-test="task-details-form"');
});

test('a task with no subtasks shows no subtask panel, and subtasks are added from the more menu', function () {
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->assertDontSeeHtml('data-test="task-subtasks-panel"')
        ->assertSeeHtml('data-test="add-subtask-button"');
});

test('moving an unstarted task to In progress sets its start date to today', function () {
    $this->travelTo('2026-11-16 09:00');
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create(['status' => TaskStatus::Todo, 'start_date' => null, 'due_date' => null]);

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->call('changeStatus', TaskStatus::InProgress->value)
        ->assertHasNoErrors()
        ->assertSet('startDate', '2026-11-16');

    expect($task->fresh()->start_date->toDateString())->toBe('2026-11-16');
});

test('starting work keeps a start date that is already set', function () {
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create(['status' => TaskStatus::Todo, 'start_date' => '2026-09-01', 'due_date' => null]);

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->call('changeStatus', TaskStatus::InProgress->value)
        ->assertHasNoErrors();

    expect($task->fresh()->start_date->toDateString())->toBe('2026-09-01');
});

test('the creator sees the activity history and the next step to approve a task under review', function () {
    $creator = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create(['created_by' => $creator->id, 'status' => TaskStatus::UnderReview]);

    Livewire::actingAs($creator)
        ->test('pages::tasks.show', ['task' => $task])
        ->assertSeeHtml('data-record-activity')
        ->assertSeeText('Approve and mark done')
        ->assertDontSeeHtml('data-test="task-edit-status-done" disabled');
});

test('the creator turns proof needed on from the details switch and it saves at once', function () {
    $creator = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create(['created_by' => $creator->id, 'evidence_required' => false]);

    Livewire::actingAs($creator)
        ->test('pages::tasks.show', ['task' => $task])
        ->assertSeeHtml('data-test="task-evidence-required"')
        ->toggle('evidenceRequired')
        ->assertHasNoErrors()
        ->assertSeeHtml('aria-checked="true"');

    expect($task->fresh()->evidence_required)->toBeTrue();
});

test('an editor who did not create the task reads proof needed as text and cannot change it', function () {
    $creator = User::factory()->role('General Manager')->create();
    $editor = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create(['created_by' => $creator->id, 'evidence_required' => true]);

    Livewire::actingAs($editor)
        ->test('pages::tasks.show', ['task' => $task])
        ->assertSeeHtml('data-test="task-evidence-required-text"')
        ->assertDontSeeHtml('data-test="task-evidence-required"')
        ->set('evidenceRequired', false)
        ->assertSet('evidenceRequired', true);

    expect($task->fresh()->evidence_required)->toBeTrue();
});

test('the activity history is only built once the creator opens it', function () {
    $creator = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create(['created_by' => $creator->id]);

    Livewire::actingAs($creator)
        ->test('pages::tasks.show', ['task' => $task])
        ->assertSeeHtml('data-test="task-activity-toggle"')
        ->assertDontSeeHtml('id="task-activity-body"')
        ->toggle('showActivity')
        ->assertSeeHtml('id="task-activity-body"');
});

test('an editor removes one assignee with the x on their chip', function () {
    $creator = User::factory()->role('General Manager')->create();
    $kept = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $removed = insertHrEmployee(firstNameEn: 'Bob', lastNameEn: 'Karam');
    $task = Task::factory()->create(['created_by' => $creator->id]);
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $kept]);
    $task->taskAssignees()->create(['employee_id' => $removed]);

    Livewire::actingAs($creator)
        ->test('pages::tasks.show', ['task' => $task])
        ->assertSeeHtml('data-test="task-remove-assignee-'.$removed.'"')
        ->call('removeAssignee', $removed)
        ->assertHasNoErrors()
        ->assertSet('assignedTo', [(string) $kept]);

    expect($task->taskAssignees()->pluck('employee_id')->all())->toBe([$kept]);
});

test('someone who may not edit the task sees no remove x and cannot drop an assignee', function () {
    Role::create(['name' => 'Task Viewer', 'guard_name' => 'web'])->givePermissionTo('tasks.view');
    $viewer = User::factory()->role('Task Viewer')->create();
    $assignee = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $task = Task::factory()->create();
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $assignee]);

    Livewire::actingAs($viewer)
        ->test('pages::tasks.show', ['task' => $task])
        ->assertDontSeeHtml('data-test="task-remove-assignee-'.$assignee.'"')
        ->call('removeAssignee', $assignee)
        ->assertForbidden();

    expect($task->taskAssignees()->pluck('employee_id')->all())->toBe([$assignee]);
});

test('a proposal task shows its donor and dates in the related-to card', function () {
    $user = User::factory()->role('General Manager')->create();
    $donor = Donor::factory()->create(['name' => 'Aid Fund']);
    $proposal = Proposal::factory()->create([
        'name' => 'Emergency Response',
        'donor_id' => $donor->id,
        'planned_date_begin' => '2026-03-01',
        'planned_date_end' => '2027-02-28',
    ]);
    $task = Task::factory()->create(['source_type' => 'proposal', 'source_id' => $proposal->id]);

    $this->actingAs($user)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertSeeHtml('data-test="task-related-card"')
        ->assertSeeText('Aid Fund')
        ->assertSeeText(CarbonImmutable::parse('2026-03-01')->translatedFormat(__('formats.date')))
        ->assertSeeText(CarbonImmutable::parse('2027-02-28')->translatedFormat(__('formats.date')));
});

test('a project task shows its dates and program in the related-to card', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create(['name' => 'Winter Support', 'start_date' => '2026-01-05', 'end_date' => '2026-12-20']);
    // Y-16 (D-93): a delivery task is raised at a place, which carries the dates.
    $row = rowFor($project);
    $task = Task::factory()->create(['source_type' => 'proposal_location', 'source_id' => $row->id]);

    $this->actingAs($user)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertSeeText(__('tasks.related.program'))
        ->assertSeeText(CarbonImmutable::parse('2026-12-20')->translatedFormat(__('formats.date')));
});

test('the approver returns an Under review task to To do with a reason the assignee sees', function () {
    $creator = User::factory()->role('General Manager')->create();
    $worker = userWithRole('Team Member', insertHrEmployee());
    $task = Task::factory()->create(['created_by' => $creator->id, 'status' => TaskStatus::UnderReview]);
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $worker->employee_id]);

    Livewire::actingAs($creator)
        ->test('pages::tasks.show', ['task' => $task])
        ->assertSeeHtml('data-test="task-return-button"')
        ->call('startReturn')
        ->assertSet('taskStatus', TaskStatus::Todo->value)
        ->assertSeeHtml('data-test="task-send-back-form"')
        ->call('sendBack')
        ->assertHasErrors(['taskStatus'])
        ->set('reviewNote', 'The signed list is missing')
        ->call('sendBack')
        ->assertHasNoErrors();

    expect($task->fresh())
        ->status->toBe(TaskStatus::Todo)
        ->returned_reason->toBe('The signed list is missing');

    Livewire::actingAs($worker)
        ->test('pages::tasks.show', ['task' => $task])
        ->assertSeeHtml('data-test="task-returned-reason"')
        ->assertSeeText('The signed list is missing')
        ->assertDontSeeHtml('data-test="task-return-button"');

    expect(collect(sentencesFor($worker))->filter(fn (string $line): bool => str_contains($line, 'The signed list is missing')))->toHaveCount(1);
});

test('approving a returned task clears its return reason', function () {
    $creator = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create(['created_by' => $creator->id, 'status' => TaskStatus::UnderReview, 'returned_reason' => 'Fix totals']);

    Livewire::actingAs($creator)
        ->test('pages::tasks.show', ['task' => $task])
        ->call('changeStatus', TaskStatus::Done->value)
        ->assertHasNoErrors();

    expect($task->fresh())->status->toBe(TaskStatus::Done)->returned_reason->toBeNull();
});

test('only the approver of an Under review task can open the return box', function () {
    $creator = User::factory()->role('General Manager')->create();
    $worker = userWithRole('Team Member', insertHrEmployee());
    $task = Task::factory()->create(['created_by' => $creator->id, 'status' => TaskStatus::UnderReview]);
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $worker->employee_id]);

    Livewire::actingAs($worker)
        ->test('pages::tasks.show', ['task' => $task])
        ->call('startReturn')
        ->assertSet('taskStatus', TaskStatus::UnderReview->value);
});
