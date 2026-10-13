<?php

use App\Actions\Proposals\CorrectProposalStatus;
use App\Enums\ProposalStatus;
use App\Events\ProposalStatusChanged;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/**
 * Run a correction as the given user.
 */
function correctStatus(User $actor, Proposal $proposal, ProposalStatus $to, string $reason = 'The rejection was recorded on the wrong proposal.'): mixed
{
    test()->actingAs($actor);

    return app(CorrectProposalStatus::class)->handle($proposal, $to, $proposal->status, $reason);
}

test('a correction holder reopens a terminal status with a flagged note', function (string $role, ProposalStatus $from, ProposalStatus $to) {
    $actor = userWithRole($role);
    $proposal = Proposal::factory()->create(['status' => $from]);
    Event::fake([ProposalStatusChanged::class]);

    $result = correctStatus($actor, $proposal, $to);

    expect($proposal->fresh()->status)->toBe($to)
        ->and($result->note->is_correction)->toBeTrue()
        ->and($result->note->changed_by)->toBe($actor->id)
        ->and($result->note->context)->toBe('The rejection was recorded on the wrong proposal.');
    Event::assertDispatched(ProposalStatusChanged::class, fn (ProposalStatusChanged $event): bool => $event->from === $from
        && $event->to === $to
        && $event->isCorrection === true);
})->with([
    'General Manager, rejected → submitted' => ['General Manager', ProposalStatus::Rejected, ProposalStatus::Submitted],
    'Public Relations Manager, completed → active' => ['Public Relations Manager', ProposalStatus::Completed, ProposalStatus::Active],
]);

test('a user without proposals.correct-status cannot correct', function (string $role) {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Rejected]);

    expect(fn () => correctStatus(userWithRole($role), $proposal, ProposalStatus::Submitted))
        ->toThrow(AuthorizationException::class);
    expect($proposal->fresh()->status)->toBe(ProposalStatus::Rejected)
        ->and($proposal->statusNotes()->count())->toBe(0);
})->with(['Financial', 'Managers']);

test('a correction is refused for a live source or an approved target', function (ProposalStatus $from, ProposalStatus $to) {
    $proposal = Proposal::factory()->create(['status' => $from]);

    expect(fn () => correctStatus(userWithRole('General Manager'), $proposal, $to))
        ->toThrow(ValidationException::class, __('proposals.transition_not_allowed', ['from' => $from->label(), 'to' => $to->label()]));
    expect($proposal->fresh()->status)->toBe($from)
        ->and($proposal->statusNotes()->count())->toBe(0);
})->with([
    'submitted is not terminal' => [ProposalStatus::Submitted, ProposalStatus::ProposalDraft],
    'active is not terminal' => [ProposalStatus::Active, ProposalStatus::Completed],
    'rejected → approved' => [ProposalStatus::Rejected, ProposalStatus::Approved],
    'completed → approved' => [ProposalStatus::Completed, ProposalStatus::Approved],
]);

test('a correction needs a reason', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Completed]);

    expect(fn () => correctStatus(userWithRole('General Manager'), $proposal, ProposalStatus::Active, '   '))
        ->toThrow(ValidationException::class, __('proposals.reason_required'));
    expect($proposal->fresh()->status)->toBe(ProposalStatus::Completed);
});

test('a stale correction is refused', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Rejected]);
    $this->actingAs(userWithRole('General Manager'));

    expect(fn () => app(CorrectProposalStatus::class)->handle($proposal, ProposalStatus::Submitted, ProposalStatus::Completed, 'Wrong proposal.'))
        ->toThrow(ValidationException::class, __('proposals.stale_status'));
    expect($proposal->statusNotes()->count())->toBe(0);
});
