<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Proposal;
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
 * The funding agreements page as the finance manager opens it.
 *
 * @param  array<string, string>  $query
 */
function agreementRegister(array $query = []): Testable
{
    return Livewire::actingAs(test()->user)->withQueryParams($query)->test('pages::funding-agreements.index');
}

/**
 * A tranche in the agreement's own currency.
 *
 * @param  array<string, mixed>  $attributes
 */
function registerTranche(DonorProposal $agreement, array $attributes): TransfersTracking
{
    return TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'amount_transfered_currency' => $agreement->currency,
        'amount_received_currency' => $agreement->currency,
        'transfer_date' => null,
        'receive_date' => null,
        'ar_status' => ArStatus::Pending,
        ...$attributes,
    ]);
}

/**
 * 1,000 USD: 400 received (390 net), 200 overdue, 100 upcoming, 50 in transit.
 */
function registerMixedAgreement(string $proposalName = 'Mixed Proposal'): DonorProposal
{
    $agreement = DonorProposal::factory()->create([
        'proposal_id' => Proposal::factory()->create(['name' => $proposalName])->id,
        'budget' => '1000.00',
        'currency' => Currency::Usd,
    ]);

    registerTranche($agreement, ['payment_amount' => '400.00', 'net_amount_received' => '390.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2026-05-15', 'transfer_date' => '2026-05-15', 'receive_date' => '2026-05-20']);
    registerTranche($agreement, ['payment_amount' => '200.00', 'scheduled_date' => '2026-06-05']);
    registerTranche($agreement, ['payment_amount' => '100.00', 'scheduled_date' => '2026-06-25']);
    registerTranche($agreement, ['payment_amount' => '50.00', 'scheduled_date' => '2026-06-14', 'transfer_date' => '2026-06-14']);

    return $agreement;
}

test('the page opens on the table with every money figure of an agreement in its own column', function () {
    $agreement = registerMixedAgreement();

    $page = agreementRegister()
        ->assertSet('view', 'table')
        ->assertSeeHtml('data-register')
        ->assertDontSeeHtml('data-calendar')
        ->assertSeeHtml('data-agreement-row="'.$agreement->id.'"')
        ->assertSee('1,000.00')
        ->assertSee('750.00')
        ->assertSee('390.00')
        ->assertSee('39%')
        ->assertSee('600.00');

    $row = $page->instance()->rows->first();

    expect((float) $row->money_scheduled)->toBe(750.0)
        ->and((float) $row->money_received)->toBe(390.0)
        ->and((float) $row->money_in_transit)->toBe(50.0)
        ->and((float) $row->money_overdue)->toBe(200.0)
        ->and((float) $row->money_upcoming)->toBe(100.0)
        ->and((float) $row->money_unscheduled)->toBe(250.0)
        ->and((float) $row->money_outstanding)->toBe(600.0)
        ->and((int) $row->money_tranches)->toBe(4)
        ->and($row->money_next_due)->toBe('2026-06-05');
});

test('the overview view shows the calendar instead of the table', function () {
    registerMixedAgreement();

    agreementRegister()
        ->set('view', 'overview')
        ->assertSeeHtml('data-calendar')
        ->assertDontSeeHtml('data-register');
});

test('totals are kept per currency and add up to the rows shown', function () {
    registerMixedAgreement();
    DonorProposal::factory()->create(['budget' => '500.00', 'currency' => Currency::Eur]);
    DonorProposal::factory()->create(['budget' => '2000.00', 'currency' => Currency::Usd]);

    $page = agreementRegister();
    $totals = $page->instance()->totals->keyBy('currency');

    expect($totals->keys()->all())->toBe(['EUR', 'USD'])
        ->and($totals['USD'])->toMatchArray([
            'agreements' => 2,
            'budget' => 3000.0,
            'scheduled' => 750.0,
            'received' => 390.0,
            'in_transit' => 50.0,
            'overdue' => 200.0,
            'unscheduled' => 2250.0,
            'outstanding' => 2600.0,
        ])
        ->and($totals['EUR']['budget'])->toBe(500.0);

    $page->assertDontSeeHtml('data-register-total=')->assertSeeHtml('data-totals-currency="USD"')
        ->assertSeeHtml('data-totals-currency="EUR"')
        ->assertDontSee('3,500.00');

    // A status narrows the rows, and the totals follow the rows.
    $page->call('toggleStage', 'overdue');

    expect($page->instance()->totals->firstWhere('currency', 'USD')['budget'])->toBe(1000.0);
});

