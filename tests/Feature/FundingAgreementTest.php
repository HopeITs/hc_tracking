<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\Account;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Proposal;
use App\Models\TransfersTracking;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('guests are redirected from funding agreements', function () {
    $this->get(route('funding-agreements.index'))->assertRedirect(route('login'));
});

test('agreements can be filtered by donor and currency', function () {
    $user = User::factory()->role('Financial')->create();
    $unicef = Donor::factory()->create(['name' => 'UNICEF']);
    $echo = Donor::factory()->create(['name' => 'ECHO']);

    DonorProposal::factory()->create([
        'donor_id' => $unicef->id,
        'proposal_id' => Proposal::factory()->create(['name' => 'Dollar Proposal'])->id,
        'currency' => Currency::Usd,
    ]);
    DonorProposal::factory()->create([
        'donor_id' => $echo->id,
        'proposal_id' => Proposal::factory()->create(['name' => 'Euro Proposal'])->id,
        'currency' => Currency::Eur,
    ]);

    Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->assertSee('Dollar Proposal')
        ->assertSee('Euro Proposal')
        ->set('donor', (string) $unicef->id)
        ->assertSee('Dollar Proposal')
        ->assertDontSee('Euro Proposal')
        ->call('clearFilters')
        ->set('currency', Currency::Eur->value)
        ->assertDontSee('Dollar Proposal')
        ->assertSee('Euro Proposal');
});

test('an agreement can be created with a budget and currency', function () {
    $user = User::factory()->role('Financial')->create();
    $donor = Donor::factory()->create();
    $proposal = Proposal::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->call('openCreateModal')
        ->set('agreementDonorId', (string) $donor->id)
        ->set('agreementProposalId', (string) $proposal->id)
        ->set('budget', '750000.00')
        ->set('agreementCurrency', Currency::Usd->value)
        ->set('projectNum', 'SYR-2026-014')
        ->call('saveAgreement')
        ->assertHasNoErrors();

    $agreement = DonorProposal::query()->where('project_num', 'SYR-2026-014')->sole();

    expect($agreement->currency)->toBe(Currency::Usd)
        ->and((float) $agreement->budget)->toBe(750000.0);
});

test('a negative budget is rejected', function () {
    $user = User::factory()->role('Financial')->create();

    Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->call('openCreateModal')
        ->set('agreementDonorId', (string) Donor::factory()->create()->id)
        ->set('agreementProposalId', (string) Proposal::factory()->create()->id)
        ->set('budget', '-1')
        ->set('agreementCurrency', Currency::Usd->value)
        ->call('saveAgreement')
        ->assertHasErrors(['budget']);
});

test('funding agreements offer no delete because transfers would cascade', function () {
    $user = User::factory()->role('Financial')->create();
    DonorProposal::factory()->create();

    $component = Livewire::actingAs($user)->test('pages::funding-agreements.index');

    $component->assertDontSee('wire:click="confirmDelete')->assertDontSee('Delete agreement');

    expect(method_exists($component->instance(), 'deleteAgreement'))->toBeFalse();
});

test('agreement detail lists its tranches and totals received per currency', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create([
        'budget' => '500000.00',
        'currency' => Currency::Usd,
    ]);
    $account = Account::factory()->create(['name' => 'Operations USD']);

    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'account_id' => $account->id,
        'payment_amount' => '250000.00',
        'amount_transfered_currency' => Currency::Usd,
        'net_amount_received' => '248000.00',
        'amount_received_currency' => Currency::Usd,
        'ar_status' => ArStatus::Received,
    ]);

    $this->actingAs($user)
        ->get(route('funding-agreements.show', $agreement))
        ->assertOk()
        ->assertSeeText('250,000.00')
        ->assertSeeText('248,000.00')
        ->assertSeeText('Operations USD');
});

test('agreement detail renders an agreement with no tranches', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create();

    $this->actingAs($user)
        ->get(route('funding-agreements.show', $agreement))
        ->assertOk()
        ->assertSeeText('No tranches scheduled');
});

test('a user without funding agreement permissions cannot record one', function () {
    $viewer = User::factory()->role('Managers')->create();

    $fresh = fn () => Livewire::actingAs($viewer)->test('pages::funding-agreements.index');

    $fresh()->assertDontSeeHtml('data-test="create-agreement-button"');
    $fresh()->call('openCreateModal')->assertForbidden();
    $fresh()->set('agreementDonorId', (string) Donor::factory()->create()->id)
        ->set('agreementProposalId', (string) Proposal::factory()->create()->id)
        ->set('budget', '100')
        ->set('agreementCurrency', 'USD')
        ->call('saveAgreement')->assertForbidden();

    expect(DonorProposal::query()->count())->toBe(0);
});
