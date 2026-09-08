<?php

use App\Models\City;
use App\Models\Proposal;
use App\Models\YearlyPlan;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Relations\Relation;

test('the morph map registers exactly the two plan indicator sources', function () {
    expect(Relation::morphMap())->toBe([
        'yearly_plan' => YearlyPlan::class,
        'proposal' => Proposal::class,
    ]);
});

test('the morph map is enforced so an unmapped model has no morph class', function () {
    expect(Relation::requiresMorphMap())->toBeTrue()
        ->and((new YearlyPlan)->getMorphClass())->toBe('yearly_plan')
        ->and((new Proposal)->getMorphClass())->toBe('proposal');

    (new City)->getMorphClass();
})->throws(ClassMorphViolationException::class);

test('each mapped key fits the fifty character source_type column', function () {
    foreach (array_keys(Relation::morphMap()) as $key) {
        expect(strlen($key))->toBeLessThanOrEqual(50);
    }
});
