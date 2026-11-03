# Wave 2 · Meeting of 23 Oct 2026 (Rami · Rita · Mai · Alice · Anthony)

How to run an item: `/lifecycle W-NN [slice]` · prompts in [`prompts/`](prompts/README.md) · session rules in [`AGENT.md`](AGENT.md) · ClickUp list **PT New**.

Status: **draft plan, not approved**. Written 28 Oct 2026 from the meeting notes and a read of the code on `sub-main`. Follows the October pack (`docs/lifecycle/`): `contracts.md` and `decisions.md` stay the sources of truth; every item below that changes them is marked **[contract]** and needs a new D-number before any code.

Legend · size: S ≤ ½ day · M 1–2 days · L 3–5 days. Owner: A = Team A (state machine, Phase 1 tables), B = Team B (UI, panels, M&E).

---

## 0. What the code already does (no work, only tell the team)

| Meeting note | Reality on `sub-main` |
|---|---|
| Remove the Risks card from the desk | Done in `205cc05b`: no Risks panel, no Risks lens on the project card. Docs (`.ai/rules/desk.md`, `tracking-desk.md`) are stale and should be corrected. |
| Hide "Add panel" when nothing is allowed | `$this->library` already holds only allowed panels; the Add tile shows only when an allowed panel is not placed (`pages/desk/⚡index.blade.php:107,126`). What remains: the Customize sheet still *lists* locked panels (`_customize-sheet.blade.php:54-59`), see W-11. |
| Reports in the sidebar | `reports.index` is in the M&E group (`Destinations.php:144`) for `reports.view` holders. If Mai does not see it, it is a permission, not a missing link. What is missing is a **donor-reports index** (W-16). |
| Semi-annual donor report from monthly reports | Exists: `ReportingPeriod::SemiAnnual`, `DonorReportAggregator` (T19). Only the *field list* for the "targets only" short version needs agreeing (W-23). |
| Project created from two systems | Projects are created only here (`SaveProject`). There is no HR project table or sync. Nothing to block; confirm with HR that their system has no project form. |
| Completed needs final reports | The gate exists (`TransitionProposal::hasFinalReport`) but `RecordFinalReportAcceptance` has **no UI**, so a donor-funded proposal cannot be closed today. That is a bug (W-05). |

---

## 1. Decisions needed first (Dev 1 → management, one page)

| # | Question | Recommended answer | Blocks |
|---|---|---|---|
| D-55 | **Non-physical centers.** HR owns `centers` (read-only). How do we model "Micro Idlib, followed up by Micro Aleppo"? | A tracking-side `host_center_id` on `projects` (HR center that follows up), with `center_id` NULL and `city_id` required. The open-project rule becomes per (`center_id` ?? `city_id`, `program_id`). No new local centers table; HR stays the only center source. | W-01, W-02 |
| D-56 | **Internal project number.** Auto-generated; which format and from which seed? | `HC-YYYY-NNN` (matches the seed pattern in the 30 Sep spec), per-year sequence in a `sequences` table under lock; read-only in the form, GM may override with a reason. Confirm the 2026 starting number with Garo. | W-03 |
| D-57 | **New donor from the proposal form.** Who approves a pending donor, and what is blocked while pending? | `donors.approval_status` (`pending` / `approved`), approved by PR Manager or GM. A proposal with a pending donor cannot leave `concept note`. | W-04 |
| D-58 | **Submitted + On hold merge.** Keep `on hold` as a status or make it a flag on `submitted`? | Flag: `proposals.on_hold_since` + existing `status_remind_on`; label "Submitted (On hold)". `ProposalStatus::OnHold` becomes `@deprecated` like `FinalReports`; rows migrate to `submitted` (+ flag). Fewer transitions, same reminder. | W-06 |
| D-59 | **Negotiation log.** Where are donor negotiation changes recorded before approved / rejected? | `proposal_status_notes.kind` (`transition` / `correction` / `negotiation`): a note without a status move, optional file (`MediaCollection::Negotiation`). Shown in the Status timeline. | W-07 |
| D-60 | **Extension.** What is an extension and who records it? | `proposals.extended_date_end` + reason + optional amendment file, action `ExtendProposal` (Team A). Only on `active`. Project end date widens through the existing `ProjectPeriodChanged` path, so new monthly reports are generated. Original `actual_date_end` is kept for the distinction the meeting asked for. | W-08 |

