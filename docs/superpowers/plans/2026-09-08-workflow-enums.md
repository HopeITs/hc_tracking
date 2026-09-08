# Workflow Enums Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace six free-text `VARCHAR(50)` workflow columns with PHP 8.5 typed backed enums, and derive `proposals.phase` from `proposals.status` instead of storing it.

**Architecture:** Six string-backed enums in `app/Enums/`, each copying the existing `App\Enums\UserRole` exactly — `declare(strict_types=1)`, TitleCase case names, one `public static function values(): array`. `ProposalStatus::phase()` maps each status onto a `ProposalPhase` through a `match` with no `default` arm; `ProposalPhase::statuses()` derives the reverse lookup from that same `match`, so the mapping is written once. The `proposals.phase` column is dropped and replaced by a read-only accessor on `Proposal`.

**Tech Stack:** PHP 8.5.10, Laravel 13.30, Pest 4, MySQL/MariaDB, Laravel Pint.

**Spec:** `docs/superpowers/specs/2026-09-08-workflow-enums-design.md`

## Global Constraints

- **PHP binary:** a bare `php` on this machine is XAMPP's 8.4.12 and Composer's platform guard will abort. Always use the project wrapper `./php.cmd` from the repository root. It works from both Bash and PowerShell.
- **Every PHP file in `app/` and `app/Enums/` carries `declare(strict_types=1);`** immediately after `<?php`. Pint's laravel preset neither adds nor strips it — keep it by hand.
- **Enum case names are TitleCase** (`ConceptNote`, `InProgress`, `UnderReview`). **Enum values are lower case** and stored verbatim. Casing is part of the value.
- **Mass assignment uses the Laravel 13 `#[Fillable([...])]` class attribute**, not `protected $fillable`. Casts go in `protected function casts(): array`.
- **`Date::use(CarbonImmutable::class)` is set in `AppServiceProvider`**, so every `date`/`datetime` cast returns `CarbonImmutable`. Do not change existing date casts.
- **Test layering is load-bearing** (`.ai/rules/tests.md`): `tests/Feature/*Test.php` reads rows back with `DB::table(...)->find()` and asserts raw strings — no Eloquent cast may sit between assertion and stored value. `tests/Feature/Models/*Test.php` uses models, and casts are visible there. Do not "fix" a `tests/Feature` string assertion into an enum assertion.
- **Factories omit any column that has a database default**, so the default is what the `tests/Feature` tests prove. Keep it that way.
- **Run `./php.cmd vendor/bin/pint --dirty --format agent` before every commit.** Never `--test`.
- **Tests use `RefreshDatabase` against the MySQL `hc_tracking_test` schema**, so an edited migration is picked up automatically on the next test run. No manual migrate step is needed for tests.
- **Do not delete or rename existing tests.** `tests/Feature/UserRoleTest.php` is the reference pattern and is not modified by this plan.

---

### Task 1: ProposalStatus and ProposalPhase enums

The two proposal enums and the derivation between them. No model, migration or database work — this task is pure PHP and its test needs no database rows.

**Files:**
- Create: `app/Enums/ProposalStatus.php`
- Create: `app/Enums/ProposalPhase.php`
- Test: `tests/Feature/ProposalPhaseTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `App\Enums\ProposalStatus` — cases `ConceptNote`, `ProposalDraft`, `Submitted`, `Approved`, `Rejected`, `Active`, `FinalReports`, `Completed`; `public function phase(): ProposalPhase`; `public static function values(): array` returning `array<int, string>`.
  - `App\Enums\ProposalPhase` — cases `Planning`, `Implementation`, `Monitoring`, `Closure`, `Archived`; `public function statuses(): array` returning `array<int, ProposalStatus>`; `public static function values(): array` returning `array<int, string>`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ProposalPhaseTest.php`:

```php
<?php

use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;

test('every status maps to the phase it belongs to', function (ProposalStatus $status, ProposalPhase $phase) {
    expect($status->phase())->toBe($phase);
})->with([
    'concept note' => [ProposalStatus::ConceptNote, ProposalPhase::Planning],
    'proposal draft' => [ProposalStatus::ProposalDraft, ProposalPhase::Planning],
    'submitted' => [ProposalStatus::Submitted, ProposalPhase::Implementation],
    'approved' => [ProposalStatus::Approved, ProposalPhase::Implementation],
    'rejected' => [ProposalStatus::Rejected, ProposalPhase::Implementation],
    'active' => [ProposalStatus::Active, ProposalPhase::Monitoring],
    'final reports' => [ProposalStatus::FinalReports, ProposalPhase::Closure],
    'completed' => [ProposalStatus::Completed, ProposalPhase::Archived],
]);

test('a phase lists exactly the statuses that map onto it', function () {
    expect(ProposalPhase::Planning->statuses())
        ->toBe([ProposalStatus::ConceptNote, ProposalStatus::ProposalDraft])
        ->and(ProposalPhase::Implementation->statuses())
        ->toBe([ProposalStatus::Submitted, ProposalStatus::Approved, ProposalStatus::Rejected])
        ->and(ProposalPhase::Monitoring->statuses())->toBe([ProposalStatus::Active])
        ->and(ProposalPhase::Closure->statuses())->toBe([ProposalStatus::FinalReports])
        ->and(ProposalPhase::Archived->statuses())->toBe([ProposalStatus::Completed]);
});

/**
 * phase() has no default arm, so a status added without a mapping raises
 * UnhandledMatchError. This proves the other direction: no status is silently
 * left out of every phase, and none is counted twice.
 */
test('every status belongs to exactly one phase', function () {
    $mapped = array_merge(...array_map(
        fn (ProposalPhase $phase): array => $phase->statuses(),
        ProposalPhase::cases(),
    ));

    expect($mapped)->toHaveCount(count(ProposalStatus::cases()));
});

test('every proposal status value fits the fifty character column', function () {
    foreach (ProposalStatus::values() as $value) {
        expect(strlen($value))->toBeLessThanOrEqual(50);
    }
});

test('the proposals status column default is a real status', function () {
    expect(ProposalStatus::tryFrom('concept note'))->toBe(ProposalStatus::ConceptNote);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
./php.cmd artisan test --compact tests/Feature/ProposalPhaseTest.php
```

Expected: FAIL — `Class "App\Enums\ProposalStatus" not found`.

- [ ] **Step 3: Create ProposalPhase**

Create `app/Enums/ProposalPhase.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The phase of work a proposal is in.
 *
 * A phase is never stored. It is derived from ProposalStatus, which is the
 * single source of truth — `proposals` has no `phase` column, because two
 * independent columns could disagree.
 */
enum ProposalPhase: string
{
    case Planning = 'planning';
    case Implementation = 'implementation';
    case Monitoring = 'monitoring';
    case Closure = 'closure';
    case Archived = 'archived';

    /**
     * The statuses that place a proposal in this phase.
     *
     * Derived from ProposalStatus::phase() rather than listed again here, so
     * the mapping between the two enums exists in exactly one place.
     *
     * @return array<int, ProposalStatus>
     */
    public function statuses(): array
    {
        return array_values(array_filter(
            ProposalStatus::cases(),
            fn (ProposalStatus $status): bool => $status->phase() === $this,
        ));
    }

    /**
     * All phase values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

- [ ] **Step 4: Create ProposalStatus**

Create `app/Enums/ProposalStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The states a proposal moves through, from first concept to archive.
 *
 * The value is what lands in `proposals.status` and in the
 * `proposal_status_notes.status` audit trail, both VARCHAR(50). Values are
 * lower case and casing is part of the value; display capitalisation belongs
 * to the UI, not the database.
 */
enum ProposalStatus: string
{
    case ConceptNote = 'concept note';
    case ProposalDraft = 'proposal draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Active = 'active';
    case FinalReports = 'final reports';
    case Completed = 'completed';

    /**
     * The phase of work this status places the proposal in.
     *
     * The match deliberately has no default arm: a status added without a
     * phase raises UnhandledMatchError rather than silently taking a wrong one.
     */
    public function phase(): ProposalPhase
    {
        return match ($this) {
            self::ConceptNote, self::ProposalDraft => ProposalPhase::Planning,
            self::Submitted, self::Approved, self::Rejected => ProposalPhase::Implementation,
            self::Active => ProposalPhase::Monitoring,
            self::FinalReports => ProposalPhase::Closure,
            self::Completed => ProposalPhase::Archived,
        };
    }

