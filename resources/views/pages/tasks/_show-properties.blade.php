{{-- The details column on ⚡show.blade.php: assignees, priority, dates, proof and what the task is related to, one row each. Each field saves the moment it changes for whoever may change it (updated() in the page); everyone else reads it as text. Only the creator moves the due date; the start date fills itself when work starts; the completed date shows once the task is done. --}}
@use('App\Enums\TaskPriority')
@use('App\Enums\TaskStatus')
@php
    $rowClass = 'flex min-h-11 items-center justify-between gap-3 px-4 py-2';
    $rowLabelClass = 'shrink-0 text-sm text-secondary-medium dark:text-ink-muted';
    $readValueClass = 'text-sm font-semibold tabular-nums text-secondary-dark dark:text-ink';
    $dateInputClass = 'h-8 rounded-md border border-transparent bg-transparent px-1.5 text-end text-sm font-semibold tabular-nums text-secondary-dark hover:border-secondary-light focus:border-secondary-medium focus:outline-none dark:text-ink dark:[color-scheme:dark] dark:hover:border-line-strong';
    $assigneeEmployees = $this->task->taskAssignees->pluck('employee')->filter();
    $priorityDot = fn (TaskPriority $priority): string => match ($priority) {
        TaskPriority::High => 'bg-primary',
        TaskPriority::Medium => 'bg-secondary-medium',
        TaskPriority::Low => 'bg-secondary-light',
    };
    $currentPriority = TaskPriority::tryFrom($taskPriority) ?? $this->task->priority;
    $daysLate = $this->task->status !== TaskStatus::Done && $this->task->due_date?->isPast() && ! $this->task->due_date->isToday()
        ? (int) $this->task->due_date->copy()->startOfDay()->diffInDays(today())
        : 0;
    $source = $this->task->source;
    $sourceUrl = $source instanceof \App\Models\Milestone && auth()->user()->can('view', $source) ? route('milestones.show', $source) : null;
@endphp

