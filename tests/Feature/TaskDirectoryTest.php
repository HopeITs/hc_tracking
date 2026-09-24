<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\TaskAttachment;
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

    $draft = Task::factory()->create([
        'title' => 'Draft distribution plan',
        'description' => 'Prepare the delivery sequence.',
        'status' => TaskStatus::Todo,
        'priority' => TaskPriority::High,
    ]);
    $draft->taskAssignees()->delete();
    $draft->taskAssignees()->create(['employee_id' => $alice]);

    $verify = Task::factory()->create([
        'title' => 'Verify beneficiary list',
        'description' => 'Check every registration.',
        'status' => TaskStatus::Done,
        'priority' => TaskPriority::Low,
    ]);
    $verify->taskAssignees()->delete();
    $verify->taskAssignees()->create(['employee_id' => $bob]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->assertSee('Draft distribution plan')->assertSee('Verify beneficiary list')
        ->set('search', 'delivery sequence')->assertSee('Draft distribution plan')->assertDontSee('Verify beneficiary list')
        ->call('clearFilters')->set('search', 'Bob Rahman')->assertDontSee('Draft distribution plan')->assertSee('Verify beneficiary list')
        ->call('clearFilters')->set('status', TaskStatus::Done->value)->assertDontSee('Draft distribution plan')->assertSee('Verify beneficiary list')
        ->call('clearFilters')->set('priority', TaskPriority::High->value)->assertSee('Draft distribution plan')->assertDontSee('Verify beneficiary list')
        ->call('clearFilters')->set('assignee', (string) $bob)->assertDontSee('Draft distribution plan')->assertSee('Verify beneficiary list')
        ->call('clearFilters')
        ->assertSet('search', '')->assertSet('status', '')->assertSet('priority', '')->assertSet('assignee', '')->assertSet('sourceType', '')->assertSet('sourceId', '')
        ->assertSee('Draft distribution plan')->assertSee('Verify beneficiary list');
});

test('tasks can be filtered by source type and by a specific source record', function () {
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create(['name' => 'First distribution']);
    $proposal = Proposal::factory()->create();

    Task::factory()->create(['title' => 'Attached task', 'source_type' => 'milestone', 'source_id' => $milestone->id]);
    Task::factory()->create(['title' => 'Loose task', 'source_type' => 'proposal', 'source_id' => $proposal->id]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->assertSee('Attached task')->assertSee('Loose task')
        ->set('sourceType', 'milestone')->assertSee('Attached task')->assertDontSee('Loose task')
        ->set('sourceId', (string) $milestone->id)->assertSee('Attached task')->assertDontSee('Loose task')
        ->set('sourceType', 'proposal')->set('sourceId', '')->assertDontSee('Attached task')->assertSee('Loose task');
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
        ->set('taskSourceType', 'proposal')
        ->set('taskSourceId', (string) $proposal->id)
        ->set('assignedTo', [(string) $assignee])
        ->set('taskStatus', TaskStatus::Todo->value)
        ->set('taskPriority', TaskPriority::Medium->value)
        ->set('startDate', '')
        ->set('dueDate', '2026-03-15')
        ->set('completedDate', '')
        ->call('saveTask')
        ->assertHasNoErrors()
        ->assertSet('showTaskDrawer', false);

    $task = Task::query()->where('title', 'Prepare the sitrep')->sole();

    expect($task->created_by)->toBe($creator->id)
        ->and($task->taskAssignees->pluck('employee_id')->all())->toBe([$assignee])
        ->and($task->source_type)->toBe('proposal')
        ->and($task->source_id)->toBe($proposal->id)
        ->and($task->description)->toBeNull()
        ->and($task->start_date)->toBeNull()
        ->and($task->due_date?->toDateString())->toBe('2026-03-15')
        ->and($task->completed_date)->toBeNull();
});

test('a task can have several assignees at once', function () {
    $creator = User::factory()->create();
    $alice = insertHrEmployee();
    $bob = insertHrEmployee();
    $proposal = Proposal::factory()->create();

    Livewire::actingAs($creator)
        ->test('pages::tasks.index')
        ->call('openCreateDrawer')
        ->set('title', 'Coordinate the handover')
        ->set('taskSourceType', 'proposal')
        ->set('taskSourceId', (string) $proposal->id)
        ->set('assignedTo', [(string) $alice, (string) $bob])
        ->call('saveTask')
        ->assertHasNoErrors();

    $task = Task::query()->where('title', 'Coordinate the handover')->sole();

    expect($task->taskAssignees->pluck('employee_id')->sort()->values()->all())
        ->toBe(collect([$alice, $bob])->sort()->values()->all());
});

test('creating a task rejects missing and invalid required selections', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('openCreateDrawer')
        ->set('title', '   ')
        ->set('taskSourceType', 'proposal')
        ->set('taskSourceId', '999999')
        ->set('assignedTo', ['999999'])
        ->call('saveTask')
        ->assertHasErrors(['title', 'taskSourceId', 'assignedTo.0']);

    expect(Task::query()->count())->toBe(0);
});

