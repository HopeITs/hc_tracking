# Funding tables — Phase 5: Proposals register

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Proposals page opens on a table with one row per proposal per currency: status, phase and planned dates, then the donors behind it, committed, received, % received, outstanding, overdue and next due in their own columns; filterable by year, status, phase, donor, currency and flags; sortable, totalled per currency and exportable. Today's funding position, pipeline rail, funding flow and proposal dossiers stay behind the `Table | Overview` switch. The phase ends with one test that pins the Agreements, Donors and Proposals tables to the same figures.

**Architecture:** The same shape as the Donors page (Phase 4): the class keeps everything it has and gains the `FiltersFinanceRegister` trait, two filters and a `rows` paginator that left-joins `AgreementMoney::perOwner('proposal_id', …)`. The join and every column read from it are skipped for a viewer who may not see agreements; tranche figures are zeroed at the source for a viewer who may not see transfers.

**Tech Stack:** Laravel 13, Livewire 4 single-file page components, Flux (lite), Tailwind 4, Pest 5, MySQL.

**Depends on:** Phases 0–4 done.

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
- **Hide money, keep names** (`.ai/rules/pages.md`): on this page a viewer without `funding-agreements.view` sees no donor name, no budget and no donor filter; a viewer without `transfers.view` sees no tranche figure. The query is skipped, not only the cell.
- **Proposal status only moves with an audit note** (`.ai/rules/proposals.md`): the table offers the existing "Change status" action (`openStageModal`), never a status edit of its own.
- A proposal's own name is plain text in its row; its page opens from the eye button placed first in the actions.
- No existing test is deleted. A test that asserts on the old visual is pointed at the Overview view with `->set('view', 'overview')`.
- Line numbers quoted below are for commit `99c95eb`.

## File Structure

| File | Responsibility |
| --- | --- |
| Create `resources/views/pages/proposals/_overview.blade.php` | Today's funding position, pipeline, flow and dossiers, moved unchanged. |
| Modify `resources/views/pages/proposals/⚡index.blade.php` | New filters, table data, template. |
| Create `tests/Feature/ProposalRegisterTest.php` | The table. |
| Modify `tests/Feature/ProposalPipelineTest.php` | Point two visual assertions at the Overview view. |
| Modify `tests/Feature/AccessControl/FundraisingPagesMoneyTest.php` | Point one visual assertion at the Overview view. |
| Modify `tests/Feature/FundingWorkflowTest.php` | Pin the three tables to the same figures. |

---

### Task 1: Move today's overview into a partial

A pure move: the page looks and behaves exactly as before when this task ends.

**Files:**
- Create: `resources/views/pages/proposals/_overview.blade.php`
- Modify: `resources/views/pages/proposals/⚡index.blade.php` (lines 811–1287)

- [ ] **Step 1: Confirm the block boundaries**

```bash
cd /c/xampp/htdocs/hc-tracking && sed -n '808p;810p;811p;1287p;1288p' "resources/views/pages/proposals/⚡index.blade.php"
```

Expected output, exactly:

```
    @if (! $hasAnyProposal)
    @else
        {{-- Where the money stands: one line per currency, never summed across them. --}}
        </section>
    @endif
```

- [ ] **Step 2: Build the partial**

The partial defines the four values only it needs, so the table view never pays for the queries behind them.

```bash
cd /c/xampp/htdocs/hc-tracking && {
  cat <<'BLADE'
{{--
    The Overview view of the proposals page: where the money stands, the
    pipeline rail, the funding flow and one dossier per proposal. Included by
    ⚡index.blade.php, which defines $amount, $shortAmount, $canAgreements,
    $mutedText, $inkText, $receivedText and $hairline.
--}}
@php
    $lifecycle = \App\Enums\ProposalStatus::lifecycle();
    $matchingCount = $this->filteredProposalIds->count();
    // The flow names every matching proposal but the list below shows one page
    // of them: a proposal on this page is scrolled to, any other one opened.
    $pageProposalIds = $this->proposals->getCollection()->modelKeys();
    $proposalHref = fn (int $id): string => in_array($id, $pageProposalIds, true) ? '#proposal-'.$id : route('proposals.show', tap(new \App\Models\Proposal, fn ($proposal) => $proposal->id = $id));
@endphp

BLADE
  sed -n '811,1287p' "resources/views/pages/proposals/⚡index.blade.php"
} > resources/views/pages/proposals/_overview.blade.php
```

- [ ] **Step 3: Qualify the class references the partial can no longer import**

In `resources/views/pages/proposals/_overview.blade.php`, below the header block:

1. Replace the one occurrence of `tap(new Project, ` with `tap(new \App\Models\Project, `.
2. Replace both occurrences of `ProposalStatus::Rejected` with `\App\Enums\ProposalStatus::Rejected`.
3. Replace the one occurrence of `ProposalStatus::Submitted` with `\App\Enums\ProposalStatus::Submitted`.

Verify:

```bash
cd /c/xampp/htdocs/hc-tracking && grep -n -E "new (Project|Proposal)\b|(^|[^\\\\A-Za-z])(ProposalStatus|ProposalPhase|ArStatus|TransfersTracking|Collection|Number)::" resources/views/pages/proposals/_overview.blade.php
```

Expected: no output.

- [ ] **Step 4: Replace the block in the page with the include**

In `resources/views/pages/proposals/⚡index.blade.php` delete lines 811–1287 and put this single line in their place:

```blade
        @include('pages.proposals._overview')
```

- [ ] **Step 5: Prove nothing changed**

Run: `.\php.cmd artisan test --compact tests/Feature/ProposalPipelineTest.php tests/Feature/AccessControl/FundraisingPagesMoneyTest.php tests/Feature/FundingWorkflowTest.php`
Expected: PASS, the same counts as before.

---

### Task 2: The register — class, template and tests

**Files:**
- Modify: `resources/views/pages/proposals/⚡index.blade.php`
- Create: `tests/Feature/ProposalRegisterTest.php`
- Modify: `tests/Feature/ProposalPipelineTest.php`
- Modify: `tests/Feature/AccessControl/FundraisingPagesMoneyTest.php`

**Interfaces:**
- Consumes (Phase 0): trait `FiltersFinanceRegister`; `AgreementMoney::perOwner('proposal_id', ?int $year, bool $yearOnly, bool $withTransfers)`, `::ownerSelect()`, `::ownerTotalsSelect(string $ownerKey)`, `::yearBounds()`; `TransfersTracking` scope `inState()`; `CsvExport`; the register components; `downloadedCsv()`.
- Produces: public properties `search`, `status`, `phase`, `donor`, `currency`, `flag`; computed `rows`, `totals`, `proposalCount`, `filterDonors`, `hasAnyProposal`; actions `toggleFlag`, `exportCsv`; file name `proposals-{Y-m-d}.csv`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ProposalRegisterTest.php`:

```php
<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Project;
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
 * The proposals page as the finance manager opens it.
 *
 * @param  array<string, string>  $query
 */
function proposalRegister(array $query = []): Testable
{
    return Livewire::actingAs(test()->user)->withQueryParams($query)->test('pages::proposals.index');
}

/**
 * A tranche in its agreement's own currency.
 *
 * @param  array<string, mixed>  $attributes
 */
