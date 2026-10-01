# Internal and External Project Numbers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:test-driven-development, executed inline task by task. Steps use checkbox (`- [ ]`) syntax for tracking. **Never commit or push**: every "commit" step from the skill template is replaced by "leave it in the working tree".

**Goal:** Give every proposal a required, unique internal project number and rename the agreement's donor reference to the external project number, end to end (schema, rules, forms, pages, lang, seeders, tests).

**Architecture:** Two migrations: rename `donor_proposal.project_num` → `external_project_number`; add `proposals.internal_project_number` (backfilled with `TEMP-NNNN`, then NOT NULL + UNIQUE). `Proposal` owns the placeholder logic (`PLACEHOLDER_NUMBER_PREFIX`, `isPlaceholderNumber()`). The proposal index form validates the number (required, max 50, unique, `TEMP-` reserved) and turns a racing unique violation into the same field error. A new `x-ui.project-number` Blade component renders a number (LTR-isolated, placeholder chip) on every page that shows one.

**Tech Stack:** Laravel 13, Livewire 4 single-file pages, Pest, MariaDB (utf8mb4_unicode_ci), Blade, Tailwind 4 tokens.

**Spec:** `docs/superpowers/specs/2026-09-30-internal-external-project-numbers-design.md`

## Global Constraints

- PHP is 8.5 at `C:\php85`: always `.\php.cmd` / `.\composer.cmd`, never bare `php`.
- Never `git commit` / `git push`. Leave every change in the working tree.
- Never run the full suite. Run only the test files listed per task, by path.
- Create migrations, tests and components with `.\php.cmd artisan make:... --no-interaction`.
- Every visible string in both `lang/en` and `lang/ar` (`LocalizationTest` enforces key parity).
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- Do not touch unrelated files (navigation lang files, sidebar, guideline-button, NavigationTest).
- Only migrate `hc_tracking` / `hc_tracking_test`. Tests use `hc_tracking_test` (phpunit.xml). I do NOT run `migrate` on `hc_tracking`; you do (see "Commands for you").
- Internal number: required, trimmed, max 50, unique case-insensitive (collation), `TEMP-` reserved (case-insensitive) unless it equals the proposal's current stored number.
- External number: optional, non-unique, max 50, empty string stored as null.
- Labels: "Internal project number" / "رقم المشروع الداخلي", "External project number" / "رقم المشروع الخارجي", "No external project number" / "لا يوجد رقم مشروع خارجي", "Placeholder — replace" / "رقم مؤقت — يرجى الاستبدال".
- Numbering ranges: real proposals 001–034, factory 100–899, demo 901+. Tests that set an explicit number use `HC-2026-0NN` (outside the factory range) or `TEMP-NNNN`.
- Money stays gated: external numbers only where agreements already load behind `funding-agreements.view`.
- Blade: never the `@php(...)` one-liner; dark-mode classes use Graphite tokens only; logical (start/end) classes only.

## Your answers to my questions (2026-10-01), folded into the plan

1. **CoreDataSeeder** gives its 5 proposals the same numbers as RealProposalsSeeder: Family to Family - CP = `HC-2026-001`, Micro Projects - CP = `HC-2026-002`, Leadership Aleppo - MCC = `HC-2026-005`, Study Zone Syria - CDCS = `HC-2026-011`, Children University - Missio Munich = `HC-2026-012`.
2. **Transfers expanded reference line** with no external number shows the muted "No external project number".
3. **Extra tests**: one display test per page the spec left untested. Note: the donor detail page is tested in `tests/Feature/DonorDirectoryTest.php` (that is where every `pages::donors.show` test lives; `tests/Feature/DonorTest.php` holds only raw-schema tests).
4. **Form layout**: the internal number is its own full-width input row directly under the name (and the starting status, on create). Nothing else moves.

## Things I am adding that the spec does not spell out (please confirm when you approve)

- **Reserved-prefix message text** (key `proposals.internal_project_number_prefix_reserved`, beside the page's other form messages in `proposals.php`):
  - en: `Numbers starting with TEMP- are reserved for placeholders. Enter the real internal project number.`
  - ar: `الأرقام التي تبدأ بـ TEMP- محجوزة للأرقام المؤقتة. أدخل رقم المشروع الداخلي الفعلي.`
- **`lang/en/validation.php` attributes**: today English has no form attributes (Laravel derives "internal project number" from the property name). I add `internalProjectNumber` and `externalProjectNumber` to **both** en and ar, because the race-condition fallback builds the message from `validation.attributes.internalProjectNumber`, and your rule is "every visible string in both languages".
- **Race-condition catch is narrowed**: only a `UniqueConstraintViolationException` whose message names `proposals_internal_project_number_unique` becomes the duplicate error; any other unique violation (e.g. slug) is re-thrown, so it is never misreported as "number taken".
- **Component empty states**: an empty external number renders "No external project number" (no label); an internal number is never empty (NOT NULL), so the component renders nothing for it.
- **Funding agreements list**: the "card" place for the numbers is the existing "Donor reference" slot in each agreement's expanded detail; it becomes two side-by-side entries (internal, external).
- **`RealProposalsSeeder` is not exercised by any test** (`RealDataSeederTest` replaces it with a stand-in). Its change is verified by reading the code only; it runs for real only on `migrate:fresh` + `db:seed --class=RealDataSeeder`.
- **No migration backfill test** (the spec lists none). I verify the backfill read-only after you run `migrate` (step in Task 9).

## File map

| File | Responsibility | Task |
| --- | --- | --- |
| `database/migrations/<ts>_rename_project_num_on_donor_proposal_table.php` (new) | Rename external column | 1 |
| `database/migrations/<ts>_add_internal_project_number_to_proposals_table.php` (new) | Add, backfill, NOT NULL, UNIQUE | 1 |
| `app/Models/Proposal.php` | Fillable, docblock, prefix constant, placeholder helpers, activity event, pivot | 1 |
| `app/Models/DonorProposal.php`, `app/Models/Donor.php` | Rename in fillable/docblock/activity/pivot | 1 |
| `database/factories/ProposalFactory.php` | `HC-2026-100…899` | 1 |
| `database/seeders/{RealProposalsSeeder,ProgrammeSeeder,CoreDataSeeder}.php` | Explicit numbers, renamed agreement key | 1 |
| `tests/Pest.php` | `insertDonorProposal($externalProjectNumber)` | 1 |
| `lang/{en,ar}/activity.php` | Field labels | 1 |
| `app/Actions/FundingAgreements/SaveFundingAgreement.php`, `app/Livewire/Concerns/ManagesAgreementForm.php`, `app/Livewire/Concerns/ManagesTransferForm.php` | Rename external field | 2 |
| `resources/views/components/funding/{agreement,transfer}-modal.blade.php` | Rename field + label | 2 (+4, 7) |
| All pages selecting `project_num` | Rename select/search/text | 2 |
| `lang/{en,ar}/{common,funding_agreements,proposals,donors,transfers,validation}.php` | Renamed/new keys | 2, 3 |
| `resources/views/components/ui/project-number.blade.php` (new) | Shared number display | 3 |
| `resources/views/pages/proposals/⚡index.blade.php` | Form field, validation, list display, search | 3 |
| `resources/views/pages/proposals/⚡show.blade.php` | Header number, locked proposal label | 4 |
| `resources/views/pages/funding-agreements/⚡{index,show}.blade.php` | Both numbers, search | 5 |
| `resources/views/pages/donors/⚡show.blade.php` | Internal beside external | 6 |
| `resources/views/pages/transfers/⚡index.blade.php` | Internal beside external, search | 7 |
| `resources/views/pages/executive-summary/⚡index.blade.php`, `resources/views/pages/projects/⚡show.blade.php` | Context line, linked proposals, picker | 8 |

---

### Task 1: Schema, models, factory, helpers, seeders, activity labels

**Files:**
- Create: `database/migrations/<ts>_rename_project_num_on_donor_proposal_table.php`
- Create: `database/migrations/<ts>_add_internal_project_number_to_proposals_table.php`
- Modify: `app/Models/Proposal.php`, `app/Models/DonorProposal.php`, `app/Models/Donor.php`
- Modify: `database/factories/ProposalFactory.php`
- Modify: `database/seeders/RealProposalsSeeder.php`, `database/seeders/ProgrammeSeeder.php`, `database/seeders/CoreDataSeeder.php`
- Modify: `tests/Pest.php`, `lang/en/activity.php`, `lang/ar/activity.php`
- Test: `tests/Feature/ProposalTest.php`, `tests/Feature/DonorProposalTest.php`, `tests/Feature/Models/DonorTest.php`

**Interfaces:**
- Produces: columns `proposals.internal_project_number` (VARCHAR 50, NOT NULL, UNIQUE `proposals_internal_project_number_unique`) and `donor_proposal.external_project_number`; `Proposal::PLACEHOLDER_NUMBER_PREFIX = 'TEMP-'`; `Proposal::isPlaceholderNumber(?string $number): bool`; `$proposal->hasPlaceholderInternalNumber(): bool`; `insertDonorProposal(int $donorId, int $proposalId, string $budget = '500000.00', ?Currency $currency = null, ?string $externalProjectNumber = null): int`.

- [x] **Step 1: Write the failing tests**

`tests/Feature/ProposalTest.php` — add `'internal_project_number' => 'HC-2026-001',` as the second key (after `'name'`) of the three raw inserts (the `insertGetId` at line 23, the `insertGetId` at line 35, the `insert` at line 56). Add `use Illuminate\Database\UniqueConstraintViolationException;` and append:

```php
test('a proposal cannot be stored without an internal project number', function () {
    DB::table('proposals')->insert([
        'name' => 'Emergency WASH Response',
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

/**
 * The column is utf8mb4_unicode_ci, so the unique index ignores case: a
 * number differing only in case is the same number.
 */
test('two proposals cannot share an internal project number, whatever its case', function (string $second) {
    $proposal = fn (string $number): array => [
        'name' => 'Emergency WASH Response',
        'internal_project_number' => $number,
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('proposals')->insert($proposal('HC-2026-001'));
    DB::table('proposals')->insert($proposal($second));
})->with(['HC-2026-001', 'hc-2026-001'])->throws(UniqueConstraintViolationException::class);
```

`tests/Feature/DonorProposalTest.php` — rename the three external-number tests' assertions (lines 77, 83, 92):

```php
test('an agreement may be recorded before the donor issues a project number', function () {
    $id = insertDonorProposal(insertDonor(), insertProposal());

    expect(DB::table('donor_proposal')->find($id)->external_project_number)->toBeNull();
});

test('an agreement stores the donor project reference verbatim', function () {
    $id = insertDonorProposal(insertDonor(), insertProposal(), '500000.00', Currency::Eur, 'ECHO/SYR/BUD/2026/91008');

    expect(DB::table('donor_proposal')->find($id)->external_project_number)->toBe('ECHO/SYR/BUD/2026/91008');
});

test('two agreements may carry the same project number', function () {
    $proposalId = insertProposal();

    insertDonorProposal(insertDonor(), $proposalId, '500000.00', Currency::Usd, 'SYR-2026-014');
    insertDonorProposal(insertDonor('WFP'), $proposalId, '120000.00', Currency::Usd, 'SYR-2026-014');

    expect(DB::table('donor_proposal')->where('external_project_number', 'SYR-2026-014')->count())->toBe(2);
});
```

`tests/Feature/Models/DonorTest.php` — in "a donor reaches its proposals through the donor_proposal table": `'project_num' => 'PRJ-0001'` → `'external_project_number' => 'PRJ-0001'`, and `->and($linked->pivot->project_num)->toBe('PRJ-0001')` → `->and($linked->pivot->external_project_number)->toBe('PRJ-0001')`.

- [x] **Step 2: Run them to verify they fail**

Run: `.\php.cmd artisan test --compact tests/Feature/ProposalTest.php tests/Feature/DonorProposalTest.php tests/Feature/Models/DonorTest.php`
Expected: FAIL — unknown column `internal_project_number` / `external_project_number`, and "a proposal cannot be stored without an internal project number" fails because the insert succeeds (no such column yet, so nothing is missing).

- [x] **Step 3: Create the migrations**

Run:
```
.\php.cmd artisan make:migration rename_project_num_on_donor_proposal_table --table=donor_proposal --no-interaction
.\php.cmd artisan make:migration add_internal_project_number_to_proposals_table --table=proposals --no-interaction
```

Replace the rename migration's contents:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The number the donor files an agreement under is the external project
     * number, beside the internal number on proposals. Type, length (50),
     * nullability and the absence of an index stay exactly as they are.
     */
    public function up(): void
    {
        Schema::table('donor_proposal', function (Blueprint $table) {
            $table->renameColumn('project_num', 'external_project_number');
        });
    }

    public function down(): void
    {
        Schema::table('donor_proposal', function (Blueprint $table) {
            $table->renameColumn('external_project_number', 'project_num');
        });
    }
};
```

Replace the add migration's contents:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every proposal carries the internal project number the team tracks it
     * by: required and unique. The collation (utf8mb4_unicode_ci) makes the
     * unique index case-insensitive, so "HC-1" and "hc-1" collide on purpose.
     *
     * Existing rows get a unique "TEMP-0001" style placeholder for the team to
     * replace. The id is padded in PHP: str_pad never shortens a longer id,
     * where SQL LPAD(id, 4, '0') would cut 12345 to 1234 and could collide.
     * The rows are written through the query builder, so the backfill records
     * no activity and notifies nobody.
     */
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->string('internal_project_number', 50)->nullable()->after('name');
        });

        DB::table('proposals')->select('id')->lazyById()->each(function (object $proposal): void {
            DB::table('proposals')->where('id', $proposal->id)->update([
                'internal_project_number' => 'TEMP-'.str_pad((string) $proposal->id, 4, '0', STR_PAD_LEFT),
            ]);
        });

        Schema::table('proposals', function (Blueprint $table) {
            $table->string('internal_project_number', 50)->change();
            $table->unique('internal_project_number');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropUnique(['internal_project_number']);
            $table->dropColumn('internal_project_number');
        });
    }
};
```

