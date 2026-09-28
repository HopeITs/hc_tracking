<?php

use App\Models\Project;
use App\Models\Risk;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->ownerEmployee = insertHrEmployee();
    $this->owner = userWithRole('Team Member', $this->ownerEmployee);
    $this->ownProject = Project::factory()->create(['user_id' => $this->ownerEmployee]);
    $this->otherProject = Project::factory()->create();
    $this->risks = fn (User $user) => Livewire::actingAs($user)->test('pages::risks.index');
    $this->log = fn (User $user, Project $project, string $name) => ($this->risks)($user)
        ->call('openCreateModal')
        ->set('riskProjectId', (string) $project->id)
        ->set('name', $name)
        ->set('impact', 'Late distribution')
        ->call('saveRisk');
});

test('a project owner logs risks on their own project only', function () {
    ($this->log)($this->owner, $this->ownProject, 'Supplier delay')->assertHasNoErrors();
    ($this->log)($this->owner, $this->otherProject, 'Not mine')->assertForbidden();

    expect(Risk::query()->where('name', 'Supplier delay')->exists())->toBeTrue()
        ->and(Risk::query()->where('name', 'Not mine')->exists())->toBeFalse();
});

test('an owner cannot move a risk onto a project they do not answer for', function () {
    $risk = Risk::factory()->create(['project_id' => $this->ownProject->id]);

    ($this->risks)($this->owner)
        ->call('openEditModal', $risk->id)
        ->set('riskProjectId', (string) $this->otherProject->id)
        ->call('saveRisk')
        ->assertForbidden();

    expect($risk->fresh()->project_id)->toBe($this->ownProject->id);
});

test('MEAL and Team Members without a project cannot log risks', function (string $role) {
    ($this->risks)(userWithRole($role))->call('openCreateModal')->assertForbidden();
})->with(['MEAL', 'Team Member']);

test('the owner flips materialised on their own project\'s risk but not others\'', function () {
    $own = Risk::factory()->create(['project_id' => $this->ownProject->id]);
    $other = Risk::factory()->create(['project_id' => $this->otherProject->id]);

    ($this->risks)($this->owner)->call('setHappened', $own->id, true)->assertOk();
    ($this->risks)($this->owner)->call('setHappened', $other->id, true)->assertForbidden();

    expect($own->fresh()->happened)->toBeTrue()
        ->and($other->fresh()->happened)->toBeFalse();
});

test('only risks.delete may delete', function () {
    $risk = Risk::factory()->create(['project_id' => $this->ownProject->id]);

    ($this->risks)($this->owner)->call('confirmDelete', $risk->id)->assertForbidden();
    ($this->risks)(userWithRole('Managers'))->call('confirmDelete', $risk->id)->call('deleteRisk')->assertOk();

    expect(Risk::query()->find($risk->id))->toBeNull();
});
