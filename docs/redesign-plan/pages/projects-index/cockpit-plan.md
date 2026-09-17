# Projects index · Portfolio Cockpit plan

**Status:** approved design, not started. **Supersedes** `README.md` §5 (target design) and §6 (Livewire changes); everything else in `README.md` still stands, and `copy.md` / `tests.md` / `checklist.md` remain the companion files.

| | |
| --- | --- |
| **Route** | `projects.index` → `/projects` |
| **Page file** | `resources/views/pages/projects/⚡index.blade.php` (519 lines today) |
| **Mode** | Operate — officers find and edit a project; the manager scans funding, delivery and slippage |
| **Approved by the user** | Portfolio cockpit + switchable views · full funding lens incl. portfolio totals · clickable triage KPIs · Board (status-grouped, rich cards) as default · index page only |

---

## 0. Read these first, in this order

1. `docs/redesign-plan/00-conventions.md` — strict mode, money, i18n, RTL, test hooks.
2. `docs/redesign-plan/01-design-system.md` §6 (The Operations Board): §6.1 surfaces, §6.5 tone map, §6.6 motion, §6.8 empty states, §6.9 kitsch guard.
3. `.ai/rules/pages.md`, `.ai/rules/ui.md`, `.ai/rules/components-ui.md`, `.ai/rules/css.md`, `.ai/rules/views-enums.md`, `.ai/rules/models.md`. These are **binding**, and three of them constrain this page directly — see §2.
4. This plan, then `README.md` §7–§10 (interactions, states, responsive, accessibility) which still apply and are not repeated here.
5. The current page file, end to end, before editing.

---

## 1. Current state (verified 2026-09-17)

- `#[Title('titles.projects')]`, `WithPagination`, `paginate(15)`, ordered `name, id`.
- URL filters, all `#[Url(except: '')]` strings: `search`, `city`, `program`, `strategy`, `owner`, `status`. Each has an `updatedX()` that calls `resetPage()`. `clearFilters()` resets all six.
- Search matches project `name`, HR city `name_en`/`name_ar` (ids resolved first, cross-connection), program `name`, and owner via `HrEmployee::idsMatchingName()`.
- Eager loads `city:id,name_en,name_ar`, `program:id,name`, `user:id,first_name_en,last_name_en,first_name_ar,last_name_ar`; `withCount(['risks','milestones','planIndicators','proposals'])`.
- Option lists: `cityOptions()`, `programOptions()`, `userOptions()` (`HrEmployee::pickerOptions()`).
- Modal: `showProjectModal`, `editingProjectId`, 13 form props, `openCreateModal()` / `openEditModal(int)` / `saveProject()` / `closeProjectModal()`, validation with custom messages, toasts `projects.project_created` / `projects.project_updated`. **No delete** — its absence is asserted by a test.
- Markup: `x-ui.page-header` (search + city + program + owner + status filters, "Add project" action), one `x-ui.panel` of `divide-y` `<article>` rows, empty state, pagination footer scrolling to `#project-directory-heading`.

**What is wrong with it:** lifecycle is a dropdown rather than a visible pipeline; no progress anywhere although milestones carry `weight_percentage`; slippage is invisible (an active project past `end_date` looks identical to a healthy one); six relation counts are plain text nobody scans; no sorting; and **money — the manager's primary lens — is absent entirely**.

---

## 2. Binding constraints (do not rediscover these the hard way)

