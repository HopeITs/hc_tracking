{{--
    The read-only results chain (T16), built by App\Support\ResultsChain:
    impact → outcome → output → activity, cross-cutting roots below, each node
    with its indicators and per-center targets, gap markers and the chain
    filters. Renders no queries. Hosts: programs/show, annual-plans/show and
    the proposal Logical Framework tab (chain view). The host passes a `start`
    slot (Start Logical Framework) only to results.create holders.
--}}
@props([
    'chain',
    'summary' => null,
    'heading' => null,
    'subheading' => null,
    'headingId' => 'program-results-heading',
    'filterable' => true,
])

@php
    use App\Enums\ResultType;

    $summary ??= $chain['summary'];
    $heading ??= __('programs.results_logframe');
    $subheading ??= __('results_framework.chain_subheading');
    $levelMarks = [
        ResultType::Impact->value => 'bg-secondary-dark ring-secondary-dark dark:bg-ink dark:ring-ink',
        ResultType::Outcome->value => 'bg-primary ring-primary',
        ResultType::Output->value => 'bg-board ring-secondary-light',
        ResultType::Activity->value => 'bg-transparent ring-secondary-medium dark:ring-ink-muted',
        ResultType::CrossCutting->value => 'bg-secondary-light ring-secondary-light dark:bg-ink-muted dark:ring-ink-muted',
    ];
    $options = $chain['options'];
    $filters = $chain['filters'];
    $isFiltered = $filters['indicator'] !== '' || $filters['center'] !== '' || $filters['donor'] !== '';
    $showFilters = $filterable && ! $chain['isEmpty'] && ($options['indicators'] !== [] || $options['centers'] !== [] || count($options['donors']) > 1);
    $gapCounts = array_filter($chain['gaps']);
@endphp

