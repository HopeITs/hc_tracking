# UI Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the shared `x-ui.*` Blade component layer that the twenty-two new pages are assembled from, and prove it by completing the three existing hub pages with detail pages.

**Architecture:** Anonymous Blade components under `resources/views/components/ui/`, each with one responsibility, wrapping Flux and baking in the palette overrides that the four existing pages currently repeat in a per-page `@php` block. Then three read-only detail pages (`strategies.show`, `programs.show`, `yearly-plans.show`) as the first consumers, which flush out interface mistakes before fifteen more pages depend on them.

**Tech Stack:** Laravel 13, PHP 8.5, Livewire 4 (single-file components), Flux 2, Tailwind 4 (CSS-first), Pest 5.

## Global Constraints

These apply to every task in this plan and to all three spine plans that follow.

- **Strict mode is on.** `Model::shouldBeStrict(! app()->isProduction())`. Lazy loading throws. Every relation a Blade view touches must be eager-loaded in the `#[Computed]` query, nested paths included. Never disable the check; eager load instead.
- **Palette is fixed.** `primary #be1f24`; `secondary-dark #58595b`, `secondary-medium #6d6e71`, `secondary-light #939598`, `secondary-soft #dcddde`. No new colour is introduced. No new `--spacing-*` token is added — unprefixed names collapse Flux modals (`.ai/rules/css.md`).
- **Morph keys, never class names.** `plan_indicators.source_type` holds `'yearly_plan'` or `'proposal'`. Never `App\Models\YearlyPlan`.
- **The morph map holds four entries.** `'yearly_plan' => YearlyPlan::class`, `'proposal' => Proposal::class` (target sources), plus `'project' => Project::class`, `'milestone' => Milestone::class` (media owners, added by the DB schema update below). `Relation::enforceMorphMap()` is global; `tests/Feature/Models/MorphMapTest.php` asserts the map holds exactly these four. Adding a fifth breaks it.
- **Money is never summed across currencies.** `USD`/`EUR`/`SYP` have no conversion rate anywhere in this system. Totals are always per-currency.
- **Never compare a pivot currency to a `Currency` case.** `$proposal->donors()->first()->pivot->currency` is the raw string `'USD'`; `DonorProposal::first()->currency` is a `Currency` case. Read funding through `donorProposals()`.
- **No further schema changes.** The additions in "DB Schema Update (2026-09-11)" below are already migrated, seeded and tested — they precede this plan's execution, not a task within it. No migration, model, observer, factory or seeder is modified while executing these tasks. If a page cannot be built without a further one, stop and raise it.
- **Table naming.** Entity tables are plural; pivots are singular (`project_proposal`). `TransfersTracking` and `DonorProposal` carry `$table` overrides.
- **Tests** live at `tests/Feature/<Feature>Test.php`, `tests/Feature/Admin/` for admin pages. No new `tests/Feature/Pages/` directory.
- **Pint** runs before any task is considered done: `vendor/bin/pint --dirty --format agent`.
- **Commits** are made only when the user has approved them. Each task's commit step stages files and stops for approval rather than committing unattended.

---

## DB Schema Update (2026-09-11)

Implemented, migrated, seeded and tested (539 tests passing) before any task in
this plan or the three spine plans runs. This is a record of what changed and
what it obliges the UI to do — not a task list. Full detail lives in the
Delivery, Funding and M&E plans' own addenda, scoped to the sections each
plan builds.

**New enums:** `DonorType` (bilateral, foundation, individual, corporate — nullable),
`ProjectStatus` (draft → submitted → approved → active → closed → archived,
NOT NULL, defaults `draft`), `ReportStatus` (draft → reviewed → approved, NOT
NULL, defaults `draft`), `MediaCollection` (proposal, agreement, mou, photo,
evidence, other), `RagStatus` (red, amber, green — **derived, no column**,
exactly like `ProposalPhase`).

**New tables:** `media` (polymorphic file store — `mediable_type`/`mediable_id`,
owners are `project` and `milestone`), `indicator_progress` (dated readings
against an indicator — distinct from `reports`, which stays a narrative
document).

**Columns added to existing tables:**
- `donors`: `type`, `contact_person`, `email`, `phone`, `country`, `notes` — all nullable.
- `projects`: `description`, `status`, `start_date`, `end_date`, `target_beneficiaries`, `target_men`, `target_women`, `target_boys`, `target_girls` (five discrete integer columns, not JSON — see `.ai/rules/feature.md` on why).
- `indicators`: `project_id` (nullable, `nullOnDelete`), `unit`, `baseline`, `target` (the **lifetime** target — distinct from `plan_indicators.target`, a per-period commitment), `frequency` (reuses the `Period` enum).
- `reports`: `status` replaces the dropped `is_approved`/`approved_date`/`is_editable`/`edited_date` quartet. `is_seen_by_meal` survives unchanged. Five narrative columns added: `period_start`, `period_end`, `executive_summary`, `key_achievements`, `challenges`, `lessons_learned`, `next_period_plans`.

**Morph map widened from two entries to four.** `'project' => Project::class`
and `'milestone' => Milestone::class` were added alongside the existing
`'yearly_plan'`/`'proposal'` pair, for the new `media` table. `MediaOwnerObserver`
handles cleanup exactly as `PlanIndicatorSourceObserver` and `ProgramObserver`
do for the original pair — including the hop through
`milestones.project_id`'s and `milestones.parent_milestone_id`'s database
cascades, which fire no Eloquent events.

**Consequence for `x-ui.enum-badge` (Task 2).** The component's tone-mapping
`match` should grow arms for `ProjectStatus`, `ReportStatus` and `DonorType` when
the Delivery/Funding/M&E plans reach the pages that render them, following the
same rule already used: filled primary = terminal-negative, filled dark =
active/achieved, outlined = pending/neutral. Not needed for Foundation's own
three detail pages (Strategy, Program, Annual Plan render none of these new
enums), so no code in this plan changes — flagging it here so the pattern is
extended consistently rather than reinvented per spine plan.

**Consequence for `x-ui.money` (Task 2).** No change. The per-currency rule
already covers every existing and new money column; nothing new was added that
carries a currency.

**No consequence for Tasks 5–7** (Strategy/Program/Annual Plan detail): none of
the three touch `donors`, `projects`, `indicators` or `reports` columns directly.

---

## File Structure

Created in this plan:

| File | Responsibility |
| --- | --- |
| `resources/views/components/ui/input.blade.php` | Flux input with palette overrides baked in |
| `resources/views/components/ui/select.blade.php` | Flux select with palette overrides baked in |
| `resources/views/components/ui/button.blade.php` | Flux button, `tone` prop: `primary`/`secondary`/`ghost` |
| `resources/views/components/ui/enum-badge.blade.php` | One `BackedEnum` case in, one palette pill out |
| `resources/views/components/ui/money.blade.php` | Amount + currency; refuses to render without a currency |
| `resources/views/components/ui/user-chip.blade.php` | Avatar + name + role + email from a `User` |
| `resources/views/components/ui/stat-tile.blade.php` | Labelled figure with optional denominator |
| `resources/views/components/ui/empty-state.blade.php` | Icon, heading, body, action; filtered vs unfiltered copy |
| `resources/views/components/ui/panel.blade.php` | Bordered card: toolbar row, body, footer |
| `resources/views/components/ui/page-header.blade.php` | Title, count badge, description, filters slot, actions slot |
| `resources/views/components/ui/detail-header.blade.php` | Breadcrumb, title, meta row, actions |
| `resources/views/components/ui/relation-tabs.blade.php` | Tab strip for detail-page relation panels |
| `resources/views/components/ui/tree-rows.blade.php` | Depth-indented recursive renderer for a `HasTree` subtree |
| `resources/views/pages/strategies/⚡show.blade.php` | Strategy detail |
| `resources/views/pages/programs/⚡show.blade.php` | Program detail |
| `resources/views/pages/yearly-plans/⚡show.blade.php` | Annual plan detail |
| `tests/Feature/UiComponentsTest.php` | Every `x-ui.*` component's contract |
| `tests/Feature/StrategyDetailTest.php` | Strategy detail page |
| `tests/Feature/ProgramDetailTest.php` | Program detail page |
| `tests/Feature/YearlyPlanDetailTest.php` | Annual plan detail page |

Modified:

| File | Change |
| --- | --- |
| `routes/web.php` | Three `Route::livewire` detail routes |
| `resources/views/components/navigation/sidebar.blade.php` | Regroup into Overview / Strategy / Administration / Settings |
| `resources/views/pages/strategies/⚡index.blade.php` | Row links to detail |
| `resources/views/pages/programs/⚡index.blade.php` | Row links to detail |
| `resources/views/pages/yearly-plans/⚡index.blade.php` | Cell links to detail |

