<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\Account;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Proposal;
use App\Models\TransfersTracking;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('guests are redirected from transfer tracking', function () {
    $this->get(route('transfers.index'))->assertRedirect(route('login'));
});

test('transfers can be filtered by receipt status and account', function () {
    $user = User::factory()->role('Financial')->create();
    $operations = Account::factory()->create(['account_name' => 'Operations USD']);
    $reserve = Account::factory()->create(['account_name' => 'Reserve EUR']);

    TransfersTracking::factory()->create([
        'account_id' => $operations->id,
        'ar_status' => ArStatus::Received,
        'payment_amount' => '111111.00',
    ]);
    TransfersTracking::factory()->create([
        'account_id' => $reserve->id,
        'ar_status' => ArStatus::Pending,
        'payment_amount' => '222222.00',
    ]);

    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->assertSee('111,111.00')
        ->assertSee('222,222.00')
        ->set('arStatus', ArStatus::Pending->value)
        ->assertDontSee('111,111.00')
        ->assertSee('222,222.00')
        ->call('clearFilters')
        ->set('account', (string) $operations->id)
        ->assertSee('111,111.00')
        ->assertDontSee('222,222.00');
});

test('a tranche can be scheduled with no account assigned', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->call('openCreateModal')
        ->set('transferAgreementId', (string) $agreement->id)
        ->set('transferAccountId', '')
        ->set('paymentAmount', '100000.00')
        ->set('transferredCurrency', Currency::Usd->value)
        ->set('scheduledDate', '2026-05-01')
        ->set('transferArStatus', ArStatus::Pending->value)
        ->call('saveTransfer')
        ->assertHasNoErrors();

    $transfer = TransfersTracking::query()->where('donor_proposal_id', $agreement->id)->sole();

    expect($transfer->account_id)->toBeNull()
        ->and($transfer->ar_status)->toBe(ArStatus::Pending);
});

test('a pending tranche with no received figures saves those columns as null', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->call('openCreateModal')
        ->set('transferAgreementId', (string) $agreement->id)
        ->set('paymentAmount', '100000.00')
        ->set('transferredCurrency', Currency::Usd->value)
        ->set('scheduledDate', '')
        ->set('amountReceived', '')
        ->set('netAmountReceived', '')
        ->set('transferArStatus', ArStatus::Pending->value)
        ->call('saveTransfer')
        ->assertHasNoErrors();

    $transfer = TransfersTracking::query()->where('donor_proposal_id', $agreement->id)->sole();

    expect($transfer->scheduled_date)->toBeNull()
        ->and($transfer->amount_received)->toBeNull()
        ->and($transfer->net_amount_received)->toBeNull();
});

test('the three currency columns are recorded independently', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create();
    $account = Account::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->call('openCreateModal')
        ->set('transferAgreementId', (string) $agreement->id)
        ->set('transferAccountId', (string) $account->id)
        ->set('paymentAmount', '100000.00')
        ->set('transferredCurrency', Currency::Usd->value)
        ->set('fees', '250.00')
        ->set('feesCurrency', Currency::Eur->value)
        ->set('amountReceived', '99750.00')
        ->set('netAmountReceived', '99500.00')
        ->set('receivedCurrency', Currency::Syp->value)
        ->set('exchangeRate', '1.000000')
        ->set('scheduledDate', '2026-05-01')
        ->set('receiveDate', '2026-05-03')
        ->set('transferArStatus', ArStatus::Received->value)
        ->call('saveTransfer')
        ->assertHasNoErrors();

    $transfer = TransfersTracking::query()->where('donor_proposal_id', $agreement->id)->sole();

    expect($transfer->amount_transfered_currency)->toBe(Currency::Usd)
        ->and($transfer->fees_currency)->toBe(Currency::Eur)
        ->and($transfer->amount_received_currency)->toBe(Currency::Syp);
});

