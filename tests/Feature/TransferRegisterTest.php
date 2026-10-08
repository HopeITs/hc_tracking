<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\Account;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->role('Financial')->create();
});

/**
 * The transfers page as the finance manager opens it, optionally from a URL.
 *
 * @param  array<string, string>  $query
 */
function transferRegister(array $query = []): Testable
{
    return Livewire::actingAs(test()->user)->withQueryParams($query)->test('pages::transfers.index');
}

/**
 * A USD tranche that finished its journey: 1,000 sent, 995 gross, 985 net,
 * 10 of it in fees.
 *
 * @param  array<string, mixed>  $overrides
 */
function registerSettledTranche(array $overrides = []): TransfersTracking
{
    return TransfersTracking::factory()->create([
        'payment_amount' => '1000.00',
        'amount_transfered_currency' => Currency::Usd,
        'fees' => '10.00',
        'fees_currency' => Currency::Usd,
        'amount_received' => '995.00',
        'net_amount_received' => '985.00',
        'amount_received_currency' => Currency::Usd,
        'scheduled_date' => '2026-03-01',
        'transfer_date' => '2026-03-02',
        'receive_date' => '2026-03-05',
        'ar_status' => ArStatus::Received,
        ...$overrides,
    ]);
}

/**
 * A USD tranche of 500 nobody has sent yet, due next month.
 *
 * @param  array<string, mixed>  $overrides
 */
function registerPendingTranche(array $overrides = []): TransfersTracking
{
    return TransfersTracking::factory()->create([
        'payment_amount' => '500.00',
        'amount_transfered_currency' => Currency::Usd,
        'scheduled_date' => now()->addMonth()->toDateString(),
        'transfer_date' => null,
        'receive_date' => null,
        'ar_status' => ArStatus::Pending,
        ...$overrides,
    ]);
}

test('the page opens on the table, newest scheduled date first and undated last', function () {
    $january = registerPendingTranche(['scheduled_date' => '2026-01-10']);
    $undated = registerPendingTranche(['scheduled_date' => null]);
    $may = registerPendingTranche(['scheduled_date' => '2026-05-10']);

    $page = transferRegister()
        ->assertSet('view', 'table')
        ->assertSeeHtml('data-register')
        ->assertDontSeeHtml('data-ledger-currency');

    expect($page->instance()->rows->pluck('id')->all())->toBe([$may->id, $january->id, $undated->id]);
});

test('the overview view shows the ledger and the ladder instead of the table', function () {
    registerSettledTranche();

    transferRegister()
        ->set('view', 'overview')
        ->assertSeeHtml('data-ledger-currency="USD"')
        ->assertDontSeeHtml('data-register');
});

test('a table row shows fees, rate, gross, net and the difference without expanding anything', function () {
    registerSettledTranche(['exchange_rate' => '0.925000']);

    transferRegister()
        ->assertSee('1,000.00')
        ->assertSee('10.00')
        ->assertSee('0.925')
        ->assertSee('995.00')
        ->assertSee('985.00')
        ->assertSee('−15.00')
        ->assertDontSeeHtml('aria-controls="transfer-detail-');
});

test('the donor, currency, account and agreement filters each narrow the rows', function () {
    $unicef = Donor::factory()->create(['name' => 'UNICEF']);
    $echo = Donor::factory()->create(['name' => 'ECHO']);
    $operations = Account::factory()->create(['account_name' => 'Operations USD']);
    $dollars = DonorProposal::factory()->create(['donor_id' => $unicef->id, 'currency' => Currency::Usd]);
    $euros = DonorProposal::factory()->create(['donor_id' => $echo->id, 'currency' => Currency::Eur]);

    $usd = registerPendingTranche(['donor_proposal_id' => $dollars->id, 'account_id' => $operations->id]);
    $eur = registerPendingTranche(['donor_proposal_id' => $euros->id, 'amount_transfered_currency' => Currency::Eur]);

    $ids = fn (Testable $page): array => $page->instance()->rows->pluck('id')->all();

    expect($ids(transferRegister()->set('donor', (string) $unicef->id)))->toBe([$usd->id])
        ->and($ids(transferRegister()->set('currency', 'EUR')))->toBe([$eur->id])
        ->and($ids(transferRegister()->set('account', (string) $operations->id)))->toBe([$usd->id])
        ->and($ids(transferRegister(['agreement' => (string) $euros->id])))->toBe([$eur->id]);
});

