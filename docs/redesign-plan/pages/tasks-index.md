# P13 · Tasks index

**Route:** `tasks.index` · **Group:** E · Tasks (same owner as P14) · **Wave:** 1 · **Depends on:** 02 (`x-ui.kanban`)

## Files you own

- `resources/views/pages/tasks/⚡index.blade.php`
- `lang/*/tasks.php`
- `tests/Feature/TaskDirectoryTest.php`

## Current state

Filters: search (title/description/assignee), status, priority, assignee, milestone (incl. "no milestone"), reset; create/edit modal; delete confirm. Rows: title, milestone + project (or "no milestone"), proposal name (not linked), status badge, priority badge, `progress_percentage` as "NN%", `due_date`, assignee chip, edit/delete. 15/page.

Fields: `title, description, milestone_id, proposal_id, assigned_to, status (TaskStatus: todo, in progress, under review, done, blocked), priority (low, medium, high), start_date, due_date, completed_date, progress_percentage, created_by`.

## Target layout

1. **Header:** keep filters + "Add task"; `x-ui.view-toggle` (`#[Url] view`: `list` | `board`, default `list`); add "Mine" quick filter button (sets assignee to `auth()->id()`) and "Overdue" toggle (`#[Url] overdue`: not done and `due_date < today`).
2. **Summary slot:** `x-ui.status-strip` over `TaskStatus` (`wire-model="status"`; Blocked tone critical, Done good, others neutral) + "{n} overdue" critical count.
3. **List view** (`x-ui.data-table`):
   - Task: title link (`tasks.show`) + meta `milestone.name · milestone.project.name` (project linked) or "No milestone"; proposal name **linked** to `proposals.show`
   - Status `enum-badge`
   - Priority: `enum-badge` (existing tones)
   - Progress: `x-ui.progress-ring size="sm"` + "NN%" text
   - Due: `x-ui.schedule-status :planned="due_date" :actual="completed_date" :done="status === Done"`
   - Assignee chip; actions (keep existing method names)
4. **Board view:** `x-ui.kanban`, one column per `TaskStatus` in enum order, cards: title link, project meta, priority badge, progress bar, schedule status, assignee avatar. Filters apply. **Board is not paginated**: load max 50 cards per column ordered by `due_date` (nulls last), show "+{n} more, switch to list" when truncated (count via one grouped query).
   - **Drag-to-change-status is NOT in scope** until the project owner approves it. Build the board read-only.

## Tests (`TaskDirectoryTest.php`)

- Keep existing tests (including the markup assertions on this page).
- Add: board groups tasks by status; per-column cap + "more" count; overdue filter; "Mine" filter; proposal link present.

## Acceptance

- [ ] Board ≤ 7 queries.
- [ ] Conventions §10 checklist.
