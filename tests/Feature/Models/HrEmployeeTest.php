<?php

use App\Models\HrDepartment;
use App\Models\HrEmployee;
use Illuminate\Support\Facades\DB;

test('is_board_member is cast to boolean', function () {
    $employeeId = insertHrEmployee(firstNameEn: 'Board', lastNameEn: 'Member');

    DB::connection('hr')->table('employees')->where('id', $employeeId)->update(['is_board_member' => 1]);

    expect(HrEmployee::query()->find($employeeId)->is_board_member)->toBeTrue();
});

test('currentDepartmentId resolves through the active contract', function () {
    $departmentId = insertHrDepartment('Media');
    $employeeId = insertHrEmployee();
    $positionId = insertHrPosition($departmentId);
    insertHrContract($employeeId, $positionId);

    expect(HrEmployee::query()->find($employeeId)->currentDepartmentId())->toBe($departmentId);
});

test('currentDepartmentId ignores inactive contracts', function () {
    $oldDepartmentId = insertHrDepartment('Finance');
    $newDepartmentId = insertHrDepartment('Media');
    $employeeId = insertHrEmployee();
    $oldPositionId = insertHrPosition($oldDepartmentId);
    $newPositionId = insertHrPosition($newDepartmentId);
    insertHrContract($employeeId, $oldPositionId, isActive: false, startDate: '2020-01-01 00:00:00');
    insertHrContract($employeeId, $newPositionId, startDate: '2026-01-01 00:00:00');

    expect(HrEmployee::query()->find($employeeId)->currentDepartmentId())->toBe($newDepartmentId);
});

test('currentDepartmentId is null with no active contract', function () {
    $employeeId = insertHrEmployee();

    expect(HrEmployee::query()->find($employeeId)->currentDepartmentId())->toBeNull();
});

test('HrDepartment::employeeIdsInDepartment resolves every active employee in that department', function () {
    $departmentId = insertHrDepartment('Media');
    $otherDepartmentId = insertHrDepartment('Finance');
    $positionId = insertHrPosition($departmentId);
    $otherPositionId = insertHrPosition($otherDepartmentId);

    $inDepartment = insertHrEmployee(firstNameEn: 'In', lastNameEn: 'Dept');
    $outOfDepartment = insertHrEmployee(firstNameEn: 'Out', lastNameEn: 'Dept');
    insertHrContract($inDepartment, $positionId);
    insertHrContract($outOfDepartment, $otherPositionId);

    expect(HrDepartment::employeeIdsInDepartment($departmentId))
        ->toBe([$inDepartment]);
});