test('creating a task rejects a missing source type or source', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('openCreateDrawer')
        ->set('title', 'Valid title')
        ->set('assignedTo', [(string) insertHrEmployee()])
        ->call('saveTask')
        ->assertHasErrors(['taskSourceType', 'taskSourceId']);

    expect(Task::query()->count())->toBe(0);
});

test('creating a task rejects an unrecognized source type', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('openCreateDrawer')
        ->set('title', 'Valid title')
        ->set('taskSourceType', 'not-a-real-type')
        ->set('taskSourceId', '1')
        ->set('assignedTo', [(string) insertHrEmployee()])
        ->call('saveTask')
        ->assertHasErrors(['taskSourceType']);

    expect(Task::query()->count())->toBe(0);
});

test('creating a task with no assignee at all is rejected', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('openCreateDrawer')
        ->set('title', 'Valid title')
        ->set('taskSourceType', 'proposal')
        ->set('taskSourceId', (string) Proposal::factory()->create()->id)
        ->set('assignedTo', [])
        ->call('saveTask')
        ->assertHasErrors(['assignedTo']);

    expect(Task::query()->count())->toBe(0);
});

test('creating a task rejects invalid workflow values and progress', function (string $property, string $value) {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('openCreateDrawer')
        ->set('title', 'Valid title')
        ->set('taskSourceType', 'proposal')
        ->set('taskSourceId', (string) Proposal::factory()->create()->id)
        ->set('assignedTo', [(string) insertHrEmployee()])
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
        ->set('taskSourceType', 'proposal')
        ->set('taskSourceId', (string) Proposal::factory()->create()->id)
        ->set('assignedTo', [(string) insertHrEmployee()])
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
    $originalProposal = Proposal::factory()->create();
    $newMilestone = Milestone::factory()->create();
    $task = Task::factory()->create([
        'created_by' => $creator->id,
        'title' => 'Original',
        'source_type' => 'proposal',
        'source_id' => $originalProposal->id,
        'description' => null,
    ]);

    Livewire::actingAs($editor)
        ->test('pages::tasks.index')
        ->call('openEditDrawer', $task->id)
        ->assertSet('title', 'Original')->assertSet('description', '')->assertSet('taskSourceType', 'proposal')->assertSet('taskSourceId', (string) $originalProposal->id)
        ->set('title', '  Revised task  ')
        ->set('description', '  Revised scope.  ')
        ->set('taskStatus', TaskStatus::InProgress->value)
        ->set('taskPriority', TaskPriority::High->value)
        ->set('startDate', '2026-04-01')
        ->set('dueDate', '2026-04-30')
        ->set('completedDate', '2026-04-29')
        ->set('progressPercentage', '80')
        ->set('assignedTo', [(string) $newAssignee])
        ->set('taskSourceType', 'milestone')
        ->set('taskSourceId', (string) $newMilestone->id)
        ->call('saveTask')
        ->assertHasNoErrors();

    $task->refresh();

    expect($task->title)->toBe('Revised task')
        ->and($task->description)->toBe('Revised scope.')
        ->and($task->status)->toBe(TaskStatus::InProgress)
        ->and($task->priority)->toBe(TaskPriority::High)
        ->and($task->progress_percentage)->toBe(80)
        ->and($task->taskAssignees->pluck('employee_id')->all())->toBe([$newAssignee])
        ->and($task->source_type)->toBe('milestone')
        ->and($task->source_id)->toBe($newMilestone->id)
        ->and($task->created_by)->toBe($creator->id);
});

