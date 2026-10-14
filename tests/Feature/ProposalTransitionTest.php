<?php

use App\Actions\Proposals\TransitionProposal;
use App\Enums\Currency;
use App\Enums\ProposalStatus;
use App\Events\ProposalStatusChanged;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/**
 * A proposal at the given status that meets every §2 requirement: one linked
 * project with beneficiaries and a requested budget.
 */
function transitionableProposal(ProposalStatus $status, ?ProposalStatus $preHold = null): Proposal
{
    $proposal = Proposal::factory()->create([
        'status' => $status,
        'requested_budget' => '120000.00',
        'requested_currency' => Currency::Usd,
    ]);
    $proposal->projects()->attach(Project::factory()->create()->id, ['target_beneficiaries' => 400]);

    if ($preHold !== null) {
        ProposalStatusNote::factory()->create(['proposal_id' => $proposal->id, 'status' => $preHold, 'context' => 'Sent.']);
        ProposalStatusNote::factory()->create(['proposal_id' => $proposal->id, 'status' => ProposalStatus::OnHold, 'context' => 'Donor paused the call.']);
    }

    return $proposal;
}

/**
 * Run a transition as the given user.
 *
 * @param  array{reason?: string, effective_date?: string, remind_on?: string}  $input
 */
function transition(User $actor, Proposal $proposal, ProposalStatus $to, array $input = [], ?ProposalStatus $expectedFrom = null): mixed
{
    test()->actingAs($actor);

    return app(TransitionProposal::class)->handle($proposal, $to, $expectedFrom ?? $proposal->status, $input);
}

/**
 * The validation errors a refused transition reports.
 *
 * @return array<string, list<string>>
 */
