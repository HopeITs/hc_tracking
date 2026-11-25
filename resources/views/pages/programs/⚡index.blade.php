<?php

use App\Actions\Programs\SaveProgram;
use App\Enums\ProjectStatus;
use App\Support\Like;
use App\Models\HrEmployee;
use App\Models\Program;
use App\Models\Project;
use App\Models\Strategy;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::desk'), Title('titles.programs')] class extends Component {
    /** The strategy / manager filter value that lists programs with nobody linked. */
    private const Unassigned = 'none';

    /** SaveProgram's field names mapped to this form's properties, for its validation errors. */
    private const FormFields = [
        'name_en' => 'nameEn',
        'name_ar' => 'nameAr',
        'code' => 'code',
        'is_active' => 'isActive',
        'strategy_id' => 'strategyId',
        'manager_id' => 'managerId',
    ];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $strategy = '';

    #[Url(except: '')]
    public string $manager = '';

    #[Url(except: '')]
    public string $gap = '';

    /** '' lists every program; 'active' or 'inactive' narrows to that flag. */
    #[Url(except: '')]
    public string $status = '';

    public bool $showProgramModal = false;
    #[Locked]
    public ?int $editingProgramId = null;
    public string $editingProgramName = '';
    public string $nameEn = '';
    public string $nameAr = '';
    public string $code = '';
    public bool $isActive = true;
    public string $strategyId = '';
    public string $managerId = '';

    /**
     * The form as it was opened, for the modal's discard guard.
     *
     * @var array<string, string|bool>
     */
    #[Locked]
    public array $originalForm = [];

    public function filterByStrategy(?int $strategyId = null): void
    {
        $this->strategy = $strategyId === null ? '' : (string) $strategyId;
    }

    public function filterByManager(?int $managerId = null): void
    {
        $this->manager = $managerId === null ? '' : (string) $managerId;
    }

    public function updatedGap(): void
    {
        $this->gap = in_array($this->gap, $this->gapValues(), true) ? $this->gap : '';
    }

    public function updatedStatus(): void
    {
        $this->status = in_array($this->status, ['active', 'inactive'], true) ? $this->status : '';
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->strategy = '';
        $this->manager = '';
        $this->gap = '';
        $this->status = '';
    }

    public function showGap(string $gap): void
    {
        $this->gap = $gap;
        $this->updatedGap();
    }

    /**
     * The "Needs attention" choices this viewer may use; the proposal one is
     * funding data, so it is offered only to people who can see proposals.
     *
     * @return list<string>
     */
    protected function gapValues(): array
    {
        return array_values(array_filter(
            ['overdue', 'risk', 'no_proposal', 'no_plan', 'no_strategy', 'no_manager'],
            fn (string $gap): bool => $gap !== 'no_proposal' || $this->canSeeFunding(),
        ));
    }

    /** @return Collection<int, Program> */
    #[Computed]
    public function programs(): Collection
    {
        $counts = [
            'projects',
            'projects as open_projects_count' => fn (Builder $query) => $query->whereIn('status', Project::openStatusValues()),
        ];

        return $this->filteredProgramsQuery()
            ->select(['id', 'name_en', 'name_ar', 'slug', 'is_active', 'strategy_id', 'manager_id', 'updated_at'])
            ->with([
                'strategy:id,name,start_year,end_year',
                'manager:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
            ])
            ->withCount($counts)
            ->orderByName()
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
                $query->where(Like::everyWord($search, fn (Builder $query, string $word) => $query
                    ->whereIn('programs.id', Program::idsMatchingName($word, acrossLayouts: false))
                    ->orWhereHas('strategy', Like::word('name', $word))
                    ->orWhereIn('manager_id', HrEmployee::idsMatchingName($word, acrossLayouts: false))));
            })
            ->when($this->strategy === self::Unassigned, fn (Builder $query) => $query->whereNull('strategy_id'))
            ->when($this->strategy !== '' && $this->strategy !== self::Unassigned, fn (Builder $query) => $query->where('strategy_id', (int) $this->strategy))
            ->when($this->manager === self::Unassigned, fn (Builder $query) => $query->whereNull('manager_id'))
            ->when($this->manager !== '' && $this->manager !== self::Unassigned, fn (Builder $query) => $query->where('manager_id', (int) $this->manager))
            ->when($this->status === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($this->status === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->when($this->gap === 'no_strategy', fn (Builder $query) => $query->whereNull('strategy_id'))
            ->when($this->gap === 'no_manager', fn (Builder $query) => $query->whereNull('manager_id'))
            ->when($this->gap === 'overdue', fn (Builder $query) => $query->whereHas('projects', fn (Builder $projects) => $projects
                ->where('status', ProjectStatus::Active->value)
                ->whereDate('end_date', '<', today())))
            ->when($this->gap === 'risk', fn (Builder $query) => $query->whereHas('projects', fn (Builder $projects) => $projects
                ->whereIn('status', Project::openStatusValues())
                ->whereHas('risks', fn (Builder $risks) => $risks->where('happened', true))))
            ->when($this->gap === 'no_proposal' && $this->canSeeFunding(), fn (Builder $query) => $query->whereHas('projects', fn (Builder $projects) => $projects
                ->whereIn('status', Project::openStatusValues())
                ->doesntHave('proposals')))
            ->when($this->gap === 'no_plan', fn (Builder $query) => $query->whereDoesntHave(
                'annualPlans',
                fn (Builder $plans) => $plans->where('year', now()->year)->whereHas('planIndicators'),
            ));
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

        $names = HrEmployee::query()
            ->whereIn('id', $counts->keys())
            ->get(['id', 'first_name_en', 'last_name_en', 'first_name_ar', 'last_name_ar'])
            ->mapWithKeys(fn (HrEmployee $employee): array => [$employee->id => $employee->name]);

        return $counts
            ->map(fn (int|string $total, int|string $id): array => [
                'value' => (string) $id,
                // First-strong isolates keep "(2)" at the end of the line whatever script the name is in.
                'label' => "\u{2068}".($names->get((int) $id) ?: '#'.$id)."\u{2069} (".$total.')',
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
    public function canSeeFunding(): bool
    {
        return auth()->user()?->can('proposals.view') === true;
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', Program::class);

        $this->resetProgramForm();
        $this->rememberOriginalForm();
        $this->showProgramModal = true;
    }

    public function openEditModal(int $programId): void
    {
        $program = Program::query()
            ->select(['id', 'name_en', 'name_ar', 'code', 'is_active', 'strategy_id', 'manager_id'])
            ->findOrFail($programId);

        Gate::authorize('update', $program);

        $this->resetValidation();
        $this->editingProgramId = $program->id;
        $this->editingProgramName = $program->name;
        $this->nameEn = (string) $program->name_en;
        $this->nameAr = (string) $program->name_ar;
        $this->code = (string) $program->code;
        $this->isActive = $program->is_active;
        $this->strategyId = (string) $program->strategy_id;
        $this->managerId = (string) $program->manager_id;
        $this->rememberOriginalForm();
        $this->showProgramModal = true;
    }

    /**
     * Add a program or save its names, code, active flag, strategy and
     * manager (D-68: tracking owns programs). SaveProgram checks permission
     * and validates; its errors are shown on this form's fields.
     */
    public function saveProgram(): void
    {
        $creating = $this->editingProgramId === null;

        try {
            app(SaveProgram::class)->handle($this->editingProgramId, [
                'name_en' => $this->nameEn,
                'name_ar' => $this->nameAr,
                'code' => $this->code,
                'is_active' => $this->isActive,
                'strategy_id' => $this->strategyId,
                'manager_id' => $this->managerId,
            ]);
        } catch (ValidationException $exception) {
            $this->setErrorBag(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => [self::FormFields[$field] ?? $field => $messages])
                ->all());

            return;
        }

        $this->showProgramModal = false;
        $this->resetProgramForm();
        unset($this->programs, $this->programSummary, $this->unassignedCounts, $this->managerOptions);

        Flux::toast(variant: 'success', text: $creating ? __('programs.program_added') : __('programs.program_saved'));
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
        $this->nameEn = '';
        $this->nameAr = '';
        $this->code = '';
        $this->isActive = true;
        $this->strategyId = '';
        $this->managerId = '';
        $this->originalForm = [];
    }

    protected function rememberOriginalForm(): void
    {
        $this->originalForm = [
            'nameEn' => $this->nameEn,
            'nameAr' => $this->nameAr,
            'code' => $this->code,
            'isActive' => $this->isActive,
            'strategyId' => $this->strategyId,
            'managerId' => $this->managerId,
        ];
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $strategy !== '' || $manager !== '' || $gap !== '' || $status !== '';
    $summary = $this->programSummary;
    $visiblePrograms = $this->programs;
@endphp

<x-desk.register class="admin-directory @container/directory" heading-id="program-directory-heading" :title="__('common.programs')" :count="$summary['total']">
        @can('programs.create')
        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-program-button" data-quick-create>
                {{ __('programs.add_program') }}
            </x-ui.button>
        </x-slot:actions>
    @endcan

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

            <x-ui.header-filter-select id="program-attention" :label="__('programs.needs_attention')" wire:model.live="gap" :value="$gap">
                <option value="">{{ __('programs.attention_all') }}</option>
                <option value="overdue">{{ __('programs.attention_overdue') }}</option>
                <option value="risk">{{ __('programs.attention_risk') }}</option>
                @if ($this->canSeeFunding())
                    <option value="no_proposal">{{ __('programs.attention_no_proposal') }}</option>
                @endif
                <option value="no_plan">{{ __('programs.gap_no_plan', ['year' => now()->year]) }}</option>
                <option value="no_strategy">{{ __('programs.gap_no_strategy') }}</option>
                <option value="no_manager">{{ __('programs.gap_no_manager') }}</option>
            </x-ui.header-filter-select>

            <x-ui.header-filter-select id="program-status" :label="__('programs.status')" wire:model.live="status" :value="$status">
                <option value="">{{ __('programs.status_all') }}</option>
                <option value="active">{{ __('programs.status_active') }}</option>
                <option value="inactive">{{ __('programs.status_inactive') }}</option>
            </x-ui.header-filter-select>
        </x-slot:filters>
    
    <div class="flex justify-end"><span wire:loading.delay.flex wire:target="search,strategy,manager,gap,status,clearFilters" class="hidden items-center gap-1.5 text-xs text-secondary-medium dark:text-ink-muted"><flux:icon.arrow-path class="size-3.5 animate-spin" /> {{ __('common.updating') }}</span>@if ($filtersAreActive && $visiblePrograms->isNotEmpty())<flux:button size="sm" variant="ghost" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>@endif</div>

        @if ($visiblePrograms->isEmpty())
            <x-ui.empty-state icon="briefcase" :heading="$filtersAreActive ? __('programs.no_matching_programs') : __('programs.no_programs_yet')" :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('programs.programs_appear_here_once_added')">
                @if ($filtersAreActive)
                    <x-slot:action>
                        <x-ui.button size="sm" wire:click="clearFilters">{{ __('common.reset_filters') }}</x-ui.button>
                    </x-slot:action>
                @endif
            </x-ui.empty-state>
        @else
            <div class="grid gap-6 @3xl/directory:grid-cols-2 @6xl/directory:grid-cols-4">
                @foreach ($visiblePrograms as $program)
                    <article class="group relative flex min-w-0 flex-col gap-4 rounded-lg border border-secondary-soft bg-white p-4 transition-colors duration-200 hover:border-primary focus-within:border-secondary-medium dark:border-line dark:bg-surface-1 dark:hover:border-primary" wire:key="program-card-{{ $program->id }}">
                        <a href="{{ route('programs.show', $program) }}" wire:navigate class="absolute inset-0 z-0 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium" tabindex="-1" aria-hidden="true"></a>
                        <div class="relative z-10 flex items-start justify-between gap-3 pointer-events-none"><div class="flex min-w-0 items-center gap-3"><div class="grid size-10 shrink-0 place-items-center rounded-md bg-primary text-white"><flux:icon.briefcase class="size-5" /></div><div class="min-w-0"><h2 class="line-clamp-2 break-words text-base font-semibold text-secondary-dark dark:text-ink" title="{{ $program->name }}">{{ $program->name }}</h2>@unless ($program->is_active)<span class="mt-1 inline-flex items-center rounded-full border border-secondary-light px-2 py-0.5 text-[11px] font-semibold leading-4 text-secondary-dark dark:border-line-strong dark:text-ink" data-program-inactive>{{ __('programs.inactive') }}</span>@endunless</div></div><div class="pointer-events-auto flex shrink-0 items-center gap-1"><a href="{{ route('programs.show', $program) }}" wire:navigate class="grid size-11 place-items-center rounded-md border-0! bg-transparent! text-secondary-dark! transition-colors hover:bg-secondary-soft! dark:text-ink! dark:hover:bg-surface-3! focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium @3xl/directory:size-8!" aria-label="{{ __('programs.open_name', ['name' => $program->name]) }}"><flux:icon.eye class="size-4.5" /></a>@can('programs.update')<x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-11! touch-manipulation hover:bg-secondary-soft! dark:hover:bg-surface-3! @3xl/directory:size-8!" wire:click="openEditModal({{ $program->id }})" aria-label="{{ __('programs.edit_name', ['name' => $program->name]) }}" title="{{ __('programs.edit_program') }}" />@endcan</div></div>
                        <dl class="relative z-10 grid grid-cols-2 gap-4 pointer-events-none"><div class="min-w-0"><dt class="text-[11px] font-semibold uppercase tracking-[0.08em] text-secondary-medium dark:text-ink-muted">{{ __('common.strategy') }}</dt>@if ($program->strategy)<dd class="mt-1 truncate text-sm font-medium text-secondary-dark dark:text-ink" dir="auto" title="{{ $program->strategy->name }}">{{ $program->strategy->name }}</dd>@if (! str_contains($program->strategy->name, (string) $program->strategy->start_year))<p class="text-xs tabular-nums text-secondary-medium dark:text-ink-muted">{{ $program->strategy->start_year }}–{{ $program->strategy->end_year }}</p>@endif @else<dd class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-medium text-status-warn" data-program-unlinked>{{ __('programs.not_linked_yet') }}@can('programs.update')<button type="button" class="pointer-events-auto inline-flex min-h-11 items-center rounded-md px-2 text-xs font-semibold text-secondary-dark underline underline-offset-2 hover:bg-secondary-soft dark:text-ink dark:hover:bg-surface-3 @3xl/directory:min-h-8" wire:click="openEditModal({{ $program->id }})" aria-label="{{ __('programs.assign_name_short', ['name' => $program->name]) }}">{{ __('programs.assign') }}</button>@endcan</dd>@endif</div><div class="min-w-0"><dt class="text-[11px] font-semibold uppercase tracking-[0.08em] text-secondary-medium dark:text-ink-muted">{{ __('programs.manager') }}</dt><dd class="mt-1">@if ($program->manager)<x-ui.employee-chip :employee="$program->manager" compact />@else<span class="text-sm text-secondary-medium dark:text-ink-muted">{{ __('common.not_assigned') }}</span>@endif</dd></div></dl>
                        <div class="relative z-10 mt-auto grid grid-cols-2 border-t border-secondary-soft pt-3 dark:border-line pointer-events-none"><div><p class="text-[11px] font-semibold uppercase tracking-[0.08em] text-secondary-medium dark:text-ink-muted">{{ __('programs.open_projects') }}</p><p class="mt-1 text-2xl font-bold tabular-nums text-secondary-dark dark:text-ink" data-program-open-projects>{{ $program->open_projects_count }}</p></div><div><p class="text-[11px] font-semibold uppercase tracking-[0.08em] text-secondary-medium dark:text-ink-muted">{{ __('programs.closed_projects') }}</p><p class="mt-1 text-2xl font-bold tabular-nums text-secondary-dark dark:text-ink" data-program-closed-projects>{{ $program->projects_count - $program->open_projects_count }}</p></div></div>
                    </article>
                @endforeach
            </div>
        @endif

    <x-ui.modal
        wire:model="showProgramModal"
        size="md"
        :title="$editingProgramId === null ? __('programs.add_program') : __('programs.edit_name', ['name' => $editingProgramName])"
        :description="__('programs.program_form_description')"
        submit="saveProgram"
        close="closeProgramModal"
        :discard-guard="$showProgramModal ? ['fields' => array_keys($originalForm), 'original' => $originalForm] : null"
    >
        @if ($showProgramModal)
            <div class="grid gap-y-4">
                <x-ui.form-input wire:model="nameEn" :label="__('programs.name_en')" maxlength="255" required autofocus dir="ltr" />
                <x-ui.form-input wire:model="nameAr" :label="__('programs.name_ar')" maxlength="255" dir="rtl" />
                <x-ui.form-input wire:model="code" :label="__('programs.code')" :help="__('programs.code_help')" maxlength="50" dir="ltr" />

                <flux:checkbox wire:model="isActive" :label="__('programs.active')" :description="__('programs.active_help')" data-test="program-active" />

                <x-ui.form-select wire:model="strategyId" :label="__('programs.linked_strategy')">
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
