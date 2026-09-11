<?php

use App\Enums\RagStatus;

/**
 * RagStatus has no column of its own — it is derived on read from two
 * percentages, exactly as ProposalPhase is derived from a status. This file
 * therefore tests the derivation, not a cast.
 */
test('an indicator keeping pace with the calendar rates green', function () {
    expect(RagStatus::rate(50.0, 50.0))->toBe(RagStatus::Green)
        ->and(RagStatus::rate(80.0, 50.0))->toBe(RagStatus::Green)
        ->and(RagStatus::rate(100.0, 100.0))->toBe(RagStatus::Green);
});

test('an indicator slightly behind the calendar rates amber', function () {
    // 40 of an expected 50 is 80% of pace: past the green threshold of 90%,
    // short of the red threshold of 70%.
    expect(RagStatus::rate(40.0, 50.0))->toBe(RagStatus::Amber);
});

test('an indicator well behind the calendar rates red', function () {
    expect(RagStatus::rate(10.0, 50.0))->toBe(RagStatus::Red)
        ->and(RagStatus::rate(0.0, 90.0))->toBe(RagStatus::Red);
});

test('a window that has not started cannot be behind schedule', function () {
    expect(RagStatus::rate(0.0, 0.0))->toBe(RagStatus::Green)
        ->and(RagStatus::rate(0.0, -5.0))->toBe(RagStatus::Green);
});

test('the same achievement rates differently against different elapsed time', function () {
    // This is the whole point of the enum: 40% achieved is fine early and
    // alarming late. A fixed threshold would call both the same.
    expect(RagStatus::rate(40.0, 30.0))->toBe(RagStatus::Green)
        ->and(RagStatus::rate(40.0, 50.0))->toBe(RagStatus::Amber)
        ->and(RagStatus::rate(40.0, 95.0))->toBe(RagStatus::Red);
});

test('the rag values are lower case and casing is part of the value', function () {
    expect(RagStatus::values())->toBe(['red', 'amber', 'green']);

    expect(fn () => RagStatus::from('Green'))->toThrow(ValueError::class);
});
