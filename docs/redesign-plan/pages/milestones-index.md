# P11 · Milestones index

**Route:** `milestones.index` (index only, no show) · **Group:** D · Projects · **Wave:** 1 · **Depends on:** 02 (`x-ui.tree`, `x-ui.timeline`)

## Files you own

- `resources/views/pages/milestones/⚡index.blade.php`
- `resources/views/pages/milestones/_tree-node.blade.php` (delete when replaced)
- `lang/*/milestones.php`
- `tests/Feature/MilestoneDirectoryTest.php`

## Current state

Filters: project, status, responsible, reset. Grouped by project (panel per project: name, city, count), self-nesting tree via `_tree-node.blade.php` with padding indentation and an ASCII "└". Node: sequence + name, status badge, `planned_date`, `weight_percentage`, responsible chip, description, collapsible files (`<details>`), edit/delete (delete locked when node has children or tasks). Modal: name, description, project (locked on edit), parent, planned/actual date, status, sequence, weight %, responsible.

## Target layout

1. **Header:** keep filters + "Add milestone"; add `x-ui.view-toggle` (`#[Url] view`: `tree` | `roadmap`, default `tree`) and a new filter chip set "Overdue only" (`#[Url] overdue` bool: `status = Pending and planned_date < today`).
2. **Summary slot:** `x-ui.status-strip` over `MilestoneStatus` (`wire-model="status"`) + a separate critical count "{n} overdue" button toggling `overdue`.
3. **Per project panel:**
   - Header: project name link (`projects.show`), city, milestone count, and the project's weighted `x-ui.progress-bar` (Reached weight ÷ non-cancelled weight; compute in PHP from the loaded milestones, no extra query).
   - **Tree view:** `x-ui.tree` with:
     - label: `#{sequence} {name}`
     - badge: status
     - meta: `x-ui.schedule-status :planned="planned_date" :actual="actual_date" :done="status === Reached"`, weight as a tiny `progress-bar` (weight ÷ 100), responsible chip, files count toggle (keep `<details>` disclosure, restyled; keep existing file display behaviour)
     - actions: edit / delete / locked-delete tooltip. **Keep** `wire:click="openCreateModal"`, `openEditModal({id})`, `confirmDelete({id})` strings exactly.
     - Overdue nodes: critical schedule status; no coloured side stripes.
   - **Roadmap view:** `x-ui.timeline` of that project's milestones flattened in tree order (sublabel shows parent name for children), planned date as point marker (single date), actual as marker, tone by status/overdue.
4. Weight sanity note: when Σ non-cancelled weights of a project ≠ 100, show a `warn` inline note "Weights add up to {n}%" in the panel header (capacity rule).

## Tests (`MilestoneDirectoryTest.php`)

- Keep: `data-tree-depth="1"`, the three `wire:click` strings.
- Add: overdue filter shows only past-due pending milestones (`Carbon::setTestNow`); weight sum ≠ 100 note; roadmap view renders timeline rows.
- Remove the `_tree-node.blade.php` include only after tests pass.

## Acceptance

- [ ] No ASCII connector glyphs remain.
- [ ] ≤ 6 queries.
- [ ] Conventions §10 checklist.
