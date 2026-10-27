{{--
    One indicator card of indicators/index: its place in the chain, the
    trajectory rail, the reading sparkline, targets, badges and actions.
    Included by _index-cards.blade.php once per row, with ['row' => $row];
    it also reads $levelMarks, $levelAccents and $overview from the page's
    top PHP block.
--}}
@use('App\Enums\IndicatorMeasure')
                @php
                    $indicator = $row['indicator'];
                    $tone = $this->ragTone($row['rag']);
                    $sparkline = $this->spark($row['series'], $row['baseline'], $row['targetValue']);
                    $unit = $indicator->measure === IndicatorMeasure::Percentage ? '%' : $indicator->unit;
                    $span = $row['targetValue'] === null ? null : $row['targetValue'] - $row['baseline'];
                    $latestPct = $span === null || $span === 0.0 || $row['latest'] === null
                        ? null
                        : max(0.0, min(100.0, (($row['latest'] - $row['baseline']) / $span) * 100));
                @endphp

                <article
                    class="group relative flex flex-col overflow-hidden rounded-xl border border-secondary-soft bg-white shadow-card transition-all hover:-translate-y-0.5 hover:border-secondary-light hover:shadow-card-hover dark:border-transparent dark:bg-surface-1 dark:shadow-raised dark:hover:shadow-raised"
                    wire:key="indicator-card-{{ $indicator->id }}"
                    data-result-type="{{ $indicator->result->type->value }}"
                >
                    <span aria-hidden="true" class="h-1 w-full {{ $levelAccents[$indicator->result->type->value] }}"></span>

                    <div class="flex min-w-0 flex-col gap-3 p-4">
                        {{-- Where this indicator sits in the chain. --}}
                        <div class="flex min-w-0 items-start gap-2">
                            <span aria-hidden="true" class="mt-1 size-2.5 shrink-0 rounded-full ring-2 ring-inset {{ $levelMarks[$indicator->result->type->value] }}"></span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate type-meta text-secondary-medium dark:text-ink-muted" title="{{ $indicator->result->statement }}">
                                    {{ Str::limit($indicator->result->statement, 64) }}
                                </p>
                                <p class="mt-0.5 truncate type-meta text-secondary-light">
                                    {{ $indicator->result->program?->name ?? __('common.unassigned') }}
                                    · {{ $indicator->project?->name ?? __('common.organisation_wide') }}
                                </p>
                            </div>
                            <x-ui.enum-badge :case="$row['rag']" class="shrink-0" />
                        </div>

                        <h3 class="text-base font-bold leading-snug text-secondary-dark dark:text-ink">{{ $indicator->name }}</h3>

                        {{-- The rail: baseline, where we are, where the calendar says we should be, target. --}}
                        <div>
                            <div class="flex items-baseline justify-between gap-2">
                                <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('indicators.trajectory') }}</p>
                                <p class="text-lg font-bold tabular-nums leading-none {{ $this->toneText($tone) }}">
                                    {{ $row['achieved'] === null ? '—' : number_format($row['achieved'], 1).'%' }}
                                </p>
                            </div>

                            <div class="relative mt-2 h-2.5 w-full overflow-visible rounded-full bg-secondary-soft dark:bg-surface-2">
                                @if ($latestPct !== null)
                                    <div class="h-full rounded-full transition-[width] duration-500 motion-reduce:transition-none {{ $this->toneFill($tone) }}" style="width: {{ $latestPct }}%"></div>
                                @endif

                                @if ($row['targetValue'] !== null)
                                    <span
                                        class="absolute -top-1 bottom-[-0.25rem] w-0.5 rounded-full bg-secondary-dark/50 dark:bg-white/50"
                                        style="inset-inline-start: {{ $overview['elapsed'] }}%"
                                        aria-hidden="true"
                                        title="{{ __('indicators.expected_by_now') }}"
                                    ></span>
                                @endif
                            </div>

                            <div class="mt-1.5 flex items-baseline justify-between gap-2 type-meta tabular-nums text-secondary-medium dark:text-ink-muted">
                                <span>{{ __('indicators.baseline') }} {{ $this->figure($row['baseline']) }}</span>
                                <span class="font-semibold text-secondary-dark dark:text-ink">
                                    {{ $this->figure($row['latest']) }}{{ $unit !== null ? ' '.$unit : '' }}
                                    @if ($row['delta'] !== null && $row['delta'] !== 0.0)
                                        <span @class([
                                            'ms-0.5 font-semibold',
                                            'text-status-good' => $row['delta'] > 0,
                                            'text-secondary-medium dark:text-ink-muted' => $row['delta'] < 0,
                                        ])>{{ $row['delta'] > 0 ? '+' : '−' }}{{ $this->figure(abs($row['delta'])) }}</span>
                                    @endif
                                </span>
                                <span>{{ __('indicators.target') }} {{ $this->figure($row['targetValue']) }}</span>
                            </div>
                        </div>

                        {{-- The readings themselves, against the target they chase. --}}
                        <div class="rounded-lg border border-secondary-soft bg-board-head p-2.5 dark:border-line ">
                            @if ($sparkline !== null)
                                <div class="relative {{ $this->toneText($tone) }}">
                                    <svg viewBox="0 0 100 30" preserveAspectRatio="none" class="h-12 w-full overflow-visible" aria-hidden="true">
                                        <path d="{{ $sparkline['area'] }}" fill="currentColor" opacity="0.10" />
                                        @if ($sparkline['targetY'] !== null)
                                            <line x1="0" y1="{{ $sparkline['targetY'] }}" x2="100" y2="{{ $sparkline['targetY'] }}" stroke="currentColor" stroke-width="1" stroke-dasharray="3 3" opacity="0.4" vector-effect="non-scaling-stroke" />
                                        @endif
                                        <polyline points="{{ $sparkline['line'] }}" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                                    </svg>

                                    {{-- The latest reading. Laid over the chart so it stays a circle at every width. --}}
                                    <span
                                        aria-hidden="true"
                                        class="pointer-events-none absolute size-2 rounded-full bg-current"
                                        style="inset-inline-start: calc({{ $sparkline['lastPct'][0] }}% - 0.25rem); top: calc({{ $sparkline['lastPct'][1] }}% - 0.25rem)"
                                    ></span>
                                </div>
                            @else
                                <div class="flex h-12 items-center justify-center">
                                    <p class="type-meta text-secondary-light">
                                        {{ $row['readings'] === 0 ? __('indicators.no_readings_logged_yet') : __('indicators.not_enough_readings') }}
                                    </p>
                                </div>
                            @endif

                            <p class="mt-1.5 truncate type-meta text-secondary-medium dark:text-ink-muted">
                                @if ($row['latestDate'] === null)
                                    {{ __('indicators.never_measured') }}
                                @else
                                    {{ __('indicators.last_read_date', ['date' => $row['latestDate']->translatedFormat(__('formats.date'))]) }}
                                    @if ($row['evidence'] !== null)
                                        · {{ Str::limit($row['evidence'], 28) }}
                                    @endif
                                @endif
                            </p>
                        </div>

                        {{-- What was committed to funders, and what has been reported back. --}}
                        {{-- Counted per target: two targets on one indicator can sit in different plans and years. --}}
                        @if ($row['targets'] !== [])
                            <a href="{{ route('targets.index', ['indicator' => $indicator->id]) }}" wire:navigate class="group/targets block rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium">
                                <div class="flex items-baseline justify-between gap-2">
                                    <p class="type-label text-secondary-medium group-hover/targets:text-primary dark:group-hover/targets:text-brand-text dark:text-ink-muted">{{ __('indicators.targets_achieved') }}</p>
                                    <p class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted">
                                        {{ $row['targetsAchieved'] }} / {{ count($row['targets']) }}
                                    </p>
                                </div>
                                <x-ui.progress-bar
                                    class="mt-1.5"
                                    :value="$row['targetsAchieved']"
                                    :max="count($row['targets'])"
                                    tone="funding"
                                    :label="__('indicators.targets_achieved')"
                                    label-hidden
                                />
                            </a>
                        @endif

                        {{-- Cadence, commitments and the money behind them. --}}
                        <div class="flex flex-wrap items-center gap-1.5">
                            <x-ui.enum-badge :case="$indicator->frequency" />
                            <x-ui.enum-badge :case="$indicator->measure" />

                            @if ($row['stale'])
                                <span class="inline-flex items-center gap-1 rounded-md border border-status-warn/30 bg-status-warn-soft px-2 py-0.5 text-xs font-semibold text-status-warn">
                                    <flux:icon.clock variant="micro" class="size-3" aria-hidden="true" />
                                    {{ __('indicators.needs_a_reading') }}
                                </span>
                            @endif

                            @if ($row['overdueReports'] > 0)
                                <span class="inline-flex items-center gap-1 rounded-md border border-primary bg-status-critical-soft px-2 py-0.5 text-xs font-semibold text-primary dark:text-brand-text">
                                    <flux:icon.document-text variant="micro" class="size-3" aria-hidden="true" />
                                    {{ trans_choice('indicators.count_report_overdue', $row['overdueReports'], ['count' => $row['overdueReports']]) }}
                                </span>
                            @endif

                            @if ($row['chainIssues'] > 0)
                                <span class="inline-flex items-center gap-1 rounded-md border border-primary bg-status-critical-soft px-2 py-0.5 text-xs font-semibold text-primary dark:text-brand-text">
                                    <flux:icon.link-slash variant="micro" class="size-3" aria-hidden="true" />
                                    {{ trans_choice('indicators.count_target_out_of_step', $row['chainIssues'], ['count' => $row['chainIssues']]) }}
                                </span>
                            @endif

                            @foreach (array_slice($row['funders'], 0, 2) as $funder)
                                <span @class([
                                    'inline-flex max-w-full items-center gap-1 truncate rounded-full border px-2 py-0.5 text-[11px] font-semibold',
                                    'border-funding/30 bg-funding-soft text-funding' => $funder['type'] === 'donor',
                                    'border-secondary-light text-secondary-medium dark:text-ink-muted' => $funder['type'] !== 'donor',
                                ])>
                                    <flux:icon :name="$funder['type'] === 'donor' ? 'building-library' : 'calendar'" variant="micro" class="size-3 shrink-0" aria-hidden="true" />
                                    <span class="truncate">{{ $funder['label'] }}</span>
                                </span>
                            @endforeach

                            @if (count($row['funders']) > 2)
                                <span class="type-meta text-secondary-medium dark:text-ink-muted">+{{ count($row['funders']) - 2 }}</span>
                            @endif
                        </div>
                    </div>

                    <div class="mt-auto flex items-center gap-1 border-t border-secondary-soft px-2.5 py-2 dark:border-line">
                        <x-ui.button tone="ghost" size="sm" icon="arrows-pointing-out" class="min-h-9" wire:click="openInspectModal({{ $indicator->id }})" data-test="inspect-indicator-{{ $indicator->id }}">
                            {{ __('indicators.the_chain') }}
                        </x-ui.button>

                        <div class="ms-auto flex items-center gap-0.5">
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
                </article>
