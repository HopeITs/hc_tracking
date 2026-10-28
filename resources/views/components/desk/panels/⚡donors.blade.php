<?php

use App\Enums\ArStatus;
use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Donors: each donor we have written a proposal for (the ones we actually
 * work with), most recently active first: its type, a strip of its
 * proposals coloured by where each stands (in review, approved, rejected),
 * last contact and next step. Money (received against committed, per currency) is read and shown
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
            ->whereHas('proposalsWrittenFor')
            ->withCount('proposalsWrittenFor as proposals_count')
            ->with(['proposalsWrittenFor' => fn ($query) => $query->select(['id', 'donor_id', 'name', 'status', 'updated_at'])->latest('updated_at')])
            ->withMax('proposalsWrittenFor as last_active_at', 'updated_at')
            ->orderByDesc('last_active_at')
            ->orderBy('name')
            ->get();
    }

    /**
     * The tone a proposal's status takes in the donor's strip.
     */
    public function stripTone(ProposalStatus $status): string
    {
        return match (true) {
            in_array($status, [ProposalStatus::Approved, ProposalStatus::Active, ProposalStatus::FinalReports, ProposalStatus::Completed], true) => 'good',
            $status === ProposalStatus::Rejected => 'bad',
            in_array($status, ProposalPhase::Planning->statuses(), true) || in_array($status, ProposalPhase::Ideation->statuses(), true) => 'act',
            default => 'mute',
        };
    }

    /**
     * Type (from the enum column) and the donor's standing: what its newest
     * proposal is doing.
     *
     * @return array<string, string>
     */
    protected function facetsOf(mixed $row): array
    {
        $newest = $row->proposalsWrittenFor->first();

        return array_filter([
            'type' => $row->type?->label(),
            'status' => $newest === null ? null : __('desk.donor_strip.'.$this->stripTone($newest->status)),
        ]);
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
    $pg = $this->paginate($this->rows, self::PerPage);
    $money = $this->money;
@endphp

<x-desk.panel :facets="$pg['facets']" id="donors" register="donors.index" :size="$size" :index="$index" :total="$total" :count="$pg['total']">
    @if ($pg['total'] > 0)
        <ul @class(['dk-dn', 'dk-dn-money' => $money !== []])>
            @foreach ($pg['slice'] as $donor)
                @php
                    $lines = $money[$donor->id] ?? [];
                    $first = $lines[0] ?? null;
                    $share = $first === null || $first['committed'] <= 0 ? 0 : (int) floor($first['received'] / $first['committed'] * 100);
                @endphp
                <li wire:key="d-{{ $donor->id }}">
                    <a href="{{ route('donors.show', $donor) }}" wire:navigate class="dk-dn-n" title="{{ $donor->name }}">{{ $donor->name }}@if ($donor->type !== null)<span class="dk-is"> · {{ $donor->type->label() }}</span>@endif</a>
                    <span class="dk-dn-strip" title="{{ trans_choice('desk.proposals_count', $donor->proposals_count, ['count' => $donor->proposals_count]) }}">
                        @foreach ($donor->proposalsWrittenFor->take(8) as $proposal)
                            <i class="dk-dot-{{ $this->stripTone($proposal->status) }}" title="{{ $proposal->name }} — {{ $proposal->status->label() }}"></i>
                        @endforeach
                        @if ($donor->proposals_count > 8)<b class="dk-num">+{{ $donor->proposals_count - 8 }}</b>@endif
                    </span>
                    <span class="dk-dn-m dk-is" title="{{ $donor->next_step }}">@if ($donor->last_contacted_on !== null){{ __('desk.last_contact') }} {{ \App\Support\Desk\RelativeDay::for($donor->last_contacted_on) }}@else {{ __('desk.no_contact_yet') }} @endif @if (filled($donor->next_step))· → {{ $donor->next_step }}@endif</span>
                    @if ($money !== [])
                        <span class="dk-dn-v dk-num" title="{{ collect($lines)->map(fn ($line) => \App\Support\Desk\Money::format($line['received'], $line['currency']).' / '.\App\Support\Desk\Money::format($line['committed'], $line['currency']))->implode(' · ') }}">
                            @if ($first !== null)
                                {{ \App\Support\Desk\Money::format($first['received'], $first['currency']) }} <span class="dk-muted">{{ __('desk.of') }} {{ \App\Support\Desk\Money::format($first['committed'], $first['currency']) }}</span>@if (count($lines) > 1) <span class="dk-muted">+{{ count($lines) - 1 }}</span>@endif
                                <span class="dk-meter"><b style="width:{{ min(100, $share) }}%"></b></span>
                            @else
                                <span class="dk-muted">{{ __('desk.no_agreement_yet') }}</span>
                            @endif
                        </span>
                    @endif
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
