# Task 01 — Decisions workshop and sign-off of the corrected story

> **المهمة:** ورشة القرارات وتوقيع القصة المصححة
> **المطوّر:** Dev 1 + Dev 2 (مع مدير MEAL، ومدير المشاريع والتمويل، والمالية، والإدارة) · Dev 3 يدوّن · قادة الفريق B يحضرون الجزء الثاني · **الفريق:** A · **الأيام:** 1–2 (الاثنين 2026-10-12 – الثلاثاء 2026-10-13) · **الجهد:** يوم لكل من Dev 1 وDev 2 + نصف يوم Dev 3، ونصف يوم إضافي لـ Dev 1 لسجل القرارات · **يعتمد على:** لا شيء
> **متى تستخدمه:** في اليوم 1 (الاثنين 2026-10-12) قبل أي مهمة أخرى، لتجهيز حزمة الورشة؛ وسجل القرارات يُغلق في CP0 (نهاية اليوم 2، الثلاثاء 2026-10-13) قبل كتابة أي شيفرة.
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are preparing Task 01 of the HC Tracking proposal-lifecycle correction plan: the decisions workshop that fixes the corrected story before any code is written. You write NO application code. Your outcome is a workshop pack and a draft decision log at `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` covering every decision in plan section 9 (D-01…D-54 and their lettered sub-decisions; there is no D-52), plus read-only data checks that a human runs on production. This matters because the system was built once on undocumented assumptions; every later task reads this log (corrected story: project inside an HR centre, one open project per centre and program, draft without dates → concept note → proposal → donor approval fixes dates and makes the project `approved` (funded) → MEAL confirms the monthly report template → project `active` and monthly reports generated → one agreement and the generated expected transfers; MEAL side: Indicator Library, logframe with Activity and Cross-Cutting levels, per-centre targets, monthly report template, monthly report). Task 04 cannot start until D-02, D-03, D-04, D-05 and D-20 are settled, and Task 17 needs D-07, D-08, D-09 and D-29.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins) (project conventions; you will not change code, but the log must respect them).
2. `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md` — Arabic and authoritative; English table, status, permission and UI names in it are exact. Read sections 0 (including the two teams and phases), 1 (especially 1.0 domain facts), 2, `### Task 01` (including its «تعديلات المراجعة 2» block), section 5 (security matrix), section 7 (integration checkpoints, CP0 in particular), section 9 (decisions D-01…D-54, including «إجابات الجولة الثانية (المراجعة 9)»), the header paragraphs «المراجعة 3» to «المراجعة 9», and the "Business rules" of every task so you can map each decision to the tasks it blocks.
3. `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` — if it exists, update it; never reset a decision already recorded as decided.
4. `.ai/rules/index.md`, then the rules a decision may change: `.ai/rules/proposals.md` (starting status chosen on create), `.ai/rules/funding.md` (agreement on an unapproved proposal is allowed but warned), `.ai/rules/views-components-ui.md` ("nothing under an output"), `.ai/rules/resultsindicatorstargetsreports.md` (achievement counts APPROVED reports only — D-07), `.ai/rules/policies.md` (nine built-in role names), `.ai/rules/pages.md` ("Hide money, keep names"), `.ai/rules/console-commands.md` (never run `RolesAndPermissionsSeeder` on a live database), `.ai/rules/database-migrations.md` (`donor_proposal.proposal_id` RESTRICT), `.ai/rules/alerts.md` (WorkingCalendar and NotificationSettings — D-22).
5. Source to study (read only): `config/access-control.php` (the nine roles and their baselines — base of the permission matrix), `app/Enums/ProposalStatus.php`, `app/Enums/ProposalPhase.php`, `app/Enums/ProjectStatus.php`, `app/Actions/Projects/RestoreProject.php` (restores to draft today — D-27), `app/Livewire/Concerns/ManagesAgreementForm.php` (warning-only approval gate — D-26), `database/migrations/2026_09_04_155826_create_donor_proposal_table.php`.
6. Skills: none required. Use Laravel Boost `database-schema` and `database-query` (read-only, LOCAL dev database only) to re-check the numbers in the plan's assumption 4. Use `search-docs` only if a decision needs framework facts.

