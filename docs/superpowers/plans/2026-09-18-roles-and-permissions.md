# Dynamic Roles & Permissions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace hc-tracking's fixed 4-value `UserRole` enum with a dynamic roles/permissions system (`spatie/laravel-permission`), seeded with 8 named roles, a full permission catalog across every existing module, and two admin pages (Users, and a new Roles & Permissions page) built in hc-tracking's own design system.

**Architecture:** `spatie/laravel-permission` provides the `roles`/`permissions`/pivot tables. `App\Models\Role` (extends Spatie's) auto-grants a config-driven baseline permission set when a role is created. `config/access-control.php` is the single source of truth for the permission catalog (module → actions) and each seeded role's starting permissions. `User` gains `HasRoles`; the old `role` enum column is dropped in the same task that removes all its consumers, since the enum's deletion breaks every reference at once (Policies, the create-user command, the Users page, several tests) — there is no safe halfway state to ship separately.

**Tech Stack:** Laravel 13, Livewire/Volt (`pages::` view namespace), Flux UI, Pest, `spatie/laravel-permission`.

## Global Constraints

- PHP 8.5 only — run all `php`/`composer`/`artisan` commands via `/c/php85/php`, never the XAMPP-bundled 8.4.12 (`php` on PATH). Composer itself: `/c/php85/php $(which composer)` or the project's usual composer invocation with `/c/php85/php` prefixed if a bare `composer` resolves to the wrong PHP.
- Every class in `app/Models` keeps `declare(strict_types=1);`.
- Mass assignment/hidden fields use the Laravel 13 attributes `#[Fillable([...])]` / `#[Hidden([...])]`, not `protected $fillable`.
- New Blade/Livewire code uses hc-tracking's existing design system (Flux UI, `x-ui.page-header`, `.admin-directory`/`.directory-filters` CSS hooks) — never hc-hr's hardcoded red-branded classes.
- Bind `flux:modal` with `wire:model` (a boolean flag), never `:open`.
- Run `vendor/bin/pint --dirty --format agent` after any PHP edits, before considering a task done.
- Tests: `php artisan test --compact` or `vendor/bin/pest`, scoped to the relevant file/filter per task; ask the user to run the full suite only after all tasks pass.
- Never commit — leave changes staged for the user to review (existing project convention).
- Composer dependency addition (`spatie/laravel-permission`) is pre-approved by the user for this feature only.

---

## File Map

| File | Change |
| --- | --- |
| `composer.json` | + `spatie/laravel-permission` |
| `config/access-control.php` | New — permission catalog + role baselines |
| `app/Models/Role.php` | New — extends Spatie Role, auto-grants baseline on create |
| `app/Models/Permission.php` | New — extends Spatie Permission |
| `database/migrations/..._create_permission_tables.php` | New (Spatie's published migration) |
| `database/seeders/RolesAndPermissionsSeeder.php` | New — seeds catalog + 8 roles |
| `database/seeders/DatabaseSeeder.php` | + register the seeder |
| `database/migrations/..._drop_role_from_users_table.php` | New — drops `users.role` |
| `database/migrations/..._assign_super_admin_to_known_admins.php` | New — one-time data migration |
| `app/Enums/UserRole.php` | **Deleted** |
| `app/Models/User.php` | `HasRoles`, drop enum/column references |
| `app/Models/HrEmployee.php` | + `is_board_member` cast |
| `app/Policies/UserPolicy.php` | Permission-based checks |
| `app/Policies/StrategyPolicy.php` | Permission-based checks |
| `app/Console/Commands/CreateUserCommand.php` | Dynamic role name instead of enum |
| `database/factories/UserFactory.php` | `role()` state assigns a dynamic role |
| `resources/views/components/ui/user-chip.blade.php` | Show dynamic role name |
| `resources/views/components/navigation/sidebar.blade.php` | Drop stale guard, add Roles & Permissions link |
| `resources/views/pages/admin/⚡users.blade.php` | Dynamic role select |
| `resources/views/pages/admin/⚡roles.blade.php` | New — Roles & Permissions page |
| `routes/web.php` | + `admin.roles.index` |
| `lang/en/roles.php`, `lang/ar/roles.php` | New — new page's strings |
| `lang/en/users.php`, `lang/ar/users.php`, `lang/en/navigation.php`, `lang/ar/navigation.php` | + a few keys |
| Tests (listed per task) | Updated/added |

---

### Task 1: Install the package and define the permission catalog

**Files:**
- Modify: `composer.json`
- Create: `config/access-control.php`
- Create: `app/Models/Role.php`
- Create: `app/Models/Permission.php`
- Create: `database/migrations/2026_09_18_090000_create_permission_tables.php` (Spatie's own migration, vendor-published)
- Test: `tests/Feature/AccessControlCatalogTest.php`

**Interfaces:**
- Produces: `config('access-control.groups')` — `array<string, list<string>>`, module name => action suffixes (e.g. `'programs' => ['view', 'create', 'update', 'delete']`). `config('access-control.role_baselines')` — `array<string, list<string>>`, role name => permission names (`'{module}.{action}'`). `App\Models\Role` (extends `Spatie\Permission\Models\Role`), `App\Models\Permission` (extends `Spatie\Permission\Models\Permission`).

- [ ] **Step 1: Install the package**

```bash
/c/php85/php -d memory_limit=-1 $(where composer.phar 2>/dev/null || echo composer) require spatie/laravel-permission
```

If the shell's bare `composer` already resolves through PHP 8.5 (check with `composer --version` — it must not print a PHP 8.4 warning), simply run:

```bash
composer require spatie/laravel-permission
```

- [ ] **Step 2: Publish Spatie's migration and config, keep only the migration**

```bash
/c/php85/php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
```

This creates `config/permission.php` and a timestamped
`database/migrations/xxxx_xx_xx_xxxxxx_create_permission_tables.php`. Rename
the migration file to `2026_09_18_090000_create_permission_tables.php` (keep
today ahead of every existing migration; check `database/migrations` for the
current latest timestamp first and adjust if a later one already exists).
Leave `config/permission.php` at its published defaults — no team support,
default guard `web`, both match this app.

- [ ] **Step 3: Write the catalog config**

Create `config/access-control.php`:

```php
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
```

- [ ] **Step 4: `App\Models\Permission`**

Create `app/Models/Permission.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission {}
```

- [ ] **Step 5: `App\Models\Role`**

Create `app/Models/Role.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * A named collection of permissions a user is assigned.
 *
 * "Super Admin" (config('access-control.administrator_roles')) always holds
 * every permission, regardless of what config('access-control.role_baselines')
 * says — new modules must never leave it locked out. Every other role starts
 * with its configured baseline, editable afterward from the Roles &
 * Permissions admin page.
 */
class Role extends SpatieRole
{
    protected static function booted(): void
    {
        static::created(function (Role $role): void {
            if (in_array($role->name, config('access-control.administrator_roles', []), true)) {
                $role->givePermissionTo(Permission::all());
            } else {
                $baseline = config("access-control.role_baselines.{$role->name}", []);

                if ($baseline !== []) {
                    $role->givePermissionTo($baseline);
                }
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }
}
```

- [ ] **Step 6: Migrate**

```bash
/c/php85/php artisan migrate
```

- [ ] **Step 7: Write the failing test**

Create `tests/Feature/AccessControlCatalogTest.php`:

```php
<?php

use App\Models\Permission;
use App\Models\Role;

test('the permission catalog config drives permission names', function () {
    expect(config('access-control.groups'))->toHaveKey('programs')
        ->and(config('access-control.groups.programs'))->toBe(['view', 'create', 'update', 'delete']);
});

test('creating the Super Admin role grants it every permission that exists', function () {
    Permission::query()->create(['name' => 'programs.view', 'guard_name' => 'web']);
    Permission::query()->create(['name' => 'programs.delete', 'guard_name' => 'web']);

    $role = Role::query()->create(['name' => 'Super Admin', 'guard_name' => 'web']);

    expect($role->fresh()->permissions->pluck('name')->all())
        ->toEqualCanonicalizing(['programs.view', 'programs.delete']);
});

test('creating a configured role grants exactly its baseline', function () {
    Permission::query()->create(['name' => 'reports.view', 'guard_name' => 'web']);
    Permission::query()->create(['name' => 'reports.create', 'guard_name' => 'web']);
    Permission::query()->create(['name' => 'reports.update', 'guard_name' => 'web']);
    Permission::query()->create(['name' => 'programs.view', 'guard_name' => 'web']);
    Permission::query()->create(['name' => 'projects.view', 'guard_name' => 'web']);
    Permission::query()->create(['name' => 'calendar.view', 'guard_name' => 'web']);

    $role = Role::query()->create(['name' => 'Media Team', 'guard_name' => 'web']);

    expect($role->fresh()->permissions->pluck('name')->all())
        ->toEqualCanonicalizing(config('access-control.role_baselines.Media Team'));
});

test('creating an unconfigured role grants nothing automatically', function () {
    $role = Role::query()->create(['name' => 'Whatever', 'guard_name' => 'web']);

    expect($role->fresh()->permissions)->toBeEmpty();
});
```

- [ ] **Step 8: Run it to verify it passes**

Run: `/c/php85/php artisan test tests/Feature/AccessControlCatalogTest.php --compact`
Expected: PASS (this is a happy-path test written after the implementation, since the config/model *are* the deliverable — there is no separate "make it fail first" step for a config file).

- [ ] **Step 9: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add composer.json composer.lock config/access-control.php config/permission.php app/Models/Role.php app/Models/Permission.php database/migrations/2026_09_18_090000_create_permission_tables.php tests/Feature/AccessControlCatalogTest.php
git commit -m "feat: install spatie/laravel-permission and define the access-control catalog"
```

---

### Task 2: Seed the 8 roles and the permission catalog

**Files:**
- Create: `database/seeders/RolesAndPermissionsSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/RolesAndPermissionsSeederTest.php`

**Interfaces:**
- Consumes: `config('access-control.groups')`, `config('access-control.role_baselines')`, `App\Models\Role`, `App\Models\Permission` (Task 1).
- Produces: running `RolesAndPermissionsSeeder` leaves exactly these 19×~3.7 permissions and 8 roles in the database, each role holding its configured baseline (Super Admin holding all of them).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/RolesAndPermissionsSeederTest.php`:

```php
<?php

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;

test('the seeder creates one permission per module action and all 8 roles', function () {
    (new RolesAndPermissionsSeeder)->run();

    foreach (config('access-control.groups') as $module => $actions) {
        foreach ($actions as $action) {
            expect(Permission::query()->where('name', "{$module}.{$action}")->exists())->toBeTrue();
        }
    }

    expect(Role::query()->pluck('name')->all())->toEqualCanonicalizing([
        'Financial', 'Media Team', 'MEAL', 'Public Relations',
        'Public Relations Manager', 'Managers', 'Super Admin', 'General Manager',
    ]);
});

test('the seeder gives Super Admin every seeded permission', function () {
    (new RolesAndPermissionsSeeder)->run();

    $superAdmin = Role::query()->where('name', 'Super Admin')->sole();
    $totalPermissions = Permission::query()->count();

    expect($superAdmin->permissions()->count())->toBe($totalPermissions);
});

test('running the seeder twice does not duplicate roles or permissions', function () {
    (new RolesAndPermissionsSeeder)->run();
    (new RolesAndPermissionsSeeder)->run();

    expect(Role::query()->count())->toBe(8)
        ->and(Permission::query()->count())->toBe(
            collect(config('access-control.groups'))->flatten()->count()
        );
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `/c/php85/php artisan test tests/Feature/RolesAndPermissionsSeederTest.php --compact`
Expected: FAIL — `Class "Database\Seeders\RolesAndPermissionsSeeder" not found`.

- [ ] **Step 3: Write the seeder**

Create `database/seeders/RolesAndPermissionsSeeder.php`. It grants baselines
explicitly rather than relying on `Role::booted()`'s `created` event, because
`DatabaseSeeder` uses `WithoutModelEvents` and would otherwise mute it:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the full access-control permission catalog and the 8 application
 * roles, each with its config('access-control.role_baselines') starting
 * permission set. Idempotent: safe to run more than once.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('access-control.groups') as $module => $actions) {
            foreach ($actions as $action) {
                Permission::query()->firstOrCreate([
                    'name' => "{$module}.{$action}",
                    'guard_name' => 'web',
                ]);
            }
        }

        $allPermissions = Permission::all();

        foreach (config('access-control.role_baselines') as $roleName => $permissionNames) {
            $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($permissionNames);
        }

        foreach (config('access-control.administrator_roles') as $roleName) {
            $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($allPermissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
```

- [ ] **Step 4: Register it in `DatabaseSeeder`**

Modify `database/seeders/DatabaseSeeder.php` — add `RolesAndPermissionsSeeder::class` as the first entry in the `$this->call([...])` array (before `CoreDataSeeder::class`), since nothing else depends on it but it should exist before any domain data that might reference roles.

- [ ] **Step 5: Run it to verify it passes**

Run: `/c/php85/php artisan test tests/Feature/RolesAndPermissionsSeederTest.php --compact`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/seeders/RolesAndPermissionsSeeder.php database/seeders/DatabaseSeeder.php tests/Feature/RolesAndPermissionsSeederTest.php
git commit -m "feat: seed the 8 access-control roles and their permission baselines"
```

---

### Task 3: Replace `UserRole` with dynamic roles everywhere (the cutover)

This is one task, not several, because deleting `App\Enums\UserRole` breaks
every consumer at once — there is no working intermediate state to split
across separate reviewable commits. Do every step below before running the
full suite.

**Files:**
- Delete: `app/Enums/UserRole.php`
- Delete: `tests/Feature/UserRoleTest.php`
- Modify: `app/Models/User.php`
- Modify: `database/factories/UserFactory.php`
- Modify: `app/Policies/UserPolicy.php`
- Modify: `app/Policies/StrategyPolicy.php`
- Modify: `app/Console/Commands/CreateUserCommand.php`
- Modify: `resources/views/components/ui/user-chip.blade.php`
- Modify: `resources/views/components/navigation/sidebar.blade.php`
- Modify: `resources/views/pages/admin/⚡users.blade.php`
- Modify: `lang/en/users.php`, `lang/ar/users.php`
- Create: `database/migrations/2026_09_18_090100_drop_role_from_users_table.php`
- Create: `database/migrations/2026_09_18_090200_assign_super_admin_to_known_admins.php`
- Move + rewrite in full: `tests/Unit/Policies/UserPolicyTest.php` → `tests/Feature/Policies/UserPolicyTest.php`
- Modify (rewrite in full): `tests/Feature/StrategyManagementTest.php`
- Modify (rewrite in full): `tests/Feature/Admin/UserDirectoryTest.php`
- Modify (rewrite in full): `tests/Feature/CreateUserCommandTest.php`
- Modify (rewrite in full): `tests/Feature/SyncHrUsersCommandTest.php`

**Interfaces:**
- Consumes: `App\Models\Role` (Task 1), the 8 seeded roles (Task 2 — tests create roles inline via `Role::findOrCreate()` since `RefreshDatabase` does not run seeders).
- Produces: `User::forHrAccount(HrUser $hrUser): self` (no longer assigns a default role — new users start roleless unless their email is in `auth.hr_admin_emails`, in which case they get `Super Admin`). `User::createFromHrAccount(HrUser $hrUser, ?string $roleName, bool $verified = true): self`. `UserFactory::role(string $roleName): static`.

- [ ] **Step 1: Data migration — assign Super Admin to the 2 known admins, by email**

Create `database/migrations/2026_09_18_090200_assign_super_admin_to_known_admins.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The only two accounts holding UserRole::Admin at the time this system
     * was built. Every other user (all currently Officer) is deliberately
     * left with no role — see docs/superpowers/specs/2026-09-18-roles-and-permissions-design.md.
     */
    public function up(): void
    {
        $superAdmin = Role::query()->firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        User::query()
            ->whereIn('email', ['mai.youssef@hcsyria.org', 'youssef.kharbout@hcsyria.org'])
            ->get()
            ->each(fn (User $user) => $user->assignRole($superAdmin));
    }

    public function down(): void
    {
        User::query()
            ->whereIn('email', ['mai.youssef@hcsyria.org', 'youssef.kharbout@hcsyria.org'])
            ->get()
            ->each(fn (User $user) => $user->removeRole('Super Admin'));
    }
};
```

- [ ] **Step 2: Drop the `role` column**

Create `database/migrations/2026_09_18_090100_drop_role_from_users_table.php`
(runs before the data migration above — confirm its timestamp sorts earlier,
`090100` < `090200`):

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 50)->default('Officer')->after('employee_id');
        });
    }
};
```

- [ ] **Step 3: Delete the enum and its dedicated test**

```bash
rm app/Enums/UserRole.php tests/Feature/UserRoleTest.php
```

- [ ] **Step 4: Rewrite `app/Models/User.php`**

Remove the `use App\Enums\UserRole;` import, `UserRole` from the class
docblock's `@property`, `'role' => 'Officer'` from `$attributes`, and
`'role' => UserRole::class` from `casts()`. Add `use Spatie\Permission\Traits\HasRoles;`
to the `use` list. Replace `forHrAccount()` and `createFromHrAccount()`:

```php
    /**
     * The tracking user for an HR account, created on first use.
     *
     * Every HR account may use tracking. A new user starts with no role,
     * except an email listed in auth.hr_admin_emails, which starts as Super
     * Admin.
     */
    public static function forHrAccount(HrUser $hrUser): self
    {
        $user = static::query()->where('email', $hrUser->email)->first();

        if ($user !== null) {
            return $user;
        }

        $role = in_array(mb_strtolower($hrUser->email), config('auth.hr_admin_emails', []), true)
            ? 'Super Admin'
            : null;

        return static::createFromHrAccount($hrUser, $role);
    }
```

```php
    /**
     * Give an HR account access to hc-tracking, optionally with a role.
     *
     * Name and email are taken from HR. No local password is stored: people
     * always log in with their HR password.
     */
    public static function createFromHrAccount(HrUser $hrUser, ?string $roleName, bool $verified = true): self
    {
        $user = new self;

        $user->forceFill([
            'name' => $hrUser->employee?->name_en ?: $hrUser->email,
            'email' => $hrUser->email,
            'hr_user_id' => $hrUser->id,
            'employee_id' => $hrUser->employee_id,
            'password' => null,
            'email_verified_at' => $verified ? now() : null,
        ])->save();

        if ($roleName !== null) {
            $user->assignRole(Role::findOrCreate($roleName));
        }

        return $user;
    }
```

Add `use App\Models\Role;` (or reference `\App\Models\Role` inline — match
the file's existing import style, which imports models by short name) and
`use Spatie\Permission\Traits\HasRoles;` to the `use HasFactory, Notifiable, ...;`
trait list.

- [ ] **Step 5: Rewrite `UserFactory::role()`**

In `database/factories/UserFactory.php`, remove `use App\Enums\UserRole;` and
replace the `role()` state:

```php
    /**
     * Assign the user an existing (or newly-created) application role.
     */
    public function role(string $roleName): static
    {
        return $this->afterCreating(function (User $user) use ($roleName): void {
            $user->assignRole(\App\Models\Role::findOrCreate($roleName, 'web'));
        });
    }
```

- [ ] **Step 6: Rewrite `UserPolicy`**

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('access-management.manage-users');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $targetUser): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $targetUser): bool
    {
        return $this->viewAny($user) && ! $user->is($targetUser);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $targetUser): bool
    {
        return $this->viewAny($user) && ! $user->is($targetUser);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $targetUser): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $targetUser): bool
    {
        return false;
    }
}
```

- [ ] **Step 7: Rewrite `StrategyPolicy`**

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Strategy;
use App\Models\User;

class StrategyPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Strategy $strategy): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('strategies.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Strategy $strategy): bool
    {
        return $user->can('strategies.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Strategy $strategy): bool
    {
        return $user->can('strategies.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Strategy $strategy): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Strategy $strategy): bool
    {
        return false;
    }
}
```

