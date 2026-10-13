<?php

use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Proposal;
use App\Models\TransfersTracking;

test('a donor proposal resolves the donor_proposal table', function () {
    expect((new DonorProposal)->getTable())->toBe('donor_proposal');
});

test('a donor proposal belongs to a donor and a proposal', function () {
    $donor = Donor::factory()->create();
    $proposal = Proposal::factory()->create();

    Proposal::query()->whereKey($proposal->id)->update(['donor_id' => $donor->id]);
    $agreement = DonorProposal::factory()->create([
        'donor_id' => $donor->id,
        'proposal_id' => $proposal->id,
    ]);

    expect($agreement->donor->id)->toBe($donor->id)
        ->and($agreement->proposal->id)->toBe($proposal->id);
});

test('a donor proposal has its payment tranches', function () {
    $agreement = DonorProposal::factory()->create();

    TransfersTracking::factory(2)->create(['donor_proposal_id' => $agreement->id]);
    TransfersTracking::factory()->create();

    expect($agreement->transfers)->toHaveCount(2)
        ->and($agreement->transfers->first())->toBeInstanceOf(TransfersTracking::class);
});

test('a donor proposal casts its budget to two decimal places', function () {
    $agreement = DonorProposal::factory()->create(['budget' => '500000.5'])->fresh();

    expect($agreement->budget)->toBe('500000.50');
});
