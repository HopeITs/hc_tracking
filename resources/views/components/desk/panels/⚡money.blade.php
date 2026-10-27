<?php

use App\Enums\ArStatus;
use App\Livewire\Concerns\Dashboard\ChartsDashboard;
use App\Livewire\Concerns\Dashboard\ReadsDashboardAttention;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use App\Support\Desk\Money;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Funding position: per currency, money received against committed and
 * what is late (the dashboard's funding position, currencies never
 * summed), then received against committed per donor in USD.
 */
new class extends Component {
    use ChartsDashboard;
    use IsDeskPanel;
    use ReadsDashboardAttention;

    /**
     * currency => late amount.
     *
     * @return array<string, float>
     */
    #[Computed]
    public function late(): array
    {
        if (! $this->canViewFundingPosition()) {
            return [];
        }

        return TransfersTracking::query()
            ->inState('late')
            ->selectRaw('amount_transfered_currency as currency, sum(payment_amount) as total')
            ->groupBy('amount_transfered_currency')
            ->pluck('total', 'currency')
            ->mapWithKeys(fn ($total, $currency): array => [(string) $currency => (float) $total])
            ->all();
    }

    /**
     * @return Collection<int, array{name: string, committed: float, received: float}>
     */
    #[Computed]
    public function donors(): Collection
    {
        if (! $this->canViewFundingPosition()) {
            return new Collection;
        }

        $committed = DonorProposal::query()->where('currency', 'USD')->selectRaw('donor_id, sum(budget) as total')->groupBy('donor_id')->pluck('total', 'donor_id');
        $received = TransfersTracking::query()
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->where('ar_status', ArStatus::Received)
            ->where('amount_received_currency', 'USD')
            ->selectRaw('donor_proposal.donor_id, sum(net_amount_received) as total')
            ->groupBy('donor_proposal.donor_id')
            ->pluck('total', 'donor_id');
        $names = Donor::query()->whereKey($committed->keys()->all())->pluck('name', 'id');

        return $committed
            ->filter(fn ($total): bool => (float) $total > 0)
            ->map(fn ($total, $donorId): array => ['name' => (string) $names->get($donorId, '—'), 'committed' => (float) $total, 'received' => (float) ($received[$donorId] ?? 0)])
            ->sortByDesc('committed')
            ->values();
    }
};
?>

@php
    $late = $this->late;
    $donors = $this->donors;
    $peak = max(1, (float) $donors->max('committed'));
    $pg = $this->paginate($donors, self::PerPage);
@endphp

<x-desk.panel :facets="$pg['facets']" id="money" register="funding-agreements.index" :size="$size" :index="$index" :total="$total">
    <div class="dk-money">
        @foreach ($this->fundingPosition as $row)
            <div class="dk-mrow" wire:key="money-{{ $row['currency'] }}">
                <span class="dk-cur">{{ $row['currency'] }}</span>
                <div class="dk-fig dk-rec"><span>{{ __('desk.received') }}</span><strong class="dk-num">{{ Money::format($row['received'], $row['currency']) }}</strong></div>
                <div class="dk-fig"><span>{{ __('desk.committed') }}</span><strong class="dk-num">{{ Money::format($row['committed'], $row['currency']) }}</strong></div>
                @if (($late[$row['currency']] ?? 0) > 0)
                    <div class="dk-fig"><span>{{ __('desk.filter_late') }}</span><strong class="dk-num dk-bad">{{ Money::format($late[$row['currency']], $row['currency']) }}</strong></div>
                @endif
                <div class="dk-meter dk-wide"><b style="width:{{ min(100, $row['share'] ?? 0) }}%"></b></div>
            </div>
        @endforeach
    </div>
    @if ($donors->isNotEmpty())
        <div class="dk-dbars">
            <p class="dk-small dk-muted">{{ __('desk.by_donor_usd') }}</p>
            @foreach ($pg['slice'] as $donor)
                <div class="dk-dbar" wire:key="db-{{ $loop->index }}">
                    <span>{{ $donor['name'] }}</span>
                    <span class="dk-track"><b class="dk-com" style="width:{{ round($donor['committed'] / $peak * 100, 2) }}%"></b><b class="dk-rec" style="width:{{ round(min($donor['received'], $donor['committed']) / $peak * 100, 2) }}%"></b></span>
                    <span class="dk-num dk-small">{{ Money::format($donor['received'], 'USD') }} / {{ Money::format($donor['committed'], 'USD') }}</span>
                </div>
            @endforeach
        </div>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
