# Projects index · build spec

| | |
| --- | --- |
| **Route** | `projects.index` → `/projects` (`Route::livewire('projects', 'pages::projects.index')`) |
| **Page file** | `resources/views/pages/projects/⚡index.blade.php` |
| **Owner group** | D · Projects (same person as `projects-show`, `milestones-index`, `risks-index`) |
| **Wave** | 1 · starts after `02-shared-components` + `01-design-system` land (you may stub components against the contracts earlier) |
| **Mode** | Operate: officers find and update projects; the manager scans lifecycle, progress and slippage |
| **Effort** | ~2 days including tests |

Files in this folder:

| File | What it is |
| --- | --- |
| [`cockpit-plan.md`](cockpit-plan.md) | **START HERE.** The approved Portfolio Cockpit design; supersedes §5 and §6 below |
| `README.md` | This spec: what to build and how, in order |
| [`copy.md`](copy.md) | Every new/changed string, English + Arabic, with exact keys |
| [`tests.md`](tests.md) | Existing tests to keep + new tests to write (full Pest code) |
| [`checklist.md`](checklist.md) | Sign-off checklist; tick it before setting status to `review` |

---

## 1. Read first (in this order)

1. [`../../00-conventions.md`](../../00-conventions.md): all of it (strict mode, money, i18n, RTL, test hooks, no commits).
2. [`../../01-design-system.md`](../../01-design-system.md) **§6 The Operations Board**: §6.1 surfaces, §6.3 timetable rows, §6.4 stage rail, §6.5 tone map, §6.6 motion, §6.8 empty states, §6.9 kitsch guard.
3. [`../../02-shared-components.md`](../../02-shared-components.md): contracts for `page-header`, `stage-rail`, `data-table` (`timetable` variant), `status-block`, `progress-bar`, `schedule-status`, `view-toggle`, `employee-chip`, `empty-state`, `board-loading`.
4. Current source: `resources/views/pages/projects/⚡index.blade.php` (read all 510 lines before editing).

## 2. Files

**You own (edit freely):**
- `resources/views/pages/projects/⚡index.blade.php`
- `lang/en/projects.php`, `lang/ar/projects.php` (shared with your other D pages)
- `tests/Feature/ProjectDirectoryTest.php`

**Do not edit** (ask the owner): `resources/views/components/ui/**` (Foundation), `lang/*/common.php`, `lang/*/ui.php` (Foundation), `app/Models/Project.php` (no model changes in this redesign), `routes/web.php`.

## 3. Current state (verified from source, 2026-09-15)

- **Component class:** `#[Title('titles.projects')]`, `WithPagination`, `paginate(15)`, ordered `name, id`.
- **URL filters** (all `#[Url(except: '')]` strings): `search`, `city`, `program`, `owner`, `status`. Each `updatedX()` calls `resetPage()`. `clearFilters()` resets all five.
- **Search** matches project `name`, HR city `name_en`/`name_ar` (resolved to ids first, cross-connection), program `name`, owner via `HrEmployee::idsMatchingName()`.
- **Eager loads:** `city:id,name_en,name_ar`, `program:id,name`, `user:id,first_name_en,last_name_en,first_name_ar,last_name_ar` (owner is an `HrEmployee` via `user_id`). `withCount(['risks', 'milestones', 'planIndicators', 'proposals'])`.
- **Option lists:** `cityOptions()`, `programOptions()`, `userOptions()` (`HrEmployee::pickerOptions()`).
- **Modal:** `showProjectModal`, `editingProjectId`; form props `name, description, projectStatus, startDate, endDate, targetBeneficiaries, targetMen, targetWomen, targetBoys, targetGirls, cityId, programId, userId`; actions `openCreateModal()`, `openEditModal(int $projectId)`, `saveProject()`, `closeProjectModal()`; validation + custom messages as in source; toast `projects.project_created` / `projects.project_updated`.
- **No delete** (tested: `the project directory offers no delete action`).
- **Markup:** `x-ui.page-header` (filters: search, city, program, owner, status select; action "Add project" `data-test="create-project-button"`), one `x-ui.panel` with `divide-y` `<article>` rows (name link + status badge, city · program, dates, 2×3 grid of text counts, owner chip, edit icon), empty state (filtered vs not), pagination footer scrolling to `#project-directory-heading`. Modal max-w-4xl with identity, description, city/program/owner, dates, beneficiary fieldset, Cancel / Create-or-Save (`data-test="save-project-button"`).

