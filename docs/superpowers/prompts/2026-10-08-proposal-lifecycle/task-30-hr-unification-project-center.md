# Task 30 — HR Read-Only Unification and Project-to-Center Link (project inside a center)

> **المهمة:** توحيد بيانات HR للقراءة فقط (الموظف، منصبه الحالي من العقد الفعّال، مستواه الوظيفي، قسمه، برنامجه، مركزه، مدينته) وربط كل مشروع بمركز HR وبرنامج (المركز مبنى قد يضم عدة مشاريع، مشروع مفتوح واحد لكل مركز وبرنامج، D-30)
> **المطوّر:** Dev 2 · **الفريق:** A · **الأيام:** 2–3 · **الجهد:** يومان · **يعتمد على:** Task 01 (القرار D-30)
> **متى تستخدمه:** مباشرة بعد نقطة التكامل CP0 (نهاية اليوم 2: سجل القرارات وفيه D-30 وأسماء الجداول والأعمدة). تقرير المطابقة يجب أن يكون جاهزًا نهاية اليوم 3، و`EmployeeProfile` و`projects.center_id` يُجمَّدان في CP1.
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are implementing Task 30 of the HC Tracking proposal-lifecycle correction plan. Two outcomes. (1) **One read-only source for people and structure:** everything about an employee — current position (from the active contract), job level, department, program, center and city — is read from the HR database (connection `hr`, table prefix `hchr_`) and never written or copied into tracking tables. (2) **Project inside a center (D-30, Revision 6):** every project is linked to exactly one HR center (`projects.center_id`) and one program, its city is always the center's city, and a center (a building) may host several projects, but at most one open project (`draft`/`approved`/`active`) per **center and program**; a new grant for an existing project is a new proposal on the same project, not a new project. Other tasks build on what you freeze at CP1: Task 05, 07, 10, 15, 17, 21, 23 and 31.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins) (Laravel 13, PHP 8.5, Livewire 4 + Flux UI 2, Pest, Pint, Boost tools, `php artisan make:* --no-interaction`, no new dependencies or base folders without approval).
2. `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md` (Arabic, authoritative; English table, column and UI names are exact): section 0 (assumptions 4, 7 and 9; "rules between teams"), section 1.0 (domain facts 1 and 9), `### Task 30` in full, section 5 (rows "HR data" and "HR data (writing and sensitivity)"), section 7 (CP0, CP1), and section 9 entries D-13 and D-30.
3. `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` if it exists — a recorded decision overrides the plan's default (here D-30: what to do with projects in cities that have no HR center).
4. Rules (read all; confirm against `.ai/rules/index.md` for every file you add): `concerns-factories.md` (HR models, `RemembersHrLookup`, "no copying HR data"), `models.md`, `views-pages.md`, `database-migrations.md`, `migrations.md`, `feature.md`, `pages-funding-agreements.md` (matches `database/migrations/**`), `commands-seeders.md`, `commands.md`, `console-commands.md`, `enums.md`, `middleware-actions-fortify.md` (all match `app/Console/Commands/**`), `components-ui.md`, `pages.md`, `ui.md`, `views.md`, `views-enums.md`, `tests.md`, `seeders.md` (matches `tests/Pest.php`). Then run `grep -rin 'hr\b\|hchr\|employee\|center\|contract\|position\|no fk' .ai/rules`.
5. Study first (verify each exists with Glob/Grep before relying on it): `app/Models/{HrModel,HrCity,HrContract,HrDepartment,HrEmployee,HrPosition,HrProgram,HrUser}.php` (note `HrModel::save()/delete()` refuse writes; `HrDepartment::employeeIdsInDepartment()` joins `contracts` → `positions` on `contracts.is_active`; `HrEmployee::idsMatchingName()`, `pickerOptions()`, `pickerLabels()`, `forgetPickerOptions()`, `currentDepartmentId()`; `HrPosition::programManagerEmployeeIds()`); `app/Models/Concerns/RemembersHrLookup.php`; `app/Models/User.php` (`departmentId()`, `departmentEmployeeIds()`); `app/Models/Project.php`; the project create/edit form in `resources/views/pages/projects/⚡index.blade.php` (writes `city_id` from `cityId` today) and `⚡show.blade.php`; `resources/views/components/ui/employee-chip.blade.php`; `tests/Feature/EmployeePickerOptionsTest.php`; `tests/Feature/Models/{HrModelTest,HrEmployeeTest}.php`; `tests/Pest.php` (`createHrSchema()`, `insertHrCity/Program/Department/Employee/Position/Contract()`); `app/Console/Commands/SyncHrUsers.php` (command conventions); `config/database.php` (`hr` connection).
6. Activate skills: `laravel-best-practices`, `livewire-development`, `fluxui-development`, `database-migrations`, `laravel-security`, `testing-best-practices`.
7. Use Boost `database-connections` and `database-schema` on the `hr` connection (`centers`, `job_levels`, `positions`, `contracts`) and on `projects`; Boost `database-query` (read-only) for the local mapping data; `search-docs` for cross-connection relations, `Schema::connection`, and command options.