test('a negative payment amount is rejected', function () {
    $user = User::factory()->role('Financial')->create();

    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->call('openCreateModal')
        ->set('transferAgreementId', (string) DonorProposal::factory()->create()->id)
        ->set('paymentAmount', '-5')
        ->set('transferredCurrency', Currency::Usd->value)
        ->set('transferArStatus', ArStatus::Pending->value)
        ->call('saveTransfer')
        ->assertHasErrors(['paymentAmount']);
});

test('a tranche can be deleted from its edit form', function () {
    $user = User::factory()->role('Financial')->create();
    $transfer = TransfersTracking::factory()->create();

    Livewire::actingAs($user)->test('pages::transfers.index')
        ->call('openTransferEdit', $transfer->id)
        ->call('confirmTransferDelete')
        ->assertSet('confirmingTransferDelete', true)
        ->call('deleteTransfer')
        ->assertSet('showTransferModal', false);

    expect(TransfersTracking::query()->whereKey($transfer->id)->exists())->toBeFalse();
});

test('deleting a tranche needs the delete permission', function () {
    $user = User::factory()->create();
    $transfer = TransfersTracking::factory()->create();

    Livewire::actingAs($user)->test('pages::transfers.index')
        ->call('deleteTransfer')
        ->assertForbidden();

    expect(TransfersTracking::query()->whereKey($transfer->id)->exists())->toBeTrue();
});

test('tranche dates and status must agree', function (array $input, string $error) {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create();

    Livewire::actingAs($user)->test('pages::transfers.index')
        ->call('openTransferCreate', $agreement->id)
        ->set(['paymentAmount' => '100', ...$input])
        ->call('saveTransfer')
        ->assertHasErrors([$error]);
})->with([
    'pending with a landing date' => [['transferArStatus' => 'pending', 'receiveDate' => '2026-01-01'], 'receiveDate'],
    'landed before it was sent' => [['transferArStatus' => 'received', 'netAmountReceived' => '100', 'transferDate' => '2026-02-01', 'receiveDate' => '2026-01-01'], 'receiveDate'],
    'sent in the future' => [['transferDate' => '2999-01-01'], 'transferDate'],
    'zero exchange rate' => [['exchangeRate' => '0'], 'exchangeRate'],
    'amount above its column' => [['paymentAmount' => '10000000000000'], 'paymentAmount'],
]);

test('choosing an agreement for a new tranche takes its currency', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create(['currency' => Currency::Eur]);

    Livewire::actingAs($user)->test('pages::transfers.index')
        ->call('openTransferCreate')
        ->set('transferAgreementId', (string) $agreement->id)
        ->assertSet('transferredCurrency', 'EUR')
        ->assertSet('feesCurrency', 'EUR')
        ->assertSet('receivedCurrency', 'EUR');
});

test('transfer totals are never summed across currencies', function () {
    $user = User::factory()->role('Financial')->create();

    TransfersTracking::factory()->create([
        'payment_amount' => '100000.00',
        'amount_transfered_currency' => Currency::Usd,
    ]);
    TransfersTracking::factory()->create([
        'payment_amount' => '50000.00',
        'amount_transfered_currency' => Currency::Eur,
    ]);

    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->assertSee('100,000.00')
        ->assertSee('50,000.00')
        ->assertDontSee('150,000.00');
});

test('transfer tracking renders with no transfers', function () {
    $user = User::factory()->role('Financial')->create();

    Livewire::actingAs($user)->test('pages::transfers.index')->assertSee('No transfers recorded');
});

test('a user without transfer permissions cannot schedule a tranche', function () {
    $viewer = User::factory()->role('Managers')->create()->givePermissionTo('transfers.view');
    $agreement = DonorProposal::factory()->create(['currency' => Currency::Usd]);

    $fresh = fn () => Livewire::actingAs($viewer)->test('pages::transfers.index');

    $fresh()->assertDontSeeHtml('data-test="create-transfer-button"');
    $fresh()->call('openCreateModal')->assertForbidden();
    $fresh()->set('transferAgreementId', (string) $agreement->id)
        ->set('paymentAmount', '100.00')
        ->set('transferredCurrency', Currency::Usd->value)
        ->set('transferArStatus', ArStatus::Pending->value)
        ->call('saveTransfer')->assertForbidden();

    expect(TransfersTracking::query()->count())->toBe(0);
});

