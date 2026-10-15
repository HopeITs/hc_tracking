<?php

use App\Actions\Milestones\SaveProposalMilestone;
use App\Actions\Proposals\DeleteProposalPlan;
use App\Actions\Tasks\SaveDepartmentTask;
use App\Enums\DeliverableKind;
use App\Enums\DeliveryState;
use App\Enums\ProposalStatus;
use App\Enums\TaskKind;
use App\Exceptions\InvalidParentException;
use App\Models\Milestone;
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
 * A complete Add Milestone submission, dated inside 2026.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function proposalPlanMilestoneInput(array $overrides = []): array
{
    return [
        'name' => 'Community consultations',
        'description' => null,
        'planned_start_date' => '2026-03-01',
        'planned_end_date' => '2026-04-30',
        'responsible_employee_id' => insertHrEmployee(),
        ...$overrides,
    ];
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

test('a proposal manager adds a milestone and a department task to a concept note', function () {
    $manager = userWithRole('Public Relations Manager');
    $proposal = proposalPlanProposal();
    $media = proposalPlanDepartment();
    $this->actingAs($manager);

    $milestone = app(SaveProposalMilestone::class)->handle($proposal, null, proposalPlanMilestoneInput());
    $task = app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($media['department'], [
        'milestone_id' => $milestone->id,
        'assignee_employee_id' => $media['employee'],
    ]));

    $this->assertDatabaseHas('milestones', [
        'id' => $milestone->id,
        'proposal_id' => $proposal->id,
        'project_id' => null,
        'name' => 'Community consultations',
        'planned_start_date' => '2026-03-01',
        'planned_end_date' => '2026-04-30',
        'is_final_report' => false,
    ]);
    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'source_type' => 'milestone',
        'source_id' => $milestone->id,
        'department_id' => $media['department'],
        'kind' => 'general',
        'delivery_state' => null,
        'requested_on' => '2026-03-01',
        'due_date' => '2026-03-20',
        'created_by' => $manager->id,
    ]);
    $this->assertDatabaseHas('task_assignees', ['task_id' => $task->id, 'employee_id' => $media['employee']]);
});

test('a milestones and tasks creator who can see the proposal plans it too', function () {
    $employeeId = insertHrEmployee();
    $planner = userWithRole('Managers', $employeeId);
    $proposal = proposalPlanProposal(ProposalStatus::ProposalDraft);
    ProposalReviewer::factory()->create(['proposal_id' => $proposal->id, 'employee_id' => $employeeId]);
    $finance = proposalPlanDepartment('Finance');
    $this->actingAs($planner);

    $milestone = app(SaveProposalMilestone::class)->handle($proposal, null, proposalPlanMilestoneInput());
    $task = app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($finance['department']));

    $this->assertDatabaseHas('milestones', ['id' => $milestone->id, 'proposal_id' => $proposal->id]);
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'source_type' => 'proposal', 'source_id' => $proposal->id, 'department_id' => $finance['department']]);
});

test('a milestones creator who cannot see the proposal is refused', function () {
    $proposal = proposalPlanProposal();
    $this->actingAs(userWithRole('Managers'));

    expect(fn () => app(SaveProposalMilestone::class)->handle($proposal, null, proposalPlanMilestoneInput()))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('milestones', 0);
});

test('the plan stays editable until the proposal is submitted', function (ProposalStatus $status) {
    $proposal = proposalPlanProposal($status);
    $milestone = Milestone::factory()->forProposal($proposal)->create(['name' => 'Kickoff']);
    $department = proposalPlanDepartment();
    $this->actingAs(userWithRole('Public Relations Manager'));

    app(SaveProposalMilestone::class)->handle($proposal, $milestone->id, proposalPlanMilestoneInput(['name' => 'Donor kickoff']));
    app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($department['department']));

    expect($milestone->fresh()->name)->toBe('Donor kickoff');
    $this->assertDatabaseHas('tasks', ['source_type' => 'proposal', 'source_id' => $proposal->id]);
})->with([
    'concept note review' => [ProposalStatus::ConceptNoteReview],
    'concept note submitted' => [ProposalStatus::ConceptNoteSubmitted],
    'proposal draft' => [ProposalStatus::ProposalDraft],
    'proposal review' => [ProposalStatus::ProposalReview],
]);

