<?php

use App\Enums\ProposalStatus;
use App\Enums\StatusNoteKind;
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
        ->toBe(['proposal draft', 'rejected']);

    // A hold is not a move since W-06 (D-58): it has its own action in slice 2.
    $component->assertDontSeeHtml('value="on hold"')
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
        ->assertDontSeeHtml('wire:model="statusDate"')
        ->set('newStatus', ProposalStatus::Rejected->value)
        ->assertSeeHtml('wire:model="statusDate"')
        // No move asks for a reminder date any more (W-06, D-58): the hold
        // action owns it.
        ->assertDontSeeHtml('wire:model="statusRemindOn"');
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
    $proposal->locations()->delete();

    statusModal('Financial', $proposal)
        ->set('newStatus', ProposalStatus::ConceptNoteReview->value)
        ->call('changeStatus')
        ->assertHasErrors(['statusGate'])
        ->assertSeeHtml('data-test="status-gate-alert"')
        ->assertSee(__('proposals.locations_required'));

    expect($proposal->fresh()->status)->toBe(ProposalStatus::ConceptNote);
});

test('a proposal moved by someone else is refused with a reload prompt, and reload shows its new targets', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);

    $component = statusModal('Financial', $proposal)
        ->set('newStatus', ProposalStatus::ProposalDraft->value)
        ->set('statusContext', 'Donor asked to resubmit.');

    $proposal->forceFill(['status' => ProposalStatus::Rejected])->save();
    ProposalStatusNote::factory()->create(['proposal_id' => $proposal->id, 'status' => ProposalStatus::Submitted]);
    ProposalStatusNote::factory()->create(['proposal_id' => $proposal->id, 'status' => ProposalStatus::Rejected]);

    $component->call('changeStatus')
        ->assertSet('statusStale', true)
        ->assertSeeHtml('data-test="status-stale-alert"')
        ->assertSee(__('proposals.stale_status_explained'));

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Rejected)
        ->and(ProposalStatusNote::query()->where('proposal_id', $proposal->id)->count())->toBe(2);

    $component->call('reloadStatusModal')
        ->assertSet('statusStale', false)
        ->assertSet('statusExpectedFrom', ProposalStatus::Rejected->value);
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

/*
 * W-06 slice 2 (D-58): a hold is a flag, so it has its own two actions on the
 * proposal page instead of an entry in the transition picker.
 */

test('the hold actions are offered only where a hold is allowed', function () {
    $user = userWithRole('Financial');
    $page = fn (Proposal $proposal) => Livewire::actingAs($user)->test('pages::proposals.show', ['proposal' => $proposal]);

    foreach ([ProposalStatus::Submitted, ProposalStatus::ConceptNoteSubmitted] as $holdable) {
        $page(Proposal::factory()->create(['status' => $holdable]))
            ->assertSeeHtml('data-test="open-hold-modal"')
            ->assertDontSeeHtml('data-test="open-off-hold-modal"');
    }

    // Already held: only the way back out is offered.
    $held = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);
    $held->forceFill(['on_hold_since' => today(), 'status_remind_on' => today()->addWeek()])->save();

    $page($held)
        ->assertSeeHtml('data-test="open-off-hold-modal"')
        ->assertDontSeeHtml('data-test="open-hold-modal"');

    // Nowhere else: a hold means the ball is in the donor's court.
    foreach ([ProposalStatus::ConceptNote, ProposalStatus::ProposalDraft, ProposalStatus::Approved, ProposalStatus::Active] as $other) {
        $page(Proposal::factory()->create(['status' => $other]))
            ->assertDontSeeHtml('data-test="open-hold-modal"')
            ->assertDontSeeHtml('data-test="open-off-hold-modal"');
    }
});

