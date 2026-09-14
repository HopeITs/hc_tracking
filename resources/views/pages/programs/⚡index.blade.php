<?php

use App\Models\HrEmployee;
use App\Models\Program;
use App\Models\Strategy;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Programs')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $strategy = '';

    #[Url(except: '')]
    public string $manager = '';

    public bool $showProgramModal = false;
    public ?int $editingProgramId = null;
    public string $editingProgramName = '';
    public string $strategyId = '';
    public string $managerId = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStrategy(): void
    {
        $this->resetPage();
    }

    public function updatedManager(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->strategy = '';
        $this->manager = '';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Program> */
    #[Computed]
    public function programs(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Program::query()
            ->select(['id', 'name', 'strategy_id', 'manager_id', 'updated_at'])
            ->with([
                'strategy:id,name,start_year,end_year',
                'manager:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
            ])
            ->withCount(['projects', 'yearlyPlans'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhereHas('strategy', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
                        ->orWhereIn('manager_id', HrEmployee::idsMatchingName($search));
                });
            })
            ->when($this->strategy !== '', fn ($query) => $query->where('strategy_id', (int) $this->strategy))
            ->when($this->manager !== '', fn ($query) => $query->where('manager_id', (int) $this->manager))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15);
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
            ->select(['id', 'name', 'strategy_id', 'manager_id'])
            ->findOrFail($programId);

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
        $validated = $this->validate([
            'strategyId' => ['nullable', 'integer', Rule::exists(Strategy::class, 'id')],
            'managerId' => ['nullable', 'integer', Rule::exists(HrEmployee::class, 'id')],
        ], [
            'strategyId.exists' => __('The selected strategy is no longer available.'),
            'managerId.exists' => __('The selected manager is no longer available.'),
        ]);

        Program::query()->findOrFail($this->editingProgramId)->update([
            'strategy_id' => blank($validated['strategyId']) ? null : (int) $validated['strategyId'],
            'manager_id' => blank($validated['managerId']) ? null : (int) $validated['managerId'],
        ]);

        $this->showProgramModal = false;
        $this->resetProgramForm();

        Flux::toast(variant: 'success', text: __('Program assignment updated.'));
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
    $controlClasses = 'border-secondary-light! bg-white! text-secondary-dark! placeholder:text-secondary-light! dark:border-secondary-light! dark:bg-secondary-medium! dark:text-white!';
    $primaryButtonClasses = 'border-primary! bg-primary! text-white! hover:border-primary! hover:bg-primary!';
    $secondaryButtonClasses = 'border-secondary-light! bg-white! text-secondary-dark! hover:bg-secondary-soft! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white! dark:hover:bg-secondary-medium!';
    $editButtonClasses = 'border-0! bg-transparent! text-secondary-dark! hover:bg-secondary-soft! dark:text-white! dark:hover:bg-secondary-medium!';
    $filtersAreActive = $search !== '' || $strategy !== '' || $manager !== '';
@endphp

<section
    class="admin-directory @container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white [&_[data-flux-cell]]:border-secondary-soft! [&_[data-flux-cell]]:text-secondary-medium! [&_[data-flux-column]]:border-secondary-soft! [&_[data-flux-column]]:text-secondary-dark! [&_[data-flux-heading]]:text-secondary-dark! [&_[data-flux-label]]:text-secondary-dark! [&_[data-flux-subheading]]:text-secondary-medium! dark:[&_[data-flux-cell]]:border-secondary-medium! dark:[&_[data-flux-cell]]:text-secondary-soft! dark:[&_[data-flux-column]]:border-secondary-medium! dark:[&_[data-flux-column]]:text-white! dark:[&_[data-flux-heading]]:text-white! dark:[&_[data-flux-label]]:text-white! dark:[&_[data-flux-subheading]]:text-secondary-soft!"
    aria-labelledby="program-directory-heading"
