<?php

use App\Models\Task;
use App\Models\TaskUpdate;
use App\Models\User;
use Carbon\CarbonImmutable;

test('a task update belongs to a task and a user', function () {
    $task = Task::factory()->create();
    $user = User::factory()->create();

    $update = TaskUpdate::factory()->create([
        'task_id' => $task->id,
        'user_id' => $user->id,
    ]);

    expect($update->task->id)->toBe($task->id)
        ->and($update->user)->toBeInstanceOf(User::class)
        ->and($update->user->id)->toBe($user->id);
});

test('a task update casts its date to a datetime', function () {
    $update = TaskUpdate::factory()->create()->fresh();

    expect($update->date)->toBeInstanceOf(CarbonImmutable::class);
});
