# Funding tables — Phase 4: Donors register

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Donors page opens on a table with one row per donor per currency: contact and follow-up on the start side, then committed, received, % received, outstanding, overdue, next transfer and last receipt in their own columns; filterable by year, relationship, type, country, currency and flags; sortable, totalled per currency and exportable. Today's pulse strip, wallet, transfers-ahead rail and donor list stay behind the `Table | Overview` switch.

**Architecture:** The page keeps its class (`donors`, `pulse`, `wallet`, `typeRail`, `upcomingTransfers`, `donorMoney`, the donor form trait) and gains the `FiltersFinanceRegister` trait, three filters and a `rows` paginator that left-joins `AgreementMoney::perOwner('donor_id', …)` (Phase 0), which yields one line per donor and currency. The join, and every column read from it, is skipped for a viewer who may not see agreements; the tranche-derived figures are zeroed at the source for a viewer who may not see transfers.

**Tech Stack:** Laravel 13, Livewire 4 single-file page components, Flux (lite), Tailwind 4, Pest 5, MySQL.

**Depends on:** Phases 0–2 done.

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
- Currencies are never added together.
- **Hide money, keep names** (`.ai/rules/pages.md`): budgets are queried and rendered only for holders of `funding-agreements.view`; tranche figures only for holders of `transfers.view`. Skipping the query is required — hiding the cell is not enough. Donor names stay for everyone who may open the page.
- A donor's own name is plain text in its row; its page opens from the eye button placed first in the actions.
- No existing test is deleted. A test that asserts on the old visual is pointed at the Overview view with `->set('view', 'overview')`.
- The donor form trait (`ManagesDonorForm`) owns a `country` property, so this page's country filter is `countryFilter` with the URL key `country`.
- Line numbers quoted below are for commit `99c95eb`.

## File Structure

| File | Responsibility |
| --- | --- |
| Create `resources/views/pages/donors/_overview.blade.php` | Today's pulse, wallet, rail and donor list, moved unchanged. |
| Modify `resources/views/pages/donors/⚡index.blade.php` | New filters, table data, template. |
| Create `tests/Feature/DonorRegisterTest.php` | The table. |
| Modify `tests/Feature/DonorDirectoryTest.php` | Point four visual assertions at the Overview view. |
| Modify `tests/Feature/AccessControl/FundraisingPagesMoneyTest.php` | Point one visual assertion at the Overview view. |

---

### Task 1: Move today's overview and donor list into a partial

A pure move: the page looks and behaves exactly as before when this task ends.

**Files:**
- Create: `resources/views/pages/donors/_overview.blade.php`
- Modify: `resources/views/pages/donors/⚡index.blade.php` (lines 565–942)

- [x] **Step 1: Confirm the block boundaries**

```bash
cd /c/xampp/htdocs/hc-tracking && sed -n '562p;564p;565p;942p;943p' "resources/views/pages/donors/⚡index.blade.php"
```

Expected output, exactly:

```
    @if (! $hasAnyDonor)
    @else
        {{-- Overview: one framed band — the money by funder beside what is due next. --}}
        </section>
    @endif
```

- [x] **Step 2: Build the partial**

The partial defines the two values that cost a query and that only it needs, so the table view never pays for them.

```bash
cd /c/xampp/htdocs/hc-tracking && {
  printf '%s\n' '{{--' \
    '    The Overview view of the donors page: the pulse strip, who funds us, the' \
    '    transfers ahead, the donor mix and the donor list. Included by' \
    '    ⚡index.blade.php, which defines $amount, $shortAmount, $initials,' \
    '    $canAgreements, $canTransfers, $mutedText, $inkText and $hairline. The' \
    '    Alpine state it reads (focus, lit) lives on the page'"'"'s root <section>.' \
    '--}}' \
    '@php' \
    '    $pulse = $this->pulse;' \
    '    $pageDonorIds = $this->donors->getCollection()->pluck('"'"'id'"'"')->all();' \
    '@endphp' ''
  sed -n '565,942p' "resources/views/pages/donors/⚡index.blade.php"
} > resources/views/pages/donors/_overview.blade.php
```

- [x] **Step 3: Qualify the three class references the partial can no longer import**

In `resources/views/pages/donors/_overview.blade.php`:

1. Replace both occurrences of `tap(new Donor, ` with `tap(new \App\Models\Donor, `.
2. Replace the one occurrence of `tap(new DonorProposal, ` with `tap(new \App\Models\DonorProposal, `.

Verify:

```bash
cd /c/xampp/htdocs/hc-tracking && grep -n -E "new (Donor|DonorProposal)\b|(^|[^\\\\A-Za-z])(ArStatus|DonorType|TransfersTracking|Collection|Number)::" resources/views/pages/donors/_overview.blade.php
```

Expected: no output. (`App\Models\DonorProposal::percentLabel` is already written with its namespace.)

- [x] **Step 4: Replace the block in the page with the include**

In `resources/views/pages/donors/⚡index.blade.php` delete lines 565–942 and put this single line in their place:

```blade
        @include('pages.donors._overview')
```

- [x] **Step 5: Prove nothing changed**

Run: `.\php.cmd artisan test --compact tests/Feature/DonorDirectoryTest.php tests/Feature/AccessControl/FundraisingPagesMoneyTest.php tests/Feature/FundingWorkflowTest.php tests/Feature/SearchWildcardTest.php`
Expected: PASS, the same counts as before.

---

### Task 2: The register — class, template and tests

**Files:**
- Modify: `resources/views/pages/donors/⚡index.blade.php`
- Create: `tests/Feature/DonorRegisterTest.php`
- Modify: `tests/Feature/DonorDirectoryTest.php`
- Modify: `tests/Feature/AccessControl/FundraisingPagesMoneyTest.php`

**Interfaces:**
- Consumes (Phase 0): trait `FiltersFinanceRegister`; `AgreementMoney::perOwner(string $owner, ?int $year = null, bool $yearOnly = false, bool $withTransfers = true): Illuminate\Database\Query\Builder` (one row per `owner_id` and `currency` with `o_agreements`, `o_committed`, `o_tranches`, `o_received`, `o_outstanding`, `o_overdue`, `o_overdue_count`, `o_next_due`, `o_last_received`, `o_due_in_year`, `o_received_in_year`); `AgreementMoney::ownerSelect(): string` (the matching `money_currency`, `money_agreements`, `money_committed`, `money_tranches`, `money_received`, `money_outstanding`, `money_overdue`, `money_overdue_count`, `money_next_due`, `money_last_received`, `money_due_in_year`, `money_received_in_year`); `AgreementMoney::ownerTotalsSelect(string $ownerKey): string`; `AgreementMoney::yearBounds()`; `TransfersTracking` scope `inState()`; `CsvExport`; the register components; `downloadedCsv()`.
- Produces: public properties `search`, `type`, `relationship`, `sort`, `countryFilter` (URL key `country`), `currency`, `flag`; computed `rows`, `totals`, `donorCount`, `nextTransfers`, `countryOptions`, `hasAnyDonor`; actions `toggleFlag`, `exportCsv`; file name `donors-{Y-m-d}.csv`.

- [x] **Step 1: Write the failing tests**

Create `tests/Feature/DonorRegisterTest.php`:

