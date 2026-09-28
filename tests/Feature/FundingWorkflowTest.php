<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\TransfersTracking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('a user without donor permissions cannot create, edit or delete a donor', function (string $method, array $arguments) {
    $user = User::factory()->create();
    $donor = Donor::factory()->create();

    Livewire::actingAs($user)->test('pages::donors.index')
        ->call($method, ...($arguments === [] ? [] : [$donor->id]))
        ->assertForbidden();

    expect(Donor::query()->whereKey($donor->id)->exists())->toBeTrue();
})->with([
    'create' => ['openCreateModal', []],
    'edit' => ['openEditModal', ['donor']],
    'delete' => ['confirmDelete', ['donor']],
]);

test('a sent tranche past its date is in transit on the donor page, never overdue', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create(['currency' => Currency::Usd, 'budget' => '1000.00']);
    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '400.00',
        'amount_transfered_currency' => Currency::Usd,
        'scheduled_date' => CarbonImmutable::today()->subDays(10),
        'transfer_date' => CarbonImmutable::today()->subDays(5),
        'ar_status' => ArStatus::Pending,
    ]);

    $page = Livewire::actingAs($user)->test('pages::donors.show', ['donor' => $agreement->donor_id])->instance();

    expect($page->overdueTranches)->toBe([])
        ->and($page->agreementMoney($page->agreements->first()))->toMatchArray(['due' => 400.0, 'overdue' => 0.0]);
});

test('the donor page never sets a tranche in another currency against the budget', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create(['currency' => Currency::Usd, 'budget' => '1000.00']);
    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '900.00',
        'amount_transfered_currency' => Currency::Eur,
        'scheduled_date' => CarbonImmutable::today()->addMonth(),
        'ar_status' => ArStatus::Pending,
    ]);

    $page = Livewire::actingAs($user)->test('pages::donors.show', ['donor' => $agreement->donor_id])->instance();

    expect($page->agreementMoney($page->agreements->first())['unscheduled'])->toBe(1000.0);
});

test('the donor page lists reports still owed to the donor and records an agreement for it', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create();
    $owed = DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'title' => 'Interim narrative', 'submitted_on' => null]);
    DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'title' => 'Inception report', 'submitted_on' => CarbonImmutable::today()]);

    Livewire::actingAs($user)->test('pages::donors.show', ['donor' => $agreement->donor_id])
        ->assertSeeHtml('data-owed-donor-report="'.$owed->id.'"')
        ->assertDontSee('Inception report')
        ->call('recordAgreementForDonor')
        ->assertSet('showAgreementModal', true)
        ->assertSet('agreementDonorId', (string) $agreement->donor_id);
});

test('an overdue donor report reaches the proposal deadlines and warnings', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create();
    DonorReport::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'due_on' => CarbonImmutable::today()->subDays(3),
        'submitted_on' => null,
    ]);

    $page = Livewire::actingAs($user)->test('pages::proposals.show', ['proposal' => $agreement->proposal_id])->instance();

    expect(collect($page->deadlines)->where('kind', 'donor-report')->where('state', 'overdue'))->toHaveCount(1)
        ->and(collect($page->warnings)->pluck('key'))->toContain('overdue-donor-reports');
});

test('a sent tranche is not an overdue deadline on the proposal page', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create();
    $transfer = TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'scheduled_date' => CarbonImmutable::today()->subDays(10),
        'transfer_date' => CarbonImmutable::today()->subDays(2),
        'ar_status' => ArStatus::Pending,
    ]);

    $page = Livewire::actingAs($user)->test('pages::proposals.show', ['proposal' => $agreement->proposal_id])->instance();

    expect($page->transferIsOverdue($transfer))->toBeFalse()
        ->and(collect($page->deadlines)->pluck('key'))->not->toContain('transfer-'.$transfer->id);
});

test('a new proposal opens its status history, and editing it never changes the status', function () {
    $user = User::factory()->role('Financial')->create();

    $component = Livewire::actingAs($user)->test('pages::proposals.index')
        ->call('openCreateModal')
        ->set('name', 'Winter response')
        ->set('plannedDateBegin', '2026-01-01')
        ->set('plannedDateEnd', '2026-12-31')
        ->set('proposalStatus', ProposalStatus::Submitted->value)
        ->call('saveProposal')
        ->assertHasNoErrors();

    $proposal = Proposal::query()->where('name', 'Winter response')->firstOrFail();

    expect($proposal->status)->toBe(ProposalStatus::Submitted)
        ->and(ProposalStatusNote::query()->where('proposal_id', $proposal->id)->pluck('status')->all())->toBe([ProposalStatus::Submitted]);

    $component->call('openEditModal', $proposal->id)
        ->set('proposalStatus', ProposalStatus::Completed->value)
        ->call('saveProposal')
        ->assertHasNoErrors();

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Submitted);
});

test('the funding flow opens a proposal that is not on the current page', function () {
    $user = User::factory()->role('Financial')->create();
    $agreements = DonorProposal::factory()->count(16)->create();
    $offPage = Livewire::actingAs($user)->test('pages::proposals.index')->instance()->proposals->getCollection()->modelKeys();
    $missing = $agreements->pluck('proposal_id')->diff($offPage)->first();

    Livewire::actingAs($user)->test('pages::proposals.index')
        ->assertSeeHtml('href="'.route('proposals.show', $missing).'"');
});