### Management rulings · 29 Oct 2026

| # | Ruling | Status | Effect on the plan |
|---|---|---|---|
| D-61 | Risks come **only from the MEAL library** and sit in the logframe next to output/outcome. No free-text risk entry afterwards. | decided | W-09 as proposed, minus the free-text "add risk" form: the drawer is a library picker only; MEAL maintains the library. |
| D-62 | **Proposal location rows** (whiteboard). On every new proposal a row table: `is_new` ☐ · **city*** · [program] · [reporter/manager] · [centers] · ➕ row. Bracketed = optional **until approval**, then required. Multi-programs → a city row per program. After `approved`, programs and projects are **created from these rows**. Programs and projects are owned by **Tracking, not HR**; HR reads them and continues its procedure (no more "Other"). If the reporter retires, a notification asks to reassign. | decided | W-10 changes: the city multi-select becomes the row table; `projectIds` picker goes away. New items **W-34** (rows + materialise projects on approval + programs source = tracking) and **W-35** (reporter-retired alert). Big contract change: `programs` becomes a local table, HR lookup becomes a sync target instead of a source. |
| D-63 | Stage task templates as proposed. | decided | W-12 unchanged. |
| D-64 | Report task as proposed. | decided | W-13 unchanged. |
| D-65 | Department manager = employee whose HR **job level is "Department Manager – Grade 5"**. | decided | W-14 changes: scope rule reads the HR job level (column to confirm in `hr`), not `direct_manager_id`. |
| D-66 | Inactive center: **suspend everything linked** by default (tasks, reminders, alerts), except tasks that must still go out, e.g. reports. | decided | W-02 changes: besides the badge, an inactive center pauses the project's non-report tasks and scanner rules; `TaskKind::Report` keeps firing. Nothing is closed automatically. |
| D-67 | Short report as proposed, plus a **brief narrative per department** in a fixed slot for follow-up. | decided | W-23 = targets figures + one short narrative field per department. Leaves "Later" only if the 29 Oct meeting wants it in this wave. |
| D-61 | **Risks & Assumptions inside the logframe.** Risks move from projects to results; what is the library? | `risk_library` (MEAL-managed, like `evidence_types`) seeded from Rami's list; `risks.result_id` + `risks.proposal_id`, `project_id` nullable. "Add Result" is replaced by "Start from library / program framework" (T32's button) and each result gets a Risks & Assumptions drawer. Standalone Risks page and sidebar entry removed. | W-09 |
| D-62 | **City and center on the proposal.** The proposal links projects (= center + program). Does "city required, center optional" mean a city pivot on the proposal? | Yes: `proposal_city` pivot (≥ 1 required at creation); `projectIds` becomes optional at creation and required at `concept note review` (already a `linked_project` key). Center multi-select = the project multi-select filtered by chosen cities. | W-10 |
| D-63 | **Stage tasks.** Which tasks per status, for which department? | A `proposal_stage_task_templates` table (status → department, kind, title, due offset), seeded by PR Manager; a listener on `ProposalStatusChanged` creates them. Start with: `proposal review` → Media, Finance, MEAL review tasks; `approved` → final report task (replaces the manual button). | W-12 |
| D-64 | **Reporting as a task.** Create a `Task` per donor report assignment? | Yes: `TaskKind::Report`, `tasks.reportable_type/id` (DonorReport, later MonthlyReport). Created when `responsible_employee_id` is set; closed by "mark submitted". The desk Tasks panel then shows it with no new panel. | W-13 |
| D-65 | **Department manager.** Who counts as the manager of a department for the Tasks panel scope? | An employee whose HR position is the `direct_manager_id` of ≥ 1 active position in that department, or a `Managers` / GM role holder. Read-only from HR; no new column. | W-14 |
| D-66 | **Center status.** What happens to a project when HR deactivates its center? | Badge "Center inactive" on project and proposal rows; no automatic close. The project is excluded from the open-project rule only when `closed`/`archived`. | W-02 |
| D-67 | **Short donor report fields.** Which figures go into the "targets only" semi-annual view? | Per indicator: target, period figure, cumulative, achievement %. No narratives, no finance. | W-23 |

### Management rulings · 28 Oct 2026 (next meeting Thu 29 Oct, 10:00)