**Problems this redesign fixes:** lifecycle status is a dropdown instead of a visible pipeline; no progress anywhere although milestones carry `weight_percentage`; schedule slippage is invisible (active projects past `end_date` look identical to healthy ones); six counts are plain text in a grid that nobody scans; no sorting; no way to browse visually.

## 4. Who uses it and for what

| User | Job on this page | What must be instant |
| --- | --- | --- |
| Projects & fundraising manager | "Where is my portfolio in its lifecycle? What is slipping?" | Lifecycle counts, past-end-date projects, low progress |
| Program/project officer | "Open my project, update its status or dates." | Find by name/owner, edit in one click |

## 5. Target design

### 5.1 First viewport (1440 × 900, 40 projects seeded)

```
┌ sidebar ┐┌───────────────────────────────────────────────────────────────────────────────┐
│         ││ Projects [40]                                     [▦ Table|▤ Cards] [Sort ▾] [+ Add project] │
│         ││ Track delivery ownership, lifecycle, schedule, and beneficiary commitments.   │
│         ││ [Search………………] [City ▾] [Program ▾] [Owner ▾]                                 │
│         ││ ┌ LIFECYCLE ───────────────────────────────────────────────────────────────┐ │
│         ││ │ DRAFT 6 │ SUBMITTED 4 │ APPROVED 5 │ ACTIVE 18 │ CLOSED 5 │ ARCHIVED 2   │ │  ← stage rail (filter)
│         ││ └──────────────────────────────────────────────────────────────────────────┘ │
│         ││ ⚠ 3 active projects are past their end date  [Show them]                       │
│         ││ ┌ board ─ 40 matching projects ──────────────────────────── [Clear filters] ┐ │
│         ││ │ SCHEDULED   PROJECT              PROGRESS       BENEFICIARIES SIGNALS  OWNER  SCHEDULE     STATUS  │ │
│         ││ │ 01 Mar 26   Aleppo Wells         ▓▓▓▓▓░░░ 62%   1,200         ⚑4 ◎2 ▤1 ⚠1  LH    Ends in 12 d [ACTIVE] ✎│ │
│         ││ │ → 31 Dec 26 Aleppo · Water Sec.  5 of 8 reached                                                     │ │
│         ││ │ …                                                                                               │ │
```

### 5.2 Layout, top to bottom

**A. Page header** (`x-ui.page-header`, keep `heading-id="project-directory-heading"`, `count` = filtered total, existing description key).
- `filters` slot, in this order: search input (existing), city select, program select, owner select. **Remove the status select**: the stage rail replaces it at every width (tests set `status` directly, so they keep working).
- `actions` slot, in this order: `x-ui.view-toggle` (table/cards), sort select, "Add project" button (keep `wire:click="openCreateModal"` and `data-test="create-project-button"`).

**B. Summary slot** (`page-header` `summary`):
1. `x-ui.stage-rail` **filter mode**: `label` = `projects.lifecycle`, `wire-model="status"`, `active` = `$status`, stages = `ProjectStatus::cases()` in enum order (draft, submitted, approved, active, closed, archived), `count` from `$this->statusCounts[$case->value] ?? 0`.
2. Slippage line, rendered **only when** `$this->pastEndDateCount > 0`: `status-warn` icon `exclamation-triangle` (mini) + `trans_choice('projects.count_active_past_end_date', n)` + a ghost button that toggles `overdue` (label `projects.show_them` when off, `projects.show_all_projects` when on, `aria-pressed`).

