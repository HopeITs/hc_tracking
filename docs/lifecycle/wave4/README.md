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
| D-75 | **A place = city + program + center.** Same city, same program and same center is one place. | Signed | Y-02 |
| D-76 | **The template is the reporting unit.** A template belongs to one place and to one or more proposals. One monthly report per template per month. | Signed | Y-02, Y-03 |
| D-77 | **MEAL chooses on approval.** Either join the new proposal to a template that already exists at the same place and pick which of its indicators are added (the center manager keeps entering one report), or create a new template (the center manager enters two reports that month). | Signed | Y-04 |
| D-78 | **A template holds** narrative questions, the indicators its proposals require, and the activity calendar. The calendar belongs to the proposal and covers its implementation phase. | Signed | Y-06, Y-07 |
| D-79 | **Extraction** filters together by proposal, city, program, center and date from / to, on screen and in the export. | Signed | Y-05 |
| D-80 | **Centers are entered by hand** on the proposal's location rows. No migration guesses a center. | Signed | Y-01 |
| D-81 | **Wave 3's D-69, D-69a, D-69b and prompt X-02 are withdrawn.** They key the report on city × program with centers as a list and one figure per project, the opposite of D-75 and D-76. X-01 (naming per audience) loses its dependency on X-02. *Recommended: withdraw.* | **Open** | Y-02 |
| D-82 | **Existing data with no center yet.** *Recommended:* a migrated template keeps `center_id` null ("center not set") and stays one template per existing project; MEAL cannot join another proposal to it until its center is set; nothing is merged automatically. | **Open** | Y-02, Y-03 |
| D-83 | **Editing location rows after approval.** *Recommended:* holders of `proposals.update` may add or change centers on an approved or active proposal with a reason (≥ 10), logged as `proposal.locations_changed`. Removing a center that already has a template is refused. Adding a center does not create a project. | **Open** | Y-01 slice 2 |
| D-84 | **Who prepares and who validates** a template's report now that it is not a project. *Recommended:* preparer = the responsible employee of the template's location rows (and their supervisors); validator rule unchanged, read through `PlaceBridge` until coverage moves to places in a later wave. | **Open** | Y-03 |
| D-85 | **Per-center targets** still carry `plan_indicators.project_id`. *Recommended:* unchanged in wave 4; a line's place is resolved through `PlaceBridge`. Moving targets to places is its own later task. | **Open** | Y-02 |
| D-86 | **Joining and periods.** When a joined proposal ends, its lines retire (`RetireReportTemplateLine`, already month-exact); the template lives while any of its proposals is active. Leaving a template after joining is not offered. *Recommended as written.* | **Open** | Y-04 |

---

## 2. Work items

| # | Item | Depends on | Size | ClickUp |
|---|---|---|---|---|
| Y-01 | **Location rows with centers in the proposal form.** Finish W-34a slice 3 (rows UI, no project picker), let rows be edited after approval, list the proposals still missing a center. | D-80, D-83 | L · 3 slices | [task](https://app.clickup.com/t/123jdbat8bp) |
| Y-02 | **Template independent of `projects`.** A template carries its place and its proposals; two templates may share a place. | Y-01, D-75, D-76, D-81, D-82, D-85 | L · 2 slices | [task](https://app.clickup.com/t/123jdbat8bq) |
| Y-03 | **Monthly report follows its template.** One report per template per month; every monthly-report action reads the template. | Y-02, D-84 | XL · 3 slices | [task](https://app.clickup.com/t/123jdbat8br) |
| Y-04 | **MEAL joins a template or creates a new one.** | Y-02, Y-03, D-77, D-86 | L · 2 slices | [task](https://app.clickup.com/t/123jdbat8bt) |
| Y-05 | **Extraction: combined filter and export.** The meeting's main priority. | Y-01, Y-03, D-79 | L · 2 slices | [task](https://app.clickup.com/t/123jdbat8bu) |
| Y-06 | **Activity calendar in the template and the report.** | Y-02, D-78 | M · 1 slice | [task](https://app.clickup.com/t/123jdbat8bv) |
| Y-07 | **MEAL edits the template's narrative questions.** | Y-02, D-78 | M · 2 slices | [task](https://app.clickup.com/t/123jdbat8bw) |

Order: Y-01 → Y-02 → Y-03 → (Y-04 ‖ Y-05) → Y-06 → Y-07. Y-01 slice 1 touches only the proposal form and can start today; everything from Y-02 on changes `[contract]` §12.4 and §13 and waits for D-81, D-82, D-84 and D-85.

Between Y-01 and Y-02 there is a **human step**: the team enters the centers of the 36 existing proposals through the form. Y-01 slice 3 gives them the list.

---

## 3. What wave 4 does not do

- Drop `projects`, `project_proposal`, or any `project_id` column.
- Move per-center targets, MEAL coverage, milestones, tasks or risks off `projects`.
- Change the donor report (`DonorReport`) or its aggregation, beyond reading the new filters where Y-05 says so.
- Change `MaterialiseProposalLocations`: an approval still creates its projects, so every module outside reporting keeps working.
