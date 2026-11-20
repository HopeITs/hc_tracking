<?php

use App\Models\Program;
use App\Models\Project;
use App\Models\ProposalLocation;
use App\Models\Risk;
use App\Models\User;
use Livewire\Livewire;

/**
 * Y-13 (D-93): a register risk belongs to a location row, and the row's
 * responsible employee — or the manager of its program — logs and edits the
 * risks there without holding `risks.create` (contracts.md §8). The register's
 * form names the row itself (slice 2).
 */
beforeEach(function () {
    $this->ownerEmployee = insertHrEmployee();
    $this->owner = userWithRole('Team Member', $this->ownerEmployee);

    $this->ownProject = Project::factory()->create();
    $this->otherProject = Project::factory()->create();

    $this->ownRow = ProposalLocation::factory()
        ->forProject($this->ownProject)
        ->create(['responsible_employee_id' => $this->ownerEmployee]);
    $this->otherRow = ProposalLocation::factory()->forProject($this->otherProject)->create();

    $this->risks = fn (User $user) => Livewire::actingAs($user)->test('pages::risks.index');
    $this->log = fn (User $user, ProposalLocation $row, string $name) => ($this->risks)($user)
        ->call('openCreateModal')
        ->set('riskLocationId', (string) $row->id)
        ->set('name', $name)
        ->set('impact', 'Late distribution')
        ->call('saveRisk');
});

test('the row\'s responsible employee logs risks at their own place only', function () {
    ($this->log)($this->owner, $this->ownRow, 'Supplier delay')->assertHasNoErrors();
    ($this->log)($this->owner, $this->otherRow, 'Not mine')->assertForbidden();

    $logged = Risk::query()->where('name', 'Supplier delay')->sole();

    expect($logged->proposal_location_id)->toBe($this->ownRow->id)
        ->and($logged->proposal_id)->toBe($this->ownRow->proposal_id)
        ->and($logged->project_id)->toBe($this->ownProject->id)
        ->and(Risk::query()->where('name', 'Not mine')->exists())->toBeFalse();
});

test('the manager of the row\'s program logs risks there, and not on another program\'s row', function () {
    $managerEmployee = insertHrEmployee();
    $manager = userWithRole('Team Member', $managerEmployee);

    $program = Program::factory()->create(['manager_id' => $managerEmployee]);
    $this->ownRow->update(['program_id' => $program->id]);

    ($this->log)($manager, $this->ownRow, 'Fuel shortage')->assertHasNoErrors();
    ($this->log)($manager, $this->otherRow, 'Not my program')->assertForbidden();

    expect(Risk::query()->where('name', 'Fuel shortage')->sole()->proposal_location_id)->toBe($this->ownRow->id)
        ->and(Risk::query()->where('name', 'Not my program')->exists())->toBeFalse();
});

test('an owner cannot move a risk to a place they do not answer for', function () {
    $risk = Risk::factory()->onRow($this->ownRow)->create();

    ($this->risks)($this->owner)
        ->call('openEditModal', $risk->id)
        ->set('riskLocationId', (string) $this->otherRow->id)
        ->call('saveRisk')
        ->assertForbidden();

    expect($risk->fresh()->proposal_location_id)->toBe($this->ownRow->id)
        ->and($risk->fresh()->project_id)->toBe($this->ownProject->id);
});

test('MEAL and Team Members with no row of their own cannot log risks', function (string $role) {
    ($this->risks)(userWithRole($role))->call('openCreateModal')->assertForbidden();
})->with(['MEAL', 'Team Member']);

test('the responsible employee flips materialised on their own row\'s risk but not another row\'s', function () {
    $own = Risk::factory()->onRow($this->ownRow)->create();
    $other = Risk::factory()->onRow($this->otherRow)->create();

    ($this->risks)($this->owner)->call('setHappened', $own->id, true)->assertOk();
    ($this->risks)($this->owner)->call('setHappened', $other->id, true)->assertForbidden();

    expect($own->fresh()->happened)->toBeTrue()
        ->and($other->fresh()->happened)->toBeFalse();
});

test('a risk the backfill could not map is editable only by risks.update holders', function () {
    $unmapped = Risk::factory()->create(['project_id' => $this->ownProject->id, 'proposal_location_id' => null]);

    ($this->risks)($this->owner)->call('setHappened', $unmapped->id, true)->assertForbidden();
    ($this->risks)(userWithRole('Managers'))->call('setHappened', $unmapped->id, true)->assertOk();

    expect($unmapped->fresh()->happened)->toBeTrue();
});

test('only risks.delete may delete', function () {
    $risk = Risk::factory()->onRow($this->ownRow)->create();

    ($this->risks)($this->owner)->call('confirmDelete', $risk->id)->assertForbidden();
    ($this->risks)(userWithRole('Managers'))->call('confirmDelete', $risk->id)->call('deleteRisk')->assertOk();

    expect(Risk::query()->find($risk->id))->toBeNull();
});
