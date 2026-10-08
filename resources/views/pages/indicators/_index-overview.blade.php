{{--
    The overview panel of indicators/index: four headline figures, the
    rating split and the commitments behind them. Included by
    ⚡index.blade.php, whose top PHP block defines $overview, $ragSegments
    and $onTrackShare.
--}}
    {{-- Measurement at a glance: four figures, then how the ratings split. --}}
    <x-ui.panel data-test="indicator-overview">
        <div class="grid gap-px bg-secondary-soft @xl/directory:grid-cols-2 @4xl/directory:grid-cols-4 dark:bg-line">
            @php
                $achievementTone = match (true) {
                    $overview['achieved'] === null => 'neutral',
                    $overview['achieved'] >= $overview['elapsed'] * 0.9 => 'good',
                    $overview['achieved'] >= $overview['elapsed'] * 0.7 => 'warn',
                    default => 'critical',
                };

                $headline = [
                    [
                        'label' => __('common.indicators'),
                        'value' => number_format($overview['indicators']),
                        'sub' => __('indicators.measuring_count_of_total_results', ['count' => $overview['results'], 'total' => $this->resultOptions->count()]),
                        'icon' => 'chart-bar',
                        'tone' => 'neutral',
                    ],
                    [
                        'label' => __('indicators.readings_logged'),
                        'value' => number_format($overview['readings']),
                        'sub' => $overview['lastReading'] === null
                            ? __('indicators.no_readings_logged_yet')
                            : __('indicators.last_logged_date', ['date' => $overview['lastReading']->translatedFormat(__('formats.date'))]),
                        'icon' => 'clipboard-document-check',
                        'tone' => 'neutral',
                    ],
                    [
                        'label' => __('indicators.average_achievement'),
                        'value' => $overview['achieved'] === null ? '—' : number_format($overview['achieved'], 0).'%',
                        'sub' => __('indicators.against_percent_of_the_year_elapsed', ['percent' => number_format($overview['elapsed'], 0)]),
                        'icon' => 'presentation-chart-line',
                        'tone' => $achievementTone,
                    ],
                    [
                        'label' => __('indicators.on_track'),
                        'value' => $onTrackShare === null ? '—' : number_format($onTrackShare, 0).'%',
                        'sub' => __('indicators.rated_green_of_total', ['count' => $overview['rag']['green'], 'total' => $overview['rated']]),
                        'icon' => 'signal',
                        'tone' => match (true) {
                            $onTrackShare === null => 'neutral',
                            $onTrackShare >= 80 => 'good',
                            $onTrackShare >= 50 => 'warn',
                            default => 'critical',
                        },
                    ],
                ];
            @endphp

            @foreach ($headline as $cell)
                <div class="flex items-start justify-between gap-3 bg-white p-4 dark:bg-surface-1" wire:key="indicator-headline-{{ $loop->index }}">
                    <div class="min-w-0">
                        <p class="type-label text-secondary-medium dark:text-ink-muted">{{ $cell['label'] }}</p>
                        <p @class([
                            'type-figure mt-2',
                            'text-status-good' => $cell['tone'] === 'good',
                            'text-status-warn' => $cell['tone'] === 'warn',
                            'text-primary dark:text-brand-text' => $cell['tone'] === 'critical',
                            'text-secondary-dark dark:text-ink' => $cell['tone'] === 'neutral',
                        ])>{{ $cell['value'] }}</p>
                        <p class="mt-1 type-meta text-secondary-medium dark:text-ink-muted">{{ $cell['sub'] }}</p>
                    </div>
                    <div @class([
                        'flex size-10 shrink-0 items-center justify-center rounded-xl',
                        'bg-status-good-soft text-status-good' => $cell['tone'] === 'good',
                        'bg-status-warn-soft text-status-warn' => $cell['tone'] === 'warn',
                        'bg-status-critical-soft text-primary dark:text-brand-text' => $cell['tone'] === 'critical',
                        'bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink' => $cell['tone'] === 'neutral',
                    ])>
                        <flux:icon :name="$cell['icon']" class="size-5" />
                    </div>
                </div>
            @endforeach
        </div>

        {{-- The rating split, as one bar. Every band is a filter. --}}
        <div class="flex flex-col gap-3 border-t border-secondary-soft bg-board-head px-4 py-4 @3xl/directory:flex-row @3xl/directory:items-center @3xl/directory:gap-6 dark:border-line">
            <div class="min-w-0 flex-1">
                <div class="flex items-baseline justify-between gap-3">
                    <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('indicators.how_the_ratings_split') }}</p>
                    <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('indicators.rated_against_percent_of_the_year_elapsed', ['percent' => number_format($overview['elapsed'], 0)]) }}</p>
                </div>

                <div class="mt-2 flex h-2.5 w-full overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2" role="group" aria-label="{{ __('indicators.how_the_ratings_split') }}">
                    @foreach ($ragSegments as $segment)
                        @if ($segment['count'] > 0)
                            <button
                                type="button"
                                wire:click="setRag('{{ $segment['key'] }}')"
                                wire:key="rag-band-{{ $segment['key'] }}"
                                data-test="rag-band-{{ $segment['key'] }}"
                                aria-pressed="{{ $rag === $segment['key'] ? 'true' : 'false' }}"
                                style="width: {{ ($segment['count'] / max($overview['indicators'], 1)) * 100 }}%"
                                @class([
                                    'h-full transition-opacity focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary',
                                    $segment['fill'],
                                    'opacity-100' => $rag === '' || $rag === $segment['key'],
                                    'opacity-30' => $rag !== '' && $rag !== $segment['key'],
                                ])
                                aria-label="{{ $segment['label'].' · '.$segment['count'] }}"
                            ></button>
                        @endif
                    @endforeach
                </div>

                <div class="mt-2.5 flex flex-wrap items-center gap-x-4 gap-y-1.5">
                    @foreach ($ragSegments as $segment)
                        <button
                            type="button"
                            wire:click="setRag('{{ $segment['key'] }}')"
                            wire:key="rag-legend-{{ $segment['key'] }}"
                            aria-pressed="{{ $rag === $segment['key'] ? 'true' : 'false' }}"
                            @class([
                                'inline-flex items-center gap-1.5 rounded-md px-1 py-0.5 type-meta transition-colors focus-visible:outline-2 focus-visible:outline-primary',
                                'font-bold text-secondary-dark dark:text-ink' => $rag === $segment['key'],
                                'text-secondary-medium hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink' => $rag !== $segment['key'],
                            ])
                        >
                            <span aria-hidden="true" class="size-2 shrink-0 rounded-full {{ $segment['dot'] }}"></span>
                            {{ $segment['label'] }}
                            <span class="tabular-nums">{{ $segment['count'] }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Who is paying for what is being measured. --}}
            <div class="flex shrink-0 items-center gap-3 border-secondary-soft @3xl/directory:border-s @3xl/directory:ps-6 dark:border-line">
                <x-ui.progress-ring
                    size="lg"
                    :value="$overview['achieved']"
                    :tone="$achievementTone"
                    :label="__('indicators.average_achievement')"
                />
                <div class="min-w-0">
                    <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('indicators.commitments_behind_these_indicators') }}</p>
                    <p class="mt-1 text-sm font-semibold text-secondary-dark dark:text-ink">
                        {{ trans_choice('common.count_target', $overview['targets'], ['count' => $overview['targets']]) }}
                        · {{ __('indicators.count_of_total_reports_approved', ['count' => $overview['approved'], 'total' => $overview['reports']]) }}
                    </p>

                    {{-- Counted, not summed: the targets span different units and measures. --}}
                    <p class="mt-0.5 type-meta tabular-nums text-secondary-medium dark:text-ink-muted">
                        {{ __('indicators.count_of_total_targets_achieved', ['count' => $overview['targetsAchieved'], 'total' => $overview['targets']]) }}
                        @if ($overview['overdueReports'] > 0)
                            · <span class="font-semibold text-status-warn">{{ trans_choice('indicators.count_report_overdue', $overview['overdueReports'], ['count' => $overview['overdueReports']]) }}</span>
                        @endif
                    </p>

                    @if ($overview['funders'] !== [])
                        <div class="mt-2 flex flex-wrap items-center gap-1">
                            <span class="sr-only">{{ __('indicators.funded_by') }}</span>
                            @foreach (array_slice($overview['funders'], 0, 4) as $funder)
                                <span @class([
                                    'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold',
                                    'border-funding/30 bg-funding-soft text-funding' => $funder['type'] === 'donor',
                                    'border-secondary-light text-secondary-medium dark:text-ink-muted' => $funder['type'] !== 'donor',
                                ])>
                                    <flux:icon :name="$funder['type'] === 'donor' ? 'building-library' : 'calendar'" variant="micro" class="size-3" aria-hidden="true" />
                                    {{ $funder['label'] }}
                                </span>
                            @endforeach
                            @if (count($overview['funders']) > 4)
                                <span class="type-meta text-secondary-medium dark:text-ink-muted">+{{ count($overview['funders']) - 4 }}</span>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </x-ui.panel>
