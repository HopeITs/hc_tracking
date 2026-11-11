<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Renderless;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

use function Livewire\invade;

test('guests are redirected from the task directory', function () {
    $this->get(route('tasks.index'))->assertRedirect(route('login'));
});

test('authenticated users can view the task directory', function () {
    $user = User::factory()->role('General Manager')->create();

    $this->actingAs($user)->get(route('tasks.index'))->assertOk()->assertSeeText('Tasks');
});

test('tasks can be filtered by search status priority and assignee', function () {
    $user = User::factory()->role('General Manager')->create();
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

test('the my tasks only filter is a checkbox that scopes every view', function () {
    $user = User::factory()->role('General Manager')->create();
    $me = insertHrEmployee(firstNameEn: 'Signed', lastNameEn: 'InUser');
    $user->forceFill(['employee_id' => $me])->save();

    $mine = Task::factory()->create(['title' => 'My own task']);
    $mine->taskAssignees()->delete();
    $mine->taskAssignees()->create(['employee_id' => $me]);

    $other = Task::factory()->create(['title' => 'Someone else\'s task']);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->assertSeeHtml('data-test="task-filter-my-tasks-only"')
        ->assertSee('My own task')->assertSee('Someone else\'s task')
        ->set('myTasksOnly', true)
        ->assertSee('My own task')->assertDontSee('Someone else\'s task')
        ->set('view', 'list')
        ->assertSee('My own task')->assertDontSee('Someone else\'s task')
        ->set('view', 'calendar')
        ->assertSee('My own task')->assertDontSee('Someone else\'s task')
        ->set('myTasksOnly', false)
        ->assertSee('My own task')->assertSee('Someone else\'s task');
});

test('tasks can be filtered by source type and by a specific source record', function () {
    $user = User::factory()->role('General Manager')->create();
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
    $creator = User::factory()->role('General Manager')->create();
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
    $creator = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();

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
    $user = User::factory()->role('General Manager')->create();

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
    $user = User::factory()->role('General Manager')->create();

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

test('a task can be created with no assignee at all', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('openCreateDrawer')
        ->set('title', 'Valid title')
        ->set('taskSourceType', 'proposal')
        ->set('taskSourceId', (string) Proposal::factory()->create()->id)
        ->set('assignedTo', [])
        ->call('saveTask')
        ->assertHasNoErrors();

    expect(Task::query()->sole()->taskAssignees()->count())->toBe(0);
});

test('creating a task rejects invalid workflow values and progress', function (string $property, string $value) {
    $user = User::factory()->role('General Manager')->create();

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
    $user = User::factory()->role('General Manager')->create();

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
    $creator = User::factory()->role('General Manager')->create();
    $editor = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();
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

test('toggleAssignee removes the last assignee and leaves the task unassigned', function () {
    $user = User::factory()->role('General Manager')->create();
    $alice = insertHrEmployee();
    $task = Task::factory()->create();
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $alice]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('toggleAssignee', $task->id, $alice);

    expect($task->refresh()->taskAssignees)->toBeEmpty();
});

test('the list row assignee trigger opens a shared popover fed by the employee list', function () {
    $user = User::factory()->role('General Manager')->create();
    $alice = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $bob = insertHrEmployee(firstNameEn: 'Bob', lastNameEn: 'Rahman');
    $task = Task::factory()->create();
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $alice]);

    // The popover draws its options in the browser from the shared
    // employeeOptions store, so the page carries no per-employee markup.
    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'list')
        ->assertSee('data-test="task-assignee-trigger-'.$task->id.'"', false)
        ->assertSee('x-data="assigneePopover"', false)
        ->assertDontSee('assignee-popover-option-'.$alice, false)
        ->assertSee('$store.assigneePicker.openFor('.$task->id.',', false);

    $this->actingAs($user)
        ->getJson(route('employees.picker-options'))
        ->assertOk()
        ->assertJsonFragment(['value' => (string) $alice])
        ->assertJsonFragment(['value' => (string) $bob]);
});

test('no tasks view renders the employee option list', function (string $view) {
    $user = User::factory()->role('General Manager')->create();
    $alice = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $tasks = Task::factory()->count(3)->create(['due_date' => today()]);

    foreach ($tasks as $task) {
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $alice]);
    }

    // Each board card, "add task" row and "add subtask" row used to loop
    // every employee into its own hidden popover: 45 board cards put 32 MB
    // of HTML on /tasks. They all open the one shared popover, which the
    // browser fills from the employee list endpoint.
    $html = Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', $view)
        ->html();

    expect(substr_count($html, 'assignee-option-'))->toBe(0)
        ->and(substr_count($html, 'assignee-popover-option-'.$alice))->toBe(0)
        // A few mentions per task (row label, avatar hover title), never one per employee.
        ->and(substr_count($html, 'Alice Nadir'))->toBeLessThanOrEqual(15);
})->with(['list', 'board', 'calendar', 'cards']);

test('a task can be deleted and takes only its own attachments with it', function () {
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create();

    Livewire::actingAs($user)->test('pages::tasks.index')->call('deleteTask')->assertHasNoErrors();

    $this->assertDatabaseHas('tasks', ['id' => $task->id]);
});

test('task rows link to the registered task detail route', function () {
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create(['title' => 'Open my details']);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->assertSeeHtml('href="'.route('tasks.show', $task).'"');
});

test('task directory content is escaped', function () {
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)->test('pages::tasks.index')->assertSee('No tasks yet');
});

test('the cards view says nothing is open for the viewer when none of the tasks are theirs', function () {
    $user = User::factory()->role('General Manager')->create();
    Task::factory()->create();

    Livewire::actingAs($user)->test('pages::tasks.index')
        ->set('view', 'cards')
        ->assertSee('Nothing open is assigned to you.')
        ->assertDontSeeHtml('data-test="task-cards-grid"');
});

test('a cards view card title links to the task detail page', function () {
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create(['title' => 'Reconcile the ledger', 'created_by' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'cards')
        ->assertSeeHtml('href="'.route('tasks.show', $task).'"')
        ->assertSeeHtml('data-test="cards-task-title-'.$task->id.'"');
});

test('the cards view shows only the viewers own tasks that match the filters', function () {
    $user = User::factory()->role('General Manager')->create();
    $myTask = Task::factory()->create(['status' => TaskStatus::Todo, 'created_by' => $user->id]);
    $myFilteredOutTask = Task::factory()->create(['status' => TaskStatus::Done, 'created_by' => $user->id]);
    $someoneElsesTask = Task::factory()->create(['status' => TaskStatus::Todo]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'cards')
        ->set('status', TaskStatus::Todo->value)
        ->assertSeeHtml('data-test="task-cards-grid"')
        ->assertSeeHtml('data-test="cards-task-card-'.$myTask->id.'"')
        ->assertDontSeeHtml('data-test="cards-task-card-'.$myFilteredOutTask->id.'"')
        ->assertDontSeeHtml('data-test="cards-task-card-'.$someoneElsesTask->id.'"')
        ->assertDontSeeHtml('data-test="my-tasks-rail"');
});

test('toggling completion marks a task done, and a completed task cannot be un-completed', function () {
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();
    Task::factory()->create(['title' => 'Older task', 'created_at' => now()->subDay()]);
    Task::factory()->create(['title' => 'Newer task', 'created_at' => now()]);

    $component = Livewire::actingAs($user)->test('pages::tasks.index')->call('setSort', 'created');

    expect($component->get('tasks')->pluck('title')->all())->toBe(['Older task', 'Newer task']);

    $component->call('setSort', 'created');
    expect($component->get('tasks')->pluck('title')->all())->toBe(['Newer task', 'Older task']);
});

test('an unrecognized sort field is ignored and leaves the current sort untouched', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('setSort', 'not-a-real-field')
        ->assertSet('sortField', 'due')
        ->assertSet('sortDirection', 'asc');
});

