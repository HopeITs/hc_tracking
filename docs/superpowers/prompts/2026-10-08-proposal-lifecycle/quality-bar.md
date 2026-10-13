# Quality bar: code and UI/UX for every proposal-lifecycle task

Every prompt in this folder asks Claude to read this file before writing code. It is the minimum bar a PR must meet before Dev 1 (backend) or Dev 2 (UI) reviews it. Where a task prompt or a rule in `.ai/rules` is more specific, the more specific text wins.

## 1. Which text wins

1. The plan `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md` (Arabic) is authoritative. Inside a task section, the latest «تعديلات المراجعة N» subsection wins over older subsections and over the base text.
2. The decision log `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` overrides a plan default for any D-xx it records as decided. Its section 10 lists the conflicts (T-01…T-08) still to be settled at CP0.
3. Inside a prompt, the higher revision block wins: **Revision 9 > Revisions 6–8 > Revision 5 > Revision 4 > Revision 3 > Revision 2 > the base sections** (Scope, Business rules, Tests…). A revision block is listed above the base sections, and it still wins over them.
4. A conflict you cannot settle this way is never picked silently. List it under the open questions in plan mode.

## 2. Code quality

**Architecture**
- Business logic lives in `app/Actions/<Module>/*`: a `final` class with the same entry-point style as its sibling Actions. Livewire pages stay thin: they validate input, call the Action and render.
- Every Action calls `Gate::authorize(...)`, and every new model has a Policy. Hiding a button is never the protection.
- One rule has one definition. Shared rules live in one place and every page, scanner, export and listener calls it. Examples: `ProposalTransitions`, `TemplateItem::isDueIn()`, `PlanIndicator::achievedValue()` / `achievedValueSql()` / `pooledRatioSql()`, `MealCoverage::responsibleFor()`, `AgreementMoney`, `TransfersTracking::stateSql()`.
- Every closed set is an enum: TitleCase cases, lower-case stored values, labels in `lang/{en,ar}/enums.php`, and `match` without `default`.
- PHP style: `declare(strict_types=1);`, typed parameters and return types, constructor promotion, `#[Fillable]`, `casts()`, `CarbonImmutable`, PHPDoc array shapes, curly braces on every control structure. Prefer PHPDoc to inline comments.
- Reuse first. Use existing Actions, Support classes, components and factories states. Add no composer or npm dependency and no new base folder without approval.

**Data**
- Create migrations with `php artisan make:migration … --no-interaction`. Give indexes and foreign keys explicit names, write a working `down()`, run pre-checks before any DDL, and keep utf8mb4_unicode_ci / InnoDB DYNAMIC (`DatabaseEncodingTest`).
- A state change is one transaction that takes `lockForUpdate` on the parent row and carries an `expectedFrom` / `expectedStatus` guard. Events, notifications and queued jobs fire after commit only.
- Generated rows are idempotent: a unique index plus upsert, listeners safe to run twice, and commands with `--dry-run` that are safe to re-run.
- HR is read-only. Never write to the `hr` connection, never copy names or positions into tracking tables, and never select salary or contract columns.

**Performance**
- No N+1 queries. Eager-load, use `#[Computed]`, and where a rule says so, load once and filter in memory. Every new list, grid, tree or report page gets a fixed query-count test (pattern: `IndicatorDirectoryTest`).
- Paginate in memory after filtering when a list can exceed about 200 rows.

**Security**
- Never trust an id sent by Livewire. Load children through their parent (`$parent->children()->whereKey($id)`), and reject the whole batch when one id is foreign (BOLA).
- Hide money, keep names: skip the money query on the server for users without `funding-agreements.view`, `transfers.view` or `accounts.view`. `@can` alone is not enough. Notifications, emails, exports, activity changes and snapshots carry no amount for those users.
- Status, donor, actor and computed totals are never read from the request.
- Uploads go through `SecureUpload` (5 MB, allowed types) before encryption at rest (Task 22). Downloads go through the protected routes.
- User-facing error messages show no path, SQL or stack detail.

**Tests**
- Pest feature tests (`php artisan make:test --pest …`), factories with their states, `insertX()` + `DB::table()` reads where `.ai/rules/tests.md` asks for it.
- Cover: the happy path, every validation message, a 403 for each new action (a fresh Livewire instance per refused call), BOLA for each action that takes a child id, double submit and concurrency, idempotency of generators, and the translated label where it matters.
- After every step run the narrowest tests. Before reporting run `vendor/bin/pint --dirty --format agent` and `vendor/bin/phpstan analyse <touched paths>` (no new errors). Ask the developer to run the full suite.

## 3. UI/UX

