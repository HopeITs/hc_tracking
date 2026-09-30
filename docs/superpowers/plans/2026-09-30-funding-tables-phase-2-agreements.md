# Funding tables — Phase 2: Funding agreements register

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Funding agreements page opens on a table with one row per agreement and every money figure in its own column (budget, scheduled, received, % received, in transit, overdue, failed, not scheduled, outstanding, next due), filterable by year, status, currency, flags and budget, sortable, totalled per currency and exportable; today's collection ledger and cash-flow calendar stay behind the `Table | Overview` switch.

**Architecture:** The page keeps its class (`ledger`, `calendar`, `stageCounts`, `agreements`, `agreementMoney`, the agreement form trait) and gains the `FiltersFinanceRegister` trait, three new filters and a `rows` paginator whose money columns come from one SQL join (`DonorProposal::withMoney()`, Phase 0), so they can be sorted, totalled and exported without loading tranches. Three small Blade components print an agreement's cells, headings and totals from one shared column list; Phase 6 reuses them on the donor and proposal pages.

**Tech Stack:** Laravel 13, Livewire 4 single-file page components, Flux (lite), Tailwind 4, Pest 5, MySQL.

**Depends on:** Phase 0 done. Phase 1 reviewed by the user (it sets the look).

**Spec:** `docs/superpowers/specs/2026-09-30-funding-finance-tables-design.md`

## Global Constraints

- PHP runs through `.\php.cmd` (PHP 8.5 at `C:\php85`), never bare `php`. From the Bash tool use `/c/php85/php.exe`.
- **Do not commit.** Leave every change in the working tree for the user's review.
- Never run the whole test suite. Run only the files each step names.
- Run `.\php.cmd vendor/bin/pint --dirty --format agent` after changing any PHP file.
- No new Composer or npm package. No migration.
- Never use the single-line `@php(...)` shorthand in Blade.
- Every visible string goes through a lang key present in both `lang/en` and `lang/ar`. This phase adds no keys: every `finance_tables.*` key it uses was created in Phase 0.
- Dark mode: only Graphite tokens behind `dark:`. Alarm text is `text-status-critical`.
- Logical Tailwind classes only (`ms/me/ps/pe/start/end`).
- Currencies are never added together. Definitions (one source: `App\Support\AgreementMoney`): **Scheduled** = every tranche sent in the agreement's own currency; **Received** = net landed in that currency; **Not scheduled** = budget − scheduled; **Outstanding** = budget − the payment amounts of tranches already received; never below zero.
- No existing test is deleted. A test that asserts on the old visual is pointed at the Overview view with `->set('view', 'overview')`.
- This page has no proposal picker: three existing tests assert that a filtered-out proposal's name is absent from the page, and a picker would list every name. Proposals are found through the search box.
- Line numbers quoted below are for commit `99c95eb`.

## File Structure

| File | Responsibility |
| --- | --- |
| Create `resources/views/components/funding/agreement-head.blade.php` | Heading cells for an agreement table, from a column list. |
| Create `resources/views/components/funding/agreement-row.blade.php` | One agreement as a table row. |
| Create `resources/views/components/funding/agreement-foot.blade.php` | One totals row per currency. |
| Create `resources/views/pages/funding-agreements/_overview.blade.php` | Today's ledger and calendar, moved unchanged. |
| Modify `resources/views/pages/funding-agreements/⚡index.blade.php` | New filters, table data, template. |
| Create `tests/Feature/AgreementComponentsTest.php` | Renders the agreement components. |
| Create `tests/Feature/FundingAgreementRegisterTest.php` | The table. |
| Modify `tests/Feature/FundingAgreementTest.php` | Point four visual assertions at the Overview view. |

---

### Task 1: Agreement table components

**Files:**
- Create: `resources/views/components/funding/agreement-head.blade.php`
- Create: `resources/views/components/funding/agreement-row.blade.php`
- Create: `resources/views/components/funding/agreement-foot.blade.php`
- Test: `tests/Feature/AgreementComponentsTest.php`

**Interfaces:**
- Consumes (Phase 0):
  - `App\Support\AgreementMoney::columns(bool $withTransfers = true, ?int $year = null, bool $yearOnly = false, bool $withFailed = false, bool $withReports = true): list<array{key: string, label: string, numeric: bool}>` — keys, in order: `currency`, `budget`, then (with transfers) `scheduled`, `received`, `percent`, (`due_in_year`, `received_in_year` when a year is chosen and not year-only), `in_transit`, `overdue`, (`failed`), (`unscheduled`, `outstanding` unless year-only), `next_due`, `tranches`, then (with reports) `reports`.
  - `DonorProposal::query()->withMoney(?int $year = null, bool $yearOnly = false)` — adds `money_tranches`, `money_scheduled`, `money_received`, `money_in_transit`, `money_overdue`, `money_overdue_count`, `money_upcoming`, `money_failed`, `money_net_missing`, `money_other_currency`, `money_next_due`, `money_last_received`, `money_due_in_year`, `money_received_in_year`, `money_unscheduled`, `money_outstanding`.
  - `DonorProposal::percentLabel(float, float): string`.
  - `x-funding.register.th`; register CSS classes.
- Produces:
  - `<x-funding.agreement-head :columns="$columns" :sortable="bool" :active :dir :actions="bool" />`
  - `<x-funding.agreement-row :agreement="$a" :columns="$columns" :year-only="bool">` with optional slots `lead` and `actions`. Prints `<tr wire:key="agreement-{id}" data-agreement-row="{id}">`. When the `reports` column is present the model must carry `overdue_reports_count` and `next_report_due`.
  - `<x-funding.agreement-foot :lines="$lines" :columns="$columns" :lead="int" :year-only="bool" :actions="bool" />` where a line is `array{currency: string, agreements: int, tranches: int, budget: float, scheduled: float, received: float, in_transit: float, overdue: float, failed: float, unscheduled: float, outstanding: float, due_in_year: float, received_in_year: float}` (the shape `AgreementMoney::line()` returns).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/AgreementComponentsTest.php`:

```php
<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\TransfersTracking;
use App\Support\AgreementMoney;
use Illuminate\Support\Facades\Blade;

/**
 * A 1,000 USD agreement with 400 received (390 net), 200 overdue and one
 * overdue donor report, loaded the way the agreement tables load it.
 */
function componentAgreement(): DonorProposal
{
    $agreement = DonorProposal::factory()->create(['budget' => '1000.00', 'currency' => Currency::Usd, 'project_num' => 'REF-7']);

    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '400.00',
        'amount_transfered_currency' => Currency::Usd,
        'net_amount_received' => '390.00',
        'amount_received_currency' => Currency::Usd,
        'scheduled_date' => now()->subMonth()->toDateString(),
        'transfer_date' => now()->subMonth()->toDateString(),
        'receive_date' => now()->subMonth()->toDateString(),
        'ar_status' => ArStatus::Received,
    ]);
    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '200.00',
        'amount_transfered_currency' => Currency::Usd,
        'scheduled_date' => now()->subDays(10)->toDateString(),
        'transfer_date' => null,
        'ar_status' => ArStatus::Pending,
    ]);
    DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'due_on' => now()->subDays(2)->toDateString()]);

    return DonorProposal::query()
        ->select('donor_proposal.*')
        ->withMoney()
        ->withCount(['reports as overdue_reports_count' => fn ($query) => $query->whereNull('submitted_on')->whereDate('due_on', '<', now()->toDateString())])
        ->withMin(['reports as next_report_due' => fn ($query) => $query->whereNull('submitted_on')->whereDate('due_on', '>=', now()->toDateString())], 'due_on')
        ->findOrFail($agreement->id);
}

test('an agreement row prints every money column exactly', function () {
    $agreement = componentAgreement();
    $columns = AgreementMoney::columns();

    $html = Blade::render(
        '<table><tbody><x-funding.agreement-row :agreement="$agreement" :columns="$columns" /></tbody></table>',
        compact('agreement', 'columns'),
    );

    expect($html)->toContain('data-agreement-row="'.$agreement->id.'"')
        ->and($html)->toContain('1,000.00')
        ->and($html)->toContain('600.00')
        ->and($html)->toContain('390.00')
        ->and($html)->toContain('39%')
        ->and($html)->toContain('200.00')
        ->and($html)->toContain('400.00')
        ->and($html)->toContain('data-report-overdue')
        ->and($html)->toContain('1 donor report overdue');
});

