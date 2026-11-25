<?php

use App\Actions\Proposals\DeleteProposalPlan;
use App\Actions\Tasks\SaveDepartmentTask;
use App\Enums\DeliverableKind;
use App\Enums\DeliveryState;
use App\Enums\ProposalStatus;
use App\Enums\TaskKind;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 09:00:00'));
});

/**
 * A proposal planned for 2026, in the given status.
 */
function proposalPlanProposal(ProposalStatus $status = ProposalStatus::ConceptNote, string $name = 'Winter Response'): Proposal
{
    $proposal = Proposal::factory()->create([
        'name' => $name,
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
    ]);

    Proposal::query()->whereKey($proposal->id)->update(['status' => $status->value]);

    return $proposal->refresh();
}

/**
 * An HR department with one employee holding an active contract in it.
 *
 * @return array{department: int, employee: int}
 */
function proposalPlanDepartment(string $name = 'Media'): array
{
    $departmentId = insertHrDepartment($name);
    $employeeId = insertHrEmployee();
    insertHrContract($employeeId, insertHrPosition($departmentId));

    return ['department' => $departmentId, 'employee' => $employeeId];
}

/**
 * A complete department task submission for the given department.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function proposalPlanTaskInput(int $departmentId, array $overrides = []): array
{
    return [
        'title' => 'Film the distribution',
        'description' => null,
        'department_id' => $departmentId,
        'kind' => TaskKind::General->value,
        'requested_on' => '2026-03-01',
        'due_date' => '2026-03-20',
        ...$overrides,
    ];
}

/**
 * Run a plan action that must fail validation, and return its messages.
 *
 * @return array<string, array<int, string>>
 */
function proposalPlanValidationErrors(Closure $action): array
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('The action passed validation.');
}

test('a proposal manager adds a department task to a concept note', function () {
    $manager = userWithRole('Public Relations Manager');
    $proposal = proposalPlanProposal();
    $media = proposalPlanDepartment();
    $this->actingAs($manager);

    $task = app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($media['department'], [
        'assignee_employee_id' => $media['employee'],
    ]));

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'source_type' => 'proposal',
        'source_id' => $proposal->id,
        'department_id' => $media['department'],
        'kind' => 'general',
        'delivery_state' => null,
        'requested_on' => '2026-03-01',
        'due_date' => '2026-03-20',
        'created_by' => $manager->id,
    ]);
    $this->assertDatabaseHas('task_assignees', ['task_id' => $task->id, 'employee_id' => $media['employee']]);
});

test('a tasks creator who can see the proposal plans it too', function () {
    $employeeId = insertHrEmployee();
    $planner = userWithRole('Managers', $employeeId);
    $proposal = proposalPlanProposal(ProposalStatus::ProposalDraft);
    ProposalReviewer::factory()->create(['proposal_id' => $proposal->id, 'employee_id' => $employeeId]);
    $finance = proposalPlanDepartment('Finance');
    $this->actingAs($planner);

    $task = app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($finance['department']));

    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'source_type' => 'proposal', 'source_id' => $proposal->id, 'department_id' => $finance['department']]);
});

test('the plan stays editable until the proposal is submitted', function (ProposalStatus $status) {
    $proposal = proposalPlanProposal($status);
    $department = proposalPlanDepartment();
    $this->actingAs(userWithRole('Public Relations Manager'));

    $task = app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($department['department']));
    app(SaveDepartmentTask::class)->handle($proposal, $task->id, proposalPlanTaskInput($department['department'], ['title' => 'Film the handover']));

    expect($task->fresh()->title)->toBe('Film the handover');
})->with([
    'concept note review' => [ProposalStatus::ConceptNoteReview],
    'concept note submitted' => [ProposalStatus::ConceptNoteSubmitted],
    'proposal draft' => [ProposalStatus::ProposalDraft],
    'proposal review' => [ProposalStatus::ProposalReview],
]);