function proposalTranche(DonorProposal $agreement, array $attributes): TransfersTracking
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
 * Water Response, active through 2026: ECHO commits 1,000 USD (400 received,
 * 390 net; 200 overdue; 100 due on 25 June) and GIZ commits 500 EUR with
 * nothing scheduled. It funds one project.
 */
function registerFundedProposal(): Proposal
{
    $proposal = Proposal::factory()->create([
        'name' => 'Water Response',
        'status' => ProposalStatus::Active,
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
    ]);
    $proposal->projects()->attach(Project::factory()->create());

    $dollars = DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'donor_id' => Donor::factory()->create(['name' => 'ECHO'])->id,
        'budget' => '1000.00',
        'currency' => Currency::Usd,
    ]);
    DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'donor_id' => Donor::factory()->create(['name' => 'GIZ'])->id,
        'budget' => '500.00',
        'currency' => Currency::Eur,
    ]);

    proposalTranche($dollars, ['payment_amount' => '400.00', 'net_amount_received' => '390.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2026-05-15', 'transfer_date' => '2026-05-15', 'receive_date' => '2026-05-20']);
    proposalTranche($dollars, ['payment_amount' => '200.00', 'scheduled_date' => '2026-06-05']);
    proposalTranche($dollars, ['payment_amount' => '100.00', 'scheduled_date' => '2026-06-25']);

    return $proposal;
}

test('the page opens on the table with one row per proposal and currency', function () {
    $funded = registerFundedProposal();
    $idea = Proposal::factory()->create(['name' => 'Shelter Response', 'status' => ProposalStatus::ConceptNote, 'planned_date_begin' => '2025-03-01', 'planned_date_end' => '2025-09-30']);

    $page = proposalRegister()
        ->assertSet('view', 'table')
        ->assertSeeHtml('data-register')
        ->assertDontSeeHtml('data-funding-position')
        ->assertSee('Water Response')
        ->assertSee('Shelter Response')
        ->assertSee('ECHO')
        ->assertSee('GIZ')
        ->assertSee('1,000.00')
        ->assertSee('390.00')
        ->assertSee('39%')
        ->assertSee('600.00');

    $rows = $page->instance()->rows->getCollection();

    expect($rows->map(fn (Proposal $row): string => $row->name.'|'.($row->money_currency ?? '-'))->all())
        ->toBe(['Water Response|EUR', 'Water Response|USD', 'Shelter Response|-'])
        ->and($page->instance()->proposalCount)->toBe(2);

    $dollars = $rows->firstWhere('money_currency', 'USD');

    expect((int) $dollars->money_agreements)->toBe(1)
        ->and((float) $dollars->money_committed)->toBe(1000.0)
        ->and((float) $dollars->money_received)->toBe(390.0)
        ->and((float) $dollars->money_outstanding)->toBe(600.0)
        ->and((float) $dollars->money_overdue)->toBe(200.0)
        ->and($dollars->money_next_due)->toBe('2026-06-05')
        ->and((int) $dollars->projects_count)->toBe(1)
        ->and($rows->last()->id)->toBe($idea->id);
});

test('the overview view shows the funding position and the dossiers instead of the table', function () {
    registerFundedProposal();

    proposalRegister()
        ->set('view', 'overview')
        ->assertSeeHtml('data-funding-position')
        ->assertDontSeeHtml('data-register');
});

test('a row offers the view, change status, edit and delete actions the dossier offered', function () {
    $funded = registerFundedProposal();
    $idea = Proposal::factory()->create(['name' => 'Shelter Response']);

    proposalRegister()
        ->assertSeeHtml('aria-label="View Water Response"')
        ->assertSeeHtml('href="'.route('proposals.show', $funded).'"')
        ->assertSeeHtml('data-test="advance-proposal-'.$funded->id.'"')
        ->assertSeeHtml('wire:click="confirmDelete('.$idea->id.')"')
        ->assertDontSeeHtml('wire:click="confirmDelete('.$funded->id.')"');
});

test('currency, donor and the flags narrow the rows', function () {
    $funded = registerFundedProposal();
    $idea = Proposal::factory()->create(['name' => 'Shelter Response']);
    $reporting = Proposal::factory()->create(['name' => 'Clinic Rebuild']);
    DonorReport::factory()->create([
        'donor_proposal_id' => DonorProposal::factory()->create(['proposal_id' => $reporting->id, 'currency' => Currency::Usd])->id,
        'due_on' => '2026-06-01',
    ]);

    $proposalIds = fn (Testable $page): array => $page->instance()->rows->pluck('id')->unique()->sort()->values()->all();
    $echo = Donor::query()->where('name', 'ECHO')->sole();

    expect($proposalIds(proposalRegister()->call('toggleFlag', 'no_agreement')))->toBe([$idea->id])
        ->and($proposalIds(proposalRegister()->call('toggleFlag', 'overdue')))->toBe([$funded->id])
        ->and($proposalIds(proposalRegister()->call('toggleFlag', 'report_overdue')))->toBe([$reporting->id])
        ->and($proposalIds(proposalRegister()->set('donor', (string) $echo->id)))->toBe([$funded->id]);

    proposalRegister()->set('donor', (string) $echo->id)->assertSee('Funded by ECHO')->assertSeeHtml('data-donor-chip');

    // A currency keeps only that currency's line of each proposal.
    $euros = proposalRegister()->set('currency', 'EUR')->instance()->rows->getCollection();

    expect($euros)->toHaveCount(1)
        ->and($euros->first()->money_currency)->toBe('EUR');
});

test('choosing a year adds that year beside the lifetime figures and hides lines with nothing in it', function () {
    registerFundedProposal();
    $earlier = Proposal::factory()->create(['name' => 'Old Grant']);
    proposalTranche(
        DonorProposal::factory()->create(['proposal_id' => $earlier->id, 'budget' => '700.00', 'currency' => Currency::Usd]),
        ['payment_amount' => '700.00', 'net_amount_received' => '700.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2024-02-01', 'receive_date' => '2024-02-05'],
    );

    $page = proposalRegister()->set('year', '2026');
    $row = $page->instance()->rows->getCollection()->sole();

    expect($row->name)->toBe('Water Response')
        ->and($row->money_currency)->toBe('USD')
        ->and((float) $row->money_due_in_year)->toBe(700.0)
        ->and((float) $row->money_received_in_year)->toBe(390.0);

    $page->assertSee('Due in 2026')->assertSee('Received in 2026');

    $only = proposalRegister()->set('year', '2024')->set('figures', 'year')->instance()->rows->getCollection()->sole();

    expect($only->name)->toBe('Old Grant')
        ->and((float) $only->money_received)->toBe(700.0);
});

test('totals are kept per currency and add up to the rows shown', function () {
    registerFundedProposal();
    DonorProposal::factory()->create(['budget' => '2000.00', 'currency' => Currency::Usd]);

    $page = proposalRegister();
    $totals = $page->instance()->totals->keyBy('currency');

    expect($totals->keys()->all())->toBe(['EUR', 'USD'])
        ->and($totals['USD'])->toMatchArray(['proposals' => 2, 'agreements' => 2, 'committed' => 3000.0, 'received' => 390.0, 'outstanding' => 2600.0, 'overdue' => 200.0])
        ->and($totals['EUR'])->toMatchArray(['proposals' => 1, 'committed' => 500.0]);

    $page->assertSeeHtml('data-register-total="USD"')
        ->assertSeeHtml('data-register-total="EUR"')
        ->assertDontSee('3,500.00');
});