(`->change()` without `->nullable()` makes the column NOT NULL; MariaDB keeps its position.)

- [x] **Step 4: Update the models**

`app/Models/Proposal.php`:
- docblock: after `@property string $slug` add `@property string $internal_project_number`.
- `#[Fillable([...])]`: add `'internal_project_number',` after `'name',`.
- add directly after the `use HasFactory, HasSlug, RecordsActivity;` line:

```php
    /**
     * Marks the internal numbers the migration wrote for proposals that had
     * none. Reserved: nobody may type a new number starting with it.
     */
    public const PLACEHOLDER_NUMBER_PREFIX = 'TEMP-';
```

- add after `slugSource()`:

```php
    /**
     * Whether an internal project number is a migration placeholder still
     * waiting for the real number, compared case-insensitively.
     */
    public static function isPlaceholderNumber(?string $number): bool
    {
        return $number !== null && str_starts_with(mb_strtoupper($number), self::PLACEHOLDER_NUMBER_PREFIX);
    }

    /**
     * Whether this proposal still holds its migration placeholder number.
     */
    public function hasPlaceholderInternalNumber(): bool
    {
        return static::isPlaceholderNumber($this->internal_project_number);
    }
```

- `donors()`: `->withPivot(['budget', 'currency', 'external_project_number'])`.
- `activityEvents()`:

```php
        return ['status' => 'status_changed', 'name' => 'updated', 'internal_project_number' => 'updated', 'planned_date_begin' => 'updated', 'planned_date_end' => 'updated'];
```

`app/Models/DonorProposal.php`:
- class docblock line "budget, a currency and the project number the donor files it under." → "budget, a currency and the external project number the donor files it under."
- `@property string|null $project_num` → `@property string|null $external_project_number`
- `#[Fillable(['donor_id', 'budget', 'currency', 'external_project_number', 'proposal_id'])]`
- `activityEvents()`: `return ['budget' => 'updated', 'currency' => 'updated', 'external_project_number' => 'updated'];`

`app/Models/Donor.php`: `proposals()` → `->withPivot(['budget', 'currency', 'external_project_number'])`.

- [x] **Step 5: Factory, Pest helper, seeders, activity labels**

`database/factories/ProposalFactory.php` — docblock of `definition()` gains a paragraph, and the array gains the number:

```php
    /**
     * Define the model's default state.
     *
     * `status` and the actual dates are left to the column defaults so a fresh
     * proposal starts as an unstarted concept note.
     *
     * `internal_project_number` is NOT NULL with no default, so it is always
     * set. Factory numbers use 100–899: real proposals use 001–034 and demo
     * proposals 901+, so the three never collide.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $begin = fake()->dateTimeBetween('-2 years', '+6 months');

        return [
            'name' => fake()->randomElement(['Emergency WASH Response', 'Winterization Support', 'Rapid Shelter Repair']),
            'internal_project_number' => 'HC-2026-'.fake()->unique()->numberBetween(100, 899),
            'planned_date_begin' => $begin->format('Y-m-d'),
            'planned_date_end' => fn (array $attributes): string => CarbonImmutable::parse($attributes['planned_date_begin'])
                ->addYear()->toDateString(),
        ];
    }
```

`tests/Pest.php` — `insertDonorProposal`:

```php
function insertDonorProposal(int $donorId, int $proposalId, string $budget = '500000.00', ?Currency $currency = null, ?string $externalProjectNumber = null): int
{
    $agreement = [
        'donor_id' => $donorId,
        'proposal_id' => $proposalId,
        'budget' => $budget,
    ];

    if ($currency !== null) {
        $agreement['currency'] = $currency;
    }

    if ($externalProjectNumber !== null) {
        $agreement['external_project_number'] = $externalProjectNumber;
    }

    return DonorProposal::factory()->create($agreement)->id;
}
```

