{{--
    The transfers register's Board view: a column per state, a card per
    tranche (amount, donor, proposal, scheduled date). Someone who may update
    transfers opens the tranche's form from the card. Included by
    ⚡index.blade.php.
--}}
@php
    $canUpdateTransfers = auth()->user()->can('transfers.update');
@endphp

<x-desk.board data-test="transfer-board">
    @foreach ($this->boardColumns as $column)
        <x-desk.board-column :label="$column['label']" :count="$column['count']" :key="$column['key']" wire:key="transfer-column-{{ $column['key'] }}">
            @foreach ($column['items'] as $boardTransfer)
                <x-desk.board-card
                    wire:key="transfer-card-{{ $boardTransfer->id }}"
                    data-test="transfer-board-card-{{ $boardTransfer->id }}"
                    :click="$canUpdateTransfers ? 'openTransferEdit('.$boardTransfer->id.')' : null"
                >
                    <strong class="dk-num">{{ App\Support\Desk\Money::format($boardTransfer->payment_amount, $boardTransfer->amount_transfered_currency) }}</strong>
                    <span class="dk-is">{{ $boardTransfer->donorProposal->donor->name }} · {{ $boardTransfer->donorProposal->proposal->name }}</span>
                    <span class="dk-is">{{ $boardTransfer->scheduled_date?->translatedFormat(__('formats.date_short')) ?? __('desk.register.undated') }}</span>
                </x-desk.board-card>
            @endforeach
        </x-desk.board-column>
    @endforeach
</x-desk.board>
