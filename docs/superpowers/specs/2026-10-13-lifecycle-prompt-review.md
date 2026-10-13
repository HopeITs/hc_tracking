# Lifecycle plan: prompt review (rev 9 prompts)

Reviewed 13 Oct 2026 (day 2, CP0). I read T01, T04 and T17-A line by line and ran mechanical checks over all 32 prompts: referenced paths checked against the repo, skills checked against `.claude/skills`, and counts of terms that conflict between prompts.

**Verdict:** the domain content is excellent: precise, bilingual, and grounded in real file paths (every *existing* file the prompts point at does exist). The prompt engineering is what's broken. If you hand these prompts to 7 developers as they are, each Claude session will either **stop at the first precondition** (because the referenced docs are missing) or **settle the contradictions between revision blocks its own way**, and you end up with 7 incompatible versions of the same contract.

---

## P0: ship-blockers (fix before anyone starts a session)

### 1. The "authoritative" source does not exist
Every prompt says to read `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md` ("Arabic and authoritative") plus `…-decisions.md`, `…-open-questions-r6.ar.fixed.docx` and `…-r7.ar.docx`. **None of these files exist on any branch** (`main`, `gitlab/main`, or the current branch). T01's own precondition says: "The plan file exists… if not, stop and report."
- Even if you committed it, a 1.5 MB Arabic file is several hundred thousand tokens. That is more than the whole context window, so "read section 1.0, 5, 7, 9 and `### Task 17`…" can't be done in one session.
- `.docx` files can't be read reliably in Claude Code.

**Fix:** commit a small, canonical **context pack** (see the Architecture section). Prompts point at headings inside it, never at the 1.5 MB plan.

### 2. Stacked "Revision N wins" blocks contradict the prompt body
The model is asked to merge up to 5 revision layers mentally. It won't do that consistently, and the acceptance criteria and tests still encode the *old* rules, so the model will "pass" by building the wrong thing. Examples:

| Prompt | Revision says | Body / tests / acceptance still say |
|---|---|---|
| T02 | `proposals.correct-status` = GM + PR Manager (D-20) | "Super Admin only" **5×**, including "a test must pin this" and "Do not give it to General Manager" |
| T04 | `final reports` removed (D-02) | Transition table has `active → final reports`, phase map has `closure ← final reports`; test: "General Manager gets 403" on Correct Status |
| T04 | — | Role/goal: "terminal statuses can only be corrected by Super Admin" |
| T17-A | Reopen decider: Rev 6-8 = project MEAL officer fallback; Rev 9 = PR Manager fallback | Body: any `reports.reopen` holder. **Three answers in one prompt** |
| T17-A | Rev 3: lines by frequency + `collected_by` (D-36) | Body: "one line per active target". Plan (D-38) says **template lines from T33 replace D-36**, and D-38 appears in **zero** prompts |
| T28 (QA) | — | "`correct-status` (Super Admin only)" **9×**, so QA will log the *correct* behaviour as a defect |
| T01 | Plan has D-01…D-54, "0 open decisions" | "exactly 34 decision rows D-01…D-34"; Super Admin only for correct-status |

**Fix:** flatten. Each prompt is one current body with no revision blocks. Revision history goes into a changelog file that no agent reads.

### 3. The project state machine contradicts the plan (critical path)
The plan, D-04 and D-39 say: project **draft → approved** (donor approval + owner confirmation) **→ active** (MEAL confirms the template, T33).
- **T05** deprecates `ProjectStatus::Approved` and says "`draft → active` only automatically when a linked proposal is approved".
- **T08** body: approval "activates each project", "the only road from draft to active is `ActivateProjectFromApprovedProposal`". Only one revision line (D-04b) says otherwise.
- **T04** lists `approved → active` as a `TransitionProposal` move with an "actual start date". The plan says that move happens through MEAL template confirmation.

Dev 1 will build T05 → T08 against the wrong lifecycle, and every Team B listener (`ProjectActivated`) will fire at the wrong moment.

