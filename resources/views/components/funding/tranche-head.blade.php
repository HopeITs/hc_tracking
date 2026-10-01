@props(['sortable' => false, 'active' => '', 'dir' => 'asc', 'actions' => true])

@php
    /**
     * The headings every tranche table shares, in the order x-funding.tranche-row
     * prints its cells. A page that adds an identity column prints that heading
     * itself, before this component.
     */
    $columns = [
        ['sort' => 'status', 'label' => __('common.status'), 'numeric' => false],
        ['sort' => 'account', 'label' => __('transfers.account'), 'numeric' => false],
        ['sort' => 'scheduled', 'label' => __('transfers.scheduled'), 'numeric' => false],
        ['sort' => 'sent', 'label' => __('transfers.sent_on'), 'numeric' => false],
        ['sort' => 'received', 'label' => __('transfers.received_on'), 'numeric' => false],
        ['sort' => null, 'label' => __('common.currency'), 'numeric' => false],
        ['sort' => 'amount', 'label' => __('finance_tables.amount_sent'), 'numeric' => true],
        ['sort' => 'fees', 'label' => __('transfers.fees'), 'numeric' => true],
        ['sort' => 'rate', 'label' => __('transfers.exchange_rate'), 'numeric' => true],
        ['sort' => 'gross', 'label' => __('finance_tables.gross_received'), 'numeric' => true],
        ['sort' => 'net', 'label' => __('common.net_received'), 'numeric' => true],
        ['sort' => 'difference', 'label' => __('finance_tables.difference'), 'numeric' => true],
    ];
@endphp

@foreach ($columns as $column)
    <x-funding.register.th :sort="$sortable ? $column['sort'] : null" :active="$active" :dir="$dir" :numeric="$column['numeric']">{{ $column['label'] }}</x-funding.register.th>
@endforeach

@if ($actions)
    <th scope="col"><span class="sr-only">{{ __('common.actions') }}</span></th>
@endif
