<?php

use App\Enums\Period;
use App\Enums\RagStatus;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\Project;
use App\Models\User;

test('an indicator reports its readings oldest first', function () {
    $indicator = Indicator::factory()->create();

    IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'date' => '2026-06-30', 'value' => '600.00']);
    IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'date' => '2026-03-31', 'value' => '300.00']);

    expect($indicator->progress->pluck('value')->all())->toBe(['300.00', '600.00']);
});

test('a reading belongs to its indicator and its recorder', function () {
    $indicator = Indicator::factory()->create();
    $user = User::factory()->create(['name' => 'Layla Haddad']);

    $reading = IndicatorProgress::factory()->create([
        'indicator_id' => $indicator->id,
        'recorded_by' => $user->id,
    ]);

    expect($reading->indicator->id)->toBe($indicator->id)
        ->and($reading->recorder->name)->toBe('Layla Haddad');
});

test('an indicator may be scoped to a project as well as its result', function () {
    $project = Project::factory()->create();
    $indicator = Indicator::factory()->forProject($project->id)->create();

    expect($indicator->project->id)->toBe($project->id)
        ->and($indicator->result)->not->toBeNull();
});

test('an indicator with no project scope reports null rather than failing', function () {
    expect(Indicator::factory()->create()->project)->toBeNull();
});

test('the latest value is the most recent reading', function () {
    $indicator = Indicator::factory()->create();

    IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'date' => '2026-03-31', 'value' => '300.00']);
    IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'date' => '2026-09-30', 'value' => '900.00']);

    expect($indicator->latestValue())->toBe(900.0);
});

test('the latest value answers from a loaded relation without a further query', function () {
    $indicator = Indicator::factory()->create();
    IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'value' => '750.00']);

    $loaded = Indicator::query()->whereKey($indicator->id)->with('progress')->sole();

    expect($loaded->latestValue())->toBe(750.0);
});

test('achievement is measured as movement from baseline to target', function () {
    $indicator = Indicator::factory()->create(['baseline' => '40.00', 'target' => '80.00']);

    IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'value' => '60.00']);

    // 60 sits halfway between a baseline of 40 and a target of 80. A raw share
    // of the target would have called this 75%.
    expect($indicator->percentAchieved())->toBe(50.0);
});

test('achievement is null when there is no target no reading or no movement asked for', function () {
    $noTarget = Indicator::factory()->untargeted()->create();
    IndicatorProgress::factory()->create(['indicator_id' => $noTarget->id]);

    $noReading = Indicator::factory()->create(['baseline' => '0.00', 'target' => '100.00']);

    $noMovement = Indicator::factory()->create(['baseline' => '50.00', 'target' => '50.00']);
    IndicatorProgress::factory()->create(['indicator_id' => $noMovement->id, 'value' => '50.00']);

    expect($noTarget->percentAchieved())->toBeNull()
        ->and($noReading->percentAchieved())->toBeNull()
        ->and($noMovement->percentAchieved())->toBeNull();
});

test('an indicator on schedule rates green and one well behind rates red', function () {
    $onSchedule = Indicator::factory()->create(['baseline' => '0.00', 'target' => '100.00']);
    IndicatorProgress::factory()->create(['indicator_id' => $onSchedule->id, 'value' => '50.00']);

    $behind = Indicator::factory()->create(['baseline' => '0.00', 'target' => '100.00']);
    IndicatorProgress::factory()->create(['indicator_id' => $behind->id, 'value' => '10.00']);

    // Half the window has elapsed: 50% achieved is on track, 10% is not.
    $start = new DateTimeImmutable('2026-01-01');
    $end = new DateTimeImmutable('2026-12-31');
    $halfway = new DateTimeImmutable('2026-07-01');

    expect($onSchedule->ragStatus($start, $end, $halfway))->toBe(RagStatus::Green)
        ->and($behind->ragStatus($start, $end, $halfway))->toBe(RagStatus::Red);
});

test('the same achievement rates differently as the window elapses', function () {
    $indicator = Indicator::factory()->create(['baseline' => '0.00', 'target' => '100.00']);
    IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'value' => '40.00']);

    $start = new DateTimeImmutable('2026-01-01');
    $end = new DateTimeImmutable('2026-12-31');

    // 40% achieved is fine in April and alarming in December. Rating against
    // elapsed time rather than a fixed threshold is the whole point.
    expect($indicator->ragStatus($start, $end, new DateTimeImmutable('2026-04-01')))->toBe(RagStatus::Green)
        ->and($indicator->ragStatus($start, $end, new DateTimeImmutable('2026-12-01')))->toBe(RagStatus::Red);
});

test('an indicator that cannot be measured has no rating at all', function () {
    $indicator = Indicator::factory()->untargeted()->create();

    expect($indicator->ragStatus(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-12-31')))->toBeNull();
});

test('an indicator casts its frequency baseline and target', function () {
    $indicator = Indicator::factory()
        ->measuredEvery(Period::Monthly)
        ->create(['baseline' => '10.5', 'target' => '99.5'])
        ->fresh();

    expect($indicator->frequency)->toBe(Period::Monthly)
        ->and($indicator->baseline)->toBe('10.50')
        ->and($indicator->target)->toBe('99.50');
});