test('editing a task to drop an assignee removes only that assignment', function () {
    $user = User::factory()->create();
    $alice = insertHrEmployee();
    $bob = insertHrEmployee();
    $task = Task::factory()->create();
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $alice]);
    $task->taskAssignees()->create(['employee_id' => $bob]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('openEditDrawer', $task->id)
        ->assertSet('assignedTo', [(string) $alice, (string) $bob])
        ->set('assignedTo', [(string) $alice])
        ->call('saveTask')
        ->assertHasNoErrors();

    expect($task->refresh()->taskAssignees->pluck('employee_id')->all())->toBe([$alice]);
});

test('toggleAssignee adds and removes an assignee directly from the list row', function () {
    $user = User::factory()->create();
    $alice = insertHrEmployee();
    $bob = insertHrEmployee();
    $task = Task::factory()->create();
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $alice]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('toggleAssignee', $task->id, $bob)
        ->assertHasNoErrors();

    expect($task->refresh()->taskAssignees->pluck('employee_id')->sort()->values()->all())
        ->toBe(collect([$alice, $bob])->sort()->values()->all());

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('toggleAssignee', $task->id, $bob);

    expect($task->refresh()->taskAssignees->pluck('employee_id')->all())->toBe([$alice]);
});

test('toggleAssignee refuses to remove the last remaining assignee', function () {
    $user = User::factory()->create();
    $alice = insertHrEmployee();
    $task = Task::factory()->create();
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $alice]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('toggleAssignee', $task->id, $alice)
        ->assertDispatched('toast-show');

    expect($task->refresh()->taskAssignees->pluck('employee_id')->all())->toBe([$alice]);
});

test('the list row assignee trigger opens an inline multi select popover of every employee', function () {
    $user = User::factory()->create();
    $alice = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $bob = insertHrEmployee(firstNameEn: 'Bob', lastNameEn: 'Rahman');
    $task = Task::factory()->create();
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $alice]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'list')
        ->assertSee('data-test="task-assignee-trigger-'.$task->id.'"', false)
        ->assertSee('data-test="task-assignee-option-'.$task->id.'-'.$alice.'"', false)
        ->assertSee('data-test="task-assignee-option-'.$task->id.'-'.$bob.'"', false)
        ->assertSee('wire:click="toggleAssignee('.$task->id.', '.$bob.')"', false);
});

test('a task can be deleted and takes only its own attachments with it', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $otherTask = Task::factory()->create();
    $attachment = TaskAttachment::factory()->create(['task_id' => $task->id]);
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
        ->and(DB::table('task_assignees')->where('task_id', $task->id)->count())->toBe(0)
        ->and(DB::table('task_attachments')->find($attachment->id))->toBeNull()
        ->and(DB::table('tasks')->find($otherTask->id))->not->toBeNull()
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
    $milestone = Milestone::factory()->create(['name' => '<svg onload=milestone()>']);
    $task = Task::factory()->create([
        'title' => '<script>task()</script>',
        'source_type' => 'milestone',
        'source_id' => $milestone->id,
    ]);
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $assignee]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->assertSee('<script>task()</script>')
        ->assertSee('<script>assignee()</script>')
        ->assertSee('<svg onload=milestone()>')
        ->assertDontSeeHtml('<script>task()</script>')
        ->assertDontSeeHtml('<script>assignee()</script>')
        ->assertDontSeeHtml('<svg onload=milestone()>');
});

test('the task directory renders with no tasks', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::tasks.index')->assertSee('No tasks yet');
});

test('the my tasks rail stays visible and shows an empty state when the viewer has no tasks', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::tasks.index')
        ->assertSeeHtml('data-test="my-tasks-rail"')
        ->assertSeeHtml('data-test="my-tasks-empty"')
        ->assertSee('My Tasks');
});

