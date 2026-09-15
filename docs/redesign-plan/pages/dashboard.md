# P1 · Dashboard

**Route:** `dashboard` (`/dashboard`) · **Group:** A · Command center · **Wave:** 1 · **Depends on:** 02, 03, 04

## Files you own

- `routes/web.php`: **only** the `dashboard` line
- New `resources/views/pages/dashboard/⚡index.blade.php`
- Delete `resources/views/dashboard.blade.php` (after the new page works)
- New `lang/en/dashboard.php`, `lang/ar/dashboard.php`
- `tests/Feature/DashboardTest.php`

## Current state

Starter-kit placeholder: three empty boxes + one empty panel with `x-placeholder-pattern`. `Route::view('dashboard', 'dashboard')`. No data.

## Goal

The first screen a fundraising manager opens: portfolio health in one glance, what needs attention today, and what just happened. Connects delivery and money on one screen (PRODUCT principle 1).

## Route change

```php
Route::livewire('dashboard', 'pages::dashboard.index')->name('dashboard');
```
Keep it inside the `auth, verified` group. `#[Title('titles.dashboard')]` (add the key if missing).

## Layout (top → bottom)

1. **Header:** `x-ui.page-header` title "Dashboard", description = today's date `translatedFormat(__('formats.date'))` + "Portfolio overview".
2. **Figures band** (one `x-ui.panel`, figures in a row, wraps to 2 columns under `@3xl`, 1 under `@sm`). Use `x-ui.figure`, **not** separate cards:
   - Active projects: `Project::where('status', ProjectStatus::Active)->count()` → href `projects.index?status=active`
   - Milestones due this month: `Milestone::where('status', MilestoneStatus::Pending)->whereBetween('planned_date', [startOfMonth, endOfMonth])->count()` → `milestones.index`
   - Overdue tasks: `Task::where('status', '!=', TaskStatus::Done)->whereDate('due_date', '<', today())->count()` → tone `critical` when > 0 → `tasks.index`
   - Materialised risks: `Risk::where('happened', true)->count()` → tone `critical` when > 0 → `risks.index?state=materialised` (use the existing state filter value)
   - Reports awaiting MEAL review: `Report::where('is_seen_by_meal', false)->where('status', '!=', ReportStatus::Draft)->count()` → `reports.index` with the unseen filter
3. **Two-column grid** (`@5xl:grid-cols-[1.6fr_1fr]`):
   - **Left: "Needs attention"** panel: one merged list, max 8 rows, sorted by urgency (overdue days desc). Sources (one query each, `limit(8)`, eager-load shown relations):
     - Overdue tasks: title, `milestone.project.name`, `x-ui.schedule-status` (due_date, completed_date, done=false), assignee chip → `tasks.show`
     - Overdue milestones: `status = Pending` and `planned_date < today`: name, `project.name`, schedule-status → `milestones.index?project=`
     - Pending transfers past schedule: `ar_status = Pending` and `scheduled_date < today`: `donorProposal.donor.name · donorProposal.proposal.name`, `x-ui.money` (payment_amount, amount_transfered_currency), schedule-status → `funding-agreements.show`
     - Materialised risks: name, `project.name`, critical chip → `risks.index`
     - Empty → `x-ui.empty-state` icon `check-circle`, "Nothing needs attention right now".
   - **Right: "Funding position"** panel. For **each currency** present:
     - `x-ui.capacity-bar` committed = `DonorProposal::selectRaw('currency, sum(budget) as total')->groupBy('currency')`, used = `TransfersTracking::where('ar_status', ArStatus::Received)->selectRaw('amount_received_currency as currency, sum(net_amount_received) as total')->groupBy('amount_received_currency')`. Match by currency code; a currency with receipts but no commitments still shows (committed 0 → bar in warn with "No commitment recorded").
     - Below: link "All funding agreements" → `funding-agreements.index`.
4. **Charts row** (`@4xl:grid-cols-3`), each an `x-ui.chart` inside a panel:
   - "Projects by status": donut, series = counts per `ProjectStatus` (one grouped query), tones: Active good, Approved/Submitted neutral, Draft secondary-light, Closed/Archived secondary-dark.
   - "Proposal pipeline": bar, categories = `ProposalPhase` labels, counts via proposals grouped by `status` mapped to phase in PHP.
   - "Received per month": area, last 12 months, **one series per currency** (select `receive_date, amount_received_currency, net_amount_received` where `ar_status = received` and `receive_date >= now()->subMonths(11)->startOfMonth()`, group in PHP by `Y-m` for DB portability). If more than one currency, render one chart per currency (tabs via `x-ui.relation-tabs` with `wireTarget="currency"`), never a shared y-axis.
5. **Recent activity** panel (full width, max 10 rows, `x-ui.activity-row`), merged in PHP from:
   - `ProposalStatusNote::with('proposal')->latest('date')->limit(10)`: "{proposal} moved to {status}"
   - `TaskUpdate::with(['task', 'user'])->latest('date')->limit(10)`: "{user} updated {task}"
   - `Report::with(['user', 'planIndicator.indicator'])->whereNotNull('submit_date')->latest('submit_date')->limit(10)`: "{user} submitted a report on {indicator}"
   - Sort by date desc, take 10.

## Data rules

- Each band/panel is its own `#[Computed]` method (`figures`, `attention`, `funding`, `projectStatusChart`, `pipelineChart`, `receiptsChart`, `activity`). Target ≤ 20 queries total.
- No year filter on this page (the executive summary owns fiscal scoping).

## States

- Fresh install (all zero): figures show `0`, charts show their `empty` text, attention/activity empty states. The page must render with an empty DB.
- Loading: none needed (no interactive filters except the receipts currency tabs → `wire:loading` on that chart panel).

## i18n (`dashboard.php`)

Titles, figure labels, panel headings, attention reasons, activity sentences with placeholders (`:proposal`, `:status`, `:user`, `:task`, `:indicator`), empty states, "No commitment recorded". Arabic plurals where counts appear.

## Tests (`DashboardTest.php`)

- Keep existing shell assertions.
- Figures: seed 2 active + 1 draft project, 1 overdue task, 1 materialised risk → counts render.
- Attention list includes the overdue task and links to `tasks.show`.
- Funding position renders one capacity bar per currency and never a combined total (seed USD + EUR).
- Empty database renders without errors and shows the empty states.
- Activity shows a proposal status note sentence.

## Acceptance

- [ ] Route switched; old view deleted; `DashboardTest` green.
- [ ] Every figure links to a filtered destination that actually applies the filter.
- [ ] ≤ 20 queries with seeded data; no lazy loading.
- [ ] Conventions §10 checklist.
