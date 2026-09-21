<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\Account;
use App\Models\DonorProposal;
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
    $operations = Account::factory()->create(['name' => 'Operations USD']);
    $reserve = Account::factory()->create(['name' => 'Reserve EUR']);

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

test('transfers offer no delete', function () {
    $user = User::factory()->role('Financial')->create();
    TransfersTracking::factory()->create();

    $component = Livewire::actingAs($user)->test('pages::transfers.index');

    $component->assertDontSee('wire:click="confirmDelete');

    expect(method_exists($component->instance(), 'deleteTransfer'))->toBeFalse();
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
    $viewer = User::factory()->role('Managers')->create();
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
