# Funding tables — Phase 1: Transfers register

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Transfers page opens on a full table of every tranche (all money columns visible, sortable, filterable, groupable, with totals and CSV export); today's ledger and ladder stay available behind a `Table | Overview` switch.

**Architecture:** The page keeps its existing component class (form trait, `ledger`, `transfers`, `toggleStage`, `setSort`) so the Overview view is untouched, and gains the Phase 0 `FiltersFinanceRegister` trait plus new filters, a `rows` paginator, SQL `totals`, `groupTotals` and `exportCsv()`. The old markup moves verbatim into `_overview.blade.php`; the table is built from the Phase 0 register components plus four new tranche components that Phase 6 reuses on the detail pages.

**Tech Stack:** Laravel 13, Livewire 4 single-file page components, Flux (lite), Tailwind 4, Pest 5, MySQL.

**Depends on:** Phase 0 (`2026-09-30-funding-tables-phase-0-kit.md`) fully done.

**Spec:** `docs/superpowers/specs/2026-09-30-funding-finance-tables-design.md`

## Global Constraints

- PHP runs through `.\php.cmd` (PHP 8.5 at `C:\php85`), never bare `php`. From the Bash tool use `/c/php85/php.exe`.
- **Do not commit.** Leave every change in the working tree for the user's review. Where a task says "Checkpoint", stop after the listed commands pass.
- Never run the whole test suite (about 10 minutes). Run only the files each step names.
- Run `.\php.cmd vendor/bin/pint --dirty --format agent` after changing any PHP file.
- No new Composer or npm package. No migration.
- Never use the single-line `@php(...)` shorthand in Blade; always a full `@php … @endphp` block.
- Every visible string goes through a lang key present in both `lang/en` and `lang/ar`. New keys for this work live in `lang/*/finance_tables.php` (created in Phase 0) — this phase adds none.
- Dark mode: only Graphite tokens behind `dark:` (`surface-0..3`, `ink`, `ink-muted`, `ink-faint`, `line`, `line-strong`). Alarm text is `text-status-critical`, never `text-primary`.
- Logical Tailwind classes only (`ms/me/ps/pe/start/end/text-start/text-end`), never left/right.
- Currencies are never added together. "Received" is `net_amount_received` of a settled tranche. A shortfall is summed per tranche and never netted against an overage.
- No existing test is deleted. A test that asserts on the old visual is pointed at the Overview view with `->set('view', 'overview')`.
- Line numbers quoted below are for commit `99c95eb`. If a file has changed since, find the block by the first and last lines quoted instead.

## File Structure

| File | Responsibility |
| --- | --- |
| Create `resources/views/components/funding/state.blade.php` | One tranche's state as a dot and a word. |
| Create `resources/views/components/funding/tranche-head.blade.php` | The heading cells every tranche table shares. |
| Create `resources/views/components/funding/tranche-row.blade.php` | One tranche as a table row: every money column. |
| Create `resources/views/components/funding/tranche-foot.blade.php` | One totals row per currency. |
| Create `resources/views/components/funding/tranche-group.blade.php` | A group header row with its subtotals. |
| Create `resources/views/pages/transfers/_overview.blade.php` | Today's ledger and ladder, moved unchanged. |
| Modify `resources/views/pages/transfers/⚡index.blade.php` | New filters, table data, template. |
| Create `tests/Feature/TrancheComponentsTest.php` | Renders the tranche components. |
| Create `tests/Feature/TransferRegisterTest.php` | The table: filters, sort, totals, grouping, export. |
| Modify `tests/Feature/TransferTrackingTest.php` | Point visual assertions at the Overview view. |

---

### Task 1: Tranche table components

**Files:**
- Create: `resources/views/components/funding/state.blade.php`
- Create: `resources/views/components/funding/tranche-head.blade.php`
- Create: `resources/views/components/funding/tranche-row.blade.php`
- Create: `resources/views/components/funding/tranche-foot.blade.php`
- Create: `resources/views/components/funding/tranche-group.blade.php`
- Test: `tests/Feature/TrancheComponentsTest.php`

**Interfaces:**
- Consumes (Phase 0): `TransfersTracking::state()`, `::isMissingNetAmount()`, `::difference(): ?float`, `::landedInAnotherCurrency(): bool`; `App\Support\Tranches::timing(TransfersTracking): ?string` and `::stateLabel(string $state, bool $missingNet = false): string`; `x-funding.register.th` (props `sort`, `active`, `dir`, `numeric`); register CSS classes `num`, `date`, `fig`, `sub`, `register-group`; lang group `finance_tables`.
- Produces:
  - `<x-funding.state :transfer="$t" />` — also accepts `state="late"` with `:missing-net="bool"` when no model is at hand.
  - `<x-funding.tranche-head :sortable="bool" :active="string" :dir="'asc'|'desc'" :actions="bool" />` — 12 `<th>` (status, account, scheduled, sent, received, currency, amount, fees, rate, gross, net, difference) plus one empty `<th>` when `actions`. Sort keys: `status`, `account`, `scheduled`, `sent`, `received`, `amount`, `fees`, `rate`, `gross`, `net`, `difference`.
  - `<x-funding.tranche-row :transfer="$t">` — one `<tr wire:key="transfer-{id}" data-transfer="{id}" data-transfer-state="{state}">`. Optional slots: `lead` (`<td>`s printed before the status cell), `actions` (one `<td>` printed last). Requires the `account` relation to be loaded.
  - `<x-funding.tranche-foot :lines="$lines" :lead="int" :actions="bool" :label="?string" />` — one `<tr data-register-total="{currency}">` per line.
  - `<x-funding.tranche-group :label="string" :lines="$lines" :lead="int" :actions="bool" :group="string" />` — one `<tr class="register-group" data-group="{group}">`.
  - `$lines` everywhere is `list<array{currency: string, tranches: int, sent: float, fees: float, gross: float, net: float, shortfall: float, overage: float}>`.

- [x] **Step 1: Write the failing tests**

Create `tests/Feature/TrancheComponentsTest.php`:

```php
<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\Account;
use App\Models\TransfersTracking;
use Illuminate\Support\Facades\Blade;

/**
 * A tranche as the components receive it: saved, re-read so every column is
 * hydrated, with its account loaded.
 */
function componentTranche(array $attributes): TransfersTracking
{
    return TransfersTracking::factory()->create($attributes)->fresh()->load('account');
}

test('a settled tranche row prints every money column exactly', function () {
    $tranche = componentTranche([
        'account_id' => Account::factory()->create(['account_name' => 'Operations USD'])->id,
        'payment_amount' => '250000.00',
        'amount_transfered_currency' => Currency::Usd,
        'fees' => '450.00',
        'fees_currency' => Currency::Usd,
        'exchange_rate' => '1.000000',
        'amount_received' => '249800.00',
        'net_amount_received' => '249350.00',
        'amount_received_currency' => Currency::Usd,
        'scheduled_date' => '2026-04-01',
        'transfer_date' => '2026-04-03',
        'receive_date' => '2026-04-08',
        'ar_status' => ArStatus::Received,
    ]);

    $html = Blade::render('<table><tbody><x-funding.tranche-row :transfer="$tranche" /></tbody></table>', ['tranche' => $tranche]);

    expect($html)->toContain('wire:key="transfer-'.$tranche->id.'"')
        ->and($html)->toContain('data-transfer-state="received"')
        ->and($html)->toContain('Operations USD')
        ->and($html)->toContain('250,000.00')
        ->and($html)->toContain('450.00')
        ->and($html)->toContain('249,800.00')
        ->and($html)->toContain('249,350.00')
        ->and($html)->toContain('−650.00')
        ->and($html)->toContain('Landed in 5 days');
});

test('a pending tranche shows dashes for what has not happened, never its prefilled net', function () {
    $tranche = componentTranche([
        'payment_amount' => '1000.00',
        'amount_received' => '777.00',
        'net_amount_received' => '777.00',
        'scheduled_date' => now()->addMonth()->toDateString(),
        'transfer_date' => null,
        'ar_status' => ArStatus::Pending,
    ]);

    $html = Blade::render('<table><tbody><x-funding.tranche-row :transfer="$tranche" /></tbody></table>', ['tranche' => $tranche]);

    expect($html)->toContain('data-transfer-state="upcoming"')
        ->and($html)->toContain('1,000.00')
        ->and($html)->not->toContain('777.00')
        ->and($html)->toContain('No account');
});

test('a received tranche with no net reads not recorded, and one in another currency reads converted', function () {
    $missing = componentTranche([
        'payment_amount' => '300.00',
        'amount_received' => null,
        'net_amount_received' => null,
        'ar_status' => ArStatus::Received,
    ]);
    $converted = componentTranche([
        'payment_amount' => '300.00',
        'amount_transfered_currency' => Currency::Usd,
        'amount_received' => '280.00',
        'net_amount_received' => '275.00',
        'amount_received_currency' => Currency::Eur,
        'ar_status' => ArStatus::Received,
    ]);

    $render = fn (TransfersTracking $tranche): string => Blade::render('<table><tbody><x-funding.tranche-row :transfer="$tranche" /></tbody></table>', ['tranche' => $tranche]);

    expect($render($missing))->toContain('Not recorded yet')
        ->and($render($missing))->toContain('data-state="net-missing"')
        ->and($render($converted))->toContain('Converted')
        ->and($render($converted))->toContain('275.00')
        ->and($render($converted))->toContain('EUR');
});

test('the row prints its lead and actions slots around the shared cells', function () {
    $tranche = componentTranche(['ar_status' => ArStatus::Pending]);

    $html = Blade::render(
        '<table><tbody><x-funding.tranche-row :transfer="$tranche"><x-slot:lead><td>LEAD CELL</td></x-slot:lead><x-slot:actions><td>ACTION CELL</td></x-slot:actions></x-funding.tranche-row></tbody></table>',
        ['tranche' => $tranche],
    );

    expect(strpos($html, 'LEAD CELL'))->toBeLessThan(strpos($html, 'data-state='))
        ->and(strpos($html, 'ACTION CELL'))->toBeGreaterThan(strpos($html, 'data-cell="difference"'));
});

test('the foot prints one row per currency and never nets an overage against a shortfall', function () {
    $lines = [
        ['currency' => 'EUR', 'tranches' => 1, 'sent' => 500.0, 'fees' => 0.0, 'gross' => 500.0, 'net' => 500.0, 'shortfall' => 0.0, 'overage' => 0.0],
        ['currency' => 'USD', 'tranches' => 2, 'sent' => 150000.0, 'fees' => 35.0, 'gross' => 170000.0, 'net' => 170000.0, 'shortfall' => 10000.0, 'overage' => 30000.0],
    ];

    $html = Blade::render('<table><tfoot><x-funding.tranche-foot :lines="$lines" :lead="1" /></tfoot></table>', ['lines' => $lines]);

    expect($html)->toContain('data-register-total="EUR"')
        ->and($html)->toContain('data-register-total="USD"')
        ->and($html)->toContain('150,000.00')
        ->and($html)->toContain('−10,000.00')
        ->and($html)->toContain('+30,000.00')
        ->and($html)->not->toContain('150,500.00')
        ->and($html)->toContain('colspan="6"');
});

test('a group row carries its label and a subtotal line per currency', function () {
    $lines = [
        ['currency' => 'USD', 'tranches' => 26, 'sent' => 2600.0, 'fees' => 0.0, 'gross' => 0.0, 'net' => 0.0, 'shortfall' => 0.0, 'overage' => 0.0],
    ];

    $html = Blade::render('<table><tbody><x-funding.tranche-group label="Alpha Fund" group="7" :lines="$lines" :lead="1" /></tbody></table>', ['lines' => $lines]);

    expect($html)->toContain('data-group="7"')
        ->and($html)->toContain('register-group')
        ->and($html)->toContain('Alpha Fund')
        ->and($html)->toContain('26 tranches')
        ->and($html)->toContain('2,600.00');
});

test('the head offers a sort button per sortable column only when asked', function () {
    $sortable = Blade::render('<table><thead><tr><x-funding.tranche-head sortable active="amount" dir="desc" /></tr></thead></table>');
    $plain = Blade::render('<table><thead><tr><x-funding.tranche-head :actions="false" /></tr></thead></table>');

    expect($sortable)->toContain('wire:click="sortRegister(\'amount\')"')
        ->and($sortable)->toContain('aria-sort="descending"')
        ->and(substr_count($sortable, '<th scope='))->toBe(13)
        ->and($plain)->not->toContain('sortRegister')
        ->and(substr_count($plain, '<th scope='))->toBe(12);
});
```

- [x] **Step 2: Run the tests to verify they fail**

Run: `.\php.cmd artisan test --compact tests/Feature/TrancheComponentsTest.php`
Expected: FAIL — `Unable to locate a class or view for component [funding.tranche-row]`.

- [x] **Step 3: Create the state component**

Create `resources/views/components/funding/state.blade.php`:

```blade
@props(['transfer' => null, 'state' => null, 'missingNet' => false])

@php
    /**
     * One tranche's state in the vocabulary every funding page shares
     * (TransfersTracking::state()). Received money nobody recorded a net
     * amount for is its own reading: it must never look like money landed.
     */
    if ($transfer !== null) {
        $state = $transfer->state();
        $missingNet = $transfer->isMissingNetAmount();
    }

    [$dot, $tone] = match (true) {
        (bool) $missingNet => ['money-unrecorded', 'text-status-warn'],
        $state === 'received' => ['bg-funding', 'text-funding'],
        $state === 'transit' => ['bg-status-warn', 'text-status-warn'],
        $state === 'late', $state === 'failed' => ['bg-primary dark:bg-status-critical', 'text-status-critical'],
        default => ['money-pending', 'text-secondary-medium dark:text-ink-muted'],
    };

    /* One place writes every state key out in full: LocalizationTest reads lang keys literally, so a key glued together at runtime would fail it. */
    $label = \App\Support\Tranches::stateLabel((string) $state, (bool) $missingNet);
@endphp

<span {{ $attributes->class('inline-flex items-center gap-1.5 text-xs font-semibold whitespace-nowrap '.$tone) }} data-state="{{ $missingNet ? 'net-missing' : $state }}">
    <span class="size-2 shrink-0 rounded-full {{ $dot }}" aria-hidden="true"></span>{{ $label }}
</span>
```

