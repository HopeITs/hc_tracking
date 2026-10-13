<?php

use App\Enums\CollectionFrequency;
use App\Enums\DenominatorBasis;
use App\Enums\IndicatorCollector;
use App\Enums\IndicatorMeasure;
use App\Enums\ResultType;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

test('a library entry hydrates its enums, flags and follow-up months', function () {
    $entry = LibraryIndicator::factory()->percentage()->create([
        'collected_by' => IndicatorCollector::Meal,
        'denominator_basis' => DenominatorBasis::Entered,
        'frequency' => CollectionFrequency::FollowUp,
        'follow_up_months' => 6,
        'suggested_level' => ResultType::Output,
        'is_disaggregated' => true,
        'is_cumulative' => true,
    ])->fresh();

    expect($entry->measure)->toBe(IndicatorMeasure::Percentage)
        ->and($entry->collected_by)->toBe(IndicatorCollector::Meal)
        ->and($entry->denominator_basis)->toBe(DenominatorBasis::Entered)
        ->and($entry->frequency)->toBe(CollectionFrequency::FollowUp)
        ->and($entry->follow_up_months)->toBe(6)
        ->and($entry->suggested_level)->toBe(ResultType::Output)
        ->and($entry->is_disaggregated)->toBeTrue()
        ->and($entry->is_cumulative)->toBeTrue()
        ->and($entry->is_legacy)->toBeFalse()
        ->and($entry->is_active)->toBeTrue();
});

test('the columns default to numeric, project team, not cumulative, not legacy and active', function () {
    $id = DB::table('library_indicators')->insertGetId([
        'code' => 'HC.OP1.1',
        'level_ref' => 'OP1',
        'serial' => 1,
        'name_ar' => 'عدد الأسر المستفيدة',
        'frequency' => 'monthly',
    ]);

    $row = DB::table('library_indicators')->find($id);

    expect($row->measure)->toBe('numeric')
        ->and($row->collected_by)->toBe('project_team')
        ->and((int) $row->is_disaggregated)->toBe(0)
        ->and((int) $row->is_cumulative)->toBe(0)
        ->and((int) $row->is_legacy)->toBe(0)
        ->and((int) $row->is_active)->toBe(1)
        ->and($row->program_id)->toBeNull()
        ->and($row->name_en)->toBeNull()
        ->and($row->denominator_basis)->toBeNull();
});

test('two entries cannot share a code', function () {
    LibraryIndicator::factory()->create(['code' => 'HC.G.1']);

    LibraryIndicator::factory()->create(['code' => 'HC.G.1']);
})->throws(QueryException::class);

test('a library entry lists the indicators linked to it, and an indicator reads its library entry', function () {
    $entry = LibraryIndicator::factory()->create();
    $linked = Indicator::factory()->create(['library_indicator_id' => $entry->id]);
    Indicator::factory()->create();

    expect($entry->indicators->modelKeys())->toBe([$linked->id])
        ->and($linked->libraryIndicator->is($entry))->toBeTrue();
});

test('an entry an indicator points at cannot be deleted from the database', function () {
    $entry = LibraryIndicator::factory()->create();
    Indicator::factory()->create(['library_indicator_id' => $entry->id]);

    DB::table('library_indicators')->where('id', $entry->id)->delete();
})->throws(QueryException::class);

test('the active scope leaves deactivated entries out', function () {
    $active = LibraryIndicator::factory()->create();
    LibraryIndicator::factory()->inactive()->create();

    expect(LibraryIndicator::query()->active()->pluck('id')->all())->toBe([$active->id]);
});

test('the localized name follows the locale and falls back to arabic without an english name', function (string $locale, ?string $nameEn, string $expected) {
    $entry = LibraryIndicator::factory()->create(['name_ar' => 'عدد الأطفال', 'name_en' => $nameEn]);
    App::setLocale($locale);

    expect($entry->localizedName())->toBe($expected);
})->with([
    'english with an english name' => ['en', 'Number of children', 'Number of children'],
    'english without an english name' => ['en', null, 'عدد الأطفال'],
    'arabic' => ['ar', 'Number of children', 'عدد الأطفال'],
]);

test('a collection frequency names the target period it schedules, if any', function (CollectionFrequency $frequency, ?string $period) {
    expect($frequency->period()?->value)->toBe($period);
})->with([
    [CollectionFrequency::Monthly, 'monthly'],
    [CollectionFrequency::Quarterly, 'quarterly'],
    [CollectionFrequency::SemiAnnual, 'semi-annual'],
    [CollectionFrequency::Annual, 'annual'],
    [CollectionFrequency::PerEvent, null],
    [CollectionFrequency::PerSeason, null],
    [CollectionFrequency::FollowUp, null],
    [CollectionFrequency::BaselineEndline, null],
    [CollectionFrequency::OneTime, null],
]);

test('the collector decides the default denominator basis', function (IndicatorCollector $collector, DenominatorBasis $basis) {
    expect($collector->defaultDenominatorBasis())->toBe($basis);
})->with([
    'project team counts against the fixed target' => [IndicatorCollector::ProjectTeam, DenominatorBasis::Fixed],
    'MEAL enters numerator and denominator' => [IndicatorCollector::Meal, DenominatorBasis::Entered],
]);
