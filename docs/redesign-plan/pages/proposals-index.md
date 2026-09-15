# P22 · Proposals index (pipeline)

**Route:** `proposals.index` · **Group:** I · Proposals (same owner as P23) · **Wave:** 1 · **Depends on:** 02 (`kanban`, `timeline`)

## Files you own

- `resources/views/pages/proposals/⚡index.blade.php`
- `lang/*/proposals.php`
- `tests/Feature/ProposalPipelineTest.php`

## Current state

Filters: search (name/notes), status, phase, reset; add/edit modal (name, planned/actual begin–end dates, status with phase shown, notes); delete guarded when agreements exist. Rows: name, planned date range, status badge, derived phase badge, counts (agreements, projects, tasks).

`ProposalStatus`: concept note, proposal draft, submitted, approved, rejected, active, final reports, completed. `ProposalPhase` (derived accessor, no column): planning, implementation, monitoring, closure, archived.

## Target layout

1. **Header:** keep filters + "Add proposal"; `x-ui.view-toggle` (`#[Url] view`: `pipeline` | `list` | `timeline`, default `pipeline`).
2. **Summary slot:** `x-ui.status-strip` by phase (`wire-model` = existing phase property).
3. **Pipeline view:** `x-ui.kanban` with one column per `ProposalPhase` (enum order). Cards: name link, status badge, planned date range, `x-ui.schedule-status :planned="planned_date_end" :actual="actual_date_end" :done="phase in [closure, archived]"`, committed money per currency (≤ 2 lines, grouped query like P20), counts row (agreements / projects / tasks icons). Max 50 per column with "+{n} more" as in P13. **Read-only**: status changes stay in the detail page's "Change status" modal because each change requires a context note.
4. **List view:** `x-ui.data-table` with the current columns + committed money per currency.
5. **Timeline view:** `x-ui.timeline` rows: planned begin → planned end bar, actual end marker, tone by phase; sorted by `planned_date_begin`.

## Tests (`ProposalPipelineTest.php`)

- Existing tests green (phase derivation filters).
- Add: pipeline columns group by phase; list view parity; timeline rows; committed per currency on cards (no cross-currency totals); delete guard unchanged.

## Acceptance

- [ ] ≤ 7 queries.
- [ ] Conventions §10 checklist.
