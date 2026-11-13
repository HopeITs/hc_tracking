# Wave 4 · Monthly reports rework (MEAL meeting, 11 Nov 2026)

Status: **draft plan**. Written on 12 Nov 2026 from the reports meeting of 11 Nov (MEAL side), Dev 1's rulings in chat the next day, and a read of `main` (`1660aef9`). `contracts.md` and `decisions.md` stay the sources of truth: every item marked **[contract]** needs its D-number signed in §1 before code.

ClickUp parent: https://app.clickup.com/t/123jdbat8b0

Size: S ≤ ½ day · M 1–2 days · L 3–5 days · XL 5+ days.

---

## 0. What the meeting said, and what the code does today

| # | Meeting note | Today on `main` |
|---|---|---|
| 1 | When a proposal is approved, MEAL is asked to prepare the shape of the monthly report. | Done. `PrefillReportTemplates` creates the template as `pending` and notifies MEAL; `ConfirmReportTemplate` activates the project. |
| 2 | The report holds narrative questions, the indicators the proposal requires, and the activity calendar. | Indicators: editable lines. Questions: copied from the bank, **read-only** on the template tab, no action changes them. Calendar: `activity_schedules` exists per proposal (with a per-project override) but is not shown as part of the template. |
| 3 | One report can be the model for more than one proposal, entered once by the project manager. | Happens **always and automatically**: one template per project (`report_templates.project_id` unique), one line per library indicator, one share per covering target. |
| 4 | MEAL may add a new proposal to an existing report and pick the extra indicators; or build a new template, and then the center manager enters two reports a month. | No choice exists. A second template at the same place is impossible. |
| 5 | Each center has its own report. | A project is one center (`projects.center_id`), one `monthly_reports` row per project and month. |
| 6 | Reports are filtered together by proposal, governorate, program, center and date from / to. | `reports/monthly-matrix`: proposal, donor, city, center (a project id), indicator, pillar, year. `monthly-reports`: city, project, month, status. **Program and from / to are missing.** |
| 7 | "A project is the proposal once approved." The `projects` table stays for now, but nothing new may rely on it. | Templates, monthly reports, per-center targets, MEAL coverage and the preparer rule all read `projects`. |

Two findings that shape the order:

- **No center is entered anywhere.** Local: `projects.center_id` and `host_center_id` are null on all 36 projects, `proposal_location_centers` has 0 rows. Dev 1 confirmed production is the same. A place cannot be named until centers are entered.
- **There is no screen to enter a center.** The proposal form still uses the project picker (`projectIds`). `SaveProposal` accepts `locations[]` with `center_ids`, but no UI sends it: **W-34a slice 3 is unfinished** (`wave2/handoffs/W-34a.md`).

---

## 1. Decisions

`Signed` = ruled by Dev 1 in chat on 12 Nov 2026. `Open` = needs a ruling; a prompt that names an open D-number stops at Start state.

