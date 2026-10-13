# Task 16 — Full results chain view: from impact to activities and milestones

> **المهمة:** عرض السلسلة الكاملة: من الأثر إلى النشاط والمراحل
> **المطوّر:** B3 (مراجعة Dev 2) · **الفريق:** B · **الأيام:** 9–10 (الخميس 2026-10-22 ← الجمعة 2026-10-23) · **الجهد:** يومان (أول ما يُختصر عند التأخر، R9) · **يعتمد على:** Task 12، Task 13، Task 31
> **متى تستخدمه:** بعد دمج Task 12 (الإطار المنطقي v2 وربط المراحل بالأنشطة، CP2) وTask 31 (مراحل المقترح ومهام الأقسام) وTask 13 (المؤشرات والمستهدفات لكل مركز، قبل CP3 نهاية اليوم 9). إن تأخر المسار عند CP3 يُختصر إلى عرض شجري بلا مراحل (البند 1 في قائمة التقليص R9).
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are implementing Task 16 of the HC Tracking proposal-lifecycle correction plan in this Laravel 13 / Livewire 4 / Flux UI 2 repository. The outcome: one read-only tree that shows, for a proposal, a program and an annual plan, the chain Impact -> Outcome -> Output -> Activity, with each node's indicators (library name and code), the target per center with its achievement, and each activity's milestones with status, weighted progress and planned/actual dates — plus gap markers and filters (indicator, center, donor). In the corrected story the proposal's framework contributes to its program's framework "to achieve the strategy" and its activities are delivered through project milestones; this view lets MEAL, the fundraising manager and UAT (Task 28) read the whole line in one screen.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins) (Boost rules, Pest, Pint, no new dependencies or base folders).
2. The plan `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md`: section `### Task 16` in full. It is Arabic and authoritative; English names in it are exact. Also read `### Task 12` and `### Task 13` (the data you render), section 1.2 (MEAL side), `### Task 31` (proposal milestones and department tasks), section 5 rows "البيانات المالية" and "مهام الأقسام", section 7 rows CP2 and CP3, section 0 risk R9 and «قواعد العمل بين الفريقين».
3. The decision log `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` if it exists; a recorded decision overrides the plan's default for D-24 (multi-program proposals), D-25 (no summing percentages), D-18 (annual plan activities).
4. Rule files: `.ai/rules/components-ui.md`, `.ai/rules/views-components-ui.md`, `.ai/rules/results.md`, `.ai/rules/indicators.md`, `.ai/rules/resultsindicatorstargetsreports.md`, `.ai/rules/concerns.md`, `.ai/rules/proposals.md`, `.ai/rules/admin.md`, `.ai/rules/annual-plans.md`, `.ai/rules/pages.md`, `.ai/rules/ui.md`, `.ai/rules/views.md`, `.ai/rules/views-enums.md`, `.ai/rules/views-pages.md`, `.ai/rules/css.md`, `.ai/rules/tests.md`. Then `grep -rin 'logframe\|lineage\|parentRecursive\|weighted' .ai/rules`.
5. Existing code to study first:
   - Components: `resources/views/components/ui/logframe.blade.php`, `logframe-node.blade.php` (exhaustive `match` on `ResultType`), `logframe-branch.blade.php`, `framework-node.blade.php` (takes prebuilt node arrays, renders no queries), `target-lineage.blade.php` (shared ResultType colour mapping).
   - Pages: `resources/views/pages/programs/⚡show.blade.php` (`resultRoots()` builds `childrenRecursive` in PHP, `resultSummary()`, `milestoneProgress()`), `resources/views/pages/proposals/⚡show.blade.php` (the **Logical Framework** tab added by Task 12, `targets()`), `resources/views/pages/annual-plans/⚡show.blade.php` (`targets()`, `resultGroups()`), `resources/views/pages/results/⚡index.blade.php` (`buildNode()`, `matchesFilters()`, promoted roots with hidden lineage, `forgetFramework()`), `resources/views/pages/projects/⚡show.blade.php` (`milestoneProgressPercent()` weighted formula excluding cancelled milestones).
   - Models: `app/Concerns/HasTree.php` (`parentRecursive`, `childrenRecursive`, never `ancestors()` in loops), `app/Models/Result.php`, `app/Models/PlanIndicator.php` (`achievedValue()`, `percentAchieved()`), `app/Models/Milestone.php`, `app/Enums/MilestoneStatus.php`, `app/Enums/ResultType.php`.
   - Tests: `tests/Feature/ProgramDetailTest.php`, `tests/Feature/ProposalDetailTest.php`, `tests/Feature/AnnualPlanDetailTest.php`, `tests/Feature/ResultsFrameworkTest.php` (`data-tree-depth`, `data-result-type`), `tests/Feature/IndicatorDirectoryTest.php` (query-count pattern).
