<?php

use App\Enums\ResultType;
use App\Livewire\Concerns\Results\BuildsResultsFramework;
use App\Livewire\Concerns\Results\BuildsResultsTree;
use App\Livewire\Concerns\Results\ManagesResultForm;
use App\Livewire\Concerns\Results\SummarisesResults;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\Result;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::desk'), Title('titles.results_framework')] class extends Component {
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
     * 'offtrack' only statements behind target, 'waiting' only statements whose
     * figures are old or never read. The three are disjoint (measurements()['status']).
     */
    #[Url(except: '')]
    public string $focus = '';

    /**
     * `?program=<id>&create=1` (the logframe-missing alert, T14) opens the
     * create form for that program's framework, starting at impact. Read once
     * here rather than bound with #[Url], so closing the dialog and refreshing
     * does not reopen it. Anything else in the link is ignored.
     */
    public function mount(): void
    {
        $programId = request()->integer('program');

        if (request()->query('create') !== '1' || $programId <= 0 || ! Gate::allows('results.create') || ! Program::query()->whereKey($programId)->exists()) {
            return;
        }

        $this->openCreateModal();
        $this->resultProgramId = (string) $programId;
        $this->resultType = ResultType::Impact->value;
    }

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
};
?>

@php
    $filtersAreActive = $search !== '' || $proposal !== '' || $program !== '' || $type !== '' || $focus !== '';
    $overview = $this->overview;


@endphp

<x-desk.register class="@container/directory" heading-id="results-framework-heading" :title="__('common.results_framework')" :count="$overview['statements']">
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
            @can('results.create')<x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-result-button" data-quick-create>
                {{ __('results_framework.add_result') }}
            </x-ui.button>@endcan
        </x-slot:actions>


    {{-- Three numbers that say what needs a hand, each one a filter. --}}
    @include('pages.results._index-overview')

    @if ($filtersAreActive)
        <div>
            <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-ink-muted!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
        </div>
    @endif

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
</x-desk.register>
