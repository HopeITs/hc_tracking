# Funding tables — Phase 6: Agreement, donor and proposal detail pages

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The three detail pages behind the Funding tables open on tables too: the agreement page shows a totals line, every tranche with every money column, and the donor reports as a table; the donor page shows its agreements and all its tranches as tables instead of a `<details>` block; the proposal page's Funding tab shows its agreements and tranches as tables. On each, today's visual (dial and journeys, money river and calendar, chronology) stays behind a `Table | Overview` switch.

**Architecture:** Each page gains the small `SwitchesFundingView` trait (Phase 0: one URL-bound `view` property) and reuses the row components built in Phases 1 and 2. A new `x-funding.tranche-table` wraps those rows for a loaded collection of tranches, with totals computed in PHP by `Tranches::totals()`. Agreement rows on the donor and proposal pages come from `DonorProposal::withMoney()`, the same SQL the index table reads. On the agreement and donor pages the old markup moves into partials; on the 3,000-line proposal page the Funding tab's old markup stays in place inside an `@if`.

**Tech Stack:** Laravel 13, Livewire 4 single-file page components, Flux (lite), Tailwind 4, Pest 5, MySQL.

**Depends on:** Phases 0, 1 and 2 done (the tranche and agreement row components).

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
- **Hide money, keep names** (`.ai/rules/pages.md`): a viewer without `funding-agreements.view` gets no agreement, budget or donor name on these pages; a viewer without `transfers.view` gets agreements with no tranche figures and no tranche table. The query is skipped, not only the markup.
- The forms are out of scope: `x-funding.agreement-modal`, `x-funding.transfer-modal`, `x-donors.form-modal` and the donor-report modal keep their markup, their actions and their `data-test` hooks.
- Pages pinned to one record keep their scopes (`agreementProposalScope`, `agreementDonorScope`, `scopedAgreements`, `transferProposalScope`): a table row's edit button calls the same `openAgreementEdit` / `openTransferEdit` the old markup called.
- No existing test is deleted. A test that asserts on the old visual is pointed at the Overview view with `->set('view', 'overview')`.
- Line numbers quoted below are for commit `99c95eb`.

## File Structure

| File | Responsibility |
| --- | --- |
| Create `resources/views/components/funding/tranche-table.blade.php` | A full tranche table for a loaded collection. |
| Create `resources/views/pages/funding-agreements/_show-attention.blade.php` | The "needs attention" list, shared by both views. |
| Create `resources/views/pages/funding-agreements/_show-connections.blade.php` | The connected records, shared by both views. |
| Create `resources/views/pages/funding-agreements/_show-overview.blade.php` | The dial, timeline, journeys and report list, moved unchanged. |
| Modify `resources/views/pages/funding-agreements/⚡show.blade.php` | View switch, table view, tranche export. |
| Create `resources/views/pages/donors/_show-overview.blade.php` | The money river, readings, calendar and agreement cards, moved unchanged. |
| Modify `resources/views/pages/donors/⚡show.blade.php` | View switch, table view, tranche export. |
| Modify `resources/views/pages/proposals/⚡show.blade.php` | View switch and table view inside the Funding tab. |
| Create `tests/Feature/FundingDetailTablesTest.php` | The three table views. |
| Modify `tests/Feature/FundingAgreementTest.php`, `tests/Feature/GanttTimelineTest.php`, `tests/Feature/DonorDirectoryTest.php`, `tests/Feature/AccessControl/FundraisingPagesMoneyTest.php`, `tests/Feature/ProposalDetailActionsTest.php` | Point visual assertions at the Overview view. |

---

### Task 1: The tranche table component

**Files:**
- Create: `resources/views/components/funding/tranche-table.blade.php`
- Modify: `tests/Feature/TrancheComponentsTest.php`

**Interfaces:**
- Consumes: `x-funding.register`, `x-funding.register.th`, `x-funding.tranche-head`, `x-funding.tranche-row`, `x-funding.tranche-foot`; `App\Support\Tranches::totals(iterable $transfers): array<string, array{currency: string, tranches: int, sent: float, fees: float, gross: float, net: float, shortfall: float, overage: float}>` (Phase 0).
- Produces: `<x-funding.tranche-table :transfers="$collection" :caption="string" :editable="bool" :party="null|'donor'|'proposal'" :size="'lg'|'xl'" />`.
  - Each tranche needs its `account` relation loaded; with `party`, also `donorProposal` and that agreement's `donor` or `proposal`.
  - `editable` adds a last column whose button calls `openTransferEdit({id})` and carries `data-test="edit-transfer-{id}"`, shown only to holders of `transfers.update`.

- [x] **Step 1: Write the failing test**

Append to `tests/Feature/TrancheComponentsTest.php`:

```php
test('the tranche table prints every tranche, an identity column when asked, and a totals row per currency', function () {
    $agreement = \App\Models\DonorProposal::factory()->create([
        'proposal_id' => \App\Models\Proposal::factory()->create(['name' => 'Water Response'])->id,
        'project_num' => 'WR-9',
        'currency' => Currency::Usd,
    ]);
    $tranches = collect([
        componentTranche(['donor_proposal_id' => $agreement->id, 'payment_amount' => '400.00', 'amount_transfered_currency' => Currency::Usd, 'fees' => '5.00', 'fees_currency' => Currency::Usd, 'amount_received' => '398.00', 'net_amount_received' => '390.00', 'amount_received_currency' => Currency::Usd, 'ar_status' => ArStatus::Received]),
        componentTranche(['donor_proposal_id' => $agreement->id, 'payment_amount' => '100.00', 'amount_transfered_currency' => Currency::Usd, 'ar_status' => ArStatus::Pending, 'transfer_date' => null]),
    ])->each(fn (TransfersTracking $tranche) => $tranche->setRelation('donorProposal', $agreement->load('proposal')));

    $html = Blade::render('<x-funding.tranche-table :transfers="$tranches" caption="Tranches" party="proposal" />', ['tranches' => $tranches]);

    expect($html)->toContain('data-register')
        ->and($html)->toContain('Water Response')
        ->and($html)->toContain('WR-9')
        ->and(substr_count($html, 'data-transfer="'))->toBe(2)
        ->and($html)->toContain('data-register-total="USD"')
        ->and($html)->toContain('500.00')
        ->and($html)->toContain('−10.00')
        ->and($html)->not->toContain('data-test="edit-transfer-');
});
```

- [x] **Step 2: Run it to verify it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/TrancheComponentsTest.php --filter="tranche table"`
Expected: FAIL — `Unable to locate a class or view for component [funding.tranche-table]`.

- [x] **Step 3: Create the component**

Create `resources/views/components/funding/tranche-table.blade.php`:

```blade
@props(['transfers', 'caption', 'editable' => false, 'party' => null, 'size' => 'lg'])

@php
    /**
     * A full tranche table for a collection already in memory: the detail
     * pages hold a handful of tranches each, so their totals are summed here
     * by the same rules the transfers page sums in SQL.
     *
     * `party` names the identity column a page needs when its tranches belong
     * to several agreements: the proposal on a donor page, the donor on a
     * proposal page.
     */
    $lines = array_values(\App\Support\Tranches::totals($transfers));
    $lead = $party === null ? 0 : 1;
    $partyLabel = $party === 'donor' ? __('funding_agreements.donor') : __('common.proposal');
@endphp

<x-funding.register :size="$size" :caption="$caption" {{ $attributes }}>
    <x-slot:head>
        @if ($party !== null)
            <x-funding.register.th>{{ $partyLabel }}</x-funding.register.th>
        @endif
        <x-funding.tranche-head :actions="$editable" />
    </x-slot:head>

    @foreach ($transfers as $transfer)
        <x-funding.tranche-row :transfer="$transfer">
            @if ($party !== null)
                @php
                    $rowAgreement = $transfer->donorProposal;
                    $rowParty = $party === 'donor' ? $rowAgreement->donor : $rowAgreement->proposal;
                    $partyRoute = $party === 'donor' ? 'donors.show' : 'proposals.show';
                    $partyAbility = $party === 'donor' ? 'donors.view' : 'proposals.view';
                @endphp
                <x-slot:lead>
                    <td data-label="{{ $partyLabel }}" data-wide>
                        @can($partyAbility)
                            <a href="{{ route($partyRoute, $rowParty) }}" wire:navigate class="font-semibold underline-offset-2 hover:underline">{{ $rowParty->name }}</a>
                        @else
                            <span class="font-semibold">{{ $rowParty->name }}</span>
                        @endcan
                        <span class="sub">
                            @can('funding-agreements.view')
                                <a href="{{ route('funding-agreements.show', $rowAgreement) }}" wire:navigate class="underline-offset-2 hover:underline">{{ filled($rowAgreement->project_num) ? $rowAgreement->project_num : __('proposals.view_agreement') }}</a>
                            @else
                                {{ $rowAgreement->project_num ?? __('common.no_donor_reference') }}
                            @endcan
                        </span>
                    </td>
                </x-slot:lead>
            @endif

            @if ($editable)
                <x-slot:actions>
                    <td>
                        @can('transfers.update')
                            <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-8!" wire:click="openTransferEdit({{ $transfer->id }})" :aria-label="__('transfers.edit_transfer')" data-test="edit-transfer-{{ $transfer->id }}" />
                        @endcan
                    </td>
                </x-slot:actions>
            @endif
        </x-funding.tranche-row>
    @endforeach

    <x-slot:foot>
        <x-funding.tranche-foot :lines="$lines" :lead="$lead" :actions="$editable" />
    </x-slot:foot>
