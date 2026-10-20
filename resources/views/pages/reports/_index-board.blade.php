{{--
    The reports register's Board view: a column per status, a card per report
    (indicator, project, planned date) that opens the report. Included by
    ⚡index.blade.php.
--}}
<x-desk.board data-test="report-board">
    @foreach ($this->boardColumns as $column)
        <x-desk.board-column :label="$column['label']" :count="$column['count']" :key="$column['key']" wire:key="report-column-{{ $column['key'] }}">
            @foreach ($column['items'] as $boardReport)
                <x-desk.board-card :href="route('reports.show', $boardReport)" wire:key="report-card-{{ $boardReport->id }}" data-test="report-board-card-{{ $boardReport->id }}">
                    <strong>{{ $boardReport->planIndicator->indicator->name }}</strong>
                    <span class="dk-is">{{ $boardReport->planIndicator->project?->name ?? __('common.organisation_wide') }} · {{ $boardReport->planned_date->translatedFormat(__('formats.date')) }}</span>
                </x-desk.board-card>
            @endforeach
        </x-desk.board-column>
    @endforeach
</x-desk.board>
