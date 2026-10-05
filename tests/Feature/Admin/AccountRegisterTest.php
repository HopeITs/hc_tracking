<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\Account;
use App\Models\TransfersTracking;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo('2026-06-15 09:00:00');
    $this->user = User::factory()->role('Financial')->create();
});

/**
 * The accounts page as the finance manager opens it.
 *
 * @param  array<string, string>  $query
 */
function accountRegister(array $query = []): Testable
{
    return Livewire::actingAs(test()->user)->withQueryParams($query)->test('pages::admin.accounts');
}

/**
 * A tranche into an account, in the account's own currency unless told otherwise.
 *
 * @param  array<string, mixed>  $attributes
 */
function accountTranche(Account $account, array $attributes): TransfersTracking
{
    return TransfersTracking::factory()->create([
        'account_id' => $account->id,
        'amount_transfered_currency' => $account->currency,
        'amount_received_currency' => $account->currency,
        'transfer_date' => null,
        'receive_date' => null,
        'ar_status' => ArStatus::Pending,
        ...$attributes,
    ]);
}

/**
 * Operations USD at Fransbank, Lebanon: 985 landed, 50 in transit, 100 upcoming, 200 overdue.
 */
function registerBusyAccount(): Account
{
    $account = Account::factory()->create([
        'account_name' => 'Operations USD',
        'bank_name' => 'Fransbank',
        'currency' => Currency::Usd,
        'country' => 'Lebanon',
        'account_number' => '030/02/353/2531267/1/3',
        'opened_at' => '2024-03-15',
    ]);

    accountTranche($account, ['payment_amount' => '1000.00', 'net_amount_received' => '985.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2026-05-15', 'transfer_date' => '2026-05-16', 'receive_date' => '2026-05-20']);
    accountTranche($account, ['payment_amount' => '200.00', 'scheduled_date' => '2026-06-05']);
    accountTranche($account, ['payment_amount' => '100.00', 'scheduled_date' => '2026-06-25']);
    accountTranche($account, ['payment_amount' => '50.00', 'scheduled_date' => '2026-06-14', 'transfer_date' => '2026-06-14']);

    return $account;
}

test('the page opens on the table with what landed in each account', function () {
    $account = registerBusyAccount();

    $page = accountRegister()
        ->assertSet('view', 'table')
        ->assertSeeHtml('data-register')
        ->assertSeeHtml('data-account-row="'.$account->id.'"')
        ->assertDontSee('Funds received')
        ->assertSee('Operations USD')
        ->assertSee('Fransbank')
        ->assertSee('Lebanon')
        ->assertSee('985.00')
        ->assertSee('200.00');

    $row = $page->instance()->rows->sole();

    expect((int) $row->money_transfers)->toBe(4)
        ->and((float) $row->money_received)->toBe(985.0)
        ->and((float) $row->money_in_transit)->toBe(50.0)
        ->and((float) $row->money_upcoming)->toBe(100.0)
        ->and((float) $row->money_overdue)->toBe(200.0)
        ->and($row->money_last_receipt)->toBe('2026-05-20');
});

test('the overview view shows the tiles and the cards instead of the table', function () {
    registerBusyAccount();

    accountRegister()
        ->set('view', 'overview')
        ->assertSee('Funds received')
        ->assertDontSeeHtml('data-register');
});

test('a row opens the transfers table filtered to its account', function () {
    $account = registerBusyAccount();

    accountRegister()->assertSeeHtml('href="'.route('transfers.index', ['account' => $account->id]).'"');
});

test('money that landed in another currency is named, never added to the account\'s figure', function () {
    $account = registerBusyAccount();
    accountTranche($account, ['payment_amount' => '700.00', 'net_amount_received' => '640.00', 'amount_received_currency' => Currency::Eur, 'ar_status' => ArStatus::Received, 'scheduled_date' => '2025-11-01', 'receive_date' => '2025-11-10']);

    $page = accountRegister();
    $row = $page->instance()->rows->sole();

    expect((float) $row->money_received)->toBe(985.0)
        ->and((int) $row->money_other_currency)->toBe(1);

    $page->assertSeeHtml('data-other-currency')->assertDontSee('1,625.00');
});

test('country, currency and the flags narrow the rows', function () {
    $busy = registerBusyAccount();
    $idle = Account::factory()->create(['account_name' => 'Reserve EUR', 'currency' => Currency::Eur, 'country' => 'Syria']);
    $quiet = Account::factory()->create(['account_name' => 'Field USD', 'currency' => Currency::Usd, 'country' => 'Syria']);
    accountTranche($quiet, ['payment_amount' => '10.00', 'scheduled_date' => '2026-08-01']);

    $ids = fn (Testable $page): array => $page->instance()->rows->pluck('id')->sort()->values()->all();

    expect($ids(accountRegister()->set('countryFilter', 'Syria')))->toBe([$idle->id, $quiet->id])
        ->and($ids(accountRegister()->set('currencyFilter', 'EUR')))->toBe([$idle->id])
        ->and($ids(accountRegister()->call('toggleFlag', 'has_transfers')))->toBe([$busy->id, $quiet->id])
        ->and($ids(accountRegister()->call('toggleFlag', 'no_transfers')))->toBe([$idle->id])
        ->and($ids(accountRegister()->call('toggleFlag', 'in_transit')))->toBe([$busy->id])
        ->and($ids(accountRegister(['country' => 'Lebanon'])))->toBe([$busy->id]);
});