</x-funding.register>
```

- [x] **Step 4: Run it to verify it passes**

Run: `.\php.cmd artisan test --compact tests/Feature/TrancheComponentsTest.php`
Expected: PASS, 8 tests.

---

### Task 2: Agreement detail

**Files:**
- Create: `resources/views/pages/funding-agreements/_show-attention.blade.php`
- Create: `resources/views/pages/funding-agreements/_show-connections.blade.php`
- Create: `resources/views/pages/funding-agreements/_show-overview.blade.php`
- Modify: `resources/views/pages/funding-agreements/⚡show.blade.php`
- Create: `tests/Feature/FundingDetailTablesTest.php`
- Modify: `tests/Feature/FundingAgreementTest.php`
- Modify: `tests/Feature/GanttTimelineTest.php`

**Interfaces:**
- Consumes (Phase 0): trait `App\Livewire\Concerns\SwitchesFundingView` (public `$view`, URL-bound, `table` by default); `Tranches::csvHeadings()`, `Tranches::csvRow()`; `CsvExport::download()`; `downloadedCsv()`.
- Produces: public action `exportTranchesCsv(): StreamedResponse`, file name `agreement-tranches-{Y-m-d}.csv`.

- [x] **Step 1: Write the failing tests**

Create `tests/Feature/FundingDetailTablesTest.php`:

```php
<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Enums\DonorReportType;
use App\Models\Account;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\TransfersTracking;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo('2026-06-15 09:00:00');
    $this->user = User::factory()->role('Financial')->create();
});

/**
 * ECHO funds Water Response with 1,000 USD, reference WR-9: 400 received into
 * Operations USD (395 gross, 390 net, 5 in fees), 200 overdue since 5 June,
 * 100 due on 25 June.
 */
function detailAgreement(): DonorProposal
{
    $agreement = DonorProposal::factory()->create([
        'donor_id' => Donor::factory()->create(['name' => 'ECHO'])->id,
        'proposal_id' => Proposal::factory()->create(['name' => 'Water Response'])->id,
        'budget' => '1000.00',
        'currency' => Currency::Usd,
        'project_num' => 'WR-9',
    ]);

    $tranche = fn (array $attributes): TransfersTracking => TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'amount_transfered_currency' => Currency::Usd,
        'amount_received_currency' => Currency::Usd,
        'transfer_date' => null,
        'receive_date' => null,
        'ar_status' => ArStatus::Pending,
        ...$attributes,
    ]);

    $tranche([
        'account_id' => Account::factory()->create(['account_name' => 'Operations USD'])->id,
        'payment_amount' => '400.00',
        'fees' => '5.00',
        'fees_currency' => Currency::Usd,
        'amount_received' => '395.00',
        'net_amount_received' => '390.00',
        'ar_status' => ArStatus::Received,
        'scheduled_date' => '2026-05-15',
        'transfer_date' => '2026-05-16',
        'receive_date' => '2026-05-20',
    ]);
    $tranche(['payment_amount' => '200.00', 'scheduled_date' => '2026-06-05']);
    $tranche(['payment_amount' => '100.00', 'scheduled_date' => '2026-06-25']);

    return $agreement;
}

test('the agreement page opens on its totals and a table of every tranche', function () {
    $agreement = detailAgreement();

    Livewire::actingAs($this->user)
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->assertSet('view', 'table')
        ->assertSeeHtml('data-register')
        ->assertDontSeeHtml('data-dial')
        ->assertSeeHtml('data-total="budget"')
        ->assertSee('1,000.00')
        ->assertSee('700.00')
        ->assertSee('390.00')
        ->assertSee('395.00')
        ->assertSee('−10.00')
        ->assertSee('600.00')
        ->assertSee('Operations USD')
        ->assertSeeHtml('data-transfer-state="late"')
        ->assertSeeHtml('data-attention')
        ->assertSeeHtml('data-connections')
        ->assertSeeHtml('href="'.route('donors.show', $agreement->donor).'"')
        ->assertSeeHtml(e(route('transfers.index', ['agreement' => $agreement->id])));
});

test('the agreement page overview shows the dial and the journeys instead of the tables', function () {
    $agreement = detailAgreement();

    Livewire::actingAs($this->user)
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->set('view', 'overview')
        ->assertSeeHtml('data-dial')
        ->assertSeeHtml('data-trace')
        ->assertSeeHtml('data-attention')
        ->assertSeeHtml('data-connections')
        ->assertDontSeeHtml('data-register');

    Livewire::actingAs($this->user)
        ->withQueryParams(['view' => 'sideways'])
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->assertSet('view', 'table');
});

test('the agreement page lists donor reports as a table with their state and actions', function () {
    $agreement = detailAgreement();
    $overdue = DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'title' => 'Interim financial report', 'type' => DonorReportType::Financial, 'due_on' => '2026-06-10']);
    $sent = DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'title' => 'Inception report', 'due_on' => '2026-03-01', 'submitted_on' => '2026-02-27']);

    Livewire::actingAs($this->user)
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->assertSeeHtml('data-donor-report="'.$overdue->id.'"')
        ->assertSeeHtml('data-donor-report="'.$sent->id.'"')
        ->assertSee('Interim financial report')
        ->assertSee('5 days overdue')
        ->assertSee('Inception report')
        ->assertSeeHtml('data-test="submit-report-'.$overdue->id.'"')
        ->assertDontSeeHtml('data-test="submit-report-'.$sent->id.'"')
        ->assertSeeHtml('data-test="delete-report-'.$sent->id.'"');
});

test('the agreement page exports its tranches', function () {
    $agreement = detailAgreement();

    $page = Livewire::actingAs($this->user)
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->call('exportTranchesCsv')
        ->assertFileDownloaded('agreement-tranches-2026-06-15.csv');

    $csv = downloadedCsv($page);

    expect($csv)->toHaveCount(4)
        ->and($csv[1][0])->toBe('Received')
        ->and($csv[1][1])->toBe('ECHO')
        ->and($csv[1][2])->toBe('Water Response')
        ->and($csv[1][3])->toBe('WR-9')
        ->and($csv[1][4])->toBe('Operations USD')
        ->and($csv[1][9])->toBe('400.00')
        ->and($csv[1][14])->toBe('390.00')
        ->and($csv[1][16])->toBe('-10.00')
        ->and($csv[2][0])->toBe('Overdue');

    Livewire::actingAs(User::factory()->create())
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->call('exportTranchesCsv')
        ->assertForbidden();
});
```

- [x] **Step 2: Run the tests to verify they fail**

Run: `.\php.cmd artisan test --compact tests/Feature/FundingDetailTablesTest.php`
Expected: FAIL — `Property [$view] not found on component`.

- [x] **Step 3: Confirm the block boundaries**

```bash
cd /c/xampp/htdocs/hc-tracking && sed -n '1003p;1106p;1118p;1120p;1363p;1366p;1521p;1522p' "resources/views/pages/funding-agreements/⚡show.blade.php"
```

Expected output, exactly:

```
    {{-- The promise: the dial on the start side, its readings on the end side. --}}
            @if ($this->attention !== [])
            @endif
    </div>
        <aside class="min-w-0" aria-labelledby="connections-heading" data-connections>
            <ol class="connection-thread mt-5 flex flex-col">
        </aside>
    </div>
```

- [x] **Step 4: Build the three partials from the current file**

Run all three before editing the page, so the line numbers stay valid.

```bash
cd /c/xampp/htdocs/hc-tracking && page="resources/views/pages/funding-agreements/⚡show.blade.php" && dir="resources/views/pages/funding-agreements"

{
  printf '%s\n' '{{-- What needs chasing on this agreement, most urgent first. Shown in both views of ⚡show.blade.php. --}}'
  sed -n '1106,1118p' "$page"
} > "$dir/_show-attention.blade.php"

{
  printf '%s\n' '{{--' \
    '    Every record this money is tied to, each one link away. Shown in both' \
    '    views of ⚡show.blade.php: a single thread beside the journeys in the' \
    '    Overview view, and in columns under the tables ($connectionsAsColumns).' \
    '--}}'
  sed -n '1363,1521p' "$page"
} > "$dir/_show-connections.blade.php"

{
  printf '%s\n' '{{--' \
    '    The Overview view of the agreement page: the dial and its readings, the' \
    '    timeline, each tranche as a journey and the donor reports as a list.' \
    '    Included by ⚡show.blade.php, which defines $money, $term, $journeys,' \
    '    $arcStroke, $dotClass, $amount, $currency, $agreement, $donor, $proposal,' \
    '    $coFunders and $donorAgreements.' \
    '--}}'
  sed -n '1003,1105p' "$page"
  printf '%s\n' "            @include('pages.funding-agreements._show-attention')"
  sed -n '1119,1362p' "$page"
  printf '%s\n' "        @include('pages.funding-agreements._show-connections')"
  sed -n '1522p' "$page"
} > "$dir/_show-overview.blade.php"
```

- [x] **Step 5: Let the connections lay out in columns**

In `resources/views/pages/funding-agreements/_show-connections.blade.php` replace the line:

```blade
            <ol class="connection-thread mt-5 flex flex-col">
