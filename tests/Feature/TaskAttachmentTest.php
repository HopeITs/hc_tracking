<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Attach a file to a task and return the attachment id.
 */
function insertTaskAttachment(int $taskId, string $path = 'task-attachments/2026/distribution-list.pdf'): int
{
    return DB::table('task_attachments')->insertGetId([
        'task_id' => $taskId,
        'path' => $path,
        'type' => 'pdf',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('an attachment defaults to the date it was uploaded', function () {
    $user = User::factory()->create();
    $taskId = insertTask(insertProposal(), $user->id, $user->id);

    $attachment = DB::table('task_attachments')->find(insertTaskAttachment($taskId));

    expect($attachment->date)->toBe(DB::scalar('SELECT CURRENT_DATE'))
        ->and($attachment->path)->toBe('task-attachments/2026/distribution-list.pdf')
        ->and($attachment->type)->toBe('pdf');
});

test('an attachment can be backdated to the document it holds', function () {
    $user = User::factory()->create();
    $taskId = insertTask(insertProposal(), $user->id, $user->id);

    $id = DB::table('task_attachments')->insertGetId([
        'task_id' => $taskId,
        'path' => 'task-attachments/2025/signed-waybill.pdf',
        'type' => 'pdf',
        'date' => '2025-11-04',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('task_attachments')->find($id)->date)->toBe('2025-11-04');
});

test('the same file may be attached to a task more than once', function () {
    $user = User::factory()->create();
    $taskId = insertTask(insertProposal(), $user->id, $user->id);

    insertTaskAttachment($taskId);
    insertTaskAttachment($taskId);

    $this->assertDatabaseCount('task_attachments', 2);
});

test('deleting a task deletes its attachments', function () {
    $user = User::factory()->create();
    $taskId = insertTask(insertProposal(), $user->id, $user->id);
    insertTaskAttachment($taskId);

    DB::table('tasks')->where('id', $taskId)->delete();

    $this->assertDatabaseCount('task_attachments', 0);
});

test('an attachment cannot be stored without a path', function () {
    $user = User::factory()->create();
    $taskId = insertTask(insertProposal(), $user->id, $user->id);

    DB::table('task_attachments')->insert([
        'task_id' => $taskId,
        'type' => 'pdf',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);