test('the plan is locked once submitted and closed when rejected', function (ProposalStatus $status, string $reason) {
    $proposal = proposalPlanProposal($status);
    $milestone = Milestone::factory()->forProposal($proposal)->create(['name' => 'Kickoff']);
    $department = proposalPlanDepartment();
    $this->actingAs(userWithRole('Public Relations Manager'));

    expect(fn () => app(SaveProposalMilestone::class)->handle($proposal, null, proposalPlanMilestoneInput()))
        ->toThrow(AuthorizationException::class, __($reason))
        ->and(fn () => app(SaveProposalMilestone::class)->handle($proposal, $milestone->id, proposalPlanMilestoneInput(['name' => 'Moved'])))
        ->toThrow(AuthorizationException::class, __($reason))
        ->and(fn () => app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($department['department'])))
        ->toThrow(AuthorizationException::class, __($reason));

    expect($milestone->fresh()->name)->toBe('Kickoff');
    $this->assertDatabaseCount('milestones', 1);
    $this->assertDatabaseCount('tasks', 0);
})->with([
    'submitted' => [ProposalStatus::Submitted, 'milestones.plan_window_locked'],
    'on hold' => [ProposalStatus::OnHold, 'milestones.plan_window_locked'],
    'rejected' => [ProposalStatus::Rejected, 'milestones.plan_window_closed'],
]);

test('after approval milestones follow the normal milestone rights', function () {
    $proposal = proposalPlanProposal(ProposalStatus::Approved);
    $input = proposalPlanMilestoneInput();

    $this->actingAs(userWithRole('Public Relations Manager'));
    expect(fn () => app(SaveProposalMilestone::class)->handle($proposal, null, $input))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('milestones', 0);

    $this->actingAs(userWithRole('Managers'));
    $milestone = app(SaveProposalMilestone::class)->handle($proposal, null, $input);
    $this->assertDatabaseHas('milestones', ['id' => $milestone->id, 'proposal_id' => $proposal->id]);
});

test('after approval the grant period replaces the planned period', function () {
    $proposal = proposalPlanProposal(ProposalStatus::Approved);
    Proposal::query()->whereKey($proposal->id)->update(['actual_date_begin' => '2026-04-01', 'actual_date_end' => '2026-09-30']);
    $this->actingAs(userWithRole('Managers'));

    $errors = proposalPlanValidationErrors(fn () => app(SaveProposalMilestone::class)->handle($proposal->refresh(), null, proposalPlanMilestoneInput([
        'planned_start_date' => '2026-03-01',
        'planned_end_date' => '2026-03-31',
    ])));

    expect($errors)->toBe(['planned_start_date' => [__('milestones.milestone_outside_period')]]);
    $this->assertDatabaseCount('milestones', 0);
});

test('a user without plan rights writes nothing', function () {
    $proposal = proposalPlanProposal();
    $department = proposalPlanDepartment();
    $this->actingAs(User::factory()->create());

    expect(fn () => app(SaveProposalMilestone::class)->handle($proposal, null, proposalPlanMilestoneInput()))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($department['department'])))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveProposalMilestone::class)->addFinalReport($proposal, [
            'planned_start_date' => '2026-12-01',
            'responsible_employee_id' => $department['employee'],
            'department_id' => $department['department'],
        ]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('milestones', 0);
    $this->assertDatabaseCount('tasks', 0);
});

