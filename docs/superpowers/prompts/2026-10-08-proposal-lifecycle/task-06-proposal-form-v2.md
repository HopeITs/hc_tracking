# Task 06 — Create Proposal / Edit Proposal form v2

> **المهمة:** نموذج **Create Proposal** / **Edit Proposal** v2
> **المطوّر:** Dev 2 · **الفريق:** A · **الأيام:** 4–6 (الخميس 2026-10-15 – الاثنين 2026-10-19) · **الجهد:** 3 أيام · **يعتمد على:** Task 03، Task 04 (CP1)
> **متى تستخدمه:** بعد CP1 (نهاية اليوم 3، الأربعاء 2026-10-14)، أي بعد دمج Task 03 (`proposals.donor_id` والميزانية المطلوبة) وتجميد قيم Task 04 وتوقيع `TransitionProposal`؛ يُبنى بالتوازي مع Task 04، و`TransitionProposal` نفسه يُدمج قبل CP2 (نهاية اليوم 6).
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are implementing Task 06 of the HC Tracking proposal-lifecycle correction plan: "Add Proposal (include Project link - Donors - Budget, ....)". The outcome: a proposal is created from one or more projects, with its single donor and an estimated requested budget, and always starts at the beginning of the lifecycle (`concept note`, or `proposal draft` only with a written reason) instead of a freely chosen status. Saving and project linking move out of the Livewire pages into actions (`SaveProposal`, `LinkProposalProject`, `UnlinkProposalProject`). In the corrected story the project is drafted first, then its concept note is created for one donor; Task 07 (review loop), Task 10 (renewal clone), Task 12 / Task 13 (logframe, per-centre targets) and Task 31 (milestones and department tasks) build on this form, these actions and the proposal page tabs.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins).
2. `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md`, section `### Task 06` — Arabic and authoritative; UI names (**Create Proposal**, **Edit Proposal**, **New Concept Note**, **Proposal**, **Project**) and column names are exact. Also its «تعديلات المراجعة 2» block, section 1.0 (fact 4: the proposal is a planning container from creation), `### Task 12`, `### Task 13` and `### Task 31` (what Team B puts in the tabs), the «تعديلات المراجعة 2» and «تعديلات المراجعة 6» blocks of `### Task 05` and `### Task 30` (closed project returns to draft; one open project per centre and program), and section 9 rows D-03, D-24, D-25, D-27, D-30, D-31b, D-34.
3. `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` (if it exists; a recorded decision overrides the plan's default for D-xx).
4. Rules: `.ai/rules/proposals.md` (status only moves with an audit note; the edit form never writes status), `.ai/rules/proposalsfunding-agreementstransfersdonors.md` (internal number rules; display through `x-ui.project-number`), `.ai/rules/admin.md`, `.ai/rules/pages.md` (`wire:model` modals, never `:open`; eye button; "Hide money, keep names"), `.ai/rules/ui.md`, `.ai/rules/views.md` (no `@php(...)`; `input:class`; dark-mode tokens), `.ai/rules/views-pages.md` (slug route binding: pass models to `route()`), `.ai/rules/views-enums.md`, `.ai/rules/components-ui.md` (`x-ui.multi-select-popover` binding styles), `.ai/rules/views-components-ui.md` (target chain issues, "project funded by proposal"), `.ai/rules/database-migrations.md` (`project_proposal` unique pair), `.ai/rules/models.md`, `.ai/rules/tests.md`.
5. Source: `resources/views/pages/proposals/⚡index.blade.php` (`openCreateModal()`, `openEditModal()`, `saveProposal()` with the `UniqueConstraintViolationException` handling, `resetProposalForm()`, the create/edit modal markup), `resources/views/pages/proposals/⚡show.blade.php` (`openDetailsModal()`, `saveDetails()`, `linkableProjects()`, `linkProject()`, `confirmUnlinkProject()`, `unlinkProject()`, `canViewAgreements()`), `resources/views/pages/projects/⚡show.blade.php` (`attachableProposals()`, `attachProposal()`, `detachProposal()`), `app/Actions/Proposals/TransitionProposal.php` and `app/Support/ProposalTransitions.php` (Task 04), `app/Models/Proposal.php`, `app/Models/Project.php` (`isOwnerRejected()`, `ownerAssignment()`), `app/Models/PlanIndicator.php` (`chainIssuesFor()`), `app/Policies/ProposalPolicy.php`, `app/Concerns/RecordsActivity.php` (`recordActivity()`), `resources/views/components/ui/multi-select-popover.blade.php`, `resources/views/components/ui/project-number.blade.php`, `lang/{en,ar}/proposals.php`, `lang/{en,ar}/notifications.php` (proposal activity sentences).
6. Skills: `livewire-development`, `fluxui-development`, `tailwindcss-development`, `laravel-best-practices`, `laravel-security`, `testing-best-practices`. Use Boost `search-docs` (Livewire 4 validation, `Rule::exists` with closures, Flux modal) before using those APIs.

## Preconditions to verify
- `proposals.donor_id` (NOT NULL), `requested_budget`, `requested_currency` exist (Task 03).
- `App\Actions\Proposals\TransitionProposal`, `App\Support\ProposalTransitions` and `ProposalStatus::ConceptNoteSubmitted` exist (Task 04). Task 04 runs in parallel (days 4–6): if it is not merged yet, build against the CP1-frozen signature and wire the `proposal draft` skip transition last; do not stub a different signature.
- The unique index on `project_proposal(project_id, proposal_id)` already exists (migration `2026_09_04_155319_create_project_proposal_table.php`); do not add it again.
- If any check fails, stop and report instead of improvising.

## Revision 2 changes (2026-10-08)
- Timeline: Team A, days 4–6 (2026-10-15 → 2026-10-19), in parallel with Task 04 (same CP1 contract); Task 07 starts on day 7 on top of this task.
- The proposal is a planning container from its first draft (section 1.0 fact 4): `proposals/⚡show` gets the tab skeleton **Logical Framework** (Task 12), **Indicators & Targets** (Task 13) and **Plan** (Task 31), each with an «قيد الإنشاء» / "Under construction" empty state only. Team B (B1, B2, B3) fills them; give each tab its own partial/include so Team B never edits the same lines as this task.
- Planned dates (`planned_date_begin` / `planned_date_end`) are optional in the draft and REQUIRED when moving to `concept note review`, because milestones and department tasks (Task 31) are scheduled on them. The guard lives in `ProposalTransitions::requirements()` (Task 04); if Task 04 did not add it, add it there, not in the form.
- The projects picker shows each project's HR centre, program and city (Task 30, `EmployeeProfile` / `HrCenter`), since a project sits inside a centre and a centre may host several programs' projects.
- A `closed` project may be linked (a centre coming back with a new grant): inside the same `SaveProposal` / `LinkProposalProject` transaction it returns to `draft` with no new dates, provided the same centre has no other open (`draft`/`approved`/`active`) project of the same program (Task 05, D-30 as amended in Revision 6). Its historical dates stay readable in **Activity Log** (`activities`); Task 08 writes the new dates at approval. `archived` projects are never linkable. Put the move in one small reusable step (e.g. `App\Actions\Projects\ReopenProjectAsDraft`: lock the project, refuse when the centre has another open project, set `draft`, clear the dates) built on the one-open-project-per-centre-and-program check that Task 30 exposes; do not write a second check. Task 05 (days 7–8) reuses this step and the same check for create and restore.

## Revision 4 changes (2026-10-12)
Source: plan header paragraph «المراجعة 4», `### Task 05` and `### Task 33`. Where this block conflicts with the Revision 2 block or the base sections, this block wins.
- `approved` is a live project status again (funded, awaiting the monthly report template). An `approved` project is **linkable**: a second proposal may arrive before MEAL confirms the first template, and its items join the same draft template (Task 33). Linking never changes an `approved` or `active` project's status.

## Revision 9 changes (2026-10-12)
Source: the corrected R6 answers file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.fixed.docx`, which is newer than the R7 questions file and replaces the defaults Revision 8 applied. Read the plan header paragraph «المراجعة 9», the decision rows in section 9 (and «الأسئلة المتبقية بعد المراجعة 9»), and the «تعديلات المراجعة 9» subsection of this task. **Where this block conflicts with any text further down, including the Revisions 6–8 block, this block wins.**
- **D-25:** per selected project, a field «عدد المستفيدين في هذا المقترح» (integer, min:1, nullable in `concept note`) saved to `project_proposal.target_beneficiaries` by `SaveProposal`/`LinkProposalProject`. Validation `beneficiaries.*`: nullable, integer, min:1, keys must be within `projectIds`. After `approved` only `proposals.update` holders (PR) may change it, with a logged reason; sent reports are not recalculated and MEAL is notified (`proposal.beneficiaries_changed`). Tests: saved per project; zero rejected; foreign project id rejected; post-approval change logs the reason. Effort +0.25 day.

## Scope
### In scope
- Create `app/Actions/Proposals/SaveProposal.php` (create and edit); `proposals/⚡index` `saveProposal()` and `proposals/⚡show` `saveDetails()` call it.
- Create `app/Actions/Proposals/LinkProposalProject.php` and `UnlinkProposalProject.php`; the proposal page and the project page (`attachProposal()`/`detachProposal()`) both go through them, so the last-project guard cannot be bypassed from either side.
- Form fields: name, internal number (existing rules), donor (one, required; the internal donor «تمويل ذاتي — Hope» appears for self-funded proposals, D-27a), projects/centres (multi-select, ≥ 1), beneficiaries per selected project (Revision 9), requested budget + currency (money holders only), planned dates (existing rules), notes, starting stage (`concept note` default, or `proposal draft` with a skip reason).
- Remove the free "starting status" select and every route that lets a form write status.
- **New Concept Note** button on `projects/⚡show` opening the **Create Proposal** modal on `proposals/⚡index` with that project preselected (server re-validates it).
- Activity events for linking/unlinking (`proposal.project_linked`, `proposal.project_unlinked`) with sentences in `lang/{en,ar}/notifications.php`.
- Labels and validation messages in `lang/{en,ar}/proposals.php`; Livewire tests.
- Revision 2: tab skeleton on `proposals/⚡show` (**Logical Framework**, **Indicators & Targets**, **Plan**) with an "Under construction" state; centre and city in the projects picker; planned dates optional at creation.
### Out of scope
- Tab contents: logframe (Task 12), indicators and targets grid (Task 13), milestones and department tasks (Task 31) — Team B;
- Reviewers loop and meeting invite (Task 07); approval and actual dates (Task 08); agreement form (Task 09); renewal clone and **Start from Previous Proposal** (Task 10); per-centre targets grid (Task 13); budget lines by category (Task 25).

## Business rules
- Every new proposal starts at `concept note`. A `proposal draft` start is allowed only with a written skip reason (D-03): create the proposal at `concept note` (first `ProposalStatusNote` = `__('proposals.proposal_recorded_note')`), then call `TransitionProposal` (`concept note → proposal draft`, reason = skip reason) inside the same database transaction. This keeps Task 04's rule that only its actions write status. Mention in plan mode that the plan text says "first note"; confirm this two-note shape.
- The donor is editable only while the status is `concept note` or `concept note review` and no agreement exists; after `concept note submitted` or once an agreement exists it is locked (the Task 03 composite FK also refuses).
- Linkable projects: status `draft`, `approved`, `active` or `closed`, not owner-rejected (`isOwnerRejected()`), and visible to the user. Never `archived`. Linking a `closed` project moves it back to `draft` and clears `start_date`/`end_date` (a draft has no dates, Task 05; the old values stay in **Activity Log** through `RecordsActivity`, and Task 08 writes the new dates at approval). That link is refused when the same centre already has another open project of the same program.
- One proposal may fund several projects (centres); one project may have several proposals (renewals, different donors).
- `requested_budget` is optional at creation and required before `proposal review` (Task 04 gate).
- Unlinking a project is refused when it is the last linked project, or when the proposal has targets on that project (`plan_indicators` with this proposal as source and that `project_id`) that already have reports. If targets without reports exist, list them and ask for confirmation; after unlinking they stay and are flagged as inconsistent (existing behaviour of the targets tab).
- Open questions for plan mode: whether **Edit Proposal** changes project links (recommended: no — links are managed from the **Proposal** page's projects panel through Link/Unlink with their confirmation; create sets them); whether the current actual-date fields stay in the form (recommended: drop them from create; Task 08 sets them on approval); whether project owners without `proposals.update` keep linking from the project page (today `attachProposal()` checks `ProjectPolicy::update`; the plan says linking needs `proposals.update`).

## Security and authorization
- Create: `proposals.create`; edit, link, unlink: `proposals.update`, via `ProposalPolicy` and `Gate::authorize` inside each action.
- The project list is built on the server from projects the user can see and that pass the status rules. Submitted ids are validated against the same query (`Rule::exists` with the status conditions plus the owner-rejected check), so editing an id in the request cannot link a forbidden project.
- Unlink loads the project through the parent: `$proposal->projects()->whereKey($projectId)`; a foreign id fails as not found.
- Money: the budget fields are rendered and saved only for holders of `funding-agreements.view`, `transfers.view` or `accounts.view`. `SaveProposal` decides this from the acting user, never from the request; for anyone else the submitted budget is ignored and an existing value is left untouched. No amount appears in toasts, activity sentences or notifications.
- Donor and status are never mass-assigned from request data; `donor_id` is set explicitly after the lock rule is checked.
- Every save, link and unlink is recorded in **Activity Log** (`activities`).

## Validation
Messages in `lang/en/proposals.php` and `lang/ar/proposals.php` (reuse existing keys where they exist):
- `name`: required, string, max:255 — "Enter the proposal name." / «أدخل اسم المقترح» (existing `proposals.enter_a_proposal_name`).
- `internalProjectNumber`: existing rules (unique, case-insensitive, no new `TEMP-`).
- `donorId`: required, integer, exists:donors,id — "Choose the donor." / «اختر المانح».
- `projectIds`: required, array, min:1; each item exists with the status/owner conditions — "Link the proposal to at least one project." / «اربط المقترح بمشروع واحد على الأقل»; an item that became unavailable — existing `proposals.that_project_is_no_longer_available`.
- `requestedBudget`: nullable, numeric, min:0, max:999999999999.99 — "The budget cannot be negative." / «الميزانية لا تكون سالبة».
- `requestedCurrency`: required_with:requestedBudget, `Rule::enum(Currency::class)`.
- `plannedDateBegin` / `plannedDateEnd`: nullable at creation (revision 2), existing date rules and messages when given; required by the `concept note → concept note review` transition — "Set the planned start and end dates before sending the concept note for review." / «حدّد تاريخَي البداية والنهاية المخططين قبل إرسال المذكرة للمراجعة».
- `startingStage`: in `concept note`, `proposal draft`; `skipReason`: required_if:startingStage,proposal draft, min:10 — "Explain why the concept note is skipped (at least 10 characters)." / «اكتب سبب تخطّي المذكرة المفاهيمية (10 أحرف على الأقل)».
- Donor locked: "The donor cannot change after the concept note was sent or an agreement exists." / «لا يتغير المانح بعد إرسال المذكرة المفاهيمية أو تسجيل اتفاقية».
- Unlink: "The last linked project cannot be unlinked." / «لا يمكن فك آخر مشروع مرتبط بالمقترح»; "This project has reported targets in this proposal." / «لهذا المشروع مستهدفات عليها تقارير في هذا المقترح».

## Edge cases and concurrency
- Two saves with the same new internal number: keep the existing `UniqueConstraintViolationException` handling (now around `SaveProposal`).
- Double click on Save: the unique internal number stops a second row; make sure the second attempt surfaces the field error, not a 500.
- A project archived between opening the form and saving: server validation refuses it with the message.
- A user loses money permission while editing: the budget is ignored server-side.
- A user with `proposals.create` but without `proposals.update` choosing `proposal draft`: the transition's policy check fails; refuse the whole save with a clear message (nothing half-created).
- A donor cannot be deleted while proposals use it (`restrictOnDelete`).
- A legacy proposal without a requested budget saves fine; the gate asks for it only on the next transition.
- Two users unlink the last two projects at once: `UnlinkProposalProject` locks the proposal row (`lockForUpdate`) and recounts links inside the transaction, so one of them is refused.
- Two proposals link the same `closed` project, or a new project is created for its centre at the same moment: lock the project row and the centre's open projects before moving it to `draft`; the second request finds it already `draft` and simply links, or is refused when another open project of that centre now exists.

## Data integrity
- `SaveProposal` runs in one transaction: proposal row + project links (`sync` on create) + any closed → draft move of a linked project + first status note (+ the optional skip transition). Any failure rolls everything back.
- `LinkProposalProject` uses `syncWithoutDetaching` (the unique pair makes it idempotent); `UnlinkProposalProject` detaches inside a transaction after the lock and checks.
- Pivot writes fire no model events: record `proposal.project_linked` / `proposal.project_unlinked` explicitly with `$proposal->recordActivity(...)` (names only, no money).

## Implementation guidance
- Actions live in `app/Actions/Proposals/`, `final` classes with `declare(strict_types=1);`, explicit return types and PHPDoc array shapes, e.g. `SaveProposal::handle(?Proposal $existing, array $data, User $actor): Proposal` with `@param array{name: string, internal_project_number: string, donor_id: int, project_ids: list<int>, requested_budget?: ?string, requested_currency?: ?string, planned_date_begin: ?string, planned_date_end: ?string, notes: ?string, starting_stage?: string, skip_reason?: ?string} $data`.
- Projects picker: `x-ui.multi-select-popover` in the drawer/calendar style (`<input type="checkbox" wire:model.live="projectIds" value="…">` rows, each with the `x-show` search filter). Donor: a single form select (`x-ui.form-select`); header filters are not involved.
- The modal binds `wire:model="showProposalModal"` (boolean), never `:open`. Show the internal number with `x-ui.project-number`.
- **New Concept Note**: link to `route('proposals.index', [...])` with the project's slug-bound model or id in the query, read it in `mount()`/a `#[Url]` property, open the create modal, and re-validate it on save. Show the button only to holders of `proposals.create`, and only for `draft`, `active` or `closed` projects that are not owner-rejected (for a `closed` one, only when its centre has no other open project).
- Keep "unfunded" on the projects page measured by the proposal link (`doesntHave('proposals')`), not by agreements.
- Every visible string goes through `lang/{en,ar}`; check RTL layout of the modal in Arabic.

## Tests (Pest)
- Create `tests/Feature/ProposalFormTest.php`: a successful create stores donor, projects and the first note at `concept note`; missing project or donor shows the field messages; `proposal draft` start without a reason is refused and with a reason produces the transition note; an archived project or a tampered id the user cannot pick is refused; a closed project is linked and returns to `draft` with no dates, and is refused when its centre already has another open project; a user without money permission (for example MEAL or Public Relations) neither sees the budget input nor saves a tampered value, while Financial and Public Relations Manager (who holds `funding-agreements.view` since D-31b) do; changing the donor after `concept note submitted` or with an agreement is refused; **Edit Proposal** never writes status; a user without `proposals.create` gets 403 (fresh Livewire instance per forbidden call); the **New Concept Note** link opens the modal with the project preselected; Arabic messages render under `ar`.
- Create `tests/Feature/ProposalProjectLinkTest.php`: link and unlink from the **Proposal** page and from the **Project** page go through the actions; unlinking the last project is refused; unlinking a project with reported targets is refused; unlinking a project whose targets have no reports asks for confirmation and then succeeds; a foreign project id is not found; activity rows are recorded without amounts.
- Update `tests/Feature/ProposalPipelineTest.php` ("a proposal can be created with a trimmed name" now needs donor and project), `tests/Feature/ProposalDetailActionsTest.php` ("the details form edits name, dates and notes but never the status", "a project can be linked and unlinked from the page"), `tests/Feature/ProjectDetailTest.php` where it attaches proposals.
- Revision 2 (in `ProposalFormTest` or `ProposalDetailTest`): the three tabs render with the "Under construction" state on a fresh `concept note`; a proposal saves without planned dates and the move to `concept note review` without them is refused with the message; the projects picker shows centre and city.
- Must stay green: `ProposalDetailTest`, `ProposalRegisterTest`, `ProjectProposalTest`, `AccessControl/FundraisingPagesMoneyTest`, `AccessControl/AuthorizationCoverageTest`, `LocalizationTest`.
- Run: `php artisan test --compact tests/Feature/ProposalFormTest.php tests/Feature/ProposalProjectLinkTest.php`, then the files above.

## Acceptance criteria
- [ ] No new proposal can exist without a donor or without a linked project (tests + the NOT NULL column).
- [ ] The **New Concept Note** button on **Project** opens **Create Proposal** with that project selected.
- [ ] No form offers or writes an arbitrary status; `proposal draft` starts carry a recorded reason.
- [ ] Budget is invisible and unsavable for users without money permissions.
- [ ] Every message exists in Arabic and English, and the modal reads correctly in RTL.
- [ ] The **Proposal** page shows the **Logical Framework**, **Indicators & Targets** and **Plan** tabs from the first draft, each in its own partial ready for Team B.
- [ ] Planned dates are optional in the draft and required before `concept note review`.
- [ ] A closed project can receive a new proposal and returns to a dateless `draft`; an archived one cannot; a centre never ends up with two open projects of the same program.
- [ ] Each linked project carries its beneficiaries count from `proposal review` on (Task 04 gate), and the **Proposal** page shows it next to each centre.

## Do not
- Keep the "starting status" list with every status.
- Load every project into the picker without the permission and status conditions.
- Compute "unfunded" from agreements; it is measured by the proposal–project link.
- Duplicate the save or link logic inside the pages instead of calling the actions.
- Bind a Flux modal with `:open`, or use `@php(...)` shorthand.

## Quality bar (this task)
- **Create/Edit Proposal** in clear sections (basics; donor and money; projects and beneficiaries; planned dates; starting stage); the projects picker shows center, program and city; the beneficiaries input sits next to each selected project; the skip reason appears only when `proposal draft` is chosen; the money section is absent (not hidden) for non-money users.
- Each tab skeleton keeps the plan's «قيد الإنشاء» label and adds one line saying what the tab will hold.
- **New Concept Note** is visible only when the user may use it; tab order follows the sections; long forms show an error summary that jumps to the first invalid field.
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
Your final message must contain: summary; files changed; migrations added (none expected); tests run with pass/fail output; decisions assumed (D-03, D-24 and the plan-mode answers on edit-time links, actual dates and project-page linking rights); follow-ups; and the handoff facts: the `SaveProposal`, `LinkProposalProject` and `UnlinkProposalProject` signatures and data shapes, the linkable-project rule, the money-visibility check used, the activity event names, the closed → draft step (class name and signature; Task 05 reuses it), how `target_beneficiaries` is read and written (Tasks 13, 17 and 33 read it as the fixed denominator), and the tab keys and partial paths of the skeleton — Task 07 (reviewers on the saved proposal) relies on them, and Team B: Task 10 (B3, clone through `SaveProposal`), Task 12 (B1, **Logical Framework** tab), Task 13 (B2, **Indicators & Targets** tab, per-centre targets on linked projects), Task 31 (B3, **Plan** tab on planned dates).
````