**C. Directory board** (`x-ui.panel`):
- Subtitle: existing `projects.count_matching_project` trans_choice.
- Toolbar: `x-ui.board-loading wire:loading.delay wire:target="search, city, program, owner, status, overdue, sort, gotoPage, nextPage, previousPage"` + "Clear filters" ghost button when `$filtersAreActive` (keep `wire:click="clearFilters"`).
- Body: **table view** or **cards view** (below). Rows container gets `wire:loading.class="opacity-60"` with the same `wire:target`.
- Footer: existing `flux:pagination` with `scroll-to="#project-directory-heading"`.

**D. Table view** (`x-ui.data-table variant="timetable" :caption="__('projects.project_directory')"`)

| # | Column header key | Cell content (exact source) | Width / align |
| --- | --- | --- | --- |
| 1 | `projects.col_scheduled` | two lines, `tabular-nums`: `start_date` then `→ end_date`, each `translatedFormat(__('formats.date'))`, em dash for null. Role `scheduled`. | 7.5rem |
| 2 | `common.projects` | name `<a href="{{ route('projects.show', $project) }}" wire:navigate>` (`type-heading`, hover underline) + meta line `city?->name ?? __('common.unknown_city')` · `program->name` | fluid, min 14rem |
| 3 | `projects.col_progress` | `x-ui.progress-bar :value="$project->progress" tone="neutral" :label="__('projects.milestone_progress_for', ['name' => $project->name])" show-value` + meta `projects.milestones_reached` (`:reached` = `reached_milestones_count`, `:total` = `milestones_count`). When `progress` is null: bar track only + meta `projects.no_weighted_milestones`. | 11rem |
| 4 | `projects.col_beneficiaries` | `number_format(target_beneficiaries)` or em dash (numeric, end-aligned) + meta `projects.breakdown_count` only when `targetDemographicTotal()` is not null **and** differs from the headline | 8rem, numeric |
| 5 | `projects.col_signals` | four icon+number chips, each with `aria-label` from `common.count_*` trans_choice: `flag` milestones_count · `chart-bar` plan_indicators_count · `document-text` proposals_count · `exclamation-triangle` risks_count; the risk chip is `status-critical` text **when `materialised_risks_count > 0`**, and its aria-label then uses `projects.count_risks_materialised`. Zero counts render in `secondary-light`. | 10rem |
| 6 | `projects.owner` | `x-ui.employee-chip :employee="$project->user" compact` | 11rem |
| 7 | `projects.col_schedule` | `x-ui.schedule-status :planned="$project->end_date" :actual="null" :done="in_array($project->status, [ProjectStatus::Closed, ProjectStatus::Archived], true)"`. Role `actual`. | 9rem |
| 8 | `common.status` | `x-ui.status-block :case="$project->status"` (tone map 01 §6.5). Role `status`. | 9rem |
| 9 | (visually hidden `common.actions`) | edit icon button, **unchanged**: `wire:click="openEditModal({{ $project->id }})"`, `aria-label="__('common.edit_name', ['name' => $project->name])"`, size-8 desktop / size-11 mobile | 3rem |

Row `wire:key="project-{{ $project->id }}"` (unchanged). Whole-row click is **not** added (the name link and edit button are the targets).

Below the `@3xl` container width the table stacks (component behaviour): each project becomes a block showing Project (name + meta) on top, Status block and Schedule on one line, then Progress, then Signals + Owner, then Scheduled dates, then the edit button at the block's inline-end top corner. Cell `label`s supply the stacked labels.

