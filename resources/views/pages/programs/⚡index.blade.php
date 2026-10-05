<?php

use App\Support\Like;
use App\Models\HrEmployee;
use App\Models\Program;
use App\Models\Strategy;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('titles.programs')] class extends Component {
    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $strategy = '';

    #[Url(except: '')]
    public string $manager = '';

    #[Url(except: '')]
    public string $gap = '';

    public int $visibleProgramCount = 8;

    public bool $showProgramModal = false;
    #[Locked]
    public ?int $editingProgramId = null;
    public string $editingProgramName = '';
    public string $strategyId = '';
    public string $managerId = '';

    public function updatedSearch(): void
    {
        $this->resetVisiblePrograms();
    }

    public function updatedStrategy(): void
    {
        $this->resetVisiblePrograms();
    }

    public function updatedManager(): void
    {
        $this->resetVisiblePrograms();
    }

    public function filterByStrategy(?int $strategyId = null): void
    {
        $this->strategy = $strategyId === null ? '' : (string) $strategyId;
        $this->resetVisiblePrograms();
    }

    public function filterByManager(?int $managerId = null): void
    {
        $this->manager = $managerId === null ? '' : (string) $managerId;
        $this->resetVisiblePrograms();
    }

    public function updatedGap(): void
    {
        $this->gap = in_array($this->gap, ['no_strategy', 'no_manager'], true) ? $this->gap : '';
        $this->resetVisiblePrograms();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->strategy = '';
        $this->manager = '';
        $this->gap = '';
        $this->resetVisiblePrograms();
    }

    public function showGap(string $gap): void
    {
        $this->gap = $gap;
        $this->resetVisiblePrograms();
    }

    public function loadMore(): void
    {
        if ($this->visibleProgramCount < $this->programSummary['total']) {
            $this->visibleProgramCount += 4;
        }
    }

    protected function resetVisiblePrograms(): void
    {
        $this->visibleProgramCount = 8;
    }

    /** @return Collection<int, Program> */
    #[Computed]
    public function programs(): Collection
    {
        return $this->filteredProgramsQuery()
            ->select(['id', 'strategy_id', 'manager_id', 'updated_at'])
            ->with([
                'strategy:id,name,start_year,end_year',
                'manager:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
            ])
            ->withCount([
                'projects',
                'annualPlans',
            ])
            ->orderByName()
            ->limit($this->visibleProgramCount + 1)
            ->get();
    }

    /** @return array{total: int, without_strategy: int, without_manager: int} */
    #[Computed]
    public function programSummary(): array
    {
        /** @var object{total: int|string, without_strategy: int|string, without_manager: int|string} $summary */
        $summary = $this->filteredProgramsQuery()
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when strategy_id is null then 1 else 0 end) as without_strategy')
            ->selectRaw('sum(case when manager_id is null then 1 else 0 end) as without_manager')
            ->first();

        return [
            'total' => (int) $summary->total,
            'without_strategy' => (int) $summary->without_strategy,
            'without_manager' => (int) $summary->without_manager,
        ];
    }

    /** @return Builder<Program> */
    protected function filteredProgramsQuery(): Builder
    {
        $search = trim($this->search);

        return Program::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->whereNameLike($search)
                        ->orWhereHas('strategy', fn (Builder $query) => $query->where('name', 'like', Like::contains($search)))
                        ->orWhereIn('manager_id', HrEmployee::idsMatchingName($search));
                });
            })
            ->when($this->strategy !== '', fn (Builder $query) => $query->where('strategy_id', (int) $this->strategy))
            ->when($this->manager !== '', fn (Builder $query) => $query->where('manager_id', (int) $this->manager))
            ->when($this->gap === 'no_strategy', fn (Builder $query) => $query->whereNull('strategy_id'))
            ->when($this->gap === 'no_manager', fn (Builder $query) => $query->whereNull('manager_id'));
    }

    /** @return Collection<int, Strategy> */
    #[Computed]
    public function strategyOptions(): Collection
    {
        return Strategy::query()
            ->select(['id', 'name', 'start_year', 'end_year'])
            ->orderByDesc('start_year')
            ->orderByDesc('end_year')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, HrEmployee> */
    #[Computed]
    public function managerOptions(): Collection
    {
        return HrEmployee::pickerOptions();
    }

    public function openEditModal(int $programId): void
    {
        $program = Program::query()
            ->select(['id', 'strategy_id', 'manager_id'])
            ->findOrFail($programId);

        Gate::authorize('update', $program);

        $this->resetValidation();
        $this->editingProgramId = $program->id;
        $this->editingProgramName = $program->name;
        $this->strategyId = (string) $program->strategy_id;
        $this->managerId = (string) $program->manager_id;
        $this->showProgramModal = true;
    }

    /**
     * Link an HR program to a strategy and manager.
     *
     * The program itself (its name, whether it exists) belongs to HR, so only
     * the two tracking-side links are editable, and either may be left empty.
     */
    public function saveProgram(): void
    {
        Gate::authorize('update', Program::query()->findOrFail($this->editingProgramId));

        $validated = $this->validate([
            'strategyId' => ['nullable', 'integer', Rule::exists(Strategy::class, 'id')],
            'managerId' => ['nullable', 'integer', Rule::exists(HrEmployee::class, 'id')],
        ], [
            'strategyId.exists' => __('programs.the_selected_strategy_is_no_longer_available'),
            'managerId.exists' => __('programs.the_selected_manager_is_no_longer_available'),
        ]);

        Program::query()->findOrFail($this->editingProgramId)->update([
            'strategy_id' => blank($validated['strategyId']) ? null : (int) $validated['strategyId'],
            'manager_id' => blank($validated['managerId']) ? null : (int) $validated['managerId'],
        ]);

        $this->showProgramModal = false;
        $this->resetProgramForm();

        Flux::toast(variant: 'success', text: __('programs.program_assignment_updated'));
    }

    public function closeProgramModal(): void
    {
        $this->showProgramModal = false;
        $this->resetProgramForm();
    }

    protected function resetProgramForm(): void
    {
        $this->resetValidation();
        $this->editingProgramId = null;
        $this->editingProgramName = '';
        $this->strategyId = '';
        $this->managerId = '';
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $strategy !== '' || $manager !== '' || $gap !== '';
    $summary = $this->programSummary;
    $visiblePrograms = $this->programs->take($visibleProgramCount);
    $hasMorePrograms = $this->programs->count() > $visibleProgramCount;
@endphp

<section class="admin-directory @container/directory flex min-h-full w-full flex-1 flex-col gap-8 bg-white px-1 text-secondary-dark dark:bg-surface-0 dark:text-ink" aria-labelledby="program-directory-heading">
    <x-ui.page-header heading-id="program-directory-heading" :title="__('common.programs')" :count="$summary['total']">
        <x-slot:filters>
            <div class="min-w-0">
                <label for="program-search" class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.1em] text-secondary-medium dark:text-ink-muted">{{ __('programs.search_programs') }}</label>
                <x-ui.input id="program-search" data-directory-search type="search" icon="magnifying-glass" :aria-label="__('programs.search_programs')" :placeholder="__('programs.program_strategy_or_manager')" wire:model.live.debounce.300ms="search" />
            </div>
            <x-ui.header-filter-select id="program-strategy" :label="__('common.strategy')" :search-label="__('programs.search_strategies')" wire:model.live="strategy" :value="$strategy">
                <option value="">{{ __('programs.all_strategies') }}</option>
                @foreach ($this->strategyOptions as $strategyOption)
                    <option value="{{ $strategyOption->id }}">
                        {{ $strategyOption->name }} · {{ $strategyOption->start_year }}–{{ $strategyOption->end_year }}
                    </option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select id="program-manager" :label="__('programs.manager')" :search-label="__('programs.search_managers')" wire:model.live="manager" :value="$manager">
                <option value="">{{ __('common.all_managers') }}</option>
                @foreach ($this->managerOptions as $managerOption)
                    <option value="{{ $managerOption->id }}">
                        {{ $managerOption->name }}
                    </option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>
    </x-ui.page-header>

    <div class="flex justify-end"><span wire:loading.delay.flex wire:target="search,strategy,manager,gap,clearFilters" class="hidden items-center gap-1.5 text-xs text-secondary-medium dark:text-ink-muted"><flux:icon.arrow-path class="size-3.5 animate-spin" /> {{ __('common.updating') }}</span>@if ($filtersAreActive)<flux:button size="sm" variant="ghost" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>@endif</div>

        @if ($visiblePrograms->isEmpty())
            <x-ui.empty-state icon="briefcase" :heading="$filtersAreActive ? __('programs.no_matching_programs') : __('programs.no_programs_from_hr_yet')" :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('programs.programs_appear_here_once_they_are_added_in_the_hr_system')">
                @if ($filtersAreActive)
                    <x-slot:action>
                        <x-ui.button size="sm" wire:click="clearFilters">{{ __('common.reset_filters') }}</x-ui.button>
                    </x-slot:action>
                @endif
            </x-ui.empty-state>
        @else
            <div class="grid gap-6 @3xl/directory:grid-cols-2 @6xl/directory:grid-cols-4">
                @foreach ($visiblePrograms as $program)
                    <article class="group relative flex min-h-56 min-w-0 flex-col gap-4 rounded-lg border border-secondary-soft bg-white p-4 transition-colors duration-200 hover:border-primary focus-within:border-primary dark:border-line dark:bg-surface-1 dark:hover:border-primary" wire:key="program-card-{{ $program->id }}">
                        <a href="{{ route('programs.show', $program) }}" wire:navigate class="absolute inset-0 z-0 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" :aria-label="__('programs.open_name', ['name' => $program->name])"></a>
                        <div class="relative z-10 flex items-start justify-between gap-3 pointer-events-none"><div class="flex min-w-0 items-center gap-3"><div class="grid size-10 shrink-0 place-items-center rounded-md bg-primary dark:bg-white/10 dark:text-ink text-white"><flux:icon.briefcase class="size-5" /></div><div class="min-w-0"><p class="text-[11px] font-semibold uppercase tracking-[0.08em] text-secondary-medium dark:text-ink-muted">{{ __('common.program') }}</p><h2 class="mt-1 truncate text-base font-semibold text-secondary-dark dark:text-ink">{{ $program->name }}</h2></div></div><div class="pointer-events-auto flex shrink-0 items-center gap-1"><a href="{{ route('programs.show', $program) }}" wire:navigate class="grid size-11 place-items-center rounded-md border-0! bg-transparent! text-secondary-dark! transition-colors hover:bg-secondary-soft! dark:text-ink! dark:hover:bg-surface-2! focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary @3xl/directory:size-8!" :aria-label="__('programs.open_name', ['name' => $program->name])"><flux:icon.eye class="size-4.5" /></a>@can('programs.update')<x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-11! touch-manipulation @3xl/directory:size-8!" wire:click="openEditModal({{ $program->id }})" :aria-label="__('common.edit_name', ['name' => $program->name])" />@endcan</div></div>
                        <dl class="relative z-10 grid grid-cols-2 gap-4 pointer-events-none"><div class="min-w-0"><dt class="text-[11px] font-semibold uppercase tracking-[0.08em] text-secondary-medium dark:text-ink-muted">{{ __('common.strategy') }}</dt><dd class="mt-1 truncate text-sm font-medium text-secondary-dark dark:text-ink">{{ $program->strategy?->name ?? __('common.not_assigned') }}</dd>@if ($program->strategy)<p class="text-xs tabular-nums text-secondary-medium dark:text-ink-muted">{{ $program->strategy->start_year }}–{{ $program->strategy->end_year }}</p>@endif</div><div class="min-w-0"><dt class="text-[11px] font-semibold uppercase tracking-[0.08em] text-secondary-medium dark:text-ink-muted">{{ __('programs.manager') }}</dt><dd class="mt-1">@if ($program->manager)<x-ui.employee-chip :employee="$program->manager" compact />@else<span class="text-sm text-secondary-medium dark:text-ink-muted">{{ __('common.not_assigned') }}</span>@endif</dd></div></dl>
                        <div class="relative z-10 mt-auto grid grid-cols-2 border-t border-secondary-soft pt-3 dark:border-line pointer-events-none"><div><p class="text-[11px] font-semibold uppercase tracking-[0.08em] text-secondary-medium dark:text-ink-muted">{{ __('common.projects') }}</p><p class="mt-1 text-2xl font-bold tabular-nums text-secondary-dark dark:text-ink">{{ $program->projects_count }}</p></div><div><p class="text-[11px] font-semibold uppercase tracking-[0.08em] text-secondary-medium dark:text-ink-muted">{{ __('programs.annual_plans') }}</p><p class="mt-1 text-2xl font-bold tabular-nums text-secondary-dark dark:text-ink">{{ $program->annual_plans_count }}</p></div></div>
                    </article>
                @endforeach
            </div>
            @if ($hasMorePrograms)
                <div class="flex justify-center pt-1"><x-ui.button tone="primary" icon="plus" class="min-h-11 px-5 data-loading:opacity-70" wire:click="loadMore" wire:loading.attr="disabled" wire:target="loadMore">{{ __('programs.load_more') }}</x-ui.button></div>
            @endif
        @endif

    <x-ui.modal
        wire:model="showProgramModal"
        size="md"
        :title="__('programs.assign_name', ['name' => $editingProgramName])"
        :description="__('programs.the_program_name_is_managed_in_the_hr_system_link_it_to_a_st')"
        submit="saveProgram"
        close="closeProgramModal"
    >
        <div class="grid gap-y-4">
            <x-ui.form-select wire:model="strategyId" :label="__('programs.linked_strategy')" autofocus>
                <option value="">{{ __('common.not_assigned') }}</option>
                @foreach ($this->strategyOptions as $strategyOption)
                    <option value="{{ $strategyOption->id }}">{{ $strategyOption->name }} ({{ $strategyOption->start_year }}–{{ $strategyOption->end_year }})</option>
                @endforeach
            </x-ui.form-select>

            <x-ui.form-select wire:model="managerId" :label="__('programs.direct_manager')" :help="__('programs.the_assigned_manager_is_the_hr_employee_directly_accountable')">
                <option value="">{{ __('common.not_assigned') }}</option>
                @foreach ($this->managerOptions as $managerOption)
                    <option value="{{ $managerOption->id }}">{{ $managerOption->name }} · {{ $managerOption->secondary_name }}</option>
                @endforeach
            </x-ui.form-select>
        </div>

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-program-button">{{ __('common.save_changes') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
</section>
