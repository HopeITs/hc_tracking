<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\PreviewsFiles;
use App\Livewire\Concerns\Tasks\ManagesSubtasks;
use App\Livewire\Concerns\Tasks\ManagesTaskAttachments;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Task;
use App\Models\TaskAttachment;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('titles.task')] class extends Component
{
    use ManagesSubtasks;
    use ManagesTaskAttachments;
    use PreviewsFiles;
    use WithFileUploads;

    #[Locked]
    public int $taskId;

    public string $title = '';

    public string $description = '';

    public string $taskStatus = 'todo';

    public string $taskPriority = 'medium';

    public string $startDate = '';

    public string $dueDate = '';

    public string $completedDate = '';

    public string $progressPercentage = '0';

    /** @var array<int, string> */
    public array $assignedTo = [];

    public function mount(Task $task): void
    {
        Gate::authorize('view', $task);

        $this->taskId = $task->id;

        $freshTask = $this->task;
        $this->title = $freshTask->title;
        $this->description = $freshTask->description ?? '';
        $this->taskStatus = $freshTask->status->value;
        $this->taskPriority = $freshTask->priority->value;
        $this->startDate = $freshTask->start_date?->toDateString() ?? '';
        $this->dueDate = $freshTask->due_date?->toDateString() ?? '';
        $this->completedDate = $freshTask->completed_date?->toDateString() ?? '';
        $this->progressPercentage = (string) $freshTask->progress_percentage;
        $this->assignedTo = $freshTask->taskAssignees->pluck('employee_id')->map(fn (int $id): string => (string) $id)->all();
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

    /**
     * Save the task. Editors (tasks.update, or the task's creator) change
     * every field; its assignees may only move status and progress, so for
     * them every other field keeps its stored value.
     */
    public function updateTask(): void
    {
        $task = Task::query()->findOrFail($this->taskId);
        $canEdit = Gate::allows('update', $task);

        if (! $canEdit) {
            Gate::authorize('updateStatus', $task);
        }

        $this->title = trim($this->title);
        $this->description = trim($this->description);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'taskStatus' => ['required', new Enum(TaskStatus::class)],
            'taskPriority' => ['required', new Enum(TaskPriority::class)],
            'startDate' => ['nullable', 'date'],
            'dueDate' => ['nullable', 'date', Rule::when($this->startDate !== '', ['after_or_equal:startDate'])],
            'completedDate' => ['nullable', 'date'],
            'progressPercentage' => ['required', 'integer', 'min:0', 'max:100'],
            'assignedTo' => ['required', 'array', 'min:1'],
            'assignedTo.*' => ['integer', Rule::exists(HrEmployee::class, 'id')],
        ], [
            'title.required' => __('tasks.enter_a_task_title'),
            'assignedTo.required' => __('tasks.assign_this_task_to_someone'),
            'assignedTo.min' => __('tasks.assign_this_task_to_someone'),
            'assignedTo.*.exists' => __('tasks.the_selected_assignee_is_no_longer_available'),
            'dueDate.after_or_equal' => __('tasks.the_due_date_cannot_fall_before_the_start_date'),
            'progressPercentage.min' => __('tasks.progress_cannot_be_negative'),
            'progressPercentage.max' => __('tasks.progress_cannot_exceed_100_percent'),
        ]);

        DB::transaction(function () use ($task, $validated, $canEdit): void {
            $progress = [
                'status' => $validated['taskStatus'],
                'completed_date' => $validated['completedDate'] === '' ? null : $validated['completedDate'],
                'progress_percentage' => (int) $validated['progressPercentage'],
            ];

            if (! $canEdit) {
                $task->fill($progress)->save();

                return;
            }

            $task->fill([
                ...$progress,
                'title' => $validated['title'],
                'description' => $validated['description'] === '' ? null : $validated['description'],
                'priority' => $validated['taskPriority'],
                'start_date' => $validated['startDate'] === '' ? null : $validated['startDate'],
                'due_date' => $validated['dueDate'] === '' ? null : $validated['dueDate'],
            ])->save();

            $employeeIds = collect($validated['assignedTo'])->map(fn ($id): int => (int) $id)->unique()->values();

            $task->taskAssignees()->whereNotIn('employee_id', $employeeIds)->delete();
            $existingIds = $task->taskAssignees()->pluck('employee_id');

            $employeeIds->diff($existingIds)->each(fn (int $employeeId) => $task->taskAssignees()->create([
                'employee_id' => $employeeId,
            ]));
        });

        unset($this->task);

        Flux::toast(variant: 'success', text: __('tasks.task_updated'));
    }

    /**
     * The task's attachments, in the order the page lists them.
     *
     * @return Collection<int, TaskAttachment>
     */
    protected function previewableFiles(): Collection
    {
        return $this->attachments;
    }

    /**
     * Viewing is not an edit, so it stays available to anyone who can see
     * the task. An id that is not one of this task's attachments is a 404.
     */
    protected function previewableFileId(int $fileId): ?int
    {
        return $this->taskAttachment($fileId)->id;
    }
};
?>

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" aria-labelledby="task-detail-heading">
    @php $assignees = $this->task->taskAssignees->pluck('employee')->filter(); @endphp

    <x-ui.detail-header
        id="task-detail-heading"
        :breadcrumbs="[
            ['label' => __('common.tasks'), 'url' => route('tasks.index')],
            ['label' => $this->task->title],
        ]"
        :title="$this->task->title"
        :unread="app(\App\Notifications\UnreadSubjects::class)->has(auth()->user(), 'task', $this->task->id)"
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
            <p class="whitespace-pre-line p-3.5 text-sm text-secondary-medium dark:text-ink-muted">{{ $this->task->description }}</p>
        </x-ui.panel>
    @endif

    @php
        $canEditTask = auth()->user()->can('update', $this->task);
        $canUpdateTaskStatus = $canEditTask || auth()->user()->can('updateStatus', $this->task);
    @endphp

    @if ($canUpdateTaskStatus)
        @include('pages.tasks._show-status-panel')
    @endif

    @if ($this->task->parent_task_id === null)
        @include('pages.tasks._show-subtasks')
    @endif

    @php $canEditAttachments = auth()->user()->can('update', $this->task); @endphp
    @include('pages.tasks._show-attachments')

    <section aria-labelledby="record-activity-heading" class="grid gap-3" data-record-activity>
        <h2 id="record-activity-heading" class="text-base font-bold">{{ __('activity.title') }}</h2>
        <livewire:activity.timeline :subjects="['task' => [$this->task->id]]" :key="'task-activity-'.$this->task->id" />
    </section>

    @if ($this->task->parent_task_id === null)
        @include('pages.tasks._show-subtasks-modal')
    @endif

    @include('pages.tasks._show-modals')
</section>
