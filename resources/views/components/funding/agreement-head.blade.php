@props(['columns', 'sortable' => false, 'active' => '', 'dir' => 'asc', 'actions' => true])

{{--
    The headings of an agreement table, from the column list
    App\Support\AgreementMoney::columns() builds. A page prints its identity
    column's heading itself, before this component. The sort key of a column
    is its key; currency and reports do not sort.
--}}
@foreach ($columns as $column)
    <x-funding.register.th
        :sort="$sortable && ! in_array($column['key'], ['currency', 'reports'], true) ? $column['key'] : null"
        :active="$active"
        :dir="$dir"
        :numeric="$column['numeric']"
    >{{ $column['label'] }}</x-funding.register.th>
@endforeach

@if ($actions)
    <th scope="col"><span class="sr-only">{{ __('common.actions') }}</span></th>
@endif