```php
<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Enums\DonorType;
use App\Models\Donor;
use App\Models\DonorProposal;
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
 * The donors page as the finance manager opens it.
 *
 * @param  array<string, string>  $query
 */
function donorRegister(array $query = []): Testable
{
    return Livewire::actingAs(test()->user)->withQueryParams($query)->test('pages::donors.index');
}

/**
 * A tranche in its agreement's own currency.
 *
 * @param  array<string, mixed>  $attributes
 */
function donorTranche(DonorProposal $agreement, array $attributes): TransfersTracking
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
 * UNICEF: 1,000 USD with 400 received (390 net), 200 overdue and 100 due on
 * 25 June; and 500 EUR with nothing scheduled.
 */
function registerFundingDonor(): Donor
{
    $donor = Donor::factory()->ofType(DonorType::Bilateral)->create([
        'name' => 'UNICEF',
        'country' => 'Switzerland',
        'contact_person' => 'Lina Haddad',
        'email' => 'grants@unicef.test',
        'phone' => '+961 (1) 234-567',
    ]);

    $dollars = DonorProposal::factory()->create(['donor_id' => $donor->id, 'budget' => '1000.00', 'currency' => Currency::Usd]);
    DonorProposal::factory()->create(['donor_id' => $donor->id, 'budget' => '500.00', 'currency' => Currency::Eur]);

    donorTranche($dollars, ['payment_amount' => '400.00', 'net_amount_received' => '390.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2026-05-15', 'transfer_date' => '2026-05-15', 'receive_date' => '2026-05-20']);
    donorTranche($dollars, ['payment_amount' => '200.00', 'scheduled_date' => '2026-06-05']);
    donorTranche($dollars, ['payment_amount' => '100.00', 'scheduled_date' => '2026-06-25']);

    return $donor;
}

test('the page opens on the table with one row per donor and currency', function () {
    $donor = registerFundingDonor();
    $prospect = Donor::factory()->create(['name' => 'Zeta Trust']);

    $page = donorRegister()
        ->assertSet('view', 'table')
        ->assertSeeHtml('data-register')
        ->assertDontSeeHtml('data-wallet')
        ->assertSee('UNICEF')
        ->assertSee('Zeta Trust')
        ->assertSee('1,000.00')
        ->assertSee('390.00')
        ->assertSee('39%')
        ->assertSee('600.00');

    $rows = $page->instance()->rows->getCollection();

    expect($rows->map(fn (Donor $row): string => $row->name.'|'.($row->money_currency ?? '-'))->all())
        ->toBe(['UNICEF|EUR', 'UNICEF|USD', 'Zeta Trust|-'])
        ->and($page->instance()->donorCount)->toBe(2);

    $dollars = $rows->firstWhere('money_currency', 'USD');

    expect((int) $dollars->money_agreements)->toBe(1)
        ->and((float) $dollars->money_committed)->toBe(1000.0)
        ->and((float) $dollars->money_received)->toBe(390.0)
        ->and((float) $dollars->money_outstanding)->toBe(600.0)
        ->and((float) $dollars->money_overdue)->toBe(200.0)
        ->and((int) $dollars->money_overdue_count)->toBe(1)
        ->and($dollars->money_next_due)->toBe('2026-06-05')
        ->and($dollars->money_last_received)->toBe('2026-05-20');

    expect((float) $rows->firstWhere('money_currency', 'EUR')->money_committed)->toBe(500.0)
        ->and($prospect->id)->toBe($rows->last()->id);
});

test('the overview view shows the wallet and the donor list instead of the table', function () {
    registerFundingDonor();

    donorRegister()
        ->set('view', 'overview')
        ->assertSeeHtml('data-wallet')
        ->assertDontSeeHtml('data-register');
});

test('a row shows the contact, the follow-up and the next transfer with its amount', function () {
    $donor = registerFundingDonor();
    $donor->update(['next_step' => 'Send the interim report', 'next_step_due_on' => '2026-06-01']);

    donorRegister()
        ->assertSee('Lina Haddad')
        ->assertSeeHtml('href="mailto:grants@unicef.test"')
        ->assertSeeHtml('href="tel:+9611234567"')
        ->assertSee('Send the interim report')
        ->assertSeeHtml('data-follow-up-overdue')
        ->assertSeeHtml('data-next-transfer')
        ->assertSee('200.00 USD')
        ->assertSeeHtml('aria-label="View UNICEF"')
        ->assertSeeHtml('href="'.route('donors.show', $donor).'"');
});

test('country, currency, relationship, type and the flags narrow the rows', function () {
    $funding = registerFundingDonor();
    $prospect = Donor::factory()->ofType(DonorType::Foundation)->create(['name' => 'Zeta Trust', 'country' => 'Norway', 'next_step' => 'Call back', 'next_step_due_on' => '2026-06-10']);

    $donorIds = fn (Testable $page): array => $page->instance()->rows->pluck('id')->unique()->sort()->values()->all();

    expect($donorIds(donorRegister()->set('countryFilter', 'Norway')))->toBe([$prospect->id])
        ->and($donorIds(donorRegister(['country' => 'Switzerland'])))->toBe([$funding->id])
        ->and($donorIds(donorRegister()->set('relationship', 'prospect')))->toBe([$prospect->id])
        ->and($donorIds(donorRegister()->call('toggleType', 'bilateral')))->toBe([$funding->id])
        ->and($donorIds(donorRegister()->call('toggleFlag', 'overdue')))->toBe([$funding->id])
        ->and($donorIds(donorRegister()->call('toggleFlag', 'follow_up')))->toBe([$prospect->id]);

    // A currency keeps only that currency's line of each donor.
    $euros = donorRegister()->set('currency', 'EUR')->instance()->rows->getCollection();

    expect($euros)->toHaveCount(1)
        ->and($euros->first()->money_currency)->toBe('EUR');
});

test('choosing a year adds that year beside the lifetime figures and hides lines with nothing in it', function () {
    registerFundingDonor();
    $earlier = Donor::factory()->create(['name' => 'Old Grant']);
    donorTranche(
        DonorProposal::factory()->create(['donor_id' => $earlier->id, 'budget' => '700.00', 'currency' => Currency::Usd]),
        ['payment_amount' => '700.00', 'net_amount_received' => '700.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2024-02-01', 'receive_date' => '2024-02-05'],
    );

    $page = donorRegister()->set('year', '2026');
    $row = $page->instance()->rows->getCollection()->sole();

    expect($row->name)->toBe('UNICEF')
        ->and($row->money_currency)->toBe('USD')
        ->and((float) $row->money_due_in_year)->toBe(700.0)
        ->and((float) $row->money_received_in_year)->toBe(390.0)
        ->and((float) $row->money_committed)->toBe(1000.0);

    $page->assertSee('Due in 2026')->assertSee('Received in 2026');

    $only = donorRegister()->set('year', '2024')->set('figures', 'year')->instance()->rows->getCollection()->sole();

    expect($only->name)->toBe('Old Grant')
        ->and((float) $only->money_received)->toBe(700.0);
});

test('totals are kept per currency and add up to the rows shown', function () {
    registerFundingDonor();
    DonorProposal::factory()->create(['donor_id' => Donor::factory()->create()->id, 'budget' => '2000.00', 'currency' => Currency::Usd]);

    $page = donorRegister();
    $totals = $page->instance()->totals->keyBy('currency');

    expect($totals->keys()->all())->toBe(['EUR', 'USD'])
        ->and($totals['USD'])->toMatchArray(['donors' => 2, 'agreements' => 2, 'committed' => 3000.0, 'received' => 390.0, 'outstanding' => 2600.0, 'overdue' => 200.0])
        ->and($totals['EUR'])->toMatchArray(['donors' => 1, 'committed' => 500.0]);

    $page->assertSeeHtml('data-register-total="USD"')
        ->assertSeeHtml('data-register-total="EUR"')
        ->assertDontSee('3,500.00');
});

test('the table opens by donor name and a heading sorts it', function () {
    registerFundingDonor();
    $larger = Donor::factory()->create(['name' => 'Alpha Fund']);
    DonorProposal::factory()->create(['donor_id' => $larger->id, 'budget' => '9000.00', 'currency' => Currency::Usd]);

    $lines = fn (Testable $page): array => $page->instance()->rows->getCollection()->map(fn (Donor $row): string => $row->name.'|'.$row->money_currency)->all();

    $page = donorRegister();
    expect($lines($page))->toBe(['Alpha Fund|USD', 'UNICEF|EUR', 'UNICEF|USD']);

    $page->call('sortRegister', 'committed');
    expect($lines($page))->toBe(['UNICEF|EUR', 'Alpha Fund|USD', 'UNICEF|USD']);
});

test('someone who may not see agreements gets the donors with no money columns and no money query', function () {
    registerFundingDonor();

    $page = Livewire::actingAs(userWithRole('Public Relations Manager'))->test('pages::donors.index');

    $page->assertOk()
        ->assertSee('UNICEF')
        ->assertSee('Lina Haddad')
        ->assertDontSee('1,000.00')
        ->assertDontSee('390.00')
        ->assertDontSeeHtml('data-cell="committed"')
        ->assertDontSeeHtml('data-register-totals');

    expect($page->instance()->rows->getCollection())->toHaveCount(1)
        ->and($page->instance()->totals)->toBeEmpty()
        ->and($page->instance()->nextTransfers)->toBeEmpty()
        ->and(array_key_exists('money_committed', $page->instance()->rows->first()->getAttributes()))->toBeFalse();
});

test('someone who may see agreements but not transfers gets budgets without tranche figures', function () {
    registerFundingDonor();
    $viewer = userWithRole('Public Relations Manager')->givePermissionTo('funding-agreements.view');

    $page = Livewire::actingAs($viewer)->test('pages::donors.index');

    $page->assertSee('1,000.00')
        ->assertDontSee('390.00')
        ->assertDontSeeHtml('data-cell="received"')
        ->assertDontSeeHtml('data-next-transfer');

    $dollars = $page->instance()->rows->getCollection()->firstWhere('money_currency', 'USD');

    expect((float) $dollars->money_committed)->toBe(1000.0)
        ->and((float) $dollars->money_received)->toBe(0.0)
        ->and($page->instance()->nextTransfers)->toBeEmpty();
});

test('values typed into the address bar fall back to the defaults', function () {
    registerFundingDonor();

    donorRegister(['view' => 'list', 'type' => 'royalty', 'relationship' => 'friends', 'sort' => 'luck', 'country' => 'Atlantis', 'currency' => 'GBP', 'flag' => 'rich', 'sortBy' => 'email', 'perPage' => '1'])
        ->assertSet('view', 'table')
        ->assertSet('type', '')
        ->assertSet('relationship', '')
        ->assertSet('sort', 'name')
        ->assertSet('countryFilter', '')
        ->assertSet('currency', '')
        ->assertSet('flag', '')
        ->assertSet('sortBy', '')
        ->assertSet('perPage', '25')
        ->assertOk();
});

test('the export holds every filtered line and only the columns the viewer may see', function () {
    registerFundingDonor();
    Donor::factory()->count(30)->create();

    $page = donorRegister()->call('exportCsv')->assertFileDownloaded('donors-2026-06-15.csv');
    $csv = downloadedCsv($page);
    $unicef = collect($csv)->first(fn (array $row): bool => $row[0] === 'UNICEF' && $row[10] === 'USD');

    expect($csv)->toHaveCount(33)
        ->and($csv[0][0])->toBe('Donor')
        ->and($csv[0][11])->toBe('Committed')
        ->and($unicef[1])->toBe('Bilateral')
        ->and($unicef[2])->toBe('Switzerland')
        ->and($unicef[3])->toBe('Lina Haddad')
        ->and($unicef[4])->toBe('grants@unicef.test')
        ->and($unicef[9])->toBe('1')
        ->and($unicef[11])->toBe('1000.00')
        ->and($unicef[12])->toBe('390.00')
        ->and($unicef[13])->toBe('39')
        ->and($unicef[14])->toBe('600.00')
        ->and($unicef[15])->toBe('200.00')
        ->and($unicef[17])->toBe('2026-06-05');

    $publicRelations = Livewire::actingAs(userWithRole('Public Relations Manager'))->test('pages::donors.index')->call('exportCsv');
    $plain = downloadedCsv($publicRelations);

    expect($plain[0])->toHaveCount(9)
        ->and(collect($plain)->flatten()->contains('1000.00'))->toBeFalse();
});

test('exporting needs the donors view permission', function () {
    registerFundingDonor();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::donors.index')
        ->call('exportCsv')
        ->assertForbidden();
});
```