test('a milestone outside the proposal period is refused', function (array $dates, string $field) {
    $proposal = proposalPlanProposal();
    $this->actingAs(userWithRole('Public Relations Manager'));

    $errors = proposalPlanValidationErrors(fn () => app(SaveProposalMilestone::class)->handle($proposal, null, proposalPlanMilestoneInput($dates)));

    expect($errors)->toBe([$field => [__('milestones.milestone_outside_period')]]);
    $this->assertDatabaseCount('milestones', 0);
})->with([
    'starts before it' => [['planned_start_date' => '2025-12-31', 'planned_end_date' => '2026-02-01'], 'planned_start_date'],
    'ends after it' => [['planned_start_date' => '2026-12-01', 'planned_end_date' => '2027-01-15'], 'planned_end_date'],
]);

test('another proposal\'s milestones and tasks cannot be reached through this proposal', function () {
    $manager = userWithRole('Public Relations Manager');
    $proposal = proposalPlanProposal();
    $other = proposalPlanProposal(name: 'Other Response');
    $foreignMilestone = Milestone::factory()->forProposal($other)->create(['name' => 'Foreign gate']);
    $foreignTask = Task::factory()->forProposal($other)->create(['title' => 'Foreign task', 'created_by' => $manager->id]);
    $foreignAssignee = $foreignTask->taskAssignees()->value('employee_id');
    $department = proposalPlanDepartment();
    $this->actingAs($manager);
    $milestones = app(SaveProposalMilestone::class);
    $tasks = app(SaveDepartmentTask::class);

    expect(fn () => $milestones->handle($proposal, $foreignMilestone->id, proposalPlanMilestoneInput(['name' => 'Hijacked'])))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => $tasks->handle($proposal, $foreignTask->id, proposalPlanTaskInput($department['department'], ['title' => 'Hijacked'])))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => $tasks->handle($proposal, null, proposalPlanTaskInput($department['department'], ['milestone_id' => $foreignMilestone->id])))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => $tasks->assign($proposal, $foreignTask->id, null))
        ->toThrow(ModelNotFoundException::class)
        ->and(proposalPlanValidationErrors(fn () => $milestones->handle($proposal, null, proposalPlanMilestoneInput(['parent_milestone_id' => $foreignMilestone->id]))))
        ->toHaveKey('parent_milestone_id');

    expect($foreignMilestone->fresh()->name)->toBe('Foreign gate')
        ->and($foreignTask->fresh()->title)->toBe('Foreign task')
        ->and($foreignTask->taskAssignees()->pluck('employee_id')->all())->toBe([$foreignAssignee]);
    $this->assertDatabaseCount('milestones', 1);
    $this->assertDatabaseCount('tasks', 1);
});

test('a center-specific milestone must name one of the proposal\'s linked projects', function () {
    $proposal = proposalPlanProposal();
    $linked = Project::factory()->create();
    $proposal->projects()->attach($linked->id, ['target_beneficiaries' => 100]);
    $unlinked = Project::factory()->create();
    $this->actingAs(userWithRole('Public Relations Manager'));
    $action = app(SaveProposalMilestone::class);

    $errors = proposalPlanValidationErrors(fn () => $action->handle($proposal, null, proposalPlanMilestoneInput(['project_id' => $unlinked->id])));
    $milestone = $action->handle($proposal, null, proposalPlanMilestoneInput(['project_id' => $linked->id]));

    expect($errors)->toBe(['project_id' => [__('milestones.project_not_linked_to_proposal')]]);
    $this->assertDatabaseHas('milestones', ['id' => $milestone->id, 'proposal_id' => $proposal->id, 'project_id' => $linked->id]);
    $this->assertDatabaseCount('milestones', 1);
});

