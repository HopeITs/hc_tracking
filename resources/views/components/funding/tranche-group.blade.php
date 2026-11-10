@props(['label', 'lines' => [], 'lead' => 0, 'actions' => true, 'group' => '', 'compact' => false, 'conversions' => false])

@php
    /**
     * The header of one group of tranches, with the group's own subtotals:
     * one line per currency in each money cell, in the same order.
     *
     * @var iterable<array{currency: string, tranches: int, sent: float, fees: float, gross: float, net: float, shortfall: float, overage: float}> $lines
     */
    $lines = collect($lines)->map(fn ($line): array => (array) $line)->values();
    $money = fn (string|float|int $value): string => number_format((float) $value, 2);
    $count = (int) $lines->sum('tranches');
@endphp

<tr class="register-group" wire:key="group-{{ $group }}" data-group="{{ $group }}">
    <td colspan="{{ $lead + ($compact ? 2 : 5) }}" data-wide>
        {{ $label }}
        <span class="sub">{{ trans_choice('transfers.count_tranches', $count, ['count' => $count]) }}</span>
    </td>
    @unless ($compact)
        <td data-label="{{ __('common.currency') }}">
            @foreach ($lines as $line)
                <span class="block">{{ $line['currency'] }}</span>
            @endforeach
        </td>
    @endunless
    <td class="num" data-label="{{ __('finance_tables.amount_sent') }}">
        @foreach ($lines as $line)
            <span class="fig block">{{ $money($line['sent']) }}@if ($compact) <span class="sub">{{ $line['currency'] }}</span>@endif</span>
        @endforeach
    </td>
    @unless ($compact)
        <td class="num" data-label="{{ __('transfers.fees') }}">
            @foreach ($lines as $line)
                <span class="fig block">{{ $money($line['fees']) }}</span>
            @endforeach
        </td>
    @endunless
    @unless ($compact && ! $conversions)
        <td></td>
        <td class="num" data-label="{{ __('finance_tables.gross_received') }}">
            @foreach ($lines as $line)
                <span class="fig block">{{ $money($line['gross']) }}</span>
            @endforeach
        </td>
    @endunless
    <td class="num" data-label="{{ __('common.net_received') }}">
        @foreach ($lines as $line)
            <span class="fig block text-funding">{{ $money($line['net']) }}</span>
        @endforeach
    </td>
    <td class="num" data-label="{{ __('finance_tables.difference') }}">
        @foreach ($lines as $line)
            <span class="block">
                @if ((float) $line['shortfall'] > 0)
                    <span class="fig text-status-critical">−{{ $money($line['shortfall']) }}</span>
                @endif
                @if ((float) $line['overage'] > 0)
                    <span class="fig text-funding">+{{ $money($line['overage']) }}</span>
                @endif
                @if ((float) $line['shortfall'] <= 0 && (float) $line['overage'] <= 0)
                    <span class="fig">0.00</span>
                @endif
            </span>
        @endforeach
    </td>
    @if ($actions)
        <td></td>
    @endif
</tr>
