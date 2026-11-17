<?php

use App\Actions\Proposals\RequestProposalChange;
use App\Actions\Proposals\WithdrawProposalChange;
use App\Enums\ChangeReviewVerdict;
use App\Enums\ProposalChangeRequestStatus;
use App\Enums\ProposalStatus;
use App\Events\ProposalChangeDecided;
use App\Events\ProposalChangeRequested;
use App\Models\Activity;
use App\Models\Proposal;
use App\Models\ProposalChangeRequest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/*
| Y-09 slice 1 (D-89, D-89a): a change to an approved proposal is asked for,
| stored as `pending` with its two reviews, and changes nothing until decided.
| The requester may withdraw it; nothing is deleted.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->role('Public Relations')->create();
    $this->actingAs($this->user);

    $this->proposal = Proposal::factory()->running()->create(['name' => 'Original name', 'requested_budget' => 1000, 'requested_currency' => 'USD']);
});

/**
 * Ask for a change as the acting user.
 *
 * @param  array<string, mixed>  $data
 */
function requestChange(Proposal $proposal, array $data = ['name' => 'Renamed proposal'], string $reason = 'The donor asked for a new title.', ?string $expectedUpdatedAt = null): ProposalChangeRequest
{
    return app(RequestProposalChange::class)->handle($proposal, $data, $reason, $expectedUpdatedAt);
}

test('a request is stored pending with its from and to and leaves the proposal unchanged', function () {
    Event::fake([ProposalChangeRequested::class]);

    $request = requestChange($this->proposal);

    expect($request->status)->toBe(ProposalChangeRequestStatus::Pending)
        ->and($request->requested_by)->toBe($this->user->id)
        ->and($request->reason)->toBe('The donor asked for a new title.')
        ->and($request->changes)->toBe([['field' => 'name', 'from' => 'Original name', 'to' => 'Renamed proposal']])
        ->and($request->payload)->toBe(['name' => 'Renamed proposal'])
        ->and($this->proposal->fresh()->name)->toBe('Original name');

    Event::assertDispatched(ProposalChangeRequested::class, fn (ProposalChangeRequested $event): bool => $event->changeRequestId === $request->id
        && $event->proposalId === $this->proposal->id
        && $event->actorId === $this->user->id);
});

test('a request is born with its two review rows', function () {
    holdMealManagerSlot();

    $request = requestChange($this->proposal);
    $reviews = $request->reviews()->orderBy('id')->get();

    expect($reviews->pluck('slot')->all())->toBe(ProposalChangeRequest::ReviewSlots)
        ->and($reviews->firstWhere('slot', 'meal_manager')->verdict)->toBeNull();
});

test('a vacant slot review is born skipped', function () {
    holdMealManagerSlot();

    $request = requestChange($this->proposal);
    $finance = $request->reviews()->where('slot', 'finance_manager')->sole();

    expect($finance->verdict)->toBe(ChangeReviewVerdict::Skipped)
        ->and($finance->reviewed_at)->not->toBeNull()
        ->and($request->reviews()->where('slot', 'meal_manager')->sole()->verdict)->toBeNull();
});

test('a reason under ten characters is refused', function () {
    expect(fn () => requestChange($this->proposal, reason: '  too short '))
        ->toThrow(ValidationException::class, __('proposals.change_request_reason_min'));

    expect(ProposalChangeRequest::query()->count())->toBe(0);
});

test('a request that changes nothing is refused', function () {
    expect(fn () => requestChange($this->proposal, ['name' => 'Original name']))
        ->toThrow(ValidationException::class, __('proposals.change_request_empty'));

    expect(ProposalChangeRequest::query()->count())->toBe(0);
});

test('a second pending request is refused', function () {
    requestChange($this->proposal);

    expect(fn () => requestChange($this->proposal, ['name' => 'Another name']))
        ->toThrow(ValidationException::class, __('proposals.change_request_pending'));

    expect(ProposalChangeRequest::query()->count())->toBe(1);
});

test('a user without proposals.update is forbidden', function () {
    $this->actingAs(User::factory()->role('Media Team')->create());

    expect(fn () => requestChange($this->proposal))->toThrow(AuthorizationException::class);
});

test('a proposal the donor has not approved is forbidden', function () {
    $this->proposal->forceFill(['status' => ProposalStatus::Submitted])->save();

    expect(fn () => requestChange($this->proposal))->toThrow(AuthorizationException::class);

    expect(ProposalChangeRequest::query()->count())->toBe(0);
});

test('a stale expected updated_at is refused', function () {
    expect(fn () => requestChange($this->proposal, expectedUpdatedAt: '2000-01-01 00:00:00'))
        ->toThrow(ValidationException::class, __('proposals.stale_status'));

    expect(ProposalChangeRequest::query()->count())->toBe(0);
});

test('a matching expected updated_at is accepted', function () {
    $request = requestChange($this->proposal, expectedUpdatedAt: $this->proposal->updated_at->toDateTimeString());

    expect($request->isPending())->toBeTrue();
});

test('the activity names the changed fields and holds no amount', function () {
    $this->actingAs(User::factory()->role('Financial')->create());

    $request = requestChange($this->proposal, ['requestedBudget' => '98765.43', 'requestedCurrency' => 'USD']);
    $activity = Activity::query()->where('event', 'proposal.change_requested')->sole();

    expect($request->changes[0]['field'])->toBe('requestedBudget')
        ->and($activity->properties)->toMatchArray(['change_request_id' => $request->id, 'fields' => ['requestedBudget']])
        ->and(json_encode($activity->toArray()))->not->toContain('98765')
        ->and(json_encode($activity->toArray()))->not->toContain('1000.00');
});

test('the requester withdraws a pending request without deleting it', function () {
    Event::fake([ProposalChangeDecided::class]);
    $request = requestChange($this->proposal);

    $withdrawn = app(WithdrawProposalChange::class)->handle($request);

    expect($withdrawn->status)->toBe(ProposalChangeRequestStatus::Rejected)
        ->and($withdrawn->decided_by)->toBe($this->user->id)
        ->and($withdrawn->decided_at)->not->toBeNull()
        ->and($withdrawn->decision_note)->toBe(__('proposals.change_request_withdrawn_note'))
        ->and(ProposalChangeRequest::query()->count())->toBe(1)
        ->and($request->reviews()->count())->toBe(2)
        ->and($this->proposal->fresh()->name)->toBe('Original name')
        ->and(Activity::query()->where('event', 'proposal.change_rejected')->sole()->properties)
        ->toMatchArray(['change_request_id' => $request->id, 'withdrawn' => true]);

    Event::assertDispatched(ProposalChangeDecided::class, fn (ProposalChangeDecided $event): bool => $event->changeRequestId === $request->id
        && $event->status === ProposalChangeRequestStatus::Rejected);
});

test('someone other than the requester may not withdraw', function () {
    $request = requestChange($this->proposal);
    $this->actingAs(User::factory()->role('Public Relations Manager')->create());

    expect(fn () => app(WithdrawProposalChange::class)->handle($request))->toThrow(AuthorizationException::class);

    expect($request->fresh()->isPending())->toBeTrue();
});

test('a decided request cannot be withdrawn', function () {
    $request = requestChange($this->proposal);
    $request->forceFill(['status' => ProposalChangeRequestStatus::Approved])->save();

    expect(fn () => app(WithdrawProposalChange::class)->handle($request))
        ->toThrow(ValidationException::class, __('proposals.change_request_not_pending'));

    expect($request->fresh()->status)->toBe(ProposalChangeRequestStatus::Approved);
});
