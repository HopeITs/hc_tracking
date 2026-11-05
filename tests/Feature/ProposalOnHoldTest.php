<?php

use App\Actions\Proposals\ClearProposalOnHold;
use App\Actions\Proposals\SetProposalOnHold;
use App\Actions\Proposals\TransitionProposal;
use App\Enums\Currency;
use App\Enums\ProposalStatus;
use App\Enums\StatusNoteKind;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\Alerts\DeadlineCatalog;
use App\Notifications\Alerts\DeadlineItem;
use App\Notifications\Alerts\OnHoldReminderScanner;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
| W-06 (D-58): a hold is a flag on a proposal that keeps its status, not a
| status of its own. These cover the two writers of `proposals.on_hold_since`
| and everything that must clear it.
*/

/**
 * A proposal at the given status with one linked project, so the moves out of
 * it meet their §2 requirements.
 */
function holdableProposal(ProposalStatus $status = ProposalStatus::Submitted): Proposal
{
    $proposal = Proposal::factory()->create([
        'status' => $status,
        'requested_budget' => '120000.00',
        'requested_currency' => Currency::Usd,
    ]);
    $proposal->projects()->attach(Project::factory()->create()->id, ['target_beneficiaries' => 400]);

    return $proposal;
}

/**
 * The validation errors a refused call reports.
 *
 * @return array<string, list<string>>
 */
function refusedHold(callable $call): array
{
    try {
        $call();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('The call was not refused.');
}

function putOnHold(User $actor, Proposal $proposal, array $input = [], ?ProposalStatus $expected = null): mixed
{
    test()->actingAs($actor);

    return app(SetProposalOnHold::class)->handle($proposal, $expected ?? $proposal->status, [
        'reason' => 'Donor paused the call.',
        'remind_on' => '2026-12-01',
        ...$input,
    ]);
}

beforeEach(function () {
    $this->travelTo('2026-11-04 10:00:00');
    $this->actor = userWithRole('Financial');
});

test('a submitted proposal goes on hold without moving, and the note says so', function (ProposalStatus $status) {
    $proposal = holdableProposal($status);

    $note = putOnHold($this->actor, $proposal);

    expect($proposal->fresh())
        ->status->toBe($status)
        ->on_hold_since->not->toBeNull()
        ->and($proposal->fresh()->status_remind_on->toDateString())->toBe('2026-12-01')
        ->and($note->status)->toBe($status)
        ->and($note->kind)->toBe(StatusNoteKind::OnHold)
        ->and($note->changed_by)->toBe($this->actor->id)
        ->and($note->context)->toBe('Donor paused the call.');
})->with([
    'submitted' => ProposalStatus::Submitted,
    'concept note submitted' => ProposalStatus::ConceptNoteSubmitted,
]);

test('taking a proposal off hold clears the flag and the reminder, and keeps the status', function () {
    $proposal = holdableProposal();
    putOnHold($this->actor, $proposal);

    $note = app(ClearProposalOnHold::class)->handle($proposal->fresh(), ProposalStatus::Submitted, 'The donor came back.');

    expect($proposal->fresh())
        ->status->toBe(ProposalStatus::Submitted)
        ->on_hold_since->toBeNull()
        ->status_remind_on->toBeNull()
        ->and($note->status)->toBe(ProposalStatus::Submitted)
        ->and($note->kind)->toBe(StatusNoteKind::OffHold)
        ->and($note->context)->toBe('The donor came back.');
});

test('a hold is refused in any status but the two submitted ones', function (ProposalStatus $status) {
    $proposal = holdableProposal($status);

    expect(refusedHold(fn () => putOnHold($this->actor, $proposal)))->toHaveKey('status');
    expect($proposal->fresh()->on_hold_since)->toBeNull();
})->with([
    'concept note' => ProposalStatus::ConceptNote,
    'concept note review' => ProposalStatus::ConceptNoteReview,
    'proposal draft' => ProposalStatus::ProposalDraft,
    'proposal review' => ProposalStatus::ProposalReview,
    'approved' => ProposalStatus::Approved,
    'rejected' => ProposalStatus::Rejected,
]);

test('a remind date that is not in the future is refused', function (string $remindOn) {
    $proposal = holdableProposal();

    expect(refusedHold(fn () => putOnHold($this->actor, $proposal, ['remind_on' => $remindOn])))->toHaveKey('remind_on');
    expect($proposal->fresh()->on_hold_since)->toBeNull();
})->with(['today' => '2026-11-04', 'yesterday' => '2026-11-03']);

test('a hold without a reason is refused', function () {
    $proposal = holdableProposal();

    expect(refusedHold(fn () => putOnHold($this->actor, $proposal, ['reason' => '  '])))->toHaveKey('reason');
    expect($proposal->fresh()->on_hold_since)->toBeNull();
});

test('a stale expected status refuses both writers and changes nothing', function () {
    $proposal = holdableProposal();

    expect(refusedHold(fn () => putOnHold($this->actor, $proposal, [], ProposalStatus::ProposalReview)))->toHaveKey('status');
    expect($proposal->fresh()->on_hold_since)->toBeNull();

    putOnHold($this->actor, $proposal);

    expect(refusedHold(fn () => app(ClearProposalOnHold::class)->handle($proposal->fresh(), ProposalStatus::ProposalDraft)))->toHaveKey('status');
    expect($proposal->fresh()->on_hold_since)->not->toBeNull();
});

test('neither writer touches the status column', function () {
    $proposal = holdableProposal();
    $notesBefore = $proposal->statusNotes()->count();

    putOnHold($this->actor, $proposal);
    app(ClearProposalOnHold::class)->handle($proposal->fresh(), ProposalStatus::Submitted);

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Submitted)
        ->and($proposal->statusNotes()->count())->toBe($notesBefore + 2);
});

