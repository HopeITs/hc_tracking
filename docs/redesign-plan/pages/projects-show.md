# P10 · Project detail

**Route:** `projects.show` · **Group:** D · Projects · **Wave:** 1 · **Depends on:** 02

## Files you own

- `resources/views/pages/projects/⚡show.blade.php`
- `lang/*/projects.php` (shared with P9)
- `tests/Feature/ProjectDetailTest.php`, `tests/Feature/ProjectProposalTest.php`

## Current state

Detail header (breadcrumbs, name, status, city, program → strategy, owner, updated); 4 stat tiles (start, end, headline target, demographic total); description panel + beneficiary breakdown (men/women/boys/girls 2×2); relation tabs: Milestones (tree, status badge), Risks (materialised/watching, impact, mitigation), Targets (statement, source badge, period, value), Proposals (attach/detach select + link, unlink), Files (media: type/collection/date).

## Target layout

1. **Detail header:** keep; `status` prop shows project status; `summary` slot:
   - `x-ui.progress-ring size="lg"` milestone progress (same weighted formula as P9) + caption "{reached} of {total} milestones reached".
   - `x-ui.schedule-status :planned="end_date"` + "Day {elapsed} of {duration}" when active.
2. **Overview grid** (`@5xl:grid-cols-3`):
   - Description panel (max 70ch).
   - **Beneficiaries** panel: headline figure `target_beneficiaries`; horizontal stacked bar of men / women / boys / girls (`x-ui.chart type="bar"` stacked horizontal, or four `progress-bar`s of each ÷ demographic total; choose the chart), totals with labels. Null breakdown → "Breakdown not recorded".
   - **Funding** panel: for each linked proposal's agreements, per currency `x-ui.capacity-bar` committed vs received. Eager-load `proposals.donorProposals.transfers` (select only needed columns) and aggregate in PHP per currency. Link each proposal.
3. **Tabs** (icons: `flag`, `exclamation-triangle`, `chart-bar`, `document-text`, `paper-clip`):
   - **Milestones:** `x-ui.view-toggle` within the tab (`#[Url] milestoneView`: `roadmap` | `tree`, default `roadmap`).
     - Roadmap: `x-ui.timeline` rows ordered by `sequence`: label name, sublabel responsible user, start = previous milestone's planned date or project `start_date` (document this in a code comment-free way: compute in PHP), end = `planned_date`, actual = `actual_date`, tone by status (Reached good, Missed critical, Cancelled neutral, Pending: warn if overdue else neutral).
     - Tree: `x-ui.tree` (keep `data-tree-depth`) with status badge, weight %, `schedule-status`.
   - **Risks:** cards in a 2-column grid: name, state chip (materialised = critical filled with `exclamation-triangle`; watching = warn outline with `eye`), impact text, mitigation text (label "Mitigation"). Materialised first.
   - **Targets:** `x-ui.data-table`: indicator statement, source badge (plan year / proposal), period, target, achieved bar (latest report value, same subquery pattern as P8).
   - **Proposals:** keep attach/detach behaviour and method names; rows show status badge, phase, per-currency committed money.
   - **Files:** project media restyled as a list with type chip, collection label, uploaded date, uploader. **Keep the current link/no-link behaviour exactly as it is today** (media has `disk`/`path`; don't add or remove downloads in this redesign).

## Tests

- `ProjectDetailTest`: keep existing (`data-tree-depth="1"` in tree view: test must request `?milestoneView=tree` or the test default; update the test and note it); add weighted progress, roadmap row order by sequence, funding panel per currency.
- `ProjectProposalTest`: attach/detach still work.

## Acceptance

- [ ] ≤ 12 queries on any tab.
- [ ] Conventions §10 checklist.