test('the flags and the budget range narrow the rows', function () {
    $mixed = registerMixedAgreement('Mixed Proposal');
    $empty = DonorProposal::factory()->create(['budget' => '90000.00', 'currency' => Currency::Usd]);
    $reporting = DonorProposal::factory()->create(['budget' => '300.00', 'currency' => Currency::Usd]);
    DonorReport::factory()->create(['donor_proposal_id' => $reporting->id, 'due_on' => '2026-06-01']);
    registerTranche($reporting, ['payment_amount' => '300.00', 'net_amount_received' => null, 'ar_status' => ArStatus::Received, 'scheduled_date' => '2026-05-01']);
    registerTranche($reporting, ['payment_amount' => '10.00', 'amount_transfered_currency' => Currency::Eur, 'scheduled_date' => '2026-07-01']);

    $ids = fn (Testable $page): array => $page->instance()->rows->pluck('id')->sort()->values()->all();

    expect($ids(agreementRegister()->call('toggleFlag', 'report_overdue')))->toBe([$reporting->id])
        ->and($ids(agreementRegister()->call('toggleFlag', 'net_missing')))->toBe([$reporting->id])
        ->and($ids(agreementRegister()->call('toggleFlag', 'other_currency')))->toBe([$reporting->id])
        ->and($ids(agreementRegister()->call('toggleFlag', 'no_tranches')))->toBe([$empty->id])
        ->and($ids(agreementRegister()->set('min', '50000')))->toBe([$empty->id])
        ->and($ids(agreementRegister()->set('max', '500')))->toBe([$reporting->id])
        ->and($ids(agreementRegister()->set('min', '500')->set('max', '5000')))->toBe([$mixed->id]);

    agreementRegister()->call('toggleFlag', 'no_tranches')->call('toggleFlag', 'no_tranches')->assertSet('flag', '');
});

test('choosing a year adds that year beside the lifetime figures and hides agreements with nothing in it', function () {
    $agreement = DonorProposal::factory()->create(['budget' => '1000.00', 'currency' => Currency::Usd]);
    registerTranche($agreement, ['payment_amount' => '300.00', 'net_amount_received' => '300.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2025-11-01', 'transfer_date' => '2025-12-20', 'receive_date' => '2026-01-10']);
    registerTranche($agreement, ['payment_amount' => '400.00', 'scheduled_date' => '2026-09-01']);

    $elsewhere = DonorProposal::factory()->create(['budget' => '500.00', 'currency' => Currency::Usd]);
    registerTranche($elsewhere, ['payment_amount' => '500.00', 'scheduled_date' => '2024-03-01']);

    $page = agreementRegister()->set('year', '2026');
    $row = $page->instance()->rows->sole();

    expect($row->id)->toBe($agreement->id)
        ->and((float) $row->money_scheduled)->toBe(700.0)
        ->and((float) $row->money_due_in_year)->toBe(400.0)
        ->and((float) $row->money_received_in_year)->toBe(300.0);

    $page->assertSee('Due in 2026')->assertSee('Received in 2026')->assertSee('Outstanding');

    // 2025: the tranche was due that year but landed in the next.
    $earlier = agreementRegister()->set('year', '2025')->instance()->rows->sole();

    expect((float) $earlier->money_due_in_year)->toBe(300.0)
        ->and((float) $earlier->money_received_in_year)->toBe(0.0);
});

test('year only recalculates every tranche figure for that year and drops the whole-agreement columns', function () {
    $agreement = DonorProposal::factory()->create(['budget' => '1000.00', 'currency' => Currency::Usd]);
    registerTranche($agreement, ['payment_amount' => '300.00', 'net_amount_received' => '300.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2025-11-01', 'transfer_date' => '2025-12-20', 'receive_date' => '2026-01-10']);
    registerTranche($agreement, ['payment_amount' => '400.00', 'scheduled_date' => '2026-09-01']);

    $page = agreementRegister()->set('year', '2026')->set('figures', 'year');
    $row = $page->instance()->rows->sole();

    expect((float) $row->money_scheduled)->toBe(400.0)
        ->and((float) $row->money_received)->toBe(0.0)
        ->and((int) $row->money_tranches)->toBe(1)
        ->and((float) $page->instance()->totals->sole()['scheduled'])->toBe(400.0);

    $page->assertSee('Budget (whole agreement)')
        ->assertDontSee('Outstanding')
        ->assertDontSee('Due in 2026');

    // The mode means nothing without a year.
    agreementRegister()->set('figures', 'year')->assertSet('figures', 'lifetime');
});

