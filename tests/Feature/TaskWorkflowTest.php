<?php

use App\Actions\Tasks\ChangeTaskStatus;
use App\Actions\Tasks\CompleteTask;
use App\Enums\DependencyType;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\ScheduleDependency;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-09-16 09:00:00');
});

/**
 * A task created by $creator and assigned to the given HR employees only.
 *
 * @param  list<int>  $assigneeEmployeeIds
 * @param  array<string, mixed>  $attributes
 */
function workflowTask(User $creator, array $assigneeEmployeeIds, array $attributes = []): Task
{
    return Activity::withoutRecording(function () use ($creator, $assigneeEmployeeIds, $attributes): Task {
        $task = Task::factory()->create(['created_by' => $creator->id, 'title' => 'Map the wells', 'status' => TaskStatus::InProgress, ...$attributes]);
        $task->taskAssignees()->delete();

        foreach ($assigneeEmployeeIds as $employeeId) {
            $task->taskAssignees()->create(['employee_id' => $employeeId]);
        }

        return $task;
    });
}

/**
 * The workflow refusal for one move, or null when it went through.
 */
function moveAs(User $user, Task $task, TaskStatus $to, ?string $note = null): ?string
{
    test()->actingAs($user);

    try {
        app(ChangeTaskStatus::class)->handle($task->fresh(), $to, $note);
    } catch (ValidationException $refused) {
        return $refused->validator->errors()->first('status');
    }

    return null;
}

test('the creator who is also an assignee may mark their own task done', function () {
    $me = userWithRole('Team Member', insertHrEmployee());
    $task = workflowTask($me, [$me->employee_id]);

    expect(moveAs($me, $task, TaskStatus::Done))->toBeNull()
        ->and($task->fresh()->status)->toBe(TaskStatus::Done);
});

test('an assignee of someone else\'s task goes no further than under review, which tells the creator', function () {
    $creator = userWithRole('Managers', insertHrEmployee());
    $worker = userWithRole('Team Member', insertHrEmployee(firstNameEn: 'Omar'));
    $task = workflowTask($creator, [$worker->employee_id]);

    expect(moveAs($worker, $task, TaskStatus::Done))->toBe(__('tasks.workflow.only_approver_done'))
        ->and(moveAs($worker, $task, TaskStatus::UnderReview))->toBeNull()
        ->and(sentencesFor($creator))->toContain($worker->name.' sent “Map the wells” for your review on Sep 16, 2026');

    $this->actingAs($worker);
    expect(fn () => app(CompleteTask::class)->handle($task->fresh()))->toThrow(ValidationException::class);
});

test('the creator approves an under review task, or sends it back only with a note the assignees receive', function () {
    $creator = userWithRole('Managers', insertHrEmployee());
    $worker = userWithRole('Team Member', insertHrEmployee());
    $task = workflowTask($creator, [$worker->employee_id], ['status' => TaskStatus::UnderReview]);

    expect(moveAs($creator, $task, TaskStatus::InProgress))->toBe(__('tasks.workflow.note_required'))
        ->and(moveAs($creator, $task, TaskStatus::InProgress, 'Add the photos'))->toBeNull()
        ->and(collect(sentencesFor($worker))->filter(fn (string $line): bool => str_contains($line, 'sent “Map the wells” back: “Add the photos”')))->toHaveCount(1);

    $task->forceFill(['status' => TaskStatus::UnderReview])->save();

    expect(moveAs($creator, $task, TaskStatus::Done))->toBeNull();
});

test('done waits for open subtasks, open predecessors and required evidence', function () {
    $me = userWithRole('Team Member', insertHrEmployee());
    $task = workflowTask($me, [$me->employee_id], ['evidence_required' => true]);
    $subtask = workflowTask($me, [$me->employee_id], ['parent_task_id' => $task->id, 'title' => 'Sub']);

    expect(moveAs($me, $task, TaskStatus::Done))->toBe(trans_choice('tasks.workflow.open_subtasks', 1, ['count' => 1]));

    $subtask->forceFill(['status' => TaskStatus::Done])->save();
    $before = workflowTask($me, [$me->employee_id], ['title' => 'Buy pipes']);
    ScheduleDependency::query()->create([
        'project_id' => Project::factory()->create()->id,
        'predecessor_type' => 'task', 'predecessor_id' => $before->id,
        'successor_type' => 'task', 'successor_id' => $task->id,
        'type' => DependencyType::FinishToStart, 'lag_days' => 0,
    ]);

    expect(moveAs($me, $task, TaskStatus::Done))->toBe(__('tasks.workflow.open_dependency', ['task' => 'Buy pipes']));

    $before->forceFill(['status' => TaskStatus::Done])->save();

    expect(moveAs($me, $task, TaskStatus::Done))->toBe(__('tasks.workflow.evidence_missing'));

    TaskAttachment::factory()->create(['task_id' => $task->id]);

    expect(moveAs($me, $task, TaskStatus::Done))->toBeNull();
});

test('the task page form follows the same rules', function () {
    $creator = userWithRole('Managers', insertHrEmployee());
    $worker = userWithRole('Team Member', insertHrEmployee());
    $task = workflowTask($creator, [$worker->employee_id]);

    Livewire::actingAs($worker)->test('pages::tasks.show', ['task' => $task])
        ->set('taskStatus', TaskStatus::Done->value)
        ->call('updateTask')
        ->assertHasErrors(['taskStatus']);

    expect($task->fresh()->status)->toBe(TaskStatus::InProgress);
});

test('the desk tasks card lists mine (assigned or created), my department, and offers approve on reviews', function () {
    $department = insertHrDepartment();
    $me = userWithRole('Managers', insertHrEmployee());
    insertHrContract($me->employee_id, insertHrPosition($department));
    $colleague = insertHrEmployee();
    insertHrContract($colleague, insertHrPosition($department));
    $stranger = insertHrEmployee();
    $other = userWithRole('Managers', insertHrEmployee());

    workflowTask($me, [$stranger], ['title' => 'I asked for this', 'status' => TaskStatus::UnderReview]);
    workflowTask($other, [$me->employee_id], ['title' => 'Given to me']);
    workflowTask($other, [$colleague], ['title' => 'Colleague work']);

    $card = Livewire::actingAs($me)->test('desk.panels.tasks', ['size' => 'L'])
        ->assertSee(['I asked for this', 'Given to me'])
        ->assertDontSee('Colleague work')
        ->assertSee(__('desk.decision_action.approve'))
        ->assertSee(__('desk.tasks_send_back'));

    $card->set('scope', 'department')->assertSee('Colleague work');
});
