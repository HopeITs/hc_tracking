<?php

use App\Enums\ProjectStatus;
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
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::desk'), Title('titles.programs')] class extends Component {
    /** The strategy / manager filter value that lists programs with nobody linked. */
    private const Unassigned = 'none';

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
    #[Locked]
    public string $originalStrategyId = '';
    #[Locked]
    public string $originalManagerId = '';

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
        $counts = [
            'projects',
            'annualPlans',
            'projects as overdue_projects_count' => fn (Builder $query) => $query
                ->where('status', ProjectStatus::Active->value)
                ->whereDate('end_date', '<', today()),
            'projects as risky_projects_count' => fn (Builder $query) => $query
                ->whereHas('risks', fn (Builder $risks) => $risks->where('happened', true)),
        ];

        if ($this->canSeeFunding()) {
            $counts['projects as unfunded_projects_count'] = fn (Builder $query) => $query->doesntHave('proposals');
        }

        return $this->filteredProgramsQuery()
            ->select(['id', 'strategy_id', 'manager_id', 'updated_at'])
            ->with([
                'strategy:id,name,start_year,end_year',
                'manager:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
            ])
            ->withCount($counts)
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
            ->when($this->strategy === self::Unassigned, fn (Builder $query) => $query->whereNull('strategy_id'))
            ->when($this->strategy !== '' && $this->strategy !== self::Unassigned, fn (Builder $query) => $query->where('strategy_id', (int) $this->strategy))
            ->when($this->manager === self::Unassigned, fn (Builder $query) => $query->whereNull('manager_id'))
            ->when($this->manager !== '' && $this->manager !== self::Unassigned, fn (Builder $query) => $query->where('manager_id', (int) $this->manager))
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

    /**
     * Who manages a program today, with how many, so the filter lists only
     * people who can match something. A manager already in the URL stays
     * listed even if no program has them any more.
     *
     * @return list<array{value: string, label: string}>
     */
    #[Computed]
    public function managerOptions(): array
    {
        $counts = Program::query()
            ->whereNotNull('manager_id')
            ->selectRaw('manager_id, count(*) as total')
            ->groupBy('manager_id')
            ->pluck('total', 'manager_id');

        if (ctype_digit($this->manager) && ! $counts->has((int) $this->manager)) {
            $counts->put((int) $this->manager, 0);
        }

        $labels = HrEmployee::pickerLabels()->pluck('label', 'value');

        return $counts
            ->map(fn (int|string $total, int|string $id): array => [
                'value' => (string) $id,
                // The left-to-right mark keeps "(2)" after an Arabic name instead of reordering before it.
                'label' => ($labels->get((string) $id) ?? HrEmployee::query()->find($id)?->name ?? '#'.$id)."\u{200E} (".$total.')',
            ])
            ->sortBy(fn (array $option): string => mb_strtolower($option['label']))
            ->values()
            ->all();
    }

    /** @return array{without_strategy: int, without_manager: int} */
    #[Computed]
    public function unassignedCounts(): array
    {
        /** @var object{without_strategy: int|string|null, without_manager: int|string|null} $row */
        $row = Program::query()
            ->selectRaw('sum(case when strategy_id is null then 1 else 0 end) as without_strategy')
            ->selectRaw('sum(case when manager_id is null then 1 else 0 end) as without_manager')
            ->first();

        return ['without_strategy' => (int) $row->without_strategy, 'without_manager' => (int) $row->without_manager];
    }

    /**
     * A strategy as one short name: the years are already in names like
     * "Country Strategy 2026-2030", so they are added only when the name lacks them.
     */
    public function strategyLabel(Strategy $strategy): string
    {
        return str_contains($strategy->name, (string) $strategy->start_year)
            ? $strategy->name
            : $strategy->name.' ('.$strategy->start_year.'–'.$strategy->end_year.')';
    }

    /** Whether the viewer may see funding signals (projects with no proposal yet). */
    protected function canSeeFunding(): bool
    {
        return auth()->user()?->can('proposals.view') === true;
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
        $this->strategyId = $this->originalStrategyId = (string) $program->strategy_id;
        $this->managerId = $this->originalManagerId = (string) $program->manager_id;
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
        $this->originalStrategyId = '';
        $this->originalManagerId = '';
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $strategy !== '' || $manager !== '' || $gap !== '';
    $summary = $this->programSummary;
    $visiblePrograms = $this->programs->take($visibleProgramCount);
    $hasMorePrograms = $this->programs->count() > $visibleProgramCount;
@endphp

<x-desk.register class="admin-directory @container/directory" heading-id="program-directory-heading" :title="__('common.programs')" :count="$summary['total']">
    
        <x-slot:search>
            <x-desk.register-search id="program-search" data-directory-search :label="__('programs.search_programs')" :placeholder="__('programs.program_strategy_or_manager')" wire:model.live.debounce.300ms="search" />
        </x-slot:search>
        <x-slot:filters>
            <x-ui.header-filter-select id="program-strategy" :label="__('common.strategy')" :search-label="__('programs.search_strategies')" wire:model.live="strategy" :value="$strategy">
                <option value="">{{ __('programs.all_strategies') }}</option>
                <option value="none">{{ __('programs.filter_not_assigned', ['count' => $this->unassignedCounts['without_strategy']]) }}</option>
                @foreach ($this->strategyOptions as $strategyOption)
                    <option value="{{ $strategyOption->id }}">{{ $this->strategyLabel($strategyOption) }}</option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select id="program-manager" :label="__('programs.manager')" :search-label="__('programs.search_managers')" wire:model.live="manager" :value="$manager">
                <option value="">{{ __('common.all_managers') }}</option>
                <option value="none">{{ __('programs.filter_not_assigned', ['count' => $this->unassignedCounts['without_manager']]) }}</option>
                @foreach ($this->managerOptions as $managerOption)
                    <option value="{{ $managerOption['value'] }}">{{ $managerOption['label'] }}</option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>
    
    <div class="flex justify-end"><span wire:loading.delay.flex wire:target="search,strategy,manager,gap,clearFilters" class="hidden items-center gap-1.5 text-xs text-secondary-medium dark:text-ink-muted"><flux:icon.arrow-path class="size-3.5 animate-spin" /> {{ __('common.updating') }}</span>@if ($filtersAreActive && $visiblePrograms->isNotEmpty())<flux:button size="sm" variant="ghost" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>@endif</div>

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
                    <article class="group relative flex min-h-56 min-w-0 flex-col gap-4 rounded-lg border border-secondary-soft bg-white p-4 transition-colors duration-200 hover:border-primary focus-within:border-secondary-medium dark:border-line dark:bg-surface-1 dark:hover:border-primary" wire:key="program-card-{{ $program->id }}">
                        <a href="{{ route('programs.show', $program) }}" wire:navigate class="absolute inset-0 z-0 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium" tabindex="-1" aria-hidden="true"></a>
                        @php
                            $overdueProjects = (int) $program->overdue_projects_count;
                            $riskyProjects = (int) $program->risky_projects_count;
                            $unfundedProjects = (int) ($program->unfunded_projects_count ?? 0);
                            $health = $program->projects_count === 0 ? 'none' : ($overdueProjects > 0 || $riskyProjects > 0 ? 'critical' : ($unfundedProjects > 0 ? 'warn' : 'good'));
                        @endphp
                        <div class="relative z-10 flex items-start justify-between gap-3 pointer-events-none"><div class="flex min-w-0 items-center gap-3"><div class="grid size-10 shrink-0 place-items-center rounded-md bg-primary text-white"><flux:icon.briefcase class="size-5" /></div><div class="min-w-0"><p class="text-[11px] font-semibold uppercase tracking-[0.08em] text-secondary-medium dark:text-ink-muted">{{ __('common.program') }}</p><h2 class="mt-1 line-clamp-2 break-words text-base font-semibold text-secondary-dark dark:text-ink" title="{{ $program->name }}">{{ $program->name }}</h2></div></div><div class="pointer-events-auto flex shrink-0 items-center gap-1"><a href="{{ route('programs.show', $program) }}" wire:navigate class="grid size-11 place-items-center rounded-md border-0! bg-transparent! text-secondary-dark! transition-colors hover:bg-secondary-soft! dark:text-ink! dark:hover:bg-surface-3! focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium @3xl/directory:size-8!" aria-label="{{ __('programs.open_name', ['name' => $program->name]) }}"><flux:icon.eye class="size-4.5" /></a>@can('programs.update')<x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-11! touch-manipulation hover:bg-secondary-soft! dark:hover:bg-surface-3! @3xl/directory:size-8!" wire:click="openEditModal({{ $program->id }})" aria-label="{{ __('common.edit_name', ['name' => $program->name]) }}" />@endcan</div></div>
                        <dl class="relative z-10 grid grid-cols-2 gap-4 pointer-events-none"><div class="min-w-0"><dt class="text-[11px] font-semibold uppercase tracking-[0.08em] text-secondary-medium dark:text-ink-muted">{{ __('common.strategy') }}</dt>@if ($program->strategy)<dd class="mt-1 truncate text-sm font-medium text-secondary-dark dark:text-ink" title="{{ $program->strategy->name }}">{{ $program->strategy->name }}</dd>@if (! str_contains($program->strategy->name, (string) $program->strategy->start_year))<p class="text-xs tabular-nums text-secondary-medium dark:text-ink-muted">{{ $program->strategy->start_year }}–{{ $program->strategy->end_year }}</p>@endif @else<dd class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-medium text-status-warn" data-program-unlinked>{{ __('programs.not_linked_yet') }}@can('programs.update')<button type="button" class="pointer-events-auto inline-flex min-h-11 items-center rounded-md px-2 text-xs font-semibold text-secondary-dark underline underline-offset-2 hover:bg-secondary-soft dark:text-ink dark:hover:bg-surface-3 @3xl/directory:min-h-8" wire:click="openEditModal({{ $program->id }})" aria-label="{{ __('programs.assign_name_short', ['name' => $program->name]) }}">{{ __('programs.assign') }}</button>@endcan</dd>@endif</div><div class="min-w-0"><dt class="text-[11px] font-semibold uppercase tracking-[0.08em] text-secondary-medium dark:text-ink-muted">{{ __('programs.manager') }}</dt><dd class="mt-1">@if ($program->manager)<x-ui.employee-chip :employee="$program->manager" compact />@else<span class="text-sm text-secondary-medium dark:text-ink-muted">{{ __('common.not_assigned') }}</span>@endif</dd></div></dl>
                        <p class="relative z-10 mt-auto flex flex-wrap items-center gap-x-3 gap-y-1 text-xs pointer-events-none" data-program-health="{{ $health }}">
                            <span @class(['size-2 shrink-0 rounded-full', 'bg-status-critical' => $health === 'critical', 'bg-status-warn' => $health === 'warn', 'bg-status-good' => $health === 'good', 'bg-secondary-light dark:bg-ink-faint' => $health === 'none']) aria-hidden="true"></span>
                            @if ($health === 'none')
                                <span class="text-secondary-medium dark:text-ink-muted">{{ __('programs.card_no_projects') }}</span>
                            @elseif ($health === 'good')
                                <span class="text-secondary-medium dark:text-ink-muted">{{ __('programs.card_nothing_late') }}</span>
                            @else
                                @if ($overdueProjects > 0)<span class="font-semibold text-status-critical">{{ trans_choice('programs.card_overdue', $overdueProjects, ['count' => $overdueProjects]) }}</span>@endif
                                @if ($riskyProjects > 0)<span class="font-semibold text-status-critical">{{ trans_choice('programs.card_risks', $riskyProjects, ['count' => $riskyProjects]) }}</span>@endif
                                @if ($unfundedProjects > 0)<span class="font-semibold text-status-warn">{{ trans_choice('programs.card_unfunded', $unfundedProjects, ['count' => $unfundedProjects]) }}</span>@endif
                            @endif
                        </p>
                        <div class="relative z-10 grid grid-cols-2 border-t border-secondary-soft pt-3 dark:border-line pointer-events-none"><div><p class="text-[11px] font-semibold uppercase tracking-[0.08em] text-secondary-medium dark:text-ink-muted">{{ __('common.projects') }}</p><p class="mt-1 text-2xl font-bold tabular-nums text-secondary-dark dark:text-ink">{{ $program->projects_count }}</p></div><div><p class="text-[11px] font-semibold uppercase tracking-[0.08em] text-secondary-medium dark:text-ink-muted">{{ __('programs.annual_plans') }}</p><p class="mt-1 text-2xl font-bold tabular-nums text-secondary-dark dark:text-ink">{{ $program->annual_plans_count }}</p></div></div>
                    </article>
                @endforeach
            </div>
            <p class="text-center text-xs text-secondary-medium dark:text-ink-muted" data-program-shown>{{ __('programs.showing_count', ['shown' => $visiblePrograms->count(), 'total' => $summary['total']]) }}</p>
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
        :discard-guard="$showProgramModal ? ['fields' => ['strategyId', 'managerId'], 'original' => ['strategyId' => $originalStrategyId, 'managerId' => $originalManagerId]] : null"
    >
        @if ($showProgramModal)
            <div class="grid gap-y-4">
                <x-ui.form-select wire:model="strategyId" :label="__('programs.linked_strategy')" autofocus>
                    <option value="">{{ __('common.not_assigned') }}</option>
                    @foreach ($this->strategyOptions as $strategyOption)
                        <option value="{{ $strategyOption->id }}">{{ $this->strategyLabel($strategyOption) }}</option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-select wire:model="managerId" :label="__('programs.direct_manager')" :help="__('programs.the_assigned_manager_is_the_hr_employee_directly_accountable')" employees>
                    <option value="">{{ __('common.not_assigned') }}</option>
                </x-ui.form-select>
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-program-button">{{ __('common.save_changes') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
</x-desk.register>
