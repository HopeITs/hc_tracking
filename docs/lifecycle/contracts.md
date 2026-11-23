# Contracts (frozen at CP1, Wed 14 Oct 2026)

The one place for every name, value, signature, payload, column and permission that crosses a task boundary. **Only Dev 1 edits this file, and both teams must agree** (log each change in `CHANGELOG.md`). Items marked ⚑ are defaults that Dev 1 confirms at CP1; until then, build against them as written.

---

## §1 Proposal statuses and phases
`App\Enums\ProposalStatus` (lower-case values; casts both `proposals.status` and `proposal_status_notes.status`):

| Case | Value | Phase (`ProposalPhase`) | `stage()` | Live |
|---|---|---|---|---|
| ConceptNote | `concept note` | ideation | concept_note | ✔ |
| ConceptNoteReview | `concept note review` | ideation | concept_note | ✔ new |
| ConceptNoteSubmitted | `concept note submitted` | ideation | concept_note | ✔ new |
| ProposalDraft | `proposal draft` | planning | proposal | ✔ |
| ProposalReview | `proposal review` | planning | proposal | ✔ new |
| Submitted | `submitted` | planning | proposal | ✔ |
| OnHold | `on hold` | planning | its own stage | ✘ `@deprecated` (W-06, D-58: history rendering only; no transition in or out, rows migrated back to their pre-hold status with the flag set) |
| Approved | `approved` | implementation | grant | ✔ |
| Active | `active` | monitoring | grant | ✔ |
| Completed | `completed` | archived | grant | ✔ terminal |
| Rejected | `rejected` | archived | stage of the pre-rejection status | ✔ terminal |
| FinalReports | `final reports` | closure | grant | ✘ `@deprecated` (history rendering only; no transition in or out; rows migrated to `active` by T04) |

- `ProposalPhase` gains `Ideation = 'ideation'` as its first case. Phase and stage are derived, **never stored**. Every `match` is exhaustive with no `default`.
- `ProposalStatus::live(): list<self>`: every case except `FinalReports` and `OnHold`. Pickers, filters and charts iterate `live()`, never `cases()`.
- **W-06 (D-58):** "on hold" is `proposals.on_hold_since IS NOT NULL` (§7), allowed only while the status is `concept note submitted` or `submitted`. A proposal on hold keeps its status; `Proposal::isOnHold()` reads the flag and `Proposal::statusLabel()` is the one place the two are put together as "Submitted (On hold)". Its `StatusTone` is `warn`.
- **W-32 (D-55b):** a payroll proposal starts in `proposal draft` (never `concept note`); `ProposalPhase` unchanged.
- `isApprovedByDonor()`: approved, active, completed (and the deprecated final reports).
- "Pre-hold / pre-rejection status" = the status of the latest `proposal_status_notes` row before it entered hold or rejection.

## §2 Proposal transitions
`App\Support\ProposalTransitions` holds this table and nothing else.

| From | To | `requirements()` keys | Writer |
|---|---|---|---|
| concept note | concept note review | `locations` (≥ 1 row with a city), `donor`, `planned_dates` (W-34a; was `linked_project`) | TransitionProposal |
| concept note review | concept note | `reason` | TransitionProposal |
| concept note review | concept note submitted | `effective_date` | TransitionProposal |
| concept note | proposal draft | `reason` (skip reason, D-03) | TransitionProposal |
| concept note submitted | proposal draft | `effective_date` (donor accepted the concept note) | TransitionProposal |
| concept note submitted | rejected | `reason_min_10`, `effective_date` | TransitionProposal |
| proposal draft | proposal review | `budget`, `planned_dates`, `target_beneficiaries` | TransitionProposal |
| proposal review | proposal draft | `reason` | TransitionProposal |
| proposal review | submitted | `effective_date`, `target_beneficiaries` | TransitionProposal |
| submitted | proposal draft | `reason` (donor asked to resubmit) | TransitionProposal |
| submitted | rejected | `reason_min_10`, `effective_date` | TransitionProposal |
| submitted | approved | `locations_complete` (W-34a; was "≥ 1 linked project") | **ApproveProposal only** (T08) |
| approved | active | — | **ActivateProject only** (T08; called by T33) |
| active | completed | `final_report`, `effective_date` | TransitionProposal |
| rejected · completed | any live status except approved | `reason` | **CorrectProposalStatus only** (`is_correction = true`) |

Key meanings:
- `linked_project`: at least 1 linked project. Kept for one release behind the rows (W-34a: linking a project = a row with `project_id`), then removed.
- `locations` (W-34a, D-62): at least 1 `proposal_locations` row with a city. Message `proposals.locations_required`.
- `locations_complete` (W-34a, D-62): every `proposal_locations` row has program **and** responsible **and** (center or `city_wide` or `is_new`), and no `program_requests` row of the proposal is still `pending`. Checked by `ApproveProposal`. Message `proposals.locations_incomplete`.
- `donor`: `donor_id` is set.
- `planned_dates`: `planned_date_begin` and `planned_date_end` are set.
- `reason`: required text.
- `reason_min_10`: at least 10 characters.
- `effective_date`: `before_or_equal:today`.
- `remind_on`: `after:today`, stored in `proposals.status_remind_on`.
- `budget`: `requested_budget` + `requested_currency` are set.
- `target_beneficiaries`: `project_proposal.target_beneficiaries ≥ 1` on **every** linked project.
- `final_report` (D-02c): the final-report milestone is complete, the `media` category `final_report` file exists, and `final_report_accepted_on` + `final_report_accepted_by` are set. A self-funded proposal needs only the milestone + file.

Rules:
- Every move writes exactly one `ProposalStatusNote` (`status`, `context`, `effective_date`, `changed_by`, `is_correction`) in the same transaction as the status. `context` is NOT NULL: a move without a reason writes the translated standard note.
- **A proposal on hold keeps its status (W-06, D-58).** Every move out of `submitted` / `concept note submitted` clears `on_hold_since` and `status_remind_on`, as does approval. `remind_on` is no longer a transition requirement key: `SetProposalOnHold` validates it.
- Messages never reveal an amount (the budget gate message names no figure).

**W-32 (D-55b): payroll rows.** New rows (payroll only; `ProposalTransitions::requirements()` gains the kind from the proposal): `proposal draft → submitted` keys `budget`, `planned_dates`, `narrative`, `effective_date`; `submitted → proposal draft` `reason`; `submitted → rejected` `reason_min_10`, `effective_date`; `submitted → approved` **ApproveProposal only**; `approved → active` **ApproveProposal** (same transaction, right after `approved`: a payroll proposal has nothing to wait for); `active → completed` `final_report`, `effective_date` where `final_report` for payroll = the `final_report` file + `final_report_accepted_on/by` (no milestone). Project-kind rows are untouched. Key meaning `narrative`: `narrative` is set (≥ 50 chars).

*W-32 rulings (4 Nov, for Dev 1 to confirm):*
- `concept note → proposal draft` `reason` is a payroll row **used only at creation**: `SaveProposal` creates the proposal (column default `concept note`, first note) and moves it straight to `proposal draft` through `TransitionProposal` with the standard note "payroll proposal", in one transaction, exactly like the D-03 skip. No other move leads into `concept note`, `concept note review`, `concept note submitted` or `proposal review` for payroll.
- `ProposalTransitions::targets()` and `correctionTargets()` take the kind as well. Payroll correction targets: `proposal draft`, `submitted`, `active`, `completed`, `rejected` (never `approved`, never the current status).
- `final_report` for payroll with an internal (self-funded) donor = the `final_report` file only.
- Hold (W-06) is allowed for a `submitted` payroll proposal; extension (W-08) for an `active` one (no projects to widen).

## §3 Project statuses and transitions
`App\Enums\ProjectStatus`: Draft `draft`, **Approved `approved` (live)**, Active `active`, Closed `closed`, Archived `archived`. `Submitted` is `@deprecated` (history only). `ProjectStatus::live()` = draft, approved, active, closed, archived. **Open** = draft, approved, active (`isOpen()`).

| From | To | Trigger | Writer (only) | Dates |
|---|---|---|---|---|
| — | draft | project created (center + program required) | project form → `SaveProject` (T05) | none (prohibited) |
| draft | approved | a linked proposal is approved **and** the owner has acknowledged (either order) | `ApproveProjectFromProposal` (T08) | set: earliest begin / latest end of its donor-approved proposals |
| approved | active | MEAL confirms the project's report template (≥ 1 confirmed line) | `ActivateProject` (T08), called by `ConfirmReportTemplate` (T33) | kept |
| approved · active | (dates extended) | another proposal of the project is approved | `ApproveProjectFromProposal` (T08) | never shortened; `ProjectPeriodChanged` only when the project is `active` |
| active | closed | manual (`ProjectPolicy::update`) | `CloseProject` (T05) | kept |
| closed | draft | linked to a new proposal | `ReopenProjectAsDraft` (T06) | cleared (history in Activity Log) |
| any | archived | manual (`projects.delete`) | `ArchiveProject` (existing) | kept |
| archived | closed / draft | restore: `closed` if dated, else `draft` (D-27) | `RestoreProject` (T05) | kept |

- **One open project per (`center_id`, `program_id`)** (D-30a): checked server-side, inside the transaction, under a lock, by `Project::openProjectFor(?int $centerId, int $cityId, int $programId, ?int $exceptId = null): ?Project` (T30, W-01: keyed on `center_id ?? city_id`). Create, restore, reopen-as-draft and link all reuse it. No DB unique index.
- A draft holds dates **only** while it is linked to a donor-approved proposal and waits for its owner's acknowledgement (badge "Awaiting owner acknowledgement"). The project form never gives a draft dates. Approved and active projects always hold dates. Invariant test: `ProjectDraftLifecycleTest` (T05, extended by T08).
- Owner-rejected projects (`isOwnerRejected()`, ASG-03) are skipped by approval and stay locked.
- Defensive data migration (T05): legacy `submitted` → `draft` (no dates) or `active` (dated); legacy `approved` → `active` when dated, else `draft`. Print counts before and after.

## §4 Status writers (guard tests enforce this)
- `proposals.status` is written only in: `App\Actions\Proposals\TransitionProposal`, `CorrectProposalStatus`, `ApproveProposal`, `App\Actions\Projects\ActivateProject`, and the T04 data migration. Enforced by `tests/Feature/ProposalStatusWriteGuardTest.php` (source scan). `status` is **not** in `Proposal`'s `#[Fillable]`.
- **W-06:** `proposals.on_hold_since` is set and cleared only in `App\Actions\Proposals\SetProposalOnHold` and `ClearProposalOnHold`, which write no status; `TransitionProposal` and `ApproveProposal` clear it as part of the move they make, and the W-06 data migration sets it once. Enforced by a second source scan in the same guard test. `on_hold_since` is **not** in `Proposal`'s `#[Fillable]`.
- `projects.status` is written only in: `SaveProject` (create = draft), `ApproveProjectFromProposal`, `ActivateProject`, `CloseProject`, `ReopenProjectAsDraft`, `ArchiveProject`, `RestoreProject`, and the T05 data migration. Enforced by `tests/Feature/ProjectStatusWriteGuardTest.php` (T05; T08 extends its allowlist).

## §5 Domain events
Plain Laravel event classes in `app/Events/`, `final`, readonly promoted properties, implementing `ShouldDispatchAfterCommit`. They are dispatched **only after commit**; a refused or rolled-back action dispatches nothing. Listeners are queued, retried, idempotent, and never call back into Phase 1 actions (except the `§6` entry points marked "Team B may call").

