<?php

use App\Enums\MilestoneStatus;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('titles.milestones')] class extends Component {
    #[Url(except: '')]
    public string $project = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $responsible = '';

    #[Url(as: 'view', except: 'timeline')]
    public string $view = 'timeline';

    public bool $showMilestoneModal = false;
    public ?int $editingMilestoneId = null;
    public ?int $deletingMilestoneId = null;
    public string $name = '';
    public string $description = '';
    public string $plannedDate = '';
    public string $actualDate = '';
    public string $milestoneStatus = 'pending';
    public string $sequence = '1';
    public string $weightPercentage = '0';
    public string $responsibleUserId = '';
    public string $milestoneProjectId = '';
    public string $parentMilestoneId = '';

    public function clearFilters(): void
    {
        $this->project = '';
        $this->status = '';
        $this->responsible = '';
    }

    public function filterByProject(?int $projectId = null): void
    {
        $this->project = $projectId === null ? '' : (string) $projectId;
    }

    public function filterByStatus(?string $status = null): void
    {
        $this->status = $status !== null && MilestoneStatus::tryFrom($status) !== null ? $status : '';
    }

    public function filterByResponsible(?int $responsibleId = null): void
    {
        $this->responsible = $responsibleId === null ? '' : (string) $responsibleId;
    }

    public function updatedMilestoneProjectId(): void
    {
        if ($this->editingMilestoneId === null) {
            $this->parentMilestoneId = '';
        }

        unset($this->parentOptions);
    }

    /**
     * Projects carrying matching milestones, with ancestors retained so every
     * result remains understandable in its hierarchy.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projectsWithMilestones(): Collection
    {
        $projects = Project::query()
            ->select(['id', 'name', 'city_id'])
            ->when($this->project !== '', fn (Builder $query) => $query->whereKey((int) $this->project))
            ->with([
                'city:id,name_en,name_ar',
                'milestones' => fn ($query) => $query
                    ->select([
                        'id',
                        'project_id',
                        'name',
                        'description',
                        'planned_date',
                        'actual_date',
                        'status',
                        'sequence',
                        'weight_percentage',
                        'responsible_user_id',
                        'parent_milestone_id',
                    ])
                    ->with(['responsibleUser:id,first_name_en,last_name_en,first_name_ar,last_name_ar'])
                    ->withCount(['children', 'tasks'])
                    ->orderBy('sequence')
                    ->orderBy('id'),
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return $projects
            ->map(function (Project $project): Project {
                $childrenByParent = $project->milestones->groupBy(
                    fn (Milestone $milestone): string => $milestone->parent_milestone_id === null
                        ? 'root'
                        : (string) $milestone->parent_milestone_id,
                );

                $buildVisibleBranch = function (?int $parentId) use (&$buildVisibleBranch, $childrenByParent): Collection {
                    $key = $parentId === null ? 'root' : (string) $parentId;

                    /** @var Collection<int, Milestone> $children */
                    $children = $childrenByParent->get($key, new Collection);

                    return $children
                        ->map(function (Milestone $milestone) use (&$buildVisibleBranch): Milestone {
                            $milestone->setRelation('childrenRecursive', $buildVisibleBranch($milestone->id));

                            return $milestone;
                        })
                        ->filter(fn (Milestone $milestone): bool => $this->matchesMilestoneFilters($milestone)
                            || $milestone->childrenRecursive->isNotEmpty())
                        ->values();
                };

                $project->setRelation('milestones', $buildVisibleBranch(null));

                return $project;
            })
            ->filter(fn (Project $project): bool => $project->milestones->isNotEmpty())
            ->values();
    }

    /**
     * Delivery health across the currently filtered milestones.
     *
     * @return array{total: int, reached: int, overdue: int, missed: int, due_soon: int}
     */
    #[Computed]
    public function milestoneStats(): array
    {
        $today = CarbonImmutable::today();

        $milestones = $this->projectsWithMilestones
            ->flatMap(fn (Project $project): Collection => $this->flattenMilestones($project->milestones));

        return [
            'total' => $milestones->count(),
            'reached' => $milestones->where('status', MilestoneStatus::Reached)->count(),
            'overdue' => $milestones->filter(
                fn (Milestone $milestone): bool => $milestone->status === MilestoneStatus::Pending && $milestone->planned_date->lt($today),
            )->count(),
            'missed' => $milestones->where('status', MilestoneStatus::Missed)->count(),
            'due_soon' => $milestones->filter(
                fn (Milestone $milestone): bool => $milestone->status === MilestoneStatus::Pending && $milestone->planned_date->between($today, $today->addDays(7)),
            )->count(),
        ];
    }

    /** @return Collection<int, Project> */
    #[Computed]
    public function projectOptions(): Collection
    {
        return Project::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    /** @return Collection<int, HrEmployee> */
    #[Computed]
    public function userOptions(): Collection
    {
        return HrEmployee::pickerOptions();
    }

    /** @return Collection<int, Milestone> */
    #[Computed]
    public function parentOptions(): Collection
    {
        if ($this->milestoneProjectId === '') {
            return new Collection;
        }

        $invalidParentIds = $this->invalidParentIds();

        return Milestone::query()
            ->select(['id', 'name', 'project_id', 'sequence'])
            ->where('project_id', $this->parentProjectId())
            ->when($invalidParentIds !== [], fn (Builder $query) => $query->whereNotIn('id', $invalidParentIds))
            ->orderBy('sequence')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function canDelete(int $milestoneId): bool
    {
        $milestone = Milestone::query()
            ->select(['id'])
            ->withCount(['children', 'tasks'])
            ->find($milestoneId);

        return $milestone !== null
            && $milestone->children_count === 0
            && $milestone->tasks_count === 0;
    }

    public function openCreateModal(): void
    {
        $this->resetMilestoneForm();
        $this->showMilestoneModal = true;
    }

    public function openEditModal(int $milestoneId): void
    {
        $milestone = Milestone::query()
            ->select(['id', 'project_id', 'name', 'description', 'planned_date', 'actual_date', 'status', 'sequence', 'weight_percentage', 'responsible_user_id', 'parent_milestone_id'])
            ->findOrFail($milestoneId);

        $this->resetValidation();
        $this->editingMilestoneId = $milestone->id;
        $this->name = $milestone->name;
        $this->description = $milestone->description ?? '';
        $this->plannedDate = $milestone->planned_date->toDateString();
        $this->actualDate = $milestone->actual_date?->toDateString() ?? '';
        $this->milestoneStatus = $milestone->status->value;
        $this->sequence = (string) $milestone->sequence;
        $this->weightPercentage = $milestone->weight_percentage;
        $this->responsibleUserId = (string) $milestone->responsible_user_id;
        $this->milestoneProjectId = (string) $milestone->project_id;
        $this->parentMilestoneId = (string) ($milestone->parent_milestone_id ?? '');
        $this->showMilestoneModal = true;
    }

    public function saveMilestone(): void
    {
        $this->name = trim($this->name);
        $this->description = trim($this->description);

        $existingMilestone = $this->editingMilestoneId === null
            ? null
            : Milestone::query()->findOrFail($this->editingMilestoneId);
        $projectId = $existingMilestone?->project_id ?? (int) $this->milestoneProjectId;
        $invalidParentIds = $this->invalidParentIds();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'plannedDate' => ['required', 'date'],
            'actualDate' => ['nullable', 'date'],
            'milestoneStatus' => ['required', new Enum(MilestoneStatus::class)],
            'sequence' => ['required', 'integer', 'min:0'],
            'weightPercentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'responsibleUserId' => ['required', 'integer', Rule::exists(HrEmployee::class, 'id')],
            'milestoneProjectId' => [
                'required',
                'integer',
                Rule::exists(Project::class, 'id'),
                Rule::when($existingMilestone !== null, Rule::in([(string) $existingMilestone?->project_id])),
            ],
            'parentMilestoneId' => [
                'nullable',
                'integer',
                Rule::exists(Milestone::class, 'id')->where(
                    fn ($query) => $query->where('project_id', $projectId),
                ),
                Rule::notIn($invalidParentIds),
            ],
        ], [
            'name.required' => __('milestones.enter_a_milestone_name'),
            'plannedDate.required' => __('milestones.set_the_planned_date'),
            'responsibleUserId.required' => __('milestones.select_the_responsible_staff_member'),
            'milestoneProjectId.required' => __('milestones.select_the_project'),
            'milestoneProjectId.in' => __('milestones.a_milestone_cannot_be_moved_to_another_project'),
            'parentMilestoneId.exists' => __('milestones.select_a_milestone_from_this_project'),
            'parentMilestoneId.not_in' => __('milestones.a_milestone_cannot_be_nested_under_itself_or_its_descendants'),
            'weightPercentage.max' => __('milestones.weight_cannot_exceed_100_percent'),
        ]);

        $milestone = $existingMilestone ?? new Milestone;
        $attributes = [
            'name' => $validated['name'],
            'description' => $validated['description'] === '' ? null : $validated['description'],
            'planned_date' => $validated['plannedDate'],
            'actual_date' => $validated['actualDate'] === '' ? null : $validated['actualDate'],
            'status' => $validated['milestoneStatus'],
            'sequence' => (int) $validated['sequence'],
            'weight_percentage' => $validated['weightPercentage'],
            'responsible_user_id' => (int) $validated['responsibleUserId'],
            'parent_milestone_id' => $validated['parentMilestoneId'] === '' || $validated['parentMilestoneId'] === null
                ? null
                : (int) $validated['parentMilestoneId'],
        ];

        if ($existingMilestone === null) {
            $attributes['project_id'] = (int) $validated['milestoneProjectId'];
        }

        $milestone->fill($attributes)->save();
        $wasRecentlyCreated = $milestone->wasRecentlyCreated;

        $this->showMilestoneModal = false;
        $this->resetMilestoneForm();
        unset($this->projectsWithMilestones);

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('milestones.milestone_created') : __('milestones.milestone_updated'),
        );
    }

    public function quickSetStatus(int $milestoneId, string $status): void
    {
        $newStatus = MilestoneStatus::tryFrom($status);
        $milestone = Milestone::query()->find($milestoneId);

        if ($newStatus === null || $milestone === null) {
            return;
        }

        $milestone->status = $newStatus;
        $milestone->save();
        unset($this->projectsWithMilestones);

        Flux::toast(variant: 'success', text: __('milestones.milestone_updated'));
    }

    public function confirmDelete(int $milestoneId): void
    {
        $this->deletingMilestoneId = $milestoneId;
    }

    public function deleteMilestone(): void
    {
        if ($this->deletingMilestoneId === null) {
            return;
        }

        $milestoneId = $this->deletingMilestoneId;
        $deleted = DB::transaction(function () use ($milestoneId): bool {
            $milestone = Milestone::query()->lockForUpdate()->find($milestoneId);

            if ($milestone === null || $milestone->children()->exists() || $milestone->tasks()->exists()) {
                return false;
            }

            return (bool) $milestone->delete();
        });

        $this->deletingMilestoneId = null;
        unset($this->projectsWithMilestones);

        if (! $deleted) {
            Flux::toast(
                variant: 'danger',
                text: __('milestones.this_milestone_still_has_nested_milestones_or_tasks_move_or'),
            );

            return;
        }

        Flux::toast(variant: 'success', text: __('milestones.milestone_deleted'));
    }

    public function closeMilestoneModal(): void
    {
        $this->showMilestoneModal = false;
        $this->resetMilestoneForm();
    }

    protected function resetMilestoneForm(): void
    {
        $this->resetValidation();
        $this->editingMilestoneId = null;
        $this->name = '';
        $this->description = '';
        $this->plannedDate = '';
        $this->actualDate = '';
        $this->milestoneStatus = 'pending';
        $this->sequence = '1';
        $this->weightPercentage = '0';
        $this->responsibleUserId = '';
        $this->milestoneProjectId = '';
        $this->parentMilestoneId = '';
        unset($this->parentOptions);
    }

    /**
     * Flattens a filtered milestone tree (as returned by projectsWithMilestones)
     * into a single ordered list for the timeline and board views.
     *
     * @param  Collection<int, Milestone>  $milestones
     * @return Collection<int, Milestone>
     */
    public function flattenMilestones(Collection $milestones): Collection
    {
        return $milestones->reduce(
            fn (Collection $flat, Milestone $milestone): Collection => $flat
                ->push($milestone)
                ->merge($this->flattenMilestones($milestone->childrenRecursive)),
            new Collection,
        );
    }

    protected function matchesMilestoneFilters(Milestone $milestone): bool
    {
        return ($this->status === '' || $milestone->status->value === $this->status)
            && ($this->responsible === '' || $milestone->responsible_user_id === (int) $this->responsible);
    }

    /** @return array<int, int> */
    protected function invalidParentIds(): array
    {
        if ($this->editingMilestoneId === null) {
            return [];
        }

        $milestone = Milestone::query()
            ->select(['id', 'parent_milestone_id'])
            ->with('childrenRecursive:id,parent_milestone_id,sequence')
            ->find($this->editingMilestoneId);

        if ($milestone === null) {
            return [];
        }

        return [...$milestone->descendants()->modelKeys(), $milestone->id];
    }

    protected function parentProjectId(): int
    {
        if ($this->editingMilestoneId !== null) {
            return (int) Milestone::query()->whereKey($this->editingMilestoneId)->value('project_id');
        }

        return (int) $this->milestoneProjectId;
    }
};
?>

