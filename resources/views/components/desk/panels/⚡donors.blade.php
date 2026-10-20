<?php

use App\Enums\ArStatus;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Donors: each donor with its type, proposals, last contact and next
 * step. Money (received against committed, per currency) is read and shown
 * only to viewers who may see funding agreements and transfers
 * (.ai/rules/pages.md "Hide money, keep names").
 */
new class extends Component {
    use IsDeskPanel;

    /**
     * @return Collection<int, Donor>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->visible(Donor::query(), 'donors')
            ->select(['id', 'slug', 'name', 'type', 'is_internal', 'last_contacted_on', 'next_step'])
            ->withCount('proposals')
            ->orderBy('name')
            ->get();
    }

    /**
     * donor id => list of [currency, received, committed].
     *
     * @return array<int, list<array{currency: string, received: float, committed: float}>>
     */
    #[Computed]
    public function money(): array
    {
        if (! $this->canViewFundingPosition()) {
            return [];
        }

        $committed = DonorProposal::query()
            ->selectRaw('donor_id, currency, sum(budget) as total')
            ->groupBy('donor_id', 'currency')
            ->get();

        $received = TransfersTracking::query()
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->where('ar_status', ArStatus::Received)
            ->selectRaw('donor_proposal.donor_id, amount_received_currency as currency, sum(net_amount_received) as total')
            ->groupBy('donor_proposal.donor_id', 'amount_received_currency')
            ->get()
            ->mapWithKeys(fn ($row): array => [$row->donor_id.'|'.$row->currency => (float) $row->total]);

        $money = [];

        foreach ($committed as $row) {
            $currency = $row->currency instanceof BackedEnum ? $row->currency->value : (string) $row->currency;
            $money[(int) $row->donor_id][] = [
                'currency' => $currency,
                'received' => (float) ($received[$row->donor_id.'|'.$currency] ?? 0),
                'committed' => (float) $row->total,
            ];
        }

        return $money;
    }
};
?>

@php
    $pg = $this->paginate($this->rows, 4);
    $money = $this->money;
@endphp

<x-desk.panel id="donors" register="donors.index" :size="$size" :index="$index" :total="$total" :count="$pg['total']">
    @if ($pg['total'] > 0)
        <ul class="dk-plist">
            @foreach ($pg['slice'] as $donor)
                <li wire:key="d-{{ $donor->id }}">
                    <div>
                        <strong><a href="{{ route('donors.show', $donor) }}" wire:navigate>{{ $donor->name }}</a>@if ($donor->is_internal) <span class="dk-pill dk-mute">{{ __('desk.internal') }}</span>@endif</strong>
                        <span class="dk-is">@if ($donor->type !== null){{ $donor->type->label() }} · @endif{{ trans_choice('desk.proposals_count', $donor->proposals_count, ['count' => $donor->proposals_count]) }}@if ($donor->last_contacted_on !== null) · {{ __('desk.last_contact') }} {{ \App\Support\Desk\RelativeDay::for($donor->last_contacted_on) }}@endif</span>
                        @if (filled($donor->next_step))
                            <span class="dk-is">→ {{ $donor->next_step }}</span>
                        @endif
                    </div>
                    @foreach ($money[$donor->id] ?? [] as $line)
                        <span class="dk-num dk-small">{{ \App\Support\Desk\Money::format($line['received'], $line['currency']) }}<br><span class="dk-muted">/ {{ \App\Support\Desk\Money::format($line['committed'], $line['currency']) }}</span></span>
                    @endforeach
                </li>
            @endforeach
        </ul>
    @else
        <p class="dk-pempty">{{ __('desk.panel.donors.empty') }}</p>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
