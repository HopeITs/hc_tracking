<?php

use App\Enums\Currency;
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
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from the donor directory', function () {
    $this->get(route('donors.index'))->assertRedirect(route('login'));
});

test('donors can be searched', function () {
    $user = User::factory()->create();
    Donor::factory()->create(['name' => 'UNICEF']);
    Donor::factory()->create(['name' => 'ECHO']);

    Livewire::actingAs($user)
        ->test('pages::donors.index')
        ->assertSee('UNICEF')
        ->assertSee('ECHO')
        ->set('search', 'UNI')
        ->assertSee('UNICEF')
        ->assertDontSee('ECHO');
});

test('a donor can be created and two donors may share a name', function () {
    $user = User::factory()->create();
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
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::donors.index')
        ->call('openCreateModal')
        ->set('name', '')
        ->call('saveDonor')
        ->assertHasErrors(['name']);
});

test('a donor with no agreements can be deleted', function () {
    $user = User::factory()->create();
    $donor = Donor::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::donors.index')
        ->call('confirmDelete', $donor->id)
        ->call('deleteDonor')
        ->assertHasNoErrors();

    expect(DB::table('donors')->find($donor->id))->toBeNull();
});

test('a donor holding an agreement is not deleted', function () {
    $user = User::factory()->create();
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
    $user = User::factory()->create();
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
    $user = User::factory()->create();
    $donor = Donor::factory()->create(['name' => 'Quiet Donor']);

    $this->actingAs($user)
        ->get(route('donors.show', $donor))
        ->assertOk()
        ->assertSeeText('Quiet Donor')
        ->assertSeeText('No agreements yet');
});

test('donor detail shows total disbursed and reports submitted separately from total committed', function () {
    $user = User::factory()->create();
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
        'net_amount_received' => '120000.00',
        'amount_received_currency' => Currency::Usd,
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