@php
    $filtersAreActive = $project !== '' || $status !== '' || $responsible !== '';
    $totalMilestones = $this->projectsWithMilestones->sum(fn ($projectRow) => $this->flattenMilestones($projectRow->milestones)->count());
    $selectedProject = $project === '' ? null : $this->projectOptions->firstWhere('id', (int) $project);
    $selectedResponsible = $responsible === '' ? null : $this->userOptions->firstWhere('id', (int) $responsible);
@endphp

<section class="admin-directory @container/directory flex min-h-full w-full flex-1 flex-col gap-5 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="milestone-directory-heading">
    <header class="grid gap-8 border-b border-secondary-soft pb-7 @6xl/directory:grid-cols-[minmax(16rem,.7fr)_minmax(0,1.3fr)] @6xl/directory:items-center dark:border-secondary-medium">
        <div class="min-w-0">
            <div class="flex items-center gap-2.5">
                <flux:heading id="milestone-directory-heading" size="xl" class="text-3xl! font-bold! leading-none!">{{ __('common.milestones') }}</flux:heading>
                <span data-count-badge class="inline-flex min-w-7 items-center justify-center rounded-md bg-primary px-2 py-0.5 text-xs font-bold tabular-nums text-white">{{ $totalMilestones }}</span>
            </div>
        </div>

        <div class="directory-filters grid gap-3 @2xl/directory:grid-cols-2 @6xl/directory:grid-cols-4">
            <div class="min-w-0">
                <span aria-hidden="true" class="mb-1.5 hidden text-[11px] font-semibold uppercase tracking-[0.1em] opacity-0 @2xl/directory:block">&nbsp;</span>
                <x-ui.button tone="primary" icon="plus" class="min-h-11 w-full touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-milestone-button">
                    {{ __('milestones.add_milestone') }}
                </x-ui.button>
            </div>

            <div class="min-w-0">
                <label for="milestone-project-filter" class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.1em] text-secondary-medium dark:text-secondary-soft">{{ __('common.project') }}</label>
                <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!" x-data="{ optionSearch: '' }">
                    <flux:button id="milestone-project-filter" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('common.project')">
                        <span class="truncate">{{ $selectedProject?->name ?? __('common.all_projects') }}</span>
                    </flux:button>
                    <flux:menu class="filter-menu">
                        @if ($this->projectOptions->count() > 6)
                            <div class="border-b border-secondary-soft p-1.5 dark:border-secondary-medium">
                                <x-ui.input type="search" size="sm" icon="magnifying-glass" x-model="optionSearch" :aria-label="__('milestones.search_projects')" :placeholder="__('milestones.search_projects')" />
                            </div>
                        @endif
                        <flux:menu.item wire:click="filterByProject">
                            {{ __('common.all_projects') }}
                            @if ($project === '')<flux:icon.check class="ms-auto size-4 text-secondary-medium" />@endif
                        </flux:menu.item>
                        @foreach ($this->projectOptions as $projectOption)
                            <flux:menu.item wire:click="filterByProject({{ $projectOption->id }})" x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())" :wire:key="'milestone-project-filter-'.$projectOption->id">
                                <span class="min-w-0 truncate">{{ $projectOption->name }}</span>
                                @if ((int) $project === $projectOption->id)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                            </flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
            </div>

            <div class="min-w-0">
                <label for="milestone-status-filter" class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.1em] text-secondary-medium dark:text-secondary-soft">{{ __('common.status') }}</label>
                <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!">
                    <flux:button id="milestone-status-filter" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('common.status')">
                        <span class="truncate">{{ $status === '' ? __('common.all_statuses') : MilestoneStatus::from($status)->label() }}</span>
                    </flux:button>
                    <flux:menu class="filter-menu">
                        <flux:menu.item wire:click="filterByStatus">
                            {{ __('common.all_statuses') }}
                            @if ($status === '')<flux:icon.check class="ms-auto size-4 text-secondary-medium" />@endif
                        </flux:menu.item>
                        @foreach (MilestoneStatus::cases() as $statusCase)
                            <flux:menu.item wire:click="filterByStatus('{{ $statusCase->value }}')" :wire:key="'milestone-status-filter-'.$statusCase->value">
                                <span class="min-w-0 truncate">{{ $statusCase->label() }}</span>
                                @if ($status === $statusCase->value)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                            </flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
            </div>

            <div class="min-w-0">
                <label for="milestone-responsible-filter" class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.1em] text-secondary-medium dark:text-secondary-soft">{{ __('milestones.responsible') }}</label>
                <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!" x-data="{ optionSearch: '' }">
                    <flux:button id="milestone-responsible-filter" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('milestones.responsible')">
                        <span class="truncate">{{ $selectedResponsible?->name ?? __('common.anyone') }}</span>
                    </flux:button>
                    <flux:menu class="filter-menu">
                        @if ($this->userOptions->count() > 6)
                            <div class="border-b border-secondary-soft p-1.5 dark:border-secondary-medium">
                                <x-ui.input type="search" size="sm" icon="magnifying-glass" x-model="optionSearch" :aria-label="__('milestones.search_responsible')" :placeholder="__('milestones.search_responsible')" />
                            </div>
                        @endif
                        <flux:menu.item wire:click="filterByResponsible">
                            {{ __('common.anyone') }}
                            @if ($responsible === '')<flux:icon.check class="ms-auto size-4 text-secondary-medium" />@endif
                        </flux:menu.item>
                        @foreach ($this->userOptions as $userOption)
                            <flux:menu.item wire:click="filterByResponsible({{ $userOption->id }})" x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())" :wire:key="'milestone-user-filter-'.$userOption->id">
                                <span class="min-w-0 truncate">{{ $userOption->name }} · {{ $userOption->secondary_name }}</span>
                                @if ((int) $responsible === $userOption->id)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                            </flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
            </div>
        </div>
    </header>

    <div class="grid gap-3 @2xl/directory:grid-cols-3 @5xl/directory:grid-cols-5">
        <x-ui.stat-tile :label="__('milestones.total_milestones')" :value="$this->milestoneStats['total']" icon="flag" wire:key="milestone-stat-total" />
        <x-ui.stat-tile :label="MilestoneStatus::Reached->label()" :value="$this->milestoneStats['reached']" icon="check-circle" tone="good" wire:key="milestone-stat-reached" />
        <x-ui.stat-tile :label="__('milestones.timeline_overdue')" :value="$this->milestoneStats['overdue']" icon="exclamation-triangle" tone="warn" wire:key="milestone-stat-overdue" />
        <x-ui.stat-tile :label="MilestoneStatus::Missed->label()" :value="$this->milestoneStats['missed']" icon="x-circle" tone="critical" wire:key="milestone-stat-missed" />
        <x-ui.stat-tile :label="__('milestones.due_within_7_days')" :value="$this->milestoneStats['due_soon']" icon="clock" wire:key="milestone-stat-due-soon" />
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex flex-wrap gap-1 rounded-lg border border-secondary-soft bg-secondary-soft/40 p-1 dark:border-secondary-medium dark:bg-secondary-medium/40" role="group" aria-label="{{ __('milestones.view') }}">
            @foreach ([
                'list' => ['icon' => 'queue-list', 'label' => __('milestones.list_view')],
                'timeline' => ['icon' => 'chart-bar', 'label' => __('milestones.timeline_view')],
                'board' => ['icon' => 'view-columns', 'label' => __('milestones.board_view')],
            ] as $viewOption => $viewMeta)
                <button
                    type="button"
                    wire:click="$set('view', '{{ $viewOption }}')"
                    aria-pressed="{{ $view === $viewOption ? 'true' : 'false' }}"
                    data-test="milestone-view-{{ $viewOption }}"
                    @class([
                        'inline-flex min-h-9 cursor-pointer items-center gap-1.5 rounded-md px-3 text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                        'bg-white text-secondary-dark shadow-sm dark:bg-secondary-dark dark:text-white' => $view === $viewOption,
                        'text-secondary-medium hover:text-secondary-dark dark:text-secondary-soft dark:hover:text-white' => $view !== $viewOption,
                    ])
                >
                    <flux:icon :name="$viewMeta['icon']" variant="micro" class="size-4" aria-hidden="true" />
                    {{ $viewMeta['label'] }}
                </button>
            @endforeach
        </div>

        <div class="flex shrink-0 items-center gap-2">
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
            @endif
        </div>
    </div>

    @if ($this->projectsWithMilestones->isEmpty())
        <x-ui.panel>
            <x-ui.empty-state icon="flag" :heading="$filtersAreActive ? __('milestones.no_matching_milestones') : __('common.no_milestones_yet')" :body="$filtersAreActive ? __('common.change_the_filters_and_try_again') : __('milestones.add_the_first_milestone_to_a_project')">
                <x-slot:action>
                    @if ($filtersAreActive)
                        <x-ui.button size="sm" wire:click="clearFilters">{{ __('common.reset_filters') }}</x-ui.button>
                    @else
                        <x-ui.button size="sm" tone="primary" icon="plus" wire:click="openCreateModal">{{ __('milestones.add_milestone') }}</x-ui.button>
                    @endif
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.panel>
    @else
        @switch ($view)
            @case ('timeline')
                @include('pages.milestones._timeline', ['projects' => $this->projectsWithMilestones])
                @break

            @case ('board')
                @include('pages.milestones._board', ['projects' => $this->projectsWithMilestones])
                @break

            @default
                @include('pages.milestones._list', ['projects' => $this->projectsWithMilestones])
        @endswitch
    @endif

    @php
        $selectedMilestoneProject = $milestoneProjectId === '' ? null : $this->projectOptions->firstWhere('id', (int) $milestoneProjectId);
        $selectedParentMilestone = $parentMilestoneId === '' ? null : $this->parentOptions->firstWhere('id', (int) $parentMilestoneId);
        $selectedResponsibleOption = $responsibleUserId === '' ? null : $this->userOptions->firstWhere('id', (int) $responsibleUserId);
        $dropdownLabelClasses = 'mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.1em] text-secondary-medium dark:text-secondary-soft';
    @endphp

    <flux:modal wire:model="showMilestoneModal" class="w-full max-w-3xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveMilestone" class="flex flex-col gap-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingMilestoneId === null ? __('milestones.add_milestone') : __('milestones.edit_milestone') }}</flux:heading>
            </div>

            <x-ui.input wire:model="name" :label="__('milestones.milestone_name')" type="text" maxlength="255" required autofocus />

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="min-w-0">
                    <label for="milestone-form-project" class="{{ $dropdownLabelClasses }}">{{ __('common.project') }}</label>
                    @if ($editingMilestoneId !== null)
                        <flux:tooltip :content="__('milestones.a_milestone_cannot_be_moved_to_another_project')">
                            @include('pages.milestones._project-dropdown', ['disabled' => true])
                        </flux:tooltip>
                    @else
                        @include('pages.milestones._project-dropdown', ['disabled' => false])
                    @endif
                    <flux:error name="milestoneProjectId" class="mt-1.5" />
                </div>

                <div class="min-w-0">
                    <label for="milestone-form-parent" class="{{ $dropdownLabelClasses }}">{{ __('milestones.nest_under') }}</label>
                    <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!" x-data="{ optionSearch: '' }">
                        <flux:button id="milestone-form-parent" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('milestones.nest_under')">
                            <span class="truncate">{{ $selectedParentMilestone?->name ?? __('common.top_level') }}</span>
                        </flux:button>
                        <flux:menu class="filter-menu">
                            @if ($this->parentOptions->count() > 6)
                                <div class="border-b border-secondary-soft p-1.5 dark:border-secondary-medium">
                                    <x-ui.input type="search" size="sm" icon="magnifying-glass" x-model="optionSearch" :aria-label="__('milestones.search_milestones')" :placeholder="__('milestones.search_milestones')" />
                                </div>
                            @endif
                            <flux:menu.item wire:click="$set('parentMilestoneId', '')">
                                {{ __('common.top_level') }}
                                @if ($parentMilestoneId === '')<flux:icon.check class="ms-auto size-4 text-secondary-medium" />@endif
                            </flux:menu.item>
                            @foreach ($this->parentOptions as $parentOption)
                                <flux:menu.item wire:click="$set('parentMilestoneId', {{ $parentOption->id }})" x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())" :wire:key="'milestone-form-parent-option-'.$parentOption->id">
                                    <span class="min-w-0 truncate">{{ $parentOption->name }}</span>
                                    @if ((int) $parentMilestoneId === $parentOption->id)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                    <flux:error name="parentMilestoneId" class="mt-1.5" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input wire:model="plannedDate" :label="__('milestones.planned_date_2')" type="date" required />
                <x-ui.input wire:model="actualDate" :label="__('milestones.actual_date')" type="date" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="min-w-0">
                    <label for="milestone-form-status" class="{{ $dropdownLabelClasses }}">{{ __('common.status') }}</label>
                    <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!">
                        <flux:button id="milestone-form-status" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('common.status')">
                            <span class="truncate">{{ MilestoneStatus::from($milestoneStatus)->label() }}</span>
                        </flux:button>
                        <flux:menu class="filter-menu">
                            @foreach (MilestoneStatus::cases() as $statusCase)
                                <flux:menu.item wire:click="$set('milestoneStatus', '{{ $statusCase->value }}')" :wire:key="'milestone-form-status-option-'.$statusCase->value">
                                    <span class="min-w-0 truncate">{{ $statusCase->label() }}</span>
                                    @if ($milestoneStatus === $statusCase->value)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                    <flux:error name="milestoneStatus" class="mt-1.5" />
                </div>

                <x-ui.input wire:model="sequence" :label="__('milestones.sequence')" type="number" min="0" required />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input wire:model="weightPercentage" :label="__('milestones.weight')" type="number" min="0" max="100" step="0.01" required />

                <div class="min-w-0">
                    <label for="milestone-form-responsible" class="{{ $dropdownLabelClasses }}">{{ __('milestones.responsible') }}</label>
                    <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!" x-data="{ optionSearch: '' }">
                        <flux:button id="milestone-form-responsible" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('milestones.responsible')">
                            <span class="truncate">{{ $selectedResponsibleOption ? $selectedResponsibleOption->name.' · '.$selectedResponsibleOption->secondary_name : __('common.select') }}</span>
                        </flux:button>
                        <flux:menu class="filter-menu">
                            @if ($this->userOptions->count() > 6)
                                <div class="border-b border-secondary-soft p-1.5 dark:border-secondary-medium">
                                    <x-ui.input type="search" size="sm" icon="magnifying-glass" x-model="optionSearch" :aria-label="__('milestones.search_responsible')" :placeholder="__('milestones.search_responsible')" />
                                </div>
                            @endif
                            @foreach ($this->userOptions as $userOption)
                                <flux:menu.item wire:click="$set('responsibleUserId', {{ $userOption->id }})" x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())" :wire:key="'milestone-form-responsible-option-'.$userOption->id">
                                    <span class="min-w-0 truncate">{{ $userOption->name }} · {{ $userOption->secondary_name }}</span>
                                    @if ((int) $responsibleUserId === $userOption->id)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                    <flux:error name="responsibleUserId" class="mt-1.5" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="closeMilestoneModal">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" data-test="save-milestone-button">{{ $editingMilestoneId === null ? __('milestones.create_milestone') : __('common.save_changes') }}</x-ui.button>
            </div>
        </form>
    </flux:modal>

    <flux:modal :open="$deletingMilestoneId !== null" wire:close="$set('deletingMilestoneId', null)" class="w-full max-w-md border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('milestones.delete_this_milestone') }}</flux:heading>
            <flux:subheading>{{ __('milestones.only_a_milestone_with_no_nested_milestones_and_no_tasks_can') }}</flux:subheading>
            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="$set('deletingMilestoneId', null)">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="deleteMilestone" data-test="confirm-delete-milestone">{{ __('milestones.delete_milestone') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</section>
