# Funding tables — Phase 0: The shared finance kit

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build everything Phases 1–6 share — one SQL definition of every money figure, the register Livewire traits, the CSV writer, the table/filter/totals Blade components, the register CSS and the `finance_tables` lang group — so each page phase only wires them together.

**Architecture:** Money is defined once: tranche states on `TransfersTracking` (PHP and SQL side by side), agreement and owner figures in `App\Support\AgreementMoney` (grouped derived tables joined as `money`), tranche lists in `App\Support\Tranches`. Pages get filters/sort/paging from `FiltersFinanceRegister` and the Table/Overview switch from `SwitchesFundingView`. No page changes in this phase.

**Tech Stack:** Laravel 13, Livewire 4.4, Flux (lite), Tailwind 4, Pest 5, MySQL (tests run on MySQL with ONLY_FULL_GROUP_BY).

**Spec:** `docs/superpowers/specs/2026-09-30-funding-finance-tables-design.md`

## Global Constraints

- PHP runs through `.\php.cmd` (PHP 8.5 at `C:\php85`), never bare `php`. From the Bash tool use `/c/php85/php.exe`.
- **Do not commit.** Leave every change in the working tree for the user's review.
- Never run the whole test suite. Run only the files each step names.
- Run `.\php.cmd vendor/bin/pint --dirty --format agent` after changing any PHP file.
- No new Composer or npm package. No migration.
- Never use the single-line `@php(...)` shorthand in Blade.
- Every visible string goes through a lang key in both `lang/en` and `lang/ar`. Never build a key at runtime (`__('group.prefix_'.$x)`): `LocalizationTest` reads keys literally.
- `Model::shouldBeStrict()` is on outside production: never read an attribute a query did not select; read optional `money_*` attributes through `getAttributes()`.
- Dark mode: Graphite tokens only. Logical properties only (`inset-inline-start`, `text-align: end`).
- Currencies are never added together. Year bounds are interpolated into SQL only after being built from a validated integer.
- Public Livewire methods in traits must not contain write calls (`AuthorizationCoverageTest`).
- Markup hooks other phases rely on: register root `data-register` and `data-data-table` (tour "table" step); totals root `data-register-totals`, per line `data-totals-currency`, per figure `data-total="{key}"`; filters root `data-finance-filters` (must **not** contain the text `data-register`: Overview tests assert its absence); `th` starts with `<th scope="col"`; sort button `wire:click="sortRegister('{key}')"` with literal quotes; `aria-sort="ascending|descending"` on the active heading.

## File Structure

| File | Responsibility |
| --- | --- |
| Modify `app/Models/TransfersTracking.php` | State SQL, scopes, `isSettled()`, `landedInAnotherCurrency()`, `difference()`. |
| Modify `app/Models/DonorProposal.php` | Scopes `joinMoney()`, `withMoney()`. |
| Create `app/Support/AgreementMoney.php` | Agreement and owner money SQL, columns, totals. |
| Create `app/Support/Tranches.php` | State labels, timing, PHP totals, CSV rows. |
| Create `app/Support/CsvExport.php` | Streams a safe UTF-8 CSV. |
| Create `app/Livewire/Concerns/SwitchesFundingView.php` | `view` URL property. |
| Create `app/Livewire/Concerns/FiltersFinanceRegister.php` | Year, figures mode, sort, page size. |
| Create `resources/views/components/funding/{filters,filter-row,pills,totals,register,pager}.blade.php`, `resources/views/components/funding/register/th.blade.php` | Shared markup. |
| Modify `resources/css/app.css` | Register styles. |
| Create `lang/en/finance_tables.php`, `lang/ar/finance_tables.php` | Every new string. |
| Modify `tests/Pest.php` | `downloadedCsv()` helper. |
| Tests | `tests/Feature/Models/TransfersTrackingTest.php` (append), `tests/Feature/AgreementMoneyTest.php`, `tests/Feature/TranchesTest.php`, `tests/Feature/CsvExportTest.php`, `tests/Feature/FinanceRegisterTraitTest.php`, `tests/Feature/FinanceKitComponentsTest.php`. |

---

### Task 1: Lang group

Created first so every later task can render.

**Files:** Create `lang/en/finance_tables.php`, `lang/ar/finance_tables.php`.

- [x] **Step 1: Create `lang/en/finance_tables.php`**

```php
<?php

return [
    'accounts_caption' => 'Accounts register: one row per account',
    'agreement' => 'Agreement',
    'agreements' => 'Agreements',
    'agreements_caption' => 'Funding agreements register: one row per agreement',
    'all' => 'All',
    'all_countries' => 'All countries',
    'all_tranches' => 'All tranches',
    'all_years' => 'All years',
    'amount' => 'Amount',
    'amount_sent' => 'Amount sent',
    'basis_received' => 'Received',
    'basis_scheduled' => 'Scheduled',
    'basis_sent' => 'Sent',
    'budget' => 'Budget',
    'budget_whole_agreement' => 'Budget (whole agreement)',
    'closed_on' => 'Closed on',
    'committed_whole' => 'Committed (whole agreements)',
    'converted' => 'Converted',
    'count_accounts_open' => 'Accounts: :count · open: :open',
    'count_donors' => '{1} :count donor|[2,*] :count donors',
    'count_fees_in_another_currency' => '{1} :count fee recorded in another currency|[2,*] :count fees recorded in another currency',
    'count_landed_in_another_currency' => '{1} :count tranche landed in another currency|[2,*] :count tranches landed in another currency',
    'count_proposals' => '{1} :count proposal|[2,*] :count proposals',
    'date_is' => 'Date',
    'difference' => 'Difference',
    'donor_agreement' => 'Donor / agreement',
    'donors_caption' => 'Donors register: one row per donor and currency',
    'due_in_year' => 'Due in :year',
    'export_csv' => 'Export CSV',
    'failed' => 'Failed',
    'fees_currency' => 'Fees currency',
    'figures_all_time' => 'All time',
    'figures_label' => 'Figures',
    'figures_lifetime' => 'Whole agreement',
    'figures_year' => ':year only',
    'flag_converted' => 'Converted',
    'flag_follow_up_due' => 'Follow-up due',
    'flag_has_overdue' => 'Has overdue money',
    'flag_has_transfers' => 'Has transfers',
    'flag_in_transit' => 'Money in transit',
    'flag_no_account' => 'No account',
    'flag_no_agreement' => 'No agreement',
    'flag_no_tranches' => 'No tranches',
    'flag_no_transfers' => 'No transfers',
    'flag_other_currency' => 'Tranche in another currency',
    'flag_report_overdue' => 'Donor report overdue',
    'flag_short' => 'Landed short',
    'flag_unrecorded' => 'Net not recorded',
    'flags' => 'Flags',
    'from' => 'From',
    'gross_received' => 'Gross received',
    'group_account' => 'Account',
    'group_agreement' => 'Agreement',
    'group_by' => 'Group by',
    'group_donor' => 'Donor',
    'group_month' => 'Month',
    'group_none' => 'None',
    'in_transit' => 'In transit',
    'last_receipt' => 'Last receipt',
    'last_received' => 'Last received',
    'maximum' => 'Max',
    'minimum' => 'Min',
    'needs_attention_first' => 'Needs attention first',
    'next_due' => 'Next due',
    'next_report_due' => 'Next report due',
    'no_date' => 'No date',
    'not_scheduled' => 'Not scheduled',
    'order' => 'Order',
    'outstanding' => 'Outstanding',
    'overdue' => 'Overdue',
    'overdue_transfers' => 'Overdue transfers',
    'percent_received' => '% received',
    'proposal_status' => 'Proposal status',
    'proposals_caption' => 'Proposals register: one row per proposal and currency',
    'received' => 'Received',
    'received_currency' => 'Received currency',
    'received_in_year' => 'Received in :year',
    'received_net' => 'Received (net)',
    'recorded_status' => 'Recorded status',
    'reference' => 'Reference',
    'reports_overdue' => 'Reports overdue',
    'rows_per_page' => 'Rows per page',
    'scheduled' => 'Scheduled',
    'sort_by' => 'Sort by :column',
    'state_failed' => 'Failed',
    'state_late' => 'Overdue',
    'state_net_missing' => 'Net not recorded',
    'state_received' => 'Received',
    'state_transit' => 'In transit',
    'state_upcoming' => 'Upcoming',
    'to' => 'to',
    'total' => 'Total',
    'tranches' => 'Tranches',
    'transfers_caption' => 'Transfers register: one row per tranche',
    'upcoming' => 'Upcoming',
    'view_label' => 'View',
    'view_overview' => 'Overview',
    'view_table' => 'Table',
    'year' => 'Year',
];
```

- [x] **Step 2: Create `lang/ar/finance_tables.php`** with exactly the same keys:

```php
<?php

return [
    'accounts_caption' => 'سجل الحسابات: سطر لكل حساب',
    'agreement' => 'الاتفاقية',
    'agreements' => 'الاتفاقيات',
    'agreements_caption' => 'سجل اتفاقيات التمويل: سطر لكل اتفاقية',
    'all' => 'الكل',
    'all_countries' => 'كل البلدان',
    'all_tranches' => 'كل الدفعات',
    'all_years' => 'كل السنوات',
    'amount' => 'المبلغ',
    'amount_sent' => 'المبلغ المرسل',
    'basis_received' => 'الاستلام',
    'basis_scheduled' => 'الجدولة',
    'basis_sent' => 'الإرسال',
    'budget' => 'الميزانية',
    'budget_whole_agreement' => 'الميزانية (كامل الاتفاقية)',
    'closed_on' => 'تاريخ الإغلاق',
    'committed_whole' => 'الملتزم به (كامل الاتفاقيات)',
    'converted' => 'حُوِّلت العملة',
    'count_accounts_open' => 'الحسابات: :count · المفتوحة: :open',
    'count_donors' => '{1} مانح واحد|{2} مانحان|[3,10] :count مانحين|[11,*] :count مانحاً',
    'count_fees_in_another_currency' => '{1} رسم واحد مسجّل بعملة أخرى|{2} رسمان مسجّلان بعملة أخرى|[3,10] :count رسوم مسجّلة بعملة أخرى|[11,*] :count رسماً مسجّلاً بعملة أخرى',
    'count_landed_in_another_currency' => '{1} دفعة واحدة وصلت بعملة أخرى|{2} دفعتان وصلتا بعملة أخرى|[3,10] :count دفعات وصلت بعملة أخرى|[11,*] :count دفعة وصلت بعملة أخرى',
    'count_proposals' => '{1} مقترح واحد|{2} مقترحان|[3,10] :count مقترحات|[11,*] :count مقترحاً',
    'date_is' => 'التاريخ',
    'difference' => 'الفرق',
    'donor_agreement' => 'المانح / الاتفاقية',
    'donors_caption' => 'سجل المانحين: سطر لكل مانح وعملة',
    'due_in_year' => 'المستحق في :year',
    'export_csv' => 'تصدير CSV',
    'failed' => 'الفاشل',
    'fees_currency' => 'عملة الرسوم',
    'figures_all_time' => 'كل الفترات',
    'figures_label' => 'الأرقام',
    'figures_lifetime' => 'كامل الاتفاقية',
    'figures_year' => 'سنة :year فقط',
    'flag_converted' => 'حُوِّلت عملتها',
    'flag_follow_up_due' => 'متابعة مستحقة',
    'flag_has_overdue' => 'لديه مبالغ متأخرة',
    'flag_has_transfers' => 'فيه تحويلات',
    'flag_in_transit' => 'أموال قيد التحويل',
    'flag_no_account' => 'بلا حساب',
    'flag_no_agreement' => 'بلا اتفاقية',
    'flag_no_tranches' => 'بلا دفعات',
    'flag_no_transfers' => 'بلا تحويلات',
    'flag_other_currency' => 'دفعة بعملة أخرى',
    'flag_report_overdue' => 'تقرير مانح متأخر',
    'flag_short' => 'وصلت ناقصة',
    'flag_unrecorded' => 'الصافي غير مسجّل',
    'flags' => 'مؤشرات',
    'from' => 'من',
    'gross_received' => 'إجمالي المستلم',
    'group_account' => 'الحساب',
    'group_agreement' => 'الاتفاقية',
    'group_by' => 'تجميع حسب',
    'group_donor' => 'المانح',
    'group_month' => 'الشهر',
    'group_none' => 'بلا تجميع',
    'in_transit' => 'قيد التحويل',
    'last_receipt' => 'آخر استلام',
    'last_received' => 'آخر استلام',
    'maximum' => 'الحد الأعلى',
    'minimum' => 'الحد الأدنى',
    'needs_attention_first' => 'ما يحتاج متابعة أولاً',
    'next_due' => 'الاستحقاق التالي',
    'next_report_due' => 'موعد التقرير التالي',
    'no_date' => 'بلا تاريخ',
    'not_scheduled' => 'غير مجدول',
    'order' => 'الترتيب',
    'outstanding' => 'المتبقي',
    'overdue' => 'المتأخر',
    'overdue_transfers' => 'التحويلات المتأخرة',
    'percent_received' => 'نسبة المستلم %',
    'proposal_status' => 'حالة المقترح',
    'proposals_caption' => 'سجل المقترحات: سطر لكل مقترح وعملة',
    'received' => 'المستلم',
    'received_currency' => 'عملة الاستلام',
    'received_in_year' => 'المستلم في :year',
    'received_net' => 'المستلم (الصافي)',
    'recorded_status' => 'الحالة المسجّلة',
    'reference' => 'المرجع',
    'reports_overdue' => 'التقارير المتأخرة',
    'rows_per_page' => 'عدد الصفوف في الصفحة',
    'scheduled' => 'المجدول',
    'sort_by' => 'ترتيب حسب :column',
    'state_failed' => 'فاشلة',
    'state_late' => 'متأخرة',
    'state_net_missing' => 'الصافي غير مسجّل',
    'state_received' => 'مستلمة',
    'state_transit' => 'قيد التحويل',
    'state_upcoming' => 'قادمة',
    'to' => 'إلى',
    'total' => 'الإجمالي',
    'tranches' => 'الدفعات',
    'transfers_caption' => 'سجل التحويلات: سطر لكل دفعة',
    'upcoming' => 'القادم',
    'view_label' => 'طريقة العرض',
    'view_overview' => 'نظرة عامة',
    'view_table' => 'جدول',
    'year' => 'السنة',
];
```

- [x] **Step 3: Verify every key the plans use exists**

```bash
cd /c/xampp/htdocs/hc-tracking && grep -ohE "finance_tables\.[a-z_0-9]+[a-z0-9]" docs/superpowers/plans/2026-09-30-funding-tables-phase-*.md | sort -u | sed 's/finance_tables\.//' | while read k; do grep -q "^    '$k' =>" lang/en/finance_tables.php || echo "missing: $k"; done
```

Expected: only `missing: php` (the file name itself) at most.

Run: `.\php.cmd artisan test --compact tests/Feature/LocalizationTest.php` — Expected: PASS.

Words checked against tests in later phases: in "year only" mode the Agreements page must not print "Outstanding" or "Due in 2026"; no flag or pill label here contains either.

---

### Task 2: Tranche states on the model

**Files:** Modify `app/Models/TransfersTracking.php`; Test: append to `tests/Feature/Models/TransfersTrackingTest.php`.

**Interfaces — Produces:**
- `TransfersTracking::STATES` (`['received','failed','transit','late','upcoming']`), `::DATE_COLUMNS` (`['scheduled'=>'scheduled_date','sent'=>'transfer_date','received'=>'receive_date']`).
- Static `stateSql(string $state): string`, `settledSql()`, `landedShortSql()`, `missingNetSql()`, `convertedSql()`: SQL conditions, every column qualified `transfers_tracking.`, today interpolated.
- Scopes: `inState(string)`, `settled()`, `landedShort()`, `missingNet()`, `converted()`, `datedWithin(string $basis, ?string $from, ?string $to)`.
- Instance: `isSettled(): bool`, `landedInAnotherCurrency(): bool`, `difference(): ?float` (net − payment, settled only).

- [x] **Step 1: Append the failing tests**

```php
use App\Enums\ArStatus;
use App\Enums\Currency;

test('every tranche state reads the same in SQL as on the model', function () {
    $this->travelTo('2026-06-15 09:00:00');

    $tranches = collect([
        TransfersTracking::factory()->create(['ar_status' => ArStatus::Received]),
        TransfersTracking::factory()->create(['ar_status' => ArStatus::Failed]),
        TransfersTracking::factory()->create(['transfer_date' => '2026-06-10', 'scheduled_date' => '2026-05-01']),
        TransfersTracking::factory()->create(['transfer_date' => null, 'scheduled_date' => '2026-06-14']),
        TransfersTracking::factory()->create(['transfer_date' => null, 'scheduled_date' => '2026-06-15']),
        TransfersTracking::factory()->create(['transfer_date' => null, 'scheduled_date' => null]),
    ])->map->fresh();

    foreach (TransfersTracking::STATES as $state) {
        expect(TransfersTracking::query()->inState($state)->orderBy('id')->pluck('id')->all())
            ->toBe($tranches->filter(fn (TransfersTracking $t): bool => $t->state() === $state)->pluck('id')->values()->all(), $state);
    }

    expect($tranches->map->state()->all())->toBe(['received', 'failed', 'transit', 'late', 'upcoming', 'upcoming']);
});

test('settled, landed short, missing net and converted tranches are told apart', function () {
    $settled = TransfersTracking::factory()->create(['ar_status' => ArStatus::Received, 'payment_amount' => '100.00', 'net_amount_received' => '90.00', 'amount_transfered_currency' => Currency::Usd, 'amount_received_currency' => Currency::Usd]);
    $missing = TransfersTracking::factory()->create(['ar_status' => ArStatus::Received, 'net_amount_received' => null, 'amount_received' => null]);
    $converted = TransfersTracking::factory()->create(['ar_status' => ArStatus::Received, 'net_amount_received' => '80.00', 'amount_transfered_currency' => Currency::Usd, 'amount_received_currency' => Currency::Eur]);
    $pending = TransfersTracking::factory()->create(['ar_status' => ArStatus::Pending, 'net_amount_received' => '5.00']);

    expect(TransfersTracking::query()->settled()->pluck('id')->all())->toBe([$settled->id])
        ->and(TransfersTracking::query()->landedShort()->pluck('id')->all())->toBe([$settled->id])
        ->and(TransfersTracking::query()->missingNet()->pluck('id')->all())->toBe([$missing->id])
        ->and(TransfersTracking::query()->converted()->pluck('id')->all())->toBe([$converted->id])
        ->and($settled->fresh()->isSettled())->toBeTrue()
        ->and($settled->fresh()->difference())->toBe(-10.0)
        ->and($converted->fresh()->landedInAnotherCurrency())->toBeTrue()
        ->and($converted->fresh()->difference())->toBeNull()
        ->and($pending->fresh()->difference())->toBeNull();
});

test('a date range reads the date its basis names and leaves undated tranches out', function () {
    $tranche = TransfersTracking::factory()->create(['scheduled_date' => '2025-12-20', 'transfer_date' => '2026-01-05', 'receive_date' => '2026-02-10', 'ar_status' => ArStatus::Received]);
    TransfersTracking::factory()->create(['scheduled_date' => null, 'transfer_date' => null]);

    expect(TransfersTracking::query()->datedWithin('scheduled', '2025-12-01', '2025-12-31')->pluck('id')->all())->toBe([$tranche->id])
        ->and(TransfersTracking::query()->datedWithin('sent', '2026-01-01', null)->pluck('id')->all())->toBe([$tranche->id])
        ->and(TransfersTracking::query()->datedWithin('received', null, '2026-01-31')->count())->toBe(0)
        ->and(TransfersTracking::query()->datedWithin('nonsense', '2025-12-01', '2025-12-31')->count())->toBe(1);
});
```

