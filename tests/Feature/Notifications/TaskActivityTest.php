<?php

use App\Enums\TaskStatus;
use App\Jobs\FanOutActivity;
use App\Models\Activity;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\NotificationPresenter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

test('creating a task records who created it, in which program', function () {
    $actor = Activity::withoutRecording(fn () => User::factory()->create());
    [$program, $milestone] = Activity::withoutRecording(function (): array {
        $program = Program::factory()->create();
        $milestone = Milestone::factory()->create(['project_id' => Project::factory()->create(['program_id' => $program->id])->id]);

        return [$program, $milestone];
    });

    $this->actingAs($actor);
    $task = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id, 'title' => 'Plan']);

    $activity = Activity::query()->where('event', 'task.created')->sole();

    expect($activity->actor_id)->toBe($actor->id)
        ->and($activity->subject_type)->toBe('task')
        ->and($activity->subject_id)->toBe($task->id)
        ->and($activity->program_id)->toBe($program->id)
        ->and($activity->module)->toBe('tasks')
        ->and($activity->label())->toBe('Plan')
        ->and($activity->shouldNotify())->toBeTrue();
});

test('a status change is recorded with old and new values', function () {
    $task = Activity::withoutRecording(fn () => Task::factory()->create())->fresh();

    $task->update(['status' => TaskStatus::InProgress]);

    $activity = Activity::query()->sole();

    expect($activity->event)->toBe('task.status_changed')
        ->and($activity->changes['status'])->toBe(['todo', 'in progress'])
        ->and($activity->properties['field'])->toBe('status');
});

test('a change to a field that is not meaningful is logged but does not notify', function () {
    Queue::fake();
    $task = Activity::withoutRecording(fn () => Task::factory()->create());

    $task->update(['description' => 'More detail']);

    $activity = Activity::query()->sole();

    expect($activity->event)->toBe('task.updated')->and($activity->shouldNotify())->toBeFalse();
    Queue::assertNotPushed(FanOutActivity::class);
});

test('a meaningful activity queues its fan-out', function () {
    Queue::fake();

    Task::factory()->create();

    Queue::assertPushed(FanOutActivity::class);
});

test('assigning and unassigning are recorded on the task', function () {
    $employee = insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Ali');
    $task = Activity::withoutRecording(fn () => Task::factory()->create());

    $assignee = $task->taskAssignees()->create(['employee_id' => $employee]);
    $assignee->delete();

    $activities = Activity::query()->orderBy('id')->get();

    expect($activities->pluck('event')->all())->toBe(['task.assigned', 'task.unassigned'])
        ->and($activities->pluck('subject_id')->unique()->all())->toBe([$task->id])
        ->and($activities->first()->properties['employee'])->toEqual(['en' => 'Nour Ali', 'ar' => 'سامي حداد']);
});

test('deleting a task remembers who it named', function () {
    $employee = insertHrEmployee();
    $task = Activity::withoutRecording(function () use ($employee) {
        $task = Task::factory()->create();
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $employee]);

        return $task;
    });

    $task->delete();

    $activity = Activity::query()->where('event', 'task.deleted')->sole();

    expect($activity->properties['employee_ids'])->toBe([$employee])
        ->and($activity->properties['creator_id'])->toBe($task->created_by);
});

test('attachments and subtasks are recorded on their parent task', function () {
    $parent = Activity::withoutRecording(fn () => Task::factory()->create());

    TaskAttachment::factory()->create(['task_id' => $parent->id]);
    $subtask = Activity::withoutRecording(fn () => Task::factory()->create(['parent_task_id' => $parent->id]));
    Task::factory()->create(['parent_task_id' => $parent->id, 'title' => 'Buy tanks']);
    $subtask->update(['status' => TaskStatus::Done]);

    $onParent = Activity::query()->where('subject_id', $parent->id)->orderBy('id')->pluck('event')->all();

    expect($onParent)->toBe(['task.attachment_added', 'task.subtask_added', 'task.subtask_completed']);
});

test('nothing is recorded while recording is off', function () {
    Activity::withoutRecording(function (): void {
        $task = Task::factory()->create();
        $task->update(['status' => TaskStatus::Done]);
        $task->delete();
    });

    expect(Activity::query()->count())->toBe(0);
});

test('completing a task from the task list notifies its assignee but not the actor', function () {
    $employee = insertHrEmployee();
    $assignee = Activity::withoutRecording(fn () => User::factory()->role('Managers')->create(['employee_id' => $employee]));
    $actor = Activity::withoutRecording(fn () => User::factory()->role('Managers')->create(['name' => 'Sami']));
    $task = Activity::withoutRecording(function () use ($employee, $actor) {
        $task = Task::factory()->create(['created_by' => $actor->id, 'title' => 'Plan']);
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $employee]);

        return $task;
    });

    Livewire::actingAs($actor)->test('pages::tasks.index')->call('toggleComplete', $task->id);

    expect(UserNotification::query()->forUser($actor)->count())->toBe(0);

    $row = UserNotification::query()->forUser($assignee)->sole();

    expect(app(NotificationPresenter::class)->sentence($row->data, 'en'))
        ->toBe('Sami moved “Plan” from '.__('enums.task_status.todo').' to '.__('enums.task_status.done'));
});
