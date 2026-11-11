---
paths:
  - 'resources/views/pages/{milestones,tasks}/**'
---

# Milestonestasks

## Milestones/tasks calendar views mirror My Calendar's day/4-days/week/month switcher
Both milestones and tasks calendar views (`_calendar.blade.php`) now have the same "Today / Month ▾ / < > / heading" toolbar and day/4-days/week/month switching as `pages/calendar/⚡index.blade.php` (My Calendar), added 2026-09-23. Each index component got `viewMode` (`#[Url(as:'period', except:'month')]`) and `anchorDate` (`#[Url(except:'')]`) props plus `setViewMode()`/`previousPeriod()`/`nextPeriod()`/`periodLengthInDays()`/`activeAnchor()`/`periodDays()` methods, now shared with the calendar page's component through app/Livewire/Concerns/NavigatesCalendarPeriods.php. `calendarGrid()`/`calendar()` on each returns `weeks` (month view) XOR `days` (day/4-days/week view) — never both — so the Blade template branches on `$viewMode === 'month'` to pick which one to read.

Milestones' non-month views render the same per-day chip/quick-add markup as the month grid's day cell, in an "all day" row above an empty 24-hour visual grid (decorative only, matching My Calendar's all-day row treatment).

Tasks dropped that hour grid in the 2026-11-10 calendar redesign (user-approved): tasks have no time of day, so the empty 24 rows were dead space. Day / 4 days / Week now render one column per day (`.dk-tc-cols`, stacking as rows on a phone) holding the day-cell partial. Don't reintroduce the hour grid on Tasks unless tasks gain a time-of-day field.

Tasks' day-cell markup (task chips + inline quick-add composer with assignee/priority pickers) was extracted into `pages/tasks/_calendar-day-cell.blade.php` so the month grid and the day columns `@include` the same partial instead of duplicating it. It relies on `$this->taskDraft()`/`$this->userOptions` being reachable via `$this` inside an `@include`d partial — confirmed working in this codebase (see `_list-task-composer.blade.php`), because Volt binds the compiled Blade closure to the component instance and `@include` inherits that scope. Do NOT wrap this kind of `$this`-calling logic in a nested `function() use (...) {}` closure defined inside the parent template — closures auto-bind `$this` too in PHP, but it was avoided here in favor of the already-proven `@include` pattern.
