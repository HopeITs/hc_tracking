<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Permission catalog
|--------------------------------------------------------------------------
|
| One entry per module. Each permission is seeded as "{module}.{action}".
| Add a module here and run `php artisan permissions:sync` to add the new
| permissions without touching any other file (the RolesAndPermissionsSeeder
| resets every role, so it is for a fresh database only). The catalog is built before
| the returned array so the role baselines below can be derived from it.
*/
$groups = [
    'access-management' => ['manage-users', 'manage-roles', 'manage-permissions'],
    'programs' => ['view', 'update'],
    'projects' => ['view', 'create', 'update', 'delete'],
    'milestones' => ['view', 'create', 'update', 'delete'],
    'indicators' => ['view', 'create', 'update', 'delete'],
    'results' => ['view', 'create', 'update', 'delete'],
    'risks' => ['view', 'create', 'update', 'delete'],
    'targets' => ['view', 'create', 'update', 'delete'],
    'donors' => ['view', 'create', 'update', 'delete'],
    'proposals' => ['view', 'create', 'update', 'delete'],
    'funding-agreements' => ['view', 'create', 'update', 'delete'],
    'transfers' => ['view', 'create', 'update', 'delete'],
    'strategies' => ['view', 'create', 'update', 'delete'],
    'annual-plans' => ['view', 'create', 'update', 'delete'],
    'reports' => ['view', 'create', 'update', 'delete', 'approve', 'mark-seen'],
    'tasks' => ['view', 'create', 'update', 'delete'],
    'calendar' => ['view'],
    'executive-summary' => ['view'],
    'accounts' => ['view', 'create', 'update', 'delete'],
    'cities' => ['view'],
    'activity-log' => ['view'],
    'notification-settings' => ['manage'],
];

$allPermissions = [];

foreach ($groups as $module => $actions) {
    foreach ($actions as $action) {
        $allPermissions[] = "{$module}.{$action}";
    }
}

// Every role views every module (user decision 2026-09-25) — except the
// activity log (Super Admin and General Manager) and the money pages (user
// decision 2026-09-29), which only the finance-facing roles below add back.
$financeViews = ['transfers.view', 'funding-agreements.view', 'accounts.view', 'executive-summary.view'];
$fundraisingViews = ['donors.view', 'proposals.view'];
$restrictedViews = ['activity-log.view', ...$financeViews, ...$fundraisingViews];
$viewEverything = array_values(array_filter(
    $allPermissions,
    fn (string $permission): bool => str_ends_with($permission, '.view') && ! in_array($permission, $restrictedViews, true),
));

// Two-factor enforcement fails closed: only an explicit false/0/off/no turns
// it off; unset, empty or unrecognised values keep it on.
$twoFactorSetting = env('AUTH_TWO_FACTOR_ENFORCED');
$twoFactorEnforced = ! in_array(is_string($twoFactorSetting) ? strtolower(trim($twoFactorSetting)) : $twoFactorSetting, [false, '0', 'off', 'no'], true);

$taskWriter = ['tasks.create', 'tasks.update'];
$reportWriter = ['reports.create', 'reports.update'];