test('the final report template creates the final report milestone and its task once', function () {
    $manager = userWithRole('Public Relations Manager');
    $proposal = proposalPlanProposal();
    $publicRelations = proposalPlanDepartment('Public Relations');
    $input = [
        'planned_start_date' => '2026-12-01',
        'planned_end_date' => '2026-12-20',
        'responsible_employee_id' => $publicRelations['employee'],
        'department_id' => $publicRelations['department'],
    ];
    $this->actingAs($manager);
    $action = app(SaveProposalMilestone::class);

    $milestone = $action->addFinalReport($proposal, $input);
    $errors = proposalPlanValidationErrors(fn () => $action->addFinalReport($proposal, $input));

    $this->assertDatabaseHas('milestones', [
        'id' => $milestone->id,
        'proposal_id' => $proposal->id,
        'project_id' => null,
        'is_final_report' => true,
        'name' => __('milestones.final_report_milestone_name'),
    ]);
    $this->assertDatabaseHas('tasks', [
        'source_type' => 'milestone',
        'source_id' => $milestone->id,
        'department_id' => $publicRelations['department'],
        'kind' => 'general',
        'title' => __('department_tasks.final_report_task_title'),
        'requested_on' => '2026-03-01',
        'due_date' => '2026-12-20',
        'created_by' => $manager->id,
    ]);
    expect($errors)->toBe(['final_report' => [__('milestones.final_report_exists')]]);
    $this->assertDatabaseCount('milestones', 1);
    $this->assertDatabaseCount('tasks', 1);
});

test('the ordinary milestone form never marks a final report', function () {
    $proposal = proposalPlanProposal();
    $this->actingAs(userWithRole('Public Relations Manager'));

    $milestone = app(SaveProposalMilestone::class)->handle($proposal, null, proposalPlanMilestoneInput(['is_final_report' => true]));

    $this->assertDatabaseHas('milestones', ['id' => $milestone->id, 'is_final_report' => false]);
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

test('deleting a proposal plan removes its milestones, tasks and files and nothing else', function () {
    Storage::fake('local');
    $proposal = proposalPlanProposal();
    $other = proposalPlanProposal(name: 'Other Response');
    $parent = Milestone::factory()->forProposal($proposal)->create();
    $child = Milestone::factory()->forProposal($proposal)->create(['parent_milestone_id' => $parent->id]);
    $proposalTask = Task::factory()->forProposal($proposal)->create();
    $subtask = Task::factory()->subtaskOf($proposalTask)->create();
    $milestoneTask = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $child->id]);
    Storage::disk('local')->put('task-attachments/plan.pdf', 'plan');
    TaskAttachment::factory()->create(['task_id' => $milestoneTask->id, 'path' => 'task-attachments/plan.pdf', 'original_name' => 'plan.pdf']);
    $otherMilestone = Milestone::factory()->forProposal($other)->create();
    $otherTask = Task::factory()->forProposal($other)->create();
    $this->actingAs(userWithRole('Financial'));

    app(DeleteProposalPlan::class)->handle($proposal);

    foreach ([$parent, $child, $proposalTask, $subtask, $milestoneTask] as $deleted) {
        $this->assertModelMissing($deleted);
    }
    $this->assertModelExists($proposal);
    $this->assertModelExists($otherMilestone);
    $this->assertModelExists($otherTask);
    Storage::disk('local')->assertMissing('task-attachments/plan.pdf');
});

test('only a proposals.delete holder may delete a proposal plan', function () {
    $proposal = proposalPlanProposal();
    $milestone = Milestone::factory()->forProposal($proposal)->create();
    $this->actingAs(userWithRole('Public Relations Manager'));

    expect(fn () => app(DeleteProposalPlan::class)->handle($proposal))->toThrow(AuthorizationException::class);

    $this->assertModelExists($milestone);
});

test('a milestone cannot nest under a milestone of another proposal', function () {
    $proposal = proposalPlanProposal();
    $foreignParent = Milestone::factory()->forProposal(proposalPlanProposal(name: 'Other Response'))->create();

    expect(fn () => Milestone::factory()->forProposal($proposal)->create(['parent_milestone_id' => $foreignParent->id]))
        ->toThrow(InvalidParentException::class);
});

