<?php

use App\Models\ProposalStatusNote;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Record a status transition against a proposal, and return the note id.
 */
function insertProposalStatusNote(int $proposalId, string $status = 'submitted', string $context = 'Submitted through the donor portal.'): int
{
    return ProposalStatusNote::factory()->create([
        'proposal_id' => $proposalId,
        'status' => $status,
        'context' => $context,
    ])->id;
}

test('a status note is dated the day it was recorded', function () {
    $note = DB::table('proposal_status_notes')->find(insertProposalStatusNote(insertProposal()));

    expect($note->date)->toBe(DB::scalar('SELECT CURRENT_DATE'))
        ->and($note->status)->toBe('submitted')
        ->and($note->context)->toBe('Submitted through the donor portal.');
});

test('a status note can be backdated to when the transition happened', function () {
    $id = DB::table('proposal_status_notes')->insertGetId([
        'proposal_id' => insertProposal(),
        'status' => 'concept note',
        'context' => 'Concept note drafted during the response planning workshop.',
        'date' => '2025-10-09',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('proposal_status_notes')->find($id)->date)->toBe('2025-10-09');
});

test('a proposal keeps a full history of its transitions', function () {
    $proposalId = insertProposal();

    insertProposalStatusNote($proposalId, 'concept note', 'Drafted.');
    insertProposalStatusNote($proposalId, 'submitted', 'Sent to the donor.');
    insertProposalStatusNote($proposalId, 'awarded', 'Grant agreement countersigned.');

    expect(DB::table('proposal_status_notes')->where('proposal_id', $proposalId)->count())->toBe(3);
});

test('deleting a proposal deletes its status history', function () {
    $proposalId = insertProposal();
    insertProposalStatusNote($proposalId);

    DB::table('proposals')->where('id', $proposalId)->delete();

    $this->assertDatabaseCount('proposal_status_notes', 0);
});

test('a status note cannot be recorded without its context', function () {
    DB::table('proposal_status_notes')->insert([
        'proposal_id' => insertProposal(),
        'status' => 'rejected',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);
