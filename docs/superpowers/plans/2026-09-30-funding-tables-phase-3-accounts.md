# Funding tables — Phase 3: Accounts register

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Accounts page opens on a table with one row per bank account and what landed in it (received, in transit, upcoming, overdue, last receipt), filterable by year, state, currency, country and flags, sortable, totalled per currency and exportable. The eye button on a row opens the Transfers table filtered to that account, which is the account's statement. Today's tiles, donut and cards stay behind the `Table | Overview` switch.

**Architecture:** A new `App\Support\AccountMoney` builds one grouped query of tranche money per account, counted in the account's own currency. The page joins it for the table, its totals and its export, so a money column sorts in SQL. The page's existing filters move into a shared `filteredQuery()` that both views build on; flags and the year are `whereHas` conditions, so the Overview view needs no join.

**Tech Stack:** Laravel 13, Livewire 4 single-file page components, Flux (lite), Tailwind 4, Pest 5, MySQL.

**Depends on:** Phase 0 done. Phase 1 done (the Transfers page accepts `?account={id}`).

**Spec:** `docs/superpowers/specs/2026-09-30-funding-finance-tables-design.md`

## Global Constraints

- PHP runs through `.\php.cmd` (PHP 8.5 at `C:\php85`), never bare `php`. From the Bash tool use `/c/php85/php.exe`.
- **Do not commit.** Leave every change in the working tree for the user's review.
- Never run the whole test suite. Run only the files each step names.
- Run `.\php.cmd vendor/bin/pint --dirty --format agent` after changing any PHP file.
- No new Composer or npm package. No migration.
- Never use the single-line `@php(...)` shorthand in Blade.
- Every visible string goes through a lang key present in both `lang/en` and `lang/ar`. This phase adds no keys.
- Dark mode: only Graphite tokens behind `dark:`. Logical Tailwind classes only.
- Currencies are never added together. An account's figures count only money in the account's own currency; money that landed in another currency is counted apart and named, never converted.
- `accounts.is_active` decides whether an account is open — never `closed_at` (`.ai/rules/models.md`).
- Full account numbers are shown, searched and exported only for people who hold `accounts.update` (`showsFullAccountNumbers`); everyone else gets `maskedAccountNumber()`.
- Accounts are never deleted. There is no delete action anywhere on this page.
- No existing test is deleted.
- Line numbers quoted below are for commit `99c95eb`.

## File Structure

| File | Responsibility |
| --- | --- |
| Create `app/Support/AccountMoney.php` | The SQL for an account's tranche money. |
| Create `resources/views/pages/admin/_accounts-overview.blade.php` | Today's tiles, donut and cards, moved unchanged. |
| Modify `resources/views/pages/admin/⚡accounts.blade.php` | New filters, table data, template. |
| Create `tests/Feature/AccountMoneyTest.php` | The money query. |
| Create `tests/Feature/Admin/AccountRegisterTest.php` | The table. |

---

### Task 1: The account money query

**Files:**
- Create: `app/Support/AccountMoney.php`
- Test: `tests/Feature/AccountMoneyTest.php`

**Interfaces:**
- Consumes (Phase 0): `TransfersTracking::stateSql(string): string`, `App\Support\AgreementMoney::yearBounds(?int): ?array{0: string, 1: string}`.
- Produces:
  - `AccountMoney::perAccount(?int $year = null, bool $yearOnly = false): Illuminate\Database\Query\Builder` — one row per `account_id` with `a_transfers`, `a_received`, `a_in_transit`, `a_upcoming`, `a_overdue`, `a_other_currency`, `a_last_receipt`, `a_received_in_year`, `a_in_year`.
  - `AccountMoney::select(): string` — the `money_*` select list for a query that joined `perAccount()` as `money`: `money_transfers`, `money_received`, `money_in_transit`, `money_upcoming`, `money_overdue`, `money_other_currency`, `money_last_receipt`, `money_received_in_year`.
  - `AccountMoney::totalsSelect(): string` — the grouped totals list: `currency`, `accounts`, `open_accounts`, `transfers`, `received`, `in_transit`, `upcoming`, `overdue`, `received_in_year`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/AccountMoneyTest.php`:

```php
<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\Account;
use App\Models\TransfersTracking;
use App\Support\AccountMoney;

beforeEach(function () {
    $this->travelTo('2026-06-15 09:00:00');
});

/**
 * A USD account with one tranche in each state, plus one that landed in EUR.
 */
function moneyAccount(): Account
{
    $account = Account::factory()->create(['account_name' => 'Operations USD', 'currency' => Currency::Usd]);

    $tranche = fn (array $attributes): TransfersTracking => TransfersTracking::factory()->create([
        'account_id' => $account->id,
        'amount_transfered_currency' => Currency::Usd,
        'amount_received_currency' => Currency::Usd,
        'transfer_date' => null,
        'receive_date' => null,
        'ar_status' => ArStatus::Pending,
        ...$attributes,
    ]);

    $tranche(['payment_amount' => '1000.00', 'net_amount_received' => '985.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2026-05-15', 'transfer_date' => '2026-05-16', 'receive_date' => '2026-05-20']);
    $tranche(['payment_amount' => '200.00', 'scheduled_date' => '2026-06-05']);
    $tranche(['payment_amount' => '100.00', 'scheduled_date' => '2026-06-25']);
    $tranche(['payment_amount' => '50.00', 'scheduled_date' => '2026-06-14', 'transfer_date' => '2026-06-14']);
    $tranche(['payment_amount' => '700.00', 'net_amount_received' => '640.00', 'amount_received_currency' => Currency::Eur, 'ar_status' => ArStatus::Received, 'scheduled_date' => '2025-11-01', 'transfer_date' => '2025-11-02', 'receive_date' => '2025-11-10']);

    return $account;
}

/**
 * One account's joined money row.
 */
function accountMoneyRow(Account $account, ?int $year = null, bool $yearOnly = false): Account
{
    return Account::query()
        ->leftJoinSub(AccountMoney::perAccount($year, $yearOnly), 'money', 'money.account_id', '=', 'accounts.id')
        ->select('accounts.id')
        ->selectRaw(AccountMoney::select())
        ->findOrFail($account->id);
}

test('an account counts what landed, is moving, is due and is late in its own currency only', function () {
    $row = accountMoneyRow(moneyAccount());

    expect((int) $row->money_transfers)->toBe(5)
        ->and((float) $row->money_received)->toBe(985.0)
        ->and((float) $row->money_in_transit)->toBe(50.0)
        ->and((float) $row->money_upcoming)->toBe(100.0)
        ->and((float) $row->money_overdue)->toBe(200.0)
        ->and((int) $row->money_other_currency)->toBe(1)
        ->and($row->money_last_receipt)->toBe('2026-05-20');
});

test('an account with no tranche reads zero, not null', function () {
    $row = accountMoneyRow(Account::factory()->create());

    expect((int) $row->money_transfers)->toBe(0)
        ->and((float) $row->money_received)->toBe(0.0)
        ->and($row->money_last_receipt)->toBeNull();
});