test('putting a proposal on hold records the flag and its note without moving the status', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);
    $user = userWithRole('Financial');

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->call('openHoldModal')
        ->assertSet('showHoldModal', true)
        ->set('holdReason', 'The donor asked for the revised budget first.')
        ->set('holdRemindOn', today()->addWeeks(2)->toDateString())
        ->call('putOnHold')
        ->assertHasNoErrors()
        ->assertSet('showHoldModal', false);

    $fresh = $proposal->fresh();
    $note = ProposalStatusNote::query()->where('proposal_id', $proposal->id)->sole();

    expect($fresh->status)->toBe(ProposalStatus::Submitted)
        ->and($fresh->isOnHold())->toBeTrue()
        ->and($fresh->status_remind_on?->toDateString())->toBe(today()->addWeeks(2)->toDateString())
        ->and($note->kind)->toBe(StatusNoteKind::OnHold)
        ->and($note->status)->toBe(ProposalStatus::Submitted)
        ->and($note->context)->toBe('The donor asked for the revised budget first.')
        ->and($note->changed_by)->toBe($user->id);
});

test('taking a proposal off hold clears the flag and its reminder', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);
    $proposal->forceFill(['on_hold_since' => today()->subMonth(), 'status_remind_on' => today()->addWeek()])->save();

    Livewire::actingAs(userWithRole('Financial'))
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->call('openOffHoldModal')
        ->assertSet('showOffHoldModal', true)
        ->set('offHoldNote', 'The donor came back with questions.')
        ->call('takeOffHold')
        ->assertHasNoErrors()
        ->assertSet('showOffHoldModal', false);

    $fresh = $proposal->fresh();
    $note = ProposalStatusNote::query()->where('proposal_id', $proposal->id)->sole();

    expect($fresh->isOnHold())->toBeFalse()
        ->and($fresh->status_remind_on)->toBeNull()
        ->and($fresh->status)->toBe(ProposalStatus::Submitted)
        ->and($note->kind)->toBe(StatusNoteKind::OffHold)
        ->and($note->context)->toBe('The donor came back with questions.');
});

test('a hold without a reason or with a past reminder date is refused and writes nothing', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);

    Livewire::actingAs(userWithRole('Financial'))
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->call('openHoldModal')
        ->set('holdReason', '   ')
        ->set('holdRemindOn', today()->addWeek()->toDateString())
        ->call('putOnHold')
        ->assertHasErrors(['holdReason'])
        ->set('holdReason', 'Waiting on the donor.')
        ->set('holdRemindOn', today()->subDay()->toDateString())
        ->call('putOnHold')
        ->assertHasErrors(['holdRemindOn'])
        ->assertSee(__('proposals.remind_on_future'));

    expect($proposal->fresh()->isOnHold())->toBeFalse()
        ->and($proposal->statusNotes()->count())->toBe(0);
});

test('a hold on a proposal someone else moved meanwhile is refused with the stale message', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);

    $component = Livewire::actingAs(userWithRole('Financial'))
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->call('openHoldModal')
        ->set('holdReason', 'Waiting on the donor.')
        ->set('holdRemindOn', today()->addWeek()->toDateString());

    $proposal->forceFill(['status' => ProposalStatus::Rejected])->save();

    $component->call('putOnHold')
        ->assertSet('holdStale', true)
        ->assertSee(__('proposals.stale_status'));

    expect($proposal->fresh()->isOnHold())->toBeFalse()
        ->and($proposal->statusNotes()->count())->toBe(0);
});

test('a user without proposals.update cannot reach either hold action', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);
    $proposal->forceFill(['on_hold_since' => today()])->save();

    $fresh = fn () => Livewire::actingAs(userWithRole('Team Member'))->test('pages::proposals.show', ['proposal' => $proposal]);

    $fresh()->assertDontSeeHtml('data-test="open-off-hold-modal"');
    $fresh()->call('openHoldModal')->assertForbidden();
    $fresh()->call('openOffHoldModal')->assertForbidden();

    expect($proposal->fresh()->isOnHold())->toBeTrue()
        ->and($proposal->statusNotes()->count())->toBe(0);
});

test('the hold modals read in Arabic', function () {
    app()->setLocale('ar');
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);

    Livewire::actingAs(userWithRole('Financial'))
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->assertSee(__('proposals.put_on_hold'))
        ->call('openHoldModal')
        ->assertSee(__('proposals.put_on_hold_consequence'))
        ->assertSee(__('proposals.why_put_on_hold'));
});