test('a landed share under one percent reads <1, never 0, and a near-full one is never rounded up', function () {
    expect(DonorProposal::percentLabel(0, 1000))->toBe('0')
        ->and(DonorProposal::percentLabel(30, 100000))->toBe('<1')
        ->and(DonorProposal::percentLabel(996, 1000))->toBe('99')
        ->and(DonorProposal::percentLabel(5, 0))->toBe('0');
});

test('fees, a missing net amount and a failed transfer never turn money back into unscheduled', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create(['currency' => Currency::Usd, 'budget' => '1000.00']);
    $tranche = fn (array $attributes) => TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'amount_transfered_currency' => Currency::Usd,
        'amount_received_currency' => Currency::Usd,
        'scheduled_date' => CarbonImmutable::today()->subMonth(),
        'transfer_date' => CarbonImmutable::today()->subDays(20),
        ...$attributes,
    ]);
    $tranche(['payment_amount' => '400.00', 'receive_date' => CarbonImmutable::today()->subDays(10), 'net_amount_received' => '380.00', 'ar_status' => ArStatus::Received]);
    $tranche(['payment_amount' => '300.00', 'receive_date' => CarbonImmutable::today()->subDays(10), 'net_amount_received' => null, 'ar_status' => ArStatus::Received]);
    $tranche(['payment_amount' => '200.00', 'ar_status' => ArStatus::Failed]);

    $show = Livewire::actingAs($user)->test('pages::proposals.show', ['proposal' => $agreement->proposal_id])->instance();
    $index = Livewire::actingAs($user)->test('pages::proposals.index')->instance();
    $donor = Livewire::actingAs($user)->test('pages::donors.show', ['donor' => $agreement->donor_id])->instance();

    expect($show->agreementMoney[$agreement->id]['unscheduled'])->toBe(100.0)
        ->and($index->fundingPosition->firstWhere('currency', 'USD')['unscheduled'])->toBe(100.0)
        ->and($index->agreementMoney($index->proposals->first()->donorProposals->first())['unscheduled'])->toBe(100.0)
        ->and($donor->agreementMoney($donor->agreements->first()))->toMatchArray(['failed' => 200.0, 'unscheduled' => 100.0]);
});

test('the donor directory never lists money already sent as the next transfer due', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create(['currency' => Currency::Usd]);
    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '700.00',
        'amount_transfered_currency' => Currency::Usd,
        'scheduled_date' => CarbonImmutable::today()->subDays(30),
        'transfer_date' => CarbonImmutable::today()->subDays(25),
        'ar_status' => ArStatus::Pending,
    ]);
    $unsent = TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '300.00',
        'amount_transfered_currency' => Currency::Usd,
        'scheduled_date' => CarbonImmutable::today()->addDays(10),
        'ar_status' => ArStatus::Pending,
    ]);

    $page = Livewire::actingAs($user)->test('pages::donors.index')->instance();
    $donor = $page->donors->first();

    expect($page->donorMoney($donor)['next']['amount'])->toBe(300.0)
        ->and($donor->next_due_date)->toBe($unsent->scheduled_date->toDateString())
        ->and($page->upcomingTransfers->pluck('amount')->all())->toBe([300.0]);
});

test('an agreement recorded from a donor page always belongs to that donor', function () {
    $user = User::factory()->role('Financial')->create();
    $donor = Donor::factory()->create();
    $otherDonor = Donor::factory()->create();
    $proposal = Proposal::factory()->create();

    Livewire::actingAs($user)->test('pages::donors.show', ['donor' => $donor->id])
        ->call('recordAgreementForDonor')
        ->assertSeeHtml('data-locked-donor')
        ->set('agreementDonorId', (string) $otherDonor->id)
        ->set('agreementProposalId', (string) $proposal->id)
        ->set('budget', '5000')
        ->call('saveAgreement')
        ->assertHasNoErrors();

    expect(DonorProposal::query()->where('proposal_id', $proposal->id)->pluck('donor_id')->all())->toBe([$donor->id]);
});

test('an agreement with tranches shows its currency locked instead of offering a choice', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create(['currency' => Currency::Eur]);

    Livewire::actingAs($user)->test('pages::funding-agreements.index')
        ->call('openEditModal', $agreement->id)
        ->assertDontSeeHtml('data-locked-currency');

    TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id, 'amount_transfered_currency' => Currency::Eur]);

    Livewire::actingAs($user)->test('pages::funding-agreements.index')
        ->call('openEditModal', $agreement->id)
        ->assertSeeHtml('data-locked-currency');
});

test('the transfers ledger narrowed to one agreement opens a new tranche on it', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create(['currency' => Currency::Eur]);

    Livewire::actingAs($user)->withQueryParams(['agreement' => (string) $agreement->id])->test('pages::transfers.index')
        ->call('openCreateModal')
        ->assertSet('transferAgreementId', (string) $agreement->id)
        ->assertSet('transferredCurrency', 'EUR');
});
