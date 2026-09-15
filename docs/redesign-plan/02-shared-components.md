# 02 · Shared component contracts

**Owner group:** Foundation · **Wave:** 0 (after 01) · **Blocks:** all pages

These are **contracts**: page programmers code against these props and slots, and the foundation owner implements them. Once a contract is published, changing a prop name or meaning needs every page owner's agreement. Adding optional props is fine.

All components live in `resources/views/components/ui/`, are anonymous Blade components, take colour only from tokens, work in dark + RTL, and put all strings in `lang/*/ui.php`.

## Files you own

- `resources/views/components/ui/**`
- `lang/en/ui.php`, `lang/ar/ui.php`, `lang/en/formats.php`, `lang/ar/formats.php` (add `month_short`, `day_month` patterns)
- `tests/Feature/UiComponentsTest.php`

Tone values everywhere: `neutral | good | warn | critical | funding`.

---

## A. Existing components: keep API, restyle

| Component | Keep | Add (optional) |
| --- | --- | --- |
| `page-header` | `title, count, description, headingId`, slots `filters`, `actions`; `data-count-badge` only when `count !== null` | slot `summary` (rendered full-width below the title row: status strips, KPI rows) |
| `detail-header` | `breadcrumbs, title, subtitle`, slots `meta`, `actions` | slot `summary` (below meta), prop `status` (an enum case, rendered as `enum-badge` next to the title) |
| `panel` | `title, subtitle, count`, slots `toolbar`, `footer` | prop `flush` (bool, no body padding assumptions), prop `icon` |
| `stat-tile` | `label, value, denominator, unit, scope` | `tone`, `href` (whole tile becomes a link), `hint` (meta line), `trend` (list<float> → inline `sparkline`), `delta` (string, e.g. "+4 this month") |
| `relation-tabs` | `tabs, active, wireTarget` (`tabs[].key/label/count`) | `tabs[].icon` (Flux icon name) |
| `empty-state` | `icon, heading, body`, slot `action` | variant `filtered` (bool) → shows the "Clear filters" action text from `ui.php` when paired with a `wire:click` in the slot |
| `enum-badge` | `case` + current tone classes (tested) | `size` (`sm`/`md`) |
| `money`, `user-chip`, `employee-chip`, `button`, `input`, `select` | unchanged API | restyle only; keep tested classes |
| `tree-rows` | `nodes, depth, label, badge` | becomes a thin wrapper that renders `x-ui.tree` (below) so existing callers keep working |

## B. New components

### `x-ui.status-strip`
Segmented, proportional distribution bar that doubles as a filter.
```blade
<x-ui.status-strip
    :label="__('projects.by_status')"
    :segments="[['key' => 'active', 'label' => 'Active', 'count' => 12, 'tone' => 'good'], ...]"
    :active="$status"              {{-- ?string --}}
    wire-model="status"            {{-- ?string: clicking sets this property; clicking the active one clears it --}}
/>
```
- Renders a 10px flat bar (segment width ∝ count, min 2px when count > 0) + a legend row of buttons `label · count`.
- Buttons: `aria-pressed`, `wire:click="$set('status', 'active')"`. Without `wire-model`, the legend is static text.
- Total 0 → neutral empty bar + "No records yet" (`ui.no_records`).

### `x-ui.progress-ring`
```blade
<x-ui.progress-ring :value="72.5" size="md" tone="good" :label="__('tasks.progress')" />
```
- `value` ?float 0–100 (null → em dash in the centre, no arc). `size`: `sm` 28px (no inner text), `md` 48px, `lg` 96px (inner text `type-figure`).
- `role="progressbar" aria-valuemin=0 aria-valuemax=100 aria-valuenow aria-label`. SVG circle, flat stroke, starts at 12 o'clock, does not mirror in RTL.

### `x-ui.progress-bar`
```blade
<x-ui.progress-bar :value="$weightDone" :max="100" tone="neutral" :label="..." show-value />
```
- Flat 6px track. `value > max` → full bar in `warn` + text "Over by N%" (`ui.over_by`). Null value → track only + em dash.

