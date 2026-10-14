<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Project;
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
 * The proposals page as the finance manager opens it.
 *
 * @param  array<string, string>  $query
 */
function proposalRegister(array $query = []): Testable
{
    return Livewire::actingAs(test()->user)->withQueryParams($query)->test('pages::proposals.index');
}

/**
 * A tranche in its agreement's own currency.
 *
 * @param  array<string, mixed>  $attributes
 */
function proposalTranche(DonorProposal $agreement, array $attributes): TransfersTracking
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
 * Water Response, active through 2026: ECHO commits 1,000 USD (400 received,
 * 390 net; 200 overdue; 100 due on 25 June) and GIZ commits 500 EUR with
 * nothing scheduled. It funds one project.
 */
function registerFundedProposal(): Proposal
{
    $proposal = Proposal::factory()->create([
        'name' => 'Water Response',
        'status' => ProposalStatus::Active,
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
    ]);
    $proposal->projects()->attach(Project::factory()->create());

    $fundingDonorId = Donor::factory()->create(['name' => 'ECHO'])->id;
    Proposal::query()->whereKey($proposal->id)->update(['donor_id' => $fundingDonorId]);
    $dollars = DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'donor_id' => $fundingDonorId,
        'budget' => '1000.00',
        'currency' => Currency::Usd,
    ]);
    // A proposal has one donor and one agreement, so the second currency is a
    // sibling proposal (a co-funder is a separate proposal, D-24).
    $coFunded = Proposal::factory()->create([
        'name' => 'Water Response',
        'donor_id' => Donor::factory()->create(['name' => 'GIZ'])->id,
        'status' => ProposalStatus::Active,
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
    ]);
    DonorProposal::factory()->create([
        'proposal_id' => $coFunded->id,
        'budget' => '500.00',
        'currency' => Currency::Eur,
    ]);

    proposalTranche($dollars, ['payment_amount' => '400.00', 'net_amount_received' => '390.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2026-05-15', 'transfer_date' => '2026-05-15', 'receive_date' => '2026-05-20']);
    proposalTranche($dollars, ['payment_amount' => '200.00', 'scheduled_date' => '2026-06-05']);
    proposalTranche($dollars, ['payment_amount' => '100.00', 'scheduled_date' => '2026-06-25']);

    return $proposal;
}

test('the page opens on the table with one row per proposal and currency', function () {
    $funded = registerFundedProposal();
    $idea = Proposal::factory()->create(['name' => 'Shelter Response', 'status' => ProposalStatus::ConceptNote, 'planned_date_begin' => '2025-03-01', 'planned_date_end' => '2025-09-30']);

    $page = proposalRegister()
        ->assertSet('view', 'table')
        ->assertSeeHtml('data-register')
        ->assertDontSeeHtml('data-funding-position')
        ->assertSee('Water Response')
        ->assertSee('Shelter Response')
        ->assertSee('ECHO')
        ->assertSee('GIZ')
        ->assertSee('1,000.00')
        ->assertSee('390.00')
        ->assertSee('39%')
        ->assertSee('600.00');

    $rows = $page->instance()->rows->getCollection();

    expect($rows->map(fn (Proposal $row): string => $row->name.'|'.($row->money_currency ?? '-'))->all())
        ->toBe(['Water Response|USD', 'Water Response|EUR', 'Shelter Response|-'])
        ->and($page->instance()->proposalCount)->toBe(3);

    $dollars = $rows->firstWhere('money_currency', 'USD');

    expect((int) $dollars->money_agreements)->toBe(1)
        ->and((float) $dollars->money_committed)->toBe(1000.0)
        ->and((float) $dollars->money_received)->toBe(390.0)
        ->and((float) $dollars->money_outstanding)->toBe(600.0)
        ->and((float) $dollars->money_overdue)->toBe(200.0)
        ->and($dollars->money_next_due)->toBe('2026-06-05')
        ->and((int) $dollars->projects_count)->toBe(1)
        ->and($rows->last()->id)->toBe($idea->id);
});

test('the overview view shows the funding position and the dossiers instead of the table', function () {
    registerFundedProposal();

    proposalRegister()
        ->set('view', 'overview')
        ->assertSeeHtml('data-funding-position')
        ->assertDontSeeHtml('data-register');
});

test('a row offers the view, change status, edit and delete actions the dossier offered', function () {
    $funded = registerFundedProposal();
    $idea = Proposal::factory()->create(['name' => 'Shelter Response']);

    proposalRegister()
        ->assertSeeHtml('aria-label="View Water Response"')
        ->assertSeeHtml('href="'.route('proposals.show', $funded).'"')
        ->assertSeeHtml('data-test="advance-proposal-'.$funded->id.'"')
        ->assertSeeHtml('wire:click="confirmDelete('.$idea->id.')"')
        ->assertDontSeeHtml('wire:click="confirmDelete('.$funded->id.')"');
});