test('sorting stays server-side and correct across paginated pages', function () {
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();
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
    // fourth, and the "To review" tab badge's count a fifth — five total, not a second full
    // (board-sized) fetch of every task with its relations just to count a
    // handful of numbers.
    expect($taskTableQueries)->toHaveCount(5);
});

test('cards view cards visually distinguish tasks assigned to the viewer from tasks the viewer only delegated', function () {
    $employeeId = insertHrEmployee();
    $user = User::factory()->role('General Manager')->create(['employee_id' => $employeeId]);

    $assignedToMe = Task::factory()->create(['title' => 'Assigned to me', 'created_by' => $user->id]);
    $assignedToMe->taskAssignees()->delete();
    $assignedToMe->taskAssignees()->create(['employee_id' => $employeeId]);

    $delegatedByMe = Task::factory()->create(['title' => 'Delegated by me', 'created_by' => $user->id]);
    $delegatedByMe->taskAssignees()->delete();
    $delegatedByMe->taskAssignees()->create(['employee_id' => insertHrEmployee(firstNameEn: 'Someone', lastNameEn: 'Else')]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'cards')
        ->assertSee('data-test="cards-task-role-assigned-'.$assignedToMe->id.'"', false)
        ->assertSee('data-test="cards-task-role-delegated-'.$delegatedByMe->id.'"', false)
        ->assertDontSee('data-test="cards-task-role-delegated-'.$assignedToMe->id.'"', false)
        ->assertDontSee('data-test="cards-task-role-assigned-'.$delegatedByMe->id.'"', false)
        ->assertSee('Assigned to Someone Else');
});

test('the list view groups tasks by status with a colored collapsible header', function () {
    $user = User::factory()->role('General Manager')->create();
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

test('list view group headers count every task in the group, not just the current page', function () {
    $user = User::factory()->role('General Manager')->create();
    Task::factory()->count(15)->create(['priority' => TaskPriority::Low]);
    Task::factory()->count(10)->create(['priority' => TaskPriority::High]);

    $component = Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'list')
        ->set('group', 'priority');

    expect($component->instance()->taskGroupCounts)->toEqual(['high' => 10, 'low' => 15])
        ->and($component->instance()->tasks->getCollection()->map(fn (Task $task) => $task->priority->value)->all())
        ->toBe([...array_fill(0, 10, 'high'), ...array_fill(0, 10, 'low')]);

    $component->call('gotoPage', 2);

    expect($component->instance()->tasks->getCollection()->map(fn (Task $task) => $task->priority->value)->all())
        ->toBe(array_fill(0, 5, 'low'));
});

test('list view status groups follow the workflow order and stay together across pages', function () {
    $user = User::factory()->role('General Manager')->create();
    Task::factory()->count(20)->create(['status' => TaskStatus::Done, 'due_date' => now()->subYear()]);
    Task::factory()->count(2)->create(['status' => TaskStatus::UnderReview, 'due_date' => null]);
    Task::factory()->create(['status' => TaskStatus::Todo, 'due_date' => null]);

    $component = Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'list')
        ->set('group', 'status');

    expect($component->instance()->taskGroupCounts)->toEqual(['todo' => 1, 'under review' => 2, 'done' => 20])
        ->and($component->instance()->tasks->getCollection()->map(fn (Task $task) => $task->status->value)->unique()->values()->all())
        ->toBe(['todo', 'under review', 'done']);
});

test('list view milestone group counts are keyed like the milestone groups', function () {
    $user = User::factory()->role('General Manager')->create();
    $milestone = Milestone::factory()->create();
    Task::factory()->count(3)->create(['source_type' => 'milestone', 'source_id' => $milestone->id]);
    Task::factory()->create(['source_type' => 'proposal', 'source_id' => Proposal::factory()->create()->id]);

    $component = Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'list')
        ->set('group', 'milestone');

    expect($component->instance()->taskGroupCounts)->toEqual([(string) $milestone->id => 3, '' => 1]);
});

test('list view assignee groups keep each assignee set together and count across pages', function () {
    $user = User::factory()->role('General Manager')->create();
    $alice = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $bob = insertHrEmployee(firstNameEn: 'Bob', lastNameEn: 'Rahman');

    $assign = function (int $count, ?int $employeeId): void {
        Task::factory()->count($count)->create()->each(function (Task $task) use ($employeeId): void {
            $task->taskAssignees()->delete();

            if ($employeeId !== null) {
                $task->taskAssignees()->create(['employee_id' => $employeeId]);
            }
        });
    };
    $assign(12, $bob);
    $assign(12, $alice);
    $assign(2, null);

    $component = Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'list')
        ->set('group', 'assignee');

    expect($component->instance()->taskGroupCounts)->toEqual([(string) $alice => 12, (string) $bob => 12, '' => 2]);

    $firstPageKeys = $component->instance()->tasks->getCollection()
        ->map(fn (Task $task) => $task->taskAssignees->pluck('employee_id')->sort()->implode(','))->all();

    expect($firstPageKeys)->toBe([...array_fill(0, 12, (string) $alice), ...array_fill(0, 8, (string) $bob)]);
});

test('quickCreateTask opens the drawer pre-filled with the title and the group context', function () {
    $user = User::factory()->role('General Manager')->create(['employee_id' => null]);
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
        ->assertSet('showTaskDrawer', false);

    expect(Task::query()->sole()->only(['title', 'source_type', 'source_id']))
        ->toBe(['title' => 'Another task', 'source_type' => 'milestone', 'source_id' => $milestone->id]);
});

test('quickCreateTask does nothing for a blank title', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('quickCreateTask', '   ')
        ->assertSet('showTaskDrawer', false);
});

