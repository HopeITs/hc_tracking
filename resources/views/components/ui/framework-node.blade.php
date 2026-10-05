@props(['node', 'depth' => 0, 'order' => 0])

@php
    use App\Enums\RagStatus;
    use App\Enums\ResultType;

    $result = $node['result'];
    $measurement = $node['measurement'];
    $indicatorRows = $measurement['rows'];
    $indicatorCount = count($indicatorRows);
    $rag = $measurement['rag'];
    $ratedCount = $rag['green'] + $rag['amber'] + $rag['red'];
    $achieved = $measurement['achieved'];
    $rollup = $node['rollup'];
    $children = $node['children'];
    $lineage = $node['lineage'];

    /* The one Impact/Outcome/Output identity used across programs, projects and this page. */
    $mark = match ($result->type) {
        ResultType::Impact => 'bg-secondary-dark ring-secondary-dark dark:bg-ink dark:ring-ink',
        ResultType::Outcome => 'bg-primary ring-primary',
        ResultType::Output => 'bg-board ring-secondary-light',
    };

    $accent = match ($result->type) {
        ResultType::Impact => 'h-1 bg-primary',
        ResultType::Outcome => 'h-0.5 bg-primary/60',
        ResultType::Output => 'h-0.5 bg-transparent',
    };

    $statementClasses = match ($result->type) {
        ResultType::Impact => 'text-lg font-bold leading-snug @2xl/directory:text-xl',
        ResultType::Outcome => 'text-base font-semibold leading-snug',
        ResultType::Output => 'text-sm font-semibold leading-relaxed',
    };

    /* Worst rating wins: one red indicator colours the whole statement red. */
    $tone = match (true) {
        $rag['red'] > 0 => 'critical',
        $rag['amber'] > 0 => 'warn',
        $ratedCount > 0 => 'good',
        default => 'neutral',
    };

    $formatValue = function (mixed $value): string {
        if ($value === null || $value === '') {
            return '—';
        }

        $number = (float) $value;

        return number_format($number, fmod($number, 1.0) === 0.0 ? 0 : 2);
    };
@endphp

<div
    {{ $attributes }}
    data-tree-depth="{{ $depth }}"
    data-result-type="{{ $result->type->value }}"
    x-data="{ branchOpen: true, measuresOpen: {{ $indicatorCount > 0 && $indicatorCount <= 4 ? 'true' : 'false' }} }"