### `x-ui.capacity-bar`
Committed-vs-used for money, one currency.
```blade
<x-ui.capacity-bar :committed="$agreement->budget" :used="$received" :currency="$agreement->currency" />
```
- Throws `InvalidArgumentException` if `currency` is null (same rule as `money`).
- Shows `money(used) of money(committed) · NN%`, bar tone `funding`; over 100% → `warn` + overflow marker.
- Pages with several currencies render one capacity-bar per currency; never merge.

### `x-ui.rag-chip`
```blade
<x-ui.rag-chip :case="$indicator->ragStatus($from, $to)" />
```
- `RagStatus::Green` → good, `Amber` → warn, `Red` → critical. Flat filled chip, dot + `$case->label()`. Null → em dash with `ui.not_set` aria-label.

### `x-ui.sparkline`
```blade
<x-ui.sparkline :points="[3, 5, 4, 8]" tone="neutral" :label="__('indicators.trend')" />
```
- Server SVG (`width` 96, `height` 28 defaults), polyline + 12% area fill + emphasized last point. `< 2` points → em dash. `role="img"` + aria-label including first → last value.

### `x-ui.chart`
Wrapper around ApexCharts. Full contract in `03-charts.md`.

### `x-ui.tree`
Hierarchies with real connector lines (results framework, milestones).
```blade
<x-ui.tree
    :nodes="$roots"                                  {{-- models with childrenRecursive eager-loaded --}}
    :label="fn ($node) => $node->statement"
    :badge="fn ($node) => $node->type"               {{-- ?Closure → enum case --}}
    :href="fn ($node) => null"                       {{-- ?Closure → ?string --}}
    :meta="fn ($node) => view('pages.results._node-meta', ['node' => $node])"  {{-- ?Closure → Htmlable --}}
    :actions="fn ($node) => ..."                     {{-- ?Closure → Htmlable (edit/delete buttons) --}}
/>
```
- Each node row carries `data-tree-depth="{depth}"` and `wire:key="tree-node-{id}"` (**tests**).
- Connectors: 1px `secondary-soft` lines drawn with `border-inline-start` on the children group + an elbow per node (logical properties, correct in RTL). Indent 1.25rem per level.
- Nodes with children get a disclosure button (`aria-expanded`); default expanded up to depth 2.

### `x-ui.timeline`
Planned-vs-actual roadmap rows (milestones, proposals, strategies).
```blade
<x-ui.timeline
    :from="$windowStart" :to="$windowEnd"          {{-- CarbonInterface; default: min/max of items --}}
    :items="[[
        'key' => 'm-12', 'label' => 'Household survey', 'sublabel' => 'WASH · Aleppo',
        'start' => $plannedStart, 'end' => $plannedEnd,  // ?CarbonInterface; a single date → point marker
        'actual' => $actualDate,                            // ?CarbonInterface
        'tone' => 'good', 'href' => route(...),
    ]]"
/>
```
- Label column (min 12rem) + track. Month ticks formatted with `__('formats.month_short')`. A vertical "today" line inside the window.
- Positions via `inset-inline-start: N%` so RTL mirrors naturally.
- Narrow container (< `@2xl`): track hidden, row shows `x-ui.schedule-status` instead.

### `x-ui.schedule-status`
The universal "planned vs actual" reading.
```blade
<x-ui.schedule-status :planned="$task->due_date" :actual="$task->completed_date" :done="$task->status === TaskStatus::Done" />
```
Resolves, in order: no planned → "Unscheduled" (neutral); done & actual ≤ planned → "On time" (good); done & actual > planned → "N days late" (warn); not done & planned < today → "Overdue by N days" (critical); not done & planned within 7 days → "Due in N days" (warn); else "Due {date}" (neutral). All strings `trans_choice` in `ui.php`.