- [x] **Step 2: Run the tests to verify they fail**

Run: `.\php.cmd artisan test --compact tests/Feature/DonorRegisterTest.php`
Expected: FAIL — `Property [$view] not found on component`.

- [x] **Step 3: Extend the component class**

All edits are in the PHP block of `resources/views/pages/donors/⚡index.blade.php`.

**3a. Imports.** Add these to the `use` list at the top (keep the list alphabetical):

```php
use App\Enums\Currency;
use App\Livewire\Concerns\FiltersFinanceRegister;
use App\Support\AgreementMoney;
use App\Support\CsvExport;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\StreamedResponse;
```

**3b. Traits, constants and filter properties.** Replace the lines from `use ManagesDonorForm;` through the `$sort` property (lines 26–45) with:

```php
    use FiltersFinanceRegister;
    use ManagesDonorForm;
    use WithPagination;

    /** The type filter value that selects donors carrying no classification. */
    public const UNCLASSIFIED = 'unclassified';

    /** How many donors the share-of-wallet strip names before folding the rest together. */
    public const NAMED_SEGMENTS = 7;

    /** How the Overview list may be ordered. */
    public const SORTS = ['name', 'agreements', 'next_due', 'follow_up'];

    /** @var list<string> */
    public const FLAGS = ['overdue', 'follow_up'];

    /**
     * Every property a filter control writes. The donor form's own fields
     * (`country`, `name`, …) are not filters, which is why the country filter
     * carries a `Filter` suffix.
     *
     * @var list<string>
     */
    private const FILTERS = ['search', 'type', 'relationship', 'sort', 'countryFilter', 'currency', 'flag'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $relationship = '';

    /** The Overview list's order. The table sorts by its column headings instead. */
    #[Url(except: 'name')]
    public string $sort = 'name';

    #[Url(as: 'country', except: '')]
    public string $countryFilter = '';

    #[Url(except: '')]
    public string $currency = '';

    #[Url(except: '')]
    public string $flag = '';
```

**3c. Filter handling.** Replace the five methods `updatedSearch()`, `updatedRelationship()`, `updatedType()`, `updatedSort()` and `clearFilters()` (lines 51–78) with:

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
        if (! in_array($this->type, [...DonorType::values(), self::UNCLASSIFIED], true)) {
            $this->type = '';
        }

        if (! in_array($this->relationship, ['funding', 'prospect'], true)) {
            $this->relationship = '';
        }

        if (! in_array($this->sort, self::SORTS, true)) {
            $this->sort = 'name';
        }

        if (! in_array($this->countryFilter, $this->countryOptions, true)) {
            $this->countryFilter = '';
        }

        if (Currency::tryFrom($this->currency) === null) {
            $this->currency = '';
        }

        if (! in_array($this->flag, self::FLAGS, true)) {
            $this->flag = '';
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'type', 'relationship', 'sort', 'countryFilter', 'currency', 'flag']);
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
     * The countries donors are in, for the country picker.
     *
     * @return list<string>
     */
    #[Computed]
    public function countryOptions(): array
    {
        return Donor::query()->whereNotNull('country')->where('country', '!=', '')->distinct()->orderBy('country')->pluck('country')->all();
    }