test('the amount range and the flags narrow the rows', function () {
    $small = registerPendingTranche(['payment_amount' => '100.00']);
    $large = registerPendingTranche(['payment_amount' => '9000.00', 'account_id' => Account::factory()->create()->id]);
    $converted = registerSettledTranche(['amount_received_currency' => Currency::Eur, 'account_id' => Account::factory()->create()->id]);

    $ids = fn (Testable $page): array => $page->instance()->rows->pluck('id')->sort()->values()->all();

    expect($ids(transferRegister()->set('min', '5000')))->toBe([$large->id])
        ->and($ids(transferRegister()->set('max', '100')))->toBe([$small->id])
        ->and($ids(transferRegister()->call('toggleFlag', 'converted')))->toBe([$converted->id])
        ->and($ids(transferRegister()->call('toggleFlag', 'no_account')))->toBe([$small->id]);

    transferRegister()->call('toggleFlag', 'converted')->call('toggleFlag', 'converted')->assertSet('flag', '');
});

test('the date range reads the date the basis names', function () {
    $tranche = registerSettledTranche([
        'scheduled_date' => '2025-12-20',
        'transfer_date' => '2026-01-05',
        'receive_date' => '2026-02-10',
    ]);

    $count = fn (string $basis, string $from, string $to): int => transferRegister()
        ->set('dateBasis', $basis)
        ->set('from', $from)
        ->set('to', $to)
        ->instance()->rows->total();

    expect($count('scheduled', '2025-12-01', '2025-12-31'))->toBe(1)
        ->and($count('scheduled', '2026-01-01', '2026-12-31'))->toBe(0)
        ->and($count('sent', '2026-01-01', '2026-01-31'))->toBe(1)
        ->and($count('received', '2026-02-01', '2026-02-28'))->toBe(1)
        ->and($count('received', '2026-01-01', '2026-01-31'))->toBe(0);
});

test('a year pill fills the date range and a custom range clears the year', function () {
    registerSettledTranche(['scheduled_date' => '2025-06-01']);
    registerSettledTranche(['scheduled_date' => '2026-06-01']);

    $page = transferRegister()
        ->set('year', '2026')
        ->assertSet('from', '2026-01-01')
        ->assertSet('to', '2026-12-31');

    expect($page->instance()->rows->total())->toBe(1);

    $page->set('to', '2026-06-30')->assertSet('year', '');
    $page->set('year', '2025')->set('year', '')->assertSet('from', '')->assertSet('to', '');

    transferRegister(['year' => '2025'])->assertSet('from', '2025-01-01')->assertSet('to', '2025-12-31');
});

test('values a visitor types into the address bar fall back to the defaults', function () {
    registerPendingTranche();

    transferRegister([
        'view' => 'grid', 'stage' => 'nowhere', 'flag' => 'nothing', 'group' => 'planet', 'dateBasis' => 'never',
        'currency' => 'GBP', 'from' => '2026-13-40', 'to' => 'soon', 'min' => 'abc', 'max' => '-5',
        'sortBy' => 'id; drop table', 'sortDir' => 'sideways', 'perPage' => '7', 'donor' => 'x', 'account' => '1 or 1', 'sort' => 'random',
    ])
        ->assertSet('view', 'table')
        ->assertSet('stage', '')
        ->assertSet('flag', '')
        ->assertSet('group', '')
        ->assertSet('dateBasis', 'scheduled')
        ->assertSet('currency', '')
        ->assertSet('from', '')
        ->assertSet('to', '')
        ->assertSet('min', '')
        ->assertSet('max', '')
        ->assertSet('sortBy', '')
        ->assertSet('sortDir', '')
        ->assertSet('perPage', '')
        ->assertSet('donor', '')
        ->assertSet('account', '')
        ->assertSet('sort', 'attention')
        ->assertOk();
});

