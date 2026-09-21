<?php

use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;
use App\Enums\TaskStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\TransfersTracking;
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

test('the delete confirmation opens and closes through its boolean', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('confirmDelete', $proposal->id)
        ->assertSet('showDeleteModal', true)
        ->call('cancelDelete')
        ->assertSet('showDeleteModal', false)
        ->assertSet('deletingProposalId', null);
});

test('moving a proposal to its next stage records the status and the reason together', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openStageModal', $proposal->id)
        ->assertSet('showStageModal', true)
        ->assertSet('newStatus', ProposalStatus::Approved->value)
        ->set('statusContext', '  Donor signed off the budget.  ')
        ->set('statusDate', '2026-09-01')
        ->call('recordStageChange')
        ->assertHasNoErrors()
        ->assertSet('showStageModal', false);

    expect(DB::table('proposals')->find($proposal->id)->status)->toBe('approved');

    $note = DB::table('proposal_status_notes')->where('proposal_id', $proposal->id)->sole();

    expect($note->status)->toBe('approved')
        ->and($note->context)->toBe('Donor signed off the budget.')
        ->and($note->date)->toBe('2026-09-01');
});

test('a stage move without a reason changes nothing', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Active]);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openStageModal', $proposal->id)
        ->set('statusContext', '   ')
        ->call('recordStageChange')
        ->assertHasErrors(['statusContext']);

    expect(DB::table('proposals')->find($proposal->id)->status)->toBe('active')
        ->and(DB::table('proposal_status_notes')->where('proposal_id', $proposal->id)->count())->toBe(0);
});

test('a proposal with no next stage opens the stage move on its current status', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Rejected]);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openStageModal', $proposal->id)
        ->assertSet('newStatus', ProposalStatus::Rejected->value);
});

test('selecting a donor shows only the proposals it funds, and selecting it again clears the filter', function () {
    $user = User::factory()->create();
    $echo = Donor::factory()->create(['name' => 'ECHO']);
    $giz = Donor::factory()->create(['name' => 'GIZ']);
    $funded = Proposal::factory()->create(['name' => 'Water Response']);
    $other = Proposal::factory()->create(['name' => 'Shelter Response']);
    DonorProposal::factory()->create(['donor_id' => $echo->id, 'proposal_id' => $funded->id]);
    DonorProposal::factory()->create(['donor_id' => $giz->id, 'proposal_id' => $other->id]);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('toggleDonor', $echo->id)
        ->assertSet('donor', (string) $echo->id)
        ->assertSee('Funded by ECHO')
        ->assertSee('Water Response')
        ->assertDontSee('Shelter Response')
        ->call('toggleDonor', $echo->id)
        ->assertSet('donor', '')
        ->assertSee('Shelter Response');
});

test('the pipeline rail toggles the phase filter and still counts the phases it hides', function () {
    $user = User::factory()->create();
    Proposal::factory()->create(['status' => ProposalStatus::Active]);
    Proposal::factory()->create(['status' => ProposalStatus::Rejected]);

    $component = Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('togglePhase', ProposalPhase::Monitoring->value)
        ->assertSet('phase', ProposalPhase::Monitoring->value);

    $implementation = collect($component->instance()->pipeline)->firstWhere('phase', ProposalPhase::Implementation);

    expect($implementation['count'])->toBe(1)
        ->and($implementation['rejected'])->toBe(1);

    $component->call('togglePhase', ProposalPhase::Monitoring->value)->assertSet('phase', '');
});

test('the funding position splits committed, received and pending per currency for matching proposals only', function () {
    $user = User::factory()->create();
    $donor = Donor::factory()->create();
    $shown = Proposal::factory()->create(['name' => 'Shown Proposal']);
    $hidden = Proposal::factory()->create(['name' => 'Hidden Proposal']);

    $usd = DonorProposal::factory()->create(['donor_id' => $donor->id, 'proposal_id' => $shown->id, 'budget' => 1000, 'currency' => 'USD']);
    DonorProposal::factory()->create(['donor_id' => $donor->id, 'proposal_id' => $shown->id, 'budget' => 500, 'currency' => 'EUR']);
    DonorProposal::factory()->create(['donor_id' => $donor->id, 'proposal_id' => $hidden->id, 'budget' => 9000, 'currency' => 'USD']);

    TransfersTracking::factory()->create(['donor_proposal_id' => $usd->id, 'ar_status' => 'received', 'net_amount_received' => 300, 'amount_received_currency' => 'USD']);
    TransfersTracking::factory()->create(['donor_proposal_id' => $usd->id, 'ar_status' => 'pending', 'payment_amount' => 200, 'amount_transfered_currency' => 'USD']);

    $position = Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->set('search', 'Shown')
        ->instance()
        ->fundingPosition
        ->keyBy('currency');

    expect($position->keys()->all())->toBe(['EUR', 'USD'])
        ->and($position['USD']['committed'])->toBe(1000.0)
        ->and($position['USD']['received'])->toBe(300.0)
        ->and($position['USD']['pending'])->toBe(200.0)
        ->and($position['USD']['unscheduled'])->toBe(500.0)
        ->and($position['EUR']['committed'])->toBe(500.0)
        ->and($position['EUR']['received'])->toBe(0.0);
});

test('the funding flow draws one graph per currency and lists unfunded proposals apart', function () {
    $user = User::factory()->create();
    $donor = Donor::factory()->create(['name' => 'ECHO']);
    $funded = Proposal::factory()->create(['name' => 'Funded Proposal']);
    $unfunded = Proposal::factory()->create(['name' => 'Unfunded Proposal']);
    $project = Project::factory()->create(['name' => 'Water Network Expansion']);
    $funded->projects()->attach($project);
    DonorProposal::factory()->create(['donor_id' => $donor->id, 'proposal_id' => $funded->id, 'budget' => 1000, 'currency' => 'USD']);
    DonorProposal::factory()->create(['donor_id' => $donor->id, 'proposal_id' => $funded->id, 'budget' => 250, 'currency' => 'USD']);

    $flow = Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->assertSee('Water Network Expansion')
        ->instance()
        ->flow;

    expect($flow['currencies'])->toHaveCount(1)
        ->and($flow['currencies'][0]['graph']['money'])->toBe([
            ['from' => 'd'.$donor->id, 'to' => 'p'.$funded->id, 'amount' => 1250.0],
        ])
        ->and($flow['currencies'][0]['graph']['links'])->toBe([
            ['from' => 'p'.$funded->id, 'to' => 'j'.$project->id],
        ])
        ->and($flow['unfunded'])->toBe([['id' => $unfunded->id, 'name' => 'Unfunded Proposal']]);
});

test('each proposal row shows its task progress and overdue work', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();
    Task::factory()->for($proposal)->create(['status' => TaskStatus::Done]);
    Task::factory()->for($proposal)->create(['status' => TaskStatus::Todo, 'due_date' => now()->subDays(3)->toDateString()]);
    Task::factory()->for($proposal)->create(['status' => TaskStatus::InProgress, 'due_date' => now()->addDays(3)->toDateString()]);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->assertSee('1 of 3 tasks done')
        ->assertSee('1 overdue');
});