| # | Decision | Status | Blocks |
|---|---|---|---|
| D-74 | **`projects` stays, nothing new depends on it.** No table is dropped and no `project_id` column is removed. Code written in wave 4 adds no foreign key to `projects` and no new read of `projects` or `project_proposal`. Where an existing table still needs the project (per-center targets, coverage), the read goes through one bridge class, `App\Support\Reporting\PlaceBridge`, and nowhere else. | Signed | all |
| D-75 | **A place = city + program + center.** Same city, same program and same center is one place. A program that runs across a city with no center of its own is the **city-wide** place of that city and program (D-88). | Signed | Y-02 |
| D-76 | **The template is the reporting unit.** A template belongs to one place and to one or more proposals. One monthly report per template per month. | Signed | Y-02, Y-03 |
| D-77 | **MEAL chooses on approval.** Either join the new proposal to a template that already exists at the same place and pick which of its indicators are added (the center manager keeps entering one report), or create a new template (the center manager enters two reports that month). | Signed | Y-04 |
| D-78 | **A template holds** narrative questions, the indicators its proposals require, and the activity calendar. The calendar belongs to the proposal and covers its implementation phase. | Signed | Y-06, Y-07 |
| D-79 | **Extraction** filters together by proposal, city, program, center and date from / to, on screen and in the export. | Signed | Y-05 |
| D-80 | **Centers are entered by hand** on the proposal's location rows. No migration guesses a center. | Signed | Y-01 |
| D-81 | **Wave 3's D-69, D-69a, D-69b and prompt X-02 are withdrawn.** They key the report on city × program with centers as a list and one figure per project, the opposite of D-75 and D-76. X-01 (naming per audience) loses its dependency on X-02. Withdrawn. | Signed (13 Nov) | Y-02 |
| D-82 | **Existing data with no center yet.** *Recommended:* a migrated template keeps `center_id` null ("center not set") and stays one template per existing project; MEAL cannot join another proposal to it until its center is set; nothing is merged automatically. Ruled as recommended. "Center not set" means **missing**; it is not the city-wide place of D-88. | Signed (13 Nov) | Y-02, Y-03 |
| D-83 | **Editing location rows after approval.** *Recommended:* holders of `proposals.update` may add or change centers on an approved or active proposal with a reason (≥ 10), logged as `proposal.locations_changed`. Removing a center that already has a template is refused. Adding a center does not create a project. **Ruled: fill-empty only.** Once the proposal is approved, a row that has **no center and is not city-wide** may be filled once, by holders of `proposals.update`: either centers are added, or the row is marked city-wide (D-88). This fill changes or removes nothing and asks no reason. Every other change after approval (a center changed or removed, city, program, responsible, or any other field of the proposal) is a **change request** (D-89), never a direct edit; no project is created. Logged as `proposal.locations_changed`. | Signed (13 Nov) | Y-01 slice 2 |
| D-84 | **Who prepares and who validates** a template's report. **Preparer** = the responsible employee of the location rows of the template's proposals at its place (and their supervisors): the row's responsible is the project manager and the one who hands in the monthly reports. A joined template whose proposals name different responsibles may be prepared by either. **Validator** rule unchanged, read through `PlaceBridge` until MEAL coverage moves to places in a later wave. A project is the set of its proposals: it does not exist without one, except in self-funding (see D-87). | Signed (13 Nov) | Y-03 |
| D-85 | **Per-center targets** still carry `plan_indicators.project_id`. *Recommended:* unchanged in wave 4; a line's place is resolved through `PlaceBridge`. Moving targets to places is its own later task. **Ruled against the recommendation:** per-center targets move to places within wave 4 (needs its own work item). | Signed (13 Nov) | Y-02 |
| D-86 | **Joining and periods.** When a joined proposal ends, its lines retire (`RetireReportTemplateLine`, already month-exact); the template lives while any of its proposals is active. Leaving a template after joining is not offered. **Overlapping lines:** an indicator required by more than one of the template's proposals is one shared line; it stays while any proposal that requires it is active, and only lines required by the ended proposal alone retire from the month after it ends. Ruled as recommended. | Signed (13 Nov) | Y-04 |
| D-87 | **Self-funding.** A place that carries on without a donor is reported through a proposal whose donor is internal (`donors.is_internal`), so every template still has at least one proposal and the row's responsible is still known. It has location rows, a responsible, a template and monthly reports like any other; only donor reports and expected transfers skip it, as today. | Signed (13 Nov) | Y-02 |
| D-88 | **A program may run in a city without a center.** A location row is either tied to centers or marked **city-wide** (`proposal_locations.city_wide`): no center, and that is its answer, not a gap. A city-wide row is complete (`locations_complete`), is not listed as "missing a center", and gives one place: city + program, city-wide. That place is a real place: it has its template, its targets and its monthly report, and MEAL may join another proposal to it (D-77). A row cannot be both: marking it city-wide requires that it has no center. | Signed (13 Nov) | Y-01, Y-02, Y-08, Y-04, Y-05 |
| D-89 | **Change request.** An approved proposal can still change, but only through a change request: the PR team opens it on the proposal, states what changes and **why** (reason required), and the change is recorded under that name with who, when, the reason and the before / after values. It covers a center changed or removed, a row's city, program or responsible, and any other field of the proposal that is editable after approval today. The one-time fill of D-83 is not a change request. | Signed (13 Nov) | Y-09 |
| D-89a | **A change request needs approval.** The PR team opens it (`proposals.update`); it changes **nothing** until the **PR Manager** approves it (holders of `proposals.approve`, D-70). Before that decision the request is **reviewed** by the Finance Manager and the MEAL Manager (the `finance_manager` and `meal_manager` slots): each records "no objection" or "remarks" with a note. The PR Manager can approve only once both reviews are in, sees them on the decision screen, and still decides alone; a review with remarks does not block. Reject is possible at any time. A slot with no active holder is skipped and recorded as such. Approve applies the change; reject keeps the proposal as it was and asks a reason. One request may be open per proposal at a time. A PR Manager who opens a request may approve it themself: no second approver exists for that role. A change that would move or remove a center whose template already has a **submitted or validated** monthly report is refused when it is opened and again when it is approved. *Ruled against the recommendation (which was: no approver).* | Signed (13 Nov) | Y-09 |

