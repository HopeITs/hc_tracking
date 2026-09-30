<?php

use App\Models\Milestone;
use Illuminate\Support\Facades\DB;

test('links to models loaded without their slug read every slug in one query', function () {
    $milestones = Milestone::factory()->count(3)->create();
    $narrow = Milestone::query()->select(['id'])->get();

    DB::enableQueryLog();
    $links = $narrow->map(fn (Milestone $milestone): string => route('milestones.show', $milestone));
    $slugQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'slug'));

    expect($slugQueries)->toHaveCount(1)
        ->and($links->all())->toBe($milestones->map(fn (Milestone $milestone): string => route('milestones.show', $milestone))->all());
});

test('a row created after the slugs were read still gets its own slug', function () {
    Milestone::factory()->create();
    route('milestones.show', Milestone::query()->select(['id'])->first());

    $later = Milestone::factory()->create();

    expect(route('milestones.show', Milestone::query()->select(['id'])->find($later->id)))
        ->toBe(route('milestones.show', $later));
});