### 4. T33 and T34 have no prompt, and nobody owns the activation write
T33 (5.5 days) **is the gate that makes a project active**. It's on the critical path, but it has no prompt. Its owners are B4 + B3, while the team rules say "Team B never changes Phase 1 tables or the state machine". So who writes `projects.status = active`?
**Decision needed (yours):** recommended: Team A ships `ActivateProject` (T08) as the only writer; T33's `ConfirmReportTemplate` calls it, and it goes on the status-guard allowlist.

### 5. T01 is obsolete
Rev 9 says every decision is answered. T01 still runs a workshop for 34 decisions. Today's real day-2 work is **writing the context pack**, so replace T01 with that (prompt below).

---

## P1: quality problems that cost you correctness

6. **Skills that don't exist.** `laravel-security` (28 refs) and `database-migrations` (19 refs) aren't in `.claude/skills`. The real list is: `laravel-best-practices`, `livewire-development`, `fluxui-development`, `testing-best-practices`, `tailwindcss-development`, `fortify-development`, `infer-conventions`.
7. **Prompts are too big for one session.** T17-A is 44 KB of spec for 7.5 developer-days; T11, T12, T19 and T21 are about 30 KB each. On Pro, a session that size compacts mid-task, and the details lost in compaction are exactly the frozen-contract details. Split anything over ~2 days into **slices with hard stop points** (one session per slice, `/clear` between slices).
8. **About 90 "confirm in plan mode" questions**, counted mechanically across the prompts (T04, T12, T13 and T17-A each have 8). Each developer's Claude will answer them differently, and many of them are *cross-team contracts* (`stage()` for on-hold, `reports.value` nullability, event `via` values). Pre-decide them in `contracts.md`. Plan mode may only ask about things the pack doesn't answer, and any contract question goes to Dev 1, not to the developer at the keyboard.
9. **Handoffs disappear.** "Report back: your final message must contain the CP1 facts…" puts the contract into one chat transcript. Downstream tasks can't read it. **Fix:** every task writes `docs/lifecycle/handoffs/TNN.md`, which is committed and reviewed. Downstream preconditions read that file *and* check the code (class exists, test green).
10. **Contracts frozen as prose, not code.** CP1 "freezes" signatures as text. Make Dev 1 commit **stubs on day 3**: enum cases, event classes with typed constructors, action signatures throwing `LogicException('T04')`. Team B then codes against real types, PHPStan catches drift, and arch tests protect them.
11. **No "start state" ritual.** Prompts assume upstream work is merged. Add an opening step: `git switch main && git pull`, create a branch `lifecycle/TNN-slug`, run the upstream task's tests, and stop if they're red.
12. **Branch and commit rules conflict.** The prompts say "do not commit"; the team rules say "one branch per task, small daily merges". Say explicitly: Claude creates the branch, never commits, and leaves changes staged for the developer.
13. **Machine-specific commands.** `php artisan test` fails on machines that use an isolated PHP (this repo uses `.\php.cmd`). Put the PHP binary in each developer's `CLAUDE.local.md` and have prompts say "run tests using the PHP command from CLAUDE.local.md".
14. **TDD isn't required.** Tests are listed, but nothing makes them come first. For state machines and money, "write the dataset test first and watch it fail" is the single biggest correctness lever.
15. **The persona is the least valuable lever. Don't add more.** "You are a world-class 10x engineer" adds nothing measurable. What moves results is: one source of truth, explicit non-goals, an executable definition of done, and stop conditions. The prompts already have the last three; fix the first.

## What to keep (it's genuinely strong)
- The section skeleton (Scope / Out of scope / Business rules / Security / Validation / Edge cases / Data integrity / Tests / Acceptance / Do not).
- Exact bilingual message strings, exact file paths (all existing references verified), and named tests that must stay green.
- Security patterns: `Gate::authorize` inside the action, `lockForUpdate` + `expectedFrom`, BOLA via relation scoping, after-commit events.
- Plan mode first, `record-rule` instead of memory, no production access.

