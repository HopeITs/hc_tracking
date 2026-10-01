<?php

use App\Enums\Currency;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a funding agreement defaults to USD', function () {
    $id = insertDonorProposal(insertDonor(), insertProposal(), '500000.00');

    $agreement = DB::table('donor_proposal')->find($id);

    expect($agreement->budget)->toBe('500000.00')
        ->and($agreement->currency)->toBe('USD');
});

test('a budget keeps its exact value at the top of the range', function () {
    $id = insertDonorProposal(insertDonor(), insertProposal(), '999999999999.99', Currency::Eur);

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

/**
 * proposal_id restricts rather than cascades so a single proposal delete cannot
 * reach through donor_proposal into transfers_tracking and erase the record of
 * money received. Removing the agreement first is a deliberate, separate act.
 */
test('a proposal cannot be deleted while a funding agreement points at it', function () {
    $donorId = insertDonor();
    $proposalId = insertProposal();
    insertDonorProposal($donorId, $proposalId);

    expect(fn () => DB::table('proposals')->where('id', $proposalId)->delete())
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('proposals', ['id' => $proposalId]);
    $this->assertDatabaseCount('donor_proposal', 1);
});

test('a proposal is deletable once its funding agreements are removed', function () {
    $donorId = insertDonor();
    $proposalId = insertProposal();
    $agreementId = insertDonorProposal($donorId, $proposalId);

    DB::table('donor_proposal')->where('id', $agreementId)->delete();
    DB::table('proposals')->where('id', $proposalId)->delete();

    $this->assertDatabaseCount('proposals', 0);
    $this->assertDatabaseHas('donors', ['id' => $donorId]);
});

test('one donor may fund the same proposal under several agreements', function () {
    $donorId = insertDonor();
    $proposalId = insertProposal();

    insertDonorProposal($donorId, $proposalId, '500000.00', Currency::Usd);
    insertDonorProposal($donorId, $proposalId, '120000.00', Currency::Eur);

    $this->assertDatabaseCount('donor_proposal', 2);
});

test('an agreement may be recorded before the donor issues a project number', function () {
    $id = insertDonorProposal(insertDonor(), insertProposal());

    expect(DB::table('donor_proposal')->find($id)->external_project_number)->toBeNull();
});

test('an agreement stores the donor project reference verbatim', function () {
    $id = insertDonorProposal(insertDonor(), insertProposal(), '500000.00', Currency::Eur, 'ECHO/SYR/BUD/2026/91008');

    expect(DB::table('donor_proposal')->find($id)->external_project_number)->toBe('ECHO/SYR/BUD/2026/91008');
});

test('two agreements may carry the same project number', function () {
    $proposalId = insertProposal();

    insertDonorProposal(insertDonor(), $proposalId, '500000.00', Currency::Usd, 'SYR-2026-014');
    insertDonorProposal(insertDonor('WFP'), $proposalId, '120000.00', Currency::Usd, 'SYR-2026-014');

    expect(DB::table('donor_proposal')->where('external_project_number', 'SYR-2026-014')->count())->toBe(2);
});
