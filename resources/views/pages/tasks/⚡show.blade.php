<?php

use App\Actions\Desk\SendNudge;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\PreviewsFiles;
use App\Livewire\Concerns\Tasks\ManagesSubtasks;
use App\Livewire\Concerns\Tasks\ManagesTaskAttachments;
use App\Livewire\Concerns\Tasks\MovesTask;
use App\Models\HrEmployee;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Support\TaskWorkflow;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
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
     * Whether the folded Activity history has been opened, so its timeline
     * is only built for someone who looks at it.
     */
    public bool $showActivity = false;

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
                'activity_plan_milestone_id',
            ])
            ->with([
                'source',
                'planMilestone:id,activity_plan_id,title',
                'planMilestone.plan:id,slug',
                'taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
                'creator:id,hr_user_id',
                'parentTask:id,title',
            ])
            ->findOrFail($this->taskId);
    }

    /**
     * Save the task. Editors (tasks.update, or the task's creator) change
     * every field; its assignees may only move status and progress, so for
     * them every other field keeps its stored value. Moving an unstarted
     * task to In progress sets its start date to today.
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

        $evidenceKept = $canEdit && $task->created_by !== auth()->id() && $this->evidenceRequired !== $task->evidence_required;

        if ($evidenceKept) {
            $this->evidenceRequired = $task->evidence_required;
        }

        $from = $task->status;
        $approved = TaskWorkflow::approves(auth()->user(), $task);
        $note = SendNudge::cleanNote($this->reviewNote);
        TaskWorkflow::guard(auth()->user(), $task, TaskStatus::from($validated['taskStatus']), $note, 'taskStatus');

        $startsWork = $validated['taskStatus'] === TaskStatus::InProgress->value
            && $from !== TaskStatus::InProgress
            && $task->start_date === null
            && $validated['startDate'] === '';

        if ($startsWork) {
            $validated['startDate'] = today()->toDateString();
            $this->startDate = $validated['startDate'];
        }

        DB::transaction(function () use ($task, $validated, $canEdit, $startsWork): void {
            $progress = [
                ...($startsWork ? ['start_date' => $validated['startDate']] : []),
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

        if ($evidenceKept) {
            Flux::toast(variant: 'danger', text: __('tasks.only_the_task_creator_can_change_proof'));
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
     * Take one person off the task from their chip's x, saved at once like
     * every other details field (editors only; updateTask keeps the
     * assignees for everyone else).
     */
    public function removeAssignee(int $employeeId): void
    {
        $this->assignedTo = array_values(array_filter(
            $this->assignedTo,
            fn (string $assignedId): bool => (int) $assignedId !== $employeeId,
        ));

        $this->saveInPlace('assignedTo');
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
        $approvesTask = \App\Support\TaskWorkflow::approves(auth()->user(), $this->task);
        $hasDescription = filled($this->task->description);
        $canAddSubtask = $this->task->parent_task_id === null && auth()->user()->can('create', \App\Models\Task::class);
        $canMoveTask = $this->moveDestinations !== null && $canEditTask;
        $nextStep = match ($this->task->status) {
            TaskStatus::Todo => ['label' => __('tasks.show.start_task'), 'to' => TaskStatus::InProgress],
            TaskStatus::InProgress => $approvesTask
                ? ['label' => __('tasks.show.mark_done'), 'to' => TaskStatus::Done]
                : ['label' => __('tasks.show.submit_for_review'), 'to' => TaskStatus::UnderReview],
            TaskStatus::UnderReview => $approvesTask
                ? ['label' => __('tasks.show.approve_done'), 'to' => TaskStatus::Done]
                : ['label' => __('tasks.show.waiting_for_approval'), 'to' => null],
            TaskStatus::Done => null,
        };
    @endphp

    <x-ui.detail-header
        :back="\App\Support\BackUrl::for(route('tasks.index'))"
        id="task-detail-heading"
        :breadcrumbs="[
            ['label' => __('common.tasks'), 'url' => route('tasks.index')],
            ['label' => $this->task->title],
        ]"
        :title="$this->task->title"
        :unread="app(\App\Notifications\UnreadSubjects::class)->has(auth()->user(), 'task', $this->task->id)"
    >
        <x-slot:meta>
            <span class="font-medium text-secondary-dark dark:text-ink">{{ $this->task->sourceLabel() }}</span>
            @if ($this->task->planMilestone?->plan !== null)
                <span aria-hidden="true">·</span>
                <a href="{{ route('activity-plans.show', $this->task->planMilestone->plan) }}" wire:navigate class="font-medium hover:underline" data-test="task-plan-milestone" dir="auto">
                    {{ __('annual_plans.milestones.supports', ['name' => $this->task->planMilestone->title]) }}
                </a>
            @endif
            <span aria-hidden="true">·</span>
            <span>{{ __('tasks.created_by_name', ['name' => $this->task->creator->name]) }}</span>
            @if ($this->task->parentTask !== null)
                <span aria-hidden="true">·</span>
                <a href="{{ route('tasks.show', $this->task->parentTask) }}" wire:navigate class="font-medium hover:underline">
                    {{ __('tasks.subtask_of_title', ['title' => $this->task->parentTask->title]) }}
                </a>
            @endif
            <x-tasks.paused-chip :task="$this->task" :paused-ids="$this->pausedProjectIds" :full="true" />
            @if ($this->task->isReportTask())
                <x-ui.enum-badge :case="$this->task->kind" data-test="report-task-chip" />
                @if ($this->task->reportUrl() !== null)
                    <a href="{{ $this->task->reportUrl() }}" wire:navigate class="font-medium hover:underline">{{ __('tasks.open_report') }}</a>
                @endif
                <span class="basis-full text-xs text-secondary-medium dark:text-ink-muted" data-test="report-task-helper">{{ __('tasks.report_task_helper') }}</span>
            @endif
        </x-slot:meta>

        <x-slot:actions>
            <div class="flex items-center gap-2">
                @if ($nextStep !== null && $canUpdateTaskStatus)
                    @if ($nextStep['to'] !== null)
                        <x-ui.button tone="primary" wire:click="changeStatus('{{ $nextStep['to']->value }}')" wire:loading.attr="disabled" wire:target="changeStatus" data-test="task-next-step">{{ $nextStep['label'] }}</x-ui.button>
                    @else
                        <x-ui.button tone="secondary" disabled data-test="task-next-step">{{ $nextStep['label'] }}</x-ui.button>
                    @endif
                @endif

                @if ($canEditTask || $canAddSubtask || $canMoveTask)
                    <flux:dropdown align="end">
                        <flux:button variant="ghost" icon="ellipsis-horizontal" :aria-label="__('tasks.show.more_actions')" data-test="task-more-actions" />
                        <flux:menu>
                            @if ($canEditTask)
                                <flux:menu.item icon="pencil-square" x-on:click="$dispatch('task-edit-details')" data-test="task-edit-details-button">{{ $hasDescription ? __('tasks.show.edit_title_description') : __('tasks.show.add_description') }}</flux:menu.item>
                            @endif
                            @if ($canAddSubtask)
                                <flux:menu.item icon="plus" wire:click="openAddSubtaskModal" data-test="add-subtask-button">{{ __('tasks.add_subtask') }}</flux:menu.item>
                            @endif
                            @if ($canMoveTask)
                                <flux:menu.item icon="folder-arrow-down" wire:click="openMoveModal" data-test="task-move-button">{{ __('tasks.move_to') }}</flux:menu.item>
                            @endif
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </div>
        </x-slot:actions>
    </x-ui.detail-header>

    @include('pages.tasks._show-status-panel')

    {{-- Content, then the details on the inline-end side once the page is wide. On a phone the details come first. --}}
    <div class="grid items-start gap-4 @4xl/detail:grid-cols-[minmax(0,1fr)_20rem]">
        <aside class="min-w-0 @4xl/detail:sticky @4xl/detail:top-4 @4xl/detail:col-start-2 @4xl/detail:row-start-1" aria-labelledby="task-properties-heading" data-test="task-properties">
            @include('pages.tasks._show-properties')
        </aside>

        <div class="grid min-w-0 gap-4 @4xl/detail:col-start-1 @4xl/detail:row-start-1">
            @if ($hasDescription || $canEditTask)
                @include('pages.tasks._show-details')
            @endif

            @if ($this->task->parent_task_id === null && $this->subtasks->isNotEmpty())
                @include('pages.tasks._show-subtasks')
            @endif

            @include('pages.tasks._show-attachments')

            {{-- The history is for whoever approves the task (its creator, or an editor not doing the work); assignees see only the work. --}}
            @if ($approvesTask)
                <x-ui.panel :clip="false" data-record-activity>
                    <button
                        type="button"
                        wire:click="$toggle('showActivity')"
                        aria-expanded="{{ $showActivity ? 'true' : 'false' }}"
                        aria-controls="task-activity-body"
                        class="flex w-full cursor-pointer items-center justify-between gap-2 px-4 py-3 text-start"
                        data-test="task-activity-toggle"
                    >
                        <h2 id="record-activity-heading" class="text-base font-bold">{{ __('tasks.show.activity') }}</h2>
                        <flux:icon name="chevron-down" variant="micro" wire:loading.remove wire:target="showActivity" @class(['size-4 text-secondary-medium transition-transform dark:text-ink-muted', 'rotate-180' => $showActivity]) />
                        <flux:icon.loading variant="micro" wire:loading wire:target="showActivity" class="size-4 text-secondary-medium dark:text-ink-muted" />
                    </button>
                    @if ($showActivity)
                        <div id="task-activity-body" class="border-t border-panel-border p-4">
                            <livewire:activity.timeline :subjects="['task' => [$this->task->id]]" :key="'task-activity-'.$this->task->id" />
                        </div>
                    @endif
                </x-ui.panel>
            @endif
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

