<?php

use App\Enums\AssignmentStatus;
use App\Enums\MediaCollection;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\Media;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(fn () => $this->travelTo('2026-10-14 10:00:00'));

/**
 * A submitted proposal planned 2026-11-01 to 2027-10-31, with two places of
 * its own: one whose responsible has acknowledged and one still waiting.
 *
 * Y-17: a place IS a location row of the proposal (D-93), so there is no
 * project anywhere in this fixture.
 *
 * @return array{proposal: Proposal, ready: ProposalLocation, waiting: ProposalLocation}
 */
function proposalReadyToApprove(): array
{
    $proposal = Proposal::factory()->create([
        'status' => ProposalStatus::Submitted,
        'planned_date_begin' => '2026-11-01',
        'planned_date_end' => '2027-10-31',
    ]);

    $place = function (string $label) use ($proposal): ProposalLocation {
        $cityId = insertHrCity($label, 'مكان');
        $employeeId = insertHrEmployee($cityId);
        $row = ProposalLocation::factory()->create([
            'proposal_id' => $proposal->id,
            'city_id' => $cityId,
            'program_id' => Program::factory()->create(['manager_id' => $employeeId])->id,
            'responsible_employee_id' => $employeeId,
            'target_beneficiaries' => 10,
        ]);
        $row->centers()->create(['center_id' => insertHrCenter($cityId, 'C'.Str::random(6))]);

        return $row->fresh();
    };

    $ready = $place('Ready place');
    $ready->currentAssignment('responsible_employee_id')->forceFill(['status' => AssignmentStatus::Acknowledged])->save();
    $waiting = $place('Waiting place');

    return ['proposal' => $proposal, 'ready' => $ready, 'waiting' => $waiting];
}

function approveModal(string $role, Proposal $proposal): Testable
{
    return Livewire::actingAs(userWithRole($role))
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->call('openApproveModal', $proposal->id);
}

test('the button shows only while the proposal can be approved and only to the Public Relations Manager (D-70)', function () {
    ['proposal' => $proposal] = proposalReadyToApprove();

    foreach (['Financial', 'General Manager', 'Managers'] as $role) {
        Livewire::actingAs(userWithRole($role))->test('pages::proposals.show', ['proposal' => $proposal])->assertDontSeeHtml('data-test="open-approve-modal"');
    }

    Livewire::actingAs(userWithRole('Financial'))->test('pages::proposals.show', ['proposal' => $proposal])->call('openApproveModal', $proposal->id)->assertForbidden();

    Livewire::actingAs(userWithRole('Public Relations Manager'))->test('pages::proposals.show', ['proposal' => $proposal])->assertSeeHtml('data-test="open-approve-modal"');
    Livewire::actingAs(userWithRole('Team Member'))->test('pages::proposals.show', ['proposal' => $proposal])->assertDontSeeHtml('data-test="open-approve-modal"');

    $proposal->forceFill(['status' => ProposalStatus::ProposalDraft])->save();

    Livewire::actingAs(userWithRole('Public Relations Manager'))->test('pages::proposals.show', ['proposal' => $proposal])->assertDontSeeHtml('data-test="open-approve-modal"');
});

test('the dialog pre-fills the planned dates and previews each project in English and Arabic', function (string $locale) {
    app()->setLocale($locale);
    ['proposal' => $proposal, 'ready' => $ready, 'waiting' => $waiting] = proposalReadyToApprove();

    $component = approveModal('Public Relations Manager', $proposal)
        ->assertSet('showApproveModal', true)
        ->assertSet('approveBegin', '2026-11-01')
        ->assertSet('approveEnd', '2027-10-31')
        ->assertSet('approveDecisionDate', '2026-10-14')
        ->assertSee(__('proposals.grant_start_help'))
        ->assertSee(__('proposals.approval_outcome_funded'))
        ->assertSee(__('proposals.approval_outcome_waiting'));

    expect($component->instance()->approvalPreview)->toBe([
        $ready->id => ['name' => $ready->label(), 'outcome' => 'funded'],
        $waiting->id => ['name' => $waiting->label(), 'outcome' => 'waiting'],
    ]);
})->with(['en', 'ar']);

test('a planned start in the past moves to the decision date and keeps the planned length', function () {
    ['proposal' => $proposal] = proposalReadyToApprove();
    $proposal->forceFill(['planned_date_begin' => '2026-09-01', 'planned_date_end' => '2027-08-31'])->save();

    approveModal('Public Relations Manager', $proposal)
        ->assertSet('approveBegin', '2026-10-14')
        ->assertSet('approveEnd', '2027-10-13');
});

