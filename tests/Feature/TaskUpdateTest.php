<?php

use App\Models\TaskUpdate;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Leave a comment on a task and return its id.
 */
function insertTaskUpdate(int $taskId, int $userId, string $comment = 'Distribution list verified with the field team.'): int
{
    return TaskUpdate::factory()->create([
        'task_id' => $taskId,
        'user_id' => $userId,
        'comment' => $comment,
    ])->id;
}

test('a comment is stamped with the moment it was left', function () {
    $user = User::factory()->create();
    $taskId = insertTask(insertProposal(), $user->id, $user->id);

    $update = DB::table('task_updates')->find(insertTaskUpdate($taskId, $user->id));

    expect($update->date)->toStartWith(DB::scalar('SELECT CURRENT_DATE'))
        ->and($update->comment)->toBe('Distribution list verified with the field team.');
});

test('a comment cannot be left empty', function () {
    $user = User::factory()->create();
    $taskId = insertTask(insertProposal(), $user->id, $user->id);

    DB::table('task_updates')->insert([
        'task_id' => $taskId,
        'user_id' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

test('deleting a task deletes its comment thread', function () {
    $user = User::factory()->create();
    $taskId = insertTask(insertProposal(), $user->id, $user->id);
    insertTaskUpdate($taskId, $user->id);

    DB::table('tasks')->where('id', $taskId)->delete();

    $this->assertDatabaseCount('task_updates', 0);
});

test('a user is not deletable while their comments remain', function () {
    $author = User::factory()->create();
    $commenter = User::factory()->create();
    $taskId = insertTask(insertProposal(), $author->id, $author->id);
    insertTaskUpdate($taskId, $commenter->id);

    expect(fn () => DB::table('users')->where('id', $commenter->id)->delete())
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('users', ['id' => $commenter->id]);
});
