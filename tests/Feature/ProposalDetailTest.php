<?php

use App\Enums\Currency;
use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from proposal detail', function () {
    $proposal = Proposal::factory()->create();

    $this->get(route('proposals.show', $proposal))->assertRedirect(route('login'));
});

test('proposal detail shows its name status and derived phase', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create([
        'name' => 'Emergency WASH Response',
        'status' => ProposalStatus::Active,
    ]);

    $this->actingAs($user)
        ->get(route('proposals.show', $proposal))
        ->assertOk()
        ->assertSeeText('Emergency WASH Response')
        ->assertSeeText('Active')
        // ProposalStatus::Active belongs to ProposalPhase::Monitoring.
        ->assertSeeText('Monitoring');
});

test('proposal detail shows only its own status notes newest first', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();
    $other = Proposal::factory()->create();

    ProposalStatusNote::factory()->create([
        'proposal_id' => $proposal->id,
        'context' => 'Older note',
        'date' => '2026-01-01',
    ]);
    ProposalStatusNote::factory()->create([
        'proposal_id' => $proposal->id,
        'context' => 'Newer note',
        'date' => '2026-06-01',
    ]);
    ProposalStatusNote::factory()->create([
        'proposal_id' => $other->id,
        'context' => 'Someone elses note',
    ]);

    $this->actingAs($user)
        ->get(route('proposals.show', $proposal))
        ->assertOk()
        ->assertSeeTextInOrder(['Newer note', 'Older note'])
        ->assertDontSeeText('Someone elses note');
});

test('changing status updates the proposal and appends an audit note in one act', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('newStatus', ProposalStatus::Approved->value)
        ->set('statusContext', '  Grant agreement countersigned.  ')
        ->set('statusDate', '2026-04-01')
        ->call('changeStatus')
        ->assertHasNoErrors();

    $proposal->refresh();
    $note = ProposalStatusNote::query()->where('proposal_id', $proposal->id)->sole();

    expect($proposal->status)->toBe(ProposalStatus::Approved)
        ->and($note->status)->toBe(ProposalStatus::Approved)
        ->and($note->context)->toBe('Grant agreement countersigned.')
        ->and($note->date->toDateString())->toBe('2026-04-01');
});

test('a status change with no context is rejected and changes nothing', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('newStatus', ProposalStatus::Approved->value)
        ->set('statusContext', '   ')
        ->call('changeStatus')
        ->assertHasErrors(['statusContext']);

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Submitted)
        ->and(ProposalStatusNote::query()->where('proposal_id', $proposal->id)->count())->toBe(0);
});

test('funding is totalled per currency and never summed across them', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();

    DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'donor_id' => Donor::factory()->create(['name' => 'UNICEF'])->id,
        'budget' => '500000.00',
        'currency' => Currency::Usd,
    ]);
    DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'donor_id' => Donor::factory()->create(['name' => 'ECHO'])->id,
        'budget' => '200000.00',
        'currency' => Currency::Eur,
    ]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'funding')
        ->assertSee('UNICEF')
        ->assertSee('ECHO')
        ->assertSee('500,000.00')
        ->assertSee('200,000.00')
        ->assertSee('USD')
        ->assertSee('EUR')
        // 700,000 would be the forbidden cross-currency sum.
        ->assertDontSee('700,000.00');
});

test('proposal detail shows targets committed in this proposal', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();
    $indicator = Indicator::factory()->create(['name' => 'Households assisted']);

    PlanIndicator::factory()->create([
        'source_type' => 'proposal',
        'source_id' => $proposal->id,
        'indicator_id' => $indicator->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'targets')
        ->assertSee('Households assisted');
});

test('proposal detail renders a proposal with nothing attached', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create(['name' => 'Bare Proposal']);

    $this->actingAs($user)
        ->get(route('proposals.show', $proposal))
        ->assertOk()
        ->assertSeeText('Bare Proposal')
        ->assertSeeText('No status history yet');
});
