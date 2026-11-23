<?php

use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Scopes\TaskVisibilityScope;
use App\Models\Task;
use App\Models\TaskAttachment;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function () {
    $this->mediaDepartment = $media = insertHrDepartment('Media');
    $finance = insertHrDepartment('Finance');
    $this->mediaEmployee = insertHrEmployee(firstNameEn: 'Media', lastNameEn: 'Person');
    insertHrContract($this->mediaEmployee, insertHrPosition($media));
    $this->financeEmployee = insertHrEmployee(firstNameEn: 'Finance', lastNameEn: 'Person');
    insertHrContract($this->financeEmployee, insertHrPosition($finance));

    $this->member = userWithRole('Team Member', $this->mediaEmployee);

    $this->project = Project::factory()->create();

    $this->financeTask = Task::factory()->create([
        'title' => 'Finance-only task',
        'source_type' => 'project',
        'source_id' => $this->project->id,
        'due_date' => now()->subDays(2),
    ]);
    $this->financeTask->taskAssignees()->delete();
    $this->financeTask->taskAssignees()->create(['employee_id' => $this->financeEmployee]);
});

test('a hidden task is not found, not forbidden', function () {
    $this->actingAs($this->member)->get(route('tasks.show', $this->financeTask))->assertNotFound();
});

test('a hidden task file cannot be downloaded', function () {
    $attachment = TaskAttachment::factory()->create(['task_id' => $this->financeTask->id]);

    $this->actingAs($this->member)->get(route('tasks.files.download', [$this->financeTask, $attachment]))->assertNotFound();
});

test('the desk lists no hidden task', function () {
    $this->actingAs($this->member)->get(route('dashboard'))->assertOk()->assertDontSee('Finance-only task');
    $this->actingAs(userWithRole('Managers'))->get(route('tasks.index'))->assertOk()->assertSee('Finance-only task');
});

test('the calendar never shows another department\'s tasks, whoever is picked', function () {
    $this->financeTask->update(['due_date' => '2026-06-15']);
    $marker = 'data-test="calendar-task-'.$this->financeTask->id.'"';

    // The calendar only draws its grid for an account linked to an HR employee.
    Livewire::actingAs(userWithRole('Managers', insertHrEmployee()))->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->set('filterEmployeeIds', [(string) $this->financeEmployee])
        ->assertSee($marker, false);

    Livewire::actingAs($this->member)->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->set('filterEmployeeIds', [(string) $this->financeEmployee])
        ->assertDontSee($marker, false);
});

test('the project page hides other departments\' tasks', function () {
    Livewire::actingAs($this->member)->test('pages::projects.show', ['project' => $this->project])
        ->set('tab', 'tasks')
        ->assertDontSee('Finance-only task');
});

test('a project owner sees every task in their own project', function () {
    $this->project->update(['user_id' => $this->mediaEmployee]);

    Livewire::actingAs($this->member->fresh())->test('pages::projects.show', ['project' => $this->project])
        ->set('tab', 'tasks')
        ->assertSee('Finance-only task');
});

test('a manager sees every task', function () {
    Livewire::actingAs(userWithRole('Managers'))->test('pages::projects.show', ['project' => $this->project])
        ->set('tab', 'tasks')
        ->assertSee('Finance-only task');
});

test('a scoped user with no department still sees their own tasks', function () {
    $loner = userWithRole('Team Member');
    Task::factory()->create(['title' => 'My own task', 'created_by' => $loner->id]);

    Livewire::actingAs($loner)->test('pages::tasks.index')->assertSee('My own task');
});

test('proposal progress counts every task, including hidden ones', function () {
    $proposal = Proposal::factory()->create();
    $this->financeTask->update(['source_type' => 'proposal', 'source_id' => $proposal->id]);

    $row = Livewire::actingAs(userWithRole('Public Relations Manager', $this->mediaEmployee))->test('pages::proposals.index')->instance()->proposals->firstWhere('id', $proposal->id);

    expect($row->tasks_count)->toBe(1);
});

test('a Media Team editor moves status on department tasks but cannot edit them, and only views owned-project tasks', function () {
    $editor = userWithRole('Media Team', $this->mediaEmployee);
    $this->project->update(['user_id' => $this->mediaEmployee]);
    $colleague = insertHrEmployee(firstNameEn: 'Media', lastNameEn: 'Colleague');
    insertHrContract($colleague, insertHrPosition($this->mediaDepartment));
    $departmentTask = Task::factory()->create(['title' => 'Media department task']);
    $departmentTask->taskAssignees()->delete();
    $departmentTask->taskAssignees()->create(['employee_id' => $colleague]);
    $gate = Gate::forUser($editor->fresh());

    expect($gate->allows('update', $departmentTask))->toBeFalse()
        ->and($gate->allows('updateStatus', $departmentTask))->toBeTrue()
        ->and($gate->allows('view', $this->financeTask))->toBeTrue()
        ->and($gate->allows('update', $this->financeTask))->toBeFalse()
        ->and($gate->allows('updateStatus', $this->financeTask))->toBeFalse();
});

test('Managers edit tasks in every department', function () {
    expect(Gate::forUser(userWithRole('Managers'))->allows('update', $this->financeTask))->toBeTrue();
});

test('a task on a row the user is responsible for is visible (Y-12)', function () {
    $owner = userWithRole('Team Member', $this->mediaEmployee);
    $proposal = Proposal::factory()->create();
    $row = ProposalLocation::factory()->create([
        'proposal_id' => $proposal->id,
        'program_id' => Program::factory()->create()->id,
        'responsible_employee_id' => $this->mediaEmployee,
        'city_wide' => true,
    ]);

    $task = Task::factory()->create(['source_type' => 'proposal_location', 'source_id' => $row->id]);
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $this->financeEmployee]);

    // Not an assignee, not in the department: visible only because the row
    // is theirs.
    expect(TaskVisibilityScope::allows($owner, $task))->toBeTrue();
});

test('a task on a row of a program the user manages is visible (Y-12)', function () {
    $manager = userWithRole('Team Member', $this->mediaEmployee);
    $program = Program::factory()->create(['manager_id' => $this->mediaEmployee]);
    $proposal = Proposal::factory()->create();
    $row = ProposalLocation::factory()->create([
        'proposal_id' => $proposal->id,
        'program_id' => $program->id,
        'responsible_employee_id' => insertHrEmployee(),
        'city_wide' => true,
    ]);

    $task = Task::factory()->create(['source_type' => 'proposal_location', 'source_id' => $row->id]);
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $this->financeEmployee]);

    expect(TaskVisibilityScope::allows($manager, $task))->toBeTrue();
});

test('a task on someone else row stays hidden (Y-12)', function () {
    $stranger = userWithRole('Team Member', insertHrEmployee());
    $proposal = Proposal::factory()->create();
    $row = ProposalLocation::factory()->create([
        'proposal_id' => $proposal->id,
        'program_id' => Program::factory()->create()->id,
        'responsible_employee_id' => $this->mediaEmployee,
        'city_wide' => true,
    ]);

    $task = Task::factory()->create(['source_type' => 'proposal_location', 'source_id' => $row->id]);
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $this->financeEmployee]);

    expect(TaskVisibilityScope::allows($stranger, $task))->toBeFalse();
});