### `x-ui.kanban`, `x-ui.kanban.column`, `x-ui.kanban.card`
```blade
<x-ui.kanban :label="__('tasks.board')">
    <x-ui.kanban.column key="todo" :label="..." :count="4" tone="neutral">
        <x-ui.kanban.card :href="route('tasks.show', $task)" wire:key="task-card-{{ $task->id }}">...</x-ui.kanban.card>
    </x-ui.kanban.column>
</x-ui.kanban>
```
- Columns `min-w-72`, horizontal scroll with `scroll-snap` on narrow widths; column header sticky with count.
- Attributes pass through to the column/card root so `wire:sort` / `wire:sort:item` can be attached by a page if a page's instruction allows drag.

### `x-ui.view-toggle`
```blade
<x-ui.view-toggle wire-model="view" :active="$view" :options="[['key' => 'table', 'label' => __('ui.view_table'), 'icon' => 'table-cells'], ['key' => 'cards', 'label' => __('ui.view_cards'), 'icon' => 'squares-2x2']]" />
```
Segmented `role="radiogroup"`, each option `role="radio" aria-checked`, sets the property via `wire:click="$set(...)"`.

### `x-ui.data-table` (+ `x-ui.data-table.cell`)
One responsive table for directories; replaces duplicated desktop-table + mobile-card markup.
```blade
<x-ui.data-table :caption="__('users.directory')" stack-below="@3xl">
    <x-slot:head>
        <th scope="col">...</th>
    </x-slot:head>
    @foreach ($rows as $row)
        <tr wire:key="row-{{ $row->id }}">
            <x-ui.data-table.cell :label="__('users.role')">...</x-ui.data-table.cell>
        </tr>
    @endforeach
</x-ui.data-table>
```
- A real `<table>`; below the container breakpoint rows become stacked blocks, and each cell shows its `label` via `data-label`. Sticky header, hairline rows, numeric cells `text-end tabular-nums` via `numeric` prop on cell.

### `x-ui.activity-row`
```blade
<x-ui.activity-row icon="document-check" :actor="$user" :sentence="__('dashboard.activity.report_submitted', ['indicator' => $name])" :href="$url" :at="$date" />
```
Actor avatar/initials (User or HrEmployee or null → system icon), translated sentence, relative time (`diffForHumans`) with absolute date in `title`.

### `x-ui.heat-cell`
```blade
<x-ui.heat-cell :value="$count" :max="$maxCount" :href="$url" :label="$ariaLabel">{{ $slot }}</x-ui.heat-cell>
```
Five steps (0, ≤25%, ≤50%, ≤75%, >75% of `max`) using `secondary-dark` via `color-mix` at 0/12/28/48/72%. Text switches to white from step 3. Value 0 → empty dashed cell.

### `x-ui.reference-list`
Replaces the misleading file lists on tasks and reports (decision: relabel, no downloads).
```blade
<x-ui.reference-list :items="$attachments->map(fn ($a) => ['name' => basename($a->path), 'type' => $a->type, 'date' => $a->created_at])" />
```
Heading "Referenced documents", note "Kept outside HC Tracking. Ask the record owner for a copy." (`ui.php`). Items are plain text rows with type chip + date, **no link, no download icon, no pointer cursor**.

### `x-ui.figure-housing` + `x-ui.flap-figure` (Operations Board signature; see 01 §6.2)
```blade
<x-ui.figure-housing :label="__('dashboard.portfolio_figures')">
    <x-ui.flap-figure :value="$activeProjects" :label="__('dashboard.active_projects')" tone="good" :href="route('projects.index', ['status' => 'active'])" />
    <x-ui.flap-figure :value="number_format($committed, 0)" unit="USD" :label="__('dashboard.committed')" />
</x-ui.figure-housing>
```
- `figure-housing`: dark band, `role="group"` + `aria-label`, lays figures in a row (`flex-wrap`, gap 1px showing housing colour), max **one per page**.
- `flap-figure` props: `value` (int|string|null; null → "—" in a single cell), `label`, `unit` (?string, rendered outside cells), `tone` (?`good|warn|critical`: dot before label only), `href` (?string: whole figure is a link with focus ring), `caption` (?string, second meta line).
- Splits `value` into cells on the thousands separator of the formatted string (`1,240,500` → `1` `240` `500`); each cell has `wire:key="flap-{label-hash}-{index}-{char-group}"` so a changed value re-mounts and plays the roll animation (01 §6.6).
- Screen readers get the whole value once (`aria-label="{label}: {value} {unit}"`); cells are `aria-hidden`.