| # | Ruling | Status | Effect on the plan |
|---|---|---|---|
| D-55 | A project in a city with no HR center (Idleb) gets a **location** (city) plus an **assigned center** (e.g. Head Office Aleppo) and an **assigned responsible person** (e.g. George Yashou for the Wells project), who can be changed later. | decided | W-01 as proposed. The responsible person is the existing project owner (`projects.user_id`, editable in the form); no new column. |
| D-55b | **Payroll proposal.** Volunteer salaries and equipment of a center (e.g. Micro) are not budgeted inside each donor's proposal. They get their own proposal kind: narrative, donor, begin/end date, attachment. Finance supplies the budget before it is sent; it has only a final report. | decided (fields to confirm) | New item **W-32** [contract]. Confirmed 29 Oct: the donor gets the **final report only**, no periodic reports. |
| D-56 | Internal number format | pending | Rita presents the proposal to Shadi. W-03 waits. |
| D-57 | New donor during a proposal: (1) the proposal **cannot be saved** while the donor is pending; (2) a donor created by Garo must be accepted by Freddy, so it stays a **pending donor request**. | decided | W-04 changes: the form shows "donor request pending" and blocks save, instead of allowing save and blocking the first transition. Approver = Freddy = CEO, i.e. the **General Manager** role (confirmed 29 Oct). |
| D-58 | "Submitted (On Hold)" | decided | W-06 as proposed. |
| D-59 | Negotiation is **optional**: attachments + narrative, with a notification that lists pending actions. | decided | W-07 as proposed + a notification key `proposal.negotiation_noted` to reviewers with the open actions. |
| D-60 | Extension as proposed, plus: when a project is **ending soon and money remains**, notify Shadi, who asks Garo to request the extension. | decided, more discussion pending | W-08 as proposed + new alert **W-33**. Thresholds (days before end, remaining amount) to be set at the next meeting. |

---

## 2. Work items

### Phase 1 · Fixes and small UI (no contract change) — days 1–3, Team B

| # | Item | Where | Size |
|---|---|---|---|
| W-15 | **"Go to → Programs" opens Projects.** `programs.show` is a sheet over `projects.index?program=X`; closing lands on the filtered projects. Change the register behind the sheet and `closeUrl` to `programs.index`; update `RecordSheetTest`. | `pages/programs/⚡show.blade.php:253` | S |
| W-17 | **Back button.** Add a `dk-back` to `x-ui.detail-header` and the project story header that uses the referrer when it is inside the app, else the register route. | `components/ui/detail-header.blade.php`, `projects/_story-header.blade.php:16` | S |
| W-18 | **Targets behind rows clickable** → indicator and project links. | `desk/panels/⚡targets.blade.php:78-83` | S |
| W-19 | **Project card: dates first.** Make "Dates" the default lens and always show start → end under the name. | `desk/panels/⚡projects.blade.php:185-249` | S |
| W-11 | **Customize sheet hides locked panels** (no lock list); keep `desk.nothing_to_add`. | `pages/desk/_customize-sheet.blade.php:54-59` | S |
| W-20 | **Scroll trap.** Verify in the browser; `.dk-pb{overflow:auto; overscroll-behavior:contain}` (`desk.css:519`) is overridden by `desk-shell.css:339-354`, so a stale build is the likely cause. Check `.dk-months` and `.dk-mx-wrap` (horizontal, `contain`) and drop `overscroll-behavior-x: contain` if the wheel is captured. | `resources/css/desk.css`, `desk-shell.css` | S |
| W-21 | **Add indicator from the project page.** Button in the Targets tab → `indicators.index?create&project=` with the project preset in the form modal. | `projects/_show-targets-tab.blade.php`, `indicators/⚡index.blade.php:66-73` | S |
| W-16 | **Reports entry.** Rename "Donor Report Builder" → "Reports", add `donor-reports.index` (list of all donor reports, filters: donor, proposal, state), sidebar entry under M&E; duties panel title "Reports". | `routes/web.php:180-204`, `Destinations.php:148`, new `pages/donor-reports/⚡index.blade.php` | M |
| W-05 | **Final report acceptance UI.** Button on the proposal Reports/Status tab calling `RecordFinalReportAcceptance` (date, by, optional acceptance file). Without it `active → completed` is unreachable. | `proposals/_show-actions.blade.php`, `_show-modals.blade.php` | S |
| W-22 | **Stale docs.** Correct `.ai/rules/desk.md` and `docs/redesign-plan/tracking-desk.md` (sidebar is back, Risks panel gone). | docs | S |

