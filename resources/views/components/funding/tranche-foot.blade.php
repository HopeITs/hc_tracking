@props(['lines' => [], 'lead' => 0, 'actions' => true, 'label' => null])

@php
    /**
     * One totals row per currency, under the columns x-funding.tranche-row
     * prints. `lead` is how many identity cells the page prints before the
     * status cell, so the label spans them plus status, account and the three
     * dates.
     *
     * @var list<array{currency: string, tranches: int, sent: float, fees: float, gross: float, net: float, shortfall: float, overage: float}> $lines
     */
    $money = fn (float $value): string => number_format($value, 2);
@endphp

@foreach ($lines as $line)
    <tr wire:key="tranche-total-{{ $line['currency'] }}" data-register-total="{{ $line['currency'] }}">
        <td colspan="{{ $lead + 5 }}" data-wide>
            {{ $label ?? __('finance_tables.total') }}
            <span class="sub">{{ trans_choice('transfers.count_tranches', $line['tranches'], ['count' => $line['tranches']]) }}</span>
        </td>
        <td data-label="{{ __('common.currency') }}">{{ $line['currency'] }}</td>
        <td class="num" data-label="{{ __('finance_tables.amount_sent') }}"><span class="fig">{{ $money($line['sent']) }}</span></td>
        <td class="num" data-label="{{ __('transfers.fees') }}"><span class="fig">{{ $money($line['fees']) }}</span></td>
        <td></td>
        <td class="num" data-label="{{ __('finance_tables.gross_received') }}"><span class="fig">{{ $money($line['gross']) }}</span></td>
        <td class="num" data-label="{{ __('common.net_received') }}"><span class="fig text-funding">{{ $money($line['net']) }}</span></td>
        {{-- An overage is real, but it never puts a shortfall back: the two are printed apart. --}}
        <td class="num" data-label="{{ __('finance_tables.difference') }}" data-total-difference>
            @if ($line['shortfall'] > 0)
                <span class="fig text-status-critical">−{{ $money($line['shortfall']) }}</span>
            @endif
            @if ($line['overage'] > 0)
                <span class="fig block text-funding">+{{ $money($line['overage']) }}</span>
            @endif
            @if ($line['shortfall'] <= 0 && $line['overage'] <= 0)
                <span class="fig">0.00</span>
            @endif
        </td>
        @if ($actions)
            <td></td>
        @endif
    </tr>
@endforeach
