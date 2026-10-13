# Task 08 — Proposal approval: grant dates and project funding

> **المهمة:** اعتماد المقترح: التواريخ وتمويل المشاريع (**Approve Proposal**). التفعيل (`active`) صار عند تأكيد MEAL لنموذج التقرير في Task 33 (المراجعة 4).
> **المطوّر:** Dev 1 · **الفريق:** A · **الأيام:** 9–10 (الخميس 2026-10-22 – الجمعة 2026-10-23) · **الجهد:** يومان · **يعتمد على:** Task 04، Task 05
> **متى تستخدمه:** بعد دمج Task 05 (اليوم 8)، وبعد CP2 (نهاية اليوم 6: أصناف الأحداث `ProposalStatusChanged` و`ProjectFunded` و`ProjectActivated` و`ProjectPeriodChanged` موجودة)؛ يتوازى مع آخر يوم من Task 07 (CP3 نهاية اليوم 9) ليشمل إشعار المعنيين، ويجب أن يُدمج قبل Task 09 في اليوم 11 (CP4).
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are implementing Task 08 of the HC Tracking proposal-lifecycle correction plan (Laravel 13, PHP 8.5, Livewire 4, Flux UI 2, Pest). The outcome: one atomic **Approve Proposal** action that moves a proposal from `submitted` or `on hold` to `approved`, records the donor's approval evidence (D-26), fixes the grant's actual begin/end dates, gives every linked draft project those dates, and makes each project whose owner has acknowledged the assignment **`approved` (funded, awaiting the monthly report template)** — and funds the rest automatically the moment their owner acknowledges. This is "Approved (Begin & End Dates) → Project funded" in the corrected story as amended by Revision 4 (draft project without dates → concept note → proposal → donor approval fixes dates and funds the project → MEAL confirms the monthly report template and the project becomes `active` in Task 33 → one agreement). After this task the only road from `draft` to `approved` is `FundProjectFromApprovedProposal`; nothing here makes a project `active`. Approval is the formal funding moment (D-28) that fires the integration events Team B listens to (`ProposalStatusChanged`, `ProjectFunded`, `ProjectPeriodChanged`); Task 09 (agreement gate and expected transfers), Task 13, Task 21, Task 31 and Task 33 rely on it.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins).
2. `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md`, section `### Task 08` — Arabic and authoritative; English table, column, enum, permission and UI names are exact. Also read its «تعديلات المراجعة 2» block (the event contract), sections 0 («قواعد العمل بين الفريقين») and 1–2 (especially 1.0), `### Task 04` and `### Task 05` (contracts you consume), the listener parts of `### Task 09`, `### Task 17`, `### Task 21` and `### Task 31`, section 5 (security matrix), section 7 (CP2, CP4) and rows D-01, D-04, D-05, D-06, D-26, D-28, D-29, D-34 of section 9.
3. `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` if it exists; a recorded decision overrides the plan's default for D-01 / D-04 / D-05 / D-06.
4. Rule files: `.ai/rules/index.md`, `models.md`, `enums.md`, `views-enums.md`, `recipients.md`, `views-components-notifications.md`, `pages.md`, `proposals.md`, `proposalsfunding-agreementstransfersdonors.md`, `admin.md`, `ui.md`, `views.md`, `views-pages.md`, `providers.md`, `tests.md`. Then run `grep -rin 'assignment\|acknowledge\|strict\|afterCommit\|Hide money' .ai/rules`.
5. Study first: `app/Actions/Proposals/TransitionProposal.php` and `app/Support/ProposalTransitions.php` (Task 04), `app/Actions/Projects/{CloseProject,ArchiveProject,RestoreProject}.php`, `app/Policies/{ProposalPolicy,ProjectPolicy,AssignmentPolicy}.php`, `app/Models/{Proposal,ProposalStatusNote,Project,Assignment,HrContract,Program}.php`, `app/Concerns/{HasAssignments,RecordsActivity}.php`, `app/Notifications/{ActivityRecorder,QuickActions}.php`, `app/Notifications/Recipients/{ProposalRecipients,ProjectRecipients,ModuleRecipients}.php`, `resources/views/pages/proposals/⚡show.blade.php`, `resources/views/pages/projects/{⚡show,⚡index}.blade.php` (`submitAssignment`, `ensureOwnerAcknowledgedToLeaveDraft`, `statusLocked`), `database/factories/{ProposalFactory,ProjectFactory,AssignmentFactory}.php`, `tests/Feature/Assignments/ProjectAssignmentTest.php`, `tests/Feature/ProposalDetailActionsTest.php`, `tests/Feature/AccessControl/ActionAuthorizationTest.php`.
6. Activate skills: `laravel-best-practices`, `livewire-development`, `fluxui-development`, `laravel-security`, `testing-best-practices`.
7. Use Laravel Boost `search-docs` before relying on framework APIs (`lockForUpdate`, `DB::transaction`, queued `afterCommit`, `Notification::fake`) and `database-schema` on `proposals`, `projects`, `project_proposal`, `proposal_status_notes`, `assignments` before writing code that depends on columns.