**The four existing index pages keep their own `@php` class blocks.** They are not migrated onto `x-ui.*`. They work and are tested; the only edits they receive are the row links added in Tasks 6–8.

---

### Task 1: Form control components

**Files:**
- Create: `resources/views/components/ui/input.blade.php`
- Create: `resources/views/components/ui/select.blade.php`
- Create: `resources/views/components/ui/button.blade.php`
- Test: `tests/Feature/UiComponentsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `<x-ui.input />` and `<x-ui.select />`, both forwarding every attribute to the Flux component. `<x-ui.button tone="primary|secondary|ghost" />` forwarding to `flux:button`.

These three exist so that no new page repeats the `$controlClasses` / `$primaryButtonClasses` / `$secondaryButtonClasses` / `$editButtonClasses` block. The class strings below are copied verbatim from `resources/views/pages/programs/⚡index.blade.php` so the new pages are visually identical to the existing ones.

- [ ] **Step 1: Write the failing test**

```php
<?php

use Illuminate\Support\Facades\Blade;

test('the ui input carries the shared control classes', function () {
    $html = Blade::render('<x-ui.input name="search" label="Search" />');

    expect($html)->toContain('border-secondary-light!')
        ->and($html)->toContain('bg-white!')
        ->and($html)->toContain('dark:bg-secondary-medium!');
});

test('the ui button renders one tone per variant', function (string $tone, string $expected) {
    $html = Blade::render('<x-ui.button :tone="$tone">Go</x-ui.button>', ['tone' => $tone]);

    expect($html)->toContain($expected);
})->with([
    'primary' => ['primary', 'bg-primary!'],
    'secondary' => ['secondary', 'bg-white!'],
    'ghost' => ['ghost', 'bg-transparent!'],
]);