test('the table opens newest planned start first and a heading sorts it', function () {
    registerFundedProposal();
    $older = Proposal::factory()->create(['name' => 'Alpha Appeal', 'planned_date_begin' => '2025-01-01', 'planned_date_end' => '2025-12-31']);
    DonorProposal::factory()->create(['proposal_id' => $older->id, 'budget' => '9000.00', 'currency' => Currency::Usd]);

    $lines = fn (Testable $page): array => $page->instance()->rows->getCollection()->map(fn (Proposal $row): string => $row->name.'|'.$row->money_currency)->all();

    $page = proposalRegister();
    expect($lines($page))->toBe(['Water Response|EUR', 'Water Response|USD', 'Alpha Appeal|USD']);

    $page->call('sortRegister', 'proposal');
    expect($lines($page))->toBe(['Alpha Appeal|USD', 'Water Response|EUR', 'Water Response|USD']);

    $page->call('sortRegister', 'committed');
    expect($lines($page))->toBe(['Water Response|EUR', 'Alpha Appeal|USD', 'Water Response|USD']);
});

test('someone who may not see agreements gets the proposals with no donor, no money and no money query', function () {
    registerFundedProposal();

    $page = Livewire::actingAs(userWithRole('Public Relations Manager'))->test('pages::proposals.index');

    $page->assertOk()
        ->assertSee('Water Response')
        ->assertDontSee('ECHO')
        ->assertDontSee('1,000.00')
        ->assertDontSeeHtml('data-cell="committed"')
        ->assertDontSeeHtml('data-register-totals');

    expect($page->instance()->rows->getCollection())->toHaveCount(1)
        ->and($page->instance()->totals)->toBeEmpty()
        ->and($page->instance()->filterDonors)->toBeEmpty()
        ->and(array_key_exists('money_committed', $page->instance()->rows->first()->getAttributes()))->toBeFalse()
        ->and($page->instance()->rows->first()->relationLoaded('donorProposals'))->toBeFalse();
});

test('someone who may see agreements but not transfers gets budgets without tranche figures', function () {
    registerFundedProposal();
    $viewer = userWithRole('Public Relations Manager')->givePermissionTo('funding-agreements.view');

    $page = Livewire::actingAs($viewer)->test('pages::proposals.index');

    $page->assertSee('ECHO')
        ->assertSee('1,000.00')
        ->assertDontSee('390.00')
        ->assertDontSeeHtml('data-cell="received"');

    expect((float) $page->instance()->rows->getCollection()->firstWhere('money_currency', 'USD')->money_received)->toBe(0.0);
});

test('values typed into the address bar fall back to the defaults', function () {
    registerFundedProposal();

    proposalRegister(['view' => 'board', 'status' => 'lost', 'phase' => 'moon', 'donor' => 'me', 'currency' => 'GBP', 'flag' => 'rich', 'sortBy' => 'notes', 'perPage' => '2'])
        ->assertSet('view', 'table')
        ->assertSet('status', '')
        ->assertSet('phase', '')
        ->assertSet('donor', '')
        ->assertSet('currency', '')
        ->assertSet('flag', '')
        ->assertSet('sortBy', '')
        ->assertSet('perPage', '25')
        ->assertOk();
});

test('the export holds every filtered line and only the columns the viewer may see', function () {
    registerFundedProposal();
    Proposal::factory()->count(30)->create(['planned_date_begin' => '2020-01-01', 'planned_date_end' => '2020-12-31']);

    $page = proposalRegister()->call('exportCsv')->assertFileDownloaded('proposals-2026-06-15.csv');
    $csv = downloadedCsv($page);
    $dollars = collect($csv)->first(fn (array $row): bool => $row[0] === 'Water Response' && $row[8] === 'USD');

    expect($csv)->toHaveCount(33)
        ->and($csv[0][0])->toBe('Proposal')
        ->and($csv[0][9])->toBe('Committed')
        ->and($dollars[1])->toBe('Active')
        ->and($dollars[3])->toBe('2026-01-01')
        ->and($dollars[4])->toBe('2026-12-31')
        ->and($dollars[5])->toBe('1')
        ->and($dollars[6])->toBe('ECHO')
        ->and($dollars[7])->toBe('1')
        ->and($dollars[9])->toBe('1000.00')
        ->and($dollars[10])->toBe('390.00')
        ->and($dollars[11])->toBe('39')
        ->and($dollars[12])->toBe('600.00')
        ->and($dollars[13])->toBe('200.00')
        ->and($dollars[14])->toBe('2026-06-05');

    $publicRelations = Livewire::actingAs(userWithRole('Public Relations Manager'))->test('pages::proposals.index')->call('exportCsv');
    $plain = downloadedCsv($publicRelations);

    expect($plain[0])->toHaveCount(6)
        ->and(collect($plain)->flatten()->contains('ECHO'))->toBeFalse()
        ->and(collect($plain)->flatten()->contains('1000.00'))->toBeFalse();
});