## Preconditions to verify
- `ProposalStatus::OnHold` exists; `TransitionProposal` refuses `approved` even when sent manually; `proposal_status_notes.changed_by` and `is_correction` exist (Task 04).
- The project form rejects dates while `draft` and never offers `active`; `ProjectFactory::draft()` exists; `App\Actions\Projects\CloseProject` exists (Task 05).
- `proposals.donor_id` exists (Task 03).
- The event classes `ProposalStatusChanged`, `ProjectFunded`, `ProjectActivated`, `ProjectPeriodChanged` exist with the CP1 payloads (written by Dev 1 on day 6, CP2). If they do not, write them first exactly as frozen at CP1, before the approval logic, and report it.
- `App\Actions\Proposals\ApproveProposal` and `App\Actions\Projects\FundProjectFromApprovedProposal` do not exist yet; `ProjectStatus::Approved` is a live (non-deprecated) system status and `ProjectFactory::approved()` exists (Task 05, Revision 4); the `ProjectFunded` event class exists (Task 04, Revision 4) — if it does not, write it first exactly as frozen and report it.
- Optional (Task 07): `proposal_reviewers` table and the `proposal_default_reviewers` setting. If absent, build the recipient list without them behind one clearly named method and list it as a follow-up.
- If a required check fails, stop and report instead of improvising.

## Revision 2 changes (2026-10-08)
- Timeline: Team A, days 9–10 (2026-10-22 → 2026-10-23); Task 09 follows on day 11 and closes CP4 (full end-to-end path, end of day 11).
- Approval = formal funding (D-28). A `submitted` proposal shows «بانتظار التمويل» / "Awaiting funding" on the **Project** page; funding happens at `approved`, activation and monthly-report generation at template confirmation (Revision 4).
- Integration events (the contract with Team B; names and payloads frozen at CP1, classes written by Dev 1 on day 6 / CP2 before this logic), dispatched AFTER COMMIT (`DB::afterCommit` / `ShouldDispatchAfterCommit`), never inside the transaction:
  - `ProposalStatusChanged(proposalId, from, to, actorId)` for every transition (dispatched by `TransitionProposal`, Task 04) — and `ApproveProposal` dispatches it for its own `submitted|on hold → approved` move so listeners see every movement (confirm in plan mode).
  - ~~`ProjectActivated` from this task~~ — superseded by Revision 4: this task dispatches `ProjectFunded(projectId, proposalId, startDate, endDate)` once per project that becomes `approved`, from both paths (approval and owner acknowledgement). `ProjectActivated` comes only from Task 33.
  - `ProjectPeriodChanged(projectId, oldStart, oldEnd, newStart, newEnd)` when an already funded or active project's dates are extended (D-05).
- Listeners (queued, retried, failures land in `failed_jobs`, all idempotent; a failing listener never rolls back the approval): Task 33 (B4, template drafting on `ProjectFunded` / `ProposalStatusChanged(to: approved)`), Task 17 (B1, generate one `monthly_reports` row per month on `ProjectActivated` from Task 33, add new months only on `ProjectPeriodChanged`, D-29), Task 31 and Task 12 (B3/B1, rebase proposal milestones, department tasks and the activity calendar by `actual_date_begin − planned_date_begin` at approval, D-34), Task 09 (Dev 1, `GenerateExpectedTransfers` on `FundingAgreementRecorded`), Task 21 (Dev 3 + B4, notifications to MEAL and General Management via `proposal.approved`, `project.funded`, `project.period_changed`). This task writes NO listener.

