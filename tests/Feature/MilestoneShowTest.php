<?php

use App\Enums\MilestoneStatus;
use App\Enums\TaskStatus;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from the milestone show page', function () {
    $milestone = Milestone::factory()->create();

    $this->get(route('milestones.show', $milestone))->assertRedirect(route('login'));
});

test('the milestone show page renders the edit form with the current values', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create(['name' => 'Aleppo Wells']);
    $milestone = Milestone::factory()->create([
        'project_id' => $project->id,
        'name' => 'Baseline assessment',
    ]);

    Livewire::actingAs($user)
        ->test('pages::milestones.show', ['milestone' => $milestone])
        ->assertSee('Baseline assessment')
        ->assertSee('Aleppo Wells')
        ->assertSet('name', 'Baseline assessment')
        ->assertSee('wire:submit="updateMilestone"', false);
});

test('a milestone can be updated from its own page', function () {
    $user = User::factory()->role('General Manager')->create();
    $milestone = Milestone::factory()->create(['name' => 'Initial gate', 'sequence' => 5]);

    Livewire::actingAs($user)
        ->test('pages::milestones.show', ['milestone' => $milestone])
        ->set('name', '  Revised gate  ')
        ->set('weightPercentage', '35.50')
        ->call('updateMilestone')
        ->assertHasNoErrors();

    $milestone->refresh();

    expect($milestone->name)->toBe('Revised gate')
        ->and($milestone->sequence)->toBe(5)
        ->and($milestone->weight_percentage)->toBe('35.50');
});

test('updating a milestone requires its core delivery fields', function () {
    $user = User::factory()->role('General Manager')->create();
    $milestone = Milestone::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::milestones.show', ['milestone' => $milestone])
        ->set('name', '   ')
        ->set('plannedStartDate', '')
        ->set('responsibleUserId', '')
        ->call('updateMilestone')
        ->assertHasErrors(['name', 'plannedStartDate', 'responsibleUserId']);
});

test('the edit parent picker is project local and excludes the milestone and its descendants', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $root = Milestone::factory()->create(['project_id' => $project->id, 'name' => 'Root gate']);
    $child = Milestone::factory()->childOf($root)->create(['name' => 'Child gate']);
    Milestone::factory()->create(['project_id' => $project->id, 'name' => 'Sibling gate']);
    Milestone::factory()->create(['project_id' => $otherProject->id, 'name' => 'Other project gate']);

    $component = Livewire::actingAs($user)
        ->test('pages::milestones.show', ['milestone' => $root]);

    $names = $component->instance()->parentOptions()->pluck('name')->all();

    expect($names)->toContain('Sibling gate')
        ->not->toContain('Root gate')
        ->not->toContain('Child gate')
        ->not->toContain('Other project gate');

    $component->set('parentMilestoneId', (string) $child->id)
        ->call('updateMilestone')
        ->assertHasErrors(['parentMilestoneId']);
});

test('a related milestone can be added from the show page as a child by default', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $responsible = insertHrEmployee();
    $milestone = Milestone::factory()->create(['project_id' => $project->id]);

    Livewire::actingAs($user)
        ->test('pages::milestones.show', ['milestone' => $milestone])
        ->call('openRelatedModal')
        ->assertSet('showRelatedModal', true)
        ->assertSet('relatedParentMilestoneId', (string) $milestone->id)
        ->set('relatedName', '  Household survey  ')
        ->set('relatedResponsibleUserId', (string) $responsible)
        ->set('relatedPlannedStartDate', '2026-06-30')
        ->call('saveRelatedMilestone')
        ->assertHasNoErrors()
        ->assertSet('showRelatedModal', false);

    $related = Milestone::query()->where('name', 'Household survey')->sole();

    expect($related->parent_milestone_id)->toBe($milestone->id)
        ->and($related->project_id)->toBe($project->id)
        ->and($related->responsible_user_id)->toBe($responsible);
});