---

## Architecture: how prompts should be delivered

```
docs/lifecycle/
  README.md              ← 1 page: the story, the 8 steps, team rules, CP dates
  decisions.md           ← D-01…D-54 FINAL answers only, 1–3 lines each, no history
  contracts.md           ← enums + values, transition table, phase map, event classes + payloads,
                           action signatures, permission names, table/column names. Single source.
  glossary.md
  prompts/T01.md … T34.md  ← flattened, one body each (template below)
  handoffs/TNN.md        ← written by each task, read by downstream tasks
  CHANGELOG.md           ← revision history; agents never read it
.claude/commands/lifecycle.md  ← slash command: /lifecycle T04  (loads preamble + prompt)
```

`.claude/commands/lifecycle.md`:
```markdown
---
description: Run one task of the proposal-lifecycle plan
argument-hint: TNN
---
Read, in order: docs/lifecycle/README.md, docs/lifecycle/contracts.md,
the decisions listed in docs/lifecycle/prompts/$ARGUMENTS.md, then that prompt.
Follow its "Start state" section before anything else. Enter plan mode.
```

Every developer then runs `/lifecycle T04`. No copy-pasting from an HTML page, no version drift, and the prompts are reviewable in git.

### Per-task prompt template (target size 6–12 KB, one slice of ≤ 2 days)

```markdown
# TNN · <title> · owner <Dev/B#> · slice <k>/<n> · days x–y

## Goal (3 lines)
What exists after this session that didn't before, and who consumes it.

## Sources of truth (read these, nothing else)
- docs/lifecycle/contracts.md §<anchors>
- docs/lifecycle/decisions.md: D-xx, D-yy (final; do not re-decide)
- docs/lifecycle/handoffs/T<upstream>.md
- .ai/rules: <only the files whose globs match this task's paths>
- Skills: <only real ones>
- Code to read first: <exact paths>

## Start state (run, then stop if any check fails)
1. git switch main && git pull; git switch -c lifecycle/TNN-<slug>
2. Confirm upstream: <class/table/test that must exist>; run <upstream test file>, must be green.
3. Boost database-schema on <tables>.
If anything fails: write what failed to the chat and STOP. Never stub an upstream task.

## Scope
In: …   Out (owned by TNN): …

## Rules (current, flattened, no revision blocks)
Tables, guards, messages (en + ar), with every "confirm in plan mode" already answered.

## Non-negotiables
Security, concurrency and data-integrity bullets (unchanged from today's prompts).

## Build order (TDD)
1. Write <test file> dataset → run → red.
2. Implement <x> → green. 3. …

## Definition of done (executable)
- [ ] `<php> artisan test --compact <files>`: all pass (paste output)
- [ ] `vendor/bin/pint --dirty --format agent` clean
- [ ] Guard/arch test proves <invariant>
- [ ] docs/lifecycle/handoffs/TNN.md written (template: contracts delivered, deviations, follow-ups)

## Stop and escalate to Dev 1 (don't decide)
Anything that changes contracts.md, a Phase 1 table, or an event payload.

## Do not
…   Never commit (leave changes staged). Never touch production. Never run the full suite.
```

---

## Exemplar: T04 rewritten (flattened, corrected, ready to run)

> Assumes the context pack exists. The D-20 / T33 / phase-map choices below follow the plan text; confirm items marked ⚑.