1. **`x-ui.page-header` is mandatory** on every index header (`.ai/rules/pages.md`). Do not hand-roll a header. A branch that did was reverted during the `ad28bcb` merge.
2. **Header dropdowns must be `x-ui.header-filter-select`** (`.ai/rules/ui.md`). Never `x-ui.select`, `flux:select` or a native select in the filters slot.
3. **Actions stay out of the filters grid** (`.ai/rules/ui.md`) — they belong in the `actions` slot.
4. **flux-lite ships no menu JS** (`.ai/rules/components-ui.md`): `ui-menu`, `ui-menu-item`, `ui-menu-checkbox` are not registered, and `flux:select variant="listbox" multiple` does not exist. `<flux:menu.checkbox>` renders inert. Multi-select must be hand-rolled with Alpine `x-show`, as `x-ui.header-filter-multiselect` already does.
5. **Bind `flux:modal` with `wire:model`, never `:open`** (`.ai/rules/pages.md`). `:open` never opens from a Livewire round trip.
6. **No raw hex outside `app.css`** (`.ai/rules/css.md`). Use the approved tokens only, and `--color-status-*` / `--color-funding` / `--color-board*` are for status, data-viz and board surfaces only — never brand, buttons or nav.
7. **Every visible string** goes through `lang/en/*` **and** `lang/ar/*` (`.ai/rules/views-enums.md`). `LocalizationTest` fails on a missing key, a lost placeholder or a missing enum label. Never name a group file after a word Flux translates bare.
8. **HR is read-only** (`.ai/rules/models.md`). `projects.city_id` / `projects.user_id` hold HR ids with no FK; resolve names to ids rather than `whereHas` across connections.
9. **No model changes.** Progress and funding stay page-level computations (`README.md` §12).

---

## 3. Data facts, verified against the live schema

### 3.1 Relations available on `Project`

| Relation | Shape | Notes |
| --- | --- | --- |
| `city()` | `belongsTo HrCity` | HR connection, read-only |
| `program()` | `belongsTo Program` | `program.strategy_id` gives the strategy |
| `user()` | `belongsTo HrEmployee, 'user_id'` | column name is `user_id`, contents are an HR **employee** id |
| `proposals()` | `belongsToMany Proposal` | pivot `project_proposal`, unique `(project_id, proposal_id)` |
| `risks()` | `hasMany Risk` | `risks.happened` boolean distinguishes materialised from watching |
| `milestones()` | `hasMany Milestone` | `weight_percentage decimal(5,2)`, `planned_date`, `actual_date`, `status`, self-nesting via `parent_milestone_id` |
| `planIndicators()` | `hasMany PlanIndicator` | targets attributed to the project |
| `indicators()` | `hasMany Indicator` | `indicators.project_id` is nullable; most indicators are programme level |
| `media()` | `morphMany Media` | file records only |

`targetDemographicTotal()` already exists on the model and returns `null` when none of the four demographic targets is set. It is deliberately allowed to disagree with `target_beneficiaries`.

### 3.2 Enums

- `ProjectStatus`: `draft, submitted, approved, active, closed, archived` (in that order) + `isOpen()`, `next()`.
- `MilestoneStatus`: `pending, reached, missed, cancelled`.
- `RagStatus`: `red, amber, green`, **derived, no column**, via `RagStatus::rate(float $percentAchieved, float $percentElapsed)`. Amber below 0.9 of elapsed, Red below 0.7, and a window that has not started rates Green. **Reuse this — do not invent a second traffic-light rule.**
- `Currency`: `USD, EUR, SYP`.
- `Period`: `monthly, quarterly, semi-annual, annual`.

### 3.3 Funding — and the trap that will bite you

Money lives two hops away:

```
projects → project_proposal → proposals → donor_proposal → donors
                                          (budget decimal(14,2), currency varchar(10), project_num)
```

**Verified on the live database:** every proposal is shared by **2–3 projects**.

```
proposal 1 → 3 projects
proposal 2 → 2 projects
proposal 3 → 2 projects
proposal 4 → 2 projects
```

Two consequences, both non-negotiable:

1. **A portfolio total must be computed over DISTINCT proposals.** Summing a per-project figure across the page overcounts by roughly 2.25× on today's data. Compute the cockpit total from the distinct proposal ids reachable from the filtered project set, not by adding up the cards.
2. **A shared proposal's budget is not attributable to one project.** Do not print it as "this project's budget". Label the per-card figure as a funding **envelope** — the proposals covering this project — and when a proposal is shared, say so (`projects.shared_with_other_projects`). An honest hedge beats a precise lie; this is exactly the kind of number a fundraising manager will check against their own records.

