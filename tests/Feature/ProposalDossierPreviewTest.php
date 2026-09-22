<?php

/**
 * TEMPORARY — covers the dossier preview component while it lives alongside
 * the existing proposal detail page. On merge, point every render at
 * 'pages::proposals.show' / route('proposals.show') and fold these cases into
 * tests/Feature/ProposalDetailTest.php.
 */

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Enums\ProposalStatus;
use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use Livewire\Livewire;

/**
 * A proposal funded by one agreement, so the money tests share one setup.
 *
 * @return array{0: Proposal, 1: DonorProposal}
 */
function fundedProposal(array $attributes = [], string $budget = '500000.00'): array
{
    $proposal = Proposal::factory()->create($attributes);

    $agreement = DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'donor_id' => Donor::factory()->create(['name' => 'ECHO'])->id,
        'budget' => $budget,
        'currency' => Currency::Usd,
    ]);

    return [$proposal, $agreement];
}

test('every connection renders at once with no tab to click', function () {
    $user = User::factory()->create();
    [$proposal, $agreement] = fundedProposal();

    $project = Project::factory()->create(['name' => 'Aleppo Water Points']);
    $proposal->projects()->attach($project);

    Task::factory()->create(['source_type' => 'proposal', 'source_id' => $proposal->id, 'title' => 'Verify beneficiary list']);

    PlanIndicator::factory()->forProposal($proposal)->create([
        'indicator_id' => Indicator::factory()->create(['name' => 'Households assisted'])->id,
    ]);

    ProposalStatusNote::factory()->create([
        'proposal_id' => $proposal->id,
        'context' => 'Donor confirmed the allocation',
    ]);

    // One request, no ->set('tab'): the old page could only show one of these at a time.
    $this->actingAs($user)
        ->get(route('proposals.dossier-preview', $proposal))
        ->assertOk()
        ->assertSeeText('ECHO')
        ->assertSeeText('Aleppo Water Points')
        ->assertSeeText('Verify beneficiary list')
        ->assertSeeText('Households assisted')
        ->assertSeeText('Donor confirmed the allocation');
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
        ->test('pages::proposals.dossier', ['proposal' => $proposal])
        ->assertSee('UNICEF')
        ->assertSee('ECHO')
        ->assertSee('500,000.00')
        ->assertSee('200,000.00')
        ->assertSee('USD')
        ->assertSee('EUR')
        // 700,000 would be the forbidden cross-currency sum.
        ->assertDontSee('700,000.00');
});

test('a tranche that has arrived shows what was sent and what actually landed', function () {
    $user = User::factory()->create();
    [$proposal, $agreement] = fundedProposal();

    TransfersTracking::factory()
        ->received(Account::factory()->create(['name' => 'Main USD Account']))
        ->create([
            'donor_proposal_id' => $agreement->id,
            'payment_amount' => '100000.00',
            'amount_received' => '100000.00',
            'scheduled_date' => now()->subMonth()->toDateString(),
        ]);

    // received() charges a one percent fee, so 100,000 sent lands as 99,000 net.
    Livewire::actingAs($user)
        ->test('pages::proposals.dossier', ['proposal' => $proposal])
        ->assertSee('100,000.00')
        ->assertSee('99,000.00')
        ->assertSeeText('Main USD Account');
});

test('a pending tranche past its scheduled date is called overdue', function () {
    $user = User::factory()->create();
    [$proposal, $agreement] = fundedProposal();

    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '250000.00',
        'scheduled_date' => now()->subDays(12)->toDateString(),
        'ar_status' => ArStatus::Pending,
    ]);

    $rendered = Livewire::actingAs($user)
        ->test('pages::proposals.dossier', ['proposal' => $proposal]);

    $rendered->assertSeeText('12 days overdue')
        ->assertSee('data-tranche-state="overdue"', escape: false);
});

test('a tranche still to come is not reported as money received', function () {
    $user = User::factory()->create();
    [$proposal, $agreement] = fundedProposal();

    // The factory fills net_amount_received even on a pending tranche, so the
    // page must decide from ar_status, not from that column being non-null.
    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '250000.00',
        'scheduled_date' => now()->addDays(30)->toDateString(),
        'ar_status' => ArStatus::Pending,
    ]);

    Livewire::actingAs($user)
        ->test('pages::proposals.dossier', ['proposal' => $proposal])
        ->assertSeeText('Nothing received yet')
        ->assertSee('data-tranche-state="pending"', escape: false);
});