test('the ledger counts shortfall only on money that finished its journey', function () {
    $user = User::factory()->role('Financial')->create();

    // Landed short: 100,000 left the donor, 97,500 arrived, 1,500 of it in fees.
    TransfersTracking::factory()->create([
        'payment_amount' => '100000.00',
        'amount_transfered_currency' => Currency::Usd,
        'fees' => '1500.00',
        'fees_currency' => Currency::Usd,
        'amount_received' => '99000.00',
        'net_amount_received' => '97500.00',
        'amount_received_currency' => Currency::Usd,
        'scheduled_date' => '2026-01-05',
        'transfer_date' => '2026-01-06',
        'receive_date' => '2026-01-10',
        'ar_status' => ArStatus::Received,
    ]);

    // Still travelling. Nothing has been lost yet, so nothing may be reported as lost.
    TransfersTracking::factory()->create([
        'payment_amount' => '40000.00',
        'amount_transfered_currency' => Currency::Usd,
        'net_amount_received' => null,
        'scheduled_date' => '2026-01-08',
        'transfer_date' => '2026-01-09',
        'receive_date' => null,
        'ar_status' => ArStatus::Pending,
    ]);

    $line = Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->instance()
        ->ledger
        ->firstWhere('currency', Currency::Usd->value);

    expect($line['sent'])->toBe(140000.0)
        ->and($line['settledSent'])->toBe(100000.0)
        ->and($line['landed'])->toBe(97500.0)
        ->and($line['shrink'])->toBe(2500.0)
        ->and($line['fees'])->toBe(1500.0)
        ->and($line['exchangeGap'])->toBe(1000.0)
        ->and($line['inFlight'])->toBe(40000.0);
});

test('a tranche that landed in another currency is never subtracted from what was sent', function () {
    $user = User::factory()->role('Financial')->create();

    TransfersTracking::factory()->create([
        'payment_amount' => '100000.00',
        'amount_transfered_currency' => Currency::Usd,
        'fees' => '0.00',
        'fees_currency' => Currency::Usd,
        'amount_received' => '90000.00',
        'net_amount_received' => '90000.00',
        'amount_received_currency' => Currency::Eur,
        'scheduled_date' => '2026-01-05',
        'transfer_date' => '2026-01-06',
        'receive_date' => '2026-01-10',
        'ar_status' => ArStatus::Received,
    ]);

    $line = Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->instance()
        ->ledger
        ->firstWhere('currency', Currency::Usd->value);

    // 100,000 USD and 90,000 EUR are not comparable, so there is no shortfall
    // to report — only a converted tranche to disclose.
    expect($line['sent'])->toBe(100000.0)
        ->and($line['settledSent'])->toBe(0.0)
        ->and($line['landed'])->toBe(0.0)
        ->and($line['shrink'])->toBe(0.0)
        ->and($line['convertedCount'])->toBe(1);
});

/**
 * Two pending tranches whose only difference is which side of today they fall
 * on, so a stage or a sort is the only thing that can tell them apart.
 *
 * @return array{0: TransfersTracking, 1: TransfersTracking}
 */
function overdueAndUpcomingTranche(): array
{
    $overdue = TransfersTracking::factory()->create([
        'payment_amount' => '111111.00',
        'scheduled_date' => now()->subMonth()->toDateString(),
        'transfer_date' => null,
        'ar_status' => ArStatus::Pending,
    ]);
    $upcoming = TransfersTracking::factory()->create([
        'payment_amount' => '222222.00',
        'scheduled_date' => now()->addMonth()->toDateString(),
        'transfer_date' => null,
        'ar_status' => ArStatus::Pending,
    ]);

    return [$overdue, $upcoming];
}

