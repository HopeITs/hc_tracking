<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a new task starts as todo at medium priority with no progress', function () {
    $user = User::factory()->create();

    $task = DB::table('tasks')->find(insertTask(insertProposal(), $user->id, $user->id));

    expect($task->status)->toBe('todo')
        ->and($task->priority)->toBe('medium')
        ->and((int) $task->progress_percentage)->toBe(0)
        ->and($task->milestone_id)->toBeNull()
        ->and($task->description)->toBeNull()
        ->and($task->start_date)->toBeNull()
        ->and($task->due_date)->toBeNull()
        ->and($task->completed_date)->toBeNull();
});

test('deleting a milestone keeps its tasks but unlinks them', function () {
    $user = User::factory()->create();
    $projectId = insertProject(insertCity(), insertProgram(insertStrategy(), $user->id), $user->id);
    $milestoneId = insertMilestone($projectId, $user->id);
    $taskId = insertTask(insertProposal(), $user->id, $user->id, $milestoneId);

    DB::table('milestones')->where('id', $milestoneId)->delete();

    $this->assertDatabaseHas('tasks', ['id' => $taskId, 'milestone_id' => null]);
});

test('deleting a proposal deletes the tasks under it', function () {
    $user = User::factory()->create();
    $proposalId = insertProposal();
    insertTask($proposalId, $user->id, $user->id);

    DB::table('proposals')->where('id', $proposalId)->delete();

    $this->assertDatabaseCount('tasks', 0);
});

test('a user is not deletable while a task is assigned to them', function () {
    $author = User::factory()->create();
    $assignee = User::factory()->create();
    insertTask(insertProposal(), $assignee->id, $author->id);

    expect(fn () => DB::table('users')->where('id', $assignee->id)->delete())
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('users', ['id' => $assignee->id]);
});

test('a user is not deletable while a task they created still exists', function () {
    $author = User::factory()->create();
    $assignee = User::factory()->create();
    insertTask(insertProposal(), $assignee->id, $author->id);

    expect(fn () => DB::table('users')->where('id', $author->id)->delete())
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('users', ['id' => $author->id]);
});
