# Task 04 — Proposal state machine v2 (ProposalWorkflow)

> **المهمة:** آلة حالات المقترح v2 (ProposalWorkflow)
> **المطوّر:** Dev 1 · **الفريق:** A · **الأيام:** 4–6 (الخميس 2026-10-15 – الاثنين 2026-10-19) · **الجهد:** 3 أيام · **يعتمد على:** Task 01، Task 03
> **متى تستخدمه:** بعد دمج Task 03 وتوقيع CP1 (نهاية اليوم 3، الأربعاء 2026-10-14: قيم `ProposalStatus` وتوقيع `TransitionProposal` وأسماء الأحداث مجمّدة)، وحسم D-02 وD-03 وD-04 وD-05 وD-20 في سجل القرارات، ووجود `proposals.correct-status` من Task 02؛ ويجب أن يُدمج مع أصناف الأحداث قبل CP2 (نهاية اليوم 6، الاثنين 2026-10-19).
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are implementing Task 04 of the HC Tracking proposal-lifecycle correction plan. The outcome: a server-enforced proposal state machine replaces today's "any status to any status" move. Every transition has guards, required fields and an immutable audit note; concept-note stages, internal review stages and `on hold` are added; `final reports` is removed (D-02) and closing a proposal requires the uploaded final report and the donor's recorded acceptance (D-02c); terminal statuses can only be corrected by General Manager and Public Relations Manager through **Correct Status** (D-20). In the corrected story the concept note goes through review and is sent to the donor, then the proposal is drafted, reviewed and submitted; only the donor's approval (Task 08) fixes dates and makes linked projects `approved` (funded); MEAL's confirmation of the monthly report template (Task 33) then makes them `active`; the agreement follows (Task 09). Tasks 05–10 and the scanners in Task 21 depend on the values, the transition table and the `TransitionProposal` signature frozen at CP1 (end of day 3); the merged action and the event classes are due at CP2 (end of day 6).

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins).
2. `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md`: section 1.3 (state diagram) and `### Task 04` — Arabic and authoritative; status values and UI names (**Change Status**, **Correct Status**) are exact. Also section 5, section 7 (CP1, CP2), `### Task 08` «تعديلات المراجعة 2» (the event contract) and section 9 rows D-02, D-03, D-20, D-26, D-28.
3. `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` (if it exists; a recorded decision overrides the plan's default for D-xx).
4. Rules: `.ai/rules/enums.md` (lower-case values; `phase` derived, never stored; `match` without `default`; `ProposalStatus` casts both `proposals.status` and `proposal_status_notes.status`), `.ai/rules/views-enums.md` (labels in `lang/*/enums.php`; `LocalizationTest`), `.ai/rules/proposals.md` (status moves only with an audit note; the edit form never writes status), `.ai/rules/models.md`, `.ai/rules/providers.md` (strict mode), `.ai/rules/database-migrations.md`, `.ai/rules/migrations.md`, `.ai/rules/factories.md`, `.ai/rules/seeders.md`, `.ai/rules/tests.md`, `.ai/rules/pages.md` (`wire:model` modals, "Hide money, keep names"), `.ai/rules/ui.md`, `.ai/rules/views.md`, `.ai/rules/admin.md`, `.ai/rules/alerts.md`, `.ai/rules/livewire-concerns.md` (Gantt payloads).
5. Source: `app/Enums/ProposalStatus.php`, `app/Enums/ProposalPhase.php`, `app/Actions/Proposals/ChangeProposalStatus.php`, `app/Policies/ProposalPolicy.php`, `app/Models/Proposal.php`, `app/Models/ProposalStatusNote.php`, `database/factories/ProposalStatusNoteFactory.php`, `database/migrations/2026_09_07_144321_create_proposal_status_notes_table.php`, `resources/views/pages/proposals/⚡index.blade.php` (`openStageModal()`, `recordStageChange()`, `showStageModal`, `saveProposal()` start status), `resources/views/pages/proposals/⚡show.blade.php` (`openStatusModal()`, `changeStatus()`, `lifecycle()`), `resources/views/pages/proposals/_overview.blade.php` (`next()`), `resources/views/components/ui/enum-badge.blade.php`, `app/Livewire/Concerns/BuildsPortfolioGantt.php` (`proposalProgress()` 5/15/30/45/65/90/100, `proposalTone()`), `app/Notifications/Alerts/DeadlineCatalog.php` and `StalledScanner.php` (exclude completed/rejected), `resources/views/pages/projects/⚡show.blade.php` (phase filter ~line 809), `resources/views/pages/dashboard/⚡index.blade.php`, `tests/Feature/AccessControl/AuthorizationCoverageTest.php` (source-scan pattern).
6. Skills: `laravel-best-practices`, `laravel-security`, `database-migrations`, `livewire-development`, `fluxui-development`, `testing-best-practices`. Use Boost `search-docs` (Pest arch testing, `lockForUpdate`, Livewire validation) and `database-schema` on `proposal_status_notes` before the migration.

## Preconditions to verify
- `proposals.donor_id`, `requested_budget`, `requested_currency`, `status_remind_on` exist (Task 03).
- `proposals.correct-status` exists in `config/access-control.php` (Task 02).
- The decision log records D-02, D-03, D-20 (or their defaults applied). If any precondition fails, stop and report instead of improvising.

## Revision 2 changes (2026-10-08)
- Timeline: Team A, days 4–6 (2026-10-15 → 2026-10-19). Values, transition table and the `TransitionProposal` signature are frozen at CP1 (end of day 3, agreed with Task 03); the merged action is due at CP2 (end of day 6). Task 06 (Dev 2) builds the form in parallel on days 4–6 against the frozen signature, so publish the signature early and do not change it.
- Day 6 (schedule, section 6) also carries the event classes that Dev 1 writes for Team B before the approval logic (Task 08 revision): `ProposalStatusChanged(proposalId, from, to, actorId)`, `ProjectActivated(projectId, proposalId, startDate, endDate, via)`, `ProjectPeriodChanged(projectId, oldStart, oldEnd, newStart, newEnd)`, `FundingAgreementRecorded` (payload as frozen at CP1). They must exist in `main` at CP2 so Team B (Task 17, Task 31) and Task 21 can write listeners in parallel. Only `ProposalStatusChanged` is dispatched in this task.
- `TransitionProposal` (and `CorrectProposalStatus`) dispatch `ProposalStatusChanged` once per successful transition, after commit (`DB::afterCommit` / `ShouldDispatchAfterCommit`); a rolled-back or refused transition dispatches nothing. Task 21's catalog maps it to `proposal.status_changed` (MEAL + General Management are recipients of every move).
- Task 06 revision: planned dates (`planned_date_begin` / `planned_date_end`) are optional in the draft and REQUIRED on `concept note → concept note review` (milestones and department tasks are scheduled on them). Add `planned_dates` to `requirements()` for that line.

## Revision 4 changes (2026-10-12)
Source: plan header paragraph «المراجعة 4», section 1.1 steps 7–7أ, `### Task 08` «تعديلات المراجعة 4» and `### Task 33`. Where this block conflicts with the Revision 2 block or the base sections, this block wins.
- The proposal state machine itself does not change. What changes is the event contract you write on day 6: add a **fifth** class `ProjectFunded(projectId, proposalId, startDate, endDate)`. Task 08 dispatches it when a draft project becomes `approved` (funded, awaiting the monthly report template); Task 33 (B4, from day 8) listens to it to draft the template and notify MEAL, so it must be in `main` at CP2 with the others.
- `ProjectActivated` keeps its payload but is dispatched only by Task 33 (`ActivateProjectFromConfirmedTemplate`, `via: template_confirmation`). Document `via` as a string whose only October value is `template_confirmation`; nothing in this task or Task 08 dispatches it.
- `ProposalStatusChanged(to: approved)` (dispatched by Task 08's `ApproveProposal`) is also a Task 33 trigger: it adds `pending` template items for linked projects that are already `active`. Keep the payload exactly as frozen at CP1.

## Revisions 6–8 changes (2026-10-12)
Source: decision-workshop answers (R6 file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.docx`) and two real M&E workbooks (`Syria Cross e.V._ME_Tool_IPTWP_V1_2026_Aug.xlsx`, `Children University - IPTT - 2026.xlsx`). Read the plan header paragraphs «المراجعة 6/7/8», decision rows D-02, D-03, D-20, and the «تعديلات المراجعة 6, 7 and 8» subsections of this task in the plan. Open questions still running on their documented default are listed in `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r7.ar.docx`. Where this block conflicts with text further down this prompt, this block wins.
- `final reports` is removed from `ProposalStatus` (D-02); `active → completed` is direct. Production rows in `final reports` move to `active` with an activity entry (Task 27).
- D-02a: the «Final Report» milestone is added manually from a template (Task 31), flagged `is_final_report`.
- **D-02b (block, not warn):** `active → completed` is rejected unless (1) the proposal's final-report milestone is complete and (2) donor acceptance is recorded. New `proposals` columns: `final_report_submitted_on`, `final_report_accepted_on`, `final_report_accepted_by` (FK users); the acceptance letter/email is required in `media` category `final_report_acceptance`. Action `RecordFinalReportAcceptance` (holder of `proposals.update`). Message: «لا يُغلق المقترح قبل تسليم التقرير الختامي وتسجيل قبول الممول له». A self-funded proposal (D-27a) only needs the completed milestone. **Correct Status** may override only with a mandatory reason and the correction flag.
- D-03: skipping the concept note needs a reason; add the ready reason «المقترح موجود مسبقًا».
- Tests: completed without milestone → rejected; milestone but no acceptance → rejected; acceptance without file → rejected; full path → completed.

## Revision 9 changes (2026-10-12)
Source: the corrected R6 answers file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.fixed.docx`, which is newer than the R7 questions file and replaces the defaults Revision 8 applied. Read the plan header paragraph «المراجعة 9», the decision rows in section 9 (and «الأسئلة المتبقية بعد المراجعة 9»), and the «تعديلات المراجعة 9» subsection of this task. **Where this block conflicts with any text further down, including the Revisions 6–8 block, this block wins.**
- **D-02c (decided, amends D-02b):** PR (`proposals.update`) records donor acceptance via `RecordFinalReportAcceptance`. The **final report file itself is mandatory** (`media` category `final_report`, shown on the proposal dashboard and **Executive Summary**); `final_report_accepted_on` and `final_report_accepted_by` are mandatory; the acceptance email (`final_report_acceptance`) is **optional**. `active → completed` requires the completed final-report milestone, the uploaded report and the recorded acceptance — «ارفع التقرير الختامي وسجّل موافقة الممول عليه قبل إغلاق المقترح». Self-funded: milestone + report file. Tests: no report file → rejected; file without acceptance → rejected; file + acceptance without email → passes; non-PR → 403.
- **D-25:** moving to `proposal review` (and `submitted`) requires `target_beneficiaries` on every linked project — «أدخل عدد المستفيدين لكل مركز مرتبط بالمقترح». No retroactive check on proposals already past `submitted`. Test: two linked projects, one without a count → transition rejected.

## Scope
### In scope
- `ProposalStatus`: add `ConceptNoteReview = 'concept note review'`, `ConceptNoteSubmitted = 'concept note submitted'`, `ProposalReview = 'proposal review'`, `OnHold = 'on hold'`; update `phase()`, `next()`, `lifecycle()`; add `stage()` (`concept_note` | `proposal` | `grant`, display only). Existing values keep their names.
- `ProposalPhase`: add `Ideation = 'ideation'` (first case).
- Create migration `add_correction_and_actor_to_proposal_status_notes_table`: `is_correction` boolean default false, `changed_by` nullable FK to `users` with `nullOnDelete()`. Update `ProposalStatusNote` (`#[Fillable]`, casts, `changedBy()`, mirror `is_correction` default in `$attributes`).
- Create `app/Support/ProposalTransitions.php`, `app/Actions/Proposals/TransitionProposal.php`, `app/Actions/Proposals/CorrectProposalStatus.php`; add `ProposalPolicy::transition()` and `ProposalPolicy::correctStatus()`; move every caller off `ChangeProposalStatus` and delete it.
- **Change Status** modal on `proposals/⚡index` and `proposals/⚡show`: only allowed targets and only their fields; a **Correct Status** entry on `⚡show` for terminal statuses, visible to holders of `proposals.correct-status` (confirm the UI in plan mode; the action and its tests are mandatory).
- Every use of `ProposalStatus::`, `->phase`, `ProposalPhase::`, `->next()`, `lifecycle()`: pages, `_overview`, enum badge tones, Gantt progress/tone, scanners, dashboard, seeders, factories, tests.
- Labels and messages in `lang/{en,ar}/enums.php` and `lang/{en,ar}/proposals.php`.
- Event classes in `app/Events/` (`ProposalStatusChanged`, `ProjectFunded`, `ProjectActivated`, `ProjectPeriodChanged`, `FundingAgreementRecorded`) with the CP1 payloads; dispatch `ProposalStatusChanged` after commit from `TransitionProposal` and `CorrectProposalStatus`.
- Revisions 6–9: the data migration that removes `final reports` (rows move to `active` with an activity entry; print the local count); `proposals.final_report_submitted_on`, `final_report_accepted_on`, `final_report_accepted_by`; `media` categories `final_report` (required) and `final_report_acceptance` (optional); `App\Actions\Proposals\RecordFinalReportAcceptance` with a **Record Final Report Acceptance** modal on `proposals/⚡show` (upload the final report, acceptance date, optional donor email, optional note) for `proposals.update` holders; the `target_beneficiaries` gate on `proposal draft → proposal review` (D-25).
### Out of scope
- `ApproveProposal` and project activation, and dispatching `ProjectActivated` / `ProjectPeriodChanged` (Task 08); dispatching `FundingAgreementRecorded` (Task 09); listeners (Tasks 09, 17, 21, 31); reviewers, meeting invite and `meetingAt` storage (Task 07); the v2 create/edit form and `SaveProposal` (Task 06); `on hold` reminders (Task 21); agreement gate (Task 09).

## Business rules
| From | To | Required fields / guards |
|---|---|---|
| concept note | concept note review | ≥ 1 linked project, donor set, planned dates (revision 2; meeting time handled by Task 07) |
| concept note review | concept note | reason |
| concept note review | concept note submitted | effective (sent) date ≤ today |
| concept note | proposal draft | skip reason (D-03) |
| concept note submitted | proposal draft | date the donor accepted the concept note |
| concept note submitted / submitted | rejected | reason ≥ 10 characters + date |
| concept note submitted / submitted | on hold | `status_remind_on` after today + reason |
| proposal draft | proposal review | `requested_budget` + `requested_currency` + planned dates |
| proposal review | proposal draft | reason |
| proposal review | submitted | submission date ≤ today |
| submitted | proposal draft | reason (donor asked for resubmission) |
| on hold | submitted / proposal draft / rejected | the target's own fields |
| submitted / on hold | approved | only through `ApproveProposal` (Task 08) — `TransitionProposal` always refuses |
| approved | active | actual start date; warning (not a block) when no agreement exists (D-26) |
| active | completed | actual end date; the proposal's `is_final_report` milestone complete, the final report file uploaded (`media` category `final_report`) and donor acceptance recorded (`final_report_accepted_on`, `final_report_accepted_by`) — D-02c; self-funded (D-27a): milestone + report file only |

*(Revision 6 removed `final reports`; the old rows `active → final reports` and `active / final reports → completed` no longer exist.)*

- Every transition writes one `ProposalStatusNote` (status, reason/context, effective date, `changed_by`) in the same transaction as the status.
- Entering `on hold` sets `proposals.status_remind_on`; leaving it clears it.
- `rejected` and `completed` are terminal; only `CorrectProposalStatus` leaves them, with a mandatory reason and `is_correction = true`. Recommend it never targets `approved` (that goes through Task 08) — confirm in plan mode.
- Phase is derived: ideation ← the three concept-note statuses; planning ← `proposal draft`, `proposal review`, `submitted`, `on hold`; implementation ← `approved`; monitoring ← `active`; closure ← no stored status any more (D-02): propose in plan mode a derivation from loaded data, recommended `active` with an `is_final_report` milestone in progress or past `actual_date_end`; archived ← `completed`, `rejected`. *(The plan's Revision 6 wording, "closure = `completed` with an open final-report milestone", can never be true once D-02b/D-02c block `completed` until that milestone is complete; flag it in your report.)* This moves `concept note`, `submitted` and `rejected` to new phases: re-check every phase filter and count.
- Open questions to raise in plan mode: which targets `on hold` offers when the hold was entered from `concept note submitted` (recommended: `proposal draft` and `rejected` only); what `stage()` returns for `on hold` and `rejected`; whether "actual start/end date" on `approved → active` / `→ completed` writes `proposals.actual_date_begin/end` or only the note date (Task 08 already sets the actual dates at approval); and what a `proposals.update` holder without money permission sees when the budget gate blocks `proposal draft → proposal review` — the message must not reveal any amount and should say who can enter the budget (Public Relations Manager holds `funding-agreements.view` since D-31b, so it can).

## Security and authorization
- Transitions require `proposals.update` through `ProposalPolicy::transition($user, $proposal, $to)`; call `Gate::authorize` inside the action, not only in the component.
- `CorrectProposalStatus` requires `proposals.correct-status` (`ProposalPolicy::correctStatus`).
- The target is checked against `ProposalTransitions` on the server; a value the client was never offered gets a validation error (422) or 403. Hiding options is not protection.
- IDOR/BOLA: the action reloads the proposal by id with `lockForUpdate()` and re-checks the policy on that row; never trust a status held in Livewire state.
- Remove `status` from `Proposal`'s `#[Fillable]` (strict mode then throws on any mass-assigned status) and write it only inside the three actions; confirm in plan mode and grep for `fill([... 'status'` callers first.
- Every transition is logged in **Activity Log** (`activities`) as `proposal.status_changed` (already raised by `Proposal::activityEvents()` when `status` changes). Notes carry no money.

## Validation
Messages go to `lang/en/proposals.php` and `lang/ar/proposals.php`:
- Target not allowed: "The proposal cannot move from :from to :to." / «لا يمكن نقل المقترح من :from إلى :to».
- Reason missing: "Write the reason for this change." / «اكتب سبب هذا التغيير».
- Rejection reason: "Write the reason for the rejection (at least 10 characters)." / «اكتب سبب الرفض (10 أحرف على الأقل)».
- Effective date: `required|date|before_or_equal:today` — "The date cannot be in the future." / «التاريخ لا يكون في المستقبل».
- `status_remind_on`: `required|date|after:today` — "The reminder date must be in the future." / «تاريخ التذكير يجب أن يكون في المستقبل».
- Budget gate: "Set the requested budget and its currency before sending the proposal for review." / «حدّد الميزانية المطلوبة وعملتها قبل إرسال المقترح للمراجعة».
- No linked project: "Link at least one project first." / «اربط مشروعًا واحدًا على الأقل أولًا».
- Stale state: "The proposal status just changed. Reload the page." / «تغيّرت حالة المقترح للتو، أعد التحميل».

## Edge cases and concurrency
- Two users move the same proposal: `TransitionProposal` locks the row (`lockForUpdate`) and takes `expectedFrom`; if the stored status differs it refuses with the stale-state message and writes nothing.
- Double click: the second call finds the new status ≠ `expectedFrom` and is refused gently; exactly one note exists.
- Existing data: old values (`approved`, `active`, …) stay valid; no data migration of statuses.
- Alerts: `DeadlineCatalog` and `StalledScanner` already skip `completed`/`rejected`; add `on hold` (its reminder comes in Task 21).
- A proposal whose projects are all archived: transition allowed, return a warning.
- `proposal_status_notes.context` is NOT NULL: for transitions with no required reason, write a standard translated note the way `saveProposal()` writes `__('proposals.proposal_recorded_note')`, or propose making the column nullable — decide in plan mode.
- The create form in `proposals/⚡index` still lets users pick any starting status: in this task new proposals start at the column default `concept note`; Task 06 adds the `proposal draft` start (through a transition with the skip reason). Confirm in plan mode.

## Data integrity
- One transaction per transition: lock, re-check, update status (+ `status_remind_on`), insert the note. Notes are never updated or deleted; add no UI for that.
- Migration: `database-schema` first; `down()` drops the FK, then the columns.
- `phase()` and every `match` over `ProposalStatus` keep no `default` arm (the enum badge's existing `default` arm is the one allowed exception — add explicit tones for the new values anyway).
- Do not store `phase` or `stage`.

## Implementation guidance
```php
final class ProposalTransitions
{
    /** @return array<string, list<ProposalStatus>> from value => allowed targets */
    public static function map(): array { /* the table above, nothing else */ }

    public static function allows(ProposalStatus $from, ProposalStatus $to): bool { /* ... */ }

    /** @return list<string> required inputs: reason, reason_min_10, effective_date, remind_on, budget, linked_project */
    public static function requirements(ProposalStatus $from, ProposalStatus $to): array { /* ... */ }
}

final class TransitionProposal
{
    /** @param array{reason?: string, effective_date?: string, remind_on?: string} $input */
    public function handle(Proposal $proposal, ProposalStatus $to, ProposalStatus $expectedFrom, array $input): TransitionResult { /* ... */ }
}
```
- `TransitionResult` (or an equivalent small return value) carries the note and any warnings (no agreement, all projects archived). Task 07 will call `InviteDefaultReviewers` inside this transaction when entering `concept note review` / `proposal review`; keep that place obvious. Domain events are plain Laravel event classes dispatched after commit (see Revision 2); add no custom event bus.
- `declare(strict_types=1);`, explicit return types, PHPDoc array shapes, curly braces everywhere.
- Gantt progress: extend `proposalProgress()` for the new values (e.g. CN 5, CN review 8, CN submitted 10, proposal draft 15, proposal review 22, submitted/on hold 30) and confirm the numbers in plan mode.
- Modals bind `wire:model` booleans (`showStageModal`, `showStatusModal`), never `:open`; use the existing `x-ui.*` and Flux components on those pages.

## Tests (Pest)
- Create `tests/Feature/ProposalTransitionTest.php`: one dataset row per table line (success + note written with `changed_by`); a disallowed move (`concept note → approved`) writes nothing; each missing required field fails with its message; `approved` through `TransitionProposal` is refused; `on hold` sets and clears `status_remind_on`; stale `expectedFrom` is refused with no second note; a user without `proposals.update` gets 403 (fresh Livewire instance per forbidden call: `$fresh = fn () => Livewire::actingAs($u)->test('pages::proposals.show', [...])`).
- Create `tests/Feature/CorrectProposalStatusTest.php`: General Manager and Public Relations Manager correct `rejected`/`completed` with a reason and `is_correction = true`; Financial and MEAL get 403; a non-terminal source is refused; a correction to `completed` without the D-02c evidence needs the reason and the correction flag (D-20).
- Create `tests/Feature/ProposalStatusWriteGuardTest.php`: a source-scan test (or Pest `arch()` if it can express it) that fails when `app/` or `resources/views/` writes a proposal's `status` outside `TransitionProposal`, `CorrectProposalStatus` and the future `app/Actions/Proposals/ApproveProposal.php`.
- Update `tests/Feature/ProposalPhaseTest.php` (new mapping, `next()`, `lifecycle()`; "a new proposal is in the planning phase" becomes ideation), `tests/Feature/ProposalPipelineTest.php` (stage move tests), `tests/Feature/ProposalStatusNoteTest.php`, `tests/Feature/EnumCastTest.php`.
- Must stay green: `ProposalDetailTest`, `ProposalDetailActionsTest`, `ProposalRegisterTest`, `DashboardTest`, `GanttTimelineTest`, `LocalizationTest`, `Notifications/DeadlineCatalogTest`, `Notifications/StalledScannerTest`, `FundingWorkflowTest`, `ExecutiveSummaryTest`.
- In `ProposalTransitionTest`: with `Event::fake([ProposalStatusChanged::class])`, a successful transition dispatches exactly one event with `proposalId`, `from`, `to`, `actorId`; a refused transition or a rolled-back transaction dispatches none; `concept note → concept note review` without planned dates fails with its message.
- Run: `php artisan test --compact tests/Feature/ProposalTransitionTest.php`, then each file above.

## Acceptance criteria
- [ ] Nothing changes `proposals.status` outside `TransitionProposal`, `ApproveProposal` (Task 08) and `CorrectProposalStatus`, proven by the guard test.
- [ ] **Change Status** shows only the allowed targets and their fields, on both pages, in Arabic and English.
- [ ] Every table line has a passing test; concurrency and double-submit tests pass.
- [ ] All existing proposal, pipeline, dashboard, Gantt and scanner tests pass.
- [ ] The five event classes (including `ProjectFunded`, Revision 4) exist with the CP1 payloads by CP2 (end of day 6); `ProposalStatusChanged` is dispatched after commit only.
- [ ] `final reports` no longer exists in the enum, `lifecycle()`, `next()`, `phase()`, scanners or the timeline; `active → completed` is refused without the final report file and the recorded acceptance, with the D-02c message.

## Do not
- Store `phase` (or `stage`) in a column.
- Keep the full status list in the modal.
- Write status from **Edit Proposal** or any other form.
- Rename existing values such as `'concept note'`.
- Add a `default` arm to `phase()` to silence a new case.

## Quality bar (this task)
- **Change Status** modal: only the allowed targets, each with a one-line description; the fields for the chosen target appear progressively; the stale-state error explains what happened and offers a reload; the confirm button is disabled while saving.
- **Correct Status** and **Record Final Report Acceptance** go through `x-ui.confirm-modal` stating the consequence; the closing gate message names what is missing (milestone, report file, acceptance).
- New statuses get explicit `x-ui.enum-badge` tones with text; the Gantt progress map lives in one place and is documented.
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
Your final message must contain: summary; files changed (and the deleted `ChangeProposalStatus`); migrations added; tests run with pass/fail output; decisions assumed (D-02, D-03, D-20, D-26 and every plan-mode answer); follow-ups; and the handoff facts frozen at CP1: the final enum values and phase map, `ProposalTransitions::map()` and `requirements()` keys, the exact `TransitionProposal::handle()` signature and return type, the `ProposalPolicy` ability names, the guard-test allowlist, and the event class names, namespaces and constructor payloads — Task 05 (project phase), 06 (start stage and SaveProposal), 07 (reviewer hook), 08 (`ApproveProposal` allowlisted; dispatches `ProjectFunded`, `ProjectPeriodChanged` and `ProposalStatusChanged(to: approved)`), 09 (`FundingAgreementRecorded`), 10 and 21 rely on them, and Team B listens to them (Task 33 / B4: template drafting on `ProjectFunded` and `ProposalStatusChanged(to: approved)`, then activation that dispatches `ProjectActivated`; Task 17 / B1: monthly report generation on `ProjectActivated`; Task 31 / B3 and Task 12 / B1: plan and activity-calendar rebase at approval).
````
