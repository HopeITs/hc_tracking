<?php

use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use Carbon\CarbonImmutable;

test('a status note belongs to a proposal', function () {
    $proposal = Proposal::factory()->create();
    $note = ProposalStatusNote::factory()->create(['proposal_id' => $proposal->id]);

    expect($note->proposal)->toBeInstanceOf(Proposal::class)
        ->and($note->proposal->id)->toBe($proposal->id);
});

test('a status note casts its date to a date', function () {
    $note = ProposalStatusNote::factory()->create()->fresh();

    expect($note->date)->toBeInstanceOf(CarbonImmutable::class)
        ->and($note->date->toDateString())->toBe(now()->toDateString());
});
