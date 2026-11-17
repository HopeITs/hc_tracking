<?php

declare(strict_types=1);

use App\Actions\Proposals\ReviewProposalChange;
use App\Enums\ChangeReviewVerdict;
use App\Enums\ProposalChangeRequestStatus;
use App\Events\ProposalChangeReviewed;
use App\Models\Activity;
use App\Models\ProposalChangeRequest;
use App\Models\ProposalChangeRequestReview;
use App\Models\User;
use App\Notifications\Alerts\NotificationSettings;
use App\Support\EmployeeProfile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/*
| Y-09 slice 1 (contracts §6, D-89a): the Finance Manager and the MEAL
| Manager each review a change request in their own slot. Advice only; the
| "ready for your decision" event goes out once, when the last waiting
| review comes in.
*/

/**
 * Put one HR employee on each review slot's position; null leaves a slot
 * vacant.
 *
 * @return array{finance: int|null, meal: int|null}
 */
function holdChangeReviewSlots(bool $finance = true, bool $meal = true): array
{
    $holders = ['finance' => null, 'meal' => null];
    $positions = ['finance' => null, 'meal' => null];

    if ($finance) {
        $holders['finance'] = insertHrEmployee(firstNameEn: 'Fadi', lastNameEn: 'Finance');
        $positions['finance'] = insertHrPosition(insertHrDepartment('Finance'), 'Finance Manager');
        insertHrContract($holders['finance'], $positions['finance']);
    }

    if ($meal) {
        $holders['meal'] = insertHrEmployee(firstNameEn: 'Maha', lastNameEn: 'Manager');
        $positions['meal'] = insertHrPosition(insertHrDepartment('Monitoring & Evaluation'), 'MEAL Manager');
        insertHrContract($holders['meal'], $positions['meal']);
    }

    app(NotificationSettings::class)->save(['proposal_default_reviewers' => [
        'meal_manager' => $positions['meal'], 'finance_manager' => $positions['finance'], 'fundraising_manager' => null, 'ceo' => null,
    ]]);

    EmployeeProfile::forgetRememberedLookup();

    return $holders;
}

/**
 * Review as the given user.
 */
function reviewChangeAs(User $user, ProposalChangeRequest $request, string $slot, string $verdict, ?string $note = null): ProposalChangeRequestReview
{
    test()->actingAs($user);

    return app(ReviewProposalChange::class)->handle($request, $slot, $verdict, $note);
}

beforeEach(function () {
    Event::fake([ProposalChangeReviewed::class]);

    $holders = holdChangeReviewSlots();
    $this->financeManager = userWithRole('Financial', $holders['finance']);
    $this->mealManager = userWithRole('MEAL', $holders['meal']);
    $this->changeRequest = ProposalChangeRequest::factory()->withReviews()->create();
});

it('is born with two waiting reviews', function () {
    expect($this->changeRequest->reviews()->pluck('slot')->sort()->values()->all())->toBe(['finance_manager', 'meal_manager'])
        ->and($this->changeRequest->reviews()->whereNull('verdict')->count())->toBe(2);
});

it('lets each slot holder record a verdict in their own slot', function () {
    $finance = reviewChangeAs($this->financeManager, $this->changeRequest, 'finance_manager', 'no_objection');
    $meal = reviewChangeAs($this->mealManager, $this->changeRequest, 'meal_manager', 'remarks', '  Check the target figures.  ');

    expect($finance->slot)->toBe('finance_manager')
        ->and($finance->verdict)->toBe(ChangeReviewVerdict::NoObjection)
        ->and($finance->note)->toBeNull()
        ->and($finance->reviewed_by)->toBe($this->financeManager->id)
        ->and($finance->reviewed_at)->not->toBeNull()
        ->and($meal->verdict)->toBe(ChangeReviewVerdict::Remarks)
        ->and($meal->note)->toBe('Check the target figures.')
        ->and($meal->reviewed_by)->toBe($this->mealManager->id);

    $activities = Activity::query()->where('event', 'proposal.change_reviewed')->orderBy('id')->get();

    expect($activities)->toHaveCount(2)
        ->and($activities[0]->properties)->toMatchArray(['change_request_id' => $this->changeRequest->id, 'slot' => 'finance_manager', 'verdict' => 'no_objection', 'ready' => false])
        ->and($activities[0]->properties)->not->toHaveKey('remarks')
        ->and($activities[1]->properties)->toMatchArray(['change_request_id' => $this->changeRequest->id, 'slot' => 'meal_manager', 'verdict' => 'remarks', 'ready' => true, 'remarks' => ['meal_manager']]);
});

it('refuses a holder reviewing in the other slot', function () {
    reviewChangeAs($this->financeManager, $this->changeRequest, 'meal_manager', 'no_objection');
})->throws(AuthorizationException::class);

