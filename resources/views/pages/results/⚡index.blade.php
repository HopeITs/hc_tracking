<?php

use App\Enums\ResultType;
use App\Livewire\Concerns\Results\BuildsResultsFramework;
use App\Livewire\Concerns\Results\BuildsResultsTree;
use App\Livewire\Concerns\Results\ManagesResultForm;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use Illuminate\Database\Eloquent\Builder;
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

    #[Url(except: '')]
    public string $search = '';

    /** '' every program, 'none' the framework outside every program, an id one program. */
    #[Url(except: '')]
    public string $program = '';

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
        $this->program = '';
    }

    /**
     * This page reads and writes program frameworks only. A proposal's
     * framework, even one started from a program's, lives on that proposal.
     *
     * @return Builder<Result>
     */
    protected function frameworkResults(): Builder
    {
        return Result::query()->programFramework();
    }

    /**
     * Programs that carry a framework, read from the loaded results (no
     * query), so the filter only offers a choice that shows something.
     *
     * @return Collection<int, Program>
     */
    #[Computed]
    public function frameworkProgramOptions(): Collection
    {
        return $this->allResults
            ->map(fn (Result $result): ?Program => $result->program)
            ->filter()
            ->unique('id')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /** Whether any program-framework statement sits outside every program. */
    #[Computed]
    public function hasUnassignedFramework(): bool
    {
        return $this->allResults->contains(fn (Result $result): bool => $result->program_id === null);
    }

    /**
     * Proposals that own a framework, narrowed by the same program filter and
     * by the search on their title; each is a small card linking to its
     * logframe. A proposal funding a project the user may open links to that
     * project's Story block (its Logical Framework tab); otherwise to the
     * proposal page's tab. A proposal the user can reach neither way is left out.
     *
     * @return Collection<int, array{id: int, name: string, href: string}>
     */
    #[Computed]
    public function proposalFrameworks(): Collection
    {
        $user = auth()->user();
        $mayViewProposals = $user->can('proposals.view');
        $mayViewProjects = $user->can('projects.view');

        if (! $mayViewProposals && ! $mayViewProjects) {
            return collect();
        }

        $search = trim($this->search);

        return Proposal::query()
            ->select(['id', 'slug', 'name'])
            ->whereHas('results', fn (Builder $results): Builder => $results
                ->when($this->program === 'none', fn (Builder $query): Builder => $query->whereNull('program_id'))
                ->when($this->program !== '' && $this->program !== 'none', fn (Builder $query): Builder => $query->where('program_id', (int) $this->program)))
            ->when($search !== '', fn (Builder $query): Builder => $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->when($mayViewProjects, fn (Builder $query): Builder => $query->with(['projects' => fn ($projects) => $projects->select(['projects.id', 'projects.slug', 'projects.program_id'])->orderBy('projects.id')]))
            ->orderBy('name')
            ->get()
            ->map(function (Proposal $proposal) use ($user, $mayViewProposals, $mayViewProjects): ?array {
                $project = $mayViewProjects ? $proposal->projects->first(fn (Project $project): bool => $user->can('view', $project)) : null;

                $href = match (true) {
                    $project !== null => route('projects.show', ['project' => $project, 'proposal' => $proposal->id, 'ptab' => 'logframe']).'#proposal',
                    $mayViewProposals => route('proposals.show', ['proposal' => $proposal, 'ptab' => 'logframe']),
                    default => null,
                };

                return $href === null ? null : ['id' => $proposal->id, 'name' => $proposal->name, 'href' => $href];
            })
            ->filter()
            ->values();
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $program !== '';
    $groups = $this->groupedRoots;
    $proposalFrameworks = $this->proposalFrameworks;
@endphp

<x-desk.register class="@container/directory" heading-id="results-framework-heading" :title="__('common.results_framework')" :count="$groups->count()">
        <x-slot:search>
            <x-desk.register-search :label="__('results_framework.search_the_framework')" :placeholder="__('results_framework.search_programs_or_proposals')" wire:model.live.debounce.300ms="search" />
        </x-slot:search>
        <x-slot:filters>
            <x-ui.header-filter-select :label="__('common.program')" wire:model.live="program" :value="$program">
                <option value="">{{ __('common.all_programs') }}</option>
                @foreach ($this->frameworkProgramOptions as $programOption)
                    <option value="{{ $programOption->id }}">{{ $programOption->name }}</option>
                @endforeach
                @if ($this->hasUnassignedFramework)
                    <option value="none">{{ __('common.unassigned') }}</option>
                @endif
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            @can('results.create')<x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-result-button" data-quick-create>
                {{ __('results_framework.add_result') }}
            </x-ui.button>@endcan
        </x-slot:actions>

    @if ($filtersAreActive)
        <div>
            <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-ink-muted!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
        </div>
    @endif

    @if ($groups->isEmpty() && $proposalFrameworks->isEmpty())
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
        @include('pages.results._index-proposal-cards')
    @endif

    @include('pages.results._index-result-modal')

    <livewire:indicators.quick-form />

    <x-ui.confirm-modal
        permanent
        wire:model="showDeleteModal"
        close="cancelDelete"
        :title="__('results_framework.delete_this_result')"
        :message="__('results_framework.only_a_result_with_no_nested_results_and_no_indicators_can_b')"
        confirm="deleteResult"
        :confirm-label="__('results_framework.delete_result')"
        confirm-test="confirm-delete-result"
    />
</x-desk.register>
