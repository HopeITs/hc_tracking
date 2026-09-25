<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Enums\DonorReportType;
use App\Models\Account;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\TransfersTracking;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('guests are redirected from funding agreements', function () {
    $this->get(route('funding-agreements.index'))->assertRedirect(route('login'));
});

test('agreements can be filtered by donor and currency', function () {
    $user = User::factory()->role('Financial')->create();
    $unicef = Donor::factory()->create(['name' => 'UNICEF']);
    $echo = Donor::factory()->create(['name' => 'ECHO']);

    DonorProposal::factory()->create([
        'donor_id' => $unicef->id,
        'proposal_id' => Proposal::factory()->create(['name' => 'Dollar Proposal'])->id,
        'currency' => Currency::Usd,
    ]);
    DonorProposal::factory()->create([
        'donor_id' => $echo->id,
        'proposal_id' => Proposal::factory()->create(['name' => 'Euro Proposal'])->id,
        'currency' => Currency::Eur,
    ]);

    Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->assertSee('Dollar Proposal')
        ->assertSee('Euro Proposal')
        ->set('donor', (string) $unicef->id)
        ->assertSee('Dollar Proposal')
        ->assertDontSee('Euro Proposal')
        ->call('clearFilters')
        ->set('currency', Currency::Eur->value)
        ->assertDontSee('Dollar Proposal')
        ->assertSee('Euro Proposal');
});

test('the collection ledger splits each currency from committed to net without mixing currencies', function () {
    $user = User::factory()->role('Financial')->create();
    $dollars = DonorProposal::factory()->create(['budget' => '1000.00', 'currency' => Currency::Usd]);
    DonorProposal::factory()->create(['budget' => '500.00', 'currency' => Currency::Eur]);

    $tranche = fn (array $attributes) => TransfersTracking::factory()->create([
        'donor_proposal_id' => $dollars->id,
        'amount_transfered_currency' => Currency::Usd,
        'amount_received_currency' => Currency::Usd,
        'transfer_date' => null,
        ...$attributes,
    ]);

    $tranche(['payment_amount' => '400.00', 'net_amount_received' => '390.00', 'ar_status' => ArStatus::Received, 'transfer_date' => now()->subMonth(), 'scheduled_date' => now()->subMonth()]);
    $tranche(['payment_amount' => '200.00', 'ar_status' => ArStatus::Pending, 'scheduled_date' => now()->subDays(10)]);
    $tranche(['payment_amount' => '100.00', 'ar_status' => ArStatus::Pending, 'scheduled_date' => now()->addDays(10)]);
    $tranche(['payment_amount' => '50.00', 'ar_status' => ArStatus::Pending, 'transfer_date' => now()->subDay(), 'scheduled_date' => now()->subDay()]);

    $ledger = Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->assertSeeText('Cash-flow calendar')
        ->instance()
        ->ledger
        ->keyBy('currency');

    expect($ledger->keys()->all())->toBe(['EUR', 'USD'])
        ->and($ledger['EUR']['committed'])->toBe(500.0)
        ->and($ledger['EUR']['scheduled'])->toBe(0.0)
        ->and($ledger['USD'])->toMatchArray([
            'committed' => 1000.0,
            'scheduled' => 750.0,
            'unscheduled' => 250.0,
            'sent' => 450.0,
            'overdue' => 200.0,
            'upcoming' => 100.0,
            'inTransit' => 50.0,
            'received' => 390.0,
            'receivedGross' => 400.0,
            'lost' => 10.0,
            'gained' => 0.0,
            'failed' => 0.0,
            'netMissing' => 0.0,
        ]);
});