```

**3d. The filtered query.** Replace the body of `filteredQuery()` (keep its doc block) with:

```php
    protected function filteredQuery(bool $withType = true): Builder
    {
        $search = trim($this->search);

        return Donor::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('donors.name', 'like', Like::contains($search))
                        ->orWhere('donors.country', 'like', Like::contains($search))
                        ->orWhere('donors.contact_person', 'like', Like::contains($search))
                        ->orWhere('donors.email', 'like', Like::contains($search));
                });
            })
            ->when($this->relationship === 'funding', fn (Builder $query) => $query->has('donorProposals'))
            ->when($this->relationship === 'prospect', fn (Builder $query) => $query->doesntHave('donorProposals'))
            ->when($withType && $this->type === self::UNCLASSIFIED, fn (Builder $query) => $query->whereNull('donors.type'))
            ->when($withType && in_array($this->type, DonorType::values(), true), fn (Builder $query) => $query->where('donors.type', $this->type))
            ->when($this->countryFilter !== '', fn (Builder $query) => $query->where('donors.country', $this->countryFilter))
            ->when($this->flag === 'follow_up', fn (Builder $query) => $query->whereDate('donors.next_step_due_on', '<=', today()))
            // A money filter only applies to a viewer who may see that money: otherwise it would leak it one row at a time.
            ->when($this->currency !== '' && $this->canViewAgreements(), fn (Builder $query) => $query->whereHas('donorProposals', fn (Builder $query) => $query->where('currency', $this->currency)))
            ->when($this->flag === 'overdue' && $this->canViewTransfers(), fn (Builder $query) => $query->whereHas('donorProposals.transfers', fn (Builder $query) => $query->inState('late')))
            ->when($this->selectedYear() !== null && $this->canViewTransfers(), fn (Builder $query) => $this->applyYear($query));
    }

    /**
     * Keep only donors with money in the chosen year: a tranche due in it or,
     * while the lifetime figures are shown, one that landed in it.
     *
     * @param  Builder<Donor>  $query
     * @return Builder<Donor>
     */
    protected function applyYear(Builder $query): Builder
    {
        $bounds = AgreementMoney::yearBounds($this->selectedYear());
        $yearOnly = $this->yearOnly();

        return $query->whereHas('donorProposals.transfers', fn (Builder $query) => $query->where(fn (Builder $query) => $query
            ->whereBetween('scheduled_date', $bounds)
            ->when(! $yearOnly, fn (Builder $query) => $query->orWhere(fn (Builder $query) => $query
                ->where('ar_status', ArStatus::Received)
                ->whereBetween('receive_date', $bounds)))));
    }
```

**3e. The table's data.** Directly before the existing `canDelete()` method add:

```php
    /**
     * The filtered donors joined to their money, one line per donor and
     * currency; a donor holding no agreement keeps a single line with none.
     *
     * A viewer who may not see agreements gets the donors alone: the join is
     * never made, so no budget is ever queried for them. A viewer who may see
     * agreements but not transfers gets the join with every tranche figure
     * zeroed at its source.
     *
     * @return Builder<Donor>
     */
    protected function joinedQuery(): Builder
    {
        $query = $this->filteredQuery();

        if (! $this->canViewAgreements()) {
            return $query;
        }

        $canTransfers = $this->canViewTransfers();
        $year = $canTransfers ? $this->selectedYear() : null;
        $yearOnly = $canTransfers && $this->yearOnly();

        return $query
            ->leftJoinSub(AgreementMoney::perOwner('donor_id', $year, $yearOnly, $canTransfers), 'money', 'money.owner_id', '=', 'donors.id')
            ->when($this->currency !== '', fn (Builder $query) => $query->where('money.currency', $this->currency))
            // With a year chosen, a line with nothing in that year is not shown.
            ->when($year !== null && ! $yearOnly, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('money.o_due_in_year', '>', 0)
                ->orWhere('money.o_received_in_year', '>', 0)))
            ->when($yearOnly, fn (Builder $query) => $query->where('money.o_tranches', '>', 0));
    }

    /**
     * What each column heading sorts by. Money headings exist only for a
     * viewer who may see that money, and sort inside their currency.
     *
     * @return array<string, array{dir: 'asc'|'desc', order: list<string>}>
     */
    protected function registerSorts(): array
    {
        $canAgreements = $this->canViewAgreements();
        $byName = $canAgreements ? ['donors.name asc', 'money.currency asc', 'donors.id asc'] : ['donors.name asc', 'donors.id asc'];
        $date = fn (string $expression): array => ['dir' => 'asc', 'order' => ["{$expression} is null", "{$expression} {dir}", ...$byName]];

        $sorts = [
            'donor' => ['dir' => 'asc', 'order' => $canAgreements ? ['donors.name {dir}', 'money.currency asc', 'donors.id asc'] : ['donors.name {dir}', 'donors.id asc']],
            'country' => ['dir' => 'asc', 'order' => ['donors.country is null', 'donors.country {dir}', ...$byName]],
            'follow_up' => $date('donors.next_step_due_on'),
        ];

        if (! $canAgreements) {
            return $sorts;
        }

        $money = fn (string $expression): array => ['dir' => 'desc', 'order' => ['money.currency is null', 'money.currency asc', "{$expression} {dir}", ...$byName]];
        $sum = fn (string $column): string => "coalesce(money.{$column}, 0)";

        $sorts['agreements'] = ['dir' => 'desc', 'order' => ["{$sum('o_agreements')} {dir}", ...$byName]];
        $sorts['committed'] = $money($sum('o_committed'));

        if (! $this->canViewTransfers()) {
            return $sorts;
        }

        return [
            ...$sorts,
            'received' => $money($sum('o_received')),
            'percent' => ['dir' => 'desc', 'order' => ["(case when {$sum('o_committed')} > 0 then {$sum('o_received')} / money.o_committed else 0 end) {dir}", ...$byName]],
            'due_in_year' => $money($sum('o_due_in_year')),
            'received_in_year' => $money($sum('o_received_in_year')),
            'outstanding' => $money($sum('o_outstanding')),
            'overdue' => $money($sum('o_overdue')),
            'next_transfer' => $date('money.o_next_due'),
            'last_received' => ['dir' => 'desc', 'order' => ['money.o_last_received is null', 'money.o_last_received {dir}', ...$byName]],
        ];
    }

    protected function registerDefaultSort(): string
    {
        return 'donor';
    }

    /**
     * The table's visible page.
     *
     * @return LengthAwarePaginator<int, Donor>
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $query = $this->joinedQuery()->select('donors.*')->withCount('donorProposals');

        if ($this->canViewAgreements()) {
            $query->selectRaw(AgreementMoney::ownerSelect());
        }

        return $this->applyRegisterSort($query)->paginate($this->registerPerPage());
    }

    /**
     * How many donors the table holds: the header's count, which a donor with
     * two currencies must not inflate.
     */
    #[Computed]
    public function donorCount(): int
    {
        return $this->joinedQuery()->distinct()->count('donors.id');
    }

    /**
     * The table's totals, one line per currency. Empty for a viewer who may
     * not see agreements.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function totals(): Collection
    {
        if (! $this->canViewAgreements()) {
            return new Collection;
        }

        return $this->joinedQuery()
            ->toBase()
            ->whereNotNull('money.currency')
            ->selectRaw(AgreementMoney::ownerTotalsSelect('donors.id'))
            ->groupBy('money.currency')
            ->orderBy('money.currency')
            ->get()
            ->map(fn (object $row): array => [
                'currency' => (string) $row->currency,
                'donors' => (int) $row->owners,
                'agreements' => (int) $row->agreements,
                'committed' => (float) $row->committed,
                'received' => (float) $row->received,
                'outstanding' => (float) $row->outstanding,
                'overdue' => (float) $row->overdue,
                'due_in_year' => (float) $row->due_in_year,
                'received_in_year' => (float) $row->received_in_year,
            ]);
    }

    /**
     * The earliest unsent tranche of each donor and agreement currency on the
     * visible page, keyed "donorId|currency": the date the table prints as the
     * next transfer, with the amount due on it. Money already sent is in
     * transit, so it is never a next transfer.
     *
     * @return Collection<string, object>
     */
    #[Computed]
    public function nextTransfers(): Collection
    {
        if (! $this->canViewAgreements() || ! $this->canViewTransfers()) {
            return new Collection;
        }

        $donorIds = $this->rows->getCollection()->pluck('id')->unique()->values();

        if ($donorIds->isEmpty()) {
            return new Collection;
        }

        return TransfersTracking::query()
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->whereIn('donor_proposal.donor_id', $donorIds)
            ->where('transfers_tracking.ar_status', ArStatus::Pending)
            ->whereNull('transfers_tracking.transfer_date')
            ->whereNotNull('transfers_tracking.scheduled_date')
            ->orderBy('transfers_tracking.scheduled_date')
            ->orderBy('transfers_tracking.id')
            ->toBase()
            ->get([
                'donor_proposal.donor_id',
                'donor_proposal.currency as agreement_currency',
                'transfers_tracking.payment_amount',
                'transfers_tracking.amount_transfered_currency as sent_currency',
                'transfers_tracking.scheduled_date',
            ])
            ->unique(fn (object $row): string => $row->donor_id.'|'.$row->agreement_currency)
            ->keyBy(fn (object $row): string => $row->donor_id.'|'.$row->agreement_currency);
    }

    /**
     * Whether any donor exists at all, so an empty page can tell "nothing
     * recorded yet" from "nothing matches".
     */
    #[Computed]
    public function hasAnyDonor(): bool
    {
        return Donor::query()->exists();
    }

    /**
     * Every line the filters match, in the table's order, as a CSV file. The
     * file holds the columns the viewer sees on screen and no others.
     */
    public function exportCsv(): StreamedResponse
    {
        Gate::authorize('donors.view');

        $canAgreements = $this->canViewAgreements();
        $canTransfers = $canAgreements && $this->canViewTransfers();
        $year = $canTransfers ? $this->selectedYear() : null;

        $query = $this->joinedQuery()->select('donors.*');

        if ($canAgreements) {
            $query->selectRaw(AgreementMoney::ownerSelect());
        }

        $donors = $this->applyRegisterSort($query)->cursor();

        $headings = [
            __('donors.donor'),
            __('common.type'),
            __('donors.country'),
            __('donors.contact_person'),
            __('common.email'),
            __('donors.phone'),
            __('donors.next_step'),
            __('donors.next_step_due_on'),
            __('donors.last_contacted_on'),
            ...($canAgreements ? [__('finance_tables.agreements'), __('common.currency'), __('common.committed')] : []),
            ...($canTransfers ? [
                __('finance_tables.received'),
                __('finance_tables.percent_received'),
                __('finance_tables.outstanding'),
                __('finance_tables.overdue'),
                __('finance_tables.overdue_transfers'),
                __('donors.next_transfer'),
                __('finance_tables.last_received'),
            ] : []),
            ...($year === null ? [] : [
                __('finance_tables.due_in_year', ['year' => $year]),
                __('finance_tables.received_in_year', ['year' => $year]),
            ]),
        ];

        $rows = (function () use ($donors, $canAgreements, $canTransfers, $year) {
            foreach ($donors as $donor) {
                yield [
                    $donor->name,
                    $donor->type?->label(),
                    $donor->country,
                    $donor->contact_person,
                    $donor->email,
                    $donor->phone,
                    $donor->next_step,
                    $donor->next_step_due_on,
                    $donor->last_contacted_on,
                    ...($canAgreements ? [
                        (int) $donor->money_agreements,
                        $donor->money_currency,
                        $donor->money_currency === null ? null : CsvExport::amount($donor->money_committed),
                    ] : []),
                    ...($canTransfers ? [
                        $donor->money_currency === null ? null : CsvExport::amount($donor->money_received),
                        $donor->money_currency === null ? null : DonorProposal::percentLabel((float) $donor->money_received, (float) $donor->money_committed),
                        $donor->money_currency === null ? null : CsvExport::amount($donor->money_outstanding),
                        $donor->money_currency === null ? null : CsvExport::amount($donor->money_overdue),
                        (int) $donor->money_overdue_count,
                        $donor->money_next_due,
                        $donor->money_last_received,
                    ] : []),
                    ...($year === null ? [] : [
                        CsvExport::amount($donor->money_due_in_year),
                        CsvExport::amount($donor->money_received_in_year),
                    ]),
                ];
            }
        })();

        return CsvExport::download('donors-'.CarbonImmutable::today()->toDateString().'.csv', $headings, $rows);
    }
