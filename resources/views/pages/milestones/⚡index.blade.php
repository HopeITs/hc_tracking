<?php

use App\Enums\MilestoneStatus;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Project;
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
                    ->with([
                        'responsibleUser:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
                        'media' => fn ($query) => $query
                            ->select(['id', 'mediable_type', 'mediable_id', 'collection', 'original_name', 'type', 'uploaded_at'])
                            ->orderByDesc('uploaded_at')
                            ->orderByDesc('id'),
                    ])
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
    $countVisibleMilestones = function (Collection $milestones) use (&$countVisibleMilestones): int {
        return $milestones->sum(
            fn (Milestone $milestone): int => 1 + $countVisibleMilestones($milestone->childrenRecursive),
        );
    };
    $visibleMilestoneCount = $this->projectsWithMilestones->sum(
        fn (Project $project): int => $countVisibleMilestones($project->milestones),
    );
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="milestone-directory-heading">
    <x-ui.page-header heading-id="milestone-directory-heading" :title="__('common.milestones')" :count="$visibleMilestoneCount">
        <x-slot:filters>
            <x-ui.header-filter-select :label="__('common.project')" wire:model.live="project" :value="$project">
                <flux:select.option value="">{{ __('common.all_projects') }}</flux:select.option>
                @foreach ($this->projectOptions as $projectOption)
                    <flux:select.option :value="$projectOption->id" :wire:key="'milestone-project-filter-'.$projectOption->id">{{ $projectOption->name }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('common.status')" wire:model.live="status" :value="$status">
                <flux:select.option value="">{{ __('common.all_statuses') }}</flux:select.option>
                @foreach (MilestoneStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value" :wire:key="'milestone-status-filter-'.$statusCase->value">{{ $statusCase->label() }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('milestones.responsible')" wire:model.live="responsible" :value="$responsible">
                <flux:select.option value="">{{ __('common.anyone') }}</flux:select.option>
                @foreach ($this->userOptions as $userOption)
                    <flux:select.option :value="$userOption->id" :wire:key="'milestone-user-filter-'.$userOption->id">{{ $userOption->name }} · {{ $userOption->secondary_name }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-milestone-button">{{ __('milestones.add_milestone') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

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
        @foreach ($this->projectsWithMilestones as $projectRow)
            <x-ui.panel :title="$projectRow->name" :subtitle="$projectRow->city?->name ?? __('common.unknown_city')" :count="$projectRow->milestones->count()" wire:key="milestone-project-{{ $projectRow->id }}">
                <x-slot:toolbar>
                    <a href="{{ route('projects.show', $projectRow) }}" wire:navigate class="text-xs font-semibold text-secondary-medium hover:underline dark:text-secondary-soft">{{ __('milestones.open_project') }}</a>
                </x-slot:toolbar>

                <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($projectRow->milestones as $milestone)
                        @include('pages.milestones._tree-node', ['milestone' => $milestone, 'depth' => 0])
                    @endforeach
                </div>
            </x-ui.panel>
        @endforeach
    @endif

    <flux:modal wire:model="showMilestoneModal" class="w-full max-w-3xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveMilestone" class="flex flex-col gap-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingMilestoneId === null ? __('milestones.add_milestone') : __('milestones.edit_milestone') }}</flux:heading>
                <flux:subheading>{{ __('milestones.a_milestone_may_nest_only_within_its_project_project_ownersh') }}</flux:subheading>
            </div>

            <x-ui.input wire:model="name" :label="__('milestones.milestone_name')" type="text" maxlength="255" required autofocus />
            <flux:textarea wire:model="description" :label="__('common.description')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:border-secondary-light! dark:bg-secondary-medium! dark:text-white!" />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select wire:model.live="milestoneProjectId" :label="__('common.project')" :disabled="$editingMilestoneId !== null" required>
                    <flux:select.option value="">{{ __('common.select_a_project') }}</flux:select.option>
                    @foreach ($this->projectOptions as $projectOption)
                        <flux:select.option :value="$projectOption->id" :wire:key="'milestone-project-option-'.$projectOption->id">{{ $projectOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="parentMilestoneId" :label="__('milestones.nest_under')">
                    <flux:select.option value="">{{ __('common.top_level') }}</flux:select.option>
                    @foreach ($this->parentOptions as $parentOption)
                        <flux:select.option :value="$parentOption->id" :wire:key="'milestone-parent-option-'.$parentOption->id">{{ $parentOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input wire:model="plannedDate" :label="__('milestones.planned_date_2')" type="date" required />
                <x-ui.input wire:model="actualDate" :label="__('milestones.actual_date')" type="date" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2 @2xl/directory:grid-cols-4">
                <x-ui.select wire:model="milestoneStatus" :label="__('common.status')" required>
                    @foreach (MilestoneStatus::cases() as $statusCase)
                        <flux:select.option :value="$statusCase->value" :wire:key="'milestone-status-option-'.$statusCase->value">{{ $statusCase->label() }}</flux:select.option>
                    @endforeach
                </x-ui.select>
                <x-ui.input wire:model="sequence" :label="__('milestones.sequence')" type="number" min="0" required />
                <x-ui.input wire:model="weightPercentage" :label="__('milestones.weight')" type="number" min="0" max="100" step="0.01" required />
                <x-ui.select wire:model="responsibleUserId" :label="__('milestones.responsible')" required>
                    <flux:select.option value="">{{ __('common.select') }}</flux:select.option>
                    @foreach ($this->userOptions as $userOption)
                        <flux:select.option :value="$userOption->id" :wire:key="'milestone-responsible-option-'.$userOption->id">{{ $userOption->name }} · {{ $userOption->secondary_name }}</flux:select.option>
                    @endforeach
                </x-ui.select>
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