**E. Cards view** (`view === 'cards'`)
- Grid `grid gap-px bg-board-line @3xl/directory:grid-cols-2 @6xl/directory:grid-cols-3` inside the panel body (hairline gaps: the board is divided, **not** separate floating cards, and never nested in another card).
- Each cell (`bg-board p-4 flex flex-col gap-3`, `wire:key="project-card-{{ $project->id }}"`):
  1. Top line: `x-ui.status-block` (compact inline height 24px) + schedule-status text at inline-end.
  2. Name link (`type-heading`) + meta city · program.
  3. Progress bar with "reached of total" meta.
  4. Two-column mini facts: Scheduled (`start → end`) | Beneficiaries.
  5. Signal chips row + owner chip (compact) at inline-end.
  6. Edit button (same `wire:click` string) at the cell's top inline-end corner.
- Same pagination and filters.

**F. Modal** (keep behaviour, restructure layout)
- Keep `flux:modal wire:model="showProjectModal"`, `form wire:submit="saveProject"`, every field, validation, messages, toast, `data-test="save-project-button"`, Cancel `wire:click="closeProjectModal"`.
- Header: title (`projects.add_project` / `projects.edit_project`) + existing subheading.
- Body sections, each a `fieldset` with a `legend` (`type-label`) and hairline top border (no nested boxes):
  1. `projects.section_identity`: name (autofocus), status select, description textarea (rows 3).
  2. `projects.section_placement`: city, program, owner (3 columns ≥ `@2xl`).
  3. `projects.section_schedule`: start date, end date (2 columns). Under it, a live Alpine hint when both dates are filled: `projects.duration_days` computed client-side `x-data="{ get days() { … } }"` from `$wire.startDate` / `$wire.endDate` (hide when end < start; server validation still decides).
  4. `projects.beneficiary_targets` (existing legend + existing help text): 5 inputs. Below, live Alpine hint `projects.breakdown_adds_up` using `$wire.targetMen + targetWomen + targetBoys + targetGirls` and `$wire.targetBeneficiaries`. Informational only (the two are independent by design, see existing help text).
- Footer: unchanged buttons, sticky to the modal bottom on mobile.

### 5.3 Visual rules specific to this page

- The **only** large red area is the selected stage segment (and the primary "Add project" button).
- Status is expressed once per row, as the status block. Don't also render `x-ui.enum-badge` in the row.
- Numbers: `tabular-nums` in Scheduled, Progress %, Beneficiaries, Signals.
- Past-end-date projects: schedule-status shows critical text; **no** row background tint and no side stripe.

## 6. Livewire changes (exact)

Add properties:

```php
#[Url(except: 'table')]
public string $view = 'table';

#[Url(except: 'name')]
public string $sort = 'name';

#[Url(except: false)]
public bool $overdue = false;
```

Add `updatedView()` (no reset needed, but normalise: `if (! in_array($this->view, ['table', 'cards'], true)) { $this->view = 'table'; }`), `updatedSort()` (normalise against the whitelist, then `resetPage()`), `updatedOverdue()` (`resetPage()`). Extend `clearFilters()` to also set `$this->overdue = false` (**not** `view` or `sort`).

Extract the filter logic of `projects()` into one protected method so the list and the rail counts can't drift:

```php
/** @param Builder<Project> $query */
protected function applyFilters(Builder $query, bool $includeStatus = true): Builder
{
    // move the existing search/city/program/owner/status `when()` blocks here unchanged,
    // wrap the status block in `when($includeStatus && $this->status !== '', ...)`
    // and add:
    return $query->when($this->overdue, fn (Builder $query) => $query
        ->where('status', ProjectStatus::Active)
        ->whereDate('end_date', '<', today()));
}
```

`projects()` becomes: same `select`, same `with`, then

```php
->withCount([
    'risks', 'milestones', 'planIndicators', 'proposals',
    'risks as materialised_risks_count' => fn ($query) => $query->where('happened', true),
    'milestones as reached_milestones_count' => fn ($query) => $query->where('status', MilestoneStatus::Reached),
])
->withSum(['milestones as reached_weight' => fn ($query) => $query->where('status', MilestoneStatus::Reached)], 'weight_percentage')
->withSum(['milestones as planned_weight' => fn ($query) => $query->where('status', '!=', MilestoneStatus::Cancelled)], 'weight_percentage')
```

