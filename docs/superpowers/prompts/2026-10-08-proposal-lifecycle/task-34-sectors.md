# Task 34 — Sectors: what a project covers

> **المهمة:** القطاعات (Sectors): وحدة قابلة للإدارة تصف ما يغطيه المشروع، ولكل مشروع عدة قطاعات (المراجعة 6، مع تعديلات المراجعتين 8 و9)
> **المطوّر:** B3 (مراجعة Dev 2) · **الفريق:** B · **الأيام:** 4–6 في الخطة (الخميس 2026-10-15 ← الاثنين 2026-10-19) · **الجهد:** يوم ونصف · **يعتمد على:** Task 02 (`sectors.view` و`sectors.manage`)، Task 05 (نموذج المشروع) · **يعتمد عليها:** Task 18 وTask 19 (مرشح القطاع، اختياري)، Task 27 (البذر)
> **متى تستخدمه:** بعد CP0 (أسماء الصلاحيات مجمّدة) ومزامنة Task 02. **تنبيه جدولة:** الخطة تضع المهمة في الأيام 4–6، بينما يُدمج نموذج المشروع (Task 05) في اليوم 8. لذلك تُنفَّذ على مرحلتين في الجلسة نفسها أو جلستين: (1) الأيام 4–6: الجدول والنموذج والصفحة والبذر و`SyncProjectSectors` وحقل القطاعات كمكوّن مستقل؛ (2) بعد دمج Task 05: وضع الحقل في الخانة التي تتركها Task 05 في نموذج المشروع، وشارات صفحة المشروع، والعمود والمرشح في قائمة المشاريع. لاحظ أن B3 مشغول أيضًا بـ Task 31 في الأيام 3–6؛ يؤكد Dev 2 الأيام الفعلية.
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

> هذا البرومبت كُتب بعد المراجعة 9، فنصّه يجمع المراجعات 6 و8 و9 في متن واحد. عند أي تعارض مع الخطة، الخطة هي المرجع (`### Task 34` وأقسام «تعديلات المراجعة» فيه).