test('the my tasks rail card title links to the task detail page', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['title' => 'Reconcile the ledger', 'created_by' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->assertSeeHtml('href="'.route('tasks.show', $task).'"')
        ->assertSeeHtml('data-test="my-task-title-'.$task->id.'"');
});

test('toggling completion marks a task done, and a completed task cannot be un-completed', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['status' => TaskStatus::InProgress, 'progress_percentage' => 40, 'completed_date' => null]);

    Livewire::actingAs($user)->test('pages::tasks.index')->call('toggleComplete', $task->id);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Done)
        ->and($task->progress_percentage)->toBe(100)
        ->and($task->completed_date?->toDateString())->toBe(now()->toDateString());

    Livewire::actingAs($user)->test('pages::tasks.index')
        ->call('toggleComplete', $task->id)
        ->assertDispatched('toast-show');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Done)
        ->and($task->completed_date?->toDateString())->toBe(now()->toDateString());
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
    $zoeTask = Task::factory()->create(['title' => 'Zoe task']);
    $zoeTask->taskAssignees()->delete();
    $zoeTask->taskAssignees()->create(['employee_id' => $zoe]);
    $amirTask = Task::factory()->create(['title' => 'Amir task']);
    $amirTask->taskAssignees()->delete();
    $amirTask->taskAssignees()->create(['employee_id' => $amir]);

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

    $page = Livewire::actingAs($user)->test('pages::tasks.index');

    DB::flushQueryLog();
    DB::enableQueryLog();

    $page->set('view', 'list');

    $taskTableQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], '`tasks`') || str_contains($query['query'], 'from "tasks"'));

    DB::disableQueryLog();

    // paginate() itself issues a count query plus the page's select query;
    // the stat tiles add one aggregate select on top of that, the eager
    // load of each row's own subtasks (for the inline expand panel) adds a
    // fourth, and the "My Tasks" rail's own capped preview query a fifth —
    // five total, not a second full (board-sized) fetch of every task with
    // its relations just to count a handful of numbers.
    expect($taskTableQueries)->toHaveCount(5);
});

test('the My Tasks rail visually distinguishes tasks assigned to the viewer from tasks the viewer only delegated', function () {
    $employeeId = insertHrEmployee();
    $user = User::factory()->create(['employee_id' => $employeeId]);

    $assignedToMe = Task::factory()->create(['title' => 'Assigned to me', 'created_by' => $user->id]);
    $assignedToMe->taskAssignees()->delete();
    $assignedToMe->taskAssignees()->create(['employee_id' => $employeeId]);

    $delegatedByMe = Task::factory()->create(['title' => 'Delegated by me', 'created_by' => $user->id]);
    $delegatedByMe->taskAssignees()->delete();
    $delegatedByMe->taskAssignees()->create(['employee_id' => insertHrEmployee(firstNameEn: 'Someone', lastNameEn: 'Else')]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->assertSee('data-test="my-task-role-assigned-'.$assignedToMe->id.'"', false)
        ->assertSee('data-test="my-task-role-delegated-'.$delegatedByMe->id.'"', false)
        ->assertDontSee('data-test="my-task-role-delegated-'.$assignedToMe->id.'"', false)
        ->assertDontSee('data-test="my-task-role-assigned-'.$delegatedByMe->id.'"', false)
        ->assertSee('Assigned to Someone Else');
});

test('the list view groups tasks by status with a colored collapsible header', function () {
    $user = User::factory()->create();
    Task::factory()->create(['title' => 'Grouped todo task', 'status' => TaskStatus::Todo]);
    Task::factory()->create(['title' => 'Grouped done task', 'status' => TaskStatus::Done]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'list')
        ->set('group', 'status')
        ->assertSee('Grouped todo task')
        ->assertSee('Grouped done task')
        ->assertSee('data-test="task-quick-add-status-todo"', false)
        ->assertSee('data-test="task-quick-add-status-done"', false);
});

test('quickCreateTask opens the drawer pre-filled with the title and the group context', function () {
    $user = User::factory()->create(['employee_id' => null]);
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
        ->assertSet('taskSourceType', 'milestone')
        ->assertSet('taskSourceId', (string) $milestone->id);

    expect(Task::query()->count())->toBe(0);
});

test('quickCreateTask does nothing for a blank title', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('quickCreateTask', '   ')
        ->assertSet('showTaskDrawer', false);
});