test('the table opens most urgent first and a heading sorts inside each currency', function () {
    $late = DonorProposal::factory()->create(['budget' => '100.00', 'currency' => Currency::Usd]);
    registerTranche($late, ['payment_amount' => '100.00', 'scheduled_date' => '2026-05-01']);
    $soon = DonorProposal::factory()->create(['budget' => '900.00', 'currency' => Currency::Usd]);
    registerTranche($soon, ['payment_amount' => '900.00', 'scheduled_date' => '2026-07-01']);
    $quiet = DonorProposal::factory()->create(['budget' => '500.00', 'currency' => Currency::Eur]);

    $ids = fn (Testable $page): array => $page->instance()->rows->pluck('id')->all();

    $page = agreementRegister();
    expect($ids($page))->toBe([$late->id, $soon->id, $quiet->id]);

    $page->call('sortRegister', 'budget');
    expect($ids($page))->toBe([$quiet->id, $soon->id, $late->id]);

    $page->call('sortRegister', 'budget')->assertSet('sortDir', 'asc');
    expect($ids($page))->toBe([$quiet->id, $late->id, $soon->id]);
});

test('values typed into the address bar fall back to the defaults', function () {
    registerMixedAgreement();

    agreementRegister([
        'view' => 'cards', 'stage' => 'lost', 'flag' => 'x', 'currency' => 'GBP', 'donor' => '1;2',
        'min' => 'lots', 'max' => '-1', 'year' => '20x6', 'figures' => 'decade', 'sortBy' => 'password', 'perPage' => '9999',
    ])
        ->assertSet('view', 'table')
        ->assertSet('stage', '')
        ->assertSet('flag', '')
        ->assertSet('currency', '')
        ->assertSet('donor', '')
        ->assertSet('min', '')
        ->assertSet('max', '')
        ->assertSet('year', '')
        ->assertSet('figures', 'lifetime')
        ->assertSet('sortBy', '')
        ->assertSet('perPage', '')
        ->assertOk();
});

test('reset filters clears every filter and keeps the view and the sort', function () {
    registerMixedAgreement();

    agreementRegister()
        ->set('view', 'overview')
        ->call('sortRegister', 'budget')
        ->set('currency', 'USD')
        ->set('year', '2026')
        ->set('min', '10')
        ->call('toggleStage', 'overdue')
        ->call('toggleFlag', 'net_missing')
        ->call('clearFilters')
        ->assertSet('currency', '')
        ->assertSet('year', '')
        ->assertSet('min', '')
        ->assertSet('stage', '')
        ->assertSet('flag', '')
        ->assertSet('view', 'overview')
        ->assertSet('sortBy', 'budget');
});

test('a row opens its agreement from the eye button and links its donor and proposal', function () {
    $agreement = registerMixedAgreement();

    agreementRegister()
        ->assertSeeHtml('href="'.route('funding-agreements.show', $agreement).'"')
        ->assertSeeHtml('href="'.route('donors.show', $agreement->donor).'"')
        ->assertSeeHtml('href="'.route('proposals.show', $agreement->proposal).'"')
        ->assertSeeHtml('href="'.route('transfers.index', ['agreement' => $agreement->id]).'"');
});

test('a viewer without the update permission gets the table with no edit buttons', function () {
    $viewer = User::factory()->role('Managers')->create()->givePermissionTo('funding-agreements.view');
    $agreement = registerMixedAgreement();

    Livewire::actingAs($viewer)->test('pages::funding-agreements.index')
        ->assertSeeHtml('data-agreement-row="'.$agreement->id.'"')
        ->assertDontSeeHtml('data-test="edit-agreement-'.$agreement->id.'"')
        ->assertDontSeeHtml('data-test="create-agreement-button"');
});

test('the export holds every filtered agreement with plain numbers', function () {
    $donor = Donor::factory()->create(['name' => 'Alpha Fund']);
    $agreement = registerMixedAgreement('Water Response');
    $agreement->update(['donor_id' => $donor->id, 'external_project_number' => 'AF-01']);
    DonorProposal::factory()->count(30)->create(['currency' => Currency::Usd, 'budget' => '10.00']);
    DonorProposal::factory()->create(['currency' => Currency::Eur]);

    $page = agreementRegister()
        ->set('currency', 'USD')
        ->call('sortRegister', 'budget')
        ->call('exportCsv')
        ->assertFileDownloaded('funding-agreements-2026-06-15.csv');

    $csv = downloadedCsv($page);

    expect($csv)->toHaveCount(32)
        ->and($csv[0][0])->toBe('Donor')
        ->and($csv[1][0])->toBe('Alpha Fund')
        ->and($csv[1][1])->toBe('Water Response')
        ->and($csv[1][3])->toBe('AF-01')
        ->and($csv[1][4])->toBe('USD')
        ->and($csv[1][5])->toBe('1000.00')
        ->and($csv[1][6])->toBe('750.00')
        ->and($csv[1][7])->toBe('390.00')
        ->and($csv[1][8])->toBe('39')
        ->and($csv[1][13])->toBe('600.00')
        ->and($csv[1][14])->toBe('2026-06-05')
        ->and($csv[1][15])->toBe('4');
});

test('exporting needs the funding agreements view permission', function () {
    registerMixedAgreement();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::funding-agreements.index')
        ->call('exportCsv')
        ->assertForbidden();
});
