# Proposal lifecycle correction: context pack

October 2026 · 15 working days (Mon 12 Oct → Fri 30 Oct) · Team A (Dev 1–3) + Team B (B1–B4).

**Core change:** a project starts as a draft with no dates. It gets dates only when a donor approves a proposal for it (→ `approved`), and it becomes `active` only when MEAL confirms its monthly report template.

## How to run a task
```
/lifecycle T04            # slice 1
/lifecycle T17A 2         # slice 2 of a multi-slice task
```
The command loads `AGENT.md` → `contracts.md` → `quality-bar.md` → the decisions the prompt lists → the prompt, then runs the start-state checks and enters plan mode. One slice per session; `/clear` between slices.

## Files
| File | Purpose | Who edits |
|---|---|---|
| `AGENT.md` | Rules every session follows | Dev 1 |
| `decisions.md` | Final answers D-01…D-54 | Dev 1 after management sign-off |
| `contracts.md` | Frozen names, values, signatures, events, schema, permissions | Dev 1 only (both teams agree) |
| `quality-bar.md` | Code and UI/UX bar every PR is reviewed against | Dev 1 + Dev 2 |
| `glossary.md` | Terms and roles | anyone |
| `prompts/TNN.md` | One prompt per task, flattened (no revision blocks) | Dev 1 |
| `prompts/later/` | Out of the October window (T14, T24, T25, T32b) | — |
| `handoffs/TNN.md` | Written by each task at the end; read by downstream tasks | task owner |
| `launch/` | Launch deliverables: `checks.sql`, `runbook.md`, `rehearsal-report.md` (T27); `uat-scripts.md`, `qa-report.md` (T28); `deployment.md` (T29) | T27 · T28 · T29 owners |
| `CHANGELOG.md` | History of the pack; agents never read it | Dev 1 |

## The story (8 steps)
1. **Project**: created in an HR center + program. Draft, no dates. One open project (draft/approved/active) per center + program.
2. **Concept note**: for one donor. concept note → concept note review → concept note submitted.
3. **Proposal**: the planning container (logframe, indicators, targets, milestones, Media and Finance tasks). proposal draft → proposal review → submitted.
4. **Donor approval**: fixes the real dates. The proposal → `approved`; each linked project whose owner acknowledged → `approved` with dates.
5. **Report template**: MEAL confirms what the project reports monthly. Project → `active`; monthly reports are generated for every month.
6. **Monthly reports**: the center manager submits, MEAL validates. Only validated numbers count.
7. **Agreement and transfers**: one agreement per approved proposal; one expected transfer row per installment.
8. **Donor reports**: aggregated on demand from validated monthly reports.

## Teams and ownership
| Phase | Tasks | Owner |
|---|---|---|
| 0 Foundations | T01 pack · T02 permissions · T30 HR | Dev 1+2 · Dev 3 · Dev 2 |
| 1 Lifecycle (critical path) | T03 → T04 → T05 → T08 → T09 · T06 · T07 | Dev 1 · Dev 2 · Dev 2 |
| 2 MEAL content | T11 · T12 · T13 · T31 · T10 · T15 · T16 · T32 · T34 | B2 · B1 · B2 · B3 · B3 · B1 · B3 · B4 · B3 |
| 3 Reports | T33 · T17A · T17B · T18 · T19 | B4+B3 · B1 · B2 · B3 · Dev 2 |
| 4 Cross-cutting | T21 · T22 · T23 · T26 | Dev 3 + B4 · B4 · B3 · B4 |
| 5 Launch | T27 · T28 · T29 | Dev 1 · everyone · Dev 1 |

**Team B never changes Phase 1 tables or the state machine.** It integrates by listening to the events in `contracts.md §5`, or by calling the Team A actions that `contracts.md §6` lists for it. Every Team B PR is reviewed by Dev 1 (backend) or Dev 2 (UI) within one working day. Only Team A has production access.

## Checkpoints
| CP | Day / date | Frozen or due |
|---|---|---|
| CP0 | 2 · Tue 13 Oct | `decisions.md`, permission names, table/column names, event names |
| CP1 | 3 · Wed 14 Oct | `contracts.md` signed; proposal schema; `projects.center_id`; `EmployeeProfile`; library schema; **contract stubs on `main`** (enums, event classes, action signatures) |
| CP2 | 6 · Mon 19 Oct | State machine merged; event classes dispatched; logframe, library and planning schemas in; Micro files approved by MEAL |
| CP3 | 9 · Thu 22 Oct | Monthly report + template schemas; `pooledRatioSql()`; generation listener; target grid; MEAL checks the template |
| CP4 | 11 · Mon 26 Oct | End-to-end test passes the whole path |
| CP5 | 12 · Tue 27 Oct | Feature freeze; first rehearsal passes; cut list applied |
| CP6 | 13 · Wed 28 Oct | Go / No-Go: UAT signed, runbook ready, encryption key stored off-server |
| Deploy | 14 · Thu 29 Oct | Maintenance window; 48 h monitoring; named on-call 31 Oct – 1 Nov |

## Cut list (apply in this order if late at CP3)
T16 → plain tree · T18 "group by indicator" · quarterly narrative questions · T23 My Team card · T10 logframe copy on renewal · T22 encrypting old files · T32 "Start from program framework" button. **Never cut** numerator/denominator in T17.
