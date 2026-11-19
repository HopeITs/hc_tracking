<?php

declare(strict_types=1);

use App\Enums\ChangeReviewVerdict;
use App\Enums\Currency;
use App\Enums\ProposalChangeRequestStatus;
use App\Models\Proposal;
use App\Models\ProposalChangeRequest;
use App\Models\User;
use App\Support\ProposalChangeLines;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| Y-09 slice 2: the change request modal and chapter on proposals/show. The
| slot holders review, the PR Manager approves or rejects, the requester
| withdraws; everyone else reads.
*/

beforeEach(function () {
    $holders = holdChangeReviewSlots();
    $this->financeManager = userWithRole('Financial', $holders['finance']);
    $this->mealManager = userWithRole('MEAL', $holders['meal']);
    $this->prManager = userWithRole('Public Relations Manager');
    $this->requester = userWithRole('Public Relations');
    $this->proposal = Proposal::factory()->running()->create(['name' => 'Winter Shelter']);
    $this->changeRequest = pendingChangeRequestOn($this->proposal, ['name' => 'Winter Shelter Phase II'], $this->requester);
});

function changeScreen(User $user, Proposal $proposal): Testable
{
    return Livewire::actingAs($user)->test('pages::proposals.show', ['proposal' => $proposal]);
}

/**
 * Record a verdict in each slot directly, for setup.
 */
function changeScreenReviews(ProposalChangeRequest $request, ?ChangeReviewVerdict $finance, ?ChangeReviewVerdict $meal): void
{
    foreach (['finance_manager' => $finance, 'meal_manager' => $meal] as $slot => $verdict) {
        $request->reviews()->where('slot', $slot)->update([
            'verdict' => $verdict?->value,
            'note' => $verdict === ChangeReviewVerdict::Remarks ? 'Check the end date with the donor.' : null,
            'reviewed_at' => $verdict === null ? null : now(),
        ]);
    }
}

it('lets each slot holder review in their own slot through the modal', function (string $holder, string $slot, string $verdict, ?string $note) {
    changeScreen($this->{$holder}, $this->proposal)
        ->call('openChangeRequestModal', $this->changeRequest->id)
        ->assertSet('showChangeRequestModal', true)
        ->assertSeeHtml('data-test="change-review-form"')
        ->set('reviewVerdict', $verdict)
        ->set('reviewNote', $note ?? '')
        ->call('submitChangeReview', $slot)
        ->assertHasNoErrors()
        ->assertSet('showChangeRequestModal', false);

    $review = $this->changeRequest->reviews()->where('slot', $slot)->sole();

    expect($review->verdict?->value)->toBe($verdict)
        ->and($review->reviewed_by)->toBe($this->{$holder}->id)
        ->and($this->changeRequest->reviews()->whereNull('verdict')->count())->toBe(1);
})->with([
    'finance' => ['financeManager', 'finance_manager', 'no_objection', null],
    'meal' => ['mealManager', 'meal_manager', 'remarks', 'Check the target figures.'],
]);

it('asks remarks for a note of ten characters', function () {
    changeScreen($this->financeManager, $this->proposal)
        ->call('openChangeRequestModal', $this->changeRequest->id)
        ->set('reviewVerdict', 'remarks')
        ->set('reviewNote', 'short')
        ->call('submitChangeReview', 'finance_manager')
        ->assertHasErrors(['reviewNote'])
        ->assertSet('showChangeRequestModal', true);

    expect($this->changeRequest->reviews()->whereNull('verdict')->count())->toBe(2);
});

it('refuses a review or a decision from someone who holds neither', function () {
    $fresh = fn (User $user): Testable => changeScreen($user, $this->proposal)->call('openChangeRequestModal', $this->changeRequest->id);

    $fresh($this->prManager)->set('reviewVerdict', 'no_objection')->call('submitChangeReview', 'finance_manager')->assertForbidden();
    $fresh($this->financeManager)->set('reviewVerdict', 'no_objection')->call('submitChangeReview', 'meal_manager')->assertForbidden();
    $fresh($this->financeManager)->call('approveChange')->assertForbidden();
    $fresh($this->requester)->set('rejectNote', 'Not what the donor signed.')->call('rejectChange')->assertForbidden();

    expect($this->changeRequest->fresh()->status)->toBe(ProposalChangeRequestStatus::Pending)
        ->and($this->changeRequest->reviews()->whereNull('verdict')->count())->toBe(2);
});

