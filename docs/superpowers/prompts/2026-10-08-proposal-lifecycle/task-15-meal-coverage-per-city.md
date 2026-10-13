# Task 15 — MEAL responsible per governorate (city)

> **المهمة:** مسؤول MEAL لكل محافظة/مركز
> **المطوّر:** B1 (مراجعة Dev 1) · **الفريق:** B · **الأيام:** 2–3 (الثلاثاء 2026-10-13 ← الأربعاء 2026-10-14؛ اليوم 3 نصف يوم) · **الجهد:** 1.5 يوم · **يعتمد على:** Task 02، Task 30 (المدن والمراكز من HR)
> **متى تستخدمه:** في اليوم 2 بعد CP0 (نهاية اليوم 2: اسم الصلاحية `meal-coverage.manage` من Task 02 ومزامنتها). يتوازى مع Task 30 (Dev 2، اليومان 2–3): ابدأ بالمدن من HR مباشرة، واربط `responsibleFor()` بمدينة المركز (`projects.center_id`) بعد CP1 (نهاية اليوم 3). لا يحتاج انتظار مهام المسار الأخرى.
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are implementing Task 15 of the HC Tracking proposal-lifecycle correction plan in this Laravel 13 / Livewire 4 / Flux UI 2 repository. The outcome (as settled by Revision 9, D-12a): every governorate can have one MEAL officer, and any project can have its own officer who overrides the governorate's (possibly someone from another governorate); both are assigned by the holder of the **MEAL Manager** slot (Task 07) on one settings page. A single helper `App\Support\MealCoverage::responsibleFor(Project $project): ?int` answers "who validates this project's monthly reports" (project officer → governorate officer → MEAL Manager), and `projectsCoveredBy(int $employeeId)` answers "which projects are mine". In the corrected story, project managers send a monthly report per project (Task 17) and that project's MEAL officer validates it and confirms its report template (Task 33); the **Monthly Matrix** (Task 18) offers a "my projects" filter and Task 21's `OversightRecipients` adds the officer to every project event.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins) (Boost rules, `php artisan make:* --no-interaction`, Pest, Pint, no new dependencies or base folders).
2. The plan `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md`: section `### Task 15` in full. It is Arabic and authoritative; English table, column and permission names are exact. Also read section 5 rows "بيانات HR" and "بيانات HR (الكتابة والحساسية)", section 2 row "التقارير", section 1.0 (project = HR center), section 0 «قواعد العمل بين الفريقين», `### Task 30` (`EmployeeProfile`, `projects.center_id`) and `### Task 21` (`OversightRecipients` uses your helper).
3. The decision log `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` if it exists; a recorded decision overrides the plan's default for D-12 / D-12a (decided in Revision 9: governorate officer, optional per-project officer, MEAL Manager fallback).
4. Rule files: `.ai/rules/database-migrations.md`, `.ai/rules/migrations.md`, `.ai/rules/feature.md`, `.ai/rules/models.md`, `.ai/rules/concerns-factories.md`, `.ai/rules/views-pages.md`, `.ai/rules/factories.md`, `.ai/rules/seeders.md`, `.ai/rules/database-seeders.md`, `.ai/rules/providers.md`, `.ai/rules/recipients.md`, `.ai/rules/views-components-notifications.md`, `.ai/rules/pages.md`, `.ai/rules/ui.md`, `.ai/rules/views.md`, `.ai/rules/views-enums.md`, `.ai/rules/policies.md`, `.ai/rules/tests.md`. Then `grep -rin 'hr\b\|employee\|city' .ai/rules`.
5. Existing code to study first:
   - `resources/views/pages/admin/⚡cities.blade.php` (HR cities, `map`/`grid` views, `projectStatsByCity()`, in-memory sort), `resources/views/pages/admin/_cities-map.blade.php`.
   - `app/Models/HrCity.php`, `app/Models/HrEmployee.php` (`is_active`, `NameColumns`, `pickerOptions()` — cached list that includes inactive employees), `app/Models/HrModel.php` (read-only HR connection).
   - `resources/views/components/ui/form-select.blade.php` (`employees` mode, client-rendered cached list), `app/Http/Controllers/EmployeePickerOptionsController.php`.
   - `app/Models/Program.php` (`TracksActivity`, `activityEmployeeFields()`), `app/Concerns/RecordsActivity.php`, `app/Notifications/Recipients/ManagedRecipients.php` (direct employee + `changedFrom`), `config/notifications.php` (`modules`, `module_icons`), `app/Providers/AppServiceProvider.php` `configureMorphMap()`, `app/Notifications/SubjectLinks.php`.
   - `database/factories/ProgramFactory.php` and `database/factories/Concerns/CreatesHrRecords.php` (`createHrCity()`, `createHrEmployee()`).
   - Tests: `tests/Feature/Admin/CityDirectoryTest.php`, `tests/Feature/Models/MorphMapTest.php`, `tests/Feature/Notifications/DesktopAppTest.php`, `tests/Feature/DatabaseSeederTest.php`, `tests/Pest.php` (`insertHrCity()`, `insertHrEmployee()`, `userWithRole()`).
