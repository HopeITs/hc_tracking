@props(['agreement', 'columns', 'yearOnly' => false])

@php
    /**
     * One agreement as a table row. Every money figure is a `money_*` column
     * DonorProposal::withMoney() selected, so the row, the sort, the totals
     * and the export all read the same number.
     *
     * @var \App\Models\DonorProposal $agreement
     */
    $money = fn (string|float|int|null $value): string => number_format((float) $value, 2);
    $budget = (float) $agreement->budget;
    $today = \Carbon\CarbonImmutable::today();
@endphp

<tr wire:key="agreement-{{ $agreement->id }}" data-agreement-row="{{ $agreement->id }}">
    {{ $lead ?? '' }}

    @foreach ($columns as $column)
        @switch($column['key'])
            @case('currency')
                <td data-label="{{ $column['label'] }}">{{ $agreement->currency->value }}</td>
                @break

            @case('budget')
                <td class="num" data-label="{{ $column['label'] }}" data-cell="budget">
                    <span class="fig font-semibold">{{ $money($budget) }}</span>
                </td>
                @break

            @case('received')
            @case('received_in_year')
                <td class="num" data-label="{{ $column['label'] }}" data-cell="{{ $column['key'] }}">
                    <span class="fig font-semibold text-funding">{{ $money($agreement->getAttribute('money_'.$column['key'])) }}</span>
                </td>
                @break

            @case('percent')
                @php
                    // In year-only mode the share is of what that year expected, not of the whole budget.
                    $percentBase = $yearOnly ? (float) $agreement->money_scheduled : $budget;
                @endphp
                <td class="num" data-label="{{ $column['label'] }}" data-cell="percent">
                    <span class="fig">{{ \App\Models\DonorProposal::percentLabel((float) $agreement->money_received, $percentBase) }}%</span>
                </td>
                @break

            @case('overdue')
            @case('failed')
                @php
                    $alarmValue = (float) $agreement->getAttribute('money_'.$column['key']);
                @endphp
                <td class="num" data-label="{{ $column['label'] }}" data-cell="{{ $column['key'] }}">
                    <span @class(['fig', 'font-semibold text-status-critical' => $alarmValue > 0])>{{ $money($alarmValue) }}</span>
                </td>
                @break

            @case('next_due')
                @php
                    $nextDue = $agreement->money_next_due !== null ? \Carbon\CarbonImmutable::parse($agreement->money_next_due) : null;
                @endphp
                <td class="date" data-label="{{ $column['label'] }}" data-cell="next_due">
                    @if ($nextDue === null)
                        —
                    @else
                        <span @class(['font-semibold text-status-critical' => $nextDue->lt($today)])>{{ $nextDue->translatedFormat(__('formats.date')) }}</span>
                    @endif
                </td>
                @break

            @case('tranches')
                <td class="num" data-label="{{ $column['label'] }}" data-cell="tranches">
                    @can('transfers.view')
                        <a href="{{ route('transfers.index', ['agreement' => $agreement->id]) }}" wire:navigate class="fig font-semibold underline underline-offset-2" data-tranches-link>{{ (int) $agreement->money_tranches }}</a>
                    @else
                        <span class="fig">{{ (int) $agreement->money_tranches }}</span>
                    @endcan
                </td>
                @break

            @case('reports')
                <td data-label="{{ $column['label'] }}" data-cell="reports">
                    @if ((int) $agreement->overdue_reports_count > 0)
                        <span class="font-semibold text-status-critical" data-report-overdue>{{ trans_choice('funding_agreements.count_donor_reports_overdue', (int) $agreement->overdue_reports_count, ['count' => (int) $agreement->overdue_reports_count]) }}</span>
                    @elseif ($agreement->next_report_due !== null)
                        <span data-report-due>{{ \Carbon\CarbonImmutable::parse($agreement->next_report_due)->translatedFormat(__('formats.date')) }}</span>
                    @else
                        —
                    @endif
                </td>
                @break

            @default
                <td class="num" data-label="{{ $column['label'] }}" data-cell="{{ $column['key'] }}">
                    <span class="fig">{{ $money($agreement->getAttribute('money_'.$column['key'])) }}</span>
                </td>
        @endswitch
    @endforeach

    {{ $actions ?? '' }}
</tr>