## Revision 4 changes (2026-10-12)
Source: plan header paragraph «المراجعة 4», section 1.1 steps 7–7أ, section 1.3 (project status line), D-39, and the «تعديلات المراجعة 4» subsection of this task. Where this block conflicts with the Revision 2 block or the base sections, this block wins.
- **Activation moves to Task 33 (D-39).** Rename the planned `ActivateProjectFromApprovedProposal` to `FundProjectFromApprovedProposal`. It gives the dates and turns a draft whose owner acknowledged into **`approved`** (not `active`), and it does **not** dispatch `ProjectActivated`.
- **New event `ProjectFunded(projectId, proposalId, startDate, endDate)`**, dispatched after commit once per project that becomes `approved` (approval path and owner-acknowledgement path). Task 33 listens to it (`DraftTemplateFromProposal`, MEAL notification «أكّد نموذج التقرير»).
- `ProjectActivated` is dispatched only by Task 33's `ActivateProjectFromConfirmedTemplate` (same payload, `via: template_confirmation`). The generation listeners (Task 17 monthly reports) do not change.
- **Already `active` project (renewal / second donor):** its status does not change; it is extended as in D-05 (`ProjectPeriodChanged` when dates move), and `ProposalStatusChanged(to: approved)` lets Task 33 add `pending` items to its template. An already `approved` project (waiting for its first template) receiving a second approval stays `approved`; its dates follow D-05.
- **Transfers (Task 09)** stay tied to the agreement, not to activation, so they never wait for the template.
- **Acceptance wording:** "every `active` project has at least one donor-approved proposal **and a confirmed template**"; the result message lists projects "funded, awaiting report template" instead of "activated".
- **Tests:** approval + owner acknowledgement → `approved` and exactly one `ProjectFunded`, no `ProjectActivated`.