```

with:

```blade
            <ol @class(['mt-5', 'connection-thread flex flex-col' => ! ($connectionsAsColumns ?? false), 'grid gap-x-12 @4xl/detail:grid-cols-2 @7xl/detail:grid-cols-3' => $connectionsAsColumns ?? false])>
```

Verify none of the three partials needs an import:

```bash
cd /c/xampp/htdocs/hc-tracking && grep -n -E "(^|[^\\\\A-Za-z])(ArStatus|DonorReportType|CarbonImmutable|TransfersTracking|DonorProposal|Collection|Number)::" resources/views/pages/funding-agreements/_show-*.blade.php
```

Expected: no output (the one date call is already written `\Carbon\CarbonImmutable::today()`).

- [x] **Step 6: Add the trait and the export to the class**

In the PHP block of `resources/views/pages/funding-agreements/⚡show.blade.php`:

Add to the `use` list at the top (keep it alphabetical):

```php
use App\Livewire\Concerns\SwitchesFundingView;
use App\Support\CsvExport;
use App\Support\Tranches;
use Symfony\Component\HttpFoundation\StreamedResponse;
```

Replace:

```php
    use BuildsPortfolioGantt;
    use ManagesAgreementForm;
```

with:

```php
    use BuildsPortfolioGantt;
    use ManagesAgreementForm;
    use SwitchesFundingView;
```

Directly after the `reports()` method add:

```php
    /**
     * Every tranche of this agreement as a CSV file, in schedule order.
     */
    public function exportTranchesCsv(): StreamedResponse
    {
        Gate::authorize('funding-agreements.view');

        $agreement = $this->agreement;

        $rows = $this->transfers->map(fn (TransfersTracking $transfer): array => Tranches::csvRow(
            $transfer,
            $agreement->donor->name,
            $agreement->proposal->name,
            $agreement->project_num,
            $transfer->account?->account_name,
        ));

        return CsvExport::download('agreement-tranches-'.CarbonImmutable::today()->toDateString().'.csv', Tranches::csvHeadings(), $rows);
    }
```

- [x] **Step 7: Replace the body of the template with the two views**

In the template of `resources/views/pages/funding-agreements/⚡show.blade.php`:

**7a.** In the header's `<x-slot:actions>`, replace:

```blade
            <div class="flex flex-wrap items-center gap-2 @3xl/detail:shrink-0 @3xl/detail:flex-nowrap">
                <x-ui.enum-badge :case="$proposal->status" />
```

with:

```blade
            <div class="flex flex-wrap items-center gap-2 @3xl/detail:shrink-0 @3xl/detail:flex-nowrap">
                <x-ui.enum-badge :case="$proposal->status" />
                <x-ui.view-toggle
                    :label="__('finance_tables.view_label')"
                    wire-model="view"
                    :active="$this->view"
                    :options="[
                        ['key' => 'table', 'label' => __('finance_tables.view_table'), 'icon' => 'table-cells'],
                        ['key' => 'overview', 'label' => __('finance_tables.view_overview'), 'icon' => 'chart-bar'],
                    ]"
                />
```

**7b.** Delete lines 1003–1522 (from `{{-- The promise: the dial on the start side, its readings on the end side. --}}` through the `</div>` that closes the tranches-and-connections grid) and put this in their place:

```blade
    @if ($this->view === 'overview')
        @include('pages.funding-agreements._show-overview')
    @else
        @php
            $totalLines = [[
                'currency' => $currency,
                'caption' => trans_choice('common.count_tranche', count($journeys), ['count' => count($journeys)]),
                'figures' => array_values(array_filter([
                    ['key' => 'budget', 'label' => __('finance_tables.budget'), 'value' => $money['budget']],
                    // Every tranche sent in this currency, whatever became of it: failed money was scheduled too.
                    ['key' => 'scheduled', 'label' => __('finance_tables.scheduled'), 'value' => $money['scheduled'] + $money['failed']],
                    ['key' => 'received', 'label' => __('finance_tables.received'), 'value' => $money['landed'], 'tone' => 'good', 'note' => $money['percentLabel'].'%'],
                    ['key' => 'lost', 'label' => __('funding_agreements.fees_and_exchange'), 'value' => $money['lost'], 'prefix' => $money['lost'] > 0 ? '−' : ''],
                    ['key' => 'in_transit', 'label' => __('finance_tables.in_transit'), 'value' => $money['transit'], 'tone' => $money['transit'] > 0 ? 'warn' : null],
                    ['key' => 'overdue', 'label' => __('finance_tables.overdue'), 'value' => $money['late'], 'tone' => $money['late'] > 0 ? 'critical' : null],
                    $money['failed'] > 0 ? ['key' => 'failed', 'label' => __('finance_tables.failed'), 'value' => $money['failed'], 'tone' => 'critical'] : null,
                    ['key' => 'unscheduled', 'label' => __('finance_tables.not_scheduled'), 'value' => $money['unscheduled'], 'tone' => 'muted'],
                    ['key' => 'outstanding', 'label' => __('finance_tables.outstanding'), 'value' => $money['stillToCome']],
                ])),
                'notes' => array_values(array_filter([
                    ...collect($money['foreignLandings'])->map(fn (float $foreignTotal, string $foreignCode): string => __('funding_agreements.also_landed_in', ['amount' => $amount($foreignTotal), 'currency' => $foreignCode]))->values()->all(),
                    $money['foreignCount'] > 0 ? trans_choice('funding_agreements.count_tranches_sent_in_another_currency', $money['foreignCount'], ['count' => $money['foreignCount']]) : null,
                ])),
            ]];
        @endphp

        <div class="pt-8">
            <x-funding.totals :lines="$totalLines" />
        </div>

        @include('pages.funding-agreements._show-attention')

        {{-- Every tranche, with every figure of its journey in its own column. --}}
        <section class="mt-10 min-w-0" aria-labelledby="tranches-heading" data-tranches>
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                <h2 id="tranches-heading" class="text-xl font-bold tracking-tight {{ $inkText }}">
                    {{ __('funding_agreements.tranches') }}
                    <span class="ms-1 text-base font-semibold tabular-nums {{ $mutedText }}">{{ count($journeys) }}</span>
                </h2>
                @if ($journeys !== [])
                    <div class="flex flex-wrap items-center gap-3">
                        <a href="{{ route('transfers.index', ['agreement' => $agreement->id]) }}" wire:navigate class="text-xs font-semibold underline-offset-4 hover:underline {{ $inkText }}" data-transfers-ledger-link>{{ __('funding_agreements.open_in_transfers_ledger') }}</a>
                        <x-ui.button size="sm" icon="arrow-down-tray" class="min-h-10 touch-manipulation" wire:click="exportTranchesCsv" wire:loading.attr="disabled" wire:target="exportTranchesCsv" data-test="export-csv">{{ __('finance_tables.export_csv') }}</x-ui.button>
                    </div>
                @endif
            </div>

            @if ($journeys === [])
                <div class="mt-6">
                    <x-ui.empty-state class="rtl:[&_svg]:-scale-x-100" icon="arrow-right-circle" :heading="__('funding_agreements.no_tranches_scheduled')" :body="__('funding_agreements.payments_scheduled_under_this_agreement_will_appear_here')" />
                </div>
            @else
                <x-funding.tranche-table class="mt-4" :transfers="$this->transfers" :caption="__('funding_agreements.tranches_of_name', ['name' => $donor->name.' · '.$proposal->name])" editable />
            @endif

            @if ($money['unscheduled'] > 0.0)
                <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 py-4" data-unscheduled-line>
                    <p class="flex items-center gap-2 text-sm {{ $mutedText }}">
                        <span class="size-2 rounded-full border-2 border-dashed border-secondary-light" aria-hidden="true"></span>
                        {{ __('funding_agreements.not_scheduled_yet') }}
                        <span class="font-bold tabular-nums {{ $inkText }}">{{ $amount($money['unscheduled']) }} {{ $currency }}</span>
                    </p>
                    @can('transfers.create')
                    <button type="button" wire:click="openTransferCreate({{ $agreement->id }})" class="min-h-9 touch-manipulation text-xs font-semibold underline underline-offset-4 {{ $inkText }}">{{ __('funding_agreements.schedule_the_rest') }}</button>
                    @endcan
                </div>
            @endif
        </section>

        {{-- What the donor is owed back: every report, by when, and whether it went out. --}}
        <section class="mt-10 min-w-0 border-t pt-8 {{ $hairline }}" aria-labelledby="reports-heading" data-donor-reports>
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
                <h2 id="reports-heading" class="text-xl font-bold tracking-tight {{ $inkText }}">
                    {{ __('funding_agreements.donor_reports') }}
                    <span class="ms-1 text-base font-semibold tabular-nums {{ $mutedText }}">{{ $this->reports->count() }}</span>
                </h2>
                @can('funding-agreements.update')
                <x-ui.button size="sm" icon="plus" class="min-h-10 touch-manipulation" wire:click="openReportCreate" data-test="add-donor-report">{{ __('funding_agreements.add_report') }}</x-ui.button>
                @endcan
            </div>

            @if ($this->reports->isEmpty())
                <p class="mt-4 text-sm {{ $mutedText }}">{{ __('funding_agreements.no_donor_reports_yet') }}</p>
            @else
                <x-funding.register class="mt-4" size="sm" :caption="__('funding_agreements.donor_reports')">
                    <x-slot:head>
                        <x-funding.register.th>{{ __('funding_agreements.report_title') }}</x-funding.register.th>
                        <x-funding.register.th>{{ __('common.type') }}</x-funding.register.th>
                        <x-funding.register.th>{{ __('funding_agreements.due') }}</x-funding.register.th>
                        <x-funding.register.th>{{ __('funding_agreements.submitted') }}</x-funding.register.th>
                        <x-funding.register.th>{{ __('common.state') }}</x-funding.register.th>
                        <th scope="col"><span class="sr-only">{{ __('common.actions') }}</span></th>
                    </x-slot:head>

                    @foreach ($this->reports as $report)
                        @php
                            $reportDays = (int) \Carbon\CarbonImmutable::today()->diffInDays($report->due_on, false);
                            $reportOverdue = $report->isOverdue();
                        @endphp
                        <tr wire:key="donor-report-{{ $report->id }}" data-donor-report="{{ $report->id }}">
                            <td data-label="{{ __('funding_agreements.report_title') }}" data-wide><span class="font-semibold">{{ $report->title }}</span></td>
                            <td data-label="{{ __('common.type') }}">{{ $report->type->label() }}</td>
                            <td class="date" data-label="{{ __('funding_agreements.due') }}">{{ $report->due_on->translatedFormat(__('formats.date')) }}</td>
                            <td class="date" data-label="{{ __('funding_agreements.submitted') }}">{{ $report->submitted_on?->translatedFormat(__('formats.date')) ?? '—' }}</td>
                            <td data-label="{{ __('common.state') }}" data-report-status>
                                <span @class([
                                    'text-xs font-semibold',
                                    'text-funding' => $report->isSubmitted(),
                                    'text-status-critical' => $reportOverdue,
                                    $mutedText => ! $report->isSubmitted() && ! $reportOverdue,
                                ])>
                                    @if ($report->isSubmitted())
                                        {{ __('funding_agreements.submitted') }}
                                    @elseif ($reportOverdue)
                                        {{ trans_choice('funding_agreements.count_days_overdue', -$reportDays, ['count' => -$reportDays]) }}
                                    @else
                                        {{ trans_choice('funding_agreements.due_in_days', $reportDays, ['count' => $reportDays]) }}
                                    @endif
                                </span>
                            </td>
                            <td>
                                @can('funding-agreements.update')
                                    <span class="flex items-center justify-end gap-1">
                                        @unless ($report->isSubmitted())
                                            <x-ui.button size="sm" icon="check" class="min-h-8 touch-manipulation" wire:click="markReportSubmitted({{ $report->id }})" data-test="submit-report-{{ $report->id }}">{{ __('funding_agreements.mark_submitted') }}</x-ui.button>
                                        @endunless
                                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-8!" wire:click="openReportEdit({{ $report->id }})" :aria-label="__('common.edit_name', ['name' => $report->title])" />
                                        <x-ui.button tone="ghost" size="sm" icon="trash" class="size-8!" wire:click="confirmReportDelete({{ $report->id }})" :aria-label="__('funding_agreements.delete_report_name', ['name' => $report->title])" data-test="delete-report-{{ $report->id }}" />
                                    </span>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </x-funding.register>
            @endif
        </section>

        <div class="mt-10 border-t pt-8 {{ $hairline }}">
            @include('pages.funding-agreements._show-connections', ['connectionsAsColumns' => true])
        </div>
    @endif