- [x] **Step 4: Create the head component**

Create `resources/views/components/funding/tranche-head.blade.php`:

```blade
@props(['sortable' => false, 'active' => '', 'dir' => 'asc', 'actions' => true])

@php
    /**
     * The headings every tranche table shares, in the order x-funding.tranche-row
     * prints its cells. A page that adds an identity column prints that heading
     * itself, before this component.
     */
    $columns = [
        ['sort' => 'status', 'label' => __('common.status'), 'numeric' => false],
        ['sort' => 'account', 'label' => __('transfers.account'), 'numeric' => false],
        ['sort' => 'scheduled', 'label' => __('transfers.scheduled'), 'numeric' => false],
        ['sort' => 'sent', 'label' => __('transfers.sent_on'), 'numeric' => false],
        ['sort' => 'received', 'label' => __('transfers.received_on'), 'numeric' => false],
        ['sort' => null, 'label' => __('common.currency'), 'numeric' => false],
        ['sort' => 'amount', 'label' => __('finance_tables.amount_sent'), 'numeric' => true],
        ['sort' => 'fees', 'label' => __('transfers.fees'), 'numeric' => true],
        ['sort' => 'rate', 'label' => __('transfers.exchange_rate'), 'numeric' => true],
        ['sort' => 'gross', 'label' => __('finance_tables.gross_received'), 'numeric' => true],
        ['sort' => 'net', 'label' => __('common.net_received'), 'numeric' => true],
        ['sort' => 'difference', 'label' => __('finance_tables.difference'), 'numeric' => true],
    ];
@endphp

@foreach ($columns as $column)
    <x-funding.register.th :sort="$sortable ? $column['sort'] : null" :active="$active" :dir="$dir" :numeric="$column['numeric']">{{ $column['label'] }}</x-funding.register.th>
@endforeach

@if ($actions)
    <th scope="col"><span class="sr-only">{{ __('common.actions') }}</span></th>
@endif
```

- [x] **Step 5: Create the row component**

Create `resources/views/components/funding/tranche-row.blade.php`:

```blade
@props(['transfer'])

@php
    /** @var \App\Models\TransfersTracking $transfer */
    $state = $transfer->state();
    $sentCurrency = $transfer->amount_transfered_currency;
    $isReceived = $state === 'received';
    $difference = $transfer->difference();
    $timing = \App\Support\Tranches::timing($transfer);
    $money = fn (string|float|int $value): string => number_format((float) $value, 2);
    $date = fn (?\DateTimeInterface $value): string => $value?->translatedFormat(__('formats.date')) ?? '—';
    /* Trimming the raw decimal:6 string would turn a rate of 0 into an empty cell. */
    $rate = rtrim(rtrim(number_format((float) $transfer->exchange_rate, 6, '.', ''), '0'), '.');
    $rate = $rate === '' ? '0' : $rate;
@endphp

<tr wire:key="transfer-{{ $transfer->id }}" data-transfer="{{ $transfer->id }}" data-transfer-state="{{ $state }}">
    {{ $lead ?? '' }}

    <td data-label="{{ __('common.status') }}">
        <x-funding.state :transfer="$transfer" />
        @if ($timing !== null)
            <span @class(['sub', 'text-status-critical!' => $state === 'late'])>{{ $timing }}</span>
        @endif
    </td>

    <td data-label="{{ __('transfers.account') }}">
        @if ($transfer->account === null)
            <span class="italic text-secondary-medium dark:text-ink-muted">{{ __('transfers.no_account') }}</span>
        @else
            {{ $transfer->account->account_name }}
        @endif
    </td>

    <td class="date" data-label="{{ __('transfers.scheduled') }}">{{ $date($transfer->scheduled_date) }}</td>
    <td class="date" data-label="{{ __('transfers.sent_on') }}">{{ $date($transfer->transfer_date) }}</td>
    <td class="date" data-label="{{ __('transfers.received_on') }}">{{ $date($transfer->receive_date) }}</td>

    <td data-label="{{ __('common.currency') }}">{{ $sentCurrency->value }}</td>

    <td class="num" data-label="{{ __('finance_tables.amount_sent') }}" data-cell="sent">
        <span class="fig font-semibold">{{ $money($transfer->payment_amount) }}</span>
    </td>

    <td class="num" data-label="{{ __('transfers.fees') }}" data-cell="fees">
        @if ((float) $transfer->fees > 0)
            <span class="fig">{{ $money($transfer->fees) }}</span>
            @if ($transfer->fees_currency !== $sentCurrency)
                <span class="sub">{{ $transfer->fees_currency->value }}</span>
            @endif
        @else
            —
        @endif
    </td>

    <td class="num" data-label="{{ __('transfers.exchange_rate') }}" data-cell="rate">
        <span class="fig">{{ $rate }}</span>
    </td>

    {{-- A pending tranche may carry amounts filled in ahead of time. They have not landed, so they are not shown. --}}
    <td class="num" data-label="{{ __('finance_tables.gross_received') }}" data-cell="gross">
        @if ($isReceived && $transfer->amount_received !== null)
            <span class="fig">{{ $money($transfer->amount_received) }}</span>
        @else
            —
        @endif
    </td>

    <td class="num" data-label="{{ __('common.net_received') }}" data-cell="net">
        @if ($transfer->isMissingNetAmount())
            <span class="font-semibold text-status-warn">{{ __('transfers.not_recorded_yet') }}</span>
        @elseif ($isReceived)
            <span class="fig font-semibold text-funding">{{ $money($transfer->net_amount_received) }}</span>
            @if ($transfer->amount_received_currency !== $sentCurrency)
                <span class="sub">{{ $transfer->amount_received_currency->value }}</span>
            @endif
        @else
            —
        @endif
    </td>

    {{-- Only a settled tranche has a difference: two currencies are never subtracted. --}}
    <td class="num" data-label="{{ __('finance_tables.difference') }}" data-cell="difference">
        @if ($difference === null)
            @if ($transfer->landedInAnotherCurrency() && ! $transfer->isMissingNetAmount())
                <span class="text-xs font-semibold text-secondary-medium dark:text-ink-muted">{{ __('finance_tables.converted') }}</span>
            @else
                —
            @endif
        @elseif ($difference < 0)
            <span class="fig font-semibold text-status-critical">−{{ $money(abs($difference)) }}</span>
        @elseif ($difference > 0)
            <span class="fig font-semibold text-funding">+{{ $money($difference) }}</span>
        @else
            <span class="fig">0.00</span>
        @endif
    </td>

    {{ $actions ?? '' }}
</tr>
```

- [x] **Step 6: Create the foot component**

Create `resources/views/components/funding/tranche-foot.blade.php`:

```blade
@props(['lines' => [], 'lead' => 0, 'actions' => true, 'label' => null])

@php
    /**
     * One totals row per currency, under the columns x-funding.tranche-row
     * prints. `lead` is how many identity cells the page prints before the
     * status cell, so the label spans them plus status, account and the three
     * dates.
     *
     * @var list<array{currency: string, tranches: int, sent: float, fees: float, gross: float, net: float, shortfall: float, overage: float}> $lines
     */
    $money = fn (float $value): string => number_format($value, 2);
@endphp

@foreach ($lines as $line)
    <tr wire:key="tranche-total-{{ $line['currency'] }}" data-register-total="{{ $line['currency'] }}">
        <td colspan="{{ $lead + 5 }}" data-wide>
            {{ $label ?? __('finance_tables.total') }}
            <span class="sub">{{ trans_choice('transfers.count_tranches', $line['tranches'], ['count' => $line['tranches']]) }}</span>
        </td>
        <td data-label="{{ __('common.currency') }}">{{ $line['currency'] }}</td>
        <td class="num" data-label="{{ __('finance_tables.amount_sent') }}"><span class="fig">{{ $money($line['sent']) }}</span></td>
        <td class="num" data-label="{{ __('transfers.fees') }}"><span class="fig">{{ $money($line['fees']) }}</span></td>
        <td></td>
        <td class="num" data-label="{{ __('finance_tables.gross_received') }}"><span class="fig">{{ $money($line['gross']) }}</span></td>
        <td class="num" data-label="{{ __('common.net_received') }}"><span class="fig text-funding">{{ $money($line['net']) }}</span></td>
        {{-- An overage is real, but it never puts a shortfall back: the two are printed apart. --}}
        <td class="num" data-label="{{ __('finance_tables.difference') }}" data-total-difference>
            @if ($line['shortfall'] > 0)
                <span class="fig text-status-critical">−{{ $money($line['shortfall']) }}</span>
            @endif
            @if ($line['overage'] > 0)
                <span class="fig block text-funding">+{{ $money($line['overage']) }}</span>
            @endif
            @if ($line['shortfall'] <= 0 && $line['overage'] <= 0)
                <span class="fig">0.00</span>
            @endif
        </td>
        @if ($actions)
            <td></td>
        @endif
    </tr>
@endforeach
```

- [x] **Step 7: Create the group component**

Create `resources/views/components/funding/tranche-group.blade.php`:

```blade
@props(['label', 'lines' => [], 'lead' => 0, 'actions' => true, 'group' => ''])

@php
    /**
     * The header of one group of tranches, with the group's own subtotals:
     * one line per currency in each money cell, in the same order.
     *
     * @var iterable<array{currency: string, tranches: int, sent: float, fees: float, gross: float, net: float, shortfall: float, overage: float}> $lines
     */
    $lines = collect($lines)->map(fn ($line): array => (array) $line)->values();
    $money = fn (string|float|int $value): string => number_format((float) $value, 2);
    $count = (int) $lines->sum('tranches');
@endphp

<tr class="register-group" wire:key="group-{{ $group }}" data-group="{{ $group }}">
    <td colspan="{{ $lead + 5 }}" data-wide>
        {{ $label }}
        <span class="sub">{{ trans_choice('transfers.count_tranches', $count, ['count' => $count]) }}</span>
    </td>
    <td data-label="{{ __('common.currency') }}">
        @foreach ($lines as $line)
            <span class="block">{{ $line['currency'] }}</span>
        @endforeach
    </td>
    <td class="num" data-label="{{ __('finance_tables.amount_sent') }}">
        @foreach ($lines as $line)
            <span class="fig block">{{ $money($line['sent']) }}</span>
        @endforeach
    </td>
    <td class="num" data-label="{{ __('transfers.fees') }}">
        @foreach ($lines as $line)
            <span class="fig block">{{ $money($line['fees']) }}</span>
        @endforeach
    </td>
    <td></td>
    <td class="num" data-label="{{ __('finance_tables.gross_received') }}">
        @foreach ($lines as $line)
            <span class="fig block">{{ $money($line['gross']) }}</span>
        @endforeach
    </td>
    <td class="num" data-label="{{ __('common.net_received') }}">
        @foreach ($lines as $line)
            <span class="fig block text-funding">{{ $money($line['net']) }}</span>
        @endforeach
    </td>
    <td class="num" data-label="{{ __('finance_tables.difference') }}">
        @foreach ($lines as $line)
            <span class="block">
                @if ((float) $line['shortfall'] > 0)
                    <span class="fig text-status-critical">−{{ $money($line['shortfall']) }}</span>
                @endif
                @if ((float) $line['overage'] > 0)
                    <span class="fig text-funding">+{{ $money($line['overage']) }}</span>
                @endif
                @if ((float) $line['shortfall'] <= 0 && (float) $line['overage'] <= 0)
                    <span class="fig">0.00</span>
                @endif
            </span>
        @endforeach
    </td>
    @if ($actions)
        <td></td>
    @endif
</tr>
```

- [x] **Step 8: Run the tests to verify they pass**

Run: `.\php.cmd artisan test --compact tests/Feature/TrancheComponentsTest.php`
Expected: PASS, 7 tests.

If "Landed in 5 days" fails, check that Phase 0's `Tranches::timing()` uses `transfers.landed_in_count_days` with the days between `transfer_date` and `receive_date`.

- [x] **Step 9: Checkpoint**

Run: `.\php.cmd vendor/bin/pint --dirty --format agent`
Run: `.\php.cmd artisan test --compact tests/Feature/TrancheComponentsTest.php tests/Feature/LocalizationTest.php`
Expected: PASS. Do not commit.

---

### Task 2: Move today's ledger and ladder into a partial

A pure move. The page must look and behave exactly as before when this task ends.

**Files:**
- Create: `resources/views/pages/transfers/_overview.blade.php`
- Modify: `resources/views/pages/transfers/⚡index.blade.php` (lines 516–1133)

**Interfaces:**
- Produces: `@include('pages.transfers._overview')`. The partial reads these variables from the including view: `$stage`, `$sort`, `$filtersAreActive`, `$mutedText`, `$inkText`, `$hairline`, `$amount`, `$exact`, `$percent`, `$amountAtLeast`, `$rate`, `$sortOptions`, and `$this`.

- [x] **Step 1: Confirm the block boundaries**

Run (Bash tool):

```bash
cd /c/xampp/htdocs/hc-tracking && sed -n '516p;1132p;1133p;1134p' "resources/views/pages/transfers/⚡index.blade.php"
```

Expected output, exactly these four lines:

```
        {{-- The ledger: per currency, what left the donor against what actually arrived. --}}
            <flux:pagination :paginator="$this->transfers" class="text-secondary-medium! dark:text-ink-muted!" scroll-to="#transfer-ladder-heading" />
        @endif
    @endif
```

