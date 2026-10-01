<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\Account;
use App\Models\TransfersTracking;
use App\Support\AccountMoney;

beforeEach(function () {
    $this->travelTo('2026-06-15 09:00:00');
});

/**
 * A USD account with one tranche in each state, plus one that landed in EUR.
 */
function moneyAccount(): Account
{
    $account = Account::factory()->create(['account_name' => 'Operations USD', 'currency' => Currency::Usd]);

    $tranche = fn (array $attributes): TransfersTracking => TransfersTracking::factory()->create([
        'account_id' => $account->id,
        'amount_transfered_currency' => Currency::Usd,
        'amount_received_currency' => Currency::Usd,
        'transfer_date' => null,
        'receive_date' => null,
        'ar_status' => ArStatus::Pending,
        ...$attributes,
    ]);

    $tranche(['payment_amount' => '1000.00', 'net_amount_received' => '985.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2026-05-15', 'transfer_date' => '2026-05-16', 'receive_date' => '2026-05-20']);
    $tranche(['payment_amount' => '200.00', 'scheduled_date' => '2026-06-05']);
    $tranche(['payment_amount' => '100.00', 'scheduled_date' => '2026-06-25']);
    $tranche(['payment_amount' => '50.00', 'scheduled_date' => '2026-06-14', 'transfer_date' => '2026-06-14']);
    $tranche(['payment_amount' => '700.00', 'net_amount_received' => '640.00', 'amount_received_currency' => Currency::Eur, 'ar_status' => ArStatus::Received, 'scheduled_date' => '2025-11-01', 'transfer_date' => '2025-11-02', 'receive_date' => '2025-11-10']);

    return $account;
}

/**
 * One account's joined money row.
 */
function accountMoneyRow(Account $account, ?int $year = null, bool $yearOnly = false): Account
{
    return Account::query()
        ->leftJoinSub(AccountMoney::perAccount($year, $yearOnly), 'money', 'money.account_id', '=', 'accounts.id')
        ->select('accounts.id')
        ->selectRaw(AccountMoney::select())
        ->findOrFail($account->id);
}

test('an account counts what landed, is moving, is due and is late in its own currency only', function () {
    $row = accountMoneyRow(moneyAccount());

    expect((int) $row->money_transfers)->toBe(5)
        ->and((float) $row->money_received)->toBe(985.0)
        ->and((float) $row->money_in_transit)->toBe(50.0)
        ->and((float) $row->money_upcoming)->toBe(100.0)
        ->and((float) $row->money_overdue)->toBe(200.0)
        ->and((int) $row->money_other_currency)->toBe(1)
        ->and($row->money_last_receipt)->toBe('2026-05-20');
});

test('an account with no tranche reads zero, not null', function () {
    $row = accountMoneyRow(Account::factory()->create());

    expect((int) $row->money_transfers)->toBe(0)
        ->and((float) $row->money_received)->toBe(0.0)
        ->and($row->money_last_receipt)->toBeNull();
});

test('a year adds what landed that year, and year only keeps just that year\'s tranches', function () {
    $account = moneyAccount();

    $beside = accountMoneyRow($account, 2026);
    $only = accountMoneyRow($account, 2026, yearOnly: true);
    $earlier = accountMoneyRow($account, 2025, yearOnly: true);

    expect((float) $beside->money_received_in_year)->toBe(985.0)
        ->and((int) $beside->money_transfers)->toBe(5)
        ->and((int) $only->money_transfers)->toBe(4)
        ->and((float) $only->money_received)->toBe(985.0)
        ->and((int) $earlier->money_transfers)->toBe(1)
        ->and((float) $earlier->money_received)->toBe(0.0)
        ->and((int) $earlier->money_other_currency)->toBe(1);
});

test('totals group by the account currency', function () {
    moneyAccount();
    Account::factory()->create(['currency' => Currency::Usd, 'is_active' => false, 'closed_at' => '2026-01-31']);
    Account::factory()->create(['currency' => Currency::Eur]);

    $totals = Account::query()
        ->leftJoinSub(AccountMoney::perAccount(), 'money', 'money.account_id', '=', 'accounts.id')
        ->toBase()
        ->selectRaw(AccountMoney::totalsSelect())
        ->groupBy('accounts.currency')
        ->orderBy('accounts.currency')
        ->get()
        ->keyBy('currency');

    expect($totals->keys()->all())->toBe(['EUR', 'USD'])
        ->and((int) $totals['USD']->accounts)->toBe(2)
        ->and((int) $totals['USD']->open_accounts)->toBe(1)
        ->and((float) $totals['USD']->received)->toBe(985.0)
        ->and((float) $totals['EUR']->received)->toBe(0.0);
});
