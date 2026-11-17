@use('App\Enums\ResultType')
@php
    /*
     * The program's Results tab: title, search and filters on top, stat cards
     * that filter the indicators, a level switch (the whole chain as a tree,
     * or one level as a flat list), then one card per result that folds open
     * to list its indicators a page at a time.
     */
    $chain = $this->chain;
    $summary = $this->resultSummary;
    $board = $this->resultsBoard;
    $counts = $board['counts'];
    $options = $chain['options'];
    $filters = $chain['filters'];
    $gapCounts = array_filter($chain['gaps']);
    $hasCenterFilter = $options['centers'] !== [];
    $hasDonorFilter = count($options['donors']) > 1;
    $chainFiltered = $filters['center'] !== '' || $filters['donor'] !== '' || $filters['indicator'] !== '';

    // The shared result-level marks (.ai/rules/components-ui.md).
    $levelMarks = [
        ResultType::Impact->value => 'bg-secondary-dark ring-secondary-dark dark:bg-ink dark:ring-ink',
        ResultType::Outcome->value => 'bg-primary ring-primary',
        ResultType::Output->value => 'bg-board ring-secondary-light',
        ResultType::Activity->value => 'bg-transparent ring-secondary-medium dark:ring-ink-muted',
        ResultType::CrossCutting->value => 'bg-secondary-light ring-secondary-light dark:bg-ink-muted dark:ring-ink-muted',
    ];
    $stateTones = ['achieved' => 'dk-good', 'in_progress' => 'dk-active', 'not_started' => '', 'no_target' => 'dk-warn'];
    $filterCards = [
        ['key' => 'all', 'tone' => null],
        ['key' => 'achieved', 'tone' => null],
        ['key' => 'in_progress', 'tone' => null],
        ['key' => 'not_started', 'tone' => null],
        ['key' => 'no_target', 'tone' => $counts['no_target'] > 0 ? 'warn' : null],
    ];
    $canOpenTargets = auth()->user()?->can('targets.view') === true;
    $busyTargets = 'pickResultFilter, pickResultLevel, resultSearch, chainCenter, chainDonor, resetChainFilters';
@endphp

