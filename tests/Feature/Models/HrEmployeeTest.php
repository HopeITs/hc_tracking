<?php

use App\Models\HrEmployee;
use Illuminate\Support\Facades\DB;

test('is_board_member is cast to boolean', function () {
    $employeeId = insertHrEmployee(firstNameEn: 'Board', lastNameEn: 'Member');

    DB::connection('hr')->table('employees')->where('id', $employeeId)->update(['is_board_member' => 1]);

    expect(HrEmployee::query()->find($employeeId)->is_board_member)->toBeTrue();
});
