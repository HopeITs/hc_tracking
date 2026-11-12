@use('App\Enums\ResultType')
@use('App\Models\AnnualPlan')
@use('App\Models\Report')
@php
    /*
     * The program's Annual plans tab: a strip of fiscal years on top, then
     * the picked year's plan (who runs it, how much of the year has gone, how
     * far its targets are), stat cards that filter its targets, and a grid of
     * group cards (project, level, reporting or indicator) that each open to
     * list their targets with progress, reports and a Record report button.
     */
    $user = auth()->user();
    $slots = $this->planStripYears;
    $shownYear = $this->shownPlanYear;
    $plan = $this->shownPlan;
    $board = $plan === null ? null : $this->planBoard;
    $currentYear = (int) now()->year;
    $elapsed = $this->planYearElapsed($shownYear);
    $canCreatePlan = $user?->can('create', AnnualPlan::class) === true;
    $canReport = $user?->can('create', Report::class) === true;
    $createUrl = fn (?int $year): string => route('annual-plans.targets', array_filter(['create' => 1, 'program' => $program->id, 'year' => $year]));

    // The shared result-level marks (.ai/rules/components-ui.md).
    $levelMarks = [
        ResultType::Impact->value => 'bg-secondary-dark dark:bg-ink',
        ResultType::Outcome->value => 'bg-primary',
        ResultType::Output->value => 'bg-secondary-light',
        ResultType::Activity->value => 'bg-secondary-soft ring-1 ring-inset ring-secondary-medium dark:bg-surface-2 dark:ring-ink-muted',
        ResultType::CrossCutting->value => 'bg-secondary-medium dark:bg-ink-muted',
    ];
    $statusTones = ['late' => 'dk-bad', 'review' => 'dk-active', 'draft' => '', 'none' => '', 'approved' => 'dk-good'];
    $yearState = fn (int $year): string => match (true) {
        $year === $currentYear => __('programs.plan_status_current'),
        $year < $currentYear => __('programs.plan_status_closed'),
        default => __('programs.plan_status_upcoming'),
    };
@endphp