test('the row, the head and the foot always print the same number of cells', function (array $arguments) {
    $agreement = componentAgreement();
    $columns = AgreementMoney::columns(...$arguments);
    $lines = AgreementMoney::totals([$agreement]);

    $head = Blade::render('<table><thead><tr><x-funding.agreement-head :columns="$columns" /></tr></thead></table>', compact('columns'));
    $row = Blade::render('<table><tbody><x-funding.agreement-row :agreement="$agreement" :columns="$columns" /></tbody></table>', compact('agreement', 'columns'));
    $foot = Blade::render('<table><tfoot><x-funding.agreement-foot :lines="$lines" :columns="$columns" :lead="0" /></tfoot></table>', compact('lines', 'columns'));

    expect(substr_count($head, '<th scope='))->toBe(count($columns) + 1)
        ->and(substr_count($row, '<td'))->toBe(count($columns))
        ->and(substr_count($foot, '<td'))->toBe(count($columns) + 1);
})->with([
    'every column' => [['withFailed' => true]],
    'a year beside the lifetime figures' => [['year' => 2026]],
    'one year only' => [['year' => 2026, 'yearOnly' => true]],
    'no transfers permission' => [['withTransfers' => false]],
    'no reports' => [['withReports' => false]],
]);

test('without the transfers columns a row shows the budget and nothing derived from tranches', function () {
    $agreement = componentAgreement();
    $columns = AgreementMoney::columns(withTransfers: false, withReports: false);

    $html = Blade::render('<table><tbody><x-funding.agreement-row :agreement="$agreement" :columns="$columns" /></tbody></table>', compact('agreement', 'columns'));

    expect($html)->toContain('1,000.00')
        ->and($html)->not->toContain('390.00')
        ->and($html)->not->toContain('data-cell="received"');
});