test('an old receipt-status bookmark still narrows to that status', function () {
    $received = registerSettledTranche();
    registerPendingTranche();

    $page = transferRegister(['arStatus' => 'received'])->assertSet('stage', 'received');

    expect($page->instance()->rows->pluck('id')->all())->toBe([$received->id]);
});

test('table totals follow the status filter while the overview ledger keeps describing the whole set', function () {
    registerSettledTranche();
    registerPendingTranche();

    $page = transferRegister()->call('toggleStage', 'received');

    $totals = $page->instance()->totals->firstWhere('currency', 'USD');
    $ledger = $page->instance()->ledger->firstWhere('currency', 'USD');

    expect($totals['tranches'])->toBe(1)
        ->and($totals['expected'])->toBe(1000.0)
        ->and($totals['landed'])->toBe(985.0)
        ->and($totals['gross'])->toBe(995.0)
        ->and($totals['recordedFees'])->toBe(10.0)
        ->and($totals['shrink'])->toBe(15.0)
        ->and($ledger['expected'])->toBe(1500.0)
        ->and($ledger['tranches'])->toBe(2);
});

test('the status pill counts ignore the status filter and nothing else', function () {
    registerSettledTranche();
    registerSettledTranche(['net_amount_received' => null, 'amount_received' => null]);
    registerPendingTranche();
    registerPendingTranche(['scheduled_date' => now()->subMonth()->toDateString()]);
    registerPendingTranche(['amount_transfered_currency' => Currency::Eur]);

    $counts = transferRegister()->set('currency', 'USD')->call('toggleStage', 'overdue')->instance()->stageCounts;

    expect($counts)->toMatchArray([
        'all' => 4,
        'received' => 2,
        'upcoming' => 1,
        'overdue' => 1,
        'transit' => 0,
        'failed' => 0,
        'short' => 1,
        'unrecorded' => 1,
    ]);
});

test('table totals never add currencies together and never net an overage against a shortfall', function () {
    registerSettledTranche(['payment_amount' => '100000.00', 'fees' => '0.00', 'amount_received' => '90000.00', 'net_amount_received' => '90000.00']);
    registerSettledTranche(['payment_amount' => '50000.00', 'fees' => '0.00', 'amount_received' => '80000.00', 'net_amount_received' => '80000.00']);
    registerSettledTranche([
        'payment_amount' => '7000.00',
        'amount_transfered_currency' => Currency::Eur,
        'fees_currency' => Currency::Eur,
        'amount_received_currency' => Currency::Eur,
        'amount_received' => '7000.00',
        'net_amount_received' => '7000.00',
        'fees' => '0.00',
    ]);

    $page = transferRegister();
    $totals = $page->instance()->totals->keyBy('currency');

    expect($totals->keys()->all())->toBe(['USD', 'EUR'])
        ->and($totals['USD']['expected'])->toBe(150000.0)
        ->and($totals['USD']['shrink'])->toBe(10000.0)
        ->and($totals['USD']['overage'])->toBe(30000.0)
        ->and($totals['EUR']['expected'])->toBe(7000.0);

    $page->assertDontSeeHtml('data-register-total=')->assertSeeHtml('data-totals-currency="USD"')
        ->assertSeeHtml('data-totals-currency="EUR"')
        ->assertSee('−10,000.00')
        ->assertSee('+30,000.00')
        ->assertDontSee('157,000.00');
});

