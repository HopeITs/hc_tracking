# Task 10 — Renewal as a new proposal on the same project (center), duplicate detection and proposal pre-fill

> **المهمة:** التجديد = مقترح جديد على المشروع (المركز) نفسه، وكشف التكرار والتعبئة المسبقة (**Start from Previous Proposal**، **Similar Projects**)
> **المطوّر:** B3 (مراجعة Dev 2) · **الفريق:** B · **الأيام:** 7–8 · **الجهد:** يومان · **يعتمد على:** Task 05، Task 06، Task 30 (وTask 12 وTask 31 للنسخ الكامل للإطار والخطة، ويمكن إضافته لاحقًا)
> **متى تستخدمه:** بعد دمج Task 05 وTask 06 وTask 30 (بعد CP1: `projects.center_id` و`EmployeeProfile` ورفض المشروع المفتوح الثاني للمركز)، ويُفضَّل بعد CP2 (مخطط Task 12: `results.proposal_id` و`results.copied_from_id`، ومخطط Task 31: `milestones.proposal_id` و`tasks.department_id/kind`) لنسخ الإطار والمراحل ومهام الأقسام. إن لم يُدمجا بعد، يُنفَّذ الاستنساخ دونهما وتُسجَّل إضافتهما متابعةً. عمود `proposals.previous_proposal_id` يمسّ جدولًا من المرحلة 1، فيُعرض ترحيله على Dev 1 قبل الدمج (قاعدة العمل 1 بين الفريقين).
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are implementing Task 10 of the HC Tracking proposal-lifecycle correction plan (Laravel 13, PHP 8.5, Livewire 4, Flux UI 2, Pest). You are on Team B (owner B3, reviewed by Dev 2; backend parts also reviewed by Dev 1), days 7–8, 2 days of effort.

