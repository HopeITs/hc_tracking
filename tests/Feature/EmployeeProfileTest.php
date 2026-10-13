<?php

use App\Support\EmployeeProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

test('the profile reads the position of the active contract, not an ended one', function () {
    $departmentId = insertHrDepartment('Programs');
    $employeeId = insertHrEmployee();
    $oldPositionId = insertHrPosition($departmentId, 'Old Officer');
    $currentPositionId = insertHrPosition($departmentId, 'Field Officer');
    insertHrContract($employeeId, $oldPositionId, isActive: false, startDate: '2025-01-01 00:00:00');
    insertHrContract($employeeId, $currentPositionId, startDate: '2024-01-01 00:00:00');

    $profile = EmployeeProfile::for($employeeId);

    expect($profile['position_id'])->toBe($currentPositionId)
        ->and($profile['position_title_en'])->toBe('Field Officer');
});

test('with several active contracts the latest start date wins and a warning is logged', function () {
    Log::spy();
    $departmentId = insertHrDepartment();
    $employeeId = insertHrEmployee();
    $earlierPositionId = insertHrPosition($departmentId, 'Earlier');
    $laterPositionId = insertHrPosition($departmentId, 'Later');
    insertHrContract($employeeId, $laterPositionId, startDate: '2026-03-01 00:00:00');
    insertHrContract($employeeId, $earlierPositionId, startDate: '2025-03-01 00:00:00');

    expect(EmployeeProfile::for($employeeId)['position_id'])->toBe($laterPositionId);

    Log::shouldHaveReceived('warning')->once();
});

test('the profile carries level, grade, department, program, center, city and direct manager', function () {
    $cityId = insertHrCity('Idlib', 'إدلب');
    $centerId = insertHrCenter($cityId);
    $levelId = insertHrJobLevel('Senior', 'أول', 4);
    $programId = insertHrProgram('Micro');
    $departmentId = insertHrDepartment();

    $managerPositionId = insertHrPosition($departmentId, 'Center Manager');
    $managerId = insertHrEmployee(firstNameEn: 'Mona');
    insertHrContract($managerId, $managerPositionId);

    $positionId = insertHrPosition($departmentId, 'Field Officer', $programId, directManagerId: $managerPositionId, centerId: $centerId, jobLevelId: $levelId);
    DB::connection('hr')->table('positions')->where('id', $positionId)->update(['title_ar' => 'موظف ميداني']);
    $employeeId = insertHrEmployee();
    insertHrContract($employeeId, $positionId);

    expect(EmployeeProfile::for($employeeId))->toBe([
        'employee_id' => $employeeId,
        'position_id' => $positionId,
        'position_title_en' => 'Field Officer',
        'position_title_ar' => 'موظف ميداني',
        'job_level' => 'Senior',
        'grade' => 4,
        'department_id' => $departmentId,
        'program_id' => $programId,
        'center_id' => $centerId,
        'city_id' => $cityId,
        'direct_manager_employee_id' => $managerId,
        'is_active' => true,
    ]);
});

test('the job level follows the interface language', function () {
    $levelId = insertHrJobLevel('Senior', 'أول');
    $employeeId = insertHrEmployee();
    insertHrContract($employeeId, insertHrPosition(insertHrDepartment(), jobLevelId: $levelId));

    app()->setLocale('ar');

    expect(EmployeeProfile::for($employeeId)['job_level'])->toBe('أول');
});

test('a position without a center keeps its own city', function () {
    $cityId = insertHrCity();
    $employeeId = insertHrEmployee();
    insertHrContract($employeeId, insertHrPosition(insertHrDepartment(), cityId: $cityId));

    expect(EmployeeProfile::for($employeeId))
        ->center_id->toBeNull()
        ->city_id->toBe($cityId);
});

test('an employee marked inactive in HR reads as inactive', function () {
    $employeeId = insertHrEmployee(isActive: false);
    insertHrContract($employeeId, insertHrPosition(insertHrDepartment()));

    expect(EmployeeProfile::for($employeeId)['is_active'])->toBeFalse();
});

test('an employee with no active contract has no profile', function () {
    $employeeId = insertHrEmployee();
    insertHrContract($employeeId, insertHrPosition(insertHrDepartment()), isActive: false);

    expect(EmployeeProfile::for($employeeId))->toBeNull();
});

