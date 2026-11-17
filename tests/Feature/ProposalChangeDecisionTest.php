<?php

use App\Actions\Proposals\ApproveProposalChange;
use App\Actions\Proposals\RejectProposalChange;
use App\Actions\Proposals\SaveProposal;
use App\Enums\ChangeReviewVerdict;
use App\Enums\Currency;
use App\Enums\ProposalChangeRequestStatus;
use App\Enums\ProposalStatus;
use App\Events\ProposalChangeDecided;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalChangeRequest;
use App\Models\User;
use App\Notifications\Alerts\NotificationSettings;
use App\Support\EmployeeProfile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/*
| Y-09 slice 1 (contracts §6, D-89a, D-70): only the PR Manager decides a
| change request; approval re-checks it against the proposal as it is now
| and applies it through SaveProposal; rejection changes nothing.
*/

beforeEach(function () {
    $this->travelTo('2026-10-14 10:00:00');
    $this->manager = userWithRole('Public Relations Manager');
    $this->actingAs($this->manager);
});

/**
 * A pending change request on the proposal whose stored `changes` are what
 * SaveProposal computes for the payload, with both reviews waiting.
 *
 * @param  array<string, mixed>  $payload
 */
function decisionRequest(Proposal $proposal, array $payload, ?User $requester = null): ProposalChangeRequest
{
    $requester ??= userWithRole('Public Relations');
    $changes = DB::transaction(fn (): array => app(SaveProposal::class)->changesFor($proposal->fresh(), $payload, $requester));

    return ProposalChangeRequest::factory()->withReviews()->create([
        'proposal_id' => $proposal->id,
        'requested_by' => $requester->id,
        'changes' => $changes,
        'payload' => $payload,
    ]);
}

/**
 * Record a verdict in each slot directly, for setup.
 */
function decisionReviews(ProposalChangeRequest $request, ?ChangeReviewVerdict $finance, ?ChangeReviewVerdict $meal): void
{
    foreach (['finance_manager' => $finance, 'meal_manager' => $meal] as $slot => $verdict) {
        $request->reviews()->where('slot', $slot)->update([
            'verdict' => $verdict?->value,
            'note' => $verdict === ChangeReviewVerdict::Remarks ? 'Check the end date with the donor first.' : null,
            'reviewed_at' => $verdict === null ? null : now(),
        ]);
    }
}

/**
 * Give both review slots an active holder.
 */
function decisionHoldBothSlots(): void
{
    $finance = insertHrEmployee(firstNameEn: 'Fadi', lastNameEn: 'Finance');
    $financePosition = insertHrPosition(insertHrDepartment('Finance'), 'Finance Manager');
    insertHrContract($finance, $financePosition);

    $meal = insertHrEmployee(firstNameEn: 'Maha', lastNameEn: 'Manager');
    $mealPosition = insertHrPosition(insertHrDepartment('Monitoring & Evaluation'), 'MEAL Manager');
    insertHrContract($meal, $mealPosition);

    app(NotificationSettings::class)->save(['proposal_default_reviewers' => [
        'meal_manager' => $mealPosition, 'finance_manager' => $financePosition, 'fundraising_manager' => null, 'ceo' => null,
    ]]);

    EmployeeProfile::forgetRememberedLookup();
}

/**
 * The validation errors a refused decision reports.
 *
 * @return array<string, list<string>>
 */