**Currencies are never summed across codes.** Today the data is 100% USD (4 rows, 6,196,262.91), but EUR and SYP exist in the enum. Group per currency and render one line per currency present. Use `x-ui.money`.

---

## 4. Target design

### 4.1 Shape

```
Projects [8]                     [Board|Timeline|Table]  [Sort ▾]  [+ Add project]
[Search……] [City ▾] [Program ▾] [Owner ▾]
┌ LIFECYCLE ─────────────────────────────────────────────────────────┐
│ DRAFT 1 │ SUBMITTED 1 │ APPROVED 1 │ ACTIVE 3 │ CLOSED 1 │ ARCH. 1 │   ← filter, aria-pressed
└────────────────────────────────────────────────────────────────────┘
[ Committed  $6,196,262 ][ Beneficiaries 12,400 ] [Unfunded 2][Overdue 1][Slipping 3][Risks live 4]
                 read-only                              clickable triage lenses
┌ 8 matching projects ───────────────────────────── [Clear filters] ┐
│ ── ACTIVE ─────────────────────────────────────────────────────── │
│ [card] [card] [card]                                              │
│ ── APPROVED ───────────────────────────────────────────────────── │
```

### 4.2 Header

`x-ui.page-header`, keeping `heading-id="project-directory-heading"` and `:count="$this->projects->total()"`.

- **filters slot, in order:** search input (existing, unchanged semantics), City, Program, Owner — each `x-ui.header-filter-select`. **Remove the status select**; the lifecycle rail replaces it. Tests set `status` directly, so they keep passing.
- **actions slot, in order:** view toggle, sort select, "Add project" (keep `wire:click="openCreateModal"` and `data-test="create-project-button"`).
- The existing `strategy` filter has no visible control today and is URL-only. Leave it that way — `programs/show` links into it.

### 4.3 Cockpit (header `summary` slot)

**Band 1 — lifecycle rail.** Six contiguous segments in `ProjectStatus::cases()` order, each with its count from `statusCounts`, `role="group"` + `aria-label`, `aria-pressed` per segment, count inside the accessible name ("Active, 3 projects"). Clicking sets `status`; clicking the selected one clears it. Counts ignore the `status` filter but respect every other filter.

**The selected segment and the "Add project" button are the only large red areas on the page.** Cell tint must not exceed 34% of `--color-board` (see `.ai/rules/annual-plans.md` — brand red as solid text on a dark ground fails contrast; use border/pill for markers, not red type).

**Band 2 — position + triage.** Two read-only figures, then four clickable lenses (single-select, URL `lens`):

| Tile | Meaning | Tone |
| --- | --- | --- |
| Committed funding | per currency, over **distinct** proposals of the filtered set | `funding` |
| Beneficiaries committed | Σ `target_beneficiaries` of the filtered set | neutral |
| **Unfunded** | `doesntHave('proposals')` | `warn` |
| **Overdue** | `status = active` and `end_date < today` | `critical` |
| **Slipping** | has a milestone with `planned_date < today` and status not in (reached, cancelled) | `warn` |
| **Risks live** | `materialised_risks_count > 0` | `critical` |

A zero-count lens renders quiet and non-interactive rather than vanishing, so the strip keeps a stable shape. `lens` is a single value, not a set — combining lenses produces empty sets that read as bugs.

### 4.4 Board view (default)

Cards grouped into labelled status bands. **When `view === 'board'`, order by status first, then the chosen sort**, so bands are contiguous and pagination never splits one oddly. Board page size **24**; table and timeline stay at **15**.

Card anatomy, in a `grid gap-px bg-board-line` (hairline divisions — the board is divided, **not** floating cards, and never nested in another card):

