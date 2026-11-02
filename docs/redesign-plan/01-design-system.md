# 01 · Design system (tokens, type, motion)

**Owner group:** Foundation · **Wave:** 0 (first) · **Blocks:** everything

## Files you own

- `resources/css/app.css`
- `.ai/rules/css.md` (update through Boost `record-rule`, not by hand)
- `lang/en/ui.php`, `lang/ar/ui.php` (shared component strings)

## 1. Brand anchor (unchanged)

| Token | Value | Role |
| --- | --- | --- |
| `--color-primary` | `#be1f24` | action, current, critical signal |
| `--color-secondary-dark` | `#58595b` | ink, sidebar ground |
| `--color-secondary-medium` | `#6d6e71` | secondary ink, dark surfaces |
| `--color-secondary-light` | `#939598` | borders, muted ink |
| `--color-secondary-soft` | `#dcddde` | hairlines, fills |
| font | Somar Sans 300–800 | both languages |

## 2. New tokens (approved extension)

Add to `@theme` in `app.css`. **Status and data-viz only**, never for brand, buttons or navigation.

```css
--color-status-good: #1d7a4c;        /* on track, received, reached, done */
--color-status-good-soft: #e6f2ec;
--color-status-warn: #9a6412;        /* at risk, pending-late, amber RAG (white text passes 4.5:1) */
--color-status-warn-soft: #f7eedd;
--color-status-critical: var(--color-primary); /* red RAG, overdue, failed, materialised */
--color-status-critical-soft: #f8e4e4;
--color-funding: #2b5f6b;            /* money series in charts, capacity fill */
--color-funding-soft: #e3eef0;
--color-surface: #ffffff;
--color-surface-sunken: #f6f6f6;     /* page ground behind panels */
```

Dark values (in the `.dark` layer): `status-good #4fbf8a`, `good-soft #16281f`, `status-warn #e0a743`, `warn-soft #2b2212`, `critical-soft #3a1a1b`, `funding #6fb0bd`, `funding-soft #142326`, `surface #58595b`, `surface-sunken #4a4b4d`.

Chart series order (categorical, max 6): `secondary-dark`, `primary`, `funding`, `status-warn`, `secondary-light`, `status-good`. Expose these as CSS variables; `03-charts.md` reads them at runtime.

Semantic tone names used by every component prop: `neutral | good | warn | critical | funding`.

After editing, record the palette change with `record-rule` (glob `resources/css/app.css`, title "Status and funding tokens are approved for status/data-viz only") so `.ai/rules/css.md` no longer says "no new colour".

## 3. Type scale

Somar Sans only. Define utility classes in `@layer components` and use them in components (pages use components or these classes, never ad-hoc sizes):

| Class | Size / line-height / weight | Use |
| --- | --- | --- |
| `.type-figure-xl` | 3rem / 1 / 800, `tabular-nums`, tracking -0.02em | dashboard hero figures |
| `.type-figure` | 1.75rem / 1.1 / 700, `tabular-nums` | stat tiles |
| `.type-title` | 1.5rem / 1.2 / 700 | page title |
| `.type-heading` | 1rem / 1.35 / 600 | panel headings |
| `.type-body` | 0.875rem / 1.55 / 400 | body |
| `.type-meta` | 0.75rem / 1.4 / 500 | captions, meta |
| `.type-label` | 0.6875rem / 1.3 / 600, uppercase (Latin only: `:lang(ar)` removes uppercase and letter-spacing), tracking 0.08em | column heads, group labels |

All numeric cells: `tabular-nums`. Body measure max 70ch for narrative text (reports, descriptions).

## 4. Space, radius, elevation

- Keep the existing `--spacing-ui-*` scale and aliases. Page rhythm: section gap `--spacing-ui-xl`, panel padding `--spacing-card`.
- Radius: `--radius-control: 0.375rem`, `--radius-panel: 0.625rem`. Nothing rounder except avatars.
- Elevation: panels are flat with a 1px `secondary-soft` border. Shadows only on overlays (modals, dropdowns, command palette): `0 2px 8px rgb(0 0 0 / .08), 0 16px 40px rgb(0 0 0 / .16)`.
- Page ground: `surface-sunken` behind panels (light), so panels read as sheets. Update `layouts/app.blade.php` `flux:main` background with the shell owner (see `04-app-shell.md`).

