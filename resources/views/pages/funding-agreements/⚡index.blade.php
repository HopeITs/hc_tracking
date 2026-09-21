<?php

use App\Enums\Currency;
use App\Livewire\Concerns\ManagesAgreementForm;
use App\Models\DonorProposal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.funding_agreements')] class extends Component {
    use ManagesAgreementForm;
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $donor = '';

    #[Url(except: '')]
    public string $currency = '';

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

    public function openCreateModal(): void
    {
        $this->openAgreementCreate();
    }

    public function openEditModal(int $agreementId): void
    {
        $this->openAgreementEdit($agreementId);
    }

    protected function agreementSaved(DonorProposal $agreement, bool $isCreate): void
    {
        $this->resetPage();
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $donor !== '' || $currency !== '';
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="agreement-directory-heading">
    <x-ui.page-header
        heading-id="agreement-directory-heading"
        :title="__('common.funding_agreements')"
        :count="$this->agreements->total()"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('funding_agreements.search_agreements')" :placeholder="__('common.reference_donor_or_proposal')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('funding_agreements.donor')" wire:model.live="donor" :value="$donor">
                <flux:select.option value="">{{ __('funding_agreements.all_donors') }}</flux:select.option>
                @foreach ($this->donorOptions as $donorOption)
                    <flux:select.option :value="$donorOption->id" :wire:key="'agr-donor-'.$donorOption->id">{{ $donorOption->name }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('common.currency')" wire:model.live="currency" :value="$currency">
                <flux:select.option value="">{{ __('funding_agreements.all_currencies') }}</flux:select.option>
                @foreach (Currency::cases() as $currencyCase)
                    <flux:select.option :value="$currencyCase->value" :wire:key="'agr-currency-'.$currencyCase->value">{{ $currencyCase->value }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            @can('funding-agreements.create')
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-agreement-button">
                {{ __('funding_agreements.record_agreement') }}
            </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @if ($this->budgetByCurrency !== [])
        <div class="grid gap-3 @2xl/directory:grid-cols-3">
            @foreach ($this->budgetByCurrency as $currencyCode => $total)
                <x-ui.stat-tile :label="__('funding_agreements.committed_on_this_page')" :value="number_format($total, 2)" :unit="$currencyCode" :scope="$currencyCode" wire:key="agr-total-{{ $currencyCode }}" />
            @endforeach
        </div>
    @endif

    <x-ui.panel :subtitle="trans_choice('funding_agreements.count_matching_agreement', $this->agreements->total(), ['count' => $this->agreements->total()])">
        <x-slot:toolbar>
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
            @endif
        </x-slot:toolbar>

        @if ($this->agreements->isEmpty())
            <x-ui.empty-state
                icon="banknotes"
                :heading="$filtersAreActive ? __('funding_agreements.no_matching_agreements') : __('common.no_agreements_yet')"
                :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('funding_agreements.record_the_first_funding_agreement')"
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
                                {{ $agreement->project_num ?? __('common.no_donor_reference') }}
                                · {{ trans_choice('common.count_tranche', $agreement->transfers_count, ['count' => $agreement->transfers_count]) }}
                            </p>
                        </div>

                        <x-ui.enum-badge :case="$agreement->proposal->status" />
                        <x-ui.money :amount="$agreement->budget" :currency="$agreement->currency" class="@4xl/directory:w-40 @4xl/directory:justify-end" />

                        {{-- No delete: transfers_tracking cascades from this row, so
                             deleting an agreement would erase the money received. --}}
                        @can('funding-agreements.update')
                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9! shrink-0" wire:click="openEditModal({{ $agreement->id }})" :aria-label="__('funding_agreements.edit_agreement')" />
                        @endcan
                    </article>
                @endforeach
            </div>
        @endif

        <x-slot:footer>
            <flux:pagination :paginator="$this->agreements" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#agreement-directory-heading" />
        </x-slot:footer>
    </x-ui.panel>

    <x-funding.agreement-modal
        :open="$showAgreementModal"
        :editing="$editingAgreementId !== null"
        :donor-options="$showAgreementModal ? $this->donorOptions : []"
        :proposal-options="$showAgreementModal ? $this->proposalOptions : []"
    />
</section>
