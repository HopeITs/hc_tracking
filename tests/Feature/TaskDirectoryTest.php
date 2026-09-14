<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskUpdate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from the task directory', function () {
    $this->get(route('tasks.index'))->assertRedirect(route('login'));
});

test('authenticated users can view the task directory', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('tasks.index'))->assertOk()->assertSeeText('Tasks');
});

test('tasks can be filtered by search status priority and assignee', function () {
    $user = User::factory()->create();
    $alice = User::factory()->create(['name' => 'Alice Nadir']);
    $bob = User::factory()->create(['name' => 'Bob Rahman']);

    Task::factory()->create([
        'title' => 'Draft distribution plan',
        'description' => 'Prepare the delivery sequence.',
        'assigned_to' => $alice->id,
        'status' => TaskStatus::Todo,
        'priority' => TaskPriority::High,
    ]);
    Task::factory()->create([
        'title' => 'Verify beneficiary list',
        'description' => 'Check every registration.',
        'assigned_to' => $bob->id,
        'status' => TaskStatus::Done,
        'priority' => TaskPriority::Low,
    ]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->assertSee('Draft distribution plan')->assertSee('Verify beneficiary list')
        ->set('search', 'delivery sequence')->assertSee('Draft distribution plan')->assertDontSee('Verify beneficiary list')
        ->call('clearFilters')->set('search', 'Bob Rahman')->assertDontSee('Draft distribution plan')->assertSee('Verify beneficiary list')
        ->call('clearFilters')->set('status', TaskStatus::Done->value)->assertDontSee('Draft distribution plan')->assertSee('Verify beneficiary list')
        ->call('clearFilters')->set('priority', TaskPriority::High->value)->assertSee('Draft distribution plan')->assertDontSee('Verify beneficiary list')
        ->call('clearFilters')->set('assignee', (string) $bob->id)->assertDontSee('Draft distribution plan')->assertSee('Verify beneficiary list')
        ->call('clearFilters')
        ->assertSet('search', '')->assertSet('status', '')->assertSet('priority', '')->assertSet('assignee', '')->assertSet('milestone', '')
        ->assertSee('Draft distribution plan')->assertSee('Verify beneficiary list');
});

test('tasks can be filtered by a milestone or the explicit no milestone state', function () {
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create(['name' => 'First distribution']);

    Task::factory()->create(['title' => 'Attached task', 'milestone_id' => $milestone->id]);
    Task::factory()->create(['title' => 'Loose task', 'milestone_id' => null]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->assertSee('Attached task')->assertSee('Loose task')
        ->set('milestone', (string) $milestone->id)->assertSee('Attached task')->assertDontSee('Loose task')
        ->set('milestone', 'none')->assertDontSee('Attached task')->assertSee('Loose task')->assertSee('No milestone');
});

test('a task records its creator and safely normalizes optional values', function () {
    $creator = User::factory()->create();
    $assignee = User::factory()->create();
    $proposal = Proposal::factory()->create();

    Livewire::actingAs($creator)
        ->test('pages::tasks.index')
        ->call('openCreateModal')
        ->set('title', '  Prepare the sitrep  ')
        ->set('description', '   ')
        ->set('taskProposalId', (string) $proposal->id)
        ->set('assignedTo', (string) $assignee->id)
        ->set('taskStatus', TaskStatus::Todo->value)
        ->set('taskPriority', TaskPriority::Medium->value)
        ->set('startDate', '')
        ->set('dueDate', '2026-03-15')
        ->set('completedDate', '')
        ->set('taskMilestoneId', '')
        ->call('saveTask')
        ->assertHasNoErrors()
        ->assertSet('showTaskModal', false);

    $task = Task::query()->where('title', 'Prepare the sitrep')->sole();

    expect($task->created_by)->toBe($creator->id)
        ->and($task->assigned_to)->toBe($assignee->id)
        ->and($task->proposal_id)->toBe($proposal->id)
        ->and($task->milestone_id)->toBeNull()
        ->and($task->description)->toBeNull()
        ->and($task->start_date)->toBeNull()
        ->and($task->due_date?->toDateString())->toBe('2026-03-15')
        ->and($task->completed_date)->toBeNull();
});

test('creating a task rejects missing and invalid required selections', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('openCreateModal')
        ->set('title', '   ')
        ->set('taskProposalId', '999999')
        ->set('assignedTo', '999999')
        ->set('taskMilestoneId', '999999')
        ->call('saveTask')
        ->assertHasErrors(['title', 'taskProposalId', 'assignedTo', 'taskMilestoneId']);

    expect(Task::query()->count())->toBe(0);
});

test('creating a task rejects invalid workflow values and progress', function (string $property, string $value) {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('openCreateModal')
        ->set('title', 'Valid title')
        ->set('taskProposalId', (string) Proposal::factory()->create()->id)
        ->set('assignedTo', (string) User::factory()->create()->id)
        ->set($property, $value)
        ->call('saveTask')
        ->assertHasErrors([$property]);

    expect(Task::query()->count())->toBe(0);
})->with([
    'invalid status' => ['taskStatus', 'DONE'],
    'invalid priority' => ['taskPriority', 'urgent'],
    'negative progress' => ['progressPercentage', '-1'],
    'progress over one hundred' => ['progressPercentage', '101'],
]);

test('a due date before the start date is rejected', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('openCreateModal')
        ->set('title', 'Schedule delivery')
        ->set('taskProposalId', (string) Proposal::factory()->create()->id)
        ->set('assignedTo', (string) User::factory()->create()->id)
        ->set('startDate', '2026-03-15')
        ->set('dueDate', '2026-03-14')
        ->call('saveTask')
        ->assertHasErrors(['dueDate']);

    expect(Task::query()->count())->toBe(0);
});