test('the plan is locked once submitted and closed when rejected', function (ProposalStatus $status, string $reason) {
    $proposal = proposalPlanProposal($status);
    $department = proposalPlanDepartment();
    $this->actingAs(userWithRole('Public Relations Manager'));

    expect(fn () => app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($department['department'])))
        ->toThrow(AuthorizationException::class, __($reason));

    $this->assertDatabaseCount('tasks', 0);
})->with([
    'submitted' => [ProposalStatus::Submitted, 'department_tasks.plan_window_locked'],
    'on hold' => [ProposalStatus::OnHold, 'department_tasks.plan_window_locked'],
    'rejected' => [ProposalStatus::Rejected, 'department_tasks.plan_window_closed'],
]);

test('a user without plan rights writes nothing', function () {
    $proposal = proposalPlanProposal();
    $department = proposalPlanDepartment();
    $this->actingAs(User::factory()->create());

    expect(fn () => app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($department['department'])))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('tasks', 0);
});

test('a department task needs an existing HR department', function (?int $departmentId, string $message) {
    $proposal = proposalPlanProposal();
    $this->actingAs(userWithRole('Public Relations Manager'));

    $errors = proposalPlanValidationErrors(fn () => app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput(0, ['department_id' => $departmentId])));

    expect($errors)->toBe(['department_id' => [__($message)]]);
    $this->assertDatabaseCount('tasks', 0);
})->with([
    'missing' => [null, 'department_tasks.choose_the_department'],
    'not in HR' => [999999, 'department_tasks.department_not_found'],
]);

test('the assignee must work in the task\'s department', function () {
    $proposal = proposalPlanProposal();
    $media = proposalPlanDepartment('Media');
    $finance = proposalPlanDepartment('Finance');
    $this->actingAs(userWithRole('Public Relations Manager'));

    $errors = proposalPlanValidationErrors(fn () => app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($media['department'], [
        'assignee_employee_id' => $finance['employee'],
    ])));

    expect($errors)->toBe(['assignee_employee_id' => [__('department_tasks.assignee_not_in_department')]]);
    $this->assertDatabaseCount('tasks', 0);
});

test('assigning a department task replaces or clears its person within the department', function () {
    $proposal = proposalPlanProposal();
    $media = proposalPlanDepartment('Media');
    $colleague = insertHrEmployee();
    insertHrContract($colleague, insertHrPosition($media['department']));
    $finance = proposalPlanDepartment('Finance');
    $this->actingAs(userWithRole('Public Relations Manager'));
    $action = app(SaveDepartmentTask::class);
    $task = $action->handle($proposal, null, proposalPlanTaskInput($media['department'], ['assignee_employee_id' => $media['employee']]));

    $action->assign($proposal, $task->id, $colleague);
    $afterReassign = $task->taskAssignees()->pluck('employee_id')->all();
    $errors = proposalPlanValidationErrors(fn () => $action->assign($proposal, $task->id, $finance['employee']));
    $afterRefusal = $task->taskAssignees()->pluck('employee_id')->all();
    $action->assign($proposal, $task->id, null);

    expect($afterReassign)->toBe([$colleague])
        ->and($errors)->toBe(['assignee_employee_id' => [__('department_tasks.assignee_not_in_department')]])
        ->and($afterRefusal)->toBe([$colleague])
        ->and($task->taskAssignees()->exists())->toBeFalse();
});

test('a media deliverable needs its deliverable kinds and starts requested', function () {
    $proposal = proposalPlanProposal();
    $media = proposalPlanDepartment();
    $this->actingAs(userWithRole('Public Relations Manager'));
    $action = app(SaveDepartmentTask::class);
    $deliverable = ['kind' => TaskKind::MediaDeliverable->value];

    $errors = proposalPlanValidationErrors(fn () => $action->handle($proposal, null, proposalPlanTaskInput($media['department'], [...$deliverable, 'media_kinds' => []])));
    $task = $action->handle($proposal, null, proposalPlanTaskInput($media['department'], [...$deliverable, 'media_kinds' => ['video', 'photos']]));

    expect($errors)->toBe(['media_kinds' => [__('department_tasks.choose_media_kinds')]])
        ->and($task->fresh()->media_kinds->all())->toBe([DeliverableKind::Video, DeliverableKind::Photos])
        ->and($task->fresh()->delivery_state)->toBe(DeliveryState::Requested);
});