## Preconditions to verify
- The plan file exists at the path above; if not, stop and report.
- `docs/superpowers/specs/` exists (it holds earlier design specs).
- You have no production access and must not ask for credentials. Anything that needs production becomes SQL for a human.
- If the decision log already exists with decided rows, list them in your plan and only add what is missing.

## Revision 2 changes (2026-10-08)
- Timeline is now 15 working days (Mon 2026-10-12 → Fri 2026-10-30) with two teams: Team A (Dev 1, Dev 2, Dev 3) and Team B (B1–B4). The workshop is one day (day 1); its second half is attended by Team B leads and walks through sections 0 and 1 and the contracts between the teams (events, tables Team B must not change). The decision log closes at CP0 (end of day 2, 2026-10-13).
- Section 1.0 domain facts are presented for sign-off BEFORE the decisions, because they change Tasks 05, 08, 09, 10, 17, 19, 21, 23, 30 and 31: project = HR centre (one open project per centre); many-to-many funding through proposals; funded = donor approval (D-28); the proposal is a planning container from creation (logframe, indicators, milestones, Media/Finance department tasks); monthly centre reports every month regardless of the donor schedule; dynamic aggregated donor reports (quarterly/semi-annual/annual/custom); auto-generation on activation (monthly reports) and on agreement (expected transfer); notifications to MEAL and General Management for every movement; HR is read-only.
- New decisions D-28…D-34 (funded at approval, monthly report generation, project = centre, generated transfer row, on-demand donor reports, department task assignment/visibility, proposal milestones shared and rebased). D-16 now concerns what the Media team sees in **Department Tasks** (Task 31), D-17/D-18 are out of the October window (Tasks 24/25 deferred), Task 20 is merged into Task 31.
- Requests to the HR team during the workshop: add the missing centres (assumption 4: cities 7, 8, 12, 13, 14, 17 have projects but no HR centre) and confirm `positions.direct_manager_id` is up to date.
- An unsettled decision takes its default after ONE working day (not two); the monthly template and permission matrix are due by day 5 at the latest.

## Revision 3 changes (2026-10-09)
Source: `Stratigy.pptx` (strategy 2026–2030) and `micro logic.xlsx` (the real Micro program logframe, urban and agricultural tracks). Read plan section 1.0 facts 10–12 and the «تعديلات المراجعة 3» subsection of this task. Where this block conflicts with text further down this prompt, this block wins.
- Add to the decision pack: **D-25 (rewritten)**: counts sum; percentage/average = latest approved per target, pooled across centers/donors as Σnumerator ÷ Σdenominator, never an average of percentages. Also **D-35** (HR programs → the three strategic pillars; interventions with no HR program; is DISC = "Development Impact and Support Center"), **D-36** (who enters which indicator and when: center manager in the monthly report vs MEAL in periodic target reports, driven by `collected_by` + `frequency`), and **D-37** (adopt `micro logic.xlsx` as the Micro reference framework; meaning of default targets "-", "**", "+20%"; English names optional).
- Present facts 10–12 of plan section 1.0 for sign-off together with the Revision 2 facts.
- Ask the MEAL manager to review the Micro JSON data files (Task 32) by end of day 5, and to name any other program reference frameworks (Study Zone, LEAP, DISC, Children University) for import after launch.
- Handoff additions: D-25 and D-36 before Task 11/17; D-35 and D-37 before Task 32.