it('shows a viewer who neither reviews nor decides a read-only modal', function () {
    changeScreen(userWithRole('Public Relations'), $this->proposal)
        ->call('openChangeRequestModal', $this->changeRequest->id)
        ->assertSet('showChangeRequestModal', true)
        ->assertSeeHtml('data-test="change-read-only"')
        ->assertDontSeeHtml('data-test="change-review-form"')
        ->assertDontSeeHtml('data-test="approve-change"');
});

it('keeps Approve disabled, saying who is waiting, until both reviews are in', function () {
    changeScreenReviews($this->changeRequest, ChangeReviewVerdict::Remarks, null);

    changeScreen($this->prManager, $this->proposal)
        ->call('openChangeRequestModal', $this->changeRequest->id)
        ->assertSeeHtml('data-test="approve-blocked"')
        ->assertSeeText(__('proposals.change.approve_blocked', ['waiting' => __('proposals.change.waiting_for_meal')]))
        ->assertSeeHtmlInOrder(['data-test="approve-change"', 'disabled'])
        ->call('approveChange')
        ->assertHasErrors(['changeRequest']);

    expect($this->proposal->fresh()->name)->toBe('Winter Shelter');

    changeScreenReviews($this->changeRequest, ChangeReviewVerdict::Remarks, ChangeReviewVerdict::NoObjection);

    changeScreen($this->prManager, $this->proposal)
        ->call('openChangeRequestModal', $this->changeRequest->id)
        ->assertDontSeeHtml('data-test="approve-blocked"')
        ->call('confirmApproveChange')
        ->assertSet('showApproveChangeModal', true)
        ->call('approveChange')
        ->assertHasNoErrors()
        ->assertSet('showChangeRequestModal', false)
        ->assertSet('showApproveChangeModal', false);

    expect($this->changeRequest->fresh()->status)->toBe(ProposalChangeRequestStatus::Approved)
        ->and($this->proposal->fresh()->name)->toBe('Winter Shelter Phase II');
});

it('rejects only with a note of ten characters', function () {
    $screen = changeScreen($this->prManager, $this->proposal)
        ->call('openChangeRequestModal', $this->changeRequest->id)
        ->set('rejectNote', 'too short')
        ->call('rejectChange')
        ->assertHasErrors(['rejectNote']);

    expect($this->changeRequest->fresh()->status)->toBe(ProposalChangeRequestStatus::Pending);

    $screen->set('rejectNote', 'The donor did not sign the new name.')
        ->call('rejectChange')
        ->assertHasNoErrors()
        ->assertSet('showChangeRequestModal', false);

    expect($this->changeRequest->fresh())
        ->status->toBe(ProposalChangeRequestStatus::Rejected)
        ->decision_note->toBe('The donor did not sign the new name.')
        ->and($this->proposal->fresh()->name)->toBe('Winter Shelter');
});

it('lets the requester withdraw, and nobody else', function () {
    changeScreen($this->prManager, $this->proposal)
        ->call('openChangeRequestModal', $this->changeRequest->id)
        ->assertDontSeeHtml('data-test="withdraw-change"')
        ->call('withdrawChange')
        ->assertForbidden();

    changeScreen($this->requester, $this->proposal)
        ->call('openChangeRequestModal', $this->changeRequest->id)
        ->assertSeeHtml('data-test="withdraw-change"')
        ->call('confirmWithdrawChange')
        ->assertSet('showWithdrawChangeModal', true)
        ->call('withdrawChange')
        ->assertHasNoErrors()
        ->assertSet('showChangeRequestModal', false);

    expect($this->changeRequest->fresh()->status)->toBe(ProposalChangeRequestStatus::Rejected);
});

it('offers only Reject on a stale request', function () {
    changeScreenReviews($this->changeRequest, ChangeReviewVerdict::NoObjection, ChangeReviewVerdict::NoObjection);
    DB::table('proposals')->where('id', $this->proposal->id)->update(['name' => 'Winter Shelter Renamed']);

    changeScreen($this->prManager, $this->proposal)
        ->call('openChangeRequestModal', $this->changeRequest->id)
        ->call('approveChange')
        ->assertSet('changeIsStale', true)
        ->assertSeeText(__('proposals.change.stale_only_reject'))
        ->assertDontSeeHtml('data-test="approve-change"')
        ->assertSeeHtml('data-test="reject-change"');

    expect($this->changeRequest->fresh()->status)->toBe(ProposalChangeRequestStatus::Pending)
        ->and($this->proposal->fresh()->name)->toBe('Winter Shelter Renamed');
});