test('a task that is not a media deliverable keeps no deliverable kinds', function () {
    $proposal = proposalPlanProposal();
    $media = proposalPlanDepartment();
    $this->actingAs(userWithRole('Public Relations Manager'));

    $task = app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($media['department'], ['media_kinds' => ['video']]));

    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'kind' => 'general', 'media_kinds' => null, 'delivery_state' => null]);
});

test('a delivered media deliverable keeps its kind', function () {
    $manager = userWithRole('Public Relations Manager');
    $proposal = proposalPlanProposal();
    $media = proposalPlanDepartment();
    $task = Task::factory()->forProposal($proposal, $media['department'])->mediaDeliverable()->create([
        'created_by' => $manager->id,
        'delivery_state' => DeliveryState::Delivered,
    ]);
    $this->actingAs($manager);

    $errors = proposalPlanValidationErrors(fn () => app(SaveDepartmentTask::class)->handle($proposal, $task->id, proposalPlanTaskInput($media['department'])));

    expect($errors)->toBe(['kind' => [__('tasks.delivered_deliverable_keeps_its_kind')]])
        ->and($task->fresh()->kind)->toBe(TaskKind::MediaDeliverable);
});

test('the due date cannot fall before the request date', function (array $dates) {
    $proposal = proposalPlanProposal();
    $media = proposalPlanDepartment();
    $this->actingAs(userWithRole('Public Relations Manager'));

    $errors = proposalPlanValidationErrors(fn () => app(SaveDepartmentTask::class)->handle($proposal, null, [
        ...array_diff_key(proposalPlanTaskInput($media['department']), ['requested_on' => true]),
        ...$dates,
    ]));

    expect($errors)->toBe(['due_date' => [__('tasks.due_before_requested')]]);
    $this->assertDatabaseCount('tasks', 0);
})->with([
    'requested date given' => [['requested_on' => '2026-03-10', 'due_date' => '2026-03-09']],
    'requested today by default' => [['due_date' => '2026-02-28']],
]);

test('a financial task title carries no amount', function (string $title) {
    $proposal = proposalPlanProposal();
    $finance = proposalPlanDepartment('Finance');
    $this->actingAs(userWithRole('Public Relations Manager'));

    $errors = proposalPlanValidationErrors(fn () => app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($finance['department'], [
        'kind' => TaskKind::Financial->value,
        'title' => $title,
    ])));

    expect($errors)->toBe(['title' => [__('department_tasks.financial_title_has_amount')]]);
    $this->assertDatabaseCount('tasks', 0);
})->with([
    'a number' => ['Transfer 1,500 to the center'],
    'a currency code' => ['Pay the usd advance'],
    'a dollar sign' => ['Settle the $ advance'],
    'a euro sign' => ['Reconcile € receipts'],
    'the Syrian pound' => ['سداد السلفة ل.س'],
]);

test('a financial task title without an amount is accepted', function () {
    $proposal = proposalPlanProposal();
    $finance = proposalPlanDepartment('Finance');
    $this->actingAs(userWithRole('Public Relations Manager'));

    $task = app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($finance['department'], [
        'kind' => TaskKind::Financial->value,
        'title' => 'Prepare the budget annex for 12 centers',
    ]));

    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'kind' => 'financial']);
});

test('deleting a proposal plan removes its tasks and files and nothing else', function () {
    Storage::fake('local');
    $proposal = proposalPlanProposal();
    $other = proposalPlanProposal(name: 'Other Response');
    $proposalTask = Task::factory()->forProposal($proposal)->create();
    $subtask = Task::factory()->subtaskOf($proposalTask)->create();
    Storage::disk('local')->put('task-attachments/plan.pdf', 'plan');
    TaskAttachment::factory()->create(['task_id' => $subtask->id, 'path' => 'task-attachments/plan.pdf', 'original_name' => 'plan.pdf']);
    $otherTask = Task::factory()->forProposal($other)->create();
    $this->actingAs(userWithRole('Financial'));

    app(DeleteProposalPlan::class)->handle($proposal);

    foreach ([$proposalTask, $subtask] as $deleted) {
        $this->assertModelMissing($deleted);
    }
    $this->assertModelExists($proposal);
    $this->assertModelExists($otherTask);
    Storage::disk('local')->assertMissing('task-attachments/plan.pdf');
});