6. Skills to activate: `livewire-development`, `fluxui-development`, `tailwindcss-development`, `laravel-best-practices`, `performance-optimization` is not required; `testing-best-practices`.
7. Use Boost `search-docs` for eager loading constraints and Livewire `#[Url]`/`#[Computed]`; use `database-schema` on `results`, `indicators`, `plan_indicators`, `milestones` to confirm Task 12/13 columns.

## Preconditions to verify
- `ResultType::Activity`, `results.proposal_id`, `results.contributes_to_result_id`, `milestones.result_id` exist, and `x-ui.logframe-node` already handles `ResultType::Activity` — Task 12 (CP2, end of day 6).
- The **Logical Framework** tab exists on `proposals/⚡show` — Task 12.
- `indicators.library_indicator_id` and `App\Models\LibraryIndicator` (code, `name_en`, `name_ar`) exist — Task 11; per-center targets exist as `plan_indicators` with `source_type = 'proposal'` and `project_id` = center — Task 13.
- `milestones.proposal_id` (proposal-level milestones) and department tasks on proposals (`tasks.source_type = 'proposal'`, `tasks.department_id`, `tasks.kind`) — Task 31. If missing, render project milestones only and report it.
- `annual_plan_activities` does not exist: Task 24 is out of the October window (D-18). The annual plan view therefore shows the program framework's activities whose linked milestones' planned dates fall in the plan year; do not create Task 24's table.
If a required item fails, stop and report.