test('quickCreateTask creates the task directly with no drawer when the milestone and assignee are both known', function () {
    $employeeId = insertHrEmployee();
    $user = User::factory()->role('General Manager')->create(['employee_id' => $employeeId]);
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
    $user = User::factory()->role('General Manager')->create(['employee_id' => $employeeId]);
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
    $user = User::factory()->role('General Manager')->create(['employee_id' => $employeeId]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('quickCreateTask', 'Ambiguous task')
        ->assertSet('showTaskDrawer', true)
        ->assertSet('title', 'Ambiguous task');

    expect(Task::query()->count())->toBe(0);
});

test('quickCreateTask files a task with no other context under the viewer\'s own department', function () {
    $employeeId = insertHrEmployee();
    $departmentId = insertHrDepartment('Monitoring & Evaluation');
    insertHrContract($employeeId, insertHrPosition($departmentId));
    $user = User::factory()->role('General Manager')->create(['employee_id' => $employeeId]);
    Task::factory()->create(['status' => TaskStatus::Todo]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'list')
        ->set('group', 'status')
        ->assertSee('Department: Monitoring & Evaluation')
        ->call('quickCreateTask', 'Department task')
        ->assertSet('showTaskDrawer', false);

    $task = Task::query()->where('title', 'Department task')->sole();

    expect($task->title)->toBe('Department task')
        ->and($task->source_type)->toBe('department')
        ->and($task->source_id)->toBe($departmentId)
        ->and($task->taskAssignees->pluck('employee_id')->all())->toBe([$employeeId]);
});

test('the inline row\'s more fields button opens the drawer with the typed title and row draft', function () {
    $user = User::factory()->role('General Manager')->create(['employee_id' => insertHrEmployee()]);
    $assignee = insertHrEmployee();
    Task::factory()->create(['status' => TaskStatus::Todo]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'list')
        ->set('group', 'status')
        ->assertSeeHtml('data-test="task-quick-add-more-status-todo"')
        ->set('taskDrafts.row-1', ['priority' => TaskPriority::High->value, 'due_date' => '2026-12-01', 'assignees' => [(string) $assignee]])
        ->call('openQuickTaskInDrawer', '  Needs a description  ', TaskStatus::InProgress->value, null, 'row-1')
        ->assertSet('showTaskDrawer', true)
        ->assertSet('title', 'Needs a description')
        ->assertSet('taskStatus', TaskStatus::InProgress->value)
        ->assertSet('taskPriority', TaskPriority::High->value)
        ->assertSet('dueDate', '2026-12-01')
        ->assertSet('assignedTo', [(string) $assignee]);

    expect(Task::query()->count())->toBe(1);
});

test('a user who cannot create tasks cannot open the inline row in the drawer', function () {
    Role::create(['name' => 'Task Viewer', 'guard_name' => 'web'])->givePermissionTo('tasks.view');
    $viewer = User::factory()->role('Task Viewer')->create();

    Livewire::actingAs($viewer)
        ->test('pages::tasks.index')
        ->call('openQuickTaskInDrawer', 'Sneaky task')
        ->assertForbidden();
});

test('quickCreateTask creates the task unassigned when the signed-in user has no linked HR employee', function () {
    $user = User::factory()->role('General Manager')->create(['employee_id' => null]);
    $milestone = Milestone::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('quickCreateTask', 'Unassigned task', null, (string) $milestone->id)
        ->assertSet('showTaskDrawer', false);

    expect(Task::query()->sole()->taskAssignees()->count())->toBe(0);
});

test('an inline add row whose assignees were all removed creates the task unassigned', function () {
    $employeeId = insertHrEmployee();
    $user = User::factory()->role('General Manager')->create(['employee_id' => $employeeId]);
    $milestone = Milestone::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('toggleTaskDraftAssignee', 'row-1', $employeeId)
        ->call('toggleTaskDraftAssignee', 'row-1', $employeeId)
        ->call('quickCreateTask', 'Nobody yet', null, (string) $milestone->id, 'row-1');

    expect(Task::query()->sole()->taskAssignees()->count())->toBe(0);
});

test('the shared assignee popover offers to remove every assignee', function () {
    Task::factory()->create();

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::tasks.index')
        ->set('view', 'list')
        ->assertSeeHtml('data-test="assignee-remove-all"')
        ->assertSee('Remove all assignees');
});

test('the list view inline add task row honours its own due date priority status and assignees', function () {
    // The row's own signed-in user (Alice) is only the *fallback* assignee —
    // once the draft explicitly picks someone (Bob), that pick wins outright
    // rather than being merged with the fallback.
    $alice = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $bob = insertHrEmployee(firstNameEn: 'Bob', lastNameEn: 'Rahman');
    $user = User::factory()->role('General Manager')->create(['employee_id' => $alice]);
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
    $user = User::factory()->role('General Manager')->create(['employee_id' => null]);

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
    $user = User::factory()->role('General Manager')->create();

    $component = Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('toggleTaskDraftAssignee', 'row-1', $alice);

    expect(invade($component->instance())->taskDraft('row-1')['assignees'])->toBe([(string) $alice]);

    $component->call('toggleTaskDraftAssignee', 'row-1', $alice);

    expect(invade($component->instance())->taskDraft('row-1')['assignees'])->toBe([]);
});

test('quickCreateTask uses the calendar days own due date over any draft due date', function () {
    $employeeId = insertHrEmployee();
    $user = User::factory()->role('General Manager')->create(['employee_id' => $employeeId]);
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
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();
    $parent = Task::factory()->create();
    $subtask = Task::factory()->create(['parent_task_id' => $parent->id]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('addSubtaskQuick', $subtask->id, 'Grandchild task');

    expect(Task::query()->where('title', 'Grandchild task')->exists())->toBeFalse();
});

test('addSubtaskQuick does nothing for a blank title', function () {
    $user = User::factory()->role('General Manager')->create();
    $parent = Task::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('addSubtaskQuick', $parent->id, '   ');

    expect($parent->subtasks()->count())->toBe(0);
});

test('subtaskDraft defaults to todo medium priority no due date and the parents own assignees', function () {
    $user = User::factory()->role('General Manager')->create();
    $alice = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $parent = Task::factory()->create();
    $parent->taskAssignees()->delete();
    $parent->taskAssignees()->create(['employee_id' => $alice]);

    $draft = invade(Livewire::actingAs($user)->test('pages::tasks.index')->instance())
        ->subtaskDraft($parent->fresh(['taskAssignees']));

    expect($draft)->toBe([
        'status' => TaskStatus::Todo->value,
        'priority' => TaskPriority::Medium->value,
        'due_date' => '',
        'assignees' => [(string) $alice],
    ]);
});

test('the list view inline add subtask row honours its own due date priority status and assignees', function () {
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();
    $alice = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $parent = Task::factory()->create();
    $parent->taskAssignees()->delete();
    $parent->taskAssignees()->create(['employee_id' => $alice]);

    $component = Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('toggleSubtaskDraftAssignee', $parent->id, $alice);

    expect(invade($component->instance())->subtaskDraft($parent->fresh(['taskAssignees']))['assignees'])->toBe([]);
});

test('addSubtaskQuick adds the subtask unassigned when the draft removed every assignee', function () {
    $user = User::factory()->role('General Manager')->create();
    $alice = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $parent = Task::factory()->create();
    $parent->taskAssignees()->delete();
    $parent->taskAssignees()->create(['employee_id' => $alice]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('toggleSubtaskDraftAssignee', $parent->id, $alice)
        ->call('addSubtaskQuick', $parent->id, 'Untouched assignees');

    $subtask = Task::query()->where('title', 'Untouched assignees')->sole();

    expect($subtask->taskAssignees)->toBeEmpty();
});

test('dropping a task into a different board column persists the status change', function () {
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();
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

test('each board column shows its task count and an empty column collapses to a slim strip', function () {
    $user = User::factory()->role('General Manager')->create();
    Task::factory()->count(2)->create(['status' => TaskStatus::Todo]);
    Task::factory()->create(['status' => TaskStatus::InProgress]);

    $html = Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'board')
        ->assertSeeHtml('data-test="task-board-count-todo">2</span>')
        ->assertSeeHtml('data-test="task-board-count-in progress">1</span>')
        ->assertSeeHtml('data-test="task-board-count-done">0</span>')
        ->assertSeeHtml('data-test="task-board-strip-done"')
        ->assertDontSeeHtml('data-test="task-board-strip-todo"')
        ->assertDontSee('No tasks in this status')
        ->html();

    expect($html)
        ->toMatch('/data-test="task-board-column-todo"\s+data-collapsed="false"/')
        ->toMatch('/data-test="task-board-column-done"\s+data-collapsed="true"/');
});

test('every board column offers an add control that creates the task straight into that status', function () {
    $employeeId = insertHrEmployee();
    $user = User::factory()->role('General Manager')->create(['employee_id' => $employeeId]);
    $milestone = Milestone::factory()->create();
    Task::factory()->create(['status' => TaskStatus::Todo]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'board')
        ->assertSeeHtml('data-test="task-board-header-add-done"')
        ->assertSeeHtml('data-test="task-board-strip-add-done"')
        ->assertSeeHtml('data-test="task-board-add-toggle-todo"')
        ->assertDontSeeHtml('data-test="task-board-add-toggle-done"')
        ->assertSeeHtml("quickCreateTask(\$event.target.value, 'done', null, 'board|done')")
        ->call('quickCreateTask', 'Wrap up the report', TaskStatus::Done->value, (string) $milestone->id, 'board|done')
        ->assertSet('showTaskDrawer', false)
        ->assertSeeHtml('data-test="task-board-count-done">1</span>');

    expect(Task::query()->where('title', 'Wrap up the report')->sole()->status)->toBe(TaskStatus::Done);
});

test('a view-only user sees the board counts but no add task control in any column', function () {
    Role::create(['name' => 'Task Viewer', 'guard_name' => 'web'])->givePermissionTo('tasks.view');
    $viewer = User::factory()->role('Task Viewer')->create();
    Task::factory()->create(['status' => TaskStatus::Todo]);

    $board = Livewire::actingAs($viewer)
        ->test('pages::tasks.index')
        ->set('view', 'board')
        ->assertSeeHtml('data-test="task-board-count-todo">1</span>');

    foreach (TaskStatus::cases() as $status) {
        $board->assertDontSeeHtml('data-test="task-board-header-add-'.$status->value.'"')
            ->assertDontSeeHtml('data-test="task-board-strip-add-'.$status->value.'"')
            ->assertDontSeeHtml('data-test="task-board-add-toggle-'.$status->value.'"')
            ->assertDontSeeHtml('data-test="task-board-quick-add-'.$status->value.'"');
    }
});

test('the board view offers a keyboard accessible status menu as a non pointer alternative to dragging', function () {
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create(['status' => TaskStatus::Todo]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'board')
        ->assertSee('data-test="task-quick-status-'.$task->id.'"', false)
        ->assertSee("\$store.taskMenu.openFor('status', 'board-status-".$task->id."'", false)
        ->assertDontSee('wire:change="quickSetStatus(', false);
});

test('the board view marks a todo task missed once its due date passes', function () {
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();
    $parent = Task::factory()->create(['title' => 'Parent with subtasks']);
    Task::factory()->subtaskOf($parent)->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'board')
        ->assertSee('data-test="task-board-card-'.$parent->id.'"', false);
});

test('the board view offers an inline priority flag dropdown for subtasks, like the add task row', function () {
    $user = User::factory()->role('General Manager')->create();
    $parent = Task::factory()->create();
    $subtask = Task::factory()->subtaskOf($parent)->create(['priority' => TaskPriority::Low]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'board')
        ->assertSee('data-test="task-board-subtask-priority-trigger-'.$subtask->id.'"', false)
        ->assertSee("\$store.taskMenu.openFor('priority', 'board-sub-priority-".$subtask->id."'", false)
        ->call('quickSetPriority', $subtask->id, TaskPriority::High->value)
        ->assertHasNoErrors();

    expect($subtask->refresh()->priority)->toBe(TaskPriority::High);
});

test('a task with subtasks cannot be deleted until they are moved or removed', function () {
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('confirmDelete', $task->id)
        ->call('deleteTask')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
});

test('quickSetStatus moves a task and syncs its completion fields', function () {
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create(['status' => TaskStatus::Todo, 'start_date' => null]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('quickSetStatus', $task->id, TaskStatus::InProgress->value)
        ->assertHasNoErrors();

    expect($task->refresh()->start_date?->toDateString())->toBe(now()->toDateString());
});

test('quickSetStatus does not overwrite an already-recorded start date', function () {
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create(['status' => TaskStatus::Todo, 'start_date' => '2026-01-01']);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('quickSetStatus', $task->id, TaskStatus::InProgress->value)
        ->assertHasNoErrors();

    expect($task->refresh()->start_date?->toDateString())->toBe('2026-01-01');
});

test('the stats section counts overdue and missed tasks separately and filters by each', function () {
    $user = User::factory()->role('General Manager')->create();
    $missedTask = Task::factory()->create(['status' => TaskStatus::Todo, 'due_date' => now()->subDay()->toDateString()]);
    Task::factory()->create(['status' => TaskStatus::InProgress, 'due_date' => now()->subDay()->toDateString()]);

    $component = Livewire::actingAs($user)->test('pages::tasks.index');

    expect($component->get('taskStats')['overdue'])->toBe(1)
        ->and($component->get('taskStats')['missed'])->toBe(1);

    $component->call('toggleDueFilter', 'missed')
        ->assertSee($missedTask->title)
        ->assertSet('dueFilter', 'missed');
});

test('pressing a headline figure filters the list but keeps every figure steady', function () {
    $user = User::factory()->role('General Manager')->create();
    Task::factory()->create(['status' => TaskStatus::Todo, 'due_date' => now()->subDay()->toDateString()]);
    Task::factory()->create(['status' => TaskStatus::InProgress, 'due_date' => now()->subDay()->toDateString()]);
    Task::factory()->create(['status' => TaskStatus::Todo, 'due_date' => now()->addDays(2)->toDateString()]);

    $component = Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('toggleDueFilter', 'overdue')
        ->assertSeeHtml('data-test="task-stat-overdue"')
        ->assertSeeHtml('aria-pressed="true"');

    expect($component->get('taskStats'))->toMatchArray(['total' => 1, 'all' => 3, 'overdue' => 1, 'missed' => 1, 'due_soon' => 1]);

    $component->call('toggleDueFilter', 'overdue')->assertSet('dueFilter', '');
});

test('choosing a due filter in the bar returns the list to page one', function () {
    $user = User::factory()->role('General Manager')->create();
    Task::factory()->count(25)->create(['status' => TaskStatus::InProgress, 'due_date' => now()->subDay()->toDateString()]);

    Livewire::actingAs($user)
        ->withQueryParams(['view' => 'list', 'page' => 2])
        ->test('pages::tasks.index')
        ->assertSet('paginators.page', 2)
        ->set('dueFilter', 'overdue')
        ->assertSet('paginators.page', 1);
});

test('filters kept behind the Filters button show as chips that remove them', function () {
    $user = User::factory()->role('General Manager')->create();
    Task::factory()->create(['title' => 'Urgent one', 'priority' => TaskPriority::High]);
    Task::factory()->create(['title' => 'Calm one', 'priority' => TaskPriority::Low]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->assertDontSeeHtml('data-test="task-active-filter-chips"')
        ->set('priority', TaskPriority::High->value)
        ->assertSeeHtml('data-test="task-active-filter-priority"')
        ->assertSee('Urgent one')->assertDontSee('Calm one')
        ->set('priority', '')
        ->assertDontSeeHtml('data-test="task-active-filter-chips"')
        ->assertSee('Calm one');
});

test('quickSetStatus refuses to change the status of a completed task', function () {
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->completed()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('quickSetStatus', $task->id, TaskStatus::Todo->value)
        ->assertDispatched('toast-show');

    expect($task->refresh()->status)->toBe(TaskStatus::Done);
});

test('the calendar view places tasks on their due date and lists unscheduled tasks separately', function () {
    $user = User::factory()->role('General Manager')->create();
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
    $this->travelTo(now()->startOfMonth()->addDays(10));
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();

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
    $user = User::factory()->role('General Manager')->create();

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
    $user = User::factory()->role('General Manager')->create();
    Task::factory()->create(['title' => 'Weekly task', 'due_date' => '2026-04-15']);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'calendar')
        ->call('setViewMode', 'week')
        ->set('anchorDate', '2026-04-15')
        ->assertSee('Weekly task');
});

test('rescheduleTask sets or clears a due date from calendar drag and drop', function () {
    $user = User::factory()->role('General Manager')->create();
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

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('rescheduleTask', $task->id, '2026-02-31');

    expect($task->refresh()->due_date)->toBeNull();
});

test('rescheduleTask refuses to change the due date for anyone but the task creator', function () {
    $creator = User::factory()->role('General Manager')->create();
    $otherUser = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create(['due_date' => '2026-05-01', 'created_by' => $creator->id]);

    Livewire::actingAs($otherUser)
        ->test('pages::tasks.index')
        ->call('rescheduleTask', $task->id, '2026-05-10')
        ->assertDispatched('toast-show');

    expect($task->refresh()->due_date?->toDateString())->toBe('2026-05-01');
});

test('a view-only user gets no calendar drag handles or drag hint, and a reschedule is refused with a message', function () {
    Role::create(['name' => 'Task Viewer', 'guard_name' => 'web'])->givePermissionTo('tasks.view');
    $viewer = User::factory()->role('Task Viewer')->create();
    $task = Task::factory()->create(['title' => 'Someone else task', 'due_date' => null]);

    Livewire::actingAs($viewer)
        ->test('pages::tasks.index')
        ->set('view', 'calendar')
        ->assertSee('Someone else task')
        ->assertDontSee('data-task-id="'.$task->id.'"', false)
        ->assertDontSeeHtml('data-test="task-calendar-schedule-'.$task->id.'"')
        ->assertDontSee('Drag one onto a day to schedule it.')
        ->assertSee('Unscheduled tasks have no due date.')
        ->call('rescheduleTask', $task->id, '2026-05-10')
        ->assertDispatched('toast-show');

    expect($task->refresh()->due_date)->toBeNull();
});

test('the task creator can drag their unscheduled tasks onto the calendar', function () {
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create(['due_date' => null, 'created_by' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'calendar')
        ->assertSee('data-task-id="'.$task->id.'"', false)
        ->assertSeeHtml('data-test="task-calendar-schedule-'.$task->id.'"')
        ->assertSeeHtml('data-test="task-calendar-schedule-date-'.$task->id.'"')
        ->assertSee('Drag one onto a day to schedule it.');
});

test('a busy calendar day shows its first chips and puts the rest behind a "+N more" list', function () {
    $user = User::factory()->role('General Manager')->create();
    $day = now()->startOfMonth()->addDays(9)->toDateString();
    $tasks = Task::factory()->count(5)->create(['due_date' => $day, 'priority' => TaskPriority::Low]);
    $urgent = Task::factory()->create(['due_date' => $day, 'priority' => TaskPriority::High]);

    $page = Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'calendar')
        ->assertSeeHtml('data-test="task-calendar-more-'.$day.'"')
        ->assertSee('+4 more')
        // High priority leads the day, so it is never hidden behind "+N more".
        ->assertSeeHtml('data-test="task-calendar-chip-'.$urgent->id.'"');

    foreach ($tasks->push($urgent) as $task) {
        $page->assertSeeHtml('data-test="task-calendar-more-item-'.$task->id.'"');
    }
});

test('the calendar shows the visible month and every unscheduled task however large the backlog', function () {
    $user = User::factory()->role('General Manager')->create();
    Task::factory()->count(500)->create(['due_date' => now()->subYear()->toDateString()]);
    $later = Task::factory()->create(['title' => 'Next month task', 'due_date' => now()->addMonthNoOverflow()->startOfMonth()->addDays(14)->toDateString()]);
    Task::factory()->create(['title' => 'Undated backlog task', 'due_date' => null]);

    $page = Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'calendar')
        ->assertSee('Undated backlog task')
        ->call('nextMonth')
        ->assertSeeHtml('data-test="task-calendar-chip-'.$later->id.'"');

    expect($page->instance()->calendarGrid['tasksByDay']->flatten()->pluck('id')->all())->toBe([$later->id]);
});

test('the default due-date sort lists unfinished tasks before done ones', function () {
    $user = User::factory()->role('General Manager')->create();
    Task::factory()->create(['title' => 'Old finished task', 'status' => TaskStatus::Done, 'due_date' => now()->subYear()]);
    Task::factory()->create(['title' => 'Undated open task', 'status' => TaskStatus::UnderReview, 'due_date' => null]);
    Task::factory()->create(['title' => 'Dated open task', 'status' => TaskStatus::Todo, 'due_date' => now()->addWeek()]);

    $component = Livewire::actingAs($user)->test('pages::tasks.index')->set('view', 'list');

    expect($component->instance()->tasks->getCollection()->pluck('title')->all())
        ->toBe(['Dated open task', 'Undated open task', 'Old finished task']);
});

test('a view-only user sees plain status and due date text on board cards and a view-only notice', function () {
    Role::create(['name' => 'Task Viewer', 'guard_name' => 'web'])->givePermissionTo('tasks.view');
    $viewer = User::factory()->role('Task Viewer')->create();
    $task = Task::factory()->create(['status' => TaskStatus::Todo, 'due_date' => '2024-03-25']);

    Livewire::actingAs($viewer)
        ->test('pages::tasks.index')
        ->set('view', 'board')
        ->assertDontSee('data-test="task-board-due-date-'.$task->id.'"', false)
        ->assertDontSee('data-test="task-quick-status-'.$task->id.'"', false)
        ->assertSee('data-test="task-board-due-date-text-'.$task->id.'"', false)
        ->assertSee('Mar 25, 2024')
        ->assertSee('You can view these tasks. Only tasks you created or are assigned to can be changed.');
});

test('the task creator keeps the editable due date on their board card and sees no view-only notice', function () {
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create(['status' => TaskStatus::Todo, 'created_by' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'board')
        ->assertSee('data-test="task-board-due-date-'.$task->id.'"', false)
        ->assertDontSee('data-test="task-board-due-date-text-'.$task->id.'"', false)
        ->assertDontSee('You can view these tasks.');
});

test('the task register explains overdue and missed, and labels the related-to filter', function () {
    $user = User::factory()->role('General Manager')->create();
    Task::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->assertSee('Started, past its due date')
        ->assertSee('Not started, past its due date')
        ->assertSee('Anything')
        ->assertSee('Any date');
});

test('list cards show the year on a due date outside the current year', function () {
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create(['due_date' => '2024-03-25', 'created_by' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'list')
        ->assertSee('data-test="task-card-due-date-'.$task->id.'"', false)
        ->assertSee('Mar 25, 2024');
});

test('stat chips are only coloured when their count is above zero', function () {
    $user = User::factory()->role('General Manager')->create();
    Task::factory()->create(['status' => TaskStatus::Todo, 'due_date' => now()->addMonths(2)]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->assertDontSeeHtml('class="dk-bad"')
        ->assertDontSeeHtml('class="dk-warn"');
});

test('list cards offer the subtask toggle only when a task has subtasks', function () {
    $user = User::factory()->role('General Manager')->create();
    $parent = Task::factory()->create();
    $loneTask = Task::factory()->create();
    Task::factory()->subtaskOf($parent)->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'list')
        ->set('listLayout', 'narrow')
        ->assertSeeHtml('data-test="task-subtasks-toggle-mobile-'.$parent->id.'"')
        ->assertDontSeeHtml('data-test="task-subtasks-toggle-mobile-'.$loneTask->id.'"');
});

test('list rows spell out how late a task is, not only by colour', function (string $layout, string $hookSuffix) {
    $user = User::factory()->role('General Manager')->create();
    $missed = Task::factory()->create(['status' => TaskStatus::Todo, 'due_date' => today()->subDays(3)]);
    $overdue = Task::factory()->create(['status' => TaskStatus::InProgress, 'due_date' => today()->subDays(5)]);
    $finished = Task::factory()->create(['status' => TaskStatus::Done, 'due_date' => today()->subDays(4)]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'list')
        ->set('listLayout', $layout)
        ->assertSeeHtml('data-test="task-missed'.$hookSuffix.'-'.$missed->id.'"')
        ->assertSee('Missed · 3 days late')
        ->assertSeeHtml('data-test="task-overdue'.$hookSuffix.'-'.$overdue->id.'"')
        ->assertSee('5 days late')
        ->assertDontSee('4 days late');
})->with([
    'table' => ['wide', ''],
    'cards' => ['narrow', '-mobile'],
]);

test('list rows name the priority in words beside its flag', function () {
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create(['priority' => TaskPriority::High, 'created_by' => $user->id]);

    $html = Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'list')
        ->set('listLayout', 'wide')
        ->html();

    expect($html)->toMatch('/data-test="task-priority-trigger-'.$task->id.'"[\s\S]*?<span class="truncate">High<\/span>/');
});

test('the table offers the subtask toggle with done-of-total only when a task has subtasks', function () {
    $user = User::factory()->role('General Manager')->create();
    $parent = Task::factory()->create();
    $loneTask = Task::factory()->create();
    Task::factory()->subtaskOf($parent)->create(['status' => TaskStatus::Done]);
    Task::factory()->subtaskOf($parent)->create(['status' => TaskStatus::Todo]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'list')
        ->set('listLayout', 'wide')
        ->assertSeeHtml('data-test="task-subtasks-toggle-'.$parent->id.'"')
        ->assertSee('1 of 2 subtasks done')
        ->assertDontSeeHtml('data-test="task-subtasks-toggle-'.$loneTask->id.'"');
});

test('the scope tabs show my tasks, tasks I assigned and tasks waiting for my review', function () {
    $user = User::factory()->role('General Manager')->create();
    $me = insertHrEmployee(firstNameEn: 'Signed', lastNameEn: 'InUser');
    $user->forceFill(['employee_id' => $me])->save();

    $assignedToMe = Task::factory()->create(['title' => 'Assigned to me task']);
    $assignedToMe->taskAssignees()->delete();
    $assignedToMe->taskAssignees()->create(['employee_id' => $me]);
    Task::factory()->create(['title' => 'I assigned this task', 'created_by' => $user->id, 'status' => TaskStatus::Todo]);
    Task::factory()->create(['title' => 'Waiting for my review task', 'created_by' => $user->id, 'status' => TaskStatus::UnderReview]);
    Task::factory()->create(['title' => 'Somebody else review task', 'status' => TaskStatus::UnderReview]);

    $titles = fn ($component) => $component->instance()->tasks->getCollection()->pluck('title')->sort()->values()->all();

    $component = Livewire::actingAs($user)->test('pages::tasks.index')->set('view', 'list')
        ->assertSeeHtml('data-test="task-scope-tabs"')
        ->assertSeeHtml('data-test="task-scope-review-count"');

    expect($titles($component->call('setScope', 'mine')))->toBe(['Assigned to me task'])
        ->and($titles($component->call('setScope', 'assigned')))->toBe(['I assigned this task', 'Waiting for my review task'])
        ->and($titles($component->call('setScope', 'review')))->toBe(['Waiting for my review task'])
        ->and($component->instance()->tasksAwaitingMyReview)->toBe(1)
        ->and($titles($component->call('setScope', 'anything-else')))->toHaveCount(4)
        ->and($component->get('scope'))->toBe('');
});

test('the assigned to me tab is empty for a user with no employee record', function () {
    $user = User::factory()->role('General Manager')->create(['employee_id' => null]);
    Task::factory()->create();

    $component = Livewire::actingAs($user)->test('pages::tasks.index')->set('view', 'list')->call('setScope', 'mine');

    expect($component->instance()->tasks->total())->toBe(0);
});

test('the show all subtasks switch drives the list and board but not the calendar', function () {
    $user = User::factory()->role('General Manager')->create();
    Task::factory()->create();

    $page = Livewire::actingAs($user)->test('pages::tasks.index');

    $page->set('view', 'list')
        ->assertSeeHtml('data-test="task-subtasks-toggle-all"')
        ->assertSeeHtml('x-data="taskSubtaskScope"');

    $page->set('view', 'board')
        ->assertSeeHtml('data-test="task-subtasks-toggle-all"')
        ->assertSeeHtml('x-data="taskSubtaskScope"');

    $page->set('view', 'calendar')
        ->assertDontSeeHtml('data-test="task-subtasks-toggle-all"');
});

test('assignee avatars on board cards and list rows name the person on hover', function () {
    $user = User::factory()->role('General Manager')->create();
    $alice = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $task = Task::factory()->create(['status' => TaskStatus::Todo]);
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $alice]);

    $page = Livewire::actingAs($user)->test('pages::tasks.index');

    // The board shows a person card on hover, filled from the avatar's data.
    $page->set('view', 'board')
        ->assertSeeHtml('data-person-name="Alice Nadir"')
        ->assertSeeHtml('x-on:mouseenter="$store.personCard.show($el)"')
        ->assertSeeHtml('data-test="person-card"');
    $page->set('view', 'list')->set('listLayout', 'wide')->assertSeeHtml('title="Alice Nadir"');
});

test('the task form is rendered only while its drawer is open', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->assertDontSeeHtml('wire:model="progressPercentage"')
        ->call('openCreateDrawer')
        ->assertSeeHtml('wire:model="progressPercentage"');
});

test('opening the create drawer files the task under the viewer\'s department and assigns it to them', function () {
    $employeeId = insertHrEmployee();
    $departmentId = insertHrDepartment('Monitoring & Evaluation');
    insertHrContract($employeeId, insertHrPosition($departmentId));
    $user = User::factory()->role('General Manager')->create(['employee_id' => $employeeId]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('openCreateDrawer')
        ->assertSet('showTaskDrawer', true)
        ->assertSet('taskSourceType', 'department')
        ->assertSet('taskSourceId', (string) $departmentId)
        ->assertSet('assignedTo', [(string) $employeeId]);
});

test('the create dialog is one screen, not a step wizard', function () {
    $user = User::factory()->role('General Manager')->create(['employee_id' => insertHrEmployee()]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('openCreateDrawer')
        ->assertSeeHtml('data-test="task-drawer-composer"')
        ->assertSeeHtml('data-test="task-drawer-title-input"')
        ->assertSeeHtml('data-test="task-assignee-picker"')
        ->assertDontSeeHtml('class="form-section');
});

test('the popover toggle actions skip the list re-render and still save', function () {
    $user = User::factory()->role('General Manager')->create();
    $alice = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $bob = insertHrEmployee(firstNameEn: 'Bob', lastNameEn: 'Rahman');
    $task = Task::factory()->create();
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $alice]);

    // Re-rendering the whole list for each click made picking assignees feel
    // slow; the popover refreshes the list once, when it closes.
    $component = Livewire::actingAs($user)->test('pages::tasks.index')->set('view', 'list');
    $class = get_class($component->instance());

    foreach (['togglePickedAssignee', 'toggleTaskDraftAssignee', 'toggleSubtaskDraftAssignee'] as $method) {
        expect((new ReflectionMethod($class, $method))->getAttributes(Renderless::class))->toHaveCount(1);
    }

    // The avatar "x" calls toggleAssignee directly and must keep re-rendering the row.
    expect((new ReflectionMethod($class, 'toggleAssignee'))->getAttributes(Renderless::class))->toHaveCount(0);

    $component->call('togglePickedAssignee', $task->id, $bob);

    expect($task->refresh()->taskAssignees->pluck('employee_id')->sort()->values()->all())->toBe(collect([$alice, $bob])->sort()->values()->all());
});

test('the list renders only the layout the browser reported', function (?string $layout, bool $table, bool $cards) {
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->create();

    // Rendering every task as a table row and a mobile card cost ~40% of the
    // list's server time; the browser reports its width and gets one layout.
    $html = Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'list')
        ->set('listLayout', $layout)
        ->html();

    expect(str_contains($html, 'data-test="task-row-'.$task->id.'"'))->toBe($table)
        ->and(str_contains($html, 'data-test="task-card-'.$task->id.'"'))->toBe($cards);
})->with([
    'not reported yet renders both' => [null, true, true],
    'wide renders the table' => ['wide', true, false],
    'narrow renders the cards' => ['narrow', false, true],
]);

