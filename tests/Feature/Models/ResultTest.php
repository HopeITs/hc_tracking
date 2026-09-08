<?php

use App\Models\Indicator;
use App\Models\Program;
use App\Models\Result;
use Illuminate\Support\Facades\DB;

test('a result nests under a parent result', function () {
    $outcome = Result::factory()->type('outcome')->create();
    $output = Result::factory()->type('output')->create(['parent_id' => $outcome->id]);

    expect($output->parent->id)->toBe($outcome->id)
        ->and($outcome->children)->toHaveCount(1)
        ->and($outcome->children->first()->id)->toBe($output->id)
        ->and($outcome->parent)->toBeNull();
});

test('a result belongs to a program when it is not global', function () {
    $program = Program::factory()->create();

    expect(Result::factory()->create(['program_id' => $program->id])->program->id)->toBe($program->id)
        ->and(Result::factory()->create()->program)->toBeNull();
});

test('a result has the indicators that measure it', function () {
    $result = Result::factory()->create();

    Indicator::factory(2)->create(['result_id' => $result->id]);
    Indicator::factory()->create();

    expect($result->indicators)->toHaveCount(2)
        ->and($result->indicators->first())->toBeInstanceOf(Indicator::class);
});

test('a result reports its ancestors nearest first', function () {
    $impact = Result::factory()->type('impact')->create();
    $outcome = Result::factory()->type('outcome')->childOf($impact)->create();
    $output = Result::factory()->type('output')->childOf($outcome)->create();

    expect($output->ancestors()->pluck('id')->all())->toBe([$outcome->id, $impact->id])
        ->and($impact->ancestors())->toBeEmpty();
});

test('a result reports every descendant in its subtree', function () {
    $impact = Result::factory()->type('impact')->create();
    $outcome = Result::factory()->type('outcome')->childOf($impact)->create();
    $output = Result::factory()->type('output')->childOf($outcome)->create();
    Result::factory()->type('outcome')->create();

    expect($impact->descendants()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$outcome->id, $output->id])->sort()->values()->all())
        ->and($output->descendants())->toBeEmpty();
});

test('an unsaved result has no descendants', function () {
    Result::factory()->create();

    expect((new Result)->descendants())->toBeEmpty();
});

test('a result subtree eager-loaded through childrenRecursive costs no further queries', function () {
    $impact = Result::factory()->type('impact')->create();
    $outcome = Result::factory()->type('outcome')->childOf($impact)->create();
    Result::factory()->type('output')->childOf($outcome)->create();

    $root = Result::query()->with('childrenRecursive')->findOrFail($impact->id);

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect($root->descendants())->toHaveCount(2)
        ->and(DB::getQueryLog())->toBeEmpty();
});

test('a result knows its depth and whether it is a root or a leaf', function () {
    $impact = Result::factory()->type('impact')->create();
    $outcome = Result::factory()->type('outcome')->childOf($impact)->create();

    expect($impact->depth())->toBe(0)
        ->and($impact->isRoot())->toBeTrue()
        ->and($impact->isLeaf())->toBeFalse()
        ->and($outcome->depth())->toBe(1)
        ->and($outcome->isRoot())->toBeFalse()
        ->and($outcome->isLeaf())->toBeTrue();
});

test('the roots scope returns only top-level results', function () {
    $impact = Result::factory()->type('impact')->create();
    Result::factory()->type('outcome')->childOf($impact)->create();

    expect(Result::roots()->pluck('id')->all())->toBe([$impact->id]);
});

test('parentRecursive eager-loads a breadcrumb chain without a query per level', function () {
    $impact = Result::factory()->type('impact')->create();
    $outcome = Result::factory()->type('outcome')->childOf($impact)->create();
    $output = Result::factory()->type('output')->childOf($outcome)->create();

    $loaded = Result::query()->with('parentRecursive')->findOrFail($output->id);

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect($loaded->parentRecursive->id)->toBe($outcome->id)
        ->and($loaded->parentRecursive->parentRecursive->id)->toBe($impact->id)
        ->and($loaded->parentRecursive->parentRecursive->parentRecursive)->toBeNull()
        ->and(DB::getQueryLog())->toBeEmpty();
});

test('isLeaf answers from an eager-loaded subtree without querying', function () {
    $impact = Result::factory()->type('impact')->create();
    Result::factory()->type('outcome')->childOf($impact)->create();

    $root = Result::query()->with('childrenRecursive')->findOrFail($impact->id);

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect($root->isLeaf())->toBeFalse()
        ->and($root->childrenRecursive->first()->isLeaf())->toBeTrue()
        ->and(DB::getQueryLog())->toBeEmpty();
});