If they differ, stop and find the block by those lines instead: it starts at the `{{-- The ledger: …` comment and ends at the `@endif` that closes `@if ($this->transfers->isEmpty())`.

- [x] **Step 2: Create the partial from that block**

```bash
cd /c/xampp/htdocs/hc-tracking && sed -n '516,1133p' "resources/views/pages/transfers/⚡index.blade.php" > resources/views/pages/transfers/_overview.blade.php
```

- [x] **Step 3: Qualify the two class references the partial can no longer import**

A partial has no `use` statements. In `resources/views/pages/transfers/_overview.blade.php` replace both occurrences of:

```
diffInDays(CarbonImmutable::today())
```

with:

```
diffInDays(\Carbon\CarbonImmutable::today())
```

Verify nothing else needs it:

```bash
cd /c/xampp/htdocs/hc-tracking && grep -n -E "(^|[^\\\\A-Za-z])(ArStatus|Currency|CarbonImmutable|TransfersTracking|DonorProposal|Collection)::" resources/views/pages/transfers/_overview.blade.php
```

Expected: no output.

- [x] **Step 4: Put a header comment on the partial**

Insert as the first lines of `resources/views/pages/transfers/_overview.blade.php`:

```blade
{{--
    The Overview view of the transfers page: the per-currency shortfall ledger
    and the ladder of tranches. Included by ⚡index.blade.php, which defines the
    formatting closures ($amount, $exact, $percent, $amountAtLeast, $rate),
    $sortOptions and $filtersAreActive this markup reads.
--}}
```

- [x] **Step 5: Replace the block in the page with the include**

In `resources/views/pages/transfers/⚡index.blade.php`, delete lines 516–1133 and put this single line in their place:

```blade
        @include('pages.transfers._overview')
```

The surrounding code now reads:

```blade
    @if (! $this->hasAnyTransfer)
        <x-ui.empty-state
            class="rtl:[&_svg]:-scale-x-100"
            icon="arrow-right-circle"
            :heading="__('transfers.no_transfers_recorded')"
            :body="__('transfers.record_the_first_tranche_against_a_funding_agreement')"
        />
    @else
        @include('pages.transfers._overview')
    @endif
```

- [x] **Step 6: Run the existing tests to prove nothing changed**

Run: `.\php.cmd artisan test --compact tests/Feature/TransferTrackingTest.php tests/Feature/FundingWorkflowTest.php`
Expected: PASS, the same counts as before this task (24 and 14 tests).

- [x] **Step 7: Checkpoint**

Do not commit.

---

### Task 3: The register — component class, template and tests

**Files:**
- Modify: `resources/views/pages/transfers/⚡index.blade.php` (whole file)
- Create: `tests/Feature/TransferRegisterTest.php`
- Modify: `tests/Feature/TransferTrackingTest.php`

**Interfaces:**
- Consumes (Phase 0): trait `App\Livewire\Concerns\FiltersFinanceRegister` (public `$view`, `$year`, `$figures`, `$sortBy`, `$sortDir`, `$perPage`; `sortRegister(string)`, `yearOptions` computed; protected `activeSort()`, `activeSortDir()`, `applyRegisterSort(Builder)`, `registerPerPage()`, `resetRegisterFilters()`; abstract `registerSorts()`, `registerDefaultSort()`); `TransfersTracking::stateSql()`, `::settledSql()`, `::landedShortSql()`, `::missingNetSql()`, `::DATE_COLUMNS`, scopes `datedWithin()`, `converted()`; components `x-funding.filters`, `x-funding.pills`, `x-funding.filter-row`, `x-funding.totals`, `x-funding.register`, `x-funding.register.th`, `x-funding.pager`.
- Consumes (Task 1): `x-funding.tranche-head`, `x-funding.tranche-row`, `x-funding.tranche-foot`, `x-funding.tranche-group`.
- Produces (used by Phases 3 and 6 as link targets): the page accepts `?account={id}`, `?agreement={id}`, `?edit={id}`, `?view=overview`.
- Produces: public properties `search`, `donor`, `account`, `currency`, `stage`, `flag`, `dateBasis`, `from`, `to`, `min`, `max`, `group`, `sort`, `agreement`; computed `rows`, `totals`, `stageCounts`, `groupTotals`, `ledger`, `transfers`, `filterDonors`, `filterAccounts`; actions `toggleStage`, `toggleFlag`, `toggleAttentionOrder`, `setSort`, `clearFilters`.

- [x] **Step 1: Write the failing tests**

Create `tests/Feature/TransferRegisterTest.php`:

```php
<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\Account;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->role('Financial')->create();
});

/**
 * The transfers page as the finance manager opens it, optionally from a URL.
 *
 * @param  array<string, string>  $query
 */
function transferRegister(array $query = []): Testable
{
    return Livewire::actingAs(test()->user)->withQueryParams($query)->test('pages::transfers.index');
}

/**
 * A USD tranche that finished its journey: 1,000 sent, 995 gross, 985 net,
 * 10 of it in fees.
 *
 * @param  array<string, mixed>  $overrides
 */
function registerSettledTranche(array $overrides = []): TransfersTracking
{
    return TransfersTracking::factory()->create([
        'payment_amount' => '1000.00',
        'amount_transfered_currency' => Currency::Usd,
        'fees' => '10.00',
        'fees_currency' => Currency::Usd,
        'amount_received' => '995.00',
        'net_amount_received' => '985.00',
        'amount_received_currency' => Currency::Usd,
        'scheduled_date' => '2026-03-01',
        'transfer_date' => '2026-03-02',
        'receive_date' => '2026-03-05',
        'ar_status' => ArStatus::Received,
        ...$overrides,
    ]);
}

/**
 * A USD tranche of 500 nobody has sent yet, due next month.
 *
 * @param  array<string, mixed>  $overrides
 */
function registerPendingTranche(array $overrides = []): TransfersTracking
{
    return TransfersTracking::factory()->create([
        'payment_amount' => '500.00',
        'amount_transfered_currency' => Currency::Usd,
        'scheduled_date' => now()->addMonth()->toDateString(),
        'transfer_date' => null,
        'receive_date' => null,
        'ar_status' => ArStatus::Pending,
        ...$overrides,
    ]);
}

test('the page opens on the table, newest scheduled date first and undated last', function () {
    $january = registerPendingTranche(['scheduled_date' => '2026-01-10']);
    $undated = registerPendingTranche(['scheduled_date' => null]);
    $may = registerPendingTranche(['scheduled_date' => '2026-05-10']);

    $page = transferRegister()
        ->assertSet('view', 'table')
        ->assertSeeHtml('data-register')
        ->assertDontSeeHtml('data-ledger-currency');

    expect($page->instance()->rows->pluck('id')->all())->toBe([$may->id, $january->id, $undated->id]);
});

test('the overview view shows the ledger and the ladder instead of the table', function () {
    registerSettledTranche();

    transferRegister()
        ->set('view', 'overview')
        ->assertSeeHtml('data-ledger-currency="USD"')
        ->assertDontSeeHtml('data-register');
});

test('a table row shows fees, rate, gross, net and the difference without expanding anything', function () {
    registerSettledTranche(['exchange_rate' => '0.925000']);

    transferRegister()
        ->assertSee('1,000.00')
        ->assertSee('10.00')
        ->assertSee('0.925')
        ->assertSee('995.00')
        ->assertSee('985.00')
        ->assertSee('−15.00')
        ->assertDontSeeHtml('aria-controls="transfer-detail-');
});

test('the donor, currency, account and agreement filters each narrow the rows', function () {
    $unicef = Donor::factory()->create(['name' => 'UNICEF']);
    $echo = Donor::factory()->create(['name' => 'ECHO']);
    $operations = Account::factory()->create(['account_name' => 'Operations USD']);
    $dollars = DonorProposal::factory()->create(['donor_id' => $unicef->id, 'currency' => Currency::Usd]);
    $euros = DonorProposal::factory()->create(['donor_id' => $echo->id, 'currency' => Currency::Eur]);

    $usd = registerPendingTranche(['donor_proposal_id' => $dollars->id, 'account_id' => $operations->id]);
    $eur = registerPendingTranche(['donor_proposal_id' => $euros->id, 'amount_transfered_currency' => Currency::Eur]);

    $ids = fn (Testable $page): array => $page->instance()->rows->pluck('id')->all();

    expect($ids(transferRegister()->set('donor', (string) $unicef->id)))->toBe([$usd->id])
        ->and($ids(transferRegister()->set('currency', 'EUR')))->toBe([$eur->id])
        ->and($ids(transferRegister()->set('account', (string) $operations->id)))->toBe([$usd->id])
        ->and($ids(transferRegister(['agreement' => (string) $euros->id])))->toBe([$eur->id]);
});

test('the amount range and the flags narrow the rows', function () {
    $small = registerPendingTranche(['payment_amount' => '100.00']);
    $large = registerPendingTranche(['payment_amount' => '9000.00', 'account_id' => Account::factory()->create()->id]);
    $converted = registerSettledTranche(['amount_received_currency' => Currency::Eur, 'account_id' => Account::factory()->create()->id]);

    $ids = fn (Testable $page): array => $page->instance()->rows->pluck('id')->sort()->values()->all();

    expect($ids(transferRegister()->set('min', '5000')))->toBe([$large->id])
        ->and($ids(transferRegister()->set('max', '100')))->toBe([$small->id])
        ->and($ids(transferRegister()->call('toggleFlag', 'converted')))->toBe([$converted->id])
        ->and($ids(transferRegister()->call('toggleFlag', 'no_account')))->toBe([$small->id]);

    transferRegister()->call('toggleFlag', 'converted')->call('toggleFlag', 'converted')->assertSet('flag', '');
});

test('the date range reads the date the basis names', function () {
    $tranche = registerSettledTranche([
        'scheduled_date' => '2025-12-20',
        'transfer_date' => '2026-01-05',
        'receive_date' => '2026-02-10',
    ]);

    $count = fn (string $basis, string $from, string $to): int => transferRegister()
        ->set('dateBasis', $basis)
        ->set('from', $from)
        ->set('to', $to)
        ->instance()->rows->total();

    expect($count('scheduled', '2025-12-01', '2025-12-31'))->toBe(1)
        ->and($count('scheduled', '2026-01-01', '2026-12-31'))->toBe(0)
        ->and($count('sent', '2026-01-01', '2026-01-31'))->toBe(1)
        ->and($count('received', '2026-02-01', '2026-02-28'))->toBe(1)
        ->and($count('received', '2026-01-01', '2026-01-31'))->toBe(0);
});

test('a year pill fills the date range and a custom range clears the year', function () {
    registerSettledTranche(['scheduled_date' => '2025-06-01']);
    registerSettledTranche(['scheduled_date' => '2026-06-01']);

    $page = transferRegister()
        ->set('year', '2026')
        ->assertSet('from', '2026-01-01')
        ->assertSet('to', '2026-12-31');

    expect($page->instance()->rows->total())->toBe(1);

    $page->set('to', '2026-06-30')->assertSet('year', '');
    $page->set('year', '2025')->set('year', '')->assertSet('from', '')->assertSet('to', '');

    transferRegister(['year' => '2025'])->assertSet('from', '2025-01-01')->assertSet('to', '2025-12-31');
});

test('values a visitor types into the address bar fall back to the defaults', function () {
    registerPendingTranche();

    transferRegister([
        'view' => 'grid', 'stage' => 'nowhere', 'flag' => 'nothing', 'group' => 'planet', 'dateBasis' => 'never',
        'currency' => 'GBP', 'from' => '2026-13-40', 'to' => 'soon', 'min' => 'abc', 'max' => '-5',
        'sortBy' => 'id; drop table', 'sortDir' => 'sideways', 'perPage' => '7', 'donor' => 'x', 'account' => '1 or 1', 'sort' => 'random',
    ])
        ->assertSet('view', 'table')
        ->assertSet('stage', '')
        ->assertSet('flag', '')
        ->assertSet('group', '')
        ->assertSet('dateBasis', 'scheduled')
        ->assertSet('currency', '')
        ->assertSet('from', '')
        ->assertSet('to', '')
        ->assertSet('min', '')
        ->assertSet('max', '')
        ->assertSet('sortBy', '')
        ->assertSet('sortDir', '')
        ->assertSet('perPage', '25')
        ->assertSet('donor', '')
        ->assertSet('account', '')
        ->assertSet('sort', 'attention')
        ->assertOk();
});

test('an old receipt-status bookmark still narrows to that status', function () {
    $received = registerSettledTranche();
    registerPendingTranche();

    $page = transferRegister(['arStatus' => 'received'])->assertSet('stage', 'received');

    expect($page->instance()->rows->pluck('id')->all())->toBe([$received->id]);
});

test('table totals follow the status filter while the overview ledger keeps describing the whole set', function () {
    registerSettledTranche();
    registerPendingTranche();

    $page = transferRegister()->call('toggleStage', 'received');

    $totals = $page->instance()->totals->firstWhere('currency', 'USD');
    $ledger = $page->instance()->ledger->firstWhere('currency', 'USD');

    expect($totals['tranches'])->toBe(1)
        ->and($totals['expected'])->toBe(1000.0)
        ->and($totals['landed'])->toBe(985.0)
        ->and($totals['gross'])->toBe(995.0)
        ->and($totals['recordedFees'])->toBe(10.0)
        ->and($totals['shrink'])->toBe(15.0)
        ->and($ledger['expected'])->toBe(1500.0)
        ->and($ledger['tranches'])->toBe(2);
});

test('the status pill counts ignore the status filter and nothing else', function () {
    registerSettledTranche();
    registerSettledTranche(['net_amount_received' => null, 'amount_received' => null]);
    registerPendingTranche();
    registerPendingTranche(['scheduled_date' => now()->subMonth()->toDateString()]);
    registerPendingTranche(['amount_transfered_currency' => Currency::Eur]);

    $counts = transferRegister()->set('currency', 'USD')->call('toggleStage', 'overdue')->instance()->stageCounts;

    expect($counts)->toMatchArray([
        'all' => 4,
        'received' => 2,
        'upcoming' => 1,
        'overdue' => 1,
        'transit' => 0,
        'failed' => 0,
        'short' => 1,
        'unrecorded' => 1,
    ]);
});

test('table totals never add currencies together and never net an overage against a shortfall', function () {
    registerSettledTranche(['payment_amount' => '100000.00', 'fees' => '0.00', 'amount_received' => '90000.00', 'net_amount_received' => '90000.00']);
    registerSettledTranche(['payment_amount' => '50000.00', 'fees' => '0.00', 'amount_received' => '80000.00', 'net_amount_received' => '80000.00']);
    registerSettledTranche([
        'payment_amount' => '7000.00',
        'amount_transfered_currency' => Currency::Eur,
        'fees_currency' => Currency::Eur,
        'amount_received_currency' => Currency::Eur,
        'amount_received' => '7000.00',
        'net_amount_received' => '7000.00',
        'fees' => '0.00',
    ]);

    $page = transferRegister();
    $totals = $page->instance()->totals->keyBy('currency');

    expect($totals->keys()->all())->toBe(['USD', 'EUR'])
        ->and($totals['USD']['expected'])->toBe(150000.0)
        ->and($totals['USD']['shrink'])->toBe(10000.0)
        ->and($totals['USD']['overage'])->toBe(30000.0)
        ->and($totals['EUR']['expected'])->toBe(7000.0);

    $page->assertSeeHtml('data-register-total="USD"')
        ->assertSeeHtml('data-register-total="EUR"')
        ->assertSee('−10,000.00')
        ->assertSee('+30,000.00')
        ->assertDontSee('157,000.00');
});

test('a column heading sorts the rows and a second click reverses them', function () {
    $small = registerPendingTranche(['payment_amount' => '100.00', 'scheduled_date' => '2026-05-01']);
    $large = registerPendingTranche(['payment_amount' => '9000.00', 'scheduled_date' => '2026-01-01']);
    $euro = registerPendingTranche(['payment_amount' => '50000.00', 'amount_transfered_currency' => Currency::Eur, 'scheduled_date' => '2026-03-01']);

    $ids = fn (Testable $page): array => $page->instance()->rows->pluck('id')->all();

    $page = transferRegister()->call('sortRegister', 'amount');

    // Amounts sort inside their currency, so a EUR figure is never ranked against a USD one.
    expect($ids($page))->toBe([$euro->id, $large->id, $small->id]);

    $page->call('sortRegister', 'amount')->assertSet('sortDir', 'asc');

    expect($ids($page))->toBe([$euro->id, $small->id, $large->id]);

    $page->assertSeeHtml('aria-sort="ascending"');
});

test('needs attention first leads with overdue money and turns off again', function () {
    $upcoming = registerPendingTranche(['scheduled_date' => now()->addMonths(2)->toDateString()]);
    $overdue = registerPendingTranche(['scheduled_date' => now()->subMonth()->toDateString()]);

    $page = transferRegister()->call('toggleAttentionOrder')->assertSet('sortBy', 'attention');

    expect($page->instance()->rows->pluck('id')->all())->toBe([$overdue->id, $upcoming->id]);

    $page->call('toggleAttentionOrder')->assertSet('sortBy', '');

    expect($page->instance()->rows->pluck('id')->all())->toBe([$upcoming->id, $overdue->id]);
});

test('a group subtotal covers the whole group even when the group runs onto the next page', function () {
    $donor = Donor::factory()->create(['name' => 'Alpha Fund']);
    $agreement = DonorProposal::factory()->create(['donor_id' => $donor->id, 'currency' => Currency::Usd]);

    TransfersTracking::factory()->count(26)->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '100.00',
        'amount_transfered_currency' => Currency::Usd,
        'scheduled_date' => '2026-02-01',
        'transfer_date' => null,
        'ar_status' => ArStatus::Pending,
    ]);

    $page = transferRegister()->set('group', 'donor');

    expect($page->instance()->rows)->toHaveCount(25)
        ->and((float) $page->instance()->groupTotals->get((string) $donor->id)->first()->sent)->toBe(2600.0);

    $page->assertSeeHtml('data-group="'.$donor->id.'"')
        ->assertSee('Alpha Fund')
        ->assertSee('2,600.00');
});

test('grouping by month follows the chosen date basis and files undated tranches together', function () {
    registerSettledTranche(['scheduled_date' => '2026-03-01', 'receive_date' => '2026-04-02', 'transfer_date' => '2026-03-02']);
    registerPendingTranche(['scheduled_date' => null]);

    $byScheduled = transferRegister()->set('group', 'month')->instance()->groupTotals->keys()->sort()->values()->all();
    $byReceived = transferRegister()->set('dateBasis', 'received')->set('group', 'month')->instance()->groupTotals->keys()->sort()->values()->all();

    expect($byScheduled)->toBe(['', '2026-03'])
        ->and($byReceived)->toBe(['', '2026-04']);
});

test('the page size can be changed and an unknown size is ignored', function () {
    TransfersTracking::factory()->count(30)->create(['ar_status' => ArStatus::Pending, 'transfer_date' => null]);

    $page = transferRegister();

    expect($page->instance()->rows)->toHaveCount(25);

    $page->set('perPage', '50');
    expect($page->instance()->rows)->toHaveCount(30);

    $page->set('perPage', '5000')->assertSet('perPage', '25');
});

test('reset filters clears every filter and keeps the view, the grouping and the sort', function () {
    registerPendingTranche();

    transferRegister()
        ->set('view', 'overview')
        ->set('group', 'donor')
        ->set('currency', 'USD')
        ->set('min', '10')
        ->set('year', '2026')
        ->call('toggleStage', 'upcoming')
        ->call('toggleFlag', 'no_account')
        ->call('clearFilters')
        ->assertSet('currency', '')
        ->assertSet('min', '')
        ->assertSet('year', '')
        ->assertSet('from', '')
        ->assertSet('stage', '')
        ->assertSet('flag', '')
        ->assertSet('view', 'overview')
        ->assertSet('group', 'donor');
});

test('a viewer without the update permission gets the table with no edit buttons', function () {
    $viewer = User::factory()->role('Managers')->create()->givePermissionTo('transfers.view');
    $tranche = registerPendingTranche();

    Livewire::actingAs($viewer)->test('pages::transfers.index')
        ->assertSeeHtml('data-transfer="'.$tranche->id.'"')
        ->assertDontSeeHtml('data-test="edit-transfer-'.$tranche->id.'"')
        ->assertDontSeeHtml('data-test="create-transfer-button"');
});

test('a row links to its donor, proposal and agreement only for people who may open them', function () {
    $tranche = registerPendingTranche();
    $agreement = $tranche->donorProposal;
    $viewer = User::factory()->role('Managers')->create()->givePermissionTo('transfers.view');

    transferRegister()
        ->assertSeeHtml('href="'.route('donors.show', $agreement->donor).'"')
        ->assertSeeHtml('href="'.route('proposals.show', $agreement->proposal).'"')
        ->assertSeeHtml('href="'.route('funding-agreements.show', $agreement).'"');

    Livewire::actingAs($viewer)->test('pages::transfers.index')
        ->assertSee($agreement->donor->name)
        ->assertDontSeeHtml('href="'.route('donors.show', $agreement->donor).'"')
        ->assertDontSeeHtml('href="'.route('funding-agreements.show', $agreement).'"');
});
```

