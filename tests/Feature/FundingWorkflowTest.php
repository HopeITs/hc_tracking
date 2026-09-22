<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\TransfersTracking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('a user without donor permissions cannot create, edit or delete a donor', function (string $method, array $arguments) {
    $user = User::factory()->create();
    $donor = Donor::factory()->create();

    Livewire::actingAs($user)->test('pages::donors.index')
        ->call($method, ...($arguments === [] ? [] : [$donor->id]))
        ->assertForbidden();

    expect(Donor::query()->whereKey($donor->id)->exists())->toBeTrue();
})->with([
    'create' => ['openCreateModal', []],
    'edit' => ['openEditModal', ['donor']],
    'delete' => ['confirmDelete', ['donor']],
]);

test('a sent tranche past its date is in transit on the donor page, never overdue', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create(['currency' => Currency::Usd, 'budget' => '1000.00']);
    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '400.00',
        'amount_transfered_currency' => Currency::Usd,
        'scheduled_date' => CarbonImmutable::today()->subDays(10),
        'transfer_date' => CarbonImmutable::today()->subDays(5),
        'ar_status' => ArStatus::Pending,
    ]);

    $page = Livewire::actingAs($user)->test('pages::donors.show', ['donor' => $agreement->donor_id])->instance();

    expect($page->overdueTranches)->toBe([])
        ->and($page->agreementMoney($page->agreements->first()))->toMatchArray(['due' => 400.0, 'overdue' => 0.0]);
});

test('the donor page never sets a tranche in another currency against the budget', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create(['currency' => Currency::Usd, 'budget' => '1000.00']);
    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '900.00',
        'amount_transfered_currency' => Currency::Eur,
        'scheduled_date' => CarbonImmutable::today()->addMonth(),
        'ar_status' => ArStatus::Pending,
    ]);

    $page = Livewire::actingAs($user)->test('pages::donors.show', ['donor' => $agreement->donor_id])->instance();

    expect($page->agreementMoney($page->agreements->first())['unscheduled'])->toBe(1000.0);
});

test('the donor page lists reports still owed to the donor and records an agreement for it', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create();
    $owed = DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'title' => 'Interim narrative', 'submitted_on' => null]);
    DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'title' => 'Inception report', 'submitted_on' => CarbonImmutable::today()]);

    Livewire::actingAs($user)->test('pages::donors.show', ['donor' => $agreement->donor_id])
        ->assertSeeHtml('data-owed-donor-report="'.$owed->id.'"')
        ->assertDontSee('Inception report')
        ->call('recordAgreementForDonor')
        ->assertSet('showAgreementModal', true)
        ->assertSet('agreementDonorId', (string) $agreement->donor_id);
});

test('an overdue donor report reaches the proposal deadlines and warnings', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create();
    DonorReport::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'due_on' => CarbonImmutable::today()->subDays(3),
        'submitted_on' => null,
    ]);

    $page = Livewire::actingAs($user)->test('pages::proposals.show', ['proposal' => $agreement->proposal_id])->instance();

    expect(collect($page->deadlines)->where('kind', 'donor-report')->where('state', 'overdue'))->toHaveCount(1)
        ->and(collect($page->warnings)->pluck('key'))->toContain('overdue-donor-reports');
});

test('a sent tranche is not an overdue deadline on the proposal page', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create();
    $transfer = TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'scheduled_date' => CarbonImmutable::today()->subDays(10),
        'transfer_date' => CarbonImmutable::today()->subDays(2),
        'ar_status' => ArStatus::Pending,
    ]);

    $page = Livewire::actingAs($user)->test('pages::proposals.show', ['proposal' => $agreement->proposal_id])->instance();

    expect($page->transferIsOverdue($transfer))->toBeFalse()
        ->and(collect($page->deadlines)->pluck('key'))->not->toContain('transfer-'.$transfer->id);
});

test('a new proposal opens its status history, and editing it never changes the status', function () {
    $user = User::factory()->role('Financial')->create();

    $component = Livewire::actingAs($user)->test('pages::proposals.index')
        ->call('openCreateModal')
        ->set('name', 'Winter response')
        ->set('plannedDateBegin', '2026-01-01')
        ->set('plannedDateEnd', '2026-12-31')
        ->set('proposalStatus', ProposalStatus::Submitted->value)
        ->call('saveProposal')
        ->assertHasNoErrors();

    $proposal = Proposal::query()->where('name', 'Winter response')->firstOrFail();

    expect($proposal->status)->toBe(ProposalStatus::Submitted)
        ->and(ProposalStatusNote::query()->where('proposal_id', $proposal->id)->pluck('status')->all())->toBe([ProposalStatus::Submitted]);

    $component->call('openEditModal', $proposal->id)
        ->set('proposalStatus', ProposalStatus::Completed->value)
        ->call('saveProposal')
        ->assertHasNoErrors();

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Submitted);
});

test('the funding flow opens a proposal that is not on the current page', function () {
    $user = User::factory()->role('Financial')->create();
    $agreements = DonorProposal::factory()->count(16)->create();
    $offPage = Livewire::actingAs($user)->test('pages::proposals.index')->instance()->proposals->getCollection()->modelKeys();
    $missing = $agreements->pluck('proposal_id')->diff($offPage)->first();

    Livewire::actingAs($user)->test('pages::proposals.index')
        ->assertSeeHtml('href="'.route('proposals.show', $missing).'"');
});
