<?php

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * @return list<string>
 */
function rolePermissionNames(string $role): array
{
    return Role::findByName($role, 'web')->permissions()->pluck('name')->sort()->values()->all();
}

test('syncing keeps every change made to a role on the roles tab', function () {
    (new RolesAndPermissionsSeeder)->run();
    $meal = Role::findByName('MEAL', 'web');
    $meal->revokePermissionTo('results.delete');
    $meal->givePermissionTo('risks.create');
    $before = rolePermissionNames('MEAL');

    $this->artisan('permissions:sync')->assertSuccessful();

    expect(rolePermissionNames('MEAL'))->toBe($before)
        ->not->toContain('results.delete')
        ->toContain('risks.create');
});

test('a new permission reaches Super Admin and the roles whose baseline lists it', function () {
    (new RolesAndPermissionsSeeder)->run();
    config()->set('access-control.groups.budgets', ['view']);
    config()->set('access-control.role_baselines.Financial', [...config('access-control.role_baselines.Financial'), 'budgets.view']);

    $this->artisan('permissions:sync')
        ->expectsOutputToContain('Permission added: budgets.view')
        ->assertSuccessful();

    expect(Permission::query()->where('name', 'budgets.view')->exists())->toBeTrue()
        ->and(rolePermissionNames('Super Admin'))->toContain('budgets.view')
        ->and(rolePermissionNames('Financial'))->toContain('budgets.view')
        ->and(rolePermissionNames('Team Member'))->not->toContain('budgets.view');
});

test('a missing role is created with its baseline', function () {
    (new RolesAndPermissionsSeeder)->run();
    Role::findByName('Media Team', 'web')->delete();

    $this->artisan('permissions:sync')->expectsOutputToContain('Role added: Media Team')->assertSuccessful();

    expect(rolePermissionNames('Media Team'))->toEqualCanonicalizing(config('access-control.role_baselines.Media Team'));
});

test('a dry run changes nothing', function () {
    (new RolesAndPermissionsSeeder)->run();
    config()->set('access-control.groups.budgets', ['view']);

    $this->artisan('permissions:sync', ['--dry-run' => true])
        ->expectsOutputToContain('Permission added: budgets.view')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(Permission::query()->where('name', 'budgets.view')->exists())->toBeFalse();
});

test('an up to date database is left alone', function () {
    (new RolesAndPermissionsSeeder)->run();

    $this->artisan('permissions:sync')->expectsOutputToContain('already up to date')->assertSuccessful();
});

test('a lifecycle permission new to the database reaches exactly its baseline roles and Super Admin', function () {
    (new RolesAndPermissionsSeeder)->run();
    Permission::findByName('reports.reopen', 'web')->delete();

    $this->artisan('permissions:sync')
        ->expectsOutputToContain('Permission added: reports.reopen')
        ->assertSuccessful();

    $holders = Role::query()->whereHas('permissions', fn ($query) => $query->where('name', 'reports.reopen'))->pluck('name')->all();

    expect($holders)->toEqualCanonicalizing(['Super Admin', 'MEAL', 'Public Relations Manager']);
});

test('a lifecycle permission granted by hand outside its baseline survives a sync', function () {
    (new RolesAndPermissionsSeeder)->run();
    Role::findByName('Managers', 'web')->givePermissionTo('supervisions.manage');
    Permission::findByName('sectors.manage', 'web')->delete();

    $this->artisan('permissions:sync')->assertSuccessful();

    expect(rolePermissionNames('Managers'))->toContain('supervisions.manage')
        ->and(rolePermissionNames('MEAL'))->toContain('sectors.manage');
});
