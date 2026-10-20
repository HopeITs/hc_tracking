<?php

use App\Enums\ProposalStatus;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\Proposal;
use App\Support\Desk\Money;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Proposal pipeline: how many proposals sit in each status (each bar
 * filters the list below), then the proposals themselves. Budgets show
 * only to funding-agreement viewers (.ai/rules/pages.md "Hide money").
 */
new class extends Component {
    use IsDeskPanel;

    /**
     * The artifact's pill tone per status.
     */
    private const array Tones = [
        'concept note review' => 'act', 'concept note submitted' => 'act', 'proposal review' => 'act', 'submitted' => 'act',
        'on hold' => 'warn', 'approved' => 'good', 'active' => 'good', 'final reports' => 'good', 'completed' => 'mute', 'rejected' => 'bad',
    ];

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        $byStatus = $this->visible(Proposal::query(), 'proposals')->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(ProposalStatus::cases())->mapWithKeys(fn (ProposalStatus $status): array => [$status->value => (int) ($byStatus[$status->value] ?? 0)])->all();
    }

    /**
     * @return Collection<int, Proposal>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->visible(Proposal::query(), 'proposals')
            ->select(['id', 'slug', 'name', 'status'])
            ->with(['donorProposals:id,proposal_id,donor_id,budget,currency', 'donorProposals.donor:id,name'])
            ->when($this->filter !== 'all', fn ($query) => $query->where('status', $this->filter))
            ->latest('id')
            ->get();
    }

    public function tone(ProposalStatus $status): string
    {
        return self::Tones[$status->value] ?? '';
    }
};
?>

@php
    $counts = $this->counts;
    $peak = max(1, ...array_values($counts));
    $pg = $this->paginate($this->rows, 4);
    $showMoney = $this->canView('funding-agreements');
@endphp

<x-desk.panel id="pipeline" register="proposals.index" :size="$size" :index="$index" :total="$total" :count="array_sum($counts)">
    <div class="dk-funnel">
        @foreach (ProposalStatus::cases() as $status)
            @php
                $on = $filter === $status->value;
                $tone = $this->tone($status);
            @endphp
            <button type="button" wire:key="fb-{{ $loop->index }}" @class(['dk-fb', 'dk-on' => $on]) wire:click="setFilter('{{ $on ? 'all' : $status->value }}')" title="{{ $status->label() }}">
                <span class="dk-fl">{{ $status->label() }}</span>
                <span class="dk-fbar"><b @class([$tone !== '' ? 'dk-'.$tone : null]) style="width:{{ round($counts[$status->value] / $peak * 100, 2) }}%"></b></span>
                <span class="dk-num">{{ $counts[$status->value] }}</span>
            </button>
        @endforeach
    </div>
    <ul class="dk-plist">
        @foreach ($pg['slice'] as $proposal)
            @php
                $tone = $this->tone($proposal->status);
                $agreement = $proposal->donorProposals->first();
            @endphp
            <li wire:key="p-{{ $proposal->id }}">
                <div>
                    <strong><a href="{{ route('proposals.show', $proposal) }}" wire:navigate>{{ $proposal->name }}</a></strong>
                    <span class="dk-is">{{ $proposal->donorProposals->map(fn ($link) => $link->donor?->name)->filter()->implode(', ') ?: __('desk.no_donor_yet') }}</span>
                </div>
                <span @class(['dk-pill', $tone !== '' ? 'dk-'.$tone : null])>{{ $proposal->status->label() }}</span>
                @if ($showMoney)
                    <span class="dk-num dk-small">{{ $agreement !== null && (float) $agreement->budget > 0 ? Money::format($agreement->budget, $agreement->currency) : '—' }}</span>
                @endif
            </li>
        @endforeach
    </ul>

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