test('an employee hired after the profile was loaded is found', function () {
    $departmentId = insertHrDepartment();
    $first = insertHrEmployee();
    insertHrContract($first, insertHrPosition($departmentId));
    EmployeeProfile::for($first);

    $hired = insertHrEmployee(firstNameEn: 'New');
    insertHrContract($hired, insertHrPosition($departmentId));

    expect(EmployeeProfile::for($hired))->not->toBeNull();
});

test('asking about many employees without a contract costs no more HR queries than one', function () {
    $unplaced = array_map(fn (int $i): int => insertHrEmployee(firstNameEn: 'Unplaced '.$i), range(1, 5));

    DB::connection('hr')->enableQueryLog();
    array_map(EmployeeProfile::for(...), $unplaced);

    expect(DB::connection('hr')->getQueryLog())->toHaveCount(2);
});

test('employeeIdsAtCenter lists the active holders of positions at that center', function () {
    $cityId = insertHrCity();
    $centerId = insertHrCenter($cityId);
    $otherCenterId = insertHrCenter($cityId, 'Other');
    $departmentId = insertHrDepartment();

    $atCenter = insertHrEmployee();
    insertHrContract($atCenter, insertHrPosition($departmentId, centerId: $centerId));
    $elsewhere = insertHrEmployee();
    insertHrContract($elsewhere, insertHrPosition($departmentId, centerId: $otherCenterId));
    $left = insertHrEmployee();
    insertHrContract($left, insertHrPosition($departmentId, centerId: $centerId), isActive: false);

    expect(EmployeeProfile::employeeIdsAtCenter($centerId))->toBe([$atCenter]);
});

test('employeeIdsInDepartment lists the active holders of positions in that department', function () {
    $departmentId = insertHrDepartment('Media');
    $otherDepartmentId = insertHrDepartment('Finance');

    $inDepartment = insertHrEmployee();
    insertHrContract($inDepartment, insertHrPosition($departmentId));
    $outside = insertHrEmployee();
    insertHrContract($outside, insertHrPosition($otherDepartmentId));

    expect(EmployeeProfile::employeeIdsInDepartment($departmentId))->toBe([$inDepartment]);
});

test('managerOf is the holder of the direct manager position, null when vacant', function () {
    $departmentId = insertHrDepartment();
    $managerPositionId = insertHrPosition($departmentId, 'Manager');
    $vacantPositionId = insertHrPosition($departmentId, 'Vacant Manager');
    $managerId = insertHrEmployee();
    insertHrContract($managerId, $managerPositionId);

    $managed = insertHrEmployee();
    insertHrContract($managed, insertHrPosition($departmentId, directManagerId: $managerPositionId));
    $orphan = insertHrEmployee();
    insertHrContract($orphan, insertHrPosition($departmentId, directManagerId: $vacantPositionId));

    expect(EmployeeProfile::managerOf($managed))->toBe($managerId)
        ->and(EmployeeProfile::managerOf($orphan))->toBeNull()
        ->and(EmployeeProfile::managerOf(insertHrEmployee()))->toBeNull();
});

test('holderOfPosition is the active holder with the latest start date', function () {
    $positionId = insertHrPosition(insertHrDepartment());
    $earlier = insertHrEmployee();
    $later = insertHrEmployee();
    $former = insertHrEmployee();
    insertHrContract($earlier, $positionId, startDate: '2025-01-01 00:00:00');
    insertHrContract($later, $positionId, startDate: '2026-01-01 00:00:00');
    insertHrContract($former, $positionId, isActive: false, startDate: '2026-06-01 00:00:00');

    expect(EmployeeProfile::holderOfPosition($positionId))->toBe($later)
        ->and(EmployeeProfile::holderOfPosition(insertHrPosition(insertHrDepartment())))->toBeNull();
});

test('the HR queries never read contract pay or every contract column', function () {
    $employeeId = insertHrEmployee();
    insertHrContract($employeeId, insertHrPosition(insertHrDepartment()));

    DB::connection('hr')->enableQueryLog();
    EmployeeProfile::for($employeeId);
    EmployeeProfile::managerOf($employeeId);
    EmployeeProfile::employeeIdsAtCenter(1);
    $queries = collect(DB::connection('hr')->getQueryLog())->pluck('query');

    expect($queries)->not->toBeEmpty()
        ->and($queries->count())->toBeLessThanOrEqual(2);

    foreach ($queries as $query) {
        expect($query)->not->toContain('salary')
            ->not->toContain('currency')
            ->not->toContain('*');
    }
});