`database/seeders/RealProposalsSeeder.php`:
- `run()` firstOrCreate create values: add `'internal_project_number' => $proposal['internal_project_number'],` as the first key.
- agreement firstOrCreate values: `'project_num' => $row['project_num'],` → `'external_project_number' => $row['external_project_number'],`
- every `donorProposals()` row: key `'project_num' =>` → `'external_project_number' =>` (values unchanged).
- every `proposals()` row: add `'internal_project_number' => 'HC-2026-NNN',` right after `'name' => ...,`, in list order:

| # | Proposal | Number |
| --- | --- | --- |
| 1 | Family to Family - CP | HC-2026-001 |
| 2 | Micro Projects - CP | HC-2026-002 |
| 3 | Children University Aleppo - CP | HC-2026-003 |
| 4 | School to School - CP | HC-2026-004 |
| 5 | Leadership Aleppo - MCC | HC-2026-005 |
| 6 | Children University Aleppo - MCC | HC-2026-006 |
| 7 | ADISC - MCC | HC-2026-007 |
| 8 | DDISC - MCC | HC-2026-008 |
| 9 | Micro Projects - MCC | HC-2026-009 |
| 10 | CHC Team Burnout Prevention and Mental Wellbeing Retreat - MCC | HC-2026-010 |
| 11 | Study Zone Syria - CDCS | HC-2026-011 |
| 12 | Children University - Missio Munich | HC-2026-012 |
| 13 | Leadership Homs - Missio Achen | HC-2026-013 |
| 14 | Children University Homs - Missio Austria | HC-2026-014 |
| 15 | Hope Center Latakia - Missio Austria | HC-2026-015 |
| 16 | Leadership Homs - OO | HC-2026-016 |
| 17 | Hope Center Latakia - OO | HC-2026-017 |
| 18 | ADISC - OO | HC-2026-018 |
| 19 | Hope Center Damascus - OO | HC-2026-019 |
| 20 | Hope Center Homs - OO | HC-2026-020 |
| 21 | Study Zone Latakia - OO | HC-2026-021 |
| 22 | Al-Wadi Micro Projects Center - OO | HC-2026-022 |
| 23 | HDISC - IF | HC-2026-023 |
| 24 | DDISC - IF | HC-2026-024 |
| 25 | Science Zone Homs - IF | HC-2026-025 |
| 26 | Science Zone Aleppo - IF | HC-2026-026 |
| 27 | Houses Restoration - CGR | HC-2026-027 |
| 28 | Managers Meeting - CGR | HC-2026-028 |
| 29 | Educational equipment - Myriad | HC-2026-029 |
| 30 | Micro Projects Al-Wadi - ACN | HC-2026-030 |
| 31 | Micro Projects Latakia - ACN | HC-2026-031 |
| 32 | Micro Projects Damascus - ACN | HC-2026-032 |
| 33 | Micro Projects Homs - ACN | HC-2026-033 |
| 34 | Al-Wadi Training Center - GIZ | HC-2026-034 |

Example row after the change:

```php
            ['name' => 'Family to Family - CP', 'internal_project_number' => 'HC-2026-001', 'planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-12-31', 'status' => ProposalStatus::Active, 'notes' => 'Donor: CP, Budget: €854,470.00'],
```

`database/seeders/ProgrammeSeeder.php`:

```php
            $proposal = Proposal::factory()->running()->create([
                'name' => $name.' Emergency Response',
                'internal_project_number' => 'HC-2026-'.(901 + $index),
                'planned_date_begin' => $strategy->start_year.'-01-01',
                'planned_date_end' => $strategy->start_year.'-12-31',
            ]);

            $proposal->projects()->attach($projects->pluck('id'));

            DonorProposal::factory()->create([
                'donor_id' => $donors->random()->id,
                'proposal_id' => $proposal->id,
                'external_project_number' => 'PRJ-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
            ]);
```

`database/seeders/CoreDataSeeder.php`:
- comment becomes:

```php
        // Real proposals recorded from grant paperwork, not yet linked to a
        // program or project. The donor and budget only exist as free text in
        // `notes` here, because the source data gives no external_project_number
        // to key a donor_proposal row on — raise that with the user before
        // inventing one. Each internal number matches RealProposalsSeeder's for
        // the same proposal, so seeding both never assigns two numbers.
```
- each profile gains its number: Family to Family - CP `'internal_project_number' => 'HC-2026-001',`, Micro Projects - CP `HC-2026-002`, Leadership Aleppo - MCC `HC-2026-005`, Study Zone Syria - CDCS `HC-2026-011`, Children University - Missio Munich `HC-2026-012` (first key of each profile array).
- firstOrCreate values: add `'internal_project_number' => $profile['internal_project_number'],` as the first key.

`lang/en/activity.php` `fields`: remove `'project_num' => 'Project number',`; add (alphabetical position within `fields` if sorted, else where `project_num` was) `'external_project_number' => 'External project number',` and `'internal_project_number' => 'Internal project number',`.
`lang/ar/activity.php` `fields`: remove `'project_num' => 'رقم المشروع',`; add `'external_project_number' => 'رقم المشروع الخارجي',` and `'internal_project_number' => 'رقم المشروع الداخلي',` in the same positions.

- [x] **Step 6: Run the tests to verify they pass**

Run: `.\php.cmd artisan test --compact tests/Feature/ProposalTest.php tests/Feature/DonorProposalTest.php tests/Feature/Models/DonorTest.php tests/Feature/Models/ProposalTest.php tests/Feature/DatabaseEncodingTest.php tests/Feature/DatabaseSeederTest.php tests/Feature/Models/MorphMapTest.php`
Expected: PASS (RefreshDatabase rebuilds `hc_tracking_test` with both migrations).

- [x] **Step 7: Pint; leave changes uncommitted**

Run: `vendor/bin/pint --dirty --format agent`

---

### Task 2: Rename the external number across forms, pages and lang

After Task 1 every page that selects `project_num` breaks; this task renames all of them and relabels the text, with no new display yet.

**Files:**
- Modify: `app/Actions/FundingAgreements/SaveFundingAgreement.php`, `app/Livewire/Concerns/ManagesAgreementForm.php`, `app/Livewire/Concerns/ManagesTransferForm.php`
- Modify: `resources/views/components/funding/agreement-modal.blade.php`, `resources/views/components/funding/transfer-modal.blade.php`
- Modify: `resources/views/pages/{proposals/⚡index,proposals/⚡show,funding-agreements/⚡index,funding-agreements/⚡show,donors/⚡index,donors/⚡show,transfers/⚡index,executive-summary/⚡index}.blade.php`
- Modify: `lang/{en,ar}/{common,funding_agreements,proposals,donors,transfers,validation}.php`
- Test: `tests/Feature/FundingAgreementTest.php`

**Interfaces:**
- Consumes: column `external_project_number` (Task 1).
- Produces: Livewire property `ManagesAgreementForm::$externalProjectNumber` (string); lang keys `funding_agreements.external_project_number`, `common.no_external_project_number`, `common.internal_project_number`, `common.external_project_number`, `common.placeholder_number`, `validation.attributes.{internalProjectNumber,externalProjectNumber}`.

- [x] **Step 1: Write the failing test**

`tests/Feature/FundingAgreementTest.php`, "an agreement can be created with a budget and currency":

```php
        ->set('externalProjectNumber', 'SYR-2026-014')
        ->call('saveAgreement')
        ->assertHasNoErrors();

    $agreement = DonorProposal::query()->where('external_project_number', 'SYR-2026-014')->sole();
```

- [x] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/FundingAgreementTest.php --filter="an agreement can be created with a budget and currency"`
Expected: FAIL — property `externalProjectNumber` not found (and `project_num` select errors).

- [x] **Step 3: Rename in PHP**

`SaveFundingAgreement::handle()`:

```php
            'external_project_number' => $validated['externalProjectNumber'] === '' ? null : $validated['externalProjectNumber'],
```

`ManagesAgreementForm`: `public string $projectNum = '';` → `public string $externalProjectNumber = '';`; in `openAgreementEdit()` `$this->externalProjectNumber = $agreement->external_project_number ?? '';`; in `saveAgreement()` `$this->externalProjectNumber = trim($this->externalProjectNumber);` and rule key `'externalProjectNumber' => ['nullable', 'string', 'max:50'],`; in `resetAgreementForm()` `$this->externalProjectNumber = '';`.

`ManagesTransferForm::agreementOptions()`: `->select(['id', 'donor_id', 'proposal_id', 'external_project_number', 'currency', 'budget'])`.

- [x] **Step 4: Rename in components and pages (select, search, text)**

`components/funding/agreement-modal.blade.php`:

```blade
    <x-ui.form-section :title="__('funding_agreements.section_amount')" :fields="['budget', 'agreementCurrency', 'externalProjectNumber']">
```
```blade
            <x-ui.form-input wire:model="externalProjectNumber" :label="__('funding_agreements.external_project_number')" maxlength="50" />
```

`components/funding/transfer-modal.blade.php` option (Task 7 adds the internal number):

```blade
                    <option value="{{ $agreementOption->id }}">{{ $agreementOption->donor->name }} · {{ $agreementOption->proposal->name }}@if (filled($agreementOption->external_project_number)) · {{ $agreementOption->external_project_number }}@endif · {{ number_format((float) $agreementOption->budget, 0) }} {{ $agreementOption->currency->value }}</option>