then `applyFilters($query)`, then ordering by `$this->sortColumn()`, then `paginate(15)`.

Ordering (`protected function applySort(Builder $query): Builder`):

| `sort` | Order |
| --- | --- |
| `name` (default, and any unknown value) | `name` asc, `id` asc |
| `start_date` | `start_date is null` asc (via `orderByRaw('start_date is null')`), `start_date` asc, `name` asc |
| `end_date` | `orderByRaw('end_date is null')`, `end_date` asc, `name` asc |
| `updated_at` | `updated_at` desc, `id` desc |

Progress value (no query): add to the Blade `@php` per row, or better a small closure in the component:

```php
public function progressFor(Project $project): ?float
{
    $planned = (float) ($project->planned_weight ?? 0);

    return $planned > 0 ? round(min(100, ((float) ($project->reached_weight ?? 0) / $planned) * 100), 1) : null;
}
```

New computed methods:

```php
/** @return array<string, int> */
#[Computed]
public function statusCounts(): array
{
    return $this->applyFilters(Project::query(), includeStatus: false)
        ->selectRaw('status, count(*) as aggregate')
        ->groupBy('status')
        ->pluck('aggregate', 'status')
        ->map(fn ($count) => (int) $count)
        ->all();
}

#[Computed]
public function pastEndDateCount(): int
{
    // Ignores the status and overdue filters on purpose: it's the portfolio-wide alert.
    return $this->applyFilters(Project::query(), includeStatus: false)
        ->where('status', ProjectStatus::Active)
        ->whereDate('end_date', '<', today())
        ->count();
}
```

⚠ `applyFilters(..., includeStatus: false)` still applies `overdue`. For `pastEndDateCount` that's harmless (same condition). For `statusCounts`, when `overdue` is on, only the Active segment has a count; this is correct and intended.

`$filtersAreActive` in the Blade `@php` gains `|| $overdue`.

Imports to add: `App\Enums\MilestoneStatus`, `Illuminate\Database\Eloquent\Builder`.

Query budget per render: projects page (1) + count (1) + eager loads (city is HR connection 1, program 1, user HR 1) + city search pre-query (0–1) + statusCounts (1) + pastEndDateCount (1) + option lists (3) = **≤ 10**.

## 7. Interactions & keyboard

| Action | Result |
| --- | --- |
| Click/Enter/Space on a stage segment | sets `status`; segment `aria-pressed="true"`, rows refresh, page resets to 1 |
| Activate the selected segment again | clears `status` |
| "Show them" | `overdue = true`, button text becomes "Show all projects", `aria-pressed="true"` |
| View toggle (arrow keys inside the radiogroup) | switches view, keeps filters/page |
| Sort select | re-orders, resets page |
| Edit (Enter on focused icon) | opens modal, focus lands on Project name |
| Escape in modal | closes (Flux), focus returns to the edit button |
| Pagination | scrolls to the heading (existing) |

Tab order: search → city → program → owner → view toggle → sort → Add project → stage rail segments → slippage button → Clear filters → rows (name link, edit) → pagination.

## 8. States