test('the planned window places today between the planned dates', function () {
    $user = User::factory()->create();

    [$proposal] = fundedProposal([
        'planned_date_begin' => now()->subDays(100)->toDateString(),
        'planned_date_end' => now()->addDays(265)->toDateString(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::proposals.dossier', ['proposal' => $proposal])
        ->assertSeeText('Day 100 of 365')
        ->assertSeeText('265 days left');
});

test('a proposal past its planned end says so rather than counting days left', function () {
    $user = User::factory()->create();

    [$proposal] = fundedProposal([
        'planned_date_begin' => now()->subDays(400)->toDateString(),
        'planned_date_end' => now()->subDays(20)->toDateString(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::proposals.dossier', ['proposal' => $proposal])
        ->assertSeeText('20 days past the planned end');
});

test('delivery counts what is finished and what is overdue', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();

    Task::factory()->completed()->create(['source_type' => 'proposal', 'source_id' => $proposal->id, 'title' => 'Signed agreement filed']);
    Task::factory()->create([
        'source_type' => 'proposal',
        'source_id' => $proposal->id,
        'title' => 'Submit interim report',
        'status' => TaskStatus::Todo,
        'due_date' => now()->subDays(5)->toDateString(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::proposals.dossier', ['proposal' => $proposal])
        ->assertSeeText('1 of 2 tasks done')
        ->assertSeeText('1 overdue');
});

test('a target reports what approved reports achieved against what was committed', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();

    $target = PlanIndicator::factory()->forProposal($proposal)->create([
        'indicator_id' => Indicator::factory()->create(['name' => 'Households assisted'])->id,
        'target' => '1000.00',
    ]);

    Report::factory()->approved()->create(['plan_indicator_id' => $target->id, 'value' => '400.00']);
    Report::factory()->approved()->create(['plan_indicator_id' => $target->id, 'value' => '200.00']);

    Livewire::actingAs($user)
        ->test('pages::proposals.dossier', ['proposal' => $proposal])
        ->assertSeeText('Households assisted')
        ->assertSeeText('600')
        ->assertSeeText('of 1,000');
});

test('a report still awaiting approval is not counted as achievement', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();

    $target = PlanIndicator::factory()->forProposal($proposal)->create([
        'indicator_id' => Indicator::factory()->create(['name' => 'Households assisted'])->id,
        'target' => '1000.00',
    ]);

    Report::factory()->reviewed()->create(['plan_indicator_id' => $target->id, 'value' => '400.00']);

    Livewire::actingAs($user)
        ->test('pages::proposals.dossier', ['proposal' => $proposal])
        ->assertSeeText('of 1,000')
        ->assertSeeText('0%');
});

test('the proposal notes are shown when there are any', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create(['notes' => 'Co-funded with the 2026 resilience window.']);

    Livewire::actingAs($user)
        ->test('pages::proposals.dossier', ['proposal' => $proposal])
        ->assertSeeText('Co-funded with the 2026 resilience window.');
});

test('the lifecycle spine dates the stops the proposal has reached', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Approved]);

    ProposalStatusNote::factory()->create([
        'proposal_id' => $proposal->id,
        'status' => ProposalStatus::Submitted,
        'date' => '2026-03-12',
        'context' => 'Submitted to the donor portal',
    ]);

    $rendered = Livewire::actingAs($user)
        ->test('pages::proposals.dossier', ['proposal' => $proposal]);

    // The spine links its dated stop at the note that recorded it.
    $rendered->assertSee('#status-note-'.ProposalStatusNote::query()->first()->id, escape: false)
        ->assertSeeText('Approved');
});

test('changing status updates the proposal and appends an audit note in one act', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::ProposalDraft]);

    Livewire::actingAs($user)
        ->test('pages::proposals.dossier', ['proposal' => $proposal])
        ->call('openStatusModal')
        ->set('newStatus', ProposalStatus::Submitted->value)
        ->set('statusContext', 'Submitted through the donor portal')
        ->set('statusDate', '2026-04-01')
        ->call('changeStatus')
        ->assertHasNoErrors()
        ->assertSet('showStatusModal', false);

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Submitted);

    $note = ProposalStatusNote::query()->where('proposal_id', $proposal->id)->sole();
    expect($note->status)->toBe(ProposalStatus::Submitted)
        ->and($note->context)->toBe('Submitted through the donor portal');
});

test('a status change with no context is rejected and changes nothing', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::ProposalDraft]);

    Livewire::actingAs($user)
        ->test('pages::proposals.dossier', ['proposal' => $proposal])
        ->call('openStatusModal')
        ->set('newStatus', ProposalStatus::Submitted->value)
        ->set('statusContext', '   ')
        ->set('statusDate', '2026-04-01')
        ->call('changeStatus')
        ->assertHasErrors('statusContext');

    expect($proposal->refresh()->status)->toBe(ProposalStatus::ProposalDraft)
        ->and(ProposalStatusNote::query()->where('proposal_id', $proposal->id)->count())->toBe(0);
});

test('a proposal with nothing attached still renders every section', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create(['name' => 'Bare Proposal']);

    $this->actingAs($user)
        ->get(route('proposals.dossier-preview', $proposal))
        ->assertOk()
        ->assertSeeText('Bare Proposal')
        ->assertSeeText('No status history yet')
        ->assertSeeText('No projects linked');
});