```

Pages — every occurrence, nothing else:
- `proposals/⚡index.blade.php`: line 185 select `'project_num'` → `'external_project_number'`; line 1193 `$agreement->project_num` (×2) → `$agreement->external_project_number`.
- `proposals/⚡show.blade.php`: line 234 select; line 2009 `$agreement->project_num` (×2).
- `funding-agreements/⚡index.blade.php`: line 94 `->where('project_num', ...)` → `->where('external_project_number', ...)`; line 150 select; lines 1239–1240 become (Task 5 replaces them again):

```blade
                                                        <dt class="font-semibold {{ $mutedText }}">{{ __('funding_agreements.external_project_number') }}</dt>
                                                        <dd class="mt-1 font-bold tabular-nums {{ $inkText }}">{{ $agreement->external_project_number ?? __('common.no_external_project_number') }}</dd>
```
- `funding-agreements/⚡show.blade.php`: lines 88 and 635 selects; line 981 `{{ $agreement->external_project_number ?? __('common.no_external_project_number') }}`.
- `donors/⚡index.blade.php`: line 149 select.
- `donors/⚡show.blade.php`: line 63 select; line 633 `{{ $lane['agreement']->external_project_number ?? __('common.no_external_project_number') }}`; line 703 `$agreement->project_num` (×2) → `$agreement->external_project_number`.
- `transfers/⚡index.blade.php`: line 127 `find($agreementId, ['id', 'donor_id', 'proposal_id', 'external_project_number'])`; line 199 `->where('external_project_number', ...)`; line 258 `'donorProposal:id,donor_id,proposal_id,external_project_number',`; line 945 `$transfer->donorProposal->project_num` (×2) → `external_project_number`; line 1123 `['reference' => $transfer->donorProposal->external_project_number]`.
- `executive-summary/⚡index.blade.php`: line 766 `'donorProposal:id,donor_id,proposal_id,external_project_number'` (Task 8 adds the proposal number).

- [x] **Step 5: Lang**

`lang/en/common.php`: remove `'no_donor_reference' => 'No donor reference',`; add in alphabetical position `'external_project_number' => 'External project number',`, `'internal_project_number' => 'Internal project number',`, `'no_external_project_number' => 'No external project number',`, `'placeholder_number' => 'Placeholder — replace',`.
`lang/ar/common.php`: remove `'no_donor_reference'`; add `'external_project_number' => 'رقم المشروع الخارجي',`, `'internal_project_number' => 'رقم المشروع الداخلي',`, `'no_external_project_number' => 'لا يوجد رقم مشروع خارجي',`, `'placeholder_number' => 'رقم مؤقت — يرجى الاستبدال',`.
`lang/en/funding_agreements.php`: `'donor_reference' => 'Donor reference',` → `'external_project_number' => 'External project number',` (moved to its alphabetical place). `lang/ar/funding_agreements.php`: `'donor_reference' => 'مرجع المانح',` → `'external_project_number' => 'رقم المشروع الخارجي',`.
`lang/en/proposals.php`: `'agreement_number' => 'External no. :number',`. ar: `'agreement_number' => 'رقم خارجي :number',`.
`lang/en/donors.php`: `'agreement_ref' => 'External no. :number',`. ar: `'agreement_ref' => 'رقم خارجي :number',`.
`lang/en/transfers.php`: `'reference_num' => 'External no. :reference',`. ar: `'reference_num' => 'رقم خارجي :reference',`.
`lang/en/validation.php` `attributes`:

```php
    'attributes' => [
        'externalProjectNumber' => 'external project number',
        'files.*' => 'file',
        'internalProjectNumber' => 'internal project number',
    ],
```
`lang/ar/validation.php` `attributes`: `'projectNum' => 'مرجع المانح',` removed; add `'externalProjectNumber' => 'رقم المشروع الخارجي',` (after `'exchangeRate'`) and `'internalProjectNumber' => 'رقم المشروع الداخلي',` (after `'indicatorResultId'`).

- [x] **Step 6: Verify nothing still names the old column or keys**

Run (Grep tool): `project_num|projectNum|donor_reference|no_donor_reference` over `app`, `resources`, `database/seeders`, `database/factories`, `tests`, `lang`.
Expected: only `database/migrations/2026_09_04_155826_create_donor_proposal_table.php` and the new rename migration.

- [x] **Step 7: Run the tests**

Run: `.\php.cmd artisan test --compact tests/Feature/FundingAgreementTest.php tests/Feature/TransferTrackingTest.php tests/Feature/DonorDirectoryTest.php tests/Feature/ProposalDetailTest.php tests/Feature/ProposalDetailActionsTest.php tests/Feature/ExecutiveSummaryTest.php tests/Feature/LocalizationTest.php`
Expected: PASS.

- [x] **Step 8: Pint; leave changes uncommitted**

---

### Task 3: Shared component, proposal form and proposals list

**Files:**
- Create: `resources/views/components/ui/project-number.blade.php`
- Modify: `resources/views/pages/proposals/⚡index.blade.php`, `lang/{en,ar}/proposals.php`
- Test: `tests/Feature/ProposalPipelineTest.php`, `tests/Feature/FundingWorkflowTest.php`

**Interfaces:**
- Consumes: `Proposal::isPlaceholderNumber()`, `common.*` keys (Tasks 1–2).
- Produces: `<x-ui.project-number :number="?string" kind="internal|external" :label="bool" />`, rendering `<bdi dir="ltr">` for the number and `data-placeholder-chip="{number}"` on the chip; Livewire property `$internalProjectNumber` on `pages::proposals.index`.

- [x] **Step 1: Write the failing tests**

`tests/Feature/ProposalPipelineTest.php` — add `use App\Models\Activity;`, then:
- in "a proposal can be created with a trimmed name" add `->set('internalProjectNumber', 'HC-2026-041')` after the name.
- in "a proposal end date cannot fall before its begin date" add `->set('internalProjectNumber', 'HC-2026-042')`.
- in "a user without proposal permissions…" the `saveProposal` line becomes:

```php
    $fresh()->set('name', 'Sneaked in')->set('internalProjectNumber', 'HC-2026-043')->set('plannedDateBegin', '2026-01-01')->set('plannedDateEnd', '2026-12-31')->set('proposalStatus', 'concept note')->call('saveProposal')->assertForbidden();
```

Append:

```php
test('a proposal cannot be created without an internal project number', function () {
    $user = User::factory()->role('Financial')->create();

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openCreateModal')
        ->set('name', 'Rapid Response Fund')
        ->set('internalProjectNumber', '   ')
        ->set('proposalStatus', ProposalStatus::ConceptNote->value)
        ->set('plannedDateBegin', '2026-01-01')
        ->set('plannedDateEnd', '2026-12-31')
        ->call('saveProposal')
        ->assertHasErrors(['internalProjectNumber' => 'required']);

    expect(DB::table('proposals')->where('name', 'Rapid Response Fund')->exists())->toBeFalse();
});

test('a new proposal cannot reuse another proposal\'s internal number, whatever its case', function () {
    $user = User::factory()->role('Financial')->create();
    Proposal::factory()->create(['internal_project_number' => 'HC-2026-044']);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openCreateModal')
        ->set('name', 'Rapid Response Fund')
        ->set('internalProjectNumber', 'hc-2026-044')
        ->set('proposalStatus', ProposalStatus::ConceptNote->value)
        ->set('plannedDateBegin', '2026-01-01')
        ->set('plannedDateEnd', '2026-12-31')
        ->call('saveProposal')
        ->assertHasErrors(['internalProjectNumber' => 'unique'])
        ->assertSee('The internal project number has already been taken.');

    expect(DB::table('proposals')->where('name', 'Rapid Response Fund')->exists())->toBeFalse();
});

test('the internal project number is trimmed and limited to 50 characters', function () {
    $user = User::factory()->role('Financial')->create();

    $component = Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openCreateModal')
        ->set('name', 'Rapid Response Fund')
        ->set('proposalStatus', ProposalStatus::ConceptNote->value)
        ->set('plannedDateBegin', '2026-01-01')
        ->set('plannedDateEnd', '2026-12-31')
        ->set('internalProjectNumber', str_repeat('A', 51))
        ->call('saveProposal')
        ->assertHasErrors(['internalProjectNumber' => 'max']);

    $component->set('internalProjectNumber', '  HC-2026-045  ')
        ->call('saveProposal')
        ->assertHasNoErrors();

    expect(DB::table('proposals')->where('name', 'Rapid Response Fund')->value('internal_project_number'))->toBe('HC-2026-045');
});

test('a new internal number cannot use the reserved placeholder prefix', function (string $number) {
    $user = User::factory()->role('Financial')->create();

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openCreateModal')
        ->set('name', 'Rapid Response Fund')
        ->set('internalProjectNumber', $number)
        ->set('proposalStatus', ProposalStatus::ConceptNote->value)
        ->set('plannedDateBegin', '2026-01-01')
        ->set('plannedDateEnd', '2026-12-31')
        ->call('saveProposal')
        ->assertHasErrors('internalProjectNumber')
        ->assertSee(__('proposals.internal_project_number_prefix_reserved'));

    expect(DB::table('proposals')->where('name', 'Rapid Response Fund')->exists())->toBeFalse();
})->with(['TEMP-0099', 'temp-0099']);

