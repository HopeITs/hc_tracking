<?php

use App\Models\Permission;
use App\Models\Role;

const FINANCE_VIEWS = ['transfers.view', 'funding-agreements.view', 'accounts.view', 'executive-summary.view'];

const FUNDRAISING_VIEWS = ['donors.view', 'proposals.view'];

/**
 * Every module's view permission. Every role holds all of them (user
 * decision 2026-09-25: everyone may view every module page) — except the
 * activity log, which stays with Super Admin and General Manager, and the
 * money pages (user decision 2026-09-29), which only finance-facing roles hold.
 *
 * @return list<string>
 */
function everyViewPermission(): array
{
    return collect(config('access-control.groups'))
        ->filter(fn (array $actions): bool => in_array('view', $actions, true))
        ->keys()
        ->map(fn (string $module): string => "{$module}.view")
        ->reject(fn (string $permission): bool => in_array($permission, ['activity-log.view', ...FINANCE_VIEWS, ...FUNDRAISING_VIEWS], true))
        ->values()
        ->all();
}

test('the money pages are open only to the finance-facing roles', function (string $permission, array $roles) {
    $holders = Role::query()->whereHas('permissions', fn ($query) => $query->where('name', $permission))->pluck('name')->all();

    expect($holders)->toEqualCanonicalizing($roles);
})->with([
    'transfers' => ['transfers.view', ['Super Admin', 'General Manager', 'Financial']],
    'funding agreements' => ['funding-agreements.view', ['Super Admin', 'General Manager', 'Financial']],
    'bank accounts' => ['accounts.view', ['Super Admin', 'General Manager', 'Financial']],
    'executive summary' => ['executive-summary.view', ['Super Admin', 'General Manager', 'Financial']],
    'donors' => ['donors.view', ['Super Admin', 'General Manager', 'Financial', 'Public Relations Manager']],
    'proposals' => ['proposals.view', ['Super Admin', 'General Manager', 'Financial', 'Public Relations Manager']],
]);

test('only Super Admin, General Manager and Public Relations Manager may add projects by default', function () {
    $holders = Role::query()->whereHas('permissions', fn ($query) => $query->where('name', 'projects.create'))->pluck('name')->all();

    expect($holders)->toEqualCanonicalizing(['Super Admin', 'General Manager', 'Public Relations Manager']);
});

test('only Super Admin manages organisation notification settings by default', function () {
    $holders = Role::query()->whereHas('permissions', fn ($query) => $query->where('name', 'notification-settings.manage'))->pluck('name')->all();

    expect($holders)->toBe(['Super Admin']);
});

test('only Super Admin and General Manager may open the activity log by default', function () {
    $holders = Role::query()->whereHas('permissions', fn ($query) => $query->where('name', 'activity-log.view'))->pluck('name')->all();

    expect($holders)->toEqualCanonicalizing(['Super Admin', 'General Manager']);
});

test('each role holds every view permission plus exactly its approved write permissions', function (string $roleName, array $writes) {
    $granted = Role::findByName($roleName, 'web')->permissions->pluck('name')->all();

    expect($granted)->toEqualCanonicalizing([...everyViewPermission(), ...$writes]);
})->with([
    'Team Member' => ['Team Member', []],
    'Media Team' => ['Media Team', ['tasks.create', 'tasks.update', 'reports.create', 'reports.update']],
    'Public Relations' => ['Public Relations', ['tasks.create', 'tasks.update', 'reports.create', 'reports.update']],
    'Public Relations Manager' => ['Public Relations Manager', [
        ...FUNDRAISING_VIEWS,
        'tasks.create', 'tasks.update', 'reports.create', 'reports.update',
        'donors.create', 'donors.update', 'proposals.create', 'proposals.update',
        'projects.create',
    ]],
    'MEAL' => ['MEAL', [
        'tasks.create', 'tasks.update', 'reports.create', 'reports.update', 'reports.approve', 'reports.mark-seen',
        'indicators.create', 'indicators.update', 'indicators.delete',
        'results.create', 'results.update', 'results.delete',
        'targets.create', 'targets.update', 'targets.delete',
    ]],
    'Financial' => ['Financial', [
        ...FINANCE_VIEWS, ...FUNDRAISING_VIEWS,
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
        'programs.update',
        'annual-plans.create', 'annual-plans.update', 'annual-plans.delete',
        'projects.update', 'projects.delete',
        'milestones.create', 'milestones.update', 'milestones.delete',
        'risks.create', 'risks.update', 'risks.delete',
        'indicators.create', 'indicators.update',
        'results.create', 'results.update',
        'targets.create', 'targets.update',
    ]],
]);

test('the General Manager holds everything except role and permission management, the MEAL acknowledgement and notification settings', function () {
    $granted = Role::findByName('General Manager', 'web')->permissions->pluck('name')->all();
    $expected = Permission::query()
        ->whereNotIn('name', ['access-management.manage-roles', 'access-management.manage-permissions', 'reports.mark-seen', 'notification-settings.manage'])
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