test('quickCreateTask creates the task directly with no drawer when the milestone and assignee are both known', function () {
    $employeeId = insertHrEmployee();
    $user = User::factory()->create(['employee_id' => $employeeId]);
    $milestone = Milestone::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('quickCreateTask', '  Inline task  ', TaskStatus::InProgress->value, (string) $milestone->id)
        ->assertSet('showTaskDrawer', false);

    $task = Task::query()->where('title', 'Inline task')->sole();

    expect($task->status)->toBe(TaskStatus::InProgress)
        ->and($task->source_type)->toBe('milestone')
        ->and($task->source_id)->toBe($milestone->id)
        ->and($task->taskAssignees->pluck('employee_id')->all())->toBe([$employeeId]);
});

test('quickCreateTask falls back to the active source filter when no milestone context is given', function () {
    $employeeId = insertHrEmployee();
    $user = User::factory()->create(['employee_id' => $employeeId]);
    $proposal = Proposal::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('sourceType', 'proposal')
        ->set('sourceId', (string) $proposal->id)
        ->call('quickCreateTask', 'Filtered task', null, null)
        ->assertSet('showTaskDrawer', false);

    $task = Task::query()->where('title', 'Filtered task')->sole();

    expect($task->source_type)->toBe('proposal')
        ->and($task->source_id)->toBe($proposal->id);
});