```markdown
# T04 · Proposal state machine v2 · owner Dev 1 · slice 1/2 (transitions) · days 4–5

## Goal
Replace "any status to any status" with a server-enforced transition table. After this
slice, only TransitionProposal, CorrectProposalStatus, ApproveProposal (T08) and
ConfirmReportTemplate (T33) ⚑ can write proposals.status, proven by a guard test.
Slice 2 (day 6): Change Status / Correct Status modals + all callers migrated.

## Sources of truth
- contracts.md §ProposalStatus, §Transitions, §Phases, §Events (CP1 frozen; do not change)
- decisions.md: D-02c, D-03, D-20, D-25, D-26, D-28
- handoffs/T03.md (donor_id, requested_budget/currency, status_remind_on,
  target_beneficiaries), handoffs/T02.md (proposals.correct-status → GM + PR Manager)
- .ai/rules: enums.md, views-enums.md, proposals.md, models.md, providers.md,
  database-migrations.md, migrations.md, factories.md, tests.md
- Skills: laravel-best-practices, testing-best-practices
- Read first: app/Enums/ProposalStatus.php, app/Enums/ProposalPhase.php,
  app/Actions/Proposals/ChangeProposalStatus.php, app/Policies/ProposalPolicy.php,
  app/Models/Proposal.php, app/Models/ProposalStatusNote.php,
  tests/Feature/AccessControl/AuthorizationCoverageTest.php (source-scan pattern)

## Start state
1. git switch main && git pull && git switch -c lifecycle/T04-state-machine
2. Verify: columns proposals.donor_id, requested_budget, requested_currency,
   status_remind_on; project_proposal.target_beneficiaries; permission
   proposals.correct-status in config/access-control.php. Run T03's test file → green.
3. Boost database-schema on proposals, proposal_status_notes.
Any failure → report and STOP.

## Scope
In: ProposalStatus cases + phase()/next()/lifecycle()/stage(); ProposalPhase::Ideation;
migration add is_correction + changed_by to proposal_status_notes; ProposalTransitions;
TransitionProposal; CorrectProposalStatus; ProposalPolicy::transition/correctStatus;
RecordFinalReportAcceptance; event class stubs (ProposalStatusChanged dispatched here;
ProjectActivated, ProjectPeriodChanged, FundingAgreementRecorded classes only).
Out: ApproveProposal (T08), ConfirmReportTemplate (T33), reviewers (T07),
create form (T06), on-hold reminders (T21), modals + caller migration (slice 2).

## Rules
Statuses (existing values keep names; `final reports` is REMOVED):
concept note · concept note review · concept note submitted · proposal draft ·
proposal review · submitted · on hold · approved · rejected · active · completed

| From | To | Required |
|---|---|---|
| concept note | concept note review | ≥1 linked project, donor, planned dates |
| concept note review | concept note | reason |
| concept note review | concept note submitted | sent date ≤ today |
| concept note | proposal draft | skip reason (preset «المقترح موجود مسبقًا») |
| concept note submitted | proposal draft | donor-acceptance date ≤ today |
| concept note submitted | on hold / rejected | see on hold / rejected rows |
| proposal draft | proposal review | requested_budget + currency + planned dates + target_beneficiaries on EVERY linked project |
| proposal review | proposal draft | reason |
| proposal review | submitted | submission date ≤ today (+ target_beneficiaries check) |
| submitted | proposal draft | reason (donor asked to resubmit) |
| submitted, on hold | rejected | reason ≥ 10 chars + date |
| submitted | on hold | reason + status_remind_on > today |
| on hold | submitted / proposal draft / rejected | the target row's fields |
| submitted, on hold | approved | REFUSED here: ApproveProposal (T08) only |
| approved | active | REFUSED here: ConfirmReportTemplate (T33) only ⚑ |
| active | completed | final-report milestone complete + media `final_report` file + final_report_accepted_on/_by (acceptance email optional). Self-funded: milestone + file |

- Phase (derived, never stored, match without default): ideation ← 3 concept-note
  statuses; planning ← proposal draft, proposal review, submitted, on hold;
  implementation ← approved; monitoring ← active; archived ← completed, rejected.
- Terminal: rejected, completed. Only CorrectProposalStatus leaves them: holders of
  proposals.correct-status (General Manager, Public Relations Manager; NOT Super Admin
  by role default), mandatory reason, is_correction = true, never targets approved.
- Every move: one ProposalStatusNote (status, context, effective_date, changed_by) in
  the same transaction; on hold sets status_remind_on, leaving clears it; context NOT
  NULL → use the translated standard note for moves without a reason.
- Messages (lang/{en,ar}/proposals.php): keys and texts in contracts.md §Messages.
  The budget-gate message never shows an amount.

## Non-negotiables
- Gate::authorize inside each action; reload by id with lockForUpdate(); compare
  expectedFrom → stale message, write nothing.
- Remove `status` from Proposal #[Fillable] (grep `'status'` fill callers first).
- ProposalStatusChanged dispatched once, after commit only; none on refusal/rollback.

## Build order (TDD)
1. tests/Feature/ProposalTransitionTest.php: dataset, one row per table line (success +
   note with changed_by) and one per missing field → run → red.
2. Enum + ProposalTransitions::map()/allows()/requirements() → green.
3. TransitionProposal + policy; concurrency + double-submit + event tests.
4. CorrectProposalStatusTest: GM and PR correct rejected/completed with reason;
   Financial and Managers → 403; non-terminal source refused.
5. FinalReportCompletionTest: no file → refused; file without acceptance → refused;
   file + acceptance without email → passes; non-PR → 403.
6. ProposalStatusWriteGuardTest (source scan): allowlist = the four actions above.
7. Update ProposalPhaseTest, ProposalStatusNoteTest, EnumCastTest.

## Definition of done
- [ ] Each file above + ProposalDetailTest, ProposalPipelineTest, DashboardTest,
      GanttTimelineTest, LocalizationTest pass (paste output)
- [ ] Pint clean
- [ ] handoffs/T04.md: final map(), requirements() keys, handle() signature + return
      type, policy ability names, guard allowlist, event FQCNs + payloads

## Stop and escalate to Dev 1
Any change to contracts.md §Transitions/§Events, or an existing status value.

## Do not
Store phase/stage · add a default arm · write status from any form · keep the
full status list anywhere · commit · run the full suite · touch production.
```