test('exporting needs the proposals view permission', function () {
    registerFundedProposal();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::proposals.index')
        ->call('exportCsv')
        ->assertForbidden();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `.\php.cmd artisan test --compact tests/Feature/ProposalRegisterTest.php`
Expected: FAIL — `Property [$view] not found on component`.

- [ ] **Step 3: Extend the component class**

All edits are in the PHP block of `resources/views/pages/proposals/⚡index.blade.php`.

**3a. Imports.** Add these to the `use` list at the top (keep the list alphabetical):

```php
use App\Enums\Currency;
use App\Livewire\Concerns\FiltersFinanceRegister;
use App\Support\AgreementMoney;
use App\Support\CsvExport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\StreamedResponse;
```

**3b. Trait, constants and filter properties.** Replace the lines from `use WithPagination;` through the `$donor` property (lines 31–43) with:

```php
    use FiltersFinanceRegister;
    use WithPagination;

    /** @var list<string> */
    public const FLAGS = ['no_agreement', 'overdue', 'report_overdue'];

    /**
     * Every property a filter control writes.
     *
     * @var list<string>
     */
    private const FILTERS = ['search', 'status', 'phase', 'donor', 'currency', 'flag'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $phase = '';

    #[Url(except: '')]
    public string $donor = '';

    #[Url(except: '')]
    public string $currency = '';

    #[Url(except: '')]
    public string $flag = '';
```

**3c. Filter handling.** Replace the four methods `updatedSearch()`, `updatedStatus()`, `updatedPhase()` and `clearFilters()` (lines 68–90) with:

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
        if (ProposalStatus::tryFrom($this->status) === null) {
            $this->status = '';
        }

        if (ProposalPhase::tryFrom($this->phase) === null) {
            $this->phase = '';
        }

        // The donor filter narrows by funding agreements, so it exists only for a viewer who may see them.
        if (! ctype_digit($this->donor) || ! $this->canViewAgreements()) {
            $this->donor = '';
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
        $this->reset(['search', 'status', 'phase', 'donor', 'currency', 'flag']);
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
     * The donors that hold an agreement, for the donor picker. Empty for a
     * viewer who may not see agreements: a donor's name next to a proposal is
     * what that permission protects.
     *
     * @return EloquentCollection<int, Donor>
     */
    #[Computed]
    public function filterDonors(): EloquentCollection
    {
        return $this->canViewAgreements()
            ? Donor::query()->has('donorProposals')->orderBy('name')->orderBy('id')->get(['id', 'name'])
            : new EloquentCollection;
    }
```

**3d. The filtered query.** Replace the body of `filteredQuery()` (keep its doc block) with:

```php
    protected function filteredQuery(bool $withPhase = true): Builder
    {
        $search = trim($this->search);
        $canAgreements = $this->canViewAgreements();
        $canTransfers = $canAgreements && $this->canViewTransfers();
        // The donor filter narrows by funding agreements, so it only applies to a viewer who may see them.
        $donorId = $canAgreements ? (int) $this->donor : 0;
        $today = now()->toDateString();

        return Proposal::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('proposals.name', 'like', Like::contains($search))->orWhere('proposals.notes', 'like', Like::contains($search));
                });
            })
            ->when(ProposalStatus::tryFrom($this->status), fn (Builder $query, ProposalStatus $status) => $query->where('proposals.status', $status))
            // There is no phase column: a phase is a set of statuses, derived
            // from the same match that ProposalStatus::phase() uses.
            ->when(
                $withPhase && ProposalPhase::tryFrom($this->phase) !== null,
                fn (Builder $query) => $query->whereIn('proposals.status', ProposalPhase::from($this->phase)->statuses())
            )
            ->when($donorId > 0, fn (Builder $query) => $query->whereHas('donorProposals', fn (Builder $query) => $query->where('donor_id', $donorId)))
            // A money filter only applies to a viewer who may see that money: otherwise it would leak it one row at a time.
            ->when($this->currency !== '' && $canAgreements, fn (Builder $query) => $query->whereHas('donorProposals', fn (Builder $query) => $query->where('currency', $this->currency)))
            ->when($this->flag === 'no_agreement' && $canAgreements, fn (Builder $query) => $query->doesntHave('donorProposals'))
            ->when($this->flag === 'report_overdue' && $canAgreements, fn (Builder $query) => $query->whereHas('donorProposals.reports', fn (Builder $query) => $query->whereNull('submitted_on')->whereDate('due_on', '<', $today)))
            ->when($this->flag === 'overdue' && $canTransfers, fn (Builder $query) => $query->whereHas('donorProposals.transfers', fn (Builder $query) => $query->inState('late')))
            ->when($this->selectedYear() !== null && $canTransfers, fn (Builder $query) => $this->applyYear($query));
    }

    /**
     * Keep only proposals with money in the chosen year: a tranche due in it
     * or, while the lifetime figures are shown, one that landed in it.
     *
     * @param  Builder<Proposal>  $query
     * @return Builder<Proposal>
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

The existing `pipeline()` method builds on `filteredQuery(withPhase: false)` and groups by `proposals.status`; qualifying the columns above keeps it valid.

**3e. The table's data.** Directly before the existing `canDelete()` method add:

```php
    /**
     * The filtered proposals joined to their money, one line per proposal and
     * currency; a proposal with no agreement keeps a single line with none.
     *
     * A viewer who may not see agreements gets the proposals alone: the join
     * is never made. A viewer who may see agreements but not transfers gets
     * the join with every tranche figure zeroed at its source.
     *
     * @return Builder<Proposal>
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
            ->leftJoinSub(AgreementMoney::perOwner('proposal_id', $year, $yearOnly, $canTransfers), 'money', 'money.owner_id', '=', 'proposals.id')
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
        $tail = $canAgreements ? ['proposals.id asc', 'money.currency asc'] : ['proposals.id asc'];
        $date = fn (string $column, string $dir): array => ['dir' => $dir, 'order' => ["proposals.{$column} is null", "proposals.{$column} {dir}", ...$tail]];

        $sorts = [
            'proposal' => ['dir' => 'asc', 'order' => ['proposals.name {dir}', ...$tail]],
            'status' => ['dir' => 'asc', 'order' => ['proposals.status {dir}', 'proposals.name asc', ...$tail]],
            'planned_start' => $date('planned_date_begin', 'desc'),
            'planned_end' => $date('planned_date_end', 'desc'),
            'projects' => ['dir' => 'desc', 'order' => ['projects_count {dir}', 'proposals.name asc', ...$tail]],
        ];

        if (! $canAgreements) {
            return $sorts;
        }

        $byName = ['proposals.name asc', 'proposals.id asc'];
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
            'next_due' => ['dir' => 'asc', 'order' => ['money.o_next_due is null', 'money.o_next_due {dir}', ...$byName]],
        ];
    }

    protected function registerDefaultSort(): string
    {
        return 'planned_start';
    }

    /**
     * The columns every line carries, whoever is looking.
     *
     * @param  Builder<Proposal>  $query
     * @return Builder<Proposal>
     */
    protected function selectLine(Builder $query): Builder
    {
        $query
            ->select(['proposals.id', 'proposals.slug', 'proposals.name', 'proposals.planned_date_begin', 'proposals.planned_date_end', 'proposals.status'])
            ->withCount(['projects', 'donorProposals']);

        if ($this->canViewAgreements()) {
            $query->selectRaw(AgreementMoney::ownerSelect());
        }

        return $query;
    }

    /**
     * The table's visible page. The donors behind each proposal load only for
     * a viewer who may see agreements.
     *
     * @return LengthAwarePaginator<int, Proposal>
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $query = $this->selectLine($this->joinedQuery());

        if ($this->canViewAgreements()) {
            $query->with([
                'donorProposals' => fn ($query) => $query->select(['id', 'proposal_id', 'donor_id', 'currency'])->orderBy('id'),
                'donorProposals.donor' => fn ($query) => $query->select(['id', 'slug', 'name']),
            ]);
        }

        return $this->applyRegisterSort($query)->paginate($this->registerPerPage());
    }

    /**
     * How many proposals the table holds: the header's count, which a
     * proposal with two currencies must not inflate.
     */
    #[Computed]
    public function proposalCount(): int
    {
        return $this->joinedQuery()->distinct()->count('proposals.id');
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
            ->selectRaw(AgreementMoney::ownerTotalsSelect('proposals.id'))
            ->groupBy('money.currency')
            ->orderBy('money.currency')
            ->get()
            ->map(fn (object $row): array => [
                'currency' => (string) $row->currency,
                'proposals' => (int) $row->owners,
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
     * Whether any proposal exists at all, so an empty page can tell "nothing
     * recorded yet" from "nothing matches".
     */
    #[Computed]
    public function hasAnyProposal(): bool
    {
        return Proposal::query()->exists();
    }

    /**
     * Every line the filters match, in the table's order, as a CSV file. The
     * file holds the columns the viewer sees on screen and no others. The
     * donors of a line are named by one grouped subquery, so the rows can be
     * read lazily.
     */
    public function exportCsv(): StreamedResponse
    {
        Gate::authorize('proposals.view');

        $canAgreements = $this->canViewAgreements();
        $canTransfers = $canAgreements && $this->canViewTransfers();
        $year = $canTransfers ? $this->selectedYear() : null;

        $query = $this->selectLine($this->joinedQuery());

        if ($canAgreements) {
            $query->selectRaw("(select group_concat(distinct donors.name order by donors.name separator ', ')
                from donor_proposal as funding
                inner join donors on donors.id = funding.donor_id
                where funding.proposal_id = proposals.id and funding.currency = money.currency) as donor_names");
        }

        $proposals = $this->applyRegisterSort($query)->cursor();

        $headings = [
            __('common.proposal'),
            __('common.status'),
            __('proposals.phase'),
            __('proposals.planned_start'),
            __('proposals.planned_end'),
            __('common.projects'),
            ...($canAgreements ? [__('common.donors'), __('finance_tables.agreements'), __('common.currency'), __('common.committed')] : []),
            ...($canTransfers ? [
                __('finance_tables.received'),
                __('finance_tables.percent_received'),
                __('finance_tables.outstanding'),
                __('finance_tables.overdue'),
                __('finance_tables.next_due'),
            ] : []),
            ...($year === null ? [] : [
                __('finance_tables.due_in_year', ['year' => $year]),
                __('finance_tables.received_in_year', ['year' => $year]),
            ]),
        ];

        $rows = (function () use ($proposals, $canAgreements, $canTransfers, $year) {
            foreach ($proposals as $proposal) {
                $hasMoney = $canAgreements && $proposal->money_currency !== null;

                yield [
                    $proposal->name,
                    $proposal->status->label(),
                    $proposal->phase->label(),
                    $proposal->planned_date_begin,
                    $proposal->planned_date_end,
                    (int) $proposal->projects_count,
                    ...($canAgreements ? [
                        $proposal->getAttribute('donor_names'),
                        (int) $proposal->money_agreements,
                        $proposal->money_currency,
                        $hasMoney ? CsvExport::amount($proposal->money_committed) : null,
                    ] : []),
                    ...($canTransfers ? [
                        $hasMoney ? CsvExport::amount($proposal->money_received) : null,
                        $hasMoney ? DonorProposal::percentLabel((float) $proposal->money_received, (float) $proposal->money_committed) : null,
                        $hasMoney ? CsvExport::amount($proposal->money_outstanding) : null,
                        $hasMoney ? CsvExport::amount($proposal->money_overdue) : null,
                        $proposal->money_next_due,
                    ] : []),
                    ...($year === null ? [] : [
                        CsvExport::amount($proposal->money_due_in_year),
                        CsvExport::amount($proposal->money_received_in_year),
                    ]),
                ];
            }
        })();

        return CsvExport::download('proposals-'.CarbonImmutable::today()->toDateString().'.csv', $headings, $rows);
    }
```

Everything else in the class (`togglePhase`, `toggleDonor`, the two permission helpers, `unreadIds`, `proposals`, `filteredProposalIds`, `fundingPosition`, `pipeline`, `flow`, `donorFilterName`, `agreementMoney`, `canDelete`, and the proposal, stage and delete forms) stays exactly as it is.

- [ ] **Step 4: Replace the head of the template**

The template now runs: an `@php` block, the `<section>` with its header, the active-filters strip, the `@if (! $hasAnyProposal) … @else @include … @endif` block, then three modals. Replace everything from the `@php` line directly after the closing `?>` up to, but not including, the first modal (`<x-ui.modal` with `wire:model="showProposalModal"`) with:

```blade
@php
    $showsOverview = $this->view === 'overview';
    $canAgreements = $this->canViewAgreements();
    $canTransfers = $canAgreements && $this->canViewTransfers();
    $filtersAreActive = collect([$this->search, $this->status, $this->phase, $this->donor, $this->currency, $this->flag, $this->year])
        ->contains(fn (string $value): bool => $value !== '');
    $total = $showsOverview ? $this->proposals->total() : $this->proposalCount;

    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $inkText = 'text-secondary-dark dark:text-ink';
    $receivedText = 'text-funding';
    $hairline = 'border-secondary-soft dark:border-line';

    /* The two closures below format the Overview view (_overview.blade.php). The table prints exact amounts itself. */
    $amount = fn (float $value): string => Number::format($value, maxPrecision: 0);
    $shortAmount = fn (float $value): string => Number::abbreviate($value, maxPrecision: 1);

    /* `$chosen` is read instead of the properties so a loop variable can never shadow one. */
    $chosen = [
        'year' => $this->year,
        'figures' => $this->figures,
        'status' => $this->status,
        'phase' => $this->phase,
        'currency' => $this->currency,
        'flag' => $this->flag,
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
    $statusPills = [
        ['label' => __('finance_tables.all'), 'click' => "\$set('status', '')", 'active' => $chosen['status'] === ''],
        ...array_map(fn (ProposalStatus $case): array => $setPill('status', $case->value, $case->label()), ProposalStatus::cases()),
    ];
    /* The pipeline counts ignore the phase filter itself, so every phase stays countable. */
    $phasePills = [
        ['label' => __('finance_tables.all'), 'click' => "togglePhase('')", 'active' => $chosen['phase'] === ''],
        ...array_map(fn (array $stop): array => [
            'label' => $stop['phase']->label(),
            'click' => "togglePhase('{$stop['phase']->value}')",
            'active' => $chosen['phase'] === $stop['phase']->value,
            'count' => $stop['count'],
            'test' => 'phase-'.$stop['phase']->value,
        ], $this->pipeline),
    ];
    $currencyPills = [
        ['label' => __('finance_tables.all'), 'click' => "\$set('currency', '')", 'active' => $chosen['currency'] === ''],
        ...array_map(fn (Currency $case): array => $setPill('currency', $case->value, $case->value), Currency::cases()),
    ];
    $flagPills = array_values(array_filter([
        ['label' => __('finance_tables.flag_no_agreement'), 'click' => "toggleFlag('no_agreement')", 'active' => $chosen['flag'] === 'no_agreement', 'test' => 'flag-no-agreement'],
        $canTransfers ? ['label' => __('finance_tables.flag_has_overdue'), 'click' => "toggleFlag('overdue')", 'active' => $chosen['flag'] === 'overdue', 'tone' => 'critical', 'test' => 'flag-overdue'] : null,
        ['label' => __('finance_tables.flag_report_overdue'), 'click' => "toggleFlag('report_overdue')", 'active' => $chosen['flag'] === 'report_overdue', 'test' => 'flag-report-overdue'],
    ]));
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" aria-labelledby="proposal-pipeline-heading">
    <x-ui.page-header
        heading-id="proposal-pipeline-heading"
        :title="__('common.proposals')"
        :count="$total"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('proposals.search_proposals')" :placeholder="__('proposals.name_or_notes')" wire:model.live.debounce.300ms="search" clearable />

            @if ($canAgreements)
                <x-ui.header-filter-select :label="__('funding_agreements.donor')" wire:model.live="donor" :value="$this->donor">
                    <flux:select.option value="">{{ __('funding_agreements.all_donors') }}</flux:select.option>
                    @foreach ($this->filterDonors as $donorOption)
                        <flux:select.option :value="$donorOption->id" :wire:key="'proposal-donor-'.$donorOption->id">{{ $donorOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.header-filter-select>
            @endif
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

                @if ($this->hasAnyProposal)
                    <x-ui.button icon="arrow-down-tray" class="min-h-11 touch-manipulation @sm/directory:min-h-10" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv" data-test="export-csv">
                        {{ __('finance_tables.export_csv') }}
                    </x-ui.button>
                @endif

                @can('create', App\Models\Proposal::class)
                <x-ui.button tone="primary" icon="plus" class="min-h-11 touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-proposal-button">
                    {{ __('proposals.add_proposal') }}
                </x-ui.button>
                @endcan
            </div>
        </x-slot:actions>

        @if ($this->hasAnyProposal)
            <x-slot:summary>
                <x-funding.filters :active="$filtersAreActive">
                    {{-- A year narrows by tranches, so it is offered only to someone who may see them. --}}
                    @if ($canTransfers)
                        <x-funding.pills :label="__('finance_tables.year')" :pills="$yearPills" />

                        @if ($selectedYear !== null)
                            <x-funding.pills :label="__('finance_tables.figures_label')" :pills="$figurePills" />
                        @endif
                    @endif

                    <x-funding.pills :label="__('common.status')" :pills="$statusPills" />

                    {{-- The Overview view chooses the phase on its own pipeline rail. --}}
                    @unless ($showsOverview)
                        <x-funding.pills :label="__('proposals.phase')" :pills="$phasePills" />
                    @endunless

                    @if ($canAgreements)
                        <x-funding.pills :label="__('common.currency')" :pills="$currencyPills" />
                        <x-funding.pills :label="__('finance_tables.flags')" :pills="$flagPills" />
                    @endif

                    @if ($this->donorFilterName !== null)
                        <x-funding.filter-row :label="__('funding_agreements.donor')">
                            <button type="button" wire:click="toggleDonor({{ (int) $this->donor }})" class="inline-flex min-h-8 items-center gap-1.5 rounded-full bg-funding-soft ps-3 pe-2 text-xs font-semibold text-funding transition-colors hover:bg-funding hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-ink dark:hover:text-surface-0" aria-label="{{ __('proposals.remove_donor_filter', ['name' => $this->donorFilterName]) }}" data-donor-chip>
                                {{ __('proposals.funded_by', ['name' => $this->donorFilterName]) }}
                                <flux:icon.x-mark variant="micro" class="size-3.5" />
                            </button>
                        </x-funding.filter-row>
                    @endif
                </x-funding.filters>
            </x-slot:summary>
        @endif
    </x-ui.page-header>

    @if (! $this->hasAnyProposal)
        <x-ui.empty-state icon="document-text" :heading="__('proposals.no_proposals_yet')" :body="__('proposals.add_the_first_proposal_to_open_the_pipeline')" />
    @elseif ($showsOverview)
        @include('pages.proposals._overview')
    @elseif ($this->rows->isEmpty())
        <x-ui.empty-state icon="document-text" :heading="__('proposals.no_matching_proposals')" :body="__('common.change_the_search_or_filters_and_try_again')" />
    @else
        @php
            $totals = $this->totals;
            $money = fn (string|float|int|null $value): string => number_format((float) $value, 2);
            $date = fn (?\DateTimeInterface $value): string => $value?->translatedFormat(__('formats.date')) ?? '—';
            $today = \Carbon\CarbonImmutable::today();
            $showsYearColumns = $selectedYear !== null && ! $yearOnly;
            $unreadIds = $this->unreadIds;

            $totalLines = $totals->map(fn (array $line): array => [
                'currency' => $line['currency'],
                'caption' => trans_choice('finance_tables.count_proposals', $line['proposals'], ['count' => $line['proposals']]),
                'figures' => array_values(array_filter([
                    ['key' => 'committed', 'label' => __('common.committed'), 'value' => $line['committed']],
                    $canTransfers ? ['key' => 'received', 'label' => __('finance_tables.received'), 'value' => $line['received'], 'tone' => 'good', 'note' => DonorProposal::percentLabel($line['received'], $line['committed']).'%'] : null,
                    $showsYearColumns ? ['key' => 'due_in_year', 'label' => __('finance_tables.due_in_year', ['year' => $selectedYear]), 'value' => $line['due_in_year']] : null,
                    $showsYearColumns ? ['key' => 'received_in_year', 'label' => __('finance_tables.received_in_year', ['year' => $selectedYear]), 'value' => $line['received_in_year'], 'tone' => 'good'] : null,
                    $canTransfers && ! $yearOnly ? ['key' => 'outstanding', 'label' => __('finance_tables.outstanding'), 'value' => $line['outstanding']] : null,
                    $canTransfers ? ['key' => 'overdue', 'label' => __('finance_tables.overdue'), 'value' => $line['overdue'], 'tone' => $line['overdue'] > 0 ? 'critical' : null] : null,
                ])),
            ])->all();

            $previousProposalId = null;
        @endphp

        @if ($canAgreements)
            <x-funding.totals :lines="$totalLines" />
        @endif

        <x-funding.register :size="$canAgreements ? '2xl' : 'md'" :caption="__('finance_tables.proposals_caption')">
            <x-slot:head>
                <x-funding.register.th sort="proposal" :active="$sortKey" :dir="$sortDirection">{{ __('common.proposal') }}</x-funding.register.th>
                <x-funding.register.th sort="status" :active="$sortKey" :dir="$sortDirection">{{ __('common.status') }}</x-funding.register.th>
                <x-funding.register.th>{{ __('proposals.phase') }}</x-funding.register.th>
                <x-funding.register.th sort="planned_start" :active="$sortKey" :dir="$sortDirection">{{ __('proposals.planned_start') }}</x-funding.register.th>
                <x-funding.register.th sort="planned_end" :active="$sortKey" :dir="$sortDirection">{{ __('proposals.planned_end') }}</x-funding.register.th>
                <x-funding.register.th sort="projects" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('common.projects') }}</x-funding.register.th>
                @if ($canAgreements)
                    <x-funding.register.th>{{ __('common.donors') }}</x-funding.register.th>
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
                    <x-funding.register.th sort="next_due" :active="$sortKey" :dir="$sortDirection">{{ __('finance_tables.next_due') }}</x-funding.register.th>
                @endif
                <th scope="col"><span class="sr-only">{{ __('common.actions') }}</span></th>
            </x-slot:head>

            @foreach ($this->rows as $proposal)
                @php
                    // A proposal funded in two currencies has two lines; its identity is printed on the first only.
                    $isRepeat = $previousProposalId === $proposal->id;
                    $previousProposalId = $proposal->id;
                    $lineCurrency = $canAgreements ? $proposal->money_currency : null;
                    $hasMoney = $lineCurrency !== null;
                    $lineAgreements = $hasMoney ? $proposal->donorProposals->filter(fn ($agreement): bool => $agreement->currency->value === $lineCurrency)->unique('donor_id') : collect();
                    $nextDue = $canTransfers && $hasMoney && $proposal->money_next_due !== null ? \Carbon\CarbonImmutable::parse($proposal->money_next_due) : null;
                    $overdue = $canTransfers && $hasMoney ? (float) $proposal->money_overdue : 0.0;
                @endphp
                <tr wire:key="proposal-{{ $proposal->id }}-{{ $lineCurrency ?? 'none' }}" data-proposal-row data-proposal="{{ $proposal->id }}">
                    <td data-label="{{ __('common.proposal') }}" data-wide>
                        @if (! $isRepeat && in_array($proposal->id, $unreadIds, true))
                            <span class="me-1 inline-block size-2 rounded-full bg-primary align-middle dark:bg-ink" data-unread-dot="proposal-{{ $proposal->id }}" title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                        @endif
                        <span @class(['font-semibold', $mutedText => $isRepeat])>{{ $proposal->name }}</span>
                    </td>
                    <td data-label="{{ __('common.status') }}">
                        @unless ($isRepeat)
                            <x-ui.enum-badge :case="$proposal->status" />
                        @endunless
                    </td>
                    {{-- phase is a read-only accessor over status->phase(), not a column. --}}
                    <td data-label="{{ __('proposals.phase') }}">{{ $isRepeat ? '' : $proposal->phase->label() }}</td>
                    <td class="date" data-label="{{ __('proposals.planned_start') }}">{{ $isRepeat ? '' : $date($proposal->planned_date_begin) }}</td>
                    <td class="date" data-label="{{ __('proposals.planned_end') }}">{{ $isRepeat ? '' : $date($proposal->planned_date_end) }}</td>
                    <td class="num" data-label="{{ __('common.projects') }}">
                        @unless ($isRepeat)
                            <span class="fig">{{ (int) $proposal->projects_count }}</span>
                        @endunless
                    </td>

                    @if ($canAgreements)
                        <td data-label="{{ __('common.donors') }}">
                            @forelse ($lineAgreements as $lineAgreement)
                                <a href="{{ route('donors.show', $lineAgreement->donor) }}" wire:navigate class="block underline-offset-2 hover:underline">{{ $lineAgreement->donor->name }}</a>
                            @empty
                                <span class="{{ $mutedText }}">{{ __('proposals.no_funding_agreements') }}</span>
                            @endforelse
                        </td>
                        <td class="num" data-label="{{ __('finance_tables.agreements') }}"><span class="fig">{{ (int) $proposal->money_agreements }}</span></td>
                        <td data-label="{{ __('common.currency') }}">{{ $lineCurrency ?? '—' }}</td>
                        <td class="num" data-label="{{ __('common.committed') }}" data-cell="committed">
                            @if ($hasMoney)
                                <span class="fig font-semibold">{{ $money($proposal->money_committed) }}</span>
                            @else
                                —
                            @endif
                        </td>
                    @endif

                    @if ($canTransfers)
                        <td class="num" data-label="{{ __('finance_tables.received') }}" data-cell="received">
                            @if ($hasMoney)
                                <span class="fig font-semibold text-funding">{{ $money($proposal->money_received) }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="num" data-label="{{ __('finance_tables.percent_received') }}" data-cell="percent">
                            @if ($hasMoney)
                                <span class="fig">{{ DonorProposal::percentLabel((float) $proposal->money_received, (float) $proposal->money_committed) }}%</span>
                            @else
                                —
                            @endif
                        </td>
                        @if ($showsYearColumns)
                            <td class="num" data-label="{{ __('finance_tables.due_in_year', ['year' => $selectedYear]) }}" data-cell="due_in_year"><span class="fig">{{ $money($proposal->money_due_in_year) }}</span></td>
                            <td class="num" data-label="{{ __('finance_tables.received_in_year', ['year' => $selectedYear]) }}" data-cell="received_in_year"><span class="fig font-semibold text-funding">{{ $money($proposal->money_received_in_year) }}</span></td>
                        @endif
                        @unless ($yearOnly)
                            <td class="num" data-label="{{ __('finance_tables.outstanding') }}" data-cell="outstanding">
                                @if ($hasMoney)
                                    <span class="fig">{{ $money($proposal->money_outstanding) }}</span>
                                @else
                                    —
                                @endif
                            </td>
                        @endunless
                        <td class="num" data-label="{{ __('finance_tables.overdue') }}" data-cell="overdue">
                            @if ($hasMoney)
                                <span @class(['fig', 'font-semibold text-status-critical' => $overdue > 0])>{{ $money($overdue) }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="date" data-label="{{ __('finance_tables.next_due') }}">
                            @if ($nextDue !== null)
                                <span @class(['font-semibold text-status-critical' => $nextDue->lt($today)])>{{ $date($nextDue) }}</span>
                            @else
                                —
                            @endif
                        </td>
                    @endif

                    <td>
                        @unless ($isRepeat)
                            <span class="flex items-center justify-end">
                                <x-ui.button tone="ghost" size="sm" icon="eye" class="size-8!" :href="route('proposals.show', $proposal)" wire:navigate :aria-label="__('common.view_name', ['name' => $proposal->name])" />
                                @can('update', $proposal)
                                    {{-- Status only moves with an audit note, so this opens the same form the dossier did. --}}
                                    <x-ui.button tone="ghost" size="sm" icon="arrows-right-left" class="size-8!" wire:click="openStageModal({{ $proposal->id }})" :aria-label="__('proposals.change_status')" data-test="advance-proposal-{{ $proposal->id }}" />
                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-8!" wire:click="openEditModal({{ $proposal->id }})" :aria-label="__('common.edit_name', ['name' => $proposal->name])" />
                                @endcan
                                @can('delete', $proposal)
                                    @if ($proposal->donor_proposals_count === 0)
                                        <x-ui.button tone="ghost" size="sm" icon="trash" class="size-8!" wire:click="confirmDelete({{ $proposal->id }})" :aria-label="__('common.delete_name', ['name' => $proposal->name])" />
                                    @else
                                        <flux:tooltip :content="__('proposals.funded_proposals_cannot_be_deleted_remove_the_funding_agreem')">
                                            <span class="inline-flex size-8 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true" tabindex="0" aria-label="{{ __('proposals.funded_proposals_cannot_be_deleted_remove_the_funding_agreem') }}">
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
                        <tr wire:key="proposal-total-{{ $line['currency'] }}" data-register-total="{{ $line['currency'] }}">
                            <td colspan="7" data-wide>
                                {{ __('finance_tables.total') }}
                                <span class="sub">{{ trans_choice('finance_tables.count_proposals', $line['proposals'], ['count' => $line['proposals']]) }}</span>
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
                                <td></td>
                            @endif
                            <td></td>
                        </tr>
                    @endforeach
                </x-slot:foot>
            @endif
        </x-funding.register>

        <x-funding.pager :paginator="$this->rows" :per-page="$this->perPage" scroll-to="#proposal-pipeline-heading" />
    @endif

```

The three modals and the closing `</section>` that follow stay exactly as they are.

The template reads `DonorProposal::percentLabel`, `Currency::cases()`, `ProposalStatus::cases()` and `Number::` by their short names; all four are imported in the file's PHP block.

- [ ] **Step 5: Run the new tests**

Run: `.\php.cmd artisan test --compact tests/Feature/ProposalRegisterTest.php`
Expected: PASS, 12 tests.

Common failures and their cause:
- `Column 'status' in where clause is ambiguous` cannot happen (the joined table exposes only `owner_id`, `currency` and `o_*`); if a `name` or `status` reference fails, it is an unqualified column left in `filteredQuery()`.
- `Unknown column 'projects_count' in 'order clause'` — `withCount` must run before `applyRegisterSort`; `selectLine()` guarantees that.
- The Public Relations export has more than 6 columns — headings and rows must be built from the same two flags.
- `Call to a member function phase() on null` — the row select must include `proposals.status`: `phase` is derived from it (`.ai/rules` on `Proposal::$appends`).

- [ ] **Step 6: Point the old visual assertions at the Overview view**

In `tests/Feature/ProposalPipelineTest.php` insert `->set('view', 'overview')` on the line directly after `->test('pages::proposals.index')` in these tests only:

| Test name | The assertion that needs the Overview view |
| --- | --- |
| `the funding flow draws one graph per currency and lists unfunded proposals apart` | `assertSee('Water Network Expansion')` is a node of the flow |
| `each proposal row shows its task progress and overdue work` | `assertSee('1 of 3 tasks done')` is the dossier |

In `tests/Feature/AccessControl/FundraisingPagesMoneyTest.php`, in the test `Public Relations Manager sees proposal names on the proposal directory but no money`, change the `$page` closure to:

```php
    $page = fn (string $role) => Livewire::actingAs(userWithRole($role))->test('pages::proposals.index')->set('view', 'overview');
```

That test asserts `data-funding-position` and `data-connection="funding"`, which are the Overview's. The table's own version of the same guarantee is the two permission tests in `ProposalRegisterTest.php`.

Every other test passes unchanged. In particular `selecting a donor shows only the proposals it funds, and selecting it again clears the filter` still finds "Funded by ECHO" — the chip now sits in the header's filter rows.

- [ ] **Step 7: Run every test that touches this page**

Run: `.\php.cmd artisan test --compact tests/Feature/ProposalPipelineTest.php tests/Feature/ProposalRegisterTest.php tests/Feature/AccessControl/FundraisingPagesMoneyTest.php tests/Feature/AccessControl/BrowserCallableMethodsTest.php tests/Feature/FundingWorkflowTest.php tests/Feature/LocalizationTest.php`
Expected: PASS.

- [ ] **Step 8: Checkpoint**

Run: `.\php.cmd vendor/bin/pint --dirty --format agent`. Do not commit.

---

### Task 3: Pin the three tables to the same figures

`FundingWorkflowTest` is where the shared money definitions are pinned (`.ai/rules/funding-agreements.md`). The three registers now read them from one SQL source; this test makes sure a later change cannot make one of them drift.

**Files:**
- Modify: `tests/Feature/FundingWorkflowTest.php`

- [ ] **Step 1: Write the test**

Append to `tests/Feature/FundingWorkflowTest.php`:

```php
test('the agreements, donors and proposals tables print the same received, outstanding, overdue and share for the same money', function () {
    $this->travelTo('2026-06-15 09:00:00');

    $user = User::factory()->role('Financial')->create();
    $agreement = DonorProposal::factory()->create(['currency' => Currency::Usd, 'budget' => '1000.00']);
    $tranche = fn (array $attributes) => TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'amount_transfered_currency' => Currency::Usd,
        'amount_received_currency' => Currency::Usd,
        'transfer_date' => null,
        'receive_date' => null,
        'ar_status' => ArStatus::Pending,
        ...$attributes,
    ]);

    // 400 sent, 380 landed: the 20 the journey took is not owed again.
    $tranche(['payment_amount' => '400.00', 'net_amount_received' => '380.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2026-05-01', 'transfer_date' => '2026-05-02', 'receive_date' => '2026-05-06']);
    // Received, but nobody recorded what landed: nothing counts as received.
    $tranche(['payment_amount' => '100.00', 'net_amount_received' => null, 'ar_status' => ArStatus::Received, 'scheduled_date' => '2026-05-10', 'receive_date' => '2026-05-12']);
    // Landed in another currency: never set against the budget.
    $tranche(['payment_amount' => '50.00', 'net_amount_received' => '45.00', 'amount_received_currency' => Currency::Eur, 'ar_status' => ArStatus::Received, 'scheduled_date' => '2026-05-15', 'receive_date' => '2026-05-16']);
    $tranche(['payment_amount' => '200.00', 'scheduled_date' => '2026-06-05']);
    $tranche(['payment_amount' => '150.00', 'ar_status' => ArStatus::Failed, 'scheduled_date' => '2026-06-01']);

    $agreementRow = Livewire::actingAs($user)->test('pages::funding-agreements.index')->instance()->rows->sole();
    $donorRow = Livewire::actingAs($user)->test('pages::donors.index')->instance()->rows->getCollection()->sole();
    $proposalRow = Livewire::actingAs($user)->test('pages::proposals.index')->instance()->rows->getCollection()->sole();

    $reading = fn (object $row, string $committed): array => [
        'committed' => (float) $row->{$committed},
        'received' => (float) $row->money_received,
        'outstanding' => (float) $row->money_outstanding,
        'overdue' => (float) $row->money_overdue,
        'share' => DonorProposal::percentLabel((float) $row->money_received, (float) $row->{$committed}),
    ];

    $expected = ['committed' => 1000.0, 'received' => 380.0, 'outstanding' => 450.0, 'overdue' => 200.0, 'share' => '38'];

    expect($reading($agreementRow, 'budget'))->toBe($expected)
        ->and($reading($donorRow, 'money_committed'))->toBe($expected)
        ->and($reading($proposalRow, 'money_committed'))->toBe($expected)
        // Failed and unrecorded money was scheduled, so it is never "not scheduled".
        ->and((float) $agreementRow->money_unscheduled)->toBe(100.0)
        ->and((int) $agreementRow->money_net_missing)->toBe(1);
});
```

Outstanding is 1,000 minus the payment amounts of the three received tranches (400 + 100 + 50): what the donor has still to send, whatever the journey took from what was sent.

- [ ] **Step 2: Run it**

Run: `.\php.cmd artisan test --compact tests/Feature/FundingWorkflowTest.php`
Expected: PASS, 15 tests.

If the three readings disagree, the fault is in `AgreementMoney` (Phase 0), not in a page: fix it there, then re-run the Phase 0 `AgreementMoneyTest` as well.

- [ ] **Step 3: Checkpoint**

Run: `.\php.cmd vendor/bin/pint --dirty --format agent`. Do not commit.

---

### Task 4: Look at it

**Files:** none changed unless a defect is found.

- [ ] **Step 1: Render the page** as in Phase 1, Task 5 for `/proposals`, `/proposals?year=2026`, `/proposals?view=overview`, once as a `Financial` user and once as a `Public Relations Manager`.

- [ ] **Step 2: Check each of these and fix what fails**

| Check | Expected |
| --- | --- |
| A proposal with two currencies | Two lines; the second repeats the name in the muted colour and leaves status, phase, dates, projects and actions empty. |
| An unfunded proposal | One line with dashes in the money columns and a delete button. |
| Change status | The arrows button opens the existing status form with the next status preselected. |
| Public Relations Manager | Proposal, Status, Phase, the two dates, Projects and the actions only; no donor picker, no currency, flag or year pills. |
| Overview view | Funding position, pipeline rail, flow and dossiers as before. |
| Dark mode, Arabic, 390px | As in Phase 1. |

- [ ] **Step 3: Delete the preview file and checkpoint.** Do not commit.
