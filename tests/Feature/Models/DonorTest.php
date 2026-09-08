<?php

use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Proposal;

test('a donor has its funding agreements', function () {
    $donor = Donor::factory()->create();
    DonorProposal::factory(2)->create(['donor_id' => $donor->id]);
    DonorProposal::factory()->create();

    expect($donor->donorProposals)->toHaveCount(2)
        ->and($donor->donorProposals->first())->toBeInstanceOf(DonorProposal::class);
});

test('a donor reaches its proposals through the donor_proposal table', function () {
    $donor = Donor::factory()->create();
    $proposal = Proposal::factory()->create();

    DonorProposal::factory()->create([
        'donor_id' => $donor->id,
        'proposal_id' => $proposal->id,
        'budget' => '750000.00',
        'project_num' => 'PRJ-0001',
    ]);

    $linked = $donor->proposals()->sole();

    expect($linked->id)->toBe($proposal->id)
        ->and($linked->pivot->budget)->toBe('750000.00')
        ->and($linked->pivot->currency)->toBe('USD')
        ->and($linked->pivot->project_num)->toBe('PRJ-0001');
});