test('a center-specific milestone may nest under a proposal-level one but not under another center', function () {
    $proposal = proposalPlanProposal();
    [$first, $second] = Project::factory()->count(2)->create();
    $proposalLevel = Milestone::factory()->forProposal($proposal)->create();
    $firstCenter = Milestone::factory()->forProposal($proposal)->create(['project_id' => $first->id]);

    $nested = Milestone::factory()->forProposal($proposal)->create(['project_id' => $first->id, 'parent_milestone_id' => $proposalLevel->id]);

    expect($nested->parent_milestone_id)->toBe($proposalLevel->id)
        ->and(fn () => Milestone::factory()->forProposal($proposal)->create(['project_id' => $second->id, 'parent_milestone_id' => $firstCenter->id]))
        ->toThrow(InvalidParentException::class);
});

test('proposal-level milestones are numbered within their proposal', function () {
    $proposal = proposalPlanProposal();
    $other = proposalPlanProposal(name: 'Other Response');

    $first = Milestone::factory()->forProposal($proposal)->create();
    $second = Milestone::factory()->forProposal($proposal)->create();
    $otherFirst = Milestone::factory()->forProposal($other)->create();

    expect([$first->sequence, $second->sequence, $otherFirst->sequence])->toBe([1, 2, 1]);
});

test('a proposal-level milestone is shown under its proposal on the milestone pages', function (string $view) {
    $user = User::factory()->role('General Manager')->create();
    $proposal = proposalPlanProposal(name: 'Winter Response');
    Milestone::factory()->forProposal($proposal)->create(['name' => 'Donor kickoff']);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', $view)
        ->assertSee('Winter Response')
        ->assertSee('Donor kickoff');
})->with(['list', 'timeline', 'board']);

test('a proposal-level milestone is shown on the milestone calendar', function () {
    $user = User::factory()->role('General Manager')->create();
    Milestone::factory()->forProposal(proposalPlanProposal())->create(['name' => 'Donor kickoff']);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'calendar')
        ->set('month', '2026-01')
        ->assertSee('Donor kickoff');
});

test('a proposal-level milestone and its tasks open with the proposal named', function () {
    $user = User::factory()->role('General Manager')->create();
    $proposal = proposalPlanProposal(name: 'Winter Response');
    $milestone = Milestone::factory()->forProposal($proposal)->create(['name' => 'Donor kickoff']);
    $task = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id, 'title' => 'Book the venue']);

    Livewire::actingAs($user)
        ->test('pages::milestones.show', ['milestone' => $milestone])
        ->assertSee('Donor kickoff')
        ->assertSee('Winter Response');
    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->assertSee('Book the venue')
        ->assertSee('Donor kickoff · Winter Response');
});

test('the dashboard names the proposal of a late proposal-level milestone and its task', function () {
    $proposal = proposalPlanProposal(name: 'Winter Response');
    $milestone = Milestone::factory()->forProposal($proposal)->create(['name' => 'Donor kickoff', 'planned_end_date' => '2026-02-01']);
    Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id, 'title' => 'Book the venue', 'due_date' => '2026-02-10']);

    $contexts = collect(Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::dashboard.index')
        ->instance()
        ->attentionItems)->pluck('context', 'title');

    expect($contexts->get('Donor kickoff'))->toBe(__('dashboard.milestone_in', ['project' => 'Winter Response']))
        ->and($contexts->get('Book the venue'))->toBe(__('dashboard.task_in', ['parent' => 'Winter Response']));
});

