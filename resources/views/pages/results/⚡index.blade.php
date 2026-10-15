<?php

use App\Enums\ResultType;
use App\Livewire\Concerns\Results\BuildsResultsFramework;
use App\Livewire\Concerns\Results\BuildsResultsTree;
use App\Livewire\Concerns\Results\ManagesResultForm;
use App\Livewire\Concerns\Results\SummarisesResults;
use App\Models\Proposal;
use App\Models\Result;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('titles.results_framework')] class extends Component {
    use BuildsResultsFramework;
    use BuildsResultsTree;
    use ManagesResultForm;
    use SummarisesResults;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $program = '';

    /** '' every framework, 'none' the program framework only, an id one proposal's framework. */
    #[Url(except: '')]
    public string $proposal = '';

    #[Url(except: '')]
    public string $type = '';

    /**
     * Narrows the framework to the statements that need attention.
     *
     * '' shows everything, 'unmeasured' only statements carrying no indicator,
     * 'offtrack' only statements whose indicators are rating amber or red.
     */
    #[Url(except: '')]
    public string $focus = '';

    /** 'cascade' draws the chain, 'matrix' lists every statement in one grid. */
    #[Url(except: 'cascade')]
    public string $view = 'cascade';

    public function clearFilters(): void
    {
        $this->search = '';
        $this->proposal = '';
        $this->program = '';
        $this->type = '';
        $this->focus = '';
    }

    /**
     * Proposals that own a framework, read from the loaded results (no query).
     *
     * @return Collection<int, Proposal>
     */
    #[Computed]
    public function proposalOptions(): Collection
    {
        return $this->allResults
            ->map(fn (Result $result): ?Proposal => $result->proposal)
            ->filter()
            ->unique('id')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * Toggle one of the attention chips; clicking the active one clears it.
     */
    public function setFocus(string $focus): void
    {
        $this->focus = $this->focus === $focus ? '' : $focus;
    }

    /**
     * Toggle the level filter from a chain card; clicking the active one clears it.
     */
    public function setType(string $type): void
    {
        $this->type = $this->type === $type ? '' : $type;
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $proposal !== '' || $program !== '' || $type !== '' || $focus !== '';
    $overview = $this->overview;
    $programCount = $this->groupedRoots->count();

    $levelMarks = [
        ResultType::Impact->value => 'bg-secondary-dark ring-secondary-dark dark:bg-ink dark:ring-ink',
        ResultType::Outcome->value => 'bg-primary ring-primary',
        ResultType::Output->value => 'bg-board ring-secondary-light',
        ResultType::Activity->value => 'bg-transparent ring-secondary-medium dark:ring-ink-muted',
        ResultType::CrossCutting->value => 'bg-secondary-light ring-secondary-light dark:bg-ink-muted dark:ring-ink-muted',
    ];

    $coverageTone = match (true) {
        $overview['coverage'] === null => 'neutral',
        $overview['coverage'] >= 80 => 'good',
        $overview['coverage'] >= 50 => 'warn',
        default => 'critical',
    };

    $onTrackShare = $overview['rated'] === 0 ? null : ($overview['rag']['green'] / $overview['rated']) * 100;

    $focusCounts = $this->focusCounts;

    $focusChips = [
        [
            'key' => 'unmeasured',
            'label' => __('results_framework.needs_indicators'),
            'icon' => 'exclamation-triangle',
            'count' => $focusCounts['unmeasured'],
        ],
        [
            'key' => 'offtrack',
            'label' => __('results_framework.off_track'),
            'icon' => 'arrow-trending-down',
            'count' => $focusCounts['offtrack'],
        ],
    ];
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" aria-labelledby="results-framework-heading">
    <x-ui.page-header
        heading-id="results-framework-heading"
        :title="__('common.results_framework')"
        :count="$overview['statements']"
    >
        <x-slot:filters>
            <x-ui.input
                type="search"
                icon="magnifying-glass"
                :label="__('results_framework.search_the_framework')"
                :placeholder="__('results_framework.statement_or_indicator_name')"
                wire:model.live.debounce.300ms="search"
                clearable
            />

            <x-ui.header-filter-select :label="__('common.program')" wire:model.live="program" :value="$program">
                <option value="">{{ __('common.all_programs') }}</option>
                <option value="none">{{ __('common.unassigned') }}</option>
                @foreach ($this->programOptions as $programOption)
                    <option value="{{ $programOption->id }}">{{ $programOption->name }}</option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('results_framework.framework')" wire:model.live="proposal" :value="$proposal">
                <option value="">{{ __('results_framework.all_frameworks') }}</option>
                <option value="none">{{ __('results_framework.program_framework_only') }}</option>
                @foreach ($this->proposalOptions as $proposalOption)
                    <option value="{{ $proposalOption->id }}">{{ $proposalOption->name }}</option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('common.type')" wire:model.live="type" :value="$type">
                <option value="">{{ __('results_framework.all_types') }}</option>
                @foreach (ResultType::cases() as $typeCase)
                    <option value="{{ $typeCase->value }}">{{ $typeCase->label() }}</option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            @can('results.create')<x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-result-button">
                {{ __('results_framework.add_result') }}
            </x-ui.button>@endcan
        </x-slot:actions>
    </x-ui.page-header>

    {{-- The framework at a glance: four figures, then the chain they describe. --}}
    @include('pages.results._index-overview')

    {{-- Attention chips on one side, the way of reading the framework on the other. --}}
    <div class="flex flex-wrap items-center gap-2">
        @foreach ($focusChips as $chip)
            <button
                type="button"
                wire:click="setFocus('{{ $chip['key'] }}')"
                aria-pressed="{{ $focus === $chip['key'] ? 'true' : 'false' }}"
                data-test="focus-chip-{{ $chip['key'] }}"
                @class([
                    'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 type-meta font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                    'border-primary bg-primary dark:bg-ink dark:text-surface-0 dark:border-ink text-white' => $focus === $chip['key'],
                    'border-secondary-soft bg-white text-secondary-medium hover:border-secondary-light hover:text-secondary-dark dark:border-line dark:bg-surface-1 dark:text-ink-muted dark:hover:text-ink' => $focus !== $chip['key'],
                ])
            >
                <flux:icon :name="$chip['icon']" variant="micro" class="size-3.5" aria-hidden="true" />
                {{ $chip['label'] }}
                <span @class([
                    'inline-flex min-w-5 items-center justify-center rounded-full px-1 text-[11px] font-bold tabular-nums',
                    'bg-white/20' => $focus === $chip['key'],
                    'bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink' => $focus !== $chip['key'],
                ])>{{ $chip['count'] }}</span>
            </button>
        @endforeach

        @if ($filtersAreActive)
            <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-ink-muted!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
        @endif

        <x-ui.view-toggle
            class="ms-auto"
            wire-model="view"
            :active="$view"
            :label="__('results_framework.how_to_read_the_framework')"
            :options="[
                ['key' => 'cascade', 'label' => __('results_framework.cascade'), 'icon' => 'share'],
                ['key' => 'matrix', 'label' => __('results_framework.matrix'), 'icon' => 'table-cells'],
            ]"
        />
    </div>

    @if ($this->groupedRoots->isEmpty())
        <x-ui.panel>
            <x-ui.empty-state
                icon="chart-bar"
                :heading="$filtersAreActive ? __('results_framework.no_matching_results') : __('common.no_results_yet')"
                :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('results_framework.add_the_first_impact_statement_to_start_the_logframe')"
            >
                <x-slot:action>
                    @if ($filtersAreActive || auth()->user()->can('results.create'))<x-ui.button tone="primary" :icon="$filtersAreActive ? 'arrow-path' : 'plus'" wire:click="{{ $filtersAreActive ? 'clearFilters' : 'openCreateModal' }}">
                        {{ $filtersAreActive ? __('common.reset_filters') : __('results_framework.add_result') }}
                    </x-ui.button>@endif
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.panel>
    @elseif ($view === 'matrix')
        @include('pages.results._index-matrix')
    @else
        @include('pages.results._index-cascade')
    @endif

    @include('pages.results._index-result-modal')

    <x-ui.confirm-modal
        wire:model="showDeleteModal"
        close="cancelDelete"
        :title="__('results_framework.delete_this_result')"
        :message="__('results_framework.only_a_result_with_no_nested_results_and_no_indicators_can_b')"
        confirm="deleteResult"
        :confirm-label="__('results_framework.delete_result')"
        confirm-test="confirm-delete-result"
    />
</section>
