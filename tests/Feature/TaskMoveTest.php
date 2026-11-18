<?php

use App\Actions\Tasks\MoveTask;
use App\Models\Program;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/*
| "Move to" on the task page: a top-level task, with its subtasks, moves to
| the program itself or one of its projects — never outside its program.
*/

beforeEach(function () {
    $this->program = Program::factory()->create();
    $this->project = Project::factory()->create(['program_id' => $this->program->id, 'name' => 'Wells Project']);
    $this->otherProject = Project::factory()->create(['program_id' => $this->program->id, 'name' => 'Pumps Project']);
    $this->manager = userWithRole('General Manager', insertHrEmployee());
    $this->task = Task::factory()->create(['source_type' => 'project', 'source_id' => $this->project->id]);
    $this->subtask = Task::factory()->create(['source_type' => 'project', 'source_id' => $this->project->id, 'parent_task_id' => $this->task->id]);
});

test('the task page lists every place in the program and marks the current one', function () {
    Livewire::actingAs($this->manager)
        ->test('pages::tasks.show', ['task' => $this->task])
        ->assertSeeHtml('data-test="task-move-button"')
        ->call('openMoveModal')
        ->assertSet('showMoveModal', true)
        ->assertSet('moveTarget', 'project:'.$this->project->id)
        ->assertSeeHtml('data-move-target="program"')
        ->assertSeeHtml('data-move-target="project:'.$this->otherProject->id.'"')
        ->assertSee(['Wells Project', 'Pumps Project', 'Current']);
});

test('a task never moves outside its program', function () {
    $foreignProject = Project::factory()->create();

    Livewire::actingAs($this->manager)
        ->test('pages::tasks.show', ['task' => $this->task])
        ->call('openMoveModal')
        ->set('moveTarget', 'project:'.$foreignProject->id)
        ->call('moveTask')
        ->assertSet('showMoveModal', true);

    expect($this->task->fresh()->only(['source_type', 'source_id']))->toBe(['source_type' => 'project', 'source_id' => $this->project->id]);
});

test('a subtask offers no move: it follows its parent', function () {
    Livewire::actingAs($this->manager)
        ->test('pages::tasks.show', ['task' => $this->subtask])
        ->assertDontSeeHtml('data-test="task-move-button"')
        ->set('moveTarget', 'program')
        ->call('moveTask');

    expect($this->subtask->fresh()->source_type)->toBe('project');
});

test('someone who cannot edit the task cannot move it', function () {
    $this->actingAs(User::factory()->create());

    expect(fn () => app(MoveTask::class)->handle($this->task, 'program'))->toThrow(AuthorizationException::class)
        ->and($this->task->fresh()->source_type)->toBe('project');
});
