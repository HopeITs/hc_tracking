<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Link a project to a proposal.
 */
function linkProjectToProposal(int $projectId, int $proposalId): void
{
    DB::table('project_proposal')->insert([
        'project_id' => $projectId,
        'proposal_id' => $proposalId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('a project is linked to a proposal', function () {
    $user = User::factory()->create();
    $projectId = insertProject(insertCity(), insertProgram(insertStrategy(), $user->id), $user->id);
    $proposalId = insertProposal();

    linkProjectToProposal($projectId, $proposalId);

    $this->assertDatabaseHas('project_proposal', [
        'project_id' => $projectId,
        'proposal_id' => $proposalId,
    ]);
});

test('deleting a project removes the link but keeps the proposal', function () {
    $user = User::factory()->create();
    $projectId = insertProject(insertCity(), insertProgram(insertStrategy(), $user->id), $user->id);
    $proposalId = insertProposal();
    linkProjectToProposal($projectId, $proposalId);

    DB::table('projects')->where('id', $projectId)->delete();

    $this->assertDatabaseCount('project_proposal', 0);
    $this->assertDatabaseHas('proposals', ['id' => $proposalId]);
});

test('deleting a proposal removes the link but keeps the project', function () {
    $user = User::factory()->create();
    $projectId = insertProject(insertCity(), insertProgram(insertStrategy(), $user->id), $user->id);
    $proposalId = insertProposal();
    linkProjectToProposal($projectId, $proposalId);

    DB::table('proposals')->where('id', $proposalId)->delete();

    $this->assertDatabaseCount('project_proposal', 0);
    $this->assertDatabaseHas('projects', ['id' => $projectId]);
});

test('the same project and proposal cannot be linked twice', function () {
    $user = User::factory()->create();
    $projectId = insertProject(insertCity(), insertProgram(insertStrategy(), $user->id), $user->id);
    $proposalId = insertProposal();
    linkProjectToProposal($projectId, $proposalId);

    expect(fn () => linkProjectToProposal($projectId, $proposalId))
        ->toThrow(QueryException::class);

    $this->assertDatabaseCount('project_proposal', 1);
});

test('one project may be linked to several proposals', function () {
    $user = User::factory()->create();
    $projectId = insertProject(insertCity(), insertProgram(insertStrategy(), $user->id), $user->id);

    linkProjectToProposal($projectId, insertProposal('Emergency WASH Response'));
    linkProjectToProposal($projectId, insertProposal('Shelter Rehabilitation'));

    $this->assertDatabaseCount('project_proposal', 2);
});
