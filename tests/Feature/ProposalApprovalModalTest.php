<?php

use App\Enums\AssignmentStatus;
use App\Enums\MediaCollection;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\Media;
use App\Models\Project;
use App\Models\Proposal;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(fn () => $this->travelTo('2026-10-14 10:00:00'));

/**
 * A submitted proposal planned 2026-11-01 to 2027-10-31, with one project
 * whose owner has acknowledged and one still waiting.
 *
 * @return array{proposal: Proposal, ready: Project, waiting: Project}
 */
function proposalReadyToApprove(): array
{
    $proposal = Proposal::factory()->create([
        'status' => ProposalStatus::Submitted,
        'planned_date_begin' => '2026-11-01',
        'planned_date_end' => '2027-10-31',
    ]);
    $ready = Project::factory()->create(['name' => 'Ready project']);
    $ready->currentAssignment('user_id')->forceFill(['status' => AssignmentStatus::Acknowledged])->save();
    $waiting = Project::factory()->create(['name' => 'Waiting project']);
    $proposal->projects()->attach([$ready->id => ['target_beneficiaries' => 10], $waiting->id => ['target_beneficiaries' => 10]]);

    return ['proposal' => $proposal, 'ready' => $ready, 'waiting' => $waiting];
}

function approveModal(string $role, Proposal $proposal): Testable
{
    return Livewire::actingAs(userWithRole($role))
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->call('openApproveModal', $proposal->id);
}

test('the button shows only while the proposal can be approved and only to proposal editors', function () {
    ['proposal' => $proposal] = proposalReadyToApprove();

    Livewire::actingAs(userWithRole('Financial'))->test('pages::proposals.show', ['proposal' => $proposal])->assertSeeHtml('data-test="open-approve-modal"');
    Livewire::actingAs(userWithRole('Team Member'))->test('pages::proposals.show', ['proposal' => $proposal])->assertDontSeeHtml('data-test="open-approve-modal"');

    $proposal->forceFill(['status' => ProposalStatus::ProposalDraft])->save();

    Livewire::actingAs(userWithRole('Financial'))->test('pages::proposals.show', ['proposal' => $proposal])->assertDontSeeHtml('data-test="open-approve-modal"');
});

test('the dialog pre-fills the planned dates and previews each project in English and Arabic', function (string $locale) {
    app()->setLocale($locale);
    ['proposal' => $proposal, 'ready' => $ready, 'waiting' => $waiting] = proposalReadyToApprove();

    $component = approveModal('Financial', $proposal)
        ->assertSet('showApproveModal', true)
        ->assertSet('approveBegin', '2026-11-01')
        ->assertSet('approveEnd', '2027-10-31')
        ->assertSet('approveDecisionDate', '2026-10-14')
        ->assertSee(__('proposals.grant_start_help'))
        ->assertSee(__('proposals.approval_outcome_funded'))
        ->assertSee(__('proposals.approval_outcome_waiting'));

    expect($component->instance()->approvalPreview)->toBe([
        $ready->id => ['name' => 'Ready project', 'outcome' => 'funded'],
        $waiting->id => ['name' => 'Waiting project', 'outcome' => 'waiting'],
    ]);
})->with(['en', 'ar']);

test('a planned start in the past moves to the decision date and keeps the planned length', function () {
    ['proposal' => $proposal] = proposalReadyToApprove();
    $proposal->forceFill(['planned_date_begin' => '2026-09-01', 'planned_date_end' => '2027-08-31'])->save();

    approveModal('Financial', $proposal)
        ->assertSet('approveBegin', '2026-10-14')
        ->assertSet('approveEnd', '2027-10-13');
});

test('approving with a note funds the ready project, leaves the waiting one a dated draft and closes the dialog', function () {
    ['proposal' => $proposal, 'ready' => $ready, 'waiting' => $waiting] = proposalReadyToApprove();

    approveModal('Financial', $proposal)
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

    approveModal('Financial', $proposal)
        ->set('approveDecisionDate', '2026-10-13')
        ->set('approveFile', validUpload('donor-email.pdf', 100))
        ->call('approveProposal')
        ->assertHasNoErrors();

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Approved)
        ->and(Media::query()->where('mediable_type', 'proposal')->where('mediable_id', $proposal->id)->sole()->collection)->toBe(MediaCollection::DonorApproval);
});

test('missing evidence is explained next to the field and nothing is written', function (string $mode, string $field) {
    ['proposal' => $proposal] = proposalReadyToApprove();

    approveModal('Financial', $proposal)
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

    $component = approveModal('Financial', $proposal);
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

    approveModal('Financial', $proposal)->assertSet('showApproveModal', false);
});

test('the preview names extended and skipped projects too', function () {
    ['proposal' => $proposal] = proposalReadyToApprove();
    $active = Project::factory()->active()->create(['name' => 'Running project']);
    $archived = Project::factory()->status(ProjectStatus::Archived)->create(['name' => 'Archived project']);
    $proposal->projects()->attach([$active->id => ['target_beneficiaries' => 10], $archived->id => ['target_beneficiaries' => 10]]);

    $outcomes = collect(approveModal('Financial', $proposal)->instance()->approvalPreview)->pluck('outcome', 'name');

    expect($outcomes['Running project'])->toBe('extended')
        ->and($outcomes['Archived project'])->toBe('skipped');
});
