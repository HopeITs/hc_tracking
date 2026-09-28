<?php

use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->mediaDepartmentId = insertHrDepartment('Media');
    $this->financeDepartmentId = insertHrDepartment('Finance');

    $this->mediaPositionId = insertHrPosition($this->mediaDepartmentId);
    $this->financePositionId = insertHrPosition($this->financeDepartmentId);

    $this->mediaEmployeeId = insertHrEmployee(firstNameEn: 'Media', lastNameEn: 'Person');
    insertHrContract($this->mediaEmployeeId, $this->mediaPositionId);

    $this->financeEmployeeId = insertHrEmployee(firstNameEn: 'Finance', lastNameEn: 'Person');
    insertHrContract($this->financeEmployeeId, $this->financePositionId);

    $this->mediaUser = User::factory()->role('Media Team')->create(['employee_id' => $this->mediaEmployeeId]);
    $this->financeUser = User::factory()->role('Financial')->create(['employee_id' => $this->financeEmployeeId]);
    $this->managerUser = User::factory()->role('Managers')->create();

    $this->mediaTask = Task::factory()->create(['title' => 'Media task']);
    $this->mediaTask->taskAssignees()->delete();
    $this->mediaTask->taskAssignees()->create(['employee_id' => $this->mediaEmployeeId]);

    $this->financeTask = Task::factory()->create(['title' => 'Finance task']);
    $this->financeTask->taskAssignees()->delete();
    $this->financeTask->taskAssignees()->create(['employee_id' => $this->financeEmployeeId]);
});

test('a scoped team role only sees tasks assigned within its own department by default', function () {
    Livewire::actingAs($this->mediaUser)
        ->test('pages::tasks.index')
        ->assertSee('Media task')
        ->assertDontSee('Finance task');
});

test('a scoped team role cannot open another department\'s task show page', function () {
    // Not found rather than forbidden: a hidden task's link does not even
    // confirm that the task exists.
    $this->actingAs($this->mediaUser)
        ->get(route('tasks.show', $this->financeTask))
        ->assertNotFound();

    $this->actingAs($this->mediaUser)
        ->get(route('tasks.show', $this->mediaTask))
        ->assertOk();
});

test('a bypass role sees every department by default and can filter down to one', function () {
    Livewire::actingAs($this->managerUser)
        ->test('pages::tasks.index')
        ->assertSee('Media task')->assertSee('Finance task')
        ->set('departmentId', (string) $this->mediaDepartmentId)
        ->assertSee('Media task')->assertDontSee('Finance task');
});

test('a task created in one department but assigned to another still shows for the creator\'s department', function () {
    $crossTask = Task::factory()->create(['title' => 'Cross-department task', 'created_by' => $this->mediaUser->id]);
    $crossTask->taskAssignees()->delete();
    $crossTask->taskAssignees()->create(['employee_id' => $this->financeEmployeeId]);

    Livewire::actingAs($this->mediaUser)
        ->test('pages::tasks.index')
        ->assertSee('Cross-department task');

    Livewire::actingAs($this->financeUser)
        ->test('pages::tasks.index')
        ->assertSee('Cross-department task');
});

test('my tasks stays visible to a scoped viewer even when assigned outside their department', function () {
    $assignedElsewhere = Task::factory()->create(['title' => 'Assigned to media from finance']);
    $assignedElsewhere->taskAssignees()->delete();
    $assignedElsewhere->taskAssignees()->create(['employee_id' => $this->mediaEmployeeId]);
    $assignedElsewhere->taskAssignees()->create(['employee_id' => $this->financeEmployeeId]);

    Livewire::actingAs($this->mediaUser)
        ->test('pages::tasks.index')
        ->set('myTasksOnly', true)
        ->assertSee('Assigned to media from finance')
        ->assertDontSee('Finance task');
});

test('the named team roles were granted tasks permissions', function () {
    foreach (['Media Team', 'Public Relations', 'Public Relations Manager', 'Financial'] as $roleName) {
        $user = User::factory()->role($roleName)->create();

        expect($user->can('tasks.view'))->toBeTrue()
            ->and($user->can('tasks.create'))->toBeTrue()
            ->and($user->can('tasks.update'))->toBeTrue();
    }
});
