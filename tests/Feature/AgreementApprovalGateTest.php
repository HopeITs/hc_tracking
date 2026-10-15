<?php

use App\Actions\FundingAgreements\SaveFundingAgreement;
use App\Enums\Currency;
use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Proposal;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function donorApprovedProposal(array $attributes = []): Proposal
{
    return Proposal::factory()->running()->create($attributes);
}

function recordAgreementOn(User $user, Proposal $proposal, string $budget = '1000.00', string $currency = 'USD')
{
    return Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->call('openAgreementCreate')
        ->set('agreementProposalId', (string) $proposal->id)
        ->set('budget', $budget)
        ->set('agreementCurrency', $currency)
        ->call('saveAgreement');
}

test('an agreement is recorded for a donor-approved proposal and takes its donor from it', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = donorApprovedProposal();

    recordAgreementOn($user, $proposal, '750000.00', 'EUR')->assertHasNoErrors();

    $agreement = DonorProposal::query()->sole();

    expect($agreement->proposal_id)->toBe($proposal->id)
        ->and($agreement->donor_id)->toBe($proposal->donor_id)
        ->and($agreement->currency)->toBe(Currency::Eur);
});

test('a proposal that no donor approved is refused with the translated message', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);

    recordAgreementOn($user, $proposal)
        ->assertHasErrors(['agreementProposalId'])
        ->assertSee(__('funding_agreements.proposal_not_donor_approved'));

    expect(DonorProposal::query()->count())->toBe(0);
});

test('a second agreement for the same proposal is refused with a friendly message', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = donorApprovedProposal();
    DonorProposal::factory()->create(['proposal_id' => $proposal->id]);

    recordAgreementOn($user, $proposal)
        ->assertHasErrors(['agreementProposalId'])
        ->assertSee(__('funding_agreements.agreement_exists'));

    expect(DonorProposal::query()->count())->toBe(1);
});

test('losing the race to the unique index gives the same friendly message', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = donorApprovedProposal();

    // A competing request inserts its agreement between this save's check and its insert.
    DonorProposal::creating(function () use ($proposal): void {
        DB::table('donor_proposal')->insert([
            'slug' => 'competing-agreement',
            'donor_id' => $proposal->donor_id,
            'proposal_id' => $proposal->id,
            'budget' => '1.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    recordAgreementOn($user, $proposal)
        ->assertHasErrors(['agreementProposalId'])
        ->assertSee(__('funding_agreements.agreement_exists'));

    // The competing row lived in the same rolled-back transaction: no agreement, no 500.
    expect(DonorProposal::query()->count())->toBe(0);
});

test('a forged donor id is ignored: the proposal decides', function () {
    $this->actingAs(User::factory()->role('Financial')->create());
    $proposal = donorApprovedProposal();
    $stranger = Donor::factory()->create();

    $agreement = app(SaveFundingAgreement::class)->handle(null, [
        'agreementDonorId' => (string) $stranger->id,
        'agreementProposalId' => (string) $proposal->id,
        'budget' => '10.00',
        'agreementCurrency' => 'USD',
        'externalProjectNumber' => '',
    ]);

    expect($agreement->donor_id)->toBe($proposal->donor_id);
});

test('an update never moves the agreement to another proposal or donor', function () {
    $this->actingAs(User::factory()->role('Financial')->create());
    $agreement = DonorProposal::factory()->create();
    $other = donorApprovedProposal();

    $saved = app(SaveFundingAgreement::class)->handle($agreement, [
        'agreementProposalId' => (string) $other->id,
        'budget' => '99.00',
        'agreementCurrency' => 'USD',
        'externalProjectNumber' => '',
    ]);

    expect($saved->proposal_id)->toBe($agreement->proposal_id)
        ->and($saved->donor_id)->toBe($agreement->donor_id)
        ->and((float) $saved->budget)->toBe(99.0);
});

test('a proposal page files the agreement for its own proposal whatever the payload says', function () {
    $user = User::factory()->role('Financial')->create();
    $own = donorApprovedProposal();
    $other = donorApprovedProposal();

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $own])
        ->call('openAgreementCreate')
        ->set('agreementProposalId', (string) $other->id)
        ->set('budget', '500')
        ->set('agreementCurrency', 'USD')
        ->call('saveAgreement')
        ->assertHasNoErrors();

    expect(DonorProposal::query()->sole()->proposal_id)->toBe($own->id);
});

test('a donor page refuses a proposal of another donor', function () {
    $user = User::factory()->role('Financial')->create();
    $donor = Donor::factory()->create();
    $foreign = donorApprovedProposal();

    Livewire::actingAs($user)
        ->test('pages::donors.show', ['donor' => $donor])
        ->call('openAgreementCreate')
        ->set('agreementProposalId', (string) $foreign->id)
        ->set('budget', '500')
        ->set('agreementCurrency', 'USD')
        ->call('saveAgreement')
        ->assertHasErrors(['agreementProposalId']);

    expect(DonorProposal::query()->count())->toBe(0);
});

test('the picker lists only eligible proposals and carries no money', function () {
    $user = User::factory()->role('Financial')->create();
    $eligible = donorApprovedProposal(['name' => 'Eligible One', 'requested_budget' => '4321.00']);
    DonorProposal::factory()->create(['proposal_id' => donorApprovedProposal(['name' => 'Already Funded'])->id]);
    Proposal::factory()->create(['name' => 'Still A Concept']);

    $options = Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->call('openAgreementCreate')
        ->instance()
        ->proposalOptions;

    expect($options->pluck('id')->all())->toBe([$eligible->id])
        ->and($options->first()->relationLoaded('donor'))->toBeTrue()
        ->and(array_keys($options->first()->getAttributes()))->not->toContain('requested_budget');
});

test('a user without funding-agreements.create cannot open or save the form', function () {
    $viewer = User::factory()->role('Managers')->create()->givePermissionTo('funding-agreements.view');
    $proposal = donorApprovedProposal();

    $fresh = fn () => Livewire::actingAs($viewer)->test('pages::funding-agreements.index');

    $fresh()->call('openAgreementCreate')->assertForbidden();
    $fresh()->set('agreementProposalId', (string) $proposal->id)->set('budget', '10')->call('saveAgreement')->assertForbidden();

    expect(DonorProposal::query()->count())->toBe(0);
});

test('a legacy agreement on a non-approved proposal can still be edited and shows the inconsistency warning', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create(['budget' => '100.00']);

    expect($agreement->proposal->status->isApprovedByDonor())->toBeFalse();

    Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->call('openAgreementEdit', $agreement->id)
        ->assertSeeHtml('data-agreement-proposal-warning')
        ->assertSee(__('funding_agreements.proposal_status_inconsistent_with_agreement'))
        ->set('budget', '250.00')
        ->call('saveAgreement')
        ->assertHasNoErrors();

    expect((float) $agreement->fresh()->budget)->toBe(250.0);
});

