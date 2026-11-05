<?php

use App\Models\Program;
use App\Models\Strategy;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->strategy = Strategy::factory()->create(['name' => 'Country Strategy', 'start_year' => 2026, 'end_year' => 2030]);
    $this->other = Strategy::factory()->create(['name' => 'Regional Strategy', 'start_year' => 2026, 'end_year' => 2030]);
});

test('the programs modal opens with the strategy\'s current programs ticked', function () {
    $linked = Program::factory()->create(['strategy_id' => $this->strategy->id]);
    Program::factory()->create(['strategy_id' => null]);

    Livewire::actingAs(User::factory()->role('Managers')->create())
        ->test('pages::strategies.index')
        ->call('openProgramsModal', $this->strategy->id)
        ->assertSet('showProgramsModal', true)
        ->assertSet('managingStrategyId', $this->strategy->id)
        ->assertSet('selectedProgramIds', [(string) $linked->id])
        ->assertSeeHtml('data-test="strategy-programs-modal"');
});

test('saving links ticked programs, unlinks unticked ones and moves programs from another strategy', function () {
    $staying = Program::factory()->create(['strategy_id' => $this->strategy->id]);
    $dropped = Program::factory()->create(['strategy_id' => $this->strategy->id]);
    $unlinked = Program::factory()->create(['strategy_id' => null]);
    $moving = Program::factory()->create(['strategy_id' => $this->other->id]);

    Livewire::actingAs(User::factory()->role('Managers')->create())
        ->test('pages::strategies.index')
        ->call('openProgramsModal', $this->strategy->id)
        ->set('selectedProgramIds', [(string) $staying->id, (string) $unlinked->id, (string) $moving->id])
        ->assertSeeHtml('data-test="strategy-programs-move-warning"')
        ->call('savePrograms')
        ->assertHasNoErrors()
        ->assertSet('showProgramsModal', false);

    expect($staying->fresh()->strategy_id)->toBe($this->strategy->id)
        ->and($unlinked->fresh()->strategy_id)->toBe($this->strategy->id)
        ->and($moving->fresh()->strategy_id)->toBe($this->strategy->id)
        ->and($dropped->fresh()->strategy_id)->toBeNull();
});

test('roles without programs.update can neither open nor save the programs modal', function () {
    $program = Program::factory()->create(['strategy_id' => null]);
    $user = User::factory()->role('Team Member')->create();

    expect($user->can('programs.update'))->toBeFalse();

    Livewire::actingAs($user)
        ->test('pages::strategies.index')
        ->assertDontSeeHtml('data-test="manage-strategy-programs-button"')
        ->call('openProgramsModal', $this->strategy->id)
        ->assertForbidden();

    Livewire::actingAs($user)
        ->test('pages::strategies.index')
        ->call('savePrograms')
        ->assertForbidden();

    expect($program->fresh()->strategy_id)->toBeNull();
});

test('an unknown program id is rejected and nothing changes', function () {
    $program = Program::factory()->create(['strategy_id' => $this->strategy->id]);

    Livewire::actingAs(User::factory()->role('Managers')->create())
        ->test('pages::strategies.index')
        ->call('openProgramsModal', $this->strategy->id)
        ->set('selectedProgramIds', ['999999'])
        ->call('savePrograms')
        ->assertHasErrors(['selectedProgramIds.0']);

    expect($program->fresh()->strategy_id)->toBe($this->strategy->id);
});