(Keep the file's existing `use` lines; add only the two missing ones at the top.)

- [x] **Step 2: Run to see it fail**

Run: `.\php.cmd artisan test --compact tests/Feature/Models/TransfersTrackingTest.php` — Expected: FAIL, `Call to undefined method … inState()`.

- [x] **Step 3: Implement**

Add imports `use Illuminate\Database\Eloquent\Attributes\Scope;`, `use Illuminate\Database\Eloquent\Builder;`, `use InvalidArgumentException;`. Add inside the class, after `$table`:

```php
    /**
     * The states every funding page shares, in the vocabulary of state().
     *
     * @var list<string>
     */
    public const STATES = ['received', 'failed', 'transit', 'late', 'upcoming'];

    /**
     * The date column each date basis of a filter reads.
     *
     * @var array<string, string>
     */
    public const DATE_COLUMNS = ['scheduled' => 'scheduled_date', 'sent' => 'transfer_date', 'received' => 'receive_date'];
```

After `isMissingNetAmount()` add:

```php
    /**
     * Received, with its net recorded, and landed in the currency it was sent
     * in: the only tranche whose two ends can honestly be compared.
     */
    public function isSettled(): bool
    {
        return $this->ar_status === ArStatus::Received
            && $this->net_amount_received !== null
            && $this->amount_received_currency === $this->amount_transfered_currency;
    }

    /**
     * Received in a currency other than the one it was sent in.
     */
    public function landedInAnotherCurrency(): bool
    {
        return $this->ar_status === ArStatus::Received && $this->amount_received_currency !== $this->amount_transfered_currency;
    }

    /**
     * What landed minus what was sent, for a settled tranche only: two
     * currencies are never subtracted.
     */
    public function difference(): ?float
    {
        return $this->isSettled() ? round((float) $this->net_amount_received - (float) $this->payment_amount, 2) : null;
    }

    /**
     * state() as an SQL condition, so a filter, a count and a sum read a
     * tranche exactly as its row does.
     */
    public static function stateSql(string $state): string
    {
        $table = 'transfers_tracking';
        $today = CarbonImmutable::today()->toDateString();
        $pending = "{$table}.ar_status = '".ArStatus::Pending->value."'";

        return match ($state) {
            'received' => "({$table}.ar_status = '".ArStatus::Received->value."')",
            'failed' => "({$table}.ar_status = '".ArStatus::Failed->value."')",
            'transit' => "({$pending} and {$table}.transfer_date is not null)",
            'late' => "({$pending} and {$table}.transfer_date is null and {$table}.scheduled_date is not null and {$table}.scheduled_date < '{$today}')",
            'upcoming' => "({$pending} and {$table}.transfer_date is null and ({$table}.scheduled_date is null or {$table}.scheduled_date >= '{$today}'))",
            default => throw new InvalidArgumentException("Unknown tranche state [{$state}]."),
        };
    }

    /**
     * isSettled() as an SQL condition.
     */
    public static function settledSql(): string
    {
        return '('.self::stateSql('received').' and transfers_tracking.net_amount_received is not null and transfers_tracking.amount_received_currency = transfers_tracking.amount_transfered_currency)';
    }

    /**
     * A settled tranche that landed with less than was sent.
     */
    public static function landedShortSql(): string
    {
        return '('.self::settledSql().' and transfers_tracking.net_amount_received < transfers_tracking.payment_amount)';
    }

    /**
     * isMissingNetAmount() as an SQL condition.
     */
    public static function missingNetSql(): string
    {
        return '('.self::stateSql('received').' and transfers_tracking.net_amount_received is null)';
    }

    /**
     * landedInAnotherCurrency() as an SQL condition.
     */
    public static function convertedSql(): string
    {
        return '('.self::stateSql('received').' and transfers_tracking.amount_received_currency <> transfers_tracking.amount_transfered_currency)';
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function inState(Builder $query, string $state): void
    {
        $query->whereRaw(self::stateSql($state));
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function settled(Builder $query): void
    {
        $query->whereRaw(self::settledSql());
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function landedShort(Builder $query): void
    {
        $query->whereRaw(self::landedShortSql());
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function missingNet(Builder $query): void
    {
        $query->whereRaw(self::missingNetSql());
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function converted(Builder $query): void
    {
        $query->whereRaw(self::convertedSql());
    }

    /**
     * Tranches whose chosen date falls inside the range. An unknown basis
     * reads the scheduled date; a tranche without that date is left out.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function datedWithin(Builder $query, string $basis, ?string $from, ?string $to): void
    {
        $column = 'transfers_tracking.'.(self::DATE_COLUMNS[$basis] ?? 'scheduled_date');

        $query->when($from !== null, fn (Builder $query) => $query->where($column, '>=', $from))
            ->when($to !== null, fn (Builder $query) => $query->where($column, '<=', $to))
            ->when($from === null && $to === null, fn (Builder $query) => $query->whereNotNull($column));
    }
```

The scope is named `converted`, not `landedInAnotherCurrency`: a scope and an instance method cannot share a name.

- [x] **Step 4: Run** `.\php.cmd artisan test --compact tests/Feature/Models/TransfersTrackingTest.php` — Expected: PASS.

---

### Task 3: AgreementMoney and the DonorProposal scopes

**Files:** Create `app/Support/AgreementMoney.php`; modify `app/Models/DonorProposal.php`; Test: `tests/Feature/AgreementMoneyTest.php`.

**Interfaces — Produces** (exact names Phases 2–6 use):
- `yearBounds(?int $year): ?array{0: string, 1: string}`.
- `perAgreement(?int $year = null, bool $yearOnly = false): Illuminate\Database\Query\Builder` — one row per `donor_proposal_id` with `m_tranches, m_scheduled, m_received, m_received_gross, m_in_transit, m_overdue, m_overdue_count, m_upcoming, m_failed, m_net_missing, m_other_currency, m_next_due, m_last_received, m_due_in_year, m_received_in_year`.
- Constants `UNSCHEDULED_SQL`, `OUTSTANDING_SQL` (read `donor_proposal.budget` and the joined alias `money`).
- `agreementColumns(): string` (the `money_*` select list), `totalsSelect(): string`, `line(object|array $row): array`, `totals(iterable $agreements): list<array>`.
- `columns(bool $withTransfers = true, ?int $year = null, bool $yearOnly = false, bool $withFailed = false, bool $withReports = true): list<array{key: string, label: string, numeric: bool}>`.
- `perOwner(string $owner, ?int $year = null, bool $yearOnly = false, bool $withTransfers = true)` → `owner_id, currency, o_agreements, o_committed, o_tranches, o_received, o_outstanding, o_overdue, o_overdue_count, o_next_due, o_last_received, o_due_in_year, o_received_in_year`; `ownerSelect(): string` → `money_currency … money_received_in_year`; `ownerTotalsSelect(string $ownerKey): string` → `currency, owners, agreements, committed, received, outstanding, overdue, due_in_year, received_in_year`.
- `DonorProposal` scopes `joinMoney(?int $year = null, bool $yearOnly = false)` (left-joins `perAgreement` as `money`) and `withMoney(...)` (join + `agreementColumns()`; selects `donor_proposal.*` first when nothing is selected yet).

Definitions: **Scheduled** = payments of every tranche sent in the agreement's currency (failed included). **Received** = net of settled tranches in that currency. **Received gross** = payments of received tranches in that currency. **Not scheduled** = budget − scheduled. **Outstanding** = budget − received gross. Both floored at 0. **Due in year** = scheduled payments dated in the year; **received in year** = settled net received in the year. Year only = every figure counts only tranches scheduled in the year.

- [x] **Step 1: Write the failing test** — `tests/Feature/AgreementMoneyTest.php`:

```php
<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use App\Support\AgreementMoney;

beforeEach(fn () => $this->travelTo('2026-06-15 09:00:00'));

/**
 * 1,000 USD: 400 received (390 net, landed 2026-05-20), 200 overdue,
 * 100 upcoming, 50 in transit, one 10 EUR tranche.
 */
function moneyAgreement(array $attributes = []): DonorProposal
{
    $agreement = DonorProposal::factory()->create(['budget' => '1000.00', 'currency' => Currency::Usd, ...$attributes]);
    $tranche = fn (array $values) => TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id, 'amount_transfered_currency' => Currency::Usd, 'amount_received_currency' => Currency::Usd,
        'transfer_date' => null, 'receive_date' => null, 'ar_status' => ArStatus::Pending, ...$values,
    ]);

    $tranche(['payment_amount' => '400.00', 'net_amount_received' => '390.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2026-05-15', 'transfer_date' => '2026-05-15', 'receive_date' => '2026-05-20']);
    $tranche(['payment_amount' => '200.00', 'scheduled_date' => '2026-06-05']);
    $tranche(['payment_amount' => '100.00', 'scheduled_date' => '2026-06-25']);
    $tranche(['payment_amount' => '50.00', 'scheduled_date' => '2026-06-14', 'transfer_date' => '2026-06-14']);
    $tranche(['payment_amount' => '10.00', 'amount_transfered_currency' => Currency::Eur, 'scheduled_date' => '2025-03-01']);

    return $agreement;
}

function moneyRow(DonorProposal $agreement, ?int $year = null, bool $yearOnly = false): DonorProposal
{
    return DonorProposal::query()->withMoney($year, $yearOnly)->findOrFail($agreement->id);
}

test('an agreement carries every money figure from one join', function () {
    $row = moneyRow(moneyAgreement());

    expect((int) $row->money_tranches)->toBe(5)
        ->and((float) $row->money_scheduled)->toBe(750.0)
        ->and((float) $row->money_received)->toBe(390.0)
        ->and((float) $row->money_in_transit)->toBe(50.0)
        ->and((float) $row->money_overdue)->toBe(200.0)
        ->and((int) $row->money_overdue_count)->toBe(1)
        ->and((float) $row->money_upcoming)->toBe(100.0)
        ->and((int) $row->money_other_currency)->toBe(1)
        ->and((float) $row->money_unscheduled)->toBe(250.0)
        ->and((float) $row->money_outstanding)->toBe(600.0)
        ->and($row->money_next_due)->toBe('2025-03-01')
        ->and($row->money_last_received)->toBe('2026-05-20')
        ->and((float) $row->money_due_in_year)->toBe(0.0)
        ->and($row->budget)->toBe('1000.00');
});

test('an agreement with no tranche reads zero, never null', function () {
    $row = moneyRow(DonorProposal::factory()->create(['budget' => '500.00']));

    expect((float) $row->money_scheduled)->toBe(0.0)
        ->and((float) $row->money_unscheduled)->toBe(500.0)
        ->and((float) $row->money_outstanding)->toBe(500.0)
        ->and($row->money_next_due)->toBeNull();
});

test('a year adds its own figures, and year only counts nothing else', function () {
    $agreement = moneyAgreement();

    $beside = moneyRow($agreement, 2026);
    $only = moneyRow($agreement, 2025, true);

    expect((float) $beside->money_due_in_year)->toBe(750.0)
        ->and((float) $beside->money_received_in_year)->toBe(390.0)
        ->and((float) $beside->money_scheduled)->toBe(750.0)
        ->and((int) $only->money_tranches)->toBe(1)
        ->and((float) $only->money_scheduled)->toBe(0.0)
        ->and(AgreementMoney::yearBounds(2026))->toBe(['2026-01-01', '2026-12-31'])
        ->and(AgreementMoney::yearBounds(null))->toBeNull();
});

test('totals are one line per currency in SQL and in PHP alike', function () {
    moneyAgreement();
    DonorProposal::factory()->create(['budget' => '2000.00', 'currency' => Currency::Usd]);
    DonorProposal::factory()->create(['budget' => '300.00', 'currency' => Currency::Eur]);

    $sql = DonorProposal::query()->joinMoney()->toBase()->selectRaw(AgreementMoney::totalsSelect())
        ->groupBy('donor_proposal.currency')->orderBy('donor_proposal.currency')->get()
        ->map(fn (object $row): array => AgreementMoney::line($row))->keyBy('currency');
    $php = collect(AgreementMoney::totals(DonorProposal::query()->select('donor_proposal.*')->withMoney()->get()))->keyBy('currency');

    expect($sql->keys()->all())->toBe(['EUR', 'USD'])
        ->and($sql['USD'])->toMatchArray(['agreements' => 2, 'budget' => 3000.0, 'scheduled' => 750.0, 'received' => 390.0, 'unscheduled' => 2250.0, 'outstanding' => 2600.0])
        ->and($php->all())->toEqual($sql->all());

    $budgetsOnly = AgreementMoney::totals(DonorProposal::query()->get());

    expect(collect($budgetsOnly)->firstWhere('currency', 'USD')['received'])->toBe(0.0);
});

test('owners get one line per currency, with or without the tranche figures', function () {
    $donor = Donor::factory()->create();
    moneyAgreement(['donor_id' => $donor->id]);
    DonorProposal::factory()->create(['donor_id' => $donor->id, 'budget' => '500.00', 'currency' => Currency::Eur]);

    $lines = AgreementMoney::perOwner('donor_id')->where('donor_proposal.donor_id', $donor->id)->orderBy('currency')->get();
    $plain = AgreementMoney::perOwner('donor_id', withTransfers: false)->where('donor_proposal.donor_id', $donor->id)->orderBy('currency')->get();

    expect($lines->pluck('currency')->all())->toBe(['EUR', 'USD'])
        ->and((float) $lines[1]->o_committed)->toBe(1000.0)
        ->and((float) $lines[1]->o_received)->toBe(390.0)
        ->and((float) $lines[1]->o_outstanding)->toBe(600.0)
        ->and((int) $lines[1]->o_overdue_count)->toBe(1)
        ->and($lines[1]->o_last_received)->toBe('2026-05-20')
        ->and((float) $lines[0]->o_outstanding)->toBe(500.0)
        ->and((float) $plain[1]->o_received)->toBe(0.0);

    expect(fn () => AgreementMoney::perOwner('budget'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => AgreementMoney::ownerTotalsSelect('x; drop'))->toThrow(InvalidArgumentException::class);
});

test('the column list follows the permission, the year and the mode', function () {
    $keys = fn (array $columns): array => array_column($columns, 'key');

    expect($keys(AgreementMoney::columns()))->toBe(['currency', 'budget', 'scheduled', 'received', 'percent', 'in_transit', 'overdue', 'unscheduled', 'outstanding', 'next_due', 'tranches', 'reports'])
        ->and($keys(AgreementMoney::columns(year: 2026, withFailed: true)))->toBe(['currency', 'budget', 'scheduled', 'received', 'percent', 'due_in_year', 'received_in_year', 'in_transit', 'overdue', 'failed', 'unscheduled', 'outstanding', 'next_due', 'tranches', 'reports'])
        ->and($keys(AgreementMoney::columns(year: 2026, yearOnly: true, withReports: false)))->toBe(['currency', 'budget', 'scheduled', 'received', 'percent', 'in_transit', 'overdue', 'next_due', 'tranches'])
        ->and($keys(AgreementMoney::columns(withTransfers: false)))->toBe(['currency', 'budget', 'reports'])
        ->and(AgreementMoney::columns(year: 2026, yearOnly: true)[1]['label'])->toBe('Budget (whole agreement)');
});
```

- [x] **Step 2: Run to see it fail** — `.\php.cmd artisan test --compact tests/Feature/AgreementMoneyTest.php` — FAIL (`Class "App\Support\AgreementMoney" not found`).

- [x] **Step 3: Create `app/Support/AgreementMoney.php`**

```php
<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\TransfersTracking;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one definition of an agreement's money, in SQL, so a figure reads the
 * same in a row, a sort, a total and an export.
 *
 * Only tranches sent in the agreement's own currency are set against its
 * budget; a tranche in another currency is counted apart, never converted.
 */
final class AgreementMoney
{
    /** The part of the budget no tranche covers yet. Reads the joined alias `money`. */
    public const UNSCHEDULED_SQL = '(case when donor_proposal.budget > coalesce(money.m_scheduled, 0) then donor_proposal.budget - coalesce(money.m_scheduled, 0) else 0 end)';

    /** The part of the budget not yet received, counted in payment amounts so fees are not owed again. */
    public const OUTSTANDING_SQL = '(case when donor_proposal.budget > coalesce(money.m_received_gross, 0) then donor_proposal.budget - coalesce(money.m_received_gross, 0) else 0 end)';

    /**
     * The first and last day of a year, or null without one.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function yearBounds(?int $year): ?array
    {
        return $year === null ? null : [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)];
    }

    /**
     * One row per agreement with every tranche figure. In year-only mode every
     * figure counts only the tranches scheduled in that year.
     */
    public static function perAgreement(?int $year = null, bool $yearOnly = false): Builder
    {
        $t = 'transfers_tracking';
        $own = "{$t}.amount_transfered_currency = agreement.currency";
        $state = fn (string $name): string => TransfersTracking::stateSql($name);
        $sum = fn (string $condition, string $column = "{$t}.payment_amount"): string => "coalesce(sum(case when {$condition} then {$column} else 0 end), 0)";
        $count = fn (string $condition): string => "coalesce(sum(case when {$condition} then 1 else 0 end), 0)";
        $landed = "({$own} and ".TransfersTracking::settledSql().')';
        $bounds = self::yearBounds($year);

        $inYear = $bounds === null ? null : "between '{$bounds[0]}' and '{$bounds[1]}'";

        return DB::table($t)
            ->join('donor_proposal as agreement', 'agreement.id', '=', "{$t}.donor_proposal_id")
            ->when($yearOnly && $bounds !== null, fn (Builder $query) => $query->whereBetween("{$t}.scheduled_date", $bounds))
            ->groupBy("{$t}.donor_proposal_id")
            ->selectRaw(implode(', ', [
                "{$t}.donor_proposal_id as donor_proposal_id",
                'count(*) as m_tranches',
                $sum($own).' as m_scheduled',
                $sum($landed, "{$t}.net_amount_received").' as m_received',
                $sum("{$own} and ".$state('received')).' as m_received_gross',
                $sum("{$own} and ".$state('transit')).' as m_in_transit',
                $sum("{$own} and ".$state('late')).' as m_overdue',
                $count("{$own} and ".$state('late')).' as m_overdue_count',
                $sum("{$own} and ".$state('upcoming')).' as m_upcoming',
                $sum("{$own} and ".$state('failed')).' as m_failed',
                $count(TransfersTracking::missingNetSql()).' as m_net_missing',
                $count("not ({$own})").' as m_other_currency',
                'min(case when '.$state('late').' or '.$state('upcoming')." then {$t}.scheduled_date end) as m_next_due",
                'max(case when '.$state('received')." then {$t}.receive_date end) as m_last_received",
                ($inYear === null ? '0' : $sum("{$own} and {$t}.scheduled_date {$inYear}")).' as m_due_in_year',
                ($inYear === null ? '0' : $sum("{$landed} and {$t}.receive_date {$inYear}", "{$t}.net_amount_received")).' as m_received_in_year',
            ]));
    }

    /**
     * The money columns withMoney() adds to an agreement, zero when it has no tranche.
     */
    public static function agreementColumns(): string
    {
        $zeroed = ['tranches', 'scheduled', 'received', 'in_transit', 'overdue', 'overdue_count', 'upcoming', 'failed', 'net_missing', 'other_currency', 'due_in_year', 'received_in_year'];

        return implode(', ', [
            ...array_map(fn (string $name): string => "coalesce(money.m_{$name}, 0) as money_{$name}", $zeroed),
            'money.m_next_due as money_next_due',
            'money.m_last_received as money_last_received',
            self::UNSCHEDULED_SQL.' as money_unscheduled',
            self::OUTSTANDING_SQL.' as money_outstanding',
        ]);
    }

    /**
     * Totals per currency over agreements joined to `money`; group by donor_proposal.currency.
     */
    public static function totalsSelect(): string
    {
        $sums = ['tranches', 'scheduled', 'received', 'in_transit', 'overdue', 'failed', 'due_in_year', 'received_in_year'];

        return implode(', ', [
            'donor_proposal.currency as currency',
            'count(*) as agreements',
            'coalesce(sum(donor_proposal.budget), 0) as budget',
            ...array_map(fn (string $name): string => "coalesce(sum(money.m_{$name}), 0) as {$name}", $sums),
            'coalesce(sum('.self::UNSCHEDULED_SQL.'), 0) as unscheduled',
            'coalesce(sum('.self::OUTSTANDING_SQL.'), 0) as outstanding',
        ]);
    }

    /**
     * One totals row, typed.
     *
     * @return array{currency: string, agreements: int, tranches: int, budget: float, scheduled: float, received: float, in_transit: float, overdue: float, failed: float, unscheduled: float, outstanding: float, due_in_year: float, received_in_year: float}
     */
    public static function line(object|array $row): array
    {
        $row = (array) $row;
        $float = fn (string $key): float => (float) ($row[$key] ?? 0);

        return [
            'currency' => (string) $row['currency'],
            'agreements' => (int) ($row['agreements'] ?? 0),
            'tranches' => (int) ($row['tranches'] ?? 0),
            'budget' => $float('budget'),
            'scheduled' => $float('scheduled'),
            'received' => $float('received'),
            'in_transit' => $float('in_transit'),
            'overdue' => $float('overdue'),
            'failed' => $float('failed'),
            'unscheduled' => $float('unscheduled'),
            'outstanding' => $float('outstanding'),
            'due_in_year' => $float('due_in_year'),
            'received_in_year' => $float('received_in_year'),
        ];
    }

    /**
     * The same totals summed in PHP over agreements already loaded, for the
     * detail pages. An agreement loaded without withMoney() counts its budget
     * and, having no tranche figures, nothing else.
     *
     * @param  iterable<\App\Models\DonorProposal>  $agreements
     * @return list<array<string, mixed>>
     */
    public static function totals(iterable $agreements): array
    {
        $lines = [];

        foreach ($agreements as $agreement) {
            $values = $agreement->getAttributes();
            $currency = $agreement->currency->value;
            $lines[$currency] ??= ['currency' => $currency, 'agreements' => 0, 'tranches' => 0, 'budget' => 0.0, 'scheduled' => 0.0, 'received' => 0.0, 'in_transit' => 0.0, 'overdue' => 0.0, 'failed' => 0.0, 'unscheduled' => 0.0, 'outstanding' => 0.0, 'due_in_year' => 0.0, 'received_in_year' => 0.0];
            $lines[$currency]['agreements']++;
            $lines[$currency]['budget'] += (float) $agreement->budget;
            $lines[$currency]['tranches'] += (int) ($values['money_tranches'] ?? 0);

            foreach (['scheduled', 'received', 'in_transit', 'overdue', 'failed', 'unscheduled', 'outstanding', 'due_in_year', 'received_in_year'] as $key) {
                $lines[$currency][$key] += (float) ($values['money_'.$key] ?? 0);
            }
        }

        ksort($lines);

        return array_values($lines);
    }

    /**
     * The money columns of an agreement table, in order.
     *
     * @return list<array{key: string, label: string, numeric: bool}>
     */
    public static function columns(bool $withTransfers = true, ?int $year = null, bool $yearOnly = false, bool $withFailed = false, bool $withReports = true): array
    {
        $yearOnly = $yearOnly && $year !== null;
        $column = fn (string $key, string $label, bool $numeric = true): array => ['key' => $key, 'label' => $label, 'numeric' => $numeric];

        $columns = [
            $column('currency', __('common.currency'), false),
            $column('budget', $yearOnly ? __('finance_tables.budget_whole_agreement') : __('finance_tables.budget')),
        ];

        if ($withTransfers) {
            $columns[] = $column('scheduled', __('finance_tables.scheduled'));
            $columns[] = $column('received', __('finance_tables.received'));
            $columns[] = $column('percent', __('finance_tables.percent_received'));

            if ($year !== null && ! $yearOnly) {
                $columns[] = $column('due_in_year', __('finance_tables.due_in_year', ['year' => $year]));
                $columns[] = $column('received_in_year', __('finance_tables.received_in_year', ['year' => $year]));
            }

            $columns[] = $column('in_transit', __('finance_tables.in_transit'));
            $columns[] = $column('overdue', __('finance_tables.overdue'));

            if ($withFailed) {
                $columns[] = $column('failed', __('finance_tables.failed'));
            }

            if (! $yearOnly) {
                $columns[] = $column('unscheduled', __('finance_tables.not_scheduled'));
                $columns[] = $column('outstanding', __('finance_tables.outstanding'));
            }

            $columns[] = $column('next_due', __('finance_tables.next_due'), false);
            $columns[] = $column('tranches', __('finance_tables.tranches'));
        }

        if ($withReports) {
            $columns[] = $column('reports', __('funding_agreements.donor_reports'), false);
        }

        return $columns;
    }

    /**
     * One row per donor or proposal and agreement currency. Without the
     * transfers permission no tranche is read: those figures are zero.
     */
    public static function perOwner(string $owner, ?int $year = null, bool $yearOnly = false, bool $withTransfers = true): Builder
    {
        if (! in_array($owner, ['donor_id', 'proposal_id'], true)) {
            throw new InvalidArgumentException("Unknown agreement owner [{$owner}].");
        }

        $base = [
            "donor_proposal.{$owner} as owner_id",
            'donor_proposal.currency as currency',
            'count(*) as o_agreements',
            'coalesce(sum(donor_proposal.budget), 0) as o_committed',
        ];

        $query = DB::table('donor_proposal')->groupBy("donor_proposal.{$owner}", 'donor_proposal.currency');

        if (! $withTransfers) {
            return $query->selectRaw(implode(', ', [...$base, '0 as o_tranches', '0 as o_received', '0 as o_outstanding', '0 as o_overdue', '0 as o_overdue_count', 'null as o_next_due', 'null as o_last_received', '0 as o_due_in_year', '0 as o_received_in_year']));
        }

        return $query
            ->leftJoinSub(self::perAgreement($year, $yearOnly), 'money', 'money.donor_proposal_id', '=', 'donor_proposal.id')
            ->selectRaw(implode(', ', [
                ...$base,
                'coalesce(sum(money.m_tranches), 0) as o_tranches',
                'coalesce(sum(money.m_received), 0) as o_received',
                'coalesce(sum('.self::OUTSTANDING_SQL.'), 0) as o_outstanding',
                'coalesce(sum(money.m_overdue), 0) as o_overdue',
                'coalesce(sum(money.m_overdue_count), 0) as o_overdue_count',
                'min(money.m_next_due) as o_next_due',
                'max(money.m_last_received) as o_last_received',
                'coalesce(sum(money.m_due_in_year), 0) as o_due_in_year',
                'coalesce(sum(money.m_received_in_year), 0) as o_received_in_year',
            ]));
    }

    /**
     * The perOwner() columns, joined as `money`, under their row names.
     */
    public static function ownerSelect(): string
    {
        $columns = ['agreements', 'committed', 'tranches', 'received', 'outstanding', 'overdue', 'overdue_count', 'next_due', 'last_received', 'due_in_year', 'received_in_year'];

        return implode(', ', ['money.currency as money_currency', ...array_map(fn (string $name): string => "money.o_{$name} as money_{$name}", $columns)]);
    }

    /**
     * Totals per currency over owners joined to perOwner() as `money`.
     */
    public static function ownerTotalsSelect(string $ownerKey): string
    {
        if (preg_match('/^[a-z_]+\.[a-z_]+$/', $ownerKey) !== 1) {
            throw new InvalidArgumentException("Invalid owner key [{$ownerKey}].");
        }

        return implode(', ', [
            'money.currency as currency',
            "count(distinct {$ownerKey}) as owners",
            ...array_map(fn (string $name): string => "coalesce(sum(money.o_{$name}), 0) as {$name}", ['agreements', 'committed', 'received', 'outstanding', 'overdue', 'due_in_year', 'received_in_year']),
        ]);
    }
}
```

`totals()` relies on `use App\Models\DonorProposal` only in its docblock; the FQ name there is fine.

- [x] **Step 4: Add the scopes to `app/Models/DonorProposal.php`**

Imports: `use App\Support\AgreementMoney;`, `use Illuminate\Database\Eloquent\Attributes\Scope;`, `use Illuminate\Database\Eloquent\Builder;`. After `reports()` add:

```php
    /**
     * Left-join every agreement's money, aliased `money` (see AgreementMoney).
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function joinMoney(Builder $query, ?int $year = null, bool $yearOnly = false): void
    {
        $query->leftJoinSub(AgreementMoney::perAgreement($year, $yearOnly), 'money', 'money.donor_proposal_id', '=', 'donor_proposal.id');
    }

    /**
     * Join the money and select it as `money_*` attributes.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function withMoney(Builder $query, ?int $year = null, bool $yearOnly = false): void
    {
        if ($query->getQuery()->columns === null) {
            $query->select('donor_proposal.*');
        }

        $query->joinMoney($year, $yearOnly)->selectRaw(AgreementMoney::agreementColumns());
    }
```

- [x] **Step 5: Run** `.\php.cmd artisan test --compact tests/Feature/AgreementMoneyTest.php tests/Feature/Models/DonorProposalTest.php` — Expected: PASS. A `1055 … ONLY_FULL_GROUP_BY` error means a non-aggregated column slipped into a select; every column outside the group key must sit inside `sum`/`min`/`max`/`count`.

---

### Task 4: Tranches and CsvExport

**Files:** Create `app/Support/CsvExport.php`, `app/Support/Tranches.php`; modify `tests/Pest.php`; Tests `tests/Feature/CsvExportTest.php`, `tests/Feature/TranchesTest.php`.

**Interfaces — Produces:** `CsvExport::download(string $filename, array $headings, iterable $rows): StreamedResponse`, `::amount(string|float|int|null): string`, `::cell(mixed): string`; `Tranches::stateLabel(string $state, bool $missingNet = false): string`, `::timing(TransfersTracking): ?string`, `::totals(iterable): list<array{currency, tranches, sent, fees, gross, net, shortfall, overage}>`, `::csvHeadings(): list<string>` (18 columns), `::csvRow(TransfersTracking $transfer, string $donor, string $proposal, ?string $reference, ?string $account): list<mixed>`; Pest helper `downloadedCsv(Testable $component): list<list<string>>`.

CSV columns, in order: 0 state, 1 donor, 2 proposal, 3 reference, 4 account, 5 scheduled, 6 sent on, 7 received on, 8 currency, 9 amount sent, 10 fees, 11 fees currency, 12 rate, 13 gross, 14 net, 15 received currency, 16 difference, 17 recorded status (raw `ar_status`). Gross, net and received currency are empty unless the tranche is received.

- [x] **Step 1: Add the helper to `tests/Pest.php`** (import `use Livewire\Features\SupportTesting\Testable;`, append at the end):

```php
/**
 * The rows of the CSV file a Livewire action just sent, heading row first.
 *
 * @return list<list<string>>
 */
function downloadedCsv(Testable $component): array
{
    $content = (string) base64_decode((string) data_get($component->effects, 'download.content'));
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $content));
    rewind($stream);

    $rows = [];
    while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
        $rows[] = $row;
    }

    fclose($stream);

    return $rows;
}
```

- [x] **Step 2: Write the failing tests**

`tests/Feature/CsvExportTest.php`:

```php
<?php

use App\Enums\Currency;
use App\Support\CsvExport;
use Carbon\CarbonImmutable;

test('a csv starts with a byte order mark and writes plain cells', function () {
    $response = CsvExport::download('x.csv', ['Name', 'Amount'], [
        ['=HYPERLINK("x")', CsvExport::amount('1234.5')],
        ['-15.00', CarbonImmutable::parse('2026-03-05')],
        [null, Currency::Usd],
    ]);

    ob_start();
    $response->sendContent();
    $content = (string) ob_get_clean();

    expect($content)->toStartWith("\xEF\xBB\xBF")
        ->and($response->headers->get('content-type'))->toContain('text/csv')
        ->and($content)->toContain("'=HYPERLINK")
        ->and($content)->toContain('1234.50')
        ->and($content)->toContain("-15.00,2026-03-05")
        ->and($content)->toContain(',USD')
        ->and(CsvExport::amount(null))->toBe('')
        ->and(CsvExport::cell('+961 1'))->toBe("'+961 1")
        ->and(CsvExport::cell('<1'))->toBe('<1');
});
```

`tests/Feature/TranchesTest.php`:

```php
<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\TransfersTracking;
use App\Support\Tranches;

beforeEach(fn () => $this->travelTo('2026-06-15 09:00:00'));

test('timing reads overdue, in transit and landed days', function () {
    $tranche = fn (array $values): TransfersTracking => TransfersTracking::factory()->create($values)->fresh();

    expect(Tranches::timing($tranche(['scheduled_date' => '2026-06-10', 'transfer_date' => null])))->toBe('5 days overdue')
        ->and(Tranches::timing($tranche(['scheduled_date' => '2026-06-01', 'transfer_date' => '2026-06-14'])))->toBe('In transit for 1 day')
        ->and(Tranches::timing($tranche(['ar_status' => ArStatus::Received, 'transfer_date' => '2026-04-03', 'receive_date' => '2026-04-08'])))->toBe('Landed in 5 days')
        ->and(Tranches::timing($tranche(['ar_status' => ArStatus::Received, 'transfer_date' => '2026-04-08', 'receive_date' => '2026-04-03'])))->toBe('Landed the same day')
        ->and(Tranches::timing($tranche(['scheduled_date' => '2026-07-01', 'transfer_date' => null])))->toBeNull()
        ->and(Tranches::stateLabel('late'))->toBe('Overdue')
        ->and(Tranches::stateLabel('received', true))->toBe('Net not recorded');
});

test('totals stay per currency and never net an overage against a shortfall', function () {
    $received = ['ar_status' => ArStatus::Received, 'amount_transfered_currency' => Currency::Usd, 'amount_received_currency' => Currency::Usd, 'fees_currency' => Currency::Usd];

    $lines = Tranches::totals(collect([
        TransfersTracking::factory()->make([...$received, 'payment_amount' => '100.00', 'fees' => '2.00', 'amount_received' => '95.00', 'net_amount_received' => '90.00']),
        TransfersTracking::factory()->make([...$received, 'payment_amount' => '50.00', 'fees' => '0.00', 'amount_received' => '80.00', 'net_amount_received' => '80.00']),
        TransfersTracking::factory()->make(['payment_amount' => '70.00', 'amount_transfered_currency' => Currency::Eur, 'net_amount_received' => '70.00']),
    ]));

    expect($lines)->toBe([
        ['currency' => 'EUR', 'tranches' => 1, 'sent' => 70.0, 'fees' => 0.0, 'gross' => 0.0, 'net' => 0.0, 'shortfall' => 0.0, 'overage' => 0.0],
        ['currency' => 'USD', 'tranches' => 2, 'sent' => 150.0, 'fees' => 2.0, 'gross' => 175.0, 'net' => 170.0, 'shortfall' => 10.0, 'overage' => 30.0],
    ]);
});

test('a csv row holds plain values and hides what has not landed', function () {
    $settled = TransfersTracking::factory()->create(['ar_status' => ArStatus::Received, 'payment_amount' => '1000.00', 'fees' => '10.00', 'amount_received' => '995.00', 'net_amount_received' => '985.00', 'exchange_rate' => '0.925000', 'scheduled_date' => '2026-03-01', 'transfer_date' => '2026-03-02', 'receive_date' => '2026-03-05'])->fresh();
    $pending = TransfersTracking::factory()->create(['payment_amount' => '500.00', 'net_amount_received' => '777.00', 'scheduled_date' => '2026-07-01', 'transfer_date' => null])->fresh();

    $row = Tranches::csvRow($settled, 'ECHO', 'Water', 'WR-9', 'Operations');
    $later = Tranches::csvRow($pending, 'ECHO', 'Water', null, null);

    expect(Tranches::csvHeadings())->toHaveCount(18)
        ->and($row)->toHaveCount(18)
        ->and([$row[0], $row[9], $row[12], $row[13], $row[14], $row[16], $row[17]])->toBe(['Received', '1000.00', '0.925', '995.00', '985.00', '-15.00', 'received'])
        ->and([$later[0], $later[13], $later[14], $later[15], $later[16]])->toBe(['Upcoming', '', '', '', '']);
});
```

Run: `.\php.cmd artisan test --compact tests/Feature/CsvExportTest.php tests/Feature/TranchesTest.php` — FAIL (classes missing).

- [x] **Step 3: Create `app/Support/CsvExport.php`**

```php
<?php

declare(strict_types=1);

namespace App\Support;

use BackedEnum;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a CSV a spreadsheet opens cleanly: UTF-8 with a byte order mark,
 * ISO dates, plain numbers, and no cell a spreadsheet would run as a formula.
 */
final class CsvExport
{
    /**
     * @param  list<string>  $headings
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function download(string $filename, array $headings, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headings, $rows): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, array_map(self::cell(...), $headings), ',', '"', '');

            foreach ($rows as $row) {
                fputcsv($output, array_map(self::cell(...), array_values($row)), ',', '"', '');
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * An amount as a plain number with two decimals, or empty.
     */
    public static function amount(string|float|int|null $value): string
    {
        return $value === null ? '' : number_format((float) $value, 2, '.', '');
    }

    /**
     * One cell as text. Text that starts like a formula is prefixed with an
     * apostrophe; numbers, negative ones included, are left alone.
     */
    public static function cell(mixed $value): string
    {
        $text = match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            $value instanceof BackedEnum => (string) $value->value,
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };

        return $text !== '' && ! is_numeric($text) && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }
}
```

- [x] **Step 4: Create `app/Support/Tranches.php`**

```php
<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;

/**
 * A list of tranches already in memory: labels, timing, totals and CSV rows,
 * by the same rules the transfers page applies in SQL.
 */
final class Tranches
{
    /**
     * Every state key written out in full (LocalizationTest reads keys literally).
     */
    public static function stateLabel(string $state, bool $missingNet = false): string
    {
        if ($missingNet) {
            return __('finance_tables.state_net_missing');
        }

        return match ($state) {
            'received' => __('finance_tables.state_received'),
            'failed' => __('finance_tables.state_failed'),
            'transit' => __('finance_tables.state_transit'),
            'late' => __('finance_tables.state_late'),
            default => __('finance_tables.state_upcoming'),
        };
    }

    /**
     * How late, how long in transit, or how long the journey took.
     */
    public static function timing(TransfersTracking $transfer): ?string
    {
        $today = CarbonImmutable::today();

        return match ($transfer->state()) {
            'late' => trans_choice('transfers.count_days_overdue', $days = (int) $transfer->scheduled_date->diffInDays($today), ['count' => $days]),
            'transit' => trans_choice('transfers.in_transit_for_count_days', $days = max(0, (int) $transfer->transfer_date->diffInDays($today)), ['count' => $days]),
            'received' => $transfer->transfer_date !== null && $transfer->receive_date !== null
                ? trans_choice('transfers.landed_in_count_days', $days = max(0, (int) $transfer->transfer_date->diffInDays($transfer->receive_date, false)), ['count' => $days])
                : null,
            default => null,
        };
    }

    /**
     * One line per currency sent. Shortfall and overage are summed per
     * tranche and never netted against each other.
     *
     * @param  iterable<TransfersTracking>  $transfers
     * @return list<array{currency: string, tranches: int, sent: float, fees: float, gross: float, net: float, shortfall: float, overage: float}>
     */
    public static function totals(iterable $transfers): array
    {
        $lines = [];

        foreach ($transfers as $transfer) {
            $currency = $transfer->amount_transfered_currency->value;
            $payment = (float) $transfer->payment_amount;
            $line = $lines[$currency] ?? ['currency' => $currency, 'tranches' => 0, 'sent' => 0.0, 'fees' => 0.0, 'gross' => 0.0, 'net' => 0.0, 'shortfall' => 0.0, 'overage' => 0.0];

            $line['tranches']++;
            $line['sent'] += $payment;

            if ($transfer->fees_currency === $transfer->amount_transfered_currency) {
                $line['fees'] += (float) $transfer->fees;
            }

            if ($transfer->state() === 'received' && $transfer->amount_received_currency === $transfer->amount_transfered_currency) {
                $line['gross'] += (float) $transfer->amount_received;
            }

            if ($transfer->isSettled()) {
                $net = (float) $transfer->net_amount_received;
                $line['net'] += $net;
                $line['shortfall'] += max(0.0, $payment - $net);
                $line['overage'] += max(0.0, $net - $payment);
            }

            $lines[$currency] = $line;
        }

        ksort($lines);

        return array_values(array_map(
            fn (array $line): array => array_map(fn (mixed $value): mixed => is_float($value) ? round($value, 2) : $value, $line),
            $lines,
        ));
    }

    /**
     * @return list<string>
     */
    public static function csvHeadings(): array
    {
        return [
            __('common.state'), __('funding_agreements.donor'), __('common.proposal'), __('finance_tables.reference'), __('transfers.account'),
            __('transfers.scheduled'), __('transfers.sent_on'), __('transfers.received_on'), __('common.currency'), __('finance_tables.amount_sent'),
            __('transfers.fees'), __('finance_tables.fees_currency'), __('transfers.exchange_rate'), __('finance_tables.gross_received'), __('common.net_received'),
            __('finance_tables.received_currency'), __('finance_tables.difference'), __('finance_tables.recorded_status'),
        ];
    }

    /**
     * @return list<mixed>
     */
    public static function csvRow(TransfersTracking $transfer, string $donor, string $proposal, ?string $reference, ?string $account): array
    {
        $received = $transfer->state() === 'received';
        $rate = rtrim(rtrim(number_format((float) $transfer->exchange_rate, 6, '.', ''), '0'), '.');

        return [
            self::stateLabel($transfer->state(), $transfer->isMissingNetAmount()),
            $donor, $proposal, $reference, $account,
            $transfer->scheduled_date, $transfer->transfer_date, $transfer->receive_date,
            $transfer->amount_transfered_currency->value,
            CsvExport::amount($transfer->payment_amount),
            CsvExport::amount($transfer->fees),
            $transfer->fees_currency->value,
            $rate === '' ? '0' : $rate,
            $received ? CsvExport::amount($transfer->amount_received) : '',
            $received ? CsvExport::amount($transfer->net_amount_received) : '',
            $received ? $transfer->amount_received_currency->value : '',
            CsvExport::amount($transfer->difference()),
            $transfer->ar_status->value,
        ];
    }
}
```

The final `array_map` rounds floats to cents so sums compare exactly.

- [x] **Step 5: Run** `.\php.cmd artisan test --compact tests/Feature/CsvExportTest.php tests/Feature/TranchesTest.php tests/Feature/LocalizationTest.php` — PASS.

---

### Task 5: The register traits

**Files:** Create `app/Livewire/Concerns/SwitchesFundingView.php`, `app/Livewire/Concerns/FiltersFinanceRegister.php`; Test `tests/Feature/FinanceRegisterTraitTest.php`.

**Interfaces — Produces:**
- `SwitchesFundingView`: `#[Url(except: 'table')] public string $view = 'table'` ('table'|'overview').
- `FiltersFinanceRegister` (uses `SwitchesFundingView`): `#[Url(except: '')] $year`, `#[Url(except: 'lifetime')] $figures` ('lifetime'|'year', 'year' only with a year), `#[Url(except: '')] $sortBy`, `#[Url(except: '')] $sortDir`, `#[Url(except: '25')] $perPage` ('25'|'50'|'100'); `sortRegister(string $key)`; computed `yearOptions(): list<int>`; protected `activeSort()`, `activeSortDir()`, `applyRegisterSort(Builder)`, `selectedYear(): ?int`, `yearOnly(): bool`, `registerPerPage(): int`, `resetRegisterFilters()` (year and figures only), `normaliseRegister()`; abstract `registerSorts(): array<string, array{dir, order: list<string>}>` (`{dir}` placeholder in each order) and `registerDefaultSort(): string`. It does not own `currency`.
- Order of hooks (Livewire 4.4): the page's own `mount()`/`updated()` run first, then the trait hooks.

- [x] **Step 1: Write the failing test** (an anonymous component exercises the trait):

```php
<?php

use App\Livewire\Concerns\FiltersFinanceRegister;
use App\Models\TransfersTracking;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\WithPagination;

function registerProbe(): Component
{
    return new class extends Component
    {
        use FiltersFinanceRegister;
        use WithPagination;

        protected function registerSorts(): array
        {
            return [
                'scheduled' => ['dir' => 'desc', 'order' => ['transfers_tracking.scheduled_date {dir}', 'transfers_tracking.id desc']],
                'amount' => ['dir' => 'desc', 'order' => ['transfers_tracking.payment_amount {dir}', 'transfers_tracking.id desc']],
            ];
        }

        protected function registerDefaultSort(): string
        {
            return 'scheduled';
        }

        public function ids(): array
        {
            return $this->applyRegisterSort(TransfersTracking::query())->pluck('id')->all();
        }

        public function render(): string
        {
            return '<div>{{ $this->selectedYear() }}|{{ $this->yearOnly() ? "only" : "beside" }}</div>';
        }
    };
}

test('values from the address bar fall back to the defaults', function () {
    Livewire::withQueryParams(['view' => 'grid', 'year' => '20x6', 'figures' => 'decade', 'sortBy' => 'id;drop', 'sortDir' => 'up', 'perPage' => '7'])
        ->test(registerProbe())
        ->assertSet('view', 'table')->assertSet('year', '')->assertSet('figures', 'lifetime')
        ->assertSet('sortBy', '')->assertSet('sortDir', '')->assertSet('perPage', '25');
});

test('year only needs a year', function () {
    Livewire::test(registerProbe())
        ->set('figures', 'year')->assertSet('figures', 'lifetime')
        ->set('year', '2026')->set('figures', 'year')->assertSee('2026|only')
        ->set('year', '')->assertSet('figures', 'lifetime');
});

test('a heading sorts by its default direction and a second click reverses it', function () {
    $small = TransfersTracking::factory()->create(['payment_amount' => '10.00']);
    $large = TransfersTracking::factory()->create(['payment_amount' => '900.00']);

    $page = Livewire::test(registerProbe())->call('sortRegister', 'amount')->assertSet('sortBy', 'amount')->assertSet('sortDir', 'desc');
    expect($page->instance()->ids())->toBe([$large->id, $small->id]);

    $page->call('sortRegister', 'amount')->assertSet('sortDir', 'asc');
    expect($page->instance()->ids())->toBe([$small->id, $large->id]);

    $page->call('sortRegister', 'unknown')->assertSet('sortBy', 'amount');
});

test('year options list every year a tranche is dated in, newest first', function () {
    $this->travelTo('2026-06-15 09:00:00');
    TransfersTracking::factory()->create(['scheduled_date' => '2024-03-01', 'receive_date' => null]);

    expect(Livewire::test(registerProbe())->instance()->yearOptions)->toBe([2026, 2024]);
});
```

`resetRegisterFilters()` is protected; every page in Phases 1–5 covers it through `clearFilters()`.

- [x] **Step 2: Create `app/Livewire/Concerns/SwitchesFundingView.php`**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Livewire\Attributes\Url;

/**
 * The Table | Overview switch every Funding page shares. Table is the default.
 */
trait SwitchesFundingView
{
    #[Url(except: 'table')]
    public string $view = 'table';

    public function mountSwitchesFundingView(): void
    {
        $this->normaliseFundingView();
    }

    public function updatedSwitchesFundingView(string $property): void
    {
        if ($property === 'view') {
            $this->normaliseFundingView();
        }
    }

    protected function normaliseFundingView(): void
    {
        if (! in_array($this->view, ['table', 'overview'], true)) {
            $this->view = 'table';
        }
    }
}
```

- [x] **Step 3: Create `app/Livewire/Concerns/FiltersFinanceRegister.php`**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * Year, figures mode, column sort and page size for a Funding register.
 * Every value arrives from the URL, so each is normalised on the way in.
 */
trait FiltersFinanceRegister
{
    use SwitchesFundingView;

    /** @var list<string> */
    private const REGISTER_PAGE_SIZES = ['25', '50', '100'];

    #[Url(except: '')]
    public string $year = '';

    /** Whole-agreement figures with the year beside them, or every figure for the year only. */
    #[Url(except: 'lifetime')]
    public string $figures = 'lifetime';

    #[Url(except: '')]
    public string $sortBy = '';

    #[Url(except: '')]
    public string $sortDir = '';

    #[Url(except: '25')]
    public string $perPage = '25';

    /**
     * What each column heading sorts by: its default direction and the SQL
     * order terms, each with a {dir} placeholder where the direction goes.
     *
     * @return array<string, array{dir: 'asc'|'desc', order: list<string>}>
     */
    abstract protected function registerSorts(): array;

    abstract protected function registerDefaultSort(): string;

    public function mountFiltersFinanceRegister(): void
    {
        $this->normaliseRegister();
    }

    public function updatedFiltersFinanceRegister(string $property): void
    {
        if (in_array($property, ['year', 'figures', 'sortBy', 'sortDir', 'perPage'], true)) {
            $this->normaliseRegister();
            $this->resetRegisterPage();
        }
    }

    /**
     * Sort by a heading, or reverse it when it already leads.
     */
    public function sortRegister(string $key): void
    {
        $sorts = $this->registerSorts();

        if (! array_key_exists($key, $sorts)) {
            return;
        }

        $this->sortDir = $this->activeSort() === $key
            ? ($this->activeSortDir() === 'asc' ? 'desc' : 'asc')
            : $sorts[$key]['dir'];
        $this->sortBy = $key;
        $this->resetRegisterPage();
    }

    /**
     * Every year a tranche is scheduled or received in, plus this year and
     * the chosen one, newest first.
     *
     * @return list<int>
     */
    #[Computed]
    public function yearOptions(): array
    {
        $years = TransfersTracking::query()->toBase()
            ->selectRaw('year(scheduled_date) as y')->whereNotNull('scheduled_date')
            ->union(TransfersTracking::query()->toBase()->selectRaw('year(receive_date) as y')->whereNotNull('receive_date'))
            ->pluck('y')
            ->map(fn ($year): int => (int) $year)
            ->push(CarbonImmutable::today()->year)
            ->when($this->selectedYear() !== null, fn ($years) => $years->push($this->selectedYear()))
            ->unique()
            ->sortDesc()
            ->values()
            ->all();

        return $years;
    }

    protected function activeSort(): string
    {
        return array_key_exists($this->sortBy, $this->registerSorts()) ? $this->sortBy : $this->registerDefaultSort();
    }

    protected function activeSortDir(): string
    {
        return in_array($this->sortDir, ['asc', 'desc'], true) ? $this->sortDir : $this->registerSorts()[$this->activeSort()]['dir'];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function applyRegisterSort(Builder $query): Builder
    {
        $direction = $this->activeSortDir();

        foreach ($this->registerSorts()[$this->activeSort()]['order'] as $term) {
            $query->orderByRaw(str_replace('{dir}', $direction, $term));
        }

        return $query;
    }

    protected function selectedYear(): ?int
    {
        return preg_match('/^(19|20|21)\d{2}$/', $this->year) === 1 ? (int) $this->year : null;
    }

    protected function yearOnly(): bool
    {
        return $this->selectedYear() !== null && $this->figures === 'year';
    }

    protected function registerPerPage(): int
    {
        return (int) $this->perPage;
    }

    /**
     * Clear the year and the figures mode. The view, the sort and the page
     * size are choices about reading, not filters, so they stay.
     */
    protected function resetRegisterFilters(): void
    {
        $this->year = '';
        $this->figures = 'lifetime';
    }

    protected function normaliseRegister(): void
    {
        $this->normaliseFundingView();

        if ($this->selectedYear() === null) {
            $this->year = '';
        }

        if ($this->figures !== 'year' || $this->year === '') {
            $this->figures = 'lifetime';
        }

        if (! array_key_exists($this->sortBy, $this->registerSorts())) {
            $this->sortBy = '';
        }

        if (! in_array($this->sortDir, ['asc', 'desc'], true)) {
            $this->sortDir = '';
        }

        if (! in_array($this->perPage, self::REGISTER_PAGE_SIZES, true)) {
            $this->perPage = '25';
        }
    }

    private function resetRegisterPage(): void
    {
        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }
}
```

If PHP rejects `private const` inside the trait on the installed version, move the list into `normaliseRegister()` as a local array.

- [x] **Step 4: Run** `.\php.cmd artisan test --compact tests/Feature/FinanceRegisterTraitTest.php tests/Feature/AccessControl/AuthorizationCoverageTest.php` — PASS.

If `Livewire::test(new class …)` cannot render the inline string on this Livewire version, return `view('components.funding.probe-test')` is **not** an option (no test views in `resources`); instead turn the probe into a named class at the bottom of the test file (`final class FinanceRegisterProbe extends Component`) and pass `FinanceRegisterProbe::class`.

---

### Task 6: Components and CSS

**Files:** Create the seven Blade files below; append to `resources/css/app.css`; Test `tests/Feature/FinanceKitComponentsTest.php`.

- [x] **Step 1: Write the failing test**

```php
<?php

use Illuminate\Support\Facades\Blade;

test('the register, its heading, the totals, the pills and the pager print the hooks other pages rely on', function () {
    $register = Blade::render('<x-funding.register size="xl" caption="Cap" class="mt-4"><x-slot:head><x-funding.register.th sort="amount" active="amount" dir="desc" numeric>Amount</x-funding.register.th><x-funding.register.th>Plain</x-funding.register.th></x-slot:head><tr><td>1</td><td>2</td></tr><x-slot:foot><tr><td>F</td></tr></x-slot:foot></x-funding.register>');

    expect($register)->toContain('data-register')->toContain('data-data-table')->toContain('register-xl')->toContain('mt-4')
        ->toContain('wire:click="sortRegister(\'amount\')"')->toContain('aria-sort="descending"')->toContain('<tfoot>')
        ->and(substr_count($register, '<th scope='))->toBe(2);

    $totals = Blade::render('<x-funding.totals :lines="$lines" />', ['lines' => [[
        'currency' => 'USD', 'caption' => '2 tranches',
        'figures' => [['key' => 'shortfall', 'label' => 'Shortfall', 'value' => 10.0, 'prefix' => '−', 'tone' => 'critical', 'note' => '1%']],
        'notes' => ['A note'],
    ]]]);

    expect($totals)->toContain('data-register-totals')->toContain('data-totals-currency="USD"')->toContain('data-total="shortfall"')
        ->toContain('−10.00')->toContain('A note')->toContain('1%')
        ->and(Blade::render('<x-funding.totals :lines="[]" />'))->not->toContain('data-register');

    $filters = Blade::render('<x-funding.filters :active="true"><x-funding.pills label="Year" :pills="$pills" /></x-funding.filters>', ['pills' => [
        ['label' => '2026', 'click' => "\$set('year', '2026')", 'active' => true, 'count' => 3, 'test' => 'year-2026'],
        null,
    ]]);

    expect($filters)->toContain('data-finance-filters')->not->toContain('data-register')
        ->toContain('aria-pressed="true"')->toContain('data-test="year-2026"')->toContain('data-test="reset-filters"');
});
```

(The pager needs a paginator; Phases 1–5 render it on real pages.)

- [x] **Step 2: Create the components**

`resources/views/components/funding/register.blade.php`:

```blade
@props(['caption' => null, 'size' => 'lg'])

{{--
    A Funding register: one real table, every figure in its own column. The
    outer element is a size container; while the table fits, nothing scrolls
    sideways and the heading row sticks under the app bar; below that the
    table scrolls inside itself with its first column pinned; on a phone each
    row becomes a block of labelled pairs (td data-label). See app.css.
--}}
<div {{ $attributes->class(['register', 'register-'.$size]) }} data-register data-data-table>
    <div class="register-scroll">
        <table>
            @if (filled($caption))
                <caption class="sr-only">{{ $caption }}</caption>
            @endif
            <thead>
                <tr>{{ $head }}</tr>
            </thead>
            <tbody>
                {{ $slot }}
            </tbody>
            @isset($foot)
                <tfoot>{{ $foot }}</tfoot>
            @endisset
        </table>
    </div>
</div>
```

`resources/views/components/funding/register/th.blade.php`:

```blade
@props(['sort' => null, 'active' => '', 'dir' => 'asc', 'numeric' => false])

@php
    $isActive = $sort !== null && $sort === $active;
@endphp

<th scope="col" {{ $attributes->class(['num' => $numeric]) }} @if ($isActive) aria-sort="{{ $dir === 'desc' ? 'descending' : 'ascending' }}" @endif @if ($sort !== null) data-sort="{{ $sort }}" @endif>
    @if ($sort === null)
        {{ $slot }}
    @else
        <button type="button" wire:click="sortRegister('{{ $sort }}')" class="register-sort" aria-label="{{ __('finance_tables.sort_by', ['column' => trim(strip_tags((string) $slot))]) }}">
            <span>{{ $slot }}</span>
            @if (! $isActive)
                <flux:icon.chevron-up-down variant="micro" class="size-3.5 opacity-50" />
            @elseif ($dir === 'desc')
                <flux:icon.chevron-down variant="micro" class="size-3.5" />
            @else
                <flux:icon.chevron-up variant="micro" class="size-3.5" />
            @endif
        </button>
    @endif
</th>
```

`resources/views/components/funding/filters.blade.php`:

```blade
@props(['active' => false, 'reset' => 'clearFilters'])

<div {{ $attributes->class('flex flex-col gap-2') }} data-finance-filters>
    {{ $slot }}

    @if ($active)
        <div class="flex justify-end">
            <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-ink-muted!" wire:click="{{ $reset }}" data-test="reset-filters">{{ __('common.reset_filters') }}</flux:button>
        </div>
    @endif
</div>
```

`resources/views/components/funding/filter-row.blade.php`:

```blade
@props(['label'])

<div {{ $attributes->class('flex flex-wrap items-center gap-x-2 gap-y-1.5') }} role="group" aria-label="{{ $label }}">
    <span class="type-label w-24 shrink-0 text-secondary-medium dark:text-ink-faint">{{ $label }}</span>
    {{ $slot }}
</div>
```

`resources/views/components/funding/pills.blade.php`:

```blade
@props(['label', 'pills' => []])

{{-- One value per row: a pill sets it, the active pill clears it. A null entry is skipped. --}}
<x-funding.filter-row :label="$label" {{ $attributes }}>
    @foreach (array_filter($pills) as $pill)
        @php
            $isActive = (bool) ($pill['active'] ?? false);
            $tone = $pill['tone'] ?? null;
        @endphp
        <button
            type="button"
            wire:click="{{ $pill['click'] }}"
            aria-pressed="{{ $isActive ? 'true' : 'false' }}"
            @isset($pill['test']) data-test="{{ $pill['test'] }}" @endisset
            @class([
                'inline-flex min-h-8 touch-manipulation items-center gap-1.5 rounded-full px-3 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                'bg-secondary-dark text-white dark:bg-ink dark:text-surface-0' => $isActive,
                'text-secondary-dark hover:bg-secondary-soft dark:text-ink dark:hover:bg-surface-2' => ! $isActive,
            ])
        >
            @if ($tone !== null && ! $isActive)
                <span @class(['size-1.5 rounded-full', 'bg-primary dark:bg-status-critical' => $tone === 'critical', 'bg-status-warn' => $tone === 'warn']) aria-hidden="true"></span>
            @endif
            {{ $pill['label'] }}
            @if (array_key_exists('count', $pill))
                <span class="tabular-nums opacity-70">{{ $pill['count'] }}</span>
            @endif
        </button>
    @endforeach

    {{ $slot }}
</x-funding.filter-row>
```

`resources/views/components/funding/totals.blade.php`:

```blade
@props(['lines' => []])

@php
    $toneClass = fn (?string $tone): string => match ($tone) {
        'good' => 'text-funding',
        'warn' => 'text-status-warn',
        'critical' => 'text-status-critical',
        'muted' => 'text-secondary-medium dark:text-ink-muted',
        default => 'text-secondary-dark dark:text-ink',
    };
@endphp

{{-- One typographic line per currency; currencies are never added together. --}}
@if (count($lines) > 0)
    <div {{ $attributes->class('flex flex-col divide-y divide-secondary-soft dark:divide-line') }} data-register-totals>
        @foreach ($lines as $line)
            <div class="flex flex-wrap items-start gap-x-8 gap-y-3 py-4 first:pt-0" wire:key="totals-{{ $line['currency'] }}" data-totals-currency="{{ $line['currency'] }}">
                <div class="w-24 shrink-0">
                    <p class="text-xl leading-none font-bold text-secondary-dark dark:text-ink">{{ $line['currency'] }}</p>
                    @if (filled($line['caption'] ?? null))
                        <p class="mt-1 text-xs text-secondary-medium dark:text-ink-muted">{{ $line['caption'] }}</p>
                    @endif
                </div>
                <dl class="flex min-w-0 flex-1 flex-wrap gap-x-9 gap-y-3">
                    @foreach ($line['figures'] as $figure)
                        <div data-total="{{ $figure['key'] }}">
                            <dt class="type-label text-secondary-medium dark:text-ink-faint">{{ $figure['label'] }}</dt>
                            <dd class="mt-0.5 flex items-baseline gap-1.5">
                                <span dir="ltr" class="text-lg font-bold tabular-nums {{ $toneClass($figure['tone'] ?? null) }}">{{ $figure['prefix'] ?? '' }}{{ number_format((float) $figure['value'], 2) }}</span>
                                @if (filled($figure['note'] ?? null))
                                    <span class="text-xs font-semibold text-secondary-medium dark:text-ink-muted">{{ $figure['note'] }}</span>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
                @if (($line['notes'] ?? []) !== [])
                    <ul class="basis-full ps-32 text-xs text-secondary-medium dark:text-ink-muted max-sm:ps-0">
                        @foreach ($line['notes'] as $note)
                            <li>{{ $note }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>
@endif
```

`resources/views/components/funding/pager.blade.php`:

```blade
@props(['paginator', 'perPage' => '25', 'scrollTo' => null])

<div {{ $attributes->class('flex flex-wrap items-center justify-between gap-x-6 gap-y-3') }} data-finance-pager>
    <div class="flex items-center gap-1" role="group" aria-label="{{ __('finance_tables.rows_per_page') }}">
        <span class="type-label me-1 text-secondary-medium dark:text-ink-faint">{{ __('finance_tables.rows_per_page') }}</span>
        @foreach (['25', '50', '100'] as $size)
            <button type="button" wire:click="$set('perPage', '{{ $size }}')" aria-pressed="{{ (string) $perPage === $size ? 'true' : 'false' }}" data-test="per-page-{{ $size }}" @class([
                'min-h-8 min-w-9 rounded-full px-2 text-xs font-semibold tabular-nums',
                'bg-secondary-dark text-white dark:bg-ink dark:text-surface-0' => (string) $perPage === $size,
                'text-secondary-dark hover:bg-secondary-soft dark:text-ink dark:hover:bg-surface-2' => (string) $perPage !== $size,
            ])>{{ $size }}</button>
        @endforeach
    </div>

    <flux:pagination :paginator="$paginator" :scroll-to="$scrollTo" class="text-secondary-medium! dark:text-ink-muted!" />
</div>
```

- [x] **Step 3: Append the register CSS to the end of `resources/css/app.css`**

```css
/*
 * Finance registers (x-funding.register): the tables of the Funding pages.
 * .register is a size container. Table min-width per size; while the
 * container is at least that wide nothing scrolls sideways and the heading
 * row sticks under the app bar (sticky cannot work inside an overflow box);
 * narrower, the table scrolls inside .register-scroll with its first column
 * pinned; under 48rem each row stacks as labelled pairs.
 */
@layer components {
    .register {
        container-type: inline-size;
        --register-ground: var(--color-white);
        --register-ink: var(--color-secondary-dark);
        --register-muted: var(--color-secondary-medium);
        --register-sticky-top: 4rem;
        color: var(--register-ink);
    }

    .dark .register {
        --register-ground: var(--color-surface-0);
        --register-ink: var(--color-ink);
        --register-muted: var(--color-ink-muted);
    }

    .register-scroll {
        overflow-x: auto;
        border-block: 1px solid var(--color-board-line);
    }

    .register table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 0.875rem;
    }

    .register-sm table { min-width: 56rem; }
    .register-md table { min-width: 68rem; }
    .register-lg table { min-width: 80rem; }
    .register-xl table { min-width: 92rem; }
    .register-2xl table { min-width: 104rem; }

    .register th,
    .register td {
        padding: 0.625rem 0.75rem;
        text-align: start;
        vertical-align: top;
        border-bottom: 1px solid var(--color-board-line);
        background: var(--register-ground);
    }

    .register thead th {
        background: var(--color-board-head);
        color: var(--register-muted);
        font-size: 0.75rem;
        font-weight: 600;
        white-space: nowrap;
        vertical-align: bottom;
    }

    .register tbody tr:hover td { background: var(--color-board-head); }

    .register th:first-child,
    .register td:first-child {
        position: sticky;
        inset-inline-start: 0;
        z-index: 1;
        min-width: 14rem;
    }

    .register .num { text-align: end; white-space: nowrap; }
    .register .date { white-space: nowrap; font-variant-numeric: tabular-nums; }
    .register .fig { display: inline-block; direction: ltr; unicode-bidi: isolate; font-variant-numeric: tabular-nums; }
    .register .sub { display: block; margin-top: 0.125rem; font-size: 0.75rem; font-weight: 400; color: var(--register-muted); }
    .register .fig.block { display: block; }

    .register-sort {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        font: inherit;
        color: inherit;
        cursor: pointer;
    }

    .register th.num .register-sort { flex-direction: row-reverse; }
    .register th[aria-sort] { color: var(--register-ink); }

    .register tr.register-group td {
        background: var(--color-board-head);
        font-weight: 700;
        border-top: 1px solid var(--color-board-line);
    }

    .register tfoot td {
        background: var(--color-board-head);
        font-weight: 700;
        border-top: 2px solid var(--color-board-line);
    }

    @container (min-width: 56rem) { .register-sm > .register-scroll { overflow: visible; } .register-sm thead th { position: sticky; top: var(--register-sticky-top); z-index: 2; } }
    @container (min-width: 68rem) { .register-md > .register-scroll { overflow: visible; } .register-md thead th { position: sticky; top: var(--register-sticky-top); z-index: 2; } }
    @container (min-width: 80rem) { .register-lg > .register-scroll { overflow: visible; } .register-lg thead th { position: sticky; top: var(--register-sticky-top); z-index: 2; } }
    @container (min-width: 92rem) { .register-xl > .register-scroll { overflow: visible; } .register-xl thead th { position: sticky; top: var(--register-sticky-top); z-index: 2; } }
    @container (min-width: 104rem) { .register-2xl > .register-scroll { overflow: visible; } .register-2xl thead th { position: sticky; top: var(--register-sticky-top); z-index: 2; } }

    @container (max-width: 47.99rem) {
        .register .register-scroll { overflow: visible; border: 0; }
        .register table { min-width: 0; }
        .register table,
        .register tbody,
        .register tfoot,
        .register tr,
        .register td { display: block; width: 100%; }
        .register thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
        .register tr { padding: 0.75rem 0; border-bottom: 1px solid var(--color-board-line); }
        .register td { position: static; display: flex; justify-content: space-between; gap: 1rem; padding: 0.25rem 0; border: 0; text-align: end; background: transparent; }
        .register td:empty { display: none; }
        .register td[data-label]::before { content: attr(data-label); color: var(--register-muted); font-size: 0.75rem; font-weight: 600; text-align: start; }
        .register td[data-wide] { display: block; text-align: start; font-size: 1rem; }
        .register td[data-wide]::before { content: none; }
    }
}
```

`@container` with no name queries the nearest container, which for every rule above is `.register`. The phone block comes last so its `min-width: 0` beats the size rules.

- [x] **Step 4: Run** `.\php.cmd artisan test --compact tests/Feature/FinanceKitComponentsTest.php` — PASS. Then ask the user whether `composer run dev` is running (the CSS must compile; see `.ai/rules` frontend note). Run `npm run build` only with their approval.

---

### Task 7: Checkpoint

- [x] Run `.\php.cmd vendor/bin/pint --dirty --format agent`.
- [x] Run: `.\php.cmd artisan test --compact tests/Feature/Models/TransfersTrackingTest.php tests/Feature/AgreementMoneyTest.php tests/Feature/TranchesTest.php tests/Feature/CsvExportTest.php tests/Feature/FinanceRegisterTraitTest.php tests/Feature/FinanceKitComponentsTest.php tests/Feature/LocalizationTest.php tests/Feature/AccessControl/AuthorizationCoverageTest.php tests/Feature/TransferTrackingTest.php tests/Feature/FundingAgreementTest.php` — PASS (the last two prove no existing page changed).
- [x] Do not commit. Phase 1 starts from here.