<section class="dk-ptk dk-ppl" data-test="program-plans">
    {{-- 1. Title, search and the add button. --}}
    <header class="dk-ptk-h">
        <h2>{{ __('programs.annual_plans') }} <span class="dk-num">{{ $program->annual_plans_count }}</span></h2>
        @if ($board !== null && $board['total'] > 0)
            <label class="dk-prog-search">
                <span class="sr-only">{{ __('programs.plans_find') }}</span>
                <x-desk.icon name="search" />
                <input type="search" wire:model.live.debounce.300ms="planSearch" placeholder="{{ __('programs.plans_find') }}" data-test="program-plan-search" />
            </label>
        @endif
        @if ($canCreatePlan)
            <x-ui.button tone="primary" size="sm" icon="plus" :href="$createUrl($plan === null ? $shownYear : null)" wire:navigate data-test="add-program-plan-button">{{ __('annual_plans.new_annual_plan') }}</x-ui.button>
        @endif
    </header>

        {{-- 2. Last year, this year and next year; the picked year drives everything below. --}}
        <nav class="dk-ppl-years" aria-label="{{ __('programs.plans_years') }}"
            x-init="$nextTick(() => { const picked = $el.querySelector('[aria-current=true]'); if (picked && $el.scrollWidth > $el.clientWidth) { $el.scrollLeft = picked.offsetLeft - ($el.clientWidth - picked.offsetWidth) / 2 } })">
            @foreach ($slots as $slot)
                <button type="button" wire:key="plan-year-{{ $slot['year'] }}" wire:click="pickPlanYear({{ $slot['year'] }})"
                    aria-current="{{ $slot['year'] === $shownYear ? 'true' : 'false' }}"
                    @class(['dk-ppl-year', 'dk-ppl-gap' => $slot['plan'] === null, 'dk-ppl-now' => $slot['year'] === $currentYear])
                    data-plan-year="{{ $slot['year'] }}">
                    <span class="dk-ppl-year-y dk-num">{{ $slot['year'] }}</span>
                    <span class="dk-ppl-year-s">
                        @if ($slot['plan'] === null)
                            {{ __('programs.plans_no_plan') }}
                        @else
                            {{ trans_choice('programs.plans_target_count', $slot['targets'], ['count' => $slot['targets']]) }}
                        @endif
                    </span>
                    @if ($slot['year'] === $currentYear)
                        <i class="dk-ppl-year-tag">{{ __('programs.plan_now') }}</i>
                    @endif
                </button>
            @endforeach
        </nav>

        @if ($plan === null)
            {{-- 3a. A year without a plan: say so, and offer to write it. --}}
            <div class="dk-ppl-gapcard" data-plan-gap="{{ $shownYear }}">
                <span class="dk-ppl-gapcard-i"><x-desk.icon name="cal" /></span>
                <div>
                    <h3>{{ __('programs.plans_gap_title', ['year' => $shownYear]) }}</h3>
                    <p>{{ $shownYear < $currentYear ? __('programs.plans_gap_past') : __('programs.plans_gap_body') }}</p>
                </div>
                @if ($canCreatePlan)
                    <x-ui.button tone="primary" size="sm" icon="plus" :href="$createUrl($shownYear)" wire:navigate data-test="plan-gap-create">{{ __('programs.plans_write_plan', ['year' => $shownYear]) }}</x-ui.button>
                @endif
            </div>
        @else
            {{-- 3b. The picked plan at a glance. --}}
            <div class="dk-ppl-bar" wire:key="plan-bar-{{ $plan->id }}">
                <div class="dk-ppl-bar-id">
                    <p @class(['dk-ppl-bar-k', 'dk-ppl-bar-k-now' => $shownYear === $currentYear])>{{ $yearState($shownYear) }}</p>
                    <h3>{{ __('common.fy_year', ['year' => $shownYear]) }}</h3>
                </div>

                <div class="dk-ppl-bar-cell">
                    <p class="dk-ppl-bar-k">{{ __('programs.plans_manager') }}</p>
                    <p class="dk-ppl-bar-who">
                        @if ($plan->manager !== null)
                            <x-ui.avatar :src="\App\Models\HrEmployee::avatarUrl($plan->manager_id)" class="dk-av dk-av-xs" aria-hidden="true" />
                        @endif
                        <span>{{ $plan->manager?->name ?? __('common.not_assigned') }}</span>
                    </p>
                </div>

                <div class="dk-ppl-bar-cell">
                    <p class="dk-ppl-bar-k">{{ __('programs.plans_year_gone') }}</p>
                    <p class="dk-ppl-bar-fig"><span class="dk-num">{{ $elapsed }}%</span><span class="dk-ppl-meter" aria-hidden="true"><i style="width: {{ $elapsed }}%"></i></span></p>
                </div>

                @if ($board !== null && $board['progress'] !== null)
                    <div class="dk-ppl-bar-cell">
                        <p class="dk-ppl-bar-k">{{ __('programs.plans_average_progress') }}</p>
                        <p class="dk-ppl-bar-fig"><span class="dk-num">{{ $board['progress'] }}%</span><span class="dk-ppl-meter dk-ppl-meter-good" aria-hidden="true"><i style="width: {{ $board['progress'] }}%"></i></span></p>
                    </div>
                @endif

                <a href="{{ route('annual-plans.show', $plan) }}" wire:navigate class="dk-ptk-pane-link dk-ppl-bar-open" data-test="plan-open">{{ __('programs.plans_open_plan') }}<x-desk.icon name="arrow" class="dk-flip" /></a>
            </div>

            @if ($board === null)
                <p class="dk-prog-clear dk-ppl-note">{{ __('programs.plans_targets_hidden') }}</p>
            @elseif ($board['total'] === 0)
                <x-ui.empty-state icon="chart-bar" :heading="__('common.no_targets_yet')" :body="__('programs.plans_no_targets_body')" />
            @else
                @php
                    $counts = $board['counts'];
                    $cards = [
                        ['key' => 'all', 'label' => __('programs.plans_filter_all'), 'tone' => null],
                        ['key' => 'reached', 'label' => __('programs.plans_filter_reached'), 'tone' => null],
                        ['key' => 'behind', 'label' => __('programs.plans_filter_behind'), 'tone' => $counts['behind'] > 0 ? 'warn' : null],
                        ['key' => 'late', 'label' => __('programs.plans_filter_late'), 'tone' => $counts['late'] > 0 ? 'bad' : null],
                        ['key' => 'review', 'label' => __('programs.plans_filter_review'), 'tone' => null],
                    ];
                @endphp

                {{-- 4. Stat cards that are also the filters; clicking the active one goes back to every target. --}}
                <div @class(['dk-ptk-cards', 'dk-prog-chips-off' => $board['searching']]) role="group" aria-label="{{ __('programs.plans_filters') }}">
                    @foreach ($cards as $card)
                        <button type="button" wire:key="plan-filter-{{ $card['key'] }}" wire:click="pickPlanFilter('{{ $card['key'] }}')"
                            aria-pressed="{{ $this->planFilter === $card['key'] ? 'true' : 'false' }}"
                            @class(['dk-ptk-card', 'dk-bad' => $card['tone'] === 'bad', 'dk-warn' => $card['tone'] === 'warn']) data-plan-filter="{{ $card['key'] }}">
                            <span class="dk-ptk-card-k">{{ $card['label'] }}</span>
                            <span class="dk-ptk-card-n dk-num">
                                {{ $counts[$card['key']] }}@if ($card['key'] === 'reached')<small>/{{ $board['total'] }}</small>@endif
                            </span>
                        </button>
                    @endforeach
                </div>

                {{-- 5. What the cards below stand for. --}}
                <div class="dk-ptk-bar">
                    <div class="dk-ptk-seg" role="group" aria-label="{{ __('programs.tasks_group_by') }}">
                        @foreach ($this::PLAN_GROUPINGS as $grouping)
                            <button type="button" wire:key="plan-group-{{ $grouping }}" wire:click="$set('planGroupBy', '{{ $grouping }}')" aria-pressed="{{ $this->planGroupBy === $grouping ? 'true' : 'false' }}" data-plan-grouping="{{ $grouping }}">{{ __('programs.plans_group_'.$grouping) }}</button>
                        @endforeach
                    </div>
                    <p class="dk-ppl-legend">{{ __('programs.plans_cards_hint') }}</p>
                </div>

                {{-- 6. One card per group, one row at first and a row per click; a card's targets open in a modal. --}}
                @if ($board['groups']->isEmpty())
                    <p class="dk-prog-clear dk-ppl-note dk-ppl-nothing">{{ $board['searching'] ? __('programs.plans_none_match') : __('programs.plans_none_in_filter') }}</p>
                @else
                    <div wire:key="plan-groups-{{ $shownYear }}-{{ $this->planGroupBy }}-{{ $this->planFilter }}-{{ $board['searching'] ? 'q' : 'all' }}"
                        x-data="{
                            rows: 1,
                            cols: 1,
                            total: {{ $board['groups']->count() }},
                            all: @js($board['searching']),
                            get limit() { return this.all ? Infinity : this.rows * this.cols },
                            get remaining() { return Math.max(0, this.total - this.limit) },
                            measure() { this.cols = Math.max(1, getComputedStyle($refs.grid).gridTemplateColumns.split(' ').filter(Boolean).length) },
                        }"
                        x-init="$nextTick(() => measure())" @resize.window.debounce.150ms="measure()">
                        <div class="dk-prog-cards dk-ppl-grid" x-ref="grid" wire:loading.class="dk-ptk-busy" wire:target="pickPlanFilter, planGroupBy, planSearch, pickPlanYear">
                            @foreach ($board['groups'] as $group)
                                @include('pages.programs._plan-group-card', ['cardKey' => 'plan-group-card-'.$shownYear.'-'.$this->planGroupBy.'-'.$group['key'].'-'.$this->planFilter, 'cardIndex' => $loop->index])
                            @endforeach
                        </div>

                        <div class="dk-prog-more-row" x-show="! all" x-cloak>
                            <button type="button" class="dk-prog-more" x-show="remaining > 0" @click="rows++" data-test="plan-groups-more">
                                <span x-text="Math.min(cols, remaining) === 1 ? @js(__('programs.plans_load_one')) : @js(__('programs.plans_load_row')).replace(':count', Math.min(cols, remaining))">{{ __('programs.plans_load_one') }}</span>
                            </button>
                            <button type="button" class="dk-prog-more" x-show="rows > 1" @click="rows = 1">{{ __('programs.attention_show_less') }}</button>
                        </div>
                    </div>
                @endif
            @endif
        @endif
</section>