```

The activity section, the modals and the closing `</section>` that follow stay exactly as they are.

Check that `funding_agreements.tranches_of_name` exists (the agreements index already uses it):

```bash
cd /c/xampp/htdocs/hc-tracking && grep -n "'tranches_of_name'" lang/en/funding_agreements.php lang/ar/funding_agreements.php
```

Expected: one line in each file.

- [x] **Step 8: Run the new tests**

Run: `.\php.cmd artisan test --compact tests/Feature/FundingDetailTablesTest.php`
Expected: PASS, 4 tests.

Expected figures, for debugging: scheduled 700 (400 + 200 + 100), received 390, fees and exchange 10, outstanding 600 (1,000 − the 400 received), and a difference of −10.00 on the received row.

- [x] **Step 9: Point three old visual assertions at the Overview view**

In `tests/Feature/FundingAgreementTest.php`:

1. Test `a sliver of money landed reads as under one percent, not zero` — its last chain becomes:

```php
    Livewire::actingAs($user)
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->set('view', 'overview')
        ->assertSeeHtml('data-test="dial-percent">&lt;1<');
```

2. Test `agreement detail shows the gross amount received beside what landed` — its chain becomes:

```php
    Livewire::actingAs($user)
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->set('view', 'overview')
        ->assertSeeText('gross 990.00')
        ->assertSeeText('985.00 USD landed');
```

In `tests/Feature/GanttTimelineTest.php`, in the test that builds `pages::funding-agreements.show` (around line 194), insert `->set('view', 'overview')` directly after `->test('pages::funding-agreements.show', ['donorProposal' => $agreement])`: the timeline is part of the Overview view.

Every other agreement-page test passes unchanged. The reasons, so a failure can be traced:
- `agreement detail counts only received tranches as landed …` reads "10 days late" from the attention list and the other-currency sentence from the totals notes, both shown in the table view.
- `a tranche the donor sent that has not landed past its date is in transit on both pages …` reads its sentence from the attention list.
- `reports owed to the donor can be added, submitted and deleted on the agreement` reads the title and "5 days overdue" from the reports table.
- `agreement detail links the donor, proposal, co-funders …` reads its links from the connections, shown in both views.

- [x] **Step 10: Run every test that touches this page**

Run: `.\php.cmd artisan test --compact tests/Feature/FundingAgreementTest.php tests/Feature/FundingDetailTablesTest.php tests/Feature/GanttTimelineTest.php tests/Feature/Notifications/ActivityTimelineTest.php tests/Feature/TrancheComponentsTest.php tests/Feature/LocalizationTest.php`
Expected: PASS.

- [ ] **Step 11: Checkpoint**

Run: `.\php.cmd vendor/bin/pint --dirty --format agent`. Do not commit.

---

### Task 3: Donor detail

**Files:**
- Create: `resources/views/pages/donors/_show-overview.blade.php`
- Modify: `resources/views/pages/donors/⚡show.blade.php`
- Modify: `tests/Feature/FundingDetailTablesTest.php`
- Modify: `tests/Feature/DonorDirectoryTest.php`
- Modify: `tests/Feature/AccessControl/FundraisingPagesMoneyTest.php`

**Interfaces:**
- Consumes: `SwitchesFundingView`; `DonorProposal::withMoney()`; `AgreementMoney::columns()`, `AgreementMoney::totals(iterable $agreements): list<array>`; `x-funding.agreement-head|row|foot`; `x-funding.tranche-table`; `Tranches::csvHeadings()`, `::csvRow()`; `CsvExport`.
- Produces: computed `agreementRows`, `allTranches`; action `exportTranchesCsv()`, file name `donor-tranches-{Y-m-d}.csv`.

- [x] **Step 1: Write the failing tests**

Append to `tests/Feature/FundingDetailTablesTest.php`:

```php
test('the donor page opens on its agreements and all its tranches as tables', function () {
    $agreement = detailAgreement();
    $second = DonorProposal::factory()->create([
        'donor_id' => $agreement->donor_id,
        'proposal_id' => Proposal::factory()->create(['name' => 'Winter Appeal'])->id,
        'budget' => '500.00',
        'currency' => Currency::Eur,
    ]);

    $page = Livewire::actingAs($this->user)
        ->test('pages::donors.show', ['donor' => $agreement->donor])
        ->assertSet('view', 'table')
        ->assertDontSeeHtml('data-money-river')
        ->assertSeeHtml('data-agreements')
        ->assertSeeHtml('data-agreement-row="'.$agreement->id.'"')
        ->assertSeeHtml('data-agreement-row="'.$second->id.'"')
        ->assertSee('Water Response')
        ->assertSee('Winter Appeal')
        ->assertSee('1,000.00')
        ->assertSee('390.00')
        ->assertSee('600.00')
        ->assertSee('500.00')
        ->assertDontSee('1,500.00')
        ->assertSeeHtml('data-all-tranches')
        ->assertSeeHtml('data-transfer-state="late"')
        ->assertSee('Operations USD')
        ->assertSeeHtml('data-overdue-tranche=')
        ->assertDontSeeHtml('<details');

    expect($page->instance()->agreementRows)->toHaveCount(2)
        ->and((float) $page->instance()->agreementRows->firstWhere('id', $agreement->id)->money_received)->toBe(390.0)
        ->and($page->instance()->allTranches)->toHaveCount(3);
});

test('the donor page overview shows the money river and the calendar instead of the tables', function () {
    $agreement = detailAgreement();

    Livewire::actingAs($this->user)
        ->test('pages::donors.show', ['donor' => $agreement->donor])
        ->set('view', 'overview')
        ->assertSeeHtml('data-money-river')
        ->assertSeeHtml('data-money-calendar')
        ->assertSeeHtml('data-overdue-tranche=')
        ->assertDontSeeHtml('data-all-tranches');
});