6. Skills to activate: `laravel-best-practices`, `database-migrations`, `livewire-development`, `fluxui-development`, `laravel-security`, `testing-best-practices`.
7. Use Boost `search-docs` for migrations, `Rule` closures, `UniqueConstraintViolationException`, and Livewire forms; use `database-schema` before writing the migration (confirm `users` and that no `meal_coverages` exists).

## Preconditions to verify
- Permission `meal-coverage.manage` exists in `config/access-control.php` for MEAL (Task 02; General Manager is excluded by T-01), and Task 07's slot resolver for the **MEAL Manager** slot exists (`holderEmployeeId()` / `isVacant()`). If the resolver is not merged yet (Task 07 lands on day 9), code against its agreed signature behind one method and list it as a follow-up. `cities.view` exists (it does today).
- HR `employees` and `cities` are readable in tests through `createHrSchema()` / `insertHr*()` helpers.
- No `meal_coverages` table or `MealCoverage` class exists yet.
- Optional at start (Task 30, CP1 end of day 3): `projects.center_id` and `App\Support\EmployeeProfile`. If not merged yet, use `$project->city_id` and leave a TODO for the center's city.
If any fails, stop and report.

## Revision 2 changes (2026-10-08)
- Owner is now **B1 (Team B)**, reviewed by Dev 1; days 2–3 (Tue 2026-10-13 → Wed 2026-10-14 ½). Dependencies now include **Task 30** (HR cities, centers and employees, read-only).
- Project = HR center (plan section 1.0, D-30): a project's city is its center's city. `responsibleFor()` resolves the city from the project's HR center (`projects.center_id` → `hchr_centers` city, through `EmployeeProfile` / the HR models) and falls back to `projects.city_id` when `center_id` is null. Employee lookups (active flag, name) go through Task 30's `EmployeeProfile` when available; HR is read-only and never copied.
- Task 21's `OversightRecipients` adds "the MEAL officer of the project" to every catalog event, so `responsibleFor()` and `projectsCoveredBy()` (Revision 9; it replaces the earlier `citiesCoveredBy()` idea) are consumed by Task 21 too; keep the signatures stable from CP1.
- Work under the inter-team rules (plan section 0 «قواعد العمل بين الفريقين»): do not change Phase 1 tables (`proposals`, `projects`, `donor_proposal`, `proposal_status_notes`) or the proposal state machine — request any such change from Dev 1 so it is recorded in the plan; integrate with Phase 1 by listening to its events (`ProposalStatusChanged`, `ProjectActivated`, `ProjectPeriodChanged`, `FundingAgreementRecorded`, dispatched after commit) instead of calling Phase 1 Actions directly; every PR is reviewed by Dev 1 (backend) or Dev 2 (UI) within one working day and merges only with this task's suite green; no production access — seed or masked data only.
- Register this task's events (`meal_coverage.assigned` / `meal_coverage.responsible_changed`, digest importance) in the Task 21 event catalog `App\Notifications\EventCatalog` so MEAL and General Management are notified (plan section 1.0, fact 8; `EventCatalogCoverageTest` must stay green). If the catalog is not merged yet, add the keys in your PR and coordinate with Dev 3.