test('the cash-flow calendar totals each month and quarter per currency and runs in whole quarters around today', function () {
    $this->travelTo(now()->setDate(2026, 6, 15));

    $user = User::factory()->role('Financial')->create();
    $dollars = DonorProposal::factory()->create(['budget' => '1000.00', 'currency' => Currency::Usd]);
    $euros = DonorProposal::factory()->create(['budget' => '800.00', 'currency' => Currency::Eur]);

    foreach ([
        [$dollars, Currency::Usd, '300.00', ArStatus::Received, '2026-03-10', '2026-03-10'],
        [$dollars, Currency::Usd, '200.00', ArStatus::Pending, '2026-03-20', null],
        [$dollars, Currency::Usd, '100.00', ArStatus::Failed, '2026-03-25', '2026-03-25'],
        [$dollars, Currency::Usd, '400.00', ArStatus::Pending, '2027-02-01', null],
        [$euros, Currency::Eur, '800.00', ArStatus::Pending, '2026-03-05', null],
    ] as [$agreement, $currency, $payment, $status, $scheduled, $sent]) {
        TransfersTracking::factory()->create([
            'donor_proposal_id' => $agreement->id,
            'payment_amount' => $payment,
            'amount_transfered_currency' => $currency,
            'amount_received_currency' => $currency,
            'ar_status' => $status,
            'scheduled_date' => $scheduled,
            'transfer_date' => $sent,
        ]);
    }

    $calendar = Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->instance()
        ->calendar;

    expect($calendar['months'][0]['key'])->toBe('2026-01')
        ->and(end($calendar['months'])['key'])->toBe('2027-03')
        ->and(array_column($calendar['quarters'], 'key'))->toBe(['2026-Q1', '2026-Q2', '2026-Q3', '2026-Q4', '2027-Q1'])
        ->and(array_column($calendar['quarters'], 'index'))->toBe([0, 3, 6, 9, 12])
        ->and($calendar['today'])->toBe(round(5 + 14 / 30, 4))
        ->and(array_keys($calendar['inflow']))->toBe(['EUR', 'USD'])
        ->and($calendar['inflow']['USD']['months']['2026-03'])->toBe([
            'received' => 300.0,
            'late' => 200.0,
            'failed' => 100.0,
            'pending' => 0.0,
            'total' => 600.0,
        ])
        ->and($calendar['inflow']['USD']['months']['2027-02']['pending'])->toBe(400.0)
        ->and($calendar['inflow']['USD']['quarters'])->toBe([
            '2026-Q1' => ['received' => 300.0, 'pending' => 0.0, 'late' => 200.0, 'failed' => 100.0, 'total' => 600.0],
            '2027-Q1' => ['received' => 0.0, 'pending' => 400.0, 'late' => 0.0, 'failed' => 0.0, 'total' => 400.0],
        ])
        ->and($calendar['inflow']['USD']['quarterMax'])->toBe(600.0)
        ->and($calendar['inflow']['EUR']['months']['2026-03']['late'])->toBe(800.0);
});

test('the cash-flow calendar pads an empty schedule around today', function () {
    $this->travelTo(now()->setDate(2026, 6, 15));

    $user = User::factory()->role('Financial')->create();
    DonorProposal::factory()->create();

    $calendar = Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->assertSeeText('No tranches scheduled yet')
        ->instance()
        ->calendar;

    expect($calendar['months'][0]['key'])->toBe('2026-04')
        ->and(end($calendar['months'])['key'])->toBe('2026-12')
        ->and($calendar['inflow'])->toBe([]);
});

test('agreements can be narrowed to where their money is stuck, most urgent first', function () {
    $user = User::factory()->role('Financial')->create();

    $late = DonorProposal::factory()->create([
        'proposal_id' => Proposal::factory()->create(['name' => 'Late Proposal'])->id,
        'budget' => '1000.00',
        'currency' => Currency::Usd,
    ]);
    $onTime = DonorProposal::factory()->create([
        'proposal_id' => Proposal::factory()->create(['name' => 'On Time Proposal'])->id,
        'budget' => '1000.00',
        'currency' => Currency::Usd,
    ]);
    $paid = DonorProposal::factory()->create([
        'proposal_id' => Proposal::factory()->create(['name' => 'Paid Proposal'])->id,
        'budget' => '300.00',
        'currency' => Currency::Usd,
    ]);

    foreach ([
        [$late, '1000.00', ArStatus::Pending, now()->subDays(30), null],
        [$onTime, '1000.00', ArStatus::Pending, now()->addDays(30), null],
        [$paid, '300.00', ArStatus::Received, now()->subDays(60), now()->subDays(60)],
    ] as [$agreement, $payment, $status, $scheduled, $sent]) {
        TransfersTracking::factory()->create([
            'donor_proposal_id' => $agreement->id,
            'payment_amount' => $payment,
            'amount_transfered_currency' => Currency::Usd,
            'amount_received_currency' => Currency::Usd,
            'ar_status' => $status,
            'scheduled_date' => $scheduled,
            'transfer_date' => $sent,
        ]);
    }

    $component = Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->assertSeeInOrder(['Late Proposal', 'On Time Proposal']);

    expect($component->instance()->stageCounts)->toMatchArray([
        'overdue' => 1,
        'failed' => 0,
        'upcoming' => 1,
        'unscheduled' => 0,
        'received' => 1,
    ]);

    $component
        ->call('toggleStage', 'overdue')
        ->assertSet('stage', 'overdue')
        ->assertSee('Late Proposal')
        ->assertDontSee('On Time Proposal')
        ->assertDontSee('Paid Proposal')
        ->call('toggleStage', 'received')
        ->assertSee('Paid Proposal')
        ->assertDontSee('Late Proposal')
        ->call('toggleStage', 'received')
        ->assertSet('stage', '')
        ->call('toggleStage', 'not-a-stage')
        ->assertSet('stage', '');
});

