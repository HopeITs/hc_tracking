<?php

use App\Enums\FileType;
use App\Enums\TaskStatus;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskUpdate;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

test('guests are redirected from task detail', function () {
    $task = Task::factory()->create();

    $this->get(route('tasks.show', $task))->assertRedirect(route('login'));
});

test('missing tasks return not found from task detail', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('tasks.show', 999999))->assertNotFound();
});

test('task detail shows the task proposal milestone assignee and creator', function () {
    $user = User::factory()->create();
    $assignee = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $creator = User::factory()->create(['name' => 'Bob Rahman']);
    $project = Project::factory()->create(['name' => 'Winter Support']);
    $milestone = Milestone::factory()->create([
        'project_id' => $project->id,
        'name' => 'Distribution ready',
    ]);
    $proposal = Proposal::factory()->create(['name' => 'Emergency Response']);
    $task = Task::factory()->create([
        'milestone_id' => $milestone->id,
        'proposal_id' => $proposal->id,
        'title' => 'Draft distribution plan',
        'assigned_to' => $assignee,
        'created_by' => $creator->id,
        'start_date' => '2026-09-01',
        'due_date' => '2026-09-30',
        'progress_percentage' => 35,
    ]);

    $this->actingAs($user)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertSeeText('Draft distribution plan')
        ->assertSeeText('Emergency Response')
        ->assertSeeText('Distribution ready')
        ->assertSeeText('Winter Support')
        ->assertSeeText('Alice Nadir')
        ->assertSeeText('Bob Rahman')
        ->assertSeeText('35%')
        ->assertSeeText('Sep 1, 2026')
        ->assertSeeText('Sep 30, 2026');
});

test('task detail shows only its own updates newest first', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $otherTask = Task::factory()->create();

    TaskUpdate::factory()->create(['task_id' => $task->id, 'comment' => 'Mine first', 'date' => '2026-09-10 08:00:00']);
    TaskUpdate::factory()->create(['task_id' => $task->id, 'comment' => 'Mine second', 'date' => '2026-09-11 08:00:00']);
    TaskUpdate::factory()->create(['task_id' => $otherTask->id, 'comment' => 'Not mine']);

    $this->actingAs($user)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertSeeText('Mine second')
        ->assertSeeText('Mine first')
        ->assertDontSeeText('Not mine')
        ->assertSeeTextInOrder(['Mine second', 'Mine first']);
});

test('updates with the same date are ordered newest first by id', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();

    TaskUpdate::factory()->create(['task_id' => $task->id, 'comment' => 'Lower id', 'date' => '2026-09-11 08:00:00']);
    TaskUpdate::factory()->create(['task_id' => $task->id, 'comment' => 'Higher id', 'date' => '2026-09-11 08:00:00']);

    $this->actingAs($user)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertSeeTextInOrder(['Higher id', 'Lower id']);
});

test('an update records the authenticated user as its author after trimming', function () {
    $author = User::factory()->create();
    $task = Task::factory()->create();

    $this->freezeTime(function () use ($author, $task): void {
        Livewire::actingAs($author)
            ->test('pages::tasks.show', ['task' => $task])
            ->set('comment', '  Distribution list verified with the field team.  ')
            ->call('addUpdate')
            ->assertHasNoErrors()
            ->assertSet('comment', '')
            ->assertSee('Distribution list verified with the field team.');

        $this->assertDatabaseHas('task_updates', [
            'task_id' => $task->id,
            'user_id' => $author->id,
            'comment' => 'Distribution list verified with the field team.',
            'date' => now()->format('Y-m-d H:i:s'),
        ]);
    });
});

test('an empty update is rejected with a useful message', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->set('comment', '   ')
        ->call('addUpdate')
        ->assertHasErrors(['comment' => 'required'])
        ->assertSee('Write an update before posting it.');

    $this->assertDatabaseMissing('task_updates', ['task_id' => $task->id]);
});

test('an update longer than the supported limit is rejected', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->set('comment', str_repeat('a', 5001))
        ->call('addUpdate')
        ->assertHasErrors(['comment' => 'max']);

    $this->assertDatabaseMissing('task_updates', ['task_id' => $task->id]);
});

test('the task id cannot be changed from the client before posting an update', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $otherTask = Task::factory()->create();

    expect(fn () => Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->set('taskId', $otherTask->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    $this->assertDatabaseMissing('task_updates', ['task_id' => $task->id]);
    $this->assertDatabaseMissing('task_updates', ['task_id' => $otherTask->id]);
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
        'assigned_to' => $assignee,
        'created_by' => $creator->id,
        'proposal_id' => $proposal->id,
    ]);
    TaskUpdate::factory()->create([
        'task_id' => $task->id,
        'user_id' => $creator->id,
        'comment' => '<script>update()</script>',
    ]);
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
        ->assertSee('<script>update()</script>')
        ->assertSee('<img src=x onerror=attachment()>.pdf')
        ->assertDontSeeHtml('<script>task()</script>')
        ->assertDontSeeHtml('<img src=x onerror=description()>')
        ->assertDontSeeHtml('<script>assignee()</script>')
        ->assertDontSeeHtml('<img src=x onerror=creator()>')
        ->assertDontSeeHtml('<svg onload=proposal()>')
        ->assertDontSeeHtml('<script>update()</script>')
        ->assertDontSeeHtml('<img src=x onerror=attachment()>.pdf');
});

test('task detail renders a task without a milestone updates or attachments', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['milestone_id' => null, 'description' => null]);

    $this->actingAs($user)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertSeeText('No milestone')
        ->assertSeeText('No updates yet')
        ->assertSeeText('No files recorded');
});

test('a subtask can be added from the task detail page and inherits the parent proposal milestone and assignee', function () {
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create();
    $assignee = insertHrEmployee();
    $parent = Task::factory()->create(['milestone_id' => $milestone->id, 'assigned_to' => $assignee]);

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $parent])
        ->set('newSubtaskTitle', '  Confirm the delivery window  ')
        ->call('addSubtask')
        ->assertHasNoErrors()
        ->assertSet('newSubtaskTitle', '')
        ->assertSee('Confirm the delivery window');

    $subtask = Task::query()->where('title', 'Confirm the delivery window')->sole();

    expect($subtask->parent_task_id)->toBe($parent->id)
        ->and($subtask->proposal_id)->toBe($parent->proposal_id)
        ->and($subtask->milestone_id)->toBe($milestone->id)
        ->and($subtask->assigned_to)->toBe($assignee)
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
