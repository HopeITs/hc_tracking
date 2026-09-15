# Projects index · sign-off checklist

Tick every box before setting the tracker row to `review`. Paste the reviewer notes at the bottom.

## Behaviour
- [ ] Stage rail filters by status; re-activating the selected segment clears it.
- [ ] Rail counts respect search/city/program/owner (and overdue) but not status.
- [ ] Slippage line shows only when active projects are past `end_date`; "Show them" toggles `overdue`; Clear filters resets it.
- [ ] Sort works for all four options; invalid `?sort=` falls back to name.
- [ ] Table/cards toggle keeps filters, sort and page; invalid `?view=` falls back to table.
- [ ] Create and edit still work end to end; toasts appear; no delete anywhere.
- [ ] Modal live hints (duration, breakdown sum) update while typing and never block saving.

## Visual (01 §6 Operations Board)
- [ ] Timetable columns in the specified order and widths; status block is the row's only status indicator.
- [ ] The only large red area is the selected stage segment (+ primary button).
- [ ] No shadows on boards, no gradients, no coloured side stripes, no nested cards in cards view.
- [ ] `tabular-nums` on dates, %, beneficiaries, signal counts.
- [ ] Loading line + 60% rows on every filter/sort/page change.

## States (README §8)
- [ ] Empty DB · filtered-empty · no dates · no weighted milestones · missing owner · missing city · 120-char name · max-int beneficiaries · modal validation errors.

## Responsive (README §9)
- [ ] 1440 · 1024 (Signals + Beneficiaries folded into meta) · 390 (stacked, 44px targets, scrollable rail with selection in view).

## Themes & language
- [ ] Light-en · Dark-en · Light-ar · Dark-ar screenshots taken and checked.
- [ ] RTL: rail order, arrow icon flips, no uppercase in Arabic labels, logical classes only (`grep -nE "\b(ml|mr|pl|pr|left|right)-" resources/views/pages/projects/⚡index.blade.php` returns nothing).

## Accessibility
- [ ] Keyboard-only run through README §7 tab order.
- [ ] axe DevTools: 0 serious/critical issues.
- [ ] Progress bars and rail buttons announce names and values.

## Quality gates
- [ ] `./php.cmd artisan test --compact tests/Feature/ProjectDirectoryTest.php` green (12 existing + 11 new).
- [ ] `LocalizationTest`, `UiComponentsTest`, `NavigationTest` green.
- [ ] Full suite green.
- [ ] `./php.cmd vendor/bin/pint --dirty --format agent` clean.
- [ ] `npm run build` succeeds; no console errors on the page.
- [ ] Query log with 40 projects ≤ budget (README §6).
- [ ] Nothing committed; changed files listed in the review note.

## Review note
```
Changed files:
- resources/views/pages/projects/⚡index.blade.php
- lang/en/projects.php
- lang/ar/projects.php
- tests/Feature/ProjectDirectoryTest.php

Deviations from spec (with reason):
-

Screenshots:
-
```