```

Everything else in the class (`toggleType`, `canViewAgreements`, `canViewTransfers`, `donors`, `filteredDonorIds`, `pulse`, `wallet`, `typeRail`, `overviewRows`, `upcomingTransfers`, `donorMoney`, `canDelete`, `donorSaved`, the delete flow) stays exactly as it is.

- [x] **Step 4: Replace the template**

Replace everything after the closing `?>` with:

```blade

@php
    $showsOverview = $this->view === 'overview';
    $canAgreements = $this->canViewAgreements();
    $canTransfers = $canAgreements && $this->canViewTransfers();
    $filtersAreActive = collect([$this->search, $this->type, $this->relationship, $this->countryFilter, $this->currency, $this->flag, $this->year])
        ->contains(fn (string $value): bool => $value !== '');
    $total = $showsOverview ? $this->donors->total() : $this->donorCount;

    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $inkText = 'text-secondary-dark dark:text-ink';
    $hairline = 'border-secondary-soft dark:border-line';

    /* The three closures below format the Overview view (_overview.blade.php). The table prints exact amounts itself. */
    $amount = fn (float $value): string => Number::format($value, maxPrecision: 0);
    $shortAmount = fn (float $value): string => Number::abbreviate($value, maxPrecision: 1);
    $initials = fn (string $name): string => mb_strtoupper(collect(preg_split('/\s+/u', trim($name)) ?: [])->filter()->take(2)->map(fn (string $word): string => mb_substr($word, 0, 1))->implode('')) ?: '·';

    /* `$chosen` is read instead of the properties so a loop variable can never shadow one. */
    $chosen = [
        'year' => $this->year,
        'figures' => $this->figures,
        'relationship' => $this->relationship,
        'type' => $this->type,
        'currency' => $this->currency,
        'flag' => $this->flag,
        'sort' => $this->sort,
    ];
    $selectedYear = $canTransfers ? $this->selectedYear() : null;
    $yearOnly = $canTransfers && $this->yearOnly();
    $sortKey = $this->activeSort();
    $sortDirection = $this->activeSortDir();

    $setPill = fn (string $property, string $value, string $label): array => [
        'label' => $label,
        'click' => "\$set('{$property}', '".($chosen[$property] === $value ? '' : $value)."')",
        'active' => $chosen[$property] === $value,
    ];

    $yearPills = [
        ['label' => __('finance_tables.all_years'), 'click' => "\$set('year', '')", 'active' => $chosen['year'] === ''],
        ...array_map(fn (int $option): array => $setPill('year', (string) $option, (string) $option), $canTransfers ? $this->yearOptions : []),
    ];
    $figurePills = [
        ['label' => __('finance_tables.figures_lifetime'), 'click' => "\$set('figures', 'lifetime')", 'active' => $chosen['figures'] === 'lifetime'],
        ['label' => __('finance_tables.figures_year', ['year' => $chosen['year']]), 'click' => "\$set('figures', 'year')", 'active' => $chosen['figures'] === 'year'],
    ];
    $relationshipPills = [
        ['label' => __('finance_tables.all'), 'click' => "\$set('relationship', '')", 'active' => $chosen['relationship'] === ''],
        $setPill('relationship', 'funding', __('donors.funding_now')),
        $setPill('relationship', 'prospect', __('donors.prospects')),
    ];
    $typePills = [
        ['label' => __('finance_tables.all'), 'click' => "toggleType('')", 'active' => $chosen['type'] === ''],
        ...array_map(fn (array $stop): array => [
            'label' => $stop['label'],
            'click' => "toggleType('{$stop['value']}')",
            'active' => $chosen['type'] === $stop['value'],
            'count' => $stop['count'],
            'test' => 'type-'.$stop['value'],
        ], $this->typeRail),
    ];
    $currencyPills = [
        ['label' => __('finance_tables.all'), 'click' => "\$set('currency', '')", 'active' => $chosen['currency'] === ''],
        ...array_map(fn (Currency $case): array => $setPill('currency', $case->value, $case->value), Currency::cases()),
    ];
    $flagPills = array_values(array_filter([
        $canTransfers ? ['label' => __('finance_tables.flag_has_overdue'), 'click' => "toggleFlag('overdue')", 'active' => $chosen['flag'] === 'overdue', 'tone' => 'critical', 'test' => 'flag-overdue'] : null,
        ['label' => __('finance_tables.flag_follow_up_due'), 'click' => "toggleFlag('follow_up')", 'active' => $chosen['flag'] === 'follow_up', 'test' => 'flag-follow-up'],
    ]));
    $overviewOrderPills = array_values(array_filter([
        ['label' => __('donors.sort_name'), 'click' => "\$set('sort', 'name')", 'active' => $chosen['sort'] === 'name'],
        ['label' => __('donors.sort_agreements'), 'click' => "\$set('sort', 'agreements')", 'active' => $chosen['sort'] === 'agreements'],
        $canTransfers ? ['label' => __('donors.sort_next_due'), 'click' => "\$set('sort', 'next_due')", 'active' => $chosen['sort'] === 'next_due'] : null,
        ['label' => __('donors.sort_follow_up'), 'click' => "\$set('sort', 'follow_up')", 'active' => $chosen['sort'] === 'follow_up'],
    ]));
