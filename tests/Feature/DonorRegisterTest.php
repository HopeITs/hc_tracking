<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Enums\DonorType;
use App\Models\Donor;
use App\Models\DonorProposal;
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
 * The donors page as the finance manager opens it.
 *
 * @param  array<string, string>  $query
 */
function donorRegister(array $query = []): Testable
{
    return Livewire::actingAs(test()->user)->withQueryParams($query)->test('pages::donors.index');
}

/**
 * A tranche in its agreement's own currency.
 *
 * @param  array<string, mixed>  $attributes
 */
function donorTranche(DonorProposal $agreement, array $attributes): TransfersTracking
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
 * UNICEF: 1,000 USD with 400 received (390 net), 200 overdue and 100 due on
 * 25 June; and 500 EUR with nothing scheduled.
 */
function registerFundingDonor(): Donor
{
    $donor = Donor::factory()->ofType(DonorType::Bilateral)->create([
        'name' => 'UNICEF',
        'country' => 'Switzerland',
        'contact_person' => 'Lina Haddad',
        'email' => 'grants@unicef.test',
        'phone' => '+961 (1) 234-567',
    ]);

    $dollars = DonorProposal::factory()->create(['donor_id' => $donor->id, 'budget' => '1000.00', 'currency' => Currency::Usd]);
    DonorProposal::factory()->create(['donor_id' => $donor->id, 'budget' => '500.00', 'currency' => Currency::Eur]);

    donorTranche($dollars, ['payment_amount' => '400.00', 'net_amount_received' => '390.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2026-05-15', 'transfer_date' => '2026-05-15', 'receive_date' => '2026-05-20']);
    donorTranche($dollars, ['payment_amount' => '200.00', 'scheduled_date' => '2026-06-05']);
    donorTranche($dollars, ['payment_amount' => '100.00', 'scheduled_date' => '2026-06-25']);

    return $donor;
}

test('the page opens on the table with one row per donor and currency', function () {
    $donor = registerFundingDonor();
    $prospect = Donor::factory()->create(['name' => 'Zeta Trust']);

    $page = donorRegister()
        ->assertSet('view', 'table')
        ->assertSeeHtml('data-register')
        ->assertDontSeeHtml('data-wallet')
        ->assertSee('UNICEF')
        ->assertSee('Zeta Trust')
        ->assertSee('1,000.00')
        ->assertSee('390.00')
        ->assertSee('39%')
        ->assertSee('600.00');

    $rows = $page->instance()->rows->getCollection();

    expect($rows->map(fn (Donor $row): string => $row->name.'|'.($row->money_currency ?? '-'))->all())
        ->toBe(['UNICEF|EUR', 'UNICEF|USD', 'Zeta Trust|-'])
        ->and($page->instance()->donorCount)->toBe(2);

    $dollars = $rows->firstWhere('money_currency', 'USD');

    expect((int) $dollars->money_agreements)->toBe(1)
        ->and((float) $dollars->money_committed)->toBe(1000.0)
        ->and((float) $dollars->money_received)->toBe(390.0)
        ->and((float) $dollars->money_outstanding)->toBe(600.0)
        ->and((float) $dollars->money_overdue)->toBe(200.0)
        ->and((int) $dollars->money_overdue_count)->toBe(1)
        ->and($dollars->money_next_due)->toBe('2026-06-05')
        ->and($dollars->money_last_received)->toBe('2026-05-20');

    expect((float) $rows->firstWhere('money_currency', 'EUR')->money_committed)->toBe(500.0)
        ->and($prospect->id)->toBe($rows->last()->id);
});

test('the overview view shows the wallet and the donor list instead of the table', function () {
    registerFundingDonor();

    donorRegister()
        ->set('view', 'overview')
        ->assertSeeHtml('data-wallet')
        ->assertDontSeeHtml('data-register');
});

test('a row names the first contact, the follow-up date and the next transfer, and leaves the rest to the donor page', function () {
    $donor = registerFundingDonor();
    $donor->update(['contact_person' => 'Lina Haddad / Omar Saleh / Rana Aziz', 'next_step' => 'Send the interim report', 'next_step_due_on' => '2026-06-01']);

    donorRegister()
        ->assertSee('Lina Haddad')
        ->assertDontSee('Omar Saleh')
        ->assertSee('+2 more')
        ->assertDontSeeHtml('href="mailto:grants@unicef.test"')
        ->assertDontSeeHtml('href="tel:+9611234567"')
        ->assertDontSee('Send the interim report')
        ->assertSeeHtml('data-follow-up-overdue')
        ->assertSeeHtml('data-next-transfer')
        ->assertSee('200.00 USD')
        ->assertSeeHtml('aria-label="View UNICEF"')
        ->assertSeeHtml('href="'.route('donors.show', $donor).'"');
});