return [

    'groups' => $groups,

    /*
    |--------------------------------------------------------------------------
    | Administrator roles
    |--------------------------------------------------------------------------
    |
    | Always hold every permission (see App\Models\Role::booted()), and are the
    | only ones who may grant or remove roles carrying user-management rights
    | (see App\Policies\UserPolicy).
    */
    'administrator_roles' => ['Super Admin'],

    /*
    |--------------------------------------------------------------------------
    | Default role
    |--------------------------------------------------------------------------
    |
    | The role every new account starts with: User::forHrAccount(), the
    | Admin > Users create form and `user:create` when no role is chosen.
    */
    'default_role' => 'Team Member',

    /*
    |--------------------------------------------------------------------------
    | Required two-factor authentication
    |--------------------------------------------------------------------------
    |
    | Anyone holding one of these roles is kept on the security settings page
    | until they turn on two-factor authentication, and may not switch it off
    | (App\Http\Middleware\EnsureTwoFactorIsEnabled). AUTH_TWO_FACTOR_ENFORCED
    | switches the requirement off, as phpunit.xml does for the test suite.
    |
    | Two-factor is optional for every role (user decision 2026-09-28): each
    | person turns on email codes, the authenticator app or passkeys from the
    | security page. List role names here to make it required for them again.
    */
    'two_factor' => [
        'enforced' => $twoFactorEnforced,
        'required_roles' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Task department scoping
    |--------------------------------------------------------------------------
    |
    | task_department_scoped_roles only ever see tasks of their own HR
    | department, plus their own tasks and tasks on projects they answer for
    | (App\Models\Scopes\TaskVisibilityScope). A bypass role sees every
    | department and wins when a user holds both kinds.
    */
    'task_department_scope_bypass_roles' => ['Super Admin', 'General Manager', 'Managers'],
    'task_department_scoped_roles' => ['MEAL', 'Financial', 'Media Team', 'Public Relations', 'Public Relations Manager', 'Team Member'],

    /*
    |--------------------------------------------------------------------------
    | Role baselines
    |--------------------------------------------------------------------------
    |
    | Granted when a role is created (App\Models\Role::booted()). A permission
    | added to a list later reaches that role through `permissions:sync`,
    | which never takes one away. Every role views every module except the
    | activity log and the money pages; each list adds the money views that
    | role needs and what it may change. "Super Admin" is handled in code.
    | These nine names are the built-in roles: they cannot be renamed or
    | deleted (App\Policies\UserPolicy), but their permissions stay editable
    | from the Admin > Users roles tab.
    */
    'role_baselines' => [
        'Team Member' => $viewEverything,
        'Media Team' => [...$viewEverything, ...$taskWriter, ...$reportWriter],
        'Public Relations' => [...$viewEverything, ...$taskWriter, ...$reportWriter],
        'Public Relations Manager' => [
            ...$viewEverything, ...$fundraisingViews, ...$taskWriter, ...$reportWriter,
            'donors.create', 'donors.update',
            'proposals.create', 'proposals.update',
        ],
        'MEAL' => [
            ...$viewEverything, ...$taskWriter, ...$reportWriter, 'reports.approve', 'reports.mark-seen',
            'indicators.create', 'indicators.update', 'indicators.delete',
            'results.create', 'results.update', 'results.delete',
            'targets.create', 'targets.update', 'targets.delete',
        ],
        'Financial' => [
            ...$viewEverything, ...$financeViews, ...$fundraisingViews, ...$taskWriter,
            'donors.create', 'donors.update', 'donors.delete',
            'proposals.create', 'proposals.update', 'proposals.delete',
            'funding-agreements.create', 'funding-agreements.update', 'funding-agreements.delete',
            'transfers.create', 'transfers.update', 'transfers.delete',
            'accounts.create', 'accounts.update', 'accounts.delete',
        ],
        'Managers' => [
            ...$viewEverything, ...$taskWriter, 'tasks.delete', ...$reportWriter,
            'strategies.create', 'strategies.update', 'strategies.delete',
            'programs.update',
            'annual-plans.create', 'annual-plans.update', 'annual-plans.delete',
            'projects.create', 'projects.update', 'projects.delete',
            'milestones.create', 'milestones.update', 'milestones.delete',
            'risks.create', 'risks.update', 'risks.delete',
            'indicators.create', 'indicators.update',
            'results.create', 'results.update',
            'targets.create', 'targets.update',
        ],
        'General Manager' => array_values(array_diff($allPermissions, [
            'access-management.manage-roles',
            'access-management.manage-permissions',
            'reports.mark-seen',
            'notification-settings.manage',
        ])),
    ],
];
