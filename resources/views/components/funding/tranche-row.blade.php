@props(['transfer'])

@php
    /** @var \App\Models\TransfersTracking $transfer */
    $state = $transfer->state();
    $sentCurrency = $transfer->amount_transfered_currency;
    $isReceived = $state === 'received';
    $difference = $transfer->difference();
    $timing = \App\Support\Tranches::timing($transfer);
    $money = fn (string|float|int $value): string => number_format((float) $value, 2);
    $date = fn (?\DateTimeInterface $value): string => $value?->translatedFormat(__('formats.date')) ?? '—';
    /* Trimming the raw decimal:6 string would turn a rate of 0 into an empty cell. */
    $rate = rtrim(rtrim(number_format((float) $transfer->exchange_rate, 6, '.', ''), '0'), '.');
    $rate = $rate === '' ? '0' : $rate;
@endphp

<tr wire:key="transfer-{{ $transfer->id }}" data-transfer="{{ $transfer->id }}" data-transfer-state="{{ $state }}">
    {{ $lead ?? '' }}

    <td data-label="{{ __('common.status') }}">
        <x-funding.state :transfer="$transfer" />
        @if ($timing !== null)
            <span @class(['sub', 'text-status-critical!' => $state === 'late'])>{{ $timing }}</span>
        @endif
    </td>

    <td data-label="{{ __('transfers.account') }}">
        @if ($transfer->account === null)
            <span class="italic text-secondary-medium dark:text-ink-muted">{{ __('transfers.no_account') }}</span>
        @else
            {{ $transfer->account->account_name }}
        @endif
    </td>

    <td class="date" data-label="{{ __('transfers.scheduled') }}">{{ $date($transfer->scheduled_date) }}</td>
    <td class="date" data-label="{{ __('transfers.sent_on') }}">{{ $date($transfer->transfer_date) }}</td>
    <td class="date" data-label="{{ __('transfers.received_on') }}">{{ $date($transfer->receive_date) }}</td>

    <td data-label="{{ __('common.currency') }}">{{ $sentCurrency->value }}</td>

    <td class="num" data-label="{{ __('finance_tables.amount_sent') }}" data-cell="sent">
        <span class="fig font-semibold">{{ $money($transfer->payment_amount) }}</span>
    </td>

    <td class="num" data-label="{{ __('transfers.fees') }}" data-cell="fees">
        @if ((float) $transfer->fees > 0)
            <span class="fig">{{ $money($transfer->fees) }}</span>
            @if ($transfer->fees_currency !== $sentCurrency)
                <span class="sub">{{ $transfer->fees_currency->value }}</span>
            @endif
        @else
            —
        @endif
    </td>

    <td class="num" data-label="{{ __('transfers.exchange_rate') }}" data-cell="rate">
        <span class="fig">{{ $rate }}</span>
    </td>

    {{-- A pending tranche may carry amounts filled in ahead of time. They have not landed, so they are not shown. --}}
    <td class="num" data-label="{{ __('finance_tables.gross_received') }}" data-cell="gross">
        @if ($isReceived && $transfer->amount_received !== null)
            <span class="fig">{{ $money($transfer->amount_received) }}</span>
        @else
            —
        @endif
    </td>

    <td class="num" data-label="{{ __('common.net_received') }}" data-cell="net">
        @if ($transfer->isMissingNetAmount())
            <span class="font-semibold text-status-warn">{{ __('transfers.not_recorded_yet') }}</span>
        @elseif ($isReceived)
            <span class="fig font-semibold text-funding">{{ $money($transfer->net_amount_received) }}</span>
            @if ($transfer->amount_received_currency !== $sentCurrency)
                <span class="sub">{{ $transfer->amount_received_currency->value }}</span>
            @endif
        @else
            —
        @endif
    </td>

    {{-- Only a settled tranche has a difference: two currencies are never subtracted. --}}
    <td class="num" data-label="{{ __('finance_tables.difference') }}" data-cell="difference">
        @if ($difference === null)
            @if ($transfer->landedInAnotherCurrency() && ! $transfer->isMissingNetAmount())
                <span class="text-xs font-semibold text-secondary-medium dark:text-ink-muted">{{ __('finance_tables.converted') }}</span>
            @else
                —
            @endif
        @elseif ($difference < 0)
            <span class="fig font-semibold text-status-critical">−{{ $money(abs($difference)) }}</span>
        @elseif ($difference > 0)
            <span class="fig font-semibold text-funding">+{{ $money($difference) }}</span>
        @else
            <span class="fig">0.00</span>
        @endif
    </td>

    {{ $actions ?? '' }}
</tr>