test('country, currency, relationship, type and the flags narrow the rows', function () {
    $funding = registerFundingDonor();
    $prospect = Donor::factory()->ofType(DonorType::Foundation)->create(['name' => 'Zeta Trust', 'country' => 'Norway', 'next_step' => 'Call back', 'next_step_due_on' => '2026-06-10']);

    $donorIds = fn (Testable $page): array => $page->instance()->rows->pluck('id')->unique()->sort()->values()->all();

    expect($donorIds(donorRegister()->set('countryFilter', 'Norway')))->toBe([$prospect->id])
        ->and($donorIds(donorRegister(['country' => 'Switzerland'])))->toBe([$funding->id])
        ->and($donorIds(donorRegister()->set('relationship', 'prospect')))->toBe([$prospect->id])
        ->and($donorIds(donorRegister()->call('toggleType', 'bilateral')))->toBe([$funding->id])
        ->and($donorIds(donorRegister()->call('toggleFlag', 'overdue')))->toBe([$funding->id])
        ->and($donorIds(donorRegister()->call('toggleFlag', 'follow_up')))->toBe([$prospect->id]);

    // A currency keeps only that currency's line of each donor.
    $euros = donorRegister()->set('currency', 'EUR')->instance()->rows->getCollection();

    expect($euros)->toHaveCount(1)
        ->and($euros->first()->money_currency)->toBe('EUR');
});

test('choosing a year adds that year beside the lifetime figures and hides lines with nothing in it', function () {
    registerFundingDonor();
    $earlier = Donor::factory()->create(['name' => 'Old Grant']);
    donorTranche(
        DonorProposal::factory()->create(['donor_id' => $earlier->id, 'budget' => '700.00', 'currency' => Currency::Usd]),
        ['payment_amount' => '700.00', 'net_amount_received' => '700.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2024-02-01', 'receive_date' => '2024-02-05'],
    );

    $page = donorRegister()->set('year', '2026');
    $row = $page->instance()->rows->getCollection()->sole();

    expect($row->name)->toBe('UNICEF')
        ->and($row->money_currency)->toBe('USD')
        ->and((float) $row->money_due_in_year)->toBe(700.0)
        ->and((float) $row->money_received_in_year)->toBe(390.0)
        ->and((float) $row->money_committed)->toBe(1000.0);

    $page->assertSee('Due in 2026')->assertSee('Received in 2026');

    $only = donorRegister()->set('year', '2024')->set('figures', 'year')->instance()->rows->getCollection()->sole();

    expect($only->name)->toBe('Old Grant')
        ->and((float) $only->money_received)->toBe(700.0);
});

test('totals are kept per currency and add up to the rows shown', function () {
    registerFundingDonor();
    DonorProposal::factory()->create(['donor_id' => Donor::factory()->create()->id, 'budget' => '2000.00', 'currency' => Currency::Usd]);

    $page = donorRegister();
    $totals = $page->instance()->totals->keyBy('currency');

    expect($totals->keys()->all())->toBe(['EUR', 'USD'])
        ->and($totals['USD'])->toMatchArray(['donors' => 2, 'agreements' => 2, 'committed' => 3000.0, 'received' => 390.0, 'outstanding' => 2600.0, 'overdue' => 200.0])
        ->and($totals['EUR'])->toMatchArray(['donors' => 1, 'committed' => 500.0]);

    $page->assertDontSeeHtml('data-register-total=')->assertSeeHtml('data-totals-currency="USD"')
        ->assertSeeHtml('data-totals-currency="EUR"')
        ->assertDontSee('3,500.00');
});

test('the table opens by donor name and a heading sorts it', function () {
    registerFundingDonor();
    $larger = Donor::factory()->create(['name' => 'Alpha Fund']);
    DonorProposal::factory()->create(['donor_id' => $larger->id, 'budget' => '9000.00', 'currency' => Currency::Usd]);

    $lines = fn (Testable $page): array => $page->instance()->rows->getCollection()->map(fn (Donor $row): string => $row->name.'|'.$row->money_currency)->all();

    $page = donorRegister();
    expect($lines($page))->toBe(['Alpha Fund|USD', 'UNICEF|EUR', 'UNICEF|USD']);

    $page->call('sortRegister', 'committed');
    expect($lines($page))->toBe(['UNICEF|EUR', 'Alpha Fund|USD', 'UNICEF|USD']);
});

test('someone who may not see agreements gets the donors with no money columns and no money query', function () {
    registerFundingDonor();

    $page = Livewire::actingAs(userWithRole(fundraiserWithoutAgreements()))->test('pages::donors.index');

    $page->assertOk()
        ->assertSee('UNICEF')
        ->assertSee('Lina Haddad')
        ->assertDontSee('1,000.00')
        ->assertDontSee('390.00')
        ->assertDontSeeHtml('data-cell="committed"')
        ->assertDontSeeHtml('data-register-totals');

    expect($page->instance()->rows->getCollection())->toHaveCount(1)
        ->and($page->instance()->totals)->toBeEmpty()
        ->and($page->instance()->nextTransfers)->toBeEmpty()
        ->and(array_key_exists('money_committed', $page->instance()->rows->first()->getAttributes()))->toBeFalse();
});