---

## Replacement T01 (today's real day-2 work, Dev 1 + Dev 2, ~1 day)

```markdown
# T01 · Build the lifecycle context pack · NO application code
Goal: turn plan rev 9 (attached HTML / Arabic source) into docs/lifecycle/ as in the
architecture above. Every later prompt reads only this pack.
1. decisions.md: D-01…D-54, final answer only (latest revision wins), 1–3 lines,
   with the tasks each blocks. Flag any decision whose revisions disagree → list for
   management; do not pick.
2. contracts.md: every enum value, transition, phase, permission name, event class +
   payload, action signature, new table/column that crosses a task boundary.
   Resolve: project status flow (draft→approved→active), activation writer (T33),
   correct-status holders (D-20).
3. Flatten prompts/TNN.md to the template; delete revision blocks; replace phantom
   skills; answer every "confirm in plan mode" item in contracts.md or mark it
   "Dev 1 decides at CP1".
4. Write prompts for T33 and T34 (missing today).
Done: zero references to the .ar.md plan or .docx files; grep for "Super Admin",
"final reports", "D-36", "laravel-security", "database-migrations" returns only
intended hits; Dev 1 and the MEAL Manager sign off on contracts.md.
```

---

## Fix list, all 32 prompts

Legend: **R** = revision blocks to flatten · **SA** = stale "Super Admin" refs · **FR** = stale `final reports` refs · **Q** = plan-mode questions to pre-decide. All prompts: replace the `.ar.md`/`.docx` references and the phantom skills, add Start state, add a handoff file.

