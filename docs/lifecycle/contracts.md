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
| OnHold | `on hold` | planning | stage of the pre-hold status | ✔ new |
| Approved | `approved` | implementation | grant | ✔ |
| Active | `active` | monitoring | grant | ✔ |
| Completed | `completed` | archived | grant | ✔ terminal |
| Rejected | `rejected` | archived | stage of the pre-rejection status | ✔ terminal |
| FinalReports | `final reports` | closure | grant | ✘ `@deprecated` (history rendering only; no transition in or out; rows migrated to `active` by T04) |

- `ProposalPhase` gains `Ideation = 'ideation'` as its first case. Phase and stage are derived, **never stored**. Every `match` is exhaustive with no `default`.
- `ProposalStatus::live(): list<self>`: every case except `FinalReports`. Pickers, filters and charts iterate `live()`, never `cases()`.
- `isApprovedByDonor()`: approved, active, completed (and the deprecated final reports).
- "Pre-hold / pre-rejection status" = the status of the latest `proposal_status_notes` row before it entered hold or rejection.

## §2 Proposal transitions
`App\Support\ProposalTransitions` holds this table and nothing else.

| From | To | `requirements()` keys | Writer |
|---|---|---|---|
| concept note | concept note review | `linked_project`, `donor`, `planned_dates` | TransitionProposal |
| concept note review | concept note | `reason` | TransitionProposal |
| concept note review | concept note submitted | `effective_date` | TransitionProposal |
| concept note | proposal draft | `reason` (skip reason, D-03) | TransitionProposal |
| concept note submitted | proposal draft | `effective_date` (donor accepted the concept note) | TransitionProposal |
| concept note submitted | on hold | `reason`, `remind_on` | TransitionProposal |
| concept note submitted | rejected | `reason_min_10`, `effective_date` | TransitionProposal |
| proposal draft | proposal review | `budget`, `planned_dates`, `target_beneficiaries` | TransitionProposal |
| proposal review | proposal draft | `reason` | TransitionProposal |
| proposal review | submitted | `effective_date`, `target_beneficiaries` | TransitionProposal |
| submitted | proposal draft | `reason` (donor asked to resubmit) | TransitionProposal |
| submitted | on hold | `reason`, `remind_on` | TransitionProposal |
| submitted | rejected | `reason_min_10`, `effective_date` | TransitionProposal |
| on hold (pre-hold = concept note submitted) | proposal draft · rejected | the target row's keys | TransitionProposal |
| on hold (pre-hold = submitted) | submitted · proposal draft · rejected | the target row's keys | TransitionProposal |
| submitted · on hold (pre-hold = submitted) | approved | — | **ApproveProposal only** (T08) |
| approved | active | — | **ActivateProject only** (T08; called by T33) |
| active | completed | `final_report`, `effective_date` | TransitionProposal |
| rejected · completed | any live status except approved | `reason` | **CorrectProposalStatus only** (`is_correction = true`) |

Key meanings:
- `linked_project`: at least 1 linked project.
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
- Entering `on hold` sets `status_remind_on`; leaving clears it.
- Messages never reveal an amount (the budget gate message names no figure).

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

- **One open project per (`center_id`, `program_id`)** (D-30a): checked server-side, inside the transaction, under a lock, by `Project::openProjectFor(int $centerId, int $programId, ?int $exceptId = null): ?Project` (T30). Create, restore, reopen-as-draft and link all reuse it. No DB unique index.
- A draft holds dates **only** while it is linked to a donor-approved proposal and waits for its owner's acknowledgement (badge "Awaiting owner acknowledgement"). The project form never gives a draft dates. Approved and active projects always hold dates. Invariant test: `ProjectDraftLifecycleTest` (T05, extended by T08).
- Owner-rejected projects (`isOwnerRejected()`, ASG-03) are skipped by approval and stay locked.
- Defensive data migration (T05): legacy `submitted` → `draft` (no dates) or `active` (dated); legacy `approved` → `active` when dated, else `draft`. Print counts before and after.

