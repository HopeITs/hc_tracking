{{--
    The table view of indicators/index: every figure side by side, then the
    pager. Included by ⚡index.blade.php, whose top PHP block defines $paged,
    $overview and $levelMarks.
--}}
@use('App\Enums\IndicatorMeasure')
        {{-- Every figure side by side, for comparing many indicators at once. --}}
        <x-ui.panel
            :title="__('indicators.table')"
            :count="$overview['indicators']"
            :subtitle="__('indicators.every_indicator_with_its_trend_and_the_money_behind_it')"
        >
            <div class="overflow-x-auto">
                <div class="min-w-[68rem]">
                    <div class="grid grid-cols-[minmax(0,1fr)_8rem_7rem_7rem_9rem_5.5rem_5rem_9rem] items-center gap-3 border-b border-secondary-soft bg-board-head px-4 py-2 dark:border-line">
                        <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('common.indicator_name') }}</span>
                        <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('indicators.baseline_to_target') }}</span>
                        <span class="type-label text-end text-secondary-medium dark:text-ink-muted">{{ __('indicators.latest') }}</span>
                        <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('indicators.trend') }}</span>
                        <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('indicators.percent_achieved') }}</span>
                        <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('indicators.rating') }}</span>
                        <span class="type-label text-end text-secondary-medium dark:text-ink-muted">{{ __('common.targets') }}</span>
                        <span class="type-label text-end text-secondary-medium dark:text-ink-muted">{{ __('common.actions') }}</span>
                    </div>

                    <div class="divide-y divide-secondary-soft dark:divide-line">
                        @foreach ($paged as $row)
                            @php
                                $indicator = $row['indicator'];
                                $tone = $this->ragTone($row['rag']);
                                $sparkline = $this->spark($row['series'], $row['baseline'], $row['targetValue']);
                                $achievedPct = $row['achieved'] === null ? null : max(0.0, min(100.0, $row['achieved']));
                                $unit = $indicator->measure === IndicatorMeasure::Percentage ? '%' : $indicator->unit;
                            @endphp
                            <div class="grid grid-cols-[minmax(0,1fr)_8rem_7rem_7rem_9rem_5.5rem_5rem_9rem] items-center gap-3 px-4 py-3" wire:key="indicator-row-{{ $indicator->id }}">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span aria-hidden="true" class="size-2 shrink-0 rounded-full ring-2 ring-inset {{ $levelMarks[$indicator->result->type->value] }}"></span>
                                        <p class="truncate text-sm font-semibold">{{ $indicator->name }}</p>
                                    </div>
                                    <p class="mt-0.5 truncate type-meta text-secondary-medium dark:text-ink-muted">
                                        {{ Str::limit($indicator->result->statement, 60) }}
                                        · {{ $indicator->result->program?->name ?? __('common.unassigned') }}
                                    </p>
                                </div>

                                <p class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted">
                                    {{ $this->figure($row['baseline']) }} <flux:icon.arrow-long-right variant="micro" class="inline size-3 rtl:rotate-180" aria-hidden="true" /> {{ $this->figure($row['targetValue']) }}
                                    @if ($unit !== null)
                                        <span class="text-secondary-light">{{ $unit }}</span>
                                    @endif
                                </p>

                                <p class="text-end text-sm font-semibold tabular-nums">{{ $this->figure($row['latest']) }}</p>

                                <div class="flex items-center">
                                    @if ($sparkline !== null)
                                        <div class="relative w-full {{ $this->toneText($tone) }}">
                                            <svg viewBox="0 0 100 30" preserveAspectRatio="none" class="h-6 w-full overflow-visible" aria-hidden="true">
                                                @if ($sparkline['targetY'] !== null)
                                                    <line x1="0" y1="{{ $sparkline['targetY'] }}" x2="100" y2="{{ $sparkline['targetY'] }}" stroke="currentColor" stroke-width="1" stroke-dasharray="3 3" opacity="0.35" vector-effect="non-scaling-stroke" />
                                                @endif
                                                <polyline points="{{ $sparkline['line'] }}" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                                            </svg>

                                            <span
                                                aria-hidden="true"
                                                class="pointer-events-none absolute size-1.5 rounded-full bg-current"
                                                style="inset-inline-start: calc({{ $sparkline['lastPct'][0] }}% - 0.1875rem); top: calc({{ $sparkline['lastPct'][1] }}% - 0.1875rem)"
                                            ></span>
                                        </div>
                                    @else
                                        <span class="type-meta text-secondary-light">{{ __('indicators.not_enough_readings') }}</span>
                                    @endif
                                </div>

                                <div class="min-w-0">
                                    <p class="text-sm font-bold tabular-nums {{ $this->toneText($tone) }}">
                                        {{ $row['achieved'] === null ? '—' : number_format($row['achieved'], 1).'%' }}
                                    </p>
                                    <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2">
                                        @if ($achievedPct !== null)
                                            <div class="h-full rounded-full {{ $this->toneFill($tone) }}" style="width: {{ $achievedPct }}%"></div>
                                        @endif
                                    </div>
                                </div>

                                <x-ui.enum-badge :case="$row['rag']" />

                                <p class="text-end text-sm tabular-nums text-secondary-medium dark:text-ink-muted">
                                    @if ($row['targets'] === [])
                                        0
                                    @else
                                        <a href="{{ route('targets.index', ['indicator' => $indicator->id]) }}" wire:navigate class="hover:text-primary dark:hover:text-brand-text hover:underline" title="{{ __('indicators.count_of_total_targets_achieved', ['count' => $row['targetsAchieved'], 'total' => count($row['targets'])]) }}">
                                            {{ $row['targetsAchieved'] }}/{{ count($row['targets']) }}
                                        </a>
                                    @endif
                                </p>

                                <div class="flex items-center justify-end gap-0.5">
                                    <x-ui.button tone="ghost" size="sm" icon="arrows-pointing-out" class="size-9!" wire:click="openInspectModal({{ $indicator->id }})" :aria-label="__('indicators.view_the_chain_for_name', ['name' => $indicator->name])" />
                                    @can('indicators.update')<x-ui.button tone="ghost" size="sm" icon="plus-circle" class="size-9!" wire:click="openProgressModal({{ $indicator->id }})" :aria-label="__('indicators.log_a_reading_for_name', ['name' => $indicator->name])" data-test="log-reading-button-{{ $indicator->id }}" />@endcan
                                    @can('indicators.update')<x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $indicator->id }})" :aria-label="__('common.edit_name', ['name' => $indicator->name])" />@endcan
                                    @if ($row['targets'] === [])
                                        @can('indicators.delete')<x-ui.button tone="ghost" size="sm" icon="trash" class="size-9!" wire:click="confirmDelete({{ $indicator->id }})" :aria-label="__('common.delete_name', ['name' => $indicator->name])" />@endcan
                                    @else
                                        <flux:tooltip :content="__('indicators.indicators_carrying_targets_cannot_be_deleted')">
                                            <span class="inline-flex size-9 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
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

            <x-slot:footer>
                <flux:pagination :paginator="$paged" class="text-secondary-medium! dark:text-ink-muted!" scroll-to="#indicator-directory-heading" />
            </x-slot:footer>
        </x-ui.panel>