it('opens the pending request from the ?change-request=1 deep link, once', function () {
    Livewire::withQueryParams(['change-request' => '1'])
        ->actingAs($this->financeManager)
        ->test('pages::proposals.show', ['proposal' => $this->proposal])
        ->assertSet('showChangeRequestModal', true)
        ->assertSet('decidingChangeRequestId', $this->changeRequest->id)
        ->assertSet('changeRequestLink', false);
});

it('skips a vacant review when the decision screen opens', function () {
    holdChangeReviewSlots(meal: false);

    changeScreen($this->prManager, $this->proposal)
        ->call('openChangeRequestModal', $this->changeRequest->id)
        ->assertSeeText(__('proposals.change.no_holder'));

    expect($this->changeRequest->reviews()->where('slot', 'meal_manager')->sole()->verdict)->toBe(ChangeReviewVerdict::Skipped)
        ->and($this->changeRequest->reviews()->where('slot', 'finance_manager')->sole()->verdict)->toBeNull();
});

it('never opens a change request of another proposal', function () {
    $other = pendingChangeRequestOn(Proposal::factory()->running()->create(['name' => 'Summer Camp']), ['name' => 'Summer Camp II']);

    changeScreen($this->prManager, $this->proposal)
        ->call('openChangeRequestModal', $other->id)
        ->assertNotFound();
});

it('leaves the money lines out for a viewer without money', function () {
    $proposal = Proposal::factory()->running()->create(['name' => 'Water Point', 'requested_budget' => '120000.00', 'requested_currency' => Currency::Usd]);
    $request = pendingChangeRequestOn($proposal, ['name' => 'Water Point II', 'requestedBudget' => '150000'], $this->prManager);

    expect(collect($request->changes)->pluck('field')->all())->toContain('requestedBudget');

    changeScreen($this->requester, $proposal)
        ->call('openChangeRequestModal', $request->id)
        ->assertSeeHtml('data-change-line="name"')
        ->assertDontSeeHtml('data-change-line="requestedBudget"')
        ->assertDontSeeText('150,000.00');

    changeScreen($this->prManager, $proposal)
        ->call('openChangeRequestModal', $request->id)
        ->assertSeeHtml('data-change-line="requestedBudget"');
});

it('reads the chapter in a fixed number of queries however many requests it lists', function () {
    // The chapter sits in the folded "History & approvals" section; ?ptab=status opens it.
    $historyScreen = fn (Proposal $proposal) => Livewire::withQueryParams(['ptab' => 'status'])->actingAs($this->prManager)->test('pages::proposals.show', ['proposal' => $proposal]);

    $countQueries = function (Proposal $proposal) use ($historyScreen): int {
        $historyScreen($proposal);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $historyScreen($proposal)->assertSeeHtml('data-test="change-requests-chapter"');

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $seed = function (Proposal $proposal, int $count): void {
        foreach (range(1, $count) as $index) {
            $request = pendingChangeRequestOn($proposal, ['name' => 'Renamed '.$index], userWithRole('Public Relations'));
            $request->forceFill(['status' => ProposalChangeRequestStatus::Rejected, 'decided_by' => $this->prManager->id, 'decided_at' => now(), 'decision_note' => 'Not this time, thanks.'])->save();
            $request->reviews()->where('slot', 'finance_manager')->update(['verdict' => 'no_objection', 'reviewed_by' => $this->financeManager->id, 'reviewed_at' => now()]);
        }
    };

    $one = Proposal::factory()->running()->create();
    $twenty = Proposal::factory()->running()->create();
    $seed($one, 1);
    $seed($twenty, 20);

    expect($countQueries($twenty))->toBe($countQueries($one));
});

it('renders the decision modal lines from the one presenter', function () {
    $lines = ProposalChangeLines::for($this->changeRequest->changes, $this->prManager);

    $screen = changeScreen($this->prManager, $this->proposal)->call('openChangeRequestModal', $this->changeRequest->id);

    expect($screen->instance()->decidingChangeLines)->toBe($lines);
});
