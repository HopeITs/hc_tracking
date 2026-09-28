<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Enums\DonorType;
use App\Enums\ProjectStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\TransfersTracking;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('guests are redirected from the donor directory', function () {
    $this->get(route('donors.index'))->assertRedirect(route('login'));
});

test('donors can be searched', function () {
    $user = User::factory()->role('Financial')->create();
    Donor::factory()->create(['name' => 'UNICEF', 'country' => null, 'contact_person' => null]);
    Donor::factory()->create(['name' => 'ECHO', 'country' => null, 'contact_person' => null]);

    Livewire::actingAs($user)
        ->test('pages::donors.index')
        ->assertSee('UNICEF')
        ->assertSee('ECHO')
        ->set('search', 'UNI')
        ->assertSee('UNICEF')
        ->assertDontSee('ECHO');
});

test('a donor can be created and two donors may share a name', function () {
    $user = User::factory()->role('Financial')->create();
    Donor::factory()->create(['name' => 'Country Office']);

    Livewire::actingAs($user)
        ->test('pages::donors.index')
        ->call('openCreateModal')
        ->set('name', '  Country Office  ')
        ->call('saveDonor')
        ->assertHasNoErrors();

    expect(Donor::query()->where('name', 'Country Office')->count())->toBe(2);
});

test('creating a donor requires a name', function () {
    $user = User::factory()->role('Financial')->create();

    Livewire::actingAs($user)
        ->test('pages::donors.index')
        ->call('openCreateModal')
        ->set('name', '')
        ->call('saveDonor')
        ->assertHasErrors(['name']);
});

test('a donor with no agreements can be deleted', function () {
    $user = User::factory()->role('Financial')->create();
    $donor = Donor::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::donors.index')
        ->call('confirmDelete', $donor->id)
        ->call('deleteDonor')
        ->assertHasNoErrors();

    expect(DB::table('donors')->find($donor->id))->toBeNull();
});

test('a donor holding an agreement is not deleted', function () {
    $user = User::factory()->role('Financial')->create();
    $donor = Donor::factory()->create();
    DonorProposal::factory()->create([
        'donor_id' => $donor->id,
        'proposal_id' => Proposal::factory()->create()->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::donors.index')
        ->call('confirmDelete', $donor->id)
        ->call('deleteDonor');

    expect(DB::table('donors')->find($donor->id))->not->toBeNull();
});

test('donor detail totals committed budget per currency', function () {
    $user = User::factory()->role('Financial')->create();
    $donor = Donor::factory()->create(['name' => 'UNICEF']);

    DonorProposal::factory()->create([
        'donor_id' => $donor->id,
        'proposal_id' => Proposal::factory()->create(['name' => 'First Proposal'])->id,
        'budget' => '500000.00',
        'currency' => Currency::Usd,
    ]);
    DonorProposal::factory()->create([
        'donor_id' => $donor->id,
        'proposal_id' => Proposal::factory()->create(['name' => 'Second Proposal'])->id,
        'budget' => '250000.00',
        'currency' => Currency::Eur,
    ]);

    $this->actingAs($user)
        ->get(route('donors.show', $donor))
        ->assertOk()
        ->assertSeeText('UNICEF')
        ->assertSeeText('First Proposal')
        ->assertSeeText('Second Proposal')
        ->assertSeeText('500,000.00')
        ->assertSeeText('250,000.00')
        ->assertDontSeeText('750,000.00');
});

test('donor detail renders a donor with no agreements', function () {
    $user = User::factory()->role('Financial')->create();
    $donor = Donor::factory()->create(['name' => 'Quiet Donor']);

    $this->actingAs($user)
        ->get(route('donors.show', $donor))
        ->assertOk()
        ->assertSeeText('Quiet Donor')
        ->assertSeeText('No agreements yet');
});

test('donor detail shows total disbursed and reports submitted separately from total committed', function () {
    $user = User::factory()->role('Financial')->create();
    $donor = Donor::factory()->create(['name' => 'UNICEF']);

    $proposal = Proposal::factory()->create(['name' => 'First Proposal']);
    $agreement = DonorProposal::factory()->create([
        'donor_id' => $donor->id,
        'proposal_id' => $proposal->id,
        'budget' => '500000.00',
        'currency' => Currency::Usd,
    ]);

    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '120000.00',
        'net_amount_received' => '120000.00',
        'amount_received_currency' => Currency::Usd,
        'amount_transfered_currency' => Currency::Usd,
        'receive_date' => now()->subMonth()->toDateString(),
        'ar_status' => ArStatus::Received,
    ]);

    $openProject = Project::factory()->create(['status' => ProjectStatus::Active]);
    $closedProject = Project::factory()->create(['status' => ProjectStatus::Closed]);
    $proposal->projects()->attach([$openProject->id, $closedProject->id]);

    $indicator = Indicator::factory()->create();
    $planIndicator = PlanIndicator::factory()->create([
        'source_type' => 'proposal',
        'source_id' => $proposal->id,
        'indicator_id' => $indicator->id,
    ]);
    Report::factory()->create([
        'plan_indicator_id' => $planIndicator->id,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('donors.show', $donor))
        ->assertOk()
        ->assertSeeText('500,000.00')
        ->assertSeeText('120,000.00')
        ->assertDontSeeText('620,000.00')
        ->assertSeeText('1');
});

test('donor detail counts only received tranches as money in and flags overdue ones', function () {
    $user = User::factory()->role('Financial')->create();
    $donor = Donor::factory()->create();
    $agreement = DonorProposal::factory()->create([
        'donor_id' => $donor->id,
        'proposal_id' => Proposal::factory()->create(['name' => 'Late Grant'])->id,
        'budget' => '1000.00',
        'currency' => Currency::Usd,
    ]);

    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '300.00',
        'net_amount_received' => '300.00',
        'amount_received_currency' => Currency::Usd,
        'amount_transfered_currency' => Currency::Usd,
        'receive_date' => now()->subMonths(2)->toDateString(),
        'ar_status' => ArStatus::Received,
    ]);
    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '450.00',
        'net_amount_received' => '450.00',
        'amount_received_currency' => Currency::Usd,
        'amount_transfered_currency' => Currency::Usd,
        'scheduled_date' => now()->subDays(10)->toDateString(),
        'ar_status' => ArStatus::Pending,
    ]);

    $component = Livewire::actingAs($user)->test('pages::donors.show', ['donor' => $donor]);

    expect($component->instance()->moneyByCurrency['USD'])->toMatchArray([
        'committed' => 1000.0,
        'received' => 300.0,
        'overdue' => 450.0,
        'due' => 0.0,
        'unscheduled' => 250.0,
    ]);

    $component
        ->assertSeeHtml('data-overdue-tranche=')
        ->assertSeeText('10 days late')
        ->assertDontSeeText('750.00');
});

