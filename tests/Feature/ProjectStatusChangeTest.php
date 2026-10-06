<?php

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->ownerEmployee = insertHrEmployee();
    $this->owner = userWithRole('Team Member', $this->ownerEmployee);
    $this->manager = userWithRole('Managers');

    // Created by the manager, so the owner is asked to acknowledge (Pending).
    $this->actingAs($this->manager);
    $this->project = Project::factory()->status(ProjectStatus::Draft)->create(['user_id' => $this->ownerEmployee]);
    auth()->logout();

    $this->show = fn (User $user) => Livewire::actingAs($user)->test('pages::projects.show', ['project' => $this->project]);
});

test('a manager changes the status from the project page', function () {
    $this->project->currentAssignment('user_id')->acknowledge($this->owner, null);

    ($this->show)($this->manager)
        ->assertSeeHtml('data-test="project-status-option-active"')
        ->call('changeStatus', ProjectStatus::Active->value)
        ->assertHasNoErrors();

    expect($this->project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('a draft cannot move on until its owner has acknowledged', function () {
    ($this->show)($this->manager)
        ->call('changeStatus', ProjectStatus::Submitted->value)
        ->assertDispatched('toast-show', fn (string $event, array $params) => ($params['slots']['text'] ?? null) === __('projects.owner_must_acknowledge_first')
            && ($params['dataset']['variant'] ?? null) === 'danger');

    expect($this->project->fresh()->status)->toBe(ProjectStatus::Draft);

    ($this->show)($this->manager)->call('changeStatus', ProjectStatus::Archived->value);

    expect($this->project->fresh()->status)->toBe(ProjectStatus::Archived);
});

test('archiving an unacknowledged draft does not bypass the acknowledgement', function () {
    ($this->show)($this->manager)->call('changeStatus', ProjectStatus::Archived->value);
    ($this->show)($this->manager)->call('changeStatus', ProjectStatus::Active->value);

    expect($this->project->fresh()->status)->toBe(ProjectStatus::Archived);
});

test('an owner without delete rights cannot move an archived project out of the archive', function () {
    $project = Project::factory()->create(['user_id' => $this->ownerEmployee, 'status' => ProjectStatus::Archived]);

    Livewire::actingAs($this->owner)
        ->test('pages::projects.show', ['project' => $project])
        ->call('changeStatus', ProjectStatus::Active->value)
        ->assertForbidden();

    expect($project->fresh()->status)->toBe(ProjectStatus::Archived);
});

test('someone without edit rights is forbidden and sees no status menu', function () {
    $outsider = userWithRole('Team Member');

    ($this->show)($outsider)->assertDontSeeHtml('data-test="project-status-trigger"');

    ($this->show)($outsider)->call('changeStatus', ProjectStatus::Active->value)->assertForbidden();

    expect($this->project->fresh()->status)->toBe(ProjectStatus::Draft);
});

test('an owner without delete rights cannot archive and is not offered it', function () {
    $this->project->currentAssignment('user_id')->acknowledge($this->owner, null);

    ($this->show)($this->owner)
        ->assertSeeHtml('data-test="project-status-option-active"')
        ->assertDontSeeHtml('data-test="project-status-option-archived"');

    ($this->show)($this->owner)->call('changeStatus', ProjectStatus::Archived->value)->assertForbidden();

    expect($this->project->fresh()->status)->toBe(ProjectStatus::Draft);
});

test('an unknown status value is ignored', function () {
    ($this->show)($this->manager)->call('changeStatus', 'bogus');

    expect($this->project->fresh()->status)->toBe(ProjectStatus::Draft);
});

test('a status with the wrong casing is ignored', function () {
    ($this->show)($this->manager)->call('changeStatus', 'ACTIVE');

    expect($this->project->fresh()->status)->toBe(ProjectStatus::Draft);
});

test('a project already past draft moves freely between states', function () {
    $project = Project::factory()->status(ProjectStatus::Active)->create();

    Livewire::actingAs($this->manager)
        ->test('pages::projects.show', ['project' => $project])
        ->call('changeStatus', ProjectStatus::Closed->value)
        ->assertHasNoErrors();

    expect($project->fresh()->status)->toBe(ProjectStatus::Closed);
});