test('moving the proposal out of submitted clears the hold', function () {
    $proposal = holdableProposal();
    putOnHold($this->actor, $proposal);

    test()->actingAs($this->actor);
    app(TransitionProposal::class)->handle($proposal->fresh(), ProposalStatus::ProposalDraft, ProposalStatus::Submitted, ['reason' => 'The donor asked for a resubmission.']);

    expect($proposal->fresh())
        ->status->toBe(ProposalStatus::ProposalDraft)
        ->on_hold_since->toBeNull()
        ->status_remind_on->toBeNull();
});

test('approving a proposal that is on hold clears the flag', function () {
    $proposal = holdableProposal();
    putOnHold($this->actor, $proposal);

    expect($proposal->fresh()->canBeApproved())->toBeTrue();
});

test('a Team Member may not put a proposal on hold or take it off', function () {
    $member = userWithRole('Team Member');
    $proposal = holdableProposal();

    expect(fn () => putOnHold($member, $proposal))->toThrow(AuthorizationException::class);

    putOnHold($this->actor, $proposal);
    test()->actingAs($member);

    expect(fn () => app(ClearProposalOnHold::class)->handle($proposal->fresh(), ProposalStatus::Submitted))
        ->toThrow(AuthorizationException::class);

    expect($proposal->fresh()->on_hold_since)->not->toBeNull();
});

test('the proposal reads «Submitted (On hold)» only while the flag is set', function () {
    $proposal = holdableProposal();

    expect($proposal->isOnHold())->toBeFalse()
        ->and($proposal->statusLabel())->toBe(ProposalStatus::Submitted->label());

    putOnHold($this->actor, $proposal);

    expect($proposal->fresh())
        ->isOnHold()->toBeTrue()
        ->and($proposal->fresh()->statusLabel())->toBe(__('proposals.status_on_hold_label', ['status' => ProposalStatus::Submitted->label()]));
});

test('on hold is no longer a status anyone can move to', function () {
    expect(ProposalStatus::live())->not->toContain(ProposalStatus::OnHold)
        ->and(holdableProposal()->transitionTargets())->not->toContain(ProposalStatus::OnHold);
});

