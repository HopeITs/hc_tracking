<?php

use App\Actions\Donors\DeleteDonor;
use App\Actions\Proposals\SaveProposal;
use App\Models\Activity;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\TransfersTracking;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Valid create data for SaveProposal: one back donor and one project.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function partnerProposalData(array $overrides = []): array
{
    return [
        'name' => 'Winter Shelter Response',
        'donorId' => Donor::factory()->create(['name' => 'GIZ'])->id,
        'locations' => [placePayload()],
        'beneficiaries' => [],
        'plannedDateBegin' => '2026-11-01',
        'plannedDateEnd' => '2027-10-31',
        'notes' => '',
        'startingStage' => 'concept note',
        'skipReason' => null,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $data
 */
function savePartnerProposal(User $actor, ?Proposal $existing, array $data): Proposal
{
    test()->actingAs($actor);

    return app(SaveProposal::class)->handle($existing, $data, $actor);
}

test('PR records the Main Partner and the other Partners on a proposal', function () {
    $mainPartner = Donor::factory()->create(['name' => 'Syriac Cross']);
    $partners = Donor::factory()->count(2)->create();

    $proposal = savePartnerProposal(userWithRole('Public Relations'), null, partnerProposalData([
        'mainPartnerId' => $mainPartner->id,
        'partnerIds' => $partners->pluck('id')->all(),
    ]));

    expect($proposal->main_partner_id)->toBe($mainPartner->id)
        ->and($proposal->partners->pluck('id')->sort()->values()->all())->toBe($partners->pluck('id')->sort()->values()->all())
        ->and($mainPartner->mainPartnerProposals()->pluck('id')->all())->toBe([$proposal->id]);
});

test('a user outside PR cannot set a Main Partner and the request is ignored', function () {
    $mainPartner = Donor::factory()->create();

    $proposal = savePartnerProposal(userWithRole('Financial'), null, partnerProposalData(['mainPartnerId' => $mainPartner->id]));

    expect($proposal->main_partner_id)->toBeNull()
        ->and($proposal->partners)->toBeEmpty();
});

test('the Main Partner and the Partners are refused when they break the rules', function (Closure $overrides, string $field) {
    $actor = userWithRole('Public Relations');
    $data = partnerProposalData();
    $other = Donor::factory()->create();

    try {
        savePartnerProposal($actor, null, [...$data, ...$overrides($data, $other)]);
        $this->fail('The save was not refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }

    expect(Proposal::query()->count())->toBe(0);
})->with([
    'main partner is the back donor' => [fn (array $data) => ['mainPartnerId' => $data['donorId']], 'mainPartnerId'],
    'partners without a main partner' => [fn (array $data, Donor $other) => ['partnerIds' => [$other->id]], 'partnerIds'],
    'partner is the main partner' => [fn (array $data, Donor $other) => ['mainPartnerId' => $other->id, 'partnerIds' => [$other->id]], 'partnerIds'],
    'partner listed twice' => [fn (array $data, Donor $other) => ['mainPartnerId' => Donor::factory()->create()->id, 'partnerIds' => [$other->id, $other->id]], 'partnerIds.0'],
]);

test('clearing the Main Partner on edit clears the Partners too', function () {
    $actor = userWithRole('Public Relations');
    $proposal = savePartnerProposal($actor, null, partnerProposalData([
        'mainPartnerId' => Donor::factory()->create()->id,
        'partnerIds' => [Donor::factory()->create()->id],
    ]));

    $edited = savePartnerProposal($actor, $proposal, [
        'name' => $proposal->name,
        'donorId' => $proposal->donor_id,
        'plannedDateBegin' => $proposal->planned_date_begin->toDateString(),
        'plannedDateEnd' => $proposal->planned_date_end->toDateString(),
        'notes' => '',
        'mainPartnerId' => null,
        'partnerIds' => [],
    ]);

    expect($edited->main_partner_id)->toBeNull()
        ->and($edited->partners()->count())->toBe(0);
});

test('an edit that does not send partners leaves them as they are', function () {
    $actor = userWithRole('Public Relations');
    $mainPartner = Donor::factory()->create();
    $proposal = savePartnerProposal($actor, null, partnerProposalData(['mainPartnerId' => $mainPartner->id]));

    $edited = savePartnerProposal($actor, $proposal, [
        'name' => 'Renamed',
        'donorId' => $proposal->donor_id,
        'plannedDateBegin' => $proposal->planned_date_begin->toDateString(),
        'plannedDateEnd' => $proposal->planned_date_end->toDateString(),
        'notes' => '',
    ]);

    expect($edited->name)->toBe('Renamed')
        ->and($edited->main_partner_id)->toBe($mainPartner->id);
});

test('the form shows the Main Partner pickers only after the box is ticked and only to PR', function () {
    $component = Livewire::actingAs(userWithRole('Public Relations'))->test('pages::proposals.index')
        ->call('openCreateModal')
        ->assertSeeHtml('data-test="has-main-partner"')
        ->assertDontSeeHtml('id="proposal-main-partner"')
        ->set('hasMainPartner', true)
        ->assertSeeHtml('id="proposal-main-partner"')
        ->assertSeeHtml('data-test="partner-picker"');

    expect($component->get('hasMainPartner'))->toBeTrue();

    Livewire::actingAs(userWithRole('Financial'))->test('pages::proposals.index')
        ->call('openCreateModal')
        ->assertDontSeeHtml('data-test="has-main-partner"');
});

test('the form saves a Main Partner picked in the library', function () {
    $mainPartner = Donor::factory()->create(['name' => 'Syriac Cross']);
    $donor = Donor::factory()->create(['name' => 'GIZ']);

    Livewire::actingAs(userWithRole('Public Relations'))->test('pages::proposals.index')
        ->call('openCreateModal')
        ->set('hasMainPartner', true)
        ->set('mainPartnerId', (string) $mainPartner->id)
        ->set('donorId', (string) $donor->id)
        ->call('saveProposal')
        ->assertSet('mainPartnerId', (string) $mainPartner->id);
});

test('the donor page lists the proposals where it is Main Partner or Partner', function () {
    $organisation = Donor::factory()->create(['name' => 'Syriac Cross']);
    $asMain = Proposal::factory()->create(['name' => 'Routed Project', 'main_partner_id' => $organisation->id]);
    $asPartner = Proposal::factory()->create(['name' => 'Listed Project']);
    $asPartner->partners()->attach($organisation->id);
    Proposal::factory()->create(['name' => 'Unrelated Project']);

    Livewire::actingAs(userWithRole('Public Relations'))->test('pages::donors.show', ['donor' => $organisation])
        ->assertSeeHtml('data-partner-roles')
        ->assertSee('Routed Project')
        ->assertSee('Listed Project')
        ->assertDontSee('Unrelated Project');
});

test('the register can be narrowed to proposals routed through a Main Partner', function () {
    $routed = Proposal::factory()->create(['name' => 'Routed Project', 'main_partner_id' => Donor::factory()->create(['name' => 'Syriac Cross'])->id]);
    Proposal::factory()->create(['name' => 'Direct Project']);

    Livewire::actingAs(userWithRole('Public Relations'))->test('pages::proposals.index')
        ->set('flag', 'via_partner')
        ->assertSee('Routed Project')
        ->assertDontSee('Direct Project')
        ->assertSee('Syriac Cross');

    expect($routed->main_partner_id)->not->toBeNull();
});

test('a donor named as Main Partner or Partner cannot be deleted', function () {
    $organisation = Donor::factory()->create();
    Proposal::factory()->create(['main_partner_id' => $organisation->id]);

    $this->actingAs(userWithRole('Financial'));

    expect(app(DeleteDonor::class)->handle($organisation))->toBeFalse()
        ->and(Donor::query()->whereKey($organisation->id)->exists())->toBeTrue();
});

test('a donor added from the Main Partner button fills the Main Partner field, from the Partner button the Partners', function () {
    $actor = userWithRole('Public Relations Manager');
    $main = Donor::factory()->create();
    $partner = Donor::factory()->create();

    Livewire::actingAs($actor)->test('pages::proposals.index')
        ->call('openCreateModal')
        ->dispatch('donor-requested', donorId: $main->id, target: 'main')
        ->assertSet('hasMainPartner', true)
        ->assertSet('mainPartnerId', (string) $main->id)
        ->dispatch('donor-requested', donorId: $partner->id, target: 'partner')
        ->assertSet('partnerIds', [(string) $partner->id])
        ->assertSet('donorId', '');
});

test('only a manager of partners may fill the partner fields from the add-donor flow', function () {
    $donor = Donor::factory()->create();

    Livewire::actingAs(userWithRole('Financial'))->test('pages::proposals.index')
        ->call('openCreateModal')
        ->dispatch('donor-requested', donorId: $donor->id, target: 'main')
        ->assertForbidden();
});

test('the add-donor buttons appear beside the Main Partner and Partner pickers', function () {
    Livewire::actingAs(userWithRole('Public Relations Manager'))->test('pages::proposals.index')
        ->call('openCreateModal')
        ->set('hasMainPartner', true)
        ->assertSeeHtml('data-test="add-main-partner-button"')
        ->assertSeeHtml('data-test="add-partner-button"');
});

test('the filter line counts the distinct Main Partners and the proposals routed through them', function () {
    $syriacCross = Donor::factory()->create();
    Proposal::factory()->count(2)->create(['main_partner_id' => $syriacCross->id]);
    Proposal::factory()->create(['main_partner_id' => Donor::factory()->create()->id]);
    Proposal::factory()->create();

    $component = Livewire::actingAs(userWithRole('Public Relations'))->test('pages::proposals.index')
        ->set('flag', 'via_partner');

    expect($component->instance()->mainPartnerCount)->toBe(2)
        ->and($component->instance()->viaPartnerCount)->toBe(3);
    $component->assertSeeHtml('data-main-partner-summary');
});

test('the agreement page says the agreement, transfers and reports go through the Main Partner', function () {
    $mainPartner = Donor::factory()->create(['name' => 'Syriac Cross']);
    $agreement = DonorProposal::factory()->create();
    $agreement->proposal->update(['main_partner_id' => $mainPartner->id]);

    Livewire::actingAs(userWithRole('Financial'))->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->assertSeeHtml('data-main-partner-note="agreement"')
        ->assertSeeHtml('data-main-partner-note="transfers"')
        ->assertSeeHtml('data-main-partner-note="reports"')
        ->assertSee('Syriac Cross');
});

test('a direct grant shows no Main Partner note on the agreement page', function () {
    $agreement = DonorProposal::factory()->create();

    Livewire::actingAs(userWithRole('Financial'))->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->assertDontSeeHtml('data-main-partner-note');
});

test('the agreements and transfers lists show which Main Partner each line goes through', function () {
    $mainPartner = Donor::factory()->create(['name' => 'Syriac Cross']);
    $agreement = DonorProposal::factory()->create();
    $agreement->proposal->update(['main_partner_id' => $mainPartner->id]);
    TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id]);

    Livewire::actingAs(userWithRole('Financial'))->test('pages::funding-agreements.index')
        ->assertSeeHtml('data-main-partner-line')
        ->assertSee('Syriac Cross');

    Livewire::actingAs(userWithRole('Financial'))->test('pages::transfers.index')
        ->assertSeeHtml('data-main-partner-line')
        ->assertSee('Syriac Cross');
});

test('the proposal page card and funding tab name the Main Partner and the Partners', function () {
    $mainPartner = Donor::factory()->create(['name' => 'Syriac Cross']);
    $partner = Donor::factory()->create(['name' => 'Caritas']);
    $agreement = DonorProposal::factory()->create();
    $proposal = $agreement->proposal;
    $proposal->update(['main_partner_id' => $mainPartner->id]);
    $proposal->partners()->attach($partner->id);

    Livewire::actingAs(userWithRole('Public Relations Manager'))->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'funding')
        ->assertSeeHtml('data-main-partner-card')
        ->assertSeeHtml('data-partners-line')
        ->assertSee('Caritas')
        ->assertSeeHtml('data-main-partner-note="all"');
});

test('the donor report page says the report is sent to the Main Partner', function () {
    $mainPartner = Donor::factory()->create(['name' => 'Syriac Cross']);
    $agreement = DonorProposal::factory()->create();
    $agreement->proposal->update(['main_partner_id' => $mainPartner->id]);
    $report = DonorReport::factory()->create(['donor_proposal_id' => $agreement->id]);

    Livewire::actingAs(userWithRole('General Manager'))->test('pages::donor-reports.show', ['donorReport' => $report])
        ->assertSeeHtml('data-main-partner-note="reports"')
        ->assertSee('Syriac Cross');
});

test('only the PR roles manage partners, not the General Manager or the Financial role', function () {
    expect(userWithRole('Public Relations')->can('proposals.manage-partners'))->toBeTrue()
        ->and(userWithRole('Public Relations Manager')->can('proposals.manage-partners'))->toBeTrue()
        ->and(userWithRole('General Manager')->can('proposals.manage-partners'))->toBeFalse()
        ->and(userWithRole('Financial')->can('proposals.manage-partners'))->toBeFalse();
});

test('changing the Main Partner or the Partners is written to History by name', function () {
    $actor = userWithRole('Public Relations');
    $mainPartner = Donor::factory()->create(['name' => 'Syriac Cross']);
    $partner = Donor::factory()->create(['name' => 'Caritas']);
    $proposal = savePartnerProposal($actor, null, partnerProposalData());

    savePartnerProposal($actor, $proposal, [
        'name' => $proposal->name,
        'donorId' => $proposal->donor_id,
        'plannedDateBegin' => $proposal->planned_date_begin->toDateString(),
        'plannedDateEnd' => $proposal->planned_date_end->toDateString(),
        'notes' => '',
        'mainPartnerId' => $mainPartner->id,
        'partnerIds' => [$partner->id],
    ]);

    $events = Activity::query()->where('subject_id', $proposal->id)->get();

    expect($events->pluck('event')->all())->toContain('proposal.updated');
    expect(json_encode($events->pluck('changes')->all()))->toContain('Caritas');
});
