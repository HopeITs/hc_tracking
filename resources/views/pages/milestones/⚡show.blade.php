<?php

use App\Enums\MilestoneStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('titles.milestone')] class extends Component {
    #[Locked]
    public int $milestoneId;

    public string $name = '';
    public string $description = '';
    public string $plannedStartDate = '';
    public string $plannedEndDate = '';
    public string $milestoneStatus = 'planned';
    public string $weightPercentage = '0';
    public string $responsibleUserId = '';
    public string $parentMilestoneId = '';

    public bool $showRelatedModal = false;
    public string $relatedName = '';
    public string $relatedDescription = '';
    public string $relatedPlannedStartDate = '';
    public string $relatedPlannedEndDate = '';
    public string $relatedStatus = 'planned';
    public string $relatedWeightPercentage = '0';
    public string $relatedResponsibleUserId = '';
    public string $relatedParentMilestoneId = '';

    public bool $showAddTaskModal = false;
    public string $newTaskTitle = '';
    public string $newTaskStatus = 'todo';
    public string $newTaskPriority = 'medium';
    public string $newTaskDueDate = '';

    /** @var array<int, string> */
    public array $newTaskAssignedTo = [];

    public function mount(Milestone $milestone): void
    {
        $this->milestoneId = $milestone->id;

        $freshMilestone = $this->milestone;
        $this->name = $freshMilestone->name;
        $this->description = $freshMilestone->description ?? '';
        $this->plannedStartDate = $freshMilestone->planned_start_date->toDateString();
        $this->plannedEndDate = $freshMilestone->planned_end_date?->toDateString() ?? '';
        $this->milestoneStatus = $freshMilestone->status->value;
        $this->weightPercentage = $freshMilestone->weight_percentage;
        $this->responsibleUserId = (string) $freshMilestone->responsible_user_id;
        $this->parentMilestoneId = (string) ($freshMilestone->parent_milestone_id ?? '');
    }

    #[Computed]
    public function milestone(): Milestone
    {
        return Milestone::query()
            ->select(['id', 'project_id', 'name', 'description', 'planned_start_date', 'planned_end_date', 'actual_start_date', 'actual_end_date', 'status', 'sequence', 'weight_percentage', 'responsible_user_id', 'parent_milestone_id', 'updated_at'])
            ->with(['project:id,name', 'responsibleUser:id,first_name_en,last_name_en,first_name_ar,last_name_ar', 'parent:id,name'])
            ->findOrFail($this->milestoneId);
    }

    /** @return Collection<int, Milestone> */
    #[Computed]
    public function relatedMilestones(): Collection
    {
        return Milestone::query()
            ->select(['id', 'name', 'status', 'planned_start_date', 'planned_end_date', 'actual_end_date', 'sequence'])
            ->where('parent_milestone_id', $this->milestoneId)
            ->orderBy('sequence')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Task> */
    #[Computed]
    public function relatedTasks(): Collection
    {
        return Task::query()
            ->select(['id', 'title', 'status', 'priority', 'due_date', 'progress_percentage'])
            ->where('source_type', 'milestone')
            ->where('source_id', $this->milestoneId)
            ->whereNull('parent_task_id')
            ->with('taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar')
            ->orderBy('id')
            ->get();
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
        $invalidParentIds = $this->invalidParentIds();

        return Milestone::query()
            ->select(['id', 'name', 'project_id', 'sequence'])
            ->where('project_id', $this->milestone->project_id)
            ->whereNotIn('id', $invalidParentIds)
            ->orderBy('sequence')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Milestone> */
    #[Computed]
    public function relatedParentOptions(): Collection
    {
        return Milestone::query()
            ->select(['id', 'name', 'project_id', 'sequence'])
            ->where('project_id', $this->milestone->project_id)
            ->orderBy('sequence')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function updateMilestone(): void
    {
        $this->name = trim($this->name);
        $this->description = trim($this->description);

        $milestone = Milestone::query()->findOrFail($this->milestoneId);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'plannedStartDate' => ['required', 'date'],
            'plannedEndDate' => ['nullable', 'date', 'after_or_equal:plannedStartDate'],
            'milestoneStatus' => ['required', new Enum(MilestoneStatus::class)],
            'weightPercentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'responsibleUserId' => ['required', 'integer', Rule::exists(HrEmployee::class, 'id')],
            'parentMilestoneId' => [
                'nullable',
                'integer',
                Rule::exists(Milestone::class, 'id')->where(
                    fn ($query) => $query->where('project_id', $milestone->project_id),
                ),
                Rule::notIn($this->invalidParentIds()),
            ],
        ], [
            'name.required' => __('milestones.enter_a_milestone_name'),
            'plannedStartDate.required' => __('milestones.set_the_planned_start_date'),
            'plannedEndDate.after_or_equal' => __('milestones.planned_end_date_must_be_on_or_after_the_planned_start_date'),
            'responsibleUserId.required' => __('milestones.select_the_responsible_staff_member'),
            'parentMilestoneId.exists' => __('milestones.select_a_milestone_from_this_project'),
            'parentMilestoneId.not_in' => __('milestones.a_milestone_cannot_be_nested_under_itself_or_its_descendants'),
            'weightPercentage.max' => __('milestones.weight_cannot_exceed_100_percent'),
        ]);

        $milestone->fill([
            'name' => $validated['name'],
            'description' => $validated['description'] === '' ? null : $validated['description'],
            'planned_start_date' => $validated['plannedStartDate'],
            'planned_end_date' => $validated['plannedEndDate'] === '' ? null : $validated['plannedEndDate'],
            'status' => $validated['milestoneStatus'],
            'weight_percentage' => $validated['weightPercentage'],
            'responsible_user_id' => (int) $validated['responsibleUserId'],
            'parent_milestone_id' => $validated['parentMilestoneId'] === '' || $validated['parentMilestoneId'] === null
                ? null
                : (int) $validated['parentMilestoneId'],
        ])->save();

        unset($this->milestone);
        unset($this->parentOptions);

        Flux::toast(variant: 'success', text: __('milestones.milestone_updated'));
    }

    public function openRelatedModal(): void
    {
        $this->resetValidation();
        $this->relatedName = '';
        $this->relatedDescription = '';
        $this->relatedPlannedStartDate = '';
        $this->relatedPlannedEndDate = '';
        $this->relatedStatus = 'planned';
        $this->relatedWeightPercentage = '0';
        $this->relatedResponsibleUserId = '';
        $this->relatedParentMilestoneId = (string) $this->milestoneId;
        $this->showRelatedModal = true;
    }

    public function closeRelatedModal(): void
    {
        $this->showRelatedModal = false;
    }

    public function saveRelatedMilestone(): void
    {
        $this->relatedName = trim($this->relatedName);
        $this->relatedDescription = trim($this->relatedDescription);

        $projectId = $this->milestone->project_id;

        $validated = $this->validate([
            'relatedName' => ['required', 'string', 'max:255'],
            'relatedDescription' => ['nullable', 'string'],
            'relatedPlannedStartDate' => ['required', 'date'],
            'relatedPlannedEndDate' => ['nullable', 'date', 'after_or_equal:relatedPlannedStartDate'],
            'relatedStatus' => ['required', new Enum(MilestoneStatus::class)],
            'relatedWeightPercentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'relatedResponsibleUserId' => ['required', 'integer', Rule::exists(HrEmployee::class, 'id')],
            'relatedParentMilestoneId' => [
                'nullable',
                'integer',
                Rule::exists(Milestone::class, 'id')->where(
                    fn ($query) => $query->where('project_id', $projectId),
                ),
            ],
        ], [
            'relatedName.required' => __('milestones.enter_a_milestone_name'),
            'relatedPlannedStartDate.required' => __('milestones.set_the_planned_start_date'),
            'relatedPlannedEndDate.after_or_equal' => __('milestones.planned_end_date_must_be_on_or_after_the_planned_start_date'),
            'relatedResponsibleUserId.required' => __('milestones.select_the_responsible_staff_member'),
            'relatedParentMilestoneId.exists' => __('milestones.select_a_milestone_from_this_project'),
            'relatedWeightPercentage.max' => __('milestones.weight_cannot_exceed_100_percent'),
        ]);

        Milestone::query()->create([
            'project_id' => $projectId,
            'name' => $validated['relatedName'],
            'description' => $validated['relatedDescription'] === '' ? null : $validated['relatedDescription'],
            'planned_start_date' => $validated['relatedPlannedStartDate'],
            'planned_end_date' => $validated['relatedPlannedEndDate'] === '' ? null : $validated['relatedPlannedEndDate'],
            'status' => $validated['relatedStatus'],
            'weight_percentage' => $validated['relatedWeightPercentage'],
            'responsible_user_id' => (int) $validated['relatedResponsibleUserId'],
            'parent_milestone_id' => $validated['relatedParentMilestoneId'] === '' || $validated['relatedParentMilestoneId'] === null
                ? null
                : (int) $validated['relatedParentMilestoneId'],
        ]);

        $this->showRelatedModal = false;
        unset($this->relatedMilestones);
        unset($this->milestone);

        Flux::toast(variant: 'success', text: __('milestones.milestone_created'));
    }

    public function openAddTaskModal(): void
    {
        $this->resetValidation();
        $this->newTaskTitle = '';
        $this->newTaskStatus = TaskStatus::Todo->value;
        $this->newTaskPriority = TaskPriority::Medium->value;
        $this->newTaskDueDate = '';
        $this->newTaskAssignedTo = [];
        $this->showAddTaskModal = true;
    }

    public function closeAddTaskModal(): void
    {
        $this->showAddTaskModal = false;
    }

    public function addTask(): void
    {
        $this->newTaskTitle = trim($this->newTaskTitle);

        $validated = $this->validate([
            'newTaskTitle' => ['required', 'string', 'max:255'],
            'newTaskStatus' => ['required', new Enum(TaskStatus::class)],
            'newTaskPriority' => ['required', new Enum(TaskPriority::class)],
            'newTaskDueDate' => ['nullable', 'date'],
            'newTaskAssignedTo' => ['required', 'array', 'min:1'],
            'newTaskAssignedTo.*' => ['integer', Rule::exists(HrEmployee::class, 'id')],
        ], [
            'newTaskTitle.required' => __('tasks.enter_a_task_title'),
            'newTaskAssignedTo.required' => __('tasks.assign_this_task_to_someone'),
            'newTaskAssignedTo.min' => __('tasks.assign_this_task_to_someone'),
            'newTaskAssignedTo.*.exists' => __('tasks.the_selected_assignee_is_no_longer_available'),
        ]);

        $milestoneId = $this->milestoneId;
        $statusCase = TaskStatus::from($validated['newTaskStatus']);
        $employeeIds = collect($validated['newTaskAssignedTo'])->map(fn ($id): int => (int) $id)->unique()->values();

        DB::transaction(function () use ($milestoneId, $validated, $statusCase, $employeeIds): void {
            $task = Task::query()->create([
                'source_type' => 'milestone',
                'source_id' => $milestoneId,
                'title' => $validated['newTaskTitle'],
                'status' => $statusCase,
                'priority' => $validated['newTaskPriority'],
                'due_date' => $validated['newTaskDueDate'] === '' ? null : $validated['newTaskDueDate'],
                'progress_percentage' => $statusCase === TaskStatus::Done ? 100 : 0,
                'completed_date' => $statusCase === TaskStatus::Done ? CarbonImmutable::today() : null,
                'created_by' => (int) auth()->id(),
            ]);

            foreach ($employeeIds as $employeeId) {
                $task->taskAssignees()->create(['employee_id' => $employeeId]);
            }
        });

        $this->showAddTaskModal = false;
        unset($this->relatedTasks);

        Flux::toast(variant: 'success', text: __('tasks.task_created'));
    }

    /** @return array<int, int> */
    protected function invalidParentIds(): array
    {
        $milestone = Milestone::query()
            ->select(['id', 'parent_milestone_id'])
            ->with('childrenRecursive:id,parent_milestone_id,sequence')
            ->find($this->milestoneId);

        if ($milestone === null) {
            return [];
        }

        return [...$milestone->descendants()->modelKeys(), $milestone->id];
    }
};
?>

@php
    $dropdownLabelClasses = 'mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.1em] text-secondary-medium dark:text-secondary-soft';
    $selectedParentMilestone = $parentMilestoneId === '' ? null : $this->parentOptions->firstWhere('id', (int) $parentMilestoneId);
    $selectedResponsibleOption = $responsibleUserId === '' ? null : $this->userOptions->firstWhere('id', (int) $responsibleUserId);
    $selectedRelatedParentMilestone = $relatedParentMilestoneId === '' ? null : $this->relatedParentOptions->firstWhere('id', (int) $relatedParentMilestoneId);
    $selectedRelatedResponsibleOption = $relatedResponsibleUserId === '' ? null : $this->userOptions->firstWhere('id', (int) $relatedResponsibleUserId);
    $selectedNewTaskAssigneeOptions = $this->userOptions->whereIn('id', collect($newTaskAssignedTo)->map(fn ($id) => (int) $id));
    $newTaskAssigneeTriggerLabel = match (true) {
        $selectedNewTaskAssigneeOptions->isEmpty() => __('common.select'),
        $selectedNewTaskAssigneeOptions->count() <= 2 => $selectedNewTaskAssigneeOptions->pluck('name')->implode(', '),
        default => __('common.count_selected', ['count' => $selectedNewTaskAssigneeOptions->count()]),
    };
@endphp

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="milestone-detail-heading">
    <x-ui.detail-header
        id="milestone-detail-heading"
        :breadcrumbs="[
            ['label' => __('common.milestones'), 'url' => route('milestones.index')],
            ['label' => $this->milestone->name],
        ]"
        :title="$this->milestone->name"
        :unread="app(\App\Notifications\UnreadSubjects::class)->has(auth()->user(), 'milestone', $this->milestone->id)"
        title-class="text-3xl! font-bold! leading-none!"
        :subtitle="$this->milestone->project->name"
    >
        <x-slot:meta>
            <x-ui.enum-badge :case="$this->milestone->status" />
            <x-ui.milestone-flags :milestone="$this->milestone" />
            @if ($this->milestone->parent !== null)
                <span>{{ __('milestones.nested_milestone') }}: {{ $this->milestone->parent->name }}</span>
            @endif
            <span>{{ __('milestones.responsible') }}: {{ $this->milestone->responsibleUser->name }} · {{ $this->milestone->responsibleUser->secondary_name }}</span>
            <span>{{ __('common.updated_date', ['date' => $this->milestone->updated_at?->translatedFormat(__('formats.date'))]) }}</span>
        </x-slot:meta>
    </x-ui.detail-header>

    <div class="grid gap-3 @2xl/detail:grid-cols-3 @4xl/detail:grid-cols-6">
        <x-ui.stat-tile :label="__('milestones.weight')" :value="rtrim(rtrim(number_format((float) $this->milestone->weight_percentage, 2), '0'), '.').'%'" icon="scale" />
        <x-ui.stat-tile :label="__('milestones.planned_start_date')" :value="$this->milestone->planned_start_date->translatedFormat(__('formats.date'))" icon="calendar" />
        <x-ui.stat-tile :label="__('milestones.planned_end_date')" :value="$this->milestone->planned_end_date?->translatedFormat(__('formats.date')) ?? '—'" icon="calendar" />
        <x-ui.stat-tile :label="__('milestones.actual_start_date')" :value="$this->milestone->actual_start_date?->translatedFormat(__('formats.date')) ?? '—'" icon="calendar-days" />
        <x-ui.stat-tile :label="__('milestones.actual_end_date')" :value="$this->milestone->actual_end_date?->translatedFormat(__('formats.date')) ?? '—'" icon="calendar-days" />
        <x-ui.stat-tile :label="__('milestones.sequence')" :value="$this->milestone->sequence" icon="hashtag" />
    </div>

    <x-ui.panel :title="__('milestones.edit_milestone')" :subtitle="__('milestones.edit_milestone_hint')">
        <form wire:submit="updateMilestone" class="flex flex-col gap-5 p-4">
            <x-ui.input wire:model="name" :label="__('milestones.milestone_name')" type="text" maxlength="255" required autofocus />

            <flux:textarea wire:model="description" :label="__('common.description')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:border-secondary-light! dark:bg-secondary-medium! dark:text-white!" />

            <div class="flex flex-col gap-4 border-t border-secondary-soft pt-5 dark:border-secondary-medium">
                <p class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('milestones.schedule_and_status') }}</p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input wire:model="plannedStartDate" :label="__('milestones.planned_start_date')" type="date" required />
                    <x-ui.input wire:model="plannedEndDate" :label="__('milestones.planned_end_date')" type="date" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="min-w-0">
                        <label for="milestone-edit-status" class="{{ $dropdownLabelClasses }}">{{ __('common.status') }}</label>
                        <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!">
                            <flux:button id="milestone-edit-status" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('common.status')">
                                <span class="truncate">{{ MilestoneStatus::from($milestoneStatus)->label() }}</span>
                            </flux:button>
                            <flux:menu class="filter-menu">
                                @foreach (MilestoneStatus::cases() as $statusCase)
                                    <flux:menu.item wire:click="$set('milestoneStatus', '{{ $statusCase->value }}')" :wire:key="'milestone-edit-status-option-'.$statusCase->value">
                                        <span class="min-w-0 truncate">{{ $statusCase->label() }}</span>
                                        @if ($milestoneStatus === $statusCase->value)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                    </flux:menu.item>
                                @endforeach
                            </flux:menu>
                        </flux:dropdown>
                        <flux:error name="milestoneStatus" class="mt-1.5" />
                    </div>

                    <x-ui.input wire:model="weightPercentage" :label="__('milestones.weight')" type="number" min="0" max="100" step="0.01" required />
                </div>
            </div>

            <div class="flex flex-col gap-4 border-t border-secondary-soft pt-5 dark:border-secondary-medium">
                <p class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('milestones.assignment') }}</p>

                <div class="min-w-0">
                    <x-ui.progress-bar :value="(float) $weightPercentage" :max="100" show-value class="mt-2" />
                </div>

                <div class="min-w-0">
                    <label for="milestone-edit-responsible" class="{{ $dropdownLabelClasses }}">{{ __('milestones.responsible') }}</label>
                    <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!" x-data="{ optionSearch: '' }">
                        <flux:button id="milestone-edit-responsible" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('milestones.responsible')">
                            <span class="truncate">{{ $selectedResponsibleOption ? $selectedResponsibleOption->name.' · '.$selectedResponsibleOption->secondary_name : __('common.select') }}</span>
                        </flux:button>
                        <flux:menu class="filter-menu">
                            @if ($this->userOptions->count() > 6)
                                <div class="border-b border-secondary-soft p-1.5 dark:border-secondary-medium">
                                    <x-ui.input type="search" size="sm" icon="magnifying-glass" x-model="optionSearch" :aria-label="__('milestones.search_responsible')" :placeholder="__('milestones.search_responsible')" />
                                </div>
                            @endif
                            @foreach ($this->userOptions as $userOption)
                                <flux:menu.item wire:click="$set('responsibleUserId', {{ $userOption->id }})" x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())" :wire:key="'milestone-edit-responsible-option-'.$userOption->id">
                                    <span class="min-w-0 truncate">{{ $userOption->name }} · {{ $userOption->secondary_name }}</span>
                                    @if ((int) $responsibleUserId === $userOption->id)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                    <flux:error name="responsibleUserId" class="mt-1.5" />
                </div>

                <div class="min-w-0 sm:max-w-xs">
                    <label for="milestone-edit-parent" class="{{ $dropdownLabelClasses }}">{{ __('milestones.nest_under') }}</label>
                    <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!" x-data="{ optionSearch: '' }">
                        <flux:button id="milestone-edit-parent" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('milestones.nest_under')">
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
                                <flux:menu.item wire:click="$set('parentMilestoneId', {{ $parentOption->id }})" x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())" :wire:key="'milestone-edit-parent-option-'.$parentOption->id">
                                    <span class="min-w-0 truncate">{{ $parentOption->name }}</span>
                                    @if ((int) $parentMilestoneId === $parentOption->id)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                    <flux:error name="parentMilestoneId" class="mt-1.5" />
                </div>
            </div>

            <div class="flex justify-end gap-2 border-t border-secondary-soft pt-4 dark:border-secondary-medium">
                <x-ui.button type="submit" tone="primary" class="min-h-11 touch-manipulation sm:min-h-10" data-test="update-milestone-button">{{ __('common.save_changes') }}</x-ui.button>
            </div>
        </form>
    </x-ui.panel>

    <x-ui.panel :title="__('milestones.related_milestones')" :count="$this->relatedMilestones->count()">
        <x-slot:toolbar>
            <x-ui.button tone="primary" size="sm" icon="plus" wire:click="openRelatedModal" data-test="add-related-milestone-button">{{ __('milestones.add_related_milestone') }}</x-ui.button>
        </x-slot:toolbar>

        @if ($this->relatedMilestones->isEmpty())
            <x-ui.empty-state icon="flag" :heading="__('milestones.no_related_milestones_yet')" :body="__('milestones.add_a_milestone_nested_under_this_one')">
                <x-slot:action>
                    <x-ui.button size="sm" tone="primary" icon="plus" wire:click="openRelatedModal">{{ __('milestones.add_related_milestone') }}</x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <ul class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->relatedMilestones as $related)
                    <li class="flex items-center justify-between gap-3 p-3.5" wire:key="related-milestone-{{ $related->id }}">
                        <a href="{{ route('milestones.show', $related) }}" wire:navigate class="min-w-0 truncate text-sm font-semibold hover:underline">{{ $related->name }}</a>
                        <div class="flex shrink-0 items-center gap-3">
                            <span class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $related->planned_start_date->translatedFormat(__('formats.date')) }}</span>
                            <x-ui.enum-badge :case="$related->status" />
                            <x-ui.milestone-flags :milestone="$related" />
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.panel>

    <x-ui.panel :title="__('tasks.related_tasks')" :count="$this->relatedTasks->count()">
        <x-slot:toolbar>
            <x-ui.button tone="primary" size="sm" icon="plus" wire:click="openAddTaskModal" data-test="add-milestone-task-button">{{ __('tasks.add_task') }}</x-ui.button>
        </x-slot:toolbar>

        @if ($this->relatedTasks->isEmpty())
            <x-ui.empty-state icon="clipboard-document-list" :heading="__('common.no_tasks_yet')" :body="__('milestones.add_a_task_linked_to_this_milestone')">
                <x-slot:action>
                    <x-ui.button size="sm" tone="primary" icon="plus" wire:click="openAddTaskModal">{{ __('tasks.add_task') }}</x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <ul class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->relatedTasks as $relatedTask)
                    @php $relatedTaskAssignees = $relatedTask->taskAssignees->pluck('employee')->filter(); @endphp
                    <li class="flex flex-wrap items-center justify-between gap-3 p-3.5" wire:key="milestone-related-task-{{ $relatedTask->id }}">
                        <a href="{{ route('tasks.show', $relatedTask) }}" wire:navigate class="min-w-0 flex-1 truncate text-sm font-semibold hover:underline">
                            {{ $relatedTask->title }}
                        </a>
                        <div class="flex shrink-0 items-center gap-3">
                            <span class="w-40 shrink-0 truncate text-xs text-secondary-medium dark:text-secondary-soft">
                                {{ $relatedTaskAssignees->isEmpty() ? __('common.not_assigned') : $relatedTaskAssignees->map->name->implode(', ') }}
                            </span>
                            <span class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">
                                {{ $relatedTask->due_date?->translatedFormat(__('formats.date')) ?? '—' }}
                            </span>
                            <x-ui.enum-badge :case="$relatedTask->status" />
                            <x-ui.enum-badge :case="$relatedTask->priority" />
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.panel>

    <flux:modal name="milestone-add-task-modal" wire:model="showAddTaskModal" wire:close="closeAddTaskModal" class="w-full max-w-2xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="addTask" class="flex flex-col gap-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ __('tasks.add_task') }}</flux:heading>
            </div>

            <x-ui.input wire:model="newTaskTitle" :label="__('tasks.task_title')" type="text" maxlength="255" required autofocus data-test="new-milestone-task-title-input" />

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="min-w-0">
                    <label class="mb-1.5 block text-[11px] font-semibold tracking-[0.1em] text-secondary-medium uppercase dark:text-secondary-soft" id="new-milestone-task-status-label">{{ __('common.status') }}</label>
                    <div class="grid grid-cols-2 gap-1.5" role="radiogroup" aria-labelledby="new-milestone-task-status-label">
                        @foreach (TaskStatus::cases() as $statusCase)
                            @php $isNewTaskStatusSelected = $newTaskStatus === $statusCase->value; @endphp
                            <button
                                type="button"
                                role="radio"
                                aria-checked="{{ $isNewTaskStatusSelected ? 'true' : 'false' }}"
                                wire:click="$set('newTaskStatus', '{{ $statusCase->value }}')"
                                @class([
                                    'inline-flex min-h-10 cursor-pointer touch-manipulation items-center justify-center gap-1.5 rounded-lg border px-2.5 py-2 text-xs font-semibold whitespace-nowrap transition-colors',
                                    'border-secondary-dark bg-secondary-dark text-white dark:border-white dark:bg-white dark:text-secondary-dark' => $isNewTaskStatusSelected,
                                    'border-secondary-light text-secondary-medium hover:border-secondary-medium hover:text-secondary-dark dark:text-secondary-soft dark:hover:text-white' => ! $isNewTaskStatusSelected,
                                ])
                                data-test="new-milestone-task-status-{{ $statusCase->value }}"
                            >
                                {{ $statusCase->label() }}
                            </button>
                        @endforeach
                    </div>
                    <flux:error name="newTaskStatus" class="mt-1.5" />
                </div>

                <div class="min-w-0">
                    <label class="mb-1.5 block text-[11px] font-semibold tracking-[0.1em] text-secondary-medium uppercase dark:text-secondary-soft" id="new-milestone-task-priority-label">{{ __('tasks.priority') }}</label>
                    <div class="grid grid-cols-3 gap-1.5" role="radiogroup" aria-labelledby="new-milestone-task-priority-label">
                        @foreach (TaskPriority::cases() as $priorityCase)
                            @php $isNewTaskPrioritySelected = $newTaskPriority === $priorityCase->value; @endphp
                            <button
                                type="button"
                                role="radio"
                                aria-checked="{{ $isNewTaskPrioritySelected ? 'true' : 'false' }}"
                                wire:click="$set('newTaskPriority', '{{ $priorityCase->value }}')"
                                @class([
                                    'inline-flex min-h-10 cursor-pointer touch-manipulation items-center justify-center gap-1.5 rounded-lg border px-2.5 py-2 text-xs font-semibold whitespace-nowrap transition-colors',
                                    'border-secondary-dark bg-secondary-dark text-white dark:border-secondary-medium dark:bg-secondary-medium' => $isNewTaskPrioritySelected,
                                    'border-secondary-light text-secondary-medium hover:border-secondary-medium hover:text-secondary-dark dark:text-secondary-soft dark:hover:text-white' => ! $isNewTaskPrioritySelected,
                                ])
                                data-test="new-milestone-task-priority-{{ $priorityCase->value }}"
                            >
                                {{ $priorityCase->label() }}
                            </button>
                        @endforeach
                    </div>
                    <flux:error name="newTaskPriority" class="mt-1.5" />
                </div>
            </div>

            <div class="min-w-0">
                <label class="mb-1.5 block text-[11px] font-semibold tracking-[0.1em] text-secondary-medium uppercase dark:text-secondary-soft">{{ __('tasks.assignees') }}</label>
                <x-ui.multi-select-popover
                    :label="__('tasks.assignees')"
                    target="[data-modal='milestone-add-task-modal']"
                    class="flex w-full cursor-pointer items-center justify-between gap-2 rounded-lg border border-secondary-light bg-white px-3 py-2 text-start text-sm dark:border-secondary-light dark:bg-secondary-medium"
                    data-test="new-milestone-task-assignee-picker"
                >
                    <x-slot:trigger>
                        <span class="min-w-0 truncate">{{ $newTaskAssigneeTriggerLabel }}</span>
                        <flux:icon.chevron-up-down class="size-4 shrink-0 text-secondary-medium dark:text-secondary-soft" aria-hidden="true" />
                    </x-slot:trigger>

                    @foreach ($this->userOptions as $userOption)
                        <label
                            x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())"
                            class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-secondary-soft dark:hover:bg-secondary-medium"
                        >
                            <input type="checkbox" wire:model.live="newTaskAssignedTo" value="{{ $userOption->id }}" class="rounded border-secondary-light text-primary focus:ring-primary" data-test="new-milestone-task-assignee-checkbox-{{ $userOption->id }}" />
                            <span class="min-w-0 truncate">{{ $userOption->name }} · {{ $userOption->secondary_name }}</span>
                        </label>
                    @endforeach
                </x-ui.multi-select-popover>
                <flux:error name="newTaskAssignedTo" class="mt-1.5" />
            </div>

            <x-ui.input wire:model="newTaskDueDate" :label="__('tasks.due_date_2')" type="date" />

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="closeAddTaskModal">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" data-test="save-new-milestone-task-button">{{ __('tasks.add_task') }}</x-ui.button>
            </div>
        </form>
    </flux:modal>

    <section aria-labelledby="record-activity-heading" class="grid gap-3" data-record-activity>
        <h2 id="record-activity-heading" class="text-base font-bold">{{ __('activity.title') }}</h2>
        <livewire:activity.timeline :subjects="['milestone' => [$this->milestone->id]]" :key="'milestone-activity-'.$this->milestone->id" />
    </section>

    <flux:modal wire:model="showRelatedModal" wire:close="closeRelatedModal" class="w-full max-w-3xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveRelatedMilestone" class="flex flex-col gap-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ __('milestones.add_related_milestone') }}</flux:heading>
            </div>

            <x-ui.input wire:model="relatedName" :label="__('milestones.milestone_name')" type="text" maxlength="255" required autofocus />

            <div class="min-w-0">
                <label for="related-milestone-form-parent" class="{{ $dropdownLabelClasses }}">{{ __('milestones.nest_under') }}</label>
                <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!" x-data="{ optionSearch: '' }">
                    <flux:button id="related-milestone-form-parent" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('milestones.nest_under')">
                        <span class="truncate">{{ $selectedRelatedParentMilestone?->name ?? __('common.top_level') }}</span>
                    </flux:button>
                    <flux:menu class="filter-menu">
                        @if ($this->relatedParentOptions->count() > 6)
                            <div class="border-b border-secondary-soft p-1.5 dark:border-secondary-medium">
                                <x-ui.input type="search" size="sm" icon="magnifying-glass" x-model="optionSearch" :aria-label="__('milestones.search_milestones')" :placeholder="__('milestones.search_milestones')" />
                            </div>
                        @endif
                        <flux:menu.item wire:click="$set('relatedParentMilestoneId', '')">
                            {{ __('common.top_level') }}
                            @if ($relatedParentMilestoneId === '')<flux:icon.check class="ms-auto size-4 text-secondary-medium" />@endif
                        </flux:menu.item>
                        @foreach ($this->relatedParentOptions as $relatedParentOption)
                            <flux:menu.item wire:click="$set('relatedParentMilestoneId', {{ $relatedParentOption->id }})" x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())" :wire:key="'related-milestone-form-parent-option-'.$relatedParentOption->id">
                                <span class="min-w-0 truncate">{{ $relatedParentOption->name }}</span>
                                @if ((int) $relatedParentMilestoneId === $relatedParentOption->id)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                            </flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
                <flux:error name="relatedParentMilestoneId" class="mt-1.5" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input wire:model="relatedPlannedStartDate" :label="__('milestones.planned_start_date')" type="date" required />
                <x-ui.input wire:model="relatedPlannedEndDate" :label="__('milestones.planned_end_date')" type="date" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="min-w-0">
                    <label for="related-milestone-form-status" class="{{ $dropdownLabelClasses }}">{{ __('common.status') }}</label>
                    <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!">
                        <flux:button id="related-milestone-form-status" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('common.status')">
                            <span class="truncate">{{ MilestoneStatus::from($relatedStatus)->label() }}</span>
                        </flux:button>
                        <flux:menu class="filter-menu">
                            @foreach (MilestoneStatus::cases() as $statusCase)
                                <flux:menu.item wire:click="$set('relatedStatus', '{{ $statusCase->value }}')" :wire:key="'related-milestone-form-status-option-'.$statusCase->value">
                                    <span class="min-w-0 truncate">{{ $statusCase->label() }}</span>
                                    @if ($relatedStatus === $statusCase->value)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                    <flux:error name="relatedStatus" class="mt-1.5" />
                </div>

                <x-ui.input wire:model="relatedWeightPercentage" :label="__('milestones.weight')" type="number" min="0" max="100" step="0.01" required />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="min-w-0">
                    <label for="related-milestone-form-responsible" class="{{ $dropdownLabelClasses }}">{{ __('milestones.responsible') }}</label>
                    <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!" x-data="{ optionSearch: '' }">
                        <flux:button id="related-milestone-form-responsible" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('milestones.responsible')">
                            <span class="truncate">{{ $selectedRelatedResponsibleOption ? $selectedRelatedResponsibleOption->name.' · '.$selectedRelatedResponsibleOption->secondary_name : __('common.select') }}</span>
                        </flux:button>
                        <flux:menu class="filter-menu">
                            @if ($this->userOptions->count() > 6)
                                <div class="border-b border-secondary-soft p-1.5 dark:border-secondary-medium">
                                    <x-ui.input type="search" size="sm" icon="magnifying-glass" x-model="optionSearch" :aria-label="__('milestones.search_responsible')" :placeholder="__('milestones.search_responsible')" />
                                </div>
                            @endif
                            @foreach ($this->userOptions as $userOption)
                                <flux:menu.item wire:click="$set('relatedResponsibleUserId', {{ $userOption->id }})" x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())" :wire:key="'related-milestone-form-responsible-option-'.$userOption->id">
                                    <span class="min-w-0 truncate">{{ $userOption->name }} · {{ $userOption->secondary_name }}</span>
                                    @if ((int) $relatedResponsibleUserId === $userOption->id)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                    <flux:error name="relatedResponsibleUserId" class="mt-1.5" />
                </div>
            </div>

            <flux:textarea wire:model="relatedDescription" :label="__('common.description')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:border-secondary-light! dark:bg-secondary-medium! dark:text-white!" />

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="closeRelatedModal">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" data-test="save-related-milestone-button">{{ __('milestones.create_milestone') }}</x-ui.button>
            </div>
        </form>
    </flux:modal>
</section>
