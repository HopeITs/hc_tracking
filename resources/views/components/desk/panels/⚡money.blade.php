<?php

use App\Enums\ArStatus;
use App\Livewire\Concerns\Dashboard\ChartsDashboard;
use App\Livewire\Concerns\Dashboard\ReadsDashboardAttention;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use App\Support\Desk\Money;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Funding position: one small total line per currency (received of
 * committed, currencies never summed), then received against committed per
 * funding agreement, named by its project (or its proposal when no project
 * is linked yet), with the next open tranche and its state. Status: fully
 * received, partly, nothing yet, or an overdue tranche. Received money
 * counts only when it arrived in the agreement's own currency. Money
 * viewers only (canViewFundingPosition).
 */
new class extends Component {
    use ChartsDashboard;
    use IsDeskPanel;
    use ReadsDashboardAttention;

    /**
     * @return Collection<int, array{agreement: DonorProposal, name: string, donor: string, program: string|null, committed: float, received: float, share: int, status: string, next: TransfersTracking|null, nextState: string|null}>
     */
    #[Computed]
    public function rows(): Collection
    {
        if (! $this->canViewFundingPosition()) {
            return new Collection;
        }

        return DonorProposal::query()
            ->select(['id', 'slug', 'donor_id', 'proposal_id', 'budget', 'currency'])
            ->where('budget', '>', 0)
            ->with([
                'donor:id,name',
                'proposal:id,name',
                'proposal.projects:id,name,program_id',
                'proposal.projects.program:id',
                'transfers' => fn ($query) => $query->select(['id', 'donor_proposal_id', 'payment_amount', 'amount_transfered_currency', 'scheduled_date', 'transfer_date', 'ar_status', 'net_amount_received', 'amount_received_currency']),
            ])
            ->get()
            ->map(function (DonorProposal $agreement): array {
                $currency = $agreement->currency->value;
                $committed = (float) $agreement->budget;
                $received = (float) $agreement->transfers
                    ->filter(fn (TransfersTracking $transfer): bool => $transfer->ar_status === ArStatus::Received && $transfer->amount_received_currency?->value === $currency)
                    ->sum('net_amount_received');
                $open = $agreement->transfers->filter(fn (TransfersTracking $transfer): bool => $transfer->ar_status === ArStatus::Pending)->sortBy('scheduled_date');
                $next = $open->first();
                $nextState = $next === null ? null : ($next->transfer_date !== null ? 'transit' : ($next->scheduled_date?->lt(today()) ? 'late' : 'upcoming'));
                $project = $agreement->proposal?->projects->first();

                return [
                    'agreement' => $agreement,
                    'name' => $project?->name ?? $agreement->proposal?->name ?? '—',
                    'donor' => $agreement->donor?->name ?? '—',
                    'program' => $project?->program_id === null ? null : $project->program?->name,
                    'committed' => $committed,
                    'received' => $received,
                    'share' => (int) floor($received / $committed * 100),
                    'status' => match (true) {
                        $open->contains(fn (TransfersTracking $transfer): bool => $transfer->transfer_date === null && $transfer->scheduled_date?->lt(today())) => 'overdue',
                        $received >= $committed => 'full',
                        $received > 0 => 'partly',
                        default => 'none',
                    },
                    'next' => $next,
                    'nextState' => $nextState,
                ];
            })
            ->sortBy([
                fn (array $a, array $b): int => ($b['status'] === 'overdue') <=> ($a['status'] === 'overdue'),
                fn (array $a, array $b): int => $b['committed'] <=> $a['committed'],
            ])
            ->values();
    }

    /**
     * @return array<string, string>
     */
    protected function facetsOf(mixed $row): array
    {
        return array_filter([
            'donor' => $row['donor'],
            'project' => $row['name'],
            'program' => $row['program'],
            'status' => __('desk.funding_status.'.$row['status']),
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }
};
?>

@php
    $rows = $this->rows;
    $pg = $this->paginate($rows, self::PerPage);
    $format = __('desk.date_short');
@endphp

<x-desk.panel :facets="$pg['facets']" id="money" register="funding-agreements.index" :size="$size" :index="$index" :total="$total" :count="$rows->count()">
    @if ($this->fundingPosition->isNotEmpty())
        <p class="dk-fp-tot dk-num">
            @foreach ($this->fundingPosition as $total)
                <span wire:key="fpt-{{ $total['currency'] }}"><b>{{ Money::format($total['received'], $total['currency']) }}</b> {{ __('desk.of') }} {{ Money::format($total['committed'], $total['currency']) }}@if ($total['share'] !== null) <span class="dk-muted">({{ $total['share'] }}%)</span>@endif</span>
            @endforeach
        </p>
    @endif

    @if ($pg['total'] > 0)
        <ul class="dk-fp" {{ $this->fitAttributes($pg) }}>
            @foreach ($pg['slice'] as $row)
                @php
                    $currency = $row['agreement']->currency->value;
                @endphp
                <li wire:key="fp-{{ $row['agreement']->id }}" data-status="{{ $row['status'] }}">
                    <a href="{{ route('funding-agreements.show', $row['agreement']) }}" wire:navigate class="dk-fp-n" title="{{ $row['name'] }} · {{ $row['donor'] }}">{{ $row['name'] }}<span class="dk-is"> · {{ $row['donor'] }}</span></a>
                    <span class="dk-fp-bar" title="{{ $row['share'] }}%"><span class="dk-meter"><b style="width:{{ min(100, $row['share']) }}%"></b></span></span>
                    <span class="dk-fp-v dk-num">{{ Money::format($row['received'], $currency) }} <span class="dk-muted">{{ __('desk.of') }} {{ Money::format($row['committed'], $currency) }}</span></span>
                    <span class="dk-fp-t">
                        @if ($row['next'] !== null)
                            <span @class(['dk-pill', 'dk-bad' => $row['nextState'] === 'late', 'dk-act' => $row['nextState'] === 'transit', 'dk-mute' => $row['nextState'] === 'upcoming']) title="{{ Money::format($row['next']->payment_amount, $row['next']->amount_transfered_currency->value) }}">{{ __('desk.tranche_state.'.$row['nextState'], ['date' => $row['next']->scheduled_date?->translatedFormat($format) ?? '—']) }}</span>
                        @else
                            <span class="dk-pill {{ $row['status'] === 'full' ? 'dk-good' : 'dk-mute' }}">{{ __('desk.funding_status.'.$row['status']) }}</span>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    @else
        <p class="dk-pempty">{{ __('desk.nothing_here') }}</p>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