### Phase 2 · Proposal flow [contract] — days 3–9, Team A (Dev 1 + Dev 2)

| # | Item | Depends on | Size |
|---|---|---|---|
| W-03 | **Auto internal number.** `ProposalNumbers::next()` under `lockForUpdate`, read-only field, GM override. Backfill `TEMP-*` rows. | D-56 | M |
| W-04 | **Inline donor creation with pending approval.** Modal in the proposal form; `donors.approval_status`; the donor stays a **pending request** until a **General Manager** (Freddy, CEO) approves it on the Donors page; the proposal form shows "donor request pending" and **refuses to save** until then (D-57 ruling). | D-57 | M |
| W-10 | **Form order and location rows.** Order: name, number (auto), donor (+ add), **location rows** (`is_new` · city* · program · reporter · centers · ➕, D-62), concept-note text or file, planned dates, budget (money holders), starting stage, **notes last**. No `projectIds` picker; rows optional fields become required at `approved` (W-34). | D-62 | M |
| W-24 | **Concept note content.** `proposals.concept_note` text + `MediaCollection::ConceptNote`; shown on the Status tab for the ideation phase with title, donor, created date. | — | S |
| W-06 | **Submitted (On hold) merge.** Flag instead of status; transitions table shrinks (hold rows removed, `set_on_hold` / `clear_on_hold` actions); data migration `on hold → submitted + flag`; pickers, charts, board columns updated. Guard tests. | D-58 | L |
| W-07 | **Negotiation notes.** `proposal_status_notes.kind`, "Add negotiation note" on the Status tab (text + optional file), allowed in `submitted` only. | D-59 | S |
| W-08 | **Extension.** `ExtendProposal` action + modal (new end date, reason, file). Widens project end date, fires `ProjectPeriodChanged`, writes a status note (`kind = extension`). Shown as "Extended to …" next to the original end. | D-60 | M |
| W-12 | **Stage task templates.** Table + seeder + listener + admin page (PR Manager). The manual "Add final report" button becomes the `approved` template. | D-63 | M |
| W-25 | **Remove `Rejected` shortcut confusion.** Nothing to build: `Approved → Active → Completed` or `Rejected` already matches §2. Only the UI stepper (`_show-lifecycle.blade.php`) should draw the merged line. | W-06 | S |
| W-32 | **Payroll proposal kind.** `proposals.kind` (`project` default, `payroll`). Payroll: narrative, donor, begin/end, attachment; no projects link, no logframe, no targets, no monthly reports; lifecycle draft → (Finance enters budget, requirement key `budget`) → submitted → approved → active → completed with the final report only. Hidden from the M&E aggregations. | D-55b | L |
| W-33 | **Extension alert.** Scanner rule in `DeadlineCatalog`: active proposal ending within N days whose agreement has remaining funds (money holders only) → notify Shadi's slot; "Request extension" action for Garo opens the W-08 modal. | D-60, W-08 | M |
| W-34 | **Proposal location rows + tracking-owned programs.** `proposal_locations` (proposal_id, city_id, program_id?, responsible_employee_id?, is_new) + `proposal_location_centers`. Required at `approved` (requirement key `locations_complete`). On `approved` the writer creates the missing programs/projects from the rows (W-01 rules). `programs` becomes a local table seeded from HR; HR lookups become read-only mirrors. | D-62, W-10, W-01 | XL — spec: [W-34.md](W-34.md); W-34b (local programs) waits for D-68 |
| W-35 | **Reporter retired alert.** Scanner rule: a location row or project whose responsible employee is inactive in HR → notify the proposal owner + PR Manager, "Reassign" action opens the row. | D-62, W-34 | S |

### Phase 3 · Centers and projects [contract] — days 5–10, Team A

