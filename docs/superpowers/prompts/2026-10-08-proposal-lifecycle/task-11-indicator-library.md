# Task 11 — Indicator Library

> **المهمة:** **Indicator Library**
> **المطوّر:** B2 (مراجعة Dev 2) · **الفريق:** B · **الأيام:** 2–5 (الثلاثاء 2026-10-13 ← الجمعة 2026-10-16) · **الجهد:** 4 أيام (المراجعة 3) · **يعتمد على:** Task 01، Task 02
> **متى تستخدمه:** في اليوم 2 بعد CP0 (نهاية اليوم 2: سجل القرارات، خصوصًا D-10 وD-11 وD-25، وأسماء صلاحيات `indicator-library.*` من Task 02)؛ يتوازى مع Task 03، ويجب تثبيت مخطط `library_indicators` في نقطة التكامل CP1 (نهاية اليوم 3، 2026-10-14) لأن Task 12 وTask 13 وTask 18 تبني عليه.
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are implementing Task 11 of the HC Tracking proposal-lifecycle correction plan in this Laravel 13 / PHP 8.5 / Livewire 4 / Flux UI 2 repository. The outcome: a central, bilingual **Indicator Library** at `/indicator-library`, managed by MEAL and the General Manager (create, edit, deactivate, delete only when unused), with existing `indicators` rows linked to it through `indicators.library_indicator_id`, and an idempotent command `meal:seed-indicator-library {--dry-run}` that turns today's 61 indicators into library entries and links them. In the corrected story (draft project without dates -> concept note -> proposal -> donor approval fixes dates and funds the project -> MEAL confirms the monthly report template -> project active -> one agreement), the MEAL side picks a proposal's indicators from this library instead of retyping them (Task 13), every monthly-report template item must come from it (Task 33), and the **Monthly Matrix** shows their codes and Arabic/English names (Task 18). Since Revision 9 indicators are fixed **per program** and each entry gets a server-generated, organisation-wide unique code `{program code}.{level}.{serial}` (e.g. `002.OP1.2.3`). The library schema is a frozen contract at CP1 (end of day 3, 2026-10-14).

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins) (Laravel Boost rules, Pest, Pint, `php artisan make:* --no-interaction`, no new dependencies or base folders).
2. The plan `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md`: section `### Task 11` in full. It is Arabic and authoritative; English table, column, enum, permission and UI names in it are exact. Also read section 1.2 (MEAL side), section 5 rows "الصلاحيات", "Mass assignment", "التكرار وIdempotency", "سجل التدقيق", section 7 row CP1, section 0 «قواعد العمل بين الفريقين», and section 9 rows D-10, D-11, D-25.
3. The decision log `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` if it exists. A recorded decision overrides the plan's default for D-10/D-10a/D-10b (now decided: generated per-program code), D-11 (the command creates drafts MEAL reviews), D-25/D-25a/D-25b (decided: `denominator_basis`, unique counts entered as new-this-month), D-50/D-50b (disaggregation set by MEAL).
4. Rule files: `.ai/rules/database-migrations.md`, `.ai/rules/migrations.md`, `.ai/rules/feature.md` (JSON columns are `utf8mb4_bin` on MariaDB), `.ai/rules/models.md`, `.ai/rules/providers.md` (strict mode, morph map), `.ai/rules/enums.md`, `.ai/rules/views-enums.md`, `.ai/rules/factories.md`, `.ai/rules/seeders.md`, `.ai/rules/database-seeders.md` (bulk writes under `Activity::withoutRecording`), `.ai/rules/console-commands.md`, `.ai/rules/indicators.md` (load once, filter in memory, flat query count), `.ai/rules/views-components-ui.md` (measure locks once targets exist), `.ai/rules/resultsindicatorstargetsreports.md`, `.ai/rules/pages.md`, `.ai/rules/ui.md`, `.ai/rules/views.md`, `.ai/rules/views-pages.md`, `.ai/rules/routes.md`, `.ai/rules/app.md`, `.ai/rules/components-navigation.md`, `.ai/rules/navigation.md`, `.ai/rules/navigation-css.md` (logical classes, RTL), `.ai/rules/recipients.md`, `.ai/rules/tests.md`. Then run `grep -rin 'indicator\|measure\|library' .ai/rules`.
5. Existing code to study first:
   - `resources/views/pages/indicators/⚡index.blade.php`: load-once `#[Computed]` reads, in-memory `search` filter (~line 466), `saveIndicator()` and `changeKeepsTargetsInChain()` (measure lock, ~line 852), `forgetIndicators()`, the four `wire:model` modals.
   - `app/Models/Indicator.php` (`#[Fillable]`, `$attributes` mirror of column defaults, `casts()`, `RecordsActivity`), `app/Models/PlanIndicator.php` (`measuresPercentage()` reads `indicators.measure`; `achievedValue()`), `app/Models/Result.php`.
   - `app/Enums/IndicatorMeasure.php`, `app/Enums/ResultType.php`, `app/Enums/Period.php`, `app/Enums/Concerns/HasTranslatedLabel.php`.
   - Action shape: `app/Actions/Donors/SaveDonor.php`, `app/Actions/Donors/DeleteDonor.php` (plain class, `handle()`, `Gate::authorize` inside, input already validated by the component). Policy shape: `app/Policies/RiskPolicy.php`.
   - Command shape: `app/Console/Commands/SyncPermissions.php` (`--dry-run`, `Activity::withoutRecording` + `DB::transaction`).
   - `app/Providers/AppServiceProvider.php::configureMorphMap()`, `config/notifications.php` (`modules`, `module_icons`), `app/Notifications/Recipients/WatcherRecipients.php`, `app/Notifications/SubjectLinks.php`, `app/Support/Like.php`.
   - `resources/views/components/navigation/sidebar.blade.php` (Monitoring & Evaluation group), `routes/web.php` (`indicators.index`), `database/migrations/2026_09_07_133614_create_indicators_table.php`, `database/seeders/MealSeeder.php`.
   - Tests: `tests/Feature/IndicatorDirectoryTest.php` (query-count test ~line 337), `tests/Feature/NavigationTest.php` (Super Admin route order), `tests/Feature/Models/MorphMapTest.php`, `tests/Feature/DatabaseSeederTest.php`, `tests/Feature/SyncPermissionsCommandTest.php`, `tests/Feature/Notifications/QuietWritesTest.php`, `tests/Pest.php` (`userWithRole()`, `insertIndicator()`).
