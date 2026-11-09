<?php

use App\Models\Permission;
use App\Models\Role;

/**
 * An existing database before D-70: the permission is not in it yet.
 */
beforeEach(function () {
    Permission::query()->where('name', 'proposals.approve')->delete();

    $this->approveMigration = require glob(database_path('migrations/*_grant_proposals_approve_permission.php'))[0];
});

test('the migration creates proposals.approve and hands it to the Public Relations Manager and Super Admin only', function () {
    $this->approveMigration->up();

    $holders = Role::query()->whereHas('permissions', fn ($query) => $query->where('name', 'proposals.approve'))->pluck('name')->all();

    expect($holders)->toEqualCanonicalizing(['Super Admin', 'Public Relations Manager']);
});

test('running the migration twice changes nothing', function () {
    $this->approveMigration->up();
    $this->approveMigration->up();

    expect(Permission::query()->where('name', 'proposals.approve')->count())->toBe(1);
});
