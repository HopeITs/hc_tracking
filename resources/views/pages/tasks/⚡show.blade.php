<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Milestone;
use App\Models\Task;
use App\Models\TaskAttachment;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('titles.task')] class extends Component
{
    #[Locked]
    public int $taskId;

    public string $newSubtaskTitle = '';

    public function mount(Task $task): void
    {
        Gate::authorize('view', $task);

        $this->taskId = $task->id;
    }

    #[Computed]
    public function task(): Task
    {
        return Task::query()
            ->select([
                'id',
                'parent_task_id',
                'source_type',
                'source_id',
                'title',
                'description',
                'status',
                'priority',
                'start_date',
                'due_date',
                'completed_date',
                'progress_percentage',
                'created_by',
            ])
            ->with([
                // morphWith() eager loads the milestone's project on top of
                // the source itself in the same batch, so the meta line
                // below can show "milestone · project" without an extra
                // query — the other four source types have nothing further
                // to load.
                'source' => fn (MorphTo $morphTo) => $morphTo->morphWith([Milestone::class => ['project:id,name']]),
                'taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
                'creator:id,hr_user_id',
                'parentTask:id,title',
            ])
            ->findOrFail($this->taskId);
    }

    /** @return Collection<int, Task> */
    #[Computed]
    public function subtasks(): Collection
    {
        return Task::query()
            ->select(['id', 'title', 'status', 'priority', 'due_date', 'progress_percentage'])
            ->where('parent_task_id', $this->taskId)
            ->with('taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar')
            ->orderBy('id')
            ->get();
    }

    /**
     * A subtask inherits its parent's source and assignees so it can be
     * created immediately from a single title — the same required fields
     * the top-level "add task" flow needs, just pre-filled here instead of
     * asked for, since the parent already answers them.
     */
    public function addSubtask(): void
    {
        $this->newSubtaskTitle = trim($this->newSubtaskTitle);

        $validated = $this->validate([
            'newSubtaskTitle' => ['required', 'string', 'max:255'],
        ], [
            'newSubtaskTitle.required' => __('tasks.enter_a_task_title'),
        ]);

        $parent = $this->task;

        $subtask = Task::query()->create([
            'parent_task_id' => $parent->id,
            'source_type' => $parent->source_type,
            'source_id' => $parent->source_id,
            'title' => $validated['newSubtaskTitle'],
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Medium,
            'created_by' => (int) auth()->id(),
        ]);

        foreach ($parent->taskAssignees as $parentAssignee) {
            $subtask->taskAssignees()->create(['employee_id' => $parentAssignee->employee_id]);
        }

        $this->newSubtaskTitle = '';
        unset($this->subtasks);

        Flux::toast(variant: 'success', text: __('tasks.subtask_added'));
    }

    public function toggleSubtaskComplete(int $subtaskId): void
    {
        $subtask = Task::query()->where('parent_task_id', $this->taskId)->find($subtaskId);

        if ($subtask === null) {
            return;
        }

        if ($subtask->status === TaskStatus::Done) {
            $subtask->status = TaskStatus::Todo;
            $subtask->completed_date = null;
        } else {
            $subtask->status = TaskStatus::Done;
            $subtask->completed_date = CarbonImmutable::today();
            $subtask->progress_percentage = 100;
        }

        $subtask->save();
        unset($this->subtasks);
    }

    /** @return Collection<int, TaskAttachment> */
    #[Computed]
    public function attachments(): Collection
    {
        return TaskAttachment::query()
            ->select(['id', 'task_id', 'path', 'type', 'date'])
            ->where('task_id', $this->taskId)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }
};
?>

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="task-detail-heading">
    @php $assignees = $this->task->taskAssignees->pluck('employee')->filter(); @endphp

    <x-ui.detail-header
        id="task-detail-heading"
        :breadcrumbs="[
            ['label' => __('common.tasks'), 'url' => route('tasks.index')],
            ['label' => $this->task->title],
        ]"
        :title="$this->task->title"
        :subtitle="$this->task->sourceLabel()"
    >
        <x-slot:meta>
            @if ($this->task->source instanceof \App\Models\Milestone)
                <a href="{{ route('projects.show', $this->task->source->project) }}" wire:navigate class="font-medium hover:underline">
                    {{ $this->task->source->name }} · {{ $this->task->source->project->name }}
                </a>
            @endif
            <span>{{ __('tasks.assigned_to_name', ['name' => $assignees->isEmpty() ? __('common.not_assigned') : $assignees->map->name->implode(', ')]) }}</span>
            <span>{{ __('tasks.created_by_name', ['name' => $this->task->creator->name]) }}</span>
            @if ($this->task->parentTask !== null)
                <a href="{{ route('tasks.show', $this->task->parentTask) }}" wire:navigate class="font-medium hover:underline">
                    {{ __('tasks.subtask_of_title', ['title' => $this->task->parentTask->title]) }}
                </a>
            @endif
        </x-slot:meta>

        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.enum-badge :case="$this->task->status" />
                <x-ui.enum-badge :case="$this->task->priority" />
            </div>
        </x-slot:actions>
    </x-ui.detail-header>

    <div class="grid gap-3 @2xl/detail:grid-cols-4">
        <x-ui.stat-tile :label="__('tasks.progress')" :value="$this->task->progress_percentage.'%'" />
        <x-ui.stat-tile :label="__('common.start')" :value="$this->task->start_date?->translatedFormat(__('formats.date')) ?? '—'" />
        <x-ui.stat-tile :label="__('tasks.due')" :value="$this->task->due_date?->translatedFormat(__('formats.date')) ?? '—'" />
        <x-ui.stat-tile :label="__('common.completed')" :value="$this->task->completed_date?->translatedFormat(__('formats.date')) ?? '—'" />
    </div>

    @if ($this->task->description !== null)
        <x-ui.panel :title="__('common.description')">
            <p class="whitespace-pre-line p-3.5 text-sm text-secondary-medium dark:text-secondary-soft">{{ $this->task->description }}</p>
        </x-ui.panel>
    @endif

    @if ($this->task->parentTask === null)
        <x-ui.panel :title="__('tasks.subtasks')" :count="$this->subtasks->count()" :subtitle="__('tasks.subtasks_inherit_this_task_s_proposal_milestone_and_assign')">
            <div class="border-b border-secondary-soft p-3.5 dark:border-secondary-medium">
                <form wire:submit="addSubtask" class="flex flex-col gap-2 sm:flex-row sm:items-start">
                    <div class="flex-1">
                        <x-ui.input
                            wire:model="newSubtaskTitle"
                            :label="__('tasks.subtask_title')"
                            :placeholder="__('tasks.what_needs_to_happen')"
                            maxlength="255"
                            data-test="new-subtask-input"
                        />
                    </div>
                    <x-ui.button type="submit" tone="primary" size="sm" icon="plus" class="mt-6 shrink-0 sm:mt-0" wire:loading.attr="disabled" wire:target="addSubtask" data-test="add-subtask-button">
                        <span wire:loading.remove wire:target="addSubtask">{{ __('tasks.add_subtask') }}</span>
                        <span wire:loading wire:target="addSubtask">{{ __('tasks.adding') }}</span>
                    </x-ui.button>
                </form>
            </div>

            @if ($this->subtasks->isEmpty())
                <x-ui.empty-state icon="rectangle-stack" :heading="__('tasks.no_subtasks_yet')" :body="__('tasks.break_this_task_down_into_smaller_steps')" />
            @else
                <ul class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->subtasks as $subtask)
                        @php
                            $isSubtaskDone = $subtask->status === TaskStatus::Done;
                            $subtaskAssignees = $subtask->taskAssignees->pluck('employee')->filter();
                        @endphp
                        <li class="flex flex-wrap items-center gap-3 p-3.5" wire:key="subtask-{{ $subtask->id }}">
                            <button
                                type="button"
                                wire:click="toggleSubtaskComplete({{ $subtask->id }})"
                                class="inline-flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-full text-secondary-medium hover:bg-secondary-soft dark:text-secondary-soft dark:hover:bg-secondary-medium"
                                aria-label="{{ $isSubtaskDone ? __('tasks.mark_incomplete') : __('tasks.mark_complete') }}"
                                data-test="subtask-toggle-complete-{{ $subtask->id }}"
                            >
                                <flux:icon.check-circle :variant="$isSubtaskDone ? 'solid' : 'outline'" class="size-4.5 {{ $isSubtaskDone ? 'text-status-good' : '' }}" />
                            </button>
                            <a href="{{ route('tasks.show', $subtask) }}" wire:navigate @class(['min-w-0 flex-1 truncate text-sm font-medium hover:underline', 'text-secondary-light line-through' => $isSubtaskDone])>
                                {{ $subtask->title }}
                            </a>
                            <x-ui.enum-badge :case="$subtask->status" />
                            <x-ui.enum-badge :case="$subtask->priority" />
                            <span class="w-40 shrink-0 truncate text-xs text-secondary-medium dark:text-secondary-soft">
                                {{ $subtaskAssignees->isEmpty() ? __('common.not_assigned') : $subtaskAssignees->map->name->implode(', ') }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.panel>
    @endif

    <x-ui.panel :title="__('tasks.attachments')" :count="$this->attachments->count()" :subtitle="__('common.file_records_only_the_files_themselves_are_not_stored_in_thi')">
        @if ($this->attachments->isEmpty())
            <x-ui.empty-state icon="paper-clip" :heading="__('common.no_files_recorded')" :body="__('tasks.attachment_records_for_this_task_will_appear_here')" />
        @else
            <ul class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->attachments as $attachment)
                    {{-- Storage has no corresponding files, so metadata must never become a download link. --}}
                    <li class="flex items-center gap-3 p-3.5" wire:key="task-attachment-{{ $attachment->id }}">
                        <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-md border border-secondary-light text-[11px] font-semibold text-secondary-medium uppercase dark:text-secondary-soft">
                            {{ Str::upper($attachment->type->value) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ basename($attachment->path) }}</p>
                            <p class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $attachment->date->translatedFormat(__('formats.date')) }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.panel>
</section>
