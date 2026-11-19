{{--
    The logical framework box (components/proposals/⚡logframe): task bar,
    tools and the Results chain or Matrix. Sticky top, scrolling body.
--}}
@php
    $fw = $this->framework;
    $visible = $this->visibleIds;
    $canEdit = $this->canEdit;
    $canEditTargets = $this->canEditCentreTargets;
    $canCreate = $canEdit && auth()->user()->can('results.create');
    $task = $this->logframeTask;
    $checks = $this->checks;
    $readyCount = count(array_filter($checks, fn (array $check): bool => $check['ok']));
    $looseIds = array_values(array_filter(array_keys($fw['activities']), fn (int $id): bool => $fw['activities'][$id]['parent'] === null || ! isset($fw['results'][$fw['activities'][$id]['parent']])));
    $problemCount = count(array_filter($fw['results'], fn (array $result): bool => $result['issues'] !== []));
    $layerWarn = [
        'activities' => collect($fw['activities'])->contains('status', 'late'),
        'risks' => collect($fw['results'])->contains(fn (array $result): bool => collect($result['risks'])->contains('happened', true)),
        'assumptions' => collect($fw['results'])->contains(fn (array $result): bool => collect($result['assumptions'])->contains(fn (array $a): bool => $a['status'] !== \App\Enums\AssumptionStatus::Holds)),
    ];
    $icon = [
        'tree' => '<rect x="9" y="2" width="6" height="5" rx="1"/><rect x="2" y="17" width="6" height="5" rx="1"/><rect x="16" y="17" width="6" height="5" rx="1"/><path d="M12 7v5M5 17v-3h14v3"/>',
        'table' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M9 10v10"/>',
        'task' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        'sliders' => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
    ];
    $svg = fn (string $name, int $size = 14): string => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$icon[$name].'</svg>';
@endphp