test('a column heading sorts the rows and a second click reverses them', function () {
    $small = registerPendingTranche(['payment_amount' => '100.00', 'scheduled_date' => '2026-05-01']);
    $large = registerPendingTranche(['payment_amount' => '9000.00', 'scheduled_date' => '2026-01-01']);
    $euro = registerPendingTranche(['payment_amount' => '50000.00', 'amount_transfered_currency' => Currency::Eur, 'scheduled_date' => '2026-03-01']);

    $ids = fn (Testable $page): array => $page->instance()->rows->pluck('id')->all();

    $page = transferRegister()->call('sortRegister', 'amount');

    // Amounts sort inside their currency, so a EUR figure is never ranked against a USD one.
    expect($ids($page))->toBe([$euro->id, $large->id, $small->id]);

    $page->call('sortRegister', 'amount')->assertSet('sortDir', 'asc');

    expect($ids($page))->toBe([$euro->id, $small->id, $large->id]);

    $page->assertSeeHtml('aria-sort="ascending"');
});

test('needs attention first leads with overdue money and turns off again', function () {
    $upcoming = registerPendingTranche(['scheduled_date' => now()->addMonths(2)->toDateString()]);
    $overdue = registerPendingTranche(['scheduled_date' => now()->subMonth()->toDateString()]);

    $page = transferRegister()->call('toggleAttentionOrder')->assertSet('sortBy', 'attention');

    expect($page->instance()->rows->pluck('id')->all())->toBe([$overdue->id, $upcoming->id]);

    $page->call('toggleAttentionOrder')->assertSet('sortBy', '');

    expect($page->instance()->rows->pluck('id')->all())->toBe([$upcoming->id, $overdue->id]);
});

test('a group subtotal covers the whole group even when the group runs onto the next page', function () {
    $donor = Donor::factory()->create(['name' => 'Alpha Fund']);
    $agreement = DonorProposal::factory()->create(['donor_id' => $donor->id, 'currency' => Currency::Usd]);

    TransfersTracking::factory()->count(26)->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '100.00',
        'amount_transfered_currency' => Currency::Usd,
        'scheduled_date' => '2026-02-01',
        'transfer_date' => null,
        'ar_status' => ArStatus::Pending,
    ]);

    $page = transferRegister()->set('group', 'donor');

    expect($page->instance()->rows)->toHaveCount(5)
        ->and((float) $page->instance()->groupTotals->get((string) $donor->id)->first()->sent)->toBe(2600.0);

    $page->assertSeeHtml('data-group="'.$donor->id.'"')
        ->assertSee('Alpha Fund')
        ->assertSee('2,600.00');
});

test('grouping by month follows the chosen date basis and files undated tranches together', function () {
    registerSettledTranche(['scheduled_date' => '2026-03-01', 'receive_date' => '2026-04-02', 'transfer_date' => '2026-03-02']);
    registerPendingTranche(['scheduled_date' => null]);

    $byScheduled = transferRegister()->set('group', 'month')->instance()->groupTotals->keys()->sort()->values()->all();
    $byReceived = transferRegister()->set('dateBasis', 'received')->set('group', 'month')->instance()->groupTotals->keys()->sort()->values()->all();

    expect($byScheduled)->toBe(['', '2026-03'])
        ->and($byReceived)->toBe(['', '2026-04']);
});

test('the page size can be changed and an unknown size is ignored', function () {
    TransfersTracking::factory()->count(30)->create(['ar_status' => ArStatus::Pending, 'transfer_date' => null]);

    $page = transferRegister();

    expect($page->instance()->rows)->toHaveCount(5);

    $page->set('perPage', '25');
    expect($page->instance()->rows)->toHaveCount(25);

    $page->set('perPage', '5000')->assertSet('perPage', '');
});

test('reset filters clears every filter and keeps the view, the grouping and the sort', function () {
    registerPendingTranche();

    transferRegister()
        ->set('view', 'overview')
        ->set('group', 'donor')
        ->set('currency', 'USD')
        ->set('min', '10')
        ->set('year', '2026')
        ->call('toggleStage', 'upcoming')
        ->call('toggleFlag', 'no_account')
        ->call('clearFilters')
        ->assertSet('currency', '')
        ->assertSet('min', '')
        ->assertSet('year', '')
        ->assertSet('from', '')
        ->assertSet('stage', '')
        ->assertSet('flag', '')
        ->assertSet('view', 'overview')
        ->assertSet('group', 'donor');
});

