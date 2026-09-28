<?php

use App\Enums\FileType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Task;
use App\Models\TaskAttachment;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('titles.task')] class extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $taskId;

    public bool $showAddSubtaskModal = false;

    public string $newSubtaskTitle = '';

    public string $newSubtaskStatus = 'todo';

    public string $newSubtaskPriority = 'medium';

    public string $newSubtaskDueDate = '';

    /** @var array<int, string> */
    public array $newSubtaskAssignedTo = [];

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

    public bool $showFileModal = false;

    #[Locked]
    public ?int $editingFileId = null;

    public $newFile = null;

    public string $fileDisplayName = '';

    public string $fileDate = '';

    public bool $showDeleteFileModal = false;

    #[Locked]
    public ?int $deletingFileId = null;

    public bool $showPreviewModal = false;

    #[Locked]
    public ?int $previewingFileId = null;

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

    /** @return Collection<int, HrEmployee> */
    #[Computed]
    public function userOptions(): Collection
    {
        return HrEmployee::pickerOptions();
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
     * A new subtask inherits its parent's source, so only its own title,
     * status, priority, due date and assignees need asking for. Assignees
     * default to the parent's own — mirrors the list page's subtaskDraft()
     * default — so the common case (same people finish the smaller step)
     * needs no extra clicks.
     */
    public function openAddSubtaskModal(): void
    {
        Gate::authorize('create', Task::class);

        $this->resetValidation();
        $this->newSubtaskTitle = '';
        $this->newSubtaskStatus = TaskStatus::Todo->value;
        $this->newSubtaskPriority = TaskPriority::Medium->value;
        $this->newSubtaskDueDate = '';
        $this->newSubtaskAssignedTo = $this->task->taskAssignees->pluck('employee_id')->map(fn (int $id): string => (string) $id)->all();
        $this->showAddSubtaskModal = true;
    }

    public function closeAddSubtaskModal(): void
    {
        $this->showAddSubtaskModal = false;
    }

    public function addSubtask(): void
    {
        // Subtasks are one level deep: judged by the column, since the parent
        // itself may be hidden from this viewer.
        if ($this->task->parent_task_id !== null) {
            return;
        }

        Gate::authorize('create', Task::class);

        $this->newSubtaskTitle = trim($this->newSubtaskTitle);

        $validated = $this->validate([
            'newSubtaskTitle' => ['required', 'string', 'max:255'],
            'newSubtaskStatus' => ['required', new Enum(TaskStatus::class)],
            'newSubtaskPriority' => ['required', new Enum(TaskPriority::class)],
            'newSubtaskDueDate' => ['nullable', 'date'],
            'newSubtaskAssignedTo' => ['required', 'array', 'min:1'],
            'newSubtaskAssignedTo.*' => ['integer', Rule::exists(HrEmployee::class, 'id')],
        ], [
            'newSubtaskTitle.required' => __('tasks.enter_a_task_title'),
            'newSubtaskAssignedTo.required' => __('tasks.assign_this_task_to_someone'),
            'newSubtaskAssignedTo.min' => __('tasks.assign_this_task_to_someone'),
            'newSubtaskAssignedTo.*.exists' => __('tasks.the_selected_assignee_is_no_longer_available'),
        ]);

        $parent = $this->task;
        $statusCase = TaskStatus::from($validated['newSubtaskStatus']);
        $employeeIds = collect($validated['newSubtaskAssignedTo'])->map(fn ($id): int => (int) $id)->unique()->values();

        DB::transaction(function () use ($parent, $validated, $statusCase, $employeeIds): void {
            $subtask = Task::query()->create([
                'parent_task_id' => $parent->id,
                'source_type' => $parent->source_type,
                'source_id' => $parent->source_id,
                'title' => $validated['newSubtaskTitle'],
                'status' => $statusCase,
                'priority' => $validated['newSubtaskPriority'],
                'due_date' => $validated['newSubtaskDueDate'] === '' ? null : $validated['newSubtaskDueDate'],
                'progress_percentage' => $statusCase === TaskStatus::Done ? 100 : 0,
                'completed_date' => $statusCase === TaskStatus::Done ? CarbonImmutable::today() : null,
                'created_by' => (int) auth()->id(),
            ]);

            foreach ($employeeIds as $employeeId) {
                $subtask->taskAssignees()->create(['employee_id' => $employeeId]);
            }
        });

        $this->showAddSubtaskModal = false;
        unset($this->subtasks);

        Flux::toast(variant: 'success', text: __('tasks.subtask_added'));
    }

    /** @return Collection<int, TaskAttachment> */
    #[Computed]
    public function attachments(): Collection
    {
        return TaskAttachment::query()
            ->select(['id', 'task_id', 'path', 'disk', 'original_name', 'size', 'date', 'type', 'uploaded_by'])
            ->with('uploader:id,hr_user_id')
            ->where('task_id', $this->taskId)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    public function openCreateFileModal(): void
    {
        Gate::authorize('update', $this->task);

        $this->resetFileForm();
        $this->fileDate = now()->toDateString();
        $this->showFileModal = true;
    }

    public function openEditFileModal(int $fileId): void
    {
        Gate::authorize('update', $this->task);

        $file = $this->taskAttachment($fileId);

        $this->resetFileForm();
        $this->editingFileId = $file->id;
        $this->fileDisplayName = $file->displayName();
        $this->fileDate = $file->date->toDateString();
        $this->showFileModal = true;
    }

    /**
     * Upload a new attachment, or rename, redate and optionally replace an
     * existing one — the same rules as a report's supporting files.
     */
    public function saveFile(): void
    {
        Gate::authorize('update', $this->task);

        $isCreate = $this->editingFileId === null;
        $allowedTypes = implode(',', FileType::values());

        $validated = $this->validate([
            'newFile' => [$isCreate ? 'required' : 'nullable', 'file', 'max:5120', 'mimes:'.$allowedTypes, 'extensions:'.$allowedTypes],
            'fileDisplayName' => [$isCreate ? 'nullable' : 'required', 'string', 'max:255'],
            'fileDate' => ['required', 'date'],
        ], [
            'newFile.required' => __('tasks.select_a_file_to_upload'),
            'fileDisplayName.required' => __('tasks.enter_a_file_name'),
            'fileDate.required' => __('tasks.set_the_file_date'),
        ]);

        $file = $isCreate
            ? new TaskAttachment(['task_id' => $this->taskId])
            : $this->taskAttachment($this->editingFileId);

        $replacedFile = null;

        if ($this->newFile !== null) {
            if (! $isCreate && $file->original_name !== null) {
                $replacedFile = ['disk' => $file->disk, 'path' => $file->path];
            }

            $file->fill([
                'disk' => 'local',
                'path' => $this->newFile->store('task-attachments/'.$this->taskId, 'local'),
                'type' => FileType::from(Str::lower($this->newFile->getClientOriginalExtension())),
                'mime_type' => $this->newFile->getMimeType(),
                'size' => $this->newFile->getSize(),
                'uploaded_by' => auth()->id(),
            ]);
        }

        $displayName = trim((string) ($validated['fileDisplayName'] ?? ''));

        $file->fill([
            'original_name' => $displayName !== ''
                ? $this->displayNameWithExtension($displayName, $file->type)
                : $this->newFile->getClientOriginalName(),
            'date' => $validated['fileDate'],
        ])->save();

        if ($replacedFile !== null) {
            $this->removeFromDisk($file->id, $replacedFile['disk'], $replacedFile['path']);
        }

        $this->closeFileModal();
        unset($this->attachments);

        Flux::toast(variant: 'success', text: $isCreate ? __('tasks.file_uploaded') : __('tasks.file_updated'));
    }

    public function closeFileModal(): void
    {
        $this->showFileModal = false;
        $this->resetFileForm();
    }

    protected function resetFileForm(): void
    {
        $this->resetValidation();
        $this->editingFileId = null;
        $this->newFile = null;
        $this->fileDisplayName = '';
        $this->fileDate = '';
    }

    public function confirmDeleteFile(int $fileId): void
    {
        Gate::authorize('update', $this->task);

        $this->deletingFileId = $this->taskAttachment($fileId)->id;
        $this->showDeleteFileModal = true;
    }

    /**
     * Delete an attachment's row and, for a real upload, its file on disk.
     */
    public function deleteFile(): void
    {
        Gate::authorize('update', $this->task);

        if ($this->deletingFileId === null) {
            $this->closeDeleteFileModal();

            return;
        }

        $file = $this->taskAttachment($this->deletingFileId);

        if ($file->original_name !== null) {
            $this->removeFromDisk($file->id, $file->disk, $file->path);
        }

        $file->delete();

        $this->closeDeleteFileModal();
        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('tasks.file_deleted'));
    }

    public function closeDeleteFileModal(): void
    {
        $this->showDeleteFileModal = false;
        $this->deletingFileId = null;
    }

    /**
     * Open the in-page preview for one of this task's attachments.
     *
     * Viewing is not an edit, so it stays available to anyone who can see
     * the task.
     */
    public function openPreview(int $fileId): void
    {
        $this->previewingFileId = $this->taskAttachment($fileId)->id;
        $this->showPreviewModal = true;
    }

    /**
     * Step the open preview to the previous (-1) or next (1) file, wrapping.
     */
    public function previewAdjacent(int $direction): void
    {
        $ids = $this->attachments->pluck('id')->values();
        $position = $ids->search($this->previewingFileId);

        if ($position === false || $ids->count() < 2) {
            return;
        }

        $this->previewingFileId = $ids[($position + ($direction < 0 ? -1 : 1) + $ids->count()) % $ids->count()];
    }

    public function closePreview(): void
    {
        $this->showPreviewModal = false;
        $this->previewingFileId = null;
    }

    /**
     * The attachment behind a file action, scoped to this task.
     *
     * The id comes from the client, so without this scope a user could act
     * on another task's attachment by tampering with it (IDOR).
     */
    private function taskAttachment(int $fileId): TaskAttachment
    {
        return TaskAttachment::query()->where('task_id', $this->taskId)->findOrFail($fileId);
    }

    /**
     * A user-typed display name, guaranteed to carry the file's real
     * extension, since it becomes the downloaded filename.
     */
    private function displayNameWithExtension(string $displayName, FileType $type): string
    {
        if (Str::lower(pathinfo($displayName, PATHINFO_EXTENSION)) === $type->value) {
            return $displayName;
        }

        return $displayName.'.'.$type->value;
    }

    /**
     * Remove a stored file, logging rather than surfacing a storage failure
     * so a disk hiccup never leaves a record stuck in the UI.
     */
    private function removeFromDisk(int $fileId, string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (\Throwable $exception) {
            Log::warning('Failed to delete task attachment from disk.', [
                'task_attachment_id' => $fileId,
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);
        }
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
            <p class="whitespace-pre-line p-3.5 text-sm text-secondary-medium dark:text-secondary-soft">{{ $this->task->description }}</p>
        </x-ui.panel>
    @endif

    @php
        $selectedAssigneeOptions = $this->userOptions->whereIn('id', collect($assignedTo)->map(fn ($id) => (int) $id));
        $assigneeTriggerLabel = match (true) {
            $selectedAssigneeOptions->isEmpty() => __('common.select'),
            $selectedAssigneeOptions->count() <= 2 => $selectedAssigneeOptions->pluck('name')->implode(', '),
            default => __('common.count_selected', ['count' => $selectedAssigneeOptions->count()]),
        };
    @endphp

    <x-ui.panel :title="__('tasks.edit_task')" :subtitle="__('tasks.edit_task_hint')">
        <form wire:submit="updateTask" class="flex flex-col gap-5 p-4">
            <x-ui.input wire:model="title" :label="__('tasks.task_title')" type="text" maxlength="255" required />

            <flux:textarea wire:model="description" :label="__('common.description')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:border-secondary-light! dark:bg-secondary-medium! dark:text-white!" />

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="min-w-0">
                    <label class="mb-1.5 block text-[11px] font-semibold tracking-[0.1em] text-secondary-medium uppercase dark:text-secondary-soft" id="task-edit-status-label">{{ __('common.status') }}</label>
                    <div class="grid grid-cols-2 gap-1.5" role="radiogroup" aria-labelledby="task-edit-status-label">
                        @foreach (TaskStatus::cases() as $statusCase)
                            @php $isStatusSelected = $taskStatus === $statusCase->value; @endphp
                            <button
                                type="button"
                                role="radio"
                                aria-checked="{{ $isStatusSelected ? 'true' : 'false' }}"
                                wire:click="$set('taskStatus', '{{ $statusCase->value }}')"
                                @class([
                                    'inline-flex min-h-10 cursor-pointer touch-manipulation items-center justify-center gap-1.5 rounded-lg border px-2.5 py-2 text-xs font-semibold whitespace-nowrap transition-colors',
                                    'border-secondary-dark bg-secondary-dark text-white dark:border-white dark:bg-white dark:text-secondary-dark' => $isStatusSelected,
                                    'border-secondary-light text-secondary-medium hover:border-secondary-medium hover:text-secondary-dark dark:text-secondary-soft dark:hover:text-white' => ! $isStatusSelected,
                                ])
                                data-test="task-edit-status-{{ $statusCase->value }}"
                            >
                                {{ $statusCase->label() }}
                            </button>
                        @endforeach
                    </div>
                    <flux:error name="taskStatus" class="mt-1.5" />
                </div>

                <div class="min-w-0">
                    <label class="mb-1.5 block text-[11px] font-semibold tracking-[0.1em] text-secondary-medium uppercase dark:text-secondary-soft" id="task-edit-priority-label">{{ __('tasks.priority') }}</label>
                    <div class="grid grid-cols-3 gap-1.5" role="radiogroup" aria-labelledby="task-edit-priority-label">
                        @foreach (TaskPriority::cases() as $priorityCase)
                            @php $isPrioritySelected = $taskPriority === $priorityCase->value; @endphp
                            <button
                                type="button"
                                role="radio"
                                aria-checked="{{ $isPrioritySelected ? 'true' : 'false' }}"
                                wire:click="$set('taskPriority', '{{ $priorityCase->value }}')"
                                @class([
                                    'inline-flex min-h-10 cursor-pointer touch-manipulation items-center justify-center gap-1.5 rounded-lg border px-2.5 py-2 text-xs font-semibold whitespace-nowrap transition-colors',
                                    'border-secondary-dark bg-secondary-dark text-white dark:border-secondary-medium dark:bg-secondary-medium' => $isPrioritySelected,
                                    'border-secondary-light text-secondary-medium hover:border-secondary-medium hover:text-secondary-dark dark:text-secondary-soft dark:hover:text-white' => ! $isPrioritySelected,
                                ])
                                data-test="task-edit-priority-{{ $priorityCase->value }}"
                            >
                                {{ $priorityCase->label() }}
                            </button>
                        @endforeach
                    </div>
                    <flux:error name="taskPriority" class="mt-1.5" />
                </div>
            </div>

            <div class="min-w-0">
                <label class="mb-1.5 block text-[11px] font-semibold tracking-[0.1em] text-secondary-medium uppercase dark:text-secondary-soft" for="task-edit-progress-slider">{{ __('tasks.progress_2') }}</label>
                <div class="flex items-center gap-3">
                    <input id="task-edit-progress-slider" type="range" wire:model="progressPercentage" min="0" max="100" step="1" class="h-1.5 w-full flex-1 cursor-pointer accent-primary" data-test="task-edit-progress-slider" />
                    <div class="relative w-16 shrink-0">
                        <input type="number" wire:model="progressPercentage" min="0" max="100" required class="w-full rounded-lg border border-secondary-light bg-white py-1.5 ps-2 pe-6 text-end text-sm font-semibold tabular-nums text-secondary-dark focus:ring-1 focus:ring-primary focus:outline-none dark:border-secondary-light dark:bg-secondary-medium dark:text-white" aria-label="{{ __('tasks.progress_2') }}" data-test="task-edit-progress-input" />
                        <span class="pointer-events-none absolute inset-y-0 end-2 flex items-center text-sm text-secondary-medium dark:text-secondary-soft">%</span>
                    </div>
                </div>
                <flux:error name="progressPercentage" class="mt-1.5" />
            </div>

            <div class="min-w-0">
                <label class="mb-1.5 block text-[11px] font-semibold tracking-[0.1em] text-secondary-medium uppercase dark:text-secondary-soft">{{ __('tasks.assignees') }}</label>
                <x-ui.multi-select-popover
                    :label="__('tasks.assignees')"
                    class="flex w-full cursor-pointer items-center justify-between gap-2 rounded-lg border border-secondary-light bg-white px-3 py-2 text-start text-sm dark:border-secondary-light dark:bg-secondary-medium"
                    data-test="task-edit-assignee-picker"
                >
                    <x-slot:trigger>
                        <span class="min-w-0 truncate">{{ $assigneeTriggerLabel }}</span>
                        <flux:icon.chevron-up-down class="size-4 shrink-0 text-secondary-medium dark:text-secondary-soft" aria-hidden="true" />
                    </x-slot:trigger>

                    @foreach ($this->userOptions as $userOption)
                        <label
                            x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())"
                            class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-secondary-soft dark:hover:bg-secondary-medium"
                        >
                            <input type="checkbox" wire:model.live="assignedTo" value="{{ $userOption->id }}" class="rounded border-secondary-light text-primary focus:ring-primary" data-test="task-edit-assignee-checkbox-{{ $userOption->id }}" />
                            <span class="min-w-0 truncate">{{ $userOption->name }} · {{ $userOption->secondary_name }}</span>
                        </label>
                    @endforeach
                </x-ui.multi-select-popover>
                <flux:error name="assignedTo" class="mt-1.5" />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.input wire:model="startDate" :label="__('common.start_date')" type="date" />
                <x-ui.input wire:model="dueDate" :label="__('tasks.due_date_2')" type="date" />
                <x-ui.input wire:model="completedDate" :label="__('tasks.completed_date')" type="date" />
            </div>
            <flux:error name="dueDate" class="-mt-3" />

            <div class="flex justify-end gap-2 border-t border-secondary-soft pt-4 dark:border-secondary-medium">
                <x-ui.button type="submit" tone="primary" class="min-h-11 touch-manipulation sm:min-h-10" data-test="update-task-button">{{ __('common.save_changes') }}</x-ui.button>
            </div>
        </form>
    </x-ui.panel>

    @if ($this->task->parent_task_id === null)
        <x-ui.panel :title="__('tasks.related_tasks')" :count="$this->subtasks->count()" :subtitle="__('tasks.subtasks_inherit_this_task_s_proposal_milestone_and_assign')">
            <x-slot:toolbar>
                @can('create', App\Models\Task::class)<x-ui.button tone="primary" size="sm" icon="plus" wire:click="openAddSubtaskModal" data-test="add-subtask-button">{{ __('tasks.add_subtask') }}</x-ui.button>@endcan
            </x-slot:toolbar>

            @if ($this->subtasks->isEmpty())
                <x-ui.empty-state icon="rectangle-stack" :heading="__('tasks.no_subtasks_yet')" :body="__('tasks.break_this_task_down_into_smaller_steps')">
                    <x-slot:action>
                        @can('create', App\Models\Task::class)<x-ui.button size="sm" tone="primary" icon="plus" wire:click="openAddSubtaskModal">{{ __('tasks.add_subtask') }}</x-ui.button>@endcan
                    </x-slot:action>
                </x-ui.empty-state>
            @else
                <ul class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->subtasks as $subtask)
                        @php $subtaskAssignees = $subtask->taskAssignees->pluck('employee')->filter(); @endphp
                        <li class="flex flex-wrap items-center justify-between gap-3 p-3.5" wire:key="subtask-{{ $subtask->id }}">
                            <a href="{{ route('tasks.show', $subtask) }}" wire:navigate class="min-w-0 flex-1 truncate text-sm font-semibold hover:underline">
                                {{ $subtask->title }}
                            </a>
                            <div class="flex shrink-0 items-center gap-3">
                                <span class="w-40 shrink-0 truncate text-xs text-secondary-medium dark:text-secondary-soft">
                                    {{ $subtaskAssignees->isEmpty() ? __('common.not_assigned') : $subtaskAssignees->map->name->implode(', ') }}
                                </span>
                                <span class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">
                                    {{ $subtask->due_date?->translatedFormat(__('formats.date')) ?? '—' }}
                                </span>
                                <x-ui.enum-badge :case="$subtask->status" />
                                <x-ui.enum-badge :case="$subtask->priority" />
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.panel>
    @endif

    @php $canEditAttachments = auth()->user()->can('update', $this->task); @endphp
    <x-ui.panel :title="__('tasks.attachments')" :count="$this->attachments->count()" :subtitle="__('tasks.attachments_subtitle')">
        @if ($canEditAttachments)
            <x-slot:toolbar>
                <x-ui.button size="sm" tone="primary" icon="arrow-up-tray" wire:click="openCreateFileModal" data-test="create-file-button">
                    {{ __('tasks.upload_file') }}
                </x-ui.button>
            </x-slot:toolbar>
        @endif

        @if ($this->attachments->isEmpty())
            <x-ui.empty-state icon="paper-clip" :heading="__('common.no_files_recorded')" :body="__('tasks.attachment_records_for_this_task_will_appear_here')">
                @if ($canEditAttachments)
                    <x-slot:action>
                        <x-ui.button size="sm" tone="primary" icon="arrow-up-tray" wire:click="openCreateFileModal">{{ __('tasks.upload_file') }}</x-ui.button>
                    </x-slot:action>
                @endif
            </x-ui.empty-state>
        @else
            <ul class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->attachments as $attachment)
                    @php
                        $attachmentName = $attachment->displayName();
                        $isStored = $attachment->isStored();
                        $fileIcon = match ($attachment->type) {
                            FileType::Xlsx => 'table-cells',
                            FileType::Jpg, FileType::Png => 'photo',
                            default => 'document-text',
                        };
                    @endphp
                    <li class="flex flex-wrap items-center gap-3 p-3.5" wire:key="task-attachment-{{ $attachment->id }}">
                        @if ($isStored && in_array($attachment->type, [FileType::Jpg, FileType::Png], true))
                            <button type="button" wire:click="openPreview({{ $attachment->id }})" class="size-10 shrink-0 overflow-hidden rounded-xl border border-secondary-soft dark:border-secondary-medium" aria-label="{{ __('tasks.preview_name', ['name' => $attachmentName]) }}">
                                <img src="{{ route('tasks.files.preview', [$this->task, $attachment]) }}" alt="" loading="lazy" class="size-full object-cover" />
                            </button>
                        @else
                            <span class="inline-flex size-10 shrink-0 flex-col items-center justify-center gap-0.5 rounded-xl border border-secondary-soft bg-board-head dark:border-secondary-medium dark:bg-secondary-medium/30">
                                <flux:icon :name="$fileIcon" variant="micro" class="size-3.5 text-secondary-medium dark:text-secondary-soft" />
                                <span class="text-[10px] font-bold text-secondary-dark dark:text-white">{{ Str::upper($attachment->type->value) }}</span>
                            </span>
                        @endif

                        <div class="min-w-0 flex-1">
                            @if ($isStored)
                                <button type="button" wire:click="openPreview({{ $attachment->id }})" class="block max-w-full truncate text-start text-sm font-medium hover:text-primary hover:underline" title="{{ $attachmentName }}" data-test="preview-file-name">{{ $attachmentName }}</button>
                            @else
                                <p class="truncate text-sm font-medium" title="{{ $attachmentName }}">{{ $attachmentName }}</p>
                            @endif
                            <p class="flex flex-wrap items-center gap-x-1.5 text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">
                                <time datetime="{{ $attachment->date?->toDateString() }}">{{ $attachment->date?->translatedFormat(__('formats.date')) }}</time>
                                @if ($attachment->size !== null)
                                    <span aria-hidden="true">&middot;</span>
                                    <span>{{ \Illuminate\Support\Number::fileSize($attachment->size, precision: 1) }}</span>
                                @endif
                                @if ($attachment->uploader !== null)
                                    <span aria-hidden="true">&middot;</span>
                                    <span class="truncate">{{ $attachment->uploader->name }}</span>
                                @endif
                                @unless ($isStored)
                                    <span class="rounded-md border border-secondary-light px-1.5 text-[11px] font-semibold">{{ __('tasks.reference_only') }}</span>
                                @endunless
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            @if ($isStored)
                                <x-ui.button tone="ghost" size="sm" icon="eye" class="size-8!" wire:click="openPreview({{ $attachment->id }})" :aria-label="__('tasks.preview_name', ['name' => $attachmentName])" data-test="preview-file-button" />
                                <a
                                    href="{{ route('tasks.files.download', [$this->task, $attachment]) }}"
                                    class="inline-flex size-8 items-center justify-center rounded-lg text-secondary-medium transition-colors hover:bg-secondary-soft hover:text-secondary-dark dark:text-secondary-soft dark:hover:bg-secondary-medium dark:hover:text-white"
                                    aria-label="{{ __('common.download_name', ['name' => $attachmentName]) }}"
                                    data-test="download-file-link"
                                >
                                    <flux:icon name="arrow-down-tray" variant="micro" class="size-4" />
                                </a>
                            @endif

                            @if ($canEditAttachments)
                                <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-8!" wire:click="openEditFileModal({{ $attachment->id }})" :aria-label="__('common.edit_name', ['name' => $attachmentName])" data-test="edit-file-button" />
                                <x-ui.button tone="ghost" size="sm" icon="trash" class="size-8! text-primary! hover:bg-status-critical-soft!" wire:click="confirmDeleteFile({{ $attachment->id }})" :aria-label="__('common.delete_name', ['name' => $attachmentName])" data-test="delete-file-button" />
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.panel>

    <section aria-labelledby="record-activity-heading" class="grid gap-3" data-record-activity>
        <h2 id="record-activity-heading" class="text-base font-bold">{{ __('activity.title') }}</h2>
        <livewire:activity.timeline :subjects="['task' => [$this->task->id]]" :key="'task-activity-'.$this->task->id" />
    </section>

    @if ($this->task->parent_task_id === null)
        <x-ui.modal
            wire:model="showAddSubtaskModal"
            size="md"
            :title="__('tasks.add_subtask')"
            submit="addSubtask"
            close="closeAddSubtaskModal"
            required-note
        >
            <div class="grid grid-cols-2 gap-x-4 gap-y-4">
                <x-ui.form-input wire:model="newSubtaskTitle" :label="__('tasks.subtask_title')" maxlength="255" required autofocus class="col-span-2" data-test="new-subtask-title-input" />

                <x-ui.form-segmented
                    wire:model="newSubtaskStatus"
                    :label="__('common.status')"
                    class="col-span-2"
                    :options="[
                        ['value' => TaskStatus::Todo->value, 'label' => TaskStatus::Todo->label(), 'dot' => 'border border-secondary-light', 'test' => 'new-subtask-status-'.TaskStatus::Todo->value],
                        ['value' => TaskStatus::InProgress->value, 'label' => TaskStatus::InProgress->label(), 'dot' => 'bg-status-active', 'test' => 'new-subtask-status-'.TaskStatus::InProgress->value],
                        ['value' => TaskStatus::UnderReview->value, 'label' => TaskStatus::UnderReview->label(), 'dot' => 'bg-status-warn', 'test' => 'new-subtask-status-'.TaskStatus::UnderReview->value],
                        ['value' => TaskStatus::Done->value, 'label' => TaskStatus::Done->label(), 'dot' => 'bg-status-good', 'test' => 'new-subtask-status-'.TaskStatus::Done->value],
                    ]"
                />

                <x-ui.form-segmented
                    wire:model="newSubtaskPriority"
                    :label="__('tasks.priority')"
                    :options="[
                        ['value' => TaskPriority::Low->value, 'label' => TaskPriority::Low->label(), 'icon' => 'flag', 'iconClass' => 'text-secondary-light', 'test' => 'new-subtask-priority-'.TaskPriority::Low->value],
                        ['value' => TaskPriority::Medium->value, 'label' => TaskPriority::Medium->label(), 'icon' => 'flag', 'iconClass' => 'text-status-warn', 'test' => 'new-subtask-priority-'.TaskPriority::Medium->value],
                        ['value' => TaskPriority::High->value, 'label' => TaskPriority::High->label(), 'icon' => 'flag', 'iconClass' => 'text-status-critical', 'test' => 'new-subtask-priority-'.TaskPriority::High->value],
                    ]"
                />

                <x-ui.form-input wire:model="newSubtaskDueDate" :label="__('tasks.due_date_2')" type="date" />

                <x-ui.form-multiselect wire:model="newSubtaskAssignedTo" :label="__('tasks.assignees')" :placeholder="__('common.select')" class="col-span-2" data-test="new-subtask-assignee-picker">
                    @foreach ($this->userOptions as $userOption)
                        <flux:select.option :value="$userOption->id">{{ $userOption->name }} · {{ $userOption->secondary_name }}</flux:select.option>
                    @endforeach
                </x-ui.form-multiselect>
            </div>

            <x-slot:actions>
                <x-ui.button type="submit" tone="primary" data-test="save-new-subtask-button">{{ __('tasks.add_subtask') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
    @endif

    <x-ui.modal
        wire:model="showFileModal"
        size="md"
        :title="$editingFileId === null ? __('tasks.upload_file') : __('tasks.edit_file')"
        :description="__('tasks.allowed_file_types_and_size', ['types' => Str::upper(implode(', ', FileType::values())), 'size' => '5 MB'])"
        submit="saveFile"
        close="closeFileModal"
        required-note
    >
        @if ($showFileModal)
            <div class="grid gap-y-4">
                <x-ui.form-file
                    wire:model="newFile"
                    :label="$editingFileId === null ? __('tasks.choose_a_file') : __('tasks.replace_file_optional')"
                    :accept="collect(FileType::values())->map(fn ($extension) => '.'.$extension)->implode(',')"
                    :required="$editingFileId === null"
                />

                <div class="grid grid-cols-2 gap-x-4 gap-y-4">
                    <x-ui.form-input wire:model="fileDisplayName" :label="__('tasks.file_display_name')" :placeholder="$editingFileId === null ? __('tasks.defaults_to_the_uploaded_file_name') : null" :required="$editingFileId !== null" />
                    <x-ui.form-input wire:model="fileDate" :label="__('tasks.attachment_date')" type="date" required />
                </div>
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" wire:loading.attr="disabled" wire:target="newFile,saveFile" data-test="save-file-button">
                {{ $editingFileId === null ? __('tasks.upload_file') : __('common.save_changes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    @php
        $previewFile = $showPreviewModal ? $this->attachments->firstWhere('id', $previewingFileId) : null;
        $previewMeta = $previewFile === null ? null : collect([
            Str::upper($previewFile->type->value),
            $previewFile->size !== null ? \Illuminate\Support\Number::fileSize($previewFile->size, precision: 1) : null,
            $previewFile->date?->translatedFormat(__('formats.date')),
            $this->attachments->count() > 1 ? __('tasks.preview_position', [
                'position' => $this->attachments->search(fn ($candidate) => $candidate->id === $previewFile->id) + 1,
                'total' => $this->attachments->count(),
            ]) : null,
        ])->filter()->implode(' · ');
    @endphp

    <x-ui.modal
        wire:model="showPreviewModal"
        size="xl"
        :title="$previewFile?->displayName() ?? ''"
        :description="$previewMeta"
        close="closePreview"
        :cancel-label="__('common.close')"
        flush
    >
        @if ($previewFile !== null)
            @php
                $previewName = $previewFile->displayName();
                $previewUrl = route('tasks.files.preview', [$this->task, $previewFile]);
                $downloadUrl = route('tasks.files.download', [$this->task, $previewFile]);
            @endphp

            <x-slot:headerActions>
                @if ($this->attachments->count() > 1)
                    <x-ui.button tone="ghost" size="sm" icon="chevron-left" class="size-8! rtl:[&_svg]:-scale-x-100" wire:click="previewAdjacent(-1)" :aria-label="__('tasks.previous_file')" />
                    <x-ui.button tone="ghost" size="sm" icon="chevron-right" class="size-8! rtl:[&_svg]:-scale-x-100" wire:click="previewAdjacent(1)" :aria-label="__('tasks.next_file')" />
                @endif
                @if ($previewFile->isPreviewable())
                    <a href="{{ $previewUrl }}" target="_blank" rel="noopener" class="inline-flex size-8 items-center justify-center rounded-lg text-secondary-medium transition-colors hover:bg-secondary-soft hover:text-secondary-dark dark:text-secondary-soft dark:hover:bg-secondary-medium dark:hover:text-white" aria-label="{{ __('tasks.open_in_new_tab') }}">
                        <flux:icon name="arrow-top-right-on-square" variant="micro" class="size-4" />
                    </a>
                @endif
                <a href="{{ $downloadUrl }}" class="inline-flex size-8 items-center justify-center rounded-lg text-secondary-medium transition-colors hover:bg-secondary-soft hover:text-secondary-dark dark:text-secondary-soft dark:hover:bg-secondary-medium dark:hover:text-white" aria-label="{{ __('common.download_name', ['name' => $previewName]) }}">
                    <flux:icon name="arrow-down-tray" variant="micro" class="size-4" />
                </a>
            </x-slot:headerActions>

            <div
                class="bg-board-head dark:bg-secondary-medium/20"
                wire:key="preview-{{ $previewFile->id }}"
                x-data
                x-on:keydown.left.window="$wire.previewAdjacent(document.dir === 'rtl' ? 1 : -1)"
                x-on:keydown.right.window="$wire.previewAdjacent(document.dir === 'rtl' ? -1 : 1)"
            >
                @if (! $previewFile->isStored())
                    <x-ui.empty-state icon="eye-slash" :heading="__('tasks.preview_not_available')" :body="__('tasks.this_record_has_no_stored_file')" />
                @elseif ($previewFile->type === FileType::Pdf)
                    <iframe src="{{ $previewUrl }}#view=FitH" title="{{ $previewName }}" class="h-[75dvh] w-full border-0 bg-white" data-test="pdf-preview"></iframe>
                @elseif ($previewFile->isPreviewable())
                    <div class="flex min-h-[50dvh] items-center justify-center p-4">
                        <img src="{{ $previewUrl }}" alt="{{ $previewName }}" class="max-h-[75dvh] max-w-full rounded-lg object-contain shadow-sm" data-test="image-preview" />
                    </div>
                @else
                    {{-- Word/Excel: rendered client-side by resources/js/file-preview.js from the authenticated download route. --}}
                    <div
                        wire:ignore
                        x-data="officePreview(@js(['url' => $downloadUrl, 'type' => $previewFile->type->value]))"
                        class="flex h-[75dvh] flex-col"
                        data-test="office-preview"
                    >
                        <div x-show="sheets.length > 1" class="flex shrink-0 gap-1 overflow-x-auto border-b border-secondary-soft bg-white px-3 py-2 dark:border-secondary-medium dark:bg-secondary-dark">
                            <template x-for="(sheetName, index) in sheets" :key="index">
                                <button
                                    type="button"
                                    x-on:click="showSheet(index)"
                                    x-text="sheetName"
                                    class="shrink-0 rounded-md px-2.5 py-1 text-xs font-semibold whitespace-nowrap"
                                    :class="index === activeSheet ? 'bg-primary text-white' : 'text-secondary-medium hover:bg-secondary-soft dark:text-secondary-soft dark:hover:bg-secondary-medium'"
                                ></button>
                            </template>
                        </div>

                        <div x-show="status === 'loading'" class="flex flex-1 items-center justify-center gap-2 text-sm text-secondary-medium dark:text-secondary-soft">
                            <flux:icon.loading class="size-5" />
                            {{ __('tasks.loading_preview') }}
                        </div>

                        <div x-show="status === 'error'" x-cloak class="flex-1">
                            <x-ui.empty-state icon="exclamation-triangle" :heading="__('tasks.preview_not_available')" :body="__('tasks.this_file_could_not_be_previewed')">
                                <x-slot:action>
                                    <a href="{{ $downloadUrl }}" class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white hover:opacity-90">
                                        <flux:icon name="arrow-down-tray" variant="micro" class="size-4" />
                                        {{ __('common.download_name', ['name' => $previewName]) }}
                                    </a>
                                </x-slot:action>
                            </x-ui.empty-state>
                        </div>

                        <div x-show="status === 'ready'" x-cloak x-ref="canvas" class="min-h-0 flex-1 overflow-auto"></div>

                        <p x-show="truncated" x-cloak class="shrink-0 border-t border-secondary-soft bg-white px-3 py-2 text-xs text-secondary-medium dark:border-secondary-medium dark:bg-secondary-dark dark:text-secondary-soft">{{ __('tasks.sheet_preview_truncated', ['rows' => 500]) }}</p>
                    </div>
                @endif
            </div>
        @endif
    </x-ui.modal>

    <x-ui.confirm-modal
        wire:model="showDeleteFileModal"
        close="closeDeleteFileModal"
        :title="$showDeleteFileModal && $deletingFileId !== null && ($deletingAttachment = $this->attachments->firstWhere('id', $deletingFileId)) !== null ? __('common.delete_name', ['name' => $deletingAttachment->displayName()]) : __('common.delete')"
        confirm="deleteFile"
        :confirm-label="__('common.delete')"
        confirm-test="confirm-delete-file-button"
    />
</section>
