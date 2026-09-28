<?php

use App\Enums\MilestoneStatus;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->ownerEmployee = insertHrEmployee();
    $this->owner = userWithRole('Team Member', $this->ownerEmployee);
    $this->ownProject = Project::factory()->create(['user_id' => $this->ownerEmployee]);
    $this->otherProject = Project::factory()->create();
    $this->index = fn (User $user) => Livewire::actingAs($user)->test('pages::milestones.index');
    $this->fillForm = fn ($page, Project $project, string $name) => $page
        ->set('milestoneProjectId', (string) $project->id)
        ->set('name', $name)
        ->set('plannedStartDate', '2026-10-01')
        ->set('plannedEndDate', '2026-10-31')
        ->set('responsibleUserId', (string) $this->ownerEmployee);
});

test('a Team Member who owns no project cannot add milestones', function () {
    ($this->index)(userWithRole('Team Member'))->call('openCreateModal')->assertForbidden();
});

test('a project owner adds milestones to their own project only', function () {
    ($this->fillForm)(($this->index)($this->owner)->call('openCreateModal'), $this->ownProject, 'Owner milestone')
        ->call('saveMilestone')
        ->assertHasNoErrors();

    expect(Milestone::query()->where('name', 'Owner milestone')->exists())->toBeTrue();

    ($this->fillForm)(($this->index)($this->owner)->call('openCreateModal'), $this->otherProject, 'Intruder milestone')
        ->call('saveMilestone')
        ->assertForbidden();

    expect(Milestone::query()->where('name', 'Intruder milestone')->exists())->toBeFalse();
});

test('the responsible person moves status but cannot rename the milestone', function () {
    $responsibleEmployee = insertHrEmployee();
    $responsible = userWithRole('Team Member', $responsibleEmployee);
    $milestone = Milestone::factory()->create([
        'project_id' => $this->otherProject->id,
        'responsible_user_id' => $responsibleEmployee,
        'name' => 'Original name',
    ]);

    ($this->index)($responsible)->call('quickSetStatus', $milestone->id, MilestoneStatus::Ongoing->value)->assertOk();

    expect($milestone->fresh()->status)->toBe(MilestoneStatus::Ongoing);

    Livewire::actingAs($responsible)->test('pages::milestones.show', ['milestone' => $milestone])
        ->set('name', 'Renamed by responsible')
        ->call('updateMilestone');

    expect($milestone->fresh()->name)->toBe('Original name');
});

test('someone with no rights on a milestone cannot move it', function () {
    $milestone = Milestone::factory()->create(['project_id' => $this->otherProject->id]);

    ($this->index)(userWithRole('Team Member'))->call('quickSetStatus', $milestone->id, MilestoneStatus::Ongoing->value)->assertForbidden();
});

test('only milestones.delete may delete', function () {
    $milestone = Milestone::factory()->create(['project_id' => $this->ownProject->id]);

    ($this->index)($this->owner)->call('confirmDelete', $milestone->id)->assertForbidden();
    ($this->index)(userWithRole('MEAL'))->call('confirmDelete', $milestone->id)->assertForbidden();
    ($this->index)(userWithRole('Managers'))->call('confirmDelete', $milestone->id)->call('deleteMilestone')->assertOk();

    expect(Milestone::query()->find($milestone->id))->toBeNull();
});
