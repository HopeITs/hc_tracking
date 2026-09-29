<?php

use App\Models\Permission;
use App\Models\Role;

/**
 * Puts the database in the state it was in before the access review: Team
 * Member views the money pages, Public Relations Manager deletes reports,
 * General Manager holds a cities permission, and the notification settings
 * permission does not exist yet.
 */
beforeEach(function () {
    Role::findByName('Team Member', 'web')->givePermissionTo(['donors.view', 'transfers.view']);
    Role::findByName('Public Relations Manager', 'web')->givePermissionTo(Permission::findOrCreate('reports.delete', 'web'));
    Role::findByName('General Manager', 'web')->givePermissionTo(Permission::findOrCreate('cities.create', 'web'));
    Permission::findByName('notification-settings.manage', 'web')->delete();

    $this->runAccessReviewMigration = function (): void {
        $migration = require glob(database_path('migrations/*_apply_role_access_review_decisions.php'))[0];
        $migration->up();
    };
});

test('the migration takes the money views back from the roles that lost them', function () {
    ($this->runAccessReviewMigration)();

    expect(Role::findByName('Team Member', 'web')->hasPermissionTo('donors.view'))->toBeFalse()
        ->and(Role::findByName('Team Member', 'web')->hasPermissionTo('transfers.view'))->toBeFalse();
});

test('the migration takes report deletion from Public Relations Manager but leaves what it still holds', function () {
    ($this->runAccessReviewMigration)();

    $role = Role::findByName('Public Relations Manager', 'web');

    expect($role->hasPermissionTo('reports.delete'))->toBeFalse()
        ->and($role->hasPermissionTo('donors.view'))->toBeTrue();
});

test('the migration removes the cities permission nothing checked', function () {
    ($this->runAccessReviewMigration)();

    expect(Permission::query()->where('name', 'cities.create')->exists())->toBeFalse()
        ->and(Role::findByName('General Manager', 'web')->permissions->pluck('name'))->not->toContain('cities.create');
});

test('the migration gives notification settings to Super Admin only', function () {
    ($this->runAccessReviewMigration)();

    expect(Role::findByName('Super Admin', 'web')->hasPermissionTo('notification-settings.manage'))->toBeTrue()
        ->and(Role::findByName('General Manager', 'web')->hasPermissionTo('notification-settings.manage'))->toBeFalse();
});

test('a permission an admin added by hand to a role survives the migration', function () {
    Role::findByName('MEAL', 'web')->givePermissionTo('risks.create');

    ($this->runAccessReviewMigration)();

    expect(Role::findByName('MEAL', 'web')->hasPermissionTo('risks.create'))->toBeTrue();
});
