<?php

use App\Models\Indicator;
use App\Models\Program;
use App\Models\Result;

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