1. Status-tone rail on the inline-start edge (2px, logical property so RTL flips it).
2. `x-ui.status-block`-equivalent + RAG schedule pill at the inline-end.
3. Name link (`route('projects.show', $project)`, `wire:navigate`, `line-clamp-2`, full name in `title`) + meta `city · program`.
4. Weighted progress bar + "{reached} of {total} milestones reached", or track-only + "No weighted milestones" when `planned_weight` is 0.
5. Funding envelope: amount per currency + donor chips, plus the shared-proposal note when applicable. "Not funded yet" when there are no proposals.
6. Beneficiaries: headline, and the breakdown only when `targetDemographicTotal()` is non-null **and differs** from the headline.
7. Four signal chips — `flag` milestones · `chart-bar` targets · `document-text` proposals · `exclamation-triangle` risks. The risk chip goes `status-critical` when `materialised_risks_count > 0` and its `aria-label` switches to `projects.count_risks_materialised`. Zero counts in `secondary-light`.
8. Owner chip (`x-ui.employee-chip compact`) and the edit button at the top inline-end corner, `wire:click="openEditModal({{ $project->id }})"` **unchanged**.

### 4.5 Timeline view

A Gantt of the same filtered set. **Build it page-locally. Do not edit `x-ui.project-timeline`** — that component is wired to `programs/show`'s own `projectSearch` / `filterProjectsByStatus` methods and its `@4xl/detail` container name; editing it risks that page. Reuse its *arithmetic and visual vocabulary*, which is already proven there:

- window from the filtered set's own min `start_date` / max `end_date`; month ticks with a step of `ceil(months / 8)`; a today line; bars positioned by clamped percentage.
- progress fill inside the bar, an "expected by now" marker from elapsed time, milestone diamonds tinted reached / pending / overdue / missed, and the RAG pill at the row end.
- dashed track for not-yet-started, neutral for finished.
- Projects with no usable dates get a row with an "Unscheduled" pill rather than a zero-width bar.

### 4.6 Table view

The nine columns from `README.md` §5.2 D — Scheduled · Project · Progress · Beneficiaries · Signals · Owner · Schedule · Status · edit — **plus a tenth Funding column** (per-currency, `tabular-nums`, end-aligned). Use `x-ui.data-table` with `x-ui.data-table.cell`; it already stacks into blocks below `@3xl` and carries the `sr-only` caption.

### 4.7 Modal

Behaviour, validation rules, messages, toasts and `data-test` hooks **unchanged**. Restructure into four `fieldset`s with `legend`s (`type-label`, hairline top border, no nested boxes): Identity · Placement · Schedule · Beneficiary targets. Add two Alpine hints, both informational only: duration in days when both dates are set, and whether the four demographic figures add up to the headline.

---

## 5. Livewire changes

### 5.1 New properties

```php
#[Url(except: 'board')]
public string $view = 'board';      // board | timeline | table

#[Url(except: 'name')]
public string $sort = 'name';       // name | start_date | end_date | updated_at

#[Url(except: '')]
public string $lens = '';           // '' | unfunded | overdue | slipping | risks
```

`updatedView()` and `updatedSort()` normalise against their whitelists and fall back to the default on an unknown value; `updatedSort()` and `updatedLens()` call `resetPage()`. `clearFilters()` also resets `lens` — but **not** `view` or `sort`, which are preferences rather than filters. `$filtersAreActive` gains `|| $lens !== ''`.

### 5.2 One filter method, so nothing can drift

Extract the existing `when()` blocks into a single method used by the list, the rail counts and the triage counts:

```php
/** @param Builder<Project> $query */
protected function applyFilters(Builder $query, bool $includeStatus = true, bool $includeLens = true): Builder
```

- Moves search / city / program / strategy / owner across unchanged.
- Wraps the status block in `when($includeStatus && $this->status !== '', ...)`.
- Applies the lens when `$includeLens`:
  - `unfunded` → `doesntHave('proposals')`
  - `overdue` → `where('status', ProjectStatus::Active)->whereDate('end_date', '<', today())`
  - `slipping` → `whereHas('milestones', fn ($q) => $q->whereDate('planned_date', '<', today())->whereNotIn('status', [MilestoneStatus::Reached, MilestoneStatus::Cancelled]))`
  - `risks` → `whereHas('risks', fn ($q) => $q->where('happened', true))`

**Rail counts call `applyFilters(includeStatus: false)`** — so the rail always shows where the rest of the filtered portfolio sits. **Triage counts call `applyFilters(includeStatus: false, includeLens: false)`** — a lens must never shrink its own tile, or clicking it makes the number jump and the page looks broken.

