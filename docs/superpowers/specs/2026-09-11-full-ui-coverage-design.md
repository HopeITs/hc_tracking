# Full UI Coverage — Design

Date: 2026-09-11
Branch: `data-visualization`
Status: approved (inventory), pending review (this document)

## Goal

Every entity table in the schema becomes reachable, readable and — where deletion
is safe — writable through the application UI. The v0 deliverable is a navigable
product that shows the real seeded data, including the two self-referencing
trees, the polymorphic target source, the plain pivot and the pivot-that-is-an-
entity.

Fifteen of twenty-two entity tables currently have no UI. This closes that gap.

## Non-goals

- Authorization. `.ai/rules/enums.md` records that policies were deliberately
  deferred until the UI exists. Sections are role-neutral; any authenticated,
  verified user reaches all of them. The one exception is the existing
  `admin.users.index`, which keeps its `can('viewAny', User::class)` gate.
- File upload and download. `storage/app` holds nothing but `.gitignore` files;
  every `report_files.path` and `task_attachments.path` is a factory-generated
  `{uuid}.{ext}` pointing at no file. Both sections render metadata only. No
  download link is emitted, because it would 404.
- Currency conversion. There is no rate table. Money is presented per currency.
- Changes to migrations, models, observers, factories or seeders. This is a UI
  layer over the existing schema. If a page cannot be built without a schema
  change, that is a finding to raise, not a change to make.

## Architecture

### Page shape

Livewire 4 single-file components under `resources/views/pages/<area>/`, named
`⚡index.blade.php` and `⚡show.blade.php`, matching the four pages already in
the repository. Routes are registered with `Route::livewire(...)` in
`routes/web.php` inside the existing `['auth', 'verified']` group.

Detail routes bind the model in `mount()`:

    public function mount(Project $project): void { $this->projectId = $project->id; }

The component stores the id and re-reads through a `#[Computed]` method rather
than holding a hydrated model in a public property. Livewire serialises public
properties between requests; a hydrated Eloquent model with eager-loaded
relations round-trips badly and re-triggers strict-mode violations after a
network hop.

### Shared presentation components

The four existing pages each repeat the same `@php` block of Flux override
classes (`$controlClasses`, `$primaryButtonClasses`, `$secondaryButtonClasses`,
`$editButtonClasses`) and the same header / toolbar / empty-state / pagination
skeleton. Repeating that across twenty-two more pages is the main structural
risk in this work.

New Blade components under `resources/views/components/ui/` absorb it:

| Component | Responsibility |
| --- | --- |
| `x-ui.page-header` | Title, count badge, description, `filters` slot, `actions` slot |
| `x-ui.panel` | Bordered card with toolbar row, body slot, footer slot |
| `x-ui.empty-state` | Icon, heading, body, optional action — filtered vs unfiltered copy |
| `x-ui.enum-badge` | One enum case in, one coloured pill out |
| `x-ui.user-chip` | Avatar + name + role + email, from a `User` |
| `x-ui.money` | Amount + currency, tabular-nums, never summed across currencies |
| `x-ui.tree-rows` | Depth-indented renderer for a `HasTree` subtree |
| `x-ui.detail-header` | Breadcrumb, title, meta row, actions |
| `x-ui.relation-tabs` | Tab strip for detail-page relation panels |
| `x-ui.stat-tile` | Labelled figure with optional denominator |

The approved palette tokens from `.ai/rules/css.md` (`primary #be1f24`, the four
`secondary-*` values) are used through the existing utility names. No new
`--spacing-*` token is introduced; that file's namespacing trap stays untouched.

**The four existing pages are not migrated onto these components.** They work,
they are tested, and rewriting them buys nothing for this goal while risking
regressions in the one part of the UI that is already correct. The components
are for the new pages.

### Data loading

Strict mode (`Model::shouldBeStrict(! app()->isProduction())`, recorded in
`.ai/rules/providers.md`) makes lazy loading throw. Therefore:

- Every index page loads through one `#[Computed]` method returning a
  `LengthAwarePaginator`, with an explicit `select()`, an explicit `with()`
  covering every relation the row markup touches — nested paths included — and
  `withCount()` for every badge.
- Every detail page loads the subject in one `#[Computed]` method, and each
  relation panel in its own `#[Computed]` method, paginated or capped. A project
  with five hundred tasks must not render five hundred rows.
- Filters are `#[Url(except: '')]` string properties with `updatedX()` calling
  `resetPage()`, matching the Programs page exactly.
- No query is ever issued inside a render loop. `.ai/rules/yearly-plans.md`
  already holds this line for the annual-plan matrix and asserts a fixed query
  count; new pages follow the same discipline.

### Trees

`Milestone` and `Result` traverse through `App\Concerns\HasTree`. Pages use
`childrenRecursive` to load a subtree in one pass per level and render it
depth-indented via `x-ui.tree-rows`. `descendants()` is never called inside a
loop.