<section @class(['pp-lf', 'pp-compact' => $density === 'compact', 'pp-tall' => $tall]) id="lf" aria-labelledby="lf-h" data-test="proposal-logframe" data-tour="proposal-logframe">
    <div class="pp-lf-top">
        @if ($task !== null)
            @include('components.proposals._framework-task')
        @endif

        <div class="pp-lf-title">
            <div>
                <h2 id="lf-h">{{ __('proposals.framework.title') }}
                    @if ($this->isMultiProgram)
                        <span class="pp-mini" data-test="logframe-multi-program">{{ __('results_framework.multi_program') }}</span>
                    @endif
                </h2>
                <p>{{ __('proposals.framework.intro') }}</p>
                @if (! $canEdit && \App\Support\ProposalFramework::isStructureLocked($this->proposal))
                    <p class="pp-small" data-test="logframe-locked">{{ __('results_framework.locked_read_only') }}</p>
                @endif
            </div>
            <div class="pp-lf-tools">
                @if ($canCreate)
                    <button type="button" class="pp-btn pp-btn-sm" wire:click="openPicker" data-test="start-from-program-framework">{!! $svg('tree') !!} {{ __('proposals.framework.start_from_program') }}</button>
                @endif
                <span class="pp-switch" role="group" aria-label="{{ __('proposals.framework.layout') }}">
                    <button type="button" @class(['pp-on' => $layout === 'chain']) wire:click="setLayout('chain')" aria-pressed="{{ $layout === 'chain' ? 'true' : 'false' }}" data-test="layout-chain">{!! $svg('tree') !!} {{ __('proposals.framework.layout_chain') }}</button>
                    <button type="button" @class(['pp-on' => $layout === 'matrix']) wire:click="setLayout('matrix')" aria-pressed="{{ $layout === 'matrix' ? 'true' : 'false' }}" data-test="layout-matrix">{!! $svg('table') !!} {{ __('proposals.framework.layout_matrix') }}</button>
                    <button type="button" @class(['pp-on' => $layout === 'board']) wire:click="setLayout('board')" aria-pressed="{{ $layout === 'board' ? 'true' : 'false' }}" data-test="layout-board">{!! $svg('task') !!} {{ __('proposals.framework.layout_board') }}</button>
                </span>
                <span class="pp-rel" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
                    <button type="button" class="pp-btn pp-btn-sm" x-on:click="open = ! open" x-bind:aria-expanded="open" data-test="customize">{!! $svg('sliders') !!} {{ __('proposals.framework.customize') }}</button>
                    <div class="pp-pop" role="dialog" aria-label="{{ __('proposals.framework.customize') }}" x-show="open" x-cloak>
                        <h4>{{ __('proposals.framework.show_under_each') }}</h4>
                        @foreach ($this::LAYERS as $layer)
                            <label><input type="checkbox" @checked($layers[$layer] ?? false) wire:click="toggleLayer('{{ $layer }}')"> {{ __('proposals.framework.layer_'.$layer) }}</label>
                        @endforeach
                        <h4>{{ __('proposals.framework.spacing') }}</h4>
                        <label><input type="radio" name="pp-density" @checked($density !== 'compact') wire:click="setDensity('comfortable')"> {{ __('proposals.framework.comfortable') }}</label>
                        <label><input type="radio" name="pp-density" @checked($density === 'compact') wire:click="setDensity('compact')"> {{ __('proposals.framework.compact') }}</label>
                        <h4>{{ __('proposals.framework.box_height') }}</h4>
                        <label><input type="radio" name="pp-tall" @checked(! $tall) wire:click="setTall(false)"> {{ __('proposals.framework.fixed_box') }}</label>
                        <label><input type="radio" name="pp-tall" @checked($tall) wire:click="setTall(true)"> {{ __('proposals.framework.full_length') }}</label>
                        <h4>{{ __('proposals.framework.layout') }}</h4>
                        <label><input type="radio" name="pp-layout" @checked($layout === 'chain') wire:click="setLayout('chain')"> {{ __('proposals.framework.layout_chain_long') }}</label>
                        <label><input type="radio" name="pp-layout" @checked($layout === 'matrix') wire:click="setLayout('matrix')"> {{ __('proposals.framework.layout_matrix_long') }}</label>
                        <div class="pp-pop-foot">
                            <button type="button" class="pp-link" wire:click="resetPrefs">{{ __('proposals.framework.reset_default') }}</button>
                            <button type="button" class="pp-btn pp-btn-sm" x-on:click="open = false">{{ __('proposals.framework.close_customize') }}</button>
                        </div>
                        <span class="pp-small">{{ __('proposals.framework.remembered') }}</span>
                    </div>
                </span>
            </div>
        </div>

        @if ($layout !== 'board')
        <div class="pp-layers" role="group" aria-label="{{ __('proposals.framework.show_under_each') }}">
            <span class="pp-small">{{ __('proposals.framework.show') }}</span>
            @foreach ($this::LAYERS as $layer)
                <button type="button" @class(['pp-layer', 'pp-on' => $layers[$layer] ?? false, 'pp-warn' => $layerWarn[$layer] ?? false]) wire:click="toggleLayer('{{ $layer }}')" aria-pressed="{{ ($layers[$layer] ?? false) ? 'true' : 'false' }}" data-test="layer-{{ $layer }}">
                    <span class="pp-box">@if ($layers[$layer] ?? false){!! $svg('check', 11) !!}@endif</span>{{ __('proposals.framework.layer_'.$layer) }} <span class="pp-c">{{ $fw['counts'][$layer] }}</span>
                </button>
            @endforeach
        </div>
        @endif

        <div class="pp-lf-bar">
            <span class="pp-lf-bar-l">
                <input class="pp-search" type="search" wire:model.live.debounce.250ms="search" placeholder="{{ __('proposals.framework.search') }}" aria-label="{{ __('proposals.framework.search') }}" data-test="framework-search">
                @if ($layout !== 'board')
                <label class="pp-toggle"><input type="checkbox" wire:model.live="problemsOnly"> {{ __('proposals.framework.only_problems', ['count' => $problemCount]) }}</label>
                @endif
            </span>
            <span class="pp-lf-bar-r">
                @if ($layout === 'chain')
                    <button type="button" class="pp-btn pp-btn-sm" wire:click="openAll" data-test="open-all">{{ __('proposals.framework.open_all') }}</button>
                    <button type="button" class="pp-btn pp-btn-sm" wire:click="closeAll" data-test="close-all">{{ __('proposals.framework.close_all') }}</button>
                @endif
                @if ($canCreate)
                    <button type="button" class="pp-btn pp-btn-sm pp-btn-primary" wire:click="openCreateModal" data-test="create-logframe-result">{!! $svg('plus') !!} {{ __('proposals.framework.add_result') }}</button>
                @endif
            </span>
        </div>
    </div>

    <div class="pp-lf-body" data-test="framework-body">
        @if ($looseIds !== [] && $layout !== 'board')
            <div class="pp-lbanner" data-test="loose-banner">
                {!! $svg('task', 16) !!}
                <span><b>{{ trans_choice('proposals.framework.loose_banner', count($looseIds), ['count' => count($looseIds)]) }}</b> {{ __('proposals.framework.loose_banner_help') }}</span>
                <button type="button" class="pp-btn pp-btn-sm pp-btn-primary" wire:click="setLayout('board')" data-test="open-board">{{ __('proposals.framework.open_board') }}</button>
            </div>
        @endif

        @if ($layout === 'board')
            @include('components.proposals._framework-board')
        @elseif ($fw['results'] === [])
            <div class="pp-empty">{{ __('proposals.framework.no_results') }}</div>
        @elseif ($visible === [])
            <div class="pp-empty">{{ $search !== '' ? __('proposals.framework.nothing_matches', ['search' => $search]) : __('proposals.framework.no_problems') }}</div>
        @elseif ($layout === 'matrix')
            @include('components.proposals._framework-matrix')
        @else
            <div class="pp-tree">
                @foreach ($fw['roots'] as $rootId)
                    @include('components.proposals._framework-node', ['resultId' => $rootId])
                @endforeach
            </div>
        @endif
    </div>

    @include('components.proposals._framework-modals')

    @if ($canCreate)
        @include('components.proposals._framework-picker')
    @endif
</section>