test('only a proposals.delete holder may delete a proposal plan', function () {
    $proposal = proposalPlanProposal();
    $task = Task::factory()->forProposal($proposal)->create();
    $this->actingAs(userWithRole('Public Relations Manager'));

    expect(fn () => app(DeleteProposalPlan::class)->handle($proposal))->toThrow(AuthorizationException::class);

    $this->assertModelExists($task);
});

/*
 * The Plan tab on the proposal page (slice 2).
 */

test('the old tasks tab lands on the plan tab', function () {
    Livewire::actingAs(userWithRole('Public Relations Manager'))
        ->withQueryParams(['tab' => 'tasks'])
        ->test('pages::proposals.show', ['proposal' => proposalPlanProposal()])
        ->assertSet('tab', 'plan')
        ->assertSeeHtml('data-tab-panel="plan"');
});

test('the plan tab groups the media, finance and other department tasks', function () {
    $proposal = proposalPlanProposal();
    $media = proposalPlanDepartment('Media');
    $finance = proposalPlanDepartment('Finance');
    $this->actingAs($manager = userWithRole('Public Relations Manager'));

    $film = app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($media['department'], [
        'title' => 'Film the distribution',
        'kind' => TaskKind::MediaDeliverable->value,
        'media_kinds' => [DeliverableKind::Video->value],
    ]));
    app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($finance['department'], ['title' => 'Open the project ledger', 'kind' => TaskKind::Financial->value]));
    app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($media['department'], ['title' => 'Book the venue']));

    $component = Livewire::actingAs($manager)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'plan');

    expect($component->instance()->planGroups['media']->pluck('title')->all())->toBe(['Film the distribution'])
        ->and($component->instance()->planGroups['finance']->pluck('title')->all())->toBe(['Open the project ledger'])
        ->and($component->instance()->planGroups['other']->pluck('title')->all())->toBe(['Book the venue']);

    $component->assertSeeHtml('data-department="'.$media['department'].'"')
        ->assertDontSeeHtml('data-plan-group="milestones"');

    $rows = collect($component->instance()->planGanttPayload['rows']);

    expect($rows->pluck('id')->all())->toContain('plan-'.$proposal->id, 'plan-task-'.$film->id)
        ->and($rows->every(fn (array $row): bool => $row['editable'] === false))->toBeTrue();
});

test('the plan tab is read-only once submitted and says why', function () {
    Livewire::actingAs(userWithRole('Public Relations Manager'))
        ->test('pages::proposals.show', ['proposal' => proposalPlanProposal(ProposalStatus::Submitted)])
        ->set('tab', 'plan')
        ->assertSeeHtml('data-plan-read-only')
        ->assertSeeText(__('department_tasks.plan_window_locked'))
        ->assertDontSeeHtml('data-test="add-plan-task-media"');
});

test('a user without plan rights is refused by the plan forms and nothing is written', function () {
    $proposal = proposalPlanProposal();
    $department = proposalPlanDepartment();
    $viewer = userWithRole('Media Team');

    Livewire::actingAs($viewer)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('planTask.title', 'Sneaky task')
        ->set('planTask.department_id', (string) $department['department'])
        ->set('planTask.due_date', '2026-03-20')
        ->call('savePlanTask')
        ->assertForbidden();

    expect(Task::query()->withoutGlobalScopes()->where('title', 'Sneaky task')->exists())->toBeFalse();
});

test('editing and assigning a plan task both refuse a task from another proposal', function () {
    $manager = userWithRole('Public Relations Manager');
    $proposal = proposalPlanProposal();
    $other = proposalPlanProposal(name: 'Other Response');
    $department = proposalPlanDepartment();
    $task = Task::factory()->forProposal($other, $department['department'])->create([
        'title' => 'Foreign task', 'created_by' => $manager->id,
    ]);
    $assigneeId = $task->taskAssignees()->sole()->employee_id;
    $this->actingAs($manager);
    $tasks = app(SaveDepartmentTask::class);

    expect(fn () => $tasks->handle($proposal, $task->id, proposalPlanTaskInput($department['department'])))
        ->toThrow(ModelNotFoundException::class);
    expect(fn () => $tasks->assign($proposal, $task->id, null))->toThrow(ModelNotFoundException::class);

    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'title' => 'Foreign task', 'source_id' => $other->id]);
    $this->assertDatabaseHas('task_assignees', ['task_id' => $task->id, 'employee_id' => $assigneeId]);
});

