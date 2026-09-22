# Proposal detail — the dossier · design

**Date:** 2026-09-21
**Surface:** `resources/views/pages/proposals/⚡show.blade.php` (route `proposals.show`, `/proposals/{proposal}`)
**Status:** approved by the user, ready for implementation

## Audience and job

The projects & fundraising manager. They open one proposal to answer two questions:

1. How much of what was promised has actually landed, and how much is still owed?
2. Does the money arrive before the work it pays for is due?

Secondary: officers checking which projects, tasks and targets hang off this proposal, and recording a stage move with its reason.

## Thesis

One proposal read as **a commitment on a clock**. Refuses the category default — a tab strip that hides four fifths of the connections behind a click.

Inherits the language established on `/proposals` (commit b078472): white ground, Somar Sans, huge tabular numerals, funding teal for received money, amber-striped for pending, HC red reserved for the current stage / rejection / overdue, hairline dividers rather than boxed cards, and a dot-and-thread motif for every sequence.

## Page order

1. **Header** — `x-ui.detail-header` with `title-class="text-3xl! font-bold! leading-none!"`. Meta: planned range, actual begin. Actions: status badge, phase chip, **Change status**, **Edit**. `proposals.notes` renders under the title as the standing brief: quiet body text, clamped past ~3 lines with a disclosure toggle. Distinct from the dated status-change reasons.

2. **The position** — money per currency, never summed across currencies. Four figures at index scale with count-up: Committed, Received, Pending, Not yet scheduled, over one segmented bar (solid teal received, amber-striped pending). Each figure carries a sub-note: received shows its percent of committed, pending its transfer count, unscheduled "committed with no transfer yet".

3. **Lifecycle rail** — the seven `ProposalStatus::lifecycle()` stops as a dot-and-thread. Past stops filled and dated from their status note, current filled with a red ring, ahead hollow. `Rejected` renders as a terminal red branch off Submitted, only when it is the live status. A stop that has a note is a button that scrolls to that entry in the history. The "Move to <next stage>" action sits at the live end of the rail.

4. **The time spine** — the signature. One horizontal date axis. Domain runs from the earliest of (planned begin, first status note, first scheduled transfer) to the latest of (planned end, last transfer date, last task due date). `Today` is a vertical HC-red line. Four lanes:
   - **Frame**: planned begin–end bar; actual begin / actual end markers.
   - **Money**: one mark per tranche — solid teal when received, amber-striped ring when scheduled, HC red when scheduled and past due. Mark size scales with amount.
   - **Work**: task due dates coloured by task status; overdue red.
   - **Signal**: status changes as small dots.

   Hovering or focusing a mark shows its detail and lights the matching row in the section below. Mirrors under RTL with logical properties. Every mark is keyboard reachable; the spine is duplicated as a plain dated list for assistive tech; `prefers-reduced-motion` skips the draw-in.

5. **Funding** — per agreement: donor (link to `donors.show`), agreement reference (link to `funding-agreements.show`), budget as a large numeral, over the received / pending / unscheduled bar. Beneath it every tranche on a dated line: scheduled date, amount, AR status, receiving account, plus fees and exchange rate shown **only** when the received currency differs from the agreement's. Money received in another currency is reported beside the bar, never folded into it.

6. **Projects** — dot-and-thread list: name (link), city · program, status.

7. **Tasks** — ordered by due date. Progress ring, status, assignee chips; overdue flagged red. A done/total slot bar above the list, matching the index dossier row.

8. **Targets** — indicator, result lineage, project or "organisation-wide", period, target value and real achievement drawn as a progress bar tinted by achievement status.

9. **Status history** — vertical thread, newest first: status badge, date, context.

**Sticky section-nav** replaces the tab strip: a thin strip under the header with the same labels and counts, which scroll-spies the active section and jumps on click. It navigates the page instead of hiding it.

## Constraints and decisions on record

- **Currencies are never summed.** USD / EUR / SYP have no conversion rate in this system. Every money figure is grouped per currency, exactly as `budgetByCurrency()` already does.
- **Target achievement** reads `PlanIndicator::achievedValueSql()` / `achievementStatusSql()` as subqueries on the targets query — never `achievedValue()` per row (N+1), never a local formula. Per `.ai/rules/resultsindicatorstargetsreports.md`.
- **Agreement currency** is read through `donorProposals()`, never `donors()->pivot`: the `belongsToMany` pivot carries no casts and returns currency as a raw string.
- **Query cost**: a continuous dossier loads every section. Target ~16–18 queries, all eager loaded, zero N+1. This supersedes the "≤12 queries on any tab" note in `docs/redesign-plan/pages/proposals-show.md`, which assumed one tab loaded at a time.
- **Modal binding**: `flux:modal` binds `wire:model` to a boolean, never `:open`. Both the status modal and the new edit modal carry their own boolean flag, and tests assert the boolean, not just the id. Per `.ai/rules/pages.md`.
- **No `@php(...)` shorthand** anywhere — it silently corrupts the compiled page. Full block form only. Per `.ai/rules/views.md`.
- **Strings**: every visible string goes through `lang/en/proposals.php` **and** `lang/ar/proposals.php` (shared words in `common.php`, enum labels via `$case->label()`). `tests/Feature/LocalizationTest.php` fails on any missing key or lost placeholder.
- **Delete** is not offered here. It stays on the index, where the "has no funding agreement" guard already lives.

## Data the page must reach

| Section | Source |
| --- | --- |
| Position, Funding | `DonorProposal` where `proposal_id`, with `donor`, `transfers.account` |
| Tranche marks | `transfers_tracking`: `scheduled_date`, `transfer_date`, `receive_date`, `payment_amount`, `net_amount_received`, `ar_status`, `fees`, `exchange_rate`, currencies |
| Lifecycle, Signal, History | `ProposalStatusNote` where `proposal_id`, ordered by date desc |
| Projects | `proposal->projects()` with `city`, `program` |
| Tasks, Work lane | `Task` where `proposal_id`, with `taskAssignees.employee` |
| Targets | `PlanIndicator` where `source_type = 'proposal'` (morph map key, never a class name) with `indicator.result`, `project`, achievement subqueries |

## Testing

- `tests/Feature/ProposalDetailTest.php` and `ProposalStatusNoteTest.php` stay green. The two `->set('tab', ...)` calls at lines 118 and 142 are dropped — with tabs gone that content always renders. No test is deleted.
- New coverage:
  - the lifecycle rail marks past, current and ahead stops, and renders the rejected branch only when rejected is live;
  - the position splits committed / received / pending / unscheduled per currency and never sums across currencies;
  - the tranche schedule flags a pending transfer whose scheduled date is past as overdue;
  - targets show achievement from approved reports only;
  - the edit action saves and the modal is driven by its boolean flag;
  - a proposal with nothing attached renders every section's empty state.