- [x] **Step 2: Run the tests to verify they fail**

Run: `.\php.cmd artisan test --compact tests/Feature/TransferRegisterTest.php`
Expected: FAIL — the first failure is `Failed asserting that an array has the key 'view'` or `Property [$view] not found on component`.

- [x] **Step 3: Replace the component class**

In `resources/views/pages/transfers/⚡index.blade.php` replace everything from the opening `<?php` through the closing `?>` (the whole PHP block) with:

```php
<?php

use App\Enums\Currency;
use App\Livewire\Concerns\FiltersFinanceRegister;
use App\Livewire\Concerns\ManagesTransferForm;
use App\Models\Account;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use App\Support\Like;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.transfers')] class extends Component {
    use FiltersFinanceRegister;
    use ManagesTransferForm;
    use WithPagination;

    /**
     * How the Overview ladder may be ranked. `attention` leads with money that
     * has misbehaved.
     *
     * @var list<string>
     */
    public const SORTS = ['attention', 'shortfall', 'date', 'amount'];

    /**
     * Where a tranche's money can be: the five states every funding page
     * shares (overdue is the model's `late`), plus the two conditions only a
     * received tranche can be in — landed short of what was sent, and received
     * without anyone saying what arrived.
     *
     * @var list<string>
     */
    public const STAGES = ['overdue', 'transit', 'upcoming', 'received', 'failed', 'short', 'unrecorded'];

    /** @var list<string> */
    public const FLAGS = ['converted', 'no_account'];

    /** @var list<string> */
    public const GROUPS = ['agreement', 'donor', 'account', 'month'];

    /** @var list<string> */
    public const DATE_BASES = ['scheduled', 'sent', 'received'];

    /**
     * Every property a filter control writes. A change to any of them is
     * normalised and sends the table back to its first page.
     *
     * @var list<string>
     */
    private const FILTERS = ['search', 'donor', 'account', 'currency', 'stage', 'flag', 'dateBasis', 'from', 'to', 'min', 'max', 'group', 'agreement', 'year'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $donor = '';

    #[Url(except: '')]
    public string $account = '';

    #[Url(except: '')]
    public string $currency = '';

    #[Url(except: '')]
    public string $stage = '';

    #[Url(except: '')]
    public string $flag = '';

    /** Which of a tranche's three dates the date range and the month grouping read. */
    #[Url(except: 'scheduled')]
    public string $dateBasis = 'scheduled';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: '')]
    public string $min = '';

    #[Url(except: '')]
    public string $max = '';

    #[Url(except: '')]
    public string $group = '';

    /** The Overview ladder's order. The table sorts by its column headings instead. */
    #[Url(except: 'attention')]
    public string $sort = 'attention';

    /**
     * One funding agreement to narrow the page to, set by the "see in the
     * transfers ledger" link on an agreement page.
     */
    #[Url(except: '')]
    public string $agreement = '';

    /**
     * Deep link from a notification's "Mark received": opens that tranche's
     * edit form once, then clears itself.
     */
    #[Url(as: 'edit', except: '')]
    public string $editTransfer = '';

    /**
     * Everything here arrives from the URL, so a hand-typed value would
     * otherwise leave a filter applied with no control showing it. Normalise
     * on the way in.
     */
    public function mount(): void
    {
        // A bookmark from before the status pills: ?arStatus=received|failed.
        $legacyStatus = (string) request()->query('arStatus', '');

        if ($this->stage === '' && in_array($legacyStatus, ['received', 'failed'], true)) {
            $this->stage = $legacyStatus;
        }

        $this->normaliseFilters();
        $this->syncRangeWithYear();

        if (ctype_digit($this->editTransfer)) {
            $this->openTransferEdit((int) $this->editTransfer);
        }

        $this->editTransfer = '';
    }

    public function updated(string $property): void
    {
        if (! in_array($property, self::FILTERS, true)) {
            return;
        }

        if ($property === 'year') {
            [$this->from, $this->to] = $this->yearBounds($this->year) ?? ['', ''];
        }

        $this->normaliseFilters();
        $this->syncRangeWithYear();
        $this->resetPage();
    }

    protected function normaliseFilters(): void
    {
        if (! in_array($this->sort, self::SORTS, true)) {
            $this->sort = 'attention';
        }

        if (! in_array($this->stage, self::STAGES, true)) {
            $this->stage = '';
        }

        if (! in_array($this->flag, self::FLAGS, true)) {
            $this->flag = '';
        }

        if (! in_array($this->group, self::GROUPS, true)) {
            $this->group = '';
        }

        if (! in_array($this->dateBasis, self::DATE_BASES, true)) {
            $this->dateBasis = 'scheduled';
        }

        if (Currency::tryFrom($this->currency) === null) {
            $this->currency = '';
        }

        foreach (['donor', 'account', 'agreement'] as $idFilter) {
            if (! ctype_digit($this->{$idFilter})) {
                $this->{$idFilter} = '';
            }
        }

        $this->from = $this->cleanDate($this->from);
        $this->to = $this->cleanDate($this->to);
        $this->min = $this->cleanAmount($this->min);
        $this->max = $this->cleanAmount($this->max);
    }

    /**
     * On this page a year is a shortcut for a date range, not a filter of its
     * own: choosing one fills the range, and a range that is no longer that
     * whole year lets go of it.
     */
    protected function syncRangeWithYear(): void
    {
        $bounds = $this->yearBounds($this->year);

        if ($bounds === null) {
            $this->year = '';

            return;
        }

        if ($this->from === '' && $this->to === '') {
            [$this->from, $this->to] = $bounds;

            return;
        }

        if ([$this->from, $this->to] !== $bounds) {
            $this->year = '';
        }
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    protected function yearBounds(string $year): ?array
    {
        return preg_match('/^(19|20|21)\d{2}$/', $year) === 1 ? ["{$year}-01-01", "{$year}-12-31"] : null;
    }

    /**
     * A real calendar date in Y-m-d, or nothing.
     */
    protected function cleanDate(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }

    /**
     * An amount the payment column can hold, or nothing.
     */
    protected function cleanAmount(string $value): string
    {
        return is_numeric($value) && (float) $value >= 0 && (float) $value <= self::MAX_AMOUNT ? $value : '';
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'donor', 'account', 'currency', 'stage', 'flag', 'dateBasis', 'from', 'to', 'min', 'max', 'agreement']);
        $this->resetRegisterFilters();
        $this->resetPage();
    }

    /**
     * The agreement the page is narrowed to, for the filter chip.
     */
    #[Computed]
    public function agreementFilter(): ?DonorProposal
    {
        $agreementId = (int) $this->agreement;

        return $agreementId > 0
            ? DonorProposal::query()->with(['donor:id,name', 'proposal:id,name'])->find($agreementId, ['id', 'donor_id', 'proposal_id', 'project_num'])
            : null;
    }

    /**
     * The donors that hold an agreement, for the donor picker.
     *
     * @return EloquentCollection<int, Donor>
     */
    #[Computed]
    public function filterDonors(): EloquentCollection
    {
        return Donor::query()->has('donorProposals')->orderBy('name')->orderBy('id')->get(['id', 'name']);
    }

    /**
     * Every account, open or closed, for the account picker. The rows already
     * name the account each tranche lands in, so the list shows nothing new.
     *
     * @return EloquentCollection<int, Account>
     */
    #[Computed]
    public function filterAccounts(): EloquentCollection
    {
        return Account::query()->orderBy('account_name')->orderBy('id')->get(['id', 'account_name', 'is_active']);
    }

    /**
     * Narrow to one stage, or clear it by choosing it again.
     */
    public function toggleStage(string $stage): void
    {
        $this->stage = $this->stage === $stage || ! in_array($stage, self::STAGES, true) ? '' : $stage;
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
     * The Overview ladder's order.
     */
    public function setSort(string $sort): void
    {
        $this->sort = in_array($sort, self::SORTS, true) ? $sort : 'attention';
        $this->resetPage();
    }

    /**
     * Rank the table by what needs chasing, or go back to its default order.
     */
    public function toggleAttentionOrder(): void
    {
        [$this->sortBy, $this->sortDir] = $this->activeSort() === 'attention' ? ['', ''] : ['attention', 'asc'];
        $this->resetPage();
    }

    /**
     * A tranche whose journey has finished and whose two ends can honestly be
     * compared. One definition, on the model, for every page.
     */
    protected function settledSql(): string
    {
        return TransfersTracking::settledSql();
    }

    /**
     * Stage conditions as SQL, keyed by stage. Each one is the model's own
     * definition, so a filter, a count and a total read a tranche the same way
     * its row does.
     *
     * @return array<string, string>
     */
    protected function stageSql(): array
    {
        return [
            'overdue' => TransfersTracking::stateSql('late'),
            'transit' => TransfersTracking::stateSql('transit'),
            'upcoming' => TransfersTracking::stateSql('upcoming'),
            'received' => TransfersTracking::stateSql('received'),
            'failed' => TransfersTracking::stateSql('failed'),
            'short' => TransfersTracking::landedShortSql(),
            'unrecorded' => TransfersTracking::missingNetSql(),
        ];
    }

    /**
     * Tranches matching every active filter, with no ordering and no eager
     * loads, so the table, its totals, the Overview ledger and the export all
     * build on it.
     *
     * The Overview ledger and the status counts pass $withStage false so they
     * keep describing the whole filtered set while a stage narrows the rows.
     *
     * @return Builder<TransfersTracking>
     */
    protected function filteredQuery(bool $withStage = true): Builder
    {
        $search = trim($this->search);
        $stages = $this->stageSql();

        return TransfersTracking::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->whereHas('donorProposal', function (Builder $query) use ($search): void {
                    $query
                        ->where('project_num', 'like', Like::contains($search))
                        ->orWhereHas('donor', fn (Builder $query) => $query->where('name', 'like', Like::contains($search)))
                        ->orWhereHas('proposal', fn (Builder $query) => $query->where('name', 'like', Like::contains($search)));
                });
            })
            ->when($this->donor !== '', fn (Builder $query) => $query->whereHas('donorProposal', fn (Builder $query) => $query->where('donor_id', (int) $this->donor)))
            ->when($this->account !== '', fn (Builder $query) => $query->where('transfers_tracking.account_id', (int) $this->account))
            ->when((int) $this->agreement > 0, fn (Builder $query) => $query->where('transfers_tracking.donor_proposal_id', (int) $this->agreement))
            ->when($this->currency !== '', fn (Builder $query) => $query->where('transfers_tracking.amount_transfered_currency', $this->currency))
            ->when($this->from !== '' || $this->to !== '', fn (Builder $query) => $query->datedWithin($this->dateBasis, $this->from ?: null, $this->to ?: null))
            ->when($this->min !== '', fn (Builder $query) => $query->where('transfers_tracking.payment_amount', '>=', (float) $this->min))
            ->when($this->max !== '', fn (Builder $query) => $query->where('transfers_tracking.payment_amount', '<=', (float) $this->max))
            ->when($this->flag === 'converted', fn (Builder $query) => $query->converted())
            ->when($this->flag === 'no_account', fn (Builder $query) => $query->whereNull('transfers_tracking.account_id'))
            ->when(
                $withStage && array_key_exists($this->stage, $stages),
                fn (Builder $query) => $query->whereRaw($stages[$this->stage]),
            );
    }

    /**
     * The filtered tranches joined to the records their rows name, so the
     * table can be sorted and grouped by donor, proposal and account.
     *
     * @return Builder<TransfersTracking>
     */
    protected function registerQuery(): Builder
    {
        return $this->filteredQuery()
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->join('donors', 'donors.id', '=', 'donor_proposal.donor_id')
            ->join('proposals', 'proposals.id', '=', 'donor_proposal.proposal_id')
            ->leftJoin('accounts', 'accounts.id', '=', 'transfers_tracking.account_id');
    }

    /**
     * What each column heading sorts by. Dates always put a missing date last;
     * amounts sort inside their currency, so figures in different currencies
     * are never ranked against each other.
     *
     * @return array<string, array{dir: 'asc'|'desc', order: list<string>}>
     */
    protected function registerSorts(): array
    {
        $table = 'transfers_tracking';
        $stages = $this->stageSql();
        $settled = $this->settledSql();
        $lastById = "{$table}.id desc";

        $byDate = fn (string $column): array => ["{$table}.{$column} is null", "{$table}.{$column} {dir}", $lastById];
        $byAmount = fn (string $currency, string $amount): array => ["{$table}.{$amount} is null", "{$table}.{$currency} asc", "{$table}.{$amount} {dir}", $lastById];

        return [
            'scheduled' => ['dir' => 'desc', 'order' => $byDate('scheduled_date')],
            'sent' => ['dir' => 'desc', 'order' => $byDate('transfer_date')],
            'received' => ['dir' => 'desc', 'order' => $byDate('receive_date')],
            'donor' => ['dir' => 'asc', 'order' => ['donors.name {dir}', 'proposals.name {dir}', $lastById]],
            'account' => ['dir' => 'asc', 'order' => ['accounts.account_name is null', 'accounts.account_name {dir}', $lastById]],
            'status' => ['dir' => 'asc', 'order' => [
                "case when {$stages['overdue']} then 0 when {$stages['failed']} then 1 when {$stages['transit']} then 2 when {$stages['upcoming']} then 3 else 4 end {dir}",
                "{$table}.scheduled_date is null",
                "{$table}.scheduled_date asc",
                $lastById,
            ]],
            'amount' => ['dir' => 'desc', 'order' => $byAmount('amount_transfered_currency', 'payment_amount')],
            'fees' => ['dir' => 'desc', 'order' => $byAmount('fees_currency', 'fees')],
            'rate' => ['dir' => 'desc', 'order' => ["{$table}.exchange_rate {dir}", $lastById]],
            'gross' => ['dir' => 'desc', 'order' => $byAmount('amount_received_currency', 'amount_received')],
            'net' => ['dir' => 'desc', 'order' => $byAmount('amount_received_currency', 'net_amount_received')],
            // Largest loss first. A tranche with no comparable ends sorts last
            // rather than passing for one that lost nothing.
            'difference' => ['dir' => 'asc', 'order' => [
                "case when {$settled} then 0 else 1 end",
                "{$table}.amount_transfered_currency asc",
                "({$table}.net_amount_received - {$table}.payment_amount) {dir}",
                $lastById,
            ]],
            // Overdue first and oldest-overdue first inside it, then outright
            // failures, then money that landed short, then money still moving.
            'attention' => ['dir' => 'asc', 'order' => [
                "case when {$stages['overdue']} then 0 when {$stages['failed']} then 1 when {$stages['short']} then 2 when {$stages['unrecorded']} then 3 when {$stages['transit']} then 4 else 5 end {dir}",
                "{$table}.scheduled_date is null",
                "{$table}.scheduled_date asc",
                $lastById,
            ]],
        ];
    }

    protected function registerDefaultSort(): string
    {
        return 'scheduled';
    }

    /**
     * The date column the date range and the month grouping read.
     */
    protected function dateColumn(): string
    {
        return 'transfers_tracking.'.TransfersTracking::DATE_COLUMNS[$this->dateBasis];
    }

    /**
     * The SQL expression whose value a group shares, or null when the table is
     * not grouped.
     */
    protected function groupKeySql(): ?string
    {
        return match ($this->group) {
            'agreement' => 'transfers_tracking.donor_proposal_id',
            'donor' => 'donor_proposal.donor_id',
            'account' => 'coalesce(transfers_tracking.account_id, 0)',
            'month' => "coalesce(date_format({$this->dateColumn()}, '%Y-%m'), '')",
            default => null,
        };
    }

    /**
     * Keep each group's rows together, ahead of the chosen sort.
     *
     * @param  Builder<TransfersTracking>  $query
     * @return Builder<TransfersTracking>
     */
    protected function groupOrder(Builder $query): Builder
    {
        return match ($this->group) {
            'agreement' => $query->orderBy('donors.name')->orderBy('proposals.name')->orderBy('transfers_tracking.donor_proposal_id'),
            'donor' => $query->orderBy('donors.name')->orderBy('donor_proposal.donor_id'),
            'account' => $query->orderByRaw('accounts.account_name is null')->orderBy('accounts.account_name')->orderBy('transfers_tracking.account_id'),
            'month' => $query->orderByRaw("{$this->dateColumn()} is null")->orderByRaw("date_format({$this->dateColumn()}, '%Y-%m') desc"),
            default => $query,
        };
    }

    /**
     * The group a row belongs to: the same value groupKeySql() gives it.
     */
    protected function groupKeyOf(TransfersTracking $transfer): string
    {
        return match ($this->group) {
            'agreement' => (string) $transfer->donor_proposal_id,
            'donor' => (string) $transfer->donorProposal->donor_id,
            'account' => (string) ($transfer->account_id ?? 0),
            'month' => $transfer->{TransfersTracking::DATE_COLUMNS[$this->dateBasis]}?->format('Y-m') ?? '',
            default => '',
        };
    }

    /**
     * What a group's header row calls it.
     */
    protected function groupLabelOf(TransfersTracking $transfer): string
    {
        $agreement = $transfer->donorProposal;

        return match ($this->group) {
            'agreement' => collect([$agreement->donor->name, $agreement->proposal->name, $agreement->project_num])->filter()->implode(' · '),
            'donor' => $agreement->donor->name,
            'account' => $transfer->account?->account_name ?? __('transfers.no_account'),
            'month' => $transfer->{TransfersTracking::DATE_COLUMNS[$this->dateBasis]}?->translatedFormat(__('formats.month_year')) ?? __('finance_tables.no_date'),
            default => '',
        };
    }

    /**
     * The table's visible page.
     *
     * @return LengthAwarePaginator<int, TransfersTracking>
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $query = $this->registerQuery()
            ->select('transfers_tracking.*')
            ->with([
                'donorProposal:id,slug,donor_id,proposal_id,project_num',
                'donorProposal.donor:id,slug,name',
                'donorProposal.proposal:id,slug,name',
                // Nullable: a tranche may be scheduled before an account exists.
                'account:id,account_name',
            ]);

        return $this->applyRegisterSort($this->groupOrder($query))->paginate($this->registerPerPage());
    }

    /**
     * The Overview ladder's order.
     *
     * @param  Builder<TransfersTracking>  $query
     * @return Builder<TransfersTracking>
     */
    protected function applySort(Builder $query): Builder
    {
        $settled = $this->settledSql();
        $stages = $this->stageSql();

        $sorted = match ($this->sort) {
            // Proportional loss, so a small tranche bled dry outranks a large
            // one that lost a rounding error. Tranches with no comparable end
            // sort last rather than masquerading as losing nothing.
            'shortfall' => $query
                ->orderByRaw("case when {$settled} and payment_amount > 0 then (payment_amount - net_amount_received) / payment_amount else -1 end desc")
                ->orderByDesc('payment_amount'),
            'amount' => $query->orderByDesc('payment_amount'),
            'date' => $query->orderByRaw('scheduled_date is null, scheduled_date desc'),
            // Overdue first and oldest-overdue first inside it, then outright
            // failures, then money that landed short, then money still moving.
            default => $query
                ->orderByRaw("case
                    when {$stages['overdue']} then 0
                    when {$stages['failed']} then 1
                    when {$stages['short']} then 2
                    when {$stages['unrecorded']} then 3
                    when {$stages['transit']} then 4
                    else 5 end")
                ->orderByRaw('scheduled_date is null, scheduled_date asc'),
        };

        return $sorted->orderByDesc('id');
    }

    /**
     * The Overview ladder's visible page.
     *
     * @return LengthAwarePaginator<int, TransfersTracking>
     */
    #[Computed]
    public function transfers(): LengthAwarePaginator
    {
        $query = $this->filteredQuery()
            ->select([
                'id', 'donor_proposal_id', 'account_id', 'payment_amount', 'amount_transfered_currency',
                'scheduled_date', 'transfer_date', 'fees', 'fees_currency', 'amount_received',
                'exchange_rate', 'net_amount_received', 'amount_received_currency', 'receive_date', 'ar_status',
            ])
            ->with([
                'donorProposal:id,donor_id,proposal_id,project_num',
                'donorProposal.donor:id,name',
                'donorProposal.proposal:id,name',
                // Nullable: a tranche may be scheduled before an account exists.
                'account:id,account_name,is_active',
            ]);

        return $this->applySort($query)->paginate(20);
    }

    /**
     * One line per currency money was sent in: what left the donor, what
     * actually landed, and what the journey took out of it.
     *
     * Every figure is a grouped aggregate so the line costs one query however
     * many tranches exist. Currencies are grouped, never added: a USD line and
     * a EUR line are two separate stories on the same page.
     *
     * Only settled tranches feed the shortfall. Money still travelling has not
     * lost anything yet, and saying otherwise would invent leakage.
     *
     * The shortfall is the sum of each tranche's own loss, never the
     * difference between two totals. A tranche that arrived over — a
     * favourable rate, a donor rounding up — is real, but it did not put the
     * money back into the tranche that lost it, so it is reported beside the
     * shortfall and never allowed to cancel it. Netting the two is how a page
     * ends up announcing "landed in full" over money that never arrived.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function ledgerLines(bool $withStage): Collection
    {
        $settled = $this->settledSql();
        $stages = $this->stageSql();

        // Per tranche: what it lost, and how much of that loss the recorded
        // fee explains. Written as CASE rather than LEAST so the expression
        // does not depend on one database's scalar function set.
        $rowShortfall = "(case when {$settled} and net_amount_received < payment_amount then payment_amount - net_amount_received else 0 end)";
        $rowFees = "(case
            when not ({$settled}) then 0
            when net_amount_received >= payment_amount then 0
            when fees_currency <> amount_transfered_currency then 0
            when fees < payment_amount - net_amount_received then fees
            else payment_amount - net_amount_received end)";

        return $this->filteredQuery($withStage)
            ->selectRaw("
                amount_transfered_currency as currency,
                count(*) as tranches,
                coalesce(sum(payment_amount), 0) as expected,
                coalesce(sum(case when {$settled} then payment_amount else 0 end), 0) as settled_sent,
                coalesce(sum(case when {$settled} then net_amount_received else 0 end), 0) as landed,
                coalesce(sum({$rowShortfall}), 0) as shortfall,
                coalesce(sum({$rowFees}), 0) as fees,
                coalesce(sum(case when {$settled} and net_amount_received > payment_amount then net_amount_received - payment_amount else 0 end), 0) as overage,
                coalesce(sum(case when {$stages['overdue']} then payment_amount else 0 end), 0) as overdue,
                sum(case when {$stages['overdue']} then 1 else 0 end) as overdue_count,
                coalesce(sum(case when {$stages['transit']} then payment_amount else 0 end), 0) as transit,
                sum(case when {$stages['transit']} then 1 else 0 end) as transit_count,
                coalesce(sum(case when {$stages['upcoming']} then payment_amount else 0 end), 0) as upcoming,
                sum(case when {$stages['upcoming']} then 1 else 0 end) as upcoming_count,
                coalesce(sum(case when {$stages['failed']} then payment_amount else 0 end), 0) as failed,
                sum(case when {$stages['failed']} then 1 else 0 end) as failed_count,
                sum(case when {$stages['short']} then 1 else 0 end) as short_count,
                sum(case when {$stages['unrecorded']} then 1 else 0 end) as unrecorded_count,
                coalesce(sum(case when {$stages['unrecorded']} then payment_amount else 0 end), 0) as unrecorded_amount,
                sum(case when ar_status = 'received' and amount_received_currency <> amount_transfered_currency then 1 else 0 end) as converted_count,
                coalesce(sum(case when ar_status = 'received' and amount_received_currency <> amount_transfered_currency then payment_amount else 0 end), 0) as converted_amount,
                coalesce(sum(case when transfer_date is not null then payment_amount else 0 end), 0) as actually_sent,
                coalesce(sum(case when ar_status = 'received' and amount_received_currency = amount_transfered_currency then coalesce(amount_received, 0) else 0 end), 0) as gross,
                coalesce(sum(case when fees_currency = amount_transfered_currency then fees else 0 end), 0) as recorded_fees,
                sum(case when fees > 0 and fees_currency <> amount_transfered_currency then 1 else 0 end) as fees_elsewhere_count
            ")
            ->groupBy('amount_transfered_currency')
            ->orderByRaw('sum(payment_amount) desc')
            ->get()
            ->map(function (TransfersTracking $row): array {
                $settledSent = (float) $row->getAttribute('settled_sent');
                $landed = (float) $row->getAttribute('landed');

                // Already summed per tranche in SQL, so a tranche that arrived
                // over cannot hide one that arrived short. Fees are named
                // first because they are the part someone recorded; whatever
                // is left is the exchange and the unexplained, and it is not
                // padded to look tidy.
                $shrink = (float) $row->getAttribute('shortfall');
                $feesTaken = (float) $row->getAttribute('fees');

                return [
                    'currency' => (string) $row->getAttribute('currency'),
                    'tranches' => (int) $row->getAttribute('tranches'),
                    // Everything this currency's tranches add up to, sent or not.
                    // Never labelled "sent": most of it may not have left yet.
                    'expected' => (float) $row->getAttribute('expected'),
                    // Only what actually left the donor, i.e. has a transfer date.
                    'sent' => (float) $row->getAttribute('actually_sent'),
                    'settledSent' => $settledSent,
                    'landed' => $landed,
                    'shrink' => $shrink,
                    'fees' => $feesTaken,
                    'exchangeGap' => max(0.0, $shrink - $feesTaken),
                    'overage' => (float) $row->getAttribute('overage'),
                    // Everything whose journey has not finished: still to be sent,
                    // moving, failed, converted, or received without a net amount.
                    'inFlight' => max(0.0, (float) $row->getAttribute('expected') - $settledSent),
                    'overdue' => (float) $row->getAttribute('overdue'),
                    'overdueCount' => (int) $row->getAttribute('overdue_count'),
                    'transit' => (float) $row->getAttribute('transit'),
                    'transitCount' => (int) $row->getAttribute('transit_count'),
                    'upcoming' => (float) $row->getAttribute('upcoming'),
                    'upcomingCount' => (int) $row->getAttribute('upcoming_count'),
                    'failed' => (float) $row->getAttribute('failed'),
                    'failedCount' => (int) $row->getAttribute('failed_count'),
                    'shortCount' => (int) $row->getAttribute('short_count'),
                    'unrecordedCount' => (int) $row->getAttribute('unrecorded_count'),
                    'unrecordedAmount' => (float) $row->getAttribute('unrecorded_amount'),
                    'convertedCount' => (int) $row->getAttribute('converted_count'),
                    'convertedAmount' => (float) $row->getAttribute('converted_amount'),
                    // The table's own columns: what the bank credited before its
                    // charges, and every fee recorded in the currency sent.
                    'gross' => (float) $row->getAttribute('gross'),
                    'recordedFees' => (float) $row->getAttribute('recorded_fees'),
                    'feesElsewhereCount' => (int) $row->getAttribute('fees_elsewhere_count'),
                ];
            });
    }

    /**
     * The Overview ledger. It deliberately ignores the stage filter, so its
     * figures keep describing the whole filtered set while a stage narrows
     * only the ladder beneath it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function ledger(): Collection
    {
        return $this->ledgerLines(withStage: false);
    }

    /**
     * The table's totals. They respect every filter, status included, so the
     * totals line and the footer always add up to the rows shown.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function totals(): Collection
    {
        return $this->ledgerLines(withStage: true);
    }

    /**
     * How many tranches sit at each stage under every other filter, for the
     * status pills.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function stageCounts(): array
    {
        $stages = $this->stageSql();

        $counts = $this->filteredQuery(withStage: false)
            ->toBase()
            ->selectRaw(
                'count(*) as n_all, '.collect($stages)
                    ->map(fn (string $condition, string $stage): string => "coalesce(sum(case when {$condition} then 1 else 0 end), 0) as n_{$stage}")
                    ->implode(', ')
            )
            ->first();

        return collect(['all', ...array_keys($stages)])
            ->mapWithKeys(fn (string $stage): array => [$stage => (int) ($counts->{'n_'.$stage} ?? 0)])
            ->all();
    }

    /**
     * Each group's subtotals per currency, over every filtered tranche and not
     * only the visible page, so a group split across two pages shows its true
     * subtotal on both. Keyed by the group's key.
     *
     * @return Collection<string, Collection<int, object>>
     */
    #[Computed]
    public function groupTotals(): Collection
    {
        $key = $this->groupKeySql();

        if ($key === null) {
            return new Collection;
        }

        $settled = $this->settledSql();
        $table = 'transfers_tracking';

        return $this->filteredQuery()
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->toBase()
            ->selectRaw("
                {$key} as group_key,
                {$table}.amount_transfered_currency as currency,
                count(*) as tranches,
                coalesce(sum({$table}.payment_amount), 0) as sent,
                coalesce(sum(case when {$table}.fees_currency = {$table}.amount_transfered_currency then {$table}.fees else 0 end), 0) as fees,
                coalesce(sum(case when {$table}.ar_status = 'received' and {$table}.amount_received_currency = {$table}.amount_transfered_currency then coalesce({$table}.amount_received, 0) else 0 end), 0) as gross,
                coalesce(sum(case when {$settled} then {$table}.net_amount_received else 0 end), 0) as net,
                coalesce(sum(case when {$settled} and {$table}.net_amount_received < {$table}.payment_amount then {$table}.payment_amount - {$table}.net_amount_received else 0 end), 0) as shortfall,
                coalesce(sum(case when {$settled} and {$table}.net_amount_received > {$table}.payment_amount then {$table}.net_amount_received - {$table}.payment_amount else 0 end), 0) as overage
            ")
            ->groupByRaw("{$key}, {$table}.amount_transfered_currency")
            ->orderBy('currency')
            ->get()
            ->groupBy(fn (object $row): string => (string) $row->group_key);
    }

    /**
     * Whether any tranche exists at all, so an empty page can tell the
     * difference between "nothing recorded yet" and "nothing matches".
     */
    #[Computed]
    public function hasAnyTransfer(): bool
    {
        return TransfersTracking::query()->exists();
    }

    /**
     * A page narrowed to one agreement opens the new tranche on it, with its
     * currency, as recording one from the agreement page would.
     */
    public function openCreateModal(): void
    {
        $agreementId = (int) $this->agreement;

        $this->openTransferCreate($agreementId > 0 && DonorProposal::query()->whereKey($agreementId)->exists() ? $agreementId : null);
    }

    public function openEditModal(int $transferId): void
    {
        $this->openTransferEdit($transferId);
    }

    protected function transferSaved(TransfersTracking $transfer, bool $isCreate): void
    {
        $this->resetPage();
    }

    protected function transferDeleted(int $transferId): void
    {
        $this->resetPage();
    }
};
?>
```