test('the donor page shows budgets without tranches to someone who may not see transfers', function () {
    $agreement = detailAgreement();
    $viewer = userWithRole('Public Relations Manager')->givePermissionTo('funding-agreements.view');

    $page = Livewire::actingAs($viewer)->test('pages::donors.show', ['donor' => $agreement->donor]);

    $page->assertSee('1,000.00')
        ->assertDontSee('390.00')
        ->assertDontSeeHtml('data-all-tranches')
        ->assertDontSeeHtml('data-cell="received"');

    expect(array_key_exists('money_received', $page->instance()->agreementRows->first()->getAttributes()))->toBeFalse()
        ->and($page->instance()->allTranches)->toBeEmpty();

    Livewire::actingAs($viewer)
        ->test('pages::donors.show', ['donor' => $agreement->donor])
        ->call('exportTranchesCsv')
        ->assertForbidden();
});

test('the donor page exports every tranche of the donor', function () {
    $agreement = detailAgreement();

    $page = Livewire::actingAs($this->user)
        ->test('pages::donors.show', ['donor' => $agreement->donor])
        ->call('exportTranchesCsv')
        ->assertFileDownloaded('donor-tranches-2026-06-15.csv');

    $csv = downloadedCsv($page);

    expect($csv)->toHaveCount(4)
        ->and($csv[1][1])->toBe('ECHO')
        ->and($csv[1][2])->toBe('Water Response')
        ->and($csv[1][3])->toBe('WR-9');
});
```

- [x] **Step 2: Run them to verify they fail**

Run: `.\php.cmd artisan test --compact tests/Feature/FundingDetailTablesTest.php --filter="donor page"`
Expected: FAIL — `Property [$view] not found on component`.

- [x] **Step 3: Confirm the block boundaries and build the partial**

```bash
cd /c/xampp/htdocs/hc-tracking && sed -n '499p;516p;518p;854p;855p' "resources/views/pages/donors/⚡show.blade.php"
```

Expected output, exactly:

```
        {{-- Late money first: every overdue tranche, each opening the agreement that holds it. --}}
        @endif
        {{-- The money river: one per currency, committed split into what landed, what is coming, what is late and what nobody has scheduled. --}}
        </section>
    @endif
```

```bash
cd /c/xampp/htdocs/hc-tracking && {
  printf '%s\n' '{{--' \
    '    The Overview view of the donor page: the money river per currency, the' \
    '    three readings, the money calendar and one card per agreement. Included' \
    '    by ⚡show.blade.php, which defines $amount, $formatDate, $percentOf,' \
    '    $stateLabels, $donor, $mutedText, $inkText and $hairline.' \
    '--}}'
  sed -n '518,854p' "resources/views/pages/donors/⚡show.blade.php"
} > resources/views/pages/donors/_show-overview.blade.php
```

In `resources/views/pages/donors/_show-overview.blade.php` replace the one occurrence of `ArStatus::Failed->label()` with `\App\Enums\ArStatus::Failed->label()`.

Verify:

```bash
cd /c/xampp/htdocs/hc-tracking && grep -n -E "(^|[^\\\\A-Za-z])(ArStatus|TransfersTracking|Collection|Number|CarbonImmutable)::" resources/views/pages/donors/_show-overview.blade.php
```

Expected: no output.

- [x] **Step 4: Extend the class**

In the PHP block of `resources/views/pages/donors/⚡show.blade.php`:

Add to the `use` list at the top (keep it alphabetical):

```php
use App\Livewire\Concerns\SwitchesFundingView;
use App\Support\CsvExport;
use App\Support\Tranches;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;
```

Replace:

```php
    use ManagesAgreementForm;
    use ManagesDonorForm;
```

with:

```php
    use ManagesAgreementForm;
    use ManagesDonorForm;
    use SwitchesFundingView;