## Revision 5 changes (2026-10-12)
Source: management note on co-funded indicators, recorded as **D-46** (amends D-40). Read plan section 1.0 facts 14 and 18, decision row D-46, and the «تعديلات المراجعة 5» subsections of Tasks 13, 17, 18, 19 and 33 in the plan (Task 01 itself has no Revision 4 or 5 subsection). Where this block conflicts with text further down this prompt, this block wins.
- An indicator shared by several donors in one center follows its program's mode, `programs.donor_attribution` (`App\Enums\DonorAttribution`: `shared`, `split`; default `shared`). `shared` (e.g. DESC: 30 students, one report, 30 for each donor) = D-40. `split` (e.g. Micro: 10 beneficiaries = 6 for donor 1 + 4 for donor 2, a separate report per donor): the center manager enters each donor's share monthly, each donor's mirror carries only its share, and the center's achievement is the sum. A beneficiary has exactly one funder, so there is no separate total field and no reconciliation check.
- Add **D-46** to the decision pack as settled (mode per program; center manager enters shares monthly; one funder per beneficiary) and ask MEAL to name each program's mode before the Task 27 rehearsal.

## Revisions 4 and 6–9 changes (2026-10-12)
Source: plan header paragraphs «المراجعة 4» and «المراجعة 6» to «المراجعة 9», section 9 rows D-38…D-54 and the table «إجابات الجولة الثانية (المراجعة 9)». The workshop was held on day 1 and the log already exists: **update it, never reset a decided row**. Where this block conflicts with any text further down this prompt, this block wins.
- **Decision set:** the log covers D-01…D-54 (no D-52) with every lettered sub-decision (D-02a/b/c, D-04a/b, D-09a/b, D-10a/b, D-12a, D-20a, D-25a/b, D-27a, D-30a/b/c, D-31a/b, D-35a, D-43a, D-44a, D-48a, D-49a/b/c, D-50a/b, D-51a) and S-1…S-4 for sectors. D-36 is replaced by D-38 (keep the row, mark it «مستبدل»). After Revision 9 no decision blocks an October task; D-17 (Task 25) and D-18 (Task 24) stay deferred.
- **Revision 4 story change (D-38…D-45):** donor approval + owner acknowledgement makes the project `approved` (funded, awaiting the monthly report template); the project becomes `active` only when MEAL confirms its **Monthly Report Template** (Task 33). Monthly reports are generated at that confirmation. Present this with the section 1.0 facts 13–17 for sign-off.
- **Decisions that changed earlier defaults in this prompt:** `proposals.correct-status` = General Manager + Public Relations Manager (D-20); Super Admin is for developers only and is never in a business default or a fallback (D-20a); reopen decisions = MEAL Manager slot, and Public Relations Manager when the slot is empty (D-09, D-09a); `final reports` status removed (D-02); targets entered by MEAL only (D-23); one open project per centre **and program** (D-30); Public Relations Manager gets `funding-agreements.view/create/update` (D-31b).
- **New permission names to fix at CP0** (from Tasks 33 and 34): `report-templates.view/manage/confirm`, `evidence-types.manage`, `narrative-questions.manage`, `sectors.view/manage`, and the reopen grant to Public Relations Manager.
- **CP0 conflicts (log section 10, T-01…T-08) must each get a decision or an applied default before CP0 closes:** GM's "everything except" list (T-01), `report-templates.view` visibility (T-02), stale Super Admin text in Tasks 33/34 (T-03), `proposals.approve` vs `proposals.update` (T-04/T-07), local data that contradicts assumption 4 (T-06), the `ceo` reviewer slot (T-08).
- **Inputs checklist additions:** from MEAL, the evidence-type list and the narrative question bank (Task 33), each program's `donor_attribution` mode (D-46), the MEAL officer per governorate and per-project overrides (D-12a), and review of the Micro JSON files (D-37); from HR, fixing the duplicated program codes `003`, `004`, `009` (D-10a) and adding the wells, alternative energy, PSS/case management and parenting programs (D-35a) and the missing centres (D-30c) before 2026-10-27; from PR, the self-funded proposals to link to the internal donor (D-27a).
- **Decision → task map additions:** D-35, D-37→32 · D-38…D-45→33, 17, 18, 19, 21, 27 · D-46→13, 17, 18, 19, 27, 33 · D-47 (S-1…S-4)→34, 02, 05, 27 · D-48→17, 27, 33 · D-49→12, 17, 27, 31, 33 · D-50→11, 13, 17, 18, 19, 27, 33 · D-51→12, 17, 18, 31 · D-53→none · D-54→27, 28, 32.

