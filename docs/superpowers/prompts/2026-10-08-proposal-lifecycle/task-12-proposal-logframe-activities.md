# Task 12 — Logical framework v2: Activity level, proposal framework, link to the program

> **المهمة:** الإطار المنطقي v2: مستوى النشاط، وإطار المقترح، وربطه بالبرنامج
> **المطوّر:** B1 (مراجعة Dev 1) · **الفريق:** B · **الأيام:** 3–6 (الأربعاء 2026-10-14 ← الاثنين 2026-10-19) · **الجهد:** 3.5 يوم · **يعتمد على:** Task 03، Task 04، Task 11
> **متى تستخدمه:** التصميم في اليوم 3 (نصف يوم)، والترحيلات من اليوم 4 بعد توقيع CP1 (نهاية اليوم 3: مخطط `proposals` وقيم `ProposalStatus` ومخطط `library_indicators` من Task 11)؛ ويجب أن يُسلَّم مخططه ويُوقَّع في نقطة التكامل CP2 (نهاية اليوم 6، 2026-10-19) لأن Task 13 وTask 16 وTask 10 وTask 31 تبني فوق أعمدته.
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are implementing Task 12 of the HC Tracking proposal-lifecycle correction plan in this Laravel 13 / PHP 8.5 / Livewire 4 / Flux UI 2 repository. The outcome: the results chain gains a fourth level, `ResultType::Activity` (Impact -> Outcome -> Output -> Activity, nothing below an activity); every proposal can own its own logical framework in `results` (`results.proposal_id`), whose root contributes to a result of the program framework (`results.contributes_to_result_id`); project milestones link to a framework activity (`milestones.result_id`); and `results.copied_from_id` exists for renewal cloning. MEAL edits the proposal framework from a **Logical Framework** tab on the **Proposal** page, `/results` gets a proposal filter and the activity level, and the milestone form gets an "Activity in framework" field. In the corrected story the proposal (one donor, several centers) carries the framework that "achieves the strategy" through its program and is delivered through milestones; Task 13 attaches library indicators and per-center targets to these nodes, Task 16 renders the full chain, and Task 10 clones it.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins).
2. The plan `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md`: section `### Task 12` in full. It is Arabic and authoritative; English table, column, enum, permission and UI names in it are exact (Impact = الأثر، Outcome = النتيجة، Output = المُخرَج، Activity = النشاط). Also read section 1.2, `### Task 13` and `### Task 16` (your consumers), section 5 rows "BOLA/IDOR في Livewire" and "الصلاحيات", section 7 rows CP1 and CP2, section 0 «قواعد العمل بين الفريقين», `### Task 31` (proposal milestones), and section 9 rows D-24 and D-34.
3. The decision log `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` if it exists; a recorded decision overrides the plan's default for D-24 (multi-program proposal framework) and D-01 (who approves).
4. Rule files: `.ai/rules/database-migrations.md`, `.ai/rules/migrations.md`, `.ai/rules/models.md`, `.ai/rules/providers.md`, `.ai/rules/views-pages.md`, `.ai/rules/enums.md`, `.ai/rules/views-enums.md`, `.ai/rules/observers.md` (tree integrity lives in observers, throw `InvalidParentException`), `.ai/rules/concerns.md` (`HasTree`), `.ai/rules/results.md` (load once, `forgetFramework()`), `.ai/rules/views-components-ui.md` (chain enforced in forms; "nothing under an output" — this task changes it), `.ai/rules/components-ui.md` (ResultType mark/accent mapping, `framework-node` takes prebuilt arrays), `.ai/rules/resultsindicatorstargetsreports.md`, `.ai/rules/indicators.md`, `.ai/rules/proposals.md`, `.ai/rules/proposalsfunding-agreementstransfersdonors.md`, `.ai/rules/admin.md`, `.ai/rules/milestones.md`, `.ai/rules/milestonestasks.md`, `.ai/rules/pages.md`, `.ai/rules/ui.md`, `.ai/rules/views.md`, `.ai/rules/factories.md`, `.ai/rules/seeders.md`, `.ai/rules/tests.md`. Then run `grep -rin 'result\|logframe\|output\|milestone' .ai/rules`.
5. Existing code to study first:
   - `app/Enums/ResultType.php`, `app/Models/Result.php` (`HasTree`, `#[Fillable]`, `activityEvents()`), `app/Models/Milestone.php`, `app/Models/Proposal.php` (`delete()` is transactional; `PlanIndicatorSourceObserver`), `app/Models/Program.php::results()`, `app/Concerns/HasTree.php`.
   - `app/Observers/ResultObserver.php` (cycle guard only today), `app/Observers/MilestoneObserver.php`, `app/Exceptions/InvalidParentException.php` (`cycle()`, `crossProject()`).
   - `resources/views/pages/results/⚡index.blade.php`: `allResults()`, `buildNode()`, `matchesFilters()`, `parentLevelOf()` (has a `default` arm), `parentOptions()` (no scope filter today), `openCreateModal()` ("an output is the bottom of the chain"), `saveResult()` (level rule, parent's program, subtree program move, `programChangeBreaksChain()`), `canDelete()`, `forgetFramework()`.
   - Every `ResultType` consumer: `resources/views/components/ui/{logframe,logframe-node,logframe-branch,framework-node,target-lineage,plan-years,enum-badge}.blade.php`, `resources/views/pages/programs/⚡show.blade.php` (`resultRoots()` reads every result of the program), `resources/views/pages/indicators/⚡index.blade.php` (type-keyed maps ~line 1123), `resources/views/pages/projects/⚡show.blade.php` (`$resultOrder` ~line 694), `resources/views/pages/annual-plans/⚡show.blade.php`, `lang/{en,ar}/enums.php` (`result_type`).
   - `resources/views/pages/proposals/⚡show.blade.php` (`TABS`, `tabs()`, `mount()` tab guard, `targets()`), `resources/views/pages/proposals/⚡index.blade.php` (`confirmDelete()`, `canDelete()`, `deleteProposal()` — there is no `DeleteProposal` Action yet).
   - Milestone forms: `resources/views/pages/milestones/⚡show.blade.php` (`updateMilestone()`, `saveRelatedMilestone()`), `resources/views/pages/milestones/⚡index.blade.php` (`saveMilestone()`), `resources/views/pages/calendar/⚡index.blade.php` (`saveMilestone()`), `app/Policies/MilestonePolicy.php` (`update` vs `updateProgress`).
   - Tests: `tests/Feature/ResultsFrameworkTest.php`, `tests/Feature/ResultTest.php`, `tests/Feature/Models/ResultTest.php`, `tests/Feature/Models/TreeIntegrityTest.php`, `tests/Feature/AccessControl/ResultsFrameworkAuthorizationTest.php`, `tests/Feature/MilestoneShowTest.php`, `tests/Feature/ProposalRegisterTest.php`, `tests/Feature/Models/TransactionalDeleteTest.php`, `tests/Feature/ProgramDetailTest.php`, `tests/Feature/IndicatorDirectoryTest.php` (query-count pattern), `tests/Pest.php` (`insertResult()`).
6. Skills to activate: `laravel-best-practices`, `database-migrations`, `livewire-development`, `fluxui-development`, `tailwindcss-development`, `laravel-security`, `testing-best-practices`.
7. Use Laravel Boost `search-docs` before relying on framework APIs (self-referencing `foreignId()->constrained()`, `#[Scope]`, eager-load constraints, Livewire `#[Url]`/`#[Computed]`). Use `database-schema` on `results`, `milestones`, `indicators`, `plan_indicators`, `proposals` before writing migrations.

## Preconditions to verify
- `proposals.donor_id` exists and `ProposalStatus` holds the v2 values with `App\Actions\Proposals\TransitionProposal` — Tasks 03 and 04 (CP1).
- `library_indicators` and `indicators.library_indicator_id` exist — Task 11.
- `results` has no `proposal_id`, `contributes_to_result_id`, `copied_from_id`; `milestones` has no `result_id`; `ResultType` has three cases.
- Optional: `ProposalPolicy::view` admits reviewers (Task 07). If absent, the tab follows `proposals.view` only; note it as a follow-up.
If a required check fails, stop and report instead of improvising.

## Revision 2 changes (2026-10-08)
- Owner is now **B1 (Team B)**, reviewed by Dev 1; days 3–6 (Wed 2026-10-14 → Mon 2026-10-19): design on day 3 (½), migrations and observers on day 4, the tab on day 5, the **Results Framework** page on day 6. The schema (`ResultType::Activity`, `results.proposal_id`, `contributes_to_result_id`, `copied_from_id`, `milestones.result_id`) freezes at **CP2, end of day 6** (was CP3).
- The proposal framework (Outcomes → Outputs → Activities and their indicators) is editable **from `concept note` onwards** (the proposal is the planning container from its first draft, plan section 1.0 fact 4). After `submitted`, structural edits (add, move, delete, change type) are locked except for holders of `results.update`; enforce this in `SaveResult`/`DeleteResult` from the proposal's status, never from Livewire state.
- `milestones.result_id` must also accept **proposal-level milestones** added by Task 31 (`milestones.proposal_id`, `project_id` nullable): the activity must belong to that proposal's framework. Coordinate the column order with B3 (Task 31 migrates `milestones` on day 4) so the two migrations do not conflict.
- Work under the inter-team rules (plan section 0 «قواعد العمل بين الفريقين»): do not change Phase 1 tables (`proposals`, `projects`, `donor_proposal`, `proposal_status_notes`) or the proposal state machine — request any such change from Dev 1 so it is recorded in the plan; integrate with Phase 1 by listening to its events (`ProposalStatusChanged`, `ProjectActivated`, `ProjectPeriodChanged`, `FundingAgreementRecorded`, dispatched after commit) instead of calling Phase 1 Actions directly; every PR is reviewed by Dev 1 (backend) or Dev 2 (UI) within one working day and merges only with this task's suite green; no production access — seed or masked data only.
- `results` and `milestones` are not Phase 1 tables, but `DeleteProposal` and the new tab on `proposals/⚡show` touch Phase 1 code owned by Team A: agree the `DeleteProposal` shape with Dev 1 (Task 04/08 may already have created one — reuse it) and the tab slot with Dev 2 (Task 06/07 edit the same page). Any column you need on `proposals` is requested from Dev 1.
- Register this task's events (`result.*` framework edits on proposals, digest importance) in the Task 21 event catalog `App\Notifications\EventCatalog` so MEAL and General Management are notified (plan section 1.0, fact 8; `EventCatalogCoverageTest` must stay green). If the catalog is not merged yet, add the keys in your PR and coordinate with Dev 3.

## Revision 3 changes (2026-10-09)
Source: `Stratigy.pptx` (strategy 2026–2030) and `micro logic.xlsx` (the real Micro program logframe, urban and agricultural tracks). Read plan section 1.0 facts 10–12 and the «تعديلات المراجعة 3» subsection of this task. Where this block conflicts with text further down this prompt, this block wins.
- Add `ResultType::CrossCutting` (`'cross-cutting'`): root only (no parent, no children), carries indicators (beneficiary satisfaction, complaints closed on time, perceived livelihood impact). Allowed in program and proposal frameworks; `contributes_to_result_id` does not apply. `ResultObserver` rejects a parent or child for it: «المستوى العابر لا يندرج تحت نتيجة ولا تندرج تحته نتائج».
- Update every array keyed by `ResultType` (README note 10) for both new cases, `activity` and `cross-cutting`, in the same change. **Results Framework** shows cross-cutting results in a separate group below the tree.
- Activity indicators are **optional**: in the reference logframe activities are procedural steps under an output (registration form → field visits → committee → contracts → procurement → opening) and are what milestones link to (Task 31).
- A program may have several Impact roots (Micro: urban and agricultural tracks). No new column — the root is the track; Task 32 copies a chosen root into a proposal.
- `cross-cutting` is part of the CP2 contract. Task 14 moves from day 7 to day 12 for B1 to absorb the extra half day.
- Extra tests: cross-cutting root saves; cross-cutting under an impact, or a child under cross-cutting, is rejected; pages keyed by `ResultType` render a cross-cutting node without error.

## Revisions 6–8 changes (2026-10-12)
Source: decision-workshop answers (R6 file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.docx`) and two real M&E workbooks (`Syria Cross e.V._ME_Tool_IPTWP_V1_2026_Aug.xlsx`, `Children University - IPTT - 2026.xlsx`). Read the plan header paragraphs «المراجعة 6/7/8», decision rows D-34, D-49, D-51, and the «تعديلات المراجعة 6, 7 and 8» subsections of this task in the plan. Open questions still running on their documented default are listed in `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r7.ar.docx`. Where this block conflicts with text further down this prompt, this block wins.
- **Activity calendar (D-49, plan Revision 7):** new table `activity_schedules`: `id`, `result_id` (activity, FK `cascadeOnDelete`), `proposal_id` (FK `cascadeOnDelete`), `project_id` (nullable FK `cascadeOnDelete`: NULL = every project of the proposal, a value = a per-project override that wins over the general row), `month` (date, first day of month), timestamps; unique (`result_id`, `proposal_id`, `project_id`, `month`). In the proposal's **Logical Framework** tab, an "activity × months" grid (like the Simplified Gantt Chart template): columns are the months of the proposal's planned period, a cell toggles a month. Months outside `planned_date_begin`…`planned_date_end` are refused — «الشهر خارج مدة المقترح». An activity with no months never appears in the monthly report and shows a «بلا رزنامة» badge. At approval the months shift by the actual-minus-planned start (same rule as Task 31's rebase, D-34) and the shift is logged; trigger it from `ProposalStatusChanged(to: approved)` after commit, idempotent per approval. Tests: month outside the period refused; per-project row wins over the general one; shift at approval; user without framework edit rights gets 403. Ownership: B1 for the table and the shift, B2 for the grid UI.
- Activity calendar `activity_schedules` stays at **month** resolution (D-49b default; Revision 9 adds weeks). Edited by the logframe editor (MEAL) and the proposal editor before approval (D-49c default); one calendar per proposal with per-project overrides (D-49a default). Shifted once at approval by the actual-vs-planned start difference (D-34).
- **D-51:** new `results.activity_kind` (`App\Enums\ActivityKind`: `continuous` — sessions/workshops; `event` — pre/post test, survey, parents' meeting), required only for `activity`, default `continuous`. A continuous activity gets a planned start and end month (all months between are scheduled); an event gets one or more separate months.
- Drop `activity_schedules.source = rescheduled`: unfinished activities now carry over automatically (Task 17), so no reschedule rows are written.
- Children University places calendar activities under outcomes; the plan keeps activities under outputs — MEAL creates them under the matching output on import.

## Revision 9 changes (2026-10-12)
Source: the corrected R6 answers file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.fixed.docx`, which is newer than the R7 questions file and replaces the defaults Revision 8 applied. Read the plan header paragraph «المراجعة 9», the decision rows in section 9 (and «الأسئلة المتبقية بعد المراجعة 9»), and the «تعديلات المراجعة 9» subsection of this task. **Where this block conflicts with any text further down, including the Revisions 6–8 block, this block wins.**
- **D-49b (month/week):** `activity_schedules.weeks` (JSON nullable, week numbers 1–5 of the month, weeks start Monday; null = whole month). Uniqueness stays per month. Grid: month cell toggles as before, a second click opens week selection. Approval shift moves months and keeps week numbers. Actual dates are entered in the monthly report (Task 17).
- **D-49a, D-49c confirmed:** one calendar per proposal with per-project override; before approval MEAL and the proposal editor edit it; after approval unfinished activities carry over automatically (D-51) and only MEAL edits planned months/weeks (logged).
- Validation «رقم الأسبوع بين 1 و5»; weeks only for scheduled months. Tests: week 6 rejected; weeks for an unscheduled month rejected; shift keeps weeks; proposal editor after approval 403. Effort +0.5 day (B2).

## Scope
### In scope
- `ResultType::Activity = 'activity'` (last case) and labels in `lang/{en,ar}/enums.php` (Activity = النشاط).
- Create migration `add_proposal_framework_columns_to_results_table`: `proposal_id` (nullable FK `proposals`, `restrictOnDelete`), `contributes_to_result_id` (nullable self FK, `nullOnDelete`), `copied_from_id` (nullable self FK, `nullOnDelete`; for Task 10).
- Create migration `add_result_id_to_milestones_table`: nullable FK `results`, `nullOnDelete`.
- Models: `Result` fillable + relations `proposal()`, `contributesTo()`, `contributions()`, `copiedFrom()`, `milestones()`; `#[Scope]` `programFramework()` (`proposal_id IS NULL`) and `proposalFramework()`; `Milestone` fillable `result_id` + `activity()`; `Proposal::results()`; activity events for the new fields.
- `ResultObserver` (scope and level rules) and `MilestoneObserver` (activity consistency), with new `InvalidParentException` named constructors.
- Create `app/Actions/Results/SaveResult.php` (extract the rules from `results/⚡index::saveResult()`; both `/results` and the proposal tab call it), `app/Actions/Results/DeleteResult.php` (optional, if it removes duplication), `app/Actions/Milestones/LinkMilestoneToActivity.php`, `app/Actions/Proposals/DeleteProposal.php` (create it; move `proposals/⚡index::deleteProposal()` onto it).
- `/results`: `#[Url] proposal` filter applied in memory, the activity level in the type filter and level cards, parent options restricted to the same framework, grouping of proposal frameworks (recommended: one group per proposal, labelled with its name; confirm in plan mode), `forgetFramework()` after every write.
- **Logical Framework** tab on `proposals/⚡show` (new key `logframe` in `TABS`): the proposal's tree (reuse `framework-node` node arrays or `x-ui.logframe`), create/edit/delete for holders of `results.*`, the "contributes to" picker on roots, a "Multi-program" badge when `program_id` is NULL (D-24). Read-only for everyone else who can open the page. Task 16 later extends what each node shows.
- "Activity in framework" field on the milestone edit form (`milestones/⚡show`); propose in plan mode whether the create forms (`milestones/⚡index`, related milestone, calendar) also get it.
- Structural lock after `submitted` (Revision 2): proposal-framework nodes are created, moved, retyped or deleted after the proposal reaches `submitted` only by holders of `results.update`; text edits follow the normal rules.
- `LinkMilestoneToActivity` and `MilestoneObserver` accept proposal-level milestones (Task 31): activity from the same proposal's framework.
- Every `ResultType` consumer gets an explicit Activity arm (no `default` added to silence it); the program page and any "program framework" read use `programFramework()`.
- Record the changed rule with `record-rule` in `.ai/rules/views-components-ui.md` ("an activity sits under an output; nothing under an activity; a child shares its parent's proposal and program") and the Activity mark/accent colours in `.ai/rules/components-ui.md`.
- Lang keys in `lang/{en,ar}/results_framework.php`, `proposals.php` (tab), `milestones.php` (field), `notifications.php` (`events.result.*`, `events.milestone.*` if new events).
- Revisions 3 and 6–9 (see the blocks above): `ResultType::CrossCutting`; `results.activity_kind` with `App\Enums\ActivityKind`; migration `create_activity_schedules_table` (with the Revision 9 `weeks` JSON column); the activity × months grid with week selection in the **Logical Framework** tab; the approval-time shift listener; the post-approval edit rule (MEAL only, logged).
### Out of scope
Selecting library indicators and per-center targets (Task 13); the full chain view with targets, achievement and milestone progress (Task 16); cloning frameworks (Task 10 uses `copied_from_id`); the "program without framework" alert (Task 14); annual plan activities (Task 24); tours (Task 26).

## Business rules
- The parent is exactly one level up: activity under output, output under outcome, outcome under impact; impact has no parent; nothing under an activity.
- One scope per tree: a child carries its parent's `proposal_id` and its parent's `program_id` (the existing "child takes its parent's program" rule, extended).
- Proposal framework `program_id` = the program of the proposal's linked projects when they share one program, otherwise NULL and shown as "Multi-program" (D-24). `SaveResult` derives it for proposal roots; never take it from input.
- `contributes_to_result_id` is allowed only on a root of a proposal framework (`parent_id` NULL, `proposal_id` set), pointing to a result with `proposal_id IS NULL` whose `program_id` is one of the programs of the proposal's projects. Raise in plan mode whether a level restriction applies (recommended: none).
- `milestones.result_id` must reference an `activity` that is either in the framework of a proposal funding the milestone's project (`project_proposal`) or in the program framework of that project's program.
- Activities can carry indicators like any other level ("each one has indicators").
- Delete a result: existing rules (no delete with children or indicators). Delete a proposal with a framework: refused while any indicator in its framework has targets with reports; otherwise `DeleteProposal` deletes the framework and the proposal in one transaction (the existing funding-agreement guard stays).
- Changing an output that has activities to another type is refused (existing "nested results still sit at the level below" rule).
- RAG rating stays on the calendar year.

## Security and authorization
- Results writes keep `results.create` / `results.update` / `results.delete` (MEAL and General Manager; Managers create and update). `proposals.update` alone grants nothing on the proposal framework.
- Linking a milestone: `MilestonePolicy::update` (`milestones.update` or the project owner). The plan also names the milestone's responsible person, who today only holds `updateProgress` (status only): raise it in plan mode; default to `update`.
- Viewing the tab follows the page (`proposals.view`, or the reviewer access from Task 07). No money in the tab: do not load agreements, budgets or transfers.
- Every Action calls `Gate::authorize` itself. Parent, contributes-to and activity ids are validated on the server against the scope: load the proposal's results through `Result::query()->where('proposal_id', $proposal->id)->whereKey($id)`, program results through `programFramework()`, and the milestone's allowed activities from its project's proposals and program. Never trust ids held in Livewire state; mark edited ids `#[Locked]`.
- Audit: `Result` and `Milestone` already implement `TracksActivity`; add the new fields to `activityEvents()` with translated sentences.

## Validation
Messages in `lang/{en,ar}/results_framework.php` and `lang/{en,ar}/milestones.php`:
- `resultType`: `Rule::enum(ResultType::class)`; wrong parent level — "An activity sits under an output only." / «النشاط يندرج تحت مُخرَج فقط» (and the existing level messages for the other levels).
- `parentId`: same framework as the edited node — "You cannot link a result from another framework." / «لا يمكن ربط نتيجة من إطار آخر».
- `contributesToResultId`: as in the rules — "Choose a result from the program framework." / «اختر نتيجة من إطار البرنامج».
- `milestone resultId`: type activity and an allowed framework — "Choose an activity from the framework of this project's proposal." / «اختر نشاطًا من إطار مقترح هذا المشروع».
- Proposal delete refused — "This proposal's framework has reported targets; it cannot be deleted." / «لإطار هذا المقترح مستهدفات عليها تقارير، فلا يمكن حذفه».

## Edge cases and concurrency
- A project moves to another program: activities already linked to its milestones stay; they are flagged "out of framework" when they no longer fit, never auto-fixed. Add a read helper (e.g. `Milestone::activityLinkIssues()` returning translation keys, in the style of `PlanIndicator::chainIssuesFor()`) that the milestone page shows and Task 16 reuses.
- A project is unlinked from a proposal: milestone links to that proposal's activities are flagged, not deleted. Observers fire only when `result_id` / `parent_id` is dirty, so unrelated saves of flagged rows still succeed.
- The proposal's linked projects change programs after the framework exists: decide in plan mode whether `SaveResult` re-derives `program_id` on the next save or a follow-up resyncs it (recommended: re-derive on save of the root, cascade to the subtree as today).
- Program pages must not start showing proposal nodes: today `programs/⚡show::resultRoots()` and `Program::results()` read every result with that `program_id`. Use `programFramework()` there and list every other read you change; ask whether annual-plan targets may point at proposal-framework indicators (recommended: no, through `chainIssuesFor()`).
- Arrays keyed by `ResultType` values (`plan-years`, `indicators/⚡index`, `projects/⚡show`) throw "undefined key" once an activity exists: grep all of them.
- Two editors save under the same parent: no uniqueness is involved; the observer re-reads the parent inside the save, so a parent deleted meanwhile fails the FK and returns the "choose another parent" message, not a 500.
- Double click on delete proposal: the second call finds no row and returns quietly.
- The `/results` page and the proposal tab keep a fixed query count with both kinds of framework loaded.

## Data integrity
- Migrations: run `database-schema` first; each `down()` drops the FKs, then the columns. No data change: existing rows stay program frameworks (`proposal_id` NULL).
- `results.proposal_id` restricts deletes; `DeleteProposal` removes the framework through Eloquent inside one `DB::transaction` (leaf-first, or roots relying on the existing `parent_id` cascade — decide in plan mode, and whether the batch runs under `Activity::withoutRecording` with one proposal event), then `$proposal->delete()` so `PlanIndicatorSourceObserver` still removes its targets. Report files on disk are not involved because reported frameworks are refused.
- `milestones.result_id`, `contributes_to_result_id`, `copied_from_id` are `nullOnDelete`.
- `ResultObserver` and `MilestoneObserver` throw `InvalidParentException` (backstop); `SaveResult` and `LinkMilestoneToActivity` validate first and return field errors. Raw query-builder writes bypass both; the existing subtree program move in `saveResult()` must keep the scope (it only writes `program_id` within one framework).

## Implementation guidance
- `declare(strict_types=1);`, typed signatures, PHPDoc array shapes, `#[Fillable]`, `casts()`, curly braces. Backed enum values lower case; no `default` arm added to any `match` over `ResultType`.
- Skeletons:
```php
final class SaveResult
{
    /** @param array{statement: string, resultType: string, checkoutPeriod: string, resultProgramId: ?string, parentId: ?string, contributesToResultId?: ?string} $validated */
    public function handle(?Result $existing, array $validated, ?Proposal $proposal = null): Result {}
}

final class LinkMilestoneToActivity
{
    public function handle(Milestone $milestone, ?int $activityId): Milestone {}
}
```
- `results/⚡index` keeps loading the tree once (`results.md`): add `proposal_id` and `contributes_to_result_id` to `allResults()`'s select, filter in PHP, and call `forgetFramework()` after every write.
- The Activity level needs its own visual identity: propose mark/accent classes in plan mode (e.g. an outlined dot and no accent bar) and use them everywhere the other levels are mapped.
- Modals bound with `wire:model` booleans, never `:open`; `x-ui.*` and Flux components; logical spacing (`ps-*`/`pe-*`); no `@php(...)` shorthand.

## Tests (Pest)
Follow `.ai/rules/tests.md` (seed `RolesAndPermissionsSeeder`, `User::factory()->role(...)`, one fresh Livewire instance per forbidden call). Update existing tests whose behaviour this task changes (e.g. "nothing can be added under an output" becomes "nothing can be added under an activity"); never delete them.
- Create `tests/Feature/ResultScopeTest.php` (or extend `tests/Feature/Models/TreeIntegrityTest.php`): activity under output succeeds; activity under outcome refused; a child under an activity refused; a child with another `proposal_id` or `program_id` than its parent refused; `contributes_to` from a non-root, to a proposal-framework result, or to a program outside the proposal's projects refused; a multi-program proposal root gets `program_id` NULL.
- Create `tests/Feature/ProposalLogframeTest.php` (`pages::proposals.show`, tab `logframe`): MEAL builds four levels and links the root; Managers create and edit but cannot delete; a holder of `proposals.update` without `results.*` gets 403 on every write; a parent id from another proposal's framework is refused (BOLA); viewers see the tree read-only.
- Create `tests/Feature/MilestoneActivityLinkTest.php`: linking an activity from a funding proposal's framework or from the project's program framework succeeds; an activity from a framework that does not fund the project is refused; a non-activity result is refused; unlinking the project from the proposal keeps the link and flags it; a user without `MilestonePolicy::update` gets 403.
- Revision 2: the proposal tab is editable while the proposal is `concept note`; after `submitted` a structural edit by a user without `results.update` is refused; a proposal-level milestone (Task 31) links to an activity of its own proposal and is refused for another proposal's activity.
- Create `tests/Feature/DeleteProposalTest.php` (or extend `ProposalRegisterTest.php`): a proposal whose framework has no reports deletes with its framework in one transaction; one with reported targets is refused and nothing is deleted; the funding-agreement guard still holds.
- Update `tests/Feature/ResultsFrameworkTest.php` (activity level, proposal filter, same-scope parent options, and a new flat query-count test for 3 and 30 statements across both kinds of framework), `tests/Feature/EnumCastTest.php`, `tests/Feature/ProgramDetailTest.php` (program framework excludes proposal nodes), `tests/Feature/MilestoneShowTest.php`.
- Keep green: `ResultTest`, `Models/ResultTest`, `AccessControl/ResultsFrameworkAuthorizationTest`, `AccessControl/MilestoneAuthorizationTest`, `IndicatorDirectoryTest`, `TargetDirectoryTest`, `ProjectDetailTest`, `AnnualPlanDetailTest`, `ProposalDetailTest`, `Models/TransactionalDeleteTest`, `LocalizationTest`, `Notifications/MonitoringActivityTest`.
- Run `php artisan test --compact tests/Feature/ResultScopeTest.php` and each file above (or `--filter=...`).

## Acceptance criteria
- [ ] MEAL builds a four-level framework inside a proposal and links its root to the program framework.
- [ ] The milestone form offers only allowed activities, and the server refuses any other id.
- [ ] Program pages show only program frameworks; `/results` shows both, filterable by proposal and by the activity level, at a flat query count (test).
- [ ] The framework is editable from `concept note`, structurally locked after `submitted` except for `results.update` (test).
- [ ] Deleting a proposal with a reported framework is refused; without reports it removes the framework atomically.
- [ ] All existing results, indicators, targets and milestone tests pass; every new string exists in `lang/en` and `lang/ar`.

## Do not
- Create a separate logframe table instead of extending `results`.
- Break "a child follows its parent's program", or let a proposal node join another framework.
- Load the tree per node (N+1) or call `HasTree::ancestors()` in loops.
- Add a `default` arm to a `ResultType` match, or delete existing tests whose expectations change.
- Let `proposals.update` alone edit the proposal framework.
- Bind a Flux modal with `:open`, use `@php(...)` shorthand, or add a dependency or base folder.

## Quality bar (this task)
- **Logical Framework** tab: one consistent ResultType identity (including Activity and Cross-Cutting); add-child actions only where the level is allowed; the contributes-to picker on roots; read-only rendering with one explanatory line after the `submitted` lock.
- Activity calendar grid: sticky activity column, months as columns inside a horizontally scrolling panel, keyboard toggling, a week popover on second click, months outside the planned period disabled with a tooltip, and a «بلا رزنامة» badge.
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
Your final message must contain: a summary; files changed; migrations added; tests run with pass/fail output; decisions assumed (D-24 and the plan-mode answers: grouping on `/results`, responsible person linking, milestone create forms, framework delete order, level restriction on contributes-to, annual-plan targets on proposal nodes); follow-ups. Handoff facts frozen at CP2 (end of day 6): the exact columns and FK behaviours, `ResultType::Activity` and `CrossCutting`, `ActivityKind`, the `activity_schedules` shape and its "which months is activity X due for project P" read helper (Task 17 builds the monthly report's activity section from it, Task 31 draws it on the Gantt), the `Result` scopes and relation names (`proposal`, `contributesTo`, `contributions`, `copiedFrom`, `Milestone::activity`), `SaveResult::handle()` and `LinkMilestoneToActivity::handle()` signatures, `DeleteProposal` behaviour, the Activity colour mapping, and `Milestone::activityLinkIssues()` — Task 13 (attach indicators under proposal nodes), Task 16 (chain view, contributions, gap markers), Task 10 (clone with `copied_from_id`), Task 31 (proposal milestones linked to activities) and Task 14 ("no framework" = no `programFramework()` rows for the program) rely on them.
````