test('opening the delete confirmation opens its dialog and cancelling closes it', function () {
    $user = User::factory()->role('Financial')->create();
    $donor = Donor::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::donors.index')
        ->call('confirmDelete', $donor->id)
        ->assertSet('showDeleteModal', true)
        ->call('cancelDelete')
        ->assertSet('showDeleteModal', false)
        ->assertSet('deletingDonorId', null);
});

test('donors can be filtered by type from the rail and by relationship', function () {
    $user = User::factory()->role('Financial')->create();
    $funder = Donor::factory()->create(['name' => 'Funding Foundation', 'type' => DonorType::Foundation]);
    Donor::factory()->create(['name' => 'Quiet Foundation', 'type' => DonorType::Foundation]);
    Donor::factory()->create(['name' => 'Unclassified Office', 'type' => null]);
    DonorProposal::factory()->create(['donor_id' => $funder->id, 'proposal_id' => Proposal::factory()->create()->id]);

    Livewire::actingAs($user)
        ->test('pages::donors.index')
        ->call('toggleType', 'unclassified')
        ->assertSee('Unclassified Office')
        ->assertDontSee('Quiet Foundation')
        ->call('toggleType', 'foundation')
        ->set('relationship', 'prospect')
        ->assertSee('Quiet Foundation')
        ->assertDontSee('Funding Foundation')
        ->set('relationship', 'funding')
        ->assertSee('Funding Foundation')
        ->assertDontSee('Quiet Foundation')
        ->call('toggleType', 'foundation')
        ->assertSet('type', '');
});