## Scope
### In scope
- Create (or update) `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md`, written in Arabic like the plan, with every table, column, status, permission and UI name in English exactly as the plan spells it. Sections:
  1. Header: date, participants by role (not by name), status legend `مقرر` / `افتراضي مطبّق (قابل للمراجعة)` / `مفتوح`.
  2. Workshop agenda: domain facts for sign-off (section 1.0), corrected story (section 1.1–1.2), proposal state diagram (1.3), gap table (section 2), then decisions one by one.
  3. Decision table, one row per decision in plan section 9 (D-01…D-54 and lettered sub-decisions): question, recommended default copied from section 9, impact, tasks blocked, `.ai/rules` file affected, decision, decision owner (role), date, reviewable flag, and a "must be decided before" column (D-28/D-30 before Task 03 and Task 30; D-02/03/04/05/20 before Task 04; D-31 before Task 09; D-07/08/09/29 before Task 17; D-32 before Task 19; D-33/D-34 before Task 31; D-17/D-18 marked «خارج نافذة أكتوبر» — Tasks 24/25 deferred).
  4. Permission matrix draft (role × module × action) for the nine roles, built from the current `config/access-control.php` plus the Task 02 default table; mark new rows; leave a signature line for management.
  5. External inputs checklist with an expected date no later than day 5 (2026-10-16): monthly report template from MEAL (fields, narrative per item, attachments, examples); default reviewer slots (MEAL Manager, Finance Manager, Projects & Fundraising Manager) as roles/positions; "who supervises whom" source (D-13); who sees money on the new Proposal pages; from the HR team: missing centres added and `direct_manager_id` confirmed (D-30, D-13).
  6. Read-only pre-check SQL appendix (see Data integrity) with a results table (run by, date, result).
  7. Low-fidelity text wireframes for **Create Proposal** (Task 06 field list), the **Reviewers** panel (Task 07) and the **Monthly Report** page (Task 17): fields, order, actions, empty and error states.
  8. Draft `record-rule` entries (glob, title, note) for each decision that changes an existing rule, e.g. `.ai/rules/views-components-ui.md` "nothing under an output" → "Activity under Output" (Task 12), `.ai/rules/funding.md` warning-only gate → hard gate (Task 09), `.ai/rules/proposals.md` free starting status → `concept note` or `proposal draft` with a skip reason (Task 06).
### Out of scope
- Any change under `app/`, `config/`, `database/`, `resources/`, `lang/`, `routes/`, `tests/` (Tasks 02–31).
- Editing the plan file; report contradictions instead (section 7 says a frozen contract changes only with both sides and a plan update).
- Running anything on production, running migrations or seeders.

## Business rules
- A decision not settled within one working day takes the recommended default and is tagged `افتراضي مطبّق (قابل للمراجعة)`; it is shown again at the next checkpoint.
- A decision that changes a documented `.ai/rules` rule is recorded with Boost `record-rule`, not left in chat — but only after a human confirms in chat that the decision is final.
- Conflicting sources (e.g. the guide allows co-funders on one proposal, the corrected story says one agreement) go to management; the default is the corrected story (co-funder = separate proposal, D-24).
- People in the meeting notes (Rami, Garo, Shadi, Freddy) are role illustrations; the log names roles, positions or configurable slots, never a person, in anything code will follow.
- The signed permission matrix is the only source for Task 02: no permission outside it or the plan's defaults. `proposals.correct-status` goes to General Manager and Public Relations Manager (D-20); Super Admin is developers-only and never appears in a business default (D-20a).
- The log must state explicitly who sees money on the new Proposal pages (requested budget), applying "Hide money, keep names".
- Done = every D-xx is `مقرر` or `افتراضي مطبّق`, and the template and matrix are received or have a dated expected receipt no later than day 5.
- The section 1.0 domain facts are signed (or their dissent recorded) before any decision row is settled.

