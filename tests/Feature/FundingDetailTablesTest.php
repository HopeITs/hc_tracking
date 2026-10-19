<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Enums\DonorReportType;
use App\Models\Account;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Proposal;
use App\Models\TransfersTracking;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo('2026-06-15 09:00:00');
    $this->user = User::factory()->role('Financial')->create();
});

/**
 * ECHO funds Water Response with 1,000 USD, reference WR-9: 400 received into
 * Operations USD (395 gross, 390 net, 5 in fees), 200 overdue since 5 June,
 * 100 due on 25 June.
 */
function detailAgreement(): DonorProposal
{
    $agreement = DonorProposal::factory()->create([
        'proposal_id' => Proposal::factory()->create(['name' => 'Water Response', 'donor_id' => Donor::factory()->create(['name' => 'ECHO'])->id])->id,
        'budget' => '1000.00',
        'currency' => Currency::Usd,
        'external_project_number' => 'WR-9',
    ]);

    $tranche = fn (array $attributes): TransfersTracking => TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'amount_transfered_currency' => Currency::Usd,
        'amount_received_currency' => Currency::Usd,
        'transfer_date' => null,
        'receive_date' => null,
        'ar_status' => ArStatus::Pending,
        ...$attributes,
    ]);

    $tranche([
        'account_id' => Account::factory()->create(['account_name' => 'Operations USD'])->id,
        'payment_amount' => '400.00',
        'fees' => '5.00',
        'fees_currency' => Currency::Usd,
        'amount_received' => '395.00',
        'net_amount_received' => '390.00',
        'ar_status' => ArStatus::Received,
        'scheduled_date' => '2026-05-15',
        'transfer_date' => '2026-05-16',
        'receive_date' => '2026-05-20',
    ]);
    $tranche(['payment_amount' => '200.00', 'scheduled_date' => '2026-06-05']);
    $tranche(['payment_amount' => '100.00', 'scheduled_date' => '2026-06-25']);

    return $agreement;
}

test('the agreement page opens on its totals and a table of every tranche', function () {
    $agreement = detailAgreement();

    Livewire::actingAs($this->user)
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->assertSet('view', 'table')
        ->assertSeeHtml('data-register')
        ->assertDontSeeHtml('data-dial')
        ->assertSeeHtml('data-total="budget"')
        ->assertSee('1,000.00')
        ->assertSee('700.00')
        ->assertSee('390.00')
        ->assertSee('395.00')
        ->assertSee('−10.00')
        ->assertSee('600.00')
        ->assertSee('Operations USD')
        ->assertSeeHtml('data-transfer-state="late"')
        ->assertSeeHtml('data-attention')
        ->assertSeeHtml('data-connections')
        ->assertSeeHtml('href="'.route('donors.show', $agreement->donor).'"')
        ->assertSeeHtml(e(route('transfers.index', ['agreement' => $agreement->id])));
});

test('the agreement page overview shows the dial and the journeys instead of the tables', function () {
    $agreement = detailAgreement();

    Livewire::actingAs($this->user)
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->set('view', 'overview')
        ->assertSeeHtml('data-dial')
        ->assertSeeHtml('data-trace')
        ->assertSeeHtml('data-attention')
        ->assertSeeHtml('data-connections')
        ->assertDontSeeHtml('data-register');

    Livewire::actingAs($this->user)
        ->withQueryParams(['view' => 'sideways'])
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->assertSet('view', 'table');
});

test('the agreement page lists donor reports as a table with their state and actions', function () {
    $agreement = detailAgreement();
    $overdue = DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'title' => 'Interim financial report', 'type' => DonorReportType::Financial, 'due_on' => '2026-06-10']);
    $sent = DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'title' => 'Inception report', 'due_on' => '2026-03-01', 'submitted_on' => '2026-02-27']);

    Livewire::actingAs($this->user)
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->assertSeeHtml('data-donor-report="'.$overdue->id.'"')
        ->assertSeeHtml('data-donor-report="'.$sent->id.'"')
        ->assertSee('Interim financial report')
        ->assertSee('5 days overdue')
        ->assertSee('Inception report')
        ->assertSeeHtml('data-test="submit-report-'.$overdue->id.'"')
        ->assertDontSeeHtml('data-test="submit-report-'.$sent->id.'"')
        ->assertSeeHtml('data-test="delete-report-'.$overdue->id.'"')
        ->assertDontSeeHtml('data-test="delete-report-'.$sent->id.'"');
});

test('the agreement page exports its tranches', function () {
    $agreement = detailAgreement();

    $page = Livewire::actingAs($this->user)
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->call('exportTranchesCsv')
        ->assertFileDownloaded('agreement-tranches-2026-06-15.csv');

    $csv = downloadedCsv($page);

    expect($csv)->toHaveCount(4)
        ->and($csv[1][0])->toBe('Received')
        ->and($csv[1][1])->toBe('ECHO')
        ->and($csv[1][2])->toBe('Water Response')
        ->and($csv[1][3])->toBe('WR-9')
        ->and($csv[1][4])->toBe('Operations USD')
        ->and($csv[1][9])->toBe('400.00')
        ->and($csv[1][14])->toBe('390.00')
        ->and($csv[1][16])->toBe('-10.00')
        ->and($csv[2][0])->toBe('Overdue');

    Livewire::actingAs(User::factory()->create())
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->call('exportTranchesCsv')
        ->assertForbidden();
});

