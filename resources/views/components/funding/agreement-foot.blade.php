@props(['lines' => [], 'columns', 'lead' => 1, 'actions' => true, 'yearOnly' => false, 'label' => null])

@php
    /**
     * One totals row per currency, under the columns x-funding.agreement-row
     * prints. `lead` is how many identity cells the page prints first.
     */
    $money = fn (string|float|int $value): string => number_format((float) $value, 2);
@endphp

@foreach ($lines as $line)
    <tr wire:key="agreement-total-{{ $line['currency'] }}" data-register-total="{{ $line['currency'] }}">
        @if ($lead > 0)
            <td colspan="{{ $lead }}" data-wide>
                {{ $label ?? __('finance_tables.total') }}
                <span class="sub">{{ trans_choice('common.count_agreement', $line['agreements'], ['count' => $line['agreements']]) }}</span>
            </td>
        @endif

        @foreach ($columns as $column)
            @switch($column['key'])
                @case('currency')
                    <td data-label="{{ $column['label'] }}">{{ $line['currency'] }}</td>
                    @break

                @case('percent')
                    <td class="num" data-label="{{ $column['label'] }}">
                        <span class="fig">{{ \App\Models\DonorProposal::percentLabel((float) $line['received'], (float) ($yearOnly ? $line['scheduled'] : $line['budget'])) }}%</span>
                    </td>
                    @break

                @case('tranches')
                    <td class="num" data-label="{{ $column['label'] }}"><span class="fig">{{ (int) $line['tranches'] }}</span></td>
                    @break

                @case('next_due')
                @case('reports')
                    <td></td>
                    @break

                @default
                    <td class="num" data-label="{{ $column['label'] }}"><span class="fig">{{ $money($line[$column['key']]) }}</span></td>
            @endswitch
        @endforeach

        @if ($actions)
            <td></td>
        @endif
    </tr>
@endforeach