| Task | Size | R | Specific fixes |
|---|---|---|---|
| T01 | 19 KB | 3 | Replace entirely (above). D-01…D-34 → D-54; correct-status is GM + PR |
| T02 | 17 KB | 3 | SA×10: body/test/acceptance pin Super Admin, flip to D-20 (GM + PR) |
| T30 | 18 KB | 2 | Consumers (T05, T10, T14, T17) need `EmployeeProfile` API in contracts.md |
| T03 | 19 KB | 3 | Q×5 → pre-decide; add `target_beneficiaries` location to contracts (T04 and T17 depend on it) |
| T04 | 23 KB | 3 | Rewritten above. FR×5, SA×2, approved→active owner |
| T05 | 20 KB | 2 | **P0:** keep `ProjectStatus::Approved` live; flow is draft→approved→active; owner of each move |
| T06 | 23 KB | 2 | Split: form UI / SaveProposal; depends on T04 handoff file |
| T07 | 22 KB | 2 | Reviewer hook point inside TransitionProposal: name it in contracts.md |
| T08 | 21 KB | 2 | **P0:** approval → project `approved`, not `active`; `ProjectActivated` fires from T33 path; fix "only road" sentence and tests |
| T09 | 19 KB | 2 | SA×3, FR×1 |
| T11 | 30 KB | 4 | Split into schema+seed command / UI slices; Q×5 |
| T12 | 30 KB | 4 | Split; Q×8; parent-level rule into contracts.md |
| T13 | 26 KB | 5 | Q×8; "empty vs 0" rule → contracts.md |
| T31 | 20 KB | 2 | Rebase listener on `ProposalStatusChanged`/approval event; payload from contracts |
| T10 | 21 KB | 2 | FR×1; uses closed→draft reopen from T05 |
| T14 | 15 KB | 1 | Post-launch: mark it so nobody starts it in October |
| T15 | 19 KB | 3 | `MealCoverage::responsibleFor()` signature → contracts.md (T17 depends on it) |
| T16 | 16 KB | 1 | First to cut: add "minimum viable = plain tree" slice |
| T32 | 16 KB | 2 | Micro JSON files must be committed before start (precondition) |
| **T33** | none | – | **Write it.** Template backend + confirm action + activation gate |
| **T34** | none | – | **Write it.** Sectors |
| T17-A | 44 KB | 5 | **Split into 4 slices** (schema/models → actions/policy → listener+commands → alerts/catalog). Reconcile 3 reopen deciders → D-09a (MEAL Mgr → PR Mgr). Lines come from the T33 template (D-38), not frequency (D-36). Q×8 |
| T17-B | 28 KB | 5 | SA×1; read contract from handoffs/T17-A.md, not chat |
| T18 | 26 KB | 5 | Must use `pooledRatioSql()` from contracts; Q×6 |
| T19 | 30 KB | 4 | SA×2; split aggregate engine / delivery UI; Q×6 |
| T21 | 29 KB | 2 | Two owners (Dev 3 + B4): split the prompt per owner with a file boundary |
| T22 | 22 KB | 4 | Key-handling steps must be human-run; Claude never sees the real key |
| T23 | 22 KB | 1 | Depends on T30 `EmployeeProfile::managerOf()`: read it from handoffs/T30.md |
| T26 | 18 KB | 1 | Runs per UI task: convert into a checklist appended to every UI prompt |
| T27 | 29 KB | 5 | FR×3 (data move from `final reports` → `active`, keep this one), SA×1 |
| T28 | 28 KB | 4 | **SA×9: QA would fail correct behaviour.** Rebuild scenarios from contracts.md |
| T29 | 22 KB | 1 | Human-run runbook; Claude drafts only |
| T24 / T25 | 20 / 18 KB | 2 / 1 | Out of window: move to `prompts/later/` so nobody starts them |

## Pro-plan operating rules for every developer
- One slice = one session. `/clear` between slices; never let a slice auto-compact. If it does, stop, write the handoff, and start fresh.
- Use the strongest model you have for T03, T04, T05, T08, T09, T17-A and T19 (state, money, contracts). A lighter model is fine for T26, T34 and T14.
- Always start in plan mode (Shift+Tab). Approve the plan only after it cites `contracts.md` for every contract it touches.
- Dev 1 reviews every `handoffs/TNN.md` the same day. That file is the integration contract, not the chat.