test('a task can be fully edited without changing its creator', function () {
    $creator = User::factory()->create();
    $editor = User::factory()->create();
    $newAssignee = User::factory()->create();
    $newProposal = Proposal::factory()->create();
    $newMilestone = Milestone::factory()->create();
    $task = Task::factory()->create([
        'created_by' => $creator->id,
        'title' => 'Original',
        'milestone_id' => null,
        'description' => null,
    ]);

    Livewire::actingAs($editor)
        ->test('pages::tasks.index')
        ->call('openEditModal', $task->id)
        ->assertSet('title', 'Original')->assertSet('description', '')->assertSet('taskMilestoneId', '')
        ->set('title', '  Revised task  ')
        ->set('description', '  Revised scope.  ')
        ->set('taskStatus', TaskStatus::InProgress->value)
        ->set('taskPriority', TaskPriority::High->value)
        ->set('startDate', '2026-04-01')
        ->set('dueDate', '2026-04-30')
        ->set('completedDate', '2026-04-29')
        ->set('progressPercentage', '80')
        ->set('assignedTo', (string) $newAssignee->id)
        ->set('taskProposalId', (string) $newProposal->id)
        ->set('taskMilestoneId', (string) $newMilestone->id)
        ->call('saveTask')
        ->assertHasNoErrors();

    $task->refresh();

    expect($task->title)->toBe('Revised task')
        ->and($task->description)->toBe('Revised scope.')
        ->and($task->status)->toBe(TaskStatus::InProgress)
        ->and($task->priority)->toBe(TaskPriority::High)
        ->and($task->progress_percentage)->toBe(80)
        ->and($task->assigned_to)->toBe($newAssignee->id)
        ->and($task->proposal_id)->toBe($newProposal->id)
        ->and($task->milestone_id)->toBe($newMilestone->id)
        ->and($task->created_by)->toBe($creator->id);
});

test('a task can be deleted and takes only its own updates and attachments with it', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $otherTask = Task::factory()->create();
    $update = TaskUpdate::factory()->create(['task_id' => $task->id]);
    $attachment = TaskAttachment::factory()->create(['task_id' => $task->id]);
    $otherUpdate = TaskUpdate::factory()->create(['task_id' => $otherTask->id]);
    $otherAttachment = TaskAttachment::factory()->create(['task_id' => $otherTask->id]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('confirmDelete', $task->id)
        ->assertSet('deletingTaskId', $task->id)
        ->call('deleteTask')
        ->assertHasNoErrors()
        ->assertSet('deletingTaskId', null);

    expect(DB::table('tasks')->find($task->id))->toBeNull()
        ->and(DB::table('task_updates')->find($update->id))->toBeNull()
        ->and(DB::table('task_attachments')->find($attachment->id))->toBeNull()
        ->and(DB::table('tasks')->find($otherTask->id))->not->toBeNull()
        ->and(DB::table('task_updates')->find($otherUpdate->id))->not->toBeNull()
        ->and(DB::table('task_attachments')->find($otherAttachment->id))->not->toBeNull();
});

test('delete is a no-op until a task is confirmed', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();

    Livewire::actingAs($user)->test('pages::tasks.index')->call('deleteTask')->assertHasNoErrors();

    $this->assertDatabaseHas('tasks', ['id' => $task->id]);
});

test('task rows link to the registered task detail route', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['title' => 'Open my details']);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->assertSeeHtml('href="'.route('tasks.show', $task).'"');
});

test('task directory content is escaped', function () {
    $user = User::factory()->create();
    $assignee = User::factory()->create(['name' => '<script>assignee()</script>']);
    $proposal = Proposal::factory()->create(['name' => '<img src=x onerror=proposal()>']);
    $milestone = Milestone::factory()->create(['name' => '<svg onload=milestone()>']);
    Task::factory()->create([
        'title' => '<script>task()</script>',
        'assigned_to' => $assignee->id,
        'proposal_id' => $proposal->id,
        'milestone_id' => $milestone->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->assertSee('<script>task()</script>')
        ->assertSee('<script>assignee()</script>')
        ->assertSee('<img src=x onerror=proposal()>')
        ->assertSee('<svg onload=milestone()>')
        ->assertDontSeeHtml('<script>task()</script>')
        ->assertDontSeeHtml('<script>assignee()</script>')
        ->assertDontSeeHtml('<img src=x onerror=proposal()>')
        ->assertDontSeeHtml('<svg onload=milestone()>');
});

test('the task directory renders with no tasks', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::tasks.index')->assertSee('No tasks yet');
});