test('choosing a proposal pre-fills the requested budget and currency', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = donorApprovedProposal(['requested_budget' => '12000.50', 'requested_currency' => Currency::Eur]);

    Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->call('openAgreementCreate')
        ->set('agreementProposalId', (string) $proposal->id)
        ->assertSet('budget', '12000.50')
        ->assertSet('agreementCurrency', 'EUR');
});

test('the Public Relations Manager can record and edit an agreement but not delete one', function () {
    $manager = userWithRole('Public Relations Manager');
    $proposal = donorApprovedProposal();

    recordAgreementOn($manager, $proposal)->assertHasNoErrors();
    $agreement = DonorProposal::query()->sole();

    Livewire::actingAs($manager)
        ->test('pages::funding-agreements.index')
        ->call('openAgreementEdit', $agreement->id)
        ->set('budget', '2000.00')
        ->call('saveAgreement')
        ->assertHasNoErrors();

    Livewire::actingAs($manager)
        ->test('pages::funding-agreements.index')
        ->call('openAgreementEdit', $agreement->id)
        ->call('deleteAgreement')
        ->assertForbidden();

    expect((float) $agreement->fresh()->budget)->toBe(2000.0);
});

test('the Record Agreement button shows only for a donor-approved proposal without one, to a holder of create', function () {
    $proposal = donorApprovedProposal();
    $page = fn (User $user) => Livewire::actingAs($user)->test('pages::proposals.show', ['proposal' => $proposal]);

    $page(User::factory()->role('Financial')->create())->assertSeeHtml('data-test="record-agreement-button"');
    $page(User::factory()->role('Media Team')->create())->assertDontSeeHtml('data-test="record-agreement-button"');

    DonorProposal::factory()->create(['proposal_id' => $proposal->id]);
    $page(User::factory()->role('Financial')->create())->assertDontSeeHtml('data-test="record-agreement-button"');

    $concept = Proposal::factory()->create();
    Livewire::actingAs(User::factory()->role('Financial')->create())->test('pages::proposals.show', ['proposal' => $concept])
        ->assertDontSeeHtml('data-test="record-agreement-button"');
});

test('the notification deep link opens the agreement form once, pre-filled, and only when it applies', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = donorApprovedProposal(['requested_budget' => '800.00', 'requested_currency' => Currency::Eur]);

    Livewire::withQueryParams(['record_agreement' => '1'])->actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->assertSet('showAgreementModal', true)
        ->assertSet('budget', '800.00')
        ->assertSet('agreementCurrency', 'EUR')
        ->assertSet('recordAgreementLink', false);

    DonorProposal::factory()->create(['proposal_id' => $proposal->id]);

    Livewire::withQueryParams(['record_agreement' => '1'])->actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->assertSet('showAgreementModal', false);
});

test('a proposal moved away from approved after its agreement was recorded warns on both pages', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create();

    Livewire::actingAs($user)->test('pages::proposals.show', ['proposal' => $agreement->proposal])
        ->assertSee(__('funding_agreements.proposal_status_inconsistent_with_agreement'));

    Livewire::actingAs($user)->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->assertSee(__('funding_agreements.proposal_status_inconsistent_with_agreement'));
});