test('a ledger figure narrows the ladder to that stage and clears when chosen again', function () {
    $user = User::factory()->role('Financial')->create();
    [$overdue, $upcoming] = overdueAndUpcomingTranche();

    $component = Livewire::actingAs($user)->test('pages::transfers.index');

    // Asserted on the ladder rows, not on the figures: the ledger above keeps
    // describing the whole filtered set on purpose while a stage narrows only
    // the ladder, so both amounts stay on the page either way.
    expect($component->instance()->transfers->total())->toBe(2);

    $component
        ->call('toggleStage', 'overdue')
        ->assertSet('stage', 'overdue')
        ->assertSeeHtml('wire:key="transfer-'.$overdue->id.'"')
        ->assertDontSeeHtml('wire:key="transfer-'.$upcoming->id.'"');

    expect($component->instance()->transfers->total())->toBe(1);

    $component
        ->call('toggleStage', 'overdue')
        ->assertSet('stage', '')
        ->assertSeeHtml('wire:key="transfer-'.$upcoming->id.'"');
});

test('the default ladder order leads with money that is overdue', function () {
    $user = User::factory()->role('Financial')->create();
    [$overdue, $upcoming] = overdueAndUpcomingTranche();

    $overdueRow = 'wire:key="transfer-'.$overdue->id.'"';
    $upcomingRow = 'wire:key="transfer-'.$upcoming->id.'"';

    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->assertSet('sort', 'attention')
        ->assertSeeHtmlInOrder([$overdueRow, $upcomingRow])
        // Largest amount reverses the pair, so the order is the sort's doing
        // and not the order the rows happened to be created in.
        ->call('setSort', 'amount')
        ->assertSeeHtmlInOrder([$upcomingRow, $overdueRow]);
});

test('a tranche that arrived over never cancels one that arrived short', function () {
    $user = User::factory()->role('Financial')->create();

    $settled = fn (string $sent, string $landed): array => [
        'payment_amount' => $sent,
        'amount_transfered_currency' => Currency::Usd,
        'fees' => '0.00',
        'fees_currency' => Currency::Usd,
        'amount_received' => $landed,
        'net_amount_received' => $landed,
        'amount_received_currency' => Currency::Usd,
        'scheduled_date' => '2026-01-05',
        'transfer_date' => '2026-01-06',
        'receive_date' => '2026-01-10',
        'ar_status' => ArStatus::Received,
    ];

    // 10,000 genuinely never arrived.
    TransfersTracking::factory()->create($settled('100000.00', '90000.00'));
    // A favourable rate on a different tranche. Real, but it did not put the
    // missing 10,000 back, so it must not be allowed to erase it.
    TransfersTracking::factory()->create($settled('50000.00', '80000.00'));

    $line = Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->instance()
        ->ledger
        ->firstWhere('currency', Currency::Usd->value);

    expect($line['shrink'])->toBe(10000.0)
        ->and($line['overage'])->toBe(30000.0);
});

test('a tranche marked received with no net amount is not reported as landed', function () {
    $user = User::factory()->role('Financial')->create();

    TransfersTracking::factory()->create([
        'payment_amount' => '100000.00',
        'amount_transfered_currency' => Currency::Usd,
        'net_amount_received' => null,
        'amount_received' => null,
        'amount_received_currency' => Currency::Usd,
        'scheduled_date' => '2026-01-05',
        'transfer_date' => '2026-01-06',
        'receive_date' => '2026-01-10',
        'ar_status' => ArStatus::Received,
    ]);

    // The measure's own label is the assertion: it described this tranche as
    // "Sent 100,000.00 USD · Landed" while nothing had been recorded.
    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->assertSeeHtml('aria-label="Sent 100,000.00 USD · Not recorded yet"')
        ->assertDontSeeHtml('aria-label="Sent 100,000.00 USD · Landed"');
});

