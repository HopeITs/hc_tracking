@use('App\Enums\ResultType')

{{-- The framework at a glance: four figures. Shown above both views of ⚡index.blade.php. --}}
    <x-ui.panel data-test="framework-overview">
        <div class="grid gap-px bg-secondary-soft @xl/directory:grid-cols-2 @4xl/directory:grid-cols-4 dark:bg-line">
            @php
                $headline = [
                    [
                        'label' => __('results_framework.statements'),
                        'value' => number_format($overview['statements']),
                        'sub' => trans_choice('results_framework.count_across_program', $programCount, ['count' => $programCount]),
                        'icon' => 'rectangle-stack',
                        'tone' => 'neutral',
                    ],
                    [
                        'label' => __('common.indicators'),
                        'value' => number_format($overview['indicators']),
                        'sub' => __('results_framework.measuring_count_of_total_statements', ['count' => $overview['measured'], 'total' => $overview['statements']]),
                        'icon' => 'chart-bar',
                        'tone' => 'neutral',
                    ],
                    [
                        'label' => __('results_framework.measurement_coverage'),
                        'value' => $overview['coverage'] === null ? '—' : number_format($overview['coverage'], 0).'%',
                        'sub' => trans_choice('results_framework.count_statement_without_an_indicator', $overview['statements'] - $overview['measured'], ['count' => $overview['statements'] - $overview['measured']]),
                        'icon' => 'check-badge',
                        'tone' => $coverageTone,
                    ],
                    [
                        'label' => __('results_framework.on_track'),
                        'value' => $onTrackShare === null ? '—' : number_format($onTrackShare, 0).'%',
                        'sub' => __('results_framework.rated_green_of_total', ['count' => $overview['rag']['green'], 'total' => $overview['rated']]),
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
                <div class="flex items-start justify-between gap-3 bg-white p-4 dark:bg-surface-1" wire:key="framework-headline-{{ $loop->index }}">
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

        {{-- The chain itself. Each rung is a filter: click a level to narrow the page to it. --}}
        <div class="flex flex-col gap-4 border-t border-secondary-soft bg-board-head px-4 py-4 @4xl/directory:flex-row @4xl/directory:items-center dark:border-line">
            <ol class="flex min-w-0 flex-1 flex-col gap-2 @2xl/directory:flex-row @2xl/directory:items-stretch" aria-label="{{ __('programs.logframe_results_chain') }}">
                @foreach (ResultType::cases() as $levelIndex => $levelCase)
                    @php
                        $level = $overview['levels'][$levelCase->value];
                        $isActiveLevel = $type === $levelCase->value;
                    @endphp
                    <li class="flex min-w-0 flex-1 items-stretch gap-2" wire:key="framework-level-{{ $levelCase->value }}">
                        <button
                            type="button"
                            wire:click="setType('{{ $levelCase->value }}')"
                            aria-pressed="{{ $isActiveLevel ? 'true' : 'false' }}"
                            data-test="level-filter-{{ $levelCase->value }}"
                            @class([
                                'group flex min-w-0 flex-1 flex-col rounded-xl border p-3 text-start transition-all focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                                'border-primary bg-white shadow-card dark:bg-surface-1' => $isActiveLevel,
                                'border-secondary-soft bg-white hover:-translate-y-0.5 hover:border-secondary-light dark:border-line dark:bg-surface-1' => ! $isActiveLevel,
                            ])
                        >
                            <span class="flex items-center gap-2">
                                <span aria-hidden="true" class="size-2.5 shrink-0 rounded-full ring-2 ring-inset {{ $levelMarks[$levelCase->value] }}"></span>
                                <span class="type-label truncate text-secondary-medium dark:text-ink-muted">{{ $levelCase->label() }}</span>
                                <span class="ms-auto text-xl font-bold tabular-nums text-secondary-dark dark:text-ink">{{ $level['statements'] }}</span>
                            </span>

                            <span class="mt-1.5 block type-meta text-secondary-medium dark:text-ink-muted">
                                {{ __('results_framework.measured_count_of_total', ['count' => $level['measured'], 'total' => $level['statements']]) }}
                                · {{ trans_choice('programs.logframe_count_indicators', $level['indicators'], ['count' => $level['indicators']]) }}
                            </span>

                            {{-- Pinned to the bottom so the three bars line up however the copy above them wraps. --}}
                            <x-ui.progress-bar
                                class="mt-auto pt-2.5"
                                :value="$level['measured']"
                                :max="max($level['statements'], 1)"
                                :tone="$level['statements'] > 0 && $level['measured'] === $level['statements'] ? 'good' : ($level['measured'] === 0 ? 'warn' : 'neutral')"
                                :label="__('results_framework.measurement_coverage')"
                                label-hidden
                            />
                        </button>

                        @unless ($loop->last)
                            <flux:icon.arrow-long-right variant="micro" class="hidden size-4 shrink-0 self-center text-secondary-light @2xl/directory:block rtl:rotate-180" aria-hidden="true" />
                            <flux:icon.arrow-long-down variant="micro" class="mx-auto size-4 shrink-0 self-center text-secondary-light @2xl/directory:hidden" aria-hidden="true" />
                        @endunless
                    </li>
                @endforeach
            </ol>

            <div class="flex shrink-0 items-center gap-3 border-secondary-soft @4xl/directory:border-s @4xl/directory:ps-5 dark:border-line">
                <x-ui.progress-ring
                    size="lg"
                    :value="$overview['coverage']"
                    :tone="$coverageTone"
                    :label="__('results_framework.measurement_coverage')"
                />
                <div class="min-w-0">
                    <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('results_framework.measurement_coverage') }}</p>
                    <p class="mt-1 text-sm font-semibold text-secondary-dark dark:text-ink">
                        {{ __('results_framework.measured_count_of_total', ['count' => $overview['measured'], 'total' => $overview['statements']]) }}
                    </p>
                    <p class="mt-0.5 type-meta text-secondary-medium dark:text-ink-muted">
                        {{ trans_choice('common.count_target', $overview['targets'], ['count' => $overview['targets']]) }}
                        · {{ __('results_framework.count_reports_count_approved', ['count' => $overview['reports'], 'approved' => $overview['approved']]) }}
                    </p>

                    @if ($overview['funders'] !== [])
                        <div class="mt-2 flex flex-wrap items-center gap-1">
                            <span class="sr-only">{{ __('results_framework.funded_by') }}</span>
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