test('an agreement can be created with a budget and currency', function () {
    $user = User::factory()->role('Financial')->create();
    $donor = Donor::factory()->create();
    $proposal = Proposal::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->call('openCreateModal')
        ->set('agreementDonorId', (string) $donor->id)
        ->set('agreementProposalId', (string) $proposal->id)
        ->set('budget', '750000.00')
        ->set('agreementCurrency', Currency::Usd->value)
        ->set('projectNum', 'SYR-2026-014')
        ->call('saveAgreement')
        ->assertHasNoErrors();

    $agreement = DonorProposal::query()->where('project_num', 'SYR-2026-014')->sole();

    expect($agreement->currency)->toBe(Currency::Usd)
        ->and((float) $agreement->budget)->toBe(750000.0);
});

test('a negative budget is rejected', function () {
    $user = User::factory()->role('Financial')->create();

    Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->call('openCreateModal')
        ->set('agreementDonorId', (string) Donor::factory()->create()->id)
        ->set('agreementProposalId', (string) Proposal::factory()->create()->id)
        ->set('budget', '-1')
        ->set('agreementCurrency', Currency::Usd->value)
        ->call('saveAgreement')
        ->assertHasErrors(['budget']);
});

test('an agreement with nothing under it can be deleted', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create();

    Livewire::actingAs($user)->test('pages::funding-agreements.index')
        ->call('openAgreementEdit', $agreement->id)
        ->call('confirmAgreementDelete')
        ->assertSet('confirmingAgreementDelete', true)
        ->call('deleteAgreement')
        ->assertSet('showAgreementModal', false);

    expect(DonorProposal::query()->whereKey($agreement->id)->exists())->toBeFalse();
});

test('an agreement holding tranches or donor reports is never deleted', function (string $holding) {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create();
    $holding === 'tranche'
        ? TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id])
        : DonorReport::factory()->create(['donor_proposal_id' => $agreement->id]);

    Livewire::actingAs($user)->test('pages::funding-agreements.index')
        ->call('openAgreementEdit', $agreement->id)
        ->assertSeeHtml('data-agreement-delete-blocked')
        ->call('deleteAgreement');

    expect(DonorProposal::query()->whereKey($agreement->id)->exists())->toBeTrue();
})->with(['tranche', 'report']);

test('deleting an agreement needs the delete permission', function () {
    $user = User::factory()->create();
    $agreement = DonorProposal::factory()->create();

    Livewire::actingAs($user)->test('pages::funding-agreements.index')
        ->call('deleteAgreement')
        ->assertForbidden();

    expect(DonorProposal::query()->whereKey($agreement->id)->exists())->toBeTrue();
});

test('deleting the agreement from its own page returns to the directory', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create();

    Livewire::actingAs($user)->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->call('openAgreementEdit', $agreement->id)
        ->call('deleteAgreement')
        ->assertRedirect(route('funding-agreements.index'));
});

test('the agreement currency is fixed once tranches exist', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create(['currency' => Currency::Usd]);
    TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id]);

    Livewire::actingAs($user)->test('pages::funding-agreements.index')
        ->call('openAgreementEdit', $agreement->id)
        ->set('agreementCurrency', 'EUR')
        ->call('saveAgreement')
        ->assertHasErrors(['agreementCurrency']);

    expect($agreement->fresh()->currency)->toBe(Currency::Usd);
});

test('a budget larger than its column is rejected, not sent to the database', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create();

    Livewire::actingAs($user)->test('pages::funding-agreements.index')
        ->call('openAgreementEdit', $agreement->id)
        ->set('budget', '10000000000000')
        ->call('saveAgreement')
        ->assertHasErrors(['budget' => 'max']);
});