test('an exchange rate of zero is shown as zero rather than an empty cell', function () {
    $user = User::factory()->role('Financial')->create();

    TransfersTracking::factory()->create([
        'payment_amount' => '1000.00',
        'exchange_rate' => '0.000000',
        'ar_status' => ArStatus::Pending,
        'scheduled_date' => '2026-01-05',
        'transfer_date' => null,
    ]);

    // Trimming the raw decimal:6 string turned "0.000000" into "".
    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->assertSeeHtml('<dd class="mt-0.5 font-semibold tabular-nums">0</dd>');
});

test('a receive date before the transfer date never reports a negative transit time', function () {
    $user = User::factory()->role('Financial')->create();

    TransfersTracking::factory()->create([
        'payment_amount' => '1000.00',
        'amount_transfered_currency' => Currency::Usd,
        'net_amount_received' => '1000.00',
        'amount_received' => '1000.00',
        'amount_received_currency' => Currency::Usd,
        'scheduled_date' => '2026-01-05',
        'transfer_date' => '2026-01-10',
        'receive_date' => '2026-01-07',
        'ar_status' => ArStatus::Received,
    ]);

    // It falls back to the plain state rather than reporting the typo as a fact.
    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->assertDontSee('-3 day')
        ->assertDontSee('−3 day')
        ->assertSeeHtml('aria-label="Sent 1,000.00 USD, landed 1,000.00 USD"');
});

test('the headline separates what is expected from what has actually been sent', function () {
    $user = User::factory()->role('Financial')->create();

    // Not sent: no transfer date.
    TransfersTracking::factory()->create([
        'payment_amount' => '100000.00',
        'amount_transfered_currency' => Currency::Usd,
        'scheduled_date' => now()->subMonth()->toDateString(),
        'transfer_date' => null,
        'ar_status' => ArStatus::Pending,
    ]);
    // Sent, still moving.
    TransfersTracking::factory()->create([
        'payment_amount' => '40000.00',
        'amount_transfered_currency' => Currency::Usd,
        'scheduled_date' => now()->subDays(5)->toDateString(),
        'transfer_date' => now()->subDays(4)->toDateString(),
        'ar_status' => ArStatus::Pending,
    ]);

    $line = Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->instance()
        ->ledger
        ->firstWhere('currency', Currency::Usd->value);

    // The headline used to call all 140,000 "Sent" while the rows below said
    // "Nothing has left the donor yet" about most of it.
    expect($line['expected'])->toBe(140000.0)
        ->and($line['sent'])->toBe(40000.0);
});

test('a small fee is never reported as a zero percent shortfall', function () {
    $user = User::factory()->role('Financial')->create();

    // 30 on 100,000 is 0.03% — the ordinary wire-fee case.
    TransfersTracking::factory()->create([
        'payment_amount' => '100000.00',
        'amount_transfered_currency' => Currency::Usd,
        'fees' => '30.00',
        'fees_currency' => Currency::Usd,
        'amount_received' => '99970.00',
        'net_amount_received' => '99970.00',
        'amount_received_currency' => Currency::Usd,
        'scheduled_date' => '2026-01-05',
        'transfer_date' => '2026-01-06',
        'receive_date' => '2026-01-07',
        'ar_status' => ArStatus::Received,
    ]);

    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->assertDontSee('−0%')
        ->assertDontSee('-0%')
        ->assertSee('<0.1%');
});

