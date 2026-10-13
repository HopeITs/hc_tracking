# Task 13 — Proposal indicator checklist and per-center targets grid

> **المهمة:** قائمة المؤشرات بالتحديد في المقترح والمشروع، والمستهدفات لكل مركز
> **المطوّر:** B2 (مراجعة Dev 2) · **الفريق:** B · **الأيام:** 5–8 (الجمعة 2026-10-16 ← الأربعاء 2026-10-21؛ اليوم 5 نصف يوم) · **الجهد:** 3 أيام · **يعتمد على:** Task 11، Task 12، Task 06
> **متى تستخدمه:** بعد إنهاء Task 11 (**Indicator Library**، CP1) ومخطط Task 12 (الإطار المنطقي v2، يُوقَّع في CP2 نهاية اليوم 6) وTask 06 (نموذج المقترح وربط المشاريع، الفريق A)؛ يبدأ نصف يوم في اليوم 5 على مخطط Task 12 المدموج، ويجب أن تكتمل الشبكة قبل نقطة التكامل CP3 (نهاية اليوم 9، 2026-10-22) لأن أسطر التقرير الشهري (Task 17) والتقرير التجميعي (Task 19) تُبنى منها.
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are implementing Task 13 of the HC Tracking proposal-lifecycle correction plan in this Laravel 13 / Livewire 4 / Flux UI 2 repository. The outcome: from one **Indicators & Targets** tab on the **Proposal** page, MEAL ticks indicators from the **Indicator Library**, attaches each to a node of the proposal's own logical framework, then enters one target per linked project (center) in a grid saved atomically. The **Project** page shows the project's indicators from every source and lets MEAL scope program-framework indicators to the project. In the corrected story (draft project without dates -> concept note -> proposal -> donor approval -> one agreement), this is the MEAL side of the proposal: "one donor, one proposal, several centers, same indicators, a target per center". These targets are each **donor's commitment**; Task 33 reads them as suggestions when MEAL builds a project's monthly report template, and Tasks 16, 17, 18 and 19 read them per center and per donor.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins) (Laravel Boost rules, Pest, Pint, `php artisan make:* --no-interaction`, no new dependencies or base folders).
2. The plan `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md`: section `### Task 13` in full. It is Arabic and authoritative; English table, column, permission and UI names in it are exact. Also read `### Task 11` and `### Task 12` (the contracts you build on), section 5 rows "BOLA/IDOR في Livewire", "التزامن" and "الحفظ الجزئي", section 7 rows CP2 and CP3, section 1.0 (project = HR center), section 0 «قواعد العمل بين الفريقين», and `### Task 30` (`EmployeeProfile`, `projects.center_id`).
3. The decision log `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` if it exists. A recorded decision overrides the plan's default for D-23 (who enters targets), D-11 (renewal suggestions), D-24 (multi-program proposals), D-25 (no summing percentages).
4. Rule files: `.ai/rules/resultsindicatorstargetsreports.md`, `.ai/rules/views-components-ui.md`, `.ai/rules/proposals.md`, `.ai/rules/proposalsfunding-agreementstransfersdonors.md`, `.ai/rules/admin.md`, `.ai/rules/pages.md`, `.ai/rules/ui.md`, `.ai/rules/views.md`, `.ai/rules/views-enums.md`, `.ai/rules/views-pages.md`, `.ai/rules/components-ui.md`, `.ai/rules/indicators.md`, `.ai/rules/results.md`, `.ai/rules/models.md`, `.ai/rules/tests.md`, `.ai/rules/seeders.md`. If you add a migration: `.ai/rules/database-migrations.md`, `.ai/rules/migrations.md`, `.ai/rules/feature.md`. Then run `grep -rin 'target\|indicator\|chain' .ai/rules`.
5. Existing code to study first:
   - `resources/views/pages/proposals/⚡show.blade.php`: `TABS` constant (already has a `targets` tab), `tabs()`, `targets()`, `warnings()` (deep links with `'tab' => 'targets'`), `canViewAgreements()`.
   - `resources/views/pages/targets/⚡index.blade.php` `saveTarget()`: percentage cap at 100, "indicator and source locked once reported", `chainIssuesFor()` rejecting only issues an edit introduces, and the duplicate check.
   - `app/Models/PlanIndicator.php` (`achievedValue()`, `percentAchieved()`, `chainIssuesFor()`, `#[Fillable]`), `app/Models/Indicator.php`, `app/Models/Result.php`, `app/Models/Proposal.php`, `app/Enums/Period.php`, `app/Enums/IndicatorMeasure.php`.
   - `resources/views/pages/projects/⚡show.blade.php` (`targets()`, `targetsByResult()`, `x-ui.target-lineage`), `resources/views/pages/indicators/⚡index.blade.php` (`mount()` deep links, load-once pattern).
   - Action shape: `app/Actions/Reports/ApproveReport.php`, `app/Actions/FundingAgreements/SaveFundingAgreement.php` (plain class, `handle()`, `Gate::authorize` inside, input already validated by the component).
   - Tests: `tests/Feature/TargetDirectoryTest.php`, `tests/Feature/ProposalDetailTest.php`, `tests/Feature/IndicatorDirectoryTest.php` (query-count pattern), `tests/Pest.php` helpers.
   - Whatever Tasks 11 and 12 created: `App\Models\LibraryIndicator`, `ResultType::Activity`, `results.proposal_id`, the **Logical Framework** tab.