test('currency, donor and the flags narrow the rows', function () {
    $funded = registerFundedProposal();
    $idea = Proposal::factory()->create(['name' => 'Shelter Response']);
    $reporting = Proposal::factory()->create(['name' => 'Clinic Rebuild']);
    DonorReport::factory()->create([
        'donor_proposal_id' => DonorProposal::factory()->create(['proposal_id' => $reporting->id, 'currency' => Currency::Usd])->id,
        'due_on' => '2026-06-01',
    ]);

    $proposalIds = fn (Testable $page): array => $page->instance()->rows->pluck('id')->unique()->sort()->values()->all();
    $echo = Donor::query()->where('name', 'ECHO')->sole();

    expect($proposalIds(proposalRegister()->call('toggleFlag', 'no_agreement')))->toBe([$idea->id])
        ->and($proposalIds(proposalRegister()->call('toggleFlag', 'overdue')))->toBe([$funded->id])
        ->and($proposalIds(proposalRegister()->call('toggleFlag', 'report_overdue')))->toBe([$reporting->id])
        ->and($proposalIds(proposalRegister()->set('donor', (string) $echo->id)))->toBe([$funded->id]);

    proposalRegister()->set('donor', (string) $echo->id)->assertSee('Funded by ECHO')->assertSeeHtml('data-donor-chip');

    // A currency keeps only that currency's line of each proposal.
    $euros = proposalRegister()->set('currency', 'EUR')->instance()->rows->getCollection();

    expect($euros)->toHaveCount(1)
        ->and($euros->first()->money_currency)->toBe('EUR');
});

test('choosing a year adds that year beside the lifetime figures and hides lines with nothing in it', function () {
    registerFundedProposal();
    $earlier = Proposal::factory()->create(['name' => 'Old Grant']);
    proposalTranche(
        DonorProposal::factory()->create(['proposal_id' => $earlier->id, 'budget' => '700.00', 'currency' => Currency::Usd]),
        ['payment_amount' => '700.00', 'net_amount_received' => '700.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2024-02-01', 'receive_date' => '2024-02-05'],
    );

    $page = proposalRegister()->set('year', '2026');
    $row = $page->instance()->rows->getCollection()->sole();

    expect($row->name)->toBe('Water Response')
        ->and($row->money_currency)->toBe('USD')
        ->and((float) $row->money_due_in_year)->toBe(700.0)
        ->and((float) $row->money_received_in_year)->toBe(390.0);

    $page->assertSee('Due in 2026')->assertSee('Received in 2026');

    $only = proposalRegister()->set('year', '2024')->set('figures', 'year')->instance()->rows->getCollection()->sole();

    expect($only->name)->toBe('Old Grant')
        ->and((float) $only->money_received)->toBe(700.0);
});

test('totals are kept per currency and add up to the rows shown', function () {
    registerFundedProposal();
    DonorProposal::factory()->create(['budget' => '2000.00', 'currency' => Currency::Usd]);

    $page = proposalRegister();
    $totals = $page->instance()->totals->keyBy('currency');

    expect($totals->keys()->all())->toBe(['EUR', 'USD'])
        ->and($totals['USD'])->toMatchArray(['proposals' => 2, 'agreements' => 2, 'committed' => 3000.0, 'received' => 390.0, 'outstanding' => 2600.0, 'overdue' => 200.0])
        ->and($totals['EUR'])->toMatchArray(['proposals' => 1, 'committed' => 500.0]);

    $page->assertDontSeeHtml('data-register-total=')->assertSeeHtml('data-totals-currency="USD"')
        ->assertSeeHtml('data-totals-currency="EUR"')
        ->assertDontSee('3,500.00');
});

test('the table opens newest planned start first and a heading sorts it', function () {
    registerFundedProposal();
    $older = Proposal::factory()->create(['name' => 'Alpha Appeal', 'planned_date_begin' => '2025-01-01', 'planned_date_end' => '2025-12-31']);
    DonorProposal::factory()->create(['proposal_id' => $older->id, 'budget' => '9000.00', 'currency' => Currency::Usd]);

    $lines = fn (Testable $page): array => $page->instance()->rows->getCollection()->map(fn (Proposal $row): string => $row->name.'|'.$row->money_currency)->all();

    $page = proposalRegister();
    expect($lines($page))->toBe(['Water Response|USD', 'Water Response|EUR', 'Alpha Appeal|USD']);

    $page->call('sortRegister', 'proposal');
    expect($lines($page))->toBe(['Alpha Appeal|USD', 'Water Response|USD', 'Water Response|EUR']);

    $page->call('sortRegister', 'committed');
    expect($lines($page))->toBe(['Water Response|EUR', 'Alpha Appeal|USD', 'Water Response|USD']);
});