| State | What renders |
| --- | --- |
| No projects at all | Rail with all zero counts (future style), no slippage line, board with empty row: icon `rectangle-stack`, `common.no_projects_yet`, existing body, primary "Add project" action |
| Filters match nothing | Empty row: `projects.no_matching_projects`, `common.change_the_search_or_filters_and_try_again`, "Clear filters" action |
| Loading (filter/sort/page) | `board-loading` line + rows at 60% opacity; header and rail stay interactive |
| Project with no dates | Scheduled shows `—` / `→ —`; schedule-status "Unscheduled" (neutral) |
| No weighted milestones | Progress track only + "No weighted milestones" |
| Weights over 100 | Bar capped by formula at 100% (`min(100, …)`) |
| Owner deleted in HR (`user` null) | `employee-chip` renders `common.not_assigned` |
| City missing in HR | `common.unknown_city` (existing) |
| Very long name (120 chars) | wraps to 2 lines max (`line-clamp-2`) with full name in `title`; table doesn't overflow |
| Beneficiaries 4,294,967,295 | fits in 8rem at `tabular-nums` (number_format) |
| Validation errors in modal | inline under fields (existing Flux behaviour), modal stays open, first invalid field focused |
| Dark mode | board/ground tokens; status blocks keep white text contrast |
| Arabic (RTL) | rail reads right→left starting at Draft; `→` in dates becomes `←` via `rtl:-scale-x-100` on an icon (use `flux:icon.arrow-long-right` mini instead of the text arrow); stage labels not uppercase |

## 9. Responsive

| Container width | Behaviour |
| --- | --- |
| ≥ `@6xl` (≈ 72rem) | all 9 table columns; filters in one row (header component) |
| `@3xl`–`@6xl` | table hides Signals and Beneficiaries columns (`hidden @6xl/directory:table-cell`); their data moves into the Project meta line as "{beneficiaries} beneficiaries · ⚑4 ◎2 ▤1 ⚠1" |
| < `@3xl` | stacked blocks (5.2 D); rail horizontally scrollable with the selected segment scrolled into view; view toggle + sort + add button wrap to their own row; add button full width |
| 390px phone | touch targets ≥ 44px (edit button size-11, segments 56px tall), modal full-screen with sticky footer |

## 10. Accessibility

- Stage rail: `role="group"` with `aria-label`, buttons with `aria-pressed`, counts included in each button's accessible name ("Active, 18 projects").
- Progress bar: `role="progressbar"` with `aria-valuenow`, label names the project.
- Status block text is real text (not only colour). Schedule status includes words, not only colour.
- Table: `<caption class="sr-only">`, `<th scope="col">`; the actions header is visually hidden but present.
- Slippage line is a normal paragraph + button (not an alert role; it isn't new information on each render).
- Focus ring visible on every interactive element (01 §7).

## 11. Build order (do it in this sequence)

1. Read §1 docs and the current file end to end.
2. Add the new lang keys from [`copy.md`](copy.md) (en + ar) and run `./php.cmd artisan test --compact tests/Feature/LocalizationTest.php`.
3. Livewire class: add properties, `applyFilters`, `applySort`, the new aggregates, `statusCounts`, `pastEndDateCount`, `progressFor`, `updated*` hooks, extend `clearFilters`. Run the existing `ProjectDirectoryTest` and keep it green before touching markup.
4. Header: remove the status select; add view toggle and sort select; add the summary slot (rail + slippage line).
5. Board toolbar: loading line + Clear filters.
6. Table view with all 9 columns; then the `@3xl`–`@6xl` column hiding; then the stacked layout check at 390px.
7. Cards view.
8. Modal restructure + two Alpine hints.
9. Write the new tests from [`tests.md`](tests.md); run the file.
10. Browser pass: light/dark × en/ar × 1440/1024/390, all states in §8 (seed with `Project::factory()->count(40)` plus milestones with weights in tinker **on a local DB only**, or via the existing seeder).
11. Pint, full test suite, `npm run build`, tick [`checklist.md`](checklist.md), set tracker status to `review`. **Don't commit.**

## 12. Out of scope (don't do these)

- No delete action (tested absence).
- No bulk actions, no export, no inline status editing on rows.
- No new model methods/accessors on `Project`; progress stays a page-level computation.
- No figure housing on this page (reserved for dashboard, executive summary, detail headers).
- Don't change the search semantics or the modal's validation rules/messages.
