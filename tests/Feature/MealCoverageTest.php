<?php

use App\Actions\Cities\AssignMealCoverage;
use App\Enums\MealCoverageScope;
use App\Models\Activity;
use App\Models\MealCoverageAssignment;
use App\Models\Program;
use App\Models\ReportTemplate;
use App\Models\UserNotification;
use App\Notifications\ActivityPresenter;
use App\Support\MealCoverage;
use App\Support\Reporting\ReportPlace;
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

function coveragePlace(int $city, ?int $center = null, bool $wide = true): ReportPlace
{
    return new ReportPlace($city, Program::factory()->create()->id, $center, $wide);
}

function coveragePlaceFields(ReportPlace $place): array
{
    return ['city_id' => $place->cityId, 'program_id' => $place->programId, 'center_id' => $place->centerId, 'city_wide' => $place->cityWide];
}

function coverPlace(ReportPlace $place, int $employee): void
{
    DB::table('meal_coverages')->insert(coveragePlaceFields($place) + [
        'scope_type' => 'place', 'scope_key' => 'place:'.$place->key(), 'employee_id' => $employee,
    ]);
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

test('a place officer from another governorate notifies the new officer and the governorate officer', function () {
    $manager = mealManager();
    $aleppo = insertHrCity('Aleppo');
    $governorateOfficer = insertHrEmployee(firstNameEn: 'Rami', lastNameEn: 'Saleh');
    $projectOfficer = insertHrEmployee(cityId: insertHrCity('Homs', 'حمص'), firstNameEn: 'Nour', lastNameEn: 'Ali');
    $governorateUser = userWithRole('MEAL', $governorateOfficer);
    $projectUser = userWithRole('MEAL', $projectOfficer);
    coverCity($aleppo, $governorateOfficer);
    $place = coveragePlace($aleppo);

    $this->actingAs($manager);
    app(AssignMealCoverage::class)->handle(MealCoverageScope::Place, coveragePlaceFields($place), $projectOfficer);

    $row = DB::table('meal_coverages')->where('scope_type', 'place')->sole();

    expect($row->project_id)->toBeNull()
        ->and($row->city_id)->toBe($aleppo)
        ->and(Activity::query()->where('event', 'meal_coverage.place_assigned')->sole()->event)->toBe('meal_coverage.place_assigned')
        ->and(UserNotification::query()->forUser($projectUser)->sole()->data['key'])->toBe('notifications.events.meal_coverage.place_assigned_you')
        ->and(UserNotification::query()->forUser($governorateUser)->sole()->data['key'])->toBe('notifications.events.meal_coverage.place_assigned');
});

test('a center outside the selected governorate cannot be given an officer', function () {
    $manager = mealManager();

    $this->actingAs($manager);

    $place = coveragePlace(insertHrCity('Aleppo'), insertHrCenter(insertHrCity('Homs')), false);
    expect(fn () => app(AssignMealCoverage::class)->handle(MealCoverageScope::Place, coveragePlaceFields($place), insertHrEmployee()))
        ->toThrow(ValidationException::class);
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
            DB::table('meal_coverages')->insert(['scope_type' => 'city', 'scope_key' => 'city:'.$aleppo, 'city_id' => $aleppo, 'employee_id' => $racer, 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    $this->actingAs($manager);
    app(AssignMealCoverage::class)->handle(MealCoverageScope::City, $aleppo, $officer);

    expect(DB::table('meal_coverages')->count())->toBe(1)
        ->and(DB::table('meal_coverages')->value('employee_id'))->toBe($officer);
});

test('the place officer outranks the governorate officer', function () {
    $aleppo = insertHrCity('Aleppo');
    $place = coveragePlace($aleppo);
    coverCity($aleppo, insertHrEmployee());
    coverPlace($place, $projectOfficer = insertHrEmployee(cityId: insertHrCity('Homs', 'حمص')));

    expect(MealCoverage::responsibleForPlace($place))->toBe($projectOfficer);
});

test('a place with no officer of its own inherits the officer of its center\'s governorate', function () {
    $aleppo = insertHrCity('Aleppo');
    $homs = insertHrCity('Homs', 'حمص');
    coverCity($aleppo, $aleppoOfficer = insertHrEmployee());
    coverCity($homs, insertHrEmployee());
    $place = coveragePlace($aleppo, insertHrCenter($aleppo), false);

    expect(MealCoverage::responsibleForPlace($place))->toBe($aleppoOfficer);
});

test('an unset place falls back to its own city', function () {
    $aleppo = insertHrCity('Aleppo');
    coverCity($aleppo, $officer = insertHrEmployee());

    expect(MealCoverage::responsibleForPlace(coveragePlace($aleppo, wide: false)))->toBe($officer);
});

test('inactive officers are skipped down to the meal manager slot holder', function () {
    $aleppo = insertHrCity('Aleppo');
    $place = coveragePlace($aleppo);
    coverPlace($place, insertHrEmployee(isActive: false));
    coverCity($aleppo, insertHrEmployee(isActive: false));
    $managerId = holdMealManagerSlot();

    expect(MealCoverage::responsibleForPlace($place))->toBe($managerId);
});

test('with no officer and a vacant meal manager slot nobody is responsible', function () {
    expect(MealCoverage::responsibleForPlace(coveragePlace(insertHrCity('Aleppo'))))->toBeNull();
});

test('the place scope includes direct and inherited places and excludes another officer’s place', function () {
    $aleppo = insertHrCity('Aleppo');
    $homs = insertHrCity('Homs');
    $officer = insertHrEmployee();
    coverCity($aleppo, $officer);
    $inherited = coveragePlace($aleppo);
    $taken = coveragePlace($aleppo);
    $direct = coveragePlace($homs);
    $outside = coveragePlace($homs);
    coverPlace($taken, insertHrEmployee());
    coverPlace($direct, $officer);
    $templates = collect([$inherited, $taken, $direct, $outside])->map(
        fn (ReportPlace $place) => ReportTemplate::factory()->create(coveragePlaceFields($place)),
    );

    expect(MealCoverage::scopeCoveredBy(ReportTemplate::query(), $officer)->pluck('id')->all())
        ->toEqualCanonicalizing([$templates[0]->id, $templates[2]->id]);
});
