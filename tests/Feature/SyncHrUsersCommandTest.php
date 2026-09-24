<?php

use App\Models\Activity;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('every hr account gets a tracking user with no role, listed admins as super admin', function () {
    config(['auth.hr_admin_emails' => ['admin@example.org']]);
    $ranaId = insertHrUser(insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Darwish'), 'rana@example.org');
    $adminId = insertHrUser(insertHrEmployee(), 'admin@example.org');
    $before = DB::connection('hr')->table('users')->get()->toArray();

    $this->artisan('hr:sync-users')->assertSuccessful();

    expect(User::query()->where('hr_user_id', $ranaId)->sole())
        ->roles->toBeEmpty()
        ->name->toBe('Rana Darwish')
        ->email->toBe('rana@example.org')
        ->and(User::query()->where('hr_user_id', $adminId)->sole()->roles->first()?->name)->toBe('Super Admin')
        ->and(DB::connection('hr')->table('users')->get()->toArray())->toEqual($before);
});

test('syncing again leaves existing users and their roles alone', function () {
    $user = User::factory()->role('Managers')->create(['email' => 'rana@example.org']);

    $this->artisan('hr:sync-users')->assertSuccessful();
    $this->artisan('hr:sync-users')->assertSuccessful();

    expect(User::query()->count())->toBe(1)
        ->and($user->refresh()->roles->first()?->name)->toBe('Managers');
});

test('inactive hr employees get no tracking user', function () {
    insertHrUser(insertHrEmployee(isActive: false), 'gone@example.org');
    $systemId = insertHrUser(null, 'system@example.org');

    $this->artisan('hr:sync-users')->assertSuccessful();

    expect(User::query()->pluck('hr_user_id')->all())->toBe([$systemId]);
});

test('users of inactive hr employees are deleted unless records reference them', function () {
    $gone = User::factory()->create(['email' => 'gone@example.org']);
    $busy = User::factory()->create(['email' => 'busy@example.org']);
    $rana = User::factory()->create(['email' => 'rana@example.org']);
    DB::connection('hr')->table('employees')->whereIn('id', [$gone->employee_id, $busy->employee_id])->update(['is_active' => false]);
    Report::factory()->create(['user_id' => $busy->id]);

    $this->artisan('hr:sync-users')
        ->expectsOutputToContain('Kept busy@example.org')
        ->assertSuccessful();

    expect(User::query()->orderBy('id')->pluck('id')->all())->toBe([$busy->id, $rana->id]);
});

test('syncing refreshes the hr employee an account belongs to', function () {
    $user = Activity::withoutRecording(fn (): User => User::factory()->create());
    $employeeId = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Darwish');
    DB::connection('hr')->table('users')->where('id', $user->hr_user_id)->update(['employee_id' => $employeeId]);

    $this->artisan('hr:sync-users')->expectsOutputToContain('Updated 1 user(s)')->assertSuccessful();

    expect($user->fresh())
        ->employee_id->toBe($employeeId)
        ->name->toBe('Rana Darwish');
});

test('a user kept because records reference them keeps their roles', function () {
    $busy = User::factory()->role('Managers')->create();
    DB::connection('hr')->table('employees')->where('id', $busy->employee_id)->update(['is_active' => false]);
    Report::factory()->create(['user_id' => $busy->id]);

    $this->artisan('hr:sync-users')->assertSuccessful();

    expect($busy->refresh()->roles->pluck('name')->all())->toBe(['Managers']);
});