test('someone who may not see agreements gets the proposals with no donor, no money and no money query', function () {
    registerFundedProposal();

    $page = Livewire::actingAs(userWithRole(fundraiserWithoutAgreements()))->test('pages::proposals.index');

    $page->assertOk()
        ->assertSee('Water Response')
        ->assertDontSee('ECHO')
        ->assertDontSee('1,000.00')
        ->assertDontSeeHtml('data-cell="committed"')
        ->assertDontSeeHtml('data-register-totals');

    expect($page->instance()->rows->getCollection())->toHaveCount(2)
        ->and($page->instance()->totals)->toBeEmpty()
        ->and($page->instance()->filterDonors)->toBeEmpty()
        ->and(array_key_exists('money_committed', $page->instance()->rows->first()->getAttributes()))->toBeFalse()
        ->and($page->instance()->rows->first()->relationLoaded('donorProposals'))->toBeFalse();
});

test('someone who may see agreements but not transfers gets budgets without tranche figures', function () {
    registerFundedProposal();
    $viewer = userWithRole('Public Relations Manager')->givePermissionTo('funding-agreements.view');

    $page = Livewire::actingAs($viewer)->test('pages::proposals.index');

    $page->assertSee('ECHO')
        ->assertSee('1,000.00')
        ->assertDontSee('390.00')
        ->assertDontSeeHtml('data-cell="received"');

    expect((float) $page->instance()->rows->getCollection()->firstWhere('money_currency', 'USD')->money_received)->toBe(0.0);
});

test('values typed into the address bar fall back to the defaults', function () {
    registerFundedProposal();

    proposalRegister(['view' => 'board', 'status' => 'lost', 'phase' => 'moon', 'donor' => 'me', 'currency' => 'GBP', 'flag' => 'rich', 'sortBy' => 'notes', 'perPage' => '2'])
        ->assertSet('view', 'table')
        ->assertSet('status', '')
        ->assertSet('phase', '')
        ->assertSet('donor', '')
        ->assertSet('currency', '')
        ->assertSet('flag', '')
        ->assertSet('sortBy', '')
        ->assertSet('perPage', '')
        ->assertOk();
});

test('the export holds every filtered line and only the columns the viewer may see', function () {
    registerFundedProposal();
    Proposal::factory()->count(30)->create(['planned_date_begin' => '2020-01-01', 'planned_date_end' => '2020-12-31']);

    $page = proposalRegister()->call('exportCsv')->assertFileDownloaded('proposals-2026-06-15.csv');
    $csv = downloadedCsv($page);
    $dollars = collect($csv)->first(fn (array $row): bool => $row[0] === 'Water Response' && $row[8] === 'USD');

    expect($csv)->toHaveCount(33)
        ->and($csv[0][0])->toBe('Proposal')
        ->and($csv[0][9])->toBe('Committed')
        ->and($dollars[1])->toBe('Active')
        ->and($dollars[3])->toBe('2026-01-01')
        ->and($dollars[4])->toBe('2026-12-31')
        ->and($dollars[5])->toBe('1')
        ->and($dollars[6])->toBe('ECHO')
        ->and($dollars[7])->toBe('1')
        ->and($dollars[9])->toBe('1000.00')
        ->and($dollars[10])->toBe('390.00')
        ->and($dollars[11])->toBe('39')
        ->and($dollars[12])->toBe('600.00')
        ->and($dollars[13])->toBe('200.00')
        ->and($dollars[14])->toBe('2026-06-05');

    $fundraiser = Livewire::actingAs(userWithRole(fundraiserWithoutAgreements()))->test('pages::proposals.index')->call('exportCsv');
    $plain = downloadedCsv($fundraiser);

    expect($plain[0])->toHaveCount(6)
        ->and(collect($plain)->flatten()->contains('ECHO'))->toBeFalse()
        ->and(collect($plain)->flatten()->contains('1000.00'))->toBeFalse();
});

test('exporting needs the proposals view permission', function () {
    registerFundedProposal();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::proposals.index')
        ->call('exportCsv')
        ->assertForbidden();
});

test('the proposal form is rendered only while its modal is open', function () {
    proposalRegister()
        ->assertDontSeeHtml('wire:model="plannedDateBegin"')
        ->call('openCreateModal')
        ->assertSeeHtml('wire:model="plannedDateBegin"');
});

test('the status form is rendered only while its modal is open', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);

    proposalRegister()
        ->assertDontSeeHtml('wire:model="statusContext"')
        ->call('openStatusModal', $proposal->id)
        ->set('newStatus', ProposalStatus::ProposalDraft->value)
        ->assertSeeHtml('wire:model="statusContext"');
});
