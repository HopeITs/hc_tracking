<?php

use App\Models\Account;
use App\Models\AnnualPlan;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\HrDepartment;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\MealCoverageAssignment;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\Result;
use App\Models\Risk;
use App\Models\Role;
use App\Models\StrategicPillar;
use App\Models\Strategy;
use App\Models\Supervision;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Relations\Relation;

test('the morph map registers exactly the target sources, task sources, media owners, the permission model side and the activity subjects', function () {
    expect(Relation::morphMap())->toBe([
        'annual_plan' => AnnualPlan::class,
        'proposal' => Proposal::class,
        'project' => Project::class,
        'milestone' => Milestone::class,
        'program' => Program::class,
        'department' => HrDepartment::class,
        'user' => User::class,
        'task' => Task::class,
        'strategy' => Strategy::class,
        'risk' => Risk::class,
        'indicator' => Indicator::class,
        'plan_indicator' => PlanIndicator::class,
        'result' => Result::class,
        'report' => Report::class,
        'donor' => Donor::class,
        'donor_proposal' => DonorProposal::class,
        'donor_report' => DonorReport::class,
        'transfer' => TransfersTracking::class,
        'account' => Account::class,
        'role' => Role::class,
        'library_indicator' => LibraryIndicator::class,
        'strategic_pillar' => StrategicPillar::class,
        'meal_coverage' => MealCoverageAssignment::class,
        'supervision' => Supervision::class,
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
        ->and((new User)->getMorphClass())->toBe('user')
        ->and((new Task)->getMorphClass())->toBe('task')
        ->and((new Strategy)->getMorphClass())->toBe('strategy')
        ->and((new TransfersTracking)->getMorphClass())->toBe('transfer');

    (new ReportFile)->getMorphClass();
})->throws(ClassMorphViolationException::class);

test('each mapped key fits the fifty character source_type column', function () {
    foreach (array_keys(Relation::morphMap()) as $key) {
        expect(strlen($key))->toBeLessThanOrEqual(50);
    }
});
