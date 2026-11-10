<?php

use App\Actions\Desk\SendNudge;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\PreviewsFiles;
use App\Livewire\Concerns\Tasks\ManagesSubtasks;
use App\Livewire\Concerns\Tasks\ManagesTaskAttachments;
use App\Livewire\Concerns\Tasks\MovesTask;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Support\TaskWorkflow;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::desk'), Title('titles.task')] class extends Component
{
    use ManagesSubtasks;
    use ManagesTaskAttachments;
    use MovesTask;
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

    public bool $evidenceRequired = false;

    /**
     * The projects whose center HR has deactivated, read once so the
     * «Paused» chip costs no query per row (D-66, W-02).
     *
     * @return list<int>
     */
    #[Computed]
    public function pausedProjectIds(): array
    {
        return Project::pausedIds();
    }

    /**
     * What to fix, when the approver sends an Under review task back.
     */
    public string $reviewNote = '';

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
        $this->evidenceRequired = $freshTask->evidence_required;
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
                'evidence_required',
                'created_by',
                'kind',
                'department_id',
                'reportable_type',
                'reportable_id',
            ])
            ->with([
                // morphWith() eager loads the milestone's project on top of
                // the source itself in the same batch, so the meta line
                // below can show "milestone · project" without an extra
                // query — the other four source types have nothing further
                // to load.
                'source' => fn (MorphTo $morphTo) => $morphTo->morphWith([Milestone::class => ['project:id,name', 'proposal:id,name']]),
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
            'assignedTo' => ['array'],
            'assignedTo.*' => ['integer', Rule::exists(HrEmployee::class, 'id')],
        ], [
            'title.required' => __('tasks.enter_a_task_title'),
            'assignedTo.*.exists' => __('tasks.the_selected_assignee_is_no_longer_available'),
            'dueDate.after_or_equal' => __('tasks.the_due_date_cannot_fall_before_the_start_date'),
            'progressPercentage.min' => __('tasks.progress_cannot_be_negative'),
            'progressPercentage.max' => __('tasks.progress_cannot_exceed_100_percent'),
        ]);

        $dueDateKept = $canEdit && $task->created_by !== auth()->id() && $validated['dueDate'] !== ($task->due_date?->toDateString() ?? '');

        if ($dueDateKept) {
            $validated['dueDate'] = $task->due_date?->toDateString() ?? '';
            $this->dueDate = $validated['dueDate'];
        }

        $from = $task->status;
        $approved = TaskWorkflow::approves(auth()->user(), $task);
        $note = SendNudge::cleanNote($this->reviewNote);
        TaskWorkflow::guard(auth()->user(), $task, TaskStatus::from($validated['taskStatus']), $note, 'taskStatus');

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
                'evidence_required' => $this->evidenceRequired,
            ])->save();

            $employeeIds = collect($validated['assignedTo'])->map(fn ($id): int => (int) $id)->unique()->values();

            $task->taskAssignees()->whereNotIn('employee_id', $employeeIds)->delete();
            $existingIds = $task->taskAssignees()->pluck('employee_id');

            $employeeIds->diff($existingIds)->each(fn (int $employeeId) => $task->taskAssignees()->create([
                'employee_id' => $employeeId,
            ]));
        });

        TaskWorkflow::announce(auth()->user(), $task, $from, $approved, $note);
        $this->reviewNote = '';
        unset($this->task);

        if ($dueDateKept) {
            Flux::toast(variant: 'danger', text: __('tasks.only_the_task_creator_can_change_the_due_date'));
        }

        Flux::toast(variant: 'success', text: __('tasks.task_updated'));
    }

    /**
     * The properties column saves each field the moment it changes. A
     * refused change puts the field back to its stored value and shows why.
     */
    public function updated(string $property): void
    {
        $field = Str::before($property, '.');

        if (in_array($field, ['taskPriority', 'startDate', 'dueDate', 'completedDate', 'progressPercentage', 'assignedTo', 'evidenceRequired'], true)) {
            $this->saveInPlace($field);
        }
    }

    /**
     * Move the task to another status from the properties column. An
     * approver sending an Under review task back first writes a note, so
     * that move waits for sendBack().
     */
    public function changeStatus(string $status): void
    {
        if (TaskStatus::tryFrom($status) === null) {
            return;
        }

        $this->taskStatus = $status;

        if ($this->reviewNoteNeeded()) {
            return;
        }

        $this->saveInPlace('taskStatus');
    }

    /**
     * Send an Under review task back with the approver's note.
     */
    public function sendBack(): void
    {
        $this->updateTask();
    }

    /**
     * Drop a status move that is waiting for its send-back note.
     */
    public function cancelStatusChange(): void
    {
        $this->reviewNote = '';
        $this->resetValidation(['taskStatus', 'reviewNote']);
        $this->restoreProperty('taskStatus');
    }

    /**
     * Whether the chosen status sends an Under review task back, which
     * TaskWorkflow lets the approver do only with a note.
     */
    public function reviewNoteNeeded(): bool
    {
        return $this->task->status === TaskStatus::UnderReview
            && in_array($this->taskStatus, [TaskStatus::Todo->value, TaskStatus::InProgress->value], true)
            && TaskWorkflow::approves(auth()->user(), $this->task);
    }

    /**
     * Save the title and description edited in place.
     */
    public function saveDetails(): void
    {
        $this->updateTask();
        $this->dispatch('task-details-saved');
    }

    /**
     * Throw away an unsaved title or description edit.
     */
    public function cancelDetails(): void
    {
        $this->resetValidation(['title', 'description']);
        $this->restoreProperty('title');
        $this->restoreProperty('description');
    }

    /**
     * Save after one property changed; on refusal put it back and rethrow
     * so the error shows beside the field.
     */
    protected function saveInPlace(string $field): void
    {
        try {
            $this->updateTask();
        } catch (ValidationException $exception) {
            $this->restoreProperty($field);

            throw $exception;
        }
    }

    /**
     * Reset one form property to the value stored on the task.
     */
    protected function restoreProperty(string $field): void
    {
        unset($this->task);
        $storedTask = $this->task;

        match ($field) {
            'title' => $this->title = $storedTask->title,
            'description' => $this->description = $storedTask->description ?? '',
            'taskStatus' => $this->taskStatus = $storedTask->status->value,
            'taskPriority' => $this->taskPriority = $storedTask->priority->value,
            'startDate' => $this->startDate = $storedTask->start_date?->toDateString() ?? '',
            'dueDate' => $this->dueDate = $storedTask->due_date?->toDateString() ?? '',
            'completedDate' => $this->completedDate = $storedTask->completed_date?->toDateString() ?? '',
            'progressPercentage' => $this->progressPercentage = (string) $storedTask->progress_percentage,
            'assignedTo' => $this->assignedTo = $storedTask->taskAssignees->pluck('employee_id')->map(fn (int $id): string => (string) $id)->all(),
            'evidenceRequired' => $this->evidenceRequired = $storedTask->evidence_required,
            default => null,
        };
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

<section class="@container/detail flex w-full flex-1 flex-col gap-4 text-secondary-dark dark:text-ink" aria-labelledby="task-detail-heading">
    @php
        $canEditTask = auth()->user()->can('update', $this->task);
        $canUpdateTaskStatus = $canEditTask || auth()->user()->can('updateStatus', $this->task);
        $canRescheduleTask = $canEditTask && $this->task->created_by === auth()->id();
        $canEditAttachments = auth()->user()->can('manageFiles', $this->task);
    @endphp

    <x-ui.detail-header
        :back="\App\Support\BackUrl::for(route('tasks.index'))"
        id="task-detail-heading"
        :breadcrumbs="[
            ['label' => __('common.tasks'), 'url' => route('tasks.index')],
            ...($this->task->source instanceof \App\Models\Milestone ? [array_filter(['label' => $this->task->source->name, 'url' => auth()->user()->can('view', $this->task->source) ? route('milestones.show', $this->task->source) : null])] : []),
            ['label' => $this->task->title],
        ]"
        :title="$this->task->title"
        :unread="app(\App\Notifications\UnreadSubjects::class)->has(auth()->user(), 'task', $this->task->id)"
        :subtitle="$this->task->sourceLabel()"
    >
        <x-slot:meta>
            @if ($this->task->source instanceof \App\Models\Milestone && $this->task->source->project !== null)
                <a href="{{ route('projects.show', $this->task->source->project) }}" wire:navigate class="font-medium hover:underline">
                    {{ $this->task->source->name }} · {{ $this->task->source->ownerName() }}
                </a>
            @elseif ($this->task->source instanceof \App\Models\Milestone)
                <span class="font-medium">{{ $this->task->source->name }} · {{ $this->task->source->ownerName() }}</span>
            @endif
            @if ($this->task->isReportTask())
                <x-ui.enum-badge :case="$this->task->kind" data-test="report-task-chip" />
                @if ($this->task->reportUrl() !== null)
                    <a href="{{ $this->task->reportUrl() }}" wire:navigate class="font-medium hover:underline">{{ __('tasks.open_report') }}</a>
                @endif
                <span class="basis-full text-xs text-secondary-medium dark:text-ink-muted" data-test="report-task-helper">{{ __('tasks.report_task_helper') }}</span>
            @endif
            <x-tasks.paused-chip :task="$this->task" :paused-ids="$this->pausedProjectIds" :full="true" />
            <span>{{ __('tasks.created_by_name', ['name' => $this->task->creator->name]) }}</span>
            @if ($this->task->parentTask !== null)
                <a href="{{ route('tasks.show', $this->task->parentTask) }}" wire:navigate class="font-medium hover:underline">
                    {{ __('tasks.subtask_of_title', ['title' => $this->task->parentTask->title]) }}
                </a>
            @endif
        </x-slot:meta>

        @if ($this->moveDestinations !== null && auth()->user()->can('update', $this->task))
            <x-slot:actions>
                <x-ui.button tone="secondary" size="sm" icon="folder-arrow-down" wire:click="openMoveModal" data-test="task-move-button">{{ __('tasks.move_to') }}</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.detail-header>

    {{-- Content, then the properties on the inline-end side once the page is wide. On a phone the properties come first. --}}
    <div class="grid items-start gap-4 @4xl/detail:grid-cols-[minmax(0,1fr)_20rem]">
        <aside class="min-w-0 @4xl/detail:sticky @4xl/detail:top-4 @4xl/detail:col-start-2 @4xl/detail:row-start-1" aria-labelledby="task-properties-heading" data-test="task-properties">
            @include('pages.tasks._show-properties')
        </aside>

        <div class="grid min-w-0 gap-4 @4xl/detail:col-start-1 @4xl/detail:row-start-1">
            @include('pages.tasks._show-details')

            @if ($this->task->parent_task_id === null)
                @include('pages.tasks._show-subtasks')
            @endif

            @include('pages.tasks._show-attachments')

            <section aria-labelledby="record-activity-heading" class="grid gap-3" data-record-activity>
                <h2 id="record-activity-heading" class="text-base font-bold">{{ __('activity.title') }}</h2>
                <livewire:activity.timeline :subjects="['task' => [$this->task->id]]" :key="'task-activity-'.$this->task->id" />
            </section>
        </div>
    </div>

    @if ($this->task->parent_task_id === null)
        @include('pages.tasks._show-subtasks-modal')
    @endif

    @include('pages.tasks._show-modals')

    @if ($this->moveDestinations !== null)
        @include('pages.tasks._show-move-modal')
    @endif
</section>