### `x-ui.status-block` (row status cell; see 01 §6.3 and §6.5)
```blade
<x-ui.status-block :case="$task->status" />                 {{-- tone from the §6.5 map --}}
<x-ui.status-block tone="critical" :label="__('ui.overdue')" />  {{-- derived states --}}
```
- Props: `case` (?BackedEnum with `label()`), or `tone` + `label`. `case` wins when both given. Null case → neutral block with "—".
- Implements the **only** enum → tone map in the app (01 §6.5). Throws `InvalidArgumentException` for an enum not in the map (so new enums get mapped deliberately).
- Renders `data-tone="{tone}"` for tests and the flip animation.
- `x-ui.enum-badge` stays for secondary, inline statuses (for example inside meta lines) and keeps its tested classes.

### `x-ui.stage-rail` (ordered lifecycles; see 01 §6.4)
```blade
{{-- Filter mode (index pages) --}}
<x-ui.stage-rail :label="__('projects.lifecycle')" wire-model="status" :active="$status"
    :stages="collect(ProjectStatus::cases())->map(fn ($case) => ['key' => $case->value, 'label' => $case->label(), 'count' => $counts[$case->value] ?? 0])->all()" />

{{-- Position mode (detail pages) --}}
<x-ui.stage-rail :label="__('proposals.status_path')" :current="$proposal->status->value"
    :stages="[...]" />
```
- Props: `label` (aria), `stages` list<{key, label, count?: int, href?: string}>, and **either** `wire-model` + `active` (filter mode: segments are `button`s with `aria-pressed`; clicking the active one sets the property to `''`) **or** `current` (position mode: segments before `current` are *passed*, `current` is *current*, after are *future*; segments are `li` in an `ol`, current has `aria-current="step"`).
- Optional `branch` prop: `['after' => 'submitted', 'key' => 'rejected', 'label' => ...]` renders a terminal critical segment after the named stage **only** when `current === branch.key` (used by proposals).
- Filter mode with `''` active: all segments "future" style (none selected).

### `x-ui.data-table` variants
Add prop `variant`: `default` | `timetable`. `timetable` fixes the first column (`scheduled`) at 7.5rem and the last (`status`) at 9rem, as in 01 §6.3; cells accept `x-ui.data-table.cell :label :numeric :role="scheduled|actual|status"`.

### `x-ui.board-loading`
```blade
<x-ui.board-loading wire:loading.delay wire:target="search, status, city" />
```
Place directly inside a panel header; renders the 2px indeterminate primary line (01 §6.6). The parent board gets `wire:loading.class="opacity-60"` on its rows container.

---

## Tests to add in `UiComponentsTest.php`

- status-strip: proportional widths, `aria-pressed` on active, `wire:click` set/clear, zero-total state.
- progress-ring / progress-bar: aria attributes, null value, overflow text.
- capacity-bar: throws without currency; over-100% state.
- rag-chip: tone per case, null.
- sparkline: fewer than 2 points renders em dash.
- tree: `data-tree-depth` present at depth 0/1/2 (keep the existing tree-rows test passing).
- schedule-status: each of the six branches (use `Carbon::setTestNow`).
- reference-list: renders no `<a` and no `href`.
- data-table: stacked labels present; `timetable` variant column roles.
- status-block: every enum case in the 01 §6.5 map resolves to its tone (`data-tone`); unmapped enum throws; null case renders "—".
- stage-rail: filter mode `aria-pressed` + clear on re-click; position mode passed/current/future classes + `aria-current="step"`; branch segment only when current is the branch key.
- flap-figure: splits `1,240,500` into three cells; null → "—"; `aria-label` carries full value and unit; `href` renders a link.
- figure-housing: `role="group"` with label.

## Acceptance

- [ ] Every contract above implemented with the exact prop names.
- [ ] Existing `UiComponentsTest` assertions still pass; new tests added.
- [ ] A kitchen-sink check page is **not** added to routes. Verify components via tests and the first consuming pages.