test('the reminder scanner reads the flag, not the status', function () {
    $proposal = holdableProposal();
    putOnHold($this->actor, $proposal, ['remind_on' => '2026-11-10']);

    $this->travelTo('2026-11-10 06:00:00');

    expect(app(OnHoldReminderScanner::class)->scan(now()->toImmutable()))->toBeGreaterThan(0);
});

/*
| The data migration that retires the status. RefreshDatabase runs it against
| an empty database, so these put the rows it is meant to find in place and
| call it directly.
*/

/**
 * A proposal left in the retired `on hold` status, with the trail it would
 * have had. Written through the query builder so no model event fires and
 * the stored value is exactly what a pre-W-06 row holds.
 */
function proposalStuckOnHold(?ProposalStatus $preHold, string $heldOn = '2026-09-20'): int
{
    $id = Proposal::factory()->create(['status' => ProposalStatus::Submitted])->id;
    DB::table('proposals')->where('id', $id)->update(['status' => 'on hold', 'status_remind_on' => '2026-12-15']);

    if ($preHold !== null) {
        DB::table('proposal_status_notes')->insert([
            'proposal_id' => $id, 'status' => $preHold->value, 'context' => 'Sent.',
            'date' => '2026-09-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    DB::table('proposal_status_notes')->insert([
        'proposal_id' => $id, 'status' => 'on hold', 'context' => 'Donor paused the call.',
        'date' => $heldOn, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function runOnHoldMigration(): void
{
    (require database_path('migrations/2026_11_04_103014_move_on_hold_proposals_to_pre_hold_status.php'))->up();
}

test('the migration puts each held proposal back at the status it was held from', function (ProposalStatus $preHold) {
    $id = proposalStuckOnHold($preHold);

    runOnHoldMigration();

    $row = DB::table('proposals')->where('id', $id)->first();

    expect($row->status)->toBe($preHold->value)
        ->and($row->on_hold_since)->toContain('2026-09-20')
        ->and($row->status_remind_on)->toContain('2026-12-15');
})->with([
    'held after the proposal was sent' => ProposalStatus::Submitted,
    'held after the concept note was sent' => ProposalStatus::ConceptNoteSubmitted,
]);

test('a held proposal whose trail shows no pre-hold status falls back to submitted', function () {
    $id = proposalStuckOnHold(null);

    runOnHoldMigration();

    expect(DB::table('proposals')->where('id', $id)->value('status'))->toBe('submitted');
});

test('the migration leaves every other proposal alone and writes one note each', function () {
    $held = proposalStuckOnHold(ProposalStatus::Submitted);
    $untouched = Proposal::factory()->create(['status' => ProposalStatus::Approved]);
    $notesBefore = DB::table('proposal_status_notes')->where('proposal_id', $held)->count();

    runOnHoldMigration();

    expect(DB::table('proposals')->where('status', 'on hold')->count())->toBe(0)
        ->and(DB::table('proposals')->whereNotNull('on_hold_since')->count())->toBe(1)
        ->and($untouched->fresh()->status)->toBe(ProposalStatus::Approved)
        ->and(DB::table('proposal_status_notes')->where('proposal_id', $held)->count())->toBe($notesBefore + 1)
        ->and(DB::table('proposal_status_notes')->where('proposal_id', $held)->orderByDesc('id')->value('kind'))->toBe('off_hold');
});

test('a held proposal is left out of the deadline chase, as it was while hold was a status', function () {
    $proposal = holdableProposal();
    $proposal->forceFill(['planned_date_end' => '2026-11-06'])->save();

    $named = fn (): bool => app(DeadlineCatalog::class)
        ->items(CarbonImmutable::parse('2026-11-30'))
        ->contains(fn (DeadlineItem $item): bool => $item->subjectType === 'proposal' && $item->subjectId === $proposal->id);

    expect($named())->toBeTrue();

    putOnHold($this->actor, $proposal);

    expect($named())->toBeFalse();
});