test('a proposal still holding its placeholder saves while the number is left alone, but not with another placeholder', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create(['internal_project_number' => 'TEMP-0007']);

    $component = Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openEditModal', $proposal->id)
        ->assertSet('internalProjectNumber', 'TEMP-0007')
        ->set('internalProjectNumber', 'TEMP-0008')
        ->call('saveProposal')
        ->assertHasErrors('internalProjectNumber');

    $component->set('internalProjectNumber', 'TEMP-0007')
        ->set('name', 'Renamed proposal')
        ->call('saveProposal')
        ->assertHasNoErrors();

    $stored = DB::table('proposals')->find($proposal->id);

    expect($stored->name)->toBe('Renamed proposal')
        ->and($stored->internal_project_number)->toBe('TEMP-0007');
});

test('saving a proposal with its own internal number raises no duplicate error', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create(['internal_project_number' => 'HC-2026-046']);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openEditModal', $proposal->id)
        ->set('notes', 'Checked against the donor letter')
        ->call('saveProposal')
        ->assertHasNoErrors();

    expect(DB::table('proposals')->find($proposal->id)->notes)->toBe('Checked against the donor letter');
});

test('changing the internal number is recorded in the activity log and notifies', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Activity::withoutRecording(fn () => Proposal::factory()->create(['internal_project_number' => 'TEMP-0003']));

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openEditModal', $proposal->id)
        ->set('internalProjectNumber', 'HC-2026-047')
        ->call('saveProposal')
        ->assertHasNoErrors();

    $activity = Activity::query()->where('subject_type', 'proposal')->where('subject_id', $proposal->id)->sole();

    expect($activity->event)->toBe('proposal.updated')
        ->and($activity->changes['internal_project_number'])->toBe(['TEMP-0003', 'HC-2026-047'])
        ->and($activity->shouldNotify())->toBeTrue();
});

test('a duplicate that slips past validation shows the duplicate error instead of failing', function () {
    $user = User::factory()->role('Financial')->create();
    Proposal::factory()->create(['internal_project_number' => 'HC-2026-048']);

    // Stands in for a colleague saving the same number between this form's
    // validation and its insert: validation saw a free number, the database
    // does not.
    Proposal::creating(function (Proposal $proposal): void {
        $proposal->internal_project_number = 'HC-2026-048';
    });

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openCreateModal')
        ->set('name', 'Rapid Response Fund')
        ->set('internalProjectNumber', 'HC-2026-049')
        ->set('proposalStatus', ProposalStatus::ConceptNote->value)
        ->set('plannedDateBegin', '2026-01-01')
        ->set('plannedDateEnd', '2026-12-31')
        ->call('saveProposal')
        ->assertHasErrors('internalProjectNumber')
        ->assertSee('The internal project number has already been taken.')
        ->assertSet('showProposalModal', true);

    expect(DB::table('proposals')->where('name', 'Rapid Response Fund')->exists())->toBeFalse();
});

test('the proposals list finds a proposal by its internal number', function () {
    $user = User::factory()->role('Financial')->create();
    Proposal::factory()->create(['name' => 'Winter Shelter Kit', 'internal_project_number' => 'HC-2026-061']);
    Proposal::factory()->create(['name' => 'Cash for Work', 'internal_project_number' => 'HC-2026-062']);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->set('search', '2026-061')
        ->assertSee('Winter Shelter Kit')
        ->assertDontSee('Cash for Work');
});

test('a placeholder internal number carries the replace chip and a real one does not', function () {
    $user = User::factory()->role('Financial')->create();
    Proposal::factory()->create(['internal_project_number' => 'TEMP-0063']);
    Proposal::factory()->create(['internal_project_number' => 'HC-2026-063']);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->assertSeeHtml('<bdi dir="ltr" class="font-semibold tabular-nums">HC-2026-063</bdi>')
        ->assertSeeHtml('data-placeholder-chip="TEMP-0063"')
        ->assertDontSeeHtml('data-placeholder-chip="HC-2026-063"')
        ->assertSee('Placeholder — replace');
});
```

`tests/Feature/FundingWorkflowTest.php`, "a new proposal opens its status history…": add `->set('internalProjectNumber', 'HC-2026-051')` after `->set('name', 'Winter response')`. (The later edit save keeps the number loaded by `openEditModal`.)

- [x] **Step 2: Run them to verify they fail**

Run: `.\php.cmd artisan test --compact tests/Feature/ProposalPipelineTest.php tests/Feature/FundingWorkflowTest.php`
Expected: FAIL — property `internalProjectNumber` not found / NOT NULL violation on create.

- [x] **Step 3: Create the component**

Run: `.\php.cmd artisan make:component ui.project-number --view --no-interaction`
Replace `resources/views/components/ui/project-number.blade.php`:

```blade
{{--
    One project number. An internal number belongs to a proposal and carries an amber chip while it is
    still a migration placeholder (App\Models\Proposal::isPlaceholderNumber, so the TEMP- prefix lives
    in one place). An external number belongs to a funding agreement and may be empty. The number sits
    in <bdi dir="ltr"> so "HC-2026-001" never flips to "001-2026-HC" on an Arabic page.
--}}
@props([
    'number' => null,
    'kind' => 'internal',
    'label' => false,
])

@php
    $isInternal = $kind === 'internal';
    $isPlaceholder = $isInternal && \App\Models\Proposal::isPlaceholderNumber($number);
@endphp

<span {{ $attributes->class('inline-flex flex-wrap items-center gap-x-1.5 gap-y-1') }} data-project-number="{{ $kind }}">
    @if (filled($number))
        @if ($label)
            <span class="text-secondary-medium dark:text-ink-muted">{{ $isInternal ? __('common.internal_project_number') : __('common.external_project_number') }}</span>
        @endif
        <bdi dir="ltr" class="font-semibold tabular-nums">{{ $number }}</bdi>
        @if ($isPlaceholder)
            <span class="inline-flex items-center rounded-full bg-status-warn-soft px-2 py-0.5 text-[11px] font-semibold text-status-warn" data-placeholder-chip="{{ $number }}">{{ __('common.placeholder_number') }}</span>
        @endif
    @elseif (! $isInternal)
        <span class="text-secondary-medium dark:text-ink-muted">{{ __('common.no_external_project_number') }}</span>
    @endif
</span>
```

- [x] **Step 4: Proposal form, validation, list display and search**

`proposals/⚡index.blade.php` PHP block:
- imports: add `use Closure;`, `use Illuminate\Database\UniqueConstraintViolationException;`, `use Illuminate\Validation\Rule;`.
- property after `public string $name = '';`: `public string $internalProjectNumber = '';`
- `filteredQuery()` search:

```php
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', Like::contains($search))
                        ->orWhere('internal_project_number', 'like', Like::contains($search))
                        ->orWhere('notes', 'like', Like::contains($search));
                });
