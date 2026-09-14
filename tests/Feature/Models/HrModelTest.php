<?php

use App\Models\HrCity;
use App\Models\HrEmployee;
use App\Models\HrProgram;
use App\Models\HrUser;

test('hr models read hchr_ tables through the hr connection', function (string $model, string $table) {
    $instance = new $model;

    expect($instance->getConnectionName())->toBe('hr')
        ->and($instance->getConnection()->getTablePrefix().$instance->getTable())->toBe($table);
})->with([
    [HrCity::class, 'hchr_cities'],
    [HrProgram::class, 'hchr_programs'],
    [HrEmployee::class, 'hchr_employees'],
    [HrUser::class, 'hchr_users'],
]);

test('an employee exposes english and arabic full names', function () {
    $employee = HrEmployee::find(insertHrEmployee());

    expect($employee->name_en)->toBe('Sami Haddad')
        ->and($employee->name_ar)->toBe('سامي حداد');
});

test('an employee belongs to a city and has an hr login account', function () {
    $cityId = insertHrCity('Homs');
    $employeeId = insertHrEmployee($cityId);
    insertHrUser($employeeId, 'sami@example.org');

    $employee = HrEmployee::find($employeeId);

    expect($employee->city->name_en)->toBe('Homs')
        ->and($employee->user->email)->toBe('sami@example.org')
        ->and(HrCity::find($cityId)->employees)->toHaveCount(1)
        ->and(HrUser::firstWhere('email', 'sami@example.org')->employee->is($employee))->toBeTrue();
});

test('inactive hr records are still read', function () {
    insertHrCity(isActive: false);
    insertHrProgram(isActive: false);

    expect(HrCity::count())->toBe(1)
        ->and(HrProgram::first()->is_active)->toBeFalse();
});

test('hr records cannot be created', function () {
    $city = new HrCity;
    $city->name_en = 'Tartus';

    expect(fn () => $city->save())->toThrow(LogicException::class, 'read-only')
        ->and(HrCity::count())->toBe(0);
});

test('hr records cannot be updated', function () {
    $program = HrProgram::find(insertHrProgram());
    $program->name = 'Renamed';

    expect(fn () => $program->save())->toThrow(LogicException::class, 'read-only')
        ->and(HrProgram::find($program->id)->name)->toBe('Hope Center');
});

test('hr records cannot be deleted', function () {
    $employeeId = insertHrEmployee();

    expect(fn () => HrEmployee::find($employeeId)->delete())->toThrow(LogicException::class, 'read-only')
        ->and(fn () => HrEmployee::destroy($employeeId))->toThrow(LogicException::class, 'read-only')
        ->and(HrEmployee::find($employeeId))->not->toBeNull();
});

test('sensitive hr fields are hidden from serialization', function () {
    $hidden = HrEmployee::find(insertHrEmployee())->getHidden();

    expect($hidden)->toContain('national_number', 'personal_phone', 'digital_id_token')
        ->and((new HrUser)->getHidden())->toContain('password', 'remember_token');
});