function refusedDecision(callable $decide): array
{
    try {
        $decide();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('The decision was not refused.');
}

/**
 * An approved proposal with one linked project carrying beneficiaries.
 */
function decisionLinkedProposal(int $beneficiaries = 400): Proposal
{
    return Activity::withoutRecording(function () use ($beneficiaries): Proposal {
        $proposal = Proposal::factory()->create(['requested_budget' => '120000.00', 'requested_currency' => Currency::Usd]);
        $proposal->projects()->attach(Project::factory()->create(['name' => 'Water Network Rehabilitation'])->id, ['target_beneficiaries' => $beneficiaries]);
        DB::table('proposals')->where('id', $proposal->id)->update(['status' => ProposalStatus::Approved->value]);

        return $proposal->fresh();
    });
}

test('approve applies exactly the stored changes and marks the request approved', function () {
    $proposal = Proposal::factory()->running()->create(['name' => 'Winter Shelter', 'notes' => 'Keep as is.']);
    $before = $proposal->fresh();
    $request = decisionRequest($proposal, ['name' => 'Winter Shelter Phase II', 'plannedDateEnd' => '2027-12-31']);
    decisionReviews($request, ChangeReviewVerdict::NoObjection, ChangeReviewVerdict::NoObjection);

    $decided = app(ApproveProposalChange::class)->handle($request);

    $proposal->refresh();
    $activity = Activity::query()->where('event', 'proposal.change_approved')->sole();

    expect($decided->status)->toBe(ProposalChangeRequestStatus::Approved)
        ->and($decided->decided_by)->toBe($this->manager->id)
        ->and($decided->decided_at)->not->toBeNull()
        ->and($proposal->name)->toBe('Winter Shelter Phase II')
        ->and($proposal->planned_date_end->toDateString())->toBe('2027-12-31')
        ->and($proposal->planned_date_begin->toDateString())->toBe($before->planned_date_begin->toDateString())
        ->and($proposal->notes)->toBe('Keep as is.')
        ->and($proposal->donor_id)->toBe($before->donor_id)
        ->and($proposal->status)->toBe(ProposalStatus::Approved)
        ->and($activity->subject_id)->toBe($proposal->id)
        ->and($activity->properties['change_request_id'])->toBe($request->id)
        ->and($activity->properties['fields'])->toEqualCanonicalizing(['name', 'plannedDateEnd']);
});

test('reject needs a note of at least 10 characters and changes nothing', function () {
    $proposal = Proposal::factory()->running()->create(['name' => 'Winter Shelter']);
    $request = decisionRequest($proposal, ['name' => 'Winter Shelter Phase II']);

    $errors = refusedDecision(fn () => app(RejectProposalChange::class)->handle($request, '  too short '));

    expect($errors['note'])->toBe([__('proposals.change_request_note_min')])
        ->and($request->fresh()->status)->toBe(ProposalChangeRequestStatus::Pending);

    $decided = app(RejectProposalChange::class)->handle($request, 'The donor did not sign the new name.');

    expect($decided->status)->toBe(ProposalChangeRequestStatus::Rejected)
        ->and($decided->decided_by)->toBe($this->manager->id)
        ->and($decided->decision_note)->toBe('The donor did not sign the new name.')
        ->and($proposal->fresh()->name)->toBe('Winter Shelter')
        ->and(Activity::query()->where('event', 'proposal.change_rejected')->sole()->properties['change_request_id'])->toBe($request->id);
});

test('a user who can update but not approve proposals gets 403 on both decisions', function (string $role) {
    $proposal = Proposal::factory()->running()->create(['name' => 'Winter Shelter']);
    $request = decisionRequest($proposal, ['name' => 'Winter Shelter Phase II']);
    decisionReviews($request, ChangeReviewVerdict::NoObjection, ChangeReviewVerdict::NoObjection);
    $this->actingAs(userWithRole($role));

    expect(fn () => app(ApproveProposalChange::class)->handle($request))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RejectProposalChange::class)->handle($request, 'Not what the donor signed.'))->toThrow(AuthorizationException::class)
        ->and($request->fresh()->status)->toBe(ProposalChangeRequestStatus::Pending)
        ->and($proposal->fresh()->name)->toBe('Winter Shelter');
})->with(['Public Relations', 'General Manager']);

test('a PR Manager decides their own request', function () {
    $proposal = Proposal::factory()->running()->create(['name' => 'Winter Shelter']);
    $request = decisionRequest($proposal, ['name' => 'Winter Shelter Phase II'], $this->manager);
    decisionReviews($request, ChangeReviewVerdict::NoObjection, ChangeReviewVerdict::NoObjection);

    app(ApproveProposalChange::class)->handle($request);

    expect($request->fresh()->status)->toBe(ProposalChangeRequestStatus::Approved)
        ->and($proposal->fresh()->name)->toBe('Winter Shelter Phase II');
});

