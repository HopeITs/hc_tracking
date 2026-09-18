# Dynamic roles & permissions (ported from hc-hr's access-management concept)

Date: 2026-09-18
Branch: youssef-interfaces-edits

## Problem

hc-tracking's authorization is a fixed 4-value enum (`App\Enums\UserRole`:
Officer/Manager/MEAL/Admin) hardcoded into two Policies (`UserPolicy`,
`StrategyPolicy`) and a badge component. There is no way to create a new role,
no per-role permission granularity, and no way to grant/revoke a single
capability without touching code.

hc-hr solves this with `spatie/laravel-permission`: dynamic `Role` and
`Permission` models, a two-tab "Employees / Roles" Livewire page (assign
roles+direct permissions to a person; create roles and toggle grouped
permissions), and a config-driven permission catalog
(`config/access-control.php`) seeded per role. The user wants that same
*mechanism* ported into hc-tracking, seeded with hc-tracking's own roles and
modules — not hc-hr's visual styling, and not hc-hr's domain (employees,
leaves, contracts).

## Scope

**In scope:**
- Add `spatie/laravel-permission`, `App\Models\Role` / `App\Models\Permission`
  extending Spatie's base classes, `HasRoles` on `User`.
- Drop `App\Enums\UserRole` and the `users.role` column entirely. Update the 4
  files that reference it (`UserPolicy`, `StrategyPolicy`,
  `CreateUserCommand`, `user-chip.blade.php`) to use dynamic roles/permissions.
- Seed exactly these 8 roles: Financial, Media Team, MEAL, Public Relations,
  Public Relations Manager, Managers, Super Admin, General Manager.
- A permission catalog covering every existing hc-tracking module (see
  Decisions table) with `view/create/update/delete` (and module-specific
  extras where an existing Policy already has one, e.g. `restore`).
- Migrate existing users: the 2 current `Admin` users become `Super Admin`;
  all other users (currently all `Officer`) get **no role** — zero
  permissions until manually assigned.
- Surface `is_board_member` (already a column on the HR `employees` table,
  read-only cross-DB) on `HrEmployee`, for later use in visibility grants —
  this spec only exposes the attribute, it does not wire it into any gate.
- Two new/changed admin pages, hc-tracking design system throughout (Flux UI,
  `x-ui.page-header`, `.admin-directory`/`.directory-filters` CSS hooks — no
  hc-hr hardcoded classes):
  - **Users** (`admin/⚡users.blade.php`, existing route/sidebar entry):
    directory + role assignment per user, replacing the enum select with a
    dynamic role select.
  - **Roles & Permissions** (new page, new sidebar link, new route
    `admin.roles.index`): role directory (create/rename/delete) + grouped
    permission checkboxes per role, matching hc-hr's fieldset-per-module
    layout.

**Out of scope (explicitly deferred):**
- Wiring `is_board_member` into any actual permission/gate — just expose the
  attribute.
- Per-user *direct* permission overrides beyond role assignment (hc-hr has
  this on its Employees tab). Not requested; roles-only assignment for now.
- Migrating every other Policy/Gate in the app that doesn't currently check
  `UserRole` — only the 2 that do, plus wiring the new permission catalog so
  future policies *can* use it.
- Multi-role-per-user UI nuance beyond what Spatie gives for free (a user can
  technically hold >1 role; the Users page shows/edits a single primary role
  select, matching current UX, not a multi-select).

## Decisions

| Decision | Choice | Why |
| --- | --- | --- |
| Package | `spatie/laravel-permission` | Same package hc-hr uses; proven pattern, no need to hand-roll. Requires composer approval — granted. |
| Enum fate | Deleted, not layered | User chose full replacement over running two authorization systems side by side. |
| Seeded roles | Financial, Media Team, MEAL, Public Relations, Public Relations Manager, Managers, Super Admin, General Manager | User's exact list. |
| Existing-user migration | Admin→Super Admin (2 users); everyone else→no role | Auto-mapping 254 Officers into 8 specific new roles is a per-person judgment call no migration can make safely; user confirmed this explicitly. |
| Board member | Read from HR `employees.is_board_member`, not a role | User clarified it's an HR-sourced attribute, not part of the role list. |
| Permission catalog scope | Full catalog across all existing modules now, not a minimal starter | User's choice — programs, projects, milestones, indicators, results, risks, targets, donors, proposals, funding-agreements (donor_proposal/transfers_tracking), strategies, annual-plans, reports, tasks, calendar, plus access-management.\* and admin.\* (accounts, cities). |
| Visual design | hc-tracking's existing design system | User's choice — keep hc-hr's UX *concept* (directory + detail panel, grouped permission fieldsets, role badges), not its hardcoded red-branded classes. |
| Page structure | Two separate pages/sidebar links, not tabs on one page | User explicitly asked for a separate "Roles and Permissions" sidebar link after seeing the tabbed proposal. |

## Architecture

### Data layer
- `composer require spatie/laravel-permission`, publish + run its migration.
- `App\Models\Role extends Spatie\Permission\Models\Role` — `booted()` hook
  seeds each newly-created role's baseline permissions from
  `config('access-control.role_baselines')`, mirroring hc-hr's `Role` model.
  Super Admin gets every permission unconditionally.
- `App\Models\Permission extends Spatie\Permission\Models\Permission`.
- `config/access-control.php`: `groups` (module => actions) driving the
  seeded Permission rows and the fieldset grouping in the UI;
  `role_baselines` (role => permission names) seeded onto each role at
  creation.
- Migration: drop `users.role` column (new migration, not editing the base
  one, per `.ai/rules/database-migrations.md`); remove `UserRole` cast from
  `User::casts()`.
- Data migration (in the same deploy, via a migration or a dedicated
  Artisan command run once): assign Super Admin to the 2 current Admin users
  by email; leave everyone else unassigned.
- `User` gets `use HasRoles;`. `App\Models\HrEmployee::casts()` gains
  `'is_board_member' => 'boolean'`.

### Authorization
- `UserPolicy` and `StrategyPolicy` switch from `$user->role === UserRole::X`
  to `$user->can('access-management.manage-users')` /
  `$user->hasAnyRole(['Managers', 'Super Admin'])`-style checks against the
  new permission catalog (exact mapping decided during implementation to
  preserve current behavior for the 2 Policies that exist today).
- `CreateUserCommand` and `user-chip.blade.php` updated to read/display the
  dynamic role instead of the enum.

### UI
- **Users page** (existing route/sidebar entry, existing file): keep its
  current directory/list + modal shape (per `.ai/rules/pages.md`'s modal
  binding rule — `wire:model` boolean, never `:open`), swap the role
  `flux:select` from `UserRole::cases()` to `Role::all()`.
- **Roles & Permissions page** (new route `admin.roles.index`, new sidebar
  link under the same admin nav group as Users/Accounts/Cities): left column
  role directory (name, user count, create/rename/delete for non-protected
  roles); right panel grouped permission checkboxes (`fieldset` per module
  from `config('access-control.groups')`), select-all/clear-all, Super Admin
  shown as a protected role (checkboxes disabled, all checked).
- Both pages authorize via `Gate::authorize` in `mount()`, matching the
  existing Users page's convention.

## Testing

- Feature tests for the Roles & Permissions page: create/rename/delete a
  role, toggle permissions, Super Admin protected-role guard.
- Feature tests for the Users page: role assignment via the new dynamic
  select.
- Unit/feature test for the data migration: the 2 known Admin users land on
  Super Admin, a sample of previously-Officer users end up roleless.
- Policy tests updated for `UserPolicy`/`StrategyPolicy` against the new
  permission-based checks.
