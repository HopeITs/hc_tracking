<?php

use App\Models\Program;
use App\Models\Strategy;

test('a strategy has the programs delivered under it', function () {
    $strategy = Strategy::factory()->create();
    Program::factory(2)->create(['strategy_id' => $strategy->id]);
    Program::factory()->create();

    expect($strategy->programs)->toHaveCount(2)
        ->and($strategy->programs->first())->toBeInstanceOf(Program::class);
});

test('a strategy casts its years to integers', function () {
    $strategy = Strategy::factory()->create(['start_year' => 2021, 'end_year' => 2025])->fresh();

    expect($strategy->start_year)->toBe(2021)
        ->and($strategy->end_year)->toBe(2025);
});
