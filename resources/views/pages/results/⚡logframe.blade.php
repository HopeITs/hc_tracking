<?php

use App\Models\Proposal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::desk'), Title('proposals.framework.title')] class extends Component {
    #[Url(as: 'proposal', except: '')]
    public string $selectedProposal = '';

    /** @return Collection<int, Proposal> */
    #[Computed]
    public function proposals(): Collection
    {
        if (Gate::denies('viewAny', Proposal::class)) {
            return new Collection;
        }

        return Proposal::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'kind'])
            ->reject(fn (Proposal $proposal): bool => $proposal->isPayroll());
    }

    #[Computed]
    public function proposal(): ?Proposal
    {
        if ($this->selectedProposal === '' || ! ctype_digit($this->selectedProposal)) {
            return null;
        }

        return $this->proposals->find((int) $this->selectedProposal);
    }
};
?>

<x-desk.register heading-id="meal-logframe-heading" :title="__('proposals.framework.title')">
    <x-slot:filters>
        <x-ui.header-filter-select :label="__('proposals.framework.choose_proposal')" wire:model.live="selectedProposal" :value="(string) ($this->proposal?->id ?? '')">
            <option value="">{{ __('proposals.framework.choose_proposal') }}</option>
            @foreach ($this->proposals as $option)
                <option value="{{ $option->id }}">{{ $option->name }}</option>
            @endforeach
        </x-ui.header-filter-select>
    </x-slot:filters>

    @if ($this->proposal !== null)
        <div class="pp-page">
            <livewire:proposals.logframe :proposal-id="$this->proposal->id" :key="'meal-logframe-'.$this->proposal->id" />
        </div>
    @else
        <p class="p-4 text-sm">{{ __('proposals.framework.choose_proposal') }}</p>
    @endif
</x-desk.register>
