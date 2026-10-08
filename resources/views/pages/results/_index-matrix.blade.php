{{-- Every statement in one grid. Shown when the page's view is matrix. --}}
        {{-- One row per statement, indented by level, with every figure side by side. --}}
        <x-ui.panel :title="__('results_framework.matrix')" :count="$overview['statements']" :subtitle="__('results_framework.every_statement_with_what_measures_it_and_how_it_is_tracking')">
            <div class="overflow-x-auto">
                <div class="min-w-[64rem]">
                    <div class="grid grid-cols-[minmax(0,1fr)_7rem_5.5rem_5.5rem_12rem_6rem_6rem] items-center gap-3 border-b border-secondary-soft bg-board-head px-4 py-2 dark:border-line">
                        <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('results_framework.statement') }}</span>
                        <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('common.type') }}</span>
                        <span class="type-label text-end text-secondary-medium dark:text-ink-muted">{{ __('common.indicators') }}</span>
                        <span class="type-label text-end text-secondary-medium dark:text-ink-muted">{{ __('common.targets') }}</span>
                        <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('indicators.percent_achieved') }}</span>
                        <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('results_framework.rating') }}</span>
                        <span class="type-label text-end text-secondary-medium dark:text-ink-muted">{{ __('common.actions') }}</span>
                    </div>

                    <div class="divide-y divide-secondary-soft dark:divide-line">
                        @foreach ($this->visibleNodes as $row)
                            @php
                                $node = $row['node'];
                                $matrixResult = $node['result'];
                                $matrixMeasurement = $node['measurement'];
                                $matrixIndicators = count($matrixMeasurement['rows']);
                                $matrixRag = $matrixMeasurement['rag'];
                                $matrixRated = $matrixRag['green'] + $matrixRag['amber'] + $matrixRag['red'];
                                $matrixTone = match (true) {
                                    $matrixRag['red'] > 0 => 'critical',
                                    $matrixRag['amber'] > 0 => 'warn',
                                    $matrixRated > 0 => 'good',
                                    default => 'neutral',
                                };
                            @endphp
                            <div
                                data-tree-depth="{{ $node['depth'] }}"
                                data-result-type="{{ $matrixResult->type->value }}"
                                class="grid grid-cols-[minmax(0,1fr)_7rem_5.5rem_5.5rem_12rem_6rem_6rem] items-center gap-3 px-4 py-2.5 transition-colors hover:bg-board-head"
                                wire:key="matrix-row-{{ $matrixResult->id }}"
                            >
                                <div class="flex min-w-0 items-center gap-2" style="padding-inline-start: {{ $node['depth'] * 1.25 }}rem">
                                    <span aria-hidden="true" class="size-2 shrink-0 rounded-full ring-2 ring-inset {{ $levelMarks[$matrixResult->type->value] }}"></span>
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-secondary-dark dark:text-ink">{{ $matrixResult->statement }}</p>
                                        <p class="truncate type-meta text-secondary-medium dark:text-ink-muted">
                                            {{ $row['program'] }} · {{ __('programs.logframe_checkout', ['period' => $matrixResult->checkout_period->label()]) }}
                                            @foreach ($matrixMeasurement['funders'] as $funder)
                                                · {{ $funder['label'] }}
                                            @endforeach
                                        </p>
                                    </div>
                                </div>

                                <x-ui.enum-badge :case="$matrixResult->type" class="justify-self-start" />

                                <span @class([
                                    'text-end text-sm font-semibold tabular-nums',
                                    'text-status-warn' => $matrixIndicators === 0,
                                    'text-secondary-dark dark:text-ink' => $matrixIndicators > 0,
                                ])>{{ $matrixIndicators }}</span>

                                <span class="text-end text-sm font-semibold tabular-nums text-secondary-dark dark:text-ink">{{ $matrixMeasurement['targets'] }}</span>

                                <div class="flex items-center gap-2">
                                    {{-- Clamped: progress-bar flags anything over its max as a warning, and over-achievement is not one. --}}
                                    <x-ui.progress-bar
                                        class="min-w-16 flex-1"
                                        :value="$matrixMeasurement['achieved'] === null ? null : min($matrixMeasurement['achieved'], 100)"
                                        :max="100"
                                        :tone="$matrixTone"
                                        :label="__('indicators.percent_achieved')"
                                        label-hidden
                                    />
                                    <span class="w-11 shrink-0 text-end type-meta font-semibold tabular-nums text-secondary-dark dark:text-ink">
                                        {{ $matrixMeasurement['achieved'] === null ? '—' : number_format($matrixMeasurement['achieved'], 0).'%' }}
                                    </span>
                                </div>

                                <div class="flex items-center gap-1">
                                    @if ($matrixRated === 0)
                                        <span class="text-secondary-light">—</span>
                                    @else
                                        @foreach (['green' => 'bg-status-good', 'amber' => 'bg-status-warn', 'red' => 'bg-primary dark:bg-status-critical'] as $band => $fill)
                                            @if ($matrixRag[$band] > 0)
                                                <span class="inline-flex items-center gap-1 text-[11px] font-bold tabular-nums text-secondary-dark dark:text-ink">
                                                    <span aria-hidden="true" class="size-1.5 rounded-full {{ $fill }}"></span>{{ $matrixRag[$band] }}
                                                </span>
                                            @endif
                                        @endforeach
                                    @endif
                                </div>

                                <div class="flex items-center justify-end gap-0.5">
                                    @can('results.update')<x-ui.button
                                        tone="ghost"
                                        size="sm"
                                        icon="pencil-square"
                                        class="size-8!"
                                        wire:click="openEditModal({{ $matrixResult->id }})"
                                        :aria-label="__('common.edit_name', ['name' => $matrixResult->statement])"
                                    />@endcan
                                    @if ($node['deletable'])
                                        @can('results.delete')<x-ui.button
                                            tone="ghost"
                                            size="sm"
                                            icon="trash"
                                            class="size-8!"
                                            wire:click="confirmDelete({{ $matrixResult->id }})"
                                            :aria-label="__('common.delete_name', ['name' => $matrixResult->statement])"
                                        />@endcan
                                    @else
                                        <flux:tooltip :content="__('results_framework.locked_while_it_carries_nested_results_or_indicators')">
                                            <span class="inline-flex size-8 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
                                                <flux:icon.lock-closed class="size-4" />
                                            </span>
                                        </flux:tooltip>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-ui.panel>
