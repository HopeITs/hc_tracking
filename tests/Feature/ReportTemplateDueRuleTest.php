<?php

use App\Enums\CollectionFrequency;
use App\Enums\LineSchedule;
use App\Enums\ReportTemplateLineStatus;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\PlanIndicator;
use App\Models\ReportTemplateLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| ReportTemplateLine::isDueIn() (contracts.md §12.4) is pure: it reads the
| line and the library entry its target's indicator links to, which callers
| eager-load. Month index i counts whole months from effective_from (0-based):
| quarterly is due when (i + 1) % 3 = 0, so months 3, 6, 9, 12 from the start.
*/

/**
 * A line built in memory, its target chain set as loaded relations.
 *
 * @param  array<string, mixed>  $attributes
 */
function dueRuleLine(array $attributes, ?CollectionFrequency $frequency = CollectionFrequency::Monthly, ?int $followUpMonths = null): ReportTemplateLine
{
    $library = $frequency === null ? null : (new LibraryIndicator)->forceFill(['frequency' => $frequency, 'follow_up_months' => $followUpMonths]);
    $indicator = (new Indicator)->setRelation('libraryIndicator', $library);
    $target = (new PlanIndicator)->setRelation('indicator', $indicator);

    return (new ReportTemplateLine)->forceFill([
        'status' => ReportTemplateLineStatus::Confirmed,
        'schedule' => LineSchedule::LibraryDefault,
        'schedule_months' => null,
        'once_after_months' => null,
        'effective_from' => '2026-01-01',
        'effective_to' => null,
        ...$attributes,
    ])->setRelation('planIndicator', $target);
}

test('the library frequency schedules a library_default line', function (CollectionFrequency $frequency, ?int $followUpMonths, string $month, bool $due) {
    $line = dueRuleLine([], $frequency, $followUpMonths);

    expect($line->isDueIn(CarbonImmutable::parse($month)))->toBe($due);
})->with([
    'monthly, first month' => [CollectionFrequency::Monthly, null, '2026-01-01', true],
    'monthly, seventh month' => [CollectionFrequency::Monthly, null, '2026-07-01', true],
    'per event, any month' => [CollectionFrequency::PerEvent, null, '2026-03-01', true],
    'quarterly, month 1' => [CollectionFrequency::Quarterly, null, '2026-01-01', false],
    'quarterly, month 2' => [CollectionFrequency::Quarterly, null, '2026-02-01', false],
    'quarterly, month 3' => [CollectionFrequency::Quarterly, null, '2026-03-01', true],
    'quarterly, month 4' => [CollectionFrequency::Quarterly, null, '2026-04-01', false],
    'quarterly, month 6' => [CollectionFrequency::Quarterly, null, '2026-06-01', true],
    'quarterly, month 9' => [CollectionFrequency::Quarterly, null, '2026-09-01', true],
    'quarterly, month 12' => [CollectionFrequency::Quarterly, null, '2026-12-01', true],
    'quarterly, month 15' => [CollectionFrequency::Quarterly, null, '2027-03-01', true],
    'semi-annual, month 5' => [CollectionFrequency::SemiAnnual, null, '2026-05-01', false],
    'semi-annual, month 6' => [CollectionFrequency::SemiAnnual, null, '2026-06-01', true],
    'semi-annual, month 12' => [CollectionFrequency::SemiAnnual, null, '2026-12-01', true],
    'annual, month 11' => [CollectionFrequency::Annual, null, '2026-11-01', false],
    'annual, month 12' => [CollectionFrequency::Annual, null, '2026-12-01', true],
    'annual, month 13' => [CollectionFrequency::Annual, null, '2027-01-01', false],
    'annual, month 24' => [CollectionFrequency::Annual, null, '2027-12-01', true],
    'follow-up 12, month 12' => [CollectionFrequency::FollowUp, 12, '2026-12-01', false],
    'follow-up 12, month 13' => [CollectionFrequency::FollowUp, 12, '2027-01-01', true],
    'follow-up 12, month 14' => [CollectionFrequency::FollowUp, 12, '2027-02-01', false],
    'follow-up 3, month 4' => [CollectionFrequency::FollowUp, 3, '2026-04-01', true],
    'follow-up with no months' => [CollectionFrequency::FollowUp, null, '2026-01-01', false],
    'per season, never' => [CollectionFrequency::PerSeason, null, '2026-03-01', false],
    'baseline-endline, never' => [CollectionFrequency::BaselineEndline, null, '2026-01-01', false],
    'one time, never' => [CollectionFrequency::OneTime, null, '2026-06-01', false],
]);

