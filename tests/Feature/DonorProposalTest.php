<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a funding agreement defaults to USD', function () {
    $id = insertDonorProposal(insertDonor(), insertProposal(), '500000.00');

    $agreement = DB::table('donor_proposal')->find($id);

    expect($agreement->budget)->toBe('500000.00')
        ->and($agreement->currency)->toBe('USD');
});

test('a budget keeps its exact value at the top of the range', function () {
    $id = insertDonorProposal(insertDonor(), insertProposal(), '999999999999.99', 'EUR');

    $agreement = DB::table('donor_proposal')->find($id);

    expect($agreement->budget)->toBe('999999999999.99')
        ->and($agreement->currency)->toBe('EUR');
});

test('a donor is not deletable while it funds a proposal', function () {
    $donorId = insertDonor();
    insertDonorProposal($donorId, insertProposal());

    expect(fn () => DB::table('donors')->where('id', $donorId)->delete())
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('donors', ['id' => $donorId]);
});

test('deleting a proposal deletes its funding agreements but keeps the donor', function () {
    $donorId = insertDonor();
    $proposalId = insertProposal();
    insertDonorProposal($donorId, $proposalId);

    DB::table('proposals')->where('id', $proposalId)->delete();

    $this->assertDatabaseCount('donor_proposal', 0);
    $this->assertDatabaseHas('donors', ['id' => $donorId]);
});

test('one donor may fund the same proposal under several agreements', function () {
    $donorId = insertDonor();
    $proposalId = insertProposal();

    insertDonorProposal($donorId, $proposalId, '500000.00', 'USD');
    insertDonorProposal($donorId, $proposalId, '120000.00', 'EUR');

    $this->assertDatabaseCount('donor_proposal', 2);
});