Notes for the implementer:
- `DateTimeImmutable` is PHP's global class; a single-file Livewire component has no namespace, so it needs no import.
- `self::MAX_AMOUNT` comes from `ManagesTransferForm`.
- The SQL the Overview ledger runs is the same text as before, except that stage and settled conditions now come from the model with their columns qualified.

- [x] **Step 4: Replace the template**

In the same file, replace everything after the closing `?>` with:

```blade

@php
    $showsOverview = $this->view === 'overview';
    $filtersAreActive = collect([$this->search, $this->donor, $this->account, $this->currency, $this->stage, $this->flag, $this->from, $this->to, $this->min, $this->max, $this->agreement])
        ->contains(fn (string $value): bool => $value !== '');
    $total = $showsOverview ? $this->transfers->total() : $this->rows->total();

    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $inkText = 'text-secondary-dark dark:text-ink';
    $hairline = 'border-secondary-soft dark:border-line';

    /* The closures below format the Overview view (_overview.blade.php). The table prints exact amounts itself. */
    $amount = fn (float $value): string => Number::format($value, maxPrecision: 0);
    $exact = fn (float $value): string => number_format($value, 2);
    /**
     * A percentage that never rounds a real loss down to zero. A 30 USD fee on
     * a 100,000 USD tranche is 0.03% — the common case in this domain — and
     * printing it as a red "−0%" reads as a broken page, not as a small loss.
     */
    $percent = function (float $part, float $whole): string {
        if ($whole <= 0) {
            return '0';
        }

        $value = $part / $whole * 100;
        $formatted = Number::format($value, maxPrecision: 1);

        return $value > 0 && (float) str_replace(',', '', $formatted) === 0.0
            ? __('transfers.under_a_tenth_of_a_percent')
            : $formatted;
    };

    /**
     * Whole units, except for an amount that would otherwise round to zero:
     * a shortfall of 0.40 is still a shortfall and must not print as "0".
     */
    $amountAtLeast = fn (float $value): string => $value > 0 && $value < 1
        ? number_format($value, 2)
        : Number::format($value, maxPrecision: 0);

    /**
     * An exchange rate with its trailing zeros dropped. Trimming the raw
     * decimal:6 string would turn a rate of 0 into an empty cell.
     */
    $rate = function (string $value): string {
        $trimmed = rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    };

    $sortOptions = [
        'attention' => __('transfers.sort_attention'),
        'shortfall' => __('transfers.sort_shortfall'),
        'date' => __('transfers.sort_date'),
        'amount' => __('transfers.sort_amount'),
    ];

    /* Filter pills. `$chosen` is read instead of the properties so a loop variable can never shadow one. */
    $chosen = [
        'year' => $this->year,
        'dateBasis' => $this->dateBasis,
        'currency' => $this->currency,
        'stage' => $this->stage,
        'flag' => $this->flag,
        'group' => $this->group,
    ];
    $sortKey = $this->activeSort();
    $sortDirection = $this->activeSortDir();
    $counts = $this->hasAnyTransfer ? $this->stageCounts : [];

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

    $yearPills = [
        ['label' => __('finance_tables.all_years'), 'click' => "\$set('year', '')", 'active' => $chosen['year'] === ''],
        ...array_map(fn (int $option): array => $setPill('year', (string) $option, (string) $option), $this->yearOptions),
    ];
    $basisPills = [
        $setPill('dateBasis', 'scheduled', __('finance_tables.basis_scheduled')),
        $setPill('dateBasis', 'sent', __('finance_tables.basis_sent')),
        $setPill('dateBasis', 'received', __('finance_tables.basis_received')),
    ];
    $statusPills = [
        ['label' => __('finance_tables.all'), 'click' => "toggleStage('')", 'active' => $chosen['stage'] === '', 'count' => $counts['all'] ?? 0, 'test' => 'stage-all'],
        $stagePill('upcoming', __('finance_tables.state_upcoming')),
        $stagePill('overdue', __('finance_tables.state_late'), 'critical'),
        $stagePill('transit', __('finance_tables.state_transit'), 'warn'),
        $stagePill('received', __('finance_tables.state_received')),
        $stagePill('failed', __('finance_tables.state_failed'), 'critical'),
    ];
    $currencyPills = [
        ['label' => __('finance_tables.all'), 'click' => "\$set('currency', '')", 'active' => $chosen['currency'] === ''],
        ...array_map(fn (Currency $case): array => $setPill('currency', $case->value, $case->value), Currency::cases()),
    ];
    /* Landed short and net-not-recorded are stages of a received tranche, so they share the status property. */
    $flagPills = [
        $stagePill('short', __('finance_tables.flag_short'), 'critical'),
        $stagePill('unrecorded', __('finance_tables.flag_unrecorded'), 'warn'),
        ['label' => __('finance_tables.flag_converted'), 'click' => "toggleFlag('converted')", 'active' => $chosen['flag'] === 'converted', 'test' => 'flag-converted'],
        ['label' => __('finance_tables.flag_no_account'), 'click' => "toggleFlag('no_account')", 'active' => $chosen['flag'] === 'no_account', 'test' => 'flag-no-account'],
    ];
    $groupPills = [
        ['label' => __('finance_tables.group_none'), 'click' => "\$set('group', '')", 'active' => $chosen['group'] === ''],
        $setPill('group', 'agreement', __('finance_tables.group_agreement')),
        $setPill('group', 'donor', __('finance_tables.group_donor')),
        $setPill('group', 'account', __('finance_tables.group_account')),
        $setPill('group', 'month', __('finance_tables.group_month')),
    ];
    $orderPills = [
        ['label' => __('finance_tables.needs_attention_first'), 'click' => 'toggleAttentionOrder', 'active' => $sortKey === 'attention', 'test' => 'order-attention'],
    ];
@endphp

<section
    class="@container/ladder flex min-h-full w-full flex-1 flex-col gap-6 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink"
    aria-labelledby="transfer-ladder-heading"
>
    <x-ui.page-header
        heading-id="transfer-ladder-heading"
        :title="__('common.transfers')"
        :count="$total"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('transfers.search_transfers')" :placeholder="__('common.reference_donor_or_proposal')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('funding_agreements.donor')" wire:model.live="donor" :value="$this->donor">
                <flux:select.option value="">{{ __('funding_agreements.all_donors') }}</flux:select.option>
                @foreach ($this->filterDonors as $donorOption)
                    <flux:select.option :value="$donorOption->id" :wire:key="'tr-donor-'.$donorOption->id">{{ $donorOption->name }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('transfers.account')" wire:model.live="account" :value="$this->account">
                <flux:select.option value="">{{ __('common.all_accounts') }}</flux:select.option>
                @foreach ($this->filterAccounts as $accountOption)
                    <flux:select.option :value="$accountOption->id" :wire:key="'tr-account-'.$accountOption->id">
                        {{ $accountOption->account_name }}@unless ($accountOption->is_active) · {{ __('common.closed') }}@endunless
                    </flux:select.option>
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

                @can('transfers.create')
                <x-ui.button tone="primary" icon="plus" class="min-h-11 touch-manipulation @sm/ladder:min-h-10" wire:click="openCreateModal" data-test="create-transfer-button">
                    {{ __('transfers.record_transfer') }}
                </x-ui.button>
                @endcan
            </div>
        </x-slot:actions>

        @if ($this->hasAnyTransfer)
            <x-slot:summary>
                <x-funding.filters :active="$filtersAreActive">
                    <x-funding.pills :label="__('finance_tables.year')" :pills="$yearPills" />

                    <x-funding.pills :label="__('finance_tables.date_is')" :pills="$basisPills">
                        <span class="ms-1 text-xs font-semibold {{ $mutedText }}">{{ __('finance_tables.from') }}</span>
                        <x-ui.input type="date" size="sm" class="w-40" wire:model.live="from" :aria-label="__('finance_tables.from')" />
                        <span class="text-xs font-semibold {{ $mutedText }}">{{ __('finance_tables.to') }}</span>
                        <x-ui.input type="date" size="sm" class="w-40" wire:model.live="to" :aria-label="__('finance_tables.to')" />
                    </x-funding.pills>

                    <x-funding.pills :label="__('common.status')" :pills="$statusPills" />
                    <x-funding.pills :label="__('common.currency')" :pills="$currencyPills" />
                    <x-funding.pills :label="__('finance_tables.flags')" :pills="$flagPills" />

                    <x-funding.filter-row :label="__('finance_tables.amount')">
                        <x-ui.input type="number" inputmode="decimal" min="0" step="0.01" size="sm" class="w-36" wire:model.live.debounce.400ms="min" :placeholder="__('finance_tables.minimum')" :aria-label="__('finance_tables.minimum')" />
                        <span class="text-xs font-semibold {{ $mutedText }}">{{ __('finance_tables.to') }}</span>
                        <x-ui.input type="number" inputmode="decimal" min="0" step="0.01" size="sm" class="w-36" wire:model.live.debounce.400ms="max" :placeholder="__('finance_tables.maximum')" :aria-label="__('finance_tables.maximum')" />
                    </x-funding.filter-row>

                    @unless ($showsOverview)
                        <x-funding.pills :label="__('finance_tables.group_by')" :pills="$groupPills" />
                        <x-funding.pills :label="__('finance_tables.order')" :pills="$orderPills" />
                    @endunless

                    @if ($this->agreementFilter !== null)
                        <x-funding.filter-row :label="__('finance_tables.group_agreement')">
                            <button type="button" wire:click="$set('agreement', '')" class="inline-flex min-h-8 items-center gap-1.5 rounded-full bg-funding-soft ps-3 pe-2 text-xs font-semibold text-funding transition-colors hover:bg-funding hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-ink dark:hover:text-surface-0" data-agreement-chip>
                                {{ __('transfers.agreement_filter_chip', ['donor' => $this->agreementFilter->donor->name, 'proposal' => $this->agreementFilter->proposal->name]) }}
                                <flux:icon.x-mark variant="micro" class="size-3.5" />
                            </button>
                        </x-funding.filter-row>
                    @endif
                </x-funding.filters>
            </x-slot:summary>
        @endif
    </x-ui.page-header>

    @if (! $this->hasAnyTransfer)
        <x-ui.empty-state
            class="rtl:[&_svg]:-scale-x-100"
            icon="arrow-right-circle"
            :heading="__('transfers.no_transfers_recorded')"
            :body="__('transfers.record_the_first_tranche_against_a_funding_agreement')"
        />
    @elseif ($showsOverview)
        @include('pages.transfers._overview')
    @elseif ($this->rows->isEmpty())
        <x-ui.empty-state
            class="rtl:[&_svg]:-scale-x-100"
            icon="arrow-right-circle"
            :heading="__('transfers.no_matching_transfers')"
            :body="__('common.change_the_search_or_filters_and_try_again')"
        />
    @else
        @php
            $totalLines = $this->totals->map(fn (array $line): array => [
                'currency' => $line['currency'],
                'caption' => trans_choice('transfers.count_tranches', $line['tranches'], ['count' => $line['tranches']]),
                'figures' => array_values(array_filter([
                    ['key' => 'expected', 'label' => __('transfers.expected'), 'value' => $line['expected']],
                    ['key' => 'sent', 'label' => __('transfers.sent'), 'value' => $line['sent']],
                    ['key' => 'received', 'label' => __('finance_tables.received_net'), 'value' => $line['landed'], 'tone' => 'good'],
                    ['key' => 'fees', 'label' => __('transfers.fees'), 'value' => $line['recordedFees']],
                    ['key' => 'shortfall', 'label' => __('transfers.shortfall'), 'value' => $line['shrink'], 'prefix' => $line['shrink'] > 0 ? '−' : '', 'tone' => $line['shrink'] > 0 ? 'critical' : null],
                    $line['overage'] > 0 ? ['key' => 'overage', 'label' => __('transfers.arrived_over'), 'value' => $line['overage'], 'prefix' => '+', 'tone' => 'good'] : null,
                    ['key' => 'outstanding', 'label' => __('finance_tables.outstanding'), 'value' => $line['inFlight']],
                ])),
                'notes' => array_values(array_filter([
                    $line['convertedCount'] > 0 ? trans_choice('transfers.count_tranches_landed_in_another_currency', $line['convertedCount'], ['count' => $line['convertedCount'], 'amount' => number_format($line['convertedAmount'], 2).' '.$line['currency']]) : null,
                    $line['unrecordedCount'] > 0 ? trans_choice('transfers.count_tranches_missing_net', $line['unrecordedCount'], ['count' => $line['unrecordedCount'], 'amount' => number_format($line['unrecordedAmount'], 2).' '.$line['currency']]) : null,
                    $line['feesElsewhereCount'] > 0 ? trans_choice('finance_tables.count_fees_in_another_currency', $line['feesElsewhereCount'], ['count' => $line['feesElsewhereCount']]) : null,
                ])),
            ])->all();

            $footLines = $this->totals->map(fn (array $line): array => [
                'currency' => $line['currency'],
                'tranches' => $line['tranches'],
                'sent' => $line['expected'],
                'fees' => $line['recordedFees'],
                'gross' => $line['gross'],
                'net' => $line['landed'],
                'shortfall' => $line['shrink'],
                'overage' => $line['overage'],
            ])->all();

            $isGrouped = $this->group !== '';
            $previousGroup = null;
        @endphp

        <x-funding.totals :lines="$totalLines" />

        <x-funding.register size="xl" :caption="__('finance_tables.transfers_caption')">
            <x-slot:head>
                <x-funding.register.th sort="donor" :active="$sortKey" :dir="$sortDirection">{{ __('finance_tables.donor_agreement') }}</x-funding.register.th>
                <x-funding.tranche-head sortable :active="$sortKey" :dir="$sortDirection" />
            </x-slot:head>

            @foreach ($this->rows as $transfer)
                @php
                    $rowAgreement = $transfer->donorProposal;
                    $groupKey = $isGrouped ? $this->groupKeyOf($transfer) : null;
                @endphp

                @if ($isGrouped && $groupKey !== $previousGroup)
                    <x-funding.tranche-group
                        :label="$this->groupLabelOf($transfer)"
                        :group="$groupKey"
                        :lines="$this->groupTotals->get($groupKey, [])"
                        :lead="1"
                    />
                    @php
                        $previousGroup = $groupKey;
                    @endphp
                @endif

                <x-funding.tranche-row :transfer="$transfer">
                    <x-slot:lead>
                        <td data-label="{{ __('finance_tables.donor_agreement') }}" data-wide>
                            @can('donors.view')
                                <a href="{{ route('donors.show', $rowAgreement->donor) }}" wire:navigate class="font-semibold underline-offset-2 hover:underline">{{ $rowAgreement->donor->name }}</a>
                            @else
                                <span class="font-semibold">{{ $rowAgreement->donor->name }}</span>
                            @endcan
                            <span class="sub">
                                @can('proposals.view')
                                    <a href="{{ route('proposals.show', $rowAgreement->proposal) }}" wire:navigate class="underline-offset-2 hover:underline">{{ $rowAgreement->proposal->name }}</a>
                                @else
                                    {{ $rowAgreement->proposal->name }}
                                @endcan
                                ·
                                @can('funding-agreements.view')
                                    <a href="{{ route('funding-agreements.show', $rowAgreement) }}" wire:navigate class="underline-offset-2 hover:underline" data-agreement-link>{{ filled($rowAgreement->project_num) ? $rowAgreement->project_num : __('proposals.view_agreement') }}</a>
                                @else
                                    {{ $rowAgreement->project_num ?? __('common.no_donor_reference') }}
                                @endcan
                            </span>
                        </td>
                    </x-slot:lead>

                    <x-slot:actions>
                        <td>
                            @can('transfers.update')
                                {{-- Deleting a tranche happens inside its edit form. --}}
                                <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-8!" wire:click="openEditModal({{ $transfer->id }})" :aria-label="__('transfers.edit_transfer')" data-test="edit-transfer-{{ $transfer->id }}" />
                            @endcan
                        </td>
                    </x-slot:actions>
                </x-funding.tranche-row>
            @endforeach

            <x-slot:foot>
                <x-funding.tranche-foot :lines="$footLines" :lead="1" />
            </x-slot:foot>
        </x-funding.register>

        <x-funding.pager :paginator="$this->rows" :per-page="$this->perPage" scroll-to="#transfer-ladder-heading" />
    @endif

    <x-funding.transfer-modal
        :editing="$editingTransferId !== null"
        :agreement-options="$showTransferModal ? $this->agreementOptions : []"
        :account-options="$showTransferModal ? $this->accountOptions : []"
        :confirming-delete="$confirmingTransferDelete"
    />
</section>
```

