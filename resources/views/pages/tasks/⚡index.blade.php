<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.tasks')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $priority = '';

    #[Url(except: '')]
    public string $assignee = '';

    /** Accepts a milestone id, or the literal 'none' for tasks without one. */
    #[Url(except: '')]
    public string $milestone = '';

    public bool $showTaskModal = false;
    public ?int $editingTaskId = null;
    public ?int $deletingTaskId = null;
    public string $title = '';
    public string $description = '';
    public string $taskStatus = 'todo';
    public string $taskPriority = 'medium';
    public string $startDate = '';
    public string $dueDate = '';
    public string $completedDate = '';
    public string $progressPercentage = '0';
    public string $assignedTo = '';
    public string $taskProposalId = '';
    public string $taskMilestoneId = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPriority(): void
    {
        $this->resetPage();
    }

    public function updatedAssignee(): void
    {
        $this->resetPage();
    }

    public function updatedMilestone(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->priority = '';
        $this->assignee = '';
        $this->milestone = '';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Task> */
    #[Computed]
    public function tasks(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Task::query()
            ->select(['id', 'milestone_id', 'proposal_id', 'title', 'assigned_to', 'status', 'priority', 'due_date', 'progress_percentage'])
            ->with([
                'milestone:id,name,project_id',
                'milestone.project:id,name',
                'proposal:id,name',
                'assignee:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('title', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%')
                        ->orWhereIn('assigned_to', HrEmployee::idsMatchingName($search));
                });
            })
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->priority !== '', fn (Builder $query) => $query->where('priority', $this->priority))
            ->when($this->assignee !== '', fn (Builder $query) => $query->where('assigned_to', (int) $this->assignee))
            ->when($this->milestone === 'none', fn (Builder $query) => $query->whereNull('milestone_id'))
            ->when($this->milestone !== '' && $this->milestone !== 'none', fn (Builder $query) => $query->where('milestone_id', (int) $this->milestone))
            ->orderByRaw('due_date is null, due_date')
            ->orderBy('id')
            ->paginate(15);
    }

    /** @return Collection<int, HrEmployee> */
    #[Computed]
    public function userOptions(): Collection
    {
        return HrEmployee::pickerOptions();
    }

    /** @return Collection<int, Proposal> */
    #[Computed]
    public function proposalOptions(): Collection
    {
        return Proposal::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    /** @return Collection<int, Milestone> */
    #[Computed]
    public function milestoneOptions(): Collection
    {
        return Milestone::query()
            ->select(['id', 'name', 'project_id'])
            ->with('project:id,name')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function openCreateModal(): void
    {
        $this->resetTaskForm();
        $this->showTaskModal = true;
    }

    public function openEditModal(int $taskId): void
    {
        $task = Task::query()
            ->select(['id', 'milestone_id', 'proposal_id', 'title', 'description', 'assigned_to', 'status', 'priority', 'start_date', 'due_date', 'completed_date', 'progress_percentage'])
            ->findOrFail($taskId);

        $this->resetValidation();
        $this->editingTaskId = $task->id;
        $this->title = $task->title;
        $this->description = $task->description ?? '';
        $this->taskStatus = $task->status->value;
        $this->taskPriority = $task->priority->value;
        $this->startDate = $task->start_date?->toDateString() ?? '';
        $this->dueDate = $task->due_date?->toDateString() ?? '';
        $this->completedDate = $task->completed_date?->toDateString() ?? '';
        $this->progressPercentage = (string) $task->progress_percentage;
        $this->assignedTo = (string) $task->assigned_to;
        $this->taskProposalId = (string) $task->proposal_id;
        $this->taskMilestoneId = (string) ($task->milestone_id ?? '');
        $this->showTaskModal = true;
    }

    public function saveTask(): void
    {
        $this->trimTaskForm();

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'taskStatus' => ['required', new Enum(TaskStatus::class)],
            'taskPriority' => ['required', new Enum(TaskPriority::class)],
            'startDate' => ['nullable', 'date'],
            'dueDate' => ['nullable', 'date', Rule::when($this->startDate !== '', ['after_or_equal:startDate'])],
            'completedDate' => ['nullable', 'date'],
            'progressPercentage' => ['required', 'integer', 'min:0', 'max:100'],
            'assignedTo' => ['required', 'integer', Rule::exists(HrEmployee::class, 'id')],
            'taskProposalId' => ['required', 'integer', Rule::exists(Proposal::class, 'id')],
            'taskMilestoneId' => ['nullable', 'integer', Rule::exists(Milestone::class, 'id')],
        ], [
            'title.required' => __('tasks.enter_a_task_title'),
            'assignedTo.required' => __('tasks.assign_this_task_to_someone'),
            'assignedTo.exists' => __('tasks.the_selected_assignee_is_no_longer_available'),
            'taskProposalId.required' => __('tasks.select_the_proposal_this_task_delivers_against'),
            'taskProposalId.exists' => __('common.the_selected_proposal_is_no_longer_available'),
            'taskMilestoneId.exists' => __('tasks.the_selected_milestone_is_no_longer_available'),
            'dueDate.after_or_equal' => __('tasks.the_due_date_cannot_fall_before_the_start_date'),
            'progressPercentage.min' => __('tasks.progress_cannot_be_negative'),
            'progressPercentage.max' => __('tasks.progress_cannot_exceed_100_percent'),
        ]);

        $isCreate = $this->editingTaskId === null;
        $task = $isCreate ? new Task : Task::query()->findOrFail($this->editingTaskId);
        $attributes = [
            'title' => $validated['title'],
            'description' => $validated['description'] === '' ? null : $validated['description'],
            'status' => $validated['taskStatus'],
            'priority' => $validated['taskPriority'],
            'start_date' => $validated['startDate'] === '' ? null : $validated['startDate'],
            'due_date' => $validated['dueDate'] === '' ? null : $validated['dueDate'],
            'completed_date' => $validated['completedDate'] === '' ? null : $validated['completedDate'],
            'progress_percentage' => (int) $validated['progressPercentage'],
            'assigned_to' => (int) $validated['assignedTo'],
            'proposal_id' => (int) $validated['taskProposalId'],
            'milestone_id' => $validated['taskMilestoneId'] === '' || $validated['taskMilestoneId'] === null ? null : (int) $validated['taskMilestoneId'],
        ];

        if ($isCreate) {
            $attributes['created_by'] = (int) auth()->id();
        }

        $task->fill($attributes)->save();
        $this->showTaskModal = false;
        $this->resetTaskForm();
        $this->resetPage();

        Flux::toast(variant: 'success', text: $isCreate ? __('tasks.task_created') : __('tasks.task_updated'));
    }

    public function confirmDelete(int $taskId): void
    {
        $this->deletingTaskId = $taskId;
    }

    public function deleteTask(): void
    {
        if ($this->deletingTaskId === null) {
            return;
        }

        Task::query()->findOrFail($this->deletingTaskId)->delete();
        $this->deletingTaskId = null;
        $this->resetPage();
        Flux::toast(variant: 'success', text: __('tasks.task_deleted'));
    }

    public function closeTaskModal(): void
    {
        $this->showTaskModal = false;
        $this->resetTaskForm();
    }

    protected function resetTaskForm(): void
    {
        $this->resetValidation();
        $this->editingTaskId = null;
        $this->title = '';
        $this->description = '';
        $this->taskStatus = TaskStatus::Todo->value;
        $this->taskPriority = TaskPriority::Medium->value;
        $this->startDate = '';
        $this->dueDate = '';
        $this->completedDate = '';
        $this->progressPercentage = '0';
        $this->assignedTo = '';
        $this->taskProposalId = '';
        $this->taskMilestoneId = '';
    }

    protected function trimTaskForm(): void
    {
        foreach (['title', 'description', 'taskStatus', 'taskPriority', 'startDate', 'dueDate', 'completedDate', 'progressPercentage', 'assignedTo', 'taskProposalId', 'taskMilestoneId'] as $property) {
            $this->{$property} = trim($this->{$property});
        }
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $status !== '' || $priority !== '' || $assignee !== '' || $milestone !== '';
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="task-directory-heading">
    <x-ui.page-header heading-id="task-directory-heading" :title="__('common.tasks')" :count="$this->tasks->total()" :description="__('tasks.work_items_across_every_proposal_ordered_by_the_nearest_due')">
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('tasks.search_tasks')" :placeholder="__('tasks.title_description_or_assignee')" wire:model.live.debounce.300ms="search" clearable />
            <x-ui.select :label="__('common.status')" wire:model.live="status">
                <flux:select.option value="">{{ __('common.all_statuses') }}</flux:select.option>
                @foreach (TaskStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value" :wire:key="'task-status-filter-'.$statusCase->value">{{ $statusCase->label() }}</flux:select.option>
                @endforeach
            </x-ui.select>
            <x-ui.select :label="__('tasks.priority')" wire:model.live="priority">
                <flux:select.option value="">{{ __('tasks.all_priorities') }}</flux:select.option>
                @foreach (TaskPriority::cases() as $priorityCase)
                    <flux:select.option :value="$priorityCase->value" :wire:key="'task-priority-filter-'.$priorityCase->value">{{ $priorityCase->label() }}</flux:select.option>
                @endforeach
            </x-ui.select>
            <x-ui.select :label="__('tasks.assignee')" wire:model.live="assignee">
                <flux:select.option value="">{{ __('common.anyone') }}</flux:select.option>
                @foreach ($this->userOptions as $userOption)
                    <flux:select.option :value="$userOption->id" :wire:key="'task-assignee-filter-'.$userOption->id">{{ $userOption->name }} · {{ $userOption->secondary_name }}</flux:select.option>
                @endforeach
            </x-ui.select>
            <x-ui.select :label="__('tasks.milestone')" wire:model.live="milestone">
                <flux:select.option value="">{{ __('tasks.any_milestone') }}</flux:select.option>
                <flux:select.option value="none">{{ __('tasks.no_milestone') }}</flux:select.option>
                @foreach ($this->milestoneOptions as $milestoneOption)
                    <flux:select.option :value="$milestoneOption->id" :wire:key="'task-milestone-filter-'.$milestoneOption->id">{{ $milestoneOption->name }} · {{ $milestoneOption->project->name }}</flux:select.option>
                @endforeach
            </x-ui.select>
        </x-slot:filters>
        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-task-button">{{ __('tasks.add_task') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel :subtitle="trans_choice('tasks.count_matching_task', $this->tasks->total(), ['count' => $this->tasks->total()])">
        <x-slot:toolbar>
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" class="text-secondary-medium! hover:bg-secondary-soft! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
            @endif
        </x-slot:toolbar>

        @if ($this->tasks->isEmpty())
            <x-ui.empty-state icon="clipboard-document-check" :heading="$filtersAreActive ? __('tasks.no_matching_tasks') : __('common.no_tasks_yet')" :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('tasks.add_the_first_task_against_a_proposal')">
                <x-slot:action>
                    @if ($filtersAreActive)
                        <x-ui.button size="sm" wire:click="clearFilters">{{ __('common.reset_filters') }}</x-ui.button>
                    @else
                        <x-ui.button size="sm" tone="primary" icon="plus" wire:click="openCreateModal">{{ __('tasks.add_task') }}</x-ui.button>
                    @endif
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->tasks as $task)
                    <article class="flex flex-col gap-3 p-3.5 @4xl/directory:flex-row @4xl/directory:items-center" wire:key="task-{{ $task->id }}">
                        <div class="min-w-0 @4xl/directory:me-auto">
                            <a href="{{ route('tasks.show', $task) }}" wire:navigate class="text-sm font-semibold hover:underline">{{ $task->title }}</a>
                            <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">
                                @if ($task->milestone === null)
                                    <span class="italic">{{ __('tasks.no_milestone') }}</span>
                                @else
                                    {{ $task->milestone->name }} · {{ $task->milestone->project->name }}
                                @endif
                                · {{ $task->proposal->name }}
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.enum-badge :case="$task->status" />
                            <x-ui.enum-badge :case="$task->priority" />
                            <span class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $task->progress_percentage }}%</span>
                            @if ($task->due_date !== null)
                                <span class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">{{ __('tasks.due_date', ['date' => $task->due_date->translatedFormat(__('formats.date_short'))]) }}</span>
                            @endif
                        </div>
                        <x-ui.employee-chip :employee="$task->assignee" compact class="@4xl/directory:w-44" />
                        <div class="flex shrink-0 items-center gap-1">
                            <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-11! touch-manipulation @sm/directory:size-9!" wire:click="openEditModal({{ $task->id }})" :aria-label="__('tasks.edit_title', ['title' => $task->title])" />
                            <x-ui.button tone="ghost" size="sm" icon="trash" class="size-11! touch-manipulation @sm/directory:size-9!" wire:click="confirmDelete({{ $task->id }})" :aria-label="__('tasks.delete_title', ['title' => $task->title])" />
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        <x-slot:footer>
            <flux:pagination :paginator="$this->tasks" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#task-directory-heading" />
        </x-slot:footer>
    </x-ui.panel>

    <flux:modal wire:model="showTaskModal" class="w-full max-w-3xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveTask" class="flex flex-col gap-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingTaskId === null ? __('tasks.add_task') : __('tasks.edit_task') }}</flux:heading>
                <flux:subheading>{{ __('tasks.every_task_delivers_against_a_proposal_a_milestone_is_option') }}</flux:subheading>
            </div>
            <x-ui.input wire:model="title" :label="__('tasks.task_title')" type="text" maxlength="255" required autofocus />
            <flux:textarea wire:model="description" :label="__('common.description')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:border-secondary-light! dark:bg-secondary-medium! dark:text-white!" />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select wire:model="taskProposalId" :label="__('common.proposal')" required>
                    <flux:select.option value="">{{ __('common.select_a_proposal') }}</flux:select.option>
                    @foreach ($this->proposalOptions as $proposalOption)
                        <flux:select.option :value="$proposalOption->id" :wire:key="'task-proposal-option-'.$proposalOption->id">{{ $proposalOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>
                <x-ui.select wire:model="taskMilestoneId" :label="__('tasks.milestone')">
                    <flux:select.option value="">{{ __('tasks.no_milestone') }}</flux:select.option>
                    @foreach ($this->milestoneOptions as $milestoneOption)
                        <flux:select.option :value="$milestoneOption->id" :wire:key="'task-milestone-option-'.$milestoneOption->id">{{ $milestoneOption->name }} · {{ $milestoneOption->project->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 @2xl/directory:grid-cols-4">
                <x-ui.select wire:model="taskStatus" :label="__('common.status')" required>
                    @foreach (TaskStatus::cases() as $statusCase)
                        <flux:select.option :value="$statusCase->value" :wire:key="'task-status-option-'.$statusCase->value">{{ $statusCase->label() }}</flux:select.option>
                    @endforeach
                </x-ui.select>
                <x-ui.select wire:model="taskPriority" :label="__('tasks.priority')" required>
                    @foreach (TaskPriority::cases() as $priorityCase)
                        <flux:select.option :value="$priorityCase->value" :wire:key="'task-priority-option-'.$priorityCase->value">{{ $priorityCase->label() }}</flux:select.option>
                    @endforeach
                </x-ui.select>
                <x-ui.input wire:model="progressPercentage" :label="__('tasks.progress_2')" type="number" min="0" max="100" required />
                <x-ui.select wire:model="assignedTo" :label="__('tasks.assignee')" required>
                    <flux:select.option value="">{{ __('common.select') }}</flux:select.option>
                    @foreach ($this->userOptions as $userOption)
                        <flux:select.option :value="$userOption->id" :wire:key="'task-assignee-option-'.$userOption->id">{{ $userOption->name }} · {{ $userOption->secondary_name }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.input wire:model="startDate" :label="__('common.start_date')" type="date" />
                <x-ui.input wire:model="dueDate" :label="__('tasks.due_date_2')" type="date" />
                <x-ui.input wire:model="completedDate" :label="__('tasks.completed_date')" type="date" />
            </div>
            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="closeTaskModal">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" data-test="save-task-button">{{ $editingTaskId === null ? __('tasks.create_task') : __('common.save_changes') }}</x-ui.button>
            </div>
        </form>
    </flux:modal>

    <flux:modal :open="$deletingTaskId !== null" wire:close="$set('deletingTaskId', null)" class="w-full max-w-md border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('tasks.delete_this_task') }}</flux:heading>
            <flux:subheading>{{ __('tasks.its_comments_and_attachment_records_are_removed_with_it_noth') }}</flux:subheading>
            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="$set('deletingTaskId', null)">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="deleteTask" data-test="confirm-delete-task">{{ __('tasks.delete_task') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</section>