>
    <header class="flex flex-col gap-3 border-b border-secondary-soft pb-4 dark:border-secondary-medium @6xl/directory:flex-row @6xl/directory:items-end">
        <div class="min-w-48 @6xl/directory:me-auto">
            <div class="flex items-center gap-2">
                <flux:heading id="program-directory-heading" size="lg">{{ __('Programs') }}</flux:heading>
                <span class="inline-flex min-w-7 items-center justify-center rounded-md border border-secondary-light px-2 py-0.5 text-xs font-semibold text-secondary-medium dark:text-secondary-soft">
                    {{ $this->programs->total() }}
                </span>
            </div>
            <p class="text-sm text-secondary-medium dark:text-secondary-soft">{{ __('Programs come from the HR system. Link each one to a strategy and an accountable manager.') }}</p>
        </div>

        <div class="grid w-full gap-2 @sm/directory:grid-cols-2 @3xl/directory:grid-cols-[minmax(13rem,1fr)_minmax(10rem,0.8fr)_minmax(10rem,0.8fr)] @6xl/directory:max-w-[62rem]">
            <flux:input type="search" icon="magnifying-glass" :label="__('Search programs')" :placeholder="__('Program, strategy, or manager')" :class="$controlClasses" wire:model.live.debounce.300ms="search" clearable />

            <flux:select :label="__('Strategy')" :class="$controlClasses" wire:model.live="strategy">
                <flux:select.option value="">{{ __('All strategies') }}</flux:select.option>
                @foreach ($this->strategyOptions as $strategyOption)
                    <flux:select.option :value="$strategyOption->id" :wire:key="'strategy-filter-'.$strategyOption->id">
                        {{ $strategyOption->name }} ({{ $strategyOption->start_year }}–{{ $strategyOption->end_year }})
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:select :label="__('Manager')" :class="$controlClasses" wire:model.live="manager">
                <flux:select.option value="">{{ __('All managers') }}</flux:select.option>
                @foreach ($this->managerOptions as $managerOption)
                    <flux:select.option :value="$managerOption->id" :wire:key="'manager-filter-'.$managerOption->id">
                        {{ $managerOption->name_en }} · {{ $managerOption->name_ar }}
                    </flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </header>

    <div class="overflow-hidden rounded-lg border border-secondary-light bg-white dark:border-secondary-medium dark:bg-secondary-dark">
        <div class="flex min-h-10 flex-wrap items-center justify-between gap-2 border-b border-secondary-soft px-3.5 py-2 dark:border-secondary-medium">
            <p class="text-xs font-semibold text-secondary-medium dark:text-secondary-soft">
                {{ trans_choice('{1} :count matching program|[2,*] :count matching programs', $this->programs->total(), ['count' => $this->programs->total()]) }}
            </p>
            <div class="flex items-center gap-2">
                <span wire:loading.delay.flex wire:target="search,strategy,manager,clearFilters" class="hidden items-center gap-1.5 text-xs text-secondary-medium dark:text-secondary-soft">
                    <flux:icon.arrow-path class="size-3.5 animate-spin" /> {{ __('Updating') }}
                </span>
                @if ($filtersAreActive)
                    <flux:button size="sm" variant="ghost" class="text-secondary-medium! hover:bg-secondary-soft! hover:text-secondary-dark! dark:text-secondary-soft! dark:hover:bg-secondary-medium! dark:hover:text-white!" wire:click="clearFilters">
                        {{ __('Reset filters') }}
                    </flux:button>
                @endif
            </div>
        </div>

        @if ($this->programs->isEmpty())
            <div class="flex min-h-64 flex-col items-center justify-center gap-3 px-5 py-10 text-center">
                <div class="flex size-12 items-center justify-center rounded-lg border border-secondary-light bg-white text-secondary-medium dark:bg-secondary-dark dark:text-secondary-soft">
                    <flux:icon.briefcase class="size-5" />
                </div>
                <div class="max-w-sm space-y-1">
                    <flux:heading size="base">{{ $filtersAreActive ? __('No matching programs') : __('No programs from HR yet') }}</flux:heading>
                    <p class="text-sm text-secondary-medium dark:text-secondary-soft">
                        {{ $filtersAreActive ? __('Change the search or filters and try again.') : __('Programs appear here once they are added in the HR system.') }}
                    </p>
                </div>
                @if ($filtersAreActive)
                    <flux:button size="sm" :class="$secondaryButtonClasses" wire:click="clearFilters">{{ __('Reset filters') }}</flux:button>
                @endif
            </div>
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium @5xl/directory:hidden">
                @foreach ($this->programs as $program)
                    <article class="flex flex-col gap-3 p-3.5" wire:key="mobile-program-{{ $program->id }}">
                        <div class="flex min-w-0 items-start justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-3">
                                <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-secondary-soft text-secondary-dark dark:bg-secondary-medium dark:text-white">
                                    <flux:icon.briefcase class="size-4.5" />
                                </div>
                                <div class="min-w-0">
                                    <h2 class="truncate text-sm font-semibold text-secondary-dark dark:text-white"><a href="{{ route('programs.show', $program) }}" wire:navigate class="hover:underline">{{ $program->name }}</a></h2>
                                    <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">{{ $program->strategy?->name ?? __('Strategy not assigned') }}</p>
                                </div>
                            </div>
                            @if ($program->strategy)
                                <span class="inline-flex shrink-0 rounded-md bg-secondary-soft px-2 py-1 text-xs font-semibold tabular-nums text-secondary-dark dark:bg-secondary-medium dark:text-white">
                                    {{ $program->strategy->start_year }}–{{ $program->strategy->end_year }}
                                </span>
                            @endif
                        </div>

                        <div class="flex items-center gap-2.5 rounded-lg border border-secondary-soft p-2.5 dark:border-secondary-medium">
                            <x-ui.employee-chip :employee="$program->manager" />
                        </div>

                        <div class="flex min-h-11 items-center justify-between gap-3 border-t border-secondary-soft pt-2.5 dark:border-secondary-medium">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs font-medium text-secondary-medium dark:text-secondary-soft">
                                <span>{{ trans_choice(':count project|:count projects', $program->projects_count, ['count' => $program->projects_count]) }}</span>
                                <span>{{ trans_choice(':count annual plan|:count annual plans', $program->yearly_plans_count, ['count' => $program->yearly_plans_count]) }}</span>
                            </div>
                            <flux:button size="sm" icon="pencil-square" :class="$editButtonClasses.' size-11! shrink-0 touch-manipulation'" wire:click="openEditModal({{ $program->id }})" :aria-label="__('Edit :name', ['name' => $program->name])" />
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="hidden @5xl/directory:block">
                <flux:table class="table-fixed [&_[data-flux-cell]]:px-3.5! [&_[data-flux-cell]]:py-2.5! [&_[data-flux-column]]:px-3.5! [&_[data-flux-column]]:py-2.5! [&_tbody_tr]:transition-colors [&_tbody_tr]:hover:bg-secondary-soft dark:[&_tbody_tr]:hover:bg-secondary-medium">
                    <flux:table.columns class="bg-secondary-soft text-xs font-semibold dark:bg-secondary-medium">
                        <flux:table.column class="w-[22%]">{{ __('Program') }}</flux:table.column>
                        <flux:table.column class="w-[22%]">{{ __('Strategy') }}</flux:table.column>
                        <flux:table.column class="w-[26%]">{{ __('Manager') }}</flux:table.column>
                        <flux:table.column class="w-[14%]" align="center">{{ __('Delivery') }}</flux:table.column>
                        <flux:table.column class="w-[10%]" align="center">{{ __('Updated') }}</flux:table.column>
                        <flux:table.column class="w-[6%]" align="center">{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->programs as $program)
                            <flux:table.row :key="$program->id">
                                <flux:table.cell variant="strong" class="text-secondary-dark! dark:text-white!">
                                    <div class="flex min-w-0 items-center gap-2.5">
                                        <div class="flex size-8 shrink-0 items-center justify-center rounded-md bg-secondary-soft text-secondary-dark dark:bg-secondary-medium dark:text-white"><flux:icon.briefcase class="size-4" /></div>
                                        <a href="{{ route('programs.show', $program) }}" wire:navigate class="truncate font-semibold hover:underline">{{ $program->name }}</a>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if ($program->strategy)
                                        <div class="min-w-0">
                                            <p class="truncate font-medium text-secondary-dark dark:text-white">{{ $program->strategy->name }}</p>
                                            <p class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $program->strategy->start_year }}–{{ $program->strategy->end_year }}</p>
                                        </div>
                                    @else
                                        <p class="text-secondary-medium dark:text-secondary-soft">{{ __('Not assigned') }}</p>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    <x-ui.employee-chip :employee="$program->manager" />
                                </flux:table.cell>
                                <flux:table.cell align="center">
                                    <div class="inline-flex items-center gap-1.5" aria-label="{{ __(':projects projects and :plans annual plans', ['projects' => $program->projects_count, 'plans' => $program->yearly_plans_count]) }}">
                                        <span class="inline-flex min-w-8 items-center justify-center rounded-md bg-secondary-soft px-2 py-1 text-xs font-semibold text-secondary-dark dark:bg-secondary-medium dark:text-white">{{ $program->projects_count }}</span>
                                        <span class="text-secondary-light">/</span>
                                        <span class="inline-flex min-w-8 items-center justify-center rounded-md border border-secondary-light px-2 py-1 text-xs font-semibold text-secondary-dark dark:text-white">{{ $program->yearly_plans_count }}</span>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell align="center"><time datetime="{{ $program->updated_at?->toDateString() }}">{{ $program->updated_at?->format('M j, Y') }}</time></flux:table.cell>
                                <flux:table.cell align="center">
                                    <flux:button size="sm" icon="pencil-square" :class="$editButtonClasses.' size-8!'" wire:click="openEditModal({{ $program->id }})" :aria-label="__('Edit :name', ['name' => $program->name])" />
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>

            <div class="border-t border-secondary-soft px-3 pb-3 dark:border-secondary-medium">
                <flux:pagination :paginator="$this->programs" class="text-secondary-medium! [&_button]:text-secondary-medium! [&_button:hover]:bg-secondary-soft! dark:text-secondary-soft! dark:[&_button]:text-secondary-soft! dark:[&_button:hover]:bg-secondary-medium!" scroll-to="#program-directory-heading" />
            </div>
        @endif
    </div>

    <flux:modal wire:model="showProgramModal" class="w-full max-w-2xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveProgram" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ __('Assign :name', ['name' => $editingProgramName]) }}</flux:heading>
                <flux:subheading>{{ __('The program name is managed in the HR system. Link it to a strategy and an accountable manager.') }}</flux:subheading>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="strategyId" :class="$controlClasses" :label="__('Linked strategy')">
                    <flux:select.option value="">{{ __('Not assigned') }}</flux:select.option>
                    @foreach ($this->strategyOptions as $strategyOption)
                        <flux:select.option :value="$strategyOption->id" :wire:key="'strategy-option-'.$strategyOption->id">
                            {{ $strategyOption->name }} ({{ $strategyOption->start_year }}–{{ $strategyOption->end_year }})
                        </flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="managerId" :class="$controlClasses" :label="__('Direct manager')">
                    <flux:select.option value="">{{ __('Not assigned') }}</flux:select.option>
                    @foreach ($this->managerOptions as $managerOption)
                        <flux:select.option :value="$managerOption->id" :wire:key="'manager-option-'.$managerOption->id">
                            {{ $managerOption->name_en }} · {{ $managerOption->name_ar }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="rounded-lg border border-secondary-soft bg-secondary-soft/50 p-3 text-sm text-secondary-medium dark:border-secondary-medium dark:bg-secondary-medium/50 dark:text-secondary-soft">
                <div class="flex items-start gap-2.5">
                    <flux:icon.information-circle class="mt-0.5 size-4 shrink-0" />
                    <p>{{ __('The assigned manager is the HR employee directly accountable for this program.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <flux:button type="button" variant="filled" :class="$secondaryButtonClasses.' min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto'" wire:click="closeProgramModal">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" :class="$primaryButtonClasses.' min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto'" data-test="save-program-button">
                    {{ __('Save changes') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