- [x] **Step 5: Drop the agreement chip and the "Reset filters" button from the Overview toolbar**

The filter rows in the header now carry both, for both views. In `resources/views/pages/transfers/_overview.blade.php` find this block (it sits inside the `{{-- How the ladder is ranked, and what is currently narrowing it. --}}` toolbar):

```blade
            <div class="flex flex-wrap items-center gap-3">
                <span class="text-xs tabular-nums {{ $mutedText }}">
                    {{ trans_choice('transfers.count_matching_transfer', $this->transfers->total(), ['count' => $this->transfers->total()]) }}
                </span>
                @if ($this->agreementFilter !== null)
                    <button type="button" wire:click="$set('agreement', '')" class="inline-flex min-h-8 items-center gap-1.5 rounded-full bg-funding-soft ps-3 pe-2 text-xs font-semibold text-funding transition-colors hover:bg-funding hover:text-white dark:hover:text-surface-0 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-ink" data-agreement-chip>
                        {{ __('transfers.agreement_filter_chip', ['donor' => $this->agreementFilter->donor->name, 'proposal' => $this->agreementFilter->proposal->name]) }}
                        <flux:icon.x-mark variant="micro" class="size-3.5" />
                    </button>
                @endif
                @if ($filtersAreActive)
                    <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-ink-muted!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
                @endif
            </div>
```