test('the calendar day detail names the proposal of a proposal-level milestone', function () {
    $employeeId = insertHrEmployee();
    Milestone::factory()->forProposal(proposalPlanProposal(name: 'Winter Response'))->create([
        'name' => 'Donor kickoff',
        'responsible_user_id' => $employeeId,
    ]);

    $items = Livewire::actingAs(userWithRole('Managers', $employeeId))
        ->test('pages::calendar.index')
        ->set('month', '2026-01')
        ->call('openDayDetail', '2026-01-01')
        ->instance()
        ->dayDetailItems;

    expect($items->firstWhere('title', 'Donor kickoff')['subLabel'] ?? null)->toBe('Winter Response');
});

test('a proposal-level milestone is offered as a task source labelled with its proposal', function () {
    Milestone::factory()->forProposal(proposalPlanProposal(name: 'Winter Response'))->create(['name' => 'Donor kickoff']);

    $options = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::tasks.index')
        ->set('taskSourceType', 'milestone')
        ->instance()
        ->sourceOptions;

    expect($options->pluck('name')->all())->toBe(['Donor kickoff · Winter Response']);
});

test('a proposal milestone gets its related milestones on the plan, not on its own page', function () {
    $user = User::factory()->role('General Manager')->create();
    $milestone = Milestone::factory()->forProposal(proposalPlanProposal())->create();

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'list')
        ->assertSee($milestone->name)
        ->assertDontSee('data-test="milestone-add-nested-'.$milestone->id.'"', false);
    Livewire::actingAs($user)
        ->test('pages::milestones.show', ['milestone' => $milestone])
        ->assertDontSee('data-test="add-related-milestone-button"', false)
        ->call('openRelatedModal')
        ->assertForbidden();
});

test('a project milestone is never offered a parent from a proposal plan', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $milestone = Milestone::factory()->create(['project_id' => $project->id]);
    $sibling = Milestone::factory()->create(['project_id' => $project->id]);
    $centerSpecific = Milestone::factory()->forProposal(proposalPlanProposal())->create(['project_id' => $project->id]);

    $component = Livewire::actingAs($user)->test('pages::milestones.show', ['milestone' => $milestone]);
    $offered = $component->instance()->parentOptions->modelKeys();
    $component->set('parentMilestoneId', (string) $centerSpecific->id)->call('updateMilestone');

    expect($offered)->toBe([$sibling->id]);
    $component->assertHasErrors(['parentMilestoneId']);
    expect($milestone->fresh()->parent_milestone_id)->toBeNull();
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

test('the plan tab groups the milestones and the media, finance and other department tasks', function () {
    $proposal = proposalPlanProposal();
    $media = proposalPlanDepartment('Media');
    $finance = proposalPlanDepartment('Finance');
    $this->actingAs($manager = userWithRole('Public Relations Manager'));

    $milestone = app(SaveProposalMilestone::class)->handle($proposal, null, proposalPlanMilestoneInput(['name' => 'Baseline survey']));
    app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($media['department'], [
        'title' => 'Film the distribution',
        'kind' => TaskKind::MediaDeliverable->value,
        'media_kinds' => [DeliverableKind::Video->value],
        'milestone_id' => $milestone->id,
    ]));
    app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($finance['department'], ['title' => 'Open the project ledger', 'kind' => TaskKind::Financial->value]));
    app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($media['department'], ['title' => 'Book the venue']));

    $component = Livewire::actingAs($manager)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'plan');

    expect($component->instance()->planGroups['media']->pluck('title')->all())->toBe(['Film the distribution'])
        ->and($component->instance()->planGroups['finance']->pluck('title')->all())->toBe(['Open the project ledger'])
        ->and($component->instance()->planGroups['other']->pluck('title')->all())->toBe(['Book the venue']);

    $component->assertSeeHtml('data-plan-milestone="'.$milestone->id.'"')
        ->assertSeeText('Baseline survey')
        ->assertSeeHtml('data-department="'.$media['department'].'"')
        ->assertSeeHtml('data-test="add-plan-milestone-button"')
        ->assertSeeHtml('data-test="add-final-report-button"');

    $rows = collect($component->instance()->planGanttPayload['rows']);

    expect($rows->pluck('id')->all())->toContain('plan-'.$proposal->id, 'plan-milestone-'.$milestone->id)
        ->and($rows->every(fn (array $row): bool => $row['editable'] === false))->toBeTrue();
});