## 5. Motion

- Durations: `--duration-fast: 120ms`, `--duration-base: 200ms`, `--duration-slow: 360ms`. Easing: `--ease-out: cubic-bezier(0.16, 1, 0.3, 1)`.
- Allowed: bar/ring value transitions on update (width/stroke-dashoffset), segment hover, panel content cross-fade on tab switch, command palette open.
- Every animation starts from a visible state and has a `motion-reduce` fallback of no motion.

## 6. Visual world: **The Operations Board** (decided 2026-09-15)

**Thesis.** Every record in HC Tracking is a line on one operations board: *what was scheduled, what actually happened, and its status*, readable from across the room. The portfolio is a board, not a pile of cards. It refuses the category default (identical KPI cards + rounded tables + badges everywhere).

**Lineage.** Station departures boards (scheduled / actual / status), fused with a lifecycle **stage rail** (from the "Darkroom" direction) and a disciplined structural grid with large precise figures (from "Construction Grid").

**Why it fits this product.** Nearly every entity carries planned vs actual: milestones (`planned_date` / `actual_date`), tasks (`due_date` / `completed_date`), transfers (`scheduled_date` / `receive_date`), reports (`planned_date` / `submit_date`), proposals (`planned_date_*` / `actual_date_*`), projects (`start_date`, `end_date`). Workflows are ordered lifecycles (`ProjectStatus`, `ProposalStatus`/`ProposalPhase`, `ReportStatus`, `TaskStatus`). The board grammar makes all of them read the same way: easy for officers, glanceable for the manager.

### 6.1 Surfaces

| Token | Light | Dark | Use |
| --- | --- | --- | --- |
| `--color-ground` | `#f3f3f4` | `#232425` | page background behind boards |
| `--color-board` | `#ffffff` | `#2e2f31` | every panel ("board") |
| `--color-board-line` | `#dcddde` | `#454649` | board border + row hairlines |
| `--color-board-head` | `#f8f8f9` | `#343538` | column-header strip, board header strip |
| `--color-housing` | `#2b2c2e` | `#1c1d1e` | the figure housing band (always dark) |
| `--color-flap` | `#3b3c3f` | `#2e2f31` | flap cells inside the housing |
| `--color-flap-split` | `#1f2022` | `#131414` | the 1px split line across a flap |
| `--color-flap-label` | `#b9babc` | `#a3a4a6` | labels inside the housing |