and replace it with:

```blade
            <span class="text-xs tabular-nums {{ $mutedText }}">
                {{ trans_choice('transfers.count_matching_transfer', $this->transfers->total(), ['count' => $this->transfers->total()]) }}
            </span>
```

- [x] **Step 6: Run the new tests**

Run: `.\php.cmd artisan test --compact tests/Feature/TransferRegisterTest.php`
Expected: PASS, 20 tests.

Common failures and their cause:
- `Undefined variable $arStatus` — a leftover of the old header; the template in Step 4 no longer reads it.
- `Column 'id' in order clause is ambiguous` — an `orderBy('id')` reached the joined query; every order in `registerSorts()` must be qualified with `transfers_tracking.`.
- `Class "DateTimeImmutable" not found` cannot happen in a namespace-less file; if it does, the file gained a `namespace` line — remove it.

- [x] **Step 7: Point the old visual assertions at the Overview view**

In `tests/Feature/TransferTrackingTest.php` make exactly these changes.

Replace the whole test `transfers can be filtered by receipt status and account` with (the receipt-status select is gone; a stage does its job):

```php
test('transfers can be filtered by stage and account', function () {
    $user = User::factory()->role('Financial')->create();
    $operations = Account::factory()->create(['account_name' => 'Operations USD']);
    $reserve = Account::factory()->create(['account_name' => 'Reserve EUR']);

    TransfersTracking::factory()->create([
        'account_id' => $operations->id,
        'ar_status' => ArStatus::Received,
        'payment_amount' => '111111.00',
    ]);
    TransfersTracking::factory()->create([
        'account_id' => $reserve->id,
        'ar_status' => ArStatus::Pending,
        'payment_amount' => '222222.00',
    ]);

    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->assertSee('111,111.00')
        ->assertSee('222,222.00')
        ->call('toggleStage', 'received')
        ->assertSee('111,111.00')
        ->assertDontSee('222,222.00')
        ->call('clearFilters')
        ->set('account', (string) $operations->id)
        ->assertSee('111,111.00')
        ->assertDontSee('222,222.00');
});
```