test('the foot prints one row per currency with its own percentage', function () {
    $lines = [
        ['currency' => 'EUR', 'agreements' => 1, 'tranches' => 0, 'budget' => 500.0, 'scheduled' => 0.0, 'received' => 0.0, 'in_transit' => 0.0, 'overdue' => 0.0, 'failed' => 0.0, 'unscheduled' => 500.0, 'outstanding' => 500.0, 'due_in_year' => 0.0, 'received_in_year' => 0.0],
        ['currency' => 'USD', 'agreements' => 2, 'tranches' => 3, 'budget' => 2000.0, 'scheduled' => 600.0, 'received' => 390.0, 'in_transit' => 0.0, 'overdue' => 200.0, 'failed' => 0.0, 'unscheduled' => 1400.0, 'outstanding' => 1600.0, 'due_in_year' => 0.0, 'received_in_year' => 0.0],
    ];
    $columns = AgreementMoney::columns();

    $html = Blade::render('<table><tfoot><x-funding.agreement-foot :lines="$lines" :columns="$columns" :lead="1" /></tfoot></table>', compact('lines', 'columns'));

    expect($html)->toContain('data-register-total="EUR"')
        ->and($html)->toContain('data-register-total="USD"')
        ->and($html)->toContain('2,000.00')
        ->and($html)->toContain('19%')
        ->and($html)->toContain('2 agreements')
        ->and($html)->not->toContain('2,500.00');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `.\php.cmd artisan test --compact tests/Feature/AgreementComponentsTest.php`
Expected: FAIL — `Unable to locate a class or view for component [funding.agreement-row]`.

- [ ] **Step 3: Create the head component**

Create `resources/views/components/funding/agreement-head.blade.php`:

```blade
@props(['columns', 'sortable' => false, 'active' => '', 'dir' => 'asc', 'actions' => true])

{{--
    The headings of an agreement table, from the column list
    App\Support\AgreementMoney::columns() builds. A page prints its identity
    column's heading itself, before this component. The sort key of a column
    is its key; currency and reports do not sort.
--}}
@foreach ($columns as $column)
    <x-funding.register.th
        :sort="$sortable && ! in_array($column['key'], ['currency', 'reports'], true) ? $column['key'] : null"
        :active="$active"
        :dir="$dir"
        :numeric="$column['numeric']"
    >{{ $column['label'] }}</x-funding.register.th>
@endforeach

@if ($actions)
    <th scope="col"><span class="sr-only">{{ __('common.actions') }}</span></th>
@endif
```

- [ ] **Step 4: Create the row component**

Create `resources/views/components/funding/agreement-row.blade.php`:

```blade
@props(['agreement', 'columns', 'yearOnly' => false])

@php
    /**
     * One agreement as a table row. Every money figure is a `money_*` column
     * DonorProposal::withMoney() selected, so the row, the sort, the totals
     * and the export all read the same number.
     *
     * @var \App\Models\DonorProposal $agreement
     */
    $money = fn (string|float|int|null $value): string => number_format((float) $value, 2);
    $budget = (float) $agreement->budget;
    $today = \Carbon\CarbonImmutable::today();
@endphp

<tr wire:key="agreement-{{ $agreement->id }}" data-agreement-row="{{ $agreement->id }}">
    {{ $lead ?? '' }}

    @foreach ($columns as $column)
        @switch($column['key'])
            @case('currency')
                <td data-label="{{ $column['label'] }}">{{ $agreement->currency->value }}</td>
                @break

            @case('budget')
                <td class="num" data-label="{{ $column['label'] }}" data-cell="budget">
                    <span class="fig font-semibold">{{ $money($budget) }}</span>
                </td>
                @break

            @case('received')
            @case('received_in_year')
                <td class="num" data-label="{{ $column['label'] }}" data-cell="{{ $column['key'] }}">
                    <span class="fig font-semibold text-funding">{{ $money($agreement->getAttribute('money_'.$column['key'])) }}</span>
                </td>
                @break

            @case('percent')
                @php
                    // In year-only mode the share is of what that year expected, not of the whole budget.
                    $percentBase = $yearOnly ? (float) $agreement->money_scheduled : $budget;
                @endphp
                <td class="num" data-label="{{ $column['label'] }}" data-cell="percent">
                    <span class="fig">{{ \App\Models\DonorProposal::percentLabel((float) $agreement->money_received, $percentBase) }}%</span>
                </td>
                @break

            @case('overdue')
            @case('failed')
                @php
                    $alarmValue = (float) $agreement->getAttribute('money_'.$column['key']);
                @endphp
                <td class="num" data-label="{{ $column['label'] }}" data-cell="{{ $column['key'] }}">
                    <span @class(['fig', 'font-semibold text-status-critical' => $alarmValue > 0])>{{ $money($alarmValue) }}</span>
                </td>
                @break

            @case('next_due')
                @php
                    $nextDue = $agreement->money_next_due !== null ? \Carbon\CarbonImmutable::parse($agreement->money_next_due) : null;
                @endphp
                <td class="date" data-label="{{ $column['label'] }}" data-cell="next_due">
                    @if ($nextDue === null)
                        —
                    @else
                        <span @class(['font-semibold text-status-critical' => $nextDue->lt($today)])>{{ $nextDue->translatedFormat(__('formats.date')) }}</span>
                    @endif
                </td>
                @break

            @case('tranches')
                <td class="num" data-label="{{ $column['label'] }}" data-cell="tranches">
                    @can('transfers.view')
                        <a href="{{ route('transfers.index', ['agreement' => $agreement->id]) }}" wire:navigate class="fig font-semibold underline underline-offset-2" data-tranches-link>{{ (int) $agreement->money_tranches }}</a>
                    @else
                        <span class="fig">{{ (int) $agreement->money_tranches }}</span>
                    @endcan
                </td>
                @break

            @case('reports')
                <td data-label="{{ $column['label'] }}" data-cell="reports">
                    @if ((int) $agreement->overdue_reports_count > 0)
                        <span class="font-semibold text-status-critical" data-report-overdue>{{ trans_choice('funding_agreements.count_donor_reports_overdue', (int) $agreement->overdue_reports_count, ['count' => (int) $agreement->overdue_reports_count]) }}</span>
                    @elseif ($agreement->next_report_due !== null)
                        <span data-report-due>{{ \Carbon\CarbonImmutable::parse($agreement->next_report_due)->translatedFormat(__('formats.date')) }}</span>
                    @else
                        —
                    @endif
                </td>
                @break

            @default
                <td class="num" data-label="{{ $column['label'] }}" data-cell="{{ $column['key'] }}">
                    <span class="fig">{{ $money($agreement->getAttribute('money_'.$column['key'])) }}</span>
                </td>
        @endswitch
    @endforeach

    {{ $actions ?? '' }}
</tr>
```

- [ ] **Step 5: Create the foot component**

Create `resources/views/components/funding/agreement-foot.blade.php`:

```blade
@props(['lines' => [], 'columns', 'lead' => 1, 'actions' => true, 'yearOnly' => false, 'label' => null])

@php
    /**
     * One totals row per currency, under the columns x-funding.agreement-row
     * prints. `lead` is how many identity cells the page prints first.
     */
    $money = fn (string|float|int $value): string => number_format((float) $value, 2);
@endphp

@foreach ($lines as $line)
    <tr wire:key="agreement-total-{{ $line['currency'] }}" data-register-total="{{ $line['currency'] }}">
        @if ($lead > 0)
            <td colspan="{{ $lead }}" data-wide>
                {{ $label ?? __('finance_tables.total') }}
                <span class="sub">{{ trans_choice('common.count_agreement', $line['agreements'], ['count' => $line['agreements']]) }}</span>
            </td>
        @endif

        @foreach ($columns as $column)
            @switch($column['key'])
                @case('currency')
                    <td data-label="{{ $column['label'] }}">{{ $line['currency'] }}</td>
                    @break

                @case('percent')
                    <td class="num" data-label="{{ $column['label'] }}">
                        <span class="fig">{{ \App\Models\DonorProposal::percentLabel((float) $line['received'], (float) ($yearOnly ? $line['scheduled'] : $line['budget'])) }}%</span>
                    </td>
                    @break

                @case('tranches')
                    <td class="num" data-label="{{ $column['label'] }}"><span class="fig">{{ (int) $line['tranches'] }}</span></td>
                    @break

                @case('next_due')
                @case('reports')
                    <td></td>
                    @break

                @default
                    <td class="num" data-label="{{ $column['label'] }}"><span class="fig">{{ $money($line[$column['key']]) }}</span></td>
            @endswitch
        @endforeach

        @if ($actions)
            <td></td>
        @endif
    </tr>
@endforeach
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `.\php.cmd artisan test --compact tests/Feature/AgreementComponentsTest.php`
Expected: PASS, 8 tests (one of them with five data sets).

If the cell-count test fails for one data set, the three components disagree about that column: each must print exactly one cell per entry of `$columns`.

- [ ] **Step 7: Checkpoint**

Run: `.\php.cmd vendor/bin/pint --dirty --format agent`. Do not commit.

---

### Task 2: Move today's ledger and calendar into a partial

A pure move: the page looks and behaves exactly as before when this task ends.

**Files:**
- Create: `resources/views/pages/funding-agreements/_overview.blade.php`
- Modify: `resources/views/pages/funding-agreements/⚡index.blade.php` (lines 631–1339)

- [ ] **Step 1: Confirm the block boundaries**

```bash
cd /c/xampp/htdocs/hc-tracking && sed -n '549p;592p;631p;1339p;1340p' "resources/views/pages/funding-agreements/⚡index.blade.php"
```

Expected output, exactly:

```
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    ];
        {{-- The collection ledger: one line per currency, the promise split by where its money is. --}}
        </section>
    @endif
```

- [ ] **Step 2: Build the partial**

The partial carries its own copy of the formatting variables (lines 549–592), so it needs nothing from the page but `$this` and `$stage`.

```bash
cd /c/xampp/htdocs/hc-tracking && {
  printf '%s\n' '{{--' \
    '    The Overview view of the funding agreements page: the per-currency' \
    '    collection ledger, the stage chips and the cash-flow calendar. Included' \
    '    by ⚡index.blade.php. It defines its own formatting variables, so it' \
    '    reads nothing from the page except $this and $stage.' \
    '--}}' '@php'
  sed -n '549,592p' "resources/views/pages/funding-agreements/⚡index.blade.php"
  printf '%s\n' '@endphp' ''
  sed -n '631,1339p' "resources/views/pages/funding-agreements/⚡index.blade.php"
} > resources/views/pages/funding-agreements/_overview.blade.php
```

- [ ] **Step 3: Fix what a partial cannot import**

In `resources/views/pages/funding-agreements/_overview.blade.php`:

1. The copied variables use two facades the page imported. Replace `Number::format(` with `\Illuminate\Support\Number::format(` and `Number::abbreviate(` with `\Illuminate\Support\Number::abbreviate(` (one occurrence each, in the `$amount` and `$shortAmount` closures).
2. Replace the one occurrence of `$transfer->ar_status === ArStatus::Received` with `$transfer->ar_status === \App\Enums\ArStatus::Received`.

Verify:

```bash
cd /c/xampp/htdocs/hc-tracking && grep -n -E "(^|[^\\\\A-Za-z])(ArStatus|Currency|CarbonImmutable|Number|TransfersTracking|DonorProposal|Collection)::" resources/views/pages/funding-agreements/_overview.blade.php
```

Expected: no output.

- [ ] **Step 4: Replace the block in the page with the include**

In `resources/views/pages/funding-agreements/⚡index.blade.php` delete lines 631–1339 and put this single line in their place:

```blade
        @include('pages.funding-agreements._overview')
```

Leave the page's own `@php` block (lines 546–593) as it is for now; Task 3 rewrites it.

- [ ] **Step 5: Prove nothing changed**

Run: `.\php.cmd artisan test --compact tests/Feature/FundingAgreementTest.php tests/Feature/FundingWorkflowTest.php`
Expected: PASS, the same counts as before (33 and 14 tests).

---

### Task 3: The register — class, template and tests

**Files:**
- Modify: `resources/views/pages/funding-agreements/⚡index.blade.php`
- Modify: `resources/views/pages/funding-agreements/_overview.blade.php`
- Create: `tests/Feature/FundingAgreementRegisterTest.php`
- Modify: `tests/Feature/FundingAgreementTest.php`

**Interfaces:**
- Consumes (Phase 0): trait `FiltersFinanceRegister`; `DonorProposal` scopes `withMoney(?int $year, bool $yearOnly)` and `joinMoney(?int $year, bool $yearOnly)`; `AgreementMoney::columns()`, `::yearBounds(?int): ?array{0: string, 1: string}`, `::totalsSelect(): string`, `::line(object|array): array`, `::UNSCHEDULED_SQL`, `::OUTSTANDING_SQL`; `TransfersTracking` scope `missingNet()`; `App\Support\CsvExport`; components `x-funding.filters`, `x-funding.pills`, `x-funding.filter-row`, `x-funding.totals`, `x-funding.register`, `x-funding.register.th`, `x-funding.pager`; test helper `downloadedCsv()`.
- Consumes (Task 1): `x-funding.agreement-head`, `x-funding.agreement-row`, `x-funding.agreement-foot`.
- Produces: public properties `search`, `donor`, `currency`, `stage`, `flag`, `min`, `max`; computed `rows`, `totals`, `hasAnyAgreement`; actions `toggleFlag`, `exportCsv`; file name `funding-agreements-{Y-m-d}.csv`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/FundingAgreementRegisterTest.php`:

```php
<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Proposal;
use App\Models\TransfersTracking;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo('2026-06-15 09:00:00');
    $this->user = User::factory()->role('Financial')->create();
});

/**
 * The funding agreements page as the finance manager opens it.
 *
 * @param  array<string, string>  $query
 */
function agreementRegister(array $query = []): Testable
{
    return Livewire::actingAs(test()->user)->withQueryParams($query)->test('pages::funding-agreements.index');
}

/**
 * A tranche in the agreement's own currency.
 *
 * @param  array<string, mixed>  $attributes
 */
function registerTranche(DonorProposal $agreement, array $attributes): TransfersTracking
{
    return TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'amount_transfered_currency' => $agreement->currency,
        'amount_received_currency' => $agreement->currency,
        'transfer_date' => null,
        'receive_date' => null,
        'ar_status' => ArStatus::Pending,
        ...$attributes,
    ]);
}

/**
 * 1,000 USD: 400 received (390 net), 200 overdue, 100 upcoming, 50 in transit.
 */
function registerMixedAgreement(string $proposalName = 'Mixed Proposal'): DonorProposal
{
    $agreement = DonorProposal::factory()->create([
        'proposal_id' => Proposal::factory()->create(['name' => $proposalName])->id,
        'budget' => '1000.00',
        'currency' => Currency::Usd,
    ]);

    registerTranche($agreement, ['payment_amount' => '400.00', 'net_amount_received' => '390.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2026-05-15', 'transfer_date' => '2026-05-15', 'receive_date' => '2026-05-20']);
    registerTranche($agreement, ['payment_amount' => '200.00', 'scheduled_date' => '2026-06-05']);
    registerTranche($agreement, ['payment_amount' => '100.00', 'scheduled_date' => '2026-06-25']);
    registerTranche($agreement, ['payment_amount' => '50.00', 'scheduled_date' => '2026-06-14', 'transfer_date' => '2026-06-14']);

    return $agreement;
}

test('the page opens on the table with every money figure of an agreement in its own column', function () {
    $agreement = registerMixedAgreement();

    $page = agreementRegister()
        ->assertSet('view', 'table')
        ->assertSeeHtml('data-register')
        ->assertDontSeeHtml('data-calendar')
        ->assertSeeHtml('data-agreement-row="'.$agreement->id.'"')
        ->assertSee('1,000.00')
        ->assertSee('750.00')
        ->assertSee('390.00')
        ->assertSee('39%')
        ->assertSee('600.00');

    $row = $page->instance()->rows->first();

    expect((float) $row->money_scheduled)->toBe(750.0)
        ->and((float) $row->money_received)->toBe(390.0)
        ->and((float) $row->money_in_transit)->toBe(50.0)
        ->and((float) $row->money_overdue)->toBe(200.0)
        ->and((float) $row->money_upcoming)->toBe(100.0)
        ->and((float) $row->money_unscheduled)->toBe(250.0)
        ->and((float) $row->money_outstanding)->toBe(600.0)
        ->and((int) $row->money_tranches)->toBe(4)
        ->and($row->money_next_due)->toBe('2026-06-05');
});

test('the overview view shows the calendar instead of the table', function () {
    registerMixedAgreement();

    agreementRegister()
        ->set('view', 'overview')
        ->assertSeeHtml('data-calendar')
        ->assertDontSeeHtml('data-register');
});

test('totals are kept per currency and add up to the rows shown', function () {
    registerMixedAgreement();
    DonorProposal::factory()->create(['budget' => '500.00', 'currency' => Currency::Eur]);
    DonorProposal::factory()->create(['budget' => '2000.00', 'currency' => Currency::Usd]);

    $page = agreementRegister();
    $totals = $page->instance()->totals->keyBy('currency');

    expect($totals->keys()->all())->toBe(['EUR', 'USD'])
        ->and($totals['USD'])->toMatchArray([
            'agreements' => 2,
            'budget' => 3000.0,
            'scheduled' => 750.0,
            'received' => 390.0,
            'in_transit' => 50.0,
            'overdue' => 200.0,
            'unscheduled' => 2250.0,
            'outstanding' => 2600.0,
        ])
        ->and($totals['EUR']['budget'])->toBe(500.0);

    $page->assertSeeHtml('data-register-total="USD"')
        ->assertSeeHtml('data-register-total="EUR"')
        ->assertDontSee('3,500.00');

    // A status narrows the rows, and the totals follow the rows.
    $page->call('toggleStage', 'overdue');

    expect($page->instance()->totals->firstWhere('currency', 'USD')['budget'])->toBe(1000.0);
});

test('the flags and the budget range narrow the rows', function () {
    $mixed = registerMixedAgreement('Mixed Proposal');
    $empty = DonorProposal::factory()->create(['budget' => '90000.00', 'currency' => Currency::Usd]);
    $reporting = DonorProposal::factory()->create(['budget' => '300.00', 'currency' => Currency::Usd]);
    DonorReport::factory()->create(['donor_proposal_id' => $reporting->id, 'due_on' => '2026-06-01']);
    registerTranche($reporting, ['payment_amount' => '300.00', 'net_amount_received' => null, 'ar_status' => ArStatus::Received, 'scheduled_date' => '2026-05-01']);
    registerTranche($reporting, ['payment_amount' => '10.00', 'amount_transfered_currency' => Currency::Eur, 'scheduled_date' => '2026-07-01']);

    $ids = fn (Testable $page): array => $page->instance()->rows->pluck('id')->sort()->values()->all();

    expect($ids(agreementRegister()->call('toggleFlag', 'report_overdue')))->toBe([$reporting->id])
        ->and($ids(agreementRegister()->call('toggleFlag', 'net_missing')))->toBe([$reporting->id])
        ->and($ids(agreementRegister()->call('toggleFlag', 'other_currency')))->toBe([$reporting->id])
        ->and($ids(agreementRegister()->call('toggleFlag', 'no_tranches')))->toBe([$empty->id])
        ->and($ids(agreementRegister()->set('min', '50000')))->toBe([$empty->id])
        ->and($ids(agreementRegister()->set('max', '500')))->toBe([$reporting->id])
        ->and($ids(agreementRegister()->set('min', '500')->set('max', '5000')))->toBe([$mixed->id]);

    agreementRegister()->call('toggleFlag', 'no_tranches')->call('toggleFlag', 'no_tranches')->assertSet('flag', '');
});

test('choosing a year adds that year beside the lifetime figures and hides agreements with nothing in it', function () {
    $agreement = DonorProposal::factory()->create(['budget' => '1000.00', 'currency' => Currency::Usd]);
    registerTranche($agreement, ['payment_amount' => '300.00', 'net_amount_received' => '300.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2025-11-01', 'transfer_date' => '2025-12-20', 'receive_date' => '2026-01-10']);
    registerTranche($agreement, ['payment_amount' => '400.00', 'scheduled_date' => '2026-09-01']);

    $elsewhere = DonorProposal::factory()->create(['budget' => '500.00', 'currency' => Currency::Usd]);
    registerTranche($elsewhere, ['payment_amount' => '500.00', 'scheduled_date' => '2024-03-01']);

    $page = agreementRegister()->set('year', '2026');
    $row = $page->instance()->rows->sole();

    expect($row->id)->toBe($agreement->id)
        ->and((float) $row->money_scheduled)->toBe(700.0)
        ->and((float) $row->money_due_in_year)->toBe(400.0)
        ->and((float) $row->money_received_in_year)->toBe(300.0);

    $page->assertSee('Due in 2026')->assertSee('Received in 2026')->assertSee('Outstanding');

    // 2025: the tranche was due that year but landed in the next.
    $earlier = agreementRegister()->set('year', '2025')->instance()->rows->sole();

    expect((float) $earlier->money_due_in_year)->toBe(300.0)
        ->and((float) $earlier->money_received_in_year)->toBe(0.0);
});

test('year only recalculates every tranche figure for that year and drops the whole-agreement columns', function () {
    $agreement = DonorProposal::factory()->create(['budget' => '1000.00', 'currency' => Currency::Usd]);
    registerTranche($agreement, ['payment_amount' => '300.00', 'net_amount_received' => '300.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2025-11-01', 'transfer_date' => '2025-12-20', 'receive_date' => '2026-01-10']);
    registerTranche($agreement, ['payment_amount' => '400.00', 'scheduled_date' => '2026-09-01']);

    $page = agreementRegister()->set('year', '2026')->set('figures', 'year');
    $row = $page->instance()->rows->sole();

    expect((float) $row->money_scheduled)->toBe(400.0)
        ->and((float) $row->money_received)->toBe(0.0)
        ->and((int) $row->money_tranches)->toBe(1)
        ->and((float) $page->instance()->totals->sole()['scheduled'])->toBe(400.0);

    $page->assertSee('Budget (whole agreement)')
        ->assertDontSee('Outstanding')
        ->assertDontSee('Due in 2026');

    // The mode means nothing without a year.
    agreementRegister()->set('figures', 'year')->assertSet('figures', 'lifetime');
});

test('the table opens most urgent first and a heading sorts inside each currency', function () {
    $late = DonorProposal::factory()->create(['budget' => '100.00', 'currency' => Currency::Usd]);
    registerTranche($late, ['payment_amount' => '100.00', 'scheduled_date' => '2026-05-01']);
    $soon = DonorProposal::factory()->create(['budget' => '900.00', 'currency' => Currency::Usd]);
    registerTranche($soon, ['payment_amount' => '900.00', 'scheduled_date' => '2026-07-01']);
    $quiet = DonorProposal::factory()->create(['budget' => '500.00', 'currency' => Currency::Eur]);

    $ids = fn (Testable $page): array => $page->instance()->rows->pluck('id')->all();

    $page = agreementRegister();
    expect($ids($page))->toBe([$late->id, $soon->id, $quiet->id]);

    $page->call('sortRegister', 'budget');
    expect($ids($page))->toBe([$quiet->id, $soon->id, $late->id]);

    $page->call('sortRegister', 'budget')->assertSet('sortDir', 'asc');
    expect($ids($page))->toBe([$quiet->id, $late->id, $soon->id]);
});

test('values typed into the address bar fall back to the defaults', function () {
    registerMixedAgreement();

    agreementRegister([
        'view' => 'cards', 'stage' => 'lost', 'flag' => 'x', 'currency' => 'GBP', 'donor' => '1;2',
        'min' => 'lots', 'max' => '-1', 'year' => '20x6', 'figures' => 'decade', 'sortBy' => 'password', 'perPage' => '9999',
    ])
        ->assertSet('view', 'table')
        ->assertSet('stage', '')
        ->assertSet('flag', '')
        ->assertSet('currency', '')
        ->assertSet('donor', '')
        ->assertSet('min', '')
        ->assertSet('max', '')
        ->assertSet('year', '')
        ->assertSet('figures', 'lifetime')
        ->assertSet('sortBy', '')
        ->assertSet('perPage', '25')
        ->assertOk();
});

test('reset filters clears every filter and keeps the view and the sort', function () {
    registerMixedAgreement();

    agreementRegister()
        ->set('view', 'overview')
        ->call('sortRegister', 'budget')
        ->set('currency', 'USD')
        ->set('year', '2026')
        ->set('min', '10')
        ->call('toggleStage', 'overdue')
        ->call('toggleFlag', 'net_missing')
        ->call('clearFilters')
        ->assertSet('currency', '')
        ->assertSet('year', '')
        ->assertSet('min', '')
        ->assertSet('stage', '')
        ->assertSet('flag', '')
        ->assertSet('view', 'overview')
        ->assertSet('sortBy', 'budget');
});

test('a row opens its agreement from the eye button and links its donor and proposal', function () {
    $agreement = registerMixedAgreement();

    agreementRegister()
        ->assertSeeHtml('href="'.route('funding-agreements.show', $agreement).'"')
        ->assertSeeHtml('href="'.route('donors.show', $agreement->donor).'"')
        ->assertSeeHtml('href="'.route('proposals.show', $agreement->proposal).'"')
        ->assertSeeHtml('href="'.route('transfers.index', ['agreement' => $agreement->id]).'"');
});

test('a viewer without the update permission gets the table with no edit buttons', function () {
    $viewer = User::factory()->role('Managers')->create()->givePermissionTo('funding-agreements.view');
    $agreement = registerMixedAgreement();

    Livewire::actingAs($viewer)->test('pages::funding-agreements.index')
        ->assertSeeHtml('data-agreement-row="'.$agreement->id.'"')
        ->assertDontSeeHtml('data-test="edit-agreement-'.$agreement->id.'"')
        ->assertDontSeeHtml('data-test="create-agreement-button"');
});

test('the export holds every filtered agreement with plain numbers', function () {
    $donor = Donor::factory()->create(['name' => 'Alpha Fund']);
    $agreement = registerMixedAgreement('Water Response');
    $agreement->update(['donor_id' => $donor->id, 'project_num' => 'AF-01']);
    DonorProposal::factory()->count(30)->create(['currency' => Currency::Usd, 'budget' => '10.00']);
    DonorProposal::factory()->create(['currency' => Currency::Eur]);

    $page = agreementRegister()
        ->set('currency', 'USD')
        ->call('sortRegister', 'budget')
        ->call('exportCsv')
        ->assertFileDownloaded('funding-agreements-2026-06-15.csv');

    $csv = downloadedCsv($page);

    expect($csv)->toHaveCount(32)
        ->and($csv[0][0])->toBe('Donor')
        ->and($csv[1][0])->toBe('Alpha Fund')
        ->and($csv[1][1])->toBe('Water Response')
        ->and($csv[1][3])->toBe('AF-01')
        ->and($csv[1][4])->toBe('USD')
        ->and($csv[1][5])->toBe('1000.00')
        ->and($csv[1][6])->toBe('750.00')
        ->and($csv[1][7])->toBe('390.00')
        ->and($csv[1][8])->toBe('39')
        ->and($csv[1][13])->toBe('600.00')
        ->and($csv[1][14])->toBe('2026-06-05')
        ->and($csv[1][15])->toBe('4');
});

test('exporting needs the funding agreements view permission', function () {
    registerMixedAgreement();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::funding-agreements.index')
        ->call('exportCsv')
        ->assertForbidden();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `.\php.cmd artisan test --compact tests/Feature/FundingAgreementRegisterTest.php`
Expected: FAIL — `Property [$view] not found on component`.

- [ ] **Step 3: Extend the component class**

All edits are in the PHP block of `resources/views/pages/funding-agreements/⚡index.blade.php`.

**3a. Imports.** Replace the `use` list at the top of the file with:

```php
use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Enums\ProposalStatus;
use App\Livewire\Concerns\FiltersFinanceRegister;
use App\Livewire\Concerns\ManagesAgreementForm;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use App\Support\AgreementMoney;
use App\Support\CsvExport;
use App\Support\Like;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;
```

**3b. Traits, constants and properties.** Replace the lines from `use ManagesAgreementForm;` through the `clearFilters()` method (lines 21–66) with:

```php
    use FiltersFinanceRegister;
    use ManagesAgreementForm;
    use WithPagination;

    /**
     * Where an agreement's money can be stuck. Each one is a filter the page
     * can be narrowed to.
     *
     * @var list<string>
     */
    public const STAGES = ['overdue', 'failed', 'upcoming', 'unscheduled', 'in_transit', 'received'];

    /**
     * Conditions that are not about where the money is.
     *
     * @var list<string>
     */
    public const FLAGS = ['report_overdue', 'net_missing', 'other_currency', 'no_tranches'];

    /**
     * Every property a filter control writes.
     *
     * @var list<string>
     */
    private const FILTERS = ['search', 'donor', 'currency', 'stage', 'flag', 'min', 'max'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $donor = '';

    #[Url(except: '')]
    public string $currency = '';

    #[Url(except: '')]
    public string $stage = '';

    #[Url(except: '')]
    public string $flag = '';

    /** The smallest budget to show. */
    #[Url(except: '')]
    public string $min = '';

    /** The largest budget to show. */
    #[Url(except: '')]
    public string $max = '';

    /**
     * Everything here arrives from the URL. Normalise on the way in, so a
     * hand-typed value never leaves a filter applied with no control showing it.
     */
    public function mount(): void
    {
        $this->normaliseFilters();
    }

    public function updated(string $property): void
    {
        if (in_array($property, self::FILTERS, true)) {
            $this->normaliseFilters();
            $this->resetPage();
        }
    }

    protected function normaliseFilters(): void
    {
        if (! in_array($this->stage, self::STAGES, true)) {
            $this->stage = '';
        }

        if (! in_array($this->flag, self::FLAGS, true)) {
            $this->flag = '';
        }

        if (Currency::tryFrom($this->currency) === null) {
            $this->currency = '';
        }

        if (! ctype_digit($this->donor)) {
            $this->donor = '';
        }

        foreach (['min', 'max'] as $bound) {
            $value = $this->{$bound};
            $this->{$bound} = is_numeric($value) && (float) $value >= 0 && (float) $value <= self::MAX_BUDGET ? $value : '';
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'donor', 'currency', 'stage', 'flag', 'min', 'max']);
        $this->resetRegisterFilters();
        $this->resetPage();
    }
```

**3c. Flag toggle.** Directly after the existing `toggleStage()` method add:

```php
    /**
     * Narrow to one flag, or clear it by choosing it again.
     */
    public function toggleFlag(string $flag): void
    {
        $this->flag = $this->flag === $flag || ! in_array($flag, self::FLAGS, true) ? '' : $flag;
        $this->resetPage();
    }
```

**3d. The filtered query.** Replace the body of `filteredQuery()` (keep its doc block) with:

```php
    protected function filteredQuery(bool $withStage = true): Builder
    {
        $search = trim($this->search);

        return DonorProposal::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('project_num', 'like', Like::contains($search))
                        ->orWhereHas('donor', fn (Builder $query) => $query->where('name', 'like', Like::contains($search)))
                        ->orWhereHas('proposal', fn (Builder $query) => $query->where('name', 'like', Like::contains($search)));
                });
            })
            ->when($this->donor !== '', fn (Builder $query) => $query->where('donor_id', (int) $this->donor))
            ->when(Currency::tryFrom($this->currency), fn (Builder $query, Currency $currency) => $query->where('currency', $currency))
            ->when($this->min !== '', fn (Builder $query) => $query->where('donor_proposal.budget', '>=', (float) $this->min))
            ->when($this->max !== '', fn (Builder $query) => $query->where('donor_proposal.budget', '<=', (float) $this->max))
            ->when($this->flag !== '', fn (Builder $query) => $this->applyFlag($query, $this->flag))
            ->when($this->selectedYear() !== null, fn (Builder $query) => $this->applyYear($query))
            ->when($withStage && in_array($this->stage, self::STAGES, true), fn (Builder $query) => $this->applyStage($query, $this->stage));
    }
```

**3e. Flag and year conditions.** Directly after the existing `applyStage()` method add:

```php
    /**
     * Narrow a query to agreements carrying one flag.
     *
     * @param  Builder<DonorProposal>  $query
     * @return Builder<DonorProposal>
     */
    protected function applyFlag(Builder $query, string $flag): Builder
    {
        $today = CarbonImmutable::today()->toDateString();

        return match ($flag) {
            'report_overdue' => $query->whereHas('reports', fn (Builder $query) => $query->whereNull('submitted_on')->whereDate('due_on', '<', $today)),
            'net_missing' => $query->whereHas('transfers', fn (Builder $query) => $query->missingNet()),
            'other_currency' => $query->whereHas('transfers', fn (Builder $query) => $query->whereColumn('transfers_tracking.amount_transfered_currency', '<>', 'donor_proposal.currency')),
            'no_tranches' => $query->doesntHave('transfers'),
            default => $query,
        };
    }

    /**
     * Keep only agreements with money in the chosen year: a tranche due in it
     * or, while the lifetime figures are shown, one that landed in it.
     *
     * @param  Builder<DonorProposal>  $query
     * @return Builder<DonorProposal>
     */
    protected function applyYear(Builder $query): Builder
    {
        $bounds = AgreementMoney::yearBounds($this->selectedYear());
        $yearOnly = $this->yearOnly();

        return $query->whereHas('transfers', fn (Builder $query) => $query->where(fn (Builder $query) => $query
            ->whereBetween('scheduled_date', $bounds)
            ->when(! $yearOnly, fn (Builder $query) => $query->orWhere(fn (Builder $query) => $query
                ->where('ar_status', ArStatus::Received)
                ->whereBetween('receive_date', $bounds)))));
    }
```

**3f. The table's data.** Directly before the existing `openCreateModal()` method add:

```php
    /**
     * The filtered agreements joined to the records their rows name and to
     * their money, which is one grouped join rather than a loaded relation:
     * that is what lets a money column be sorted, totalled and exported.
     *
     * @return Builder<DonorProposal>
     */
    protected function registerQuery(): Builder
    {
        $today = CarbonImmutable::today()->toDateString();

        return $this->filteredQuery()
            ->join('donors', 'donors.id', '=', 'donor_proposal.donor_id')
            ->join('proposals', 'proposals.id', '=', 'donor_proposal.proposal_id')
            ->select('donor_proposal.*')
            ->withMoney($this->selectedYear(), $this->yearOnly())
            ->withCount(['reports as overdue_reports_count' => fn (Builder $query) => $query
                ->whereNull('submitted_on')
                ->whereDate('due_on', '<', $today)])
            ->withMin(['reports as next_report_due' => fn (Builder $query) => $query
                ->whereNull('submitted_on')
                ->whereDate('due_on', '>=', $today)], 'due_on');
    }

    /**
     * What each column heading sorts by. Amounts sort inside their currency.
     *
     * @return array<string, array{dir: 'asc'|'desc', order: list<string>}>
     */
    protected function registerSorts(): array
    {
        $lastById = 'donor_proposal.id desc';
        $money = fn (string $expression): array => ['dir' => 'desc', 'order' => ['donor_proposal.currency asc', "{$expression} {dir}", $lastById]];
        $sum = fn (string $column): string => "coalesce(money.{$column}, 0)";

        return [
            'agreement' => ['dir' => 'asc', 'order' => ['donors.name {dir}', 'proposals.name {dir}', $lastById]],
            'budget' => $money('donor_proposal.budget'),
            'scheduled' => $money($sum('m_scheduled')),
            'received' => $money($sum('m_received')),
            'percent' => ['dir' => 'desc', 'order' => ["(case when donor_proposal.budget > 0 then {$sum('m_received')} / donor_proposal.budget else 0 end) {dir}", $lastById]],
            'due_in_year' => $money($sum('m_due_in_year')),
            'received_in_year' => $money($sum('m_received_in_year')),
            'in_transit' => $money($sum('m_in_transit')),
            'overdue' => $money($sum('m_overdue')),
            'failed' => $money($sum('m_failed')),
            'unscheduled' => $money(AgreementMoney::UNSCHEDULED_SQL),
            'outstanding' => $money(AgreementMoney::OUTSTANDING_SQL),
            // The earliest unsent tranche leads, so anything overdue sits at the top.
            'next_due' => ['dir' => 'asc', 'order' => ['money.m_next_due is null', 'money.m_next_due {dir}', $lastById]],
            'tranches' => ['dir' => 'desc', 'order' => ["{$sum('m_tranches')} {dir}", $lastById]],
        ];
    }

    protected function registerDefaultSort(): string
    {
        return 'next_due';
    }

    /**
     * The table's visible page.
     *
     * @return LengthAwarePaginator<int, DonorProposal>
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        return $this->applyRegisterSort($this->registerQuery())
            ->with(['donor:id,slug,name', 'proposal:id,slug,name,status'])
            ->paginate($this->registerPerPage());
    }

    /**
     * The table's totals, one line per currency. They respect every filter,
     * status included, so they always add up to the rows shown.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function totals(): Collection
    {
        return $this->filteredQuery()
            ->joinMoney($this->selectedYear(), $this->yearOnly())
            ->toBase()
            ->selectRaw(AgreementMoney::totalsSelect())
            ->groupBy('donor_proposal.currency')
            ->orderBy('donor_proposal.currency')
            ->get()
            ->map(fn (object $row): array => AgreementMoney::line($row));
    }

    /**
     * Whether any agreement exists at all, so an empty page can tell "nothing
     * recorded yet" from "nothing matches".
     */
    #[Computed]
    public function hasAnyAgreement(): bool
    {
        return DonorProposal::query()->exists();
    }

    /**
     * Every agreement the filters match, in the table's order, as a CSV file.
     */
    public function exportCsv(): StreamedResponse
    {
        Gate::authorize('funding-agreements.view');

        $year = $this->selectedYear();

        $agreements = $this->applyRegisterSort($this->registerQuery())
            ->addSelect(['donors.name as donor_name', 'proposals.name as proposal_name', 'proposals.status as proposal_status'])
            ->cursor();

        $headings = [
            __('funding_agreements.donor'),
            __('common.proposal'),
            __('finance_tables.proposal_status'),
            __('finance_tables.reference'),
            __('common.currency'),
            __('finance_tables.budget'),
            __('finance_tables.scheduled'),
            __('finance_tables.received'),
            __('finance_tables.percent_received'),
            __('finance_tables.in_transit'),
            __('finance_tables.overdue'),
            __('finance_tables.failed'),
            __('finance_tables.not_scheduled'),
            __('finance_tables.outstanding'),
            __('finance_tables.next_due'),
            __('finance_tables.tranches'),
            __('finance_tables.reports_overdue'),
            __('finance_tables.next_report_due'),
            ...($year === null ? [] : [
                __('finance_tables.due_in_year', ['year' => $year]),
                __('finance_tables.received_in_year', ['year' => $year]),
            ]),
        ];

        $rows = (function () use ($agreements, $year) {
            foreach ($agreements as $agreement) {
                yield [
                    (string) $agreement->getAttribute('donor_name'),
                    (string) $agreement->getAttribute('proposal_name'),
                    ProposalStatus::from((string) $agreement->getAttribute('proposal_status'))->label(),
                    $agreement->project_num,
                    $agreement->currency->value,
                    CsvExport::amount($agreement->budget),
                    CsvExport::amount($agreement->money_scheduled),
                    CsvExport::amount($agreement->money_received),
                    DonorProposal::percentLabel((float) $agreement->money_received, (float) $agreement->budget),
                    CsvExport::amount($agreement->money_in_transit),
                    CsvExport::amount($agreement->money_overdue),
                    CsvExport::amount($agreement->money_failed),
                    CsvExport::amount($agreement->money_unscheduled),
                    CsvExport::amount($agreement->money_outstanding),
                    $agreement->money_next_due,
                    (int) $agreement->money_tranches,
                    (int) $agreement->overdue_reports_count,
                    $agreement->next_report_due,
                    ...($year === null ? [] : [
                        CsvExport::amount($agreement->money_due_in_year),
                        CsvExport::amount($agreement->money_received_in_year),
                    ]),
                ];
            }
        })();

        return CsvExport::download('funding-agreements-'.CarbonImmutable::today()->toDateString().'.csv', $headings, $rows);
    }
```

Everything else in the class (`toggleStage`, `applyStage`, `agreements`, `ledger`, `stageCounts`, `calendar`, `quarterKey`, `monthOffset`, `trancheState`, `percentLabel`, `agreementMoney`, the four form callbacks) stays exactly as it is.

- [ ] **Step 4: Replace the template**

Replace everything after the closing `?>` with:

```blade

@php
    $showsOverview = $this->view === 'overview';
    $filtersAreActive = collect([$this->search, $this->donor, $this->currency, $this->stage, $this->flag, $this->min, $this->max, $this->year])
        ->contains(fn (string $value): bool => $value !== '');
    $total = $showsOverview ? $this->agreements->total() : $this->rows->total();

    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $inkText = 'text-secondary-dark dark:text-ink';
    $hairline = 'border-secondary-soft dark:border-line';

    /* `$chosen` is read instead of the properties so a loop variable can never shadow one. */
    $chosen = [
        'year' => $this->year,
        'figures' => $this->figures,
        'currency' => $this->currency,
        'stage' => $this->stage,
        'flag' => $this->flag,
    ];
    $selectedYear = $this->selectedYear();
    $yearOnly = $this->yearOnly();
    $sortKey = $this->activeSort();
    $sortDirection = $this->activeSortDir();
    $counts = $this->hasAnyAgreement && ! $showsOverview ? $this->stageCounts : [];

    $setPill = fn (string $property, string $value, string $label): array => [
        'label' => $label,
        'click' => "\$set('{$property}', '".($chosen[$property] === $value ? '' : $value)."')",
        'active' => $chosen[$property] === $value,
    ];
    $stagePill = fn (string $value, string $label, ?string $tone = null): array => [
        'label' => $label,
        'click' => "toggleStage('{$value}')",
        'active' => $chosen['stage'] === $value,
        'count' => $counts[$value] ?? 0,
        'tone' => ($counts[$value] ?? 0) > 0 ? $tone : null,
        'test' => 'stage-'.$value,
    ];
    $flagPill = fn (string $value, string $label): array => [
        'label' => $label,
        'click' => "toggleFlag('{$value}')",
        'active' => $chosen['flag'] === $value,
        'test' => 'flag-'.$value,
    ];

    $yearPills = [
        ['label' => __('finance_tables.all_years'), 'click' => "\$set('year', '')", 'active' => $chosen['year'] === ''],
        ...array_map(fn (int $option): array => $setPill('year', (string) $option, (string) $option), $this->yearOptions),
    ];
    $figurePills = [
        ['label' => __('finance_tables.figures_lifetime'), 'click' => "\$set('figures', 'lifetime')", 'active' => $chosen['figures'] === 'lifetime'],
        ['label' => __('finance_tables.figures_year', ['year' => $chosen['year']]), 'click' => "\$set('figures', 'year')", 'active' => $chosen['figures'] === 'year'],
    ];
    $statusPills = [
        ['label' => __('finance_tables.all'), 'click' => "toggleStage('')", 'active' => $chosen['stage'] === '', 'test' => 'stage-all'],
        $stagePill('overdue', __('funding_agreements.stage_overdue'), 'critical'),
        $stagePill('failed', __('funding_agreements.stage_failed'), 'critical'),
        $stagePill('in_transit', __('funding_agreements.stage_in_transit'), 'warn'),
        $stagePill('upcoming', __('funding_agreements.stage_upcoming')),
        $stagePill('unscheduled', __('funding_agreements.stage_unscheduled')),
        $stagePill('received', __('funding_agreements.stage_received')),
    ];
    $currencyPills = [
        ['label' => __('finance_tables.all'), 'click' => "\$set('currency', '')", 'active' => $chosen['currency'] === ''],
        ...array_map(fn (Currency $case): array => $setPill('currency', $case->value, $case->value), Currency::cases()),
    ];
    $flagPills = [
        $flagPill('report_overdue', __('finance_tables.flag_report_overdue')),
        $flagPill('net_missing', __('finance_tables.flag_unrecorded')),
        $flagPill('other_currency', __('finance_tables.flag_other_currency')),
        $flagPill('no_tranches', __('finance_tables.flag_no_tranches')),
    ];
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-6 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" aria-labelledby="agreement-directory-heading">
    <x-ui.page-header
        heading-id="agreement-directory-heading"
        :title="__('common.funding_agreements')"
        :count="$total"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('funding_agreements.search_agreements')" :placeholder="__('common.reference_donor_or_proposal')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('funding_agreements.donor')" wire:model.live="donor" :value="$this->donor">
                <flux:select.option value="">{{ __('funding_agreements.all_donors') }}</flux:select.option>
                @foreach ($this->donorOptions as $donorOption)
                    <flux:select.option :value="$donorOption->id" :wire:key="'agr-donor-'.$donorOption->id">{{ $donorOption->name }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <x-ui.view-toggle
                    :label="__('finance_tables.view_label')"
                    wire-model="view"
                    :active="$this->view"
                    :options="[
                        ['key' => 'table', 'label' => __('finance_tables.view_table'), 'icon' => 'table-cells'],
                        ['key' => 'overview', 'label' => __('finance_tables.view_overview'), 'icon' => 'chart-bar'],
                    ]"
                />

                @if ($this->hasAnyAgreement)
                    <x-ui.button icon="arrow-down-tray" class="min-h-11 touch-manipulation sm:min-h-10" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv" data-test="export-csv">
                        {{ __('finance_tables.export_csv') }}
                    </x-ui.button>
                @endif

                @can('funding-agreements.create')
                <x-ui.button tone="primary" icon="plus" class="min-h-11 touch-manipulation sm:min-h-10" wire:click="openCreateModal" data-test="create-agreement-button">
                    {{ __('funding_agreements.record_agreement') }}
                </x-ui.button>
                @endcan
            </div>
        </x-slot:actions>

        @if ($this->hasAnyAgreement)
            <x-slot:summary>
                <x-funding.filters :active="$filtersAreActive">
                    <x-funding.pills :label="__('finance_tables.year')" :pills="$yearPills" />

                    @if ($selectedYear !== null)
                        <x-funding.pills :label="__('finance_tables.figures_label')" :pills="$figurePills" />
                    @endif

                    {{-- The Overview view narrows by stage with its own chips, under its ledger. --}}
                    @unless ($showsOverview)
                        <x-funding.pills :label="__('common.status')" :pills="$statusPills" />
                    @endunless

                    <x-funding.pills :label="__('common.currency')" :pills="$currencyPills" />
                    <x-funding.pills :label="__('finance_tables.flags')" :pills="$flagPills" />

                    <x-funding.filter-row :label="__('finance_tables.budget')">
                        <x-ui.input type="number" inputmode="decimal" min="0" step="0.01" size="sm" class="w-36" wire:model.live.debounce.400ms="min" :placeholder="__('finance_tables.minimum')" :aria-label="__('finance_tables.minimum')" />
                        <span class="text-xs font-semibold {{ $mutedText }}">{{ __('finance_tables.to') }}</span>
                        <x-ui.input type="number" inputmode="decimal" min="0" step="0.01" size="sm" class="w-36" wire:model.live.debounce.400ms="max" :placeholder="__('finance_tables.maximum')" :aria-label="__('finance_tables.maximum')" />
                    </x-funding.filter-row>
                </x-funding.filters>
            </x-slot:summary>
        @endif
    </x-ui.page-header>

    @if (! $this->hasAnyAgreement)
        <x-ui.empty-state icon="banknotes" :heading="__('common.no_agreements_yet')" :body="__('funding_agreements.record_the_first_funding_agreement')" />
    @elseif ($showsOverview)
        @include('pages.funding-agreements._overview')
    @elseif ($this->rows->isEmpty())
        <x-ui.empty-state icon="banknotes" :heading="__('funding_agreements.no_matching_agreements')" :body="__('common.change_the_search_or_filters_and_try_again')" />
    @else
        @php
            $totals = $this->totals;
            // A column nobody has money in is noise: failed money earns its column only when there is some.
            $showFailed = $totals->sum('failed') > 0 || $chosen['stage'] === 'failed';
            $columns = AgreementMoney::columns(year: $selectedYear, yearOnly: $yearOnly, withFailed: $showFailed);

            $totalLines = $totals->map(fn (array $line): array => [
                'currency' => $line['currency'],
                'caption' => trans_choice('common.count_agreement', $line['agreements'], ['count' => $line['agreements']]),
                'figures' => array_values(array_filter([
                    ['key' => 'budget', 'label' => $yearOnly ? __('finance_tables.budget_whole_agreement') : __('finance_tables.budget'), 'value' => $line['budget']],
                    ['key' => 'scheduled', 'label' => __('finance_tables.scheduled'), 'value' => $line['scheduled']],
                    ['key' => 'received', 'label' => __('finance_tables.received'), 'value' => $line['received'], 'tone' => 'good', 'note' => DonorProposal::percentLabel($line['received'], $yearOnly ? $line['scheduled'] : $line['budget']).'%'],
                    $selectedYear !== null && ! $yearOnly ? ['key' => 'due_in_year', 'label' => __('finance_tables.due_in_year', ['year' => $selectedYear]), 'value' => $line['due_in_year']] : null,
                    $selectedYear !== null && ! $yearOnly ? ['key' => 'received_in_year', 'label' => __('finance_tables.received_in_year', ['year' => $selectedYear]), 'value' => $line['received_in_year'], 'tone' => 'good'] : null,
                    ['key' => 'in_transit', 'label' => __('finance_tables.in_transit'), 'value' => $line['in_transit'], 'tone' => $line['in_transit'] > 0 ? 'warn' : null],
                    ['key' => 'overdue', 'label' => __('finance_tables.overdue'), 'value' => $line['overdue'], 'tone' => $line['overdue'] > 0 ? 'critical' : null],
                    $line['failed'] > 0 ? ['key' => 'failed', 'label' => __('finance_tables.failed'), 'value' => $line['failed'], 'tone' => 'critical'] : null,
                    $yearOnly ? null : ['key' => 'unscheduled', 'label' => __('finance_tables.not_scheduled'), 'value' => $line['unscheduled'], 'tone' => 'muted'],
                    $yearOnly ? null : ['key' => 'outstanding', 'label' => __('finance_tables.outstanding'), 'value' => $line['outstanding']],
                ])),
            ])->all();
        @endphp

        <x-funding.totals :lines="$totalLines" />

        <x-funding.register size="2xl" :caption="__('finance_tables.agreements_caption')">
            <x-slot:head>
                <x-funding.register.th sort="agreement" :active="$sortKey" :dir="$sortDirection">{{ __('finance_tables.agreement') }}</x-funding.register.th>
                <x-funding.agreement-head :columns="$columns" sortable :active="$sortKey" :dir="$sortDirection" />
            </x-slot:head>

            @foreach ($this->rows as $agreement)
                @php
                    $agreementName = $agreement->donor->name.' · '.$agreement->proposal->name;
                @endphp
                <x-funding.agreement-row :agreement="$agreement" :columns="$columns" :year-only="$yearOnly">
                    <x-slot:lead>
                        <td data-label="{{ __('finance_tables.agreement') }}" data-wide>
                            @can('donors.view')
                                <a href="{{ route('donors.show', $agreement->donor) }}" wire:navigate class="font-semibold underline-offset-2 hover:underline">{{ $agreement->donor->name }}</a>
                            @else
                                <span class="font-semibold">{{ $agreement->donor->name }}</span>
                            @endcan
                            <span class="sub">
                                @can('proposals.view')
                                    <a href="{{ route('proposals.show', $agreement->proposal) }}" wire:navigate class="underline-offset-2 hover:underline">{{ $agreement->proposal->name }}</a>
                                @else
                                    {{ $agreement->proposal->name }}
                                @endcan
                                · {{ $agreement->proposal->status->label() }}
                            </span>
                            <span class="sub">{{ $agreement->project_num ?? __('common.no_donor_reference') }}</span>
                        </td>
                    </x-slot:lead>

                    <x-slot:actions>
                        {{-- No delete here: an agreement is deleted from its edit form, and only while nothing is filed under it. --}}
                        <td>
                            <span class="flex items-center justify-end">
                                <x-ui.button tone="ghost" size="sm" icon="eye" class="size-8!" :href="route('funding-agreements.show', $agreement)" wire:navigate :aria-label="__('common.view_name', ['name' => $agreementName])" />
                                @can('funding-agreements.update')
                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-8!" wire:click="openEditModal({{ $agreement->id }})" :aria-label="__('common.edit_name', ['name' => $agreementName])" data-test="edit-agreement-{{ $agreement->id }}" />
                                @endcan
                            </span>
                        </td>
                    </x-slot:actions>
                </x-funding.agreement-row>
            @endforeach

            <x-slot:foot>
                <x-funding.agreement-foot :lines="$totals->all()" :columns="$columns" :lead="1" :year-only="$yearOnly" />
            </x-slot:foot>
        </x-funding.register>

        <x-funding.pager :paginator="$this->rows" :per-page="$this->perPage" scroll-to="#agreement-directory-heading" />
    @endif

    <x-funding.agreement-modal
        :open="$showAgreementModal"
        :editing="$editingAgreementId !== null"
        :donor-options="$showAgreementModal ? $this->donorOptions : []"
        :warning="$showAgreementModal ? $this->agreementProposalWarning : null"
        :delete-blocker="$showAgreementModal ? $this->agreementDeleteBlocker : null"
        :locked-currency="$showAgreementModal && $this->agreementCurrencyLocked ? $agreementCurrency : null"
        :confirming-delete="$confirmingAgreementDelete"
        :proposal-options="$showAgreementModal ? $this->proposalOptions : []"
    />
</section>
```

Before saving, compare the `<x-funding.agreement-modal … />` tag above with the one that was at the bottom of the file (lines 1342–1351) and keep the original's attributes if they differ: the modal is out of scope and must not change.

- [ ] **Step 5: Drop the duplicate Reset button from the Overview chips**

The header now carries `Reset filters` for both views. In `resources/views/pages/funding-agreements/_overview.blade.php`, inside the `<nav … data-stage-chips>` element, delete this block:

```blade

                @if ($filtersAreActive)
                    <flux:button size="sm" variant="ghost" class="ms-auto text-secondary-medium! dark:text-ink-muted!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
                @endif
```

- [ ] **Step 6: Run the new tests**

Run: `.\php.cmd artisan test --compact tests/Feature/FundingAgreementRegisterTest.php`
Expected: PASS, 13 tests.

Common failures and their cause:
- `Column 'id' in where clause is ambiguous` — an unqualified `id` reached the joined query. `applyStage()` and the search do not use one; check anything you added.
- `Unknown column 'money.m_…'` in `totals` — `joinMoney()` was not applied before `toBase()`.
- The default-order test fails with the EUR agreement first — `next_due` must put `money.m_next_due is null` before the date.

- [ ] **Step 7: Point four old visual assertions at the Overview view**

In `tests/Feature/FundingAgreementTest.php` insert `->set('view', 'overview')` on the line directly after `->test('pages::funding-agreements.index')` in these tests only:

| Test name | The assertion that needs the Overview view |
| --- | --- |
| `the collection ledger splits each currency from committed to net without mixing currencies` | `assertSeeText('Cash-flow calendar')` |
| `the cash-flow calendar pads an empty schedule around today` | `assertSeeText('No tranches scheduled yet')` |
| `failed transfers are their own stage, apart from upcoming and in transit` | `assertSeeHtml('data-ledger-figure="failed"')` |
| `a received tranche with no net recorded counts as nothing landed on both pages and is flagged` | `assertSeeHtml('data-ledger-net-missing')` |

In the third, the component is built as `$component = Livewire::actingAs($user)->test('pages::funding-agreements.index');` — change that line to:

```php
    $component = Livewire::actingAs($user)->test('pages::funding-agreements.index')->set('view', 'overview');
```

In the fourth, change the `$index = …` chain to:

```php
    $index = Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->set('view', 'overview')
        ->assertSeeHtml('data-ledger-net-missing');
```

Every other test in the file passes unchanged: the ones that filter and then assert a proposal name work on the table rows, and `the index flags agreements with an overdue donor report` finds `data-report-overdue` and "1 donor report overdue" in the table's Donor reports column.

- [ ] **Step 8: Run every test that touches this page**

Run: `.\php.cmd artisan test --compact tests/Feature/FundingAgreementTest.php tests/Feature/FundingAgreementRegisterTest.php tests/Feature/AgreementComponentsTest.php tests/Feature/FundingWorkflowTest.php tests/Feature/LocalizationTest.php`
Expected: PASS.

- [ ] **Step 9: Checkpoint**

Run: `.\php.cmd vendor/bin/pint --dirty --format agent`. Do not commit.

---

### Task 4: Look at it

**Files:** none changed unless a defect is found.

- [ ] **Step 1: Render the page** as in Phase 1, Task 5 (`.ai/rules/pages.md` → "Preview an auth-gated page in a browser without signing in") for `/funding-agreements`, `/funding-agreements?year=2026`, `/funding-agreements?year=2026&figures=year`, `/funding-agreements?view=overview`.

- [ ] **Step 2: Check each of these and fix what fails**

| Check | Expected |
| --- | --- |
| Wide window | Every column fits; header row sticks under the app bar. |
| Laptop width | Table scrolls sideways inside itself with the Agreement column pinned; the page does not scroll sideways. |
| A year chosen | Two extra columns, "Due in 2026" and "Received in 2026", after "% received". |
| Year only | Heading reads "Budget (whole agreement)"; "Not scheduled" and "Outstanding" are gone. |
| Overdue money | Critical colour in the Overdue column and on a past "Next due" date. |
| Footer | One row per currency; figures sit under their columns. |
| Overview view | Identical to the page before this phase, minus the Reset button in the chips row. |
| Dark mode, Arabic, 390px | As in Phase 1. |

- [ ] **Step 3: Delete the preview file and checkpoint.** Do not commit.
