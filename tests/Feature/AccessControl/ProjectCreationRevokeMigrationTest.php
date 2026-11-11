<?php

use App\Models\Role;

/**
 * An existing database where `permissions:sync` gave Financial projects.create (W-34a).
 */
beforeEach(function () {
    Role::findByName('Financial', 'web')->givePermissionTo('projects.create');

    $this->revokeMigration = require glob(database_path('migrations/*_revoke_project_creation_from_financial.php'))[0];
});

test('the migration takes project creation from Financial and leaves the other holders alone', function () {
    $this->revokeMigration->up();

    $holders = Role::query()->whereHas('permissions', fn ($query) => $query->where('name', 'projects.create'))->pluck('name')->all();

    expect($holders)->toEqualCanonicalizing(['Super Admin', 'General Manager', 'Public Relations Manager']);
});

test('running the migration twice changes nothing', function () {
    $this->revokeMigration->up();
    $this->revokeMigration->up();

    expect(Role::findByName('Financial', 'web')->hasPermissionTo('projects.create'))->toBeFalse();
});