test('only wide and narrow are accepted as a reported list layout', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('setListLayout', 'narrow')
        ->assertSet('listLayout', 'narrow')
        ->call('setListLayout', 'sideways')
        ->assertSet('listLayout', null);
});

test('status and priority menus render once per page, not once per row', function () {
    $user = User::factory()->role('General Manager')->create();
    Task::factory()->count(3)->create();

    $html = Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'list')
        ->set('listLayout', 'wide')
        ->html();

    // Rows hold a trigger that opens the shared menu in _task-option-menu.
    expect(substr_count($html, "\$store.taskMenu.openFor('status'"))->toBeGreaterThanOrEqual(3)
        ->and(substr_count($html, 'data-test="task-status-option-todo"'))->toBe(1)
        ->and(substr_count($html, 'data-test="task-priority-option-high"'))->toBe(1);
});

test('the board opens the shared menu for priority instead of a dropdown per card', function () {
    $user = User::factory()->role('General Manager')->create();
    $tasks = Task::factory()->count(3)->create();

    $html = Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'board')
        ->html();

    foreach ($tasks as $task) {
        expect($html)->toContain("\$store.taskMenu.openFor('priority', 'board-priority-".$task->id."'");
    }

    expect(substr_count($html, 'data-test="task-priority-option-high"'))->toBe(1);
});

