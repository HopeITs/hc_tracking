<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Permission catalog
    |--------------------------------------------------------------------------
    |
    | One entry per module. Each permission is seeded as "{module}.{action}".
    | Add a module here and re-run the RolesAndPermissionsSeeder to add new
    | permissions without touching any other file.
    */
    'groups' => [
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
        'reports' => ['view', 'create', 'update', 'delete'],
        'tasks' => ['view', 'create', 'update', 'delete'],
        'calendar' => ['view'],
        'executive-summary' => ['view'],
        'accounts' => ['view', 'create', 'update', 'delete'],
        'cities' => ['view', 'create', 'update', 'delete'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Role baselines
    |--------------------------------------------------------------------------
    |
    | Permissions granted to a role the moment it is created (see
    | App\Models\Role::booted()). "Super Admin" is handled unconditionally in
    | code and does not need an entry here. These are a starting point, not a
    | final answer — every one of them is editable straight away from the
    | Roles & Permissions admin page.
    */
    'administrator_roles' => ['Super Admin'],

    'role_baselines' => [
        'General Manager' => [
            'access-management.manage-users',
            'programs.view', 'projects.view', 'milestones.view', 'indicators.view',
            'results.view', 'risks.view', 'targets.view', 'donors.view', 'proposals.view',
            'funding-agreements.view', 'transfers.view', 'strategies.view', 'annual-plans.view',
            'reports.view', 'tasks.view', 'calendar.view', 'executive-summary.view',
            'accounts.view', 'cities.view',
        ],
        'Managers' => [
            'programs.view', 'programs.create', 'programs.update',
            'projects.view', 'projects.create', 'projects.update',
            'milestones.view', 'milestones.create', 'milestones.update',
            'indicators.view', 'indicators.create', 'indicators.update',
            'results.view', 'results.create', 'results.update',
            'risks.view', 'risks.create', 'risks.update',
            'targets.view', 'targets.create', 'targets.update',
            'strategies.view', 'strategies.create', 'strategies.update', 'strategies.delete',
            'annual-plans.view', 'annual-plans.create', 'annual-plans.update',
            'tasks.view', 'tasks.create', 'tasks.update',
            'reports.view', 'reports.create', 'reports.update',
            'donors.view', 'proposals.view', 'funding-agreements.view', 'transfers.view',
            'calendar.view', 'executive-summary.view',
        ],
        'MEAL' => [
            'indicators.view', 'indicators.create', 'indicators.update',
            'results.view', 'results.create', 'results.update',
            'reports.view', 'reports.create', 'reports.update',
            'programs.view', 'projects.view', 'milestones.view', 'risks.view', 'targets.view',
            'annual-plans.view', 'tasks.view', 'calendar.view', 'executive-summary.view',
        ],
        'Financial' => [
            'donors.view', 'donors.create', 'donors.update', 'donors.delete',
            'proposals.view', 'proposals.create', 'proposals.update', 'proposals.delete',
            'funding-agreements.view', 'funding-agreements.create', 'funding-agreements.update', 'funding-agreements.delete',
            'transfers.view', 'transfers.create', 'transfers.update', 'transfers.delete',
            'accounts.view', 'accounts.create', 'accounts.update', 'accounts.delete',
            'programs.view', 'projects.view', 'calendar.view',
        ],
        'Media Team' => [
            'reports.view', 'reports.create', 'reports.update',
            'programs.view', 'projects.view', 'calendar.view',
        ],
        'Public Relations' => [
            'reports.view', 'reports.create', 'reports.update',
            'programs.view', 'projects.view', 'donors.view', 'calendar.view',
        ],
        'Public Relations Manager' => [
            'reports.view', 'reports.create', 'reports.update', 'reports.delete',
            'programs.view', 'projects.view', 'donors.view', 'proposals.view',
            'funding-agreements.view', 'calendar.view',
        ],
    ],
];