test('the ui button rejects an unknown tone', function () {
    expect(fn () => Blade::render('<x-ui.button tone="rainbow">Go</x-ui.button>'))
        ->toThrow(InvalidArgumentException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/UiComponentsTest.php`
Expected: FAIL — `Unable to locate a class or view for component [ui.input]`

- [ ] **Step 3: Write the components**

`resources/views/components/ui/input.blade.php`:

```blade
@props(['label' => null])

<flux:input
    :label="$label"
    {{ $attributes->class('border-secondary-light! bg-white! text-secondary-dark! placeholder:text-secondary-light! dark:border-secondary-light! dark:bg-secondary-medium! dark:text-white!') }}
>
    {{ $slot }}
</flux:input>
```

`resources/views/components/ui/select.blade.php`:

```blade
@props(['label' => null])

<flux:select
    :label="$label"
    {{ $attributes->class('border-secondary-light! bg-white! text-secondary-dark! placeholder:text-secondary-light! dark:border-secondary-light! dark:bg-secondary-medium! dark:text-white!') }}
>
    {{ $slot }}
</flux:select>
```

`resources/views/components/ui/button.blade.php`:

```blade
@props(['tone' => 'secondary'])

@php
    $toneClasses = match ($tone) {
        'primary' => 'border-primary! bg-primary! text-white! hover:border-primary! hover:bg-primary!',
        'secondary' => 'border-secondary-light! bg-white! text-secondary-dark! hover:bg-secondary-soft! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white! dark:hover:bg-secondary-medium!',
        'ghost' => 'border-0! bg-transparent! text-secondary-dark! hover:bg-secondary-soft! dark:text-white! dark:hover:bg-secondary-medium!',
        default => throw new InvalidArgumentException("Unknown x-ui.button tone [{$tone}]."),
    };

    $fluxVariant = $tone === 'primary' ? 'primary' : 'filled';
@endphp

<flux:button :variant="$fluxVariant" {{ $attributes->class($toneClasses) }}>
    {{ $slot }}
</flux:button>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/UiComponentsTest.php`
Expected: PASS (5 assertions across 3 tests)

- [ ] **Step 5: Format and stage**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components/ui tests/Feature/UiComponentsTest.php
```

Stop here and report. Do not commit — the user reviews every file before it enters history.

---

### Task 2: Enum badge, money and user chip

**Files:**
- Create: `resources/views/components/ui/enum-badge.blade.php`
- Create: `resources/views/components/ui/money.blade.php`
- Create: `resources/views/components/ui/user-chip.blade.php`
- Modify: `tests/Feature/UiComponentsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `<x-ui.enum-badge :case="$enumCaseOrNull" />` — renders an em dash for null.
  - `<x-ui.money :amount="$decimalString" :currency="$currencyCaseOrString" />` — throws `InvalidArgumentException` when currency is null.
  - `<x-ui.user-chip :user="$user" :compact="false" />` — requires `name`, `email`, `role` selected on the model.

Tone assignment uses the palette only: a filled primary pill marks a terminal-negative state, a filled secondary-dark pill marks an active or achieved state, an outlined pill marks everything pending or neutral. Hue is not the only signal — the label is always present.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/UiComponentsTest.php`:

```php
use App\Enums\Currency;
use App\Enums\MilestoneStatus;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\User;

test('the enum badge labels a case and tones it', function () {
    $html = Blade::render('<x-ui.enum-badge :case="$case" />', ['case' => TaskStatus::InProgress]);

    expect($html)->toContain('In Progress')
        ->and($html)->toContain('bg-secondary-dark');
});

test('the enum badge tones a terminal negative state with the primary colour', function () {
    $html = Blade::render('<x-ui.enum-badge :case="$case" />', ['case' => MilestoneStatus::Missed]);

    expect($html)->toContain('Missed')
        ->and($html)->toContain('bg-primary');
});

test('the enum badge renders an em dash for a null case', function () {
    $html = Blade::render('<x-ui.enum-badge :case="$case" />', ['case' => null]);

    expect(trim(strip_tags($html)))->toBe('—');
});

test('money renders the amount and its currency', function () {
    $html = Blade::render('<x-ui.money :amount="$amount" :currency="$currency" />', [
        'amount' => '1500000.00',
        'currency' => Currency::Usd,
    ]);

    expect($html)->toContain('1,500,000.00')
        ->and($html)->toContain('USD')
        ->and($html)->toContain('tabular-nums');
});

test('money accepts a raw pivot currency string without comparing it to an enum case', function () {
    $html = Blade::render('<x-ui.money :amount="$amount" currency="USD" />', ['amount' => '10.00']);

    expect($html)->toContain('10.00')->and($html)->toContain('USD');
});

test('money refuses to render an amount with no currency', function () {
    expect(fn () => Blade::render('<x-ui.money :amount="$amount" :currency="null" />', ['amount' => '10.00']))
        ->toThrow(InvalidArgumentException::class);
});

test('the user chip shows name role and email', function () {
    $user = User::factory()->role(UserRole::Meal)->create([
        'name' => 'Layla Haddad',
        'email' => 'layla@example.com',
    ]);

    $html = Blade::render('<x-ui.user-chip :user="$user" />', ['user' => $user]);

    expect($html)->toContain('Layla Haddad')
        ->and($html)->toContain('MEAL')
        ->and($html)->toContain('layla@example.com');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/UiComponentsTest.php`
Expected: FAIL — `Unable to locate a class or view for component [ui.enum-badge]`

- [ ] **Step 3: Write the components**

`resources/views/components/ui/enum-badge.blade.php`:

```blade
@props(['case' => null])

@php
    $tone = match (true) {
        $case === null => 'none',
        $case instanceof \App\Enums\TaskStatus => match ($case) {
            \App\Enums\TaskStatus::Done => 'solid',
            \App\Enums\TaskStatus::InProgress, \App\Enums\TaskStatus::UnderReview => 'solid',
            \App\Enums\TaskStatus::Blocked => 'accent',
            \App\Enums\TaskStatus::Todo => 'outline',
        },
        $case instanceof \App\Enums\MilestoneStatus => match ($case) {
            \App\Enums\MilestoneStatus::Reached => 'solid',
            \App\Enums\MilestoneStatus::Missed, \App\Enums\MilestoneStatus::Cancelled => 'accent',
            \App\Enums\MilestoneStatus::Pending => 'outline',
        },
        $case instanceof \App\Enums\ProposalStatus => match ($case) {
            \App\Enums\ProposalStatus::Approved,
            \App\Enums\ProposalStatus::Active,
            \App\Enums\ProposalStatus::Completed => 'solid',
            \App\Enums\ProposalStatus::Rejected => 'accent',
            default => 'outline',
        },
        $case instanceof \App\Enums\ArStatus => match ($case) {
            \App\Enums\ArStatus::Received => 'solid',
            \App\Enums\ArStatus::Failed => 'accent',
            \App\Enums\ArStatus::Pending => 'outline',
        },
        $case instanceof \App\Enums\TaskPriority => match ($case) {
            \App\Enums\TaskPriority::High => 'accent',
            \App\Enums\TaskPriority::Medium => 'solid',
            \App\Enums\TaskPriority::Low => 'outline',
        },
        $case instanceof \App\Enums\ResultType => match ($case) {
            \App\Enums\ResultType::Impact => 'solid',
            default => 'outline',
        },
        default => 'outline',
    };

    $toneClasses = match ($tone) {
        'accent' => 'border-primary bg-primary text-white',
        'solid' => 'border-secondary-dark bg-secondary-dark text-white dark:border-secondary-medium dark:bg-secondary-medium',
        'outline' => 'border-secondary-light text-secondary-medium dark:text-secondary-soft',
        'none' => '',
    };
@endphp

@if ($case === null)
    <span {{ $attributes->class('text-secondary-light') }} aria-label="{{ __('Not set') }}">—</span>
@else
    <span {{ $attributes->class('inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-semibold whitespace-nowrap '.$toneClasses) }}>
        {{ \Illuminate\Support\Str::title($case->value) }}
    </span>
@endif
```

`resources/views/components/ui/money.blade.php`:

```blade
@props(['amount', 'currency'])

@php
    if ($currency === null) {
        throw new InvalidArgumentException('x-ui.money requires a currency; amounts are never shown bare because USD, EUR and SYP are not convertible in this system.');
    }

    $currencyCode = $currency instanceof \App\Enums\Currency ? $currency->value : (string) $currency;
    $formattedAmount = number_format((float) $amount, 2);
@endphp

<span {{ $attributes->class('inline-flex items-baseline gap-1 tabular-nums whitespace-nowrap') }}>
    <span class="font-semibold">{{ $formattedAmount }}</span>
    <span class="text-xs font-medium text-secondary-medium dark:text-secondary-soft">{{ $currencyCode }}</span>
</span>
```

`resources/views/components/ui/user-chip.blade.php`:

```blade
@props(['user', 'compact' => false])

<div {{ $attributes->class('flex min-w-0 items-center gap-2.5') }}>
    <flux:avatar
        class="bg-secondary-soft! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!"
        size="sm"
        :initials="$user->initials()"
    />
    <div class="min-w-0">
        <p class="truncate text-sm font-medium text-secondary-dark dark:text-white">{{ $user->name }}</p>
        @unless ($compact)
            <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">
                {{ $user->role->value }} · {{ $user->email }}
            </p>
        @endunless
    </div>
</div>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/UiComponentsTest.php`
Expected: PASS

- [ ] **Step 5: Format and stage**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components/ui tests/Feature/UiComponentsTest.php
```

Stop and report. Do not commit.

---

### Task 3: Page header, panel, empty state and stat tile

**Files:**
- Create: `resources/views/components/ui/stat-tile.blade.php`
- Create: `resources/views/components/ui/empty-state.blade.php`
- Create: `resources/views/components/ui/panel.blade.php`
- Create: `resources/views/components/ui/page-header.blade.php`
- Modify: `tests/Feature/UiComponentsTest.php`

**Interfaces:**
- Consumes: `x-ui.button` (Task 1).
- Produces:
  - `<x-ui.page-header :title="..." :count="?int" :description="..."><x-slot:filters/><x-slot:actions/></x-ui.page-header>`
  - `<x-ui.panel :title="..." :subtitle="..."><x-slot:toolbar/>body<x-slot:footer/></x-ui.panel>`
  - `<x-ui.empty-state icon="..." :heading="..." :body="..."><x-slot:action/></x-ui.empty-state>`
  - `<x-ui.stat-tile :label="..." :value="..." :denominator="?" :unit="?" />`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/UiComponentsTest.php`:

```php
test('the page header shows a title with a count badge and a description', function () {
    $html = Blade::render(
        '<x-ui.page-header title="Projects" :count="8" description="Delivery sites." />'
    );

    expect($html)->toContain('Projects')
        ->and($html)->toContain('8')
        ->and($html)->toContain('Delivery sites.');
});

test('the page header omits the count badge when no count is given', function () {
    $html = Blade::render('<x-ui.page-header title="Projects" />');

    expect($html)->toContain('Projects')
        ->and($html)->not->toContain('data-count-badge');
});

test('the stat tile shows a value over a denominator', function () {
    $html = Blade::render('<x-ui.stat-tile label="Targeted" :value="6" :denominator="8" unit="projects" />');

    expect($html)->toContain('Targeted')
        ->and($html)->toContain('6')
        ->and($html)->toContain('8')
        ->and($html)->toContain('projects');
});

test('the empty state carries different copy when filters are active', function () {
    $filtered = Blade::render(
        '<x-ui.empty-state icon="rectangle-stack" heading="No matching projects" body="Change the filters." />'
    );

    expect($filtered)->toContain('No matching projects')
        ->and($filtered)->toContain('Change the filters.');
});

test('the panel renders its title and body', function () {
    $html = Blade::render('<x-ui.panel title="Risks">Body text</x-ui.panel>');

    expect($html)->toContain('Risks')->and($html)->toContain('Body text');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/UiComponentsTest.php`
Expected: FAIL — `Unable to locate a class or view for component [ui.page-header]`

- [ ] **Step 3: Write the components**

`resources/views/components/ui/stat-tile.blade.php`:

```blade
@props(['label', 'value', 'denominator' => null, 'unit' => null, 'scope' => null])

<div {{ $attributes->class('rounded-lg border border-secondary-light bg-white p-3.5 dark:border-secondary-medium dark:bg-secondary-dark') }}>
    <div class="flex items-center justify-between gap-2">
        <p class="text-xs font-semibold tracking-wide text-secondary-medium uppercase dark:text-secondary-soft">{{ $label }}</p>
        @if ($scope !== null)
            <span class="rounded-md border border-secondary-light px-1.5 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-secondary-soft">{{ $scope }}</span>
        @endif
    </div>
    <p class="mt-1.5 flex items-baseline gap-1 tabular-nums">
        <span class="text-2xl font-semibold text-secondary-dark dark:text-white">{{ $value }}</span>
        @if ($denominator !== null)
            <span class="text-sm text-secondary-medium dark:text-secondary-soft">/ {{ $denominator }}</span>
        @endif
        @if ($unit !== null)
            <span class="text-xs text-secondary-medium dark:text-secondary-soft">{{ $unit }}</span>
        @endif
    </p>
</div>
```

`resources/views/components/ui/empty-state.blade.php`:

```blade
@props(['icon' => 'inbox', 'heading', 'body' => null])

<div {{ $attributes->class('flex min-h-64 flex-col items-center justify-center gap-3 px-5 py-10 text-center') }}>
    <div class="flex size-12 items-center justify-center rounded-lg border border-secondary-light bg-white text-secondary-medium dark:bg-secondary-dark dark:text-secondary-soft">
        <flux:icon :name="$icon" class="size-5" />
    </div>
    <div class="max-w-sm space-y-1">
        <flux:heading size="base">{{ $heading }}</flux:heading>
        @if ($body !== null)
            <p class="text-sm text-secondary-medium dark:text-secondary-soft">{{ $body }}</p>
        @endif
    </div>
    {{ $action ?? '' }}
</div>
```

`resources/views/components/ui/panel.blade.php`:

```blade
@props(['title' => null, 'subtitle' => null, 'count' => null])

<section {{ $attributes->class('overflow-hidden rounded-lg border border-secondary-light bg-white dark:border-secondary-medium dark:bg-secondary-dark') }}>
    @if ($title !== null || isset($toolbar))
        <div class="flex min-h-10 flex-wrap items-center justify-between gap-2 border-b border-secondary-soft px-3.5 py-2 dark:border-secondary-medium">
            <div class="min-w-0">
                @if ($title !== null)
                    <div class="flex items-center gap-2">
                        <p class="truncate text-sm font-semibold text-secondary-dark dark:text-white">{{ $title }}</p>
                        @if ($count !== null)
                            <span class="inline-flex min-w-6 items-center justify-center rounded-md border border-secondary-light px-1.5 py-0.5 text-[11px] font-semibold tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $count }}</span>
                        @endif
                    </div>
                @endif
                @if ($subtitle !== null)
                    <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">{{ $subtitle }}</p>
                @endif
            </div>
            {{ $toolbar ?? '' }}
        </div>
    @endif

    {{ $slot }}

    @isset($footer)
        <div class="border-t border-secondary-soft px-3 pb-3 dark:border-secondary-medium">{{ $footer }}</div>
    @endisset
</section>
```

`resources/views/components/ui/page-header.blade.php`:

```blade
@props(['title', 'count' => null, 'description' => null, 'headingId' => null])

<header {{ $attributes->class('flex flex-col gap-3 border-b border-secondary-soft pb-4 dark:border-secondary-medium @6xl/directory:flex-row @6xl/directory:items-end') }}>
    <div class="min-w-48 @6xl/directory:me-auto">
        <div class="flex items-center gap-2">
            <flux:heading :id="$headingId" size="lg">{{ $title }}</flux:heading>
            @if ($count !== null)
                <span data-count-badge class="inline-flex min-w-7 items-center justify-center rounded-md border border-secondary-light px-2 py-0.5 text-xs font-semibold tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $count }}</span>
            @endif
        </div>
        @if ($description !== null)
            <p class="text-sm text-secondary-medium dark:text-secondary-soft">{{ $description }}</p>
        @endif
    </div>

    @isset($filters)
        <div class="grid w-full gap-2 @sm/directory:grid-cols-2 @6xl/directory:max-w-[62rem]">{{ $filters }}</div>
    @endisset

    {{ $actions ?? '' }}
</header>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/UiComponentsTest.php`
Expected: PASS

- [ ] **Step 5: Format and stage**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components/ui tests/Feature/UiComponentsTest.php
```

Stop and report. Do not commit.

---

### Task 4: Detail header, relation tabs and the tree renderer

**Files:**
- Create: `resources/views/components/ui/detail-header.blade.php`
- Create: `resources/views/components/ui/relation-tabs.blade.php`
- Create: `resources/views/components/ui/tree-rows.blade.php`
- Modify: `tests/Feature/UiComponentsTest.php`

**Interfaces:**
- Consumes: `x-ui.enum-badge` (Task 2).
- Produces:
  - `<x-ui.detail-header :breadcrumbs="[['label' => 'Programs', 'url' => '...'], ['label' => 'WASH']]" :title="..." :subtitle="..."><x-slot:meta/><x-slot:actions/></x-ui.detail-header>` — the last breadcrumb entry has no `url` and is not a link.
  - `<x-ui.relation-tabs :tabs="[['key' => 'tasks', 'label' => 'Tasks', 'count' => 4]]" :active="$tab" wire-target="tab" />`
  - `<x-ui.tree-rows :nodes="$collection" :depth="0" :label="fn ($n) => $n->name" />` — recurses through the `children` relation.

`tree-rows` requires the subtree to be eager-loaded through `childrenRecursive`. It calls `$node->children` only, never `descendants()`, so a loaded subtree costs no query. Rendering an unloaded collection under strict mode throws `LazyLoadingViolationException` — which is the intended failure, not a bug to work around.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/UiComponentsTest.php`:

```php
use App\Models\Result;

test('the detail header links every breadcrumb but the last', function () {
    $html = Blade::render('<x-ui.detail-header :breadcrumbs="$crumbs" title="Water Security" />', [
        'crumbs' => [
            ['label' => 'Programs', 'url' => 'http://localhost/programs'],
            ['label' => 'Water Security'],
        ],
    ]);

    expect($html)->toContain('http://localhost/programs')
        ->and(substr_count($html, '<a '))->toBe(1);
});

test('the relation tabs mark the active tab', function () {
    $html = Blade::render('<x-ui.relation-tabs :tabs="$tabs" active="risks" wire-target="tab" />', [
        'tabs' => [
            ['key' => 'milestones', 'label' => 'Milestones', 'count' => 3],
            ['key' => 'risks', 'label' => 'Risks', 'count' => 2],
        ],
    ]);

    expect($html)->toContain('Milestones')
        ->and($html)->toContain('Risks')
        ->and($html)->toContain('aria-selected="true"');
});

test('the tree renderer indents a loaded subtree without querying per node', function () {
    $root = Result::factory()->create(['statement' => 'Root impact']);
    Result::factory()->create(['statement' => 'Child outcome', 'parent_id' => $root->id]);

    $loaded = Result::query()->whereKey($root->id)->with('childrenRecursive')->get();

    $html = Blade::render('<x-ui.tree-rows :nodes="$nodes" :label="$label" />', [
        'nodes' => $loaded,
        'label' => fn (Result $result): string => $result->statement,
    ]);

    expect($html)->toContain('Root impact')
        ->and($html)->toContain('Child outcome')
        ->and($html)->toContain('data-tree-depth="1"');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/UiComponentsTest.php`
Expected: FAIL — `Unable to locate a class or view for component [ui.detail-header]`

- [ ] **Step 3: Write the components**

`resources/views/components/ui/detail-header.blade.php`:

```blade
@props(['breadcrumbs' => [], 'title', 'subtitle' => null])

<header {{ $attributes->class('flex flex-col gap-3 border-b border-secondary-soft pb-4 dark:border-secondary-medium') }}>
    @if ($breadcrumbs !== [])
        <nav aria-label="{{ __('Breadcrumb') }}" class="flex flex-wrap items-center gap-1.5 text-xs text-secondary-medium dark:text-secondary-soft">
            @foreach ($breadcrumbs as $index => $crumb)
                @if ($index > 0)
                    <span aria-hidden="true" class="text-secondary-light">/</span>
                @endif

                @if (isset($crumb['url']))
                    <a href="{{ $crumb['url'] }}" wire:navigate class="rounded font-medium hover:text-secondary-dark dark:hover:text-white">{{ $crumb['label'] }}</a>
                @else
                    <span aria-current="page" class="font-semibold text-secondary-dark dark:text-white">{{ $crumb['label'] }}</span>
                @endif
            @endforeach
        </nav>
    @endif

    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
        <div class="min-w-0 sm:me-auto">
            <flux:heading size="lg">{{ $title }}</flux:heading>
            @if ($subtitle !== null)
                <p class="text-sm text-secondary-medium dark:text-secondary-soft">{{ $subtitle }}</p>
            @endif
            @isset($meta)
                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs text-secondary-medium dark:text-secondary-soft">{{ $meta }}</div>
            @endisset
        </div>
        {{ $actions ?? '' }}
    </div>
</header>
```

`resources/views/components/ui/relation-tabs.blade.php`:

```blade
@props(['tabs' => [], 'active' => null, 'wireTarget' => 'tab'])

<div role="tablist" {{ $attributes->class('flex flex-wrap items-center gap-1 border-b border-secondary-soft dark:border-secondary-medium') }}>
    @foreach ($tabs as $tab)
        @php $isActive = $tab['key'] === $active; @endphp
        <button
            type="button"
            role="tab"
            wire:key="relation-tab-{{ $tab['key'] }}"
            wire:click="$set('{{ $wireTarget }}', '{{ $tab['key'] }}')"
            aria-selected="{{ $isActive ? 'true' : 'false' }}"
            @class([
                'inline-flex min-h-11 touch-manipulation items-center gap-1.5 border-b-2 px-3 text-sm font-medium transition-colors sm:min-h-10',
                'border-primary text-secondary-dark dark:text-white' => $isActive,
                'border-transparent text-secondary-medium hover:text-secondary-dark dark:text-secondary-soft dark:hover:text-white' => ! $isActive,
            ])
        >
            {{ $tab['label'] }}
            @if (($tab['count'] ?? null) !== null)
                <span class="inline-flex min-w-5 items-center justify-center rounded-md border border-secondary-light px-1 text-[11px] font-semibold tabular-nums">{{ $tab['count'] }}</span>
            @endif
        </button>
    @endforeach
</div>
```

`resources/views/components/ui/tree-rows.blade.php`:

```blade
@props(['nodes', 'depth' => 0, 'label', 'badge' => null])

@foreach ($nodes as $node)
    <div
        data-tree-depth="{{ $depth }}"
        wire:key="tree-node-{{ $node->getKey() }}"
        class="flex items-center gap-2.5 border-b border-secondary-soft px-3.5 py-2.5 last:border-b-0 dark:border-secondary-medium"
        style="padding-inline-start: {{ 0.875 + ($depth * 1.25) }}rem"
    >
        @if ($depth > 0)
            <span aria-hidden="true" class="text-secondary-light">└</span>
        @endif

        <p class="min-w-0 flex-1 truncate text-sm text-secondary-dark dark:text-white">{{ $label($node) }}</p>

        @if ($badge !== null)
            <x-ui.enum-badge :case="$badge($node)" />
        @endif
    </div>

    @if ($node->children->isNotEmpty())
        <x-ui.tree-rows :nodes="$node->children" :depth="$depth + 1" :label="$label" :badge="$badge" />
    @endif
@endforeach
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/UiComponentsTest.php`
Expected: PASS

- [ ] **Step 5: Format and stage**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components/ui tests/Feature/UiComponentsTest.php
```

Stop and report. Do not commit.

---

### Task 5: Strategy detail page

**Files:**
- Create: `resources/views/pages/strategies/⚡show.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/pages/strategies/⚡index.blade.php`
- Test: `tests/Feature/StrategyDetailTest.php`

**Interfaces:**
- Consumes: `x-ui.detail-header`, `x-ui.panel`, `x-ui.empty-state`, `x-ui.stat-tile`, `x-ui.user-chip` (Tasks 2–4).
- Produces: route `strategies.show` taking a `Strategy`. Component alias `pages::strategies.show`, public property `public int $strategyId`.

This is the first page consumer. It establishes the detail-page shape every later detail page copies: `mount(Model $model)` stores the id, `#[Computed]` re-reads with explicit eager loads.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Program;
use App\Models\Strategy;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from strategy detail', function () {
    $strategy = Strategy::factory()->create();

    $this->get(route('strategies.show', $strategy))->assertRedirect(route('login'));
});

test('strategy detail shows the strategy its period and its programs', function () {
    $user = User::factory()->create();
    $manager = User::factory()->create(['name' => 'Layla Haddad']);
    $strategy = Strategy::factory()->create([
        'name' => 'Country Strategy',
        'start_year' => 2026,
        'end_year' => 2030,
    ]);
    Program::factory()->create([
        'name' => 'Water Security',
        'strategy_id' => $strategy->id,
        'manager_id' => $manager->id,
    ]);

    $this->actingAs($user)
        ->get(route('strategies.show', $strategy))
        ->assertOk()
        ->assertSeeText('Country Strategy')
        ->assertSeeText('2026')
        ->assertSeeText('2030')
        ->assertSeeText('Water Security')
        ->assertSeeText('Layla Haddad');
});

test('strategy detail does not show another strategy programs', function () {
    $user = User::factory()->create();
    $subject = Strategy::factory()->create(['name' => 'Country Strategy']);
    $other = Strategy::factory()->create(['name' => 'Regional Strategy']);

    Program::factory()->create(['name' => 'Water Security', 'strategy_id' => $subject->id]);
    Program::factory()->create(['name' => 'Safe Shelter', 'strategy_id' => $other->id]);

    $this->actingAs($user)
        ->get(route('strategies.show', $subject))
        ->assertOk()
        ->assertSeeText('Water Security')
        ->assertDontSeeText('Safe Shelter');
});

test('strategy detail renders a strategy with no programs', function () {
    $user = User::factory()->create();
    $strategy = Strategy::factory()->create(['name' => 'Empty Strategy']);

    $this->actingAs($user)
        ->get(route('strategies.show', $strategy))
        ->assertOk()
        ->assertSeeText('Empty Strategy')
        ->assertSeeText('No programs yet');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/StrategyDetailTest.php`
Expected: FAIL — `Route [strategies.show] not defined.`

- [ ] **Step 3: Add the route**

In `routes/web.php`, directly after the existing `strategies.index` registration:

```php
    Route::livewire('strategies/{strategy}', 'pages::strategies.show')
        ->name('strategies.show');
```

- [ ] **Step 4: Write the page**

`resources/views/pages/strategies/⚡show.blade.php`:

```blade
<?php

use App\Models\Program;
use App\Models\Strategy;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Strategy')] class extends Component {
    public int $strategyId;

    public function mount(Strategy $strategy): void
    {
        $this->strategyId = $strategy->id;
    }

    #[Computed]
    public function strategy(): Strategy
    {
        return Strategy::query()
            ->select(['id', 'name', 'start_year', 'end_year', 'updated_at'])
            ->withCount('programs')
            ->findOrFail($this->strategyId);
    }

    /** @return Collection<int, Program> */
    #[Computed]
    public function programs(): Collection
    {
        return Program::query()
            ->select(['id', 'name', 'strategy_id', 'manager_id'])
            ->where('strategy_id', $this->strategyId)
            ->with(['manager:id,name,email,role'])
            ->withCount(['projects', 'yearlyPlans'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }
};
?>

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('Strategies'), 'url' => route('strategies.index')],
            ['label' => $this->strategy->name],
        ]"
        :title="$this->strategy->name"
        :subtitle="__('Strategic period :start to :end', ['start' => $this->strategy->start_year, 'end' => $this->strategy->end_year])"
    >
        <x-slot:meta>
            <span>{{ trans_choice(':count program|:count programs', $this->strategy->programs_count, ['count' => $this->strategy->programs_count]) }}</span>
            <span>{{ __('Updated :date', ['date' => $this->strategy->updated_at?->format('M j, Y')]) }}</span>
        </x-slot:meta>
    </x-ui.detail-header>

    <div class="grid gap-3 @2xl/detail:grid-cols-3">
        <x-ui.stat-tile :label="__('Period start')" :value="$this->strategy->start_year" />
        <x-ui.stat-tile :label="__('Period end')" :value="$this->strategy->end_year" />
        <x-ui.stat-tile :label="__('Programs')" :value="$this->strategy->programs_count" :unit="__('total')" />
    </div>

    <x-ui.panel :title="__('Programs')" :count="$this->programs->count()" :subtitle="__('Delivery programs carried by this strategy.')">
        @if ($this->programs->isEmpty())
            <x-ui.empty-state
                icon="briefcase"
                :heading="__('No programs yet')"
                :body="__('Programs linked to this strategy will appear here.')"
            />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->programs as $program)
                    <article class="flex flex-col gap-3 p-3.5 @3xl/detail:flex-row @3xl/detail:items-center" wire:key="strategy-program-{{ $program->id }}">
                        <div class="min-w-0 @3xl/detail:me-auto">
                            <a href="{{ route('programs.show', $program) }}" wire:navigate class="truncate text-sm font-semibold text-secondary-dark hover:underline dark:text-white">
                                {{ $program->name }}
                            </a>
                            <p class="text-xs text-secondary-medium dark:text-secondary-soft">
                                {{ trans_choice(':count project|:count projects', $program->projects_count, ['count' => $program->projects_count]) }}
                                ·
                                {{ trans_choice(':count annual plan|:count annual plans', $program->yearly_plans_count, ['count' => $program->yearly_plans_count]) }}
                            </p>
                        </div>
                        <x-ui.user-chip :user="$program->manager" class="@3xl/detail:w-64" />
                    </article>
                @endforeach
            </div>
        @endif
    </x-ui.panel>
</section>
```

**Note:** this page links to `programs.show`, which Task 6 creates. Implement Task 6 before running the full suite, or the link generation throws `RouteNotFoundException`. If running Task 5's tests standalone first, temporarily assert only the non-link tests — but the intended order is 5 then 6, and Task 6's test run covers both.

- [ ] **Step 5: Add the row link on the strategies index**

In `resources/views/pages/strategies/⚡index.blade.php`, wrap the strategy name cell content in a link. Find the element rendering `{{ $strategy->name }}` in the desktop table and replace that text node with:

```blade
<a href="{{ route('strategies.show', $strategy) }}" wire:navigate class="truncate font-semibold hover:underline">{{ $strategy->name }}</a>
```

Apply the same change to the mobile card's `<h2>` content.

- [ ] **Step 6: Run tests**

Run: `vendor/bin/pest tests/Feature/StrategyDetailTest.php tests/Feature/StrategyTest.php`
Expected: PASS (the existing `StrategyTest` must stay green — the index edit is presentational only)

- [ ] **Step 7: Format and stage**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/pages/strategies tests/Feature/StrategyDetailTest.php
```

Stop and report. Do not commit.

---

### Task 6: Program detail page

**Files:**
- Create: `resources/views/pages/programs/⚡show.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/pages/programs/⚡index.blade.php`
- Test: `tests/Feature/ProgramDetailTest.php`

**Interfaces:**
- Consumes: `x-ui.detail-header`, `x-ui.panel`, `x-ui.empty-state`, `x-ui.stat-tile`, `x-ui.user-chip`, `x-ui.relation-tabs`, `x-ui.tree-rows` (Tasks 2–4).
- Produces: route `programs.show` taking a `Program`. Component alias `pages::programs.show`, public properties `public int $programId` and `public string $tab` (`'projects'`, `'plans'`, `'results'`).

This page carries the first `x-ui.relation-tabs` and the first `x-ui.tree-rows` consumer — the results logframe, loaded through `childrenRecursive`. `results.program_id` is nullable, so only this program's roots are shown; unassigned results belong to the M&E plan's Results page.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\ResultType;
use App\Models\Program;
use App\Models\Project;
use App\Models\Result;
use App\Models\Strategy;
use App\Models\User;
use App\Models\YearlyPlan;
use Livewire\Livewire;

test('guests are redirected from program detail', function () {
    $program = Program::factory()->create();

    $this->get(route('programs.show', $program))->assertRedirect(route('login'));
});

test('program detail shows the program its strategy and its manager', function () {
    $user = User::factory()->create();
    $manager = User::factory()->create(['name' => 'Layla Haddad']);
    $strategy = Strategy::factory()->create(['name' => 'Country Strategy']);
    $program = Program::factory()->create([
        'name' => 'Water Security',
        'strategy_id' => $strategy->id,
        'manager_id' => $manager->id,
    ]);

    $this->actingAs($user)
        ->get(route('programs.show', $program))
        ->assertOk()
        ->assertSeeText('Water Security')
        ->assertSeeText('Country Strategy')
        ->assertSeeText('Layla Haddad');
});

test('program detail lists only its own projects', function () {
    $user = User::factory()->create();
    $subject = Program::factory()->create();
    $other = Program::factory()->create();

    Project::factory()->create(['name' => 'Aleppo Wells', 'program_id' => $subject->id]);
    Project::factory()->create(['name' => 'Homs Shelter', 'program_id' => $other->id]);

    Livewire::actingAs($user)
        ->test('pages::programs.show', ['program' => $subject])
        ->assertSet('tab', 'projects')
        ->assertSee('Aleppo Wells')
        ->assertDontSee('Homs Shelter');
});

test('program detail switches to annual plans and results', function () {
    $user = User::factory()->create();
    $program = Program::factory()->create();
    YearlyPlan::factory()->create(['program_id' => $program->id, 'year' => 2026]);
    Result::factory()->create([
        'program_id' => $program->id,
        'statement' => 'Improved access to safe water.',
        'type' => ResultType::Impact,
    ]);

    Livewire::actingAs($user)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'plans')
        ->assertSee('2026')
        ->set('tab', 'results')
        ->assertSee('Improved access to safe water.');
});