- [ ] **Step 8: Rewrite `CreateUserCommand`**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HrUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Gives an HR account access to hc-tracking.
 *
 * Self-registration is disabled and passwords live in the HR system, so an
 * account is an existing HR login plus an optional application role. Name
 * and email are taken from HR; the person logs in with their HR password.
 */
class CreateUserCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'user:create
        {--email= : The email of an existing HR system account}
        {--role= : An existing role name; omit for no role}
        {--unverified : Leave the email address unverified}';

    /**
     * @var string
     */
    protected $description = 'Give an HR system account access, optionally with a role';

    /**
     * Collect the details, validate them, and store the user.
     */
    public function handle(): int
    {
        $interactive = $this->input->isInteractive();

        $email = $this->option('email') ?? ($interactive ? text('HR account email', required: true) : null);

        $roleNames = Role::query()->orderBy('name')->pluck('name')->all();

        $role = $this->option('role')
            ?? ($interactive ? select('Role', array_merge(['(none)'], $roleNames), '(none)') : null);
        $role = $role === '(none)' ? null : $role;

        $validator = Validator::make([
            'email' => $email,
            'role' => $role,
        ], [
            'email' => ['required', 'string', 'email', Rule::unique(User::class)],
            'role' => ['nullable', 'string', Rule::in($roleNames)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $hrUser = HrUser::query()->with('employee')->where('email', $email)->first();

        if ($hrUser === null) {
            $this->components->error("No HR system account uses {$email}.");

            return self::FAILURE;
        }

        $user = User::createFromHrAccount($hrUser, $validated['role'], verified: ! $this->option('unverified'));

        $this->components->info("Created {$user->email}".($validated['role'] !== null ? " as {$validated['role']}." : ' with no role.'));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 9: Update `user-chip.blade.php`**

Replace `{{ $user->role->label() }} · {{ $user->email }}` with a dynamic
role, falling back cleanly when the user has none:

```blade
<p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">
    {{ $user->roles->first()?->name ?? __('users.no_role') }} · {{ $user->email }}
</p>
```

Add `'no_role' => 'No role'` to `lang/en/users.php` and `'no_role' => 'بلا دور'`
to `lang/ar/users.php`.

- [ ] **Step 10: Sidebar — drop the stale guard, add the new link**

In `resources/views/components/navigation/sidebar.blade.php`, the existing
Users link is wrapped in `@if (auth()->user()?->getRawOriginal('role') !== null)`
— that guard existed only because reading `$user->role` could throw under
strict mode before the user round-tripped through the database; the column
no longer exists, so delete that `@if`/`@endif` wrapper (keep the `@can`
inside it). Add a new item right after it, in the same `Administration`
group:

```blade
@can('viewAny', App\Models\User::class)
    <flux:sidebar.item
        icon="users"
        :href="route('admin.users.index')"
        :current="request()->routeIs('admin.users.*')"
        :class="$navigationItemClasses"
        wire:navigate
    >
        {{ __('navigation.user_directory') }}
    </flux:sidebar.item>
@endcan

@can('access-management.manage-roles')
    <flux:sidebar.item
        icon="shield-check"
        :href="route('admin.roles.index')"
        :current="request()->routeIs('admin.roles.*')"
        :class="$navigationItemClasses"
        wire:navigate
    >
        {{ __('navigation.roles_and_permissions') }}
    </flux:sidebar.item>
@endcan
```

Add `'roles_and_permissions' => 'Roles & permissions'` to
`lang/en/navigation.php` and `'roles_and_permissions' => 'الأدوار والصلاحيات'`
to `lang/ar/navigation.php`.

- [ ] **Step 11: Update `admin/⚡users.blade.php`**

Replace every `UserRole` reference:
- Remove `use App\Enums\UserRole;`; add `use App\Models\Role;`.
- `public string $role = '';` (the filter) and `public string $selectedRole = UserRole::Officer->value;`
  become `public string $selectedRole = '';` (empty = no role — matches the
  new nullable-role model; the filter property `$role` stays a plain string,
  now holding a role **name** instead of an enum value).
- `roleOptions()`: `return UserRole::cases();` becomes:

```php
    /**
     * @return \Illuminate\Support\Collection<int, Role>
     */
    #[Computed]
    public function roleOptions(): Collection
    {
        return Role::query()->orderBy('name')->get();
    }
```

- The `users()` query's `->when($this->role !== '', fn ($query) => $query->where('role', $this->role))`
  becomes a role-name filter through Spatie's pivot:
  `->when($this->role !== '', fn ($query) => $query->whereHas('roles', fn ($q) => $q->where('name', $this->role)))`.
  Also add `->with('roles')` to the base query (replacing the `select`d
  `role` column, which no longer exists) so the table can show each user's
  role without N+1 queries.
- `openEditModal()`: `$this->selectedRole = $user->role->value;` becomes
  `$this->selectedRole = $user->roles->first()?->name ?? '';`.
- `saveUser()`: both validation blocks change
  `'selectedRole' => ['required', Rule::enum(UserRole::class)]` to
  `'selectedRole' => ['nullable', 'string', Rule::in(Role::query()->pluck('name'))]`
  (role assignment is now optional, matching the "no role by default" data
  decision). Replace `$user->forceFill(['role' => UserRole::from($validated['selectedRole'])])->save();`
  with:

```php
            if ($validated['selectedRole'] !== null && $validated['selectedRole'] !== '') {
                $user->syncRoles([Role::findOrCreate($validated['selectedRole'], 'web')]);
            } else {
                $user->syncRoles([]);
            }
```

  and the create branch's `User::createFromHrAccount($hrUser, UserRole::from($validated['selectedRole']));`
  becomes `User::createFromHrAccount($hrUser, $validated['selectedRole'] !== '' ? $validated['selectedRole'] : null);`.
- `resetUserForm()`: `$this->selectedRole = UserRole::Officer->value;` becomes
  `$this->selectedRole = '';`.
- In the Blade section: remove the `$roleBadgeClasses` match-on-enum closure
  (replace both its call sites — the mobile card and the desktop table row —
  with a single neutral badge class, since roles are now open-ended and
  can't be hardcoded per-case):

```blade
    $roleBadgeClasses = 'border-secondary-light bg-secondary-light text-white dark:border-secondary-medium dark:bg-secondary-medium';
```

  and change `{{ $roleBadgeClasses($user->role) }}` (both occurrences) to
  `{{ $roleBadgeClasses }}`, and `{{ $user->role->label() }}` (both
  occurrences) to `{{ $user->roles->first()?->name ?? __('users.no_role') }}`.
- The role filter `<x-ui.header-filter-select>` loop `@foreach ($this->roleOptions as $roleOption)`
  changes `:value="$roleOption->value"` to `:value="$roleOption->name"` and
  `{{ $roleOption->label() }}` to `{{ $roleOption->name }}` (both the filter
  select and the create/edit modal's role select use the same pattern —
  update all 3 occurrences: header filter, create/edit modal select, and the
  modal's per-role help text `match (UserRole::tryFrom($selectedRole)) {...}`
  block, which is deleted entirely — dynamic roles have no hardcoded
  description, so drop that `<p>` help text under the role select).

- [ ] **Step 12: Move `UserPolicyTest` from Unit to Feature and rewrite it**

The original test lived in `tests/Unit/Policies/UserPolicyTest.php` and
avoided the database entirely (`setRawAttributes()` + the enum). That no
longer works: permission checks (`$user->can(...)`, `assignRole()`) need
real `roles`/`permissions`/pivot tables, and `tests/Pest.php` only wires
`RefreshDatabase` + the in-memory HR schema onto `tests/Feature/**`
(`pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature')`)
— `tests/Unit/**` runs on plain `PHPUnit\Framework\TestCase` with no app or
database. So this test moves to `tests/Feature/Policies/UserPolicyTest.php`:

```bash
mkdir -p tests/Feature/Policies
rm tests/Unit/Policies/UserPolicyTest.php
```

Create `tests/Feature/Policies/UserPolicyTest.php`:

```php
<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Policies\UserPolicy;

function userForPolicy(int $id, ?string $roleName): User
{
    $user = new User;
    $user->setRawAttributes(['id' => $id]);
    $user->exists = true;

    if ($roleName !== null) {
        $user->assignRole(Role::findOrCreate($roleName, 'web'));
    }

    return $user;
}

beforeEach(function () {
    Permission::query()->create(['name' => 'access-management.manage-users', 'guard_name' => 'web']);
    Role::query()->create(['name' => 'Super Admin', 'guard_name' => 'web'])
        ->givePermissionTo('access-management.manage-users');
    Role::query()->create(['name' => 'Managers', 'guard_name' => 'web']);
});

test('only a role with the manage-users permission can list view and create users', function (?string $role, bool $allowed) {
    $actor = userForPolicy(1, $role);
    $targetUser = userForPolicy(2, null);
    $policy = new UserPolicy;

    expect($policy->viewAny($actor))->toBe($allowed)
        ->and($policy->view($actor, $targetUser))->toBe($allowed)
        ->and($policy->create($actor))->toBe($allowed);
})->with([
    'no role' => [null, false],
    'managers (no manage-users permission)' => ['Managers', false],
    'super admin' => ['Super Admin', true],
]);

test('a manager-of-users can update and delete other users but not themselves', function () {
    $admin = userForPolicy(1, 'Super Admin');
    $otherUser = userForPolicy(2, null);
    $policy = new UserPolicy;

    expect($policy->update($admin, $otherUser))->toBeTrue()
        ->and($policy->delete($admin, $otherUser))->toBeTrue()
        ->and($policy->update($admin, $admin))->toBeFalse()
        ->and($policy->delete($admin, $admin))->toBeFalse()
        ->and($policy->restore($admin, $otherUser))->toBeFalse()
        ->and($policy->forceDelete($admin, $otherUser))->toBeFalse();
});

test('a user without the manage-users permission cannot update or delete users', function () {
    $actor = userForPolicy(1, 'Managers');
    $targetUser = userForPolicy(2, null);
    $policy = new UserPolicy;

    expect($policy->update($actor, $targetUser))->toBeFalse()
        ->and($policy->delete($actor, $targetUser))->toBeFalse();
});
```

- [ ] **Step 13: Rewrite `tests/Feature/StrategyManagementTest.php`**

Remove `use App\Enums\UserRole;`. Every `User::factory()->role(UserRole::Manager)->create()`
becomes `User::factory()->role('Managers')->create()`, every
`User::factory()->role(UserRole::Admin)->create()` becomes
`User::factory()->role('Super Admin')->create()`. Because roles are now
assigned via `Role::findOrCreate()` inside the factory, and permissions are
only granted through `config('access-control.role_baselines')` (which only
`RolesAndPermissionsSeeder` applies), each test must ensure the relevant
permission exists on the role before asserting behavior driven by it. Add a
`beforeEach` that seeds just what this file's tests need:

```php
<?php

use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Permission::query()->firstOrCreate(['name' => 'strategies.create', 'guard_name' => 'web']);
    Permission::query()->firstOrCreate(['name' => 'strategies.update', 'guard_name' => 'web']);
    Permission::query()->firstOrCreate(['name' => 'strategies.delete', 'guard_name' => 'web']);

    Role::findOrCreate('Managers', 'web')->givePermissionTo(['strategies.create', 'strategies.update', 'strategies.delete']);
    Role::findOrCreate('Super Admin', 'web')->givePermissionTo(['strategies.create', 'strategies.update', 'strategies.delete']);
    Role::findOrCreate('MEAL', 'web');
});
```

Then apply these replacements through the rest of the file (every occurrence,
same behavior as before — a "manager" is now a `'Managers'` role holder, an
"admin" a `'Super Admin'` role holder, "officer"/"meal" role holders that
were never given the strategy permissions above stay forbidden):

- `User::factory()->role(UserRole::Manager)->create()` → `User::factory()->role('Managers')->create()`
- `User::factory()->role(UserRole::Admin)->create()` → `User::factory()->role('Super Admin')->create()`
- The two `->with([...])` datasets using `UserRole::Officer`/`UserRole::Meal`
  (`'officers and meal staff cannot manage strategies'` and
  `'the add strategy control is hidden from officers and meal staff'`) change
  their `function (UserRole $role)` signature to `function (string $roleName)`
  and their dataset values from `UserRole::Officer`/`UserRole::Meal` to
  `'Officer'`/`'MEAL'` (any role name not granted the strategy permissions —
  `'Officer'` is no longer a real seeded role, but `Role::findOrCreate()`
  inside the factory will create it on demand with zero permissions, which is
  exactly the "cannot manage strategies" case being tested); update
  `User::factory()->role($role)->create()` to `User::factory()->role($roleName)->create()`
  inside each test body.
- `'every role can open a strategy through the view icon, not the name'`'s
  dataset becomes `'officer' => 'Officer', 'meal' => 'MEAL', 'manager' => 'Managers', 'admin' => 'Super Admin'`,
  with the test body's `function (UserRole $role)` → `function (string $roleName)`
  and `User::factory()->role($role)->create()` → `User::factory()->role($roleName)->create()`.

- [ ] **Step 14: Rewrite `tests/Feature/Admin/UserDirectoryTest.php`**

Remove `use App\Enums\UserRole;`; add `use App\Models\Role;`. Add a
`beforeEach` seeding the roles this file exercises:

```php
beforeEach(function () {
    Role::findOrCreate('Super Admin', 'web')->givePermissionTo(
        \App\Models\Permission::query()->firstOrCreate(['name' => 'access-management.manage-users', 'guard_name' => 'web'])
    );
    Role::findOrCreate('Officer', 'web');
    Role::findOrCreate('Managers', 'web');
    Role::findOrCreate('MEAL', 'web');
});
```

Replace every `UserRole::Admin` with `'Super Admin'`, `UserRole::Officer`
with `'Officer'`, `UserRole::Manager` with `'Managers'`, `UserRole::Meal`
with `'MEAL'` — as plain strings, both in `User::factory()->role(...)` calls
and in `->set('role', ...)` / `->set('selectedRole', ...)` Livewire calls
(drop the `->value` — these become plain role-name strings directly, e.g.
`->set('role', 'Managers')` instead of `->set('role', UserRole::Manager->value)`).
Specific line-level changes:
- `function (UserRole $role)` test signatures → `function (string $roleName)`;
  their `->with([...])` datasets keep the same string values already listed
  above, just without the enum wrapper (e.g. `'officer' => 'Officer'`).
- In `'administrators can give an hr account access with any role'`, replace
  `->and($user->role)->toBe($role)` with
  `->and($user->roles->first()?->name)->toBe($role)`.
- In `'administrators can change another users role but not their hr details'`,
  Pest's fluent `expect($user->refresh())->role->toBe(...)->name->toBe(...)`
  chain can't safely proxy through `->roles->first()?->name` (the nullsafe
  operator doesn't behave the same way through an `Expectation` wrapper), so
  switch that block to plain `->and()` calls instead:

```php
    $refreshed = $user->refresh();

    expect($refreshed->roles->first()?->name)->toBe('Managers')
        ->and($refreshed->name)->toBe('Rana Darwish')
        ->and($refreshed->email)->toBe('rana@example.org');
```
- `'a new user must name an available hr account and a valid role'` already
  uses the literal string `'Super Admin'` for `selectedRole` — leave as is,
  it now resolves against the real dynamic role name.

- [ ] **Step 15: Rewrite `tests/Feature/CreateUserCommandTest.php`**

```php
<?php

use App\Models\Role;
use App\Models\User;

test('the command gives an hr account access with the given role', function () {
    Role::findOrCreate('MEAL', 'web');
    $employeeId = insertHrEmployee(firstNameEn: 'Amina', lastNameEn: 'Haddad');
    $hrUserId = insertHrUser($employeeId, 'amina@example.org');

    $this->artisan('user:create', [
        '--email' => 'amina@example.org',
        '--role' => 'MEAL',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $user = User::query()->where('email', 'amina@example.org')->sole();

    expect($user->name)->toBe('Amina Haddad')
        ->and($user->roles->first()?->name)->toBe('MEAL')
        ->and($user->hr_user_id)->toBe($hrUserId)
        ->and($user->employee_id)->toBe($employeeId)
        ->and($user->password)->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull();
});

test('a user has no role when none is given', function () {
    insertHrUser(insertHrEmployee(), 'dana@example.org');

    $this->artisan('user:create', [
        '--email' => 'dana@example.org',
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(User::query()->sole()->roles)->toBeEmpty();
});

test('the unverified flag leaves the address unverified', function () {
    insertHrUser(insertHrEmployee(), 'dana@example.org');

    $this->artisan('user:create', [
        '--email' => 'dana@example.org',
        '--unverified' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(User::query()->sole()->email_verified_at)->toBeNull();
});

test('an email with no hr account is rejected', function () {
    $this->artisan('user:create', [
        '--email' => 'nobody@example.org',
        '--no-interaction' => true,
    ])->assertFailed();

    $this->assertDatabaseCount('users', 0);
});

test('an unknown role is rejected', function () {
    insertHrUser(insertHrEmployee(), 'dana@example.org');

    $this->artisan('user:create', [
        '--email' => 'dana@example.org',
        '--role' => 'not-a-real-role',
        '--no-interaction' => true,
    ])->assertFailed();

    $this->assertDatabaseCount('users', 0);
});

test('a duplicate email is rejected', function () {
    User::factory()->create(['email' => 'taken@example.org']);
    insertHrUser(insertHrEmployee(), 'taken@example.org');

    $this->artisan('user:create', [
        '--email' => 'taken@example.org',
        '--no-interaction' => true,
    ])->assertFailed();

    $this->assertDatabaseCount('users', 1);
});

test('a missing email is rejected', function () {
    $this->artisan('user:create', ['--no-interaction' => true])->assertFailed();

    $this->assertDatabaseCount('users', 0);
});
```

- [ ] **Step 16: Rewrite `tests/Feature/SyncHrUsersCommandTest.php`**

```php
<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('every hr account gets a tracking user with no role, listed admins as super admin', function () {
    config(['auth.hr_admin_emails' => ['admin@example.org']]);
    insertHrUser(insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Darwish'), 'rana@example.org');
    insertHrUser(insertHrEmployee(), 'admin@example.org');
    $before = DB::connection('hr')->table('users')->get()->toArray();

    $this->artisan('hr:sync-users')->assertSuccessful();

    expect(User::query()->where('email', 'rana@example.org')->sole())
        ->roles->toBeEmpty()
        ->name->toBe('Rana Darwish')
        ->and(User::query()->where('email', 'admin@example.org')->sole()->roles->first()?->name)->toBe('Super Admin')
        ->and(DB::connection('hr')->table('users')->get()->toArray())->toEqual($before);
});

test('syncing again leaves existing users and their roles alone', function () {
    $user = User::factory()->role('Managers')->create(['email' => 'rana@example.org']);
    insertHrUser(insertHrEmployee(), 'rana@example.org');

    $this->artisan('hr:sync-users')->assertSuccessful();
    $this->artisan('hr:sync-users')->assertSuccessful();

    expect(User::query()->count())->toBe(1)
        ->and($user->refresh()->roles->first()?->name)->toBe('Managers');
});
```

- [ ] **Step 17: Run every affected test**

Run:
```bash
/c/php85/php artisan test --compact tests/Feature/Policies/UserPolicyTest.php tests/Feature/StrategyManagementTest.php tests/Feature/Admin/UserDirectoryTest.php tests/Feature/CreateUserCommandTest.php tests/Feature/SyncHrUsersCommandTest.php tests/Feature/Models/UserTest.php
```
Expected: PASS. If `Models/UserTest.php` fails on anything role-related,
read it and apply the same string-role pattern used above — it wasn't listed
in the original grep results, so it likely needs no change, but confirm.

- [ ] **Step 18: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A -- app database/factories database/migrations resources/views/components/ui/user-chip.blade.php resources/views/components/navigation/sidebar.blade.php resources/views/pages/admin lang tests
git status
git commit -m "feat: replace the UserRole enum with dynamic roles across the app"
```

---

### Task 4: The Roles & Permissions admin page

**Files:**
- Create: `resources/views/pages/admin/⚡roles.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/components/navigation/sidebar.blade.php` (link already added in Task 3, Step 10 — this task only builds the page it points to)
- Create: `lang/en/roles.php`, `lang/ar/roles.php`
- Test: `tests/Feature/Admin/RolesAndPermissionsTest.php`

**Interfaces:**
- Consumes: `App\Models\Role`, `App\Models\Permission`, `config('access-control.groups')` (Task 1); `UserPolicy`-style authorization pattern (Task 3) — this page authorizes against a new `access-management.manage-roles` / `access-management.manage-permissions` permission pair, following the same `Gate::authorize` convention the Users page already uses.

- [ ] **Step 1: Add the route**

In `routes/web.php`, add right after the `admin.users.index` route:

```php
    Route::livewire('admin/roles', 'pages::admin.roles')
        ->can('access-management.manage-roles')
        ->name('admin.roles.index');
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Admin/RolesAndPermissionsTest.php`:

```php
<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    Permission::query()->firstOrCreate(['name' => 'access-management.manage-roles', 'guard_name' => 'web']);
    Permission::query()->firstOrCreate(['name' => 'access-management.manage-permissions', 'guard_name' => 'web']);
    Permission::query()->firstOrCreate(['name' => 'programs.view', 'guard_name' => 'web']);
    Permission::query()->firstOrCreate(['name' => 'programs.create', 'guard_name' => 'web']);

    Role::findOrCreate('Super Admin', 'web')->givePermissionTo([
        'access-management.manage-roles', 'access-management.manage-permissions',
        'programs.view', 'programs.create',
    ]);
});

function actingAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    return $user;
}

test('guests are redirected from the roles and permissions page', function () {
    $this->get(route('admin.roles.index'))->assertRedirect(route('login'));
});

test('a user without manage-roles is forbidden', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.roles.index'))->assertForbidden();
});

test('an authorized user sees every seeded role', function () {
    $admin = actingAdmin();
    Role::findOrCreate('Managers', 'web');

    $this->actingAs($admin)
        ->get(route('admin.roles.index'))
        ->assertOk()
        ->assertSeeText('Super Admin')
        ->assertSeeText('Managers');
});

test('an authorized user can create a role', function () {
    $admin = actingAdmin();

    Livewire::actingAs($admin)
        ->test('pages::admin.roles')
        ->set('newRoleName', 'Regional Auditor')
        ->call('createRole')
        ->assertHasNoErrors();

    expect(Role::query()->where('name', 'Regional Auditor')->exists())->toBeTrue();
});

test('a duplicate role name is rejected', function () {
    $admin = actingAdmin();
    Role::findOrCreate('Managers', 'web');

    Livewire::actingAs($admin)
        ->test('pages::admin.roles')
        ->set('newRoleName', 'Managers')
        ->call('createRole')
        ->assertHasErrors(['newRoleName']);
});

test('an authorized user can rename a non-protected role', function () {
    $admin = actingAdmin();
    $role = Role::findOrCreate('Managers', 'web');

    Livewire::actingAs($admin)
        ->test('pages::admin.roles')
        ->call('selectRole', $role->id)
        ->set('selectedRoleName', 'Programme Managers')
        ->call('renameRole')
        ->assertHasNoErrors();

    expect($role->refresh()->name)->toBe('Programme Managers');
});

test('super admin cannot be renamed or deleted', function () {
    $admin = actingAdmin();
    $superAdmin = Role::query()->where('name', 'Super Admin')->sole();

    Livewire::actingAs($admin)
        ->test('pages::admin.roles')
        ->call('selectRole', $superAdmin->id)
        ->set('selectedRoleName', 'Renamed')
        ->call('renameRole')
        ->assertForbidden();

    Livewire::actingAs($admin)
        ->test('pages::admin.roles')
        ->call('selectRole', $superAdmin->id)
        ->call('deleteRole')
        ->assertForbidden();

    expect(Role::query()->where('name', 'Super Admin')->exists())->toBeTrue();
});

test('an authorized user can delete a non-protected role with no users', function () {
    $admin = actingAdmin();
    $role = Role::findOrCreate('Media Team', 'web');

    Livewire::actingAs($admin)
        ->test('pages::admin.roles')
        ->call('selectRole', $role->id)
        ->call('deleteRole')
        ->assertHasNoErrors();

    expect(Role::query()->where('name', 'Media Team')->exists())->toBeFalse();
});

test('an authorized user can toggle a permission on a role', function () {
    $admin = actingAdmin();
    $role = Role::findOrCreate('Media Team', 'web');
    $permission = Permission::query()->where('name', 'programs.view')->sole();

    Livewire::actingAs($admin)
        ->test('pages::admin.roles')
        ->call('selectRole', $role->id)
        ->set('rolePermissionIds', [$permission->id])
        ->call('saveRolePermissions')
        ->assertHasNoErrors();

    expect($role->fresh()->hasPermissionTo('programs.view'))->toBeTrue();
});
```

- [ ] **Step 3: Run it to verify it fails**

Run: `/c/php85/php artisan test tests/Feature/Admin/RolesAndPermissionsTest.php --compact`
Expected: FAIL — the `pages::admin.roles` view doesn't exist yet.

- [ ] **Step 4: Write the lang files**

Create `lang/en/roles.php`:

```php
<?php

return [
    'title' => 'Roles & permissions',
    'new_role' => 'New role',
    'role_name_placeholder' => 'e.g. Regional Auditor',
    'create_role' => 'Create role',
    'rename_role' => 'Rename role',
    'delete_role' => 'Delete role',
    'protected_role' => 'Protected role',
    'protected_role_help' => 'Super Admin always holds every permission and cannot be renamed or deleted.',
    'permissions' => 'Permissions',
    'select_all' => 'Select all',
    'clear_all' => 'Clear all',
    'save_permissions' => 'Save permissions',
    'users_count' => '{0} No users|{1} :count user|[2,*] :count users',
    'select_a_role' => 'Select a role to manage its permissions.',
];
```

Create `lang/ar/roles.php`:

```php
<?php

return [
    'title' => 'الأدوار والصلاحيات',
    'new_role' => 'دور جديد',
    'role_name_placeholder' => 'مثال: مدقق إقليمي',
    'create_role' => 'إنشاء دور',
    'rename_role' => 'إعادة تسمية الدور',
    'delete_role' => 'حذف الدور',
    'protected_role' => 'دور محمي',
    'protected_role_help' => 'يمتلك دور المشرف العام كل الصلاحيات دائمًا ولا يمكن إعادة تسميته أو حذفه.',
    'permissions' => 'الصلاحيات',
    'select_all' => 'تحديد الكل',
    'clear_all' => 'إلغاء التحديد',
    'save_permissions' => 'حفظ الصلاحيات',
    'users_count' => '{0} لا يوجد مستخدمون|{1} مستخدم واحد|{2} مستخدمان|[3,10] :count مستخدمين|[11,*] :count مستخدمًا',
    'select_a_role' => 'اختر دورًا لإدارة صلاحياته.',
];
```

- [ ] **Step 5: Build the page**

Create `resources/views/pages/admin/⚡roles.blade.php`:

```php
<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('roles.title')] class extends Component {
    public ?int $selectedRoleId = null;

    public string $newRoleName = '';

    public string $selectedRoleName = '';

    /** @var array<int, int> */
    public array $rolePermissionIds = [];

    public function mount(): void
    {
        Gate::authorize('access-management.manage-roles');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Role>
     */
    #[Computed]
    public function roles(): \Illuminate\Database\Eloquent\Collection
    {
        return Role::query()->withCount('users')->orderBy('name')->get();
    }

    #[Computed]
    public function selectedRole(): ?Role
    {
        return $this->selectedRoleId === null
            ? null
            : Role::query()->find($this->selectedRoleId);
    }

    #[Computed]
    public function selectedRoleIsProtected(): bool
    {
        return in_array($this->selectedRole?->name, config('access-control.administrator_roles', []), true);
    }

    /**
     * Permissions grouped by module, in config('access-control.groups') order.
     *
     * @return array<int, array{label: string, permissions: \Illuminate\Support\Collection<int, Permission>}>
     */
    #[Computed]
    public function permissionGroups(): array
    {
        $permissionsByName = Permission::all()->keyBy('name');
        $groups = [];

        foreach (config('access-control.groups') as $module => $actions) {
            $permissions = collect($actions)
                ->map(fn (string $action) => $permissionsByName->get("{$module}.{$action}"))
                ->filter();

            if ($permissions->isEmpty()) {
                continue;
            }

            $groups[] = [
                'label' => str($module)->replace('-', ' ')->headline(),
                'permissions' => $permissions->values(),
            ];
        }

        return $groups;
    }

    public function selectRole(int $roleId): void
    {
        Gate::authorize('access-management.manage-roles');

        $role = Role::query()->findOrFail($roleId);

        $this->selectedRoleId = $role->id;
        $this->selectedRoleName = $role->name;
        $this->rolePermissionIds = $role->permissions->pluck('id')->all();
    }

    public function createRole(): void
    {
        Gate::authorize('access-management.manage-roles');

        $validated = $this->validate([
            'newRoleName' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')],
        ]);

        $role = Role::query()->create(['name' => $validated['newRoleName'], 'guard_name' => 'web']);

        $this->newRoleName = '';
        $this->selectRole($role->id);
    }

    public function renameRole(): void
    {
        Gate::authorize('access-management.manage-roles');

        $role = Role::query()->findOrFail($this->selectedRoleId);

        abort_if(in_array($role->name, config('access-control.administrator_roles', []), true), 403);

        $validated = $this->validate([
            'selectedRoleName' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role->id)],
        ]);

        $role->update(['name' => $validated['selectedRoleName']]);
    }

    public function deleteRole(): void
    {
        Gate::authorize('access-management.manage-roles');

        $role = Role::query()->findOrFail($this->selectedRoleId);

        abort_if(in_array($role->name, config('access-control.administrator_roles', []), true), 403);

        $role->delete();

        $this->selectedRoleId = null;
        $this->selectedRoleName = '';
        $this->rolePermissionIds = [];
    }

    public function selectAllRolePermissions(): void
    {
        Gate::authorize('access-management.manage-permissions');

        $this->rolePermissionIds = Permission::query()->pluck('id')->all();
    }

    public function clearRolePermissions(): void
    {
        Gate::authorize('access-management.manage-permissions');

        $this->rolePermissionIds = [];
    }

    public function saveRolePermissions(): void
    {
        Gate::authorize('access-management.manage-permissions');

        $role = Role::query()->findOrFail($this->selectedRoleId);

        abort_if(in_array($role->name, config('access-control.administrator_roles', []), true), 403);

        $role->syncPermissions(
            Permission::query()->whereIn('id', $this->rolePermissionIds)->get()
        );
    }
};
?>

