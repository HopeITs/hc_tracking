<?php

use App\Models\Activity;
use App\Models\Supervision;
use App\Support\SupervisionMap;
use Illuminate\Support\Facades\DB;

/**
 * A local supervision row written without recording.
 */
function localSupervision(int $supervisorId, int $superviseeId): void
{
    Activity::withoutRecording(fn () => Supervision::factory()->create([
        'supervisor_employee_id' => $supervisorId,
        'supervisee_employee_id' => $superviseeId,
    ]));
}

/**
 * A manager holding a position and the officers whose positions report to it.
 *
 * @param  list<int>  $officerIds
 */
function managedBy(int $managerId, array $officerIds, bool $officerContractActive = true): void
{
    $department = insertHrDepartment();
    $managerPosition = insertHrPosition($department, 'Manager');
    insertHrContract($managerId, $managerPosition);

    foreach ($officerIds as $officerId) {
        insertHrContract($officerId, insertHrPosition($department, 'Officer', directManagerId: $managerPosition), isActive: $officerContractActive);
    }
}

test('the HR direct manager of the current position supervises with no local row', function () {
    [$manager, $officer] = [insertHrEmployee(), insertHrEmployee()];
    managedBy($manager, [$officer]);

    expect(SupervisionMap::supervisorsOf([$officer]))->toBe([$officer => [$manager]])
        ->and(SupervisionMap::superviseesOf($manager))->toBe([$officer])
        ->and(SupervisionMap::supervises($manager, $officer))->toBeTrue()
        ->and(SupervisionMap::supervises($officer, $manager))->toBeFalse();
});

test('the manager of an ended contract does not supervise', function () {
    [$manager, $officer] = [insertHrEmployee(), insertHrEmployee()];
    managedBy($manager, [$officer], officerContractActive: false);

    expect(SupervisionMap::supervisorsOf([$officer]))->toBe([$officer => []])
        ->and(SupervisionMap::superviseesOf($manager))->toBe([]);
});

test('a local exception adds a supervisor next to the HR manager, without duplicates', function () {
    [$manager, $extra, $officer] = [insertHrEmployee(), insertHrEmployee(), insertHrEmployee()];
    managedBy($manager, [$officer]);
    localSupervision($extra, $officer);

    $supervisors = SupervisionMap::supervisorsOf([$officer, $officer])[$officer];
    sort($supervisors);

    expect($supervisors)->toBe([$manager, $extra])
        ->and(SupervisionMap::superviseesOf($extra))->toBe([$officer])
        ->and(SupervisionMap::supervises($extra, $officer))->toBeTrue();
});

test('an inactive supervisor or supervisee has no effect', function () {
    [$inactiveManager, $officer] = [insertHrEmployee(isActive: false), insertHrEmployee()];
    managedBy($inactiveManager, [$officer]);
    [$supervisor, $inactiveSupervisee] = [insertHrEmployee(), insertHrEmployee(isActive: false)];
    localSupervision($supervisor, $inactiveSupervisee);

    expect(SupervisionMap::supervisorsOf([$officer, $inactiveSupervisee]))->toBe([$officer => [], $inactiveSupervisee => []])
        ->and(SupervisionMap::superviseesOf($inactiveManager))->toBe([])
        ->and(SupervisionMap::superviseesOf($supervisor))->toBe([])
        ->and(SupervisionMap::supervises($supervisor, $inactiveSupervisee))->toBeFalse();
});

test('a new local row is seen after the map is flushed', function () {
    [$supervisor, $supervisee] = [insertHrEmployee(), insertHrEmployee()];

    expect(SupervisionMap::superviseesOf($supervisor))->toBe([]);

    localSupervision($supervisor, $supervisee);
    SupervisionMap::flush();

    expect(SupervisionMap::superviseesOf($supervisor))->toBe([$supervisee]);
});

test('supervisorsOf costs the same number of queries for one or ten people', function () {
    $manager = insertHrEmployee();
    $officers = array_map(fn (): int => insertHrEmployee(), range(1, 10));
    managedBy($manager, $officers);

    foreach ($officers as $officer) {
        localSupervision(insertHrEmployee(), $officer);
    }

    $queriesFor = function (array $ids): int {
        SupervisionMap::flush();
        DB::flushQueryLog();
        DB::connection('hr')->flushQueryLog();

        SupervisionMap::supervisorsOf($ids);

        return count(DB::getQueryLog()) + count(DB::connection('hr')->getQueryLog());
    };

    DB::enableQueryLog();
    DB::connection('hr')->enableQueryLog();

    $cold = $queriesFor([$officers[0]]);
    $one = $queriesFor([$officers[0]]);
    $ten = $queriesFor($officers);

    expect($cold)->toBeLessThanOrEqual(4)
        ->and($ten)->toBe($one);
});

test('the map writes nothing to HR and never reads contract pay columns', function () {
    [$manager, $officer] = [insertHrEmployee(), insertHrEmployee()];
    managedBy($manager, [$officer]);
    localSupervision(insertHrEmployee(), $officer);

    DB::connection('hr')->enableQueryLog();
    SupervisionMap::supervisorsOf([$officer]);
    SupervisionMap::superviseesOf($manager);
    $queries = collect(DB::connection('hr')->getQueryLog())->pluck('query');

    expect($queries)->not->toBeEmpty();

    foreach ($queries as $query) {
        expect(strtolower($query))->toStartWith('select')
            ->not->toContain('salary')
            ->not->toContain('currency')
            ->not->toContain('*');
    }
});
