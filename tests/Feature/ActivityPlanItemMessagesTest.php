<?php

use App\Actions\AnnualPlans\SaveActivityPlanItem;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The shape errors of an activity's details, keyed by the field that is wrong.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, list<string>>
 */
function itemShapeErrors(array $overrides): array
{
    $input = [
        'single_week' => false,
        'unit' => 'session',
        'unit_other' => '',
        'cities' => [1],
        'expected_people' => '',
        'note' => '',
        'batches' => [['start' => '2027-03-01', 'end' => '2027-04-26', 'count' => 4, 'hours' => 2]],
        ...$overrides,
    ];

    try {
        SaveActivityPlanItem::validated($input, [1], CarbonImmutable::parse('2027-01-04'), CarbonImmutable::parse('2027-12-27'));
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

test('a batch number below one is named in plain words on that batch number', function () {
    $errors = itemShapeErrors(['batches' => [['start' => '2027-03-01', 'end' => '2027-04-26', 'count' => 0, 'hours' => 2]]]);

    expect($errors['batches.0.count'] ?? null)->toBe([__('annual_plans.batch_count_invalid')]);
});

test('hours outside half an hour to a day are named in plain words on that batch', function () {
    $errors = itemShapeErrors(['batches' => [['start' => '2027-03-01', 'end' => '2027-04-26', 'count' => 4, 'hours' => 30]]]);

    expect($errors['batches.0.hours'] ?? null)->toBe([__('annual_plans.batch_hours_invalid')]);
});

test('a negative expected people is named in plain words', function () {
    expect(itemShapeErrors(['expected_people' => '-3'])['expected_people'] ?? null)
        ->toBe([__('annual_plans.expected_people_invalid')]);
});
