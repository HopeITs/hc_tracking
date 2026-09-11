# Funding Spine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `proposals`, `proposal_status_notes`, `donors`, `donor_proposal`, `transfers_tracking` and `accounts` reachable, readable and writable — without opening any route by which the money trail can be erased.

**Architecture:** Livewire 4 single-file pages built from the `x-ui.*` components. Proposals and Donors are hubs with detail pages; Funding Agreements (`donor_proposal`) gets its own section because it is an entity in pivot clothing; Transfers is a cross-cutting index; Accounts lives under Administration. Status changes on a proposal write the `proposal_status_notes` audit trail in the same transaction.

**Tech Stack:** Laravel 13, PHP 8.5, Livewire 4, Flux 2, Tailwind 4, Pest 5.

**Depends on:** the Foundation plan (all 8 tasks). It does **not** depend on the Delivery plan, but Task 2 of this plan removes a `Route::has('proposals.show')` guard that the Delivery plan's Project detail page carries — **if the Delivery plan has not been implemented, skip that step and note it.**

## Global Constraints

- **Strict mode is on.** Lazy loading throws. Eager load every relation the view touches.
- **Palette is fixed:** `primary #be1f24`, `secondary-dark/medium/light/soft`. Badge tone is palette-only.
- **Money is never summed across currencies.** `USD`, `EUR` and `SYP` have no conversion rate in this system. Every total is per-currency, and `x-ui.money` throws when handed an amount with no currency. **This is the single most important rule in this plan.**
- **Never compare a pivot currency to a `Currency` case.** `$proposal->donors()->first()->pivot->currency` is the raw string `'USD'`; `DonorProposal::first()->currency` is a `Currency` case. All funding UI reads through `donorProposals()`, never `donors()->pivot`.
- **`Currency` values are UPPER case** — the only enum here that is. `'usd'` throws `ValueError` on read.
- **`proposals.phase` is derived, never written.** Read `$proposal->phase`; query a phase with `whereIn('status', ProposalPhase::X->statuses())`.
- **Morph keys, never class names:** a proposal's targets are `source_type = 'proposal'`. The morph map holds four entries as of the schema update below (`'yearly_plan'`, `'proposal'`, `'project'`, `'milestone'`) — the latter two are for `media` and are not touched by this spine.
- **No further schema changes.** The `donors` profile columns in the addendum below are already migrated, seeded and tested. No migration, model, observer, factory or seeder is modified while executing these tasks.
- **Tests** live at `tests/Feature/<Feature>Test.php`, `tests/Feature/Admin/` for Accounts.
- **Pint** before any task is done. **Never commit** — stage and stop.

## DB Schema Update (2026-09-11)

Implemented, migrated, seeded and tested before this plan runs — not a task
within it.

**`donors` gained a contactable profile.** New columns, all nullable: `type`
(`DonorType` enum — bilateral, foundation, individual, corporate), `contact_person`,
`email`, `phone`, `country`, `notes`. Every column is nullable because a
funder can legitimately be recorded from a grant letter alone with no named
contact and no classification yet — `donors.name` staying non-unique is
unchanged, and this addition doesn't touch that decision.

**Obligation for Task 3 (Donor directory and detail) — extends, does not
replace, the task below.** The create/edit form gains fields for `type` (a
`DonorType` select, with a "not classified" option since it's nullable),
`contact_person`, `email`, `phone`, `country`, `notes`. `x-ui.enum-badge`
should grow a `DonorType` arm rendering it outlined (none of the four values
is a terminal or achieved state).

**The donor detail page (Task 3's `⚡show.blade.php`) additionally needs four
stat cards**, requested directly by the user rather than derived from the
spec:

1. **Total committed** — already computed by Task 3's `budgetByCurrency()`
   (per currency, never summed across them — the spine's core money rule).
   No new code; the four cards below are additive to what Task 3 already
   builds.
2. **Total disbursed** — the equivalent grouped sum, but read from
   `transfers_tracking.net_amount_received` across every agreement this donor
   holds, not from `donor_proposal.budget`. This is a **new** computed
   property on the detail page: `disbursedByCurrency()`, grouping on
   `amount_received_currency` (a different column from the transferred
   currency — a USD payment can land as SYP) exactly as
   `FundingAgreementTest`'s equivalent method already does for one agreement.
   Sum only rows where `net_amount_received !== null`.
3. **Active/closed projects** — the donor has no direct relation to
   `projects`; the path is `Donor → donorProposals → proposal → projects()`.
   Add a computed property counting distinct projects via
   `$this->donor->proposals()->with('projects')` (or a single query joining
   `donor_proposal` → `project_proposal` → `projects`), split by
   `ProjectStatus::isOpen()` — open states (draft/submitted/approved/active)
   in one figure, closed states (closed/archived) in the other. A project
   funded by two of this donor's proposals must be counted once, not twice —
   use `distinct()` on the project id.
4. **Reports submitted** — count of `Report` rows whose `plan_indicator_id`
   traces back to a target committed under one of this donor's proposals
   (`plan_indicators.source_type = 'proposal'` and `source_id` in this
   donor's proposal ids). This is a portfolio-wide count across all of the
   donor's agreements, not per-agreement like Task 3's existing "transfers"
   count.

None of this changes Task 3's write permissions (create/edit, guarded delete
when agreements exist) or its file list — it is one additional test case
(`'donor detail shows total disbursed and reports submitted separately from
total committed'`) and roughly forty lines added to the existing
`⚡show.blade.php`, not a new page.

## Delete rules governing this spine

From the migrations. `.ai/rules/database-migrations.md` records why two of these were changed from the original design.

| Column | Rule | Consequence |
| --- | --- | --- |
| `donor_proposal.proposal_id` | **restrict** | A proposal with any funding agreement **cannot** be deleted. This was deliberately changed from cascade after it was found to let one `$proposal->delete()` reach through `donor_proposal` into `transfers_tracking` and erase every record of money received |
| `donor_proposal.donor_id` | **restrict** | A donor with any agreement cannot be deleted |
| `transfers_tracking.donor_proposal_id` | cascade | Deleting an agreement **does** erase its transfers — intended, because acting on the agreement is the deliberate act, but it is why the UI offers no agreement delete |
| `transfers_tracking.account_id` | **restrict**, nullable | An account money has passed through can never be deleted. Close it instead |
| `proposal_status_notes.proposal_id` | cascade | The audit trail goes with its proposal |
| `tasks.proposal_id`, `project_proposal.proposal_id` | cascade | An unfunded proposal's tasks and project links go with it |

Therefore: **Proposals delete only when unfunded.** **Donors delete only when they hold no agreement.** **Funding Agreements have no delete.** **Transfers have no delete.** **Accounts have no delete — they close.**

---

## File Structure

| File | Responsibility |
| --- | --- |
| `resources/views/pages/proposals/⚡index.blade.php` | Proposal pipeline, phase/status filters, create/edit, guarded delete |
| `resources/views/pages/proposals/⚡show.blade.php` | Proposal detail: status timeline, funding, projects, tasks, targets |
| `resources/views/pages/donors/⚡index.blade.php` | Donor directory, create/edit, guarded delete |
| `resources/views/pages/donors/⚡show.blade.php` | Donor detail: agreements and money received, per currency |
| `resources/views/pages/funding-agreements/⚡index.blade.php` | Agreements index, create/edit, no delete |
| `resources/views/pages/funding-agreements/⚡show.blade.php` | Agreement detail: tranche schedule and receipts |
| `resources/views/pages/transfers/⚡index.blade.php` | Transfer tracking, create/edit, no delete |
| `resources/views/pages/admin/⚡accounts.blade.php` | Accounts, create/edit/close, no delete |
| `tests/Feature/ProposalPipelineTest.php` | Proposals index and delete guard |
| `tests/Feature/ProposalDetailTest.php` | Proposal detail, status change, audit trail |
| `tests/Feature/DonorDirectoryTest.php` | Donors |
| `tests/Feature/FundingAgreementTest.php` | Agreements index and detail |
| `tests/Feature/TransferTrackingTest.php` | Transfers |
| `tests/Feature/Admin/AccountDirectoryTest.php` | Accounts |

Modified: `routes/web.php`, `resources/views/components/navigation/sidebar.blade.php`, and — only if the Delivery plan has landed — `resources/views/pages/projects/⚡show.blade.php`.

---

### Task 1: Proposal pipeline

**Files:**
- Create: `resources/views/pages/proposals/⚡index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ProposalPipelineTest.php`

**Interfaces:**
- Consumes: `x-ui.page-header`, `x-ui.panel`, `x-ui.empty-state`, `x-ui.enum-badge`, `x-ui.money`, `x-ui.input`, `x-ui.select`, `x-ui.button`.
- Produces: route `proposals.index`; component `pages::proposals.index`; public properties `$search`, `$status`, `$phase`, `$showProposalModal`, `$editingProposalId`, `$deletingProposalId`, form fields `$name`, `$plannedDateBegin`, `$plannedDateEnd`, `$actualDateBegin`, `$actualDateEnd`, `$proposalStatus`, `$notes`; methods `openCreateModal()`, `openEditModal(int)`, `saveProposal()`, `confirmDelete(int)`, `deleteProposal()`, `closeProposalModal()`, `clearFilters()`; computed `canDelete(int): bool`.

**The phase filter does not touch a column.** There is no `phase` column. Filtering by phase means `whereIn('status', ProposalPhase::from($this->phase)->statuses())`.

**The delete guard is the point of this task.** A proposal holding any `donor_proposal` row cannot be deleted — the database RESTRICTs it, and the UI must say why rather than letting a query exception surface.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from the proposal pipeline', function () {
    $this->get(route('proposals.index'))->assertRedirect(route('login'));
});

test('proposals can be filtered by search and status', function () {
    $user = User::factory()->create();

    Proposal::factory()->create(['name' => 'Emergency WASH Response', 'status' => ProposalStatus::Active]);
    Proposal::factory()->create(['name' => 'Winter Shelter Kit', 'status' => ProposalStatus::Rejected]);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->assertSee('Emergency WASH Response')
        ->assertSee('Winter Shelter Kit')
        ->set('search', 'WASH')
        ->assertSee('Emergency WASH Response')
        ->assertDontSee('Winter Shelter Kit')
        ->call('clearFilters')
        ->set('status', ProposalStatus::Rejected->value)
        ->assertDontSee('Emergency WASH Response')
        ->assertSee('Winter Shelter Kit');
});

test('the phase filter selects every status belonging to that phase', function () {
    $user = User::factory()->create();

    // Monitoring holds exactly ProposalStatus::Active.
    Proposal::factory()->create(['name' => 'Monitored proposal', 'status' => ProposalStatus::Active]);
    // Planning holds ConceptNote and ProposalDraft.
    Proposal::factory()->create(['name' => 'Planned proposal', 'status' => ProposalStatus::ConceptNote]);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->set('phase', ProposalPhase::Monitoring->value)
        ->assertSee('Monitored proposal')
        ->assertDontSee('Planned proposal')
        ->call('clearFilters')
        ->set('phase', ProposalPhase::Planning->value)
        ->assertDontSee('Monitored proposal')
        ->assertSee('Planned proposal');
});

test('a proposal can be created with a trimmed name', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openCreateModal')
        ->set('name', '  Rapid Response Fund  ')
        ->set('proposalStatus', ProposalStatus::ConceptNote->value)
        ->set('plannedDateBegin', '2026-01-01')
        ->set('plannedDateEnd', '2026-12-31')
        ->call('saveProposal')
        ->assertHasNoErrors()
        ->assertSet('showProposalModal', false);

    $proposal = Proposal::query()->where('name', 'Rapid Response Fund')->sole();

    expect($proposal->status)->toBe(ProposalStatus::ConceptNote);
});

test('a proposal end date cannot fall before its begin date', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('openCreateModal')
        ->set('name', 'Backwards Proposal')
        ->set('proposalStatus', ProposalStatus::ConceptNote->value)
        ->set('plannedDateBegin', '2026-12-31')
        ->set('plannedDateEnd', '2026-01-01')
        ->call('saveProposal')
        ->assertHasErrors(['plannedDateEnd']);
});

test('an unfunded proposal can be deleted', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('confirmDelete', $proposal->id)
        ->call('deleteProposal')
        ->assertHasNoErrors();

    expect(DB::table('proposals')->find($proposal->id))->toBeNull();
});