````markdown
## Role and goal
You are implementing Task 34 of the HC Tracking proposal-lifecycle correction plan in this Laravel 13 / PHP 8.5 / Livewire 4 / Flux UI 2 repository. The outcome: a managed **Sectors** list (Health, Food Security & Livelihoods, WASH, Shelter & NFIs, Protection, Education — Arabic and English) that MEAL and the General Manager maintain from a **Sectors** admin page, and a many-to-many link from projects to sectors, picked on the project form (at least one sector required for **new** projects), shown as badges on the **Project** page, and usable as a column and filter on **Projects**. A sector is not a strategic pillar (Task 32) and not a program: it describes what a project covers, for reporting by sector through the project.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this PR is reviewed against).
2. The plan `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md` — Arabic and authoritative; English names are exact. Read `### Task 34` in full with its «تعديلات المراجعة 8» and «تعديلات المراجعة 9» subsections (they replace the base text where they differ: S-1 final list, S-2 required for new projects, S-3 project-only link, S-4 no Super Admin), section 9 row D-47, `### Task 05` (the project form you plug into, and its «تعديلات المراجعة 6» line on sectors), `### Task 02` «تعديلات المراجعة 6 و8» (permissions), section 0 «قواعد العمل بين الفريقين».
3. The decision log `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` (D-47, S-1…S-4, and T-03: the plan's base security line still lists Super Admin in `sectors.manage`; Revision 8 removed it).
4. Rule files: `.ai/rules/database-migrations.md`, `migrations.md`, `feature.md`, `models.md`, `providers.md` (strict mode: mirror column defaults in `$attributes`), `factories.md`, `seeders.md`, `database-seeders.md`, `console-commands.md`, `views-pages.md`, `pages.md`, `ui.md`, `views.md`, `views-enums.md`, `admin.md`, `components-ui.md`, `navigation.md`, `components-navigation.md`, `app.md`, `routes.md`, `tests.md`. Then run `grep -rin 'admin\|seed\|filter\|multi' .ai/rules`.
5. Code to study first: `resources/views/pages/admin/⚡cities.blade.php` and `⚡accounts.blade.php` (admin page anatomy, `#[Computed]` single read), `resources/views/pages/projects/⚡index.blade.php` (form, filters, columns) and `⚡show.blade.php` (header meta), `resources/views/components/ui/{page-header,header-filter-select,header-filter-multiselect,multi-select-popover,data-table,empty-state,confirm-modal,form-section,enum-badge}.blade.php`, `app/Models/Project.php`, `app/Policies/ProjectPolicy.php`, `config/access-control.php` (`sectors.*` from Task 02), `resources/views/components/navigation/sidebar.blade.php`, `routes/web.php`, an existing Action pair such as `app/Actions/Donors/{SaveDonor,DeleteDonor}.php`, `tests/Feature/Admin/CityDirectoryTest.php`.
6. Skills: `laravel-best-practices`, `database-migrations`, `livewire-development`, `fluxui-development`, `tailwindcss-development`, `laravel-security`, `testing-best-practices`. Use Boost `database-schema` on `projects` before the pivot migration, and `search-docs` for `belongsToMany` `sync()`, `Rule::exists` with conditions and Livewire `#[Url]`.

## Preconditions to verify
- `sectors.view` (in `$viewEverything`) and `sectors.manage` (MEAL, General Manager) exist in `config/access-control.php` and are synced locally (Task 02).
- No `sectors` or `project_sector` table exists.
- Phase 2 of the work (form wiring) needs Task 05 merged with the sectors slot it leaves in the project form. If Task 05 is not merged, finish phase 1 and report; do not edit the project form in parallel with Dev 1.
If a check fails, stop and report instead of improvising.

## Scope
### In scope — phase 1 (days 4–6)
- Migration `create_sectors_table`: `id`, `name_ar` (VARCHAR(100), unique), `name_en` (VARCHAR(100), unique), `description` (VARCHAR(500), nullable), `is_active` (default true), `sort` (unsigned smallint, default 0), timestamps.
- Migration `create_project_sector_table`: `project_id` (FK `cascadeOnDelete`), `sector_id` (FK `restrictOnDelete`), unique (`project_id`, `sector_id`), explicit names. `projects` itself is not changed (Phase 1 table rule).
- Model `Sector` (`#[Fillable]`, `casts()`, `$attributes` mirroring `is_active` and `sort`, `TracksActivity`, `#[Scope] active()`, `localizedName()`), factory with `inactive()`; `Project::sectors()` (`belongsToMany`, ordered by `sort`).
- `SectorPolicy` (`viewAny`/`view` → `sectors.view`; `create`/`update`/`deactivate`/`delete`/`reorder` → `sectors.manage`).
- Actions in `app/Actions/Sectors/`: `SaveSector`, `DeactivateSector` (and reactivate), `DeleteSector` (refused while used), `ReorderSectors`, `SyncProjectSectors`.
- **Sectors** page `/admin/sectors` (`pages::admin.sectors`, route `admin.sectors.index`, `->can('sectors.view')`): list with name (current locale + the other language below), description, status badge, project count, and actions for `sectors.manage` holders (create, edit, deactivate/reactivate, delete when unused, reorder with keyboard-accessible up/down buttons); sidebar entry in the administration group behind `sectors.view`.
- **Initial list (S-1, Revision 9)** — six sectors, created idempotently by `name_en` through a data migration or a small command, **not only through `DatabaseSeeder`** (production never runs `db:seed`, Task 27/29); the local seeder may call the same code:

  | # | العربية | English |
  |---|---|---|
  | 1 | الصحة | Health |
  | 2 | الأمن الغذائي وسبل العيش | Food Security & Livelihoods |
  | 3 | المياه والإصحاح البيئي والنظافة | Water, Sanitation and Hygiene (WASH) |
  | 4 | المأوى والمواد غير الغذائية | Shelter & Non-Food Items (NFIs) |
  | 5 | الحماية | Protection |
  | 6 | التعليم | Education |

- A reusable sectors field (e.g. `resources/views/components/projects/sectors-field.blade.php` over `x-ui.multi-select-popover`, active sectors only, keeps already-linked inactive sectors visible with an «معطَّل» badge).
- Event keys `sector.created`, `sector.updated`, `sector.deactivated`, `project.sectors_changed` registered in Task 21's `EventCatalog` (digest importance).
- Lang `lang/{en,ar}/sectors.php`, `titles.php`, `navigation.php`, `notifications.php` (`events.sector.*`).
### In scope — phase 2 (after Task 05 merges)
- Put the sectors field in the slot Task 05 left in the project form; `projects/⚡index` saves through `SyncProjectSectors` in the same request flow as the project save (one transaction if Task 05's save Action allows it; otherwise immediately after, inside its own transaction — decide with Dev 1).
- **Project** page: sector badges in the header meta.
- **Projects** list: a sectors column and a sector filter (`x-ui.header-filter-select` or `header-filter-multiselect`), including a «بلا قطاع» option.
### Out of scope
Sector filters in **Monthly Matrix** or **Donor Report Builder** (optional for Tasks 18/19 later); linking sectors to proposals, programs or indicators (S-3: project only); any change to `projects` columns.

## Business rules
- A sector used by any project cannot be deleted — «هذا القطاع مرتبط بمشاريع؛ عطّله بدل حذفه». Deactivate instead.
- A deactivated sector stays visible on the projects already linked (badge «معطَّل») and is never offered for a new choice.
- **S-2:** at least one sector is required for **new** projects — «اختر قطاعًا واحدًا على الأقل». Existing projects may have none until MEAL completes them (the «بلا قطاع» filter helps); editing an existing project without sectors does not force one, but removing the last sector of a project that has one is refused.
- **S-3:** sectors link to projects only; reports by sector are read through the project.
- Changing a project's sectors is recorded in **Activity Log** (one `project.sectors_changed` entry with added/removed names).

## Security and authorization
- Manage: `sectors.manage` (MEAL, General Manager — S-4; Super Admin only technically, D-20a). View: `sectors.view`.
- Linking sectors to a project: `Gate::authorize('update', $project)` inside `SyncProjectSectors`.
- BOLA: `sector_ids` are accepted only from active sectors (`Rule::exists` with `is_active = true`), loaded through the query, never trusted from the request; ids of inactive sectors already linked are kept, not re-added.
- Every Action calls `Gate::authorize`; no write from the Blade page.

## Validation
Messages in `lang/{en,ar}/sectors.php`:
- `nameAr`: required, max:100, unique — «اسم القطاع بالعربية مستعمل». `nameEn`: required, max:100, unique — "This English sector name is already used."
- `description`: nullable, max:500.
- `sectorIds`: array, min:1 on create (S-2); `sectorIds.*`: integer, an existing active sector — «اختر قطاعًا نشطًا».

## Edge cases and concurrency
- Two saves with the same name: the unique index rejects the second; catch `UniqueConstraintViolationException` and show the field error.
- A sector deactivated while someone edits a project that picks it: the save refuses that sector with the field message.
- Deleting a project removes only its pivot rows (`cascadeOnDelete`).
- Reorder clicked twice quickly: the Action reads the current order under a lock and writes it once.

## Data integrity
- `SyncProjectSectors` runs in a transaction with `sync()`; unique keys on names and on the pivot.
- The initial-list step is idempotent by `name_en`; a second run changes nothing and never renames a sector an admin edited.
- utf8mb4_unicode_ci / InnoDB DYNAMIC; `DatabaseEncodingTest` green.

## Implementation guidance
- Follow the existing admin pages (`pages::admin.cities`, `pages::admin.accounts`): `x-ui.page-header` with a count badge, create button in the actions slot, a single `#[Computed]` read with `withCount('projects')`, Flux modals bound to booleans with `wire:model`, `x-ui.confirm-modal` for deactivate and delete.
- Sectors are tracking data, not HR: no `HrModel`.
- Terminology: «القطاع» (Sector); never mix with «المحور الاستراتيجي» (pillar) or «البرنامج».

## Tests (Pest)
- `tests/Feature/SectorsTest.php`: MEAL and General Manager create, edit, deactivate, reactivate, reorder and delete an unused sector; another role gets 403 on each write (fresh Livewire instance per forbidden call) but can view; duplicate names refused; deleting a used sector refused; the initial-list step creates six sectors once and is a no-op on the second run.
- `tests/Feature/ProjectSectorsTest.php` (phase 2): a new project without a sector is refused with the message; saving with two sectors links both and logs one activity entry; an inactive sector is refused for a new choice but stays visible on a linked project; a user without `projects.update` on the project gets 403; removing the last sector of a project that has one is refused; the projects list filter (including «بلا قطاع») narrows rows; the query count of `/admin/sectors` and of the projects list stays flat.
- Arabic and English render; `LocalizationTest` and `DatabaseEncodingTest` green.

## Acceptance criteria
- [ ] MEAL adds a sector and links it to a project in under a minute; it shows on the project page, the projects list and its filter.
- [ ] The six initial sectors exist on every environment through an idempotent, production-safe step.
- [ ] New projects cannot be saved without a sector; existing ones can be completed later.
- [ ] No Super Admin in any business default; every write has a 403 test.

## Do not
- Store sectors as text or JSON on `projects`, or add any column to `projects`.
- Delete a sector in use, or hide an inactive sector from a project that already has it.
- Seed production data only through `DatabaseSeeder`.
- Edit the project form before Task 05 merges, bind a Flux modal with `:open`, or use `@php(...)` shorthand.

## Quality bar (this task)
- States: empty list (with a create button for managers), loading on every write button, inline field errors, a toast after save, the "in use — deactivate instead" explanation in the delete modal.
- RTL/LTR: names with `dir="auto"`; up/down reorder icons flip correctly in RTL; the page works at 375 px (list collapses to cards or scrolls inside its panel).
- Accessibility: reorder by keyboard with announced position (`aria-live`), labelled inputs, focus returned to the triggering row after a modal closes.

## Working method
- Start in plan mode: restate the task in 5 bullets, list files you will touch, list open questions (initial-list mechanism, where the project save and the sector sync share a transaction, phase-2 timing with Dev 1); wait for approval.
- Implement in small steps; after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes, and `vendor/bin/phpstan analyse` on touched paths.
- Verify every screen in the browser (Arabic RTL + English, light + dark, desktop + 375 px) before reporting.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory).
- Do not commit, push, or run destructive commands unless the developer asks. Never run anything against production.
- Stay inside this task's scope; note anything else as a follow-up.

## Report back
Summary; files changed; migrations added; tests run with pass/fail output; screens checked (locale × theme × width); decisions assumed (S-1…S-4, the initial-list mechanism, the transaction shape); follow-ups (phase 2 if Task 05 was not merged; sector filters for Tasks 18/19). Handoff for Task 26 (route, title, `data-tour` anchors), Task 27 (the initial-list step and its expected output, the «بلا قطاع» count of existing projects), Task 28 (the UAT script: add a sector, link it, filter by it).
````