| # | Item | Depends on | Size |
|---|---|---|---|
| W-01 | **Host center.** `projects.host_center_id` (HR id, nullable), `center_id` nullable stays, `city_id` required. `SaveProject` rule: physical center **or** host center. `openProjectFor()` keyed on `center_id ?? city_id`. Badges "Hosted by Micro Aleppo". | D-55 | M |
| W-02 | **"Add center" form.** Rename the project form to "Add center": city, program, physical center (optional), host center (required when none), owner, sectors. Project name defaults to "Program — Center/City". Run `hr:map-project-centers --apply` first so old projects stop showing "No center" in the proposal picker; then show the inactive-center badge and **pause** the project's non-report tasks and scanner rules while the center is inactive (D-66 ruling; report tasks keep firing). | W-01, D-66 | M |
| W-26 | **Projects page simpler.** Every chapter folded with a toggle except the current one; the embedded proposal page (chapter 2) becomes a summary + link; drop the duplicated facts (beneficiaries, funding, donor shown twice); side column collapsible. | — | L |

### Phase 4 · Logframe, risks, tasks, desk additions — days 8–15, Team B

| # | Item | Depends on | Size |
|---|---|---|---|
| W-09 | **Risks & Assumptions in the logframe.** `risk_library` + admin page (MEAL); `risks.result_id`, `proposal_id`; a library-only picker drawer (no free-text risk, D-61 ruling) per result node in `components/proposals/⚡logframe.blade.php`; "Add Result" → "Start from program framework / library"; remove `risks.index` from the sidebar and the project Risks tab (keep the route for history for one release). Risks heat map moves to the proposal page. | D-61 | L |
| W-13 | **Report tasks.** `TaskKind::Report`, `reportable` morph, created on donor-report assignment, closed on submit; Tasks panel row links to the report. | D-64 | M |
| W-14 | **Tasks panel scope.** `mine` = assigned to me + created by me (already); `department` offered only to employees whose HR job level is "Department Manager – Grade 5" (D-65 ruling; confirm the `hr` column) ; `team` kept. Same rule on `department-tasks`. | D-65 | S |
| W-27 | **To-do and Notes panels.** Two local tables (`user_todos`, `user_notes`, owner = user), two desk panels (`todo`, `notes`), allowed for everyone, sizes S. Plain text, no sharing. | — | M |
| W-28 | **Unified status colors.** One `StatusTone` enum (good / warn / critical / active / neutral) and a `HasStatusTone` concern on every status enum; `enum-badge`, `FluxMarkup::statusPill`, calendar and board maps read `tone()`. Critical gets its own token (not the brand red). Fix the listed inconsistencies (TaskStatus, MilestoneStatus Ongoing/Postponed, RagStatus in enum-badge, ProjectStatus Approved). | — | M |
| W-29 | **Sections and borders.** One recipe: `x-ui.panel` and `.dk-panel` share radius (12px) and border token; replace the 43 raw bordered divs by `x-ui.panel` or a new `x-ui.section` (borderless, uppercase heading). | — | L |
| W-30 | **"Main tabs not in the sidebar".** Needs Mai to show the case: the desk has no tabs; the rail collapses group labels. Likely answer: show group headings in the collapsed rail tooltip, or pin the sub-pages (Monthly matrix, Templates) under Reports. Not sized until clarified. | Mai | ? |

### Later (not in this wave)

| # | Item | Why later |
|---|---|---|
| W-31 | Leave substitute: tasks with a deadline move to a backup employee while the assignee is on leave. | HR has no leave data readable from here; needs an HR column or a local "acting for" table and a scanner. Design with HR first. |
| W-23 | Short semi-annual report layout: targets figures + one short narrative per department (D-67 ruling). | The aggregation exists; pull into this wave only if the 29 Oct meeting asks. |

---

## 3. Order and dependencies

```
Decisions D-55…D-67 (Dev 1, day 0-1)
  ├─ Phase 1 fixes (B, days 1-3)            no decision needed
  ├─ Phase 2 proposal flow (A, days 3-9)    W-04 → W-10 → W-24 → W-06 → W-07 → W-08 → W-12 → W-32 · W-33 ; W-03 waits for D-56
  ├─ Phase 3 centers (A, days 5-10)         W-01 → W-02 → W-34 → W-35 → W-26 ; W-34 also needs W-10
  └─ Phase 4 (B, days 8-15)                 W-09 · W-13 · W-14 · W-27 · W-28 · W-29 · W-30
```

Rules carried over from `AGENT.md`: one branch per item (`wave2/W-NN-slug`), test first, status writes only through the §4 writers, HR read-only, Pint before finishing, a handoff file per item in `docs/lifecycle/wave2/handoffs/`.