test('quick actions stay silent on the board, including moving a card, and still toast in the list', function () {
    $user = User::factory()->role('General Manager')->create();
    $alice = insertHrEmployee(firstNameEn: 'Alice', lastNameEn: 'Nadir');
    $bob = insertHrEmployee(firstNameEn: 'Bob', lastNameEn: 'Rahman');
    $task = Task::factory()->create(['status' => TaskStatus::Todo, 'priority' => TaskPriority::Low]);
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $alice]);

    // A toast per pick piles up when the board is worked in bursts, and a
    // moved card already shows its new status by sitting in the new column.
    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'board')
        ->call('quickSetPriority', $task->id, TaskPriority::High->value)
        ->call('toggleAssignee', $task->id, $bob)
        ->call('quickSetStatus', $task->id, TaskStatus::InProgress->value)
        ->assertNotDispatched('toast-show');

    expect($task->refresh()->status)->toBe(TaskStatus::InProgress)
        ->and($task->priority)->toBe(TaskPriority::High)
        ->and($task->taskAssignees()->count())->toBe(2);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'list')
        ->call('quickSetPriority', $task->id, TaskPriority::Low->value)
        ->assertDispatched('toast-show');
});

test('a refused quick action still explains itself on the board', function () {
    $user = User::factory()->role('General Manager')->create();
    $task = Task::factory()->completed()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'board')
        ->call('toggleComplete', $task->id)
        ->assertDispatched('toast-show');
});

