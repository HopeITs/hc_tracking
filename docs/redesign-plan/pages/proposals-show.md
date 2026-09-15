# P23 · Proposal detail

**Route:** `proposals.show` · **Group:** I · Proposals · **Wave:** 1 · **Depends on:** 02

## Files you own

- `resources/views/pages/proposals/⚡show.blade.php`
- `lang/*/proposals.php` (shared with P22)
- `tests/Feature/ProposalDetailTest.php`, `tests/Feature/ProposalStatusNoteTest.php` (only if UI assertions live there)

## Current state

Strongest detail page. Header (name, planned/actual ranges, status + phase badges, "Change status" button → modal writing `proposals.status` + a `ProposalStatusNote` with required context and effective date). Committed-per-currency stat tiles. Tabs: Status history, Funding, Projects, Tasks (title, assignee, status, progress %), Targets (indicator, project, period, value).

## Target layout

**Keep structure; upgrade visuals.**

1. **Detail header:** keep; `summary` slot:
   - **Status path**: the `ProposalStatus` sequence as connected steps (concept note → proposal draft → submitted → approved → active → final reports → completed); current filled, passed checked; `rejected` shown as a terminal critical step branching after "submitted" only when it's the current status.
   - `x-ui.timeline` single row planned begin–end with actual markers.
2. **Funding strip:** per currency `x-ui.capacity-bar` committed vs received (through `donorProposals.transfers`, `ar_status = received`).
3. **Tabs** with icons (`clock`, `banknotes`, `rectangle-stack`, `clipboard-document-check`, `chart-bar`):
   - **Status history:** vertical timeline: date, status badge, context text (escaped), newest first.
   - **Funding:** `x-ui.data-table`: donor (link) · project_num · budget money · received money · `progress-bar` · tranches → `funding-agreements.show`.
   - **Projects:** rows with status badge, city, program, milestone progress bar (weighted formula from P9).
   - **Tasks:** `progress-ring sm` instead of "NN%", `schedule-status`, assignee chip.
   - **Targets:** achieved `progress-bar` (latest report value, subquery pattern from P8).
4. "Change status" modal: unchanged fields, validation and method names.

## Tests

- Existing detail and status-note tests green.
- Add: status path marks current and passed steps; rejected branch; funding capacity per currency; tasks progress ring.

## Acceptance

- [ ] ≤ 12 queries on any tab.
- [ ] Conventions §10 checklist.