## Preconditions to verify
- CP0 passed and D-30 is recorded (decided in Revisions 6 and 8: one open project per center **and program**, D-30a; HR adds the missing centers before 2026-10-27, D-30c; projects in cities still without a center keep `center_id = NULL` with an admin warning).
- HR tables as verified: `centers` (`id,name_en,name_ar,weekends,open_at,close_at,city_id,address_en,address_ar,is_active`), `job_levels` (`id,name_en,name_ar,description,grade,email`), `positions` (`program_id,center_id,city_id,job_level_id,department_id,direct_manager_id,tech_manager_id,title_en,title_ar,is_active`…), `contracts` (`employee_id,position_id,…,salary,currency,…,is_active,start_date,end_date`). No `HrCenter`/`HrJobLevel` model exists yet.
- `projects` has `city_id` and `program_id` and no `center_id`. Local data: 26 HR centers in 6 cities; projects in cities 7, 8, 12, 13, 14 and 17 have no HR center.
- The in-memory `hr` test schema in `tests/Pest.php` lacks `centers` and `job_levels` and the `positions.center_id/city_id/job_level_id/title_*` columns — extend it, never point tests at the real `hr_db`.
If a hard precondition fails, stop and report instead of improvising.

## Revisions 6–8 changes (2026-10-12)
Source: decision-workshop answers (R6 file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.docx`) and two real M&E workbooks (`Syria Cross e.V._ME_Tool_IPTWP_V1_2026_Aug.xlsx`, `Children University - IPTT - 2026.xlsx`). Read the plan header paragraphs «المراجعة 6/7/8», decision rows D-30, and the «تعديلات المراجعة 6, 7 and 8» subsections of this task in the plan. Open questions still running on their documented default are listed in `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r7.ar.docx`. Where this block conflicts with text further down this prompt, this block wins.
- A center is a building that may host several projects (Hope Center: Hope, F2F, Micro Projects, wells). Rule, confirmed (D-30a): one open project (`draft`/`approved`/`active`) per (`center_id`, `program_id`), checked server-side in a transaction with `lockForUpdate`.
- `HrCenter::projects()` returns all of the center's projects; filters and pages list projects, not one project.
- D-30c: HR adds the centers for cities 7, 8, 12, 13, 14, 17 before the rehearsal (2026-10-27); otherwise `center_id` stays empty with a warning.
- Tests: two open projects in one center with two programs → accepted; same program → rejected; two concurrent saves → one.

## Revision 9 changes (2026-10-12)
Source: the corrected R6 answers file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.fixed.docx`, which is newer than the R7 questions file and replaces the defaults Revision 8 applied. Read the plan header paragraph «المراجعة 9», the decision rows in section 9 (and «الأسئلة المتبقية بعد المراجعة 9»), and the «تعديلات المراجعة 9» subsection of this task. **Where this block conflicts with any text further down, including the Revisions 6–8 block, this block wins.**
- **D-10a:** the reconciliation report adds a uniqueness check on HR `programs.code`, because the program code now prefixes every indicator code. Local dev HR (2026-10-12): `003` Relief Programs (inactive) + Family to Family; `004` Earthquake Assistance + School to School; `009` Sha8er + Entrepreneurship project + CYG. Send the list to the HR team to fix before library seeding (Task 11, day 5).
- **D-35a:** the report states whether HR has added Wells, Alternative Energy, PSS & Case Management and Parenting programs, and their codes.

## Scope
### In scope
- `App\Models\HrCenter` (`centers`) and `App\Models\HrJobLevel` (`job_levels`), both extending `HrModel`, with `options()`/lookup helpers using `RemembersHrLookup`. Explicit columns only (no `job_levels.email` needed in pickers).
- `HrPosition` relations `center()`, `jobLevel()`, `city()`, `directManager()` (`direct_manager_id`).
- `App\Support\EmployeeProfile` (propose `make:class`): one cached read per running app giving, per active employee, `position_title_en/ar`, `job_level` + `grade`, `department_id`, `program_id`, `center_id`, `city_id`, `direct_manager_employee_id`. Helpers: `employeeIdsInDepartment(int)` (delegates to / extends `HrDepartment::employeeIdsInDepartment`), `employeeIdsAtCenter(int)`, `managerOf(int)`, `for(int): ?array` (or a small readonly DTO — propose the shape in plan mode; it is a CP1 contract).
- Migration: `projects.center_id` `unsignedBigInteger`, nullable, indexed, **no FK** (other connection).
- `Project`: `center_id` in `#[Fillable]`, a `center()` accessor that resolves through the lookup (no cross-connection `whereHas`), `isOpen()`-based "open project for center" query.
- Project form: **Center** picker (active centers, label in the current locale) replaces free city choice on create; `city_id` is written server-side from the center; default project name = center name (editable).
- Command `hr:map-project-centers` (`--dry-run` default, `--apply`, `--force`): proposes a center per project by matching (`city_id`, `program_id`) against `positions.center_id`; prints matched / ambiguous / no-center tables.
- `x-ui.employee-chip` and the employee picker show position, job level, department and center from `EmployeeProfile`.
- Lang keys in `lang/{en,ar}/projects.php` (and the command's output strings if user-facing).
### Out of scope
- Any write to the `hr` connection; managing centers, employees or contracts.
- Making `center_id` NOT NULL (later step after production mapping, Task 27).
- Project status v2 and draft-without-dates (Task 05); duplicate detection UI and renewal (Task 10); MEAL owner per city (Task 15); "who follows whom" (Task 23); department tasks (Task 31).

## Business rules
- Read only: no `save()`/`delete()` on any HR model, no HR names, titles or center names copied into tracking tables.
- Current position = the position of the active contract (`contracts.is_active = 1`); several active contracts → latest `start_date`, and log a warning. No active contract → "no current position", shown as inactive in new pickers.
- Project inside a center: every non-archived project has one center and one program; at most one open project (`draft`/`approved`/`active`, use `ProjectStatus::isOpen()` if it matches; `approved` is the Revision 4 "funded, awaiting template" status Task 05 brings back) per (`center_id`, `program_id`), checked server-side (no DB unique index — the condition depends on status). Closed/archived projects do not block, and a second open project in the same center with another program is allowed.
- Project city = center city, always. Changing the center changes the city.
- Default project name on create = center name in the current locale.

## Security and authorization
- No new permissions; HR data is shown only where the existing pages already show employees.
- Salary, currency, contract type and every other sensitive `contracts` column are **never selected**: every HR query lists its columns explicitly (`select([...])`, `pluck`), never `*`.
- Project create/edit keeps its existing policy (`ProjectPolicy`, `Gate::authorize` in the action/method); `center_id` and `city_id` are not trusted from the request beyond the validated center id.
- The command is console-only, `--dry-run` by default; `--apply` writes only `projects.center_id`/`city_id`.

## Validation
Messages in `lang/en/projects.php` and `lang/ar/projects.php`:
- `centerId`: required on create, integer, exists and active in `hr.centers` (`Rule::exists(HrCenter::class, 'id')->where('is_active', true)`) — «اختر المركز».
- Another open project for the same center and program — «لهذا المركز مشروع مفتوح لهذا البرنامج: {name}. أضِف مقترحًا جديدًا عليه بدل إنشاء مشروع» (link the message to that project when the user may view it).
- On edit, an inactive center already linked may stay (no forced change); choosing a different center must pass the same rules.

## Edge cases and concurrency
- Center deactivated in HR: linked projects keep it, show an "inactive center" badge, and it cannot be chosen for a new project.
- HR unavailable: pages show the id with «تعذّر تحميل بيانات HR» and do not break (existing picker behaviour).
- Projects in cities without HR centers: stay `center_id = NULL`; an admin-visible warning lists them; the command reports them under "no center".
- Two users creating an open project for the same center and program at once: run the "open project exists" check and the insert in one transaction with a lock (e.g. `lockForUpdate` on the open projects of that center, or a named lock keyed by center and program) so only one succeeds.
- Command re-run: idempotent; never overwrites an existing `center_id` without `--force`; ambiguous matches are reported, never guessed.

## Data integrity
- `center_id` has no FK; existence is checked in the server (existing "link by id without FK" rule). `down()` drops the index then the column.
- Table/column charset `utf8mb4_unicode_ci` on InnoDB DYNAMIC (`DatabaseEncodingTest`).
- No backfill inside the migration; mapping is the command's job so it can be reviewed with the report first.

## Implementation guidance
- `declare(strict_types=1);`, `#[Fillable([...])]`, `casts()`, CarbonImmutable, explicit return types, PHPDoc array shapes for the profile.
- "Resolve ids first" pattern from `HrEmployee`: query the `hr` connection for ids, then query tracking tables with `whereIn` — never `whereHas` across connections.
- Cache per running app instance, refreshed after 60 seconds or on an unknown id (`RemembersHrLookup`); keep the profile load to one or two queries (contracts ⋈ positions, plus job_levels/centers lookups).
- Skeleton:
```php
final class EmployeeProfile
{
    /** @return array{position_title_en: ?string, position_title_ar: ?string, job_level: ?string, grade: ?int, department_id: ?int, program_id: ?int, center_id: ?int, city_id: ?int, direct_manager_employee_id: ?int}|null */
    public static function for(int $employeeId): ?array {}

    /** @return list<int> */
    public static function employeeIdsAtCenter(int $centerId): array {}

    public static function managerOf(int $employeeId): ?int {}
}
```
- After merging, record the rule "current position = active contract (latest start_date); never select contract salary/currency" with Boost `record-rule` (glob `{app/Models/Hr*.php,app/Support/EmployeeProfile.php,app/Models/Concerns/RemembersHrLookup.php}`).

## Tests (Pest)
- `tests/Feature/Models/HrCenterTest.php`: `save()`/`delete()` on `HrCenter` and `HrJobLevel` throw; options list active centers only.
- `tests/Feature/EmployeeProfileTest.php`: position from the active contract, not the ended one; latest `start_date` wins with two active contracts; job level, grade, department, center, city and direct manager correct; no active contract → null; `employeeIdsAtCenter` and `managerOf`; query log (`DB::connection('hr')->enableQueryLog()`) contains no `salary`/`currency` and no `*` on `contracts`.
- `tests/Feature/ProjectCenterTest.php`: create with a center writes `center_id` and the center's `city_id` (a tampered `cityId` is ignored); default name = center name; second open project for the same center **and program** refused with the message; same center with another program accepted; an `approved` project blocks like `draft`/`active`; closed/archived project does not block; two concurrent saves for the same pair → one project; inactive center refused; 403 path unchanged for a user without project create permission.
- `tests/Feature/MapProjectCentersCommandTest.php`: `--dry-run` writes nothing and classifies matched / ambiguous / no-center; `--apply` fills only unmapped projects; `--force` overwrites; re-run is a no-op.
- Update `tests/Feature/EmployeePickerOptionsTest.php` (and the chip render) for position/department/center; extend `createHrSchema()` and add `insertHrCenter()`/`insertHrJobLevel()` helpers in `tests/Pest.php`.
- Run `DatabaseEncodingTest`, `LocalizationTest`, `ProjectTest`, `ProjectDirectoryTest`, `Models/HrModelTest`, `Models/HrEmployeeTest`.
- Run: `php artisan test --compact tests/Feature/EmployeeProfileTest.php`, then `--filter=...` per change.

## Acceptance criteria
- [ ] Every employee picker and chip shows current position, department and center from HR.
- [ ] A project is created from a center and program; its city follows the center; a second open project for the same center and program is refused, another program in the same center is accepted.
- [ ] The dry-run report also lists duplicated HR `programs.code` values (D-10a), whether the new programs exist in HR (D-35a), and centers that already hold more than one open project of the same program (to resolve before the rule goes live, Task 27).
- [ ] `hr:map-project-centers --dry-run` report is ready on day 3 for management and the HR team (paste it in your report).
- [ ] No write to the `hr` connection on any path (test); no salary/contract columns in any query (test).
- [ ] `EmployeeProfile` and `projects.center_id` are documented for CP1; all listed tests pass; Pint is clean.

## Do not
- Copy center names, position titles or department names into tracking tables.
- Select `salary`, `currency` or any contract detail, or use `select *` on HR tables.
- Add a FK across connections, or a DB unique index on `center_id`.
- Use `whereHas` across the `hr` and default connections.
- Change `ProjectStatus` or project date rules (Task 05), or build the renewal flow (Task 10).

## Quality bar (this task)
- Employee chip and picker: position, job level, department and center on a compact secondary line; the HR-unavailable fallback text; an «مركز غير فعّال» badge; the center picker is searchable and shows the city.
- The mapping command prints readable tables (matched, ambiguous, no center, duplicated program codes).
- Everything in `quality-bar.md` applies. Before reporting, open every new or changed screen in the browser (Arabic RTL and English, light and dark, desktop and 375 px), run `vendor/bin/phpstan analyse` on the touched paths, and list both in your report.

## Working method
- Start in plan mode: restate the task in 5 bullets, list files you will touch, list open questions (profile return shape, `isOpen()` vs `draft`/`approved`/`active`, handling of unmapped projects); wait for approval.
- Implement in small steps; after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory).
- Do not commit, push, or run destructive commands (`migrate:fresh`, seeders that reset roles, `--apply` on shared data) unless the developer asks.
- Never run anything against production, and never write to the HR database.
- Stay inside Task 30's scope; note anything else as a follow-up.

## Report back
Your final message must contain: a short summary; files created/changed; the migration (name, up/down); tests run with pass/fail output; the dry-run mapping report (counts per class + list of ambiguous and no-center projects, cities 7/8/12/13/14/17); decisions assumed (D-30, open-status definition). Handoff facts: the exact `EmployeeProfile` API and return shape (Task 07 reviewers, Task 15 MEAL owner per city/center, Task 17 center manager, Task 21 department recipients, Task 23 direct manager from `positions.direct_manager_id`, Task 31 department tasks via `employeeIdsInDepartment`); `HrCenter` lookup methods; how the "one open project per center and program" check is exposed (Task 05 create/restore, Task 06 closed → draft re-link and Task 10 duplicate detection all reuse it — one method, not three).
````