test('program detail renders the results logframe as a tree', function () {
    $user = User::factory()->create();
    $program = Program::factory()->create();
    $impact = Result::factory()->create([
        'program_id' => $program->id,
        'statement' => 'Root impact statement.',
        'type' => ResultType::Impact,
    ]);
    Result::factory()->create([
        'program_id' => $program->id,
        'parent_id' => $impact->id,
        'statement' => 'Nested outcome statement.',
        'type' => ResultType::Outcome,
    ]);

    Livewire::actingAs($user)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'results')
        ->assertSee('Root impact statement.')
        ->assertSee('Nested outcome statement.')
        ->assertSee('data-tree-depth="1"', false);
});

test('program detail renders a program with nothing attached', function () {
    $user = User::factory()->create();
    $program = Program::factory()->create(['name' => 'Bare Program']);

    $this->actingAs($user)
        ->get(route('programs.show', $program))
        ->assertOk()
        ->assertSeeText('Bare Program')
        ->assertSeeText('No projects yet');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/ProgramDetailTest.php`
Expected: FAIL — `Route [programs.show] not defined.`

- [ ] **Step 3: Add the route**

In `routes/web.php`, directly after the existing `programs.index` registration:

```php
    Route::livewire('programs/{program}', 'pages::programs.show')
        ->name('programs.show');
```

- [ ] **Step 4: Write the page**

`resources/views/pages/programs/⚡show.blade.php`:

```blade
<?php

use App\Models\Program;
use App\Models\Project;
use App\Models\Result;
use App\Models\YearlyPlan;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Program')] class extends Component {
    public int $programId;

    #[Url(except: 'projects')]
    public string $tab = 'projects';

    public function mount(Program $program): void
    {
        $this->programId = $program->id;
    }

    #[Computed]
    public function program(): Program
    {
        return Program::query()
            ->select(['id', 'name', 'strategy_id', 'manager_id', 'updated_at'])
            ->with([
                'strategy:id,name,start_year,end_year',
                'manager:id,name,email,role',
            ])
            ->withCount(['projects', 'yearlyPlans', 'results'])
            ->findOrFail($this->programId);
    }

    /** @return Collection<int, Project> */
    #[Computed]
    public function projects(): Collection
    {
        return Project::query()
            ->select(['id', 'name', 'city_id', 'program_id', 'user_id'])
            ->where('program_id', $this->programId)
            ->with(['city:id,name', 'user:id,name,email,role'])
            ->withCount(['risks', 'milestones'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, YearlyPlan> */
    #[Computed]
    public function yearlyPlans(): Collection
    {
        return YearlyPlan::query()
            ->select(['id', 'program_id', 'manager_id', 'year'])
            ->where('program_id', $this->programId)
            ->with(['manager:id,name,email,role'])
            ->withCount('planIndicators')
            ->orderByDesc('year')
            ->get();
    }

    /**
     * The program's logframe roots, with their whole subtree loaded.
     *
     * childrenRecursive costs one query per level and the logframe is three
     * levels deep at most, so the tree renders without touching the database
     * again per node.
     *
     * @return Collection<int, Result>
     */
    #[Computed]
    public function resultRoots(): Collection
    {
        return Result::query()
            ->select(['id', 'statement', 'parent_id', 'program_id', 'type', 'checkout_period'])
            ->where('program_id', $this->programId)
            ->roots()
            ->with('childrenRecursive')
            ->get();
    }

    /** @return array<int, array{key: string, label: string, count: int}> */
    #[Computed]
    public function tabs(): array
    {
        return [
            ['key' => 'projects', 'label' => __('Projects'), 'count' => $this->program->projects_count],
            ['key' => 'plans', 'label' => __('Annual plans'), 'count' => $this->program->yearly_plans_count],
            ['key' => 'results', 'label' => __('Results'), 'count' => $this->program->results_count],
        ];
    }
};
?>

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('Programs'), 'url' => route('programs.index')],
            ['label' => $this->program->name],
        ]"
        :title="$this->program->name"
    >
        <x-slot:meta>
            <a href="{{ route('strategies.show', $this->program->strategy) }}" wire:navigate class="font-medium hover:text-secondary-dark hover:underline dark:hover:text-white">
                {{ $this->program->strategy->name }}
                ({{ $this->program->strategy->start_year }}–{{ $this->program->strategy->end_year }})
            </a>
            <span>{{ __('Updated :date', ['date' => $this->program->updated_at?->format('M j, Y')]) }}</span>
        </x-slot:meta>

        <x-slot:actions>
            <x-ui.user-chip :user="$this->program->manager" class="rounded-lg border border-secondary-soft p-2.5 dark:border-secondary-medium" />
        </x-slot:actions>
    </x-ui.detail-header>

    <x-ui.relation-tabs :tabs="$this->tabs" :active="$tab" wire-target="tab" />

    @if ($tab === 'projects')
        <x-ui.panel :title="__('Projects')" :count="$this->projects->count()">
            @if ($this->projects->isEmpty())
                <x-ui.empty-state icon="rectangle-stack" :heading="__('No projects yet')" :body="__('Projects delivered under this program will appear here.')" />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->projects as $project)
                        <article class="flex flex-col gap-3 p-3.5 @3xl/detail:flex-row @3xl/detail:items-center" wire:key="program-project-{{ $project->id }}">
                            <div class="min-w-0 @3xl/detail:me-auto">
                                <a href="{{ route('projects.show', $project) }}" wire:navigate class="truncate text-sm font-semibold hover:underline">{{ $project->name }}</a>
                                <p class="text-xs text-secondary-medium dark:text-secondary-soft">
                                    {{ $project->city->name }}
                                    ·
                                    {{ trans_choice(':count risk|:count risks', $project->risks_count, ['count' => $project->risks_count]) }}
                                    ·
                                    {{ trans_choice(':count milestone|:count milestones', $project->milestones_count, ['count' => $project->milestones_count]) }}
                                </p>
                            </div>
                            <x-ui.user-chip :user="$project->user" class="@3xl/detail:w-64" />
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    @elseif ($tab === 'plans')
        <x-ui.panel :title="__('Annual plans')" :count="$this->yearlyPlans->count()">
            @if ($this->yearlyPlans->isEmpty())
                <x-ui.empty-state icon="calendar-days" :heading="__('No annual plans yet')" :body="__('Operational plans written for this program will appear here.')" />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->yearlyPlans as $plan)
                        <article class="flex flex-col gap-3 p-3.5 @3xl/detail:flex-row @3xl/detail:items-center" wire:key="program-plan-{{ $plan->id }}">
                            <div class="min-w-0 @3xl/detail:me-auto">
                                <a href="{{ route('yearly-plans.show', $plan) }}" wire:navigate class="text-sm font-semibold tabular-nums hover:underline">{{ __('FY :year', ['year' => $plan->year]) }}</a>
                                <p class="text-xs text-secondary-medium dark:text-secondary-soft">
                                    {{ trans_choice(':count target|:count targets', $plan->plan_indicators_count, ['count' => $plan->plan_indicators_count]) }}
                                </p>
                            </div>
                            <x-ui.user-chip :user="$plan->manager" class="@3xl/detail:w-64" />
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    @else
        <x-ui.panel :title="__('Results logframe')" :count="$this->program->results_count" :subtitle="__('Impact, outcome and output statements, nested as recorded.')">
            @if ($this->resultRoots->isEmpty())
                <x-ui.empty-state icon="chart-bar" :heading="__('No results yet')" :body="__('Logframe statements assigned to this program will appear here.')" />
            @else
                <x-ui.tree-rows
                    :nodes="$this->resultRoots"
                    :label="fn (\App\Models\Result $result): string => $result->statement"
                    :badge="fn (\App\Models\Result $result) => $result->type"
                />
            @endif
        </x-ui.panel>
    @endif
