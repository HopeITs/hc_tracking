{{-- The report-history panel on ⚡show.blade.php: the trend chart and the list of reports against the same target. Shown when the target has more than one report. --}}
        <x-ui.panel :title="__('reports.report_history')" :count="$this->history->count()" :subtitle="__('reports.every_report_against_this_same_target_newest_first')">
            @php $trendRows = $this->history->sortBy('planned_date')->values(); @endphp
            <div class="px-4 pt-4 pb-2">
                <x-ui.chart
                    type="area"
                    :label="__('reports.report_history')"
                    :series="[['name' => __('reports.reported_value'), 'data' => $trendRows->map(fn ($row) => (float) $row->value)->all()]]"
                    :categories="$trendRows->map(fn ($row) => $row->planned_date?->translatedFormat(__('formats.date_short')) ?? '—')->all()"
                    :tones="['critical']"
                    :height="160"
                />
            </div>

            <ul class="divide-y divide-secondary-soft dark:divide-line">
                @foreach ($this->history as $row)
                    @php $isCurrent = $row->id === $report->id; @endphp
                    <li wire:key="history-{{ $row->id }}">
                        @if ($isCurrent)
                            <div class="flex items-center gap-3 bg-status-critical-soft p-3.5 dark:bg-primary/10">
                                <span class="rounded-full bg-primary dark:bg-brand px-2 py-0.5 text-[11px] font-bold text-white">{{ __('reports.this_report') }}</span>
                                <span class="tabular-nums text-sm text-secondary-dark dark:text-ink">{{ $row->planned_date?->translatedFormat(__('formats.date')) }}</span>
                                <span class="ms-auto text-sm font-semibold tabular-nums">{{ number_format((float) $row->value, 2) }}</span>
                                <x-ui.enum-badge :case="$row->status" />
                            </div>
                        @else
                            <a href="{{ route('reports.show', $row) }}" wire:navigate class="flex items-center gap-3 p-3.5 transition-colors hover:bg-board-head">
                                <span class="tabular-nums text-sm text-secondary-medium dark:text-ink-muted">{{ $row->planned_date?->translatedFormat(__('formats.date')) }}</span>
                                <span class="ms-auto text-sm font-semibold tabular-nums">{{ number_format((float) $row->value, 2) }}</span>
                                <x-ui.enum-badge :case="$row->status" />
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.panel>