function refusedTransition(callable $move): array
{
    try {
        $move();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('The transition was not refused.');
}

beforeEach(fn () => $this->travelTo('2026-10-13 10:00:00'));

test('every allowed transition moves the status and writes one note by the actor', function (ProposalStatus $from, ProposalStatus $to, array $input, ?ProposalStatus $preHold) {
    $actor = userWithRole('Financial');
    $proposal = transitionableProposal($from, $preHold);
    $notesBefore = $proposal->statusNotes()->count();

    $result = transition($actor, $proposal, $to, $input);

    expect($proposal->fresh()->status)->toBe($to)
        ->and($proposal->statusNotes()->count())->toBe($notesBefore + 1)
        ->and($result->note->status)->toBe($to)
        ->and($result->note->changed_by)->toBe($actor->id)
        ->and($result->note->is_correction)->toBeFalse()
        ->and($result->note->context)->not->toBe('');
})->with([
    'concept note → review' => [ProposalStatus::ConceptNote, ProposalStatus::ConceptNoteReview, [], null],
    'concept note review → concept note' => [ProposalStatus::ConceptNoteReview, ProposalStatus::ConceptNote, ['reason' => 'Rework the problem statement.'], null],
    'concept note review → submitted' => [ProposalStatus::ConceptNoteReview, ProposalStatus::ConceptNoteSubmitted, ['effective_date' => '2026-10-12'], null],
    'concept note → proposal draft (skip)' => [ProposalStatus::ConceptNote, ProposalStatus::ProposalDraft, ['reason' => 'المقترح موجود مسبقًا'], null],
    'concept note submitted → proposal draft' => [ProposalStatus::ConceptNoteSubmitted, ProposalStatus::ProposalDraft, ['effective_date' => '2026-10-13'], null],
    'concept note submitted → on hold' => [ProposalStatus::ConceptNoteSubmitted, ProposalStatus::OnHold, ['reason' => 'Donor paused the call.', 'remind_on' => '2026-11-01'], null],
    'concept note submitted → rejected' => [ProposalStatus::ConceptNoteSubmitted, ProposalStatus::Rejected, ['reason' => 'Outside the donor priorities.', 'effective_date' => '2026-10-10'], null],
    'proposal draft → review' => [ProposalStatus::ProposalDraft, ProposalStatus::ProposalReview, [], null],
    'proposal review → proposal draft' => [ProposalStatus::ProposalReview, ProposalStatus::ProposalDraft, ['reason' => 'Budget lines need detail.'], null],
    'proposal review → submitted' => [ProposalStatus::ProposalReview, ProposalStatus::Submitted, ['effective_date' => '2026-10-13'], null],
    'submitted → proposal draft' => [ProposalStatus::Submitted, ProposalStatus::ProposalDraft, ['reason' => 'Donor asked to resubmit.'], null],
    'submitted → on hold' => [ProposalStatus::Submitted, ProposalStatus::OnHold, ['reason' => 'Donor freeze.', 'remind_on' => '2026-12-01'], null],
    'submitted → rejected' => [ProposalStatus::Submitted, ProposalStatus::Rejected, ['reason' => 'Donor declined the grant.', 'effective_date' => '2026-10-13'], null],
    'on hold (concept note submitted) → proposal draft' => [ProposalStatus::OnHold, ProposalStatus::ProposalDraft, ['effective_date' => '2026-10-13'], ProposalStatus::ConceptNoteSubmitted],
    'on hold (concept note submitted) → rejected' => [ProposalStatus::OnHold, ProposalStatus::Rejected, ['reason' => 'Call cancelled for good.', 'effective_date' => '2026-10-13'], ProposalStatus::ConceptNoteSubmitted],
    'on hold (submitted) → submitted' => [ProposalStatus::OnHold, ProposalStatus::Submitted, ['effective_date' => '2026-10-13'], ProposalStatus::Submitted],
    'on hold (submitted) → proposal draft' => [ProposalStatus::OnHold, ProposalStatus::ProposalDraft, ['reason' => 'Donor asked to resubmit.'], ProposalStatus::Submitted],
    'on hold (submitted) → rejected' => [ProposalStatus::OnHold, ProposalStatus::Rejected, ['reason' => 'Donor declined the grant.', 'effective_date' => '2026-10-13'], ProposalStatus::Submitted],
]);

test('a missing requirement refuses the move with its message and writes nothing', function (ProposalStatus $from, ProposalStatus $to, array $input, Closure $break, string $field, string $message) {
    $proposal = transitionableProposal($from);
    $break($proposal);

    $errors = refusedTransition(fn () => transition(userWithRole('Financial'), $proposal, $to, $input));

    expect($errors[$field])->toBe([__($message)])
        ->and($proposal->fresh()->status)->toBe($from)
        ->and($proposal->statusNotes()->count())->toBe(0);
})->with([
    'linked_project' => [ProposalStatus::ConceptNote, ProposalStatus::ConceptNoteReview, [], fn (Proposal $p) => $p->projects()->detach(), 'linked_project', 'proposals.link_project_first'],
    'planned_dates' => [ProposalStatus::ConceptNote, ProposalStatus::ConceptNoteReview, [], fn (Proposal $p) => $p->forceFill(['planned_date_end' => null])->save(), 'planned_dates', 'proposals.planned_dates_required'],
    'reason' => [ProposalStatus::ConceptNoteReview, ProposalStatus::ConceptNote, ['reason' => '  '], fn () => null, 'reason', 'proposals.reason_required'],
    'reason_min_10' => [ProposalStatus::Submitted, ProposalStatus::Rejected, ['reason' => 'No fit.', 'effective_date' => '2026-10-13'], fn () => null, 'reason', 'proposals.rejection_reason'],
    'effective_date' => [ProposalStatus::ProposalReview, ProposalStatus::Submitted, ['effective_date' => '2026-10-14'], fn () => null, 'effective_date', 'proposals.date_not_future'],
    'remind_on' => [ProposalStatus::Submitted, ProposalStatus::OnHold, ['reason' => 'Donor freeze.', 'remind_on' => '2026-10-13'], fn () => null, 'remind_on', 'proposals.remind_on_future'],
    'budget' => [ProposalStatus::ProposalDraft, ProposalStatus::ProposalReview, [], fn (Proposal $p) => $p->forceFill(['requested_budget' => null])->save(), 'budget', 'proposals.budget_required'],
    'target_beneficiaries' => [ProposalStatus::ProposalReview, ProposalStatus::Submitted, ['effective_date' => '2026-10-13'], fn (Proposal $p) => $p->projects()->updateExistingPivot($p->projects()->value('projects.id'), ['target_beneficiaries' => null]), 'target_beneficiaries', 'proposals.beneficiaries_required'],
]);

test('every linked project needs beneficiaries before the proposal goes to review', function () {
    $proposal = transitionableProposal(ProposalStatus::ProposalDraft);
    $proposal->projects()->attach(Project::factory()->create()->id, ['target_beneficiaries' => null]);

    $errors = refusedTransition(fn () => transition(userWithRole('Financial'), $proposal, ProposalStatus::ProposalReview));

    expect($errors['target_beneficiaries'])->toBe([__('proposals.beneficiaries_required')])
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::ProposalDraft);
});