<section
    class="admin-directory @container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white"
    aria-labelledby="roles-heading"
>
    <x-ui.page-header heading-id="roles-heading" :title="__('roles.title')" :count="$this->roles->count()" />

    <div class="grid min-w-0 gap-4 xl:grid-cols-[19rem_minmax(0,1fr)]">
        <aside class="min-w-0 overflow-hidden rounded-lg border border-secondary-light bg-white dark:border-secondary-medium dark:bg-secondary-dark">
            <form wire:submit="createRole" class="space-y-2 border-b border-secondary-soft p-3 dark:border-secondary-medium">
                <flux:input
                    wire:model="newRoleName"
                    :label="__('roles.new_role')"
                    :placeholder="__('roles.role_name_placeholder')"
                />
                <flux:button type="submit" variant="primary" size="sm" class="w-full">
                    {{ __('roles.create_role') }}
                </flux:button>
            </form>

            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->roles as $role)
                    <button
                        type="button"
                        wire:click="selectRole({{ $role->id }})"
                        wire:key="role-{{ $role->id }}"
                        class="flex min-h-12 w-full items-center justify-between gap-3 px-3 py-2.5 text-start text-sm transition {{ $selectedRoleId === $role->id ? 'bg-secondary-soft font-semibold text-primary dark:bg-secondary-medium' : 'text-secondary-dark hover:bg-secondary-soft dark:text-white dark:hover:bg-secondary-medium' }}"
                    >
                        <span class="min-w-0 truncate">{{ $role->name }}</span>
                        <span class="inline-flex min-w-6 shrink-0 items-center justify-center rounded-md border border-secondary-light px-1.5 py-0.5 text-xs font-semibold text-secondary-medium dark:border-secondary-medium dark:text-secondary-soft">
                            {{ $role->users_count }}
                        </span>
                    </button>
                @endforeach
            </div>
        </aside>

        <section class="min-w-0 rounded-lg border border-secondary-light bg-white dark:border-secondary-medium dark:bg-secondary-dark">
            @if ($this->selectedRole)
                <div class="flex flex-wrap items-start justify-between gap-4 border-b border-secondary-soft px-5 py-4 dark:border-secondary-medium">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:heading size="lg">{{ $this->selectedRole->name }}</flux:heading>
                            @if ($this->selectedRoleIsProtected)
                                <span class="rounded-md border border-primary px-2 py-1 text-xs font-bold text-primary">
                                    {{ __('roles.protected_role') }}
                                </span>
                            @endif
                        </div>
                        @if ($this->selectedRoleIsProtected)
                            <p class="mt-1.5 text-xs font-semibold text-primary">{{ __('roles.protected_role_help') }}</p>
                        @endif
                    </div>

                    @unless ($this->selectedRoleIsProtected)
                        <form wire:submit="renameRole" class="grid w-full items-end gap-2 sm:w-auto sm:grid-cols-[minmax(12rem,1fr)_auto_auto]">
                            <flux:input wire:model="selectedRoleName" :label="__('roles.rename_role')" />
                            <flux:button type="submit" size="sm">{{ __('roles.rename_role') }}</flux:button>
                            <flux:button type="button" size="sm" variant="danger" wire:click="deleteRole" wire:confirm="{{ __('roles.delete_role') }}?">
                                {{ __('roles.delete_role') }}
                            </flux:button>
                        </form>
                    @endunless
                </div>

                <form wire:submit="saveRolePermissions">
                    <div class="flex flex-wrap items-center justify-between gap-3 px-5 pt-4">
                        <flux:heading size="base">{{ __('roles.permissions') }}</flux:heading>
                        @unless ($this->selectedRoleIsProtected)
                            <div class="flex gap-2">
                                <flux:button type="button" size="sm" variant="ghost" wire:click="selectAllRolePermissions">
                                    {{ __('roles.select_all') }}
                                </flux:button>
                                <flux:button type="button" size="sm" variant="ghost" wire:click="clearRolePermissions">
                                    {{ __('roles.clear_all') }}
                                </flux:button>
                            </div>
                        @endunless
                    </div>

                    <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,16rem),1fr))] gap-3 p-5">
                        @foreach ($this->permissionGroups as $group)
                            <fieldset class="min-w-0 rounded-lg border border-secondary-light p-3.5 dark:border-secondary-medium">
                                <legend class="px-1 text-xs font-bold uppercase tracking-wide text-secondary-medium dark:text-secondary-soft">
                                    {{ $group['label'] }}
                                </legend>
                                <div class="mt-1 grid grid-cols-[repeat(auto-fit,minmax(8rem,1fr))] gap-x-3 gap-y-2">
                                    @foreach ($group['permissions'] as $permission)
                                        <label class="flex min-h-8 items-center gap-2 text-sm text-secondary-dark dark:text-white">
                                            <input
                                                type="checkbox"
                                                wire:model="rolePermissionIds"
                                                value="{{ $permission->id }}"
                                                @disabled($this->selectedRoleIsProtected)
                                                class="rounded border-secondary-light text-primary focus:ring-primary"
                                            >
                                            <span>{{ $permission->name }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                        @endforeach
                    </div>

                    @unless ($this->selectedRoleIsProtected)
                        <div class="flex justify-end border-t border-secondary-soft px-5 py-3 dark:border-secondary-medium">
                            <flux:button type="submit" variant="primary">{{ __('roles.save_permissions') }}</flux:button>
                        </div>
                    @endunless
                </form>
            @else
                <div class="flex min-h-64 items-center justify-center p-6 text-center text-sm text-secondary-medium dark:text-secondary-soft">
                    {{ __('roles.select_a_role') }}
                </div>
            @endif
        </section>
    </div>
</section>
```

- [ ] **Step 6: Run it to verify it passes**

Run: `/c/php85/php artisan test tests/Feature/Admin/RolesAndPermissionsTest.php --compact`
Expected: PASS.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/pages/admin/⚡roles.blade.php lang/en/roles.php lang/ar/roles.php tests/Feature/Admin/RolesAndPermissionsTest.php
git commit -m "feat: add the Roles & Permissions admin page"
```

---

### Task 5: Expose `is_board_member` on `HrEmployee`

**Files:**
- Modify: `app/Models/HrEmployee.php`
- Test: `tests/Feature/Models/HrEmployeeTest.php` (create if it doesn't already exist — check first with `Glob` before writing; if it exists, add the test into it instead of creating a duplicate file)

**Interfaces:**
- Produces: `HrEmployee::$is_board_member` (bool, cast).

- [ ] **Step 1: Write the failing test**

If `tests/Feature/Models/HrEmployeeTest.php` does not exist, create it with
just this test; if it exists, append this test to it:

```php
test('is_board_member is cast to boolean', function () {
    $employeeId = insertHrEmployee(firstNameEn: 'Board', lastNameEn: 'Member');

    DB::connection('hr')->table('employees')->where('id', $employeeId)->update(['is_board_member' => 1]);

    expect(App\Models\HrEmployee::query()->find($employeeId)->is_board_member)->toBeTrue();
});
```

Check `tests/Pest.php`'s `insertHrEmployee()` / `createHrSchema()` helpers
first (search for `is_board_member` in `tests/Pest.php`) — if the in-memory
HR schema's `employees` table doesn't yet have an `is_board_member` column,
add it to `createHrSchema()`'s table definition as
`$table->boolean('is_board_member')->default(false);`, matching the real HR
database's column so the test fixture stays truthful.

- [ ] **Step 2: Run it to verify it fails**

Run: `/c/php85/php artisan test --filter="is_board_member" --compact`
Expected: FAIL — either the column is missing from the test schema, or the
attribute isn't cast (currently returns `"1"`/`0`/int, not `true`/`false`).

- [ ] **Step 3: Add the cast**

In `app/Models/HrEmployee.php`, add `'is_board_member' => 'boolean'` to
`casts()` (alongside the existing `'is_active' => 'boolean'`), and
`@property bool $is_board_member` to the class docblock.

- [ ] **Step 4: Run it to verify it passes**

Run: `/c/php85/php artisan test --filter="is_board_member" --compact`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/HrEmployee.php tests/Feature/Models/HrEmployeeTest.php tests/Pest.php
git commit -m "feat: expose is_board_member on HrEmployee"
```

---

### Task 6: Full suite verification

**Files:** None new — this task runs the whole test suite and fixes any
fallout Task 3's cutover missed (e.g. a Policy test elsewhere, a seeder that
assumed `Officer` existed, a Blade view still referencing `$user->role`).

- [ ] **Step 1: Search for any remaining reference**

```bash
grep -rn "UserRole\|->role->label\|UserRole::" app resources tests --include="*.php" --include="*.blade.php"
```

Expected: no results. If anything remains, fix it following the same
pattern used in Task 3 (dynamic role name string, `$user->roles->first()?->name`,
`$user->can('...')`).

- [ ] **Step 2: Run the full suite**

```bash
/c/php85/php artisan test --compact
```

Expected: PASS. Fix any failure by tracing it to the specific file/behavior
it covers and applying the same dynamic-role pattern used throughout this
plan — do not weaken an assertion to make it pass.

- [ ] **Step 3: Manual smoke check (dev server)**

Ask the user to run `npm run dev` or `composer run dev` if hc-tracking's
frontend build isn't already running, then verify in the browser:
1. `/admin/users` — role select shows the 8 seeded roles (plus any ad hoc
   ones created via the command), a user with no role shows "No role".
2. `/admin/roles` — the new sidebar link works, role list shows user counts,
   permission checkboxes toggle and save, Super Admin is protected
   (checkboxes disabled, no rename/delete controls).
3. Confirm the two known Super Admin accounts (`mai.youssef@hcsyria.org`,
   `youssef.kharbout@hcsyria.org`) can still reach `/admin/users` and
   `/admin/roles` after `php artisan migrate` runs the data migration in
   Task 3 against the real database — **do not run `php artisan migrate` or
   `php artisan db:seed` against the real (non-testing) database without the
   user's explicit go-ahead**, since Task 3's migrations are irreversible
   data changes (dropping a column, reassigning roles) on production data.

- [ ] **Step 4: Report to the user**

Summarize what changed, list the two commands the user needs to run
themselves against the real database when ready (`php artisan migrate`, then
optionally `php artisan db:seed --class=RolesAndPermissionsSeeder` if the
seeder wasn't already folded into `db:seed`), and remind them the 254
previously-Officer users now have no role until reassigned from
`/admin/users`.