6. Skills to activate: `laravel-best-practices`, `livewire-development`, `fluxui-development`, `tailwindcss-development`, `database-migrations`, `laravel-security`, `testing-best-practices`.
7. Use Laravel Boost `search-docs` before using framework APIs (Livewire computed properties and `#[Url]`, `Rule::unique()->ignore()`, `Rule::enum`, JSON casts, Artisan command options, `UniqueConstraintViolationException`). Use `database-schema` on `indicators` and `plan_indicators` before writing the migrations.

## Preconditions to verify
- `config/access-control.php` contains `indicator-library` => `view`, `create`, `update`, `delete`; `view` reaches every role, and `create/update/delete` reach MEAL and General Manager — Task 02. Today the module does not exist.
- No `library_indicators` table, `App\Models\LibraryIndicator`, `App\Enums\CollectionFrequency` or `App\Enums\Disaggregation` exists yet.
- `indicators` has no `library_indicator_id` column yet.
If a check fails, stop and report instead of improvising.

## Revision 2 changes (2026-10-08)
- Owner is now **B2 (Team B)**, reviewed by Dev 2; days 2–5 (Tue 2026-10-13 → Fri 2026-10-16) of the 15-working-day window (Mon 2026-10-12 → Fri 2026-10-30).
- The `library_indicators` schema now freezes at **CP1, end of day 3 (2026-10-14)**: push the migration on day 2 so Task 12 (B1) and Task 13 (B2) can build on it.
- Work under the inter-team rules (plan section 0 «قواعد العمل بين الفريقين»): do not change Phase 1 tables (`proposals`, `projects`, `donor_proposal`, `proposal_status_notes`) or the proposal state machine — request any such change from Dev 1 so it is recorded in the plan; integrate with Phase 1 by listening to its events (`ProposalStatusChanged`, `ProjectActivated`, `ProjectPeriodChanged`, `FundingAgreementRecorded`, dispatched after commit) instead of calling Phase 1 Actions directly; every PR is reviewed by Dev 2 (UI) or Dev 1 (backend) within one working day and merges only with this task's suite green; no production access — seed or masked data only.
- Register this task's events (`library_indicator.created/updated/deactivated`, digest importance) in the Task 21 event catalog `App\Notifications\EventCatalog` so MEAL and General Management are notified (plan section 1.0, fact 8; `EventCatalogCoverageTest` must stay green). If the catalog is not merged yet, add the keys in your PR and coordinate with Dev 3.

