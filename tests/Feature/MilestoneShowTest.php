<?php

use App\Enums\MilestoneStatus;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from the milestone show page', function () {
    $milestone = Milestone::factory()->create();

    $this->get(route('milestones.show', $milestone))->assertRedirect(route('login'));
});

test('the milestone show page renders the edit form with the current values', function () {
    $user = User::factory()->create();
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
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create(['name' => 'Initial gate']);

    Livewire::actingAs($user)
        ->test('pages::milestones.show', ['milestone' => $milestone])
        ->set('name', '  Revised gate  ')
        ->set('sequence', '8')
        ->set('weightPercentage', '35.50')
        ->call('updateMilestone')
        ->assertHasNoErrors();

    $milestone->refresh();

    expect($milestone->name)->toBe('Revised gate')
        ->and($milestone->sequence)->toBe(8)
        ->and($milestone->weight_percentage)->toBe('35.50');
});

test('updating a milestone requires its core delivery fields', function () {
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::milestones.show', ['milestone' => $milestone])
        ->set('name', '   ')
        ->set('plannedDate', '')
        ->set('responsibleUserId', '')
        ->call('updateMilestone')
        ->assertHasErrors(['name', 'plannedDate', 'responsibleUserId']);
});

test('the edit parent picker is project local and excludes the milestone and its descendants', function () {
    $user = User::factory()->create();
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
    $user = User::factory()->create();
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
        ->set('relatedPlannedDate', '2026-06-30')
        ->call('saveRelatedMilestone')
        ->assertHasNoErrors()
        ->assertSet('showRelatedModal', false);

    $related = Milestone::query()->where('name', 'Household survey')->sole();

    expect($related->parent_milestone_id)->toBe($milestone->id)
        ->and($related->project_id)->toBe($project->id)
        ->and($related->responsible_user_id)->toBe($responsible);
});

test('a related milestone can instead be created at the top level of the same project', function () {
    $user = User::factory()->create();
    $responsible = insertHrEmployee();
    $milestone = Milestone::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::milestones.show', ['milestone' => $milestone])
        ->call('openRelatedModal')
        ->set('relatedName', 'Standalone gate')
        ->set('relatedResponsibleUserId', (string) $responsible)
        ->set('relatedPlannedDate', '2026-06-30')
        ->set('relatedParentMilestoneId', '')
        ->call('saveRelatedMilestone')
        ->assertHasNoErrors();

    $related = Milestone::query()->where('name', 'Standalone gate')->sole();

    expect($related->parent_milestone_id)->toBeNull();
});

test('newly related milestones appear in the related milestones panel', function () {
    $user = User::factory()->create();
    $parent = Milestone::factory()->create();
    $child = Milestone::factory()->childOf($parent)->create(['name' => 'Household survey']);

    Livewire::actingAs($user)
        ->test('pages::milestones.show', ['milestone' => $parent])
        ->assertSee('Household survey')
        ->assertSee('href="'.route('milestones.show', $child).'"', false);
});

test('creating a related milestone requires its core delivery fields', function () {
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::milestones.show', ['milestone' => $milestone])
        ->call('openRelatedModal')
        ->set('relatedName', '   ')
        ->set('relatedPlannedDate', '')
        ->set('relatedResponsibleUserId', '')
        ->call('saveRelatedMilestone')
        ->assertHasErrors(['relatedName', 'relatedPlannedDate', 'relatedResponsibleUserId']);
});

test('the status can be changed from the edit form', function () {
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create(['status' => MilestoneStatus::Pending]);

    Livewire::actingAs($user)
        ->test('pages::milestones.show', ['milestone' => $milestone])
        ->set('milestoneStatus', MilestoneStatus::Reached->value)
        ->call('updateMilestone')
        ->assertHasNoErrors();

    expect($milestone->refresh()->status)->toBe(MilestoneStatus::Reached);
});
