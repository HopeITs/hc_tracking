<?php

use App\Models\Permission;
use App\Models\Role;

/**
 * Every module's view permission. Every role holds all of them (user
 * decision 2026-09-25: everyone may view every module page).
 *
 * @return list<string>
 */
function everyViewPermission(): array
{
    return collect(config('access-control.groups'))
        ->filter(fn (array $actions): bool => in_array('view', $actions, true))
        ->keys()
        ->map(fn (string $module): string => "{$module}.view")
        ->values()
        ->all();
}

test('each role holds every view permission plus exactly its approved write permissions', function (string $roleName, array $writes) {
    $granted = Role::findByName($roleName, 'web')->permissions->pluck('name')->all();

    expect($granted)->toEqualCanonicalizing([...everyViewPermission(), ...$writes]);
})->with([
    'Team Member' => ['Team Member', []],
    'Media Team' => ['Media Team', ['tasks.create', 'tasks.update', 'reports.create', 'reports.update']],
    'Public Relations' => ['Public Relations', ['tasks.create', 'tasks.update', 'reports.create', 'reports.update']],
    'Public Relations Manager' => ['Public Relations Manager', [
        'tasks.create', 'tasks.update', 'reports.create', 'reports.update', 'reports.delete',
        'donors.create', 'donors.update', 'proposals.create', 'proposals.update',
    ]],
    'MEAL' => ['MEAL', [
        'tasks.create', 'tasks.update', 'reports.create', 'reports.update', 'reports.approve', 'reports.mark-seen',
        'indicators.create', 'indicators.update', 'indicators.delete',
        'results.create', 'results.update', 'results.delete',
        'targets.create', 'targets.update', 'targets.delete',
    ]],
    'Financial' => ['Financial', [
        'tasks.create', 'tasks.update',
        'donors.create', 'donors.update', 'donors.delete',
        'proposals.create', 'proposals.update', 'proposals.delete',
        'funding-agreements.create', 'funding-agreements.update', 'funding-agreements.delete',
        'transfers.create', 'transfers.update', 'transfers.delete',
        'accounts.create', 'accounts.update', 'accounts.delete',
    ]],
    'Managers' => ['Managers', [
        'tasks.create', 'tasks.update', 'tasks.delete', 'reports.create', 'reports.update',
        'strategies.create', 'strategies.update', 'strategies.delete',
        'programs.create', 'programs.update',
        'annual-plans.create', 'annual-plans.update', 'annual-plans.delete',
        'projects.create', 'projects.update', 'projects.delete',
        'milestones.create', 'milestones.update', 'milestones.delete',
        'risks.create', 'risks.update', 'risks.delete',
        'indicators.create', 'indicators.update',
        'results.create', 'results.update',
        'targets.create', 'targets.update',
    ]],
]);

test('the General Manager holds everything except role and permission management and the MEAL acknowledgement', function () {
    $granted = Role::findByName('General Manager', 'web')->permissions->pluck('name')->all();
    $expected = Permission::query()
        ->whereNotIn('name', ['access-management.manage-roles', 'access-management.manage-permissions', 'reports.mark-seen'])
        ->pluck('name')
        ->all();

    expect($granted)->toEqualCanonicalizing($expected);
});

test('Super Admin holds every permission in the catalog', function () {
    expect(Role::findByName('Super Admin', 'web')->permissions()->count())->toBe(Permission::query()->count());
});

test('reports gain approve and mark-seen permissions', function () {
    expect(config('access-control.groups.reports'))->toBe(['view', 'create', 'update', 'delete', 'approve', 'mark-seen']);
});

test('Team Member is the default role and is department-scoped for tasks', function () {
    expect(config('access-control.default_role'))->toBe('Team Member')
        ->and(config('access-control.task_department_scoped_roles'))->toContain('Team Member')
        ->and(config('access-control.task_department_scope_bypass_roles'))->not->toContain('Team Member');
});