test('a request whose from no longer matches is stale and applies nothing', function () {
    $proposal = Proposal::factory()->running()->create(['name' => 'Winter Shelter']);
    $request = decisionRequest($proposal, ['name' => 'Winter Shelter Phase II', 'plannedDateEnd' => '2027-12-31']);
    decisionReviews($request, ChangeReviewVerdict::NoObjection, ChangeReviewVerdict::NoObjection);
    DB::table('proposals')->where('id', $proposal->id)->update(['name' => 'Winter Shelter Renamed']);
    $endBefore = $proposal->fresh()->planned_date_end->toDateString();

    $errors = refusedDecision(fn () => app(ApproveProposalChange::class)->handle($request));

    expect($errors['changeRequest'])->toBe([__('proposals.change_request_stale', ['fields' => 'name'])])
        ->and($request->fresh()->status)->toBe(ProposalChangeRequestStatus::Pending)
        ->and($proposal->fresh()->name)->toBe('Winter Shelter Renamed')
        ->and($proposal->fresh()->planned_date_end->toDateString())->toBe($endBefore)
        ->and(Activity::query()->where('event', 'proposal.change_approved')->exists())->toBeFalse();
});

test('a decided request is refused on a second decision', function () {
    $proposal = Proposal::factory()->running()->create(['name' => 'Winter Shelter']);
    $request = decisionRequest($proposal, ['name' => 'Winter Shelter Phase II']);
    app(RejectProposalChange::class)->handle($request, 'The donor did not sign the new name.');

    $approve = refusedDecision(fn () => app(ApproveProposalChange::class)->handle($request));
    $reject = refusedDecision(fn () => app(RejectProposalChange::class)->handle($request, 'Rejecting it a second time.'));

    expect($approve['changeRequest'])->toBe([__('proposals.change_request_not_pending')])
        ->and($reject['changeRequest'])->toBe([__('proposals.change_request_not_pending')])
        ->and($request->fresh()->decision_note)->toBe('The donor did not sign the new name.')
        ->and($proposal->fresh()->name)->toBe('Winter Shelter');
});

test('approve is refused while a review is waiting and passes with one remarks and one no objection', function () {
    decisionHoldBothSlots();
    $proposal = Proposal::factory()->running()->create(['name' => 'Winter Shelter']);
    $request = decisionRequest($proposal, ['name' => 'Winter Shelter Phase II']);
    decisionReviews($request, ChangeReviewVerdict::Remarks, null);

    $errors = refusedDecision(fn () => app(ApproveProposalChange::class)->handle($request));

    expect($errors['changeRequest'])->toBe([__('proposals.change_request_reviews_pending')])
        ->and($proposal->fresh()->name)->toBe('Winter Shelter');

    decisionReviews($request, ChangeReviewVerdict::Remarks, ChangeReviewVerdict::NoObjection);
    app(ApproveProposalChange::class)->handle($request);

    expect($request->fresh()->status)->toBe(ProposalChangeRequestStatus::Approved)
        ->and($proposal->fresh()->name)->toBe('Winter Shelter Phase II');
});

test('reject passes with no review in', function () {
    decisionHoldBothSlots();
    $proposal = Proposal::factory()->running()->create(['name' => 'Winter Shelter']);
    $request = decisionRequest($proposal, ['name' => 'Winter Shelter Phase II']);

    app(RejectProposalChange::class)->handle($request, 'The donor did not sign the new name.');

    expect($request->fresh()->status)->toBe(ProposalChangeRequestStatus::Rejected)
        ->and($request->reviews()->whereNull('verdict')->count())->toBe(2);
});

