# Lifecycle pack sync: from the corrective prompts to `docs/lifecycle/`

14 Oct 2026. Source: `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/` (final version, including note 23 of its README). Target: `docs/lifecycle/` (pack v1, built on 13 Oct from an earlier snapshot of the same prompts).

Rule used: the corrective prompts are the reference for **content**; the pack keeps its **format** (flattened prompts, slices, `AGENT.md`, handoffs). Prompt-level gaps were applied directly. Anything that would change `contracts.md` or `decisions.md` (frozen, Dev 1) is listed under "Needs a decision" and was **not** applied.

## Applied to every task
- `docs/lifecycle/quality-bar.md` (from `quality-bar.md`; section 1 now points at `AGENT.md §1` instead of the Arabic plan and revision blocks).
- `/lifecycle` reads it after `contracts.md`; `AGENT.md §1` and `README.md` reference it.
- A "Quality bar (this task)" section before "Definition of done" in every prompt (T17 → T17A and T17B; T14, T24, T25 in `later/`; T20 had none).

## Per task

### T05
**Applied:** reuse the existing `open_project_exists` message (with `:name`); `center_required` and `status_not_from_form` messages; tampering rules (city, dates, status); concurrent close; dated milestones on a draft; the `approved` badge; activity logging; the centre tests (required centre, tampered city, same/other program, no "renew" option); `phase()` for `active` = the current proposal's phase (implementation / monitoring / closure), else Implementation.

**Needs a decision (contract):**
- **Legacy `approved` rows with dates.** Corrective prompt: stay `approved` and enter the T33 template queue. `contracts.md §3`: become `active`.
- **Working-days field (D-22).** Corrective prompt: T05 renders it on the project form (ISO weekday checkboxes, "use the organisation's week" when empty). `contracts.md §17 item 9`: no edit UI in October.

### T07
**Applied:** the one meaning of "vacant" (no position, no active contract, or inactive holder) and "Super Admin is never a fallback"; per-request authorization; `RecordsParentActivity`; policy coverage and the morph-map rule; the cached employee picker; the oversight-recipients test once T21 is merged.

No contract conflicts.

### T08
**Applied:** an `approved` project with a second approval stays `approved` and its targets join the pending template; no new `proposals.approve` permission; `note` max 2000; legacy active projects without an approved proposal are only reported; transfers never wait for activation; the T05 badge "Funded, awaiting report template" (instead of "Awaiting MEAL template"); result message wording; the real actor and `via` on activity rows; no `requested_budget` in activity data; T27 invariant query "every newly active project has a confirmed template".

**Kept from the pack (code already exists on `main`):** the names `ApproveProjectFromProposal` (corrective prompt: `FundProjectFromApprovedProposal`) and `ActivateProject` in T08 (corrective prompt: `ActivateProjectFromConfirmedTemplate` in T33). Only the names differ; the behaviour is the same. Approval from `on hold` only when the pre-hold stage was `submitted` (matches the T04 follow-up commit).

**Needs a decision (contract):**
- **`ProjectFunded(projectId, proposalId, startDate, endDate)` event.** Corrective prompt: dispatched once per project that becomes `approved` (approval path **and** owner-acknowledgement path), and T33 drafts the template from it. Pack: no such event; T33 pre-fills on `ProposalStatusChanged(to: approved)`. Gap in the pack: a project whose owner acknowledges **after** the approval becomes `approved` without any event, so T33 has no direct trigger for it. Adding the event changes `contracts.md §5`, the T01 stubs, T08, T21 and T33.
- **Activity key.** Corrective prompt: `project.funded`. Pack (`contracts.md §15.2`): `project.approved`.
- **R4 (pack ruling ⚑):** `ActivateProject` also moves the project's `approved` proposals to `active`. The corrective prompt doesn't say this.

### T09
**Applied:** at least one installment; dates inside the agreement term; deleting the generated row when an installment with no actuals is removed; agreements with manual transfers stay untouched; **`stateSql()` and `Tranches::timing()` updated together, with the parity test ("in transit" until arrival)**; proposal lock before the pre-check; generation in its own transaction with amounts as stored; Correct Status and co-funder cases; three messages (arrival before transfer, outside term, empty schedule); the matching tests (incl. MEAL → 403 on the schedule).

**Kept from the pack:** `installments` instead of `tranches` for the schedule (R8: "tranche" already means a `transfers_tracking` row in `Tranches.php`).

No contract conflicts.

### T10
**Applied:** how the similarity search works (`Like::contains` per word, Arabic matching, exclude the current center, newest first); the "another program in the same center is accepted and listed" test; the `nullOnDelete` test for the predecessor.

The pack version already covers everything else (and adds `previousYearReference()` for T13). No contract conflicts.

### T11
**Applied:** `measure` decides the unit; the `indicators.name` question for plan mode; unique-index retry, the double-submit warning, the delete race; factory states `institutional()`, `disaggregated()`, `collectedByMeal()`; the `forPrograms()` scope; `CollectionFrequency::period()` for T13.

**Needs a decision (contract):**
- **R10 (pack ruling):** the pack drops the `disaggregation` JSON column and the `Disaggregation` enum (`gender`, `disability`), and `is_disaggregated` alone drives the breakdown. The corrective prompt keeps both, next to `is_disaggregated`. T11 is not on `main` yet, so this is cheap to decide either way.

### T12
**Applied:** months outside the planned period refused (`month_outside_period` «الشهر خارج مدة المقترح») with its test; an activity without months never reaches the monthly report and shows «بلا رزنامة»; activities may carry indicators; RAG stays on the calendar year; the FK race message; `result.*` events in the T21 catalog; a flat query count for the tab.

No contract conflicts.

### T13
**Applied:** the «غير مرتبط بالمكتبة» badge (a legacy instance can't enter a template); a proposal target = the donor's commitment, and T33 never overwrites it; a single total target (D-50a); indicator/source locked once reported; value/period validation and messages; activity default back to the corrective prompt (per-row activity kept; a quiet batch only if plan mode agrees). The pack had made the batch quiet.

No contract conflicts.

### T16
**Applied:** start only when Dev 2 confirms there's room (B3's days 9–10 belong to T33's UI); cross-cutting group; inactive entries and the chain-issue flag; `forget…()` after writes; in slice 2, each activity's status badge from `project_activity_statuses` (T17) and its months/weeks from `activity_schedules`.

No contract conflicts.