    /**
     * All status values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
./php.cmd artisan test --compact tests/Feature/ProposalPhaseTest.php
```

Expected: PASS, 12 tests (8 dataset cases plus 4).

- [ ] **Step 6: Format**

```bash
./php.cmd vendor/bin/pint --dirty --format agent
```

- [ ] **Step 7: Commit**

```bash
git add app/Enums/ProposalStatus.php app/Enums/ProposalPhase.php tests/Feature/ProposalPhaseTest.php
git commit -m "ADD: ProposalStatus and ProposalPhase enums with derived phase mapping"
```

---

### Task 2: Milestone, task and AR enums, cast onto their models

The four columns that have no derivation. Creates the shared cast test that Task 3 extends.

**Files:**
- Create: `app/Enums/MilestoneStatus.php`
- Create: `app/Enums/TaskStatus.php`
- Create: `app/Enums/TaskPriority.php`
- Create: `app/Enums/ArStatus.php`
- Modify: `database/migrations/2026_09_07_124757_create_tasks_table.php:21` — default `'TODO'` becomes `'todo'`
- Modify: `app/Models/Milestone.php` — `status` cast and `@property`
- Modify: `app/Models/Task.php` — `status` and `priority` casts and `@property`
- Modify: `app/Models/TransfersTracking.php` — `ar_status` cast and `@property`
- Modify: `database/factories/TaskFactory.php` — class docblock wording only
- Modify: `tests/Feature/TaskTest.php:7,12` — `'TODO'` becomes `'todo'`
- Modify: `tests/Feature/Models/FactoryStateTest.php:24` — string becomes `ArStatus::Received`
- Test: `tests/Feature/EnumCastTest.php` (create)

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces:
  - `App\Enums\MilestoneStatus` — `Pending`, `Reached`, `Missed`, `Cancelled`; `values(): array<int, string>`.
  - `App\Enums\TaskStatus` — `Todo`, `InProgress`, `UnderReview`, `Done`, `Blocked`; `values(): array<int, string>`.
  - `App\Enums\TaskPriority` — `Low`, `Medium`, `High`; `values(): array<int, string>`.
  - `App\Enums\ArStatus` — `Pending`, `Received`, `Failed`; `values(): array<int, string>`.
  - `tests/Feature/EnumCastTest.php` — a file-local `enumColumns(): array` returning one row per enum-cast column, and the datasets `'enum columns'` and `'defaulted enum columns'` built from it. Task 3 appends two rows to `enumColumns()`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EnumCastTest.php`:

```php
<?php

use App\Enums\ArStatus;
use App\Enums\MilestoneStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Milestone;
use App\Models\Task;
use App\Models\TransfersTracking;
use Illuminate\Support\Facades\DB;
use ValueError;

/**
 * Every enum-cast column in the schema.
 *
 * `assign` is a case written through Eloquent, `stored` the string it must
 * leave in the column. `default` is the case the column default must hydrate
 * as, or null where the column has no default. `badCasing` is a value that
 * differs from a real member only by capitalisation and must therefore be
 * rejected on read.
 *
 * @return array<string, array{0: array<string, mixed>}>
 */
function enumColumns(): array
{
    return [
        'milestones.status' => [[
            'model' => Milestone::class,
            'table' => 'milestones',
            'column' => 'status',
            'assign' => MilestoneStatus::Cancelled,
            'stored' => 'cancelled',
            'default' => MilestoneStatus::Pending,
            'badCasing' => 'Pending',
        ]],
        'tasks.status' => [[
            'model' => Task::class,
            'table' => 'tasks',
            'column' => 'status',
            'assign' => TaskStatus::UnderReview,
            'stored' => 'under review',
            'default' => TaskStatus::Todo,
            'badCasing' => 'TODO',
        ]],
        'tasks.priority' => [[
            'model' => Task::class,
            'table' => 'tasks',
            'column' => 'priority',
            'assign' => TaskPriority::High,
            'stored' => 'high',
            'default' => TaskPriority::Medium,
            'badCasing' => 'Medium',
        ]],
        'transfers_tracking.ar_status' => [[
            'model' => TransfersTracking::class,
            'table' => 'transfers_tracking',
            'column' => 'ar_status',
            'assign' => ArStatus::Failed,
            'stored' => 'failed',
            'default' => ArStatus::Pending,
            'badCasing' => 'Pending',
        ]],
    ];
}

dataset('enum columns', fn (): array => enumColumns());

dataset('defaulted enum columns', fn (): array => array_filter(
    enumColumns(),
    fn (array $row): bool => $row[0]['default'] !== null,
));

test('an enum assigned to a model is stored as its string value', function (array $column) {
    $model = $column['model']::factory()->create([$column['column'] => $column['assign']]);

    expect($model->fresh()->{$column['column']})->toBe($column['assign']);

    $this->assertDatabaseHas($column['table'], [
        'id' => $model->id,
        $column['column'] => $column['stored'],
    ]);
})->with('enum columns');

test('a column default hydrates as its enum case', function (array $column) {
    $model = $column['model']::factory()->create()->fresh();

    expect($model->{$column['column']})->toBe($column['default']);
})->with('defaulted enum columns');

/**
 * Casing is part of the value, exactly as it is for UserRole. A value that
 * differs only by capitalisation is not a fifth member — it is unreadable.
 */
test('a value differing only by casing is not accepted on read', function (array $column) {
    $model = $column['model']::factory()->create();

    DB::table($column['table'])
        ->where('id', $model->id)
        ->update([$column['column'] => $column['badCasing']]);

    $column['model']::query()->findOrFail($model->id)->{$column['column']};
})->with('enum columns')->throws(ValueError::class);

test('every enum value fits the fifty character column', function (array $column) {
    foreach ($column['assign']::values() as $value) {
        expect(strlen($value))->toBeLessThanOrEqual(50);
    }
})->with('enum columns');
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
./php.cmd artisan test --compact tests/Feature/EnumCastTest.php
```

Expected: FAIL — `Class "App\Enums\MilestoneStatus" not found`.

- [ ] **Step 3: Create the four enums**

Create `app/Enums/MilestoneStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a milestone was reached, and if not, why it is no longer pending.
 *
 * The value is what lands in `milestones.status` (VARCHAR(50)). Values are
 * lower case and casing is part of the value.
 */
enum MilestoneStatus: string
{
    case Pending = 'pending';
    case Reached = 'reached';
    case Missed = 'missed';
    case Cancelled = 'cancelled';