>
    <article class="logframe-reveal overflow-hidden rounded-xl border border-secondary-soft bg-white shadow-card transition-colors hover:border-secondary-light dark:border-transparent dark:bg-surface-1 dark:shadow-raised dark:hover:border-field-line" style="--logframe-order: {{ $order }}">
        <span aria-hidden="true" class="block {{ $accent }}"></span>

        <div class="flex flex-wrap items-start gap-x-3 gap-y-2 px-4 pt-3.5">
            <span class="inline-flex shrink-0 items-center gap-1.5">
                <span aria-hidden="true" class="size-2.5 rounded-full ring-2 ring-inset {{ $mark }}"></span>
                <span class="type-label text-secondary-medium dark:text-ink-muted">{{ $result->type->label() }}</span>
            </span>

            <span class="type-meta shrink-0 text-secondary-medium dark:text-ink-muted">
                {{ __('programs.logframe_checkout', ['period' => $result->checkout_period->label()]) }}
            </span>

            <div class="ms-auto flex shrink-0 items-center gap-0.5">
                {{-- An output is the bottom of the chain: nothing contributes to it. --}}
                @unless ($result->type === ResultType::Output)
                    <flux:tooltip :content="__('results_framework.add_a_contributing_result')">
                        @can('results.create')<x-ui.button
                            tone="ghost"
                            size="sm"
                            icon="plus"
                            class="size-8!"
                            wire:click="openCreateModal({{ $result->id }})"
                            :aria-label="__('results_framework.add_a_contributing_result')"
                            data-test="add-child-result-{{ $result->id }}"
                        />@endcan
                    </flux:tooltip>
                @endunless

                <flux:tooltip :content="__('results_framework.edit_result')">
                    @can('results.update')<x-ui.button
                        tone="ghost"
                        size="sm"
                        icon="pencil-square"
                        class="size-8!"
                        wire:click="openEditModal({{ $result->id }})"
                        :aria-label="__('common.edit_name', ['name' => $result->statement])"
                        data-test="edit-result-{{ $result->id }}"
                    />@endcan
                </flux:tooltip>

                @if ($node['deletable'])
                    <flux:tooltip :content="__('results_framework.delete_result')">
                        @can('results.delete')<x-ui.button
                            tone="ghost"
                            size="sm"
                            icon="trash"
                            class="size-8!"
                            wire:click="confirmDelete({{ $result->id }})"
                            :aria-label="__('common.delete_name', ['name' => $result->statement])"
                            data-test="delete-result-{{ $result->id }}"
                        />@endcan
                    </flux:tooltip>
                @else
                    <flux:tooltip :content="__('results_framework.locked_while_it_carries_nested_results_or_indicators')">
                        <span class="inline-flex size-8 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
                            <flux:icon.lock-closed class="size-4" />
                        </span>
                    </flux:tooltip>
                @endif
            </div>
        </div>

        @if ($lineage !== [])
            <p class="mt-1.5 flex flex-wrap items-center gap-1 px-4 type-meta text-secondary-medium dark:text-ink-muted">
                <flux:icon.arrow-turn-down-right variant="micro" class="size-3.5 shrink-0 rtl:-scale-x-100" aria-hidden="true" />
                <span class="sr-only">{{ __('results_framework.contributes_to') }}</span>
                @foreach ($lineage as $ancestor)
                    <span>{{ Str::limit($ancestor->statement, 48) }}</span>
                    @unless ($loop->last)
                        <span aria-hidden="true" class="text-secondary-light">/</span>
                    @endunless
                @endforeach
            </p>
        @endif

        <p class="mt-1.5 px-4 pb-3.5 text-pretty text-secondary-dark {{ $statementClasses }} dark:text-ink">{{ $result->statement }}</p>

        {{-- The measurement rail: what this statement is measured by, and how it is tracking. --}}
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-secondary-soft bg-board-head px-4 py-2.5 dark:border-line">
            @if ($indicatorCount > 0)
                <button
                    type="button"
                    x-on:click="measuresOpen = ! measuresOpen"
                    x-bind:aria-expanded="measuresOpen.toString()"
                    aria-controls="framework-measures-{{ $result->id }}"
                    class="inline-flex items-center gap-1.5 rounded-md type-meta font-semibold text-secondary-dark hover:text-primary dark:hover:text-brand-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-ink"
                >
                    <flux:icon.chart-bar variant="micro" class="size-3.5" aria-hidden="true" />
                    {{ trans_choice('programs.logframe_count_indicators', $indicatorCount, ['count' => $indicatorCount]) }}
                    <flux:icon.chevron-down variant="micro" class="size-3.5 transition-transform duration-200" x-bind:class="measuresOpen ? '' : '-rotate-90 rtl:rotate-90'" aria-hidden="true" />
                </button>
            @else
                <span class="inline-flex items-center gap-1.5 type-meta font-semibold text-status-warn">
                    <flux:icon.exclamation-triangle variant="micro" class="size-3.5" aria-hidden="true" />
                    {{ __('results_framework.no_indicator_yet') }}
                </span>
                {{-- The next step, one click away: the indicator form opens already tied to this statement. --}}
                <a
                    href="{{ route('indicators.index', ['create' => $result->id]) }}"
                    wire:navigate
                    class="inline-flex items-center gap-1 rounded-md type-meta font-semibold text-primary dark:text-brand-text hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                    data-test="add-indicator-for-result-{{ $result->id }}"
                >
                    <flux:icon.plus variant="micro" class="size-3.5" aria-hidden="true" />
                    {{ __('results_framework.add_indicator') }}
                </a>
            @endif

            <span class="inline-flex items-center gap-1.5 type-meta text-secondary-medium dark:text-ink-muted">
                <flux:icon.flag variant="micro" class="size-3.5" aria-hidden="true" />
                {{ trans_choice('common.count_target', $measurement['targets'], ['count' => $measurement['targets']]) }}
            </span>

            @if ($measurement['reports'] > 0)
                <span class="inline-flex items-center gap-1.5 type-meta text-secondary-medium dark:text-ink-muted">
                    <flux:icon.document-check variant="micro" class="size-3.5" aria-hidden="true" />
                    {{ __('results_framework.count_reports_count_approved', ['count' => $measurement['reports'], 'approved' => $measurement['approved']]) }}
                </span>
            @endif

            {{-- Who is paying for this statement: the donor behind a proposal, or the plan year. --}}
            @foreach ($measurement['funders'] as $funder)
                <span @class([
                    'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold',
                    'border-funding/30 bg-funding-soft text-funding' => $funder['type'] === 'donor',
                    'border-secondary-light text-secondary-medium dark:text-ink-muted' => $funder['type'] !== 'donor',
                ])>
                    <flux:icon :name="$funder['type'] === 'donor' ? 'building-library' : 'calendar'" variant="micro" class="size-3" aria-hidden="true" />
                    {{ $funder['label'] }}
                </span>
            @endforeach

            @if ($ratedCount > 0)
                <span class="inline-flex items-center gap-1" aria-label="{{ __('results_framework.rating_split', ['green' => $rag['green'], 'amber' => $rag['amber'], 'red' => $rag['red']]) }}">
                    @foreach (['green' => 'bg-status-good', 'amber' => 'bg-status-warn', 'red' => 'bg-primary dark:bg-status-critical'] as $band => $fill)
                        @if ($rag[$band] > 0)
                            <span class="inline-flex items-center gap-1 rounded-full bg-white px-1.5 py-0.5 text-[11px] font-bold tabular-nums text-secondary-dark dark:bg-surface-2 dark:text-ink">
                                <span aria-hidden="true" class="size-1.5 rounded-full {{ $fill }}"></span>{{ $rag[$band] }}
                            </span>
                        @endif
                    @endforeach
                </span>
            @endif

            @if ($achieved !== null)
                <div class="ms-auto flex w-full min-w-40 items-center gap-2 @md/directory:w-52">
                    {{-- Clamped: progress-bar flags anything over its max as a warning, and over-achievement is not one. --}}
                    <x-ui.progress-bar
                        class="flex-1"
                        :value="min($achieved, 100)"
                        :max="100"
                        :tone="$tone"
                        :label="__('indicators.percent_achieved')"
                        label-hidden
                    />
                    <span class="w-12 shrink-0 text-end type-meta font-bold tabular-nums text-secondary-dark dark:text-ink">{{ number_format($achieved, 0) }}%</span>
                </div>
            @endif

            @if ($rollup['statements'] > 1)
                <span class="inline-flex items-center gap-1.5 type-meta text-secondary-medium dark:text-ink-muted">
                    <flux:icon.share variant="micro" class="size-3.5 rotate-90" aria-hidden="true" />
                    {{ trans_choice('results_framework.count_nested_statement', $rollup['statements'] - 1, ['count' => $rollup['statements'] - 1]) }}
                </span>
            @endif
        </div>

        {{-- Indicator drawer: baseline, latest reading and target for every measure on this statement. --}}
        @if ($indicatorCount > 0)
            <div id="framework-measures-{{ $result->id }}" x-show="measuresOpen" x-collapse>
                <ul class="divide-y divide-secondary-soft border-t border-secondary-soft dark:divide-line dark:border-line">
                    @foreach ($indicatorRows as $row)
                        @php
                            $indicator = $row['indicator'];
                            $series = $row['series'];
                            $rowTone = match ($row['rag']) {
                                RagStatus::Green => 'good',
                                RagStatus::Amber => 'warn',
                                RagStatus::Red => 'critical',
                                default => 'neutral',
                            };
                        @endphp
                        <li class="px-4 py-3" wire:key="framework-indicator-{{ $indicator->id }}">
                          <div class="flex flex-col gap-2.5 @2xl/directory:flex-row @2xl/directory:items-center @2xl/directory:gap-4">
                            <div class="min-w-0 @2xl/directory:flex-1">
                                <p class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('indicators.index', ['inspect' => $indicator->id]) }}" wire:navigate class="truncate text-sm font-semibold text-secondary-dark hover:text-primary dark:hover:text-brand-text hover:underline dark:text-ink">{{ $indicator->name }}</a>
                                    <x-ui.enum-badge :case="$indicator->measure" class="shrink-0" />
                                    @if ($indicator->project !== null)
                                        <span class="inline-flex shrink-0 items-center gap-1 type-meta text-secondary-medium dark:text-ink-muted">
                                            <flux:icon.briefcase variant="micro" class="size-3.5" aria-hidden="true" />{{ $indicator->project->name }}
                                        </span>
                                    @endif
                                </p>
                                <p class="mt-0.5 flex flex-wrap items-center gap-x-1.5 type-meta text-secondary-medium dark:text-ink-muted">
                                    <span class="tabular-nums">{{ $formatValue($indicator->baseline) }}</span>
                                    <flux:icon.arrow-long-right variant="micro" class="size-3.5 rtl:rotate-180" aria-hidden="true" />
                                    <span class="font-bold tabular-nums text-secondary-dark dark:text-ink">{{ $formatValue($row['latest']) }}</span>
                                    <flux:icon.arrow-long-right variant="micro" class="size-3.5 rtl:rotate-180" aria-hidden="true" />
                                    <span class="tabular-nums">{{ $formatValue($indicator->target) }}</span>
                                    @if ($indicator->unit !== null && $indicator->unit !== '')
                                        <span>· {{ $indicator->unit }}</span>
                                    @endif
                                    <span>· {{ $indicator->frequency->label() }}</span>
                                    @if ($row['latestDate'] !== null)
                                        <span>· {{ __('results_framework.read_on_date', ['date' => $row['latestDate']->translatedFormat(__('formats.date'))]) }}</span>
                                    @endif
                                </p>
                            </div>

                            @if (count($series) > 1)
                                @php
                                    $lowest = min($series);
                                    $highest = max($series);
                                    $span = $highest - $lowest;
                                    $points = collect($series)->values()
                                        ->map(fn (float $value, int $index): string => round(($index / (count($series) - 1)) * 60, 1).','.round($span > 0 ? 20 - ((($value - $lowest) / $span) * 20) : 10, 1))
                                        ->implode(' ');
                                @endphp
                                <svg viewBox="0 0 60 20" class="h-5 w-16 shrink-0 text-secondary-light" aria-hidden="true">
                                    <polyline points="{{ $points }}" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            @endif

                            <div class="flex items-center gap-2 @2xl/directory:w-56">
                                {{-- Clamped: progress-bar flags anything over its max as a warning, and over-achievement is not one. --}}
                                <x-ui.progress-bar
                                    class="min-w-20 flex-1"
                                    :value="$row['achieved'] === null ? null : min($row['achieved'], 100)"
                                    :max="100"
                                    :tone="$rowTone"
                                    :label="__('indicators.percent_achieved')"
                                    label-hidden
                                />
                                <span class="w-12 shrink-0 text-end type-meta font-semibold tabular-nums text-secondary-dark dark:text-ink">
                                    {{ $row['achieved'] === null ? '—' : number_format($row['achieved'], 0).'%' }}
                                </span>
                                <x-ui.enum-badge :case="$row['rag']" class="shrink-0" />
                            </div>
                          </div>

                          {{-- The funding chain: every target committed on this indicator, where it was committed, and what has been reported. --}}
                          @if ($row['targetRows'] !== [])
                            <ul class="mt-2.5 flex flex-col gap-2 border-s border-secondary-soft ps-3 @xl/directory:flex-row @xl/directory:flex-wrap dark:border-line">
                                @foreach ($row['targetRows'] as $targetRow)
                                    {{-- Two rows inside the card: where it was committed, then what was committed. Keeps the
                                         source name readable at 375px instead of truncating it to a single letter. --}}
                                    <li class="flex flex-1 flex-col gap-1.5 rounded-lg border border-secondary-soft px-2.5 py-2 @xl/directory:min-w-72 dark:border-line" wire:key="framework-target-{{ $targetRow['target']->id }}">
                                        <div class="flex min-w-0 items-center gap-2">
                                            <span @class([
                                                'inline-flex shrink-0 items-center gap-1 rounded-md border px-1.5 py-0.5 text-[11px] font-semibold',
                                                'border-funding/30 bg-funding-soft text-funding' => $targetRow['sourceType'] === 'proposal',
                                                'border-secondary-light text-secondary-medium dark:text-ink-muted' => $targetRow['sourceType'] !== 'proposal',
                                            ])>
                                                <flux:icon :name="$targetRow['sourceType'] === 'proposal' ? 'building-library' : 'calendar'" variant="micro" class="size-3" aria-hidden="true" />
                                                {{ $targetRow['sourceType'] === 'proposal' ? __('common.proposal') : __('common.annual_plan') }}
                                            </span>

                                            <span class="min-w-0 flex-1 truncate type-meta text-secondary-dark dark:text-ink">
                                                @if ($targetRow['sourceLabel'] === null)
                                                    {{-- source_id carries no foreign key, so the plan or proposal can be gone. --}}
                                                    <span class="text-status-warn">{{ __('targets.source_no_longer_available') }}</span>
                                                @else
                                                    {{ $targetRow['sourceLabel'] }}
                                                @endif
                                                @if ($targetRow['donors'] !== [])
                                                    {{-- Plain text, not the funding tint: #6fb0bd on the dark card surface only reaches 2.9:1. --}}
                                                    <span class="font-bold text-secondary-dark dark:text-ink">· {{ implode(', ', $targetRow['donors']) }}</span>
                                                @endif
                                                @if ($targetRow['project'] !== null)
                                                    <span class="text-secondary-medium dark:text-ink-muted">· {{ $targetRow['project'] }}</span>
                                                @endif
                                            </span>
                                        </div>

                                        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5">
                                            <x-ui.enum-badge :case="$targetRow['target']->period" class="shrink-0" />

                                            <span class="shrink-0 text-sm font-bold tabular-nums text-secondary-dark dark:text-ink">{{ $formatValue($targetRow['target']->target) }}</span>

                                            {{-- Approved achievement, by the one rule every page shares (PlanIndicator::achievedValue()). --}}
                                            @if ($targetRow['percent'] !== null)
                                                <span @class([
                                                    'shrink-0 type-meta font-semibold tabular-nums',
                                                    'text-status-good' => $targetRow['status'] === 'achieved',
                                                    'text-secondary-dark dark:text-ink' => $targetRow['status'] === 'in_progress',
                                                    'text-secondary-medium dark:text-ink-muted' => $targetRow['status'] === 'not_started',
                                                ])>{{ __('results_framework.percent_achieved', ['percent' => number_format($targetRow['percent'], 0)]) }}</span>
                                            @endif

                                            <flux:tooltip :content="$targetRow['reports'] > 0 ? __('results_framework.count_reports_count_approved', ['count' => $targetRow['reports'], 'approved' => $targetRow['approved']]) : __('projects.target_no_reports_yet')">
                                                <span @class([
                                                    'ms-auto inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums',
                                                    'bg-status-good-soft text-status-good' => $targetRow['reports'] > 0,
                                                    'bg-status-warn-soft text-status-warn' => $targetRow['reports'] === 0,
                                                ])>
                                                    <flux:icon :name="$targetRow['reports'] > 0 ? 'document-check' : 'exclamation-triangle'" variant="micro" class="size-3.5" aria-hidden="true" />
                                                    {{ $targetRow['reports'] }}
                                                </span>
                                            </flux:tooltip>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                          @endif
                        </li>
                    @endforeach
                </ul>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-secondary-soft px-4 py-2 dark:border-line">
                    <a href="{{ route('indicators.index', ['result' => $result->id]) }}" wire:navigate class="type-meta font-semibold text-secondary-medium hover:text-primary dark:hover:text-brand-text hover:underline dark:text-ink-muted">
                        {{ __('results_framework.manage_indicators') }}
                    </a>
                    <a href="{{ route('indicators.index', ['create' => $result->id]) }}" wire:navigate class="inline-flex items-center gap-1 type-meta font-semibold text-secondary-medium hover:text-primary dark:hover:text-brand-text hover:underline dark:text-ink-muted">
                        <flux:icon.plus variant="micro" class="size-3.5" aria-hidden="true" />
                        {{ __('results_framework.add_indicator') }}
                    </a>
                    @if ($measurement['targets'] > 0)
                        <a href="{{ route('targets.index', ['result' => $result->id]) }}" wire:navigate class="type-meta font-semibold text-secondary-medium hover:text-primary dark:hover:text-brand-text hover:underline dark:text-ink-muted">
                            {{ __('results_framework.view_targets') }}
                        </a>
                    @endif
                </div>
            </div>
        @endif
    </article>

    @if ($children !== [])
        <div class="ps-3 @2xl/directory:ps-6">
            <button
                type="button"
                x-on:click="branchOpen = ! branchOpen"
                x-bind:aria-expanded="branchOpen.toString()"
                aria-controls="framework-branch-{{ $result->id }}"
                class="mt-2 inline-flex items-center gap-1.5 rounded-md type-meta font-semibold text-secondary-medium hover:text-primary dark:hover:text-brand-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-ink-muted"
            >
                <flux:icon.chevron-down variant="micro" class="size-3.5 transition-transform duration-200" x-bind:class="branchOpen ? '' : '-rotate-90 rtl:rotate-90'" aria-hidden="true" />
                {{ trans_choice('programs.logframe_count_contributing', count($children), ['count' => count($children)]) }}
            </button>

            <ol id="framework-branch-{{ $result->id }}" x-show="branchOpen" x-collapse class="mt-2 space-y-4">
                @foreach ($children as $child)
                    <li class="relative ps-6" wire:key="framework-child-{{ $child['result']->id }}">
                        <span aria-hidden="true" @class([
                            'absolute start-0 top-0 w-px bg-secondary-soft dark:bg-line-strong',
                            'h-7' => $loop->last,
                            '-bottom-4' => ! $loop->last,
                        ])></span>
                        <span aria-hidden="true" class="absolute start-0 top-7 h-px w-6 bg-secondary-soft dark:bg-line-strong"></span>

                        <x-ui.framework-node :node="$child" :depth="$depth + 1" :order="$loop->index" />
                    </li>
                @endforeach
            </ol>
        </div>
    @endif
</div>
