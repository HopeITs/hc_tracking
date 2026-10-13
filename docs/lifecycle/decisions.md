# Decisions: final answers (plan revision 9, workshop 12 Oct 2026)

Final answers only. Earlier revisions are in `CHANGELOG.md`. **Status:** `decided` = management answered; `default` = the recommended default was accepted and can be revisited at a checkpoint. In code, everything binds to **roles, slots or settings, never to people's names**.

| # | Question | Answer | Status | Tasks |
|---|---|---|---|---|
| D-01 | Board approval before sending to donor? | No board gate. Internal review is enough; a **CEO** slot joins the default reviewers. | decided | 04, 07 |
| D-02 | Keep the `final reports` status? | **Removed.** The final report is a milestone (`is_final_report`, added from a template, T31). Legacy rows move `final reports → active` (data migration in T04, rehearsed in T27). | decided | 04, 27, 31 |
| D-02c | What closes a proposal? | `active → completed` needs: the final-report milestone complete, the final report file (`media` category `final_report`), and `final_report_accepted_on` + `final_report_accepted_by` recorded by a `proposals.update` holder. The acceptance email (`final_report_acceptance`) is optional. Self-funded: milestone + file only. **Correct Status** may override with a reason. | decided | 04 |
| D-03 | Can the concept note be skipped? | Yes, with a written reason (preset «المقترح موجود مسبقًا»). | decided | 04, 06 |
| D-04 | When does the project start? | Grant start = the proposal's `planned_date_begin`, moved to the approval date if approval is late, or later with a reason. | decided | 08 |
| D-04b | When does the project become active? | When MEAL confirms the monthly report template. Monthly reports start at the month of `actual_date_begin`. | decided | 08, 33, 17 |
| D-05 | Dates with several approved proposals? | Earliest start, latest end; never shortened. One Gantt per proposal plus an overall one. In a funding gap the project stays active and monthly reports continue. | decided | 08, 17 |
| D-06 | Project whose only proposal is rejected? | The proposal is archived as rejected; the project stays draft with a "No active proposal" badge; archiving is manual. No auto-archive. | decided | 05 |
| D-07 | When do monthly numbers count? | When MEAL **validates** (Confirm). Locked for the manager after submit. Return needs a note. | decided | 17 |
| D-08 | Monthly ordering | Blocks **submitting** month M while M-1 (with lines) is unsubmitted; opening any generated month is allowed. | default | 17 |
| D-09 / D-09a | Who decides a reopen request? | The **MEAL Manager** slot holder. If the slot is empty or its holder is inactive in HR: active **Public Relations Manager** holders. If neither: the request stays pending and the General Manager is notified. The preparer never decides their own request. | decided | 02, 17 |
| D-10 / D-10a | Indicator code | Required, server-generated `PROGRAM.LEVEL.SEQ` (e.g. `002.OP1.2.3`), unique org-wide; `HC` prefix for org-wide indicators. HR must first fix duplicated program codes (`003`, `004`, `009` locally). | decided | 11, 30 |
| D-11 | Library seeding and renewal targets | Seed the library from existing indicators (conflicts listed). Last year's targets are suggestions only on renewal. | default | 10, 11, 13 |
| D-12 / D-12a | MEAL officer scope | One officer per governorate (city), optional per-project officer (may be from another governorate) overrides; fallback the MEAL Manager slot. Routing: `MealCoverage::responsibleFor($project)` = project officer → governorate officer → MEAL Manager. | decided | 15, 17 |
| D-13 | Supervision source | HR direct manager (`positions.direct_manager_id`); a local table holds exceptions and additions only. | default | 23 |
| D-14 | Default reviewers | Slots: MEAL Manager, Finance Manager, Projects & Fundraising Manager, CEO, plus each linked project's owner and program manager. Slots resolve to HR positions. | default | 07 |
| D-15 | Must every reviewer see it before sending? | No. A warning lists who has not seen it. | default | 07 |
| D-16 | What does Media see? | A no-money view of its department tasks. | default | 31 |
| D-17 | Budget lines by category | Postponed (November plan, T25). | decided | 25 |
| D-18 | Annual plan workflow | Postponed to early 2027 (T24). | decided | 24 |
| D-19 | Encryption scope and key | All uploaded files; separate `FILE_ENCRYPTION_KEY`, backed up off the server. | default | 22 |
| D-20 / D-20a | Who corrects final statuses? | **General Manager and Public Relations Manager**, with a mandatory reason; the note is flagged `is_correction`. **Super Admin is for developers only**: it keeps every permission technically, but is never in a business default list, never a fallback decision-maker, and never the only account a test uses. | decided | 02, 04, 28 |
| D-21 | Donor report delivery | The final file is required on delivery; a delivered report is frozen. | default | 19 |
| D-22 | Working week | Mon–Fri by default, plus optional per-project working days. Holiday events go out the next working day. | decided | 17, 19, 21 |
| D-23 | Who enters targets? | MEAL only (`targets.create/update` revoked from General Manager and Managers). | decided | 02, 13 |
| D-24 | Multi-program proposals, co-funders | Multi-program allowed (no program, "multi-program" badge). A co-funder is a separate proposal. | default | 03, 12 |
| D-25 | Combining numbers | Counts sum. For percentages the manager enters the **count only**; % = count ÷ `project_proposal.target_beneficiaries` (fixed denominator). Ratios MEAL measures (pre/post tests, surveys) take numerator + denominator. Pooling is Σnumerator ÷ Σdenominator, never an average of percentages. Unique people: enter only new people each month. Cumulative: last approved value. `target_beneficiaries` is required on every linked project from `proposal review` onward. | decided | 03, 04, 06, 11, 17, 18, 19 |
| D-26 | Agreement needed to approve or activate? | No. Approval needs donor-approval evidence: a file (`media` category `donor_approval`) **or** a note (`proposals.approval_note`, min 10). A warning "No agreement recorded yet" shows until one exists. | decided | 08 |
| D-27 / D-27a | Restore from archive; self-funding | Restore → `closed` if the project has dates, else `draft`, only if no other open project exists for that center + program. One internal donor «تمويل ذاتي — Hope» (`donors.is_internal`). Its agreement is optional, it generates no transfers, and it is excluded from donor KPIs and donor reports. | decided | 03, 05, 09, 19 |
| D-28 | When is funding official? | At donor approval, not at submission. `submitted` shows "Awaiting funding". | default | 05, 08 |
| D-29 | When are monthly reports generated? | All months at activation. Projects already active at launch get months from the deploy month (Oct 2026), never retroactively. | default | 17 |
| D-30 / D-30a / D-30c | Project = center? | A center (HR building) hosts several projects. **One open project (draft/approved/active) per (center_id, program_id)**, checked server-side under a lock. The owner prepares its monthly report. HR adds centers for cities 7, 8, 12, 13, 14, 17 before the rehearsal; otherwise `center_id` stays NULL with a warning. | decided | 05, 10, 30 |
| D-31 / D-31a / D-31b | Generated transfers | One expected `transfers_tracking` row per installment of the schedule (`funding_agreement_installments`), which PR and Finance enter together. Four dates: `scheduled_date`, `transfer_date`, `arrival_date`, `receive_date` (transfer ≤ arrival ≤ receive). Commission = `fees`. Public Relations Manager gains `funding-agreements.view/create/update` (not delete). | decided | 02, 09 |
| D-32 | Fixed donor report schedules? | No. On-demand aggregation with a manual due date. | default | 19 |
| D-33 | Department tasks | Assigned to a department first (HR); a person is optional until a week before the due date, then an alert. | default | 31 |
| D-34 | Proposal milestones per center? | Shared at proposal level (a center-specific one carries a `project_id`); shifted once on approval by (actual − planned start); dates a user already edited are left alone. | default | 31 |
| D-35 / D-35a | Programs → pillars | Pillar 1 economic empowerment & family support: Micro, Relief, Family to Family, Wells, Alternative Energy. Pillar 2 youth & students: Study Zone, LEAP, Entrepreneurship, DISC. Pillar 3 childhood & positive parenting: Children University, PSS, Case Management, Parenting. HR adds the missing programs. DISC = Development Impact and Support Center. | decided | 32 |
| D-36 | (replaced by D-38) | — | — | — |
| D-37 | Micro framework and target symbols | Adopted. `0.8` → 80 %; `-` → tracking only; `**` → set per project; `+20%` → 20 % change from baseline. | default | 32 |
| D-38 | What defines monthly report lines? | **MEAL's per-project template** (T33): library indicators only, each line with its own schedule (library default, monthly, selected months, or once after N months). | decided | 33, 17 |
| D-39 | When is a project active? | After approval + owner acknowledgement it is `approved`; it becomes `active` when MEAL confirms the template. | decided | 05, 08, 33 |
| D-40 / D-46 | Same indicator, two donors | Per program `programs.donor_attribution`: `shared` (default; e.g. DESC, 30 students count fully for each donor; one line; target = the higher one) or `split` (e.g. Micro, 10 = 6 + 4; the manager enters each donor's share; center total = sum; target = sum). One funder per beneficiary under `split`. | decided | 13, 17, 18, 19 |
| D-41 | New proposal on an active project | Its lines appear only within its own period, after MEAL confirms them. The manager is told "new indicators for proposal X, from month A to month B". Nothing retroactive; no report is reopened. | decided | 33, 17 |
| D-42 | Supporting files | A fixed type list managed by MEAL (`evidence_types`); the required types per line block submission. | decided | 33, 17 |
| D-43 / D-43a | Narrative questions | An org-wide bank (monthly + quarterly) copied into each project's template, editable by MEAL; reports keep the question text. | decided | 33, 17 |
| D-44 | Org-wide indicators | A yearly target per center via the program's annual plan; the plan is auto-created for 2026 only. | decided | 33 |
| D-45 | Already-active projects at launch | They stay active; a pre-filled template waits in MEAL's queue; their lines appear after confirmation. | decided | 33, 27 |
| D-47 | Sectors | Starting list: Health · Food security & livelihoods · WASH · Shelter & NFIs · Protection · Education. At least one is required for new projects. Linked to projects only; managed by MEAL and GM; used sectors are deactivated, not deleted. | decided | 34 |
| D-48 / D-48a | MEAL-only indicators | Per line `entered_by` = `project_team` or `meal`. A MEAL line is read-only for the manager and doesn't block submit; it blocks **Confirm** until MEAL fills it or marks «لم يُقس هذا الشهر» during review. | decided | 33, 17 |
| D-49 / D-49b | Activity calendar | Logframe activities only; month + week precision (the report shows planned weeks); the actual date is entered in the report; MEAL confirmation updates the status. | decided | 12, 17 |
| D-50 | Disaggregation | By sex (male/female), and persons with disability per sex; the target stays a total; set per indicator by MEAL in the library; no age groups yet. | decided | 11, 17 |
| D-51 | Activity statuses | not_started, started, ongoing, suspended, cancelled, completed, conducted. Type `continuous` (default) or `event`. Unfinished ones roll over to the next month. | decided | 12, 17 |
| D-53 / D-54 | Cohorts; real Excel files in rehearsal | No cohorts. The rehearsal uses seed data; the Syria Cross and Children University files are design references only. | decided | 27 |

## Owner hand-offs
- **HR team, before day 5:** fix duplicated program codes (D-10a); add the missing programs (D-35a).
- **HR team, before the rehearsal (27 Oct):** add the missing centers (D-30c); confirm `positions.direct_manager_id` (D-13).
- **MEAL, before day 5:** monthly template content, evidence types, narrative-question bank, and each program's `donor_attribution` mode; review the Micro JSON files (T32).
- **Management:** sign the permission matrix (`contracts.md §8`).