test('a viewer without the update permission gets the table with no edit buttons', function () {
    $viewer = User::factory()->role('Managers')->create()->givePermissionTo('transfers.view');
    $tranche = registerPendingTranche();

    Livewire::actingAs($viewer)->test('pages::transfers.index')
        ->assertSeeHtml('data-transfer="'.$tranche->id.'"')
        ->assertDontSeeHtml('data-test="edit-transfer-'.$tranche->id.'"')
        ->assertDontSeeHtml('data-test="create-transfer-button"');
});

test('a row links to its donor, proposal and agreement only for people who may open them', function () {
    $tranche = registerPendingTranche();
    $agreement = $tranche->donorProposal;
    $viewer = User::factory()->role('Managers')->create()->givePermissionTo('transfers.view');

    transferRegister()
        ->assertSeeHtml('href="'.route('donors.show', $agreement->donor).'"')
        ->assertSeeHtml('href="'.route('proposals.show', $agreement->proposal).'"')
        ->assertSeeHtml('href="'.route('funding-agreements.show', $agreement).'"');

    Livewire::actingAs($viewer)->test('pages::transfers.index')
        ->assertSee($agreement->donor->name)
        ->assertDontSeeHtml('href="'.route('donors.show', $agreement->donor).'"')
        ->assertDontSeeHtml('href="'.route('funding-agreements.show', $agreement).'"');
});

test('the export holds every filtered row, not only the visible page', function () {
    // The state column depends on today: on this day a tranche due on 1 February is still upcoming.
    $this->travelTo('2026-01-15 09:00:00');

    $agreement = DonorProposal::factory()->create([
        'donor_id' => Donor::factory()->create(['name' => 'Alpha Fund'])->id,
        'external_project_number' => 'AF-2026-01',
        'currency' => Currency::Usd,
    ]);
    TransfersTracking::factory()->count(30)->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '100.00',
        'amount_transfered_currency' => Currency::Usd,
        'scheduled_date' => '2026-02-01',
        'transfer_date' => null,
        'ar_status' => ArStatus::Pending,
    ]);
    registerPendingTranche(['amount_transfered_currency' => Currency::Eur]);

    $page = transferRegister()
        ->set('currency', 'USD')
        ->call('exportCsv')
        ->assertFileDownloaded('transfers-'.now()->toDateString().'.csv');

    $csv = downloadedCsv($page);

    expect($csv)->toHaveCount(31)
        ->and($csv[0][1])->toBe('Donor')
        ->and($csv[1][0])->toBe('Upcoming')
        ->and($csv[1][1])->toBe('Alpha Fund')
        ->and($csv[1][3])->toBe('AF-2026-01')
        ->and($csv[1][5])->toBe('2026-02-01')
        ->and($csv[1][8])->toBe('USD')
        ->and($csv[1][9])->toBe('100.00')
        ->and($csv[1][17])->toBe('pending');
});

test('the export writes plain numbers, ISO dates and the difference of a settled tranche', function () {
    registerSettledTranche();

    $row = downloadedCsv(transferRegister()->call('exportCsv'))[1];

    expect($row[0])->toBe('Received')
        ->and($row[5])->toBe('2026-03-01')
        ->and($row[6])->toBe('2026-03-02')
        ->and($row[7])->toBe('2026-03-05')
        ->and($row[9])->toBe('1000.00')
        ->and($row[10])->toBe('10.00')
        ->and($row[13])->toBe('995.00')
        ->and($row[14])->toBe('985.00')
        ->and($row[15])->toBe('USD')
        ->and($row[16])->toBe('-15.00')
        ->and($row[17])->toBe('received');
});

test('exporting needs the transfers view permission', function () {
    registerPendingTranche();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::transfers.index')
        ->call('exportCsv')
        ->assertForbidden();
});

test('the tranche form is rendered only while its modal is open', function () {
    transferRegister()
        ->assertDontSeeHtml('wire:model="paymentAmount"')
        ->call('openTransferCreate')
        ->assertSeeHtml('wire:model="paymentAmount"');
});