test('recording an agreement against an unapproved proposal warns but is allowed', function () {
    $user = User::factory()->role('Financial')->create();
    $donor = Donor::factory()->create();
    $proposal = Proposal::factory()->create(['status' => 'concept note']);

    Livewire::actingAs($user)->test('pages::funding-agreements.index')
        ->call('openAgreementCreate')
        ->set('agreementDonorId', (string) $donor->id)
        ->set('agreementProposalId', (string) $proposal->id)
        ->assertSeeHtml('data-agreement-proposal-warning')
        ->set('budget', '1000')
        ->call('saveAgreement')
        ->assertHasNoErrors();

    expect(DonorProposal::query()->where('proposal_id', $proposal->id)->exists())->toBeTrue();
});

test('the agreement page links to its tranches in the transfers ledger', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create();
    TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id]);
    $other = TransfersTracking::factory()->create();

    Livewire::actingAs($user)->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->assertSeeHtml(e(route('transfers.index', ['agreement' => $agreement->id])));

    Livewire::actingAs($user)->withQueryParams(['agreement' => (string) $agreement->id])->test('pages::transfers.index')
        ->assertDontSeeHtml('wire:key="transfer-'.$other->id.'"')
        ->assertSeeHtml('data-agreement-chip');
});

test('agreement detail lists its tranches and totals received per currency', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create([
        'budget' => '500000.00',
        'currency' => Currency::Usd,
    ]);
    $account = Account::factory()->create(['account_name' => 'Operations USD']);

    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'account_id' => $account->id,
        'payment_amount' => '250000.00',
        'amount_transfered_currency' => Currency::Usd,
        'net_amount_received' => '248000.00',
        'amount_received_currency' => Currency::Usd,
        'ar_status' => ArStatus::Received,
    ]);

    $this->actingAs($user)
        ->get(route('funding-agreements.show', $agreement))
        ->assertOk()
        ->assertSeeText('250,000.00')
        ->assertSeeText('248,000.00')
        ->assertSeeText('Operations USD');
});

test('agreement detail renders an agreement with no tranches', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create();

    $this->actingAs($user)
        ->get(route('funding-agreements.show', $agreement))
        ->assertOk()
        ->assertSeeText('No tranches scheduled');
});

test('a user without funding agreement permissions cannot record one', function () {
    $viewer = User::factory()->role('Managers')->create();

    $fresh = fn () => Livewire::actingAs($viewer)->test('pages::funding-agreements.index');

    $fresh()->assertDontSeeHtml('data-test="create-agreement-button"');
    $fresh()->call('openCreateModal')->assertForbidden();
    $fresh()->set('agreementDonorId', (string) Donor::factory()->create()->id)
        ->set('agreementProposalId', (string) Proposal::factory()->create()->id)
        ->set('budget', '100')
        ->set('agreementCurrency', 'USD')
        ->call('saveAgreement')->assertForbidden();

    expect(DonorProposal::query()->count())->toBe(0);
});

test('agreement detail counts only received tranches as landed and adds the promise back up to the budget', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create(['budget' => '1000.00', 'currency' => Currency::Usd]);

    $tranche = fn (array $attributes) => TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'amount_transfered_currency' => Currency::Usd,
        'amount_received_currency' => Currency::Usd,
        ...$attributes,
    ]);

    $tranche(['payment_amount' => '400.00', 'net_amount_received' => '390.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => now()->subMonths(2)]);
    // A pending tranche with its net filled in ahead of time has not landed.
    $tranche(['payment_amount' => '300.00', 'net_amount_received' => '300.00', 'ar_status' => ArStatus::Pending, 'scheduled_date' => now()->subDays(10)]);
    $tranche(['payment_amount' => '100.00', 'ar_status' => ArStatus::Pending, 'scheduled_date' => now()->addDays(20)]);
    $tranche(['payment_amount' => '50.00', 'amount_transfered_currency' => Currency::Eur, 'ar_status' => ArStatus::Pending, 'scheduled_date' => now()->addDays(5)]);

    $money = Livewire::actingAs($user)
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->assertSeeText('10 days late')
        ->assertSeeText('1 tranche was sent in another currency and is not counted here.')
        ->instance()
        ->money;

    expect($money)->toMatchArray([
        'landed' => 390.0,
        'lost' => 10.0,
        'late' => 300.0,
        'upcoming' => 100.0,
        'unscheduled' => 200.0,
        'stillToCome' => 600.0,
        'percent' => 39,
        'foreignCount' => 1,
    ])->and($money['landed'] + $money['lost'] + $money['unrecorded'] + $money['late'] + $money['upcoming'] + $money['transit'] + $money['failed'] + $money['unscheduled'])->toBe(1000.0);
});

