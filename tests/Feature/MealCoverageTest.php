<?php

use App\Actions\Cities\AssignMealCoverage;
use App\Enums\MealCoverageScope;
use App\Models\Activity;
use App\Models\MealCoverageAssignment;
use App\Models\Project;
use App\Models\UserNotification;
use App\Notifications\ActivityPresenter;
use App\Support\MealCoverage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A city coverage row written without recording, for routing tests.
 */
function coverCity(int $cityId, int $employeeId): void
{
    Activity::withoutRecording(fn () => MealCoverageAssignment::factory()->forCity($cityId)->create(['employee_id' => $employeeId]));
}

/**
 * A project coverage row written without recording, for routing tests.
 */
function coverProject(int $projectId, int $employeeId): void
{
    Activity::withoutRecording(fn () => MealCoverageAssignment::factory()->forProject($projectId)->create(['employee_id' => $employeeId]));
}

function projectInCity(int $cityId, ?int $centerId = null): Project
{
    return Activity::withoutRecording(fn () => Project::factory()->create(['city_id' => $cityId, 'center_id' => $centerId]));
}

test('assigning a governorate officer stores who assigned them and records the activity', function () {
    $manager = mealManager();
    $aleppo = insertHrCity('Aleppo');
    $officer = insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Ali');

    $this->actingAs($manager);
    app(AssignMealCoverage::class)->handle(MealCoverageScope::City, $aleppo, $officer);

    $row = DB::table('meal_coverages')->sole();
    $activity = Activity::query()->sole();

    expect($row->scope_type)->toBe('city')
        ->and($row->city_id)->toBe($aleppo)
        ->and($row->project_id)->toBeNull()
        ->and($row->employee_id)->toBe($officer)
        ->and($row->assigned_by)->toBe($manager->id)
        ->and($activity->event)->toBe('meal_coverage.assigned')
        ->and($activity->module)->toBe('cities')
        ->and($activity->label())->toBe('Aleppo')
        ->and(app(ActivityPresenter::class)->sentence($activity, 'en'))->toBe($manager->name.' made Nour Ali the MEAL officer for “Aleppo”');
});

test('changing the governorate officer replaces the row and notifies the new and the previous officer', function () {
    $manager = mealManager();
    $aleppo = insertHrCity('Aleppo');
    $previous = insertHrEmployee(firstNameEn: 'Rami', lastNameEn: 'Saleh');
    $next = insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Ali');
    $previousUser = userWithRole('MEAL', $previous);
    $nextUser = userWithRole('MEAL', $next);
    coverCity($aleppo, $previous);

    $this->actingAs($manager);
    app(AssignMealCoverage::class)->handle(MealCoverageScope::City, $aleppo, $next);

    expect(DB::table('meal_coverages')->count())->toBe(1)
        ->and(DB::table('meal_coverages')->value('employee_id'))->toBe($next)
        ->and(Activity::query()->sole()->event)->toBe('meal_coverage.responsible_changed')
        ->and(UserNotification::query()->forUser($nextUser)->sole()->data['key'])->toBe('notifications.events.meal_coverage.responsible_changed_you')
        ->and(UserNotification::query()->forUser($previousUser)->sole()->data['key'])->toBe('notifications.events.meal_coverage.responsible_changed')
        ->and(UserNotification::query()->forUser($manager)->count())->toBe(0);
});

test('assigning the officer a city already has writes and notifies nothing', function () {
    $manager = mealManager();
    $aleppo = insertHrCity('Aleppo');
    $officer = insertHrEmployee();
    coverCity($aleppo, $officer);
    $before = DB::table('meal_coverages')->sole();

    $this->travel(5)->minutes();
    $this->actingAs($manager);
    app(AssignMealCoverage::class)->handle(MealCoverageScope::City, $aleppo, $officer);

    expect(DB::table('meal_coverages')->sole())->toEqual($before)
        ->and(Activity::query()->count())->toBe(0)
        ->and(UserNotification::query()->count())->toBe(0);
});

test('an employee who is inactive in hr, or unknown, cannot be assigned', function (bool $known) {
    $manager = mealManager();
    $aleppo = insertHrCity('Aleppo');
    $employeeId = $known ? insertHrEmployee(isActive: false) : 999_999;

    $this->actingAs($manager);

    expect(fn () => app(AssignMealCoverage::class)->handle(MealCoverageScope::City, $aleppo, $employeeId))
        ->toThrow(ValidationException::class, 'Choose an active employee.');
    expect(DB::table('meal_coverages')->count())->toBe(0);
})->with(['inactive' => true, 'unknown' => false]);

test('a city hr does not have cannot be assigned', function () {
    $manager = mealManager();

    $this->actingAs($manager);

    expect(fn () => app(AssignMealCoverage::class)->handle(MealCoverageScope::City, 999_999, insertHrEmployee()))
        ->toThrow(ValidationException::class, 'City not found.');
    expect(DB::table('meal_coverages')->count())->toBe(0);
});

test('a city inactive in hr can still be given an officer', function () {
    $manager = mealManager();
    $tartus = insertHrCity('Tartus', 'طرطوس', isActive: false);

    $this->actingAs($manager);
    app(AssignMealCoverage::class)->handle(MealCoverageScope::City, $tartus, insertHrEmployee());

    expect(DB::table('meal_coverages')->where('city_id', $tartus)->exists())->toBeTrue();
});