test('a milestone and the final report are added through the plan tab forms', function () {
    $proposal = proposalPlanProposal();
    $pr = proposalPlanDepartment('Public Relations');
    $responsible = insertHrEmployee();

    $page = Livewire::actingAs(userWithRole('Public Relations Manager'))
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'plan')
        ->call('openPlanMilestoneForm')
        ->assertSet('showPlanMilestoneModal', true)
        ->set('planMilestone.name', 'Inception workshop')
        ->set('planMilestone.planned_start_date', '2026-03-10')
        ->set('planMilestone.responsible_employee_id', (string) $responsible)
        ->call('savePlanMilestone')
        ->assertHasNoErrors()
        ->assertSet('showPlanMilestoneModal', false)
        ->call('openFinalReportForm')
        ->set('finalReport.planned_start_date', '2026-12-01')
        ->set('finalReport.planned_end_date', '2026-12-20')
        ->set('finalReport.responsible_employee_id', (string) $responsible)
        ->set('finalReport.department_id', (string) $pr['department'])
        ->call('saveFinalReport')
        ->assertHasNoErrors()
        ->assertSet('showFinalReportModal', false)
        ->assertSeeHtml('data-final-report')
        ->assertDontSeeHtml('data-test="add-final-report-button"');

    expect(Milestone::query()->where('proposal_id', $proposal->id)->where('is_final_report', true)->count())->toBe(1);

    $page->call('openFinalReportForm')
        ->set('finalReport.planned_start_date', '2026-12-01')
        ->set('finalReport.responsible_employee_id', (string) $responsible)
        ->set('finalReport.department_id', (string) $pr['department'])
        ->call('saveFinalReport')
        ->assertHasErrors('finalReport');

    expect(Milestone::query()->where('proposal_id', $proposal->id)->where('is_final_report', true)->count())->toBe(1);
});

test('a milestone outside the period shows the message on its field', function () {
    Livewire::actingAs(userWithRole('Public Relations Manager'))
        ->test('pages::proposals.show', ['proposal' => proposalPlanProposal()])
        ->set('tab', 'plan')
        ->call('openPlanMilestoneForm')
        ->set('planMilestone.name', 'Too late')
        ->set('planMilestone.planned_start_date', '2027-02-01')
        ->set('planMilestone.responsible_employee_id', (string) insertHrEmployee())
        ->call('savePlanMilestone')
        ->assertHasErrors(['planMilestone.planned_start_date'])
        ->assertSee(__('milestones.milestone_outside_period'));

    expect(Milestone::query()->where('name', 'Too late')->exists())->toBeFalse();
});

test('the plan tab is read-only once submitted and says why', function () {
    Livewire::actingAs(userWithRole('Public Relations Manager'))
        ->test('pages::proposals.show', ['proposal' => proposalPlanProposal(ProposalStatus::Submitted)])
        ->set('tab', 'plan')
        ->assertSeeHtml('data-plan-read-only')
        ->assertSeeText(__('milestones.plan_window_locked'))
        ->assertDontSeeHtml('data-test="add-plan-milestone-button"')
        ->assertDontSeeHtml('data-test="add-plan-task-media"');
});