In each of the following tests insert `->set('view', 'overview')` on the line directly after `->test('pages::transfers.index')`:

| Test name | Why |
| --- | --- |
| `the default ladder order leads with money that is overdue` | asserts the ladder's own order |
| `a tranche marked received with no net amount is not reported as landed` | asserts the measure's `aria-label` |
| `an exchange rate of zero is shown as zero rather than an empty cell` | asserts the ladder's `<dd>` |
| `a receive date before the transfer date never reports a negative transit time` | asserts the measure's `aria-label` |
| `a small fee is never reported as a zero percent shortfall` | asserts the ledger's percentage |
| `landed in full is never claimed while money is still outstanding` | asserts the ledger headline — this test builds the component twice; add the line to both |

For example, the first becomes:

```php
    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->set('view', 'overview')
        ->assertSet('sort', 'attention')
        ->assertSeeHtmlInOrder([$overdueRow, $upcomingRow])
        // Largest amount reverses the pair, so the order is the sort's doing
        // and not the order the rows happened to be created in.
        ->call('setSort', 'amount')
        ->assertSeeHtmlInOrder([$upcomingRow, $overdueRow]);
```

No other test in that file changes: the ones that read `->instance()->ledger` or `->instance()->transfers` do not depend on the view, and `wire:key="transfer-{id}"` is printed by the table rows too.

- [x] **Step 8: Run every test that touches this page**

Run: `.\php.cmd artisan test --compact tests/Feature/TransferTrackingTest.php tests/Feature/TransferRegisterTest.php tests/Feature/FundingWorkflowTest.php tests/Feature/FundingAgreementTest.php tests/Feature/Notifications/QuickActionsTest.php tests/Feature/LocalizationTest.php`
Expected: PASS.

`FundingAgreementTest` contains `the agreement page links to its tranches in the transfers ledger`, which asserts `data-agreement-chip` on this page: the chip now renders in the header's filter rows.

- [x] **Step 9: Checkpoint**

Run: `.\php.cmd vendor/bin/pint --dirty --format agent`
Do not commit.

---

### Task 4: CSV export

**Files:**
- Modify: `resources/views/pages/transfers/⚡index.blade.php`
- Modify: `tests/Feature/TransferRegisterTest.php`

**Interfaces:**
- Consumes (Phase 0): `App\Support\CsvExport::download(string $filename, array $headings, iterable $rows): StreamedResponse`; `App\Support\Tranches::csvHeadings(): list<string>` and `::csvRow(TransfersTracking $transfer, string $donor, string $proposal, ?string $reference, ?string $account): list<mixed>`; test helper `downloadedCsv(Testable $component): list<list<string>>` from `tests/Pest.php`.
- Produces: public action `exportCsv(): StreamedResponse`, file name `transfers-{Y-m-d}.csv`.

- [x] **Step 1: Write the failing tests**

Append to `tests/Feature/TransferRegisterTest.php`:

```php
test('the export holds every filtered row, not only the visible page', function () {
    // The state column depends on today: on this day a tranche due on 1 February is still upcoming.
    $this->travelTo('2026-01-15 09:00:00');

    $agreement = DonorProposal::factory()->create([
        'donor_id' => Donor::factory()->create(['name' => 'Alpha Fund'])->id,
        'project_num' => 'AF-2026-01',
        'currency' => Currency::Usd,
    ]);
    TransfersTracking::factory()->count(30)->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '100.00',
        'amount_transfered_currency' => Currency::Usd,
        'scheduled_date' => '2026-02-01',
        'transfer_date' => null,
        'ar_status' => ArStatus::Pending,
    ]);
    registerPendingTranche(['amount_transfered_currency' => Currency::Eur]);

    $page = transferRegister()
        ->set('currency', 'USD')
        ->call('exportCsv')
        ->assertFileDownloaded('transfers-'.now()->toDateString().'.csv');

    $csv = downloadedCsv($page);

    expect($csv)->toHaveCount(31)
        ->and($csv[0][1])->toBe('Donor')
        ->and($csv[1][0])->toBe('Upcoming')
        ->and($csv[1][1])->toBe('Alpha Fund')
        ->and($csv[1][3])->toBe('AF-2026-01')
        ->and($csv[1][5])->toBe('2026-02-01')
        ->and($csv[1][8])->toBe('USD')
        ->and($csv[1][9])->toBe('100.00')
        ->and($csv[1][17])->toBe('pending');
});

test('the export writes plain numbers, ISO dates and the difference of a settled tranche', function () {
    registerSettledTranche();

    $row = downloadedCsv(transferRegister()->call('exportCsv'))[1];

    expect($row[0])->toBe('Received')
        ->and($row[5])->toBe('2026-03-01')
        ->and($row[6])->toBe('2026-03-02')
        ->and($row[7])->toBe('2026-03-05')
        ->and($row[9])->toBe('1000.00')
        ->and($row[10])->toBe('10.00')
        ->and($row[13])->toBe('995.00')
        ->and($row[14])->toBe('985.00')
        ->and($row[15])->toBe('USD')
        ->and($row[16])->toBe('-15.00')
        ->and($row[17])->toBe('received');
});

test('exporting needs the transfers view permission', function () {
    registerPendingTranche();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::transfers.index')
        ->call('exportCsv')
        ->assertForbidden();
});
```

- [x] **Step 2: Run them to verify they fail**

Run: `.\php.cmd artisan test --compact tests/Feature/TransferRegisterTest.php --filter=export`
Expected: FAIL — `Unable to call component method. Public method [exportCsv] not found`.

- [x] **Step 3: Add the export action**

In `resources/views/pages/transfers/⚡index.blade.php` add these imports to the `use` list at the top (keep the list alphabetical):

```php
use App\Support\CsvExport;
use App\Support\Tranches;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;
```

and add this method directly after `groupTotals()`:

```php
    /**
     * Every tranche the filters match, in the table's order, as a CSV file.
     *
     * The rows are read lazily and name their donor, proposal and account from
     * the joined columns, so a large export holds one tranche in memory at a
     * time.
     */
    public function exportCsv(): StreamedResponse
    {
        Gate::authorize('transfers.view');

        $transfers = $this->applyRegisterSort($this->groupOrder($this->registerQuery()))
            ->select([
                'transfers_tracking.*',
                'donors.name as donor_name',
                'proposals.name as proposal_name',
                'donor_proposal.project_num as agreement_reference',
                'accounts.account_name as landing_account',
            ])
            ->cursor();

        $rows = (function () use ($transfers) {
            foreach ($transfers as $transfer) {
                yield Tranches::csvRow(
                    $transfer,
                    (string) $transfer->getAttribute('donor_name'),
                    (string) $transfer->getAttribute('proposal_name'),
                    $transfer->getAttribute('agreement_reference'),
                    $transfer->getAttribute('landing_account'),
                );
            }
        })();

        return CsvExport::download('transfers-'.CarbonImmutable::today()->toDateString().'.csv', Tranches::csvHeadings(), $rows);
    }
```

- [x] **Step 4: Add the Export button**

In the template's `<x-slot:actions>`, insert this between the `<x-ui.view-toggle … />` and the `@can('transfers.create')` line:

```blade
                @if ($this->hasAnyTransfer)
                    <x-ui.button icon="arrow-down-tray" class="min-h-11 touch-manipulation @sm/ladder:min-h-10" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv" data-test="export-csv">
                        {{ __('finance_tables.export_csv') }}
                    </x-ui.button>
                @endif
```

- [x] **Step 5: Run the tests to verify they pass**

Run: `.\php.cmd artisan test --compact tests/Feature/TransferRegisterTest.php`
Expected: PASS, 23 tests.

- [x] **Step 6: Checkpoint**

Run: `.\php.cmd vendor/bin/pint --dirty --format agent`
Run: `.\php.cmd artisan test --compact tests/Feature/TransferTrackingTest.php tests/Feature/TransferRegisterTest.php tests/Feature/TrancheComponentsTest.php tests/Feature/LocalizationTest.php`
Expected: PASS. Do not commit.

---

### Task 5: Look at it

The tests prove the numbers. This task proves the page reads well, which is what the finance manager judges.

**Files:** none changed unless a defect is found.

- [x] **Step 1: Make sure the assets are current**

Ask the user whether `composer run dev` (or `npm run dev`) is running. If not, ask them to start it; the register CSS from Phase 0 must be compiled.

- [x] **Step 2: Render the page without signing in**

Follow `.ai/rules/pages.md` → "Preview an auth-gated page in a browser without signing in": boot the HTTP kernel in a scratch script run with `C:\php85\php.exe`, log in the first `Financial` user, handle `Request::create('/transfers')`, write the HTML to `public/__preview.html`, rewrite the APP_URL and Vite hosts to the running ports, and open it in the Browser pane. Repeat with `/transfers?view=overview`, `/transfers?group=donor`, `/transfers?stage=overdue&currency=USD`.

- [x] **Step 3: Check each of these and fix what fails**

| Check | Expected |
| --- | --- |
| Table view, wide window (≥ 1500px content) | All 14 columns fit; no sideways scroll; the header row sticks under the app bar when the page scrolls. |
| Table view, laptop width | The table scrolls sideways inside itself; the Donor / agreement column stays pinned; the page itself never scrolls sideways. |
| Amounts | Two decimals, thousands separators, right-aligned, digits in one vertical line per column. |
| A settled tranche | Difference in the critical colour with a true minus sign. |
| Group by donor | Group rows read as headers (tinted), each with its subtotals. |
| Overview view | Identical to the page before this phase, minus the chip and Reset button that moved to the header. |
| Dark mode (`resize_window` with `colorScheme: dark`) | No light boxes; table head on `surface-2`; text readable. |
| Arabic (render with a user whose `locale` is `ar`) | Columns mirrored; amounts still read left to right with the minus sign in front. |
| 390px wide | Each row is a block of labelled pairs; nothing overflows. |

- [x] **Step 4: Delete the preview file**

Remove `public/__preview.html` and the scratch script.

- [x] **Step 5: Checkpoint**

Show the user two screenshots (table view, grouped view) and stop. This is the review point the spec names: Phases 2–6 copy this page's pattern, so any change to the look is cheapest now.
