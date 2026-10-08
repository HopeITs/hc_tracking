{{--
    The chain inspector of indicators/index: one indicator's lineage, its
    reading history and every target committed against it. Included by
    ⚡index.blade.php, whose top PHP block defines $levelMarks.
--}}
@use('App\Enums\IndicatorMeasure')
    {{-- The chain: every reading behind the number, and every commitment in front of it. --}}
    <x-ui.modal
        wire:model="showInspectModal"
        size="xl"
        close="closeInspectModal"
        :cancel-label="__('common.close')"
        :title="$showInspectModal && $this->inspected !== null ? $this->inspected['indicator']->name : ''"
    >
        @if ($showInspectModal && $this->inspected !== null)
            @php
                $row = $this->inspected;
                $indicator = $row['indicator'];
                $tone = $this->ragTone($row['rag']);
                $unit = $indicator->measure === IndicatorMeasure::Percentage ? '%' : $indicator->unit;
            @endphp

            <div class="space-y-5 py-5">
                <div class="border-b border-secondary-soft pb-4 dark:border-line">
                    {{-- The whole chain above this indicator: program, then every
                         statement it rolls up through, then what it is scoped to. --}}
                    <div class="flex flex-wrap items-center gap-x-1.5 gap-y-1 type-meta text-secondary-medium dark:text-ink-muted">
                        <span>{{ $indicator->result->program?->name ?? __('common.unassigned') }}</span>

                        @foreach (array_reverse($row['lineage']) as $ancestor)
                            <flux:icon.chevron-right variant="micro" class="size-3 rtl:rotate-180" aria-hidden="true" />
                            <span class="inline-flex items-center gap-1.5" title="{{ $ancestor->statement }}">
                                <span aria-hidden="true" class="size-2 rounded-full ring-2 ring-inset {{ $levelMarks[$ancestor->type->value] }}"></span>
                                {{ Str::limit($ancestor->statement, 40) }}
                            </span>
                        @endforeach

                        <flux:icon.chevron-right variant="micro" class="size-3 rtl:rotate-180" aria-hidden="true" />
                        <span class="inline-flex items-center gap-1.5" title="{{ $indicator->result->statement }}">
                            <span aria-hidden="true" class="size-2 rounded-full ring-2 ring-inset {{ $levelMarks[$indicator->result->type->value] }}"></span>
                            {{ Str::limit($indicator->result->statement, 50) }}
                        </span>

                        <flux:icon.chevron-right variant="micro" class="size-3 rtl:rotate-180" aria-hidden="true" />
                        <span class="font-semibold text-secondary-dark dark:text-ink">{{ $indicator->project?->name ?? __('common.organisation_wide') }}</span>
                    </div>


                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <x-ui.enum-badge :case="$row['rag']" />
                        <x-ui.enum-badge :case="$indicator->frequency" />
                        <x-ui.enum-badge :case="$indicator->measure" />
                        <span class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted">
                            {{ __('indicators.baseline') }} {{ $this->figure($row['baseline']) }}
                            <flux:icon.arrow-long-right variant="micro" class="inline size-3 rtl:rotate-180" aria-hidden="true" />
                            {{ __('indicators.target') }} {{ $this->figure($row['targetValue']) }}{{ $unit !== null ? ' '.$unit : '' }}
                        </span>
                        <span class="ms-auto text-xl font-bold tabular-nums {{ $this->toneText($tone) }}">
                            {{ $row['achieved'] === null ? '—' : number_format($row['achieved'], 1).'%' }}
                        </span>
                    </div>
                </div>

                <div class="grid gap-5 lg:grid-cols-2">
                    <section aria-labelledby="inspect-readings-heading">
                        <h3 id="inspect-readings-heading" class="type-label text-secondary-medium dark:text-ink-muted">
                            {{ __('indicators.reading_history') }}
                            <span class="ms-1 tabular-nums">{{ $row['readings'] }}</span>
                        </h3>

                        @if ($this->inspectedReadings->isEmpty())
                            <p class="mt-3 rounded-lg border border-dashed border-secondary-light p-4 text-center type-meta text-secondary-medium dark:text-ink-muted">
                                {{ __('indicators.no_readings_logged_yet') }}
                            </p>
                        @else
                            <ol class="mt-3 max-h-80 space-y-0 overflow-y-auto border-s border-secondary-soft ps-4 dark:border-line">
                                @foreach ($this->inspectedReadings as $entry)
                                    <li class="relative pb-4 last:pb-0" wire:key="reading-{{ $entry['reading']->id }}">
                                        <span aria-hidden="true" class="absolute -start-[1.3125rem] top-1.5 size-2 rounded-full {{ $loop->first ? $this->toneFill($tone) : 'bg-secondary-light' }}"></span>

                                        <div class="flex items-baseline justify-between gap-2">
                                            <p class="text-sm font-semibold tabular-nums">
                                                {{ $this->figure($entry['value']) }}{{ $unit !== null ? ' '.$unit : '' }}
                                                @if ($entry['delta'] !== null && $entry['delta'] !== 0.0)
                                                    <span @class([
                                                        'ms-1 type-meta font-semibold',
                                                        'text-status-good' => $entry['delta'] > 0,
                                                        'text-secondary-medium dark:text-ink-muted' => $entry['delta'] < 0,
                                                    ])>{{ $entry['delta'] > 0 ? '+' : '−' }}{{ $this->figure(abs($entry['delta'])) }}</span>
                                                @endif
                                            </p>
                                            <p class="shrink-0 type-meta text-secondary-medium dark:text-ink-muted">
                                                {{ $entry['reading']->date->translatedFormat(__('formats.date')) }}
                                            </p>
                                        </div>

                                        @if ($entry['reading']->source_of_verification !== null)
                                            <p class="mt-0.5 type-meta text-secondary-medium dark:text-ink-muted">
                                                <flux:icon.document-check variant="micro" class="inline size-3" aria-hidden="true" />
                                                {{ $entry['reading']->source_of_verification }}
                                            </p>
                                        @endif

                                        @if ($entry['reading']->notes !== null)
                                            <p class="mt-0.5 type-meta text-secondary-medium dark:text-ink-muted">{{ $entry['reading']->notes }}</p>
                                        @endif

                                        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                                            @if ($entry['reading']->recorder !== null)
                                                <p class="me-auto type-meta text-secondary-light">
                                                    {{ __('indicators.recorded_by_name', ['name' => $entry['reading']->recorder->name]) }}
                                                </p>
                                            @endif

                                            @if ($deletingReadingId === $entry['reading']->id)
                                                <span class="ms-auto inline-flex items-center gap-1.5 type-meta">
                                                    <span class="font-semibold text-primary dark:text-brand-text">{{ __('indicators.delete_this_reading') }}</span>
                                                    <x-ui.button size="sm" tone="primary" class="min-h-7! px-2!" wire:click="deleteReading" data-test="confirm-delete-reading-{{ $entry['reading']->id }}">{{ __('common.delete') }}</x-ui.button>
                                                    <x-ui.button size="sm" class="min-h-7! px-2!" wire:click="cancelDeleteReading">{{ __('common.cancel') }}</x-ui.button>
                                                </span>
                                            @else
                                                <span class="ms-auto inline-flex items-center gap-0.5">
                                                    @can('indicators.update')<x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-7!" wire:click="openEditReadingModal({{ $entry['reading']->id }})" :aria-label="__('indicators.edit_reading_from_date', ['date' => $entry['reading']->date->translatedFormat(__('formats.date'))])" data-test="edit-reading-{{ $entry['reading']->id }}" />@endcan
                                                    @can('indicators.update')<x-ui.button tone="ghost" size="sm" icon="trash" class="size-7!" wire:click="confirmDeleteReading({{ $entry['reading']->id }})" :aria-label="__('indicators.delete_reading_from_date', ['date' => $entry['reading']->date->translatedFormat(__('formats.date'))])" data-test="delete-reading-{{ $entry['reading']->id }}" />@endcan
                                                </span>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </section>

                    <section aria-labelledby="inspect-targets-heading">
                        <div class="flex items-center justify-between gap-2">
                            <h3 id="inspect-targets-heading" class="type-label text-secondary-medium dark:text-ink-muted">
                                {{ __('indicators.committed_targets') }}
                                <span class="ms-1 tabular-nums">{{ count($row['targets']) }}</span>
                            </h3>
                            @if ($row['targets'] !== [])
                                <a href="{{ route('targets.index', ['indicator' => $indicator->id]) }}" wire:navigate class="type-meta font-semibold text-secondary-medium hover:text-primary dark:hover:text-brand-text hover:underline dark:text-ink-muted">
                                    {{ __('indicators.open_in_targets') }}
                                </a>
                            @endif
                        </div>

                        @if ($row['targets'] === [])
                            <div class="mt-3 flex flex-col items-center gap-3 rounded-lg border border-dashed border-secondary-light p-4 text-center">
                                <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('indicators.no_targets_committed_against_this_indicator_yet') }}</p>
                                <x-ui.button size="sm" tone="primary" icon="flag" :href="route('targets.index', ['indicator' => $indicator->id, 'create' => 1])" wire:navigate data-test="commit-target-for-indicator">
                                    {{ __('indicators.commit_a_target') }}
                                </x-ui.button>
                            </div>
                        @else
                            <ul class="mt-3 max-h-96 space-y-2 overflow-y-auto">
                                @foreach ($row['targets'] as $target)
                                    <li class="rounded-lg border border-secondary-soft bg-board-head p-3 dark:border-line" wire:key="target-{{ $target['target']->id }}">
                                        <div class="flex items-baseline justify-between gap-2">
                                            <p class="min-w-0 truncate text-sm font-semibold">
                                                {{ $target['sourceLabel'] ?? __('indicators.source_no_longer_available') }}
                                            </p>
                                            <p class="shrink-0 text-sm font-bold tabular-nums">{{ $this->figure((float) $target['target']->target) }}{{ $unit !== null ? ' '.$unit : '' }}</p>
                                        </div>

                                        <p class="mt-0.5 type-meta text-secondary-medium dark:text-ink-muted">
                                            {{ $target['target']->period->label() }}
                                            · {{ $target['project'] ?? __('common.organisation_wide') }}
                                        </p>

                                        @if ($target['donors'] !== [])
                                            <div class="mt-2 flex flex-wrap gap-1">
                                                @foreach ($target['donors'] as $donor)
                                                    <span class="inline-flex items-center gap-1 rounded-full border border-funding/30 bg-funding-soft px-2 py-0.5 text-[11px] font-semibold text-funding">
                                                        <flux:icon.building-library variant="micro" class="size-3" aria-hidden="true" />
                                                        {{ $donor }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif

                                        @foreach ($target['issues'] as $issue)
                                            <p class="mt-2 flex items-start gap-1.5 type-meta font-semibold text-primary dark:text-brand-text">
                                                <flux:icon.link-slash variant="micro" class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                                                {{ __($issue) }}
                                            </p>
                                        @endforeach

                                        {{-- Approved reports only (PlanIndicator::achievedValue()); the rest is shown as pending. --}}
                                        <div class="mt-2.5 border-t border-secondary-soft pt-2.5 dark:border-line">
                                            <div class="flex items-baseline justify-between gap-2">
                                                <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('indicators.achieved') }}</p>
                                                <p class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted">
                                                    <span class="font-semibold text-secondary-dark dark:text-ink">{{ $this->figure($target['achieved']) }}</span> / {{ $this->figure($target['committed']) }}
                                                    @if ($target['percent'] !== null)
                                                        · {{ number_format($target['percent'], 0) }}%
                                                    @endif
                                                </p>
                                            </div>

                                            <x-ui.progress-bar
                                                class="mt-1.5"
                                                :value="min($target['achieved'], $target['committed'])"
                                                :max="max($target['committed'], 0.01)"
                                                tone="funding"
                                                :label="__('indicators.achieved')"
                                                label-hidden
                                            />

                                            <p class="mt-1 type-meta tabular-nums text-secondary-medium dark:text-ink-muted">
                                                {{ __('indicators.count_of_total_reports_approved', ['count' => $target['approved'], 'total' => $target['reports']]) }}
                                                @if ($target['pending'] > 0)
                                                    · {{ __('indicators.value_pending_approval', ['value' => $this->figure($target['pending'])]) }}
                                                @endif
                                            </p>

                                            @if ($target['reportRows']->isEmpty())
                                                <p class="mt-2 type-meta text-secondary-light">{{ __('indicators.no_reports_filed_yet') }}</p>
                                            @else
                                                <ul class="mt-2 space-y-0.5">
                                                    @foreach ($target['reportRows'] as $report)
                                                        <li wire:key="report-{{ $report->id }}">
                                                            <a href="{{ route('reports.show', $report) }}" wire:navigate class="-mx-1 flex items-center gap-2 rounded px-1 py-0.5 type-meta transition-colors hover:bg-white dark:hover:bg-white/5">
                                                                <span class="inline-flex shrink-0 items-center rounded border px-1.5 py-px text-[10px] font-semibold {{ $this->reportStatusChip($report->status) }}">
                                                                    {{ $report->status->label() }}
                                                                </span>
                                                                <span class="tabular-nums font-semibold text-secondary-dark dark:text-ink">{{ $this->figure((float) $report->value) }}</span>
                                                                <span class="min-w-0 truncate text-secondary-medium dark:text-ink-muted">
                                                                    @if ($report->period_start !== null && $report->period_end !== null)
                                                                        {{ $report->period_start->translatedFormat(__('formats.date_short')) }}–{{ $report->period_end->translatedFormat(__('formats.date_short')) }}
                                                                    @else
                                                                        {{ $report->planned_date->translatedFormat(__('formats.date')) }}
                                                                    @endif
                                                                </span>
                                                                @if ($report->isOverdue())
                                                                    <span class="ms-auto shrink-0 font-semibold text-primary dark:text-brand-text">
                                                                        {{ __('ui.schedule_due_date', ['date' => $report->planned_date->translatedFormat(__('formats.date_short'))]) }}
                                                                    </span>
                                                                @endif
                                                            </a>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif

                                            <a href="{{ route('reports.index', ['create' => 1, 'target' => $target['target']->id]) }}" wire:navigate class="mt-2 inline-flex items-center gap-1 type-meta font-semibold text-primary dark:text-brand-text hover:underline" data-test="record-report-for-target-{{ $target['target']->id }}">
                                                <flux:icon.plus variant="micro" class="size-3.5" aria-hidden="true" />
                                                {{ __('indicators.record_a_report') }}
                                            </a>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                </div>
            </div>

            <x-slot:actions>
                @if ($row['targets'] !== [])
                    <x-ui.button icon="flag" :href="route('targets.index', ['indicator' => $indicator->id, 'create' => 1])" wire:navigate>
                        {{ __('indicators.commit_a_target') }}
                    </x-ui.button>
                @endif
                @can('indicators.update')<x-ui.button type="button" tone="primary" icon="plus-circle" wire:click="openProgressModal({{ $indicator->id }})">
                    {{ __('indicators.log_reading') }}
                </x-ui.button>@endcan
            </x-slot:actions>
        @endif
    </x-ui.modal>
