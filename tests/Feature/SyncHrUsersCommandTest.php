<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('every hr account gets a tracking user as an officer, listed admins as admin', function () {
    config(['auth.hr_admin_emails' => ['admin@example.org']]);
    insertHrUser(insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Darwish'), 'rana@example.org');
    insertHrUser(insertHrEmployee(), 'admin@example.org');
    $before = DB::connection('hr')->table('users')->get()->toArray();

    $this->artisan('hr:sync-users')->assertSuccessful();

    expect(User::query()->where('email', 'rana@example.org')->sole())
        ->role->toBe(UserRole::Officer)
        ->name->toBe('Rana Darwish')
        ->and(User::query()->where('email', 'admin@example.org')->sole()->role)->toBe(UserRole::Admin)
        ->and(DB::connection('hr')->table('users')->get()->toArray())->toEqual($before);
});

test('syncing again leaves existing users and their roles alone', function () {
    $user = User::factory()->role(UserRole::Manager)->create(['email' => 'rana@example.org']);
    insertHrUser(insertHrEmployee(), 'rana@example.org');

    $this->artisan('hr:sync-users')->assertSuccessful();
    $this->artisan('hr:sync-users')->assertSuccessful();

    expect(User::query()->count())->toBe(1)
        ->and($user->refresh()->role)->toBe(UserRole::Manager);
});