Parent selection in a create/edit form excludes the node itself and its
descendants from the options list, so the observer's `InvalidParentException`
is a backstop rather than the primary guard. For `Milestone`, the parent options
are additionally restricted to the same `project_id`, because
`.ai/rules/observers.md` records that nothing at the database level stops a
milestone nesting under one in another project.

### The morph

`plan_indicators.source_type` / `source_id` resolve through the enforced morph
map to exactly `YearlyPlan` and `Proposal`. The UI:

- displays the source using the map key (`yearly_plan`, `proposal`), never a
  class name;
- filters by source with `where('source_type', 'yearly_plan')`;
- offers exactly those two types in the create form's source-type select;
- renders a "source no longer available" state rather than throwing when
  `source` resolves to `null`, because `.ai/rules/database-migrations.md`
  records that a query-builder delete can orphan a target and no foreign key
  can stop it.

No new polymorphic relation is introduced. The map stays at two entries and
`tests/Feature/Models/MorphMapTest.php` keeps passing untouched.

### Pivot vs pivot-entity

`project_proposal` is a plain pivot. It is managed by attach/detach controls on
both Project detail and Proposal detail, and the unique index on the id pair
means `attach()` cannot silently double-count.

`donor_proposal` is an entity in pivot clothing — it carries `budget`,
`currency` and `project_num`. It gets its own section, "Funding Agreements",
with an index and a detail page. All funding UI reads through
`Proposal::donorProposals()` / `Donor::donorProposals()`, never through the
`belongsToMany` pivot, because `.ai/rules/enums.md` records that
`$proposal->donors()->first()->pivot->currency` is a raw string while
`DonorProposal::first()->currency` is a `Currency` case. A pivot currency is
never compared to a `Currency` case.

### Derived values

`proposals.phase` has no column. It is read through the `$proposal->phase`
accessor and a whole phase is queried with
`whereIn('status', ProposalPhase::Implementation->statuses())`. The UI never
writes a phase.

## Sections

Sidebar grouping, replacing the current single "Overview" group. Per
`.ai/rules/app.md`, every item is added only once its named route exists.

- **Overview** — Dashboard, Executive Summary
- **Strategy** — Strategies, Programs, Annual Plans
- **Delivery** — Projects, Milestones, Tasks, Risks
- **Results & Learning** — Results Framework, Indicators, Targets, Reports
- **Funding** — Proposals, Donors, Funding Agreements, Transfers
- **Administration** — Accounts, Cities, Users
- **Settings** — Profile, Appearance, Security

### Write permissions, and the rule that set each one

Every foreign-key delete rule in the schema was mapped before these were
decided. A section gets delete only where deleting cannot silently take
unrelated data with it.

| Section | Route(s) | Writes | Governing rule |
| --- | --- | --- | --- |
| Projects | `projects.index`, `projects.show` | Create, Edit | No delete: cascades into risks, milestones, tasks, task_updates, task_attachments |
| Milestones | `milestones.index`, Project panel | Create, Edit, Delete (leaf and taskless only) | `parent_milestone_id` cascades, so a parent delete would take its subtree. "Taskless" is a separate, deliberate product choice: `tasks.milestone_id` is `nullOnDelete`, so deleting a milestone with tasks would not lose them — it would silently orphan them into "No milestone", which is worse than refusing |
| Tasks | `tasks.index`, `tasks.show` | Create, Edit, Delete | Cascades reach only its own updates and attachments |
| Task updates | Task detail timeline | Create only | Append-only comment log; `user_id` is `auth()->id()` |
| Task attachments | Task detail panel | None | No files on disk |
| Risks | `risks.index`, Project panel | Create, Edit, Delete | Leaf table; nothing cascades out of it |
| Proposals | `proposals.index`, `proposals.show` | Create, Edit; Delete disabled with reason when funded | `donor_proposal.proposal_id` RESTRICTs to protect the money trail |
| Status notes | Proposal detail timeline | Written by the status-change action | Audit trail for `proposals.status`, same `ProposalStatus` enum |
| Donors | `donors.index`, `donors.show` | Create, Edit; Delete disabled when agreements exist | `donor_proposal.donor_id` RESTRICTs |
| Funding Agreements | `funding-agreements.index`, `.show` | Create, Edit | No delete: cascades to `transfers_tracking` |
| Transfers | `transfers.index`, agreement panel | Create, Edit | No delete; money records do not disappear |
| Accounts | `admin.accounts.index` | Create, Edit, Close | No delete (`account_id` RESTRICTs); uses `Account::close()` |
| Results | `results.index`, Program panel | Create, Edit, Delete (leaf only) | `parent_id` cascades |
| Indicators | `indicators.index` | Create, Edit; Delete (untargeted only) | Cascades through plan_indicators into reports |
| Targets | `targets.index`, Annual Plan and Proposal panels | Create, Edit; Delete (unreported only) | Cascades to reports and report_files |
| Reports | `reports.index`, `reports.show` | Create, Edit; Approve / Seen-by-MEAL / Lock-editing | No delete; the three booleans and their date columns are real workflow |
| Report files | Report detail panel | None | No files on disk |
| Strategy detail | `strategies.show` | Read | Completes an existing hub |
| Program detail | `programs.show` | Read | Completes an existing hub |
| Annual Plan detail | `yearly-plans.show` | Read | Completes an existing hub; hosts the Targets panel |

