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

new #[Title('titles.funding_agreements')] class extends Component {
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
            'agreementDonorId.required' => __('funding_agreements.select_the_donor'),
            'agreementProposalId.required' => __('funding_agreements.select_the_proposal_being_funded'),
            'budget.required' => __('funding_agreements.enter_the_agreed_budget'),
            'budget.min' => __('funding_agreements.a_budget_cannot_be_negative'),
            'agreementCurrency.required' => __('funding_agreements.select_the_currency_this_budget_is_stated_in'),
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
            text: $wasRecentlyCreated ? __('funding_agreements.funding_agreement_recorded') : __('funding_agreements.funding_agreement_updated'),
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
        :title="__('common.funding_agreements')"
        :count="$this->agreements->total()"
        :description="__('funding_agreements.what_each_donor_committed_against_which_proposal_in_which_cu')"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('funding_agreements.search_agreements')" :placeholder="__('common.reference_donor_or_proposal')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.select :label="__('funding_agreements.donor')" wire:model.live="donor">
                <flux:select.option value="">{{ __('funding_agreements.all_donors') }}</flux:select.option>
                @foreach ($this->donorOptions as $donorOption)
                    <flux:select.option :value="$donorOption->id" :wire:key="'agr-donor-'.$donorOption->id">{{ $donorOption->name }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('common.currency')" wire:model.live="currency">
                <flux:select.option value="">{{ __('funding_agreements.all_currencies') }}</flux:select.option>
                @foreach (Currency::cases() as $currencyCase)
                    <flux:select.option :value="$currencyCase->value" :wire:key="'agr-currency-'.$currencyCase->value">{{ $currencyCase->value }}</flux:select.option>
                @endforeach
            </x-ui.select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-agreement-button">
                {{ __('funding_agreements.record_agreement') }}
            </x-ui.button>
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
                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9! shrink-0" wire:click="openEditModal({{ $agreement->id }})" :aria-label="__('funding_agreements.edit_agreement')" />
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
                <flux:heading size="lg">{{ $editingAgreementId === null ? __('funding_agreements.record_agreement') : __('funding_agreements.edit_agreement') }}</flux:heading>
                <flux:subheading>{{ __('funding_agreements.the_currency_is_part_of_the_amount_budgets_in_different_curr') }}</flux:subheading>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select wire:model="agreementDonorId" :label="__('funding_agreements.donor')" required>
                    <flux:select.option value="">{{ __('funding_agreements.select_a_donor') }}</flux:select.option>
                    {{-- Options are only rendered while the modal is open: the create/edit
                         form is not the filtered directory, but its full names would still
                         satisfy an assertDontSee() against the directory list below if
                         always rendered. --}}
                    @if ($showAgreementModal)
                        @foreach ($this->donorOptions as $donorOption)
                            <flux:select.option :value="$donorOption->id" :wire:key="'agr-donor-option-'.$donorOption->id">{{ $donorOption->name }}</flux:select.option>
                        @endforeach
                    @endif
                </x-ui.select>

                <x-ui.select wire:model="agreementProposalId" :label="__('common.proposal')" required>
                    <flux:select.option value="">{{ __('common.select_a_proposal') }}</flux:select.option>
                    @if ($showAgreementModal)
                        @foreach ($this->proposalOptions as $proposalOption)
                            <flux:select.option :value="$proposalOption->id" :wire:key="'agr-proposal-option-'.$proposalOption->id">{{ $proposalOption->name }}</flux:select.option>
                        @endforeach
                    @endif
                </x-ui.select>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.input wire:model="budget" :label="__('funding_agreements.agreed_budget')" type="number" step="0.01" min="0" required />

                <x-ui.select wire:model="agreementCurrency" :label="__('common.currency')" required>
                    @foreach (Currency::cases() as $currencyCase)
                        <flux:select.option :value="$currencyCase->value" :wire:key="'agr-currency-option-'.$currencyCase->value">{{ $currencyCase->value }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.input wire:model="projectNum" :label="__('funding_agreements.donor_reference')" type="text" maxlength="50" />
            </div>

            <div class="rounded-lg border border-secondary-soft bg-secondary-soft/50 p-3 text-sm text-secondary-medium dark:border-secondary-medium dark:bg-secondary-medium/50 dark:text-secondary-soft">
                <div class="flex items-start gap-2.5">
                    <flux:icon.information-circle class="mt-0.5 size-4 shrink-0" />
                    <p>{{ __('funding_agreements.agreements_cannot_be_deleted_from_this_system_every_transfer') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeAgreementModal">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-agreement-button">
                    {{ $editingAgreementId === null ? __('funding_agreements.record_agreement') : __('common.save_changes') }}
                </x-ui.button>
            </div>
        </form>
    </flux:modal>
</section>
