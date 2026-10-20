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
        return $this->filter === 'all' ? 'upcoming' : $this->filter;
    }

    #[Computed]
    public function lateCount(): int
    {
        return $this->canView('transfers') ? TransfersTracking::query()->inState('late')->count() : 0;
    }
};
?>

@php
    $pg = $this->paginate($this->rows, 5);
    $format = __('desk.date_long');
@endphp

<x-desk.panel id="transfers" register="transfers.index" :size="$size" :index="$index" :total="$total" :count="$this->lateCount > 0 ? $this->lateCount : null">
    <x-slot:tools>
        <div class="dk-seg" role="group">
            @foreach (['upcoming' => __('desk.filter_upcoming'), 'late' => __('desk.filter_late'), 'received' => __('desk.filter_received')] as $key => $label)
                <button type="button" wire:click="setFilter('{{ $key }}')" aria-pressed="{{ $this->shownFilter() === $key ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>
    </x-slot:tools>

    @if ($pg['total'] > 0)
        <ol class="dk-ladder">
            @foreach ($pg['slice'] as $transfer)
                @php
                    $state = $transfer->state();
                    $date = $transfer->receive_date ?? $transfer->arrival_date ?? $transfer->scheduled_date;
                    $received = $state === 'received';
                @endphp
                <li wire:key="t-{{ $transfer->id }}" class="dk-{{ $state }}">
                    <span class="dk-d">{{ $date?->translatedFormat($format) ?? '—' }}</span>
                    <i></i>
                    <span><strong class="dk-num">{{ Money::format($received ? ($transfer->net_amount_received ?? $transfer->payment_amount) : $transfer->payment_amount, $received ? $transfer->amount_received_currency : $transfer->amount_transfered_currency) }}</strong> <span class="dk-is">{{ $transfer->donorProposal?->donor?->name ?? '—' }} · {{ $transfer->donorProposal?->proposal?->name ?? '—' }}</span></span>
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
