<?php

use App\Enums\IndicatorMeasure;
use App\Livewire\Concerns\Indicators\BuildsIndicatorRows;
use App\Livewire\Concerns\Indicators\InspectsIndicatorChain;
use App\Livewire\Concerns\Indicators\ManagesIndicatorForm;
use App\Livewire\Concerns\Indicators\ManagesIndicatorReadings;
use App\Livewire\Concerns\Indicators\PresentsIndicators;
use App\Livewire\Concerns\Indicators\SummarisesIndicators;
use App\Models\Indicator;
use App\Models\Result;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::desk'), Title('titles.indicators')] class extends Component {
    use BuildsIndicatorRows;
    use InspectsIndicatorChain;
    use ManagesIndicatorForm;
    use ManagesIndicatorReadings;
    use PresentsIndicators;
    use SummarisesIndicators;
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $result = '';

    #[Url(except: '')]
    public string $measure = '';

    #[Url(except: '')]
    public string $program = '';

    /** One of the RagStatus values, or '' for every rating including unrated. */
    #[Url(except: '')]
    public string $rag = '';

    /**
     * Narrows the page to the indicators that need a hand.
     *
     * '' shows everything, 'offtrack' the amber and red ones, 'unmeasured'
     * those carrying no reading at all, 'stale' those whose last reading is
     * older than their own frequency, 'untargeted' those with no target to be
     * measured against.
     */
    #[Url(except: '')]
    public string $focus = '';

    /** 'cards' draws the trajectory cards, 'table' lists every figure in one grid. */
    #[Url(except: 'cards')]
    public string $view = 'cards';

    /**
     * Deep links from the rest of the chain.
     *
     * `?create=<result id>` opens the indicator form already tied to that
     * statement (the results framework's "Add indicator"); `?inspect=<id>`
     * opens one indicator's chain. Read once here rather than bound with #[Url],
     * so closing the dialog and refreshing does not reopen it.
     */
    public function mount(): void
    {
        $createForResult = request()->integer('create');

        if ($createForResult > 0 && Gate::allows('indicators.create') && Result::query()->whereKey($createForResult)->exists()) {
            $this->openCreateModal();
            $this->indicatorResultId = (string) $createForResult;
        }

        $inspect = request()->integer('inspect');

        if ($inspect > 0 && Indicator::query()->whereKey($inspect)->exists()) {
            $this->openInspectModal($inspect);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedResult(): void
    {
        $this->resetPage();
    }

    public function updatedMeasure(): void
    {
        $this->resetPage();
    }

    public function updatedProgram(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->result = '';
        $this->measure = '';
        $this->program = '';
        $this->rag = '';
        $this->focus = '';
        $this->resetPage();
    }

    /**
     * Toggle one of the attention chips; clicking the active one clears it.
     */
    public function setFocus(string $focus): void
    {
        $this->focus = $this->focus === $focus ? '' : $focus;
        $this->resetPage();
    }

    /**
     * Toggle the rating filter from the distribution bar or its legend.
     */
    public function setRag(string $rag): void
    {
        $this->rag = $this->rag === $rag ? '' : $rag;
        $this->resetPage();
    }

    /**
     * Drop every cached read after a write, so the same request re-renders the
     * figures it just changed rather than the ones it read at the top.
     */
    protected function forgetIndicators(): void
    {
        foreach (['allIndicators', 'rows', 'filteredRows', 'pagedRows', 'overview', 'focusCounts', 'inspected', 'inspectedReadings'] as $property) {
            unset($this->{$property});
        }
    }
};
?>

@php
    use App\Enums\ResultType;

    $filtersAreActive = $search !== '' || $result !== '' || $measure !== '' || $program !== '' || $rag !== '' || $focus !== '';
    $overview = $this->overview;
    $paged = $this->pagedRows;
    $focusCounts = $this->focusCounts;

    // One fixed visual identity for Impact/Outcome/Output app-wide; see
    // .ai/rules/components-ui.md. Copied, never reinvented.
    $levelMarks = [
        ResultType::Impact->value => 'bg-secondary-dark ring-secondary-dark dark:bg-ink dark:ring-ink',
        ResultType::Outcome->value => 'bg-primary ring-primary',
        ResultType::Output->value => 'bg-board ring-secondary-light',
        ResultType::Activity->value => 'bg-transparent ring-secondary-medium dark:ring-ink-muted',
        ResultType::CrossCutting->value => 'bg-secondary-light ring-secondary-light dark:bg-ink-muted dark:ring-ink-muted',
    ];

    $levelAccents = [
        ResultType::Impact->value => 'bg-secondary-dark dark:bg-ink',
        ResultType::Outcome->value => 'bg-primary',
        ResultType::Output->value => 'bg-secondary-light',
        ResultType::Activity->value => 'bg-secondary-soft ring-1 ring-inset ring-secondary-medium dark:bg-surface-2 dark:ring-ink-muted',
        ResultType::CrossCutting->value => 'bg-secondary-medium dark:bg-ink-muted',
    ];

    $ragSegments = [
        ['key' => App\Enums\RagStatus::Green->value, 'label' => App\Enums\RagStatus::Green->label(), 'count' => $overview['rag']['green'], 'fill' => 'bg-status-good', 'dot' => 'bg-status-good'],
        ['key' => App\Enums\RagStatus::Amber->value, 'label' => App\Enums\RagStatus::Amber->label(), 'count' => $overview['rag']['amber'], 'fill' => 'bg-status-warn', 'dot' => 'bg-status-warn'],
        ['key' => App\Enums\RagStatus::Red->value, 'label' => App\Enums\RagStatus::Red->label(), 'count' => $overview['rag']['red'], 'fill' => 'bg-primary dark:bg-status-critical', 'dot' => 'bg-primary dark:bg-status-critical'],
        ['key' => 'unrated', 'label' => __('indicators.unrated'), 'count' => $overview['unrated'], 'fill' => 'bg-secondary-soft dark:bg-line-strong', 'dot' => 'bg-secondary-light'],
    ];

    $focusChips = [
        ['key' => 'offtrack', 'label' => __('indicators.off_track'), 'icon' => 'arrow-trending-down', 'count' => $focusCounts['offtrack']],
        ['key' => 'stale', 'label' => __('indicators.needs_a_reading'), 'icon' => 'clock', 'count' => $focusCounts['stale']],
        ['key' => 'reporting', 'label' => __('indicators.reporting_overdue'), 'icon' => 'document-text', 'count' => $focusCounts['reporting']],
        ['key' => 'unmeasured', 'label' => __('indicators.never_measured'), 'icon' => 'exclamation-triangle', 'count' => $focusCounts['unmeasured']],
        ['key' => 'untargeted', 'label' => __('indicators.no_target'), 'icon' => 'flag', 'count' => $focusCounts['untargeted']],
    ];

    $onTrackShare = $overview['rated'] === 0 ? null : ($overview['rag']['green'] / $overview['rated']) * 100;

    $achievementTone = match (true) {
        $overview['achieved'] === null => 'neutral',
        $overview['achieved'] >= $overview['elapsed'] * 0.9 => 'good',
        $overview['achieved'] >= $overview['elapsed'] * 0.7 => 'warn',
        default => 'critical',
    };
    $onTrackTone = match (true) {
        $onTrackShare === null => 'neutral',
        $onTrackShare >= 80 => 'good',
        $onTrackShare >= 50 => 'warn',
        default => 'critical',
    };
    $toneClass = fn (string $tone): string => match ($tone) {
        'good' => 'text-status-good',
        'warn' => 'text-status-warn',
        'critical' => 'text-primary dark:text-brand-text',
        default => '',
    };
    $headline = [
        ['label' => __('common.indicators'), 'value' => number_format($overview['indicators']), 'tone' => 'neutral'],
        ['label' => __('indicators.readings_logged'), 'value' => number_format($overview['readings']), 'tone' => 'neutral'],
        ['label' => __('indicators.average_achievement'), 'value' => $overview['achieved'] === null ? '—' : number_format($overview['achieved'], 0).'%', 'tone' => $achievementTone],
        ['label' => __('indicators.on_track'), 'value' => $onTrackShare === null ? '—' : number_format($onTrackShare, 0).'%', 'tone' => $onTrackTone],
    ];
@endphp

<x-desk.register class="@container/directory" heading-id="indicator-directory-heading" :title="__('common.indicators')" :count="$overview['indicators']">
    
        <x-slot:figures>
            @foreach ($headline as $cell)
                <span wire:key="indicator-headline-{{ $loop->index }}"><b class="dk-num {{ $toneClass($cell['tone']) }}">{{ $cell['value'] }}</b> {{ $cell['label'] }}</span>@unless ($loop->last) · @endunless
            @endforeach
        </x-slot:figures>
        <x-slot:search>
            <x-desk.register-search :label="__('indicators.search_indicators')" :placeholder="__('indicators.indicator_or_result_statement')" wire:model.live.debounce.300ms="search" />
        </x-slot:search>
        <x-slot:filters>
            <x-ui.header-filter-select :label="__('common.program')" wire:model.live="program" :value="$program">
                <option value="">{{ __('common.all_programs') }}</option>
                <option value="none">{{ __('common.unassigned') }}</option>
                @foreach ($this->programOptions as $programOption)
                    <option value="{{ $programOption->id }}">{{ $programOption->name }}</option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('indicators.result')" wire:model.live="result" :value="$result">
                <option value="">{{ __('indicators.all_results') }}</option>
                @foreach ($this->resultOptions as $resultOption)
                    <option value="{{ $resultOption->id }}">{{ Str::limit($resultOption->statement, 50) }}</option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('indicators.measure')" wire:model.live="measure" :value="$measure">
                <option value="">{{ __('indicators.all_measures') }}</option>
                @foreach (IndicatorMeasure::cases() as $measureCase)
                    <option value="{{ $measureCase->value }}">{{ $measureCase->label() }}</option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            @can('indicators.create')<x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-indicator-button">
                {{ __('indicators.add_indicator') }}
            </x-ui.button>@endcan
        </x-slot:actions>
    
    @include('pages.indicators._index-overview')

    @include('pages.indicators._index-focus-bar')

    @if ($paged->isEmpty())
        <x-ui.panel>
            <x-ui.empty-state
                icon="chart-bar"
                :heading="$filtersAreActive ? __('indicators.no_matching_indicators') : __('indicators.no_indicators_yet')"
                :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('indicators.add_the_first_indicator_against_a_result')"
            >
                <x-slot:action>
                    @if ($filtersAreActive || auth()->user()->can('indicators.create'))<x-ui.button tone="primary" :icon="$filtersAreActive ? 'arrow-path' : 'plus'" wire:click="{{ $filtersAreActive ? 'clearFilters' : 'openCreateModal' }}">
                        {{ $filtersAreActive ? __('common.reset_filters') : __('indicators.add_indicator') }}
                    </x-ui.button>@endif
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.panel>
    @elseif ($view === 'table')
        @include('pages.indicators._index-table')
    @else
        @include('pages.indicators._index-cards')
    @endif

    @include('pages.indicators._index-inspect-modal')

    @include('pages.indicators._index-form-modal')

    @include('pages.indicators._index-reading-modal')
</x-desk.register>
