<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Notifications\QuickActions;
use Livewire\Livewire;

beforeEach(function () {
    $this->employee = insertHrEmployee();
    $this->assignee = userWithRole('Team Member', $this->employee);
    $this->task = Task::factory()->create(['title' => 'Assigned work']);
    $this->task->taskAssignees()->delete();
    $this->task->taskAssignees()->create(['employee_id' => $this->employee]);
    $this->index = fn (User $user) => Livewire::actingAs($user)->test('pages::tasks.index');
});

test('a Team Member cannot create tasks', function () {
    ($this->index)($this->assignee)->call('quickCreateTask', 'Sneaky task')->assertForbidden();

    expect(Task::query()->withoutGlobalScopes()->where('title', 'Sneaky task')->exists())->toBeFalse();
});

test('an assignee may move their own task\'s status but not edit it', function () {
    ($this->index)($this->assignee)->call('quickSetStatus', $this->task->id, TaskStatus::InProgress->value)->assertOk();

    expect($this->task->fresh()->status)->toBe(TaskStatus::InProgress);

    ($this->index)($this->assignee)->call('quickSetPriority', $this->task->id, TaskPriority::High->value)->assertForbidden();
    ($this->index)($this->assignee)->call('openEditDrawer', $this->task->id)->assertForbidden();
});

test('a creator without tasks.update may edit and delete their own task', function () {
    $creator = userWithRole('Team Member');
    $own = Task::factory()->create(['created_by' => $creator->id]);

    ($this->index)($creator)->call('quickSetPriority', $own->id, TaskPriority::High->value)->assertOk();
    ($this->index)($creator)->call('confirmDelete', $own->id)->call('deleteTask')->assertOk();

    expect(Task::query()->withoutGlobalScopes()->find($own->id))->toBeNull();
});

test('only tasks.delete or the creator may delete someone else\'s task', function () {
    $media = userWithRole('Media Team', $this->employee);

    ($this->index)($media)->call('confirmDelete', $this->task->id)->assertForbidden();
    ($this->index)(userWithRole('Managers'))->call('confirmDelete', $this->task->id)->call('deleteTask')->assertOk();

    expect(Task::query()->withoutGlobalScopes()->find($this->task->id))->toBeNull();
});

test('the task being deleted cannot be swapped from the browser', function () {
    $creator = userWithRole('Team Member');
    $own = Task::factory()->create(['created_by' => $creator->id]);

    expect(fn () => ($this->index)($creator)
        ->call('confirmDelete', $own->id)
        ->set('deletingTaskId', $this->task->id))
        ->toThrow(Exception::class);
});

test('adding a task on a project needs tasks.create', function () {
    $project = Project::factory()->create();

    Livewire::actingAs($this->assignee)->test('pages::projects.show', ['project' => $project])->call('openAddTaskModal')->assertForbidden();
    Livewire::actingAs(userWithRole('Media Team'))->test('pages::projects.show', ['project' => $project])->call('openAddTaskModal')->assertOk();
});

test('an assignee on the task page changes status but not the title', function () {
    $this->task->update(['priority' => TaskPriority::Low]);

    $page = Livewire::actingAs($this->assignee)->test('pages::tasks.show', ['task' => $this->task])
        ->assertSeeHtml('data-test="task-edit-status-done"')
        ->assertSeeHtml('data-test="task-edit-progress-input"')
        ->assertSeeHtml('data-test="task-edit-completed-date-input"')
        ->assertSeeHtml('data-test="update-task-button"')
        ->assertDontSeeHtml('data-test="task-edit-title-input"')
        ->assertDontSeeHtml('data-test="task-edit-priority-high"')
        ->assertDontSeeHtml('data-test="task-edit-assignee-picker"')
        ->assertSee('Assigned work');

    $page->set('title', 'Renamed by assignee')
        ->set('taskPriority', TaskPriority::High->value)
        ->set('taskStatus', TaskStatus::Done->value)
        ->set('progressPercentage', '100')
        ->set('completedDate', '2026-09-28')
        ->call('updateTask');

    expect($this->task->fresh())
        ->title->toBe('Assigned work')
        ->priority->toBe(TaskPriority::Low)
        ->status->toBe(TaskStatus::UnderReview)
        ->progress_percentage->toBe(100)
        ->completed_date->toDateString()->toBe('2026-09-28');
});

test('someone who can see a task but has no rights on it cannot change it from the task page', function () {
    $colleague = userWithRole('Team Member');
    $visible = Task::factory()->create(['title' => 'Colleague task']);
    $visible->taskAssignees()->delete();
    $visible->taskAssignees()->create(['employee_id' => $this->employee]);
    $manager = userWithRole('Managers');

    Livewire::actingAs($manager)->test('pages::tasks.show', ['task' => $visible])
        ->assertSeeHtml('data-test="task-edit-title-input"')
        ->assertSeeHtml('data-test="task-edit-priority-high"')
        ->assertSeeHtml('data-test="update-task-button"')
        ->set('title', 'Renamed by manager')
        ->call('updateTask')
        ->assertHasNoErrors();

    expect($visible->fresh()->title)->toBe('Renamed by manager');

    $this->actingAs($colleague)->get(route('tasks.show', $this->task))->assertNotFound();

    Role::create(['name' => 'Task Viewer', 'guard_name' => 'web'])->givePermissionTo('tasks.view');
    $viewer = User::factory()->role('Task Viewer')->create();

    Livewire::actingAs($viewer)->test('pages::tasks.show', ['task' => $visible])
        ->assertSee('Renamed by manager')
        ->assertDontSeeHtml('data-test="task-edit-form"')
        ->assertDontSeeHtml('data-test="task-edit-title-input"')
        ->assertDontSeeHtml('data-test="task-edit-status-done"')
        ->assertDontSeeHtml('data-test="update-task-button"')
        ->set('title', 'Renamed by viewer')
        ->call('updateTask')
        ->assertForbidden();

    expect($visible->fresh()->title)->toBe('Renamed by manager');
});

test('quick actions are offered only to people who may move the task', function () {
    $offered = app(QuickActions::class)->forSubject('task', $this->task->id, $this->assignee);
    $notOffered = app(QuickActions::class)->forSubject('task', $this->task->id, userWithRole('Team Member'));

    expect(array_column($offered, 'type'))->toContain('task.start')
        ->and($notOffered)->toBe([]);
});