6. Skills to activate: `livewire-development`, `fluxui-development`, `tailwindcss-development`, `laravel-best-practices`, `laravel-security`, `testing-best-practices` (and `database-migrations` only if a migration is approved).
7. Use Laravel Boost `search-docs` before relying on Livewire/Laravel APIs (validation of nested arrays, `lockForUpdate`, Livewire computed properties). Use `database-schema` on `plan_indicators`, `indicators`, `results`, `library_indicators` before writing any query or migration.

## Preconditions to verify
- `library_indicators` table and `App\Models\LibraryIndicator` exist with `code`, `name_en`, `name_ar`, `measure`, `suggested_level`, `is_active` — Task 11.
- `indicators.library_indicator_id` exists (FK `restrictOnDelete`) — Task 11.
- `App\Enums\ResultType::Activity` and `results.proposal_id` exist, and `ResultObserver` enforces the proposal scope — Task 12 (CP2, end of day 6).
- `proposals.donor_id` exists and proposals link to at least one project through `project_proposal` — Tasks 03 and 06.
- Permissions `targets.create`, `targets.update`, `targets.delete`, `indicators.create`, `indicators.delete` exist in `config/access-control.php` (they do today).
- `projects.center_id` and `App\Support\EmployeeProfile` (centers and cities read from HR) — Task 30 (CP1). If missing, label columns with the project name and report it.
- Optional: `proposals.previous_proposal_id` — Task 10. If missing, skip the prior-year column and report it as a follow-up; do not create the column.
If any required item fails, stop and report instead of improvising.

## Revision 2 changes (2026-10-08)
- Owner is now **B2 (Team B)**, reviewed by Dev 2; days 5–8 (Fri 2026-10-16 ½ → Wed 2026-10-21). The grid must be complete before **CP3 (end of day 9, 2026-10-22)**: Task 17 builds monthly report lines from these targets and Task 19 aggregates them. Task 12's schema is now frozen at CP2 (end of day 6), not CP3.
- Targets per center are entered **during the proposal stage, before approval** (from `concept note` onwards), so the proposal reaches the donor with its full plan and figures. The grid is editable in every non-terminal status; it stays read-only only for `rejected` (and `completed` work).
- A grid column ("center") is a linked project tied to an HR center (`projects.center_id`, Task 30; project = center, D-30). Label each column with the **center name and its city** read through `App\Support\EmployeeProfile` / the HR models (read-only, never copied into tracking tables), falling back to the project name when `center_id` is null.
- Work under the inter-team rules (plan section 0 «قواعد العمل بين الفريقين»): do not change Phase 1 tables (`proposals`, `projects`, `donor_proposal`, `proposal_status_notes`) or the proposal state machine — request any such change from Dev 1 so it is recorded in the plan; integrate with Phase 1 by listening to its events (`ProposalStatusChanged`, `ProjectActivated`, `ProjectPeriodChanged`, `FundingAgreementRecorded`, dispatched after commit) instead of calling Phase 1 Actions directly; every PR is reviewed by Dev 2 (UI) or Dev 1 (backend) within one working day and merges only with this task's suite green; no production access — seed or masked data only.
- `proposals/⚡show` is a Phase 1 page also edited by Dev 2 (Tasks 06 and 07) and B1/B3 (Tasks 12 and 31): agree the tab slot with Dev 2 and keep your change to the `targets` tab.
- Register this task's events (e.g. `proposal.targets_saved` as one digest event per grid save, not one per cell) in the Task 21 event catalog `App\Notifications\EventCatalog` so MEAL and General Management are notified (plan section 1.0, fact 8; `EventCatalogCoverageTest` must stay green). If the catalog is not merged yet, add the keys in your PR and coordinate with Dev 3.

