<?php

use App\Enums\FileType;
use App\Enums\TaskStatus;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from task detail', function () {
    $task = Task::factory()->create();

    $this->get(route('tasks.show', $task))->assertRedirect(route('login'));
});

test('missing tasks return not found from task detail', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('tasks.show', 999999))->assertNotFound();
});

test('task detail shows the task milestone project assignees and creator', function () {
    $user = User::factory()->create();
    $assignee = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $creator = User::factory()->create(['name' => 'Bob Rahman']);
    $project = Project::factory()->create(['name' => 'Winter Support']);
    $milestone = Milestone::factory()->create([
        'project_id' => $project->id,
        'name' => 'Distribution ready',
    ]);
    $task = Task::factory()->create([
        'source_type' => 'milestone',
        'source_id' => $milestone->id,
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
        ->assertSeeText('Distribution ready')
        ->assertSeeText('Winter Support')
        ->assertSeeText('Alice Nadir')
        ->assertSeeText('Bob Rahman')
        ->assertSeeText('35%')
        ->assertSeeText('Sep 1, 2026')
        ->assertSeeText('Sep 30, 2026');
});

test('task detail shows the task proposal as its source when it is not raised against a milestone', function () {
    $user = User::factory()->create();
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
    $user = User::factory()->create();
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
    $user = User::factory()->create();
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
    $user = User::factory()->create();
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

test('task detail renders a task with no attachments and no project link when the source is not a milestone', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['source_type' => 'proposal', 'source_id' => Proposal::factory()->create()->id, 'description' => null]);

    $this->actingAs($user)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertSeeText('No files recorded');
});

test('a subtask can be added from the task detail page and inherits the parent source and assignees', function () {
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create();
    $assignee = insertHrEmployee();
    $parent = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id]);
    $parent->taskAssignees()->delete();
    $parent->taskAssignees()->create(['employee_id' => $assignee]);

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $parent])
        ->set('newSubtaskTitle', '  Confirm the delivery window  ')
        ->call('addSubtask')
        ->assertHasNoErrors()
        ->assertSet('newSubtaskTitle', '')
        ->assertSee('Confirm the delivery window');

    $subtask = Task::query()->where('title', 'Confirm the delivery window')->sole();

    expect($subtask->parent_task_id)->toBe($parent->id)
        ->and($subtask->source_type)->toBe('milestone')
        ->and($subtask->source_id)->toBe($milestone->id)
        ->and($subtask->taskAssignees->pluck('employee_id')->all())->toBe([$assignee])
        ->and($subtask->status)->toBe(TaskStatus::Todo)
        ->and($subtask->created_by)->toBe($user->id);
});

test('adding a subtask with a blank title is rejected', function () {
    $user = User::factory()->create();
    $parent = Task::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $parent])
        ->set('newSubtaskTitle', '   ')
        ->call('addSubtask')
        ->assertHasErrors(['newSubtaskTitle' => 'required']);

    expect(Task::query()->where('parent_task_id', $parent->id)->count())->toBe(0);
});

test('subtasks persist and remain visible after navigating back to the task', function () {
    $user = User::factory()->create();
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

test('a subtask can be toggled complete from its parent task page', function () {
    $user = User::factory()->create();
    $parent = Task::factory()->create();
    $subtask = Task::factory()->subtaskOf($parent)->create(['status' => TaskStatus::Todo, 'progress_percentage' => 0]);

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $parent])
        ->call('toggleSubtaskComplete', $subtask->id);

    expect($subtask->refresh()->status)->toBe(TaskStatus::Done)
        ->and($subtask->progress_percentage)->toBe(100);

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $parent])
        ->call('toggleSubtaskComplete', $subtask->id);

    expect($subtask->refresh()->status)->toBe(TaskStatus::Todo);
});

test('toggling a task that is not a subtask of this parent is a no-op', function () {
    $user = User::factory()->create();
    $parent = Task::factory()->create();
    $unrelated = Task::factory()->create(['status' => TaskStatus::Todo]);

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $parent])
        ->call('toggleSubtaskComplete', $unrelated->id);

    expect($unrelated->refresh()->status)->toBe(TaskStatus::Todo);
});

test('a subtask panel does not appear on a subtask s own detail page', function () {
    $user = User::factory()->create();
    $parent = Task::factory()->create();
    $subtask = Task::factory()->subtaskOf($parent)->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $subtask])
        ->assertDontSee('Add subtask')
        ->assertSeeText('Subtask of');
});