The binding domain fact (plan section 1.0, D-30 as amended in Revision 6): **a project sits inside a center**. Every project is linked to one HR center (`projects.center_id`) and one program; a center (a building) may host several projects, but at most one open project (`draft`/`approved`/`active`) per **center and program**. A new grant for an existing project is **a new proposal on the same project**, never a new project. So "renewal" in this task means:
- **Start from Previous Proposal**: clone last year's proposal (donor, linked projects/centers, notes, logical framework and its indicators from Task 12, milestones and department tasks from Task 31 without actual dates, deliveries or files) into a fresh `concept note`, linked to its predecessor through `proposals.previous_proposal_id`, showing last year's achievement beside each target as a reference only.
- **Duplicate detection** when creating a project: same center and program with an open project → refused, with a link "Add a proposal to it" (the refusal rule itself is Task 30's; you add the call to action); the center's projects of other programs and similar names in other centers → a non-blocking **Similar Projects** list for information.
- **History**: the chain "previous ← current → next" on the **Proposal** page, and a "Funding history" list (all proposals of the center and their donors across years, no money) on the **Project** page.

There is **no** `projects.previous_project_id` and **no** `CreateRenewalProject` action: older drafts of this prompt had them; they contradict the revised plan and must not be built. Task 13 (target suggestions) and Task 18 (year comparison in **Monthly Matrix**) consume `previous_proposal_id`.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins).
2. `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md` — Arabic and authoritative; English table, column, enum, permission and UI names are exact. Read: section 0 ("الفريق والأدوار", "المراحل وتوزيعها على الفريقين", "قواعد العمل بين الفريقين"), section 1.0 (domain facts), `### Task 10` in full, `### Task 30` (center link, open-project rule, `EmployeeProfile`), `### Task 05`, `### Task 06`, `### Task 12` (columns you copy), `### Task 13` (targets per center, `plan_indicators`), `### Task 31` (milestones and department tasks on the proposal, its edge case "مقترح يُستنسخ للتجديد"), section 5, section 7 (CP1, CP2) and rows D-11, D-24, D-27, D-30, D-34 of section 9.
3. `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` if it exists; a recorded decision overrides the plan's default for D-11 / D-24 / D-27 / D-30.
4. Rule files: `.ai/rules/index.md`, `database-migrations.md`, `migrations.md`, `feature.md`, `models.md`, `concerns-factories.md` (HR is read-only, never copied), `observers.md`, `concerns.md`, `factories.md`, `seeders.md`, `enums.md`, `views-enums.md`, `pages.md`, `proposals.md`, `proposalsfunding-agreementstransfersdonors.md`, `resultsindicatorstargetsreports.md`, `milestones.md`, `milestonestasks.md`, `tasks.md`, `views-components-ui.md`, `components-ui.md`, `ui.md`, `views.md`, `views-pages.md`, `tests.md`. Then run `grep -rin 'renew\|copied_from\|cycle\|Like::\|debounce\|center' .ai/rules`.
5. Study first: `resources/views/pages/projects/{⚡index,⚡show}.blade.php` (create form after Task 30: center picker, derived city, open-project refusal), `resources/views/pages/proposals/{⚡index,⚡show}.blade.php`, `app/Actions/Proposals/{SaveProposal,LinkProposalProject}.php` (Task 06), `app/Actions/Projects/*.php` (Task 05 `RestoreProject`, Task 06's closed → draft step), `app/Support/EmployeeProfile.php` and `app/Models/HrCenter.php` (Task 30), `app/Models/{Project,Proposal,Result,Indicator,PlanIndicator,Milestone,Task}.php`, `app/Concerns/{HasTree,HasAssignments}.php`, `app/Observers/{ResultObserver,MilestoneObserver,MediaOwnerObserver}.php`, `app/Exceptions/InvalidParentException.php`, `app/Support/Like.php`, `app/Policies/{ProjectPolicy,ProposalPolicy}.php`, `database/factories/{ProjectFactory,ProposalFactory}.php`, `tests/Feature/{ProjectDirectoryTest,ProjectDetailTest,ProposalRegisterTest}.php`, `tests/Feature/Models/TreeIntegrityTest.php`, `tests/Feature/AccessControl/ActionAuthorizationTest.php`.
6. Activate skills: `laravel-best-practices`, `livewire-development`, `fluxui-development`, `database-migrations`, `laravel-security`, `testing-best-practices`.
7. Use Laravel Boost `search-docs` before relying on framework APIs (self-referencing FKs, `wire:model.live.debounce`, computed properties, `Cache::lock`) and `database-schema` on `projects`, `proposals`, `project_proposal`, `results`, `indicators`, `plan_indicators`, `milestones`, `tasks` before writing migrations.

## Preconditions to verify
- Task 30: `projects.center_id` exists, `HrCenter` is a read-only `HrModel`, `EmployeeProfile` exists, and saving a second open project for the same center and program is refused on the server with the Task 30 message. If the refusal is missing, stop and report — do not re-implement it here.
- Task 05: projects are created as `draft` without dates; `ProjectFactory::draft()` exists; `RestoreProject` brings an archived project back as `closed` (with dates) or `draft` (D-27).
- Task 06: `SaveProposal` and `LinkProposalProject` exist; Create Proposal takes donor, projects and requested budget; `proposals.donor_id` exists (Task 03). Linking a `closed` project moves it back to a dateless `draft` inside the same save through Task 06's step (e.g. `ReopenProjectAsDraft`), refused when the centre has another open project.
- `proposals.previous_proposal_id` does not exist yet; `projects.previous_project_id` does not exist and must not be created.
- Optional (CP2): Task 12 columns (`results.proposal_id`, `results.copied_from_id`, `library_indicator_id`) and Task 31 columns (`milestones.proposal_id`, `tasks.source_type = 'proposal'`, `tasks.department_id`, `tasks.kind`). Missing → clone without that part behind one clearly named step, and list it as a follow-up.
- Whether `proposals` has a creator column after Task 03 (needed for the idempotency key).
If a required check fails, stop and report instead of improvising.

## Revision 3 changes (2026-10-09)
Source: `Stratigy.pptx` (strategy 2026–2030) and `micro logic.xlsx` (the real Micro program logframe, urban and agricultural tracks). Read plan section 1.0 facts 10–12 and the «تعديلات المراجعة 3» subsection of this task. Where this block conflicts with text further down this prompt, this block wins.
- Copying the logical framework on renewal MUST reuse `App\Actions\Results\CopyResultTree` built by Task 32 (day 7). Do not write a second tree-copy routine. `CopyResultTree` fills `copied_from_id` on every result and indicator and never copies targets or reports.
- If Task 32 is not merged when you start, code against the Action signature agreed at CP2 and coordinate with B4.

## Revisions 6–8 changes (2026-10-12)
Source: decision-workshop answers (R6 file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.docx`) and two real M&E workbooks (`Syria Cross e.V._ME_Tool_IPTWP_V1_2026_Aug.xlsx`, `Children University - IPTT - 2026.xlsx`). Read the plan header paragraphs «المراجعة 6/7/8», decision rows D-30, and the «تعديلات المراجعة 6, 7 and 8» subsections of this task in the plan. Open questions still running on their documented default are listed in `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r7.ar.docx`. Where this block conflicts with text further down this prompt, this block wins.
- Renewal = a new proposal on the same project, and a project is identified by center **and** program (D-30a confirmed). Duplicate detection looks for an open project for that pair; «similar projects» lists the center's other projects for information only.

## Scope
### In scope
- Migration: `proposals.previous_proposal_id` (nullable FK → `proposals`, `nullOnDelete`, indexed). It touches a Phase 1 table: show the migration to Dev 1 before merging (inter-team rule 1) and say so in your report.
- `copied_from_id` audit columns: the plan wants them on `results`, `indicators`, `milestones` and `tasks`. Only `results.copied_from_id` is created by Task 12. Propose the others in plan mode (nullable self-FK, `nullOnDelete`) and wait for approval; `milestones`/`tasks` columns must be agreed with the Task 31 owner (also B3).
- Model: `Proposal::previousProposal()`, `Proposal::renewals()`; add to `#[Fillable]` and `@property` docs.
- `App\Actions\Proposals\CloneProposalForRenewal`.
- `App\Support\SimilarProjects` (similarity query, max 5 rows).
- Cycle guard for `previous_proposal_id` (a proposal cannot precede itself or any ancestor): in an observer on `Proposal` (`saving`, only when the column is dirty and non-null) — check whether a `ProposalObserver` exists first; create it otherwise and register it with `#[ObservedBy]`.
- Create Proposal (Task 06 form): **Start from Previous Proposal** — pick a visible previous proposal (approved/completed/rejected), enter the new internal number, open the form pre-filled; save through `CloneProposalForRenewal`.
- Create Project (`projects/⚡index`): when Task 30 refuses the center, show the existing open project with **Add a proposal to it** (link to Create Proposal pre-selecting that project). **Similar Projects** panel fed by the typed name: name, center, program only; "This is a new project" confirmation required to save when matches exist.
- Closed project of a center and program with no other open project: offer **Add a proposal to it**; linking the new proposal reopens it as a dateless draft through Task 06's step (D-30). Archived project: offer **Restore** (Task 05 `RestoreProject`, D-27) first, then the same link. Never write `status` directly.
- `proposals/⚡show`: chain "previous ← current → next(s)"; last year's achievement beside each target of the cloned proposal (reference only).
- `projects/⚡show`: **Funding history** — every proposal linked to this project (center) across years: internal number, donor name, status, planned/actual years. No money.
- Lang keys in `lang/{en,ar}/projects.php`, `proposals.php`.
### Out of scope
- The open-project-per-center rule and center picker (Task 30). Target grid and suggestions per center (Task 13). Year comparison in **Monthly Matrix** (Task 18). Project status rules and restore semantics (Task 05). Logical framework schema (Task 12). Milestones/department tasks schema and rebase (Task 31). Notification catalog (Task 21). Note anything else as a follow-up.

## Business rules
- No project is created to renew a grant for an existing center. The renewal is a new proposal linked to the same project(s).
- Clone **never** copies: status (always `concept note`, first `ProposalStatusNote` written by `SaveProposal`), actual dates, files, agreement, reports, reviewers, deliveries of department tasks (`delivered_on`, `delivered_link`, `delivered_text`, delivery files), monthly reports, transfers.
- Clone copies: donor, linked projects (centers), notes, planned duration (as planned dates the user may edit), the proposal logical framework and its indicators (Task 12), milestones and department tasks (Task 31) with planned dates only and statuses reset; `previous_proposal_id` points to the source.
- A linked project whose HR center became inactive is skipped and named in the result message. A library indicator that became inactive (Task 11) is skipped and named in the message.
- Targets (`plan_indicators`) are **not** copied as commitments; last year's values are shown as references (D-11), read through `PlanIndicator::achievedValue()` / `percentAchieved()` on the previous proposal's targets matched by library indicator and project.
- The clone needs a new unique `internal_project_number` (existing Task 06 rules, `TEMP-` reserved): the action opens the pre-filled form; it never saves silently.
- Duplicate detection: same center + same program + open project = **refuse** (Task 30 rule, with the link); another program in the same center, or a similar name in another center = **inform only**.
- The clone goes through `SaveProposal`, so `proposal.created` is emitted once (Task 21 catalog); do not emit a second creation event.

## Security and authorization
- `CloneProposalForRenewal`: `Gate::authorize('create', Proposal::class)` (`proposals.create`); `ActionAuthorizationTest` enforces `Gate::authorize(` in `app/Actions`.
- The previous proposal must be visible to the user (`Gate::allows('view', $previous)`), re-loaded and re-checked on the server; ids from the client are never trusted. Linked projects are read from the previous proposal's relation, not from the request.
- Copying the framework also needs `results.create`; copying milestones/department tasks needs `milestones.create` / `tasks.create` (or `proposals.update`, per Task 31). Without them the proposal is cloned without that part and the message says so.
- **Similar Projects** and **Funding history** show names, center, program, donor name and status only — no budget, requested amount, agreement amount or transfers.
- Activity Log (`activities`): the clone records its source id on the new proposal's creation entry (or a dedicated event the Task 21 catalog lists — ask in plan mode).

## Validation
- `previousProposalId`: nullable, integer, exists, visible, status in approved / completed / rejected (map to the Task 04 `ProposalStatus` cases; ask if "approved" should include `active`; `final reports` no longer exists after Task 04, D-02) — ar «اختر المقترح الذي يتجدد» / en "Choose the proposal being renewed."
- No cycle: a proposal cannot be its own predecessor or a predecessor of any ancestor — messages in `proposals.php`.
- New internal number: the existing Task 06 rules.
- Similar-project confirmation flag: required (accepted) when matches exist — «يوجد مشاريع بأسماء مشابهة؛ أكّد أن هذا مشروع جديد».
- All messages in `lang/ar/*.php` + `lang/en/*.php` of the module; no literal strings.

## Edge cases and concurrency
- Double click on clone: idempotent — key = `previous_proposal_id` + an open `concept note` clone by the same user in the last 2 minutes; return the existing draft. If `proposals` has no creator column, use an atomic `Cache::lock` keyed by user + source and the `proposal.created` activity actor; do not add a column without approval.
- Renewing from a proposal whose project is now closed: the clone links that project and `SaveProposal` reopens it as a dateless draft (Task 06's step), only if the center has no other open project of that program; otherwise the clone links that program's current open project in the center — ask in plan mode which applies when they differ.
- Two users cloning the same source at once: both are allowed (two drafts), the second user sees a notice "a renewal draft already exists: {number}".
- Name typed in Arabic or with diacritics: search uses `utf8mb4_unicode_ci` with `App\Support\Like::contains`.
- Partial failure while cloning framework, milestones or tasks: the whole clone rolls back.
- Previous proposal deleted later: `nullOnDelete` keeps the renewal; the chain shows "removed".

## Data integrity
- Clone runs in one transaction: proposal + project links + first status note + framework rows + indicators + milestones + department tasks; tree parents are remapped to the new ids (copy parents before children, keep an old→new id map), never pointing back into the source.
- Every copied row carries `copied_from_id` where the column exists.
- The cycle guard walks up `previous_proposal_id` one query per level (same approach as `HasTree::wouldCycleUnder`) and throws `InvalidParentException` (add a named constructor). Raw query-builder writes bypass observers (documented trap in `observers.md`).
- Migration: nullable column only, FK as stated, `utf8mb4_unicode_ci` / InnoDB DYNAMIC, working `down()` (drop FK, then column).
- HR data (center, city, employee names) is read through `HrCenter` / `EmployeeProfile`, never copied into tracking tables.

## Implementation guidance
- Similarity: normalise the typed name into words, `where` with `Like::contains` per word, optionally same `program_id`, exclude the current center, limit 5, newest first; computed property fed by `wire:model.live.debounce.400ms`. No new packages.
- Never `whereHas` across the `hr` connection; load center names with one batched lookup.
- Reuse the existing tree/gantt/task components (`x-ui.*`, `_list-group`) for showing copied plans; do not duplicate forms.
- `php artisan make:migration ... --no-interaction`, `make:class` for Support/Actions, `make:observer`.
- Flux modals with `wire:model` booleans, `x-ui.*` components, logical Tailwind classes, all text through `__()`.
- Skeleton:
```php
final class CloneProposalForRenewal
{
    /** @return array{proposal: Proposal, skippedProjects: list<string>, skippedIndicators: list<string>, copied: array{results: int, indicators: int, milestones: int, tasks: int}} */
    public function handle(Proposal $previous, array $data, User $user): array {}
}
```
- After merge, record the durable rule "renewal = new proposal on the same project; no project chain" with Boost `record-rule` (glob `{app/Actions/Proposals/**,resources/views/pages/proposals/**,resources/views/pages/projects/**}`).

## Tests (Pest)
- `tests/Feature/ProposalRenewalCloneTest.php`: clone copies only the allowed fields; status `concept note`; `previous_proposal_id` set; same projects linked; files/agreement/reports/reviewers/deliveries not copied; framework copied with `copied_from_id` and remapped parents when Task 12 exists; milestones/tasks copied with planned dates only when Task 31 exists; without `results.create` cloned without framework + message; inactive library indicator skipped; inactive HR center skipped; double click creates one clone; cycle refused; invisible previous proposal → 403/validation error; user without `proposals.create` → 403 (fresh Livewire instance per forbidden call); last year's achievement equals `achievedValue()`.
- `tests/Feature/ProjectDuplicateDetectionTest.php`: second open project for the same center and program refused with a link to the existing project; another program in the same center accepted (and listed as information); a closed project of the center returns to a dateless draft when a renewal proposal is linked to it (Task 06's step); an archived one is offered **Restore** first; no project chain column exists.
- `tests/Feature/SimilarProjectsTest.php`: a similar-name project in another center appears; max 5; no money in the output; saving requires the confirmation flag; Arabic name matching.
- `projects/⚡show` **Funding history** lists all proposals and donors of the center, with no amounts in the HTML.
- Migration test with id-returning helpers: `nullOnDelete` on `previous_proposal_id`.
- Commands: `php artisan test --compact tests/Feature/ProposalRenewalCloneTest.php`, then `--filter=...` per change.

## Acceptance criteria
- [ ] Renewing a center's grant never creates a project; it creates a `concept note` proposal on the same project(s), linked to its predecessor.
- [ ] A second open project for the same center and program is refused with a link to "Add a proposal to it"; other programs in the center and similar names only inform.
- [ ] The **Project** page shows every proposal of the center and its donor across years; the **Proposal** page shows the previous/next chain.
- [ ] Last year's achievement is shown beside each target of a renewal proposal, read through `PlanIndicator` methods.
- [ ] Existing project and proposal directory/detail tests pass; Pint clean; touched test files green.

## Do not
- Create `projects.previous_project_id`, `CreateRenewalProject`, or any new project as part of a renewal.
- Copy files, agreements, reports, reviewers, statuses, actual dates, deliveries or targets as commitments.
- Block project creation because of name similarity.
- Re-implement the Task 30 open-project refusal, or write `projects.status` outside the Task 05 / Task 06 actions.
- Change other Phase 1 tables (`projects`, `donor_proposal`, `proposal_status_notes`) beyond the one approved column or the state machine (inter-team rule 1).
- Compute achievement with a local formula, add a dependency, or add a base folder.

## Quality bar (this task)
- Duplicate refusal on **Create Project** carries a direct "Add a proposal to it" button; **Similar Projects** is debounced, capped at 5 and styled as information, not an error.
- **Start from Previous Proposal** previews what will and will not be copied before confirming; the project's funding history is a compact, chronological list.
- Everything in `quality-bar.md` applies. Before reporting, open every new or changed screen in the browser (Arabic RTL and English, light and dark, desktop and 375 px), run `vendor/bin/phpstan analyse` on the touched paths, and list both in your report.

## Working method
- Start in plan mode: restate the task in 5 bullets, list files you will touch, list open questions (`copied_from_id` on indicators/milestones/tasks, idempotency key, statuses accepted as "previous", closed vs open project when they differ, framework/plan copy if CP2 is not merged); wait for approval.
- Implement in small steps; after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory).
- Do not commit, push, or run destructive commands (`migrate:fresh`, seeders that reset roles, deleting storage) unless the developer asks.
- Never run anything against production; Team B uses seeded or masked data only.
- Stay inside Task 10's scope; note anything else as a follow-up.

## Report back
Summary; files changed; migrations added (and confirmation that Dev 1 reviewed the `proposals` migration, or that it is pending); tests run with pass/fail output; decisions assumed (D-11, D-24, D-27, D-30 and the open questions); follow-ups (framework or plan copy if deferred). Handoff facts: for Task 13 — how to reach the previous proposal's targets (`previous_proposal_id` → `planIndicators`, matched by library indicator and project) and the reference-value helper; for Task 18 — `previous_proposal_id` for year comparison per center; for Task 21 — which events the clone emits; for Task 27 — the new column(s) and their FK behaviour.
````
