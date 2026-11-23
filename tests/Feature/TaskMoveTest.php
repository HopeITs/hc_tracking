<?php

use App\Actions\Tasks\MoveTask;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
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

test('a task moves onto a location row of the same program (Y-12)', function () {
    $proposal = Proposal::factory()->create();
    $row = ProposalLocation::factory()->create([
        'proposal_id' => $proposal->id,
        'program_id' => $this->program->id,
        'city_wide' => true,
    ]);

    $move = app(MoveTask::class);

    expect($move->resolveTarget($this->program->id, 'location:'.$row->id))
        ->toBe(['type' => 'proposal_location', 'id' => $row->id])
        ->and($move->targetFor('proposal_location', $row->id))->toBe('location:'.$row->id)
        ->and($move->programIdOfSource('proposal_location', $row->id))->toBe($this->program->id);

    $this->actingAs($this->manager);
    $move->handle($this->task, 'location:'.$row->id);

    // The subtask follows its parent, as it always has.
    expect($this->task->fresh()->source_type)->toBe('proposal_location')
        ->and((int) $this->task->fresh()->source_id)->toBe($row->id)
        ->and($this->subtask->fresh()->source_type)->toBe('proposal_location')
        ->and((int) $this->subtask->fresh()->source_id)->toBe($row->id);
});

test('a row of another program is refused as a move target', function () {
    $otherProgram = Program::factory()->create();
    $proposal = Proposal::factory()->create();
    $foreignRow = ProposalLocation::factory()->create([
        'proposal_id' => $proposal->id,
        'program_id' => $otherProgram->id,
        'city_wide' => true,
    ]);

    expect(app(MoveTask::class)->resolveTarget($this->program->id, 'location:'.$foreignRow->id))->toBeNull();
});
