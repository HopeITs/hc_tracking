<?php

use App\Enums\ResultType;
use App\Exceptions\InvalidParentException;
use App\Models\Result;

test('a result cannot be its own parent', function () {
    $result = Result::factory()->create();

    expect(fn () => $result->update(['parent_id' => $result->id]))
        ->toThrow(InvalidParentException::class);
});

test('a result cannot be nested under one of its own descendants', function () {
    $root = Result::factory()->type(ResultType::Impact)->create();
    $child = Result::factory()->type(ResultType::Outcome)->childOf($root)->create();
    $grandchild = Result::factory()->type(ResultType::Output)->childOf($child)->create();

    expect(fn () => $root->update(['parent_id' => $grandchild->id]))
        ->toThrow(InvalidParentException::class);
});

test('a result is moved to a valid new parent', function () {
    $first = Result::factory()->type(ResultType::Outcome)->create();
    $second = Result::factory()->type(ResultType::Outcome)->create();
    $output = Result::factory()->type(ResultType::Output)->childOf($first)->create();

    $output->update(['parent_id' => $second->id]);

    expect($output->fresh()->parent_id)->toBe($second->id);
});

test('detaching a result from its parent is allowed', function () {
    $root = Result::factory()->create();
    $child = Result::factory()->childOf($root)->create();

    $child->update(['parent_id' => null]);

    expect($child->fresh()->parent_id)->toBeNull();
});

test('deleting a result deletes its whole subtree, not just one level', function () {
    $root = Result::factory()->create();
    $child = Result::factory()->childOf($root)->create();
    $grandchild = Result::factory()->childOf($child)->create();

    $root->delete();

    $this->assertDatabaseMissing('results', ['id' => $child->id])
        ->assertDatabaseMissing('results', ['id' => $grandchild->id]);
});
