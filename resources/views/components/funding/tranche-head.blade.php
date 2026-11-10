@props(['sortable' => false, 'active' => '', 'dir' => 'asc', 'actions' => true, 'compact' => false, 'conversions' => false])

@php
    /**
     * The headings every tranche table shares, in the order x-funding.tranche-row
     * prints its cells. A page that adds an identity column prints that heading
     * itself, before this component.
     *
     * `compact` keeps the five a register is read by — status, when it is due,
     * what was sent, what landed, the gap — and leaves the journey detail to
     * the Overview ladder and the edit form. The columns it drops are exactly
     * the ones x-funding.tranche-row drops, so the two stay in step.
     */
    $columns = array_values(array_filter([
        ['sort' => 'status', 'label' => __('common.status'), 'numeric' => false],
        $compact ? null : ['sort' => 'account', 'label' => __('transfers.account'), 'numeric' => false],
        ['sort' => 'scheduled', 'label' => __('transfers.scheduled'), 'numeric' => false],
        $compact ? null : ['sort' => 'sent', 'label' => __('transfers.sent_on'), 'numeric' => false],
        $compact ? null : ['sort' => 'received', 'label' => __('transfers.received_on'), 'numeric' => false],
        $compact ? null : ['sort' => null, 'label' => __('common.currency'), 'numeric' => false],
        ['sort' => 'amount', 'label' => __('finance_tables.amount_sent'), 'numeric' => true],
        $compact ? null : ['sort' => 'fees', 'label' => __('transfers.fees'), 'numeric' => true],
        $compact && ! $conversions ? null : ['sort' => 'rate', 'label' => __('transfers.exchange_rate'), 'numeric' => true],
        $compact && ! $conversions ? null : ['sort' => 'gross', 'label' => __('finance_tables.gross_received'), 'numeric' => true],
        ['sort' => 'net', 'label' => __('common.net_received'), 'numeric' => true],
        ['sort' => 'difference', 'label' => __('finance_tables.difference'), 'numeric' => true],
    ]));
@endphp

@foreach ($columns as $column)
    <x-funding.register.th :sort="$sortable ? $column['sort'] : null" :active="$active" :dir="$dir" :numeric="$column['numeric']">{{ $column['label'] }}</x-funding.register.th>
@endforeach

@if ($actions)
    <th scope="col"><span class="sr-only">{{ __('common.actions') }}</span></th>
@endif
