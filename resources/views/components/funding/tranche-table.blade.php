@props(['transfers', 'caption', 'editable' => false, 'party' => null, 'size' => 'lg'])

@php
    /**
     * A full tranche table for a collection already in memory.
     *
     * `party` names the identity column a page needs when its tranches belong
     * to several agreements: the proposal on a donor page, the donor on a
     * proposal page.
     */
    $partyLabel = $party === 'donor' ? __('funding_agreements.donor') : __('common.proposal');
@endphp

<x-funding.register :size="$size" :caption="$caption" {{ $attributes }}>
    <x-slot:head>
        @if ($party !== null)
            <x-funding.register.th>{{ $partyLabel }}</x-funding.register.th>
        @endif
        <x-funding.tranche-head :actions="$editable" />
    </x-slot:head>

    @foreach ($transfers as $transfer)
        <x-funding.tranche-row :transfer="$transfer">
            @if ($party !== null)
                @php
                    $rowAgreement = $transfer->donorProposal;
                    $rowParty = $party === 'donor' ? $rowAgreement->donor : $rowAgreement->proposal;
                    $partyRoute = $party === 'donor' ? 'donors.show' : 'proposals.show';
                    $partyAbility = $party === 'donor' ? 'donors.view' : 'proposals.view';
                @endphp
                <x-slot:lead>
                    <td data-label="{{ $partyLabel }}" data-wide>
                        @can($partyAbility)
                            <a href="{{ route($partyRoute, $rowParty) }}" wire:navigate class="font-semibold underline-offset-2 hover:underline">{{ $rowParty->name }}</a>
                        @else
                            <span class="font-semibold">{{ $rowParty->name }}</span>
                        @endcan
                        <span class="sub">
                            @can('funding-agreements.view')
                                <a href="{{ route('funding-agreements.show', $rowAgreement) }}" wire:navigate class="underline-offset-2 hover:underline">{{ filled($rowAgreement->external_project_number) ? $rowAgreement->external_project_number : __('proposals.view_agreement') }}</a>
                            @else
                                {{ $rowAgreement->external_project_number ?? __('common.no_external_project_number') }}
                            @endcan
                        </span>
                    </td>
                </x-slot:lead>
            @endif

            @if ($editable)
                <x-slot:actions>
                    <td>
                        @can('transfers.update')
                            <x-ui.button tone="ghost" size="sm" icon="pencil-square" icon:variant="outline" class="size-8!" wire:click="openTransferEdit({{ $transfer->id }})" :aria-label="__('transfers.edit_transfer')" data-test="edit-transfer-{{ $transfer->id }}" />
                        @endcan
                    </td>
                </x-slot:actions>
            @endif
        </x-funding.tranche-row>
    @endforeach
</x-funding.register>