test('someone who may see agreements but not transfers gets budgets without tranche figures', function () {
    registerFundingDonor();
    $viewer = userWithRole('Public Relations Manager')->givePermissionTo('funding-agreements.view');

    $page = Livewire::actingAs($viewer)->test('pages::donors.index');

    $page->assertSee('1,000.00')
        ->assertDontSee('390.00')
        ->assertDontSeeHtml('data-cell="received"')
        ->assertDontSeeHtml('data-next-transfer');

    $dollars = $page->instance()->rows->getCollection()->firstWhere('money_currency', 'USD');

    expect((float) $dollars->money_committed)->toBe(1000.0)
        ->and((float) $dollars->money_received)->toBe(0.0)
        ->and($page->instance()->nextTransfers)->toBeEmpty();
});

test('the table shows five donors a page by default and offers a few more', function () {
    Donor::factory()->count(12)->create();

    $page = donorRegister()->assertSet('perPage', '')->assertSeeHtml('data-test="per-page-5"');

    expect($page->instance()->rows)->toHaveCount(5);

    $page->set('perPage', '10');
    expect($page->instance()->rows)->toHaveCount(10);

    $page->set('perPage', '5')->assertSet('perPage', '');
});

test('every filter sits behind one filters button, whose badge counts the filters applied', function () {
    registerFundingDonor();

    donorRegister(['type' => 'bilateral', 'country' => 'Switzerland'])
        ->assertSeeHtml('data-test="open-filters"')
        ->assertSeeHtml('data-filter-count>2</span>')
        ->call('clearFilters')
        ->assertDontSeeHtml('data-filter-count');
});

test('the filters dialog only drafts: its controls send nothing until the draft is applied', function () {
    registerFundingDonor();
    Donor::factory()->create(['name' => 'Zeta Trust', 'country' => 'Norway']);

    $page = donorRegister()
        ->assertSeeHtml('wire:model="search"')
        ->assertDontSeeHtml('wire:model.live.debounce.300ms="search"')
        ->assertDontSeeHtml("wire:click=\"toggleType('bilateral')\"")
        ->assertSeeHtml("pick('type', 'bilateral', true");

    // The draft arrives with the apply call: the list narrows and starts again at page one.
    $page->set('countryFilter', 'Norway')->call('applyFilters');

    expect($page->instance()->rows->pluck('name')->all())->toBe(['Zeta Trust']);
});

test('values typed into the address bar fall back to the defaults', function () {
    registerFundingDonor();

    donorRegister(['view' => 'list', 'type' => 'royalty', 'relationship' => 'friends', 'sort' => 'luck', 'country' => 'Atlantis', 'currency' => 'GBP', 'flag' => 'rich', 'sortBy' => 'email', 'perPage' => '1'])
        ->assertSet('view', 'table')
        ->assertSet('type', '')
        ->assertSet('relationship', '')
        ->assertSet('sort', 'name')
        ->assertSet('countryFilter', '')
        ->assertSet('currency', '')
        ->assertSet('flag', '')
        ->assertSet('sortBy', '')
        ->assertSet('perPage', '')
        ->assertOk();
});

test('the export holds every filtered line and only the columns the viewer may see', function () {
    registerFundingDonor();
    Donor::factory()->count(30)->create();

    $page = donorRegister()->call('exportCsv')->assertFileDownloaded('donors-2026-06-15.csv');
    $csv = downloadedCsv($page);
    $unicef = collect($csv)->first(fn (array $row): bool => $row[0] === 'UNICEF' && $row[10] === 'USD');

    expect($csv)->toHaveCount(33)
        ->and($csv[0][0])->toBe('Donor')
        ->and($csv[0][11])->toBe('Committed')
        ->and($unicef[1])->toBe('Bilateral')
        ->and($unicef[2])->toBe('Switzerland')
        ->and($unicef[3])->toBe('Lina Haddad')
        ->and($unicef[4])->toBe('grants@unicef.test')
        ->and($unicef[9])->toBe('1')
        ->and($unicef[11])->toBe('1000.00')
        ->and($unicef[12])->toBe('390.00')
        ->and($unicef[13])->toBe('39')
        ->and($unicef[14])->toBe('600.00')
        ->and($unicef[15])->toBe('200.00')
        ->and($unicef[17])->toBe('2026-06-05');

    $fundraiser = Livewire::actingAs(userWithRole(fundraiserWithoutAgreements()))->test('pages::donors.index')->call('exportCsv');
    $plain = downloadedCsv($fundraiser);

    expect($plain[0])->toHaveCount(9)
        ->and(collect($plain)->flatten()->contains('1000.00'))->toBeFalse();
});

test('exporting needs the donors view permission', function () {
    registerFundingDonor();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::donors.index')
        ->call('exportCsv')
        ->assertForbidden();
});