### 5.3 Aggregates on the list query

```php
->withCount([
    'risks', 'milestones', 'planIndicators', 'proposals',
    'risks as materialised_risks_count' => fn ($q) => $q->where('happened', true),
    'milestones as reached_milestones_count' => fn ($q) => $q->where('status', MilestoneStatus::Reached),
])
->withSum(['milestones as reached_weight' => fn ($q) => $q->where('status', MilestoneStatus::Reached)], 'weight_percentage')
->withSum(['milestones as planned_weight' => fn ($q) => $q->where('status', '!=', MilestoneStatus::Cancelled)], 'weight_percentage')
```

Progress, with no extra query:

```php
public function progressFor(Project $project): ?float
{
    $planned = (float) ($project->planned_weight ?? 0);

    return $planned > 0 ? round(min(100, ((float) ($project->reached_weight ?? 0) / $planned) * 100), 1) : null;
}
```

Weights over 100 are capped by the `min()`, per `README.md` §8.

### 5.4 Funding, in one grouped read

Keyed by the **visible page's** project ids, never per card:

```php
/** @return array<int, array<string, float>>  project id => [currency => total] */
#[Computed]
public function fundingByProject(): array
```

One query over `project_proposal` joined to `donor_proposal`, `whereIn('project_proposal.project_id', $visibleIds)`, grouped by `project_id, currency`. Collect donor names and the per-proposal share count in the same read so the card can say a proposal is shared.

```php
/** @return array<string, float>  currency => total, over DISTINCT proposals */
#[Computed]
public function committedFunding(): array
```

Resolves the distinct proposal ids of the filtered set (**not** the page), then sums `donor_proposal.budget` grouped by currency. See §3.3 — this is the query that must not double count.

### 5.5 Sorting

| `sort` | Order (board view prefixes `status` first) |
| --- | --- |
| `name` (default, and any unknown value) | `name` asc, `id` asc |
| `start_date` | `orderByRaw('start_date is null')`, `start_date` asc, `name` asc |
| `end_date` | `orderByRaw('end_date is null')`, `end_date` asc, `name` asc |
| `updated_at` | `updated_at` desc, `id` desc |

Nulls sort last in both date orders, which is why the `is null` raw ordering comes first.

### 5.6 Query budget

page (1) + count (1) + eager loads (city HR 1, program 1, user HR 1) + city search pre-query (0–1) + statusCounts (1) + 4 triage counts (4) + fundingByProject (1) + committedFunding (1) + beneficiary sum (1) + option lists (3) = **≤ 17**.

Assert it with `assertDatabaseQueryCount` or a `DB::listen` counter in the test. If it comes in higher, the likely cause is a per-card funding lookup — fix that, don't raise the budget. Consider folding the four triage counts into one `selectRaw` of conditional aggregates if you want to get under 14.

---

## 6. Copy

Add every new key to `lang/en/projects.php` **and** `lang/ar/projects.php`, following `copy.md`'s existing entries. New groups needed beyond what `copy.md` already lists:

- lifecycle rail label, and the rail's per-segment accessible name (`:status`, `:count`)
- the six cockpit tiles: committed funding, beneficiaries committed, unfunded, overdue, slipping, risks live — each with a short helper line
- funding envelope label, "not funded yet", "shared with {count} other projects" (`trans_choice`)
- view toggle labels: board, timeline, table (+ the group's `aria-label`)
- sort labels for the four sort values
- timeline legend reuses the existing `programs.timeline_*` keys where the wording matches; add `projects.*` equivalents rather than reaching into another page's group if the wording differs.

Arabic plurals use the explicit `{0}|{1}|{2}|[3,10]|[11,*]` ranges. Run `./php.cmd artisan test --compact tests/Feature/LocalizationTest.php` as soon as the keys are in, **before** the markup.

---

## 7. Tests

Extend `tests/Feature/ProjectDirectoryTest.php` (268 lines, 13 tests). **All 13 must stay green** — in particular `the project directory offers no delete action` and the filter tests that set `status` directly. Add:

1. Each lens filters to exactly the right projects — one test per lens, with a fixture that satisfies one lens and not the others.
2. Triage counts ignore the active lens (click `overdue`, assert the `slipping` count is unchanged).
3. Rail counts ignore `status` but respect `city` / `program` / `owner`.
4. `committedFunding` does **not** double count a proposal shared by two projects — the regression test for §3.3. Build two projects on one proposal of 1,000 and assert the total is 1,000, not 2,000.
5. Multi-currency: a USD proposal and a EUR proposal produce two separate lines and are never added.
6. `progressFor` — weighted progress from mixed milestone statuses, `null` when no weighted milestones, capped at 100 when weights exceed 100.
7. `view=timeline` and `view=table` render the same project set as `view=board`; an unknown `view` falls back to `board`.
8. Sorting by each of the four values, including that null dates sort last.
9. Query count stays within the §5.6 budget.
10. Board view groups by status and uses page size 24.

Use factories and their existing states; `MilestoneFactory` needs weights set explicitly for the progress tests.

---

## 8. Build order

1. Read §0. Read the current page file end to end.
2. Add the lang keys (en + ar). Run `LocalizationTest`.
3. Livewire class only: properties, `applyFilters`, sorting, aggregates, `progressFor`, `statusCounts`, the four triage counts, `fundingByProject`, `committedFunding`, `updated*` hooks, `clearFilters`. **Run the existing `ProjectDirectoryTest` and get it green before touching any markup.**
4. Write the funding double-count test (§7.4) now, while the query is fresh.
5. Header: drop the status select, add the view toggle and sort select.
6. Cockpit: lifecycle rail, then the two read-only figures, then the four lenses.
7. Board view + status bands.
8. Table view (ten columns), then the column hiding between `@3xl` and `@6xl`.
9. Timeline view.
10. Modal restructure + the two Alpine hints.
11. Remaining tests from §7; run the file.
12. Browser pass: light/dark × en/ar × 1440/1024/390, plus every state in `README.md` §8. Seed with `Project::factory()->count(40)` and weighted milestones **on a local DB only**.
13. `vendor/bin/pint --dirty --format agent`, then the full suite, then `npm run build`.
14. Run the design detector over the changed file:
    `impeccable detect --json resources/views/pages/projects/⚡index.blade.php`
15. Tick `checklist.md`. **Do not commit without asking.**

---

## 9. Out of scope

- No delete action (its absence is tested).
- No bulk actions, no export, no inline status editing on cards or rows.
- No new methods or accessors on `App\Models\Project` — progress and funding stay page-level.
- No edits to `resources/views/components/ui/**`. Everything new on this page is page-local. `x-ui.stage-rail`, `x-ui.view-toggle`, `x-ui.status-block`, `x-ui.schedule-status`, `x-ui.board-loading` and `x-ui.progress-ring` are named by `README.md` but **do not exist** — that foundation wave never landed. Build their behaviour inline here; extract later, when a second page needs it.
- No changes to the search semantics or the modal's validation rules and messages.
- No redesign of `projects/⚡show.blade.php` — the user scoped this to the index.

---

## 10. Acceptance

- [ ] All 13 existing `ProjectDirectoryTest` tests green, plus the new ones from §7.
- [ ] `LocalizationTest` green; no literal UI strings; RTL verified in Arabic.
- [ ] Portfolio funding is computed over distinct proposals and proven by the §7.4 test.
- [ ] Currencies are never summed across codes.
- [ ] ≤ 17 queries per render, asserted.
- [ ] `x-ui.page-header` + `x-ui.header-filter-select` used; no action inside the filters grid.
- [ ] Every modal bound with `wire:model`, not `:open`.
- [ ] No raw hex outside `app.css`; status/funding/board tokens used only for status, data-viz and board surfaces.
- [ ] Touch targets ≥ 44px at 390px; focus ring visible on every interactive element.
- [ ] `x-ui.project-timeline` untouched; `programs/show` still renders correctly.
- [ ] Pint clean, full suite green, `npm run build` clean.