test('a long board column draws its first cards and the rest on request', function () {
    $user = User::factory()->role('General Manager')->create();
    Task::factory()->count(27)->create(['status' => TaskStatus::Todo]);

    $board = Livewire::actingAs($user)->test('pages::tasks.index');

    expect(substr_count($board->html(), 'wire:key="task-board-card-'))->toBe(25);
    $board->assertSeeHtml('data-test="task-board-more-todo"')
        ->assertSeeHtml('data-test="task-board-count-todo">27<');

    $board->call('showMoreBoard', 'todo')
        ->assertDontSeeHtml('data-test="task-board-more-todo"');

    expect(substr_count($board->html(), 'wire:key="task-board-card-'))->toBe(27);
});

test('the due today filter lists only unfinished tasks due today', function () {
    $user = User::factory()->role('General Manager')->create();
    Task::factory()->create(['title' => 'Due today open', 'status' => TaskStatus::InProgress, 'due_date' => now()->toDateString()]);
    Task::factory()->create(['title' => 'Due today finished', 'status' => TaskStatus::Done, 'due_date' => now()->toDateString()]);
    Task::factory()->create(['title' => 'Due tomorrow', 'status' => TaskStatus::Todo, 'due_date' => now()->addDay()->toDateString()]);

    $titles = fn ($component) => $component->instance()->tasks->getCollection()->pluck('title')->all();

    $component = Livewire::actingAs($user)->test('pages::tasks.index')->set('view', 'list')->set('dueFilter', 'due_today');

    expect($titles($component))->toBe(['Due today open'])
        ->and($component->get('taskStats'))->toMatchArray(['total' => 1, 'all' => 3]);

    expect($titles($component->set('dueFilter', 'not-a-filter')))->toHaveCount(3);
});

