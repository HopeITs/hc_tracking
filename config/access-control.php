<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Permission catalog
|--------------------------------------------------------------------------
|
| One entry per module. Each permission is seeded as "{module}.{action}".
| Add a module here and re-run the RolesAndPermissionsSeeder to add new
| permissions without touching any other file. The catalog is built before
| the returned array so the role baselines below can be derived from it.
*/
$groups = [
    'access-management' => ['manage-users', 'manage-roles', 'manage-permissions'],
    'programs' => ['view', 'create', 'update', 'delete'],
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
    'cities' => ['view', 'create', 'update', 'delete'],
];

$allPermissions = [];

foreach ($groups as $module => $actions) {
    foreach ($actions as $action) {
        $allPermissions[] = "{$module}.{$action}";
    }
}

// Every role views every module (user decision 2026-09-25).
$viewEverything = array_values(array_filter($allPermissions, fn (string $permission): bool => str_ends_with($permission, '.view')));

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
    */
    'two_factor' => [
        'enforced' => $twoFactorEnforced,
        'required_roles' => ['Super Admin', 'General Manager', 'Managers', 'Financial'],
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
    | Granted when a role is created (App\Models\Role::booted()) and re-synced
    | by RolesAndPermissionsSeeder. Every role views every module; each list
    | adds what that role may change. "Super Admin" is handled in code. Every
    | one of these stays editable from the Admin > Users roles tab.
    */
    'role_baselines' => [
        'Team Member' => $viewEverything,
        'Media Team' => [...$viewEverything, ...$taskWriter, ...$reportWriter],
        'Public Relations' => [...$viewEverything, ...$taskWriter, ...$reportWriter],
        'Public Relations Manager' => [
            ...$viewEverything, ...$taskWriter, ...$reportWriter, 'reports.delete',
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
            ...$viewEverything, ...$taskWriter,
            'donors.create', 'donors.update', 'donors.delete',
            'proposals.create', 'proposals.update', 'proposals.delete',
            'funding-agreements.create', 'funding-agreements.update', 'funding-agreements.delete',
            'transfers.create', 'transfers.update', 'transfers.delete',
            'accounts.create', 'accounts.update', 'accounts.delete',
        ],
        'Managers' => [
            ...$viewEverything, ...$taskWriter, 'tasks.delete', ...$reportWriter,
            'strategies.create', 'strategies.update', 'strategies.delete',
            'programs.create', 'programs.update',
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
        ])),
    ],
];