</section>
```

**Note:** this page links to `projects.show` and `yearly-plans.show`. `yearly-plans.show` arrives in Task 7; `projects.show` arrives in the Delivery plan. Until the Delivery plan lands, the projects tab's link target does not exist and `route('projects.show', …)` throws. **Task 7 therefore includes a guard step** that makes both links conditional on `Route::has(...)`, matching the pattern `login.blade.php` already uses for the optional `register` route (`.ai/rules/enums.md` records that trap). Do not skip it.

- [ ] **Step 5: Add the row link on the programs index**

In `resources/views/pages/programs/⚡index.blade.php`, in the desktop table's first cell, replace:

```blade
<p class="truncate font-semibold">{{ $program->name }}</p>
```

with:

```blade
<a href="{{ route('programs.show', $program) }}" wire:navigate class="truncate font-semibold hover:underline">{{ $program->name }}</a>
```

And in the mobile card, replace the `<h2>` content the same way.

- [ ] **Step 6: Run tests**

Run: `vendor/bin/pest tests/Feature/ProgramDetailTest.php tests/Feature/StrategyDetailTest.php tests/Feature/ProgramManagementTest.php`
Expected: The `programs.show` tests fail on `Route [projects.show] not defined` until Task 7's guard lands. Proceed to Task 7, then re-run. `ProgramManagementTest` must stay green.

- [ ] **Step 7: Format and stage**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/pages/programs tests/Feature/ProgramDetailTest.php
```