<x-ui.panel :clip="false">
    <h2 id="task-properties-heading" class="px-4 pt-3.5 pb-1.5 text-base font-bold text-secondary-dark dark:text-ink">{{ __('tasks.show.details') }}</h2>

    <dl class="divide-y divide-secondary-soft pb-1 dark:divide-line">
        <div class="grid min-w-0 gap-2 px-4 py-2.5">
            <dt class="{{ $rowLabelClass }}">{{ __('tasks.assignees') }}</dt>
            <dd class="grid min-w-0 gap-2">
                @if ($assigneeEmployees->isEmpty())
                    <p class="text-sm text-secondary-medium dark:text-ink-muted">{{ __('common.not_assigned') }}</p>
                @else
                    <ul class="flex min-w-0 flex-wrap gap-1.5" data-test="task-assignee-names">
                        @foreach ($assigneeEmployees as $assigneeEmployee)
                            <li @class(['inline-flex max-w-full min-w-0 items-center gap-1.5 rounded-full bg-secondary-soft py-0.5 ps-0.5 text-sm font-semibold dark:bg-surface-2', 'pe-1' => $canEditTask, 'pe-2.5' => ! $canEditTask]) wire:key="task-assignee-chip-{{ $assigneeEmployee->id }}">
                                <x-ui.avatar :src="\App\Models\HrEmployee::avatarUrl($assigneeEmployee->id)" class="size-6 shrink-0 rounded-full" aria-hidden="true" />
                                <span class="truncate">{{ $assigneeEmployee->name }}</span>
                                @if ($canEditTask)
                                    <button
                                        type="button"
                                        wire:click="removeAssignee({{ $assigneeEmployee->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="removeAssignee({{ $assigneeEmployee->id }})"
                                        class="grid size-5 shrink-0 cursor-pointer place-items-center rounded-full text-secondary-medium hover:bg-primary hover:text-white disabled:opacity-50 dark:text-ink-muted"
                                        aria-label="{{ __('tasks.remove_assignee_name', ['name' => $assigneeEmployee->name]) }}"
                                        title="{{ __('tasks.remove_assignee_name', ['name' => $assigneeEmployee->name]) }}"
                                        data-test="task-remove-assignee-{{ $assigneeEmployee->id }}"
                                    >
                                        <flux:icon name="x-mark" variant="micro" class="size-3.5" />
                                    </button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($canEditTask)
                    {{-- The picker trigger reads "Add people" instead of repeating the names the chips already show. --}}
                    <div class="relative min-w-0 [&_.form-label]:sr-only [&_[data-flux-button]]:min-w-0 [&_[data-trigger-label]]:invisible">
                        <x-ui.form-multiselect wire:model.live="assignedTo" :label="__('tasks.assignees')" :placeholder="__('tasks.show.add_people')" data-test="task-edit-assignee-picker" employees />
                        <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center gap-1.5 ps-3.5 text-sm font-medium text-secondary-medium dark:text-ink-muted" aria-hidden="true">
                            <flux:icon name="user-plus" variant="micro" class="size-4" />
                            {{ __('tasks.show.add_people') }}
                        </span>
                    </div>
                @endif
            </dd>
        </div>

        <div class="{{ $rowClass }}">
            <dt class="{{ $rowLabelClass }}">{{ __('tasks.priority') }}</dt>
            <dd>
                @if ($canEditTask)
                    <flux:dropdown align="end">
                        <button type="button" class="inline-flex h-8 cursor-pointer items-center gap-2 rounded-md px-2 text-sm font-semibold hover:bg-secondary-soft dark:hover:bg-surface-2" data-test="task-edit-priority-trigger">
                            <span class="size-2 rounded-full {{ $priorityDot($currentPriority) }}" aria-hidden="true"></span>
                            {{ $currentPriority->label() }}
                            <flux:icon name="chevron-down" variant="micro" class="size-3.5 text-secondary-medium dark:text-ink-muted" />
                        </button>
                        <flux:menu>
                            @foreach (TaskPriority::cases() as $priorityCase)
                                <flux:menu.item wire:click="$set('taskPriority', '{{ $priorityCase->value }}')" data-test="task-edit-priority-{{ $priorityCase->value }}">
                                    <span class="me-2 inline-block size-2 rounded-full {{ $priorityDot($priorityCase) }}" aria-hidden="true"></span>{{ $priorityCase->label() }}
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                @else
                    <span class="inline-flex items-center gap-2 {{ $readValueClass }}" data-test="task-priority-text">
                        <span class="size-2 rounded-full {{ $priorityDot($this->task->priority) }}" aria-hidden="true"></span>{{ $this->task->priority->label() }}
                    </span>
                @endif
            </dd>
        </div>
        <flux:error name="taskPriority" class="px-4 pb-2" />

        <div class="{{ $rowClass }}">
            <dt class="{{ $rowLabelClass }}">
                @if ($canEditTask)
                    <label for="task-property-startDate">{{ __('common.start_date') }}</label>
                @else
                    {{ __('common.start_date') }}
                @endif
            </dt>
            <dd>
                @if ($canEditTask)
                    <input id="task-property-startDate" type="date" wire:model.live.change="startDate" class="{{ $dateInputClass }}" data-test="task-edit-start-date-input" />
                @elseif ($this->task->start_date !== null)
                    <span class="{{ $readValueClass }}" data-test="task-edit-start-date-input-text">{{ $this->task->start_date->translatedFormat(__('formats.date')) }}</span>
                @else
                    <span class="text-sm text-secondary-medium dark:text-ink-muted" data-test="task-edit-start-date-input-text">{{ __('tasks.show.start_date_auto') }}</span>
                @endif
            </dd>
        </div>
        <flux:error name="startDate" class="px-4 pb-2" />

        <div class="{{ $rowClass }}">
            <dt class="{{ $rowLabelClass }}">
                @if ($canRescheduleTask)
                    <label for="task-property-dueDate">{{ __('tasks.due_date_2') }}</label>
                @else
                    {{ __('tasks.due_date_2') }}
                @endif
            </dt>
            <dd class="flex items-center gap-2">
                @if ($daysLate > 0)
                    <span class="rounded-md border border-primary px-1.5 text-[11px] font-bold whitespace-nowrap text-primary dark:border-brand-text dark:text-brand-text" data-test="task-days-late">{{ trans_choice('tasks.show.days_late', $daysLate, ['count' => $daysLate]) }}</span>
                @endif
                @if ($canRescheduleTask)
                    <input id="task-property-dueDate" type="date" wire:model.live.change="dueDate" class="{{ $dateInputClass }}" data-test="task-edit-due-date-input" />
                @else
                    <span class="{{ $readValueClass }}" data-test="task-edit-due-date-input-text">{{ $this->task->due_date?->translatedFormat(__('formats.date')) ?? '—' }}</span>
                @endif
            </dd>
        </div>
        <flux:error name="dueDate" class="px-4 pb-2" />

        @if ($this->task->status === TaskStatus::Done)
            <div class="{{ $rowClass }}">
                <dt class="{{ $rowLabelClass }}">{{ __('tasks.completed_date') }}</dt>
                <dd class="{{ $readValueClass }}" data-test="task-completed-date-text">{{ $this->task->completed_date?->translatedFormat(__('formats.date')) ?? '—' }}</dd>
            </div>
        @endif

        <div class="{{ $rowClass }}">
            <dt class="{{ $rowLabelClass }}">{{ __('tasks.show.proof_needed') }}</dt>
            <dd>
                @if ($canEditTask)
                    <button
                        type="button"
                        role="switch"
                        aria-checked="{{ $evidenceRequired ? 'true' : 'false' }}"
                        aria-label="{{ __('tasks.show.proof_needed') }}"
                        wire:click="$toggle('evidenceRequired')"
                        wire:loading.attr="disabled"
                        wire:target="evidenceRequired"
                        @class([
                            'relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60',
                            'bg-primary' => $evidenceRequired,
                            'bg-secondary-light dark:bg-line-strong' => ! $evidenceRequired,
                        ])
                        data-test="task-evidence-required"
                    >
                        <span @class(['inline-block size-5 rounded-full bg-white shadow transition-transform', 'translate-x-5.5 rtl:-translate-x-5.5' => $evidenceRequired, 'translate-x-0.5 rtl:-translate-x-0.5' => ! $evidenceRequired]) aria-hidden="true"></span>
                    </button>
                @else
                    <span class="{{ $readValueClass }}" data-test="task-evidence-required-text">{{ $this->task->evidence_required ? __('tasks.show.yes') : __('tasks.show.no') }}</span>
                @endif
            </dd>
        </div>

        <div class="{{ $rowClass }}">
            <dt class="{{ $rowLabelClass }}">{{ __('tasks.source_type') }}</dt>
            <dd class="min-w-0 text-end">
                @if ($sourceUrl !== null)
                    <a href="{{ $sourceUrl }}" wire:navigate class="block truncate {{ $readValueClass }} hover:underline" data-test="task-related-to">{{ $this->task->sourceLabel() }}</a>
                @else
                    <span class="block truncate {{ $readValueClass }}" data-test="task-related-to">{{ $this->task->sourceLabel() }}</span>
                @endif
            </dd>
        </div>
    </dl>
</x-ui.panel>