test('a proposal holding a funding agreement is not deleted and says why', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();
    $donor = Donor::factory()->create();
    $agreement = DonorProposal::factory()->create([
        'donor_id' => $donor->id,
        'proposal_id' => $proposal->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::proposals.index')
        ->call('confirmDelete', $proposal->id)
        ->call('deleteProposal');

    expect(DB::table('proposals')->find($proposal->id))->not->toBeNull()
        ->and(DB::table('donor_proposal')->find($agreement->id))->not->toBeNull();
});

test('the pipeline renders with no proposals', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::proposals.index')->assertSee('No proposals yet');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/ProposalPipelineTest.php`
Expected: FAIL — `Route [proposals.index] not defined.`

- [ ] **Step 3: Add the route**

```php
    Route::livewire('proposals', 'pages::proposals.index')
        ->name('proposals.index');
```

- [ ] **Step 4: Write the page**

`resources/views/pages/proposals/⚡index.blade.php`:

```blade
<?php

use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;
use App\Models\Proposal;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Proposals')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $phase = '';

    public bool $showProposalModal = false;
    public ?int $editingProposalId = null;
    public ?int $deletingProposalId = null;

    public string $name = '';
    public string $plannedDateBegin = '';
    public string $plannedDateEnd = '';
    public string $actualDateBegin = '';
    public string $actualDateEnd = '';
    public string $proposalStatus = 'concept note';
    public string $notes = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPhase(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->phase = '';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Proposal> */
    #[Computed]
    public function proposals(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Proposal::query()
            ->select(['id', 'name', 'planned_date_begin', 'planned_date_end', 'actual_date_begin', 'actual_date_end', 'status', 'updated_at'])
            ->withCount(['donorProposals', 'projects', 'tasks', 'statusNotes', 'planIndicators'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')->orWhere('notes', 'like', '%'.$search.'%');
                });
            })
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            // There is no phase column: a phase is a set of statuses, derived
            // from the same match that ProposalStatus::phase() uses.
            ->when(
                $this->phase !== '',
                fn ($query) => $query->whereIn('status', ProposalPhase::from($this->phase)->statuses())
            )
            ->orderByDesc('planned_date_begin')
            ->orderBy('id')
            ->paginate(15);
    }

    /**
     * Whether this proposal may be deleted.
     *
     * donor_proposal.proposal_id RESTRICTs on delete, deliberately: without it
     * one proposal delete would reach through the agreement into
     * transfers_tracking and erase every record of money received. The UI
     * refuses first so the user gets a reason instead of a query exception.
     */
    public function canDelete(int $proposalId): bool
    {
        $proposal = Proposal::query()
            ->select(['id'])
            ->withCount('donorProposals')
            ->find($proposalId);

        return $proposal !== null && $proposal->donor_proposals_count === 0;
    }

    public function openCreateModal(): void
    {
        $this->resetProposalForm();
        $this->showProposalModal = true;
    }

    public function openEditModal(int $proposalId): void
    {
        $proposal = Proposal::query()
            ->select(['id', 'name', 'planned_date_begin', 'planned_date_end', 'actual_date_begin', 'actual_date_end', 'status', 'notes'])
            ->findOrFail($proposalId);

        $this->resetValidation();
        $this->editingProposalId = $proposal->id;
        $this->name = $proposal->name;
        $this->plannedDateBegin = $proposal->planned_date_begin?->toDateString() ?? '';
        $this->plannedDateEnd = $proposal->planned_date_end?->toDateString() ?? '';
        $this->actualDateBegin = $proposal->actual_date_begin?->toDateString() ?? '';
        $this->actualDateEnd = $proposal->actual_date_end?->toDateString() ?? '';
        $this->proposalStatus = $proposal->status->value;
        $this->notes = $proposal->notes ?? '';
        $this->showProposalModal = true;
    }

    public function saveProposal(): void
    {
        $this->name = trim($this->name);
        $this->notes = trim($this->notes);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'plannedDateBegin' => ['required', 'date'],
            'plannedDateEnd' => ['required', 'date', 'after_or_equal:plannedDateBegin'],
            'actualDateBegin' => ['nullable', 'date'],
            'actualDateEnd' => ['nullable', 'date', 'after_or_equal:actualDateBegin'],
            'proposalStatus' => ['required', new Enum(ProposalStatus::class)],
            'notes' => ['nullable', 'string'],
        ], [
            'name.required' => __('Enter a proposal name.'),
            'plannedDateBegin.required' => __('Set the planned start date.'),
            'plannedDateEnd.required' => __('Set the planned end date.'),
            'plannedDateEnd.after_or_equal' => __('The planned end cannot fall before the planned start.'),
            'actualDateEnd.after_or_equal' => __('The actual end cannot fall before the actual start.'),
        ]);

        $proposal = $this->editingProposalId === null
            ? new Proposal
            : Proposal::query()->findOrFail($this->editingProposalId);

        $proposal->fill([
            'name' => $validated['name'],
            'planned_date_begin' => $validated['plannedDateBegin'],
            'planned_date_end' => $validated['plannedDateEnd'],
            'actual_date_begin' => $validated['actualDateBegin'] === '' ? null : $validated['actualDateBegin'],
            'actual_date_end' => $validated['actualDateEnd'] === '' ? null : $validated['actualDateEnd'],
            'status' => $validated['proposalStatus'],
            'notes' => $validated['notes'] === '' ? null : $validated['notes'],
        ])->save();

        $wasRecentlyCreated = $proposal->wasRecentlyCreated;

        $this->showProposalModal = false;
        $this->resetProposalForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('Proposal created.') : __('Proposal updated.'),
        );
    }

    public function confirmDelete(int $proposalId): void
    {
        $this->deletingProposalId = $proposalId;
    }

    public function deleteProposal(): void
    {
        if ($this->deletingProposalId === null) {
            return;
        }

        if (! $this->canDelete($this->deletingProposalId)) {
            $this->deletingProposalId = null;

            Flux::toast(
                variant: 'danger',
                text: __('This proposal has a funding agreement. Remove the agreement first — deleting a funded proposal would erase the record of money received.'),
            );

            return;
        }

        Proposal::query()->findOrFail($this->deletingProposalId)->delete();

        $this->deletingProposalId = null;
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('Proposal deleted.'));
    }

    public function closeProposalModal(): void
    {
        $this->showProposalModal = false;
        $this->resetProposalForm();
    }

    protected function resetProposalForm(): void
    {
        $this->resetValidation();
        $this->editingProposalId = null;
        $this->name = '';
        $this->plannedDateBegin = '';
        $this->plannedDateEnd = '';
        $this->actualDateBegin = '';
        $this->actualDateEnd = '';
        $this->proposalStatus = 'concept note';
        $this->notes = '';
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $status !== '' || $phase !== '';
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="proposal-pipeline-heading">
    <x-ui.page-header
        heading-id="proposal-pipeline-heading"
        :title="__('Proposals')"
        :count="$this->proposals->total()"
        :description="__('The funding pipeline, newest planned start first. Phase is derived from status.')"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('Search proposals')" :placeholder="__('Name or notes')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.select :label="__('Status')" wire:model.live="status">
                <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                @foreach (ProposalStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value" :wire:key="'proposal-status-'.$statusCase->value">{{ Str::title($statusCase->value) }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('Phase')" wire:model.live="phase">
                <flux:select.option value="">{{ __('All phases') }}</flux:select.option>
                @foreach (ProposalPhase::cases() as $phaseCase)
                    <flux:select.option :value="$phaseCase->value" :wire:key="'proposal-phase-'.$phaseCase->value">{{ Str::title($phaseCase->value) }}</flux:select.option>
                @endforeach
            </x-ui.select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-proposal-button">
                {{ __('Add proposal') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel :subtitle="trans_choice('{1} :count matching proposal|[2,*] :count matching proposals', $this->proposals->total(), ['count' => $this->proposals->total()])">
        <x-slot:toolbar>
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('Reset filters') }}</flux:button>
            @endif
        </x-slot:toolbar>

        @if ($this->proposals->isEmpty())
            <x-ui.empty-state
                icon="document-text"
                :heading="$filtersAreActive ? __('No matching proposals') : __('No proposals yet')"
                :body="$filtersAreActive ? __('Change the search or filters and try again.') : __('Add the first proposal to open the pipeline.')"
            />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->proposals as $proposal)
                    <article class="flex flex-col gap-3 p-3.5 @4xl/directory:flex-row @4xl/directory:items-center" wire:key="proposal-{{ $proposal->id }}">
                        <div class="min-w-0 @4xl/directory:me-auto">
                            <a href="{{ route('proposals.show', $proposal) }}" wire:navigate class="text-sm font-semibold hover:underline">{{ $proposal->name }}</a>
                            <p class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">
                                {{ $proposal->planned_date_begin?->format('M j, Y') }} – {{ $proposal->planned_date_end?->format('M j, Y') }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.enum-badge :case="$proposal->status" />
                            {{-- phase is a read-only accessor over status->phase(), not a column. --}}
                            <span class="rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-secondary-soft">
                                {{ Str::title($proposal->phase->value) }}
                            </span>
                        </div>

                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs font-medium text-secondary-medium dark:text-secondary-soft @4xl/directory:w-64">
                            <span>{{ trans_choice(':count agreement|:count agreements', $proposal->donor_proposals_count, ['count' => $proposal->donor_proposals_count]) }}</span>
                            <span>{{ trans_choice(':count project|:count projects', $proposal->projects_count, ['count' => $proposal->projects_count]) }}</span>
                            <span>{{ trans_choice(':count task|:count tasks', $proposal->tasks_count, ['count' => $proposal->tasks_count]) }}</span>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $proposal->id }})" :aria-label="__('Edit :name', ['name' => $proposal->name])" />

                            @if ($proposal->donor_proposals_count === 0)
                                <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9!" wire:click="confirmDelete({{ $proposal->id }})" :aria-label="__('Delete :name', ['name' => $proposal->name])" />
                            @else
                                <flux:tooltip :content="__('Funded proposals cannot be deleted. Remove the funding agreement first.')">
                                    <span class="inline-flex size-9 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
                                        <flux:icon.lock-closed class="size-4" />
                                    </span>
                                </flux:tooltip>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        <x-slot:footer>
            <flux:pagination :paginator="$this->proposals" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#proposal-pipeline-heading" />
        </x-slot:footer>
    </x-ui.panel>

    <flux:modal wire:model="showProposalModal" class="w-full max-w-3xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveProposal" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingProposalId === null ? __('Add proposal') : __('Edit proposal') }}</flux:heading>
                <flux:subheading>{{ __('Phase follows automatically from the status you choose.') }}</flux:subheading>
            </div>

            <x-ui.input wire:model="name" :label="__('Proposal name')" type="text" maxlength="255" required autofocus />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input wire:model="plannedDateBegin" :label="__('Planned start')" type="date" required />
                <x-ui.input wire:model="plannedDateEnd" :label="__('Planned end')" type="date" required />
                <x-ui.input wire:model="actualDateBegin" :label="__('Actual start')" type="date" />
                <x-ui.input wire:model="actualDateEnd" :label="__('Actual end')" type="date" />
            </div>

            <x-ui.select wire:model="proposalStatus" :label="__('Status')" required>
                @foreach (ProposalStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value" :wire:key="'proposal-status-option-'.$statusCase->value">
                        {{ Str::title($statusCase->value) }} — {{ Str::title($statusCase->phase()->value) }}
                    </flux:select.option>
                @endforeach
            </x-ui.select>

            <flux:textarea wire:model="notes" :label="__('Notes')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />

            <div class="rounded-lg border border-secondary-soft bg-secondary-soft/50 p-3 text-sm text-secondary-medium dark:border-secondary-medium dark:bg-secondary-medium/50 dark:text-secondary-soft">
                <div class="flex items-start gap-2.5">
                    <flux:icon.information-circle class="mt-0.5 size-4 shrink-0" />
                    <p>{{ __('Changing status here does not write an audit note. Use the status action on the proposal page to record why it changed.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeProposalModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-proposal-button">
                    {{ $editingProposalId === null ? __('Create proposal') : __('Save changes') }}
                </x-ui.button>
            </div>
        </form>
    </flux:modal>

    <flux:modal :open="$deletingProposalId !== null" wire:close="$set('deletingProposalId', null)" class="w-full max-w-md border border-secondary-light! bg-white! dark:bg-secondary-dark!">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Delete this proposal?') }}</flux:heading>
            <flux:subheading>{{ __('Its tasks, project links and status history are removed with it. A proposal holding a funding agreement cannot be deleted at all.') }}</flux:subheading>
            <div class="flex justify-end gap-2 border-t border-secondary-soft pt-4 dark:border-secondary-medium">
                <x-ui.button type="button" wire:click="$set('deletingProposalId', null)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" wire:click="deleteProposal" data-test="confirm-delete-proposal">{{ __('Delete proposal') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</section>
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/pest tests/Feature/ProposalPipelineTest.php`
Expected: the filter, phase, create, validation and guard tests PASS. The row link to `proposals.show` needs Task 2; implement Task 2's route (Step 3) if the run blocks, then return.

- [ ] **Step 6: Format and stage**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/pages/proposals tests/Feature/ProposalPipelineTest.php
```

Stop and report. Do not commit.

---

### Task 2: Proposal detail and the status audit trail

**Files:**
- Create: `resources/views/pages/proposals/⚡show.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/pages/projects/⚡show.blade.php` — **only if the Delivery plan has landed**
- Test: `tests/Feature/ProposalDetailTest.php`

**Interfaces:**
- Consumes: `x-ui.detail-header`, `x-ui.relation-tabs`, `x-ui.panel`, `x-ui.empty-state`, `x-ui.enum-badge`, `x-ui.money`, `x-ui.stat-tile`, `x-ui.select`, `x-ui.button`.
- Produces: route `proposals.show`; component `pages::proposals.show`; public properties `public int $proposalId`, `public string $tab` (`'status'`, `'funding'`, `'projects'`, `'tasks'`, `'targets'`), `public bool $showStatusModal`, `public string $newStatus`, `public string $statusContext`, `public string $statusDate`; method `changeStatus()`.

**The status change is the one real workflow action here.** It updates `proposals.status` and appends a `proposal_status_notes` row **in one transaction**, so the live status and its audit trail can never disagree. Both columns cast to the same `ProposalStatus` enum by design — `.ai/rules/enums.md` records that a second enum would let the recorded history drift from the live vocabulary.

**Funding totals are grouped by currency.** A proposal funded 500,000 USD and 200,000 EUR shows two lines, never one sum.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\Currency;
use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from proposal detail', function () {
    $proposal = Proposal::factory()->create();

    $this->get(route('proposals.show', $proposal))->assertRedirect(route('login'));
});

test('proposal detail shows its name status and derived phase', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create([
        'name' => 'Emergency WASH Response',
        'status' => ProposalStatus::Active,
    ]);

    $this->actingAs($user)
        ->get(route('proposals.show', $proposal))
        ->assertOk()
        ->assertSeeText('Emergency WASH Response')
        ->assertSeeText('Active')
        // ProposalStatus::Active belongs to ProposalPhase::Monitoring.
        ->assertSeeText('Monitoring');
});

test('proposal detail shows only its own status notes newest first', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();
    $other = Proposal::factory()->create();

    ProposalStatusNote::factory()->create([
        'proposal_id' => $proposal->id,
        'context' => 'Older note',
        'date' => '2026-01-01',
    ]);
    ProposalStatusNote::factory()->create([
        'proposal_id' => $proposal->id,
        'context' => 'Newer note',
        'date' => '2026-06-01',
    ]);
    ProposalStatusNote::factory()->create([
        'proposal_id' => $other->id,
        'context' => 'Someone elses note',
    ]);

    $this->actingAs($user)
        ->get(route('proposals.show', $proposal))
        ->assertOk()
        ->assertSeeTextInOrder(['Newer note', 'Older note'])
        ->assertDontSeeText('Someone elses note');
});

test('changing status updates the proposal and appends an audit note in one act', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('newStatus', ProposalStatus::Approved->value)
        ->set('statusContext', '  Grant agreement countersigned.  ')
        ->set('statusDate', '2026-04-01')
        ->call('changeStatus')
        ->assertHasNoErrors();

    $proposal->refresh();
    $note = ProposalStatusNote::query()->where('proposal_id', $proposal->id)->sole();

    expect($proposal->status)->toBe(ProposalStatus::Approved)
        ->and($note->status)->toBe(ProposalStatus::Approved)
        ->and($note->context)->toBe('Grant agreement countersigned.')
        ->and($note->date->toDateString())->toBe('2026-04-01');
});

test('a status change with no context is rejected and changes nothing', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('newStatus', ProposalStatus::Approved->value)
        ->set('statusContext', '   ')
        ->call('changeStatus')
        ->assertHasErrors(['statusContext']);

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Submitted)
        ->and(ProposalStatusNote::query()->where('proposal_id', $proposal->id)->count())->toBe(0);
});

test('funding is totalled per currency and never summed across them', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();

    DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'donor_id' => Donor::factory()->create(['name' => 'UNICEF'])->id,
        'budget' => '500000.00',
        'currency' => Currency::Usd,
    ]);
    DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'donor_id' => Donor::factory()->create(['name' => 'ECHO'])->id,
        'budget' => '200000.00',
        'currency' => Currency::Eur,
    ]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'funding')
        ->assertSee('UNICEF')
        ->assertSee('ECHO')
        ->assertSee('500,000.00')
        ->assertSee('200,000.00')
        ->assertSee('USD')
        ->assertSee('EUR')
        // 700,000 would be the forbidden cross-currency sum.
        ->assertDontSee('700,000.00');
});

test('proposal detail shows targets committed in this proposal', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();
    $indicator = \App\Models\Indicator::factory()->create(['name' => 'Households assisted']);

    \App\Models\PlanIndicator::factory()->create([
        'source_type' => 'proposal',
        'source_id' => $proposal->id,
        'indicator_id' => $indicator->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'targets')
        ->assertSee('Households assisted');
});

test('proposal detail renders a proposal with nothing attached', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create(['name' => 'Bare Proposal']);

    $this->actingAs($user)
        ->get(route('proposals.show', $proposal))
        ->assertOk()
        ->assertSeeText('Bare Proposal')
        ->assertSeeText('No status history yet');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/ProposalDetailTest.php`
Expected: FAIL — `Route [proposals.show] not defined.`

- [ ] **Step 3: Add the route**

```php
    Route::livewire('proposals/{proposal}', 'pages::proposals.show')
        ->name('proposals.show');
```

- [ ] **Step 4: Write the page**

`resources/views/pages/proposals/⚡show.blade.php`:

```blade
<?php

use App\Enums\ProposalStatus;
use App\Models\DonorProposal;
use App\Models\PlanIndicator;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Proposal')] class extends Component {
    public int $proposalId;

    #[Url(except: 'status')]
    public string $tab = 'status';

    public bool $showStatusModal = false;
    public string $newStatus = '';
    public string $statusContext = '';
    public string $statusDate = '';

    public function mount(Proposal $proposal): void
    {
        $this->proposalId = $proposal->id;
    }

    #[Computed]
    public function proposal(): Proposal
    {
        return Proposal::query()
            ->select(['id', 'name', 'planned_date_begin', 'planned_date_end', 'actual_date_begin', 'actual_date_end', 'status', 'notes', 'updated_at'])
            ->withCount(['donorProposals', 'projects', 'tasks', 'statusNotes', 'planIndicators'])
            ->findOrFail($this->proposalId);
    }

    /** @return Collection<int, ProposalStatusNote> */
    #[Computed]
    public function statusNotes(): Collection
    {
        return ProposalStatusNote::query()
            ->select(['id', 'proposal_id', 'status', 'context', 'date'])
            ->where('proposal_id', $this->proposalId)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The funding agreements on this proposal.
     *
     * Read through donorProposals(), never through donors()->pivot: the pivot
     * built by belongsToMany carries no casts, so its currency arrives as a raw
     * string while this relation's arrives as a Currency case.
     *
     * @return Collection<int, DonorProposal>
     */
    #[Computed]
    public function agreements(): Collection
    {
        return DonorProposal::query()
            ->select(['id', 'donor_id', 'budget', 'currency', 'project_num', 'proposal_id'])
            ->where('proposal_id', $this->proposalId)
            ->with('donor:id,name')
            ->withCount('transfers')
            ->orderBy('id')
            ->get();
    }

    /**
     * Committed budget per currency.
     *
     * Grouped, never summed: USD, EUR and SYP have no conversion rate in this
     * system, so a single total would be a fabricated number.
     *
     * @return array<string, float>
     */
    #[Computed]
    public function budgetByCurrency(): array
    {
        return $this->agreements
            ->groupBy(fn (DonorProposal $agreement): string => $agreement->currency->value)
            ->map(fn (Collection $group): float => (float) $group->sum(fn (DonorProposal $agreement): float => (float) $agreement->budget))
            ->all();
    }

    /** @return Collection<int, \App\Models\Project> */
    #[Computed]
    public function projects(): Collection
    {
        return $this->proposal->projects()
            ->select(['projects.id', 'projects.name', 'projects.city_id', 'projects.program_id'])
            ->with(['city:id,name', 'program:id,name'])
            ->orderBy('projects.name')
            ->get();
    }

    /** @return Collection<int, Task> */
    #[Computed]
    public function tasks(): Collection
    {
        return Task::query()
            ->select(['id', 'proposal_id', 'milestone_id', 'title', 'status', 'priority', 'due_date', 'assigned_to', 'progress_percentage'])
            ->where('proposal_id', $this->proposalId)
            ->with('assignee:id,name,email,role')
            ->orderByRaw('due_date is null, due_date')
            ->get();
    }

    /**
     * Targets committed in this proposal.
     *
     * Filtered on the morph map key 'proposal', never a class name.
     *
     * @return Collection<int, PlanIndicator>
     */
    #[Computed]
    public function targets(): Collection
    {
        return PlanIndicator::query()
            ->select(['id', 'source_type', 'source_id', 'indicator_id', 'target', 'project_id', 'period'])
            ->where('source_type', 'proposal')
            ->where('source_id', $this->proposalId)
            ->with(['indicator:id,name,result_id', 'indicator.result:id,statement', 'project:id,name'])
            ->get();
    }

    /** @return array<int, array{key: string, label: string, count: int}> */
    #[Computed]
    public function tabs(): array
    {
        return [
            ['key' => 'status', 'label' => __('Status history'), 'count' => $this->proposal->status_notes_count],
            ['key' => 'funding', 'label' => __('Funding'), 'count' => $this->proposal->donor_proposals_count],
            ['key' => 'projects', 'label' => __('Projects'), 'count' => $this->proposal->projects_count],
            ['key' => 'tasks', 'label' => __('Tasks'), 'count' => $this->proposal->tasks_count],
            ['key' => 'targets', 'label' => __('Targets'), 'count' => $this->proposal->plan_indicators_count],
        ];
    }

    public function openStatusModal(): void
    {
        $this->resetValidation();
        $this->newStatus = $this->proposal->status->value;
        $this->statusContext = '';
        $this->statusDate = now()->toDateString();
        $this->showStatusModal = true;
    }

    /**
     * Move the proposal to a new status and record why.
     *
     * Both writes happen in one transaction. The live status and its audit
     * trail are the same vocabulary — proposal_status_notes.status casts to the
     * same ProposalStatus enum — so they must never be allowed to disagree.
     */
    public function changeStatus(): void
    {
        $this->statusContext = trim($this->statusContext);

        $validated = $this->validate([
            'newStatus' => ['required', new Enum(ProposalStatus::class)],
            'statusContext' => ['required', 'string'],
            'statusDate' => ['required', 'date'],
        ], [
            'newStatus.required' => __('Choose the new status.'),
            'statusContext.required' => __('Record why the status changed.'),
            'statusDate.required' => __('Set the date this change took effect.'),
        ]);

        DB::transaction(function () use ($validated): void {
            $proposal = Proposal::query()->findOrFail($this->proposalId);
            $proposal->fill(['status' => $validated['newStatus']])->save();

            ProposalStatusNote::query()->create([
                'proposal_id' => $this->proposalId,
                'status' => $validated['newStatus'],
                'context' => $validated['statusContext'],
                'date' => $validated['statusDate'],
            ]);
        });

        $this->showStatusModal = false;
        $this->statusContext = '';
        unset($this->proposal, $this->statusNotes);

        Flux::toast(variant: 'success', text: __('Status changed and recorded.'));
    }
};
?>

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('Proposals'), 'url' => route('proposals.index')],
            ['label' => $this->proposal->name],
        ]"
        :title="$this->proposal->name"
    >
        <x-slot:meta>
            <span class="tabular-nums">{{ $this->proposal->planned_date_begin?->format('M j, Y') }} – {{ $this->proposal->planned_date_end?->format('M j, Y') }}</span>
            @if ($this->proposal->actual_date_begin !== null)
                <span class="tabular-nums">{{ __('Actual: :begin', ['begin' => $this->proposal->actual_date_begin->format('M j, Y')]) }}</span>
            @endif
        </x-slot:meta>

        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.enum-badge :case="$this->proposal->status" />
                <span class="rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-secondary-soft">
                    {{ Str::title($this->proposal->phase->value) }}
                </span>
                <x-ui.button tone="primary" size="sm" icon="arrow-path" wire:click="openStatusModal" data-test="open-status-modal">{{ __('Change status') }}</x-ui.button>
            </div>
        </x-slot:actions>
    </x-ui.detail-header>

    @if ($this->budgetByCurrency !== [])
        <div class="grid gap-3 @2xl/detail:grid-cols-3">
            @foreach ($this->budgetByCurrency as $currencyCode => $total)
                <x-ui.stat-tile
                    :label="__('Committed budget')"
                    :value="number_format($total, 2)"
                    :unit="$currencyCode"
                    :scope="$currencyCode"
                    wire:key="budget-{{ $currencyCode }}"
                />
            @endforeach
        </div>
    @endif

    <x-ui.relation-tabs :tabs="$this->tabs" :active="$tab" wire-target="tab" />

    @if ($tab === 'status')
        <x-ui.panel :title="__('Status history')" :count="$this->statusNotes->count()" :subtitle="__('The audit trail for this proposal\'s status, newest first.')">
            @if ($this->statusNotes->isEmpty())
                <x-ui.empty-state icon="clock" :heading="__('No status history yet')" :body="__('Use Change status to record the first movement and why it happened.')" />
            @else
                <ol class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->statusNotes as $note)
                        <li class="flex flex-col gap-1.5 p-3.5" wire:key="status-note-{{ $note->id }}">
                            <div class="flex items-center justify-between gap-3">
                                <x-ui.enum-badge :case="$note->status" />
                                <time datetime="{{ $note->date?->toDateString() }}" class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $note->date?->format('M j, Y') }}</time>
                            </div>
                            @if ($note->context !== null)
                                <p class="text-sm text-secondary-dark dark:text-white">{{ $note->context }}</p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-ui.panel>
    @elseif ($tab === 'funding')
        <x-ui.panel :title="__('Funding agreements')" :count="$this->agreements->count()" :subtitle="__('Budgets are shown per currency. They are never added together.')">
            @if ($this->agreements->isEmpty())
                <x-ui.empty-state icon="banknotes" :heading="__('No funding agreements')" :body="__('Agreements signed against this proposal will appear here.')" />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->agreements as $agreement)
                        <article class="flex flex-col gap-2 p-3.5 @3xl/detail:flex-row @3xl/detail:items-center" wire:key="proposal-agreement-{{ $agreement->id }}">
                            <div class="min-w-0 @3xl/detail:me-auto">
                                <a href="{{ route('funding-agreements.show', $agreement) }}" wire:navigate class="text-sm font-semibold hover:underline">{{ $agreement->donor->name }}</a>
                                <p class="text-xs text-secondary-medium dark:text-secondary-soft">
                                    {{ $agreement->project_num ?? __('No donor reference') }}
                                    · {{ trans_choice(':count tranche|:count tranches', $agreement->transfers_count, ['count' => $agreement->transfers_count]) }}
                                </p>
                            </div>
                            <x-ui.money :amount="$agreement->budget" :currency="$agreement->currency" />
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    @elseif ($tab === 'projects')
        <x-ui.panel :title="__('Projects covered')" :count="$this->projects->count()" :subtitle="__('Manage these links from the project page.')">
            @if ($this->projects->isEmpty())
                <x-ui.empty-state icon="rectangle-stack" :heading="__('No projects linked')" :body="__('Projects this proposal funds will appear here.')" />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->projects as $project)
                        <article class="flex items-center gap-3 p-3.5" wire:key="proposal-project-{{ $project->id }}">
                            <div class="min-w-0 me-auto">
                                @if (Route::has('projects.show'))
                                    <a href="{{ route('projects.show', $project) }}" wire:navigate class="text-sm font-semibold hover:underline">{{ $project->name }}</a>
                                @else
                                    <p class="text-sm font-semibold">{{ $project->name }}</p>
                                @endif
                                <p class="text-xs text-secondary-medium dark:text-secondary-soft">{{ $project->city->name }} · {{ $project->program->name }}</p>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    @elseif ($tab === 'tasks')
        <x-ui.panel :title="__('Tasks')" :count="$this->tasks->count()">
            @if ($this->tasks->isEmpty())
                <x-ui.empty-state icon="clipboard-document-check" :heading="__('No tasks yet')" :body="__('Tasks delivering against this proposal will appear here.')" />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->tasks as $task)
                        <article class="flex flex-wrap items-center gap-3 p-3.5" wire:key="proposal-task-{{ $task->id }}">
                            <div class="min-w-0 me-auto">
                                @if (Route::has('tasks.show'))
                                    <a href="{{ route('tasks.show', $task) }}" wire:navigate class="text-sm font-semibold hover:underline">{{ $task->title }}</a>
                                @else
                                    <p class="text-sm font-semibold">{{ $task->title }}</p>
                                @endif
                                <p class="text-xs text-secondary-medium dark:text-secondary-soft">{{ $task->assignee->name }}</p>
                            </div>
                            <x-ui.enum-badge :case="$task->status" />
                            <span class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $task->progress_percentage }}%</span>
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    @else
        <x-ui.panel :title="__('Targets committed here')" :count="$this->targets->count()">
            @if ($this->targets->isEmpty())
                <x-ui.empty-state icon="chart-bar" :heading="__('No targets yet')" :body="__('Indicator targets committed in this proposal will appear here.')" />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->targets as $target)
                        <article class="flex flex-wrap items-center gap-3 p-3.5" wire:key="proposal-target-{{ $target->id }}">
                            <div class="min-w-0 me-auto">
                                <p class="text-sm font-semibold">{{ $target->indicator->name }}</p>
                                <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">
                                    {{ $target->project?->name ?? __('Organisation-wide') }}
                                </p>
                            </div>
                            <x-ui.enum-badge :case="$target->period" />
                            <span class="text-sm font-semibold tabular-nums">{{ number_format((float) $target->target, 2) }}</span>
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    @endif

    <flux:modal wire:model="showStatusModal" class="w-full max-w-xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="changeStatus" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ __('Change status') }}</flux:heading>
                <flux:subheading>{{ __('The new status and your reason are recorded together, as one entry in the audit trail.') }}</flux:subheading>
            </div>

            <x-ui.select wire:model="newStatus" :label="__('New status')" required>
                @foreach (ProposalStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value" :wire:key="'change-status-'.$statusCase->value">
                        {{ Str::title($statusCase->value) }} — {{ Str::title($statusCase->phase()->value) }}
                    </flux:select.option>
                @endforeach
            </x-ui.select>

            <flux:textarea wire:model="statusContext" :label="__('Why did it change?')" rows="3" required class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />

            <x-ui.input wire:model="statusDate" :label="__('Effective date')" type="date" required />

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="$set('showStatusModal', false)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-status-button">{{ __('Record change') }}</x-ui.button>
            </div>
        </form>
    </flux:modal>
</section>
```

- [ ] **Step 5: Remove the Delivery plan's forward guard — only if that plan has landed**

If `resources/views/pages/projects/⚡show.blade.php` exists, replace its guarded block:

```blade
@if (Route::has('proposals.show'))
    <a href="{{ route('proposals.show', $proposal) }}" wire:navigate class="truncate text-sm font-semibold hover:underline">{{ $proposal->name }}</a>
@else
    <p class="truncate text-sm font-semibold">{{ $proposal->name }}</p>
@endif
```

with the plain link:

```blade
<a href="{{ route('proposals.show', $proposal) }}" wire:navigate class="truncate text-sm font-semibold hover:underline">{{ $proposal->name }}</a>
```

If that file does not exist, skip this step and note it in the report.

- [ ] **Step 6: Run tests**

Run: `vendor/bin/pest tests/Feature/ProposalDetailTest.php tests/Feature/ProposalPipelineTest.php`
Expected: PASS — both. The `funding` tab test depends on Task 3's `funding-agreements.show` route; if it blocks, implement Task 4 Step 3 first.

- [ ] **Step 7: Format and stage**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/pages tests/Feature/ProposalDetailTest.php
```

Stop and report. Do not commit.

---

### Task 3: Donor directory and detail

**Files:**
- Create: `resources/views/pages/donors/⚡index.blade.php`
- Create: `resources/views/pages/donors/⚡show.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/DonorDirectoryTest.php`

**Interfaces:**
- Consumes: `x-ui.page-header`, `x-ui.detail-header`, `x-ui.panel`, `x-ui.empty-state`, `x-ui.money`, `x-ui.stat-tile`, `x-ui.enum-badge`, `x-ui.input`, `x-ui.button`.
- Produces: routes `donors.index`, `donors.show`; components `pages::donors.index`, `pages::donors.show`; index properties `$search`, `$showDonorModal`, `$editingDonorId`, `$deletingDonorId`, `$name`; methods `openCreateModal()`, `openEditModal(int)`, `saveDonor()`, `confirmDelete(int)`, `deleteDonor()`, `closeDonorModal()`, `canDelete(int): bool`; detail property `public int $donorId`.

`donors.name` is deliberately **not** unique (`.ai/rules/database-migrations.md`). Do not add a `unique` validation rule.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\Currency;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from the donor directory', function () {
    $this->get(route('donors.index'))->assertRedirect(route('login'));
});

test('donors can be searched', function () {
    $user = User::factory()->create();
    Donor::factory()->create(['name' => 'UNICEF']);
    Donor::factory()->create(['name' => 'ECHO']);

    Livewire::actingAs($user)
        ->test('pages::donors.index')
        ->assertSee('UNICEF')
        ->assertSee('ECHO')
        ->set('search', 'UNI')
        ->assertSee('UNICEF')
        ->assertDontSee('ECHO');
});

test('a donor can be created and two donors may share a name', function () {
    $user = User::factory()->create();
    Donor::factory()->create(['name' => 'Country Office']);

    Livewire::actingAs($user)
        ->test('pages::donors.index')
        ->call('openCreateModal')
        ->set('name', '  Country Office  ')
        ->call('saveDonor')
        ->assertHasNoErrors();

    expect(Donor::query()->where('name', 'Country Office')->count())->toBe(2);
});

test('creating a donor requires a name', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::donors.index')
        ->call('openCreateModal')
        ->set('name', '')
        ->call('saveDonor')
        ->assertHasErrors(['name']);
});

test('a donor with no agreements can be deleted', function () {
    $user = User::factory()->create();
    $donor = Donor::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::donors.index')
        ->call('confirmDelete', $donor->id)
        ->call('deleteDonor')
        ->assertHasNoErrors();

    expect(DB::table('donors')->find($donor->id))->toBeNull();
});

test('a donor holding an agreement is not deleted', function () {
    $user = User::factory()->create();
    $donor = Donor::factory()->create();
    DonorProposal::factory()->create([
        'donor_id' => $donor->id,
        'proposal_id' => Proposal::factory()->create()->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::donors.index')
        ->call('confirmDelete', $donor->id)
        ->call('deleteDonor');

    expect(DB::table('donors')->find($donor->id))->not->toBeNull();
});

test('donor detail totals committed budget per currency', function () {
    $user = User::factory()->create();
    $donor = Donor::factory()->create(['name' => 'UNICEF']);

    DonorProposal::factory()->create([
        'donor_id' => $donor->id,
        'proposal_id' => Proposal::factory()->create(['name' => 'First Proposal'])->id,
        'budget' => '500000.00',
        'currency' => Currency::Usd,
    ]);
    DonorProposal::factory()->create([
        'donor_id' => $donor->id,
        'proposal_id' => Proposal::factory()->create(['name' => 'Second Proposal'])->id,
        'budget' => '250000.00',
        'currency' => Currency::Eur,
    ]);

    $this->actingAs($user)
        ->get(route('donors.show', $donor))
        ->assertOk()
        ->assertSeeText('UNICEF')
        ->assertSeeText('First Proposal')
        ->assertSeeText('Second Proposal')
        ->assertSeeText('500,000.00')
        ->assertSeeText('250,000.00')
        ->assertDontSeeText('750,000.00');
});

test('donor detail renders a donor with no agreements', function () {
    $user = User::factory()->create();
    $donor = Donor::factory()->create(['name' => 'Quiet Donor']);

    $this->actingAs($user)
        ->get(route('donors.show', $donor))
        ->assertOk()
        ->assertSeeText('Quiet Donor')
        ->assertSeeText('No agreements yet');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/DonorDirectoryTest.php`
Expected: FAIL — `Route [donors.index] not defined.`

- [ ] **Step 3: Add the routes**

```php
    Route::livewire('donors', 'pages::donors.index')
        ->name('donors.index');

    Route::livewire('donors/{donor}', 'pages::donors.show')
        ->name('donors.show');
```

- [ ] **Step 4: Write the index**

`resources/views/pages/donors/⚡index.blade.php`:

```blade
<?php

use App\Models\Donor;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Donors')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    public bool $showDonorModal = false;
    public ?int $editingDonorId = null;
    public ?int $deletingDonorId = null;
    public string $name = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Donor> */
    #[Computed]
    public function donors(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Donor::query()
            ->select(['id', 'name', 'updated_at'])
            ->withCount('donorProposals')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15);
    }

    /**
     * Whether this donor may be deleted.
     *
     * donor_proposal.donor_id RESTRICTs: a donor that has signed anything stays.
     */
    public function canDelete(int $donorId): bool
    {
        $donor = Donor::query()->select(['id'])->withCount('donorProposals')->find($donorId);

        return $donor !== null && $donor->donor_proposals_count === 0;
    }

    public function openCreateModal(): void
    {
        $this->resetDonorForm();
        $this->showDonorModal = true;
    }

    public function openEditModal(int $donorId): void
    {
        $donor = Donor::query()->select(['id', 'name'])->findOrFail($donorId);

        $this->resetValidation();
        $this->editingDonorId = $donor->id;
        $this->name = $donor->name;
        $this->showDonorModal = true;
    }

    public function saveDonor(): void
    {
        $this->name = trim($this->name);

        // Deliberately no unique rule: donors.name is a plain non-unique
        // VARCHAR by standing decision.
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
        ], [
            'name.required' => __('Enter a donor name.'),
        ]);

        $donor = $this->editingDonorId === null
            ? new Donor
            : Donor::query()->findOrFail($this->editingDonorId);

        $donor->fill(['name' => $validated['name']])->save();

        $wasRecentlyCreated = $donor->wasRecentlyCreated;

        $this->showDonorModal = false;
        $this->resetDonorForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('Donor created.') : __('Donor updated.'),
        );
    }

    public function confirmDelete(int $donorId): void
    {
        $this->deletingDonorId = $donorId;
    }

    public function deleteDonor(): void
    {
        if ($this->deletingDonorId === null) {
            return;
        }

        if (! $this->canDelete($this->deletingDonorId)) {
            $this->deletingDonorId = null;

            Flux::toast(
                variant: 'danger',
                text: __('This donor has signed a funding agreement and cannot be deleted.'),
            );

            return;
        }

        Donor::query()->findOrFail($this->deletingDonorId)->delete();

        $this->deletingDonorId = null;
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('Donor deleted.'));
    }

    public function closeDonorModal(): void
    {
        $this->showDonorModal = false;
        $this->resetDonorForm();
    }

    protected function resetDonorForm(): void
    {
        $this->resetValidation();
        $this->editingDonorId = null;
        $this->name = '';
    }
};
?>

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="donor-directory-heading">
    <x-ui.page-header
        heading-id="donor-directory-heading"
        :title="__('Donors')"
        :count="$this->donors->total()"
        :description="__('Funders and the agreements they hold.')"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('Search donors')" :placeholder="__('Donor name')" wire:model.live.debounce.300ms="search" clearable />
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-donor-button">
                {{ __('Add donor') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel :subtitle="trans_choice('{1} :count matching donor|[2,*] :count matching donors', $this->donors->total(), ['count' => $this->donors->total()])">
        @if ($this->donors->isEmpty())
            <x-ui.empty-state
                icon="building-library"
                :heading="$search !== '' ? __('No matching donors') : __('No donors yet')"
                :body="$search !== '' ? __('Change the search and try again.') : __('Add the first donor.')"
            />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->donors as $donor)
                    <article class="flex items-center gap-3 p-3.5" wire:key="donor-{{ $donor->id }}">
                        <div class="min-w-0 me-auto">
                            <a href="{{ route('donors.show', $donor) }}" wire:navigate class="text-sm font-semibold hover:underline">{{ $donor->name }}</a>
                            <p class="text-xs text-secondary-medium dark:text-secondary-soft">
                                {{ trans_choice(':count agreement|:count agreements', $donor->donor_proposals_count, ['count' => $donor->donor_proposals_count]) }}
                            </p>
                        </div>

                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $donor->id }})" :aria-label="__('Edit :name', ['name' => $donor->name])" />

                        @if ($donor->donor_proposals_count === 0)
                            <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9!" wire:click="confirmDelete({{ $donor->id }})" :aria-label="__('Delete :name', ['name' => $donor->name])" />
                        @else
                            <flux:tooltip :content="__('Donors holding an agreement cannot be deleted.')">
                                <span class="inline-flex size-9 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
                                    <flux:icon.lock-closed class="size-4" />
                                </span>
                            </flux:tooltip>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif

        <x-slot:footer>
            <flux:pagination :paginator="$this->donors" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#donor-directory-heading" />
        </x-slot:footer>
    </x-ui.panel>

    <flux:modal wire:model="showDonorModal" class="w-full max-w-lg border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveDonor" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingDonorId === null ? __('Add donor') : __('Edit donor') }}</flux:heading>
                <flux:subheading>{{ __('Donor names need not be unique — two country offices may share one.') }}</flux:subheading>
            </div>

            <x-ui.input wire:model="name" :label="__('Donor name')" type="text" maxlength="255" required autofocus />

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeDonorModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-donor-button">
                    {{ $editingDonorId === null ? __('Create donor') : __('Save changes') }}
                </x-ui.button>
            </div>
        </form>
    </flux:modal>

    <flux:modal :open="$deletingDonorId !== null" wire:close="$set('deletingDonorId', null)" class="w-full max-w-md border border-secondary-light! bg-white! dark:bg-secondary-dark!">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Delete this donor?') }}</flux:heading>
            <flux:subheading>{{ __('Only a donor holding no funding agreement can be deleted.') }}</flux:subheading>
            <div class="flex justify-end gap-2 border-t border-secondary-soft pt-4 dark:border-secondary-medium">
                <x-ui.button type="button" wire:click="$set('deletingDonorId', null)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" wire:click="deleteDonor" data-test="confirm-delete-donor">{{ __('Delete donor') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</section>
```

- [ ] **Step 5: Write the detail page**

`resources/views/pages/donors/⚡show.blade.php`:

```blade
<?php

use App\Models\Donor;
use App\Models\DonorProposal;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Donor')] class extends Component {
    public int $donorId;

    public function mount(Donor $donor): void
    {
        $this->donorId = $donor->id;
    }

    #[Computed]
    public function donor(): Donor
    {
        return Donor::query()
            ->select(['id', 'name', 'updated_at'])
            ->withCount('donorProposals')
            ->findOrFail($this->donorId);
    }

    /** @return Collection<int, DonorProposal> */
    #[Computed]
    public function agreements(): Collection
    {
        return DonorProposal::query()
            ->select(['id', 'donor_id', 'proposal_id', 'budget', 'currency', 'project_num'])
            ->where('donor_id', $this->donorId)
            ->with('proposal:id,name,status')
            ->withCount('transfers')
            ->orderBy('id')
            ->get();
    }

    /**
     * Committed budget per currency. Grouped, never summed across currencies.
     *
     * @return array<string, float>
     */
    #[Computed]
    public function budgetByCurrency(): array
    {
        return $this->agreements
            ->groupBy(fn (DonorProposal $agreement): string => $agreement->currency->value)
            ->map(fn (Collection $group): float => (float) $group->sum(fn (DonorProposal $agreement): float => (float) $agreement->budget))
            ->all();
    }
};
?>

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('Donors'), 'url' => route('donors.index')],
            ['label' => $this->donor->name],
        ]"
        :title="$this->donor->name"
    >
        <x-slot:meta>
            <span>{{ trans_choice(':count agreement|:count agreements', $this->donor->donor_proposals_count, ['count' => $this->donor->donor_proposals_count]) }}</span>
        </x-slot:meta>
    </x-ui.detail-header>

    @if ($this->budgetByCurrency !== [])
        <div class="grid gap-3 @2xl/detail:grid-cols-3">
            @foreach ($this->budgetByCurrency as $currencyCode => $total)
                <x-ui.stat-tile :label="__('Committed')" :value="number_format($total, 2)" :unit="$currencyCode" :scope="$currencyCode" wire:key="donor-budget-{{ $currencyCode }}" />
            @endforeach
        </div>
    @endif

    <x-ui.panel :title="__('Funding agreements')" :count="$this->agreements->count()" :subtitle="__('Budgets are shown per currency and never added together.')">
        @if ($this->agreements->isEmpty())
            <x-ui.empty-state icon="banknotes" :heading="__('No agreements yet')" :body="__('Agreements signed by this donor will appear here.')" />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->agreements as $agreement)
                    <article class="flex flex-col gap-2 p-3.5 @3xl/detail:flex-row @3xl/detail:items-center" wire:key="donor-agreement-{{ $agreement->id }}">
                        <div class="min-w-0 @3xl/detail:me-auto">
                            <a href="{{ route('funding-agreements.show', $agreement) }}" wire:navigate class="text-sm font-semibold hover:underline">{{ $agreement->proposal->name }}</a>
                            <p class="text-xs text-secondary-medium dark:text-secondary-soft">
                                {{ $agreement->project_num ?? __('No donor reference') }}
                                · {{ trans_choice(':count tranche|:count tranches', $agreement->transfers_count, ['count' => $agreement->transfers_count]) }}
                            </p>
                        </div>
                        <x-ui.enum-badge :case="$agreement->proposal->status" />
                        <x-ui.money :amount="$agreement->budget" :currency="$agreement->currency" />
                    </article>
                @endforeach
            </div>
        @endif
    </x-ui.panel>
</section>
```

- [ ] **Step 6: Run tests**

Run: `vendor/bin/pest tests/Feature/DonorDirectoryTest.php`
Expected: the index tests PASS. The detail page links to `funding-agreements.show`; implement Task 4 Step 3 if the run blocks, then return.

- [ ] **Step 7: Format and stage**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/pages/donors tests/Feature/DonorDirectoryTest.php
```

Stop and report. Do not commit.

---

### Task 4: Funding agreements

**Files:**
- Create: `resources/views/pages/funding-agreements/⚡index.blade.php`
- Create: `resources/views/pages/funding-agreements/⚡show.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/FundingAgreementTest.php`

**Interfaces:**
- Consumes: `x-ui.page-header`, `x-ui.detail-header`, `x-ui.panel`, `x-ui.empty-state`, `x-ui.money`, `x-ui.stat-tile`, `x-ui.enum-badge`, `x-ui.input`, `x-ui.select`, `x-ui.button`.
- Produces: routes `funding-agreements.index`, `funding-agreements.show`; components `pages::funding-agreements.index`, `pages::funding-agreements.show`; index properties `$search`, `$donor`, `$currency`, `$showAgreementModal`, `$editingAgreementId`, form fields `$agreementDonorId`, `$agreementProposalId`, `$budget`, `$agreementCurrency`, `$projectNum`; methods `openCreateModal()`, `openEditModal(int)`, `saveAgreement()`, `closeAgreementModal()`, `clearFilters()`; detail property `public int $agreementId`.

**There is no delete.** `transfers_tracking.donor_proposal_id` cascades, so deleting an agreement erases every transfer recorded under it. No confirm modal, no trash button, no `deleteAgreement()` method — the absence is asserted by a test.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\Account;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Proposal;
use App\Models\TransfersTracking;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from funding agreements', function () {
    $this->get(route('funding-agreements.index'))->assertRedirect(route('login'));
});

test('agreements can be filtered by donor and currency', function () {
    $user = User::factory()->create();
    $unicef = Donor::factory()->create(['name' => 'UNICEF']);
    $echo = Donor::factory()->create(['name' => 'ECHO']);

    DonorProposal::factory()->create([
        'donor_id' => $unicef->id,
        'proposal_id' => Proposal::factory()->create(['name' => 'Dollar Proposal'])->id,
        'currency' => Currency::Usd,
    ]);
    DonorProposal::factory()->create([
        'donor_id' => $echo->id,
        'proposal_id' => Proposal::factory()->create(['name' => 'Euro Proposal'])->id,
        'currency' => Currency::Eur,
    ]);

    Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->assertSee('Dollar Proposal')
        ->assertSee('Euro Proposal')
        ->set('donor', (string) $unicef->id)
        ->assertSee('Dollar Proposal')
        ->assertDontSee('Euro Proposal')
        ->call('clearFilters')
        ->set('currency', Currency::Eur->value)
        ->assertDontSee('Dollar Proposal')
        ->assertSee('Euro Proposal');
});

test('an agreement can be created with a budget and currency', function () {
    $user = User::factory()->create();
    $donor = Donor::factory()->create();
    $proposal = Proposal::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->call('openCreateModal')
        ->set('agreementDonorId', (string) $donor->id)
        ->set('agreementProposalId', (string) $proposal->id)
        ->set('budget', '750000.00')
        ->set('agreementCurrency', Currency::Usd->value)
        ->set('projectNum', 'SYR-2026-014')
        ->call('saveAgreement')
        ->assertHasNoErrors();

    $agreement = DonorProposal::query()->where('project_num', 'SYR-2026-014')->sole();

    expect($agreement->currency)->toBe(Currency::Usd)
        ->and((float) $agreement->budget)->toBe(750000.0);
});

test('a negative budget is rejected', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->call('openCreateModal')
        ->set('agreementDonorId', (string) Donor::factory()->create()->id)
        ->set('agreementProposalId', (string) Proposal::factory()->create()->id)
        ->set('budget', '-1')
        ->set('agreementCurrency', Currency::Usd->value)
        ->call('saveAgreement')
        ->assertHasErrors(['budget']);
});

test('funding agreements offer no delete because transfers would cascade', function () {
    $user = User::factory()->create();
    DonorProposal::factory()->create();

    $component = Livewire::actingAs($user)->test('pages::funding-agreements.index');

    $component->assertDontSee('wire:click="confirmDelete')->assertDontSee('Delete agreement');

    expect(method_exists($component->instance(), 'deleteAgreement'))->toBeFalse();
});

test('agreement detail lists its tranches and totals received per currency', function () {
    $user = User::factory()->create();
    $agreement = DonorProposal::factory()->create([
        'budget' => '500000.00',
        'currency' => Currency::Usd,
    ]);
    $account = Account::factory()->create(['name' => 'Operations USD']);

    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'account_id' => $account->id,
        'payment_amount' => '250000.00',
        'amount_transfered_currency' => Currency::Usd,
        'net_amount_received' => '248000.00',
        'amount_received_currency' => Currency::Usd,
        'ar_status' => ArStatus::Received,
    ]);

    $this->actingAs($user)
        ->get(route('funding-agreements.show', $agreement))
        ->assertOk()
        ->assertSeeText('250,000.00')
        ->assertSeeText('248,000.00')
        ->assertSeeText('Operations USD');
});

test('agreement detail renders an agreement with no tranches', function () {
    $user = User::factory()->create();
    $agreement = DonorProposal::factory()->create();

    $this->actingAs($user)
        ->get(route('funding-agreements.show', $agreement))
        ->assertOk()
        ->assertSeeText('No tranches scheduled');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/FundingAgreementTest.php`
Expected: FAIL — `Route [funding-agreements.index] not defined.`

- [ ] **Step 3: Add the routes**

```php
    Route::livewire('funding-agreements', 'pages::funding-agreements.index')
        ->name('funding-agreements.index');

    Route::livewire('funding-agreements/{donorProposal}', 'pages::funding-agreements.show')
        ->name('funding-agreements.show');
```

Note the parameter is `{donorProposal}`, matching `mount(DonorProposal $donorProposal)`.

- [ ] **Step 4: Write the index**

`resources/views/pages/funding-agreements/⚡index.blade.php`:

```blade
<?php

use App\Enums\Currency;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Proposal;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Funding Agreements')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $donor = '';

    #[Url(except: '')]
    public string $currency = '';

    public bool $showAgreementModal = false;
    public ?int $editingAgreementId = null;
    public string $agreementDonorId = '';
    public string $agreementProposalId = '';
    public string $budget = '';
    public string $agreementCurrency = 'USD';
    public string $projectNum = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedDonor(): void
    {
        $this->resetPage();
    }

    public function updatedCurrency(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->donor = '';
        $this->currency = '';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, DonorProposal> */
    #[Computed]
    public function agreements(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return DonorProposal::query()
            ->select(['id', 'donor_id', 'proposal_id', 'budget', 'currency', 'project_num', 'updated_at'])
            ->with(['donor:id,name', 'proposal:id,name,status'])
            ->withCount('transfers')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('project_num', 'like', '%'.$search.'%')
                        ->orWhereHas('donor', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
                        ->orWhereHas('proposal', fn ($query) => $query->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->when($this->donor !== '', fn ($query) => $query->where('donor_id', (int) $this->donor))
            ->when($this->currency !== '', fn ($query) => $query->where('currency', $this->currency))
            ->orderByDesc('id')
            ->paginate(15);
    }

    /**
     * Committed budget per currency across the filtered set.
     *
     * @return array<string, float>
     */
    #[Computed]
    public function budgetByCurrency(): array
    {
        return $this->agreements->getCollection()
            ->groupBy(fn (DonorProposal $agreement): string => $agreement->currency->value)
            ->map(fn (Collection $group): float => (float) $group->sum(fn (DonorProposal $agreement): float => (float) $agreement->budget))
            ->all();
    }

    /** @return Collection<int, Donor> */
    #[Computed]
    public function donorOptions(): Collection
    {
        return Donor::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    /** @return Collection<int, Proposal> */
    #[Computed]
    public function proposalOptions(): Collection
    {
        return Proposal::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    public function openCreateModal(): void
    {
        $this->resetAgreementForm();
        $this->showAgreementModal = true;
    }

    public function openEditModal(int $agreementId): void
    {
        $agreement = DonorProposal::query()
            ->select(['id', 'donor_id', 'proposal_id', 'budget', 'currency', 'project_num'])
            ->findOrFail($agreementId);

        $this->resetValidation();
        $this->editingAgreementId = $agreement->id;
        $this->agreementDonorId = (string) $agreement->donor_id;
        $this->agreementProposalId = (string) $agreement->proposal_id;
        $this->budget = (string) $agreement->budget;
        $this->agreementCurrency = $agreement->currency->value;
        $this->projectNum = $agreement->project_num ?? '';
        $this->showAgreementModal = true;
    }

    public function saveAgreement(): void
    {
        $this->projectNum = trim($this->projectNum);

        $validated = $this->validate([
            'agreementDonorId' => ['required', 'integer', Rule::exists(Donor::class, 'id')],
            'agreementProposalId' => ['required', 'integer', Rule::exists(Proposal::class, 'id')],
            'budget' => ['required', 'numeric', 'min:0'],
            'agreementCurrency' => ['required', new Enum(Currency::class)],
            'projectNum' => ['nullable', 'string', 'max:50'],
        ], [
            'agreementDonorId.required' => __('Select the donor.'),
            'agreementProposalId.required' => __('Select the proposal being funded.'),
            'budget.required' => __('Enter the agreed budget.'),
            'budget.min' => __('A budget cannot be negative.'),
            'agreementCurrency.required' => __('Select the currency this budget is stated in.'),
        ]);

        $agreement = $this->editingAgreementId === null
            ? new DonorProposal
            : DonorProposal::query()->findOrFail($this->editingAgreementId);

        $agreement->fill([
            'donor_id' => (int) $validated['agreementDonorId'],
            'proposal_id' => (int) $validated['agreementProposalId'],
            'budget' => $validated['budget'],
            'currency' => $validated['agreementCurrency'],
            'project_num' => $validated['projectNum'] === '' ? null : $validated['projectNum'],
        ])->save();

        $wasRecentlyCreated = $agreement->wasRecentlyCreated;

        $this->showAgreementModal = false;
        $this->resetAgreementForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('Funding agreement recorded.') : __('Funding agreement updated.'),
        );
    }

    public function closeAgreementModal(): void
    {
        $this->showAgreementModal = false;
        $this->resetAgreementForm();
    }

    protected function resetAgreementForm(): void
    {
        $this->resetValidation();
        $this->editingAgreementId = null;
        $this->agreementDonorId = '';
        $this->agreementProposalId = '';
        $this->budget = '';
        $this->agreementCurrency = 'USD';
        $this->projectNum = '';
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $donor !== '' || $currency !== '';
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="agreement-directory-heading">
    <x-ui.page-header
        heading-id="agreement-directory-heading"
        :title="__('Funding Agreements')"
        :count="$this->agreements->total()"
        :description="__('What each donor committed, against which proposal, in which currency.')"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('Search agreements')" :placeholder="__('Reference, donor, or proposal')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.select :label="__('Donor')" wire:model.live="donor">
                <flux:select.option value="">{{ __('All donors') }}</flux:select.option>
                @foreach ($this->donorOptions as $donorOption)
                    <flux:select.option :value="$donorOption->id" :wire:key="'agr-donor-'.$donorOption->id">{{ $donorOption->name }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('Currency')" wire:model.live="currency">
                <flux:select.option value="">{{ __('All currencies') }}</flux:select.option>
                @foreach (Currency::cases() as $currencyCase)
                    <flux:select.option :value="$currencyCase->value" :wire:key="'agr-currency-'.$currencyCase->value">{{ $currencyCase->value }}</flux:select.option>
                @endforeach
            </x-ui.select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-agreement-button">
                {{ __('Record agreement') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($this->budgetByCurrency !== [])
        <div class="grid gap-3 @2xl/directory:grid-cols-3">
            @foreach ($this->budgetByCurrency as $currencyCode => $total)
                <x-ui.stat-tile :label="__('Committed on this page')" :value="number_format($total, 2)" :unit="$currencyCode" :scope="$currencyCode" wire:key="agr-total-{{ $currencyCode }}" />
            @endforeach
        </div>
    @endif

    <x-ui.panel :subtitle="trans_choice('{1} :count matching agreement|[2,*] :count matching agreements', $this->agreements->total(), ['count' => $this->agreements->total()])">
        <x-slot:toolbar>
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('Reset filters') }}</flux:button>
            @endif
        </x-slot:toolbar>

        @if ($this->agreements->isEmpty())
            <x-ui.empty-state
                icon="banknotes"
                :heading="$filtersAreActive ? __('No matching agreements') : __('No agreements yet')"
                :body="$filtersAreActive ? __('Change the search or filters and try again.') : __('Record the first funding agreement.')"
            />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->agreements as $agreement)
                    <article class="flex flex-col gap-3 p-3.5 @4xl/directory:flex-row @4xl/directory:items-center" wire:key="agreement-{{ $agreement->id }}">
                        <div class="min-w-0 @4xl/directory:me-auto">
                            <a href="{{ route('funding-agreements.show', $agreement) }}" wire:navigate class="text-sm font-semibold hover:underline">
                                {{ $agreement->donor->name }} · {{ $agreement->proposal->name }}
                            </a>
                            <p class="text-xs text-secondary-medium dark:text-secondary-soft">
                                {{ $agreement->project_num ?? __('No donor reference') }}
                                · {{ trans_choice(':count tranche|:count tranches', $agreement->transfers_count, ['count' => $agreement->transfers_count]) }}
                            </p>
                        </div>

                        <x-ui.enum-badge :case="$agreement->proposal->status" />
                        <x-ui.money :amount="$agreement->budget" :currency="$agreement->currency" class="@4xl/directory:w-40 @4xl/directory:justify-end" />

                        {{-- No delete: transfers_tracking cascades from this row, so
                             deleting an agreement would erase the money received. --}}
                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9! shrink-0" wire:click="openEditModal({{ $agreement->id }})" :aria-label="__('Edit agreement')" />
                    </article>
                @endforeach
            </div>
        @endif

        <x-slot:footer>
            <flux:pagination :paginator="$this->agreements" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#agreement-directory-heading" />
        </x-slot:footer>
    </x-ui.panel>

    <flux:modal wire:model="showAgreementModal" class="w-full max-w-2xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveAgreement" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingAgreementId === null ? __('Record agreement') : __('Edit agreement') }}</flux:heading>
                <flux:subheading>{{ __('The currency is part of the amount. Budgets in different currencies are never added together.') }}</flux:subheading>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select wire:model="agreementDonorId" :label="__('Donor')" required>
                    <flux:select.option value="">{{ __('Select a donor') }}</flux:select.option>
                    @foreach ($this->donorOptions as $donorOption)
                        <flux:select.option :value="$donorOption->id" :wire:key="'agr-donor-option-'.$donorOption->id">{{ $donorOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="agreementProposalId" :label="__('Proposal')" required>
                    <flux:select.option value="">{{ __('Select a proposal') }}</flux:select.option>
                    @foreach ($this->proposalOptions as $proposalOption)
                        <flux:select.option :value="$proposalOption->id" :wire:key="'agr-proposal-option-'.$proposalOption->id">{{ $proposalOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.input wire:model="budget" :label="__('Agreed budget')" type="number" step="0.01" min="0" required />

                <x-ui.select wire:model="agreementCurrency" :label="__('Currency')" required>
                    @foreach (Currency::cases() as $currencyCase)
                        <flux:select.option :value="$currencyCase->value" :wire:key="'agr-currency-option-'.$currencyCase->value">{{ $currencyCase->value }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.input wire:model="projectNum" :label="__('Donor reference')" type="text" maxlength="50" />
            </div>

            <div class="rounded-lg border border-secondary-soft bg-secondary-soft/50 p-3 text-sm text-secondary-medium dark:border-secondary-medium dark:bg-secondary-medium/50 dark:text-secondary-soft">
                <div class="flex items-start gap-2.5">
                    <flux:icon.information-circle class="mt-0.5 size-4 shrink-0" />
                    <p>{{ __('Agreements cannot be deleted from this system. Every transfer recorded under one would be erased with it.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeAgreementModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-agreement-button">
                    {{ $editingAgreementId === null ? __('Record agreement') : __('Save changes') }}
                </x-ui.button>
            </div>
        </form>
    </flux:modal>
</section>
```

- [ ] **Step 5: Write the detail page**

`resources/views/pages/funding-agreements/⚡show.blade.php`:

```blade
<?php

use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Funding Agreement')] class extends Component {
    public int $agreementId;

    public function mount(DonorProposal $donorProposal): void
    {
        $this->agreementId = $donorProposal->id;
    }

    #[Computed]
    public function agreement(): DonorProposal
    {
        return DonorProposal::query()
            ->select(['id', 'donor_id', 'proposal_id', 'budget', 'currency', 'project_num', 'updated_at'])
            ->with(['donor:id,name', 'proposal:id,name,status'])
            ->withCount('transfers')
            ->findOrFail($this->agreementId);
    }

    /** @return Collection<int, TransfersTracking> */
    #[Computed]
    public function transfers(): Collection
    {
        return TransfersTracking::query()
            ->select([
                'id', 'donor_proposal_id', 'account_id', 'payment_amount', 'amount_transfered_currency',
                'scheduled_date', 'transfer_date', 'fees', 'fees_currency', 'amount_received',
                'exchange_rate', 'net_amount_received', 'amount_received_currency', 'receive_date', 'ar_status',
            ])
            ->where('donor_proposal_id', $this->agreementId)
            // account_id is nullable: a tranche may be scheduled before an
            // account is assigned.
            ->with('account:id,name,is_active')
            ->orderByRaw('scheduled_date is null, scheduled_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Net received per currency.
     *
     * Grouped on amount_received_currency, which is a different column from
     * the transferred currency: a USD payment can land as SYP.
     *
     * @return array<string, float>
     */
    #[Computed]
    public function receivedByCurrency(): array
    {
        return $this->transfers
            ->filter(fn (TransfersTracking $transfer): bool => $transfer->net_amount_received !== null)
            ->groupBy(fn (TransfersTracking $transfer): string => $transfer->amount_received_currency->value)
            ->map(fn (Collection $group): float => (float) $group->sum(fn (TransfersTracking $transfer): float => (float) $transfer->net_amount_received))
            ->all();
    }
};
?>

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('Funding Agreements'), 'url' => route('funding-agreements.index')],
            ['label' => $this->agreement->donor->name],
        ]"
        :title="$this->agreement->donor->name.' · '.$this->agreement->proposal->name"
        :subtitle="$this->agreement->project_num ?? __('No donor reference')"
    >
        <x-slot:meta>
            <a href="{{ route('donors.show', $this->agreement->donor) }}" wire:navigate class="font-medium hover:underline">{{ __('Donor page') }}</a>
            <a href="{{ route('proposals.show', $this->agreement->proposal) }}" wire:navigate class="font-medium hover:underline">{{ __('Proposal page') }}</a>
        </x-slot:meta>

        <x-slot:actions>
            <x-ui.enum-badge :case="$this->agreement->proposal->status" />
        </x-slot:actions>
    </x-ui.detail-header>

    <div class="grid gap-3 @2xl/detail:grid-cols-3">
        <x-ui.stat-tile
            :label="__('Committed')"
            :value="number_format((float) $this->agreement->budget, 2)"
            :unit="$this->agreement->currency->value"
            :scope="$this->agreement->currency->value"
        />

        @foreach ($this->receivedByCurrency as $currencyCode => $total)
            <x-ui.stat-tile :label="__('Net received')" :value="number_format($total, 2)" :unit="$currencyCode" :scope="$currencyCode" wire:key="received-{{ $currencyCode }}" />
        @endforeach
    </div>

    <x-ui.panel :title="__('Tranches')" :count="$this->transfers->count()" :subtitle="__('Scheduled first. The transferred and received currencies can differ.')">
        @if ($this->transfers->isEmpty())
            <x-ui.empty-state icon="arrow-right-circle" :heading="__('No tranches scheduled')" :body="__('Payments scheduled under this agreement will appear here.')" />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->transfers as $transfer)
                    <article class="grid gap-2 p-3.5 @3xl/detail:grid-cols-[1fr_auto_auto_auto]" wire:key="agreement-transfer-{{ $transfer->id }}">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold">
                                {{ $transfer->scheduled_date?->format('M j, Y') ?? __('Unscheduled') }}
                            </p>
                            <p class="text-xs text-secondary-medium dark:text-secondary-soft">
                                @if ($transfer->account === null)
                                    <span class="italic">{{ __('No account assigned') }}</span>
                                @else
                                    {{ $transfer->account->name }}@unless ($transfer->account->is_active) · {{ __('closed') }}@endunless
                                @endif
                                @if ($transfer->transfer_date !== null)
                                    · {{ __('sent :date', ['date' => $transfer->transfer_date->format('M j')]) }}
                                @endif
                            </p>
                        </div>

                        <x-ui.money :amount="$transfer->payment_amount" :currency="$transfer->amount_transfered_currency" />

                        <div class="text-xs text-secondary-medium tabular-nums dark:text-secondary-soft">
                            @if ($transfer->net_amount_received !== null)
                                <x-ui.money :amount="$transfer->net_amount_received" :currency="$transfer->amount_received_currency" />
                                <p>{{ __('rate :rate', ['rate' => rtrim(rtrim((string) $transfer->exchange_rate, '0'), '.')]) }}</p>
                            @else
                                <span>—</span>
                            @endif
                        </div>

                        <x-ui.enum-badge :case="$transfer->ar_status" />
                    </article>
                @endforeach
            </div>
        @endif
    </x-ui.panel>
</section>
```

- [ ] **Step 6: Run tests**

Run: `vendor/bin/pest tests/Feature/FundingAgreementTest.php tests/Feature/DonorDirectoryTest.php tests/Feature/ProposalDetailTest.php`
Expected: PASS — all three, including the forward links from Tasks 2 and 3.

- [ ] **Step 7: Format and stage**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/pages/funding-agreements tests/Feature/FundingAgreementTest.php
```

Stop and report. Do not commit.

---

### Task 5: Transfer tracking

**Files:**
- Create: `resources/views/pages/transfers/⚡index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/TransferTrackingTest.php`

**Interfaces:**
- Consumes: `x-ui.page-header`, `x-ui.panel`, `x-ui.empty-state`, `x-ui.money`, `x-ui.stat-tile`, `x-ui.enum-badge`, `x-ui.input`, `x-ui.select`, `x-ui.button`.
- Produces: route `transfers.index`; component `pages::transfers.index`; public properties `$search`, `$arStatus`, `$account`, `$showTransferModal`, `$editingTransferId`, form fields `$transferAgreementId`, `$transferAccountId`, `$paymentAmount`, `$transferredCurrency`, `$scheduledDate`, `$transferDate`, `$fees`, `$feesCurrency`, `$amountReceived`, `$exchangeRate`, `$netAmountReceived`, `$receivedCurrency`, `$receiveDate`, `$transferArStatus`; methods `openCreateModal()`, `openEditModal(int)`, `saveTransfer()`, `closeTransferModal()`, `clearFilters()`.

**This table has three independent currency columns.** `amount_transfered_currency`, `fees_currency` and `amount_received_currency` are separate by design — a USD payment can incur EUR fees and land as SYP. Each amount is rendered with its own currency and no two are added together.

**There is no delete.** Money records do not disappear. The absence is asserted.

`account_id` is nullable with RESTRICT: a tranche can be scheduled before an account exists, but an account money has passed through can never be removed.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\Account;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from transfer tracking', function () {
    $this->get(route('transfers.index'))->assertRedirect(route('login'));
});

test('transfers can be filtered by receipt status and account', function () {
    $user = User::factory()->create();
    $operations = Account::factory()->create(['name' => 'Operations USD']);
    $reserve = Account::factory()->create(['name' => 'Reserve EUR']);

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
        ->set('arStatus', ArStatus::Pending->value)
        ->assertDontSee('111,111.00')
        ->assertSee('222,222.00')
        ->call('clearFilters')
        ->set('account', (string) $operations->id)
        ->assertSee('111,111.00')
        ->assertDontSee('222,222.00');
});

test('a tranche can be scheduled with no account assigned', function () {
    $user = User::factory()->create();
    $agreement = DonorProposal::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->call('openCreateModal')
        ->set('transferAgreementId', (string) $agreement->id)
        ->set('transferAccountId', '')
        ->set('paymentAmount', '100000.00')
        ->set('transferredCurrency', Currency::Usd->value)
        ->set('scheduledDate', '2026-05-01')
        ->set('transferArStatus', ArStatus::Pending->value)
        ->call('saveTransfer')
        ->assertHasNoErrors();

    $transfer = TransfersTracking::query()->where('donor_proposal_id', $agreement->id)->sole();

    expect($transfer->account_id)->toBeNull()
        ->and($transfer->ar_status)->toBe(ArStatus::Pending);
});

test('the three currency columns are recorded independently', function () {
    $user = User::factory()->create();
    $agreement = DonorProposal::factory()->create();
    $account = Account::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->call('openCreateModal')
        ->set('transferAgreementId', (string) $agreement->id)
        ->set('transferAccountId', (string) $account->id)
        ->set('paymentAmount', '100000.00')
        ->set('transferredCurrency', Currency::Usd->value)
        ->set('fees', '250.00')
        ->set('feesCurrency', Currency::Eur->value)
        ->set('amountReceived', '99750.00')
        ->set('netAmountReceived', '99500.00')
        ->set('receivedCurrency', Currency::Syp->value)
        ->set('exchangeRate', '1.000000')
        ->set('scheduledDate', '2026-05-01')
        ->set('transferArStatus', ArStatus::Received->value)
        ->call('saveTransfer')
        ->assertHasNoErrors();

    $transfer = TransfersTracking::query()->where('donor_proposal_id', $agreement->id)->sole();

    expect($transfer->amount_transfered_currency)->toBe(Currency::Usd)
        ->and($transfer->fees_currency)->toBe(Currency::Eur)
        ->and($transfer->amount_received_currency)->toBe(Currency::Syp);
});

test('a negative payment amount is rejected', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->call('openCreateModal')
        ->set('transferAgreementId', (string) DonorProposal::factory()->create()->id)
        ->set('paymentAmount', '-5')
        ->set('transferredCurrency', Currency::Usd->value)
        ->set('transferArStatus', ArStatus::Pending->value)
        ->call('saveTransfer')
        ->assertHasErrors(['paymentAmount']);
});

test('transfers offer no delete', function () {
    $user = User::factory()->create();
    TransfersTracking::factory()->create();

    $component = Livewire::actingAs($user)->test('pages::transfers.index');

    $component->assertDontSee('wire:click="confirmDelete');

    expect(method_exists($component->instance(), 'deleteTransfer'))->toBeFalse();
});

test('transfer totals are never summed across currencies', function () {
    $user = User::factory()->create();

    TransfersTracking::factory()->create([
        'payment_amount' => '100000.00',
        'amount_transfered_currency' => Currency::Usd,
    ]);
    TransfersTracking::factory()->create([
        'payment_amount' => '50000.00',
        'amount_transfered_currency' => Currency::Eur,
    ]);

    Livewire::actingAs($user)
        ->test('pages::transfers.index')
        ->assertSee('100,000.00')
        ->assertSee('50,000.00')
        ->assertDontSee('150,000.00');
});

test('transfer tracking renders with no transfers', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::transfers.index')->assertSee('No transfers recorded');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/TransferTrackingTest.php`
Expected: FAIL — `Route [transfers.index] not defined.`

- [ ] **Step 3: Add the route**

```php
    Route::livewire('transfers', 'pages::transfers.index')
        ->name('transfers.index');
```

- [ ] **Step 4: Write the page**

`resources/views/pages/transfers/⚡index.blade.php`:

```blade
<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\Account;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Transfers')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $arStatus = '';

    #[Url(except: '')]
    public string $account = '';

    public bool $showTransferModal = false;
    public ?int $editingTransferId = null;

    public string $transferAgreementId = '';
    public string $transferAccountId = '';
    public string $paymentAmount = '';
    public string $transferredCurrency = 'USD';
    public string $scheduledDate = '';
    public string $transferDate = '';
    public string $fees = '0';
    public string $feesCurrency = 'USD';
    public string $amountReceived = '';
    public string $exchangeRate = '1.000000';
    public string $netAmountReceived = '';
    public string $receivedCurrency = 'USD';
    public string $receiveDate = '';
    public string $transferArStatus = 'pending';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedArStatus(): void
    {
        $this->resetPage();
    }

    public function updatedAccount(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->arStatus = '';
        $this->account = '';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, TransfersTracking> */
    #[Computed]
    public function transfers(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return TransfersTracking::query()
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
                'account:id,name,is_active',
            ])
            ->when($search !== '', function ($query) use ($search): void {
                $query->whereHas('donorProposal', function ($query) use ($search): void {
                    $query
                        ->where('project_num', 'like', '%'.$search.'%')
                        ->orWhereHas('donor', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
                        ->orWhereHas('proposal', fn ($query) => $query->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->when($this->arStatus !== '', fn ($query) => $query->where('ar_status', $this->arStatus))
            ->when($this->account !== '', fn ($query) => $query->where('account_id', (int) $this->account))
            ->orderByRaw('scheduled_date is null, scheduled_date desc')
            ->orderByDesc('id')
            ->paginate(15);
    }

    /**
     * Scheduled payment totals per transferred currency, for this page.
     *
     * Three currency columns exist on this table and they are independent: a
     * USD payment can incur EUR fees and land as SYP. Nothing is added across
     * currencies anywhere on this page.
     *
     * @return array<string, float>
     */
    #[Computed]
    public function paymentByCurrency(): array
    {
        return $this->transfers->getCollection()
            ->groupBy(fn (TransfersTracking $transfer): string => $transfer->amount_transfered_currency->value)
            ->map(fn (Collection $group): float => (float) $group->sum(fn (TransfersTracking $transfer): float => (float) $transfer->payment_amount))
            ->all();
    }

    /** @return Collection<int, Account> */
    #[Computed]
    public function accountOptions(): Collection
    {
        return Account::query()->select(['id', 'name', 'is_active'])->orderBy('name')->orderBy('id')->get();
    }

    /** @return Collection<int, DonorProposal> */
    #[Computed]
    public function agreementOptions(): Collection
    {
        return DonorProposal::query()
            ->select(['id', 'donor_id', 'proposal_id', 'project_num'])
            ->with(['donor:id,name', 'proposal:id,name'])
            ->orderBy('id')
            ->get();
    }

    public function openCreateModal(): void
    {
        $this->resetTransferForm();
        $this->showTransferModal = true;
    }

    public function openEditModal(int $transferId): void
    {
        $transfer = TransfersTracking::query()->findOrFail($transferId);

        $this->resetValidation();
        $this->editingTransferId = $transfer->id;
        $this->transferAgreementId = (string) $transfer->donor_proposal_id;
        $this->transferAccountId = (string) ($transfer->account_id ?? '');
        $this->paymentAmount = (string) $transfer->payment_amount;
        $this->transferredCurrency = $transfer->amount_transfered_currency->value;
        $this->scheduledDate = $transfer->scheduled_date?->toDateString() ?? '';
        $this->transferDate = $transfer->transfer_date?->toDateString() ?? '';
        $this->fees = (string) $transfer->fees;
        $this->feesCurrency = $transfer->fees_currency->value;
        $this->amountReceived = (string) ($transfer->amount_received ?? '');
        $this->exchangeRate = (string) $transfer->exchange_rate;
        $this->netAmountReceived = (string) ($transfer->net_amount_received ?? '');
        $this->receivedCurrency = $transfer->amount_received_currency->value;
        $this->receiveDate = $transfer->receive_date?->toDateString() ?? '';
        $this->transferArStatus = $transfer->ar_status->value;
        $this->showTransferModal = true;
    }

    public function saveTransfer(): void
    {
        $validated = $this->validate([
            'transferAgreementId' => ['required', 'integer', Rule::exists(DonorProposal::class, 'id')],
            'transferAccountId' => ['nullable', 'integer', Rule::exists(Account::class, 'id')],
            'paymentAmount' => ['required', 'numeric', 'min:0'],
            'transferredCurrency' => ['required', new Enum(Currency::class)],
            'scheduledDate' => ['nullable', 'date'],
            'transferDate' => ['nullable', 'date'],
            'fees' => ['required', 'numeric', 'min:0'],
            'feesCurrency' => ['required', new Enum(Currency::class)],
            'amountReceived' => ['nullable', 'numeric', 'min:0'],
            'exchangeRate' => ['required', 'numeric', 'min:0'],
            'netAmountReceived' => ['nullable', 'numeric', 'min:0'],
            'receivedCurrency' => ['required', new Enum(Currency::class)],
            'receiveDate' => ['nullable', 'date'],
            'transferArStatus' => ['required', new Enum(ArStatus::class)],
        ], [
            'transferAgreementId.required' => __('Select the funding agreement this tranche belongs to.'),
            'paymentAmount.required' => __('Enter the payment amount.'),
            'paymentAmount.min' => __('A payment amount cannot be negative.'),
            'fees.min' => __('Fees cannot be negative.'),
            'exchangeRate.min' => __('An exchange rate cannot be negative.'),
        ]);

        $transfer = $this->editingTransferId === null
            ? new TransfersTracking
            : TransfersTracking::query()->findOrFail($this->editingTransferId);

        $transfer->fill([
            'donor_proposal_id' => (int) $validated['transferAgreementId'],
            'account_id' => $validated['transferAccountId'] === '' || $validated['transferAccountId'] === null
                ? null
                : (int) $validated['transferAccountId'],
            'payment_amount' => $validated['paymentAmount'],
            'amount_transfered_currency' => $validated['transferredCurrency'],
            'scheduled_date' => $validated['scheduledDate'] === '' ? null : $validated['scheduledDate'],
            'transfer_date' => $validated['transferDate'] === '' ? null : $validated['transferDate'],
            'fees' => $validated['fees'],
            'fees_currency' => $validated['feesCurrency'],
            'amount_received' => $validated['amountReceived'] === '' ? null : $validated['amountReceived'],
            'exchange_rate' => $validated['exchangeRate'],
            'net_amount_received' => $validated['netAmountReceived'] === '' ? null : $validated['netAmountReceived'],
            'amount_received_currency' => $validated['receivedCurrency'],
            'receive_date' => $validated['receiveDate'] === '' ? null : $validated['receiveDate'],
            'ar_status' => $validated['transferArStatus'],
        ])->save();

        $wasRecentlyCreated = $transfer->wasRecentlyCreated;

        $this->showTransferModal = false;
        $this->resetTransferForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('Transfer recorded.') : __('Transfer updated.'),
        );
    }

    public function closeTransferModal(): void
    {
        $this->showTransferModal = false;
        $this->resetTransferForm();
    }

    protected function resetTransferForm(): void
    {
        $this->resetValidation();
        $this->editingTransferId = null;
        $this->transferAgreementId = '';
        $this->transferAccountId = '';
        $this->paymentAmount = '';
        $this->transferredCurrency = 'USD';
        $this->scheduledDate = '';
        $this->transferDate = '';
        $this->fees = '0';
        $this->feesCurrency = 'USD';
        $this->amountReceived = '';
        $this->exchangeRate = '1.000000';
        $this->netAmountReceived = '';
        $this->receivedCurrency = 'USD';
        $this->receiveDate = '';
        $this->transferArStatus = 'pending';
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $arStatus !== '' || $account !== '';
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="transfer-directory-heading">
    <x-ui.page-header
        heading-id="transfer-directory-heading"
        :title="__('Transfers')"
        :count="$this->transfers->total()"
        :description="__('Every tranche, what was sent, what landed, and in which currency.')"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('Search transfers')" :placeholder="__('Reference, donor, or proposal')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.select :label="__('Receipt status')" wire:model.live="arStatus">
                <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                @foreach (ArStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value" :wire:key="'ar-'.$statusCase->value">{{ Str::title($statusCase->value) }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('Account')" wire:model.live="account">
                <flux:select.option value="">{{ __('All accounts') }}</flux:select.option>
                @foreach ($this->accountOptions as $accountOption)
                    <flux:select.option :value="$accountOption->id" :wire:key="'tr-account-'.$accountOption->id">
                        {{ $accountOption->name }}@unless ($accountOption->is_active) · {{ __('closed') }}@endunless
                    </flux:select.option>
                @endforeach
            </x-ui.select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-transfer-button">
                {{ __('Record transfer') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($this->paymentByCurrency !== [])
        <div class="grid gap-3 @2xl/directory:grid-cols-3">
            @foreach ($this->paymentByCurrency as $currencyCode => $total)
                <x-ui.stat-tile :label="__('Sent on this page')" :value="number_format($total, 2)" :unit="$currencyCode" :scope="$currencyCode" wire:key="tr-total-{{ $currencyCode }}" />
            @endforeach
        </div>
    @endif

    <x-ui.panel :subtitle="trans_choice('{1} :count matching transfer|[2,*] :count matching transfers', $this->transfers->total(), ['count' => $this->transfers->total()])">
        <x-slot:toolbar>
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('Reset filters') }}</flux:button>
            @endif
        </x-slot:toolbar>

        @if ($this->transfers->isEmpty())
            <x-ui.empty-state
                icon="arrow-right-circle"
                :heading="$filtersAreActive ? __('No matching transfers') : __('No transfers recorded')"
                :body="$filtersAreActive ? __('Change the search or filters and try again.') : __('Record the first tranche against a funding agreement.')"
            />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->transfers as $transfer)
                    <article class="grid gap-2 p-3.5 @4xl/directory:grid-cols-[1.4fr_auto_auto_auto_auto]" wire:key="transfer-{{ $transfer->id }}">
                        <div class="min-w-0">
                            <a href="{{ route('funding-agreements.show', $transfer->donorProposal) }}" wire:navigate class="text-sm font-semibold hover:underline">
                                {{ $transfer->donorProposal->donor->name }} · {{ $transfer->donorProposal->proposal->name }}
                            </a>
                            <p class="text-xs text-secondary-medium dark:text-secondary-soft">
                                {{ $transfer->scheduled_date?->format('M j, Y') ?? __('Unscheduled') }}
                                ·
                                @if ($transfer->account === null)
                                    <span class="italic">{{ __('No account') }}</span>
                                @else
                                    {{ $transfer->account->name }}
                                @endif
                            </p>
                        </div>

                        <div class="text-xs">
                            <p class="text-secondary-medium dark:text-secondary-soft">{{ __('Sent') }}</p>
                            <x-ui.money :amount="$transfer->payment_amount" :currency="$transfer->amount_transfered_currency" />
                        </div>

                        <div class="text-xs">
                            <p class="text-secondary-medium dark:text-secondary-soft">{{ __('Fees') }}</p>
                            <x-ui.money :amount="$transfer->fees" :currency="$transfer->fees_currency" />
                        </div>

                        <div class="text-xs">
                            <p class="text-secondary-medium dark:text-secondary-soft">{{ __('Net received') }}</p>
                            @if ($transfer->net_amount_received === null)
                                <span class="text-secondary-light">—</span>
                            @else
                                <x-ui.money :amount="$transfer->net_amount_received" :currency="$transfer->amount_received_currency" />
                            @endif
                        </div>

                        <div class="flex items-center gap-1.5">
                            <x-ui.enum-badge :case="$transfer->ar_status" />
                            {{-- No delete: a money record never disappears. --}}
                            <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $transfer->id }})" :aria-label="__('Edit transfer')" />
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        <x-slot:footer>
            <flux:pagination :paginator="$this->transfers" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#transfer-directory-heading" />
        </x-slot:footer>
    </x-ui.panel>

    <flux:modal wire:model="showTransferModal" class="w-full max-w-4xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveTransfer" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingTransferId === null ? __('Record transfer') : __('Edit transfer') }}</flux:heading>
                <flux:subheading>{{ __('Sent, fees and received each carry their own currency. They are recorded separately and never combined.') }}</flux:subheading>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select wire:model="transferAgreementId" :label="__('Funding agreement')" required>
                    <flux:select.option value="">{{ __('Select an agreement') }}</flux:select.option>
                    @foreach ($this->agreementOptions as $agreementOption)
                        <flux:select.option :value="$agreementOption->id" :wire:key="'tr-agr-'.$agreementOption->id">
                            {{ $agreementOption->donor->name }} · {{ $agreementOption->proposal->name }}
                        </flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="transferAccountId" :label="__('Account')">
                    <flux:select.option value="">{{ __('Not yet assigned') }}</flux:select.option>
                    @foreach ($this->accountOptions as $accountOption)
                        <flux:select.option :value="$accountOption->id" :wire:key="'tr-account-option-'.$accountOption->id">
                            {{ $accountOption->name }}@unless ($accountOption->is_active) · {{ __('closed') }}@endunless
                        </flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <fieldset class="grid gap-4 rounded-lg border border-secondary-soft p-3 sm:grid-cols-4 dark:border-secondary-medium">
                <legend class="px-1 text-xs font-semibold text-secondary-medium uppercase dark:text-secondary-soft">{{ __('Sent') }}</legend>
                <x-ui.input wire:model="paymentAmount" :label="__('Payment amount')" type="number" step="0.01" min="0" required />
                <x-ui.select wire:model="transferredCurrency" :label="__('Currency')" required>
                    @foreach (Currency::cases() as $currencyCase)
                        <flux:select.option :value="$currencyCase->value" :wire:key="'tr-sent-'.$currencyCase->value">{{ $currencyCase->value }}</flux:select.option>
                    @endforeach
                </x-ui.select>
                <x-ui.input wire:model="scheduledDate" :label="__('Scheduled')" type="date" />
                <x-ui.input wire:model="transferDate" :label="__('Sent on')" type="date" />
            </fieldset>

            <fieldset class="grid gap-4 rounded-lg border border-secondary-soft p-3 sm:grid-cols-2 dark:border-secondary-medium">
                <legend class="px-1 text-xs font-semibold text-secondary-medium uppercase dark:text-secondary-soft">{{ __('Fees') }}</legend>
                <x-ui.input wire:model="fees" :label="__('Fees')" type="number" step="0.01" min="0" required />
                <x-ui.select wire:model="feesCurrency" :label="__('Fees currency')" required>
                    @foreach (Currency::cases() as $currencyCase)
                        <flux:select.option :value="$currencyCase->value" :wire:key="'tr-fees-'.$currencyCase->value">{{ $currencyCase->value }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            </fieldset>

            <fieldset class="grid gap-4 rounded-lg border border-secondary-soft p-3 sm:grid-cols-5 dark:border-secondary-medium">
                <legend class="px-1 text-xs font-semibold text-secondary-medium uppercase dark:text-secondary-soft">{{ __('Received') }}</legend>
                <x-ui.input wire:model="amountReceived" :label="__('Amount received')" type="number" step="0.01" min="0" />
                <x-ui.input wire:model="netAmountReceived" :label="__('Net received')" type="number" step="0.01" min="0" />
                <x-ui.select wire:model="receivedCurrency" :label="__('Currency')" required>
                    @foreach (Currency::cases() as $currencyCase)
                        <flux:select.option :value="$currencyCase->value" :wire:key="'tr-recv-'.$currencyCase->value">{{ $currencyCase->value }}</flux:select.option>
                    @endforeach
                </x-ui.select>
                <x-ui.input wire:model="exchangeRate" :label="__('Exchange rate')" type="number" step="0.000001" min="0" required />
                <x-ui.input wire:model="receiveDate" :label="__('Received on')" type="date" />
            </fieldset>

            <x-ui.select wire:model="transferArStatus" :label="__('Receipt status')" required>
                @foreach (ArStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value" :wire:key="'tr-ar-'.$statusCase->value">{{ Str::title($statusCase->value) }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeTransferModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-transfer-button">
                    {{ $editingTransferId === null ? __('Record transfer') : __('Save changes') }}
                </x-ui.button>
            </div>
        </form>
    </flux:modal>
</section>
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/pest tests/Feature/TransferTrackingTest.php`
Expected: PASS

- [ ] **Step 6: Format and stage**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/pages/transfers tests/Feature/TransferTrackingTest.php
```

Stop and report. Do not commit.

---

### Task 6: Accounts

**Files:**
- Create: `resources/views/pages/admin/⚡accounts.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/AccountDirectoryTest.php`

**Interfaces:**
- Consumes: `x-ui.page-header`, `x-ui.panel`, `x-ui.empty-state`, `x-ui.input`, `x-ui.button`.
- Produces: route `admin.accounts.index`; component `pages::admin.accounts`; public properties `$search`, `$state` (`''`, `'active'`, `'closed'`), `$showAccountModal`, `$editingAccountId`, `$closingAccountId`, form fields `$name`, `$openedAt`; methods `openCreateModal()`, `openEditModal(int)`, `saveAccount()`, `confirmClose(int)`, `closeAccount()`, `closeAccountModal()`, `clearFilters()`.

Note the file lives at `pages/admin/⚡accounts.blade.php` — a flat name, matching the existing `⚡cities.blade.php` and `⚡users.blade.php`, so the component alias is `pages::admin.accounts`.

**Accounts are closed, never deleted.** `transfers_tracking.account_id` RESTRICTs. Closing goes through the existing `Account::close()`, which `forceFill`s because `closed_at` is written alongside `is_active` and must never drift from it.

There is no `deleteAccount()` method, and a test asserts its absence.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Account;
use App\Models\TransfersTracking;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from the account directory', function () {
    $this->get(route('admin.accounts.index'))->assertRedirect(route('login'));
});

test('accounts can be filtered by open and closed state', function () {
    $user = User::factory()->create();

    Account::factory()->create(['name' => 'Operations USD', 'is_active' => true]);
    Account::factory()->create(['name' => 'Legacy EUR', 'is_active' => false, 'closed_at' => '2026-01-31']);

    Livewire::actingAs($user)
        ->test('pages::admin.accounts')
        ->assertSee('Operations USD')
        ->assertSee('Legacy EUR')
        ->set('state', 'active')
        ->assertSee('Operations USD')
        ->assertDontSee('Legacy EUR')
        ->call('clearFilters')
        ->set('state', 'closed')
        ->assertDontSee('Operations USD')
        ->assertSee('Legacy EUR');
});

test('an account can be opened', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::admin.accounts')
        ->call('openCreateModal')
        ->set('name', '  Reserve SYP  ')
        ->set('openedAt', '2026-02-01')
        ->call('saveAccount')
        ->assertHasNoErrors();

    $account = Account::query()->where('name', 'Reserve SYP')->sole();

    expect($account->is_active)->toBeTrue()
        ->and($account->closed_at)->toBeNull();
});

test('opening an account requires a name and an opening date', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::admin.accounts')
        ->call('openCreateModal')
        ->set('name', '')
        ->set('openedAt', '')
        ->call('saveAccount')
        ->assertHasErrors(['name', 'openedAt']);
});

test('closing an account sets both the flag and the date together', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['is_active' => true]);

    Livewire::actingAs($user)
        ->test('pages::admin.accounts')
        ->call('confirmClose', $account->id)
        ->call('closeAccount')
        ->assertHasNoErrors();

    $account->refresh();

    expect($account->is_active)->toBeFalse()
        ->and($account->closed_at)->not->toBeNull();
});

test('accounts offer no delete because transfers restrict it', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create();
    TransfersTracking::factory()->create(['account_id' => $account->id]);

    $component = Livewire::actingAs($user)->test('pages::admin.accounts');

    $component->assertDontSee('wire:click="confirmDelete');

    expect(method_exists($component->instance(), 'deleteAccount'))->toBeFalse()
        ->and(DB::table('accounts')->find($account->id))->not->toBeNull();
});

test('the account directory renders with no accounts', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::admin.accounts')->assertSee('No accounts yet');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Admin/AccountDirectoryTest.php`
Expected: FAIL — `Route [admin.accounts.index] not defined.`

- [ ] **Step 3: Add the route**

Beside the existing admin routes:

```php
    Route::livewire('admin/accounts', 'pages::admin.accounts')
        ->name('admin.accounts.index');
```

- [ ] **Step 4: Write the page**

`resources/views/pages/admin/⚡accounts.blade.php`:

```blade
<?php

use App\Models\Account;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Accounts')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $state = '';

    public bool $showAccountModal = false;
    public ?int $editingAccountId = null;
    public ?int $closingAccountId = null;
    public string $name = '';
    public string $openedAt = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedState(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->state = '';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Account> */
    #[Computed]
    public function accounts(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Account::query()
            ->select(['id', 'name', 'opened_at', 'closed_at', 'is_active', 'updated_at'])
            ->withCount('transfers')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->when($this->state === 'active', fn ($query) => $query->where('is_active', true))
            ->when($this->state === 'closed', fn ($query) => $query->where('is_active', false))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15);
    }

    public function openCreateModal(): void
    {
        $this->resetAccountForm();
        $this->showAccountModal = true;
    }

    public function openEditModal(int $accountId): void
    {
        $account = Account::query()->select(['id', 'name', 'opened_at'])->findOrFail($accountId);

        $this->resetValidation();
        $this->editingAccountId = $account->id;
        $this->name = $account->name;
        $this->openedAt = $account->opened_at?->toDateString() ?? '';
        $this->showAccountModal = true;
    }

    public function saveAccount(): void
    {
        $this->name = trim($this->name);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'openedAt' => ['required', 'date'],
        ], [
            'name.required' => __('Enter an account name.'),
            'openedAt.required' => __('Set the date this account opened.'),
        ]);

        $account = $this->editingAccountId === null
            ? new Account
            : Account::query()->findOrFail($this->editingAccountId);

        // is_active is deliberately not set here: the column default makes a
        // new account usable, and closing goes through Account::close().
        $account->fill([
            'name' => $validated['name'],
            'opened_at' => $validated['openedAt'],
        ])->save();

        $wasRecentlyCreated = $account->wasRecentlyCreated;

        $this->showAccountModal = false;
        $this->resetAccountForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('Account opened.') : __('Account updated.'),
        );
    }

    public function confirmClose(int $accountId): void
    {
        $this->closingAccountId = $accountId;
    }

    /**
     * Close an account so it can no longer receive transfers.
     *
     * Accounts are never deleted: transfers_tracking.account_id RESTRICTs
     * precisely so an account money has passed through survives. Account::close()
     * writes closed_at and is_active together, through forceFill, because
     * closed_at sits outside #[Fillable].
     */
    public function closeAccount(): void
    {
        if ($this->closingAccountId === null) {
            return;
        }

        Account::query()->findOrFail($this->closingAccountId)->close();

        $this->closingAccountId = null;
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('Account closed.'));
    }

    public function closeAccountModal(): void
    {
        $this->showAccountModal = false;
        $this->resetAccountForm();
    }

    protected function resetAccountForm(): void
    {
        $this->resetValidation();
        $this->editingAccountId = null;
        $this->name = '';
        $this->openedAt = '';
    }
};
?>

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="account-directory-heading">
    <x-ui.page-header
        heading-id="account-directory-heading"
        :title="__('Accounts')"
        :count="$this->accounts->total()"
        :description="__('Bank accounts money is received into. Accounts close; they are never deleted.')"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('Search accounts')" :placeholder="__('Account name')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.select :label="__('State')" wire:model.live="state">
                <flux:select.option value="">{{ __('All accounts') }}</flux:select.option>
                <flux:select.option value="active">{{ __('Open') }}</flux:select.option>
                <flux:select.option value="closed">{{ __('Closed') }}</flux:select.option>
            </x-ui.select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-account-button">
                {{ __('Open account') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel :subtitle="trans_choice('{1} :count matching account|[2,*] :count matching accounts', $this->accounts->total(), ['count' => $this->accounts->total()])">
        @if ($this->accounts->isEmpty())
            <x-ui.empty-state
                icon="building-library"
                :heading="$search !== '' || $state !== '' ? __('No matching accounts') : __('No accounts yet')"
                :body="$search !== '' || $state !== '' ? __('Change the search or filters and try again.') : __('Open the first account.')"
            />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->accounts as $account)
                    <article class="flex flex-wrap items-center gap-3 p-3.5" wire:key="account-{{ $account->id }}">
                        <div class="min-w-0 me-auto">
                            <p class="text-sm font-semibold">{{ $account->name }}</p>
                            <p class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">
                                {{ __('Opened :date', ['date' => $account->opened_at?->format('M j, Y')]) }}
                                @if ($account->closed_at !== null)
                                    · {{ __('closed :date', ['date' => $account->closed_at->format('M j, Y')]) }}
                                @endif
                                · {{ trans_choice(':count transfer|:count transfers', $account->transfers_count, ['count' => $account->transfers_count]) }}
                            </p>
                        </div>

                        @if ($account->is_active)
                            <span class="rounded-md border border-secondary-dark bg-secondary-dark px-2 py-0.5 text-xs font-semibold text-white dark:border-secondary-medium dark:bg-secondary-medium">{{ __('Open') }}</span>
                        @else
                            <span class="rounded-md border border-secondary-light px-2 py-0.5 text-xs font-semibold text-secondary-medium dark:text-secondary-soft">{{ __('Closed') }}</span>
                        @endif

                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $account->id }})" :aria-label="__('Edit :name', ['name' => $account->name])" />

                        {{-- No delete anywhere: transfers_tracking.account_id RESTRICTs. --}}
                        @if ($account->is_active)
                            <x-ui.button tone="ghost" size="sm" icon="lock-closed" class="size-9!" wire:click="confirmClose({{ $account->id }})" :aria-label="__('Close :name', ['name' => $account->name])" />
                        @endif
                    </article>
                @endforeach
            </div>
        @endif

        <x-slot:footer>
            <flux:pagination :paginator="$this->accounts" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#account-directory-heading" />
        </x-slot:footer>
    </x-ui.panel>

    <flux:modal wire:model="showAccountModal" class="w-full max-w-lg border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveAccount" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingAccountId === null ? __('Open account') : __('Edit account') }}</flux:heading>
                <flux:subheading>{{ __('A new account is usable immediately. To stop it receiving transfers, close it.') }}</flux:subheading>
            </div>

            <x-ui.input wire:model="name" :label="__('Account name')" type="text" maxlength="255" required autofocus />
            <x-ui.input wire:model="openedAt" :label="__('Opened on')" type="date" required />

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeAccountModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-account-button">
                    {{ $editingAccountId === null ? __('Open account') : __('Save changes') }}
                </x-ui.button>
            </div>
        </form>
    </flux:modal>

    <flux:modal :open="$closingAccountId !== null" wire:close="$set('closingAccountId', null)" class="w-full max-w-md border border-secondary-light! bg-white! dark:bg-secondary-dark!">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Close this account?') }}</flux:heading>
            <flux:subheading>{{ __('It stops accepting new transfers. Existing transfer records are kept — an account money has passed through is never deleted.') }}</flux:subheading>
            <div class="flex justify-end gap-2 border-t border-secondary-soft pt-4 dark:border-secondary-medium">
                <x-ui.button type="button" wire:click="$set('closingAccountId', null)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" wire:click="closeAccount" data-test="confirm-close-account">{{ __('Close account') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</section>
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/pest tests/Feature/Admin/AccountDirectoryTest.php`
Expected: PASS

- [ ] **Step 6: Format and stage**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/pages/admin tests/Feature/Admin/AccountDirectoryTest.php
```

Stop and report. Do not commit.

---

### Task 7: Funding sidebar group

**Files:**
- Modify: `resources/views/components/navigation/sidebar.blade.php`
- Modify: `tests/Feature/NavigationTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/NavigationTest.php`:

```php
test('the sidebar carries a funding group once its routes exist', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Funding')
        ->assertSeeText('Proposals')
        ->assertSeeText('Donors')
        ->assertSeeText('Funding Agreements')
        ->assertSeeText('Transfers')
        ->assertSeeText('Accounts');
});
```

Update the existing `the sidebar does not render a group for routes that do not exist yet` test to assert only that `Results & Learning` is absent.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/NavigationTest.php`
Expected: FAIL — "Funding" is not present.

- [ ] **Step 3: Add the group and the Accounts item**

In `resources/views/components/navigation/sidebar.blade.php`, add a `Funding` group after `Delivery` (or after `Strategy` if the Delivery plan has not landed):

```blade
        <flux:sidebar.group
            :heading="__('Funding')"
            :class="$navigationGroupClasses"
        >
            <flux:sidebar.item
                icon="document-text"
                :href="route('proposals.index')"
                :current="request()->routeIs('proposals.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('Proposals') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="building-library"
                :href="route('donors.index')"
                :current="request()->routeIs('donors.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('Donors') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="banknotes"
                :href="route('funding-agreements.index')"
                :current="request()->routeIs('funding-agreements.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('Funding Agreements') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="arrow-right-circle"
                :href="route('transfers.index')"
                :current="request()->routeIs('transfers.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('Transfers') }}
            </flux:sidebar.item>
        </flux:sidebar.group>
```

And inside the existing `Administration` group, before the Cities item:

```blade
            <flux:sidebar.item
                icon="credit-card"
                :href="route('admin.accounts.index')"
                :current="request()->routeIs('admin.accounts.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('Accounts') }}
            </flux:sidebar.item>
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Feature/NavigationTest.php`
Expected: PASS

- [ ] **Step 5: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS. `DonorProposalTest`, `TransfersTrackingTest`, `TransactionalDeleteTest` and `ProposalPhaseTest` must all stay green — this spine changes no model or migration.

- [ ] **Step 6: Format, type-check and stage**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
git add resources/views/components/navigation/sidebar.blade.php tests/Feature/NavigationTest.php
```

Stop and report. Do not commit.

---

## Self-Review

**Spec coverage.** Six rows of the spec's sections table: Proposals (create/edit, guarded delete), Status notes (written by the status-change action), Donors (create/edit, guarded delete), Funding Agreements (create/edit, no delete), Transfers (create/edit, no delete), Accounts (create/edit/close, no delete). The `donor_proposal`-as-entity decision from the spec's "Pivot vs pivot-entity" section is realised as its own section with its own index and detail page.

**The spec's money rule is enforced in three places, not asserted once.** `x-ui.money` throws without a currency (Foundation plan, Task 2). Every total is a `groupBy(currency)` map, never a scalar. And three tests assert the forbidden cross-currency sum is absent from the rendered page: `700,000.00` on Proposal detail, `750,000.00` on Donor detail, `150,000.00` on Transfers.

**Placeholder scan.** No TBD, no vague instructions. Every code step carries literal file content. Three forward references exist and each is named at the step that creates it: Task 1 → Task 2's route, Tasks 2 and 3 → Task 4's route, and Task 2 Step 5's conditional edit to a Delivery-plan file that may not exist. That last one is explicitly marked skippable with a note requirement rather than left to be discovered.

**Type consistency.** Form fields that would collide with a filter carry a model prefix: `$agreementDonorId`, `$agreementProposalId`, `$agreementCurrency`, `$transferAgreementId`, `$transferAccountId`, `$transferArStatus`, `$proposalStatus`. The delete flow is `confirmDelete(int)` → `$deleting<Model>Id` → `delete<Model>()` on the two deletable tables, and `canDelete(int): bool` exists on exactly those two. Accounts use `confirmClose`/`closeAccount` instead, because closing is not deleting and the names should not suggest it is. `budgetByCurrency()` and `receivedByCurrency()` and `paymentByCurrency()` all return `array<string, float>`.

**Known risk.** `budgetByCurrency()` on the agreements index totals **the current page only**, not the whole filtered set, and the tile says "Committed on this page" so the number is never misread as a portfolio total. Changing it to a full-set total means a second grouped aggregate query; that is a deliberate choice to revisit if the user wants portfolio figures.