test('agreement detail links the donor, proposal, co-funders, the donor\'s other agreements and funded projects', function () {
    $user = User::factory()->role('Financial')->create();
    $donor = Donor::factory()->create(['name' => 'ECHO']);
    $proposal = Proposal::factory()->create(['name' => 'Relief Response']);
    $agreement = DonorProposal::factory()->create(['donor_id' => $donor->id, 'proposal_id' => $proposal->id]);
    $coFunder = DonorProposal::factory()->create([
        'donor_id' => Donor::factory()->create(['name' => 'GIZ'])->id,
        'proposal_id' => $proposal->id,
    ]);
    $sibling = DonorProposal::factory()->create([
        'donor_id' => $donor->id,
        'proposal_id' => Proposal::factory()->create(['name' => 'Winter Appeal'])->id,
    ]);
    $project = Project::factory()->create(['name' => 'Mobile Clinic Expansion']);
    $proposal->projects()->attach($project);

    $this->actingAs($user)
        ->get(route('funding-agreements.show', $agreement))
        ->assertOk()
        ->assertSee(route('donors.show', $donor), false)
        ->assertSee(route('proposals.show', $proposal), false)
        ->assertSee(route('funding-agreements.show', $coFunder), false)
        ->assertSee(route('funding-agreements.show', $sibling), false)
        ->assertSee(route('projects.show', $project), false)
        ->assertSeeText('GIZ')
        ->assertSeeText('Winter Appeal')
        ->assertSeeText('Mobile Clinic Expansion');
});

test('a tranche saved from the agreement page is filed against that agreement even when the payload names another', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create(['currency' => Currency::Eur]);
    $other = DonorProposal::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->call('openTransferCreate', $agreement->id)
        ->assertSet('transferAgreementId', (string) $agreement->id)
        ->assertSet('transferredCurrency', 'EUR')
        ->set('transferAgreementId', (string) $other->id)
        ->set('paymentAmount', '250')
        ->set('scheduledDate', now()->addMonth()->toDateString())
        ->call('saveTransfer')
        ->assertHasNoErrors()
        ->assertSet('showTransferModal', false);

    expect($agreement->transfers()->count())->toBe(1)
        ->and($other->transfers()->count())->toBe(0);
});

test('a tranche the donor sent that has not landed past its date is in transit on both pages, never overdue', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create(['budget' => '1000.00', 'currency' => Currency::Usd]);
    $sent = TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '400.00',
        'amount_transfered_currency' => Currency::Usd,
        'ar_status' => ArStatus::Pending,
        'scheduled_date' => now()->subDays(12),
        'transfer_date' => now()->subDays(10),
    ]);

    $index = Livewire::actingAs($user)->test('pages::funding-agreements.index');

    expect($index->instance()->trancheState($sent))->toBe('transit')
        ->and($index->instance()->stageCounts)->toMatchArray(['in_transit' => 1, 'overdue' => 0])
        ->and($index->instance()->ledger->first())->toMatchArray(['inTransit' => 400.0, 'overdue' => 0.0]);

    $show = Livewire::actingAs($user)
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->assertSeeText('in transit, 12 days past its date')
        ->assertDontSeeText('12 days late');

    expect($show->instance()->trancheState($sent))->toBe('transit')
        ->and($show->instance()->money)->toMatchArray(['transit' => 400.0, 'late' => 0.0])
        ->and(array_column($show->instance()->attention, 'key'))->toContain('transit-'.$sent->id);
});