test('a target outside the table is refused and writes nothing', function (ProposalStatus $from, ProposalStatus $to) {
    $proposal = transitionableProposal($from);

    $errors = refusedTransition(fn () => transition(userWithRole('Financial'), $proposal, $to, ['reason' => 'Trying a shortcut.', 'effective_date' => '2026-10-13']));

    expect($errors['status'])->toBe([__('proposals.transition_not_allowed', ['from' => $from->label(), 'to' => $to->label()])])
        ->and($proposal->fresh()->status)->toBe($from)
        ->and($proposal->statusNotes()->count())->toBe(0);
})->with([
    'concept note → approved' => [ProposalStatus::ConceptNote, ProposalStatus::Approved],
    'submitted → approved (ApproveProposal only)' => [ProposalStatus::Submitted, ProposalStatus::Approved],
    'approved → active (ActivateProject only)' => [ProposalStatus::Approved, ProposalStatus::Active],
    'rejected → proposal draft (correction only)' => [ProposalStatus::Rejected, ProposalStatus::ProposalDraft],
    'completed → active (correction only)' => [ProposalStatus::Completed, ProposalStatus::Active],
    'concept note → submitted' => [ProposalStatus::ConceptNote, ProposalStatus::Submitted],
    'into the retired final reports' => [ProposalStatus::Active, ProposalStatus::FinalReports],
]);

test('on hold offers only the targets of its pre-hold status', function () {
    $proposal = transitionableProposal(ProposalStatus::OnHold, ProposalStatus::ConceptNoteSubmitted);

    $errors = refusedTransition(fn () => transition(userWithRole('Financial'), $proposal, ProposalStatus::Submitted, ['effective_date' => '2026-10-13']));

    expect($errors)->toHaveKey('status')
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::OnHold);
});

test('entering on hold sets the reminder date and leaving clears it', function () {
    $actor = userWithRole('Financial');
    $proposal = transitionableProposal(ProposalStatus::Submitted);
    ProposalStatusNote::factory()->create(['proposal_id' => $proposal->id, 'status' => ProposalStatus::Submitted, 'context' => 'Sent.']);

    transition($actor, $proposal, ProposalStatus::OnHold, ['reason' => 'Donor freeze.', 'remind_on' => '2026-11-15']);
    $remindOn = $proposal->fresh()->status_remind_on?->toDateString();
    transition($actor, $proposal, ProposalStatus::Submitted, ['effective_date' => '2026-10-13']);

    expect($remindOn)->toBe('2026-11-15')
        ->and($proposal->fresh()->status_remind_on)->toBeNull();
});

test('the note carries the effective date, or today and the standard note when none applies', function () {
    $actor = userWithRole('Financial');

    $dated = transition($actor, transitionableProposal(ProposalStatus::ProposalReview), ProposalStatus::Submitted, ['effective_date' => '2026-10-01']);
    $plain = transition($actor, transitionableProposal(ProposalStatus::ConceptNote), ProposalStatus::ConceptNoteReview);

    expect($dated->note->date->toDateString())->toBe('2026-10-01')
        ->and($plain->note->date->toDateString())->toBe('2026-10-13')
        ->and($plain->note->context)->toBe(__('proposals.status_moved_standard_note', [
            'from' => ProposalStatus::ConceptNote->label(),
            'to' => ProposalStatus::ConceptNoteReview->label(),
        ]));
});

test('a stale expected status is refused and leaves exactly one note', function () {
    $actor = userWithRole('Financial');
    $proposal = transitionableProposal(ProposalStatus::ConceptNote);
    $staleCopy = Proposal::query()->findOrFail($proposal->id);
    transition($actor, $proposal, ProposalStatus::ConceptNoteReview);

    $errors = refusedTransition(fn () => transition($actor, $staleCopy, ProposalStatus::ProposalDraft, ['reason' => 'Skipping the concept note.'], ProposalStatus::ConceptNote));

    expect($errors['status'])->toBe([__('proposals.stale_status')])
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::ConceptNoteReview)
        ->and($proposal->statusNotes()->count())->toBe(1);
});

test('a user without proposals.update cannot move a proposal', function () {
    $proposal = transitionableProposal(ProposalStatus::ConceptNote);

    expect(fn () => transition(userWithRole('Managers'), $proposal, ProposalStatus::ConceptNoteReview))
        ->toThrow(AuthorizationException::class);
    expect($proposal->fresh()->status)->toBe(ProposalStatus::ConceptNote)
        ->and($proposal->statusNotes()->count())->toBe(0);
});