## §4 Status writers (guard tests enforce this)
- `proposals.status` is written only in: `App\Actions\Proposals\TransitionProposal`, `CorrectProposalStatus`, `ApproveProposal`, `App\Actions\Projects\ActivateProject`, and the T04 data migration. Enforced by `tests/Feature/ProposalStatusWriteGuardTest.php` (source scan). `status` is **not** in `Proposal`'s `#[Fillable]`.
- `projects.status` is written only in: `SaveProject` (create = draft), `ApproveProjectFromProposal`, `ActivateProject`, `CloseProject`, `ReopenProjectAsDraft`, `ArchiveProject`, `RestoreProject`, and the T05 data migration. Enforced by `tests/Feature/ProjectStatusWriteGuardTest.php` (T05; T08 extends its allowlist).

## §5 Domain events
Plain Laravel event classes in `app/Events/`, `final`, readonly promoted properties, implementing `ShouldDispatchAfterCommit`. They are dispatched **only after commit**; a refused or rolled-back action dispatches nothing. Listeners are queued, retried, idempotent, and never call back into Phase 1 actions (except the `§6` entry points marked "Team B may call").

| Event | Constructor | Dispatched by | Consumers |
|---|---|---|---|
| `ProposalStatusChanged` | `int $proposalId, ProposalStatus $from, ProposalStatus $to, ?int $actorId, bool $isCorrection = false` | every proposal status writer (§4), once per move | T21 (`proposal.status_changed`), T31 (rebase on `to = approved`), T33 (template pre-fill on `to = approved`) |
| `ProjectActivated` | `int $projectId, ?int $proposalId, CarbonImmutable $startDate, CarbonImmutable $endDate, string $via` | `ActivateProject`; `$via` ∈ {`template_confirmation`} | T17 (generate monthly reports), T21 |
| `ProjectPeriodChanged` | `int $projectId, CarbonImmutable $oldStart, CarbonImmutable $oldEnd, CarbonImmutable $newStart, CarbonImmutable $newEnd` | `ApproveProjectFromProposal` when an **active** project's dates change | T17 (add months), T21 |
| `FundingAgreementRecorded` | `int $fundingAgreementId, int $proposalId, ?int $actorId` (`$fundingAgreementId` = `donor_proposal.id`) | `SaveFundingAgreement` on create | T09 (`GenerateExpectedTransfers`), T21 |

Event classes land on `main` as **stubs by CP1** (day 3) and are dispatched by CP2 (day 6), so Team B can write listeners from day 4.

## §6 Team A actions (signatures frozen at CP1)
All are `final`, `declare(strict_types=1)`, with `Gate::authorize` inside and `handle()` as the entry point. A stub that throws `LogicException('Implemented in TNN')` lands by CP1.

```php
// T04, app/Actions/Proposals
TransitionProposal::handle(Proposal $proposal, ProposalStatus $to, ProposalStatus $expectedFrom, array $input): TransitionResult
    // @param array{reason?: string, effective_date?: string, remind_on?: string} $input
CorrectProposalStatus::handle(Proposal $proposal, ProposalStatus $to, ProposalStatus $expectedFrom, string $reason): TransitionResult
RecordFinalReportAcceptance::handle(Proposal $proposal, CarbonImmutable $acceptedOn): Proposal   // acceptor = auth user
// App\Support\TransitionResult (readonly): ProposalStatusNote $note, list<string> $warnings  (warning keys: no_agreement, all_projects_archived, reviewers_unseen)

// T06, app/Actions/Proposals and Projects
SaveProposal::handle(?Proposal $existing, array $data, User $actor): Proposal
LinkProposalProject::handle(Proposal $proposal, int $projectId, ?int $targetBeneficiaries = null): void
UnlinkProposalProject::handle(Proposal $proposal, int $projectId): void
ReopenProjectAsDraft::handle(Project $project): void          // called inside SaveProposal/LinkProposalProject transactions

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
ApproveProjectFromProposal::handle(Project $project, string $via): bool   // $via ∈ {proposal_approval, owner_acknowledgement}; idempotent
ActivateProject::handle(Project $project, string $via): void              // Team B (T33) may call. Requires project approved + dated;
                                                                          // moves its still-`approved` donor-approved proposals → active (one note each)
// T09, app/Actions/FundingAgreements
SaveFundingAgreement (existing; donor written from the proposal)
SaveAgreementInstallments::handle(DonorProposal $agreement, array $installments): void  // @param list<array{sequence:int, amount:string, currency:string, scheduled_date:string}>
GenerateExpectedTransfers  // queued listener of FundingAgreementRecorded + job dispatched after SaveAgreementInstallments; idempotent per installment_id
```

