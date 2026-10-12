---
paths:
  - 'resources/views/pages/tasks/**'
  - resources/views/pages/tasks/_list-group.blade.php
---

# Tasks

## Tasks list/board/calendar redesign, subtasks, sortable columns (reapplied 2026-09-18)
This whole feature set was rebuilt from scratch on 2026-09-18 after an accidental `git discard` wiped the original uncommitted work. Blocked was already removed from TaskStatus by that point (committed separately), so this version has 4 statuses everywhere (board has 4 columns, no unblock feature exists).

Architecture: single-page multi-view (list/board/calendar) mirroring milestones' pattern — one `filteredTasksQuery()`, a paginated `tasks()` computed for list, an unpaginated `boardTasks()` (capped 500) shared by board/calendar/stats. `taskStats()` is a standalone aggregate `select(DB::raw(...))` query, decoupled from `boardTasks()` — never let it depend on loading every task again.

New reusable components, scoped to task views only: `x-ui.drawer` (slide-over, `x-modelable` + `wire:model`, not `@entangle`), `x-ui.status-pill` (filled TaskStatus chip — 4 arms only now), `x-ui.group-header` (collapsible, localStorage-persisted). Drag-and-drop is Sortable.js (`resources/js/task-board.js`, lazy-imported), with a module-level `pendingTaskIds` Set guarding against double-drops and an accessible `<select>` fallback per board card (matches milestones' precedent).

Sorting: `sortField`/`sortDirection` (`#[Url(as:'sort')]`/`#[Url(as:'dir')]`), `setSort()` toggles direction on repeat clicks. Assignee sort resolves employee names via a small extra query (HrEmployee is on the separate read-only `hr` connection, no cross-DB join) then orders by a `CASE WHEN` id sequence — inherently dynamic raw SQL; don't try to force it into a PHPStan literal-string, `resources/views/**` isn't in `phpstan.neon`'s `paths` anyway.

Subtasks: `tasks.parent_task_id` (self-FK, `restrictOnDelete`), one level deep (enforced in `⚡show.blade.php` by only rendering the panel when `parentTask === null`, not in the schema). `addSubtask()` lives in `app/Livewire/Concerns/Tasks/ManagesSubtasks.php`, used by the SHOW page's Livewire component (separate instance from the index page) and inherits proposal/milestone/assignee from the parent so it needs only a title. A task with subtasks can't be deleted (DB `restrictOnDelete` + an app-level transactional guard in `deleteTask()` that toasts instead of throwing).

`userOptions`/`proposalOptions`/`milestoneOptions` are `#[Computed(persist: true, seconds: 300)]` — per-component-instance cache (safe, no cross-user leak, unlike `cache: true` which is keyed by component class and shared globally) — avoids re-querying these on every single quick action.

Task name truncation needs `table-fixed` on the `<table>` in `_list-group.blade.php` — without it `truncate` is a no-op in a table cell and a long title stretches the whole table.

If Blocked (or any status) is ever reintroduced, every `match(TaskStatus ...)` without a `default` arm across this feature (status-pill, `_board.blade.php`'s `$columnTone`, `orderByEnumSequence`'s status list, `enum-badge.blade.php`) needs the new arm added — they're deliberately exhaustive so a forgotten one is a compile-time/runtime error, not a silent gap.

## Assignee cell is an inline multi-select popover, not a drawer shortcut
The desktop list row's assignee cell used to just open the edit drawer on click — user feedback was that this reads as "no click action" since every other column (due date, priority, status) edits inline. It's now a real inline multi-select: click the avatar stack to open a checkbox-style popover of every `userOptions()` employee, click any row to call `toggleAssignee($taskId, $employeeId)` (in `app/Livewire/Concerns/Tasks/ManagesTaskDrawer.php`, next to `quickSetPriority`/`quickSetStatus`), which adds the employee if absent or removes them if present — refusing removal (toast, no-op) when it would leave zero assignees, matching `saveTask()`'s own `min:1` rule.

This popover is hand-rolled Alpine, NOT `flux:dropdown` — same reasoning as `x-ui.header-filter-multiselect` (see components-ui.md): a Flux menu item closes the popover on the first click, which is wrong when picking several values in a row. It also uses `<template x-teleport="body">` with `position: fixed` coordinates computed from `getBoundingClientRect()` on click, because the list panel wrapper (`_list.blade.php`) has `overflow-hidden` for its rounded corners — an in-flow `absolute` popover would get clipped for rows near the bottom of the table. `x-teleport` is Alpine core (no plugin needed) and survives Livewire's morph correctly since Livewire diffs the `<template>` at its original server-rendered position, not the teleported runtime location.

Known limitation: the popover's `left` position isn't RTL-aware (always anchors to the visual left edge of the trigger, not the reading-direction start) — acceptable today since the panel is narrow (`w-60`) and rarely reaches the viewport edge, but revisit if Arabic-locale users report it running off-screen.
