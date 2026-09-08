<?php

use App\Models\Task;
use App\Models\TaskAttachment;
use Carbon\CarbonImmutable;

test('a task attachment belongs to a task', function () {
    $task = Task::factory()->create();
    $attachment = TaskAttachment::factory()->create(['task_id' => $task->id]);

    expect($attachment->task)->toBeInstanceOf(Task::class)
        ->and($attachment->task->id)->toBe($task->id);
});

test('a task attachment casts its date to a date', function () {
    $attachment = TaskAttachment::factory()->create()->fresh();

    expect($attachment->date)->toBeInstanceOf(CarbonImmutable::class)
        ->and($attachment->date->toDateString())->toBe(now()->toDateString());
});