These replace `surface` / `surface-sunken` from §2 (keep §2's status and funding tokens). Boards: radius `6px`, 1px `board-line`, **no shadow**. Controls: radius `4px`.

### 6.2 Signature element 1: Figure housing with flap cells

- One dark horizontal band (`x-ui.figure-housing`) holding 2–6 `x-ui.flap-figure`s.
- Each figure: characters rendered in `flap` cells (one cell per character group: digits grouped as displayed, e.g. `1,240` = one cell), `type-figure-xl` white numerals, a 1px `flap-split` line across the vertical middle, a `type-label` caption below in `flap-label`. A figure's status tone colours only a 3px dot before the caption (good/warn/critical), never the numerals.
- **Where allowed, and only there:** dashboard figures band and one key figure in a detail page header (for example the project's weighted progress %). **Max one housing per page.** Never on body text, tables or buttons (the kitsch guard).
- Money in a flap figure: the currency code sits outside the cells as `type-meta`; one figure per currency.

### 6.3 Signature element 2: Timetable rows

Primary lists of dated records render as a timetable (`x-ui.data-table variant="timetable"`):

```
SCHEDULED | ITEM (title + context meta) | … page columns … | ACTUAL | STATUS
```

- **Scheduled** column first (`7.5rem`, `tabular-nums`, `secondary-medium`); **Actual** column before status (em dash when none; late actual in `status-warn` text).
- **Status** column last, fixed `9rem`: an `x-ui.status-block`, a flat colour block filling the cell (inset 6px block, radius 3px), label weight 700 12px white (neutral tone: `secondary-soft` fill, `secondary-dark` text). Latin labels uppercase with 0.06em tracking; Arabic never uppercase.
- Optional row-actions column (edit/delete icon buttons, 3rem, visually hidden header) may follow Status; nothing else may.
- Column header strip: `board-head` background, `type-label`. Row min-height 52px, hairline dividers, hover `ground` tint, no zebra.
- Undated lists (donors, cities, accounts, results) use `x-ui.data-table` default variant: same hairlines and header strip, no Scheduled/Actual columns.

### 6.4 Signature element 3: Stage rail

- Ordered lifecycles render as `x-ui.stage-rail`: equal-height (56px) segments separated by 2px gaps, each with `type-label` name + count (`1.25rem`, 700, tabular).
- States: **passed** → `secondary-dark` fill, white text; **current / selected** → `primary` fill, white text (the *only* large red area on a page); **future** → `board` fill, 1px `board-line`, `secondary-dark` text.
- On index pages the rail is a filter (selected segment = active filter; others "future" style; click selected again to clear). On detail pages it shows a record's position (passed / current / future).
- Distributions that are **not** ordered (for example donors by type, RAG) use `x-ui.status-strip` instead.
- Narrow widths: horizontal scroll with scroll-snap; the selected/current segment is scrolled into view on load.

### 6.5 Status tone map (single source of truth, implemented in `x-ui.status-block`)

| Enum | good | warn | critical | neutral | funding |
| --- | --- | --- | --- | --- | --- |
| `ProjectStatus` | active | submitted, approved | — | draft, closed, archived | — |
| `MilestoneStatus` | reached | — | missed | pending, cancelled | — |
| `TaskStatus` | done | under review | blocked | todo, in progress | — |
| `ProposalStatus` | approved, active, completed | submitted, final reports | rejected | concept note, proposal draft | — |
| `ReportStatus` | approved | reviewed | — | draft | — |
| `ArStatus` | received | pending | failed | — | — |
| `RagStatus` | green | amber | red | — | — |
| `TaskPriority` | — | medium | high | low | — |

Derived states: *overdue* → critical; *due within 7 days* → warn; *late but done* → warn; *materialised risk* → critical; *watching risk* → warn.

### 6.6 Motion: one grammar, the flap

- **Figure change:** when a flap figure's value changes (Livewire re-render with a new `wire:key` per value), each cell rolls: new characters enter `translateY(-100%) → 0`, 360ms, `--ease-out`, 25ms stagger per cell.
- **Status change:** a status block whose tone changes flips in: `rotateX(-90deg) → 0`, 200ms, `transform-origin: top`.
- **Board refresh (loading):** the updating board's rows go to 60% opacity and a 2px `primary` line runs along the bottom edge of the board header (`scaleX` indeterminate, 900ms loop). No spinners in boards.
- Nothing else animates on entry. `prefers-reduced-motion`: all of the above disabled (instant swap, loading line static).

### 6.7 Charts in this world

Flat marks, hairline `board-line` grid, no gradients, no shadows, area fill 12%, bar radius 2px, donut hole 72% with the total in the centre as a small flap-style figure (plain text, not housing). Series colours from §2 order. Charts always sit inside a board with a board header.

### 6.8 Empty and edge states

- Empty board: board header + one dashed-outline row at timetable height containing the empty-state icon, heading, body and action (`x-ui.empty-state` renders this when inside `data-table`).
- Filtered-empty: same row, copy says what to change, action "Clear filters".
- Error (validation) stays in modals; boards never show raw errors.

### 6.9 Kitsch guard (review checklist)

- [ ] Flap cells only inside the one figure housing.
- [ ] No mechanical/dot-matrix fonts; Somar Sans everywhere.
- [ ] Red used only for: primary actions, the current/selected stage segment, critical status, the sidebar current item.
- [ ] No shadows on boards; no gradients anywhere.

## 7. Browser surfaces

Theme these in `app.css`: `::selection` (primary at 20%), `caret-color: var(--color-primary)`, thin scrollbars (`scrollbar-color: secondary-light transparent`), `:focus-visible` outline `2px solid primary` offset 2px, link `text-underline-offset: 3px`.

## Acceptance

- [ ] Tokens present in light + dark; contrast checked (text ≥ 4.5:1, status chips with white text ≥ 4.5:1).
- [ ] Type utility classes exist and are used by the shared components.
- [ ] `.ai/rules/css.md` updated via `record-rule`.
- [ ] Existing `.admin-directory` overrides still work (removed only once `pages/admin-*.md` migrate to `x-ui.data-table`).
- [ ] `npm run build` passes; `UiComponentsTest` green.
