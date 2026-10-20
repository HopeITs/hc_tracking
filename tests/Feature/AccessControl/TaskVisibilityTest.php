<?php

use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\TaskAttachment;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

use function Livewire\invade;

beforeEach(function () {
    $this->mediaDepartment = $media = insertHrDepartment('Media');
    $finance = insertHrDepartment('Finance');
    $this->mediaEmployee = insertHrEmployee(firstNameEn: 'Media', lastNameEn: 'Person');
    insertHrContract($this->mediaEmployee, insertHrPosition($media));
    $this->financeEmployee = insertHrEmployee(firstNameEn: 'Finance', lastNameEn: 'Person');
    insertHrContract($this->financeEmployee, insertHrPosition($finance));

    $this->member = userWithRole('Team Member', $this->mediaEmployee);

    $this->project = Project::factory()->create();
    $this->milestone = Milestone::factory()->create(['project_id' => $this->project->id]);

    $this->financeTask = Task::factory()->create([
        'title' => 'Finance-only task',
        'source_type' => 'milestone',
        'source_id' => $this->milestone->id,
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
    $this->actingAs(userWithRole('Managers'))->get(route('dashboard'))->assertOk()->assertSee('Finance-only task');
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

test('a milestone whose only task is hidden still cannot be deleted', function () {
    $page = invade(Livewire::actingAs($this->member)->test('pages::milestones.index')->instance());

    expect($page->canDelete($this->milestone->id))->toBeFalse();
});

test('a department-limited editor edits own and department tasks, and only views owned-project tasks', function () {
    $editor = userWithRole('Media Team', $this->mediaEmployee);
    $this->project->update(['user_id' => $this->mediaEmployee]);
    $colleague = insertHrEmployee(firstNameEn: 'Media', lastNameEn: 'Colleague');
    insertHrContract($colleague, insertHrPosition($this->mediaDepartment));
    $departmentTask = Task::factory()->create(['title' => 'Media department task']);
    $departmentTask->taskAssignees()->delete();
    $departmentTask->taskAssignees()->create(['employee_id' => $colleague]);
    $gate = Gate::forUser($editor->fresh());

    expect($gate->allows('update', $departmentTask))->toBeTrue()
        ->and($gate->allows('view', $this->financeTask))->toBeTrue()
        ->and($gate->allows('update', $this->financeTask))->toBeFalse()
        ->and($gate->allows('updateStatus', $this->financeTask))->toBeFalse();
});

test('Managers edit tasks in every department', function () {
    expect(Gate::forUser(userWithRole('Managers'))->allows('update', $this->financeTask))->toBeTrue();
});