## Revision 3 changes (2026-10-09)
Source: `Stratigy.pptx` (strategy 2026–2030) and `micro logic.xlsx` (the real Micro program logframe, urban and agricultural tracks). Read plan section 1.0 facts 10–12 and the «تعديلات المراجعة 3» subsection of this task. Where this block conflicts with text further down this prompt, this block wins.
- Effort is now **4 days**: day 5 is fully for the seed command and the `average` measure; Task 13 starts on day 6.
- New `library_indicators` columns: `calculation_method` (text: numerator/denominator/formula, separate from `definition`), `numerator_label` and `denominator_label` (nullable; input labels for ratio/average lines in Task 17), `collected_by` (new enum `App\Enums\IndicatorCollector`: `project_team`, `meal`; default `project_team`), `frequency_note` (free text in the logframe's own wording), `follow_up_months` (nullable smallint, required when frequency is `follow-up`), `is_legacy` (boolean).
- `CollectionFrequency` cases become: `monthly`, `quarterly`, `semi-annual`, `annual`, `per-event`, `per-season`, `follow-up`, `baseline-endline`, `one-time` (drop `midline-endline`).
- Add `IndicatorMeasure::Average` (`'average'`). Achievement for one target with an average measure = **latest approved value** (like percentage), not a sum. Change `PlanIndicator::achievedValue()` AND `achievedValueSql()` together (rename the private `measuresPercentage()` to `measuresLevel()` covering percentage + average), update the PHP/SQL agreement test in `tests/Feature/Models/PlanIndicatorTest.php`, and record the change with `record-rule` on `.ai/rules/resultsindicatorstargetsreports.md`. Average has no 100 cap.
- `name_ar` is required, `name_en` is **optional** (the source logframe is Arabic only). Entries without an English name show a «missing English name» badge and filter, and fall back to Arabic.
- `meal:seed-indicator-library` marks the entries it creates from today's 61 indicators as `is_legacy = true`. The real Micro indicators are imported by Task 32, not by this command.
- Extra tests: average measure → latest approved value, PHP and SQL agree; `follow_up_months` required for `follow-up`; an entry with only an Arabic name saves and renders.

## Revisions 6–8 changes (2026-10-12)
Source: decision-workshop answers (R6 file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.docx`) and two real M&E workbooks (`Syria Cross e.V._ME_Tool_IPTWP_V1_2026_Aug.xlsx`, `Children University - IPTT - 2026.xlsx`). Read the plan header paragraphs «المراجعة 6/7/8», decision rows D-10, D-50, and the «تعديلات المراجعة 6, 7 and 8» subsections of this task in the plan. Open questions still running on their documented default are listed in `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r7.ar.docx`. Where this block conflicts with text further down this prompt, this block wins.
- **D-50 (decided):** `App\Enums\Disaggregation` becomes `gender`, `disability` (drop `age_group` for October). New `library_indicators.is_disaggregated` (boolean, default false), set by MEAL: indicators about people are disaggregated; counts of sessions, workshops or centers are not. When on, the breakdown is always: male, of whom persons with disabilities; female, of whom persons with disabilities.
- **D-10a (default applied):** the mandatory code lives on the project/proposal indicator (`indicators.code`), unique within the project, repeatable across projects. Children University codes (`G1`, `O1.1`, `Op1.2a`) collide with Micro's, which supports this. The library code is an optional reference used to suggest a code.
- From the Children University logframe: «وقت الحساب» → `frequency_note` verbatim; «الشخص المسؤول» (MEAL, PM, Admin, CM) → `collected_by` (`meal` or `project_team`), original text kept in `data_source`.
- Validation guards found in the workbooks: a percentage target is 0–100, not 0–1 (Syria Cross cell R52 used 70 vs 0.7); `measure` decides the unit even if the name says «عدد».
- **Super Admin is for developers only (D-20a).** It keeps every permission technically (`Role::booted()`), but never appears in a business default list, is never a fallback decision-maker, and is never the only account a test uses.

## Revision 9 changes (2026-10-12)
Source: the corrected R6 answers file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.fixed.docx`, which is newer than the R7 questions file and replaces the defaults Revision 8 applied. Read the plan header paragraph «المراجعة 9», the decision rows in section 9 (and «الأسئلة المتبقية بعد المراجعة 9»), and the «تعديلات المراجعة 9» subsection of this task. **Where this block conflicts with any text further down, including the Revisions 6–8 block, this block wins.**
This block **replaces** the code rules of Revisions 6 and 8 (D-10/D-10a defaults).
- **Indicators are fixed per program (D-10a).** New `library_indicators` columns: `program_id` (HR id, no FK, nullable = institutional indicator), `level_ref` (string 20, level token + node number in the program framework: `G`, `OC1`, `OP1.2`, `AC1.2.1`, `CC`), `serial` (unsigned smallint).
- **Code = `{programs.code}.{level_ref}.{serial}`**, e.g. `002.OP1.2.3` (Micro Project, output 1.2, 3rd indicator); also `002.G.1`, `002.OC1.2`, `002.AC1.2.1.1`, `002.CC.1`. Server-generated, never typed; `library_indicators.code` becomes NOT NULL and unique organisation-wide. Serial = next within (`program_id`, `level_ref`) inside a transaction with `lockForUpdate`; serials of deactivated entries are never reused; the code is stored and frozen after first use (an HR code change later does not rewrite it). Changing program/output is allowed only while unused and regenerates the code.
- **Level tokens (D-10b, decided):** `G` Goal (Impact), `OC` Outcome, `OP` Output, `AC` Activity, `CC` Cross-Cutting. Institutional indicators use prefix `HC` (Hope Center) instead of a program code, e.g. `HC.OP1.1`.
- **Unique counts (D-25b, decided):** no special flag. For «unique individuals» indicators the manager enters **only new individuals this month**, so the total is the sum of months like any count; MEAL states this in `definition` and the field label.
- `indicators.code` is no longer typed: copied from the library on attach, read-only, kept for compatibility and legacy rows.
- **HR program codes are not unique today** (local dev HR, 2026-10-12): `003` = Relief Programs (inactive) and Family to Family; `004` = Earthquake Assistance and School to School; `009` = Sha8er, Entrepreneurship project, CYG. HR is read-only: creating an entry for a program whose code is duplicated is rejected «رمز البرنامج مكرر في HR، اطلب تصحيحه من فريق HR»; the unique index protects the table regardless.
- `meal:seed-indicator-library`: program from the result's program, `level_ref` from the node type and number, `HC` when no program; `--dry-run` prints proposed codes for MEAL review and stops on a duplicated program code, naming it.
- Validation: `programId` required unless institutional, active HR program — «اختر البرنامج»; `levelRef` required, regex `^(G|CC|OC\d+|OP\d+(\.\d+)*|AC\d+(\.\d+)*)$` — «أدخل المستوى ورقمه كما في إطار البرنامج، مثل OP1.2».
- **D-25a resolved:** `library_indicators.denominator_basis` (`App\Enums\DenominatorBasis`: `fixed`, `entered`) for percentage indicators. Default `fixed` when `collected_by = project_team`, `entered` when `meal`; MEAL can override.
- Library page grouped/filterable by program.
- Tests: code format; serial increments per output and restarts per new output; concurrent saves never collide; duplicated HR program code rejected; deactivated serial not reused; institutional `HC` prefix; code frozen after use. Effort +0.5 day.

## Scope
### In scope
- Create migration `create_library_indicators_table` with the consolidated Revision 3 + 8 + 9 columns: `id`, `code` (VARCHAR(50), **NOT NULL, unique**, server-generated), `program_id` (unsigned big int, nullable = institutional, HR id, no FK), `level_ref` (VARCHAR(20)), `serial` (unsigned smallint), unique (`program_id`, `level_ref`, `serial`) (institutional rows use a fixed program sentinel or a separate unique — propose in plan mode), `name_ar` (VARCHAR(255), required), `name_en` (VARCHAR(255), nullable), `definition` (text, nullable: what it measures), `calculation_method` (text, nullable: numerator / denominator / formula), `numerator_label`, `denominator_label` (VARCHAR(255), nullable), `measure` (VARCHAR(50), default `numeric`), `denominator_basis` (VARCHAR(20), nullable; percentage only), `unit` (VARCHAR(50), nullable), `is_disaggregated` (boolean, default false), `disaggregation` (JSON, nullable), `data_source`, `means_of_verification` (nullable), `collected_by` (VARCHAR(20), default `project_team`), `is_cumulative` (boolean, default false), `frequency` (VARCHAR(50)), `frequency_note` (text, nullable), `follow_up_months` (unsigned smallint, nullable), `suggested_level` (VARCHAR(50), nullable), `is_legacy` (boolean, default false), `is_active` (boolean, default true), timestamps. (`imported_at` belongs to Task 32's migration; do not add it here.)
- Create migration `add_library_indicator_id_to_indicators_table`: nullable `foreignId('library_indicator_id')->constrained('library_indicators')->restrictOnDelete()`.
- Create enums (all with `HasTranslatedLabel` and `values()`): `App\Enums\CollectionFrequency` (`monthly`, `quarterly`, `semi-annual`, `annual`, `per-event`, `per-season`, `follow-up`, `baseline-endline`, `one-time`), `App\Enums\Disaggregation` (`gender`, `disability`), `App\Enums\IndicatorCollector` (`project_team`, `meal`), `App\Enums\DenominatorBasis` (`fixed`, `entered`). Add `IndicatorMeasure::Average`. Add `CollectionFrequency::period(): ?Period` (null for the non-periodic cases) so Task 13 can fill `indicators.frequency`.
- One code generator (e.g. `App\Support\IndicatorCodes::next(?int $programId, string $levelRef): array{code: string, serial: int}`) used by `SaveLibraryIndicator`, the seed command and Task 32's import; it reads `programs.code` from HR, uses `HC` for institutional entries, locks within (`program_id`, `level_ref`), never reuses a serial and refuses a duplicated HR program code.
- Create `app/Models/LibraryIndicator.php` (+ factory with states `inactive()`, `percentage()`, `average()`, `institutional()`, `disaggregated()`, `collectedByMeal()`), `Indicator::libraryIndicator()` and `LibraryIndicator::indicators()`, a `#[Scope] active()`, a `#[Scope] forPrograms(array $programIds)` (program entries + institutional), and a `localizedName()` helper (user's locale, falls back to Arabic).
- Create `app/Policies/LibraryIndicatorPolicy.php` and Actions `app/Actions/LibraryIndicators/SaveLibraryIndicator.php`, `DeactivateLibraryIndicator.php`, `DeleteLibraryIndicator.php` (a matching `reactivate` path is recommended; confirm in plan mode).
- Create page `resources/views/pages/indicator-library/⚡index.blade.php` (`pages::indicator-library.index`), route `indicator-library.index` at `/indicator-library` with `->can('indicator-library.view')`, title key `titles.indicator_library`, and a Sidebar item in the Monitoring & Evaluation group (add `indicator-library.view` to that group's `@canany`). The page groups entries by program (institutional last) and filters by program, level, measure, collector, active/inactive, «missing English name» and «legacy».
- Create command `app/Console/Commands/SeedIndicatorLibrary.php` with signature `meal:seed-indicator-library {--dry-run}`.
- Morph key `library_indicator`; module key `indicator-library` registered in `config/notifications.php` (`modules` with a new `LibraryIndicatorRecipients extends WatcherRecipients` watching `MEAL`, `module_icons`); `SubjectLinks` entry pointing to `indicator-library.index`.
- Lang: `lang/{en,ar}/indicator_library.php`, enum labels in `lang/{en,ar}/enums.php` (`collection_frequency`, `disaggregation`), `titles.php`, `navigation.php`, event sentences in `notifications.php` under `events.library_indicator.*`.
- Add the `library_indicator.*` keys to `App\Notifications\EventCatalog` (Task 21) with MEAL + General Management as oversight recipients.
- Seed a few library entries in `MealSeeder` (under the seeder's existing quiet wrapper) and add `LibraryIndicator` to `DatabaseSeederTest`.
### Out of scope
Selecting library entries in proposals and projects, attaching them to results, targets per center (Task 13); `ResultType::Activity` and proposal frameworks (Task 12); Monthly Matrix (Task 18); guided tour content and the user guide (Task 26, but keep stable `data-*` hooks on the page); permission catalog changes (Task 02); any change to the achievement definition (D-25).

## Business rules
- Fields as listed in Scope; `name_ar` is required and `name_en` optional (Revision 3); `code` is generated by the server from program + level + serial, unique organisation-wide and never typed (Revision 9, D-10a/D-10b); `follow_up_months` is required when the frequency is `follow-up`; `denominator_basis` applies to percentage entries only (default `fixed` for `project_team`, `entered` for `meal`).
- `measure` locks as soon as any linked `indicators` row has `plan_indicators` (same reason as the existing lock: it decides how reports add up).
- `indicators.measure` is not a descriptive copy: `PlanIndicator::achievedValue()` reads it. A linked instance must always carry its library entry's `measure`. When the library measure changes (allowed only while unlocked), `SaveLibraryIndicator` updates every linked `indicators.measure` in the same transaction. Record this with `record-rule` so Task 13 follows it.
- Display reads names and definition from the library, so an edit shows everywhere. `indicators.name` stays filled for the existing pages (recommended from `name_en`; confirm in plan mode, and whether a library rename also refreshes linked `indicators.name` — watch the `indicators` unique `(result_id, name)`). Committed values (targets, reports) never change.
- Delete is refused while any `indicators` row references the entry; **Deactivate** is the alternative. A deactivated entry stays on existing uses and is never offered for new selection.
- `is_cumulative` is stored and displayed, and changes no achievement figure (D-25 as decided keeps "latest approved value" for one target; cumulative handling stays as is).
- Program and level may change only while the entry is unused, and regenerate the code; after first use the code is frozen.
- Command: for each indicator without `library_indicator_id`, link it to an existing entry with the same normalized name and the same measure, otherwise create one. Same name with different measures is never merged: two entries are created and the conflict is listed. Already linked indicators are skipped (idempotent). The summary prints created / linked / conflicts. Open question for plan mode: existing names are single-language, but both name columns are required — recommended: detect the script, store the name in the matching column, copy it to the other, and list those entries for MEAL review (D-11); no extra "draft" column unless the developer approves it.

## Security and authorization
- `indicator-library.view` for the page (route `->can()` plus `LibraryIndicatorPolicy::viewAny`); `indicator-library.create`, `.update` (edit, deactivate, reactivate), `.delete` for writes.
- Every Action calls `Gate::authorize` itself; the component only hides buttons. Every public Livewire method that writes must authorize (`tests/Feature/AccessControl/AuthorizationCoverageTest.php` scans for it).
- Load the edited entry by id inside the Action and re-check; never trust a model or `measure` lock state held in Livewire state. Mark ids `#[Locked]`.
- `#[Fillable]` lists only the editable columns; `is_active` changes only through deactivate/reactivate.
- Audit: `LibraryIndicator` implements `TracksActivity` with `RecordsActivity`; events `library_indicator.created`, `library_indicator.updated`, `library_indicator.deactivated` (and `reactivated` if added). The command runs under `Activity::withoutRecording`.
- No money anywhere on this page.

## Validation
Messages in `lang/en/indicator_library.php` and `lang/ar/indicator_library.php`, never inline text.
- `nameAr`: required, string, max:255 — «أدخل اسم المؤشر بالعربية» / "Enter the indicator's Arabic name."; `nameEn`: nullable, string, max:255.
- `code` is not an input. `programId`: required unless the entry is institutional, an active HR program whose code is unique in HR — «اختر البرنامج» / «رمز البرنامج مكرر في HR، اطلب تصحيحه من فريق HR»; `levelRef`: required, regex `^(G|CC|OC\d+|OP\d+(\.\d+)*|AC\d+(\.\d+)*)$` — «أدخل المستوى ورقمه كما في إطار البرنامج، مثل OP1.2».
- `followUpMonths`: required_if frequency is `follow-up`, integer 1–60 — «حدد بعد كم شهر من التمويل يُقاس المؤشر». `collectedBy`: `Rule::enum(IndicatorCollector::class)`. `denominatorBasis`: required for percentage, `Rule::enum(DenominatorBasis::class)`. `numeratorLabel` / `denominatorLabel`: nullable, max:255.
- `measure`: required, `Rule::enum(IndicatorMeasure::class)`; when locked and changed — "The measure cannot change because this indicator has targets." / «لا يمكن تغيير نوع القياس لأن للمؤشر مستهدفات».
- `frequency`: required, `Rule::enum(CollectionFrequency::class)`. `suggestedLevel`: nullable, `Rule::enum(ResultType::class)`.
- `disaggregation`: nullable array, each item `Rule::enum(Disaggregation::class)`, distinct.
- `unit`: nullable, max:50 (cleared for percentage, as `saveIndicator()` does). `definition`, `dataSource`, `meansOfVerification`: nullable strings with sensible max lengths.
- Delete refused — "This indicator is in use. Deactivate it instead." / «هذا المؤشر مستعمل، أوقفه بدل حذفه».

## Edge cases and concurrency
- Two concurrent saves for the same program and level: the generator locks within (`program_id`, `level_ref`), so serials never collide; the unique indexes are the backstop — catch `UniqueConstraintViolationException` and retry once or return a friendly error, never a 500.
- Double submit on create: disable the button with `wire:loading`; warn (do not block) when the same program already has an entry with the same Arabic name and measure.
- Measure lock race: re-check the lock inside the transaction after `lockForUpdate()` on the entry, not from page state.
- Deactivating an entry used by a running proposal: existing indicators and targets stay untouched.
- Delete race (an indicator links between check and delete): the `restrictOnDelete` FK throws; catch it and show the "in use" message.
- Command run twice, or interrupted: skips linked indicators; processes in chunks, each chunk in its own transaction; `--dry-run` writes nothing (assert the database is unchanged).
- Arabic search: the page filters in memory, so normalise both sides (strip tashkeel U+064B–U+065F, U+0670 and tatweel U+0640; unify أ/إ/آ to ا) and cap the term with `App\Support\Like::term()`; use `Like::contains()` only for SQL searches.

## Data integrity
- Migration order: `library_indicators` first, then the `indicators` FK. Both `down()` methods work (drop the FK before the column). Tables are `utf8mb4_unicode_ci`/InnoDB DYNAMIC (`DatabaseEncodingTest` must stay green; the JSON column is the documented `utf8mb4_bin` exception).
- `library_indicators.code` NOT NULL and unique; `(program_id, level_ref, serial)` unique; institutional entries handled as decided in plan mode.
- `indicators.library_indicator_id` restricts deletes; no cascade anywhere.
- Writes go through Eloquent so `RecordsActivity` runs; the measure propagation and the save share one `DB::transaction`.
- Model mirrors column defaults in `$attributes` (`measure`, `is_cumulative`, `is_active`) for strict mode, and casts `disaggregation` to an array of enum values (or `AsEnumCollection`; confirm with `search-docs`).

## Implementation guidance
- Every new PHP file: `declare(strict_types=1);`, explicit return types, PHPDoc array shapes, curly braces everywhere. Model uses `#[Fillable([...])]`, `casts()`, CarbonImmutable `@property` types. Backed enum values stay lower case.
- Create files with `php artisan make:model LibraryIndicator --factory --no-interaction`, `make:policy`, `make:command`, `make:enum` (check `--help`), `make:migration`.
- Page: one `#[Computed]` read of all entries with `withCount` of linked indicators and a boolean "has targets" (no per-row queries), then search, filters (suggested level, measure, active/inactive) and pagination in PHP, like `indicators/⚡index`; a `forgetLibrary()` helper unsets every computed after a write. `x-ui.page-header` with a count badge; filters with `x-ui.header-filter-select`; the create button in the actions slot; modals bound with `wire:model` to booleans (`showEntryModal`, `showDeleteModal`, `showDeactivateModal`), never `:open`. No `@php(...)` shorthand.
- Show the name in the user's locale with the other language as a secondary line (`dir="auto"`); render `code` LTR-isolated. Logical spacing classes only (`ps-*`, `pe-*`, `text-start`). Dark mode uses the Graphite tokens.
- Skeleton:
```php
final class SaveLibraryIndicator
{
    /** @param array{programId: ?int, levelRef: string, nameAr: string, nameEn: ?string, definition: ?string, calculationMethod: ?string, numeratorLabel: ?string, denominatorLabel: ?string, measure: string, denominatorBasis: ?string, unit: ?string, isDisaggregated: bool, dataSource: ?string, meansOfVerification: ?string, collectedBy: string, isCumulative: bool, frequency: string, frequencyNote: ?string, followUpMonths: ?int, suggestedLevel: ?string} $validated */
    public function handle(?LibraryIndicator $existing, array $validated): LibraryIndicator {}
}
```

## Tests (Pest)
Follow `.ai/rules/tests.md`: seed `RolesAndPermissionsSeeder`, use `User::factory()->role(...)` or `userWithRole()`, and build a fresh Livewire instance for each forbidden call.
- Create `tests/Feature/IndicatorLibraryTest.php`: MEAL creates, edits, deactivates and deletes an unused entry; General Manager can too; Team Member, Managers and Financial get 403 on each write (one fresh instance per call) but can view; the code is generated (`002.OP1.2.1`, then `.2`), a code sent in the request is ignored, and a duplicated HR program code is refused with its message; measure change refused when a linked indicator has targets and allowed (and propagated to `indicators.measure`) when not; delete of a used entry refused, deactivate allowed and the entry drops out of `LibraryIndicator::active()`; Arabic search matches with and without tashkeel; query count is equal for 5 and 50 entries; the page renders in Arabic with labels from `lang/ar`.
- Create `tests/Feature/Models/LibraryIndicatorTest.php`: casts (enums, JSON disaggregation), relations, column defaults by raw insert, FK `restrictOnDelete` from `indicators`.
- Create `tests/Feature/SeedIndicatorLibraryCommandTest.php`: links every unlinked indicator; same name + same measure shares one entry; same name + different measures creates two entries and reports the conflict; second run creates nothing; `--dry-run` leaves every table unchanged; no `activities` rows are written.
- Update `tests/Feature/NavigationTest.php` (route order includes `indicator-library.index`), `tests/Feature/Models/MorphMapTest.php`, `tests/Feature/EnumCastTest.php`, `tests/Feature/DatabaseSeederTest.php`; keep `EventCatalogCoverageTest` (once Task 21 is merged), `LocalizationTest`, `DatabaseEncodingTest`, `AccessControl/PolicyCoverageTest`, `AccessControl/AuthorizationCoverageTest`, `IndicatorDirectoryTest`, `Notifications/SubjectLinksTest` green.
- Run `php artisan test --compact tests/Feature/IndicatorLibraryTest.php` (then each file / `--filter=...`).

## Acceptance criteria
- [ ] MEAL manages the whole library from `/indicator-library` in Arabic and English; other roles only view.
- [ ] Every existing indicator is linked to a library entry after the command, or listed in its conflict report; a second run changes nothing.
- [ ] Deleting a used entry is impossible; deactivating hides it from new selection only.
- [ ] Linked `indicators.measure` always equals the library `measure` (test).
- [ ] Every entry has a generated, unique `{program}.{level}.{serial}` code (or `HC.…` for institutional); serials never collide or get reused.
- [ ] The page's query count is flat (test); every new string exists in `lang/en` and `lang/ar`.

## Do not
- Copy library fields into `indicators` as a second source of truth (only `name`, plus the `measure` mirror that achievement needs).
- Hard-delete an entry in use, or cascade any delete from the library.
- Change the achievement definition because of `is_cumulative`.
- Query per row on the page, or write from the component without an Action.
- Bind a Flux modal with `:open`, use `@php(...)` shorthand, or add a new dependency or base folder.

## Quality bar (this task)
- Library page grouped by program with sticky group headers; search over normalized Arabic, English and the code; filters in the header; text badges for missing English name, legacy and inactive; codes shown LTR in a monospace face.
- Entry modal in sections (identity: program, level, names; measurement: measure, unit, denominator basis, labels; collection: frequency, follow-up months, collector, disaggregation; references); fields appear only when relevant (follow-up months only for `follow-up`, denominator basis only for percentages).
- Deactivate and delete go through confirm modals that state the consequence; the generated code is shown after save in the toast.
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
Your final message must contain: a summary; files changed; migrations added; tests run with pass/fail output; the `meal:seed-indicator-library --dry-run` summary on the local database; decisions assumed (D-10, D-11, D-25 and the plan-mode answers: name language in `indicators.name`, seeded bilingual names, reactivate path); follow-ups. Handoff facts frozen at CP1 (end of day 3): the exact `library_indicators` columns and casts, `LibraryIndicator::active()`, `forPrograms()`, `localizedName()`, `CollectionFrequency::period()`, the code generator's class and signature, the measure-mirror rule and its recorded rule file — Task 13 (program-scoped selection list and attach), Task 17 and Task 33 (`collected_by`, `denominator_basis`, `is_disaggregated`, `numerator_label`, frequency copied onto template items), Task 32 (import through the same generator), Task 18 (codes and bilingual names), Task 10 (skips inactive entries) and Task 26 (page hooks) rely on them; and for Task 27 the command's run order and expected output on production data.
````
