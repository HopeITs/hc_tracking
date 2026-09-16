<?php

use App\Enums\DonorType;
use App\Models\Donor;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.donors')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    public bool $showDonorModal = false;
    public ?int $editingDonorId = null;
    public ?int $deletingDonorId = null;
    public string $name = '';
    public string $type = '';
    public string $contactPerson = '';
    public string $email = '';
    public string $phone = '';
    public string $country = '';
    public string $notes = '';

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
            ->select(['id', 'name', 'type', 'updated_at'])
            ->withCount('donorProposals')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15);
    }

    /**
     * The classification options offered in the donor form.
     *
     * @return array<int, DonorType>
     */
    #[Computed]
    public function donorTypeOptions(): array
    {
        return DonorType::cases();
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
        $donor = Donor::query()
            ->select(['id', 'name', 'type', 'contact_person', 'email', 'phone', 'country', 'notes'])
            ->findOrFail($donorId);

        $this->resetValidation();
        $this->editingDonorId = $donor->id;
        $this->name = $donor->name;
        $this->type = $donor->type?->value ?? '';
        $this->contactPerson = (string) $donor->contact_person;
        $this->email = (string) $donor->email;
        $this->phone = (string) $donor->phone;
        $this->country = (string) $donor->country;
        $this->notes = (string) $donor->notes;
        $this->showDonorModal = true;
    }

    public function saveDonor(): void
    {
        $this->name = trim($this->name);

        // Deliberately no unique rule: donors.name is a plain non-unique
        // VARCHAR by standing decision.
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'in:'.implode(',', DonorType::values())],
            'contactPerson' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'country' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'name.required' => __('donors.enter_a_donor_name'),
        ]);

        $donor = $this->editingDonorId === null
            ? new Donor
            : Donor::query()->findOrFail($this->editingDonorId);

        $donor->fill([
            'name' => $validated['name'],
            'type' => $validated['type'] !== '' && $validated['type'] !== null ? $validated['type'] : null,
            'contact_person' => $validated['contactPerson'] !== '' ? $validated['contactPerson'] : null,
            'email' => $validated['email'] !== '' ? $validated['email'] : null,
            'phone' => $validated['phone'] !== '' ? $validated['phone'] : null,
            'country' => $validated['country'] !== '' ? $validated['country'] : null,
            'notes' => $validated['notes'] !== '' ? $validated['notes'] : null,
        ])->save();

        $wasRecentlyCreated = $donor->wasRecentlyCreated;

        $this->showDonorModal = false;
        $this->resetDonorForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('donors.donor_created') : __('donors.donor_updated'),
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
                text: __('donors.this_donor_has_signed_a_funding_agreement_and_cannot_be_dele'),
            );

            return;
        }

        Donor::query()->findOrFail($this->deletingDonorId)->delete();

        $this->deletingDonorId = null;
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('donors.donor_deleted'));
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
        $this->type = '';
        $this->contactPerson = '';
        $this->email = '';
        $this->phone = '';
        $this->country = '';
        $this->notes = '';
    }
};
?>

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="donor-directory-heading">
    <x-ui.page-header
        heading-id="donor-directory-heading"
        :title="__('common.donors')"
        :count="$this->donors->total()"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('donors.search_donors')" :placeholder="__('donors.donor_name')" wire:model.live.debounce.300ms="search" clearable />
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-donor-button">
                {{ __('donors.add_donor') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel :subtitle="trans_choice('donors.count_matching_donor', $this->donors->total(), ['count' => $this->donors->total()])">
        @if ($this->donors->isEmpty())
            <x-ui.empty-state
                icon="building-library"
                :heading="$search !== '' ? __('donors.no_matching_donors') : __('donors.no_donors_yet')"
                :body="$search !== '' ? __('common.change_the_search_and_try_again') : __('donors.add_the_first_donor')"
            />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->donors as $donor)
                    <article class="flex items-center gap-3 p-3.5" wire:key="donor-{{ $donor->id }}">
                        <div class="min-w-0 me-auto">
                            <a href="{{ route('donors.show', $donor) }}" wire:navigate class="text-sm font-semibold hover:underline">{{ $donor->name }}</a>
                            <p class="text-xs text-secondary-medium dark:text-secondary-soft">
                                {{ trans_choice('common.count_agreement', $donor->donor_proposals_count, ['count' => $donor->donor_proposals_count]) }}
                            </p>
                        </div>

                        <x-ui.enum-badge :case="$donor->type" />

                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $donor->id }})" :aria-label="__('common.edit_name', ['name' => $donor->name])" />

                        @if ($donor->donor_proposals_count === 0)
                            <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9!" wire:click="confirmDelete({{ $donor->id }})" :aria-label="__('common.delete_name', ['name' => $donor->name])" />
                        @else
                            <flux:tooltip :content="__('donors.donors_holding_an_agreement_cannot_be_deleted')">
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
                <flux:heading size="lg">{{ $editingDonorId === null ? __('donors.add_donor') : __('donors.edit_donor') }}</flux:heading>
                <flux:subheading>{{ __('donors.donor_names_need_not_be_unique_two_country_offices_may_share') }}</flux:subheading>
            </div>

            <x-ui.input wire:model="name" :label="__('donors.donor_name')" type="text" maxlength="255" required autofocus />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="type" :label="__('common.type')">
                    <flux:select.option value="">{{ __('donors.not_classified') }}</flux:select.option>
                    @foreach ($this->donorTypeOptions as $donorTypeOption)
                        <flux:select.option :value="$donorTypeOption->value" :wire:key="'donor-type-'.$donorTypeOption->value">
                            {{ $donorTypeOption->label() }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

                <x-ui.input wire:model="contactPerson" :label="__('donors.contact_person')" type="text" maxlength="255" />
                <x-ui.input wire:model="email" :label="__('common.email')" type="email" maxlength="255" />
                <x-ui.input wire:model="phone" :label="__('donors.phone')" type="text" maxlength="50" />
                <x-ui.input wire:model="country" :label="__('donors.country')" type="text" maxlength="255" class="sm:col-span-2" />
            </div>

            <flux:textarea wire:model="notes" :label="__('common.notes')" rows="3" maxlength="2000" />

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeDonorModal">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-donor-button">
                    {{ $editingDonorId === null ? __('donors.create_donor') : __('common.save_changes') }}
                </x-ui.button>
            </div>
        </form>
    </flux:modal>

    <flux:modal :open="$deletingDonorId !== null" wire:close="$set('deletingDonorId', null)" class="w-full max-w-md border border-secondary-light! bg-white! dark:bg-secondary-dark!">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('donors.delete_this_donor') }}</flux:heading>
            <flux:subheading>{{ __('donors.only_a_donor_holding_no_funding_agreement_can_be_deleted') }}</flux:subheading>
            <div class="flex justify-end gap-2 border-t border-secondary-soft pt-4 dark:border-secondary-medium">
                <x-ui.button type="button" wire:click="$set('deletingDonorId', null)">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" wire:click="deleteDonor" data-test="confirm-delete-donor">{{ __('donors.delete_donor') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</section>