```

In `agreements()`, add the slug to two selects so a row's links cost no extra query. Replace:

```php
            ->select(['id', 'donor_id', 'proposal_id', 'budget', 'currency', 'project_num'])
            ->where('donor_id', $this->donorId)
            ->with([
                'proposal:id,name,status',
```

with:

```php
            ->select(['id', 'slug', 'donor_id', 'proposal_id', 'budget', 'currency', 'project_num'])
            ->where('donor_id', $this->donorId)
            ->with([
                'proposal:id,slug,name,status',
```

Directly after the `agreements()` method add:

```php
    /**
     * The same agreements as table rows: each with its money read by the one
     * SQL definition every agreement table shares, and its donor reports
     * counted. Empty for a viewer without funding-agreements.view; without
     * transfers.view the money is not queried at all and the rows carry the
     * budget only.
     *
     * @return Collection<int, DonorProposal>
     */
    #[Computed]
    public function agreementRows(): Collection
    {
        if (! auth()->user()?->can('funding-agreements.view')) {
            return new Collection;
        }

        $today = CarbonImmutable::today()->toDateString();

        return DonorProposal::query()
            ->select('donor_proposal.*')
            ->where('donor_proposal.donor_id', $this->donorId)
            ->when(auth()->user()->can('transfers.view'), fn (Builder $query) => $query->withMoney())
            ->with(['proposal:id,slug,name,status'])
            ->withCount(['reports as overdue_reports_count' => fn (Builder $query) => $query
                ->whereNull('submitted_on')
                ->whereDate('due_on', '<', $today)])
            ->withMin(['reports as next_report_due' => fn (Builder $query) => $query
                ->whereNull('submitted_on')
                ->whereDate('due_on', '>=', $today)], 'due_on')
            ->orderBy('donor_proposal.id')
            ->get();
    }

    /**
     * Every tranche of every agreement, in schedule order with undated ones
     * last, each knowing the agreement it belongs to. Empty without
     * transfers.view, because agreements() loads no tranche then.
     *
     * @return Collection<int, TransfersTracking>
     */
    #[Computed]
    public function allTranches(): Collection
    {
        return new Collection($this->agreements
            ->flatMap(fn (DonorProposal $agreement) => $agreement->transfers
                ->each(fn (TransfersTracking $transfer) => $transfer->setRelation('donorProposal', $agreement)))
            ->sortBy([
                fn (TransfersTracking $a, TransfersTracking $b): int => ($a->scheduled_date === null) <=> ($b->scheduled_date === null),
                fn (TransfersTracking $a, TransfersTracking $b): int => $a->scheduled_date <=> $b->scheduled_date,
                fn (TransfersTracking $a, TransfersTracking $b): int => $a->id <=> $b->id,
            ])
            ->values()
            ->all());
    }

    /**
     * Every tranche of this donor as a CSV file.
     */
    public function exportTranchesCsv(): StreamedResponse
    {
        Gate::authorize('funding-agreements.view');
        Gate::authorize('transfers.view');

        $donor = $this->donor;

        $rows = $this->allTranches->map(fn (TransfersTracking $transfer): array => Tranches::csvRow(
            $transfer,
            $donor->name,
            $transfer->donorProposal->proposal->name,
            $transfer->donorProposal->project_num,
            $transfer->account?->account_name,
        ));

        return CsvExport::download('donor-tranches-'.CarbonImmutable::today()->toDateString().'.csv', Tranches::csvHeadings(), $rows);
    }
```

In `agreementSaved()`, add the two new readings to the `unset(...)` list so an agreement recorded from this page appears in the table at once. Replace:

```php
        unset($this->donor, $this->agreements, $this->moneyByCurrency,
```

with:

```php
        unset($this->donor, $this->agreements, $this->agreementRows, $this->allTranches, $this->moneyByCurrency,
```

- [x] **Step 5: Add the switch and the table view to the template**

**5a.** In the header's `<x-slot:actions>`, directly before the `@can('donors.update')` line, insert:

```blade
            @can('funding-agreements.view')
                <x-ui.view-toggle
                    class="@3xl/detail:order-last"
                    :label="__('finance_tables.view_label')"
                    wire-model="view"
                    :active="$this->view"
                    :options="[
                        ['key' => 'table', 'label' => __('finance_tables.view_table'), 'icon' => 'table-cells'],
                        ['key' => 'overview', 'label' => __('finance_tables.view_overview'), 'icon' => 'chart-bar'],
                    ]"
                />
            @endcan
```

**5b.** Delete lines 518–854 (from `{{-- The money river: … --}}` through the `</section>` that closes `data-agreements`) and put this in their place. The overdue list above it (lines 499–516) stays where it is, so it shows in both views.

```blade
        @if ($this->view === 'overview')
            @include('pages.donors._show-overview')
        @else
            @php
                $canTransfers = auth()->user()->can('transfers.view');
                $agreementRows = $this->agreementRows;
                $columns = \App\Support\AgreementMoney::columns(withTransfers: $canTransfers);
                $footLines = \App\Support\AgreementMoney::totals($agreementRows);

                $totalLines = collect($this->moneyByCurrency)->map(fn (array $line, string $lineCurrency): array => [
                    'currency' => $lineCurrency,
                    'caption' => null,
                    'figures' => array_values(array_filter([
                        ['key' => 'committed', 'label' => __('common.committed'), 'value' => $line['committed']],
                        $canTransfers ? ['key' => 'received', 'label' => __('finance_tables.received'), 'value' => $line['received'], 'tone' => 'good', 'note' => DonorProposal::percentLabel($line['received'], $line['committed']).'%'] : null,
                        $canTransfers ? ['key' => 'due', 'label' => __('donors.due_ahead'), 'value' => $line['due'], 'tone' => $line['due'] > 0 ? 'warn' : null] : null,
                        $canTransfers ? ['key' => 'overdue', 'label' => __('finance_tables.overdue'), 'value' => $line['overdue'], 'tone' => $line['overdue'] > 0 ? 'critical' : null] : null,
                        $canTransfers && $line['failed'] > 0 ? ['key' => 'failed', 'label' => __('finance_tables.failed'), 'value' => $line['failed'], 'tone' => 'critical'] : null,
                        $canTransfers ? ['key' => 'unscheduled', 'label' => __('finance_tables.not_scheduled'), 'value' => $line['unscheduled'], 'tone' => 'muted'] : null,
                    ])),
                    'notes' => [],
                ])->values()->all();

                $owedReports = $this->agreements->flatMap(fn ($owingAgreement) => $owingAgreement->reports->map(fn ($owedReport): array => ['agreement' => $owingAgreement, 'report' => $owedReport]))->sortBy(fn (array $owed) => $owed['report']->due_on)->values();
            @endphp

            <div class="mt-9">
                <x-funding.totals :lines="$totalLines" />
                @foreach ($this->receivedElsewhere as $elsewhereCurrency => $elsewhereTotal)
                    <p class="mt-2 text-xs {{ $mutedText }}" wire:key="elsewhere-{{ $elsewhereCurrency }}">{{ __('donors.received_in_other_currency', ['currency' => $elsewhereCurrency, 'amount' => $amount($elsewhereTotal)]) }}</p>
                @endforeach
            </div>

            {{-- Every agreement this donor holds, one row each. --}}
            <section class="mt-10 min-w-0" aria-labelledby="agreements-heading" data-agreements>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="agreements-heading" class="text-lg font-bold {{ $inkText }}">
                        {{ __('common.funding_agreements_2') }}
                        <span class="ms-1 text-base font-semibold tabular-nums {{ $mutedText }}">{{ $agreementRows->count() }}</span>
                    </h2>
                    @can('funding-agreements.create')
                    <x-ui.button size="sm" icon="plus" class="min-h-10 touch-manipulation" wire:click="recordAgreementForDonor" data-test="record-agreement-for-donor">{{ __('funding_agreements.record_agreement') }}</x-ui.button>
                    @endcan
                </div>

                <x-funding.register class="mt-4" :size="$canTransfers ? 'xl' : 'sm'" :caption="__('donors.agreements_and_what_they_fund')">
                    <x-slot:head>
                        <x-funding.register.th>{{ __('common.proposal') }}</x-funding.register.th>
                        <x-funding.agreement-head :columns="$columns" />
                    </x-slot:head>

                    @foreach ($agreementRows as $agreementRow)
                        <x-funding.agreement-row :agreement="$agreementRow" :columns="$columns">
                            <x-slot:lead>
                                <td data-label="{{ __('common.proposal') }}" data-wide>
                                    @can('proposals.view')
                                        <a href="{{ route('proposals.show', $agreementRow->proposal) }}" wire:navigate class="font-semibold underline-offset-2 hover:underline">{{ $agreementRow->proposal->name }}</a>
                                    @else
                                        <span class="font-semibold">{{ $agreementRow->proposal->name }}</span>
                                    @endcan
                                    <span class="sub">{{ $agreementRow->proposal->status->label() }} · {{ $agreementRow->project_num ?? __('common.no_donor_reference') }}</span>
                                </td>
                            </x-slot:lead>

                            <x-slot:actions>
                                <td>
                                    <span class="flex items-center justify-end">
                                        <x-ui.button tone="ghost" size="sm" icon="eye" class="size-8!" :href="route('funding-agreements.show', $agreementRow)" wire:navigate :aria-label="__('donors.view_agreement_for', ['name' => $agreementRow->proposal->name])" />
                                        @can('funding-agreements.update')
                                            <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-8!" wire:click="openAgreementEdit({{ $agreementRow->id }})" :aria-label="__('common.edit_name', ['name' => $agreementRow->proposal->name])" data-test="edit-agreement-{{ $agreementRow->id }}" />
                                        @endcan
                                    </span>
                                </td>
                            </x-slot:actions>
                        </x-funding.agreement-row>
                    @endforeach

                    <x-slot:foot>
                        <x-funding.agreement-foot :lines="$footLines" :columns="$columns" :lead="1" />
                    </x-slot:foot>
                </x-funding.register>
            </section>

            {{-- Every tranche, in full. Nothing to open. --}}
            @if ($canTransfers)
                <section class="mt-10 min-w-0 border-t pt-8 {{ $hairline }}" aria-labelledby="all-tranches-heading" data-all-tranches>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 id="all-tranches-heading" class="text-lg font-bold {{ $inkText }}">
                            {{ __('finance_tables.all_tranches') }}
                            <span class="ms-1 text-base font-semibold tabular-nums {{ $mutedText }}">{{ $this->allTranches->count() }}</span>
                        </h2>
                        @if ($this->allTranches->isNotEmpty())
                            <x-ui.button size="sm" icon="arrow-down-tray" class="min-h-10 touch-manipulation" wire:click="exportTranchesCsv" wire:loading.attr="disabled" wire:target="exportTranchesCsv" data-test="export-csv">{{ __('finance_tables.export_csv') }}</x-ui.button>
                        @endif
                    </div>

                    @if ($this->allTranches->isEmpty())
                        <p class="mt-4 text-sm {{ $mutedText }}">{{ __('donors.no_dated_tranches') }}</p>
                    @else
                        <x-funding.tranche-table class="mt-4" size="xl" :transfers="$this->allTranches" :caption="__('finance_tables.all_tranches')" party="proposal" />
                    @endif
                </section>
            @endif

            {{-- What this donor is still owed back. --}}
            @if ($owedReports->isNotEmpty())
                <section class="mt-10 min-w-0 border-t pt-8 {{ $hairline }}" aria-labelledby="owed-reports-heading" data-owed-donor-reports>
                    <h2 id="owed-reports-heading" class="text-lg font-bold {{ $inkText }}">
                        {{ trans_choice('donors.count_reports_owed_to_donor', $owedReports->count(), ['count' => $owedReports->count()]) }}
                    </h2>

                    <x-funding.register class="mt-4" size="sm" :caption="__('funding_agreements.donor_reports')">
                        <x-slot:head>
                            <x-funding.register.th>{{ __('funding_agreements.report_title') }}</x-funding.register.th>
                            <x-funding.register.th>{{ __('common.type') }}</x-funding.register.th>
                            <x-funding.register.th>{{ __('common.proposal') }}</x-funding.register.th>
                            <x-funding.register.th>{{ __('funding_agreements.due') }}</x-funding.register.th>
                            <x-funding.register.th>{{ __('common.state') }}</x-funding.register.th>
                        </x-slot:head>

                        @foreach ($owedReports as $owed)
                            <tr wire:key="owed-report-{{ $owed['report']->id }}" data-owed-donor-report="{{ $owed['report']->id }}">
                                <td data-label="{{ __('funding_agreements.report_title') }}" data-wide>
                                    <a href="{{ route('funding-agreements.show', $owed['agreement']) }}#reports-heading" wire:navigate class="font-semibold underline-offset-2 hover:underline">{{ $owed['report']->title }}</a>
                                </td>
                                <td data-label="{{ __('common.type') }}">{{ $owed['report']->type->label() }}</td>
                                <td data-label="{{ __('common.proposal') }}">{{ $owed['agreement']->proposal->name }}</td>
                                <td class="date" data-label="{{ __('funding_agreements.due') }}">{{ $formatDate($owed['report']->due_on) }}</td>
                                <td data-label="{{ __('common.state') }}">
                                    <span @class(['text-xs font-semibold', 'text-status-critical' => $owed['report']->isOverdue(), $mutedText => ! $owed['report']->isOverdue()])>
                                        {{ $owed['report']->isOverdue() ? __('donors.report_overdue_since', ['date' => $formatDate($owed['report']->due_on)]) : __('donors.report_due_on', ['date' => $formatDate($owed['report']->due_on)]) }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </x-funding.register>
                </section>
            @endif

            <div class="pb-10"></div>
        @endif
```

The lines that follow (`@endif`, `@endcan`, the two modals, `</section>`) stay exactly as they are.

`common.funding_agreements_2` is the existing sentence-case "Funding agreements" key.

- [x] **Step 6: Run the new tests**

Run: `.\php.cmd artisan test --compact tests/Feature/FundingDetailTablesTest.php`
Expected: PASS, 8 tests.

Common failures and their cause:
- `MissingAttributeException [money_received]` for the viewer without transfers — a cell read a money column the row does not carry; `AgreementMoney::columns(withTransfers: false)` must be what both the head and the rows receive.
- `AgreementMoney::totals()` fails on the same viewer — it must read each figure with a default of zero when the attribute is absent (Phase 0).
- "1,500.00" appears — a footer added two currencies; `AgreementMoney::totals()` returns one line per currency.

- [x] **Step 7: Point the old visual assertions at the Overview view**

In `tests/Feature/DonorDirectoryTest.php`:

1. Test `donor detail counts only received tranches as money in and flags overdue ones` — change the line that builds the component to:

```php
    $component = Livewire::actingAs($user)->test('pages::donors.show', ['donor' => $donor])->set('view', 'overview');
```

It asserts that "750.00" is absent, meaning a pending tranche's prefilled net must not be added to what was received. The table view legitimately prints 750.00 as the amount scheduled; its own guarantee is `money_received` in the test added in Step 1.

2. Test `donor detail lists each tranche in full and each report against its targets` — insert `->set('view', 'overview')` directly after `->test('pages::donors.show', ['donor' => $donor])`. It asserts `data-tranche-row`, `data-point` and the reports filed against targets, which are the Overview's.

In `tests/Feature/AccessControl/FundraisingPagesMoneyTest.php`, test `Public Relations Manager sees the donor page without agreements, budgets or tranches` — change the `$page` closure to:

```php
    $page = fn (string $role) => Livewire::actingAs(userWithRole($role))->test('pages::donors.show', ['donor' => $donor])->set('view', 'overview');
```

It asserts `data-money-river` for the finance role. The table view's guarantee for the restricted role is the third test added in Step 1.

Every other donor-page test passes unchanged: the HTTP tests read budgets and received amounts the tables print; `the donor page lists reports still owed to the donor …` (in `FundingWorkflowTest`) finds `data-owed-donor-report` on the owed-reports table.

- [x] **Step 8: Run every test that touches this page**

Run: `.\php.cmd artisan test --compact tests/Feature/DonorDirectoryTest.php tests/Feature/FundingDetailTablesTest.php tests/Feature/AccessControl/FundraisingPagesMoneyTest.php tests/Feature/FundingWorkflowTest.php tests/Feature/LocalizationTest.php`
Expected: PASS.

- [x] **Step 9: Checkpoint**

Run: `.\php.cmd vendor/bin/pint --dirty --format agent`. Do not commit.

---

### Task 4: Proposal detail — the Funding tab

The proposal page is 3,000 lines. Only its Funding tab changes, and the old markup stays where it is, inside one `@if`.

**Files:**
- Modify: `resources/views/pages/proposals/⚡show.blade.php`
- Modify: `tests/Feature/FundingDetailTablesTest.php`
- Modify: `tests/Feature/ProposalDetailActionsTest.php`

**Interfaces:**
- Consumes: `SwitchesFundingView`; `DonorProposal::withMoney()`; `AgreementMoney::columns()`, `::totals()`; `x-funding.agreement-head|row|foot`; `x-funding.tranche-table`.
- Produces: computed `agreementRows`, `fundingTranches`.

- [x] **Step 1: Write the failing tests**

Append to `tests/Feature/FundingDetailTablesTest.php`:

```php
test('the proposal funding tab opens on its agreements and tranches as tables', function () {
    $agreement = detailAgreement();

    $page = Livewire::actingAs($this->user)
        ->test('pages::proposals.show', ['proposal' => $agreement->proposal])
        ->set('tab', 'funding')
        ->assertSet('view', 'table')
        ->assertDontSeeHtml('data-chronology')
        ->assertSeeHtml('data-agreement-row="'.$agreement->id.'"')
        ->assertSee('ECHO')
        ->assertSee('1,000.00')
        ->assertSee('390.00')
        ->assertSee('600.00')
        ->assertSeeHtml('data-funding-tranches')
        ->assertSeeHtml('data-transfer-state="late"')
        ->assertSee('Operations USD')
        ->assertSeeHtml('data-test="record-agreement"')
        ->assertSeeHtml('data-test="edit-agreement-'.$agreement->id.'"')
        ->assertSeeHtml('data-test="schedule-tranche-'.$agreement->id.'"');

    expect($page->instance()->agreementRows)->toHaveCount(1)
        ->and($page->instance()->fundingTranches)->toHaveCount(3);
});

test('the proposal funding tab overview shows the chronology instead of the tables', function () {
    $agreement = detailAgreement();

    Livewire::actingAs($this->user)
        ->test('pages::proposals.show', ['proposal' => $agreement->proposal])
        ->set('tab', 'funding')
        ->set('view', 'overview')
        ->assertSeeHtml('data-chronology')
        ->assertSeeHtml('data-agreement="'.$agreement->id.'"')
        ->assertDontSeeHtml('data-funding-tranches');
});

test('the proposal funding tab shows budgets without tranches to someone who may not see transfers', function () {
    $agreement = detailAgreement();
    $viewer = userWithRole('Public Relations Manager')->givePermissionTo('funding-agreements.view');

    $page = Livewire::actingAs($viewer)
        ->test('pages::proposals.show', ['proposal' => $agreement->proposal])
        ->set('tab', 'funding');

    $page->assertSee('1,000.00')
        ->assertDontSee('390.00')
        ->assertDontSeeHtml('data-funding-tranches')
        ->assertDontSeeHtml('data-transfer=');

    expect($page->instance()->fundingTranches)->toBeEmpty();
});
```

- [x] **Step 2: Run them to verify they fail**

Run: `.\php.cmd artisan test --compact tests/Feature/FundingDetailTablesTest.php --filter="proposal funding tab"`
Expected: FAIL — `Property [$view] not found on component`.

- [x] **Step 3: Extend the class**

In the PHP block of `resources/views/pages/proposals/⚡show.blade.php`:

Add to the `use` list at the top (keep it alphabetical):

```php
use App\Livewire\Concerns\SwitchesFundingView;
use App\Support\AgreementMoney;
```

Replace:

```php
    use ManagesAgreementForm;
    use ManagesTransferForm;
    use WithFileUploads;
```

with:

```php
    use ManagesAgreementForm;
    use ManagesTransferForm;
    use SwitchesFundingView;
    use WithFileUploads;
```

In `agreements()`, add the slug to two selects so a row's links cost no extra query. Replace:

```php
            ->select(['id', 'donor_id', 'budget', 'currency', 'project_num', 'proposal_id'])
            ->where('proposal_id', $this->proposalId)
            ->with([
                'donor:id,name',
```

with:

```php
            ->select(['id', 'slug', 'donor_id', 'budget', 'currency', 'project_num', 'proposal_id'])
            ->where('proposal_id', $this->proposalId)
            ->with([
                'donor:id,slug,name',
```

Directly after the `agreements()` method add:

```php
    /**
     * The same agreements as table rows: each with its money read by the one
     * SQL definition every agreement table shares, and its donor reports
     * counted. Empty for a viewer without funding-agreements.view; without
     * transfers.view the money is not queried at all and the rows carry the
     * budget only.
     *
     * @return Collection<int, DonorProposal>
     */
    #[Computed]
    public function agreementRows(): Collection
    {
        if (! $this->canViewAgreements()) {
            return new Collection;
        }

        $today = CarbonImmutable::today()->toDateString();

        return DonorProposal::query()
            ->select('donor_proposal.*')
            ->where('donor_proposal.proposal_id', $this->proposalId)
            ->when($this->canViewTransfers(), fn (Builder $query) => $query->withMoney())
            ->with(['donor:id,slug,name'])
            ->withCount(['reports as overdue_reports_count' => fn (Builder $query) => $query
                ->whereNull('submitted_on')
                ->whereDate('due_on', '<', $today)])
            ->withMin(['reports as next_report_due' => fn (Builder $query) => $query
                ->whereNull('submitted_on')
                ->whereDate('due_on', '>=', $today)], 'due_on')
            ->orderBy('donor_proposal.id')
            ->get();
    }

    /**
     * Every tranche of every agreement on this proposal, in schedule order
     * with undated ones last, each knowing the agreement it belongs to. Empty
     * without transfers.view, because agreements() loads no tranche then.
     *
     * @return Collection<int, TransfersTracking>
     */
    #[Computed]
    public function fundingTranches(): Collection
    {
        return new Collection($this->agreements
            ->flatMap(fn (DonorProposal $agreement) => $agreement->transfers
                ->each(fn (TransfersTracking $transfer) => $transfer->setRelation('donorProposal', $agreement)))
            ->sortBy([
                fn (TransfersTracking $a, TransfersTracking $b): int => ($a->scheduled_date === null) <=> ($b->scheduled_date === null),
                fn (TransfersTracking $a, TransfersTracking $b): int => $a->scheduled_date <=> $b->scheduled_date,
                fn (TransfersTracking $a, TransfersTracking $b): int => $a->id <=> $b->id,
            ])
            ->values()
            ->all());
    }
```

In `forgetReadings()`, add the two new readings. Replace:

```php
            $this->proposal, $this->statusNotes, $this->agreements, $this->donorReports, $this->agreementMoney, $this->moneyByCurrency,
```

with:

```php
            $this->proposal, $this->statusNotes, $this->agreements, $this->agreementRows, $this->fundingTranches, $this->donorReports, $this->agreementMoney, $this->moneyByCurrency,
```

- [x] **Step 4: Confirm the tab's boundaries**

```bash
cd /c/xampp/htdocs/hc-tracking && grep -n "@if (\$tab === 'funding')\|@elseif (\$tab === 'projects')" "resources/views/pages/proposals/⚡show.blade.php"
```

Expected: two lines. Between them sits the Funding tab: first a `<div class="mb-6 flex flex-wrap items-center justify-end gap-2">` holding the two action buttons, then `@if ($this->agreements->isEmpty())` …, and last the `<div class="mt-10 border-t pt-6 …">` that holds the chronology.

- [x] **Step 5: Add the switch and wrap the old markup**

**5a.** In the Funding tab's action row, replace:

```blade
            <div class="mb-6 flex flex-wrap items-center justify-end gap-2">
                @can('transfers.create')
```

with:

```blade
            <div class="mb-6 flex flex-wrap items-center justify-end gap-2">
                <x-ui.view-toggle
                    class="me-auto"
                    :label="__('finance_tables.view_label')"
                    wire-model="view"
                    :active="$this->view"
                    :options="[
                        ['key' => 'table', 'label' => __('finance_tables.view_table'), 'icon' => 'table-cells'],
                        ['key' => 'overview', 'label' => __('finance_tables.view_overview'), 'icon' => 'chart-bar'],
                    ]"
                />
                @can('transfers.create')
```

**5b.** Directly after that action row's closing `</div>` — that is, directly before the line `            @if ($this->agreements->isEmpty())` — insert:

```blade
            @if ($this->view === 'overview')
```

**5c.** Directly before the line `        @elseif ($tab === 'projects')` — that is, directly after the `</div>` that closes the chronology block — insert:

```blade
            @elseif ($this->agreements->isEmpty())
                <x-ui.empty-state icon="banknotes" :heading="__('proposals.no_funding_agreements')" :body="__('proposals.agreements_signed_against_this_proposal_will_appear_here')" />
            @else
                @php
                    $canTransfers = $this->canViewTransfers();
                    $agreementRows = $this->agreementRows;
                    $fundingColumns = AgreementMoney::columns(withTransfers: $canTransfers);
                    $fundingFootLines = AgreementMoney::totals($agreementRows);

                    $fundingTotalLines = collect($this->moneyByCurrency)->map(fn (array $line, string $lineCurrency): array => [
                        'currency' => $lineCurrency,
                        'caption' => null,
                        'figures' => array_values(array_filter([
                            ['key' => 'committed', 'label' => __('proposals.committed'), 'value' => $line['committed']],
                            $canTransfers ? ['key' => 'received', 'label' => __('proposals.received'), 'value' => $line['received'], 'tone' => 'good', 'note' => DonorProposal::percentLabel($line['received'], $line['committed']).'%'] : null,
                            $canTransfers ? ['key' => 'pending', 'label' => __('proposals.pending'), 'value' => $line['pending'], 'tone' => $line['pending'] > 0 ? 'warn' : null] : null,
                            $canTransfers ? ['key' => 'overdue', 'label' => __('finance_tables.overdue'), 'value' => $line['overdue'], 'tone' => $line['overdue'] > 0 ? 'critical' : null] : null,
                            $canTransfers ? ['key' => 'unscheduled', 'label' => __('proposals.not_yet_scheduled'), 'value' => $line['unscheduled'], 'tone' => 'muted'] : null,
                        ])),
                        'notes' => [],
                    ])->values()->all();
                @endphp

                <x-funding.totals :lines="$fundingTotalLines" />

                {{-- Every agreement on this proposal, one row each. --}}
                <x-funding.register class="mt-6" :size="$canTransfers ? 'xl' : 'sm'" :caption="__('common.funding_agreements_2')">
                    <x-slot:head>
                        <x-funding.register.th>{{ __('funding_agreements.donor') }}</x-funding.register.th>
                        <x-funding.agreement-head :columns="$fundingColumns" />
                    </x-slot:head>

                    @foreach ($agreementRows as $agreementRow)
                        <x-funding.agreement-row :agreement="$agreementRow" :columns="$fundingColumns">
                            <x-slot:lead>
                                <td data-label="{{ __('funding_agreements.donor') }}" data-wide>
                                    @can('donors.view')
                                        <a href="{{ route('donors.show', $agreementRow->donor) }}" wire:navigate class="font-semibold underline-offset-2 hover:underline">{{ $agreementRow->donor->name }}</a>
                                    @else
                                        <span class="font-semibold">{{ $agreementRow->donor->name }}</span>
                                    @endcan
                                    <span class="sub">{{ $agreementRow->project_num ?? __('common.no_donor_reference') }}</span>
                                </td>
                            </x-slot:lead>

                            <x-slot:actions>
                                <td>
                                    <span class="flex items-center justify-end">
                                        <x-ui.button tone="ghost" size="sm" icon="eye" class="size-8!" :href="route('funding-agreements.show', $agreementRow)" wire:navigate :aria-label="__('proposals.view_agreement')" />
                                        @can('transfers.create')
                                            <x-ui.button tone="ghost" size="sm" icon="plus" class="size-8!" wire:click="openTransferCreate({{ $agreementRow->id }})" :aria-label="__('proposals.schedule_tranche_for', ['donor' => $agreementRow->donor->name])" data-test="schedule-tranche-{{ $agreementRow->id }}" />
                                        @endcan
                                        @can('funding-agreements.update')
                                            <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-8!" wire:click="openAgreementEdit({{ $agreementRow->id }})" :aria-label="__('common.edit_name', ['name' => $agreementRow->donor->name])" data-test="edit-agreement-{{ $agreementRow->id }}" />
                                        @endcan
                                    </span>
                                </td>
                            </x-slot:actions>
                        </x-funding.agreement-row>
                    @endforeach

                    <x-slot:foot>
                        <x-funding.agreement-foot :lines="$fundingFootLines" :columns="$fundingColumns" :lead="1" />
                    </x-slot:foot>
                </x-funding.register>

                {{-- Every tranche, in full. --}}
                @if ($canTransfers)
                    <section class="mt-10 min-w-0 border-t pt-8 {{ $hairline }}" aria-labelledby="funding-tranches-heading" data-funding-tranches>
                        <h2 id="funding-tranches-heading" class="text-lg font-bold {{ $inkText }}">
                            {{ __('finance_tables.all_tranches') }}
                            <span class="ms-1 text-base font-semibold tabular-nums {{ $mutedText }}">{{ $this->fundingTranches->count() }}</span>
                        </h2>

                        @if ($this->fundingTranches->isEmpty())
                            <p class="mt-4 text-sm {{ $mutedText }}">{{ __('proposals.committed_with_no_transfer_yet') }}</p>
                        @else
                            <x-funding.tranche-table class="mt-4" size="xl" :transfers="$this->fundingTranches" :caption="__('finance_tables.all_tranches')" party="donor" editable />
                        @endif
                    </section>
                @endif
            @endif
```

The `@if ($this->view === 'overview')` of 5b now opens a block whose first statement is the old `@if ($this->agreements->isEmpty()) … @endif` and whose last is the chronology `<div>`; 5c closes it with `@elseif … @else … @endif`. Read the result once from top to bottom to confirm the nesting: the tab has exactly one new `@if` and one new `@endif`.

The template reads `AgreementMoney::` and `DonorProposal::` by their short names; both are imported in the file's PHP block.

- [x] **Step 6: Run the new tests**

Run: `.\php.cmd artisan test --compact tests/Feature/FundingDetailTablesTest.php`
Expected: PASS, 11 tests.

If the page fails to compile with `unexpected token "elseif"` or `"endif"`, the insertions of 5b and 5c are not balanced: 5b must sit outside the old `@if ($this->agreements->isEmpty())`, not inside it.

- [x] **Step 7: Point two old visual assertions at the Overview view**

In `tests/Feature/ProposalDetailActionsTest.php`:

1. Test `a tranche due today is not overdue, and one due yesterday is, everywhere on the page` — both assertions on the Funding tab become:

```php
    $page->set('tab', 'funding')->set('view', 'overview')->assertDontSeeText('Overdue');
```

and

```php
    $page->set('tab', 'funding')->set('view', 'overview')->assertSeeText('Overdue');
```

The table view always prints "Overdue" as a column heading, so the word's absence can only be asserted on the Overview's tranche list.

2. Test `a tranche received in another currency is left out of the agreement money and named instead` — its last line becomes:

```php
    $page->set('tab', 'funding')->set('view', 'overview')->assertSeeHtml('data-foreign-tranches');
```

Every other proposal-page test passes unchanged: the ones that open the Funding tab read donor names, budgets, tranche amounts and the `record-agreement` / `edit-agreement-{id}` hooks, all of which the tables print.

- [x] **Step 8: Run every test that touches this page**

Run: `.\php.cmd artisan test --compact tests/Feature/ProposalDetailTest.php tests/Feature/ProposalDetailActionsTest.php tests/Feature/ProposalFundedProjectsTest.php tests/Feature/FundingDetailTablesTest.php tests/Feature/AccessControl/FundraisingPagesMoneyTest.php tests/Feature/FundingWorkflowTest.php tests/Feature/LocalizationTest.php`
Expected: PASS.

- [ ] **Step 9: Checkpoint**

Run: `.\php.cmd vendor/bin/pint --dirty --format agent`. Do not commit.

---

### Task 5: Look at it

**Files:** none changed unless a defect is found.

- [x] **Step 1: Render each page** as in Phase 1, Task 5. Pick one agreement, its donor and its proposal from the seeded data and open each in both views (`?view=overview`), and the proposal with `?tab=funding`.

- [x] **Step 2: Check each of these and fix what fails**

| Check | Expected |
| --- | --- |
| Agreement page, table view | Totals line, attention list, tranche table with a totals row, donor reports table, connected records in two or three columns. No dial. |
| Agreement page, Overview | Identical to the page before this phase. The timeline (Gantt) draws after switching to it from the table view. |
| Donor page, table view | Overdue list on top, totals per currency, agreements table, all-tranches table with a Proposal column, owed-reports table. No `<details>`. |
| Proposal page, Funding tab | The switch sits at the start of the action row; agreements table, then tranches with a Donor column and an edit button per row. |
| Editing a tranche from any table | Opens the same form as before and the table refreshes after saving. |
| Dark mode, Arabic, 390px | As in Phase 1. |

If the Gantt does not draw after switching views, give its wrapper a `wire:key` that includes the view (`wire:key="agreement-gantt-{{ $this->agreementId }}-{{ $this->view }}"`) so Livewire mounts it fresh.

- [x] **Step 3: Delete the preview file and checkpoint.** Do not commit.