test('landed in full is never claimed while money is still outstanding', function () {
    $user = User::factory()->role('Financial')->create();

    $settled = [
        'payment_amount' => '5000.00',
        'amount_transfered_currency' => Currency::Usd,
        'fees' => '0.00',
        'fees_currency' => Currency::Usd,
        'amount_received' => '5000.00',
        'net_amount_received' => '5000.00',
        'amount_received_currency' => Currency::Usd,
        'scheduled_date' => '2026-01-05',
        'transfer_date' => '2026-01-06',
        'receive_date' => '2026-01-07',
        'ar_status' => ArStatus::Received,
    ];

    TransfersTracking::factory()->create($settled);

    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->assertSee('Landed in full')
        ->assertDontSee('Settled money landed in full');

    // One overdue tranche, and the unqualified claim must stop.
    TransfersTracking::factory()->create([
        'payment_amount' => '5000000.00',
        'amount_transfered_currency' => Currency::Usd,
        'scheduled_date' => now()->subMonth()->toDateString(),
        'transfer_date' => null,
        'ar_status' => ArStatus::Pending,
    ]);

    // The row badge may still say "Landed in full" — that tranche did. It is
    // the currency-wide headline that must no longer make the claim.
    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->assertSee('Settled money landed in full');
});

test('the transfers ledger finds a tranche by either project number', function () {
    $user = User::factory()->role('Financial')->create();
    $echo = TransfersTracking::factory()->create(['donor_proposal_id' => DonorProposal::factory()->create([
        'proposal_id' => Proposal::factory()->create(['internal_project_number' => 'HC-2026-086'])->id,
        'external_project_number' => 'ECHO-86',
    ])->id]);
    $unicef = TransfersTracking::factory()->create(['donor_proposal_id' => DonorProposal::factory()->create([
        'proposal_id' => Proposal::factory()->create(['internal_project_number' => 'HC-2026-087'])->id,
        'external_project_number' => 'UNICEF-87',
    ])->id]);

    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->set('search', 'ECHO-86')
        ->assertSeeHtml('wire:key="transfer-'.$echo->id.'"')
        ->assertDontSeeHtml('wire:key="transfer-'.$unicef->id.'"')
        ->set('search', 'HC-2026-087')
        ->assertSeeHtml('wire:key="transfer-'.$unicef->id.'"')
        ->assertDontSeeHtml('wire:key="transfer-'.$echo->id.'"');
});

test('each tranche shows its proposal\'s internal number beside the external one', function () {
    $user = User::factory()->role('Financial')->create();
    TransfersTracking::factory()->create(['donor_proposal_id' => DonorProposal::factory()->create([
        'proposal_id' => Proposal::factory()->create(['internal_project_number' => 'HC-2026-088'])->id,
        'external_project_number' => 'ECHO-88',
    ])->id]);
    TransfersTracking::factory()->create(['donor_proposal_id' => DonorProposal::factory()->create([
        'proposal_id' => Proposal::factory()->create(['internal_project_number' => 'HC-2026-089'])->id,
    ])->id]);

    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->assertSeeInOrder(['HC-2026-088', 'External no. ECHO-88'])
        ->assertSee('HC-2026-089')
        ->assertSee('No external project number');
});

test('the transfer picker names each agreement by both project numbers', function () {
    $user = User::factory()->role('Financial')->create();
    DonorProposal::factory()->create([
        'donor_id' => Donor::factory()->create(['name' => 'ECHO'])->id,
        'proposal_id' => Proposal::factory()->create(['name' => 'Winter Shelter Kit', 'internal_project_number' => 'HC-2026-090'])->id,
        'external_project_number' => 'ECHO-90',
        'budget' => '2000.00',
    ]);
    DonorProposal::factory()->create([
        'donor_id' => Donor::factory()->create(['name' => 'UNICEF'])->id,
        'proposal_id' => Proposal::factory()->create(['name' => 'Cash for Work', 'internal_project_number' => 'HC-2026-091'])->id,
        'budget' => '1000.00',
    ]);

    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->call('openTransferCreate')
        ->assertSee('ECHO · Winter Shelter Kit · HC-2026-090 · ECHO-90 · 2,000 USD')
        ->assertSee('UNICEF · Cash for Work · HC-2026-091 · 1,000 USD');
});

test('the transfers search hint names the project number', function () {
    $user = User::factory()->role('Financial')->create();

    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->assertSeeHtml('placeholder="Project number, donor, or proposal"');
});