## Revision 2 changes (2026-10-08)
- Owner is now **B3 (Team B)**, reviewed by Dev 2; days 9–10 (Thu 2026-10-22 → Fri 2026-10-23); effort cut from 3 to **2 days**. Dependencies now include **Task 31**.
- The chain now includes the proposal's own plan (Task 31): proposal-level milestones (`milestones.proposal_id`, shared across the proposal's centers, D-34; a center-specific one also carries `project_id`) under their activities, and a per-proposal summary of department tasks (media, finance) — titles, department, status and due date only, never money, and finance-kind tasks only for viewers who may see them (follow Task 31's `TaskVisibilityScope`).
- **First to be cut (R9, item 1):** if the plan is late at CP3 (end of day 9), ship a tree without milestones/department tasks (levels, indicators, per-center targets and achievement only). Structure the work so that cut is a clean stopping point: build the tree first, milestones second.
- Task 24 (annual plan activities) is out of the October window: keep the annual-plan fallback, do not plan a hand-off for it.
- Work under the inter-team rules (plan section 0 «قواعد العمل بين الفريقين»): do not change Phase 1 tables (`proposals`, `projects`, `donor_proposal`, `proposal_status_notes`) or the proposal state machine — request any such change from Dev 1 so it is recorded in the plan; integrate with Phase 1 by listening to its events (`ProposalStatusChanged`, `ProjectActivated`, `ProjectPeriodChanged`, `FundingAgreementRecorded`, dispatched after commit) instead of calling Phase 1 Actions directly; every PR is reviewed by Dev 2 (UI) or Dev 1 (backend) within one working day and merges only with this task's suite green; no production access — seed or masked data only.
- `proposals/⚡show` and `programs/⚡show` are shared pages: keep changes inside the tab/component and coordinate with Dev 2 (proposal pages). This task is read-only and emits no new events.

## Revisions 3–9 changes (2026-10-09 → 2026-10-12)
Source: plan section 6 («إزاحات المراجعة 4») and the Revision 3/8 subsections of `### Task 12`. Where this block conflicts with the Revision 2 block or the base sections, this block wins.
- **Schedule (Revision 4):** B3's days 9–10 now belong to Task 33's UI. This task is the **first item on the cut list** and may run after launch; start it only when Dev 2 confirms at CP3/CP5 that the October window still has room, and keep the "tree first, milestones second" stopping point.
- **Cross-Cutting level (Revision 3):** render `ResultType::CrossCutting` roots in their own group below the tree with their indicators and targets; they take no parent, no children and no contributes-to link. Every `ResultType` match gets an explicit arm.
- **Activities (Revisions 7–9):** when Task 17 has written `project_activity_statuses`, show each activity's confirmed status for the selected center as a text badge (Not Started, Started, Ongoing, Suspended, Cancelled, Completed, Conducted — D-51) and its planned months/weeks from `activity_schedules`; this is display only and must not add queries per node.
- **Codes (Revision 9):** indicator codes are the generated library codes (`002.OP1.2.3`), shown LTR-isolated.

## Scope
### In scope
- Extend `x-ui.logframe` (and its node/branch partials) to render per node: statement and level mark; indicators (library name in the user's locale with the other language as a secondary line, code LTR-isolated); per-center target and achievement; for activities, their milestones with status badge, weighted progress, planned and actual dates; gap markers.
- Make the component's heading id and title configurable (today it is hard-wired to `programs.results_logframe` / `program-results-heading`).
- Host pages:
  1. `proposals/⚡show` **Logical Framework** tab: the proposal's framework.
  2. `programs/⚡show`: the program framework plus proposal contributions shown under the result each proposal root contributes to (`contributes_to_result_id`), naming proposal and donor as plain text.
  3. `annual-plans/⚡show`: the plan's targets and the year's activities (see precondition).
- Filters (`#[Url]`): indicator, center (project), donor; values restricted to what the page loaded.
- Gap markers: result without indicators, indicator without target, activity without milestone, milestone (of the proposal or its projects) without activity.
- Proposal plan (Task 31): proposal-level milestones under their activities, and a department-tasks summary per proposal (no money; finance tasks only for viewers allowed by `TaskVisibilityScope`). Built after the core tree so it can be dropped under R9.
- A shared tree builder is acceptable (e.g. a class in `app/Support/`) so the three pages build identical node arrays; components render no queries.
- Empty state with a **Start Logical Framework** button for `results.create` holders.
- Lang keys in `lang/en/results_framework.php` + `lang/ar/results_framework.php` (and page groups as needed; never a group named `results`).
### Out of scope
Editing the framework, linking milestones (Task 12); entering targets (Task 13); monthly reports (Task 17); **Monthly Matrix** (Task 18); annual plan activities table and approval (Task 24); tours (Task 26).

## Business rules
- Achievement counts approved reports only, via `PlanIndicator::achievedValue()` / `percentAchieved()` (one definition).
- Weighted milestone progress uses the existing formula: reached weight over the weight of non-cancelled milestones; cancelled milestones count on neither side.
- Proposal contributions appear under the program result they contribute to, with proposal name and donor name as text.
- A multi-program proposal (D-24) appears under each program it contributes to.
- A node whose parent is hidden by a filter is promoted to a display root and shows the hidden lineage as context (existing results-page behaviour).
- Percentages are never summed across centers or donors (D-25); totals show per target.

## Security and authorization
- The view follows the host page's permission (`proposals.view` or Task 07 reviewer access, `programs.view`, `annual-plans.view`). Add no new route.
- Proposal and donor names are plain text; links to `proposals.show` / `donors.show` render only for holders of `proposals.view` / `donors.view` (`.ai/rules/pages.md`).
- No money anywhere in this view: do not load agreements, budgets or transfers.
- Filter values from the query string are validated against the loaded sets (`Rule::in`), never used raw in queries.

## Validation
No user input beyond filters. Unknown filter values are ignored and reset to "All"; nothing is persisted.

## Edge cases and concurrency
- Empty framework: empty state plus **Start Logical Framework** for MEAL (`results.create`); viewers without it see the empty state only.
- Activity with milestones from several projects: group milestones by project (center) under the activity.
- Milestone linked to an activity that no longer fits (Task 12 flags it "outside the framework"): show it with the flag, never hide it.
- Indicator scoped to a project that left the proposal: show the existing chain-issue flag.
- Inactive library entry: still displayed on existing frameworks.
- Read-only view: no concurrency writes; after Task 12/13 writes on the same page, call the page's `forget…()` helper so figures refresh.

## Data integrity
Read only. No migrations, no writes, no observers.

## Implementation guidance
- Load once per page, then filter and shape in PHP (`.ai/rules/results.md`, `.ai/rules/indicators.md`): results of the scope, indicators with library entries, plan indicators with `reports` and `indicator`, projects, milestones with `result_id`, proposals/donor names for contributions. Target a flat query count independent of node count.
- Lineage through eager-loaded `parentRecursive` or the in-memory parent map; never `HasTree::ancestors()` per node.
- Components take prebuilt arrays or models with every relation set (`setRelation`); no lazy loading (strict mode throws).
- Keep the shared ResultType mark/accent mapping (`.ai/rules/components-ui.md`) and keep `data-tree-depth` / `data-result-type` attributes.
- RTL/LTR: logical spacing (`ms-*`, `ps-*`, `pe-*`, `start-*`), directional icons `rtl:rotate-180`, numbers `tabular-nums`, mixed names `dir="auto"`.
- Flux components and `x-ui.*` (`x-ui.panel`, `x-ui.empty-state`, `x-ui.enum-badge`, `x-ui.progress-bar`, `x-ui.header-filter-select`). No `@php(...)` shorthand; Graphite tokens behind `dark:`.

## Tests (Pest)
- `tests/Feature/ResultsChainViewTest.php`:
  - the proposal tab renders all four levels, each node's indicators (library name and code), per-center targets and the correct achievement from approved reports only;
  - an activity shows its milestones with status and weighted progress, excluding cancelled milestones, including proposal-level milestones from Task 31;
  - the department-tasks summary shows media tasks without money and hides finance tasks from viewers outside the finance department;
  - gap markers appear for each of the four gap kinds;
  - filters by indicator, center and donor narrow the tree and promote orphaned children with lineage;
  - query count is equal with 3 and 30 nodes (proposal tab, program page, annual plan page);
  - a user without `proposals.view` sees proposal and donor names on the program page but no links;
  - no monetary value appears in the rendered HTML;
  - Arabic locale: headings from `lang/ar`, logical spacing classes present, tree order intact.
- Extend `tests/Feature/ProgramDetailTest.php` and `tests/Feature/AnnualPlanDetailTest.php` for contributions and the year's activities; keep `tests/Feature/ResultsFrameworkTest.php` and `tests/Feature/LocalizationTest.php` green.
Commands: `php artisan test --compact tests/Feature/ResultsChainViewTest.php`, `php artisan test --compact tests/Feature/ProgramDetailTest.php`.

## Acceptance criteria
- [ ] From the **Proposal** page one screen reads Impact -> ... -> Activity -> Milestone with achievement per center, including the proposal's own milestones and department tasks (Task 31) — or, if cut under R9, the tree without milestones.
- [ ] The program page shows proposal contributions under the right results; the annual plan page shows its targets and the year's activities.
- [ ] Achievement only via `achievedValue()`; no local formula.
- [ ] Flat query count proven by tests on all three pages.
- [ ] No money; permission-gated links only.
- [ ] Correct in Arabic (RTL) and English.

## Do not
- Query per node, or call `ancestors()` in a loop.
- Compute achievement or progress with a new local formula.
- Render amounts or link names for users without the target page's permission.
- Break the existing `x-ui.logframe` usage on `programs/⚡show` or the `ResultsFrameworkTest` attributes.
- Create Task 24's tables or add a new route.

## Quality bar (this task)
- Chain view: logical indentation, collapsible levels, indicator chips with `x-ui.progress-bar` and a per-center breakdown on demand, gap markers as text badges, filters in the header, and a print-friendly layout.
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
Your final message must contain: a summary; files changed; migrations added (expected: none); tests run with pass/fail output with the measured query counts; decisions assumed (D-18 fallback for annual plans, D-24, D-25, D-34); whether the R9 cut was applied; follow-ups (including where `annual_plan_activities` would plug in for the post-October Task 24). Handoff facts for Task 28 (UAT): the pages and filters that show the full chain; for Task 26: the new lang keys and tour anchors (`data-tour` attributes) you added.
````