test('failed transfers are their own stage, apart from upcoming and in transit', function () {
    $user = User::factory()->role('Financial')->create();
    $failing = DonorProposal::factory()->create([
        'proposal_id' => Proposal::factory()->create(['name' => 'Failing Proposal'])->id,
        'budget' => '1000.00',
        'currency' => Currency::Usd,
    ]);
    DonorProposal::factory()->create([
        'proposal_id' => Proposal::factory()->create(['name' => 'Quiet Proposal'])->id,
        'budget' => '1000.00',
        'currency' => Currency::Usd,
    ]);

    foreach ([[now()->subDays(3), null], [now()->subDays(3), now()->subDays(4)]] as [$scheduled, $sentOn]) {
        TransfersTracking::factory()->create([
            'donor_proposal_id' => $failing->id,
            'payment_amount' => '150.00',
            'amount_transfered_currency' => Currency::Usd,
            'ar_status' => ArStatus::Failed,
            'scheduled_date' => $scheduled,
            'transfer_date' => $sentOn,
        ]);
    }

    $component = Livewire::actingAs($user)->test('pages::funding-agreements.index');

    expect($component->instance()->ledger->first())->toMatchArray([
        'failed' => 300.0,
        'failedCount' => 2,
        'inTransit' => 0.0,
        'upcoming' => 0.0,
        'unscheduled' => 1700.0,
    ])->and($component->instance()->stageCounts)->toMatchArray(['failed' => 1, 'in_transit' => 0]);

    $component
        ->assertSeeHtml('data-ledger-figure="failed"')
        ->call('toggleStage', 'failed')
        ->assertSee('Failing Proposal')
        ->assertDontSee('Quiet Proposal');
});

test('a received tranche with no net recorded counts as nothing landed on both pages and is flagged', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create(['budget' => '1000.00', 'currency' => Currency::Usd]);

    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '300.00',
        'amount_transfered_currency' => Currency::Usd,
        'amount_received_currency' => Currency::Usd,
        'net_amount_received' => null,
        'ar_status' => ArStatus::Received,
        'scheduled_date' => now()->subMonth(),
    ]);
    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '200.00',
        'amount_transfered_currency' => Currency::Usd,
        'amount_received_currency' => Currency::Usd,
        'net_amount_received' => '195.00',
        'ar_status' => ArStatus::Received,
        'scheduled_date' => now()->subMonth(),
    ]);

    $index = Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->assertSeeHtml('data-ledger-net-missing');

    expect($index->instance()->ledger->first())->toMatchArray([
        'received' => 195.0,
        'receivedGross' => 500.0,
        'netMissing' => 300.0,
        'netMissingCount' => 1,
        'lost' => 5.0,
    ])->and($index->instance()->agreementMoney($agreement->load('transfers.account')))->toMatchArray([
        'received' => 195.0,
        'share' => 19,
        'missingNet' => 1,
    ]);

    $show = Livewire::actingAs($user)->test('pages::funding-agreements.show', ['donorProposal' => $agreement]);

    expect($show->instance()->money)->toMatchArray([
        'landed' => 195.0,
        'lost' => 5.0,
        'unrecorded' => 300.0,
        'unrecordedCount' => 1,
        'percent' => 19,
    ])->and(array_column($show->instance()->attention, 'key'))->toContain('net-missing');
});

test('a tranche cannot be marked received without the net amount and the date it landed', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->call('openTransferCreate', $agreement->id)
        ->set('paymentAmount', '250')
        ->set('transferArStatus', ArStatus::Received->value)
        ->call('saveTransfer')
        ->assertHasErrors(['netAmountReceived' => 'required_if', 'receiveDate' => 'required_if'])
        ->set('netAmountReceived', '245')
        ->set('receiveDate', now()->toDateString())
        ->call('saveTransfer')
        ->assertHasNoErrors();

    expect($agreement->transfers()->sole()->net_amount_received)->toBe('245.00');
});

test('a sliver of money landed reads as under one percent, not zero', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create(['budget' => '100000.00', 'currency' => Currency::Usd]);

    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '500.00',
        'amount_transfered_currency' => Currency::Usd,
        'amount_received_currency' => Currency::Usd,
        'net_amount_received' => '500.00',
        'ar_status' => ArStatus::Received,
    ]);

    $index = Livewire::actingAs($user)->test('pages::funding-agreements.index');

    expect($index->instance()->percentLabel(500.0, 100000.0))->toBe('<1')
        ->and($index->instance()->percentLabel(0.0, 100000.0))->toBe('0')
        ->and($index->instance()->percentLabel(40000.0, 100000.0))->toBe('40');

    Livewire::actingAs($user)
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->assertSeeHtml('data-test="dial-percent">&lt;1<');
});