Stop and report. Do not commit.

---

### Task 7: Annual plan detail page, and the route guard

**Files:**
- Create: `resources/views/pages/yearly-plans/⚡show.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/pages/programs/⚡show.blade.php`
- Modify: `resources/views/pages/yearly-plans/⚡index.blade.php`
- Test: `tests/Feature/YearlyPlanDetailTest.php`

**Interfaces:**
- Consumes: `x-ui.detail-header`, `x-ui.panel`, `x-ui.empty-state`, `x-ui.stat-tile`, `x-ui.user-chip`, `x-ui.enum-badge`.
- Produces: route `yearly-plans.show` taking a `YearlyPlan`. Component alias `pages::yearly-plans.show`, public property `public int $yearlyPlanId`.

This page reads `plan_indicators` through the **morph source** — the plan's `morphMany`. It is the first page to touch the polymorphic relation, and it must display the source using the map key, never a class name.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Result;
use App\Models\User;
use App\Models\YearlyPlan;
use Livewire\Livewire;

test('guests are redirected from annual plan detail', function () {
    $plan = YearlyPlan::factory()->create();

    $this->get(route('yearly-plans.show', $plan))->assertRedirect(route('login'));
});

test('annual plan detail shows the plan its program manager and year', function () {
    $user = User::factory()->create();
    $manager = User::factory()->create(['name' => 'Layla Haddad']);
    $program = Program::factory()->create(['name' => 'Water Security']);
    $plan = YearlyPlan::factory()->create([
        'program_id' => $program->id,
        'manager_id' => $manager->id,
        'year' => 2026,
    ]);

    $this->actingAs($user)
        ->get(route('yearly-plans.show', $plan))
        ->assertOk()
        ->assertSeeText('2026')
        ->assertSeeText('Water Security')
        ->assertSeeText('Layla Haddad');
});

