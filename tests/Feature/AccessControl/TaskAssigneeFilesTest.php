<?php

use App\Models\Task;
use App\Models\TaskAttachment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| The person a task is assigned to hands in the file it asks for, even when
| their role cannot edit tasks (a Team Member on a PR Manager's task,
| 2026-11-16). They still cannot edit the task's other fields.
*/

test('an assignee without tasks.update uploads a file on their task', function () {
    Storage::fake('local');
    $employeeId = insertHrEmployee();
    $assignee = userWithRole('Team Member', $employeeId);

    $task = Task::factory()->create(['title' => 'Send the budget']);
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $employeeId]);

    expect($assignee->can('tasks.update'))->toBeFalse()
        ->and(Gate::forUser($assignee->fresh())->allows('update', $task))->toBeFalse();

    Livewire::actingAs($assignee)->test('pages::tasks.show', ['task' => $task])
        ->assertSeeHtml('data-test="create-file-button"')
        ->call('openCreateFileModal')
        ->set('newFile', validUpload('budget.pdf'))
        ->call('saveFile')
        ->assertHasNoErrors();

    expect(TaskAttachment::query()->where('task_id', $task->id)->count())->toBe(1)
        ->and($task->fresh()->title)->toBe('Send the budget');
});

test('a Team Member cannot manage files on a task not assigned to them', function () {
    $teamMember = userWithRole('Team Member', insertHrEmployee());
    $task = Task::factory()->create();

    expect(Gate::forUser($teamMember->fresh())->allows('manageFiles', $task))->toBeFalse();
});