test('reports owed to the donor can be added, submitted and deleted on the agreement', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create();

    $component = Livewire::actingAs($user)
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->call('openReportCreate')
        ->assertSet('showReportModal', true)
        ->set('reportTitle', 'Interim financial report')
        ->set('reportType', DonorReportType::Financial->value)
        ->set('reportDueOn', now()->subDays(5)->toDateString())
        ->call('saveReport')
        ->assertHasNoErrors()
        ->assertSet('showReportModal', false)
        ->assertSeeText('Interim financial report')
        ->assertSeeText('5 days overdue');

    $report = DonorReport::query()->sole();

    expect($report->type)->toBe(DonorReportType::Financial)
        ->and(array_column($component->instance()->attention, 'key'))->toContain('report-'.$report->id);

    $component->call('markReportSubmitted', $report->id);

    expect($report->fresh()->submitted_on->isToday())->toBeTrue()
        ->and(array_column($component->instance()->attention, 'key'))->not->toContain('report-'.$report->id);

    $component
        ->call('confirmReportDelete', $report->id)
        ->assertSet('showReportDeleteModal', true)
        ->call('deleteReport')
        ->assertSet('showReportDeleteModal', false);

    expect(DonorReport::query()->count())->toBe(0);
});

test('a donor report cannot be submitted in the future or reached from another agreement', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create();
    $foreign = DonorReport::factory()->create();

    $fresh = fn () => Livewire::actingAs($user)->test('pages::funding-agreements.show', ['donorProposal' => $agreement]);

    $fresh()->call('openReportCreate')
        ->set('reportTitle', 'Final report')
        ->set('reportDueOn', now()->toDateString())
        ->set('reportSubmittedOn', now()->addDay()->toDateString())
        ->call('saveReport')
        ->assertHasErrors(['reportSubmittedOn' => 'before_or_equal']);

    $fresh()->call('openReportEdit', $foreign->id)->assertNotFound();
    $fresh()->call('markReportSubmitted', $foreign->id)->assertNotFound();

    expect($foreign->fresh()->submitted_on)->toBeNull();
});

test('a user who cannot update agreements cannot manage donor reports', function () {
    $viewer = User::factory()->role('Managers')->create();
    $agreement = DonorProposal::factory()->create();
    $report = DonorReport::factory()->create(['donor_proposal_id' => $agreement->id]);

    $fresh = fn () => Livewire::actingAs($viewer)->test('pages::funding-agreements.show', ['donorProposal' => $agreement]);

    $fresh()->assertDontSeeHtml('data-test="add-donor-report"')->assertSeeText($report->title);
    $fresh()->call('openReportCreate')->assertForbidden();
    $fresh()->call('markReportSubmitted', $report->id)->assertForbidden();
    $fresh()->call('confirmReportDelete', $report->id)->assertForbidden();

    expect($report->fresh()->submitted_on)->toBeNull();
});

test('the index flags agreements with an overdue donor report', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create();
    DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'due_on' => now()->subDays(2)->toDateString()]);

    Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->assertSeeHtml('data-report-overdue')
        ->assertSeeText('1 donor report overdue');
});

test('agreement detail shows when the donor was last contacted and flags a donor gone quiet', function () {
    $user = User::factory()->role('Financial')->create();
    $recent = DonorProposal::factory()->create([
        'donor_id' => Donor::factory()->create(['last_contacted_on' => now()->subDays(10)->toDateString()])->id,
    ]);
    $quiet = DonorProposal::factory()->create([
        'donor_id' => Donor::factory()->create(['last_contacted_on' => now()->subDays(120)->toDateString()])->id,
    ]);

    $recentPage = Livewire::actingAs($user)
        ->test('pages::funding-agreements.show', ['donorProposal' => $recent])
        ->assertSeeText('Last contacted');

    expect(array_column($recentPage->instance()->attention, 'key'))->not->toContain('contact');

    $quietPage = Livewire::actingAs($user)
        ->test('pages::funding-agreements.show', ['donorProposal' => $quiet])
        ->assertSeeText('for 120 days');

    expect(array_column($quietPage->instance()->attention, 'key'))->toContain('contact');
});

test('agreement detail shows the gross amount received beside what landed', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create(['currency' => Currency::Usd]);

    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '1000.00',
        'amount_transfered_currency' => Currency::Usd,
        'amount_received' => '990.00',
        'net_amount_received' => '985.00',
        'amount_received_currency' => Currency::Usd,
        'ar_status' => ArStatus::Received,
    ]);

    Livewire::actingAs($user)
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->assertSeeText('gross 990.00')
        ->assertSeeText('985.00 USD landed');
});