test('annual plan detail lists targets committed in that plan only', function () {
    $user = User::factory()->create();
    $plan = YearlyPlan::factory()->create();
    $otherPlan = YearlyPlan::factory()->create();
    $result = Result::factory()->create();
    $indicator = Indicator::factory()->create([
        'result_id' => $result->id,
        'name' => 'People reached with safe water',
    ]);
    $otherIndicator = Indicator::factory()->create([
        'result_id' => $result->id,
        'name' => 'Latrines constructed',
    ]);

    PlanIndicator::factory()->create([
        'source_type' => 'yearly_plan',
        'source_id' => $plan->id,
        'indicator_id' => $indicator->id,
        'target' => '12000.00',
    ]);
    PlanIndicator::factory()->create([
        'source_type' => 'yearly_plan',
        'source_id' => $otherPlan->id,
        'indicator_id' => $otherIndicator->id,
    ]);

    $this->actingAs($user)
        ->get(route('yearly-plans.show', $plan))
        ->assertOk()
        ->assertSeeText('People reached with safe water')
        ->assertDontSeeText('Latrines constructed')
        ->assertSeeText('12,000.00');
});

test('annual plan detail renders a plan with no targets', function () {
    $user = User::factory()->create();
    $plan = YearlyPlan::factory()->create(['year' => 2027]);

    $this->actingAs($user)
        ->get(route('yearly-plans.show', $plan))
        ->assertOk()
        ->assertSeeText('No targets yet');
});

