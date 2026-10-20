{{--
    The proposals register's Board view: a column per status, a card per
    proposal (title, donor, requested budget). Donor and budget show only to
    a viewer who may see agreements. Included by ⚡index.blade.php.
--}}
<x-desk.board data-test="proposal-board">
    @foreach ($this->boardColumns as $column)
        <x-desk.board-column :label="$column['label']" :count="$column['count']" :key="$column['key']" wire:key="proposal-column-{{ $column['key'] }}">
            @foreach ($column['items'] as $boardProposal)
                <x-desk.board-card :href="route('proposals.show', $boardProposal)" wire:key="proposal-card-{{ $boardProposal->id }}" data-test="proposal-board-card-{{ $boardProposal->id }}">
                    <strong>{{ $boardProposal->name }}</strong>
                    @if ($canAgreements && $boardProposal->donor !== null)
                        <span class="dk-is">{{ $boardProposal->donor->name }}</span>
                    @endif
                    @if ($canAgreements && $boardProposal->requested_budget !== null && $boardProposal->requested_currency !== null)
                        <span class="dk-num dk-small">{{ App\Support\Desk\Money::format($boardProposal->requested_budget, $boardProposal->requested_currency) }}</span>
                    @endif
                </x-desk.board-card>
            @endforeach
        </x-desk.board-column>
    @endforeach
</x-desk.board>