test('a project officer from another governorate notifies the new officer and the governorate officer', function () {
    $manager = mealManager();
    $aleppo = insertHrCity('Aleppo');
    $governorateOfficer = insertHrEmployee(firstNameEn: 'Rami', lastNameEn: 'Saleh');
    $projectOfficer = insertHrEmployee(cityId: insertHrCity('Homs', 'حمص'), firstNameEn: 'Nour', lastNameEn: 'Ali');
    $governorateUser = userWithRole('MEAL', $governorateOfficer);
    $projectUser = userWithRole('MEAL', $projectOfficer);
    coverCity($aleppo, $governorateOfficer);
    $project = projectInCity($aleppo);

    $this->actingAs($manager);
    app(AssignMealCoverage::class)->handle(MealCoverageScope::Project, $project->id, $projectOfficer);

    $row = DB::table('meal_coverages')->where('scope_type', 'project')->sole();

    expect($row->project_id)->toBe($project->id)
        ->and($row->city_id)->toBeNull()
        ->and(Activity::query()->sole()->event)->toBe('meal_coverage.project_assigned')
        ->and(UserNotification::query()->forUser($projectUser)->sole()->data['key'])->toBe('notifications.events.meal_coverage.project_assigned_you')
        ->and(UserNotification::query()->forUser($governorateUser)->sole()->data['key'])->toBe('notifications.events.meal_coverage.project_assigned');
});

test('a project that does not exist cannot be given an officer', function () {
    $manager = mealManager();

    $this->actingAs($manager);

    expect(fn () => app(AssignMealCoverage::class)->handle(MealCoverageScope::Project, 999_999, insertHrEmployee()))
        ->toThrow(ValidationException::class, 'Project not found.');
});

test('only the meal manager slot holder with the coverage permission may assign', function (string $role, bool $holdsSlot) {
    $employeeId = insertHrEmployee();
    holdMealManagerSlot($holdsSlot ? $employeeId : null);
    $user = userWithRole($role, $employeeId);
    $aleppo = insertHrCity('Aleppo');

    $this->actingAs($user);

    expect(fn () => app(AssignMealCoverage::class)->handle(MealCoverageScope::City, $aleppo, insertHrEmployee()))
        ->toThrow(AuthorizationException::class);
    expect(DB::table('meal_coverages')->count())->toBe(0);
})->with([
    'meal officer without the slot' => ['MEAL', false],
    'general manager holding the slot' => ['General Manager', true],
]);

test('a create race on the same city ends with one row holding the latest officer', function () {
    $manager = mealManager();
    $aleppo = insertHrCity('Aleppo');
    $racer = insertHrEmployee();
    $officer = insertHrEmployee();
    $raced = false;

    // Another request inserts the city's row after this one found none.
    MealCoverageAssignment::creating(function () use (&$raced, $aleppo, $racer): void {
        if (! $raced) {
            $raced = true;
            DB::table('meal_coverages')->insert(['scope_type' => 'city', 'city_id' => $aleppo, 'employee_id' => $racer, 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    $this->actingAs($manager);
    app(AssignMealCoverage::class)->handle(MealCoverageScope::City, $aleppo, $officer);

    expect(DB::table('meal_coverages')->count())->toBe(1)
        ->and(DB::table('meal_coverages')->value('employee_id'))->toBe($officer);
});

test('the project officer outranks the governorate officer', function () {
    $aleppo = insertHrCity('Aleppo');
    $project = projectInCity($aleppo);
    coverCity($aleppo, insertHrEmployee());
    coverProject($project->id, $projectOfficer = insertHrEmployee(cityId: insertHrCity('Homs', 'حمص')));

    expect(MealCoverage::responsibleFor($project))->toBe($projectOfficer);
});

test('a project with no officer of its own inherits the officer of its center\'s governorate', function () {
    $aleppo = insertHrCity('Aleppo');
    $homs = insertHrCity('Homs', 'حمص');
    coverCity($aleppo, $aleppoOfficer = insertHrEmployee());
    coverCity($homs, insertHrEmployee());
    $project = projectInCity($homs, insertHrCenter($aleppo));

    expect(MealCoverage::responsibleFor($project))->toBe($aleppoOfficer);
});

test('a project without a center falls back to its own city', function () {
    $aleppo = insertHrCity('Aleppo');
    coverCity($aleppo, $officer = insertHrEmployee());

    expect(MealCoverage::responsibleFor(projectInCity($aleppo)))->toBe($officer);
});

test('inactive officers are skipped down to the meal manager slot holder', function () {
    $aleppo = insertHrCity('Aleppo');
    $project = projectInCity($aleppo);
    coverProject($project->id, insertHrEmployee(isActive: false));
    coverCity($aleppo, insertHrEmployee(isActive: false));
    $managerId = holdMealManagerSlot();

    expect(MealCoverage::responsibleFor($project))->toBe($managerId);
});

test('with no officer and a vacant meal manager slot nobody is responsible', function () {
    expect(MealCoverage::responsibleFor(projectInCity(insertHrCity('Aleppo'))))->toBeNull();
});

test('an officer covers their own projects and their governorates\' projects, except those given to someone else', function () {
    $aleppo = insertHrCity('Aleppo');
    $homs = insertHrCity('Homs', 'حمص');
    $officer = insertHrEmployee();
    coverCity($aleppo, $officer);

    $inherited = projectInCity($aleppo);
    $viaCenter = projectInCity($homs, insertHrCenter($aleppo));
    $takenByOther = projectInCity($aleppo);
    coverProject($takenByOther->id, insertHrEmployee());
    $direct = projectInCity($homs);
    coverProject($direct->id, $officer);
    projectInCity($homs);

    expect(MealCoverage::projectsCoveredBy($officer))
        ->toEqualCanonicalizing([$inherited->id, $viaCenter->id, $direct->id]);
});
