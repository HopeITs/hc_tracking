<?php

use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('every hr account gets a tracking user with no role, listed admins as super admin', function () {
    config(['auth.hr_admin_emails' => ['admin@example.org']]);
    insertHrUser(insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Darwish'), 'rana@example.org');
    insertHrUser(insertHrEmployee(), 'admin@example.org');
    $before = DB::connection('hr')->table('users')->get()->toArray();

    $this->artisan('hr:sync-users')->assertSuccessful();

    expect(User::query()->where('email', 'rana@example.org')->sole())
        ->roles->toBeEmpty()
        ->name->toBe('Rana Darwish')
        ->and(User::query()->where('email', 'admin@example.org')->sole()->roles->first()?->name)->toBe('Super Admin')
        ->and(DB::connection('hr')->table('users')->get()->toArray())->toEqual($before);
});

test('syncing again leaves existing users and their roles alone', function () {
    $user = User::factory()->role('Managers')->create(['email' => 'rana@example.org']);
    insertHrUser(insertHrEmployee(), 'rana@example.org');

    $this->artisan('hr:sync-users')->assertSuccessful();
    $this->artisan('hr:sync-users')->assertSuccessful();

    expect(User::query()->count())->toBe(1)
        ->and($user->refresh()->roles->first()?->name)->toBe('Managers');
});

test('inactive hr employees get no tracking user', function () {
    insertHrUser(insertHrEmployee(isActive: false), 'gone@example.org');
    insertHrUser(null, 'system@example.org');

    $this->artisan('hr:sync-users')->assertSuccessful();

    expect(User::query()->pluck('email')->all())->toBe(['system@example.org']);
});

test('users of inactive hr employees are deleted unless records reference them', function () {
    insertHrUser(insertHrEmployee(isActive: false), 'gone@example.org');
    insertHrUser(insertHrEmployee(isActive: false), 'busy@example.org');
    insertHrUser(insertHrEmployee(), 'rana@example.org');
    User::factory()->create(['email' => 'gone@example.org']);
    $busy = User::factory()->create(['email' => 'busy@example.org']);
    Report::factory()->create(['user_id' => $busy->id]);

    $this->artisan('hr:sync-users')
        ->expectsOutputToContain('Kept busy@example.org')
        ->assertSuccessful();

    expect(User::query()->orderBy('email')->pluck('email')->all())->toBe(['busy@example.org', 'rana@example.org']);
});
