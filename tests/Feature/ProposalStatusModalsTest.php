<?php

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Open the Change Status modal on the proposal page as the given role.
 */
function statusModal(string $role, Proposal $proposal): Testable
{
    return Livewire::actingAs(userWithRole($role))
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->call('openStatusModal', $proposal->id);
}

test('the modal offers only the targets of the transition table, in English and Arabic', function (string $locale) {
    app()->setLocale($locale);
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);

    $component = statusModal('Financial', $proposal);

    expect(array_map(fn (ProposalStatus $status): string => $status->value, $component->instance()->statusTargets))
        ->toBe(['proposal draft', 'on hold', 'rejected']);

    $component->assertSee(ProposalStatus::OnHold->label())
        ->assertSee(ProposalStatus::Rejected->label())
        ->assertDontSeeHtml('value="approved"')
        ->assertSee(__('proposals.approval_has_its_own_step'));
})->with(['en', 'ar']);

test('only the fields of the chosen target appear', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);

    statusModal('Financial', $proposal)
        ->assertDontSeeHtml('wire:model="statusContext"')
        ->set('newStatus', ProposalStatus::ProposalDraft->value)
        ->assertSeeHtml('wire:model="statusContext"')
        ->assertDontSeeHtml('wire:model="statusRemindOn"')
        ->set('newStatus', ProposalStatus::OnHold->value)
        ->assertSeeHtml('wire:model="statusContext"')
        ->assertSeeHtml('wire:model="statusRemindOn"')
        ->assertDontSeeHtml('wire:model="statusDate"')
        ->set('newStatus', ProposalStatus::Rejected->value)
        ->assertSeeHtml('wire:model="statusDate"')
        ->assertDontSeeHtml('wire:model="statusRemindOn"');
});

test('moving to on hold sets the reminder and records the reason', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);
    $remindOn = now()->addWeek()->toDateString();

    statusModal('Financial', $proposal)
        ->set('newStatus', ProposalStatus::OnHold->value)
        ->set('statusContext', 'Donor froze all grants.')
        ->set('statusRemindOn', $remindOn)
        ->call('changeStatus')
        ->assertHasNoErrors();

    expect($proposal->fresh()->status)->toBe(ProposalStatus::OnHold)
        ->and($proposal->fresh()->status_remind_on->toDateString())->toBe($remindOn);
});

test('a target outside the table is refused on the status field and writes nothing', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);

    statusModal('Financial', $proposal)
        ->set('newStatus', ProposalStatus::Approved->value)
        ->call('changeStatus')
        ->assertHasErrors(['newStatus']);

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Submitted)
        ->and($proposal->statusNotes()->count())->toBe(0);
});

test('a requirement the proposal does not meet shows in the gate alert and writes nothing', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::ConceptNote]);
    $proposal->projects()->detach();

    statusModal('Financial', $proposal)
        ->set('newStatus', ProposalStatus::ConceptNoteReview->value)
        ->call('changeStatus')
        ->assertHasErrors(['statusGate'])
        ->assertSeeHtml('data-test="status-gate-alert"')
        ->assertSee(__('proposals.link_project_first'));

    expect($proposal->fresh()->status)->toBe(ProposalStatus::ConceptNote);
});

test('a proposal moved by someone else is refused with a reload prompt, and reload shows its new targets', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);

    $component = statusModal('Financial', $proposal)
        ->set('newStatus', ProposalStatus::ProposalDraft->value)
        ->set('statusContext', 'Donor asked to resubmit.');

    $proposal->forceFill(['status' => ProposalStatus::OnHold])->save();
    ProposalStatusNote::factory()->create(['proposal_id' => $proposal->id, 'status' => ProposalStatus::Submitted]);
    ProposalStatusNote::factory()->create(['proposal_id' => $proposal->id, 'status' => ProposalStatus::OnHold]);

    $component->call('changeStatus')
        ->assertSet('statusStale', true)
        ->assertSeeHtml('data-test="status-stale-alert"')
        ->assertSee(__('proposals.stale_status_explained'));

    expect($proposal->fresh()->status)->toBe(ProposalStatus::OnHold)
        ->and(ProposalStatusNote::query()->where('proposal_id', $proposal->id)->count())->toBe(2);

    $component->call('reloadStatusModal')
        ->assertSet('statusStale', false)
        ->assertSet('statusExpectedFrom', ProposalStatus::OnHold->value);
});

test('a user without proposals.update cannot open the modal', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);

    Livewire::actingAs(userWithRole('Team Member'))
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->call('openStatusModal', $proposal->id)
        ->assertForbidden();
});

test('the page offers the status button only while a move exists', function () {
    $open = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);
    $approved = Proposal::factory()->create(['status' => ProposalStatus::Approved]);
    $user = userWithRole('Financial');

    Livewire::actingAs($user)->test('pages::proposals.show', ['proposal' => $open])
        ->assertSeeHtml('data-test="open-status-modal"');
    Livewire::actingAs($user)->test('pages::proposals.show', ['proposal' => $approved])
        ->assertDontSeeHtml('data-test="open-status-modal"');
});

test('correct status is offered to its holders for a terminal status, with every live target but approved', function (string $role) {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Rejected]);

    $component = Livewire::actingAs(userWithRole($role))
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->assertSeeHtml('data-test="open-correct-status-modal"')
        ->call('openCorrectModal', $proposal->id)
        ->assertSet('showCorrectModal', true);

    $targets = $component->instance()->correctionTargets;

    expect($targets)->not->toContain(ProposalStatus::Approved, ProposalStatus::Rejected, ProposalStatus::FinalReports)
        ->and($targets)->toContain(ProposalStatus::Submitted);
})->with(['General Manager', 'Public Relations Manager']);

test('a correction records the reason and flags the note', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Rejected]);
    $user = userWithRole('General Manager');

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->call('openCorrectModal', $proposal->id)
        ->set('correctTarget', ProposalStatus::Submitted->value)
        ->set('correctReason', 'The rejection was entered on the wrong proposal.')
        ->call('correctStatus')
        ->assertHasNoErrors()
        ->assertSet('showCorrectModal', false);

    $note = ProposalStatusNote::query()->where('proposal_id', $proposal->id)->sole();

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Submitted)
        ->and($note->is_correction)->toBeTrue()
        ->and($note->changed_by)->toBe($user->id);
});

test('a correction without a reason or with approved as target changes nothing', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Rejected]);

    $component = Livewire::actingAs(userWithRole('General Manager'))
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->call('openCorrectModal', $proposal->id)
        ->set('correctTarget', ProposalStatus::Submitted->value)
        ->set('correctReason', '   ')
        ->call('correctStatus')
        ->assertHasErrors(['correctReason'])
        ->set('correctTarget', ProposalStatus::Approved->value)
        ->set('correctReason', 'Trying to skip the approval evidence.')
        ->call('correctStatus')
        ->assertHasErrors(['correctTarget']);

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Rejected)
        ->and($proposal->statusNotes()->count())->toBe(0);
});

test('correct status is hidden from and refused to users without proposals.correct-status', function (string $role) {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Rejected]);

    $fresh = fn () => Livewire::actingAs(userWithRole($role))->test('pages::proposals.show', ['proposal' => $proposal]);

    $fresh()->assertDontSeeHtml('data-test="open-correct-status-modal"');
    $fresh()->call('openCorrectModal', $proposal->id)->assertForbidden();
    expect($proposal->fresh()->status)->toBe(ProposalStatus::Rejected);
})->with(['Financial', 'Managers']);