---

## 2. Work items

| # | Item | Depends on | Size | ClickUp |
|---|---|---|---|---|
| Y-01 | **Location rows with centers in the proposal form.** Finish W-34a slice 3 (rows UI, no project picker, centers or city-wide per row), let an approved row that has neither be filled once, list the proposals still missing a center. | D-80, D-83, D-88 | L · 3 slices | [task](https://app.clickup.com/t/123jdbat8bp) |
| Y-02 | **Template independent of `projects`.** A template carries its place and its proposals; two templates may share a place. | Y-01, D-75, D-76, D-81, D-82, D-85, D-87, D-88 | L · 2 slices | [task](https://app.clickup.com/t/123jdbat8bq) |
| Y-08 | **Per-center targets move to places.** A target carries its place (city, program, center) next to `project_id`; `PlaceBridge::placeOfTarget` reads it instead of `projects`. | Y-02, D-85, D-88 | L · 2 slices | — |
| Y-03 | **Monthly report follows its template.** One report per template per month; every monthly-report action reads the template. | Y-02, Y-08, D-84 | XL · 3 slices | [task](https://app.clickup.com/t/123jdbat8br) |
| Y-04 | **MEAL joins a template or creates a new one.** | Y-02, Y-03, D-77, D-86 | L · 2 slices | [task](https://app.clickup.com/t/123jdbat8bt) |
| Y-05 | **Extraction: combined filter and export.** The meeting's main priority. | Y-01, Y-03, D-79 | L · 2 slices | [task](https://app.clickup.com/t/123jdbat8bu) |
| Y-06 | **Activity calendar in the template and the report.** | Y-02, D-78 | M · 1 slice | [task](https://app.clickup.com/t/123jdbat8bv) |
| Y-07 | **MEAL edits the template's narrative questions.** | Y-02, D-78 | M · 2 slices | [task](https://app.clickup.com/t/123jdbat8bw) |
| Y-09 | **Change request on an approved proposal.** The PR team asks for a change to an approved proposal (centers, rows, other fields) with a reason; the Finance Manager and the MEAL Manager review it; the PR Manager approves or rejects it; the proposal page lists its change requests. | Y-01, Y-02, D-89, D-89a | L · 2 slices | — |

Order: Y-01 → Y-02 → Y-08 → Y-03 → (Y-04 ‖ Y-05) → Y-06 → Y-07 → Y-09. Y-01 slice 1 touches only the proposal form and can start today; everything from Y-02 on changes `[contract]` §12.1, §12.4 and §13. All decisions D-74…D-89a are signed.

Between Y-01 and Y-02 there is a **human step**: the team gives every row of the 36 existing proposals its centers, or marks it city-wide, through the form. Y-01 slice 3 gives them the list.

---

## 3. What wave 4 does not do

- Drop `projects`, `project_proposal`, or any `project_id` column.
- Move MEAL coverage, milestones, tasks or risks off `projects`.
- Change the donor report (`DonorReport`) or its aggregation, beyond reading the new filters where Y-05 says so.
- Change `MaterialiseProposalLocations`: an approval still creates its projects, so every module outside reporting keeps working.
