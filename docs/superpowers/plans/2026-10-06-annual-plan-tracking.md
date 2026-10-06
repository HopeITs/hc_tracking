# Annual Plan Tracking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the annual plan from a "program + year + manager" record into a trackable plan. It has a dynamic period and an activity × month grid. The PM drafts it, MEAL reviews it and returns or approves it, and MEAL tracks the actual layer month by month. At the end, planned vs actual is compared, MEAL holds a final review and then closes the plan. Every phase and change is logged and notified.

**Architecture:** We keep the `annual_plans` table and the `AnnualPlan` model, and extend them with scope (program-wide or one project), a month period (`starts_on`/`ends_on`, with `year` kept in sync as the start year) and a `status` workflow. New tables hold:
- the activity catalog
- each plan's activities
- the planned months (the X marks, frozen once the plan leaves Draft)
- the actual occurrences (reported, verified, postponed, missed; per project when a program-wide activity is tracked per project)
- a durable plan event journal

All writes go through small `app/Actions/AnnualPlans/*` classes, following the existing `app/Actions/Reports/*` style: one `handle()`, `Gate::authorize`, a transaction with a row lock for status changes. Each write records a journal row plus an `annual_plan.*` activity, and that activity is what triggers notifications. The UI stays on the existing ⚡ single-file Livewire pages: the matrix overview (index) and a tabbed plan workspace (show), built from new `x-plans.*` Blade components.

**Tech Stack:** Laravel 13, PHP 8.5 (`.\php.cmd`), Livewire 4 single-file pages, Flux (flux-lite), Tailwind v4 (CSS-first), Pest, Larastan, MariaDB/MySQL (`hc_tracking_test`), HR DB on in-memory SQLite in tests.

## Global Constraints

- PHP commands always run through the project wrapper: `.\php.cmd artisan …`, `.\php.cmd vendor/bin/pest …`. Never bare `php` (that gets XAMPP 8.4).
- **Never commit.** Every task ends by staging its files with `git add` and stopping for the user's review. The user reviews every file before it enters history.
- **Never run the full test suite.** It takes about 10 minutes. Run only the files named in each task. At the very end, ask the user to run `.\php.cmd artisan test --compact` themselves.
- After any PHP edit: `.\php.cmd vendor/bin/pint --dirty --format agent`.
- Every new PHP file starts with `declare(strict_types=1);`. Models use `#[Fillable([...])]` and `protected function casts(): array`. Dates are `CarbonImmutable`.
- Curly braces on every control structure. Explicit return types and param types everywhere. PHPDoc array shapes.
- Every visible string goes through `__('group.key')` with the key present in **both** `lang/en/<group>.php` and `lang/ar/<group>.php`. Enum labels go through `HasTranslatedLabel` in `lang/*/enums.php`. Never create a lang group named after a word Flux translates bare (`results`, `of`, `to`, `files`, `showing`).
- Blade: never write `@php(...)` single-line. Always use the block form `@php … @endphp`.
- Modals bind `wire:model` to a boolean, never `:open`.
- Header filter dropdowns use `x-ui.header-filter-select`. Actions go in the `actions` slot, never in `filters`.
- No raw hex in views. Use only approved tokens (`primary`, `secondary-*`, `status-good/-warn/-critical(+soft)`, `board*`, Graphite `surface-*`/`ink*`/`line*` behind `dark:`). Red text uses `text-status-critical`. Light mode stays pixel-identical when restyling dark.
- No colour may carry meaning alone. Every grid state also differs in **shape** (ring, half, filled, dashed, ✕, diamond, arrow).
- HR is read-only. Employee ids (`manager_id`, `responsible_employee_id`) have **no foreign key**.
- `Model::shouldBeStrict()` is on: eager-load everything a page reads. Mirror DB defaults in `$attributes` when a factory omits the column.
- Factories omit default-bearing columns. Derived columns inside states are per-attribute closures.
- Morph keys must be registered in `AppServiceProvider::configureMorphMap()` **and** in `tests/Feature/Models/MorphMapTest.php`.
- Seeders and bulk syncs write under `Activity::withoutRecording()`.
- Show routes bind by slug. Always pass the model to `route()`.
- No new composer/npm dependencies.
- Before editing any file, open `.ai/rules/index.md`, read every rule file whose glob matches, and `grep -rin '<keyword>' .ai/rules`.

## Decisions this plan locks in (confirm before Task 1)

The user approved the concept (mockup at https://claude.ai/artifact/5ERRbMK9aYXP1p9Es5VDgM). These smaller points are the plan's defaults. **Ask the user to confirm or change each one before starting.** Every task below assumes the default.

| # | Decision | Default in this plan |
|---|---|---|
| D1 | Table/route name | Keep `annual_plans`, `/annual-plans`, `AnnualPlan`. "Annual" stays the product name even for 3-month plans. |
| D2 | `year` column | Kept. Always equals the **start** month's year (set automatically). A plan crossing years (Oct 2027 → Mar 2028) shows under 2027 in the matrix. |
| D3 | Existing plans | Migrated to Jan → Dec of their year, status **Active**, approved at their `created_at`. They have no activities until someone adds them. |
| D4 | "Whole plan" target granularity | New `Period::Plan` case (`'plan'`, label "Whole plan"), offered only on annual-plan targets and hidden from the results checkout-period picker. |
| D5 | Who is "PM" | The plan's `manager_id` (HR employee). For a project plan, the form preselects the project owner. Program managers of the plan's program and `annual-plans.update` holders act as PM too (same as today's policy). |
| D6 | MEAL permission | New permission `annual-plans.review`, granted to the MEAL role (General Manager and Super Admin get it automatically). It covers return, approve, cancel, targets, verify, missed, final review and close. |
| D7 | Milestones from activities | MEAL may create a milestone from a **project-plan** activity without holding `milestones.create`. The `annual-plans.review` ability is enough. Program-wide plans offer targets only. |
| D8 | Final review start | Opens automatically on the first day after `ends_on` (daily command). MEAL may also open it early by hand. |
| D9 | Cancel | MEAL may cancel a Submitted or Active plan with a reason. A cancelled plan frees its period, so a new plan for the same scope can be created. |
| D10 | Per-project tracking | A program-wide activity set to "per project" tracks every project of the program that is not `closed` (plus any project that already has a record). |
| D11 | Evidence | A month report may carry files (attendance sheet, photos), stored as `Media` in the `evidence` collection on the occurrence. Same 5 MB / `FileType` / `SecureUpload` rules as project files. |
| D12 | Period limits | Start ≤ end, at most **24 months**, whole months only. |
| D13 | Deleting a plan | Only while **Draft** or **Cancelled** (and only with `annual-plans.delete`). |
| D14 | Catalog names | Not unique (house rule for `name` columns). The add form warns when an active activity with the same name exists. |
| D15 | Closing while reports wait | Closing is refused while any month report still awaits MEAL verification. |
| D16 | Carry postponed months into the next plan | **Not in this release.** The final review lists them. A later plan can add them by hand. (Remove from scope unless the user asks.) |
| D17 | Tests whose premise this feature removes | `AnnualPlanCrudTest` "a program cannot hold two plans for the same fiscal year" and the `planYear` form tests describe the old one-plan-per-year rule. Task 24 **rewrites** them for the month form and the overlap rule. Get the user's OK before touching them (house rule: tests are not deleted without approval). |

## Domain vocabulary (read once)

| Term | Meaning | Where |
|---|---|---|
| Plan | One `annual_plans` row. Scope = program-wide (`project_id` NULL) or one project. | `AnnualPlan` |
| Phase / status | `draft → submitted → active → final review → closed` (+ `cancelled`) | `PlanStatus` |
| Catalog activity | A reusable activity name ("Beginner training") | `CatalogActivity` |
| Plan activity | A catalog activity placed in one plan (row of the grid) | `PlanActivity` |
| Planned month | An X: the activity is planned in that month | `PlanActivityMonth` |
| Occurrence | What actually happened for (activity, month[, project]) | `PlanOccurrence` |
| Unplanned | Occurrence in a month with no planned X, or on an activity added after approval (`is_unplanned`) | derived / `plan_activities.is_unplanned` |
| Shifted | A postponed planned month whose moved-to month was done | derived |
| Journal | Durable history of every phase move and tracking action | `AnnualPlanEvent` |

## State machine

```
            submit (PM)                 approve (MEAL)          ends_on passed (system) / open review (MEAL)        close (MEAL)
  DRAFT ───────────────────▶ SUBMITTED ───────────────▶ ACTIVE ─────────────────────────────────────────▶ FINAL REVIEW ───────────▶ CLOSED
    ▲                            │                         │
    └──── return (MEAL, reason) ─┘                         │
                 SUBMITTED ── cancel (MEAL, reason) ──▶ CANCELLED ◀── cancel (MEAL, reason) ── ACTIVE
```

What each role may do in each status:

| Status | PM (plan owner) | MEAL (`annual-plans.review`) | Everyone with `annual-plans.view` |
|---|---|---|---|
| Draft | edit period/manager, add/remove activities, toggle months, set responsible + tracking mode, submit, delete (with `annual-plans.delete`) | read | read |
| Submitted | read | add targets/milestones, return (reason), approve, cancel | read |
| Active | report done/postponed for months ≤ current, report unplanned, add unplanned activity, attach evidence | verify, send back, mark missed, record unplanned, add unplanned activity, targets/milestones, open final review early, cancel | read |
| Final review | read | verify, send back, mark missed, record unplanned, targets, close (notes) | read |
| Closed / Cancelled | read | read | read (delete Cancelled with `annual-plans.delete`) |

## Cell outcomes (one vocabulary for grid, review and scanner)

`PlanCellOutcome` values and shapes (CSS class `plan-mark--<value-with-dashes>`):

| Outcome | When | Shape |
|---|---|---|
| `none` | not planned, nothing happened | tiny grey dot |
| `upcoming` | planned (or moved in), month after the current month | hollow ring |
| `due` | planned (or moved in), current or past month, nothing reported, **or** a report MEAL sent back | hollow ring + red outline (`status-critical`) |
| `pending` | a report awaits MEAL verification | half-filled circle |
| `on plan` | planned month, verified | filled circle (`status-good`) |
| `shifted` | planned month postponed **and** the moved-to month verified; also drawn on the moved-to month | filled circle + arrow glyph `→` |
| `postponed` | planned month postponed, moved-to month not yet verified | dashed ring (`status-warn`) |
| `missed` | MEAL marked missed | ✕ (`status-critical`) |
| `unplanned` | verified occurrence in a month that was not planned (and not moved in) | filled diamond |

Severity for collapsing per-project cells into one activity cell (worst first):
`missed > due > postponed > pending > upcoming > shifted > on plan > unplanned > none`.

Review totals count **planned cells only** for `on plan / shifted / postponed / missed / due (not reported) / pending`, plus `unplanned` for verified unplanned cells. Match rate = `on plan ÷ planned cells`.

---

## File structure

### New files

| Path | Responsibility |
|---|---|
| `app/Enums/PlanStatus.php` | Plan phases + helpers (`isDraft()`, `allowsTracking()`, `railIndex()`, `values()`) |
| `app/Enums/PlanActivityTracking.php` | `shared` / `per project` |
| `app/Enums/OccurrenceStatus.php` | `reported`, `verified`, `postponed`, `missed`, `sent back` |
| `app/Enums/PlanEventAction.php` | Journal actions |
| `app/Enums/PlanCellOutcome.php` | Derived cell outcomes + `severity()` |
| `database/migrations/2026_10_07_100000_add_tracking_columns_to_annual_plans_table.php` | Scope, period, status, workflow stamps |
| `database/migrations/2026_10_07_100100_create_catalog_activities_table.php` | Catalog |
| `database/migrations/2026_10_07_100200_create_plan_activities_table.php` | Plan rows |
| `database/migrations/2026_10_07_100300_create_plan_activity_months_table.php` | Planned Xs |
| `database/migrations/2026_10_07_100400_create_plan_occurrences_table.php` | Actual layer |
| `database/migrations/2026_10_07_100500_create_annual_plan_events_table.php` | Journal |
| `database/migrations/2026_10_07_100600_add_plan_activity_id_to_plan_indicators_and_milestones.php` | Targets/milestones per activity |
| `database/migrations/2026_10_07_100700_add_annual_plans_review_permission.php` | Grants `annual-plans.review` to MEAL |
| `app/Models/CatalogActivity.php`, `PlanActivity.php`, `PlanActivityMonth.php`, `PlanOccurrence.php`, `AnnualPlanEvent.php` | Models |
| `database/factories/CatalogActivityFactory.php`, `PlanActivityFactory.php`, `PlanActivityMonthFactory.php`, `PlanOccurrenceFactory.php`, `AnnualPlanEventFactory.php` | Factories |
| `app/Support/Plans/PlanPeriod.php` | Month maths and month keys (`2027-09`) |
| `app/Support/Plans/PlanJournal.php` | Writes journal row + activity in one call |
| `app/Support/Plans/PlanComparison.php` | Pure outcome/total logic |
| `app/Support/Plans/PlanBoard.php` | Loads a plan in fixed queries and shapes grid rows |
| `app/Support/Plans/PlanSchedule.php` | Overlap / length / project-in-program validation |
| `app/Actions/AnnualPlans/SavePlanDetails.php` | Create/edit scope, period, manager (Draft only) |
| `app/Actions/AnnualPlans/AddPlanActivity.php`, `RemovePlanActivity.php`, `TogglePlannedMonth.php`, `UpdatePlanActivity.php` | Planned layer |
| `app/Actions/AnnualPlans/SubmitPlan.php`, `ReturnPlanForEdits.php`, `ApprovePlan.php`, `OpenFinalReview.php`, `ClosePlan.php`, `CancelPlan.php` | Workflow |
| `app/Actions/AnnualPlans/ReportOccurrence.php`, `PostponeOccurrence.php`, `VerifyOccurrence.php`, `SendOccurrenceBack.php`, `MarkOccurrenceMissed.php`, `RecordUnplannedOccurrence.php`, `AddUnplannedActivity.php`, `SaveOccurrenceEvidence.php` | Actual layer |
| `app/Actions/AnnualPlans/SetActivityTarget.php`, `SetActivityMilestone.php` | MEAL targets / milestones |
| `app/Actions/AnnualPlans/SaveCatalogActivity.php` | Catalog add/rename/deactivate |
| `app/Policies/CatalogActivityPolicy.php` | Who may manage the catalog |
| `app/Notifications/Recipients/AnnualPlanRecipients.php` | Who hears about plan events |
| `app/Notifications/Alerts/PlanTrackingScanner.php` | Month-end reminders, overdue, awaiting verification |
| `app/Console/Commands/OpenPlanFinalReviews.php` | Daily: Active → Final review after `ends_on` |
| `app/Livewire/Concerns/ManagesPlanWorkflow.php`, `EditsPlannedLayer.php`, `TracksActualLayer.php`, `ManagesPlanTargets.php` | Show-page behaviour, one trait per tab |
| `tests/PHPStan/ManagesPlanWorkflowHost.php` (+ 3 more hosts) | PHPStan hosts for the traits |
| `resources/views/components/plans/phase-rail.blade.php` | Dot-thread phase rail |
| `resources/views/components/plans/month-grid.blade.php` | Activity × month grid (planned / actual / review modes) |
| `resources/views/components/plans/mark.blade.php` | One cell mark (shape per outcome) |
| `resources/views/components/plans/legend.blade.php` | Shape legend |
| `resources/views/pages/annual-plans/_overview-tab.blade.php` | Today's show-page panels, moved |
| `resources/views/pages/annual-plans/_plan-tab.blade.php` | Planned layer editor |
| `resources/views/pages/annual-plans/_tracking-tab.blade.php` | Actual layer + cell panel |
| `resources/views/pages/annual-plans/_review-tab.blade.php` | Planned vs actual + close |
| `resources/views/pages/annual-plans/_projects-tab.blade.php` | Program plan: program-wide activities + nested project plans |
| `resources/views/pages/annual-plans/_workflow-modals.blade.php` | Return / cancel / close / target / milestone / catalog modals |
| `resources/views/pages/annual-plans/⚡catalog.blade.php` | Catalog page |
| `resources/css/plans.css` | Grid, marks, rail styles (`@layer components`) |
| `tests/Feature/AnnualPlans/*.php` | New tests (one file per task, names given per task) |

### Modified files

`app/Models/AnnualPlan.php`, `app/Models/PlanIndicator.php`, `app/Models/Milestone.php`, `app/Models/Project.php` (relation), `app/Enums/Period.php`, `app/Policies/AnnualPlanPolicy.php`, `app/Providers/AppServiceProvider.php` (morph map, policy), `app/Observers/MediaOwnerObserver.php`, `app/Observers/ProgramObserver.php` (no change, verify), `app/Notifications/ActivityParameters.php`, `app/Notifications/NotificationPresenter.php`, `app/Livewire/Concerns/ManagesReportForm.php`, `config/access-control.php`, `config/notifications.php`, `routes/web.php`, `routes/console.php`, `resources/css/app.css`, `resources/views/components/ui/enum-badge.blade.php`, `resources/views/pages/annual-plans/⚡index.blade.php`, `resources/views/pages/annual-plans/⚡show.blade.php`, `resources/views/pages/admin/⚡notification-settings.blade.php`, `resources/views/pages/{targets,reports,results,indicators,programs,executive-summary}/⚡*.blade.php` (labels/counts), `database/factories/AnnualPlanFactory.php`, `database/seeders/DatabaseSeeder.php` (+ new `PlanTrackingSeeder.php`), `lang/{en,ar}/{annual_plans,enums,notifications,common,titles,navigation}.php`, affected tests listed per task.

---

# Phase A: Data foundation

### Task 1: Enums and their labels

**Files:**
- Create: `app/Enums/PlanStatus.php`, `app/Enums/PlanActivityTracking.php`, `app/Enums/OccurrenceStatus.php`, `app/Enums/PlanEventAction.php`, `app/Enums/PlanCellOutcome.php`
- Modify: `app/Enums/Period.php`, `lang/en/enums.php`, `lang/ar/enums.php`, `resources/views/components/ui/enum-badge.blade.php`
- Test: `tests/Feature/PlanEnumsTest.php` (enum-contract tests live at the Feature root, per `.ai/rules/tests.md`)

**Interfaces:**
- Produces: `PlanStatus::{Draft,Submitted,Active,FinalReview,Closed,Cancelled}`, `PlanStatus::isDraft(): bool`, `PlanStatus::allowsTracking(): bool`, `PlanStatus::isOpen(): bool`, `PlanStatus::railIndex(): ?int`, `PlanStatus::values(): array<int,string>`; `PlanActivityTracking::{Shared,PerProject}`; `OccurrenceStatus::{Reported,Verified,Postponed,Missed}`; `PlanEventAction` cases (below); `PlanCellOutcome` cases + `severity(): int` + `cssModifier(): string`; `Period::Plan`, `Period::forResults(): list<Period>`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\OccurrenceStatus;
use App\Enums\Period;
use App\Enums\PlanActivityTracking;
use App\Enums\PlanCellOutcome;
use App\Enums\PlanEventAction;
use App\Enums\PlanStatus;

test('plan statuses are lower case and run in workflow order', function () {
    expect(PlanStatus::values())->toBe(['draft', 'submitted', 'active', 'final review', 'closed', 'cancelled']);
});

test('only draft is editable and only active and final review accept tracking', function () {
    expect(PlanStatus::Draft->isDraft())->toBeTrue()
        ->and(PlanStatus::Submitted->isDraft())->toBeFalse()
        ->and(PlanStatus::Active->allowsTracking())->toBeTrue()
        ->and(PlanStatus::FinalReview->allowsTracking())->toBeTrue()
        ->and(PlanStatus::Submitted->allowsTracking())->toBeFalse()
        ->and(PlanStatus::Closed->allowsTracking())->toBeFalse();
});

test('closed and cancelled plans are no longer open', function () {
    expect(PlanStatus::Closed->isOpen())->toBeFalse()
        ->and(PlanStatus::Cancelled->isOpen())->toBeFalse()
        ->and(PlanStatus::Active->isOpen())->toBeTrue();
});

test('the phase rail places every status except cancelled', function () {
    expect(array_map(fn (PlanStatus $status): ?int => $status->railIndex(), PlanStatus::cases()))
        ->toBe([0, 1, 2, 3, 4, null]);
});

test('worst outcome wins when cells are collapsed', function () {
    expect(PlanCellOutcome::Missed->severity())->toBeGreaterThan(PlanCellOutcome::Due->severity())
        ->and(PlanCellOutcome::Due->severity())->toBeGreaterThan(PlanCellOutcome::Postponed->severity())
        ->and(PlanCellOutcome::Postponed->severity())->toBeGreaterThan(PlanCellOutcome::Pending->severity())
        ->and(PlanCellOutcome::Pending->severity())->toBeGreaterThan(PlanCellOutcome::Upcoming->severity())
        ->and(PlanCellOutcome::Upcoming->severity())->toBeGreaterThan(PlanCellOutcome::Shifted->severity())
        ->and(PlanCellOutcome::Shifted->severity())->toBeGreaterThan(PlanCellOutcome::OnPlan->severity())
        ->and(PlanCellOutcome::OnPlan->severity())->toBeGreaterThan(PlanCellOutcome::Unplanned->severity())
        ->and(PlanCellOutcome::Unplanned->severity())->toBeGreaterThan(PlanCellOutcome::None->severity());
});

test('the whole-plan period exists but is not offered for results', function () {
    expect(Period::Plan->value)->toBe('plan')
        ->and(Period::forResults())->not->toContain(Period::Plan)
        ->and(Period::forResults())->toHaveCount(4);
});

test('every new enum case has an english and arabic label', function (string $enum) {
    foreach ($enum::cases() as $case) {
        app()->setLocale('en');
        $english = $case->label();
        app()->setLocale('ar');
        $arabic = $case->label();

        expect($english)->not->toStartWith('enums.')
            ->and($arabic)->not->toStartWith('enums.')
            ->and($arabic)->not->toBe($english);
    }
})->with([PlanStatus::class, PlanActivityTracking::class, OccurrenceStatus::class, PlanEventAction::class, PlanCellOutcome::class]);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/PlanEnumsTest.php`
Expected: FAIL, `Class "App\Enums\OccurrenceStatus" not found`.

- [ ] **Step 3: Create the enums**

`app/Enums/PlanStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where an annual plan is in its life.
 *
 * Draft is the project manager's own workspace. Leaving it freezes the planned
 * layer: from Submitted on, nobody edits planned months again. Active and Final
 * review accept tracking of what actually happened; Closed and Cancelled are
 * read-only. Values are lower case and casing is part of the value.
 */
enum PlanStatus: string
{
    use HasTranslatedLabel;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Active = 'active';
    case FinalReview = 'final review';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether the actual layer may still change.
     */
    public function allowsTracking(): bool
    {
        return $this === self::Active || $this === self::FinalReview;
    }

    /**
     * Whether the plan still holds its period against overlapping plans.
     */
    public function isOpen(): bool
    {
        return $this !== self::Closed && $this !== self::Cancelled;
    }

    /**
     * Position on the five-step phase rail; cancelled sits off the rail.
     */
    public function railIndex(): ?int
    {
        return match ($this) {
            self::Draft => 0,
            self::Submitted => 1,
            self::Active => 2,
            self::FinalReview => 3,
            self::Closed => 4,
            self::Cancelled => null,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

`app/Enums/PlanActivityTracking.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * How a program-wide activity is tracked: one status for every project, or
 * each project reporting its own. Project plans are always shared.
 */
enum PlanActivityTracking: string
{
    use HasTranslatedLabel;

    case Shared = 'shared';
    case PerProject = 'per project';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

`app/Enums/OccurrenceStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * The state of one actual-layer record (activity, month, project).
 *
 * Reported waits for MEAL; Verified and Missed are MEAL-settled. A PM's
 * Postponed takes effect at once and MEAL may send it back. Sent back keeps
 * the row (and its evidence files) while the PM corrects and re-reports.
 */
enum OccurrenceStatus: string
{
    use HasTranslatedLabel;

    case Reported = 'reported';
    case Verified = 'verified';
    case Postponed = 'postponed';
    case Missed = 'missed';
    case SentBack = 'sent back';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

`app/Enums/PlanEventAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * One entry kind in a plan's durable journal (annual_plan_events).
 *
 * The journal outlives the activity log, which is pruned after two years:
 * a return for edits must stay on record for the life of the plan.
 */
enum PlanEventAction: string
{
    use HasTranslatedLabel;

    case Created = 'created';
    case Submitted = 'submitted';
    case Returned = 'returned';
    case Approved = 'approved';
    case ReviewOpened = 'review opened';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
    case ActivityAdded = 'activity added';
    case ActivityRemoved = 'activity removed';
    case UnplannedActivityAdded = 'unplanned activity added';
    case Reported = 'reported';
    case Postponed = 'postponed';
    case Verified = 'verified';
    case SentBack = 'sent back';
    case Missed = 'missed';
    case UnplannedRecorded = 'unplanned recorded';
    case EvidenceAdded = 'evidence added';
    case TargetSet = 'target set';
    case MilestoneSet = 'milestone set';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

`app/Enums/PlanCellOutcome.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * What one grid cell means once planned months and occurrences are compared.
 *
 * Derived, never stored. App\Support\Plans\PlanComparison is the only place
 * that decides it, so the grid, the review totals and the reminders agree.
 */
enum PlanCellOutcome: string
{
    use HasTranslatedLabel;

    case None = 'none';
    case Upcoming = 'upcoming';
    case Due = 'due';
    case Pending = 'pending';
    case OnPlan = 'on plan';
    case Shifted = 'shifted';
    case Postponed = 'postponed';
    case Missed = 'missed';
    case Unplanned = 'unplanned';

    /**
     * Higher is worse; collapsing per-project cells keeps the worst.
     */
    public function severity(): int
    {
        return match ($this) {
            self::None => 0,
            self::Unplanned => 1,
            self::OnPlan => 2,
            self::Shifted => 3,
            self::Upcoming => 4,
            self::Pending => 5,
            self::Postponed => 6,
            self::Due => 7,
            self::Missed => 8,
        };
    }

    /**
     * The `plan-mark--*` CSS modifier for this outcome.
     */
    public function cssModifier(): string
    {
        return str_replace(' ', '-', $this->value);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

Modify `app/Enums/Period.php`: add the case after `Annual`, then add the helper below `values()`:

```php
    case Plan = 'plan';
```

```php
    /**
     * The periods a result may be checked over. "Whole plan" only means
     * something for a target committed in an annual plan.
     *
     * @return list<self>
     */
    public static function forResults(): array
    {
        return [self::Monthly, self::Quarterly, self::SemiAnnual, self::Annual];
    }
```

Then: `grep -rn "Period::cases()" resources app` and replace **only** the result-form pickers (results checkout period) with `Period::forResults()`. Target forms keep `Period::cases()`, but the proposal target form must also exclude `Plan`: use `array_filter(Period::cases(), fn (Period $period): bool => $period !== Period::Plan)` where the source is a proposal. List every replaced call site in the task's review note.

- [ ] **Step 4: Add labels (en and ar)**

In `lang/en/enums.php` add:

```php
    'plan_status' => [
        'draft' => 'Draft',
        'submitted' => 'With MEAL',
        'active' => 'Active',
        'final_review' => 'Final review',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
    ],
    'plan_activity_tracking' => [
        'shared' => 'Shared status',
        'per_project' => 'Per project',
    ],
    'occurrence_status' => [
        'reported' => 'Reported, awaiting MEAL',
        'verified' => 'Verified',
        'postponed' => 'Postponed',
        'missed' => 'Missed',
        'sent_back' => 'Sent back to the project manager',
    ],
    'plan_event_action' => [
        'created' => 'Created',
        'submitted' => 'Sent to MEAL',
        'returned' => 'Returned for edits',
        'approved' => 'Approved',
        'review_opened' => 'Final review opened',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
        'activity_added' => 'Activity added',
        'activity_removed' => 'Activity removed',
        'unplanned_activity_added' => 'Unplanned activity added',
        'reported' => 'Reported done',
        'postponed' => 'Postponed',
        'verified' => 'Verified',
        'sent_back' => 'Sent back',
        'missed' => 'Marked missed',
        'unplanned_recorded' => 'Unplanned activity recorded',
        'evidence_added' => 'Evidence added',
        'target_set' => 'Target set',
        'milestone_set' => 'Milestone set',
    ],
    'plan_cell_outcome' => [
        'none' => 'Not planned',
        'upcoming' => 'Planned',
        'due' => 'Due, not reported',
        'pending' => 'Awaiting MEAL',
        'on_plan' => 'On plan',
        'shifted' => 'Shifted',
        'postponed' => 'Postponed',
        'missed' => 'Missed',
        'unplanned' => 'Unplanned',
    ],
```

In the existing `'period'` array add `'plan' => 'Whole plan',`.

In `lang/ar/enums.php` add the same keys:

```php
    'plan_status' => [
        'draft' => 'مسودة',
        'submitted' => 'لدى قسم المتابعة والتقييم',
        'active' => 'نشطة',
        'final_review' => 'المراجعة النهائية',
        'closed' => 'مغلقة',
        'cancelled' => 'ملغاة',
    ],
    'plan_activity_tracking' => [
        'shared' => 'حالة مشتركة',
        'per_project' => 'لكل مشروع',
    ],
    'occurrence_status' => [
        'reported' => 'مُبلَّغ عنه، بانتظار المتابعة والتقييم',
        'verified' => 'تم التحقق',
        'postponed' => 'مؤجل',
        'missed' => 'لم يُنفَّذ',
        'sent_back' => 'أُعيد إلى مدير المشروع',
    ],
    'plan_event_action' => [
        'created' => 'أُنشئت',
        'submitted' => 'أُرسلت إلى المتابعة والتقييم',
        'returned' => 'أُعيدت للتعديل',
        'approved' => 'اعتُمدت',
        'review_opened' => 'فُتحت المراجعة النهائية',
        'closed' => 'أُغلقت',
        'cancelled' => 'أُلغيت',
        'activity_added' => 'أُضيف نشاط',
        'activity_removed' => 'أُزيل نشاط',
        'unplanned_activity_added' => 'أُضيف نشاط غير مخطط',
        'reported' => 'أُبلغ عن التنفيذ',
        'postponed' => 'أُجّل',
        'verified' => 'تم التحقق',
        'sent_back' => 'أُعيد للمراجعة',
        'missed' => 'سُجّل كغير منفّذ',
        'unplanned_recorded' => 'سُجّل نشاط غير مخطط',
        'evidence_added' => 'أُضيف دليل',
        'target_set' => 'حُدّد مستهدف',
        'milestone_set' => 'حُدّد معلم',
    ],
    'plan_cell_outcome' => [
        'none' => 'غير مخطط',
        'upcoming' => 'مخطط',
        'due' => 'مستحق ولم يُبلَّغ عنه',
        'pending' => 'بانتظار المتابعة والتقييم',
        'on_plan' => 'حسب الخطة',
        'shifted' => 'نُقل',
        'postponed' => 'مؤجل',
        'missed' => 'لم يُنفَّذ',
        'unplanned' => 'غير مخطط له',
    ],
```

and `'plan' => 'كامل الخطة',` in `'period'`.

> Arabic copy is a first pass. Flag it in the review note so the user (a native speaker) can correct wording before merge.

- [ ] **Step 5: Give the badge a tone for every new status-like enum**

In `resources/views/components/ui/enum-badge.blade.php`, inside the outer `match (true)`, before `default`, add exhaustive arms (no `default` inside them, per the enums rule):

```php
        $case instanceof \App\Enums\PlanStatus => match ($case) {
            \App\Enums\PlanStatus::Active => 'solid',
            \App\Enums\PlanStatus::Submitted, \App\Enums\PlanStatus::FinalReview => 'accent',
            \App\Enums\PlanStatus::Closed => 'good',
            \App\Enums\PlanStatus::Cancelled => 'muted',
            \App\Enums\PlanStatus::Draft => 'outline',
        },
        $case instanceof \App\Enums\OccurrenceStatus => match ($case) {
            \App\Enums\OccurrenceStatus::Verified => 'good',
            \App\Enums\OccurrenceStatus::Reported => 'solid',
            \App\Enums\OccurrenceStatus::Postponed => 'outline',
            \App\Enums\OccurrenceStatus::Missed, \App\Enums\OccurrenceStatus::SentBack => 'accent',
        },
        $case instanceof \App\Enums\PlanActivityTracking => 'outline',
```

(`good` and `muted` tones already exist; confirm by reading the `$toneClasses` match below the arms.)

- [ ] **Step 6: Run the tests**

Run: `.\php.cmd artisan test --compact tests/Feature/PlanEnumsTest.php tests/Feature/LocalizationTest.php tests/Feature/EnumCastTest.php`
Expected: PASS.

- [ ] **Step 7: Format, stage, stop for review**

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add app/Enums lang/en/enums.php lang/ar/enums.php resources/views/components/ui/enum-badge.blade.php tests/Feature/PlanEnumsTest.php
```

Stop. Tell the user which files are staged and that the Arabic labels need their check. Do not commit.

---

### Task 2: Extend `annual_plans` (scope, period, status, workflow stamps)

**Files:**
- Create: `database/migrations/2026_10_07_100000_add_tracking_columns_to_annual_plans_table.php`
- Modify: `app/Models/AnnualPlan.php`, `app/Models/Project.php`, `database/factories/AnnualPlanFactory.php`
- Test: `tests/Feature/AnnualPlans/AnnualPlanSchemaTest.php`, update `tests/Feature/Models/AnnualPlanTest.php`

**Interfaces:**
- Consumes: `PlanStatus` (Task 1).
- Produces on `AnnualPlan`:
  - columns `project_id ?int`, `starts_on CarbonImmutable`, `ends_on CarbonImmutable`, `status PlanStatus`, `submitted_at`, `approved_at`, `approved_by ?int`, `review_opened_at`, `closed_at`, `closed_by ?int`, `closing_notes ?string`, `cancelled_at`, `cancelled_by ?int`, `cancel_reason ?string`
  - `project(): BelongsTo<Project>`, `approver()`, `closer()`, `canceller()`: `BelongsTo<User>`
  - `isProgramWide(): bool`, `isCalendarYear(): bool`
  - `periodLabel(): string`: "Jan 2027 – Jun 2027", or "2027" for a full calendar year
  - `fiscalLabel(): string`: "FY 2027" for a calendar year, else `periodLabel()`
  - `label(): string`: "Program · Project · period"
  - scopes `programWide()`, `forProject(int)`, `open()`, `overlapping(CarbonImmutable $start, CarbonImmutable $end)`
  - `starts_on` mutator keeps `year` in sync
  - `Project::annualPlans(): HasMany<AnnualPlan>`
  - factory states `forProject(Project|int)`, `between(string $startMonth, string $endMonth)`, `submitted()`, `active()`, `finalReview()`, `closed()`, `cancelled()`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AnnualPlans/AnnualPlanSchemaTest.php`:

```php
<?php

use App\Enums\PlanStatus;
use App\Models\AnnualPlan;
use App\Models\Program;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('a plan defaults to draft in the database', function () {
    $programId = Program::factory()->create()->id;

    $id = DB::table('annual_plans')->insertGetId([
        'program_id' => $programId,
        'manager_id' => insertHrEmployee(),
        'year' => 2027,
        'starts_on' => '2027-01-01',
        'ends_on' => '2027-12-31',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('annual_plans')->find($id)->status)->toBe('draft');
});

test('a program may hold more than one plan in the same year', function () {
    $program = Program::factory()->create();

    AnnualPlan::factory()->between('2027-01', '2027-06')->create(['program_id' => $program->id]);
    AnnualPlan::factory()->between('2027-07', '2027-12')->create(['program_id' => $program->id]);

    expect(AnnualPlan::query()->where('program_id', $program->id)->where('year', 2027)->count())->toBe(2);
});

test('deleting a project removes its project plans', function () {
    $project = Project::factory()->create();
    $plan = AnnualPlan::factory()->forProject($project)->create();

    $project->delete();

    expect(AnnualPlan::query()->find($plan->id))->toBeNull();
});

test('the tracking columns exist with the expected nullability', function () {
    $columns = collect(Schema::getColumns('annual_plans'))->keyBy('name');

    expect($columns['starts_on']['nullable'])->toBeFalse()
        ->and($columns['ends_on']['nullable'])->toBeFalse()
        ->and($columns['project_id']['nullable'])->toBeTrue()
        ->and($columns['closing_notes']['nullable'])->toBeTrue()
        ->and($columns['cancel_reason']['nullable'])->toBeTrue();
});
```

Append to `tests/Feature/Models/AnnualPlanTest.php`:

```php
test('setting the start month keeps the fiscal year in step', function () {
    $plan = AnnualPlan::factory()->between('2027-10', '2028-03')->create();

    expect($plan->year)->toBe(2027)
        ->and($plan->starts_on->toDateString())->toBe('2027-10-01')
        ->and($plan->ends_on->toDateString())->toBe('2028-03-31');
});

test('a plan factory with only a year covers that calendar year', function () {
    $plan = AnnualPlan::factory()->create(['year' => 2026]);

    expect($plan->starts_on->toDateString())->toBe('2026-01-01')
        ->and($plan->ends_on->toDateString())->toBe('2026-12-31')
        ->and($plan->isCalendarYear())->toBeTrue()
        ->and($plan->periodLabel())->toBe('2026')
        ->and($plan->fiscalLabel())->toBe(__('common.fy_year', ['year' => 2026]));
});

test('a partial-year plan labels its months', function () {
    $plan = AnnualPlan::factory()->between('2027-04', '2027-09')->create();

    expect($plan->isCalendarYear())->toBeFalse()
        ->and($plan->periodLabel())->toBe('Apr 2027 – Sep 2027')
        ->and($plan->fiscalLabel())->toBe('Apr 2027 – Sep 2027');
});

test('a new plan reads as draft before it is refreshed', function () {
    expect(AnnualPlan::factory()->make()->status)->toBe(PlanStatus::Draft);
});

test('overlapping finds plans sharing at least one month', function () {
    $plan = AnnualPlan::factory()->between('2027-03', '2027-08')->create();

    expect(AnnualPlan::query()->overlapping(CarbonImmutable::parse('2027-08-01'), CarbonImmutable::parse('2027-12-31'))->pluck('id')->all())->toBe([$plan->id])
        ->and(AnnualPlan::query()->overlapping(CarbonImmutable::parse('2027-09-01'), CarbonImmutable::parse('2027-12-31'))->exists())->toBeFalse();
});

test('open excludes closed and cancelled plans', function () {
    $active = AnnualPlan::factory()->active()->create();
    AnnualPlan::factory()->closed()->create();
    AnnualPlan::factory()->cancelled()->create();

    expect(AnnualPlan::query()->open()->pluck('id')->all())->toBe([$active->id]);
});

test('a project plan labels its program, project and period', function () {
    $project = Project::factory()->create(['name' => 'Skills Hub A']);
    $plan = AnnualPlan::factory()->forProject($project)->between('2027-01', '2027-12')->create();

    expect($plan->isProgramWide())->toBeFalse()
        ->and($plan->label())->toBe($project->program->name.' · Skills Hub A · 2027');
});
```

(add `use App\Enums\PlanStatus; use App\Models\Project; use Carbon\CarbonImmutable;` at the top of that file if missing.)

- [ ] **Step 2: Run them to verify they fail**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/AnnualPlanSchemaTest.php tests/Feature/Models/AnnualPlanTest.php`
Expected: FAIL, unknown column `starts_on`.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Annual plans become trackable: a scope (program-wide or one project), a
 * month period chosen by the manager, and a workflow status with the stamps
 * of who moved it and when.
 *
 * `year` stays and always equals the start month's year (the model keeps it
 * in step), so the programs x years matrix and every "plans of year X" read
 * keep working. The (program_id, year) unique goes: a program may now hold a
 * program-wide plan and several project plans in one year, and two short
 * plans back to back. Overlap is refused in application code
 * (App\Support\Plans\PlanSchedule), because "overlapping months for the same
 * scope while not cancelled or closed" is not expressible as an index.
 *
 * Existing rows become Jan-Dec plans of their year, already active (D3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('annual_plans', function (Blueprint $table) {
            $table->dropUnique('annual_plans_program_id_year_unique');
        });

        Schema::table('annual_plans', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('program_id')->constrained('projects')->cascadeOnDelete();
            $table->date('starts_on')->nullable()->after('year');
            $table->date('ends_on')->nullable()->after('starts_on');
            $table->string('status', 50)->default('draft')->after('ends_on');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('review_opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('closing_notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();

            $table->index(['program_id', 'starts_on']);
            $table->index(['project_id', 'starts_on']);
            $table->index('status');
        });

        DB::table('annual_plans')->update([
            'starts_on' => DB::raw("CONCAT(year, '-01-01')"),
            'ends_on' => DB::raw("CONCAT(year, '-12-31')"),
            'status' => 'active',
            'approved_at' => DB::raw('created_at'),
        ]);

        Schema::table('annual_plans', function (Blueprint $table) {
            $table->date('starts_on')->nullable(false)->change();
            $table->date('ends_on')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('annual_plans', function (Blueprint $table) {
            $table->dropIndex(['program_id', 'starts_on']);
            $table->dropIndex(['project_id', 'starts_on']);
            $table->dropIndex(['status']);
            $table->dropConstrainedForeignId('project_id');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['starts_on', 'ends_on', 'status', 'submitted_at', 'approved_at', 'review_opened_at', 'closed_at', 'closing_notes', 'cancelled_at', 'cancel_reason']);
        });

        // Restoring the unique fails once a program holds two plans in a year;
        // that is the honest outcome of rolling back past this feature.
        Schema::table('annual_plans', function (Blueprint $table) {
            $table->unique(['program_id', 'year']);
        });
    }
};
```

> The `->change()` on MariaDB keeps the column's charset/collation. The `DatabaseEncodingTest` covers drift. Run it in Step 6.

- [ ] **Step 4: Update the model**

In `app/Models/AnnualPlan.php`:

1. Extend the docblock `@property` list with every new column (`int|null $project_id`, `CarbonImmutable $starts_on`, `CarbonImmutable $ends_on`, `PlanStatus $status`, `CarbonImmutable|null $submitted_at`, `CarbonImmutable|null $approved_at`, `int|null $approved_by`, `CarbonImmutable|null $review_opened_at`, `CarbonImmutable|null $closed_at`, `int|null $closed_by`, `string|null $closing_notes`, `CarbonImmutable|null $cancelled_at`, `int|null $cancelled_by`, `string|null $cancel_reason`).
2. Replace the `#[Fillable]` with:

```php
#[Fillable(['program_id', 'project_id', 'manager_id', 'year', 'starts_on', 'ends_on'])]
```

(Workflow columns are written with `forceFill` by the workflow actions only, never mass-assigned from a form.)

3. Add below the class opening:

```php
    /**
     * Mirrors the column default, so a plan made in memory (factory make(),
     * a form before save) reads as a draft instead of tripping strict mode.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'draft'];
```

4. Add the mutator that keeps `year` in step (an attribute mutator runs even when model events are muted, unlike a `saving` listener):

```php
    /**
     * The first month of the plan. Writing it also writes `year`, so the
     * fiscal-year reads (matrix, executive summary) always agree with it.
     *
     * @return Attribute<CarbonImmutable, CarbonInterface|string>
     */
    protected function startsOn(): Attribute
    {
        return Attribute::make(
            set: function (CarbonInterface|string $value): array {
                $start = CarbonImmutable::parse($value)->startOfMonth();

                return ['starts_on' => $start->toDateString(), 'year' => $start->year];
            },
        );
    }

    /**
     * The last day of the plan's final month.
     *
     * @return Attribute<CarbonImmutable, CarbonInterface|string>
     */
    protected function endsOn(): Attribute
    {
        return Attribute::make(
            set: fn (CarbonInterface|string $value): string => CarbonImmutable::parse($value)->endOfMonth()->toDateString(),
        );
    }
```

(imports: `Illuminate\Database\Eloquent\Casts\Attribute`, `Carbon\CarbonInterface`.) The casts still return `CarbonImmutable` on read.

5. Add relations, helpers and scopes:

```php
    /**
     * The project this plan covers, or null for a program-wide plan.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isProgramWide(): bool
    {
        return $this->project_id === null;
    }

    /**
     * Whether the plan runs exactly January to December of one year.
     */
    public function isCalendarYear(): bool
    {
        return $this->starts_on->month === 1
            && $this->ends_on->month === 12
            && $this->starts_on->year === $this->ends_on->year;
    }

    /**
     * "2027" for a calendar-year plan, else "Apr 2027 – Sep 2027".
     */
    public function periodLabel(): string
    {
        if ($this->isCalendarYear()) {
            return (string) $this->starts_on->year;
        }

        $pattern = __('formats.month_year');

        return $this->starts_on->translatedFormat($pattern).' – '.$this->ends_on->translatedFormat($pattern);
    }

    /**
     * "FY 2027" for a calendar-year plan, else the month range. What target,
     * report and indicator pages print as a plan source.
     */
    public function fiscalLabel(): string
    {
        return $this->isCalendarYear()
            ? __('common.fy_year', ['year' => $this->starts_on->year])
            : $this->periodLabel();
    }

    /**
     * "Program · Project · period", or "Program · period" when program-wide.
     */
    public function label(): string
    {
        return implode(' · ', array_filter([
            $this->program?->name,
            $this->project_id === null ? null : $this->project?->name,
            $this->periodLabel(),
        ]));
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function programWide(Builder $query): void
    {
        $query->whereNull('project_id');
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function forProject(Builder $query, int $projectId): void
    {
        $query->where('project_id', $projectId);
    }

    /**
     * Plans still holding their period: neither closed nor cancelled.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNotIn('status', [PlanStatus::Closed->value, PlanStatus::Cancelled->value]);
    }

    /**
     * Plans sharing at least one month with [start, end].
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function overlapping(Builder $query, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $query->where('starts_on', '<=', $end->toDateString())
            ->where('ends_on', '>=', $start->toDateString());
    }
```

6. In `casts()` return:

```php
        return [
            'year' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => PlanStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'review_opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
```

7. Change `slugSource()` so new slugs are unique per scope and month (existing slugs never regenerate):

```php
    protected function slugSource(): string
    {
        return implode('-', array_filter([
            $this->program?->slug,
            $this->project_id === null ? null : $this->project?->slug,
            $this->starts_on?->format('Y-m'),
        ]));
    }
```

8. Change `activityLabel()` to use `label()`, still via `activityAttribute` so a strict-mode partial select works:

```php
    public function activityLabel(): string
    {
        $plan = static::query()
            ->with(['program:id', 'project:id,name,slug'])
            ->find($this->getKey(), ['id', 'program_id', 'project_id', 'starts_on', 'ends_on']);

        return $plan?->label() ?? (string) $this->activityAttribute('year');
    }
```

9. In `activityEvents()` add the period fields so editing a draft's period is a meaningful "updated" event but status (workflow-owned) is not:

```php
        return ['manager_id' => 'manager_changed', 'starts_on' => 'updated', 'ends_on' => 'updated', 'year' => 'updated'];
```

and override `activityExcludedFields()` to keep workflow columns out of generic update logs (the workflow writes its own events):

```php
    /**
     * @return list<string>
     */
    protected function activityExcludedFields(): array
    {
        return ['status', 'submitted_at', 'approved_at', 'approved_by', 'review_opened_at', 'closed_at', 'closed_by', 'closing_notes', 'cancelled_at', 'cancelled_by', 'cancel_reason'];
    }
```

In `app/Models/Project.php` add:

```php
    /**
     * Plans written for this project alone.
     *
     * @return HasMany<AnnualPlan, $this>
     */
    public function annualPlans(): HasMany
    {
        return $this->hasMany(AnnualPlan::class);
    }
```

- [ ] **Step 5: Update the factory**

`database/factories/AnnualPlanFactory.php` `definition()`:

```php
        return [
            'program_id' => Program::factory(),
            'manager_id' => fn (): int => $this->createHrEmployee(),
            'year' => fake()->numberBetween(2021, 2030),
            'starts_on' => fn (array $attributes): string => $attributes['year'].'-01-01',
            'ends_on' => fn (array $attributes): string => $attributes['year'].'-12-31',
        ];
```

(`status` stays omitted: the DB default is what the schema test proves.)

Add states (per-attribute closures for anything derived, per `.ai/rules/factories.md`):

```php
    /**
     * A plan for one project of its program.
     */
    public function forProject(Project|int $project): static
    {
        $project = $project instanceof Project ? $project : Project::query()->findOrFail($project);

        return $this->state([
            'program_id' => $project->program_id,
            'project_id' => $project->id,
        ]);
    }

    /**
     * Months as "YYYY-MM"; the year follows the start month.
     */
    public function between(string $startMonth, string $endMonth): static
    {
        return $this->state([
            'year' => (int) substr($startMonth, 0, 4),
            'starts_on' => $startMonth.'-01',
            'ends_on' => CarbonImmutable::parse($endMonth.'-01')->endOfMonth()->toDateString(),
        ]);
    }

    public function submitted(): static
    {
        return $this->state(['status' => PlanStatus::Submitted, 'submitted_at' => now()]);
    }

    public function active(): static
    {
        return $this->state(['status' => PlanStatus::Active, 'submitted_at' => now()->subDays(3), 'approved_at' => now()]);
    }

    public function finalReview(): static
    {
        return $this->active()->state(['status' => PlanStatus::FinalReview, 'review_opened_at' => now()]);
    }

    public function closed(): static
    {
        return $this->finalReview()->state(['status' => PlanStatus::Closed, 'closed_at' => now(), 'closing_notes' => 'Delivered as reviewed.']);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => PlanStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => 'Funding withdrawn.']);
    }
```

`status`, `submitted_at` etc. are not in `#[Fillable]`. That is fine for factories: Laravel's `Factory::makeInstance()` builds every model inside `Model::unguarded()`, so states may set guarded columns. Application code must still use `forceFill` (the workflow actions do).

Add `use App\Enums\PlanStatus; use App\Models\Project; use Carbon\CarbonImmutable;`.

- [ ] **Step 6: Run the tests**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/AnnualPlanSchemaTest.php tests/Feature/Models/AnnualPlanTest.php tests/Feature/DatabaseEncodingTest.php tests/Feature/Models/FactoryStateTest.php`
Expected: PASS. If `AnnualPlanCrudTest`'s "a program cannot hold two plans for the same fiscal year" now fails, that is expected. It is rewritten in Task 24. Do **not** touch it here.

- [ ] **Step 7: Format, stage, stop for review**

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add database/migrations/2026_10_07_100000_add_tracking_columns_to_annual_plans_table.php app/Models/AnnualPlan.php app/Models/Project.php database/factories/AnnualPlanFactory.php tests/Feature/AnnualPlans/AnnualPlanSchemaTest.php tests/Feature/Models/AnnualPlanTest.php
```

---

### Task 3: Activity catalog

**Files:**
- Create: `database/migrations/2026_10_07_100100_create_catalog_activities_table.php`, `app/Models/CatalogActivity.php`, `database/factories/CatalogActivityFactory.php`, `app/Policies/CatalogActivityPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php` (only if policies are registered there; Laravel 13 auto-discovers `App\Policies\CatalogActivityPolicy`, so check `Gate::policy` calls first)
- Test: `tests/Feature/AnnualPlans/CatalogActivityTest.php`

**Interfaces:**
- Produces: `CatalogActivity` (`id`, `name`, `description`, `is_active`, `created_by`), scope `active()`, relation `creator(): BelongsTo<User>`, `planActivities(): HasMany<PlanActivity>` (added in Task 4); policy abilities `viewAny`, `create`, `update`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\AnnualPlan;
use App\Models\CatalogActivity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('a catalog activity is active unless switched off', function () {
    $id = DB::table('catalog_activities')->insertGetId([
        'name' => 'Beginner training',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('catalog_activities')->find($id)->is_active)->toBe(1);
});

test('the active scope hides switched-off activities', function () {
    $kept = CatalogActivity::factory()->create(['name' => 'Beginner training']);
    CatalogActivity::factory()->inactive()->create(['name' => 'Old workshop']);

    expect(CatalogActivity::query()->active()->pluck('id')->all())->toBe([$kept->id]);
});

test('meal and plan owners may add to the catalog, other roles may not', function () {
    $meal = userWithRole('MEAL');
    $employee = insertHrEmployee();
    $owner = userWithRole('Team Member', $employee);
    AnnualPlan::factory()->create(['manager_id' => $employee]);
    $bystander = userWithRole('Team Member');

    expect($meal->can('create', CatalogActivity::class))->toBeTrue()
        ->and($owner->can('create', CatalogActivity::class))->toBeTrue()
        ->and($bystander->can('create', CatalogActivity::class))->toBeFalse();
});

test('only meal and annual-plans.update holders rename or switch off an activity', function () {
    $activity = CatalogActivity::factory()->create();

    expect(userWithRole('MEAL')->can('update', $activity))->toBeTrue()
        ->and(userWithRole('Managers')->can('update', $activity))->toBeTrue()
        ->and(userWithRole('Team Member')->can('update', $activity))->toBeFalse();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/CatalogActivityTest.php`
Expected: FAIL, table `catalog_activities` doesn't exist.

- [ ] **Step 3: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reusable list plan activities are picked from. Names are not unique
 * (house rule for name columns); the add form warns about an active twin.
 * Rows are switched off rather than deleted once a plan uses them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_activities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_activities');
    }
};
```

- [ ] **Step 4: Model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CatalogActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One reusable activity a plan row is picked from ("Beginner training").
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'description', 'is_active', 'created_by'])]
class CatalogActivity extends Model
{
    /** @use HasFactory<CatalogActivityFactory> */
    use HasFactory;

    /**
     * Mirrors the column default (see Account::$attributes for why).
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['is_active' => true];

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The plan rows this activity was placed in.
     *
     * @return HasMany<PlanActivity, $this>
     */
    public function planActivities(): HasMany
    {
        return $this->hasMany(PlanActivity::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
```

> `planActivities()` references `PlanActivity`, created in Task 4. Write Task 4's model file before running PHPStan. The test above does not touch that relation, so the test passes without it.

- [ ] **Step 5: Factory**

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CatalogActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogActivity>
 */
class CatalogActivityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement([
                'Beginner training', 'Intermediate training', 'Masters training', 'Community outreach day',
                'Training of trainers', 'Advanced coaching', 'Hygiene kit distribution', 'Quarterly MEAL review',
                'Safeguarding refresher', 'Parents committee meeting', 'Water point repair', 'Cash distribution',
            ]),
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
```

(`is_active` omitted: default-bearing column.)

- [ ] **Step 6: Policy**

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AnnualPlan;
use App\Models\CatalogActivity;
use App\Models\User;

/**
 * The catalog is shared by project managers and MEAL (user decision
 * 2026-10-06): anyone who writes or reviews plans may add to it; renaming
 * or switching an activity off changes every plan that shows it, so that
 * stays with MEAL and annual-plans.update holders.
 */
class CatalogActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('annual-plans.view');
    }

    public function create(User $user): bool
    {
        return $user->can('annual-plans.review')
            || $user->can('annual-plans.create')
            || $user->can('annual-plans.update')
            || $user->managedProgramIds() !== []
            || ($user->employee_id !== null && AnnualPlan::query()->where('manager_id', $user->employee_id)->exists());
    }

    public function update(User $user, CatalogActivity $catalogActivity): bool
    {
        return $user->can('annual-plans.review') || $user->can('annual-plans.update');
    }
}
```

> `annual-plans.review` does not exist until Task 10. Until then `$user->can('annual-plans.review')` is simply false for everyone, and the MEAL assertions in this task's test **fail**. Implement Task 10's config change (Step 3 of Task 10: the `groups` + `MEAL` baseline lines only) **before** running this test, or run this task after Task 10. Recommended order: run Task 10 right after Task 2, then come back. The task numbers stay as written so references remain stable.

- [ ] **Step 7: Run tests, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/CatalogActivityTest.php`
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add database/migrations/2026_10_07_100100_create_catalog_activities_table.php app/Models/CatalogActivity.php database/factories/CatalogActivityFactory.php app/Policies/CatalogActivityPolicy.php tests/Feature/AnnualPlans/CatalogActivityTest.php
```

---

### Task 4: Plan activities and planned months

**Files:**
- Create: `database/migrations/2026_10_07_100200_create_plan_activities_table.php`, `database/migrations/2026_10_07_100300_create_plan_activity_months_table.php`, `app/Models/PlanActivity.php`, `app/Models/PlanActivityMonth.php`, `database/factories/PlanActivityFactory.php`, `database/factories/PlanActivityMonthFactory.php`
- Modify: `app/Models/AnnualPlan.php` (relation)
- Test: `tests/Feature/AnnualPlans/PlanActivityTest.php`

**Interfaces:**
- Produces: `PlanActivity` (`annual_plan_id`, `catalog_activity_id`, `responsible_employee_id`, `tracking PlanActivityTracking`, `is_unplanned bool`, `added_by`, `sequence`), relations `plan()`, `catalogActivity()`, `responsible(): BelongsTo<HrEmployee>`, `months(): HasMany<PlanActivityMonth>`, `occurrences(): HasMany<PlanOccurrence>` (Task 5), `planIndicators(): HasMany<PlanIndicator>` (Task 7), `milestones(): HasMany<Milestone>` (Task 7), `name(): string`; `PlanActivityMonth` (`plan_activity_id`, `month CarbonImmutable`); `AnnualPlan::activities(): HasMany<PlanActivity>` ordered by `sequence, id`; factory states `perProject()`, `unplanned()`, `inMonths(string ...$months)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\PlanActivityTracking;
use App\Models\AnnualPlan;
use App\Models\CatalogActivity;
use App\Models\PlanActivity;
use App\Models\PlanActivityMonth;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a plan activity is shared, planned and first in line by default', function () {
    $planId = AnnualPlan::factory()->create()->id;
    $catalogId = CatalogActivity::factory()->create()->id;

    $id = DB::table('plan_activities')->insertGetId([
        'annual_plan_id' => $planId,
        'catalog_activity_id' => $catalogId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $row = DB::table('plan_activities')->find($id);

    expect($row->tracking)->toBe('shared')
        ->and($row->is_unplanned)->toBe(0)
        ->and($row->sequence)->toBe(0);
});

test('one catalog activity appears once per plan', function () {
    $activity = PlanActivity::factory()->create();

    expect(fn () => PlanActivity::factory()->create([
        'annual_plan_id' => $activity->annual_plan_id,
        'catalog_activity_id' => $activity->catalog_activity_id,
    ]))->toThrow(QueryException::class);
});

test('a month is planned once per activity', function () {
    $activity = PlanActivity::factory()->inMonths('2027-02')->create();

    expect(fn () => PlanActivityMonth::factory()->create(['plan_activity_id' => $activity->id, 'month' => '2027-02-01']))
        ->toThrow(QueryException::class);
});

test('deleting a plan removes its activities and months', function () {
    $activity = PlanActivity::factory()->inMonths('2027-02', '2027-03')->create();

    $activity->plan->delete();

    expect(DB::table('plan_activities')->count())->toBe(0)
        ->and(DB::table('plan_activity_months')->count())->toBe(0);
});

test('a catalog activity in use cannot be deleted', function () {
    $activity = PlanActivity::factory()->create();

    expect(fn () => $activity->catalogActivity->delete())->toThrow(QueryException::class);
});

test('months come back as first-of-month dates in order', function () {
    $activity = PlanActivity::factory()->inMonths('2027-07', '2027-02')->create();

    expect($activity->months()->orderBy('month')->get()->map(fn (PlanActivityMonth $month): string => $month->month->toDateString())->all())
        ->toBe(['2027-02-01', '2027-07-01'])
        ->and($activity->tracking)->toBe(PlanActivityTracking::Shared)
        ->and($activity->name())->toBe($activity->catalogActivity->name);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanActivityTest.php`
Expected: FAIL, table missing.

- [ ] **Step 3: Migrations**

`2026_10_07_100200_create_plan_activities_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row of a plan's grid: a catalog activity placed in a plan.
 *
 * responsible_employee_id is an HR employee id, so no foreign key (HR is a
 * separate read-only connection). catalog_activity_id restricts: an activity
 * in use is switched off in the catalog, never deleted. is_unplanned marks a
 * row added after the planned layer froze; it has no planned months.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('annual_plan_id')->constrained('annual_plans')->cascadeOnDelete();
            $table->foreignId('catalog_activity_id')->constrained('catalog_activities')->restrictOnDelete();
            $table->unsignedBigInteger('responsible_employee_id')->nullable();
            $table->string('tracking', 50)->default('shared');
            $table->boolean('is_unplanned')->default(false);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->timestamps();

            $table->unique(['annual_plan_id', 'catalog_activity_id']);
            $table->index('responsible_employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_activities');
    }
};
```

`2026_10_07_100300_create_plan_activity_months_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The planned layer: one row per X in the grid, stored as the first day of
 * the month. Written only while the plan is a draft; afterwards it is the
 * frozen baseline the review compares against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_activity_months', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_activity_id')->constrained('plan_activities')->cascadeOnDelete();
            $table->date('month');
            $table->timestamps();

            $table->unique(['plan_activity_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_activity_months');
    }
};
```

- [ ] **Step 4: Models**

`app/Models/PlanActivity.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanActivityTracking;
use Carbon\CarbonImmutable;
use Database\Factories\PlanActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One activity row of a plan.
 *
 * @property int $id
 * @property int $annual_plan_id
 * @property int $catalog_activity_id
 * @property int|null $responsible_employee_id
 * @property PlanActivityTracking $tracking
 * @property bool $is_unplanned
 * @property int|null $added_by
 * @property int $sequence
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['annual_plan_id', 'catalog_activity_id', 'responsible_employee_id', 'tracking', 'is_unplanned', 'added_by', 'sequence'])]
class PlanActivity extends Model
{
    /** @use HasFactory<PlanActivityFactory> */
    use HasFactory;

    /**
     * Mirrors the column defaults for in-memory instances under strict mode.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['tracking' => 'shared', 'is_unplanned' => false, 'sequence' => 0];

    /**
     * @return BelongsTo<AnnualPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(AnnualPlan::class, 'annual_plan_id');
    }

    /**
     * @return BelongsTo<CatalogActivity, $this>
     */
    public function catalogActivity(): BelongsTo
    {
        return $this->belongsTo(CatalogActivity::class);
    }

    /**
     * The HR employee answerable for this activity (a plain lookup, no join).
     *
     * @return BelongsTo<HrEmployee, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'responsible_employee_id');
    }

    /**
     * The planned months (the Xs).
     *
     * @return HasMany<PlanActivityMonth, $this>
     */
    public function months(): HasMany
    {
        return $this->hasMany(PlanActivityMonth::class);
    }

    /**
     * What actually happened, per month (and project when tracked per project).
     *
     * @return HasMany<PlanOccurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(PlanOccurrence::class);
    }

    /**
     * Targets MEAL committed on this activity.
     *
     * @return HasMany<PlanIndicator, $this>
     */
    public function planIndicators(): HasMany
    {
        return $this->hasMany(PlanIndicator::class);
    }

    /**
     * Milestones MEAL set from this activity (project plans only).
     *
     * @return HasMany<Milestone, $this>
     */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class);
    }

    /**
     * The display name, read from the catalog.
     */
    public function name(): string
    {
        return (string) $this->catalogActivity?->name;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tracking' => PlanActivityTracking::class,
            'is_unplanned' => 'boolean',
            'sequence' => 'integer',
        ];
    }
}
```

`app/Models/PlanActivityMonth.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PlanActivityMonthFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One planned month of an activity: an X in the planned grid.
 *
 * @property int $id
 * @property int $plan_activity_id
 * @property CarbonImmutable $month
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['plan_activity_id', 'month'])]
class PlanActivityMonth extends Model
{
    /** @use HasFactory<PlanActivityMonthFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<PlanActivity, $this>
     */
    public function planActivity(): BelongsTo
    {
        return $this->belongsTo(PlanActivity::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['month' => 'date'];
    }
}
```

In `AnnualPlan` add:

```php
    /**
     * The grid rows, in the order the manager arranged them.
     *
     * @return HasMany<PlanActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(PlanActivity::class)->orderBy('sequence')->orderBy('id');
    }
```

(`use Illuminate\Database\Eloquent\Relations\HasMany;`)

`PlanActivity::occurrences()` references `PlanOccurrence` from Task 5. Create Task 5's model before running PHPStan.

- [ ] **Step 5: Factories**

`database/factories/PlanActivityFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlanActivityTracking;
use App\Models\AnnualPlan;
use App\Models\CatalogActivity;
use App\Models\PlanActivity;
use App\Models\PlanActivityMonth;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanActivity>
 */
class PlanActivityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'annual_plan_id' => AnnualPlan::factory(),
            'catalog_activity_id' => CatalogActivity::factory(),
        ];
    }

    public function perProject(): static
    {
        return $this->state(['tracking' => PlanActivityTracking::PerProject]);
    }

    public function unplanned(): static
    {
        return $this->state(['is_unplanned' => true]);
    }

    /**
     * Planned in the given "YYYY-MM" months.
     */
    public function inMonths(string ...$months): static
    {
        return $this->afterCreating(function (PlanActivity $activity) use ($months): void {
            foreach ($months as $month) {
                PlanActivityMonth::factory()->create(['plan_activity_id' => $activity->id, 'month' => $month.'-01']);
            }
        });
    }
}
```

`database/factories/PlanActivityMonthFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PlanActivity;
use App\Models\PlanActivityMonth;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanActivityMonth>
 */
class PlanActivityMonthFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_activity_id' => PlanActivity::factory(),
            'month' => fake()->dateTimeBetween('2027-01-01', '2027-12-01')->format('Y-m').'-01',
        ];
    }
}
```

- [ ] **Step 6: Run tests, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanActivityTest.php`
Expected: PASS (after Task 5's model file exists; if you run this task first, create an empty-bodied `PlanOccurrence` model stub **only if the test needs it**. It does not, because `occurrences()` is never called here).

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add database/migrations/2026_10_07_100200_create_plan_activities_table.php database/migrations/2026_10_07_100300_create_plan_activity_months_table.php app/Models/PlanActivity.php app/Models/PlanActivityMonth.php app/Models/AnnualPlan.php database/factories/PlanActivityFactory.php database/factories/PlanActivityMonthFactory.php tests/Feature/AnnualPlans/PlanActivityTest.php
```

---

### Task 5: Occurrences (actual layer) and the plan journal

**Files:**
- Create: `database/migrations/2026_10_07_100400_create_plan_occurrences_table.php`, `database/migrations/2026_10_07_100500_create_annual_plan_events_table.php`, `app/Models/PlanOccurrence.php`, `app/Models/AnnualPlanEvent.php`, `database/factories/PlanOccurrenceFactory.php`, `database/factories/AnnualPlanEventFactory.php`
- Modify: `app/Models/AnnualPlan.php` (`events()`, transactional delete that removes evidence), `app/Providers/AppServiceProvider.php` (morph `plan_occurrence`), `app/Observers/MediaOwnerObserver.php`, `tests/Feature/Models/MorphMapTest.php`
- Test: `tests/Feature/AnnualPlans/PlanOccurrenceTest.php`

**Interfaces:**
- Produces: `PlanOccurrence` (`plan_activity_id`, `project_id`, `project_key`, `month`, `status OccurrenceStatus`, `moved_to ?CarbonImmutable`, `note`, `reported_by`, `reported_at`, `verified_by`, `verified_at`), relations `planActivity()`, `project()`, `reporter()`, `verifier()`, `media(): MorphMany<Media>`; `AnnualPlanEvent` (`annual_plan_id`, `plan_activity_id`, `project_id`, `user_id`, `action PlanEventAction`, `from_status ?PlanStatus`, `to_status ?PlanStatus`, `month`, `note`), relations `plan()`, `planActivity()`, `project()`, `user()`; `AnnualPlan::events(): HasMany<AnnualPlanEvent>` newest first; factory states `reported()`, `verified()`, `postponedTo(string)`, `missed()`, `forProject(Project|int)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\OccurrenceStatus;
use App\Enums\PlanEventAction;
use App\Enums\PlanStatus;
use App\Models\AnnualPlan;
use App\Models\AnnualPlanEvent;
use App\Models\Media;
use App\Models\PlanActivity;
use App\Models\PlanOccurrence;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a shared occurrence is unique per activity and month', function () {
    $occurrence = PlanOccurrence::factory()->reported()->create(['month' => '2027-03-01']);

    expect(fn () => PlanOccurrence::factory()->reported()->create([
        'plan_activity_id' => $occurrence->plan_activity_id,
        'month' => '2027-03-01',
    ]))->toThrow(QueryException::class);
});

test('per-project occurrences may share a month across projects', function () {
    $activity = PlanActivity::factory()->perProject()->create();
    $first = Project::factory()->create(['program_id' => $activity->plan->program_id]);
    $second = Project::factory()->create(['program_id' => $activity->plan->program_id]);

    PlanOccurrence::factory()->reported()->forProject($first)->create(['plan_activity_id' => $activity->id, 'month' => '2027-03-01']);
    PlanOccurrence::factory()->reported()->forProject($second)->create(['plan_activity_id' => $activity->id, 'month' => '2027-03-01']);

    expect(DB::table('plan_occurrences')->pluck('project_key')->sort()->values()->all())->toBe([$first->id, $second->id]);
});

test('project_key mirrors project_id and is zero when shared', function () {
    $occurrence = PlanOccurrence::factory()->verified()->create();

    expect(DB::table('plan_occurrences')->find($occurrence->id)->project_key)->toBe(0);
});

test('casts read status, months and the journal enums', function () {
    $occurrence = PlanOccurrence::factory()->postponedTo('2027-05')->create(['month' => '2027-04-01']);
    $event = AnnualPlanEvent::factory()->create([
        'action' => PlanEventAction::Returned,
        'from_status' => PlanStatus::Submitted,
        'to_status' => PlanStatus::Draft,
    ]);

    expect($occurrence->status)->toBe(OccurrenceStatus::Postponed)
        ->and($occurrence->moved_to->toDateString())->toBe('2027-05-01')
        ->and($event->action)->toBe(PlanEventAction::Returned)
        ->and($event->to_status)->toBe(PlanStatus::Draft);
});

test('deleting a plan removes occurrences, journal and evidence rows', function () {
    $occurrence = PlanOccurrence::factory()->verified()->create();
    $plan = $occurrence->planActivity->plan;
    AnnualPlanEvent::factory()->create(['annual_plan_id' => $plan->id]);
    insertMedia('plan_occurrence', $occurrence->id, userWithRole('MEAL')->id);

    $plan->delete();

    expect(PlanOccurrence::query()->count())->toBe(0)
        ->and(AnnualPlanEvent::query()->count())->toBe(0)
        ->and(Media::query()->where('mediable_type', 'plan_occurrence')->count())->toBe(0);
});

test('events read newest first', function () {
    $plan = AnnualPlan::factory()->create();
    $older = AnnualPlanEvent::factory()->create(['annual_plan_id' => $plan->id, 'created_at' => now()->subDay()]);
    $newer = AnnualPlanEvent::factory()->create(['annual_plan_id' => $plan->id]);

    expect($plan->events()->pluck('id')->all())->toBe([$newer->id, $older->id]);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanOccurrenceTest.php`
Expected: FAIL, table missing.

- [ ] **Step 3: Migrations**

`2026_10_07_100400_create_plan_occurrences_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The actual layer: what happened for one activity in one month, and for
 * one project when a program-wide activity is tracked per project.
 *
 * project_key is project_id or 0. It exists only for the unique index: MySQL
 * lets any number of NULLs through a unique, so (activity, NULL, month) could
 * be stored twice for a shared activity. The model writes it on every save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_activity_id')->constrained('plan_activities')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->unsignedBigInteger('project_key')->default(0);
            $table->date('month');
            $table->string('status', 50)->default('reported');
            $table->date('moved_to')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reported_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['plan_activity_id', 'project_key', 'month']);
            $table->index(['status', 'reported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_occurrences');
    }
};
```

`2026_10_07_100500_create_annual_plan_events_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plan's durable journal: every phase move (with the return or cancel
 * reason) and every tracking action, kept for the life of the plan. The
 * activity log is pruned after two years and must not be the only record
 * that a plan was returned for edits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annual_plan_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('annual_plan_id')->constrained('annual_plans')->cascadeOnDelete();
            $table->foreignId('plan_activity_id')->nullable()->constrained('plan_activities')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50);
            $table->string('from_status', 50)->nullable();
            $table->string('to_status', 50)->nullable();
            $table->date('month')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['annual_plan_id', 'created_at']);
            $table->index(['plan_activity_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annual_plan_events');
    }
};
```

- [ ] **Step 4: Models**

`app/Models/PlanOccurrence.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OccurrenceStatus;
use App\Observers\MediaOwnerObserver;
use Carbon\CarbonImmutable;
use Database\Factories\PlanOccurrenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * What actually happened for one activity in one month (and project).
 *
 * @property int $id
 * @property int $plan_activity_id
 * @property int|null $project_id
 * @property int $project_key
 * @property CarbonImmutable $month
 * @property OccurrenceStatus $status
 * @property CarbonImmutable|null $moved_to
 * @property string|null $note
 * @property int|null $reported_by
 * @property CarbonImmutable|null $reported_at
 * @property int|null $verified_by
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['plan_activity_id', 'project_id', 'month', 'status', 'moved_to', 'note', 'reported_by', 'reported_at', 'verified_by', 'verified_at'])]
#[ObservedBy(MediaOwnerObserver::class)]
class PlanOccurrence extends Model
{
    /** @use HasFactory<PlanOccurrenceFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'reported', 'project_key' => 0];

    /**
     * project_key backs the unique index; it follows project_id on every save.
     */
    public function save(array $options = []): bool
    {
        $this->attributes['project_key'] = $this->project_id ?? 0;

        return parent::save($options);
    }

    /**
     * @return BelongsTo<PlanActivity, $this>
     */
    public function planActivity(): BelongsTo
    {
        return $this->belongsTo(PlanActivity::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Evidence files (attendance sheets, photos).
     *
     * @return MorphMany<Media, $this>
     */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'moved_to' => 'date',
            'status' => OccurrenceStatus::class,
            'project_key' => 'integer',
            'reported_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }
}
```

`app/Models/AnnualPlanEvent.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanEventAction;
use App\Enums\PlanStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AnnualPlanEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a plan's journal. Written only through PlanJournal.
 *
 * @property int $id
 * @property int $annual_plan_id
 * @property int|null $plan_activity_id
 * @property int|null $project_id
 * @property int|null $user_id
 * @property PlanEventAction $action
 * @property PlanStatus|null $from_status
 * @property PlanStatus|null $to_status
 * @property CarbonImmutable|null $month
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['annual_plan_id', 'plan_activity_id', 'project_id', 'user_id', 'action', 'from_status', 'to_status', 'month', 'note', 'created_at'])]
class AnnualPlanEvent extends Model
{
    /** @use HasFactory<AnnualPlanEventFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<AnnualPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(AnnualPlan::class, 'annual_plan_id');
    }

    /**
     * @return BelongsTo<PlanActivity, $this>
     */
    public function planActivity(): BelongsTo
    {
        return $this->belongsTo(PlanActivity::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => PlanEventAction::class,
            'from_status' => PlanStatus::class,
            'to_status' => PlanStatus::class,
            'month' => 'date',
        ];
    }
}
```

In `AnnualPlan` add:

```php
    /**
     * The journal, newest first.
     *
     * @return HasMany<AnnualPlanEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AnnualPlanEvent::class)->latest('created_at')->latest('id');
    }
```

and replace `delete()` so evidence files go before the database cascade hides the occurrences (same reason `ProgramObserver` exists):

```php
    public function delete(): bool
    {
        return (bool) DB::transaction(function (): ?bool {
            // plan_occurrences cascade from plan_activities, which cascade from
            // this row. A database cascade fires no model events, so their
            // evidence would be orphaned; delete them through Eloquent first.
            PlanOccurrence::query()
                ->whereIn('plan_activity_id', PlanActivity::query()->where('annual_plan_id', $this->id)->select('id'))
                ->get()
                ->each->delete();

            return parent::delete();
        });
    }
```

In `app/Observers/MediaOwnerObserver.php::deleting()` add, inside the `$model instanceof Project` branch, after the milestones line:

```php
            // plan_occurrences.project_id cascades; their evidence must go first.
            PlanOccurrence::query()->where('project_id', $model->getKey())->get()->each->delete();
            // A project plan cascades with the project; delete it through
            // Eloquent so its targets and evidence are cleaned up too.
            $model->annualPlans()->get()->each->delete();
```

(`use App\Models\PlanOccurrence;`.)

In `AppServiceProvider::configureMorphMap()` add `'plan_occurrence' => PlanOccurrence::class,` (+ import). In `tests/Feature/Models/MorphMapTest.php` add the same entry in the expected array, in the same position.

- [ ] **Step 5: Factories**

`database/factories/PlanOccurrenceFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OccurrenceStatus;
use App\Models\PlanActivity;
use App\Models\PlanOccurrence;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanOccurrence>
 */
class PlanOccurrenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_activity_id' => PlanActivity::factory(),
            'month' => '2027-03-01',
        ];
    }

    public function reported(): static
    {
        return $this->state(['status' => OccurrenceStatus::Reported, 'reported_at' => now()]);
    }

    public function verified(): static
    {
        return $this->state(['status' => OccurrenceStatus::Verified, 'reported_at' => now()->subDay(), 'verified_at' => now()]);
    }

    public function postponedTo(string $month): static
    {
        return $this->state(['status' => OccurrenceStatus::Postponed, 'moved_to' => $month.'-01', 'reported_at' => now()]);
    }

    public function missed(): static
    {
        return $this->state(['status' => OccurrenceStatus::Missed, 'verified_at' => now()]);
    }

    public function forProject(Project|int $project): static
    {
        return $this->state(['project_id' => $project instanceof Project ? $project->id : $project]);
    }
}
```

`database/factories/AnnualPlanEventFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlanEventAction;
use App\Models\AnnualPlan;
use App\Models\AnnualPlanEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnnualPlanEvent>
 */
class AnnualPlanEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'annual_plan_id' => AnnualPlan::factory(),
            'action' => PlanEventAction::Created,
        ];
    }
}
```

- [ ] **Step 6: Run tests, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanOccurrenceTest.php tests/Feature/Models/MorphMapTest.php tests/Feature/Models/TransactionalDeleteTest.php tests/Feature/Models/PlanIndicatorCleanupTest.php`
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add database/migrations/2026_10_07_100400_create_plan_occurrences_table.php database/migrations/2026_10_07_100500_create_annual_plan_events_table.php app/Models/PlanOccurrence.php app/Models/AnnualPlanEvent.php app/Models/AnnualPlan.php database/factories/PlanOccurrenceFactory.php database/factories/AnnualPlanEventFactory.php app/Providers/AppServiceProvider.php app/Observers/MediaOwnerObserver.php tests/Feature/Models/MorphMapTest.php tests/Feature/AnnualPlans/PlanOccurrenceTest.php
```

---

### Task 6: Targets and milestones point at an activity

**Files:**
- Create: `database/migrations/2026_10_07_100600_add_plan_activity_id_to_plan_indicators_and_milestones.php`
- Modify: `app/Models/PlanIndicator.php`, `app/Models/Milestone.php`
- Test: `tests/Feature/AnnualPlans/ActivityTargetLinkTest.php`

**Interfaces:**
- Produces: `plan_indicators.plan_activity_id`, `milestones.plan_activity_id` (nullable, `nullOnDelete`), `PlanIndicator::planActivity(): BelongsTo<PlanActivity>`, `Milestone::planActivity(): BelongsTo<PlanActivity>`, both columns fillable.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Milestone;
use App\Models\PlanActivity;
use App\Models\PlanIndicator;
use App\Models\Project;

test('a target can belong to one plan activity', function () {
    $activity = PlanActivity::factory()->create();
    $target = PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => $activity->annual_plan_id,
        'plan_activity_id' => $activity->id,
    ]);

    expect($target->planActivity->is($activity))->toBeTrue()
        ->and($activity->planIndicators()->pluck('id')->all())->toBe([$target->id]);
});

test('removing the activity keeps the target on the plan', function () {
    $activity = PlanActivity::factory()->create();
    $target = PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => $activity->annual_plan_id,
        'plan_activity_id' => $activity->id,
    ]);

    $activity->delete();

    expect($target->fresh()->plan_activity_id)->toBeNull();
});

test('a milestone can be set from a project plan activity', function () {
    $project = Project::factory()->create();
    $activity = PlanActivity::factory()->create();
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'plan_activity_id' => $activity->id]);

    expect($milestone->planActivity->is($activity))->toBeTrue();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/ActivityTargetLinkTest.php`
Expected: FAIL, unknown column `plan_activity_id`.

- [ ] **Step 3: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MEAL turns an activity's quantity into a target or a milestone (user
 * decision 2026-10-06). Removing the activity in a draft keeps the target on
 * the plan and the milestone on its project, so both null out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_indicators', function (Blueprint $table) {
            $table->foreignId('plan_activity_id')->nullable()->after('source_id')->constrained('plan_activities')->nullOnDelete();
        });

        Schema::table('milestones', function (Blueprint $table) {
            $table->foreignId('plan_activity_id')->nullable()->after('project_id')->constrained('plan_activities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('plan_indicators', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_activity_id');
        });

        Schema::table('milestones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_activity_id');
        });
    }
};
```

- [ ] **Step 4: Models**

`PlanIndicator`: add `'plan_activity_id'` to `#[Fillable]`, `@property int|null $plan_activity_id`, and:

```php
    /**
     * The plan activity this target measures, when MEAL set it on one.
     *
     * @return BelongsTo<PlanActivity, $this>
     */
    public function planActivity(): BelongsTo
    {
        return $this->belongsTo(PlanActivity::class);
    }
```

`Milestone`: add `'plan_activity_id'` to `#[Fillable]`, `@property int|null $plan_activity_id`, and the same `planActivity()` relation.

- [ ] **Step 5: Run tests, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/ActivityTargetLinkTest.php tests/Feature/Models/PlanIndicatorTest.php tests/Feature/Models/MilestoneTest.php`
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add database/migrations/2026_10_07_100600_add_plan_activity_id_to_plan_indicators_and_milestones.php app/Models/PlanIndicator.php app/Models/Milestone.php tests/Feature/AnnualPlans/ActivityTargetLinkTest.php
```

---

# Phase B: Domain logic

### Task 7: `PlanPeriod`: month maths

**Files:**
- Create: `app/Support/Plans/PlanPeriod.php`
- Test: `tests/Unit/PlanPeriodTest.php` (pure, no DB, so a Unit test)

**Interfaces:**
- Produces:
  - `PlanPeriod::months(CarbonImmutable $start, CarbonImmutable $end): list<CarbonImmutable>` (first days of each month, inclusive)
  - `PlanPeriod::key(CarbonInterface $month): string` → `"2027-09"`
  - `PlanPeriod::fromKey(string $key): CarbonImmutable` (throws `InvalidArgumentException` on a malformed key)
  - `PlanPeriod::contains(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $month): bool`
  - `PlanPeriod::length(CarbonImmutable $start, CarbonImmutable $end): int` (months)
  - `PlanPeriod::currentMonth(): CarbonImmutable`
  - `PlanPeriod::MaxMonths = 24`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Support\Plans\PlanPeriod;
use Carbon\CarbonImmutable;

test('months lists every first-of-month inclusive', function () {
    $months = PlanPeriod::months(CarbonImmutable::parse('2027-10-01'), CarbonImmutable::parse('2028-01-31'));

    expect(array_map(fn (CarbonImmutable $month): string => PlanPeriod::key($month), $months))
        ->toBe(['2027-10', '2027-11', '2027-12', '2028-01']);
});

test('a key round-trips and a malformed key is refused', function () {
    expect(PlanPeriod::fromKey('2027-09')->toDateString())->toBe('2027-09-01')
        ->and(fn () => PlanPeriod::fromKey('2027-13'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PlanPeriod::fromKey('27-9'))->toThrow(InvalidArgumentException::class);
});

test('contains and length agree with the inclusive period', function () {
    $start = CarbonImmutable::parse('2027-04-01');
    $end = CarbonImmutable::parse('2027-09-30');

    expect(PlanPeriod::contains($start, $end, CarbonImmutable::parse('2027-09-01')))->toBeTrue()
        ->and(PlanPeriod::contains($start, $end, CarbonImmutable::parse('2027-10-01')))->toBeFalse()
        ->and(PlanPeriod::length($start, $end))->toBe(6);
});

test('the current month follows the clock', function () {
    CarbonImmutable::setTestNow('2027-09-17 10:00:00');

    expect(PlanPeriod::currentMonth()->toDateString())->toBe('2027-09-01');

    CarbonImmutable::setTestNow();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Unit/PlanPeriodTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Support\Plans;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Month arithmetic for plans. A plan month is always the first day of the
 * month; the browser sends and receives it as a "YYYY-MM" key.
 */
final class PlanPeriod
{
    /**
     * Longest plan a manager may set (D12).
     */
    public const int MaxMonths = 24;

    /**
     * @return list<CarbonImmutable>
     */
    public static function months(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $months = [];
        $cursor = $start->startOfMonth();
        $last = $end->startOfMonth();

        while ($cursor->lte($last)) {
            $months[] = $cursor;
            $cursor = $cursor->addMonthNoOverflow();
        }

        return $months;
    }

    public static function key(CarbonInterface $month): string
    {
        return $month->format('Y-m');
    }

    public static function fromKey(string $key): CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $key) !== 1) {
            throw new InvalidArgumentException("Not a month key: {$key}");
        }

        return CarbonImmutable::parse($key.'-01')->startOfMonth();
    }

    public static function contains(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $month): bool
    {
        $month = $month->startOfMonth();

        return $month->gte($start->startOfMonth()) && $month->lte($end->startOfMonth());
    }

    public static function length(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return (int) $start->startOfMonth()->diffInMonths($end->startOfMonth()) + 1;
    }

    public static function currentMonth(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfMonth();
    }
}
```

- [ ] **Step 4: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Unit/PlanPeriodTest.php`
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add app/Support/Plans/PlanPeriod.php tests/Unit/PlanPeriodTest.php
```

---

### Task 8: `PlanSchedule`: period, overlap and scope rules

**Files:**
- Create: `app/Support/Plans/PlanSchedule.php`
- Modify: `lang/en/annual_plans.php`, `lang/ar/annual_plans.php`
- Test: `tests/Feature/AnnualPlans/PlanScheduleTest.php`

**Interfaces:**
- Consumes: `PlanPeriod`, `AnnualPlan::overlapping()/open()/programWide()/forProject()`.
- Produces: `PlanSchedule::errors(int $programId, ?int $projectId, CarbonImmutable $start, CarbonImmutable $end, ?int $ignorePlanId = null): array<string, string>`. Keys are `planStartMonth`, `planEndMonth`, `planProjectId`, and values are translated messages. An empty array means valid.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\AnnualPlan;
use App\Models\Program;
use App\Models\Project;
use App\Support\Plans\PlanSchedule;
use Carbon\CarbonImmutable;

function month(string $key): CarbonImmutable
{
    return CarbonImmutable::parse($key.'-01');
}

test('a valid program-wide period has no errors', function () {
    $program = Program::factory()->create();

    expect(app(PlanSchedule::class)->errors($program->id, null, month('2027-01'), month('2027-12')->endOfMonth()))->toBe([]);
});

test('the end month cannot come before the start month', function () {
    $program = Program::factory()->create();

    expect(app(PlanSchedule::class)->errors($program->id, null, month('2027-06'), month('2027-03')->endOfMonth()))
        ->toHaveKey('planEndMonth', __('annual_plans.the_end_month_must_be_on_or_after_the_start_month'));
});

test('a plan may not run longer than twenty-four months', function () {
    $program = Program::factory()->create();

    expect(app(PlanSchedule::class)->errors($program->id, null, month('2027-01'), month('2029-01')->endOfMonth()))
        ->toHaveKey('planEndMonth', __('annual_plans.a_plan_can_run_at_most_months', ['months' => 24]));
});

test('two open program-wide plans cannot overlap', function () {
    $program = Program::factory()->create();
    AnnualPlan::factory()->between('2027-01', '2027-06')->create(['program_id' => $program->id]);

    expect(app(PlanSchedule::class)->errors($program->id, null, month('2027-06'), month('2027-09')->endOfMonth()))
        ->toHaveKey('planStartMonth');
});

test('a cancelled or closed plan does not block its months', function () {
    $program = Program::factory()->create();
    AnnualPlan::factory()->between('2027-01', '2027-06')->cancelled()->create(['program_id' => $program->id]);
    AnnualPlan::factory()->between('2027-01', '2027-06')->closed()->create(['program_id' => $program->id]);

    expect(app(PlanSchedule::class)->errors($program->id, null, month('2027-03'), month('2027-09')->endOfMonth()))->toBe([]);
});

test('a project plan may run alongside its program plan but not another plan for the same project', function () {
    $project = Project::factory()->create();
    AnnualPlan::factory()->between('2027-01', '2027-12')->create(['program_id' => $project->program_id]);

    expect(app(PlanSchedule::class)->errors($project->program_id, $project->id, month('2027-01'), month('2027-12')->endOfMonth()))->toBe([]);

    AnnualPlan::factory()->forProject($project)->between('2027-01', '2027-06')->create();

    expect(app(PlanSchedule::class)->errors($project->program_id, $project->id, month('2027-05'), month('2027-12')->endOfMonth()))
        ->toHaveKey('planStartMonth');
});

test('the project must belong to the chosen program', function () {
    $project = Project::factory()->create();
    $otherProgram = Program::factory()->create();

    expect(app(PlanSchedule::class)->errors($otherProgram->id, $project->id, month('2027-01'), month('2027-12')->endOfMonth()))
        ->toHaveKey('planProjectId', __('annual_plans.the_project_must_belong_to_the_selected_program'));
});

test('editing a plan does not collide with itself', function () {
    $program = Program::factory()->create();
    $plan = AnnualPlan::factory()->between('2027-01', '2027-06')->create(['program_id' => $program->id]);

    expect(app(PlanSchedule::class)->errors($program->id, null, month('2027-02'), month('2027-07')->endOfMonth(), $plan->id))->toBe([]);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanScheduleTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Support\Plans;

use App\Models\AnnualPlan;
use App\Models\Project;
use Carbon\CarbonImmutable;

/**
 * The rules a plan's scope and period must satisfy.
 *
 * One open plan per scope at a time (user decision 2026-10-06): a program has
 * at most one program-wide plan for any month, and a project at most one
 * project plan. A project plan may run alongside its program's plan, which
 * is the higher view. Closed and cancelled plans free their months.
 */
final class PlanSchedule
{
    /**
     * @return array<string, string> field => message; empty when valid
     */
    public function errors(int $programId, ?int $projectId, CarbonImmutable $start, CarbonImmutable $end, ?int $ignorePlanId = null): array
    {
        if ($end->startOfMonth()->lt($start->startOfMonth())) {
            return ['planEndMonth' => __('annual_plans.the_end_month_must_be_on_or_after_the_start_month')];
        }

        if (PlanPeriod::length($start, $end) > PlanPeriod::MaxMonths) {
            return ['planEndMonth' => __('annual_plans.a_plan_can_run_at_most_months', ['months' => PlanPeriod::MaxMonths])];
        }

        if ($projectId !== null && (int) Project::query()->whereKey($projectId)->value('program_id') !== $programId) {
            return ['planProjectId' => __('annual_plans.the_project_must_belong_to_the_selected_program')];
        }

        $clash = AnnualPlan::query()
            ->select(['id', 'program_id', 'project_id', 'starts_on', 'ends_on'])
            ->open()
            ->overlapping($start->startOfMonth(), $end->endOfMonth())
            ->when($ignorePlanId !== null, fn ($query) => $query->whereKeyNot($ignorePlanId))
            ->when(
                $projectId === null,
                fn ($query) => $query->where('program_id', $programId)->whereNull('project_id'),
                fn ($query) => $query->where('project_id', $projectId),
            )
            ->first();

        if ($clash !== null) {
            return ['planStartMonth' => __('annual_plans.these_months_overlap_another_open_plan', ['period' => $clash->periodLabel()])];
        }

        return [];
    }
}
```

- [ ] **Step 4: Lang keys**

`lang/en/annual_plans.php` (keep alphabetical order within the file):

```php
    'a_plan_can_run_at_most_months' => 'A plan can run at most :months months.',
    'the_end_month_must_be_on_or_after_the_start_month' => 'The end month must be on or after the start month.',
    'the_project_must_belong_to_the_selected_program' => 'The project must belong to the selected program.',
    'these_months_overlap_another_open_plan' => 'These months overlap another open plan (:period).',
```

`lang/ar/annual_plans.php`:

```php
    'a_plan_can_run_at_most_months' => 'لا يمكن أن تتجاوز مدة الخطة :months شهرًا.',
    'the_end_month_must_be_on_or_after_the_start_month' => 'يجب أن يكون شهر النهاية في شهر البداية أو بعده.',
    'the_project_must_belong_to_the_selected_program' => 'يجب أن يتبع المشروع البرنامج المحدد.',
    'these_months_overlap_another_open_plan' => 'هذه الأشهر تتداخل مع خطة مفتوحة أخرى (:period).',
```

- [ ] **Step 5: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanScheduleTest.php tests/Feature/LocalizationTest.php`
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add app/Support/Plans/PlanSchedule.php lang/en/annual_plans.php lang/ar/annual_plans.php tests/Feature/AnnualPlans/PlanScheduleTest.php
```

---

### Task 9: `PlanJournal`: one call writes the journal row and the activity

**Files:**
- Create: `app/Support/Plans/PlanJournal.php`
- Modify: `app/Notifications/ActivityParameters.php`, `app/Notifications/NotificationPresenter.php`
- Test: `tests/Feature/AnnualPlans/PlanJournalTest.php`

**Interfaces:**
- Consumes: `AnnualPlan::recordActivity()` (from `RecordsActivity`), `AnnualPlanEvent`.
- Produces: `PlanJournal::write(AnnualPlan $plan, PlanEventAction $action, ?string $activityEvent = null, ?PlanActivity $activity = null, ?int $projectId = null, ?CarbonImmutable $month = null, ?string $note = null, ?PlanStatus $from = null, ?PlanStatus $to = null, array $properties = []): AnnualPlanEvent`. When `$activityEvent` is given (e.g. `'submitted'`), it also records activity `annual_plan.{$activityEvent}` with properties `activity` (name), `month` (`Y-m-d`), `note`, plus the extras.
- Typed sentence params: `['month' => 'Y-m-d']` renders as `formats.month_year` in the reader's language. `ActivityParameters` turns properties `month` and `moved_to` into that type and passes `activity` through.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\PlanEventAction;
use App\Enums\PlanStatus;
use App\Models\Activity;
use App\Models\AnnualPlan;
use App\Models\PlanActivity;
use App\Notifications\ActivityPresenter;
use App\Support\Plans\PlanJournal;
use Carbon\CarbonImmutable;

test('a journal entry also records the matching activity', function () {
    $actor = userWithRole('MEAL');
    $plan = Activity::withoutRecording(fn () => AnnualPlan::factory()->submitted()->create());
    $this->actingAs($actor);

    $event = app(PlanJournal::class)->write(
        $plan,
        PlanEventAction::Returned,
        activityEvent: 'returned',
        note: 'Masters needs a second month',
        from: PlanStatus::Submitted,
        to: PlanStatus::Draft,
    );

    $activity = Activity::query()->where('event', 'annual_plan.returned')->sole();

    expect($event->user_id)->toBe($actor->id)
        ->and($event->note)->toBe('Masters needs a second month')
        ->and($event->to_status)->toBe(PlanStatus::Draft)
        ->and($activity->subject_id)->toBe($plan->id)
        ->and($activity->properties['note'])->toBe('Masters needs a second month');
});

test('activity sentences name the activity and render the month in the reader language', function () {
    $this->actingAs(userWithRole('Managers'));
    $plan = Activity::withoutRecording(fn () => AnnualPlan::factory()->active()->create());
    $row = Activity::withoutRecording(fn () => PlanActivity::factory()->create(['annual_plan_id' => $plan->id]));

    app(PlanJournal::class)->write($plan, PlanEventAction::Reported, activityEvent: 'activity_reported', activity: $row, month: CarbonImmutable::parse('2027-09-01'));

    $sentence = app(ActivityPresenter::class)->sentence(Activity::query()->where('event', 'annual_plan.activity_reported')->sole(), 'en');

    expect($sentence)->toContain('“'.$row->name().'”')->toContain('Sep 2027');
});

test('a journal entry without an activity event records nothing in the activity log', function () {
    $this->actingAs(userWithRole('Managers'));
    $plan = Activity::withoutRecording(fn () => AnnualPlan::factory()->create());

    app(PlanJournal::class)->write($plan, PlanEventAction::ActivityAdded);

    expect(Activity::query()->count())->toBe(0)
        ->and($plan->events()->count())->toBe(1);
});
```

(The English sentence for `activity_reported` is added in Task 11. Until then this second test fails on the missing key: **implement Task 11 Step 4 (en sentences) together with this task**, or reorder so this test runs after Task 11. The plan keeps Task 11 separate because its recipients work is reviewable on its own.)

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanJournalTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement `PlanJournal`**

```php
<?php

declare(strict_types=1);

namespace App\Support\Plans;

use App\Enums\PlanEventAction;
use App\Enums\PlanStatus;
use App\Models\AnnualPlan;
use App\Models\AnnualPlanEvent;
use App\Models\PlanActivity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

/**
 * The one writer of a plan's history.
 *
 * Every phase move and tracking action leaves two traces: a durable row in
 * annual_plan_events (the plan's own journal, never pruned) and, when the
 * action is news, an annual_plan.* activity, which is what notifies people.
 * Writing both here keeps them from drifting apart.
 */
final class PlanJournal
{
    /**
     * @param  array<string, mixed>  $properties  extra activity properties (reporter_id, value…)
     */
    public function write(
        AnnualPlan $plan,
        PlanEventAction $action,
        ?string $activityEvent = null,
        ?PlanActivity $activity = null,
        ?int $projectId = null,
        ?CarbonImmutable $month = null,
        ?string $note = null,
        ?PlanStatus $from = null,
        ?PlanStatus $to = null,
        array $properties = [],
    ): AnnualPlanEvent {
        $event = AnnualPlanEvent::query()->create([
            'annual_plan_id' => $plan->id,
            'plan_activity_id' => $activity?->id,
            'project_id' => $projectId,
            'user_id' => Auth::id(),
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'month' => $month?->toDateString(),
            'note' => $note,
        ]);

        if ($activityEvent !== null) {
            $plan->recordActivity('annual_plan.'.$activityEvent, $from === null || $to === null ? [] : ['status' => [$from->value, $to->value]], array_filter([
                'activity' => $activity?->loadMissing('catalogActivity:id,name')->name(),
                'month' => $month?->toDateString(),
                'note' => $note,
                ...$properties,
            ], fn (mixed $value): bool => $value !== null && $value !== ''));
        }

        return $event;
    }
}
```

- [ ] **Step 4: Typed month params**

In `app/Notifications/ActivityParameters.php`:

1. Add `'activity'` to `PassThrough`:

```php
    private const PassThrough = ['subtask', 'file', 'note', 'role', 'baseline', 'value', 'added', 'removed', 'action', 'ip', 'activity'];
```

2. After the PassThrough loop, add:

```php
        foreach (['month', 'moved_to'] as $key) {
            if (isset($activity->properties[$key])) {
                $params[$key] = ['month' => substr((string) $activity->properties[$key], 0, 10)];
            }
        }
```

In `app/Notifications/NotificationPresenter.php::param()` add an arm right after the `'date'` arm:

```php
            isset($param['month']) => $this->formatDate($param['month'], (string) __('formats.month_year', [], $locale), $locale),
```

- [ ] **Step 5: Run, format, stage** (after Task 11's English sentences exist)

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanJournalTest.php tests/Feature/Notifications`
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add app/Support/Plans/PlanJournal.php app/Notifications/ActivityParameters.php app/Notifications/NotificationPresenter.php tests/Feature/AnnualPlans/PlanJournalTest.php
```

---

### Task 10: Permission `annual-plans.review` and policy abilities

**Files:**
- Create: `database/migrations/2026_10_07_100700_add_annual_plans_review_permission.php`
- Modify: `config/access-control.php`, `app/Policies/AnnualPlanPolicy.php`, `tests/Feature/AccessControl/RoleMatrixTest.php`
- Test: `tests/Feature/AnnualPlans/AnnualPlanPolicyTest.php`

**Interfaces:**
- Produces: permission `annual-plans.review`. `AnnualPlanPolicy` abilities:
  - `isOwner` (private)
  - `editDetails(User, AnnualPlan)`: owner and Draft
  - `editPlanned`: owner and Draft
  - `submit`: owner and Draft
  - `review(User, AnnualPlan)`: `annual-plans.review` and the plan is not Closed/Cancelled
  - `report(User, AnnualPlan)`: owner and Active
  - `track(User, AnnualPlan)`: (owner and Active) or (`annual-plans.review` and `allowsTracking()`)
  - `verify(User, AnnualPlan)`: `annual-plans.review` and `allowsTracking()`
  - `delete`: `annual-plans.delete` and status ∈ {Draft, Cancelled}
  - existing `update` keeps meaning "is an owner" (unchanged)

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\AnnualPlan;

test('the plan manager edits and submits only while drafting', function () {
    $employee = insertHrEmployee();
    $manager = userWithRole('Team Member', $employee);
    $draft = AnnualPlan::factory()->create(['manager_id' => $employee]);
    $submitted = AnnualPlan::factory()->submitted()->create(['manager_id' => $employee]);

    expect($manager->can('editPlanned', $draft))->toBeTrue()
        ->and($manager->can('submit', $draft))->toBeTrue()
        ->and($manager->can('editDetails', $draft))->toBeTrue()
        ->and($manager->can('editPlanned', $submitted))->toBeFalse()
        ->and($manager->can('submit', $submitted))->toBeFalse()
        ->and($manager->can('editDetails', $submitted))->toBeFalse();
});

test('meal reviews open plans but never edits the planned layer', function () {
    $meal = userWithRole('MEAL');
    $draft = AnnualPlan::factory()->create();
    $closed = AnnualPlan::factory()->closed()->create();

    expect($meal->can('review', $draft))->toBeTrue()
        ->and($meal->can('review', $closed))->toBeFalse()
        ->and($meal->can('editPlanned', $draft))->toBeFalse();
});

test('the manager reports only while active, meal verifies while active or in final review', function () {
    $employee = insertHrEmployee();
    $manager = userWithRole('Team Member', $employee);
    $meal = userWithRole('MEAL');
    $active = AnnualPlan::factory()->active()->create(['manager_id' => $employee]);
    $review = AnnualPlan::factory()->finalReview()->create(['manager_id' => $employee]);

    expect($manager->can('report', $active))->toBeTrue()
        ->and($manager->can('report', $review))->toBeFalse()
        ->and($meal->can('verify', $active))->toBeTrue()
        ->and($meal->can('verify', $review))->toBeTrue()
        ->and($meal->can('track', $review))->toBeTrue()
        ->and($manager->can('track', $review))->toBeFalse();
});

test('a plan is deleted only while draft or cancelled', function () {
    $managers = userWithRole('Managers');

    expect($managers->can('delete', AnnualPlan::factory()->create()))->toBeTrue()
        ->and($managers->can('delete', AnnualPlan::factory()->cancelled()->create()))->toBeTrue()
        ->and($managers->can('delete', AnnualPlan::factory()->active()->create()))->toBeFalse();
});

test('other roles cannot review', function () {
    expect(userWithRole('Team Member')->can('review', AnnualPlan::factory()->create()))->toBeFalse();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/AnnualPlanPolicyTest.php`
Expected: FAIL.

- [ ] **Step 3: Config**

`config/access-control.php`:

```php
    'annual-plans' => ['view', 'create', 'update', 'delete', 'review'],
```

and in the `'MEAL'` baseline append `'annual-plans.review',`.

In `tests/Feature/AccessControl/RoleMatrixTest.php`, add `'annual-plans.review',` to the MEAL list (after `'targets.delete',`). The General Manager test derives its list from the DB, so it needs no change.

- [ ] **Step 4: Rollout migration** (mirrors `2026_09_28_162144_add_activity_log_view_permission.php`)

```php
<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Roles that review plans on deploy. General Manager and Super Admin are
     * included because the General Manager holds every permission except a
     * fixed list, and Super Admin holds them all (Role::booted()).
     */
    private const array Reviewers = ['MEAL', 'General Manager', 'Super Admin'];

    public function up(): void
    {
        $permission = Permission::query()->firstOrCreate(['name' => 'annual-plans.review', 'guard_name' => 'web']);

        Role::query()
            ->with('permissions')
            ->whereIn('name', self::Reviewers)
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'annual-plans.review')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
```

- [ ] **Step 5: Policy**

Replace `update()` body with a call to a private `isOwner()` and add the abilities:

```php
    /**
     * Determine whether the user answers for the plan: its manager, the
     * manager of its program, or an annual-plans.update holder.
     */
    public function update(User $user, AnnualPlan $annualPlan): bool
    {
        return $this->isOwner($user, $annualPlan);
    }

    /**
     * Period, scope and manager change only while the plan is a draft.
     */
    public function editDetails(User $user, AnnualPlan $annualPlan): bool
    {
        return $annualPlan->status->isDraft() && $this->isOwner($user, $annualPlan);
    }

    /**
     * Activities and planned months change only while the plan is a draft;
     * leaving the draft freezes the planned layer (user decision 2026-10-06).
     */
    public function editPlanned(User $user, AnnualPlan $annualPlan): bool
    {
        return $annualPlan->status->isDraft() && $this->isOwner($user, $annualPlan);
    }

    public function submit(User $user, AnnualPlan $annualPlan): bool
    {
        return $this->editPlanned($user, $annualPlan);
    }

    /**
     * MEAL's side of the workflow: return, approve, cancel, targets,
     * milestones, final review, close.
     */
    public function review(User $user, AnnualPlan $annualPlan): bool
    {
        return $user->can('annual-plans.review') && $annualPlan->status->isOpen();
    }

    /**
     * The project manager reports what happened while the plan is active.
     */
    public function report(User $user, AnnualPlan $annualPlan): bool
    {
        return $annualPlan->status === PlanStatus::Active && $this->isOwner($user, $annualPlan);
    }

    /**
     * MEAL settles the actual layer while active and through the final review.
     */
    public function verify(User $user, AnnualPlan $annualPlan): bool
    {
        return $user->can('annual-plans.review') && $annualPlan->status->allowsTracking();
    }

    /**
     * Anyone who may change the actual layer right now.
     */
    public function track(User $user, AnnualPlan $annualPlan): bool
    {
        return $this->report($user, $annualPlan) || $this->verify($user, $annualPlan);
    }

    public function delete(User $user, AnnualPlan $annualPlan): bool
    {
        return $user->can('annual-plans.delete')
            && in_array($annualPlan->status, [PlanStatus::Draft, PlanStatus::Cancelled], true);
    }

    private function isOwner(User $user, AnnualPlan $annualPlan): bool
    {
        return $user->can('annual-plans.update')
            || ($user->employee_id !== null && $annualPlan->manager_id === $user->employee_id)
            || in_array($annualPlan->program_id, $user->managedProgramIds(), true);
    }
```

Update the class docblock to describe the phase rules. `use App\Enums\PlanStatus;`.

- [ ] **Step 6: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/AnnualPlanPolicyTest.php tests/Feature/AccessControl/RoleMatrixTest.php tests/Feature/RolesAndPermissionsSeederTest.php tests/Feature/AccessControl/PlanningAuthorizationTest.php`
Expected: PASS, except any `PlanningAuthorizationTest` case deleting an *active* (default factory = draft, so these should still pass). If one fails because it deletes a seeded active plan, switch that fixture to `AnnualPlan::factory()->create()` (draft) and note it.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add config/access-control.php database/migrations/2026_10_07_100700_add_annual_plans_review_permission.php app/Policies/AnnualPlanPolicy.php tests/Feature/AccessControl/RoleMatrixTest.php tests/Feature/AnnualPlans/AnnualPlanPolicyTest.php
```

---

### Task 11: Who hears about plan events (recipients + sentences)

**Files:**
- Create: `app/Notifications/Recipients/AnnualPlanRecipients.php`
- Modify: `config/notifications.php` (`'annual-plans' => AnnualPlanRecipients::class`), `lang/en/notifications.php`, `lang/ar/notifications.php`
- Test: `tests/Feature/Notifications/AnnualPlanWorkflowNotificationsTest.php`

**Interfaces:**
- Consumes: activity events written by `PlanJournal` (`annual_plan.<event>`), properties `reporter_id`, `employee_ids`.
- Produces: recipient map below, and sentence keys `notifications.events.annual_plan.{submitted,returned,returned_you,approved,approved_you,review_opened,closed,cancelled,activity_reported,activity_postponed,activity_verified,activity_sent_back,activity_missed,unplanned_reported,unplanned_activity_added,target_set,milestone_set}`.

Recipient map (actor never hears about their own action; the resolver already drops them):

| Event | Direct (people) | Watcher roles | Key ★ |
|---|---|---|---|
| `created`, `updated`, `manager_changed`, `deleted` | manager (+ previous manager) | Managers | no |
| `submitted` | manager | MEAL | no |
| `returned` | manager | none | no |
| `approved` | manager, program manager | Managers | yes |
| `review_opened` | manager, program manager | MEAL | no |
| `closed` | manager, program manager | Managers | yes |
| `cancelled` | manager, program manager | MEAL, Managers | yes |
| `activity_reported`, `unplanned_reported` | none | MEAL | no |
| `activity_postponed` | manager | MEAL | no |
| `activity_verified`, `activity_sent_back` | manager, reporter | none | no |
| `activity_missed` | manager, program manager | none | no |
| `unplanned_activity_added` | manager | MEAL | no |
| `target_set`, `milestone_set` | manager | none | no |

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\PlanEventAction;
use App\Enums\PlanStatus;
use App\Models\Activity;
use App\Models\AnnualPlan;
use App\Models\PlanActivity;
use App\Models\Program;
use App\Support\Plans\PlanJournal;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->managerEmployee = insertHrEmployee(firstNameEn: 'Rania', lastNameEn: 'Saleh');
    $this->manager = userWithRole('Team Member', $this->managerEmployee);
    $this->programManagerEmployee = insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Kassem');
    $this->programManager = userWithRole('Managers', $this->programManagerEmployee);
    $this->meal = userWithRole('MEAL');
    $this->otherMeal = userWithRole('MEAL');
    $this->watcher = userWithRole('Managers');
    $this->plan = Activity::withoutRecording(fn () => AnnualPlan::factory()->create([
        'program_id' => Program::factory()->create(['manager_id' => $this->programManagerEmployee])->id,
        'manager_id' => $this->managerEmployee,
    ]));
});

test('sending to MEAL reaches every MEAL user, not the bystanders', function () {
    $this->actingAs($this->manager);

    app(PlanJournal::class)->write($this->plan, PlanEventAction::Submitted, activityEvent: 'submitted', from: PlanStatus::Draft, to: PlanStatus::Submitted);

    expect(sentencesFor($this->meal))->toHaveCount(1)
        ->and(sentencesFor($this->otherMeal))->toHaveCount(1)
        ->and(sentencesFor($this->watcher))->toBe([])
        ->and(sentencesFor($this->manager))->toBe([]);
});

test('a return tells the manager why, in their words', function () {
    $this->actingAs($this->meal);

    app(PlanJournal::class)->write($this->plan, PlanEventAction::Returned, activityEvent: 'returned', note: 'Add a second Masters month', from: PlanStatus::Submitted, to: PlanStatus::Draft);

    expect(sentencesFor($this->manager)[0])->toContain('Add a second Masters month')
        ->and(sentencesFor($this->otherMeal))->toBe([]);
});

test('approval reaches the manager, the program manager and watching managers', function () {
    $this->actingAs($this->meal);

    app(PlanJournal::class)->write($this->plan, PlanEventAction::Approved, activityEvent: 'approved', from: PlanStatus::Submitted, to: PlanStatus::Active);

    expect(sentencesFor($this->manager))->toHaveCount(1)
        ->and(sentencesFor($this->programManager))->toHaveCount(1)
        ->and(sentencesFor($this->watcher))->toHaveCount(1);
});

test('a verification reaches the person who reported', function () {
    $reporter = userWithRole('Team Member');
    $row = Activity::withoutRecording(fn () => PlanActivity::factory()->create(['annual_plan_id' => $this->plan->id]));
    $this->actingAs($this->meal);

    app(PlanJournal::class)->write($this->plan, PlanEventAction::Verified, activityEvent: 'activity_verified', activity: $row, month: CarbonImmutable::parse('2027-03-01'), properties: ['reporter_id' => $reporter->id]);

    expect(sentencesFor($reporter))->toHaveCount(1)
        ->and(sentencesFor($this->manager))->toHaveCount(1);
});

test('every plan event has an english and arabic sentence', function (string $event) {
    expect(__('notifications.events.annual_plan.'.$event, [], 'en'))->not->toStartWith('notifications.')
        ->and(__('notifications.events.annual_plan.'.$event, [], 'ar'))->not->toStartWith('notifications.');
})->with(['submitted', 'returned', 'approved', 'review_opened', 'closed', 'cancelled', 'activity_reported', 'activity_postponed', 'activity_verified', 'activity_sent_back', 'activity_missed', 'unplanned_reported', 'unplanned_activity_added', 'target_set', 'milestone_set']);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/Notifications/AnnualPlanWorkflowNotificationsTest.php`
Expected: FAIL.

- [ ] **Step 3: Recipients class**

```php
<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;
use App\Models\AnnualPlan;
use App\Models\Program;

/**
 * Annual plans: the plan manager is direct on everything about their plan;
 * MEAL watch what waits on them (submissions, reports); Managers watch the
 * outcomes (approval, closing, cancellation). See the table in the plan doc
 * docs/superpowers/plans/2026-10-06-annual-plan-tracking.md, Task 11.
 */
final class AnnualPlanRecipients extends ModuleRecipients
{
    private const array MealWatches = ['submitted', 'review_opened', 'cancelled', 'activity_reported', 'unplanned_reported', 'activity_postponed', 'unplanned_activity_added'];

    private const array ManagersWatch = ['created', 'updated', 'manager_changed', 'deleted', 'approved', 'closed', 'cancelled'];

    private const array ProgramManagerHears = ['approved', 'review_opened', 'closed', 'cancelled', 'activity_missed'];

    private const array ManagerSkips = ['activity_reported', 'unplanned_reported'];

    private const array KeyEvents = ['approved', 'closed', 'cancelled'];

    public function directEmployeeIds(Activity $activity): array
    {
        $suffix = $this->suffix($activity);
        $plan = AnnualPlan::query()->find($activity->subject_id, ['id', 'program_id', 'manager_id']);

        return $this->uniqueIds([
            in_array($suffix, self::ManagerSkips, true) ? null : $plan?->manager_id,
            in_array($suffix, self::ProgramManagerHears, true) && $plan !== null
                ? Program::query()->whereKey($plan->program_id)->value('manager_id')
                : null,
            ...$this->idsFromProperty($activity, 'employee_ids'),
            $this->changedFrom($activity, 'manager_id'),
        ]);
    }

    public function directUserIds(Activity $activity): array
    {
        return in_array($this->suffix($activity), ['activity_verified', 'activity_sent_back'], true)
            ? $this->idsFromProperty($activity, 'reporter_id')
            : [];
    }

    public function assignedEmployeeIds(Activity $activity): array
    {
        return $this->uniqueIds([match ($this->suffix($activity)) {
            'manager_changed' => $this->changedTo($activity, 'manager_id'),
            'created' => AnnualPlan::query()->whereKey($activity->subject_id)->value('manager_id'),
            default => null,
        }]);
    }

    public function watcherRoles(Activity $activity): array
    {
        $suffix = $this->suffix($activity);

        return array_values(array_filter([
            in_array($suffix, self::MealWatches, true) ? 'MEAL' : null,
            in_array($suffix, self::ManagersWatch, true) ? 'Managers' : null,
        ]));
    }

    public function isKeyEvent(Activity $activity): bool
    {
        return in_array($this->suffix($activity), self::KeyEvents, true);
    }

    private function suffix(Activity $activity): string
    {
        return substr($activity->event, strlen('annual_plan.'));
    }
}
```

In `config/notifications.php` change `'annual-plans' => ManagedRecipients::class,` to `'annual-plans' => AnnualPlanRecipients::class,` (+ `use`). Keep `ManagedRecipients` for `programs`.

- [ ] **Step 4: Sentences**

Replace the `'annual_plan' => [...]` block in `lang/en/notifications.php` `events` with:

```php
        'annual_plan' => [
            'created' => ':actor created the annual plan “:subject”',
            'created_you' => ':actor made you the manager of the new annual plan “:subject”',
            'manager_changed' => ':actor made :to the manager of the annual plan “:subject”',
            'manager_changed_you' => ':actor made you the manager of the annual plan “:subject”',
            'updated' => ':actor updated the annual plan “:subject”',
            'deleted' => ':actor deleted the annual plan “:subject”',
            'submitted' => ':actor sent the plan “:subject” to MEAL for review',
            'returned' => ':actor returned “:subject” for edits: :note',
            'approved' => ':actor approved “:subject”; it is now active',
            'review_opened' => ':actor opened the final review of “:subject”',
            'closed' => ':actor closed “:subject” after the final review',
            'cancelled' => ':actor cancelled “:subject”: :note',
            'activity_reported' => ':actor reported “:activity” done for :month in “:subject”',
            'activity_postponed' => ':actor postponed “:activity” from :month to :moved_to in “:subject”',
            'activity_verified' => ':actor verified “:activity” for :month in “:subject”',
            'activity_sent_back' => ':actor sent back the report on “:activity” for :month in “:subject”: :note',
            'activity_missed' => ':actor marked “:activity” missed for :month in “:subject”',
            'unplanned_reported' => ':actor recorded the unplanned “:activity” in :month in “:subject”',
            'unplanned_activity_added' => ':actor added the unplanned activity “:activity” to “:subject”',
            'target_set' => ':actor set a target on “:activity” in “:subject”',
            'milestone_set' => ':actor set the milestone “:value” on “:activity” in “:subject”',
        ],
```

`lang/ar/notifications.php` `'annual_plan'` block (keep the existing six Arabic sentences exactly as they are, add the rest):

```php
            'submitted' => 'أرسل :actor الخطة «:subject» إلى المتابعة والتقييم للمراجعة',
            'returned' => 'أعاد :actor الخطة «:subject» للتعديل: :note',
            'approved' => 'اعتمد :actor الخطة «:subject» وأصبحت نشطة',
            'review_opened' => 'فتح :actor المراجعة النهائية للخطة «:subject»',
            'closed' => 'أغلق :actor الخطة «:subject» بعد المراجعة النهائية',
            'cancelled' => 'ألغى :actor الخطة «:subject»: :note',
            'activity_reported' => 'أبلغ :actor عن تنفيذ «:activity» في :month ضمن «:subject»',
            'activity_postponed' => 'أجّل :actor «:activity» من :month إلى :moved_to ضمن «:subject»',
            'activity_verified' => 'تحقق :actor من «:activity» في :month ضمن «:subject»',
            'activity_sent_back' => 'أعاد :actor التقرير عن «:activity» في :month ضمن «:subject»: :note',
            'activity_missed' => 'سجّل :actor «:activity» كغير منفّذ في :month ضمن «:subject»',
            'unplanned_reported' => 'سجّل :actor النشاط غير المخطط «:activity» في :month ضمن «:subject»',
            'unplanned_activity_added' => 'أضاف :actor النشاط غير المخطط «:activity» إلى «:subject»',
            'target_set' => 'حدّد :actor مستهدفًا على «:activity» ضمن «:subject»',
            'milestone_set' => 'حدّد :actor المعلم «:value» على «:activity» ضمن «:subject»',
```

> Check the existing Arabic sentences' quote style (« » vs “ ”) first, and match it.

- [ ] **Step 5: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/Notifications/AnnualPlanWorkflowNotificationsTest.php tests/Feature/Notifications/PlanningActivityTest.php tests/Feature/AnnualPlans/PlanJournalTest.php tests/Feature/LocalizationTest.php`
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add app/Notifications/Recipients/AnnualPlanRecipients.php config/notifications.php lang/en/notifications.php lang/ar/notifications.php tests/Feature/Notifications/AnnualPlanWorkflowNotificationsTest.php
```

---
### Task 12: Create and edit a plan's scope, period and manager

**Files:**
- Create: `app/Actions/AnnualPlans/SavePlanDetails.php`
- Modify: `lang/{en,ar}/annual_plans.php`
- Test: `tests/Feature/AnnualPlans/SavePlanDetailsTest.php`

**Interfaces:**
- Consumes: `PlanSchedule::errors()`, `PlanPeriod::fromKey()`, `PlanJournal::write()`, policy `create` / `editDetails` / `reassign`.
- Produces: `SavePlanDetails::handle(?AnnualPlan $plan, array{programId: int, projectId: int|null, managerId: int, startMonth: string, endMonth: string} $input): AnnualPlan`. It throws `ValidationException` keyed `planProgramId|planProjectId|planStartMonth|planEndMonth`. When a draft's period shrinks, it drops planned months that fall outside the new period.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Actions\AnnualPlans\SavePlanDetails;
use App\Enums\PlanEventAction;
use App\Enums\PlanStatus;
use App\Models\AnnualPlan;
use App\Models\PlanActivity;
use App\Models\PlanActivityMonth;
use App\Models\Program;
use App\Models\Project;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

test('a manager-level user creates a nine-month program plan', function () {
    $this->actingAs(userWithRole('Managers'));
    $program = Program::factory()->create();
    $manager = insertHrEmployee();

    $plan = app(SavePlanDetails::class)->handle(null, [
        'programId' => $program->id, 'projectId' => null, 'managerId' => $manager,
        'startMonth' => '2027-04', 'endMonth' => '2027-12',
    ]);

    expect($plan->status)->toBe(PlanStatus::Draft)
        ->and($plan->year)->toBe(2027)
        ->and($plan->starts_on->toDateString())->toBe('2027-04-01')
        ->and($plan->ends_on->toDateString())->toBe('2027-12-31')
        ->and($plan->events()->sole()->action)->toBe(PlanEventAction::Created);
});

test('a project plan is created inside its program', function () {
    $this->actingAs(userWithRole('Managers'));
    $project = Project::factory()->create();

    $plan = app(SavePlanDetails::class)->handle(null, [
        'programId' => $project->program_id, 'projectId' => $project->id, 'managerId' => insertHrEmployee(),
        'startMonth' => '2027-01', 'endMonth' => '2027-03',
    ]);

    expect($plan->project_id)->toBe($project->id);
});

test('an overlapping period is refused', function () {
    $this->actingAs(userWithRole('Managers'));
    $program = Program::factory()->create();
    AnnualPlan::factory()->between('2027-01', '2027-12')->create(['program_id' => $program->id]);

    expect(fn () => app(SavePlanDetails::class)->handle(null, [
        'programId' => $program->id, 'projectId' => null, 'managerId' => insertHrEmployee(),
        'startMonth' => '2027-06', 'endMonth' => '2027-08',
    ]))->toThrow(ValidationException::class);
});

test('the plan manager may move the months but not hand the plan to someone else', function () {
    $employee = insertHrEmployee();
    $this->actingAs(userWithRole('Team Member', $employee));
    $plan = AnnualPlan::factory()->between('2027-01', '2027-12')->create(['manager_id' => $employee]);

    app(SavePlanDetails::class)->handle($plan, [
        'programId' => $plan->program_id, 'projectId' => null, 'managerId' => insertHrEmployee(),
        'startMonth' => '2027-02', 'endMonth' => '2027-12',
    ]);

    expect($plan->fresh()->manager_id)->toBe($employee)
        ->and($plan->fresh()->starts_on->toDateString())->toBe('2027-02-01');
});

test('shrinking a draft drops planned months outside the new period', function () {
    $this->actingAs(userWithRole('Managers'));
    $plan = AnnualPlan::factory()->between('2027-01', '2027-12')->create();
    $activity = PlanActivity::factory()->inMonths('2027-01', '2027-06', '2027-12')->create(['annual_plan_id' => $plan->id]);

    app(SavePlanDetails::class)->handle($plan, [
        'programId' => $plan->program_id, 'projectId' => null, 'managerId' => $plan->manager_id,
        'startMonth' => '2027-03', 'endMonth' => '2027-09',
    ]);

    expect($activity->months()->get()->map(fn (PlanActivityMonth $month): string => $month->month->toDateString())->all())->toBe(['2027-06-01']);
});

test('a plan that left the draft cannot be edited', function () {
    $this->actingAs(userWithRole('Managers'));
    $plan = AnnualPlan::factory()->active()->create();

    expect(fn () => app(SavePlanDetails::class)->handle($plan, [
        'programId' => $plan->program_id, 'projectId' => null, 'managerId' => $plan->manager_id,
        'startMonth' => '2027-01', 'endMonth' => '2027-06',
    ]))->toThrow(AuthorizationException::class);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/SavePlanDetailsTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\PlanEventAction;
use App\Models\AnnualPlan;
use App\Models\PlanActivity;
use App\Models\PlanActivityMonth;
use App\Support\Plans\PlanJournal;
use App\Support\Plans\PlanPeriod;
use App\Support\Plans\PlanSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Create a plan, or change a draft's scope, period and manager.
 *
 * Moving a plan to another program, project or manager stays a management
 * decision (the `reassign` ability); the plan's own manager may only move its
 * months. A shorter period drops the planned months it no longer covers.
 */
class SavePlanDetails
{
    public function __construct(
        private PlanSchedule $schedule,
        private PlanJournal $journal,
    ) {}

    /**
     * @param  array{programId: int, projectId: int|null, managerId: int, startMonth: string, endMonth: string}  $input
     */
    public function handle(?AnnualPlan $plan, array $input): AnnualPlan
    {
        if ($plan === null) {
            Gate::authorize('create', AnnualPlan::class);
        } else {
            Gate::authorize('editDetails', $plan);
        }

        try {
            $start = PlanPeriod::fromKey($input['startMonth']);
            $end = PlanPeriod::fromKey($input['endMonth'])->endOfMonth();
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['planStartMonth' => __('annual_plans.choose_the_months_this_plan_covers')]);
        }

        $isNew = $plan === null;
        $plan ??= new AnnualPlan;
        $mayReassign = $isNew || Gate::allows('reassign', $plan);

        $programId = $mayReassign ? $input['programId'] : $plan->program_id;
        $projectId = $mayReassign ? $input['projectId'] : $plan->project_id;
        $managerId = $mayReassign ? $input['managerId'] : $plan->manager_id;

        $errors = $this->schedule->errors($programId, $projectId, $start, $end, $plan->id);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($plan, $isNew, $programId, $projectId, $managerId, $start, $end): AnnualPlan {
            $plan->fill([
                'program_id' => $programId,
                'project_id' => $projectId,
                'manager_id' => $managerId,
                'starts_on' => $start,
                'ends_on' => $end,
            ])->save();

            if ($isNew) {
                $this->journal->write($plan, PlanEventAction::Created);

                return $plan;
            }

            PlanActivityMonth::query()
                ->whereIn('plan_activity_id', PlanActivity::query()->where('annual_plan_id', $plan->id)->select('id'))
                ->where(fn ($query) => $query->where('month', '<', $start->toDateString())->orWhere('month', '>', $end->toDateString()))
                ->delete();

            return $plan;
        });
    }
}
```

Lang `annual_plans.php`. en: `'choose_the_months_this_plan_covers' => 'Choose the months this plan covers.',` ar: `'choose_the_months_this_plan_covers' => 'اختر الأشهر التي تغطيها هذه الخطة.',`

- [ ] **Step 4: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/SavePlanDetailsTest.php`
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add app/Actions/AnnualPlans/SavePlanDetails.php lang/en/annual_plans.php lang/ar/annual_plans.php tests/Feature/AnnualPlans/SavePlanDetailsTest.php
```

---

### Task 13: The planned layer (draft only)

**Files:**
- Create: `app/Actions/AnnualPlans/AddPlanActivity.php`, `RemovePlanActivity.php`, `TogglePlannedMonth.php`, `UpdatePlanActivity.php`
- Modify: `lang/{en,ar}/annual_plans.php`
- Test: `tests/Feature/AnnualPlans/PlannedLayerTest.php`

**Interfaces:**
- Produces:
  - `AddPlanActivity::handle(AnnualPlan $plan, CatalogActivity $catalogActivity): PlanActivity`, plus static helper `AddPlanActivity::place(AnnualPlan, CatalogActivity, bool $isUnplanned, PlanJournal, PlanEventAction, ?string $activityEvent, ?int $responsibleEmployeeId = null): PlanActivity`, which Task 15 reuses
  - `RemovePlanActivity::handle(PlanActivity $activity): void`
  - `TogglePlannedMonth::handle(PlanActivity $activity, CarbonImmutable $month): bool` (whether the month is now planned)
  - `UpdatePlanActivity::handle(PlanActivity $activity, ?int $responsibleEmployeeId, PlanActivityTracking $tracking): PlanActivity`
  - All authorize `editPlanned` on the plan. Validation keys: `catalogActivityId`, `month`, `activityTracking`, `activityResponsibleId`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Actions\AnnualPlans\AddPlanActivity;
use App\Actions\AnnualPlans\RemovePlanActivity;
use App\Actions\AnnualPlans\TogglePlannedMonth;
use App\Actions\AnnualPlans\UpdatePlanActivity;
use App\Enums\PlanActivityTracking;
use App\Enums\PlanEventAction;
use App\Models\AnnualPlan;
use App\Models\CatalogActivity;
use App\Models\PlanActivity;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->employee = insertHrEmployee();
    $this->actingAs(userWithRole('Team Member', $this->employee));
    $this->plan = AnnualPlan::factory()->between('2027-01', '2027-12')->create(['manager_id' => $this->employee]);
});

test('the manager adds catalog activities in order and the journal notes each', function () {
    $first = app(AddPlanActivity::class)->handle($this->plan, CatalogActivity::factory()->create(['name' => 'Beginner training']));
    $second = app(AddPlanActivity::class)->handle($this->plan, CatalogActivity::factory()->create(['name' => 'Masters training']));

    expect($second->sequence)->toBe($first->sequence + 1)
        ->and($this->plan->events()->pluck('action')->all())->toBe([PlanEventAction::ActivityAdded, PlanEventAction::ActivityAdded]);
});

test('an activity is added once and never from a switched-off catalog entry', function () {
    $catalog = CatalogActivity::factory()->create();
    app(AddPlanActivity::class)->handle($this->plan, $catalog);

    expect(fn () => app(AddPlanActivity::class)->handle($this->plan, $catalog))->toThrow(ValidationException::class)
        ->and(fn () => app(AddPlanActivity::class)->handle($this->plan, CatalogActivity::factory()->inactive()->create()))->toThrow(ValidationException::class);
});

test('toggling a month plans it and toggling again clears it', function () {
    $activity = PlanActivity::factory()->create(['annual_plan_id' => $this->plan->id]);
    $march = CarbonImmutable::parse('2027-03-01');

    expect(app(TogglePlannedMonth::class)->handle($activity, $march))->toBeTrue()
        ->and($activity->months()->count())->toBe(1)
        ->and(app(TogglePlannedMonth::class)->handle($activity, $march))->toBeFalse()
        ->and($activity->months()->count())->toBe(0);
});

test('a month outside the period is refused', function () {
    $activity = PlanActivity::factory()->create(['annual_plan_id' => $this->plan->id]);

    expect(fn () => app(TogglePlannedMonth::class)->handle($activity, CarbonImmutable::parse('2028-01-01')))->toThrow(ValidationException::class);
});

test('removing an activity keeps a journal line with its name', function () {
    $activity = PlanActivity::factory()->create(['annual_plan_id' => $this->plan->id]);
    $name = $activity->name();

    app(RemovePlanActivity::class)->handle($activity);

    expect(PlanActivity::query()->count())->toBe(0)
        ->and($this->plan->events()->first()->note)->toBe($name);
});

test('per-project tracking is only for program-wide plans', function () {
    $projectPlan = AnnualPlan::factory()->forProject(Project::factory()->create())->create(['manager_id' => $this->employee]);
    $activity = PlanActivity::factory()->create(['annual_plan_id' => $projectPlan->id]);

    expect(fn () => app(UpdatePlanActivity::class)->handle($activity, null, PlanActivityTracking::PerProject))->toThrow(ValidationException::class);
});

test('once sent to meal nothing in the planned layer moves', function () {
    $activity = PlanActivity::factory()->create(['annual_plan_id' => $this->plan->id]);
    $this->plan->forceFill(['status' => 'submitted'])->save();

    expect(fn () => app(TogglePlannedMonth::class)->handle($activity->fresh(), CarbonImmutable::parse('2027-03-01')))->toThrow(AuthorizationException::class);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlannedLayerTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

`AddPlanActivity.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\PlanEventAction;
use App\Models\AnnualPlan;
use App\Models\CatalogActivity;
use App\Models\PlanActivity;
use App\Support\Plans\PlanJournal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Place a catalog activity in a draft plan as a new grid row, last in line.
 */
class AddPlanActivity
{
    public function __construct(private PlanJournal $journal) {}

    public function handle(AnnualPlan $plan, CatalogActivity $catalogActivity): PlanActivity
    {
        Gate::authorize('editPlanned', $plan);

        return DB::transaction(fn (): PlanActivity => self::place($plan, $catalogActivity, false, $this->journal, PlanEventAction::ActivityAdded, null));
    }

    /**
     * Shared with AddUnplannedActivity: refuse a switched-off or duplicate
     * entry, append the row and write its journal line.
     */
    public static function place(AnnualPlan $plan, CatalogActivity $catalogActivity, bool $isUnplanned, PlanJournal $journal, PlanEventAction $action, ?string $activityEvent, ?int $responsibleEmployeeId = null): PlanActivity
    {
        if (! $catalogActivity->is_active) {
            throw ValidationException::withMessages(['catalogActivityId' => __('annual_plans.this_activity_is_switched_off_in_the_catalog')]);
        }

        if (PlanActivity::query()->where('annual_plan_id', $plan->id)->where('catalog_activity_id', $catalogActivity->id)->exists()) {
            throw ValidationException::withMessages(['catalogActivityId' => __('annual_plans.this_activity_is_already_in_the_plan')]);
        }

        $activity = PlanActivity::query()->create([
            'annual_plan_id' => $plan->id,
            'catalog_activity_id' => $catalogActivity->id,
            'responsible_employee_id' => $responsibleEmployeeId,
            'is_unplanned' => $isUnplanned,
            'added_by' => Auth::id(),
            'sequence' => (int) PlanActivity::query()->where('annual_plan_id', $plan->id)->max('sequence') + 1,
        ]);

        $activity->setRelation('catalogActivity', $catalogActivity);

        $journal->write($plan, $action, activityEvent: $activityEvent, activity: $activity);

        return $activity;
    }
}
```

`RemovePlanActivity.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\PlanEventAction;
use App\Models\PlanActivity;
use App\Support\Plans\PlanJournal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Take a row out of a draft. Its planned months go with it; targets MEAL may
 * already have set on it stay on the plan (plan_activity_id nulls out).
 */
class RemovePlanActivity
{
    public function __construct(private PlanJournal $journal) {}

    public function handle(PlanActivity $activity): void
    {
        $plan = $activity->plan;

        Gate::authorize('editPlanned', $plan);

        DB::transaction(function () use ($activity, $plan): void {
            $this->journal->write($plan, PlanEventAction::ActivityRemoved, note: $activity->loadMissing('catalogActivity:id,name')->name());
            $activity->delete();
        });
    }
}
```

`TogglePlannedMonth.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Models\PlanActivity;
use App\Models\PlanActivityMonth;
use App\Support\Plans\PlanPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Put an X in a draft's grid, or take it out. Draft edits are the manager's
 * own workspace and are not journaled; the submission is the record.
 */
class TogglePlannedMonth
{
    /**
     * @return bool whether the month is planned after the toggle
     */
    public function handle(PlanActivity $activity, CarbonImmutable $month): bool
    {
        $plan = $activity->plan;

        Gate::authorize('editPlanned', $plan);

        $month = $month->startOfMonth();

        if (! PlanPeriod::contains($plan->starts_on, $plan->ends_on, $month)) {
            throw ValidationException::withMessages(['month' => __('annual_plans.that_month_is_outside_the_plan_period')]);
        }

        $existing = PlanActivityMonth::query()
            ->where('plan_activity_id', $activity->id)
            ->whereDate('month', $month->toDateString())
            ->first();

        if ($existing !== null) {
            $existing->delete();

            return false;
        }

        PlanActivityMonth::query()->create(['plan_activity_id' => $activity->id, 'month' => $month->toDateString()]);

        return true;
    }
}
```

`UpdatePlanActivity.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\PlanActivityTracking;
use App\Models\HrEmployee;
use App\Models\PlanActivity;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Set who answers for a row and how a program-wide row is tracked.
 */
class UpdatePlanActivity
{
    public function handle(PlanActivity $activity, ?int $responsibleEmployeeId, PlanActivityTracking $tracking): PlanActivity
    {
        $plan = $activity->plan;

        Gate::authorize('editPlanned', $plan);

        if ($tracking === PlanActivityTracking::PerProject && ! $plan->isProgramWide()) {
            throw ValidationException::withMessages(['activityTracking' => __('annual_plans.only_a_program_wide_plan_tracks_per_project')]);
        }

        if ($responsibleEmployeeId !== null && ! HrEmployee::query()->whereKey($responsibleEmployeeId)->exists()) {
            throw ValidationException::withMessages(['activityResponsibleId' => __('annual_plans.the_selected_person_is_no_longer_available')]);
        }

        $activity->fill(['responsible_employee_id' => $responsibleEmployeeId, 'tracking' => $tracking])->save();

        return $activity;
    }
}
```

Lang (en):

```php
    'only_a_program_wide_plan_tracks_per_project' => 'Only a program-wide plan can track an activity per project.',
    'that_month_is_outside_the_plan_period' => 'That month is outside the plan period.',
    'the_selected_person_is_no_longer_available' => 'The selected person is no longer available.',
    'this_activity_is_already_in_the_plan' => 'This activity is already in the plan.',
    'this_activity_is_switched_off_in_the_catalog' => 'This activity is switched off in the catalog.',
```

Lang (ar):

```php
    'only_a_program_wide_plan_tracks_per_project' => 'لا يمكن متابعة النشاط لكل مشروع إلا في خطة على مستوى البرنامج.',
    'that_month_is_outside_the_plan_period' => 'هذا الشهر خارج مدة الخطة.',
    'the_selected_person_is_no_longer_available' => 'الشخص المحدد لم يعد متاحًا.',
    'this_activity_is_already_in_the_plan' => 'هذا النشاط موجود في الخطة بالفعل.',
    'this_activity_is_switched_off_in_the_catalog' => 'هذا النشاط متوقف في قائمة الأنشطة.',
```

- [ ] **Step 4: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlannedLayerTest.php`
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add app/Actions/AnnualPlans lang/en/annual_plans.php lang/ar/annual_plans.php tests/Feature/AnnualPlans/PlannedLayerTest.php
```

---

### Task 14: The workflow (submit, return, approve, final review, close, cancel)

**Files:**
- Create: `app/Support/Plans/PlanTransition.php`, `app/Actions/AnnualPlans/SubmitPlan.php`, `ReturnPlanForEdits.php`, `ApprovePlan.php`, `OpenFinalReview.php`, `ClosePlan.php`, `CancelPlan.php`
- Modify: `lang/{en,ar}/annual_plans.php`
- Test: `tests/Feature/AnnualPlans/PlanWorkflowTest.php`

**Interfaces:**
- Produces:
  - `PlanTransition::move(AnnualPlan $plan, list<PlanStatus> $from, PlanStatus $to, array<string,mixed> $stamps, PlanEventAction $action, string $activityEvent, ?string $note = null): AnnualPlan` (locks the row, checks `$from`, saves, journals, refreshes `$plan` in place)
  - `SubmitPlan::handle(AnnualPlan): AnnualPlan`
  - `ReturnPlanForEdits::handle(AnnualPlan, string $reason): AnnualPlan`
  - `ApprovePlan::handle(AnnualPlan): AnnualPlan`
  - `OpenFinalReview::handle(AnnualPlan, bool $authorize = true): AnnualPlan`
  - `ClosePlan::handle(AnnualPlan, string $notes): AnnualPlan`
  - `CancelPlan::handle(AnnualPlan, string $reason): AnnualPlan`
  - Validation keys: `plan`, `returnReason`, `closingNotes`, `cancelReason`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Actions\AnnualPlans\ApprovePlan;
use App\Actions\AnnualPlans\CancelPlan;
use App\Actions\AnnualPlans\ClosePlan;
use App\Actions\AnnualPlans\OpenFinalReview;
use App\Actions\AnnualPlans\ReturnPlanForEdits;
use App\Actions\AnnualPlans\SubmitPlan;
use App\Enums\PlanEventAction;
use App\Enums\PlanStatus;
use App\Models\AnnualPlan;
use App\Models\PlanActivity;
use App\Models\PlanOccurrence;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->employee = insertHrEmployee();
    $this->manager = userWithRole('Team Member', $this->employee);
    $this->meal = userWithRole('MEAL');
    $this->plan = AnnualPlan::factory()->between('2027-01', '2027-12')->create(['manager_id' => $this->employee]);
});

test('a draft with no planned month cannot be sent', function () {
    $this->actingAs($this->manager);

    expect(fn () => app(SubmitPlan::class)->handle($this->plan))->toThrow(ValidationException::class);
});

test('the full happy path stamps every phase and journals it', function () {
    PlanActivity::factory()->inMonths('2027-02')->create(['annual_plan_id' => $this->plan->id]);

    $this->actingAs($this->manager);
    app(SubmitPlan::class)->handle($this->plan);
    expect($this->plan->status)->toBe(PlanStatus::Submitted)->and($this->plan->submitted_at)->not->toBeNull();

    $this->actingAs($this->meal);
    app(ApprovePlan::class)->handle($this->plan);
    expect($this->plan->status)->toBe(PlanStatus::Active)->and($this->plan->approved_by)->toBe($this->meal->id);

    app(OpenFinalReview::class)->handle($this->plan);
    app(ClosePlan::class)->handle($this->plan, 'Delivered most months; Jun slipped.');

    expect($this->plan->status)->toBe(PlanStatus::Closed)
        ->and($this->plan->closing_notes)->toBe('Delivered most months; Jun slipped.')
        ->and($this->plan->events()->pluck('action')->all())->toBe([
            PlanEventAction::Closed, PlanEventAction::ReviewOpened, PlanEventAction::Approved, PlanEventAction::Submitted,
        ]);
});

test('meal returns a submission with a reason, which is kept', function () {
    PlanActivity::factory()->inMonths('2027-02')->create(['annual_plan_id' => $this->plan->id]);
    $this->actingAs($this->manager);
    app(SubmitPlan::class)->handle($this->plan);

    $this->actingAs($this->meal);
    app(ReturnPlanForEdits::class)->handle($this->plan, 'Masters needs a second month');

    $event = $this->plan->events()->first();

    expect($this->plan->status)->toBe(PlanStatus::Draft)
        ->and($this->plan->submitted_at)->toBeNull()
        ->and($event->action)->toBe(PlanEventAction::Returned)
        ->and($event->note)->toBe('Masters needs a second month')
        ->and($event->from_status)->toBe(PlanStatus::Submitted);
});

test('a return needs a reason', function () {
    $plan = AnnualPlan::factory()->submitted()->create();
    $this->actingAs($this->meal);

    expect(fn () => app(ReturnPlanForEdits::class)->handle($plan, '  '))->toThrow(ValidationException::class);
});

test('approving a plan that already moved on is refused', function () {
    $plan = AnnualPlan::factory()->submitted()->create();
    $this->actingAs($this->meal);
    app(ApprovePlan::class)->handle($plan);

    expect(fn () => app(ApprovePlan::class)->handle(AnnualPlan::query()->findOrFail($plan->id)))->toThrow(ValidationException::class);
});

test('the manager cannot approve their own plan', function () {
    $plan = AnnualPlan::factory()->submitted()->create(['manager_id' => $this->employee]);
    $this->actingAs($this->manager);

    expect(fn () => app(ApprovePlan::class)->handle($plan))->toThrow(AuthorizationException::class);
});

test('closing waits until every report is settled and needs notes', function () {
    $plan = AnnualPlan::factory()->finalReview()->create();
    PlanOccurrence::factory()->reported()->create(['plan_activity_id' => PlanActivity::factory()->create(['annual_plan_id' => $plan->id])->id]);
    $this->actingAs($this->meal);

    expect(fn () => app(ClosePlan::class)->handle($plan, 'All delivered as planned.'))->toThrow(ValidationException::class)
        ->and(fn () => app(ClosePlan::class)->handle($plan, 'ok'))->toThrow(ValidationException::class);
});

test('meal cancels an active plan with a reason, a draft cannot be cancelled', function () {
    $active = AnnualPlan::factory()->active()->create();
    $this->actingAs($this->meal);

    app(CancelPlan::class)->handle($active, 'Funding withdrawn');

    expect($active->status)->toBe(PlanStatus::Cancelled)
        ->and($active->cancel_reason)->toBe('Funding withdrawn')
        ->and(fn () => app(CancelPlan::class)->handle($this->plan, 'Not needed any more'))->toThrow(ValidationException::class);
});

test('the system opens the final review without a user', function () {
    $plan = AnnualPlan::factory()->active()->create();

    app(OpenFinalReview::class)->handle($plan, authorize: false);

    expect($plan->status)->toBe(PlanStatus::FinalReview)
        ->and($plan->events()->first()->user_id)->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanWorkflowTest.php`
Expected: FAIL.

- [ ] **Step 3: `PlanTransition`**

```php
<?php

declare(strict_types=1);

namespace App\Support\Plans;

use App\Enums\PlanEventAction;
use App\Enums\PlanStatus;
use App\Models\AnnualPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a plan between phases safely.
 *
 * The row is re-read under a lock so two reviewers acting at once cannot
 * both approve, or one approve while the other returns: the second sees the
 * new status and is refused with a message rather than a silent overwrite.
 */
final class PlanTransition
{
    public function __construct(private PlanJournal $journal) {}

    /**
     * @param  list<PlanStatus>  $from
     * @param  array<string, mixed>  $stamps
     */
    public function move(AnnualPlan $plan, array $from, PlanStatus $to, array $stamps, PlanEventAction $action, string $activityEvent, ?string $note = null): AnnualPlan
    {
        return DB::transaction(function () use ($plan, $from, $to, $stamps, $action, $activityEvent, $note): AnnualPlan {
            $locked = AnnualPlan::query()->lockForUpdate()->findOrFail($plan->id);

            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['plan' => __('annual_plans.this_plan_has_moved_on', ['status' => $locked->status->label()])]);
            }

            $previous = $locked->status;
            $locked->forceFill(['status' => $to, ...$stamps])->save();

            $this->journal->write($locked, $action, activityEvent: $activityEvent, note: $note, from: $previous, to: $to);

            $plan->setRawAttributes($locked->getAttributes(), true);

            return $plan;
        });
    }
}
```

The `$locked->save()` fires `RecordsActivity`'s `updated` hook. Every column it changes is in `activityExcludedFields()` (Task 2), so `activityChanges()` is empty and no generic `annual_plan.updated` row is written. The journal records the explicit event instead.

- [ ] **Step 4: The six actions**

`SubmitPlan.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\PlanEventAction;
use App\Enums\PlanStatus;
use App\Models\AnnualPlan;
use App\Models\PlanActivity;
use App\Models\PlanActivityMonth;
use App\Support\Plans\PlanTransition;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Send a draft to MEAL. From here the planned layer is frozen.
 */
class SubmitPlan
{
    public function __construct(private PlanTransition $transition) {}

    public function handle(AnnualPlan $plan): AnnualPlan
    {
        Gate::authorize('submit', $plan);

        $hasPlannedMonth = PlanActivityMonth::query()
            ->whereIn('plan_activity_id', PlanActivity::query()->where('annual_plan_id', $plan->id)->select('id'))
            ->exists();

        if (! $hasPlannedMonth) {
            throw ValidationException::withMessages(['plan' => __('annual_plans.plan_at_least_one_month_before_sending')]);
        }

        return $this->transition->move($plan, [PlanStatus::Draft], PlanStatus::Submitted, ['submitted_at' => now()], PlanEventAction::Submitted, 'submitted');
    }
}
```

`ReturnPlanForEdits.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\PlanEventAction;
use App\Enums\PlanStatus;
use App\Models\AnnualPlan;
use App\Support\Plans\PlanTransition;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * MEAL sends a submission back to the manager. The reason is required and
 * stays in the journal for the life of the plan (user decision 2026-10-06).
 */
class ReturnPlanForEdits
{
    public function __construct(private PlanTransition $transition) {}

    public function handle(AnnualPlan $plan, string $reason): AnnualPlan
    {
        Gate::authorize('review', $plan);

        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['returnReason' => __('annual_plans.say_what_the_project_manager_should_change')]);
        }

        return $this->transition->move($plan, [PlanStatus::Submitted], PlanStatus::Draft, ['submitted_at' => null], PlanEventAction::Returned, 'returned', $reason);
    }
}
```

`ApprovePlan.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\PlanEventAction;
use App\Enums\PlanStatus;
use App\Models\AnnualPlan;
use App\Support\Plans\PlanTransition;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * MEAL approves: the plan goes active at once and the manager is notified.
 * There is no further sign-off (user decision 2026-10-06).
 */
class ApprovePlan
{
    public function __construct(private PlanTransition $transition) {}

    public function handle(AnnualPlan $plan): AnnualPlan
    {
        Gate::authorize('review', $plan);

        return $this->transition->move($plan, [PlanStatus::Submitted], PlanStatus::Active, ['approved_at' => now(), 'approved_by' => Auth::id()], PlanEventAction::Approved, 'approved');
    }
}
```

`OpenFinalReview.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\PlanEventAction;
use App\Enums\PlanStatus;
use App\Models\AnnualPlan;
use App\Support\Plans\PlanTransition;
use Illuminate\Support\Facades\Gate;

/**
 * Active → Final review: by MEAL early, or by the daily command once the
 * period has ended (D8). The command passes authorize: false and acts as the
 * system, so the journal row has no user.
 */
class OpenFinalReview
{
    public function __construct(private PlanTransition $transition) {}

    public function handle(AnnualPlan $plan, bool $authorize = true): AnnualPlan
    {
        if ($authorize) {
            Gate::authorize('review', $plan);
        }

        return $this->transition->move($plan, [PlanStatus::Active], PlanStatus::FinalReview, ['review_opened_at' => now()], PlanEventAction::ReviewOpened, 'review_opened');
    }
}
```

`ClosePlan.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\OccurrenceStatus;
use App\Enums\PlanEventAction;
use App\Enums\PlanStatus;
use App\Models\AnnualPlan;
use App\Models\PlanActivity;
use App\Models\PlanOccurrence;
use App\Support\Plans\PlanTransition;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * MEAL closes the plan after the final review, with closing notes. A report
 * still awaiting verification blocks it (D15): a closed plan is read-only and
 * that report could never be settled.
 */
class ClosePlan
{
    public function __construct(private PlanTransition $transition) {}

    public function handle(AnnualPlan $plan, string $notes): AnnualPlan
    {
        Gate::authorize('review', $plan);

        $notes = trim($notes);

        if (mb_strlen($notes) < 10) {
            throw ValidationException::withMessages(['closingNotes' => __('annual_plans.write_the_closing_notes_before_closing')]);
        }

        $waiting = PlanOccurrence::query()
            ->whereIn('plan_activity_id', PlanActivity::query()->where('annual_plan_id', $plan->id)->select('id'))
            ->where('status', OccurrenceStatus::Reported->value)
            ->count();

        if ($waiting > 0) {
            throw ValidationException::withMessages(['plan' => trans_choice('annual_plans.settle_the_waiting_reports_before_closing', $waiting, ['count' => $waiting])]);
        }

        return $this->transition->move($plan, [PlanStatus::FinalReview], PlanStatus::Closed, ['closed_at' => now(), 'closed_by' => Auth::id(), 'closing_notes' => $notes], PlanEventAction::Closed, 'closed');
    }
}
```

`CancelPlan.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\PlanEventAction;
use App\Enums\PlanStatus;
use App\Models\AnnualPlan;
use App\Support\Plans\PlanTransition;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * MEAL cancels a submitted or active plan with a reason (D9). The months are
 * freed for a new plan of the same scope; nothing is deleted.
 */
class CancelPlan
{
    public function __construct(private PlanTransition $transition) {}

    public function handle(AnnualPlan $plan, string $reason): AnnualPlan
    {
        Gate::authorize('review', $plan);

        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['cancelReason' => __('annual_plans.say_why_the_plan_is_cancelled')]);
        }

        return $this->transition->move($plan, [PlanStatus::Submitted, PlanStatus::Active], PlanStatus::Cancelled, ['cancelled_at' => now(), 'cancelled_by' => Auth::id(), 'cancel_reason' => $reason], PlanEventAction::Cancelled, 'cancelled', $reason);
    }
}
```

Lang (en):

```php
    'plan_at_least_one_month_before_sending' => 'Plan at least one month for one activity before sending the plan to MEAL.',
    'say_what_the_project_manager_should_change' => 'Say what the project manager should change.',
    'say_why_the_plan_is_cancelled' => 'Say why the plan is cancelled.',
    'settle_the_waiting_reports_before_closing' => '{1} Verify or send back the report still waiting before closing.|[2,*] Verify or send back the :count reports still waiting before closing.',
    'this_plan_has_moved_on' => 'This plan is already “:status”. Refresh to see where it stands.',
    'write_the_closing_notes_before_closing' => 'Write the closing notes (at least 10 characters) before closing.',
```

Lang (ar), using explicit Arabic plural ranges per the localization rule:

```php
    'plan_at_least_one_month_before_sending' => 'خطّط شهرًا واحدًا على الأقل لنشاط واحد قبل إرسال الخطة إلى المتابعة والتقييم.',
    'say_what_the_project_manager_should_change' => 'اذكر ما يجب على مدير المشروع تعديله.',
    'say_why_the_plan_is_cancelled' => 'اذكر سبب إلغاء الخطة.',
    'settle_the_waiting_reports_before_closing' => '{1} تحقّق من التقرير المنتظر أو أعِده قبل الإغلاق.|{2} تحقّق من التقريرين المنتظرين أو أعِدهما قبل الإغلاق.|[3,10] تحقّق من :count تقارير منتظرة أو أعِدها قبل الإغلاق.|[11,*] تحقّق من :count تقريرًا منتظرًا أو أعِدها قبل الإغلاق.',
    'this_plan_has_moved_on' => 'هذه الخطة أصبحت «:status» بالفعل. حدّث الصفحة لمعرفة وضعها الحالي.',
    'write_the_closing_notes_before_closing' => 'اكتب ملاحظات الإغلاق (10 أحرف على الأقل) قبل الإغلاق.',
```

- [ ] **Step 5: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanWorkflowTest.php tests/Feature/Notifications/AnnualPlanWorkflowNotificationsTest.php`
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add app/Support/Plans/PlanTransition.php app/Actions/AnnualPlans lang/en/annual_plans.php lang/ar/annual_plans.php tests/Feature/AnnualPlans/PlanWorkflowTest.php
```

---

### Task 15: The actual layer (report, postpone, verify, send back, missed, unplanned)

**Files:**
- Create: `app/Support/Plans/OccurrenceSlot.php`, `app/Actions/AnnualPlans/ReportOccurrence.php`, `PostponeOccurrence.php`, `VerifyOccurrence.php`, `SendOccurrenceBack.php`, `MarkOccurrenceMissed.php`, `RecordUnplannedOccurrence.php`, `AddUnplannedActivity.php`
- Modify: `lang/{en,ar}/annual_plans.php`
- Test: `tests/Feature/AnnualPlans/ActualLayerTest.php`

**Interfaces:**
- Produces:
  - `OccurrenceSlot::resolve(PlanActivity $activity, string $monthKey, ?int $projectId): OccurrenceSlot` (throws `ValidationException` key `slot`)
  - public readonly `activity`, `month`, `projectId`
  - `plan(): AnnualPlan`, `projectKey(): int`, `isPlanned(): bool`, `isMovedIn(): bool`, `isExpected(): bool`, `hasStarted(): bool`, `occurrence(bool $lock = false): ?PlanOccurrence`, `occurrenceOrNew(): PlanOccurrence`
  - `ReportOccurrence::handle(OccurrenceSlot, ?string $note = null): PlanOccurrence`
  - `PostponeOccurrence::handle(OccurrenceSlot, CarbonImmutable $movedTo, ?string $note = null): PlanOccurrence`
  - `VerifyOccurrence::handle(PlanOccurrence): PlanOccurrence`
  - `SendOccurrenceBack::handle(PlanOccurrence, string $reason): PlanOccurrence`
  - `MarkOccurrenceMissed::handle(OccurrenceSlot, ?string $note = null): PlanOccurrence`
  - `RecordUnplannedOccurrence::handle(OccurrenceSlot, ?string $note = null): PlanOccurrence`
  - `AddUnplannedActivity::handle(AnnualPlan, CatalogActivity, ?int $responsibleEmployeeId = null): PlanActivity`

Rules the actions enforce (the tests below cover each):

| Action | Who | Slot must be | Existing occurrence must be |
|---|---|---|---|
| Report | PM (`report`) | month started | none or `sent back` |
| Postpone | PM or MEAL (`track`) | expected (planned or moved in); moved-to later and inside period | none, `reported`, `sent back` or `postponed` |
| Verify | MEAL (`verify`) | n/a | `reported` |
| Send back | MEAL | n/a | `reported` or `postponed` |
| Mark missed | MEAL | expected and started | anything but `verified` |
| Record unplanned | MEAL | **not** expected, started | none |
| Add unplanned activity | PM or MEAL (`track`) | n/a | n/a |

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Actions\AnnualPlans\AddUnplannedActivity;
use App\Actions\AnnualPlans\MarkOccurrenceMissed;
use App\Actions\AnnualPlans\PostponeOccurrence;
use App\Actions\AnnualPlans\RecordUnplannedOccurrence;
use App\Actions\AnnualPlans\ReportOccurrence;
use App\Actions\AnnualPlans\SendOccurrenceBack;
use App\Actions\AnnualPlans\VerifyOccurrence;
use App\Enums\OccurrenceStatus;
use App\Enums\PlanEventAction;
use App\Models\AnnualPlan;
use App\Models\CatalogActivity;
use App\Models\PlanActivity;
use App\Models\Project;
use App\Support\Plans\OccurrenceSlot;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-09-20 09:00:00');
    $this->employee = insertHrEmployee();
    $this->manager = userWithRole('Team Member', $this->employee);
    $this->meal = userWithRole('MEAL');
    $this->plan = AnnualPlan::factory()->between('2027-01', '2027-12')->active()->create(['manager_id' => $this->employee]);
    $this->activity = PlanActivity::factory()->inMonths('2027-07', '2027-09', '2027-10')->create(['annual_plan_id' => $this->plan->id]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('the manager reports a started month and meal verifies it', function () {
    $this->actingAs($this->manager);
    $occurrence = app(ReportOccurrence::class)->handle(OccurrenceSlot::resolve($this->activity, '2027-09', null), '22 trainees');

    $this->actingAs($this->meal);
    app(VerifyOccurrence::class)->handle($occurrence);

    expect($occurrence->status)->toBe(OccurrenceStatus::Verified)
        ->and($occurrence->reported_by)->toBe($this->manager->id)
        ->and($occurrence->verified_by)->toBe($this->meal->id)
        ->and($this->plan->events()->take(2)->pluck('action')->all())->toBe([PlanEventAction::Verified, PlanEventAction::Reported]);
});

test('a future month cannot be reported yet', function () {
    $this->actingAs($this->manager);

    expect(fn () => app(ReportOccurrence::class)->handle(OccurrenceSlot::resolve($this->activity, '2027-10', null)))->toThrow(ValidationException::class);
});

test('a month is reported once until meal sends it back', function () {
    $this->actingAs($this->manager);
    $slot = OccurrenceSlot::resolve($this->activity, '2027-09', null);
    $occurrence = app(ReportOccurrence::class)->handle($slot);

    expect(fn () => app(ReportOccurrence::class)->handle($slot))->toThrow(ValidationException::class);

    $this->actingAs($this->meal);
    app(SendOccurrenceBack::class)->handle($occurrence, 'Attach the attendance sheet');
    expect($occurrence->status)->toBe(OccurrenceStatus::SentBack);

    $this->actingAs($this->manager);
    expect(app(ReportOccurrence::class)->handle($slot)->status)->toBe(OccurrenceStatus::Reported);
});

test('postponing moves the month later and the destination becomes expected', function () {
    $this->actingAs($this->manager);
    app(PostponeOccurrence::class)->handle(OccurrenceSlot::resolve($this->activity, '2027-07', null), CarbonImmutable::parse('2027-11-01'), 'Trainer unavailable');

    $destination = OccurrenceSlot::resolve($this->activity, '2027-11', null);

    expect($destination->isPlanned())->toBeFalse()
        ->and($destination->isMovedIn())->toBeTrue()
        ->and($destination->isExpected())->toBeTrue();
});

test('a postponement must land later and inside the plan', function () {
    $this->actingAs($this->manager);
    $slot = OccurrenceSlot::resolve($this->activity, '2027-09', null);

    expect(fn () => app(PostponeOccurrence::class)->handle($slot, CarbonImmutable::parse('2027-08-01')))->toThrow(ValidationException::class)
        ->and(fn () => app(PostponeOccurrence::class)->handle($slot, CarbonImmutable::parse('2028-01-01')))->toThrow(ValidationException::class);
});

test('meal marks a planned month missed, but not one already verified', function () {
    $this->actingAs($this->meal);
    $missed = app(MarkOccurrenceMissed::class)->handle(OccurrenceSlot::resolve($this->activity, '2027-07', null), 'Venue closed');

    expect($missed->status)->toBe(OccurrenceStatus::Missed);

    $this->actingAs($this->manager);
    $reported = app(ReportOccurrence::class)->handle(OccurrenceSlot::resolve($this->activity, '2027-09', null));
    $this->actingAs($this->meal);
    app(VerifyOccurrence::class)->handle($reported);

    expect(fn () => app(MarkOccurrenceMissed::class)->handle(OccurrenceSlot::resolve($this->activity, '2027-09', null)))->toThrow(ValidationException::class);
});

test('meal records something that happened outside the plan', function () {
    $this->actingAs($this->meal);
    $occurrence = app(RecordUnplannedOccurrence::class)->handle(OccurrenceSlot::resolve($this->activity, '2027-08', null), 'Extra cohort');

    expect($occurrence->status)->toBe(OccurrenceStatus::Verified)
        ->and($this->plan->events()->first()->action)->toBe(PlanEventAction::UnplannedRecorded)
        ->and(fn () => app(RecordUnplannedOccurrence::class)->handle(OccurrenceSlot::resolve($this->activity, '2027-09', null)))->toThrow(ValidationException::class);
});

test('the manager cannot verify their own report', function () {
    $this->actingAs($this->manager);
    $occurrence = app(ReportOccurrence::class)->handle(OccurrenceSlot::resolve($this->activity, '2027-09', null));

    expect(fn () => app(VerifyOccurrence::class)->handle($occurrence))->toThrow(AuthorizationException::class);
});

test('per-project activities need a project of the program, shared ones refuse one', function () {
    $perProject = PlanActivity::factory()->perProject()->inMonths('2027-09')->create(['annual_plan_id' => $this->plan->id]);
    $inProgram = Project::factory()->create(['program_id' => $this->plan->program_id]);
    $elsewhere = Project::factory()->create();

    expect(OccurrenceSlot::resolve($perProject, '2027-09', $inProgram->id)->projectKey())->toBe($inProgram->id)
        ->and(fn () => OccurrenceSlot::resolve($perProject, '2027-09', null))->toThrow(ValidationException::class)
        ->and(fn () => OccurrenceSlot::resolve($perProject, '2027-09', $elsewhere->id))->toThrow(ValidationException::class)
        ->and(fn () => OccurrenceSlot::resolve($this->activity, '2027-09', $inProgram->id))->toThrow(ValidationException::class);
});

test('an unplanned activity is added after approval', function () {
    $this->actingAs($this->manager);
    $row = app(AddUnplannedActivity::class)->handle($this->plan, CatalogActivity::factory()->create(['name' => 'Training of trainers']));

    expect($row->is_unplanned)->toBeTrue()
        ->and($this->plan->events()->first()->action)->toBe(PlanEventAction::UnplannedActivityAdded);
});

test('nothing in the actual layer moves once the plan is closed', function () {
    $this->plan->forceFill(['status' => 'closed'])->save();
    $this->actingAs($this->meal);

    expect(fn () => app(RecordUnplannedOccurrence::class)->handle(OccurrenceSlot::resolve($this->activity->fresh(), '2027-08', null)))->toThrow(AuthorizationException::class);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/ActualLayerTest.php`
Expected: FAIL.

- [ ] **Step 3: `OccurrenceSlot`**

```php
<?php

declare(strict_types=1);

namespace App\Support\Plans;

use App\Enums\OccurrenceStatus;
use App\Enums\PlanActivityTracking;
use App\Models\AnnualPlan;
use App\Models\PlanActivity;
use App\Models\PlanActivityMonth;
use App\Models\PlanOccurrence;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * One cell of the actual layer: an activity, a month and (for a per-project
 * activity) a project. Built only through resolve(), which refuses a month
 * outside the plan or a project that does not fit the activity's tracking,
 * so every action receives a cell that exists.
 */
final readonly class OccurrenceSlot
{
    private function __construct(
        public PlanActivity $activity,
        public CarbonImmutable $month,
        public ?int $projectId,
    ) {}

    public static function resolve(PlanActivity $activity, string $monthKey, ?int $projectId): self
    {
        $activity->loadMissing(['plan', 'catalogActivity:id,name']);
        $plan = $activity->plan;

        try {
            $month = PlanPeriod::fromKey($monthKey);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['slot' => __('annual_plans.that_month_is_outside_the_plan_period')]);
        }

        if (! PlanPeriod::contains($plan->starts_on, $plan->ends_on, $month)) {
            throw ValidationException::withMessages(['slot' => __('annual_plans.that_month_is_outside_the_plan_period')]);
        }

        if ($activity->tracking === PlanActivityTracking::PerProject) {
            $programId = $projectId === null ? null : Project::query()->whereKey($projectId)->value('program_id');

            if ($programId === null || (int) $programId !== $plan->program_id) {
                throw ValidationException::withMessages(['slot' => __('annual_plans.choose_a_project_of_this_program')]);
            }
        } elseif ($projectId !== null) {
            throw ValidationException::withMessages(['slot' => __('annual_plans.this_activity_is_tracked_for_all_projects_together')]);
        }

        return new self($activity, $month, $projectId);
    }

    public function plan(): AnnualPlan
    {
        return $this->activity->plan;
    }

    public function projectKey(): int
    {
        return $this->projectId ?? 0;
    }

    public function isPlanned(): bool
    {
        return PlanActivityMonth::query()
            ->where('plan_activity_id', $this->activity->id)
            ->whereDate('month', $this->month->toDateString())
            ->exists();
    }

    /**
     * Whether a postponed month of the same row was moved into this one.
     */
    public function isMovedIn(): bool
    {
        return PlanOccurrence::query()
            ->where('plan_activity_id', $this->activity->id)
            ->where('project_key', $this->projectKey())
            ->where('status', OccurrenceStatus::Postponed->value)
            ->whereDate('moved_to', $this->month->toDateString())
            ->exists();
    }

    /**
     * Planned here, or moved here by a postponement.
     */
    public function isExpected(): bool
    {
        return $this->isPlanned() || $this->isMovedIn();
    }

    /**
     * Months that have begun may be reported; the future may not.
     */
    public function hasStarted(): bool
    {
        return $this->month->lte(PlanPeriod::currentMonth());
    }

    public function occurrence(bool $lock = false): ?PlanOccurrence
    {
        return PlanOccurrence::query()
            ->where('plan_activity_id', $this->activity->id)
            ->where('project_key', $this->projectKey())
            ->whereDate('month', $this->month->toDateString())
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * The row to write: the existing one (locked), or a new one for this cell.
     */
    public function occurrenceOrNew(): PlanOccurrence
    {
        return $this->occurrence(lock: true) ?? new PlanOccurrence([
            'plan_activity_id' => $this->activity->id,
            'project_id' => $this->projectId,
            'month' => $this->month->toDateString(),
        ]);
    }
}
```

- [ ] **Step 4: The actions**

`ReportOccurrence.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\OccurrenceStatus;
use App\Enums\PlanEventAction;
use App\Models\PlanOccurrence;
use App\Support\Plans\OccurrenceSlot;
use App\Support\Plans\PlanJournal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The project manager reports a month done. In a month that was neither
 * planned nor moved in, the same report records unplanned work.
 */
class ReportOccurrence
{
    public function __construct(private PlanJournal $journal) {}

    public function handle(OccurrenceSlot $slot, ?string $note = null): PlanOccurrence
    {
        Gate::authorize('report', $slot->plan());

        if (! $slot->hasStarted()) {
            throw ValidationException::withMessages(['slot' => __('annual_plans.only_months_that_have_started_can_be_reported')]);
        }

        return DB::transaction(function () use ($slot, $note): PlanOccurrence {
            $occurrence = $slot->occurrenceOrNew();

            if ($occurrence->exists && $occurrence->status !== OccurrenceStatus::SentBack) {
                throw ValidationException::withMessages(['slot' => __('annual_plans.this_month_already_has_a_report')]);
            }

            $expected = $slot->isExpected();

            $occurrence->fill([
                'status' => OccurrenceStatus::Reported,
                'moved_to' => null,
                'note' => $note,
                'reported_by' => Auth::id(),
                'reported_at' => now(),
                'verified_by' => null,
                'verified_at' => null,
            ])->save();

            $this->journal->write(
                $slot->plan(),
                $expected ? PlanEventAction::Reported : PlanEventAction::UnplannedRecorded,
                activityEvent: $expected ? 'activity_reported' : 'unplanned_reported',
                activity: $slot->activity,
                projectId: $slot->projectId,
                month: $slot->month,
                note: $note,
            );

            return $occurrence;
        });
    }
}
```

`PostponeOccurrence.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\OccurrenceStatus;
use App\Enums\PlanEventAction;
use App\Models\PlanOccurrence;
use App\Support\Plans\OccurrenceSlot;
use App\Support\Plans\PlanJournal;
use App\Support\Plans\PlanPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Move an expected month later inside the plan. Takes effect at once; MEAL
 * may send a manager's postponement back. When MEAL postpones, it counts as
 * confirmed (verified_by is set).
 */
class PostponeOccurrence
{
    public function __construct(private PlanJournal $journal) {}

    public function handle(OccurrenceSlot $slot, CarbonImmutable $movedTo, ?string $note = null): PlanOccurrence
    {
        $plan = $slot->plan();

        Gate::authorize('track', $plan);

        $movedTo = $movedTo->startOfMonth();

        if (! $slot->isExpected()) {
            throw ValidationException::withMessages(['slot' => __('annual_plans.only_a_planned_month_can_be_postponed')]);
        }

        if ($movedTo->lte($slot->month) || ! PlanPeriod::contains($plan->starts_on, $plan->ends_on, $movedTo)) {
            throw ValidationException::withMessages(['postponeTo' => __('annual_plans.choose_a_later_month_inside_the_plan')]);
        }

        return DB::transaction(function () use ($slot, $plan, $movedTo, $note): PlanOccurrence {
            $occurrence = $slot->occurrenceOrNew();

            if ($occurrence->exists && in_array($occurrence->status, [OccurrenceStatus::Verified, OccurrenceStatus::Missed], true)) {
                throw ValidationException::withMessages(['slot' => __('annual_plans.this_month_is_already_settled')]);
            }

            $byMeal = Gate::allows('verify', $plan);

            $occurrence->fill([
                'status' => OccurrenceStatus::Postponed,
                'moved_to' => $movedTo->toDateString(),
                'note' => $note,
                'reported_by' => Auth::id(),
                'reported_at' => now(),
                'verified_by' => $byMeal ? Auth::id() : null,
                'verified_at' => $byMeal ? now() : null,
            ])->save();

            $this->journal->write($plan, PlanEventAction::Postponed, activityEvent: 'activity_postponed', activity: $slot->activity, projectId: $slot->projectId, month: $slot->month, note: $note, properties: ['moved_to' => $movedTo->toDateString()]);

            return $occurrence;
        });
    }
}
```

`VerifyOccurrence.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\OccurrenceStatus;
use App\Enums\PlanEventAction;
use App\Models\PlanOccurrence;
use App\Support\Plans\PlanJournal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * MEAL confirms a reported month. The reporter is told.
 */
class VerifyOccurrence
{
    public function __construct(private PlanJournal $journal) {}

    public function handle(PlanOccurrence $occurrence): PlanOccurrence
    {
        $occurrence->loadMissing(['planActivity.plan', 'planActivity.catalogActivity:id,name']);
        $plan = $occurrence->planActivity->plan;

        Gate::authorize('verify', $plan);

        return DB::transaction(function () use ($occurrence, $plan): PlanOccurrence {
            $locked = PlanOccurrence::query()->lockForUpdate()->findOrFail($occurrence->id);

            if ($locked->status !== OccurrenceStatus::Reported) {
                throw ValidationException::withMessages(['slot' => __('annual_plans.only_a_report_awaiting_meal_can_be_verified')]);
            }

            $locked->fill(['status' => OccurrenceStatus::Verified, 'verified_by' => Auth::id(), 'verified_at' => now()])->save();

            $this->journal->write($plan, PlanEventAction::Verified, activityEvent: 'activity_verified', activity: $occurrence->planActivity, projectId: $locked->project_id, month: $locked->month, properties: ['reporter_id' => $locked->reported_by]);

            $occurrence->setRawAttributes($locked->getAttributes(), true);

            return $occurrence;
        });
    }
}
```

`SendOccurrenceBack.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\OccurrenceStatus;
use App\Enums\PlanEventAction;
use App\Models\PlanOccurrence;
use App\Support\Plans\PlanJournal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * MEAL returns a report or a postponement to the manager with a reason. The
 * row and its evidence stay; the cell reads as due again.
 */
class SendOccurrenceBack
{
    public function __construct(private PlanJournal $journal) {}

    public function handle(PlanOccurrence $occurrence, string $reason): PlanOccurrence
    {
        $occurrence->loadMissing(['planActivity.plan', 'planActivity.catalogActivity:id,name']);
        $plan = $occurrence->planActivity->plan;

        Gate::authorize('verify', $plan);

        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['sendBackReason' => __('annual_plans.say_what_is_missing_from_the_report')]);
        }

        return DB::transaction(function () use ($occurrence, $plan, $reason): PlanOccurrence {
            $locked = PlanOccurrence::query()->lockForUpdate()->findOrFail($occurrence->id);

            if (! in_array($locked->status, [OccurrenceStatus::Reported, OccurrenceStatus::Postponed], true)) {
                throw ValidationException::withMessages(['slot' => __('annual_plans.only_a_report_or_postponement_can_be_sent_back')]);
            }

            $locked->fill(['status' => OccurrenceStatus::SentBack, 'moved_to' => null, 'note' => $reason, 'verified_by' => Auth::id(), 'verified_at' => now()])->save();

            $this->journal->write($plan, PlanEventAction::SentBack, activityEvent: 'activity_sent_back', activity: $occurrence->planActivity, projectId: $locked->project_id, month: $locked->month, note: $reason, properties: ['reporter_id' => $locked->reported_by]);

            $occurrence->setRawAttributes($locked->getAttributes(), true);

            return $occurrence;
        });
    }
}
```

`MarkOccurrenceMissed.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\OccurrenceStatus;
use App\Enums\PlanEventAction;
use App\Models\PlanOccurrence;
use App\Support\Plans\OccurrenceSlot;
use App\Support\Plans\PlanJournal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * MEAL records that an expected month did not happen.
 */
class MarkOccurrenceMissed
{
    public function __construct(private PlanJournal $journal) {}

    public function handle(OccurrenceSlot $slot, ?string $note = null): PlanOccurrence
    {
        Gate::authorize('verify', $slot->plan());

        if (! $slot->isExpected() || ! $slot->hasStarted()) {
            throw ValidationException::withMessages(['slot' => __('annual_plans.only_a_started_planned_month_can_be_missed')]);
        }

        return DB::transaction(function () use ($slot, $note): PlanOccurrence {
            $occurrence = $slot->occurrenceOrNew();

            if ($occurrence->exists && $occurrence->status === OccurrenceStatus::Verified) {
                throw ValidationException::withMessages(['slot' => __('annual_plans.this_month_is_already_settled')]);
            }

            $occurrence->fill(['status' => OccurrenceStatus::Missed, 'moved_to' => null, 'note' => $note, 'verified_by' => Auth::id(), 'verified_at' => now()])->save();

            $this->journal->write($slot->plan(), PlanEventAction::Missed, activityEvent: 'activity_missed', activity: $slot->activity, projectId: $slot->projectId, month: $slot->month, note: $note);

            return $occurrence;
        });
    }
}
```

`RecordUnplannedOccurrence.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\OccurrenceStatus;
use App\Enums\PlanEventAction;
use App\Models\PlanOccurrence;
use App\Support\Plans\OccurrenceSlot;
use App\Support\Plans\PlanJournal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * MEAL records work that happened in a month nobody planned. Recorded by
 * MEAL, so it is verified as it is written.
 */
class RecordUnplannedOccurrence
{
    public function __construct(private PlanJournal $journal) {}

    public function handle(OccurrenceSlot $slot, ?string $note = null): PlanOccurrence
    {
        Gate::authorize('verify', $slot->plan());

        if ($slot->isExpected()) {
            throw ValidationException::withMessages(['slot' => __('annual_plans.this_month_is_planned_report_or_verify_it')]);
        }

        if (! $slot->hasStarted()) {
            throw ValidationException::withMessages(['slot' => __('annual_plans.only_months_that_have_started_can_be_reported')]);
        }

        return DB::transaction(function () use ($slot, $note): PlanOccurrence {
            $occurrence = $slot->occurrenceOrNew();

            if ($occurrence->exists) {
                throw ValidationException::withMessages(['slot' => __('annual_plans.this_month_already_has_a_report')]);
            }

            $occurrence->fill([
                'status' => OccurrenceStatus::Verified,
                'note' => $note,
                'reported_by' => Auth::id(),
                'reported_at' => now(),
                'verified_by' => Auth::id(),
                'verified_at' => now(),
            ])->save();

            $this->journal->write($slot->plan(), PlanEventAction::UnplannedRecorded, activityEvent: 'unplanned_reported', activity: $slot->activity, projectId: $slot->projectId, month: $slot->month, note: $note);

            return $occurrence;
        });
    }
}
```

`AddUnplannedActivity.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\PlanEventAction;
use App\Models\AnnualPlan;
use App\Models\CatalogActivity;
use App\Models\PlanActivity;
use App\Support\Plans\PlanJournal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A whole activity nobody planned, added while the plan is tracked. It has
 * no planned months, so everything reported on it counts as unplanned.
 */
class AddUnplannedActivity
{
    public function __construct(private PlanJournal $journal) {}

    public function handle(AnnualPlan $plan, CatalogActivity $catalogActivity, ?int $responsibleEmployeeId = null): PlanActivity
    {
        Gate::authorize('track', $plan);

        return DB::transaction(fn (): PlanActivity => AddPlanActivity::place($plan, $catalogActivity, true, $this->journal, PlanEventAction::UnplannedActivityAdded, 'unplanned_activity_added', $responsibleEmployeeId));
    }
}
```

Lang (en):

```php
    'choose_a_later_month_inside_the_plan' => 'Choose a later month inside the plan period.',
    'choose_a_project_of_this_program' => 'Choose a project of this program.',
    'only_a_planned_month_can_be_postponed' => 'Only a planned month can be postponed.',
    'only_a_report_awaiting_meal_can_be_verified' => 'Only a report awaiting MEAL can be verified.',
    'only_a_report_or_postponement_can_be_sent_back' => 'Only a report or a postponement can be sent back.',
    'only_a_started_planned_month_can_be_missed' => 'Only a planned month that has started can be marked missed.',
    'only_months_that_have_started_can_be_reported' => 'Only months that have started can be reported.',
    'say_what_is_missing_from_the_report' => 'Say what is missing from the report.',
    'this_activity_is_tracked_for_all_projects_together' => 'This activity is tracked for all projects together.',
    'this_month_already_has_a_report' => 'This month already has a report.',
    'this_month_is_already_settled' => 'This month is already settled.',
    'this_month_is_planned_report_or_verify_it' => 'This month is planned. Report or verify it instead.',
```

Lang (ar):

```php
    'choose_a_later_month_inside_the_plan' => 'اختر شهرًا لاحقًا ضمن مدة الخطة.',
    'choose_a_project_of_this_program' => 'اختر مشروعًا من هذا البرنامج.',
    'only_a_planned_month_can_be_postponed' => 'لا يمكن تأجيل إلا شهر مخطط.',
    'only_a_report_awaiting_meal_can_be_verified' => 'لا يمكن التحقق إلا من تقرير بانتظار المتابعة والتقييم.',
    'only_a_report_or_postponement_can_be_sent_back' => 'لا يمكن إعادة إلا تقرير أو تأجيل.',
    'only_a_started_planned_month_can_be_missed' => 'لا يمكن تسجيل عدم التنفيذ إلا لشهر مخطط بدأ بالفعل.',
    'only_months_that_have_started_can_be_reported' => 'لا يمكن الإبلاغ إلا عن الأشهر التي بدأت.',
    'say_what_is_missing_from_the_report' => 'اذكر ما ينقص التقرير.',
    'this_activity_is_tracked_for_all_projects_together' => 'يُتابَع هذا النشاط لجميع المشاريع معًا.',
    'this_month_already_has_a_report' => 'يوجد تقرير لهذا الشهر بالفعل.',
    'this_month_is_already_settled' => 'تمت تسوية هذا الشهر بالفعل.',
    'this_month_is_planned_report_or_verify_it' => 'هذا الشهر مخطط. أبلغ عنه أو تحقق منه بدلًا من ذلك.',
```

- [ ] **Step 5: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/ActualLayerTest.php`
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add app/Support/Plans/OccurrenceSlot.php app/Actions/AnnualPlans lang/en/annual_plans.php lang/ar/annual_plans.php tests/Feature/AnnualPlans/ActualLayerTest.php
```

---

### Task 16: Evidence files on a month report

**Files:**
- Create: `app/Actions/AnnualPlans/SaveOccurrenceEvidence.php`, `app/Actions/AnnualPlans/DeleteOccurrenceEvidence.php`
- Test: `tests/Feature/AnnualPlans/OccurrenceEvidenceTest.php`

**Interfaces:**
- Produces: `SaveOccurrenceEvidence::handle(PlanOccurrence $occurrence, UploadedFile $upload, string $displayName = ''): Media`, `DeleteOccurrenceEvidence::handle(PlanOccurrence $occurrence, Media $file): void`. Both authorize `track` on the plan. Files go under `media/plan-occurrences/{id}` on the `local` disk, collection `evidence`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Actions\AnnualPlans\DeleteOccurrenceEvidence;
use App\Actions\AnnualPlans\SaveOccurrenceEvidence;
use App\Enums\PlanEventAction;
use App\Models\AnnualPlan;
use App\Models\PlanActivity;
use App\Models\PlanOccurrence;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->employee = insertHrEmployee();
    $this->manager = userWithRole('Team Member', $this->employee);
    $plan = AnnualPlan::factory()->active()->create(['manager_id' => $this->employee]);
    $this->occurrence = PlanOccurrence::factory()->reported()->create([
        'plan_activity_id' => PlanActivity::factory()->create(['annual_plan_id' => $plan->id])->id,
    ]);
});

test('the manager attaches an attendance sheet as evidence', function () {
    $this->actingAs($this->manager);

    $media = app(SaveOccurrenceEvidence::class)->handle($this->occurrence, validUpload('attendance.pdf'), 'Attendance September');

    expect($media->mediable_type)->toBe('plan_occurrence')
        ->and($media->original_name)->toBe('Attendance September.pdf')
        ->and($this->occurrence->planActivity->plan->events()->first()->action)->toBe(PlanEventAction::EvidenceAdded);

    Storage::disk('local')->assertExists($media->path);
});

test('evidence is removed with its file', function () {
    $this->actingAs($this->manager);
    $media = app(SaveOccurrenceEvidence::class)->handle($this->occurrence, validUpload('photo.jpg'));

    app(DeleteOccurrenceEvidence::class)->handle($this->occurrence, $media);

    expect($this->occurrence->media()->count())->toBe(0);
    Storage::disk('local')->assertMissing($media->path);
});

test('a bystander cannot attach evidence', function () {
    $this->actingAs(userWithRole('Team Member'));

    expect(fn () => app(SaveOccurrenceEvidence::class)->handle($this->occurrence, validUpload('attendance.pdf')))->toThrow(AuthorizationException::class);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/OccurrenceEvidenceTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

`SaveOccurrenceEvidence.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\MediaCollection;
use App\Enums\PlanEventAction;
use App\Models\Media;
use App\Models\PlanOccurrence;
use App\Support\Plans\PlanJournal;
use App\Support\StoredFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * Attach a file proving a month happened (attendance sheet, photos).
 * Validation (size, type, SecureUpload) happens in the page, as for project
 * files.
 */
class SaveOccurrenceEvidence
{
    public function __construct(private PlanJournal $journal) {}

    public function handle(PlanOccurrence $occurrence, UploadedFile $upload, string $displayName = ''): Media
    {
        $occurrence->loadMissing(['planActivity.plan', 'planActivity.catalogActivity:id,name']);
        $plan = $occurrence->planActivity->plan;

        Gate::authorize('track', $plan);

        $file = new Media(['mediable_type' => 'plan_occurrence', 'mediable_id' => $occurrence->id, 'disk' => 'local']);

        StoredFiles::save(
            $file,
            $upload,
            'media/plan-occurrences/'.$occurrence->id,
            $displayName,
            ['collection' => MediaCollection::Evidence],
            ['uploaded_at' => now()],
            'plan evidence',
        );

        $this->journal->write($plan, PlanEventAction::EvidenceAdded, activity: $occurrence->planActivity, projectId: $occurrence->project_id, month: $occurrence->month, note: $file->original_name);

        return $file;
    }
}
```

`DeleteOccurrenceEvidence.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Models\Media;
use App\Models\PlanOccurrence;
use App\Support\StoredFiles;
use Illuminate\Support\Facades\Gate;

/**
 * Remove an evidence file and its disk copy. The file must belong to the
 * given occurrence (IDOR guard).
 */
class DeleteOccurrenceEvidence
{
    public function handle(PlanOccurrence $occurrence, Media $file): void
    {
        abort_unless($file->mediable_type === 'plan_occurrence' && (int) $file->mediable_id === $occurrence->id, 404);

        Gate::authorize('track', $occurrence->planActivity->plan);

        StoredFiles::forget($file->disk, $file->path, 'plan evidence', ['media_id' => $file->id]);

        $file->delete();
    }
}
```

Check: `Media::$fillable` must accept `mediable_type`, `mediable_id`, `disk` (as `SaveProjectFile` relies on). Make sure `Media`'s fillable includes `collection` as an enum-castable value. `SaveProjectFile` passes the raw string from validation, so pass `MediaCollection::Evidence->value` if the cast is a plain string.

- [ ] **Step 4: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/OccurrenceEvidenceTest.php`
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add app/Actions/AnnualPlans/SaveOccurrenceEvidence.php app/Actions/AnnualPlans/DeleteOccurrenceEvidence.php tests/Feature/AnnualPlans/OccurrenceEvidenceTest.php
```

---

### Task 17: MEAL turns a quantity into a target or a milestone

**Files:**
- Create: `app/Actions/AnnualPlans/SetActivityTarget.php`, `app/Actions/AnnualPlans/SetActivityMilestone.php`
- Modify: `lang/{en,ar}/annual_plans.php`
- Test: `tests/Feature/AnnualPlans/ActivityTargetsTest.php`

**Interfaces:**
- Consumes: `PlanIndicator::chainIssuesFor()`.
- Produces:
  - `SetActivityTarget::handle(PlanActivity $activity, array{indicatorId: int, target: string, period: string, projectId: int|null} $input): PlanIndicator`
  - `SetActivityMilestone::handle(PlanActivity $activity, array{name: string, plannedStart: string, plannedEnd: string|null, responsibleEmployeeId: int} $input): Milestone`
  - Validation keys: `targetIndicatorId`, `targetValue`, `targetPeriod`, `milestoneName`, `milestoneStart`, `milestoneResponsibleId`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Actions\AnnualPlans\SetActivityMilestone;
use App\Actions\AnnualPlans\SetActivityTarget;
use App\Enums\Period;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\PlanActivity;
use App\Models\Project;
use App\Models\Result;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->meal = userWithRole('MEAL');
    $this->plan = AnnualPlan::factory()->between('2027-01', '2027-12')->submitted()->create();
    $this->activity = PlanActivity::factory()->create(['annual_plan_id' => $this->plan->id]);
    $this->indicator = Indicator::factory()->create([
        'result_id' => Result::factory()->create(['program_id' => $this->plan->program_id])->id,
    ]);
});

test('meal sets a whole-plan target on an activity', function () {
    $this->actingAs($this->meal);

    $target = app(SetActivityTarget::class)->handle($this->activity, [
        'indicatorId' => $this->indicator->id, 'target' => '120', 'period' => Period::Plan->value, 'projectId' => null,
    ]);

    expect($target->source_type)->toBe('annual_plan')
        ->and($target->source_id)->toBe($this->plan->id)
        ->and($target->plan_activity_id)->toBe($this->activity->id)
        ->and($target->period)->toBe(Period::Plan);
});

test('a target that breaks the results chain is refused', function () {
    $this->actingAs($this->meal);
    $foreign = Indicator::factory()->create(['result_id' => Result::factory()->create()->id]);

    expect(fn () => app(SetActivityTarget::class)->handle($this->activity, [
        'indicatorId' => $foreign->id, 'target' => '10', 'period' => 'monthly', 'projectId' => null,
    ]))->toThrow(ValidationException::class);
});

test('the same indicator is targeted once per plan and project', function () {
    $this->actingAs($this->meal);
    $input = ['indicatorId' => $this->indicator->id, 'target' => '10', 'period' => 'monthly', 'projectId' => null];
    app(SetActivityTarget::class)->handle($this->activity, $input);

    expect(fn () => app(SetActivityTarget::class)->handle($this->activity, $input))->toThrow(ValidationException::class);
});

test('a project plan activity gets a milestone on its project', function () {
    $project = Project::factory()->create();
    $plan = AnnualPlan::factory()->forProject($project)->between('2027-01', '2027-12')->submitted()->create();
    $activity = PlanActivity::factory()->create(['annual_plan_id' => $plan->id]);
    $this->actingAs($this->meal);

    $milestone = app(SetActivityMilestone::class)->handle($activity, [
        'name' => 'Masters cohort graduates', 'plannedStart' => '2027-08-01', 'plannedEnd' => '2027-08-31', 'responsibleEmployeeId' => insertHrEmployee(),
    ]);

    expect($milestone->project_id)->toBe($project->id)
        ->and($milestone->plan_activity_id)->toBe($activity->id);
});

test('a program-wide plan offers targets only', function () {
    $this->actingAs($this->meal);

    expect(fn () => app(SetActivityMilestone::class)->handle($this->activity, [
        'name' => 'Graduation', 'plannedStart' => '2027-08-01', 'plannedEnd' => null, 'responsibleEmployeeId' => insertHrEmployee(),
    ]))->toThrow(ValidationException::class);
});

test('only meal sets targets', function () {
    $this->actingAs(userWithRole('Team Member'));

    expect(fn () => app(SetActivityTarget::class)->handle($this->activity, [
        'indicatorId' => $this->indicator->id, 'target' => '10', 'period' => 'monthly', 'projectId' => null,
    ]))->toThrow(AuthorizationException::class);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/ActivityTargetsTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

`SetActivityTarget.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\Period;
use App\Enums\PlanEventAction;
use App\Models\Indicator;
use App\Models\PlanActivity;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Support\Plans\PlanJournal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * MEAL commits an indicator target on one activity of a plan. The target is
 * an ordinary plan_indicators row (source = this plan), so achievement,
 * reports and every target page work unchanged; plan_activity_id only says
 * which row of the grid it measures.
 */
class SetActivityTarget
{
    public function __construct(private PlanJournal $journal) {}

    /**
     * @param  array{indicatorId: int, target: string, period: string, projectId: int|null}  $input
     */
    public function handle(PlanActivity $activity, array $input): PlanIndicator
    {
        $plan = $activity->plan;

        Gate::authorize('review', $plan);

        if (! is_numeric($input['target']) || (float) $input['target'] <= 0) {
            throw ValidationException::withMessages(['targetValue' => __('annual_plans.enter_a_target_above_zero')]);
        }

        $period = Period::tryFrom($input['period']);

        if ($period === null) {
            throw ValidationException::withMessages(['targetPeriod' => __('annual_plans.choose_how_often_the_target_is_counted')]);
        }

        $indicator = Indicator::query()->with('result:id,program_id')->findOrFail($input['indicatorId']);
        $projectId = $input['projectId'] ?? $plan->project_id;
        $project = $projectId === null ? null : Project::query()->findOrFail($projectId);

        $issues = PlanIndicator::chainIssuesFor($plan, $indicator, $project);

        if ($issues !== []) {
            throw ValidationException::withMessages(['targetIndicatorId' => __($issues[0])]);
        }

        $duplicate = PlanIndicator::query()
            ->where('source_type', 'annual_plan')
            ->where('source_id', $plan->id)
            ->where('indicator_id', $indicator->id)
            ->when($projectId === null, fn ($query) => $query->whereNull('project_id'), fn ($query) => $query->where('project_id', $projectId))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['targetIndicatorId' => __('annual_plans.this_indicator_already_has_a_target_in_this_plan')]);
        }

        return DB::transaction(function () use ($plan, $activity, $indicator, $projectId, $period, $input): PlanIndicator {
            $target = PlanIndicator::query()->create([
                'source_type' => 'annual_plan',
                'source_id' => $plan->id,
                'plan_activity_id' => $activity->id,
                'indicator_id' => $indicator->id,
                'project_id' => $projectId,
                'target' => $input['target'],
                'period' => $period,
            ]);

            $this->journal->write($plan, PlanEventAction::TargetSet, activityEvent: 'target_set', activity: $activity, properties: ['value' => $input['target']]);

            return $target;
        });
    }
}
```

`SetActivityMilestone.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\PlanEventAction;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\PlanActivity;
use App\Support\Plans\PlanJournal;
use App\Support\Plans\PlanPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * MEAL turns an activity's checkpoint into a milestone of the plan's
 * project (D7). Milestones belong to projects, so program-wide plans cannot
 * hold one; MEAL needs no milestones.create for this path.
 */
class SetActivityMilestone
{
    public function __construct(private PlanJournal $journal) {}

    /**
     * @param  array{name: string, plannedStart: string, plannedEnd: string|null, responsibleEmployeeId: int}  $input
     */
    public function handle(PlanActivity $activity, array $input): Milestone
    {
        $plan = $activity->plan;

        Gate::authorize('review', $plan);

        if ($plan->project_id === null) {
            throw ValidationException::withMessages(['milestoneName' => __('annual_plans.milestones_belong_to_project_plans')]);
        }

        $name = trim($input['name']);

        if ($name === '') {
            throw ValidationException::withMessages(['milestoneName' => __('annual_plans.name_the_milestone')]);
        }

        $start = CarbonImmutable::parse($input['plannedStart']);

        if (! PlanPeriod::contains($plan->starts_on, $plan->ends_on, $start)) {
            throw ValidationException::withMessages(['milestoneStart' => __('annual_plans.that_month_is_outside_the_plan_period')]);
        }

        if (! HrEmployee::query()->whereKey($input['responsibleEmployeeId'])->exists()) {
            throw ValidationException::withMessages(['milestoneResponsibleId' => __('annual_plans.the_selected_person_is_no_longer_available')]);
        }

        return DB::transaction(function () use ($plan, $activity, $name, $start, $input): Milestone {
            $milestone = Milestone::query()->create([
                'project_id' => $plan->project_id,
                'plan_activity_id' => $activity->id,
                'name' => $name,
                'planned_start_date' => $start->toDateString(),
                'planned_end_date' => $input['plannedEnd'],
                'responsible_user_id' => $input['responsibleEmployeeId'],
                'sequence' => (int) Milestone::query()->where('project_id', $plan->project_id)->whereNull('parent_milestone_id')->max('sequence') + 1,
            ]);

            $this->journal->write($plan, PlanEventAction::MilestoneSet, activityEvent: 'milestone_set', activity: $activity, properties: ['value' => $name]);

            return $milestone;
        });
    }
}
```

Lang (en):

```php
    'choose_how_often_the_target_is_counted' => 'Choose how often the target is counted.',
    'enter_a_target_above_zero' => 'Enter a target above zero.',
    'milestones_belong_to_project_plans' => 'Milestones belong to project plans. Set a target on a program-wide plan.',
    'name_the_milestone' => 'Name the milestone.',
    'this_indicator_already_has_a_target_in_this_plan' => 'This indicator already has a target in this plan for that project.',
```

Lang (ar):

```php
    'choose_how_often_the_target_is_counted' => 'اختر كم مرة يُحتسب المستهدف.',
    'enter_a_target_above_zero' => 'أدخل مستهدفًا أكبر من صفر.',
    'milestones_belong_to_project_plans' => 'المعالم تتبع خطط المشاريع. حدّد مستهدفًا في الخطة على مستوى البرنامج.',
    'name_the_milestone' => 'اكتب اسم المعلم.',
    'this_indicator_already_has_a_target_in_this_plan' => 'لهذا المؤشر مستهدف في هذه الخطة لهذا المشروع بالفعل.',
```

- [ ] **Step 4: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/ActivityTargetsTest.php`
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add app/Actions/AnnualPlans/SetActivityTarget.php app/Actions/AnnualPlans/SetActivityMilestone.php lang/en/annual_plans.php lang/ar/annual_plans.php tests/Feature/AnnualPlans/ActivityTargetsTest.php
```

---

### Task 18: Catalog writes

**Files:**
- Create: `app/Actions/AnnualPlans/SaveCatalogActivity.php`
- Modify: `lang/{en,ar}/annual_plans.php`
- Test: `tests/Feature/AnnualPlans/SaveCatalogActivityTest.php`

**Interfaces:**
- Produces: `SaveCatalogActivity::handle(?CatalogActivity $activity, array{name: string, description: string|null, isActive: bool} $input): CatalogActivity`; `SaveCatalogActivity::activeTwinExists(string $name, ?int $ignoreId = null): bool` (the D14 warning).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Actions\AnnualPlans\SaveCatalogActivity;
use App\Models\CatalogActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

test('meal adds a catalog activity and is recorded as its creator', function () {
    $meal = userWithRole('MEAL');
    $this->actingAs($meal);

    $activity = app(SaveCatalogActivity::class)->handle(null, ['name' => '  Beginner training ', 'description' => null, 'isActive' => true]);

    expect($activity->name)->toBe('Beginner training')
        ->and($activity->created_by)->toBe($meal->id);
});

test('a name is required and at most 150 characters', function () {
    $this->actingAs(userWithRole('MEAL'));

    expect(fn () => app(SaveCatalogActivity::class)->handle(null, ['name' => ' ', 'description' => null, 'isActive' => true]))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveCatalogActivity::class)->handle(null, ['name' => str_repeat('a', 151), 'description' => null, 'isActive' => true]))->toThrow(ValidationException::class);
});

test('an active twin is reported, a switched-off one is not', function () {
    CatalogActivity::factory()->create(['name' => 'Beginner training']);
    CatalogActivity::factory()->inactive()->create(['name' => 'Old workshop']);

    expect(SaveCatalogActivity::activeTwinExists('beginner TRAINING'))->toBeTrue()
        ->and(SaveCatalogActivity::activeTwinExists('Old workshop'))->toBeFalse();
});

test('a team member cannot switch an activity off', function () {
    $activity = CatalogActivity::factory()->create();
    $this->actingAs(userWithRole('Team Member'));

    expect(fn () => app(SaveCatalogActivity::class)->handle($activity, ['name' => $activity->name, 'description' => null, 'isActive' => false]))->toThrow(AuthorizationException::class);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/SaveCatalogActivityTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Models\CatalogActivity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Add, rename or switch off a catalog activity.
 */
class SaveCatalogActivity
{
    /**
     * @param  array{name: string, description: string|null, isActive: bool}  $input
     */
    public function handle(?CatalogActivity $activity, array $input): CatalogActivity
    {
        if ($activity === null) {
            Gate::authorize('create', CatalogActivity::class);
        } else {
            Gate::authorize('update', $activity);
        }

        $name = trim($input['name']);

        if ($name === '' || mb_strlen($name) > 150) {
            throw ValidationException::withMessages(['catalogName' => __('annual_plans.name_the_activity_in_at_most_150_characters')]);
        }

        $activity ??= new CatalogActivity(['created_by' => Auth::id()]);

        $activity->fill([
            'name' => $name,
            'description' => filled($input['description']) ? trim((string) $input['description']) : null,
            'is_active' => $input['isActive'],
        ])->save();

        return $activity;
    }

    /**
     * Whether another active activity already carries this name (any case).
     */
    public static function activeTwinExists(string $name, ?int $ignoreId = null): bool
    {
        return CatalogActivity::query()
            ->active()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }
}
```

Lang (en): `'name_the_activity_in_at_most_150_characters' => 'Name the activity in at most 150 characters.'`, `'an_active_activity_already_has_this_name' => 'An active activity already has this name. Use it unless this one is different.'`
Lang (ar): `'name_the_activity_in_at_most_150_characters' => 'اكتب اسم النشاط في 150 حرفًا كحد أقصى.'`, `'an_active_activity_already_has_this_name' => 'يوجد نشاط نشط بهذا الاسم. استخدمه إلا إذا كان هذا مختلفًا.'`

- [ ] **Step 4: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/SaveCatalogActivityTest.php`
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add app/Actions/AnnualPlans/SaveCatalogActivity.php lang/en/annual_plans.php lang/ar/annual_plans.php tests/Feature/AnnualPlans/SaveCatalogActivityTest.php
```

---

### Task 19: `PlanComparison`: the one place outcomes are decided

**Files:**
- Create: `app/Support/Plans/PlanComparison.php`
- Test: `tests/Unit/PlanComparisonTest.php`

**Interfaces:**
- Produces:
  - `PlanComparison::cells(list<string> $periodKeys, list<string> $plannedKeys, array<string, array{status: OccurrenceStatus, movedTo: string|null}> $occurrences, string $currentKey): array<string, array{outcome: PlanCellOutcome, planned: bool, movedIn: bool, movedTo: string|null}>`
  - `PlanComparison::worst(list<PlanCellOutcome> $outcomes): PlanCellOutcome`
  - `PlanComparison::totals(list<array<string, array{outcome: PlanCellOutcome, planned: bool}>> $rows): array{planned: int, onPlan: int, shifted: int, postponed: int, missed: int, due: int, pending: int, upcoming: int, unplanned: int, matchRate: int|null, onPlanSoFar: int|null}`
  - `PlanComparison::verdict(array<string, array{outcome: PlanCellOutcome, planned: bool}> $cells): array{key: string, onPlan: int, planned: int}`, where `key` ∈ `not_in_plan|matches_plan|partly`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\OccurrenceStatus;
use App\Enums\PlanCellOutcome;
use App\Support\Plans\PlanComparison;

test('a verified planned month is on plan and a verified unplanned one is unplanned', function () {
    $cells = (new PlanComparison)->cells(
        ['2027-01', '2027-02', '2027-03'],
        ['2027-01'],
        ['2027-01' => ['status' => OccurrenceStatus::Verified, 'movedTo' => null], '2027-02' => ['status' => OccurrenceStatus::Verified, 'movedTo' => null]],
        '2027-03',
    );

    expect($cells['2027-01']['outcome'])->toBe(PlanCellOutcome::OnPlan)
        ->and($cells['2027-02']['outcome'])->toBe(PlanCellOutcome::Unplanned)
        ->and($cells['2027-03']['outcome'])->toBe(PlanCellOutcome::None);
});

test('unreported planned months are due up to the current month, upcoming after', function () {
    $cells = (new PlanComparison)->cells(['2027-08', '2027-09', '2027-10'], ['2027-08', '2027-09', '2027-10'], [], '2027-09');

    expect($cells['2027-08']['outcome'])->toBe(PlanCellOutcome::Due)
        ->and($cells['2027-09']['outcome'])->toBe(PlanCellOutcome::Due)
        ->and($cells['2027-10']['outcome'])->toBe(PlanCellOutcome::Upcoming);
});

test('a postponement reads as postponed until the moved-to month is verified, then shifted on both ends', function () {
    $comparison = new PlanComparison;
    $period = ['2027-07', '2027-08', '2027-09', '2027-10'];
    $postponed = ['2027-07' => ['status' => OccurrenceStatus::Postponed, 'movedTo' => '2027-10']];

    $before = $comparison->cells($period, ['2027-07'], $postponed, '2027-09');
    $after = $comparison->cells($period, ['2027-07'], [...$postponed, '2027-10' => ['status' => OccurrenceStatus::Verified, 'movedTo' => null]], '2027-10');

    expect($before['2027-07']['outcome'])->toBe(PlanCellOutcome::Postponed)
        ->and($before['2027-10']['outcome'])->toBe(PlanCellOutcome::Upcoming)
        ->and($before['2027-10']['movedIn'])->toBeTrue()
        ->and($after['2027-07']['outcome'])->toBe(PlanCellOutcome::Shifted)
        ->and($after['2027-10']['outcome'])->toBe(PlanCellOutcome::Shifted);
});

test('reported is pending, sent back is due, missed is missed', function () {
    $cells = (new PlanComparison)->cells(['2027-01', '2027-02', '2027-03'], ['2027-01', '2027-02', '2027-03'], [
        '2027-01' => ['status' => OccurrenceStatus::Reported, 'movedTo' => null],
        '2027-02' => ['status' => OccurrenceStatus::SentBack, 'movedTo' => null],
        '2027-03' => ['status' => OccurrenceStatus::Missed, 'movedTo' => null],
    ], '2027-03');

    expect(array_map(fn (array $cell): PlanCellOutcome => $cell['outcome'], array_values($cells)))
        ->toBe([PlanCellOutcome::Pending, PlanCellOutcome::Due, PlanCellOutcome::Missed]);
});

test('totals count planned cells and verified unplanned work', function () {
    $comparison = new PlanComparison;
    $period = ['2027-01', '2027-02', '2027-03', '2027-04', '2027-05'];
    $row = $comparison->cells($period, ['2027-01', '2027-02', '2027-03', '2027-04'], [
        '2027-01' => ['status' => OccurrenceStatus::Verified, 'movedTo' => null],
        '2027-02' => ['status' => OccurrenceStatus::Postponed, 'movedTo' => '2027-05'],
        '2027-05' => ['status' => OccurrenceStatus::Verified, 'movedTo' => null],
        '2027-03' => ['status' => OccurrenceStatus::Missed, 'movedTo' => null],
    ], '2027-05');
    $unplannedRow = $comparison->cells($period, [], ['2027-04' => ['status' => OccurrenceStatus::Verified, 'movedTo' => null]], '2027-05');

    expect($comparison->totals([$row, $unplannedRow]))->toMatchArray([
        'planned' => 4, 'onPlan' => 1, 'shifted' => 1, 'missed' => 1, 'due' => 1, 'unplanned' => 1, 'matchRate' => 25,
    ]);
});

test('the worst outcome wins', function () {
    expect((new PlanComparison)->worst([PlanCellOutcome::OnPlan, PlanCellOutcome::Missed, PlanCellOutcome::Pending]))->toBe(PlanCellOutcome::Missed)
        ->and((new PlanComparison)->worst([]))->toBe(PlanCellOutcome::None);
});

test('a verdict says whether a row matched its plan', function () {
    $comparison = new PlanComparison;
    $matching = $comparison->cells(['2027-01'], ['2027-01'], ['2027-01' => ['status' => OccurrenceStatus::Verified, 'movedTo' => null]], '2027-01');

    expect($comparison->verdict($matching))->toBe(['key' => 'matches_plan', 'onPlan' => 1, 'planned' => 1])
        ->and($comparison->verdict($comparison->cells(['2027-01'], [], [], '2027-01'))['key'])->toBe('not_in_plan');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Unit/PlanComparisonTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Support\Plans;

use App\Enums\OccurrenceStatus;
use App\Enums\PlanCellOutcome;

/**
 * Compares the planned layer with the actual layer, one row at a time.
 *
 * Pure: it takes month keys and occurrence states, never models, so the
 * grid, the review totals and the reminders share one definition and it is
 * tested without a database. Month keys are "YYYY-MM", which order correctly
 * as strings.
 */
final class PlanComparison
{
    /**
     * @param  list<string>  $periodKeys
     * @param  list<string>  $plannedKeys
     * @param  array<string, array{status: OccurrenceStatus, movedTo: string|null}>  $occurrences
     * @return array<string, array{outcome: PlanCellOutcome, planned: bool, movedIn: bool, movedTo: string|null}>
     */
    public function cells(array $periodKeys, array $plannedKeys, array $occurrences, string $currentKey): array
    {
        $movedIn = [];

        foreach ($occurrences as $occurrence) {
            if ($occurrence['status'] === OccurrenceStatus::Postponed && $occurrence['movedTo'] !== null) {
                $movedIn[$occurrence['movedTo']] = true;
            }
        }

        $cells = [];

        foreach ($periodKeys as $key) {
            $planned = in_array($key, $plannedKeys, true);
            $isMovedIn = isset($movedIn[$key]);
            $occurrence = $occurrences[$key] ?? null;
            $destinationVerified = $occurrence !== null
                && $occurrence['movedTo'] !== null
                && ($occurrences[$occurrence['movedTo']]['status'] ?? null) === OccurrenceStatus::Verified;

            $outcome = match ($occurrence['status'] ?? null) {
                OccurrenceStatus::Verified => $planned ? PlanCellOutcome::OnPlan : ($isMovedIn ? PlanCellOutcome::Shifted : PlanCellOutcome::Unplanned),
                OccurrenceStatus::Reported => PlanCellOutcome::Pending,
                OccurrenceStatus::Postponed => $destinationVerified ? PlanCellOutcome::Shifted : PlanCellOutcome::Postponed,
                OccurrenceStatus::Missed => PlanCellOutcome::Missed,
                OccurrenceStatus::SentBack => PlanCellOutcome::Due,
                null => ($planned || $isMovedIn)
                    ? ($key <= $currentKey ? PlanCellOutcome::Due : PlanCellOutcome::Upcoming)
                    : PlanCellOutcome::None,
            };

            $cells[$key] = [
                'outcome' => $outcome,
                'planned' => $planned,
                'movedIn' => $isMovedIn,
                'movedTo' => $occurrence['movedTo'] ?? null,
            ];
        }

        return $cells;
    }

    /**
     * @param  list<PlanCellOutcome>  $outcomes
     */
    public function worst(array $outcomes): PlanCellOutcome
    {
        $worst = PlanCellOutcome::None;

        foreach ($outcomes as $outcome) {
            if ($outcome->severity() > $worst->severity()) {
                $worst = $outcome;
            }
        }

        return $worst;
    }

    /**
     * @param  list<array<string, array{outcome: PlanCellOutcome, planned: bool}>>  $rows
     * @return array{planned: int, onPlan: int, shifted: int, postponed: int, missed: int, due: int, pending: int, upcoming: int, unplanned: int, matchRate: int|null, onPlanSoFar: int|null}
     */
    public function totals(array $rows): array
    {
        $totals = ['planned' => 0, 'onPlan' => 0, 'shifted' => 0, 'postponed' => 0, 'missed' => 0, 'due' => 0, 'pending' => 0, 'upcoming' => 0, 'unplanned' => 0];

        foreach ($rows as $cells) {
            foreach ($cells as $cell) {
                if (! $cell['planned']) {
                    if ($cell['outcome'] === PlanCellOutcome::Unplanned) {
                        $totals['unplanned']++;
                    }

                    continue;
                }

                $totals['planned']++;

                $bucket = match ($cell['outcome']) {
                    PlanCellOutcome::OnPlan => 'onPlan',
                    PlanCellOutcome::Shifted => 'shifted',
                    PlanCellOutcome::Postponed => 'postponed',
                    PlanCellOutcome::Missed => 'missed',
                    PlanCellOutcome::Due => 'due',
                    PlanCellOutcome::Pending => 'pending',
                    PlanCellOutcome::Upcoming => 'upcoming',
                    PlanCellOutcome::Unplanned, PlanCellOutcome::None => null,
                };

                if ($bucket !== null) {
                    $totals[$bucket]++;
                }
            }
        }

        $dueSoFar = $totals['planned'] - $totals['upcoming'];

        return [
            ...$totals,
            'matchRate' => $totals['planned'] === 0 ? null : (int) round($totals['onPlan'] * 100 / $totals['planned']),
            'onPlanSoFar' => $dueSoFar <= 0 ? null : (int) round($totals['onPlan'] * 100 / $dueSoFar),
        ];
    }

    /**
     * @param  array<string, array{outcome: PlanCellOutcome, planned: bool}>  $cells
     * @return array{key: string, onPlan: int, planned: int}
     */
    public function verdict(array $cells): array
    {
        $planned = count(array_filter($cells, fn (array $cell): bool => $cell['planned']));
        $onPlan = count(array_filter($cells, fn (array $cell): bool => $cell['planned'] && $cell['outcome'] === PlanCellOutcome::OnPlan));

        return [
            'key' => $planned === 0 ? 'not_in_plan' : ($onPlan === $planned ? 'matches_plan' : 'partly'),
            'onPlan' => $onPlan,
            'planned' => $planned,
        ];
    }
}
```

- [ ] **Step 4: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Unit/PlanComparisonTest.php`
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add app/Support/Plans/PlanComparison.php tests/Unit/PlanComparisonTest.php
```

---

### Task 20: `PlanBoard`: load a plan once, shape every row

**Files:**
- Create: `app/Support/Plans/PlanBoard.php`
- Test: `tests/Feature/AnnualPlans/PlanBoardTest.php`

**Interfaces:**
- Consumes: `PlanComparison`, `PlanPeriod`.
- Produces:
  - `PlanBoard::for(AnnualPlan $plan, ?CarbonImmutable $today = null): array{months: list<array{key: string, label: string, isCurrent: bool}>, rows: list<array<string, mixed>>, totals: array<string, int|null>}`
  - each row: `id, name, responsibleName, tracking, isUnplanned, plannedCount, verdict, targetCount, milestoneCount, cells, projects`
  - each cell: `key, outcome, planned, movedIn, movedTo, threadBefore, threadAfter, isCurrent, occurrenceId, occurrenceStatus, evidenceCount, projectId`
  - each `projects` item: `id, name, cells` (per-project rows only, else `[]`)
  - `PlanBoard::trail(AnnualPlan $plan, int $activityId, string $monthKey, ?int $projectId): Collection<int, AnnualPlanEvent>` with `user`
  - `PlanBoard::trackedProjects(AnnualPlan $plan, ?Collection $activities = null): Collection<int, Project>`
- Query budget: **at most 7 queries on the default connection** for any number of rows (activities, catalog, months, occurrences + media count, the two `withCount`s fold into the activities query, projects). HR names are one query on the `hr` connection.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\PlanCellOutcome;
use App\Enums\ProjectStatus;
use App\Models\AnnualPlan;
use App\Models\PlanActivity;
use App\Models\PlanOccurrence;
use App\Models\Project;
use App\Support\Plans\PlanBoard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->today = CarbonImmutable::parse('2027-09-15');
    $this->plan = AnnualPlan::factory()->between('2027-07', '2027-12')->active()->create();
});

test('the board lists the period months and marks the current one', function () {
    $board = app(PlanBoard::class)->for($this->plan, $this->today);

    expect(array_column($board['months'], 'key'))->toBe(['2027-07', '2027-08', '2027-09', '2027-10', '2027-11', '2027-12'])
        ->and(array_column($board['months'], 'isCurrent'))->toBe([false, false, true, false, false, false]);
});

test('consecutive planned months are threaded together', function () {
    PlanActivity::factory()->inMonths('2027-07', '2027-08', '2027-10')->create(['annual_plan_id' => $this->plan->id]);

    $cells = app(PlanBoard::class)->for($this->plan, $this->today)['rows'][0]['cells'];

    expect($cells[0]['threadAfter'])->toBeTrue()
        ->and($cells[1]['threadBefore'])->toBeTrue()
        ->and($cells[1]['threadAfter'])->toBeFalse()
        ->and($cells[3]['threadBefore'])->toBeFalse();
});

test('a per-project row collapses to its worst project and lists each open project', function () {
    $activity = PlanActivity::factory()->perProject()->inMonths('2027-08')->create(['annual_plan_id' => $this->plan->id]);
    $good = Project::factory()->create(['program_id' => $this->plan->program_id, 'name' => 'Hub A', 'status' => ProjectStatus::Active]);
    Project::factory()->create(['program_id' => $this->plan->program_id, 'name' => 'Hub B', 'status' => ProjectStatus::Active]);
    Project::factory()->create(['program_id' => $this->plan->program_id, 'name' => 'Old', 'status' => ProjectStatus::Closed]);
    PlanOccurrence::factory()->verified()->forProject($good)->create(['plan_activity_id' => $activity->id, 'month' => '2027-08-01']);

    $row = app(PlanBoard::class)->for($this->plan, $this->today)['rows'][0];

    expect($row['cells'][1]['outcome'])->toBe(PlanCellOutcome::Due)
        ->and(array_column($row['projects'], 'name'))->toBe(['Hub A', 'Hub B'])
        ->and($row['projects'][0]['cells'][1]['outcome'])->toBe(PlanCellOutcome::OnPlan)
        ->and($row['projects'][1]['cells'][1]['outcome'])->toBe(PlanCellOutcome::Due);
});

test('the board reads a fixed number of queries however many rows', function () {
    PlanActivity::factory(6)->inMonths('2027-07', '2027-09')->create(['annual_plan_id' => $this->plan->id]);
    $plan = AnnualPlan::query()->findOrFail($this->plan->id);

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();
    app(PlanBoard::class)->for($plan, $this->today);

    expect(count(DB::connection()->getQueryLog()))->toBeLessThanOrEqual(7);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanBoardTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Support\Plans;

use App\Enums\OccurrenceStatus;
use App\Enums\PlanActivityTracking;
use App\Enums\PlanCellOutcome;
use App\Enums\ProjectStatus;
use App\Models\AnnualPlan;
use App\Models\AnnualPlanEvent;
use App\Models\PlanActivity;
use App\Models\PlanActivityMonth;
use App\Models\PlanOccurrence;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Everything the plan workspace draws, read in a fixed number of queries.
 *
 * Rows come back as plain arrays so the Blade components render without
 * touching a model (no lazy loads under strict mode, no N+1 per cell).
 */
final class PlanBoard
{
    public function __construct(private PlanComparison $comparison) {}

    /**
     * @return array{months: list<array{key: string, label: string, isCurrent: bool}>, rows: list<array<string, mixed>>, totals: array<string, int|null>}
     */
    public function for(AnnualPlan $plan, ?CarbonImmutable $today = null): array
    {
        $currentKey = PlanPeriod::key(($today ?? CarbonImmutable::now())->startOfMonth());
        $months = PlanPeriod::months($plan->starts_on, $plan->ends_on);
        $periodKeys = array_map(fn (CarbonImmutable $month): string => PlanPeriod::key($month), $months);

        $activities = PlanActivity::query()
            ->where('annual_plan_id', $plan->id)
            ->with([
                'catalogActivity:id,name',
                'responsible',
                'months:id,plan_activity_id,month',
                'occurrences' => fn ($query) => $query
                    ->select(['id', 'plan_activity_id', 'project_id', 'project_key', 'month', 'status', 'moved_to'])
                    ->withCount('media'),
            ])
            ->withCount(['planIndicators', 'milestones'])
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $projects = $activities->contains(fn (PlanActivity $activity): bool => $activity->tracking === PlanActivityTracking::PerProject)
            ? $this->trackedProjects($plan, $activities)
            : new Collection;

        $rows = [];
        $comparedRows = [];

        foreach ($activities as $activity) {
            $plannedKeys = $activity->months->map(fn (PlanActivityMonth $month): string => PlanPeriod::key($month->month))->values()->all();
            $byProject = $activity->occurrences->groupBy('project_key');
            $projectRows = [];

            if ($activity->tracking === PlanActivityTracking::PerProject) {
                $projectCells = [];

                foreach ($projects as $project) {
                    $occurrences = $byProject->get($project->id);
                    $compared = $this->comparison->cells($periodKeys, $plannedKeys, $this->states($occurrences), $currentKey);
                    $projectCells[] = $compared;
                    $comparedRows[] = $compared;
                    $projectRows[] = ['id' => $project->id, 'name' => $project->name, 'cells' => $this->shape($compared, $plannedKeys, $currentKey, $occurrences, $project->id)];
                }

                $verdictCells = [];

                foreach ($periodKeys as $key) {
                    $verdictCells[$key] = [
                        'outcome' => $this->comparison->worst(array_map(fn (array $cells): PlanCellOutcome => $cells[$key]['outcome'], $projectCells)),
                        'planned' => in_array($key, $plannedKeys, true),
                        'movedIn' => false,
                        'movedTo' => null,
                    ];
                }

                $cells = $this->shape($verdictCells, $plannedKeys, $currentKey, null, null);
            } else {
                $occurrences = $byProject->get(0);
                $verdictCells = $this->comparison->cells($periodKeys, $plannedKeys, $this->states($occurrences), $currentKey);
                $comparedRows[] = $verdictCells;
                $cells = $this->shape($verdictCells, $plannedKeys, $currentKey, $occurrences, null);
            }

            $rows[] = [
                'id' => $activity->id,
                'name' => $activity->name(),
                'responsibleName' => $activity->responsible?->name,
                'tracking' => $activity->tracking,
                'isUnplanned' => $activity->is_unplanned,
                'plannedCount' => count($plannedKeys),
                'verdict' => $this->comparison->verdict($verdictCells),
                'targetCount' => (int) $activity->plan_indicators_count,
                'milestoneCount' => (int) $activity->milestones_count,
                'cells' => $cells,
                'projects' => $projectRows,
            ];
        }

        return [
            'months' => array_map(fn (CarbonImmutable $month): array => [
                'key' => PlanPeriod::key($month),
                'label' => $month->translatedFormat(__('formats.month_short')),
                'isCurrent' => PlanPeriod::key($month) === $currentKey,
            ], $months),
            'rows' => $rows,
            'totals' => $this->comparison->totals($comparedRows),
        ];
    }

    /**
     * The projects a per-project activity is tracked for (D10): every project
     * of the program that is not closed, plus any that already holds a record.
     *
     * @param  Collection<int, PlanActivity>|null  $activities
     * @return Collection<int, Project>
     */
    public function trackedProjects(AnnualPlan $plan, ?Collection $activities = null): Collection
    {
        $withRecords = $activities === null
            ? PlanOccurrence::query()
                ->whereIn('plan_activity_id', PlanActivity::query()->where('annual_plan_id', $plan->id)->select('id'))
                ->whereNotNull('project_id')
                ->distinct()
                ->pluck('project_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all()
            : $activities->flatMap(fn (PlanActivity $activity): SupportCollection => $activity->occurrences->pluck('project_id'))->filter()->unique()->values()->all();

        return Project::query()
            ->select(['id', 'name', 'slug', 'program_id', 'status'])
            ->where('program_id', $plan->program_id)
            ->where(fn ($query) => $query->where('status', '!=', ProjectStatus::Closed->value)->orWhereIn('id', $withRecords))
            ->orderBy('name')
            ->get();
    }

    /**
     * The journal lines of one cell, newest first.
     *
     * @return Collection<int, AnnualPlanEvent>
     */
    public function trail(AnnualPlan $plan, int $activityId, string $monthKey, ?int $projectId): Collection
    {
        return AnnualPlanEvent::query()
            ->where('annual_plan_id', $plan->id)
            ->where('plan_activity_id', $activityId)
            ->whereDate('month', PlanPeriod::fromKey($monthKey)->toDateString())
            ->when($projectId === null, fn ($query) => $query->whereNull('project_id'), fn ($query) => $query->where('project_id', $projectId))
            ->with('user:id,hr_user_id')
            ->latest('created_at')
            ->latest('id')
            ->get();
    }

    /**
     * @param  SupportCollection<int, PlanOccurrence>|null  $occurrences
     * @return array<string, array{status: OccurrenceStatus, movedTo: string|null}>
     */
    private function states(?SupportCollection $occurrences): array
    {
        $states = [];

        foreach ($occurrences ?? [] as $occurrence) {
            $states[PlanPeriod::key($occurrence->month)] = [
                'status' => $occurrence->status,
                'movedTo' => $occurrence->moved_to === null ? null : PlanPeriod::key($occurrence->moved_to),
            ];
        }

        return $states;
    }

    /**
     * @param  array<string, array{outcome: PlanCellOutcome, planned: bool, movedIn: bool, movedTo: string|null}>  $compared
     * @param  list<string>  $plannedKeys
     * @param  SupportCollection<int, PlanOccurrence>|null  $occurrences
     * @return list<array<string, mixed>>
     */
    private function shape(array $compared, array $plannedKeys, string $currentKey, ?SupportCollection $occurrences, ?int $projectId): array
    {
        $byKey = [];

        foreach ($occurrences ?? [] as $occurrence) {
            $byKey[PlanPeriod::key($occurrence->month)] = $occurrence;
        }

        $keys = array_keys($compared);
        $cells = [];

        foreach ($keys as $index => $key) {
            $planned = $compared[$key]['planned'];
            $occurrence = $byKey[$key] ?? null;

            $cells[] = [
                'key' => $key,
                'outcome' => $compared[$key]['outcome'],
                'planned' => $planned,
                'movedIn' => $compared[$key]['movedIn'],
                'movedTo' => $compared[$key]['movedTo'],
                'threadBefore' => $planned && in_array($keys[$index - 1] ?? '', $plannedKeys, true),
                'threadAfter' => $planned && in_array($keys[$index + 1] ?? '', $plannedKeys, true),
                'isCurrent' => $key === $currentKey,
                'occurrenceId' => $occurrence?->id,
                'occurrenceStatus' => $occurrence?->status,
                'evidenceCount' => (int) ($occurrence?->media_count ?? 0),
                'projectId' => $projectId,
            ];
        }

        return $cells;
    }
}
```

> `$activity->occurrences->groupBy('project_key')` keys groups by the integer `project_key` (0 for shared). The `project_key` cast is `integer`, so `get(0)` and `get($project->id)` match.

- [ ] **Step 4: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanBoardTest.php`
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add app/Support/Plans/PlanBoard.php tests/Feature/AnnualPlans/PlanBoardTest.php
```

---

# Phase C: Reminders and automation

### Task 21: Month-end reminders, overdue nudges, waiting reports

**Files:**
- Create: `app/Notifications/Alerts/PlanTrackingScanner.php`
- Modify: `config/notifications.php` (`defaults` + `scanners.daily`), `resources/views/pages/admin/⚡notification-settings.blade.php`, `lang/{en,ar}/notifications.php`
- Test: `tests/Feature/Notifications/PlanTrackingScannerTest.php`

**Interfaces:**
- Consumes: `PlanBoard::for()`, `AlertDispatcher::payload()/send()`, `EmployeeRecipients::usersFor()`, `RecipientResolver::usersWithRoles()`, `WorkingCalendar`, `NotificationSettings`.
- Produces:
  - settings `plan_update_due_days` (default 3) and `plan_verification_days` (default 5)
  - alert keys `notifications.alerts.plan_tracking.{update_due,overdue,awaiting_verification}`
  - rule `plan_tracking`, with stages `update_due:{activity}:{projectKey}:{Y-m}`, `overdue:{activity}:{projectKey}:{Y-m}`, `awaiting:{occurrence}:{reported Y-m-d}`

Rules:
1. **Update due**: on a working day with fewer than `plan_update_due_days` working days left in the month, every Active plan's **current**-month cell with outcome `due` → the plan manager (direct).
2. **Overdue**: every Active plan's **past**-month cell with outcome `due` → the plan manager (direct) + MEAL (others). Once per cell (the stage key carries the cell).
3. **Awaiting verification**: occurrences `reported` for at least `plan_verification_days` working days, in Active or Final review plans → MEAL.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Activity;
use App\Models\AnnualPlan;
use App\Models\PlanActivity;
use App\Models\PlanOccurrence;
use App\Notifications\Alerts\PlanTrackingScanner;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->employee = insertHrEmployee();
    $this->manager = userWithRole('Team Member', $this->employee);
    $this->meal = userWithRole('MEAL');
    [$this->plan, $this->activity] = Activity::withoutRecording(function (): array {
        $plan = AnnualPlan::factory()->between('2027-01', '2027-12')->active()->create(['manager_id' => $this->employee]);

        return [$plan, PlanActivity::factory()->inMonths('2027-08', '2027-09')->create(['annual_plan_id' => $plan->id])];
    });
});

function mentions(array $sentences, string $needle): int
{
    return collect($sentences)->filter(fn (string $sentence): bool => str_contains($sentence, $needle))->count();
}

test('near month end the manager is reminded to report the current month', function () {
    app(PlanTrackingScanner::class)->scan(CarbonImmutable::parse('2027-09-28 06:00'));

    expect(mentions(sentencesFor($this->manager), 'Sep 2027'))->toBe(1);
});

test('mid-month there is no month-end reminder', function () {
    app(PlanTrackingScanner::class)->scan(CarbonImmutable::parse('2027-09-14 06:00'));

    expect(mentions(sentencesFor($this->manager), 'Sep 2027'))->toBe(0);
});

test('an unreported past month reaches the manager and meal once', function () {
    $scanner = app(PlanTrackingScanner::class);
    $scanner->scan(CarbonImmutable::parse('2027-09-14 06:00'));
    $scanner->scan(CarbonImmutable::parse('2027-09-15 06:00'));

    expect(mentions(sentencesFor($this->manager), 'Aug 2027'))->toBe(1)
        ->and(mentions(sentencesFor($this->meal), 'Aug 2027'))->toBe(1);
});

test('a report waiting too long nudges meal', function () {
    Activity::withoutRecording(fn () => PlanOccurrence::factory()->reported()->create([
        'plan_activity_id' => $this->activity->id,
        'month' => '2027-08-01',
        'reported_at' => '2027-09-01 10:00:00',
    ]));

    app(PlanTrackingScanner::class)->scan(CarbonImmutable::parse('2027-09-14 06:00'));

    expect(mentions(sentencesFor($this->meal), 'waiting for verification'))->toBe(1);
});

test('nothing is sent on a non-working day', function () {
    expect(app(PlanTrackingScanner::class)->scan(CarbonImmutable::parse('2027-09-25 06:00')))->toBe(0);
});
```

> Dates: 2027-09-25 is a Saturday, 2027-09-28 a Tuesday (2 working days left after it: 29th and 30th, so fewer than 3), and 2027-09-14 a Tuesday mid-month. Confirm with `CarbonImmutable::parse(...)->dayOfWeekIso` when writing the test, and adjust if `workingDaysBetween` counts endpoints differently (read `WorkingCalendar::workingDaysBetween` first).

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/Notifications/PlanTrackingScannerTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement the scanner**

```php
<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationPriority;
use App\Enums\OccurrenceStatus;
use App\Enums\PlanCellOutcome;
use App\Enums\PlanStatus;
use App\Models\AnnualPlan;
use App\Models\PlanOccurrence;
use App\Notifications\EmployeeRecipients;
use App\Notifications\RecipientResolver;
use App\Support\Plans\PlanBoard;
use App\Support\Plans\PlanPeriod;
use Carbon\CarbonImmutable;

/**
 * Keeps the actual layer filled in: reminds a plan manager to report the
 * current month as it ends, tells the manager and MEAL about a past month
 * nobody reported, and nudges MEAL about reports waiting too long. Stage keys
 * embed the cell, so each nudge fires once per cell (see AlertLedger).
 */
final class PlanTrackingScanner implements Scanner
{
    public function __construct(
        private AlertDispatcher $dispatcher,
        private RecipientResolver $resolver,
        private EmployeeRecipients $employees,
        private WorkingCalendar $calendar,
        private NotificationSettings $settings,
        private PlanBoard $board,
    ) {}

    public function scan(CarbonImmutable $now): int
    {
        if (! $this->calendar->isWorkingDay($now)) {
            return 0;
        }

        $meal = $this->resolver->usersWithRoles(['MEAL']);
        $currentKey = PlanPeriod::key($now->startOfMonth());
        $nearMonthEnd = $this->calendar->workingDaysBetween($now->startOfDay(), $now->endOfMonth()->startOfDay()) < (int) $this->settings->get('plan_update_due_days');
        $sent = 0;

        $plans = AnnualPlan::query()
            ->where('status', PlanStatus::Active->value)
            ->with(['program:id', 'project:id,name'])
            ->get();

        $managers = $this->employees->usersFor($plans->pluck('manager_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all());

        foreach ($plans as $plan) {
            $direct = array_values(array_filter([$managers[$plan->manager_id] ?? null]));

            foreach ($this->board->for($plan, $now)['rows'] as $row) {
                foreach ($this->dueCells($row) as [$cell, $projectKey]) {
                    $isCurrent = $cell['key'] === $currentKey;

                    if ($cell['key'] > $currentKey || ($isCurrent && ! $nearMonthEnd)) {
                        continue;
                    }

                    $rule = $isCurrent ? 'update_due' : 'overdue';
                    $payload = $this->dispatcher->payload(
                        'annual-plans',
                        $isCurrent ? NotificationPriority::Info : NotificationPriority::Important,
                        "notifications.alerts.plan_tracking.{$rule}",
                        ['subject' => $plan->label(), 'activity' => $row['name'], 'month' => ['month' => $cell['key'].'-01']],
                        'annual_plan',
                        $plan->id,
                    );

                    $sent += $this->dispatcher->send('plan_tracking', "{$rule}:{$row['id']}:{$projectKey}:{$cell['key']}", $payload, $direct, $isCurrent ? [] : $meal);
                }
            }
        }

        return $sent + $this->waitingReports($now, $meal);
    }

    /**
     * Due cells of a row: the row's own cells, or each project's.
     *
     * @param  array<string, mixed>  $row
     * @return list<array{0: array<string, mixed>, 1: int}>
     */
    private function dueCells(array $row): array
    {
        $sources = $row['projects'] === []
            ? [[0, $row['cells']]]
            : array_map(fn (array $project): array => [(int) $project['id'], $project['cells']], $row['projects']);
        $found = [];

        foreach ($sources as [$projectKey, $cells]) {
            foreach ($cells as $cell) {
                if ($cell['outcome'] === PlanCellOutcome::Due) {
                    $found[] = [$cell, $projectKey];
                }
            }
        }

        return $found;
    }

    /**
     * @param  list<int>  $meal
     */
    private function waitingReports(CarbonImmutable $now, array $meal): int
    {
        $threshold = (int) $this->settings->get('plan_verification_days');
        $sent = 0;

        $waiting = PlanOccurrence::query()
            ->where('status', OccurrenceStatus::Reported->value)
            ->whereHas('planActivity.plan', fn ($query) => $query->whereIn('status', [PlanStatus::Active->value, PlanStatus::FinalReview->value]))
            ->with([
                'planActivity:id,annual_plan_id,catalog_activity_id',
                'planActivity.catalogActivity:id,name',
                'planActivity.plan:id,program_id,project_id,starts_on,ends_on',
                'planActivity.plan.program:id',
                'planActivity.plan.project:id,name',
            ])
            ->get();

        foreach ($waiting as $occurrence) {
            if ($occurrence->reported_at === null || $this->calendar->workingDaysBetween($occurrence->reported_at->startOfDay(), $now->startOfDay()) < $threshold) {
                continue;
            }

            $plan = $occurrence->planActivity->plan;
            $payload = $this->dispatcher->payload(
                'annual-plans',
                NotificationPriority::Info,
                'notifications.alerts.plan_tracking.awaiting_verification',
                ['subject' => $plan->label(), 'activity' => $occurrence->planActivity->name(), 'month' => ['month' => $occurrence->month->toDateString()]],
                'annual_plan',
                $plan->id,
            );

            $sent += $this->dispatcher->send('plan_tracking', "awaiting:{$occurrence->id}:{$occurrence->reported_at->toDateString()}", $payload, [], $meal);
        }

        return $sent;
    }
}
```

- [ ] **Step 4: Config, settings page, lang**

`config/notifications.php`:
- in `defaults` add `'plan_update_due_days' => 3,` and `'plan_verification_days' => 5,`
- `scanners.daily` → `[OffTrackIndicatorScanner::class, DataGapScanner::class, PlanTrackingScanner::class]` (+ `use App\Notifications\Alerts\PlanTrackingScanner;`)

`resources/views/pages/admin/⚡notification-settings.blade.php`:
- properties: `public int $planUpdateDue = 3;` and `public int $planVerification = 5;`
- `Keys`: add `'planUpdateDue' => 'plan_update_due_days', 'planVerification' => 'plan_verification_days',`
- validation: add `'planUpdateDue' => ['required', 'integer', 'between:1,10'], 'planVerification' => ['required', 'integer', 'between:1,60'],`
- markup: after the `stalledProposal` rules row, add (read `pages/admin/_rules-row.blade.php` first and pass any extra keys the `stalled*` rows pass):

```blade
@include('pages.admin._rules-row', ['property' => 'planUpdateDue', 'label' => __('notifications.admin.plan_update_due')])
@include('pages.admin._rules-row', ['property' => 'planVerification', 'label' => __('notifications.admin.plan_verification')])
```

`lang/en/notifications.php`, under `alerts`:

```php
        'plan_tracking' => [
            'update_due' => 'Report “:activity” for :month in “:subject” before the month ends',
            'overdue' => '“:activity” for :month in “:subject” has not been reported',
            'awaiting_verification' => 'A report on “:activity” for :month in “:subject” is waiting for verification',
        ],
```

and under `admin`:

```php
        'plan_update_due' => 'Remind plan managers this many working days before month end',
        'plan_verification' => 'Nudge MEAL when a month report has waited this many working days',
```

`lang/ar/notifications.php`, same places:

```php
        'plan_tracking' => [
            'update_due' => 'أبلغ عن «:activity» لشهر :month ضمن «:subject» قبل نهاية الشهر',
            'overdue' => 'لم يُبلَّغ عن «:activity» لشهر :month ضمن «:subject»',
            'awaiting_verification' => 'تقرير عن «:activity» لشهر :month ضمن «:subject» بانتظار التحقق',
        ],
```

```php
        'plan_update_due' => 'تذكير مديري الخطط قبل نهاية الشهر بهذا العدد من أيام العمل',
        'plan_verification' => 'تنبيه المتابعة والتقييم عندما ينتظر تقرير الشهر هذا العدد من أيام العمل',
```

- [ ] **Step 5: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/Notifications/PlanTrackingScannerTest.php tests/Feature/LocalizationTest.php`, then `grep -rln "notification-settings" tests` and run the files it lists.
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add app/Notifications/Alerts/PlanTrackingScanner.php config/notifications.php "resources/views/pages/admin/⚡notification-settings.blade.php" lang/en/notifications.php lang/ar/notifications.php tests/Feature/Notifications/PlanTrackingScannerTest.php
```

---

### Task 22: Open final reviews automatically

**Files:**
- Create: `app/Console/Commands/OpenPlanFinalReviews.php`
- Modify: `routes/console.php`, `lang/{en,ar}/annual_plans.php`
- Test: `tests/Feature/AnnualPlans/OpenPlanFinalReviewsCommandTest.php`

**Interfaces:**
- Consumes: `OpenFinalReview::handle($plan, authorize: false)`.
- Produces: command `annual-plans:open-final-reviews`, scheduled daily at 05:30 (before the 06:00 daily scan).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\PlanStatus;
use App\Models\AnnualPlan;
use Carbon\CarbonImmutable;

test('active plans past their last month move to final review, others stay', function () {
    CarbonImmutable::setTestNow('2028-01-01 05:30');
    $ended = AnnualPlan::factory()->between('2027-01', '2027-12')->active()->create();
    $running = AnnualPlan::factory()->between('2027-07', '2028-06')->active()->create();
    $draft = AnnualPlan::factory()->between('2027-01', '2027-06')->create();

    $this->artisan('annual-plans:open-final-reviews')->assertSuccessful();

    expect($ended->fresh()->status)->toBe(PlanStatus::FinalReview)
        ->and($running->fresh()->status)->toBe(PlanStatus::Active)
        ->and($draft->fresh()->status)->toBe(PlanStatus::Draft);

    CarbonImmutable::setTestNow();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/OpenPlanFinalReviewsCommandTest.php`
Expected: FAIL, command not defined.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\AnnualPlans\OpenFinalReview;
use App\Enums\PlanStatus;
use App\Models\AnnualPlan;
use Illuminate\Console\Command;

/**
 * Moves every active plan whose last month has ended into the final review
 * (D8). Runs as the system: the journal row carries no user.
 */
class OpenPlanFinalReviews extends Command
{
    /**
     * @var string
     */
    protected $signature = 'annual-plans:open-final-reviews';

    /**
     * @var string
     */
    protected $description = 'Open the final review of active plans whose period has ended';

    public function handle(OpenFinalReview $openFinalReview): int
    {
        $opened = 0;

        AnnualPlan::query()
            ->where('status', PlanStatus::Active->value)
            ->where('ends_on', '<', now()->toDateString())
            ->orderBy('id')
            ->each(function (AnnualPlan $plan) use ($openFinalReview, &$opened): void {
                $openFinalReview->handle($plan, authorize: false);
                $opened++;
            });

        $this->info(__('annual_plans.final_reviews_opened', ['count' => $opened]));

        return self::SUCCESS;
    }
}
```

`routes/console.php`, after the `notifications:scan daily` line:

```php
Schedule::command('annual-plans:open-final-reviews')->dailyAt('05:30')->withoutOverlapping();
```

Lang: en `'final_reviews_opened' => 'Final reviews opened: :count',`, ar `'final_reviews_opened' => 'المراجعات النهائية التي فُتحت: :count',`

- [ ] **Step 4: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/OpenPlanFinalReviewsCommandTest.php`
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add app/Console/Commands/OpenPlanFinalReviews.php routes/console.php lang/en/annual_plans.php lang/ar/annual_plans.php tests/Feature/AnnualPlans/OpenPlanFinalReviewsCommandTest.php
```

---

# Phase D: Screens

> Before every task in this phase, read `.ai/rules/pages.md`, `ui.md`, `views.md`, `views-enums.md`, `css.md`, `annual-plans.md`, `components-ui.md`, and `.ai/rules/boost/*` if present. Load the `fluxui-development`, `livewire-development` and `tailwindcss-development` skills. Design direction comes from the approved mockup and the saved feedback: **no stat-tile grids or uniform bordered boxes as the primary structure.** Use typographic figures and dot-thread motifs, and match the mockup's grid.

### Task 23: Grid, mark, rail and legend components + stylesheet

**Files:**
- Create: `resources/css/plans.css`, `resources/views/components/plans/mark.blade.php`, `resources/views/components/plans/phase-rail.blade.php`, `resources/views/components/plans/legend.blade.php`, `resources/views/components/plans/month-grid.blade.php`
- Modify: `resources/css/app.css` (import), `lang/{en,ar}/annual_plans.php`
- Test: `tests/Feature/AnnualPlans/PlanComponentsTest.php`

**Interfaces:**
- Consumes: the `PlanBoard::for()` array shape (Task 20).
- Produces:
  - `<x-plans.mark :outcome="PlanCellOutcome" :moved-in="bool" />`
  - `<x-plans.phase-rail :status="PlanStatus" :returns="int" />`
  - `<x-plans.legend />`
  - `<x-plans.month-grid :months :rows mode="planned|actual|review" :editable="bool" :selected="?string" :can-set-targets="bool" />`. Its wire calls:
    - planned: `toggleMonth(int $activityId, string $monthKey)`, `openActivityModal(int $activityId)`, `removeActivity(int $activityId)`
    - actual: `selectCell(int $activityId, string $monthKey, ?int $projectId)`
    - both: `openTargetModal(int $activityId)` when `can-set-targets`
  - `selected` is `"{activityId}:{monthKey}:{projectId|0}"`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\PlanCellOutcome;
use App\Enums\PlanStatus;
use App\Models\AnnualPlan;
use App\Models\PlanActivity;
use App\Support\Plans\PlanBoard;
use Carbon\CarbonImmutable;

test('a missed mark is a cross and every mark names its outcome', function () {
    $this->blade('<x-plans.mark :outcome="$outcome" />', ['outcome' => PlanCellOutcome::Missed])
        ->assertSee('data-outcome="missed"', false)
        ->assertSee('plan-mark--missed', false)
        ->assertSee('✕', false);
});

test('the rail marks the current phase and counts returns', function () {
    $this->blade('<x-plans.phase-rail :status="$status" :returns="2" />', ['status' => PlanStatus::Draft])
        ->assertSee('aria-current="step"', false)
        ->assertSeeText(trans_choice('annual_plans.returned_times', 2, ['count' => 2]));
});

test('an editable planned grid renders toggle buttons with pressed state', function () {
    $plan = AnnualPlan::factory()->between('2027-01', '2027-03')->create();
    PlanActivity::factory()->inMonths('2027-02')->create(['annual_plan_id' => $plan->id]);
    $board = app(PlanBoard::class)->for($plan, CarbonImmutable::parse('2027-01-10'));

    $this->blade('<x-plans.month-grid :months="$board[\'months\']" :rows="$board[\'rows\']" mode="planned" editable />', ['board' => $board])
        ->assertSee("toggleMonth({$board['rows'][0]['id']}, '2027-02')", false)
        ->assertSee('aria-pressed="true"', false)
        ->assertSee('aria-pressed="false"', false);
});

test('a read-only grid renders no buttons', function () {
    $plan = AnnualPlan::factory()->between('2027-01', '2027-03')->submitted()->create();
    PlanActivity::factory()->inMonths('2027-02')->create(['annual_plan_id' => $plan->id]);
    $board = app(PlanBoard::class)->for($plan, CarbonImmutable::parse('2027-01-10'));

    $this->blade('<x-plans.month-grid :months="$board[\'months\']" :rows="$board[\'rows\']" mode="planned" />', ['board' => $board])
        ->assertDontSee('toggleMonth', false);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanComponentsTest.php`
Expected: FAIL, component not found.

- [ ] **Step 3: Stylesheet**

`resources/css/plans.css` (add `@import './plans.css';` after `./data-viz.css` in `app.css`):

```css
/*
 * Annual plan workspace: the activity x month grid, its cell marks and the
 * phase rail. Marks differ in SHAPE as well as colour (ring, half, filled,
 * dashed, cross, diamond, arrow) so no state relies on colour alone.
 * Light rules use the approved palette; dark overrides use Graphite tokens.
 */
@layer components {
    .plan-grid {
        min-width: calc(14rem + var(--plan-months) * 2.75rem + 9rem);
    }

    .plan-grid__row {
        display: grid;
        grid-template-columns: minmax(14rem, 1.6fr) repeat(var(--plan-months), minmax(2.75rem, 1fr)) minmax(9rem, 1fr);
        align-items: center;
        border-bottom: 1px solid var(--color-board-line);
    }

    .plan-grid__row--head {
        border-bottom: 1.5px solid var(--color-secondary-dark);
        padding-bottom: 0.5rem;
    }

    .plan-grid__row--sub {
        background: var(--color-board-head);
    }

    .plan-grid__month {
        text-align: center;
    }

    .plan-grid__month--current {
        color: var(--color-status-critical);
    }

    .plan-cell {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 2.75rem;
        border: 0;
        background: transparent;
        padding: 0;
    }

    button.plan-cell {
        cursor: pointer;
    }

    button.plan-cell:focus-visible {
        outline: 2px solid var(--color-primary);
        outline-offset: -4px;
        border-radius: 0.5rem;
    }

    button.plan-cell:hover .plan-mark {
        box-shadow: 0 0 0 6px color-mix(in oklab, var(--color-secondary-dark) 10%, transparent);
    }

    .plan-cell--current {
        background: color-mix(in oklab, var(--color-primary) 6%, transparent);
    }

    .plan-cell--selected .plan-mark {
        box-shadow: 0 0 0 6px color-mix(in oklab, var(--color-status-active) 28%, transparent);
    }

    /* The dot-thread: consecutive planned months join into one line. */
    .plan-cell[data-thread-before]::before,
    .plan-cell[data-thread-after]::after {
        content: '';
        position: absolute;
        top: 50%;
        height: 2px;
        margin-top: -1px;
        background: var(--color-secondary-light);
    }

    .plan-cell[data-thread-before]::before {
        inset-inline-start: 0;
        inset-inline-end: 50%;
    }

    .plan-cell[data-thread-after]::after {
        inset-inline-start: 50%;
        inset-inline-end: 0;
    }

    .plan-mark {
        position: relative;
        z-index: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        box-sizing: border-box;
        width: 1.125rem;
        height: 1.125rem;
        border-radius: 9999px;
        font-size: 0.8125rem;
        font-weight: 700;
        line-height: 1;
    }

    .plan-mark--none {
        width: 0.375rem;
        height: 0.375rem;
        background: var(--color-secondary-soft);
    }

    .plan-mark--upcoming {
        border: 2.5px solid var(--color-secondary-dark);
        background: var(--color-board);
    }

    .plan-mark--due {
        border: 2.5px solid var(--color-status-critical);
        background: var(--color-board);
    }

    .plan-mark--pending {
        border: 2.5px solid var(--color-status-good);
        background: linear-gradient(to right, var(--color-status-good) 50%, var(--color-board) 50%);
    }

    [dir='rtl'] .plan-mark--pending {
        background: linear-gradient(to left, var(--color-status-good) 50%, var(--color-board) 50%);
    }

    .plan-mark--on-plan {
        background: var(--color-status-good);
    }

    .plan-mark--shifted {
        background: var(--color-status-active);
        color: var(--color-board);
    }

    .plan-mark--postponed {
        border: 2.5px dashed var(--color-status-warn);
        background: var(--color-board);
    }

    .plan-mark--missed {
        border-radius: 0;
        color: var(--color-status-critical);
        font-size: 1rem;
    }

    .plan-mark--unplanned {
        width: 0.9375rem;
        height: 0.9375rem;
        border-radius: 2px;
        transform: rotate(45deg);
        background: var(--color-funding);
    }

    .plan-mark--moved-in {
        box-shadow: 0 0 0 2px var(--color-board), 0 0 0 4px var(--color-status-active);
    }

    /* Phase rail: five dots on one thread; the current phase is the brand dot. */
    .plan-rail {
        display: flex;
        margin: 0;
        padding: 0;
        list-style: none;
    }

    .plan-rail__step {
        position: relative;
        flex: 1 1 0;
        min-width: 5.5rem;
        padding-top: 1.625rem;
        padding-inline-end: 0.75rem;
    }

    .plan-rail__step::before {
        content: '';
        position: absolute;
        top: 0.4375rem;
        inset-inline: 0;
        height: 2px;
        background: var(--color-secondary-soft);
    }

    .plan-rail__step--past::before {
        background: var(--color-secondary-dark);
    }

    .plan-rail__dot {
        position: absolute;
        top: 0;
        inset-inline-start: 0;
        width: 1rem;
        height: 1rem;
        border-radius: 9999px;
        box-sizing: border-box;
        border: 2px solid var(--color-secondary-light);
        background: var(--color-board);
    }

    .plan-rail__step--past .plan-rail__dot {
        border-color: var(--color-secondary-dark);
        background: var(--color-secondary-dark);
    }

    .plan-rail__step--now .plan-rail__dot {
        border-color: var(--color-primary);
        background: var(--color-primary);
        box-shadow: 0 0 0 5px color-mix(in oklab, var(--color-primary) 16%, transparent);
    }

    .plan-rail__label {
        display: block;
        font-size: 0.875rem;
        font-weight: 600;
    }

    .plan-rail__step--next .plan-rail__label {
        font-weight: 500;
        color: var(--color-secondary-medium);
    }

    .plan-rail__note {
        display: block;
        font-size: 0.75rem;
        color: var(--color-status-critical);
    }
}

/* Dark: Graphite tokens only (css.md). Light mode stays pixel-identical. */
@layer components {
    .dark .plan-grid__row--head {
        border-bottom-color: var(--color-line-strong);
    }

    .dark .plan-cell[data-thread-before]::before,
    .dark .plan-cell[data-thread-after]::after {
        background: var(--color-line-strong);
    }

    .dark .plan-mark--none {
        background: var(--color-line-strong);
    }

    .dark .plan-mark--upcoming {
        border-color: var(--color-ink);
    }

    .dark .plan-rail__step::before {
        background: var(--color-line);
    }

    .dark .plan-rail__step--past::before,
    .dark .plan-rail__step--past .plan-rail__dot {
        border-color: var(--color-ink);
        background: var(--color-ink);
    }

    .dark .plan-rail__step--now .plan-rail__dot {
        border-color: var(--color-brand);
        background: var(--color-brand);
    }

    .dark .plan-rail__step--next .plan-rail__label {
        color: var(--color-ink-muted);
    }

    .dark .plan-rail__dot {
        border-color: var(--color-line-strong);
    }
}
```

- [ ] **Step 4: Components**

`resources/views/components/plans/mark.blade.php`:

```blade
@props(['outcome', 'movedIn' => false])

<span
    {{ $attributes->class(['plan-mark', 'plan-mark--'.$outcome->cssModifier(), 'plan-mark--moved-in' => $movedIn]) }}
    data-outcome="{{ $outcome->value }}"
    aria-hidden="true"
>@if ($outcome === \App\Enums\PlanCellOutcome::Missed)✕@elseif ($outcome === \App\Enums\PlanCellOutcome::Shifted)→@endif</span>
```

`resources/views/components/plans/phase-rail.blade.php`:

```blade
@props(['status', 'returns' => 0])

@php
    $steps = [
        \App\Enums\PlanStatus::Draft,
        \App\Enums\PlanStatus::Submitted,
        \App\Enums\PlanStatus::Active,
        \App\Enums\PlanStatus::FinalReview,
        \App\Enums\PlanStatus::Closed,
    ];
    $current = $status->railIndex();
@endphp

<div {{ $attributes->class('flex flex-col gap-2') }} data-plan-rail data-status="{{ $status->value }}">
    <ol class="plan-rail" aria-label="{{ __('annual_plans.plan_phases') }}">
        @foreach ($steps as $index => $step)
            @php
                $state = $current === null ? 'next' : ($index < $current ? 'past' : ($index === $current ? 'now' : 'next'));
            @endphp
            <li class="plan-rail__step plan-rail__step--{{ $state }}" @if ($state === 'now') aria-current="step" @endif>
                <span class="plan-rail__dot" aria-hidden="true"></span>
                <span class="plan-rail__label">{{ $step->label() }}</span>
                @if ($step === \App\Enums\PlanStatus::Draft && $returns > 0)
                    <span class="plan-rail__note">{{ trans_choice('annual_plans.returned_times', $returns, ['count' => $returns]) }}</span>
                @endif
            </li>
        @endforeach
    </ol>

    @if ($status === \App\Enums\PlanStatus::Cancelled)
        <div><x-ui.enum-badge :case="$status" /></div>
    @endif
</div>
```

`resources/views/components/plans/legend.blade.php`:

```blade
@php
    $items = [
        \App\Enums\PlanCellOutcome::Upcoming,
        \App\Enums\PlanCellOutcome::Due,
        \App\Enums\PlanCellOutcome::Pending,
        \App\Enums\PlanCellOutcome::OnPlan,
        \App\Enums\PlanCellOutcome::Postponed,
        \App\Enums\PlanCellOutcome::Shifted,
        \App\Enums\PlanCellOutcome::Missed,
        \App\Enums\PlanCellOutcome::Unplanned,
    ];
@endphp

<ul {{ $attributes->class('flex flex-wrap gap-x-5 gap-y-2 text-[13px] text-secondary-medium dark:text-ink-muted') }} aria-label="{{ __('annual_plans.legend') }}">
    @foreach ($items as $item)
        <li class="inline-flex items-center gap-2">
            <span class="inline-flex size-5 items-center justify-center"><x-plans.mark :outcome="$item" /></span>
            {{ $item->label() }}
        </li>
    @endforeach
</ul>
```

`resources/views/components/plans/month-grid.blade.php`:

```blade
@props([
    'months',
    'rows',
    'mode' => 'actual',
    'editable' => false,
    'selected' => null,
    'canSetTargets' => false,
])

@php
    $planned = \App\Enums\PlanCellOutcome::Upcoming;
    $empty = \App\Enums\PlanCellOutcome::None;
    $monthLabels = array_column($months, 'label', 'key');
@endphp

<div {{ $attributes->class('overflow-x-auto [scrollbar-width:thin]') }}>
    <div role="table" class="plan-grid" style="--plan-months: {{ count($months) }}" data-plan-grid data-mode="{{ $mode }}">
        <div role="row" class="plan-grid__row plan-grid__row--head">
            <div role="columnheader" class="type-label text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.activity') }}</div>
            @foreach ($months as $month)
                <div role="columnheader" wire:key="grid-month-{{ $month['key'] }}" @class(['plan-grid__month type-label', 'plan-grid__month--current' => $month['isCurrent']])>{{ $month['label'] }}</div>
            @endforeach
            <div role="columnheader" class="ps-4 type-label text-secondary-medium dark:text-ink-muted">{{ $mode === 'review' ? __('annual_plans.verdict') : __('annual_plans.targets_and_milestones') }}</div>
        </div>

        @forelse ($rows as $row)
            <div wire:key="grid-row-{{ $row['id'] }}" x-data="{ open: false }">
                @foreach ($mode === 'review' ? ['planned', 'actual'] : [$mode] as $lane)
                    <div role="row" class="plan-grid__row">
                        <div role="rowheader" class="flex min-w-0 items-center gap-2 py-2 pe-3">
                            @if ($lane === 'actual' && $mode === 'review')
                                <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.actual') }}</span>
                            @else
                                @if ($row['projects'] !== [] && $mode === 'actual')
                                    <button type="button" class="inline-flex size-8 shrink-0 items-center justify-center rounded-md hover:bg-secondary-soft dark:hover:bg-white/[0.06]" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-label="{{ __('annual_plans.show_each_project_for', ['activity' => $row['name']]) }}">
                                        <flux:icon.chevron-right class="size-4 transition-transform rtl:rotate-180" x-bind:class="open && 'rotate-90 rtl:rotate-90'" />
                                    </button>
                                @endif
                                <div class="min-w-0">
                                    <p class="truncate text-[15px] font-semibold">{{ $row['name'] }}</p>
                                    <p class="truncate type-meta text-secondary-medium dark:text-ink-muted">
                                        @if ($row['isUnplanned'])
                                            <span class="font-semibold text-funding">{{ __('annual_plans.unplanned') }}</span> ·
                                        @endif
                                        {{ $row['responsibleName'] ?? __('common.not_assigned') }}
                                        · {{ trans_choice('annual_plans.count_planned_months', $row['plannedCount'], ['count' => $row['plannedCount']]) }}
                                        @if ($row['tracking'] === \App\Enums\PlanActivityTracking::PerProject)
                                            · {{ $row['tracking']->label() }}
                                        @endif
                                    </p>
                                </div>
                                @if ($editable && $mode === 'planned')
                                    <span class="ms-auto inline-flex shrink-0 items-center">
                                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openActivityModal({{ $row['id'] }})" :aria-label="__('annual_plans.edit_activity', ['activity' => $row['name']])" />
                                        <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9!" wire:click="removeActivity({{ $row['id'] }})" wire:confirm="{{ __('annual_plans.remove_activity_confirm', ['activity' => $row['name']]) }}" :aria-label="__('annual_plans.remove_activity', ['activity' => $row['name']])" />
                                    </span>
                                @endif
                            @endif
                        </div>

                        @foreach ($row['cells'] as $cell)
                            @php
                                $outcome = $lane === 'planned' ? ($cell['planned'] ? $planned : $empty) : $cell['outcome'];
                                $label = __('annual_plans.cell_label', ['activity' => $row['name'], 'month' => $monthLabels[$cell['key']] ?? $cell['key'], 'state' => $lane === 'planned' ? ($cell['planned'] ? __('annual_plans.planned') : __('annual_plans.not_planned')) : $cell['outcome']->label()]);
                                $cellKey = $row['id'].':'.$cell['key'].':0';
                                $classes = ['plan-cell', 'plan-cell--current' => $cell['isCurrent'], 'plan-cell--selected' => $selected === $cellKey];
                                $clickable = ($mode === 'planned' && $editable) || ($mode === 'actual' && $row['projects'] === []);
                            @endphp
                            @if ($clickable && $mode === 'planned')
                                <button type="button" role="cell" @class($classes) wire:key="cell-{{ $cellKey }}" wire:click="toggleMonth({{ $row['id'] }}, '{{ $cell['key'] }}')" aria-pressed="{{ $cell['planned'] ? 'true' : 'false' }}" aria-label="{{ $label }}" @if ($cell['threadBefore']) data-thread-before @endif @if ($cell['threadAfter']) data-thread-after @endif>
                                    <x-plans.mark :outcome="$outcome" />
                                </button>
                            @elseif ($clickable)
                                <button type="button" role="cell" @class($classes) wire:key="cell-{{ $cellKey }}" wire:click="selectCell({{ $row['id'] }}, '{{ $cell['key'] }}', null)" aria-label="{{ $label }}" @if ($cell['threadBefore']) data-thread-before @endif @if ($cell['threadAfter']) data-thread-after @endif>
                                    <x-plans.mark :outcome="$outcome" :moved-in="$cell['movedIn']" />
                                </button>
                            @else
                                <span role="cell" @class($classes) wire:key="cell-{{ $lane }}-{{ $cellKey }}" title="{{ $label }}" @if ($cell['threadBefore'] && $lane !== 'actual') data-thread-before @endif @if ($cell['threadAfter'] && $lane !== 'actual') data-thread-after @endif>
                                    <x-plans.mark :outcome="$outcome" :moved-in="$lane === 'actual' && $cell['movedIn']" />
                                    <span class="sr-only">{{ $label }}</span>
                                </span>
                            @endif
                        @endforeach

                        <div role="cell" class="flex flex-wrap items-center gap-2 ps-4 py-2">
                            @if ($mode === 'review')
                                @if ($lane === 'planned')
                                    <span @class([
                                        'text-[13px] font-semibold',
                                        'text-status-good' => $row['verdict']['key'] === 'matches_plan',
                                        'text-status-warn' => $row['verdict']['key'] === 'partly',
                                        'text-funding' => $row['verdict']['key'] === 'not_in_plan',
                                    ])>{{ __('annual_plans.verdict_'.$row['verdict']['key'], ['on' => $row['verdict']['onPlan'], 'of' => $row['verdict']['planned']]) }}</span>
                                @endif
                            @else
                                <span class="type-meta text-secondary-medium dark:text-ink-muted">
                                    {{ trans_choice('common.count_target', $row['targetCount'], ['count' => $row['targetCount']]) }}
                                    @if ($row['milestoneCount'] > 0)
                                        · {{ trans_choice('annual_plans.count_milestone', $row['milestoneCount'], ['count' => $row['milestoneCount']]) }}
                                    @endif
                                </span>
                                @if ($canSetTargets)
                                    <x-ui.button size="sm" tone="secondary" icon="flag" wire:click="openTargetModal({{ $row['id'] }})">{{ __('annual_plans.add_target') }}</x-ui.button>
                                @endif
                            @endif
                        </div>
                    </div>
                @endforeach

                @if ($mode === 'actual')
                    @foreach ($row['projects'] as $project)
                        <div role="row" class="plan-grid__row plan-grid__row--sub" x-show="open" x-cloak wire:key="grid-row-{{ $row['id'] }}-project-{{ $project['id'] }}">
                            <div role="rowheader" class="truncate ps-10 py-2 text-[13.5px] text-secondary-medium dark:text-ink-muted">{{ $project['name'] }}</div>
                            @foreach ($project['cells'] as $cell)
                                @php
                                    $cellKey = $row['id'].':'.$cell['key'].':'.$project['id'];
                                    $label = __('annual_plans.cell_label_project', ['activity' => $row['name'], 'project' => $project['name'], 'month' => $monthLabels[$cell['key']] ?? $cell['key'], 'state' => $cell['outcome']->label()]);
                                @endphp
                                <button type="button" role="cell" @class(['plan-cell', 'plan-cell--current' => $cell['isCurrent'], 'plan-cell--selected' => $selected === $cellKey]) wire:key="cell-{{ $cellKey }}" wire:click="selectCell({{ $row['id'] }}, '{{ $cell['key'] }}', {{ $project['id'] }})" aria-label="{{ $label }}">
                                    <x-plans.mark :outcome="$cell['outcome']" :moved-in="$cell['movedIn']" />
                                </button>
                            @endforeach
                            <div role="cell"></div>
                        </div>
                    @endforeach
                @endif
            </div>
        @empty
            <div class="py-10">
                <x-ui.empty-state icon="calendar-days" :heading="__('annual_plans.no_activities_yet')" :body="$editable ? __('annual_plans.add_the_first_activity_from_the_catalog') : __('annual_plans.the_plan_has_no_activities')" />
            </div>
        @endforelse
    </div>
</div>
```

> In the actual lane, the thread is only drawn on the **planned** lane (review) and the planned grid. On the tracking grid it is drawn for planned months too. Keep `data-thread-*` on the tracking buttons as written, and drop it only on the review's actual lane (`$lane !== 'actual'` in the read-only branch).

- [ ] **Step 5: Lang keys used above** (en / ar)

```php
    'activity' => 'Activity',
    'actual' => 'Actual',
    'add_target' => 'Target',
    'add_the_first_activity_from_the_catalog' => 'Add the first activity from the catalog, then click the months it happens in.',
    'cell_label' => ':activity, :month: :state',
    'cell_label_project' => ':activity, :project, :month: :state',
    'count_milestone' => '{1} :count milestone|[2,*] :count milestones',
    'count_planned_months' => '{0} no planned months|{1} :count planned month|[2,*] :count planned months',
    'edit_activity' => 'Edit :activity',
    'legend' => 'What the marks mean',
    'no_activities_yet' => 'No activities yet',
    'not_planned' => 'Not planned',
    'plan_phases' => 'Plan phases',
    'planned' => 'Planned',
    'remove_activity' => 'Remove :activity',
    'remove_activity_confirm' => 'Remove “:activity” and its planned months from this draft?',
    'returned_times' => '{1} Returned once|[2,*] Returned :count times',
    'show_each_project_for' => 'Show each project for :activity',
    'targets_and_milestones' => 'Targets & milestones',
    'the_plan_has_no_activities' => 'This plan has no activities.',
    'unplanned' => 'Unplanned',
    'verdict' => 'Verdict',
    'verdict_matches_plan' => 'Matches the plan',
    'verdict_not_in_plan' => 'Not in the plan',
    'verdict_partly' => ':on of :of as planned',
```

```php
    'activity' => 'النشاط',
    'actual' => 'الفعلي',
    'add_target' => 'مستهدف',
    'add_the_first_activity_from_the_catalog' => 'أضف النشاط الأول من القائمة، ثم انقر على الأشهر التي يُنفَّذ فيها.',
    'cell_label' => ':activity، :month: :state',
    'cell_label_project' => ':activity، :project، :month: :state',
    'count_milestone' => '{1} معلم واحد|{2} معلمان|[3,10] :count معالم|[11,*] :count معلمًا',
    'count_planned_months' => '{0} لا أشهر مخططة|{1} شهر مخطط واحد|{2} شهران مخططان|[3,10] :count أشهر مخططة|[11,*] :count شهرًا مخططًا',
    'edit_activity' => 'تعديل :activity',
    'legend' => 'دلالة العلامات',
    'no_activities_yet' => 'لا توجد أنشطة بعد',
    'not_planned' => 'غير مخطط',
    'plan_phases' => 'مراحل الخطة',
    'planned' => 'مخطط',
    'remove_activity' => 'إزالة :activity',
    'remove_activity_confirm' => 'هل تريد إزالة «:activity» وأشهره المخططة من هذه المسودة؟',
    'returned_times' => '{1} أُعيدت مرة واحدة|{2} أُعيدت مرتين|[3,10] أُعيدت :count مرات|[11,*] أُعيدت :count مرة',
    'show_each_project_for' => 'عرض كل مشروع لـ :activity',
    'targets_and_milestones' => 'المستهدفات والمعالم',
    'the_plan_has_no_activities' => 'لا تحتوي هذه الخطة على أنشطة.',
    'unplanned' => 'غير مخطط',
    'verdict' => 'الحكم',
    'verdict_matches_plan' => 'مطابق للخطة',
    'verdict_not_in_plan' => 'خارج الخطة',
    'verdict_partly' => ':on من :of حسب الخطة',
```

Check that `text-funding` exists as a utility. `--color-funding` is in `@theme`, so Tailwind generates it.

- [ ] **Step 6: Run, build, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanComponentsTest.php tests/Feature/LocalizationTest.php`
Expected: PASS.
Then ask the user to run `npm run build` (or keep `npm run dev` running), since the new CSS partial must be compiled.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add resources/css/plans.css resources/css/app.css resources/views/components/plans lang/en/annual_plans.php lang/ar/annual_plans.php tests/Feature/AnnualPlans/PlanComponentsTest.php
```

---

### Task 24: The matrix overview (index): month periods, scopes, statuses

**Files:**
- Modify: `resources/views/pages/annual-plans/⚡index.blade.php`, `tests/Feature/AnnualPlanCrudTest.php` (**only after the user approves D17**), `tests/Feature/AnnualPlanMatrixTest.php`, `tests/Feature/AccessControl/BrowserCallableMethodsTest.php`, `lang/{en,ar}/annual_plans.php`
- Test: `tests/Feature/AnnualPlans/PlanMatrixFormTest.php`

**Interfaces:**
- Consumes: `SavePlanDetails`, `AnnualPlan` scopes and labels.
- Produces (component members):
  - properties `planScope` (`'program'|'project'`), `planProjectId`, `planStartMonth`, `planEndMonth`
  - `#[Computed] matrixPlans()` (the one read), with `planCells()` (`array<string, list<AnnualPlan>>`, program-wide only) and `projectPlanCounts()` (`array<string, int>`) derived from it
  - protected `planFor()` (first program-wide plan), protected `plansFor()`
  - `#[Computed] projectOptions()`
  - `updatedPlanProgramId()`, `updatedPlanProjectId()`
  - `planYear` and `yearFormOptions()` are removed

- [ ] **Step 1: Write the failing test**

`tests/Feature/AnnualPlans/PlanMatrixFormTest.php`:

```php
<?php

use App\Enums\PlanStatus;
use App\Models\AnnualPlan;
use App\Models\Program;
use App\Models\Project;
use App\Models\Strategy;
use Livewire\Livewire;

function matrixProgram(): Program
{
    return Program::factory()->create(['strategy_id' => Strategy::factory()]);
}

test('a nine-month program plan is created from the matrix', function () {
    $program = matrixProgram();
    $manager = insertHrEmployee();

    Livewire::actingAs(userWithRole('Managers'))
        ->test('pages::annual-plans.index')
        ->call('openCreateModal', $program->id, 2027)
        ->assertSet('planStartMonth', '2027-01')
        ->assertSet('planEndMonth', '2027-12')
        ->set('planManagerId', (string) $manager)
        ->set('planStartMonth', '2027-04')
        ->call('savePlan')
        ->assertHasNoErrors()
        ->assertSet('showPlanModal', false);

    $plan = AnnualPlan::query()->where('program_id', $program->id)->sole();

    expect($plan->starts_on->toDateString())->toBe('2027-04-01')
        ->and($plan->status)->toBe(PlanStatus::Draft);
});

test('choosing a project preselects its owner as manager', function () {
    $owner = insertHrEmployee();
    $project = Project::factory()->create(['user_id' => $owner]);

    Livewire::actingAs(userWithRole('Managers'))
        ->test('pages::annual-plans.index')
        ->call('openCreateModal', $project->program_id, 2027)
        ->set('planScope', 'project')
        ->set('planProjectId', (string) $project->id)
        ->assertSet('planManagerId', (string) $owner);
});

test('an overlapping program plan is refused on the start month', function () {
    $program = matrixProgram();
    AnnualPlan::factory()->between('2027-01', '2027-12')->create(['program_id' => $program->id]);

    Livewire::actingAs(userWithRole('Managers'))
        ->test('pages::annual-plans.index')
        ->call('openCreateModal', $program->id, 2027)
        ->set('planManagerId', (string) insertHrEmployee())
        ->call('savePlan')
        ->assertHasErrors(['planStartMonth']);
});

test('a cell shows the plan status and counts the project plans beside it', function () {
    $program = matrixProgram();
    AnnualPlan::factory()->between('2027-01', '2027-12')->active()->create(['program_id' => $program->id]);
    AnnualPlan::factory()->forProject(Project::factory()->create(['program_id' => $program->id]))->between('2027-01', '2027-06')->create();

    Livewire::actingAs(userWithRole('General Manager'))
        ->test('pages::annual-plans.index', ['years' => [2027]])
        ->assertSeeText(PlanStatus::Active->label())
        ->assertSeeText(trans_choice('annual_plans.project_plans_count', 1, ['count' => 1]));
});

test('only a draft opens in the edit form', function () {
    $plan = AnnualPlan::factory()->active()->create();

    Livewire::actingAs(userWithRole('Managers'))
        ->test('pages::annual-plans.index')
        ->call('openEditModal', $plan->id)
        ->assertForbidden();
});
```

> `->test('pages::annual-plans.index', ['years' => [2027]])` relies on `years` being a `#[Url]` property set on mount. If mount ignores passed params, use `->set('years', [2027])` instead.

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanMatrixFormTest.php`
Expected: FAIL.

- [ ] **Step 3: Component (PHP) changes in `⚡index.blade.php`**

1. Replace `public string $planYear = '';` with:

```php
    public string $planScope = 'program';

    public string $planProjectId = '';

    public string $planStartMonth = '';

    public string $planEndMonth = '';
```

2. Replace `planCells()` with three computeds (one query, two derivations). Keep the docblocks' reasoning about the four reads:

```php
    /**
     * Every plan of the visible rows and years, program-wide and project.
     *
     * The single read of annual_plans that fills the grid (AnnualPlanMatrixTest
     * still counts four reads in all). planCells() and projectPlanCounts() are
     * derived from it in PHP.
     *
     * @return Collection<int, AnnualPlan>
     */
    #[Computed]
    public function matrixPlans(): Collection
    {
        $columns = $this->yearColumns;
        $programIds = $this->programRows->getCollection()->modelKeys();

        if ($columns === [] || $programIds === []) {
            return new Collection;
        }

        return AnnualPlan::query()
            ->select(['id', 'slug', 'program_id', 'project_id', 'manager_id', 'year', 'starts_on', 'ends_on', 'status'])
            ->whereIn('program_id', $programIds)
            ->inYears($columns)
            ->managedBy($this->selectedManagerId())
            ->with('manager:id,first_name_en,last_name_en,first_name_ar,last_name_ar')
            ->withCount('planIndicators')
            ->orderBy('starts_on')
            ->get();
    }

    /**
     * Program-wide plans keyed "programId:year", earliest first.
     *
     * @return array<string, list<AnnualPlan>>
     */
    #[Computed]
    public function planCells(): array
    {
        return $this->matrixPlans
            ->whereNull('project_id')
            ->groupBy(fn (AnnualPlan $plan): string => $plan->program_id.':'.$plan->year)
            ->map(fn ($plans): array => array_values($plans->all()))
            ->all();
    }

    /**
     * How many project plans sit in each "programId:year" cell.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function projectPlanCounts(): array
    {
        return $this->matrixPlans
            ->whereNotNull('project_id')
            ->countBy(fn (AnnualPlan $plan): string => $plan->program_id.':'.$plan->year)
            ->all();
    }
```

3. In `planDelivery()` replace the id source with `$planIds = $this->matrixPlans->whereNull('project_id')->modelKeys();`.

4. Replace `planFor()` and add `plansFor()` (both stay **protected**, per `BrowserCallableMethodsTest`):

```php
    /**
     * The first program-wide plan of one program in one fiscal year.
     */
    protected function planFor(int $programId, int $year): ?AnnualPlan
    {
        return $this->planCells[$programId.':'.$year][0] ?? null;
    }

    /**
     * Every program-wide plan of one program in one fiscal year.
     *
     * @return list<AnnualPlan>
     */
    protected function plansFor(int $programId, int $year): array
    {
        return $this->planCells[$programId.':'.$year] ?? [];
    }
```

5. Add project options and the owner preselect:

```php
    /**
     * Projects of the program chosen in the form.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projectOptions(): Collection
    {
        if ($this->planProgramId === '') {
            return new Collection;
        }

        return Project::query()
            ->select(['id', 'name', 'user_id', 'program_id'])
            ->where('program_id', (int) $this->planProgramId)
            ->orderBy('name')
            ->get();
    }

    public function updatedPlanProgramId(): void
    {
        $this->planProjectId = '';
        unset($this->projectOptions);
    }

    public function updatedPlanProjectId(): void
    {
        $ownerId = $this->planProjectId === '' ? null : Project::ownerEmployeeIdFor((int) $this->planProjectId);

        if ($ownerId !== null && $this->editingPlanId === null) {
            $this->planManagerId = (string) $ownerId;
        }
    }
```

(`use App\Models\Project;`)

6. `openCreateModal()`:

```php
    public function openCreateModal(?int $programId = null, ?int $year = null): void
    {
        Gate::authorize('create', AnnualPlan::class);

        $this->resetPlanForm();
        $this->planProgramId = $programId === null ? '' : (string) $programId;
        $year ??= (int) now()->year;
        $this->planStartMonth = $year.'-01';
        $this->planEndMonth = $year.'-12';
        $this->showPlanModal = true;
    }
```

7. `openEditModal()`:

```php
    public function openEditModal(int $planId): void
    {
        $plan = AnnualPlan::query()
            ->select(['id', 'program_id', 'project_id', 'manager_id', 'year', 'starts_on', 'ends_on', 'status'])
            ->findOrFail($planId);

        Gate::authorize('editDetails', $plan);

        $this->resetValidation();
        $this->editingPlanId = $plan->id;
        $this->planScope = $plan->project_id === null ? 'program' : 'project';
        $this->planProgramId = (string) $plan->program_id;
        $this->planProjectId = $plan->project_id === null ? '' : (string) $plan->project_id;
        $this->planManagerId = (string) $plan->manager_id;
        $this->planStartMonth = $plan->starts_on->format('Y-m');
        $this->planEndMonth = $plan->ends_on->format('Y-m');
        $this->showPlanModal = true;
    }
```

8. `savePlan()`:

```php
    /**
     * Write the plan through SavePlanDetails, which owns the period, overlap
     * and reassignment rules; this method only checks the form's shape.
     */
    public function savePlan(): void
    {
        $validated = $this->validate([
            'planScope' => ['required', Rule::in(['program', 'project'])],
            'planProgramId' => ['required', 'integer', Rule::exists(Program::class, 'id')],
            'planProjectId' => ['nullable', 'required_if:planScope,project', 'integer', Rule::exists(Project::class, 'id')],
            'planManagerId' => ['required', 'integer', Rule::exists(HrEmployee::class, 'id')],
            'planStartMonth' => ['required', 'date_format:Y-m'],
            'planEndMonth' => ['required', 'date_format:Y-m'],
        ], [
            'planProgramId.required' => __('annual_plans.select_the_program_this_plan_covers'),
            'planProgramId.exists' => __('annual_plans.the_selected_program_is_no_longer_available'),
            'planProjectId.required_if' => __('annual_plans.select_the_project_this_plan_covers'),
            'planManagerId.required' => __('annual_plans.select_the_manager_accountable_for_this_plan'),
            'planManagerId.exists' => __('annual_plans.the_selected_manager_is_no_longer_available'),
            'planStartMonth.required' => __('annual_plans.choose_the_months_this_plan_covers'),
            'planEndMonth.required' => __('annual_plans.choose_the_months_this_plan_covers'),
        ]);

        $existing = $this->editingPlanId === null ? null : AnnualPlan::query()->findOrFail($this->editingPlanId);

        $plan = app(SavePlanDetails::class)->handle($existing, [
            'programId' => (int) $validated['planProgramId'],
            'projectId' => $validated['planScope'] === 'project' ? (int) $validated['planProjectId'] : null,
            'managerId' => (int) $validated['planManagerId'],
            'startMonth' => $validated['planStartMonth'],
            'endMonth' => $validated['planEndMonth'],
        ]);

        $landedOutsideTheVisibleSpan = ! in_array($plan->year, $this->yearColumns, true);

        $this->showPlanModal = false;
        $this->resetPlanForm();

        if ($landedOutsideTheVisibleSpan) {
            $this->years = [];
            $this->focusYear = (string) $plan->year;
        }

        $this->refreshMatrix();

        Flux::toast(variant: 'success', text: $existing === null ? __('annual_plans.annual_plan_created') : __('annual_plans.annual_plan_updated'));
    }
```

(`use App\Actions\AnnualPlans\SavePlanDetails;`)

9. `refreshMatrix()`: remove `$this->yearFormOptions` and `$this->planCells` stays. Add `$this->matrixPlans, $this->projectPlanCounts, $this->projectOptions`. Delete the `yearFormOptions()` computed.

10. `resetPlanForm()`: reset `planScope = 'program'`, `planProjectId = ''`, `planStartMonth = ''`, `planEndMonth = ''`, and remove `planYear`.

11. The page header `description` of the modal: change the key to `annual_plans.one_scope_one_period_one_accountable_manager`.

- [ ] **Step 4: Blade changes in `⚡index.blade.php`**

1. **Modal body**: replace the whole `<div class="grid grid-cols-2 gap-x-4 gap-y-4">…</div>` inside `@if ($showPlanModal)` with:

```blade
<div class="grid grid-cols-2 gap-x-4 gap-y-4">
    <x-ui.form-segmented
        wire:model.live="planScope"
        :label="__('annual_plans.plan_scope')"
        :options="[['value' => 'program', 'label' => __('annual_plans.scope_program')], ['value' => 'project', 'label' => __('annual_plans.scope_project')]]"
        :value="$planScope"
        class="col-span-2"
    />

    <x-ui.form-select wire:model.live="planProgramId" :label="__('common.program')" :placeholder="__('annual_plans.select_a_program')" required class="col-span-2">
        @foreach ($this->programOptions as $programOption)
            <option value="{{ $programOption->id }}">{{ $programOption->name }}</option>
        @endforeach
    </x-ui.form-select>

    @if ($planScope === 'project')
        <x-ui.form-select wire:model.live="planProjectId" :label="__('common.project')" :placeholder="__('annual_plans.select_a_project')" required class="col-span-2" :disabled="$planProgramId === ''">
            @foreach ($this->projectOptions as $projectOption)
                <option value="{{ $projectOption->id }}">{{ $projectOption->name }}</option>
            @endforeach
        </x-ui.form-select>
    @endif

    <x-ui.form-input type="month" wire:model="planStartMonth" :label="__('annual_plans.start_month')" required />
    <x-ui.form-input type="month" wire:model="planEndMonth" :label="__('annual_plans.end_month')" required />

    <x-ui.form-select wire:model="planManagerId" :label="__('annual_plans.assigned_manager')" :placeholder="__('annual_plans.select_a_manager')" required class="col-span-2">
        @foreach ($this->employeeOptions as $employeeOption)
            <option value="{{ $employeeOption->id }}">{{ $employeeOption->name }} · {{ $employeeOption->secondary_name }}</option>
        @endforeach
    </x-ui.form-select>
</div>
```

(Read `x-ui.form-segmented`'s `options` shape before pasting. If it takes `value => label` pairs, adapt.)

2. **Cells (both layouts)**: in the mobile list item and the wide grid cell, where a plan exists, add under the year/target line:

```blade
<span class="flex flex-wrap items-center gap-1.5">
    <x-ui.enum-badge :case="$plan->status" />
    @if (count($this->plansFor($program->id, $year)) > 1)
        <span class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.plus_more_plans', ['count' => count($this->plansFor($program->id, $year)) - 1]) }}</span>
    @endif
    @php
        $projectPlans = $this->projectPlanCounts[$program->id.':'.$year] ?? 0;
    @endphp
    @if ($projectPlans > 0)
        <span class="type-meta text-secondary-medium dark:text-ink-muted">{{ trans_choice('annual_plans.project_plans_count', $projectPlans, ['count' => $projectPlans]) }}</span>
    @endif
</span>
```

In the **empty-cell** branch (no program-wide plan), add the same `$projectPlans` line beneath "Add plan" so project-only cells are not shown as empty.

3. The `@can('update', $plan)` edit buttons become `@can('editDetails', $plan)`. Delete stays `@can('delete', $plan)`, which now only shows for draft/cancelled plans.

4. The `aria-label` strings that say "year" stay. They read the cell's fiscal year, which is still correct.

- [ ] **Step 5: Lang keys** (en / ar)

```php
    'end_month' => 'End month',
    'one_scope_one_period_one_accountable_manager' => 'A program or one of its projects, the months it covers, and the manager who answers for it.',
    'plan_scope' => 'This plan covers',
    'plus_more_plans' => '+:count more',
    'project_plans_count' => '{1} :count project plan|[2,*] :count project plans',
    'scope_program' => 'The whole program',
    'scope_project' => 'One project',
    'select_a_project' => 'Select a project',
    'select_the_project_this_plan_covers' => 'Select the project this plan covers.',
    'start_month' => 'Start month',
```

```php
    'end_month' => 'شهر النهاية',
    'one_scope_one_period_one_accountable_manager' => 'برنامج أو أحد مشاريعه، والأشهر التي تغطيها الخطة، والمدير المسؤول عنها.',
    'plan_scope' => 'تغطي هذه الخطة',
    'plus_more_plans' => '+:count أخرى',
    'project_plans_count' => '{1} خطة مشروع واحدة|{2} خطتا مشروع|[3,10] :count خطط مشاريع|[11,*] :count خطة مشروع',
    'scope_program' => 'البرنامج كاملًا',
    'scope_project' => 'مشروع واحد',
    'select_a_project' => 'اختر مشروعًا',
    'select_the_project_this_plan_covers' => 'اختر المشروع الذي تغطيه هذه الخطة.',
    'start_month' => 'شهر البداية',
```

Remove the keys that only `planYear` used (`select_the_fiscal_year_this_plan_covers`, `this_program_already_has_a_plan_for_that_fiscal_year`, `fiscal_year` if unused elsewhere). Grep each key across `resources/` and `app/` before removing. Remove from **both** `en` and `ar`.

- [ ] **Step 6: Update the existing tests (after the user approves D17)**

- `tests/Feature/AnnualPlanCrudTest.php`: replace `->set('planYear', '2027')` flows with `->set('planStartMonth', '2027-01')->set('planEndMonth', '2027-12')`. Rewrite "a program cannot hold two plans for the same fiscal year" as "a program cannot hold two overlapping program-wide plans", asserting `->assertHasErrors(['planStartMonth'])`. The "clicking an empty cell carries its program and fiscal year" test now asserts `planStartMonth` `'2025-01'` and `planEndMonth` `'2025-12'`.
- `tests/Feature/AccessControl/BrowserCallableMethodsTest.php`: add the dataset row `'annual plan cell list' => ['pages::annual-plans.index', 'plansFor', [1, 2026]],`.
- `tests/Feature/AnnualPlanMatrixTest.php`: the "four reads" assertion must still pass unchanged. If it fails, an extra read crept in; fix the code, not the test.

- [ ] **Step 7: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanMatrixFormTest.php tests/Feature/AnnualPlanCrudTest.php tests/Feature/AnnualPlanMatrixTest.php tests/Feature/AccessControl/BrowserCallableMethodsTest.php tests/Feature/AccessControl/PlanningAuthorizationTest.php tests/Feature/LocalizationTest.php`
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add "resources/views/pages/annual-plans/⚡index.blade.php" lang/en/annual_plans.php lang/ar/annual_plans.php tests/Feature/AnnualPlans/PlanMatrixFormTest.php tests/Feature/AnnualPlanCrudTest.php tests/Feature/AccessControl/BrowserCallableMethodsTest.php
```

---

### Task 25: The plan workspace shell (show page): header, rail, tabs, overview, history

**Files:**
- Create: `resources/views/pages/annual-plans/_overview-tab.blade.php`, `resources/views/pages/annual-plans/_history-tab.blade.php`
- Modify: `resources/views/pages/annual-plans/⚡show.blade.php`, `lang/{en,ar}/annual_plans.php`, `tests/Feature/AnnualPlanDetailTest.php` (only if a heading changed; it should not)
- Test: `tests/Feature/AnnualPlans/PlanWorkspaceTest.php`

**Interfaces:**
- Produces on the page:
  - `#[Url(except: '')] public string $tab`, `#[Computed] tabs(): list<array{key,label,count}>`, `#[Computed] activeTab(): string`
  - `#[Computed] board(): array` (`PlanBoard::for`), `#[Computed] returnCount(): int`, `#[Computed] lastReturn(): ?AnnualPlanEvent`, `#[Computed] journal(): Collection<AnnualPlanEvent>`
  - `protected function forgetPlan(): void` (used by every trait in Tasks 26–29)
- Default tab: Draft/Submitted → `plan`; Active → `tracking`; Final review/Closed → `review`; Cancelled → `history`.
- Tabs shown:
  - `plan` (always)
  - `tracking` (Active, Final review, Closed)
  - `review` (Final review, Closed)
  - `projects` (program-wide plans only)
  - `targets` (always; today's panels)
  - `history` (always)

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\PlanEventAction;
use App\Enums\PlanStatus;
use App\Models\AnnualPlan;
use App\Models\AnnualPlanEvent;
use App\Models\Project;
use Livewire\Livewire;

test('a draft opens on the plan tab, an active plan on tracking, a closed one on review', function (string $state, string $tab) {
    $plan = AnnualPlan::factory()->{$state}()->create();

    Livewire::actingAs(userWithRole('General Manager'))
        ->test('pages::annual-plans.show', ['annualPlan' => $plan])
        ->assertSet('activeTab', $tab);
})->with([
    'submitted' => ['submitted', 'plan'],
    'active' => ['active', 'tracking'],
    'closed' => ['closed', 'review'],
    'cancelled' => ['cancelled', 'history'],
]);

test('a project plan has no projects tab, a program plan does', function () {
    $programPlan = AnnualPlan::factory()->create();
    $projectPlan = AnnualPlan::factory()->forProject(Project::factory()->create())->create();
    $viewer = userWithRole('General Manager');

    expect(array_column(Livewire::actingAs($viewer)->test('pages::annual-plans.show', ['annualPlan' => $programPlan])->get('tabs'), 'key'))->toContain('projects')
        ->and(array_column(Livewire::actingAs($viewer)->test('pages::annual-plans.show', ['annualPlan' => $projectPlan])->get('tabs'), 'key'))->not->toContain('projects');
});

test('a returned draft shows why it came back', function () {
    $plan = AnnualPlan::factory()->create();
    AnnualPlanEvent::factory()->create([
        'annual_plan_id' => $plan->id,
        'action' => PlanEventAction::Returned,
        'from_status' => PlanStatus::Submitted,
        'to_status' => PlanStatus::Draft,
        'note' => 'Masters needs a second month',
    ]);

    Livewire::actingAs(userWithRole('General Manager'))
        ->test('pages::annual-plans.show', ['annualPlan' => $plan])
        ->assertSeeText('Masters needs a second month')
        ->assertSeeText(trans_choice('annual_plans.returned_times', 1, ['count' => 1]));
});

test('the history tab lists the journal newest first', function () {
    $plan = AnnualPlan::factory()->active()->create();
    AnnualPlanEvent::factory()->create(['annual_plan_id' => $plan->id, 'action' => PlanEventAction::Submitted, 'created_at' => now()->subDay()]);
    AnnualPlanEvent::factory()->create(['annual_plan_id' => $plan->id, 'action' => PlanEventAction::Approved]);

    Livewire::actingAs(userWithRole('General Manager'))
        ->test('pages::annual-plans.show', ['annualPlan' => $plan])
        ->set('tab', 'history')
        ->assertSeeTextInOrder([PlanEventAction::Approved->label(), PlanEventAction::Submitted->label()]);
});

test('the overview panels still render under the targets tab', function () {
    $plan = AnnualPlan::factory()->create();

    Livewire::actingAs(userWithRole('General Manager'))
        ->test('pages::annual-plans.show', ['annualPlan' => $plan])
        ->set('tab', 'targets')
        ->assertSeeText(__('annual_plans.results_and_targets'));
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanWorkspaceTest.php`
Expected: FAIL.

- [ ] **Step 3: Component (PHP) changes in `⚡show.blade.php`**

1. Imports: `App\Enums\PlanEventAction`, `App\Enums\PlanStatus`, `App\Models\AnnualPlanEvent`, `App\Support\Plans\PlanBoard`, `Livewire\Attributes\Url`.
2. Replace `public string $planYear = '';` with `public string $planStartMonth = ''; public string $planEndMonth = '';` and add:

```php
    #[Url(except: '')]
    public string $tab = '';
```

3. `plan()` selects and loads everything the header reads:

```php
    #[Computed]
    public function plan(): AnnualPlan
    {
        return AnnualPlan::query()
            ->select(['id', 'slug', 'program_id', 'project_id', 'manager_id', 'year', 'starts_on', 'ends_on', 'status', 'submitted_at', 'approved_at', 'approved_by', 'review_opened_at', 'closed_at', 'closed_by', 'closing_notes', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'updated_at'])
            ->with([
                'program:id,strategy_id,manager_id',
                'program.strategy:id,name',
                'project:id,name,slug,user_id',
                'manager:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
                'approver:id,hr_user_id',
                'closer:id,hr_user_id',
                'canceller:id,hr_user_id',
            ])
            ->withCount('planIndicators')
            ->findOrFail($this->annualPlanId);
    }
```

4. Add:

```php
    /**
     * The grid and its totals, in a fixed number of reads.
     *
     * @return array{months: list<array{key: string, label: string, isCurrent: bool}>, rows: list<array<string, mixed>>, totals: array<string, int|null>}
     */
    #[Computed]
    public function board(): array
    {
        return app(PlanBoard::class)->for($this->plan);
    }

    #[Computed]
    public function returnCount(): int
    {
        return AnnualPlanEvent::query()->where('annual_plan_id', $this->annualPlanId)->where('action', PlanEventAction::Returned->value)->count();
    }

    /**
     * The return the manager is answering, while the plan is back in draft.
     */
    #[Computed]
    public function lastReturn(): ?AnnualPlanEvent
    {
        if (! $this->plan->status->isDraft()) {
            return null;
        }

        return AnnualPlanEvent::query()
            ->where('annual_plan_id', $this->annualPlanId)
            ->where('action', PlanEventAction::Returned->value)
            ->with('user:id,hr_user_id')
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    /**
     * @return Collection<int, AnnualPlanEvent>
     */
    #[Computed]
    public function journal(): Collection
    {
        return $this->plan->events()->with(['user:id,hr_user_id', 'planActivity:id,catalog_activity_id', 'planActivity.catalogActivity:id,name', 'project:id,name'])->limit(200)->get();
    }

    /**
     * @return list<array{key: string, label: string, count: int|null}>
     */
    #[Computed]
    public function tabs(): array
    {
        $status = $this->plan->status;

        return array_values(array_filter([
            ['key' => 'plan', 'label' => __('annual_plans.tab_plan'), 'count' => count($this->board['rows'])],
            $status->allowsTracking() || $status === PlanStatus::Closed ? ['key' => 'tracking', 'label' => __('annual_plans.tab_tracking'), 'count' => null] : null,
            in_array($status, [PlanStatus::FinalReview, PlanStatus::Closed], true) ? ['key' => 'review', 'label' => __('annual_plans.tab_review'), 'count' => null] : null,
            $this->plan->isProgramWide() ? ['key' => 'projects', 'label' => __('common.projects'), 'count' => null] : null,
            ['key' => 'targets', 'label' => __('common.targets'), 'count' => $this->plan->plan_indicators_count],
            ['key' => 'history', 'label' => __('annual_plans.tab_history'), 'count' => null],
        ]));
    }

    #[Computed]
    public function activeTab(): string
    {
        if (in_array($this->tab, array_column($this->tabs, 'key'), true)) {
            return $this->tab;
        }

        return match ($this->plan->status) {
            PlanStatus::Draft, PlanStatus::Submitted => 'plan',
            PlanStatus::Active => 'tracking',
            PlanStatus::FinalReview, PlanStatus::Closed => 'review',
            PlanStatus::Cancelled => 'history',
        };
    }

    /**
     * Drop every memoised read after a write. Every trait calls this.
     */
    protected function forgetPlan(): void
    {
        unset(
            $this->plan, $this->board, $this->returnCount, $this->lastReturn, $this->journal, $this->tabs, $this->activeTab,
            $this->targets, $this->reportStatusCounts, $this->summary, $this->resultGroups, $this->periodMix, $this->linkedProjects, $this->recentReports,
        );
    }
```

5. Delete `yearFormOptions()`. Rewrite `openEditModal()` / `savePlan()` to use `editDetails` + `SavePlanDetails` with `planStartMonth`, `planEndMonth`, `planManagerId` (scope and program stay as they are; pass `programId` / `projectId` from `$this->plan`). Validation rules: `'planStartMonth' => ['required', 'date_format:Y-m'], 'planEndMonth' => ['required', 'date_format:Y-m'], 'planManagerId' => ['required', 'integer', Rule::exists(HrEmployee::class, 'id')]`. After saving call `$this->forgetPlan()`.

6. `deletePlan()` keeps `Gate::authorize('delete', ...)`, which now only allows draft/cancelled.

- [ ] **Step 4: Blade layout**

Replace everything between `<section class="@container/detail …">` and the closing `</section>` with this skeleton. Keep the existing modal and confirm-modal blocks, adjusted as below.

```blade
<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-6 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('common.annual_plans'), 'url' => route('annual-plans.index')],
            ['label' => $plan->fiscalLabel()],
        ]"
        :title="__('common.fy_year_program', ['year' => $plan->periodLabel(), 'program' => $plan->program->name])"
        :subtitle="$plan->project?->name ?? ($plan->program->strategy?->name ?? __('common.strategy_not_assigned'))"
    >
        <x-slot:meta>
            <x-ui.enum-badge :case="$plan->status" />
            <span class="tabular-nums">{{ $plan->periodLabel() }} · {{ trans_choice('annual_plans.count_months', \App\Support\Plans\PlanPeriod::length($plan->starts_on, $plan->ends_on), ['count' => \App\Support\Plans\PlanPeriod::length($plan->starts_on, $plan->ends_on)]) }}</span>
            <a href="{{ route('programs.show', $plan->program) }}" wire:navigate class="font-medium hover:underline">{{ $plan->program->name }}</a>
            @if ($plan->project !== null)
                <a href="{{ route('projects.show', $plan->project) }}" wire:navigate class="hover:underline">{{ $plan->project->name }}</a>
            @endif
        </x-slot:meta>

        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.employee-chip :employee="$plan->manager" class="rounded-lg border border-secondary-soft p-2.5 dark:border-line" />
                @include('pages.annual-plans._workflow-actions')
                @can('editDetails', $plan)<x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-11! touch-manipulation @sm/detail:size-10!" wire:click="openEditModal" :aria-label="__('annual_plans.edit_this_annual_plan')" data-test="edit-annual-plan-button" />@endcan
                @can('delete', $plan)<x-ui.button tone="ghost" size="sm" icon="trash" class="size-11! touch-manipulation @sm/detail:size-10!" wire:click="$set('confirmingDelete', true)" :aria-label="__('annual_plans.delete_this_annual_plan')" data-test="delete-annual-plan-button" />@endcan
            </div>
        </x-slot:actions>
    </x-ui.detail-header>

    <x-plans.phase-rail :status="$plan->status" :returns="$this->returnCount" />

    @if ($this->lastReturn !== null)
        <div class="flex flex-col gap-1 border-y border-secondary-soft py-4 dark:border-line" style="border-top: 2px solid var(--color-primary)" data-test="returned-banner">
            <p class="type-label text-status-critical">{{ __('annual_plans.returned_by', ['name' => $this->lastReturn->user?->name ?? __('notifications.system'), 'date' => $this->lastReturn->created_at->translatedFormat(__('formats.date'))]) }}</p>
            <p class="text-base">“{{ $this->lastReturn->note }}”</p>
            <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.edit_then_send_again') }}</p>
        </div>
    @endif

    @if ($plan->status === \App\Enums\PlanStatus::Cancelled)
        <p class="border-y border-secondary-soft py-4 text-base dark:border-line">{{ __('annual_plans.cancelled_because', ['reason' => $plan->cancel_reason]) }}</p>
    @endif

    <x-ui.relation-tabs :tabs="$this->tabs" :active="$this->activeTab" wire-target="tab" />

    @switch ($this->activeTab)
        @case('plan')
            @include('pages.annual-plans._plan-tab')
            @break
        @case('tracking')
            @include('pages.annual-plans._tracking-tab')
            @break
        @case('review')
            @include('pages.annual-plans._review-tab')
            @break
        @case('projects')
            @include('pages.annual-plans._projects-tab')
            @break
        @case('history')
            @include('pages.annual-plans._history-tab')
            @break
        @default
            @include('pages.annual-plans._overview-tab')
    @endswitch

    @include('pages.annual-plans._workflow-modals')

    {{-- keep the existing edit x-ui.modal here, with its body replaced as in Step 5 --}}
    {{-- keep the existing x-ui.confirm-modal here, with 'year' params replaced by $plan->periodLabel() --}}
</section>
```

The two `{{-- keep … --}}` lines mark where the existing modal markup stays. Do not leave the comments in the final file.

Until Tasks 26–31 land, create the partials they own as empty files with a one-line Blade comment, so `@include` does not fail: `_plan-tab`, `_tracking-tab`, `_review-tab`, `_projects-tab`, `_workflow-actions`, `_workflow-modals`. Each later task replaces its file.

**`_overview-tab.blade.php`**: cut the current page markup from `<section class="overflow-hidden rounded-xl border border-board-line bg-board" aria-labelledby="plan-pulse-heading">` down to the end of the recent-reports `x-ui.panel` and its wrapper `</div>` (the block just above `<x-ui.modal`). Paste it into the partial **unchanged**. The `$summary`, `$plan`, gauge `@php` variables it uses stay defined in the page's top `@php` block, which runs before the include.

**`_history-tab.blade.php`**:

```blade
<div class="grid gap-10 @4xl/detail:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
    <section aria-labelledby="journal-heading" class="flex flex-col gap-4">
        <div>
            <h2 id="journal-heading" class="type-heading">{{ __('annual_plans.plan_journal') }}</h2>
            <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.plan_journal_hint') }}</p>
        </div>
        <ol class="flex flex-col">
            @forelse ($this->journal as $entry)
                <li class="relative ps-6 pb-5 before:absolute before:start-[5px] before:top-3.5 before:bottom-0 before:w-0.5 before:bg-secondary-soft last:before:hidden dark:before:bg-line" wire:key="journal-{{ $entry->id }}">
                    <span @class([
                        'absolute start-0 top-1 size-3 rounded-full border-2',
                        'border-primary bg-primary dark:border-brand dark:bg-brand' => in_array($entry->action, [\App\Enums\PlanEventAction::Returned, \App\Enums\PlanEventAction::Cancelled, \App\Enums\PlanEventAction::SentBack, \App\Enums\PlanEventAction::Missed], true),
                        'border-secondary-dark bg-secondary-dark dark:border-ink dark:bg-ink' => ! in_array($entry->action, [\App\Enums\PlanEventAction::Returned, \App\Enums\PlanEventAction::Cancelled, \App\Enums\PlanEventAction::SentBack, \App\Enums\PlanEventAction::Missed], true),
                    ]) aria-hidden="true"></span>
                    <p class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted">{{ $entry->created_at->translatedFormat(__('formats.datetime')) }} · {{ $entry->user?->name ?? __('notifications.system') }}</p>
                    <p class="text-sm font-semibold">{{ $entry->action->label() }}
                        @if ($entry->planActivity !== null) · {{ $entry->planActivity->name() }} @endif
                        @if ($entry->month !== null) · {{ $entry->month->translatedFormat(__('formats.month_year')) }} @endif
                        @if ($entry->project !== null) · {{ $entry->project->name }} @endif
                    </p>
                    @if (filled($entry->note))
                        <p class="text-sm text-secondary-medium dark:text-ink-muted">“{{ $entry->note }}”</p>
                    @endif
                </li>
            @empty
                <li><x-ui.empty-state icon="clock" :heading="__('annual_plans.no_activity_yet')" /></li>
            @endforelse
        </ol>
    </section>

    <section aria-labelledby="activity-heading" class="flex flex-col gap-4">
        <h2 id="activity-heading" class="type-heading">{{ __('activity.title') }}</h2>
        <livewire:activity.timeline :subjects="['annual_plan' => [$plan->id]]" :key="'annual-plan-activity-'.$plan->id" />
    </section>
</div>
```

- [ ] **Step 5: Edit modal body**

Replace the old year select in the page's edit modal with:

```blade
<div class="grid grid-cols-2 gap-x-4 gap-y-4">
    <x-ui.form-input type="month" wire:model="planStartMonth" :label="__('annual_plans.start_month')" required autofocus />
    <x-ui.form-input type="month" wire:model="planEndMonth" :label="__('annual_plans.end_month')" required />
    <x-ui.form-select wire:model="planManagerId" :label="__('annual_plans.assigned_manager')" :placeholder="__('annual_plans.select_a_manager')" required class="col-span-2">
        @foreach ($this->employeeOptions as $employeeOption)
            <option value="{{ $employeeOption->id }}">{{ $employeeOption->name }} · {{ $employeeOption->secondary_name }}</option>
        @endforeach
    </x-ui.form-select>
</div>
```

In the confirm-modal messages, replace `'year' => $plan->year` with `'year' => $plan->periodLabel()`.

- [ ] **Step 6: Lang** (en / ar)

```php
    'cancelled_because' => 'Cancelled: :reason',
    'count_months' => '{1} :count month|[2,*] :count months',
    'edit_then_send_again' => 'Edit the plan, then send it to MEAL again. This return stays in the plan history.',
    'plan_journal' => 'Plan history',
    'plan_journal_hint' => 'Every phase, return and tracking change, with who and when. Kept for the life of the plan.',
    'returned_by' => 'Returned by :name on :date',
    'tab_history' => 'History',
    'tab_plan' => 'Plan',
    'tab_review' => 'Planned vs actual',
    'tab_tracking' => 'Tracking',
```

```php
    'cancelled_because' => 'أُلغيت: :reason',
    'count_months' => '{1} شهر واحد|{2} شهران|[3,10] :count أشهر|[11,*] :count شهرًا',
    'edit_then_send_again' => 'عدّل الخطة ثم أرسلها إلى المتابعة والتقييم مجددًا. تبقى هذه الإعادة في سجل الخطة.',
    'plan_journal' => 'سجل الخطة',
    'plan_journal_hint' => 'كل مرحلة وإعادة وتغيير في المتابعة، مع من قام به ومتى. يُحتفظ به طوال عمر الخطة.',
    'returned_by' => 'أعادها :name في :date',
    'tab_history' => 'السجل',
    'tab_plan' => 'الخطة',
    'tab_review' => 'المخطط مقابل الفعلي',
    'tab_tracking' => 'المتابعة',
```

- [ ] **Step 7: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanWorkspaceTest.php tests/Feature/AnnualPlanDetailTest.php tests/Feature/AccessControl/ModuleAccessTest.php tests/Feature/LocalizationTest.php`
Expected: PASS. Compile check: `.\php.cmd bisect_blade.php "resources/views/pages/annual-plans/⚡show.blade.php"` (see `.ai/rules/views.md`) if anything fails to parse.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add resources/views/pages/annual-plans lang/en/annual_plans.php lang/ar/annual_plans.php tests/Feature/AnnualPlans/PlanWorkspaceTest.php
```

---

### Task 26: Plan tab: the PM builds the draft

**Files:**
- Create: `app/Livewire/Concerns/EditsPlannedLayer.php`, `tests/PHPStan/PlannedLayerHost.php`
- Modify: `resources/views/pages/annual-plans/⚡show.blade.php` (use trait), `resources/views/pages/annual-plans/_plan-tab.blade.php`, `phpstan.neon` (only if hosts are listed one by one; check), `lang/{en,ar}/annual_plans.php`
- Test: `tests/Feature/AnnualPlans/PlanTabTest.php`

**Interfaces:**
- Consumes: `AddPlanActivity`, `RemovePlanActivity`, `TogglePlannedMonth`, `UpdatePlanActivity`, `SaveCatalogActivity`, page members `plan`, `board`, `forgetPlan()`.
- Produces (trait):
  - properties `catalogActivityId`, `showActivityModal`, `editingActivityId`, `activityResponsibleId`, `activityTracking`, `showNewCatalogModal`, `catalogName`, `catalogDescription`
  - methods `addActivity()`, `removeActivity(int)`, `toggleMonth(int, string)`, `openActivityModal(int)`, `saveActivity()`, `openNewCatalogModal()`, `createCatalogActivity()`
  - `#[Computed] catalogOptions()`, `#[Computed] catalogTwin(): bool`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\AnnualPlan;
use App\Models\CatalogActivity;
use App\Models\PlanActivity;
use Livewire\Livewire;

beforeEach(function () {
    $this->employee = insertHrEmployee();
    $this->manager = userWithRole('Team Member', $this->employee);
    $this->plan = AnnualPlan::factory()->between('2027-01', '2027-12')->create(['manager_id' => $this->employee]);
});

test('the manager adds an activity from the catalog and plans two months', function () {
    $catalog = CatalogActivity::factory()->create(['name' => 'Beginner training']);

    $page = Livewire::actingAs($this->manager)
        ->test('pages::annual-plans.show', ['annualPlan' => $this->plan])
        ->set('catalogActivityId', (string) $catalog->id)
        ->call('addActivity')
        ->assertHasNoErrors();

    $activity = PlanActivity::query()->sole();

    $page->call('toggleMonth', $activity->id, '2027-02')
        ->call('toggleMonth', $activity->id, '2027-03')
        ->assertSee("toggleMonth({$activity->id}, '2027-02')", false);

    expect($activity->months()->count())->toBe(2);
});

test('a new catalog activity is created and placed in one step, warning on an active twin', function () {
    CatalogActivity::factory()->create(['name' => 'Beginner training']);

    Livewire::actingAs($this->manager)
        ->test('pages::annual-plans.show', ['annualPlan' => $this->plan])
        ->call('openNewCatalogModal')
        ->set('catalogName', 'beginner training')
        ->assertSet('catalogTwin', true)
        ->set('catalogName', 'Parents committee meeting')
        ->call('createCatalogActivity')
        ->assertHasNoErrors()
        ->assertSet('showNewCatalogModal', false);

    expect(PlanActivity::query()->sole()->name())->toBe('Parents committee meeting');
});

test('meal sees the draft but cannot edit it', function () {
    PlanActivity::factory()->inMonths('2027-02')->create(['annual_plan_id' => $this->plan->id]);

    Livewire::actingAs(userWithRole('MEAL'))
        ->test('pages::annual-plans.show', ['annualPlan' => $this->plan])
        ->assertDontSee('toggleMonth', false)
        ->call('toggleMonth', PlanActivity::query()->value('id'), '2027-03')
        ->assertForbidden();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanTabTest.php`
Expected: FAIL.

- [ ] **Step 3: The trait**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\AnnualPlans\AddPlanActivity;
use App\Actions\AnnualPlans\RemovePlanActivity;
use App\Actions\AnnualPlans\SaveCatalogActivity;
use App\Actions\AnnualPlans\TogglePlannedMonth;
use App\Actions\AnnualPlans\UpdatePlanActivity;
use App\Enums\PlanActivityTracking;
use App\Models\AnnualPlan;
use App\Models\CatalogActivity;
use App\Models\PlanActivity;
use App\Support\Plans\PlanPeriod;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The plan tab: the manager builds the draft's grid. Every write goes through
 * an App\Actions\AnnualPlans action, which authorizes `editPlanned`; this
 * trait only shapes input and refreshes the page.
 *
 * @property-read AnnualPlan $plan  #[Computed] plan() on the page
 */
trait EditsPlannedLayer
{
    public string $catalogActivityId = '';

    public bool $showActivityModal = false;

    #[Locked]
    public ?int $editingActivityId = null;

    public string $activityResponsibleId = '';

    public string $activityTracking = 'shared';

    public bool $showNewCatalogModal = false;

    public string $catalogName = '';

    public string $catalogDescription = '';

    abstract protected function forgetPlan(): void;

    /**
     * Active catalog entries not yet in this plan.
     *
     * @return Collection<int, CatalogActivity>
     */
    #[Computed]
    public function catalogOptions(): Collection
    {
        return CatalogActivity::query()
            ->active()
            ->whereNotIn('id', PlanActivity::query()->where('annual_plan_id', $this->plan->id)->select('catalog_activity_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function catalogTwin(): bool
    {
        return trim($this->catalogName) !== '' && SaveCatalogActivity::activeTwinExists($this->catalogName);
    }

    public function updatedCatalogName(): void
    {
        unset($this->catalogTwin);
    }

    public function addActivity(): void
    {
        $this->validate(['catalogActivityId' => ['required', 'integer', Rule::exists(CatalogActivity::class, 'id')]], [
            'catalogActivityId.required' => __('annual_plans.choose_an_activity_from_the_catalog'),
        ]);

        app(AddPlanActivity::class)->handle($this->plan, CatalogActivity::query()->findOrFail((int) $this->catalogActivityId));

        $this->catalogActivityId = '';
        unset($this->catalogOptions);
        $this->forgetPlan();
    }

    public function removeActivity(int $activityId): void
    {
        app(RemovePlanActivity::class)->handle($this->ownActivity($activityId));

        unset($this->catalogOptions);
        $this->forgetPlan();
    }

    public function toggleMonth(int $activityId, string $monthKey): void
    {
        try {
            $month = PlanPeriod::fromKey($monthKey);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['month' => __('annual_plans.that_month_is_outside_the_plan_period')]);
        }

        app(TogglePlannedMonth::class)->handle($this->ownActivity($activityId), $month);

        $this->forgetPlan();
    }

    public function openActivityModal(int $activityId): void
    {
        $activity = $this->ownActivity($activityId);

        $this->resetValidation();
        $this->editingActivityId = $activity->id;
        $this->activityResponsibleId = $activity->responsible_employee_id === null ? '' : (string) $activity->responsible_employee_id;
        $this->activityTracking = $activity->tracking->value;
        $this->showActivityModal = true;
    }

    public function saveActivity(): void
    {
        $validated = $this->validate([
            'activityResponsibleId' => ['nullable', 'integer'],
            'activityTracking' => ['required', Rule::enum(PlanActivityTracking::class)],
        ]);

        app(UpdatePlanActivity::class)->handle(
            $this->ownActivity((int) $this->editingActivityId),
            $validated['activityResponsibleId'] === '' || $validated['activityResponsibleId'] === null ? null : (int) $validated['activityResponsibleId'],
            PlanActivityTracking::from($validated['activityTracking']),
        );

        $this->showActivityModal = false;
        $this->editingActivityId = null;
        $this->forgetPlan();
    }

    public function openNewCatalogModal(): void
    {
        $this->resetValidation();
        $this->catalogName = '';
        $this->catalogDescription = '';
        $this->showNewCatalogModal = true;
    }

    /**
     * Add the activity to the shared catalog and place it in this draft.
     */
    public function createCatalogActivity(): void
    {
        $catalog = app(SaveCatalogActivity::class)->handle(null, [
            'name' => $this->catalogName,
            'description' => $this->catalogDescription,
            'isActive' => true,
        ]);

        app(AddPlanActivity::class)->handle($this->plan, $catalog);

        $this->showNewCatalogModal = false;
        unset($this->catalogOptions);
        $this->forgetPlan();

        Flux::toast(variant: 'success', text: __('annual_plans.activity_added_to_the_catalog_and_plan'));
    }

    /**
     * A row of THIS plan (IDOR guard: ids come from the browser).
     */
    private function ownActivity(int $activityId): PlanActivity
    {
        return PlanActivity::query()->where('annual_plan_id', $this->plan->id)->findOrFail($activityId);
    }
}
```

`tests/PHPStan/PlannedLayerHost.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\EditsPlannedLayer;
use Livewire\Component;

/**
 * Stands in for the annual plan show page so PHPStan analyses the trait.
 * Never instantiated.
 */
abstract class PlannedLayerHost extends Component
{
    use EditsPlannedLayer;
}
```

Add `use EditsPlannedLayer;` to the page class (keep traits alphabetical).

- [ ] **Step 4: `_plan-tab.blade.php`**

```blade
@php
    $canEditPlanned = auth()->user()->can('editPlanned', $plan);
    $canReview = auth()->user()->can('review', $plan);
@endphp

<div class="flex flex-col gap-5">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <div>
            <h2 class="type-heading">{{ __('annual_plans.planned_layer') }}</h2>
            <p class="max-w-prose type-meta text-secondary-medium dark:text-ink-muted">
                {{ match (true) {
                    $canEditPlanned => __('annual_plans.planned_layer_hint_draft'),
                    $plan->status === \App\Enums\PlanStatus::Submitted => __('annual_plans.planned_layer_hint_submitted'),
                    default => __('annual_plans.planned_layer_hint_frozen'),
                } }}
            </p>
        </div>
        <p class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted">
            {{ trans_choice('annual_plans.count_activities', count($this->board['rows']), ['count' => count($this->board['rows'])]) }}
            · {{ trans_choice('annual_plans.count_planned_months', $this->board['totals']['planned'], ['count' => $this->board['totals']['planned']]) }}
        </p>
    </div>

    <x-plans.month-grid
        :months="$this->board['months']"
        :rows="$this->board['rows']"
        mode="planned"
        :editable="$canEditPlanned"
        :can-set-targets="$canReview && $plan->status !== \App\Enums\PlanStatus::Draft"
    />

    @if ($canEditPlanned)
        <form wire:submit="addActivity" class="flex flex-wrap items-end gap-3">
            <x-ui.form-select wire:model="catalogActivityId" :label="__('annual_plans.add_from_the_catalog')" :placeholder="__('annual_plans.choose_an_activity')" class="min-w-64">
                @foreach ($this->catalogOptions as $catalogOption)
                    <option value="{{ $catalogOption->id }}">{{ $catalogOption->name }}</option>
                @endforeach
            </x-ui.form-select>
            <x-ui.button type="submit" tone="primary" icon="plus" class="min-h-11">{{ __('annual_plans.add_activity') }}</x-ui.button>
            @can('create', \App\Models\CatalogActivity::class)
                <x-ui.button type="button" tone="ghost" icon="sparkles" class="min-h-11" wire:click="openNewCatalogModal">{{ __('annual_plans.new_catalog_activity') }}</x-ui.button>
            @endcan
        </form>
    @endif

    <x-plans.legend />
</div>

<x-ui.modal wire:model="showActivityModal" size="sm" :title="__('annual_plans.activity_settings')" submit="saveActivity" close="$set('showActivityModal', false)">
    @if ($showActivityModal)
        <div class="grid gap-4">
            <x-ui.form-select wire:model="activityResponsibleId" :label="__('annual_plans.responsible_person')" :placeholder="__('common.not_assigned')">
                @foreach ($this->employeeOptions as $employeeOption)
                    <option value="{{ $employeeOption->id }}">{{ $employeeOption->name }}</option>
                @endforeach
            </x-ui.form-select>
            @if ($plan->isProgramWide())
                <x-ui.form-segmented
                    wire:model="activityTracking"
                    :label="__('annual_plans.how_it_is_tracked')"
                    :options="[['value' => 'shared', 'label' => \App\Enums\PlanActivityTracking::Shared->label()], ['value' => 'per project', 'label' => \App\Enums\PlanActivityTracking::PerProject->label()]]"
                    :value="$activityTracking"
                    :help="__('annual_plans.how_it_is_tracked_help')"
                />
            @endif
        </div>
    @endif
    <x-slot:actions>
        <x-ui.button type="submit" tone="primary">{{ __('common.save_changes') }}</x-ui.button>
    </x-slot:actions>
</x-ui.modal>

<x-ui.modal wire:model="showNewCatalogModal" size="sm" :title="__('annual_plans.new_catalog_activity')" :description="__('annual_plans.new_catalog_activity_hint')" submit="createCatalogActivity" close="$set('showNewCatalogModal', false)" required-note>
    @if ($showNewCatalogModal)
        <div class="grid gap-4">
            <x-ui.form-input wire:model.live.debounce.400ms="catalogName" :label="__('annual_plans.activity_name')" required maxlength="150" />
            @if ($this->catalogTwin)
                <p class="type-meta text-status-warn" role="status">{{ __('annual_plans.an_active_activity_already_has_this_name') }}</p>
            @endif
            <x-ui.form-textarea wire:model="catalogDescription" :label="__('annual_plans.activity_description')" rows="3" />
        </div>
    @endif
    <x-slot:actions>
        <x-ui.button type="submit" tone="primary">{{ __('annual_plans.add_to_catalog_and_plan') }}</x-ui.button>
    </x-slot:actions>
</x-ui.modal>
```

> Check `x-ui.modal`'s `close` prop. If it expects a method name, add a tiny `closeActivityModal()` / `closeNewCatalogModal()` to the trait instead of `$set(...)`.

- [ ] **Step 5: Lang** (en / ar)

```php
    'activity_added_to_the_catalog_and_plan' => 'Activity added to the catalog and this plan',
    'activity_description' => 'Description',
    'activity_name' => 'Activity name',
    'activity_settings' => 'Activity settings',
    'add_activity' => 'Add activity',
    'add_from_the_catalog' => 'Add from the catalog',
    'add_to_catalog_and_plan' => 'Add to catalog and plan',
    'choose_an_activity' => 'Choose an activity',
    'choose_an_activity_from_the_catalog' => 'Choose an activity from the catalog.',
    'count_activities' => '{0} no activities|{1} :count activity|[2,*] :count activities',
    'how_it_is_tracked' => 'How it is tracked',
    'how_it_is_tracked_help' => 'Shared: one status for every project. Per project: each project reports its own months.',
    'new_catalog_activity' => 'New catalog activity',
    'new_catalog_activity_hint' => 'The catalog is shared by project managers and MEAL. The activity is added to this plan too.',
    'planned_layer' => 'Planned layer',
    'planned_layer_hint_draft' => 'Click a month to plan the activity in it. Back-to-back months join into one thread. Quantities are not entered here: MEAL turns them into targets or milestones.',
    'planned_layer_hint_frozen' => 'The planned layer is frozen. It is the baseline the final review compares the actual layer against.',
    'planned_layer_hint_submitted' => 'With MEAL for review. Nobody edits the planned layer now; MEAL adds targets, then approves or returns it.',
    'responsible_person' => 'Responsible person',
```

```php
    'activity_added_to_the_catalog_and_plan' => 'أُضيف النشاط إلى القائمة وإلى هذه الخطة',
    'activity_description' => 'الوصف',
    'activity_name' => 'اسم النشاط',
    'activity_settings' => 'إعدادات النشاط',
    'add_activity' => 'إضافة نشاط',
    'add_from_the_catalog' => 'إضافة من القائمة',
    'add_to_catalog_and_plan' => 'إضافة إلى القائمة والخطة',
    'choose_an_activity' => 'اختر نشاطًا',
    'choose_an_activity_from_the_catalog' => 'اختر نشاطًا من القائمة.',
    'count_activities' => '{0} لا أنشطة|{1} نشاط واحد|{2} نشاطان|[3,10] :count أنشطة|[11,*] :count نشاطًا',
    'how_it_is_tracked' => 'طريقة المتابعة',
    'how_it_is_tracked_help' => 'مشتركة: حالة واحدة لكل المشاريع. لكل مشروع: يبلّغ كل مشروع عن أشهره.',
    'new_catalog_activity' => 'نشاط جديد في القائمة',
    'new_catalog_activity_hint' => 'القائمة مشتركة بين مديري المشاريع والمتابعة والتقييم. يُضاف النشاط إلى هذه الخطة أيضًا.',
    'planned_layer' => 'الطبقة المخططة',
    'planned_layer_hint_draft' => 'انقر على شهر لتخطيط النشاط فيه. الأشهر المتتالية تتصل بخط واحد. لا تُدخل الكميات هنا: تحوّلها المتابعة والتقييم إلى مستهدفات أو معالم.',
    'planned_layer_hint_frozen' => 'الطبقة المخططة مجمدة. هي الأساس الذي تقارن به المراجعة النهائية الطبقة الفعلية.',
    'planned_layer_hint_submitted' => 'لدى المتابعة والتقييم للمراجعة. لا أحد يعدّل الطبقة المخططة الآن؛ تضيف المتابعة والتقييم المستهدفات ثم تعتمد الخطة أو تعيدها.',
    'responsible_person' => 'الشخص المسؤول',
```

- [ ] **Step 6: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanTabTest.php tests/Feature/LocalizationTest.php` and `.\php.cmd vendor/bin/phpstan analyse --memory-limit=1G`
Expected: PASS, no new PHPStan errors.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add app/Livewire/Concerns/EditsPlannedLayer.php tests/PHPStan/PlannedLayerHost.php resources/views/pages/annual-plans lang/en/annual_plans.php lang/ar/annual_plans.php tests/Feature/AnnualPlans/PlanTabTest.php
```

---

### Task 27: Workflow buttons and their dialogs

**Files:**
- Create: `app/Livewire/Concerns/ManagesPlanWorkflow.php`, `tests/PHPStan/PlanWorkflowHost.php`
- Modify: `resources/views/pages/annual-plans/_workflow-actions.blade.php`, `resources/views/pages/annual-plans/_workflow-modals.blade.php`, `⚡show.blade.php` (use trait), `lang/{en,ar}/annual_plans.php`
- Test: `tests/Feature/AnnualPlans/PlanWorkflowScreenTest.php`

**Interfaces:**
- Consumes: Task 14 actions.
- Produces (trait):
  - properties `showReturnModal`, `returnReason`, `showCancelModal`, `cancelReason`, `showCloseModal`, `closingNotes`, `showSubmitModal`, `showApproveModal`
  - methods `submitPlan()`, `approvePlan()`, `returnPlan()`, `cancelPlan()`, `openFinalReviewNow()`, `closePlan()`

| Status | Button (who) | Opens | Calls |
|---|---|---|---|
| Draft | **Send to MEAL** (`submit`) | confirm dialog | `submitPlan` |
| Submitted | **Approve** (`review`) | confirm dialog | `approvePlan` |
| Submitted | **Return for edits** (`review`) | reason dialog | `returnPlan` |
| Submitted/Active | **Cancel plan** (`review`) | reason dialog | `cancelPlan` |
| Active | **Start final review** (`review`) | confirm (`wire:confirm`) | `openFinalReviewNow` |
| Final review | **Close plan** (`review`), on the review tab | notes dialog | `closePlan` |

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\PlanStatus;
use App\Models\AnnualPlan;
use App\Models\PlanActivity;
use Livewire\Livewire;

beforeEach(function () {
    $this->employee = insertHrEmployee();
    $this->manager = userWithRole('Team Member', $this->employee);
    $this->meal = userWithRole('MEAL');
    $this->plan = AnnualPlan::factory()->between('2027-01', '2027-12')->create(['manager_id' => $this->employee]);
    PlanActivity::factory()->inMonths('2027-02')->create(['annual_plan_id' => $this->plan->id]);
});

test('send, return with a reason, resend, approve', function () {
    Livewire::actingAs($this->manager)->test('pages::annual-plans.show', ['annualPlan' => $this->plan])
        ->assertSeeText(__('annual_plans.send_to_meal'))
        ->call('submitPlan')
        ->assertHasNoErrors();

    Livewire::actingAs($this->meal)->test('pages::annual-plans.show', ['annualPlan' => $this->plan->fresh()])
        ->set('showReturnModal', true)
        ->set('returnReason', '')
        ->call('returnPlan')
        ->assertHasErrors(['returnReason'])
        ->set('returnReason', 'Masters needs a second month')
        ->call('returnPlan')
        ->assertHasNoErrors()
        ->assertSet('showReturnModal', false);

    expect($this->plan->fresh()->status)->toBe(PlanStatus::Draft);

    Livewire::actingAs($this->manager)->test('pages::annual-plans.show', ['annualPlan' => $this->plan->fresh()])->call('submitPlan');
    Livewire::actingAs($this->meal)->test('pages::annual-plans.show', ['annualPlan' => $this->plan->fresh()])->call('approvePlan');

    expect($this->plan->fresh()->status)->toBe(PlanStatus::Active);
});

test('the manager sees no approve or return buttons', function () {
    $this->plan->forceFill(['status' => 'submitted'])->save();

    Livewire::actingAs($this->manager)->test('pages::annual-plans.show', ['annualPlan' => $this->plan])
        ->assertDontSeeText(__('annual_plans.approve'))
        ->assertDontSeeText(__('annual_plans.return_for_edits'));
});

test('a failed send shows why on the page', function () {
    PlanActivity::query()->delete();

    Livewire::actingAs($this->manager)->test('pages::annual-plans.show', ['annualPlan' => $this->plan])
        ->call('submitPlan')
        ->assertHasErrors(['plan']);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanWorkflowScreenTest.php`
Expected: FAIL.

- [ ] **Step 3: The trait**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\AnnualPlans\ApprovePlan;
use App\Actions\AnnualPlans\CancelPlan;
use App\Actions\AnnualPlans\ClosePlan;
use App\Actions\AnnualPlans\OpenFinalReview;
use App\Actions\AnnualPlans\ReturnPlanForEdits;
use App\Actions\AnnualPlans\SubmitPlan;
use App\Models\AnnualPlan;
use Flux\Flux;

/**
 * Phase moves from the plan workspace. The actions own every rule (who,
 * from which status, reasons); a ValidationException they throw lands on the
 * page as an error under the matching field or the `plan` key.
 *
 * @property-read AnnualPlan $plan  #[Computed] plan() on the page
 */
trait ManagesPlanWorkflow
{
    public bool $showSubmitModal = false;

    public bool $showApproveModal = false;

    public bool $showReturnModal = false;

    public string $returnReason = '';

    public bool $showCancelModal = false;

    public string $cancelReason = '';

    public bool $showCloseModal = false;

    public string $closingNotes = '';

    abstract protected function forgetPlan(): void;

    public function submitPlan(): void
    {
        app(SubmitPlan::class)->handle($this->plan);

        $this->showSubmitModal = false;
        $this->afterMove(__('annual_plans.sent_to_meal'));
    }

    public function approvePlan(): void
    {
        app(ApprovePlan::class)->handle($this->plan);

        $this->showApproveModal = false;
        $this->afterMove(__('annual_plans.plan_approved'));
    }

    public function returnPlan(): void
    {
        app(ReturnPlanForEdits::class)->handle($this->plan, $this->returnReason);

        $this->showReturnModal = false;
        $this->returnReason = '';
        $this->afterMove(__('annual_plans.plan_returned'));
    }

    public function cancelPlan(): void
    {
        app(CancelPlan::class)->handle($this->plan, $this->cancelReason);

        $this->showCancelModal = false;
        $this->cancelReason = '';
        $this->afterMove(__('annual_plans.plan_cancelled'));
    }

    public function openFinalReviewNow(): void
    {
        app(OpenFinalReview::class)->handle($this->plan);

        $this->afterMove(__('annual_plans.final_review_opened'));
    }

    public function closePlan(): void
    {
        app(ClosePlan::class)->handle($this->plan, $this->closingNotes);

        $this->showCloseModal = false;
        $this->afterMove(__('annual_plans.plan_closed'));
    }

    /**
     * Refresh every read and land on the tab the new phase opens on.
     */
    private function afterMove(string $toast): void
    {
        $this->tab = '';
        $this->forgetPlan();

        Flux::toast(variant: 'success', text: $toast);
    }
}
```

(The trait writes `$this->tab`. Document it with `@property string $tab` on the trait docblock.)

`tests/PHPStan/PlanWorkflowHost.php`: same shape as Task 26's host, `use ManagesPlanWorkflow;`.

- [ ] **Step 4: `_workflow-actions.blade.php`**

```blade
@php
    $status = $plan->status;
@endphp

@if ($status->isDraft())
    @can('submit', $plan)
        <x-ui.button tone="primary" icon="paper-airplane" class="min-h-11" wire:click="$set('showSubmitModal', true)" data-test="send-to-meal">{{ __('annual_plans.send_to_meal') }}</x-ui.button>
    @endcan
@endif

@can('review', $plan)
    @if ($status === \App\Enums\PlanStatus::Submitted)
        <x-ui.button tone="primary" icon="check" class="min-h-11" wire:click="$set('showApproveModal', true)" data-test="approve-plan">{{ __('annual_plans.approve') }}</x-ui.button>
        <x-ui.button tone="secondary" icon="arrow-uturn-left" class="min-h-11" wire:click="$set('showReturnModal', true)" data-test="return-plan">{{ __('annual_plans.return_for_edits') }}</x-ui.button>
    @endif
    @if ($status === \App\Enums\PlanStatus::Active)
        <x-ui.button tone="secondary" icon="flag" class="min-h-11" wire:click="openFinalReviewNow" wire:confirm="{{ __('annual_plans.start_final_review_confirm') }}">{{ __('annual_plans.start_final_review') }}</x-ui.button>
    @endif
    @if (in_array($status, [\App\Enums\PlanStatus::Submitted, \App\Enums\PlanStatus::Active], true))
        <x-ui.button tone="ghost" icon="x-circle" class="min-h-11" wire:click="$set('showCancelModal', true)">{{ __('annual_plans.cancel_plan') }}</x-ui.button>
    @endif
@endcan

@error('plan')
    <p class="basis-full type-meta text-status-critical" role="alert">{{ $message }}</p>
@enderror
```

- [ ] **Step 5: `_workflow-modals.blade.php`**

```blade
<x-ui.confirm-modal
    wire:model="showSubmitModal"
    :title="__('annual_plans.send_to_meal_title')"
    :message="__('annual_plans.send_to_meal_message')"
    confirm="submitPlan"
    :confirm-label="__('annual_plans.send_to_meal')"
    tone="primary"
/>

<x-ui.confirm-modal
    wire:model="showApproveModal"
    :title="__('annual_plans.approve_title')"
    :message="__('annual_plans.approve_message')"
    confirm="approvePlan"
    :confirm-label="__('annual_plans.approve')"
    tone="primary"
/>

<x-ui.modal wire:model="showReturnModal" size="md" :title="__('annual_plans.return_for_edits')" :description="__('annual_plans.return_for_edits_hint')" submit="returnPlan" close="$set('showReturnModal', false)" required-note>
    @if ($showReturnModal)
        <x-ui.form-textarea wire:model="returnReason" :label="__('annual_plans.what_should_change')" required rows="4" autofocus />
    @endif
    <x-slot:actions>
        <x-ui.button type="submit" tone="primary">{{ __('annual_plans.return_to_manager') }}</x-ui.button>
    </x-slot:actions>
</x-ui.modal>

<x-ui.modal wire:model="showCancelModal" size="md" :title="__('annual_plans.cancel_plan')" :description="__('annual_plans.cancel_plan_hint')" submit="cancelPlan" close="$set('showCancelModal', false)" required-note>
    @if ($showCancelModal)
        <x-ui.form-textarea wire:model="cancelReason" :label="__('annual_plans.why_cancel')" required rows="3" autofocus />
    @endif
    <x-slot:actions>
        <x-ui.button type="submit" tone="primary">{{ __('annual_plans.cancel_plan') }}</x-ui.button>
    </x-slot:actions>
</x-ui.modal>

<x-ui.modal wire:model="showCloseModal" size="md" :title="__('annual_plans.close_plan')" :description="__('annual_plans.close_plan_hint')" submit="closePlan" close="$set('showCloseModal', false)" required-note>
    @if ($showCloseModal)
        <x-ui.form-textarea wire:model="closingNotes" :label="__('annual_plans.closing_notes')" required rows="6" autofocus />
        @error('plan')<p class="mt-2 type-meta text-status-critical" role="alert">{{ $message }}</p>@enderror
    @endif
    <x-slot:actions>
        <x-ui.button type="submit" tone="primary">{{ __('annual_plans.close_plan') }}</x-ui.button>
    </x-slot:actions>
</x-ui.modal>
```

(Check `x-ui.confirm-modal` supports `tone="primary"`. Its prop default is `'danger'`. Read its `match` before relying on it; if only `danger` exists, use it for cancel and add nothing new.)

- [ ] **Step 6: Lang** (en / ar)

```php
    'approve' => 'Approve',
    'approve_message' => 'The plan becomes active now. The project manager is notified; no other sign-off is needed.',
    'approve_title' => 'Approve this plan?',
    'cancel_plan' => 'Cancel plan',
    'cancel_plan_hint' => 'A cancelled plan stays on record and frees its months for a new plan.',
    'close_plan' => 'Close plan',
    'close_plan_hint' => 'Write what was learned. Closing makes the plan read-only for everyone.',
    'closing_notes' => 'Closing notes',
    'final_review_opened' => 'Final review opened',
    'plan_approved' => 'Plan approved and active',
    'plan_cancelled' => 'Plan cancelled',
    'plan_closed' => 'Plan closed',
    'plan_returned' => 'Plan returned to the project manager',
    'return_for_edits' => 'Return for edits',
    'return_for_edits_hint' => 'The project manager gets the plan back as a draft with your note. The return is kept in the plan history.',
    'return_to_manager' => 'Return to the project manager',
    'send_to_meal' => 'Send to MEAL',
    'send_to_meal_message' => 'After sending, you can no longer change the planned months. MEAL is notified.',
    'send_to_meal_title' => 'Send this plan to MEAL?',
    'sent_to_meal' => 'Sent to MEAL',
    'start_final_review' => 'Start final review',
    'start_final_review_confirm' => 'Start the final review now? Project managers can no longer report months.',
    'what_should_change' => 'What should the project manager change?',
    'why_cancel' => 'Why is the plan cancelled?',
```

```php
    'approve' => 'اعتماد',
    'approve_message' => 'تصبح الخطة نشطة الآن. يُبلَّغ مدير المشروع؛ ولا حاجة لموافقة أخرى.',
    'approve_title' => 'هل تريد اعتماد هذه الخطة؟',
    'cancel_plan' => 'إلغاء الخطة',
    'cancel_plan_hint' => 'تبقى الخطة الملغاة في السجل وتُحرَّر أشهرها لخطة جديدة.',
    'close_plan' => 'إغلاق الخطة',
    'close_plan_hint' => 'اكتب ما تم تعلّمه. الإغلاق يجعل الخطة للقراءة فقط للجميع.',
    'closing_notes' => 'ملاحظات الإغلاق',
    'final_review_opened' => 'فُتحت المراجعة النهائية',
    'plan_approved' => 'اعتُمدت الخطة وأصبحت نشطة',
    'plan_cancelled' => 'أُلغيت الخطة',
    'plan_closed' => 'أُغلقت الخطة',
    'plan_returned' => 'أُعيدت الخطة إلى مدير المشروع',
    'return_for_edits' => 'إعادة للتعديل',
    'return_for_edits_hint' => 'يستعيد مدير المشروع الخطة كمسودة مع ملاحظتك. تبقى الإعادة في سجل الخطة.',
    'return_to_manager' => 'إعادة إلى مدير المشروع',
    'send_to_meal' => 'إرسال إلى المتابعة والتقييم',
    'send_to_meal_message' => 'بعد الإرسال لا يمكنك تغيير الأشهر المخططة. تُبلَّغ المتابعة والتقييم.',
    'send_to_meal_title' => 'هل تريد إرسال هذه الخطة إلى المتابعة والتقييم؟',
    'sent_to_meal' => 'أُرسلت إلى المتابعة والتقييم',
    'start_final_review' => 'بدء المراجعة النهائية',
    'start_final_review_confirm' => 'هل تريد بدء المراجعة النهائية الآن؟ لن يتمكن مديرو المشاريع من الإبلاغ عن الأشهر بعدها.',
    'what_should_change' => 'ما الذي يجب أن يغيّره مدير المشروع؟',
    'why_cancel' => 'لماذا تُلغى الخطة؟',
```

- [ ] **Step 7: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanWorkflowScreenTest.php tests/Feature/LocalizationTest.php` and PHPStan.
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add app/Livewire/Concerns/ManagesPlanWorkflow.php tests/PHPStan/PlanWorkflowHost.php resources/views/pages/annual-plans lang/en/annual_plans.php lang/ar/annual_plans.php tests/Feature/AnnualPlans/PlanWorkflowScreenTest.php
```

---

### Task 28: Tracking tab: report, verify, postpone, evidence

**Files:**
- Create: `app/Livewire/Concerns/TracksActualLayer.php`, `tests/PHPStan/ActualLayerHost.php`, `app/Http/Controllers/PlanEvidenceDownloadController.php`
- Modify: `resources/views/pages/annual-plans/_tracking-tab.blade.php`, `⚡show.blade.php` (use trait + `WithFileUploads`), `routes/web.php`, `lang/{en,ar}/annual_plans.php`
- Test: `tests/Feature/AnnualPlans/TrackingTabTest.php`

**Interfaces:**
- Consumes: Task 15 and 16 actions, `OccurrenceSlot`, `PlanBoard::trail()`.
- Produces (trait):
  - properties `selectedActivityId`, `selectedMonth`, `selectedProjectId`, `occurrenceNote`, `postponeTo`, `sendBackReason`, `evidenceUpload`, `evidenceName`, `unplannedCatalogId`
  - methods `selectCell(int, string, ?int)`, `clearCell()`, `reportDone()`, `postponeCell()`, `verifyCell()`, `sendCellBack()`, `markCellMissed()`, `recordUnplanned()`, `uploadEvidence()`, `deleteEvidence(int $mediaId)`, `addUnplannedActivity()`
  - `#[Computed] selectedCell(): ?array`, with the keys below
  - route `annual-plans.evidence.download` → `GET annual-plans/{annualPlan}/evidence/{media}`

`selectedCell()` shape: `activity` (PlanActivity), `monthKey`, `monthLabel`, `projectName`, `isPlanned`, `isMovedIn`, `occurrence` (?PlanOccurrence with `reporter`, `verifier`, `media`), `outcome` (PlanCellOutcome), `trail` (Collection), `can` (array of booleans: `report`, `postpone`, `verify`, `sendBack`, `missed`, `unplanned`, `evidence`), `laterMonths` (list of `[key, label]` for the postpone select).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\OccurrenceStatus;
use App\Models\AnnualPlan;
use App\Models\PlanActivity;
use App\Models\PlanOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-09-20 10:00');
    $this->employee = insertHrEmployee();
    $this->manager = userWithRole('Team Member', $this->employee);
    $this->meal = userWithRole('MEAL');
    $this->plan = AnnualPlan::factory()->between('2027-01', '2027-12')->active()->create(['manager_id' => $this->employee]);
    $this->activity = PlanActivity::factory()->inMonths('2027-09', '2027-10')->create(['annual_plan_id' => $this->plan->id]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('the manager selects a month, reports it with a note and meal verifies it', function () {
    Livewire::actingAs($this->manager)->test('pages::annual-plans.show', ['annualPlan' => $this->plan])
        ->assertSet('activeTab', 'tracking')
        ->call('selectCell', $this->activity->id, '2027-09', null)
        ->set('occurrenceNote', '22 trainees')
        ->call('reportDone')
        ->assertHasNoErrors();

    Livewire::actingAs($this->meal)->test('pages::annual-plans.show', ['annualPlan' => $this->plan])
        ->call('selectCell', $this->activity->id, '2027-09', null)
        ->assertSeeText('22 trainees')
        ->call('verifyCell')
        ->assertHasNoErrors();

    expect(PlanOccurrence::query()->sole()->status)->toBe(OccurrenceStatus::Verified);
});

test('a manager postpones to a later month from the panel', function () {
    Livewire::actingAs($this->manager)->test('pages::annual-plans.show', ['annualPlan' => $this->plan])
        ->call('selectCell', $this->activity->id, '2027-09', null)
        ->set('postponeTo', '2027-11')
        ->call('postponeCell')
        ->assertHasNoErrors();

    expect(PlanOccurrence::query()->sole()->moved_to->toDateString())->toBe('2027-11-01');
});

test('a row id from another plan is refused', function () {
    $foreign = PlanActivity::factory()->inMonths('2027-09')->create();

    Livewire::actingAs($this->manager)->test('pages::annual-plans.show', ['annualPlan' => $this->plan])
        ->call('selectCell', $foreign->id, '2027-09', null)
        ->assertNotFound();
});

test('evidence is uploaded on a reported month and downloads only through its own plan', function () {
    Storage::fake('local');
    $occurrence = PlanOccurrence::factory()->reported()->create(['plan_activity_id' => $this->activity->id, 'month' => '2027-09-01']);

    Livewire::actingAs($this->manager)->test('pages::annual-plans.show', ['annualPlan' => $this->plan])
        ->call('selectCell', $this->activity->id, '2027-09', null)
        ->set('evidenceUpload', validUpload('attendance.pdf'))
        ->call('uploadEvidence')
        ->assertHasNoErrors();

    $media = $occurrence->media()->sole();
    $other = AnnualPlan::factory()->active()->create();

    $this->actingAs($this->manager)->get(route('annual-plans.evidence.download', [$this->plan, $media]))->assertOk();
    $this->actingAs($this->manager)->get(route('annual-plans.evidence.download', [$other, $media]))->assertNotFound();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/TrackingTabTest.php`
Expected: FAIL.

- [ ] **Step 3: The trait**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\AnnualPlans\AddUnplannedActivity;
use App\Actions\AnnualPlans\DeleteOccurrenceEvidence;
use App\Actions\AnnualPlans\MarkOccurrenceMissed;
use App\Actions\AnnualPlans\PostponeOccurrence;
use App\Actions\AnnualPlans\RecordUnplannedOccurrence;
use App\Actions\AnnualPlans\ReportOccurrence;
use App\Actions\AnnualPlans\SaveOccurrenceEvidence;
use App\Actions\AnnualPlans\SendOccurrenceBack;
use App\Actions\AnnualPlans\VerifyOccurrence;
use App\Enums\FileType;
use App\Enums\OccurrenceStatus;
use App\Enums\PlanCellOutcome;
use App\Models\AnnualPlan;
use App\Models\CatalogActivity;
use App\Models\Media;
use App\Models\PlanActivity;
use App\Models\PlanOccurrence;
use App\Models\Project;
use App\Rules\SecureUpload;
use App\Support\Plans\OccurrenceSlot;
use App\Support\Plans\PlanBoard;
use App\Support\Plans\PlanPeriod;
use Flux\Flux;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/**
 * The tracking tab: one selected cell at a time, with the actions the
 * viewer may take on it. Ids arrive from the browser, so every lookup is
 * scoped to this plan (IDOR guard) and every write goes through an action
 * that authorizes it.
 *
 * @property-read AnnualPlan $plan  #[Computed] plan() on the page
 * @property-read array<string, mixed>|null $selectedCell  #[Computed] selectedCell()
 */
trait TracksActualLayer
{
    public ?int $selectedActivityId = null;

    public string $selectedMonth = '';

    public ?int $selectedProjectId = null;

    public string $occurrenceNote = '';

    public string $postponeTo = '';

    public string $sendBackReason = '';

    /** @var UploadedFile|null */
    public $evidenceUpload = null;

    public string $evidenceName = '';

    public string $unplannedCatalogId = '';

    abstract protected function forgetPlan(): void;

    public function selectCell(int $activityId, string $monthKey, ?int $projectId): void
    {
        $this->ownActivityForTracking($activityId);

        $this->resetValidation();
        $this->selectedActivityId = $activityId;
        $this->selectedMonth = $monthKey;
        $this->selectedProjectId = $projectId;
        $this->occurrenceNote = '';
        $this->postponeTo = '';
        $this->sendBackReason = '';
        $this->evidenceUpload = null;
        $this->evidenceName = '';
        unset($this->selectedCell);
    }

    public function clearCell(): void
    {
        $this->selectedActivityId = null;
        $this->selectedMonth = '';
        $this->selectedProjectId = null;
        unset($this->selectedCell);
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function selectedCell(): ?array
    {
        if ($this->selectedActivityId === null) {
            return null;
        }

        $slot = $this->slot();
        $occurrence = $slot->occurrence()?->load(['reporter:id,hr_user_id', 'verifier:id,hr_user_id', 'media']);
        $board = collect($this->board['rows'])->firstWhere('id', $slot->activity->id);
        $cells = $slot->projectId === null ? $board['cells'] : (collect($board['projects'])->firstWhere('id', $slot->projectId)['cells'] ?? []);
        $cell = collect($cells)->firstWhere('key', $this->selectedMonth);
        $plan = $this->plan;
        $canReport = Gate::allows('report', $plan);
        $canVerify = Gate::allows('verify', $plan);
        $status = $occurrence?->status;
        $expected = $slot->isExpected();

        return [
            'activity' => $slot->activity,
            'monthKey' => $this->selectedMonth,
            'monthLabel' => $slot->month->translatedFormat(__('formats.month_year')),
            'projectName' => $slot->projectId === null ? null : Project::query()->whereKey($slot->projectId)->value('name'),
            'isPlanned' => $slot->isPlanned(),
            'isMovedIn' => $slot->isMovedIn(),
            'occurrence' => $occurrence,
            'outcome' => $cell['outcome'] ?? PlanCellOutcome::None,
            'trail' => app(PlanBoard::class)->trail($plan, $slot->activity->id, $this->selectedMonth, $slot->projectId),
            'can' => [
                'report' => $canReport && $slot->hasStarted() && ($status === null || $status === OccurrenceStatus::SentBack),
                'postpone' => Gate::allows('track', $plan) && $expected && ! in_array($status, [OccurrenceStatus::Verified, OccurrenceStatus::Missed], true),
                'verify' => $canVerify && $status === OccurrenceStatus::Reported,
                'sendBack' => $canVerify && in_array($status, [OccurrenceStatus::Reported, OccurrenceStatus::Postponed], true),
                'missed' => $canVerify && $expected && $slot->hasStarted() && $status !== OccurrenceStatus::Verified && $status !== OccurrenceStatus::Missed,
                'unplanned' => $canVerify && ! $expected && $slot->hasStarted() && $status === null,
                'evidence' => Gate::allows('track', $plan) && $occurrence !== null,
            ],
            'laterMonths' => array_values(array_map(
                fn ($month): array => ['key' => PlanPeriod::key($month), 'label' => $month->translatedFormat(__('formats.month_year'))],
                array_filter(PlanPeriod::months($plan->starts_on, $plan->ends_on), fn ($month): bool => $month->gt($slot->month)),
            )),
        ];
    }

    public function reportDone(): void
    {
        $this->validate(['occurrenceNote' => ['nullable', 'string', 'max:2000']]);

        app(ReportOccurrence::class)->handle($this->slot(), $this->noteOrNull());

        $this->afterTracking(__('annual_plans.month_reported'));
    }

    public function postponeCell(): void
    {
        $this->validate(['postponeTo' => ['required', 'date_format:Y-m'], 'occurrenceNote' => ['nullable', 'string', 'max:2000']], [
            'postponeTo.required' => __('annual_plans.choose_a_later_month_inside_the_plan'),
        ]);

        app(PostponeOccurrence::class)->handle($this->slot(), PlanPeriod::fromKey($this->postponeTo), $this->noteOrNull());

        $this->afterTracking(__('annual_plans.month_postponed'));
    }

    public function verifyCell(): void
    {
        app(VerifyOccurrence::class)->handle($this->selectedOccurrence());

        $this->afterTracking(__('annual_plans.month_verified'));
    }

    public function sendCellBack(): void
    {
        app(SendOccurrenceBack::class)->handle($this->selectedOccurrence(), $this->sendBackReason);

        $this->afterTracking(__('annual_plans.report_sent_back'));
    }

    public function markCellMissed(): void
    {
        app(MarkOccurrenceMissed::class)->handle($this->slot(), $this->noteOrNull());

        $this->afterTracking(__('annual_plans.month_marked_missed'));
    }

    public function recordUnplanned(): void
    {
        app(RecordUnplannedOccurrence::class)->handle($this->slot(), $this->noteOrNull());

        $this->afterTracking(__('annual_plans.unplanned_work_recorded'));
    }

    public function uploadEvidence(): void
    {
        $allowed = implode(',', FileType::values());

        $this->validate([
            'evidenceUpload' => ['required', 'bail', 'file', 'max:5120', 'mimes:'.$allowed, 'extensions:'.$allowed, new SecureUpload],
            'evidenceName' => ['nullable', 'string', 'max:255'],
        ], [
            'evidenceUpload.required' => __('annual_plans.choose_a_file_to_attach'),
        ]);

        app(SaveOccurrenceEvidence::class)->handle($this->selectedOccurrence(), $this->evidenceUpload, $this->evidenceName);

        $this->evidenceUpload = null;
        $this->evidenceName = '';
        $this->afterTracking(__('annual_plans.evidence_attached'));
    }

    public function deleteEvidence(int $mediaId): void
    {
        $occurrence = $this->selectedOccurrence();
        $media = Media::query()->where('mediable_type', 'plan_occurrence')->where('mediable_id', $occurrence->id)->findOrFail($mediaId);

        app(DeleteOccurrenceEvidence::class)->handle($occurrence, $media);

        $this->afterTracking(__('annual_plans.evidence_removed'));
    }

    public function addUnplannedActivity(): void
    {
        $this->validate(['unplannedCatalogId' => ['required', 'integer', Rule::exists(CatalogActivity::class, 'id')]], [
            'unplannedCatalogId.required' => __('annual_plans.choose_an_activity_from_the_catalog'),
        ]);

        app(AddUnplannedActivity::class)->handle($this->plan, CatalogActivity::query()->findOrFail((int) $this->unplannedCatalogId));

        $this->unplannedCatalogId = '';
        $this->afterTracking(__('annual_plans.unplanned_activity_added'));
    }

    private function slot(): OccurrenceSlot
    {
        return OccurrenceSlot::resolve($this->ownActivityForTracking((int) $this->selectedActivityId), $this->selectedMonth, $this->selectedProjectId);
    }

    private function selectedOccurrence(): PlanOccurrence
    {
        return $this->slot()->occurrence() ?? abort(404);
    }

    private function ownActivityForTracking(int $activityId): PlanActivity
    {
        return PlanActivity::query()->where('annual_plan_id', $this->plan->id)->findOrFail($activityId);
    }

    private function noteOrNull(): ?string
    {
        $note = trim($this->occurrenceNote);

        return $note === '' ? null : $note;
    }

    private function afterTracking(string $toast): void
    {
        $this->occurrenceNote = '';
        $this->postponeTo = '';
        $this->sendBackReason = '';
        unset($this->selectedCell);
        $this->forgetPlan();

        Flux::toast(variant: 'success', text: $toast);
    }
}
```

> `$this->board` is read inside the trait. Add `@property-read array{months: list<array<string, mixed>>, rows: list<array<string, mixed>>, totals: array<string, int|null>} $board` to the trait docblock.

Add `use TracksActualLayer, WithFileUploads;` to the page class. `PHPStan` host: `tests/PHPStan/ActualLayerHost.php` with `use TracksActualLayer;`.

- [ ] **Step 4: Evidence download**

`app/Http/Controllers/PlanEvidenceDownloadController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AnnualPlan;
use App\Models\Media;
use App\Models\PlanActivity;
use App\Models\PlanOccurrence;
use App\Support\UploadName;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlanEvidenceDownloadController extends Controller
{
    /**
     * Stream an evidence file. The media must hang off an occurrence of an
     * activity of THIS plan (IDOR guard, as for project files).
     */
    public function __invoke(AnnualPlan $annualPlan, Media $media): StreamedResponse
    {
        Gate::authorize('view', $annualPlan);

        $belongs = $media->mediable_type === 'plan_occurrence'
            && PlanOccurrence::query()
                ->whereKey($media->mediable_id)
                ->whereIn('plan_activity_id', PlanActivity::query()->where('annual_plan_id', $annualPlan->id)->select('id'))
                ->exists();

        abort_unless($belongs, 404);
        abort_unless(Storage::disk($media->disk)->exists($media->path), 404);

        return Storage::disk($media->disk)->download($media->path, UploadName::clean($media->original_name), [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
```

`routes/web.php`, next to the annual-plans routes:

```php
    Route::get('annual-plans/{annualPlan}/evidence/{media}', PlanEvidenceDownloadController::class)
        ->can('annual-plans.view')
        ->middleware('throttle:files')
        ->name('annual-plans.evidence.download');
```

(+ `use App\Http\Controllers\PlanEvidenceDownloadController;`). Read `.ai/rules/routes.md` first.

- [ ] **Step 5: `_tracking-tab.blade.php`**

```blade
@php
    $totals = $this->board['totals'];
    $cell = $this->selectedCell;
    $selectedKey = $selectedActivityId === null ? null : $selectedActivityId.':'.$selectedMonth.':'.($selectedProjectId ?? 0);
@endphp

<div class="flex flex-col gap-6">
    <p class="flex flex-wrap gap-x-5 gap-y-1 text-sm tabular-nums text-secondary-medium dark:text-ink-muted" data-test="tracking-headline">
        <span><strong class="text-secondary-dark dark:text-ink">{{ $totals['onPlan'] }}</strong> {{ __('enums.plan_cell_outcome.on_plan') }}</span>
        <span><strong class="text-secondary-dark dark:text-ink">{{ $totals['pending'] }}</strong> {{ __('enums.plan_cell_outcome.pending') }}</span>
        <span><strong class="text-secondary-dark dark:text-ink">{{ $totals['postponed'] }}</strong> {{ __('enums.plan_cell_outcome.postponed') }}</span>
        <span><strong class="text-status-critical">{{ $totals['due'] }}</strong> {{ __('enums.plan_cell_outcome.due') }}</span>
        <span><strong class="text-status-critical">{{ $totals['missed'] }}</strong> {{ __('enums.plan_cell_outcome.missed') }}</span>
        <span><strong class="text-secondary-dark dark:text-ink">{{ $totals['unplanned'] }}</strong> {{ __('enums.plan_cell_outcome.unplanned') }}</span>
    </p>

    <x-plans.legend />

    <div class="flex flex-wrap items-start gap-10">
        <section class="min-w-0 flex-[999_1_40rem]" aria-label="{{ __('annual_plans.actual_layer') }}">
            <x-plans.month-grid
                :months="$this->board['months']"
                :rows="$this->board['rows']"
                mode="actual"
                :selected="$selectedKey"
                :can-set-targets="auth()->user()->can('review', $plan)"
            />

            @can('track', $plan)
                <form wire:submit="addUnplannedActivity" class="mt-5 flex flex-wrap items-end gap-3">
                    <x-ui.form-select wire:model="unplannedCatalogId" :label="__('annual_plans.add_unplanned_activity')" :placeholder="__('annual_plans.choose_an_activity')" class="min-w-64">
                        @foreach ($this->catalogOptions as $catalogOption)
                            <option value="{{ $catalogOption->id }}">{{ $catalogOption->name }}</option>
                        @endforeach
                    </x-ui.form-select>
                    <x-ui.button type="submit" tone="secondary" icon="plus" class="min-h-11">{{ __('annual_plans.add') }}</x-ui.button>
                    <p class="basis-full type-meta text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.unplanned_activity_hint') }}</p>
                </form>
            @endcan
        </section>

        <aside class="flex min-w-0 flex-[1_1_20rem] flex-col gap-4" aria-live="polite" data-test="cell-panel">
            @if ($cell === null)
                <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.select_a_month_to_see_it') }}</p>
            @else
                <div>
                    <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.selected_month') }}</p>
                    <h3 class="text-2xl font-bold leading-tight">{{ $cell['activity']->name() }} · {{ $cell['monthLabel'] }}</h3>
                    @if ($cell['projectName'] !== null)<p class="text-sm">{{ $cell['projectName'] }}</p>@endif
                    <p class="text-sm text-secondary-medium dark:text-ink-muted">{{ $cell['isPlanned'] ? __('annual_plans.in_the_planned_baseline') : ($cell['isMovedIn'] ? __('annual_plans.moved_here_by_a_postponement') : __('annual_plans.not_in_the_planned_baseline')) }}</p>
                    <p class="mt-1 inline-flex items-center gap-2 text-base font-semibold"><x-plans.mark :outcome="$cell['outcome']" /> {{ $cell['outcome']->label() }}</p>
                </div>

                @if ($cell['occurrence'] !== null)
                    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                        @if ($cell['occurrence']->reporter !== null)
                            <dt class="text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.reported_by') }}</dt>
                            <dd>{{ $cell['occurrence']->reporter->name }} · {{ $cell['occurrence']->reported_at?->translatedFormat(__('formats.date')) }}</dd>
                        @endif
                        @if ($cell['occurrence']->verifier !== null)
                            <dt class="text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.settled_by') }}</dt>
                            <dd>{{ $cell['occurrence']->verifier->name }} · {{ $cell['occurrence']->verified_at?->translatedFormat(__('formats.date')) }}</dd>
                        @endif
                        @if ($cell['occurrence']->moved_to !== null)
                            <dt class="text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.moved_to') }}</dt>
                            <dd>{{ $cell['occurrence']->moved_to->translatedFormat(__('formats.month_year')) }}</dd>
                        @endif
                        @if (filled($cell['occurrence']->note))
                            <dt class="text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.note') }}</dt>
                            <dd>{{ $cell['occurrence']->note }}</dd>
                        @endif
                    </dl>
                @endif

                @if (in_array(true, $cell['can'], true))
                    <div class="flex flex-col gap-3">
                        @if ($cell['can']['report'] || $cell['can']['postpone'] || $cell['can']['missed'] || $cell['can']['unplanned'])
                            <x-ui.form-textarea wire:model="occurrenceNote" :label="__('annual_plans.note_optional')" rows="2" />
                        @endif
                        <div class="flex flex-wrap gap-2">
                            @if ($cell['can']['report'])<x-ui.button tone="primary" icon="check" wire:click="reportDone">{{ __('annual_plans.report_done') }}</x-ui.button>@endif
                            @if ($cell['can']['verify'])<x-ui.button tone="primary" icon="check-badge" wire:click="verifyCell">{{ __('annual_plans.verify') }}</x-ui.button>@endif
                            @if ($cell['can']['missed'])<x-ui.button tone="secondary" icon="x-mark" wire:click="markCellMissed">{{ __('annual_plans.mark_missed') }}</x-ui.button>@endif
                            @if ($cell['can']['unplanned'])<x-ui.button tone="primary" icon="plus" wire:click="recordUnplanned">{{ __('annual_plans.record_unplanned_here') }}</x-ui.button>@endif
                        </div>
                        @if ($cell['can']['postpone'] && $cell['laterMonths'] !== [])
                            <div class="flex flex-wrap items-end gap-2">
                                <x-ui.form-select wire:model="postponeTo" :label="__('annual_plans.postpone_to')" :placeholder="__('annual_plans.choose_a_month')">
                                    @foreach ($cell['laterMonths'] as $later)
                                        <option value="{{ $later['key'] }}">{{ $later['label'] }}</option>
                                    @endforeach
                                </x-ui.form-select>
                                <x-ui.button tone="secondary" icon="arrow-right" wire:click="postponeCell">{{ __('annual_plans.postpone') }}</x-ui.button>
                            </div>
                        @endif
                        @if ($cell['can']['sendBack'])
                            <div class="flex flex-wrap items-end gap-2">
                                <x-ui.form-input wire:model="sendBackReason" :label="__('annual_plans.send_back_reason')" class="min-w-56 flex-1" />
                                <x-ui.button tone="secondary" icon="arrow-uturn-left" wire:click="sendCellBack">{{ __('annual_plans.send_back') }}</x-ui.button>
                            </div>
                        @endif
                        @error('slot')<p class="type-meta text-status-critical" role="alert">{{ $message }}</p>@enderror
                    </div>
                @endif

                @if ($cell['occurrence'] !== null)
                    <section class="flex flex-col gap-2 border-t border-secondary-soft pt-3 dark:border-line">
                        <h4 class="type-label text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.evidence') }}</h4>
                        @forelse ($cell['occurrence']->media as $file)
                            <div class="flex items-center justify-between gap-2 text-sm" wire:key="evidence-{{ $file->id }}">
                                <a href="{{ route('annual-plans.evidence.download', [$plan, $file]) }}" class="truncate underline">{{ $file->original_name }}</a>
                                @if ($cell['can']['evidence'])
                                    <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9!" wire:click="deleteEvidence({{ $file->id }})" wire:confirm="{{ __('annual_plans.remove_evidence_confirm') }}" :aria-label="__('annual_plans.remove_evidence', ['name' => $file->original_name])" />
                                @endif
                            </div>
                        @empty
                            <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.no_evidence_yet') }}</p>
                        @endforelse
                        @if ($cell['can']['evidence'])
                            <form wire:submit="uploadEvidence" class="flex flex-col gap-2">
                                <x-ui.form-file wire:model="evidenceUpload" :label="__('annual_plans.attach_evidence')" :types="implode(', ', \App\Enums\FileType::values())" />
                                <x-ui.form-input wire:model="evidenceName" :label="__('annual_plans.file_display_name_optional')" />
                                <x-ui.button type="submit" tone="secondary" icon="paper-clip">{{ __('annual_plans.attach') }}</x-ui.button>
                            </form>
                        @endif
                    </section>
                @endif

                <section class="flex flex-col gap-2 border-t border-secondary-soft pt-3 dark:border-line">
                    <h4 class="type-label text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.this_months_trail') }}</h4>
                    @forelse ($cell['trail'] as $entry)
                        <p class="text-sm" wire:key="trail-{{ $entry->id }}"><span class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted">{{ $entry->created_at->translatedFormat(__('formats.datetime')) }} · {{ $entry->user?->name ?? __('notifications.system') }}</span><br>{{ $entry->action->label() }}@if (filled($entry->note)): “{{ $entry->note }}”@endif</p>
                    @empty
                        <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.nothing_recorded_yet') }}</p>
                    @endforelse
                </section>

                <x-ui.button tone="ghost" size="sm" wire:click="clearCell">{{ __('annual_plans.close_panel') }}</x-ui.button>
            @endif
        </aside>
    </div>
</div>
```

(`catalogOptions` comes from `EditsPlannedLayer`, Task 26. Check the `x-ui.form-file` props: it takes `types` / `accept`; mirror the project files form.)

- [ ] **Step 6: Lang** (en / ar)

```php
    'actual_layer' => 'Actual layer',
    'add' => 'Add',
    'add_unplanned_activity' => 'Add an unplanned activity',
    'attach' => 'Attach',
    'attach_evidence' => 'Attach evidence',
    'choose_a_file_to_attach' => 'Choose a file to attach.',
    'choose_a_month' => 'Choose a month',
    'close_panel' => 'Close',
    'evidence' => 'Evidence',
    'evidence_attached' => 'Evidence attached',
    'evidence_removed' => 'Evidence removed',
    'file_display_name_optional' => 'Display name (optional)',
    'in_the_planned_baseline' => 'In the planned baseline',
    'mark_missed' => 'Mark missed',
    'month_marked_missed' => 'Month marked missed',
    'month_postponed' => 'Month postponed',
    'month_reported' => 'Month reported to MEAL',
    'month_verified' => 'Month verified',
    'moved_here_by_a_postponement' => 'Moved here by a postponement',
    'moved_to' => 'Moved to',
    'no_evidence_yet' => 'No files attached yet.',
    'not_in_the_planned_baseline' => 'Not in the planned baseline',
    'note' => 'Note',
    'note_optional' => 'Note (optional)',
    'nothing_recorded_yet' => 'Nothing recorded yet.',
    'postpone' => 'Postpone',
    'postpone_to' => 'Postpone to',
    'record_unplanned_here' => 'Record unplanned work here',
    'remove_evidence' => 'Remove :name',
    'remove_evidence_confirm' => 'Remove this file? Its copy on the server is deleted too.',
    'report_done' => 'Report done',
    'report_sent_back' => 'Report sent back',
    'reported_by' => 'Reported by',
    'select_a_month_to_see_it' => 'Select a month in the grid to report it, verify it or see its trail.',
    'selected_month' => 'Selected month',
    'send_back' => 'Send back',
    'send_back_reason' => 'What is missing?',
    'settled_by' => 'Settled by',
    'this_months_trail' => 'This month’s trail',
    'unplanned_activity_added' => 'Unplanned activity added',
    'unplanned_activity_hint' => 'Project managers and MEAL can add anything that happened outside the plan. The planned baseline stays untouched.',
    'unplanned_work_recorded' => 'Unplanned work recorded',
    'verify' => 'Verify',
```

```php
    'actual_layer' => 'الطبقة الفعلية',
    'add' => 'إضافة',
    'add_unplanned_activity' => 'إضافة نشاط غير مخطط',
    'attach' => 'إرفاق',
    'attach_evidence' => 'إرفاق دليل',
    'choose_a_file_to_attach' => 'اختر ملفًا لإرفاقه.',
    'choose_a_month' => 'اختر شهرًا',
    'close_panel' => 'إغلاق',
    'evidence' => 'الأدلة',
    'evidence_attached' => 'أُرفق الدليل',
    'evidence_removed' => 'أُزيل الدليل',
    'file_display_name_optional' => 'الاسم المعروض (اختياري)',
    'in_the_planned_baseline' => 'ضمن الخطة الأساسية',
    'mark_missed' => 'تسجيل كغير منفّذ',
    'month_marked_missed' => 'سُجّل الشهر كغير منفّذ',
    'month_postponed' => 'أُجّل الشهر',
    'month_reported' => 'أُبلغ عن الشهر إلى المتابعة والتقييم',
    'month_verified' => 'تم التحقق من الشهر',
    'moved_here_by_a_postponement' => 'نُقل إلى هنا بسبب تأجيل',
    'moved_to' => 'نُقل إلى',
    'no_evidence_yet' => 'لا توجد ملفات مرفقة بعد.',
    'not_in_the_planned_baseline' => 'خارج الخطة الأساسية',
    'note' => 'ملاحظة',
    'note_optional' => 'ملاحظة (اختياري)',
    'nothing_recorded_yet' => 'لم يُسجَّل شيء بعد.',
    'postpone' => 'تأجيل',
    'postpone_to' => 'تأجيل إلى',
    'record_unplanned_here' => 'تسجيل عمل غير مخطط هنا',
    'remove_evidence' => 'إزالة :name',
    'remove_evidence_confirm' => 'هل تريد إزالة هذا الملف؟ تُحذف نسخته من الخادم أيضًا.',
    'report_done' => 'الإبلاغ عن التنفيذ',
    'report_sent_back' => 'أُعيد التقرير',
    'reported_by' => 'أبلغ عنه',
    'select_a_month_to_see_it' => 'اختر شهرًا من الجدول للإبلاغ عنه أو التحقق منه أو رؤية سجله.',
    'selected_month' => 'الشهر المحدد',
    'send_back' => 'إعادة',
    'send_back_reason' => 'ما الذي ينقص؟',
    'settled_by' => 'سوّاه',
    'this_months_trail' => 'سجل هذا الشهر',
    'unplanned_activity_added' => 'أُضيف نشاط غير مخطط',
    'unplanned_activity_hint' => 'يمكن لمديري المشاريع والمتابعة والتقييم إضافة أي شيء حدث خارج الخطة. تبقى الخطة الأساسية كما هي.',
    'unplanned_work_recorded' => 'سُجّل عمل غير مخطط',
    'verify' => 'تحقق',
```

- [ ] **Step 7: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/TrackingTabTest.php tests/Feature/LocalizationTest.php` and PHPStan.
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add app/Livewire/Concerns/TracksActualLayer.php tests/PHPStan/ActualLayerHost.php app/Http/Controllers/PlanEvidenceDownloadController.php routes/web.php resources/views/pages/annual-plans lang/en/annual_plans.php lang/ar/annual_plans.php tests/Feature/AnnualPlans/TrackingTabTest.php
```

---

### Task 29: Target and milestone dialogs (MEAL)

**Files:**
- Create: `app/Livewire/Concerns/ManagesPlanTargets.php`, `tests/PHPStan/PlanTargetsHost.php`
- Modify: `resources/views/pages/annual-plans/_workflow-modals.blade.php` (append the two dialogs), `⚡show.blade.php` (use trait), `lang/{en,ar}/annual_plans.php`
- Test: `tests/Feature/AnnualPlans/PlanTargetsScreenTest.php`

**Interfaces:**
- Consumes: `SetActivityTarget`, `SetActivityMilestone`.
- Produces (trait):
  - properties `showTargetModal`, `targetActivityId`, `targetIndicatorId`, `targetValue`, `targetPeriod`, `targetProjectId`, `showMilestoneModal`, `milestoneName`, `milestoneStart`, `milestoneEnd`, `milestoneResponsibleId`
  - methods `openTargetModal(int)`, `saveTarget()`, `openMilestoneModal()`, `saveMilestone()`
  - `#[Computed] indicatorOptions()`, `#[Computed] targetActivity()`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\Period;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\Milestone;
use App\Models\PlanActivity;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Result;
use Livewire\Livewire;

test('meal sets a per-month target from the grid', function () {
    $plan = AnnualPlan::factory()->between('2027-01', '2027-12')->submitted()->create();
    $activity = PlanActivity::factory()->inMonths('2027-02')->create(['annual_plan_id' => $plan->id]);
    $indicator = Indicator::factory()->create(['result_id' => Result::factory()->create(['program_id' => $plan->program_id])->id]);

    Livewire::actingAs(userWithRole('MEAL'))
        ->test('pages::annual-plans.show', ['annualPlan' => $plan])
        ->call('openTargetModal', $activity->id)
        ->assertSet('showTargetModal', true)
        ->set('targetIndicatorId', (string) $indicator->id)
        ->set('targetValue', '20')
        ->set('targetPeriod', Period::Monthly->value)
        ->call('saveTarget')
        ->assertHasNoErrors()
        ->assertSet('showTargetModal', false);

    expect(PlanIndicator::query()->sole()->plan_activity_id)->toBe($activity->id);
});

test('meal sets a milestone on a project plan activity', function () {
    $project = Project::factory()->create();
    $plan = AnnualPlan::factory()->forProject($project)->between('2027-01', '2027-12')->submitted()->create();
    $activity = PlanActivity::factory()->create(['annual_plan_id' => $plan->id]);

    Livewire::actingAs(userWithRole('MEAL'))
        ->test('pages::annual-plans.show', ['annualPlan' => $plan])
        ->call('openTargetModal', $activity->id)
        ->call('openMilestoneModal')
        ->set('milestoneName', 'Masters cohort graduates')
        ->set('milestoneStart', '2027-08-01')
        ->set('milestoneResponsibleId', (string) insertHrEmployee())
        ->call('saveMilestone')
        ->assertHasNoErrors();

    expect(Milestone::query()->sole()->plan_activity_id)->toBe($activity->id);
});

test('the manager cannot open the target dialog', function () {
    $employee = insertHrEmployee();
    $plan = AnnualPlan::factory()->submitted()->create(['manager_id' => $employee]);
    $activity = PlanActivity::factory()->create(['annual_plan_id' => $plan->id]);

    Livewire::actingAs(userWithRole('Team Member', $employee))
        ->test('pages::annual-plans.show', ['annualPlan' => $plan])
        ->call('openTargetModal', $activity->id)
        ->assertForbidden();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanTargetsScreenTest.php`
Expected: FAIL.

- [ ] **Step 3: The trait**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\AnnualPlans\SetActivityMilestone;
use App\Actions\AnnualPlans\SetActivityTarget;
use App\Enums\Period;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\PlanActivity;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * MEAL turns an activity's quantity into a target or (project plans) a
 * milestone, from the grid's Target button.
 *
 * @property-read AnnualPlan $plan  #[Computed] plan() on the page
 */
trait ManagesPlanTargets
{
    public bool $showTargetModal = false;

    #[Locked]
    public ?int $targetActivityId = null;

    public string $targetIndicatorId = '';

    public string $targetValue = '';

    public string $targetPeriod = 'plan';

    public string $targetProjectId = '';

    public bool $showMilestoneModal = false;

    public string $milestoneName = '';

    public string $milestoneStart = '';

    public string $milestoneEnd = '';

    public string $milestoneResponsibleId = '';

    abstract protected function forgetPlan(): void;

    #[Computed]
    public function targetActivity(): ?PlanActivity
    {
        return $this->targetActivityId === null
            ? null
            : PlanActivity::query()->with('catalogActivity:id,name')->where('annual_plan_id', $this->plan->id)->find($this->targetActivityId);
    }

    /**
     * Indicators on results of the plan's program (the chain rule), plus
     * indicators scoped to the plan's project.
     *
     * @return Collection<int, Indicator>
     */
    #[Computed]
    public function indicatorOptions(): Collection
    {
        return Indicator::query()
            ->select(['id', 'name', 'result_id', 'project_id'])
            ->whereHas('result', fn (Builder $query) => $query->where('program_id', $this->plan->program_id))
            ->when($this->plan->project_id !== null, fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereNull('project_id')->orWhere('project_id', $this->plan->project_id)))
            ->orderBy('name')
            ->get();
    }

    public function openTargetModal(int $activityId): void
    {
        Gate::authorize('review', $this->plan);

        PlanActivity::query()->where('annual_plan_id', $this->plan->id)->findOrFail($activityId);

        $this->resetValidation();
        $this->targetActivityId = $activityId;
        $this->targetIndicatorId = '';
        $this->targetValue = '';
        $this->targetPeriod = Period::Plan->value;
        $this->targetProjectId = $this->plan->project_id === null ? '' : (string) $this->plan->project_id;
        unset($this->targetActivity);
        $this->showTargetModal = true;
    }

    public function saveTarget(): void
    {
        $validated = $this->validate([
            'targetIndicatorId' => ['required', 'integer'],
            'targetValue' => ['required', 'numeric', 'gt:0'],
            'targetPeriod' => ['required', Rule::enum(Period::class)],
            'targetProjectId' => ['nullable', 'integer'],
        ], [
            'targetIndicatorId.required' => __('annual_plans.choose_an_indicator'),
        ]);

        app(SetActivityTarget::class)->handle($this->targetActivity ?? abort(404), [
            'indicatorId' => (int) $validated['targetIndicatorId'],
            'target' => (string) $validated['targetValue'],
            'period' => $validated['targetPeriod'],
            'projectId' => $validated['targetProjectId'] === '' || $validated['targetProjectId'] === null ? null : (int) $validated['targetProjectId'],
        ]);

        $this->showTargetModal = false;
        $this->forgetPlan();

        Flux::toast(variant: 'success', text: __('annual_plans.target_set'));
    }

    public function openMilestoneModal(): void
    {
        Gate::authorize('review', $this->plan);

        $this->showTargetModal = false;
        $this->milestoneName = '';
        $this->milestoneStart = '';
        $this->milestoneEnd = '';
        $this->milestoneResponsibleId = '';
        $this->showMilestoneModal = true;
    }

    public function saveMilestone(): void
    {
        $validated = $this->validate([
            'milestoneName' => ['required', 'string', 'max:255'],
            'milestoneStart' => ['required', 'date'],
            'milestoneEnd' => ['nullable', 'date', 'after_or_equal:milestoneStart'],
            'milestoneResponsibleId' => ['required', 'integer'],
        ]);

        app(SetActivityMilestone::class)->handle($this->targetActivity ?? abort(404), [
            'name' => $validated['milestoneName'],
            'plannedStart' => $validated['milestoneStart'],
            'plannedEnd' => $validated['milestoneEnd'] === '' ? null : $validated['milestoneEnd'],
            'responsibleEmployeeId' => (int) $validated['milestoneResponsibleId'],
        ]);

        $this->showMilestoneModal = false;
        $this->forgetPlan();

        Flux::toast(variant: 'success', text: __('annual_plans.milestone_set'));
    }
}
```

Host: `tests/PHPStan/PlanTargetsHost.php` with `use ManagesPlanTargets;`. Page: `use ManagesPlanTargets;`.

- [ ] **Step 4: Dialogs** (append to `_workflow-modals.blade.php`)

```blade
<x-ui.modal wire:model="showTargetModal" size="md" :title="__('annual_plans.set_a_target')" :description="$this->targetActivity?->name()" submit="saveTarget" close="$set('showTargetModal', false)" required-note>
    @if ($showTargetModal)
        <div class="grid grid-cols-2 gap-4">
            <x-ui.form-select wire:model="targetIndicatorId" :label="__('common.indicator')" :placeholder="__('annual_plans.choose_an_indicator')" required class="col-span-2">
                @foreach ($this->indicatorOptions as $indicatorOption)
                    <option value="{{ $indicatorOption->id }}">{{ $indicatorOption->name }}</option>
                @endforeach
            </x-ui.form-select>
            <x-ui.form-input type="number" step="0.01" min="0" wire:model="targetValue" :label="__('annual_plans.target_value')" required />
            <x-ui.form-select wire:model="targetPeriod" :label="__('annual_plans.counted')" required>
                @foreach (\App\Enums\Period::cases() as $periodOption)
                    <option value="{{ $periodOption->value }}">{{ $periodOption->label() }}</option>
                @endforeach
            </x-ui.form-select>
            @if ($plan->isProgramWide())
                <x-ui.form-select wire:model="targetProjectId" :label="__('annual_plans.delivered_by_project_optional')" :placeholder="__('common.organisation_wide')" class="col-span-2">
                    @foreach ($plan->program->projects()->orderBy('name')->get(['id', 'name']) as $projectOption)
                        <option value="{{ $projectOption->id }}">{{ $projectOption->name }}</option>
                    @endforeach
                </x-ui.form-select>
            @endif
        </div>
        @if (! $plan->isProgramWide())
            <p class="mt-4 type-meta text-secondary-medium dark:text-ink-muted">
                {{ __('annual_plans.or_a_checkpoint') }}
                <button type="button" class="font-semibold underline" wire:click="openMilestoneModal">{{ __('annual_plans.set_a_milestone_instead') }}</button>
            </p>
        @endif
    @endif
    <x-slot:actions>
        <x-ui.button type="submit" tone="primary">{{ __('annual_plans.set_target') }}</x-ui.button>
    </x-slot:actions>
</x-ui.modal>

<x-ui.modal wire:model="showMilestoneModal" size="md" :title="__('annual_plans.set_a_milestone')" :description="$this->targetActivity?->name()" submit="saveMilestone" close="$set('showMilestoneModal', false)" required-note>
    @if ($showMilestoneModal)
        <div class="grid grid-cols-2 gap-4">
            <x-ui.form-input wire:model="milestoneName" :label="__('annual_plans.milestone_name')" required class="col-span-2" />
            <x-ui.form-input type="date" wire:model="milestoneStart" :label="__('annual_plans.milestone_start')" required />
            <x-ui.form-input type="date" wire:model="milestoneEnd" :label="__('annual_plans.milestone_end')" />
            <x-ui.form-select wire:model="milestoneResponsibleId" :label="__('annual_plans.responsible_person')" :placeholder="__('annual_plans.choose_a_person')" required class="col-span-2">
                @foreach ($this->employeeOptions as $employeeOption)
                    <option value="{{ $employeeOption->id }}">{{ $employeeOption->name }}</option>
                @endforeach
            </x-ui.form-select>
        </div>
    @endif
    <x-slot:actions>
        <x-ui.button type="submit" tone="primary">{{ __('annual_plans.set_milestone') }}</x-ui.button>
    </x-slot:actions>
</x-ui.modal>
```

(`Program::projects()` relation: verify it exists. If not, query `Project::where('program_id', $plan->program_id)` through a `#[Computed] programProjects()` in the trait instead of the inline query.)

- [ ] **Step 5: Lang** (en / ar)

```php
    'choose_a_person' => 'Choose a person',
    'choose_an_indicator' => 'Choose an indicator.',
    'counted' => 'Counted',
    'delivered_by_project_optional' => 'Delivered by project (optional)',
    'milestone_end' => 'Planned end',
    'milestone_name' => 'Milestone',
    'milestone_set' => 'Milestone set',
    'milestone_start' => 'Planned start',
    'or_a_checkpoint' => 'A delivery checkpoint rather than a number?',
    'set_a_milestone' => 'Set a milestone',
    'set_a_milestone_instead' => 'Set a milestone instead',
    'set_a_target' => 'Set a target',
    'set_milestone' => 'Set milestone',
    'set_target' => 'Set target',
    'target_set' => 'Target set',
    'target_value' => 'Target',
```

```php
    'choose_a_person' => 'اختر شخصًا',
    'choose_an_indicator' => 'اختر مؤشرًا.',
    'counted' => 'يُحتسب',
    'delivered_by_project_optional' => 'المشروع المنفِّذ (اختياري)',
    'milestone_end' => 'النهاية المخططة',
    'milestone_name' => 'المعلم',
    'milestone_set' => 'حُدّد المعلم',
    'milestone_start' => 'البداية المخططة',
    'or_a_checkpoint' => 'نقطة تسليم بدلًا من رقم؟',
    'set_a_milestone' => 'تحديد معلم',
    'set_a_milestone_instead' => 'حدّد معلمًا بدلًا من ذلك',
    'set_a_target' => 'تحديد مستهدف',
    'set_milestone' => 'تحديد المعلم',
    'set_target' => 'تحديد المستهدف',
    'target_set' => 'حُدّد المستهدف',
    'target_value' => 'المستهدف',
```

- [ ] **Step 6: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanTargetsScreenTest.php tests/Feature/LocalizationTest.php` and PHPStan.
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add app/Livewire/Concerns/ManagesPlanTargets.php tests/PHPStan/PlanTargetsHost.php resources/views/pages/annual-plans lang/en/annual_plans.php lang/ar/annual_plans.php tests/Feature/AnnualPlans/PlanTargetsScreenTest.php
```

---

### Task 30: Review tab: planned vs actual, targets, close

**Files:**
- Modify: `resources/views/pages/annual-plans/_review-tab.blade.php`, `⚡show.blade.php` (one computed), `lang/{en,ar}/annual_plans.php`
- Test: `tests/Feature/AnnualPlans/ReviewTabTest.php`

**Interfaces:**
- Consumes: `board` totals and rows (review mode of `x-plans.month-grid`), the page's existing `targets` computed (`PlanIndicator::achievedValue()` / `percentAchieved()` per `.ai/rules/resultsindicatorstargetsreports.md`; never a local formula).
- Produces: `#[Computed] postponedLeftovers(): list<array{activity: string, month: string, project: string|null}>` (postponed cells whose moved-to month never got verified, listed for the next plan, D16).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\AnnualPlan;
use App\Models\PlanActivity;
use App\Models\PlanOccurrence;
use Livewire\Livewire;

test('the review shows the match rate and each row verdict', function () {
    $plan = AnnualPlan::factory()->between('2027-01', '2027-04')->finalReview()->create();
    $activity = PlanActivity::factory()->inMonths('2027-01', '2027-02')->create(['annual_plan_id' => $plan->id]);
    PlanOccurrence::factory()->verified()->create(['plan_activity_id' => $activity->id, 'month' => '2027-01-01']);
    PlanOccurrence::factory()->missed()->create(['plan_activity_id' => $activity->id, 'month' => '2027-02-01']);

    Livewire::actingAs(userWithRole('MEAL'))
        ->test('pages::annual-plans.show', ['annualPlan' => $plan])
        ->assertSet('activeTab', 'review')
        ->assertSeeText('50%')
        ->assertSeeText(__('annual_plans.verdict_partly', ['on' => 1, 'of' => 2]));
});

test('meal closes from the review with notes; a closed plan shows them', function () {
    $plan = AnnualPlan::factory()->finalReview()->create();

    Livewire::actingAs(userWithRole('MEAL'))
        ->test('pages::annual-plans.show', ['annualPlan' => $plan])
        ->set('closingNotes', 'Delivered as planned except June.')
        ->call('closePlan')
        ->assertHasNoErrors()
        ->assertSeeText('Delivered as planned except June.');
});

test('postponed months that never happened are listed for the next plan', function () {
    $plan = AnnualPlan::factory()->between('2027-01', '2027-04')->finalReview()->create();
    $activity = PlanActivity::factory()->inMonths('2027-02')->create(['annual_plan_id' => $plan->id]);
    PlanOccurrence::factory()->postponedTo('2027-04')->create(['plan_activity_id' => $activity->id, 'month' => '2027-02-01']);

    Livewire::actingAs(userWithRole('MEAL'))
        ->test('pages::annual-plans.show', ['annualPlan' => $plan])
        ->assertSeeText(__('annual_plans.carry_into_the_next_plan'))
        ->assertSeeText($activity->name());
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/ReviewTabTest.php`
Expected: FAIL.

- [ ] **Step 3: Computed on the page**

```php
    /**
     * Postponed months whose new month never got verified: what the next
     * plan should consider taking on (D16 lists them, nothing is copied).
     *
     * @return list<array{activity: string, month: string, project: string|null}>
     */
    #[Computed]
    public function postponedLeftovers(): array
    {
        $left = [];

        foreach ($this->board['rows'] as $row) {
            $sources = $row['projects'] === [] ? [[null, $row['cells']]] : array_map(fn (array $project): array => [$project['name'], $project['cells']], $row['projects']);

            foreach ($sources as [$projectName, $cells]) {
                foreach ($cells as $cell) {
                    if ($cell['outcome'] === \App\Enums\PlanCellOutcome::Postponed) {
                        $left[] = ['activity' => $row['name'], 'month' => \App\Support\Plans\PlanPeriod::fromKey($cell['key'])->translatedFormat(__('formats.month_year')), 'project' => $projectName];
                    }
                }
            }
        }

        return $left;
    }
```

(Add `$this->postponedLeftovers` to `forgetPlan()`. Import the classes at the top instead of FQCNs.)

- [ ] **Step 4: `_review-tab.blade.php`**

```blade
@php
    $totals = $this->board['totals'];
@endphp

<div class="flex flex-col gap-8">
    <section class="flex flex-wrap items-end gap-x-10 gap-y-6 border-b-[1.5px] border-secondary-dark pb-7 dark:border-line-strong" aria-label="{{ __('annual_plans.plan_outcome') }}">
        <div class="flex flex-col gap-1">
            <p class="text-7xl font-bold leading-none tracking-tight tabular-nums">{{ $totals['matchRate'] === null ? '—' : $totals['matchRate'].'%' }}</p>
            <p class="text-sm text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.of_planned_months_happened_as_planned') }}</p>
        </div>
        @foreach ([
            [\App\Enums\PlanCellOutcome::OnPlan, $totals['onPlan']],
            [\App\Enums\PlanCellOutcome::Shifted, $totals['shifted']],
            [\App\Enums\PlanCellOutcome::Postponed, $totals['postponed']],
            [\App\Enums\PlanCellOutcome::Missed, $totals['missed'] + $totals['due']],
            [\App\Enums\PlanCellOutcome::Unplanned, $totals['unplanned']],
        ] as [$outcome, $figure])
            <div class="flex flex-col gap-1">
                <p class="text-4xl font-bold leading-none tabular-nums">{{ $figure }}</p>
                <p class="inline-flex items-center gap-2 text-sm text-secondary-medium dark:text-ink-muted"><x-plans.mark :outcome="$outcome" /> {{ $outcome->label() }}</p>
            </div>
        @endforeach
    </section>

    @if ($totals['due'] > 0)
        <p class="type-meta text-status-critical">{{ trans_choice('annual_plans.months_never_reported', $totals['due'], ['count' => $totals['due']]) }}</p>
    @endif

    <x-plans.month-grid :months="$this->board['months']" :rows="$this->board['rows']" mode="review" />
    <x-plans.legend />

    <div class="grid gap-10 @4xl/detail:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <section aria-labelledby="review-targets-heading" class="flex flex-col gap-2">
            <h2 id="review-targets-heading" class="type-heading">{{ __('annual_plans.targets_and_milestones') }}</h2>
            @forelse ($this->targets as $target)
                @php
                    $percent = $target->percentAchieved();
                @endphp
                <div class="grid grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)] items-baseline gap-4 border-b border-secondary-soft py-2.5 dark:border-line" wire:key="review-target-{{ $target->id }}">
                    <div>
                        <p class="type-label text-secondary-medium dark:text-ink-muted">{{ $target->period->label() }}@if ($target->planActivity !== null) · {{ $target->planActivity->name() }}@endif</p>
                        <p class="text-sm font-semibold">{{ $target->indicator->name }}</p>
                    </div>
                    <p class="text-2xl font-bold tabular-nums">{{ \Illuminate\Support\Number::format($target->achievedValue()) }} / {{ \Illuminate\Support\Number::format((float) $target->target) }}</p>
                    <p @class([
                        'text-sm font-semibold',
                        'text-status-good' => $percent !== null && $percent >= 100,
                        'text-status-warn' => $percent !== null && $percent >= 80 && $percent < 100,
                        'text-status-critical' => $percent === null || $percent < 80,
                    ])>{{ $percent === null ? '—' : \Illuminate\Support\Number::format($percent, 0).'%' }}</p>
                </div>
            @empty
                <x-ui.empty-state icon="chart-bar" :heading="__('common.no_targets_yet')" />
            @endforelse
        </section>

        <section aria-labelledby="closing-heading" class="flex flex-col gap-4">
            <h2 id="closing-heading" class="type-heading">{{ __('annual_plans.meal_final_review') }}</h2>

            @if ($this->postponedLeftovers !== [])
                <div class="flex flex-col gap-1">
                    <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.carry_into_the_next_plan') }}</p>
                    @foreach ($this->postponedLeftovers as $leftover)
                        <p class="text-sm">{{ $leftover['activity'] }} · {{ $leftover['month'] }}@if ($leftover['project'] !== null) · {{ $leftover['project'] }}@endif</p>
                    @endforeach
                </div>
            @endif

            @if ($plan->status === \App\Enums\PlanStatus::Closed)
                <div class="border-t-2 border-secondary-dark pt-3 dark:border-ink">
                    <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.closed_on_by', ['date' => $plan->closed_at?->translatedFormat(__('formats.date')), 'name' => $plan->closer?->name ?? __('notifications.system')]) }}</p>
                    <p class="mt-2 whitespace-pre-line text-[15px] leading-relaxed">{{ $plan->closing_notes }}</p>
                </div>
            @elseif (auth()->user()->can('review', $plan) && $plan->status === \App\Enums\PlanStatus::FinalReview)
                <form wire:submit="closePlan" class="flex flex-col gap-3">
                    <x-ui.form-textarea wire:model="closingNotes" :label="__('annual_plans.closing_notes')" :help="__('annual_plans.closing_notes_help')" required rows="6" />
                    @error('plan')<p class="type-meta text-status-critical" role="alert">{{ $message }}</p>@enderror
                    <x-ui.button type="submit" tone="primary" icon="lock-closed" class="self-start">{{ __('annual_plans.close_plan') }}</x-ui.button>
                </form>
            @else
                <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.meal_closes_after_review') }}</p>
            @endif
        </section>
    </div>
</div>
```

The page's `targets()` computed must eager-load `planActivity.catalogActivity:id,name` (add it to its `with([...])`) or strict mode will throw on `$target->planActivity`.

- [ ] **Step 5: Lang** (en / ar)

```php
    'carry_into_the_next_plan' => 'Postponed and never done: consider them for the next plan',
    'closed_on_by' => 'Closed on :date by :name',
    'closing_notes_help' => 'What went well, what slipped and what the next plan should change.',
    'meal_closes_after_review' => 'MEAL closes the plan after the final review.',
    'meal_final_review' => 'MEAL final review',
    'months_never_reported' => '{1} :count planned month was never reported and counts as missed.|[2,*] :count planned months were never reported and count as missed.',
    'of_planned_months_happened_as_planned' => 'of planned months happened as planned',
    'plan_outcome' => 'Plan outcome',
```

```php
    'carry_into_the_next_plan' => 'مؤجلة ولم تُنفَّذ: فكّر في إدراجها في الخطة التالية',
    'closed_on_by' => 'أُغلقت في :date بواسطة :name',
    'closing_notes_help' => 'ما الذي سار جيدًا، وما الذي تأخر، وما الذي يجب أن تغيّره الخطة التالية.',
    'meal_closes_after_review' => 'تغلق المتابعة والتقييم الخطة بعد المراجعة النهائية.',
    'meal_final_review' => 'المراجعة النهائية للمتابعة والتقييم',
    'months_never_reported' => '{1} شهر مخطط واحد لم يُبلَّغ عنه ويُحتسب غير منفّذ.|{2} شهران مخططان لم يُبلَّغ عنهما ويُحتسبان غير منفّذين.|[3,10] :count أشهر مخططة لم يُبلَّغ عنها وتُحتسب غير منفّذة.|[11,*] :count شهرًا مخططًا لم يُبلَّغ عنها وتُحتسب غير منفّذة.',
    'of_planned_months_happened_as_planned' => 'من الأشهر المخططة نُفّذت حسب الخطة',
    'plan_outcome' => 'نتيجة الخطة',
```

- [ ] **Step 6: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/ReviewTabTest.php tests/Feature/AnnualPlanDetailTest.php tests/Feature/LocalizationTest.php`
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add resources/views/pages/annual-plans lang/en/annual_plans.php lang/ar/annual_plans.php tests/Feature/AnnualPlans/ReviewTabTest.php
```

---

### Task 31: Projects tab: the program-level view

**Files:**
- Modify: `resources/views/pages/annual-plans/_projects-tab.blade.php`, `⚡show.blade.php` (one computed), `lang/{en,ar}/annual_plans.php`
- Test: `tests/Feature/AnnualPlans/ProjectsTabTest.php`

**Interfaces:**
- Produces: `#[Computed] nestedPlans(): list<array{plan: AnnualPlan, board: array, months: list<string>}>`. These are the project plans of the program that overlap this plan's period, each with its board totals and cells laid onto **this** plan's months, so all rows share one month axis.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\AnnualPlan;
use App\Models\Project;
use Livewire\Livewire;

test('a program plan lists the overlapping project plans with their periods and phases', function () {
    $plan = AnnualPlan::factory()->between('2027-01', '2027-12')->active()->create();
    $hub = Project::factory()->create(['program_id' => $plan->program_id, 'name' => 'Skills Hub B']);
    AnnualPlan::factory()->forProject($hub)->between('2027-04', '2027-12')->active()->create();
    $elsewhere = Project::factory()->create(['name' => 'Elsewhere']);
    AnnualPlan::factory()->forProject($elsewhere)->between('2027-01', '2027-12')->create();

    Livewire::actingAs(userWithRole('General Manager'))
        ->test('pages::annual-plans.show', ['annualPlan' => $plan])
        ->set('tab', 'projects')
        ->assertSeeText('Skills Hub B')
        ->assertSeeText('Apr 2027 – Dec 2027')
        ->assertDontSeeText('Elsewhere');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/ProjectsTabTest.php`
Expected: FAIL.

- [ ] **Step 3: Computed**

```php
    /**
     * Project plans of this program overlapping this plan, each drawn on
     * this plan's month axis (months outside a project's own period are
     * blank). Program-wide plans only.
     *
     * @return list<array{plan: AnnualPlan, totals: array<string, int|null>, cells: array<string, PlanCellOutcome|null>}>
     */
    #[Computed]
    public function nestedPlans(): array
    {
        if (! $this->plan->isProgramWide()) {
            return [];
        }

        $board = app(PlanBoard::class);
        $nested = [];

        $plans = AnnualPlan::query()
            ->select(['id', 'slug', 'program_id', 'project_id', 'manager_id', 'year', 'starts_on', 'ends_on', 'status'])
            ->where('program_id', $this->plan->program_id)
            ->whereNotNull('project_id')
            ->where('status', '!=', PlanStatus::Cancelled->value)
            ->overlapping($this->plan->starts_on, $this->plan->ends_on)
            ->with(['program:id', 'project:id,name,slug'])
            ->orderBy('starts_on')
            ->get();

        foreach ($plans as $projectPlan) {
            $projectBoard = $board->for($projectPlan);
            $byMonth = [];

            foreach ($this->board['months'] as $month) {
                $outcomes = [];

                foreach ($projectBoard['rows'] as $row) {
                    foreach ($row['cells'] as $cell) {
                        if ($cell['key'] === $month['key']) {
                            $outcomes[] = $cell['outcome'];
                        }
                    }
                }

                $byMonth[$month['key']] = $outcomes === [] ? null : app(PlanComparison::class)->worst($outcomes);
            }

            $nested[] = ['plan' => $projectPlan, 'totals' => $projectBoard['totals'], 'cells' => $byMonth];
        }

        return $nested;
    }
```

(Imports: `PlanCellOutcome`, `PlanComparison`, `PlanStatus`, `PlanBoard`. Add to `forgetPlan()`. Cost: one board per nested plan, at most 7 queries each. Fine for a handful of projects. Note it in the docblock.)

- [ ] **Step 4: `_projects-tab.blade.php`**

```blade
<div class="flex flex-col gap-8">
    <section aria-labelledby="program-wide-heading" class="flex flex-col gap-3">
        <div>
            <h2 id="program-wide-heading" class="type-heading">{{ __('annual_plans.program_wide_activities') }}</h2>
            <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.program_wide_activities_hint') }}</p>
        </div>
        <x-plans.month-grid :months="$this->board['months']" :rows="$this->board['rows']" mode="actual" :selected="null" />
    </section>

    <section aria-labelledby="project-plans-heading" class="flex flex-col gap-3">
        <h2 id="project-plans-heading" class="type-heading">{{ __('annual_plans.project_plans_in_this_program') }}</h2>

        @if ($this->nestedPlans === [])
            <x-ui.empty-state icon="map-pin" :heading="__('annual_plans.no_project_plans_yet')" :body="__('annual_plans.no_project_plans_yet_hint')" />
        @else
            <div class="overflow-x-auto [scrollbar-width:thin]">
                <div role="table" class="plan-grid" style="--plan-months: {{ count($this->board['months']) }}">
                    <div role="row" class="plan-grid__row plan-grid__row--head">
                        <div role="columnheader" class="type-label text-secondary-medium dark:text-ink-muted">{{ __('common.project') }}</div>
                        @foreach ($this->board['months'] as $month)
                            <div role="columnheader" @class(['plan-grid__month type-label', 'plan-grid__month--current' => $month['isCurrent']])>{{ $month['label'] }}</div>
                        @endforeach
                        <div role="columnheader" class="ps-4 type-label text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.phase_and_progress') }}</div>
                    </div>
                    @foreach ($this->nestedPlans as $nested)
                        <div role="row" class="plan-grid__row" wire:key="nested-{{ $nested['plan']->id }}">
                            <div role="rowheader" class="flex min-w-0 flex-col py-2 pe-3">
                                <a href="{{ route('annual-plans.show', $nested['plan']) }}" wire:navigate class="truncate text-[15px] font-semibold hover:underline">{{ $nested['plan']->project->name }}</a>
                                <span class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted">{{ $nested['plan']->periodLabel() }}</span>
                            </div>
                            @foreach ($nested['cells'] as $outcome)
                                <span role="cell" class="plan-cell">
                                    @if ($outcome !== null)<x-plans.mark :outcome="$outcome" />@endif
                                </span>
                            @endforeach
                            <div role="cell" class="flex flex-col items-start gap-1 ps-4 py-2">
                                <x-ui.enum-badge :case="$nested['plan']->status" />
                                <span class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted">
                                    {{ $nested['totals']['onPlanSoFar'] === null ? __('annual_plans.not_started') : __('annual_plans.on_plan_so_far', ['percent' => $nested['totals']['onPlanSoFar']]) }}
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </section>
</div>
```

- [ ] **Step 5: Lang** (en / ar)

```php
    'no_project_plans_yet' => 'No project plans yet',
    'no_project_plans_yet_hint' => 'A project of this program can have its own plan inside this period.',
    'not_started' => 'Not started',
    'on_plan_so_far' => ':percent% on plan so far',
    'phase_and_progress' => 'Phase · progress',
    'program_wide_activities' => 'Program-wide activities',
    'program_wide_activities_hint' => 'Shared rows have one status for every project; per-project rows open to show each project.',
    'project_plans_in_this_program' => 'Project plans in this program',
```

```php
    'no_project_plans_yet' => 'لا توجد خطط مشاريع بعد',
    'no_project_plans_yet_hint' => 'يمكن لمشروع من هذا البرنامج أن تكون له خطته الخاصة ضمن هذه المدة.',
    'not_started' => 'لم تبدأ',
    'on_plan_so_far' => ':percent% حسب الخطة حتى الآن',
    'phase_and_progress' => 'المرحلة · التقدم',
    'program_wide_activities' => 'أنشطة على مستوى البرنامج',
    'program_wide_activities_hint' => 'الصفوف المشتركة لها حالة واحدة لكل المشاريع؛ وصفوف كل مشروع تُفتح لعرض كل مشروع.',
    'project_plans_in_this_program' => 'خطط المشاريع في هذا البرنامج',
```

- [ ] **Step 6: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/ProjectsTabTest.php tests/Feature/LocalizationTest.php`
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add resources/views/pages/annual-plans lang/en/annual_plans.php lang/ar/annual_plans.php tests/Feature/AnnualPlans/ProjectsTabTest.php
```

---

### Task 32: The activity catalog page

**Files:**
- Create: `resources/views/pages/annual-plans/⚡catalog.blade.php`
- Modify: `routes/web.php` (catalog route **before** `annual-plans/{annualPlan}`), `resources/views/pages/annual-plans/⚡index.blade.php` (header link), `lang/{en,ar}/annual_plans.php`, `lang/{en,ar}/titles.php`
- Test: `tests/Feature/AnnualPlans/CatalogPageTest.php`

**Interfaces:**
- Produces: route `annual-plans.catalog` → `GET annual-plans/catalog` (`can:annual-plans.view`). The page lists activities with usage counts, plus a search, an add/rename modal and a switch-off toggle. Actions authorize through `CatalogActivityPolicy`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\CatalogActivity;
use App\Models\PlanActivity;
use Livewire\Livewire;

test('the catalog lists activities with how many plans use them', function () {
    $used = CatalogActivity::factory()->create(['name' => 'Beginner training']);
    PlanActivity::factory(2)->create(['catalog_activity_id' => $used->id]);
    CatalogActivity::factory()->create(['name' => 'Water point repair']);

    $this->actingAs(userWithRole('Team Member'))
        ->get(route('annual-plans.catalog'))
        ->assertOk()
        ->assertSeeText('Beginner training')
        ->assertSeeText(trans_choice('annual_plans.used_in_plans', 2, ['count' => 2]));
});

test('meal adds and switches off an activity; a team member sees no controls', function () {
    Livewire::actingAs(userWithRole('MEAL'))
        ->test('pages::annual-plans.catalog')
        ->call('openCreate')
        ->set('catalogName', 'Parents committee meeting')
        ->call('save')
        ->assertHasNoErrors();

    $activity = CatalogActivity::query()->sole();

    Livewire::actingAs(userWithRole('MEAL'))->test('pages::annual-plans.catalog')->call('toggleActive', $activity->id);

    expect($activity->fresh()->is_active)->toBeFalse();

    Livewire::actingAs(userWithRole('Team Member'))->test('pages::annual-plans.catalog')->assertDontSee('toggleActive', false);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/CatalogPageTest.php`
Expected: FAIL.

- [ ] **Step 3: The page**

```blade
<?php

use App\Actions\AnnualPlans\SaveCatalogActivity;
use App\Models\CatalogActivity;
use App\Support\Like;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.activity_catalog')] class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $catalogName = '';

    public string $catalogDescription = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, CatalogActivity>
     */
    #[Computed]
    public function activities(): LengthAwarePaginator
    {
        return CatalogActivity::query()
            ->withCount('planActivities')
            ->when($this->search !== '', fn ($query) => $query->where('name', 'like', Like::contains($this->search)))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(25);
    }

    #[Computed]
    public function twin(): bool
    {
        return trim($this->catalogName) !== '' && SaveCatalogActivity::activeTwinExists($this->catalogName, $this->editingId);
    }

    public function updatedCatalogName(): void
    {
        unset($this->twin);
    }

    public function openCreate(): void
    {
        Gate::authorize('create', CatalogActivity::class);

        $this->resetValidation();
        $this->editingId = null;
        $this->catalogName = '';
        $this->catalogDescription = '';
        $this->showForm = true;
    }

    public function openEdit(int $id): void
    {
        $activity = CatalogActivity::query()->findOrFail($id);

        Gate::authorize('update', $activity);

        $this->resetValidation();
        $this->editingId = $activity->id;
        $this->catalogName = $activity->name;
        $this->catalogDescription = (string) $activity->description;
        $this->showForm = true;
    }

    public function save(): void
    {
        $existing = $this->editingId === null ? null : CatalogActivity::query()->findOrFail($this->editingId);

        app(SaveCatalogActivity::class)->handle($existing, [
            'name' => $this->catalogName,
            'description' => $this->catalogDescription,
            'isActive' => $existing?->is_active ?? true,
        ]);

        $this->showForm = false;
        unset($this->activities);

        Flux::toast(variant: 'success', text: __('annual_plans.catalog_saved'));
    }

    public function toggleActive(int $id): void
    {
        $activity = CatalogActivity::query()->findOrFail($id);

        app(SaveCatalogActivity::class)->handle($activity, [
            'name' => $activity->name,
            'description' => $activity->description,
            'isActive' => ! $activity->is_active,
        ]);

        unset($this->activities);
    }
};
?>

<section class="admin-directory flex min-h-full w-full flex-1 flex-col gap-6">
    <x-ui.page-header :title="__('annual_plans.activity_catalog')" :count="$this->activities->total()">
        <x-slot:filters>
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('annual_plans.search_activities')" data-directory-search />
        </x-slot:filters>
        <x-slot:actions>
            @can('create', App\Models\CatalogActivity::class)
                <x-ui.button tone="primary" icon="plus" class="min-h-11" wire:click="openCreate">{{ __('annual_plans.new_catalog_activity') }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <ul class="flex flex-col">
        @forelse ($this->activities as $activity)
            <li class="flex flex-wrap items-center justify-between gap-3 border-b border-secondary-soft py-3 dark:border-line" wire:key="catalog-{{ $activity->id }}">
                <div class="min-w-0">
                    <p @class(['text-[15px] font-semibold', 'text-secondary-light line-through dark:text-ink-faint' => ! $activity->is_active])>{{ $activity->name }}</p>
                    <p class="type-meta text-secondary-medium dark:text-ink-muted">
                        {{ trans_choice('annual_plans.used_in_plans', $activity->plan_activities_count, ['count' => $activity->plan_activities_count]) }}
                        @if (! $activity->is_active) · {{ __('annual_plans.switched_off') }} @endif
                    </p>
                </div>
                @can('update', $activity)
                    <div class="flex items-center gap-1">
                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-10!" wire:click="openEdit({{ $activity->id }})" :aria-label="__('annual_plans.edit_activity', ['activity' => $activity->name])" />
                        <x-ui.button tone="ghost" size="sm" :icon="$activity->is_active ? 'eye-slash' : 'eye'" class="min-h-10" wire:click="toggleActive({{ $activity->id }})">{{ $activity->is_active ? __('annual_plans.switch_off') : __('annual_plans.switch_on') }}</x-ui.button>
                    </div>
                @endcan
            </li>
        @empty
            <li><x-ui.empty-state icon="rectangle-stack" :heading="__('annual_plans.no_catalog_activities')" /></li>
        @endforelse
    </ul>

    {{ $this->activities->links() }}

    <x-ui.modal wire:model="showForm" size="sm" :title="$editingId === null ? __('annual_plans.new_catalog_activity') : __('annual_plans.edit_catalog_activity')" submit="save" close="$set('showForm', false)" required-note>
        @if ($showForm)
            <div class="grid gap-4">
                <x-ui.form-input wire:model.live.debounce.400ms="catalogName" :label="__('annual_plans.activity_name')" required maxlength="150" />
                @if ($this->twin)
                    <p class="type-meta text-status-warn" role="status">{{ __('annual_plans.an_active_activity_already_has_this_name') }}</p>
                @endif
                <x-ui.form-textarea wire:model="catalogDescription" :label="__('annual_plans.activity_description')" rows="3" />
            </div>
        @endif
        <x-slot:actions>
            <x-ui.button type="submit" tone="primary">{{ __('common.save_changes') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
</section>
```

> Read `App\Support\Like` for the escaping helper's real method name before using `Like::contains`. Read `x-ui.page-header`'s slot names, and follow `.ai/rules/pages.md` (count badge, filters slot, actions slot).

Route (in `routes/web.php`, **above** the `annual-plans/{annualPlan}` line, or `catalog` would bind as a slug):

```php
    Route::livewire('annual-plans/catalog', 'pages::annual-plans.catalog')
        ->can('annual-plans.view')
        ->name('annual-plans.catalog');
```

Index page header: add a ghost link next to "New annual plan" in the `actions` slot:

```blade
<x-ui.button tone="ghost" icon="rectangle-stack" class="min-h-11" :href="route('annual-plans.catalog')" wire:navigate>{{ __('annual_plans.activity_catalog') }}</x-ui.button>
```

- [ ] **Step 4: Lang** (en / ar)

`annual_plans.php`:

```php
    'activity_catalog' => 'Activity catalog',
    'catalog_saved' => 'Catalog saved',
    'edit_catalog_activity' => 'Edit catalog activity',
    'no_catalog_activities' => 'No activities in the catalog yet',
    'search_activities' => 'Search activities',
    'switch_off' => 'Switch off',
    'switch_on' => 'Switch on',
    'switched_off' => 'Switched off',
    'used_in_plans' => '{0} Not used yet|{1} Used in :count plan|[2,*] Used in :count plans',
```

```php
    'activity_catalog' => 'قائمة الأنشطة',
    'catalog_saved' => 'حُفظت القائمة',
    'edit_catalog_activity' => 'تعديل نشاط في القائمة',
    'no_catalog_activities' => 'لا توجد أنشطة في القائمة بعد',
    'search_activities' => 'البحث في الأنشطة',
    'switch_off' => 'إيقاف',
    'switch_on' => 'تشغيل',
    'switched_off' => 'متوقف',
    'used_in_plans' => '{0} غير مستخدم بعد|{1} مستخدم في خطة واحدة|{2} مستخدم في خطتين|[3,10] مستخدم في :count خطط|[11,*] مستخدم في :count خطة',
```

`titles.php`: `'activity_catalog' => 'Activity Catalog'` / `'activity_catalog' => 'قائمة الأنشطة'`.

- [ ] **Step 5: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/CatalogPageTest.php tests/Feature/AccessControl/ModuleAccessTest.php tests/Feature/LocalizationTest.php`
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add "resources/views/pages/annual-plans/⚡catalog.blade.php" "resources/views/pages/annual-plans/⚡index.blade.php" routes/web.php lang/en/annual_plans.php lang/ar/annual_plans.php lang/en/titles.php lang/ar/titles.php tests/Feature/AnnualPlans/CatalogPageTest.php
```

---

# Phase E: Everything else that reads plans

### Task 33: Labels, report windows and counts across the app

**Files:**
- Modify: `app/Livewire/Concerns/ManagesReportForm.php`, `resources/views/pages/reports/⚡index.blade.php`, `resources/views/pages/results/⚡index.blade.php`, `resources/views/pages/indicators/⚡index.blade.php`, `resources/views/pages/targets/⚡index.blade.php`, `resources/views/pages/programs/⚡show.blade.php`, `resources/views/pages/executive-summary/⚡index.blade.php`
- Test: `tests/Feature/AnnualPlans/PlanLabelsAcrossPagesTest.php`

**Interfaces:**
- Consumes: `AnnualPlan::fiscalLabel()`, `label()`, `programWide()`, `open()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Result;
use App\Models\User;
use Livewire\Livewire;

test('a partial-year plan is named by its months on the targets page', function () {
    $plan = AnnualPlan::factory()->between('2027-04', '2027-09')->create();
    $indicator = Indicator::factory()->create(['result_id' => Result::factory()->create(['program_id' => $plan->program_id])->id]);
    PlanIndicator::factory()->create(['source_type' => 'annual_plan', 'source_id' => $plan->id, 'indicator_id' => $indicator->id]);

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('targets.index'))
        ->assertOk()
        ->assertSeeText('Apr 2027 – Sep 2027');
});

test('a calendar-year plan keeps its FY label', function () {
    $plan = AnnualPlan::factory()->create(['year' => 2026]);
    $indicator = Indicator::factory()->create(['result_id' => Result::factory()->create(['program_id' => $plan->program_id])->id]);
    PlanIndicator::factory()->create(['source_type' => 'annual_plan', 'source_id' => $plan->id, 'indicator_id' => $indicator->id]);

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('targets.index'))
        ->assertSeeText(__('common.fy_year', ['year' => 2026]));
});

test('executive summary counts a program once however many plans it holds', function () {
    $program = Program::factory()->create();
    AnnualPlan::factory()->between('2027-01', '2027-06')->create(['program_id' => $program->id]);
    AnnualPlan::factory()->between('2027-07', '2027-12')->create(['program_id' => $program->id]);
    AnnualPlan::factory()->forProject(Project::factory()->create(['program_id' => $program->id]))->between('2027-01', '2027-12')->create();

    $page = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::executive-summary.index')
        ->set('year', '2027');

    // Read the figure the page exposes for coverage; adapt the accessor to
    // the page's real computed name after reading it (e.g. ->get('coverage')).
    expect(collect($page->get('scorecard'))->firstWhere('key', 'annual-plan-coverage')['covered'])->toBe(1);
});

test('a report on a partial-year plan target must fall inside its months', function () {
    $plan = AnnualPlan::factory()->between('2027-04', '2027-09')->active()->create();
    $indicator = Indicator::factory()->create(['result_id' => Result::factory()->create(['program_id' => $plan->program_id])->id]);
    $target = PlanIndicator::factory()->create(['source_type' => 'annual_plan', 'source_id' => $plan->id, 'indicator_id' => $indicator->id]);

    $page = Livewire::actingAs(userWithRole('MEAL'))
        ->test('pages::reports.index')
        ->call('openCreateModal')
        ->set('reportTargetId', (string) $target->id)
        ->set('reportValue', '5');

    $page->set('reportPlannedDate', '2027-02-15')->call('saveReport')->assertHasErrors(['reportPlannedDate']);
    $page->set('reportPlannedDate', '2027-05-15')->call('saveReport')->assertHasNoErrors(['reportPlannedDate']);
});
```

> Before running, read `pages/executive-summary/⚡index.blade.php` and `ManagesReportForm` for the real names: the computed holding the coverage rows, the report form's open/save method names, and the planned-date property. Adjust the property and method names above to match. The assertions themselves (counted once, inside-the-months) stay.

- [ ] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanLabelsAcrossPagesTest.php`
Expected: FAIL.

- [ ] **Step 3: The edits**

1. `ManagesReportForm::targetLabel()`: `$source instanceof AnnualPlan => $source->fiscalLabel(),`
2. `ManagesReportForm::coverableWindow()`: the AnnualPlan arm becomes:

```php
            $source instanceof AnnualPlan => [
                $source->starts_on->startOfDay(),
                $source->ends_on->endOfDay(),
            ],
```

Update its docblock: "the plan's months, or the proposal's period…". The `formTarget()` eager load `'source'` loads the full plan row, so `starts_on` / `ends_on` are present.

3. `reports/⚡index.blade.php:429`, `results/⚡index.blade.php:195`, `indicators/⚡index.blade.php:264`, `targets/⚡index.blade.php:636`: replace `__('common.fy_year', ['year' => $X->year])` with `$X->fiscalLabel()`. **For each page, find the `source` eager load.** If it narrows columns (a `morphWith` / `select` naming `year`), add `starts_on`, `ends_on` to it. Strict mode throws `MissingAttributeException` otherwise. If a page's query-count test (`IndicatorDirectoryTest` asserts 20) changes, you added a query. Undo and fold the columns into the existing select instead.

4. `targets/⚡index.blade.php` plan options (around line 503):

```php
        return AnnualPlan::query()
            ->select(['id', 'program_id', 'project_id', 'year', 'starts_on', 'ends_on'])
            ->with(['program:id', 'project:id,name'])
            ->open()
            ->when($programId !== null, fn ($query) => $query->where('program_id', $programId))
            ->orderByDesc('starts_on')
            ->get()
            ->map(fn (AnnualPlan $plan): array => [
                'id' => $plan->id,
                'label' => $plan->label(),
            ])
            ->all();
```

(`open()` stops new targets being committed into a closed or cancelled plan. Mention this behaviour change in the review note.)

5. `programs/⚡show.blade.php` `annualPlans()`: add `->programWide()` and select `starts_on`, `ends_on`, `status`. `planTimeline()` keeps `keyBy('year')`. With several program-wide plans in one year (back-to-back), keep the **first** by adding `->orderBy('starts_on')` before `get()` and change `keyBy` to `->groupBy('year')->map->first()`. Update its docblock.

6. `executive-summary/⚡index.blade.php`: both counts (`covered` around line 370, `written` in `planFigures()`) become:

```php
AnnualPlan::query()->programWide()->where('year', $year)->distinct()->count('program_id')
```

Replace the stale comment ("annual_plans is unique on (program_id, year)…") with: "A program may hold several plans in a year (short periods, project plans); count each program once." The plan list around line 714 adds `->programWide()`.

- [ ] **Step 4: Run every affected page test**

Run:

```powershell
.\php.cmd artisan test --compact tests/Feature/AnnualPlans/PlanLabelsAcrossPagesTest.php tests/Feature/ReportTest.php tests/Feature/ReportDirectoryTest.php tests/Feature/ReportDetailTest.php tests/Feature/ResultsFrameworkTest.php tests/Feature/IndicatorDirectoryTest.php tests/Feature/TargetDirectoryTest.php tests/Feature/ProgramDetailTest.php tests/Feature/ExecutiveSummaryTest.php tests/Feature/StrategyDetailTest.php tests/Feature/ProjectDetailTest.php tests/Feature/PlanIndicatorTest.php
```

Expected: PASS.

- [ ] **Step 5: Format, stage**

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add app/Livewire/Concerns/ManagesReportForm.php resources/views/pages/reports resources/views/pages/results resources/views/pages/indicators resources/views/pages/targets resources/views/pages/programs resources/views/pages/executive-summary tests/Feature/AnnualPlans/PlanLabelsAcrossPagesTest.php
```

---

### Task 34: Seed believable tracking data

**Files:**
- Create: `database/seeders/PlanTrackingSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php` (call it after `MealSeeder`), `database/seeders/DemoDeliverySeeder.php` (seeded plans get a status)
- Test: run `tests/Feature/DatabaseSeederTest.php` (it fails if any new table is empty)

**Interfaces:**
- Consumes: all new factories.
- Produces: seeded `catalog_activities`, `plan_activities`, `plan_activity_months`, `plan_occurrences`, `annual_plan_events`. Every new table must be non-empty.

- [ ] **Step 1: Run the seeder test to see it fail**

Run: `.\php.cmd artisan test --compact tests/Feature/DatabaseSeederTest.php`
Expected: FAIL. "the seeders populate every table in the schema" names the five empty tables.

- [ ] **Step 2: DemoDeliverySeeder**

In the loop that creates two plans per program, give the earlier year `->closed()` and the later `->active()`:

```php
            foreach ([$strategy->start_year, $strategy->start_year + 1] as $index => $year) {
                AnnualPlan::factory()->{$index === 0 ? 'closed' : 'active'}()->create([
                    'program_id' => $program->id,
                    'manager_id' => $program->manager_id ?? $employeeIds[$index % $employeeIds->count()],
                    'year' => $year,
                ]);
            }
```

(The loop variable already named `$index` is the program index. Rename the inner one to `$position` to avoid shadowing.)

- [ ] **Step 3: `PlanTrackingSeeder`**

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\OccurrenceStatus;
use App\Enums\PlanEventAction;
use App\Enums\PlanStatus;
use App\Models\AnnualPlan;
use App\Models\AnnualPlanEvent;
use App\Models\CatalogActivity;
use App\Models\PlanActivity;
use App\Models\PlanActivityMonth;
use App\Models\PlanOccurrence;
use App\Support\Plans\PlanPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Gives every seeded plan a grid: three catalog activities with planned
 * months, and for active and closed plans an actual layer that mixes on-plan,
 * postponed, missed and unplanned months, so every screen and the review
 * have something on each side. Runs inside DatabaseSeeder's
 * Activity::withoutRecording().
 */
class PlanTrackingSeeder extends Seeder
{
    private const array Catalog = [
        'Beginner training', 'Intermediate training', 'Masters training', 'Community outreach day',
        'Training of trainers', 'Quarterly MEAL review', 'Safeguarding refresher', 'Parents committee meeting',
    ];

    public function run(): void
    {
        $catalog = collect(self::Catalog)->map(fn (string $name): CatalogActivity => CatalogActivity::query()->firstOrCreate(['name' => $name]));
        $today = CarbonImmutable::now()->startOfMonth();

        AnnualPlan::query()->orderBy('id')->each(function (AnnualPlan $plan) use ($catalog, $today): void {
            $months = PlanPeriod::months($plan->starts_on, $plan->ends_on);

            foreach ($catalog->shuffle()->take(3)->values() as $position => $entry) {
                $activity = PlanActivity::query()->create([
                    'annual_plan_id' => $plan->id,
                    'catalog_activity_id' => $entry->id,
                    'sequence' => $position + 1,
                ]);

                $planned = collect($months)->filter(fn (CarbonImmutable $month, int $i): bool => ($i + $position) % 3 !== 0)->values();

                foreach ($planned as $month) {
                    PlanActivityMonth::query()->create(['plan_activity_id' => $activity->id, 'month' => $month->toDateString()]);
                }

                if (! in_array($plan->status, [PlanStatus::Active, PlanStatus::FinalReview, PlanStatus::Closed], true)) {
                    continue;
                }

                foreach ($planned as $i => $month) {
                    if ($month->gt($today) && $plan->status === PlanStatus::Active) {
                        break;
                    }

                    $status = match ($i % 7) {
                        3 => OccurrenceStatus::Missed,
                        5 => OccurrenceStatus::Reported,
                        default => OccurrenceStatus::Verified,
                    };

                    PlanOccurrence::query()->create([
                        'plan_activity_id' => $activity->id,
                        'month' => $month->toDateString(),
                        'status' => $plan->status === PlanStatus::Closed && $status === OccurrenceStatus::Reported ? OccurrenceStatus::Verified : $status,
                        'reported_at' => $month->endOfMonth(),
                        'verified_at' => $status === OccurrenceStatus::Reported ? null : $month->endOfMonth()->addDays(3),
                    ]);
                }
            }

            foreach ([PlanEventAction::Created, PlanEventAction::Submitted, PlanEventAction::Returned, PlanEventAction::Submitted, PlanEventAction::Approved] as $step => $action) {
                AnnualPlanEvent::query()->create([
                    'annual_plan_id' => $plan->id,
                    'action' => $action,
                    'note' => $action === PlanEventAction::Returned ? 'Add a second month before the graduation.' : null,
                    'created_at' => $plan->starts_on->subDays(30 - $step * 4),
                ]);
            }
        });
    }
}
```

`DatabaseSeeder::run()`: add `PlanTrackingSeeder::class,` right after `MealSeeder::class,`.

> For a closed plan, Task 14's rule says nothing may still be `reported`. The seeder flips those to verified (as above), so seeded data never contradicts the rule.

- [ ] **Step 4: Run, format, stage**

Run: `.\php.cmd artisan test --compact tests/Feature/DatabaseSeederTest.php tests/Feature/RealDataSeederTest.php`
Expected: PASS.

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
git add database/seeders/PlanTrackingSeeder.php database/seeders/DatabaseSeeder.php database/seeders/DemoDeliverySeeder.php
```

Tell the user that refreshing their local data needs `.\php.cmd artisan migrate:fresh --seed`. The data is demo data (saved memory: no backup offer needed), but **ask before running it**.

---

### Task 35: Record the rules, run the static checks, check it in a browser

**Files:**
- Modify (through the Boost `record-rule` tool only, never by hand): `.ai/rules/*`
- Test: everything touched, file by file

- [ ] **Step 1: Record durable rules** with `record-rule` (one call each):

| glob | title | note |
|---|---|---|
| `app/Actions/AnnualPlans/**` | Plan writes go through actions, phase moves through PlanTransition | Every plan write is an App\Actions\AnnualPlans action that authorizes itself. Phase changes use PlanTransition::move (row lock + status check), so two reviewers cannot both approve. Each action writes its journal line and activity through PlanJournal::write, never directly. |
| `app/Support/Plans/**` | PlanComparison is the only definition of a cell outcome | Grid, review totals and the PlanTrackingScanner all read outcomes from PlanComparison (via PlanBoard). Never compute "on plan / postponed / missed" elsewhere. PlanBoard stays within 7 default-connection queries (PlanBoardTest). |
| `database/migrations/**` | annual_plans.year is derived from starts_on | `year` is the start month's year, set by AnnualPlan's starts_on mutator; the (program_id, year) unique is gone. Overlap of open plans per scope is enforced in PlanSchedule, not the schema. plan_occurrences.project_key (project_id or 0) exists only so the unique index works for shared rows. |
| `resources/views/pages/annual-plans/**` | Planned layer frozen after Draft; the journal is the durable record | The PM edits months only in Draft (policy editPlanned). annual_plan_events is the plan's permanent history (returns, cancels, tracking); the activity log is pruned after two years and drives notifications only. |
| `{app/Livewire/Concerns/ManagesReportForm.php,resources/views/pages/reports/**}` | A plan target's report window is the plan's months | Supersedes "planned_date must fall inside the annual plan's calendar year": it must fall inside starts_on..ends_on. |
| `resources/css/plans.css` | Plan marks differ by shape, not only colour | Each PlanCellOutcome has a distinct shape (ring, half, filled, dashed, cross, diamond, arrow). Thread pseudo-elements use inline-start/end so RTL draws correctly. |

Also update the superseded notes:
- `.ai/rules/annual-plans.md`: the matrix now reads `matrixPlans()` once and derives `planCells` / `projectPlanCounts`. Still four reads.
- `.ai/rules/reports.md`: the window rule.
- `.ai/rules/models.md`: the morph map now also holds `plan_occurrence`.

Use `record-rule` to append corrections. It records; it does not edit in place. If the tool cannot amend a note, record a new note titled "SUPERSEDES: …".

- [ ] **Step 2: Static checks**

```powershell
.\php.cmd vendor/bin/pint --dirty --format agent
.\php.cmd vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: Pint clean. PHPStan: no new errors. Fix the code. Any `ignoreErrors` entry must follow `.ai/rules/app-2.md` (one identifier, one path, exact count, written reason).

Blade compile check for every changed page and partial (see `.ai/rules/views.md`):

```powershell
.\php.cmd bisect_blade.php "resources/views/pages/annual-plans/⚡show.blade.php"
.\php.cmd bisect_blade.php "resources/views/pages/annual-plans/⚡index.blade.php"
.\php.cmd bisect_blade.php "resources/views/pages/annual-plans/⚡catalog.blade.php"
```

(If `bisect_blade.php` is not at the project root, `grep -rl bisect_blade` to find it, or ask the user.)

- [ ] **Step 3: Run every new and touched test file (never the whole suite)**

```powershell
.\php.cmd artisan test --compact tests/Unit/PlanPeriodTest.php tests/Unit/PlanComparisonTest.php tests/Feature/PlanEnumsTest.php tests/Feature/AnnualPlans tests/Feature/Notifications/AnnualPlanWorkflowNotificationsTest.php tests/Feature/Notifications/PlanTrackingScannerTest.php tests/Feature/Notifications/PlanningActivityTest.php
.\php.cmd artisan test --compact tests/Feature/Models/AnnualPlanTest.php tests/Feature/Models/MorphMapTest.php tests/Feature/Models/PlanIndicatorCleanupTest.php tests/Feature/Models/TransactionalDeleteTest.php tests/Feature/Models/FactoryStateTest.php tests/Feature/DatabaseEncodingTest.php tests/Feature/DatabaseSeederTest.php tests/Feature/LocalizationTest.php
.\php.cmd artisan test --compact tests/Feature/AnnualPlanCrudTest.php tests/Feature/AnnualPlanDetailTest.php tests/Feature/AnnualPlanMatrixTest.php tests/Feature/AnnualPlanTest.php tests/Feature/AccessControl tests/Feature/RolesAndPermissionsSeederTest.php
```

Expected: all PASS. Then **ask the user** to run the complete suite themselves (`.\php.cmd artisan test --compact`, about 10 minutes).

- [ ] **Step 4: Look at it in a browser**

1. Ask the user to run `npm run build` (or confirm `composer run dev` is running).
2. Use the built-in browser preview (`preview_start`). If the page needs a session you do not have, use the server-side render recipe in `.ai/rules/pages.md` ("Preview an auth-gated page…"). Never type a password.
3. Check, in **light and dark**, at **1366×768** and **390×844** (phone), and in **Arabic** (RTL):
   - matrix: a cell with a program plan + status badge + "N project plans"
   - draft plan tab: click months, threads join, the add-from-catalog form works
   - submitted: Approve / Return buttons only for MEAL; the return banner after a return
   - tracking: select a cell, the panel shows actions, upload a PDF, download it
   - review: big match-rate figure, two-lane rows, close form
   - projects tab: nested project plans on the program's month axis
   - catalog page: add, rename, switch off
4. Take screenshots of each state and send them to the user.

- [ ] **Step 5: Final staging and hand-off**

```powershell
git status
```

List every staged and unstaged file for the user, grouped by task. **Do not commit.** The user reviews each file first (saved feedback: never commit without asking).

---

## Spec coverage (self-review)

| User requirement (2026-10-06 conversation) | Where |
|---|---|
| Manager decides the plan's period, any length (3/6/9/12+ months) | Task 2 (columns), 8 (rules, ≤24 months), 12, 24 (form), 25 (edit) |
| Rows are activities from a reusable catalog; PM + MEAL can add to it | Tasks 3, 13, 18, 26 (inline add), 32 (catalog page) |
| X = activity happens in that month, possibly several months | Tasks 4, 13, 23, 26 |
| Quantities become indicator targets or milestones, decided and set by MEAL | Tasks 6, 17, 29 |
| Targets per activity, per month or per quarter, or whole plan | Task 1 (`Period::Plan`), 17, 29 |
| PM drafts → sends to MEAL → MEAL returns (logged) or approves → active | Tasks 10, 14, 27; journal Task 5/9; banner Task 25 |
| PM cannot change anything after the phase moves | Policy `editPlanned` / `editDetails` (Task 10), enforced in every action (13, 12) |
| Approval goes straight to Active; PM is notified | Task 14 `ApprovePlan`, Task 11 recipients |
| Actual plan tracked all period, dynamic, by MEAL; PM reports, MEAL verifies | Tasks 15, 16, 28 |
| Unplanned activities or months can be added by PM or MEAL | Task 15 (`AddUnplannedActivity`, `RecordUnplannedOccurrence`, unplanned reports), 28 |
| End of period: matches, mismatches, postponed, missed, unplanned | Tasks 19, 20, 30 |
| Every change and phase logged and notified to the right people | Tasks 5 (journal), 9, 11, 21 (reminders), 25 (history tab) |
| MEAL closes after final review with notes | Tasks 14, 22 (auto-open review), 27, 30 |
| Per project and per program; program is the higher view; shared or per-project status | Tasks 2 (scope), 4 (`tracking`), 15 (slot rules), 20 (per-project rows), 31 (projects tab) |
| No overlapping plans for the same scope | Task 8 |
| Keep the programs × years overview, add a matrix per project and per program | Task 24 (overview kept), 25–31 (month matrix per plan, program view) |
| Arabic and English everywhere | Every task's lang step; `LocalizationTest` in each run |

Gaps found while self-reviewing and how they are handled:
- **D16** (carry postponed months into the next plan) is listed, not automated. Confirm with the user.
- **D17** asks the user before old tests are rewritten.
- The project page (`projects/⚡show`) does not yet link to its project plan. A one-line link in its header is a small follow-up, not in this plan. Ask the user whether they want it.

---

## Execution order (dependencies)

Tasks run in number order, with these two exceptions noted inside the tasks:

1. **Task 10's config lines** (`groups` + `MEAL` baseline) must exist before **Task 3**'s MEAL assertions pass. Recommended order: 1 → 2 → 10 → 3 → 4 → 5 → 6 → 7 → 8 → 9 + 11 (together) → 12 → … → 35.
2. **Task 9's** sentence test needs **Task 11's** English sentences. Implement them in the same sitting.

Each task leaves the app working. Tasks 1–22 change no screen except the two removals Task 24 handles. Tasks 23–32 add the screens. Tasks 33–35 align the rest of the app and verify.