## Security and authorization
- SQL in the appendix is `SELECT` only: no DDL, no DML, no `INTO OUTFILE`, no stored procedures. Recommend running it with a read-only user on a production copy or replica.
- Never paste connection strings, credentials, personal data (employee names, emails, phone numbers) or money amounts from any database into the log; record counts and record ids only.
- Flag in the log any request to grant `proposals.correct-status` beyond General Manager and Public Relations Manager, any business default that names Super Admin, or to give reviewers `proposals.view` or money access.

## Validation
- Not applicable to user input (decision task). Validate the document itself: one row for every decision in plan section 9 (D-01…D-54, no D-52) and every lettered sub-decision, with no gaps; every row has a status; every "must be decided before" item is present; every permission name matches the `module.action` form used in `config/access-control.php`; every status name matches `ProposalStatus` / `ProjectStatus` values or the new values in the plan.

## Edge cases and concurrency
- Decision owner absent: apply the default, tag it reviewable, list it for the next checkpoint.
- Production data contradicts the plan's assumption 4 (a proposal with two agreements, a proposal with none, a draft project with dates): record it as a management decision that blocks Task 03 / Task 05, not as something code will "fix".
- Permission matrix arrives after development starts: note that Task 02 only updates `role_baselines` and re-runs `permissions:sync`.
- Monthly template late (risk R2): note that Task 17 builds configurable fields.
- Another agent or person edits the log concurrently: re-read the file before every write and merge; never overwrite someone else's decided row.

## Data integrity
Write these read-only checks into the appendix for management to run on production (they feed Tasks 03, 05, 08 and 27); also run them yourself on the LOCAL dev database with Boost `database-query` and record the local results separately, labelled "local, 2026-10-xx":
- `SELECT proposal_id, COUNT(*) FROM donor_proposal GROUP BY proposal_id HAVING COUNT(*) > 1;` — must be empty (Task 03 unique index).
- Proposals without an agreement (Task 03 cannot set `proposals.donor_id` NOT NULL for them): `SELECT p.id, p.internal_project_number FROM proposals p LEFT JOIN donor_proposal dp ON dp.proposal_id = p.id WHERE dp.id IS NULL;`
- Status distribution: `SELECT status, COUNT(*) FROM proposals GROUP BY status;` and the same for `projects`.
- Projects in `submitted` / `approved` (Task 05 defensive migration) and drafts carrying dates: `SELECT id FROM projects WHERE status = 'draft' AND (start_date IS NOT NULL OR end_date IS NOT NULL);`
- Projects linked to more than one proposal, proposals linked to more than one project, and proposals linked to no project (Task 08 refuses approval for those), from `project_proposal`.
- Placeholder numbers: `SELECT COUNT(*) FROM proposals WHERE internal_project_number LIKE 'TEMP-%';`
- Row counts of `indicators`, `plan_indicators`, `reports` (assumption 4: 61 / 61 / 33).
- Project = centre (D-30, assumption 4): count of HR centres (`centers` on the `hr` connection) and projects per `city_id` whose city has no HR centre; counts and ids only, no personal data.