test('deciding a request only ever touches that request and its own proposal', function () {
    $proposalA = Proposal::factory()->running()->create(['name' => 'Winter Shelter']);
    $proposalB = Proposal::factory()->running()->create(['name' => 'Summer Camp']);
    $requestA = decisionRequest($proposalA, ['name' => 'Winter Shelter Phase II']);
    $requestB = decisionRequest($proposalB, ['name' => 'Summer Camp Phase II']);
    decisionReviews($requestA, ChangeReviewVerdict::NoObjection, ChangeReviewVerdict::NoObjection);
    decisionReviews($requestB, ChangeReviewVerdict::NoObjection, ChangeReviewVerdict::NoObjection);

    app(ApproveProposalChange::class)->handle($requestA);

    expect($proposalA->fresh()->name)->toBe('Winter Shelter Phase II')
        ->and($proposalB->fresh()->name)->toBe('Summer Camp')
        ->and($requestB->fresh()->status)->toBe(ProposalChangeRequestStatus::Pending)
        ->and($requestB->fresh()->decided_at)->toBeNull();
});

test('a review waiting in a slot with no holder is skipped inside approve and does not block', function () {
    holdMealManagerSlot();
    $proposal = Proposal::factory()->running()->create(['name' => 'Winter Shelter']);
    $request = decisionRequest($proposal, ['name' => 'Winter Shelter Phase II']);
    decisionReviews($request, null, ChangeReviewVerdict::NoObjection);

    app(ApproveProposalChange::class)->handle($request);

    expect($request->fresh()->status)->toBe(ProposalChangeRequestStatus::Approved)
        ->and($request->reviews()->where('slot', 'finance_manager')->sole()->verdict)->toBe(ChangeReviewVerdict::Skipped)
        ->and($proposal->fresh()->name)->toBe('Winter Shelter Phase II');
});

test('approving a beneficiaries change records proposal.beneficiaries_changed with the request reason', function () {
    $proposal = decisionLinkedProposal();
    $project = $proposal->projects()->sole();
    $request = decisionRequest($proposal, ['beneficiaries' => [$project->id => 650]]);
    $request->forceFill(['reason' => 'The donor widened the target group.'])->save();
    decisionReviews($request, ChangeReviewVerdict::NoObjection, ChangeReviewVerdict::NoObjection);

    app(ApproveProposalChange::class)->handle($request);

    $activity = Activity::query()->where('event', 'proposal.beneficiaries_changed')->sole();

    expect(DB::table('project_proposal')->where('proposal_id', $proposal->id)->value('target_beneficiaries'))->toBe(650)
        ->and($activity->properties['reason'])->toBe('The donor widened the target group.')
        ->and($activity->properties['projects'])->toEqual([
            ['project_id' => $project->id, 'name' => 'Water Network Rehabilitation', 'from' => 400, 'to' => 650],
        ]);
});

test('approve and reject each dispatch ProposalChangeDecided', function () {
    Event::fake([ProposalChangeDecided::class]);
    $first = Proposal::factory()->running()->create(['name' => 'Winter Shelter']);
    $second = Proposal::factory()->running()->create(['name' => 'Summer Camp']);
    $approved = decisionRequest($first, ['name' => 'Winter Shelter Phase II']);
    $rejected = decisionRequest($second, ['name' => 'Summer Camp Phase II']);
    decisionReviews($approved, ChangeReviewVerdict::NoObjection, ChangeReviewVerdict::NoObjection);

    app(ApproveProposalChange::class)->handle($approved);
    app(RejectProposalChange::class)->handle($rejected, 'The donor did not sign the new name.');

    Event::assertDispatched(ProposalChangeDecided::class, fn (ProposalChangeDecided $event): bool => $event->changeRequestId === $approved->id
        && $event->proposalId === $first->id
        && $event->actorId === $this->manager->id
        && $event->status === ProposalChangeRequestStatus::Approved);
    Event::assertDispatched(ProposalChangeDecided::class, fn (ProposalChangeDecided $event): bool => $event->changeRequestId === $rejected->id
        && $event->proposalId === $second->id
        && $event->status === ProposalChangeRequestStatus::Rejected);
});