## Revision 3 changes (2026-10-09)
Source: `Stratigy.pptx` (strategy 2026–2030) and `micro logic.xlsx` (the real Micro program logframe, urban and agricultural tracks). Read plan section 1.0 facts 10–12 and the «تعديلات المراجعة 3» subsection of this task. Where this block conflicts with text further down this prompt, this block wins.
- When an indicator instance came from a program reference framework (Task 32, `copied_from_id`), pre-fill its grid cells from the source indicator's `indicators.target` as a **suggestion** (distinct style, saved only when MEAL confirms), exactly like last year's achievement (D-11).
- Default-target meanings (D-37): a value (e.g. 80%) is suggested as-is; "-" means a tracking-only indicator (`indicators.is_tracking_only`, Task 32): it can be selected but gets no `plan_indicators` row and shows a value without achievement %; "**" means set per proposal: the cell is required before `submitted` (warning only during `concept note`); "+20%" is a change-from-baseline target entered as `percentage` with `indicators.baseline`.
- `average` indicators: no 100 cap; show the library unit.
- Show `collected_by` and `frequency` next to each indicator so MEAL sees which ones will appear in the center's monthly report and which MEAL collects itself.

## Revision 4 changes (2026-10-12)
Source: plan header paragraph «المراجعة 4», section 1.0 facts 13–14, D-38…D-41, and the «تعديلات المراجعة 4» subsection of this task. Where this block conflicts with the Revision 2/3 blocks or the base sections, this block wins.
- **Proposal targets = donor commitment; the monthly report target is MEAL's, set in Task 33.** This grid stays the source of `plan_indicators` per donor and center. Task 33 reads it as a suggestion and stores its own approved target on the template item; it never overwrites `plan_indicators.target`, and nothing in this task writes template data.
- **Same indicator, two donors, two different targets:** stays two `plan_indicators` rows (each donor's commitment); Task 33 merges them into one template item (D-40, adjusted by D-46 in Revision 5).
- **Library link is a precondition for the monthly report:** an instance without `library_indicator_id` (legacy data) can never enter a template. Show a «غير مرتبط بالمكتبة» / "Not linked to the library" badge on such rows, with a link to fix it for MEAL.

## Revision 5 changes (2026-10-12)
Source: management note on co-funded indicators, recorded as **D-46** (amends D-40). Read plan section 1.0 facts 14 and 18, decision row D-46, and the «تعديلات المراجعة 4» and «تعديلات المراجعة 5» subsections of this task in the plan. Where this block conflicts with text further down this prompt, this block wins.
- An indicator shared by several donors in one center follows its program's mode, `programs.donor_attribution` (`App\Enums\DonorAttribution`: `shared`, `split`; default `shared`). `shared` (e.g. DESC: 30 students, one report, 30 for each donor) = D-40. `split` (e.g. Micro: 10 beneficiaries = 6 for donor 1 + 4 for donor 2, a separate report per donor): the center manager enters each donor's share monthly, each donor's mirror carries only its share, and the center's achievement is the sum. A beneficiary has exactly one funder, so there is no separate total field and no reconciliation check.
- The grid itself does not change: each donor's `plan_indicators` row is that donor's commitment and is what identifies the donor's share later (Task 17).
- In a `split` program, show a "center total" next to an indicator repeated across donors = the sum of their targets; in `shared` show the highest. Test: targets 60 and 40 → 100 in `split`, 60 in `shared`.

## Revisions 6–8 changes (2026-10-12)
Source: decision-workshop answers (R6 file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.docx`) and two real M&E workbooks (`Syria Cross e.V._ME_Tool_IPTWP_V1_2026_Aug.xlsx`, `Children University - IPTT - 2026.xlsx`). Read the plan header paragraphs «المراجعة 6/7/8», decision rows D-10, D-23, D-50, and the «تعديلات المراجعة 6, 7 and 8» subsections of this task in the plan. Open questions still running on their documented default are listed in `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r7.ar.docx`. Where this block conflicts with text further down this prompt, this block wins.
- Targets and selection are **MEAL only** (D-23): GM, Managers and program managers see the grid read-only; buttons hidden **and** the policy returns 403 (test).
- ~~D-10: the code column is required when adding an indicator~~ — superseded by Revision 9 below (the code is copied from the library, read-only).
- D-50a (default): the target stays a single total; no per-gender targets. Achievement is shown disaggregated next to it.
- Target basis (Children University «الهدف» vs «الهدف رقميا»): new `plan_indicators.target_population` (decimal, nullable). For a percentage target the grid shows the derived number (80% × 180 = 144) for display only.

## Revision 9 changes (2026-10-12)
Source: the corrected R6 answers file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.fixed.docx`, which is newer than the R7 questions file and replaces the defaults Revision 8 applied. Read the plan header paragraph «المراجعة 9», the decision rows in section 9 (and «الأسئلة المتبقية بعد المراجعة 9»), and the «تعديلات المراجعة 9» subsection of this task. **Where this block conflicts with any text further down, including the Revisions 6–8 block, this block wins.**
- **D-10a:** the picker lists only library entries of the programs of the proposal's projects plus institutional ones, sorted by usage in that program's projects; separate headings for a two-program proposal (D-24). MEAL can create a missing indicator in the program library from the same screen (**Add to Program Library**) and it gets the next code. Server check: the `library_indicator_id` must belong to one of those programs or be institutional — «هذا المؤشر لا يتبع برنامج هذا المقترح».
- The code column is read-only (copied from the library). Drop the manual code entry and its duplicate message from Revision 6.
- **D-25:** beside each percentage target per center show the project's beneficiaries from the proposal and the figure («80% × 180 = 144»); `plan_indicators.target_population` defaults from `project_proposal.target_beneficiaries` and MEAL may edit it. Badge «أدخل عدد المستفيدين في المقترح» when missing.
- Tests: other program's indicator rejected; institutional accepted; code taken from library not from the request; `target_population` default.

## Scope
### In scope
- **Indicators & Targets** tab on `resources/views/pages/proposals/⚡show.blade.php`. Recommended: keep the existing tab key `targets` (deep links and `warnings()` use it) and change its label and content; confirm in plan mode.
  1. Library checklist (Revision 9): active library entries of the programs of the proposal's projects plus institutional entries (`LibraryIndicator::forPrograms()`), one heading per program, ordered by usage in that program's projects, then grouped by `suggested_level`; searchable in Arabic and English (search the code too); an **Add to Program Library** shortcut for MEAL.
  2. Ticking an entry asks for a framework node from this proposal's framework at a compatible level, then creates an `indicators` row (instance) linked to that result and to the library entry.
  3. Targets grid: rows = indicator instances in the proposal framework, columns = linked projects; each cell holds a value and a period and maps to one `plan_indicators` row (`source_type = 'proposal'`, `source_id = proposal id`, `project_id = center`, `target = value`).
  4. Beside each cell: last year's achievement when the proposal is a renewal (Task 10), read through `achievedValue()`.
- **Project** page (`resources/views/pages/projects/⚡show.blade.php`): a read-only list of the project's indicators from its proposals and from its program framework, plus a MEAL action to scope a program-framework indicator to the project (`indicators.project_id`). Recommended: extend the existing `targets` tab; confirm in plan mode.
- Create `app/Actions/Indicators/AttachLibraryIndicatorToResult.php`, `app/Actions/Indicators/DetachIndicatorFromResult.php`, `app/Actions/Targets/SaveProposalTargetsGrid.php`.
- Create the grid component `resources/views/components/ui/targets-grid.blade.php` (Blade + Alpine keyboard navigation).
- Column headers show the HR center name and city (Task 30), falling back to the project name.
- One digest event per grid save registered in the Task 21 `EventCatalog` (MEAL + General Management).
- Lang keys in `lang/en/targets.php` + `lang/ar/targets.php` (and `proposals.php` / `projects.php` for tab labels).
- Open question, not a default: the plan relies on "the existing unique constraint" on `plan_indicators`, but the schema has only an index on `(source_type, source_id)`; duplicates are blocked in application code only (`targets/⚡index` `saveTarget()`). Propose a migration adding a unique index on `(source_type, source_id, indicator_id, project_id)` with a duplicate pre-check that aborts and prints offending ids. Add it only after the developer approves.
### Out of scope
Library CRUD and seeding (Task 11); framework editing, `ResultType::Activity`, milestone links (Task 12); renewal cloning and prior-year suggestion source (Task 10); full chain view (Task 16); monthly report lines (Task 17); **Monthly Matrix** and cross-donor aggregation (Task 18); guided tours and user guide (Task 26).

## Business rules
- Unticking (detach) deletes the indicator instance only when it has no `plan_indicators`; otherwise reject with a message stating the target count (`trans_choice`, Arabic plural ranges).
- Empty cell = no target for that center. Cell with `0` = a target of zero (allowed).
- Every cell passes `PlanIndicator::chainIssuesFor()` (project funded by the proposal; indicator in a consistent framework). Reject only issues the save introduces, as `saveTarget()` does today.
- No duplicate target for the same source + indicator + project.
- Once a target has reports, its indicator and source are locked; value and period stay editable.
- Percentage indicators: value <= 100; `average` indicators have no cap (Revision 3).
- The library entry must belong to one of the programs of the proposal's projects or be institutional (Revision 9); the instance's code is copied from the library.
- The same target for two donors is entered separately per proposal; aggregation is Task 18.
- Rejected proposal (`ProposalStatus::Rejected`): grid and checklist are read-only.
- Deactivated library entry: stays on proposals that use it, never offered for new ticks.
- Level compatibility: the chosen result's `type` equals the library entry's `suggested_level` when set; any level when it is null.
- Clearing a cell that already has a saved target deletes that target only if it has no reports and the user holds `targets.delete`; otherwise the batch is rejected. (Inferred from "empty = no target"; confirm in plan mode.)

## Security and authorization
- Attach: `indicators.create`. Detach: `indicators.delete` (confirm in plan mode; Managers hold create but not delete). Grid: `targets.create` for new cells, `targets.update` for changed cells, `targets.delete` for cleared cells. `proposals.update` alone grants nothing here (D-23). Other viewers get the read-only tab.
- Each Action calls `Gate::authorize` itself; the component re-checks for UI only.
- BOLA: load the result through the proposal scope (`Result::query()->where('proposal_id', $proposal->id)->whereKey($resultId)`), projects through `$proposal->projects()->whereKey($ids)`, indicators through results of this proposal's framework, existing targets through `source_type = 'proposal'` + `source_id`. Any id outside these sets rejects the whole batch; never trust ids or versions held in Livewire state.
- `libraryIndicatorId` must exist and be active.
- No money in this tab: do not load agreements, budgets or transfers.
- Audit: `Indicator` and `PlanIndicator` already implement `TracksActivity`. A grid save of many cells records one activity per row; decide in plan mode whether that notification volume is acceptable or whether the batch should be quiet (`Activity::withoutRecording`) with one summary event. Default: keep per-row activity.

## Validation
Messages in `lang/en/targets.php` and `lang/ar/targets.php`, grouped per module, never inline text.
- `cells`: array. `cells.*.indicatorId`, `cells.*.projectId`: required, integer (membership checked in the Action).
- `cells.*.value`: nullable, numeric, min:0, max:9999999999.99 (`decimal(12,2)`), and max:100 for percentage indicators — "القيمة لا تكون سالبة" / "Value cannot be negative"; "النسبة لا تتجاوز 100" / "A percentage cannot exceed 100".
- `cells.*.period`: required_with:value, `Rule::enum(Period::class)`.
- `cells.*.version`: nullable string (the target's `updated_at` when loaded).
- `resultId` on attach: required, in this proposal's framework, compatible level — "اختر نتيجة من إطار هذا المقترح بمستوى متوافق" / English equivalent.
- `libraryIndicatorId`: required, active library entry.
- Stale cell: "عُدّلت هذه القيمة من مستخدم آخر" / "Another user changed this value; reload".

## Edge cases and concurrency
- Two users saving the grid: compare each loaded target's `updated_at` (version) with the stored one inside the transaction after `lockForUpdate`. The plan says "reject that row"; section 5 says "reject the whole batch". Default: reject the whole batch and flag the stale cells (no partial save); confirm in plan mode.
- Double submit: the second save finds identical values and writes nothing new; no duplicate rows.
- Attaching the same library entry twice under one result: `indicators` has a unique `(result_id, name)`; treat as "already attached", not a 500.
- Library `frequency` uses `CollectionFrequency` (Task 11: `monthly`, `quarterly`, `semi-annual`, `annual`, `per-event`, `per-season`, `follow-up`, `baseline-endline`, `one-time`) while `indicators.frequency` uses `Period`; use `CollectionFrequency::period()` and fall back to the column default for the non-periodic cases. State the mapping in plan mode.
- Project unlinked after targets were entered: rows stay and are flagged inconsistent (existing behaviour), never deleted.
- Permission removed mid-session: the Action re-checks and returns 403.
- Inactive HR owner of a center: irrelevant to targets; do not block.

## Data integrity
- `SaveProposalTargetsGrid` runs in one `DB::transaction`: lock the proposal row, validate every cell, then upsert. Any failing cell rejects the batch and returns the list of offending cells; nothing is written.
- Upsert per cell by (source, indicator, project) through Eloquent (`firstOrNew` + `save`) so `RecordsActivity` and observers fire; never `DB::table()->upsert()`.
- `plan_indicators.indicator_id` cascades and `project_id` is `nullOnDelete` (existing); detach deletes the instance through Eloquent only.
- If the unique index migration is approved: pre-check duplicates before DDL, `utf8mb4_unicode_ci`/InnoDB DYNAMIC, a working `down()`.

## Implementation guidance
- `declare(strict_types=1);`, typed signatures, PHPDoc array shapes. Actions are plain classes with `handle()` like their siblings. Skeleton:
```php
class SaveProposalTargetsGrid
{
    /**
     * @param  list<array{indicatorId: int, projectId: int, value: ?string, period: ?string, version: ?string}>  $cells
     * @return array{created: int, updated: int, deleted: int}
     *
     * @throws ValidationException naming every offending cell; nothing is written
     */
    public function handle(Proposal $proposal, array $cells): array {}
}
```
- Show achievement only through `achievedValue()` / `percentAchieved()` with `reports` and `indicator` eager loaded; never a local formula.
- Load the grid in a fixed number of queries (proposal, projects, framework results + indicators + library entries, targets with reports, prior-year targets), then shape it in PHP. Call one `forget…()` helper that unsets every affected computed after a write.
- Flux modals bound with `wire:model` to a boolean, never `:open`. Reuse `x-ui.*` components (`x-ui.panel`, `x-ui.empty-state`, `x-ui.button`, `x-ui.enum-badge`). Library codes render LTR-isolated; names use `dir="auto"`; show the name in the user's locale with the other language as a secondary line.
- Grid: logical spacing (`ps-*`/`pe-*`), arrow-key and Enter navigation in Alpine, labelled inputs, status not conveyed by colour alone, `wire:model` deferred until Save.
- No `@php(...)` shorthand in Blade.

## Tests (Pest)
Follow `.ai/rules/tests.md`: seed `RolesAndPermissionsSeeder`, use `User::factory()->role(...)` or `userWithRole()`, and a fresh Livewire instance for each forbidden call.
- `tests/Feature/ProposalIndicatorSelectionTest.php`: attach creates an instance under the chosen node with the right `library_indicator_id`; incompatible level rejected; inactive entry hidden and rejected; result from another proposal's framework rejected (BOLA); detach with targets rejected with the count; detach without targets succeeds; 403 without `indicators.create`.
- `tests/Feature/ProposalTargetsGridTest.php`: saves one target per center; empty cell creates no row; `0` creates a row; cell for an unlinked project rejects the whole batch and writes nothing; indicator outside the framework rejects the batch; percentage > 100 rejected; stale version rejected; double submit creates no duplicates; rejected proposal is read-only; a `concept note` proposal accepts targets (Revision 2); column headers show the HR center name and city; user without `targets.create` sees the grid read-only and gets 403 on save; query count is equal for 3 and 30 cells.
- `tests/Feature/ProjectIndicatorsTest.php`: the project page lists indicators from its proposals and its program framework; MEAL scopes a program indicator to the project; a project outside the result's program is rejected.
- Arabic render of the tab (labels from `lang/ar`), and `tests/Feature/LocalizationTest.php` stays green.
Run `php artisan test --compact tests/Feature/ProposalTargetsGridTest.php` (and each file / `--filter=...`), plus `tests/Feature/TargetDirectoryTest.php`, `tests/Feature/ProposalDetailTest.php`, `tests/Feature/ProjectDetailTest.php`.

## Acceptance criteria
- [ ] MEAL selects indicators from the library and enters targets for every center of a proposal from one screen.
- [ ] A grid save is atomic: any invalid or foreign cell leaves the database unchanged.
- [ ] Holders of `proposals.update` without `targets.*` cannot change targets (403 test).
- [ ] The **Project** page shows its indicators from proposals and from the program framework.
- [ ] Achievement figures come only from `achievedValue()` / `percentAchieved()`.
- [ ] Targets can be entered before approval (from `concept note`), and the grid is complete before CP3 (end of day 9).
- [ ] Query count of the tab is flat (test).
- [ ] Every new string exists in `lang/en` and `lang/ar`; the tab renders correctly in RTL.

## Do not
- Compute achievement with a local formula in the grid.
- Save the grid partially.
- Let any `proposals.update` holder edit targets.
- Copy library fields into `indicators` as a second source of truth (only `name` is filled, per Task 11).
- Write targets through the query builder (bypasses activity and observers).
- Bind a Flux modal with `:open`, or use `@php(...)` shorthand.
- Add the unique index, a new dependency or a new base folder without approval.

## Quality bar (this task)
- Checklist grouped by program then level, searchable; attaching asks for the node in a small popover listing compatible nodes only.
- Targets grid: sticky indicator column and center headers (center + city), keyboard navigation, suggestions (last year, framework default) styled distinctly with one-click accept, the `80% × 180 = 144` helper, text badges (not linked to the library, missing beneficiaries), and a batch save that lists offending cells.
- Non-MEAL viewers see the grid read-only with one line saying MEAL enters targets.
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
Your final message must contain: a summary; files changed; migrations added (if any); tests run with pass/fail output; decisions assumed (D-11, D-23, D-24, D-25 and the open questions above); follow-ups (e.g. prior-year column waiting on Task 10). Handoff facts: for Task 16 (chain view) and Task 18 (**Monthly Matrix**), where per-center targets live (`plan_indicators`, `source_type = 'proposal'`, `project_id` = center) and whether the unique index exists; for Task 33 (template drafting) how a project's targets in a proposal are listed with their `library_indicator_id`, `target_population` and donor; for Task 17 (monthly report lines) and Task 19 (aggregated donor reports), how targets per project and period are read; the exact Action names and `handle()` signatures.
````