@endphp

<section
    class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink"
    aria-labelledby="donor-directory-heading"
    x-data="{ focus: null, lit(id) { return this.focus === null || this.focus === id } }"
>
    <x-ui.page-header
        heading-id="donor-directory-heading"
        :title="__('common.donors')"
        :count="$total"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('donors.search_donors')" :placeholder="__('donors.name_country_or_contact')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('donors.country')" wire:model.live="countryFilter" :value="$this->countryFilter">
                <flux:select.option value="">{{ __('finance_tables.all_countries') }}</flux:select.option>
                @foreach ($this->countryOptions as $countryOption)
                    <flux:select.option :value="$countryOption" :wire:key="'donor-country-'.md5($countryOption)">{{ $countryOption }}</flux:select.option>
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

                @if ($this->hasAnyDonor)
                    <x-ui.button icon="arrow-down-tray" class="min-h-11 touch-manipulation @sm/directory:min-h-10" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv" data-test="export-csv">
                        {{ __('finance_tables.export_csv') }}
                    </x-ui.button>
                @endif

                @can('donors.create')
                <x-ui.button tone="primary" icon="plus" class="min-h-11 touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-donor-button">
                    {{ __('donors.add_donor') }}
                </x-ui.button>
                @endcan
            </div>
        </x-slot:actions>

        @if ($this->hasAnyDonor)
            <x-slot:summary>
                <x-funding.filters :active="$filtersAreActive">
                    {{-- A year narrows by tranches, so it is offered only to someone who may see them. --}}
                    @if ($canTransfers)
                        <x-funding.pills :label="__('finance_tables.year')" :pills="$yearPills" />

                        @if ($selectedYear !== null)
                            <x-funding.pills :label="__('finance_tables.figures_label')" :pills="$figurePills" />
                        @endif
                    @endif

                    <x-funding.pills :label="__('donors.relationship')" :pills="$relationshipPills" />
                    <x-funding.pills :label="__('common.type')" :pills="$typePills" />

                    @if ($canAgreements)
                        <x-funding.pills :label="__('common.currency')" :pills="$currencyPills" />
                    @endif

                    <x-funding.pills :label="__('finance_tables.flags')" :pills="$flagPills" />

                    @if ($showsOverview)
                        <x-funding.pills :label="__('finance_tables.order')" :pills="$overviewOrderPills" />
                    @endif
                </x-funding.filters>
            </x-slot:summary>
        @endif
    </x-ui.page-header>

    @if (! $this->hasAnyDonor)
        <x-ui.empty-state icon="building-library" :heading="__('donors.no_donors_yet')" :body="__('donors.add_the_first_donor')" />
    @elseif ($showsOverview)
        @include('pages.donors._overview')
    @elseif ($this->rows->isEmpty())
        <x-ui.empty-state icon="building-library" :heading="__('donors.no_matching_donors')" :body="__('common.change_the_search_or_filters_and_try_again')" />
    @else
        @php
            $totals = $this->totals;
            $money = fn (string|float|int|null $value): string => number_format((float) $value, 2);
            $date = fn (?\DateTimeInterface $value): string => $value?->translatedFormat(__('formats.date')) ?? '—';
            $today = \Carbon\CarbonImmutable::today();
            $showsYearColumns = $selectedYear !== null && ! $yearOnly;

            $totalLines = $totals->map(fn (array $line): array => [
                'currency' => $line['currency'],
                'caption' => trans_choice('finance_tables.count_donors', $line['donors'], ['count' => $line['donors']]),
                'figures' => array_values(array_filter([
                    ['key' => 'committed', 'label' => __('common.committed'), 'value' => $line['committed']],
                    $canTransfers ? ['key' => 'received', 'label' => __('finance_tables.received'), 'value' => $line['received'], 'tone' => 'good', 'note' => DonorProposal::percentLabel($line['received'], $line['committed']).'%'] : null,
                    $showsYearColumns ? ['key' => 'due_in_year', 'label' => __('finance_tables.due_in_year', ['year' => $selectedYear]), 'value' => $line['due_in_year']] : null,
                    $showsYearColumns ? ['key' => 'received_in_year', 'label' => __('finance_tables.received_in_year', ['year' => $selectedYear]), 'value' => $line['received_in_year'], 'tone' => 'good'] : null,
                    $canTransfers && ! $yearOnly ? ['key' => 'outstanding', 'label' => __('finance_tables.outstanding'), 'value' => $line['outstanding']] : null,
                    $canTransfers ? ['key' => 'overdue', 'label' => __('finance_tables.overdue'), 'value' => $line['overdue'], 'tone' => $line['overdue'] > 0 ? 'critical' : null] : null,
                ])),
            ])->all();

            $previousDonorId = null;
        @endphp

        @if ($canAgreements)
            <x-funding.totals :lines="$totalLines" />
        @endif

        <x-funding.register :size="$canAgreements ? 'xl' : 'md'" :caption="__('finance_tables.donors_caption')">
            <x-slot:head>
                <x-funding.register.th sort="donor" :active="$sortKey" :dir="$sortDirection">{{ __('donors.donor') }}</x-funding.register.th>
                <x-funding.register.th sort="country" :active="$sortKey" :dir="$sortDirection">{{ __('donors.country') }}</x-funding.register.th>
                <x-funding.register.th>{{ __('donors.section_contact') }}</x-funding.register.th>
                <x-funding.register.th sort="follow_up" :active="$sortKey" :dir="$sortDirection">{{ __('donors.follow_up') }}</x-funding.register.th>
                @if ($canAgreements)
                    <x-funding.register.th sort="agreements" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.agreements') }}</x-funding.register.th>
                    <x-funding.register.th>{{ __('common.currency') }}</x-funding.register.th>
                    <x-funding.register.th sort="committed" :active="$sortKey" :dir="$sortDirection" numeric>{{ $yearOnly ? __('finance_tables.committed_whole') : __('common.committed') }}</x-funding.register.th>
                @endif
                @if ($canTransfers)
                    <x-funding.register.th sort="received" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.received') }}</x-funding.register.th>
                    <x-funding.register.th sort="percent" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.percent_received') }}</x-funding.register.th>
                    @if ($showsYearColumns)
                        <x-funding.register.th sort="due_in_year" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.due_in_year', ['year' => $selectedYear]) }}</x-funding.register.th>
                        <x-funding.register.th sort="received_in_year" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.received_in_year', ['year' => $selectedYear]) }}</x-funding.register.th>
                    @endif
                    @unless ($yearOnly)
                        <x-funding.register.th sort="outstanding" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.outstanding') }}</x-funding.register.th>
                    @endunless
                    <x-funding.register.th sort="overdue" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.overdue') }}</x-funding.register.th>
                    <x-funding.register.th sort="next_transfer" :active="$sortKey" :dir="$sortDirection">{{ __('donors.next_transfer') }}</x-funding.register.th>
                    <x-funding.register.th sort="last_received" :active="$sortKey" :dir="$sortDirection">{{ __('finance_tables.last_received') }}</x-funding.register.th>
                @endif
                <th scope="col"><span class="sr-only">{{ __('common.actions') }}</span></th>
            </x-slot:head>

            @foreach ($this->rows as $donor)
                @php
                    // A donor funding in two currencies has two lines; its identity is printed on the first only.
                    $isRepeat = $previousDonorId === $donor->id;
                    $previousDonorId = $donor->id;
                    $isProspect = $donor->donor_proposals_count === 0;
                    $lineCurrency = $canAgreements ? $donor->money_currency : null;
                    $hasMoney = $lineCurrency !== null;
                    $next = $canTransfers && $hasMoney ? $this->nextTransfers->get($donor->id.'|'.$lineCurrency) : null;
                    $nextDate = $next === null ? null : \Carbon\CarbonImmutable::parse($next->scheduled_date);
                    $lastReceived = $canTransfers && $hasMoney && $donor->money_last_received !== null ? \Carbon\CarbonImmutable::parse($donor->money_last_received) : null;
                    $overdue = $canTransfers && $hasMoney ? (float) $donor->money_overdue : 0.0;
                @endphp
                <tr wire:key="donor-{{ $donor->id }}-{{ $lineCurrency ?? 'none' }}" data-donor-row data-donor="{{ $donor->id }}">
                    <td data-label="{{ __('donors.donor') }}" data-wide>
                        <span @class(['font-semibold', $mutedText => $isRepeat])>{{ $donor->name }}</span>
                        @unless ($isRepeat)
                            <span class="sub">{{ $donor->type?->label() ?? __('donors.not_classified') }}</span>
                        @endunless
                    </td>
                    <td data-label="{{ __('donors.country') }}">{{ $isRepeat ? '' : ($donor->country ?? '—') }}</td>
                    <td data-label="{{ __('donors.section_contact') }}">
                        @unless ($isRepeat)
                            @if (filled($donor->contact_person) || filled($donor->email) || filled($donor->phone))
                                {{ $donor->contact_person }}
                                @if (filled($donor->email))
                                    <a href="mailto:{{ $donor->email }}" class="sub underline-offset-2 hover:underline">{{ $donor->email }}</a>
                                @endif
                                @if (filled($donor->phone))
                                    <a href="tel:{{ preg_replace('/[^\d+]/', '', $donor->phone) }}" dir="ltr" class="sub underline-offset-2 hover:underline">{{ $donor->phone }}</a>
                                @endif
                            @else
                                <span class="{{ $mutedText }}">{{ __('donors.no_contact_on_file') }}</span>
                            @endif
                        @endunless
                    </td>
                    <td data-label="{{ __('donors.follow_up') }}">
                        @unless ($isRepeat)
                            @if (filled($donor->next_step))
                                {{ $donor->next_step }}
                                @if ($donor->next_step_due_on !== null)
                                    <span @class(['sub', 'font-semibold text-status-critical!' => $donor->isFollowUpOverdue()]) @if ($donor->isFollowUpOverdue()) data-follow-up-overdue @endif>
                                        {{ $donor->isFollowUpOverdue() ? __('donors.follow_up_was_due', ['date' => $date($donor->next_step_due_on)]) : __('donors.follow_up_due', ['date' => $date($donor->next_step_due_on)]) }}
                                    </span>
                                @endif
                            @else
                                —
                            @endif
                        @endunless
                    </td>

                    @if ($canAgreements)
                        <td class="num" data-label="{{ __('finance_tables.agreements') }}"><span class="fig">{{ (int) $donor->money_agreements }}</span></td>
                        <td data-label="{{ __('common.currency') }}">{{ $lineCurrency ?? '—' }}</td>
                        <td class="num" data-label="{{ __('common.committed') }}" data-cell="committed">
                            @if ($hasMoney)
                                <span class="fig font-semibold">{{ $money($donor->money_committed) }}</span>
                            @else
                                —
                            @endif
                        </td>
                    @endif

                    @if ($canTransfers)
                        <td class="num" data-label="{{ __('finance_tables.received') }}" data-cell="received">
                            @if ($hasMoney)
                                <span class="fig font-semibold text-funding">{{ $money($donor->money_received) }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="num" data-label="{{ __('finance_tables.percent_received') }}" data-cell="percent">
                            @if ($hasMoney)
                                <span class="fig">{{ DonorProposal::percentLabel((float) $donor->money_received, (float) $donor->money_committed) }}%</span>
                            @else
                                —
                            @endif
                        </td>
                        @if ($showsYearColumns)
                            <td class="num" data-label="{{ __('finance_tables.due_in_year', ['year' => $selectedYear]) }}" data-cell="due_in_year"><span class="fig">{{ $money($donor->money_due_in_year) }}</span></td>
                            <td class="num" data-label="{{ __('finance_tables.received_in_year', ['year' => $selectedYear]) }}" data-cell="received_in_year"><span class="fig font-semibold text-funding">{{ $money($donor->money_received_in_year) }}</span></td>
                        @endif
                        @unless ($yearOnly)
                            <td class="num" data-label="{{ __('finance_tables.outstanding') }}" data-cell="outstanding">
                                @if ($hasMoney)
                                    <span class="fig">{{ $money($donor->money_outstanding) }}</span>
                                @else
                                    —
                                @endif
                            </td>
                        @endunless
                        <td class="num" data-label="{{ __('finance_tables.overdue') }}" data-cell="overdue">
                            @if ($hasMoney)
                                <span @class(['fig', 'font-semibold text-status-critical' => $overdue > 0])>{{ $money($overdue) }}</span>
                                @if ($overdue > 0)
                                    <span class="sub text-status-critical!">{{ trans_choice('donors.count_transfers_overdue', (int) $donor->money_overdue_count, ['count' => (int) $donor->money_overdue_count]) }}</span>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td class="date" data-label="{{ __('donors.next_transfer') }}">
                            @if ($nextDate !== null)
                                <span @class(['font-semibold text-status-critical' => $nextDate->lt($today)]) data-next-transfer>{{ $date($nextDate) }}</span>
                                <span class="sub"><span class="fig">{{ $money($next->payment_amount) }} {{ $next->sent_currency }}</span></span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="date" data-label="{{ __('finance_tables.last_received') }}">{{ $date($lastReceived) }}</td>
                    @endif

                    <td>
                        @unless ($isRepeat)
                            <span class="flex items-center justify-end">
                                <x-ui.button tone="ghost" size="sm" icon="eye" icon:variant="outline" class="size-8!" :href="route('donors.show', $donor)" wire:navigate :aria-label="__('common.view_name', ['name' => $donor->name])" />
                                @can('donors.update')
                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" icon:variant="outline" class="size-8!" wire:click="openEditModal({{ $donor->id }})" :aria-label="__('common.edit_name', ['name' => $donor->name])" />
                                @endcan
                                @can('donors.delete')
                                    @if ($isProspect)
                                        <x-ui.button tone="ghost" size="sm" icon="trash" icon:variant="outline" class="size-8!" wire:click="confirmDelete({{ $donor->id }})" :aria-label="__('common.delete_name', ['name' => $donor->name])" />
                                    @else
                                        <flux:tooltip :content="__('donors.donors_holding_an_agreement_cannot_be_deleted')">
                                            <span class="inline-flex size-8 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
                                                <flux:icon.lock-closed class="size-4" />
                                            </span>
                                        </flux:tooltip>
                                    @endif
                                @endcan
                            </span>
                        @endunless
                    </td>
                </tr>
            @endforeach

            @if ($canAgreements)
                <x-slot:foot>
                    @foreach ($totals as $line)
                        <tr wire:key="donor-total-{{ $line['currency'] }}" data-register-total="{{ $line['currency'] }}">
                            <td colspan="4" data-wide>
                                {{ __('finance_tables.total') }}
                                <span class="sub">{{ trans_choice('finance_tables.count_donors', $line['donors'], ['count' => $line['donors']]) }}</span>
                            </td>
                            <td class="num" data-label="{{ __('finance_tables.agreements') }}"><span class="fig">{{ $line['agreements'] }}</span></td>
                            <td data-label="{{ __('common.currency') }}">{{ $line['currency'] }}</td>
                            <td class="num" data-label="{{ __('common.committed') }}"><span class="fig">{{ $money($line['committed']) }}</span></td>
                            @if ($canTransfers)
                                <td class="num" data-label="{{ __('finance_tables.received') }}"><span class="fig text-funding">{{ $money($line['received']) }}</span></td>
                                <td class="num" data-label="{{ __('finance_tables.percent_received') }}"><span class="fig">{{ DonorProposal::percentLabel($line['received'], $line['committed']) }}%</span></td>
                                @if ($showsYearColumns)
                                    <td class="num" data-label="{{ __('finance_tables.due_in_year', ['year' => $selectedYear]) }}"><span class="fig">{{ $money($line['due_in_year']) }}</span></td>
                                    <td class="num" data-label="{{ __('finance_tables.received_in_year', ['year' => $selectedYear]) }}"><span class="fig text-funding">{{ $money($line['received_in_year']) }}</span></td>
                                @endif
                                @unless ($yearOnly)
                                    <td class="num" data-label="{{ __('finance_tables.outstanding') }}"><span class="fig">{{ $money($line['outstanding']) }}</span></td>
                                @endunless
                                <td class="num" data-label="{{ __('finance_tables.overdue') }}"><span @class(['fig', 'text-status-critical' => $line['overdue'] > 0])>{{ $money($line['overdue']) }}</span></td>
                                <td colspan="2"></td>
                            @endif
                            <td></td>
                        </tr>
                    @endforeach
                </x-slot:foot>
            @endif
        </x-funding.register>

        <x-funding.pager :paginator="$this->rows" :per-page="$this->perPage" scroll-to="#donor-directory-heading" />
    @endif

    <x-donors.form-modal :editing="$editingDonorId !== null" :type-options="$this->donorTypeOptions" />

    <x-ui.confirm-modal
        wire:model="showDeleteModal"
        close="cancelDelete"
        :title="__('donors.delete_this_donor')"
        :message="__('donors.only_a_donor_holding_no_funding_agreement_can_be_deleted')"
        confirm="deleteDonor"
        :confirm-label="__('donors.delete_donor')"
        confirm-test="confirm-delete-donor"
    />
</section>
```

The template reads `DonorProposal::percentLabel` and `Currency::cases()` by their short names; both are imported in the file's PHP block.

- [x] **Step 5: Run the new tests**

Run: `.\php.cmd artisan test --compact tests/Feature/DonorRegisterTest.php`
Expected: PASS, 12 tests.

Common failures and their cause:
- `Unknown column 'money.currency'` for the Public Relations Manager — a money sort or filter reached a query that did not join. `registerSorts()` must return only the three non-money sorts when the viewer may not see agreements.
- The Public Relations export has more than 9 columns — the heading and row arrays must be built from the same `$canAgreements` / `$canTransfers` flags.
- `toggleType('')` throws — the existing method treats an unknown value as "clear", which is what the `All` pill relies on; do not change it.

- [x] **Step 6: Point the old visual assertions at the Overview view**

In `tests/Feature/DonorDirectoryTest.php` insert `->set('view', 'overview')` on the line directly after `->test('pages::donors.index')` in these tests only:

| Test name | The assertion that needs the Overview view |
| --- | --- |
| `sorting by next transfer due puts the soonest scheduled donor first` | `->set('sort', 'next_due')` orders the Overview list |
| `the overview sizes itself to the funders on screen` | `assertSeeText('None yet: …')` is the donor-mix rail |
| `a pending tranche with no scheduled date does not break the transfers ahead` | asserts the transfers-ahead rail |
| `overdue follow-ups are counted and sort first` | asserts `data-follow-ups-due` and the Overview order |

In the second, the component is built as `$component = Livewire::actingAs($user)->test('pages::donors.index');` — change that line to:

```php
    $component = Livewire::actingAs($user)->test('pages::donors.index')->set('view', 'overview');
```

In `tests/Feature/AccessControl/FundraisingPagesMoneyTest.php`, in the test `Public Relations Manager sees donor names on the donor directory but no money`, change the `$page` closure to:

```php
    $page = fn (string $role) => Livewire::actingAs(userWithRole($role))->test('pages::donors.index')->set('view', 'overview');
```

That test asserts the wallet, the transfers-ahead rail and the abbreviated `123.5K` figure, which are the Overview's. The table's own version of the same guarantee is the two permission tests in `DonorRegisterTest.php`.

Every other test passes unchanged. In particular `someone who may see agreements but not transfers gets budgets without tranches` asserts `731,942` and the absence of `123.5K` and of `data-upcoming-transfers` on the donors page — all true of the table.

- [x] **Step 7: Run every test that touches this page**

Run: `.\php.cmd artisan test --compact tests/Feature/DonorDirectoryTest.php tests/Feature/DonorRegisterTest.php tests/Feature/AccessControl/FundraisingPagesMoneyTest.php tests/Feature/AccessControl/FinanceAuthorizationTest.php tests/Feature/AccessControl/BrowserCallableMethodsTest.php tests/Feature/FundingWorkflowTest.php tests/Feature/SearchWildcardTest.php tests/Feature/LocalizationTest.php`
Expected: PASS.

`BrowserCallableMethodsTest` checks that `donorMoney` cannot be called from the browser. The helpers this phase adds (`joinedQuery`, `applyYear`, `normaliseFilters`, `registerSorts`, `registerDefaultSort`) are `protected` for the same reason; keep them so.

- [x] **Step 8: Checkpoint**

Run: `.\php.cmd vendor/bin/pint --dirty --format agent`. Do not commit.

---

### Task 3: Look at it

**Files:** none changed unless a defect is found.

- [x] **Step 1: Render the page** as in Phase 1, Task 5 for `/donors`, `/donors?year=2026`, `/donors?view=overview`, once as a `Financial` user and once as a `Public Relations Manager`.

- [x] **Step 2: Check each of these and fix what fails**

| Check | Expected |
| --- | --- |
| A donor with two currencies | Two lines; the second repeats the name in the muted colour and leaves country, contact, follow-up and actions empty. |
| A prospect | One line with dashes in the money columns and a delete button. |
| Overdue follow-up | Its due line is in the critical colour. |
| Public Relations Manager | Donor, Country, Contact, Follow-up and the actions only; no totals line, no currency or year pills. |
| Overview view | The pulse strip, wallet, rail and donor list as before, with an "Order" pill row replacing the old Sort select. |
| Dark mode, Arabic, 390px | As in Phase 1. |

- [x] **Step 3: Delete the preview file and checkpoint.** Do not commit.
