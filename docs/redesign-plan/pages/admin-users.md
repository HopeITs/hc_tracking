# P27 · Admin · Users

**Route:** `admin.users.index` (policy `viewAny` on `User`) · **Group:** K · Admin (same owner as P28–P29) · **Wave:** 1 · **Depends on:** 02 (`data-table`)

## Files you own

- `resources/views/pages/admin/⚡users.blade.php`
- `lang/*/users.php`
- `tests/Feature/Admin/UserDirectoryTest.php`

## Current state

Bespoke header (not `x-ui.page-header`) + bordered card; search (name/email) + role filter + "Add user"; duplicated markup: `flux:table` at `@4xl` (User avatar/name/email, Role badge, Access verified/pending, Joined, Actions) and card list below; per-file `@php` class maps (`$controlClasses`, `$roleBadgeClasses`); `.admin-directory` CSS overrides. Create/edit modal picks an HR account (name/email/password read-only from HR) and sets role only. Delete confirmation with FK-violation handling. Actions gated by policy.

`UserRole`: Officer, Manager, MEAL, Admin.

## Target layout

1. Replace bespoke header with `x-ui.page-header` (title, count, description, filters: search + role, actions: "Add user").
2. **Summary slot:** `x-ui.status-strip` by role (`wire-model` = existing role property) + "{n} pending verification" button (new `#[Url] access` filter: `pending` → `whereNull('email_verified_at')`).
3. **One** `x-ui.data-table` (no duplicated mobile markup): User (avatar + name + email) · Role (`x-ui.enum-badge` if `UserRole` implements `label()`; otherwise a neutral chip) · Access (Verified good chip / Pending warn chip) · Joined (date) · Actions (edit/delete icon buttons with `aria-label`, policy-gated exactly as today).
4. Remove the per-file class maps and the `admin-directory` wrapper class **for this page**; tell the foundation owner when all three admin pages are migrated so the CSS block can be deleted.
5. Modals: unchanged behaviour and validation; restyle with `x-ui.input` / `x-ui.select`.

## Tests (`Admin/UserDirectoryTest.php`)

- Existing tests green (policy, HR-sourced fields, FK delete error).
- Add: access filter; role strip counts; mobile stacked labels present (`data-label`).

## Acceptance

- [ ] No duplicated table/card markup remains.
- [ ] Conventions §10 checklist.