## Revisions 6–8 changes (2026-10-12)
Source: decision-workshop answers (R6 file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.docx`) and two real M&E workbooks (`Syria Cross e.V._ME_Tool_IPTWP_V1_2026_Aug.xlsx`, `Children University - IPTT - 2026.xlsx`). Read the plan header paragraphs «المراجعة 6/7/8», decision rows D-04, D-05, D-26, and the «تعديلات المراجعة 6, 7 and 8» subsections of this task in the plan. Open questions still running on their documented default are listed in `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r7.ar.docx`. Where this block conflicts with text further down this prompt, this block wins.
- D-04/D-04a: `actual_date_begin` defaults to the proposal's `planned_date_begin`; if approval comes later it shifts to the approval date, or a later date with a reason (e.g. first tranche late).
- D-04b: the project becomes `active` when MEAL confirms the monthly report template (Task 33); monthly reports start at the month of `actual_date_begin`, never earlier.
- D-05: Gantt per proposal plus an overall project Gantt; funding gaps keep the project `active` and monthly reports continue.
- **D-26 (alternative):** **ApproveProposal** requires donor-approval evidence: a file (`media` category `donor_approval`) **or** a justification note (`proposals.approval_note`, min 10). Message: «أرفق بريد موافقة المانح أو اكتب ملاحظة توضيحية». Activation (Task 33) without a registered agreement is allowed with that evidence and shows «لا اتفاقية مسجلة بعد» on the **Project** page until one exists; a funded project without an agreement fires `proposal.approved_without_agreement` (Task 21 catalog).
- Tests: approve with neither → rejected; file only → ok; note only → ok; the evidence is visible on the **Proposal** page; the "no agreement yet" warning shows on the **Project** page while no agreement exists.
- **Approve permission (decision log T-04/T-07):** keep `ProposalPolicy::approve` on `proposals.update` (Financial, Public Relations Manager, General Manager) unless CP0 recorded a new `proposals.approve` permission; do not invent one.

## Scope
### In scope
- `App\Actions\Proposals\ApproveProposal` and `ProposalPolicy::approve`.
- `App\Actions\Projects\FundProjectFromApprovedProposal` (called from approval and from owner acknowledgement; Revision 4).
- Owner acknowledgement hook: when an `Assignment` on a `Project` for field `user_id` is acknowledged (`Assignment::acknowledge()`, used by `projects/⚡show::submitAssignment` and `QuickActions::runAssignment`), try funding in the same transaction. Also cover a self-assigned owner (`HasAssignments::syncAssignment` creates an acknowledged row directly) and the re-ask after `Assignment::unblock()`.
- **Approve Proposal** modal on `proposals/⚡show` (`showApproveModal` bound with `wire:model`): actual begin (pre-filled with `planned_date_begin`, or the approval date when that is already past, D-04/D-04a; a later date needs a reason), actual end, decision date, donor-approval evidence (file **or** note, D-26), note, and a preview of linked projects (will be funded / will wait for owner / extended / skipped). The Change Status modal points to it instead of offering `approved`.
- Badges on `projects/⚡show` and `projects/⚡index`: «بانتظار تأكيد المالك» / "Awaiting owner acknowledgement" for drafts linked to an approved proposal, and «ممول، بانتظار نموذج التقرير» / "Funded, awaiting report template" for `approved` projects.
- Activity events `proposal.approved` and `project.funded` (property `via`: `proposal_approval` or `owner_acknowledgement`, plus the proposal id); sentences in `lang/{en,ar}/notifications.php` `events.*`; recipients wiring.
- Result message listing funded, waiting, extended and skipped projects.
- Revision 2 + 4: dispatch `ProposalStatusChanged`, `ProjectFunded` (both funding paths) and `ProjectPeriodChanged` after commit; the «بانتظار التمويل» / "Awaiting funding" badge for projects whose proposal is `submitted`.
- Lang keys in `lang/{en,ar}/proposals.php`, `projects.php`, `notifications.php`.

### Out of scope
- Agreement gate, **Record Agreement** button and expected-transfer generation (Task 09). Project start-day notice, on-hold reminder and the event catalog (Task 21). Every listener of the integration events (Tasks 09, 17, 21, 31). Transition table (Task 04). Project form, `CloseProject`, restore (Task 05). Review loop (Task 07). Note anything else as a follow-up.

## Business rules
- Approval only from `submitted` or `on hold`; anything else is refused with no write.
- Required: `actual_date_begin`, `actual_date_end` (end ≥ begin), decision date ≤ today. The proposal gets status `approved`, both actual dates and one `ProposalStatusNote` (status, note, effective date = decision date, `changed_by`).
- A proposal with no linked projects is refused: «اربط مشروعًا أولًا».
- Per linked project (read from `$proposal->projects()` on the server):

| Project state | Effect |
|---|---|
| draft, no dates, owner acknowledged (or self-assigned) | takes the grant dates, becomes `approved` (Revision 4); `ProjectFunded` |
| draft, no dates, owner not yet acknowledged | takes the grant dates, stays `draft` with the badge; becomes `approved` automatically on acknowledgement (`ProjectFunded` then) |
| locked by owner rejection (`isOwnerRejected()`) | unchanged; the top level is notified (existing ASG behaviour, `HrContract::topLevelEmployeeIds()`) |
| already `approved` (first template not confirmed yet) | stays `approved`; dates follow D-05; its new targets join the same draft template (Task 33) |
| already `active` (renewal / second proposal) | stays `active`; never shortened: start = earliest, end = latest of its approved proposals (D-05); owner notified when the end moves; Task 33 adds `pending` items from `ProposalStatusChanged(to: approved)` |
| `archived` | skipped and named in the result message |
| `closed` | normally impossible: Task 06 returns a closed project to `draft` when it is linked. A project closed AFTER it was linked is skipped and named in the result message; confirm in plan mode |

- Funding on acknowledgement computes dates from ALL the project's proposals with `status->isApprovedByDonor()` (earliest begin, latest end) and is a no-op when the project is not `draft` or has no approved proposal.
- D-04/D-04a/D-04b: the start date defaults to the proposal's planned start and shifts to the approval date when approval is late (or a later date with a reason); the project becomes `active` only at template confirmation (Task 33), and monthly reports start at the month of `actual_date_begin`.
- D-28: approval is the formal funding moment; `submitted` is only "awaiting funding".
- Exactly one `ProjectFunded` per project that actually becomes `approved` (none for projects that stay draft, are skipped or were already `approved`/`active`); one `ProjectPeriodChanged` per funded or active project whose dates change; none when nothing changes. No `ProjectActivated` from this task.
- After commit: notify Finance (**Record Agreement** needed), each project owner, program manager, the MEAL Manager (Task 07 setting), the Public Relations Manager and the proposal's reviewers (Task 07). No amounts in any of these notifications.
- Settled in the plan (Task 05 acceptance, revision 2): a `draft` may hold dates only when it is linked to a donor-approved proposal and waits for its owner's acknowledgement. Extend Task 05's invariant test to exactly that rule; the project form still never gives a draft dates.

## Security and authorization
- `ProposalPolicy::approve`: `proposals.update` (default holders Financial, Public Relations Manager, General Manager, Super Admin; D-01 may change it). `ApproveProposal` calls `Gate::authorize('approve', $proposal)` on the row it re-loads, never on the component's cached copy.
- Projects are read from the relation inside the transaction; no project id is accepted from the client.
- Automatic funding needs no permission from the acknowledging owner, but `ActionAuthorizationTest` requires `Gate::authorize(` in every file of `app/Actions/Projects`. Do not weaken that test silently: either authorize the trigger (`approve` on the proposal, or `respond` on the assignment before it is answered) or add a documented, tested exemption. Decide in plan mode.
- The actor on activity rows is the real user; automatic funding carries `via: proposal_approval` or `via: owner_acknowledgement`.
- Hide money: recipients without `funding-agreements.view` / `transfers.view` / `accounts.view` must never receive amounts; check that `proposal.approved` activity `changes`/`properties` contain no `requested_budget`.

## Validation
- `actualDateBegin`: required, date — ar «حدّد تاريخ بداية المنحة» / en "Set the grant start date."
- `actualDateEnd`: required, date, after_or_equal:actualDateBegin — ar «نهاية المنحة لا تسبق بدايتها» / en "The grant cannot end before it starts."
- `decisionDate`: required, date, before_or_equal:today — add both-language messages.
- `note`: nullable, string, max:2000.
- Wrong source status: «المقترح معتمد بالفعل» when already approved, otherwise the Task 04 "cannot move from {from} to {to}" message.
- Messages in `lang/ar/proposals.php` + `lang/en/proposals.php`.

## Edge cases and concurrency
- Double approval (two clicks or two users): row lock + `expectedFrom`; the second call is refused with «المقترح معتمد بالفعل» and writes nothing.
- Notification failure never rolls back the approval (queued, `afterCommit`). The same holds for any event listener (monthly report generation, plan rebase, transfer generation): they run after commit on the queue and are idempotent.
- Project archived after linking: skipped and reported.
- Owner rejects after approval: project stays draft and locked; after the top level unblocks and the owner acknowledges, it becomes `approved`.
- Owner acknowledges twice / acknowledgement and approval at the same moment: funding is idempotent and both paths lock the project row.
- Legacy data: `active` projects whose proposals are not approved stay untouched; the read-only check query only reports them.

## Data integrity
- One `DB::transaction`: lock the proposal (`lockForUpdate`), check `expectedFrom`, then lock linked projects ordered by `projects.id` ascending (fixed lock order avoids deadlocks), then write proposal + status note + projects.
- Acknowledgement path: wrap `Assignment::acknowledge()` (or the caller) so the assignment update and the funding commit together.
- No schema change is expected; if one becomes necessary, stop and ask.

## Implementation guidance
```php
DB::transaction(function () use ($proposal, $data, $expectedFrom) {
    $proposal = Proposal::query()->lockForUpdate()->findOrFail($proposal->id);
    Gate::authorize('approve', $proposal);
    // refuse unless status === $expectedFrom and in [Submitted, OnHold]
    $projects = $proposal->projects()->orderBy('projects.id')->lockForUpdate()->get();
    // update proposal + ProposalStatusNote + approval evidence, then each project via FundProjectFromApprovedProposal
});
```
- Final Action classes with `handle()`, `declare(strict_types=1);`, `CarbonImmutable` dates.
- Event naming: overriding `activityEventForChange()` on `Proposal` (status → `approved`) and on `Project` (status draft → `approved` → `funded`) keeps one event per change; avoid a second notifying event. Update `ProposalRecipients::isKeyEvent()` for `proposal.approved`.
- `proposals/⚡show`: Flux modal with `wire:model`, `#[Locked]` ids, `x-ui.*` components, logical Tailwind classes, all text through `__()`.

## Tests (Pest)
- `tests/Feature/ProposalApprovalTest.php`: success (status, dates, status note with `changed_by`, approval evidence, project dates, project `approved`); owner not acknowledged → draft with dates, then `approved` on acknowledgement; an already `active` project stays `active`; active project end extended, start never moved later, never shortened; archived skipped and reported; approval from `proposal draft` refused with no write; missing/invalid dates → field messages; user without `proposals.update` → 403 (fresh Livewire instance per forbidden call); double approval → second refused, exactly one status note; proposal with no projects refused.
- Extend `tests/Feature/Assignments/ProjectAssignmentTest.php`: acknowledgement (page and quick action) funds the project (`approved`); rejection keeps it draft and locked; unblock + acknowledge funds it.
- Notifications (`Notification::fake` or the existing activity fan-out assertions): only the listed recipients, no amounts in payloads, nothing sent when the transaction rolls back.
- Events (`Event::fake([...])`): dispatched only after commit; nothing dispatched when the transaction fails or the approval is refused; one `ProjectFunded` per funded project with the right `proposalId` and dates (approval path and acknowledgement path); **no** `ProjectActivated` anywhere in this task; `ProjectPeriodChanged` with old and new dates when a funded or active project is extended; one `ProposalStatusChanged` for the approval move.
- Commands: `php artisan test --compact tests/Feature/ProposalApprovalTest.php`, `--filter=...`.

## Acceptance criteria
- [ ] After approval no linked project lacks dates unless locked by owner rejection.
- [ ] Every project in `approved` or `active` has at least one donor-approved proposal, and every new `active` project also has a confirmed template (Task 33) — test + read-only check queries in the report.
- [ ] The result message names funded (awaiting template), waiting, extended and skipped projects.
- [ ] Proposal `approved` is reachable only through `ApproveProposal`, and project `approved` only through `FundProjectFromApprovedProposal`; existing proposal, project and assignment tests pass.
- [ ] `ProjectFunded`, `ProjectPeriodChanged` and `ProposalStatusChanged` fire after commit only, with the CP1 payloads, and never inside the transaction; this task dispatches no `ProjectActivated`.
- [ ] Approval without evidence (file or note) is refused with the D-26 message.
- [ ] Pint clean; touched test files green.

## Do not
- Fund a project without its owner's acknowledgement, or make any project `active` here (that is Task 33).
- Shorten an active project's dates.
- Send notifications or dispatch integration events inside the transaction or synchronously.
- Change an event's name or payload after CP1, or write Team B's listeners here.
- Accept project ids or a target status from the client.
- Add a default arm to an enum `match`; use `:open` on a Flux modal; leave literal UI text.

## Quality bar (this task)
- **Approve Proposal** modal: the start date is pre-filled with the rule explained under it; the evidence input switches between file and note with the D-26 message; a preview table shows each linked project's outcome as a text badge (will be funded, waits for owner, extended, skipped).
- The result summary lists funded, waiting, extended and skipped projects; project badges read the same on the list and the detail page.
- Everything in `quality-bar.md` applies. Before reporting, open every new or changed screen in the browser (Arabic RTL and English, light and dark, desktop and 375 px), run `vendor/bin/phpstan analyse` on the touched paths, and list both in your report.

## Working method
- Start in plan mode: restate the task in 5 bullets, list files you will touch, list open questions (a project closed after linking, authorization of the funding action, activity event naming, `via` values, `ProposalStatusChanged` on approval); wait for approval.
- Implement in small steps; after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory).
- Do not commit, push, or run destructive commands (`migrate:fresh`, seeders that reset roles, deleting storage) unless the developer asks.
- Never run anything against production.
- Stay inside Task 08's scope; note anything else as a follow-up.

## Report back
Summary; files changed; migrations added (expected none); tests run with pass/fail output; decisions assumed (D-01, D-04, D-05, D-06, D-26, D-28 and the open questions); follow-ups (e.g. reviewer notifications if Task 07 was not merged). Handoff facts: for Task 09 — the approved statuses and that Finance's approval notification points to the proposal page; for Task 13/17/33 — funded and active projects always carry dates; for Task 21 — the `proposal.approved`, `project.funded` and `proposal.approved_without_agreement` events, their `via` property and that `start_date` is set at approval; for Task 27 — the read-only invariant queries. Integration events for the listeners: the exact class names, namespaces and payloads of `ProposalStatusChanged`, `ProjectFunded` and `ProjectPeriodChanged`, where each is dispatched, and that they fire after commit — consumed by Task 09 (Dev 1, `GenerateExpectedTransfers` on `FundingAgreementRecorded`), Task 21 (Dev 3 + B4) and Team B: Task 33 (B4, template drafting on `ProjectFunded` and `ProposalStatusChanged(to: approved)`; it alone dispatches `ProjectActivated`), Task 31 and Task 12 (B3/B1, plan and activity-calendar rebase at approval), Task 17 (B1, generation on `ProjectActivated` from Task 33, extension on `ProjectPeriodChanged`).
````