test('another proposal\'s task cannot be opened through this page', function () {
    $proposal = proposalPlanProposal();
    $other = proposalPlanProposal(name: 'Other grant');
    $department = proposalPlanDepartment();
    $this->actingAs($manager = userWithRole('Public Relations Manager'));

    $foreignTask = app(SaveDepartmentTask::class)->handle($other, null, proposalPlanTaskInput($department['department'], ['title' => 'Foreign task']));

    Livewire::actingAs($manager)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->call('openPlanTaskForm', $foreignTask->id)
        ->assertStatus(404);

    Livewire::actingAs($manager)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->call('openAssignModal', $foreignTask->id)
        ->assertStatus(404);

    expect($foreignTask->fresh()->title)->toBe('Foreign task');
});

test('the proposal plan lists funded project department tasks without offering proposal-level changes', function () {
    $manager = userWithRole('Public Relations Manager');
    $proposal = proposalPlanProposal();
    $project = Project::factory()->create();
    $proposal->projects()->attach($project);
    // Y-16 (D-93): the plan reads the work of the proposal's places.
    $row = proposalRowForProject($proposal, $project);
    $department = proposalPlanDepartment();
    $task = Task::factory()->create([
        'source_type' => 'proposal_location',
        'source_id' => $row->id,
        'department_id' => $department['department'],
        'created_by' => $manager->id,
        'title' => 'Project department task',
    ]);

    $openPlan = fn () => Livewire::actingAs($manager)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'plan');

    $openPlan()
        ->assertSeeText('Project department task')
        ->assertDontSeeHtml('data-test="edit-plan-task-'.$task->id.'"')
        ->assertDontSeeHtml('data-test="assign-plan-task-'.$task->id.'"')
        ->call('openPlanTaskForm', $task->id)
        ->assertNotFound();
    $openPlan()->call('openAssignModal', $task->id)->assertNotFound();
});

test('the person is assigned from the plan tab among the department\'s people only', function () {
    $proposal = proposalPlanProposal();
    $media = proposalPlanDepartment('Media');
    $outsider = insertHrEmployee();
    $this->actingAs($manager = userWithRole('Public Relations Manager'));
    $task = app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($media['department']));

    $page = Livewire::actingAs($manager)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'plan')
        ->call('openAssignModal', $task->id)
        ->assertSet('showAssignModal', true);

    expect(array_column($page->instance()->assignOptions, 'value'))->toBe([(string) $media['employee']]);

    $page->set('assigneeEmployeeId', (string) $outsider)
        ->call('saveAssignee')
        ->assertHasErrors('assigneeEmployeeId')
        ->set('assigneeEmployeeId', (string) $media['employee'])
        ->call('saveAssignee')
        ->assertHasNoErrors()
        ->assertSet('showAssignModal', false);

    expect($task->taskAssignees()->pluck('employee_id')->all())->toBe([$media['employee']]);
});

test('the plan tab holds a flat query count whatever the number of tasks', function () {
    $proposal = proposalPlanProposal();
    $media = proposalPlanDepartment('Media');
    $manager = userWithRole('Public Relations Manager');
    $this->actingAs($manager);

    $countQueries = function () use ($manager, $proposal): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($manager)->test('pages::proposals.show', ['proposal' => $proposal])->set('tab', 'plan');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return count($queries);
    };

    app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($media['department'], ['assignee_employee_id' => $media['employee']]));
    $countQueries();
    $small = $countQueries();

    foreach (range(1, 12) as $index) {
        app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($media['department'], ['title' => 'Task '.$index, 'assignee_employee_id' => $media['employee']]));
    }

    expect($countQueries())->toBe($small);
});