Thirteen index pages, nine detail pages.

### Workflow actions that are not plain edits

- **Proposal status change** writes `proposals.status` and appends a
  `proposal_status_notes` row in one transaction. The note's `status` is the new
  status. The derived phase updates as a consequence and is never written.
- **Report approval** sets `is_approved` with `approved_date`; **mark seen by
  MEAL** sets `is_seen_by_meal`; **lock/unlock editing** sets `is_editable` with
  `edited_date`. Each is a separate explicit action, not a bare checkbox, so the
  paired date column can never drift from its boolean.
- **Account close** calls the existing `Account::close()`, which `forceFill`s
  because `closed_at` sits outside `#[Fillable]`.

## Edge cases the pages must handle

Each of these exists in the schema today and will be exercised by a test.

- `tasks.milestone_id` is nullable and `nullOnDelete`. The Tasks index groups
  and filters with an explicit "No milestone" option; the row markup never
  assumes `$task->milestone` is non-null.
- `results.program_id` is nullable and `nullOnDelete`. A program-less result
  still lists, under "Unassigned".
- `plan_indicators.project_id` is nullable. A target with no project renders as
  organisation-wide, not as a blank cell.
- A `plan_indicators` row whose source was orphaned renders "source no longer
  available".
- `Account` mirrors its `is_active` default in `protected $attributes`; the same
  trap applies to `Proposal::$attributes['status']`. Pages read these attributes
  freely, but any new query with a narrowed `select()` must include a column it
  intends to read, or strict mode raises `MissingAttributeException`.
- Every currency-bearing list shows per-currency subtotals. No view sums across
  currencies.
- Empty states everywhere, with different copy for "nothing exists yet" and
  "nothing matches these filters", matching the Programs page.

## Testing

Per `CLAUDE.md`, every page ships with tests. Per `.ai/rules/tests.md`, tests
that assert database-level behaviour read back through the query builder, while
tests under `tests/Feature/Models/**` use models directly.

These are UI tests. They follow the existing page-test convention —
`tests/Feature/<Feature>Test.php` at the suite root (`ProgramManagementTest`,
`YearlyPlanMatrixTest`, `ExecutiveSummaryTest`), with `tests/Feature/Admin/` for
admin pages. No new `tests/Feature/Pages/` directory is introduced.

The established idiom, copied from `ProgramManagementTest.php`: a `$this->get(route(...))`
test for guest redirect and per-role access, then `Livewire::actingAs($user)->test('pages::<area>.index')`
for filters and writes, reading persisted rows back with `Model::query()->where(...)->sole()`.
Each page gets:

1. **Renders** — the route returns 200 for a verified user and the page shows
   its seeded rows.
2. **Eager loading** — the page renders without raising
   `LazyLoadingViolationException`. Strict mode makes this automatic: a missed
   `with()` fails the render test rather than shipping an N+1.
3. **Filters** — each `#[Url]` filter narrows the result set and resets the page.
4. **Relations** — a detail page's panel shows that record's related rows and
   not another record's.
5. **Writes** — validation rejects bad input; a valid submit persists, asserted
   by reading the row back.
6. **Guards** — every "no delete" and "delete only when…" decision in the table
   above has a test proving the action is unavailable or refused. These are the
   tests that matter most; they are the difference between a guard and a
   comment.

Trees, the morph and the pivot each get a dedicated test: a cycle is rejected,
a target renders its source through the map key, an attach is idempotent.

Run with `php artisan test --compact`, narrowed by path while building.
`vendor/bin/pint --dirty --format agent` before finalising. `phpstan analyse`
covers `app/`, `bootstrap/app.php`, `config/`, `database/` and `routes/` — not
`tests/`, and per `.ai/rules/enums.md` that is not to change here.

## Risks

- **Volume.** Twenty-two pages is a large single pass, and the user has chosen
  to review at the end rather than per spine. The shared `x-ui.*` components are
  the mitigation: a wrong presentation decision is fixed in one file, not
  twenty-two.
- **Strict mode across many pages.** Mitigated by making the render test
  mandatory for every page — the violation is thrown, not silent.
- **Money.** The per-currency rule is stated once here and enforced by
  `x-ui.money` refusing to render a total without a currency.
- **Seeded data is thin.** Two strategies, four programs, eight projects. Some
  panels will look sparse. That is the data, not the page; empty and near-empty
  states are designed for rather than papered over.