test('the share of wallet splits each currency by donor and never adds currencies together', function () {
    $user = User::factory()->role('Financial')->create();
    $echo = Donor::factory()->create(['name' => 'ECHO']);
    $giz = Donor::factory()->create(['name' => 'GIZ']);

    $agreement = DonorProposal::factory()->create(['donor_id' => $echo->id, 'proposal_id' => Proposal::factory()->create()->id, 'budget' => '300000.00', 'currency' => Currency::Usd]);
    DonorProposal::factory()->create(['donor_id' => $giz->id, 'proposal_id' => Proposal::factory()->create()->id, 'budget' => '100000.00', 'currency' => Currency::Usd]);
    DonorProposal::factory()->create(['donor_id' => $giz->id, 'proposal_id' => Proposal::factory()->create()->id, 'budget' => '50000.00', 'currency' => Currency::Eur]);
    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'ar_status' => ArStatus::Received,
        'net_amount_received' => '75000.00',
        'amount_received_currency' => Currency::Usd,
    ]);

    $wallet = Livewire::actingAs($user)->test('pages::donors.index')->instance()->wallet;

    expect($wallet)->toHaveCount(2)
        ->and($wallet[0]['currency'])->toBe('EUR')
        ->and($wallet[0]['committed'])->toBe(50000.0)
        ->and($wallet[1]['currency'])->toBe('USD')
        ->and($wallet[1]['committed'])->toBe(400000.0)
        ->and($wallet[1]['received'])->toBe(75000.0)
        ->and($wallet[1]['segments'][0])->toMatchArray(['name' => 'ECHO', 'share' => 75.0, 'received' => 75000.0])
        ->and($wallet[1]['segments'][1])->toMatchArray(['name' => 'GIZ', 'share' => 25.0]);
});

test('sorting by next transfer due puts the soonest scheduled donor first', function () {
    $user = User::factory()->role('Financial')->create();
    $later = Donor::factory()->create(['name' => 'Alpha Donor']);
    $sooner = Donor::factory()->create(['name' => 'Zulu Donor']);

    foreach ([[$later, now()->addMonths(3)], [$sooner, now()->addWeek()]] as [$donor, $date]) {
        TransfersTracking::factory()->create([
            'donor_proposal_id' => DonorProposal::factory()->create(['donor_id' => $donor->id, 'proposal_id' => Proposal::factory()->create()->id])->id,
            'ar_status' => ArStatus::Pending,
            'scheduled_date' => $date,
        ]);
    }

    Livewire::actingAs($user)
        ->test('pages::donors.index')
        ->assertSeeInOrder(['Alpha Donor', 'Zulu Donor'])
        ->set('sort', 'next_due')
        ->assertSeeInOrder(['Zulu Donor', 'Alpha Donor']);
});

test('the overview sizes itself to the funders on screen', function () {
    $user = User::factory()->role('Financial')->create();
    $donor = Donor::factory()->create(['type' => DonorType::Bilateral]);
    DonorProposal::factory()->create(['donor_id' => $donor->id, 'proposal_id' => Proposal::factory()->create()->id, 'currency' => Currency::Usd]);

    $component = Livewire::actingAs($user)->test('pages::donors.index');

    expect($component->instance()->overviewRows)->toBe(3);
    $component->assertSeeText('None yet: Foundation, Individual, Corporate, Not classified');
});

test('a donor opens from its eye button, not from a linked name', function () {
    $user = User::factory()->role('Financial')->create();
    $donor = Donor::factory()->create(['name' => 'UNICEF']);

    Livewire::actingAs($user)
        ->test('pages::donors.index')
        ->assertSeeHtml('aria-label="View UNICEF"')
        ->assertSeeHtml('href="'.route('donors.show', $donor).'"')
        ->assertDontSeeHtml('hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary text-secondary-dark dark:text-white">UNICEF</a>');
});

test('a pending tranche with no scheduled date does not break the transfers ahead', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create(['donor_id' => Donor::factory()->create()->id]);
    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'scheduled_date' => null,
        'ar_status' => ArStatus::Pending,
    ]);

    Livewire::actingAs($user)
        ->test('pages::donors.index')
        ->assertOk()
        ->assertSeeText(__('donors.no_transfer_scheduled_ahead'));
});

test('donors can be found by email', function () {
    $user = User::factory()->role('Financial')->create();
    Donor::factory()->create(['name' => 'Mail Found', 'email' => 'grants@found.org']);
    Donor::factory()->create(['name' => 'Other Funder', 'email' => 'x@other.org']);

    Livewire::actingAs($user)
        ->test('pages::donors.index')
        ->set('search', 'grants@')
        ->assertSee('Mail Found')
        ->assertDontSee('Other Funder');
});

test('a donor phone link dials digits only', function () {
    $user = User::factory()->role('Financial')->create();
    Donor::factory()->create(['phone' => '+961 (1) 234-567']);

    Livewire::actingAs($user)
        ->test('pages::donors.index')
        ->assertSeeHtml('href="tel:+9611234567"');
});