test('search status due filter and scope narrow the list together', function () {
    $user = User::factory()->role('General Manager')->create();
    $overdue = ['status' => TaskStatus::InProgress, 'due_date' => now()->subDay()->toDateString()];

    Task::factory()->create(['title' => 'Budget review mine', 'created_by' => $user->id, ...$overdue]);
    Task::factory()->create(['title' => 'Budget review other', ...$overdue]);
    Task::factory()->create(['title' => 'Budget review on time', 'created_by' => $user->id, 'status' => TaskStatus::InProgress, 'due_date' => now()->addDays(3)->toDateString()]);
    Task::factory()->create(['title' => 'Budget review todo', 'created_by' => $user->id, 'status' => TaskStatus::Todo, 'due_date' => now()->subDay()->toDateString()]);
    Task::factory()->create(['title' => 'Venue booking mine', 'created_by' => $user->id, ...$overdue]);

    $component = Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'list')
        ->set('search', '  budget  ')
        ->set('status', TaskStatus::InProgress->value)
        ->set('dueFilter', 'overdue')
        ->call('setScope', 'assigned');

    expect($component->instance()->tasks->getCollection()->pluck('title')->all())->toBe(['Budget review mine']);
});

test('the Filters badge counts only the popover filters that apply', function () {
    $user = User::factory()->role('General Manager')->create();
    $milestone = Milestone::factory()->create();

    $component = Livewire::actingAs($user)->test('pages::tasks.index');
    $count = fn () => $component->instance()->activeAdvancedFilterCount();

    expect($count())->toBe(0);

    $component->set('priority', TaskPriority::High->value)
        ->set('assignee', '7')
        ->set('departmentId', '3')
        ->set('sourceType', 'milestone')
        ->set('sourceId', (string) $milestone->id)
        ->set('myTasksOnly', true);

    expect($count())->toBe(6);

    $component->set('view', 'cards');

    expect($count())->toBe(5);

    $scopedUser = User::factory()->role('Team Member')->create();
    $scoped = Livewire::actingAs($scopedUser)->test('pages::tasks.index')->set('departmentId', '3');

    expect($scoped->instance()->activeAdvancedFilterCount())->toBe(0);
});