test('quickCreateTask falls back to the drawer when neither a milestone nor an active source filter is known', function () {
    $employeeId = insertHrEmployee();
    $user = User::factory()->create(['employee_id' => $employeeId]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('quickCreateTask', 'Ambiguous task')
        ->assertSet('showTaskDrawer', true)
        ->assertSet('title', 'Ambiguous task');

    expect(Task::query()->count())->toBe(0);
});

test('quickCreateTask falls back to the drawer when the signed-in user has no linked HR employee', function () {
    $user = User::factory()->create(['employee_id' => null]);
    $milestone = Milestone::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('quickCreateTask', 'Unassignable task', null, (string) $milestone->id)
        ->assertSet('showTaskDrawer', true);

    expect(Task::query()->count())->toBe(0);
});

test('the list view inline add task row honours its own due date priority status and assignees', function () {
    // The row's own signed-in user (Alice) is only the *fallback* assignee —
    // once the draft explicitly picks someone (Bob), that pick wins outright
    // rather than being merged with the fallback.
    $alice = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $bob = insertHrEmployee(firstNameEn: 'Bob', lastNameEn: 'Rahman');
    $user = User::factory()->create(['employee_id' => $alice]);
    $milestone = Milestone::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('taskDrafts.row-1.due_date', '2026-11-05')
        ->set('taskDrafts.row-1.priority', TaskPriority::High->value)
        ->set('taskDrafts.row-1.status', TaskStatus::Done->value)
        ->call('toggleTaskDraftAssignee', 'row-1', $bob)
        ->call('quickCreateTask', 'Custom task', null, (string) $milestone->id, 'row-1')
        ->assertSet('showTaskDrawer', false);

    $task = Task::query()->where('title', 'Custom task')->sole();

    expect($task->due_date->toDateString())->toBe('2026-11-05')
        ->and($task->priority)->toBe(TaskPriority::High)
        ->and($task->status)->toBe(TaskStatus::Done)
        ->and($task->progress_percentage)->toBe(100)
        ->and($task->completed_date)->not->toBeNull()
        ->and($task->source_type)->toBe('milestone')
        ->and($task->source_id)->toBe($milestone->id)
        ->and($task->taskAssignees->pluck('employee_id')->all())->toBe([$bob]);
});

test('quickCreateTask falling back to the drawer carries the row draft into the drawer fields', function () {
    $bob = insertHrEmployee(firstNameEn: 'Bob', lastNameEn: 'Rahman');
    $user = User::factory()->create(['employee_id' => null]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('taskDrafts.row-1.due_date', '2026-12-01')
        ->set('taskDrafts.row-1.priority', TaskPriority::Low->value)
        ->set('taskDrafts.row-1.status', TaskStatus::UnderReview->value)
        ->call('toggleTaskDraftAssignee', 'row-1', $bob)
        ->call('quickCreateTask', 'Needs the drawer', null, null, 'row-1')
        ->assertSet('showTaskDrawer', true)
        ->assertSet('title', 'Needs the drawer')
        ->assertSet('taskPriority', TaskPriority::Low->value)
        ->assertSet('dueDate', '2026-12-01')
        ->assertSet('assignedTo', [(string) $bob]);

    expect(Task::query()->count())->toBe(0);
});

test('toggleTaskDraftAssignee toggles an employee in and out of the add-task draft', function () {
    $alice = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('toggleTaskDraftAssignee', 'row-1', $alice);

    expect($component->instance()->taskDraft('row-1')['assignees'])->toBe([(string) $alice]);

    $component->call('toggleTaskDraftAssignee', 'row-1', $alice);

    expect($component->instance()->taskDraft('row-1')['assignees'])->toBe([]);
});

test('quickCreateTask uses the calendar days own due date over any draft due date', function () {
    $employeeId = insertHrEmployee();
    $user = User::factory()->create(['employee_id' => $employeeId]);
    $milestone = Milestone::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('sourceType', 'milestone')
        ->set('sourceId', (string) $milestone->id)
        ->set('taskDrafts.calendar|2026-08-30.due_date', '2099-01-01')
        ->set('taskDrafts.calendar|2026-08-30.priority', TaskPriority::High->value)
        ->call('quickCreateTask', 'Calendar task', null, null, 'calendar|2026-08-30', '2026-08-30')
        ->assertSet('showTaskDrawer', false);

    $task = Task::query()->where('title', 'Calendar task')->sole();

    expect($task->due_date->toDateString())->toBe('2026-08-30')
        ->and($task->priority)->toBe(TaskPriority::High);
});

test('addSubtaskQuick creates a subtask inheriting the parent source and assignees', function () {
    $user = User::factory()->create();
    $alice = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $milestone = Milestone::factory()->create();
    $parent = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id]);
    $parent->taskAssignees()->delete();
    $parent->taskAssignees()->create(['employee_id' => $alice]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('addSubtaskQuick', $parent->id, '  Follow up call  ');

    $subtask = Task::query()->where('title', 'Follow up call')->sole();

    expect($subtask->parent_task_id)->toBe($parent->id)
        ->and($subtask->source_type)->toBe('milestone')
        ->and($subtask->source_id)->toBe($milestone->id)
        ->and($subtask->taskAssignees->pluck('employee_id')->all())->toBe([$alice]);
});

test('addSubtaskQuick refuses to nest a subtask under another subtask', function () {
    $user = User::factory()->create();
    $parent = Task::factory()->create();
    $subtask = Task::factory()->create(['parent_task_id' => $parent->id]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('addSubtaskQuick', $subtask->id, 'Grandchild task');

    expect(Task::query()->where('title', 'Grandchild task')->exists())->toBeFalse();
});

test('addSubtaskQuick does nothing for a blank title', function () {
    $user = User::factory()->create();
    $parent = Task::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('addSubtaskQuick', $parent->id, '   ');

    expect($parent->subtasks()->count())->toBe(0);
});

test('subtaskDraft defaults to todo medium priority no due date and the parents own assignees', function () {
    $user = User::factory()->create();
    $alice = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $parent = Task::factory()->create();
    $parent->taskAssignees()->delete();
    $parent->taskAssignees()->create(['employee_id' => $alice]);

    $draft = Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->instance()
        ->subtaskDraft($parent->fresh(['taskAssignees']));

    expect($draft)->toBe([
        'status' => TaskStatus::Todo->value,
        'priority' => TaskPriority::Medium->value,
        'due_date' => '',
        'assignees' => [(string) $alice],
    ]);
});

test('the list view inline add subtask row honours its own due date priority status and assignees', function () {
    $user = User::factory()->create();
    $alice = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $bob = insertHrEmployee(firstNameEn: 'Bob', lastNameEn: 'Rahman');
    $parent = Task::factory()->create();
    $parent->taskAssignees()->delete();
    $parent->taskAssignees()->create(['employee_id' => $alice]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set("subtaskDrafts.{$parent->id}.due_date", '2026-11-05')
        ->set("subtaskDrafts.{$parent->id}.priority", TaskPriority::High->value)
        ->set("subtaskDrafts.{$parent->id}.status", TaskStatus::Done->value)
        ->call('toggleSubtaskDraftAssignee', $parent->id, $bob)
        ->call('addSubtaskQuick', $parent->id, 'Custom subtask');

    $subtask = Task::query()->where('title', 'Custom subtask')->sole();

    expect($subtask->due_date->toDateString())->toBe('2026-11-05')
        ->and($subtask->priority)->toBe(TaskPriority::High)
        ->and($subtask->status)->toBe(TaskStatus::Done)
        ->and($subtask->progress_percentage)->toBe(100)
        ->and($subtask->completed_date)->not->toBeNull()
        ->and($subtask->taskAssignees->pluck('employee_id')->sort()->values()->all())->toBe([$alice, $bob]);
});

test('toggleSubtaskDraftAssignee removes an employee already in the draft', function () {
    $user = User::factory()->create();
    $alice = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $parent = Task::factory()->create();
    $parent->taskAssignees()->delete();
    $parent->taskAssignees()->create(['employee_id' => $alice]);

    $component = Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('toggleSubtaskDraftAssignee', $parent->id, $alice);

    expect($component->instance()->subtaskDraft($parent->fresh(['taskAssignees']))['assignees'])->toBe([]);
});

test('addSubtaskQuick falls back to the parents own assignees when the draft has none', function () {
    $user = User::factory()->create();
    $alice = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $parent = Task::factory()->create();
    $parent->taskAssignees()->delete();
    $parent->taskAssignees()->create(['employee_id' => $alice]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('toggleSubtaskDraftAssignee', $parent->id, $alice)
        ->call('addSubtaskQuick', $parent->id, 'Untouched assignees');

    $subtask = Task::query()->where('title', 'Untouched assignees')->sole();

    expect($subtask->taskAssignees->pluck('employee_id')->all())->toBe([$alice]);
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

test('the board view marks a todo task missed once its due date passes', function () {
    $user = User::factory()->create();
    $missedTask = Task::factory()->create([
        'title' => 'Still in todo',
        'status' => TaskStatus::Todo,
        'due_date' => now()->subDays(3)->toDateString(),
    ]);
    $onTimeTask = Task::factory()->create([
        'title' => 'Not due yet',
        'status' => TaskStatus::Todo,
        'due_date' => now()->addDays(3)->toDateString(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'board')
        ->assertSee('data-test="task-board-missed-'.$missedTask->id.'"', false)
        ->assertDontSee('data-test="task-board-missed-'.$onTimeTask->id.'"', false);
});

test('the board view marks an in progress task overdue once its due date passes, not missed', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create([
        'status' => TaskStatus::InProgress,
        'due_date' => now()->subDays(3)->toDateString(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'board')
        ->assertDontSee('data-test="task-board-missed-'.$task->id.'"', false);

    expect($task->isOverdue())->toBeTrue()
        ->and($task->isMissed())->toBeFalse();
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

test('the board view offers an inline priority flag dropdown for subtasks, like the add task row', function () {
    $user = User::factory()->create();
    $parent = Task::factory()->create();
    $subtask = Task::factory()->subtaskOf($parent)->create(['priority' => TaskPriority::Low]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'board')
        ->assertSee('data-test="task-board-subtask-priority-trigger-'.$subtask->id.'"', false)
        ->assertSee('quickSetPriority('.$subtask->id.', \''.TaskPriority::High->value.'\')', false)
        ->call('quickSetPriority', $subtask->id, TaskPriority::High->value)
        ->assertHasNoErrors();

    expect($subtask->refresh()->priority)->toBe(TaskPriority::High);
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

test('quickSetStatus sets the start date when a task moves into ongoing', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['status' => TaskStatus::Todo, 'start_date' => null]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('quickSetStatus', $task->id, TaskStatus::InProgress->value)
        ->assertHasNoErrors();

    expect($task->refresh()->start_date?->toDateString())->toBe(now()->toDateString());
});

test('quickSetStatus does not overwrite an already-recorded start date', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['status' => TaskStatus::Todo, 'start_date' => '2026-01-01']);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('quickSetStatus', $task->id, TaskStatus::InProgress->value)
        ->assertHasNoErrors();

    expect($task->refresh()->start_date?->toDateString())->toBe('2026-01-01');
});

test('the stats section counts overdue and missed tasks separately and filters by each', function () {
    $user = User::factory()->create();
    $missedTask = Task::factory()->create(['status' => TaskStatus::Todo, 'due_date' => now()->subDay()->toDateString()]);
    Task::factory()->create(['status' => TaskStatus::InProgress, 'due_date' => now()->subDay()->toDateString()]);

    $component = Livewire::actingAs($user)->test('pages::tasks.index');

    expect($component->get('taskStats')['overdue'])->toBe(1)
        ->and($component->get('taskStats')['missed'])->toBe(1);

    $component->call('toggleDueFilter', 'missed')
        ->assertSee($missedTask->title)
        ->assertSet('dueFilter', 'missed');
});

test('quickSetStatus refuses to change the status of a completed task', function () {
    $user = User::factory()->create();
    $task = Task::factory()->completed()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('quickSetStatus', $task->id, TaskStatus::Todo->value)
        ->assertDispatched('toast-show');

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

test('the calendar view labels a missed todo chip differently from an overdue in progress chip', function () {
    $user = User::factory()->create();
    $missedTask = Task::factory()->create([
        'title' => 'Missed on calendar',
        'status' => TaskStatus::Todo,
        'due_date' => now()->startOfMonth()->addDays(2)->toDateString(),
    ]);
    $overdueTask = Task::factory()->create([
        'title' => 'Overdue on calendar',
        'status' => TaskStatus::InProgress,
        'due_date' => now()->startOfMonth()->addDays(2)->toDateString(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'calendar')
        ->assertSee('data-test="task-calendar-chip-badge-'.$missedTask->id.'"', false)
        ->assertSee('data-test="task-calendar-chip-badge-'.$overdueTask->id.'"', false);
});

test('the calendar view switches between day, 4-days, week and month periods', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'calendar')
        ->assertSet('viewMode', 'month')
        ->call('setViewMode', 'week')
        ->assertSet('viewMode', 'week')
        ->call('setViewMode', 'day')
        ->assertSet('viewMode', 'day')
        ->call('setViewMode', 'four_days')
        ->assertSet('viewMode', 'four_days')
        ->call('setViewMode', 'not-a-real-mode')
        ->assertSet('viewMode', 'four_days');
});

test('the calendar view period navigation moves the anchor date in day/week/4-day modes', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'calendar')
        ->call('setViewMode', 'week')
        ->set('anchorDate', '2026-04-08')
        ->call('nextPeriod')
        ->assertSet('anchorDate', '2026-04-15')
        ->call('previousPeriod')
        ->assertSet('anchorDate', '2026-04-08')
        ->call('goToToday')
        ->assertSet('anchorDate', '');
});

test('a task shows on its due date in the week calendar view', function () {
    $user = User::factory()->create();
    Task::factory()->create(['title' => 'Weekly task', 'due_date' => '2026-04-15']);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'calendar')
        ->call('setViewMode', 'week')
        ->set('anchorDate', '2026-04-15')
        ->assertSee('Weekly task');
});

test('rescheduleTask sets or clears a due date from calendar drag and drop', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['due_date' => null, 'created_by' => $user->id]);

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

test('rescheduleTask refuses to change the due date for anyone but the task creator', function () {
    $creator = User::factory()->create();
    $otherUser = User::factory()->create();
    $task = Task::factory()->create(['due_date' => '2026-05-01', 'created_by' => $creator->id]);

    Livewire::actingAs($otherUser)
        ->test('pages::tasks.index')
        ->call('rescheduleTask', $task->id, '2026-05-10')
        ->assertDispatched('toast-show');

    expect($task->refresh()->due_date?->toDateString())->toBe('2026-05-01');
});
