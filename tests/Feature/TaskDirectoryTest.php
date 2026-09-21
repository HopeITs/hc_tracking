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
    $alice = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $bob = insertHrEmployee(firstNameEn: 'Bob', lastNameEn: 'Rahman');

    Task::factory()->create([
        'title' => 'Draft distribution plan',
        'description' => 'Prepare the delivery sequence.',
        'assigned_to' => $alice,
        'status' => TaskStatus::Todo,
        'priority' => TaskPriority::High,
    ]);
    Task::factory()->create([
        'title' => 'Verify beneficiary list',
        'description' => 'Check every registration.',
        'assigned_to' => $bob,
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
        ->call('clearFilters')->set('assignee', (string) $bob)->assertDontSee('Draft distribution plan')->assertSee('Verify beneficiary list')
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
    $assignee = insertHrEmployee();
    $proposal = Proposal::factory()->create();

    Livewire::actingAs($creator)
        ->test('pages::tasks.index')
        ->call('openCreateDrawer')
        ->set('title', '  Prepare the sitrep  ')
        ->set('description', '   ')
        ->set('taskProposalId', (string) $proposal->id)
        ->set('assignedTo', (string) $assignee)
        ->set('taskStatus', TaskStatus::Todo->value)
        ->set('taskPriority', TaskPriority::Medium->value)
        ->set('startDate', '')
        ->set('dueDate', '2026-03-15')
        ->set('completedDate', '')
        ->set('taskMilestoneId', '')
        ->call('saveTask')
        ->assertHasNoErrors()
        ->assertSet('showTaskDrawer', false);

    $task = Task::query()->where('title', 'Prepare the sitrep')->sole();

    expect($task->created_by)->toBe($creator->id)
        ->and($task->assigned_to)->toBe($assignee)
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
        ->call('openCreateDrawer')
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
        ->call('openCreateDrawer')
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
        ->call('openCreateDrawer')
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
    $newAssignee = insertHrEmployee();
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
        ->call('openEditDrawer', $task->id)
        ->assertSet('title', 'Original')->assertSet('description', '')->assertSet('taskMilestoneId', '')
        ->set('title', '  Revised task  ')
        ->set('description', '  Revised scope.  ')
        ->set('taskStatus', TaskStatus::InProgress->value)
        ->set('taskPriority', TaskPriority::High->value)
        ->set('startDate', '2026-04-01')
        ->set('dueDate', '2026-04-30')
        ->set('completedDate', '2026-04-29')
        ->set('progressPercentage', '80')
        ->set('assignedTo', (string) $newAssignee)
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
        ->and($task->assigned_to)->toBe($newAssignee)
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
        ->assertSet('showDeleteModal', true)
        ->call('deleteTask')
        ->assertHasNoErrors()
        ->assertSet('deletingTaskId', null)
        ->assertSet('showDeleteModal', false);

    expect(DB::table('tasks')->find($task->id))->toBeNull()
        ->and(DB::table('task_updates')->find($update->id))->toBeNull()
        ->and(DB::table('task_attachments')->find($attachment->id))->toBeNull()
        ->and(DB::table('tasks')->find($otherTask->id))->not->toBeNull()
        ->and(DB::table('task_updates')->find($otherUpdate->id))->not->toBeNull()
        ->and(DB::table('task_attachments')->find($otherAttachment->id))->not->toBeNull();
});

test('the delete confirmation can be cancelled without deleting the task', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('confirmDelete', $task->id)
        ->assertSet('showDeleteModal', true)
        ->call('cancelDelete')
        ->assertSet('showDeleteModal', false)
        ->assertSet('deletingTaskId', null);

    $this->assertDatabaseHas('tasks', ['id' => $task->id]);
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
    $assignee = insertHrEmployee(firstNameEn: '<script>assignee()</script>', lastNameEn: '');
    $proposal = Proposal::factory()->create(['name' => '<img src=x onerror=proposal()>']);
    $milestone = Milestone::factory()->create(['name' => '<svg onload=milestone()>']);
    Task::factory()->create([
        'title' => '<script>task()</script>',
        'assigned_to' => $assignee,
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

test('a task can be duplicated with a reset status and progress', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create([
        'title' => 'Deliver hygiene kits',
        'status' => TaskStatus::Done,
        'progress_percentage' => 100,
        'completed_date' => '2026-02-01',
    ]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('duplicateTask', $task->id)
        ->assertHasNoErrors();

    $copy = Task::query()->where('title', 'Deliver hygiene kits (copy)')->sole();

    expect($copy->id)->not->toBe($task->id)
        ->and($copy->status)->toBe(TaskStatus::Todo)
        ->and($copy->progress_percentage)->toBe(0)
        ->and($copy->completed_date)->toBeNull()
        ->and($copy->created_by)->toBe($user->id)
        ->and(Task::query()->count())->toBe(2);
});

test('toggling completion marks a task done and back to todo', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['status' => TaskStatus::InProgress, 'progress_percentage' => 40, 'completed_date' => null]);

    Livewire::actingAs($user)->test('pages::tasks.index')->call('toggleComplete', $task->id);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Done)
        ->and($task->progress_percentage)->toBe(100)
        ->and($task->completed_date?->toDateString())->toBe(now()->toDateString());

    Livewire::actingAs($user)->test('pages::tasks.index')->call('toggleComplete', $task->id);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Todo)
        ->and($task->completed_date)->toBeNull();
});