test('approving with a note funds the ready project, leaves the waiting one a dated draft and closes the dialog', function () {
    ['proposal' => $proposal, 'ready' => $ready, 'waiting' => $waiting] = proposalReadyToApprove();

    approveModal('Public Relations Manager', $proposal)
        ->set('approveDecisionDate', '2026-10-13')
        ->set('approveEvidenceMode', 'note')
        ->set('approveEvidenceNote', 'The donor confirmed by email on 12 October.')
        ->call('approveProposal')
        ->assertHasNoErrors()
        ->assertSet('showApproveModal', false);

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Approved)
        ->and($ready->fresh()->status)->toBe(ProjectStatus::Approved)
        ->and($waiting->fresh()->status)->toBe(ProjectStatus::Draft)
        ->and($waiting->fresh()->start_date->toDateString())->toBe('2026-11-01');
});

test('a donor approval file is stored in its own category and counts as the evidence', function () {
    Storage::fake('local');
    ['proposal' => $proposal] = proposalReadyToApprove();

    approveModal('Public Relations Manager', $proposal)
        ->set('approveDecisionDate', '2026-10-13')
        ->set('approveFile', validUpload('donor-email.pdf', 100))
        ->call('approveProposal')
        ->assertHasNoErrors();

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Approved)
        ->and(Media::query()->where('mediable_type', 'proposal')->where('mediable_id', $proposal->id)->sole()->collection)->toBe(MediaCollection::DonorApproval);
});

test('missing evidence is explained next to the field and nothing is written', function (string $mode, string $field) {
    ['proposal' => $proposal] = proposalReadyToApprove();

    approveModal('Public Relations Manager', $proposal)
        ->set('approveDecisionDate', '2026-10-13')
        ->set('approveEvidenceMode', $mode)
        ->call('approveProposal')
        ->assertHasErrors([$field])
        ->assertSet('showApproveModal', true);

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Submitted)
        ->and(Media::query()->count())->toBe(0);
})->with([
    'file mode' => ['file', 'approveFile'],
    'note mode' => ['note', 'approveEvidenceNote'],
]);

test('a proposal approved elsewhere meanwhile shows the explanation and a reload button', function () {
    ['proposal' => $proposal] = proposalReadyToApprove();

    $component = approveModal('Public Relations Manager', $proposal);
    $proposal->forceFill(['status' => ProposalStatus::Approved])->save();

    $component->set('approveDecisionDate', '2026-10-13')
        ->set('approveEvidenceMode', 'note')
        ->set('approveEvidenceNote', 'The donor confirmed by email.')
        ->call('approveProposal')
        ->assertHasErrors(['approveGate'])
        ->assertSee(__('proposals.already_approved'))
        ->assertSeeHtml('data-test="approve-reload-button"');
});

test('someone without proposals.update cannot open the dialog', function () {
    ['proposal' => $proposal] = proposalReadyToApprove();

    Livewire::actingAs(userWithRole('Team Member'))->test('pages::proposals.show', ['proposal' => $proposal])->call('openApproveModal', $proposal->id)->assertForbidden();

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Submitted);
});

test('a proposal that cannot be approved does not open the dialog', function () {
    ['proposal' => $proposal] = proposalReadyToApprove();
    $proposal->forceFill(['status' => ProposalStatus::ProposalDraft])->save();

    approveModal('Public Relations Manager', $proposal)->assertSet('showApproveModal', false);
});

test('the preview names extended and skipped places too', function () {
    ['proposal' => $proposal] = proposalReadyToApprove();

    $place = function (ProjectStatus $status) use ($proposal): ProposalLocation {
        $cityId = insertHrCity('Place '.Str::random(5), 'مكان');
        $row = ProposalLocation::factory()->complete()->create([
            'proposal_id' => $proposal->id,
            'city_id' => $cityId,
            'status' => $status,
            'start_date' => '2026-11-01',
            'end_date' => '2027-10-31',
        ]);
        $row->centers()->create(['center_id' => insertHrCenter($cityId, 'C'.Str::random(6))]);

        return $row->fresh();
    };

    $active = $place(ProjectStatus::Active);
    $archived = $place(ProjectStatus::Archived);

    $outcomes = collect(approveModal('Public Relations Manager', $proposal)->instance()->approvalPreview)->pluck('outcome', 'name');

    expect($outcomes[$active->label()])->toBe('extended')
        ->and($outcomes[$archived->label()])->toBe('skipped');
});

test('the dialog counts the places approved, waiting for their responsible and waiting for a program', function () {
    // Y-17: approval creates and links nothing, so the preview counts
    // places, and there is no linked-existing warning to show.
    ['proposal' => $proposal] = proposalReadyToApprove();
    ProposalLocation::factory()->isNew()->create(['proposal_id' => $proposal->id, 'city_id' => insertHrCity('Homs', 'حمص'), 'sort' => 2]);

    $component = approveModal('Public Relations Manager', $proposal)
        ->assertSeeHtml('data-test="approve-locations-preview"')
        ->assertSeeHtml('data-test="approve-locations-approved"')
        ->assertSeeHtml('data-test="approve-locations-waiting"')
        ->assertDontSeeHtml('data-test="approve-locations-warning-location_linked_existing"');

    expect($component->instance()->approvalLocations)->toMatchArray(['approved' => 1, 'waiting' => 1, 'wait' => 1, 'warnings' => []]);
});
