{{--
    The one list of indicators/index: a row per indicator with its name, one
    status word, how far along it is, and the single action most people come
    for (log a measurement). Everything else lives behind "Details".
    Included by ⚡index.blade.php, whose top PHP block defines $paged.
--}}
@use('App\Enums\IndicatorMeasure')
<x-ui.panel>
    <ul class="divide-y divide-secondary-soft dark:divide-line" data-test="indicator-list">
        @foreach ($paged as $row)
            @php
                $indicator = $row['indicator'];
                $status = $row['status'];
                $tone = match ($status) {
                    'ontrack' => 'good',
                    'watch', 'stale' => 'warn',
                    'late' => 'critical',
                    default => 'neutral',
                };
                $unit = $indicator->measure === IndicatorMeasure::Percentage ? '%' : $indicator->unit;
                $unitSuffix = match (true) {
                    $unit === null || $unit === '' => '',
                    $unit === '%' => '%',
                    default => ' '.$unit,
                };
                $span = $row['targetValue'] === null ? null : $row['targetValue'] - $row['baseline'];
                $progress = $span === null || $span === 0.0 || $row['latest'] === null
                    ? 0.0
                    : max(0.0, min(100.0, (($row['latest'] - $row['baseline']) / $span) * 100));
            @endphp

            <li class="grid gap-3 px-4 py-4 md:grid-cols-[minmax(0,1fr)_15rem_auto] md:items-center" wire:key="indicator-row-{{ $indicator->id }}" data-status="{{ $status }}">
                <div class="min-w-0">
                    <p class="text-base font-bold leading-snug text-secondary-dark dark:text-ink"><bdi>{{ $indicator->name }}</bdi></p>
                    <p class="mt-0.5 type-meta text-secondary-medium dark:text-ink-muted">
                        {{ $indicator->result->program?->name ?? __('common.unassigned') }}@if ($indicator->project !== null) · {{ $indicator->project->name }}@endif
                    </p>
                    @if ($row['chainIssues'] > 0)
                        <p class="mt-1 type-meta font-semibold text-status-warn">{{ trans_choice('indicators.count_target_out_of_step', $row['chainIssues'], ['count' => $row['chainIssues']]) }}</p>
                    @endif
                </div>

                <div class="min-w-0">
                    <div class="flex items-center justify-between gap-2">
                        <span class="inline-flex items-center gap-1.5 text-sm font-semibold {{ $this->toneText($tone) }}">
                            <span aria-hidden="true" class="size-2 shrink-0 rounded-full {{ $this->toneFill($tone) }}"></span>
                            {{ __('indicators.status_'.$status) }}
                        </span>
                        @if ($row['latest'] !== null)
                            <span class="type-meta tabular-nums text-secondary-dark dark:text-ink" dir="ltr">
                                @if ($row['targetValue'] !== null)
                                    {{ __('indicators.of_target', ['latest' => $this->figure($row['latest']).$unitSuffix, 'target' => $this->figure($row['targetValue']).$unitSuffix]) }}
                                @else
                                    {{ $this->figure($row['latest']).$unitSuffix }}
                                @endif
                            </span>
                        @endif
                    </div>

                    <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2" role="presentation">
                        <div class="h-full rounded-full {{ $this->toneFill($tone) }}" style="width: {{ $progress }}%"></div>
                    </div>

                    <p class="mt-1 type-meta text-secondary-medium dark:text-ink-muted">
                        @if ($row['latestDate'] === null)
                            {{ __('indicators.never_measured') }}
                        @else
                            {{ __('indicators.last_read_date', ['date' => $row['latestDate']->translatedFormat(__('formats.date'))]) }}
                        @endif
                    </p>
                </div>

                <div class="flex items-center gap-1 md:justify-end">
                    @can('indicators.record')
                        <x-ui.button size="sm" icon="plus" class="min-h-11 md:min-h-9" wire:click="openProgressModal({{ $indicator->id }})" data-test="log-reading-button-{{ $indicator->id }}">
                            {{ __('indicators.log_reading') }}
                        </x-ui.button>
                    @endcan

                    <x-ui.button tone="ghost" size="sm" icon="arrows-pointing-out" class="min-h-11 md:min-h-9" wire:click="openInspectModal({{ $indicator->id }})" data-test="inspect-indicator-{{ $indicator->id }}" :aria-label="__('indicators.details').' · '.$indicator->name">
                        {{ __('indicators.details') }}
                    </x-ui.button>

                    @if (auth()->user()->can('indicators.update') || auth()->user()->can('indicators.delete'))
                        <flux:dropdown align="end">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" class="min-h-11 md:min-h-9" :aria-label="__('indicators.more_actions_for_name', ['name' => $indicator->name])" />
                            <flux:menu>
                                @can('indicators.update')
                                    <flux:menu.item icon="pencil-square" wire:click="openEditModal({{ $indicator->id }})">{{ __('indicators.edit_indicator') }}</flux:menu.item>
                                @endcan
                                @can('indicators.delete')
                                    @if ($row['targets'] === [])
                                        <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $indicator->id }})">{{ __('common.delete') }}</flux:menu.item>
                                    @else
                                        <flux:menu.item icon="lock-closed" disabled>{{ __('indicators.indicators_carrying_targets_cannot_be_deleted') }}</flux:menu.item>
                                    @endif
                                @endcan
                            </flux:menu>
                        </flux:dropdown>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
</x-ui.panel>

@if ($paged->hasPages())
    <x-ui.panel>
        <div class="px-4 py-2">
            <flux:pagination :paginator="$paged" class="text-secondary-medium! dark:text-ink-muted!" scroll-to="#indicator-directory-heading" />
        </div>
    </x-ui.panel>
@endif
