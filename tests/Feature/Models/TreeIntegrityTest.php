<?php

use App\Enums\ResultType;
use App\Exceptions\InvalidParentException;
use App\Models\Milestone;
use App\Models\Project;
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

test('a milestone cannot be its own parent', function () {
    $milestone = Milestone::factory()->create();

    expect(fn () => $milestone->update(['parent_milestone_id' => $milestone->id]))
        ->toThrow(InvalidParentException::class);
});

test('a milestone cannot be nested under one of its own descendants', function () {
    $root = Milestone::factory()->create();
    $child = Milestone::factory()->childOf($root)->create();

    expect(fn () => $root->update(['parent_milestone_id' => $child->id]))
        ->toThrow(InvalidParentException::class);
});

test('a milestone cannot be nested under a milestone in another project', function () {
    $parent = Milestone::factory()->create();
    $stranger = Milestone::factory()->create();

    expect($stranger->project_id)->not->toBe($parent->project_id);

    expect(fn () => $stranger->update(['parent_milestone_id' => $parent->id]))
        ->toThrow(InvalidParentException::class);
});

test('a new milestone is created under an existing parent without tripping the cycle guard', function () {
    $parent = Milestone::factory()->create();

    $child = Milestone::factory()->create([
        'project_id' => $parent->project_id,
        'parent_milestone_id' => $parent->id,
    ]);

    expect($child->parent->id)->toBe($parent->id);
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

test('deleting a milestone deletes its whole subtree, not just one level', function () {
    $root = Milestone::factory()->create();
    $child = Milestone::factory()->childOf($root)->create();
    $grandchild = Milestone::factory()->childOf($child)->create();

    $root->delete();

    $this->assertDatabaseMissing('milestones', ['id' => $child->id])
        ->assertDatabaseMissing('milestones', ['id' => $grandchild->id]);
});

test('deleting a result deletes its whole subtree, not just one level', function () {
    $root = Result::factory()->create();
    $child = Result::factory()->childOf($root)->create();
    $grandchild = Result::factory()->childOf($child)->create();

    $root->delete();

    $this->assertDatabaseMissing('results', ['id' => $child->id])
        ->assertDatabaseMissing('results', ['id' => $grandchild->id]);
});

test('a milestone nested under a parent in the same project is untouched by the project guard', function () {
    $project = Project::factory()->create();
    $parent = Milestone::factory()->create(['project_id' => $project->id]);
    $child = Milestone::factory()->create(['project_id' => $project->id]);

    $child->update(['parent_milestone_id' => $parent->id]);

    expect($child->fresh()->parent_milestone_id)->toBe($parent->id);
});
