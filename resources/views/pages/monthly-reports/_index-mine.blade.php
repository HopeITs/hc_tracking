{{-- "My reports" on ⚡index.blade.php (M-06): one table, a row per generated month of the templates the user prepares (D-76), with fixed columns (place and the proposals it covers, month, status, due date, actions). What waits on the preparer comes first (mineRows()). Every empty state offers its one next step. --}}
@php
    $mineRows = $this->mineRows;
    $shownRows = array_slice($mineRows, 0, $queueLimit);
    $hiddenCount = count($mineRows) - count($shownRows);
@endphp

@if ($this->myMonths === [])
    <x-ui.panel>
        <x-ui.empty-state icon="calendar-days" :heading="__('monthly_reports.empty_mine')" :body="__('monthly_reports.empty_mine_body')">
            @if ($this->canValidate)
                <x-slot:action>
                    <x-ui.button size="sm" tone="primary" wire:click="$set('tab', 'validate')" data-empty-action="validate">{{ __('monthly_reports.go_to_validate') }}</x-ui.button>
                </x-slot:action>
            @endif
        </x-ui.empty-state>
    </x-ui.panel>
@elseif ($mineRows === [])
    <x-ui.panel>
        @if ($filtersAreActive)
            <x-ui.empty-state icon="calendar-days" :heading="__('monthly_reports.empty_filtered')" :body="__('common.change_the_filters_and_try_again')">
                <x-slot:action>
                    <x-ui.button size="sm" tone="primary" wire:click="clearFilters" data-empty-action="clear">{{ __('monthly_reports.clear_filters') }}</x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        @else
            {{-- Only a big figure narrows the table here: pressing it again lists every month. --}}
            <x-ui.empty-state icon="calendar-days" :heading="__('monthly_reports.empty_figure')">
                <x-slot:action>
                    <x-ui.button size="sm" tone="primary" wire:click="showOnly('{{ $show }}')" data-empty-action="show_all">{{ __('monthly_reports.show_every_month') }}</x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        @endif
    </x-ui.panel>
@else
    <div class="flex flex-col gap-3">
        <x-ui.data-table :caption="__('monthly_reports.tab_mine')" data-mine-table>
            <x-slot:head>
                <th>{{ __('monthly_reports.col_place') }}</th>
                <th class="w-40!">{{ __('monthly_reports.month') }}</th>
                <th class="w-44!">{{ __('monthly_reports.col_status') }}</th>
                <th>{{ __('monthly_reports.col_due') }}</th>
                <th class="w-48!"><span class="sr-only">{{ __('common.actions') }}</span></th>
            </x-slot:head>

            @foreach ($shownRows as $row)
                @include('pages.monthly-reports._index-month-row', ['row' => $row, 'rowGroup' => $row['group']])
            @endforeach
        </x-ui.data-table>

        <div class="flex flex-wrap items-center justify-between gap-3 px-1 text-sm text-secondary-medium dark:text-ink-muted">
            <span data-mine-count>{{ trans_choice('monthly_reports.month_count', count($mineRows), ['count' => count($mineRows)]) }}</span>
            @if ($hiddenCount > 0)
                <button type="button" class="dk-btn dk-ghost dk-sm" wire:click="showMoreQueue" data-mine-more>
                    {{ __('monthly_reports.show_more', ['count' => min($hiddenCount, 25)]) }}
                </button>
            @endif
        </div>
    </div>
@endif