test('a user without plan rights is refused by the plan forms and nothing is written', function () {
    $proposal = proposalPlanProposal();
    $department = proposalPlanDepartment();
    $viewer = userWithRole('Media Team');

    Livewire::actingAs($viewer)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('planMilestone.name', 'Sneaky milestone')
        ->set('planMilestone.planned_start_date', '2026-03-10')
        ->set('planMilestone.responsible_employee_id', (string) $department['employee'])
        ->call('savePlanMilestone')
        ->assertForbidden();

    Livewire::actingAs($viewer)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('planTask.title', 'Sneaky task')
        ->set('planTask.department_id', (string) $department['department'])
        ->set('planTask.due_date', '2026-03-20')
        ->call('savePlanTask')
        ->assertForbidden();

    expect(Milestone::query()->where('name', 'Sneaky milestone')->exists())->toBeFalse()
        ->and(Task::query()->withoutGlobalScopes()->where('title', 'Sneaky task')->exists())->toBeFalse();
});

test('another proposal\'s milestone or task cannot be opened through this page', function () {
    $proposal = proposalPlanProposal();
    $other = proposalPlanProposal(name: 'Other grant');
    $department = proposalPlanDepartment();
    $this->actingAs($manager = userWithRole('Public Relations Manager'));

    $foreignMilestone = app(SaveProposalMilestone::class)->handle($other, null, proposalPlanMilestoneInput(['name' => 'Foreign milestone']));
    $foreignTask = app(SaveDepartmentTask::class)->handle($other, null, proposalPlanTaskInput($department['department'], ['title' => 'Foreign task']));

    Livewire::actingAs($manager)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->call('openPlanMilestoneForm', $foreignMilestone->id)
        ->assertStatus(404);

    Livewire::actingAs($manager)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->call('openPlanTaskForm', $foreignTask->id)
        ->assertStatus(404);

    Livewire::actingAs($manager)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->call('openAssignModal', $foreignTask->id)
        ->assertStatus(404);

    expect($foreignMilestone->fresh()->name)->toBe('Foreign milestone')
        ->and($foreignTask->fresh()->title)->toBe('Foreign task');
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
    $milestone = app(SaveProposalMilestone::class)->handle($proposal, null, proposalPlanMilestoneInput());

    $countQueries = function () use ($manager, $proposal): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($manager)->test('pages::proposals.show', ['proposal' => $proposal])->set('tab', 'plan');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return count($queries);
    };

    app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($media['department'], ['milestone_id' => $milestone->id, 'assignee_employee_id' => $media['employee']]));
    $countQueries();
    $small = $countQueries();

    foreach (range(1, 12) as $index) {
        app(SaveDepartmentTask::class)->handle($proposal, null, proposalPlanTaskInput($media['department'], ['title' => 'Task '.$index, 'milestone_id' => $milestone->id, 'assignee_employee_id' => $media['employee']]));
    }

    expect($countQueries())->toBe($small);
});

test('the milestone page edits a plan milestone through the plan window and only its status once locked', function () {
    $proposal = proposalPlanProposal();
    $this->actingAs($manager = userWithRole('General Manager'));
    $milestone = app(SaveProposalMilestone::class)->handle($proposal, null, proposalPlanMilestoneInput());

    Livewire::actingAs($manager)
        ->test('pages::milestones.show', ['milestone' => $milestone])
        ->set('plannedStartDate', '2027-02-01')
        ->set('plannedEndDate', '2027-02-10')
        ->call('updateMilestone')
        ->assertHasErrors(['plannedStartDate']);

    expect($milestone->fresh()->planned_start_date->toDateString())->toBe('2026-03-01');

    Livewire::actingAs($manager)
        ->test('pages::milestones.show', ['milestone' => $milestone])
        ->set('name', 'Community consultations, round 2')
        ->call('updateMilestone')
        ->assertHasNoErrors();

    expect($milestone->fresh()->name)->toBe('Community consultations, round 2');

    Proposal::query()->whereKey($proposal->id)->update(['status' => ProposalStatus::Submitted->value]);

    Livewire::actingAs($manager)
        ->test('pages::milestones.show', ['milestone' => $milestone])
        ->set('name', 'Edited while locked')
        ->call('updateMilestone')
        ->assertHasNoErrors();

    expect($milestone->fresh()->name)->toBe('Community consultations, round 2');
});
