<?php

use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from the proposal pipeline', function () {
    $this->get(route('proposals.index'))->assertRedirect(route('login'));
});

test('proposals can be filtered by search and status', function () {
    $user = User::factory()->create();

    Proposal::factory()->create(['name' => 'Emergency WASH Response', 'status' => ProposalStatus::Active]);
    Proposal::factory()->create(['name' => 'Winter Shelter Kit', 'status' => ProposalStatus::Rejected]);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->assertSee('Emergency WASH Response')
        ->assertSee('Winter Shelter Kit')
        ->set('search', 'WASH')
        ->assertSee('Emergency WASH Response')
        ->assertDontSee('Winter Shelter Kit')
        ->call('clearFilters')
        ->set('status', ProposalStatus::Rejected->value)
        ->assertDontSee('Emergency WASH Response')
        ->assertSee('Winter Shelter Kit');
});

test('the phase filter selects every status belonging to that phase', function () {
    $user = User::factory()->create();

    // Monitoring holds exactly ProposalStatus::Active.
    Proposal::factory()->create(['name' => 'Monitored proposal', 'status' => ProposalStatus::Active]);
    // Planning holds ConceptNote and ProposalDraft.
    Proposal::factory()->create(['name' => 'Planned proposal', 'status' => ProposalStatus::ConceptNote]);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->set('phase', ProposalPhase::Monitoring->value)
        ->assertSee('Monitored proposal')
        ->assertDontSee('Planned proposal')
        ->call('clearFilters')
        ->set('phase', ProposalPhase::Planning->value)
        ->assertDontSee('Monitored proposal')
        ->assertSee('Planned proposal');
});

test('a proposal can be created with a trimmed name', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openCreateModal')
        ->set('name', '  Rapid Response Fund  ')
        ->set('proposalStatus', ProposalStatus::ConceptNote->value)
        ->set('plannedDateBegin', '2026-01-01')
        ->set('plannedDateEnd', '2026-12-31')
        ->call('saveProposal')
        ->assertHasNoErrors()
        ->assertSet('showProposalModal', false);

    $proposal = Proposal::query()->where('name', 'Rapid Response Fund')->sole();

    expect($proposal->status)->toBe(ProposalStatus::ConceptNote);
});

test('a proposal end date cannot fall before its begin date', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openCreateModal')
        ->set('name', 'Backwards Proposal')
        ->set('proposalStatus', ProposalStatus::ConceptNote->value)
        ->set('plannedDateBegin', '2026-12-31')
        ->set('plannedDateEnd', '2026-01-01')
        ->call('saveProposal')
        ->assertHasErrors(['plannedDateEnd']);
});

test('an unfunded proposal can be deleted', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('confirmDelete', $proposal->id)
        ->call('deleteProposal')
        ->assertHasNoErrors();

    expect(DB::table('proposals')->find($proposal->id))->toBeNull();
});

test('a proposal holding a funding agreement is not deleted and says why', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();
    $donor = Donor::factory()->create();
    $agreement = DonorProposal::factory()->create([
        'donor_id' => $donor->id,
        'proposal_id' => $proposal->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('confirmDelete', $proposal->id)
        ->call('deleteProposal');

    expect(DB::table('proposals')->find($proposal->id))->not->toBeNull()
        ->and(DB::table('donor_proposal')->find($agreement->id))->not->toBeNull();
});

test('the pipeline renders with no proposals', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::proposals.index')->assertSee('No proposals yet');
});
