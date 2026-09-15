# P5 · Programs index

**Route:** `programs.index` · **Group:** B · Strategy · **Wave:** 1 · **Depends on:** 02

## Files you own

- `resources/views/pages/programs/⚡index.blade.php`
- `lang/*/programs.php`
- `tests/Feature/ProgramManagementTest.php`

## Current state

Header + total badge; search (program, strategy, manager name; debounce 300ms), strategy filter, manager filter, reset; `flux:table` desktop (Program, Strategy with years, Manager, Delivery "projects/plans", Updated, Actions) and card list on mobile; 15/page; edit modal assigns `strategy_id` / `manager_id` only (name is HR-sourced, read-only).

## Target layout

1. **Header:** unchanged filters; add `x-ui.view-toggle` (`#[Url] view`, values `table` | `cards`, default `table`) in `actions`.
2. **Summary slot:** one `type-meta` line: "{n} programs · {m} without a strategy · {k} without a manager", and the "without" counts are buttons that set a new `#[Url] gap` filter (`no_strategy` | `no_manager`). These are real data gaps the manager needs to close. Add the `gap` filter to the query (`whereNull('strategy_id')` / `whereNull('manager_id')`).
3. **Table view** (`x-ui.data-table`):
   - Program (name link) + strategy name/years as meta line
   - Manager (`employee-chip compact`; null → "Not assigned" in warn text)
   - Projects: number + `x-ui.progress-ring size="sm"` showing share of the program's projects with `status = active` (`withCount(['projects', 'projects as active_projects_count' => fn ($q) => $q->where('status', ProjectStatus::Active)])`)
   - Annual plans: number
   - Updated (relative, absolute in `title`)
   - Actions: edit (existing `openEditModal({id})` signature unchanged)
4. **Cards view:** responsive grid (`@3xl:grid-cols-2 @6xl:grid-cols-3`) of program sheets: name, strategy, manager chip, a two-number row (projects / annual plans) with labels (no slash), active-projects ring, edit button. Same data, same pagination.
5. Pagination unchanged (15).

## Behaviour

- View mode persists in URL; filters apply to both views; reset clears `gap` too.
- Edit modal behaviour and validation unchanged.

## Tests (`ProgramManagementTest.php`)

- Existing tests green.
- `view=cards` renders cards with the same programs.
- `gap=no_manager` shows only programs without a manager; summary counts correct.
- Active-projects count correct (1 active of 3).

## Acceptance

- [ ] Delivery column no longer uses "projects/plans" slash notation.
- [ ] ≤ 6 queries per render.
- [ ] Conventions §10 checklist.