test('clicking a column header sorts by it ascending, then toggles descending on a second click', function () {
    $user = User::factory()->create();
    Task::factory()->create(['title' => 'Bravo task']);
    Task::factory()->create(['title' => 'Alpha task']);

    $component = Livewire::actingAs($user)->test('pages::tasks.index');

    $component->call('setSort', 'title')->assertSet('sortField', 'title')->assertSet('sortDirection', 'asc');
    expect($component->get('tasks')->pluck('title')->all())->toBe(['Alpha task', 'Bravo task']);

    $component->call('setSort', 'title')->assertSet('sortField', 'title')->assertSet('sortDirection', 'desc');
    expect($component->get('tasks')->pluck('title')->all())->toBe(['Bravo task', 'Alpha task']);

    // Switching to a different column resets to a predictable ascending default.
    $component->call('setSort', 'priority')->assertSet('sortField', 'priority')->assertSet('sortDirection', 'asc');
});

test('sorting by priority and status follows workflow severity order, not alphabetical order', function () {
    $user = User::factory()->create();
    Task::factory()->create(['title' => 'Low priority task', 'priority' => TaskPriority::Low, 'status' => TaskStatus::UnderReview]);
    Task::factory()->create(['title' => 'High priority task', 'priority' => TaskPriority::High, 'status' => TaskStatus::Todo]);

    $component = Livewire::actingAs($user)->test('pages::tasks.index');

    $component->call('setSort', 'priority');
    expect($component->get('tasks')->pluck('title')->all())->toBe(['Low priority task', 'High priority task']);

    $component->call('setSort', 'priority'); // desc
    expect($component->get('tasks')->pluck('title')->all())->toBe(['High priority task', 'Low priority task']);

    $component->call('setSort', 'status');
    expect($component->get('tasks')->pluck('title')->all())->toBe(['High priority task', 'Low priority task']); // todo before under review
});

test('sorting by assignee orders by employee name even though it lives on the read-only hr connection', function () {
    $user = User::factory()->create();
    $zoe = insertHrEmployee(firstNameEn: 'Zoe', lastNameEn: 'Youssef');
    $amir = insertHrEmployee(firstNameEn: 'Amir', lastNameEn: 'Nasser');
    Task::factory()->create(['title' => 'Zoe task', 'assigned_to' => $zoe]);
    Task::factory()->create(['title' => 'Amir task', 'assigned_to' => $amir]);

    $component = Livewire::actingAs($user)->test('pages::tasks.index')->call('setSort', 'assignee');

    expect($component->get('tasks')->pluck('title')->all())->toBe(['Amir task', 'Zoe task']);
});

test('sorting by created date orders newest or oldest first', function () {
    $user = User::factory()->create();
    Task::factory()->create(['title' => 'Older task', 'created_at' => now()->subDay()]);
    Task::factory()->create(['title' => 'Newer task', 'created_at' => now()]);

    $component = Livewire::actingAs($user)->test('pages::tasks.index')->call('setSort', 'created');

    expect($component->get('tasks')->pluck('title')->all())->toBe(['Older task', 'Newer task']);

    $component->call('setSort', 'created');
    expect($component->get('tasks')->pluck('title')->all())->toBe(['Newer task', 'Older task']);
});

test('an unrecognized sort field is ignored and leaves the current sort untouched', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('setSort', 'not-a-real-field')
        ->assertSet('sortField', 'due')
        ->assertSet('sortDirection', 'asc');
});

test('sorting stays server-side and correct across paginated pages', function () {
    $user = User::factory()->create();
    Task::factory()->count(25)->sequence(fn ($sequence) => ['title' => sprintf('Task %02d', $sequence->index)])->create();

    $component = Livewire::actingAs($user)->test('pages::tasks.index')->call('setSort', 'title');

    $firstPageTitles = $component->get('tasks')->pluck('title')->all();
    expect($firstPageTitles)->toBe(collect($firstPageTitles)->sort(SORT_STRING)->values()->all());

    $component->call('nextPage');
    $secondPageTitles = $component->get('tasks')->pluck('title')->all();

    // Every title on page two must sort after every title on page one —
    // proving the database did the ordering, not just the visible page.
    expect(max($firstPageTitles))->toBeLessThan(min($secondPageTitles));
});

test('the list view computes stat tiles with one aggregate query instead of loading every matching task', function () {
    $user = User::factory()->create();
    Task::factory()->count(10)->create();

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::actingAs($user)->test('pages::tasks.index');

    $taskTableQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'tasks'));

    DB::disableQueryLog();

    // paginate() itself issues a count query plus the page's select query;
    // the stat tiles add one aggregate select on top of that — three total,
    // not a second full (board-sized) fetch of every task with its relations
    // just to count a handful of numbers.
    expect($taskTableQueries)->toHaveCount(3);
});