<section class="dk-ptk dk-prs" data-test="program-results" data-chain-view>

    {{-- 1. Title, search and the way to the full framework. --}}
    <header class="dk-ptk-h">
        <h2 id="program-results-heading">{{ __('programs.results') }} <span class="dk-num">{{ $program->results_count }}</span></h2>
        @unless ($chain['isEmpty'])
            <label class="dk-prog-search">
                <span class="sr-only">{{ __('programs.results_find') }}</span>
                <x-desk.icon name="search" />
                <input type="search" wire:model.live.debounce.300ms="resultSearch" placeholder="{{ __('programs.results_find') }}" data-test="program-result-search" />
            </label>
        @endunless
        @can('results.view')
            <a href="{{ route('results.index') }}" wire:navigate class="dk-ptk-pane-link" data-test="program-results-framework">{{ __('programs.results_open_framework') }}<x-desk.icon name="arrow" class="dk-flip" /></a>
        @endcan
    </header>

    @if ($chain['isEmpty'])
        <x-ui.empty-state icon="share" :heading="__('results_framework.chain_empty_heading')" :body="auth()->user()?->can('results.create') ? __('results_framework.chain_empty_body_editor') : __('results_framework.chain_empty_body_viewer')">
            <x-slot:action>
                @can('results.create')
                    <x-ui.button tone="primary" icon="plus" :href="route('results.index')" wire:navigate>{{ __('results_framework.chain_start') }}</x-ui.button>
                @endcan
            </x-slot:action>
        </x-ui.empty-state>
    @else
        {{-- 2. Stat cards that are also the indicator filters; clicking the active one goes back to all. --}}
        <div @class(['dk-ptk-cards', 'dk-prog-chips-off' => $board['searching']]) role="group" aria-label="{{ __('programs.results_filters') }}">
            @foreach ($filterCards as $card)
                <button type="button" wire:key="result-filter-{{ $card['key'] }}" wire:click="pickResultFilter('{{ $card['key'] }}')"
                    aria-pressed="{{ $this->resultFilter === $card['key'] ? 'true' : 'false' }}"
                    @class(['dk-ptk-card', 'dk-warn' => $card['tone'] === 'warn']) data-result-filter="{{ $card['key'] }}">
                    <span class="dk-ptk-card-k">{{ __('programs.results_filter_'.$card['key']) }}</span>
                    <span class="dk-ptk-card-n dk-num">
                        {{ $counts[$card['key']] }}@if ($card['key'] === 'achieved' && $counts['all'] > 0)<small>/{{ $counts['all'] }}</small>@endif
                    </span>
                </button>
            @endforeach
        </div>

        {{-- 3. What needs fixing in the framework, in one line. --}}
        <p class="dk-prs-gaps" data-chain-gaps>
            <span><x-desk.icon name="chart" />{{ trans_choice('programs.logframe_count_indicators', $summary['indicators'], ['count' => $summary['indicators']]) }}</span>
            <span @class(['dk-warn' => $summary['unmeasured'] > 0, 'dk-good' => $summary['unmeasured'] === 0])>
                <x-desk.icon :name="$summary['unmeasured'] > 0 ? 'info' : 'check'" />{{ trans_choice('programs.logframe_count_unmeasured', $summary['unmeasured'], ['count' => $summary['unmeasured']]) }}
            </span>
            @if ($board['notLinked'] > 0)
                <span data-result-not-linked><x-desk.icon name="info" />{{ trans_choice('programs.results_not_linked', $board['notLinked'], ['count' => $board['notLinked']]) }}</span>
            @endif
        </p>

        {{-- 4. Level switch, chain filters and fold-all. --}}
        <div class="dk-ptk-bar dk-prs-bar">
            <div class="dk-ptk-seg" role="group" aria-label="{{ __('programs.results_levels') }}">
                <button type="button" wire:click="pickResultLevel('')" aria-pressed="{{ $this->resultLevel === '' ? 'true' : 'false' }}" data-result-level="">{{ __('programs.results_all_levels') }}</button>
                @foreach (ResultType::cases() as $type)
                    @if ($board['levels'][$type->value] > 0)
                        <button type="button" wire:key="result-level-{{ $type->value }}" wire:click="pickResultLevel('{{ $type->value }}')" aria-pressed="{{ $this->resultLevel === $type->value ? 'true' : 'false' }}" data-result-level="{{ $type->value }}">
                            <span class="dk-prs-dot ring-2 ring-inset {{ $levelMarks[$type->value] }}" aria-hidden="true"></span>{{ $type->label() }} <span class="dk-num">{{ $board['levels'][$type->value] }}</span>
                        </button>
                    @endif
                @endforeach
            </div>

            <div class="dk-prs-tools">
                @if ($hasCenterFilter)
                    <div class="dk-prs-filter">
                        <x-ui.header-filter-select :label="__('results_framework.chain_filter_center')" wire:model.live="chainCenter" :value="$filters['center']">
                            <option value="">{{ __('results_framework.chain_all_centers') }}</option>
                            @foreach ($options['centers'] as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </x-ui.header-filter-select>
                    </div>
                @endif
                @if ($hasDonorFilter)
                    <div class="dk-prs-filter">
                        <x-ui.header-filter-select :label="__('results_framework.chain_filter_donor')" wire:model.live="chainDonor" :value="$filters['donor']">
                            <option value="">{{ __('results_framework.chain_all_donors') }}</option>
                            @foreach ($options['donors'] as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </x-ui.header-filter-select>
                    </div>
                @endif
                @if ($chainFiltered)
                    <button type="button" class="dk-ptk-pane-link" wire:click="resetChainFilters">{{ __('results_framework.chain_clear_filters') }}</button>
                @endif
                @if ($board['cards'] !== [])
                    <button type="button" class="dk-ptk-pane-link" x-on:click="$dispatch('results-fold', true)" data-test="results-expand-all">{{ __('programs.results_expand_all') }}</button>
                    <button type="button" class="dk-ptk-pane-link" x-on:click="$dispatch('results-fold', false)" data-test="results-collapse-all">{{ __('programs.results_collapse_all') }}</button>
                @endif
            </div>
        </div>

        {{-- 5. The result cards: a tree, or one level as a flat list. --}}
        <div class="dk-prs-list" wire:loading.class="dk-ptk-busy" wire:target="{{ $busyTargets }}" data-chain-tree>
            @forelse ($board['cards'] as $card)
                @include('pages.programs._result-card', ['card' => $card, 'rootIndex' => $loop->index])
            @empty
                <p class="dk-prog-clear dk-ppl-note dk-ppl-nothing">{{ $board['narrowed'] || $chainFiltered ? __('programs.results_none_match') : __('results_framework.chain_no_match') }}</p>
            @endforelse
        </div>
    @endif
</section>