<section {{ $attributes->class('overflow-hidden rounded-md border border-board-line bg-board print:overflow-visible print:border-0') }} aria-labelledby="{{ $headingId }}" data-chain-view>
    <header class="flex flex-col gap-4 border-b border-board-line bg-board-head px-5 py-5 print:bg-transparent">
        <div class="flex flex-col gap-4 @4xl/detail:flex-row @4xl/detail:items-end @4xl/detail:justify-between">
            <div class="max-w-xl">
                <h2 id="{{ $headingId }}" class="text-xl font-bold text-balance">{{ $heading }}</h2>
                <p class="mt-1 text-sm text-secondary-medium dark:text-ink-muted">{{ $subheading }}</p>
            </div>

            <div class="flex flex-col gap-2 @4xl/detail:items-end">
                <ol class="flex flex-wrap items-center gap-x-2 gap-y-2" aria-label="{{ __('programs.logframe_results_chain') }}">
                    @foreach (ResultType::cases() as $type)
                        <li class="flex items-center gap-2">
                            <span class="flex items-center gap-2 rounded-full border border-board-line bg-board py-1 ps-2 pe-3">
                                <span aria-hidden="true" class="size-2.5 rounded-full ring-2 ring-inset {{ $levelMarks[$type->value] }}"></span>
                                <span class="text-sm font-semibold">{{ $type->label() }}</span>
                                <span class="text-sm font-bold tabular-nums text-secondary-medium dark:text-ink-muted">{{ $summary['levels'][$type->value] }}</span>
                            </span>
                            @unless ($loop->last)
                                <flux:icon.arrow-long-right variant="micro" class="size-4 text-secondary-light rtl:rotate-180" aria-hidden="true" />
                            @endunless
                        </li>
                    @endforeach
                </ol>
                @unless ($chain['isEmpty'])
                    <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs font-medium text-secondary-medium dark:text-ink-muted" data-chain-gaps>
                        <span class="inline-flex items-center gap-1"><flux:icon.chart-bar variant="micro" class="size-3.5" aria-hidden="true" />{{ trans_choice('programs.logframe_count_indicators', $summary['indicators'], ['count' => $summary['indicators']]) }}</span>
                        <span @class(['inline-flex items-center gap-1', 'text-status-warn' => $summary['unmeasured'] > 0, 'text-status-good' => $summary['unmeasured'] === 0])>
                            <flux:icon :name="$summary['unmeasured'] > 0 ? 'exclamation-triangle' : 'check-circle'" variant="micro" class="size-3.5" aria-hidden="true" />{{ trans_choice('programs.logframe_count_unmeasured', $summary['unmeasured'], ['count' => $summary['unmeasured']]) }}
                        </span>
                        @foreach (['no_target' => 'results_framework.chain_gap_count_no_target'] as $gap => $countKey)
                            @isset($gapCounts[$gap])
                                <span class="inline-flex items-center gap-1 text-status-warn" data-chain-gap-count="{{ $gap }}">
                                    <flux:icon.exclamation-triangle variant="micro" class="size-3.5" aria-hidden="true" />{{ trans_choice($countKey, $gapCounts[$gap], ['count' => $gapCounts[$gap]]) }}
                                </span>
                            @endisset
                        @endforeach
                    </p>
                @endunless
            </div>
        </div>

        @if ($showFilters)
            <div class="flex flex-wrap items-end gap-3 print:hidden" data-chain-filters>
                @if ($options['indicators'] !== [])
                    <div class="w-full min-w-0 sm:w-64">
                        <x-ui.header-filter-select :label="__('results_framework.chain_filter_indicator')" wire:model.live="chainIndicator" :value="$filters['indicator']">
                            <option value="">{{ __('results_framework.chain_all_indicators') }}</option>
                            @foreach ($options['indicators'] as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </x-ui.header-filter-select>
                    </div>
                @endif
                @if ($options['centers'] !== [])
                    <div class="w-full min-w-0 sm:w-56">
                        <x-ui.header-filter-select :label="__('results_framework.chain_filter_center')" wire:model.live="chainCenter" :value="$filters['center']">
                            <option value="">{{ __('results_framework.chain_all_centers') }}</option>
                            @foreach ($options['centers'] as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </x-ui.header-filter-select>
                    </div>
                @endif
                @if (count($options['donors']) > 1)
                    <div class="w-full min-w-0 sm:w-56">
                        <x-ui.header-filter-select :label="__('results_framework.chain_filter_donor')" wire:model.live="chainDonor" :value="$filters['donor']">
                            <option value="">{{ __('results_framework.chain_all_donors') }}</option>
                            @foreach ($options['donors'] as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </x-ui.header-filter-select>
                    </div>
                @endif
                @if ($isFiltered)
                    <x-ui.button tone="ghost" size="sm" icon="x-mark" wire:click="resetChainFilters" wire:loading.attr="disabled" wire:target="resetChainFilters">{{ __('results_framework.chain_clear_filters') }}</x-ui.button>
                @endif
            </div>
        @endif
    </header>

    @if ($chain['isEmpty'])
        <x-ui.empty-state icon="share" :heading="__('results_framework.chain_empty_heading')" :body="isset($start) ? __('results_framework.chain_empty_body_editor') : __('results_framework.chain_empty_body_viewer')">
            <x-slot:action>{{ $start ?? '' }}</x-slot:action>
        </x-ui.empty-state>
    @else
        <div class="space-y-8 bg-ground px-4 py-6 @3xl/detail:px-8 print:bg-transparent print:px-0" wire:loading.class="opacity-60" wire:target="chainIndicator, chainCenter, chainDonor, resetChainFilters">
            @if ($chain['tree'] === [] && $chain['crossCutting'] === [])
                <p class="rounded-md border border-dashed border-secondary-light px-4 py-3 text-center text-sm text-secondary-medium dark:border-line dark:text-ink-muted">{{ __('results_framework.chain_no_match') }}</p>
            @endif

            @if ($chain['tree'] !== [])
                <ul class="space-y-5" data-chain-tree>
                    @foreach ($chain['tree'] as $node)
                        <li wire:key="chain-root-{{ $node['result']->id }}">
                            <x-ui.logframe-node :node="$node" :depth="0" />
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($chain['crossCutting'] !== [])
                <section class="space-y-3" aria-labelledby="{{ $headingId }}-cross-cutting" data-chain-cross-cutting>
                    <h3 id="{{ $headingId }}-cross-cutting" class="type-label text-secondary-medium dark:text-ink-muted">{{ __('results_framework.cross_cutting_group') }}</h3>
                    <ul class="space-y-3">
                        @foreach ($chain['crossCutting'] as $node)
                            <li wire:key="chain-cross-cutting-{{ $node['result']->id }}">
                                <x-ui.logframe-node :node="$node" :depth="0" />
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    @endif
</section>
