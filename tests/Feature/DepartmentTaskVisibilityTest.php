<?php

use App\Models\Proposal;
use App\Models\Role;
use App\Models\Scopes\TaskVisibilityScope;
use App\Models\Task;
use App\Models\User;

/**
 * An HR employee holding an active contract in the given department.
 */
function departmentVisibilityEmployee(int $departmentId): int
{
    $employeeId = insertHrEmployee();
    insertHrContract($employeeId, insertHrPosition($departmentId));

    return $employeeId;
}

/**
 * A pre-T31 task (no department) assigned to the given employee only.
 */
function departmentVisibilityLegacyTask(int $assigneeEmployeeId): Task
{
    $task = Task::factory()->create();
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $assigneeEmployeeId]);

    return $task;
}

test('a Media Team employee sees the tasks handed to their department', function () {
    $media = insertHrDepartment('Media');
    $mediaUser = userWithRole('Media Team', departmentVisibilityEmployee($media));
    $proposal = Proposal::factory()->create();
    $general = Task::factory()->forProposal($proposal, $media)->create();
    $deliverable = Task::factory()->forProposal($proposal, $media)->mediaDeliverable()->create();
    Task::factory()->forProposal($proposal, insertHrDepartment('Programs'))->create();

    $this->actingAs($mediaUser);

    expect(Task::query()->pluck('id')->all())->toEqualCanonicalizing([$general->id, $deliverable->id])
        ->and($mediaUser->can('view', $deliverable))->toBeTrue();
});

test('an employee of another department sees none of a department\'s tasks', function () {
    $media = insertHrDepartment('Media');
    $outsider = userWithRole('Team Member', departmentVisibilityEmployee(insertHrDepartment('Programs')));
    $task = Task::factory()->forProposal(Proposal::factory()->create(), $media)->create();

    $this->actingAs($outsider);

    expect(Task::query()->pluck('id')->all())->toBe([])
        ->and($outsider->can('view', $task))->toBeFalse();
});

test('financial tasks stay hidden from Media in their own department and when assigned to them', function () {
    $media = insertHrDepartment('Media');
    $mediaEmployee = departmentVisibilityEmployee($media);
    $mediaUser = userWithRole('Media Team', $mediaEmployee);
    $proposal = Proposal::factory()->create();
    $ownDepartmentFinancial = Task::factory()->forProposal($proposal, $media)->financial()->create();
    $assignedFinancial = Task::factory()->forProposal($proposal, insertHrDepartment('Finance'))->financial()->create();
    $assignedFinancial->taskAssignees()->delete();
    $assignedFinancial->taskAssignees()->create(['employee_id' => $mediaEmployee]);

    $this->actingAs($mediaUser);

    expect(Task::query()->pluck('id')->all())->toBe([])
        ->and($mediaUser->can('view', $ownDepartmentFinancial))->toBeFalse()
        ->and($mediaUser->can('view', $assignedFinancial))->toBeFalse();
});

test('a Financial user sees their department\'s financial task by role alone', function () {
    $finance = insertHrDepartment('Finance');
    $financeUser = userWithRole('Financial', departmentVisibilityEmployee($finance));
    Role::findByName('Financial', 'web')->revokePermissionTo('proposals.update');
    $financeUser = $financeUser->fresh();
    $task = Task::factory()->forProposal(Proposal::factory()->create(), $finance)->financial()->create();

    $this->actingAs($financeUser);

    expect(Task::query()->pluck('id')->all())->toBe([$task->id])
        ->and($financeUser->can('view', $task))->toBeTrue();
});

test('a proposal editor sees every department\'s tasks but no other department\'s legacy task', function () {
    $media = insertHrDepartment('Media');
    $editor = userWithRole('Public Relations Manager', departmentVisibilityEmployee(insertHrDepartment('Public Relations')));
    $proposal = Proposal::factory()->create();
    $mediaTask = Task::factory()->forProposal($proposal, $media)->mediaDeliverable()->create();
    $financialTask = Task::factory()->forProposal($proposal, insertHrDepartment('Finance'))->financial()->create();
    $legacyTask = departmentVisibilityLegacyTask(departmentVisibilityEmployee($media));

    $this->actingAs($editor);

    expect(Task::query()->pluck('id')->all())->toEqualCanonicalizing([$mediaTask->id, $financialTask->id])
        ->and($editor->can('view', $legacyTask))->toBeFalse();
});

test('a bypass role still sees every task', function () {
    $media = insertHrDepartment('Media');
    $manager = userWithRole('General Manager', departmentVisibilityEmployee(insertHrDepartment('Management')));
    $proposal = Proposal::factory()->create();
    $mediaTask = Task::factory()->forProposal($proposal, $media)->create();
    $financialTask = Task::factory()->forProposal($proposal, insertHrDepartment('Finance'))->financial()->create();
    $legacyTask = departmentVisibilityLegacyTask(departmentVisibilityEmployee($media));

    $this->actingAs($manager);

    expect(Task::query()->pluck('id')->all())->toEqualCanonicalizing([$mediaTask->id, $financialTask->id, $legacyTask->id]);
});

test('a roleless user\'s task visibility is unchanged', function () {
    $media = insertHrDepartment('Media');
    $roleless = User::factory()->create(['employee_id' => departmentVisibilityEmployee(insertHrDepartment('Programs'))]);
    $proposal = Proposal::factory()->create();
    $mediaTask = Task::factory()->forProposal($proposal, $media)->create();
    $financialTask = Task::factory()->forProposal($proposal, insertHrDepartment('Finance'))->financial()->create();
    $legacyTask = departmentVisibilityLegacyTask(departmentVisibilityEmployee($media));

    $this->actingAs($roleless);

    expect(Task::query()->pluck('id')->all())->toEqualCanonicalizing([$mediaTask->id, $financialTask->id, $legacyTask->id]);
});

test('constrain() and allows() agree for every task and user', function () {
    $media = insertHrDepartment('Media');
    $finance = insertHrDepartment('Finance');
    $mediaEmployee = departmentVisibilityEmployee($media);
    $proposal = Proposal::factory()->create();
    $tasks = [
        Task::factory()->forProposal($proposal, $media)->create(),
        Task::factory()->forProposal($proposal, $media)->mediaDeliverable()->create(),
        Task::factory()->forProposal($proposal, $media)->financial()->create(),
        Task::factory()->forProposal($proposal, $finance)->financial()->create(),
        departmentVisibilityLegacyTask($mediaEmployee),
    ];
    $users = [
        userWithRole('Media Team', $mediaEmployee),
        userWithRole('Team Member', departmentVisibilityEmployee(insertHrDepartment('Programs'))),
        userWithRole('Financial', departmentVisibilityEmployee($finance)),
        userWithRole('Public Relations Manager', departmentVisibilityEmployee(insertHrDepartment('Public Relations'))),
        userWithRole('General Manager'),
        User::factory()->create(['employee_id' => departmentVisibilityEmployee($media)]),
    ];

    foreach ($users as $user) {
        $query = Task::query()->withoutGlobalScope(TaskVisibilityScope::class);
        TaskVisibilityScope::constrain($query, $user);
        $visibleIds = $query->pluck('id')->all();

        foreach ($tasks as $task) {
            expect(TaskVisibilityScope::allows($user, $task))
                ->toBe(in_array($task->id, $visibleIds, true), "user {$user->id} ({$user->getRoleNames()->implode(',')}), task {$task->id}");
        }
    }
});