## Implementation guidance
- Start from this decision → task map and verify it against the plan text: D-01→04, 08 · D-02→04 · D-03→04, 06 · D-04→08, 21 · D-05→08 · D-06→05 · D-07, D-08, D-09→17 (D-09 also 02) · D-10→11 · D-11→10, 11, 13 · D-12→15 · D-13→23 · D-14→02, 07 · D-15→07 · D-16→31 · D-17→25 (out of window) · D-18→24 (out of window) · D-19→22 · D-20→02, 04 · D-21→19, 21 · D-22→19, 21, 31 · D-23→13 · D-24→03, 12 · D-25→11, 18 · D-26→04 · D-27→05 · D-28→05, 08, 17 · D-29→17 · D-30→03, 05, 10, 30 · D-31→09 · D-32→02, 19 · D-33→31 · D-34→31.
- Copy defaults from section 9 word for word; you may add a "consequence if the default is applied" line, but do not invent new options.
- Current code facts worth putting next to the relevant decision: `RestoreProject` restores every archived project to `draft` (D-27); `ManagesAgreementForm` only warns on an unapproved proposal (D-26, Task 09); `ProposalStatus` has no `on hold` / review states yet (D-02/D-03); `config/access-control.php` derives the General Manager baseline as "everything except" a list (relevant to D-09 and `proposals.correct-status`).
- Keep the wireframes textual (lists and simple box sketches in Markdown); no images, no HTML.

## Tests (Pest)
- No code tests in this task. Verification is a review: Dev 1 and the decision owner read the log. Before finishing, re-read the file and check the Validation list above item by item.

## Acceptance criteria
- [ ] `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` exists with all sections listed in Scope.
- [ ] A row for every decision D-01…D-54 (no D-52) and every lettered sub-decision, each with a status, default, impact, blocked tasks and affected rule file (or "none").
- [ ] Permission matrix draft covers the nine roles and every new permission of Tasks 02, 33 and 34, with `proposals.correct-status` for General Manager and Public Relations Manager only and no business row for Super Admin.
- [ ] Conflicts T-01…T-08 each have a decision or an applied default before CP0.
- [ ] Pre-check SQL appendix is read-only and covers every check in Data integrity; local results recorded and labelled.
- [ ] Inputs checklist has an expected date ≤ day 5 for the monthly template and the matrix.
- [ ] Section 1.0 domain facts are listed for sign-off, and the HR-team requests (missing centres, `direct_manager_id`) are in the inputs checklist.
- [ ] Draft `record-rule` entries exist for every rule-changing decision; none recorded before human confirmation.

## Do not
- Start coding or edit any file outside `docs/superpowers/specs/`.
- Treat a default as decided without tagging it reviewable.
- Write people's names into rules, slots or permission defaults.
- Run, or ask for access to run, anything on production.
- Call `record-rule` for a decision nobody has confirmed.

## Quality bar (this task)
- The log is a working document for management: one decision per row, plain Arabic, the exact status words from the legend, and the consequence of the default in one sentence.
- Each text wireframe shows the screen's empty, error, read-only and confirm states, the money each role may see, and where the primary action sits (header actions slot, confirm modal for irreversible steps), following `quality-bar.md` section 3.
- The document is read by non-developers under time pressure: numbered steps, one action per step, the expected result next to it, and an explicit stop condition. Arabic text, English identifiers exact.

## Working method
- Start in plan mode: restate the task in 5 bullets, list files you will touch, list open questions; wait for approval.
- Implement in small steps; after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory).
- Do not commit, push, or run destructive commands (`migrate:fresh`, seeders that reset roles, deleting storage) unless the developer asks.
- Never run anything against production.
- Stay inside this task's scope; note anything else as a follow-up.
- (This task changes no PHP and no tests; the test and Pint bullets apply only if that changes.)

## Report back
Your final message must contain: a short summary; the file(s) created or changed; migrations added (none expected); checks run with their local results; decisions recorded with status (count per status, and the list still open); contradictions found between the plan and the code; follow-ups; and the handoff facts: the final permission names for Task 02 (CP0), the D-24/D-28/D-30 answers and the donor_proposal duplicate check for Task 03 and Task 30, D-02/D-03/D-04/D-05/D-20 for Task 04, D-06/D-27/D-30 for Task 05, D-14/D-15 for Task 07, D-31 for Task 09, and for Team B: D-07/D-08/D-09/D-29 for Task 17 (B1), D-32 for Task 19, D-33/D-34/D-16 for Task 31 (B3), D-13 for Task 23 (B3).
````
