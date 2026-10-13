# Task 02 — Permission catalog for the new modules

> **المهمة:** كتالوج الصلاحيات والوحدات الجديدة
> **المطوّر:** Dev 3 (مراجعة Dev 1) · **الفريق:** A · **الأيام:** 2–4 (الثلاثاء 2026-10-13 – الخميس 2026-10-15؛ الكتالوج في اليوم 2، والاختبارات والمزامنة في اليوم 4) · **الجهد:** 1.5 يوم · **يعتمد على:** Task 01
> **متى تستخدمه:** في اليوم 2 بعد ورشة Task 01، على أن تُثبَّت أسماء الصلاحيات في CP0 (نهاية اليوم 2، الثلاثاء 2026-10-13؛ سجل القرارات موجود، وD-09 وD-14 وD-20 وD-32 وD-33 محسومة أو مطبّق افتراضيها).
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are implementing Task 02 of the HC Tracking proposal-lifecycle correction plan. The outcome: the permissions the new features need exist in `config/access-control.php`, are granted to the right roles by default, show translated names (Arabic and English) on the Admin > Users roles tab, and reach a live database additively through `php artisan permissions:sync` without undoing admin edits. Later tasks put these names behind policies and actions (reviewers loop, status correction, Indicator Library, MEAL coverage, monthly report reopen, aggregated donor reports, supervisions, monthly report templates, evidence types, narrative questions, sectors), so the names you add are frozen at CP0 and must never change afterwards. Existing grants change too: Public Relations Manager gains the agreement permissions (D-31b) and `reports.reopen` (D-09a), and `targets.create/update` become MEAL-only (D-23).

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins).
2. `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md`, section `### Task 02` — Arabic and authoritative; the permission and role names in it are exact. In revision 2 the `media-deliverables.*` row was removed and `reports.aggregate` added. Also read section 9 rows D-09, D-12, D-14, D-16, D-20, D-23, D-31, D-32, D-33, D-47, the «Security and permissions» parts of `### Task 33` and `### Task 34`, and the decision log's section 5 (permission matrix) and section 10 (conflicts T-01…T-08).
3. `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` (if it exists; a recorded decision overrides the plan's default for D-xx, and its signed permission matrix overrides the default table below).
4. Rules: `.ai/rules/policies.md` (nine built-in role names), `.ai/rules/console-commands.md` (never run `RolesAndPermissionsSeeder` on a live database; `optimize:clear` before `permissions:sync`), `.ai/rules/views-enums.md` (every visible string in grouped `lang/{en,ar}` files; `LocalizationTest`), `.ai/rules/tests.md` (one forbidden call per Livewire instance; roleless user for "no access"), `.ai/rules/pages.md`, `.ai/rules/ui.md`, `.ai/rules/views.md` (no `@php(...)` shorthand) for the roles tab page.
5. Source: `config/access-control.php`, `app/Console/Commands/SyncPermissions.php`, `app/Models/Role.php` (`booted()` grants baselines and gives Super Admin everything), `database/seeders/RolesAndPermissionsSeeder.php`, `resources/views/pages/admin/⚡users.blade.php` (`permissionGroups()` around line 147 builds labels with `str($module)->headline()`; checkbox labels at lines ~817 and ~931 use `str($permission->name)->afterLast('.')`), `lang/en/access.php`, `lang/ar/access.php`, and the precedent data migrations `database/migrations/2026_09_28_162144_add_activity_log_view_permission.php` and `2026_10_06_100000_restrict_project_creation_to_pr_manager_and_general_manager.php`.
6. Tests to study: `tests/Feature/AccessControlCatalogTest.php`, `tests/Feature/AccessControl/RoleMatrixTest.php`, `tests/Feature/SyncPermissionsCommandTest.php`, `tests/Feature/RolesAndPermissionsSeederTest.php`, `tests/Feature/AccessControl/AuthorizationCoverageTest.php` (file-scanning style to copy), `tests/Feature/LocalizationTest.php`, `tests/Feature/Admin/UserDirectoryTest.php`.
7. Skills: `laravel-best-practices`, `laravel-security`, `testing-best-practices`, `livewire-development` (roles tab only). Use Boost `search-docs` for spatie/laravel-permission APIs before relying on them.

## Preconditions to verify
- The decision log exists and fixes the new permission names (CP0). If it does not, stop and ask the developer whether to proceed with the plan's default table; do not invent names.
- `php artisan permissions:sync --help` shows `--dry-run` (it does in `SyncPermissions`).
- Run the current tests listed above first and record the baseline. Note: `AccessControlCatalogTest` asserts `programs` = `['view','create','update','delete']` while the config holds `['view','update']`; if it already fails, report it and fix the stale assertion in this task (it is in your file), not the config.

## Revision 2 changes (2026-10-08)
- Timeline: Team A, days 2–4 (2026-10-13 → 2026-10-15). Names are frozen at CP0 (end of day 2); the tests and the local `permissions:sync` run land on day 4.
- `media-deliverables.*` is REMOVED: Task 20 (media deliverables) is merged into Task 31 (Team B, B3). Media and Finance department tasks use the existing `tasks.*` and `milestones.*` permissions plus a server-side department condition (D-33: the department is mandatory, the person optional; every employee whose current HR position is in the department sees its tasks). No new module, no new `$restrictedViews` entry.
- `reports.aggregate` is ADDED: build and save aggregated (quarterly / semi-annual / annual / custom) donor reports from validated monthly reports (Task 19, D-32). Holders: Public Relations Manager, General Manager, MEAL. It does not end in `.view`, so it never falls into `$viewEverything`.
- Team B consumes these names in its policies: Task 11 (B2, `indicator-library.*`), Task 15 (B1, `meal-coverage.manage`), Task 17 (B1, `reports.reopen`), Task 23 (B3, `supervisions.manage`), Task 31 (B3, existing `tasks.*`/`milestones.*`). Any rename after CP0 needs both teams and a plan update.

## Revisions 6–8 changes (2026-10-12)
Source: decision-workshop answers (R6 file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.docx`) and two real M&E workbooks (`Syria Cross e.V._ME_Tool_IPTWP_V1_2026_Aug.xlsx`, `Children University - IPTT - 2026.xlsx`). Read the plan header paragraphs «المراجعة 6/7/8», decision rows D-09, D-12, D-20, D-23, D-31, D-47, and the «تعديلات المراجعة 6, 7 and 8» subsections of this task in the plan. Open questions still running on their documented default are listed in `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r7.ar.docx`. Where this block conflicts with text further down this prompt, this block wins.
- `proposals.correct-status`: General Manager (= CEO) and Public Relations Manager (= Projects & Fundraising Manager) (D-20, D-20a confirmed).
- **Super Admin is for developers only (D-20a).** It keeps every permission technically (`Role::booted()`), but never appears in a business default list, is never a fallback decision-maker, and is never the only account a test uses.
- Remove Super Admin from the business lists for `sectors.manage`, `report-templates.manage`, `evidence-types.manage`, `narrative-questions.manage`, `reports.aggregate`, `proposals.approve`, `funding-agreements.*`.
- `reports.reopen`: the policy limits the decision to the **MEAL Manager** slot holder (D-09). *(The Revision 8 fallback to the project's MEAL officer is superseded by Revision 9 below: Public Relations Manager decides when the slot is empty.)*
- `targets.create/update`: MEAL only (D-23); a one-off revoke from General Manager and Managers (`--dry-run` first, Task 27).
- D-31b: Public Relations Manager gains `funding-agreements.view`, `funding-agreements.create`, `funding-agreements.update` (not delete) so PR and Finance enter the tranche schedule together. Update `config/access-control.php` and `AccessControlCatalogTest`.
- Task 34: `sectors.view` (in `$viewEverything`), `sectors.manage` (MEAL, General Manager).

## Revision 9 changes (2026-10-12)
Source: the corrected R6 answers file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.fixed.docx`, which is newer than the R7 questions file and replaces the defaults Revision 8 applied. Read the plan header paragraph «المراجعة 9», the decision rows in section 9 (and «الأسئلة المتبقية بعد المراجعة 9»), and the «تعديلات المراجعة 9» subsection of this task. **Where this block conflicts with any text further down, including the Revisions 6–8 block, this block wins.**
- **D-09a (replaces Revision 8):** when the **MEAL Manager** slot is empty or its holder is inactive in HR, holders of **Public Relations Manager** decide report reopen requests. Add `reports.reopen` to Public Relations Manager in `role_baselines`. `MonthlyReportPolicy::decideReopen`: slot filled → only its holder (PR gets 403); slot empty → active PR Manager holders; the report's preparer never decides their own request. Drop the "project MEAL officer" fallback and the `excludeFallback` use. Slot empty and no active PR Manager → request stays pending and General Manager is notified (closes D-09b).
- Tests: slot filled → PR 403; slot empty → PR decides and the project MEAL officer gets 403; preparer always 403; `AccessControlCatalogTest` updated.
- **Catalog for Tasks 33 and 34 (names frozen at CP0):** `report-templates.view`, `report-templates.manage`, `report-templates.confirm`, `evidence-types.manage`, `narrative-questions.manage` (Task 33) and `sectors.view`, `sectors.manage` (Task 34). Holders are in the Business rules table below.
- **CP0 conflicts from the decision log (section 10) that this task settles in config:** T-01 (General Manager's "everything except" list must exclude `reports.reopen`, `meal-coverage.manage`, `evidence-types.manage`, `narrative-questions.manage`, `targets.create`, `targets.update`); T-02 (`report-templates.view` ends in `.view`, so decide between `$restrictedViews` with explicit grants, recommended, or visible to everyone); T-04 (`proposals.approve` is not in the catalog: keep `ProposalPolicy::approve` on `proposals.update` unless management decides otherwise at CP0, and do not invent the permission). Ask in plan mode for any of them not yet decided in the log.
- **D-23 revoke:** `permissions:sync` stays additive by default; add an explicit one-off option (e.g. `--revoke=targets.create,targets.update --from="General Manager,Managers"`, `--dry-run` first, recorded in **Activity Log**). It is run once in the Task 27 rehearsal, never by deploy automation.

## Scope
### In scope
- `config/access-control.php`: new `$groups` entries and actions; `role_baselines`; the General Manager exclusion list (T-01); `$restrictedViews` only if CP0 chooses it for `report-templates.view` (T-02).
  - `indicator-library` => `view`, `create`, `update`, `delete`
  - `proposals` gains `manage-reviewers`, `correct-status`
  - `reports` gains `reopen` and `aggregate`
  - no `media-deliverables` module (Task 31 uses `tasks.*` / `milestones.*`)
  - `meal-coverage` => `manage`
  - `supervisions` => `manage`
  - `report-templates` => `view`, `manage`, `confirm`; `evidence-types` => `manage`; `narrative-questions` => `manage` (Task 33)
  - `sectors` => `view`, `manage` (Task 34)
- Existing grants: `funding-agreements.view/create/update` added to Public Relations Manager (D-31b); `targets.create/update` removed from the General Manager and Managers baselines (D-23) plus the one-off `--revoke` option on `permissions:sync` (see Revisions 6–8).
- Translated labels for EVERY module and action in the catalog (not only the new ones, because the roles tab renders them together): add `modules` and `actions` arrays to `lang/en/access.php` and `lang/ar/access.php`, and switch the three label sites in `resources/views/pages/admin/⚡users.blade.php` to `__('access.modules.'.$module)` / `__('access.actions.'.$action)`.
- Tests (see Tests section).
### Out of scope
- Policies, Gates and actions that use these permissions: Task 04 (`correct-status`), Task 07 (`manage-reviewers`, the MEAL Manager slot), Task 11 (`indicator-library.*`), Task 15 (`meal-coverage.manage`), Task 17 (`reports.reopen` and `MonthlyReportPolicy::decideReopen`), Task 19 (`reports.aggregate`), Task 23 (`supervisions.manage`), Task 33 (`report-templates.*`, `evidence-types.manage`, `narrative-questions.manage`), Task 34 (`sectors.*`); Task 31's department condition on `tasks.*` / `milestones.*`. This task adds names and baselines only; slot and scope checks belong to those policies.
- New roles, renaming roles, sidebar links for modules that do not exist yet.
- Running `permissions:sync` anywhere except the local dev database (production rollout is Task 29).

## Business rules
Default grants until the signed matrix arrives. This table already includes Revisions 6–9 and decision-log section 5; a signed matrix overrides it. Super Admin holds everything technically through `Role::booted()` but is developers-only (D-20a), so it appears in no business row and no test treats it as the expected holder of a business decision.

| Permission | Roles in `role_baselines` | Narrowed by policy (later task) |
|---|---|---|
| `indicator-library.view` | every role (`$viewEverything`) | — |
| `indicator-library.create/update/delete` | MEAL, General Manager | — |
| `proposals.manage-reviewers` | Financial, Public Relations Manager, General Manager | — |
| `proposals.correct-status` | General Manager, Public Relations Manager (D-20) | — |
| `reports.reopen` | MEAL, Public Relations Manager (D-09a); **excluded from General Manager** (T-01) | MEAL Manager slot holder; Public Relations Manager only while the slot is empty (Task 17) |
| `reports.aggregate` | Public Relations Manager, General Manager, MEAL (not a view permission, never in `$viewEverything`) | — |
| `meal-coverage.manage` | MEAL; **excluded from General Manager** (T-01) | MEAL Manager slot holder (Task 15) |
| `supervisions.manage` | General Manager | — |
| `sectors.view` | every role (`$viewEverything`) | — |
| `sectors.manage` | MEAL, General Manager (S-4) | — |
| `report-templates.view` | MEAL, General Manager; others per T-02 | project owner and program manager read their own project's template (Task 33) |
| `report-templates.manage` | MEAL, General Manager | — |
| `report-templates.confirm` | MEAL, General Manager | MEAL Manager slot holder or the project's MEAL officer (D-12a, Task 33) |
| `evidence-types.manage` | MEAL; **excluded from General Manager** (T-01) | MEAL Manager slot holder (Task 33) |
| `narrative-questions.manage` | MEAL; **excluded from General Manager** (T-01) | MEAL Manager slot holder (Task 33) |
| `funding-agreements.view/create/update` (existing) | **add** Public Relations Manager (D-31b); `delete` stays as is | — |
| `targets.create/update` (existing) | **MEAL only**: remove from General Manager (exclusion list) and Managers (D-23) | — |

- The General Manager baseline is `array_diff($allPermissions, [...])`: every new permission reaches GM automatically unless excluded. Check each row above against that list, and add the T-01 exclusions.
- `permissions:sync` only adds: a permission removed by an admin on the roles tab stays removed; a permission added by hand stays.

## Security and authorization
- Grant `proposals.correct-status` to General Manager and Public Relations Manager only; a test must pin the exact holders. No business default names Super Admin (D-20a).
- The slot-narrowed permissions (`reports.reopen`, `meal-coverage.manage`, `report-templates.confirm`, `evidence-types.manage`, `narrative-questions.manage`) are deliberately wider in the catalog than in practice; the narrowing lives in the policies of Tasks 15, 17 and 33. Say so in a config comment so nobody "fixes" the baseline later.
- The nine role names are fixed (`.ai/rules/policies.md`); add no role without a decision.
- Never run `RolesAndPermissionsSeeder` against a live database; deploy with `php artisan optimize:clear` then `php artisan permissions:sync --dry-run`, then `permissions:sync`.
- Hiding a button is never authorization: this task only defines names, and later tasks must check them server-side. Add the catalog-coverage test below so a misspelt permission in a later `Gate`/`@can` fails CI.

## Validation
- No user input. Automated checks: every `module.action` string passed to `can(`, `@can(`, `@cannot(`, `->can(`, `->cannot(`, `Gate::allows|denies|check|authorize(` and route `->can(` under `app/`, `resources/views/` and `routes/` exists in the catalog. Ignore ability names without a dot (`'update'`, `'view'`), which are policy methods.
- Every module key and action key has an English and Arabic label (`LocalizationTest` fails on a missing key).

## Edge cases and concurrency
- A role edited by hand on the roles tab: sync adds the new permissions its baseline lists and never revokes; prove it with a test.
- The matrix arrives after development starts: only `role_baselines` changes, then sync again; no other code changes.
- Config cache: a stale cached config hides new groups from sync; the runbook step is `optimize:clear` first.
- Permission cache: `SyncPermissions` already calls `forgetCachedPermissions()`; do not add a second cache layer.
- Additions to existing roles (D-31b, D-09a) reach live databases through `permissions:sync`. The only removal (D-23, `targets.create/update` from General Manager and Managers) goes through the explicit `--revoke` option, `--dry-run` first; never make plain `permissions:sync` revoke. Use the precedent data migrations only if the signed matrix removes something else (ask first).

## Data integrity
- `permissions:sync` writes inside one transaction under `Activity::withoutRecording` (existing behaviour); keep it.
- Run `php artisan permissions:sync --dry-run` locally and paste its output in your report: it must list only the new permissions and the roles gaining them.

## Implementation guidance
- Follow the existing `$groups` shape literally; keep module order meaningful (put `indicator-library` next to `indicators`, `meal-coverage`, `supervisions` near related modules).
- `declare(strict_types=1);` stays at the top of the config. Keep comments in the config style (who decided, when).
- Label lookup in `⚡users.blade.php`: build `['label' => __('access.modules.'.$module)]` in `permissionGroups()` and use `__('access.actions.'.str($permission->name)->afterLast('.'))` for the checkbox label; do not keep the `headline()` fallback.
- Arabic labels: concise, matching existing Arabic UI terms in `lang/ar/*.php` (e.g. reuse the words used for "Indicators", "Reports").

## Tests (Pest)
- `tests/Feature/AccessControlCatalogTest.php` (update): the new groups have exactly the listed actions; a file-scanning test (copy the `AuthorizationCoverageTest` approach) asserting every dotted permission used in code exists in `config('access-control.groups')`.
- `tests/Feature/AccessControl/RoleMatrixTest.php` (update): extend each role's write list; add the new exclusions to the General Manager test; add a dataset "holders of each new permission are exactly …" for every row of the table (e.g. `proposals.correct-status` → General Manager, Public Relations Manager, plus Super Admin only because `Role::booted()` grants it everything); update "reports gain approve and mark-seen permissions" for `reopen` (MEAL, Public Relations Manager; not General Manager) and `aggregate` (Public Relations Manager, General Manager, MEAL; absent from every view-only role); assert `targets.create/update` are absent from General Manager and Managers baselines and `funding-agreements.view/create/update` are present (and `delete` absent) for Public Relations Manager.
- `tests/Feature/SyncPermissionsCommandTest.php` (update or extend): a permission granted by hand to a role survives sync, and a new catalog permission reaches exactly the baseline roles plus Super Admin; plain sync never revokes; `--revoke … --dry-run` writes nothing and prints what it would remove; `--revoke` removes only the named permissions from the named roles and records an activity entry.
- `tests/Feature/Admin/UserDirectoryTest.php` (extend): the roles tab shows the translated module and action labels in `ar` and in `en`.
- `tests/Feature/LocalizationTest.php` must pass unchanged.
- Run: `php artisan test --compact tests/Feature/AccessControlCatalogTest.php tests/Feature/AccessControl/RoleMatrixTest.php tests/Feature/SyncPermissionsCommandTest.php tests/Feature/RolesAndPermissionsSeederTest.php tests/Feature/LocalizationTest.php tests/Feature/Admin/UserDirectoryTest.php`.

## Acceptance criteria
- [ ] `php artisan permissions:sync --dry-run` (local) lists only the new permissions and their roles.
- [ ] Each role holds exactly the default table above (tests prove it); `proposals.correct-status` is held by General Manager and Public Relations Manager; General Manager does not hold the T-01 exclusions.
- [ ] Every permission named by Tasks 33 and 34 exists, and the T-02 choice for `report-templates.view` is implemented as decided at CP0.
- [ ] Module and action names render in Arabic and English on the roles tab.
- [ ] `AccessControlCatalogTest`, `RoleMatrixTest`, `SyncPermissionsCommandTest`, `RolesAndPermissionsSeederTest`, `LocalizationTest` pass.
- [ ] The catalog-coverage test exists and passes.
- [ ] No `media-deliverables` module exists in the catalog; `reports.aggregate` exists and is not in `$viewEverything`.

## Do not
- Grant `proposals.correct-status` to anyone but General Manager and Public Relations Manager, or list Super Admin in any business default.
- Make plain `permissions:sync` revoke anything.
- Rely on hidden buttons instead of permissions.
- Run `RolesAndPermissionsSeeder` or `migrate:fresh` on a shared database.
- Rename an existing permission or module.
- Add a `media-deliverables` module or a Task 31-specific permission; department tasks use `tasks.*` / `milestones.*`.
- Add literal UI text; every label goes through `lang/{en,ar}/access.php`.

## Quality bar (this task)
- Roles tab: every module and action label is translated, grouped and ordered as in the catalog; long Arabic labels wrap without breaking the checkbox grid; the page still works at 375 px and in dark mode.
- Config comments say who decided each grant and why the slot-narrowed permissions are wider in the catalog than in practice, so nobody "fixes" them later.
- Tests pin the exact holders of each permission with a dataset (equality, not "contains").
- Everything in `quality-bar.md` applies. Before reporting, open every new or changed screen in the browser (Arabic RTL and English, light and dark, desktop and 375 px), run `vendor/bin/phpstan analyse` on the touched paths, and list both in your report.

## Working method
- Start in plan mode: restate the task in 5 bullets, list files you will touch, list open questions; wait for approval.
- Implement in small steps; after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory).
- Do not commit, push, or run destructive commands (`migrate:fresh`, seeders that reset roles, deleting storage) unless the developer asks.
- Never run anything against production.
- Stay inside this task's scope; note anything else as a follow-up.

## Report back
Your final message must contain: summary; files changed; migrations added (none expected); tests run with pass/fail output; the `permissions:sync --dry-run` output; decisions assumed (D-09/D-09a, D-14, D-20/D-20a, D-23, D-31b, D-32, D-33, and the T-01/T-02/T-04 choices); follow-ups; and the handoff facts: the final permission names and holders that Team A Tasks 04 (`proposals.correct-status`), 07 (`proposals.manage-reviewers`), 08 (approve = `proposals.update` unless CP0 decided otherwise), 09 (PRM agreement permissions), 19 (`reports.aggregate`) and Team B Tasks 11 (`indicator-library.*`, B2), 13 (`targets.*` MEAL-only), 15 (`meal-coverage.manage`, B1), 17 (`reports.reopen`, B1), 23 (`supervisions.manage`, B3), 33 (`report-templates.*`, `evidence-types.manage`, `narrative-questions.manage`, B4/B3), 34 (`sectors.*`, B3) will check in their policies, the exact `--revoke` command for the Task 27 runbook, plus the confirmation that Task 31 (B3) adds no permission and relies on `tasks.*` / `milestones.*` with a department condition.
````