test('a quarterly line counts its quarters from its own first month, not the calendar', function (string $month, bool $due) {
    $line = dueRuleLine(['effective_from' => '2026-02-01'], CollectionFrequency::Quarterly);

    expect($line->isDueIn(CarbonImmutable::parse($month)))->toBe($due);
})->with([
    'March' => ['2026-03-01', false],
    'April, month 3' => ['2026-04-01', true],
    'July, month 6' => ['2026-07-01', true],
]);

test('a schedule chosen by MEAL overrides the library frequency', function (array $attributes, CollectionFrequency $frequency, string $month, bool $due) {
    $line = dueRuleLine($attributes, $frequency);

    expect($line->isDueIn(CarbonImmutable::parse($month)))->toBe($due);
})->with([
    'monthly over quarterly, month 1' => [['schedule' => LineSchedule::Monthly], CollectionFrequency::Quarterly, '2026-01-01', true],
    'monthly over one time, month 5' => [['schedule' => LineSchedule::Monthly], CollectionFrequency::OneTime, '2026-05-01', true],
    'selected months, March' => [['schedule' => LineSchedule::SelectedMonths, 'schedule_months' => [3, 9]], CollectionFrequency::PerSeason, '2026-03-01', true],
    'selected months, September' => [['schedule' => LineSchedule::SelectedMonths, 'schedule_months' => [3, 9]], CollectionFrequency::PerSeason, '2026-09-01', true],
    'selected months, April' => [['schedule' => LineSchedule::SelectedMonths, 'schedule_months' => [3, 9]], CollectionFrequency::PerSeason, '2026-04-01', false],
    'selected months, next March' => [['schedule' => LineSchedule::SelectedMonths, 'schedule_months' => [3, 9]], CollectionFrequency::Monthly, '2027-03-01', true],
    'selected months, none chosen' => [['schedule' => LineSchedule::SelectedMonths, 'schedule_months' => null], CollectionFrequency::Monthly, '2026-03-01', false],
    'once after 6, month 6' => [['schedule' => LineSchedule::OnceAfterMonths, 'once_after_months' => 6], CollectionFrequency::BaselineEndline, '2026-06-01', false],
    'once after 6, month 7' => [['schedule' => LineSchedule::OnceAfterMonths, 'once_after_months' => 6], CollectionFrequency::BaselineEndline, '2026-07-01', true],
    'once after 6, month 8' => [['schedule' => LineSchedule::OnceAfterMonths, 'once_after_months' => 6], CollectionFrequency::Monthly, '2026-08-01', false],
    'once after none' => [['schedule' => LineSchedule::OnceAfterMonths, 'once_after_months' => null], CollectionFrequency::Monthly, '2026-01-01', false],
]);

test('only a confirmed line inside its effective window is due', function (array $attributes, string $month, bool $due) {
    $line = dueRuleLine($attributes, CollectionFrequency::Monthly);

    expect($line->isDueIn(CarbonImmutable::parse($month)))->toBe($due);
})->with([
    'pending' => [['status' => ReportTemplateLineStatus::Pending], '2026-03-01', false],
    'retired' => [['status' => ReportTemplateLineStatus::Retired], '2026-03-01', false],
    'before the first month' => [[], '2025-12-01', false],
    'the first month, mid-month date' => [[], '2026-01-20', true],
    'the last month' => [['effective_to' => '2026-06-30'], '2026-06-01', true],
    'the last month stored as its first day' => [['effective_to' => '2026-06-01'], '2026-06-15', true],
    'after the last month' => [['effective_to' => '2026-06-30'], '2026-07-01', false],
    'no last month' => [[], '2030-01-01', true],
]);

test('a target with no library link is never due on the library default', function () {
    $line = dueRuleLine([], frequency: null);

    expect($line->isDueIn(CarbonImmutable::parse('2026-01-01')))->toBeFalse();
});

test('an eager-loaded stored line is decided without a query', function () {
    $library = LibraryIndicator::factory()->create(['frequency' => CollectionFrequency::Quarterly]);
    $target = PlanIndicator::factory()->create(['indicator_id' => Indicator::factory()->create(['library_indicator_id' => $library->id])->id]);
    $line = ReportTemplateLine::factory()->confirmed()->create(['plan_indicator_id' => $target->id, 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);

    $loaded = ReportTemplateLine::query()->with('planIndicator.indicator.libraryIndicator')->findOrFail($line->id);

    DB::enableQueryLog();

    expect($loaded->isDueIn(CarbonImmutable::parse('2026-03-01')))->toBeTrue()
        ->and($loaded->isDueIn(CarbonImmutable::parse('2026-04-01')))->toBeFalse()
        ->and(DB::getQueryLog())->toBe([]);
});
