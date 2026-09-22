<?php

use App\Models\AnnualPlan;
use App\Models\HrDepartment;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Relations\Relation;

test('the morph map registers exactly the two target sources, the five task sources, the two media owners, and the permission model side', function () {
    expect(Relation::morphMap())->toBe([
        'annual_plan' => AnnualPlan::class,
        'proposal' => Proposal::class,
        'project' => Project::class,
        'milestone' => Milestone::class,
        'program' => Program::class,
        'department' => HrDepartment::class,
        'user' => User::class,
    ]);
});

test('the morph map is enforced so an unmapped model has no morph class', function () {
    expect(Relation::requiresMorphMap())->toBeTrue()
        ->and((new AnnualPlan)->getMorphClass())->toBe('annual_plan')
        ->and((new Proposal)->getMorphClass())->toBe('proposal')
        ->and((new Project)->getMorphClass())->toBe('project')
        ->and((new Milestone)->getMorphClass())->toBe('milestone')
        ->and((new Program)->getMorphClass())->toBe('program')
        ->and((new HrDepartment)->getMorphClass())->toBe('department')
        ->and((new User)->getMorphClass())->toBe('user');

    (new Strategy)->getMorphClass();
})->throws(ClassMorphViolationException::class);

test('each mapped key fits the fifty character source_type column', function () {
    foreach (array_keys(Relation::morphMap()) as $key) {
        expect(strlen($key))->toBeLessThanOrEqual(50);
    }
});