test('a target with no project reads as organisation wide', function () {
    $user = User::factory()->create();
    $plan = YearlyPlan::factory()->create();
    $indicator = Indicator::factory()->create();

    PlanIndicator::factory()->create([
        'source_type' => 'yearly_plan',
        'source_id' => $plan->id,
        'indicator_id' => $indicator->id,
        'project_id' => null,
    ]);

    $this->actingAs($user)
        ->get(route('yearly-plans.show', $plan))
        ->assertOk()
        ->assertSeeText('Organisation-wide');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/YearlyPlanDetailTest.php`
Expected: FAIL — `Route [yearly-plans.show] not defined.`

- [ ] **Step 3: Add the route**

In `routes/web.php`, directly after the existing `yearly-plans.index` registration:

```php
    Route::livewire('yearly-plans/{yearlyPlan}', 'pages::yearly-plans.show')
        ->name('yearly-plans.show');
```

- [ ] **Step 4: Write the page**

`resources/views/pages/yearly-plans/⚡show.blade.php`:

```blade
<?php

use App\Models\PlanIndicator;
use App\Models\YearlyPlan;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Annual Plan')] class extends Component {
    public int $yearlyPlanId;

    public function mount(YearlyPlan $yearlyPlan): void
    {
        $this->yearlyPlanId = $yearlyPlan->id;
    }

    #[Computed]
    public function plan(): YearlyPlan
    {
        return YearlyPlan::query()
            ->select(['id', 'program_id', 'manager_id', 'year', 'updated_at'])
            ->with([
                'program:id,name,strategy_id',
                'program.strategy:id,name',
                'manager:id,name,email,role',
            ])
            ->withCount('planIndicators')
            ->findOrFail($this->yearlyPlanId);
    }

    /**
     * The targets committed in this plan.
     *
     * Filtered on the morph map key 'yearly_plan', never a class name: the
     * column is VARCHAR(50) and the map is enforced application-wide.
     *
     * @return Collection<int, PlanIndicator>
     */
    #[Computed]
    public function targets(): Collection
    {
        return PlanIndicator::query()
            ->select(['id', 'source_type', 'source_id', 'indicator_id', 'target', 'project_id', 'period'])
            ->where('source_type', 'yearly_plan')
            ->where('source_id', $this->yearlyPlanId)
            ->with([
                'indicator:id,name,result_id,measure',
                'indicator.result:id,statement,type',
                'project:id,name,city_id',
                'project.city:id,name',
            ])
            ->withCount('reports')
            ->get();
    }
};
?>

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('Annual Plans'), 'url' => route('yearly-plans.index')],
            ['label' => __('FY :year', ['year' => $this->plan->year])],
        ]"
        :title="__('FY :year · :program', ['year' => $this->plan->year, 'program' => $this->plan->program->name])"
        :subtitle="$this->plan->program->strategy->name"
    >
        <x-slot:meta>
            @if (Route::has('programs.show'))
                <a href="{{ route('programs.show', $this->plan->program) }}" wire:navigate class="font-medium hover:text-secondary-dark hover:underline dark:hover:text-white">
                    {{ $this->plan->program->name }}
                </a>
            @else
                <span class="font-medium">{{ $this->plan->program->name }}</span>
            @endif
            <span>{{ trans_choice(':count target|:count targets', $this->plan->plan_indicators_count, ['count' => $this->plan->plan_indicators_count]) }}</span>
        </x-slot:meta>

        <x-slot:actions>
            <x-ui.user-chip :user="$this->plan->manager" class="rounded-lg border border-secondary-soft p-2.5 dark:border-secondary-medium" />
        </x-slot:actions>
    </x-ui.detail-header>

    <x-ui.panel :title="__('Committed targets')" :count="$this->targets->count()" :subtitle="__('Indicator targets this plan is accountable for.')">
        @if ($this->targets->isEmpty())
            <x-ui.empty-state icon="chart-bar" :heading="__('No targets yet')" :body="__('Indicator targets committed in this plan will appear here.')" />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->targets as $target)
                    <article class="flex flex-col gap-2 p-3.5 @3xl/detail:flex-row @3xl/detail:items-center" wire:key="plan-target-{{ $target->id }}">
                        <div class="min-w-0 @3xl/detail:me-auto">
                            <p class="truncate text-sm font-semibold">{{ $target->indicator->name }}</p>
                            <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">
                                {{ $target->indicator->result->statement }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 @3xl/detail:justify-end">
                            <span class="text-xs text-secondary-medium dark:text-secondary-soft">
                                @if ($target->project === null)
                                    {{ __('Organisation-wide') }}
                                @else
                                    {{ $target->project->name }} · {{ $target->project->city->name }}
                                @endif
                            </span>
                            <x-ui.enum-badge :case="$target->period" />
                            <span class="text-sm font-semibold tabular-nums">{{ number_format((float) $target->target, 2) }}</span>
                            <span class="inline-flex min-w-6 items-center justify-center rounded-md border border-secondary-light px-1.5 py-0.5 text-[11px] font-semibold tabular-nums text-secondary-medium dark:text-secondary-soft">
                                {{ $target->reports_count }}
                            </span>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </x-ui.panel>
</section>
```

- [ ] **Step 5: Guard the forward links on the program detail page**

`resources/views/pages/programs/⚡show.blade.php` links to `projects.show`, which does not exist until the Delivery plan lands. Guard it exactly as `login.blade.php` guards `register`.

Replace:

```blade
<a href="{{ route('projects.show', $project) }}" wire:navigate class="truncate text-sm font-semibold hover:underline">{{ $project->name }}</a>
```

with:

```blade
@if (Route::has('projects.show'))
    <a href="{{ route('projects.show', $project) }}" wire:navigate class="truncate text-sm font-semibold hover:underline">{{ $project->name }}</a>
@else
    <p class="truncate text-sm font-semibold">{{ $project->name }}</p>
@endif
```

- [ ] **Step 6: Add the cell link on the annual plans matrix**

In `resources/views/pages/yearly-plans/⚡index.blade.php`, the matrix cell that renders an existing plan becomes a link to `yearly-plans.show`. Locate the cell body rendering a present plan and wrap its content:

```blade
<a href="{{ route('yearly-plans.show', $plan) }}" wire:navigate class="inline-flex min-h-11 items-center justify-center rounded-md px-2 hover:underline sm:min-h-8">
```

Do **not** add a query inside the cell loop. `.ai/rules/yearly-plans.md` pins the matrix at exactly four queries and `YearlyPlanMatrixTest` asserts that count; the link uses the plan object already held in the `planFor()` lookup array.

- [ ] **Step 7: Run tests**

Run: `vendor/bin/pest tests/Feature/YearlyPlanDetailTest.php tests/Feature/ProgramDetailTest.php tests/Feature/StrategyDetailTest.php tests/Feature/YearlyPlanMatrixTest.php`
Expected: PASS — all four. `YearlyPlanMatrixTest`'s query-count assertion must still hold at 4.

- [ ] **Step 8: Format and stage**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/pages tests/Feature/YearlyPlanDetailTest.php
```

Stop and report. Do not commit.

---

### Task 8: Regroup the sidebar

**Files:**
- Modify: `resources/views/components/navigation/sidebar.blade.php`
- Test: `tests/Feature/NavigationTest.php` (create)

**Interfaces:**
- Consumes: the routes registered in Tasks 5–7.
- Produces: no new interface. Establishes the group structure the three spine plans append items to.

`.ai/rules/app.md`: navigation items are added only when their named routes exist. This task creates the empty-capable groups **Strategy** and **Administration** and moves existing items into them. `Delivery`, `Results & Learning` and `Funding` groups are **not** created here — they have no routes yet and a group with no items is a placeholder. Each spine plan creates its own group when it registers its first route.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\User;

test('the sidebar groups navigation under overview strategy and administration', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeTextInOrder(['Overview', 'Strategy', 'Administration']);
});

test('the sidebar does not render a group for routes that do not exist yet', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSeeText('Results & Learning')
        ->assertDontSeeText('Funding');
});

test('every sidebar link resolves to a registered route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
})->throwsNoExceptions();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/NavigationTest.php`
Expected: FAIL — "Strategy" is not present; the current sidebar has one "Overview" group holding all five items.

- [ ] **Step 3: Regroup**

In `resources/views/components/navigation/sidebar.blade.php`, split the existing `Overview` group. Keep `Dashboard` and `Executive Summary` in `Overview`. Move `Strategies`, `Programs` and `Annual Plans` into a new group placed immediately after it:

```blade
        <flux:sidebar.group
            :heading="__('Strategy')"
            :class="$navigationGroupClasses"
        >
            <flux:sidebar.item
                icon="chart-bar-square"
                :href="route('strategies.index')"
                :current="request()->routeIs('strategies.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('Strategies') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="briefcase"
                :href="route('programs.index')"
                :current="request()->routeIs('programs.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('Programs') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="calendar-days"
                :href="route('yearly-plans.index')"
                :current="request()->routeIs('yearly-plans.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('Annual Plans') }}
            </flux:sidebar.item>
        </flux:sidebar.group>
```

Move the existing `Administration` group so it sits after `Strategy` and before `Settings`. Leave the `Settings` group and the `@can('viewAny', User::class)` guard on the user directory untouched.

Note the `:current` expressions already use `routeIs('programs.*')`, so the new `programs.show` route highlights the correct item with no further change.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/NavigationTest.php`
Expected: PASS

- [ ] **Step 5: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS, with no regression in `ProgramManagementTest`, `YearlyPlanMatrixTest`, `ExecutiveSummaryTest`, `CityDirectoryTest` or `UserDirectoryTest`.

- [ ] **Step 6: Format, type-check and stage**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
git add resources/views/components/navigation/sidebar.blade.php tests/Feature/NavigationTest.php
```

Stop and report. Do not commit.

---

## Self-Review

**Spec coverage.** This plan implements the spec's "Shared presentation components" section (Tasks 1–4), the three "Strategy / Program / Annual Plan detail" rows of the sections table (Tasks 5–7), and the sidebar regrouping (Task 8). The fifteen remaining sections belong to the three spine plans and are not in scope here. The spec's "Non-goals", "Global constraints" and "Edge cases" carry into every task through the Global Constraints block above.

**Deviation from the spec, recorded deliberately.** The spec lists ten `x-ui.*` components; this plan builds **thirteen**. The three additions — `x-ui.input`, `x-ui.select`, `x-ui.button` — exist because the spec's stated aim of removing the per-page `@php` class block cannot be met by the ten alone: the filter row and modal forms use Flux inputs directly, so without wrappers every new page would still repeat `$controlClasses`. This is an addition in service of a stated goal, not a scope change.

**Placeholder scan.** No TBD, no "add appropriate error handling", no "similar to Task N". Every code step carries the literal file content. The two forward-reference hazards (`programs.show` → `projects.show`, and `strategies.show` → `programs.show`) are called out at the step that creates them and closed by Task 7 Step 5.

**Type consistency.** `$strategyId`, `$programId`, `$yearlyPlanId` follow one naming rule. `#[Computed]` accessors are singular for the subject (`strategy()`, `program()`, `plan()`) and plural for collections (`programs()`, `projects()`, `targets()`). `x-ui.tree-rows` takes `:label` and `:badge` as closures in both Task 4's test and Task 6's use. `x-ui.enum-badge` takes `:case` everywhere. `x-ui.relation-tabs` takes `wire-target` in markup, received as `$wireTarget`.

**Known risk carried forward.** Task 6's `programs.show` test cannot pass until Task 7 Step 5 lands the `Route::has('projects.show')` guard. This is stated at Task 6 Step 6 rather than hidden. Tasks 6 and 7 should be implemented back to back.
