@php
    $writable = ! $this->proposal->status->isTerminal();
    $canEdit = $writable && $this->canEdit;
    $canCreate = $canEdit && auth()->user()->can('results.create');
    $task = $this->logframeTask;
    $checks = $task !== null || $showDoneModal ? $this->checks : [];
    $readyCount = count(array_filter($checks, fn (array $check): bool => $check['ok']));
    $svg = fn (string $icon, int $size): string => match ($icon) {
        'check' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 12l5 5L20 6"/></svg>',
        'task' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 11l3 3L22 4m-1 8v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
    };
@endphp

<section class="pp-lf" id="lf" aria-labelledby="lf-h" data-test="proposal-logframe" data-tour="proposal-logframe">
    <div class="pp-lf-top">
        @if ($task !== null)
            @include('components.proposals._framework-task')
        @endif

        <div class="pp-lf-title">
            <h2 id="lf-h">{{ __('proposals.framework.title') }}
                @if ($this->isMultiProgram)
                    <span class="pp-mini" data-test="logframe-multi-program">{{ __('results_framework.multi_program') }}</span>
                @endif
            </h2>
            @if (! $canEdit && \App\Support\ProposalFramework::isStructureLocked($this->proposal))
                <p class="pp-small" data-test="logframe-locked">{{ __('results_framework.locked_read_only') }}</p>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2 p-3" data-test="framework-toolbar">
            <input class="pp-search" type="search" wire:model.live.debounce.250ms="search"
                placeholder="{{ __('proposals.framework.search') }}" aria-label="{{ __('proposals.framework.search') }}"
                data-test="framework-search">
            @if ($canCreate)
                <x-ui.button type="button" tone="primary" wire:click="openCreateModal" data-test="create-logframe-result">{{ __('proposals.framework.add_result') }}</x-ui.button>
                <x-ui.button type="button" tone="ghost" wire:click="openPicker" data-test="start-from-program-framework">{{ __('proposals.framework.start_from_program') }}</x-ui.button>
            @endif
        </div>
    </div>

    <div class="pp-lf-body" data-test="framework-body">
        @include('components.proposals._framework-table')
    </div>

    @include('components.proposals._framework-modals')

    @if ($canCreate)
        @include('components.proposals._framework-picker')
    @endif
</section>