```
- `proposals()` select: `->select(['id', 'name', 'internal_project_number', 'planned_date_begin', 'planned_date_end', 'actual_date_begin', 'actual_date_end', 'status', 'updated_at'])`
- `openEditModal()` select adds `'internal_project_number'` after `'name'`, and after `$this->name = $proposal->name;`: `$this->internalProjectNumber = $proposal->internal_project_number;`
- `resetProposalForm()` after `$this->name = '';`: `$this->internalProjectNumber = '';`
- `saveProposal()` becomes:

```php
    public function saveProposal(): void
    {
        $this->name = trim($this->name);
        $this->internalProjectNumber = trim($this->internalProjectNumber);
        $this->notes = trim($this->notes);

        $storedNumber = $this->editingProposalId === null
            ? null
            : Proposal::query()->whereKey($this->editingProposalId)->value('internal_project_number');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'internalProjectNumber' => [
                'required',
                'string',
                'max:50',
                Rule::unique('proposals', 'internal_project_number')->ignore($this->editingProposalId),
                // TEMP- marks the placeholders the migration wrote. A proposal
                // still holding one saves unchanged, but nobody types a new one.
                function (string $attribute, mixed $value, Closure $fail) use ($storedNumber): void {
                    if (Proposal::isPlaceholderNumber((string) $value) && $value !== $storedNumber) {
                        $fail(__('proposals.internal_project_number_prefix_reserved'));
                    }
                },
            ],
            'plannedDateBegin' => ['required', 'date'],
            'plannedDateEnd' => ['required', 'date', 'after_or_equal:plannedDateBegin'],
            'actualDateBegin' => ['nullable', 'date'],
            'actualDateEnd' => ['nullable', 'date', 'after_or_equal:actualDateBegin'],
            'proposalStatus' => [$this->editingProposalId === null ? 'required' : 'nullable', new Enum(ProposalStatus::class)],
            'notes' => ['nullable', 'string'],
        ], [
            'name.required' => __('proposals.enter_a_proposal_name'),
            'plannedDateBegin.required' => __('proposals.set_the_planned_start_date'),
            'plannedDateEnd.required' => __('proposals.set_the_planned_end_date'),
            'plannedDateEnd.after_or_equal' => __('proposals.the_planned_end_cannot_fall_before_the_planned_start'),
            'actualDateEnd.after_or_equal' => __('proposals.the_actual_end_cannot_fall_before_the_actual_start'),
        ]);

        $proposal = $this->editingProposalId === null
            ? new Proposal
            : Proposal::query()->findOrFail($this->editingProposalId);

        Gate::authorize($proposal->exists ? 'update' : 'create', $proposal->exists ? $proposal : Proposal::class);

        // Status is only chosen when the proposal is first recorded. After that
        // it moves through recordStageChange(), which writes the audit note
        // alongside it, so the edit form never touches it.
        try {
            DB::transaction(function () use ($proposal, $validated): void {
                $proposal->fill([
                    'name' => $validated['name'],
                    'internal_project_number' => $validated['internalProjectNumber'],
                    'planned_date_begin' => $validated['plannedDateBegin'],
                    'planned_date_end' => $validated['plannedDateEnd'],
                    'actual_date_begin' => $validated['actualDateBegin'] === '' ? null : $validated['actualDateBegin'],
                    'actual_date_end' => $validated['actualDateEnd'] === '' ? null : $validated['actualDateEnd'],
                    'notes' => $validated['notes'] === '' ? null : $validated['notes'],
                ]);

                if (! $proposal->exists) {
                    $proposal->status = ProposalStatus::from($validated['proposalStatus']);
                }

                $proposal->save();

                if ($proposal->wasRecentlyCreated) {
                    ProposalStatusNote::query()->create([
                        'proposal_id' => $proposal->id,
                        'status' => $proposal->status,
                        'context' => __('proposals.proposal_recorded_note'),
                        'date' => now()->toDateString(),
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Two people saved the same new number at the same moment: both
            // passed validation and the unique index refused the second.
            if (! str_contains($exception->getMessage(), 'proposals_internal_project_number_unique')) {
                throw $exception;
            }

            $this->addError('internalProjectNumber', __('validation.unique', ['attribute' => __('validation.attributes.internalProjectNumber')]));

            return;
        }

        $wasRecentlyCreated = $proposal->wasRecentlyCreated;

        $this->showProposalModal = false;
        $this->resetProposalForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('proposals.proposal_created') : __('proposals.proposal_updated'),
        );
    }
```

Blade, row identity (after the name span at line 1102):

```blade
                                        <span class="text-lg leading-tight font-bold {{ $inkText }}">{{ $proposal->name }}</span>
                                        <x-ui.project-number :number="$proposal->internal_project_number" kind="internal" class="text-xs {{ $mutedText }}" />
```

Blade, form (Details section): `:fields="['name', 'internalProjectNumber', 'proposalStatus', 'notes']"`, and the full-width row goes after the `@endif` that closes the starting-status select, before notes:

```blade
                @endif

                <x-ui.form-input wire:model="internalProjectNumber" :label="__('common.internal_project_number')" maxlength="50" dir="ltr" required class="@2xl/modal:col-span-2" />

                <x-ui.form-textarea wire:model="notes" :label="__('common.notes')" rows="2" class="@2xl/modal:col-span-2" />
```

Lang: `lang/en/proposals.php` add (alphabetical) `'internal_project_number_prefix_reserved' => 'Numbers starting with TEMP- are reserved for placeholders. Enter the real internal project number.',`; `lang/ar/proposals.php` add `'internal_project_number_prefix_reserved' => 'الأرقام التي تبدأ بـ TEMP- محجوزة للأرقام المؤقتة. أدخل رقم المشروع الداخلي الفعلي.',`.

- [x] **Step 5: Run the tests to verify they pass**

Run: `.\php.cmd artisan test --compact tests/Feature/ProposalPipelineTest.php tests/Feature/FundingWorkflowTest.php tests/Feature/LocalizationTest.php`
Expected: PASS.

- [x] **Step 6: Compile check, Pint; leave changes uncommitted**

Lint the compiled page and component with the project's `bisect_blade.php` (per `.ai/rules/views.md`), then `vendor/bin/pint --dirty --format agent`.

---

### Task 4: Proposal detail header, locked proposal label, agreement picker

**Files:**
- Modify: `resources/views/pages/proposals/⚡show.blade.php`, `app/Livewire/Concerns/ManagesAgreementForm.php`, `resources/views/components/funding/agreement-modal.blade.php`
- Test: `tests/Feature/ProposalDetailTest.php`, `tests/Feature/FundingAgreementTest.php`

**Interfaces:**
- Consumes: `x-ui.project-number` (Task 3).

- [x] **Step 1: Write the failing tests**

`tests/Feature/ProposalDetailTest.php`:

```php
test('the proposal header shows its internal number and the agreement form names it', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create(['name' => 'Winter Shelter Kit', 'internal_project_number' => 'TEMP-0012']);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->assertSee('Internal project number')
        ->assertSeeHtml('data-placeholder-chip="TEMP-0012"')
        ->call('openAgreementCreate')
        ->assertSee('Winter Shelter Kit · TEMP-0012');
});
```

`tests/Feature/FundingAgreementTest.php`:

```php
test('the agreement picker names each proposal with its internal number', function () {
    $user = User::factory()->role('Financial')->create();
    Proposal::factory()->create(['name' => 'Winter Shelter Kit', 'internal_project_number' => 'HC-2026-071']);

    Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->call('openCreateModal')
        ->assertSee('Winter Shelter Kit · HC-2026-071');
});
```

- [x] **Step 2: Run them to verify they fail**

Run: `.\php.cmd artisan test --compact tests/Feature/ProposalDetailTest.php tests/Feature/FundingAgreementTest.php --filter="internal number"`
Expected: FAIL — number not shown.

- [x] **Step 3: Implement**

`proposals/⚡show.blade.php`:
- `proposal()` select: `->select(['id', 'name', 'internal_project_number', 'planned_date_begin', 'planned_date_end', 'actual_date_begin', 'actual_date_end', 'status', 'notes', 'updated_at'])`
- header meta, first item:

```blade
        <x-slot:meta>
            <x-ui.project-number :number="$this->proposal->internal_project_number" kind="internal" label />
            <span class="tabular-nums">{{ __('proposals.planned_range', [...unchanged...]) }}</span>
```
- agreement modal: `:locked-proposal-name="$this->proposal->name.' · '.$this->proposal->internal_project_number"`

`ManagesAgreementForm::proposalOptions()`:

```php
        return Proposal::query()->select(['id', 'name', 'internal_project_number', 'status'])->orderBy('name')->orderBy('id')->get();
```

`agreement-modal.blade.php` proposal option:

```blade
                        <option value="{{ $proposalOption->id }}">{{ $proposalOption->name }} · {{ $proposalOption->internal_project_number }}</option>
```

- [x] **Step 4: Run the tests to verify they pass**

Run: `.\php.cmd artisan test --compact tests/Feature/ProposalDetailTest.php tests/Feature/ProposalDetailActionsTest.php tests/Feature/FundingAgreementTest.php`
Expected: PASS.

- [x] **Step 5: Pint; leave changes uncommitted**

---

### Task 5: Funding agreements list and detail

**Files:**
- Modify: `resources/views/pages/funding-agreements/⚡index.blade.php`, `resources/views/pages/funding-agreements/⚡show.blade.php`
- Test: `tests/Feature/FundingAgreementTest.php`

- [x] **Step 1: Write the failing tests**

`tests/Feature/FundingAgreementTest.php`:

```php
test('the agreements list finds an agreement by its external number or its proposal\'s internal number', function () {
    $user = User::factory()->role('Financial')->create();
    DonorProposal::factory()->create([
        'proposal_id' => Proposal::factory()->create(['name' => 'Winter Shelter Kit', 'internal_project_number' => 'HC-2026-081'])->id,
        'external_project_number' => 'ECHO/SYR/2026/7',
    ]);
    DonorProposal::factory()->create([
        'proposal_id' => Proposal::factory()->create(['name' => 'Cash for Work', 'internal_project_number' => 'HC-2026-082'])->id,
        'external_project_number' => 'UNICEF-55',
    ]);

    Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->set('search', 'ECHO/SYR')
        ->assertSee('Winter Shelter Kit')
        ->assertDontSee('Cash for Work')
        ->set('search', 'HC-2026-082')
        ->assertSee('Cash for Work')
        ->assertDontSee('Winter Shelter Kit');
});

test('an agreement card shows its proposal\'s internal number beside its external number', function () {
    $user = User::factory()->role('Financial')->create();
    DonorProposal::factory()->create([
        'proposal_id' => Proposal::factory()->create(['internal_project_number' => 'HC-2026-083'])->id,
        'external_project_number' => 'ECHO-83',
    ]);

    Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->assertSeeInOrder(['Internal project number', 'HC-2026-083', 'External project number', 'ECHO-83']);
});

test('the agreement page shows both project numbers', function () {
    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create([
        'proposal_id' => Proposal::factory()->create(['internal_project_number' => 'HC-2026-084'])->id,
        'external_project_number' => 'ECHO-84',
    ]);

    $this->actingAs($user)
        ->get(route('funding-agreements.show', $agreement))
        ->assertOk()
        ->assertSeeInOrder(['Internal project number', 'HC-2026-084', 'External project number', 'ECHO-84']);
});
```

- [x] **Step 2: Run them to verify they fail**

Run: `.\php.cmd artisan test --compact tests/Feature/FundingAgreementTest.php --filter="project number|internal number"`
Expected: FAIL.

- [x] **Step 3: Implement**

`funding-agreements/⚡index.blade.php`:
- search:

```php
                    $query
                        ->where('external_project_number', 'like', Like::contains($search))
                        ->orWhereHas('donor', fn (Builder $query) => $query->where('name', 'like', Like::contains($search)))
                        ->orWhereHas('proposal', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                            ->where('name', 'like', Like::contains($search))
                            ->orWhere('internal_project_number', 'like', Like::contains($search))));
```
- eager load: `'proposal:id,name,internal_project_number,status',`
- the external `<div>` (dt/dd from Task 2) is replaced by:

```blade
                                                    <div class="grid grid-cols-2 gap-3">
                                                        <div>
                                                            <dt class="font-semibold {{ $mutedText }}">{{ __('common.internal_project_number') }}</dt>
                                                            <dd class="mt-1 font-bold {{ $inkText }}"><x-ui.project-number :number="$agreement->proposal->internal_project_number" kind="internal" /></dd>
                                                        </div>
                                                        <div>
                                                            <dt class="font-semibold {{ $mutedText }}">{{ __('funding_agreements.external_project_number') }}</dt>
                                                            <dd class="mt-1 font-bold {{ $inkText }}"><x-ui.project-number :number="$agreement->external_project_number" kind="external" /></dd>
                                                        </div>
                                                    </div>
```

`funding-agreements/⚡show.blade.php`:
- `agreement()` eager load: `'proposal:id,name,internal_project_number,status,planned_date_begin,planned_date_end,actual_date_begin,actual_date_end',`
- header meta, the first span (from Task 2) is replaced by:

```blade
            <x-ui.project-number :number="$proposal->internal_project_number" kind="internal" label class="{{ $inkText }}" />
            <span aria-hidden="true">·</span>
            <x-ui.project-number :number="$agreement->external_project_number" kind="external" label class="{{ $inkText }}" />
```

- [x] **Step 4: Run the tests to verify they pass**

Run: `.\php.cmd artisan test --compact tests/Feature/FundingAgreementTest.php tests/Feature/GanttTimelineTest.php`
Expected: PASS.

- [x] **Step 5: Pint; leave changes uncommitted**

---

### Task 6: Donor detail page

**Files:**
- Modify: `resources/views/pages/donors/⚡show.blade.php`
- Test: `tests/Feature/DonorDirectoryTest.php`

- [x] **Step 1: Write the failing test**

`tests/Feature/DonorDirectoryTest.php` (add `use App\Models\Proposal;` if absent):

```php
test('the donor page shows each agreement\'s internal number beside its external number', function () {
    $user = User::factory()->role('Financial')->create();
    $donor = Donor::factory()->create();
    $agreement = DonorProposal::factory()->create([
        'donor_id' => $donor->id,
        'proposal_id' => Proposal::factory()->create(['internal_project_number' => 'HC-2026-085'])->id,
        'external_project_number' => 'MCC-85',
    ]);
    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'scheduled_date' => now()->addMonth()->toDateString(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::donors.show', ['donor' => $donor])
        ->assertSeeHtmlInOrder([
            'data-lane="'.$agreement->id.'"', 'HC-2026-085', 'MCC-85',
            'data-agreement="'.$agreement->id.'"', 'HC-2026-085', 'External no. MCC-85',
        ]);
});
```

- [x] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/DonorDirectoryTest.php --filter="internal number"`
Expected: FAIL.

- [x] **Step 3: Implement**

`donors/⚡show.blade.php`:
- eager load `'proposal:id,name,internal_project_number,status',`
- lane label (line 633, from Task 2) becomes:

```blade
                                    <span class="flex flex-wrap items-center gap-x-1.5 {{ $mutedText }}">
                                        <x-ui.project-number :number="$lane['agreement']->proposal->internal_project_number" kind="internal" />
                                        <span aria-hidden="true">·</span>
                                        <x-ui.project-number :number="$lane['agreement']->external_project_number" kind="external" />
                                    </span>
```
- agreement link (line 702–705) becomes:

```blade
                            <a href="{{ route('funding-agreements.show', $agreement) }}" wire:navigate class="mt-1 inline-flex flex-wrap items-center gap-1 text-xs underline-offset-4 hover:underline {{ $mutedText }}">
                                <x-ui.project-number :number="$agreement->proposal->internal_project_number" kind="internal" />
                                · {{ filled($agreement->external_project_number) ? __('donors.agreement_ref', ['number' => $agreement->external_project_number]) : __('donors.view_agreement') }}
                                · {{ trans_choice('common.count_tranche', $agreement->transfers->count(), ['count' => $agreement->transfers->count()]) }}
                                <flux:icon.arrow-up-right variant="micro" class="size-3.5 rtl:-scale-x-100" aria-hidden="true" />
                            </a>
```

- [x] **Step 4: Run the tests to verify they pass**

Run: `.\php.cmd artisan test --compact tests/Feature/DonorDirectoryTest.php tests/Feature/FundingWorkflowTest.php`
Expected: PASS.

- [x] **Step 5: Pint; leave changes uncommitted**

---

### Task 7: Transfers list and transfer picker

**Files:**
- Modify: `resources/views/pages/transfers/⚡index.blade.php`, `app/Livewire/Concerns/ManagesTransferForm.php`, `resources/views/components/funding/transfer-modal.blade.php`
- Test: `tests/Feature/TransferTrackingTest.php`

- [x] **Step 1: Write the failing tests**

`tests/Feature/TransferTrackingTest.php` (add `use App\Models\Donor;`, `use App\Models\Proposal;`):

```php
test('the transfers ledger finds a tranche by either project number', function () {
    $user = User::factory()->role('Financial')->create();
    $echo = TransfersTracking::factory()->create(['donor_proposal_id' => DonorProposal::factory()->create([
        'proposal_id' => Proposal::factory()->create(['internal_project_number' => 'HC-2026-086'])->id,
        'external_project_number' => 'ECHO-86',
    ])->id]);
    $unicef = TransfersTracking::factory()->create(['donor_proposal_id' => DonorProposal::factory()->create([
        'proposal_id' => Proposal::factory()->create(['internal_project_number' => 'HC-2026-087'])->id,
        'external_project_number' => 'UNICEF-87',
    ])->id]);

    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->set('search', 'ECHO-86')
        ->assertSeeHtml('wire:key="transfer-'.$echo->id.'"')
        ->assertDontSeeHtml('wire:key="transfer-'.$unicef->id.'"')
        ->set('search', 'HC-2026-087')
        ->assertSeeHtml('wire:key="transfer-'.$unicef->id.'"')
        ->assertDontSeeHtml('wire:key="transfer-'.$echo->id.'"');
});

test('each tranche shows its proposal\'s internal number beside the external one', function () {
    $user = User::factory()->role('Financial')->create();
    TransfersTracking::factory()->create(['donor_proposal_id' => DonorProposal::factory()->create([
        'proposal_id' => Proposal::factory()->create(['internal_project_number' => 'HC-2026-088'])->id,
        'external_project_number' => 'ECHO-88',
    ])->id]);
    TransfersTracking::factory()->create(['donor_proposal_id' => DonorProposal::factory()->create([
        'proposal_id' => Proposal::factory()->create(['internal_project_number' => 'HC-2026-089'])->id,
    ])->id]);

    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->assertSeeInOrder(['HC-2026-088', 'External no. ECHO-88'])
        ->assertSee('HC-2026-089')
        ->assertSee('No external project number');
});

test('the transfer picker names each agreement by both project numbers', function () {
    $user = User::factory()->role('Financial')->create();
    DonorProposal::factory()->create([
        'donor_id' => Donor::factory()->create(['name' => 'ECHO'])->id,
        'proposal_id' => Proposal::factory()->create(['name' => 'Winter Shelter Kit', 'internal_project_number' => 'HC-2026-090'])->id,
        'external_project_number' => 'ECHO-90',
        'budget' => '2000.00',
    ]);
    DonorProposal::factory()->create([
        'donor_id' => Donor::factory()->create(['name' => 'UNICEF'])->id,
        'proposal_id' => Proposal::factory()->create(['name' => 'Cash for Work', 'internal_project_number' => 'HC-2026-091'])->id,
        'budget' => '1000.00',
    ]);

    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->call('openTransferCreate')
        ->assertSee('ECHO · Winter Shelter Kit · HC-2026-090 · ECHO-90 · 2,000 USD')
        ->assertSee('UNICEF · Cash for Work · HC-2026-091 · 1,000 USD');
});
```

- [x] **Step 2: Run them to verify they fail**

Run: `.\php.cmd artisan test --compact tests/Feature/TransferTrackingTest.php --filter="project number|internal number"`
Expected: FAIL.

- [x] **Step 3: Implement**

`transfers/⚡index.blade.php`:
- search:

```php
                $query->whereHas('donorProposal', function (Builder $query) use ($search): void {
                    $query
                        ->where('external_project_number', 'like', Like::contains($search))
                        ->orWhereHas('donor', fn (Builder $query) => $query->where('name', 'like', Like::contains($search)))
                        ->orWhereHas('proposal', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                            ->where('name', 'like', Like::contains($search))
                            ->orWhere('internal_project_number', 'like', Like::contains($search))));
                });
```
- eager load: `'donorProposal.proposal:id,name,internal_project_number',`
- row (line 943–945): between the proposal link and the agreement link:

```blade
                                            <a href="{{ route('proposals.show', $transfer->donorProposal->proposal) }}" wire:navigate class="truncate underline-offset-2 hover:underline">{{ $transfer->donorProposal->proposal->name }}</a>
                                            <span aria-hidden="true">·</span>
                                            <x-ui.project-number :number="$transfer->donorProposal->proposal->internal_project_number" kind="internal" class="shrink-0" />
                                            <span aria-hidden="true">·</span>
                                            <a href="{{ route('funding-agreements.show', $transfer->donorProposal) }}" ...unchanged... </a>
```
- expanded reference line (line 1123):

```blade
                                        <span class="inline-flex flex-wrap items-center gap-x-1.5 {{ $inkText }}">
                                            <x-ui.project-number :number="$transfer->donorProposal->proposal->internal_project_number" kind="internal" />
                                            <span aria-hidden="true">·</span>
                                            @if (filled($transfer->donorProposal->external_project_number))
                                                <span>{{ __('transfers.reference_num', ['reference' => $transfer->donorProposal->external_project_number]) }}</span>
                                            @else
                                                <span class="{{ $mutedText }}">{{ __('common.no_external_project_number') }}</span>
                                            @endif
                                        </span>
```

`ManagesTransferForm::agreementOptions()`: `->with(['donor:id,name', 'proposal:id,name,internal_project_number'])`.

`transfer-modal.blade.php` option:

```blade
                    <option value="{{ $agreementOption->id }}">{{ $agreementOption->donor->name }} · {{ $agreementOption->proposal->name }} · {{ $agreementOption->proposal->internal_project_number }}@if (filled($agreementOption->external_project_number)) · {{ $agreementOption->external_project_number }}@endif · {{ number_format((float) $agreementOption->budget, 0) }} {{ $agreementOption->currency->value }}</option>
```

- [x] **Step 4: Run the tests to verify they pass**

Run: `.\php.cmd artisan test --compact tests/Feature/TransferTrackingTest.php tests/Feature/ProposalDetailActionsTest.php tests/Feature/FundingAgreementTest.php`
Expected: PASS (the transfer modal is shared by all three pages).

- [x] **Step 5: Pint; leave changes uncommitted**

---

### Task 8: Executive summary and project detail

**Files:**
- Modify: `resources/views/pages/executive-summary/⚡index.blade.php`, `resources/views/pages/projects/⚡show.blade.php`
- Test: `tests/Feature/ExecutiveSummaryTest.php`, `tests/Feature/ProjectDetailTest.php`

- [x] **Step 1: Write the failing tests**

`tests/Feature/ExecutiveSummaryTest.php` (add `use App\Models\Proposal;`):

```php
test('an agreement line in the money breakdown names its proposal and internal number', function () {
    $user = User::factory()->role('General Manager')->create();
    $agreement = DonorProposal::factory()->create([
        'currency' => Currency::Usd,
        'proposal_id' => Proposal::factory()->create(['name' => 'Winter Shelter Kit', 'internal_project_number' => 'HC-2026-092'])->id,
    ]);

    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '1000.00',
        'amount_transfered_currency' => Currency::Usd,
        'scheduled_date' => currentFiscalYear().'-02-01',
    ]);

    $row = collect(
        Livewire::actingAs($user)->test('pages::executive-summary.index')->call('toggleRow', 'money-USD')->instance()->breakdown
    )->firstWhere('key', 'agreement-'.$agreement->id);

    expect($row['context'])->toBe('Winter Shelter Kit · HC-2026-092');
});
```

`tests/Feature/ProjectDetailTest.php`:

```php
test('the project page shows a linked proposal\'s internal number and lists attachable ones by number', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $linked = Proposal::factory()->create(['name' => 'Winter Shelter Kit', 'internal_project_number' => 'TEMP-0021']);
    $project->proposals()->attach($linked->id);
    Proposal::factory()->create(['name' => 'Cash for Work', 'internal_project_number' => 'HC-2026-093']);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'proposals')
        ->assertSeeHtml('data-placeholder-chip="TEMP-0021"')
        ->assertSee('Cash for Work · HC-2026-093');
});
```

- [x] **Step 2: Run them to verify they fail**

Run: `.\php.cmd artisan test --compact tests/Feature/ExecutiveSummaryTest.php tests/Feature/ProjectDetailTest.php --filter="internal number"`
Expected: FAIL.

- [x] **Step 3: Implement**

`executive-summary/⚡index.blade.php` `moneyByAgreement()`:

```php
            ->with(['donorProposal:id,donor_id,proposal_id,external_project_number', 'donorProposal.donor:id,name', 'donorProposal.proposal:id,name,internal_project_number'])
```
```php
                    'context' => $agreement?->proposal === null ? '—' : $agreement->proposal->name.' · '.$agreement->proposal->internal_project_number,
```

`projects/⚡show.blade.php`:
- `attachedProposals()` select: `->select(['proposals.id', 'proposals.name', 'proposals.internal_project_number', 'proposals.status', 'proposals.planned_date_begin', 'proposals.planned_date_end'])`
- `attachableProposals()` select: `->select(['id', 'name', 'internal_project_number', 'status'])`
- picker option: `<flux:select.option :value="$proposalOption->id" :wire:key="'attachable-'.$proposalOption->id">{{ $proposalOption->name }} · {{ $proposalOption->internal_project_number }}</flux:select.option>`
- linked proposal card, directly after the name `@endif` (before the dates `<p>`):

```blade
                                    <x-ui.project-number :number="$proposal->internal_project_number" kind="internal" class="mt-1 text-xs text-secondary-medium dark:text-ink-muted" />
```

- [x] **Step 4: Run the tests to verify they pass**

Run: `.\php.cmd artisan test --compact tests/Feature/ExecutiveSummaryTest.php tests/Feature/ProjectDetailTest.php`
Expected: PASS.

- [x] **Step 5: Pint; leave changes uncommitted**

---

### Task 9: Final checks, rule, hand-off

- [x] **Step 1: Grep for leftovers**

`project_num|projectNum|donor_reference|no_donor_reference` → only the two migrations. `@php(` in the touched Blade files → none.

- [x] **Step 2: Run every affected test file once more (not the full suite)**

```
.\php.cmd artisan test --compact tests/Feature/ProposalTest.php tests/Feature/DonorProposalTest.php tests/Feature/Models/DonorTest.php tests/Feature/Models/ProposalTest.php tests/Feature/ProposalPipelineTest.php tests/Feature/FundingWorkflowTest.php tests/Feature/FundingAgreementTest.php tests/Feature/TransferTrackingTest.php tests/Feature/ProjectDetailTest.php tests/Feature/ProposalDetailTest.php tests/Feature/ProposalDetailActionsTest.php tests/Feature/DonorDirectoryTest.php tests/Feature/ExecutiveSummaryTest.php tests/Feature/GanttTimelineTest.php tests/Feature/AccessControl/FundraisingPagesMoneyTest.php tests/Feature/DatabaseSeederTest.php tests/Feature/RealDataSeederTest.php tests/Feature/LocalizationTest.php tests/Feature/DatabaseEncodingTest.php tests/Feature/Models/MorphMapTest.php
```
Expected: PASS.

- [x] **Step 3: Pint** — `vendor/bin/pint --dirty --format agent`.

- [x] **Step 4: Record the rule** with `record-rule`, glob `{app/Models/Proposal.php,app/Models/DonorProposal.php,resources/views/pages/{proposals,funding-agreements,transfers,donors}/**}`, title "Internal and external project numbers", note: internal number lives on proposals (`internal_project_number`, required, unique, case-insensitive via collation, `TEMP-` reserved for migration placeholders — `Proposal::isPlaceholderNumber`); external number lives on agreements (`donor_proposal.external_project_number`, optional, non-unique); display goes through `x-ui.project-number`.

- [x] **Step 5: Report** (numbered: files changed, tests run with results, anything skipped/failed with output) and give you the commands below.

- [x] **Step 6: After you run `migrate`**, verify read-only with the Boost `database-query` tool: `select count(*), min(internal_project_number), max(internal_project_number) from proposals` (expect every row `TEMP-NNNN`) and `show index from proposals where Key_name = 'proposals_internal_project_number_unique'`.

## Commands for you (after implementation)

```
.\php.cmd artisan migrate
```

(Applies both migrations to `hc_tracking`; existing proposals become `TEMP-0001`…)