    /**
     * All milestone status values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

Create `app/Enums/TaskStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a task sits on its board.
 *
 * The value is what lands in `tasks.status` (VARCHAR(50)). Values are lower
 * case and casing is part of the value: the column default was 'TODO' before
 * this enum existed, which made it the one column in the schema cased
 * differently from its neighbours.
 */
enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in progress';
    case UnderReview = 'under review';
    case Done = 'done';
    case Blocked = 'blocked';

    /**
     * All task status values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

Create `app/Enums/TaskPriority.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How urgently a task needs to be picked up.
 *
 * The value is what lands in `tasks.priority` (VARCHAR(50)). Values are lower
 * case and casing is part of the value.
 */
enum TaskPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /**
     * All task priority values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

Create `app/Enums/ArStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The accounts-receivable state of a scheduled payment tranche.
 *
 * The value is what lands in `transfers_tracking.ar_status` (VARCHAR(50)). A
 * tranche is pending until the money lands; `failed` records a transfer that
 * was attempted and did not arrive, which is not the same as one still due.
 */
enum ArStatus: string
{
    case Pending = 'pending';
    case Received = 'received';
    case Failed = 'failed';

    /**
     * All AR status values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

- [ ] **Step 4: Lower-case the tasks column default**

In `database/migrations/2026_09_07_124757_create_tasks_table.php`, change line 21 from:

```php
            $table->string('status', 50)->default('TODO');
```

to:

```php
            $table->string('status', 50)->default('todo');
```

Leave line 22 (`priority` defaulting to `'medium'`) unchanged — it is already correct.

- [ ] **Step 5: Cast the three models**

Every `casts()` method that gains an enum also widens its `@return` from
`array<string, string>` to `array<string, string|class-string>`. Apply that to
all three models in this task, and to both models in Task 3.

In `app/Models/Milestone.php`, add `use App\Enums\MilestoneStatus;` to the imports, change the docblock line `@property string $status` to `@property MilestoneStatus $status`, and change the `casts()` docblock and body:

```php
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'planned_date' => 'date',
            'actual_date' => 'date',
            'status' => MilestoneStatus::class,
            'sequence' => 'integer',
            'weight_percentage' => 'decimal:2',
        ];
    }
```

In `app/Models/Task.php`, add `use App\Enums\TaskPriority;` and `use App\Enums\TaskStatus;`, change `@property string $status` to `@property TaskStatus $status` and `@property string $priority` to `@property TaskPriority $priority`, and set:

```php
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'start_date' => 'date',
            'due_date' => 'date',
            'completed_date' => 'date',
            'progress_percentage' => 'integer',
        ];
    }
```

In `app/Models/TransfersTracking.php`, add `use App\Enums\ArStatus;`, change `@property string $ar_status` to `@property ArStatus $ar_status`, and add to `casts()` after `'receive_date' => 'date',`:

```php
            'ar_status' => ArStatus::class,
```

- [ ] **Step 6: Run the test to verify it passes**

```bash
./php.cmd artisan test --compact tests/Feature/EnumCastTest.php
```

Expected: PASS, 16 tests — four dataset rows through each of the four tests. All four rows have a column default at this point, so `defaulted enum columns` is the same four.

- [ ] **Step 7: Fix the two assertions the casts break**

`tests/Feature/Models/FactoryStateTest.php` reads `ar_status` through a model, so it now sees an enum. Add `use App\Enums\ArStatus;` to the imports and change line 24 from:

```php
        ->and($tranche->ar_status)->toBe('received');
```

to:

```php
        ->and($tranche->ar_status)->toBe(ArStatus::Received);
```

`tests/Feature/TaskTest.php` reads through the query builder and keeps asserting a raw string, but the string itself changed. Change the test name on line 7 from `'a new task starts as TODO at medium priority with no progress'` to `'a new task starts as todo at medium priority with no progress'`, and line 12 from:

```php
    expect($task->status)->toBe('TODO')
```

to:

```php
    expect($task->status)->toBe('todo')
```

Do **not** convert the other assertions in `tests/Feature/TaskTest.php`, `tests/Feature/MilestoneTest.php` or `tests/Feature/TransfersTrackingTest.php` to enums — those files read with `DB::table()` on purpose.

- [ ] **Step 8: Update the TaskFactory docblock**

In `database/factories/TaskFactory.php`, the class docblock says the factory leaves the defaults so a new task starts as "an untouched medium-priority TODO". Change `TODO` to `todo` so it matches the renamed default. No code in the factory changes — `completed()` already sets `'done'`, a real member.

- [ ] **Step 9: Run every affected test**

```bash
./php.cmd artisan test --compact tests/Feature/EnumCastTest.php tests/Feature/TaskTest.php tests/Feature/MilestoneTest.php tests/Feature/TransfersTrackingTest.php tests/Feature/Models/FactoryStateTest.php tests/Feature/Models/TaskTest.php tests/Feature/Models/MilestoneTest.php tests/Feature/Models/TransfersTrackingTest.php
```

Expected: PASS, no failures.

- [ ] **Step 10: Format**

```bash
./php.cmd vendor/bin/pint --dirty --format agent
```

- [ ] **Step 11: Commit**

```bash
git add app/Enums database/migrations app/Models database/factories/TaskFactory.php tests/Feature/EnumCastTest.php tests/Feature/TaskTest.php tests/Feature/Models/FactoryStateTest.php
git commit -m "ADD: MilestoneStatus, TaskStatus, TaskPriority and ArStatus enums cast onto their models"
```

---

### Task 3: Cast the proposal columns, drop the phase column

Casts both status columns, removes `proposals.phase`, adds the derived accessor, and migrates the three stale values (`signed`, `closed`, `awarded`) that are not members of `ProposalStatus`. The stale values must be fixed in the same task: `ProposalFactory::running()` writes `'signed'`, which throws `ValueError` the moment the cast exists.

**Files:**
- Modify: `database/migrations/2026_09_04_154815_create_proposals_table.php:22` — remove the `phase` column
- Modify: `app/Models/Proposal.php` — cast, drop `phase` from `#[Fillable]`, add `$attributes` default and the `phase` accessor
- Modify: `app/Models/ProposalStatusNote.php` — `status` cast and `@property`
- Modify: `database/factories/ProposalFactory.php` — `running()` and the class docblock
- Modify: `database/factories/ProposalStatusNoteFactory.php` — the status value list
- Modify: `database/seeders/FinanceSeeder.php:45` — `'signed'` becomes `'approved'`
- Modify: `tests/Pest.php:98` — `insertProposal()` docblock
- Modify: `tests/Feature/ProposalTest.php` — `'awarded'`, the `phase` assertions, plus a new raw-insert test
- Modify: `tests/Feature/EnumCastTest.php` — two more rows in `enumColumns()`
- Modify: `tests/Feature/ProposalPhaseTest.php` — model-level phase tests

**Interfaces:**
- Consumes: `App\Enums\ProposalStatus` and `App\Enums\ProposalPhase` from Task 1; `enumColumns()` and the `'enum columns'` / `'defaulted enum columns'` datasets from Task 2.
- Produces: `Proposal::$phase` as a read-only `ProposalPhase` with no column behind it, documented `@property-read ProposalPhase $phase`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/ProposalPhaseTest.php` — and add `use App\Models\Proposal;` and `use Illuminate\Support\Facades\Schema;` to the imports at the top of that file:

```php
test('the proposals table has no phase column', function () {
    expect(Schema::hasColumn('proposals', 'phase'))->toBeFalse();
});

test('a proposal reads its phase from its status', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Active]);

    expect($proposal->fresh()->phase)->toBe(ProposalPhase::Monitoring);
});

test('a new proposal is in the planning phase', function () {
    expect(Proposal::factory()->create()->fresh()->phase)->toBe(ProposalPhase::Planning);
});

/**
 * Reading phase must not require a round trip to the database. Account hit the
 * same trap: a column the factory omits is unhydrated until refresh, so the
 * model carries a matching $attributes default.
 */
test('a proposal created without a status still reports a phase', function () {
    expect(Proposal::factory()->create()->phase)->toBe(ProposalPhase::Planning);
});

test('proposals are queryable by the statuses of a phase', function () {
    Proposal::factory()->create(['status' => ProposalStatus::Active]);
    Proposal::factory()->create(['status' => ProposalStatus::Approved]);

    $implementation = Proposal::query()
        ->whereIn('status', ProposalPhase::Implementation->statuses())
        ->get();

    expect($implementation)->toHaveCount(1)
        ->and($implementation->first()->status)->toBe(ProposalStatus::Approved);
});
```

In `tests/Feature/EnumCastTest.php`, add `use App\Enums\ProposalStatus;`, `use App\Models\Proposal;` and `use App\Models\ProposalStatusNote;` to the imports, then add these two rows to the array returned by `enumColumns()`, after the `transfers_tracking.ar_status` row:

```php
        'proposals.status' => [[
            'model' => Proposal::class,
            'table' => 'proposals',
            'column' => 'status',
            'assign' => ProposalStatus::Active,
            'stored' => 'active',
            'default' => ProposalStatus::ConceptNote,
            'badCasing' => 'Active',
        ]],
        'proposal_status_notes.status' => [[
            'model' => ProposalStatusNote::class,
            'table' => 'proposal_status_notes',
            'column' => 'status',
            'assign' => ProposalStatus::Approved,
            'stored' => 'approved',
            'default' => null,
            'badCasing' => 'Approved',
        ]],
```

The `'default' => null` on the notes row is why `defaulted enum columns` filters: `proposal_status_notes.status` has no column default, so there is nothing for the default test to assert.

- [ ] **Step 2: Run the tests to verify they fail**

```bash
./php.cmd artisan test --compact tests/Feature/ProposalPhaseTest.php tests/Feature/EnumCastTest.php
```

Expected: FAIL — the `phase` column still exists, `$proposal->status` is a string not a `ProposalStatus`, and `$proposal->phase` is the old column value `'planning'` rather than a `ProposalPhase`.

- [ ] **Step 3: Drop the phase column**

In `database/migrations/2026_09_04_154815_create_proposals_table.php`, delete line 22 entirely:

```php
            $table->string('phase', 50)->default('planning');
```

`status` on line 21 stays exactly as it is.

- [ ] **Step 4: Update the Proposal model**

In `app/Models/Proposal.php`:

Add to the imports:

```php
use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
```

In the class docblock, change `@property string $status` to `@property ProposalStatus $status` and replace `@property string $phase` with `@property-read ProposalPhase $phase`.

Remove `'phase',` from the `#[Fillable([...])]` list. `'status',` stays.

Add the default attribute and the accessor. Put `$attributes` immediately after the `use HasFactory;` block, and the accessor immediately before `casts()`:

```php
    /**
     * Mirror the column default so an unrefreshed model can still report a
     * phase.
     *
     * The factory omits `status` on purpose, so the attribute is unhydrated
     * until the model is refreshed, and `phase` would fail on a model straight
     * out of create(). `Account::$is_active` carries the same mirror for the
     * same reason. The column default is still proven independently by a raw
     * insert in tests/Feature/ProposalTest.php.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => 'concept note',
    ];
```

```php
    /**
     * The phase of work this proposal is in.
     *
     * Derived from `status`, never stored. There is no `phase` column: it held
     * a value that could contradict the status sitting next to it.
     *
     * @return Attribute<ProposalPhase, never>
     */
    protected function phase(): Attribute
    {
        return Attribute::get(fn (): ProposalPhase => $this->status->phase());
    }
```

Add the cast to `casts()`:

```php
    protected function casts(): array
    {
        return [
            'status' => ProposalStatus::class,
            'planned_date_begin' => 'date',
            'planned_date_end' => 'date',
            'actual_date_begin' => 'date',
            'actual_date_end' => 'date',
        ];
    }
```

- [ ] **Step 5: Cast ProposalStatusNote**

In `app/Models/ProposalStatusNote.php`, add `use App\Enums\ProposalStatus;`, change `@property string $status` to `@property ProposalStatus $status`, and add the cast:

```php
    protected function casts(): array
    {
        return [
            'status' => ProposalStatus::class,
            'date' => 'date',
        ];
    }
```

- [ ] **Step 6: Migrate the stale vocabulary**

`database/factories/ProposalFactory.php` — add `use App\Enums\ProposalStatus;` to the imports, change the class docblock for `definition()` from "`status`, `phase` and the actual dates are left to the column defaults" to "`status` and the actual dates are left to the column defaults", and rewrite `running()`:

```php
    /**
     * Indicate that the proposal has been approved and is under way.
     *
     * The derived column is a closure rather than a value read from
     * $attributes directly, so it is resolved after the array passed to
     * create() has been merged and stays consistent with it.
     */
    public function running(): static
    {
        return $this->state([
            'status' => ProposalStatus::Approved,
            'actual_date_begin' => fn (array $attributes): string => $attributes['planned_date_begin'],
        ]);
    }
```

The `'phase' => 'implementation'` line is gone — phase now follows from `Approved`, which is an implementation-phase status.

`database/factories/ProposalStatusNoteFactory.php` — add `use App\Enums\ProposalStatus;` and replace the status line in `definition()`:

```php
            'status' => fake()->randomElement(ProposalStatus::cases()),
```

Drawing from `cases()` rather than a hand-written list means the factory can never fall out of step with the enum again.

`database/seeders/FinanceSeeder.php` — add `use App\Enums\ProposalStatus;` and replace the loop on line 45:

```php
            foreach ([ProposalStatus::ConceptNote, ProposalStatus::Submitted, ProposalStatus::Approved] as $status) {
                ProposalStatusNote::factory()->create([
                    'proposal_id' => $proposal->id,
                    'status' => $status,
                    'context' => 'Moved to '.$status->value.'.',
                ]);
            }
```

Note `$status->value` in the context string — `'Moved to '.$status` would be a TypeError on an enum instance.

`tests/Pest.php` — in the `insertProposal()` docblock, change "`status`, `phase` and the actual dates are left out so the column defaults decide them." to "`status` and the actual dates are left out so the column defaults decide them."

- [ ] **Step 7: Update tests/Feature/ProposalTest.php**

This file reads with `DB::table()`, so it keeps asserting raw strings. Three changes.

Change the first test's name from `'a new proposal starts as a concept note in planning'` to `'a new proposal starts as a concept note'` and drop the phase line, leaving:

```php
    expect($proposal->status)->toBe('concept note')
        ->and($proposal->actual_date_begin)->toBeNull()
        ->and($proposal->actual_date_end)->toBeNull()
        ->and($proposal->notes)->toBeNull();
```

In the second test, remove `'phase' => 'implementation',` from the raw insert, change `'status' => 'awarded',` to `'status' => 'approved',`, and in the assertions drop the `phase` line and change `->and($proposal->status)->toBe('awarded')` to `->and($proposal->status)->toBe('approved')`.

Add a raw-insert test proving the column default survives the model's new `$attributes` mirror. Put it directly after the first test:

```php
/**
 * Proposal carries a $attributes mirror of this default so an unrefreshed
 * model can report a phase, which means the factory no longer proves the
 * column default. This raw insert does.
 */
test('the status column defaults to concept note without the model supplying it', function () {
    $id = DB::table('proposals')->insertGetId([
        'name' => 'Emergency WASH Response',
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('proposals')->find($id)->status)->toBe('concept note');
});
```

- [ ] **Step 8: Run the tests to verify they pass**

```bash
./php.cmd artisan test --compact tests/Feature/ProposalPhaseTest.php tests/Feature/EnumCastTest.php tests/Feature/ProposalTest.php tests/Feature/ProposalStatusNoteTest.php tests/Feature/Models/ProposalTest.php tests/Feature/Models/ProposalStatusNoteTest.php
```

Expected: PASS, no failures.

- [ ] **Step 9: Verify the seeders still run against a real database**

```bash
./php.cmd artisan migrate:fresh --seed
```

Expected: every migration runs, `DatabaseSeeder` completes with no `ValueError`.

- [ ] **Step 10: Format**

```bash
./php.cmd vendor/bin/pint --dirty --format agent
```

- [ ] **Step 11: Commit**

```bash
git add app/Models database/migrations database/factories database/seeders tests
git commit -m "ADD: cast proposal statuses to ProposalStatus and derive phase from status"
```

---

### Task 4: Record the rule and verify the whole suite

**Files:**
- Modify: `.ai/rules/enums.md`

**Interfaces:**
- Consumes: everything from Tasks 1-3.
- Produces: nothing consumed by later work.

- [ ] **Step 1: Run the complete suite**

```bash
./php.cmd artisan test --compact
```

Expected: PASS, no failures and no skips other than `tests/Feature/Auth/RegistrationTest.php`, which skips itself by design because registration is disabled.

If anything fails, fix it before continuing. Likely suspects are files this plan did not touch that read a status through a model.

- [ ] **Step 2: Append the rule**

Add to the end of `.ai/rules/enums.md`, under the existing `# Enums` heading:

```markdown
## Six workflow columns are backed enums; values are lower case

`App\Enums` holds `UserRole` plus `ProposalStatus`, `ProposalPhase`,
`MilestoneStatus`, `TaskStatus`, `TaskPriority` and `ArStatus`. Every one is a
string-backed enum with a `values()` helper. All but `ProposalPhase` are cast
in the owning model's `casts()`; `ProposalPhase` has no column of its own (see
below).

Values are lower case — 'todo', 'in progress', 'concept note'. `tasks.status`
defaulted to 'TODO' before this and was the one column in the schema cased
against its neighbours; the create migration now says 'todo'. Casing is part of
the value here exactly as it is for MEAL, so a value differing only by
capitalisation throws ValueError on read. Display capitalisation is the UI's
job.

`ProposalStatus` casts BOTH `proposals.status` and
`proposal_status_notes.status`. The notes table is that column's audit trail; a
second enum would let the recorded history drift from the live vocabulary.

## proposals.phase is derived from status, not stored

There is no `phase` column. Every status belongs to exactly one phase, so two
columns could contradict each other. `ProposalStatus::phase()` holds the
mapping in a `match` with no default arm — a status added without a phase
raises UnhandledMatchError instead of silently taking a wrong one — and
`ProposalPhase::statuses()` derives the reverse lookup from that same match.

Query a whole phase with
`whereIn('status', ProposalPhase::Implementation->statuses())`. Read one
proposal's phase with `$proposal->phase`, a read-only accessor over
`status->phase()`.

TRAP: `Proposal` carries `protected $attributes = ['status' => 'concept note']`
mirroring the column default. Without it `Proposal::factory()->create()->phase`
throws, because the factory omits the column and the attribute stays unhydrated
until refresh — the same trap `Account::$is_active` documents. The column
default is therefore proven by a raw insert in tests/Feature/ProposalTest.php,
not by the factory.

Three values were in the repository and are NOT members of `ProposalStatus`:
'signed' and 'closed' (ProposalFactory, ProposalStatusNoteFactory,
FinanceSeeder) and 'awarded' (tests/Feature/ProposalTest.php). They are now
'approved' and 'completed'. `ProposalStatusNoteFactory` draws from
`ProposalStatus::cases()` rather than a hand-written list so it cannot drift
again.

## These enum-shaped columns are deliberately still free text

`results.type`, `results.checkout_period`, `indicators.measure`,
`plan_indicators.period`, `report_files.type`, `task_attachments.type` and the
three `*_currency` columns.

The first six are M&E classification vocabularies whose complete member sets
are recorded nowhere, and that subsystem has migrations but no UI and no usage.
An invented enum is worse than free text: a value outside the enum throws
ValueError on READ, so a wrong guess turns a cosmetic problem into rows the
application cannot load. Currency is an open ISO-4217 vocabulary — if it is
ever constrained the members should be the currencies donors actually transfer
in, which is a finance question, not a refactor.

Do not convert any of them without asking.
```

- [ ] **Step 3: Verify tests still pass and formatting is clean**

```bash
./php.cmd artisan test --compact && ./php.cmd vendor/bin/pint --dirty --format agent
```

Expected: full suite PASS, Pint reports no changes.

- [ ] **Step 4: Commit**

```bash
git add .ai/rules/enums.md
git commit -m "DOC: record the workflow enum conventions and the derived phase decision"
```

---

## Verification checklist

- [ ] `./php.cmd artisan test --compact` passes in full, including `tests/Feature/DatabaseEncodingTest.php` and `tests/Feature/DatabaseSeederTest.php`.
- [ ] `./php.cmd artisan migrate:fresh --seed` completes with no `ValueError`.
- [ ] `./php.cmd vendor/bin/pint --dirty --format agent` reports no changes.
- [ ] `grep -rn "'signed'\|'closed'\|'awarded'\|'TODO'" app database tests` returns nothing in a status context.
- [ ] `grep -rn "phase" database/migrations` returns nothing.
- [ ] `app/Enums/UserRole.php` and `tests/Feature/UserRoleTest.php` are untouched by the diff.