## §7 Schema owned by Team A (frozen at CP1)
**T03:**
- `proposals`: `donor_id` (NOT NULL, FK `donors` restrictOnDelete), `requested_budget` decimal(14,2) null, `requested_currency` varchar(10) null (`Currency`), `status_remind_on` date null, `approval_note` text null (D-26), `final_report_accepted_on` date null, `final_report_accepted_by` FK `users` null nullOnDelete (D-02c), `previous_proposal_id` self FK null nullOnDelete, indexed (renewal chain, T10; cycle guard in T10). Unique `proposals(id, donor_id)`.
- `donor_proposal`: unique `proposal_id`; composite FK `(proposal_id, donor_id) → proposals(id, donor_id)` ON DELETE/UPDATE RESTRICT.
- `project_proposal.target_beneficiaries` unsigned int null (D-25).
- `donors.is_internal` boolean default false, plus seeded internal donor «تمويل ذاتي — Hope» (D-27a).
- `milestones.proposal_id` FK `proposals` null cascadeOnDelete, `milestones.is_final_report` boolean default false (used by T04's `final_report` check; T31 owns their behaviour).
- `projects.working_days` JSON null (D-22): sorted distinct ISO weekday numbers 1–7 (1 = Monday, as `WorkingCalendar`); null = the global calendar (Mon–Fri default, `NotificationSettings` `working_days`). Cast `array`. No edit UI in October (§17 item 9). Read only through `WorkingCalendar::forProject()` (§9, T21 slice 2).
- `requested_budget` is in `Proposal::activityExcludedFields()`.

**T04:** `proposal_status_notes.is_correction` boolean default false, `changed_by` FK `users` null nullOnDelete. `MediaCollection`: `final_report`, `final_report_acceptance`.
**T05:** none (data migration only).
**T06:** none.
**T07:** `proposal_reviewers` (`proposal_id` FK cascade, `stage`, `employee_id` no FK, `slot`, `seen_at`, `note`, `added_by` FK users nullOnDelete; unique `(proposal_id, stage, employee_id)`); `meeting_at` datetime null on `proposal_status_notes` ⚑. `MediaCollection::NeedAssessment` (`need assessment`).
**T08:** `MediaCollection::DonorApproval` (`donor_approval`). No table change.
**T09:** `funding_agreement_installments` (`funding_agreement_id` FK `donor_proposal` cascade, `sequence`, `amount` decimal(14,2), `currency`, `scheduled_date`; unique `(funding_agreement_id, sequence)`); `transfers_tracking`: `source` varchar(20) default `manual` (`manual`|`auto`), `installment_id` FK `funding_agreement_installments` null nullOnDelete (unique when not null), `arrival_date` date null.
**T30:** `projects.center_id` unsigned big int null, indexed, **no FK** (HR connection).
**T34:** `sectors` + `project_sector` (owned by B3, see §12).

## §8 Permissions and default holders
Nine roles (fixed): Super Admin, General Manager, Managers, MEAL, Financial, Media Team, Public Relations, Public Relations Manager, Team Member. Super Admin holds everything technically (`Role::booted()`) but **appears in no business list, no fallback and no test as the only account** (D-20a). `permissions:sync` only adds.

| Permission | Default holders | Narrowed by policy to |
|---|---|---|
| `indicator-library.view` | everyone (`$viewEverything`) | — |
| `indicator-library.create/update/delete` | MEAL, General Manager | — |
| `proposals.manage-reviewers` | Financial, Public Relations Manager, General Manager | — |
| `proposals.correct-status` | **General Manager, Public Relations Manager** | — |
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

No `media-deliverables` module: department tasks use `tasks.*` / `milestones.*` with a server-side department condition (T31).

## §9 Settings and slots
- `notification_settings` key `proposal_default_reviewers` = `{meal_manager: ?int, finance_manager: ?int, fundraising_manager: ?int, ceo: ?int}` → **HR position id** (not a person). It resolves to the current holder (active contract) at use time through `EmployeeProfile`. A vacant slot → a warning, skipped.
- `App\Support\Slots::holderOf(string $slot): ?int` (employee id; T07) is the one resolver used by T07, T08, T14, T15, T17 (`meal_manager`) and T21.
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
}
```
- Current position = the active contract (`contracts.is_active = 1`); several active → latest `start_date` (logged warning); none → `null` / inactive.
- `App\Models\HrCenter` (`centers`) and `HrJobLevel` (`job_levels`) extend `HrModel` (read-only), with `options()` via `RemembersHrLookup`.
- `Project::center()` resolves through the lookup (no cross-connection relation). The project's city is always the center's city, written by the server.
- `Project::openProjectFor(int $centerId, int $programId, ?int $exceptId = null): ?Project` (see §3).

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
- `milestones.result_id` FK `results` null `nullOnDelete` → an `activity` of a proposal funding the milestone's project (or of the milestone's own `proposal_id`), or of the project's program framework. `Milestone::activity()`, `Milestone::activityLinkIssues(): list<string>`.
- `activity_schedules`: `result_id` FK `results` cascade (activity), `project_id` null (per-project override; null = proposal calendar), `month` date (first day), `weeks` JSON null (1–5, Monday-start; null = whole month); unique (`result_id`, `project_id`, `month`). Edited before approval by MEAL and the proposal editor, after approval by MEAL only (logged). Shifted once at approval by (actual − planned start) months, keeping weeks.
- Strategy (T32): `strategic_pillars` (`strategy_id` FK restrict, `code`, `name_ar`, `name_en` null, `goal_ar/en` null, `sort_order`; unique (`strategy_id`, `code`)); `programs.strategic_pillar_id` FK null nullOnDelete; `Program::pillar()`. Pillar filter path for T18: project → `program_id` → `programs.strategic_pillar_id`. Also `indicators.is_tracking_only`, `indicators.copied_from_id`, `library_indicators.imported_at` (T32); `CopyResultTree::handle(Result $sourceRoot, Proposal $proposal): Result`.
- Structural lock: after the proposal reaches `submitted`, proposal-framework nodes are created/moved/retyped/deleted only by `results.update` holders.
- Actions (T12): `SaveResult::handle(?Result $existing, array $validated, ?Proposal $proposal = null): Result`, `DeleteResult`, `LinkMilestoneToActivity::handle(Milestone $milestone, ?int $activityId): Milestone`, `SaveActivitySchedule`, and `App\Actions\Proposals\DeleteProposal::handle(Proposal $proposal): void` (**the one Team B file in `app/Actions/Proposals`; Dev 1 reviews it**).

## §12 Targets, planning, coverage, template, sectors

### §12.1 Per-center targets (T13)
- A proposal target = a `plan_indicators` row with `source_type = 'proposal'`, `source_id` = proposal id, `project_id` = the center's project, `indicator_id` = an `indicators` instance under a node of **that proposal's** framework and linked to a library entry. One per (source, indicator, project): new unique index `plan_indicators_source_indicator_project_unique` (`source_type`, `source_id`, `indicator_id`, `project_id`), with a duplicate pre-check before DDL.
- `plan_indicators.target_population` decimal(12,2) null: for percentage targets, the display base (defaults from `project_proposal.target_beneficiaries`; MEAL may edit). Display only ("80% × 180 = 144").
- Empty cell = no target; `0` = a real zero target. Percentage ≤ 100; average uncapped. Targets are entered separately per proposal/donor; combining happens only in displays (`shared` → the highest target; `split` → the sum).
- Writers: MEAL only (`targets.create/update/delete`, D-23). Editable in every non-terminal proposal status (from `concept note`); read-only for `rejected`/`completed`. Once a target has reports, its indicator and source lock (value and period stay editable).
- Picker scope (D-10a): library entries of the programs of the proposal's projects, plus institutional ones.
- `indicators.is_tracking_only` bool default false (T32): selectable, but no `plan_indicators` row.
- Actions: `AttachLibraryIndicatorToResult::handle(Proposal $proposal, int $libraryIndicatorId, int $resultId): Indicator`, `DetachIndicatorFromResult::handle(Indicator $indicator): void`, `SaveProposalTargetsGrid::handle(Proposal $proposal, array $cells): array{created:int, updated:int, deleted:int}` (atomic; `ValidationException` names the offending cells).
- Reading targets of a project in a period: `PlanIndicator::query()->forProjectInWindow(int $projectId, CarbonImmutable $from, CarbonImmutable $to)` scope (T13), reused by T17/T18/T19/T33.

### §12.2 Proposal planning (T31)
- `milestones.proposal_id` + `is_final_report` (columns by T03); T31 makes `milestones.project_id` nullable (at least one of `proposal_id`/`project_id` is set; checked in the action). Milestones are shared at proposal level; a center-specific one also carries `project_id`.
- `tasks`: `department_id` (HR id, no FK, indexed), `kind` varchar(30) default `general` (`TaskKind`: `general`, `media_deliverable`, `financial`), `media_kinds` JSON null (`DeliverableKind`: `video`, `photos`, `file`, `text`), `delivery_state` varchar(20) null (`DeliveryState`: `requested`, `delivered`, `accepted`, `returned`), `requested_on`, `delivered_on` date null, `delivered_link` varchar(2048) null, `delivered_text` text null, `accepted_at` datetime null, `returned_reason` text null.
- `proposal_plan_shifts` ledger: `proposal_id` FK cascade, `approval_note_id` FK `proposal_status_notes` (unique), `delta_days` int, `shifted` int, timestamps. Shift once per approval.
- Rebase trigger: `ProposalStatusChanged` with `to = approved` (**not** `ProjectActivated`). Delta = `actual_date_begin − planned_date_begin`. Planned dates are clipped to `actual_date_end`. Dates a user edited after creation are left alone (tracked by `milestones.planned_dates_edited_at` / `tasks.planned_dates_edited_at`, datetime null).
- Visibility: a department task is visible to `tasks.view` holders whose current HR department (`EmployeeProfile`) equals `tasks.department_id`, its assignee, and proposal-rights holders. `financial` tasks: Finance only. No money anywhere on **Department Tasks**.
- Actions: `SaveProposalMilestone`, `SaveDepartmentTask`, `DeliverDepartmentTask`, `AcceptDepartmentTask`, `ReturnDepartmentTask`, `RebaseProposalPlan::handle(Proposal $proposal, ProposalStatusNote $approvalNote): array{shifted:int, clipped:list<int>, skipped:bool}`.

### §12.3 MEAL coverage (T15)
- Table `meal_coverages`, model `App\Models\MealCoverageAssignment` (avoids a name clash with the support class): `scope_type` varchar(20) (`city` | `project`), `city_id` unsigned big int null (HR id, no FK; set when scope = city), `project_id` FK `projects` null cascadeOnDelete (set when scope = project), `employee_id` (HR id, no FK), `assigned_by` FK `users` null nullOnDelete, timestamps. Unique `city_id`, unique `project_id`.
- `App\Support\MealCoverage::responsibleFor(Project $project): ?int` (HR employee id): the active project officer → the active officer of the project's governorate (the center's city; fallback `projects.city_id`) → `Slots::holderOf('meal_manager')` → null (callers then use MEAL role holders).
- `MealCoverage::projectsCoveredBy(int $employeeId): list<int>`: directly assigned projects + projects in the employee's governorates, **excluding** projects assigned to someone else. Used by T17, T18, T21, T33.
- Managed by holders of `meal-coverage.manage` **who hold the MEAL Manager slot**. Events `meal_coverage.assigned`, `meal_coverage.responsible_changed`, `meal_coverage.project_assigned` (notify new, previous and governorate officers).

### §12.4 Monthly report template and activation gate (T33)
- **`evidence_types`**: `name_ar`, `name_en` null, `is_active`, `sort_order`. Managed by `evidence-types.manage` (D-42).
- **`narrative_questions`** (the org-wide bank): `text_ar`, `text_en` null, `frequency` (`monthly` | `quarter_end`), `sort_order`, `is_active`. Managed by `narrative-questions.manage` (D-43).
- **`report_templates`**: `project_id` FK `projects` unique cascadeOnDelete, `status` (`ReportTemplateStatus`: `pending`, `confirmed`), `confirmed_at`, `confirmed_by` FK users null nullOnDelete, timestamps. One per project.
- **`report_template_lines`**: `report_template_id` FK cascade, `plan_indicator_id` FK restrict (the target: carries proposal/donor/center; proposal targets or annual-plan org-wide targets), `status` (`ReportTemplateLineStatus`: `pending`, `confirmed`, `retired`), `schedule` (`LineSchedule`: `library_default`, `monthly`, `selected_months`, `once_after_months`), `schedule_months` JSON null (calendar month numbers 1–12), `once_after_months` smallint null, `entered_by` (`IndicatorCollector`; defaults from the library `collected_by`), `fixed_denominator` unsigned int null (from `project_proposal.target_beneficiaries` when the library `denominator_basis = fixed`), `effective_from` date (first month), `effective_to` date null (last month), `confirmed_at`, `confirmed_by`, timestamps. Unique (`report_template_id`, `plan_indicator_id`).
- **`report_template_line_evidence_types`** (pivot): the required evidence types per line.
- **`report_template_questions`**: `report_template_id` FK cascade, `narrative_question_id` null nullOnDelete, `text_ar`, `text_en` null, `frequency`, `sort_order`, `is_active`. The bank is copied in when the template is created; MEAL edits it per project.
- **Due rule:** `ReportTemplateLine::isDueIn(CarbonImmutable $month): bool`. Only `confirmed` lines, with `effective_from ≤ month ≤ effective_to`.
  - `library_default` uses the library `frequency`:
    - `monthly` / `per-event` → every month;
    - `quarterly` → the 3rd, 6th, 9th… month counted from the proposal start;
    - `semi-annual` / `annual` → the last month of each period from the proposal start;
    - `follow-up` → month (start + `follow_up_months`);
    - `per-season` / `baseline-endline` / `one-time` → never automatically (MEAL must choose `selected_months` or `once_after_months`).
  - `monthly` → every month; `selected_months` → listed calendar months; `once_after_months` → month (`effective_from` + N).
- **Pre-fill** (listener `PrefillReportTemplates`, queued, idempotent): on `ProposalStatusChanged` with `to = approved`. For each linked project: `report_templates` firstOrCreate (pending, questions copied); a **pending** line per library-linked `plan_indicator` of that proposal and project (`effective_from`/`effective_to` = the months of the proposal's actual dates). Targets without a library link are listed as "Link it to the library first". MEAL gets a "Confirm template" notification (`report_template.pending`).
- **Confirm** (`ConfirmReportTemplate::handle(ReportTemplate $template, list<int> $lineIds): ReportTemplate`): ≥ 1 line must end up `confirmed`. If the project is `approved`, call `ActivateProject($project, 'template_confirmation')` (T08) **in the same transaction**. If it's `active`, the newly confirmed lines notify the manager: "New indicators for proposal X, from month A to month B" (`report_template.lines_added`). Nothing is retroactive; no report is reopened (D-41).
- After confirmation, changes apply only to monthly reports still in `draft` (T17 Refresh Lines). `RetireReportTemplateLine` sets `effective_to` = the current month, so the line stops from next month.
- **Org-wide indicators (D-44):** `HC.*` library entries; a yearly target per center as an `annual_plan` `plan_indicators` row. Command `annual-plans:ensure-2026 {--dry-run}` creates the 2026 annual plan per program if missing (2026 only).
- **Launch backfill (D-45):** `report-templates:prefill-active {--dry-run}` creates pending templates for projects already `active`. They stay active; their lines appear after confirmation. It lists (ids only) the `fixed`-basis lines missing `fixed_denominator`; filling them is a human step (PR Manager, T06 post-approval edit), checked by T27 before go.
- **Who:** `report-templates.manage` AND (`MealCoverage::responsibleFor($project)` = the user's employee **or** the MEAL Manager slot holder). The MEAL queue = pending templates/lines of `projectsCoveredBy()` (all for the MEAL Manager).
- **Donor attribution (D-46):** `programs.donor_attribution` varchar(20) default `shared` (`DonorAttribution`: `shared`, `split`). Column and program-page setting by T13; read by T17, T18, T19.

### §12.5 Sectors (T34)
- `sectors`: `name_ar`, `name_en` null, `is_active`, `sort_order`, timestamps. Seeded: Health · Food security & livelihoods · WASH · Shelter & NFIs · Protection · Education (D-47).
- `project_sector` (pivot): `project_id` FK cascade, `sector_id` FK restrict; unique pair. Not a column on `projects`.
- `SyncProjectSectors::handle(Project $project, list<int> $sectorIds, bool $requireOne = false): void` (logged as `project.sectors_changed`). The project form (T05 `SaveProject`) calls it; ≥ 1 active sector is required for **new** projects. Component `<x-projects.sector-picker>` (T34) is used by the T05 form.
- A used sector is deactivated, never deleted; it stays visible with an "Inactive" badge. Managed by `sectors.manage`.

## §13 Monthly report (T17; contract frozen at CP3)
**Tables**
- `monthly_reports`: `slug`, `project_id` FK restrict, `period` date (first day of the month), `status` (`MonthlyReportStatus`: `draft`, `submitted`, `validated`), `prepared_by`, `submitted_at`, `submitted_by`, `validated_at`, `validated_by`, `returned_at`, `returned_by`, `return_reason` text null, `seen_by_meal_at`, timestamps. User FKs `restrictOnDelete`. Unique (`project_id`, `period`); index `status`.
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
- **Lines of month M** = the project's `confirmed` template lines with `isDueIn(M)` and their target's proposal window covering M, grouped by library indicator. `entered_by = meal` lines are read-only for the manager, don't block submit, and block **Validate** until MEAL fills them or marks them not measured (D-48).
- **Entry:**
  - counts: required on submit (0 allowed);
  - percentage with `denominator_basis = fixed`: the manager enters the count only; denominator = `fixed_denominator` (per share in `split` = that proposal's beneficiaries); numerator ≤ denominator; the server computes `value` (×100);
  - `entered` ratios/averages: numerator + denominator, or **Not measured** (`value` NULL, excluded from achievement, not a zero);
  - any `value`/`denominator` the request sends for computed lines is ignored.
- **Activities this month (D-51):** an activity appears if scheduled in M (`activity_schedules`), **or** its last confirmed status is not_started / started / ongoing / suspended (badge "Continuing from {month}" / "Late from {month}"). Required inputs: Not Started → reason (≥ 10); Started → start date; Suspended → reason; Cancelled → reason (final); Completed → completion date + evidence (final); Conducted (events only) → execution date + evidence. A continuous activity can't be Conducted; an event accepts only Not Started / Conducted / Cancelled.
- **Workflow:**

  | Move | Rule |
  |---|---|
  | generate | months generated at `ProjectActivated` (one draft per month of the period from the month of `actual_date_begin`); `ProjectPeriodChanged` adds new months only; idempotent via the unique index |
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
6. **Policy and queue.** `MonthlyReportPolicy`: `viewAny`, `view`, `open(User, Project)`, `update`, `fillMealLines`, `submit`, `validate`, `return`, `requestReopen`, `decideReopen`, `markSeen`, `delete`. `view` = `reports.view` (read-only). A query scope "reports this user may validate" (the responsible officer's projects; all for the MEAL Manager slot holder; projects whose `responsibleFor()` is null for any MEAL role holder) feeds T17B's **To validate** list and T18.
7. **MEAL lines (D-48a).** The validator fills, or marks Not measured, `entered_by = meal` lines only while the report is `submitted`.
8. **Activity-status restore.** On Validate, the previous `project_activity_statuses` state of each touched row (or "absent") goes into the `monthly_report.validated` activity properties; a granted reopen restores from the latest such entry for that report.
9. **Preparer side.** `reports.create`/`reports.update` AND (`User::ownedProjectIds()` contains the project OR `SupervisionMap::supervisorsOf()` of the owner contains the user's employee, once T23 is merged; §10).

## §14 Aggregation (T18, T19)
- **Only validated** (`approved` mirror) figures count; pending ones are shown but never summed.
- **Center view:** reads `monthly_report_lines` (Σ shares in `split`). **Donor view:** reads that donor's share mirrors (its share in `split`, the full value in `shared`).
- **Center target:** `split` → Σ the targets; `shared` → the highest.
- **Percentages:** only through `pooledRatioSql()`/`pooledRatio()` (§13); never an average of percentages.
- No money in the matrix. Donor reports (T19) exclude internal (self-funding) donors.

### §14.1 Figures and views (T18, T19)
- **Counts:** sum of validated values in the window. `is_cumulative`: the latest validated value in the window, never a sum.
- **Ratios** (`percentage`, `average`): only `pooledRatioSql()`/`pooledRatio()` (§13). Lines or shares without a denominator are excluded and counted next to the figure.
- **Center view row** = (`project_id`, `library_indicator_id`). Counts read `monthly_report_lines` of `validated` monthly reports. Ratios pool over the row's targets: `shared` → one representative target (highest target, lowest id on a tie; every share carries the same figures); `split` → all the row's targets.
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
| `proposal.on_hold_reminder` | proposals | I | all | no | proposal owner, editors | T21 |
| `proposal.final_report_accepted` (D-02c) | proposals | I | all | no | owner, program manager | T04 |
| `proposal.beneficiaries_changed` (D-25) | proposals | I | all | no | MEAL officer, MEAL Manager slot | T06 |
| `proposal.targets_saved` | proposals | D | all | no | none | T13 |
| `proposal.project_linked` / `proposal.project_unlinked` | proposals | D | all | no | none | T06 |
| `proposal.deleted` | proposals | D | all | no | none | T12 |
| `agreement.recorded` (`FundingAgreementRecorded`) | funding-agreements | I | all | no | Finance, PR Manager (no amounts for others) | T09 |
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
| `report_template.pending` | report-templates | I | all | no | responsible MEAL officer, MEAL Manager slot | T33 |
| `report_template.confirmed` | report-templates | I | all | no | owner, program manager | T33 |
| `report_template.lines_added` | report-templates | I | all | no | owner | T33 |
| `donor_report.saved` / `.submitted` | donor-reports | D | all | no | responsible employee | T19 |
| `donor_report.due` / `.overdue` | donor-reports | I | all | no | responsible employee (exempt from `funding-agreements.view`, routed to My Reporting Duties) | T19 |
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
