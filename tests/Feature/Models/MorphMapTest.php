<?php

use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Strategy;
use App\Models\YearlyPlan;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Relations\Relation;

test('the morph map registers exactly the two target sources and the two media owners', function () {
    expect(Relation::morphMap())->toBe([
        'yearly_plan' => YearlyPlan::class,
        'proposal' => Proposal::class,
        'project' => Project::class,
        'milestone' => Milestone::class,
    ]);
});

test('the morph map is enforced so an unmapped model has no morph class', function () {
    expect(Relation::requiresMorphMap())->toBeTrue()
        ->and((new YearlyPlan)->getMorphClass())->toBe('yearly_plan')
        ->and((new Proposal)->getMorphClass())->toBe('proposal')
        ->and((new Project)->getMorphClass())->toBe('project')
        ->and((new Milestone)->getMorphClass())->toBe('milestone');

    (new Strategy)->getMorphClass();
})->throws(ClassMorphViolationException::class);

test('each mapped key fits the fifty character source_type column', function () {
    foreach (array_keys(Relation::morphMap()) as $key) {
        expect(strlen($key))->toBeLessThanOrEqual(50);
    }
});
