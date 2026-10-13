# Context pack changelog

Agents never read this file. It records how the pack differs from the original plan prompts (rev 9, 12 Oct 2026) and why.

## 2026-10-13: pack v1 (replaces the per-task prompts of plan rev 9)

### Structural
- Each prompt is flattened into one current body; the stacked "Revision N wins" blocks are gone.
- References to the missing `docs/superpowers/plans/…ar.md` plan and the `.docx` decision files are removed. `contracts.md` + `decisions.md` are now the source of truth.
- The phantom skills `laravel-security` and `database-migrations` are replaced by the real skills.
- Session rules move into `AGENT.md` (start-state ritual, TDD, stop conditions, the `handoffs/` files).
- Large tasks are split into slices of one session each (T04, T06, T09, T11, T12, T13, T16, T17A, T17B, T18, T19, T21, T22, T31, T32, T33).
- Prompts were written for T33 and T34 (the plan had none). T01 now means pack sign-off plus the CP1 contract stubs.
- T14, T24 and T25 move to `prompts/later/`.

### Rulings (decisions the pack made where the sources disagreed or were silent)
| # | Ruling | Why | Cost if wrong |
|---|---|---|---|
| R1 | `proposals.correct-status` = GM + PR Manager; Super Admin is in no business list | D-20/D-20a (rev 9) overrode the prompts' bodies, tests and QA scenarios | small: permission rows + tests |
| R2 | The project lifecycle is draft → **approved** (approval + owner acknowledgement) → **active** (template confirmation); `ProjectStatus::Approved` stays live | plan diagram, D-04b, D-39; T05/T08 bodies said draft → active at approval | high if reversed: T05/T08/T33/T17 trigger points |
| R3 | `approved → active` is written only by Team A's `ActivateProject`, which T33 calls | user decision (13 Oct): keeps Team B off the state machine | low |
| R4 | `ActivateProject` also moves the project's still-`approved` proposals → `active` ⚑ | the plan diagram shows APP → ACT on template confirmation | low: one action |
| R5 | `ProposalStatus::FinalReports` stays as `@deprecated` (history rendering), with rows migrated to `active` in a T04 data migration | the precedent in T05 (deprecated project statuses) | low |
| R6 | All new Phase 1 columns are created by T03 by CP1, including `approval_note`, `final_report_accepted_*`, `previous_proposal_id`, `milestones.proposal_id/is_final_report`, `donors.is_internal` | one schema owner; Team B never migrates Phase 1 tables; unblocks T04's final-report gate | low |
| R7 | The T31 plan rebase and the T12 activity shift listen to `ProposalStatusChanged(to: approved)`, not `ProjectActivated` | under R2, activation comes later than approval | medium |
| R8 | The schedule table is `funding_agreement_installments`, not `…_tranches` | "tranche" already means a `transfers_tracking` row in this codebase (`Tranches.php`) | low (naming) |
| R9 | The library code is server-generated `PROGRAM.LEVEL.SEQ` (rev 9); there are no hand prefixes like `MIC-U.` (T32 body) | D-10, rev 9 | low |
| R10 | The library drops the `disaggregation` JSON/enum; `is_disaggregated` alone drives the gender + disability breakdown | D-50 fixes the breakdown shape; there are no age groups | low: one column later |
| R11 | Monthly report lines come from the confirmed T33 template (D-38); line = library indicator; **shares exist in both attribution modes** ⚑ | one mirror path for `achievedValue()`; rev 5 said `shared` creates no shares | medium: T17A internals |
| R12 | Pooling for `fixed`-basis %: numerator = Σ monthly counts (latest if cumulative) over a fixed denominator; `entered` = latest approved ⚑ MEAL to confirm | D-25 "new people each month" + "count ÷ target beneficiaries" | medium: T17A/T18/T19 formulas |
| R13 | GM does **not** validate monthly reports (only the MEAL chain) | D-07 / D-12a; the T17A body allowed GM | low |
| R14 | `meal-coverage.manage` and `report-templates.manage` are excluded from the GM baseline ⚑ | the plan's T02 key rules name the MEAL Manager seat | low: config rows |
| R15 | The model for `meal_coverages` is `MealCoverageAssignment` | avoids a clash with `App\Support\MealCoverage` | none |
| R16 | `programs.donor_attribution` is owned by T13 | its first consumer; T33/T17 read it | low |
| R17 | Sectors live in a `project_sector` pivot; T34 ships a picker component that the T05 form includes | Team B doesn't edit Phase 1 forms | low |
| R18 | T14 uses a `SaveProject` hook + a daily scan, not a new `ProjectObserver` | Phase 1 model ownership; T14 is post-launch | low |
| R19 | The Plan tab (T31) replaces the proposal page's existing `tasks` tab, with a redirect for `?tab=tasks` | one planning surface | low |
| R20 | The targets grid rejects the **whole batch** on any stale cell | section 5 of the plan ("no partial save") over the T13 body | low |
| R21 | T17A/T17B additions merged as `contracts.md §13.1` items 1–9; submit sequencing = the **nearest earlier month with lines** (the §13 workflow row now cites it) | fixes a real D-08 gap: a line-less M-1 could hide an unsubmitted M-2 | low |
| R22 | `monthly_report.reopen_escalated` added to the §13 event list and to T17A's notifications | §15.2 and D-09a (no decider → GM) had it; §13 did not | none |
| R23 | The T19 donor-report schema and API are `§14.2`–`§14.3`, not a new §18; T18/T19 aggregation rules are `§14.1` (existing §14 text kept above them, no conflict) | integrator brief: one aggregation section | none |
| R24 | §16: the cut-list note cites the README cut list (the proposal's "R9" was the library-code ruling); every later file table is added to `files:decrypt` / `files:encrypt-existing` / `security:reencrypt --files` by its owning task; §13 `monthly_report_files` names `encryption_key_id` | wrong cross-reference; the command table list was closed while §16 schema says "every later file table" | low |
| R25 | `projects.working_days` JSON null (ISO weekdays 1–7, null = global) is a T03 column (§7); `WorkingCalendar::forProject()` (§9) is delivered by T21 slice 2a, whose escalation for it is dropped. Edit UI and the T17A/T19 switch-over are §17 items 9–10 | ruling (13 Oct): D-22 had no owner | low: one column + one method |
| R26 | T24/T25 contracts are `§18.1`/`§18.2` "Out-of-window"; `§17 Open items` stays the last section; their catalog keys are added to §15 by those tasks when built | integrator brief | none |
| R27 | T23 `SupervisionMap` + `supervisions` table go in §10; `supervision.added/removed` join the §15.2 table; `escalation.supervisor` is documented as an alert, not a catalog key | proposal placement; the alert is a scanner message, not an activity event | none |
| R28 | Prompts say "stable `data-<name>` tour hooks (see `config/tour.php`)" instead of `data-tour` / `data-test` hooks (T07, T11, T16, T17B, T18, T23, T24, T33) | `config/tour.php` targets bare `data-<name>` attributes | none |
| R29 | Owner prompts emit the §15.2 keys they own: T04 `proposal.final_report_accepted`; T08 `proposal.approved_without_agreement`, `project.owner_acknowledged`, `project.owner_declined`, `project.period_changed` | cross-check: the keys were in §15 with no producer | low |
| R30 | The D-25 `target_beneficiaries` backfill for existing proposals is a human data-entry step (PR Manager, T06 post-approval edit). `report-templates:prefill-active` lists `fixed`-basis lines missing `fixed_denominator` (§12.4, T33); T27 runbook row 8a requires that list empty or accepted before go | coordinator ruling (T27 gap) | medium: lines without a denominator are excluded from pooling |
| R31 | D-27a self-funded relinking: T27 adds a read-only "projects without any proposal" check (runbook row 12a); Dev 1 decides per row (manual **Create Proposal** on «تمويل ذاتي — Hope»). No new command, no DML | coordinator ruling (T27 gap) | low |
| R32 | Stale ruling numbers removed from prompts: T18 "R9 cut list" → README cut list; T29 "R11" → "flood check"; T27/T29 read command flags, env names and the `UnreadableStoredFile` log fields from `contracts.md §16` instead of `handoffs/T22.md` | the numbers pointed at unrelated rulings; §16 is now the frozen source | none |

Items marked ⚑ are listed in `contracts.md §17` for Dev 1 to confirm at CP1.
