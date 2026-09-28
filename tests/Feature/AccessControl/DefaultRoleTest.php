<?php

use App\Models\HrUser;
use App\Models\User;

test('a first HR login creates a Team Member account', function () {
    $hrUserId = insertHrUser(insertHrEmployee(), 'new.person@example.org');

    $user = User::forHrAccount(HrUser::query()->findOrFail($hrUserId));

    expect($user->roles->pluck('name')->all())->toBe(['Team Member']);
});

test('an HR admin email still starts as Super Admin', function () {
    config(['auth.hr_admin_emails' => ['boss@example.org']]);
    $hrUserId = insertHrUser(insertHrEmployee(), 'boss@example.org');

    $user = User::forHrAccount(HrUser::query()->findOrFail($hrUserId));

    expect($user->roles->pluck('name')->all())->toBe(['Super Admin']);
});

test('the backfill gives every roleless user Team Member and leaves other users alone', function () {
    // Two or more: strict mode only refuses lazy loads on models fetched in a batch.
    [$roleless, $alsoRoleless] = User::factory()->count(2)->create()->all();
    $manager = userWithRole('Managers');

    $migration = require glob(database_path('migrations/*_assign_team_member_to_users_without_a_role.php'))[0];
    $migration->up();

    expect($roleless->fresh()->roles->pluck('name')->all())->toBe(['Team Member'])
        ->and($alsoRoleless->fresh()->roles->pluck('name')->all())->toBe(['Team Member'])
        ->and($manager->fresh()->roles->pluck('name')->all())->toBe(['Managers']);
});

test('a bypass role wins over a scoped role', function () {
    $user = userWithRole('Managers');
    $user->assignRole('Team Member');

    expect($user->isTaskDepartmentScoped())->toBeFalse();
});

test('Team Member alone is department-scoped', function () {
    expect(userWithRole('Team Member')->isTaskDepartmentScoped())->toBeTrue();
});