test('the scope tab and a blank search are not active filters', function () {
    $user = User::factory()->role('General Manager')->create();

    $component = Livewire::actingAs($user)->test('pages::tasks.index')->call('setScope', 'mine')->set('search', '   ');

    expect($component->instance()->hasActiveFilters())->toBeFalse();

    $component->set('dueFilter', 'due_today');

    expect($component->instance()->hasActiveFilters())->toBeTrue();
});

test('clearing the filters keeps the scope tab and the calendar period', function () {
    $user = User::factory()->role('General Manager')->create();

    $component = Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('view', 'calendar')
        ->call('setScope', 'assigned')
        ->set('viewMode', 'week')
        ->set('anchorDate', '2026-03-04')
        ->set('priority', TaskPriority::High->value)
        ->set('dueFilter', 'overdue')
        ->call('clearFilters')
        ->assertSet('priority', '')
        ->assertSet('dueFilter', '')
        ->assertSet('scope', 'assigned')
        ->assertSet('view', 'calendar')
        ->assertSet('viewMode', 'week')
        ->assertSet('anchorDate', '2026-03-04');

    expect($component->instance()->hasActiveFilters())->toBeFalse();
});

test('the popover Clear resets only the Filters popover filters', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->set('search', 'report')
        ->set('dueFilter', 'overdue')
        ->set('priority', TaskPriority::High->value)
        ->call('toggleMyTasksOnly')
        ->call('clearAdvancedFilters')
        ->assertSet('priority', '')
        ->assertSet('myTasksOnly', false)
        ->assertSet('search', 'report')
        ->assertSet('dueFilter', 'overdue');
});

test('an empty scope tab offers to show all tasks instead of adding the first one', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('setScope', 'review')
        ->assertSee(__('tasks.empty_scope_heading'))
        ->assertSeeHtml('data-test="task-empty-show-all"');
});