test('choosing a year shows what landed that year and hides accounts with nothing in it', function () {
    $busy = registerBusyAccount();
    $old = Account::factory()->create(['currency' => Currency::Usd]);
    accountTranche($old, ['payment_amount' => '400.00', 'net_amount_received' => '400.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2024-02-01', 'receive_date' => '2024-02-05']);

    $page = accountRegister()->set('year', '2026');
    $row = $page->instance()->rows->sole();

    expect($row->id)->toBe($busy->id)
        ->and((float) $row->money_received_in_year)->toBe(985.0);

    $page->assertSee('Received in 2026');

    $only = accountRegister()->set('year', '2024')->set('figures', 'year')->instance()->rows->sole();

    expect($only->id)->toBe($old->id)
        ->and((int) $only->money_transfers)->toBe(1);
});

test('totals are kept per currency and count open accounts', function () {
    registerBusyAccount();
    Account::factory()->create(['currency' => Currency::Usd, 'is_active' => false, 'closed_at' => '2026-01-31']);
    Account::factory()->create(['currency' => Currency::Eur]);

    $page = accountRegister();
    $totals = $page->instance()->totals->keyBy('currency');

    expect($totals->keys()->all())->toBe(['EUR', 'USD'])
        ->and($totals['USD'])->toMatchArray(['accounts' => 2, 'open_accounts' => 1, 'received' => 985.0, 'in_transit' => 50.0, 'upcoming' => 100.0, 'overdue' => 200.0])
        ->and($totals['EUR']['received'])->toBe(0.0);

    $page->assertDontSeeHtml('data-register-total=')->assertSeeHtml('data-totals-currency="USD"')->assertSeeHtml('data-totals-currency="EUR"');
});

test('the table opens by account name and a heading sorts it', function () {
    $busy = registerBusyAccount();
    $alpha = Account::factory()->create(['account_name' => 'Alpha Reserve', 'currency' => Currency::Usd]);
    accountTranche($alpha, ['payment_amount' => '5000.00', 'net_amount_received' => '5000.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2026-04-01', 'receive_date' => '2026-04-02']);

    $ids = fn (Testable $page): array => $page->instance()->rows->pluck('id')->all();

    $page = accountRegister();
    expect($ids($page))->toBe([$alpha->id, $busy->id]);

    $page->call('sortRegister', 'received');
    expect($ids($page))->toBe([$alpha->id, $busy->id]);

    $page->call('sortRegister', 'received');
    expect($ids($page))->toBe([$busy->id, $alpha->id]);
});

test('values typed into the address bar fall back to the defaults', function () {
    registerBusyAccount();

    accountRegister(['view' => 'map', 'state' => 'frozen', 'currencyFilter' => 'GBP', 'country' => 'Atlantis', 'flag' => 'rich', 'sortBy' => 'iban', 'perPage' => '3'])
        ->assertSet('view', 'table')
        ->assertSet('state', '')
        ->assertSet('currencyFilter', '')
        ->assertSet('countryFilter', '')
        ->assertSet('flag', '')
        ->assertSet('sortBy', '')
        ->assertSet('perPage', '')
        ->assertOk();
});

test('the table and the export mask account numbers for someone who cannot change accounts', function () {
    registerBusyAccount();
    $viewer = userWithRole('Team Member')->givePermissionTo('accounts.view');

    $viewerPage = Livewire::actingAs($viewer)->test('pages::admin.accounts')
        ->assertSeeHtml('•••• /1/3')
        ->assertDontSee('2531267')
        ->call('exportCsv');

    expect(downloadedCsv($viewerPage)[1][4])->toBe('•••• /1/3');

    $financePage = accountRegister()->assertSee('030/02/353/2531267/1/3')->call('exportCsv');

    expect(downloadedCsv($financePage)[1][4])->toBe('030/02/353/2531267/1/3');
});

test('the export holds every filtered account with plain numbers', function () {
    registerBusyAccount();
    Account::factory()->count(30)->create(['currency' => Currency::Usd]);
    Account::factory()->create(['currency' => Currency::Eur]);

    $page = accountRegister()
        ->set('currencyFilter', 'USD')
        ->call('sortRegister', 'received')
        ->call('exportCsv')
        ->assertFileDownloaded('accounts-2026-06-15.csv');

    $csv = downloadedCsv($page);

    expect($csv)->toHaveCount(32)
        ->and($csv[0][0])->toBe('Account name')
        ->and($csv[1][0])->toBe('Operations USD')
        ->and($csv[1][1])->toBe('Fransbank')
        ->and($csv[1][2])->toBe('Lebanon')
        ->and($csv[1][3])->toBe('USD')
        ->and($csv[1][5])->toBe('Open')
        ->and($csv[1][6])->toBe('2024-03-15')
        ->and($csv[1][8])->toBe('4')
        ->and($csv[1][9])->toBe('985.00')
        ->and($csv[1][10])->toBe('50.00')
        ->and($csv[1][11])->toBe('100.00')
        ->and($csv[1][12])->toBe('200.00')
        ->and($csv[1][13])->toBe('2026-05-20');
});

test('exporting needs the accounts view permission', function () {
    registerBusyAccount();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.accounts')
        ->call('exportCsv')
        ->assertForbidden();
});