| Event | Constructor | Dispatched by | Consumers |
|---|---|---|---|
| `ProposalStatusChanged` | `int $proposalId, ProposalStatus $from, ProposalStatus $to, ?int $actorId, bool $isCorrection = false` | every proposal status writer (§4), once per move | T21 (`proposal.status_changed`), T31 (rebase on `to = approved`), T33 (template pre-fill on `to = approved`) |
| `ProjectActivated` | `int $projectId, ?int $proposalId, CarbonImmutable $startDate, CarbonImmutable $endDate, string $via` | `ActivateProject`; `$via` ∈ {`template_confirmation`} | T21 (monthly reports are generated from `ReportTemplateConfirmed` since Y-03) |
| `ReportTemplateConfirmed` (Y-03, ratified by Dev 1 on 17 Nov) | `int $templateId` | `ConfirmReportTemplate` when the template first turns `confirmed` | T17 (generate the template's monthly reports) |
| `ProjectPeriodChanged` | `int $projectId, CarbonImmutable $oldStart, CarbonImmutable $oldEnd, CarbonImmutable $newStart, CarbonImmutable $newEnd` | `ApproveProjectFromProposal` when an **active** project's dates change | T17 (add months), T21 |
| `FundingAgreementRecorded` | `int $fundingAgreementId, int $proposalId, ?int $actorId` (`$fundingAgreementId` = `donor_proposal.id`) | `SaveFundingAgreement` on create | T09 (`GenerateExpectedTransfers`), T21 |
| `ProposalLocationsChanged` (W-34a, D-62) | `int $proposalId, ?int $actorId, int $added, int $removed, int $changed` (counts only, no names) | `SaveProposal` when the location rows changed; `LinkProposalProject` / `UnlinkProposalProject` | no notification, activity only (`proposal.locations_changed`); W-35 |

Event classes land on `main` as **stubs by CP1** (day 3) and are dispatched by CP2 (day 6), so Team B can write listeners from day 4.

## §6 Team A actions (signatures frozen at CP1)
All are `final`, `declare(strict_types=1)`, with `Gate::authorize` inside and `handle()` as the entry point. A stub that throws `LogicException('Implemented in TNN')` lands by CP1.

```php
// T04, app/Actions/Proposals
TransitionProposal::handle(Proposal $proposal, ProposalStatus $to, ProposalStatus $expectedFrom, array $input): TransitionResult
    // @param array{reason?: string, effective_date?: string} $input   (W-06 dropped remind_on: holds are not moves)
CorrectProposalStatus::handle(Proposal $proposal, ProposalStatus $to, ProposalStatus $expectedFrom, string $reason): TransitionResult
RecordFinalReportAcceptance::handle(Proposal $proposal, CarbonImmutable $acceptedOn): Proposal   // acceptor = auth user
// App\Support\TransitionResult (readonly): ProposalStatusNote $note, list<string> $warnings  (warning keys: no_agreement, all_projects_archived, reviewers_unseen)

// T06, app/Actions/Proposals and Projects
SaveProposal::handle(?Proposal $existing, array $data, User $actor): Proposal
LinkProposalProject::handle(Proposal $proposal, int $projectId, ?int $targetBeneficiaries = null): void
UnlinkProposalProject::handle(Proposal $proposal, int $projectId): void
ReopenProjectAsDraft::handle(Project $project): void          // called inside SaveProposal/LinkProposalProject transactions

// W-06 (D-58), app/Actions/Proposals — the only writers of on_hold_since; neither writes a status
SetProposalOnHold::handle(Proposal $proposal, ProposalStatus $expectedStatus, array $input): ProposalStatusNote
    // @param array{reason: string, remind_on: string} $input   (remind_on: after:today)
    // refused unless the status is `submitted` or `concept note submitted`
ClearProposalOnHold::handle(Proposal $proposal, ProposalStatus $expectedStatus, ?string $note = null): ProposalStatusNote

// W-34a (D-62): SaveProposal also accepts
//   locations?: list<array{city_id: int|string, program_id?: int|string|null, responsible_employee_id?: int|string|null,
//                          center_ids?: list<int|string>, is_new?: bool, new_program_name_en?: ?string, new_program_name_ar?: ?string, city_wide?: bool}>  (city_wide: Y-01, D-88, default false)
//   Validation: city active in HR; centers belong to the city; program exists; responsible is an active HR employee;
//   is_new + no program → both names required, creates/updates the program_requests row. Rows are replaced as a set
//   inside the proposal transaction (diff by (city_id, program_id), delete removed); ProposalLocationsChanged after commit.
//   `projectIds` (create) stays accepted for one release: each id becomes a row with `project_id` through LinkProposalProject.
MaterialiseProposalLocations::handle(Proposal $proposal, int $actorId): MaterialisationResult   // W-34a slice 2, called inside ApproveProposal's transaction before ApproveProjectFromProposal
    // MaterialisationResult (readonly): list<int> $createdProjectIds, list<int> $linkedProjectIds, list<int> $waitingLocationIds, list<string> $warnings (warning key: location_linked_existing)
    // preview(Proposal $proposal): MaterialisationResult  — read-only counts for the approve modal
// LinkProposalProject / UnlinkProposalProject keep their signatures and also insert / delete the matching proposal_locations row (city, program, center copied from the project; responsible = projects.user_id; is_new = false).

// T07
InviteDefaultReviewers::handle(Proposal $proposal, ReviewStage $stage): void   // called inside TransitionProposal's transaction

// T05, app/Actions/Projects
SaveProject::handle(?Project $existing, array $data, User $actor): Project
CloseProject::handle(Project $project): void
RestoreProject::handle(Project $project): ProjectStatus

// T08
ApproveProposal::handle(Proposal $proposal, ProposalStatus $expectedFrom, array $input): ApprovalResult
    // @param array{actual_date_begin: string, actual_date_end: string, decision_date: string, note?: ?string, approval_note?: ?string} $input
    // ApprovalResult (readonly): list<int> $approvedProjectIds, list<int> $waitingProjectIds, list<int> $skippedProjectIds, list<string> $warnings
    // W-32 (D-55b): $input gains nothing; for payroll actual_date_* default to the planned dates when absent; ApprovalResult lists are empty;
    // payroll → approved then active in one transaction (two notes), no project work, ProposalStatusChanged twice after commit
ApproveProjectFromProposal::handle(Project $project, string $via): bool   // $via ∈ {proposal_approval, owner_acknowledgement}; idempotent
ActivateProject::handle(Project $project, string $via): void              // Team B (T33) may call. Requires project approved + dated;
                                                                          // moves its still-`approved` donor-approved proposals → active (one note each)
// W-07 (D-59), app/Actions/Proposals
AddNegotiationNote::handle(Proposal $proposal, ProposalStatus $expectedStatus, array $input): ProposalStatusNote
    // @param array{text: string, file?: UploadedFile|null}; allowed only when status = submitted; Gate::authorize('update', $proposal); writes no status
// W-08 (D-60), app/Actions/Proposals
ExtendProposal::handle(Proposal $proposal, ProposalStatus $expectedStatus, array $input): ProposalStatusNote
    // @param array{new_date_end: string, reason: string, file?: UploadedFile|null}; allowed only for active; Gate::authorize('update', $proposal);
    // new_date_end after the current effective end; not a status writer. Widens linked approved/active projects through App\Support\ProjectPeriods::widen()
// App\Support\ProjectPeriods::widen(Project $project, CarbonImmutable $begin, CarbonImmutable $end): bool
    // saves the wider dates (never shortens); dispatches ProjectPeriodChanged only when the project is active and the dates changed

// T09, app/Actions/FundingAgreements
SaveFundingAgreement (existing; donor written from the proposal)
SaveAgreementInstallments::handle(DonorProposal $agreement, array $installments): void  // @param list<array{sequence:int, amount:string, currency:string, scheduled_date:string}>
GenerateExpectedTransfers  // queued listener of FundingAgreementRecorded + job dispatched after SaveAgreementInstallments; idempotent per installment_id

// W-09 (D-61), app/Actions/Proposals
AttachLibraryRisk::handle(Proposal $proposal, int $resultId, int $libraryRiskId, array $overrides): Risk
    // @param array{likelihood?: int, severity?: int, solution?: string}; result loaded through $proposal->results()->whereKey($resultId)->firstOrFail()
DetachRisk::handle(Proposal $proposal, int $riskId): void

// W-13 (D-64), app/Actions/Tasks
SyncReportTask::handle(DonorReport $report): ?Task
    // creates the task for the current responsible when none is open, reassigns the open one when the responsible changed,
    // completes it when the report is submitted. Called by the assignment and submission actions inside their transactions.
    // No Gate: the caller (the report action) has already authorised.
```

**Post-approval center fill (Y-01, D-83).** On a proposal whose status `isApprovedByDonor()`, `SaveProposal` accepts `locations[]` only as a fill: a row that has **no center and is not city-wide** may gain centers **or** be marked `city_wide`; a row that already has a center or is city-wide is unchanged. Changing or removing a center, or changing a row's city, program or responsible, is refused **outside an approved change request** (`proposals.location_locked_after_approval`). No reason is asked. The change is recorded as `proposal.locations_changed` with the counts; no project is created or linked by this edit (D-74).

**Change request (Y-09, D-89, D-89a).** On an approved proposal `SaveProposal` called directly accepts only the center fill (D-83); any other difference is refused (`proposals.change_request_required`).
- `RequestProposalChange::handle(Proposal $proposal, array $data, string $reason, ?string $expectedUpdatedAt = null): ProposalChangeRequest`. Authorizes `ProposalPolicy::requestChange` (`proposals.update` AND status `isApprovedByDonor()`). `$reason` required, ≥ 10 characters. `$data` takes the keys `SaveProposal` accepts on an approved proposal, plus `locations[]` without the fill-only limit. Validates as `SaveProposal` would, computes the differences, refuses an empty one (`proposals.change_request_empty`), stores a `pending` row with its two review rows and **changes nothing on the proposal**. A slot with no active holder gets its review row as `skipped` at once. Records `proposal.change_requested`, dispatches `ProposalChangeRequested`.
- `ReviewProposalChange::handle(ProposalChangeRequest $request, string $slot, string $verdict, ?string $note = null): ProposalChangeRequestReview`. Authorizes `ProposalPolicy::reviewChange`: the user's employee is the active holder of `$slot` (`Slots`). Only on a `pending` request; `verdict` is `no_objection` or `remarks`; `remarks` needs a note ≥ 10. A reviewer may change their review until the request is decided. Records `proposal.change_reviewed`; when the last waiting review comes in, dispatches `ProposalChangeReviewed`.
- `ApproveProposalChange::handle(ProposalChangeRequest $request, string $expectedStatus = 'pending'): ProposalChangeRequest`. Authorizes `ProposalPolicy::decideChange` (`proposals.approve`). Refused while a review is still waiting (`proposals.change_request_reviews_pending`); a `remarks` verdict does not block. Inside one transaction on the locked proposal: re-validates the payload against the proposal as it is **now**, refuses when a stored `from` no longer matches (`proposals.change_request_stale`), applies it through `SaveProposal`, marks the row `approved`. Records `proposal.change_approved`, dispatches `ProposalChangeDecided`.
- `RejectProposalChange::handle(ProposalChangeRequest $request, string $note, string $expectedStatus = 'pending'): ProposalChangeRequest`. Same policy. `$note` required, ≥ 10. Marks the row `rejected`; the proposal is untouched. Records `proposal.change_rejected`, dispatches `ProposalChangeDecided`.
- A requester who holds `proposals.approve` may decide their own request (D-89a). The requester may withdraw a `pending` request: `WithdrawProposalChange` deletes nothing and marks it `rejected` with the note "withdrawn by the requester".
- *(Y-09, Dev 1 ruling 2026-11-17.)* `SaveProposal` gains two public methods used only by the change-request actions: `changesFor(Proposal $locked, array $data, User $actor): list<array{field: string, from: mixed, to: mixed, row?: array{city_id: int, program_id: int|null}}>` (validates as `update()` would, without the fill-only limit, and returns the differences) and `applyChange(Proposal $locked, array $payload, string $reason, User $actor): void` (writes them; rows through `ProposalLocationRows::replace()`; a beneficiaries change still records `proposal.beneficiaries_changed` with `$reason`). `WithdrawProposalChange::handle(ProposalChangeRequest $request, string $expectedStatus = 'pending'): ProposalChangeRequest` authorizes `ProposalPolicy::withdrawChange` (the requester only) and records `proposal.change_rejected` with `withdrawn: true`. A change that would remove a row which still carries a legacy `project_id` (its city or program changed, or the row dropped) is refused at request (`proposals.location_row_has_project`) until Stage B.

**Centers that already report.** A change that moves or removes a center (or turns a row city-wide or back) is refused (`proposals.location_center_reports`) when a report template of this proposal at that place has a monthly report that is `submitted` or `validated`; checked when the request is opened **and** when it is approved. Otherwise the place's template, its lines and its targets are **not** moved by the approval: the change is applied to the row and the affected templates are listed in the notification for MEAL to settle.

## §7 Schema owned by Team A (frozen at CP1)
**T03:**
- `proposals`: `donor_id` (NOT NULL, FK `donors` restrictOnDelete), `requested_budget` decimal(14,2) null, `requested_currency` varchar(10) null (`Currency`), `status_remind_on` date null, `approval_note` text null (D-26), `final_report_accepted_on` date null, `final_report_accepted_by` FK `users` null nullOnDelete (D-02c), `previous_proposal_id` self FK null nullOnDelete, indexed (renewal chain, T10; cycle guard in T10). Unique `proposals(id, donor_id)`.
- `donor_proposal`: unique `proposal_id`; composite FK `(proposal_id, donor_id) → proposals(id, donor_id)` ON DELETE/UPDATE RESTRICT.
- `project_proposal.target_beneficiaries` unsigned int null (D-25).
- `donors.is_internal` boolean default false, plus seeded internal donor «تمويل ذاتي — Hope» (D-27a).
- ~~`milestones.proposal_id` FK `proposals` null cascadeOnDelete, `milestones.is_final_report` boolean default false~~ **Void (Y-12, on the developer's instruction 20 Nov).** The `milestones` table was dropped on 17 Nov (`2026_11_17_104452_drop_milestones_table`) and replaced by `activity_plan_milestones`, which is keyed on `activity_plan_id` and `plan_indicator_id` and never carried a project. T04's `final_report` check reads the proposal's own final-report media, not a milestone.
- `projects.working_days` JSON null (D-22): sorted distinct ISO weekday numbers 1–7 (1 = Monday, as `WorkingCalendar`); null = the global calendar (Mon–Fri default, `NotificationSettings` `working_days`). Cast `array`. No edit UI in October (§17 item 9). Read only through `WorkingCalendar::forProject()` (§9, T21 slice 2).
- **Y-13 (D-93):** `risks.proposal_location_id` FK `proposal_locations` null cascadeOnDelete, indexed (Y-13, D-93). A register risk belongs to a row and carries that row's `proposal_id`; a logframe risk belongs to a proposal's result and has no row. `project_id` is kept and still written (the row's `project_id`) until Y-18.
- `requested_budget` is in `Proposal::activityExcludedFields()`.

**T04:** `proposal_status_notes.is_correction` boolean default false, `changed_by` FK `users` null nullOnDelete. `MediaCollection`: `final_report`, `final_report_acceptance`.
**T05:** none (data migration only).
**T06:** none.
**T07:** `proposal_reviewers` (`proposal_id` FK cascade, `stage`, `employee_id` no FK, `slot`, `seen_at`, `note`, `added_by` FK users nullOnDelete; unique `(proposal_id, stage, employee_id)`); `meeting_at` datetime null on `proposal_status_notes` ⚑. `MediaCollection::NeedAssessment` (`need assessment`).
**T08:** `MediaCollection::DonorApproval` (`donor_approval`). No table change.
**T09:** `funding_agreement_installments` (`funding_agreement_id` FK `donor_proposal` cascade, `sequence`, `amount` decimal(14,2), `currency`, `scheduled_date`; unique `(funding_agreement_id, sequence)`); `transfers_tracking`: `source` varchar(20) default `manual` (`manual`|`auto`), `installment_id` FK `funding_agreement_installments` null nullOnDelete (unique when not null), `arrival_date` date null.
**T30:** `projects.center_id` unsigned big int null, indexed, **no FK** (HR connection).
> **W-01:** `projects.host_center_id` unsigned big int null, indexed `projects_host_center_id_index`, **no FK** (HR connection). Rule: a project has a physical center (`center_id`) **or** a host center (`host_center_id`), never both and never neither once W-02 ships; `city_id` is always set. The open-project rule keys on `(center_id ?? city_id, program_id)`: `Project::openProjectFor(?int $centerId, int $cityId, int $programId, ?int $exceptId = null): ?Project` — when `$centerId` is null it matches projects with `center_id IS NULL` in that city and program.
**W-06 (D-58):** `proposals.on_hold_since` date null, after `status_remind_on`, no index. "On hold" = not null, allowed only while the status is `concept note submitted` or `submitted`. Plus `proposal_status_notes.kind` varchar(20) NOT NULL default `transition` — **W-07's column, added here because W-06 needs `on_hold` / `off_hold` first**, with W-07's definition exactly (`StatusNoteKind`: `Transition = 'transition'`, `Correction = 'correction'`, `Negotiation = 'negotiation'`, `OnHold = 'on_hold'`, `OffHold = 'off_hold'`, `Extension = 'extension'`); backfill `is_correction = 1` → `correction`. W-07 and W-08 reuse the enum rather than redefine it.

**T34:** `sectors` + `project_sector` (owned by B3, see §12).
**W-04 (D-57):** `donors.approval_status` varchar(10) NOT NULL default `approved` (`DonorApprovalStatus`: `Pending = 'pending'`, `Approved = 'approved'`), `donors.requested_by` FK `users` null nullOnDelete, `donors.approved_by` FK `users` null nullOnDelete, `donors.approved_at` datetime null. Index on `approval_status`.
**W-07 (D-59):** `proposal_status_notes.kind` varchar(20) NOT NULL default `transition` (`StatusNoteKind`: `Transition = 'transition'`, `Correction = 'correction'`, `Negotiation = 'negotiation'`, `OnHold = 'on_hold'`, `OffHold = 'off_hold'`, `Extension = 'extension'`); backfill `is_correction = 1` → `correction`. `MediaCollection::Negotiation = 'negotiation'` (file linked to the note through `media.subject` = the note).
**W-08 (D-60):** `proposals.extended_date_end` date null, `proposals.extension_reason` text null; `MediaCollection::Amendment = 'amendment'` (file linked to the `extension` note, as W-07). `Proposal::effectiveDateEnd(): ?CarbonImmutable` = `extended_date_end ?? actual_date_end`; every reader of the end date for an active proposal (deadline scanners, agreement remaining period, donor report periods, project widening) uses it.
**W-09 (D-61):** `risk_library` (`id`, `title_en`, `title_ar`, `category` varchar(40) null, `default_likelihood`, `default_severity` (same scale as `risks`), `is_active` bool default 1, `sort_order`, timestamps; unique `title_en`); `risks.name_en` varchar(255) null (copied from `title_en` at pick time; `name` carries `title_ar`); `risks.library_risk_id` FK `risk_library` null `restrictOnDelete`; `risks.result_id` FK `results` null `cascadeOnDelete`; `risks.proposal_id` FK `proposals` null `cascadeOnDelete`; `risks.project_id` becomes nullable; index `(proposal_id, result_id)`. Rule: a new risk row has `library_risk_id`, `proposal_id` and `result_id`; `name`/`impact` are copied from the library at pick time so the proposal keeps its wording if the library changes.
**W-24 (D-62):** `proposals.concept_note` text null, after `notes`. It is **not** in `Proposal::activityExcludedFields()`: a change is recorded as `proposal.concept_note_updated` ("concept note updated"), whose sentence names no text. `MediaCollection::ConceptNote = 'concept_note'`: one file per proposal, and a replacing upload keeps the older one in history, as `final_report` does. Neither the text nor the file is a transition requirement key (§2 is unchanged).

**W-34a (D-62):**
```
proposal_locations
  id
  proposal_id        FK proposals cascadeOnDelete
  city_id            unsigned big int, HR id, no FK, NOT NULL
  program_id         FK programs nullOnDelete, NULL allowed
  responsible_employee_id  unsigned big int, HR id, no FK, NULL allowed
  is_new             boolean default false   -- "no program/center exists yet for this row"
  city_wide          boolean default false   -- Y-01, D-88: runs across the city with no center of its own; no proposal_location_centers rows (`proposals.location_city_wide_has_centers` otherwise)
  project_id         FK projects nullOnDelete, NULL  -- filled on approval (or when linked to an existing project)
  sort               unsigned small int
  timestamps
  unique (proposal_id, city_id, program_id)  -- program_id NULL rows are not covered by the unique index; app rule: at most one NULL-program row per city

proposal_location_centers
  proposal_location_id  FK proposal_locations cascadeOnDelete
  center_id             unsigned big int, HR id, no FK
  primary (proposal_location_id, center_id)

program_requests                      -- only for is_new rows without a program
  id
  proposal_location_id  FK cascadeOnDelete, unique
  name_en, name_ar      string
  status                string(20): pending | created | declined
  hr_program_id         unsigned big int null  -- set when HR creates it and PR Manager links it
  requested_by          FK users nullOnDelete
  timestamps
```
- `project_proposal` pivot **stays**. A location row with `project_id` is the same link; `Proposal::projects()` keeps working for everything downstream (approval, aggregation, M&E).
- `projects.city_id` stays required; `projects.center_id` nullable (T30). W-01 adds `host_center_id`. A row with several centers creates **one project per center** (one open project per center + program, contracts §3); a row with no center creates one project on `city_id` only (W-01 rule: physical center or host center, host = the row's assigned center per D-55).
- Morph keys: `proposal_location`, `program_request` (AppServiceProvider + MorphMapTest). Both record activity on the proposal (`RecordsParentActivity`).
- `ProgramRequestStatus`: `Pending = 'pending'`, `Created = 'created'`, `Declined = 'declined'`. Index names: `proposal_locations_proposal_city_program_unique`, `proposal_locations_project_id_foreign`, `proposal_locations_program_id_foreign`, `proposal_location_centers_location_id_foreign`, `program_requests_proposal_location_id_unique`, `program_requests_requested_by_foreign`, `program_requests_status_index`.
> **What the row carries (Y-11, D-93).** `slug` varchar unique null, `status` varchar(50) default `draft` indexed (`ProjectStatus` values; the enum keeps its name), `start_date` date null, `end_date` date null, `schedule_weekend` varchar(10) default `sat-sun`, `working_days` json null, `description` text null, `target_beneficiaries`, `target_men`, `target_women`, `target_boys`, `target_girls` unsigned int null. The row's owner is its `responsible_employee_id`; the owner's acknowledgement is an `assignments` row with `assignable_type = 'proposal_location'`, `field = 'responsible_employee_id'`. Sectors: `proposal_location_sector` (`proposal_location_id` FK cascade, `sector_id` FK restrict, unique pair). Files: `media.mediable_type = 'proposal_location'`. Morph key `proposal_location` is registered in `enforceMorphMap`.
>
> **Until Y-17 the row is a mirror.** `projects` stays the writer; `ProjectLocationMirror::sync(Project $project): void` copies status, period, calendar, targets, sectors and the owner's acknowledgement to every row that carries the project, in the writer's transaction. `target_beneficiaries` comes from `project_proposal.target_beneficiaries` of the row's own proposal, else the project's.
>
> **Successor (D-94).** `App\Support\Locations\ProjectSuccessor::rowFor(int $projectId, ?CarbonInterface $date = null): ?ProposalLocation`: the row carrying the project whose proposal period (`actual_date_begin` … `effectiveDateEnd()`, else planned dates) covers `$date`; when none or several cover it, or `$date` is null, the row of the most recent proposal (latest begin, then highest id). Null only when no row carries the project. `rowsFor(int $projectId): Collection`. Every Stage B backfill maps a project through this class and nothing else; Y-18 deletes it.

- **`proposal_change_requests`** (Y-09, D-89, D-89a): `proposal_id` FK cascade, `status` (`ProposalChangeRequestStatus`: `pending`, `approved`, `rejected`), `requested_by` FK users restrict, `reason` text, `changes` json (list of `{field, from, to}`; location changes as `{field: "locations", row: {city_id, program_id}, from, to}`), `payload` json (the validated input to apply on approval), `decided_by` FK users null nullOnDelete, `decided_at` datetime null, `decision_note` text null (required on reject), timestamps. Index `proposal_change_requests_proposal_status_index` (`proposal_id`, `status`). At most one `pending` row per proposal (`proposals.change_request_pending`). A decided row is never edited or deleted.
- **`proposal_change_request_reviews`** (Y-09, D-89a): `proposal_change_request_id` FK cascade, `slot` (`finance_manager` | `meal_manager`), `verdict` (`ChangeReviewVerdict`: `no_objection`, `remarks`, `skipped`) null while waiting, `note` text null (required with `remarks`), `reviewed_by` FK users null nullOnDelete, `reviewed_at` datetime null, timestamps. Unique (`proposal_change_request_id`, `slot`). Both rows are created with the request.
**W-32 (D-55b):** `proposals.kind` varchar(10) NOT NULL default `project` (`ProposalKind`: `Project = 'project'`, `Payroll = 'payroll'`), `proposals.narrative` text null (payroll only). Index on `kind`. The kind is set on create only and never changes; a renewal keeps it (W-32 ruling: a payroll renewal copies the narrative, donor and dates, never projects, framework or plan).

## §8 Permissions and default holders
Nine roles (fixed): Super Admin, General Manager, Managers, MEAL, Financial, Media Team, Public Relations, Public Relations Manager, Team Member. Super Admin holds everything technically (`Role::booted()`) but **appears in no business list, no fallback and no test as the only account** (D-20a). `permissions:sync` only adds.

| Permission | Default holders | Narrowed by policy to |
|---|---|---|
| `indicator-library.view` | everyone (`$viewEverything`) | — |
| `indicator-library.create/update/delete` | MEAL, General Manager | — |
| `proposals.manage-reviewers` | Financial, Public Relations Manager, General Manager | — |
| `proposals.correct-status` | **General Manager, Public Relations Manager** | — |
| `proposals.approve` | **Public Relations Manager only** (D-70; excluded from General Manager; Super Admin by the code rule). `ProposalPolicy::approve()` reads it; the General Manager is told of every approval through oversight | — |
| `reports.reopen` | MEAL, Public Relations Manager (excluded from GM) | MEAL Manager slot holder; if empty/inactive → active PR Manager holders; never the preparer (D-09a) |
| `reports.aggregate` | Public Relations Manager, General Manager, MEAL (never in `$viewEverything`) | — |
| `meal-coverage.manage` | MEAL (excluded from GM) ⚑ | MEAL Manager slot holder |
| `supervisions.manage` | General Manager | — |
| `sectors.view` | everyone | — |
| `sectors.manage` | MEAL, General Manager | — |
| `report-templates.manage` | MEAL (excluded from GM) | MEAL officer responsible for the project, or MEAL Manager slot holder ⚑ |
| `evidence-types.manage` | MEAL (excluded from GM) | — |
| `narrative-questions.manage` | MEAL (excluded from GM) | — |
| `targets.create/update` | **MEAL only** (revoke from GM and Managers, D-23) | — |
| `funding-agreements.view/create/update` | existing holders **+ Public Relations Manager** (D-31b) | — |
| `proposals.update` | existing (Financial, PR Manager, GM) | also gates approve, transitions, final-report acceptance |
| `projects.create` (W-34a, D-62) | existing (PR Manager, GM) **plus Financial** | approval materialises the proposal's projects through `SaveProject`, so every approver holds it; `permissions:sync` adds it |
| `donors.approve` (W-04, D-57) | **General Manager** only (plus `Super Admin` through the administrator roles rule); `donors.create` is unchanged (PR, PR Manager, GM) | — |
| `risk-library.manage` (W-09, D-61) | MEAL, General Manager; `risks.create/update/delete` keep their holders and now mean "on a proposal's logframe" | MEAL Manager slot holder (add, edit, remove, reorder library entries) |
| `risks.create` / `risks.update` (Y-13, D-93) | existing holders, **or** the location row is in `User::ownedLocationIds()`: creating or changing a register risk is allowed either way. `risks.delete`, `attach` and `detach` are unchanged | the row's responsible employee, and the manager of the row's program |

No `media-deliverables` module: department tasks use `tasks.*` with a server-side department condition (T31). (The `/ milestones.*` half of this sentence is void: the table was dropped on 17 Nov — see §12.2.)

## §9 Settings and slots
- `notification_settings` key `proposal_default_reviewers` = `{meal_manager: ?int, finance_manager: ?int, fundraising_manager: ?int, ceo: ?int}` → **HR position id** (not a person). It resolves to the current holder (active contract) at use time through `EmployeeProfile`. A vacant slot → a warning, skipped.
- `App\Support\Slots::holderOf(string $slot): ?int` (employee id; T07) is the one resolver used by T07, T08, T14, T15, T17 (`meal_manager`) and T21.
- **HR liaison slot (W-34a, D-62):** `notification_settings` key `hr_liaison_position` = `?int` HR position id (default null), resolved by `Slots::holderOf('hr_liaison')` with the same vacancy rule; the direct recipient of `program_request.opened` (§15.2). Not a reviewer slot (`ReviewerSlot` unchanged). The admin select lands with W-34a slice 3.
- `App\Enums\ReviewerSlot`: `meal_manager`, `finance_manager`, `fundraising_manager`, `ceo`, `project_owner`, `program_manager`, `additional` (declaration order = priority). `App\Enums\ReviewStage`: `concept_note`, `proposal`.
- `monthly_report_due_working_days` default 5 (T17).
- **Working calendar (D-22):** `App\Notifications\Alerts\WorkingCalendar::forProject(Project $project): WorkingCalendar` (T21 slice 2) returns a calendar with the same API (`isWorkingDay`, `addWorkingDays`, `lastWorkingDayOnOrBefore`, …) over `projects.working_days`, else the global working days. Callers that have a project use it; the rest keep the global calendar.

## §10 HR read model (T30)
```php
final class App\Support\EmployeeProfile {
    /** @return array{employee_id:int, position_id:?int, position_title_en:?string, position_title_ar:?string, job_level:?string, grade:?int,
     *                department_id:?int, program_id:?int, center_id:?int, city_id:?int, direct_manager_employee_id:?int, is_active:bool}|null */
    public static function for(int $employeeId): ?array;
    /** @return list<int> */ public static function employeeIdsAtCenter(int $centerId): array;
    /** @return list<int> */ public static function employeeIdsInDepartment(int $departmentId): array;
    public static function managerOf(int $employeeId): ?int;          // HR direct manager (positions.direct_manager_id holder)
    public static function holderOfPosition(int $positionId): ?int;   // active-contract holder, latest start_date
    public static function isDepartmentManager(int $employeeId): bool; // W-14 (D-65)
}
```
- Current position = the active contract (`contracts.is_active = 1`); several active → latest `start_date` (logged warning); none → `null` / inactive.
- **W-14 (D-65):** `isDepartmentManager()` is the one definition of "department manager": the employee's current position's job level matches `config('access-control.department_manager_job_level')` (default `Department Manager – Grade 5`). The comparison is on a normalised string (case, whitespace and the dash variants `-` `–` `—` `‑`) against `job_levels.name_en` **or** `name_ar`, so it does not depend on the interface language; it reads the remembered lookup, adds no HR query, and stores nothing locally. `User::canSeeDepartmentTasks()` delegates to it and is the only gate of the desk Tasks panel's `department` scope and the `department-tasks` page; administrator roles (§8) bypass it, and `Managers` / General Manager get it only when their HR job level matches.
- `App\Models\HrCenter` (`centers`) and `HrJobLevel` (`job_levels`) extend `HrModel` (read-only), with `options()` via `RemembersHrLookup`.
- `Project::center()` resolves through the lookup (no cross-connection relation). The project's city is always the center's city, written by the server.
- `Project::openProjectFor(?int $centerId, int $cityId, int $programId, ?int $exceptId = null): ?Project` (see §3; W-01).

**Supervision (T23, D-13)**
```php
final class App\Support\SupervisionMap {
    /** @return list<int> active supervisee employee ids: HR direct reports of $supervisorEmployeeId + local rows */
    public static function superviseesOf(int $supervisorEmployeeId): array;
    /** @param list<int> $superviseeEmployeeIds  @return array<int, list<int>> supervisee id => active supervisor employee ids */
    public static function supervisorsOf(array $superviseeEmployeeIds): array;
    public static function supervises(int $supervisorEmployeeId, int $superviseeEmployeeId): bool;
}
```
- Merges `EmployeeProfile::managerOf()` (default source) with local `supervisions` rows (exceptions and additions only). Active HR employees only, de-duplicated, memoized per request.
- Supervision grants **no permission and no visibility**. T17's "prepare on behalf" (§13.1 item 9) still requires `reports.create/update`.
- Table `supervisions`: `supervisor_employee_id`, `supervisee_employee_id` (unsigned big int, HR ids, no FK), `created_by` FK `users` null nullOnDelete, timestamps; `pair_low`/`pair_high` stored generated columns (`LEAST`/`GREATEST`) with unique `supervisions_pair_unique`; index `supervisions_supervisee_idx`. Model `App\Models\Supervision`, morph key `supervision`, `activityModule()` = `access-management`.
- Permission `supervisions.manage` (§8). Actions `AddSupervision`, `RemoveSupervision` in `app/Actions/Supervisions`. Catalog keys in §15.2.

## §11 Indicator Library and logframe (Team B: T11 schema at CP1, T12 schema at CP2)

### §11.1 `library_indicators` (T11)
| Column | Type | Rule |
|---|---|---|
| `code` | varchar(50) NOT NULL, unique | **server-generated** `{program code}.{level_ref}.{serial}` (e.g. `002.OP1.2.3`); prefix `HC` when `program_id` is NULL; frozen after first use (D-10) |
| `program_id` | unsigned big int null, **no FK** (HR) | null = institutional |
| `level_ref` | varchar(20) | regex `^(G\|CC\|OC\d+\|OP\d+(\.\d+)*\|AC\d+(\.\d+)*)$` |
| `serial` | unsigned smallint | next within (`program_id`, `level_ref`) under `lockForUpdate`; never reused |
| `name_ar` | varchar(255) **required** | |
| `name_en` | varchar(255) null | "Missing English name" badge; falls back to Arabic |
| `definition`, `calculation_method` | text null | |
| `measure` | varchar(50) default `numeric` | `IndicatorMeasure`: `numeric`, `percentage`, **`average`** (new). Locks once any linked indicator has targets |
| `unit` | varchar(50) null | cleared for percentage |
| `numerator_label`, `denominator_label` | varchar(255) null | input labels for ratio lines |
| `denominator_basis` | varchar(20) null | `DenominatorBasis`: `fixed` (count ÷ `target_beneficiaries`; default when `collected_by = project_team`) · `entered` (numerator + denominator; default when `meal`). Percentage only |
| `is_disaggregated` | bool default false | on → always male / female + persons with disability per sex (D-50) |
| `collected_by` | varchar(20) default `project_team` | `IndicatorCollector`: `project_team`, `meal`. Default for the template line's `entered_by` |
| `is_cumulative` | bool default false | cumulative → last approved value (D-25) |
| `frequency` | varchar(50) | `CollectionFrequency`: `monthly`, `quarterly`, `semi-annual`, `annual`, `per-event`, `per-season`, `follow-up`, `baseline-endline`, `one-time`. Default schedule for template lines |
| `frequency_note` | text null | the logframe's own wording |
| `follow_up_months` | smallint null | required when `frequency = follow-up` |
| `suggested_level` | varchar(50) null | `ResultType` |
| `data_source`, `means_of_verification` | text null | |
| `is_legacy` | bool default false | true for entries seeded from today's indicators |
| `is_active` | bool default true | changed only by deactivate/reactivate |

- `indicators.library_indicator_id` FK `library_indicators` null `restrictOnDelete`. `indicators.code` is copied from the library on attach (read-only).
- **Measure mirror:** a linked `indicators.measure` always equals its library `measure`; `SaveLibraryIndicator` propagates it in the same transaction.
- **Achievement:** `PlanIndicator::measuresLevel()` (renamed from `measuresPercentage()`) = percentage **or** average → latest approved value; counts sum. PHP and SQL forms change together.
- API: `LibraryIndicator::active()` scope, `localizedName(): string`, `CollectionFrequency::period(): ?Period`, `App\Support\IndicatorCode::next(?int $programId, string $levelRef): array{code: string, serial: int}`.
- Morph key `library_indicator`; events `library_indicator.created|updated|deactivated|reactivated`.

### §11.2 Logframe (T12)
- `ResultType`: `impact`, `outcome`, `output`, **`activity`**, **`cross-cutting`**. Parent exactly one level up (activity → output → outcome → impact; impact has no parent; nothing under an activity). `cross-cutting` is a root only (no parent, no children). Several impact roots per program are allowed (tracks).
- `results`: `proposal_id` FK `proposals` null `restrictOnDelete` (null = program framework); `contributes_to_result_id` self FK null `nullOnDelete` (proposal roots only, pointing to a program-framework result of one of the proposal's programs); `copied_from_id` self FK null `nullOnDelete` (T10/T32); `activity_kind` varchar(20) null (`ActivityKind`: `continuous` default, `event`; activities only).
- A child carries its parent's `proposal_id` and `program_id`. A proposal root's `program_id` = the shared program of its linked projects, else NULL ("Multi-program", D-24).
- Scopes `Result::programFramework()` (`proposal_id IS NULL`), `Result::proposalFramework()`. Relations `proposal`, `contributesTo`, `contributions`, `copiedFrom`, `milestones`; `Proposal::results()`.
- ~~`milestones.result_id` FK `results` null `nullOnDelete`… `Milestone::activity()`, `Milestone::activityLinkIssues()`~~ **Void (Y-12, on the developer's instruction 20 Nov).** `milestones` was dropped on 17 Nov with its model, observer, policy and `app/Actions/Milestones/*`. `activity_plan_milestones` carries `plan_indicator_id` instead, so an activity link is read through the plan indicator, not through a milestone. `LinkMilestoneToActivity` no longer exists.
- `activity_schedules`: `result_id` FK `results` cascade (activity), `project_id` null (per-project override; null = proposal calendar), `month` date (first day), `weeks` JSON null (1–5, Monday-start; null = whole month); unique (`result_id`, `project_id`, `month`). Edited before approval by MEAL and the proposal editor, after approval by MEAL only (logged). Shifted once at approval by (actual − planned start) months, keeping weeks.
- Strategy (T32): `strategic_pillars` (`strategy_id` FK restrict, `code`, `name_ar`, `name_en` null, `goal_ar/en` null, `sort_order`; unique (`strategy_id`, `code`)); `programs.strategic_pillar_id` FK null nullOnDelete; `Program::pillar()`. Pillar filter path for T18: project → `program_id` → `programs.strategic_pillar_id`. Also `indicators.is_tracking_only`, `indicators.copied_from_id`, `library_indicators.imported_at` (T32); `CopyResultTree::handle(Result $sourceRoot, Proposal $proposal): Result`.
- Structural lock: after the proposal reaches `submitted`, proposal-framework nodes are created/moved/retyped/deleted only by `results.update` holders.
- Actions (T12): `SaveResult::handle(?Result $existing, array $validated, ?Proposal $proposal = null): Result`, `DeleteResult`, `LinkMilestoneToActivity::handle(Milestone $milestone, ?int $activityId): Milestone`, `SaveActivitySchedule`, and `App\Actions\Proposals\DeleteProposal::handle(Proposal $proposal): void` (**the one Team B file in `app/Actions/Proposals`; Dev 1 reviews it**).

## §12 Targets, planning, coverage, template, sectors

### §12.1 Per-center targets (T13)
- A proposal target = a `plan_indicators` row with `source_type = 'proposal'`, `source_id` = proposal id, `project_id` = the center's project, `indicator_id` = an `indicators` instance under a node of **that proposal's** framework and linked to a library entry. One per (source, indicator, project): new unique index `plan_indicators_source_indicator_project_unique` (`source_type`, `source_id`, `indicator_id`, `project_id`), with a duplicate pre-check before DDL.
- **Place of a target (Y-08, D-75, D-85):** `plan_indicators.city_id` unsigned big int null (HR id, no FK, indexed), `program_id` FK `programs` null restrictOnDelete, `center_id` unsigned big int null (HR id, no FK, indexed), `city_wide` boolean default false (D-88; true only with `center_id` null; `center_id` null and `city_wide` false = "center not set", D-82), `all_centers` boolean default false (D-90; true only with `center_id` null and `city_wide` false: a **shared target**, reached together by every center of its proposal's location row for that city and program). Index `plan_indicators_place_index` (`city_id`, `program_id`, `center_id`, `city_wide`). A target with no `project_id` has no place (all three null, `city_wide` false). `project_id` is kept and still written; the unique index `plan_indicators_source_indicator_project_unique` is unchanged. `PlanIndicator::place(): ?ReportPlace` (null when `city_id` is null or the target is shared); `PlanIndicator::coversPlace(ReportPlace $place): bool` (its place equals it, or it is shared and the place's center is one of its row's centers); `PlanIndicator::query()->atPlace(ReportPlace $place)` scope. Reporting reads a target's place only through these, never through `project_id`.
- **Target of a row (Y-10, D-91):** `plan_indicators.proposal_location_id` FK `proposal_locations` null restrictOnDelete, indexed. Set on every `proposal` target; null on annual-plan targets (D-44). Unique `plan_indicators_indicator_location_unique` (`indicator_id`, `proposal_location_id`): one target per indicator per row. The target's place is written from its row in the same save: one center → `center_id`; a field-work row → `city_wide`; several centers → `all_centers` (D-90); no center yet → none. `project_id` is still written, with the row's `project_id` (null while the row has no project).
  > Also (Y-10, agreed at plan with the developer, **ratified by Dev 1 (23 Nov)**; for an approved proposal, removing a row or its targets is revised by **D-96** and replaced by deactivation in Y-19): `PlanIndicator::placeColumnsForRow(ProposalLocation $row)` is the one rule for the five place columns of a row's target; `PlanIndicator::syncPlaceFromRow(ProposalLocation $row): int` rewrites the place **and `project_id`** of the row's targets in the same transaction whenever the row's centers, field-work flag or project change (called only from `ProposalLocationRows`). A row that carries targets is never removed on its own: `ProposalLocationRows` refuses on `locations` with `proposals.location_has_targets`. Before that, every place that removes a row asks first (project manager, 19 Nov): removing the row in the proposal form, and unlinking its project, open a dialog that says how many targets the row carries and how many have reports, links to the proposal's targets tab, and offers "delete the targets" to a holder of `targets.delete` when none is reported. `ClearLocationTargets::handle(Proposal $proposal, int $locationId): int` deletes a row's targets (`targets.delete`; the row loaded through the proposal; one reported target refuses the call on `targets` with `targets.location_targets_reported`; one `proposal.targets_saved` summary). `UnlinkProposalProject::handle(Proposal $proposal, int $projectId)` keeps its frozen signature (§6) and refuses on `project` with `proposals.location_has_targets` when the project's row carries targets; `UnlinkProjectWithTargets::handle(Proposal $proposal, int $projectId): void` (`update` on the proposal AND `targets.delete`) deletes the row's targets through `ClearLocationTargets`, then unlinks, in one transaction. Deleting a proposal deletes its targets on `deleting`, before the database cascade reaches its rows; its dialog says how many go with it and links to them, and `DeleteProposal` now refuses (`results_framework.proposal_delete_reported`) when any target committed in the proposal has reports, not only one on its framework. Counts come from `App\Support\TargetCounts::ofLocation()` / `ofProposal()`. A row still waiting for its program (no `program_id`, or a pending `program_requests` row; `SaveProposalTargetsGrid::isWaitingForProgram()`) takes no new or changed target: the grid shows its column read-only with the reason, and `SaveProposalTargetsGrid` refuses on `cells.{i}` with `targets.cell_row_waiting_program`; clearing a saved one stays allowed (Y-10 slice 2).
- `plan_indicators.target_population` decimal(12,2) null: for percentage targets, the display base (defaults from `project_proposal.target_beneficiaries`; MEAL may edit). Display only ("80% × 180 = 144").
- Empty cell = no target; `0` = a real zero target. Percentage ≤ 100; average uncapped. Targets are entered separately per proposal/donor; combining happens only in displays (`shared` → the highest target; `split` → the sum).
- Writers: MEAL only (`targets.create/update/delete`, D-23). Editable in every non-terminal proposal status (from `concept note`); read-only for `rejected`/`completed`. Once a target has reports, its indicator and source lock (value and period stay editable).
- Picker scope (D-10a): library entries of the programs of the proposal's projects, plus institutional ones.
- `indicators.is_tracking_only` bool default false (T32): selectable, but no `plan_indicators` row.
- Actions: `AttachLibraryIndicatorToResult::handle(Proposal $proposal, int $libraryIndicatorId, int $resultId): Indicator`, `DetachIndicatorFromResult::handle(Indicator $indicator): void`, `SaveProposalTargetsGrid::handle(Proposal $proposal, array $cells): array{created:int, updated:int, deleted:int}` (atomic; `ValidationException` names the offending cells).
- **Cell shape (Y-10, D-91):** `SaveProposalTargetsGrid::handle(Proposal $proposal, array $cells)`: each cell is `{indicator_id, location_id, value, period, target_population, version}`. `location_id` must be a row of this proposal (BOLA: one foreign id rejects the batch). `project_id` is no longer accepted in a cell.
- Reading targets of a project in a period: `PlanIndicator::query()->forProjectInWindow(int $projectId, CarbonImmutable $from, CarbonImmutable $to)` scope (T13), reused by T17/T18/T19/T33.

### §12.2 Proposal planning (T31)
- ~~`milestones.proposal_id` + `is_final_report`; T31 makes `milestones.project_id` nullable…~~ **Void (Y-12, on the developer's instruction 20 Nov).** The `milestones` table was dropped on 17 Nov. `activity_plan_milestones` (`activity_plan_id`, `plan_indicator_id`, `title`, `due_date`, `measure`, `threshold`, `completed_at`) replaced it: it belongs to an activity plan and a plan indicator, **not** to a project or a location row, so Stage B has nothing to move and gives it no `proposal_location_id`.

> **Tasks (Y-12).** `tasks.source_type` gains `proposal_location`; the `project` source is no longer written by new code. `CreateTask` and `MoveTask` take `proposal_location` / `location:ID`. Visibility: a task whose source is a row in `User::ownedLocationIds()`, or whose source is a program the user manages. A task's `project` source is backfilled to the row `ProjectSuccessor::rowFor($projectId, due date ?? created_at)` picks (D-94); `source_id` keeps pointing at the project only for rows the backfill could not map, which are listed and left (D-80).
>
> **Schedule (Y-12).** `schedule_dependencies.proposal_location_id` and `schedule_baselines.proposal_location_id` FK null cascadeOnDelete replace `project_id` as the owner; both ends of a dependency are on the same row. The working calendar is the row's (`schedule_weekend`, `working_days`, Y-11).
- `tasks`: `department_id` (HR id, no FK, indexed), `kind` varchar(30) default `general` (`TaskKind`: `general`, `media_deliverable`, `financial`, `report` (W-13, D-64)), `media_kinds` JSON null (`DeliverableKind`: `video`, `photos`, `file`, `text`), `delivery_state` varchar(20) null (`DeliveryState`: `requested`, `delivered`, `accepted`, `returned`), `requested_on`, `delivered_on` date null, `delivered_link` varchar(2048) null, `delivered_text` text null, `accepted_at` datetime null, `returned_reason` text null.
  - **W-13 (D-64):** `tasks.reportable_type` varchar(255) null, `tasks.reportable_id` unsigned big int null, index `tasks_reportable_index` (`reportable_type`, `reportable_id`); morph map key `donor_report` (later `monthly_report`). At most **one open** (status ≠ done) report task per reportable, enforced in `SyncReportTask` under the report's lock.
- `proposal_plan_shifts` ledger: `proposal_id` FK cascade, `approval_note_id` FK `proposal_status_notes` (unique), `delta_days` int, `shifted` int, timestamps. Shift once per approval.
- Rebase trigger: `ProposalStatusChanged` with `to = approved` (**not** `ProjectActivated`). Delta = `actual_date_begin − planned_date_begin`. Planned dates are clipped to `actual_date_end`. Dates a user edited after creation are left alone (tracked by `milestones.planned_dates_edited_at` / `tasks.planned_dates_edited_at`, datetime null).
- Visibility: a department task is visible to `tasks.view` holders whose current HR department (`EmployeeProfile`) equals `tasks.department_id`, its assignee, and proposal-rights holders. `financial` tasks: Finance only. No money anywhere on **Department Tasks**.
- Actions: `SaveProposalMilestone`, `SaveDepartmentTask`, `DeliverDepartmentTask`, `AcceptDepartmentTask`, `ReturnDepartmentTask`, `RebaseProposalPlan::handle(Proposal $proposal, ProposalStatusNote $approvalNote): array{shifted:int, clipped:list<int>, skipped:bool}`.

### §12.3 MEAL coverage (T15)
- **`meal_coverages`** (Y-14): `scope_type` (`MealCoverageScope`: `city`, `place`), `city_id` unsigned big int (HR id, no FK), `program_id` FK `programs` null cascadeOnDelete, `center_id` unsigned big int null (HR id, no FK), `city_wide` boolean default false, `employee_id` (HR id), `assigned_by` FK users null nullOnDelete, timestamps. A `city` row has no program. A `place` row has a program and either a center or `city_wide`. A stored unique `scope_key` ensures one officer per governorate and per place. `project_id` remains nullable and is no longer written. Y-18 drops it.
- **API (`App\Support\MealCoverage`).** `responsibleForPlace(ReportPlace $place): ?int` resolves the place's officer, then the governorate's officer, then the MEAL Manager slot, then null. `responsibleForLocation(ProposalLocation $row): list<int>` returns distinct officers across the row's places, with governorate coverage when no center is set. `covers(int $employeeId, ReportPlace $place): bool` checks responsibility. `scopeCoveredBy(Builder $query, int $employeeId)` matches directly assigned places and unassigned places in the employee's governorates. `responsibleFor(Project)` and `projectsCoveredBy()` are removed.
- Managed by holders of `meal-coverage.manage` **who hold the MEAL Manager slot**. Events `meal_coverage.assigned`, `meal_coverage.responsible_changed`, `meal_coverage.place_assigned` (notify new, previous and governorate officers).

### §12.4 Monthly report template and activation gate (T33)
- **`evidence_types`**: `name_ar`, `name_en` null, `is_active`, `sort_order`. Managed by `evidence-types.manage` (D-42).
- **`narrative_questions`** (the org-wide bank): `text_ar`, `text_en` null, `frequency` (`monthly` | `quarter_end`), `sort_order`, `is_active`. Managed by `narrative-questions.manage` (D-43).
- **`report_templates`** (Y-02, D-75, D-76): `city_id` unsigned big int (HR id, no FK, indexed), `program_id` FK `programs` restrict, `center_id` unsigned big int null (HR id, no FK, indexed), `city_wide` boolean default false (D-88; true only with `center_id` null: the program runs across the city; `center_id` null and `city_wide` false = "center not set", D-82), `status` (`ReportTemplateStatus`: `pending`, `confirmed`), `confirmed_at`, `confirmed_by` FK users null nullOnDelete, timestamps. Index `report_templates_place_index` (`city_id`, `program_id`, `center_id`, `city_wide`), **not unique**: two templates may share a place. `project_id` is kept, nullable, no longer unique, filled only for templates that existed before Y-02; nothing written in or after Y-02 reads it (D-74).
- **`proposal_report_template`** (pivot, Y-02): `proposal_id` FK cascade, `report_template_id` FK cascade, `joined_at` datetime, `joined_by` FK users null nullOnDelete; unique (`proposal_id`, `report_template_id`). A template has ≥ 1 proposal; a self-funded place is carried by a proposal whose donor `is_internal` (D-87), never by a template without one.
- **Place.** `App\Support\Reporting\ReportPlace` (readonly: `int $cityId`, `int $programId`, `?int $centerId`, `bool $cityWide`; `isUnset(): bool` = no center and not city-wide; `equals(self): bool`, `key(): string`). `ReportTemplate::place(): ReportPlace`. `ReportTemplate::query()->atPlace(ReportPlace $place)` scope.
- **Bridge (D-74, D-85).** `App\Support\Reporting\PlaceBridge` is the only class that may read `projects` for reporting: `placeOfTarget(PlanIndicator): ?ReportPlace` returns `$target->place()` and `targetCoversPlace(PlanIndicator, ReportPlace): bool` returns `$target->coversPlace($place)` (Y-08); neither reads `projects` any more, `placesOfProposal(Proposal): list<ReportPlace>` (from `proposal_locations` and `proposal_location_centers`; one place per row and center, one city-wide place for a `city_wide` row, one unset place for a row with neither), `legacyProjectIdFor(ReportTemplate): ?int` (the template's own `project_id` when set, else the `project_id` of a location row of one of its proposals at its place; null when none).
>   Also (Y-02, ratified by Dev 1 on 17 Nov), each removed with the task named: until Y-08, `isSharedTarget(PlanIndicator): bool` (its project has no center and its row has several centers) and `targetBeneficiariesOf(PlanIndicator): ?int` (`project_proposal.target_beneficiaries` of the target's project, a `fixed` line's denominator); until Y-17, `placesOfProject(Proposal, int $projectId): list<ReportPlace>` (the places of the proposal's rows that carry the project), `proposalsOfProject(int $projectId, list<string> $statuses): Collection<int, Proposal>`, `legacyProjectIdsFor(iterable<ReportTemplate>): array<int, ?int>` (batch form of `legacyProjectIdFor`, template id ⇒ project id), `legacyProjectFor(ReportTemplate, bool $lock = false): ?Project`, `projectsByIds(list<int>): Collection<int, Project>`, `templateIdsOfProject(int $projectId): list<int>` (templates carrying the project's id, plus those at the places of the rows that carry it, for the row's proposal).
>   Also (Y-08, ratified by Dev 1 on 17 Nov), until Y-10 replaces their project branch with the location row (D-91): `placeForTarget(Model $source, ?int $projectId): ?ReportPlace` (the place a new or re-pointed target takes, by the backfill rule) and `placeColumnsForTargets(Model $source, list<int|null> $projectIds): array<int|string, array{city_id: int|null, program_id: int|null, center_id: int|null, city_wide: bool, all_centers: bool}>` (the same rule as the five columns a writer stores, keyed by project id, `''` for none; one query per save, and it carries `all_centers`, which a `ReportPlace` cannot). `isSharedTarget(PlanIndicator)` stays and returns `$target->all_centers`; `targetBeneficiariesOf(PlanIndicator)` stays until Y-10.
- **`report_template_lines`**: `report_template_id` FK cascade, `plan_indicator_id` FK restrict (the target: carries proposal/donor/center; proposal targets or annual-plan org-wide targets), `status` (`ReportTemplateLineStatus`: `pending`, `confirmed`, `retired`), `schedule` (`LineSchedule`: `library_default`, `monthly`, `selected_months`, `once_after_months`), `schedule_months` JSON null (calendar month numbers 1–12), `once_after_months` smallint null, `entered_by` (`IndicatorCollector`; defaults from the library `collected_by`), `fixed_denominator` unsigned int null (from `project_proposal.target_beneficiaries` when the library `denominator_basis = fixed`), `effective_from` date (first month), `effective_to` date null (last month), `confirmed_at`, `confirmed_by`, timestamps. Unique (`report_template_id`, `plan_indicator_id`).
- **`report_template_line_evidence_types`** (pivot): the required evidence types per line.
- **`report_template_questions`**: `report_template_id` FK cascade, `narrative_question_id` null nullOnDelete, `text_ar`, `text_en` null, `frequency`, `sort_order`, `is_active`. The bank is copied in when the template is created; MEAL edits it per project.
- **Template question actions (Y-07).** All authorize `ReportTemplatePolicy::manage` and load the question through `$template->questions()` (BOLA):
  - `SaveReportTemplateQuestion::handle(ReportTemplate $template, ?int $questionId, array{text_ar: string, text_en?: ?string, frequency: string} $data, ?string $expectedUpdatedAt = null): ReportTemplateQuestion` (create when `$questionId` is null: appended last in its frequency, `narrative_question_id` null);
  - `MoveReportTemplateQuestion::handle(ReportTemplate $template, int $questionId, string $direction): void` (`up` | `down`, within its frequency);
  - `SetReportTemplateQuestionActive::handle(ReportTemplate $template, int $questionId, bool $active): void`;
  - `DeleteReportTemplateQuestion::handle(ReportTemplate $template, int $questionId): void`: refused (`report_templates.question_has_answers`) when any `monthly_report_answers` row points at it; switch it off instead.
- A change applies to reports still in `draft`. A `submitted` or `validated` report keeps its answers and the `question_text` stored at answer time (D-41). The bank (`narrative_questions`) is never changed from a template.
- **Due rule:** `ReportTemplateLine::isDueIn(CarbonImmutable $month): bool`. Only `confirmed` lines, with `effective_from ≤ month ≤ effective_to`.
  - `library_default` uses the library `frequency`:
    - `monthly` / `per-event` → every month;
    - `quarterly` → the 3rd, 6th, 9th… month counted from the proposal start;
    - `semi-annual` / `annual` → the last month of each period from the proposal start;
    - `follow-up` → month (start + `follow_up_months`);
    - `per-season` / `baseline-endline` / `one-time` → never automatically (MEAL must choose `selected_months` or `once_after_months`).
  - `monthly` → every month; `selected_months` → listed calendar months; `once_after_months` → month (`effective_from` + N).
- **Pre-fill** (listener `PrefillReportTemplates`, queued, idempotent): on `ProposalStatusChanged` with `to = approved`. For each place of the proposal (`PlaceBridge::placesOfProposal`): a `report_templates` row at that place linked to the proposal (pending, questions copied) unless the proposal is already linked to a template there; a **pending** line per library-linked `plan_indicator` of that proposal and project (`effective_from`/`effective_to` = the months of the proposal's actual dates). Targets without a library link are listed as "Link it to the library first". MEAL gets a "Confirm template" notification (`report_template.pending`).
- **Confirm** (`ConfirmReportTemplate::handle(ReportTemplate $template, list<int> $lineIds): ReportTemplate`): ≥ 1 line must end up `confirmed`. If the project is `approved`, call `ActivateProject($project, 'template_confirmation')` (T08) **in the same transaction**. If it's `active`, the newly confirmed lines notify the manager: "New indicators for proposal X, from month A to month B" (`report_template.lines_added`). Nothing is retroactive; no report is reopened (D-41).
- After confirmation, changes apply only to monthly reports still in `draft` (T17 Refresh Lines). `RetireReportTemplateLine` sets `effective_to` = the current month, so the line stops from next month.
- **Org-wide indicators (D-44):** `HC.*` library entries; a yearly target per center as an `annual_plan` `plan_indicators` row. Command `annual-plans:ensure-2026 {--dry-run}` creates the 2026 annual plan per program if missing (2026 only).
- **Launch backfill (D-45):** `report-templates:prefill-active {--dry-run}` creates pending templates for projects already `active`. They stay active; their lines appear after confirmation. It lists (ids only) the `fixed`-basis lines missing `fixed_denominator`; filling them is a human step (PR Manager, T06 post-approval edit), checked by T27 before go.
- **Who:** `report-templates.manage` AND (`MealCoverage::responsibleForPlace($template->place())` = the user's employee **or** the MEAL Manager slot holder). The MEAL queue = pending templates/lines at places returned by `scopeCoveredBy()` (all for the MEAL Manager).
- **Donor attribution (D-46):** `programs.donor_attribution` varchar(20) default `shared` (`DonorAttribution`: `shared`, `split`). Column and program-page setting by T13; read by T17, T18, T19.

- **Placement choice (Y-04, D-77).** `report_template_placements`: `proposal_id` FK cascade, `city_id`, `program_id`, `center_id` null, `city_wide` boolean default false (the place, D-88), `status` (`TemplatePlacementStatus`: `pending`, `joined`, `created`), `report_template_id` FK null nullOnDelete (set when decided), `decided_at`, `decided_by` FK users null nullOnDelete, timestamps. Unique (`proposal_id`, `city_id`, `program_id`, `center_id`, `city_wide`). A place whose center is not set gets no placement choice: its template is created as before (D-82).
- **Pre-fill** now branches per place of the approved proposal: no confirmed or pending template of **another** proposal there → create the template as before and write the placement as `created`; otherwise write a `pending` placement, create nothing, and notify MEAL (`report_template.placement_pending`).
- `JoinReportTemplate::handle(Proposal $proposal, ReportTemplate $template, list<int> $planIndicatorIds, string $expectedStatus = 'pending'): ReportTemplate`: the template must sit at one of the proposal's places and the placement must be `pending`. Links the proposal (`proposal_report_template`), adds one **pending** line per chosen target (targets of this proposal at this place, library-linked, not already a line), marks the placement `joined`. Targets left out get no line and are listed on the template as "not reported".
- `CreateReportTemplateForProposal::handle(Proposal $proposal, ReportPlace $place, string $expectedStatus = 'pending'): ReportTemplate`: creates the proposal's own template at the place exactly as the pre-fill does, marks the placement `created`.
- Both run in one transaction under the placement lock, authorize `report-templates.manage` (the §12.4 "Who" rule), and are refused on a place whose center is not set (D-82).
- A joined proposal's lines follow the usual path: `pending` until `ConfirmReportTemplate`; once confirmed they reach **draft** reports only through `RefreshMonthlyReportLines` (D-41). The template lives while any linked proposal is active; leaving a template is not offered (D-86).
- **Overlapping lines (D-86).** An indicator required by more than one of the template's proposals is **one shared line**: one `monthly_report_lines` row per library indicator per month, with one share per covering target (§13.1 item 1). Joining never duplicates it: the new proposal's target gets its own `report_template_lines` row (unique per target, carrying its own window and fixed denominator) and becomes a **share** of the existing report line. When a proposal ends, only what that proposal alone required stops: its targets' template lines end at its last month (`effective_to`, set at pre-fill or join, or by `RetireReportTemplateLine`, month-exact), so from the month after the end its shares are no longer built; a report line that still has a share of an active proposal stays. A line required by the ended proposal alone is not built from the month after it ends.

### §12.5 Sectors (T34)
- `sectors`: `name_ar`, `name_en` null, `is_active`, `sort_order`, timestamps. Seeded: Health · Food security & livelihoods · WASH · Shelter & NFIs · Protection · Education (D-47).
- `project_sector` (pivot): `project_id` FK cascade, `sector_id` FK restrict; unique pair. Not a column on `projects`.
- `SyncProjectSectors::handle(Project $project, list<int> $sectorIds, bool $requireOne = false): void` (logged as `project.sectors_changed`). The project form (T05 `SaveProject`) calls it; ≥ 1 active sector is required for **new** projects. Component `<x-projects.sector-picker>` (T34) is used by the T05 form.
- A used sector is deactivated, never deleted; it stays visible with an "Inactive" badge. Managed by `sectors.manage`.

## §13 Monthly report (T17; contract frozen at CP3)
**Tables**
- `monthly_reports` (Y-03, D-76): `slug`, `report_template_id` FK `report_templates` restrict, `period` date (first day of the month), `status` (`MonthlyReportStatus`: `draft`, `submitted`, `validated`), `prepared_by`, `submitted_at`, `submitted_by`, `validated_at`, `validated_by`, `returned_at`, `returned_by`, `return_reason` text null, `seen_by_meal_at`, timestamps. User FKs `restrictOnDelete`. Unique (`report_template_id`, `period`); index `status`. `project_id` is kept, nullable, no longer part of a unique index, filled only for reports that existed before Y-03 and by the legacy mirror below; nothing written in or after Y-03 reads it (D-74).
- `monthly_report_reopen_requests`: `monthly_report_id` FK restrict, `requested_by`, `reason`, `status` (`ReopenRequestStatus`: `pending`, `granted`, `denied`), `decided_by`, `decided_at`, `decision_note`, timestamps. Never deleted.
- `monthly_report_lines` (one per **library indicator** due this month): `monthly_report_id` FK cascade, `library_indicator_id` FK restrict, `entered_by` (`IndicatorCollector`), `value` decimal(14,2) null, `numerator`, `denominator` decimal(12,2) null, `fixed_denominator` unsigned int null, `not_measured` bool default false, `note` text null, `filled_by` FK users null, timestamps. Unique (`monthly_report_id`, `library_indicator_id`).
- `monthly_report_line_shares` (one per covering **target** = `plan_indicator`, in **both** attribution modes ⚑): `monthly_report_line_id` FK cascade, `plan_indicator_id` FK restrict, `value`, `numerator`, `denominator`, `fixed_denominator`, `not_measured`. Unique pair. `shared` mode: the manager enters the line once; every share carries the full line figures (read-only). `split` mode: the manager enters each share; line = Σ shares. `plan_indicator_id` is never accepted from the request.
- `monthly_report_line_breakdowns` (D-50): `monthly_report_line_id` FK cascade, `monthly_report_line_share_id` FK null cascade, `gender` (`Gender`: `male`, `female`), `value`, `numerator`, `denominator`, `disabled_value`, `disabled_numerator`, `disabled_denominator`. Unique (line, share, gender). Share = Σ its breakdown; line = Σ shares (or Σ breakdown in `shared`). All totals are computed server-side.
- `monthly_report_answers`: `monthly_report_id` FK cascade, `report_template_question_id` null nullOnDelete, `question_text` (the text at answer time), `answer` text null.
- `monthly_report_files` (typed evidence, D-42): `monthly_report_id` FK cascade, `monthly_report_line_id` null cascade, `monthly_report_activity_entry_id` null cascade, `evidence_type_id` FK restrict, the stored-file columns (as `report_files`, through `StoredFiles`, incl. `encryption_key_id`, §16), `uploaded_by`. Files are removed from disk after commit on delete.
- `monthly_report_activity_entries` (D-51): `monthly_report_id` FK cascade, `result_id` FK restrict (an activity), `status` (`ActivityStatus`: `not_started`, `started`, `ongoing`, `suspended`, `cancelled`, `completed`, `conducted`) null, `reason`, `started_on`, `completed_on`, `executed_on`, `note`. Unique (report, result).
- `project_activity_statuses`: `project_id` FK cascade, `result_id` FK cascade, `status`, `started_on`, `completed_on`, `monthly_report_id` (last confirming report). Unique (project, result). Written **only** on Validate; restored from the activity log on a granted reopen.
- **Mirrors in `reports`** (so `achievedValue()` is unchanged): one `reports` row per share, with `reports.monthly_report_id` FK cascade, `reports.monthly_report_line_share_id` FK unique cascade, `plan_indicator_id` = the share's, and value/numerator/denominator = the share's. Status mirrors the report: draft → `draft`, submitted → `reviewed`, validated → `approved`. New columns `reports.numerator`, `reports.denominator` decimal(12,2) null; `reports.value` becomes nullable. Mirrors are never edited from the generic Reports pages.

**Rules**
- **Lines of month M** = the **template's** `confirmed` lines with `isDueIn(M)` and their target's proposal window covering M, grouped by library indicator. `entered_by = meal` lines are read-only for the manager, don't block submit, and block **Validate** until MEAL fills them or marks them not measured (D-48).
- **Entry:**
  - counts: required on submit (0 allowed);
  - percentage with `denominator_basis = fixed`: the manager enters the count only; denominator = `fixed_denominator` (per share in `split` = that proposal's beneficiaries); numerator ≤ denominator; the server computes `value` (×100);
  - `entered` ratios/averages: numerator + denominator, or **Not measured** (`value` NULL, excluded from achievement, not a zero);
  - any `value`/`denominator` the request sends for computed lines is ignored.
- **Activities this month (D-51):** an activity appears if scheduled in M (`activity_schedules`), **or** its last confirmed status is not_started / started / ongoing / suspended (badge "Continuing from {month}" / "Late from {month}"). Required inputs: Not Started → reason (≥ 10); Started → start date; Suspended → reason; Cancelled → reason (final); Completed → completion date + evidence (final); Conducted (events only) → execution date + evidence. A continuous activity can't be Conducted; an event accepts only Not Started / Conducted / Cancelled.
- **Workflow:**

  | Move | Rule |
  |---|---|
  | generate | months generated when the template's first line is confirmed (one draft per month from the first month any of its proposals covers); a proposal joining or a period change adds new months only; idempotent via the unique index |
  | submit | not before the month's last working day; not while the nearest earlier month with lines is unsubmitted (D-08, §13.1 item 2); no partial submit; required evidence present |
  | validate / return | by the responsible officer (`MealCoverage::responsibleFor`), or the MEAL Manager slot holder, or (when that is null) any MEAL role holder; never the preparer or submitter; return needs a reason ≥ 10 |
  | reopen | the preparer side requests (reason ≥ 10); `MonthlyReportPolicy::decideReopen` per D-09a; grant → draft, so the figures leave achievement until re-validated |

- **Pooling:** `PlanIndicator::pooledRatioSql(CarbonImmutable $from, CarbonImmutable $to): Expression` + PHP twin `pooledRatio()`, with an agreement test. Per target in the window:
  - `fixed` basis: numerator = Σ approved monthly counts (latest when `is_cumulative`), denominator = the fixed denominator;
  - `entered` basis: the latest approved line.

  Across targets: Σ numerators ÷ Σ denominators (×100 for percentage). Lines without a denominator are excluded and counted. T18 and T19 use only this ⚑.
- **Commands:** `monthly-reports:prepare {--dry-run}` (daily, `withoutOverlapping()`, `onOneServer()`; creates the lines of the current month on its first working day, idempotent); `monthly-reports:backfill {--from=YYYY-MM} {--dry-run}` (projects already active at deploy, from 2026-10, never before the project start).
- **API for T17B / T18 / T19:** Actions `OpenMonthlyReport`, `SaveMonthlyReportLines`, `SaveMonthlyReportActivities`, `SaveMonthlyReportAnswers`, `SubmitMonthlyReport`, `ValidateMonthlyReport`, `ReturnMonthlyReport`, `RequestMonthlyReportReopen`, `DecideMonthlyReportReopen`, `MarkMonthlyReportSeen`, `RefreshMonthlyReportLines`, `DeleteMonthlyReport`, `SaveMonthlyReportFile`, `DeleteMonthlyReportFile`. Each takes `expectedStatus` where it moves state. `App\Support\MonthlyReportPeriods` (required months, `canSubmit(): ?string` reason, `hasStarted()`, `dueDate()`). Exact signatures are frozen in `handoffs/T17A.md` at CP3.
- **Events** (catalog keys): `monthly_reports.generated` (one grouped notification per activation), `monthly_report.due`, `.overdue`, `.submitted`, `.validated`, `.returned`, `.reopen_requested`, `.reopen_decided`, `.reopen_escalated` (D-09a: no decider → General Manager). A month that hasn't started raises no alert. Importance, oversight and recipients: §15.2.

### §13.1 Details (T17A, T17B; merged at CP3)
1. **Line aggregation.** A `monthly_report_lines` row groups every due template line of one library indicator. `entered_by` = `meal` when any of them is `meal`. Each share's `fixed_denominator` = its template line's. Line `fixed_denominator`: `split` = Σ shares; `shared` = the highest share (as §14 "shared → the highest").
2. **Required months and sequencing.** A required month = a generated month with ≥ 1 line. Submit of M is blocked while the **nearest earlier month with lines** is not `submitted`/`validated` (D-08; a line-less M-1 can't hide an unsubmitted M-2). Deadlines and due/overdue alerts cover started required months only. Line-less months (activities and answers) can still be submitted.
3. **Questions.** `quarter_end` questions appear in calendar quarter-end months (Mar, Jun, Sep, Dec). Answers are optional on submit. Quarterly questions are on the README cut list.
4. **Evidence download (T17B).** Route `monthly-reports.files.download` = `monthly-reports/{monthlyReport}/files/{monthlyReportFile}/download`, `can:reports.view` + policy `view`, rate-limited like `reports.files.download`; the file loads through `$monthlyReport->files()`; streamed through `StoredFiles::stream()` (§16).
5. **Mirror fields.** `reports.planned_date` = last day of the month; `period_start`/`period_end` = month bounds; `user_id` = the user whose write created the mirror (created with the first write to a share's figures; Submit creates any missing); `submit_date` = submit day, NULL after return or grant. `monthly-reports:prepare` creates lines and shares only (no actor).
6. **Policy and queue.** `MonthlyReportPolicy`: `viewAny`, `view`, `open(User, ReportTemplate)`, `update`, `fillMealLines`, `submit`, `validate`, `return`, `requestReopen`, `decideReopen`, `markSeen`, `delete`. `view` = `reports.view` (read-only). A query scope "reports this user may validate" (the places covered by the responsible officer; all for the MEAL Manager slot holder; places with no responsible officer for any MEAL role holder) feeds T17B's **To validate** list and T18.
7. **MEAL lines (D-48a).** The validator fills, or marks Not measured, `entered_by = meal` lines only while the report is `submitted`.
8. **Activity-status restore.** On Validate, the previous `project_activity_statuses` state of each touched row (or "absent") goes into the `monthly_report.validated` activity properties; a granted reopen restores from the latest such entry for that report.
9. **Preparer side.** `reports.create`/`reports.update` AND (the user's employee is the `responsible_employee_id` of a location row of one of the template's proposals at the template's place, OR `SupervisionMap::supervisorsOf()` of such a responsible contains the user's employee). "At the template's place" = a row of the same city and program that names the template's center, or is `city_wide` when the template is (D-88); for a template whose center is **not set** (D-82), every row of its proposals in that city and program counts, so a migrated report always has a preparer. The row's responsible is the project manager and the one who hands in the monthly reports. A joined template whose proposals name different responsibles may be prepared by **either** of them (and by either one's supervisors); no single preparer is picked. **Validator side:** validate / return / decideReopen resolve MEAL coverage from the report template's place.

## §14 Aggregation (T18, T19)
- **Only validated** (`approved` mirror) figures count; pending ones are shown but never summed.
- **Center view:** reads `monthly_report_lines` (Σ shares in `split`). **Donor view:** reads that donor's share mirrors (its share in `split`, the full value in `shared`).
- **Center target:** `split` → Σ the targets; `shared` → the highest.
- **Percentages:** only through `pooledRatioSql()`/`pooledRatio()` (§13); never an average of percentages.
- No money in the matrix. Donor reports (T19) exclude internal (self-funding) donors.
- **W-32 (D-55b) exclusions:** `DonorReportAggregator`, the matrix, targets, indicator coverage, `MealCoverage`, the Projects and Logframe tabs, location rows (W-34a) and stage templates (W-12, unless a template is marked `kind = payroll`: add `proposal_kind` to the template table with default `project`) all skip `kind = payroll`. *W-32 ruling (4 Nov):* W-12 and W-34a are not on `main` yet, so their exclusions land with those tasks.

### §14.1 Figures and views (T18, T19)
- **Counts:** sum of validated values in the window. `is_cumulative`: the latest validated value in the window, never a sum.
- **Ratios** (`percentage`, `average`): only `pooledRatioSql()`/`pooledRatio()` (§13). Lines or shares without a denominator are excluded and counted next to the figure.
- **Center view row** = (**place**, `library_indicator_id`), where the place is the report template's (`city_id`, `program_id`, `center_id`, `city_wide`, Y-02). Two templates at one place are **one** row: their validated lines are summed (counts) or pooled (ratios) like two targets of one row. *(Was (`project_id`, `library_indicator_id`).)* Counts read `monthly_report_lines` of `validated` monthly reports. Ratios pool over the row's targets: `shared` → one representative target (highest target, lowest id on a tie; every share carries the same figures); `split` → all the row's targets.
- **Extraction filter (Y-05, D-79).** `App\Support\Reporting\ReportExtraction::for(ExtractionFilter $filter)` is the one query behind the matrix, its export and the monthly-reports list. `ExtractionFilter` (readonly): `?int $proposalId`, `?int $cityId`, `?int $programId`, `?int $centerId`, `?CarbonImmutable $from`, `?CarbonImmutable $to` (months, inclusive), plus the existing `?int $donorId`, `?int $indicatorId`, `?int $pillarId`, `bool $mine`. Place filters read the template; the proposal filter reads the line shares' targets (never `project_proposal`). An open end means "from the first month" / "to this month". *Y-05 Q1 (Dev 1, 18 Nov):* two more flags, `bool $cityWide = false` (the "Field work" place of the city, D-88) and `bool $centerUnset = false` (templates whose center is not set, D-82); each excludes `$centerId`. *Y-05 slice 2 Q1 (Dev 1, 18 Nov):* the monthly-reports list reads `reports(openEndUnbounded: true)`: there an open `to` has no upper bound, so generated months still to come stay listed; the matrix and its export keep "to this month".
- **Donor view row** = one target (`plan_indicator`): that target's share mirrors (`reports.status = approved`, `monthly_report_id` not null) and share-level breakdowns.
- **Choosing the view:** a donor or proposal filter selects the donor view; otherwise the center view.
- **Disaggregation (D-50):** male, of whom PwD, female, of whom PwD, total, from `monthly_report_line_breakdowns`, validated only. Ratio per category = Σ numerator ÷ Σ denominator. Fixed-denominator indicators: counts only (D-25).
- **Activities (D-51):** an activity's status in a period = its latest `monthly_report_activity_entries` row of a `validated` monthly report on or before the period end.
- **Narratives** = `monthly_report_answers` (question text at answer time + answer) of `validated` monthly reports.
- **Completeness (T19):** per center linked to the proposal and per month of the period that has a `monthly_reports` row: `draft` → "not submitted", `submitted` → "not validated". A center appears from its first generated month; no pivot timestamp.

### §14.2 Donor reports: enums and schema (T19, Team A)
- `App\Enums\ReportingPeriod`: `quarterly`, `semi-annual`, `annual`, `custom`. `App\Enums\ReportAudience`: `donor`, `internal`. Both `HasTranslatedLabel` + `values()`.
- `donor_reports` additions (nullable or defaulted):

| Column | Type |
|---|---|
| `audience` | varchar(20) default `donor` |
| `period_type` | varchar(20) null |
| `period_start`, `period_end` | date null |
| `snapshot` | json null (`schema_version` 1) |
| `version` | unsigned smallint default 1 |
| `responsible_employee_id` | unsigned big int null (HR, no FK) |
| `notes` | text null |

- Index `donor_reports_agreement_period_index` (`donor_proposal_id`, `type`, `period_start`, `period_end`); unique `donor_reports_agreement_period_version_unique` (same + `version`). `due_on` stays NOT NULL (D-32: manual per report); `submitted_on` stays nullable.
- `MediaCollection::ReportSubmission` (`report_submission`); morph key `donor_report`; files under `media/donor-reports/{id}` through `StoredFiles` (§16).

### §14.3 Donor reports: API (T19)
- **Aggregator:** `App\Support\DonorReportAggregator::aggregate(Proposal $proposal, CarbonImmutable $start, CarbonImmutable $end, bool $includeFinance): array`. Read-only, constant query count; the returned shape is the `snapshot` payload. Donor view only (§14.1).
- **Actions** (`app/Actions/DonorReports/`; the existing `FundingAgreements\SaveDonorReport|MarkDonorReportSubmitted|DeleteDonorReport` stay):
  - `ComposeDonorReport::handle(int $proposalId, ReportingPeriod $period, string $startMonth, ?string $endMonth, User $actor): array`
  - `SaveDonorReportSnapshot::handle(Proposal $proposal, array $input, User $actor): DonorReport` (`$input`: `period_type`, `start_month`, `end_month?`, `type`, `audience`, `due_on`, `responsible_employee_id?`, `notes?`, `title?`)
  - `RegenerateDonorReport::handle(DonorReport $report, User $actor): DonorReport` (new row, `version + 1`)
  - `SaveDonorReportFile::handle(DonorReport $report, UploadedFile $file, User $actor): Media`
  - `DeleteDonorReportFile::handle(DonorReport $report, int $mediaId): void`
- **Policy** `DonorReportPolicy` (responsible = `responsible_employee_id` = `$user->employee_id`, both non-null):

| Ability | Rule |
|---|---|
| `view` | `funding-agreements.view` or `reports.aggregate` or responsible |
| `submit`, `uploadFile`, `deleteFile` | responsible or `funding-agreements.update` |
| `downloadFile` | responsible or `funding-agreements.view` |
| `regenerate` | `reports.aggregate` and not submitted |
| `delete` | `funding-agreements.update` and not submitted |

- **Routes:** `donor-reports.compose`, `donor-reports.show`, `donor-reports.print`, `donor-reports.export`, `donor-reports.files.download` (`throttle:files`), `reporting-duties.index`.
- **Events:** `donor_report.saved`, `donor_report.submitted` (after commit, no amounts); `.due`/`.overdue` from `DeadlineCatalog` (§15.2).
- **Default responsible:** narrative → `Slots::holderOf('fundraising_manager')`, financial → `Slots::holderOf('finance_manager')`. No new slot.

## §15 Notification event catalog keys (T21)
`App\Notifications\EventCatalog` is the one list of notification events. **Every movement on a project, a proposal or a report must be in it**; `EventCatalogCoverageTest` fails otherwise. Keys are stable strings, frozen at CP0; renaming one needs both teams and a change here. A task registers a key by adding one entry to its module group in `EventCatalog::all()`, adding `events.{key}.title|body` to `lang/{en,ar}/notifications.php`, and running the coverage test. Slice 1 of T21 (Dev 3, by day 5) lands every key below, so other tasks only add lang text and emit the event.

### §15.1 Types and API
```php
enum App\Enums\EventImportance: string { case Immediate = 'immediate'; case Digest = 'digest'; }
enum App\Enums\OversightMode: string { case All = 'all'; case ManagementOnly = 'management_only'; case None = 'none'; }  // HasTranslatedLabel

final class App\Notifications\EventCatalog
{
    /** @return array<string, array{module: string, importance: EventImportance, direct: string, oversight: OversightMode,
     *                              critical: bool, activity: string|null, event: class-string|null}> key => definition */
    public static function all(): array;
    /** @return list<string> */ public static function keys(): array;
    public static function has(string $key): bool;
    /** Definition merged with the admin overrides (notification_settings key `event_overrides`); null for an unknown key.
     *  @return array{module: string, importance: EventImportance, direct: string, oversight: OversightMode, critical: bool,
     *                activity: string|null, event: class-string|null, enabled: bool, oversight_roles: list<string>}|null */
    public function effective(string $key): ?array;
}

final class App\Notifications\Recipients\OversightRecipients
{
    /** MEAL + management per the key's oversight mode; [] for `none`, for a disabled event, and never Super Admin. @return list<int> user ids */
    public function userIdsFor(string $eventKey, ?int $projectId = null): array;
    /** Role `MEAL` holders + (when $projectId is given) the user of MealCoverage::responsibleFor($project). @return list<int> */
    public function meal(?int $projectId = null): array;
    /** Role `General Manager` + roles added in settings. @return list<int> */
    public function management(): array;
    /** Members of an HR department (EmployeeProfile::employeeIdsInDepartment) mapped to users. @return list<int> */
    public function departmentUserIds(int $departmentId): array;
}
```
- `direct` = where the direct recipients come from: `module_map` (the module's `app/Notifications/Recipients/*` map), `listener` (a domain-event listener passes the ids), `scanner` (a scanner passes the ids).
- `activity` = the activity event name for activity-driven keys; `event` = the domain event class for listener-driven keys (§5).
- Settings (`NotificationSettings` key `event_overrides`): `array<string, array{enabled?: bool, importance?: string, oversight_roles?: list<string>}>`; default `[]` in `config/notifications.php` `defaults`. `Super Admin` is rejected as an oversight role (D-20a).
- Rules: oversight is added to every enabled key per its mode; digest keys reach oversight only in the daily oversight summary (`OversightDigestScanner`); direct recipients always get them immediately. Oversight never bypasses the module's view permission; oversight users are non-direct (mutes apply); direct recipients of `critical` keys are never muted. One notification per person (direct wins). No amounts in any payload, in-app text or email.

### §15.2 Keys
`I` = immediate, `D` = digest. Oversight: `all` = MEAL + General Management, `mgmt` = General Management only, `none`. `critical` = bypasses mutes for direct recipients. Module names are existing `config/notifications.php` modules; ⚑ confirm the three new ones (`monthly-reports`, `donor-reports`, `report-templates`), each added by its owning task with `module_icons` and banners.

| Key | Module | Imp. | Oversight | Critical | Direct recipients | Owner |
|---|---|---|---|---|---|---|
| `project.created` | projects | D | all | no | program manager, MEAL Manager slot | T05 |
| `project.owner_acknowledged` | projects | I | all | no | PR Manager, proposal editors | T08 |
| `project.owner_declined` | projects | I | all | no | PR Manager, program manager | T08 |
| `project.approved` | projects | I | all | yes | project owner, program manager | T08 |
| `project.activated` (`ProjectActivated`) | projects | I | all | yes | owner, program manager, MEAL officer | T08/T33 |
| `project.period_changed` (`ProjectPeriodChanged`) | projects | I | all | no | owner, program manager | T08 |
| `project.started` | projects | I | all | no | owner, program manager | T21 |
| `project.ended` | projects | I | all | no | owner, program manager | T21 |
| `project.closed` | projects | I | all | no | owner, program manager | T05 |
| `project.archived` | projects | D | all | no | owner, program manager | existing |
| `project.sectors_changed` | projects | D | all | no | none | T34 |
| `proposal.created` | proposals | D | all | no | program managers of linked projects | T06/T10 |
| `proposal.status_changed` (`ProposalStatusChanged`) | proposals | I | all | yes ⚑ | owner, editors, reviewers (rejection included) | T04 |
| `proposal.review_requested` | proposals | I | all | no | the requested reviewer | T07 |
| `proposal.reviewer_added` | proposals | I | all | no | the added reviewer | T07 |
| `proposal.reviewer_removed` | proposals | D | all | no | the removed reviewer | T07 |
| `proposal.reviewer_seen` | proposals | D | all | no | proposal owner | T07 |
| `proposal.need_assessment_added` | proposals | D | all | no | owner, MEAL Manager slot | T07 |
| `proposal.approved` | proposals | I | all | yes | Finance, owners, program managers, MEAL Manager slot, PR Manager, reviewers | T08 |
| `proposal.approved_without_agreement` (D-26) | funding-agreements | I | all | no | PR, Finance, CEO slot | T08 |
| `proposal.extended` (W-08, D-60) | proposals | I | all | no | linked project owners, Financial watchers (names and dates only) | W-08 |
| `proposal.change_requested` (Y-09, D-89a) | proposals | I | all | as `proposal.approved` | `finance_manager` and `meal_manager` slot holders ("review needed"); active holders of `proposals.approve` except the requester ("waiting for its reviews") | Y-09 |
| `proposal.change_reviewed` (Y-09) | proposals | I | all | no | once both reviews are in: active holders of `proposals.approve` ("ready for your decision", naming any remarks) | Y-09 |
| `proposal.change_approved` / `proposal.change_rejected` (Y-09) | proposals | I | all | no | the requester and the two reviewers; on approve also the responsible MEAL officer of each affected place (else the MEAL Manager slot) and the row's responsible when a location row changed | Y-09 |
| `proposal.on_hold_reminder` | proposals | I | all | no | proposal owner, editors | T21 |
| `proposal.final_report_accepted` (D-02c) | proposals | I | all | no | owner, program manager | T04 |
| `proposal.beneficiaries_changed` (D-25) | proposals | I | all | no | MEAL officer, MEAL Manager slot | T06 |
| `proposal.targets_saved` | proposals | D | all | no | none | T13 |
| `proposal.project_linked` / `proposal.project_unlinked` | proposals | D | all | no | none | T06 |
| `proposal.deleted` | proposals | D | all | no | none | T12 |
| `proposal.risk_attached` / `proposal.risk_detached` (names only) | proposals | D | all | no | none | W-09 |
| `agreement.recorded` (`FundingAgreementRecorded`) | funding-agreements | I | all | no | Finance, PR Manager (no amounts for others) | T09 |
| `donor.requested` | donors | I | none | no | `General Manager` holders of `donors.approve` | W-04 |
| `donor.approved` | donors | I | none | no | `requested_by` | W-04 |
| `donor.declined` | donors | I | none | no | `requested_by` | W-04 |
| `transfer.generated` | transfers | I | mgmt | no | Finance | T09 |
| `transfer.received` | transfers | I | mgmt | no | PR Manager, Finance | existing |
| `transfer.late` | transfers | I | mgmt | no | Finance, PR Manager | existing |
| `monthly_reports.generated` (grouped, one per activation) | monthly-reports | I | all | no | project owner | T17 |
| `monthly_report.due` / `.overdue` | monthly-reports | I | all | no | owner (preparer) | T17 |
| `monthly_report.submitted` | monthly-reports | I | all | no | responsible MEAL officer, MEAL Manager slot | T17 |
| `monthly_report.validated` | monthly-reports | I | all | no | owner, program manager | T17 |
| `monthly_report.returned` | monthly-reports | I | all | yes | owner (preparer) | T17 |
| `monthly_report.reopen_requested` | monthly-reports | I | all | no | the D-09a decider | T17 |
| `monthly_report.reopen_decided` | monthly-reports | I | all | yes | the requester | T17 |
| `monthly_report.reopen_escalated` (D-09a, no active decider) | monthly-reports | I | all | yes | General Manager | T17 |
| `report_template.placement_pending` | report-templates | I | all | no | responsible MEAL officer of the place, else MEAL Manager slot | Y-04 |
| `report_template.pending` | report-templates | I | all | no | responsible MEAL officer, MEAL Manager slot | T33 |
| `report_template.confirmed` | report-templates | I | all | no | owner, program manager | T33 |
| `report_template.lines_added` | report-templates | I | all | no | owner | T33 |
| `donor_report.saved` / `.submitted` | donor-reports | D | all | no | responsible employee | T19 |
| `donor_report.due` / `.overdue` | donor-reports | I | all | no | responsible employee (exempt from `funding-agreements.view`, routed to My Reporting Duties) | T19 |
| `task.assigned` / `task.completed` (W-13, D-64: reused for `TaskKind::Report`; no new key) | tasks | existing | existing | existing | existing | W-13 |
| `department_task.created` | tasks | I | none | no | department head and members | T31 |
| `department_task.due` | tasks | I | none | no | assignee, department | T31 |
| `department_task.overdue` | tasks | I | all | no | assignee, department, requester | T31 |
| `department_task.delivered` | tasks | I | none | no | requester | T31 |
| `department_task.returned` | tasks | I | none | yes | assignee, department | T31 |
| `library_indicator.created` / `.updated` / `.deactivated` / `.reactivated` | indicator-library | D | all | no | none | T11 |
| `meal_coverage.assigned` / `.responsible_changed` | cities | D | all | no | the new and previous officers | T15 |
| `meal_coverage.project_assigned` (D-12a) | cities | D | all | no | the new officer, previous officer, governorate officer | T15 |
| `program.logframe_missing` (T14, post-window; key reserved) | results | D | all | no | MEAL Manager slot, MEAL role | T14 |
| `annual_plan.missing` (D-44a, from 2027; key reserved) | annual-plans | I | all | no | program manager, MEAL | later |
| `supervision.added` / `supervision.removed` | access-management | D | all | no | module map (`UserRecipients`) | T23 |
| `proposal.locations_changed` (`ProposalLocationsChanged`, W-34a) | proposals | D | all | no | none (activity only; counts, no names) | W-34a |
| `program_request.opened` (W-34a) | proposals | I | all | no | PR Manager slot + HR mailbox slot (new slot in `Slots`): "Proposal X needs program «name» in HR" | W-34a |
| `program_request.linked` (W-34a) | proposals | I | all | no | proposal owner | W-34a |

Rows with `/` are separate keys (two or more entries). Post-window keys are added by their tasks when built: `annual_plan.*` (T24) and `proposal.budget_lines_updated` (T25), §18. `project.created`, `project.closed`, `project.archived`, `transfer.received` and `transfer.late` map existing activity events; their owning tasks only verify the names. If a sibling prompt names a key not in this table, the owning task adds it to the same module group and tells Dev 3; do not rename.
- **Alert (not a catalog key):** `notifications.alerts.escalation.supervisor` (T23), sent by `EscalationScanner` at the program-manager stage next to `escalation.manager`; ledger stage `supervisor:{due}`; recipients = active supervisors (`SupervisionMap`, §10) of the item's named people.

## §16 Files and encryption (T22)
All uploaded files are stored encrypted at rest. `App\Support\StoredFiles` is the only way to write or read an upload; tasks that add a file table (T07, T17, T19, T31) go through it.

**Algorithm and key.** `Illuminate\Encryption\Encrypter`, cipher `aes-256-gcm`, raw bytes (`encrypt($bytes, false)` / `decrypt($payload, false)`), 32-byte key accepted with or without the `base64:` prefix. The file key is independent from `APP_KEY`; the `Crypt` facade is never used for files. The production key is backed up off the server (D-19, CP6 gate). Real keys are generated, installed, rotated and used by humans only; Claude and tests never see them.

**Environment and config** (`config/files.php`, `.env.example` lists all three empty):

| Env | Config | Meaning |
|---|---|---|
| `FILE_ENCRYPTION_KEY` | `files.encryption.key` | current key |
| `FILE_ENCRYPTION_KEY_ID` | `files.encryption.key_id` | id of the current key; `k1` when empty; `[A-Za-z0-9._-]`, ≤ 20 chars |
| `FILE_ENCRYPTION_PREVIOUS_KEYS` | `files.encryption.previous_keys` | `id:key,id:key` (split at the first `:`); decrypt-only; an id never equals the current id |
| (fixed) | `files.encryption.cipher` | `aes-256-gcm` |

**Schema.** `encryption_key_id` varchar(20) nullable on `media`, `report_files`, `task_attachments` and every later file table (T17 `monthly_report_files` included). NULL = legacy plaintext. `size` keeps the original byte count.

**API.**
```php
final class App\Support\FileKeyring {
    /** @return array{id: string, encrypter: Encrypter} @throws FileStorageNotConfigured */ public static function current(): array;
    /** @throws UnreadableStoredFile when the id is unknown */ public static function for(string $keyId): Encrypter;
    public static function forget(): void;   // tests swap keys by setting config then calling this
}
final class App\Support\StoredFiles {
    // existing save() keeps its signature; it now encrypts the upload, stores the new path {directory}/{Str::random(40)}.{type} and sets encryption_key_id
    public static function putEncrypted(string $disk, string $path, string $contents): string;   // returns the key id
    /** @throws UnreadableStoredFile */ public static function contents(string $disk, string $path, ?string $keyId): string;
    /** The caller has already authorized. Decrypts first, then builds the response. @throws UnreadableStoredFile */
    public static function stream(string $disk, string $path, ?string $keyId, string $downloadName, FileType $type, array $headers = [], string $disposition = 'attachment'): StreamedResponse;
}
```
- `FileType::mimeType(): string` sets `Content-Type`; never the detected or client MIME.
- `FileStorageNotConfigured` extends `ValidationException` on field `newFile` (translated `errors.file_storage_not_configured`); `UnreadableStoredFile` renders a friendly page (`errors.file_unreadable`) and logs table, row id and key id only.
- Validation (`SecureUpload`, size, types) runs before `StoredFiles`, on the original upload. Download/preview URLs, routes, policies and headers are unchanged.

**Commands** (all `Isolatable`; real runs use `ConfirmableTrait` and print "run with --dry-run first"; counts and ids only):

| Command | Purpose |
|---|---|
| `files:encrypt-existing {--dry-run} {--chunk=100}` | encrypts legacy plaintext rows; resumable (rows with a key id are skipped); verified copy on a new sibling path, then one locked transaction switches `path` + `encryption_key_id`; **on the README cut list** (may run after go-live) |
| `security:reencrypt --files {--dry-run}` | re-keys every file whose `encryption_key_id` is not the current id; files only; without `--files` the command is unchanged (database columns) |
| `files:decrypt {table : media\|report_files\|task_attachments} {--id=} {--to=}` (each later file table, e.g. `monthly_report_files`, is added to the `table` choices and to `files:encrypt-existing` / `security:reencrypt --files` by its owning task) | emergency extraction of one file to an explicit destination outside the upload folders and `public/`; refuses to overwrite; prints only the destination |

**Rotation (human-run):** put the new key in `FILE_ENCRYPTION_KEY` with a new id, move the old one to `FILE_ENCRYPTION_PREVIOUS_KEYS`, run `security:reencrypt --files --dry-run` then for real, verify zero files with an old id, then remove the old key. Backups taken before `files:encrypt-existing` still hold plaintext.

## §18 Out-of-window (T24, T25)
Built after the October launch. Nothing in §1–§16 depends on them.

### §18.1 Annual plan workflow (T24)
- `annual_plans` additions: `status` varchar(20) NOT NULL default `draft` (`App\Enums\AnnualPlanStatus`: `draft`, `approved`), `approved_by` FK `users` null nullOnDelete, `approved_at` timestamp null, `prepared_by` FK `users` null nullOnDelete (set by `SaveAnnualPlanActivities`). Backfill: every pre-existing row → `approved`, approver and date NULL.
- `annual_plan_activities`: `annual_plan_id` FK cascade, `result_id` FK `results` restrictOnDelete (a program-framework activity of the plan's program, §11.2), `planned_months` json (sorted distinct ints 1–12), `notes` text null, timestamps; unique (`annual_plan_id`, `result_id`).
- Actions (`app/Actions/AnnualPlans`, `Gate::authorize` inside, `handle()`): `SaveAnnualPlanActivities::handle(AnnualPlan $plan, array $activities, array $expectedActivityIds): void`; `ApproveAnnualPlan::handle(AnnualPlan $plan): void` (approver ≠ `prepared_by`); `ReturnAnnualPlanToDraft::handle(AnnualPlan $plan, string $reason): void`.
- `AnnualPlanPolicy::approve()` = `annual-plans.update` and not the preparer. No new permission.
- Catalog keys (§15): `annual_plan.approved`, `annual_plan.returned_to_draft`, `annual_plan.activities_updated`.
- Interplay with T33 (§12.4): plans made by `annual-plans:ensure-2026` before this migration are backfilled `approved`; if the command runs after T24, it creates `approved` plans.

### §18.2 Budget lines (T25)
- `budget_lines`: `proposal_id` FK `proposals` cascadeOnDelete, `category` varchar(50) (`App\Enums\BudgetCategory`: `hr`, `operational`, `fixed`, `running`, `activity`), `description` varchar(255), `amount` decimal(14,2), timestamps; index (`proposal_id`, `category`). No currency column: currency = `proposals.requested_currency`.
- `ProposalStatus::allowsBudgetLineEdits(): bool`: true for `concept note`, `concept note review`, `concept note submitted`, `proposal draft`, `proposal review`; false otherwise (incl. the deprecated `final reports`).
- `SaveProposalBudgetLines::handle(Proposal $proposal, array $lines, array $expectedLineIds): void` (`proposals.update` + the shared money predicate, §8).
- `requested_currency` cannot change while budget lines exist: one shared guard used by `SaveProposal` and every other writer.
- Catalog key (§15): `proposal.budget_lines_updated` (no amounts; never notified to holders without a money permission).
- Phase 1 touch points (the enum method, the currency guard in Phase 1 writers) are reviewed by Dev 1.

## §17 Open items for Dev 1 at CP1 (⚑)
1. `ActivateProject` also moves the project's still-`approved` proposals → `active` (§2, §6). Confirm.
2. `meal-coverage.manage` / `report-templates.manage` excluded from General Manager (§8). Confirm with management.
3. `meeting_at` stored on the `proposal_status_notes` row that opened the review stage (§7, T07).
4. `ProposalStatus::FinalReports` kept as `@deprecated` rather than deleted (§1).
5. Monthly report shares exist in both attribution modes (§13); in `shared` they copy the line's figures.
6. Pooling for the `fixed` basis = Σ monthly counts ÷ the fixed denominator; `entered` = latest approved (§13). **MEAL Manager to confirm.**
7. `proposal.status_changed` is `critical`: its direct recipients are never muted (§15.2).
8. Three new notification modules `monthly-reports`, `donor-reports`, `report-templates` (§15.2), each added by its owning task (T17A, T19, T33) with `module_icons` and banners.
9. `projects.working_days` (§7) ships without an edit UI in October; Dev 1 sets it on request until a project-form field is assigned (T05 follow-up).
10. Whether T17A (submit day, due dates) and T19 (reminders) switch to `WorkingCalendar::forProject()` (§9) once T21 slice 2 merges, or stay on the global calendar in October (D-22 names T17 and T19).
11. `programs.strategic_pillar_id` (T32) is read by T18 but is not in this file: add it to §12 or keep it T32-internal.
12. T25 (§18.2): the `concept note submitted` edit window, and the Phase 1 enum method + currency guard.

Rationale for every ruling: `CHANGELOG.md`.
