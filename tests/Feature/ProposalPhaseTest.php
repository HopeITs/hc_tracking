<?php

use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;
use App\Models\Proposal;
use Illuminate\Support\Facades\Schema;

test('every status maps to the phase it belongs to', function (ProposalStatus $status, ProposalPhase $phase) {
    expect($status->phase())->toBe($phase);
})->with([
    'concept note' => [ProposalStatus::ConceptNote, ProposalPhase::Ideation],
    'concept note review' => [ProposalStatus::ConceptNoteReview, ProposalPhase::Ideation],
    'concept note submitted' => [ProposalStatus::ConceptNoteSubmitted, ProposalPhase::Ideation],
    'proposal draft' => [ProposalStatus::ProposalDraft, ProposalPhase::Planning],
    'proposal review' => [ProposalStatus::ProposalReview, ProposalPhase::Planning],
    'submitted' => [ProposalStatus::Submitted, ProposalPhase::Planning],
    'on hold' => [ProposalStatus::OnHold, ProposalPhase::Planning],
    'approved' => [ProposalStatus::Approved, ProposalPhase::Implementation],
    'active' => [ProposalStatus::Active, ProposalPhase::Monitoring],
    'final reports' => [ProposalStatus::FinalReports, ProposalPhase::Closure],
    'completed' => [ProposalStatus::Completed, ProposalPhase::Archived],
    'rejected' => [ProposalStatus::Rejected, ProposalPhase::Archived],
]);

test('a phase lists exactly the statuses that map onto it', function () {
    expect(ProposalPhase::Ideation->statuses())
        ->toBe([ProposalStatus::ConceptNote, ProposalStatus::ConceptNoteReview, ProposalStatus::ConceptNoteSubmitted])
        ->and(ProposalPhase::Planning->statuses())
        ->toBe([ProposalStatus::ProposalDraft, ProposalStatus::ProposalReview, ProposalStatus::Submitted, ProposalStatus::OnHold])
        ->and(ProposalPhase::Implementation->statuses())->toBe([ProposalStatus::Approved])
        ->and(ProposalPhase::Monitoring->statuses())->toBe([ProposalStatus::Active])
        ->and(ProposalPhase::Closure->statuses())->toBe([ProposalStatus::FinalReports])
        ->and(ProposalPhase::Archived->statuses())->toBe([ProposalStatus::Rejected, ProposalStatus::Completed]);
});

/**
 * phase() has no default arm, so a status added without a mapping raises
 * UnhandledMatchError. This proves the other direction: no status is silently
 * left out of every phase, and none is counted twice.
 */
test('every status belongs to exactly one phase', function () {
    $mapped = array_merge(...array_map(
        fn (ProposalPhase $phase): array => $phase->statuses(),
        ProposalPhase::cases(),
    ));

    expect($mapped)->toHaveCount(count(ProposalStatus::cases()));
});

test('every proposal status value fits the fifty character column', function () {
    foreach (ProposalStatus::values() as $value) {
        expect(strlen($value))->toBeLessThanOrEqual(50);
    }
});

test('the proposals status column default is a real status', function () {
    expect(ProposalStatus::tryFrom('concept note'))->toBe(ProposalStatus::ConceptNote);
});

test('the proposals table has no phase column', function () {
    expect(Schema::hasColumn('proposals', 'phase'))->toBeFalse();
});

test('a proposal reads its phase from its status', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Active]);

    expect($proposal->fresh()->phase)->toBe(ProposalPhase::Monitoring);
});

test('a new proposal is in the ideation phase', function () {
    expect(Proposal::factory()->create()->fresh()->phase)->toBe(ProposalPhase::Ideation);
});

/**
 * Reading phase must not require a round trip to the database. Account hit the
 * same trap: a column the factory omits is unhydrated until refresh, so the
 * model carries a matching $attributes default.
 */
test('a proposal created without a status still reports a phase', function () {
    expect(Proposal::factory()->create()->phase)->toBe(ProposalPhase::Ideation);
});

test('proposals are queryable by the statuses of a phase', function () {
    Proposal::factory()->create(['status' => ProposalStatus::Active]);
    Proposal::factory()->create(['status' => ProposalStatus::Approved]);

    $implementation = Proposal::query()
        ->whereIn('status', ProposalPhase::Implementation->statuses())
        ->get();

    expect($implementation)->toHaveCount(1)
        ->and($implementation->first()->status)->toBe(ProposalStatus::Approved);
});

test('each status names the stage it normally moves on to', function (ProposalStatus $status, ?ProposalStatus $next) {
    expect($status->next())->toBe($next);
})->with([
    'concept note' => [ProposalStatus::ConceptNote, ProposalStatus::ConceptNoteReview],
    'concept note review' => [ProposalStatus::ConceptNoteReview, ProposalStatus::ConceptNoteSubmitted],
    'concept note submitted' => [ProposalStatus::ConceptNoteSubmitted, ProposalStatus::ProposalDraft],
    'proposal draft' => [ProposalStatus::ProposalDraft, ProposalStatus::ProposalReview],
    'proposal review' => [ProposalStatus::ProposalReview, ProposalStatus::Submitted],
    'submitted' => [ProposalStatus::Submitted, ProposalStatus::Approved],
    'on hold' => [ProposalStatus::OnHold, null],
    'approved' => [ProposalStatus::Approved, ProposalStatus::Active],
    'rejected' => [ProposalStatus::Rejected, null],
    'active' => [ProposalStatus::Active, ProposalStatus::Completed],
    'final reports' => [ProposalStatus::FinalReports, null],
    'completed' => [ProposalStatus::Completed, null],
]);

test('the lifecycle is the main line in order, without the on hold, rejected and retired branches', function () {
    expect(ProposalStatus::lifecycle())->toBe(array_values(array_filter(
        ProposalStatus::cases(),
        fn (ProposalStatus $status): bool => ! in_array($status, [ProposalStatus::OnHold, ProposalStatus::Rejected, ProposalStatus::FinalReports], true),
    )));
});