test('the list view groups tasks by status with a colored collapsible header', function () {
    $user = User::factory()->create();
    Task::factory()->create(['title' => 'Grouped todo task', 'status' => TaskStatus::Todo]);
    Task::factory()->create(['title' => 'Grouped done task', 'status' => TaskStatus::Done]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('group', 'status')
        ->assertSee('Grouped todo task')
        ->assertSee('Grouped done task')
        ->assertSee('data-test="task-quick-add-status-todo"', false)
        ->assertSee('data-test="task-quick-add-status-done"', false);
});

test('quickSetAssignee reassigns a task to a valid hr employee', function () {
    $user = User::factory()->create();
    $newAssignee = insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Saleh');
    $task = Task::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('quickSetAssignee', $task->id, $newAssignee)
        ->assertHasNoErrors();

    expect($task->refresh()->assigned_to)->toBe($newAssignee);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('quickSetAssignee', $task->id, 999999);

    expect($task->refresh()->assigned_to)->toBe($newAssignee);
});

test('quickCreateTask opens the drawer pre-filled with the title and the group context', function () {
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('quickCreateTask', '  Draft the report  ', TaskStatus::UnderReview->value, null)
        ->assertSet('showTaskDrawer', true)
        ->assertSet('title', 'Draft the report')
        ->assertSet('taskStatus', TaskStatus::UnderReview->value);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('quickCreateTask', 'Another task', null, (string) $milestone->id)
        ->assertSet('showTaskDrawer', true)
        ->assertSet('taskMilestoneId', (string) $milestone->id);

    expect(Task::query()->count())->toBe(0);
});

test('quickCreateTask does nothing for a blank title', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('quickCreateTask', '   ')
        ->assertSet('showTaskDrawer', false);
});

test('dropping a task into a different board column persists the status change', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['status' => TaskStatus::Todo]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'board')
        ->call('quickSetStatus', $task->id, TaskStatus::InProgress->value)
        ->assertHasNoErrors()
        ->assertSee('data-test="task-board-card-'.$task->id.'"', false);

    expect($task->refresh()->status)->toBe(TaskStatus::InProgress);
});

test('dropping a task that no longer exists reports the failure instead of failing silently', function () {
    $user = User::factory()->create();
    $deletedTask = Task::factory()->create();
    $deletedId = $deletedTask->id;
    $deletedTask->delete();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'board')
        ->call('quickSetStatus', $deletedId, TaskStatus::Done->value)
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');
});

test('the board view offers a keyboard accessible select as a non pointer alternative to dragging', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['status' => TaskStatus::Todo]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'board')
        ->assertSee('data-test="task-quick-status-'.$task->id.'"', false)
        ->assertSee('wire:change="quickSetStatus('.$task->id.', $event.target.value)"', false);
});

test('the board view shows a subtask count badge on parent cards', function () {
    $user = User::factory()->create();
    $parent = Task::factory()->create(['title' => 'Parent with subtasks']);
    Task::factory()->subtaskOf($parent)->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'board')
        ->assertSee('data-test="task-board-card-'.$parent->id.'"', false);
});

test('a task with subtasks cannot be deleted until they are moved or removed', function () {
    $user = User::factory()->create();
    $parent = Task::factory()->create();
    $subtask = Task::factory()->subtaskOf($parent)->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('confirmDelete', $parent->id)
        ->call('deleteTask')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    $this->assertDatabaseHas('tasks', ['id' => $parent->id]);
    $this->assertDatabaseHas('tasks', ['id' => $subtask->id]);
});

test('a task with no subtasks deletes normally', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('confirmDelete', $task->id)
        ->call('deleteTask')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
});

test('quickSetStatus moves a task and syncs its completion fields', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['status' => TaskStatus::Todo, 'completed_date' => null]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('quickSetStatus', $task->id, TaskStatus::Done->value)
        ->assertHasNoErrors();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Done)
        ->and($task->completed_date)->not->toBeNull();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('quickSetStatus', $task->id, 'not-a-real-status');

    expect($task->refresh()->status)->toBe(TaskStatus::Done);
});

test('the calendar view places tasks on their due date and lists unscheduled tasks separately', function () {
    $user = User::factory()->create();
    Task::factory()->create(['title' => 'Scheduled task', 'due_date' => now()->startOfMonth()->addDays(2)->toDateString()]);
    Task::factory()->create(['title' => 'Unscheduled task', 'due_date' => null]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'calendar')
        ->assertSee('Scheduled task')
        ->assertSee('Unscheduled task')
        ->assertSee('data-test="task-calendar-unscheduled"', false);
});

test('rescheduleTask sets or clears a due date from calendar drag and drop', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['due_date' => null]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('rescheduleTask', $task->id, '2026-05-10')
        ->assertHasNoErrors();

    expect($task->refresh()->due_date?->toDateString())->toBe('2026-05-10');

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('rescheduleTask', $task->id, '');

    expect($task->refresh()->due_date)->toBeNull();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('rescheduleTask', $task->id, 'not-a-date');

    expect($task->refresh()->due_date)->toBeNull();
});