## Revisions 6–8 changes (2026-10-12)
Source: decision-workshop answers (R6 file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.docx`) and two real M&E workbooks (`Syria Cross e.V._ME_Tool_IPTWP_V1_2026_Aug.xlsx`, `Children University - IPTT - 2026.xlsx`). Read the plan header paragraphs «المراجعة 6/7/8», decision rows D-09, D-12, and the «تعديلات المراجعة 6, 7 and 8» subsections of this task in the plan. Open questions still running on their documented default are listed in `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r7.ar.docx`. Where this block conflicts with text further down this prompt, this block wins.
- `meal_coverages` (`scope_type` city|center, `scope_id`, `employee_id`, `assigned_by`), unique on (`scope_type`, `scope_id`) — one officer per scope (D-12a default applied). Managed only by the **MEAL Manager** slot holder.
- *(The `center` scope and `excludeFallback` of Revisions 6 and 8 are replaced by Revision 9 below; build only the Revision 9 shape.)*

## Revision 9 changes (2026-10-12)
Source: the corrected R6 answers file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.fixed.docx`, which is newer than the R7 questions file and replaces the defaults Revision 8 applied. Read the plan header paragraph «المراجعة 9», the decision rows in section 9 (and «الأسئلة المتبقية بعد المراجعة 9»), and the «تعديلات المراجعة 9» subsection of this task. **Where this block conflicts with any text further down, including the Revisions 6–8 block, this block wins.**
This block **replaces** the `center` scope (Revision 6) and `excludeFallback` (Revision 8).
- **D-12a:** a proposal can span projects in several governorates, so coverage is per project inside its governorate: the governorate's MEAL officer by default, or an officer **assigned to the project itself**, who may belong to another governorate.
- `meal_coverages.scope_type` = `city` | `project`. City rows use `scope_id` (HR id, no FK); project rows use a separate `project_id` (local FK to `projects`, `cascadeOnDelete`). One officer per scope (unique per city, unique per project).
- `MealCoverage::responsibleFor(Project $project)`: project officer → governorate officer → MEAL Manager. Remove `excludeFallback` (reopen fallback is now PR Manager, Task 02).
- `MealCoverage::projectsCoveredBy(int $employeeId): list<int>` replaces `citiesCoveredBy`: directly assigned projects plus projects in the employee's governorates, **excluding** projects assigned to someone else. Used by Tasks 17, 18 and 21.
- UI (MEAL Manager slot only): governorates and their officers, plus a «مسؤول خاص بمشروع» section (search project → pick any MEAL employee); each project shows «موروث من المحافظة» or the specific officer. Changes notify new, previous and governorate officers (`meal_coverage.project_assigned`).
- Tests: project officer beats governorate even from another governorate; unassigned project inherits; nothing → MEAL Manager; `projectsCoveredBy` excludes projects assigned to others; non-manager 403. Effort +0.5 day.

## Scope
### In scope
- Create migration `create_meal_coverages_table` (Revision 9 shape): `id`, `scope_type` (VARCHAR(20): `city` | `project`), `scope_id` (unsigned bigint, nullable: HR city id for `city` rows, no FK), `project_id` (nullable FK `projects`, `cascadeOnDelete`, for `project` rows), `employee_id` (unsigned bigint, HR id, no FK), `assigned_by` (FK `users`; recommended nullable + `nullOnDelete` like `report_files.uploaded_by`; confirm), timestamps; unique (`scope_type`, `scope_id`) and unique `project_id`.
- Create model `app/Models/MealCoverage.php` (`#[Fillable(['scope_type', 'scope_id', 'project_id', 'employee_id', 'assigned_by'])]`, `casts()` with a small `MealCoverageScope` enum, `project()` relation, `city()`/`employee()` lookups to HR without cross-connection joins, `TracksActivity` + `RecordsActivity`, `activityEmployeeFields()` = `['employee_id']`, events `responsible_changed` and `project_assigned`).
- Create factory `database/factories/MealCoverageFactory.php` using `CreatesHrRecords`.
- Create Actions `app/Actions/MealCoverage/AssignGovernorateOfficer.php`, `AssignProjectOfficer.php` and `ClearProjectOfficer.php` (back to «inherited from the governorate»).
- Create `app/Support/MealCoverage.php` with `responsibleFor(Project $project): ?int` (project officer → governorate officer → MEAL Manager slot holder; inactive officers are skipped) and `projectsCoveredBy(int $employeeId): list<int>` (directly assigned projects + projects in the employee's governorates, excluding projects assigned to someone else). Its short name clashes with the model; alias the model import inside it (or ask the developer to rename one) — raise in plan mode.
- Morph key `meal_coverage` in `AppServiceProvider::configureMorphMap()`, `MorphMapTest` updated, `SubjectLinks::routeFor('meal_coverage')` → `admin.cities.index`.
- Recipients: a `ModuleRecipients` subclass for the module you choose (recommended `cities`, gated by `cities.view`) naming the new and previous responsible as direct employees; register it in `config/notifications.php`. A new module key also needs `module_icons` and rendered banners (`php artisan notifications:banners`) because `DesktopAppTest` checks them — ask the developer before rendering.
- One settings page for the MEAL Manager slot holder (recommended `/admin/meal-coverage`, `pages::admin.meal-coverage`, sidebar entry under administration; or a section on **Cities** — choose in plan mode) with two sections: **Governorates** (each governorate, its officer, assign/change) and **Project officers** (search a project, pick any active MEAL employee, or clear back to «موروث من المحافظة» / "Inherited from the governorate"). Each project row shows its effective officer and where it comes from. Warnings "Officer inactive" and "City inactive in HR".
- Lang keys in `lang/en/cities.php` + `lang/ar/cities.php` and event sentences under `notifications.events.meal_coverage.*` (with `_you` variants) in `lang/{en,ar}/notifications.php`.
- `projectsCoveredBy()` is required (Revision 9), not optional: Task 17's MEAL queue, Task 18's "my projects" filter and Task 21's `OversightRecipients` use it.
- Register `meal_coverage.*` in the Task 21 `App\Notifications\EventCatalog` (MEAL + General Management, digest).
### Out of scope
Monthly report routing and validation (Task 17); **Monthly Matrix** filter (Task 18); supervision map (Task 23); new permissions (Task 02); writing anything to HR.

## Business rules
- One officer per governorate and at most one per project (D-12, D-12a). Assigning a different employee replaces the previous one.
- Every change is recorded in the **Activity Log** (`activities`) and notifies the new and the previous officer; a project-officer change also notifies the governorate officer (`meal_coverage.project_assigned`).
- `responsibleFor($project)`: the active project officer; else the active officer of the governorate of the project's HR center (fallback `$project->city_id` when `center_id` is null); else the MEAL Manager slot holder; `null` only when all three are missing (callers then alert General Management, never "every MEAL user").
- Only the **MEAL Manager** slot holder manages coverage (the policy narrows `meal-coverage.manage`, D-12); another MEAL user gets 403.
- Re-assigning the same employee is a no-op: no write, no activity, no notification.
- A city inactive in HR can still be assigned, with a warning.

## Security and authorization
- Assign/change: `meal-coverage.manage` **and** being the MEAL Manager slot holder (`MealCoveragePolicy`), checked with `Gate::authorize` inside each Action. View: `cities.view` for the governorate list; the project section loads only projects the user may view.
- `projectId` is loaded server-side and must exist; never trust a project or city id held in Livewire state (BOLA).
- `employeeId` is verified against HR on the server and must be active; the client picker list (which includes inactive people) is never trusted.
- `cityId` must exist in HR cities; never write to the `hr` connection (`HrModel::save()` throws).
- `assigned_by` comes from `auth()->id()`, never from the request; `#[Fillable]` keeps mass assignment narrow.
- Notifications carry names only, no other HR personal data (`HrEmployee` hides personal fields).

## Validation
Messages in `lang/en/cities.php` and `lang/ar/cities.php`.
- `employeeId`: required, integer, an active HR employee — "اختر موظفًا فعّالًا" / "Choose an active employee". Implement as a closure rule querying `HrEmployee::query()->whereKey($id)->where('is_active', true)->exists()` (no cross-connection `exists:` rule).
- `cityId`: required, integer, an existing HR city — "المدينة غير موجودة" / "City not found".

## Edge cases and concurrency
- Employee left HR (inactive): the row stays; the page shows "Responsible inactive"; `responsibleFor()` returns `null` so the fallback applies.
- Two managers assigning the same city at once: the transaction locks the existing row (`lockForUpdate`); a create race hits the unique index — catch `UniqueConstraintViolationException`, re-read and update, or return a friendly "changed by someone else, reload".
- Permission revoked mid-session: the Action returns 403.
- City deleted from HR: the row stays harmless; the page lists HR cities only.

## Data integrity
- Unique (`scope_type`, `scope_id`) and unique `project_id`; assignment in one `DB::transaction` (lock, then create or update through Eloquent so `RecordsActivity` fires — never `DB::table()->upsert()`).
- No FK to HR tables (`.ai/rules/models.md`); FKs only on `assigned_by` and `project_id`.
- Migration: `utf8mb4_unicode_ci` / InnoDB DYNAMIC (`DatabaseEncodingTest`), working `down()`.
- Seeding (optional, confirm): one coverage in `database/seeders/MealSeeder.php` using active HR employees only, under `Activity::withoutRecording`; add the model to the list in `tests/Feature/DatabaseSeederTest.php` if seeded.

## Implementation guidance
- `php artisan make:migration create_meal_coverages_table --no-interaction`, `php artisan make:model MealCoverage --factory --no-interaction`, `php artisan make:class Support/MealCoverage --no-interaction` (then adjust namespace/strict types).
- `declare(strict_types=1);` everywhere; `CarbonImmutable` docblocks; Actions are plain classes such as `AssignGovernorateOfficer::handle(int $cityId, int $employeeId): MealCoverage` and `AssignProjectOfficer::handle(Project $project, int $employeeId): MealCoverage`, input validated by the component.
- Settings page: load all coverages in one query (keyed by scope) and their employees in one HR query (`select([...HrEmployee::NameColumns, 'is_active'])->whereKey($ids)`); no query per row. Cache the resolved map per request so `responsibleFor()` in loops (Tasks 17, 18, 21) stays at a fixed query count.
- Picker: reuse `x-ui.form-select` in `employees` mode (cached list). Modal: Flux bound with `wire:model` to a boolean. Warnings use `text-status-*` tokens plus an icon, not colour alone.
- Skeleton:
```php
final class MealCoverage
{
    /** Project officer → governorate officer → MEAL Manager slot holder; null only when none is active. */
    public static function responsibleFor(Project $project): ?int {}

    /** @return list<int> project ids the employee covers (direct + governorate, minus projects assigned to others) */
    public static function projectsCoveredBy(int $employeeId): array {}
}
```

## Tests (Pest)
- `tests/Feature/MealCoverageTest.php`: the MEAL Manager slot holder assigns a governorate officer (row, `assigned_by`, activity); changing it replaces the row and notifies new and previous; re-assigning the same employee writes nothing; inactive employee rejected with the message; unknown city rejected; another MEAL user and a user without `meal-coverage.manage` get 403 (fresh Livewire instance per forbidden call); a project officer from another governorate beats the governorate officer; an unassigned project inherits its governorate; nothing assigned → MEAL Manager slot holder; inactive officers are skipped; a project linked to an HR center resolves the center's city (Revision 2); `projectsCoveredBy()` excludes projects assigned to someone else; concurrent create does not produce two rows.
- Settings page test: both sections render with effective officers and their source; query count does not grow with the number of cities or projects.
- `tests/Feature/Models/MorphMapTest.php` updated; `tests/Feature/Notifications/DesktopAppTest.php` green if a module was added; `tests/Feature/DatabaseEncodingTest.php`, `tests/Feature/LocalizationTest.php` green.
Commands: `php artisan test --compact tests/Feature/MealCoverageTest.php`, `php artisan test --compact tests/Feature/Admin/CityDirectoryTest.php`.

## Acceptance criteria
- [ ] Every active governorate has an officer slot, and any project can get its own officer; only the MEAL Manager slot holder can assign.
- [ ] `MealCoverage::responsibleFor()` follows project → governorate → MEAL Manager, and `projectsCoveredBy()` matches it.
- [ ] Changes appear in the **Activity Log** and notify the people concerned.
- [ ] `responsibleFor()` uses the project's HR center city (Task 30) with the `city_id` fallback.
- [ ] No foreign key or write to the HR database.
- [ ] All strings exist in English and Arabic; page works in RTL.

## Do not
- Add a foreign key to an HR table (link by id only).
- Trust the client employee list or accept an inactive employee.
- Use a query-builder upsert that bypasses activity recording.
- Add a module key without its icon/banners, or put module knowledge in `RecipientResolver`.
- Bind a Flux modal with `:open`, or use `@php(...)` shorthand.

## Quality bar (this task)
- Coverage settings: two clear sections; each governorate row shows the officer chip or "not assigned"; the project section has search, the effective officer and its source ("inherited from the governorate" or specific), and inactive-officer warnings.
- Assignment modal uses the cached employee picker filtered to active MEAL staff; changes confirm with a toast naming who was notified.
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
Your final message must contain: a summary; files changed; migrations added; tests run with pass/fail output; decisions assumed (D-12, `assigned_by` on-delete, module key, class naming); follow-ups. Handoff facts for Task 17 (routing, validation and the MEAL queue), Task 18 ("my projects" filter), Task 21 (`OversightRecipients`) and Task 33 (`report-templates.confirm` for the project's officer): the exact signatures of `MealCoverage::responsibleFor()` and `projectsCoveredBy()`, that the return values are HR employee ids (map to users with `EmployeeRecipients::usersFor()`), and the fallback rule.
````
