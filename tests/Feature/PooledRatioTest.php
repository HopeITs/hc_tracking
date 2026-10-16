<?php

use App\Enums\DenominatorBasis;
use App\Enums\IndicatorMeasure;
use App\Enums\ReportStatus;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\PlanIndicator;
use App\Models\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| PlanIndicator::pooledRatioSql() and its PHP twin pooledRatio() (contracts
| §13 Pooling, D-25): Σ numerators ÷ Σ denominators over approved rows in the
| window; fixed basis sums the counts (latest when cumulative), entered basis
| takes the latest row; rows without a denominator are left out and counted.
*/

/**
 * A target on a new library entry of the given measure and basis.
 *
 * @param  array<string, mixed>  $library
 */
function prTarget(array $library = []): PlanIndicator
{
    return Activity::withoutRecording(function () use ($library): PlanIndicator {
        $entry = LibraryIndicator::factory()->create(['measure' => IndicatorMeasure::Percentage, ...$library]);
        $indicator = Indicator::factory()->create(['library_indicator_id' => $entry->id, 'measure' => $entry->measure]);

        return PlanIndicator::factory()->create(['indicator_id' => $indicator->id]);
    });
}

function prRow(PlanIndicator $target, string $month, ?float $numerator, ?float $denominator, ReportStatus $status = ReportStatus::Approved, ?float $value = null): void
{
    Activity::withoutRecording(fn () => Report::factory()->create([
        'plan_indicator_id' => $target->id,
        'status' => $status,
        'planned_date' => CarbonImmutable::parse($month)->endOfMonth()->toDateString(),
        'numerator' => $numerator,
        'denominator' => $denominator,
        'value' => $value ?? ($numerator !== null && $denominator ? round($numerator / $denominator * 100, 2) : null),
    ]));
}

/**
 * Both definitions over the given targets, Oct – Dec 2026.
 *
 * @param  list<PlanIndicator>  $targets
 * @return array{sql: array{ratio: ?float, excluded: int}, php: array{ratio: ?float, excluded: int}}
 */
function prPool(array $targets): array
{
    [$from, $to] = [CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-12-31')];
    $ids = array_map(fn (PlanIndicator $target): int => $target->id, $targets);
    $grammar = DB::connection()->getQueryGrammar();

    $row = PlanIndicator::query()->whereKey($ids)->toBase()
        ->selectRaw(PlanIndicator::pooledRatioSql($from, $to)->getValue($grammar).' as ratio')
        ->selectRaw(PlanIndicator::pooledExcludedCountSql($from, $to)->getValue($grammar).' as excluded')
        ->first();

    $loaded = PlanIndicator::query()->whereKey($ids)->with(['reports', 'indicator.libraryIndicator'])->get();

    return [
        'sql' => ['ratio' => $row->ratio === null ? null : round((float) $row->ratio, 4), 'excluded' => (int) $row->excluded],
        'php' => (function (array $result): array {
            return ['ratio' => $result['ratio'] === null ? null : round($result['ratio'], 4), 'excluded' => $result['excluded']];
        })(PlanIndicator::pooledRatio($loaded, $from, $to)),
    ];
}

test('fixed basis sums the monthly counts over each target\'s fixed denominator', function () {
    $first = prTarget(['denominator_basis' => DenominatorBasis::Fixed]);
    $second = prTarget(['denominator_basis' => DenominatorBasis::Fixed]);
    prRow($first, '2026-10-01', 36, 180);
    prRow($first, '2026-11-01', 18, 180);
    prRow($second, '2026-10-01', 10, 100);

    $pooled = prPool([$first, $second]);

    expect($pooled['sql'])->toBe(['ratio' => round(64 / 280 * 100, 4), 'excluded' => 0])
        ->and($pooled['php'])->toBe($pooled['sql']);
});

test('a cumulative fixed-basis target takes its latest count', function () {
    $target = prTarget(['denominator_basis' => DenominatorBasis::Fixed, 'is_cumulative' => true]);
    prRow($target, '2026-10-01', 36, 180);
    prRow($target, '2026-11-01', 45, 180);

    $pooled = prPool([$target]);

    expect($pooled['sql'])->toBe(['ratio' => 25.0, 'excluded' => 0])
        ->and($pooled['php'])->toBe($pooled['sql']);
});

test('entered basis takes the latest approved row; draft, reviewed and out-of-window rows are ignored', function () {
    $first = prTarget(['denominator_basis' => DenominatorBasis::Entered]);
    $second = prTarget(['denominator_basis' => DenominatorBasis::Entered]);
    prRow($first, '2026-10-01', 9, 10);
    prRow($first, '2026-11-01', 4, 8);
    prRow($first, '2026-12-01', 1, 100, ReportStatus::Reviewed);
    prRow($first, '2027-01-01', 1, 100);
    prRow($second, '2026-11-01', 1, 2);
    prRow($second, '2026-12-01', 2, 2, ReportStatus::Draft);

    $pooled = prPool([$first, $second]);

    expect($pooled['sql'])->toBe(['ratio' => 50.0, 'excluded' => 0])
        ->and($pooled['php'])->toBe($pooled['sql']);
});

test('targets whose approved rows carry no denominator are left out and counted', function () {
    $measured = prTarget(['denominator_basis' => DenominatorBasis::Entered]);
    $noDenominator = prTarget(['measure' => IndicatorMeasure::Numeric]);
    $silent = prTarget(['denominator_basis' => DenominatorBasis::Entered]);
    prRow($measured, '2026-10-01', 3, 4);
    prRow($noDenominator, '2026-10-01', null, null, value: 12);

    $pooled = prPool([$measured, $noDenominator, $silent]);

    expect($pooled['sql'])->toBe(['ratio' => 75.0, 'excluded' => 1])
        ->and($pooled['php'])->toBe($pooled['sql']);
});

test('nothing measured pools to null', function () {
    $pooled = prPool([prTarget(['denominator_basis' => DenominatorBasis::Entered])]);

    expect($pooled['sql'])->toBe(['ratio' => null, 'excluded' => 0])
        ->and($pooled['php'])->toBe($pooled['sql']);
});