test('a related milestone can instead be created at the top level of the same project', function () {
    $user = User::factory()->role('General Manager')->create();
    $responsible = insertHrEmployee();
    $milestone = Milestone::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::milestones.show', ['milestone' => $milestone])
        ->call('openRelatedModal')
        ->set('relatedName', 'Standalone gate')
        ->set('relatedResponsibleUserId', (string) $responsible)
        ->set('relatedPlannedStartDate', '2026-06-30')
        ->set('relatedParentMilestoneId', '')
        ->call('saveRelatedMilestone')
        ->assertHasNoErrors();

    $related = Milestone::query()->where('name', 'Standalone gate')->sole();

    expect($related->parent_milestone_id)->toBeNull();
});

test('newly related milestones appear in the related milestones panel', function () {
    $user = User::factory()->role('General Manager')->create();
    $parent = Milestone::factory()->create();
    $child = Milestone::factory()->childOf($parent)->create(['name' => 'Household survey']);

    Livewire::actingAs($user)
        ->test('pages::milestones.show', ['milestone' => $parent])
        ->assertSee('Household survey')
        ->assertSee('href="'.route('milestones.show', $child).'"', false);
});

test('creating a related milestone requires its core delivery fields', function () {
    $user = User::factory()->role('General Manager')->create();
    $milestone = Milestone::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::milestones.show', ['milestone' => $milestone])
        ->call('openRelatedModal')
        ->set('relatedName', '   ')
        ->set('relatedPlannedStartDate', '')
        ->set('relatedResponsibleUserId', '')
        ->call('saveRelatedMilestone')
        ->assertHasErrors(['relatedName', 'relatedPlannedStartDate', 'relatedResponsibleUserId']);
});

test('a task can be added from the milestone show page pre-filled with this milestone', function () {
    $user = User::factory()->role('General Manager')->create();
    $assignee = insertHrEmployee();
    $milestone = Milestone::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::milestones.show', ['milestone' => $milestone])
        ->call('openAddTaskModal')
        ->assertSet('showAddTaskModal', true)
        ->set('newTaskTitle', '  Household survey  ')
        ->set('newTaskAssignedTo', [(string) $assignee])
        ->call('addTask')
        ->assertHasNoErrors()
        ->assertSet('showAddTaskModal', false);

    $task = Task::query()->where('title', 'Household survey')->sole();

    expect($task->source_type)->toBe('milestone')
        ->and($task->source_id)->toBe($milestone->id)
        ->and($task->parent_task_id)->toBeNull()
        ->and($task->taskAssignees->pluck('employee_id')->all())->toBe([$assignee]);
});

test('adding a task from the milestone show page requires a title and an assignee', function () {
    $user = User::factory()->role('General Manager')->create();
    $milestone = Milestone::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::milestones.show', ['milestone' => $milestone])
        ->call('openAddTaskModal')
        ->set('newTaskTitle', '   ')
        ->set('newTaskAssignedTo', [])
        ->call('addTask')
        ->assertHasErrors(['newTaskTitle', 'newTaskAssignedTo']);
});

test('tasks linked to this milestone appear in the related tasks panel', function () {
    $user = User::factory()->role('General Manager')->create();
    $milestone = Milestone::factory()->create();
    $task = Task::factory()->create([
        'source_type' => 'milestone',
        'source_id' => $milestone->id,
        'title' => 'Survey households',
        'status' => TaskStatus::Todo,
    ]);
    $otherMilestone = Milestone::factory()->create();
    Task::factory()->create([
        'source_type' => 'milestone',
        'source_id' => $otherMilestone->id,
        'title' => 'Unrelated task',
    ]);

    Livewire::actingAs($user)
        ->test('pages::milestones.show', ['milestone' => $milestone])
        ->assertSee('Survey households')
        ->assertSee('href="'.route('tasks.show', $task).'"', false)
        ->assertDontSee('Unrelated task');
});

test('the status can be changed from the edit form', function () {
    $user = User::factory()->role('General Manager')->create();
    $milestone = Milestone::factory()->create(['status' => MilestoneStatus::Planned]);

    Livewire::actingAs($user)
        ->test('pages::milestones.show', ['milestone' => $milestone])
        ->set('milestoneStatus', MilestoneStatus::Reached->value)
        ->call('updateMilestone')
        ->assertHasNoErrors();

    expect($milestone->refresh()->status)->toBe(MilestoneStatus::Reached);
});