test('the donor page opens on its agreements and all its tranches as tables', function () {
    $agreement = detailAgreement();
    $second = DonorProposal::factory()->create([
        'proposal_id' => Proposal::factory()->create(['name' => 'Winter Appeal', 'donor_id' => $agreement->donor_id])->id,
        'budget' => '500.00',
        'currency' => Currency::Eur,
    ]);

    $page = Livewire::actingAs($this->user)
        ->test('pages::donors.show', ['donor' => $agreement->donor])
        ->assertSet('view', 'table')
        ->assertDontSeeHtml('data-money-river')
        ->assertSeeHtml('data-agreements')
        ->assertSeeHtml('data-agreement-row="'.$agreement->id.'"')
        ->assertSeeHtml('data-agreement-row="'.$second->id.'"')
        ->assertSee('Water Response')
        ->assertSee('Winter Appeal')
        ->assertSee('1,000.00')
        ->assertSee('390.00')
        ->assertSee('600.00')
        ->assertSee('500.00')
        ->assertDontSee('1,500.00')
        ->assertSeeHtml('data-all-tranches')
        ->assertSeeHtml('data-transfer-state="late"')
        ->assertSee('Operations USD')
        ->assertSeeHtml('data-overdue-tranche=')
        ->assertDontSeeHtml('<details');

    expect($page->instance()->agreementRows)->toHaveCount(2)
        ->and((float) $page->instance()->agreementRows->firstWhere('id', $agreement->id)->money_received)->toBe(390.0)
        ->and($page->instance()->allTranches)->toHaveCount(3);
});

test('the donor page overview shows the money river and the calendar instead of the tables', function () {
    $agreement = detailAgreement();

    Livewire::actingAs($this->user)
        ->test('pages::donors.show', ['donor' => $agreement->donor])
        ->set('view', 'overview')
        ->assertSeeHtml('data-money-river')
        ->assertSeeHtml('data-money-calendar')
        ->assertSeeHtml('data-overdue-tranche=')
        ->assertDontSeeHtml('data-all-tranches');
});

test('the donor page shows budgets without tranches to someone who may not see transfers', function () {
    $agreement = detailAgreement();
    $viewer = userWithRole('Public Relations Manager')->givePermissionTo('funding-agreements.view');

    $page = Livewire::actingAs($viewer)->test('pages::donors.show', ['donor' => $agreement->donor]);

    $page->assertSee('1,000.00')
        ->assertDontSee('390.00')
        ->assertDontSeeHtml('data-all-tranches')
        ->assertDontSeeHtml('data-cell="received"');

    expect(array_key_exists('money_received', $page->instance()->agreementRows->first()->getAttributes()))->toBeFalse()
        ->and($page->instance()->allTranches)->toBeEmpty();

    Livewire::actingAs($viewer)
        ->test('pages::donors.show', ['donor' => $agreement->donor])
        ->call('exportTranchesCsv')
        ->assertForbidden();
});

test('the donor page exports every tranche of the donor', function () {
    $agreement = detailAgreement();

    $page = Livewire::actingAs($this->user)
        ->test('pages::donors.show', ['donor' => $agreement->donor])
        ->call('exportTranchesCsv')
        ->assertFileDownloaded('donor-tranches-2026-06-15.csv');

    $csv = downloadedCsv($page);

    expect($csv)->toHaveCount(4)
        ->and($csv[1][1])->toBe('ECHO')
        ->and($csv[1][2])->toBe('Water Response')
        ->and($csv[1][3])->toBe('WR-9');
});

test('the proposal funding tab opens on its agreements and tranches as tables', function () {
    $agreement = detailAgreement();

    $page = Livewire::actingAs($this->user)
        ->test('pages::proposals.show', ['proposal' => $agreement->proposal])
        ->set('tab', 'funding')
        ->assertSet('view', 'table')
        ->assertDontSeeHtml('data-chronology')
        ->assertSeeHtml('data-agreement-row="'.$agreement->id.'"')
        ->assertSee('ECHO')
        ->assertSee('1,000.00')
        ->assertSee('390.00')
        ->assertSee('600.00')
        ->assertSeeHtml('data-funding-tranches')
        ->assertSeeHtml('data-transfer-state="late"')
        ->assertSee('Operations USD')
        ->assertSeeHtml('data-test="record-agreement"')
        ->assertSeeHtml('data-test="edit-agreement-'.$agreement->id.'"')
        ->assertSeeHtml('data-test="schedule-tranche-'.$agreement->id.'"');

    expect($page->instance()->agreementRows)->toHaveCount(1)
        ->and($page->instance()->fundingTranches)->toHaveCount(3);
});

test('the proposal funding tab overview shows the chronology instead of the tables', function () {
    $agreement = detailAgreement();

    Livewire::actingAs($this->user)
        ->test('pages::proposals.show', ['proposal' => $agreement->proposal])
        ->set('tab', 'funding')
        ->set('view', 'overview')
        ->assertSeeHtml('data-chronology')
        ->assertSeeHtml('data-agreement="'.$agreement->id.'"')
        ->assertDontSeeHtml('data-funding-tranches');
});

test('the proposal funding tab shows budgets without tranches to someone who may not see transfers', function () {
    $agreement = detailAgreement();
    $viewer = userWithRole('Public Relations Manager')->givePermissionTo('funding-agreements.view');

    $page = Livewire::actingAs($viewer)
        ->test('pages::proposals.show', ['proposal' => $agreement->proposal])
        ->set('tab', 'funding');

    $page->assertSee('1,000.00')
        ->assertDontSee('390.00')
        ->assertDontSeeHtml('data-funding-tranches')
        ->assertDontSeeHtml('data-transfer=');

    expect($page->instance()->fundingTranches)->toBeEmpty();
});
