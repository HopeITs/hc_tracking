<?php

use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\TransfersTracking;
use App\Support\Desk\Money;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Transfers: tranches still to arrive (late ones included), only the late
 * ones, or those received, newest received first. The states are the
 * transfers register's own (TransfersTracking::stateSql()).
 */
new class extends Component {
    use IsDeskPanel;

    /**
     * @return Collection<int, TransfersTracking>
     */
    #[Computed]
    public function rows(): Collection
    {
        if (! $this->canView('transfers')) {
            return new Collection;
        }

        $query = TransfersTracking::query()
            ->with(['donorProposal:id,donor_id,proposal_id', 'donorProposal.donor:id,name', 'donorProposal.proposal:id,name']);

        return match ($this->shownFilter()) {
            'late' => $query->inState('late')->orderBy('scheduled_date')->get(),
            'received' => $query->inState('received')->orderByDesc('receive_date')->orderByDesc('id')->get(),
            default => $query->where(fn ($pending) => $pending->inState('transit')->orWhere(fn ($q) => $q->inState('late'))->orWhere(fn ($q) => $q->inState('upcoming')))
                ->orderByRaw('scheduled_date is null')->orderBy('scheduled_date')->get(),
        };
    }

    public function shownFilter(): string
    {
        return in_array($this->filter, ['late', 'received'], true) ? $this->filter : 'upcoming';
    }

    public function updatedFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['late', 'received'], true) ? $filter : 'all';
        $this->page = 0;
    }

    #[Computed]
    public function lateCount(): int
    {
        return $this->canView('transfers') ? TransfersTracking::query()->inState('late')->count() : 0;
    }
};
?>

@php
    $pg = $this->paginate($this->rows, self::PerPage);
    $format = __('desk.date_long');
@endphp

<x-desk.panel :facets="$pg['facets']" :filtered="$filter !== 'all'" id="transfers" register="transfers.index" :size="$size" :index="$index" :total="$total" :count="$this->lateCount > 0 ? $this->lateCount : null">
    <x-slot:filters>
        <div class="contents" data-filter-defaults='{"filter":"all"}'>
            <x-ui.header-filter-select :label="__('desk.facet.status')" wire:model="filter" :value="$filter" deferred>
                <option value="all">{{ __('desk.filter_upcoming') }}</option>
                <option value="late">{{ __('desk.filter_late') }}</option>
                <option value="received">{{ __('desk.filter_received') }}</option>
            </x-ui.header-filter-select>
        </div>
    </x-slot:filters>

    @if ($pg['total'] > 0)
        <ol class="dk-ladder dk-tr" {{ $this->fitAttributes($pg) }}>
            @foreach ($pg['slice'] as $transfer)
                @php
                    $state = $transfer->state();
                    $date = $transfer->receive_date ?? $transfer->arrival_date ?? $transfer->scheduled_date;
                    $received = $state === 'received';
                @endphp
                <li wire:key="t-{{ $transfer->id }}" class="dk-{{ $state }}">
                    <span class="dk-d">{{ $date?->translatedFormat($format) ?? '—' }}</span>
                    <i></i>
                    <span class="dk-tr-m" title="{{ $transfer->donorProposal?->donor?->name ?? '—' }} · {{ $transfer->donorProposal?->proposal?->name ?? '—' }}"><strong class="dk-num">{{ Money::format($received ? ($transfer->net_amount_received ?? $transfer->payment_amount) : $transfer->payment_amount, $received ? $transfer->amount_received_currency : $transfer->amount_transfered_currency) }}</strong> <span class="dk-is">{{ $transfer->donorProposal?->donor?->name ?? '—' }} · {{ $transfer->donorProposal?->proposal?->name ?? '—' }}</span></span>
                    <span @class(['dk-pill', 'dk-bad' => $state === 'late' || $state === 'failed', 'dk-money' => $received, 'dk-mute' => ! $received && $state !== 'late' && $state !== 'failed'])>{{ __('desk.transfer_state.'.$state) }}</span>
                </li>
            @endforeach
        </ol>
    @else
        <p class="dk-pempty">{{ __('desk.panel.transfers.empty') }}</p>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