it('refuses a verdict a person cannot give', function (string $verdict) {
    expect(fn () => reviewChangeAs($this->financeManager, $this->changeRequest, 'finance_manager', $verdict))
        ->toThrow(ValidationException::class, __('proposals.change_request_verdict_invalid'));

    expect($this->changeRequest->reviews()->whereNull('verdict')->count())->toBe(2);
})->with(['skipped', 'approve']);

it('refuses remarks without a note of ten characters', function (?string $note) {
    expect(fn () => reviewChangeAs($this->financeManager, $this->changeRequest, 'finance_manager', 'remarks', $note))
        ->toThrow(ValidationException::class, __('proposals.change_request_note_min'));

    expect($this->changeRequest->reviews()->whereNull('verdict')->count())->toBe(2);
})->with([null, '', '   short   ']);

it('refuses a user who holds no slot', function (string $role) {
    $user = userWithRole($role, insertHrEmployee());

    expect(fn () => reviewChangeAs($user, $this->changeRequest, 'finance_manager', 'no_objection'))
        ->toThrow(AuthorizationException::class);

    expect($this->changeRequest->reviews()->whereNull('verdict')->count())->toBe(2);
})->with(['Public Relations Manager', 'General Manager']);

it('lets a reviewer change their review until the decision', function () {
    reviewChangeAs($this->financeManager, $this->changeRequest, 'finance_manager', 'remarks', 'The budget line is wrong.');
    $review = reviewChangeAs($this->financeManager, $this->changeRequest, 'finance_manager', 'no_objection');

    expect($review->verdict)->toBe(ChangeReviewVerdict::NoObjection)
        ->and($review->note)->toBeNull()
        ->and($this->changeRequest->reviews()->where('slot', 'finance_manager')->count())->toBe(1);
});

it('refuses a review once the request is decided', function (ProposalChangeRequestStatus $status) {
    reviewChangeAs($this->financeManager, $this->changeRequest, 'finance_manager', 'no_objection');
    $this->changeRequest->forceFill(['status' => $status])->save();

    expect(fn () => reviewChangeAs($this->financeManager, $this->changeRequest, 'finance_manager', 'remarks', 'Too late to say this.'))
        ->toThrow(ValidationException::class, __('proposals.change_request_not_pending'));

    expect($this->changeRequest->reviews()->where('slot', 'finance_manager')->first()?->verdict)->toBe(ChangeReviewVerdict::NoObjection);
})->with([ProposalChangeRequestStatus::Approved, ProposalChangeRequestStatus::Rejected]);

it('skips an empty slot so it never blocks', function () {
    $holders = holdChangeReviewSlots(meal: false);
    $financeManager = userWithRole('Financial', $holders['finance']);

    reviewChangeAs($financeManager, $this->changeRequest, 'finance_manager', 'no_objection');

    expect($this->changeRequest->reviews()->where('slot', 'meal_manager')->first()?->verdict)->toBe(ChangeReviewVerdict::Skipped)
        ->and($this->changeRequest->reviews()->whereNull('verdict')->count())->toBe(0);

    Event::assertDispatchedTimes(ProposalChangeReviewed::class, 1);
});

it('raises the ready event once, after the second review only', function () {
    reviewChangeAs($this->financeManager, $this->changeRequest, 'finance_manager', 'no_objection');

    Event::assertNotDispatched(ProposalChangeReviewed::class);

    reviewChangeAs($this->mealManager, $this->changeRequest, 'meal_manager', 'no_objection');

    Event::assertDispatchedTimes(ProposalChangeReviewed::class, 1);
    Event::assertDispatched(ProposalChangeReviewed::class, fn (ProposalChangeReviewed $event): bool => $event->proposalId === $this->changeRequest->proposal_id
        && $event->changeRequestId === $this->changeRequest->id
        && $event->actorId === $this->mealManager->id);

    reviewChangeAs($this->financeManager, $this->changeRequest, 'finance_manager', 'remarks', 'On second thought, check it.');

    Event::assertDispatchedTimes(ProposalChangeReviewed::class, 1);

    $latest = Activity::query()->where('event', 'proposal.change_reviewed')->latest('id')->first();

    expect($latest?->properties['ready'] ?? null)->toBeFalse();
});

it('never touches a review of another request', function () {
    $other = ProposalChangeRequest::factory()->withReviews()->create();

    reviewChangeAs($this->financeManager, $this->changeRequest, 'finance_manager', 'no_objection');

    expect($other->reviews()->whereNotNull('verdict')->count())->toBe(0)
        ->and($other->reviews()->whereNull('reviewed_by')->count())->toBe(2);
});