test('a move raises the status-changed activity', function () {
    $proposal = transitionableProposal(ProposalStatus::ConceptNote);

    transition(userWithRole('Financial'), $proposal, ProposalStatus::ConceptNoteReview);

    $this->assertDatabaseHas('activities', ['subject_id' => $proposal->id, 'event' => 'proposal.status_changed']);
});

test('a successful move dispatches exactly one event with its payload', function () {
    $actor = userWithRole('Financial');
    $proposal = transitionableProposal(ProposalStatus::Submitted);
    Event::fake([ProposalStatusChanged::class]);

    transition($actor, $proposal, ProposalStatus::Rejected, ['reason' => 'Donor declined the grant.', 'effective_date' => '2026-10-13']);

    Event::assertDispatchedTimes(ProposalStatusChanged::class, 1);
    Event::assertDispatched(ProposalStatusChanged::class, fn (ProposalStatusChanged $event): bool => $event->proposalId === $proposal->id
        && $event->from === ProposalStatus::Submitted
        && $event->to === ProposalStatus::Rejected
        && $event->actorId === $actor->id
        && $event->isCorrection === false);
});

test('a refused move dispatches no event', function () {
    $proposal = transitionableProposal(ProposalStatus::ConceptNote);
    Event::fake([ProposalStatusChanged::class]);

    refusedTransition(fn () => transition(userWithRole('Financial'), $proposal, ProposalStatus::Approved));

    Event::assertNotDispatched(ProposalStatusChanged::class);
});

test('a move rolled back by an outer transaction dispatches no event and writes nothing', function () {
    $actor = userWithRole('Financial');
    $proposal = transitionableProposal(ProposalStatus::ConceptNote);
    Event::fake([ProposalStatusChanged::class]);

    try {
        DB::transaction(function () use ($actor, $proposal): void {
            transition($actor, $proposal, ProposalStatus::ConceptNoteReview);

            throw new RuntimeException('The caller failed after the move.');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(ProposalStatusChanged::class);
    expect($proposal->fresh()->status)->toBe(ProposalStatus::ConceptNote)
        ->and($proposal->statusNotes()->count())->toBe(0);
});

test('on hold and rejected keep the stage of the status they left', function (ProposalStatus $status, ProposalStatus $before, string $stage) {
    $proposal = Proposal::factory()->create(['status' => $status]);
    ProposalStatusNote::factory()->create(['proposal_id' => $proposal->id, 'status' => $before, 'context' => 'Earlier move.']);
    ProposalStatusNote::factory()->create(['proposal_id' => $proposal->id, 'status' => $status, 'context' => 'Latest move.']);

    expect($proposal->stage())->toBe($stage);
})->with([
    'on hold after the concept note was sent' => [ProposalStatus::OnHold, ProposalStatus::ConceptNoteSubmitted, 'concept_note'],
    'on hold after the proposal was sent' => [ProposalStatus::OnHold, ProposalStatus::Submitted, 'proposal'],
    'rejected after the concept note was sent' => [ProposalStatus::Rejected, ProposalStatus::ConceptNoteSubmitted, 'concept_note'],
    'rejected after the proposal was sent' => [ProposalStatus::Rejected, ProposalStatus::Submitted, 'proposal'],
]);

test('a rejection during a hold keeps the stage of the status before the hold', function (ProposalStatus $preHold, string $stage) {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Rejected]);

    foreach ([$preHold, ProposalStatus::OnHold, ProposalStatus::Rejected] as $status) {
        ProposalStatusNote::factory()->create(['proposal_id' => $proposal->id, 'status' => $status, 'context' => 'Move.']);
    }

    expect($proposal->stage())->toBe($stage);
})->with([
    'held after the concept note was sent' => [ProposalStatus::ConceptNoteSubmitted, 'concept_note'],
    'held after the proposal was sent' => [ProposalStatus::Submitted, 'proposal'],
]);

test('the retired final reports status moves to active with one note per proposal', function () {
    $retiredId = insertProposal('Winter Kits');
    DB::table('proposals')->where('id', $retiredId)->update(['status' => 'final reports']);
    $activeId = insertProposal('Clean Water');
    DB::table('proposals')->where('id', $activeId)->update(['status' => 'active']);

    (require database_path('migrations/2026_10_13_162615_move_final_reports_proposals_to_active.php'))->up();

    expect(DB::table('proposals')->find($retiredId)->status)->toBe('active')
        ->and(DB::table('proposal_status_notes')->where('proposal_id', $retiredId)->pluck('context')->all())->toBe([__('proposals.final_reports_status_retired')])
        ->and(DB::table('proposal_status_notes')->where('proposal_id', $activeId)->count())->toBe(0);
});
