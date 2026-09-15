# P3 · Strategies index

**Route:** `strategies.index` · **Group:** B · Strategy (same owner as P4–P6) · **Wave:** 1 · **Depends on:** 02

## Files you own

- `resources/views/pages/strategies/⚡index.blade.php`
- `lang/*/strategies.php`
- `tests/Feature/StrategyPortfolioTest.php`

## Current state

Header with total badge + "current planning year" pill; four stat tiles (Strategic Periods, Active Now, First Period, Planning Horizon); 2-column card grid of strategies (name, start–end dot-line-dot, status upcoming/active/completed computed from current year), sorted active → upcoming → completed. No search, no program counts. Fields: `strategies.name, start_year, end_year`.

## Target layout

1. **Header:** title + count, description, **search** input in `filters` (`#[Url] search`, debounce 300ms, matches `name`).
2. **Summary slot:** `x-ui.status-strip` with segments Active / Upcoming / Completed (counts from the computed status; `wire-model="status"`, `#[Url] status`) replacing the four stat tiles. Keep "Planning horizon: {first}–{last}" as a single `type-meta` line beside the strip.
3. **Strategy timeline** (main visual): one panel containing `x-ui.timeline`
   - `from` = 1 Jan of min `start_year`, `to` = 31 Dec of max `end_year` (of the filtered set).
   - One row per strategy: label = name, sublabel = "{n} programs" (`withCount('programs')`), start = 1 Jan start_year, end = 31 Dec end_year, tone: active → good, upcoming → neutral, completed → secondary (neutral), href → `strategies.show`.
   - Today line shows the current planning position.
4. **Below the timeline:** compact list (not cards) of the same strategies with name link, years, duration ("{n} years", trans_choice), status `enum`-style chip (component: a neutral/good chip; status isn't an enum, so use `x-ui.rag-chip`-like styling via a small inline status chip from `ui.php` keys `status_active/upcoming/completed`), programs count. Under `@2xl` the timeline collapses (component behaviour) and this list carries the page.

## Behaviour

- Filters (search, status strip) apply to both timeline and list.
- Keep default ordering active → upcoming → completed, then `start_year`.

## States

- No strategies: empty state (existing copy).
- Filter yields nothing: `empty-state filtered` with "Clear filters" (`wire:click="$set('search', ''); $set('status', null)"` via a `resetFilters` method).

## Tests (`StrategyPortfolioTest.php`)

- Existing tests updated only where tile markup was asserted (note why).
- Search narrows results; status strip filter narrows results; strip counts correct.
- Timeline rows link to `strategies.show`; programs count shown.

## Acceptance

- [ ] ≤ 4 queries; `withCount('programs')` used.
- [ ] Conventions §10 checklist.