test('a donor country longer than its column is rejected, not sent to the database', function () {
    $user = User::factory()->role('Financial')->create();

    Livewire::actingAs($user)
        ->test('pages::donors.index')
        ->call('openCreateModal')
        ->set('name', 'Long Country')
        ->set('country', str_repeat('a', 101))
        ->call('saveDonor')
        ->assertHasErrors(['country' => 'max']);
});

test('the follow-up is recorded with the donor and needs a due date', function () {
    $user = User::factory()->role('Financial')->create();

    $component = Livewire::actingAs($user)
        ->test('pages::donors.index')
        ->call('openCreateModal')
        ->set('name', 'Prospect Foundation')
        ->set('nextStep', 'Send the concept note')
        ->call('saveDonor')
        ->assertHasErrors(['nextStepDueOn' => 'required_with'])
        ->set('lastContactedOn', now()->addDay()->toDateString())
        ->set('nextStepDueOn', '2026-10-01')
        ->call('saveDonor')
        ->assertHasErrors(['lastContactedOn' => 'before_or_equal']);

    $component->set('lastContactedOn', '2026-09-01')->call('saveDonor')->assertHasNoErrors();

    $donor = DB::table('donors')->where('name', 'Prospect Foundation')->first();
    expect($donor->next_step)->toBe('Send the concept note')
        ->and($donor->next_step_due_on)->toBe('2026-10-01')
        ->and($donor->last_contacted_on)->toBe('2026-09-01');
});

test('overdue follow-ups are counted and sort first', function () {
    $user = User::factory()->role('Financial')->create();
    Donor::factory()->create(['name' => 'Aardvark Fund']);
    Donor::factory()->create(['name' => 'Zebra Trust', 'next_step' => 'Call back', 'next_step_due_on' => now()->subDays(3)->toDateString()]);

    Livewire::actingAs($user)
        ->test('pages::donors.index')
        ->assertSeeHtml('data-follow-ups-due')
        ->assertSeeText('1 follow-up due')
        ->set('sort', 'follow_up')
        ->assertSeeInOrder(['Zebra Trust', 'Aardvark Fund']);
});

test('a donor can be edited from its own page and only that donor', function () {
    $user = User::factory()->role('Financial')->create();
    $donor = Donor::factory()->create(['name' => 'Before']);
    $other = Donor::factory()->create(['name' => 'Untouched']);

    $page = Livewire::actingAs($user)
        ->test('pages::donors.show', ['donor' => $donor])
        ->assertSeeHtml('data-test="edit-donor-button"')
        ->call('openEditModal', $other->id)
        ->assertSet('showDonorModal', true)
        ->assertSet('name', 'Before');

    expect(fn () => $page->set('editingDonorId', $other->id))->toThrow(CannotUpdateLockedPropertyException::class);

    $page->set('name', 'After')
        ->call('saveDonor')
        ->assertHasNoErrors()
        ->assertSet('showDonorModal', false)
        ->assertSeeText('After');

    expect(DB::table('donors')->find($donor->id)->name)->toBe('After')
        ->and(DB::table('donors')->find($other->id)->name)->toBe('Untouched');
});

test('donor detail lists each tranche in full and each report against its targets', function () {
    $user = User::factory()->role('Financial')->create();
    $donor = Donor::factory()->create();
    $proposal = Proposal::factory()->create();
    $agreement = DonorProposal::factory()->create(['donor_id' => $donor->id, 'proposal_id' => $proposal->id, 'currency' => Currency::Usd]);

    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '1000.00',
        'fees' => '12.50',
        'fees_currency' => Currency::Usd,
        'exchange_rate' => '0.925',
        'scheduled_date' => now()->subMonth()->toDateString(),
        'ar_status' => ArStatus::Failed,
    ]);

    $indicator = Indicator::factory()->create(['name' => 'Children enrolled']);
    $planIndicator = PlanIndicator::factory()->create(['source_type' => 'proposal', 'source_id' => $proposal->id, 'indicator_id' => $indicator->id]);
    $report = Report::factory()->create(['plan_indicator_id' => $planIndicator->id, 'user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::donors.show', ['donor' => $donor])
        ->assertSeeHtml('data-tranche-row="failed"')
        ->assertSeeHtml('data-point="failed"')
        ->assertSeeText('12.50 USD')
        ->assertSeeText('0.925')
        ->assertSeeText('Children enrolled')
        ->assertSeeHtml('href="'.route('reports.show', $report).'"');
});