test('a year adds what landed that year, and year only keeps just that year\'s tranches', function () {
    $account = moneyAccount();

    $beside = accountMoneyRow($account, 2026);
    $only = accountMoneyRow($account, 2026, yearOnly: true);
    $earlier = accountMoneyRow($account, 2025, yearOnly: true);

    expect((float) $beside->money_received_in_year)->toBe(985.0)
        ->and((int) $beside->money_transfers)->toBe(5)
        ->and((int) $only->money_transfers)->toBe(4)
        ->and((float) $only->money_received)->toBe(985.0)
        ->and((int) $earlier->money_transfers)->toBe(1)
        ->and((float) $earlier->money_received)->toBe(0.0)
        ->and((int) $earlier->money_other_currency)->toBe(1);
});

test('totals group by the account currency', function () {
    moneyAccount();
    Account::factory()->create(['currency' => Currency::Usd, 'is_active' => false, 'closed_at' => '2026-01-31']);
    Account::factory()->create(['currency' => Currency::Eur]);

    $totals = Account::query()
        ->leftJoinSub(AccountMoney::perAccount(), 'money', 'money.account_id', '=', 'accounts.id')
        ->toBase()
        ->selectRaw(AccountMoney::totalsSelect())
        ->groupBy('accounts.currency')
        ->orderBy('accounts.currency')
        ->get()
        ->keyBy('currency');

    expect($totals->keys()->all())->toBe(['EUR', 'USD'])
        ->and((int) $totals['USD']->accounts)->toBe(2)
        ->and((int) $totals['USD']->open_accounts)->toBe(1)
        ->and((float) $totals['USD']->received)->toBe(985.0)
        ->and((float) $totals['EUR']->received)->toBe(0.0);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `.\php.cmd artisan test --compact tests/Feature/AccountMoneyTest.php`
Expected: FAIL — `Class "App\Support\AccountMoney" not found`.

- [ ] **Step 3: Write the class**

Run: `.\php.cmd artisan make:class Support/AccountMoney --no-interaction`

Replace the generated file `app/Support/AccountMoney.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\TransfersTracking;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What passed through each bank account, as SQL.
 *
 * Every figure counts only money in the account's own currency: an amount in
 * another currency cannot be set beside it without a rate this system does not
 * hold, so it is counted apart (`a_other_currency`) and never converted.
 *
 * The accounts table joins perAccount() for its rows, its totals and its
 * export, so a figure that is sorted, summed or exported is always the figure
 * the row prints.
 */
final class AccountMoney
{
    /**
     * One row per account that has a tranche, with its money.
     *
     * With a year, `a_received_in_year` is what landed in that year and
     * `a_in_year` counts the tranches due or landed in it. With $yearOnly,
     * only tranches scheduled in that year are read at all.
     */
    public static function perAccount(?int $year = null, bool $yearOnly = false): Builder
    {
        $table = 'transfers_tracking';
        $sentHere = "{$table}.amount_transfered_currency = account.currency";
        $landedHere = '('.TransfersTracking::stateSql('received')." and {$table}.net_amount_received is not null and {$table}.amount_received_currency = account.currency)";
        $landedElsewhere = '('.TransfersTracking::stateSql('received')." and {$table}.net_amount_received is not null and {$table}.amount_received_currency <> account.currency)";

        $sum = fn (string $condition, string $column): string => "coalesce(sum(case when {$condition} then {$column} else 0 end), 0)";
        $count = fn (string $condition): string => "coalesce(sum(case when {$condition} then 1 else 0 end), 0)";
        $sent = fn (string $state): string => $sum("({$sentHere} and ".TransfersTracking::stateSql($state).')', "{$table}.payment_amount");

        $columns = [
            "{$table}.account_id",
            'count(*) as a_transfers',
            $sum($landedHere, "{$table}.net_amount_received").' as a_received',
            $sent('transit').' as a_in_transit',
            $sent('upcoming').' as a_upcoming',
            $sent('late').' as a_overdue',
            $count($landedElsewhere).' as a_other_currency',
            'max(case when '.TransfersTracking::stateSql('received')." then {$table}.receive_date end) as a_last_receipt",
        ];

        $bounds = AgreementMoney::yearBounds($year);

        if ($bounds === null) {
            $columns[] = '0 as a_received_in_year';
            $columns[] = '0 as a_in_year';
        } else {
            [$from, $to] = $bounds;
            $receivedInYear = "({$landedHere} and {$table}.receive_date between '{$from}' and '{$to}')";
            $inYear = "({$table}.scheduled_date between '{$from}' and '{$to}' or (".TransfersTracking::stateSql('received')." and {$table}.receive_date between '{$from}' and '{$to}'))";

            $columns[] = $sum($receivedInYear, "{$table}.net_amount_received").' as a_received_in_year';
            $columns[] = $count($inYear).' as a_in_year';
        }

        return DB::table($table)
            ->join('accounts as account', 'account.id', '=', "{$table}.account_id")
            ->when(
                $bounds !== null && $yearOnly,
                fn (Builder $query) => $query->whereRaw("{$table}.scheduled_date between '{$bounds[0]}' and '{$bounds[1]}'"),
            )
            ->selectRaw(implode(', ', $columns))
            ->groupBy("{$table}.account_id");
    }

    /**
     * The money columns of a query that joined perAccount() as `money`.
     */
    public static function select(): string
    {
        return implode(', ', [
            'coalesce(money.a_transfers, 0) as money_transfers',
            'coalesce(money.a_received, 0) as money_received',
            'coalesce(money.a_in_transit, 0) as money_in_transit',
            'coalesce(money.a_upcoming, 0) as money_upcoming',
            'coalesce(money.a_overdue, 0) as money_overdue',
            'coalesce(money.a_other_currency, 0) as money_other_currency',
            'money.a_last_receipt as money_last_receipt',
            'coalesce(money.a_received_in_year, 0) as money_received_in_year',
        ]);
    }

    /**
     * One totals line per account currency, for a query that joined
     * perAccount() as `money` and groups by accounts.currency.
     */
    public static function totalsSelect(): string
    {
        return implode(', ', [
            'accounts.currency as currency',
            'count(*) as accounts',
            'coalesce(sum(case when accounts.is_active = 1 then 1 else 0 end), 0) as open_accounts',
            'coalesce(sum(money.a_transfers), 0) as transfers',
            'coalesce(sum(money.a_received), 0) as received',
            'coalesce(sum(money.a_in_transit), 0) as in_transit',
            'coalesce(sum(money.a_upcoming), 0) as upcoming',
            'coalesce(sum(money.a_overdue), 0) as overdue',
            'coalesce(sum(money.a_received_in_year), 0) as received_in_year',
        ]);
    }
}
```

The year bounds are interpolated, not bound: they come from `AgreementMoney::yearBounds()`, which builds them from an integer the register trait has already validated — the same approach `TransfersTracking::stateSql()` takes with today's date.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `.\php.cmd artisan test --compact tests/Feature/AccountMoneyTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Checkpoint**

Run: `.\php.cmd vendor/bin/pint --dirty --format agent`. Do not commit.

---

### Task 2: Move today's tiles, donut and cards into a partial

A pure move: the page looks and behaves exactly as before when this task ends.

**Files:**
- Create: `resources/views/pages/admin/_accounts-overview.blade.php`
- Modify: `resources/views/pages/admin/⚡accounts.blade.php`

- [ ] **Step 1: Confirm the block boundaries**

```bash
cd /c/xampp/htdocs/hc-tracking && sed -n '361p;381p;414p;415p;462p;463p;466p;565p' "resources/views/pages/admin/⚡accounts.blade.php"
```

Expected output, exactly:

```
@php
@endphp
        <x-slot:summary>
            <div class="grid grid-cols-1 gap-4 @2xl/directory:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
            </div>
        </x-slot:summary>
    @if ($this->accounts->isEmpty())
    @endif
```

- [ ] **Step 2: Build the partial**

Three pieces, in this order: the variables the visual needs (lines 361–381), the summary band (415–462), and the card grid with its pagination (466–565).

```bash
cd /c/xampp/htdocs/hc-tracking && {
  printf '%s\n' '{{--' \
    '    The Overview view of the accounts page: the summary tiles, the state' \
    '    donut, the funds received per currency and the account cards. Included' \
    '    by ⚡accounts.blade.php; reads $this, $search, $state and $currencyFilter.' \
    '--}}'
  sed -n '361,381p' "resources/views/pages/admin/⚡accounts.blade.php"
  printf '\n'
  sed -n '415,462p' "resources/views/pages/admin/⚡accounts.blade.php"
  printf '\n'
  sed -n '466,565p' "resources/views/pages/admin/⚡accounts.blade.php"
} > resources/views/pages/admin/_accounts-overview.blade.php
```

The file name carries the page's name because this directory holds the partials of every admin page.

- [ ] **Step 3: Check the partial imports nothing**

```bash
cd /c/xampp/htdocs/hc-tracking && grep -n -E "(^|[^\\\\A-Za-z])(ArStatus|Currency|CarbonImmutable|Number|TransfersTracking|Account|Collection)::" resources/views/pages/admin/_accounts-overview.blade.php
```

Expected: no output (the one date parse in the cards is already written `\Carbon\CarbonImmutable::parse`).

- [ ] **Step 4: Replace the blocks in the page**

In `resources/views/pages/admin/⚡accounts.blade.php`, working from the bottom up so the line numbers above stay valid:

1. Delete lines 466–565 (from `@if ($this->accounts->isEmpty())` through its `@endif`) and put this single line in their place:

```blade
    @include('pages.admin._accounts-overview')
```

2. Delete lines 414–463 (the whole `<x-slot:summary> … </x-slot:summary>`).
3. Delete lines 361–381 (the `@php … @endphp` block).

The band of tiles used to sit inside the header's `summary` slot and now sits directly under the header. That is the only visual difference, and it is one line of vertical rhythm: the section is a `flex-col gap-6`, the same spacing the slot had.

- [ ] **Step 5: Prove nothing else changed**

Run: `.\php.cmd artisan test --compact tests/Feature/Admin/AccountDirectoryTest.php tests/Feature/AccessControl/FinanceAuthorizationTest.php`
Expected: PASS, the same counts as before (10 and 6 tests).

---

### Task 3: The register — class, template and tests

**Files:**
- Modify: `resources/views/pages/admin/⚡accounts.blade.php`
- Create: `tests/Feature/Admin/AccountRegisterTest.php`

**Interfaces:**
- Consumes (Phase 0): trait `FiltersFinanceRegister`; `TransfersTracking` scope `inState(string)`; `AgreementMoney::yearBounds()`; `App\Support\CsvExport`; components `x-funding.filters`, `x-funding.pills`, `x-funding.totals`, `x-funding.register`, `x-funding.register.th`, `x-funding.pager`; test helper `downloadedCsv()`.
- Consumes (Task 1): `AccountMoney`.
- Produces: public properties `search`, `state`, `currencyFilter`, `countryFilter` (URL key `country`), `flag`; computed `rows`, `totals`, `countryOptions`, `hasAnyAccount`; actions `toggleFlag`, `exportCsv`; file name `accounts-{Y-m-d}.csv`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Admin/AccountRegisterTest.php`:

```php
<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\Account;
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
 * The accounts page as the finance manager opens it.
 *
 * @param  array<string, string>  $query
 */
function accountRegister(array $query = []): Testable
{
    return Livewire::actingAs(test()->user)->withQueryParams($query)->test('pages::admin.accounts');
}

/**
 * A tranche into an account, in the account's own currency unless told otherwise.
 *
 * @param  array<string, mixed>  $attributes
 */
function accountTranche(Account $account, array $attributes): TransfersTracking
{
    return TransfersTracking::factory()->create([
        'account_id' => $account->id,
        'amount_transfered_currency' => $account->currency,
        'amount_received_currency' => $account->currency,
        'transfer_date' => null,
        'receive_date' => null,
        'ar_status' => ArStatus::Pending,
        ...$attributes,
    ]);
}

/**
 * Operations USD at Fransbank, Lebanon: 985 landed, 50 in transit, 100 upcoming, 200 overdue.
 */
function registerBusyAccount(): Account
{
    $account = Account::factory()->create([
        'account_name' => 'Operations USD',
        'bank_name' => 'Fransbank',
        'currency' => Currency::Usd,
        'country' => 'Lebanon',
        'account_number' => '030/02/353/2531267/1/3',
        'opened_at' => '2024-03-15',
    ]);

    accountTranche($account, ['payment_amount' => '1000.00', 'net_amount_received' => '985.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2026-05-15', 'transfer_date' => '2026-05-16', 'receive_date' => '2026-05-20']);
    accountTranche($account, ['payment_amount' => '200.00', 'scheduled_date' => '2026-06-05']);
    accountTranche($account, ['payment_amount' => '100.00', 'scheduled_date' => '2026-06-25']);
    accountTranche($account, ['payment_amount' => '50.00', 'scheduled_date' => '2026-06-14', 'transfer_date' => '2026-06-14']);

    return $account;
}

test('the page opens on the table with what landed in each account', function () {
    $account = registerBusyAccount();

    $page = accountRegister()
        ->assertSet('view', 'table')
        ->assertSeeHtml('data-register')
        ->assertSeeHtml('data-account-row="'.$account->id.'"')
        ->assertDontSee('Funds received')
        ->assertSee('Operations USD')
        ->assertSee('Fransbank')
        ->assertSee('Lebanon')
        ->assertSee('985.00')
        ->assertSee('200.00');

    $row = $page->instance()->rows->sole();

    expect((int) $row->money_transfers)->toBe(4)
        ->and((float) $row->money_received)->toBe(985.0)
        ->and((float) $row->money_in_transit)->toBe(50.0)
        ->and((float) $row->money_upcoming)->toBe(100.0)
        ->and((float) $row->money_overdue)->toBe(200.0)
        ->and($row->money_last_receipt)->toBe('2026-05-20');
});

test('the overview view shows the tiles and the cards instead of the table', function () {
    registerBusyAccount();

    accountRegister()
        ->set('view', 'overview')
        ->assertSee('Funds received')
        ->assertDontSeeHtml('data-register');
});

test('a row opens the transfers table filtered to its account', function () {
    $account = registerBusyAccount();

    accountRegister()->assertSeeHtml('href="'.route('transfers.index', ['account' => $account->id]).'"');
});

test('money that landed in another currency is named, never added to the account\'s figure', function () {
    $account = registerBusyAccount();
    accountTranche($account, ['payment_amount' => '700.00', 'net_amount_received' => '640.00', 'amount_received_currency' => Currency::Eur, 'ar_status' => ArStatus::Received, 'scheduled_date' => '2025-11-01', 'receive_date' => '2025-11-10']);

    $page = accountRegister();
    $row = $page->instance()->rows->sole();

    expect((float) $row->money_received)->toBe(985.0)
        ->and((int) $row->money_other_currency)->toBe(1);

    $page->assertSeeHtml('data-other-currency')->assertDontSee('1,625.00');
});

test('country, currency and the flags narrow the rows', function () {
    $busy = registerBusyAccount();
    $idle = Account::factory()->create(['account_name' => 'Reserve EUR', 'currency' => Currency::Eur, 'country' => 'Syria']);
    $quiet = Account::factory()->create(['account_name' => 'Field USD', 'currency' => Currency::Usd, 'country' => 'Syria']);
    accountTranche($quiet, ['payment_amount' => '10.00', 'scheduled_date' => '2026-08-01']);

    $ids = fn (Testable $page): array => $page->instance()->rows->pluck('id')->sort()->values()->all();

    expect($ids(accountRegister()->set('countryFilter', 'Syria')))->toBe([$idle->id, $quiet->id])
        ->and($ids(accountRegister()->set('currencyFilter', 'EUR')))->toBe([$idle->id])
        ->and($ids(accountRegister()->call('toggleFlag', 'has_transfers')))->toBe([$busy->id, $quiet->id])
        ->and($ids(accountRegister()->call('toggleFlag', 'no_transfers')))->toBe([$idle->id])
        ->and($ids(accountRegister()->call('toggleFlag', 'in_transit')))->toBe([$busy->id])
        ->and($ids(accountRegister(['country' => 'Lebanon'])))->toBe([$busy->id]);
});

test('choosing a year shows what landed that year and hides accounts with nothing in it', function () {
    $busy = registerBusyAccount();
    $old = Account::factory()->create(['currency' => Currency::Usd]);
    accountTranche($old, ['payment_amount' => '400.00', 'net_amount_received' => '400.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2024-02-01', 'receive_date' => '2024-02-05']);

    $page = accountRegister()->set('year', '2026');
    $row = $page->instance()->rows->sole();

    expect($row->id)->toBe($busy->id)
        ->and((float) $row->money_received_in_year)->toBe(985.0);

    $page->assertSee('Received in 2026');

    $only = accountRegister()->set('year', '2024')->set('figures', 'year')->instance()->rows->sole();

    expect($only->id)->toBe($old->id)
        ->and((int) $only->money_transfers)->toBe(1);
});

test('totals are kept per currency and count open accounts', function () {
    registerBusyAccount();
    Account::factory()->create(['currency' => Currency::Usd, 'is_active' => false, 'closed_at' => '2026-01-31']);
    Account::factory()->create(['currency' => Currency::Eur]);

    $page = accountRegister();
    $totals = $page->instance()->totals->keyBy('currency');

    expect($totals->keys()->all())->toBe(['EUR', 'USD'])
        ->and($totals['USD'])->toMatchArray(['accounts' => 2, 'open_accounts' => 1, 'received' => 985.0, 'in_transit' => 50.0, 'upcoming' => 100.0, 'overdue' => 200.0])
        ->and($totals['EUR']['received'])->toBe(0.0);

    $page->assertSeeHtml('data-register-total="USD"')->assertSeeHtml('data-register-total="EUR"');
});

test('the table opens by account name and a heading sorts it', function () {
    $busy = registerBusyAccount();
    $alpha = Account::factory()->create(['account_name' => 'Alpha Reserve', 'currency' => Currency::Usd]);
    accountTranche($alpha, ['payment_amount' => '5000.00', 'net_amount_received' => '5000.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2026-04-01', 'receive_date' => '2026-04-02']);

    $ids = fn (Testable $page): array => $page->instance()->rows->pluck('id')->all();

    $page = accountRegister();
    expect($ids($page))->toBe([$alpha->id, $busy->id]);

    $page->call('sortRegister', 'received');
    expect($ids($page))->toBe([$alpha->id, $busy->id]);

    $page->call('sortRegister', 'received');
    expect($ids($page))->toBe([$busy->id, $alpha->id]);
});

test('values typed into the address bar fall back to the defaults', function () {
    registerBusyAccount();

    accountRegister(['view' => 'map', 'state' => 'frozen', 'currencyFilter' => 'GBP', 'country' => 'Atlantis', 'flag' => 'rich', 'sortBy' => 'iban', 'perPage' => '3'])
        ->assertSet('view', 'table')
        ->assertSet('state', '')
        ->assertSet('currencyFilter', '')
        ->assertSet('countryFilter', '')
        ->assertSet('flag', '')
        ->assertSet('sortBy', '')
        ->assertSet('perPage', '25')
        ->assertOk();
});

test('the table and the export mask account numbers for someone who cannot change accounts', function () {
    registerBusyAccount();
    $viewer = userWithRole('Team Member')->givePermissionTo('accounts.view');

    $viewerPage = Livewire::actingAs($viewer)->test('pages::admin.accounts')
        ->assertSeeHtml('•••• /1/3')
        ->assertDontSee('2531267')
        ->call('exportCsv');

    expect(downloadedCsv($viewerPage)[1][4])->toBe('•••• /1/3');

    $financePage = accountRegister()->assertSee('030/02/353/2531267/1/3')->call('exportCsv');

    expect(downloadedCsv($financePage)[1][4])->toBe('030/02/353/2531267/1/3');
});

test('the export holds every filtered account with plain numbers', function () {
    registerBusyAccount();
    Account::factory()->count(30)->create(['currency' => Currency::Usd]);
    Account::factory()->create(['currency' => Currency::Eur]);

    $page = accountRegister()
        ->set('currencyFilter', 'USD')
        ->call('sortRegister', 'received')
        ->call('exportCsv')
        ->assertFileDownloaded('accounts-2026-06-15.csv');

    $csv = downloadedCsv($page);

    expect($csv)->toHaveCount(32)
        ->and($csv[0][0])->toBe('Account name')
        ->and($csv[1][0])->toBe('Operations USD')
        ->and($csv[1][1])->toBe('Fransbank')
        ->and($csv[1][2])->toBe('Lebanon')
        ->and($csv[1][3])->toBe('USD')
        ->and($csv[1][5])->toBe('Open')
        ->and($csv[1][6])->toBe('2024-03-15')
        ->and($csv[1][8])->toBe('4')
        ->and($csv[1][9])->toBe('985.00')
        ->and($csv[1][10])->toBe('50.00')
        ->and($csv[1][11])->toBe('100.00')
        ->and($csv[1][12])->toBe('200.00')
        ->and($csv[1][13])->toBe('2026-05-20');
});

test('exporting needs the accounts view permission', function () {
    registerBusyAccount();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.accounts')
        ->call('exportCsv')
        ->assertForbidden();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `.\php.cmd artisan test --compact tests/Feature/Admin/AccountRegisterTest.php`
Expected: FAIL — `Property [$view] not found on component`.

- [ ] **Step 3: Extend the component class**

All edits are in the PHP block of `resources/views/pages/admin/⚡accounts.blade.php`.

**3a. Imports.** Replace the `use` list at the top with:

```php
use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Livewire\Concerns\FiltersFinanceRegister;
use App\Models\Account;
use App\Models\TransfersTracking;
use App\Support\AccountMoney;
use App\Support\AgreementMoney;
use App\Support\CsvExport;
use App\Support\Like;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;
```

**3b. Traits, constants and filter properties.** Replace the lines from `use WithPagination;` through the `$currencyFilter` property (lines 21–30) with:

```php
    use FiltersFinanceRegister;
    use WithPagination;

    /** @var list<string> */
    public const FLAGS = ['has_transfers', 'no_transfers', 'in_transit'];

    /**
     * Every property a filter control writes. The account form's own fields
     * (`currency`, `country`, …) are not filters, which is why the filters
     * carry a `Filter` suffix.
     *
     * @var list<string>
     */
    private const FILTERS = ['search', 'state', 'currencyFilter', 'countryFilter', 'flag'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $state = '';

    #[Url(except: '')]
    public string $currencyFilter = '';

    #[Url(as: 'country', except: '')]
    public string $countryFilter = '';

    #[Url(except: '')]
    public string $flag = '';
```

**3c. Filter handling.** Replace the four methods `updatedSearch()`, `updatedState()`, `updatedCurrencyFilter()` and `clearFilters()` (lines 64–85) with:

```php
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
        if (! in_array($this->state, ['active', 'closed'], true)) {
            $this->state = '';
        }

        if (Currency::tryFrom($this->currencyFilter) === null) {
            $this->currencyFilter = '';
        }

        if (! in_array($this->countryFilter, $this->countryOptions, true)) {
            $this->countryFilter = '';
        }

        if (! in_array($this->flag, self::FLAGS, true)) {
            $this->flag = '';
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'state', 'currencyFilter', 'countryFilter', 'flag']);
        $this->resetRegisterFilters();
        $this->resetPage();
    }

    /**
     * Narrow to one flag, or clear it by choosing it again.
     */
    public function toggleFlag(string $flag): void
    {
        $this->flag = $this->flag === $flag || ! in_array($flag, self::FLAGS, true) ? '' : $flag;
        $this->resetPage();
    }

    /**
     * The countries accounts are held in, for the country picker.
     *
     * @return list<string>
     */
    #[Computed]
    public function countryOptions(): array
    {
        return Account::query()->distinct()->orderBy('country')->pluck('country')->all();
    }
```

**3d. The filtered query and the Overview page.** Replace the whole `accounts()` method (its doc block, `#[Computed]` and body, lines 98–123) with:

```php
    /**
     * Accounts matching every active filter, with no ordering, so the table,
     * its totals, the Overview cards and the export all build on it. Flags and
     * the year are conditions on the account's tranches rather than a join, so
     * the Overview view needs nothing extra.
     *
     * @return Builder<Account>
     */
    protected function filteredQuery(): Builder
    {
        $search = trim($this->search);

        return Account::query()
            ->when(
                $search !== '',
                fn (Builder $query) => $query->where(fn (Builder $query) => $query
                    ->where('accounts.account_name', 'like', Like::contains($search))
                    ->orWhere('accounts.bank_name', 'like', Like::contains($search))
                    ->orWhere('accounts.account_number_last4', Account::lastFour($search))
                    ->when($this->showsFullAccountNumbers, fn (Builder $query) => $query->orWhereIn('accounts.id', Account::idsMatchingAccountNumber($search)))),
            )
            ->when($this->state === 'active', fn (Builder $query) => $query->where('accounts.is_active', true))
            ->when($this->state === 'closed', fn (Builder $query) => $query->where('accounts.is_active', false))
            ->when($this->currencyFilter !== '', fn (Builder $query) => $query->where('accounts.currency', $this->currencyFilter))
            ->when($this->countryFilter !== '', fn (Builder $query) => $query->where('accounts.country', $this->countryFilter))
            ->when($this->flag === 'has_transfers', fn (Builder $query) => $query->has('transfers'))
            ->when($this->flag === 'no_transfers', fn (Builder $query) => $query->doesntHave('transfers'))
            ->when($this->flag === 'in_transit', fn (Builder $query) => $query->whereHas('transfers', fn (Builder $query) => $query->inState('transit')))
            ->when($this->selectedYear() !== null, fn (Builder $query) => $this->applyYear($query));
    }

    /**
     * Keep only accounts with money in the chosen year: a tranche due in it
     * or, while the lifetime figures are shown, one that landed in it.
     *
     * @param  Builder<Account>  $query
     * @return Builder<Account>
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

    /**
     * The Overview view's cards, open accounts first.
     *
     * @return LengthAwarePaginator<int, Account>
     */
    #[Computed]
    public function accounts(): LengthAwarePaginator
    {
        return $this->filteredQuery()
            ->select(['id', 'account_name', 'bank_name', 'currency', 'account_number', 'country', 'opened_at', 'closed_at', 'is_active', 'updated_at'])
            ->withCount('transfers')
            ->withMax('transfers as last_transfer_at', 'transfer_date')
            ->orderByDesc('is_active')
            ->orderBy('account_name')
            ->orderBy('id')
            ->paginate(15);
    }

    /**
     * The filtered accounts joined to their money, which is one grouped join
     * rather than a loaded relation: that is what lets a money column be
     * sorted, totalled and exported.
     *
     * @return Builder<Account>
     */
    protected function registerQuery(): Builder
    {
        return $this->filteredQuery()
            ->leftJoinSub(AccountMoney::perAccount($this->selectedYear(), $this->yearOnly()), 'money', 'money.account_id', '=', 'accounts.id')
            ->select(['accounts.id', 'accounts.account_name', 'accounts.bank_name', 'accounts.currency', 'accounts.account_number', 'accounts.country', 'accounts.opened_at', 'accounts.closed_at', 'accounts.is_active'])
            ->selectRaw(AccountMoney::select());
    }

    /**
     * What each column heading sorts by. Amounts sort inside their currency.
     *
     * @return array<string, array{dir: 'asc'|'desc', order: list<string>}>
     */
    protected function registerSorts(): array
    {
        $byName = 'accounts.account_name asc';
        $lastById = 'accounts.id asc';
        $text = fn (string $column): array => ['dir' => 'asc', 'order' => ["accounts.{$column} {dir}", $byName, $lastById]];
        $date = fn (string $expression): array => ['dir' => 'desc', 'order' => ["{$expression} is null", "{$expression} {dir}", $byName, $lastById]];
        $money = fn (string $column): array => ['dir' => 'desc', 'order' => ['accounts.currency asc', "coalesce(money.{$column}, 0) {dir}", $byName, $lastById]];

        return [
            'account' => ['dir' => 'asc', 'order' => ['accounts.account_name {dir}', $lastById]],
            'bank' => $text('bank_name'),
            'country' => $text('country'),
            'currency' => $text('currency'),
            // Open accounts first.
            'state' => ['dir' => 'desc', 'order' => ['accounts.is_active {dir}', $byName, $lastById]],
            'opened' => $date('accounts.opened_at'),
            'closed' => $date('accounts.closed_at'),
            'transfers' => ['dir' => 'desc', 'order' => ['coalesce(money.a_transfers, 0) {dir}', $byName, $lastById]],
            'received' => $money('a_received'),
            'received_in_year' => $money('a_received_in_year'),
            'in_transit' => $money('a_in_transit'),
            'upcoming' => $money('a_upcoming'),
            'overdue' => $money('a_overdue'),
            'last_receipt' => $date('money.a_last_receipt'),
        ];
    }

    protected function registerDefaultSort(): string
    {
        return 'account';
    }

    /**
     * The table's visible page.
     *
     * @return LengthAwarePaginator<int, Account>
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        return $this->applyRegisterSort($this->registerQuery())->paginate($this->registerPerPage());
    }

    /**
     * The table's totals, one line per account currency, for every account
     * the filters match.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function totals(): Collection
    {
        return $this->filteredQuery()
            ->leftJoinSub(AccountMoney::perAccount($this->selectedYear(), $this->yearOnly()), 'money', 'money.account_id', '=', 'accounts.id')
            ->toBase()
            ->selectRaw(AccountMoney::totalsSelect())
            ->groupBy('accounts.currency')
            ->orderBy('accounts.currency')
            ->get()
            ->map(fn (object $row): array => [
                'currency' => (string) $row->currency,
                'accounts' => (int) $row->accounts,
                'open_accounts' => (int) $row->open_accounts,
                'transfers' => (int) $row->transfers,
                'received' => (float) $row->received,
                'in_transit' => (float) $row->in_transit,
                'upcoming' => (float) $row->upcoming,
                'overdue' => (float) $row->overdue,
                'received_in_year' => (float) $row->received_in_year,
            ]);
    }

    /**
     * Whether any account exists at all, so an empty page can tell "nothing
     * opened yet" from "nothing matches".
     */
    #[Computed]
    public function hasAnyAccount(): bool
    {
        return Account::query()->exists();
    }

    /**
     * Every account the filters match, in the table's order, as a CSV file.
     * The account number follows the same rule as the screen: whole for the
     * people who may change accounts, masked for everyone else.
     */
    public function exportCsv(): StreamedResponse
    {
        Gate::authorize('accounts.view');

        $year = $this->selectedYear();
        $showsFullNumbers = $this->showsFullAccountNumbers;
        $accounts = $this->applyRegisterSort($this->registerQuery())->cursor();

        $headings = [
            __('accounts.account_name'),
            __('accounts.bank_name'),
            __('donors.country'),
            __('common.currency'),
            __('accounts.account_number'),
            __('common.state'),
            __('accounts.opened_on'),
            __('finance_tables.closed_on'),
            __('common.transfers'),
            __('finance_tables.received'),
            __('finance_tables.in_transit'),
            __('finance_tables.upcoming'),
            __('finance_tables.overdue'),
            __('finance_tables.last_receipt'),
            ...($year === null ? [] : [__('finance_tables.received_in_year', ['year' => $year])]),
        ];

        $rows = (function () use ($accounts, $year, $showsFullNumbers) {
            foreach ($accounts as $account) {
                yield [
                    $account->account_name,
                    $account->bank_name,
                    $account->country,
                    $account->currency->value,
                    $showsFullNumbers ? $account->account_number : $account->maskedAccountNumber(),
                    $account->is_active ? __('accounts.open') : __('accounts.closed'),
                    $account->opened_at,
                    $account->closed_at,
                    (int) $account->money_transfers,
                    CsvExport::amount($account->money_received),
                    CsvExport::amount($account->money_in_transit),
                    CsvExport::amount($account->money_upcoming),
                    CsvExport::amount($account->money_overdue),
                    $account->money_last_receipt,
                    ...($year === null ? [] : [CsvExport::amount($account->money_received_in_year)]),
                ];
            }
        })();

        return CsvExport::download('accounts-'.CarbonImmutable::today()->toDateString().'.csv', $headings, $rows);
    }
```

Everything else in the class (`showsFullAccountNumbers`, `accountMoneyStats`, `summary`, `fundsByCurrency`, the account form and the close flow) stays exactly as it is.

- [ ] **Step 4: Replace the header and body of the template**

After Task 2 the template runs from `<section class="admin-directory …">` to `</section>`. Replace everything from that opening `<section …>` line up to, but not including, the first modal (`<x-ui.modal wire:model="showAccountModal"`) with:

```blade
@php
    $showsOverview = $this->view === 'overview';
    $filtersAreActive = collect([$this->search, $this->state, $this->currencyFilter, $this->countryFilter, $this->flag, $this->year])
        ->contains(fn (string $value): bool => $value !== '');
    $total = $showsOverview ? $this->accounts->total() : $this->rows->total();
    $mutedText = 'text-secondary-medium dark:text-ink-muted';

    /* `$chosen` is read instead of the properties so a loop variable can never shadow one. */
    $chosen = [
        'year' => $this->year,
        'figures' => $this->figures,
        'state' => $this->state,
        'currencyFilter' => $this->currencyFilter,
        'flag' => $this->flag,
    ];
    $selectedYear = $this->selectedYear();
    $sortKey = $this->activeSort();
    $sortDirection = $this->activeSortDir();
    $showsFullNumbers = $this->showsFullAccountNumbers;

    $setPill = fn (string $property, string $value, string $label): array => [
        'label' => $label,
        'click' => "\$set('{$property}', '".($chosen[$property] === $value ? '' : $value)."')",
        'active' => $chosen[$property] === $value,
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
        ['label' => __('finance_tables.figures_all_time'), 'click' => "\$set('figures', 'lifetime')", 'active' => $chosen['figures'] === 'lifetime'],
        ['label' => __('finance_tables.figures_year', ['year' => $chosen['year']]), 'click' => "\$set('figures', 'year')", 'active' => $chosen['figures'] === 'year'],
    ];
    $statePills = [
        ['label' => __('finance_tables.all'), 'click' => "\$set('state', '')", 'active' => $chosen['state'] === ''],
        $setPill('state', 'active', __('accounts.open')),
        $setPill('state', 'closed', __('accounts.closed')),
    ];
    $currencyPills = [
        ['label' => __('finance_tables.all'), 'click' => "\$set('currencyFilter', '')", 'active' => $chosen['currencyFilter'] === ''],
        ...array_map(fn (Currency $case): array => $setPill('currencyFilter', $case->value, $case->value), Currency::cases()),
    ];
    $flagPills = [
        $flagPill('has_transfers', __('finance_tables.flag_has_transfers')),
        $flagPill('no_transfers', __('finance_tables.flag_no_transfers')),
        $flagPill('in_transit', __('finance_tables.flag_in_transit')),
    ];
@endphp

<section class="admin-directory @container/directory flex min-h-full w-full flex-1 flex-col gap-6 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" aria-labelledby="account-directory-heading">
    <x-ui.page-header
        heading-id="account-directory-heading"
        :title="__('common.accounts')"
        :count="$total"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('accounts.search_accounts')" :placeholder="__('accounts.account_name')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('donors.country')" wire:model.live="countryFilter" :value="$this->countryFilter">
                <flux:select.option value="">{{ __('finance_tables.all_countries') }}</flux:select.option>
                @foreach ($this->countryOptions as $countryOption)
                    <flux:select.option :value="$countryOption" :wire:key="'account-country-'.md5($countryOption)">{{ $countryOption }}</flux:select.option>
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

                @if ($this->hasAnyAccount)
                    <x-ui.button icon="arrow-down-tray" class="min-h-11 touch-manipulation @sm/directory:min-h-10" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv" data-test="export-csv">
                        {{ __('finance_tables.export_csv') }}
                    </x-ui.button>
                @endif

                @can('accounts.create')
                    <x-ui.button tone="primary" icon="plus" class="min-h-11 touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-account-button">
                        {{ __('accounts.open_account') }}
                    </x-ui.button>
                @endcan
            </div>
        </x-slot:actions>

        @if ($this->hasAnyAccount)
            <x-slot:summary>
                <x-funding.filters :active="$filtersAreActive">
                    <x-funding.pills :label="__('finance_tables.year')" :pills="$yearPills" />

                    @if ($selectedYear !== null)
                        <x-funding.pills :label="__('finance_tables.figures_label')" :pills="$figurePills" />
                    @endif

                    <x-funding.pills :label="__('common.state')" :pills="$statePills" />
                    <x-funding.pills :label="__('common.currency')" :pills="$currencyPills" />
                    <x-funding.pills :label="__('finance_tables.flags')" :pills="$flagPills" />
                </x-funding.filters>
            </x-slot:summary>
        @endif
    </x-ui.page-header>

    @if (! $this->hasAnyAccount)
        <x-ui.empty-state
            icon="building-library"
            :heading="__('accounts.no_accounts_yet')"
            :body="__('accounts.open_the_first_account')"
        />
    @elseif ($showsOverview)
        @include('pages.admin._accounts-overview')
    @elseif ($this->rows->isEmpty())
        <x-ui.empty-state
            icon="building-library"
            :heading="__('accounts.no_matching_accounts')"
            :body="__('common.change_the_search_or_filters_and_try_again')"
        />
    @else
        @php
            $totals = $this->totals;
            $money = fn (string|float|int|null $value): string => number_format((float) $value, 2);
            $date = fn (?\DateTimeInterface $value): string => $value?->translatedFormat(__('formats.date')) ?? '—';

            $totalLines = $totals->map(fn (array $line): array => [
                'currency' => $line['currency'],
                'caption' => __('finance_tables.count_accounts_open', ['count' => $line['accounts'], 'open' => $line['open_accounts']]),
                'figures' => array_values(array_filter([
                    ['key' => 'received', 'label' => __('finance_tables.received'), 'value' => $line['received'], 'tone' => 'good'],
                    $selectedYear !== null && $chosen['figures'] === 'lifetime' ? ['key' => 'received_in_year', 'label' => __('finance_tables.received_in_year', ['year' => $selectedYear]), 'value' => $line['received_in_year'], 'tone' => 'good'] : null,
                    ['key' => 'in_transit', 'label' => __('finance_tables.in_transit'), 'value' => $line['in_transit'], 'tone' => $line['in_transit'] > 0 ? 'warn' : null],
                    ['key' => 'upcoming', 'label' => __('finance_tables.upcoming'), 'value' => $line['upcoming']],
                    ['key' => 'overdue', 'label' => __('finance_tables.overdue'), 'value' => $line['overdue'], 'tone' => $line['overdue'] > 0 ? 'critical' : null],
                ])),
            ])->all();

            $showsYearColumn = $selectedYear !== null && $chosen['figures'] === 'lifetime';
        @endphp

        <x-funding.totals :lines="$totalLines" />

        <x-funding.register size="xl" :caption="__('finance_tables.accounts_caption')">
            <x-slot:head>
                <x-funding.register.th sort="account" :active="$sortKey" :dir="$sortDirection">{{ __('accounts.account_name') }}</x-funding.register.th>
                <x-funding.register.th sort="bank" :active="$sortKey" :dir="$sortDirection">{{ __('accounts.bank_name') }}</x-funding.register.th>
                <x-funding.register.th sort="country" :active="$sortKey" :dir="$sortDirection">{{ __('donors.country') }}</x-funding.register.th>
                <x-funding.register.th sort="currency" :active="$sortKey" :dir="$sortDirection">{{ __('common.currency') }}</x-funding.register.th>
                <x-funding.register.th>{{ __('accounts.account_number') }}</x-funding.register.th>
                <x-funding.register.th sort="state" :active="$sortKey" :dir="$sortDirection">{{ __('common.state') }}</x-funding.register.th>
                <x-funding.register.th sort="opened" :active="$sortKey" :dir="$sortDirection">{{ __('accounts.opened_on') }}</x-funding.register.th>
                <x-funding.register.th sort="closed" :active="$sortKey" :dir="$sortDirection">{{ __('finance_tables.closed_on') }}</x-funding.register.th>
                <x-funding.register.th sort="transfers" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('common.transfers') }}</x-funding.register.th>
                <x-funding.register.th sort="received" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.received') }}</x-funding.register.th>
                @if ($showsYearColumn)
                    <x-funding.register.th sort="received_in_year" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.received_in_year', ['year' => $selectedYear]) }}</x-funding.register.th>
                @endif
                <x-funding.register.th sort="in_transit" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.in_transit') }}</x-funding.register.th>
                <x-funding.register.th sort="upcoming" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.upcoming') }}</x-funding.register.th>
                <x-funding.register.th sort="overdue" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.overdue') }}</x-funding.register.th>
                <x-funding.register.th sort="last_receipt" :active="$sortKey" :dir="$sortDirection">{{ __('finance_tables.last_receipt') }}</x-funding.register.th>
                <th scope="col"><span class="sr-only">{{ __('common.actions') }}</span></th>
            </x-slot:head>

            @foreach ($this->rows as $account)
                @php
                    $lastReceipt = $account->money_last_receipt !== null ? \Carbon\CarbonImmutable::parse($account->money_last_receipt) : null;
                    $overdue = (float) $account->money_overdue;
                    $otherCurrency = (int) $account->money_other_currency;
                @endphp
                <tr wire:key="account-{{ $account->id }}" data-account-row="{{ $account->id }}">
                    <td data-label="{{ __('accounts.account_name') }}" data-wide><span class="font-semibold">{{ $account->account_name }}</span></td>
                    <td data-label="{{ __('accounts.bank_name') }}">{{ $account->bank_name }}</td>
                    <td data-label="{{ __('donors.country') }}">{{ $account->country }}</td>
                    <td data-label="{{ __('common.currency') }}">{{ $account->currency->value }}</td>
                    <td data-label="{{ __('accounts.account_number') }}"><span class="fig" data-test="account-number">{{ $showsFullNumbers ? $account->account_number : $account->maskedAccountNumber() }}</span></td>
                    <td data-label="{{ __('common.state') }}">
                        <span @class([
                            'inline-flex items-center gap-1.5 text-xs font-semibold whitespace-nowrap',
                            'text-status-good' => $account->is_active,
                            $mutedText => ! $account->is_active,
                        ])>
                            <span @class(['size-2 shrink-0 rounded-full', 'bg-status-good' => $account->is_active, 'bg-secondary-light dark:bg-ink-faint' => ! $account->is_active]) aria-hidden="true"></span>
                            {{ $account->is_active ? __('accounts.open') : __('accounts.closed') }}
                        </span>
                    </td>
                    <td class="date" data-label="{{ __('accounts.opened_on') }}">{{ $date($account->opened_at) }}</td>
                    <td class="date" data-label="{{ __('finance_tables.closed_on') }}">{{ $date($account->closed_at) }}</td>
                    <td class="num" data-label="{{ __('common.transfers') }}">
                        @can('transfers.view')
                            <a href="{{ route('transfers.index', ['account' => $account->id]) }}" wire:navigate class="fig font-semibold underline underline-offset-2">{{ (int) $account->money_transfers }}</a>
                        @else
                            <span class="fig">{{ (int) $account->money_transfers }}</span>
                        @endcan
                    </td>
                    <td class="num" data-label="{{ __('finance_tables.received') }}" data-cell="received">
                        <span class="fig font-semibold text-funding">{{ $money($account->money_received) }}</span>
                        @if ($otherCurrency > 0)
                            {{-- Counted, never converted: the transfers table shows each one in the currency it landed in. --}}
                            <span class="sub" data-other-currency>{{ trans_choice('finance_tables.count_landed_in_another_currency', $otherCurrency, ['count' => $otherCurrency]) }}</span>
                        @endif
                    </td>
                    @if ($showsYearColumn)
                        <td class="num" data-label="{{ __('finance_tables.received_in_year', ['year' => $selectedYear]) }}" data-cell="received_in_year"><span class="fig font-semibold text-funding">{{ $money($account->money_received_in_year) }}</span></td>
                    @endif
                    <td class="num" data-label="{{ __('finance_tables.in_transit') }}" data-cell="in_transit"><span class="fig">{{ $money($account->money_in_transit) }}</span></td>
                    <td class="num" data-label="{{ __('finance_tables.upcoming') }}" data-cell="upcoming"><span class="fig">{{ $money($account->money_upcoming) }}</span></td>
                    <td class="num" data-label="{{ __('finance_tables.overdue') }}" data-cell="overdue"><span @class(['fig', 'font-semibold text-status-critical' => $overdue > 0])>{{ $money($overdue) }}</span></td>
                    <td class="date" data-label="{{ __('finance_tables.last_receipt') }}">{{ $date($lastReceipt) }}</td>
                    <td>
                        <span class="flex items-center justify-end">
                            @can('transfers.view')
                                <x-ui.button tone="ghost" size="sm" icon="eye" class="size-8!" :href="route('transfers.index', ['account' => $account->id])" wire:navigate :aria-label="__('accounts.view_transfers_for_name', ['name' => $account->account_name])" />
                            @endcan
                            @can('accounts.update')
                                <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-8!" wire:click="openEditModal({{ $account->id }})" :aria-label="__('common.edit_name', ['name' => $account->account_name])" />
                            @endcan

                            {{-- No delete anywhere: transfers_tracking.account_id RESTRICTs. Closing needs accounts.delete. --}}
                            @if ($account->is_active && auth()->user()->can('accounts.delete'))
                                <x-ui.button tone="ghost" size="sm" icon="lock-closed" class="size-8!" wire:click="confirmClose({{ $account->id }})" :aria-label="__('accounts.close_name', ['name' => $account->account_name])" />
                            @endif
                        </span>
                    </td>
                </tr>
            @endforeach

            <x-slot:foot>
                @foreach ($totals as $line)
                    <tr wire:key="account-total-{{ $line['currency'] }}" data-register-total="{{ $line['currency'] }}">
                        <td colspan="3" data-wide>
                            {{ __('finance_tables.total') }}
                            <span class="sub">{{ __('finance_tables.count_accounts_open', ['count' => $line['accounts'], 'open' => $line['open_accounts']]) }}</span>
                        </td>
                        <td data-label="{{ __('common.currency') }}">{{ $line['currency'] }}</td>
                        <td colspan="4"></td>
                        <td class="num" data-label="{{ __('common.transfers') }}"><span class="fig">{{ $line['transfers'] }}</span></td>
                        <td class="num" data-label="{{ __('finance_tables.received') }}"><span class="fig text-funding">{{ $money($line['received']) }}</span></td>
                        @if ($showsYearColumn)
                            <td class="num" data-label="{{ __('finance_tables.received_in_year', ['year' => $selectedYear]) }}"><span class="fig text-funding">{{ $money($line['received_in_year']) }}</span></td>
                        @endif
                        <td class="num" data-label="{{ __('finance_tables.in_transit') }}"><span class="fig">{{ $money($line['in_transit']) }}</span></td>
                        <td class="num" data-label="{{ __('finance_tables.upcoming') }}"><span class="fig">{{ $money($line['upcoming']) }}</span></td>
                        <td class="num" data-label="{{ __('finance_tables.overdue') }}"><span @class(['fig', 'text-status-critical' => $line['overdue'] > 0])>{{ $money($line['overdue']) }}</span></td>
                        <td colspan="2"></td>
                    </tr>
                @endforeach
            </x-slot:foot>
        </x-funding.register>

        <x-funding.pager :paginator="$this->rows" :per-page="$this->perPage" scroll-to="#account-directory-heading" />
    @endif

```

The two modals and the closing `</section>` that follow stay exactly as they are.

- [ ] **Step 5: Run the new tests**

Run: `.\php.cmd artisan test --compact tests/Feature/Admin/AccountRegisterTest.php`
Expected: PASS, 12 tests.

Common failures and their cause:
- `Column 'currency' in where clause is ambiguous` cannot happen (the joined table exposes only `account_id` and `a_*` columns); if it does, a column was added to `AccountMoney::perAccount()` without the `a_` prefix.
- `MissingAttributeException [account_number]` — the `rows` select must include `accounts.account_number`; it is encrypted and decrypted by the model's cast.
- The masked-number assertion fails with a full number — the row cell must use `$showsFullNumbers`, not `@can('accounts.view')`.

- [ ] **Step 6: Run every test that touches this page**

Run: `.\php.cmd artisan test --compact tests/Feature/Admin/AccountDirectoryTest.php tests/Feature/Admin/AccountRegisterTest.php tests/Feature/AccountMoneyTest.php tests/Feature/AccessControl/FinanceAuthorizationTest.php tests/Feature/AccountTest.php tests/Feature/LocalizationTest.php`
Expected: PASS. None of the existing account tests needs a change: they assert account names, the masked number, the absence of delete controls and the form, all of which the table prints the same way.

- [ ] **Step 7: Checkpoint**

Run: `.\php.cmd vendor/bin/pint --dirty --format agent`. Do not commit.

---

### Task 4: Look at it

**Files:** none changed unless a defect is found.

- [ ] **Step 1: Render the page** as in Phase 1, Task 5 for `/admin/accounts` (confirm the path with `.\php.cmd artisan route:list --name=admin.accounts`), the same with `?year=2026`, and with `?view=overview`.

- [ ] **Step 2: Check each of these and fix what fails**

| Check | Expected |
| --- | --- |
| Table view | One row per account; open accounts carry a green dot, closed ones a grey one; account numbers read left to right. |
| Eye button | Opens Transfers with the account picker showing that account and only its tranches listed. |
| A year chosen | "Received in 2026" appears after "Received"; accounts with nothing that year are gone. |
| Footer | One row per currency, figures under their columns. |
| Overview view | The tiles, donut and cards as before. |
| Dark mode, Arabic, 390px | As in Phase 1. |

- [ ] **Step 3: Delete the preview file and checkpoint.** Do not commit.
