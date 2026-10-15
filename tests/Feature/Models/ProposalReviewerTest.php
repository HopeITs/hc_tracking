<?php

use App\Enums\ReviewerSlot;
use App\Enums\ReviewStage;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

test('a person is a reviewer of one proposal stage at most once', function () {
    $row = ProposalReviewer::factory()->create();

    expect(fn () => ProposalReviewer::factory()->create(['proposal_id' => $row->proposal_id, 'stage' => $row->stage, 'employee_id' => $row->employee_id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('the same person may review both stages of one proposal', function () {
    $row = ProposalReviewer::factory()->create(['stage' => ReviewStage::ConceptNote]);

    ProposalReviewer::factory()->create(['proposal_id' => $row->proposal_id, 'stage' => ReviewStage::Proposal, 'employee_id' => $row->employee_id]);

    expect(DB::table('proposal_reviewers')->where('employee_id', $row->employee_id)->count())->toBe(2);
});

test('deleting a proposal deletes its reviewers', function () {
    $row = ProposalReviewer::factory()->create();

    Proposal::query()->findOrFail($row->proposal_id)->delete();

    expect(DB::table('proposal_reviewers')->find($row->id))->toBeNull();
});

test('deleting the user who added a reviewer keeps the row', function () {
    $user = User::factory()->create();
    $row = ProposalReviewer::factory()->create(['added_by' => $user->id]);

    DB::table('users')->where('id', $user->id)->delete();

    expect(DB::table('proposal_reviewers')->find($row->id)->added_by)->toBeNull();
});

test('a reviewer casts its stage, slot and seen time', function () {
    $row = ProposalReviewer::factory()->seen()->additional()->create(['stage' => ReviewStage::Proposal])->fresh();

    expect($row->stage)->toBe(ReviewStage::Proposal)
        ->and($row->slot)->toBe(ReviewerSlot::Additional)
        ->and($row->seen_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($row->proposal)->toBeInstanceOf(Proposal::class);
});