**Components and page anatomy**
- Use Flux UI and the existing `x-ui.*` components before writing markup: `x-ui.page-header` (index pages), `x-ui.detail-header` (show pages), `x-ui.panel`, `x-ui.data-table`, `x-ui.stat-tile`, `x-ui.enum-badge` / `x-ui.status-pill`, `x-ui.empty-state`, `x-ui.confirm-modal`, `x-ui.form-section` and the other `x-ui.form-*` fields, `x-ui.employee-chip`, `x-ui.multi-select-popover`, `x-ui.header-filter-select` / `x-ui.header-filter-multiselect`, `x-ui.project-number`, `x-ui.money`, `x-ui.gantt`, `x-ui.logframe` / `x-ui.framework-node`, `x-ui.relation-tabs`, `x-ui.file-card`. Extend a component with an optional prop rather than copying it.
- Follow `.ai/rules/ui.md`, `pages.md`, `views.md`, `css.md`, `components-ui.md` and `navigation.md`. In short: actions in the header actions slot, header dropdowns through `x-ui.header-filter-select`, a ghost eye button opens a record, `flux:modal` bound to a boolean with `wire:model`, never the `@php(...)` shorthand.
- New pages get a sidebar entry in the right group, behind their view permission, and stable `data-tour="…"` anchors on the main regions so Task 26 can write the guided tour.

**Every screen has its states**
- Empty: one short sentence saying why it is empty and, only if the user may act, the button that fixes it.
- Loading: `wire:loading` with a scoped `wire:target` on the control that triggered the request. The control is disabled and shows a spinner, which also prevents double submit. No full-page spinner.
- Errors: a translated message next to each invalid field. Long forms and grids also show a summary at the top that points to the first invalid row. A "changed by someone else" conflict is explained in plain words with a reload action.
- Success: the existing Flux toast. After create, land on the new record or keep the user's context.
- Read-only: say why (for example «التقرير مُرسَل ومقفل») instead of greying controls out without explanation.
- Irreversible or high-impact actions (submit, approve, confirm, retire, delete, correct status) go through `x-ui.confirm-modal`, which states the consequence in one sentence.

**Forms and data-entry grids**
- Group fields with `x-ui.form-section`, mark required fields, pre-fill sensible defaults, and add a helper line under any non-obvious field (for example arrival vs receive date, D-31a).
- Grids: keyboard navigation (Tab / Shift+Tab across, Enter down), sticky header and first column, numbers right-aligned in an LTR run with `tabular-nums`, draft autosave on blur with a visible "Saving… / Saved" indicator, and totals computed on the server and shown read-only.
- Status is never shown by colour alone: a badge has text (and an icon where useful), and colours come from the status tokens.

**Language and direction**
- Every visible string lives in `lang/{en,ar}/<module>.php`, with no literal text in Blade. Stored enum values stay English; only labels are translated.
- RTL-safe markup: logical utilities (`ms-/me-/ps-/pe-/start-/end-`, `text-start`), direction-aware icons, `dir="auto"` on user-entered or mixed-language names, and codes such as `002.OP1.2.3`, numbers and dates isolated in LTR.
- Domain terms stay consistent with Task 26: المذكرة المفاهيمية، المقترح، الاتفاقية، الأثر، النتيجة، المُخرَج، النشاط، المستهدف، التحقق.

**Theme, accessibility and responsiveness**
- Light mode uses the approved tokens and dark mode uses only the Graphite tokens. No raw hex outside `app.css`, and red text is `text-status-critical` (or `dark:text-brand-text` for non-alarm emphasis).
- WCAG AA contrast in both themes, a label for every input, `aria-label` on icon-only buttons, visible focus, modals that trap focus and return it, and a tab order that follows reading order.
- Works at 375 px wide: tables scroll inside their panel (`overflow-x-auto`) with a sticky first column, or collapse to cards; action groups wrap; the page itself never scrolls sideways.

**Verify before reporting**
- Open every new or changed screen in the browser in Arabic (RTL) and English (LTR), light and dark, at desktop width and at 375 px, and fix what you see. If you cannot sign in, use the preview approach in `.ai/rules/pages.md`. List what you checked in the report.

## 4. Before you report

- [ ] Every rule, validation message and permission in the plan section (latest revision) is implemented on the server, with a translated message.
- [ ] New and changed screens have empty, loading, error and read-only states and were checked in both languages, both themes and at phone width.
- [ ] Tests cover success, validation, 403, BOLA and concurrency for each new action; the query-count test exists for heavy pages.
- [ ] Pint is clean and PHPStan reports no new errors on touched files.
- [ ] Events the task fires are in the Task 21 catalog, and every new model records activity (`TracksActivity`).
- [ ] Durable rules or traps are recorded with Boost `record-rule`.
- [ ] The report lists the handoff facts the next task needs (names, signatures, payloads, routes).
