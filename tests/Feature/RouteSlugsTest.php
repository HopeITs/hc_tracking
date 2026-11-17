<?php

use App\Models\Task;
use Illuminate\Support\Facades\DB;

test('links to models loaded without their slug read every slug in one query', function () {
    $tasks = Task::factory()->count(3)->create();
    $narrow = Task::query()->select(['id'])->get();

    DB::enableQueryLog();
    $links = $narrow->map(fn (Task $task): string => route('tasks.show', $task));
    $slugQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'slug'));

    expect($slugQueries)->toHaveCount(1)
        ->and($links->all())->toBe($tasks->map(fn (Task $task): string => route('tasks.show', $task))->all());
});

test('a row created after the slugs were read still gets its own slug', function () {
    Task::factory()->create();
    route('tasks.show', Task::query()->select(['id'])->first());

    $later = Task::factory()->create();

    expect(route('tasks.show', Task::query()->select(['id'])->find($later->id)))
        ->toBe(route('tasks.show', $later));
});
